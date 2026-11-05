<?php

namespace App\Modules\Fonds;

use App\Models\Company;
use App\Models\User;
use App\Modules\Fonds\Models\FundEvent;
use App\Modules\Fonds\Models\FundLine;
use App\Modules\Fonds\Models\FundRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Formulaire d'appel de fonds personnalisable (logo, couleurs, titre, numérotation, listes de choix, visas,
 * pied de page légal, taux par défaut) et son rendu PDF, inspiré du « Formulaire de demande d'appel de fonds » papier.
 */
class FundForm
{
    public const DEFAULTS = [
        'title' => 'FORMULAIRE DE DEMANDE D\'APPEL DE FONDS',
        'prefix' => 'AF',
        'logo_mode' => 'company',          // company | custom | none
        'logo_position' => 'center',       // center | left
        'logo_height' => 16,               // mm
        'color_primary' => '#0B4C8C',      // titres et bandeaux
        'color_accent' => '#E07600',       // cadre du motif et filets de l'en-tête
        'color_visas' => '#006C3B',        // bandeau des visas
        'motives' => ['Fournisseur / prestataire', 'Salaires et charges sociales', 'Frais de mission', 'Charges courantes', 'Impôts et taxes', 'Avance / provision', 'Investissement'],
        'payment_methods' => ['Espèces', 'Chèque', 'Virement bancaire', 'Mobile Money', 'Wave', 'Julaya'],
        'attachments' => ['Devis', 'Facture', 'Bon de commande', 'Demande de mission', 'Contrat / convention'],
        'visas' => ['L\'émetteur de la demande', 'Supérieur hiérarchique', 'Chargé des achats', 'Chef comptable', 'Direction générale', 'Bénéficiaire (reçu des fonds)'],
        'sections' => ['motive' => true, 'beneficiary' => true, 'payment' => true, 'accounting' => true, 'visas' => true, 'remarks' => true],
        'default_discount' => null,
        'default_vat' => null,
        'tax_types' => [],                  // [{label, rate, base: net|gross}]
        'footer' => ['postal_address' => '', 'address' => '', 'phone' => '', 'email' => '', 'website' => '', 'rccm' => '', 'tax_id' => '', 'legal' => '', 'text' => ''],
    ];

    public const FOOTER_LABELS = ['postal_address' => 'Adresse postale', 'address' => 'Adresse physique', 'phone' => 'Tél.', 'email' => 'E-mail', 'website' => 'Site web',
        'rccm' => 'RCCM', 'tax_id' => 'CC', 'legal' => '', 'text' => ''];

    public const URGENCY = ['normal' => 'Normal', 'urgent' => 'Urgent', 'very_urgent' => 'Très urgent'];

    /** Réglages du formulaire, complétés par les valeurs par défaut et les coordonnées de la société. */
    public static function settings(Company $c): array
    {
        $s = (array) data_get($c->settings, 'fonds.form', []);
        $out = array_replace(self::DEFAULTS, array_intersect_key($s, self::DEFAULTS));
        $out['sections'] = array_replace(self::DEFAULTS['sections'], (array) ($s['sections'] ?? []));
        $footer = array_replace(self::DEFAULTS['footer'], (array) ($s['footer'] ?? []));
        // pied de page prérempli avec la fiche de la société tant qu'il n'a pas été réglé
        if (! isset($s['footer'])) {
            $footer = array_replace($footer, array_filter([
                'address' => trim(($c->address ?? '').' '.($c->city ?? '')), 'phone' => $c->phone, 'email' => $c->email, 'rccm' => $c->trade_register, 'tax_id' => $c->tax_id,
            ]));
        }
        $out['footer'] = $footer;
        $out['custom_logo_url'] = ! empty($s['logo_path']) ? Storage::disk('public')->url($s['logo_path']).'?v='.substr(md5((string) $s['logo_path']), 0, 8) : null;
        $out['company_logo'] = (bool) $c->logo;

        return $out;
    }

