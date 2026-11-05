<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class CrmTest extends TestCase
{
    protected string $token;

    protected array $stages;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->token] = $this->makeCompany('Agence Digitale');
        // Les étapes sont créées au premier usage du module (ici : démarrage de l'app)
        $this->api($this->token)->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['crm_leads']);
        $this->stages = collect($this->api($this->token)->getJson('/api/crm/stages')->assertOk()->json('data'))->keyBy('name')->all();
    }

    protected function lead(array $data): array
    {
        return $this->api($this->token)->postJson('/api/crm/leads', $data)->assertCreated()->json('data');
    }

    protected function move(string $id, string $stage, array $extra = []): array
    {
        return $this->api($this->token)->putJson("/api/crm/leads/$id", ['stage_id' => $this->stages[$stage]['id']] + $extra)->assertOk()->json('data');
    }

    public function test_default_pipeline_and_stage_rules(): void
    {
        $this->assertSame(['Nouveau', 'Qualifié', 'Proposition envoyée', 'Négociation', 'Gagné', 'Perdu'], array_keys($this->stages));
        // Idempotent : un second démarrage ne duplique rien
        $this->api($this->token)->getJson('/api/bootstrap')->assertOk();
        $this->assertCount(6, $this->api($this->token)->getJson('/api/crm/stages')->json('data'));

        $l = $this->lead(['title' => 'Site e-commerce Boutique Awa', 'company_name' => 'Boutique Awa', 'expected_revenue' => 1500000, 'source' => 'referral']);
        $this->assertSame($this->stages['Nouveau']['id'], $l['stage_id']);
        $this->assertSame(10, $l['probability']);
        $this->assertSame('open', $l['status']);
        $this->assertNotNull($l['owner_id']);

        $l = $this->move($l['id'], 'Négociation');
        $this->assertSame(75, $l['probability']);

        // Probabilité ajustée à la main sans changer d'étape : conservée
        $l = $this->api($this->token)->putJson("/api/crm/leads/{$l['id']}", ['probability' => 90])->assertOk()->json('data');
        $this->assertSame(90, $l['probability']);

        $l = $this->move($l['id'], 'Perdu', ['lost_reason' => 'Budget insuffisant']);
        $this->assertSame('lost', $l['status']);
        $this->assertNotNull($l['lost_at']);
        $this->assertSame('Budget insuffisant', $l['lost_reason']);

        $l = $this->move($l['id'], 'Qualifié');
        $this->assertSame('open', $l['status']);
        $this->assertNull($l['lost_at']);
        $this->assertNull($l['lost_reason']);

        $l = $this->move($l['id'], 'Gagné');
        $this->assertSame('won', $l['status']);
        $this->assertSame(100, $l['probability']);
        $this->assertNotNull($l['won_at']);

        // Une étape qui contient des opportunités ne se supprime pas
        $this->api($this->token)->deleteJson('/api/crm/stages/'.$this->stages['Gagné']['id'])->assertStatus(422);
        $this->api($this->token)->deleteJson('/api/crm/stages/'.$this->stages['Perdu']['id'])->assertStatus(422); // dernière étape « perdu »
    }

    public function test_offline_lead_and_activity_through_sync(): void
    {
        $leadId = $this->uuid();
        $actId = $this->uuid();
        $res = $this->api($this->token)->postJson('/api/sync/push', ['mutations' => [
            ['id' => $this->uuid(), 'entity' => 'crm_leads', 'op' => 'upsert', 'record_id' => $leadId, 'data' => [
                'title' => 'Application mobile Pharmacie du Plateau', 'contact_name' => 'Dr Yao', 'phone' => '0707070707',
                'stage_id' => $this->stages['Qualifié']['id'], 'expected_revenue' => 3000000, 'expected_close_date' => now()->addMonth()->toDateString(),
            ]],
            ['id' => $this->uuid(), 'entity' => 'crm_activities', 'op' => 'upsert', 'record_id' => $actId, 'data' => [
                'lead_id' => $leadId, 'type' => 'meeting', 'subject' => 'Présentation de la maquette', 'due_at' => now()->subDay()->toIso8601String(),
            ]],
        ]])->assertOk()->json('results');
        $this->assertSame(['applied', 'applied'], array_column($res, 'status'), json_encode($res));
        $this->assertSame(25, $res[0]['record']['probability']);

        $pull = $this->api($this->token)->getJson('/api/sync/pull?cursor=0')->assertOk()->json('changes');
        $this->assertCount(6, $pull['crm_stages']['upserts']);
        $this->assertSame($leadId, $pull['crm_leads']['upserts'][0]['id']);
        $this->assertFalse($pull['crm_activities']['upserts'][0]['done']);

        // Relance en retard visible dans les indicateurs, puis marquée faite
        $this->assertSame(1, $this->api($this->token)->getJson('/api/crm/stats')->json('kpi.late_activities'));
        $a = $this->api($this->token)->putJson("/api/crm/activities/$actId", ['done' => true])->assertOk()->json('data');
        $this->assertNotNull($a['done_at']);
        $this->assertSame(0, $this->api($this->token)->getJson('/api/crm/stats')->json('kpi.late_activities'));

        // Activité liée à une opportunité d'une autre société : refusée
        [, , $other] = $this->makeCompany('Concurrent');
        $this->api($other)->getJson('/api/bootstrap');
        $r = $this->api($other)->postJson('/api/crm/activities', ['lead_id' => $leadId, 'subject' => 'Espionnage'])->assertStatus(422);
    }

    public function test_quote_moves_pipeline_and_invoice_wins_the_deal(): void
    {
        $partner = $this->api($this->token)->postJson('/api/core/partners', ['name' => 'Hôtel Ivoire Lagune', 'type' => 'customer'])->json('data.id');
        $lead = $this->lead(['title' => 'Refonte site + réservation en ligne', 'partner_id' => $partner]);

        $quote = $this->api($this->token)->postJson('/api/ventes/documents', [
            'type' => 'quote', 'partner_id' => $partner, 'date' => now()->toDateString(),
            'lines' => [['description' => 'Site vitrine et module de réservation', 'quantity' => 1, 'unit_price' => 2500000, 'tax_rate' => 18]],
        ])->assertCreated()->json('data');
        $this->api($this->token)->putJson("/api/crm/leads/{$lead['id']}", ['quote_id' => $quote['id']])->assertOk();
        $lead = $this->api($this->token)->getJson("/api/crm/leads/{$lead['id']}")->json('data');
        $this->assertSame($quote['id'], $lead['quote_id']);

        $this->api($this->token)->postJson("/api/ventes/documents/{$quote['id']}/validate")->assertOk();
        $lead = $this->api($this->token)->getJson("/api/crm/leads/{$lead['id']}")->json('data');
        $this->assertSame($this->stages['Proposition envoyée']['id'], $lead['stage_id']);
        $this->assertEquals(2500000, $lead['expected_revenue']); // repris du devis (HT)

        $inv = $this->api($this->token)->postJson("/api/ventes/documents/{$quote['id']}/convert", ['type' => 'invoice'])->assertCreated()->json('data');
        $this->api($this->token)->postJson("/api/ventes/documents/{$inv['id']}/validate")->assertOk();
        $lead = $this->api($this->token)->getJson("/api/crm/leads/{$lead['id']}")->json('data');
        $this->assertSame('won', $lead['status']);
    }

    public function test_stats_and_permissions(): void
    {
        $a = $this->lead(['title' => 'A', 'expected_revenue' => 1000000, 'source' => 'website', 'expected_close_date' => now()->addMonth()->toDateString()]);
        $this->move($a['id'], 'Proposition envoyée'); // 50 % → 500 000 pondéré
        $this->lead(['title' => 'B', 'expected_revenue' => 400000, 'source' => 'website']); // 10 % → 40 000
        $c = $this->lead(['title' => 'C', 'expected_revenue' => 600000, 'source' => 'social']);
        $this->move($c['id'], 'Gagné');
        $d = $this->lead(['title' => 'D', 'expected_revenue' => 200000]);
        $this->move($d['id'], 'Perdu', ['lost_reason' => 'Prix']);

        $s = $this->api($this->token)->getJson('/api/crm/stats')->assertOk()->json();
        $this->assertSame(2, $s['kpi']['open_count']);
        $this->assertEquals(1400000, $s['kpi']['open_amount']);
        $this->assertEquals(540000, $s['kpi']['weighted']);
        $this->assertEquals(600000, $s['kpi']['won_amount']);
        $this->assertEquals(50, $s['kpi']['win_rate']);
        $this->assertSame(4, $s['kpi']['new_count']);
        $pipe = collect($s['pipeline'])->keyBy('name');
        $this->assertSame(1, $pipe['Proposition envoyée']['count']);
        $this->assertEquals(500000, $pipe['Proposition envoyée']['weighted']);
        $this->assertSame(1, $pipe['Gagné']['count']);
        $this->assertSame('Site web', $s['by_source'][0]['label']);
        $this->assertSame('Prix', $s['lost_reasons'][0]['label']);
        $this->assertSame(now()->addMonth()->format('Y-m'), $s['forecast'][0]['month']);

        $dash = $this->api($this->token)->getJson('/api/dashboard')->assertOk()->json();
        $this->assertStringContainsString('crm_weighted', json_encode($dash));

        // Un caissier n'a pas accès au CRM ; un commercial oui
        $roles = collect($this->api($this->token)->getJson('/api/roles')->json('data'))->keyBy('name');
        foreach (['Caissier' => 403, 'Commercial' => 200] as $role => $code) {
            $email = strtolower($role).'@agence.ci';
            $this->api($this->token)->postJson('/api/users', ['name' => $role, 'email' => $email, 'password' => 'secret123', 'role_id' => $roles[$role]['id']])->assertCreated();
            $t = User::where('email', $email)->first()->createToken('t')->plainTextToken;
            $this->api($t)->getJson('/api/crm/leads')->assertStatus($code);
        }
        $this->assertCount(3, $this->api($this->token)->getJson('/api/crm/users')->json('users'));
    }
}
