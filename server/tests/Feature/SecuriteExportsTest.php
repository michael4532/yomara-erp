<?php

namespace Tests\Feature;

use App\Support\SafeCell;
use Tests\TestCase;

/** Exports tableur : un texte saisi par un visiteur ne devient jamais une formule. */
class SecuriteExportsTest extends TestCase
{
    public function test_formula_injection_neutralised(): void
    {
        $this->assertSame("'=HYPERLINK(\"https://x\")", SafeCell::csv('=HYPERLINK("https://x")'));
        $this->assertSame("'+cmd", SafeCell::csv('+cmd'));
        $this->assertSame("'@SUM(A1)", SafeCell::csv('@SUM(A1)'));
        $this->assertSame('-12,5', SafeCell::csv('-12,5'));
        $this->assertSame(-3, SafeCell::csv(-3));
        $this->assertSame('Awa', SafeCell::csv('Awa'));

        $dir = sys_get_temp_dir().'/se'.uniqid();
        mkdir($dir);
        \App\Modules\ImportExport\Sheet::write($dir.'/a.xlsx', ['Nom'], [['=1+1'], [5]]);
        $zip = new \ZipArchive;
        $zip->open($dir.'/a.xlsx');
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('=1+1', $xml);
        \App\Modules\ImportExport\Sheet::write($dir.'/a.csv', ['Nom'], [['=1+1']]);
        $this->assertStringContainsString("'=1+1", file_get_contents($dir.'/a.csv'));
    }
}
