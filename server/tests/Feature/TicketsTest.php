<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TicketsTest extends TestCase
{
    protected string $admin;

    protected array $t = [];   // jetons par prénom

    protected array $uid = []; // id par prénom

    protected array $dep = []; // id par code

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->admin] = $this->makeCompany('Groupe Ivoire');
        $setup = $this->api($this->admin)->getJson('/api/tickets/setup')->assertOk()->json();
        $this->dep = collect($setup['departments'])->pluck('id', 'code')->all();
        $this->assertEqualsCanonicalizing(['DG', 'IT', 'RH', 'FIN', 'MKT', 'MG'], array_keys($this->dep));

        $role = collect($this->api($this->admin)->getJson('/api/roles')->json('data'))->firstWhere('name', 'Lecture seule')['id'];
        foreach (['Koffi', 'Awa', 'Yao', 'Mariam', 'Jean', 'Fatou', 'Paul'] as $name) {
            $email = strtolower($name).'@groupe.ci';
            $this->api($this->admin)->postJson('/api/users', ['name' => $name, 'email' => $email, 'password' => 'secret123', 'role_id' => $role])->assertCreated();
            $u = User::where('email', $email)->first();
            $this->uid[$name] = $u->id;
            $this->t[$name] = $u->createToken('t')->plainTextToken;
        }

        // DGA (Fatou) au-dessus de l'informatique et du marketing
        $dga = $this->api($this->admin)->postJson('/api/tickets/departments', ['name' => 'Direction générale adjointe', 'code' => 'DGA', 'parent_id' => $this->dep['DG'], 'head_user_id' => $this->uid['Fatou'], 'accepts_tickets' => false])->assertCreated()->json('id');
        $this->dep['DGA'] = $dga;
        $this->api($this->admin)->putJson('/api/tickets/departments/'.$this->dep['IT'], ['parent_id' => $dga])->assertOk();
        $this->api($this->admin)->putJson('/api/tickets/departments/'.$this->dep['MKT'], ['parent_id' => $dga])->assertOk();
        $this->api($this->admin)->putJson('/api/tickets/departments/'.$this->dep['IT'].'/members', ['members' => [
            ['user_id' => $this->uid['Koffi'], 'role' => 'head'], ['user_id' => $this->uid['Awa'], 'role' => 'member'], ['user_id' => $this->uid['Yao'], 'role' => 'intern'],
        ]])->assertOk();
        $this->api($this->admin)->putJson('/api/tickets/departments/'.$this->dep['MKT'].'/members', ['members' => [
            ['user_id' => $this->uid['Mariam'], 'role' => 'head'], ['user_id' => $this->uid['Jean'], 'role' => 'member'],
        ]])->assertOk();
        $this->api($this->admin)->putJson('/api/tickets/departments/'.$this->dep['FIN'].'/members', ['members' => [['user_id' => $this->uid['Paul'], 'role' => 'member']]])->assertOk();
        // Un service ne peut pas dépendre de son propre sous-service
        $this->api($this->admin)->putJson('/api/tickets/departments/'.$dga, ['parent_id' => $this->dep['IT']])->assertStatus(422);
        \App\Models\AppNotification::where('type', 'welcome')->update(['read_at' => now()]); // bienvenue des comptes créés : déjà lue
    }

    protected function as(string $name)
    {
        return $this->api($this->t[$name]);
    }

    protected function act(string $who, int $id, string $action, array $data = [], int $code = 200)
    {
        return $this->as($who)->postJson("/api/tickets/tickets/$id/$action", $data)->assertStatus($code);
    }

    public function test_printer_ticket_full_lifecycle_with_timings_and_visibility(): void
    {
        $t0 = Carbon::parse('2026-10-19 09:00:00'); // lundi
        Carbon::setTestNow($t0);
        $ticket = $this->as('Jean')->postJson('/api/tickets/tickets', [
            'department_id' => $this->dep['IT'], 'category' => 'Imprimante', 'urgency' => 'high',
            'subject' => 'Mon imprimante ne démarre plus',
            'description' => 'Bonjour, mon imprimante ne démarre plus, j\'ai besoin que vous passiez la récupérer ou regarder la panne.',
        ])->assertCreated()->json('ticket');
        $id = $ticket['id'];
        $this->assertSame('TCK-2026-00001', $ticket['number']);
        $this->assertSame('submitted', $ticket['status']);
        $this->assertSame($this->dep['MKT'], $ticket['requester_department_id']);
        $this->assertSame('2026-10-20 09:00:00', Carbon::parse($ticket['due_at'])->format('Y-m-d H:i:s')); // urgence haute : 24 h
        $this->assertSame(1, $this->as('Koffi')->getJson('/api/notifications')->json('unread'));

        // Qui voit le ticket
        foreach (['Jean' => 200, 'Mariam' => 200, 'Koffi' => 200, 'Awa' => 200, 'Yao' => 200, 'Fatou' => 200, 'Paul' => 403] as $who => $code) {
            $this->as($who)->getJson("/api/tickets/tickets/$id")->assertStatus($code);
        }
        $this->assertCount(1, $this->as('Mariam')->getJson('/api/tickets/tickets?box=department')->json('data')); // demande envoyée par son équipe
        $this->assertCount(0, $this->as('Paul')->getJson('/api/tickets/tickets')->json('data'));

        // Le stagiaire ne peut pas attribuer à quelqu'un d'autre ; le chef attribue à Awa
        $this->act('Yao', $id, 'assign', ['assignee_id' => $this->uid['Awa']], 422);
        Carbon::setTestNow($t0->copy()->addMinutes(10));
        $this->act('Koffi', $id, 'assign', ['assignee_id' => $this->uid['Awa']])->assertJsonPath('ticket.status', 'open');
        $this->assertSame('Ticket attribué : TCK-2026-00001 (Haute)', $this->as('Awa')->getJson('/api/notifications')->json('data.0.title'));
        $this->act('Koffi', $id, 'assign', ['assignee_id' => $this->uid['Paul']], 422); // pas du service

        Carbon::setTestNow($t0->copy()->addMinutes(20));
        $this->act('Awa', $id, 'accept')->assertJsonPath('ticket.status', 'accepted');
        $this->act('Jean', $id, 'done', [], 422); // le demandeur ne clôt pas lui-même le traitement
        $this->act('Awa', $id, 'reject', ['reason' => 'x'], 422); // refus réservé au chef

        Carbon::setTestNow($t0->copy()->addMinutes(30));
        $this->act('Awa', $id, 'start')->assertJsonPath('ticket.status', 'in_progress');

        Carbon::setTestNow($t0->copy()->addHour());
        $this->act('Awa', $id, 'hold', [], 422); // raison obligatoire
        $this->act('Awa', $id, 'hold', ['reason' => 'Soumission d\'achat d\'une cartouche et d\'un câble : réglé dès réception.', 'purchase' => true])
            ->assertJsonPath('ticket.status', 'on_hold')->assertJsonPath('ticket.purchase_requested', true);
        $this->assertStringContainsString('cartouche', $this->as('Jean')->getJson('/api/notifications')->json('data.0.body'));

        // Note interne invisible pour le demandeur, message visible
        $this->as('Awa')->postJson("/api/tickets/tickets/$id/comments", ['body' => 'Fournisseur relancé', 'internal' => true])->assertOk();
        $this->as('Jean')->postJson("/api/tickets/tickets/$id/comments", ['body' => 'Merci, j\'utilise celle du voisin en attendant.'])->assertOk();
        $jeanView = $this->as('Jean')->getJson("/api/tickets/tickets/$id")->json('events');
        $this->assertNotContains('Fournisseur relancé', array_column($jeanView, 'body'));
        $this->assertContains('Fournisseur relancé', array_column($this->as('Koffi')->getJson("/api/tickets/tickets/$id")->json('events'), 'body'));

        Carbon::setTestNow($t0->copy()->addDay()->addHour());
        $this->act('Awa', $id, 'resume')->assertJsonPath('ticket.status', 'in_progress');
        Carbon::setTestNow($t0->copy()->addDay()->addHours(2));
        $done = $this->act('Awa', $id, 'done', ['resolution' => 'Cartouche et câble remplacés, impression testée.'])->json('ticket');
        $this->assertSame('done', $done['status']);
        $d = $done['durations'];
        $this->assertSame(20 * 60, $d['response']);             // prise en charge
        $this->assertSame(26 * 3600, $d['resolution']);         // délai total
        $this->assertSame(24 * 3600, $d['hold']);               // attente matériel
        $this->assertSame(2 * 3600, $d['work']);                // traitement effectif
        $this->assertTrue($d['late']);                          // 26 h > 24 h

        $this->act('Jean', $id, 'close', ['rating' => 5])->assertJsonPath('ticket.status', 'closed')->assertJsonPath('ticket.rating', 5);

        // Statistiques du chef informatique et classement
        $s = $this->as('Koffi')->getJson('/api/tickets/stats?from=2026-10-19&to=2026-10-25')->assertOk()->json();
        $this->assertSame(1, $s['kpi']['received']);
        $this->assertSame(1, $s['kpi']['done']);
        $this->assertEquals(0, $s['kpi']['sla']);
        $this->assertSame('Awa', $s['ranking'][0]['name']);
        $this->assertSame('Imprimante', $s['by_category'][0]['label']);
        // La DGA voit les services placés sous elle ; un collaborateur ne voit que ses propres chiffres
        $this->assertCount(3, $this->as('Fatou')->getJson('/api/tickets/stats')->json('departments')); // DGA + informatique + marketing
        $this->as('Fatou')->getJson('/api/tickets/stats?department_id='.$this->dep['FIN'])->assertStatus(403);

        // Rapport automatique de la semaine (le lundi suivant)
        Carbon::setTestNow(Carbon::parse('2026-10-26 06:00:00'));
        Artisan::call('erp:tickets-report', ['period' => 'week']);
        $reports = $this->as('Koffi')->getJson('/api/tickets/reports')->assertOk()->json('data');
        $this->assertSame('Informatique', $reports[0]['department']);
        $this->assertStringContainsString('Collaborateur le plus performant : Awa', $reports[0]['summary']);
        $this->assertStringContainsString('1 demande(s) reçue(s)', $this->api($this->admin)->getJson('/api/tickets/reports')->json('data.0.summary'));
        $this->as('Paul')->getJson('/api/tickets/reports')->assertOk()->assertJsonCount(0, 'data');
        Carbon::setTestNow();
    }

    public function test_cancel_reopen_reject_and_self_assignment(): void
    {
        $a = $this->as('Paul')->postJson('/api/tickets/tickets', ['department_id' => $this->dep['MKT'], 'subject' => 'Affiche pour le séminaire'])->json('ticket.id');
        $this->act('Mariam', $a, 'reject', ['reason' => 'Passez par le brief créatif'])->assertJsonPath('ticket.status', 'rejected');
        $this->act('Paul', $a, 'cancel', [], 422); // déjà refusé

        $b = $this->as('Paul')->postJson('/api/tickets/tickets', ['department_id' => $this->dep['IT'], 'subject' => 'Accès Wi-Fi', 'urgency' => 'critical'])->json('ticket.id');
        // Un collaborateur peut se l'attribuer lui-même
        $this->act('Yao', $b, 'assign', ['assignee_id' => $this->uid['Yao']])->assertJsonPath('ticket.assignee_id', $this->uid['Yao']);
        $this->act('Yao', $b, 'done', ['resolution' => 'Mot de passe communiqué'])->assertJsonPath('ticket.status', 'done');
        $this->act('Paul', $b, 'reopen', ['comment' => 'Ça ne marche toujours pas'])->assertJsonPath('ticket.status', 'in_progress');
        $this->act('Paul', $b, 'cancel')->assertJsonPath('ticket.status', 'cancelled');

        // File « à traiter » de l'informatique : tickets non attribués du service
        $c = $this->as('Paul')->postJson('/api/tickets/tickets', ['department_id' => $this->dep['IT'], 'subject' => 'Écran qui scintille'])->json('ticket.id');
        $this->assertContains($c, array_column($this->as('Awa')->getJson('/api/tickets/tickets?box=todo')->json('data'), 'id'));
        // Pièce jointe
        $file = \Illuminate\Http\UploadedFile::fake()->create('photo.jpg', 120, 'image/jpeg');
        $this->as('Paul')->post("/api/tickets/tickets/$c/files", ['file' => $file])->assertCreated();
        $f = $this->as('Awa')->getJson("/api/tickets/tickets/$c")->json('files.0');
        $this->assertSame('photo.jpg', $f['name']);
        $this->as('Awa')->get("/api/tickets/tickets/$c/files/{$f['id']}")->assertOk();
        $this->as('Mariam')->get("/api/tickets/tickets/$c/files/{$f['id']}")->assertStatus(403);

        // Organisation réservée aux gestionnaires
        $this->as('Koffi')->postJson('/api/tickets/departments', ['name' => 'Audit'])->assertStatus(403);
        $this->api($this->admin)->deleteJson('/api/tickets/departments/'.$this->dep['IT'])->assertOk()->assertJsonPath('archived', true);
    }
}
