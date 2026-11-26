<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Support\Team;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Code;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Mcp\ResourceDescription;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\VectorResource;

/**
 * Code: source text in a string property, edited in a code editor that
 * highlights one of a fixed set of languages. The demo's support teams
 * keep their routing rules in YAML.
 */
final class CodeFieldTest extends VectorTestCase
{
    use InteractsWithVector;
    use ValidatesInput;

    private ?EntityManager $supportManager = null;

    public function testTheEditorsOptionsArePublished(): void
    {
        self::assertSame(['language' => 'plain', 'lineNumbers' => true, 'rows' => 10], Code::make('snippet')->toArray()['code']);
        self::assertSame(['language' => 'sql', 'lineNumbers' => false, 'rows' => 4], Code::make('snippet')->language('sql')->lineNumbers(false)->rows(4)->toArray()['code']);

        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $rules = $registry->resource('support-teams')->field('routingRules') ?? self::fail('teams have no routing rules');
        self::assertSame(['code', 'string', ['language' => 'yaml', 'lineNumbers' => true, 'rows' => 8]], [$rules['type'], $rules['valueType'], $rules['code']]);
        self::assertSame(['eq', 'ne', 'like', 'nlike', 'in', 'nin'], ResourceCompiler::operatorsFor(['type' => 'code', 'filterable' => true]), 'it filters as text does');

        $described = array_column(ResourceDescription::of($registry->resource('support-teams'), [])['fields'], null, 'name')['routingRules'];
        self::assertSame('source text in yaml', $described['format']);
    }

    public function testALanguageOrHeightOutsideTheSetIsRefusedWhereItIsDeclared(): void
    {
        foreach (['ruby' => static fn () => Code::make('snippet')->language('ruby'), 'JSON' => static fn () => Code::make('snippet')->language('JSON')] as $language => $declare) {
            try {
                $declare();
                self::fail("took {$language}");
            } catch (\InvalidArgumentException $e) {
                self::assertSame("\"snippet\": the editor highlights plain, json, html, css, javascript, typescript, sql, php, yaml, markdown, xml, not \"{$language}\".", $e->getMessage());
            }
        }

        foreach ([1, 41] as $rows) {
            try {
                Code::make('snippet')->rows($rows);
                self::fail("took {$rows} rows");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("rows() takes 2 to 40 lines, not {$rows}", $e->getMessage());
            }
        }

        foreach (Code::LANGUAGES as $language) {
            self::assertSame($language, Code::make('snippet')->language($language)->toArray()['code']['language']);
        }
    }

    public function testItNeedsAStringProperty(): void
    {
        try {
            $this->compiled(new #[Resource(Team::class, slug: 'code-teams')] class extends VectorResource {
                public function fields(): array
                {
                    return [Id::make(), Text::make('name'), Text::make('slug'), Code::make('countries')];
                }
            }, 'code-teams');
            self::fail('compiled a code field on an array property');
        } catch (ResourceCompileException $e) {
            self::assertStringContainsString('is a code field, which stores its text in a string property, not json', $e->getMessage());
        }
    }

    public function testTheTextIsWrittenAndReadBackAsTyped(): void
    {
        $teams = $this->vector($this->realAdminHeaders())->resource('support-teams');
        $id = (int) $this->support()->table('support_teams')->select('id')->orderBy('id', 'ASC')->value('id');
        $stored = $this->support()->table('support_teams')->where('id', '=', $id)->value('routing_rules');
        $yaml = "match:\n  labels: [refund]\n\t# a tab, trailing spaces   \nassign: round-robin\n";

        try {
            self::assertSame($yaml, $teams->update($id, ['routingRules' => $yaml])->assertOk()->record()['routingRules']);
            self::assertSame($yaml, $this->support()->table('support_teams')->where('id', '=', $id)->value('routing_rules'), 'stored byte for byte');
            self::assertSame($yaml, $teams->show($id)->assertOk()->record()['routingRules']);

            self::assertNull($teams->update($id, ['routingRules' => null])->assertOk()->record()['routingRules']);
            $teams->update($id, ['routingRules' => ['assign' => 'round-robin']])->assertInvalid('routingRules', 'type_mismatch');
        } finally {
            $this->support()->table('support_teams')->where('id', '=', $id)->update(['routing_rules' => $stored]);
        }
    }

    /** A builder on the support database, through a manager this test opens and closes. */
    private function support(): Query
    {
        if ($this->supportManager === null) {
            $factories = $this->app->get(OrmFactoryRegistry::class);
            self::assertInstanceOf(OrmFactoryRegistry::class, $factories);
            $this->supportManager = $factories->factory('support')->open();
        }

        return $this->supportManager->builder();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->supportManager?->close();
        $this->supportManager = null;
        parent::tearDown();
    }
}
