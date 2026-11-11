<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Documents\Models\DocFile;
use App\Modules\Documents\Models\Folder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentsTest extends TestCase
{
    protected function pdf(string $text): string
    {
        $p = new \TCPDF;
        $p->setPrintHeader(false);
        $p->AddPage();
        $p->SetFont('dejavusans', '', 12);
        $p->Write(0, $text);

        return $p->Output('', 'S');
    }

    protected function docx(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dx');
        $z = new \ZipArchive;
        $z->open($path, \ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $z->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.htmlspecialchars($text).'</w:t></w:r></w:p></w:body></w:document>');
        $z->close();
        $b = file_get_contents($path);
        unlink($path);

        return $b;
    }

    public function test_folders_access_upload_search_versions_lock_trash_shares_quota_expiry_and_signed_documents(): void
    {
        Storage::fake('local');
        [$company, $admin, $token] = $this->makeCompany('Groupe Akwaba');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@akwaba.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$paul, $compta] = $mk('Paul', 'Comptable');
        [, $commercial] = $mk('Jean', 'Commercial');
        [, $magasin] = $mk('Ousmane', 'Magasinier');
        [, $caisse] = $mk('Awa', 'Caissier');
        $push = fn ($client, array $mut) => $client()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => 'upsert'], $mut)])->assertOk()->json('results');
        $pull = fn ($client, string $e) => $client()->getJson('/api/sync/pull?cursor=0')->assertOk()->json("changes.$e.upserts") ?? [];
        $upload = fn ($client, string $name, string $bytes, array $o = []) => $client()->post('/api/documents/files', $o + ['file' => UploadedFile::fake()->createWithContent($name, $bytes)]);

        // Dossiers : ouverts à tous par défaut ; seuls les administrateurs fixent des droits (sauf dossier privé)
        $cpt = $this->uuid();
        $push($compta, [['entity' => 'dc_folders', 'record_id' => $cpt, 'data' => ['name' => 'Comptabilité']]]);
        $this->assertSame('rejected', $push($compta, [['entity' => 'dc_folders', 'record_id' => $this->uuid(), 'data' => ['name' => 'Comptabilité']]])[0]['status']);
        $fourn = $this->uuid();
        $push($compta, [['entity' => 'dc_folders', 'record_id' => $fourn, 'data' => ['name' => 'Factures fournisseurs', 'parent_id' => $cpt]]]);
        $this->assertSame('rejected', $push($compta, [['entity' => 'dc_folders', 'record_id' => $this->uuid(), 'data' => ['name' => 'Paie', 'access' => 'roles', 'role_ids' => [$role('Comptable')]]]])[0]['status']);
        $rh = $this->uuid();
        $push($api, [['entity' => 'dc_folders', 'record_id' => $rh, 'data' => ['name' => 'RH confidentiel', 'access' => 'roles', 'role_ids' => [$role('RH / Paie')]]]]);
        $priv = $this->uuid();
        $this->assertSame('applied', $push($compta, [['entity' => 'dc_folders', 'record_id' => $priv, 'data' => ['name' => 'Mes brouillons', 'access' => 'private']]])[0]['status']);
        $this->assertSame('rejected', $push($compta, [['entity' => 'dc_folders', 'record_id' => $cpt, 'data' => ['parent_id' => $fourn]]])[0]['status']); // pas de boucle

        // Dépôt : PDF et Word indexés, types dangereux refusés, droits
        $inv = $upload($compta, 'Facture Solibra 2026-118.pdf', $this->pdf('Facture fournisseur SOLIBRA — livraison de boissons, montant 1 250 000 F CFA'),
            ['folder_id' => $fourn, 'tags' => json_encode(['facture', 'boissons']), 'description' => 'Livraison d\'octobre'])->assertCreated()->json('data');
        $this->assertSame(['pdf', 1, $paul->id, ['facture', 'boissons']], [$inv['extension'], $inv['current_version'], $inv['owner_id'], $inv['tags']]);
        $this->assertArrayNotHasKey('content_text', $inv);
        $doc = $upload($compta, 'Procédure de clôture.docx', $this->docx('Procédure de clôture mensuelle : rapprochement bancaire et inventaire'), ['folder_id' => $cpt])->assertCreated()->json('data');
        $upload($compta, 'script.php', '<?php echo 1;', ['folder_id' => $cpt])->assertStatus(422)->assertJsonFragment(['message' => 'Ce type de fichier n\'est pas autorisé.']);
        $upload($compta, 'paie.pdf', $this->pdf('Livre de paie'), ['folder_id' => $rh])->assertStatus(422);
        $upload($magasin, 'x.pdf', $this->pdf('x'), ['folder_id' => $cpt])->assertForbidden();
        $caisse()->getJson('/api/documents/usage')->assertForbidden();
        $paie = $upload($api, 'Livre de paie octobre.pdf', $this->pdf('Livre de paie — salaires nets SOLIBRA'), ['folder_id' => $rh])->assertCreated()->json('data');
        $mine = $upload($compta, 'Notes.txt', 'Brouillon de budget SOLIBRA', ['folder_id' => $priv])->assertCreated()->json('data');

        // Visibilité : le magasinier ne voit ni le dossier RH ni le dossier privé du comptable ; l'administrateur voit tout
        $this->assertEqualsCanonicalizing([$cpt, $fourn], array_column($pull($magasin, 'dc_folders'), 'id'));
        $this->assertEqualsCanonicalizing([$inv['id'], $doc['id']], array_column($pull($magasin, 'dc_files'), 'id'));
        $this->assertCount(4, $pull($api, 'dc_files'));
        $magasin()->get("/api/documents/files/{$paie['id']}/download")->assertNotFound();
        $magasin()->get("/api/documents/files/{$inv['id']}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Recherche dans le contenu (PDF et Word), limitée aux dossiers visibles
        $hits = $compta()->getJson('/api/documents/search?q=solibra')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$inv['id'], $mine['id']], array_column($hits, 'id'));
        $this->assertSame('Comptabilité / Factures fournisseurs', collect($hits)->firstWhere('id', $inv['id'])['path']);
        $this->assertStringContainsStringIgnoringCase('SOLIBRA', collect($hits)->firstWhere('id', $inv['id'])['snippet']);
        $this->assertSame([$doc['id']], array_column($magasin()->getJson('/api/documents/search?q=rapprochement bancaire')->json('data'), 'id'));
        $this->assertSame([$inv['id']], array_column($magasin()->getJson('/api/documents/search?q=solibra')->json('data'), 'id'));
        $this->assertCount(3, $api()->getJson('/api/documents/search?q=solibra')->json('data'));
        $this->assertSame([$inv['id']], array_column($compta()->getJson('/api/documents/search?q=boissons&folder_id='.$cpt)->json('data'), 'id'));

        // Métadonnées hors ligne : renommer (extension conservée), déplacer, mots-clés, échéance ; création réservée au dépôt
        $r = $push($compta, [['entity' => 'dc_files', 'record_id' => $inv['id'], 'data' => ['name' => 'Facture Solibra octobre', 'tags' => ['facture', ' payée ', 'facture'], 'expires_at' => now()->addDays(5)->toDateString()]]])[0];
        $this->assertSame(['Facture Solibra octobre.pdf', ['facture', 'payée']], [$r['record']['name'], $r['record']['tags']]);
        $this->assertSame('rejected', $push($compta, [['entity' => 'dc_files', 'record_id' => $this->uuid(), 'data' => ['name' => 'fantôme.pdf']]])[0]['status']);
        $this->assertSame('rejected', $push($compta, [['entity' => 'dc_files', 'record_id' => $inv['id'], 'data' => ['folder_id' => $rh]]])[0]['status']);
        $this->assertSame([$inv['id']], array_column($compta()->getJson('/api/documents/search?q=payee')->json('data'), 'id'));

        // Versions : nouvelle version, ancienne téléchargeable, retour arrière
        $v2 = $compta()->post("/api/documents/files/{$inv['id']}/version", ['file' => UploadedFile::fake()->createWithContent('facture-corrigee.pdf', $this->pdf('Facture SOLIBRA corrigée : 1 180 000 F CFA')), 'comment' => 'Avoir déduit'])->assertOk()->json('data');
        $this->assertSame([2, 'Facture Solibra octobre.pdf'], [$v2['current_version'], $v2['name']]);
        $vers = $compta()->getJson("/api/documents/files/{$inv['id']}/versions")->assertOk()->json('data');
        $this->assertSame([[2, 'Avoir déduit', true, 'Paul'], [1, null, false, 'Paul']], array_map(fn ($v) => [$v['number'], $v['comment'], $v['current'], $v['user_name']], $vers));
        $this->assertSame($vers[1]['sha256'], hash('sha256', $compta()->get("/api/documents/files/{$inv['id']}/download?v=1")->streamedContent()));
        $this->assertNotSame($vers[0]['sha256'], $vers[1]['sha256']);
        $this->assertSame(3, $compta()->postJson("/api/documents/files/{$inv['id']}/versions/1/restore")->assertOk()->json('data.current_version'));
        $this->assertSame($vers[1]['sha256'], DocFile::withoutGlobalScopes()->find($inv['id'])->sha256);

        // Verrouillage par le propriétaire
        $push($compta, [['entity' => 'dc_files', 'record_id' => $inv['id'], 'data' => ['locked' => true]]]);
        $this->assertSame('rejected', $push($commercial, [['entity' => 'dc_files', 'record_id' => $inv['id'], 'data' => ['name' => 'piraté']]])[0]['status']);
        $this->assertSame('rejected', $commercial()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'dc_files', 'op' => 'delete', 'record_id' => $inv['id']]]])->json('results.0.status'));
        $commercial()->post("/api/documents/files/{$inv['id']}/version", ['file' => UploadedFile::fake()->createWithContent('x.pdf', $this->pdf('x'))])->assertStatus(422);
        $push($compta, [['entity' => 'dc_files', 'record_id' => $inv['id'], 'data' => ['locked' => false]]]);

        // Corbeille : suppression, restauration, suppression définitive réservée à l'administrateur
        $del = fn ($client, string $entity, string $id) => $client()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'delete', 'record_id' => $id]]])->json('results.0');
        $this->assertSame('rejected', $del($compta, 'dc_folders', $cpt)['status']); // dossier non vide
        $this->assertSame('applied', $del($compta, 'dc_files', $doc['id'])['status']);
        $this->assertSame([$doc['id']], array_column($compta()->getJson('/api/documents/trash')->json('data'), 'id'));
        $this->assertSame([], $commercial()->getJson('/api/documents/trash')->json('data'));
        $compta()->postJson("/api/documents/files/{$doc['id']}/restore")->assertOk();
        $this->assertContains($doc['id'], array_column($pull($magasin, 'dc_files'), 'id'));
        $del($compta, 'dc_files', $doc['id']);
        $compta()->deleteJson("/api/documents/files/{$doc['id']}/purge")->assertForbidden();
        $api()->deleteJson("/api/documents/files/{$doc['id']}/purge")->assertOk();
        $this->assertNull(DocFile::withoutGlobalScopes()->withTrashed()->find($doc['id']));
        Storage::disk('local')->assertMissing('documents/'.$company->id.'/'.$doc['id']);

        // Lien de partage : mot de passe, nombre de téléchargements, révocation ; partage de dossier
        $sh = $compta()->postJson('/api/documents/shares', ['file_id' => $inv['id'], 'password' => 'akwaba26', 'max_downloads' => 1, 'expires_days' => 7])->assertCreated()->json('data');
        $path = parse_url($sh['url'], PHP_URL_PATH);
        $this->flushHeaders();
        $this->get($path)->assertOk()->assertSee('Document protégé')->assertDontSee('Facture Solibra');
        $this->get($path.'/telecharger/'.$inv['id'])->assertForbidden();
        $this->post($path, ['password' => 'faux'])->assertSessionHas('error');
        $this->post($path, ['password' => 'akwaba26'])->assertRedirect($path);
        $this->get($path)->assertSee('Facture Solibra octobre.pdf');
        $this->get($path.'/telecharger/'.$inv['id'])->assertOk();
        $this->get($path.'/telecharger/'.$inv['id'])->assertForbidden();
        $this->get($path)->assertSee('Lien expiré');
        $fs = $compta()->postJson('/api/documents/shares', ['folder_id' => $fourn])->assertCreated()->json('data');
        $this->flushHeaders();
        $this->get(parse_url($fs['url'], PHP_URL_PATH))->assertSee('Facture Solibra octobre.pdf');
        $this->assertCount(1, $compta()->getJson('/api/documents/shares?folder_id='.$fourn)->json('data'));
        $compta()->deleteJson("/api/documents/shares/{$fs['id']}")->assertOk();
        $this->flushHeaders();
        $this->get(parse_url($fs['url'], PHP_URL_PATH))->assertSee('Lien expiré');
        $magasin()->postJson('/api/documents/shares', ['file_id' => $paie['id']])->assertForbidden();

        // Quota de stockage
        config(['erp.documents_quota_mb' => 1]);
        $upload($compta, 'gros.bin', str_repeat('x', 1200 * 1024), ['folder_id' => $cpt])->assertStatus(422)->assertJsonFragment(['message' => 'Espace de stockage de la société plein (1 Mo). Videz la corbeille, supprimez d\'anciennes versions ou ajoutez de l\'espace (Documents › Espace de stockage).']);
        config(['erp.documents_quota_mb' => null]);

        // En ligne (SaaS) : 2 Go partagés par toute la société, packs d'espace en plus
        config(['erp.mode' => 'saas']);
        $st = $api()->getJson('/api/documents/storage')->assertOk()->json('data');
        $this->assertSame(2 * 1073741824, $st['usage']['quota']);
        $this->assertSame([10, 20, 50, 100], array_map(fn ($p) => $p['size'] / 1073741824, $st['packs']));
        $this->assertGreaterThan(0, collect($st['people'])->firstWhere('id', $paul->id)['used']);
        $compta()->getJson('/api/documents/storage')->assertForbidden();
        \App\Models\CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'stockage_10'], ['enabled' => true]);
        $this->assertSame(12 * 1073741824, $api()->getJson('/api/documents/storage')->json('data.usage.quota'));
        config(['erp.mode' => 'single']);
        $this->assertGreaterThan(0, $compta()->getJson('/api/documents/usage')->json('data.used'));

        // Échéances et corbeille : tâche quotidienne
        $old = $upload($compta, 'Ancien.txt', 'vieux', ['folder_id' => $cpt])->json('data.id');
        $del($compta, 'dc_files', $old);
        DocFile::withoutGlobalScopes()->withTrashed()->where('id', $old)->update(['deleted_at' => now()->subDays(31)]);
        $this->artisan('erp:documents-maintenance')->assertSuccessful();
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $paul->id)->where('title', 'Échéance dans 5 jours')->exists());
        $this->assertNull(DocFile::withoutGlobalScopes()->withTrashed()->find($old));
        $this->artisan('erp:documents-maintenance')->assertSuccessful();
        $this->assertSame(1, AppNotification::withoutGlobalScopes()->where('user_id', $paul->id)->where('title', 'Échéance dans 5 jours')->count());

        // Document signé classé automatiquement dans « Documents signés »
        $client = $api()->postJson('/api/core/partners', ['name' => 'Hôtel Ivoire', 'type' => 'customer'])->json('data.id');
        $quote = $api()->postJson('/api/ventes/documents', ['type' => 'quote', 'partner_id' => $client, 'date' => now()->toDateString(),
            'lines' => [['description' => 'Prestation', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 18]]])->json('data');
        $api()->postJson("/api/ventes/documents/{$quote['id']}/validate")->assertOk();
        $req = $api()->post('/api/signature/requests', ['title' => 'Devis Hôtel Ivoire', 'send' => 1, 'source_type' => 'sales_document', 'source_id' => $quote['id'],
            'signers' => json_encode([['name' => 'M. Koné']]), 'file' => UploadedFile::fake()->createWithContent('devis.pdf', $this->pdf('Devis'))])->assertCreated()->json('data');
        $this->flushHeaders();
        $im = imagecreatetruecolor(200, 80);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imageline($im, 5, 60, 190, 20, 0);
        ob_start();
        imagepng($im);
        $this->post(parse_url($req['signers'][0]['link'], PHP_URL_PATH).'/signer', ['signature' => 'data:image/png;base64,'.base64_encode(ob_get_clean()), 'agree' => 1])->assertSessionHas('ok');
        $folder = Folder::withoutGlobalScopes()->where('company_id', $company->id)->where('system_key', 'signatures')->first();
        $this->assertSame('Documents signés', $folder->name);
        $signed = DocFile::withoutGlobalScopes()->where('folder_id', $folder->id)->first();
        $this->assertSame(['devis - signé.pdf', 'signature', $req['id'], $client], [$signed->name, $signed->source_type, $signed->source_id, $signed->partner_id]);
        $this->assertNotContains($signed->id, array_column($pull($magasin, 'dc_files'), 'id'));
        $this->assertContains($signed->id, array_column($pull($commercial, 'dc_files'), 'id'));
        $this->assertSame('rejected', $del($api, 'dc_folders', $folder->id)['status']);
    }
}
