<?php

declare(strict_types=1);

namespace App\Vector\Settings;

use Kinetis\Vector\Attributes\Settings;
use Kinetis\Vector\Fields\Email;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Settings\SettingsGroup;

/** An application-defined settings group on Vector's mechanism; the low-stock threshold drives a widget. */
#[Settings]
final class StoreSettings extends SettingsGroup
{
    #[\Override]
    public function name(): string
    {
        return 'store';
    }

    #[\Override]
    public function label(): string
    {
        return 'Store';
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-storefront-outline';
    }

    #[\Override]
    public function fields(): array
    {
        return [
            Text::make('storeName', 'Store name')->required()->max(80)->default('Vector Demo Store'),
            Email::make('supportEmail', 'Support e-mail')->max(120),
            Number::make('lowStockThreshold', 'Low-stock threshold')->required()->default(10)
                ->rule(\Kinetis\Validation\Constraints\GreaterThanOrEqual::class, 0)
                ->help('Products at or below this stock count appear in the low-stock widget.'),
        ];
    }
}
