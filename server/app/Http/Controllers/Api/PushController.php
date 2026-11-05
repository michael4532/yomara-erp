<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Push;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Jetons Firebase des appareils de l'utilisateur connecté. */
class PushController extends Controller
{
    public function store(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|min:20|max:4096', 'platform' => 'nullable|in:android,ios,macos,web']);
        $u = $r->user();
        $hash = hash('sha256', $d['token']);
        // Un appareil appartient au dernier utilisateur connecté dessus
        DB::table('push_tokens')->updateOrInsert(['token_hash' => $hash], [
            'user_id' => $u->id, 'company_id' => $u->company_id, 'token' => $d['token'],
            'platform' => $d['platform'] ?? 'android', 'last_seen_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);

        return ['ok' => true, 'enabled' => Push::enabled()];
    }

    public function destroy(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|max:4096']);
        DB::table('push_tokens')->where('token_hash', hash('sha256', $d['token']))->where('user_id', $r->user()->id)->delete();

        return response()->noContent();
    }

    /** « Tester les notifications » : envoie une notification à ses propres appareils. */
    public function test(Request $r)
    {
        abort_unless(Push::enabled(), 422, 'Les notifications push ne sont pas configurées sur ce serveur (clé Firebase manquante).');
        $n = DB::table('push_tokens')->where('user_id', $r->user()->id)->count();
        abort_if($n === 0, 422, 'Aucun téléphone enregistré : ouvrez l\'application mobile et acceptez les notifications.');
        $sent = Push::toUsers([$r->user()->id], 'Notifications activées', 'Vous recevrez ici les alertes de '.config('erp.name', 'Yomara ERP').'.', ['type' => 'test']);

        return ['devices' => $n, 'sent' => $sent];
    }
}
