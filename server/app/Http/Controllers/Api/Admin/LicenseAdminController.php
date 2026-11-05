<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Licensing\LicenseIssuer;
use App\Models\ActivityLog;
use App\Models\License;
use App\Models\ModuleOrder;
use Illuminate\Http\Request;

/** Super admin : toutes les installations sous licence. */
class LicenseAdminController extends Controller
{
    public function __construct(protected LicenseIssuer $issuer) {}

    public function index(Request $r)
    {
        $all = License::with('ownerCompany:id,name')->latest('id')->get();
        $rows = $all;
        if ($s = $r->query('status')) {
            $rows = $s === 'online' ? $rows->filter->isOnline() : $rows->where('status', $s);
        }
        if ($t = $r->query('target')) {
            $rows = $rows->where('target', $t);
        }
        if ($q = trim((string) $r->query('q'))) {
            $q = mb_strtolower($q);
            $rows = $rows->filter(fn ($l) => str_contains(mb_strtolower($l->company_name.' '.$l->key.' '.$l->device_name.' '.$l->ownerCompany?->name.' '.$l->install_url), $q));
        }
        $companyIds = $all->pluck('company_id');
        $paid = ModuleOrder::whereIn('company_id', $companyIds)->where('status', 'paid');

        return [
            'signing_ready' => \App\Licensing\LicenseSigner::ready(),
            'data' => $rows->values()->map(fn ($l) => $this->issuer->present($l)),
            'stats' => [
                'total' => $all->count(),
                'active' => $all->where('status', 'active')->count(),
                'unused' => $all->where('status', 'unused')->count(),
                'online' => $all->filter->isOnline()->count(),
                'suspended' => $all->whereIn('status', ['suspended', 'revoked'])->count(),
                'owners' => $all->pluck('owner_company_id')->unique()->count(),
                'by_target' => $all->groupBy('target')->map->count(),
                'users' => (int) $all->sum('users_count'),
                'revenue_total' => round((float) (clone $paid)->sum('amount'), 2),
                'revenue_month' => round((float) (clone $paid)->where('paid_at', '>=', now()->startOfMonth())->sum('amount'), 2),
                'versions' => $all->whereNotNull('app_version')->groupBy('app_version')->map->count(),
            ],
            'targets' => License::TARGETS,
            'statuses' => License::STATUSES,
        ];
    }

    public function show(int $id)
    {
        return ['data' => $this->issuer->present(License::findOrFail($id), true)];
    }

    public function setStatus(Request $r, int $id)
    {
        $d = $r->validate(['action' => 'required|in:suspend,reactivate,revoke,reset']);
        $l = License::findOrFail($id);
        match ($d['action']) {
            'suspend' => $l->update(['status' => 'suspended']),
            'reactivate' => $l->update(['status' => $l->fingerprint ? 'active' : 'unused']),
            'revoke' => $l->update(['status' => 'revoked']),
            'reset' => $l->update(['fingerprint' => null, 'device_name' => null, 'resets' => $l->resets + 1]),
        };
        $l->log(['suspend' => 'suspended', 'reactivate' => 'reactivated', 'revoke' => 'revoked', 'reset' => 'reset'][$d['action']], ['by' => 'éditeur']);
        ActivityLog::record('admin.license.'.$d['action'], $l);

        return ['data' => $this->issuer->present($l->fresh(), true)];
    }

    /** Site de présentation : audience, entonnoir, demandes et inscrits. */
    public function site(Request $r)
    {
        return \App\Services\SiteTraffic::analytics(min(365, max(7, (int) $r->query('days', 30))));
    }

    public function updateRequest(Request $r, int $id)
    {
        $d = $r->validate(['status' => 'required|in:new,handled']);
        abort_unless(\Illuminate\Support\Facades\DB::table('landing_requests')->where('id', $id)->update($d + ['updated_at' => now()]), 404);

        return ['ok' => true];
    }

    /** Active la clé officielle de signature des licences (clé éditeur), après une installation antérieure à la 0.99.3. */
    public function unlockSigning(Request $r)
    {
        $d = $r->validate(['editor_key' => 'required|string|max:255']);
        abort_unless(\App\Licensing\LicenseSigner::unlock($d['editor_key']), 422, 'Clé éditeur incorrecte.');

        return ['signing_ready' => true];
    }
}
