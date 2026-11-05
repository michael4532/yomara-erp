<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Fne\Models\FneCertification;
use App\Modules\Fne\Models\FneConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FneTest extends TestCase
{
    private int $seq = 0;

    private function signResponse(array $lines, int $balance = 480): array
    {
        $this->seq++;

        return [
            'ncc' => '9502363N',
            'reference' => '9502363N25000000'.$this->seq,
            'token' => 'http://54.247.95.108/fr/verification/019465c1-3f61-766c-9652-706e3'.$this->seq,
            'warning' => false,
            'balance_sticker' => $balance,
            'invoice' => [
                'id' => 'e2b2d8da-a532-4c08-9182-f5b428ca468'.$this->seq,
                'reference' => '9502363N25000000'.$this->seq,
                'fiscalStamp' => 'FS-'.$this->seq,
                'amount' => 177000, 'vatAmount' => 27000,
                'items' => array_map(fn ($i) => ['id' => 'item-'.$this->seq.'-'.$i, 'quantity' => 1], array_keys($lines)),
            ],
        ];
    }

    public function test_fne_certification_flow_templates_taxes_refund_retry_offline_and_permissions(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Quincaillerie Bamba');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();

        $sent = [];
        $mode = 'ok';
        Http::fake(function (Request $req) use (&$sent, &$mode) {
            if (! str_starts_with($req->url(), 'http://54.247.95.108/ws/')) {
                return Http::response([], 404);
            }
            $sent[] = $req;
            if ($mode === 'down') {
                throw new ConnectionException('cURL error 7: Failed to connect to 54.247.95.108 port 80');
            }
            if ($mode === 'timeout') {
                throw new ConnectionException('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received');
            }
            if ($mode === '500') {
                return Http::response(['message' => 'Internal error', 'statusCode' => 500], 500);
            }
            if ($mode === '401') {
                return Http::response(['message' => 'Unauthorized', 'statusCode' => 401], 401);
            }
            if (str_contains($req->url(), '/refund')) {
                return Http::response(['reference' => 'AVOIR-'.count($req['items']), 'token' => 'tok-refund', 'balance_sticker' => $mode === 'low' ? 39 : 479, 'invoice' => ['id' => 'refund-1']], 201);
            }

            return Http::response($this->signResponse($req['items'], $mode === 'low' ? 40 : 480));
        });

        $partner = fn (array $p) => $api()->postJson('/api/core/partners', $p + ['type' => 'customer'])->assertCreated()->json('data.id');
        $sotra = $partner(['name' => 'SOTRA BTP', 'is_company' => true, 'tax_id' => '1234567A', 'phone' => '+225 27 20 30 40 50', 'email' => 'achats@sotra.ci']);
        $noNcc = $partner(['name' => 'Entreprise Sans NCC', 'is_company' => true]);
        $paris = $partner(['name' => 'Bolloré France', 'is_company' => true, 'tax_id' => 'FR123', 'country' => 'FR']);
        $product = $api()->postJson('/api/core/products', ['name' => 'Ciment CPJ 45', 'sku' => 'CIM-45', 'sale_price' => 5000, 'tax_rate' => 18])->assertCreated()->json('data.id');

        $invoice = function (?string $partnerId, array $lines, string $type = 'invoice', array $extra = []) use ($api) {
            $id = $this->uuid();
            $res = $api()->postJson('/api/sync/push', ['mutations' => [[
                'id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id,
                'data' => ['type' => $type, 'partner_id' => $partnerId, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => $lines] + $extra,
            ]]])->assertOk()->json('results.0');
            $this->assertSame('applied', $res['status'], json_encode($res));

            return $id;
        };
        $cert = fn (string $docId) => FneCertification::withoutGlobalScopes()->where('document_id', $docId)->first();
        $line = fn (float $q = 2, float $rate = 18, ?string $p = null) => ['id' => $this->uuid(), 'product_id' => $p, 'description' => $p ? 'Ciment CPJ 45' : 'Main d\'œuvre', 'quantity' => $q, 'unit_price' => 75000, 'tax_rate' => $rate, 'unit' => 'sac'];

        // Module inactif tant que la FNE n'est pas activée : rien n'est envoyé
        $before = $invoice($sotra, [$line()]);
        $this->assertNull($cert($before));
        $this->assertCount(0, $sent);

        // Paramètres : la clé API n'est jamais renvoyée ; production exige l'URL DGI
        $s = $api()->getJson('/api/fne/settings')->assertOk()->json('data');
        $this->assertFalse($s['enabled']);
        $this->assertSame('http://54.247.95.108/ws', $s['effective_url']);
        $api()->putJson('/api/fne/settings', ['enabled' => true, 'environment' => 'prod'])->assertStatus(422);
        $s = $api()->putJson('/api/fne/settings', ['enabled' => true, 'environment' => 'test', 'api_key' => 'SECRET-KEY-1234', 'point_of_sale' => 'Caisse 1', 'establishment' => 'Adjamé', 'footer' => 'Merci'])->assertOk()->json('data');
        $this->assertTrue($s['ready']);
        $this->assertNull($s['api_key']);
        $this->assertSame('••••1234', $s['api_key_hint']);
        $this->assertStringNotContainsString('SECRET-KEY-1234', json_encode($api()->getJson('/api/fne/settings')->json()));
        $this->assertNotSame('SECRET-KEY-1234', \DB::table('fne_configs')->value('api_key')); // chiffrée en base

        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $caissier = User::create(['company_id' => $company->id, 'name' => 'Awa', 'email' => 'awa@bamba.ci', 'password' => 'secret123', 'role_id' => $role('Caissier')]);
        $caisseApi = fn () => $this->api($caissier->createToken('t')->plainTextToken);
        $caisseApi()->getJson('/api/fne/settings')->assertOk();
        $caisseApi()->putJson('/api/fne/settings', ['enabled' => false])->assertForbidden();

        // 1) Facture B2B : certifiée à la validation
        $inv1 = $invoice($sotra, [$line(2, 18, $product), $line(1, 9), $line(1, 0)]);
        $c1 = $cert($inv1);
        $this->assertSame('certified', $c1->status, (string) $c1->last_error);
        $this->assertSame('B2B', $c1->template);
        $this->assertSame('deferred', $c1->payment_method);
        $this->assertStringStartsWith('9502363N', $c1->reference);
        $this->assertStringContainsString('/fr/verification/', $c1->verify_url);
        $this->assertCount(3, $c1->items);
        $this->assertSame('item-1-0', $c1->items[0]['fne_id']);
        $this->assertSame(480, FneConfig::withoutGlobalScopes()->first()->sticker_balance);
        $req = $sent[0];
        $this->assertSame('http://54.247.95.108/ws/external/invoices/sign', $req->url());
        $this->assertSame('Bearer SECRET-KEY-1234', $req->header('Authorization')[0]);
        $this->assertSame('sale', $req['invoiceType']);
        $this->assertSame('B2B', $req['template']);
        $this->assertSame('1234567A', $req['clientNcc']);
        $this->assertSame('SOTRA BTP', $req['clientCompanyName']);
        $this->assertSame('Caisse 1', $req['pointOfSale']);
        $this->assertSame('Adjamé', $req['establishment']);
        $this->assertSame([['TVA'], ['TVAB'], ['TVAD']], array_column($req['items'], 'taxes'));
        $this->assertSame('CIM-45', $req['items'][0]['reference']);
        $this->assertEquals(75000, $req['items'][0]['amount']);
        $this->assertEquals(2, $req['items'][0]['quantity']);

        // Le client hors ligne reçoit la certification par la synchronisation
        $pulled = collect($api()->getJson('/api/sync/pull?cursor=0&limit=2000')->json('changes.fne_certifications.upserts'));
        $this->assertTrue($pulled->contains('document_id', $inv1));
        $this->assertArrayNotHasKey('payload', $pulled->first());
        $this->assertArrayNotHasKey('payload', $pulled->first());
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'fne_certifications', 'op' => 'upsert', 'record_id' => $c1->id, 'data' => ['status' => 'failed']]]])
            ->assertOk()->assertJsonPath('results.0.status', 'rejected');

        // Une facture certifiée ne s'annule plus
        $api()->putJson("/api/ventes/documents/$inv1", ['status' => 'cancelled'])->assertStatus(422)->assertJsonFragment(['message' => 'Ce document est certifié FNE : il ne peut plus être annulé. Émettez un avoir.']);

        // 2) Modèles : client sans NCC → B2C, client étranger → B2F, ticket payé en Mobile Money
        $inv2 = $invoice($noNcc, [$line()]);
        $this->assertSame('B2C', $cert($inv2)->template);
        $this->assertArrayNotHasKey('clientNcc', end($sent)->data());
        $inv3 = $invoice($paris, [$line()]);
        $this->assertSame('B2F', $cert($inv3)->template);

        // 3) Refus de contenu : taux non admis → failed + notification, pas de nouvel essai automatique
        $n = count($sent);
        $bad = $invoice($sotra, [$line(1, 5)]);
        $cb = $cert($bad);
        $this->assertSame('failed', $cb->status);
        $this->assertStringContainsString('Taux de TVA 5 % non admis', $cb->last_error);
        $this->assertCount($n, $sent); // refusé localement, rien n'est parti
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('type', 'fne')->exists());
        $api()->postJson("/api/fne/certifications/{$cb->id}/retry")->assertOk()->assertJsonPath('data.status', 'failed');
        // Certification automatique désactivée, puis certification manuelle en B2B d'un client sans NCC : refusée
        $api()->putJson('/api/fne/settings', ['auto_certify' => false])->assertOk();
        $manualDoc = $invoice($noNcc, [$line()]);
        $this->assertNull($cert($manualDoc));
        $api()->postJson('/api/fne/certify', ['document_id' => $manualDoc, 'template' => 'B2B'])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertStringContainsString('renseignez le NCC', $cert($manualDoc)->last_error);
        $api()->postJson("/api/fne/certifications/{$cert($manualDoc)->id}/retry", ['template' => 'B2C'])->assertOk()->assertJsonPath('data.status', 'certified');
        $api()->putJson('/api/fne/settings', ['auto_certify' => true])->assertOk();

        // 4) Avoir partiel depuis la facture 1 : remboursement sur les lignes FNE d'origine
        $docLines = \DB::table('trade_document_lines')->where('document_id', $inv1)->orderBy('sort')->get();
        $credit = $invoice($sotra, [['product_id' => $product, 'description' => 'Ciment CPJ 45', 'quantity' => 1, 'unit_price' => 75000, 'tax_rate' => 18]], 'credit_note', ['source_id' => $inv1]);
        $cc = $cert($credit);
        $this->assertSame('certified', $cc->status, (string) $cc->last_error);
        $this->assertSame('refund', $cc->invoice_type);
        $this->assertSame($c1->id, $cc->parent_id);
        $refundReq = end($sent);
        $this->assertSame('http://54.247.95.108/ws/external/invoices/'.$c1->fne_invoice_id.'/refund', $refundReq->url());
        $this->assertEquals([['id' => 'item-1-0', 'quantity' => 1]], $refundReq['items']);
        $this->assertSame('AVOIR-1', $cc->reference);
        $this->assertNotNull($docLines);
        // Second avoir dépassant la quantité restante (1 sac) → refusé
        $credit2 = $invoice($sotra, [['product_id' => $product, 'description' => 'Ciment CPJ 45', 'quantity' => 2, 'unit_price' => 75000, 'tax_rate' => 18]], 'credit_note', ['source_id' => $inv1]);
        $this->assertSame('failed', $cert($credit2)->status);
        $this->assertStringContainsString('ne correspond à aucune ligne restant à rembourser', $cert($credit2)->last_error);
        // Avoir sans facture d'origine
        $credit3 = $invoice($sotra, [$line(1)], 'credit_note');
        $this->assertStringContainsString('rattaché à aucune facture', $cert($credit3)->last_error);

        // 5) Serveur DGI indisponible → en attente, relance automatique
        $mode = '500';
        $inv4 = $invoice($sotra, [$line()]);
        $c4 = $cert($inv4);
        $this->assertSame('pending', $c4->status);
        $this->assertSame(500, $c4->last_status);
        $this->assertTrue($c4->next_attempt_at->isFuture());
        // Réseau coupé : même chose
        $mode = 'down';
        $inv5 = $invoice($sotra, [$line()]);
        $this->assertSame('pending', $cert($inv5)->status);
        $this->assertStringContainsString('injoignable', $cert($inv5)->last_error);
        // Un avoir sur une facture en attente attend lui aussi
        $credit4 = $invoice($sotra, [$line(1)], 'credit_note', ['source_id' => $inv5]);
        $this->assertSame('pending', $cert($credit4)->status);
        $this->assertStringContainsString('En attente de la certification de la facture d\'origine', $cert($credit4)->last_error);
        // Une facture en attente peut encore être annulée : la demande est retirée
        $api()->putJson("/api/ventes/documents/$inv4", ['status' => 'cancelled'])->assertOk();
        $this->assertTrue($cert($inv4)->trashed());

        // Retour du réseau : la tâche planifiée certifie la file (facture avant avoir), alerte stickers
        $mode = 'low';
        $this->travel(2)->hours();
        $this->artisan('erp:fne-process')->assertSuccessful();
        $this->assertSame('certified', $cert($inv5)->status);
        $this->assertSame('certified', $cert($credit4)->status);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Stock de stickers FNE faible')->exists());
        $alerts = AppNotification::where('title', 'Stock de stickers FNE faible')->count();
        $this->artisan('erp:fne-process')->assertSuccessful();
        $this->assertSame($alerts, AppNotification::where('title', 'Stock de stickers FNE faible')->count()); // une alerte par jour

        // 6) Délai dépassé : pas de renvoi automatique (risque de double certification), saisie manuelle possible
        $mode = 'timeout';
        $inv6 = $invoice($sotra, [$line()]);
        $c6 = $cert($inv6);
        $this->assertSame('failed', $c6->status);
        $this->assertStringContainsString('Vérifiez sur le portail FNE', $c6->last_error);
        $api()->postJson("/api/fne/certifications/{$c6->id}/manual", ['reference' => '9502363N2500099', 'token' => 'abc-123'])->assertOk()
            ->assertJsonPath('data.status', 'certified')->assertJsonPath('data.verify_url', 'http://54.247.95.108/fr/verification/abc-123');

        // 7) Clé refusée → failed ; la nouvelle clé relance automatiquement la file
        $mode = '401';
        $inv7 = $invoice($sotra, [$line()]);
        $this->assertSame('failed', $cert($inv7)->status);
        $this->assertStringContainsString('Clé API FNE refusée', $cert($inv7)->last_error);
        $mode = 'ok';
        $api()->putJson('/api/fne/settings', ['api_key' => 'NEW-KEY-9999'])->assertOk();
        $this->assertSame('certified', $cert($inv7)->status);
        $this->assertSame('Bearer NEW-KEY-9999', end($sent)->header('Authorization')[0]);

        // 8) Certification manuelle d'une facture validée avant l'activation, avec mode de paiement choisi
        $this->assertTrue(collect($api()->getJson('/api/fne/uncertified')->json('data'))->contains('id', $before));
        $r = $api()->postJson('/api/fne/certify', ['document_id' => $before, 'payment_method' => 'transfer'])->assertOk()->json('data');
        $this->assertSame('certified', $r['status']);
        $this->assertSame('transfer', end($sent)['paymentMethod']);
        $api()->getJson("/api/fne/certifications/{$r['id']}")->assertOk()->assertJsonPath('data.payload.paymentMethod', 'transfer');
        $caisseApi()->postJson('/api/fne/certify', ['document_id' => $before])->assertForbidden();

        // Ticket de caisse hors ligne (paiement Mobile Money dans le même lot) : le moyen de paiement est repris
        $mode = 'down';
        $ticket = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [
            ['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $ticket, 'data' => ['type' => 'receipt', 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [$line(1)]]],
            ['id' => $this->uuid(), 'entity' => 'sales_payments', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['document_id' => $ticket, 'date' => now()->toDateString(), 'amount' => 88500, 'method' => 'mobile_money']],
        ]])->assertOk()->assertJsonPath('results.1.status', 'applied');
        $this->assertSame('pending', $cert($ticket)->status);
        $mode = 'ok';
        $this->travel(10)->minutes();
        $this->artisan('erp:fne-process')->assertSuccessful();
        $this->assertSame('certified', $cert($ticket)->status);
        $this->assertSame('mobile-money', $cert($ticket)->payment_method);
        $this->assertSame('receipt', $cert($ticket)->document_type);
        $this->assertSame('Client comptant', end($sent)['clientCompanyName']);
        $this->assertSame('B2C', end($sent)['template']);

        // Bordereau d'achat : facture fournisseur d'un producteur sans facture normalisée
        $supplier = $api()->postJson('/api/core/partners', ['name' => 'Coopérative de Daloa', 'type' => 'supplier'])->assertCreated()->json('data.id');
        $ff = $this->uuid();
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'purchase_documents', 'op' => 'upsert', 'record_id' => $ff,
            'data' => ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [['description' => 'Cacao fèves', 'quantity' => 100, 'unit_price' => 1500, 'tax_rate' => 0]]]]]])
            ->assertOk()->assertJsonPath('results.0.status', 'applied');
        $this->assertNull($cert($ff)); // jamais automatique côté achats
        $api()->postJson('/api/fne/certify', ['document_id' => $ff, 'direction' => 'purchase'])->assertOk()->assertJsonPath('data.status', 'certified')->assertJsonPath('data.document_type', 'purchase');
        $this->assertSame('purchase', end($sent)['invoiceType']);
        $this->assertSame('Coopérative de Daloa', end($sent)['clientCompanyName']);

        // Tableau de bord
        $stats = $api()->getJson('/api/fne/settings')->json('data.stats');
        $this->assertGreaterThanOrEqual(7, $stats['certified_month']);
        $this->assertSame(0, $stats['pending']);
    }
}
