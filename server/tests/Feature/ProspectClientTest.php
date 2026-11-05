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
}
