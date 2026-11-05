<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Formulaire d'appel de fonds personnalisable : motif, remise et taxes, modalités, comptabilité, modèle (logo, couleurs, listes), PDF. */
class FondsFormulaireTest extends TestCase
{
    public function test_form_fields_totals_template_and_pdf(): void
    {
        Storage::fake('public');
        [$company, , $token] = $this->makeCompany('NSI Côte d\'Ivoire');
        $api = fn () => $this->api($token);

        // Modèle de la société : titre, préfixe, couleurs, listes, pied de page, TVA par défaut
        $form = $api()->putJson('/api/fonds/form', ['title' => 'DEMANDE D\'APPEL DE FONDS', 'prefix' => 'nsi-af', 'color_primary' => '#123456', 'logo_position' => 'left',
            'motives' => ['Fournisseur / prestataire', 'Frais de mission', ' '], 'payment_methods' => ['Virement bancaire', 'Julaya'], 'visas' => ['Demandeur', 'Supérieur hiérarchique', 'Chef comptable'],
            'sections' => ['accounting' => false], 'default_vat' => 18, 'footer' => ['rccm' => 'CI-ABJ-2020-B-1234', 'website' => 'nsi.ci']])->assertOk()->json('data');
        $this->assertSame('NSI-AF', $form['prefix']);
        $this->assertSame(['Fournisseur / prestataire', 'Frais de mission'], $form['motives']);
        $this->assertFalse($form['sections']['accounting']);
        $this->assertTrue($form['sections']['visas']);
        $api()->putJson('/api/fonds/form', ['color_primary' => 'red'])->assertStatus(422);
        $this->flushHeaders();

        // Logo du formulaire
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $f = $api()->post('/api/fonds/form/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertOk()->json('data');
        $this->assertSame('custom', $f['logo_mode']);
        $this->assertNotNull($f['custom_logo_url']);
        $this->flushHeaders();

        // Demande : TVA 18 % proposée par défaut, remise de 10 %
        $r = $api()->postJson('/api/fonds/requests', ['title' => 'Mission terrain Bouaké', 'department' => 'Programmes', 'motive' => 'Frais de mission', 'requester_function' => 'Chargé de projet',
            'beneficiary' => 'Hôtel Le Ran', 'beneficiary_structure' => 'Le Ran SARL', 'payment_method' => 'Julaya', 'priority' => 'very_urgent', 'attachments' => ['Devis', 'Facture'],
            'discount_mode' => 'percent', 'discount_value' => 10,
            'lines' => [['description' => 'Hébergement', 'quantity' => 2, 'unit_amount' => 25000], ['description' => 'Carburant', 'quantity' => 1, 'unit_amount' => 50000]]])
            ->assertCreated()->json('data');
        $this->assertSame([['label' => 'TVA', 'rate' => 18, 'base' => 'net']], array_map(fn ($t) => ['label' => $t['label'], 'rate' => (int) $t['rate'], 'base' => $t['base']], $r['taxes']));
        // brut 100 000, remise 10 000, net 90 000, TVA 16 200 → 106 200
        $this->assertEquals(100000, $r['total_gross']);
        $this->assertEquals(10000, $r['total_discount']);
        $this->assertEquals(16200, $r['total_taxes']);
        $this->assertEquals(106200, $r['total_requested']);
        $this->assertSame('very_urgent', $r['priority']);
        $this->flushHeaders();

        // Taxe supplémentaire calculée sur le brut
        $r = $api()->putJson('/api/fonds/requests/'.$r['id'], ['taxes' => [['label' => 'TVA', 'rate' => 18, 'base' => 'net'], ['label' => 'Retenue', 'rate' => 2, 'base' => 'gross']]])->assertOk()->json('data');
        $this->assertEquals(108200, $r['total_requested']);
        $this->flushHeaders();

        // Numérotation avec le préfixe du modèle
        $dg = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Directeur', 'email' => 'dg@nsi.ci', 'password' => 'secret123',
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Direction')->value('id')]);
        $sub = $api()->postJson('/api/fonds/requests/'.$r['id'].'/submit', ['approver_id' => $dg->id])->json('data');
        if (! isset($sub['number'])) {
            $api()->putJson('/api/fonds/requests/'.$r['id'], ['approver_id' => $dg->id]);
            $this->flushHeaders();
            $sub = $api()->postJson('/api/fonds/requests/'.$r['id'].'/submit')->assertOk()->json('data');
        }
        $this->assertStringStartsWith('NSI-AF-', $sub['number']);
        $this->flushHeaders();

        // Cadre de la comptabilité
        $acc = $api()->postJson('/api/fonds/requests/'.$r['id'].'/accounting', ['budget_available' => true, 'documents_compliant' => false, 'accounting_note' => 'Facture manquante'])->assertOk()->json('data');
        $this->assertTrue($acc['budget_available']);
        $this->assertFalse($acc['documents_compliant']);
        $this->flushHeaders();

        // PDF de la demande et aperçu du modèle
        $pdf = $api()->get('/api/fonds/requests/'.$r['id'].'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        if ($out = getenv('FONDS_PDF_OUT')) {
            file_put_contents($out, $pdf->getContent());
        }
        $this->flushHeaders();
        if ($out = getenv('FONDS_PDF_OUT')) {
            // aperçu avec un vrai logo, centré, couleurs par défaut
            $api()->post('/api/fonds/form/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', file_get_contents(public_path('brand/mark-512.png')))]);
            $this->flushHeaders();
            $api()->putJson('/api/fonds/form', ['logo_position' => 'center', 'color_primary' => '#0B4C8C', 'title' => 'FORMULAIRE DE DEMANDE D\'APPEL DE FONDS', 'sections' => ['accounting' => true]]);
            $this->flushHeaders();
            file_put_contents(str_replace('.pdf', '-preview.pdf', $out), $api()->get('/api/fonds/form/preview')->getContent());
            $this->flushHeaders();
        }
        $prev = $api()->get('/api/fonds/form/preview')->assertOk();
        $this->assertStringStartsWith('%PDF', $prev->getContent());
        $this->flushHeaders();

        $f = $api()->deleteJson('/api/fonds/form/logo')->assertOk()->json('data');
        $this->assertSame('company', $f['logo_mode']);
        $this->assertNull($f['custom_logo_url']);
    }
}
