<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Filters;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Query\ListRequest;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Write\ValueConverter;
use Kinetis\VectorAi\Failure\AiFailureMapper;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Governance\ModelBoundary;
use Kinetis\VectorAi\Http\AiRequestException;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Usage\UsageLedger;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\NeuronException;

/**
 * An admin's sentence as a filter of one resource. The model is told the
 * resource as ModelBoundary lets it be told and asked, in one structured
 * call, for the JSON object FilterSchema describes. Its answer is then
 * made a list filter and put through ListRequest::fromQueryParams() (the
 * validator the list and vector_query use) as this user's list would
 * be: a field it does not admit, or an operator, fails there. A failed
 * answer gets one repair turn quoting the exact reason; a second failure
 * is the admin's 422. A relation named in words is looked up with
 * ResourceService::options() under this user's scope: one match becomes
 * its id, several become an `unresolved` choice, none is a 422.
 *
 * Every call (the repair turn too) is one row of the usage ledger. No
 * prompt, sentence, answer or provider error body is logged: a failed
 * call is logged by AiFailureMapper with its category and status. Built
 * per request.
 */
final readonly class FilterTranslator
{
    /** The longest sentence the endpoint takes. */
    public const int MAX_TEXT = 500;

    /** The longest explanation passed on to the admin. */
    private const int MAX_EXPLANATION = 500;

    /** How many records a lookup offers when the words match several. */
    private const int CANDIDATES = 5;

    public const string SYSTEM_PROMPT = "You translate an admin's request into a filter for one resource. Answer only with the JSON object described. Today is %s in %s; write absolute dates. A day or a time the admin names is one in %s, not in UTC: write a date-time bound with the offset that zone has on that date (now %s), such as %sT00:00:00%s for the start of today.";

    public const string REPAIR_PROMPT = 'The list refused that filter: %s Answer again with the whole JSON object, corrected.';

    public function __construct(
        private ResourceService $resources,
        private ModelBoundary $boundary,
        private UsageLedger $usage,
        private ValueConverter $converter,
        private ActorContext $actor,
        private AiFailureMapper $failures,
    ) {}

    /**
     * @param array<string, bool> $permissions this user's on $visible
     * @param array<array-key, mixed>|null $current the list's filter now, in the list grammar, as context
     * @throws AiRequestException 422 when the answer cannot be made valid, or a relation it names matches nothing
     * @throws \Kinetis\VectorAi\Failure\AiFailure when the provider fails
     */
    public function translate(ResourceDescriptor $visible, array $permissions, ResolvedProvider $provider, string $text, DateTimeZone $zone, ?array $current = null): FilterTranslation
    {
        $schema = $this->boundary->schema($visible, $permissions);
        $filterable = array_values(array_filter($schema['fields'], static fn (array $f): bool => ($f['operators'] ?? []) !== []));

        if ($filterable === []) {
            throw new AiRequestException("{$schema['label']} has no field a filter could name.", 422);
        }

        $fields = array_column($filterable, null, 'name');
        $context = ['resource' => $schema, 'currentFilter' => $this->governed($current, $fields, $visible)];
        $messages = [new UserMessage([
            new TextContent("The resource, and the list's current filter:\n" . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\nYour filter replaces the current one: keep what of it the request does not change. The admin's request follows."),
            new TextContent($text),
        ])];
        $now = new DateTimeImmutable('now', $zone);
        $system = sprintf(self::SYSTEM_PROMPT, $now->format('l, Y-m-d H:i'), $zone->getName(), $zone->getName(), $now->format('P'), $now->format('Y-m-d'), $now->format('P'));
        $answer = FilterSchema::for($filterable);

        $reply = $this->call($provider, $system, $messages, $answer);

        try {
            return $this->accept($reply, $visible, $fields);
        } catch (UnusableAnswerException $e) {
            $messages[] = new AssistantMessage($reply);
            $messages[] = new UserMessage(sprintf(self::REPAIR_PROMPT, $e->getMessage()));
        }

        $reply = $this->call($provider, $system, $messages, $answer);

        try {
            return $this->accept($reply, $visible, $fields);
        } catch (UnusableAnswerException $e) {
            throw new AiRequestException($e->getMessage(), 422);
        }
    }

    /**
     * One structured call, recorded in the usage ledger whatever its end:
     * an answer, a provider failure, or anything else the provider throws,
     * which then propagates as it was.
     *
     * @param list<Message> $messages
     * @param array<string, mixed> $schema
     */
    private function call(ResolvedProvider $provider, string $system, array $messages, array $schema): string
    {
        $started = hrtime(true);
        // what the ledger records if the call ends some other way than the two below
        $outcome = 'failed:error';
        $in = 0;
        $out = 0;

        try {
            $response = $provider->provider->systemPrompt($system)->structured($messages, FilterTranslation::class, $schema);
            $usage = $response->message()->getUsage();
            $in = $usage->inputTokens ?? 0;
            $out = $usage->outputTokens ?? 0;
            $outcome = 'answered';

            return (string) $response->message()->getContent();
        } catch (NeuronException $e) {
            $failure = $this->failures->map($e);
            $outcome = 'failed:' . $failure->category->value;

            throw $failure;
        } finally {
            // one row per call, however it ended
            $this->record($provider, $in, $out, $started, $outcome);
        }
    }

    private function record(ResolvedProvider $provider, int $in, int $out, int $started, string $outcome): void
    {
        $this->usage->record(
            $this->actor->actorId(),
            $this->actor->impersonatorId,
            Feature::Filters,
            $provider->name,
            $provider->model,
            $in,
            $out,
            intdiv(hrtime(true) - $started, 1_000_000),
            0,
            $outcome,
        );
    }

    /**
     * The answer as a list filter, or why it is not one.
     *
     * @param array<string, array<string, mixed>> $fields the filterable fields the model was told of, by name
     * @throws UnusableAnswerException
     */
    private function accept(string $reply, ResourceDescriptor $visible, array $fields): FilterTranslation
    {
        $answer = self::decode($reply);
        $unresolved = [];
        $filter = $this->conditions(self::listOf($answer['conditions'] ?? null, 'conditions'), $visible, $fields, $unresolved, true);
        $groups = [];

        foreach (self::listOf($answer['anyOf'] ?? [], 'anyOf') as $group) {
            $alternatives = $this->alternatives($group, $visible, $fields, $unresolved);

            // "any of" one alternative is that alternative: its conditions join
            // the others, unless one would take the place of a condition already there
            if (count($alternatives) === 1 && self::joins($alternatives[0], $filter, $unresolved)) {
                $filter = array_merge_recursive($filter, $alternatives[0]);
            } elseif ($alternatives !== []) {
                $groups[] = $alternatives;
            }
        }

        $q = $answer['q'] ?? null;

        if ($q !== null && !is_string($q)) {
            throw new UnusableAnswerException('q must be a string or null.');
        }

        $q = $q === null || trim($q) === '' ? null : trim($q);
        $params = ['filter' => self::wire($filter, $groups)];

        if ($q !== null) {
            $params['q'] = $q;
        }

        try {
            ListRequest::fromQueryParams($params, $visible, $this->converter);
        } catch (InvalidListRequestException $e) {
            throw new UnusableAnswerException($e->getMessage(), 0, $e);
        }

        $explanation = is_string($answer['explanation'] ?? null) ? $answer['explanation'] : '';
        $explanation = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $explanation)), 0, self::MAX_EXPLANATION);

        return new FilterTranslation($filter, $groups, $q, $explanation, $unresolved);
    }

    /**
     * One anyOf group's alternatives, each one filter object's conditions.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param list<array{field: string, op: string, text: string, candidates: list<array{id: int|string, label: string}>}> $unresolved
     * @return list<array<string, array<string, string>>>
     * @throws UnusableAnswerException
     */
    private function alternatives(mixed $group, ResourceDescriptor $visible, array $fields, array &$unresolved): array
    {
        $alternatives = [];

        foreach (self::listOf($group, 'each anyOf group') as $alternative) {
            $conditions = $this->conditions(self::listOf($alternative, 'each anyOf alternative'), $visible, $fields, $unresolved, false);

            if ($conditions === []) {
                throw new UnusableAnswerException('every anyOf alternative needs at least one condition.');
            }

            $alternatives[] = $conditions;
        }

        return $alternatives;
    }

    /**
     * Whether $alternative's conditions may join $filter: none takes the
     * place of a condition already there, or of a lookup still unresolved.
     *
     * @param array<string, array<string, string>> $alternative
     * @param array<string, array<string, string>> $filter
     * @param list<array{field: string, op: string, text: string, candidates: list<array{id: int|string, label: string}>}> $unresolved
     */
    private static function joins(array $alternative, array $filter, array $unresolved): bool
    {
        return array_all($alternative, static fn (array $ops, string $name): bool => array_intersect_key($ops, $filter[$name] ?? []) === []
            && !array_any($unresolved, static fn (array $u): bool => $u['field'] === $name && isset($ops[$u['op']])));
    }

    /**
     * One filter object's conditions, `{field: {op: value}}`. A field the
     * model was not told of is refused here, before the list validator
     * would admit a filterable field the boundary keeps back; the refusal
     * names the fields it may use, never the one it wrote.
     *
     * @param list<mixed> $conditions
     * @param array<string, array<string, mixed>> $fields
     * @param list<array{field: string, op: string, text: string, candidates: list<array{id: int|string, label: string}>}> $unresolved
     * @return array<string, array<string, string>>
     * @throws UnusableAnswerException
     */
    private function conditions(array $conditions, ResourceDescriptor $visible, array $fields, array &$unresolved, bool $topLevel): array
    {
        $filter = [];

        foreach ($conditions as $condition) {
            if (!is_array($condition) || !is_string($condition['field'] ?? null) || !is_string($condition['op'] ?? null) || !array_key_exists('value', $condition)) {
                throw new UnusableAnswerException('each condition must be an object with field, op and value.');
            }

            $name = $condition['field'];
            $op = $condition['op'];

            if (!isset($fields[$name])) {
                // the name is not echoed: the repair turn and the admin's 422 name only fields the model was told of
                throw new UnusableAnswerException("a condition names a field this filter cannot use; use only " . implode(', ', array_keys($fields)) . '.');
            }

            // an ambiguous lookup is left out of $filter until someone picks, but it holds its field and operator all the same
            $pending = $topLevel && array_any($unresolved, static fn (array $u): bool => $u['field'] === $name && $u['op'] === $op);

            if (isset($filter[$name][$op]) || $pending) {
                throw new UnusableAnswerException("\"{$name}\" has the operator \"{$op}\" twice in one object.");
            }

            $value = $this->conditionValue($condition['value'], $visible, $fields[$name], $name, $op, $unresolved, $topLevel);

            if ($value === null) {
                continue; // several match: someone picks one
            }

            $filter[$name][$op] = $value;
        }

        return $filter;
    }

    /**
     * A condition's value as the list filter takes it: a lookup's one
     * match, a list of strings joined by commas, or the string itself;
     * null for a lookup several records match, with its `unresolved`
     * entry.
     *
     * @param array<string, mixed> $field the field $name names
     * @param list<array{field: string, op: string, text: string, candidates: list<array{id: int|string, label: string}>}> $unresolved
     * @throws UnusableAnswerException
     */
    private function conditionValue(mixed $value, ResourceDescriptor $visible, array $field, string $name, string $op, array &$unresolved, bool $topLevel): ?string
    {
        if (is_array($value) && array_key_exists('lookup', $value)) {
            return $this->lookup($visible, $field, $op, $value['lookup'], $unresolved, $topLevel);
        }

        if (is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $v): bool => is_string($v))) {
            // the list's grammar, and the admin's filter bar, carry a list as `a,b,c`:
            // a comma inside one value would split it into two without a word
            if (array_any($value, static fn (string $v): bool => str_contains($v, ','))) {
                throw new UnusableAnswerException("a value in the list for \"{$name}\" contains a comma, which a list filter cannot hold; leave that value out, or filter \"{$name}\" another way.");
            }

            return implode(',', $value);
        }

        if (!is_string($value)) {
            throw new UnusableAnswerException("the value of \"{$name}\" must be a string, a list of strings or {\"lookup\": words}.");
        }

        return $value;
    }

    /**
     * A relation's record named in words, looked up as this user's choice
     * list would: the id of the one match; null, with an `unresolved`
     * entry, for several.
     *
     * @param array<string, mixed> $field
     * @param list<array{field: string, op: string, text: string, candidates: list<array{id: int|string, label: string}>}> $unresolved
     * @throws UnusableAnswerException when the field is not a relation, or this user may not list its target
     * @throws AiRequestException 422 when nothing matches, or several do inside an anyOf group
     */
    private function lookup(ResourceDescriptor $visible, array $field, string $op, mixed $words, array &$unresolved, bool $topLevel): ?string
    {
        $name = (string) $field['name'];

        if (!in_array($field['type'] ?? null, ['belongsTo', 'manyToMany'], true)) {
            throw new UnusableAnswerException("\"{$name}\" is not a relation: write its value, not a lookup.");
        }

        if (!is_string($words) || trim($words) === '' || mb_strlen($words) > 200) {
            throw new UnusableAnswerException("the lookup of \"{$name}\" must be the words naming one record.");
        }

        $words = trim($words);

        try {
            $options = $this->resources->options($visible, $name, $words, [], self::CANDIDATES);
        } catch (AuthorizationException) {
            // this user may not list the relation's target: a lookup is not
            // theirs to make, but the rest of the filter may be. The refusal
            // names only the field, which the model was told of, never the target.
            throw new UnusableAnswerException("\"{$name}\" cannot be looked up; leave it out, or filter by another field.");
        }
        $label = (string) ($field['label'] ?? $name);

        if ($options === []) {
            throw new AiRequestException("{$label}: nothing matches \"{$words}\".", 422);
        }

        if (count($options) === 1) {
            return (string) $options[0]['id'];
        }

        if (!$topLevel) {
            throw new AiRequestException("{$label}: several records match \"{$words}\"; name one of them.", 422);
        }

        $unresolved[] = [
            'field' => $name,
            'op' => $op,
            'text' => $words,
            'candidates' => array_map(static fn (array $o): array => ['id' => $o['id'], 'label' => $o['label']], $options),
        ];

        return null;
    }

    /**
     * The list's current filter as context, cut to the fields the model is
     * told of: a condition on any other field, at any depth, is dropped
     * first, and what is left is left out altogether when the list itself
     * would refuse it.
     *
     * @param array<array-key, mixed>|null $current
     * @param array<string, array<string, mixed>> $fields
     * @return array<array-key, mixed>|null
     */
    private function governed(?array $current, array $fields, ResourceDescriptor $visible): ?array
    {
        $kept = $current === null ? [] : self::keep($current, $fields);

        if ($kept === []) {
            return null;
        }

        try {
            ListRequest::fromQueryParams(['filter' => $kept], $visible, $this->converter);
        } catch (InvalidListRequestException) {
            return null;
        }

        return $kept;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, array<string, mixed>> $fields
     * @return array<array-key, mixed>
     */
    private static function keep(array $node, array $fields): array
    {
        $kept = [];

        foreach ($node as $key => $value) {
            if (($key === 'or' || $key === 'and') && is_array($value)) {
                $children = array_values(array_filter(array_map(
                    static fn (mixed $child): array => is_array($child) ? self::keep($child, $fields) : [],
                    $value,
                )));

                if ($children !== []) {
                    $kept[$key] = $children;
                }
            } elseif (is_string($key) && isset($fields[$key])) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * The conditions and the groups as one filter object, the way the list
     * page sends them: one group as `or`, several each under `and`.
     *
     * @param array<string, array<string, string>> $filter
     * @param list<list<array<string, array<string, string>>>> $groups
     * @return array<string, mixed>
     */
    private static function wire(array $filter, array $groups): array
    {
        $wire = $filter;

        if (count($groups) === 1) {
            $wire['or'] = $groups[0];
        } elseif ($groups !== []) {
            $wire['and'] = array_map(static fn (array $group): array => ['or' => $group], $groups);
        }

        return $wire;
    }

    /**
     * The answer's JSON object, inside a Markdown fence or not.
     *
     * @return array<string, mixed>
     * @throws UnusableAnswerException
     */
    private static function decode(string $reply): array
    {
        $json = trim($reply);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $fenced) === 1) {
            $json = $fenced[1];
        }

        try {
            $answer = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new UnusableAnswerException('the answer is not JSON; answer with the JSON object described, and nothing else.');
        }

        if (!is_array($answer) || array_is_list($answer)) {
            throw new UnusableAnswerException('the answer must be the JSON object described.');
        }

        /** @var array<string, mixed> $answer */
        return $answer;
    }

    /**
     * @return list<mixed>
     * @throws UnusableAnswerException
     */
    private static function listOf(mixed $value, string $what): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new UnusableAnswerException("{$what} must be a list.");
        }

        return $value;
    }
}
