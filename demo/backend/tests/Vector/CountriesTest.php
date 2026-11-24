<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Support\Countries;
use PHPUnit\Framework\TestCase;

/**
 * The ISO 3166-1 alpha-2 country list the Country field draws on.
 */
final class CountriesTest extends TestCase
{
    public function testHoldsTheTwoHundredFortyNineAssignedCodesSorted(): void
    {
        $codes = Countries::codes();

        self::assertCount(249, $codes);
        self::assertCount(249, array_unique($codes));

        $sorted = $codes;
        sort($sorted);
        self::assertSame($sorted, $codes);
        self::assertSame(1, preg_match('/^[A-Z]{2}$/', $codes[0]));
    }

    public function testNamesACodeInEnglish(): void
    {
        self::assertSame('France', Countries::name('FR'));
        self::assertSame(Countries::codes(), array_keys(Countries::NAMES));
    }

    public function testRefusesAnUnassignedCode(): void
    {
        self::assertNull(Countries::name('XX'));
        self::assertNull(Countries::name('fr'));
        self::assertNull(Countries::name(''));
    }
}
