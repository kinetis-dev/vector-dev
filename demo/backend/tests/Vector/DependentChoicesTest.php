<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Support\Ticket;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;
use App\Vector\Support\AgentResource;
use App\Vector\Support\TeamResource;

/**
 * A dependent choice list: a ticket's assignee is chosen among the agents
 * of the ticket's team. The options endpoint narrows by the form's team,
 * and a write that changes the assignee or the team is checked against it.
 */
final class DependentChoicesTest extends VectorTestCase
{
    use InteractsWithVector;

    private ?EntityManager $supportManager = null;

    public function testTheRelationPublishesWhatItDependsOn(): void
    {
        $fields = array_column($this->vector($this->adminHeaders())->schema('tickets')->assertOk()->json()['fields'], null, 'name');
        self::assertSame(['field' => 'team', 'on' => 'team', 'label' => 'Team'], $fields['assignee']['relation']['dependsOn']);
        self::assertNull($fields['team']['relation']['dependsOn']);
    }

    public function testOptionsAreTheAgentsOfTheFormsTeam(): void
    {
        $tickets = $this->vector($this->realAdminHeaders())->resource('tickets');
        [$team, $members, $outsider] = $this->teamWithOutsider();

        $offered = array_column($tickets->options('assignee', depends: ['team' => (string) $team])->assertOk()->json()['data'], 'id');
        self::assertNotSame([], $offered);
        self::assertSame([], array_diff($offered, $members), 'only the team\'s agents are offered');

        // a list filter has no form to narrow by: it searches every agent
        self::assertContains($outsider, array_column($tickets->options('assignee')->assertOk()->json()['data'], 'id'));

        // resolving a stored choice's label is never narrowed
        $resolved = $this->client->get('/vector/r/tickets/fields/assignee/options', ['ids' => (string) $outsider, 'depends' => ['team' => (string) $team]], $this->realAdminHeaders())->assertOk()->json();
        self::assertIsArray($resolved);
        self::assertSame([$outsider], array_column($resolved['data'], 'id'));

        $this->client->get('/vector/r/tickets/fields/assignee/options', ['depends' => ['team' => 'nope']], $this->realAdminHeaders())->assertStatus(422);
    }

    public function testAWriteThatChangesEitherSideIsChecked(): void
    {
        $tickets = $this->vector($this->realAdminHeaders())->resource('tickets');
        [$team, $members, $outsider, $otherTeam] = $this->teamWithOutsider();
        $row = $this->support()->table('tickets')->select('id', 'team_id', 'assignee_id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($row);
        $id = (int) $row['id'];

        try {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['team_id' => $team, 'assignee_id' => null]);

            $tickets->update($id, ['assignee' => $outsider])->assertInvalid('assignee', 'invalid_choice');
            self::assertSame($members[0], $tickets->update($id, ['assignee' => $members[0]])->assertOk()->json()['data']['assignee']['id']);

            // moving the ticket strands its assignee, unless the assignee moves too
            $tickets->update($id, ['team' => $otherTeam])->assertInvalid('assignee', 'invalid_choice');
            $tickets->update($id, ['team' => $otherTeam, 'assignee' => null])->assertOk();

            // a record stored before the rule saves unchanged; only a change is checked
            $this->support()->table('tickets')->where('id', '=', $id)->update(['team_id' => $team, 'assignee_id' => $outsider]);
            $tickets->update($id, ['subject' => 'Still saves', 'team' => $team, 'assignee' => $outsider])->assertOk();
        } finally {
            $this->support()->table('tickets')->where('id', '=', $id)->update(['team_id' => $row['team_id'], 'assignee_id' => $row['assignee_id']]);
        }
    }

    public function testADependencyNamesAFieldOfTheFormAndAPropertyOfTheTarget(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $noField = new #[Resource(Ticket::class, slug: 'dependent-tickets')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BelongsTo::to('assignee', AgentResource::class)->dependsOn('team')];
            }
        };
        $noProperty = new #[Resource(Ticket::class, slug: 'dependent-tickets')] class extends VectorResource {
            public function fields(): array
            {
                return [Id::make(), BelongsTo::to('team', TeamResource::class), BelongsTo::to('assignee', AgentResource::class)->dependsOn('team', on: 'squad')];
            }
        };

        foreach ([[$noField, 'is not another field of the form'], [$noProperty, 'does not map']] as [$resource, $message]) {
            try {
                (new ResourceCompiler($metadata, [$resource::class, AgentResource::class, TeamResource::class]))->compile();
                self::fail("compiled: {$message}");
            } catch (ResourceCompileException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /** @return array{0: int, 1: list<int>, 2: int, 3: int} a team, its agents, an agent of another team, and that other team */
    private function teamWithOutsider(): array
    {
        $agents = $this->support()->table('agents')->select('id', 'team_id')->orderBy('id', 'ASC')->get();
        $team = (int) $agents[0]['team_id'];
        $members = array_map('intval', array_column(array_filter($agents, static fn (array $a): bool => (int) $a['team_id'] === $team), 'id'));
        $outside = array_values(array_filter($agents, static fn (array $a): bool => (int) $a['team_id'] !== $team))[0];

        return [$team, $members, (int) $outside['id'], (int) $outside['team_id']];
    }

    private function support(): Query
    {
        if ($this->supportManager === null) {
            $factories = $this->app->get(OrmFactoryRegistry::class);
            self::assertInstanceOf(OrmFactoryRegistry::class, $factories);
            $this->supportManager = $factories->factory('support')->open();
        }

        return $this->supportManager->builder();
    }

    protected function tearDown(): void
    {
        $this->supportManager?->close();
        $this->supportManager = null;
        parent::tearDown();
    }
}
