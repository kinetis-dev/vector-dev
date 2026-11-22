<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Http;

use Kinetis\Http\Attributes\Delete;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Put;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Store\AuditStore;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Config\ProviderConfig;
use Kinetis\VectorAi\Config\ProviderType;
use Kinetis\VectorAi\Secrets\CredentialStore;
use Kinetis\VectorAi\Secrets\SecretBox;
use Kinetis\Http\Responses\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The provider keys an administrator enters, for the providers whose key
 * the environment does not give: listed by status, sealed and stored, or
 * removed. A key goes in and never comes out; no answer, error or log
 * line carries it. Every route asks AiAccessInterface::canConfigure().
 *
 * Storing and removing a key is recorded in Vector's audit trail
 * (`vector_audit`), under the resource `vector-ai:credentials` and the
 * provider's name, with the event `set` or `delete`, the actor and the
 * impersonator: who changed which provider's key, never the key.
 */
#[RoutePrefix('/vector/ai')]
#[Middleware('@vector')]
final readonly class CredentialsController
{
    private const int MAX_KEY_LENGTH = 4096;

    /** The audit trail's resource for a provider key; the record is the provider's name. */
    public const string AUDIT_RESOURCE = 'vector-ai:credentials';

    public function __construct(
        private CurrentUserInterface $user,
        private AiAccessInterface $access,
        private AiConfig $config,
        private CredentialStore $credentials,
        private SecretBox $box,
        private AuditStore $audit,
        private ActorContext $actor,
    ) {}

    /** Provider => status, an object even with no provider configured. */
    #[Get('/credentials')]
    public function index(): ResponseInterface
    {
        $this->authorize();
        $status = $this->credentials->status();

        return JsonResponse::create($status === [] ? new \stdClass() : $status, 200, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> the provider's status after the write */
    #[Put('/credentials/{provider}')]
    public function store(string $provider, ServerRequestInterface $request): array
    {
        $this->authorize();
        $configured = $this->provider($provider);

        if (!$this->box->canSeal()) {
            throw new AiRequestException('Set ' . SecretBox::CONFIG_KEY . ' to store provider keys.', 403);
        }

        if (!CredentialStore::storable($configured)) {
            throw new AiRequestException($configured->keyFromEnvironment() || $configured->type === ProviderType::Bedrock
                ? "The key of \"{$provider}\" is managed by the environment."
                : "A {$configured->type->value} provider takes no API key.", 409);
        }

        $this->credentials->put($configured, $this->keyFrom($request), (string) $this->user->id());
        $this->recordAudit($configured, 'set');

        return $this->credentials->statusFor($configured);
    }

    /** @return array<string, mixed> the provider's status after the removal */
    #[Delete('/credentials/{provider}')]
    public function destroy(string $provider): array
    {
        $this->authorize();
        $configured = $this->provider($provider);

        if ($this->credentials->delete($configured)) {
            $this->recordAudit($configured, 'delete');
        }

        return $this->credentials->statusFor($configured);
    }

    /** Who changed $provider's key, and how: no part of the key. */
    private function recordAudit(ProviderConfig $provider, string $event): void
    {
        $this->audit->record(self::AUDIT_RESOURCE, [$provider->name], $event, [], $this->actor->actorId(), $this->actor->impersonatorId);
    }

    private function authorize(): void
    {
        if (!$this->access->canConfigure($this->user)) {
            throw new AiRequestException('This account may not configure AI.', 403);
        }
    }

    private function provider(string $name): ProviderConfig
    {
        return $this->config->provider($name) ?? throw new AiRequestException("\"{$name}\" is not a configured AI provider.", 404);
    }

    /** The `key` of the JSON body: a non-empty, single-line string, never echoed back. */
    private function keyFrom(ServerRequestInterface $request): string
    {
        try {
            $body = json_decode((string) $request->getBody(), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body is not valid JSON.')]);
        }

        $key = is_array($body) ? ($body['key'] ?? null) : null;

        if (!is_string($key) || trim($key) === '') {
            throw ValidationException::fromViolations([new Violation(['key'], 'required', 'is required.')]);
        }

        $key = trim($key);

        if (strlen($key) > self::MAX_KEY_LENGTH || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1) {
            throw ValidationException::fromViolations([new Violation(['key'], 'invalid', 'must be printable ASCII without spaces, at most ' . self::MAX_KEY_LENGTH . ' characters.')]);
        }

        return $key;
    }
}
