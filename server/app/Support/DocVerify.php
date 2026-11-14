<?php

namespace App\Support;

use App\Models\Company;
use App\Modules\Ventes\Models\SalesDocument;
use App\Payments\InvoicePayments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Authenticité des documents de vente : le code QR imprimé mène à la version en ligne du document,
 * tenue par Yomara ERP. Le lien porte une signature (HMAC sur la clé de l'application) : impossible à deviner
 * ou à fabriquer pour un faux document, et un document modifié à la main ne correspond plus à la page en ligne.
 */
class DocVerify
{
    public static function token(string $companyId, string $docId): string
    {
        return substr(hash_hmac('sha256', 'doc-verify:'.$companyId.':'.$docId, (string) config('app.key')), 0, 20);
    }

    public static function url(string|int $companyId, string $docId): string
    {
        return url('/v/'.$docId.'/'.self::token((string) $companyId, $docId));
    }

    public function show(Request $r, string $doc, string $t)
    {
        $row = DB::table('trade_documents')->where('id', $doc)->first(['company_id', 'direction']);
        if (! $row || $row->direction !== 'sale' || ! hash_equals(self::token((string) $row->company_id, $doc), $t)) {
            return $this->page(null, null, 'Ce code ne correspond à aucun document émis avec Yomara ERP. Le document que vous avez entre les mains n\'est peut-être pas authentique : contactez l\'émetteur.', 404);
        }
        $company = Company::findOrFail($row->company_id);
        app()->instance('tenant', $company);
        $d = SalesDocument::with('lines')->findOrFail($doc);
        if ($d->status === 'draft') {
            return $this->page($company, null, 'Ce document est encore un brouillon chez l\'émetteur : il n\'a pas de valeur tant qu\'il n\'est pas validé.', 200);
        }

        return $this->page($company, $d, null, 200);
    }

    protected function page(?Company $c, ?SalesDocument $d, ?string $msg, int $status)
    {
        $e = fn ($v) => e((string) $v);
        $cur = $d?->currency ?: ($c?->currency ?: 'XOF');
        $money = fn ($v) => number_format((float) $v, in_array($cur, ['XOF', 'XAF', 'GNF'], true) ? 0 : 2, ',', ' ').' '.($cur === 'XOF' || $cur === 'XAF' ? 'F CFA' : $cur);
        $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '';
        $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) (($c?->settings ?? [])['brand_color'] ?? '')) ? $c->settings['brand_color'] : '#2E6BE6';
        $logo = $c?->logo && preg_match('#^https?://#', (string) $c->logo) ? '<img src="'.$e($c->logo).'" alt="" style="max-height:48px;max-width:200px">' : '';
        $head = $c ? $logo.'<div style="font-size:20px;font-weight:800;color:#0B1B3A;margin-top:6px">'.$e($c->name).'</div>'
            .'<div style="color:#5B6B85;font-size:13px">'.$e(implode(' · ', array_filter([$c->address, $c->city, $c->phone, $c->email]))).'</div>' : '';
        $body = '';
        if ($d) {
            $label = SalesDocument::TYPES[$d->type][0] ?? 'Document';
            [$badge, $bcol] = match (true) {
                in_array($d->status, ['cancelled', 'void'], true) => ['Document annulé', '#D93025'],
                $d->type === 'credit_note' => ['Avoir authentique', '#12A150'],
                in_array($d->type, SalesDocument::PAYABLE_TYPES, true) && $d->balance() <= 0.009 => ['Authentique · Payée', '#12A150'],
                in_array($d->type, SalesDocument::PAYABLE_TYPES, true) && $d->amount_paid > 0 => ['Authentique · Partiellement payée', '#B26A00'],
                in_array($d->type, SalesDocument::PAYABLE_TYPES, true) => ['Authentique · À payer', '#2E6BE6'],
                default => ['Document authentique', '#12A150'],
            };
            $lines = '';
            foreach ($d->lines as $l) {
                $lines .= '<tr><td style="padding:8px 6px;border-bottom:1px solid #EEF1F6">'.$e($l->description).'</td><td style="padding:8px 6px;border-bottom:1px solid #EEF1F6;text-align:right;white-space:nowrap">'
                    .$e(rtrim(rtrim(number_format((float) $l->quantity, 2, ',', ' '), '0'), ',')).'</td><td style="padding:8px 6px;border-bottom:1px solid #EEF1F6;text-align:right;white-space:nowrap">'.$money($l->total ?? ((float) $l->quantity * (float) $l->unit_price)).'</td></tr>';
            }
            $fne = DB::getSchemaBuilder()->hasTable('fne_certifications') ? DB::table('fne_certifications')->where('document_id', $d->id)->whereNotNull('reference')->first() : null;
            $pay = '';
            if (in_array($d->type, ['invoice', 'receipt'], true) && $d->status === 'validated' && $d->balance() > 0.009 && InvoicePayments::gateways($c)->filter->isOnline()->isNotEmpty()) {
                $pay = '<a href="'.$e(InvoicePayments::url(app(InvoicePayments::class)->link($d))).'" style="display:block;text-align:center;background:'.$accent.';color:#fff;text-decoration:none;font-weight:700;padding:14px;border-radius:12px;margin-top:18px">Payer en ligne ('.$money($d->balance()).')</a>';
            }
            $body = '<div style="display:inline-block;background:'.$bcol.'1A;color:'.$bcol.';font-weight:800;padding:8px 14px;border-radius:30px;font-size:14px">✓ '.$e($badge).'</div>'
                .'<h1 style="margin:14px 0 2px;font-size:22px;color:#0B1B3A">'.$e($label).' '.$e($d->number).'</h1>'
                .'<div style="color:#5B6B85;font-size:14px">Émis le '.$date($d->date).($d->due_date && in_array($d->type, SalesDocument::PAYABLE_TYPES, true) ? ' · échéance '.$date($d->due_date) : '').'</div>'
                .'<table style="width:100%;margin-top:16px;font-size:14px;border-collapse:collapse"><tr><td style="color:#5B6B85;padding:4px 0">Client</td><td style="text-align:right;font-weight:700">'.$e($d->partner_name ?: '—').'</td></tr>'
                .($d->reference ? '<tr><td style="color:#5B6B85;padding:4px 0">Référence</td><td style="text-align:right">'.$e($d->reference).'</td></tr>' : '').'</table>'
                .'<table style="width:100%;margin-top:14px;font-size:13.5px;border-collapse:collapse"><tr style="background:#F6F8FC;color:#5B6B85"><th style="text-align:left;padding:8px 6px">Désignation</th><th style="text-align:right;padding:8px 6px">Qté</th><th style="text-align:right;padding:8px 6px">Total</th></tr>'.$lines.'</table>'
                .'<table style="width:100%;margin-top:12px;font-size:14px"><tr><td style="color:#5B6B85">Total HT</td><td style="text-align:right">'.$money($d->subtotal).'</td></tr>'
                .'<tr><td style="color:#5B6B85">Taxes</td><td style="text-align:right">'.$money($d->tax_total).'</td></tr>'
                .'<tr><td style="font-weight:800;font-size:16px;padding-top:6px">Total TTC</td><td style="text-align:right;font-weight:800;font-size:16px;padding-top:6px">'.$money($d->total).'</td></tr>'
                .(in_array($d->type, SalesDocument::PAYABLE_TYPES, true) ? '<tr><td style="color:#5B6B85">Déjà réglé</td><td style="text-align:right">'.$money($d->amount_paid).'</td></tr><tr><td style="font-weight:700">Reste à payer</td><td style="text-align:right;font-weight:700">'.$money(max(0, $d->balance())).'</td></tr>' : '')
                .'</table>'
                .($fne ? '<div style="margin-top:14px;padding:10px 12px;background:#F6F8FC;border-radius:10px;font-size:13px">Facture normalisée DGI n° <b>'.$e($fne->reference).'</b></div>' : '')
                .$pay
                .'<p style="color:#5B6B85;font-size:12.5px;line-height:1.5;margin-top:18px">Comparez cette page avec le document reçu : numéro, client et montants doivent être identiques. Une différence signale un document modifié.</p>';
        } else {
            $body = '<div style="display:inline-block;background:#D930251A;color:#D93025;font-weight:800;padding:8px 14px;border-radius:30px;font-size:14px">! Vérification impossible</div><p style="color:#1B2433;line-height:1.55">'.$e($msg).'</p>';
        }

        return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Vérification du document</title></head>'
            .'<body style="margin:0;background:#EEF2F8;font-family:system-ui,Segoe UI,Helvetica,Arial,sans-serif;color:#1B2433">'
            .'<div style="max-width:620px;margin:0 auto;padding:20px 14px">'
            .'<div style="background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 10px 40px rgba(11,27,58,.08)"><div style="height:5px;background:'.$accent.'"></div><div style="padding:22px 22px 26px">'
            .$head.($head ? '<hr style="border:0;border-top:1px solid #EEF1F6;margin:18px 0">' : '').$body.'</div></div>'
            .'<p style="text-align:center;color:#8A94A6;font-size:12px;margin-top:14px">Vérifié le '.now()->format('d/m/Y à H:i').' · Yomara ERP · © '.date('Y').' Yomara Technologies</p></div></body></html>', $status)
            ->header('X-Robots-Tag', 'noindex')->header('Referrer-Policy', 'no-referrer');
    }
}
