<?php

namespace App\Modules\Portail;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Partner;
use App\Modules\Portail\Models\PortalAccess;
use App\Modules\Portail\Models\PortalEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** API de l'application : invitations, accès, activité et messages du portail. */
class PortailController extends Controller
{
    public function __construct(protected PortalService $portal) {}

    protected function accessPayload(?PortalAccess $a): ?array
    {
        return $a ? [
            'active' => $a->active, 'url' => $a->active ? $a->url() : null, 'invited_at' => $a->invited_at, 'invited_via' => $a->invited_via,
            'last_login_at' => $a->last_login_at, 'login_count' => $a->login_count,
        ] : null;
    }

    public function show(string $partner)
    {
        $p = Partner::findOrFail($partner);
        $a = PortalAccess::where('partner_id', $p->id)->first();

        return [
            'access' => $this->accessPayload($a),
            'channels' => $this->portal->channels(app('tenant'), $p),
            'events' => PortalEvent::where('partner_id', $p->id)->latest('id')->limit(60)->get()->map->toApi(),
        ];
    }

    public function invite(Request $request, string $partner)
    {
        $p = Partner::findOrFail($partner);
        $channel = $request->validate(['channel' => 'nullable|in:email,whatsapp,sms,none'])['channel'] ?? null;
        $r = $this->portal->invite(app('tenant'), $p, $channel);

        return ['access' => $this->accessPayload($r['access']), 'url' => $r['url'], 'sent_via' => $r['sent_via']];
    }

    /** Coupe l'accès : l'ancien lien ne fonctionne plus (un nouveau sera créé à la prochaine invitation). */
    public function revoke(string $partner)
    {
        $p = Partner::findOrFail($partner);
        $a = PortalAccess::where('partner_id', $p->id)->firstOrFail();
        $a->forceFill(['active' => false, 'token' => Str::random(48)])->save();

        return ['access' => $this->accessPayload($a)];
    }

    public function reply(Request $request, string $partner)
    {
        $p = Partner::findOrFail($partner);
        $body = $request->validate(['body' => 'required|string|max:5000'])['body'];
        $e = $this->portal->reply(app('tenant'), $p, $body, $request->user());

        return ['data' => $e->toApi()];
    }

    /** Boîte de réception : derniers messages et réponses aux devis, tous clients confondus. */
    public function inbox()
    {
        $events = PortalEvent::whereIn('type', ['message', 'quote_accepted', 'quote_refused'])->latest('id')->limit(100)->get();
        $names = Partner::whereIn('id', $events->pluck('partner_id')->unique())->pluck('name', 'id');

        return [
            'data' => $events->map(fn ($e) => $e->toApi() + ['partner_id' => $e->partner_id, 'partner_name' => $names[$e->partner_id] ?? '']),
            'unread' => PortalEvent::where('type', 'message')->whereNull('read_at')->count(),
            'accesses' => PortalAccess::where('active', true)->count(),
            'active_30d' => PortalAccess::where('active', true)->where('last_login_at', '>', now()->subDays(30))->count(),
        ];
    }

    public function read(string $partner)
    {
        PortalEvent::where('partner_id', $partner)->whereNull('read_at')->update(['read_at' => now()]);

        return ['ok' => true];
    }
}
