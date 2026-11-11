<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Un même client présent deux fois dans le fichier ne crée qu'une fiche (la deuxième ligne la met à jour). */
class ImportDuplicateRowsTest extends TestCase
{
    public function test_same_partner_twice_in_one_file(): void
    {
        [, , $token] = $this->makeCompany('Doublons SARL');
        $path = sys_get_temp_dir().'/'.uniqid().'_c.csv';
        file_put_contents($path, "Nom;Ville\r\nMaquis Bon Goût;Cocody\r\nMaquis Bon Goût;Yopougon\r\n");
        $a = $this->api($token)->post('/api/import_export/analyze', ['entity' => 'partners', 'file' => new UploadedFile($path, 'c.csv', null, null, true)])->assertOk()->json('data');
        $r = $this->api($token)->postJson("/api/import_export/jobs/{$a['job_id']}/run", ['mapping' => $a['mapping'], 'mode' => 'upsert', 'key' => 'name'])->assertOk()->json('data');
        $this->assertSame([1, 1], [$r['created'], $r['updated']]);
        $this->assertCount(1, $this->api($token)->getJson('/api/core/partners')->json('data'));
    }
}
