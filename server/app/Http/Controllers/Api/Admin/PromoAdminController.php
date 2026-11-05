<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Store\PromoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Super admin : codes promo (formules, modules, nouveaux abonnements ou renouvellements). */
class PromoAdminController extends Controller
{
    public function index(Request $r)
    {
        $q = PromoCode::query()->latest('id');
        if ($s = trim((string) $r->query('q'))) {
            $q->where(fn ($w) => $w->where('code', 'like', "%$s%")->orWhere('description', 'like', "%$s%")->orWhere('batch', 'like', "%$s%"));
        }
        $rows = $q->limit(500)->get();
        $saved = DB::table('promo_redemptions')->selectRaw('promo_code_id, sum(discount) as total')->groupBy('promo_code_id')->pluck('total', 'promo_code_id');

        return [
            'data' => $rows->map(fn ($p) => $p->toArray() + ['label' => $p->label(), 'valid' => $p->isValidNow(), 'discount_total' => round((float) ($saved[$p->id] ?? 0), 2)]),
            'stats' => [
                'total' => PromoCode::count(),
                'active' => PromoCode::where('is_active', true)->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->count(),
                'uses' => (int) PromoCode::sum('uses'),
                'discount_total' => round((float) DB::table('promo_redemptions')->sum('discount'), 2),
            ],
            'plans' => Plan::orderBy('sort')->get(['code', 'name']),
            'modules' => Module::where('is_active', true)->where('is_core', false)->orderBy('name')->get(['key', 'name']),
            'packs' => collect(\App\Support\PricingGrid::PACKS)->map(fn ($p, $k) => ['key' => $k, 'name' => $p['name']])->values(),
            'applies' => PromoCode::APPLIES,
            'usages' => PromoCode::USAGES,
        ];
    }

    protected function rules(?PromoCode $p = null): array
    {
        return [
            'code' => [$p ? 'sometimes' : 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('promo_codes', 'code')->ignore($p?->id)],
            'description' => 'nullable|string|max:255',
            'type' => 'sometimes|in:percent,amount',
            'value' => [$p ? 'sometimes' : 'required', 'numeric', 'min:0.01'],
            'applies_to' => 'sometimes|in:all,plan,module',
            'target' => 'nullable|string|max:60',
            'usage' => 'sometimes|in:any,new,renew',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'max_uses' => 'nullable|integer|min:1',
            'once_per_user' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules() + ['count' => 'nullable|integer|min:1|max:500', 'prefix' => 'nullable|string|max:10|regex:/^[A-Za-z0-9]+$/', 'valid_months' => 'nullable|integer|min:1|max:36']);
        if (($d['type'] ?? 'percent') === 'percent' && $d['value'] > 100) {
            return response()->json(['message' => 'Un pourcentage ne peut pas dépasser 100.'], 422);
        }
        $count = (int) ($d['count'] ?? 1);
        $base = collect($d)->except(['count', 'prefix', 'valid_months', 'code'])->all() + ['created_by' => $r->user()->id];
        // Valable 3 mois par défaut
        $base['valid_until'] ??= now()->addMonths((int) ($d['valid_months'] ?? 3))->endOfDay();
        $base['code'] = null;
        $batch = $count > 1 ? 'LOT-'.now()->format('ymdHis') : null;
        $created = [];
        for ($i = 0; $i < $count; $i++) {
            $code = $count === 1 && ! empty($d['code']) ? PromoService::normalize($d['code']) : PromoService::generate($d['prefix'] ?? 'YOM');
            $created[] = PromoCode::create(['code' => $code, 'batch' => $batch] + $base);
        }
        ActivityLog::record('admin.promo.created', $created[0], ['count' => $count]);

        return response()->json(['data' => collect($created)->map(fn ($p) => $p->toArray() + ['label' => $p->label()]), 'batch' => $batch], 201);
    }

    public function update(Request $r, int $id)
    {
        $p = PromoCode::findOrFail($id);
        $d = $r->validate($this->rules($p));
        if (isset($d['code'])) {
            $d['code'] = PromoService::normalize($d['code']);
        }
        $p->update($d);

        return ['data' => $p->fresh()->toArray() + ['label' => $p->label()]];
    }

    public function destroy(int $id)
    {
        $p = PromoCode::findOrFail($id);
        if ($p->uses > 0) {
            $p->update(['is_active' => false]); // déjà utilisé : désactivé, l'historique reste

            return ['ok' => true, 'deactivated' => true];
        }
        $p->delete();

        return ['ok' => true];
    }

    public function redemptions(int $id)
    {
        $rows = DB::table('promo_redemptions')->where('promo_code_id', $id)->latest('id')->limit(200)->get();
        $companies = Company::withoutGlobalScope('real')->whereIn('id', $rows->pluck('company_id'))->pluck('name', 'id');
        $users = \App\Models\User::whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');

        return ['data' => $rows->map(fn ($x) => (array) $x + ['company' => $companies[$x->company_id] ?? null, 'user' => $users[$x->user_id] ?? null])];
    }
}
