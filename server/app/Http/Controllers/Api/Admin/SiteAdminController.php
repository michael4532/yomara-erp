<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteRequest;
use App\Modules\Sites\SiteService;
use App\Modules\Sites\Templates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Super admin : demandes de sites (modèle ou sur mesure), devis, création, domaines, suspension. */
class SiteAdminController extends Controller
{
    public function __construct(private SiteService $svc) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function index()
    {
        if (! Schema::hasTable('ws_sites')) {
            return ['requests' => [], 'sites' => [], 'settings' => SiteService::settings()];
        }
        $requests = SiteRequest::withoutGlobalScopes()->orderByRaw("case when status in ('submitted','accepted','in_progress') then 0 when status = 'quoted' then 1 else 2 end")->orderByDesc('id')->limit(300)->get();
        $sites = Site::withoutGlobalScopes()->orderByDesc('id')->limit(500)->get();
        $names = Company::whereIn('id', $requests->pluck('company_id')->merge($sites->pluck('company_id'))->unique())->get(['id', 'name', 'email', 'phone'])->keyBy('id');
        $views = DB::table('ws_visits')->whereIn('site_id', $sites->pluck('id'))->where('date', '>=', now()->subDays(29)->toDateString())->selectRaw('site_id, sum(views) v')->groupBy('site_id')->pluck('v', 'site_id');
        $msgs = DB::table('ws_messages')->whereIn('site_id', $sites->pluck('id'))->selectRaw('site_id, count(*) n')->groupBy('site_id')->pluck('n', 'site_id');

        return [
            'requests' => $requests->map(fn ($r) => $this->svc->requestToApi($r) + ['company' => $names[$r->company_id]?->only(['id', 'name', 'email', 'phone'])])->values(),
            'sites' => $sites->map(fn ($s) => $s->only(['id', 'name', 'slug', 'template', 'status', 'custom_domain', 'domain_status', 'domain_error', 'published_at', 'created_at'])
                + ['company' => $names[$s->company_id]?->name, 'company_id' => $s->company_id, 'url' => $this->svc->url($s), 'preview_url' => $this->svc->previewUrl($s),
                    'template_name' => Templates::get($s->template)['name'] ?? $s->template, 'views_30d' => (int) ($views[$s->id] ?? 0), 'messages' => (int) ($msgs[$s->id] ?? 0)])->values(),
            'settings' => SiteService::settings() + ['provider_name' => $this->svc->providerCompany()?->name],
            'pricing' => SiteService::pricing(),
            'templates' => collect(Templates::gallery())->map(fn ($t) => ['key' => $t['key'], 'name' => $t['name']])->values(),
        ];
    }

    public function settings(Request $r)
    {
        $v = $r->validate(['base_domain' => 'nullable|string|max:120', 'server_ip' => 'nullable|ip', 'cname_target' => 'nullable|string|max:190',
            'provider_company_id' => 'nullable|integer|exists:companies,id', 'auto_template' => 'nullable|boolean', 'tax_rate' => 'nullable|numeric|min:0|max:50']);

        return ['data' => SiteService::saveSettings($v)];
    }

    public function quote(Request $r, int $id)
    {
        $v = $r->validate(['lines' => 'nullable|array|max:40', 'lines.*.description' => 'required_with:lines|string|max:250', 'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:2000', 'discount_percent' => 'nullable|numeric|min:0|max:100']);

        return $this->guard(fn () => ['data' => $this->svc->requestToApi($this->svc->quote(SiteRequest::withoutGlobalScopes()->findOrFail($id), $v['lines'] ?? null, $v['note'] ?? null, (float) ($v['discount_percent'] ?? 0)))]);
    }

    public function deliver(Request $r, int $id)
    {
        $r->validate(['template' => 'nullable|string|max:60']);

        return $this->guard(function () use ($r, $id) {
            $req = SiteRequest::withoutGlobalScopes()->findOrFail($id);
            $site = $this->svc->deliver($req, $r->input('template'));

            return ['data' => $this->svc->requestToApi($req->fresh()), 'site' => ['id' => $site->id, 'url' => $this->svc->url($site), 'preview_url' => $this->svc->previewUrl($site)]];
        });
    }

    public function progress(int $id)
    {
        $req = SiteRequest::withoutGlobalScopes()->findOrFail($id);
        if ($req->status !== 'accepted') {
            return response()->json(['message' => 'Seul un devis accepté passe en réalisation.'], 422);
        }
        $req->forceFill(['status' => 'in_progress'])->save();

        return ['data' => $this->svc->requestToApi($req)];
    }

    public function reject(Request $r, int $id)
    {
        $v = $r->validate(['reason' => 'nullable|string|max:1000']);

        return $this->guard(fn () => ['data' => $this->svc->requestToApi($this->svc->reject(SiteRequest::withoutGlobalScopes()->findOrFail($id), $v['reason'] ?? null))]);
    }

    public function status(Request $r, int $id)
    {
        $v = $r->validate(['status' => 'required|in:draft,published,suspended']);
        $s = Site::withoutGlobalScopes()->findOrFail($id);
        $s->forceFill(['status' => $v['status'], 'published_at' => $v['status'] === 'published' ? ($s->published_at ?? now()) : $s->published_at])->save();

        return ['data' => $s];
    }

    public function checkDomain(int $id)
    {
        $s = Site::withoutGlobalScopes()->findOrFail($id);

        return ['data' => $this->svc->checkDomain($s)->only(['id', 'custom_domain', 'domain_status', 'domain_error', 'domain_checked_at'])];
    }
}
