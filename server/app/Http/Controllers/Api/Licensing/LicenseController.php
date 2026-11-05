<?php

namespace App\Http\Controllers\Api\Licensing;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LandingController;
use App\Licensing\LicenseIssuer;
use App\Models\ActivityLog;
use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** « Mes licences » : le client génère et gère les licences de ses installations (plateforme SaaS). */
class LicenseController extends Controller
{
    public function __construct(protected LicenseIssuer $issuer)
    {
        abort_unless(config('erp.mode') === 'saas', 404);
    }

    protected function mine(int $id): License
    {
        return License::where('owner_company_id', app('tenant')->id)->findOrFail($id);
    }

    public function index()
    {
        $rows = License::where('owner_company_id', app('tenant')->id)->latest('id')->get();
        $list = $rows->map(fn ($l) => $this->issuer->present($l))->values();

        return [
            'data' => $list,
            'stats' => [
                'total' => $rows->count(),
                'active' => $rows->where('status', 'active')->count(),
                'online' => $rows->filter->isOnline()->count(),
                'unused' => $rows->where('status', 'unused')->count(),
                'modules' => $list->sum('modules_count'),
            ],
            'targets' => License::TARGETS,
            'server_zip' => LandingController::serverZip(),
            'server' => rtrim(config('app.url'), '/'),
            'can_manage' => request()->user()->hasPermission('core.settings.manage'),
        ];
    }

    public function show(int $id)
    {
        return ['data' => $this->issuer->present($this->mine($id), true)];
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'company_name' => 'required|string|max:190',
            'target' => ['required', Rule::in(array_keys(License::TARGETS))],
            'notes' => 'nullable|string|max:1000',
        ]);
        try {
            $l = $this->issuer->create(app('tenant'), $r->user(), $d['company_name'], $d['target'], $d['notes'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        ActivityLog::record('license.created', $l, ['target' => $l->target]);

        return response()->json(['data' => $this->issuer->present($l, true)], 201);
    }

    public function update(Request $r, int $id)
    {
        $l = $this->mine($id);
        $d = $r->validate(['company_name' => 'sometimes|string|max:190', 'notes' => 'nullable|string|max:1000']);
        $l->update($d);
        if (isset($d['company_name'])) {
            $l->company?->update(['name' => $d['company_name']]);
        }
        $l->log('renamed', $d);

        return ['data' => $this->issuer->present($l->fresh(), true)];
    }

    /** Libère l'appareil : la clé pourra être activée sur un autre ordinateur / serveur. */
    public function reset(int $id)
    {
        $l = $this->mine($id);
        if ($l->status === 'revoked') {
            return response()->json(['message' => 'Licence révoquée.'], 422);
        }
        $l->log('reset', ['device' => $l->device_name]);
        $l->update(['fingerprint' => null, 'device_name' => null, 'resets' => $l->resets + 1]);

        return ['data' => $this->issuer->present($l->fresh(), true)];
    }

    public function revoke(int $id)
    {
        $l = $this->mine($id);
        $l->update(['status' => 'revoked']);
        $l->log('revoked', ['by' => request()->user()->name]);
        ActivityLog::record('license.revoked', $l);

        return ['data' => $this->issuer->present($l->fresh(), true)];
    }
}
