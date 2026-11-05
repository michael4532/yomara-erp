<?php

namespace App\Modules\Tresorerie\Bank;

use Carbon\Carbon;

/**
 * Relevés bancaires normalisés, reconnus automatiquement (sans correspondance de colonnes) :
 *  - OFX / QFX (Money, Quicken, la plupart des banques en ligne) ;
 *  - MT940 (SWIFT, banques d'Afrique de l'Ouest et internationales) ;
 *  - CAMT.053 (ISO 20022 XML, SEPA et banques modernes) ;
 *  - CFONB 120 (format bancaire français, BICICI, SGCI, banques UEMOA).
 * Chaque ligne : date, libellé, montant signé (+ crédit / − débit), référence, identifiant externe, solde éventuel.
 */
class StatementFormats
{
    public const LABELS = ['ofx' => 'OFX', 'mt940' => 'MT940 (SWIFT)', 'camt' => 'CAMT.053 (ISO 20022)', 'cfonb' => 'CFONB 120'];

    public static function detect(string $content): ?string
    {
        $head = ltrim(substr($content, 0, 4000), "\xEF\xBB\xBF \r\n\t");
        if (stripos($head, 'OFXHEADER') !== false || stripos($head, '<OFX>') !== false) {
            return 'ofx';
        }
        if (str_contains($head, 'camt.053') || str_contains($head, '<BkToCstmrStmt')) {
            return 'camt';
        }
        if (preg_match('/^:20:/m', $head) && preg_match('/^:6[01][FM]?:/m', $content)) {
            return 'mt940';
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $ok = 0;
        foreach (array_slice($lines, 0, 20) as $l) {
            if (strlen(rtrim($l)) >= 100 && strlen($l) <= 122 && in_array(substr($l, 0, 2), ['01', '04', '05', '07'], true)) {
                $ok++;
            }
        }

        return $ok >= 2 && $ok >= min(count($lines), 20) * 0.8 ? 'cfonb' : null;
    }

    /** @return array{format: string, lines: array<int, array>, opening: ?float, closing: ?float, currency: ?string} */
    public static function parse(string $content, ?string $format = null): array
    {
        $format ??= self::detect($content);
        if (! $format) {
            throw new \DomainException('Format de relevé non reconnu.');
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $out = match ($format) {
            'ofx' => self::ofx($content),
            'mt940' => self::mt940($content),
            'camt' => self::camt($content),
            'cfonb' => self::cfonb($content),
        };
        $out['lines'] = array_values(array_filter($out['lines'], fn ($l) => $l['date'] && abs($l['amount']) >= 0.005));

        return ['format' => $format] + $out;
    }

    protected static function clean(?string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8')));
    }

    // ---------------------------------------------------------------- OFX (SGML ou XML)
    protected static function ofx(string $c): array
    {
        $tag = function (string $block, string $name): ?string {
            return preg_match('/<'.$name.'>([^<\r\n]*)/i', $block, $m) ? trim($m[1]) : null;
        };
        $date = function (?string $v): ?string {
            return $v && preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m) ? "$m[1]-$m[2]-$m[3]" : null;
        };
        $lines = [];
        preg_match_all('/<STMTTRN>(.*?)(<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $c, $all);
        foreach ($all[1] as $b) {
            $name = self::clean($tag($b, 'NAME'));
            $memo = self::clean($tag($b, 'MEMO'));
            $lines[] = ['date' => $date($tag($b, 'DTPOSTED')), 'label' => trim($name.($memo && $memo !== $name ? ' — '.$memo : '')) ?: 'Opération',
                'amount' => round((float) str_replace(',', '.', (string) $tag($b, 'TRNAMT')), 2), 'reference' => $tag($b, 'CHECKNUM') ?: $tag($b, 'REFNUM'),
                'external_id' => $tag($b, 'FITID'), 'balance' => null];
        }
        $closing = preg_match('/<LEDGERBAL>.*?<BALAMT>([-\d.,]+)/is', $c, $m) ? (float) str_replace(',', '.', $m[1]) : null;

        return ['lines' => $lines, 'opening' => null, 'closing' => $closing, 'currency' => $tag($c, 'CURDEF')];
    }

    // ---------------------------------------------------------------- MT940
    protected static function mt940(string $c): array
    {
        $c = str_replace(["\r\n", "\r"], "\n", $c);
        // Regroupe les champs sur plusieurs lignes : chaque champ commence par :NN[A]:
        preg_match_all('/^:(\d{2}[A-Z]?):(.*?)(?=^:\d{2}[A-Z]?:|^-\}|\z)/ms', $c, $fields, PREG_SET_ORDER);
        $lines = [];
        $opening = $closing = null;
        $currency = null;
        $cur = null;
        $amount = fn (string $v) => (float) str_replace(',', '.', $v);
        foreach ($fields as [, $code, $val]) {
            $val = trim($val);
            if (in_array($code, ['60F', '60M'], true) && preg_match('/^([CD])(\d{6})([A-Z]{3})([\d,]+)/', $val, $m)) {
                $opening = ($m[1] === 'D' ? -1 : 1) * $amount($m[4]);
                $currency = $m[3];
            } elseif (in_array($code, ['62F', '62M'], true) && preg_match('/^([CD])(\d{6})([A-Z]{3})([\d,]+)/', $val, $m)) {
                $closing = ($m[1] === 'D' ? -1 : 1) * $amount($m[4]);
            } elseif ($code === '61' && preg_match('/^(\d{2})(\d{2})(\d{2})(\d{4})?(R?[CD])([A-Z])?([\d,]+)([A-Z][A-Z0-9]{3})?([^\n\/]*)(?:\/\/([^\n]*))?(?:\n(.*))?/s', $val, $m)) {
                if ($cur) {
                    $lines[] = $cur;
                }
                $sign = in_array($m[5], ['D', 'RC'], true) ? -1 : 1;
                $ref = trim($m[9] ?? '');
                $cur = ['date' => '20'.$m[1].'-'.$m[2].'-'.$m[3], 'label' => self::clean($m[11] ?? '') ?: 'Opération', 'amount' => round($sign * $amount($m[7]), 2),
                    'reference' => $ref && $ref !== 'NONREF' ? mb_substr($ref, 0, 120) : null, 'external_id' => trim($m[10] ?? '') ?: null, 'balance' => null];
            } elseif ($code === '86' && $cur) {
                // Informations structurées (?20…?29 libellé, ?32 nom) ou texte libre
                $txt = preg_replace('/\?(2\d|3[0-3]|6\d)/', ' ', str_replace("\n", '', $val));
                $txt = preg_replace('/^\d{3}\?00/', '', $txt);
                $txt = self::clean(preg_replace('/\?\d{2}/', ' ', $txt));
                $cur['label'] = $txt ?: $cur['label'];
            }
        }
        if ($cur) {
            $lines[] = $cur;
        }

        return ['lines' => $lines, 'opening' => $opening, 'closing' => $closing, 'currency' => $currency];
    }

    // ---------------------------------------------------------------- CAMT.053
    protected static function camt(string $c): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($c, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (! $xml) {
            throw new \DomainException('Fichier CAMT.053 illisible (XML invalide).');
        }
        $q = fn ($node, string $path) => $node->xpath(preg_replace('/(^|\/)([A-Za-z]+)/', '$1*[local-name()="$2"]', $path)) ?: [];
        $first = fn ($node, string $path) => ($r = $q($node, $path)) ? trim((string) $r[0]) : null;
        $lines = [];
        $opening = $closing = null;
        foreach ($q($xml, '//Stmt/Bal') as $bal) {
            $type = $first($bal, 'Tp/CdOrPrtry/Cd');
            $v = (float) $first($bal, 'Amt') * ($first($bal, 'CdtDbtInd') === 'DBIT' ? -1 : 1);
            if (in_array($type, ['OPBD', 'PRCD'], true)) {
                $opening = $v;
            } elseif (in_array($type, ['CLBD', 'CLAV'], true) && $closing === null) {
                $closing = $v;
            }
        }
        $currency = ($a = $q($xml, '//Stmt/Acct/Ccy')) ? (string) $a[0] : null;
        foreach ($q($xml, '//Stmt/Ntry') as $n) {
            if (($first($n, 'Sts/Cd') ?? $first($n, 'Sts')) === 'PDNG') {
                continue; // opération en attente : elle arrivera comptabilisée dans un prochain relevé
            }
            $sign = $first($n, 'CdtDbtInd') === 'DBIT' ? -1 : 1;
            $date = $first($n, 'BookgDt/Dt') ?? substr((string) $first($n, 'BookgDt/DtTm'), 0, 10) ?: $first($n, 'ValDt/Dt');
            $party = $sign > 0 ? ($first($n, 'NtryDtls/TxDtls/RltdPties/Dbtr/Nm') ?? $first($n, 'NtryDtls/TxDtls/RltdPties/Dbtr/Pty/Nm'))
                : ($first($n, 'NtryDtls/TxDtls/RltdPties/Cdtr/Nm') ?? $first($n, 'NtryDtls/TxDtls/RltdPties/Cdtr/Pty/Nm'));
            $info = implode(' ', array_map(fn ($x) => trim((string) $x), $q($n, 'NtryDtls/TxDtls/RmtInf/Ustrd'))) ?: $first($n, 'AddtlNtryInf');
            $lines[] = ['date' => $date ? substr($date, 0, 10) : null, 'label' => self::clean(trim(($party ? $party.' — ' : '').$info, ' —')) ?: 'Opération',
                'amount' => round($sign * (float) $first($n, 'Amt'), 2), 'reference' => $first($n, 'NtryDtls/TxDtls/Refs/EndToEndId') ?: $first($n, 'NtryRef'),
                'external_id' => $first($n, 'AcctSvcrRef') ?: $first($n, 'NtryDtls/TxDtls/Refs/AcctSvcrRef'), 'balance' => null];
        }

        return ['lines' => $lines, 'opening' => $opening, 'closing' => $closing, 'currency' => $currency];
    }

    // ---------------------------------------------------------------- CFONB 120
    public static function cfonbAmount(string $raw, int $decimals): float
    {
        $raw = trim($raw);
        $last = substr($raw, -1);
        $pos = '{ABCDEFGHI';
        $neg = '}JKLMNOPQR';
        if (($i = strpos($pos, $last)) !== false) {
            $digits = substr($raw, 0, -1).$i;
            $sign = 1;
        } elseif (($i = strpos($neg, $last)) !== false) {
            $digits = substr($raw, 0, -1).$i;
            $sign = -1;
        } else {
            $digits = $raw;
            $sign = 1;
        }

        return $sign * ((int) ltrim($digits, '0') / (10 ** $decimals));
    }

    protected static function cfonb(string $c): array
    {
        $lines = [];
        $opening = $closing = null;
        $currency = null;
        $date = fn (string $v) => preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $m) ? Carbon::createFromFormat('dmy', $m[1].$m[2].$m[3])->toDateString() : null;
        foreach (preg_split('/\r\n|\n|\r/', $c) as $l) {
            $l = str_pad($l, 120);
            $rec = substr($l, 0, 2);
            $dec = (int) substr($l, 19, 1);
            if ($rec === '01') {
                $opening = self::cfonbAmount(substr($l, 90, 14), $dec);
                $currency = trim(substr($l, 16, 3)) ?: null;
            } elseif ($rec === '07') {
                $closing = self::cfonbAmount(substr($l, 90, 14), $dec);
            } elseif ($rec === '04') {
                $lines[] = ['date' => $date(substr($l, 34, 6)), 'label' => self::clean(substr($l, 48, 31)) ?: 'Opération',
                    'amount' => round(self::cfonbAmount(substr($l, 90, 14), $dec), 2), 'reference' => trim(substr($l, 104, 16)) ?: null,
                    'external_id' => trim(substr($l, 81, 7)) ? trim(substr($l, 34, 6)).'-'.trim(substr($l, 81, 7)) : null, 'balance' => null];
            } elseif ($rec === '05' && $lines) {
                $extra = self::clean(substr($l, 48, 70));
                if ($extra) {
                    $lines[count($lines) - 1]['label'] = mb_substr($lines[count($lines) - 1]['label'].' '.$extra, 0, 500);
                }
            }
        }

        return ['lines' => $lines, 'opening' => $opening, 'closing' => $closing, 'currency' => $currency];
    }
}
