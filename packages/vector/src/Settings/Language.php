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
    case Polish = 'pl';
    case BrazilianPortuguese = 'pt-BR';
    case Portuguese = 'pt';
    case Turkish = 'tr';
    case Russian = 'ru';
    case Ukrainian = 'uk';
    case Hebrew = 'he';
    case Arabic = 'ar';
    case Korean = 'ko';
    case Japanese = 'ja';
    case SimplifiedChinese = 'zh-Hans';
}
