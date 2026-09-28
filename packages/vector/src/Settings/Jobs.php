<?php

declare(strict_types=1);

namespace Kinetis\Vector\Settings;

use Kinetis\Validation\Constraints\GreaterThanOrEqual;
use Kinetis\Validation\Constraints\LessThanOrEqual;
use Kinetis\Vector\Attributes\Settings;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Jobs\StalledJobs;

/**
 * How background jobs are watched: after how many minutes without a
 * progress report a running job counts as stalled — its worker died,
 * or was stopped mid-way — so the jobs page says so instead of showing
 * it running for ever, and `kinetis vector:jobs:reap` can mark it
 * failed. An admin edits it on the settings page; 30 minutes until then.
 */
#[Settings]
final class Jobs extends SettingsGroup
{
    public function label(): string
    {
        return 'Background jobs';
    }

    public function icon(): string
    {
        return 'mdi-tray-full';
    }

    public function fields(): array
    {
        return [
            Number::make('stalledAfterMinutes', 'Stalled after (minutes)')->required()->default(StalledJobs::DEFAULT_MINUTES)
                ->rule(GreaterThanOrEqual::class, 1)->rule(LessThanOrEqual::class, 10080)
                ->help('A running job that reports no progress for this many minutes is shown as stalled, and vector:jobs:reap marks it failed.'),
        ];
    }
}
