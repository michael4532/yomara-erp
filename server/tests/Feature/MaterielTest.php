<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaterielTest extends TestCase
{
    public function test_checkout_return_pdf_and_signature(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Studio Akwaba');
        $api = fn () => $this->api($token);
        $push = fn ($c, array $m) => $c()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => $m['op'] ?? 'upsert'] + $m]])->assertOk()->json('results.0');

        $cam = $this->uuid();
        $tripod = $this->uuid();
        $mic = $this->uuid();
        $chef = $this->uuid();
        $tech = $this->uuid();
        $techUser = User::create(['company_id' => $company->id, 'name' => 'Koffi', 'email' => 'koffi@akwaba.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Salarié')->value('id')]);
        foreach ([
            ['entity' => 'mat_items', 'record_id' => $cam, 'data' => ['name' => 'Caméra Sony FX3', 'code' => 'CAM-01', 'serial' => 'SN123', 'category' => 'Vidéo', 'value' => 2500000]],
            ['entity' => 'mat_items', 'record_id' => $tripod, 'data' => ['name' => 'Trépied Manfrotto', 'code' => 'TRP-01']],
            ['entity' => 'mat_items', 'record_id' => $mic, 'data' => ['name' => 'Micro Rode', 'code' => 'MIC-01']],
            ['entity' => 'rh_employees', 'record_id' => $chef, 'data' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'department' => 'Production', 'email' => 'awa@akwaba.ci']],
            ['entity' => 'rh_employees', 'record_id' => $tech, 'data' => ['first_name' => 'Koffi', 'last_name' => 'Yao', 'department' => 'Production', 'manager_id' => $chef, 'user_id' => $techUser->id]],
        ] as $m) {
            $this->assertSame('applied', $push($api, $m)['status']);
        }

        // Sortie : articles indisponibles ensuite, porteur et responsable du département signataires
        $out = $this->uuid();
        $r = $push($api, ['entity' => 'mat_checkouts', 'record_id' => $out, 'data' => ['employee_id' => $tech, 'purpose' => 'Tournage clip — Plateau', 'destination' => 'Plateau',
            'expected_back_at' => now()->addDay()->toDateTimeString(), 'lines' => [['item_id' => $cam], ['item_id' => $tripod]]]]);
        $this->assertSame(['applied', 'out', 'Production'], [$r['status'], $r['record']['status'], $r['record']['department']]);
        $this->assertStringStartsWith('BS', $r['record']['number']);
        $this->assertSame(['Porteur du matériel', 'Responsable du département'], array_column($r['record']['signers'], 'label'));
        $this->assertSame('Koné Awa', $r['record']['signers'][1]['name']);
        $this->assertSame('out', DB::table('mat_items')->where('id', $cam)->value('status'));
        // Signature électronique demandée automatiquement (module Signature actif)
        $this->assertSame('sent', DB::table('mat_checkouts')->where('id', $out)->value('signature_status'));
        $sig = DB::table('sg_requests')->where('source_type', 'mat_checkout')->where('source_id', $out)->first();
        $this->assertNotNull($sig);
        $this->assertSame(2, DB::table('sg_signers')->where('request_id', $sig->id)->count());

        // Déjà sorti : refusé ; changer l'état « sorti » à la main : refusé
        $this->assertSame('rejected', $push($api, ['entity' => 'mat_checkouts', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $chef, 'purpose' => 'x', 'lines' => [['item_id' => $cam]]]])['status']);
        $this->assertSame('rejected', $push($api, ['entity' => 'mat_items', 'record_id' => $cam, 'data' => ['status' => 'available']])['status']);

        // Bon PDF
        $pdf = $api()->get("/api/materiel/checkouts/$out/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Le technicien voit sa sortie, pas celles des autres
        $techApi = fn () => $this->api($techUser->fresh()->createToken('t')->plainTextToken);
        $techUser->forceFill(['permission_grants' => ['materiel.use']])->save();
        $this->assertSame([$out], array_column($techApi()->getJson('/api/sync/pull?cursor=0')->json('changes.mat_checkouts.upserts'), 'id'));

        // Retour partiel : la caméra revient endommagée → en réparation ; le trépied reste dehors
        $r = $push($techApi, ['entity' => 'mat_checkouts', 'record_id' => $out, 'data' => ['lines' => [['item_id' => $cam, 'returned' => true, 'condition_in' => 'damaged', 'note' => 'Écran rayé']]]]);
        $this->assertSame(['applied', 'partial'], [$r['status'], $r['record']['status']]);
        $this->assertSame('repair', DB::table('mat_items')->where('id', $cam)->value('status'));
        $this->assertSame('out', DB::table('mat_items')->where('id', $tripod)->value('status'));
        // Un retour enregistré ne s'annule pas, l'annulation n'est plus possible
        $r = $push($techApi, ['entity' => 'mat_checkouts', 'record_id' => $out, 'data' => ['lines' => [['item_id' => $cam, 'returned' => false], ['item_id' => $tripod, 'returned' => true]]]]);
        $this->assertSame('returned', $r['record']['status']);
        $this->assertTrue(collect($r['record']['lines'])->every(fn ($l) => $l['returned']));
        $this->assertSame(['repair', 'available'], [DB::table('mat_items')->where('id', $cam)->value('status'), DB::table('mat_items')->where('id', $tripod)->value('status')]);
        $this->assertSame('rejected', $push($api, ['entity' => 'mat_checkouts', 'record_id' => $out, 'data' => ['status' => 'cancelled']])['status']);

        // Sortie pour soi par le technicien (droit materiel.use), puis annulée : le micro redevient disponible
        $own = $push($techApi, ['entity' => 'mat_checkouts', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $chef, 'purpose' => 'Interview', 'lines' => [['item_id' => $mic]]]]);
        $this->assertSame($tech, $own['record']['employee_id']);
        $this->assertSame('cancelled', $push($techApi, ['entity' => 'mat_checkouts', 'record_id' => $own['record']['id'], 'data' => ['status' => 'cancelled']])['record']['status']);
        $this->assertSame('available', DB::table('mat_items')->where('id', $mic)->value('status'));

        // Signature terminée : le bon passe à « signé »
        \App\Modules\Signature\Models\SignRequest::withoutGlobalScopes()->find($sig->id)->forceFill(['status' => 'completed'])->save();
        $this->assertSame('signed', DB::table('mat_checkouts')->where('id', $out)->value('signature_status'));
    }
}
