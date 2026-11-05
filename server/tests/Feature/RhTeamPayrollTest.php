<?php

namespace Tests\Feature;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Messaging\SendResult;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RhTeamPayrollTest extends TestCase
{
    public function test_departments_team_rights_contracts_and_quick_payroll(): void
    {
        [$company, , $token] = $this->makeCompany('Agence Akwaba');
        $api = fn () => $this->api($token);
        $push = fn ($c, array $m) => $c()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert'] + $m]])->assertOk()->json('results.0');
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $chefUser = User::create(['company_id' => $company->id, 'name' => 'M. Tanon', 'email' => 'tanon@akwaba.ci', 'password' => 'secret123', 'role_id' => $role('Salarié')]);
        $chefApi = fn () => $this->api($chefUser->fresh()->createToken('t')->plainTextToken);

        $mkt = $this->uuid();
        $dev = $this->uuid();
        $chef = $this->uuid();
        $com = $this->uuid();
        $cm = $this->uuid();
        $stag = $this->uuid();
        $other = $this->uuid();
        foreach ([
            ['entity' => 'rh_employees', 'record_id' => $chef, 'data' => ['first_name' => 'Michael', 'last_name' => 'Tanon', 'user_id' => $chefUser->id, 'base_salary' => 900000]],
            ['entity' => 'rh_departments', 'record_id' => $mkt, 'data' => ['name' => 'Marketing', 'head_ids' => [$chef], 'visibility' => ['presence' => true, 'requests' => true, 'contract' => true]]],
            ['entity' => 'rh_departments', 'record_id' => $dev, 'data' => ['name' => 'Développement']],
            ['entity' => 'rh_employees', 'record_id' => $com, 'data' => ['first_name' => 'Aya', 'last_name' => 'Koffi', 'job_title' => 'Chargée de communication', 'department_id' => $mkt, 'base_salary' => 300000, 'phone' => '0707']],
            ['entity' => 'rh_employees', 'record_id' => $cm, 'data' => ['first_name' => 'Yves', 'last_name' => 'Gnagne', 'job_title' => 'Community manager', 'department_id' => $mkt, 'base_salary' => 250000]],
            ['entity' => 'rh_employees', 'record_id' => $stag, 'data' => ['first_name' => 'Ines', 'last_name' => 'Bamba', 'job_title' => 'Stagiaire marketing', 'department_id' => $mkt, 'staff_type' => 'intern', 'base_salary' => 75000]],
            ['entity' => 'rh_employees', 'record_id' => $other, 'data' => ['first_name' => 'Paul', 'last_name' => 'Dev', 'department_id' => $dev, 'base_salary' => 500000]],
        ] as $m) {
            $this->assertSame('applied', $push($api, $m)['status'], json_encode($m));
        }
        // Le nom du service suit le département ; le stagiaire est non déclaré par défaut
        $this->assertSame('Marketing', DB::table('rh_employees')->where('id', $com)->value('department'));
        $this->assertSame([0, 0], array_values(array_map('intval', (array) DB::table('rh_employees')->where('id', $stag)->first(['cnps_declared', 'cmu_enrolled']))));

        // Mon équipe : collaborateurs et stagiaire du marketing, contrat visible, salaire et coordonnées non
        $team = $chefApi()->getJson('/api/rh/team')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['Koffi Aya', 'Gnagne Yves', 'Bamba Ines'], array_column($team['members'], 'name'));
        $this->assertSame(['total' => 3, 'employees' => 2, 'interns' => 1], array_intersect_key($team['summary'], array_flip(['total', 'employees', 'interns'])));
        $aya = collect($team['members'])->firstWhere('name', 'Koffi Aya');
        $this->assertArrayHasKey('contract_type', $aya);
        $this->assertArrayNotHasKey('base_salary', $aya);
        $this->assertArrayNotHasKey('phone', $aya);
        $chefApi()->getJson("/api/rh/team/$other")->assertForbidden();
        // La RH ouvre les salaires au chef du marketing
        $this->assertSame('applied', $push($api, ['entity' => 'rh_departments', 'record_id' => $mkt, 'data' => ['visibility' => ['presence' => true, 'requests' => true, 'contract' => true, 'salary' => true]]])['status']);
        $this->assertSame(300000.0, (float) $chefApi()->getJson("/api/rh/team/$com")->assertOk()->json('base_salary'));

