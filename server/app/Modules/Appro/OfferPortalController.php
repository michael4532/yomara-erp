<?php

namespace App\Modules\Appro;

use App\Http\Controllers\Controller;
use App\Modules\Appro\Models\OfferMessage;
use App\Modules\Appro\Models\Spec;
use Illuminate\Http\Request;

/** Lien personnel d'un fournisseur consulté : il dépose son offre, pose ses questions et répond à celles de l'acheteur. */
class OfferPortalController extends Controller
{
    public function __construct(private OfferService $offers, private SourcingService $sourcing) {}

    public function show(string $token)
    {
        [$c, $inv, $cmp] = $this->offers->context($token);
        $this->offers->opened($inv);
        $offer = collect((array) $cmp->offers)->firstWhere('key', $inv->offer_key);
        $spec = $cmp->spec_id ? Spec::find($cmp->spec_id) : null;
        $currencies = array_values(array_unique(array_filter([$c->currency, $offer['currency'] ?? null, 'EUR', 'USD', 'CNY'])));

        return response()->view('appro.offer', ['company' => $c, 'inv' => $inv, 'cmp' => $cmp, 'offer' => $offer, 'spec' => $spec, 'open' => $this->offers->isOpen($cmp),
            'answered' => $inv->status === 'answered', 'currencies' => $currencies, 'won' => $cmp->status === 'awarded' && $cmp->awarded === $inv->offer_key,
            'messages' => OfferMessage::where('comparison_id', $cmp->id)->where('invite_id', $inv->id)->orderBy('id')->get(),
            'ok' => session('ok'), 'error' => session('error')])->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
    }

    public function submit(Request $r, string $token)
    {
        [$c, $inv, $cmp] = $this->offers->context($token);
        $d = $r->validate(['price' => 'required|array', 'note' => 'nullable|array', 'note.*' => 'nullable|string|max:200', 'currency' => 'nullable|string|size:3', 'delay_days' => 'nullable|integer|min:0|max:3650',
            'extra' => 'nullable|string|max:20', 'payment_terms' => 'nullable|string|max:150', 'validity' => 'nullable|date', 'comment' => 'nullable|string|max:3000', 'compliant' => 'nullable', 'file' => 'nullable|file|max:10240']);
        try {
            $this->offers->submit($c, $inv, $cmp, $d, $r->file('file'));
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect('/offre/'.$token)->with('ok', 'Merci, votre offre est enregistrée. Vous pouvez la modifier jusqu\'à la date limite.');
    }

    public function decline(Request $r, string $token)
    {
        [$c, $inv, $cmp] = $this->offers->context($token);
        try {
            $this->offers->decline($c, $inv, $cmp, $r->input('reason'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/offre/'.$token)->with('ok', 'C\'est noté : vous ne donnez pas suite. Merci de nous avoir répondu.');
    }

    public function message(Request $r, string $token)
    {
        [$c, $inv, $cmp] = $this->offers->context($token);
        $r->validate(['body' => 'required|string|max:5000']);
        try {
            $this->offers->supplierMessage($c, $inv, $cmp, (string) $r->input('body'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect('/offre/'.$token.'#messages')->with('ok', 'Message envoyé.');
    }

    public function specPdf(string $token)
    {
        [$c, , $cmp] = $this->offers->context($token);
        $s = $cmp->spec_id ? Spec::find($cmp->spec_id) : null;
        abort_unless($s, 404);

        return response($this->sourcing->specPdf($c, $s), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="cahier-des-charges.pdf"']);
    }
}
