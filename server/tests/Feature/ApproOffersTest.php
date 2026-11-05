<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\AppNotification;
use App\Modules\Appro\Models\Comparison;
use App\Modules\Appro\Models\OfferInvite;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApproOffersTest extends TestCase
{
    protected ArrayTransport $mails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = new ArrayTransport;
        Smtp::$transportFactory = fn () => $this->mails;
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Électricité Générale Koné');
        $api = fn () => $this->api($token);
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.k.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'k@k.ci', 'password' => 'x', 'from_email' => 'achats@egk.ci', 'from_name' => 'EGK',
        ]])->assertOk();
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $sup = fn (string $name, ?string $email, ?string $phone = null) => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'supplier', 'email' => $email, 'phone' => $phone])->json('data.id');
        $ep = $sup('Électro Plateau', 'ventes@ep.ci');
        $ca = $sup('Comptoir Adjamé', 'contact@ca.ci');
        $sans = $sup('Quincaillerie Sans Mail', null, '+2250700000000');
        $cable = $api()->postJson('/api/core/products', ['name' => 'Câble R2V 3G2,5', 'sale_price' => 60000, 'purchase_price' => 41000, 'tax_rate' => 18])->json('data.id');

        return compact('company', 'admin', 'api', 'push', 'ep', 'ca', 'sans', 'cable');
    }

    public function test_compare_from_request_invite_online_offer_questions_and_reminder(): void
    {
        Storage::fake('local');
        ['company' => $company, 'admin' => $admin, 'api' => $api, 'push' => $push, 'ep' => $ep, 'ca' => $ca, 'sans' => $sans, 'cable' => $cable] = $this->env();

        // « Comparer » depuis une demande d'achat : lignes reprises, fournisseur suggéré ajouté ; pas de doublon
        $rid = $this->uuid();
        $this->assertSame('applied', $push('purchase_requests', ['title' => 'Matériel chantier R+3', 'needed_by' => now()->addDays(20)->toDateString(),
            'lines' => [['product_id' => $cable, 'description' => 'Câble R2V 3G2,5', 'quantity' => 10, 'unit' => 'couronne', 'supplier_id' => $ep], ['description' => 'Disjoncteur 20 A', 'quantity' => 40]]], $rid)['status']);
        $cmp = $api()->postJson('/api/appro/comparisons/from', ['type' => 'request', 'id' => $rid])->assertOk()->json('data');
        $this->assertSame('request', $cmp['source']['type']);
        $this->assertCount(2, $cmp['lines']);
        $this->assertSame([$ep], array_column($cmp['offers'], 'partner_id'));
        $this->assertSame($cmp['id'], $api()->postJson('/api/appro/comparisons/from', ['type' => 'request', 'id' => $rid])->json('data.id'));
        $this->assertCount(1, $api()->getJson('/api/appro/comparisons?source_type=request&source_id='.$rid)->json('data'));
        [$k1, $k2] = array_column($cmp['lines'], 'key');
        $api()->putJson('/api/appro/comparisons/'.$cmp['id'], ['instructions' => 'Livraison sur le chantier de Cocody incluse.', 'deadline' => now()->addDays(5)->toDateString()])->assertOk();

        // Invitation : e-mail avec le lien pour 2 fournisseurs, lien à partager pour celui qui n'a pas d'e-mail
        $res = $api()->postJson('/api/appro/comparisons/'.$cmp['id'].'/invite', ['partner_ids' => [$ep, $ca, $sans]])->assertOk()->json();
        $this->assertSame(2, $res['result']['sent']);
        $this->assertSame(['Quincaillerie Sans Mail'], array_column($res['result']['to_share'], 'name'));
        $this->assertCount(3, $res['data']['invites']);
        $this->assertCount(3, $res['data']['offers']); // une colonne par fournisseur consulté
        $this->assertCount(2, $this->mails->messages());
        $this->assertStringContainsString('/offre/', $this->mails->messages()->first()->toString());
        $tokEp = OfferInvite::where('partner_id', $ep)->value('token');
        $tokCa = OfferInvite::where('partner_id', $ca)->value('token');

        // Le fournisseur ouvre son lien et répond en ligne en euros, avec son devis joint
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get('/offre/'.$tokEp)->assertOk()->assertSee('Matériel chantier R+3')->assertSee('Livraison sur le chantier de Cocody incluse.')->assertSee('Disjoncteur 20 A');
        $this->assertSame('opened', OfferInvite::withoutGlobalScopes()->where('token', $tokEp)->value('status'));
        $this->get('/offre/'.str_repeat('x', 40))->assertNotFound();
        $this->post('/offre/'.$tokEp, ['price' => [$k1 => '', $k2 => '']])->assertSessionHas('error', 'Indiquez au moins un prix.');
        $this->post('/offre/'.$tokEp, ['currency' => 'EUR', 'price' => [$k1 => '70', $k2 => '5,5'], 'note' => [$k1 => 'Nexans'], 'delay_days' => 4, 'extra' => '25 000',
            'payment_terms' => '30 jours', 'compliant' => '1', 'file' => UploadedFile::fake()->create('devis.pdf', 120, 'application/pdf')])->assertRedirect('/offre/'.$tokEp);
        $this->post('/offre/'.$tokCa, ['price' => [$k1 => '45 000', $k2 => '3500'], 'delay_days' => 8])->assertRedirect();
        $this->post('/offre/'.$tokCa, ['price' => [$k1 => '44500', $k2 => '3500'], 'delay_days' => 7])->assertRedirect();

        // Questions / réponses : le fournisseur écrit, l'acheteur pose une question (e-mail avec le lien) et consigne une réponse reçue par téléphone
        $this->post('/offre/'.$tokCa.'/message', ['body' => 'Le câble est-il en cuivre ou en aluminium ?'])->assertRedirect();
        $this->get('/offre/'.$tokCa)->assertSee('Le câble est-il en cuivre');

        $full = $api()->getJson('/api/appro/comparisons/'.$cmp['id'])->assertOk()->json('data');
        $byP = collect($full['offers'])->keyBy('partner_id');
        $this->assertSame(['online', 'EUR', 4, 'Nexans'], [$byP[$ep]['source'], $byP[$ep]['currency'], $byP[$ep]['delay_days'], $byP[$ep]['line_notes'][$k1]]);
        $this->assertEquals(25000, $byP[$ep]['extra']);
        $this->assertEquals(655.957, $byP[$ep]['rate']);
        $this->assertEquals(44500, $byP[$ca]['prices'][$k1]);
        $inv = collect($full['invites'])->keyBy('partner_id');
        $this->assertSame(['answered', 1, true], [$inv[$ca]['status'], $inv[$ca]['revisions'], $inv[$ep]['has_file']]);
        $this->assertSame(1, $inv[$ca]['unread']);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $admin->id)->where('title', 'like', 'Offre reçue : Électro Plateau')->exists());
        $api()->get('/api/appro/comparisons/'.$cmp['id'].'/invites/'.$inv[$ep]['id'].'/file')->assertOk();
        $before = $this->mails->messages()->count();
        $api()->postJson('/api/appro/comparisons/'.$cmp['id'].'/messages', ['invite_id' => $inv[$ca]['id'], 'body' => 'Cuivre, section 2,5 mm². Pouvez-vous livrer en 2 lots ?'])->assertOk();
        $this->assertSame($before + 1, $this->mails->messages()->count());
        $api()->postJson('/api/appro/comparisons/'.$cmp['id'].'/messages', ['invite_id' => $inv[$ca]['id'], 'body' => 'Oui, 2 lots sans frais (appel du 12)', 'direction' => 'in', 'channel' => 'phone'])->assertOk();
        $read = $api()->postJson('/api/appro/comparisons/'.$cmp['id'].'/read')->assertOk()->json('data');
        $this->assertSame(0, collect($read['invites'])->sum('unread'));
        $this->assertCount(3, $read['messages']);
        $list = collect($api()->getJson('/api/appro/comparisons')->json('data'))->firstWhere('id', $cmp['id']);
        $this->assertSame(['invited' => 3, 'answered' => 2, 'declined' => 0, 'unread' => 0], $list['online']);

        // L'acheteur réenregistre la grille : la réponse en ligne garde son origine et ses remarques
        $grid = $api()->putJson('/api/appro/comparisons/'.$cmp['id'], ['offers' => $full['offers']])->assertOk()->json('data');
        $this->assertSame('Nexans', collect($grid['offers'])->firstWhere('partner_id', $ep)['line_notes'][$k1]);

        // Relance 2 jours avant la date limite pour celui qui n'a pas répondu (une seule fois), refus d'un fournisseur
        $api()->putJson('/api/appro/comparisons/'.$cmp['id'], ['deadline' => now()->addDays(2)->toDateString()])->assertOk();
        $tokS = OfferInvite::where('partner_id', $sans)->value('token');
        OfferInvite::where('partner_id', $sans)->update(['email' => 'qsm@q.ci']);
        $this->artisan('erp:offers-remind')->assertSuccessful();
        $this->artisan('erp:offers-remind')->assertSuccessful();
        $this->assertNotNull(OfferInvite::withoutGlobalScopes()->where('token', $tokS)->value('reminded_at'));
        $this->assertSame(1, collect($this->mails->messages())->filter(fn ($m) => str_contains($m->getOriginalMessage()->getSubject(), 'Rappel'))->count());
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->post('/offre/'.$tokS.'/decliner', ['reason' => 'Rupture de stock'])->assertRedirect();
        $this->assertSame('declined', OfferInvite::withoutGlobalScopes()->where('token', $tokS)->value('status'));

        // Après la date limite : offre refusée ; après attribution, le fournisseur voit le résultat
        Carbon::setTestNow(now()->addDays(3));
        $this->post('/offre/'.$tokCa, ['price' => [$k1 => '1']])->assertSessionHas('error', 'La date limite est passée : les offres sont closes.');
        Carbon::setTestNow();
        $key = collect($full['offers'])->firstWhere('partner_id', $ca)['key'];
        $api()->postJson('/api/appro/comparisons/'.$cmp['id'].'/award', ['offer' => $key])->assertOk();
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get('/offre/'.$tokCa)->assertSee('Votre offre est retenue');
        $this->get('/offre/'.$tokEp)->assertSee('Cette consultation est attribuée');
    }

    public function test_compare_from_fund_request_tender_and_rfq(): void
    {
        ['api' => $api, 'push' => $push, 'ep' => $ep, 'ca' => $ca, 'cable' => $cable] = $this->env();

        // Appel de fonds : seules les lignes de matériel / prestations sont comparées
        $f = $api()->postJson('/api/fonds/requests', ['title' => 'Équipement agence Yopougon', 'department' => 'Agence Yopougon', 'needed_by' => now()->addDays(10)->toDateString(), 'lines' => [
            ['type' => 'funds', 'description' => 'Fonds de caisse', 'quantity' => 1, 'unit_amount' => 100000],
            ['type' => 'equipment', 'description' => 'Climatiseur split 1,5 CV', 'quantity' => 2, 'unit_amount' => 280000],
        ]])->assertCreated()->json('data');
        $cmp = $api()->postJson('/api/appro/comparisons/from', ['type' => 'fund', 'id' => (string) $f['id']])->assertOk()->json('data');
        $this->assertSame(['Climatiseur split 1,5 CV'], array_column($cmp['lines'], 'description'));
        $this->assertSame('Appel de fonds', $cmp['source']['type_label']);

        // Appel d'offres du portail : lignes et offres déjà déposées reprises
        $tid = $this->uuid();
        $lines = [['id' => 'L1', 'description' => 'Câble R2V 3G2,5', 'quantity' => 20, 'unit' => 'couronne'], ['id' => 'L2', 'description' => 'Gaine ICTA 20', 'quantity' => 30, 'unit' => 'couronne']];
        $this->assertSame('applied', $push('sp_tenders', ['title' => 'Câblage R+3', 'lines' => $lines, 'deadline' => now()->addDays(3)->toDateTimeString(), 'invited' => [$ep, $ca], 'status' => 'open'], $tid)['status']);
        $tok = \App\Modules\Fournisseurs\Models\Access::where('partner_id', $ep)->value('token');
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->post('/fournisseur/'.$tok.'/ao/'.$tid, ['price' => ['L1' => '47000', 'L2' => '9000'], 'delivery_days' => 3])->assertRedirect();
        $cmp = $api()->postJson('/api/appro/comparisons/from', ['type' => 'tender', 'id' => $tid])->assertOk()->json('data');
        $this->assertSame(['L1', 'L2'], array_column($cmp['lines'], 'key'));
        $o = collect($cmp['offers'])->firstWhere('partner_id', $ep);
        $this->assertEquals(['L1' => 47000, 'L2' => 9000], $o['prices']);
        $this->assertEquals(47000 * 20 + 9000 * 30, $cmp['analysis']['offers'][0]['total']);

        // Demande de prix (document d'achat)
        $did = $this->uuid();
        $this->assertSame('applied', $push('purchase_documents', ['type' => 'rfq', 'partner_id' => $ca, 'date' => now()->toDateString(), 'lines' => [
            ['id' => $this->uuid(), 'product_id' => $cable, 'description' => 'Câble R2V 3G2,5', 'quantity' => 5, 'unit_price' => 0, 'tax_rate' => 18, 'discount_percent' => 0],
        ]], $did)['status']);
        $cmp = $api()->postJson('/api/appro/comparisons/from', ['type' => 'rfq', 'id' => $did])->assertOk()->json('data');
        $this->assertSame($cable, $cmp['lines'][0]['product_id']);
        $this->assertSame('rfq', Comparison::find($cmp['id'])->source_type);
        $api()->postJson('/api/appro/comparisons/from', ['type' => 'rfq', 'id' => $this->uuid()])->assertNotFound();
    }
}
