<?php

declare(strict_types=1);

namespace Kinetis\Vector\Settings;

/**
 * The languages Vector's UI ships — English is the base every other one
 * falls back to — in Locales::SHIPPED's order, which the default-language
 * choice lists them in.
 */
enum Language: string
{
    case German = 'de';
    case English = 'en';
    case Spanish = 'es';
    case French = 'fr';
    case Italian = 'it';
    case Dutch = 'nl';
    case Portuguese = 'pt';
    case Hebrew = 'he';
}
