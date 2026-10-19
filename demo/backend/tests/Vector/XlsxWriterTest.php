<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Query\XlsxWriter;
use PHPUnit\Framework\TestCase;

/** The demo installs openspout/openspout and the zip extension, so XLSX can be written. */
final class XlsxWriterTest extends TestCase
{
    public function testXlsxIsAvailableWithThePackageAndTheZipExtension(): void
    {
        self::assertTrue(class_exists(\OpenSpout\Writer\XLSX\Writer::class));
        self::assertTrue(extension_loaded('zip'));
        self::assertTrue(XlsxWriter::available());
    }
}
