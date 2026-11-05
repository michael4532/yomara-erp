<?php

namespace App\Modules\Immobilisations;

use App\Models\Company;
use App\Models\Sequence;
use App\Modules\Compta\Posting;
use App\Modules\Core\Models\TradeDocumentLine;
use App\Modules\Immobilisations\Models\Asset;
use App\Modules\Immobilisations\Models\AssetCategory;
use App\Modules\Immobilisations\Models\AssetLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Immobilisations : mise en service (plan d'amortissement figé), dotations
 * comptabilisées (D 681 / C 28x), acquisition (D 2x / C 481), sortie
 * (dotation complémentaire, puis D 28x + D 81 VNC / C 2x ; cession : D 485 / C 82).
 */
class AssetService
{
    public function settings(Company $company): array
    {
        $s = (array) data_get($company->settings, 'immo', []);

        return [
            'frequency' => in_array($s['frequency'] ?? null, ['annual', 'monthly'], true) ? $s['frequency'] : 'annual',
            'auto_post' => (bool) ($s['auto_post'] ?? false),
            'counterpart' => $s['counterpart'] ?? \App\Modules\Compta\Chart::code($company, '481'),
            'sale_account' => $s['sale_account'] ?? \App\Modules\Compta\Chart::code($company, '485'),
        ];
    }

    public function setup(Company $company): void
    {
        if (AssetCategory::withTrashed()->exists()) {
            return;
        }
        foreach (AssetCategory::DEFAULTS as [$name, $gl, $dep, $years, $method]) {
            $c = new AssetCategory(['name' => $name, 'gl_asset' => \App\Modules\Compta\Chart::code($company, $gl), 'gl_depreciation' => \App\Modules\Compta\Chart::code($company, $dep),
                'gl_expense' => \App\Modules\Compta\Chart::code($company, '681'), 'years' => $years, 'method' => $method]);
            $c->save();
        }
    }

    private function decimals(): int
    {
        return TradeDocumentLine::decimalsFor(app('tenant')->currency);
    }

    // ------------------------------------------------------------------
    // Mise en service
    // ------------------------------------------------------------------

    public function activate(Asset $a): Asset
    {
        $cat = $a->category_id ? AssetCategory::withTrashed()->find($a->category_id) : null;
        if (! $cat) {
            throw new \DomainException('Choisissez la catégorie de l\'immobilisation.');
        }
        if ($a->value <= 0) {
            throw new \DomainException('Indiquez la valeur d\'acquisition (HT).');
        }
        if ($a->salvage_value >= $a->value) {
            throw new \DomainException('La valeur résiduelle doit être inférieure à la valeur d\'acquisition.');
        }
        $a->acquisition_date ??= $a->in_service_date ?? now();
        $a->in_service_date ??= $a->acquisition_date;
        if ($a->in_service_date->lt($a->acquisition_date)) {
            throw new \DomainException('La mise en service ne peut pas précéder l\'acquisition.');
        }
        if ($a->method === 'degressive' && $a->years < 3) {
            throw new \DomainException('L\'amortissement dégressif demande une durée d\'au moins 3 ans.');
        }
        if ($a->opening_date && $a->opening_date->lt($a->in_service_date)) {
            $a->opening_date = null;
        }
        $a->code ??= Sequence::next($a->company_id, 'immo', 'IMM', (int) $a->acquisition_date->format('Y'));
        $a->status = 'running';
        $a->save();

        $this->buildSchedule($a);
        if ($a->opening_date) {
            // Reprise : amortissements antérieurs déjà constatés dans l'ancien système
            AssetLine::where('asset_id', $a->id)->where('period_end', '<=', $a->opening_date->toDateString())
                ->update(['kind' => 'opening', 'posted_at' => now()]);
        } elseif ($a->post_acquisition) {
            $this->postAcquisition($a);
        }

        return $a->fresh();
    }

    /** (Re)calcule les lignes non comptabilisées. $until : date de sortie. */
    public function buildSchedule(Asset $a, ?string $until = null): void
    {
        $all = Depreciation::schedule([
            'value' => $a->value, 'salvage' => $a->salvage_value, 'method' => $a->method, 'years' => $a->years, 'coefficient' => $a->coefficient,
            'in_service_date' => $a->in_service_date->toDateString(), 'acquisition_date' => $a->acquisition_date?->toDateString(),
            'frequency' => $a->frequency, 'until' => $until, 'decimals' => $this->decimals(),
        ]);
        $posted = AssetLine::where('asset_id', $a->id)->whereNotNull('posted_at')->orderBy('seq')->get();
        $lastEnd = $posted->last()?->period_end;
        AssetLine::where('asset_id', $a->id)->whereNull('posted_at')->delete();
        $seq = (int) ($posted->max('seq') ?? 0);
        $cum = (float) $posted->sum('amount');
        $dec = $this->decimals();
        foreach ($all as $l) {
            if ($lastEnd && $l['period_end'] <= $lastEnd->toDateString()) {
                continue;
            }
            $cum = round($cum + $l['amount'], $dec);
            $line = new AssetLine(['asset_id' => $a->id, 'seq' => ++$seq, 'period_start' => $l['period_start'], 'period_end' => $l['period_end'],
                'amount' => $l['amount'], 'cumulated' => $cum, 'nbv' => round($a->value - $cum, $dec)]);
            $line->company_id = $a->company_id;
            $line->save();
        }
    }

    // ------------------------------------------------------------------
    // Écritures
    // ------------------------------------------------------------------

    private function posting(): ?Posting
    {
        return app('tenant')->hasModule('compta') && class_exists(Posting::class) ? app(Posting::class) : null;
    }

    public function postAcquisition(Asset $a): void
    {
        $cat = AssetCategory::withTrashed()->find($a->category_id);
        $counter = $a->acquisition_account ?: $this->settings(app('tenant'))['counterpart'];
        $this->posting()?->postExternal(app('tenant'), 'OD', $a->acquisition_date->toDateString(), 'Acquisition '.$a->code.' '.$a->name, $a->code, [
            [$cat->gl_asset, $a->value, 'Immobilisation '.$a->name],
            [$counter, -$a->value, 'Acquisition '.$a->code.' '.$a->name],
        ], 'asset', $a->id, 'acquisition');
    }

    public function postLine(Asset $a, AssetLine $l): void
    {
        if ($l->posted_at) {
            return;
        }
        $cat = AssetCategory::withTrashed()->find($a->category_id);
        $label = 'Dotation '.$a->code.' '.$a->name.' ('.$l->period_start->format('d/m/Y').' - '.$l->period_end->format('d/m/Y').')';
        $this->posting()?->postExternal(app('tenant'), 'OD', $l->period_end->toDateString(), $label, $a->code, [
            [$cat->gl_expense, $l->amount, $label],
            [$cat->gl_depreciation, -$l->amount, $label],
        ], 'asset_dep', (string) $l->id, 'depreciation');
        $l->posted_at = now();
        $l->save();
    }

    /** Comptabilise les dotations échues (période terminée au plus tard le $until). */
    public function postDue(Company $company, string $until, ?string $assetId = null): array
    {
        $n = 0;
        $total = 0.0;
        $touched = [];
        $lines = AssetLine::whereNull('posted_at')->where('period_end', '<=', $until)
            ->whereIn('asset_id', Asset::where('status', 'running')->when($assetId, fn ($q) => $q->whereKey($assetId))->select('id'))
            ->orderBy('period_end')->get();
        foreach ($lines as $l) {
            $a = Asset::find($l->asset_id);
            DB::transaction(fn () => $this->postLine($a, $l));
            $n++;
            $total += $l->amount;
            $touched[$a->id] = $a;
        }
        foreach ($touched as $a) {
            $this->syncAnalytic($a);
        }

        return ['count' => $n, 'total' => round($total, 2)];
    }

    /** Annule la dernière dotation comptabilisée (correction). */
    public function unpostLast(Asset $a): AssetLine
    {
        if ($a->status !== 'running') {
            throw new \DomainException('Seule une immobilisation en service peut être corrigée.');
        }
        $l = AssetLine::where('asset_id', $a->id)->whereNotNull('posted_at')->where('kind', 'normal')->orderByDesc('seq')->first();
        if (! $l) {
            throw new \DomainException('Aucune dotation à annuler.');
        }
        $this->posting()?->cancelExternal(app('tenant'), 'asset_dep', (string) $l->id, 'depreciation', 'Annulation dotation '.$a->code, false);
        $l->posted_at = null;
        $l->save();
        $this->syncAnalytic($a);

        return $l;
    }

    // ------------------------------------------------------------------
    // Sortie
    // ------------------------------------------------------------------

    public function dispose(Asset $a, array $d): Asset
    {
        if ($a->status !== 'running') {
            throw new \DomainException('Seule une immobilisation en service peut sortir.');
        }
        $date = Carbon::parse($d['date']);
        if ($date->lt($a->in_service_date)) {
            throw new \DomainException('La date de sortie précède la mise en service.');
        }
        $lastPosted = AssetLine::where('asset_id', $a->id)->whereNotNull('posted_at')->max('period_end');
        if ($lastPosted && $date->toDateString() < Carbon::parse($lastPosted)->toDateString()) {
            throw new \DomainException('Une dotation est déjà comptabilisée jusqu\'au '.Carbon::parse($lastPosted)->format('d/m/Y').' : annulez-la ou choisissez une date ultérieure.');
        }
        $type = $d['type'] === 'sale' ? 'sale' : 'scrap';
        $amount = $type === 'sale' ? round((float) ($d['amount'] ?? 0), 2) : 0.0;

        return DB::transaction(function () use ($a, $date, $type, $amount, $d) {
            // Dotation complémentaire jusqu'à la date de sortie
            $this->buildSchedule($a, $date->toDateString());
            foreach (AssetLine::where('asset_id', $a->id)->whereNull('posted_at')->orderBy('seq')->get() as $l) {
                $this->postLine($a, $l);
            }
            $cum = (float) AssetLine::where('asset_id', $a->id)->whereNotNull('posted_at')->sum('amount');
            $nbv = round($a->value - $cum, 2);
            $cat = AssetCategory::withTrashed()->find($a->category_id);
            $label = ($type === 'sale' ? 'Cession ' : 'Mise au rebut ').$a->code.' '.$a->name;
            $lines = [
                [$cat->gl_depreciation, $cum, 'Amortissements '.$a->code],
                [\App\Modules\Compta\Chart::local('81'), $nbv, 'Valeur comptable '.$a->code],
                [$cat->gl_asset, -$a->value, $label],
            ];
            if ($amount > 0) {
                $lines[] = [$this->settings(app('tenant'))['sale_account'], $amount, 'Prix de cession '.$a->code];
                $lines[] = [\App\Modules\Compta\Chart::local('82'), -$amount, 'Produit de cession '.$a->code];
            }
            $this->posting()?->postExternal(app('tenant'), 'OD', $date->toDateString(), $label, $a->code, $lines, 'asset', $a->id, 'disposal');

            $a->status = 'disposed';
            $a->disposal_date = $date;
            $a->disposal_type = $type;
            $a->disposal_amount = $amount;
            $a->disposal_partner_id = $d['partner_id'] ?? null;
            $a->disposal_note = isset($d['note']) ? mb_substr((string) $d['note'], 0, 255) : null;
            $a->save();
            $this->syncAnalytic($a);

            return $a->fresh();
        });
    }

    /** Annule une sortie (erreur de saisie) : contre-passation, retour en service, plan recalculé. */
    public function cancelDisposal(Asset $a): Asset
    {
        if ($a->status !== 'disposed') {
            throw new \DomainException('Cette immobilisation n\'est pas sortie.');
        }

        return DB::transaction(function () use ($a) {
            $this->posting()?->cancelExternal(app('tenant'), 'asset', $a->id, 'disposal', 'Annulation sortie '.$a->code, true);
            // La dotation complémentaire de la dernière période est annulée et recalculée
            $last = AssetLine::where('asset_id', $a->id)->whereNotNull('posted_at')->where('kind', 'normal')->orderByDesc('seq')->first();
            if ($last && $last->period_end->toDateString() === $a->disposal_date->toDateString()) {
                $this->posting()?->cancelExternal(app('tenant'), 'asset_dep', (string) $last->id, 'depreciation', 'Annulation dotation '.$a->code, true);
                $last->delete();
            }
            $a->forceFill(['status' => 'running', 'disposal_date' => null, 'disposal_type' => null, 'disposal_amount' => null, 'disposal_partner_id' => null, 'disposal_note' => null])->save();
            $this->buildSchedule($a);
            $this->syncAnalytic($a);

            return $a->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Analytique
    // ------------------------------------------------------------------

    public function syncAnalytic(Asset $a): void
    {
        if (! app('tenant')->hasModule('analytique') || ! class_exists(\App\Modules\Analytique\AnalyticService::class)) {
            return;
        }
        $cat = AssetCategory::withTrashed()->find($a->category_id);
        $entries = AssetLine::where('asset_id', $a->id)->whereNotNull('posted_at')->where('kind', 'normal')->orderBy('seq')->get()
            ->map(fn ($l) => ['date' => $l->period_end->toDateString(), 'amount' => -$l->amount, 'account' => $cat?->gl_expense ?? \App\Modules\Compta\Chart::local('681'),
                'label' => 'Dotation '.$a->code.' '.$a->name, 'line_id' => (string) $l->id])->all();
        $sections = app(\App\Modules\Analytique\AnalyticService::class)->syncSource('asset', $a->id, $entries, $a->analytic);
        if ($sections && class_exists(\App\Modules\Analytique\BudgetService::class)) {
            app(\App\Modules\Analytique\BudgetService::class)->checkAlerts(app('tenant'), $sections);
        }
    }

    // ------------------------------------------------------------------
    // Création depuis une facture fournisseur
    // ------------------------------------------------------------------

    /** Brouillons d'immobilisations à partir des lignes d'une facture fournisseur validée (reclassement 60x → 2x). */
    public function fromDocument(\App\Modules\Achats\Models\PurchaseDocument $doc, string $categoryId, array $lineIds = []): array
    {
        if ($doc->type !== 'supplier_invoice' || $doc->status !== 'validated') {
            throw new \DomainException('Choisissez une facture fournisseur validée.');
        }
        $cat = AssetCategory::findOrFail($categoryId);
        $map = class_exists(\App\Modules\Compta\Chart::class) ? \App\Modules\Compta\Chart::mapping(app('tenant')) : ['purchases_goods' => '601', 'purchases_services' => '605'];
        $products = \App\Modules\Core\Models\Product::withoutGlobalScopes()->whereIn('id', $doc->lines->pluck('product_id')->filter())->pluck('type', 'id');
        $out = [];
        foreach ($doc->lines as $l) {
            if ($lineIds && ! in_array($l->id, $lineIds, true)) {
                continue;
            }
            if (Asset::where('source_document_id', $doc->id)->where('notes', 'like', '%[ligne '.$l->id.']%')->exists()) {
                continue;
            }
            $a = new Asset([
                'name' => mb_substr($l->description, 0, 255), 'category_id' => $cat->id, 'acquisition_date' => $doc->date->toDateString(),
                'in_service_date' => $doc->date->toDateString(), 'value' => (float) $l->subtotal, 'method' => $cat->method, 'years' => $cat->years,
                'coefficient' => $cat->coefficient, 'partner_id' => $doc->partner_id, 'post_acquisition' => app('tenant')->hasModule('compta'),
                'acquisition_account' => $map[($products[$l->product_id] ?? 'product') === 'service' ? 'purchases_services' : 'purchases_goods'],
                'analytic' => $l->analytic ?: $doc->analytic, 'frequency' => $this->settings(app('tenant'))['frequency'],
                'notes' => 'Facture '.$doc->number.' — '.$doc->partner_name.' [ligne '.$l->id.']',
            ]);
            $a->source_document_id = $doc->id;
            $a->created_by = auth()->id();
            $a->save();
            $out[] = $a;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Tableau des immobilisations
    // ------------------------------------------------------------------

    public function register(Company $company, int $year): array
    {
        $start = Carbon::create($year, 1, 1)->toDateString();
        $end = Carbon::create($year, 12, 31)->toDateString();
        $assets = Asset::with('category')->where('status', '!=', 'draft')->where('acquisition_date', '<=', $end)
            ->where(fn ($q) => $q->whereNull('disposal_date')->orWhere('disposal_date', '>=', $start))->orderBy('code')->get();
        $lines = AssetLine::whereIn('asset_id', $assets->pluck('id'))->get()->groupBy('asset_id');
        $rows = [];
        foreach ($assets as $a) {
            $ls = $lines->get($a->id, collect());
            $before = (float) $ls->filter(fn ($l) => $l->period_end->toDateString() < $start)->sum('amount');
            $dot = (float) $ls->filter(fn ($l) => $l->period_end->toDateString() >= $start && $l->period_end->toDateString() <= $end)->sum('amount');
            $postedYear = (float) $ls->filter(fn ($l) => $l->posted_at && $l->period_end->toDateString() >= $start && $l->period_end->toDateString() <= $end)->sum('amount');
            $acquired = $a->acquisition_date->toDateString() >= $start;
            $out = $a->disposal_date && $a->disposal_date->toDateString() <= $end;
            $grossStart = $acquired ? 0 : $a->value;
            $grossEnd = $out ? 0 : $a->value;
            $depEnd = $out ? 0 : $before + $dot;
            $rows[] = [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'category' => $a->category?->name, 'gl_asset' => $a->category?->gl_asset,
                'acquisition_date' => $a->acquisition_date->toDateString(), 'method' => $a->method, 'years' => $a->years,
                'gross_start' => $grossStart, 'additions' => $acquired ? $a->value : 0, 'disposals' => $out ? $a->value : 0, 'gross_end' => $grossEnd,
                'dep_start' => round($before, 2), 'dep_year' => round($dot, 2), 'dep_posted' => round($postedYear, 2),
                'dep_reversal' => $out ? round($before + $dot, 2) : 0, 'dep_end' => round($depEnd, 2), 'nbv_end' => round($grossEnd - $depEnd, 2),
                'disposal_date' => $a->disposal_date?->toDateString(), 'disposal_amount' => $a->disposal_amount,
            ];
        }
        $keys = ['gross_start', 'additions', 'disposals', 'gross_end', 'dep_start', 'dep_year', 'dep_posted', 'dep_reversal', 'dep_end', 'nbv_end'];
        $byCat = [];
        foreach ($rows as $r) {
            $k = $r['category'] ?? 'Sans catégorie';
            $byCat[$k] ??= array_fill_keys($keys, 0.0) + ['category' => $k, 'gl_asset' => $r['gl_asset'], 'count' => 0];
            foreach ($keys as $x) {
                $byCat[$k][$x] += $r[$x];
            }
            $byCat[$k]['count']++;
        }
        $totals = array_fill_keys($keys, 0.0);
        foreach ($rows as $r) {
            foreach ($keys as $x) {
                $totals[$x] += $r[$x];
            }
        }

        return ['year' => $year, 'assets' => $rows, 'categories' => array_values($byCat), 'totals' => $totals];
    }

    public function stats(Company $company): array
    {
        $running = Asset::where('status', 'running');
        $gross = (float) (clone $running)->sum('value');
        $dep = (float) AssetLine::whereNotNull('posted_at')->whereIn('asset_id', (clone $running)->select('id'))->sum('amount');
        $due = AssetLine::whereNull('posted_at')->where('period_end', '<', now()->toDateString())->whereIn('asset_id', (clone $running)->select('id'));

        return [
            'count' => (clone $running)->count(),
            'gross' => round($gross, 2),
            'nbv' => round($gross - $dep, 2),
            'due_count' => (clone $due)->count(),
            'due_amount' => round((float) (clone $due)->sum('amount'), 2),
            'year_dotation' => round((float) AssetLine::whereBetween('period_end', [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()])
                ->whereIn('asset_id', Asset::where('status', '!=', 'draft')->select('id'))->sum('amount'), 2),
        ];
    }
}
