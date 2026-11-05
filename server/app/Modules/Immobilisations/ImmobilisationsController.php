<?php

namespace App\Modules\Immobilisations;

use App\Http\Controllers\Controller;
use App\Modules\Immobilisations\Models\Asset;
use App\Modules\Immobilisations\Models\AssetLine;
use Illuminate\Http\Request;

class ImmobilisationsController extends Controller
{
    public function __construct(private AssetService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function settings()
    {
        return ['data' => $this->svc->settings(app('tenant')) + ['stats' => $this->svc->stats(app('tenant'))]];
    }

    public function updateSettings(Request $r)
    {
        $v = $r->validate([
            'frequency' => 'in:annual,monthly', 'auto_post' => 'boolean',
            'counterpart' => ['regex:/^[1-9]\d{1,9}$/'], 'sale_account' => ['regex:/^[1-9]\d{1,9}$/'],
        ]);
        $c = app('tenant');
        $s = $c->settings ?? [];
        $s['immo'] = array_merge($this->svc->settings($c), $v);
        $c->forceFill(['settings' => $s])->save();

        return $this->settings();
    }

    /** Fiche : plan d'amortissement complet. */
    public function show(string $id)
    {
        $a = Asset::withTrashed()->findOrFail($id);

        return ['data' => $a->toSyncArray() + ['lines' => AssetLine::where('asset_id', $a->id)->orderBy('seq')->get()]];
    }

    /** Simulation du plan (formulaire, avant mise en service). */
    public function preview(Request $r)
    {
        $v = $r->validate([
            'value' => 'required|numeric|min:0', 'salvage_value' => 'nullable|numeric|min:0', 'method' => 'required|in:linear,degressive,none',
            'years' => 'required|integer|min:1|max:100', 'coefficient' => 'nullable|numeric|min:1|max:4', 'in_service_date' => 'required|date',
            'acquisition_date' => 'nullable|date', 'frequency' => 'nullable|in:annual,monthly',
        ]);

        return ['data' => Depreciation::schedule([
            'value' => $v['value'], 'salvage' => $v['salvage_value'] ?? 0, 'method' => $v['method'], 'years' => $v['years'], 'coefficient' => $v['coefficient'] ?? null,
            'in_service_date' => $v['in_service_date'], 'acquisition_date' => $v['acquisition_date'] ?? null, 'frequency' => $v['frequency'] ?? 'annual',
            'decimals' => \App\Modules\Core\Models\TradeDocumentLine::decimalsFor(app('tenant')->currency),
        ]), 'coefficient' => Depreciation::defaultCoefficient((int) $v['years'])];
    }

    public function depreciate(Request $r)
    {
        $v = $r->validate(['until' => 'nullable|date', 'asset_id' => 'nullable|uuid']);

        return $this->guard(fn () => ['data' => $this->svc->postDue(app('tenant'), $v['until'] ?? now()->toDateString(), $v['asset_id'] ?? null)]);
    }

    public function unpost(string $id)
    {
        return $this->guard(function () use ($id) {
            $this->svc->unpostLast(Asset::findOrFail($id));

            return $this->show($id);
        });
    }

    public function dispose(Request $r, string $id)
    {
        $v = $r->validate(['date' => 'required|date', 'type' => 'required|in:sale,scrap', 'amount' => 'nullable|numeric|min:0', 'partner_id' => 'nullable|uuid', 'note' => 'nullable|string|max:255']);

        return $this->guard(function () use ($v, $id) {
            $this->svc->dispose(Asset::findOrFail($id), $v);

            return $this->show($id);
        });
    }

    public function cancelDisposal(string $id)
    {
        return $this->guard(function () use ($id) {
            $this->svc->cancelDisposal(Asset::findOrFail($id));

            return $this->show($id);
        });
    }

    public function fromDocument(Request $r)
    {
        $v = $r->validate(['document_id' => 'required|uuid', 'category_id' => 'required|uuid', 'line_ids' => 'nullable|array']);

        return $this->guard(function () use ($v) {
            $doc = \App\Modules\Achats\Models\PurchaseDocument::with('lines')->findOrFail($v['document_id']);
            $assets = $this->svc->fromDocument($doc, $v['category_id'], $v['line_ids'] ?? []);

            return response()->json(['data' => array_map(fn ($a) => $a->toSyncArray(), $assets)], 201);
        });
    }

    public function register(Request $r)
    {
        $year = (int) ($r->validate(['year' => 'nullable|integer|min:2000|max:2100'])['year'] ?? now()->year);

        return ['data' => $this->svc->register(app('tenant'), $year)];
    }

    public function export(Request $r)
    {
        $year = (int) ($r->validate(['year' => 'nullable|integer|min:2000|max:2100'])['year'] ?? now()->year);
        $reg = $this->svc->register(app('tenant'), $year);
        $headers = ['Code', 'Désignation', 'Catégorie', 'Compte', 'Acquisition', 'Méthode', 'Durée (ans)', 'Brut début', 'Acquisitions', 'Sorties', 'Brut fin',
            'Amort. début', 'Dotations', 'Reprises (sorties)', 'Amort. fin', 'VNC fin', 'Date de sortie', 'Prix de cession'];
        $rows = [];
        foreach ($reg['assets'] as $a) {
            $rows[] = [$a['code'], $a['name'], $a['category'], $a['gl_asset'], $a['acquisition_date'], $a['method'] === 'degressive' ? 'Dégressif' : ($a['method'] === 'none' ? 'Non amortissable' : 'Linéaire'),
                $a['years'], $a['gross_start'], $a['additions'], $a['disposals'], $a['gross_end'], $a['dep_start'], $a['dep_year'], $a['dep_reversal'], $a['dep_end'], $a['nbv_end'],
                $a['disposal_date'], $a['disposal_amount']];
        }
        $t = $reg['totals'];
        $rows[] = ['TOTAL', '', '', '', '', '', '', $t['gross_start'], $t['additions'], $t['disposals'], $t['gross_end'], $t['dep_start'], $t['dep_year'], $t['dep_reversal'], $t['dep_end'], $t['nbv_end'], '', ''];
        $path = tempnam(sys_get_temp_dir(), 'imm').'.xlsx';
        \App\Modules\ImportExport\Sheet::write($path, $headers, $rows);

        return response()->download($path, 'tableau_immobilisations_'.$year.'.xlsx')->deleteFileAfterSend();
    }
}
