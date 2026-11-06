<?php

namespace App\Modules\Materiel;

use App\Models\Company;
use App\Modules\Materiel\Models\Checkout;
use Illuminate\Support\Facades\DB;

/** Bon de sortie (et de retour) de matériel, avec les cadres de signature. */
class MaterielPdf
{
    public function checkout(Company $c, Checkout $x): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $returned = collect($x->lines)->contains(fn ($l) => ! empty($l['returned']));
        $title = $x->status === 'returned' ? 'BON DE SORTIE ET DE RETOUR DE MATÉRIEL' : 'BON DE SORTIE DE MATÉRIEL';
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle($title.' '.$x->number);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 14, 15);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 9);

        $holder = $x->holder_name;
        if ($x->employee_id) {
            $emp = DB::table('rh_employees')->where('id', $x->employee_id)->first();
            $holder = $emp ? trim($emp->last_name.' '.$emp->first_name).($emp->matricule ? ' ('.$emp->matricule.')' : '').($emp->job_title ? ' — '.$emp->job_title : '') : $holder;
        }
        $client = $x->partner_id ? DB::table('partners')->where('company_id', $c->id)->where('id', $x->partner_id)->value('name') : null;
        $d = fn ($v) => $v ? $v->copy()->timezone(config('app.timezone'))->format('d/m/Y à H:i') : '—';

        $html = '<table cellpadding="0"><tr><td width="58%"><b style="font-size:14pt">'.$e($c->name).'</b><br><span style="color:#64748B">'
            .$e(trim(implode(' · ', array_filter([$c->address, $c->city, $c->phone])))).'</span></td>'
            .'<td width="42%" align="right"><span style="font-size:8pt;color:#64748B">N°</span><br><b style="font-size:15pt;color:#1F6FEB">'.$e($x->number).'</b></td></tr></table>'
            .'<br><div style="text-align:center;font-size:14pt;font-weight:bold;color:#0B1B3A;letter-spacing:1px">'.$e($title).'</div><br>';

        $rows = [
            'Porteur du matériel' => $holder ?: '—',
            'Département / service' => $x->department ?: '—',
            'Motif de la sortie' => $x->purpose,
            'Destination' => $x->destination,
            'Client / intervention' => $client,
            'Sortie le' => $d($x->out_at),
            'Retour prévu le' => $x->expected_back_at ? $d($x->expected_back_at) : null,
            'Rendu le' => $x->back_at ? $d($x->back_at) : null,
        ];
        $html .= '<table cellpadding="4" style="border:0.3px solid #E4E9F2">';
        foreach (array_filter($rows) as $k => $v) {
            $html .= '<tr><td width="32%" style="background-color:#F5F7FB;color:#475569">'.$e($k).'</td><td width="68%"><b>'.$e($v).'</b></td></tr>';
        }
        $html .= '</table><br><br>';

        $cond = Checkout::CONDITIONS;
        $html .= '<table cellpadding="5" border="0.3" style="border-color:#CBD5E1"><tr style="background-color:#0B1B3A;color:#ffffff;font-weight:bold">'
            .'<td width="6%">#</td><td width="'.($returned ? '34' : '44').'%">Matériel</td><td width="18%">Code / N° de série</td><td width="16%">État au départ</td>'
            .($returned ? '<td width="13%">Retour</td><td width="13%">État au retour</td>' : '<td width="16%">Retour (cocher)</td>').'</tr>';
        foreach (array_values($x->lines) as $i => $l) {
            $html .= '<tr><td>'.($i + 1).'</td><td><b>'.$e($l['name']).'</b>'.(! empty($l['note']) ? '<br><span style="color:#64748B;font-size:8pt">'.$e($l['note']).'</span>' : '').'</td>'
                .'<td>'.$e(implode(' / ', array_filter([$l['code'] ?? null, $l['serial'] ?? null])) ?: '—').'</td><td>'.$e($cond[$l['condition_out'] ?? 'ok'] ?? '').'</td>'
                .($returned
                    ? '<td>'.(! empty($l['returned']) ? $e(\Illuminate\Support\Carbon::parse($l['returned_at'])->format('d/m/Y')) : '<span style="color:#DC2626">non rendu</span>').'</td><td>'.$e(! empty($l['returned']) ? ($cond[$l['condition_in'] ?? 'ok'] ?? '') : '').'</td>'
                    : '<td style="color:#94A3B8">☐ le ____/____</td>')
                .'</tr>';
        }
        $html .= '</table>';
        if ($x->notes) {
            $html .= '<br><br><b>Observations :</b> '.nl2br($e($x->notes));
        }
        $html .= '<br><br><span style="font-size:8pt;color:#475569">Le porteur reconnaît avoir reçu le matériel ci-dessus en bon état de fonctionnement, sauf mention contraire, et s\'engage à le restituer à la date prévue. '
            .'Toute perte ou détérioration doit être signalée sans délai au responsable du département.</span><br><br>';

        // Cadres de signature
        $signers = array_values((array) $x->signers);
        if (! collect($signers)->contains(fn ($s) => ($s['label'] ?? '') === 'Responsable du département')) {
            $signers[] = ['name' => '', 'label' => 'Responsable du département'];
        }
        $signers[] = ['name' => '', 'label' => 'Remis par (magasin / logistique)'];
        $w = floor(100 / max(1, count($signers)));
        $html .= '<table cellpadding="6"><tr>';
        foreach ($signers as $s) {
            $html .= '<td width="'.$w.'%" style="border:0.3px solid #CBD5E1"><span style="color:#64748B;font-size:8pt">'.$e($s['label'] ?? 'Signature').'</span><br><b>'.$e($s['name'] ?? '').'</b><br><br><br><br><span style="color:#94A3B8;font-size:7.5pt">Date et signature</span></td>';
        }
        $html .= '</tr></table>';
        if ($x->signature_id) {
            $html .= '<br><span style="font-size:7.5pt;color:#64748B">Signature électronique demandée (référence '.$e(DB::table('sg_requests')->where('id', $x->signature_id)->value('number')).') : le document signé et son certificat de preuve sont conservés dans le module Signature.</span>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->SetY(-12);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->Cell(0, 5, $c->name.' — '.$x->number.' — édité le '.now()->format('d/m/Y H:i'), 0, 0, 'C');

        return $pdf->Output('', 'S');
    }
}
