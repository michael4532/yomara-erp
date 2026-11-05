<?php

namespace App\Modules\Rapports;

use App\Models\Company;
use App\Modules\ImportExport\Sheet;

/** Exports Excel et PDF, et résumé HTML pour les e-mails. */
class Exporter
{
    /** En-têtes et lignes (valeurs brutes) ; tableau croisé quand le rapport en est un. */
    public function table(array $res): array
    {
        $c = $res['config'];
        $dimCols = array_values(array_filter($res['columns'], fn ($x) => $x['kind'] === 'dimension'));
        $mCols = array_values(array_filter($res['columns'], fn ($x) => $x['kind'] === 'measure'));

        if ($c['chart'] === 'pivot' && count($dimCols) === 2) {
            [$d0, $d1] = [$dimCols[0]['key'], $dimCols[1]['key']];
            $m = $mCols[0]['key'];
            $cols = [];
            $lines = [];
            foreach ($res['rows'] as $r) {
                $cols[$r['keys'][$d1] ?? ''] = $r['labels'][$d1];
                $lines[$r['keys'][$d0] ?? ''] ??= ['label' => $r['labels'][$d0], 'v' => []];
                $lines[$r['keys'][$d0] ?? '']['v'][$r['keys'][$d1] ?? ''] = $r['values'][$m];
            }
            ksort($cols);
            $headers = array_merge([$dimCols[0]['label'].' \\ '.$dimCols[1]['label']], array_values($cols), ['Total']);
            $rows = [];
            $colTotals = array_fill_keys(array_keys($cols), 0.0);
            foreach ($lines as $l) {
                $row = [$l['label']];
                $sum = 0.0;
                foreach (array_keys($cols) as $k) {
                    $v = $l['v'][$k] ?? null;
                    $row[] = $v;
                    $sum += (float) $v;
                    $colTotals[$k] += (float) $v;
                }
                $row[] = $mCols[0]['additive'] ? round($sum, 2) : null;
                $rows[] = $row;
            }
            if ($mCols[0]['additive']) {
                $rows[] = array_merge(['Total'], array_map(fn ($v) => round($v, 2), array_values($colTotals)), [round(array_sum($colTotals), 2)]);
            }

            return [$headers, $rows, array_merge(['text'], array_fill(0, count($cols) + 1, $mCols[0]['format']))];
        }

        $headers = array_map(fn ($x) => $x['label'], $res['columns']);
        $formats = array_map(fn ($x) => $x['kind'] === 'measure' ? $x['format'] : 'text', $res['columns']);
        $rows = [];
        foreach ($res['rows'] as $r) {
            $row = [];
            foreach ($dimCols as $d) {
                $row[] = $r['labels'][$d['key']];
            }
            foreach ($mCols as $m) {
                $row[] = $r['values'][$m['key']];
            }
            $rows[] = $row;
        }
        if ($res['others']) {
            $row = array_merge(['Autres'], array_fill(0, max(0, count($dimCols) - 1), ''));
            foreach ($mCols as $m) {
                $row[] = $res['others'][$m['key']];
            }
            $rows[] = $row;
        }
        $total = $dimCols ? array_merge(['Total'], array_fill(0, count($dimCols) - 1, '')) : [];
        foreach ($mCols as $m) {
            $total[] = $c['cumulative'] && $dimCols && $m['additive'] ? ($rows ? end($rows)[array_search($m, $mCols, true) + count($dimCols)] : 0) : $res['totals'][$m['key']];
        }
        $rows[] = $total;

        return [$headers, $rows, $formats];
    }

    public function xlsx(array $res, string $path): void
    {
        [$headers, $rows] = $this->table($res);
        Sheet::write($path, $headers, $rows);
    }

    public static function format($v, string $format, string $currency): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (! is_numeric($v)) {
            return (string) $v;
        }
        $v = (float) $v;

