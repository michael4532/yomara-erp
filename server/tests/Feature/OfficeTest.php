<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Documents\Models\DocFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfficeTest extends TestCase
{
    public function test_create_edit_save_and_lock_office_documents(): void
    {
        Storage::fake('local');
        [$company, , $token] = $this->makeCompany('Akwaba');
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Comptable')->value('id');
        $awa = User::create(['company_id' => $company->id, 'name' => 'Awa', 'email' => 'awa@akwaba.ci', 'password' => 'secret123', 'role_id' => $role]);
        $awaApi = fn () => $this->api($awa->createToken('t')->plainTextToken);

        // Nouveau classeur vierge, ouvert dans l'éditeur
        $d = $this->api($token)->postJson('/api/documents/office/new', ['kind' => 'xlsx', 'name' => 'Budget 2027'])->assertCreated()->json('data');
        $this->assertSame('sheet', $d['kind']);
        $this->assertSame('Budget 2027.xlsx', $d['file']['name']);
        $this->flushHeaders();
        $page = $this->get('/office/'.$d['token'])->assertOk();
        $page->assertSee('"mode":"edit"', false)->assertSee('Budget 2027.xlsx', false);
        $bytes = $this->get('/api/office/'.$d['token'].'/content')->assertOk()->getContent();
        $this->assertStringStartsWith('PK', $bytes);

        // Enregistrement : nouvelle version
        $this->post('/api/office/'.$d['token'].'/save', ['file' => UploadedFile::fake()->createWithContent('Budget 2027.xlsx', $bytes)])->assertOk()->assertJsonPath('data.version', 2);

        // Une deuxième personne l'ouvre pendant la modification : lecture seule, elle sait qui modifie
        $id = $d['file']['id'];
        $s2 = $awaApi()->postJson("/api/documents/files/$id/office")->assertOk()->json('data.token');
        $this->flushHeaders();
        $this->get('/office/'.$s2)->assertOk()->assertSee('"mode":"view"', false)->assertSee('"lockedBy":"Admin Akwaba"', false);
        $this->post('/api/office/'.$s2.'/save', ['file' => UploadedFile::fake()->createWithContent('x.xlsx', $bytes)])->assertStatus(409);
        $ping = $this->postJson('/api/office/'.$s2.'/ping', ['editing' => true])->assertOk()->json('data');
        $this->assertFalse($ping['lock']['mine']);
        $this->assertCount(1, $ping['people']); // la première personne n'a pas encore « pingé »

        // Document Word vierge ; session inconnue ou expirée
        $w = $this->api($token)->postJson('/api/documents/office/new', ['kind' => 'docx'])->assertCreated()->json('data');
        $this->assertSame('Nouveau document.docx', $w['file']['name']);
        $this->flushHeaders();
        $this->get('/office/'.str_repeat('a', 40))->assertStatus(410);
        $this->assertSame(3, DocFile::withoutGlobalScopes()->where('company_id', $company->id)->count() + 1);
    }
}
