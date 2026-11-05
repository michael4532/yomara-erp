<?php

namespace App\Modules\Appro;

use App\Models\Company;

/** Mise en page commune des PDF (TCPDF). */
class Pdf
{
    public static function make(string $title, bool $landscape = false): \TCPDF
    {
        $pdf = new \TCPDF($landscape ? 'L' : 'P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setFooterFont(['dejavusans', '', 7]);
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 8.5);

        return $pdf;
    }

    public static function head(Company $c, string $title, string $subtitle): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        return '<table><tr><td width="55%"><b style="font-size:13pt">'.$e($c->name).'</b><br><span style="color:#666">'.$e(trim(($c->address ?? '').' '.($c->city ?? '').' '.($c->phone ?? ''))).'</span></td>'
            .'<td width="45%" align="right"><b style="font-size:14pt;color:#1f6feb">'.$e($title).'</b><br>'.$e($subtitle).'<br><span style="color:#888">'.now()->format('d/m/Y').'</span></td></tr></table><br>';
    }
}
