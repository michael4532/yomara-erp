<?php

namespace App\Modules\Billetterie;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Billetterie\Models\Ticket;
use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Billetterie\Models\TicketType;
use App\Modules\Evenements\Models\Event;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Achat de billets en ligne, page de commande avec les QR codes, billet individuel. */
class PublicTicketController extends Controller
{
    public function __construct(private TicketService $svc) {}

    protected function company(int $id): Company
    {
        $c = Company::find($id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('billetterie'), 404);
        app()->instance('tenant', $c);

        return $c;
    }

    protected function view(string $name, array $data)
    {
        return response()->view('billetterie.'.$name, $data)->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
    }

    protected function cur(Company $c, Event $e): string
    {
        $cur = $e->currency ?: $c->currency;

        return in_array($cur, ['XOF', 'XAF'], true) ? 'F CFA' : $cur;
    }

    public function show(string $token)
    {
        abort_unless(strlen($token) >= 32, 404);
        $raw = Event::withoutGlobalScopes()->where('public_token', $token)->whereNull('deleted_at')->first();
        abort_unless($raw && $raw->ticketing_enabled && in_array($raw->status, ['open', 'closed'], true), 404);
        $c = $this->company($raw->company_id);
        $e = Event::find($raw->id);
        $types = TicketType::where('event_id', $e->id)->where('active', true)->where('online', true)->orderBy('sort')->orderBy('price')->get()
            ->map(fn ($t) => ['t' => $t, 'left' => $t->left(), 'on' => $t->onSale()]);

        return $this->view('show', ['company' => $c, 'event' => $e, 'types' => $types, 'cur' => $this->cur($c, $e), 'error' => session('error'),
            'form' => app(RegistrationService::class)->form($e), 'source' => Str::limit((string) (request('src') ?: request('utm_source') ?: request('ref')), 60, '')]);
    }

    public function buy(Request $r, string $token)
    {
        abort_unless(strlen($token) >= 32, 404);
        $raw = Event::withoutGlobalScopes()->where('public_token', $token)->whereNull('deleted_at')->first();
        abort_unless($raw && $raw->ticketing_enabled, 404);
        $c = $this->company($raw->company_id);
        app()->instance('tenant', $c);
        $event = Event::find($raw->id);
        $reg = app(RegistrationService::class);
        $form = $reg->form($event);
        $d = $r->validate([
            'name' => 'required|string|max:160', 'phone' => 'required_without:email|nullable|string|max:40', 'email' => ($form['ask_email'] === 'required' ? 'required' : 'nullable').'|email|max:255',
            'company' => 'nullable|string|max:160', 'job' => 'nullable|string|max:120', 'consent' => 'nullable|boolean', 'source' => 'nullable|string|max:60',
            'q' => 'nullable|array', 'qty' => 'required|array', 'qty.*' => 'nullable|integer|min:0|max:50', 'website' => 'max:0',
        ], ['name.required' => 'Indiquez votre nom.', 'phone.required_without' => 'Indiquez votre téléphone ou votre e-mail.', 'email.required' => 'Indiquez votre e-mail : votre billet vous y est envoyé.']);
        try {
            $answers = $reg->answers($event, (array) ($d['q'] ?? []));
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $items = [];
        foreach ($d['qty'] as $id => $q) {
            if ((int) $q > 0 && Str::isUuid((string) $id)) {
                $items[] = ['type_id' => (string) $id, 'quantity' => (int) $q];
            }
        }
        if (! $items) {
            return back()->withInput()->with('error', 'Choisissez au moins un billet.');
        }
        $id = (string) Str::uuid();
        TicketOrder::$systemWrite = true;
        try {
            $res = app(EntityWriter::class)->upsert(TicketOrder::class, $id, ['event_id' => $raw->id, 'buyer_name' => $d['name'], 'buyer_phone' => $d['phone'] ?? null,
                'buyer_email' => $d['email'] ?? null, 'items' => $items, 'status' => 'pending', 'buyer_company' => $d['company'] ?? null, 'buyer_job' => $d['job'] ?? null,
                'answers' => $answers ?: null, 'consent' => (bool) ($d['consent'] ?? false), 'source' => $d['source'] ?? null]);
        } finally {
            TicketOrder::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            return back()->withInput()->with('error', $res['message'] === 'Données invalides.' ? 'Vérifiez votre commande.' : $res['message']);
        }
        return redirect(TicketOrder::find($id)->publicUrl());
    }

    public function order(string $token)
    {
        abort_unless(strlen($token) >= 32, 404);
        $raw = TicketOrder::withoutGlobalScopes()->where('token', $token)->whereNull('deleted_at')->first();
        abort_unless($raw, 404);
        $c = $this->company($raw->company_id);
        $o = TicketOrder::find($raw->id);
        $e = Event::find($o->event_id);
        $tickets = Ticket::where('order_id', $o->id)->orderBy('code')->get();

        return $this->view('order', ['company' => $c, 'event' => $e, 'order' => $o, 'tickets' => $tickets, 'cur' => $this->cur($c, $e),
            'payUrl' => $o->status === 'pending' ? $this->svc->payUrl($o) : null, 'types' => TicketType::where('event_id', $e->id)->pluck('name', 'id')]);
    }

    /** Avis du participant après l'événement (lien reçu par e-mail ou WhatsApp). */
    public function feedback(Request $r, string $token)
    {
        [$c, $e, $t] = $this->ticketCtx($token);
        $done = DB::table('ev_feedback')->where('ticket_id', $t->id)->first();

        return $this->view('feedback', ['company' => $c, 'event' => $e, 'ticket' => $t, 'done' => $done, 'note' => (int) $r->query('note'), 'saved' => session('saved')]);
    }

    public function saveFeedback(Request $r, string $token)
    {
        [, , $t] = $this->ticketCtx($token);
        $d = $r->validate(['rating' => 'required|integer|min:1|max:5', 'nps' => 'nullable|integer|min:0|max:10', 'comment' => 'nullable|string|max:3000', 'website' => 'max:0'],
            ['rating.required' => 'Choisissez une note de 1 à 5 étoiles.']);
        app(RegistrationService::class)->saveFeedback($t, $d);

        return redirect('/avis/'.$token)->with('saved', true);
    }

    protected function ticketCtx(string $token): array
    {
        abort_unless(strlen($token) >= 24, 404);
        $raw = Ticket::withoutGlobalScopes()->where('token', $token)->first();
        abort_unless($raw, 404);
        $c = $this->company($raw->company_id);
        app()->instance('tenant', $c);
        $e = Event::find($raw->event_id);
        abort_unless($e, 404);

        return [$c, $e, Ticket::find($raw->id)];
    }

    public function ticket(string $token)
    {
        abort_unless(strlen($token) >= 24, 404);
        $raw = Ticket::withoutGlobalScopes()->where('token', $token)->whereNull('deleted_at')->first();
        abort_unless($raw, 404);
        $c = $this->company($raw->company_id);
        $t = Ticket::find($raw->id);
        $e = Event::find($t->event_id);

        return $this->view('ticket', ['company' => $c, 'event' => $e, 'ticket' => $t, 'type' => $t->type_id ? TicketType::find($t->type_id) : null]);
    }
}
