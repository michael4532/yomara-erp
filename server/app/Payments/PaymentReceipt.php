<?php

namespace App\Payments;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Models\PaymentTransaction;
use App\Modules\Ventes\Models\SalesDocument;
use App\Platform\OrderReceipt;
use App\Support\DocVerify;
use App\Support\FrenchNumber;
use App\Support\MailTemplates;

/**
 * Reçu envoyé au client après un paiement en ligne (lien de paiement d'une facture ou lien de paiement libre) :
 * e-mail aux couleurs de la société (modèle « Paiement reçu », modifiable) avec le reçu PDF en pièce jointe
 * et le lien d'authenticité de la facture. Désactivable : Paramètres › Facturation (payment_receipt_email).
 */
class PaymentReceipt
{
    public static function number(SalesDocument $doc, PaymentTransaction $tx): string
    {
        return 'RP-'.$doc->number.'-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($tx->reference ?: $tx->id)), -6));
    }

    /** Envoie le reçu ; ne lève jamais d'exception (le paiement est déjà enregistré). Renvoie true si l'e-mail est parti. */
    public static function send(Company $c, SalesDocument $doc, PaymentTransaction $tx): bool
    {
        if ((($c->settings ?? [])['payment_receipt_email'] ?? true) === false) {
            return false;
        }
        $doc->refresh();
        $payer = (array) data_get($tx->meta, 'payer', []);
        $to = filter_var($payer['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: filter_var($doc->partner?->email, FILTER_VALIDATE_EMAIL);
        if (! $to) {
            return false;
        }
        $name = $doc->partner_name ?: ($payer['name'] ?? null);
        try {
            $money = self::money($doc->currency ?: $c->currency);
            $left = max(0, $doc->balance());
            $vars = MailTemplates::sample($c);
            $vars = [
                'client' => $name ?: 'Madame, Monsieur', 'numero' => $doc->number, 'montant' => $money((float) $tx->amount), 'date' => now()->format('d/m/Y'),
                'reste' => $left > 0.009 ? 'Il reste '.$money($left).' à régler sur cette facture.' : 'Votre facture est entièrement réglée.',
                'entreprise' => $c->name, 'lien' => DocVerify::url($c->id, (string) $doc->id), 'mode' => $tx->gateway?->label ?? 'Paiement en ligne', 'reference' => (string) $tx->reference,
            ] + $vars;
            [$subject, $text] = MailTemplates::render('payment_received', $vars, $c);
            $html = MailTemplates::layout($c, $text, [
                'card' => ['Facture' => $doc->number, 'Montant payé' => $vars['montant'], 'Mode' => $vars['mode'], 'Référence' => $vars['reference'], 'Reste à payer' => $money($left)],
                'button' => ['Voir la facture en ligne', $vars['lien']],
            ]);
            $pdf = self::pdf($c, $doc, $tx);
            $r = app(MessagingService::class)->send($c, 'email', new Message(
                to: $to, toName: $name, subject: $subject, html: $html, text: $text."\n\nFacture en ligne : ".$vars['lien'],
                attachments: [['name' => 'Recu-'.preg_replace('/[^A-Za-z0-9._-]/', '-', self::number($doc, $tx)).'.pdf', 'data' => $pdf, 'mime' => 'application/pdf']],
                replyTo: $c->email,
            ), 'payment_receipt');

            return $r->ok;
        } catch (\Throwable $e) {
            if (! $e instanceof \DomainException) {
                report($e);
            }

            return false;
        }
    }

    protected static function money(?string $cur): \Closure
    {
        $cur = $cur ?: 'XOF';
        $label = in_array($cur, ['XOF', 'XAF'], true) ? 'F CFA' : $cur;

        return fn (float $v) => number_format($v, in_array($cur, ['XOF', 'XAF', 'GNF'], true) ? 0 : 2, ',', ' ').' '.$label;
    }

    protected static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /** Reçu PDF : société, client, facture, montant en chiffres et en lettres, mode et référence, « PAYÉ », QR d'authenticité. */
    public static function pdf(Company $c, SalesDocument $doc, PaymentTransaction $tx): string
    {
        $money = self::money($doc->currency ?: $c->currency);
        $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) (($c->settings ?? [])['brand_color'] ?? '')) ? $c->settings['brand_color'] : '#0B1B3A';
        $night = [11, 27, 58];
        $grey = [91, 107, 133];
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 16, 18);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetTitle('Reçu '.self::number($doc, $tx));
        $pdf->SetAuthor($c->name);
        $pdf->AddPage();
        $pdf->SetFillColor(...self::rgb($accent));
        $pdf->Rect(0, 0, 210, 4, 'F');

