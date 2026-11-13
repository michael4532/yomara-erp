<?php

namespace App\Modules\Crm;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Relances automatiques des prospects : modèles de relance, inscription d'un prospect, suivi et arrêt. */
class SequenceController extends Controller
{
    public function __construct(protected Sequences $seq) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function row(object $s): array
    {
        $runs = DB::table('crm_sequence_runs')->where('sequence_id', $s->id);

        return ['id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'steps' => json_decode($s->steps, true) ?: [], 'active' => (bool) $s->active,
            'running' => (clone $runs)->where('status', 'active')->count(), 'finished' => (clone $runs)->where('status', 'done')->count(),
            'won' => DB::table('crm_sequence_runs')->join('crm_leads', 'crm_leads.id', '=', 'crm_sequence_runs.lead_id')->where('crm_sequence_runs.sequence_id', $s->id)->where('crm_leads.status', 'won')->count()];
    }

    public function index()
    {
        return ['data' => DB::table('crm_sequences')->where('company_id', app('tenant')->id)->orderBy('name')->get()->map(fn ($s) => $this->row($s))->values(),
            'channels' => Sequences::CHANNELS, 'vars' => Sequences::VARS];
    }

    /** Bibliothèque de relances prêtes à l'emploi (à copier puis adapter). */
    public function library()
    {
        return ['data' => array_map(fn ($p) => $p + ['steps' => Sequences::validateSteps($p['steps'])], SequenceLibrary::all())];
    }

    protected function data(Request $r, bool $creating): array
    {
        // Création depuis la bibliothèque : nom, description et étapes du modèle, que le client modifie ensuite
        if ($creating && $r->filled('preset') && ($p = SequenceLibrary::find((string) $r->input('preset')))) {
            $r->merge(['name' => $r->input('name') ?: $p['name'], 'description' => $r->input('description') ?? $p['description'], 'steps' => $r->input('steps') ?: $p['steps']]);
        }
        $d = $r->validate(['name' => ($creating ? 'required' : 'sometimes').'|string|max:150', 'description' => 'nullable|string|max:1000', 'active' => 'sometimes|boolean', 'steps' => ($creating ? 'required' : 'sometimes').'|array']);
        if (array_key_exists('steps', $d)) {
            $d['steps'] = json_encode(Sequences::validateSteps($d['steps']), JSON_UNESCAPED_UNICODE);
        }

        return $d;
    }

    public function store(Request $r)
    {
        return $this->guard(function () use ($r) {
            $id = DB::table('crm_sequences')->insertGetId($this->data($r, true) + ['company_id' => app('tenant')->id, 'created_by' => $r->user()->id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

            return response()->json(['data' => $this->row(DB::table('crm_sequences')->find($id))], 201);
        });
    }

    protected function find(int $id): object
    {
        return DB::table('crm_sequences')->where('company_id', app('tenant')->id)->where('id', $id)->first() ?? abort(404, 'Relance introuvable.');
    }

    public function update(Request $r, int $id)
    {
        $this->find($id);

        return $this->guard(function () use ($r, $id) {
            DB::table('crm_sequences')->where('id', $id)->update($this->data($r, false) + ['updated_at' => now()]);

            return ['data' => $this->row($this->find($id))];
        });
    }

    public function destroy(int $id)
    {
        $this->find($id);
        foreach (DB::table('crm_sequence_runs')->where('sequence_id', $id)->where('status', 'active')->pluck('lead_id') as $lead) {
            Sequences::stopFor(app('tenant')->id, $lead, 'relance supprimée');
        }
        DB::table('crm_sequences')->where('id', $id)->delete();

        return response()->noContent();
    }

    protected function lead(string $id): Lead
    {
        return Lead::where('id', $id)->first() ?? abort(404, 'Opportunité introuvable.');
    }

    public function enroll(Request $r, string $lead)
    {
        $v = $r->validate(['sequence_id' => 'required|integer']);
        $l = $this->lead($lead);

        return $this->guard(fn () => ['data' => $this->runs($l->id, $this->seq->enroll(app('tenant'), $l, (int) $v['sequence_id'], $r->user()))]);
    }

    public function stop(string $lead)
    {
        $l = $this->lead($lead);
        Sequences::stopFor(app('tenant')->id, (string) $l->id, 'à la demande de '.request()->user()->name);

        return ['data' => $this->runs($l->id)];
    }

    protected function runs(string $leadId, $_ = null): array
    {
        return DB::table('crm_sequence_runs')->where('company_id', app('tenant')->id)->where('lead_id', $leadId)->orderByDesc('id')->limit(10)->get()->map(function ($r) {
            $seq = DB::table('crm_sequences')->find($r->sequence_id);
            $steps = $seq ? (json_decode($seq->steps, true) ?: []) : [];

            return ['id' => $r->id, 'sequence' => $seq?->name, 'status' => $r->status, 'step' => $r->step + ($r->status === 'active' ? 1 : 0), 'steps' => count($steps),
                'next_at' => $r->next_at, 'next' => $r->status === 'active' ? ($steps[$r->step]['channel'] ?? null) : null, 'history' => json_decode((string) $r->history, true) ?: []];
        })->values()->all();
    }

    public function leadRuns(string $lead)
    {
        return ['data' => $this->runs($this->lead($lead)->id)];
    }
}
