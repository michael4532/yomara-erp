<?php

namespace App\Modules\Signature;

use App\Models\Company;
use App\Models\User;
use App\Modules\Signature\Models\SignEvent;
use App\Modules\Signature\Models\Signer;
use App\Modules\Signature\Models\SignRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Document signé : pages d'origine (mention de signature en pied de page), page des signatures,
 * certificat de preuve (journal horodaté) puis cachet numérique de l'entreprise (signature PDF).
 * Si le PDF d'origine ne peut pas être relu (compression récente), il est joint au fichier signé.
 */
class SignaturePdf
{
    public function __construct(protected SignatureService $svc) {}

    public static function countPages(string $pdf): ?int
    {
        try {
            $f = new Fpdi;

            return $f->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf));
        } catch (\Throwable) {
            $n = preg_match_all('#/Type\s*/Page[^s]#', $pdf);

            return $n ?: null;
        }
    }

    /** Cachet de l'entreprise : certificat auto-signé créé à la première signature. */
    public function certificate(Company $company): array
    {
        $path = 'signatures/'.$company->id.'/cachet.json';
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            $j = json_decode($disk->get($path), true);
            try {
                return [$j['cert'], Crypt::decryptString($j['key'])];
            } catch (\Throwable) {
                // clé d'application changée : nouveau cachet
            }
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $dn = array_filter(['commonName' => mb_substr($company->name, 0, 60).' — cachet '.config('erp.name', 'Yomara ERP'), 'organizationName' => mb_substr($company->name, 0, 60),
            'localityName' => $company->city ?: null, 'stateOrProvinceName' => $company->city ?: 'Abidjan', 'countryName' => strtoupper(substr($company->country ?: 'CI', 0, 2)), 'emailAddress' => $company->email ?: null]);
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $crt = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($crt, $certPem);
        openssl_pkey_export($key, $keyPem);
        $disk->put($path, json_encode(['cert' => $certPem, 'key' => Crypt::encryptString($keyPem), 'created_at' => now()->toIso8601String()]));

        return [$certPem, $keyPem];
    }

    public function seal(Company $company, SignRequest $r): void
    {
        $disk = Storage::disk('local');
        $original = $disk->get($r->original_path);
        $pdf = $this->newPdf($company, $r);
        $stamped = $this->importPages($pdf, $original, $r);
        if (! $stamped) {
            $this->coverPage($pdf, $company, $r, $disk->path($r->original_path));
        }
        $this->signaturesPage($pdf, $company, $r);
        $this->certificatePage($pdf, $company, $r);
        [$cert, $key] = $this->certificate($company);
        $pdf->setSignature($cert, $key, '', '', 2, [
            'Name' => $company->name, 'Location' => $company->city ?: '', 'Reason' => 'Signature électronique '.$r->number, 'ContactInfo' => $company->email ?: '',
        ]);
        $bytes = $pdf->Output('', 'S');
        $path = 'signatures/'.$company->id.'/'.$r->id.'/signe.pdf';
        $disk->put($path, $bytes);
        $r->forceFill(['signed_path' => $path, 'signed_sha256' => hash('sha256', $bytes), 'stamped' => $stamped])->save();
    }

    protected function newPdf(Company $company, SignRequest $r): Fpdi
    {
        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator(config('erp.name', 'Yomara ERP'));
        $pdf->SetAuthor($company->name);
        $pdf->SetTitle($r->title.' — signé');
        $pdf->SetSubject('Signature électronique '.$r->number);
        $pdf->SetMargins(16, 16, 16);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->SetFont('dejavusans', '', 10);

        return $pdf;
    }

    /** Reprend chaque page d'origine avec une mention discrète en pied de page. */
    protected function importPages(Fpdi $pdf, string $original, SignRequest $r): bool
    {
        $src = $original;
        try {
            $n = $pdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($src));
        } catch (\Throwable) {
            $src = $this->qpdf($original);
            if (! $src) {
                return false;
            }
            try {
                $n = $pdf->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($src));
            } catch (\Throwable) {
                return false;
            }
        }
        $pdf->SetAutoPageBreak(false);
        for ($i = 1; $i <= $n; $i++) {
            $t = $pdf->importPage($i);
            $s = $pdf->getTemplateSize($t);
            $pdf->AddPage($s['orientation'], [$s['width'], $s['height']]);
            $pdf->useTemplate($t);
            $pdf->SetFont('dejavusans', '', 6.5);
            $pdf->SetTextColor(110, 120, 140);
            $pdf->SetXY(8, $s['height'] - 6.5);
            $pdf->Cell($s['width'] - 16, 4, 'Signé électroniquement — réf. '.$r->number.' — page '.$i.'/'.$n.' — certificat de preuve en fin de document', 0, 0, 'C');
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetAutoPageBreak(true, 16);
        if (! $r->pages) {
            $r->forceFill(['pages' => $n])->save();
        }

        return true;
    }

    /** Sur VPS, qpdf (s'il est installé) réécrit les PDF récents dans un format relisible. */
    protected function qpdf(string $original): ?string
    {
        if (! config('erp.signature_qpdf', true) || ! function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        $bin = trim((string) @shell_exec('command -v qpdf 2>/dev/null'));
        if ($bin === '') {
            return null;
        }
        $in = tempnam(sys_get_temp_dir(), 'sgi');
        $out = $in.'-out.pdf';
        file_put_contents($in, $original);
        @exec(escapeshellarg($bin).' --object-streams=disable --decrypt '.escapeshellarg($in).' '.escapeshellarg($out).' 2>&1', $o, $code);
        $res = is_file($out) && in_array($code, [0, 3], true) ? file_get_contents($out) : null;
        @unlink($in);
        @unlink($out);

        return $res;
    }

    protected function coverPage(Fpdi $pdf, Company $company, SignRequest $r, string $originalAbs): void
    {
        $pdf->AddPage();
        $pdf->writeHTML($this->head($company, 'Document signé électroniquement').
            '<p style="font-size:12pt"><b>'.e($r->title).'</b></p>'.
            '<p>Le document d\'origine <b>'.e($r->original_name).'</b> est joint à ce fichier (icône trombone, ou panneau « Pièces jointes » de votre lecteur PDF). '.
            'Son empreinte SHA-256 ci-dessous permet de vérifier qu\'il n\'a pas été modifié.</p>'.
            '<p style="font-family:dejavusansmono;font-size:8pt">'.$r->original_sha256.'</p>');
        $pdf->Annotation(16, $pdf->GetY() + 4, 6, 6, 'Document original : '.$r->original_name, ['Subtype' => 'FileAttachment', 'Name' => 'Paperclip', 'FS' => $originalAbs]);
        $pdf->SetY($pdf->GetY() + 12);
        $pdf->writeHTML('<p style="color:#5B6B85;font-size:9pt">Les signatures et le certificat de preuve figurent dans les pages suivantes.</p>');
    }

    protected function head(Company $company, string $title): string
    {
        return '<table cellpadding="0"><tr><td width="1.5%" style="background-color:#FFC531"></td><td width="2.5%"></td><td width="96%"><span style="font-size:15pt;font-weight:bold;color:#0B1B3A">'.e($title).'</span><br>'.
            '<span style="font-size:9pt;color:#5B6B85">'.e($company->name).' — '.e(config('erp.name', 'Yomara ERP')).'</span></td></tr></table><br>';
    }

    protected function verification(Signer $s): string
    {
        return match ($s->verified_by) {
            'sms' => 'code reçu par SMS au '.Signer::mask($s->phone),
            'whatsapp' => 'code reçu par WhatsApp au '.Signer::mask($s->phone),
            'email' => 'code reçu par e-mail à '.Signer::mask($s->email),
            'account' => 'compte utilisateur connecté',
            default => $s->notified_at && ($s->email || $s->phone) ? 'lien personnel envoyé à '.Signer::mask($s->email ?: $s->phone) : 'lien personnel transmis par l\'émetteur',
        };
    }

    protected function signaturesPage(Fpdi $pdf, Company $company, SignRequest $r): void
    {
        $pdf->AddPage();
        $cells = [];
        foreach ($r->signers as $s) {
            $img = $s->signature_path && Storage::disk('local')->exists($s->signature_path) ? base64_encode(Storage::disk('local')->get($s->signature_path)) : null;
            $cells[] = '<td width="50%" style="border:1px solid #E3E8F1;padding:8px">'.
                '<span style="font-size:8pt;color:#5B6B85">'.e($s->label ?: 'Signataire').'</span><br><b>'.e($s->name).'</b><br>'.
                ($img ? '<img src="@'.$img.'" height="70">' : '<br><br><br>').'<br>'.
                '<span style="font-size:7.5pt;color:#5B6B85">Signé le '.e($s->signed_at ? $this->svc->at($company, $s->signed_at) : '—').'<br>Vérification : '.e($this->verification($s)).'</span></td>';
        }
        $rows = '';
        foreach (array_chunk($cells, 2) as $pair) {
            $rows .= '<tr>'.implode('', $pair).(count($pair) === 1 ? '<td width="50%"></td>' : '').'</tr>';
        }
        $pdf->writeHTML($this->head($company, 'Signatures').
            '<p><span style="font-size:12pt;font-weight:bold">'.e($r->title).'</span><br><span style="font-size:9pt;color:#5B6B85">Référence '.e($r->number).' — chaque signataire a lu et approuvé le document ci-dessus.</span></p>'.
            '<table cellpadding="8" cellspacing="6">'.$rows.'</table>');
    }

    protected function certificatePage(Fpdi $pdf, Company $company, SignRequest $r): void
    {
        $pdf->AddPage();
        $creator = $r->created_by ? User::withoutGlobalScopes()->find($r->created_by)?->name : null;
        $kv = fn ($k, $v) => '<tr><td width="32%" style="color:#5B6B85">'.e($k).'</td><td width="68%">'.$v.'</td></tr>';
        $html = $this->head($company, 'Certificat de preuve').
            '<table cellpadding="3" style="font-size:9pt">'.
            $kv('Référence', '<b>'.e($r->number).'</b>').
            $kv('Document', e($r->title)).
            $kv('Fichier d\'origine', e($r->original_name).($r->pages ? ' — '.$r->pages.' page(s)' : '')).
            $kv('Empreinte SHA-256 de l\'original', '<span style="font-family:dejavusansmono;font-size:7.5pt">'.$r->original_sha256.'</span>').
            $kv('Émis par', e($company->name).($creator ? ' — '.e($creator) : '')).
            $kv('Envoyé le', e($r->sent_at ? $this->svc->at($company, $r->sent_at) : '—')).
            $kv('Terminé le', e($r->completed_at ? $this->svc->at($company, $r->completed_at) : '—')).
            '</table><p style="font-size:11pt;font-weight:bold;color:#0B1B3A">Signataires</p><table cellpadding="4" style="font-size:8.5pt" border="0">'.
            '<tr style="background-color:#0B1B3A;color:#FFFFFF"><td width="26%">Signataire</td><td width="30%">Vérification de l\'identité</td><td width="24%">Signé le</td><td width="20%">Adresse IP</td></tr>';
        foreach ($r->signers as $i => $s) {
            $html .= '<tr style="background-color:'.($i % 2 ? '#F4F6FB' : '#FFFFFF').'"><td><b>'.e($s->name).'</b>'.($s->label ? '<br><span style="color:#5B6B85">'.e($s->label).'</span>' : '').'</td><td>'.e($this->verification($s)).'</td><td>'.e($s->signed_at ? $this->svc->at($company, $s->signed_at) : '—').'</td><td>'.e($s->ip ?: '—').'</td></tr>';
        }
        $tzName = $this->svc->tz($company);
        $html .= '</table><p style="font-size:11pt;font-weight:bold;color:#0B1B3A">Journal <span style="font-weight:normal;font-size:8pt;color:#5B6B85">(heures de '.e($tzName).', UTC'.now($tzName)->format('P').')</span></p><table cellpadding="3" style="font-size:7.8pt">';
        $names = $r->signers->keyBy('id');
        foreach ($r->events as $ev) {
            $who = $ev->signer_id ? ($names[$ev->signer_id]->name ?? '') : (($ev->data['by'] ?? null) ?: '');
            $extra = collect([
                isset($ev->data['channel']) ? (['email' => 'e-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'app' => 'application'][$ev->data['channel']] ?? $ev->data['channel']).(isset($ev->data['to']) ? ' '.$ev->data['to'] : '') : null,
                $ev->data['reason'] ?? null, isset($ev->data['order']) ? 'commande '.$ev->data['order'] : null,
            ])->filter()->implode(' — ');
            $html .= '<tr><td width="18%">'.e($ev->created_at->copy()->setTimezone($tzName)->format('d/m/Y H:i:s')).'</td><td width="30%">'.e(SignEvent::LABELS[$ev->type] ?? $ev->type).'</td><td width="36%">'.e(trim($who.($extra ? ' — '.$extra : ''), ' —')).'</td><td width="16%" style="color:#5B6B85">'.e($ev->ip ?: '').'</td></tr>';
        }
        $html .= '</table><br><p style="font-size:8pt;color:#5B6B85">Ce fichier est scellé par le cachet numérique de '.e($company->name).' : toute modification ultérieure le rend invalide (vérifiable dans les propriétés de signature du lecteur PDF). '.
            'Pour contrôler l\'authenticité d\'un exemplaire, déposez-le sur '.e(rtrim(config('app.url'), '/').'/signature/verifier').'.</p>';
        $pdf->writeHTML($html);
    }
}
