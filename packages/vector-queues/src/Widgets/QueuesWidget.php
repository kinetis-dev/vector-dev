<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Widgets;

use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;

/**
 * The queue dashboard's card: the jobs waiting and failed across the
 * watched queues, with a link to the Queues page. An application places
 * it by listing this class in a dashboard's widgets(); the package adds
 * it to none.
 *
 * Its type, `vector-queues`, is an extension type: the package's UI
 * module (resources/ui/queues.js) registers its renderer, which reads
 * GET /vector/queues itself, so that endpoint and the
 * QueueDashboardPolicyInterface behind it decide what the card shows.
 * The widget's own answer therefore carries no data, and computing it
 * opens no database or queue connection. It has no range to choose and
 * nothing to cache.
 */
final class QueuesWidget extends VectorWidget
{
    public const string TYPE = 'vector-queues';

    #[\Override]
    public function label(): string
    {
        return 'Queues';
    }

    #[\Override]
    public function type(): string
    {
        return self::TYPE;
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-tray-full';
    }

    /** @return array<string, string> */
    #[\Override]
    public function ranges(): array
    {
        return [];
    }

    #[\Override]
    public function cacheSeconds(): int
    {
        return 0;
    }

    #[\Override]
    public function calculate(WidgetRequest $request): WidgetResult
    {
        return WidgetResult::extension(self::TYPE);
    }
}
