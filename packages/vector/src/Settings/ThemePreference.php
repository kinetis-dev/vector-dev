<?php

declare(strict_types=1);

namespace Kinetis\Vector\Settings;

enum ThemePreference: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
