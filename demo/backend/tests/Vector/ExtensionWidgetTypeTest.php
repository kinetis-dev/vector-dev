<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Registry\WidgetCompiler;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetCache;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;
use Kinetis\Vector\Widgets\WidgetRunner;
use PHPUnit\Framework\TestCase;

/**
 * A widget type beyond the built-in cards: a kebab-case name whose card
 * the application registers in its UI (defineWidget or app.config
 * vector.widgets). It compiles, the descriptor's JSON Schema admits it,
 * and its answer reaches the client as the widget wrote it, with none of
 * a built-in card's shape.
 */
final class ExtensionWidgetTypeTest extends TestCase
{
    private const string OWNER = 'App\Vector\GadgetResource';

    public function testAKebabCaseTypeCompilesBesideTheBuiltInOnes(): void
    {
        $compiled = WidgetCompiler::widgets([ExtensionSparklineWidget::class, ExtensionStatWidget::class], self::OWNER);

        self::assertSame(['sparkline-chart', 'stat'], array_column($compiled, 'type'));
        self::assertSame(['name' => 'extension-sparkline', 'class' => ExtensionSparklineWidget::class, 'label' => 'Extension sparkline', 'type' => 'sparkline-chart', 'icon' => null, 'ranges' => [], 'width' => 4, 'cacheSeconds' => 0, 'cacheShared' => false], $compiled[0]);
        self::assertTrue(VectorWidget::isExtensionType('stock-gauge'));
        self::assertTrue(VectorWidget::isExtensionType('pie'));

        foreach (VectorWidget::BUILT_IN_TYPES as $type) {
            self::assertFalse(VectorWidget::isExtensionType($type), $type);
        }
    }

    public function testAnyOtherTypeIsRefusedAndSaysWhatATypeIs(): void
    {
        $widgets = [
            'Bad_Type' => new class () extends ExtensionRefusedWidget { public function type(): string { return 'Bad_Type'; } },
            'Sparkline' => new class () extends ExtensionRefusedWidget { public function type(): string { return 'Sparkline'; } },
            '' => new class () extends ExtensionRefusedWidget { public function type(): string { return ''; } },
            'bad_type' => new class () extends ExtensionRefusedWidget { public function type(): string { return 'bad_type'; } },
            '-bad' => new class () extends ExtensionRefusedWidget { public function type(): string { return '-bad'; } },
            'bad--type' => new class () extends ExtensionRefusedWidget { public function type(): string { return 'bad--type'; } },
            'bad type' => new class () extends ExtensionRefusedWidget { public function type(): string { return 'bad type'; } },
        ];

        foreach ($widgets as $type => $widget) {
            $type = (string) $type;

            try {
                WidgetCompiler::widgets([$widget::class], self::OWNER);
                self::fail("the type \"{$type}\" compiled");
            } catch (ResourceCompileException $refusal) {
                self::assertSame(
                    'Resource ' . self::OWNER . ': widget ' . $widget::class . " has the type \"{$type}\"; a widget type is stat, trend, partition, progress, table, queues, or an extension type in lower-case kebab-case",
                    $refusal->getMessage(),
                );
            }
        }
    }

    public function testTheRunnerServesAnExtensionWidgetsDataAsItIs(): void
    {
        $runner = new WidgetRunner(new RequestScope(new AppScope()), new ExtensionProbeUser(), new WidgetCache());
        [$compiled] = WidgetCompiler::widgets([ExtensionSparklineWidget::class], self::OWNER);

        // no value, format, points or slices: none of the built-in shapes is asked for or added
        self::assertSame(
            ['type' => 'sparkline-chart', 'samples' => [3, 1, 4], 'nested' => ['anything' => null], 'range' => 'all', 'cached' => false],
            $runner->run($compiled, null, 'dashboards/test'),
        );
    }

    public function testAnExtensionResultTakesNoBuiltInTypeAndNoKeyVectorWrites(): void
    {
        self::assertSame(['type' => 'stock-gauge'], WidgetResult::extension('stock-gauge')->toArray());

        foreach ([...VectorWidget::BUILT_IN_TYPES, 'Bad_Type', 'Sparkline', ''] as $type) {
            try {
                WidgetResult::extension($type);
                self::fail("\"{$type}\" made an extension result");
            } catch (\InvalidArgumentException $refusal) {
                self::assertSame("\"{$type}\" is no extension widget type: one is lower-case kebab-case and none of stat, trend, partition, progress, table, queues, which have factories of their own.", $refusal->getMessage());
            }
        }

        foreach (['type', 'range', 'cached'] as $reserved) {
            try {
                WidgetResult::extension('sparkline-chart', [$reserved => 'x']);
                self::fail("the data carried \"{$reserved}\"");
            } catch (\InvalidArgumentException $refusal) {
                self::assertSame("An extension widget's data cannot carry \"{$reserved}\", which Vector sets on every answer.", $refusal->getMessage());
            }
        }
    }

    /**
     * The descriptor's JSON Schema admits a widget whose type is an
     * extension type, as it admits a built-in one, and refuses a type that
     * is not lower-case kebab-case.
     */
    public function testTheDescriptorSchemaAdmitsAnExtensionWidgetType(): void
    {
        // decoded as objects, so an empty {} in the schema stays one; validated as $defs/widget
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/packages/vector/resources/schema/descriptor.schema.json'), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $schema);
        $widgetSchema = json_encode((object) ['$schema' => $schema->{'$schema'}, '$defs' => $schema->{'$defs'}, '$ref' => '#/$defs/widget'], JSON_THROW_ON_ERROR);

        [$compiled] = WidgetCompiler::widgets([ExtensionSparklineWidget::class], self::OWNER);
        unset($compiled['class']); // the published descriptor names no class
        $validator = new \Opis\JsonSchema\Validator();

        foreach (['sparkline-chart' => true, 'sparkline' => true, 'stat' => true, 'Sparkline' => false, 'sparkline_chart' => false] as $type => $valid) {
            $widget = json_decode(json_encode(['type' => $type] + $compiled, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
            self::assertSame($valid, $validator->validate($widget, $widgetSchema)->isValid(), $type);
        }
    }
}

final class ExtensionSparklineWidget extends VectorWidget
{
    public function type(): string
    {
        return 'sparkline-chart';
    }

    public function ranges(): array
    {
        return [];
    }

    public function calculate(WidgetRequest $request): WidgetResult
    {
        return WidgetResult::extension('sparkline-chart', ['samples' => [3, 1, 4], 'nested' => ['anything' => null]]);
    }
}

final class ExtensionStatWidget extends VectorWidget
{
    public function name(): string
    {
        return 'extension-stat';
    }

    public function type(): string
    {
        return self::STAT;
    }

    public function calculate(WidgetRequest $request): WidgetResult
    {
        return WidgetResult::stat(1);
    }
}

/** A widget the compiler must refuse; each case names its own type. */
abstract class ExtensionRefusedWidget extends VectorWidget
{
    public function name(): string
    {
        return 'refused';
    }

    public function calculate(WidgetRequest $request): WidgetResult
    {
        throw new \LogicException('not computed here');
    }
}

final readonly class ExtensionProbeUser implements CurrentUserInterface
{
    public function id(): string
    {
        return 'probe';
    }
}