        return match ($format) {
            'money' => number_format($v, in_array($currency, ['XOF', 'XAF', 'GNF'], true) ? 0 : 2, ',', ' '),
            'percent' => number_format($v, 1, ',', ' ').' %',
            'decimal' => number_format($v, 1, ',', ' '),
            default => number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2, ',', ' '),
        };
    }

    public static function currencyLabel(string $c): string
    {
        return in_array($c, ['XOF', 'XAF'], true) ? 'F CFA' : $c;
    }

    /** Indicateur principal : première mesure + évolution. */
    public function kpi(array $res): array
    {
        $m = collect($res['columns'])->firstWhere('kind', 'measure');
        $value = $res['totals'][$m['key']] ?? null;
        $prev = $res['previous']['totals'][$m['key']] ?? null;
        $delta = $prev !== null && abs((float) $prev) > 0.0001 && $value !== null ? round(((float) $value - (float) $prev) / abs((float) $prev) * 100, 1) : null;

        return ['label' => $m['label'], 'value' => $value, 'format' => $m['format'], 'previous' => $prev, 'delta' => $delta];
    }

    /** Résumé HTML (corps d'e-mail) : indicateur ou 15 premières lignes. */
    public function html(string $title, array $res): string
    {
        $cur = self::currencyLabel($res['currency']);
        $h = '<h3 style="margin:18px 0 4px;color:#0B1B3A">'.e($title).'</h3><div style="color:#5B6B85;font-size:12px;margin-bottom:8px">'.e($res['period']['label']).($res['filters_label'] ? ' · '.e($res['filters_label']) : '').'</div>';
        if ($res['config']['chart'] === 'kpi' || ! $res['rows']) {
            foreach ($res['columns'] as $col) {
                if ($col['kind'] !== 'measure') {
                    continue;
                }
                $v = $res['totals'][$col['key']];
                $p = $res['previous']['totals'][$col['key']] ?? null;
                $delta = $p !== null && abs((float) $p) > 0.0001 && $v !== null ? round(((float) $v - (float) $p) / abs((float) $p) * 100, 1) : null;
                $h .= '<div style="display:inline-block;margin:0 18px 8px 0"><div style="font-size:12px;color:#5B6B85">'.e($col['label']).'</div><div style="font-size:22px;font-weight:bold">'
                    .e(self::format($v, $col['format'], $res['currency'])).($col['format'] === 'money' ? ' <span style="font-size:13px">'.$cur.'</span>' : '').'</div>'
                    .($delta !== null ? '<div style="font-size:12px;color:'.($delta >= 0 ? '#12A150' : '#D93025').'">'.($delta >= 0 ? '+' : '').number_format($delta, 1, ',', ' ').' % vs '.e(mb_strtolower(Period::COMPARE[$res['config']['compare']])).'</div>' : '').'</div>';
            }

            return $h;
        }
        [$headers, $rows, $formats] = $this->table($res);
        $more = count($rows) > 16 ? count($rows) - 16 : 0;
        if ($more) {
            $rows = array_merge(array_slice($rows, 0, 15), [end($rows)]);
        }
        $h .= '<table cellpadding="5" style="border-collapse:collapse;font-size:12px;min-width:320px">';
        $h .= '<tr>'.implode('', array_map(fn ($x, $i) => '<th style="background:#F4F6FB;border-bottom:1px solid #E3E8F1;text-align:'.($formats[$i] === 'text' ? 'left' : 'right').'">'.e($x).'</th>', $headers, array_keys($headers))).'</tr>';
        foreach ($rows as $ri => $r) {
            $last = $ri === count($rows) - 1;
            $h .= '<tr>'.implode('', array_map(fn ($x, $i) => '<td style="border-bottom:1px solid #E3E8F1;'.($last ? 'font-weight:bold;' : '').'text-align:'.($formats[$i] === 'text' ? 'left' : 'right').'">'.e($formats[$i] === 'text' ? (string) $x : self::format($x, $formats[$i], $res['currency'])).'</td>', $r, array_keys($r))).'</tr>';
        }
        $h .= '</table>'.($more ? '<div style="font-size:11px;color:#5B6B85">… et '.$more.' ligne(s) de plus dans la pièce jointe.</div>' : '');

        return $h;
    }

    /**
     * PDF d'un ou plusieurs rapports (tableau de bord).
     *
     * @param  array  $sections  [['title' => ..., 'result' => ...] | ['title' => ..., 'error' => ...]]
     */
    public function pdf(Company $company, string $title, array $sections, ?string $subtitle = null): string
    {
        $wide = false;
        foreach ($sections as $s) {
            if (isset($s['result']) && count($this->table($s['result'])[0]) > 6) {
                $wide = true;
            }
        }
        $pdf = new \TCPDF($wide ? 'L' : 'P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setFooterFont(['dejavusans', '', 7]);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle($title);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetTextColor(11, 27, 58);
        $pdf->Cell(0, 8, $title, 0, 1);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(91, 107, 133);
        $pdf->Cell(0, 5, $company->name.' · édité le '.now()->format('d/m/Y à H:i').($subtitle ? ' · '.$subtitle : ''), 0, 1);
        $pdf->Ln(2);
        $usable = $pdf->getPageWidth() - 24;

        foreach ($sections as $s) {
            if ($pdf->GetY() > $pdf->getPageHeight() - 60) {
                $pdf->AddPage();
            }
            $pdf->SetTextColor(11, 27, 58);
            $pdf->SetFont('dejavusans', 'B', 11.5);
            $pdf->Cell(0, 7, $s['title'], 0, 1);
            if (isset($s['error'])) {
                $pdf->SetFont('dejavusans', 'I', 8.5);
                $pdf->SetTextColor(217, 48, 37);
                $pdf->MultiCell(0, 5, $s['error'], 0, 'L');
                $pdf->Ln(3);
                continue;
            }
            $res = $s['result'];
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor(91, 107, 133);
            $pdf->MultiCell(0, 4.5, $res['period']['label'].($res['filters_label'] ? ' · '.$res['filters_label'] : '').' · montants en '.self::currencyLabel($res['currency']), 0, 'L');
            $pdf->Ln(1);
            $chart = $res['config']['chart'];
            if ($chart === 'kpi' || ! $res['config']['dimensions']) {
                $this->pdfKpis($pdf, $res, $usable);
                continue;
            }
            if (in_array($chart, ['bar', 'hbar', 'line', 'pie'], true) && count($res['config']['dimensions']) === 1 && $res['rows']) {
                $this->pdfChart($pdf, $res, $usable);
            }
            [$headers, $rows, $formats] = $this->table($res);
            $html = '<table cellpadding="3" border="0" style="font-size:7.5pt"><thead><tr style="background-color:#F4F6FB;font-weight:bold">';
            foreach ($headers as $i => $hd) {
                $html .= '<th align="'.($formats[$i] === 'text' ? 'left' : 'right').'">'.e($hd).'</th>';
            }
            $html .= '</tr></thead>';
            foreach ($rows as $ri => $r) {
                $last = $ri === count($rows) - 1;
                $html .= '<tr'.($last ? ' style="font-weight:bold;background-color:#F4F6FB"' : ($ri % 2 ? ' style="background-color:#FAFBFD"' : '')).'>';
                foreach ($r as $i => $v) {
                    $html .= '<td align="'.($formats[$i] === 'text' ? 'left' : 'right').'">'.e($formats[$i] === 'text' ? (string) $v : self::format($v, $formats[$i], $res['currency'])).'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
            $pdf->writeHTML($html, true, false, true, false, '');
            if ($res['truncated']) {
                $pdf->SetFont('dejavusans', 'I', 7);
                $pdf->Cell(0, 4, 'Résultat limité à '.ReportEngine::MAX_ROWS.' lignes.', 0, 1);
            }
            $pdf->Ln(3);
        }

        return $pdf->Output('rapport.pdf', 'S');
    }

    private function pdfKpis(\TCPDF $pdf, array $res, float $usable): void
    {
        $cols = array_values(array_filter($res['columns'], fn ($x) => $x['kind'] === 'measure'));
        $w = min(62, $usable / max(1, count($cols)));
        $y = $pdf->GetY();
        foreach ($cols as $i => $col) {
            $x = 12 + $i * $w;
            $v = $res['totals'][$col['key']];
            $p = $res['previous']['totals'][$col['key']] ?? null;
            $pdf->SetFillColor(244, 246, 251);
            $pdf->RoundedRect($x, $y, $w - 3, 21, 2, '1111', 'F');
            $pdf->SetXY($x + 3, $y + 2);
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(91, 107, 133);
            $pdf->Cell($w - 9, 4, $col['label'], 0, 2);
            $pdf->SetFont('dejavusans', 'B', 14);
            $pdf->SetTextColor(11, 27, 58);
            $pdf->Cell($w - 9, 8, self::format($v, $col['format'], $res['currency']).($col['format'] === 'money' ? ' '.self::currencyLabel($res['currency']) : ''), 0, 2);
            if ($p !== null && abs((float) $p) > 0.0001 && $v !== null) {
                $d = round(((float) $v - (float) $p) / abs((float) $p) * 100, 1);
                $pdf->SetFont('dejavusans', '', 7);
                $d >= 0 ? $pdf->SetTextColor(18, 161, 80) : $pdf->SetTextColor(217, 48, 37);
                $pdf->Cell($w - 9, 4, ($d >= 0 ? '+' : '').number_format($d, 1, ',', ' ').' % (précédent : '.self::format($p, $col['format'], $res['currency']).')', 0, 2);
            }
        }
        $pdf->SetY($y + 25);
    }

    /** Graphique simple dessiné en vectoriel (première mesure). */
    private function pdfChart(\TCPDF $pdf, array $res, float $usable): void
    {
        $dim = $res['config']['dimensions'][0];
        $m = $res['config']['measures'][0];
        $rows = array_slice($res['rows'], 0, 31);
        $vals = array_map(fn ($r) => (float) ($r['values'][$m] ?? 0), $rows);
        $max = max(array_map('abs', $vals)) ?: 1;
        $blue = [31, 111, 235];
        $chart = $res['config']['chart'];
        $x0 = 12;
        $y0 = $pdf->GetY() + 1;

        if ($chart === 'hbar' || $chart === 'pie') {
            $rows = array_slice($rows, 0, 12);
            $total = array_sum(array_map(fn ($r) => abs((float) ($r['values'][$m] ?? 0)), $rows)) ?: 1;
            $labelW = 55;
            $barW = $usable - $labelW - 30;
            $y = $y0;
            foreach ($rows as $r) {
                $v = (float) ($r['values'][$m] ?? 0);
                $pdf->SetFont('dejavusans', '', 7);
                $pdf->SetTextColor(40, 40, 40);
                $pdf->SetXY($x0, $y);
                $pdf->Cell($labelW, 4.6, mb_strimwidth($r['labels'][$dim], 0, 38, '…'), 0, 0);
                $pdf->SetFillColor(...$blue);
                $pdf->Rect($x0 + $labelW, $y + 0.8, max(0.3, abs($v) / $max * $barW), 3, 'F');
                $pdf->SetXY($x0 + $labelW + abs($v) / $max * $barW + 1.5, $y);
                $pdf->Cell(28, 4.6, self::format($v, $res['columns'][count($res['config']['dimensions'])]['format'], $res['currency']).($chart === 'pie' ? ' ('.number_format(abs($v) / $total * 100, 1, ',', '').' %)' : ''), 0, 0);
                $y += 5.2;
            }
            $pdf->SetY($y + 3);

            return;
        }

        $h = 42;
        $n = count($rows);
        $step = $usable / max(1, $n);
        $pdf->SetDrawColor(227, 232, 241);
        $pdf->Line($x0, $y0 + $h, $x0 + $usable, $y0 + $h);
        $prev = null;
        foreach ($rows as $i => $r) {
            $v = (float) ($r['values'][$m] ?? 0);
            $bh = abs($v) / $max * ($h - 4);
            $cx = $x0 + $i * $step;
            if ($chart === 'line') {
                $pt = [$cx + $step / 2, $y0 + $h - ($v >= 0 ? $bh : 0)];
                if ($prev) {
                    $pdf->SetDrawColor(...$blue);
                    $pdf->SetLineWidth(0.5);
                    $pdf->Line($prev[0], $prev[1], $pt[0], $pt[1]);
                    $pdf->SetLineWidth(0.2);
                }
                $pdf->SetFillColor(...$blue);
                $pdf->Circle($pt[0], $pt[1], 0.7, 0, 360, 'F');
                $prev = $pt;
            } else {
                $v >= 0 ? $pdf->SetFillColor(...$blue) : $pdf->SetFillColor(217, 48, 37);
                $pdf->Rect($cx + $step * 0.15, $y0 + $h - $bh, $step * 0.7, max(0.2, $bh), 'F');
            }
            if ($n <= 16 || $i % (int) ceil($n / 16) === 0) {
                $pdf->SetFont('dejavusans', '', 6);
                $pdf->SetTextColor(91, 107, 133);
                $pdf->SetXY($cx, $y0 + $h + 0.8);
                $pdf->Cell($step * max(1, (int) ceil($n / 16)), 3, mb_strimwidth($r['labels'][$dim], 0, 14, '…'), 0, 0, 'C');
            }
        }
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->SetTextColor(91, 107, 133);
        $pdf->SetXY($x0, $y0 - 1);
        $pdf->Cell(60, 3, 'max. '.self::format($max, $res['columns'][count($res['config']['dimensions'])]['format'], $res['currency']), 0, 0);
        $pdf->SetY($y0 + $h + 6);
    }
}
