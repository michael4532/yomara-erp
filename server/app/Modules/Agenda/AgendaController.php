<?php

namespace App\Modules\Agenda;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AgendaController extends Controller
{
    public function __construct(protected AgendaService $svc) {}

    /** Congés, entretiens, rendez-vous clients, interventions, échéances (lecture seule). */
    public function overlay(Request $r)
    {
        $v = $r->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = Carbon::parse($v['from'])->startOfDay();
        $to = Carbon::parse($v['to'])->endOfDay();
        if ($from->diffInDays($to) > 400) {
            $to = $from->copy()->addDays(400);
        }

        return ['data' => $this->svc->overlay(app('tenant'), $r->user(), $from, $to)];
    }

    public function users()
    {
        return ['data' => User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => $u->hasPermission('agenda.use|agenda.manage'))->map(fn ($u) => $u->only(['id', 'name', 'email']))->values()];
    }

    public function feed(Request $r)
    {
        return ['data' => ['url' => $this->svc->feedUrl($r->user(), $r->isMethod('post') && $r->boolean('renew'))]];
    }

    public function ics(Request $r, string $id)
    {
        $e = Models\Event::findOrFail($id);
        abort_unless($e->canSeeDetails($r->user()), 404);

        return response($this->svc->ics(app('tenant'), [$e], $e->title, 'PUBLISH'), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="evenement.ics"']);
    }

    public function invite(Request $r, string $id)
    {
        $e = Models\Event::findOrFail($id);
        abort_unless((int) $e->owner_id === $r->user()->id || $r->user()->hasPermission('agenda.manage'), 403);

        return ['sent' => $this->svc->inviteGuests($e)];
    }

    /** Éléments des autres modules déplaçables depuis l'agenda (comme un calendrier) : modèle, droit, champs date. */
    protected const MOVABLE = [
        'interview' => [\App\Modules\Recrutement\Models\Interview::class, 'recrutement'],
        'appointment' => [\App\Modules\RendezVous\Models\Appointment::class, 'rendez_vous'],
        'intervention' => [\App\Modules\Interventions\Models\Intervention::class, 'interventions'],
        'task' => [\App\Modules\Projets\Models\Task::class, 'projets'],
    ];

    protected function movable(Request $r, string $kind): string
    {
        [$class, $module] = self::MOVABLE[$kind];
        abort_unless(app('tenant')->hasModule($module) && class_exists($class), 404);
        abort_unless($r->user()->hasPermission($class::$managePermission), 403, 'Vous ne pouvez pas modifier cet élément.');

        return $class;
    }

    /** Déplacer ou redimensionner un entretien, un rendez-vous, une intervention ou l'échéance d'une tâche. */
    public function moveOverlay(Request $r, string $kind, string $id, \App\Services\EntityWriter $writer)
    {
        $class = $this->movable($r, $kind);
        $v = $r->validate(['starts_at' => 'required|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at']);
        $s = Carbon::parse($v['starts_at']);
        $e = isset($v['ends_at']) ? Carbon::parse($v['ends_at']) : null;
        $minutes = $e ? max(5, (int) $s->diffInMinutes($e)) : null;
        $data = match ($kind) {
            'interview' => array_filter(['starts_at' => $s->format('Y-m-d H:i:s'), 'duration' => $minutes]),
            'appointment' => ['starts_at' => $s->format('Y-m-d H:i:s'), 'ends_at' => ($e ?? $s->copy()->addMinutes(30))->format('Y-m-d H:i:s')],
            'intervention' => array_filter(['scheduled_at' => $s->format('Y-m-d H:i:s'), 'duration_minutes' => $minutes]),
            'task' => ['due_date' => $s->toDateString()],
        };
        $res = $writer->upsert($class, $id, $data);
        if ($res['status'] !== \App\Services\EntityWriter::APPLIED) {
            return response()->json(['message' => $res['message'] ?? 'Modification refusée.'], 422);
        }

        return ['ok' => true];
    }

    /** Annuler un entretien, un rendez-vous ou une intervention depuis l'agenda. */
    public function cancelOverlay(Request $r, string $kind, string $id, \App\Services\EntityWriter $writer)
    {
        $class = $this->movable($r, $kind);
        $res = $writer->upsert($class, $id, ['status' => 'cancelled']);
        if ($res['status'] !== \App\Services\EntityWriter::APPLIED) {
            return response()->json(['message' => $res['message'] ?? 'Annulation refusée.'], 422);
        }

        return ['ok' => true];
    }
}
