<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class RhDocumentsTest extends TestCase
{
    public function test_hr_documents_templates_self_service_requests_issue_and_lock(): void
    {
        [$company, , $token] = $this->makeCompany('Imprimerie Akwaba');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@akwaba.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$kofi, $kofiApi] = $mk('Kofi', 'Salarié');
        [$rh, $rhApi] = $mk('Nadia', 'RH / Paie');
        $push = fn ($client, array $mut) => $client()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => 'upsert'], $mut)])->assertOk()->json('results');
        $pull = fn ($client) => $client()->getJson('/api/sync/pull?cursor=0')->assertOk()->json('changes');

        $rhApi()->putJson('/api/rh/documents/settings', ['rh_signatory' => 'Aya Kouamé', 'rh_signatory_title' => 'Directrice générale'])->assertOk();
        $eKofi = $this->uuid();
        $eAwa = $this->uuid();
        $push($rhApi, [
            ['entity' => 'rh_employees', 'record_id' => $eKofi, 'data' => ['first_name' => 'Kofi', 'last_name' => 'Yao', 'gender' => 'M', 'birth_date' => '1994-03-01', 'job_title' => 'Infographiste', 'hire_date' => '2023-09-01', 'contract_type' => 'CDI', 'base_salary' => 250000, 'user_id' => $kofi->id]],
            ['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'gender' => 'F', 'hire_date' => '2024-01-15']],
        ]);

        // Modèles : la RH les voit tous, le salarié seulement ceux en libre-service
        $tpls = collect($pull($rhApi)['rh_doc_templates']['upserts'])->keyBy('name');
        $this->assertCount(6, $tpls);
        $this->assertSame(['Attestation de travail'], array_column($pull($kofiApi)['rh_doc_templates']['upserts'], 'name'));
        $this->assertSame('rejected', $push($rhApi, [['entity' => 'rh_doc_templates', 'record_id' => $tpls['Contrat de travail à durée indéterminée']['id'], 'data' => ['self_service' => true]]])[0]['status']);

        // Fiche ou société incomplète (RCCM manquant) : pas d'attestation à trous, la demande part à la RH
        $inc = $push($kofiApi, [['entity' => 'rh_documents', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'template_id' => $tpls['Attestation de travail']['id'], 'type' => 'attestation', 'title' => 'x', 'date' => now()->toDateString()]]])[0];
        $this->assertSame('requested', $inc['record']['status']);
        $this->assertStringContainsString('fiche incomplète', $inc['record']['note']);
        $company->forceFill(['trade_register' => 'CI-ABJ-2018-B-1234', 'address' => 'Treichville, rue 12', 'city' => 'Abidjan'])->save();

        // Attestation en libre-service : texte établi par le serveur (le texte envoyé par l'appareil est ignoré)
        $r = $push($kofiApi, [['entity' => 'rh_documents', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'template_id' => $tpls['Attestation de travail']['id'], 'type' => 'attestation', 'title' => 'x', 'date' => now()->toDateString(), 'body' => 'Salaire : 9 000 000 F']]])[0];
        $this->assertSame(['applied', 'issued'], [$r['status'], $r['record']['status']]);
        $this->assertStringStartsWith('ATT', $r['record']['number']);
        $body = $r['record']['body'];
        $this->assertStringContainsString('Aya Kouamé, directrice générale de la société Imprimerie Akwaba, sise Treichville, rue 12, Abidjan, RCCM CI-ABJ-2018-B-1234', $body);
        $this->assertStringContainsString('Monsieur Yao Kofi, né le 1er mars 1994', $body);
        $this->assertStringContainsString('est employé au sein de notre entreprise depuis le 1er septembre 2023, en qualité de Infographiste, sous contrat de travail à durée indéterminée (CDI)', $body);
        $this->assertStringNotContainsString('9 000 000', $body);

        // Certificat demandé à la RH ; contrat ou document pour un collègue : refusés
        $req = $push($kofiApi, [['entity' => 'rh_documents', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'type' => 'certificate', 'title' => 'Certificat', 'date' => now()->toDateString(), 'note' => 'Pour dossier de prêt bancaire']]])[0];
        $this->assertSame(['requested', null], [$req['record']['status'], $req['record']['number']]);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $rh->id)->where('title', 'Demande de document')->exists());
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'rh_documents', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'type' => 'contract', 'title' => 'Contrat', 'date' => now()->toDateString()]]])[0]['status']);
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'rh_documents', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'type' => 'attestation', 'title' => 'x', 'date' => now()->toDateString()]]])[0]['status']);
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'rh_documents', 'record_id' => $req['record']['id'], 'data' => ['status' => 'issued', 'body' => 'faux']]])[0]['status']);

        // La RH établit le document demandé → numéro, salarié prévenu ; un brouillon reste invisible pour lui
        $r = $push($rhApi, [['entity' => 'rh_documents', 'record_id' => $req['record']['id'], 'data' => ['status' => 'issued', 'body' => 'Certificat de travail de M. Yao Kofi…']]])[0];
        $this->assertSame('issued', $r['record']['status']);
        $this->assertStringStartsWith('CT', $r['record']['number']);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $kofi->id)->where('title', 'Document disponible')->exists());
        $draft = $this->uuid();
        $push($rhApi, [['entity' => 'rh_documents', 'record_id' => $draft, 'data' => ['employee_id' => $eKofi, 'type' => 'warning', 'title' => 'Avertissement', 'date' => now()->toDateString(), 'body' => 'Retards répétés', 'status' => 'draft']]]);
        $mine = collect($pull($kofiApi)['rh_documents']['upserts']);
        $this->assertCount(3, $mine);
        $this->assertNotContains($draft, $mine->pluck('id'));
        $this->assertContains($draft, collect($pull($rhApi)['rh_documents']['upserts'])->pluck('id')->all());

        // Document émis : figé (annulation possible, ni modification ni suppression) ; solde de tout compte avec ses lignes
        $this->assertSame('rejected', $push($rhApi, [['entity' => 'rh_documents', 'record_id' => $r['record']['id'], 'data' => ['body' => 'modifié']]])[0]['status']);
        $this->assertSame('rejected', $rhApi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'rh_documents', 'op' => 'delete', 'record_id' => $r['record']['id']]]])->json('results.0.status'));
        $this->assertSame('cancelled', $push($rhApi, [['entity' => 'rh_documents', 'record_id' => $r['record']['id'], 'data' => ['status' => 'cancelled', 'note' => 'Erreur de date']]])[0]['record']['status']);
        $stc = $push($rhApi, [['entity' => 'rh_documents', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'type' => 'stc', 'title' => 'Reçu pour solde de tout compte', 'date' => now()->toDateString(), 'body' => 'Je soussignée…',
            'data' => ['lines' => [['label' => 'Salaire du mois au prorata', 'amount' => 80000], ['label' => 'Indemnité compensatrice de congés payés', 'amount' => 45000]], 'total' => 125000]]]])[0];
        $this->assertSame(['issued', 125000], [$stc['record']['status'], $stc['record']['data']['total']]);
        $this->assertStringStartsWith('STC', $stc['record']['number']);
        // Un document vide ne s'émet pas
        $this->assertSame('rejected', $push($rhApi, [['entity' => 'rh_documents', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'type' => 'other', 'title' => 'Vide', 'date' => now()->toDateString()]]])[0]['status']);
    }
}
