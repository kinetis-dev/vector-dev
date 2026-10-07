<?php

declare(strict_types=1);

namespace App\Vector\Dashboards;

use App\Vector\Support\Widgets\OpenTicketsStat;
use App\Vector\Widgets\LowStockTable;
use App\Vector\Widgets\OrdersByStatus;
use App\Vector\Widgets\OrdersTrend;
use App\Vector\Widgets\RevenueStat;
use Kinetis\Vector\Attributes\Dashboard;
use Kinetis\Vector\Widgets\VectorDashboard;

/** The admin's home page. */
#[Dashboard]
final class MainDashboard extends VectorDashboard
{
    #[\Override]
    public function label(): string
    {
        return 'Overview';
    }

    #[\Override]
    public function widgets(): array
    {
        return [RevenueStat::class, OrdersByStatus::class, OpenTicketsStat::class, OrdersTrend::class, LowStockTable::class];
    }
}
