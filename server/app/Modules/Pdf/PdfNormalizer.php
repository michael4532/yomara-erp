<?php

namespace App\Modules\Pdf;

/**
 * Réécrit un PDF « moderne » (tables de références et objets compressés, PDF 1.5+) en PDF classique
 * que FPDI sait lire, sans outil externe. Utilise qpdf s'il est présent sur le serveur (plus rapide, plus robuste).
 */
class PdfNormalizer
{
    public static function qpdf(): ?string
    {
        $bin = (string) config('erp.qpdf_path', '');
        if ($bin !== '' && is_executable($bin)) {
            return $bin;
        }
        if (! function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }
        foreach (['/usr/bin/qpdf', '/usr/local/bin/qpdf', '/opt/homebrew/bin/qpdf'] as $p) {
            if (@is_executable($p)) {
                return $p;
            }
        }

        return null;
    }

    /** Fichier lisible par FPDI (le même si rien à faire). */
    public static function normalize(string $in, string $out): string
    {
        $raw = (string) file_get_contents($in);
        if (str_contains($raw, '/Encrypt')) {
            throw new \DomainException('Ce PDF est protégé par un mot de passe : utilisez d\'abord « Déverrouiller ».');
        }
        $modern = (bool) preg_match('~/Type\s*/(XRef|ObjStm)\b~', $raw);
        if (! $modern) {
            return $in;
        }
        if ($bin = self::qpdf()) {
            @exec(escapeshellarg($bin).' --object-streams=disable --stream-data=preserve '.escapeshellarg($in).' '.escapeshellarg($out).' 2>&1', $o, $code);
            if (in_array($code, [0, 3], true) && is_file($out) && filesize($out) > 0) {
                return $out;
            }
        }
        file_put_contents($out, self::rewrite($raw));

        return $out;
    }

    // ------------------------------------------------------------------ réécriture en PHP

    /** @return array{0: array<int, array{0:int,1:int,2:int}>, 1: string} entrées [num => [type, a, b]] et dictionnaire du trailer le plus récent */
    protected static function readXref(string $raw): array
    {
        if (! preg_match_all('~startxref\s+(\d+)~', $raw, $m)) {
            throw new \DomainException('PDF illisible (table des références absente).');
        }
        $offset = (int) end($m[1]);
        $entries = [];
        $trailer = null;
        $seen = [];
        while ($offset > 0 && ! isset($seen[$offset])) {
            $seen[$offset] = true;
            $chunk = substr($raw, $offset, 64);
            if (str_starts_with(ltrim($chunk), 'xref')) {
                // Table classique
                $pos = $offset + strpos($chunk, 'xref') + 4;
                $tpos = strpos($raw, 'trailer', $pos);
                $table = substr($raw, $pos, $tpos - $pos);
                $lines = preg_split('/\r\n|\r|\n/', trim($table));
                $cur = 0;
                foreach ($lines as $ln) {
                    if (preg_match('/^(\d+)\s+(\d+)$/', trim($ln), $h)) {
                        $cur = (int) $h[1];

                        continue;
                    }
                    if (preg_match('/^(\d{10})\s+(\d{5})\s+([nf])/', trim($ln), $e)) {
                        if (! isset($entries[$cur]) && $e[3] === 'n') {
                            $entries[$cur] = [1, (int) $e[1], (int) $e[2]];
                        } elseif (! isset($entries[$cur])) {
                            $entries[$cur] = [0, 0, 0];
                        }
                        $cur++;
                    }
                }
                $dict = self::dictAt($raw, $tpos + 7);
                $trailer ??= $dict;
                // Fichier hybride : flux de références complémentaire
                if (preg_match('~/XRefStm\s+(\d+)~', $dict, $xs)) {
                    [$e2] = self::xrefStream($raw, (int) $xs[1]);
                    $entries += $e2;
                }
                $offset = preg_match('~/Prev\s+(\d+)~', $dict, $p) ? (int) $p[1] : 0;
            } else {
                [$e2, $dict] = self::xrefStream($raw, $offset);
                $entries += $e2;
                $trailer ??= $dict;
                $offset = preg_match('~/Prev\s+(\d+)~', $dict, $p) ? (int) $p[1] : 0;
            }
        }

        return [$entries, (string) $trailer];
    }

