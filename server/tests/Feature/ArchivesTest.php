<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchivesTest extends TestCase
{
    public function test_archivist_workflow(): void
    {
        Storage::fake('local');
        [$company, , $token] = $this->makeCompany('Akwaba');
        $api = fn () => $this->api($token);

        // Plan de classement créé à la première visite
        $dash = $api()->getJson('/api/archives/dashboard')->assertOk()->json('data');
        $this->assertSame(0, $dash['total']);
        $series = collect($api()->getJson('/api/archives/series')->assertOk()->json('data'));
        $factures = $series->firstWhere('code', '1.1');
        $this->assertSame(10, $factures['retention_years']);
        $this->assertContains('sale.invoice', $factures['sources']);

        // Facture validée : archivée automatiquement, scellée, conservée 10 ans
        $inv = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $inv,
            'data' => ['type' => 'invoice', 'date' => '2026-03-15', 'status' => 'validated', 'partner_name' => 'Client Archivé', 'lines' => [['id' => $this->uuid(), 'description' => 'Prestation', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 18]]]]]])
            ->assertJsonPath('results.0.status', 'applied');
        $rec = DB::table('ar_records')->where('source_id', $inv)->first();
        $this->assertNotNull($rec);
        $this->assertSame($factures['id'], (int) $rec->series_id);
        $this->assertSame('2036-03-15', $rec->retention_until);
        $this->assertSame(hash('sha256', $rec->content), $rec->sha256);
        $this->assertStringStartsWith('AR11-2026', $rec->cote);
        $this->assertTrue($api()->postJson("/api/archives/records/{$rec->id}/verify")->assertOk()->json('data.ok'));

        // Altération détectée
        DB::table('ar_records')->where('id', $rec->id)->update(['content' => str_replace('Prestation', 'Prestatiom', $rec->content)]);
        $this->assertFalse($api()->postJson("/api/archives/records/{$rec->id}/verify")->json('data.ok'));
        DB::table('ar_records')->where('id', $rec->id)->update(['content' => $rec->content]);

        // Versement de scans avec cote, emplacement, confidentialité
        $rh = $series->firstWhere('code', '2.1');
        $ids = $api()->post('/api/archives/records', ['files' => [UploadedFile::fake()->createWithContent('dossier-kone.pdf', '%PDF-1.4 dossier')], 'series_id' => $rh['id'],
            'doc_date' => '2010-01-10', 'location' => 'Salle B · Rayon 3 · Boîte 12', 'keywords' => 'Koné personnel', 'transfer_ref' => 'VER-2026-01'])->assertCreated()->json('data.ids');
        $scan = DB::table('ar_records')->where('id', $ids[0])->first();
        $this->assertSame('confidential', $scan->confidentiality);
        $this->assertSame('%PDF-1.4 dossier', $api()->get("/api/archives/records/{$scan->id}/download")->assertOk()->getContent());
        $this->assertCount(1, $api()->getJson('/api/archives/records?q=Rayon 3')->json('data'));

        // Un lecteur sans droit « confidentiel » ne voit pas le dossier du personnel
        $role = Role::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Lecteur archives', 'permissions' => ['archives.view']]);
        $reader = User::create(['company_id' => $company->id, 'name' => 'Lecteur', 'email' => 'lecteur@akwaba.ci', 'password' => 'secret123', 'role_id' => $role->id]);
        $rt = $reader->createToken('t')->plainTextToken;
        $this->api($rt)->getJson("/api/archives/records/{$scan->id}")->assertNotFound();
        $this->assertCount(1, $this->api($rt)->getJson('/api/archives/records')->json('data'));
        $this->api($rt)->postJson('/api/archives/series', ['code' => '9', 'name' => 'X', 'retention_years' => 1, 'final_action' => 'destroy', 'confidentiality' => 'public'])->assertForbidden();

        // Prêt physique : demandé, approuvé (sorti du rayon), rendu
        $loan = $this->api($rt)->postJson("/api/archives/records/{$rec->id}/loans", ['kind' => 'pret', 'purpose' => 'Contrôle fiscal'])->assertCreated()->json('data.id');
        $api()->postJson("/api/archives/loans/$loan", ['action' => 'approve'])->assertOk();
        $this->assertSame('on_loan', DB::table('ar_records')->where('id', $rec->id)->value('status'));
        $api()->postJson("/api/archives/loans/$loan", ['action' => 'return'])->assertOk();
        $this->assertSame('active', DB::table('ar_records')->where('id', $rec->id)->value('status'));

        // Un archiviste sans droit d'élimination ne lève pas un gel et n'avance pas un terme
        $arch = Role::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Archiviste', 'permissions' => ['archives.view', 'archives.manage', 'archives.confidential']]);
        $at = User::create(['company_id' => $company->id, 'name' => 'Archiviste', 'email' => 'arch@akwaba.ci', 'password' => 'secret123', 'role_id' => $arch->id])->createToken('t')->plainTextToken;
        $api()->putJson("/api/archives/records/{$rec->id}", ['legal_hold' => true])->assertOk();
        $this->api($at)->putJson("/api/archives/records/{$rec->id}", ['legal_hold' => false])->assertForbidden();
        $this->api($at)->putJson("/api/archives/records/{$rec->id}", ['retention_until' => '2020-01-01'])->assertForbidden();
        $this->api($at)->putJson("/api/archives/records/{$rec->id}", ['location' => 'Salle C'])->assertOk();
        $api()->putJson("/api/archives/records/{$rec->id}", ['legal_hold' => false])->assertOk();

        // Élimination : arrivée à terme, gel juridique respecté, visa puis exécution, notice conservée
        DB::table('ar_records')->where('id', $scan->id)->update(['final_action' => 'destroy', 'retention_until' => '2015-01-10']);
        $old = $api()->post('/api/archives/records', ['files' => [UploadedFile::fake()->createWithContent('vieux.pdf', 'ancien')], 'doc_date' => '2005-01-01', 'series_id' => $factures['id']])->json('data.ids.0');
        $api()->putJson("/api/archives/records/$old", ['legal_hold' => true])->assertOk();
        $eligible = collect($api()->getJson('/api/archives/disposals')->json('data.eligible'))->pluck('id');
        $this->assertTrue($eligible->contains($scan->id));
        $this->assertFalse($eligible->contains($old)); // gelée pour litige
        $d = $api()->postJson('/api/archives/disposals', ['ids' => [$scan->id, $old]])->assertCreated()->json('data.id');
        $api()->postJson("/api/archives/disposals/$d", ['action' => 'execute'])->assertStatus(422); // visa d'abord
        $api()->postJson("/api/archives/disposals/$d", ['action' => 'approve'])->assertOk();
        $api()->postJson("/api/archives/disposals/$d", ['action' => 'execute'])->assertOk();
        $gone = DB::table('ar_records')->where('id', $scan->id)->first();
        $this->assertSame('disposed', $gone->status);
        $this->assertNull($gone->file_path);
        $this->assertSame('active', DB::table('ar_records')->where('id', $old)->value('status'));
        $api()->get("/api/archives/records/{$scan->id}/download")->assertStatus(410);

        // Journal complet
        $actions = collect($api()->getJson('/api/archives/journal')->json('data'))->pluck('action');
        foreach (['archived', 'verified', 'downloaded', 'loan_approved', 'loan_returned', 'hold_on', 'disposed'] as $a) {
            $this->assertTrue($actions->contains($a), $a);
        }
        // Les archives comptent dans l'espace de stockage partagé
        $this->assertGreaterThan(0, $api()->getJson('/api/documents/usage')->json('data.archives'));
    }
}
