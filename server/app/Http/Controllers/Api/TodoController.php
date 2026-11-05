<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Tâches personnelles de l'utilisateur (« Ma journée »), synchronisées entre ses appareils. */
class TodoController extends Controller
{
    protected function q(Request $r)
    {
        return DB::table('user_todos')->where('user_id', $r->user()->id);
    }

    protected function out(object $t): array
    {
        return ['id' => $t->id, 'title' => $t->title, 'due_date' => $t->due_date, 'important' => (bool) $t->important, 'my_day' => (bool) $t->my_day,
            'done' => $t->done_at !== null, 'done_at' => $t->done_at, 'created_at' => $t->created_at];
    }

    public function index(Request $r)
    {
        // Les tâches terminées depuis plus de 7 jours ne sont plus renvoyées
        $rows = $this->q($r)->where(fn ($w) => $w->whereNull('done_at')->orWhere('done_at', '>=', now()->subDays(7)))
            ->orderByRaw('done_at IS NOT NULL')->orderByDesc('important')->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('sort')->orderByDesc('id')->limit(300)->get();

        return ['data' => $rows->map(fn ($t) => $this->out($t))->values(), 'open' => $this->q($r)->whereNull('done_at')->count()];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['title' => 'required|string|max:255', 'due_date' => 'nullable|date', 'important' => 'nullable|boolean']);
        $id = DB::table('user_todos')->insertGetId(['company_id' => $r->user()->company_id, 'user_id' => $r->user()->id, 'title' => trim($d['title']), 'due_date' => $d['due_date'] ?? null,
            'important' => (bool) ($d['important'] ?? false), 'my_day' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => $this->out(DB::table('user_todos')->find($id))], 201);
    }

    public function update(Request $r, int $id)
    {
        $t = $this->q($r)->where('id', $id)->first() ?? abort(404);
        $d = $r->validate(['title' => 'nullable|string|max:255', 'due_date' => 'nullable|date', 'important' => 'nullable|boolean', 'done' => 'nullable|boolean']);
        $u = ['updated_at' => now()];
        foreach (['title', 'due_date'] as $k) {
            if ($r->exists($k)) {
                $u[$k] = $d[$k];
            }
        }
        if ($r->has('important')) {
            $u['important'] = $r->boolean('important');
        }
        if ($r->has('done')) {
            $u['done_at'] = $r->boolean('done') ? ($t->done_at ?? now()) : null;
        }
        DB::table('user_todos')->where('id', $id)->update($u);

        return ['data' => $this->out(DB::table('user_todos')->find($id))];
    }

    public function destroy(Request $r, int $id)
    {
        $this->q($r)->where('id', $id)->delete();

        return response()->noContent();
    }
}
