<?php

namespace Tests\Feature;

use App\Modules\Compta\Models\Account;
use App\Modules\Compta\Models\Entry;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Reprise depuis Sage : tiers et articles (noms de champs Sage 100), balance d'ouverture, écritures. */
class ImportComptaTest extends TestCase
{
    protected function csv(string $name, array $headers, array $rows): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid().'_'.$name;
        $csv = implode("\r\n", array_map(fn ($r) => implode(';', $r), array_merge([$headers], $rows)));
        file_put_contents($path, mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_sage_partners_products_balance_and_entries(): void
    {
        [$company, , $token] = $this->makeCompany('Reprise Sage SARL');
        $api = fn () => $this->api($token);
        $import = function (string $entity, UploadedFile $f, array $opts = []) use ($api) {
            $a = $api()->post('/api/import_export/analyze', ['entity' => $entity, 'file' => $f])->assertOk()->json('data');

            return [$a, $api()->postJson("/api/import_export/jobs/{$a['job_id']}/run", $opts + ['mapping' => $a['mapping'], 'mode' => 'upsert', 'key' => array_key_first($opts['keys'] ?? []) ?? ($opts['key'] ?? 'name')])];
        };

        // 1) Tiers exportés de Sage 100 (noms de champs) : 0 = client, 1 = fournisseur
        [$a, $r] = $import('partners', $this->csv('F_COMPTET.csv', ['CT_Num', 'CT_Intitule', 'CT_Type', 'CT_Telephone', 'CT_Ville', 'CT_Identifiant'], [
            ['CLT001', 'Pharmacie du Plateau', '0', '27 20 21 22 23', 'Abidjan', '1234567A'],
            ['FRS001', 'Grossiste Adjamé', '1', '', 'Abidjan', ''],
        ]), ['key' => 'code']);
        $this->assertEquals(['code' => 0, 'name' => 1, 'type' => 2, 'phone' => 3, 'city' => 4, 'tax_id' => 5], $a['mapping']);
        $r->assertOk()->assertJsonPath('data.created', 2);
        $p = collect($api()->getJson('/api/core/partners')->json('data'))->keyBy('code');
        $this->assertSame(['customer', 'supplier'], [$p['CLT001']['type'], $p['FRS001']['type']]);

        // 2) Articles Sage
        [$a, $r] = $import('products', $this->csv('F_ARTICLE.csv', ['AR_Ref', 'AR_Design', 'FA_CodeFamille', 'AR_PrixVen', 'AR_PrixAch'], [['PARA01', 'Paracétamol 500', 'MEDIC', '1500', '900']]), ['key' => 'sku']);
        $this->assertEquals(['sku' => 0, 'name' => 1, 'category' => 2, 'sale_price' => 3, 'purchase_price' => 4], $a['mapping']);
        $r->assertOk()->assertJsonPath('data.created', 1);

        // 3) Balance d'ouverture (comptes Sage à 8 chiffres), vérification à blanc puis import
        $bal = $this->csv('balance.csv', ['N° compte', 'Intitulé', 'Solde débiteur', 'Solde créditeur'], [
            ['10100000', 'Capital social', '', '5 000 000'],
            ['52110000', 'Banque SGBCI', '3 200 000', ''],
            ['57100000', 'Caisse', '400 000', ''],
            ['41110000', 'Clients', '1 500 000', ''],
            ['40110000', 'Fournisseurs', '', '0'],
            ['44310000', 'TVA facturée', '', '0'],
        ]);
        [$a, $dry] = $import('opening_balance', $bal, ['key' => 'account', 'dry_run' => true]);
        $dry->assertOk();
        $this->assertSame(0, Entry::count()); // à blanc : rien d'écrit
        $this->assertSame(4, $dry->json('data.failed'));
        $this->assertStringContainsString('déséquilibrée', $dry->json('data.errors.0.message'));

        $bal = $this->csv('balance2.csv', ['N° compte', 'Intitulé', 'Solde débiteur', 'Solde créditeur'], [
            ['10100000', 'Capital social', '', '5 000 000'],
            ['52110000', 'Banque SGBCI', '3 200 000', ''],
            ['57100000', 'Caisse', '300 000', ''],
            ['41110000', 'Clients', '1 500 000', ''],
            ['40110000', 'Fournisseurs', '', '0'],
        ]);
        [, $r] = $import('opening_balance', $bal, ['key' => 'account']);
        $r->assertOk()->assertJsonPath('data.failed', 0)->assertJsonPath('data.created', 4)->assertJsonPath('data.skipped', 1);
        $e = Entry::with('lines')->where('reference', 'REPRISE-BALANCE')->firstOrFail();
        $this->assertEquals(5000000, $e->lines->sum('debit'));
        $this->assertEquals(5000000, $e->lines->sum('credit'));
        // Les comptes Sage retrouvent ceux du plan (zéros de fin)
        $this->assertNull(Account::where('code', '52110000')->first());
        // Deuxième import refusé
        [, $r] = $import('opening_balance', $bal, ['key' => 'account']);
        $this->assertStringContainsString('déjà été importée', $r->json('data.errors.0.message'));

        // 4) Écritures Sage (Montant + Sens 0/1), une pièce déséquilibrée refusée, pièces groupées
        $ecr = $this->csv('ecritures.csv', ['JO_Num', 'EC_Date', 'EC_Piece', 'CG_Num', 'CT_Num', 'EC_Intitule', 'EC_Montant', 'EC_Sens'], [
            ['VTE', '15/09/2026', 'FA001', '41110000', 'CLT001', 'Facture Pharmacie', '118000', '0'],
            ['VTE', '15/09/2026', 'FA001', '70110000', '', 'Facture Pharmacie', '100000', '1'],
            ['VTE', '15/09/2026', 'FA001', '44310000', '', 'TVA', '18000', '1'],
            ['BQ', '20/09/2026', 'RG001', '52110000', '', 'Règlement Pharmacie', '50000', '0'],
            ['BQ', '20/09/2026', 'RG001', '41110000', 'CLT001', 'Règlement Pharmacie', '40000', '1'],
        ]);
        $a = $api()->post('/api/import_export/analyze', ['entity' => 'entries', 'file' => $ecr])->assertOk()->json('data');
        $this->assertEquals(['journal' => 0, 'date' => 1, 'piece' => 2, 'account' => 3, 'partner' => 4, 'label' => 5, 'amount' => 6, 'direction' => 7], $a['mapping']);
        $r = $api()->postJson("/api/import_export/jobs/{$a['job_id']}/run", ['mapping' => $a['mapping'], 'mode' => 'upsert', 'key' => 'piece'])->assertOk()->json('data');
        $this->assertSame([3, 2, 1], [$r['created'], $r['failed'], $r['entries_created']]);
        $this->assertStringContainsString('RG001', $r['errors'][0]['message']);
        $fa = Entry::with('lines')->where('reference', 'FA001')->firstOrFail();
        $this->assertNotNull($fa->lines->firstWhere('debit', 118000)->partner_id); // tiers rattaché
        // Réimport : la pièce déjà reprise est ignorée
        $r2 = $api()->postJson("/api/import_export/jobs/{$a['job_id']}/run", ['mapping' => $a['mapping'], 'mode' => 'upsert', 'key' => 'piece'])->json('data');
        $this->assertSame(3, $r2['skipped']);
        $this->assertSame(1, Entry::where('reference', 'FA001')->count());
    }
}
