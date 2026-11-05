<?php

namespace App\Modules\Hebergement;

use App\Http\Controllers\Controller;
use App\Modules\Hebergement\Models\HostedService;
use Illuminate\Http\Request;

class HebergementController extends Controller
{
    public function __construct(private HostingService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function stats()
    {
        return ['data' => $this->svc->stats(app('tenant'))];
    }

    public function history(string $id)
    {
        return ['data' => $this->svc->history(HostedService::withTrashed()->findOrFail($id))];
    }

    /** Facture de renouvellement immédiate (un service, ou plusieurs du même client). */
    public function renew(Request $r)
    {
        $v = $r->validate(['ids' => 'required|array|min:1|max:50', 'ids.*' => 'uuid', 'send' => 'boolean']);

        return $this->guard(function () use ($v) {
            $services = HostedService::whereIn('id', $v['ids'])->get()->all();
            if (count($services) !== count(array_unique($v['ids']))) {
                throw new \DomainException('Service introuvable.');
            }
            $doc = $this->svc->invoice(app('tenant'), $services);
            $sent = ($v['send'] ?? false) ? $this->svc->remind(app('tenant'), $services[0], $doc, $services) : false;

            return response()->json(['data' => $doc->fresh('lines')->toSyncArray(), 'sent' => $sent, 'services' => HostedService::whereIn('id', $v['ids'])->get()->map->toSyncArray()], 201);
        });
    }

    public function extend(Request $r, string $id)
    {
        $v = $r->validate(['until' => 'nullable|date', 'provider_done' => 'boolean']);

        return $this->guard(function () use ($v, $id) {
            $s = HostedService::findOrFail($id);
            $this->svc->extend($s, $v['until'] ?? null, $v['provider_done'] ?? true);

            return ['data' => $s->fresh()->toSyncArray()];
        });
    }

    public function providerDone(string $id)
    {
        return $this->guard(function () use ($id) {
            $s = HostedService::findOrFail($id);
            $this->svc->providerDone($s);

            return ['data' => $s->fresh()->toSyncArray()];
        });
    }

    public function remind(string $id)
    {
        $s = HostedService::findOrFail($id);
        $p = $s->pendingRenewal();
        $doc = $p?->document_id ? \App\Modules\Ventes\Models\SalesDocument::find($p->document_id) : null;
        $ok = $this->svc->remind(app('tenant'), $s, $doc, [$s], $s->daysLeft());

        return $ok ? ['data' => ['sent' => true]] : response()->json(['message' => 'Aucun canal d\'envoi disponible (e-mail ou WhatsApp du client et canaux configurés).'], 422);
    }

    public function settings()
    {
        return ['data' => $this->svc->settings(app('tenant'))];
    }

    public function updateSettings(Request $r)
    {
        $v = $r->validate(['reminder_days' => 'array|max:6', 'reminder_days.*' => 'integer|min:0|max:120', 'auto_send' => 'boolean']);
        $c = app('tenant');
        $s = $c->settings ?? [];
        if (isset($v['reminder_days'])) {
            $s['hosting_reminder_days'] = array_values(array_unique(array_map('intval', $v['reminder_days'])));
        }
        if (array_key_exists('auto_send', $v)) {
            $s['hosting_auto_send'] = $v['auto_send'];
        }
        $c->forceFill(['settings' => $s])->save();

        return $this->settings();
    }

    public function run()
    {
        return ['data' => $this->svc->runDaily(app('tenant'))];
    }
}
