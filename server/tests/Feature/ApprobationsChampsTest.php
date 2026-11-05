<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Approbations\Models\ApprovalRequest;
use App\Modules\Approbations\Models\Decision;
use Tests\TestCase;

class ApprobationsChampsTest extends TestCase
{
    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Distribution Bamba');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $role = fn (string $n) => (int) Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $r) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@bamba.ci', 'password' => 'secret123', 'role_id' => $role($r)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        $push = fn ($client, string $entity, array $data, ?string $id = null) => $client()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');

        return compact('company', 'admin', 'api', 'role', 'mk', 'push');
    }

    private function doc(callable $push, $client, string $entity, array $data, ?string $id = null): array
    {
        $id ??= $this->uuid();
        $r = $push($client, $entity, $data, $id);
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $r['record'] + ['id' => $id];
    }

    public function test_quote_discount_circuit_auto_validation_rejection_resubmission_and_implicit_approval(): void
    {
        ['company' => $company, 'admin' => $admin, 'api' => $api, 'role' => $role, 'mk' => $mk, 'push' => $push] = $this->env();
        [$ali, $aliApi] = $mk('Ali Commercial', 'Commercial');
        [$dg, $dgApi] = $mk('Awa Directrice', 'Direction');
        [, $buyerApi] = $mk('Bintou Acheteuse', 'Acheteur');
        $client = $api()->postJson('/api/core/partners', ['name' => 'Hôtel du Lac', 'type' => 'customer'])->json('data.id');

        // Circuit : devis avec une remise de plus de 15 % → accord de la Direction
        $bad = $push($api, 'wf_rules', ['name' => 'Remises', 'targets' => ['sale.quote'], 'steps' => [['name' => 'Direction']]]);
        $this->assertSame('rejected', $bad['status']);
        $this->assertStringContainsString('au moins un rôle', $bad['message']);
        $this->assertSame('rejected', $push($api, 'wf_rules', ['name' => 'X', 'targets' => ['sale.nope'], 'steps' => [['roles' => [$role('Direction')]]]])['status']);
        $rule = $this->uuid();
        $this->assertSame('applied', $push($api, 'wf_rules', ['name' => 'Remises importantes', 'targets' => ['sale.quote'], 'discount_over' => 15,
            'steps' => [['name' => 'Direction', 'roles' => [$role('Direction'), $role('Administrateur')]]]], $rule)['status']);
        $this->assertContains($rule, array_column($aliApi()->getJson('/api/sync/pull?cursor=0')->json('changes.wf_rules.upserts') ?? [], 'id'));

        $line = fn ($disc) => [['description' => 'Pack boissons', 'quantity' => 10, 'unit_price' => 50000, 'discount_percent' => $disc, 'tax_rate' => 18]];

        // Remise de 10 % : validé tout de suite
        $q1 = $this->doc($push, $aliApi, 'sales_documents', ['type' => 'quote', 'partner_id' => $client, 'date' => now()->toDateString(), 'lines' => $line(10), 'status' => 'validated']);
        $this->assertSame('validated', $q1['status']);

        // Remise de 20 % : reste en brouillon et part en approbation (même saisi hors ligne)
        $q2 = $this->doc($push, $aliApi, 'sales_documents', ['type' => 'quote', 'partner_id' => $client, 'date' => now()->toDateString(), 'lines' => $line(20), 'status' => 'validated']);
        $this->assertSame('draft', $q2['status']);
        $req = ApprovalRequest::where('record_id', $q2['id'])->firstOrFail();
        $this->assertSame('pending', $req->status);
        $this->assertSame('sale.quote', $req->target);
        $this->assertEquals(400000, $req->amount);
        $this->assertEquals(20, $req->discount);
        $this->assertStringContainsString('remise 20 %', $req->label);
        $this->assertTrue(AppNotification::where('user_id', $dg->id)->where('title', 'like', 'À approuver%')->exists());

        // Le commercial ne décide pas ; l'acheteur ne voit pas la demande ; la directrice l'a dans sa boîte
        $aliApi()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved'])->assertStatus(422)->assertJsonPath('message', 'Vous ne pouvez pas approuver votre propre demande.');
        $buyerApi()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved'])->assertStatus(422);
        $this->assertSame([], $buyerApi()->getJson('/api/sync/pull?cursor=0')->json('changes.wf_requests.upserts') ?? []);
        $mine = $aliApi()->getJson('/api/sync/pull?cursor=0')->json('changes.wf_requests.upserts');
        $this->assertSame($req->id, $mine[0]['id']);
        $this->assertEqualsCanonicalizing([(int) $dg->id, (int) $admin->id], $mine[0]['current_approver_ids']);
        $this->assertSame('rejected', $push($aliApi, 'wf_requests', ['status' => 'approved'], $req->id)['status']);
        $inbox = $dgApi()->getJson('/api/approbations/inbox')->assertOk()->json('data');
        $this->assertCount(1, $inbox);
        $this->assertSame('Ali Commercial', $inbox[0]['requester_name']);

        // Accord : le devis est validé automatiquement et numéroté
        $dgApi()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved', 'comment' => 'OK client stratégique'])->assertOk()->assertJsonPath('data.status', 'approved');
        $doc = \App\Modules\Ventes\Models\SalesDocument::find($q2['id']);
        $this->assertSame('validated', $doc->status);
        $this->assertStringStartsWith('DEV', $doc->number);
        $this->assertTrue(AppNotification::where('user_id', $ali->id)->where('title', 'like', 'Demande approuvée et document validé%')->exists());
        $dgApi()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved'])->assertStatus(422);

        // Remise relevée pendant l'attente : la demande repart ; refus motivé ; nouvelle demande
        $q3 = $this->doc($push, $aliApi, 'sales_documents', ['type' => 'quote', 'partner_id' => $client, 'date' => now()->toDateString(), 'lines' => $line(18), 'status' => 'validated']);
        $first = ApprovalRequest::where('record_id', $q3['id'])->firstOrFail();
        $this->doc($push, $aliApi, 'sales_documents', ['lines' => $line(25), 'status' => 'validated'], $q3['id']);
        $this->assertSame('cancelled', $first->fresh()->status);
        $second = ApprovalRequest::where('record_id', $q3['id'])->where('status', 'pending')->firstOrFail();
        $this->assertEquals(25, $second->discount);
        $dgApi()->postJson("/api/approbations/requests/{$second->id}/decide", ['decision' => 'rejected'])->assertStatus(422)->assertJsonPath('message', 'Indiquez le motif du refus.');
        $dgApi()->postJson("/api/approbations/requests/{$second->id}/decide", ['decision' => 'rejected', 'comment' => '25 % c\'est trop : 15 % maximum'])->assertOk();
        $this->assertSame('draft', \App\Modules\Ventes\Models\SalesDocument::find($q3['id'])->status);
        $this->assertTrue(AppNotification::where('user_id', $ali->id)->where('body', 'like', '%15 % maximum%')->exists());
        $this->doc($push, $aliApi, 'sales_documents', ['lines' => $line(15), 'status' => 'validated'], $q3['id']);
        $this->assertSame('validated', \App\Modules\Ventes\Models\SalesDocument::find($q3['id'])->status); // 15 % : plus de circuit

        // Annulation par le demandeur
        $q4 = $this->doc($push, $aliApi, 'sales_documents', ['type' => 'quote', 'partner_id' => $client, 'date' => now()->toDateString(), 'lines' => $line(30), 'status' => 'validated']);
        $r4 = ApprovalRequest::where('record_id', $q4['id'])->firstOrFail();
        $buyerApi()->postJson("/api/approbations/requests/{$r4->id}/cancel")->assertStatus(422);
        $aliApi()->postJson("/api/approbations/requests/{$r4->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Un approbateur du circuit valide son propre devis remisé : accord implicite, sans attente
        $q5 = $this->doc($push, $api, 'sales_documents', ['type' => 'quote', 'partner_id' => $client, 'date' => now()->toDateString(), 'lines' => $line(30), 'status' => 'validated']);
        $this->assertSame('validated', $q5['status']);
        $this->assertSame('implicit', Decision::where('request_id', ApprovalRequest::where('record_id', $q5['id'])->value('id'))->value('decision'));

        // Historique (responsable) et filtres
        $all = $dgApi()->getJson('/api/approbations/requests')->assertOk()->json('data');
        $this->assertCount(5, $all);
        $this->assertCount(1, $aliApi()->getJson('/api/approbations/requests?status=rejected&mine=1')->json('data'));
        $hist = $dgApi()->getJson("/api/approbations/requests/{$req->id}")->json('data.decisions');
        $this->assertSame(['submitted', 'approved'], array_column($hist, 'decision'));
        $this->assertSame('OK client stratégique', $hist[1]['comment']);
    }

    public function test_multi_level_purchase_order_all_mode_delegation_escalation_reminders(): void
    {
        ['company' => $company, 'api' => $api, 'role' => $role, 'mk' => $mk, 'push' => $push] = $this->env();
        [$buyer, $buyerApi] = $mk('Bintou Acheteuse', 'Acheteur');
        [$compta, $comptaApi] = $mk('Paul Comptable', 'Comptable');
        [$dg1, $dg1Api] = $mk('Awa Directrice', 'Direction');
        [$dg2, $dg2Api] = $mk('Yao Directeur', 'Direction');
        $supplier = $api()->postJson('/api/core/partners', ['name' => 'Solibra', 'type' => 'supplier'])->json('data.id');

        $push($api, 'wf_rules', ['name' => 'Achats importants', 'targets' => ['purchase.purchase_order'], 'min_amount' => 1000000, 'reminder_hours' => 24, 'steps' => [
            ['name' => 'Contrôle comptable', 'roles' => [$role('Comptable')], 'escalate_after_hours' => 4, 'escalate_roles' => [$role('Direction')]],
            ['name' => 'Double signature direction', 'mode' => 'all', 'roles' => [$role('Direction')]],
        ]]);
        $line = fn ($p) => [['description' => 'Casiers de bière', 'quantity' => 100, 'unit_price' => $p, 'tax_rate' => 18]];
        $small = $this->doc($push, $buyerApi, 'purchase_documents', ['type' => 'purchase_order', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'lines' => $line(9000), 'status' => 'validated']);
        $this->assertSame('validated', $small['status']); // 900 000 HT : sous le seuil
        $po = $this->doc($push, $buyerApi, 'purchase_documents', ['type' => 'purchase_order', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'lines' => $line(15000), 'status' => 'validated']);
        $this->assertSame('draft', $po['status']);
        $req = ApprovalRequest::where('record_id', $po['id'])->firstOrFail();
        $this->assertSame([(int) $compta->id], app(\App\Modules\Approbations\ApprovalService::class)->actors($req));
        $dg1Api()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved'])->assertStatus(422);

        // Rappel après 24 h ; escalade à la Direction après 4 h sans réponse
        $this->travel(5)->hours();
        $this->artisan('erp:approvals')->assertSuccessful();
        $req->refresh();
        $this->assertNotNull($req->escalated_at);
        $this->assertTrue(AppNotification::where('user_id', $dg1->id)->where('title', 'like', 'Escalade%')->exists());
        $this->assertContains((int) $dg1->id, app(\App\Modules\Approbations\ApprovalService::class)->actors($req));
        $this->travel(20)->hours();
        $this->artisan('erp:approvals')->assertSuccessful();
        $this->assertTrue(AppNotification::where('user_id', $compta->id)->where('title', 'like', 'Rappel%')->exists());

        // Étape 1 : le comptable accorde ; étape 2 : les deux directeurs doivent signer
        $comptaApi()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.current_step', 1);
        $this->assertTrue(AppNotification::where('user_id', $dg2->id)->where('title', 'like', 'À approuver%')->exists());
        $dg1Api()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'pending');
        $dg1Api()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved'])->assertStatus(422);
        $this->assertSame('draft', \App\Modules\Achats\Models\PurchaseDocument::find($po['id'])->status);

        // Yao est absent : il délègue au comptable, qui signe pour lui
        $comptaApi()->postJson('/api/approbations/delegations', ['user_id' => $dg2->id, 'delegate_id' => $compta->id, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(7)->toDateString()])->assertStatus(403);
        $dg2Api()->postJson('/api/approbations/delegations', ['delegate_id' => $dg2->id, 'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString()])->assertStatus(422);
        $dg2Api()->postJson('/api/approbations/delegations', ['delegate_id' => $compta->id, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(7)->toDateString(), 'note' => 'Congés'])
            ->assertCreated()->assertJsonPath('data.active', true);
        $this->assertCount(1, $comptaApi()->getJson('/api/approbations/inbox')->json('data'));
        $comptaApi()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved', 'comment' => 'Pour Yao (congés)'])->assertOk()->assertJsonPath('data.status', 'approved');
        $last = Decision::where('request_id', $req->id)->where('decision', 'approved')->latest('id')->first();
        $this->assertSame((int) $compta->id, $last->user_id);
        $this->assertSame((int) $dg2->id, $last->on_behalf_of);
        $doc = \App\Modules\Achats\Models\PurchaseDocument::find($po['id']);
        $this->assertSame('validated', $doc->status);
        $this->assertStringStartsWith('BCF', $doc->number);
        $hist = $buyerApi()->getJson("/api/approbations/requests/{$req->id}")->assertOk()->json('data.decisions');
        $this->assertSame('Yao Directeur', end($hist)['on_behalf_of']);

        // Délégations : liste et suppression
        $this->assertCount(1, $dg2Api()->getJson('/api/approbations/delegations')->json('data'));
        $id = $dg2Api()->getJson('/api/approbations/delegations')->json('data.0.id');
        $buyerApi()->deleteJson("/api/approbations/delegations/$id")->assertStatus(403);
        $dg2Api()->deleteJson("/api/approbations/delegations/$id")->assertNoContent();
    }

    public function test_expense_circuit_blocks_direct_decision_and_approves_through_workflow(): void
    {
        ['company' => $company, 'api' => $api, 'role' => $role, 'mk' => $mk, 'push' => $push] = $this->env();
        [$kofi, $kofiApi] = $mk('Kofi Salarie', 'Salarié');
        [, $paulApi] = $mk('Paul Comptable', 'Comptable');
        [$dg, $dgApi] = $mk('Awa Directrice', 'Direction');
        $emp = $this->uuid();
        $push($api, 'rh_employees', ['first_name' => 'Kofi', 'last_name' => 'Yao', 'base_salary' => 200000, 'hire_date' => '2025-01-01', 'user_id' => $kofi->id], $emp);
        $cats = collect($kofiApi()->getJson('/api/sync/pull?cursor=0')->json('changes.nf_categories.upserts'))->keyBy('name');
        $taxi = $cats['Transport, taxi, VTC']['id'];
        $push($api, 'wf_rules', ['name' => 'Gros frais', 'targets' => ['expense'], 'min_amount' => 50000, 'steps' => [['name' => 'Direction', 'roles' => [$role('Direction')]]]]);

        $small = $this->doc($push, $kofiApi, 'nf_expenses', ['employee_id' => $emp, 'category_id' => $taxi, 'date' => now()->toDateString(), 'amount' => 8000, 'description' => 'Taxi', 'status' => 'submitted']);
        $this->assertSame(0, ApprovalRequest::where('record_id', $small['id'])->count());
        $big = $this->doc($push, $kofiApi, 'nf_expenses', ['employee_id' => $emp, 'category_id' => $taxi, 'date' => now()->toDateString(), 'amount' => 85000, 'description' => 'Location véhicule mission Yamoussoukro', 'status' => 'submitted']);
        $req = ApprovalRequest::where('record_id', $big['id'])->firstOrFail();
        $this->assertSame('expense', $req->target);

        // Décision directe refusée : la note suit le circuit
        $r = $push($paulApi, 'nf_expenses', ['status' => 'approved'], $big['id']);
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('circuit d\'approbation « Gros frais »', $r['message']);
        $this->assertSame('applied', $push($paulApi, 'nf_expenses', ['status' => 'approved'], $small['id'])['status']); // hors circuit : inchangé

        // Accord dans « Approbations » : la note devient approuvée (comptabilisée)
        $dgApi()->postJson("/api/approbations/requests/{$req->id}/decide", ['decision' => 'approved'])->assertOk();
        $e = \App\Modules\NotesFrais\Models\Expense::find($big['id']);
        $this->assertSame('approved', $e->status);
        $this->assertSame((int) $dg->id, (int) $e->decided_by);

        // Refus dans le circuit : motif repris sur la note
        $x = $this->doc($push, $kofiApi, 'nf_expenses', ['employee_id' => $emp, 'category_id' => $taxi, 'date' => now()->toDateString(), 'amount' => 60000, 'description' => 'Taxi longue distance', 'status' => 'submitted']);
        $rx = ApprovalRequest::where('record_id', $x['id'])->firstOrFail();
        $dgApi()->postJson("/api/approbations/requests/{$rx->id}/decide", ['decision' => 'rejected', 'comment' => 'Prendre le car STC'])->assertOk();
        $ex = \App\Modules\NotesFrais\Models\Expense::find($x['id']);
        $this->assertSame('rejected', $ex->status);
        $this->assertSame('Prendre le car STC', $ex->decision_note);

        // Renvoyée puis reprise en brouillon par le salarié : la demande est annulée
        $push($kofiApi, 'nf_expenses', ['amount' => 55000], $x['id']);
        $push($kofiApi, 'nf_expenses', ['status' => 'submitted'], $x['id']);
        $again = ApprovalRequest::where('record_id', $x['id'])->where('status', 'pending')->firstOrFail();
        $push($kofiApi, 'nf_expenses', ['status' => 'draft'], $x['id']);
        $this->assertSame('cancelled', $again->fresh()->status);
    }

    public function test_custom_fields_definitions_values_required_defaults_reports_and_documents(): void
    {
        ['company' => $company, 'api' => $api, 'mk' => $mk, 'push' => $push] = $this->env();
        [, $aliApi] = $mk('Ali Commercial', 'Commercial');
        $field = fn (array $d) => $push($api, 'cf_fields', $d);

        // Définitions : clé générée, liste exigeant des choix, type figé
        $sect = $this->uuid();
        $this->assertSame('applied', $push($api, 'cf_fields', ['entity' => 'partners', 'label' => 'Secteur d\'activité', 'type' => 'select', 'options' => ['Hôtellerie', 'Restauration', 'Commerce', ' ', 'Commerce'], 'required' => true, 'default_value' => 'Commerce', 'show_in_list' => true], $sect)['status']);
        $f = \App\Modules\Champs\Models\Field::find($sect);
        $this->assertSame('secteur_dactivite', $f->key);
        $this->assertSame(['Hôtellerie', 'Restauration', 'Commerce'], $f->options);
        $this->assertSame('rejected', $field(['entity' => 'partners', 'label' => 'Zone', 'type' => 'select'])['status']);
        $this->assertSame('rejected', $push($api, 'cf_fields', ['type' => 'text'], $sect)['status']);
        $this->assertSame('rejected', $field(['entity' => 'factures', 'label' => 'X', 'type' => 'text'])['status']);
        $this->assertSame('rejected', $push($aliApi, 'cf_fields', ['entity' => 'partners', 'label' => 'Y', 'type' => 'text'])['status']); // droit champs.manage
        $field(['entity' => 'partners', 'label' => 'Contribuable vérifié', 'type' => 'checkbox']);
        $field(['entity' => 'partners', 'label' => 'Date du premier contrat', 'type' => 'date']);
        $field(['entity' => 'products', 'label' => 'Garantie (mois)', 'type' => 'number']);
        $field(['entity' => 'sales_documents', 'label' => 'N° de bon de commande client', 'type' => 'text', 'show_on_pdf' => true]);
        $field(['entity' => 'crm_leads', 'label' => 'Budget annoncé', 'type' => 'money', 'required' => true]);
        $field(['entity' => 'rh_employees', 'label' => 'Taille de tenue', 'type' => 'select', 'options' => ['S', 'M', 'L', 'XL']]);
        $this->assertSame('applied', $field(['entity' => 'partners', 'label' => 'Secteur d\'activité', 'type' => 'text'])['status']);
        $this->assertTrue(\App\Modules\Champs\Models\Field::where('key', 'secteur_dactivite_2')->exists()); // clé rendue unique
        $this->assertCount(8, $aliApi()->getJson('/api/sync/pull?cursor=0')->json('changes.cf_fields.upserts'));

        // Valeurs : contrôle des types, clés inconnues ignorées, défaut à la création
        $p1 = $api()->postJson('/api/core/partners', ['name' => 'Hôtel Ivoire', 'type' => 'customer', 'custom' => ['secteur_dactivite' => 'Hôtellerie', 'contribuable_verifie' => 'oui', 'date_du_premier_contrat' => '2024-03-15', 'pirate' => 'x']])->assertCreated()->json('data');
        $this->assertEquals(['secteur_dactivite' => 'Hôtellerie', 'contribuable_verifie' => true, 'date_du_premier_contrat' => '2024-03-15'], $p1['custom']);
        $api()->postJson('/api/core/partners', ['name' => 'Maquis X', 'type' => 'customer', 'custom' => ['secteur_dactivite' => 'Banque']])->assertStatus(422);
        $api()->postJson('/api/core/partners', ['name' => 'Maquis Y', 'type' => 'customer', 'custom' => ['date_du_premier_contrat' => 'pas une date']])->assertStatus(422);
        $p2 = $api()->postJson('/api/core/partners', ['name' => 'Supérette Bonheur', 'type' => 'customer'])->assertCreated()->json('data');
        $this->assertSame('Commerce', $p2['custom']['secteur_dactivite']);
        $p3 = $this->doc($push, $aliApi, 'partners', ['name' => 'Maquis Le Baobab', 'type' => 'customer', 'custom' => ['secteur_dactivite' => 'Restauration']]);

        // Mise à jour partielle : les autres valeurs restent ; une valeur vide efface ; champ obligatoire vidé refusé
        $this->doc($push, $aliApi, 'partners', ['custom' => ['contribuable_verifie' => false]], $p1['id']);
        $this->assertEquals(['secteur_dactivite' => 'Hôtellerie', 'contribuable_verifie' => false, 'date_du_premier_contrat' => '2024-03-15'], \App\Modules\Core\Models\Partner::find($p1['id'])->custom);
        $this->doc($push, $aliApi, 'partners', ['custom' => ['date_du_premier_contrat' => null]], $p1['id']);
        $this->assertArrayNotHasKey('date_du_premier_contrat', \App\Modules\Core\Models\Partner::find($p1['id'])->custom);
        $r = $push($aliApi, 'partners', ['custom' => ['secteur_dactivite' => '']], $p1['id']);
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('Champ obligatoire : « Secteur d\'activité ».', $r['message']);

        // Champ obligatoire sans défaut ; montant saisi « à la française »
        $this->assertSame('rejected', $push($aliApi, 'crm_leads', ['title' => 'Équipement hôtel'])['status']);
        $lead = $this->doc($push, $aliApi, 'crm_leads', ['title' => 'Équipement hôtel', 'custom' => ['budget_annonce' => '1 500 000,50']]);
        $this->assertSame(1500000.5, \App\Modules\Crm\Models\Lead::find($lead['id'])->custom['budget_annonce']);

        // Champ désactivé : la valeur est conservée
        $push($api, 'cf_fields', ['active' => false], \App\Modules\Champs\Models\Field::where('key', 'contribuable_verifie')->value('id'));
        $this->doc($push, $aliApi, 'partners', ['name' => 'Hôtel Ivoire SA'], $p1['id']);
        $this->doc($push, $aliApi, 'partners', ['custom' => ['secteur_dactivite' => 'Hôtellerie']], $p1['id']);
        $this->assertFalse(\App\Modules\Core\Models\Partner::find($p1['id'])->custom['contribuable_verifie']);

        // Documents : champ modifiable même après validation
        $riz = $api()->postJson('/api/core/products', ['name' => 'Riz 25 kg', 'sale_price' => 20000, 'purchase_price' => 15000, 'tax_rate' => 0, 'custom' => ['garantie_mois' => 0]])->json('data.id');
        $huile = $api()->postJson('/api/core/products', ['name' => 'Congélateur', 'sale_price' => 300000, 'purchase_price' => 220000, 'tax_rate' => 0, 'custom' => ['garantie_mois' => 24]])->json('data.id');
        $inv = $this->doc($push, $aliApi, 'sales_documents', ['type' => 'invoice', 'partner_id' => $p1['id'], 'date' => now()->toDateString(), 'status' => 'validated', 'custom' => ['n_de_bon_de_commande_client' => 'BC-HI-2026-118'],
            'lines' => [['product_id' => $riz, 'description' => 'Riz', 'quantity' => 10, 'unit_price' => 20000, 'tax_rate' => 0], ['product_id' => $huile, 'description' => 'Congélateur', 'quantity' => 2, 'unit_price' => 300000, 'tax_rate' => 0]]]);
        $this->assertSame('validated', $inv['status']);
        $this->doc($push, $aliApi, 'sales_documents', ['custom' => ['n_de_bon_de_commande_client' => 'BC-HI-2026-118 bis']], $inv['id']);
        $this->assertSame('BC-HI-2026-118 bis', \App\Modules\Ventes\Models\SalesDocument::find($inv['id'])->custom['n_de_bon_de_commande_client']);
        $this->doc($push, $aliApi, 'sales_documents', ['type' => 'invoice', 'partner_id' => $p3['id'], 'date' => now()->toDateString(), 'status' => 'validated',
            'lines' => [['product_id' => $riz, 'description' => 'Riz', 'quantity' => 5, 'unit_price' => 20000, 'tax_rate' => 0]]]);

        // Rapports : regroupement par secteur (champ du client), filtre, somme d'un champ numérique
        $meta = collect($api()->getJson('/api/rapports/meta')->json('data.sources'))->keyBy('key');
        $dims = collect($meta['ventes']['dimensions'])->keyBy('key');
        $this->assertSame('Clients & fournisseurs › Secteur d\'activité', $dims['cf_p_secteur_dactivite']['label']);
        $this->assertArrayHasKey('cf_d_n_de_bon_de_commande_client', $dims->all());
        $this->assertContains('cf_pr_garantie_mois', array_column($meta['ventes_articles']['measures'], 'key'));
        $res = $api()->postJson('/api/rapports/preview', ['source' => 'ventes', 'config' => ['dimensions' => ['cf_p_secteur_dactivite'], 'measures' => ['subtotal'], 'period' => 'all']])->assertOk()->json('data');
        $by = collect($res['rows'])->mapWithKeys(fn ($r) => [$r['labels']['cf_p_secteur_dactivite'] => $r['values']['subtotal']]);
        $this->assertEquals(['Hôtellerie' => 800000, 'Restauration' => 100000], $by->all());
        $f = $api()->postJson('/api/rapports/preview', ['source' => 'ventes', 'config' => ['measures' => ['subtotal'], 'period' => 'all', 'filters' => [['field' => 'cf_p_secteur_dactivite', 'op' => 'eq', 'value' => 'Restauration']]]])->json('data');
        $this->assertEquals(100000, $f['totals']['subtotal']);
        $this->assertSame('Clients & fournisseurs › Secteur d\'activité = Restauration', $f['filters_label']);
        $g = $api()->postJson('/api/rapports/preview', ['source' => 'ventes_articles', 'config' => ['dimensions' => ['product'], 'measures' => ['quantity', 'cf_pr_garantie_mois_avg'], 'period' => 'all']])->assertOk()->json('data');
        $this->assertEquals(8, $g['totals']['cf_pr_garantie_mois_avg']); // moyenne par ligne vendue : (0 + 24 + 0) / 3
    }

    public function test_modules_disabled_in_saas_plan(): void
    {
        config(['erp.mode' => 'saas']);
        [, , $token] = $this->makeCompany('Petite Boutique', 'starter');
        $ents = $this->api($token)->getJson('/api/sync/pull?cursor=0')->json('entities');
        $this->assertNotContains('wf_rules', $ents);
        $this->assertNotContains('cf_fields', $ents);
        $p = $this->api($token)->postJson('/api/core/partners', ['name' => 'Client', 'type' => 'customer', 'custom' => ['x' => 1]])->assertCreated()->json('data');
        $this->assertSame(['x' => 1], $p['custom']); // module inactif : rien n'est retiré ni contrôlé
    }
}
