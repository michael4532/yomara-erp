<?php

namespace App\Modules\Agences;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Modules\Agences\Models\Branch;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgencesController extends Controller
{
    protected function row(Branch $b): array
    {
        return $b->toArray() + [
            'user_ids' => DB::table('branch_user')->where('branch_id', $b->id)->pluck('user_id')->all(),
            'warehouse_name' => $b->warehouse_id ? DB::table('warehouses')->where('id', $b->warehouse_id)->value('name') : null,
            'cash_account_name' => $b->cash_account_id ? DB::table('tr_accounts')->where('id', $b->cash_account_id)->value('name') : null,
            'manager_name' => $b->manager_id ? DB::table('users')->where('id', $b->manager_id)->value('name') : null,
        ];
    }

    public function index()
    {
        return ['data' => Branch::orderByDesc('is_head_office')->orderBy('name')->get()->map(fn ($b) => $this->row($b))->values()];
    }

    public function summary(Request $r, BranchService $svc)
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = $d['from'] ?? now()->startOfYear()->toDateString();
        $to = $d['to'] ?? now()->toDateString();

        return ['data' => $svc->summary(app('tenant'), $from, $to)];
    }

    public function save(Request $r, EntityWriter $w, ?string $id = null)
    {
        $data = $r->validate(Branch::syncRules() + ['user_ids' => 'sometimes|array', 'user_ids.*' => 'integer', 'create_warehouse' => 'sometimes|boolean', 'create_cash' => 'sometimes|boolean']);
        $id ??= (string) Str::uuid();
        $users = $data['user_ids'] ?? null;
        $res = $w->upsert(Branch::class, $id, collect($data)->except(['user_ids', 'create_warehouse', 'create_cash'])->all());
        if ($res['status'] !== EntityWriter::APPLIED) {
            return response()->json(['message' => $res['message'] ?? 'Enregistrement refusé.', 'errors' => $res['errors'] ?? null], 422);
        }
        $b = Branch::findOrFail($id);
        // Entrepôt et caisse propres à l'agence, créés à la demande
        if (! empty($data['create_warehouse']) && ! $b->warehouse_id && app('tenant')->hasModule('stock')) {
            $wid = (string) Str::uuid();
            $w->upsert(\App\Modules\Stock\Models\Warehouse::class, $wid, ['name' => 'Dépôt '.$b->name, 'code' => $b->code, 'branch_id' => $b->id]);
            $w->upsert(Branch::class, $b->id, ['warehouse_id' => $wid]);
        }
        if (! empty($data['create_cash']) && ! $b->cash_account_id && app('tenant')->hasModule('tresorerie')) {
            $aid = (string) Str::uuid();
            $w->upsert(\App\Modules\Tresorerie\Models\TreasuryAccount::class, $aid, ['name' => 'Caisse '.$b->name, 'kind' => 'cash', 'methods' => ['cash'], 'branch_id' => $b->id, 'opening_date' => now()->toDateString()]);
            $w->upsert(Branch::class, $b->id, ['cash_account_id' => $aid]);
        }
        $b = $b->fresh();
        // L'entrepôt et la caisse choisis sont rattachés à l'agence
        foreach ([['warehouses', $b->warehouse_id], ['tr_accounts', $b->cash_account_id]] as [$table, $ref]) {
            if ($ref) {
                DB::table($table)->where('company_id', app('tenant')->id)->where('id', $ref)->whereNull('branch_id')->update(['branch_id' => $b->id]);
            }
        }
        if ($users !== null) {
            $valid = DB::table('users')->where('company_id', app('tenant')->id)->whereIn('id', $users)->pluck('id')->all();
            DB::table('branch_user')->where('branch_id', $b->id)->delete();
            DB::table('branch_user')->insert(array_map(fn ($u) => ['branch_id' => $b->id, 'user_id' => $u], $valid));
        }
        ActivityLog::record('agences.saved', $b);

        return ['data' => $this->row($b)];
    }

    public function destroy(EntityWriter $w, string $id)
    {
        $b = Branch::findOrFail($id);
        $res = $w->delete(Branch::class, $id);
        if (($res['status'] ?? null) !== EntityWriter::APPLIED) {
            return response()->json(['message' => $res['message'] ?? 'Suppression refusée.'], 422);
        }
        DB::table('branch_user')->where('branch_id', $id)->delete();
        ActivityLog::record('agences.deleted', $b);

        return ['ok' => true];
    }
}
