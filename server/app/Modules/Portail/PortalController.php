<?php

namespace App\Modules\Portail;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Core\Models\Partner;
use App\Modules\Logistique\Models\Delivery;
use App\Modules\Portail\Models\PortalAccess;
use App\Modules\Portail\Models\PortalEvent;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Payments\InvoicePayments;
use Illuminate\Http\Request;

/** Espace client public : accès par lien personnel, sans mot de passe. */
class PortalController extends Controller
{
    public function __construct(protected PortalService $portal) {}

    protected function page(string $view, array $ctx, array $data = [])
    {
        [$company, $access, $partner] = $ctx;
        $logistique = $company->hasModule('logistique');
        $unread = PortalEvent::where('partner_id', $partner->id)->where('type', 'reply')
            ->where('created_at', '>', $access->last_login_at ?? now()->subYears(5))->count();

        return response()->view('portail.'.$view, $data + [
            'company' => $company, 'access' => $access, 'partner' => $partner, 'token' => $access->token,
            'logistique' => $logistique, 'unreadReplies' => $unread, 'sav' => $company->hasModule('interventions'), 'rdv' => $company->hasModule('rendez_vous'), 'fid' => $company->hasModule('fidelite'), 'prest' => $company->hasModule('prestations'), 'support' => $company->hasModule('helpdesk'),
            'cur' => $company->currency,
            'fmt' => fn ($v, $c = null) => self::money($v, $c ?: $company->currency),
        ])->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
    }

    public static function money($v, string $cur): string
    {
        return number_format((float) $v, in_array($cur, ['XOF', 'XAF', 'GNF'], true) ? 0 : 2, ',', ' ').' '.($cur === 'XOF' || $cur === 'XAF' ? 'F CFA' : $cur);
    }

    protected function ctx(string $token): array
    {
        $ctx = $this->portal->resolve($token);
        [, $access, $partner] = $ctx;
        // Une « connexion » par tranche de 30 minutes
        if (! $access->last_login_at || $access->last_login_at->lt(now()->subMinutes(30))) {
            $access->forceFill(['last_login_at' => now(), 'login_count' => $access->login_count + 1])->save();
            $this->portal->log($partner->id, 'login');
        }

        return $ctx;
    }

    // ---------- Accès ----------

    public function login()
    {
        return response()->view('portail.login', ['sent' => session('sent')])->header('X-Robots-Tag', 'noindex');
    }

    /** Lien perdu : on renvoie le lien à l'adresse ou au numéro connu (réponse identique dans tous les cas). */
    public function sendLink(Request $request)
    {
        $contact = trim((string) $request->validate(['contact' => 'required|string|max:120'])['contact']);
        $isEmail = str_contains($contact, '@');
        $digits = preg_replace('/\D/', '', $contact);
        $q = PortalAccess::withoutGlobalScopes()->where('portal_accesses.active', true)
            ->join('partners', 'partners.id', '=', 'portal_accesses.partner_id')->whereNull('partners.deleted_at')
            ->select('portal_accesses.*', 'partners.email as p_email', 'partners.phone as p_phone');
        if ($isEmail) {
            $q->whereRaw('lower(partners.email) = ?', [mb_strtolower($contact)]);
        } elseif (strlen($digits) >= 8) {
            // Les numéros sont saisis avec ou sans espaces : on tolère tout séparateur entre les chiffres
            $q->where('partners.phone', 'like', '%'.implode('%', str_split(substr($digits, -6))));
        } else {
            $q->whereRaw('1 = 0');
        }
        foreach ($q->limit(50)->get() as $a) {
            if (! $isEmail && substr(preg_replace('/\D/', '', (string) $a->p_phone), -8) !== substr($digits, -8)) {
                continue;
            }
            $company = Company::find($a->company_id);
            if (! $company?->hasModule('portail')) {
                continue;
            }
            app()->instance('tenant', $company);
            $partner = Partner::find($a->partner_id);
            $channels = $partner ? $this->portal->channels($company, $partner) : [];
            $channel = $isEmail ? 'email' : collect($channels)->first(fn ($c) => $c !== 'email');
            if ($partner && $channel && in_array($channel, $channels, true)) {
                $this->portal->invite($company, $partner, $channel, reminder: true);
            }
        }

        return redirect('/portail')->with('sent', true);
    }

    // ---------- Pages ----------