        // Société
        $x = 18;
        if ($logo = OrderReceipt::logo($c)) {
            try {
                $pdf->Image('@'.$logo, 18, 14, 0, 18, '', '', '', true, 300, '', false, false, 0, 'LT');
                $x = 18 + 4 + min(46, $pdf->getImageRBX() - 18);
            } catch (\Throwable) {
                $x = 18;
            }
        }
        $pdf->SetXY($x, 14);
        $pdf->SetTextColor(...$night);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(95, 7, $c->name, 0, 1);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(...$grey);
        foreach (array_filter([trim(implode(', ', array_filter([$c->address, $c->city]))), implode(' · ', array_filter([$c->phone, $c->email]))]) as $l) {
            $pdf->SetX($x);
            $pdf->Cell(95, 4.5, $l, 0, 1);
        }
        // Titre
        $pdf->SetXY(120, 14);
        $pdf->SetFont('dejavusans', 'B', 17);
        $pdf->SetTextColor(...self::rgb($accent));
        $pdf->Cell(72, 8, 'REÇU DE PAIEMENT', 0, 1, 'R');
        $pdf->SetX(120);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(...$night);
        $pdf->Cell(72, 5, 'N° '.self::number($doc, $tx), 0, 1, 'R');
        $pdf->SetX(120);
        $pdf->Cell(72, 5, 'Le '.now()->format('d/m/Y à H:i'), 0, 1, 'R');

        // Montant
        $pdf->SetY(52);
        $pdf->SetFillColor(246, 248, 252);
        $pdf->RoundedRect(18, 52, 174, 30, 3, '1111', 'F');
        $pdf->SetXY(24, 56);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(...$grey);
        $pdf->Cell(100, 5, 'Montant reçu', 0, 1);
        $pdf->SetX(24);
        $pdf->SetFont('dejavusans', 'B', 22);
        $pdf->SetTextColor(...$night);
        $pdf->Cell(110, 11, $money((float) $tx->amount), 0, 1);
        $pdf->SetX(24);
        $pdf->SetFont('dejavusans', 'I', 8.5);
        $pdf->SetTextColor(...$grey);
        $cur = $doc->currency ?: $c->currency ?: 'XOF';
        $pdf->MultiCell(120, 4.5, ucfirst(FrenchNumber::words((int) round((float) $tx->amount))).' '.(in_array($cur, ['XOF', 'XAF'], true) ? 'francs CFA' : $cur), 0, 'L');
        // Tampon « PAYÉ »
        $pdf->StartTransform();
        $pdf->Rotate(12, 165, 67);
        $pdf->SetDrawColor(18, 161, 80);
        $pdf->SetTextColor(18, 161, 80);
        $pdf->SetLineWidth(0.8);
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->SetXY(144, 61);
        $pdf->Cell(42, 12, 'PAYÉ', 1, 0, 'C');
        $pdf->StopTransform();

        // Détail
        $left = max(0, $doc->balance());
        $rows = [
            'Reçu de' => $doc->partner_name ?: ((array) data_get($tx->meta, 'payer', []))['name'] ?? '—',
            'Pour la facture' => $doc->number.' du '.optional($doc->date)->format('d/m/Y'),
            'Montant de la facture' => $money((float) $doc->total),
            'Mode de paiement' => $tx->gateway?->label ?? 'Paiement en ligne',
            'Référence de la transaction' => (string) $tx->reference,
            'Reste à payer' => $left > 0.009 ? $money($left) : 'Facture entièrement réglée',
        ];
        $pdf->SetY(92);
        $pdf->SetDrawColor(227, 232, 240);
        $pdf->SetLineWidth(0.25);
        foreach ($rows as $k => $v) {
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->SetTextColor(...$grey);
            $pdf->Cell(70, 8, $k, 'B', 0);
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor(...$night);
            $pdf->Cell(104, 8, (string) $v, 'B', 1, 'R');
        }

        // Authenticité
        $y = $pdf->GetY() + 12;
        $url = DocVerify::url($c->id, (string) $doc->id);
        $pdf->write2DBarcode($url, 'QRCODE,M', 18, $y, 30, 30, ['fgcolor' => $night, 'padding' => 0], 'N');
        $pdf->SetXY(54, $y + 4);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor(...$night);
        $pdf->Cell(130, 6, 'Reçu authentique', 0, 1);
        $pdf->SetX(54);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(...$grey);
        $pdf->MultiCell(130, 4.5, 'Scannez le code pour ouvrir la facture en ligne chez '.$c->name.' : vous y voyez ce paiement et le reste à payer.', 0, 'L');

        // Pied
        $pdf->SetY(-24);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(154, 164, 181);
        $pdf->Cell(0, 4, 'Paiement en ligne reçu et enregistré automatiquement. Envoyé avec Yomara ERP · © '.date('Y').' Yomara Technologies', 0, 1, 'C');

        return $pdf->Output('', 'S');
    }
}
