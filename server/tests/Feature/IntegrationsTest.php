<?php

namespace Tests\Feature;

use App\Integrations\Webhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Intégrations : webhooks sortants signés, relances, clés d'API, flux de données, documentation. */
class IntegrationsTest extends TestCase
{
    protected function tearDown(): void
    {
        Webhooks::forget();
        parent::tearDown();
    }

    public function test_webhooks_api_keys_and_feeds(): void
    {
        [$company, , $token] = $this->makeCompany('Connect SARL');
        $api = fn () => $this->api($token);
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true]), 'down.example.com/*' => Http::sequence()->push('boom', 500)->push('boom', 500)->push('', 204)]);

        $e = $api()->postJson('/api/integrations/webhooks', ['url' => 'https://hooks.example.com/yomara', 'description' => 'Zapier', 'events' => ['partners.*', 'invoice.validated', 'payment.received', 'invoice.paid']])
            ->assertCreated()->json();
        $secret = $e['secret'];
        $this->assertStringStartsWith('whsec_', $secret);
        $api()->postJson('/api/integrations/webhooks', ['url' => 'pas-une-url', 'events' => ['*']])->assertStatus(422);

        // Test de connexion : livraison immédiate et signée
        $t = $api()->postJson("/api/integrations/webhooks/{$e['data']['id']}/test")->assertOk()->json('data');
        $this->assertEquals(['ping', 'success', 200], [$t['event'], $t['status'], $t['response_code']]);
        Http::assertSent(function ($req) use ($secret) {
            if ($req->header('X-Yomara-Event')[0] !== 'ping') {
                return false;
            }
            [$ts, $v1] = sscanf($req->header('X-Yomara-Signature')[0], 't=%d,v1=%s');

            return hash_equals(hash_hmac('sha256', $ts.'.'.$req->body(), $secret), $v1);
        });

        // Création d'un client → partners.created ; facture validée puis payée → événements métier
        $partner = $api()->postJson('/api/core/partners', ['name' => 'Hôtel Ivoire', 'type' => 'customer', 'phone' => '+2250700000001'])->assertCreated()->json('data.id');
        Http::assertSent(fn ($req) => ($req->header('X-Yomara-Event')[0] ?? '') === 'partners.created' && $req['data']['name'] === 'Hôtel Ivoire');
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data]]])->assertOk()->json('results.0');
        $inv = $this->uuid();
        $this->assertSame('applied', $push('sales_documents', ['type' => 'invoice', 'partner_id' => $partner, 'date' => now()->toDateString(), 'lines' => [
            ['id' => $this->uuid(), 'description' => 'Séjour', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 0]]], $inv)['status']);
        $api()->postJson("/api/ventes/documents/$inv/validate")->assertOk();
        Http::assertSent(fn ($req) => ($req->header('X-Yomara-Event')[0] ?? '') === 'invoice.validated' && $req['data']['id'] === $inv && count($req['data']['lines']) === 1);
        $this->assertSame('applied', $push('sales_payments', ['document_id' => $inv, 'date' => now()->toDateString(), 'amount' => 100000, 'method' => 'mobile_money'])['status']);
        Http::assertSent(fn ($req) => ($req->header('X-Yomara-Event')[0] ?? '') === 'payment.received');
        Http::assertSent(fn ($req) => ($req->header('X-Yomara-Event')[0] ?? '') === 'invoice.paid' && $req['data']['payment_status'] === 'paid');
        Http::assertNotSent(fn ($req) => ($req->header('X-Yomara-Event')[0] ?? '') === 'sales_documents.created');
        $dl = $api()->getJson("/api/integrations/webhooks/{$e['data']['id']}/deliveries")->assertOk()->json('data');
        $this->assertTrue(collect($dl)->every(fn ($d) => $d['status'] === 'success'));
        $full = $api()->getJson('/api/integrations/deliveries/'.$dl[0]['id'])->assertOk()->json('data');
        $this->assertArrayHasKey('payload', $full);

        // Adresse en panne : relances espacées, puis renvoi manuel
        $down = $api()->postJson('/api/integrations/webhooks', ['url' => 'https://down.example.com/h', 'events' => ['partners.updated']])->assertCreated()->json('data');
        $api()->putJson("/api/core/partners/$partner", ['name' => 'Hôtel Ivoire Sofitel', 'type' => 'customer'])->assertOk();
        $d = DB::table('wh_deliveries')->where('endpoint_id', $down['id'])->first();
        $this->assertEquals(['pending', 1, 500], [$d->status, $d->attempts, $d->response_code]);
        $this->assertTrue(\Carbon\Carbon::parse($d->next_attempt_at)->isFuture());
        $this->travel(2)->minutes();
        $this->assertSame(1, app(Webhooks::class)->retryDue()['sent']);
        $this->assertSame(2, DB::table('wh_deliveries')->where('id', $d->id)->value('attempts'));
        $this->assertSame('success', $api()->postJson("/api/integrations/deliveries/{$d->id}/redeliver")->assertOk()->json('data.status'));
        $this->travelBack();

        // Clés d'API : lecture seule par défaut, jamais d'accès aux intégrations
        $k = $api()->postJson('/api/integrations/keys', ['name' => 'Power BI'])->assertCreated()->json();
        $this->assertFalse($k['data']['write']);
        $this->flushHeaders();
        $ro = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$k['token'], 'Accept' => 'application/json']);
        app()->forgetInstance('tenant');
        $this->app['auth']->forgetGuards();
        $ro()->getJson('/api/core/partners')->assertOk();
        $this->app['auth']->forgetGuards();
        $ro()->postJson('/api/core/partners', ['name' => 'Pirate', 'type' => 'customer'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $ro()->getJson('/api/integrations')->assertForbidden();
        $rw = $api()->postJson('/api/integrations/keys', ['name' => 'Site web', 'write' => true])->assertCreated()->json();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$rw['token'], 'Accept' => 'application/json'])->postJson('/api/core/partners', ['name' => 'Client du site', 'type' => 'customer'])->assertCreated();
        $keys = $api()->getJson('/api/integrations')->json('data.keys');
        $this->assertCount(2, $keys);
        $api()->deleteJson('/api/integrations/keys/'.$k['data']['id'])->assertNoContent();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $ro()->getJson('/api/core/partners')->assertUnauthorized();

        // Flux de données : CSV pour Google Sheets, JSON pour Power BI
        $f = $api()->postJson('/api/integrations/feeds', ['name' => 'Factures pour Sheets', 'dataset' => 'invoices'])->assertCreated()->json('data');
        $this->assertStringContainsString('=IMPORTDATA("', $f['sheets']);
        $this->flushHeaders();
        $csv = $this->get(parse_url($f['csv'], PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
        $this->assertStringContainsString('Numéro,Type,Date', $csv);
        $this->assertStringContainsString('"Hôtel Ivoire",100000,0,100000,100000,0,Payée,XOF', $csv);
        $this->assertStringContainsString(','.now()->addDays(30)->toDateString().',', $csv);
        $j = $this->getJson(parse_url($f['json'], PHP_URL_PATH))->assertOk()->json();
        $this->assertEquals([1, 100000], [count($j['rows']), $j['rows'][0]['total_ttc']]);
        foreach (['sales_lines', 'payments', 'customers', 'products', 'purchases', 'expenses'] as $ds) {
            $u = $api()->postJson('/api/integrations/feeds', ['name' => $ds, 'dataset' => $ds])->assertCreated()->json('data.csv');
            $this->flushHeaders();
            $this->get(parse_url($u, PHP_URL_PATH))->assertOk();
        }
        $this->get('/api/feeds/'.str_repeat('x', 40).'.csv')->assertNotFound();
        $this->assertSame(2, DB::table('data_feeds')->where('id', $f['id'])->value('hits')); // CSV + JSON

        // Documentation
        $this->get('/api/docs')->assertOk()->assertSee('invoice.paid')->assertSee('X-Yomara-Signature')->assertSee('/api/core/partners');
    }
}
