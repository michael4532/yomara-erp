<?php

namespace App\Modules\Billetterie;

use App\Http\Controllers\Controller;
use App\Modules\Billetterie\Models\Ticket;
use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;
use Illuminate\Http\Request;

class BilletterieController extends Controller
{
    public function __construct(private TicketService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function scan(Request $r)
    {
        $v = $r->validate(['event_id' => 'required|uuid', 'code' => 'required|string|max:200', 'gate' => 'nullable|string|max:60']);

        return $this->guard(fn () => ['data' => $this->svc->scan($v['event_id'], $v['code'], $v['gate'] ?? null)]);
    }

    public function stats(string $id)
    {
        return ['data' => $this->svc->stats(Event::findOrFail($id))];
    }

    /** Analyse des inscriptions : courbe, provenance, réponses, présence, avis. */
    public function analytics(string $id)
    {
        return ['data' => app(RegistrationService::class)->analytics(Event::findOrFail($id))];
    }

    /** Envoyer maintenant la demande d'avis aux participants. */
    public function requestFeedback(string $id)
    {
        $e = Event::findOrFail($id);
        if ($e->feedback_sent_at) {
            return response()->json(['message' => 'Demande d\'avis déjà envoyée le '.$e->feedback_sent_at->format('d/m/Y').'.'], 422);
        }
        if ($e->end_date->isFuture()) {
            return response()->json(['message' => 'L\'événement n\'est pas encore terminé.'], 422);
        }
        $n = app(RegistrationService::class)->requestFeedback(app('tenant'), now()->addDay()->toDateString(), $e->id);

        return ['data' => ['sent' => $n]];
    }

    public function badges(Request $r, string $id)
    {
        $v = $r->validate(['names' => 'required|array|min:1|max:50', 'names.*' => 'nullable|string|max:160']);

        return $this->guard(fn () => response()->json(['data' => array_map(fn ($t) => $t->toSyncArray(), $this->svc->exhibitorBadges(Booking::findOrFail($id), $v['names']))], 201));
    }

    public function resend(string $id)
    {
        $o = TicketOrder::findOrFail($id);

        return $this->svc->sendTickets(app('tenant'), $o) ? ['data' => ['sent' => true]]
            : response()->json(['message' => 'Aucun canal d\'envoi disponible (e-mail ou WhatsApp de l\'acheteur et canaux configurés).'], 422);
    }

    public function lookup(Request $r)
    {
        $t = $this->svc->find((string) $r->validate(['code' => 'required|string|max:200'])['code']);

        return $t ? ['data' => $t->toSyncArray()] : response()->json(['message' => 'Billet inconnu.'], 404);
    }
}
