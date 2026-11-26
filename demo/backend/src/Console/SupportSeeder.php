<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Support\Agent;
use App\Domain\Support\AgentRole;
use App\Domain\Support\Channel;
use App\Domain\Support\Label;
use App\Domain\Support\Team;
use App\Domain\Support\Ticket;
use App\Domain\Support\TicketPriority;
use App\Vector\Support\TicketResource;
use DateTimeImmutable;
use Kinetis\Orm\Date;
use DateTimeZone;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityManagerRegistry;
use stdClass;

/**
 * Seeds the support desk on the PostgreSQL connection "support", through
 * that connection's own manager. Tickets reference shop customers by
 * value (e-mail and id), read from the MySQL side by the caller.
 */
final readonly class SupportSeeder
{
    private const array TEAMS = ['Billing' => 'billing@vector.local', 'Shipping' => 'shipping@vector.local', 'Technical' => 'tech@vector.local', 'Returns' => 'returns@vector.local'];

    /** @var array<string, list<string>> team => the ISO 3166-1 alpha-2 codes it serves; Technical serves everywhere */
    private const array SERVES = ['Billing' => ['NL', 'DE', 'AT'], 'Shipping' => ['NL', 'DE', 'PT', 'IE'], 'Returns' => ['IL', 'CZ', 'NO']];

    private const array LABELS = ['refund' => '#E53935', 'damaged' => '#FB8C00', 'late-delivery' => '#FDD835', 'invoice' => '#43A047', 'warranty' => '#1E88E5', 'vip' => '#8E24AA', 'bug' => '#6D4C41', 'feedback' => '#00ACC1', 'duplicate' => '#757575', 'follow-up' => '#3949AB'];

    private const array SUBJECTS = ['Where is my order', 'Refund not received', 'Item arrived damaged', 'Wrong size delivered', 'Invoice needs a VAT number', 'Cannot log in', 'Warranty claim', 'Change delivery address', 'Discount code rejected', 'Product stopped working'];

    private const array AGENTS = ['Noa Levi', 'Tom Baker', 'Mia Chen', 'Lukas Weber', 'Sara Costa', 'Omar Haddad', 'Eva Novak', 'Liam Walsh', 'Ines Silva', 'Jonas Berg', 'Yael Cohen', 'Marta Ruiz'];

    public function __construct(private EntityManagerRegistry $managers) {}

    public function isEmpty(): bool
    {
        return !$this->manager()->builder()->table('support_teams')->exists();
    }

    public function truncate(): void
    {
        foreach (['ticket_label', 'ticket_replies', 'tickets', 'labels', 'agents', 'support_teams'] as $table) {
            $this->manager()->builder()->table($table)->whereRaw('1 = 1')->delete();
        }
    }

    /**
     * @param list<array{id: int, email: string}> $customers
     * @return array{teams: int, agents: int, labels: int, tickets: int}
     */
    public function seed(int $scale, array $customers): array
    {
        $entities = $this->manager();
        $utc = new DateTimeZone('UTC');
        $teams = [];

        foreach (self::TEAMS as $name => $email) {
            $team = new Team($name, strtolower($name), $email);
            // a multiple country field: the countries each team serves
            $team->serve(self::SERVES[$name] ?? null);
            $entities->persist($team);
            $teams[] = $team;
        }

        $agents = [];

        foreach (self::AGENTS as $i => $name) {
            $agent = new Agent(
                $name,
                strtolower(str_replace(' ', '.', $name)) . '@vector.local',
                $teams[$i % count($teams)],
                $i < 4 ? AgentRole::Lead : ($i === 4 ? AgentRole::Manager : AgentRole::Agent),
                Date::fromString((new DateTimeImmutable('-' . mt_rand(30, 1500) . ' days', $utc))->format('Y-m-d')),
            );

            if ($i === count(self::AGENTS) - 1) {
                $agent->deactivate();
            }

            $entities->persist($agent);
            $agents[] = $agent;
        }

        $labels = [];

        foreach (self::LABELS as $name => $color) {
            $label = new Label($name, $color);
            $entities->persist($label);
            $labels[] = $label;
        }

        $entities->flush();
        $count = 300 * $scale;
        $channels = Channel::cases();
        $priorities = TicketPriority::cases();

        for ($i = 1; $i <= $count; $i++) {
            $customer = $customers === [] ? ['id' => null, 'email' => "guest{$i}@example.com"] : $customers[array_rand($customers)];
            $createdAt = new DateTimeImmutable('-' . mt_rand(0, 180) . ' days -' . mt_rand(0, 86399) . ' seconds', $utc);
            $team = $teams[array_rand($teams)];
            $ticket = new Ticket(
                sprintf('TCK-%06d', $i),
                self::SUBJECTS[array_rand(self::SUBJECTS)] . " #{$i}",
                "Hello,\n\nI need help with my recent order. Please get back to me.\n\nThanks",
                $team,
                $channels[array_rand($channels)],
                $customer['email'],
                $customer['id'],
                $createdAt,
            );
            $priority = $priorities[mt_rand(0, 9) < 6 ? 1 : array_rand($priorities)];
            $ticket->prioritize($priority, mt_rand(0, 2) === 0 ? null : $createdAt->modify('+' . mt_rand(1, 7) . ' days'));

            // an assignee comes from the ticket's own team, the choice the admin narrows to
            $members = array_values(array_filter($agents, static fn (Agent $agent): bool => $agent->team() === $team));

            if ($members !== [] && mt_rand(0, 4) !== 0) {
                $ticket->assign($members[array_rand($members)]);
            }

            $chosen = [];

            for ($l = mt_rand(0, 2); $l > 0; $l--) {
                $label = $labels[array_rand($labels)];
                $chosen[spl_object_id($label)] = $label;
            }

            $ticket->label(array_values($chosen));
            $ticket->annotate((object) ['browser' => ['Chrome', 'Firefox', 'Safari'][mt_rand(0, 2)], 'orderRef' => sprintf('ORD-%06d', mt_rand(1, 400))]);
            // a multiple select: some of the platforms, in the order the resource lists them
            $ticket->affects(DemoSeedCommand::someOf(TicketResource::PLATFORMS, $i * 7));
            // a JSON-mode flag group: from the index, a key at times left out (it reads as false)
            $ticket->flag(self::flagsOf($i));
            // a tag list, from the index alone: the random sequence stays as it was
            $ticket->keyword(DemoSeedCommand::someOf(array_combine(TicketResource::KEYWORDS, TicketResource::KEYWORDS), $i * 5));

            for ($r = mt_rand(0, 3); $r > 0; $r--) {
                // the metadata from the index and the reply alone: the random sequence stays as it was
                $ticket->reply(mt_rand(0, 1) === 0 ? 'Customer' : self::AGENTS[array_rand(self::AGENTS)], 'Thanks, we are looking into it.', mt_rand(0, 4) === 0, $createdAt->modify("+{$r} hours"), self::replyMetadataOf($i, $r));
            }

            $roll = mt_rand(1, 10);

            if ($roll <= 4) {
                $ticket->resolve($createdAt->modify('+' . mt_rand(1, 72) . ' hours'), mt_rand(1, 5), mt_rand(0, 5) === 0 ? mt_rand(5, 50) * 100 : 0);
            }

            if ($roll === 5) {
                $ticket->resolve($createdAt->modify('+3 days'), null);
                $ticket->close();
            }

            $entities->persist($ticket);

            if ($i % 100 === 0) {
                $entities->flush();
            }
        }

        $entities->flush();

        return ['teams' => count($teams), 'agents' => count($agents), 'labels' => count($labels), 'tickets' => $count];
    }

    private function manager(): EntityManager
    {
        return $this->managers->manager('support');
    }

    /**
     * A reply's metadata, from its ticket's index and its own number: about
     * half the replies carry none, and of the rest some hold the client
     * and channel they came through, an empty object or one with an empty
     * object inside, which a JSON field keeps as sent.
     *
     * An object is a stdClass at every level: the property keeps objects.
     */
    private static function replyMetadataOf(int $i, int $r): ?stdClass
    {
        return match (($i * 3 + $r) % 8) {
            0 => (object) ['channel' => 'email', 'client' => (object) ['name' => 'Mail', 'locale' => 'en-GB']],
            1 => (object) ['channel' => 'chat', 'client' => (object) ['name' => 'Web widget', 'version' => '4.2.1'], 'attachments' => 0],
            2 => new stdClass(),
            3 => (object) ['channel' => 'phone', 'transcript' => new stdClass()],
            default => null,
        };
    }

    /**
     * A ticket's flags, picked from its index: every fourth ticket has
     * none (null), and one key is at times left out rather than false.
     *
     * @return array<string, bool>|null
     */
    private static function flagsOf(int $i): ?array
    {
        if ($i % 4 === 0) {
            return null;
        }

        $flags = ['vip' => $i % 3 === 0, 'refundRequested' => $i % 5 === 0];

        if ($i % 2 === 0) {
            $flags['legalHold'] = $i % 7 === 0;
        }

        return $flags;
    }
}
