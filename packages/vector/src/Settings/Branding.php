<?php

declare(strict_types=1);

namespace Kinetis\Vector\Settings;

use Kinetis\Vector\Attributes\Settings;
use Kinetis\Vector\Fields\Color;
use Kinetis\Vector\Fields\Enum;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Fields\Url;

/**
 * The first settings group: what the shell shows about the
 * application. Every value is structured data the UI renders escaped —
 * a title, a logo URL, two theme colours, a footer line, a default
 * theme — so an admin changes the admin's look without a deployment
 * and without ever injecting markup (§4.15).
 */
#[Settings]
final class Branding extends SettingsGroup
{
    #[\Override]
    public function label(): string
    {
        return 'Branding';
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-palette-outline';
    }

    #[\Override]
    public function fields(): array
    {
        return [
            Text::make('title', 'Application title')->required()->max(60)->default('Vector')
                ->help('Shown in the app bar, the browser tab and the navigation drawer.'),
            Url::make('logoUrl', 'Logo URL')->max(500)
                ->help('An image served by this application or another origin the deployment allows. Empty shows no logo.'),
            Color::make('primaryColor', 'Primary colour (light theme)')->rule(\Kinetis\Validation\Constraints\Regex::class, '/^#[0-9a-fA-F]{6}$/')->default('#1F5F8B'),
            Color::make('primaryColorDark', 'Primary colour (dark theme)')->rule(\Kinetis\Validation\Constraints\Regex::class, '/^#[0-9a-fA-F]{6}$/')->default('#5AA9E6'),
            Enum::make('theme', 'Default theme')->of(ThemePreference::class)->default('system'),
            Text::make('footerText', 'Footer text')->max(200)
                ->help('One line under every page, such as a legal notice or the environment name. Empty shows no footer.'),
        ];
    }
}