    public function home(string $token)
    {
        $ctx = $this->ctx($token);
        [$company, , $partner] = $ctx;
        $docs = $this->portal->documents($partner)->get();
        $invoices = $docs->where('type', 'invoice');
        $unpaid = $invoices->filter(fn ($d) => $d->balance() > 0.009)->sortBy('due_date');
        $quotes = $docs->where('type', 'quote')->filter(fn ($q) => $this->portal->quoteState($q) === 'pending');
        $deliveries = $company->hasModule('logistique')
            ? Delivery::where('partner_id', $partner->id)->whereIn('status', ['planned', 'in_transit', 'failed'])->orderBy('scheduled_date')->limit(5)->get()
            : collect();

        return $this->page('home', $ctx, [
            'due' => round($unpaid->sum(fn ($d) => $d->balance()), 2),
            'overdue' => $unpaid->filter(fn ($d) => $d->due_date && $d->due_date->lt(today()))->sum(fn ($d) => $d->balance()),
            'unpaid' => $unpaid, 'quotes' => $quotes, 'deliveries' => $deliveries,
            'orders' => $docs->where('type', 'order')->take(3),
            'year' => round($invoices->filter(fn ($d) => $d->date->year === now()->year)->sum('total'), 2),
        ]);
    }

    public function documents(string $token, string $kind)
    {
        $types = ['factures' => ['invoice', 'credit_note'], 'devis' => ['quote'], 'commandes' => ['order']][$kind] ?? abort(404);
        $ctx = $this->ctx($token);
        $docs = $this->portal->documents($ctx[2], $types)->get();

        return $this->page('documents', $ctx, [
            'kind' => $kind, 'docs' => $docs,
            'states' => $kind === 'devis' ? $docs->mapWithKeys(fn ($q) => [$q->id => $this->portal->quoteState($q)]) : collect(),
        ]);
    }

    protected function doc(array $ctx, string $id): SalesDocument
    {
        $doc = $this->portal->documents($ctx[2])->with('lines')->find($id);
        abort_unless($doc, 404);

        return $doc;
    }

    public function document(Request $request, string $token, string $id)
    {
        $ctx = $this->ctx($token);
        $doc = $this->doc($ctx, $id);
        if (! PortalEvent::where('document_id', $doc->id)->where('type', 'view')->where('created_at', '>', now()->subHour())->exists()) {
            $this->portal->log($ctx[2]->id, 'view', $doc->id, ['number' => $doc->number]);
        }
        $payments = in_array($doc->type, SalesDocument::PAYABLE_TYPES, true) ? SalesPayment::where('document_id', $doc->id)->orderBy('date')->get() : collect();

        return $this->page('document', $ctx, [
            'doc' => $doc, 'payments' => $payments, 'print' => $request->boolean('print'),
            'state' => $doc->type === 'quote' ? $this->portal->quoteState($doc) : null,
            'answer' => $doc->type === 'quote' ? PortalEvent::where('document_id', $doc->id)->whereIn('type', ['quote_accepted', 'quote_refused'])->latest('id')->first() : null,
            'canPay' => $doc->type === 'invoice' && $doc->balance() > 0.009 && InvoicePayments::gateways($ctx[0])->isNotEmpty(),
            'bank_accounts' => collect((array) data_get($ctx[0]->settings, 'bank_accounts', []))->filter(fn ($b) => $b['on_invoices'] ?? true)->values(),
            'error' => session('error'), 'ok' => session('ok'),
        ]);
    }

    public function pay(string $token, string $id, InvoicePayments $payments)
    {
        $ctx = $this->ctx($token);
        $doc = $this->doc($ctx, $id);
        abort_unless($doc->type === 'invoice', 404);
        $this->portal->log($ctx[2]->id, 'pay', $doc->id, ['number' => $doc->number, 'amount' => $doc->balance()]);

        return redirect('/pay/'.$payments->link($doc)->token);
    }

