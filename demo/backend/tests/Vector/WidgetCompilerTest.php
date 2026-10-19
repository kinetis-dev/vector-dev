<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Vector\Widgets\OrdersByStatus;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Registry\WidgetCompiler;
use Kinetis\Vector\Widgets\VectorDashboard;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;
use PHPUnit\Framework\TestCase;

/** Widget and dashboard definitions compiled into descriptor data. */
final class WidgetCompilerTest extends TestCase
{
    private const string OWNER = 'App\Vector\GadgetResource';

    public function testAWidgetCompilesToItsCardAndAnUnsupportedWidthFallsBackToFour(): void
    {
        $quarter = new class () extends VectorWidget {
            public function name(): string { return 'sales-total'; }
            public function label(): string { return 'Sales total'; }
            public function type(): string { return self::STAT; }
            public function icon(): string { return 'mdi-cash'; }
            public function ranges(): array { return ['24h' => 'Today', 'all' => 'All time']; }
            public function width(): int { return 3; }
            public function cacheSeconds(): int { return -5; }
            public function calculate(WidgetRequest $request): WidgetResult { throw new \LogicException('not computed here'); }
        };
        $half = new class () extends VectorWidget {
            public function name(): string { return 'trend'; }
            public function type(): string { return self::TREND; }
            public function width(): int { return 6; }
            public function cacheSeconds(): int { return 30; }
            public function cacheShared(): bool { return true; }
            public function calculate(WidgetRequest $request): WidgetResult { throw new \LogicException('not computed here'); }
        };
        $full = new class () extends VectorWidget {
            public function name(): string { return 'latest-orders'; }
            public function type(): string { return self::TABLE; }
            public function ranges(): array { return []; }
            public function width(): int { return 12; }
            public function calculate(WidgetRequest $request): WidgetResult { throw new \LogicException('not computed here'); }
        };
        $odd = new class () extends VectorWidget {
            public function name(): string { return 'odd-2'; }
            public function type(): string { return self::PROGRESS; }
            public function width(): int { return 5; }
            public function calculate(WidgetRequest $request): WidgetResult { throw new \LogicException('not computed here'); }
        };

        $defaultRanges = ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '1y' => '1 year'];
        self::assertSame([
            ['name' => 'sales-total', 'class' => $quarter::class, 'label' => 'Sales total', 'type' => 'stat', 'icon' => 'mdi-cash', 'ranges' => ['24h' => 'Today', 'all' => 'All time'], 'width' => 3, 'cacheSeconds' => 0, 'cacheShared' => false],
            ['name' => 'trend', 'class' => $half::class, 'label' => 'Trend', 'type' => 'trend', 'icon' => null, 'ranges' => $defaultRanges, 'width' => 6, 'cacheSeconds' => 30, 'cacheShared' => true],
            ['name' => 'latest-orders', 'class' => $full::class, 'label' => 'Latest orders', 'type' => 'table', 'icon' => null, 'ranges' => [], 'width' => 12, 'cacheSeconds' => 0, 'cacheShared' => false],
            ['name' => 'odd-2', 'class' => $odd::class, 'label' => 'Odd 2', 'type' => 'progress', 'icon' => null, 'ranges' => $defaultRanges, 'width' => 4, 'cacheSeconds' => 0, 'cacheShared' => false],
        ], WidgetCompiler::widgets([$quarter::class, $half::class, $full::class, $odd::class], self::OWNER));
    }

    public function testAWidgetNameIsKebabCaseAndARangeFollowsTheGrammar(): void
    {
        $capital = new class () extends VectorWidget {
            public function name(): string { return 'Sales'; }
            public function type(): string { return self::STAT; }
            public function calculate(WidgetRequest $request): WidgetResult { throw new \LogicException('not computed here'); }
        };
        $trailing = new class () extends VectorWidget {
            public function name(): string { return 'sales_'; }
            public function type(): string { return self::STAT; }
            public function calculate(WidgetRequest $request): WidgetResult { throw new \LogicException('not computed here'); }
        };
        $weekly = new class () extends VectorWidget {
            public function name(): string { return 'weekly'; }
            public function type(): string { return self::STAT; }
            public function ranges(): array { return ['7d' => '7 days', 'week' => 'This week']; }
            public function calculate(WidgetRequest $request): WidgetResult { throw new \LogicException('not computed here'); }
        };

        $owner = 'Resource ' . self::OWNER . ': ';
        self::assertSame($owner . 'widget ' . $capital::class . ' has the name "Sales"; a widget name is lower-case kebab-case', $this->refusal(static fn () => WidgetCompiler::widgets([$capital::class], self::OWNER)));
        self::assertSame($owner . 'widget ' . $trailing::class . ' has the name "sales_"; a widget name is lower-case kebab-case', $this->refusal(static fn () => WidgetCompiler::widgets([$trailing::class], self::OWNER)));
        self::assertSame(
            $owner . 'widget ' . $weekly::class . ' declares the range "week"; a range is a number of hours, days, months or years (24h, 30d, 6m, 1y) or all',
            $this->refusal(static fn () => WidgetCompiler::widgets([$weekly::class], self::OWNER)),
        );
    }

    public function testDashboardsComeMainFirstThenByName(): void
    {
        $zeta = new class () extends VectorDashboard {
            public function name(): string { return 'zeta'; }
            public function widgets(): array { return []; }
        };
        $main = new class () extends VectorDashboard {
            public function name(): string { return 'main'; }
            public function label(): string { return 'Overview'; }
            public function icon(): ?string { return null; }
            public function widgets(): array { return [OrdersByStatus::class]; }
        };
        $alpha = new class () extends VectorDashboard {
            public function name(): string { return 'alpha'; }
            public function widgets(): array { return []; }
        };

        $dashboards = WidgetCompiler::dashboards([$zeta::class, $main::class, $alpha::class]);

        self::assertSame(['main', 'alpha', 'zeta'], array_keys($dashboards));
        self::assertSame(['name' => 'main', 'class' => $main::class, 'label' => 'Overview', 'icon' => null], array_diff_key($dashboards['main'], ['widgets' => true]));
        self::assertSame([OrdersByStatus::class], array_column($dashboards['main']['widgets'], 'class'));
        self::assertSame(['name' => 'zeta', 'class' => $zeta::class, 'label' => 'Zeta', 'icon' => 'mdi-view-dashboard-outline', 'widgets' => []], $dashboards['zeta']);
        self::assertSame(['main', 'alpha', 'zeta'], array_keys(WidgetCompiler::dashboards([$main::class, $zeta::class, $alpha::class])));
        self::assertSame(['main', 'alpha', 'zeta'], array_keys(WidgetCompiler::dashboards([$alpha::class, $zeta::class, $main::class])));
    }

    public function testADashboardNameIsKebabCase(): void
    {
        $capital = new class () extends VectorDashboard {
            public function name(): string { return 'Main'; }
            public function widgets(): array { return []; }
        };
        $trailing = new class () extends VectorDashboard {
            public function name(): string { return 'main_'; }
            public function widgets(): array { return []; }
        };

        self::assertSame('Resource ' . $capital::class . ': has the dashboard name "Main"; a dashboard name is lower-case kebab-case', $this->refusal(static fn () => WidgetCompiler::dashboards([$capital::class])));
        self::assertSame('Resource ' . $trailing::class . ': has the dashboard name "main_"; a dashboard name is lower-case kebab-case', $this->refusal(static fn () => WidgetCompiler::dashboards([$trailing::class])));
    }

    private function refusal(\Closure $compile): string
    {
        try {
            $compile();
        } catch (ResourceCompileException $e) {
            return $e->getMessage();
        }

        self::fail('The definitions compiled.');
    }
}
