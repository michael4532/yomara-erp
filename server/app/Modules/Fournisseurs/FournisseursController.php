<?php

namespace App\Modules\Fournisseurs;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Partner;
use App\Modules\Fournisseurs\Models\Access;
use App\Modules\Fournisseurs\Models\SupplierInvoice;
use App\Modules\Fournisseurs\Models\Tender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FournisseursController extends Controller
{
    public function __construct(private FournisseursService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function stats()
    {
        return ['data' => $this->svc->stats()];
    }

    public function accesses()
    {
        $partners = Partner::whereIn('id', Access::pluck('partner_id'))->pluck('name', 'id');

        return ['data' => Access::orderByDesc('updated_at')->get()->map(fn ($a) => ['partner_id' => $a->partner_id, 'name' => $partners[$a->partner_id] ?? '—', 'active' => $a->active,
            'url' => $a->active ? $a->url() : null, 'last_seen_at' => $a->last_seen_at?->toIso8601String(), 'invited_by' => $a->invited_by])->values()];
    }

    public function invite(Request $r, string $partnerId)
    {
        $p = Partner::findOrFail($partnerId);
        $via = $r->boolean('send', true) ? $this->svc->invite(app('tenant'), $p) : (function () use ($p) {
            $this->svc->access($p);

            return [];
        })();
        $a = Access::where('partner_id', $p->id)->first();

        return ['url' => $a->url(), 'sent_via' => $via];
    }

    public function revoke(string $partnerId)
    {
        Access::where('partner_id', $partnerId)->update(['active' => false]);

        return ['ok' => true];
    }

    public function compare(string $id)
    {
        return ['data' => $this->svc->compare(Tender::findOrFail($id))];
    }

    public function award(Request $r, string $id)
    {
        $r->validate(['partner_id' => 'required|uuid']);

        return $this->guard(fn () => ['order' => $this->svc->award(app('tenant'), Tender::findOrFail($id), $r->input('partner_id'))->load('lines')->toSyncArray(), 'tender' => Tender::find($id)->toSyncArray()]);
    }

    public function invoiceFile(string $id)
    {
        $inv = SupplierInvoice::findOrFail($id);
        abort_unless($inv->file_path && Storage::disk('local')->exists($inv->file_path), 404);

        // Type déduit de l'extension contrôlée au dépôt (jamais du contenu) : un faux PDF/PNG contenant du HTML ne s'affiche pas
        $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][strtolower(pathinfo($inv->file_path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return Storage::disk('local')->response($inv->file_path, $inv->file_name, \App\Support\SafeDownload::headers($mime, (string) ($inv->file_name ?: basename($inv->file_path)), true));
    }

    public function match(string $id)
    {
        return ['data' => $this->svc->match(SupplierInvoice::findOrFail($id))];
    }

    /** Lit un PDF / une image de facture sans rien enregistrer : sert à pré-remplir le formulaire d'import. */
    public function read(Request $r)
    {
        $r->validate(['file' => 'required|file|max:10240']);
        $f = $r->file('file');
        $ext = strtolower($f->getClientOriginalExtension());
        if (! in_array($ext, FournisseursService::INVOICE_EXT, true)) {
            return response()->json(['message' => 'Importez la facture en PDF ou en image (JPG, PNG).'], 422);
        }
        $x = app(InvoiceReader::class)->read((string) file_get_contents($f->getRealPath()), $ext);
        unset($x['text']);

        return ['data' => $x + ['ocr' => app(InvoiceReader::class)->ocrAvailable()]];
    }

    /** Import d'une facture par l'acheteur (reçue par e-mail, papier scanné…) : même circuit que le portail. */
    public function upload(Request $r)
    {
        $d = $r->validate(['partner_id' => 'required|uuid', 'number' => 'nullable|string|max:60', 'date' => 'nullable|date|before_or_equal:today', 'amount' => 'nullable|numeric|gt:0',
            'order_id' => 'nullable|uuid', 'comment' => 'nullable|string|max:2000', 'file' => 'required|file|max:10240'],
            ['file.max' => 'Fichier trop lourd (10 Mo au plus).', 'date.before_or_equal' => 'La date de facture ne peut pas être dans le futur.']);
        $p = Partner::find($d['partner_id']);
        if (! $p) {
            return response()->json(['message' => 'Fournisseur introuvable.'], 422);
        }

        return $this->guard(function () use ($r, $d, $p) {
            $inv = $this->svc->depositInvoice(app('tenant'), $p, $d, $r->file('file'), 'upload', $r->user()->name);

            return response()->json(['data' => $inv->fresh()->toSyncArray()], 201);
        });
    }

    public function settings()
    {
        return ['data' => ['match_tolerance' => $this->svc->tolerance(app('tenant')), 'ocr' => app(InvoiceReader::class)->ocrAvailable()]];
    }

    public function saveSettings(Request $r)
    {
        $v = $r->validate(['match_tolerance' => 'required|numeric|min:0|max:20']);
        $c = app('tenant');
        $s = (array) ($c->settings ?? []);
        $s['fournisseurs'] = array_merge((array) ($s['fournisseurs'] ?? []), ['match_tolerance' => (float) $v['match_tolerance']]);
        $c->forceFill(['settings' => $s])->save();

        return $this->settings();
    }
}
