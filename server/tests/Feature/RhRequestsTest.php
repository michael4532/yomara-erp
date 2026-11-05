<?php

namespace Tests\Feature;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Messaging\SendResult;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RhRequestsTest extends TestCase
{
    public function test_employee_requests_as_tickets_with_manager_in_copy(): void
    {
        // Faux canal e-mail : on garde les messages envoyés
        $sent = new \ArrayObject;
        $this->app->instance(MessagingService::class, new class($sent) extends MessagingService
        {
            public function __construct(public \ArrayObject $sent) {}

            public function usable(Company $company, string $channel): ?string
            {
                return 'smtp';
            }

            public function send(Company $company, string $channel, Message $m, ?string $context = null, ?int $userId = null): SendResult
            {
                $this->sent->append($m);

                return SendResult::ok('x');
            }
        });

        [$company, , $token] = $this->makeCompany('Pharmacie Akwaba');
        $admin = fn () => $this->api($token);
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mkUser = fn (string $name, string $roleName) => User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@akwaba.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);
        $kofi = $mkUser('Kofi', 'Salarié');
        $awa = $mkUser('Awa', 'Salarié');
        $nadia = $mkUser('Nadia', 'RH / Paie');
        $client = fn (User $u) => fn () => $this->api($u->fresh()->createToken('t')->plainTextToken);
        $push = fn ($c, array $m) => $c()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert'] + $m]])->assertOk()->json('results.0');
        $company->settings = array_merge($company->settings ?? [], ['rh_requests' => ['email' => 'rh@akwaba.ci']]);
        $company->save();

        // Organigramme : le poste de Kofi est rattaché à celui d'Awa (responsable trouvé par la fiche de poste)
        $chef = $this->uuid();
        $vendeur = $this->uuid();
        $eAwa = $this->uuid();
        $eKofi = $this->uuid();
        foreach ([
            ['entity' => 'rh_positions', 'record_id' => $chef, 'data' => ['title' => 'Pharmacien titulaire']],
            ['entity' => 'rh_positions', 'record_id' => $vendeur, 'data' => ['title' => 'Vendeur en pharmacie', 'parent_id' => $chef]],
            ['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'position_id' => $chef, 'email' => 'awa.kone@akwaba.ci', 'user_id' => $awa->id]],
            ['entity' => 'rh_employees', 'record_id' => $eKofi, 'data' => ['first_name' => 'Kofi', 'last_name' => 'Yao', 'position_id' => $vendeur, 'user_id' => $kofi->id, 'base_salary' => 150000]],
        ] as $m) {
            $this->assertSame('applied', $push($admin, $m)['status']);
        }

        $raise = ['entity' => 'rh_requests', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'type' => 'raise', 'amount' => 25000, 'body' => 'Après deux ans au comptoir.']];
        // Sans le droit accordé par l'administrateur : refusé
        $this->assertSame('rejected', $push($client($kofi), $raise)['status']);

        $kofi->forceFill(['permission_grants' => ['rh.request']])->save();
        $r = $push($client($kofi), $raise);
        $this->assertSame(['applied', 'submitted', $eAwa], [$r['status'], $r['record']['status'], $r['record']['manager_id']]);
        $this->assertStringStartsWith('DEM', $r['record']['number']);
        $this->assertSame('Augmentation de salaire', $r['record']['subject']);
        $reqId = $r['record']['id'];

        // E-mail au service RH, responsable et salarié en copie ; notifications RH et responsable
        $m = $sent[0];
        $this->assertSame('rh@akwaba.ci', $m->to);
        $this->assertEqualsCanonicalizing(['awa.kone@akwaba.ci', 'kofi@akwaba.ci'], $m->cc);
        $this->assertStringContainsString($r['record']['number'], $m->subject);
        $this->assertTrue(AppNotification::where('user_id', $nadia->id)->where('ref', $reqId)->exists());
        $this->assertTrue(AppNotification::where('user_id', $awa->id)->where('ref', $reqId)->exists());

        // Le salarié ne décide pas lui-même ; le responsable voit la demande de son équipe et peut écrire
        $this->assertSame('rejected', $push($client($kofi), ['entity' => 'rh_requests', 'record_id' => $reqId, 'data' => ['status' => 'approved']])['status']);
        $teamPull = $client($awa)()->getJson('/api/sync/pull?cursor=0')->json('changes.rh_requests.upserts');
        $this->assertSame([$reqId], array_column($teamPull, 'id'));
        $client($awa)()->postJson("/api/rh/requests/$reqId/comment", ['text' => 'Je confirme, très bon élément.'])->assertOk();
        $last = $sent[count($sent) - 1];
        $this->assertSame('rh@akwaba.ci', $last->to);
        $this->assertContains('awa.kone@akwaba.ci', $last->cc);

        // Un collègue sans lien ne peut pas écrire dans la demande
        $other = $mkUser('Ama', 'Salarié');
        $client($other)()->postJson("/api/rh/requests/$reqId/comment", ['text' => 'x'])->assertForbidden();

        // Décision de la RH : e-mail au salarié, responsable et RH en copie
        $client($nadia)()->postJson("/api/rh/requests/$reqId/decide", ['status' => 'approved', 'note' => 'Effective au 1er janvier.'])->assertOk();
        $d = $sent[count($sent) - 1];
        $this->assertSame('kofi@akwaba.ci', $d->to);
        $this->assertEqualsCanonicalizing(['awa.kone@akwaba.ci', 'rh@akwaba.ci'], $d->cc);
        $thread = json_decode(DB::table('rh_requests')->where('id', $reqId)->value('messages'), true);
        $this->assertSame([['Awa', 'manager']], array_map(fn ($x) => [$x['name'], $x['role']], $thread));
        $this->assertSame('approved', DB::table('rh_requests')->where('id', $reqId)->value('status'));

        // Demande de congé : le congé est créé, la décision sur la demande s'applique au congé
        $leaveReq = $push($client($kofi), ['entity' => 'rh_requests', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'type' => 'leave', 'leave_type' => 'annual',
            'start_date' => now()->addDays(20)->toDateString(), 'end_date' => now()->addDays(24)->toDateString()]]);
        $this->assertSame('applied', $leaveReq['status']);
        $leaveId = $leaveReq['record']['leave_id'];
        $this->assertSame('requested', DB::table('rh_leaves')->where('id', $leaveId)->value('status'));
        $client($nadia)()->postJson('/api/rh/requests/'.$leaveReq['record']['id'].'/decide', ['status' => 'rejected', 'note' => 'Inventaire cette semaine-là.'])->assertOk();
        $this->assertSame('rejected', DB::table('rh_leaves')->where('id', $leaveId)->value('status'));

        // Démission sans date, type désactivé par la société : refusés ; annulation par le salarié : acceptée
        $this->assertSame('rejected', $push($client($kofi), ['entity' => 'rh_requests', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'type' => 'resignation']])['status']);
        $client($nadia)()->putJson('/api/rh/requests/settings', ['enabled' => ['leave', 'training'], 'email' => 'rh@akwaba.ci'])->assertOk();
        $this->assertSame('rejected', $push($client($kofi), ['entity' => 'rh_requests', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'type' => 'advance', 'amount' => 50000]])['status']);
        $t = $push($client($kofi), ['entity' => 'rh_requests', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'type' => 'training', 'subject' => 'Formation gestion de stock']]);
        $this->assertSame('applied', $t['status']);
        $this->assertSame('cancelled', $push($client($kofi), ['entity' => 'rh_requests', 'record_id' => $t['record']['id'], 'data' => ['status' => 'cancelled']])['record']['status']);
    }
}
