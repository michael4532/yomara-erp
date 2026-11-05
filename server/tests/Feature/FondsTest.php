<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

class FondsTest extends TestCase
{
    protected ArrayTransport $mails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = new ArrayTransport;
        Smtp::$transportFactory = fn () => $this->mails;
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fund_request_full_cycle_with_question_partial_approval_provision_and_dashboard(): void
    {
        [$company, , $token] = $this->makeCompany('Groupe Kouassi BTP');
        $admin = fn () => $this->api($token);
        $admin()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.k.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'k@k.ci', 'password' => 'x', 'from_email' => 'erp@k.ci', 'from_name' => 'Groupe Kouassi',
        ]])->assertOk();
        $role = fn ($n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $user = function (string $name, string $r) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@kouassi.ci', 'password' => 'secret123', 'role_id' => $role($r)]);
            $t = $u->createToken('t')->plainTextToken;

            return [$u, fn () => $this->api($t)];
        };
        [$chef, $chefApi] = $user('Awa Chantier', 'Commercial');
        [$dg, $dgApi] = $user('Koffi Directeur', 'Direction');
        [$daf, $dafApi] = $user('Marie Finances', 'Comptable');
        [, $otherApi] = $user('Yao Curieux', 'Commercial');

        // Comptes de trésorerie : banque du siège et caisse du chantier
        $bank = $dafApi()->postJson('/api/tresorerie/accounts', ['name' => 'SGCI Siège', 'kind' => 'bank'])->assertCreated()->json('data.id');
        $cash = $dafApi()->postJson('/api/tresorerie/accounts', ['name' => 'Caisse chantier Bingerville', 'kind' => 'cash'])->assertCreated()->json('data.id');

        // Le chef de chantier prépare sa demande
        $r = $chefApi()->postJson('/api/fonds/requests', ['title' => 'Démarrage chantier Bingerville', 'department' => 'Chantier Bingerville', 'to_department' => 'Direction générale',
            'priority' => 'urgent', 'needed_by' => now()->addDays(5)->toDateString(), 'purpose' => 'Avance pour les premiers jours de travaux.', 'lines' => [
                ['type' => 'funds', 'description' => 'Avance sur salaires journaliers', 'quantity' => 10, 'unit_amount' => 15000],
                ['type' => 'funds', 'description' => 'Carburant groupe électrogène', 'quantity' => 200, 'unit_amount' => 775],
                ['type' => 'equipment', 'description' => 'Bétonnière 350 L', 'quantity' => 1, 'unit_amount' => 450000],
            ]])->assertCreated()->json('data');
        $this->assertSame('draft', $r['status']);
        $this->assertEquals(755000, $r['total_requested']);
        $chefApi()->postJson("/api/fonds/requests/{$r['id']}/submit")->assertStatus(422)->assertJsonPath('message', 'Choisissez le responsable qui doit décider.');
        $chefApi()->putJson("/api/fonds/requests/{$r['id']}", ['approver_id' => $dg->id])->assertOk();
        $r = $chefApi()->postJson("/api/fonds/requests/{$r['id']}/submit")->assertOk()->json('data');
        $this->assertSame('submitted', $r['status']);
        $this->assertStringStartsWith('AF-'.now()->year, $r['number']);
        $this->assertTrue(AppNotification::where('user_id', $dg->id)->where('title', 'Appel de fonds à examiner')->exists());
        $mail = collect($this->mails->messages())->first(fn ($m) => str_contains($m->getOriginalMessage()->getSubject(), $r['number']));
        $this->assertNotNull($mail);
        $this->assertSame('koffi.directeur@kouassi.ci', $mail->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertCount(1, $mail->getOriginalMessage()->getAttachments());
        $this->assertStringStartsWith('%PDF', $chefApi()->get("/api/fonds/requests/{$r['id']}/pdf")->assertOk()->getContent());

        // Visibilité : un autre salarié ne voit pas la demande ; seul le responsable décide
        $otherApi()->getJson("/api/fonds/requests/{$r['id']}")->assertNotFound();
        $this->assertCount(0, $otherApi()->getJson('/api/fonds/requests')->json('data'));
        $chefApi()->postJson("/api/fonds/requests/{$r['id']}/approve")->assertStatus(422);
        $this->assertSame(1, $dgApi()->getJson('/api/fonds/dashboard')->json('data.mine.decide'));

        // Demande d'explication, réponse, retour en décision
        $dgApi()->postJson("/api/fonds/requests/{$r['id']}/question", ['body' => 'Pourquoi une bétonnière neuve ? Celle du dépôt est disponible.'])->assertOk()->assertJsonPath('data.status', 'question');
        $this->assertTrue(AppNotification::where('user_id', $chef->id)->where('title', 'Explication demandée sur votre appel de fonds')->exists());
        $chefApi()->putJson("/api/fonds/requests/{$r['id']}", ['lines' => [
            ['id' => $r['lines'][0]['id'] ?? null, 'type' => 'funds', 'description' => 'Avance sur salaires journaliers', 'quantity' => 10, 'unit_amount' => 15000],
        ]])->assertOk();
        $full = $chefApi()->getJson("/api/fonds/requests/{$r['id']}")->json('data');
        $this->assertCount(1, $full['lines']);
        $chefApi()->putJson("/api/fonds/requests/{$r['id']}", ['lines' => [
            ['id' => $full['lines'][0]['id'], 'type' => 'funds', 'description' => 'Avance sur salaires journaliers', 'quantity' => 10, 'unit_amount' => 15000],
            ['type' => 'funds', 'description' => 'Carburant groupe électrogène', 'quantity' => 200, 'unit_amount' => 775],
            ['type' => 'equipment', 'description' => 'Bétonnière du dépôt (transport)', 'quantity' => 1, 'unit_amount' => 0, 'note' => 'Mise à disposition depuis le dépôt de Yopougon'],
        ]])->assertOk();
        $r = $chefApi()->postJson("/api/fonds/requests/{$r['id']}/answer", ['body' => 'D\'accord, la bétonnière du dépôt suffit.'])->assertOk()->json('data');
        $this->assertSame('submitted', $r['status']);
        $this->assertEquals(305000, $r['total_requested']);

        // Approbation partielle : carburant ramené à 150 L ; la DAF fournit
        $lines = $r['lines'];
        $dgApi()->postJson("/api/fonds/requests/{$r['id']}/reject")->assertStatus(422);
        $r = $dgApi()->postJson("/api/fonds/requests/{$r['id']}/approve", ['provider_id' => $daf->id, 'provider_department' => 'Direction financière', 'note' => 'Carburant limité à 150 L.',
            'lines' => [['id' => $lines[1]['id'], 'approved_quantity' => 150]]])->assertOk()->json('data');
        $this->assertSame('approved', $r['status']);
        $this->assertEquals(266250, $r['total_approved']);
        $this->assertSame('Marie Finances', $r['provider']);
        $this->assertTrue(AppNotification::where('user_id', $chef->id)->where('title', 'Appel de fonds approuvé en partie')->exists());
        $this->assertTrue(AppNotification::where('user_id', $daf->id)->where('title', 'Moyens à mettre à disposition')->exists());

        // Mise à disposition : d'abord les salaires par virement banque → caisse, puis le reste
        $chefApi()->postJson("/api/fonds/requests/{$r['id']}/provide", ['items' => [['id' => $lines[0]['id']]]])->assertStatus(422);
        $r = $dafApi()->postJson("/api/fonds/requests/{$r['id']}/provide", ['items' => [['id' => $lines[0]['id']]], 'method' => 'bank_transfer', 'reference' => 'VIR-8841',
            'source_account_id' => $bank, 'dest_account_id' => $cash])->assertOk()->json('data');
        $this->assertSame('partial', $r['status']);
        $this->assertEquals(150000, $r['total_provided']);
        $ops = \App\Modules\Tresorerie\Models\Operation::where('category', 'transfer')->get();
        $this->assertEqualsCanonicalizing([-150000.0, 150000.0], $ops->pluck('amount')->all());
        $this->assertSame(1, $ops->pluck('transfer_id')->unique()->count());
        $r = $dafApi()->postJson("/api/fonds/requests/{$r['id']}/provide", ['items' => [['id' => $lines[1]['id']], ['id' => $lines[2]['id']]], 'method' => 'cash'])->assertOk()->json('data');
        $this->assertSame('provided', $r['status']);
        $this->assertEquals(266250, $r['total_provided']);
        $dafApi()->postJson("/api/fonds/requests/{$r['id']}/provide", ['items' => [['id' => $lines[0]['id']]]])->assertStatus(422);

        // Réception confirmée ; historique complet
        $r = $chefApi()->postJson("/api/fonds/requests/{$r['id']}/confirm", ['note' => 'Bien reçu sur la caisse du chantier.'])->assertOk()->json('data');
        $this->assertSame('closed', $r['status']);
        $this->assertSame(['created', 'submitted', 'question', 'updated', 'updated', 'answer', 'approved', 'provided', 'provided', 'closed'], array_column($r['events'], 'type'));
        $this->assertSame('Virement bancaire', $r['events'][7]['data']['method_label']);

        // Refus d'une seconde demande, annulation d'une troisième
        $r2 = $chefApi()->postJson('/api/fonds/requests', ['title' => 'Tablettes chantier', 'department' => 'Chantier Bingerville', 'approver_id' => $dg->id,
            'lines' => [['type' => 'equipment', 'description' => 'Tablette Android', 'quantity' => 3, 'unit_amount' => 120000]]])->json('data');
        $chefApi()->postJson("/api/fonds/requests/{$r2['id']}/submit")->assertOk();
        $dgApi()->postJson("/api/fonds/requests/{$r2['id']}/reject", ['note' => 'Pas prévu au budget du chantier.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $r3 = $chefApi()->postJson('/api/fonds/requests', ['title' => 'Casques', 'department' => 'Chantier Bingerville', 'lines' => [['type' => 'equipment', 'description' => 'Casques', 'quantity' => 10, 'unit_amount' => 5000]]])->json('data');
        $chefApi()->postJson("/api/fonds/requests/{$r3['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Relance automatique d'une demande en attente de décision
        $r4 = $chefApi()->postJson('/api/fonds/requests', ['title' => 'Gilets', 'department' => 'Chantier Bingerville', 'approver_id' => $dg->id, 'lines' => [['type' => 'equipment', 'description' => 'Gilets', 'quantity' => 10, 'unit_amount' => 3000]]])->json('data');
        $chefApi()->postJson("/api/fonds/requests/{$r4['id']}/submit")->assertOk();
        Carbon::setTestNow(now()->addDays(4));
        $this->artisan('erp:fonds-remind')->assertExitCode(0);
        $this->assertTrue(AppNotification::where('user_id', $dg->id)->where('title', 'Appel de fonds en attente de votre décision')->exists());
        Carbon::setTestNow();

        // Tableau de bord
        $d = $dgApi()->getJson('/api/fonds/dashboard')->assertOk()->json('data');
        $this->assertSame(4, $d['kpis']['count']); // brouillons exclus, demandes annulées comprises
        $this->assertEquals(266250, $d['kpis']['provided']);
        $this->assertEquals(50, $d['kpis']['approval_rate']);
        $this->assertSame('Chantier Bingerville', $d['by_department'][0]['department']);
        $this->assertCount(12, $d['months']);
        $this->assertContains('Chantier Bingerville', $chefApi()->getJson('/api/fonds/meta')->json('data.settings.departments'));
    }
}
