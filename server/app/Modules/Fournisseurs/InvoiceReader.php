<?php

namespace App\Modules\Fournisseurs;

use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Core\Models\Partner;
use Carbon\Carbon;

/**
 * Lecture automatique d'une facture fournisseur : texte du PDF (pdftotext, sinon analyseur PHP),
 * images lues par Tesseract s'il est installé ; puis n°, date, montants, NCC, fournisseur et bon de commande.
 */
class InvoiceReader
{
    public function text(string $data, string $ext): string
    {
        $ext = strtolower($ext);
        $text = '';
        if ($ext === 'pdf') {
            if ($bin = $this->bin('pdftotext')) {
                $tmp = tempnam(sys_get_temp_dir(), 'pdf');
                file_put_contents($tmp, $data);
                $text = (string) @shell_exec(escapeshellarg($bin).' -layout -enc UTF-8 '.escapeshellarg($tmp).' - 2>/dev/null');
                @unlink($tmp);
            }
            if (trim($text) === '') {
                try {
                    $text = (new \Smalot\PdfParser\Parser)->parseContent($data)->getText();
                } catch (\Throwable) {
                    $text = '';
                }
            }
        } elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff'], true) && ($bin = $this->bin('tesseract'))) {
            $tmp = tempnam(sys_get_temp_dir(), 'img').'.'.$ext;
            file_put_contents($tmp, $data);
            $text = (string) @shell_exec(escapeshellarg($bin).' '.escapeshellarg($tmp).' - -l fra+eng 2>/dev/null');
            if (trim($text) === '') {
                $text = (string) @shell_exec(escapeshellarg($bin).' '.escapeshellarg($tmp).' - 2>/dev/null');
            }
            @unlink($tmp);
        }

