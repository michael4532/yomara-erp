<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\Role;
use App\Models\User;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogistiqueTest extends TestCase
{
    protected string $token;

    protected $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, , $this->token] = $this->makeCompany('Grossiste Adjamé');
        $this->api($this->token)->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['lg_deliveries']);
    }

    public function test_delivery_round_with_driver_cod_proof_tracking_and_notifications(): void
    {
        $api = fn () => $this->api($this->token);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $api()->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();

        $driver = User::create(['company_id' => $this->company->id, 'name' => 'Ibrahim Coulibaly', 'email' => 'ibrahim@adjame.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Livreur')->value('id')]);
        $dtoken = $driver->createToken('t')->plainTextToken;
        $other = User::create(['company_id' => $this->company->id, 'name' => 'Autre Livreur', 'email' => 'autre@adjame.ci', 'password' => 'secret123',
            'role_id' => $driver->role_id]);

        $client = $api()->postJson('/api/core/partners', ['name' => 'Supérette Le Bon Prix', 'type' => 'customer', 'phone' => '0707070707'])->json('data.id');
        $inv = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(),
            'lines' => [['description' => 'Huile 20 L', 'quantity' => 2, 'unit_price' => 25000, 'tax_rate' => 0]]])->json('data');
        $api()->postJson("/api/ventes/documents/{$inv['id']}/validate")->assertOk();

        $zone = $api()->postJson('/api/logistique/zones', ['name' => 'Abobo', 'fee' => 1500, 'delay_days' => 1])->assertCreated()->json('data');
        $veh = $api()->postJson('/api/logistique/vehicles', ['name' => 'Moto 1', 'plate' => 'AB-1234-CI', 'type' => 'moto'])->assertCreated()->json('data');
        $tour = $api()->postJson('/api/logistique/tours', ['date' => now()->toDateString(), 'driver_id' => $driver->id, 'vehicle_id' => $veh['id'], 'name' => 'Abobo matin'])->assertCreated()->json('data');
        $d = $api()->postJson('/api/logistique/deliveries', [
            'source_document_id' => $inv['id'], 'partner_id' => $client, 'recipient_name' => 'M. Koné', 'recipient_phone' => '0707070707', 'address' => 'Abobo Gare, derrière la pharmacie',
            'zone_id' => $zone['id'], 'fee' => 1500, 'scheduled_date' => now()->toDateString(), 'driver_id' => $driver->id, 'tour_id' => $tour['id'],
            'items' => [['description' => 'Huile 20 L', 'quantity' => 2, 'unit' => 'bidon']], 'cod_amount' => 50000,
        ])->assertCreated()->json('data');
        $this->assertSame('LIV-'.now()->year.'-00001', $d['number']);
        $this->assertSame('planned', $d['status']);
        $this->assertNotEmpty($d['tracking_token']);

        // Le livreur ne crée rien et ne touche pas aux livraisons des autres
        $dapi = fn () => $this->api($dtoken);
        $dapi()->postJson('/api/logistique/deliveries', ['recipient_name' => 'X'])->assertStatus(422);
        $this->api($other->createToken('o')->plainTextToken)->putJson("/api/logistique/deliveries/{$d['id']}", ['status' => 'in_transit'])->assertStatus(422);
        // Il ne peut pas modifier le montant à encaisser, seulement le statut et la preuve
        $r = $dapi()->putJson("/api/logistique/deliveries/{$d['id']}", ['status' => 'in_transit', 'cod_amount' => 1])->assertOk()->json('data');
        $this->assertSame('in_transit', $r['status']);
        $this->assertEquals(50000, $r['cod_amount']);
        $this->assertSame('in_progress', $api()->getJson("/api/logistique/tours/{$tour['id']}")->json('data.status'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com') && str_contains(json_encode($req->data()), 'est en route avec Ibrahim'));

        // Livrée : signature, encaissement → paiement inscrit sur la facture, tournée terminée
        $sig = 'data:image/png;base64,'.base64_encode('fake-png');
        $dapi()->putJson("/api/logistique/deliveries/{$d['id']}", ['status' => 'delivered', 'signature' => 'javascript:alert(1)'])->assertStatus(422);
        $r = $dapi()->putJson("/api/logistique/deliveries/{$d['id']}", ['status' => 'delivered', 'signature' => $sig, 'signed_by' => 'Awa (caissière)', 'cod_collected' => 50000, 'cod_method' => 'cash'])->assertOk()->json('data');
        $this->assertNotNull($r['delivered_at']);
        $this->assertNotNull($r['payment_id']);
        $doc = $api()->getJson("/api/ventes/documents/{$inv['id']}")->json('data');
        $this->assertSame('paid', $doc['payment_status']);
        $this->assertSame('done', $api()->getJson("/api/logistique/tours/{$tour['id']}")->json('data.status'));

        // Suivi public
        $this->flushHeaders();
        $this->get('/t/'.$d['tracking_token'])->assertOk()->assertSee('Livraison '.$d['number'])->assertSee('Livrée')->assertSee('Awa (caissière)')->assertSee('Huile 20 L');
        $this->get('/t/inconnu')->assertNotFound();
        $events = $api()->getJson("/api/logistique/deliveries/{$d['id']}/events")->json('data');
        $this->assertSame(['planned', 'in_transit', 'delivered'], array_column($events, 'status'));
        $this->assertSame('Ibrahim Coulibaly', $events[1]['user']);

        // Échec sur une 2e livraison, puis remise des encaissements
        $d2 = $api()->postJson('/api/logistique/deliveries', ['recipient_name' => 'Boutique 2', 'scheduled_date' => now()->toDateString(), 'driver_id' => $driver->id, 'zone_id' => $zone['id']])->json('data');
        $f = $dapi()->putJson("/api/logistique/deliveries/{$d2['id']}", ['status' => 'failed', 'failed_reason' => 'Client absent'])->assertOk()->json('data');
        $this->assertSame(1, $f['attempts']);
        $s = $api()->getJson('/api/logistique/stats')->assertOk()->json();
        $this->assertSame(2, $s['total']);
        $this->assertEquals(50, $s['success_rate']);
        $this->assertEquals(50000, $s['cod_pending']);
        $this->assertSame(['Client absent' => 1], $s['failures']);
        $this->assertSame(1, $s['drivers'][0]['delivered']);
        $this->assertSame(['count' => 1, 'total' => 50000], $api()->postJson('/api/logistique/remit', ['driver_id' => $driver->id])->assertOk()->json());
        $this->assertEquals(0, $api()->getJson('/api/logistique/stats')->json('cod_pending'));
        // Encaissement remis : plus modifiable
        $dapi()->putJson("/api/logistique/deliveries/{$d['id']}", ['cod_collected' => 10000])->assertStatus(422);
    }
}
