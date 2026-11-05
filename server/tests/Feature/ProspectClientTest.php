<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Prospect → client automatique (comme Dolibarr) : il devient client dès sa première facture validée. */
class ProspectClientTest extends TestCase
{
    public function test_prospect_becomes_customer_on_first_validated_invoice_only(): void
    {
        [, , $token] = $this->makeCompany('Négoce Prospect');
        $api = $this->api($token);
        $api->getJson('/api/sync/pull?cursor=0')->assertOk();
        $pid = $this->uuid();
        $prod = $this->uuid();
        $push = fn (array $m) => $api->postJson('/api/sync/push', ['mutations' => array_map(fn ($x) => $x + ['id' => $this->uuid(), 'op' => 'upsert'], $m)])->assertOk()->json('results');
        $r = $push([
            ['entity' => 'partners', 'record_id' => $pid, 'data' => ['name' => 'Hôtel Lagune', 'type' => 'prospect']],
            ['entity' => 'products', 'record_id' => $prod, 'data' => ['name' => 'Eau 1,5L', 'sale_price' => 400, 'tax_rate' => 18]],
        ]);
        $this->assertSame('applied', $r[0]['status']);
        $this->assertSame('prospect', $r[0]['record']['type']);

        $doc = function (string $type) use ($api, $pid, $prod) {
            $id = $api->postJson('/api/ventes/documents', ['type' => $type, 'partner_id' => $pid, 'date' => now()->toDateString(),
                'lines' => [['product_id' => $prod, 'description' => 'Eau', 'quantity' => 10, 'unit_price' => 400, 'tax_rate' => 18]]])->assertSuccessful()->json('data.id');

            return [$id, fn () => $api->postJson("/api/ventes/documents/$id/validate")->assertSuccessful()];
        };
        $type = fn () => \Illuminate\Support\Facades\DB::table('partners')->where('id', $pid)->value('type');

        // devis validé, facture en brouillon : toujours prospect
        [, $validateQuote] = $doc('quote');
        $validateQuote();
        [, $validateInvoice] = $doc('invoice');
        $this->assertSame('prospect', $type());
        // facture validée : client
        $validateInvoice();
        $this->assertSame('customer', $type());
    }

    public function test_prospection_fields_codes_and_first_contact(): void
    {
        [, , $token] = $this->makeCompany('Négoce Prospection');
        $api = $this->api($token);
        $api->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (array $m) => $api->postJson('/api/sync/push', ['mutations' => array_map(fn ($x) => $x + ['id' => $this->uuid(), 'op' => 'upsert'], $m)])->assertOk()->json('results');
        $p1 = $this->uuid();
        $p2 = $this->uuid();
        $sup = $this->uuid();
        $r = $push([
            ['entity' => 'partners', 'record_id' => $p1, 'data' => ['name' => 'Clinique du Plateau', 'type' => 'prospect', 'prospect_level' => 'high', 'source' => 'event',
                'website' => 'clinique.ci', 'contacts' => [['name' => 'Awa Koné', 'role' => 'Directrice', 'phone' => '0707070707']]]],
            ['entity' => 'partners', 'record_id' => $p2, 'data' => ['name' => 'Boutique Treichville', 'type' => 'customer']],
            ['entity' => 'partners', 'record_id' => $sup, 'data' => ['name' => 'Grossiste', 'type' => 'supplier']],
        ]);
        $ym = now()->format('ym');
        $this->assertSame('C'.$ym.'-0001', $r[0]['record']['code']);
        $this->assertSame('C'.$ym.'-0002', $r[1]['record']['code']);
        $this->assertSame('F'.$ym.'-0001', $r[2]['record']['code']);
        $this->assertSame('never', $r[0]['record']['prospect_status']);
        $this->assertSame('Directrice', $r[0]['record']['contacts'][0]['role']);
        $this->assertNull($r[1]['record']['prospect_status']);

        // statut invalide refusé
        $bad = $push([['entity' => 'partners', 'record_id' => $p1, 'data' => ['name' => 'Clinique du Plateau', 'prospect_status' => 'xx']]]);
        $this->assertNotSame('applied', $bad[0]['status']);

        // un appel fait → « Contact en cours »
        $status = fn () => \Illuminate\Support\Facades\DB::table('partners')->where('id', $p1)->value('prospect_status');
        $push([['entity' => 'crm_activities', 'record_id' => $this->uuid(), 'data' => ['partner_id' => $p1, 'type' => 'call', 'subject' => 'Premier appel', 'done' => false]]]);
        $this->assertSame('never', $status());
        $push([['entity' => 'crm_activities', 'record_id' => $this->uuid(), 'data' => ['partner_id' => $p1, 'type' => 'call', 'subject' => 'Appel', 'done' => true]]]);
        $this->assertSame('in_progress', $status());
    }
}
