<?php

namespace App\Modules\Fournisseurs;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\PurchasePayment;
use App\Modules\Core\Models\Partner;
use App\Modules\Fournisseurs\Models\Access;
use App\Modules\Fournisseurs\Models\Bid;
use App\Modules\Fournisseurs\Models\OrderAck;
use App\Modules\Fournisseurs\Models\SupplierInvoice;
use App\Modules\Fournisseurs\Models\Tender;
use Illuminate\Http\Request;

/** Espace du fournisseur, ouvert par son lien personnel. */
class SupplierPortalController extends Controller
{
    public function __construct(private FournisseursService $svc) {}

    protected function ctx(string $token): array
    {
        abort_unless(strlen($token) >= 32, 404);
        $a = Access::withoutGlobalScopes()->where('token', $token)->where('active', true)->first();
        abort_unless($a, 404);
        $c = Company::find($a->company_id);
        abort_unless($c && $c->status !== 'suspended' && $c->hasModule('portail_fournisseurs'), 404);
        app()->instance('tenant', $c);
        $p = Partner::find($a->partner_id);
        abort_unless($p, 404);
        if (! $a->last_seen_at || $a->last_seen_at->lt(now()->subMinutes(10))) {
            $a->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return [$c, $a, $p];
    }

    protected function page(string $view, array $ctx, array $data = [])
    {
        [$c, $a, $p] = $ctx;

        return response()->view('fournisseurs.'.$view, $data + ['company' => $c, 'access' => $a, 'partner' => $p, 'token' => $a->token, 'tab' => $view,
            'ok' => session('ok'), 'error' => session('error')])->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
    }

    public function home(string $token)
    {
        $ctx = $this->ctx($token);
        [, , $p] = $ctx;
        $tenders = Tender::where('status', 'open')->where('deadline', '>', now())->get()->filter(fn ($t) => in_array($p->id, $t->invited ?? [], true))->sortBy('deadline')->values();
        $orders = $this->svc->orders($p);
        $acks = OrderAck::whereIn('order_id', $orders->pluck('id'))->orderByDesc('created_at')->get()->groupBy('order_id');
        $invoices = SupplierInvoice::where('partner_id', $p->id)->orderByDesc('created_at')->limit(5)->get();

        return $this->page('home', $ctx, ['tenders' => $tenders, 'bids' => Bid::where('partner_id', $p->id)->pluck('submitted_at', 'tender_id'),
            'toConfirm' => $orders->filter(fn ($o) => ! isset($acks[$o->id]))->values(), 'invoices' => $invoices, 'svc' => $this->svc]);
    }

    public function tenders(string $token)
    {
        $ctx = $this->ctx($token);
        [, , $p] = $ctx;
        $rows = Tender::whereIn('status', ['open', 'closed', 'awarded'])->orderByDesc('published_at')->get()->filter(fn ($t) => in_array($p->id, $t->invited ?? [], true))->values();

        return $this->page('tenders', $ctx, ['rows' => $rows, 'bids' => Bid::where('partner_id', $p->id)->get()->keyBy('tender_id')]);
    }

    public function tender(string $token, string $id)
    {
        $ctx = $this->ctx($token);
        [, , $p] = $ctx;
        $t = Tender::whereIn('status', ['open', 'closed', 'awarded'])->findOrFail($id);
        abort_unless(in_array($p->id, $t->invited ?? [], true), 404);

        return $this->page('tender', $ctx, ['t' => $t, 'bid' => Bid::where('tender_id', $t->id)->where('partner_id', $p->id)->first()]);
    }

    public function bid(Request $r, string $token, string $id)
    {
        [$c, , $p] = $this->ctx($token);
        $t = Tender::findOrFail($id);
        $d = $r->validate(['price' => 'required|array', 'note' => 'nullable|array', 'delivery_days' => 'nullable|integer|min:0|max:3650', 'valid_until' => 'nullable|date|after_or_equal:today', 'comment' => 'nullable|string|max:3000']);
        try {
            $this->svc->submitBid($c, $t, $p, $d['price'], ['notes' => $d['note'] ?? [], 'delivery_days' => $d['delivery_days'] ?? null, 'valid_until' => $d['valid_until'] ?? null, 'comment' => $d['comment'] ?? null]);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect('/fournisseur/'.$token.'/ao/'.$id)->with('ok', 'Offre enregistrée. Vous pouvez la modifier jusqu\'à la date limite.');
    }

    public function orders(string $token)
    {
        $ctx = $this->ctx($token);
        [, , $p] = $ctx;
        $orders = $this->svc->orders($p);

        return $this->page('orders', $ctx, ['rows' => $orders, 'acks' => OrderAck::whereIn('order_id', $orders->pluck('id'))->orderByDesc('created_at')->get()->groupBy('order_id')]);
    }

    public function order(string $token, string $id)
    {
        $ctx = $this->ctx($token);
        [, , $p] = $ctx;
        $o = PurchaseDocument::with('lines')->where('type', 'purchase_order')->where('partner_id', $p->id)->where('status', 'validated')->findOrFail($id);

        return $this->page('order', $ctx, ['o' => $o, 'acks' => OrderAck::where('order_id', $o->id)->orderByDesc('created_at')->get()]);
    }

    public function ack(Request $r, string $token, string $id)
    {
        [$c, , $p] = $this->ctx($token);
        $o = PurchaseDocument::where('type', 'purchase_order')->where('partner_id', $p->id)->findOrFail($id);
        $d = $r->validate(['decision' => 'required|in:accept,decline', 'delivery_date' => 'nullable|date', 'comment' => 'nullable|string|max:2000', 'name' => 'nullable|string|max:120']);
        try {
            $this->svc->acknowledge($c, $o, $p, $d['decision'] === 'accept', $d['delivery_date'] ?? null, $d['comment'] ?? null, $d['name'] ?? null);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect('/fournisseur/'.$token.'/commandes/'.$id)->with('ok', $d['decision'] === 'accept' ? 'Commande confirmée, merci.' : 'Refus transmis.');
    }

    public function invoices(string $token)
    {
        $ctx = $this->ctx($token);
        [, , $p] = $ctx;

        return $this->page('invoices', $ctx, ['rows' => SupplierInvoice::where('partner_id', $p->id)->orderByDesc('created_at')->limit(100)->get(), 'orders' => $this->svc->orders($p), 'svc' => $this->svc]);
    }

    public function deposit(Request $r, string $token)
    {
        [$c, , $p] = $this->ctx($token);
        $d = $r->validate(['number' => 'required|string|max:60', 'date' => 'required|date|before_or_equal:today', 'amount' => 'required|numeric|gt:0', 'order_id' => 'nullable|uuid',
            'comment' => 'nullable|string|max:2000', 'file' => 'required|file|max:10240'],
            ['number.required' => 'Indiquez le numéro de la facture.', 'file.required' => 'Joignez la facture (PDF ou image).', 'file.max' => 'Fichier trop lourd (10 Mo au plus).', 'date.before_or_equal' => 'La date de facture ne peut pas être dans le futur.']);
        try {
            $this->svc->depositInvoice($c, $p, $d, $r->file('file'));
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect('/fournisseur/'.$token.'/factures')->with('ok', 'Facture déposée : vous serez prévenu dès son traitement.');
    }

    public function payments(string $token)
    {
        $ctx = $this->ctx($token);
        [, , $p] = $ctx;
        $docs = PurchaseDocument::where('type', 'supplier_invoice')->where('partner_id', $p->id)->where('status', 'validated')->orderByDesc('date')->limit(100)->get();
        $payments = PurchasePayment::whereIn('document_id', $docs->pluck('id'))->orderByDesc('date')->get();

        return $this->page('payments', $ctx, ['docs' => $docs, 'payments' => $payments->groupBy('document_id'), 'paid' => $payments->sum('amount'), 'due' => $docs->sum(fn ($d) => max(0, $d->total - $d->amount_paid))]);
    }
}
