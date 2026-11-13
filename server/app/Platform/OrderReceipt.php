<?php

namespace App\Platform;

use App\Models\Company;
use App\Models\ModuleOrder;
use App\Services\SiteTraffic;
use App\Support\FrenchNumber;
use Illuminate\Support\Facades\Http;

/**
 * Reçu de paiement d'une commande de la boutique (achat en ligne ou activation manuelle par l'éditeur) :
 * en-tête de l'éditeur (logo, coordonnées, identifiants), client, produit et période, montant en chiffres et en lettres,
 * mode de paiement et référence, mention « PAYÉ ».
 */
class OrderReceipt
{
    public static function filename(ModuleOrder $o): string
    {
        return 'Recu-'.$o->number.'.pdf';
    }

    public static function pdf(ModuleOrder $o): string
    {
        $editor = SiteTraffic::editorCompany() ?? new Company(['name' => config('erp.name', 'Yomara ERP')]);
        $client = Company::withoutGlobalScope('real')->find($o->company_id) ?? new Company(['name' => '—']);
        $cur = $o->currency === 'XOF' || ! $o->currency ? 'FCFA' : $o->currency;
        $money = fn (float $v) => number_format($v, 0, ',', ' ').' '.$cur;
        $paidAt = ($o->paid_at ?? $o->created_at ?? now())->copy()->timezone(config('app.timezone'));

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 16, 18);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetTitle('Reçu '.$o->number);
        $pdf->SetAuthor($editor->name);
        $pdf->AddPage();
        $night = [11, 27, 58];

        // ---- en-tête : éditeur
        $logo = self::logo($editor);
        $x = 18;
        if ($logo) {
            try {
                $pdf->Image('@'.$logo, 18, 16, 0, 18, '', '', '', true, 300, '', false, false, 0, 'LT');
                $x = 18 + 4 + min(46, $pdf->getImageRBX() - 18);
            } catch (\Throwable) {
                $x = 18;
            }
        }
        $pdf->SetXY($x, 16);
        $pdf->SetTextColor(...$night);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(0, 7, $editor->name, 0, 1);
        $pdf->SetX($x);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(91, 107, 133);
        $info = array_filter([
            trim(implode(', ', array_filter([$editor->address, $editor->city, $editor->country === 'CI' ? 'Côte d\'Ivoire' : $editor->country]))),
            implode(' · ', array_filter([$editor->phone ? 'Tél. '.$editor->phone : null, $editor->email])),
            implode(' · ', array_filter([$editor->trade_register ? 'RCCM '.$editor->trade_register : null, $editor->tax_id ? 'NCC '.$editor->tax_id : null])),
        ]);
        $pdf->MultiCell(0, 4.2, implode("\n", $info), 0, 'L', false, 1, $x);

        // ---- titre
        $pdf->SetY(max($pdf->GetY(), 38) + 6);
        $pdf->SetFillColor(...$night);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->Cell(110, 11, '  REÇU DE PAIEMENT', 0, 0, 'L', true);
        $pdf->SetFont('dejavusans', '', 9.5);
        $pdf->Cell(0, 11, 'N° '.$o->number.'   ', 0, 1, 'R', true);
        $pdf->SetTextColor(...$night);
        $pdf->Ln(5);

        // ---- client et date
        $top = $pdf->GetY();
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(91, 107, 133);
        $pdf->Cell(95, 5, 'Reçu de', 0, 0);
        $pdf->Cell(0, 5, 'Date du paiement', 0, 1);
        $pdf->SetTextColor(...$night);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->Cell(95, 6, $client->name, 0, 0);
        $pdf->Cell(0, 6, $paidAt->format('d/m/Y à H:i'), 0, 1);
        $pdf->SetFont('dejavusans', '', 9);
        $lines = array_filter([
            trim(implode(', ', array_filter([$client->address, $client->city]))),
            implode(' · ', array_filter([$client->phone, $client->email])),
            $client->tax_id ? 'NCC '.$client->tax_id : null,
        ]);
        $pdf->MultiCell(95, 4.6, implode("\n", $lines), 0, 'L', false, 0);
        $pdf->MultiCell(0, 4.6, 'Mode de paiement : '.$o->paymentLabel().($o->payer_reference ? "\nRéférence : ".$o->payer_reference : ''), 0, 'L', false, 1);
        $pdf->SetY(max($pdf->GetY(), $top + 26) + 6);

