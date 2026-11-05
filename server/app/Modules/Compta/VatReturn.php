<?php

namespace App\Modules\Compta;

use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Déclaration de TVA d'une période et son état annexé : liste des factures fournisseurs ouvrant droit à déduction
 * (fournisseur, NCC, n° de facture, date, HT, TVA) et des factures de vente (client, NCC), crédit de TVA antérieur.
 */
class VatReturn
{
    public function __construct(protected Ledger $ledger, protected Statements $st) {}

    protected function docs(Company $c, string $direction, string $from, string $to): array
    {
        $types = $direction === 'purchase' ? ['supplier_invoice'] : ['invoice', 'receipt', 'credit_note'];

        return DB::table('trade_documents as d')->leftJoin('partners as p', 'p.id', '=', 'd.partner_id')
            ->where('d.company_id', $c->id)->whereNull('d.deleted_at')->where('d.direction', $direction)->where('d.status', 'validated')
            ->whereIn('d.type', $types)->whereBetween('d.date', [$from, $to])->where('d.tax_total', '!=', 0)
            ->orderBy('d.date')->orderBy('d.number')
            ->get(['d.id', 'd.type', 'd.number', 'd.reference', 'd.date', 'd.partner_name', 'p.name as pname', 'p.tax_id', 'd.subtotal', 'd.tax_total', 'd.total', 'd.exchange_rate', 'd.currency'])
            ->map(function ($d) {
                $rate = (float) ($d->exchange_rate ?: 1);
                $sign = $d->type === 'credit_note' ? -1 : 1;

                return ['date' => $d->date, 'number' => $d->number, 'reference' => $d->reference, 'type' => $d->type, 'partner' => $d->pname ?? $d->partner_name, 'ncc' => $d->tax_id,
                    'base' => round($sign * (float) $d->subtotal * $rate, 2), 'vat' => round($sign * (float) $d->tax_total * $rate, 2), 'total' => round($sign * (float) $d->total * $rate, 2)];
            })->values()->all();
    }

    public function build(Company $c, string $from, string $to): array
    {
        $v = $this->ledger->vat($c, $from, $to);
        $creditCode = Chart::code($c, '4449');
        $before = $this->st->balances($c, null, Carbon::parse($from)->subDay()->toDateString(), true);
        $prior = round(max(0, array_sum(array_filter($before, fn ($x, $k) => str_starts_with((string) $k, $creditCode), ARRAY_FILTER_USE_BOTH))), 2);
        $net = round($v['collected'] - $v['deductible'] - $prior, 2);
        $purchases = $this->docs($c, 'purchase', $from, $to);
        $sales = $this->docs($c, 'sale', $from, $to);

        return [
            'from' => $from, 'to' => $to, 'company' => ['name' => $c->name, 'ncc' => $c->tax_id, 'rccm' => $c->trade_register],
            'collected' => $v['collected'], 'deductible' => $v['deductible'], 'prior_credit' => $prior,
            'payable' => max(0, $net), 'credit_to_carry' => max(0, -$net),
            'sales_bases' => $v['sales_bases'], 'purchase_bases' => $v['purchase_bases'],
            'purchases' => $purchases, 'sales' => $sales,
            'missing_ncc' => count(array_filter($purchases, fn ($p) => ! $p['ncc'])),
            'totals' => ['purchases' => ['base' => round(array_sum(array_column($purchases, 'base')), 2), 'vat' => round(array_sum(array_column($purchases, 'vat')), 2)],
                'sales' => ['base' => round(array_sum(array_column($sales, 'base')), 2), 'vat' => round(array_sum(array_column($sales, 'vat')), 2)]],
        ];
    }