    /** Dictionnaire << … >> commençant à $pos (en tenant compte des dictionnaires imbriqués). */
    protected static function dictAt(string $raw, int $pos): string
    {
        $start = strpos($raw, '<<', $pos);
        $depth = 0;
        $len = strlen($raw);
        for ($i = $start; $i < $len - 1; $i++) {
            if ($raw[$i] === '<' && $raw[$i + 1] === '<') {
                $depth++;
                $i++;
            } elseif ($raw[$i] === '>' && $raw[$i + 1] === '>') {
                $depth--;
                $i++;
                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return substr($raw, $start);
    }

    protected static function streamData(string $raw, int $objOffset, string $dict): string
    {
        $s = strpos($raw, 'stream', $objOffset + strpos(substr($raw, $objOffset), $dict) + strlen($dict));
        $s += 6;
        if ($raw[$s] === "\r") {
            $s++;
        }
        if ($raw[$s] === "\n") {
            $s++;
        }
        $length = preg_match('~/Length\s+(\d+)(?!\s+\d+\s+R)~', $dict, $l) ? (int) $l[1] : null;
        $data = $length !== null ? substr($raw, $s, $length) : substr($raw, $s, strpos($raw, 'endstream', $s) - $s);
        if (preg_match('~/Filter\s*/FlateDecode|/Filter\s*\[\s*/FlateDecode\s*\]~', $dict)) {
            $dec = @gzuncompress($data);
            if ($dec === false) {
                $dec = @gzinflate(substr($data, 2));
            }
            if ($dec === false) {
                throw new \DomainException('Flux compressé illisible dans ce PDF.');
            }
            $data = $dec;
        }
        // Prédicteur PNG (souvent « Up ») des flux de références
        if (preg_match('~/Predictor\s+(\d+)~', $dict, $pr) && (int) $pr[1] >= 10) {
            $cols = preg_match('~/Columns\s+(\d+)~', $dict, $c) ? (int) $c[1] : 1;
            $data = self::unpredict($data, $cols);
        }

        return $data;
    }

    protected static function unpredict(string $data, int $cols): string
    {
        $out = '';
        $prev = array_fill(0, $cols, 0);
        $rowLen = $cols + 1;
        for ($i = 0; $i + $rowLen <= strlen($data); $i += $rowLen) {
            $type = ord($data[$i]);
            $row = [];
            for ($j = 0; $j < $cols; $j++) {
                $x = ord($data[$i + 1 + $j]);
                $left = $j > 0 ? $row[$j - 1] : 0;
                $up = $prev[$j];
                $ul = $j > 0 ? $prev[$j - 1] : 0;
                $row[$j] = match ($type) {
                    1 => ($x + $left) & 0xFF,
                    2 => ($x + $up) & 0xFF,
                    3 => ($x + intdiv($left + $up, 2)) & 0xFF,
                    4 => ($x + self::paeth($left, $up, $ul)) & 0xFF,
                    default => $x,
                };
            }
            $out .= implode('', array_map('chr', $row));
            $prev = $row;
        }

        return $out;
    }

    protected static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    /** @return array{0: array<int, array{0:int,1:int,2:int}>, 1: string} */
    protected static function xrefStream(string $raw, int $offset): array
    {
        $dict = self::dictAt($raw, $offset);
        $data = self::streamData($raw, $offset, $dict);
        preg_match('~/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s*\]~', $dict, $w) || throw new \DomainException('Table de références compressée illisible.');
        [$w1, $w2, $w3] = [(int) $w[1], (int) $w[2], (int) $w[3]];
        $size = preg_match('~/Size\s+(\d+)~', $dict, $s) ? (int) $s[1] : 0;
        $index = preg_match('~/Index\s*\[([\d\s]+)\]~', $dict, $ix) ? array_map('intval', preg_split('/\s+/', trim($ix[1]))) : [0, $size];
        $num = fn (string $b) => $b === '' ? 0 : (int) hexdec(bin2hex($b));
        $entries = [];
        $p = 0;
        $rec = $w1 + $w2 + $w3;
        for ($k = 0; $k + 1 < count($index); $k += 2) {
            for ($n = $index[$k]; $n < $index[$k] + $index[$k + 1]; $n++) {
                if ($p + $rec > strlen($data)) {
                    break 2;
                }
                $t = $w1 ? $num(substr($data, $p, $w1)) : 1;
                $a = $num(substr($data, $p + $w1, $w2));
                $b = $num(substr($data, $p + $w1 + $w2, $w3));
                $p += $rec;
                if (! isset($entries[$n])) {
                    $entries[$n] = [$t, $a, $b];
                }
            }
        }

        return [$entries, $dict];
    }

    public static function rewrite(string $raw): string
    {
        [$entries, $trailer] = self::readXref($raw);
        // Corps de chaque objet stocké en clair : de son offset jusqu'à « endobj »
        $offsets = [];
        foreach ($entries as $n => [$t, $a]) {
            if ($t === 1) {
                $offsets[$n] = $a;
            }
        }
        $objects = [];
        foreach ($offsets as $n => $off) {
            $end = strpos($raw, 'endobj', $off);
            if ($end === false) {
                continue;
            }
            $chunk = substr($raw, $off, $end - $off);
            // Objet dont un flux contient « endobj » : on se fie à la longueur du flux
            if (preg_match('~^\s*\d+\s+\d+\s+obj~', $chunk) && ($sp = strpos($chunk, 'stream')) !== false && ! str_contains(substr($chunk, $sp), 'endstream')) {
                $end = strpos($raw, 'endobj', strpos($raw, 'endstream', $off));
                $chunk = substr($raw, $off, $end - $off);
            }
            $body = preg_replace('~^\s*\d+\s+\d+\s+obj\s*~', '', $chunk);
            if (preg_match('~/Type\s*/(XRef|ObjStm)\b~', substr($body, 0, 400))) {
                continue; // structures de compression : remplacées par la table classique
            }
            $objects[$n] = rtrim($body);
        }
        // Objets rangés dans des flux d'objets (ObjStm)
        $streams = [];
        foreach ($entries as $n => [$t, $a, $b]) {
            if ($t !== 2) {
                continue;
            }
            if (! isset($streams[$a])) {
                $soff = $offsets[$a] ?? null;
                if ($soff === null) {
                    continue;
                }
                $sdict = self::dictAt($raw, $soff);
                $sdata = self::streamData($raw, $soff, $sdict);
                $first = preg_match('~/First\s+(\d+)~', $sdict, $f) ? (int) $f[1] : 0;
                $count = preg_match('~/N\s+(\d+)~', $sdict, $c) ? (int) $c[1] : 0;
                $pairs = preg_split('/\s+/', trim(substr($sdata, 0, $first)));
                $list = [];
                for ($i = 0; $i < $count; $i++) {
                    $list[] = [(int) $pairs[2 * $i], (int) $pairs[2 * $i + 1]];
                }
                $objs = [];
                foreach ($list as $i => [$on, $oo]) {
                    $start = $first + $oo;
                    $stop = isset($list[$i + 1]) ? $first + $list[$i + 1][1] : strlen($sdata);
                    $objs[$on] = trim(substr($sdata, $start, $stop - $start));
                }
                $streams[$a] = $objs;
            }
            if (isset($streams[$a][$n]) && ! isset($objects[$n])) {
                $objects[$n] = $streams[$a][$n];
            }
        }
        ksort($objects);
        $max = $objects ? max(array_keys($objects)) : 0;
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $pos = [];
        foreach ($objects as $n => $body) {
            $pos[$n] = strlen($out);
            $gen = ($entries[$n][0] ?? 1) === 1 ? (int) ($entries[$n][2] ?? 0) : 0;
            $out .= $n.' '.$gen." obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($pos[$i]) ? sprintf("%010d %05d n \n", $pos[$i], ($entries[$i][0] ?? 1) === 1 ? (int) ($entries[$i][2] ?? 0) : 0) : "0000000000 65535 f \n";
        }
        $keep = '';
        foreach (['Root', 'Info', 'ID'] as $k) {
            if ($k === 'ID' && preg_match('~/ID\s*(\[[^\]]*\])~', $trailer, $m)) {
                $keep .= '/ID '.$m[1];
            } elseif (preg_match('~/'.$k.'\s+(\d+\s+\d+\s+R)~', $trailer, $m)) {
                $keep .= '/'.$k.' '.$m[1];
            }
        }
        $out .= 'trailer << /Size '.($max + 1).' '.$keep." >>\nstartxref\n".$xref."\n%%EOF\n";

        return $out;
    }
}