    public function accept(Request $request, string $token, string $id)
    {
        $ctx = $this->ctx($token);
        $doc = $this->doc($ctx, $id);
        abort_unless($doc->type === 'quote', 404);
        $data = $request->validate(['signer' => 'required|string|max:120', 'agree' => 'accepted'], [
            'signer.required' => 'Indiquez votre nom.', 'agree.accepted' => 'Cochez la case « Bon pour accord ».',
        ]);
        try {
            $order = $this->portal->acceptQuote($ctx[0], $ctx[2], $doc, $data['signer']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/portail/'.$token.'/document/'.$doc->id)
            ->with('ok', 'Merci ! Votre accord est enregistré'.($order ? ' : commande '.$order->number.' confirmée.' : '.'));
    }

    public function refuse(Request $request, string $token, string $id)
    {
        $ctx = $this->ctx($token);
        $doc = $this->doc($ctx, $id);
        abort_unless($doc->type === 'quote', 404);
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;
        try {
            $this->portal->refuseQuote($ctx[0], $ctx[2], $doc, $reason);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/portail/'.$token.'/document/'.$doc->id)->with('ok', 'Votre réponse a été transmise. Merci.');
    }

    /** Relevé de compte : factures, avoirs et règlements avec solde progressif. */
    public function statement(Request $request, string $token)
    {
        $ctx = $this->ctx($token);
        $docs = $this->portal->documents($ctx[2], ['invoice', 'credit_note'])->get();
        $rows = collect();
        foreach ($docs as $d) {
            $rows->push(['date' => $d->date, 'label' => ($d->type === 'invoice' ? 'Facture ' : 'Avoir ').$d->number, 'debit' => $d->type === 'invoice' ? $d->total : 0, 'credit' => $d->type === 'credit_note' ? $d->total : 0, 'id' => $d->id]);
        }
        foreach (SalesPayment::whereIn('document_id', $docs->where('type', 'invoice')->pluck('id'))->get() as $p) {
            $num = $docs->firstWhere('id', $p->document_id)?->number;
            $rows->push(['date' => $p->date, 'label' => 'Règlement '.$num.($p->reference ? ' · '.$p->reference : ''), 'debit' => 0, 'credit' => $p->amount, 'id' => null]);
        }
        $balance = 0;
        $rows = $rows->sortBy(fn ($r) => $r['date']->format('Y-m-d').($r['debit'] > 0 ? '0' : '1'))->values()->map(function ($r) use (&$balance) {
            $balance += $r['debit'] - $r['credit'];

            return $r + ['balance' => round($balance, 2)];
        });

        return $this->page('statement', $ctx, ['rows' => $rows, 'balance' => round($balance, 2), 'print' => $request->boolean('print')]);
    }

    public function deliveries(string $token)
    {
        $ctx = $this->ctx($token);
        abort_unless($ctx[0]->hasModule('logistique'), 404);
        $rows = Delivery::where('partner_id', $ctx[2]->id)->where('status', '!=', 'cancelled')->orderByDesc('scheduled_date')->limit(50)->get();

        return $this->page('deliveries', $ctx, ['rows' => $rows]);
    }

    // ---------- Fidélité ----------

    public function loyalty(string $token)
    {
        $ctx = $this->ctx($token);
        abort_unless($ctx[0]->hasModule('fidelite'), 404);
        $svc = app(\App\Modules\Fidelite\LoyaltyService::class);

        return $this->page('loyalty', $ctx, ['sum' => $svc->summary($ctx[0], $ctx[2]), 'settings' => $svc->settings($ctx[0]),
            'rewards' => \App\Modules\Fidelite\Models\Reward::where('active', true)->orderBy('points_cost')->get(),
            'gifts' => \App\Modules\Fidelite\Models\GiftCard::where('partner_id', $ctx[2]->id)->whereIn('status', ['active', 'used'])->latest()->get()]);
    }

    // ---------- Forfaits & livrables ----------

    public function prestations(string $token)
    {
        $ctx = $this->ctx($token);
        abort_unless($ctx[0]->hasModule('prestations'), 404);
        $packs = \App\Modules\Prestations\Models\Pack::where('partner_id', $ctx[2]->id)->where('status', '<>', 'cancelled')->orderByDesc('start_date')->limit(20)->get();
        $entries = \App\Modules\Prestations\Models\Entry::whereIn('pack_id', $packs->pluck('id'))->orderByDesc('date')->limit(200)->get()->groupBy('pack_id');
        $deliverables = \App\Modules\Prestations\Models\Deliverable::where('partner_id', $ctx[2]->id)->whereNotNull('sent_at')->orderByDesc('sent_at')->limit(50)->get();

        $reports = \App\Modules\Prestations\Models\Report::where('partner_id', $ctx[2]->id)->whereNotNull('file_path')->orderByDesc('period')->limit(24)->get();

        return $this->page('prestations', $ctx, ['packs' => $packs, 'entries' => $entries, 'deliverables' => $deliverables, 'reports' => $reports]);
    }

    public function support(string $token)
    {
        $ctx = $this->ctx($token);
        abort_unless($ctx[0]->hasModule('helpdesk'), 404);
        $rows = \App\Modules\Helpdesk\Models\Ticket::where('partner_id', $ctx[2]->id)->orderByDesc('created_at')->limit(50)->get();

        return $this->page('support', $ctx, ['rows' => $rows, 'newUrl' => url('/aide/'.$ctx[0]->slug.'/contact').'?portal='.$token, 'helpUrl' => url('/aide/'.$ctx[0]->slug)]);
    }

    // ---------- Rendez-vous ----------

    public function appointments(string $token)
    {
        $ctx = $this->ctx($token);
        abort_unless($ctx[0]->hasModule('rendez_vous'), 404);
        $rows = \App\Modules\RendezVous\Models\Appointment::where('partner_id', $ctx[2]->id)->orderByDesc('starts_at')->limit(50)->get();
        $services = \App\Modules\RendezVous\Models\Service::pluck('name', 'id');

        return $this->page('appointments', $ctx, ['rows' => $rows, 'services' => $services, 'tz' => \App\Modules\RendezVous\Models\Appointment::tz(),
            'bookUrl' => url('/rdv/'.$ctx[0]->slug).'?portal='.$token]);
    }

    // ---------- Interventions (SAV) ----------

    public function interventions(string $token)
    {
        $ctx = $this->ctx($token);
        abort_unless($ctx[0]->hasModule('interventions'), 404);
        $rows = \App\Modules\Interventions\Models\Intervention::where('partner_id', $ctx[2]->id)->orderByDesc('created_at')->limit(50)->get();
        $equipments = \App\Modules\Interventions\Models\Equipment::where('partner_id', $ctx[2]->id)->where('status', '!=', 'retired')->orderBy('name')->get();
        $contracts = \App\Modules\Interventions\Models\Contract::where('partner_id', $ctx[2]->id)->where('status', 'active')->get();

        return $this->page('interventions', $ctx, ['rows' => $rows, 'equipments' => $equipments, 'contracts' => $contracts, 'ok' => session('ok')]);
    }

    public function requestIntervention(Request $request, string $token)
    {
        $ctx = $this->ctx($token);
        abort_unless($ctx[0]->hasModule('interventions'), 404);
        $data = $request->validate([
            'title' => 'required|string|max:150', 'description' => 'nullable|string|max:3000', 'priority' => 'nullable|integer|between:0,3',
            'equipment_id' => 'nullable|uuid', 'address' => 'nullable|string|max:255', 'contact_phone' => 'nullable|string|max:40',
        ], ['title.required' => 'Décrivez le problème en quelques mots.']);
        if (! empty($data['equipment_id']) && ! \App\Modules\Interventions\Models\Equipment::where('partner_id', $ctx[2]->id)->whereKey($data['equipment_id'])->exists()) {
            $data['equipment_id'] = null;
        }
        $i = app(\App\Modules\Interventions\InterventionService::class)->request($ctx[0], $ctx[2], array_filter($data, fn ($v) => $v !== null && $v !== ''));
        $this->portal->log($ctx[2]->id, 'message', null, ['subject' => 'Demande d\'intervention '.$i->number, 'body' => $i->title.($i->description ? "\n".$i->description : '')]);

        return redirect('/portail/'.$token.'/interventions')->with('ok', 'Demande '.$i->number.' enregistrée. Nous vous recontactons pour fixer le rendez-vous.');
    }

    public function messages(string $token)
    {
        $ctx = $this->ctx($token);
        $events = PortalEvent::where('partner_id', $ctx[2]->id)->whereIn('type', ['message', 'reply'])->orderBy('id')->get();
        $docs = $this->portal->documents($ctx[2])->limit(30)->get();

        return $this->page('messages', $ctx, ['events' => $events, 'docs' => $docs, 'ok' => session('ok'), 'ref' => request('document')]);
    }

    public function postMessage(Request $request, string $token)
    {
        $ctx = $this->ctx($token);
        $data = $request->validate(['subject' => 'required|string|max:150', 'body' => 'required|string|max:5000', 'document_id' => 'nullable|uuid'],
            ['subject.required' => 'Indiquez l\'objet.', 'body.required' => 'Écrivez votre message.']);
        $docId = $data['document_id'] ?? null;
        if ($docId && ! $this->portal->documents($ctx[2])->whereKey($docId)->exists()) {
            $docId = null;
        }
        $this->portal->message($ctx[0], $ctx[2], $data['subject'], $data['body'], $docId);

        return redirect('/portail/'.$token.'/messages')->with('ok', 'Message envoyé. Nous vous répondons au plus vite.');
    }
}
