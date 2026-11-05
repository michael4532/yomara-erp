<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Assistant IA : outils de lecture, boucle d'appels (Anthropic et compatible OpenAI), quota, rédaction, conversations. */
class IaTest extends TestCase
{
    protected function invoice(string $token, string $partner, float $ht, string $date, bool $pay = false): string
    {
        $doc = $this->uuid();
        $muts = [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $doc, 'data' => ['type' => 'invoice', 'partner_id' => $partner, 'date' => $date, 'due_date' => $date, 'status' => 'validated',
            'lines' => [['id' => $this->uuid(), 'description' => 'Ciment CPJ 45', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 18, 'discount_percent' => 0]]]]];
        if ($pay) {
            $muts[] = ['id' => $this->uuid(), 'entity' => 'sales_payments', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['document_id' => $doc, 'date' => $date, 'amount' => $ht * 1.18, 'method' => 'cash']];
        }
        $this->api($token)->postJson('/api/sync/push', ['mutations' => $muts])->assertOk();

        return $doc;
    }

    public function test_assistant_answers_with_tools_drafts_and_respects_quota(): void
    {
        config(['erp.ai.api_key' => 'sk-plateforme', 'erp.ai.provider' => 'anthropic', 'erp.ai.monthly_limit' => 3]);
        [$company, , $token] = $this->makeCompany('Quincaillerie Bamba');
        $this->api($token)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $p = $this->api($token)->postJson('/api/core/partners', ['name' => 'BTP Konan', 'type' => 'customer', 'phone' => '0707070707'])->json('data.id');
        $this->invoice($token, $p, 1000000, now()->startOfMonth()->toDateString(), true);
        $this->invoice($token, $p, 500000, now()->subDays(40)->toDateString());

        $s = $this->api($token)->getJson('/api/ia/status')->assertOk()->json('data');
        $this->assertTrue($s['configured']);
        $this->assertFalse($s['own_key']);
        $this->assertSame(3, $s['monthly_limit']);

        // Anthropic : le modèle appelle ventes_resume puis répond
        $seen = [];
        $mode = 'chat';
        Http::fake(['api.anthropic.com/*' => function (Request $req) use (&$seen, &$mode) {
            $seen[] = $req->data();
            if ($mode === 'draft') {
                return Http::response(['content' => [['type' => 'text', 'text' => 'Objet : Facture en attente de règlement'."\n\nBonjour,"]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]);
            }
            $last = end($req->data()['messages']);
            if (is_array($last['content']) && ($last['content'][0]['type'] ?? '') === 'tool_result') {
                $res = json_decode($last['content'][0]['content'], true);

                return Http::response(['content' => [['type' => 'text', 'text' => 'Ce mois-ci : '.number_format($res['ca_ht'], 0, ',', ' ').' F CFA HT.']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 50, 'output_tokens' => 20]]);
            }

            return Http::response(['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'ventes_resume', 'input' => ['du' => now()->startOfMonth()->toDateString()]]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]]);
        }]);
        $r = $this->api($token)->postJson('/api/ia/ask', ['message' => 'Combien avons-nous vendu ce mois-ci ?'])->assertOk()->json('data');
        $this->assertSame('Ce mois-ci : 1 000 000 F CFA HT.', $r['answer']);
        $this->assertSame(['ventes_resume'], $r['tools']);
        Http::assertSent(fn (Request $req) => $req->hasHeader('x-api-key', 'sk-plateforme') && $req['model'] === 'claude-haiku-5-5' && collect($req['tools'])->pluck('name')->contains('factures_impayees'));
        $this->assertStringContainsString('Quincaillerie Bamba', $seen[0]['system']);

        // Suite de la conversation : l'historique est renvoyé
        $conv = $r['conversation_id'];
        $this->api($token)->postJson('/api/ia/ask', ['message' => 'Et en TTC ?', 'conversation_id' => $conv])->assertOk();
        $this->assertCount(3, $seen[2]['messages']); // question, réponse, nouvelle question
        $this->assertCount(4, $this->api($token)->getJson("/api/ia/conversations/$conv")->json('data.messages'));

        // Quota de la plateforme : 3 demandes par mois
        $mode = 'draft';
        $num = \Illuminate\Support\Facades\DB::table('trade_documents')->where('payment_status', 'unpaid')->value('number');
        $d = $this->api($token)->postJson('/api/ia/draft', ['kind' => 'relance', 'document' => $num, 'tone' => 'ferme', 'channel' => 'email'])->assertOk()->json('data');
        $this->assertStringStartsWith('Objet :', $d['text']);
        Http::assertSent(fn (Request $req) => str_contains($req['messages'][0]['content'], $num) && str_contains($req['messages'][0]['content'], 'BTP Konan'));
        $this->api($token)->postJson('/api/ia/ask', ['message' => 'Encore une ?'])->assertStatus(422)->assertJsonFragment(['message' => 'Quota mensuel de l\'assistant atteint (3 demandes) : il se renouvelle le 1er du mois.']);

        // Clé propre à la société, API compatible OpenAI : sans quota ; l'outil des impayés est appelé
        $this->api($token)->putJson('/api/ia/settings', ['provider' => 'openai', 'model' => 'mistral-small-latest', 'base_url' => 'https://api.mistral.ai/v1', 'api_key' => 'sk-societe-1234', 'monthly_limit' => 0])->assertOk()
            ->assertJsonPath('data.own_key', true)->assertJsonPath('data.key_hint', '••••1234');
        $fail = false;
        Http::fake(['api.mistral.ai/*' => function (Request $req) use (&$fail) {
            if ($fail) {
                return Http::response(['error' => ['message' => 'invalid key']], 401);
            }
            $msgs = $req->data()['messages'];
            $last = end($msgs);
            if ($last['role'] === 'tool') {
                $res = json_decode($last['content'], true);

                return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $res['nombre'].' facture impayée : '.$res['factures'][0]['client'].', '.$res['factures'][0]['du'].' F.']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5]]);
            }

            return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'factures_impayees', 'arguments' => '{"en_retard_seulement": true}']]]]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5]]);
        }]);
        $r = $this->api($token)->postJson('/api/ia/ask', ['message' => 'Qui nous doit de l\'argent ?'])->assertOk()->json('data');
        $this->assertSame('1 facture impayée : BTP Konan, 590000 F.', $r['answer']);
        Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer sk-societe-1234') && $req['model'] === 'mistral-small-latest');

        // Erreur du fournisseur : message clair
        $fail = true;
        $this->api($token)->postJson('/api/ia/ask', ['message' => 'Test'])->assertStatus(422)->assertJsonFragment(['message' => 'Clé du fournisseur d\'IA refusée : vérifiez-la dans les paramètres de l\'assistant.']);
    }

    public function test_tools_follow_user_permissions(): void
    {
        config(['erp.ai.api_key' => 'sk-plateforme']);
        [$company, , $token] = $this->makeCompany('Boutique Awa');
        $roles = collect($this->api($token)->getJson('/api/roles')->json('data'))->keyBy('name');
        // Rôle limité : assistant + caisse, sans accès aux ventes ni à la trésorerie
        $role = $this->api($token)->postJson('/api/roles', ['name' => 'Caisse + IA', 'permissions' => ['pos.use', 'ia.use']])->assertCreated()->json('data.id');
        $this->api($token)->postJson('/api/users', ['name' => 'Awa', 'email' => 'awa@boutique.ci', 'password' => 'caisse123', 'role_id' => $role])->assertCreated();
        $awa = \App\Models\User::where('email', 'awa@boutique.ci')->first()->createToken('t')->plainTextToken;
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);
        $this->api($awa)->postJson('/api/ia/ask', ['message' => 'Solde des caisses ?'])->assertOk();
        Http::assertSent(function (Request $req) {
            $names = collect($req['tools'] ?? [])->pluck('name')->all();

            return in_array('ventes_resume', $names, true) && ! in_array('tresorerie_soldes', $names, true) && ! in_array('factures_impayees', $names, true);
        });
        // Paramètres réservés
        $this->api($awa)->putJson('/api/ia/settings', ['api_key' => 'x'])->assertForbidden();
        // Outil non autorisé demandé par le modèle : refusé
        $tools = new \App\Modules\Ia\AiTools($company, \App\Models\User::where('email', 'awa@boutique.ci')->first());
        $this->expectException(\DomainException::class);
        $tools->run('tresorerie_soldes', []);
    }
}