        return trim(preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $text)));
    }

    public function ocrAvailable(): bool
    {
        return (bool) $this->bin('tesseract');
    }

    protected function bin(string $name): ?string
    {
        if (! function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        $p = trim((string) @shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $p !== '' ? $p : null;
    }

    /** Montant écrit à la française ou à l'anglaise : « 1 234 567 », « 1.234.567,50 », « 1,234,567.50 ». */
    public static function amount(string $s): ?float
    {
        $s = preg_replace('/[^\d,.\s ]/u', '', $s);
        $s = trim(preg_replace('/[\s ]+/u', ' ', $s));
        if ($s === '') {
            return null;
        }
        $s = str_replace(' ', '', $s);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s)) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $s)) {
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
            if (substr_count($s, '.') > 1) {
                $s = preg_replace('/\.(?=.*\.)/', '', $s);
            }
        }

        return is_numeric($s) ? round((float) $s, 2) : null;
    }

    protected static function date(string $s): ?string
    {
        $months = ['janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12, 'decembre' => 12];
        try {
            if (preg_match('/(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/', $s, $m)) {
                $y = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

                return checkdate((int) $m[2], (int) $m[1], $y) ? Carbon::create($y, (int) $m[2], (int) $m[1])->toDateString() : null;
            }
            if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
                return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
            }
            if (preg_match('/(\d{1,2})(?:er)?\s+('.implode('|', array_keys($months)).')\s+(\d{4})/iu', $s, $m)) {
                return Carbon::create((int) $m[3], $months[mb_strtolower($m[2])], (int) $m[1])->toDateString();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** Champs extraits du texte (null quand ils ne sont pas trouvés). */
    public function extract(string $text): array
    {
        $t = $text;
        $amountRe = '((?:\d{1,3}(?:[\s .,]\d{3})+|\d+)(?:[.,]\d{1,2})?)';
        $find = function (array $labels) use ($t, $amountRe): ?float {
            foreach ($labels as $l) {
                if (preg_match_all('/'.$l.'[^\d\n]{0,40}'.$amountRe.'/iu', $t, $m)) {
                    $vals = array_values(array_filter(array_map([self::class, 'amount'], $m[1]), fn ($v) => $v !== null && $v > 0));
                    if ($vals) {
                        return end($vals); // le dernier total de la page (souvent le récapitulatif)
                    }
                }
            }

            return null;
        };
        $total = $find(['net\s+[àa]\s+payer', 'total\s+t\.?t\.?c\.?', 'montant\s+t\.?t\.?c\.?', 'total\s+[àa]\s+payer', 'montant\s+total', 'total\s+g[ée]n[ée]ral', 'amount\s+due', 'grand\s+total', '\btotal\b']);
        $ht = $find(['total\s+h\.?t\.?', 'montant\s+h\.?t\.?', 'sous[- ]total', 'total\s+hors\s+taxes?', 'subtotal']);
        $tva = $find(['total\s+t\.?v\.?a\.?', 'montant\s+t\.?v\.?a\.?', 't\.?v\.?a\.?\s*(?:\(?\s*18\s*%\s*\)?)?', 'vat']);
        $number = null;
        if (preg_match('/factur[e]?\s*(?:proforma\s*)?(?:n[°o]|num[ée]ro|no\.?|#|réf\.?)\s*[:.\-]?\s*([A-Z0-9][A-Z0-9\-\/_.]{2,30})/iu', $t, $m)
            || preg_match('/invoice\s*(?:n[°o]|number|no\.?|#)\s*[:.\-]?\s*([A-Z0-9][A-Z0-9\-\/_.]{2,30})/iu', $t, $m)
            || preg_match('/\bN[°o]\s*(?:de\s+)?(?:facture)?\s*[:.]?\s*([A-Z]{1,6}[\-\/]?\d[\w\-\/]{2,25})/u', $t, $m)) {
            $number = rtrim($m[1], '.-/');
        }
        $date = null;
        if (preg_match('/(?:date\s*(?:de\s+(?:la\s+)?facture|d\'émission|facture)?|le|du|invoice\s+date)\s*[:]?\s*([0-3]?\d[\/.\-][01]?\d[\/.\-]\d{2,4}|\d{1,2}(?:er)?\s+\p{L}+\s+\d{4}|\d{4}-\d{2}-\d{2})/iu', $t, $m)) {
            $date = self::date($m[1]);
        }
        $date ??= preg_match('/([0-3]?\d[\/.\-][01]?\d[\/.\-]\d{4})/', $t, $m) ? self::date($m[1]) : null;
        $ncc = preg_match('/(?:N\.?C\.?C\.?|compte\s+contribuable)\s*(?:n[°o])?\s*[:.]?\s*(\d{6,8}\s?[A-Z])/iu', $t, $m) ? strtoupper(str_replace(' ', '', $m[1])) : null;
        $orders = preg_match_all('/\b(BCF|BC|PO)[\-\s]?(\d{4})[\-\s]?(\d{3,6})\b/i', $t, $m, PREG_SET_ORDER) ? array_values(array_unique(array_map(fn ($x) => strtoupper($x[1]).'-'.$x[2].'-'.$x[3], $m))) : [];
        preg_match_all('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $t, $emails);
        preg_match_all('/(?:\+?225[\s.]?)?(?:0[1257]|2[157])(?:[\s.]?\d{2}){4}/', $t, $phones);
        $currency = preg_match('/\b(EUR|€|USD|\$|XOF|FCFA|F\s?CFA|XAF)\b/u', $t, $m) ? (['€' => 'EUR', '$' => 'USD', 'FCFA' => 'XOF', 'F CFA' => 'XOF', 'FCFA' => 'XOF'][$m[1]] ?? (str_contains($m[1], 'CFA') ? 'XOF' : $m[1])) : null;
        if ($total && $ht && $total < $ht) {
            [$total, $ht] = [$ht, $total];
        }

        return ['number' => $number, 'date' => $date, 'total' => $total, 'subtotal' => $ht, 'tax' => $tva, 'ncc' => $ncc, 'orders' => $orders,
            'emails' => array_values(array_unique(array_map('strtolower', $emails[0]))), 'phones' => array_values(array_unique($phones[0])), 'currency' => $currency];
    }

    /** Fournisseur reconnu : NCC, adresse e-mail, téléphone, puis nom présent dans le texte. */
    public function supplier(array $x, string $text): ?Partner
    {
        $q = Partner::whereIn('type', ['supplier', 'both']);
        if ($x['ncc'] && ($p = (clone $q)->get()->first(fn ($p) => $p->tax_id && strtoupper(str_replace(' ', '', $p->tax_id)) === $x['ncc']))) {
            return $p;
        }
        foreach ($x['emails'] as $e) {
            if ($p = (clone $q)->where('email', $e)->first()) {
                return $p;
            }
        }
        foreach ($x['phones'] as $ph) {
            $tail = substr(preg_replace('/\D/', '', $ph), -8);
            if (strlen($tail) === 8 && ($p = (clone $q)->whereNotNull('phone')->get()->first(fn ($p) => str_ends_with(preg_replace('/\D/', '', $p->phone), $tail)))) {
                return $p;
            }
        }
        $low = mb_strtolower($text);
        $best = null;
        foreach ((clone $q)->get() as $p) {
            $n = mb_strtolower(trim(preg_replace('/\b(sarl|sa|sas|suarl|ci|sarlu)\b\.?/iu', '', $p->name)));
            if (mb_strlen($n) >= 4 && str_contains($low, $n) && (! $best || mb_strlen($n) > mb_strlen($best[1]))) {
                $best = [$p, $n];
            }
        }

        return $best[0] ?? null;
    }

    /** Bon de commande : n° cité sur la facture, sinon commande validée du fournisseur au montant proche (±2 %). */
    public function order(array $x, ?Partner $p): ?PurchaseDocument
    {
        $q = PurchaseDocument::where('type', 'purchase_order')->where('status', 'validated');
        foreach ($x['orders'] as $n) {
            if ($o = (clone $q)->where('number', $n)->when($p, fn ($qq) => $qq->where('partner_id', $p->id))->first()) {
                return $o;
            }
        }
        if ($p && $x['total']) {
            $cands = (clone $q)->where('partner_id', $p->id)->get()->filter(fn ($o) => abs($o->total - $x['total']) <= max(1, $o->total * 0.02));

            return $cands->count() === 1 ? $cands->first() : null;
        }

        return null;
    }

    /** Lecture complète : texte, champs, fournisseur et commande proposés. */
    public function read(string $data, string $ext): array
    {
        $text = $this->text($data, $ext);
        $x = $this->extract($text);
        $p = $this->supplier($x, $text);
        $o = $this->order($x, $p);
        $p ??= $o ? Partner::find($o->partner_id) : null;
        $found = count(array_filter([$x['number'], $x['date'], $x['total']]));

        return $x + ['partner_id' => $p?->id, 'partner_name' => $p?->name, 'order_id' => $o?->id, 'order_number' => $o?->number, 'text_length' => mb_strlen($text),
            'confidence' => $text === '' ? 0 : (int) round(100 * ($found + ($p ? 1 : 0)) / 4), 'readable' => $text !== ''];
    }
}
