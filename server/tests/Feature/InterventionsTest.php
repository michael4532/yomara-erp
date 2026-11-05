<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Interventions\Models\Contract;
use App\Modules\Interventions\Models\Intervention;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InterventionsTest extends TestCase
{
    public function test_field_service_contract_visits_technician_report_stock_invoice_and_portal_request(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Froid Services');
        $api = fn () => $this->api($token);
        $this->api($token)->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['it_interventions']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $api()->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Technicien')->value('id');
        $tech = User::create(['company_id' => $company->id, 'name' => 'Moussa Traoré', 'email' => 'moussa@froid.ci', 'password' => 'secret123', 'role_id' => $role]);
        $other = User::create(['company_id' => $company->id, 'name' => 'Autre Tech', 'email' => 'autre@froid.ci', 'password' => 'secret123', 'role_id' => $role]);
        $tapi = fn () => $this->api($tech->createToken('t')->plainTextToken);
        $this->assertContains('Moussa Traoré', collect($api()->getJson('/api/interventions/technicians')->json('technicians'))->pluck('name'));

        // Pièce en stock : 50 filtres
        $filter = $api()->postJson('/api/core/products', ['name' => 'Filtre climatiseur', 'sale_price' => 15000, 'purchase_price' => 9000, 'tax_rate' => 18])->json('data.id');
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Magasin', 'is_default' => true])->json('data.id');
        $api()->postJson('/api/stock/moves', ['product_id' => $filter, 'warehouse_id' => $wh, 'quantity' => 50, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();
        $stock = fn () => collect($api()->getJson('/api/stock/levels')->json('data'))->where('product_id', $filter)->sum('quantity');

        $client = $api()->postJson('/api/core/partners', ['name' => 'Banque Atlantique Plateau', 'type' => 'customer', 'phone' => '0707000001', 'address' => 'Av. Noguès', 'city' => 'Plateau'])->json('data.id');
        $contract = $api()->postJson('/api/interventions/contracts', ['partner_id' => $client, 'name' => 'Climatisation agence Plateau', 'start_date' => now()->toDateString(),
            'frequency' => 'quarterly', 'technician_id' => $tech->id, 'price' => 60000, 'billing' => 'quarterly', 'response_hours' => 4, 'labor_included' => true])->assertCreated()->json('data');
        $this->assertStringStartsWith('CTR-', $contract['number']);
        $equip = $api()->postJson('/api/interventions/equipments', ['partner_id' => $client, 'contract_id' => $contract['id'], 'name' => 'Split salle des coffres',
            'brand' => 'Daikin', 'model' => 'FTXM35', 'serial_number' => 'DK-778812', 'location' => 'RDC'])->assertCreated()->json('data');

        // Visites préventives générées depuis le contrat (une seule fois)
        $this->assertSame(1, $api()->postJson('/api/interventions/generate')->assertOk()->json('created'));
        $this->assertSame(0, $api()->postJson('/api/interventions/generate')->json('created'));
        $visit = Intervention::withoutGlobalScopes()->where('contract_id', $contract['id'])->first();
        $this->assertSame(['preventive', 'planned', $tech->id, false, $equip['id']], [$visit->type, $visit->status, $visit->technician_id, $visit->billable, $visit->equipment_id]);
        $this->assertNotEmpty($visit->checklist);
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), Contract::withoutGlobalScopes()->find($contract['id'])->next_visit->toDateString());

        // Dépannage planifié par le responsable
        $i = $api()->postJson('/api/interventions/interventions', ['partner_id' => $client, 'title' => 'Climatiseur hall en panne', 'type' => 'corrective', 'priority' => 3,
            'technician_id' => $tech->id, 'scheduled_at' => now()->addHour()->toDateTimeString(), 'labor_rate' => 10000, 'travel_fee' => 3000,
            'contact_phone' => '0707000001', 'checklist' => [['text' => 'Contrôler la pression', 'done' => false]]])->assertCreated()->json('data');
        $this->assertStringStartsWith('INT-', $i['number']);
        $this->assertSame('planned', $i['status']);
        $this->assertTrue(AppNotification::where('user_id', $tech->id)->where('module', 'interventions')->where('ref', $i['id'])->exists());

        // Droits du technicien
        $tapi()->postJson('/api/interventions/interventions', ['title' => 'X'])->assertStatus(422);
        $this->api($other->createToken('o')->plainTextToken)->putJson("/api/interventions/interventions/{$i['id']}", ['status' => 'en_route'])->assertStatus(422);
        $tapi()->putJson("/api/interventions/interventions/{$i['id']}", ['status' => 'en_route', 'labor_rate' => 1])->assertOk()->assertJsonPath('data.labor_rate', 10000);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'en route'));
        $tapi()->putJson("/api/interventions/interventions/{$i['id']}", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $tapi()->putJson("/api/interventions/interventions/{$i['id']}", ['status' => 'done'])->assertStatus(422); // rapport obligatoire
        $sig = 'data:image/png;base64,'.base64_encode('png');
        $done = $tapi()->putJson("/api/interventions/interventions/{$i['id']}", [
            'status' => 'done', 'report' => 'Remplacement du filtre et recharge en gaz R32.', 'recommendations' => 'Nettoyer les filtres tous les mois.',
            'parts' => [['product_id' => $filter, 'description' => 'Filtre climatiseur', 'quantity' => 2, 'unit_price' => 1]],
            'checklist' => [['text' => 'Contrôler la pression', 'done' => true]], 'signature' => $sig, 'signed_by' => 'M. Kouamé', 'worked_minutes' => 95, 'satisfaction' => 5,
        ])->assertOk()->json('data');
        $this->assertSame(15000.0, (float) $done['parts'][0]['unit_price']); // prix du catalogue, pas celui du technicien
        $this->assertNotNull($done['signed_at']);
        $this->assertSame(48.0, (float) $stock());
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_SLASHES), '/r/'));
        $tapi()->putJson("/api/interventions/interventions/{$i['id']}", ['report' => 'modifié'])->assertStatus(422); // rapport signé verrouillé

        // Rapport public
        $this->flushHeaders();
        $token40 = Intervention::withoutGlobalScopes()->find($i['id'])->report_token;
        $this->get('/r/'.$token40)->assertOk()->assertSee('Remplacement du filtre')->assertSee('M. Kouamé')->assertSee('Filtre climatiseur')->assertSee('1 h 35');
        $this->get('/r/'.str_repeat('a', 40))->assertNotFound();

        // Facturation : main-d'œuvre au quart d'heure, déplacement, pièces ; pas de double sortie de stock
        $inv = $api()->postJson("/api/interventions/interventions/{$i['id']}/invoice")->assertOk()->json('invoice');
        $lines = collect($inv['lines']);
        $this->assertEqualsWithDelta(1.75, $lines->firstWhere('unit', 'h')['quantity'], 0.001);
        $this->assertSame(1.75 * 10000 + 3000 + 2 * 15000, round($lines->sum(fn ($l) => $l['quantity'] * $l['unit_price']), 2));
        $api()->postJson("/api/interventions/interventions/{$i['id']}/invoice")->assertStatus(422);
        $api()->postJson("/api/ventes/documents/{$inv['id']}/validate")->assertOk();
        $this->assertSame(48.0, (float) $stock());
        $api()->postJson("/api/ventes/documents/{$inv['id']}/cancel")->assertOk();
        $this->assertNull(Intervention::withoutGlobalScopes()->find($i['id'])->invoice_id);
        $this->assertSame(48.0, (float) $stock());
        // Réouverture par le responsable : les pièces reviennent en stock
        $api()->putJson("/api/interventions/interventions/{$i['id']}", ['status' => 'in_progress'])->assertOk();
        $this->assertSame(50.0, (float) $stock());

        // Visite du contrat : main-d'œuvre couverte, rien à facturer ; facture du contrat par période
        $tapi()->putJson("/api/interventions/interventions/{$visit->id}", ['status' => 'done', 'report' => 'Visite trimestrielle RAS.'])->assertOk();
        $api()->postJson("/api/interventions/interventions/{$visit->id}/invoice")->assertStatus(422)->assertJsonFragment(['message' => 'Rien à facturer : l\'intervention est couverte par le contrat '.$contract['number'].'.']);
        $cinv = $api()->postJson("/api/interventions/contracts/{$contract['id']}/invoice")->assertOk()->json('invoice');
        $this->assertSame(60000.0, (float) $cinv['lines'][0]['unit_price']);
        $this->assertSame(now()->addMonthsNoOverflow(3)->subDay()->toDateString(), Contract::withoutGlobalScopes()->find($contract['id'])->invoiced_until->toDateString());

        // Un responsable SAV sans droit de vente prépare quand même la facture brouillon
        $sav = User::create(['company_id' => $company->id, 'name' => 'Resp SAV', 'email' => 'sav@froid.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Responsable SAV')->value('id')]);
        $sapi = fn () => $this->api($sav->createToken('s')->plainTextToken);
        $sapi()->putJson("/api/interventions/interventions/{$i['id']}", ['status' => 'done'])->assertOk();
        $sapi()->postJson("/api/interventions/interventions/{$i['id']}/invoice")->assertOk();
        $sapi()->postJson('/api/ventes/documents', ['type' => 'invoice', 'date' => now()->toDateString()])->assertForbidden();

        // Statistiques
        $stats = $api()->getJson('/api/interventions/stats')->assertOk()->json();
        $this->assertSame(2, $stats['done']);
        $this->assertSame(5.0, (float) $stats['satisfaction']);

        // Demande depuis le portail client : rattachée au contrat de l'équipement
        $url = $api()->postJson("/api/portail/partners/$client/invite", ['channel' => 'none'])->json('url');
        $path = parse_url($url, PHP_URL_PATH);
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get($path.'/interventions')->assertOk()->assertSee('Split salle des coffres')->assertSee($contract['number']);
        $this->post($path.'/interventions', ['title' => 'Fuite d\'eau sur le split', 'equipment_id' => $equip['id'], 'priority' => 3])->assertRedirect();
        $req = Intervention::withoutGlobalScopes()->where('source', 'portal')->first();
        $this->assertSame(['requested', $contract['id'], 3], [$req->status, $req->contract_id, $req->priority]);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', '%URGENTE%')->exists());
        $this->get($path.'/interventions')->assertOk()->assertSee($req->number);
    }
}
