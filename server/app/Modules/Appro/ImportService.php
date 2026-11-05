<?php

namespace App\Modules\Appro;

use App\Models\Company;
use App\Models\Sequence;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Appro\Models\ImportCost;
use App\Modules\Appro\Models\ImportFile;
use App\Modules\Appro\Models\ImportLine;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Services\EntityWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dossier d'importation : achat en devise auprès d'un fournisseur étranger, frais d'approche (fret, assurance,
 * transit, port, douane, transport local) répartis sur chaque article → coût de revient réel et coefficient d'approche.
 */
class ImportService
{
    public const STATUSES = ['draft' => 'En préparation', 'ordered' => 'Commandé', 'in_transit' => 'En transit', 'customs' => 'En dédouanement', 'received' => 'Reçu', 'closed' => 'Clôturé', 'cancelled' => 'Annulé'];

    public const TRANSPORTS = ['sea' => 'Maritime', 'air' => 'Aérien', 'road' => 'Routier', 'rail' => 'Ferroviaire', 'courier' => 'Express (DHL, FedEx…)'];

    public const INCOTERMS = ['EXW', 'FCA', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    /** Frais d'approche : [libellé, entre dans la valeur en douane (CAF)] */
    public const COSTS = [
        'freight' => ['Fret international', true], 'insurance' => ['Assurance transport', true], 'transit' => ['Honoraires du transitaire', false],
        'port' => ['Frais portuaires / aéroportuaires', false], 'customs' => ['Droits de douane (montant global)', false], 'customs_other' => ['Redevances et autres taxes douanières', false],
        'local_transport' => ['Transport jusqu\'au dépôt', false], 'bank' => ['Frais bancaires et de transfert', false], 'other' => ['Autres frais', false],
    ];

    public const ALLOCATIONS = ['value' => 'Au prorata de la valeur', 'quantity' => 'Au prorata des quantités', 'weight' => 'Au prorata du poids'];

    public function __construct(private EntityWriter $writer) {}

    protected function assert(bool $ok, string $msg): void
    {
        if (! $ok) {
            throw new \DomainException($msg);
        }
    }

    public function save(Company $c, ?ImportFile $f, array $d, ?int $userId): ImportFile
    {
        return DB::transaction(function () use ($c, $f, $d, $userId) {
            $f ??= new ImportFile(['company_id' => $c->id, 'user_id' => $userId, 'currency' => $c->currency]);
            foreach (['title', 'partner_id', 'origin_country', 'incoterm', 'transport', 'allocation', 'order_date', 'eta', 'arrived_at', 'tracking', 'forwarder', 'project_id', 'notes'] as $k) {
                if (array_key_exists($k, $d)) {
                    $f->{$k} = is_string($d[$k]) ? (trim($d[$k]) ?: null) : $d[$k];
                }
            }
            if (array_key_exists('currency', $d)) {
                $f->currency = strtoupper((string) $d['currency']) ?: $c->currency;
            }
            if (array_key_exists('vat_rate', $d)) {
                $f->vat_rate = max(0, (float) $d['vat_rate']);
            }
            if (array_key_exists('exchange_rate', $d) && (float) $d['exchange_rate'] > 0) {
                $f->exchange_rate = (float) $d['exchange_rate'];
            } elseif (! $f->exists || array_key_exists('currency', $d)) {
                $f->exchange_rate = $this->rate($c, $f->currency) ?? ($f->exchange_rate ?: 1);
            }
            if ($f->currency === $c->currency) {
                $f->exchange_rate = 1;
            }
            $this->assert((bool) $f->title, 'Donnez un nom au dossier (ex. Câbles et disjoncteurs Schneider — Chine).');
            $f->transport = array_key_exists($f->transport, self::TRANSPORTS) ? $f->transport : 'sea';
            $f->allocation = array_key_exists($f->allocation, self::ALLOCATIONS) ? $f->allocation : 'value';
            $f->number ??= Sequence::next($c->id, 'appro.import', 'IMP');
            if (array_key_exists('status', $d) && array_key_exists($d['status'], self::STATUSES)) {
                $f->status = $d['status'];
                if ($f->status === 'received' && ! $f->arrived_at) {
                    $f->arrived_at = now()->toDateString();
                }
            }
            $f->save();
            if (array_key_exists('lines', $d)) {
                ImportLine::where('import_id', $f->id)->delete();
                foreach (array_values(array_filter((array) $d['lines'], fn ($l) => trim((string) ($l['description'] ?? '')) !== '')) as $i => $l) {
                    ImportLine::create(['company_id' => $c->id, 'import_id' => $f->id, 'product_id' => $l['product_id'] ?? null, 'description' => Str::limit(trim($l['description']), 250, ''),
                        'hs_code' => isset($l['hs_code']) ? trim((string) $l['hs_code']) ?: null : null, 'quantity' => max(0.001, (float) ($l['quantity'] ?? 1)), 'unit_price' => max(0, (float) ($l['unit_price'] ?? 0)),
                        'weight' => max(0, (float) ($l['weight'] ?? 0)), 'duty_rate' => max(0, min(100, (float) ($l['duty_rate'] ?? 0))), 'sort' => $i]);
                }
            }
            if (array_key_exists('costs', $d)) {
                ImportCost::where('import_id', $f->id)->delete();
                foreach (array_values(array_filter((array) $d['costs'], fn ($x) => (float) ($x['amount'] ?? 0) > 0)) as $i => $x) {
                    ImportCost::create(['company_id' => $c->id, 'import_id' => $f->id, 'type' => array_key_exists($x['type'] ?? '', self::COSTS) ? $x['type'] : 'other',
                        'label' => isset($x['label']) ? Str::limit(trim((string) $x['label']), 150, '') ?: null : null, 'amount' => round((float) $x['amount'], 2),
                        'allocation' => array_key_exists($x['allocation'] ?? '', self::ALLOCATIONS) ? $x['allocation'] : null, 'reference' => $x['reference'] ?? null, 'sort' => $i]);
                }
            }

            return $f->fresh();
        });
    }

    protected function rate(Company $c, string $currency): ?float
    {
        if ($currency === $c->currency) {
            return 1.0;
        }

        return class_exists(\App\Modules\Devises\FxService::class) ? app(\App\Modules\Devises\FxService::class)->rate($c, $currency) : null;
    }

    /**
     * Coût de revient : valeur d'achat convertie, frais répartis, droits de douane sur la valeur CAF, TVA à l'import (récupérable, pour information).
     */
    public function compute(ImportFile $f): array
    {
        $lines = ImportLine::where('import_id', $f->id)->orderBy('sort')->get();
        $costs = ImportCost::where('import_id', $f->id)->orderBy('sort')->get();
        $rate = (float) ($f->exchange_rate ?: 1);
        $rows = [];
        foreach ($lines as $l) {
            $rows[$l->id] = ['id' => $l->id, 'product_id' => $l->product_id, 'description' => $l->description, 'hs_code' => $l->hs_code, 'quantity' => $l->quantity,
                'unit_price' => $l->unit_price, 'weight' => $l->weight, 'duty_rate' => $l->duty_rate,
                'value_foreign' => round($l->quantity * $l->unit_price, 2), 'value' => round($l->quantity * $l->unit_price * $rate, 2), 'cif_costs' => 0.0, 'other_costs' => 0.0, 'duty' => 0.0];
        }
        $bases = [
            'value' => array_map(fn ($r) => $r['value'], $rows),
            'quantity' => array_map(fn ($r) => $r['quantity'], $rows),
            'weight' => array_map(fn ($r) => $r['quantity'] * $r['weight'], $rows),
        ];
        $share = function (float $amount, string $method) use ($bases, $rows): array {
            $b = $bases[$method] ?? $bases['value'];
            $sum = array_sum($b);
            if ($sum <= 0) {
                $b = $bases['value'];
                $sum = array_sum($b);
            }
            if ($sum <= 0) {
                $b = array_fill_keys(array_keys($rows), 1);
                $sum = max(1, count($rows));
            }
            $out = [];
            $left = round($amount, 2);
            $keys = array_keys($b);
            foreach ($keys as $i => $k) {
                $part = $i === count($keys) - 1 ? $left : round($amount * $b[$k] / $sum, 2);
                $out[$k] = $part;
                $left = round($left - $part, 2);
            }

            return $out;
        };
        $byType = [];
        $globalDuty = 0.0;
        foreach ($costs as $x) {
            $byType[$x->type] = ($byType[$x->type] ?? 0) + $x->amount;
            if (! $rows) {
                continue;
            }
            foreach ($share($x->amount, $x->allocation ?: $f->allocation) as $k => $part) {
                if (self::COSTS[$x->type][1] ?? false) {
                    $rows[$k]['cif_costs'] += $part;
                } elseif ($x->type === 'customs') {
                    $rows[$k]['duty'] += $part;
                    $globalDuty += $part;
                } else {
                    $rows[$k]['other_costs'] += $part;
                }
            }
        }
        $vatBase = 0.0;
        foreach ($rows as $k => &$r) {
            $r['cif'] = round($r['value'] + $r['cif_costs'], 2);
            if ($r['duty_rate'] > 0) {
                $r['duty'] += round($r['cif'] * $r['duty_rate'] / 100, 2);
            }
            $r['duty'] = round($r['duty'], 2);
            $r['cif_costs'] = round($r['cif_costs'], 2);
            $r['other_costs'] = round($r['other_costs'], 2);
            $r['landed'] = round($r['cif'] + $r['duty'] + $r['other_costs'], 2);
            $r['unit_landed'] = $r['quantity'] > 0 ? round($r['landed'] / $r['quantity'], 2) : 0;
            $r['unit_value'] = $r['quantity'] > 0 ? round($r['value'] / $r['quantity'], 2) : 0;
            $r['coefficient'] = $r['value'] > 0 ? round($r['landed'] / $r['value'], 3) : null;
            $vatBase += $r['cif'] + $r['duty'];
        }
        unset($r);
        $col = fn (string $k) => round(array_sum(array_column($rows, $k)), 2);
        $value = $col('value');
        $landed = $col('landed');
        $products = Product::whereIn('id', array_filter(array_column($rows, 'product_id')))->get(['id', 'name', 'purchase_price', 'sale_price'])->keyBy('id');
        foreach ($rows as &$r) {
            $p = $r['product_id'] ? ($products[$r['product_id']] ?? null) : null;
            $r['current_cost'] = $p ? (float) $p->purchase_price : null;
            $r['sale_price'] = $p ? (float) $p->sale_price : null;
            $r['margin'] = $p && (float) $p->sale_price > 0 ? round(((float) $p->sale_price - $r['unit_landed']) * 100 / (float) $p->sale_price, 1) : null;
        }
        unset($r);

        return [
            'lines' => array_values($rows),
            'costs' => collect(self::COSTS)->map(fn ($v, $k) => ['type' => $k, 'label' => $v[0], 'amount' => round($byType[$k] ?? 0, 2)])->filter(fn ($x) => $x['amount'] > 0)->values()->all(),
            'totals' => [
                'value_foreign' => $col('value_foreign'), 'value' => $value, 'cif_costs' => $col('cif_costs'), 'cif' => $col('cif'), 'duty' => $col('duty'), 'other_costs' => $col('other_costs'),
                'costs' => round($landed - $value, 2), 'landed' => $landed, 'coefficient' => $value > 0 ? round($landed / $value, 3) : null,
                'vat_import' => round($vatBase * $f->vat_rate / 100, 2), 'weight' => round(array_sum(array_map(fn ($r) => $r['quantity'] * $r['weight'], $rows)), 2),
            ],
        ];
    }

    /** Reporte le coût de revient unitaire sur le coût d'achat des articles. */
    public function apply(Company $c, ImportFile $f): int
    {
        $n = 0;
        foreach ($this->compute($f)['lines'] as $l) {
            if (! $l['product_id'] || $l['unit_landed'] <= 0) {
                continue;
            }
            $res = $this->writer->upsert(Product::class, $l['product_id'], ['purchase_price' => $l['unit_landed']]);
            $this->assert($res['status'] === EntityWriter::APPLIED, 'Article non mis à jour : '.($res['message'] ?? json_encode($res)));
            $n++;
        }
        $f->forceFill(['applied_at' => now()])->save();

        return $n;
    }

    /** Bon de commande fournisseur (brouillon) dans la devise du fournisseur. */
    public function order(Company $c, ImportFile $f): PurchaseDocument
    {
        $this->assert(! $f->purchase_order_id || ! PurchaseDocument::where('id', $f->purchase_order_id)->where('status', '!=', 'cancelled')->exists(), 'Un bon de commande existe déjà pour ce dossier.');
        $this->assert((bool) $f->partner_id, 'Choisissez le fournisseur.');
        $lines = ImportLine::where('import_id', $f->id)->orderBy('sort')->get();
        $this->assert($lines->isNotEmpty(), 'Ajoutez les articles commandés.');
        $products = Product::whereIn('id', $lines->pluck('product_id')->filter())->get()->keyBy('id');
        $res = $this->writer->upsert(PurchaseDocument::class, $id = (string) Str::uuid(), [
            'type' => 'purchase_order', 'partner_id' => $f->partner_id, 'date' => ($f->order_date ?? now())->toDateString(), 'due_date' => $f->eta?->toDateString(),
            'currency' => $f->currency, 'exchange_rate' => $f->currency === $c->currency ? null : $f->exchange_rate,
            'reference' => $f->number, 'notes' => 'Importation '.$f->number.' — '.$f->title.($f->incoterm ? ' — Incoterm '.$f->incoterm : ''), 'project_id' => $f->project_id,
            'lines' => $lines->map(fn ($l) => ['id' => (string) Str::uuid(), 'product_id' => $l->product_id, 'description' => $l->description, 'quantity' => $l->quantity,
                'unit' => $products->get($l->product_id ?? '')?->unit, 'unit_price' => $l->unit_price, 'tax_rate' => 0, 'discount_percent' => 0])->values()->all(),
        ]);
        $this->assert($res['status'] === EntityWriter::APPLIED, 'Bon de commande refusé : '.($res['message'] ?? json_encode($res['errors'] ?? $res)));
        $f->forceFill(['purchase_order_id' => $id, 'status' => $f->status === 'draft' ? 'ordered' : $f->status, 'order_date' => $f->order_date ?? now()->toDateString()])->save();

        return PurchaseDocument::find($id);
    }

    public function toApi(ImportFile $f, bool $full = false): array
    {
        $calc = $this->compute($f);
        $out = $f->only(['id', 'number', 'title', 'partner_id', 'origin_country', 'currency', 'exchange_rate', 'incoterm', 'transport', 'status', 'allocation', 'vat_rate', 'tracking', 'forwarder', 'purchase_order_id', 'project_id', 'notes'])
            + ['partner' => $f->partner_id ? Partner::withTrashed()->find($f->partner_id)?->name : null, 'status_label' => self::STATUSES[$f->status] ?? $f->status, 'transport_label' => self::TRANSPORTS[$f->transport] ?? $f->transport,
                'order_date' => $f->order_date?->toDateString(), 'eta' => $f->eta?->toDateString(), 'arrived_at' => $f->arrived_at?->toDateString(), 'applied_at' => $f->applied_at,
                'late' => $f->eta && $f->eta->lt(now()->startOfDay()) && in_array($f->status, ['ordered', 'in_transit', 'customs'], true),
                'totals' => $calc['totals'], 'lines_count' => count($calc['lines']),
                'order_number' => $f->purchase_order_id ? PurchaseDocument::find($f->purchase_order_id)?->number : null];
        if ($full) {
            $out['lines'] = $calc['lines'];
            $out['cost_items'] = ImportCost::where('import_id', $f->id)->orderBy('sort')->get(['id', 'type', 'label', 'amount', 'allocation', 'reference'])->map(fn ($x) => $x->toArray() + ['type_label' => self::COSTS[$x->type][0] ?? $x->type])->values();
            $out['cost_summary'] = $calc['costs'];
        }

        return $out;
    }

    public function pdf(Company $c, ImportFile $f): string
    {
        $calc = $this->compute($f);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => $e(number_format((float) $v, 0, ',', ' '));
        $pdf = Pdf::make('Coût de revient '.$f->number, true);
        $html = Pdf::head($c, 'COÛT DE REVIENT IMPORT', $f->number.' — '.$f->title)
            .'<table cellpadding="4"><tr><td width="50%">Fournisseur : <b>'.$e(Partner::find($f->partner_id)?->name ?? '—').'</b><br>Origine : '.$e($f->origin_country ?? '—').' · '.$e(self::TRANSPORTS[$f->transport] ?? '').($f->incoterm ? ' · Incoterm '.$e($f->incoterm) : '').'</td>'
            .'<td width="50%">Devise : <b>'.$e($f->currency).'</b> au cours de '.$e(rtrim(rtrim(number_format($f->exchange_rate, 6, ',', ' '), '0'), ',')).' '.$e($c->currency).'<br>Transitaire : '.$e($f->forwarder ?? '—').' · Suivi : '.$e($f->tracking ?? '—').'</td></tr></table><br>'
            .'<table cellpadding="4" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="22%">Article</td><td width="7%" align="right">Qté</td><td width="10%" align="right">PU '.$e($f->currency).'</td><td width="11%" align="right">Valeur '.$e($c->currency).'</td><td width="10%" align="right">Fret + assur.</td><td width="9%" align="right">Douane</td><td width="10%" align="right">Autres frais</td><td width="11%" align="right">Coût total</td><td width="10%" align="right">Coût unitaire</td></tr>';
        foreach ($calc['lines'] as $l) {
            $html .= '<tr><td>'.$e($l['description']).($l['hs_code'] ? '<br><span style="color:#777;font-size:7pt">SH '.$e($l['hs_code']).($l['duty_rate'] ? ' · '.$e($l['duty_rate']).' %' : '').'</span>' : '').'</td><td align="right">'.$e(rtrim(rtrim(number_format($l['quantity'], 3, ',', ' '), '0'), ',')).'</td><td align="right">'.$e(number_format($l['unit_price'], 2, ',', ' ')).'</td><td align="right">'.$m($l['value']).'</td><td align="right">'.$m($l['cif_costs']).'</td><td align="right">'.$m($l['duty']).'</td><td align="right">'.$m($l['other_costs']).'</td><td align="right"><b>'.$m($l['landed']).'</b></td><td align="right"><b>'.$m($l['unit_landed']).'</b></td></tr>';
        }
        $t = $calc['totals'];
        $html .= '<tr bgcolor="#f2f5fa" style="font-weight:bold"><td colspan="3">TOTAL</td><td align="right">'.$m($t['value']).'</td><td align="right">'.$m($t['cif_costs']).'</td><td align="right">'.$m($t['duty']).'</td><td align="right">'.$m($t['other_costs']).'</td><td align="right">'.$m($t['landed']).'</td><td></td></tr></table><br>';
        $html .= '<table cellpadding="4" width="60%" border="0.3"><tr bgcolor="#eeeeee"><td colspan="2"><b>Frais d\'approche</b></td></tr>';
        foreach ($calc['costs'] as $x) {
            $html .= '<tr><td>'.$e($x['label']).'</td><td align="right">'.$m($x['amount']).'</td></tr>';
        }
        $html .= '<tr><td><b>Coefficient d\'approche</b></td><td align="right"><b>'.$e(number_format((float) $t['coefficient'], 3, ',', ' ')).'</b></td></tr><tr><td>TVA à l\'import (récupérable, '.$e($f->vat_rate).' %)</td><td align="right">'.$m($t['vat_import']).'</td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