        // Contrat enregistré par la RH : la fiche suit (type, embauche, salaire, heures) ; un CDD sans fin est refusé
        $this->assertSame('rejected', $push($api, ['entity' => 'rh_contracts', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $cm, 'type' => 'CDD', 'start_date' => '2026-01-01']])['status']);
        $c = $push($api, ['entity' => 'rh_contracts', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $cm, 'type' => 'CDD', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'trial_end_date' => '2026-01-31', 'salary' => 280000, 'weekly_hours' => 35]]);
        $this->assertSame('applied', $c['status']);
        $e = DB::table('rh_employees')->where('id', $cm)->first();
        $this->assertSame(['CDD', '2026-01-01', '2026-12-31', 280000.0, 35.0], [$e->contract_type, substr($e->hire_date, 0, 10), substr($e->end_date, 0, 10), (float) $e->base_salary, (float) $e->weekly_hours]);
        $this->assertEqualsWithDelta(280000 / (35 * 52 / 12), $api()->getJson("/api/rh/employees/$cm")->json('hourly_rate'), 0.01);
        // Nouveau contrat en vigueur : le précédent se termine
        $push($api, ['entity' => 'rh_contracts', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $cm, 'type' => 'CDI', 'start_date' => '2027-01-01', 'salary' => 320000]]);
        $this->assertSame(['ended', 'active'], DB::table('rh_contracts')->where('employee_id', $cm)->orderBy('start_date')->pluck('status')->all());

        // Paie en un clic : déclarés avec retenues, stagiaire payé au montant convenu, assurance retenue
        $this->assertSame('applied', $push($api, ['entity' => 'rh_employees', 'record_id' => $com, 'data' => ['has_insurance' => true, 'insurance_name' => 'Sunu', 'insurance_employee' => 5000, 'insurance_employer' => 10000]])['status']);
        $prev = $api()->postJson('/api/paie/quick/preview', ['period' => now()->format('Y-m')])->assertOk()->json();
        $slips = collect($prev['payslips'])->keyBy('name');
        $this->assertFalse($slips['Bamba Ines']['declared']);
        $this->assertSame(75000.0, (float) $slips['Bamba Ines']['net']);
        $this->assertSame(75000.0, (float) $slips['Bamba Ines']['cost']);
        $this->assertTrue($slips['Koffi Aya']['declared']);
        $this->assertLessThan(300000 - 5000, (float) $slips['Koffi Aya']['net']);
        $ayaSlip = DB::table('paie_payslips')->where('id', $slips['Koffi Aya']['id'])->value('result');
        $this->assertContains('assurance', array_column(json_decode($ayaSlip, true)['withholdings'], 'code'));
        $paid = $api()->postJson('/api/paie/quick/pay', ['period' => now()->format('Y-m'), 'method' => 'bank_transfer'])->assertOk()->json();
        $this->assertSame('paid', $paid['status'] ?? $paid['run']['status'] ?? null);
        $api()->postJson('/api/paie/quick/pay', ['period' => now()->format('Y-m'), 'method' => 'cash'])->assertStatus(422);
        // Déclaration CNPS : sans le stagiaire
        $this->assertSame(count($prev['payslips']) - 1, $api()->getJson('/api/paie/runs/'.$prev['run']['id'].'/declaration')->json('headcount'));
    }

    public function test_sick_leave_request_with_certificate_and_chosen_copies(): void
    {
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
        [$company, $admin, $token] = $this->makeCompany('Clinique Akwaba');
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $kofi = User::create(['company_id' => $company->id, 'name' => 'Kofi', 'email' => 'kofi@akwaba.ci', 'password' => 'secret123', 'role_id' => $role('Salarié')]);
        $kofi->forceFill(['permission_grants' => ['rh.request']])->save();
        $assist = User::create(['company_id' => $company->id, 'name' => 'Assistante RH', 'email' => 'assist@akwaba.ci', 'password' => 'secret123', 'role_id' => $role('RH / Paie')]);
        $kofiApi = fn () => $this->api($kofi->fresh()->createToken('t')->plainTextToken);
        $e = $this->uuid();
        $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'rh_employees', 'record_id' => $e, 'data' => ['first_name' => 'Kofi', 'last_name' => 'Yao', 'user_id' => $kofi->id]]]])->assertOk();

        // Copie au choix : l'équipe RH et la direction sont proposées
        $opts = collect($kofiApi()->getJson('/api/rh/requests/settings')->json('cc_options'));
        $this->assertTrue($opts->contains(fn ($o) => $o['user_id'] === $assist->id && $o['group'] === 'Service RH'));
        $this->assertTrue($opts->contains(fn ($o) => $o['user_id'] === $admin->id));

        $id = $this->uuid();
        $r = $kofiApi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'rh_requests', 'record_id' => $id, 'data' => ['employee_id' => $e, 'type' => 'sick',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(2)->toDateString(), 'body' => 'Paludisme', 'cc_user_ids' => [$admin->id]]]]])->json('results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame('sick', DB::table('rh_leaves')->where('id', $r['record']['leave_id'])->value('type'));
        $this->assertContains($admin->email, array_merge([$sent[0]->to], $sent[0]->cc));
        $this->assertContains('assist@akwaba.ci', array_merge([$sent[0]->to], $sent[0]->cc));

        // Certificat médical joint : stocké, envoyé en pièce jointe, téléchargeable par la RH
        $res = $kofiApi()->post("/api/rh/requests/$id/attachments", ['file' => UploadedFile::fake()->create('certificat.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame('certificat.pdf', $res['attachments'][0]['name']);
        $this->assertArrayNotHasKey('path', $res['attachments'][0]);
        $last = $sent[count($sent) - 1];
        $this->assertSame('certificat.pdf', $last->attachments[0]['name']);
        $this->assertContains($admin->email, array_merge([$last->to], $last->cc));
        $this->api($token)->get("/api/rh/requests/$id/attachments/".$res['attachments'][0]['id'])->assertOk();
    }
}
