<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Widgets;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\QueueDashboard;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\Vector\Widgets\WidgetRequest;
use Kinetis\Vector\Widgets\WidgetResult;

/**
 * The queue dashboard's card: the jobs waiting and failed across the
 * watched queues, with a link to the Queues page. An application places
 * it by listing this class in a dashboard's widgets(); Vector adds it to
 * none.
 *
 * Its type, `queues`, is a built-in one: the admin UI ships its card,
 * which reads GET /vector/queues itself, so that endpoint and the
 * QueueDashboardPolicyInterface behind it decide what the card shows.
 * The widget's own answer therefore carries no data, and computing it
 * opens no database or queue connection. It has no range to choose and
 * nothing to cache. It is viewable exactly where the dashboard is — a
 * queue configured and the policy's view() — so a dashboard leaves the
 * card out wherever `capabilities.queues` is false.
 *
 * Built by the request container at request time, so the gate and the
 * policy are injected; the definition methods run at compile time on an
 * instance built without its constructor and read neither.
 */
final class QueuesWidget extends VectorWidget
{
    public const string TYPE = VectorWidget::QUEUES;

    public function __construct(
        private readonly QueueDashboard $dashboard,
        private readonly QueueDashboardPolicyInterface $policy,
    ) {}

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
    public function canView(CurrentUserInterface $user): bool
    {
        // the actor the request container would build for this user, as the dashboard's own routes receive it
        return $this->dashboard->available($this->policy, new ActorContext($user));
    }

    #[\Override]
    public function calculate(WidgetRequest $request): WidgetResult
    {
        return WidgetResult::queues();
    }
}
