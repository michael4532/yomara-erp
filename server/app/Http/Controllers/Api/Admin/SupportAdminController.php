<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\SupportController as S;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Support vu par l'éditeur : toutes les conversations des sociétés, réponses en direct. */
class SupportAdminController extends Controller
{
    public function index(Request $r)
    {
        $q = DB::table('support_conversations');
        if ($s = $r->query('status')) {
            $s === 'active' ? $q->where('status', '!=', 'closed') : $q->where('status', $s);
        }
        if ($c = $r->query('company_id')) {
            $q->where('company_id', (int) $c);
        }
        if ($t = trim((string) $r->query('q'))) {
            $q->where('subject', 'like', '%'.$t.'%');
        }
        $rows = $q->orderByRaw('unread_platform = 0')->orderByDesc('last_message_at')->limit(200)->get();
        $companies = Company::whereIn('id', $rows->pluck('company_id')->unique())->pluck('name', 'id');

        return [
            'data' => $rows->map(fn ($c) => S::row($c, true) + ['company_id' => $c->company_id, 'company' => $companies[$c->company_id] ?? '—'])->values(),
            'counts' => [
                'active' => DB::table('support_conversations')->where('status', '!=', 'closed')->count(),
                'unread' => (int) DB::table('support_conversations')->sum('unread_platform'),
                'closed' => DB::table('support_conversations')->where('status', 'closed')->count(),
            ],
            'categories' => S::CATEGORIES,
        ];
    }

    protected function conv(int $id)
    {
        $c = DB::table('support_conversations')->find($id);
        abort_unless($c, 404);

        return $c;
    }

    public function show(Request $r, int $id)
    {
        $c = $this->conv($id);
        if ($c->unread_platform > 0) {
            DB::table('support_conversations')->where('id', $id)->update(['unread_platform' => 0]);
        }
        $company = Company::find($c->company_id);

        return ['data' => S::row($this->conv($id), true) + ['company_id' => $c->company_id, 'company' => $company?->name,
            'company_info' => $company ? ['status' => $company->status, 'email' => $company->email ?? null, 'phone' => $company->phone ?? null,
                'users' => \App\Models\User::where('company_id', $company->id)->count(), 'modules' => count($company->enabledModules())] : null],
            'messages' => S::messages($id, (int) $r->query('after', 0))];
    }

    public function reply(Request $r, int $id)
    {
        $this->conv($id);
        $d = $r->validate(['body' => 'required|string|max:5000', 'status' => 'nullable|in:open,waiting,closed']);
        S::insert($id, $r->user()->id, true, $d['body']);
        DB::table('support_conversations')->where('id', $id)->update(['status' => $d['status'] ?? 'waiting', 'unread_company' => DB::raw('unread_company + 1'),
            'assigned_to' => $r->user()->id, 'last_message_at' => now(), 'updated_at' => now()]);

        return ['data' => S::row($this->conv($id), true), 'messages' => S::messages($id, (int) $r->input('after', 0))];
    }

    public function update(Request $r, int $id)
    {
        $this->conv($id);
        $d = $r->validate(['status' => 'nullable|in:open,waiting,closed', 'priority' => 'nullable|in:low,normal,high,urgent']);
        DB::table('support_conversations')->where('id', $id)->update(array_filter($d) + ['updated_at' => now()]);

        return ['data' => S::row($this->conv($id), true)];
    }
}
