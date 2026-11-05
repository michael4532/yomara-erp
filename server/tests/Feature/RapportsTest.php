<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\Role;
use App\Models\User;
use App\Modules\ImportExport\Sheet;
use App\Modules\Rapports\Models\Board;
use App\Modules\Rapports\Models\Report;
use App\Modules\Rapports\Models\Schedule;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

class RapportsTest extends TestCase
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
        parent::tearDown();
    }

    private function doc($api, array $data): string
    {
        $id = $this->uuid();
        $r = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id, 'data' => $data + ['status' => 'validated']]]])->json('results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $id;
    }

    private function push($api, string $entity, array $data, ?string $id = null): array
    {
        return $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data]]])->json('results.0');
    }

    /** Société avec ventes sur trois mois, un avoir, un ticket et une facture en euros. */
    private function seedSales(): array
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-06-15 10:00'));
        [$company, $admin, $token] = $this->makeCompany('Négoce Yao');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $p = fn (array $d) => $api()->postJson('/api/core/partners', $d + ['type' => 'customer'])->assertCreated()->json('data.id');
        $hotel = $p(['name' => 'Hôtel Ivoire', 'city' => 'Abidjan']);
        $maquis = $p(['name' => 'Maquis Le Baobab', 'city' => 'Bouaké']);
        $paris = $p(['name' => 'Comptoir de Paris', 'city' => 'Paris', 'currency' => 'EUR']);
        $riz = $api()->postJson('/api/core/products', ['name' => 'Riz parfumé 25 kg', 'sale_price' => 20000, 'purchase_price' => 15000, 'tax_rate' => 0])->json('data.id');
        $huile = $api()->postJson('/api/core/products', ['name' => 'Huile 20 L', 'sale_price' => 30000, 'purchase_price' => 27000, 'tax_rate' => 0])->json('data.id');
        $line = fn ($prod, $q, $price) => ['product_id' => $prod, 'description' => 'Article', 'quantity' => $q, 'unit_price' => $price, 'tax_rate' => 0];

        $this->doc($api, ['type' => 'invoice', 'partner_id' => $hotel, 'date' => '2026-04-10', 'lines' => [$line($riz, 10, 20000)]]);       // 200 000
        $this->doc($api, ['type' => 'invoice', 'partner_id' => $hotel, 'date' => '2026-06-02', 'lines' => [$line($huile, 10, 30000)]]);     // 300 000
        $this->doc($api, ['type' => 'invoice', 'partner_id' => $maquis, 'date' => '2026-06-05', 'lines' => [$line($riz, 5, 20000)]]);      // 100 000
        $this->doc($api, ['type' => 'credit_note', 'partner_id' => $maquis, 'date' => '2026-06-06', 'lines' => [$line($riz, 1, 20000)]]);  // −20 000
        $this->doc($api, ['type' => 'invoice', 'partner_id' => $paris, 'date' => '2026-05-20', 'currency' => 'EUR', 'lines' => [$line($riz, 1, 100)]]); // 100 € = 65 595,70
        $this->doc($api, ['type' => 'quote', 'partner_id' => $maquis, 'date' => '2026-06-07', 'lines' => [$line($riz, 50, 20000)]]);      // devis : hors CA

        return compact('company', 'admin', 'token', 'api', 'hotel', 'maquis', 'paris', 'riz', 'huile');
    }

    public function test_engine_grouping_periods_filters_limits_compare_pivot_and_validation(): void
    {
        ['company' => $company, 'api' => $api, 'hotel' => $hotel, 'maquis' => $maquis] = $this->seedSales();
        $run = fn (string $source, array $config) => $api()->postJson('/api/rapports/preview', ['source' => $source, 'config' => $config]);

        // Tableau de bord installé à l'activation
        $board = Board::where('company_id', $company->id)->first();
        $this->assertSame('Pilotage de la direction', $board->name);
        $this->assertGreaterThanOrEqual(6, count($board->widgets));
        $this->assertSame('roles', $board->visibility);

        // CA HT par mois, avoir déduit, euro converti, mois vides complétés
        $res = $run('ventes', ['dimensions' => ['month'], 'measures' => ['subtotal', 'count'], 'period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-06-30'])->assertOk()->json('data');
        $this->assertSame(['2026-03', '2026-04', '2026-05', '2026-06'], array_map(fn ($r) => $r['keys']['month'], $res['rows']));
        $this->assertSame('mars 2026', $res['rows'][0]['labels']['month']);
        $this->assertEquals([0, 200000, 65595.7, 380000], array_map(fn ($r) => $r['values']['subtotal'], $res['rows']));
        $this->assertEquals(645595.7, $res['totals']['subtotal']);
        $this->assertEquals(5, $res['totals']['count']);

        // Cumul progressif
        $cum = $run('ventes', ['dimensions' => ['month'], 'measures' => ['subtotal'], 'period' => 'custom', 'from' => '2026-04-01', 'to' => '2026-06-30', 'cumulative' => true])->json('data.rows');
        $this->assertEquals([200000, 265595.7, 645595.7], array_column(array_column($cum, 'values'), 'subtotal'));

        // Top 1 client avec ligne « Autres »
        $top = $run('ventes', ['dimensions' => ['partner'], 'measures' => ['subtotal'], 'period' => 'this_year', 'limit' => 1, 'chart' => 'hbar'])->json('data');
        $this->assertSame('Hôtel Ivoire', $top['rows'][0]['labels']['partner']);
        $this->assertEquals(500000, $top['rows'][0]['values']['subtotal']);
        $this->assertEquals(145595.7, $top['others']['subtotal']);

        // Filtres : client, texte, condition sur les totaux
        $f = $run('ventes', ['dimensions' => ['type'], 'measures' => ['subtotal'], 'period' => 'all', 'filters' => [['field' => 'partner', 'op' => 'eq', 'value' => $maquis]]])->json('data');
        $this->assertEqualsCanonicalizing(['Facture', 'Avoir'], array_map(fn ($r) => $r['labels']['type'], $f['rows']));
        $this->assertEquals(80000, $f['totals']['subtotal']);
        $this->assertStringContainsString('Client = Maquis Le Baobab', $f['filters_label']);
        $this->assertEquals(65595.7, $run('ventes', ['measures' => ['subtotal'], 'period' => 'all', 'filters' => [['field' => 'city', 'op' => 'contains', 'value' => 'PAR']]])->json('data.totals.subtotal'));
        $h = $run('ventes', ['dimensions' => ['partner'], 'measures' => ['subtotal'], 'period' => 'all', 'having' => [['measure' => 'subtotal', 'op' => 'gt', 'value' => 70000]]])->json('data');
        $this->assertCount(2, $h['rows']);
        $this->assertEquals(580000, $h['totals']['subtotal']);

        // Indicateur comparé au mois précédent
        $kpi = $run('ventes', ['measures' => ['subtotal'], 'period' => 'this_month', 'compare' => 'previous_period', 'chart' => 'kpi'])->json('data');
        $this->assertEquals(380000, $kpi['totals']['subtotal']);
        $this->assertEquals(65595.7, $kpi['previous']['totals']['subtotal']);
        $this->assertSame('2026-05-01', $kpi['previous']['from']);
        $this->assertStringStartsWith('Ce mois-ci (du 01/06/2026 au 30/06/2026)', $kpi['period']['label']);

        // Articles : quantités et marge estimée (prix d'achat), avoir déduit
        $art = $run('ventes_articles', ['dimensions' => ['product'], 'measures' => ['quantity', 'subtotal', 'margin', 'margin_rate'], 'period' => 'all'])->json('data');
        $byName = collect($art['rows'])->keyBy(fn ($r) => $r['labels']['product']);
        $this->assertEquals(15, $byName['Riz parfumé 25 kg']['values']['quantity']); // 10 + 5 + 1 − 1
        $this->assertEquals(300000, $byName['Huile 20 L']['values']['subtotal']);
        $this->assertEquals(30000, $byName['Huile 20 L']['values']['margin']);
        $this->assertEquals(10, $byName['Huile 20 L']['values']['margin_rate']);

        // Jour de la semaine, devis transformés, tableau croisé
        $wd = $run('ventes', ['dimensions' => ['weekday'], 'measures' => ['count'], 'period' => 'all', 'sort' => ['by' => 'weekday', 'dir' => 'asc']])->json('data.rows');
        $this->assertSame('Vendredi', collect($wd)->firstWhere('keys.weekday', '5')['labels']['weekday']);
        $pipe = $run('ventes_pipeline', ['measures' => ['count', 'total', 'conversion'], 'period' => 'all'])->json('data.totals');
        $this->assertEquals(1, $pipe['count']);
        $this->assertEquals(0, $pipe['conversion']);
        $pv = $run('ventes', ['dimensions' => ['partner', 'month'], 'measures' => ['subtotal'], 'period' => 'this_year', 'chart' => 'pivot'])->assertOk()->json('data');
        $this->assertCount(4, $pv['rows']);

        // Comptabilité : produits = CA HT (écritures arrondies au franc)
        $this->assertEquals(645596, $run('compta', ['measures' => ['revenue'], 'period' => 'this_year'])->json('data.totals.revenue'));
        $cls = $run('compta', ['dimensions' => ['class'], 'measures' => ['debit', 'credit'], 'period' => 'this_year'])->json('data.rows');
        $this->assertContains('7 · Produits', array_map(fn ($r) => $r['labels']['class'], $cls));

        // Valeurs proposées pour les filtres
        $vals = $api()->getJson('/api/rapports/values?source=ventes&field=partner&q=baob')->assertOk()->json('data');
        $this->assertSame([['key' => $maquis, 'label' => 'Maquis Le Baobab']], $vals);
        $this->assertCount(3, $api()->getJson('/api/rapports/values?source=ventes&field=type')->json('data'));

        // Définitions invalides refusées
        $run('ventes', ['measures' => ['inconnue']])->assertStatus(422)->assertJsonPath('message', 'Mesure inconnue pour « Chiffre d\'affaires » : inconnue.');
        $run('ventes', ['dimensions' => ['partner'], 'measures' => ['subtotal'], 'chart' => 'pivot'])->assertStatus(422);
        $run('ventes', ['measures' => ['subtotal'], 'chart' => 'bar'])->assertStatus(422);
        $run('ventes', ['measures' => ['subtotal'], 'filters' => [['field' => 'd.id; DROP TABLE users', 'op' => 'eq', 'value' => 1]]])->assertStatus(422);
        $run('ventes', ['measures' => ['subtotal'], 'period' => 'custom', 'from' => '2026-05-01', 'to' => '2026-04-01'])->assertStatus(422);
        $run('inconnue', ['measures' => ['x']])->assertStatus(422);

        // Catalogue
        $meta = $api()->getJson('/api/rapports/meta')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(10, count($meta['sources']));
        $this->assertGreaterThanOrEqual(20, count($api()->getJson('/api/rapports/templates')->json('data')));
    }

    public function test_sharing_rights_sync_visibility_and_dashboards(): void
    {
        ['company' => $company, 'api' => $api, 'admin' => $admin] = $this->seedSales();
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $r) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@yao.ci', 'password' => 'secret123', 'role_id' => $role($r)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$ali, $capi] = $mk('Ali Commercial', 'Commercial');
        [, $lapi] = $mk('Lecteur', 'Lecture seule');
        [, $boss] = $mk('Directeur', 'Direction');

        // Données inaccessibles au commercial
        $capi()->postJson('/api/rapports/preview', ['source' => 'compta', 'config' => ['measures' => ['revenue']]])->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $capi()->postJson('/api/rapports/preview', ['source' => 'paie', 'config' => ['measures' => ['gross']]])->assertStatus(403);
        $sources = array_column($capi()->getJson('/api/rapports/meta')->json('data.sources'), 'key');
        $this->assertContains('ventes', $sources);
        $this->assertNotContains('compta', $sources);
        $this->assertNotContains('compta', array_column($capi()->getJson('/api/rapports/templates')->json('data'), 'source'));

        // Rapport privé du commercial ; partage refusé sans rapports.manage
        $mine = $this->uuid();
        $this->assertSame('applied', $this->push($capi, 'rp_reports', ['name' => 'Mes ventes', 'source' => 'ventes', 'config' => ['dimensions' => ['month'], 'measures' => ['total'], 'period' => 'this_year', 'chart' => 'bar']], $mine)['status']);
        $this->assertSame((int) $ali->id, Report::find($mine)->owner_id);
        $r = $this->push($capi, 'rp_reports', ['visibility' => 'company'], $mine);
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('réservé aux responsables', $r['message']);
        $this->assertSame('rejected', $this->push($capi, 'rp_reports', ['name' => 'X', 'source' => 'ventes', 'config' => ['measures' => ['nope']]])['status']);

        // Visibilité à la synchronisation
        $ids = fn ($a) => array_column($a()->getJson('/api/sync/pull?cursor=0')->json('changes.rp_reports.upserts') ?? [], 'id');
        $secret = $this->uuid();
        $this->push($api, 'rp_reports', ['name' => 'Marges confidentielles', 'source' => 'ventes_articles', 'config' => ['measures' => ['margin']]], $secret);
        $this->assertContains($mine, $ids($capi));
        $this->assertNotContains($secret, $ids($capi));
        $this->assertNotContains($mine, $ids($api)); // privé : même l'administrateur ne le voit pas
        $capi()->getJson("/api/rapports/reports/$secret/run")->assertStatus(403);
        $this->push($api, 'rp_reports', ['visibility' => 'roles', 'role_ids' => [$role('Commercial')]], $secret);
        $this->assertContains($secret, $ids($capi));
        $capi()->getJson("/api/rapports/reports/$secret/run")->assertOk();
        $this->assertNotContains($secret, $ids($lapi));
        $this->assertSame('rejected', $this->push($capi, 'rp_reports', ['name' => 'Piraté'], $secret)['status']);

        // Lecture seule : consulte les rapports partagés, ne crée rien
        $shared = $this->uuid();
        $this->push($api, 'rp_reports', ['name' => 'CA mensuel', 'source' => 'ventes', 'config' => ['dimensions' => ['month'], 'measures' => ['subtotal'], 'period' => 'this_year'], 'visibility' => 'company'], $shared);
        $lapi()->getJson("/api/rapports/reports/$shared/run")->assertOk();
        $this->assertSame('rejected', $this->push($lapi, 'rp_reports', ['name' => 'Le mien', 'source' => 'ventes', 'config' => ['measures' => ['total']]])['status']);
        $lapi()->postJson('/api/rapports/preview', ['source' => 'ventes', 'config' => ['measures' => ['total']]])->assertStatus(403);

        // Tableau de bord : un rapport inaccessible n'empêche pas les autres ; période imposée
        $compta = $this->uuid();
        $this->push($api, 'rp_reports', ['name' => 'Résultat', 'source' => 'compta', 'config' => ['measures' => ['result'], 'chart' => 'kpi'], 'visibility' => 'company'], $compta);
        $bid = $this->uuid();
        $r = $this->push($api, 'rp_dashboards', ['name' => 'Commerce', 'visibility' => 'company', 'widgets' => [
            ['id' => 'a', 'report_id' => $shared, 'w' => 9], ['id' => 'b', 'report_id' => $compta, 'w' => 1], ['id' => 'c', 'report_id' => $this->uuid()],
        ]], $bid);
        $this->assertSame('applied', $r['status']);
        $this->assertCount(2, Board::find($bid)->widgets);
        $this->assertSame(4, Board::find($bid)->widgets[0]['w']);
        $out = $capi()->getJson("/api/rapports/dashboards/$bid/run?period=last_month")->assertOk()->json('data.widgets');
        $this->assertTrue($out['a']['ok']);
        $this->assertSame('last_month', $out['a']['result']['period']['preset']);
        $this->assertEquals(65595.7, $out['a']['result']['totals']['subtotal']);
        $this->assertFalse($out['b']['ok']);
        $this->assertTrue($out['b']['forbidden']);
        $this->assertTrue($boss()->getJson("/api/rapports/dashboards/$bid/run")->json('data.widgets.b.ok'));
        $this->assertSame('rejected', $this->push($capi, 'rp_dashboards', ['name' => 'Renommé'], $bid)['status']);

        // Duplication dans ses rapports privés (copie d'un rapport partagé ou d'un modèle)
        $copy = $capi()->postJson('/api/rapports/duplicate', ['report_id' => $shared])->assertCreated()->json('data');
        $this->assertSame('CA mensuel (copie)', $copy['name']);
        $this->assertSame('private', $copy['visibility']);
        $capi()->postJson('/api/rapports/duplicate', ['template' => 'top_clients'])->assertCreated()->assertJsonPath('data.name', 'Top 10 clients');
        $capi()->postJson('/api/rapports/duplicate', ['template' => 'resultat_mensuel'])->assertStatus(403);

        // Suppression d'un rapport : retiré des tableaux de bord
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'rp_reports', 'op' => 'delete', 'record_id' => $compta]]])->assertOk();
        $this->assertSame(['a'], array_column(Board::find($bid)->widgets, 'id'));

        // Envois programmés réservés aux responsables
        $capi()->getJson('/api/rapports/schedules')->assertStatus(403);
    }

    public function test_exports_and_scheduled_emails(): void
    {
        ['company' => $company, 'api' => $api, 'admin' => $admin] = $this->seedSales();
        $rid = $this->uuid();
        $this->push($api, 'rp_reports', ['name' => 'CA par client', 'source' => 'ventes', 'config' => ['dimensions' => ['partner'], 'measures' => ['subtotal', 'count'], 'period' => 'this_year', 'chart' => 'hbar'], 'visibility' => 'company'], $rid);
        $pid = $this->uuid();
        $this->push($api, 'rp_reports', ['name' => 'Croisé', 'source' => 'ventes', 'config' => ['dimensions' => ['partner', 'month'], 'measures' => ['subtotal'], 'period' => 'this_year', 'chart' => 'pivot']], $pid);

        // Excel : lignes + total
        $res = $api()->get("/api/rapports/reports/$rid/export?format=xlsx")->assertOk();
        $this->assertStringContainsString('ca_par_client.xlsx', $res->headers->get('content-disposition'));
        [$headers, $rows] = Sheet::read($res->baseResponse->getFile()->getPathname());
        $this->assertSame(['Client', 'CA HT', 'Nombre de documents'], $headers);
        $this->assertSame('Total', end($rows)[0] ?? end($rows)['Client'] ?? null);
        // Tableau croisé : une colonne par mois
        [$ph] = Sheet::read($api()->get("/api/rapports/reports/$pid/export?format=xlsx")->baseResponse->getFile()->getPathname());
        $this->assertSame(['Client \\ Mois', 'avr. 2026', 'mai 2026', 'juin 2026', 'Total'], $ph);

        // PDF du rapport et du tableau de bord
        $pdf = $api()->get("/api/rapports/reports/$rid/export?format=pdf&period=last_month")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $board = Board::where('company_id', $company->id)->first();
        $this->assertStringStartsWith('%PDF', $api()->get("/api/rapports/dashboards/{$board->id}/export")->assertOk()->getContent());

        // Envoi programmé : sans serveur d'e-mail, échec signalé
        $s = $api()->postJson('/api/rapports/schedules', ['report_id' => $rid, 'frequency' => 'weekly', 'weekday' => 1, 'hour' => 7, 'recipients' => ['dg@yao.ci', 'DG@yao.ci', 'daf@yao.ci'], 'format' => 'xlsx'])->assertCreated()->json('data');
        $this->assertSame(['dg@yao.ci', 'daf@yao.ci'], $s['recipients']);
        $this->assertSame('2026-06-22 07:00:00', Schedule::find($s['id'])->next_run_at->toDateTimeString()); // lundi suivant
        $api()->postJson('/api/rapports/schedules', ['frequency' => 'daily', 'recipients' => ['a@b.ci']])->assertStatus(422);
        $api()->postJson("/api/rapports/schedules/{$s['id']}/send")->assertStatus(422)->assertJsonPath('message', 'Envoi impossible : Aucun serveur d\'e-mail configuré (Paramètres › Messagerie).');

        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.yao.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'erp@yao.ci', 'password' => 'x', 'from_email' => 'erp@yao.ci', 'from_name' => 'Négoce Yao',
        ]])->assertOk();

        // À l'heure dite, la tâche planifiée envoie (une seule fois) avec la pièce jointe
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-06-22 07:05'));
        $this->artisan('erp:reports-send')->assertSuccessful();
        $this->assertCount(2, $this->mails->messages());
        $mail = $this->mails->messages()->first()->getOriginalMessage();
        $this->assertSame('CA par client — Négoce Yao', $mail->getSubject());
        $this->assertStringContainsString('Hôtel Ivoire', $mail->getHtmlBody());
        $this->assertSame('ca_par_client.xlsx', $mail->getAttachments()[0]->getFilename());
        $fresh = Schedule::find($s['id']);
        $this->assertSame('sent', $fresh->last_status);
        $this->assertSame('2026-06-29 07:00:00', $fresh->next_run_at->toDateTimeString());
        $this->artisan('erp:reports-send')->assertSuccessful();
        $this->assertCount(2, $this->mails->messages());

        // Tableau de bord mensuel en PDF, période imposée
        $m = $api()->postJson('/api/rapports/schedules', ['dashboard_id' => $board->id, 'frequency' => 'monthly', 'monthday' => 1, 'hour' => 8, 'recipients' => ['dg@yao.ci'], 'period' => 'last_month'])->assertCreated()->json('data');
        $this->assertSame('2026-07-01 08:00:00', Schedule::find($m['id'])->next_run_at->toDateTimeString());
        $api()->postJson("/api/rapports/schedules/{$m['id']}/send")->assertOk()->assertJsonPath('data.last_status', 'sent');
        $last = $this->mails->messages()->last()->getOriginalMessage();
        $this->assertSame('application/pdf', $last->getAttachments()[0]->getMediaType().'/'.$last->getAttachments()[0]->getMediaSubtype());

        // Désactivation, modification, suppression ; l'auteur désactivé bloque l'envoi
        $api()->putJson("/api/rapports/schedules/{$s['id']}", ['active' => false])->assertOk()->assertJsonPath('data.next_run_at', null);
        $this->assertCount(2, $api()->getJson('/api/rapports/schedules')->json('data'));
        $admin->forceFill(['is_active' => false])->save();
        $this->assertFalse(app(\App\Modules\Rapports\RapportsService::class)->send($company, Schedule::find($m['id'])));
        $this->assertStringContainsString('n\'a plus accès', Schedule::find($m['id'])->last_error);
        $admin->forceFill(['is_active' => true])->save();
        $api()->deleteJson("/api/rapports/schedules/{$m['id']}")->assertNoContent();

        // Supprimer le rapport supprime ses envois
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'rp_reports', 'op' => 'delete', 'record_id' => $rid]]])->assertOk();
        $this->assertSame(0, Schedule::count());
    }

    /** Chaque modèle et chaque regroupement de chaque source s'exécute (SQL valide). */
    public function test_every_template_source_and_dimension_runs(): void
    {
        ['api' => $api] = $this->seedSales();
        foreach ($api()->getJson('/api/rapports/templates')->json('data') as $t) {
            $api()->postJson('/api/rapports/preview', ['source' => $t['source'], 'config' => $t['config']])->assertOk();
        }
        foreach ($api()->getJson('/api/rapports/meta')->json('data.sources') as $src) {
            $measures = array_slice(array_column($src['measures'], 'key'), 0, 6);
            foreach ($src['dimensions'] as $d) {
                $r = $api()->postJson('/api/rapports/preview', ['source' => $src['key'], 'config' => ['dimensions' => [$d['key']], 'measures' => $measures, 'period' => 'all',
                    'filters' => [['field' => $d['key'], 'op' => 'not_empty']]]]);
                $this->assertSame(200, $r->status(), $src['key'].'.'.$d['key'].' : '.$r->getContent());
                $api()->getJson('/api/rapports/values?source='.$src['key'].'&field='.$d['key'])->assertOk();
            }
        }
    }

    public function test_module_disabled_in_saas_plan(): void
    {
        config(['erp.mode' => 'saas']);
        [, , $token] = $this->makeCompany('Petite Boutique', 'starter');
        $this->api($token)->getJson('/api/rapports/meta')->assertStatus(403);
        $this->assertNotContains('rp_reports', $this->api($token)->getJson('/api/sync/pull?cursor=0')->json('entities'));
    }
}
