<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanAdminController extends Controller
{
    public function index()
    {
        $counts = Company::selectRaw('plan_id, COUNT(*) as total')->groupBy('plan_id')->pluck('total', 'plan_id');

        return ['data' => Plan::orderBy('sort')->get()->map(fn ($p) => $p->toArray() + ['companies' => (int) ($counts[$p->id] ?? 0), 'offer' => $p->offer(0)])];
    }

    protected function rules(?Plan $plan = null): array
    {
        return [
            'code' => [$plan ? 'sometimes' : 'required', 'alpha_dash', 'max:50', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'nullable|string',
            'price_monthly' => 'sometimes|numeric|min:0',
            'price_yearly' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'max_users' => 'nullable|integer|min:1',
            'modules' => 'sometimes|array',
            'modules.*' => 'string|exists:modules,key',
            'is_active' => 'sometimes|boolean',
            'sort' => 'sometimes|integer',
            'is_free' => 'sometimes|boolean',
            'yearly_discount' => 'sometimes|numeric|min:0|max:90',
            'intro_discount' => 'sometimes|numeric|min:0|max:90',
            'intro_months' => 'sometimes|integer|min:0|max:24',
            'badge' => 'nullable|string|max:40',
        ];
    }

    public function store(Request $request)
    {
        return response()->json(['data' => Plan::create($request->validate($this->rules()))], 201);
    }

    public function update(Request $request, int $id)
    {
        $plan = Plan::findOrFail($id);
        $plan->update($request->validate($this->rules($plan)));
        if ($plan->yearly_discount > 0) {
            $plan->update(['price_yearly' => $plan->yearlyPrice()]); // prix annuel tenu à jour avec la remise
        }
        app(\App\Support\ModuleRegistry::class)->flush();

        return ['data' => $plan];
    }

    public function destroy(int $id)
    {
        $plan = Plan::findOrFail($id);
        abort_if(Company::where('plan_id', $id)->exists(), 422, 'Des sociétés utilisent encore cette formule.');
        $plan->delete();

        return ['ok' => true];
    }
}