    public function book(Company $c, string $from, string $to): string
    {
        $r = $this->build($c, $from, $to);
        $decl = [
            ['Période', 'du '.Carbon::parse($from)->format('d/m/Y').' au '.Carbon::parse($to)->format('d/m/Y')], ['Entreprise', $c->name], ['NCC', $c->tax_id], ['', ''],
            ['TVA collectée', $r['collected']], ['TVA déductible', $r['deductible']], ['Crédit de TVA antérieur', $r['prior_credit']],
            ['TVA À PAYER', $r['payable']], ['Crédit de TVA à reporter', $r['credit_to_carry']], ['', ''], ['Bases des ventes par taux', ''],
        ];
        foreach ($r['sales_bases'] as $b) {
            $decl[] = ['  Taux '.$b['rate'].' % : base HT / TVA', $b['base'].' / '.$b['tax']];
        }
        $decl[] = ['Bases des achats par taux', ''];
        foreach ($r['purchase_bases'] as $b) {
            $decl[] = ['  Taux '.$b['rate'].' % : base HT / TVA', $b['base'].' / '.$b['tax']];
        }
        $line = fn ($x) => [Carbon::parse($x['date'])->format('d/m/Y'), $x['partner'], $x['ncc'] ?? '', $x['reference'] ?: $x['number'], $x['number'], $x['base'], $x['vat'], $x['total']];
        $p = array_map($line, $r['purchases']);
        $p[] = ['TOTAL', '', '', '', '', $r['totals']['purchases']['base'], $r['totals']['purchases']['vat'], ''];
        $s = array_map($line, $r['sales']);
        $s[] = ['TOTAL', '', '', '', '', $r['totals']['sales']['base'], $r['totals']['sales']['vat'], ''];
        $path = tempnam(sys_get_temp_dir(), 'tva').'.xlsx';
        \App\Modules\ImportExport\Sheet::writeBook($path, [
            'Déclaration' => [['Déclaration de TVA', ''], $decl, [7]],
            'TVA déductible' => [['Date', 'Fournisseur', 'NCC fournisseur', 'N° facture fournisseur', 'Pièce', 'Montant HT', 'TVA déductible', 'TTC'], $p, [count($p) - 1]],
            'TVA collectée' => [['Date', 'Client', 'NCC client', 'Référence', 'Pièce', 'Montant HT', 'TVA collectée', 'TTC'], $s, [count($s) - 1]],
        ]);

        return $path;
    }

    public function pdf(Company $c, string $from, string $to): string
    {
        $r = $this->build($c, $from, $to);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => $e(number_format((float) $v, 0, ',', ' '));
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->AddPage();
        $html = '<table cellpadding="3"><tr><td width="60%"><b style="font-size:11pt">'.$e($c->name).'</b><br>NCC '.$e($c->tax_id ?? '—').' · RCCM '.$e($c->trade_register ?? '—').'</td>'
            .'<td width="40%" align="right"><b style="font-size:11pt;color:#1f6feb">ÉTAT ANNEXÉ À LA DÉCLARATION DE TVA</b><br>du '.Carbon::parse($from)->format('d/m/Y').' au '.Carbon::parse($to)->format('d/m/Y').'</td></tr></table><br>'
            .'<table cellpadding="4" border="0.3" width="55%"><tr><td>TVA collectée</td><td align="right">'.$m($r['collected']).'</td></tr><tr><td>TVA déductible</td><td align="right">'.$m($r['deductible']).'</td></tr>'
            .'<tr><td>Crédit de TVA antérieur</td><td align="right">'.$m($r['prior_credit']).'</td></tr><tr bgcolor="#eef2f8" style="font-weight:bold"><td>TVA à payer</td><td align="right">'.$m($r['payable']).'</td></tr>'
            .'<tr><td>Crédit de TVA à reporter</td><td align="right">'.$m($r['credit_to_carry']).'</td></tr></table><br>';
        foreach (['purchases' => ['TVA DÉDUCTIBLE — factures fournisseurs', 'Fournisseur'], 'sales' => ['TVA COLLECTÉE — factures de vente', 'Client']] as $k => [$title, $who]) {
            $html .= '<h3 style="color:#1f2a44">'.$title.'</h3><table cellpadding="3" border="0.3"><tr bgcolor="#1f2a44" color="#ffffff" style="font-weight:bold"><td width="9%">Date</td><td width="25%">'.$who.'</td><td width="13%">NCC</td><td width="15%">N° facture</td><td width="11%">Pièce</td><td width="9%" align="right">HT</td><td width="9%" align="right">TVA</td><td width="9%" align="right">TTC</td></tr>';
            foreach ($r[$k] as $x) {
                $html .= '<tr><td>'.Carbon::parse($x['date'])->format('d/m/Y').'</td><td>'.$e($x['partner']).'</td><td>'.($x['ncc'] ? $e($x['ncc']) : '<span style="color:#b91c1c">manquant</span>').'</td><td>'.$e($x['reference'] ?: $x['number']).'</td><td>'.$e($x['number']).'</td><td align="right">'.$m($x['base']).'</td><td align="right">'.$m($x['vat']).'</td><td align="right">'.$m($x['total']).'</td></tr>';
            }
            if (! $r[$k]) {
                $html .= '<tr><td colspan="8">Aucune facture.</td></tr>';
            }
            $html .= '<tr bgcolor="#eef2f8" style="font-weight:bold"><td colspan="5">TOTAL</td><td align="right">'.$m($r['totals'][$k]['base']).'</td><td align="right">'.$m($r['totals'][$k]['vat']).'</td><td></td></tr></table><br>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