    public static function rules(): array
    {
        return [
            'title' => 'nullable|string|max:120', 'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/'],
            'logo_mode' => 'nullable|in:company,custom,none', 'logo_position' => 'nullable|in:center,left', 'logo_height' => 'nullable|integer|min:8|max:40',
            'color_primary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'color_accent' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'color_visas' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'motives' => 'nullable|array|max:20', 'motives.*' => 'nullable|string|max:60',
            'payment_methods' => 'nullable|array|max:15', 'payment_methods.*' => 'nullable|string|max:60',
            'attachments' => 'nullable|array|max:15', 'attachments.*' => 'nullable|string|max:60',
            'visas' => 'nullable|array|max:12', 'visas.*' => 'nullable|string|max:60',
            'sections' => 'nullable|array', 'sections.*' => 'boolean',
            'default_discount' => 'nullable|numeric|min:0|max:100', 'default_vat' => 'nullable|numeric|min:0|max:100',
            'tax_types' => 'nullable|array|max:10', 'tax_types.*.label' => 'required|string|max:60', 'tax_types.*.rate' => 'required|numeric|min:0|max:100', 'tax_types.*.base' => 'nullable|in:net,gross',
            'footer' => 'nullable|array', 'footer.*' => 'nullable|string|max:300',
        ];
    }

    public static function save(Company $c, array $d): array
    {
        $settings = $c->settings ?? [];
        $cur = (array) data_get($settings, 'fonds.form', []);
        foreach (array_keys(self::DEFAULTS) as $k) {
            if (! array_key_exists($k, $d)) {
                continue;
            }
            $v = $d[$k];
            if (in_array($k, ['motives', 'payment_methods', 'attachments', 'visas'], true)) {
                $v = array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), (array) $v))));
            } elseif ($k === 'sections') {
                $v = array_map('boolval', array_intersect_key((array) $v, self::DEFAULTS['sections']));
            } elseif ($k === 'footer') {
                $v = array_map(fn ($x) => trim((string) $x), array_intersect_key((array) $v, self::DEFAULTS['footer']));
            } elseif ($k === 'tax_types') {
                $v = array_values(array_map(fn ($t) => ['label' => trim((string) $t['label']), 'rate' => (float) $t['rate'], 'base' => ($t['base'] ?? 'net') === 'gross' ? 'gross' : 'net'], (array) $v));
            } elseif ($k === 'prefix') {
                $v = strtoupper(trim((string) $v)) ?: 'AF';
            } elseif (is_string($v)) {
                $v = trim($v);
            }
            $cur[$k] = $v;
        }
        data_set($settings, 'fonds.form', $cur);
        $c->update(['settings' => $settings]);

        return self::settings($c->fresh());
    }

    public static function saveLogo(Company $c, UploadedFile $f): array
    {
        $old = data_get($c->settings, 'fonds.form.logo_path');
        $ext = in_array(strtolower($f->getClientOriginalExtension()), ['png', 'jpg', 'jpeg'], true) ? strtolower($f->getClientOriginalExtension()) : 'png';
        $path = 'fonds/logos/'.$c->id.'-'.Str::random(10).'.'.($ext === 'jpeg' ? 'jpg' : $ext);
        Storage::disk('public')->put($path, (string) file_get_contents($f->getRealPath()));
        $settings = $c->settings ?? [];
        data_set($settings, 'fonds.form.logo_path', $path);
        data_set($settings, 'fonds.form.logo_mode', 'custom');
        $c->update(['settings' => $settings]);
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return self::settings($c->fresh());
    }

    public static function deleteLogo(Company $c): array
    {
        $settings = $c->settings ?? [];
        if ($p = data_get($settings, 'fonds.form.logo_path')) {
            Storage::disk('public')->delete($p);
        }
        data_set($settings, 'fonds.form.logo_path', null);
        if (data_get($settings, 'fonds.form.logo_mode') === 'custom') {
            data_set($settings, 'fonds.form.logo_mode', 'company');
        }
        $c->update(['settings' => $settings]);

        return self::settings($c->fresh());
    }

    /** Image du logo pour le PDF (contenu binaire), selon le réglage : logo du formulaire, logo de la société ou aucun. */
    public static function logoBytes(Company $c, array $s): ?string
    {
        if ($s['logo_mode'] === 'none') {
            return null;
        }
        $custom = data_get($c->settings, 'fonds.form.logo_path');
        if ($s['logo_mode'] === 'custom' && $custom && Storage::disk('public')->exists($custom)) {
            return Storage::disk('public')->get($custom);
        }
        $l = (string) $c->logo;
        if (str_starts_with($l, 'data:image') && str_contains($l, ',')) {
            return base64_decode(substr($l, strpos($l, ',') + 1)) ?: null;
        }
        if (preg_match('#/storage/(.+)$#', (string) parse_url($l, PHP_URL_PATH), $m) && Storage::disk('public')->exists(urldecode($m[1]))) {
            return Storage::disk('public')->get(urldecode($m[1]));
        }
        if (str_starts_with($l, 'https://')) {
            $ctx = stream_context_create(['http' => ['timeout' => 4]]);

            return @file_get_contents($l, false, $ctx) ?: null;
        }

        return null;
    }

    /**
     * Montants du formulaire : brut (lignes), remise (pourcentage ou montant), net avant taxes, taxes (TVA et autres,
     * calculées sur le net ou le brut) et total à décaisser. Une remise en montant s'applique au prorata d'un brut réduit.
     *
     * @return array{gross: float, discount: float, net: float, taxes: array<int, array{label:string, rate:float, base:string, amount:float}>, taxes_total: float, total: float}
     */
    public static function compute(float $gross, string $mode, float $value, array $taxes, ?float $referenceGross = null): array
    {
        $gross = round(max(0, $gross), 2);
        if ($mode === 'amount') {
            $ref = $referenceGross ?? $gross;
            $discount = $ref > 0 ? min($gross, round($value * $gross / $ref, 2)) : 0.0;
        } else {
            $discount = round($gross * min(100, max(0, $value)) / 100, 2);
        }
        $net = round($gross - $discount, 2);
        $rows = [];
        foreach ($taxes as $t) {
            $rate = (float) ($t['rate'] ?? 0);
            $base = ($t['base'] ?? 'net') === 'gross' ? 'gross' : 'net';
            $rows[] = ['label' => (string) ($t['label'] ?? 'Taxe'), 'rate' => $rate, 'base' => $base, 'amount' => round(($base === 'gross' ? $gross : $net) * $rate / 100, 2)];
        }
        $tt = round(array_sum(array_column($rows, 'amount')), 2);

        return ['gross' => $gross, 'discount' => $discount, 'net' => $net, 'taxes' => $rows, 'taxes_total' => $tt, 'total' => round($net + $tt, 2)];
    }

    // ------------------------------------------------------------------ PDF

    public static function pdf(Company $c, FundRequest $r, FundService $svc, ?\Illuminate\Support\Collection $sample = null): string
    {
        $s = self::settings($c);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $num = fn ($v) => $e(number_format((float) $v, 0, ',', ' '));
        $qty = fn ($v) => $e(rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ','));
        $cur = in_array($c->currency, ['XOF', 'XAF', null], true) ? 'FCFA' : $c->currency;
        $P = $s['color_primary'];
        $A = $s['color_accent'];
        $V = $s['color_visas'];
        $box = fn (bool $on) => '<span style="font-family:dejavusans;font-size:9pt">'.($on ? '☒' : '☐').'</span>';
        $sec = $s['sections'];
        $lines = $sample ?? FundLine::where('request_id', $r->id)->orderBy('sort')->orderBy('id')->get();
        $amounts = self::compute((float) $lines->sum('amount'), (string) $r->discount_mode, (float) $r->discount_value, (array) ($r->taxes ?? []));
        $users = User::whereIn('id', array_filter([$r->requester_id, $r->approver_id, $r->provider_id]))->get()->keyBy('id');
        $requester = $users[$r->requester_id] ?? null;

        $pdf = new FundFormPdf('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->footerLines = self::footerLines($s['footer']);
        $pdf->footerColor = $P;
        $pdf->SetCreator('Yomara ERP');
        $pdf->SetTitle('Appel de fonds '.($r->number ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->SetMargins(14, 12, 14);
        $pdf->SetAutoPageBreak(true, $pdf->footerLines ? 22 : 14);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 8.5);

        // En-tête : logo (centré entre deux filets, ou à gauche)
        $logo = self::logoBytes($c, $s);
        $h = (float) $s['logo_height'];
        if ($logo) {
            $size = @getimagesizefromstring($logo);
            $w = $size ? min(70, $h * $size[0] / max(1, $size[1])) : $h * 2;
            $x = $s['logo_position'] === 'left' ? 14 : (210 - $w) / 2;
            $pdf->Image('@'.$logo, $x, 12, $w, $h, '', '', '', true, 300);
            if ($s['logo_position'] === 'center') {
                $y = 12 + $h / 2;
                $pdf->SetLineStyle(['width' => 0.6, 'color' => self::rgb($P)]);
                $pdf->Line($x - 38, $y, $x - 22, $y);
                $pdf->Line($x + $w + 22, $y, $x + $w + 38, $y);
                $pdf->SetLineStyle(['width' => 0.6, 'color' => self::rgb($A)]);
                $pdf->Line($x - 22, $y, $x - 6, $y);
                $pdf->Line($x + $w + 6, $y, $x + $w + 22, $y);
            }
            $pdf->SetY(12 + $h + 3);
        } else {
            $pdf->SetY(12);
            $pdf->writeHTML('<div style="text-align:center;font-size:14pt;font-weight:bold;color:'.$P.'">'.$e($c->name).'</div>', true, false, true, false, '');
        }

        $th = 'style="background-color:#EEF3F8;color:'.$P.';font-weight:bold"';
        $gap = '<div style="font-size:4pt;line-height:4pt">&nbsp;</div>';
        $band = fn (string $t, string $col) => $gap.'<table cellpadding="2"><tr><td style="background-color:'.$col.';color:#FFFFFF;font-weight:bold;font-size:8pt">&nbsp;'.$e($t).'</td></tr></table>';
        $html = '<div style="text-align:center;font-size:12.5pt;font-weight:bold;color:'.$P.'">'.$e($s['title']).'</div>';
        $html .= '<table cellpadding="3" border="0.3" style="border-color:#C9D3DF"><tr><td width="25%" '.$th.'>N° de référence</td><td width="25%">'.$e($r->number ?? 'Brouillon').'</td>'
            .'<td width="25%" '.$th.'>Date de la demande</td><td width="25%">'.$e(($r->submitted_at ?? $r->created_at)?->format('d/m/Y')).'</td></tr>'
            .'<tr><td '.$th.'>Échéance souhaitée</td><td>'.$e($r->needed_by?->format('d/m/Y') ?? '___ / ___ / ______').'</td>'
            .'<td '.$th.'>Montant global ('.$e($cur).')</td><td><b>'.$num($amounts['total']).'</b></td></tr></table>';

        // Motif (une seule case)
        if ($sec['motive']) {
            $items = [];
            foreach ($s['motives'] as $m) {
                $items[] = $box($r->motive === $m).' '.$e($m);
            }
            $items[] = $box($r->motive === 'other').' Autre : '.($r->motive === 'other' && $r->motive_other ? '<b>'.$e($r->motive_other).'</b>' : '..............................');
            // cadre de la couleur d'accent : tableau coloré dont la cellule intérieure est claire
            $html .= $gap.'<table cellpadding="1.2" style="background-color:'.$A.'"><tr><td><table cellpadding="4"><tr><td style="background-color:#F6F9FC"><span style="font-size:7pt;font-weight:bold;color:'.$P.'">MOTIF DE L\'APPEL DE FONDS</span> <i style="font-size:6.5pt;color:#555">(une seule case)</i><br>'
                .implode('&nbsp;&nbsp; ', $items).'</td></tr></table></td></tr></table>';
        }

        // 1. Émetteur / bénéficiaire
        $n = 1;
        if ($sec['beneficiary']) {
            $html .= $band($n++.'. Émetteur de la demande / Bénéficiaire', $P);
            $html .= '<table cellpadding="3" border="0.3" style="border-color:#C9D3DF">'
                .'<tr><td width="25%" '.$th.'>Nom et prénoms</td><td width="25%">'.$e($requester?->name).'</td><td width="25%" '.$th.'>Fonction</td><td width="25%">'.$e($r->requester_function).'</td></tr>'
                .'<tr><td '.$th.'>Service</td><td>'.$e($r->department).'</td><td '.$th.'>Projet / activité</td><td>'.$e($r->project_label).'</td></tr>'
                .'<tr><td '.$th.'>Bénéficiaire</td><td>'.$e($r->beneficiary).'</td><td '.$th.'>Structure</td><td>'.$e($r->beneficiary_structure).'</td></tr></table>';
        }

        // 2. Détails du décaissement
        $html .= $band($n++.'. Détails du décaissement', $P);
        $html .= '<table cellpadding="3" border="0.3" style="border-color:#C9D3DF"><tr><td width="25%" '.$th.'>Objet du décaissement</td><td width="75%"><b>'.$e($r->title).'</b>'.($r->purpose ? '<br>'.nl2br($e($r->purpose)) : '').'</td></tr></table>';
        $html .= '<table cellpadding="3" border="0.3" style="border-color:#C9D3DF"><tr '.$th.'><td width="7%">N°</td><td width="45%">Description des dépenses</td><td width="9%" align="right">Qté</td><td width="17%" align="right">Prix unitaire ('.$e($cur).')</td><td width="22%" align="right">Montant ('.$e($cur).')</td></tr>';
        foreach ($lines as $i => $l) {
            $html .= '<tr><td>'.sprintf('%02d', $i + 1).'</td><td>'.$e($l->description).($l->note ? '<br><span style="color:#666;font-size:7pt">'.$e($l->note).'</span>' : '').'</td><td align="right">'.$qty($l->quantity).'</td><td align="right">'.$num($l->unit_amount).'</td><td align="right">'.$num($l->amount).'</td></tr>';
        }
        $sum = fn (string $label, string $v, bool $strong = false) => '<tr><td colspan="4" align="right" style="background-color:#EEF3F8;color:'.$P.';font-weight:bold">'.$label.'</td><td align="right"'.($strong ? ' style="font-weight:bold"' : '').'>'.$v.'</td></tr>';
        $html .= $sum('MONTANT BRUT', $num($amounts['gross']));
        if ($amounts['discount'] > 0) {
            $html .= $sum('Remise'.($r->discount_mode === 'percent' ? ' ('.$e(rtrim(rtrim(number_format((float) $r->discount_value, 2, ',', ''), '0'), ',')).' %)' : ''), '- '.$num($amounts['discount']));
            $html .= $sum('MONTANT NET AVANT TAXES', $num($amounts['net']));
        }
        foreach ($amounts['taxes'] as $t) {
            $html .= $sum($e($t['label']).' — '.$e(rtrim(rtrim(number_format($t['rate'], 2, ',', ''), '0'), ',')).' % sur '.($t['base'] === 'gross' ? 'le brut' : 'le net'), $num($t['amount']));
        }
        if ($amounts['taxes']) {
            $html .= $sum('TOTAL DES TAXES', $num($amounts['taxes_total']));
        }
        $html .= '<tr><td colspan="4" align="right" style="background-color:'.$P.';color:#FFFFFF;font-weight:bold">TOTAL À DÉCAISSER</td><td align="right" style="background-color:'.$P.';color:#FFFFFF;font-weight:bold">'.$num($amounts['total']).' '.$e($cur).'</td></tr>';
        if (in_array($r->status, ['approved', 'partial', 'provided', 'closed'], true)) {
            $html .= '<tr><td colspan="4" align="right" style="font-weight:bold;color:#006C3B">Montant accordé</td><td align="right" style="font-weight:bold;color:#006C3B">'.$num($r->total_approved).'</td></tr>';
        }
        $html .= '</table>';

        // 3. Modalités de paiement
        if ($sec['payment']) {
            $pay = [];
            foreach ($s['payment_methods'] as $m) {
                $pay[] = $box($r->payment_method === $m).' '.$e($m);
            }
            $pay[] = $box($r->payment_method === 'other').' Autre : '.($r->payment_method === 'other' ? $e($r->payment_other) : '.........');
            $urg = [];
            $prio = $r->priority ?: 'normal';
            foreach (self::URGENCY as $k => $lab) {
                $urg[] = $box($prio === $k).' '.$lab;
            }
            $att = [];
            $sel = (array) ($r->attachments ?? []);
            foreach ($s['attachments'] as $a) {
                $att[] = $box(in_array($a, $sel, true)).' '.$e($a);
            }
            $att[] = $box((bool) $r->attachments_other).' Autre : '.($r->attachments_other ? $e($r->attachments_other) : '.........');
            $html .= $band($n++.'. Modalités de paiement', $P);
            $html .= '<table cellpadding="3" border="0.3" style="border-color:#C9D3DF"><tr><td width="25%" '.$th.'>Mode de paiement</td><td width="75%">'.implode('&nbsp;&nbsp; ', $pay).'</td></tr>'
                .'<tr><td '.$th.'>Niveau d\'urgence</td><td>'.implode('&nbsp;&nbsp; ', $urg).'</td></tr>'
                .'<tr><td '.$th.'>Pièces jointes</td><td>'.implode('&nbsp;&nbsp; ', $att).'</td></tr></table>';
        }

        // 4. Comptabilité
        if ($sec['accounting']) {
            $yn = fn (?bool $v, string $yes, string $no) => $box($v === true).' '.$yes.'&nbsp;&nbsp; '.$box($v === false).' '.$no;
            $html .= $band($n++.'. Cadre réservé à la comptabilité', '#3A3A3A');
            $html .= '<table cellpadding="3" border="0.3" style="border-color:#C9D3DF"><tr><td width="25%" '.$th.'>Budget disponible</td><td width="25%">'.$yn($r->budget_available, 'Oui', 'Non').'</td>'
                .'<td width="25%" '.$th.'>Vérification des pièces</td><td width="25%">'.$yn($r->documents_compliant, 'Conforme', 'Non conforme').'</td></tr>'
                .($r->accounting_note ? '<tr><td '.$th.'>Observation</td><td colspan="3">'.nl2br($e($r->accounting_note)).'</td></tr>' : '').'</table>';
        }

        // 5. Visas : noms et dates remplis d'après le circuit quand c'est possible
        if ($sec['visas'] && $s['visas']) {
            $ev = FundEvent::where('request_id', $r->id)->orderBy('id')->get();
            $at = fn (string $type) => $ev->firstWhere('type', $type)?->created_at?->format('d/m/Y');
            $auto = [0 => [$requester?->name, $at('submitted')]];
            $done = [];
            $html .= $band($n++.'. Visas et approbations', $V);
            $html .= '<table cellpadding="4" border="0.3" style="border-color:#C9D3DF"><tr style="background-color:'.$V.';color:#FFFFFF;font-weight:bold"><td width="28%">Visa / rôle</td><td width="28%">Nom et prénoms</td><td width="26%">Signature</td><td width="18%">Date</td></tr>';
            foreach (array_values($s['visas']) as $i => $role) {
                [$who, $when] = $auto[$i] ?? [null, null];
                if (preg_match('/hi[ée]rarch|direct|responsable/i', $role) && $r->approver_id && $r->decided_at && ! $who && $i > 0 && ! isset($done['approver'])) {
                    $who = $users[$r->approver_id]?->name;
                    $when = $r->decided_at->format('d/m/Y');
                    $done['approver'] = true;
                }
                if (preg_match('/b[ée]n[ée]ficiaire|re[çc]u/i', $role) && $r->closed_at) {
                    $who = $r->beneficiary ?: $requester?->name;
                    $when = $r->closed_at->format('d/m/Y');
                }
                $html .= '<tr><td style="background-color:#EEF3F8;color:'.$P.';font-weight:bold">'.$e($role).'</td><td>'.$e($who).'</td><td><br><br></td><td>'.$e($when ?? '___/___/______').'</td></tr>';
            }
            $html .= '</table>';
        }

        if ($sec['remarks']) {
            $txt = trim(implode("\n", array_filter([$r->remarks, $r->decision_note ? 'Décision : '.$r->decision_note : null])));
            $html .= $gap.'<table cellpadding="4" border="0.3" style="border-color:#C9D3DF"><tr><td width="25%" '.$th.'>Remarques / suggestions</td><td width="75%" height="50">'.nl2br($e($txt)).'</td></tr></table>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /** Aperçu du modèle avec des données d'exemple (rien n'est enregistré). */
    public static function preview(Company $c, User $u, FundService $svc): string
    {
        $s = self::settings($c);
        $r = new FundRequest(['company_id' => $c->id, 'requester_id' => $u->id, 'number' => ($s['prefix'] ?: 'AF').'-'.now()->year.'-00001', 'title' => 'Achat de fournitures pour la mission terrain',
            'department' => 'Opérations', 'requester_function' => 'Chef de projet', 'project_label' => 'Projet pilote', 'beneficiary' => 'Fournisseur ABC SARL', 'beneficiary_structure' => 'ABC SARL',
            'motive' => $s['motives'][0] ?? 'other', 'payment_method' => $s['payment_methods'][2] ?? ($s['payment_methods'][0] ?? null), 'priority' => 'urgent',
            'attachments' => array_slice($s['attachments'], 0, 2), 'discount_mode' => 'percent', 'discount_value' => (float) ($s['default_discount'] ?? 0),
            'taxes' => $s['default_vat'] ? [['label' => 'TVA', 'rate' => (float) $s['default_vat'], 'base' => 'net']] : array_slice($s['tax_types'], 0, 1),
            'needed_by' => now()->addDays(7), 'status' => 'draft', 'remarks' => 'Exemple : ce document est un aperçu du modèle.']);
        $r->id = 0;
        $r->created_at = now();
        $lines = collect([['Fournitures de bureau', 10, 2500], ['Carburant (aller-retour)', 2, 35000], ['Hébergement — 2 nuits', 2, 25000]])
            ->map(fn ($x) => new FundLine(['description' => $x[0], 'quantity' => $x[1], 'unit_amount' => $x[2], 'amount' => $x[1] * $x[2]]));

        return self::pdf($c, $r, $svc, $lines);
    }

    /** @return array<int, string> */
    protected static function footerLines(array $f): array
    {
        $parts = [];
        foreach (['postal_address', 'address', 'phone', 'email', 'website'] as $k) {
            if (trim((string) ($f[$k] ?? '')) !== '') {
                $parts[] = (self::FOOTER_LABELS[$k] ? self::FOOTER_LABELS[$k].' : ' : '').trim($f[$k]);
            }
        }
        $legal = [];
        foreach (['rccm', 'tax_id', 'legal'] as $k) {
            if (trim((string) ($f[$k] ?? '')) !== '') {
                $legal[] = (self::FOOTER_LABELS[$k] ? self::FOOTER_LABELS[$k].' : ' : '').trim($f[$k]);
            }
        }

        return array_values(array_filter([implode(' · ', $parts), implode(' · ', $legal), trim((string) ($f['text'] ?? ''))]));
    }

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }
}
