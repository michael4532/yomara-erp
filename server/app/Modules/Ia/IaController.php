<?php

namespace App\Modules\Ia;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IaController extends Controller
{
    public function __construct(protected AiService $ai) {}

    protected function domain(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function status()
    {
        return ['data' => $this->ai->publicSettings(app('tenant')) + ['suggestions' => [
            'Combien avons-nous vendu ce mois-ci par rapport au mois dernier ?',
            'Quelles sont les 5 factures impayées les plus en retard ?',
            'Quels articles sont en rupture ou sous le stock minimum ?',
            'Quels sont nos 10 meilleurs clients cette année ?',
            'Quel est le solde de nos caisses et comptes ?',
            'Quelles ont été nos plus grosses dépenses ce trimestre ?',
        ]]];
    }

    public function settings(Request $r)
    {
        $v = $r->validate(['provider' => 'nullable|in:anthropic,openai', 'model' => 'nullable|string|max:100', 'base_url' => 'nullable|url|max:255',
            'api_key' => 'sometimes|nullable|string|max:400', 'monthly_limit' => 'nullable|integer|min:0|max:100000', 'enabled' => 'sometimes|boolean']);

        return ['data' => $this->ai->saveSettings(app('tenant'), $v)];
    }

    public function ask(Request $r)
    {
        $v = $r->validate(['message' => 'required|string|max:2000', 'conversation_id' => 'nullable|integer']);

        return $this->domain(fn () => ['data' => $this->ai->ask(app('tenant'), $r->user(), $v['message'], $v['conversation_id'] ?? null)]);
    }

    public function draft(Request $r)
    {
        $v = $r->validate(['kind' => 'required|in:'.implode(',', array_keys(AiService::DRAFTS)), 'instructions' => 'nullable|string|max:2000', 'document' => 'nullable|string|max:60',
            'tone' => 'nullable|in:cordial,formel,ferme,chaleureux', 'channel' => 'nullable|in:email,whatsapp,sms']);

        return $this->domain(fn () => ['data' => $this->ai->draft(app('tenant'), $r->user(), $v['kind'], (string) ($v['instructions'] ?? ''), $v['document'] ?? null, $v['tone'] ?? 'cordial', $v['channel'] ?? 'email')]);
    }

    public function conversations(Request $r)
    {
        return ['data' => DB::table('ia_conversations')->where('company_id', app('tenant')->id)->where('user_id', $r->user()->id)->orderByDesc('updated_at')->limit(50)->get(['id', 'title', 'updated_at'])];
    }

    public function conversation(Request $r, int $id)
    {
        $c = DB::table('ia_conversations')->where('company_id', app('tenant')->id)->where('user_id', $r->user()->id)->where('id', $id)->first();
        abort_unless($c, 404);

        return ['data' => ['id' => $c->id, 'title' => $c->title, 'messages' => DB::table('ia_messages')->where('conversation_id', $id)->orderBy('id')->get(['role', 'content', 'tools', 'created_at'])
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content, 'tools' => $m->role === 'assistant' ? array_column(json_decode($m->tools ?: '[]', true) ?: [], 'name') : [], 'at' => $m->created_at])]];
    }

    public function deleteConversation(Request $r, int $id)
    {
        $c = DB::table('ia_conversations')->where('company_id', app('tenant')->id)->where('user_id', $r->user()->id)->where('id', $id);
        abort_unless((clone $c)->exists(), 404);
        DB::table('ia_messages')->where('conversation_id', $id)->delete();
        $c->delete();

        return ['ok' => true];
    }
}