        // ---- détail
        $billing = ModuleOrder::BILLINGS[$o->billing] ?? $o->billing;
        if ($o->billing !== 'one_time' && $o->period_start && $o->period_end) {
            $m = (int) round($o->period_start->floatDiffInMonths($o->period_end));
            $billing = 'Abonnement de '.($m % 12 === 0 && $m >= 12 ? ($m / 12).' an'.($m > 12 ? 's' : '') : $m.' mois');
        }
        $period = $o->period_end
            ? 'Du '.$o->period_start?->format('d/m/Y').' au '.$o->period_end->format('d/m/Y')
            : ($o->period_start ? 'À partir du '.$o->period_start->format('d/m/Y').', sans date de fin' : '');
        $pdf->SetFillColor(241, 244, 250);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(124, 8, '  Désignation', 'B', 0, 'L', true);
        $pdf->Cell(0, 8, 'Montant  ', 'B', 1, 'R', true);
        $pdf->SetFont('dejavusans', 'B', 10.5);
        $y = $pdf->GetY() + 2;
        $pdf->SetXY(18, $y);
        $pdf->MultiCell(124, 6, '  '.$o->label(), 0, 'L', false, 0);
        $pdf->Cell(0, 6, $money((float) $o->base_amount ?: (float) $o->amount).'  ', 0, 1, 'R');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(91, 107, 133);
        $pdf->MultiCell(124, 5, '  '.trim($billing.($period ? ' · '.$period : '')), 0, 'L', false, 1);
        $pdf->SetTextColor(...$night);
        if ((float) $o->discount > 0) {
            $pdf->Cell(124, 6, '  Remise', 0, 0);
            $pdf->Cell(0, 6, '− '.$money((float) $o->discount).'  ', 0, 1, 'R');
        }
        $pdf->Ln(3);
        $pdf->Line(18, $pdf->GetY(), 192, $pdf->GetY());
        $pdf->Ln(3);
        $pdf->SetFont('dejavusans', 'B', 12.5);
        $pdf->Cell(124, 9, '  Total payé', 0, 0);
        $pdf->Cell(0, 9, $money((float) $o->amount).'  ', 0, 1, 'R');
        $pdf->SetFont('dejavusans', '', 9);
        $amount = (int) round((float) $o->amount);
        $pdf->MultiCell(0, 5, $amount > 0
            ? 'Arrêté le présent reçu à la somme de : '.FrenchNumber::words($amount).' '.($cur === 'FCFA' ? 'francs CFA' : $cur).'.'
            : 'Activation offerte : aucun montant n\'a été encaissé.', 0, 'L');

        // ---- cachet « PAYÉ »
        $pdf->Ln(8);
        $sy = $pdf->GetY();
        $pdf->SetDrawColor(18, 161, 80);
        $pdf->SetTextColor(18, 161, 80);
        $pdf->SetLineWidth(.8);
        $pdf->StartTransform();
        $pdf->Rotate(-8, 150, $sy + 10);
        $pdf->RoundedRect(128, $sy, 50, 18, 3, '1111', 'D');
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetXY(128, $sy + 2);
        $pdf->Cell(50, 8, $amount > 0 ? 'PAYÉ' : 'OFFERT', 0, 2, 'C');
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->Cell(50, 5, $paidAt->format('d/m/Y'), 0, 0, 'C');
        $pdf->StopTransform();
        $pdf->SetLineWidth(.2);
        $pdf->SetDrawColor(0, 0, 0);

        // ---- pied
        $pdf->SetTextColor(91, 107, 133);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetXY(18, $sy);
        $pdf->MultiCell(100, 4.6, "Merci pour votre confiance.\nCe reçu confirme le paiement et l'activation de votre service. Conservez-le pour votre comptabilité.", 0, 'L');
        $pdf->SetY(-26);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->Cell(0, 4, $editor->name.($editor->email ? ' · '.$editor->email : '').($editor->phone ? ' · '.$editor->phone : ''), 0, 1, 'C');
        $pdf->Cell(0, 4, '© '.now()->year.' Yomara ERP — un logiciel de Yomara Technologies', 0, 1, 'C');

        return $pdf->Output('', 'S');
    }

    /** Logo de l'éditeur : URL (téléchargée) ou image intégrée (data:image) ; PNG, JPEG ou GIF seulement (TCPDF s'arrête sur une image illisible). */
    protected static function logo(Company $c): ?string
    {
        $raw = self::rawLogo($c);
        $info = $raw ? @getimagesizefromstring($raw) : false;

        return $info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true) ? $raw : null;
    }

    protected static function rawLogo(Company $c): ?string
    {
        $l = (string) $c->logo;
        if ($l === '') {
            return null;
        }
        if (str_starts_with($l, 'data:image') && str_contains($l, ',')) {
            return base64_decode(substr($l, strpos($l, ',') + 1)) ?: null;
        }
        if (str_starts_with($l, 'http')) {
            try {
                $r = Http::timeout(6)->get($l);

                return $r->successful() && strlen($r->body()) < 3_000_000 ? $r->body() : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
