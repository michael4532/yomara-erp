<?php

namespace App\Modules\Pdf;

/**
 * Retire la protection d'un PDF chiffré avec le gestionnaire de sécurité standard, en PHP pur
 * (aucun outil externe) : RC4 40 et 128 bits, AES-128 et AES-256 (révisions 2 à 6).
 * Le mot de passe fourni est essayé comme mot de passe d'ouverture puis comme mot de passe propriétaire.
 * Le résultat est un PDF 1.4 classique, sans /Encrypt, que FPDI sait relire.
 */
class PdfDecryptor extends PdfNormalizer
{
    protected const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    protected string $raw;

    protected string $key = '';

    protected int $v = 0;

    protected int $r = 0;

    /** Méthode par défaut des flux / chaînes : 'none', 'rc4', 'aes128', 'aes256' */
    protected string $stmF = 'rc4';

    protected string $strF = 'rc4';

    protected bool $encryptMetadata = true;

    protected array $entries = [];

    protected array $offsets = [];

    public static function decrypt(string $raw, string $password): string
    {
        $d = new self();
        $d->raw = $raw;

        return $d->run($password);
    }

    protected function fail(string $m): never
    {
        throw new \DomainException($m);
    }

    protected function run(string $password): string
    {
        [$this->entries, $trailer] = self::readXref($this->raw);
        foreach ($this->entries as $n => [$t, $a]) {
            if ($t === 1) {
                $this->offsets[$n] = $a;
            }
        }
        preg_match('~/Encrypt\s+(\d+)\s+(\d+)\s+R~', $trailer, $em) || $this->fail('Ce PDF n\'est pas protégé par un mot de passe.');
        $encNum = (int) $em[1];
        $enc = $this->body($encNum) ?? $this->fail('Ce PDF est endommagé (paramètres de chiffrement introuvables).');
        preg_match('~/Filter\s*/Standard\b~', $enc) || $this->fail('Ce PDF utilise une protection par certificat : seul son destinataire peut l\'ouvrir.');
        $top = $this->topLevel($enc); // sans le sous-dictionnaire /CF, qui a ses propres /Length
        $this->v = preg_match('~/V\s+(\d+)~', $top, $m) ? (int) $m[1] : 0;
        $this->r = preg_match('~/R\s+(\d+)~', $top, $m) ? (int) $m[1] : 2;
        $length = preg_match('~/Length\s+(\d+)~', $top, $m) ? (int) $m[1] : 40;
        $this->encryptMetadata = ! preg_match('~/EncryptMetadata\s+false~', $enc);
        $o = $this->pdfString($enc, 'O');
        $u = $this->pdfString($enc, 'U');
        $p = preg_match('~/P\s+(-?\d+)~', $enc, $m) ? (int) $m[1] : 0;
        $id0 = preg_match('~/ID\s*\[\s*(<[0-9A-Fa-f\s]*>|\((?:\\\\.|[^\\\\)])*\))~s', $trailer, $m) ? $this->decodeString($m[1]) : '';

        if ($this->v >= 4) {
            $this->stmF = $this->cryptMethod($enc, 'StmF');
            $this->strF = $this->cryptMethod($enc, 'StrF');
        } else {
            $this->stmF = $this->strF = 'rc4';
        }

        if ($this->r >= 5) {
            $this->key = $this->key256($password, $o, $u, $this->pdfString($enc, 'OE'), $this->pdfString($enc, 'UE'))
                ?? $this->fail('Mot de passe incorrect.');
        } else {
            $n = $this->r === 2 ? 5 : max(5, min(16, intdiv($length, 8)));
            $this->key = $this->userKey($password, $o, $p, $id0, $n, $u)
                ?? $this->userKey($this->ownerToUser($password, $o, $n), $o, $p, $id0, $n, $u)
                ?? $this->fail('Mot de passe incorrect.');
        }

        return $this->rebuild($trailer, $encNum);
    }

    // ------------------------------------------------------------------ clés

    /** Le dictionnaire sans le contenu de ses sous-dictionnaires. */
    protected function topLevel(string $dict): string
    {
        $out = '';
        $depth = 0;
        $len = strlen($dict);
        for ($i = 0; $i < $len; $i++) {
            if ($dict[$i] === '<' && ($dict[$i + 1] ?? '') === '<') {
                $depth++;
                $i++;
                $out .= $depth === 1 ? '<<' : '';

                continue;
            }
            if ($dict[$i] === '>' && ($dict[$i + 1] ?? '') === '>') {
                $depth--;
                $i++;
                $out .= $depth === 0 ? '>>' : '';

                continue;
            }
            $out .= $depth <= 1 ? $dict[$i] : '';
        }

        return $out;
    }

    protected function cryptMethod(string $enc, string $which): string
    {
        $name = preg_match('~/'.$which.'\s*/(\w+)~', $enc, $m) ? $m[1] : 'Identity';
        if ($name === 'Identity') {
            return 'none';
        }
        $cfm = preg_match('~/'.preg_quote($name, '~').'\s*<<[^>]*?/CFM\s*/(\w+)~s', $enc, $m) ? $m[1] : 'V2';

        return match ($cfm) {
            'AESV2' => 'aes128',
            'AESV3' => 'aes256',
            'None' => 'none',
            default => 'rc4',
        };
    }

    protected function pad(string $pw): string
    {
        return substr($pw.self::PAD, 0, 32);
    }

    /** Algorithme 2 (+ contrôle de l'algorithme 6) : clé du fichier si le mot de passe d'ouverture est bon. */
    protected function userKey(?string $pw, string $o, int $p, string $id0, int $n, string $u): ?string
    {
        if ($pw === null) {
            return null;
        }
        $h = md5($this->pad($pw).$o.pack('V', $p & 0xFFFFFFFF).$id0.($this->r >= 4 && ! $this->encryptMetadata ? "\xFF\xFF\xFF\xFF" : ''), true);
        if ($this->r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $h = md5(substr($h, 0, $n), true);
            }
        }
        $key = substr($h, 0, $n);
        if ($this->r === 2) {
            $ok = self::rc4($key, self::PAD) === substr($u, 0, 32);
        } else {
            $x = self::rc4($key, md5(self::PAD.$id0, true));
            for ($i = 1; $i <= 19; $i++) {
                $x = self::rc4($key ^ str_repeat(chr($i), $n), $x);
            }
            $ok = $x === substr($u, 0, 16);
        }

        return $ok ? $key : null;
    }

    /** Algorithme 7 : à partir du mot de passe propriétaire, retrouve le mot de passe d'ouverture. */
    protected function ownerToUser(string $owner, string $o, int $n): string
    {
        $h = md5($this->pad($owner), true);
        if ($this->r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $h = md5($h, true);
            }
        }
        $key = substr($h, 0, $n);
        if ($this->r === 2) {
            return self::rc4($key, $o);
        }
        $x = $o;
        for ($i = 19; $i >= 0; $i--) {
            $x = self::rc4($key ^ str_repeat(chr($i), $n), $x);
        }

        return $x;
    }

    /** AES-256 (révisions 5 et 6) : vérifie le mot de passe et déchiffre la clé du fichier. */
    protected function key256(string $password, string $o, string $u, string $oe, string $ue): ?string
    {
        $pw = substr($password, 0, 127);
        $u = str_pad($u, 48, "\0");
        $o = str_pad($o, 48, "\0");
        if (hash_equals(substr($u, 0, 32), $this->hash256($pw, substr($u, 32, 8), ''))) {
            $k = $this->hash256($pw, substr($u, 40, 8), '');

            return openssl_decrypt(substr($ue, 0, 32), 'aes-256-cbc', $k, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16)) ?: null;
        }
        $u48 = substr($u, 0, 48);
        if (hash_equals(substr($o, 0, 32), $this->hash256($pw, substr($o, 32, 8), $u48))) {
            $k = $this->hash256($pw, substr($o, 40, 8), $u48);

            return openssl_decrypt(substr($oe, 0, 32), 'aes-256-cbc', $k, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16)) ?: null;
        }

        return null;
    }

    /** Révision 5 : SHA-256 simple ; révision 6 : algorithme 2.B. */
    protected function hash256(string $pw, string $salt, string $udata): string
    {
        $k = hash('sha256', $pw.$salt.$udata, true);
        if ($this->r < 6) {
            return $k;
        }
        for ($i = 0; ; $i++) {
            $k1 = str_repeat($pw.$k.$udata, 64);
            $e = openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $mod = 0;
            for ($j = 0; $j < 16; $j++) {
                $mod += ord($e[$j]);
            }
            $k = hash(['sha256', 'sha384', 'sha512'][$mod % 3], $e, true);
            if ($i >= 63 && ord($e[strlen($e) - 1]) <= $i - 31) {
                break;
            }
        }

        return substr($k, 0, 32);
    }

    protected function objectKey(int $num, int $gen, string $method): string
    {
        if ($method === 'aes256') {
            return $this->key;
        }
        $h = md5($this->key.substr(pack('V', $num), 0, 3).substr(pack('V', $gen), 0, 2).($method === 'aes128' ? 'sAlT' : ''), true);

        return substr($h, 0, min(strlen($this->key) + 5, 16));
    }

    protected function decryptData(string $data, int $num, int $gen, string $method): string
    {
        if ($method === 'none' || $data === '') {
            return $data;
        }
        $k = $this->objectKey($num, $gen, $method);
        if ($method === 'rc4') {
            return self::rc4($k, $data);
        }
        if (strlen($data) < 32) {
            return strlen($data) === 16 ? '' : $data; // chaîne vide chiffrée : seulement l'IV et un bloc de remplissage
        }
        $out = openssl_decrypt(substr($data, 16), $method === 'aes256' ? 'aes-256-cbc' : 'aes-128-cbc', $k, OPENSSL_RAW_DATA, substr($data, 0, 16));
        if ($out === false) {
            // remplissage non conforme : on déchiffre sans le vérifier
            $out = (string) openssl_decrypt(substr($data, 16), $method === 'aes256' ? 'aes-256-cbc' : 'aes-128-cbc', $k, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($data, 0, 16));
        }

        return $out;
    }

    public static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $kl = strlen($key);
        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $kl])) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $out = '';
        $len = strlen($data);
        for ($i = 0, $j = 0, $k = 0; $k < $len; $k++) {
            $i = ($i + 1) & 255;
            $j = ($j + $s[$i]) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= $data[$k] ^ chr($s[($s[$i] + $s[$j]) & 255]);
        }

        return $out;
    }

    // ------------------------------------------------------------------ lecture des objets

    /** Corps brut (sans « n g obj » ni « endobj ») d'un objet stocké en clair dans le fichier. */
    protected function body(int $n): ?string
    {
        $off = $this->offsets[$n] ?? null;
        if ($off === null) {
            return null;
        }
        $head = substr($this->raw, $off, 300);
        if (! preg_match('~^\s*\d+\s+\d+\s+obj\s*~', $head, $hm)) {
            return null;
        }
        $start = $off + strlen($hm[0]);
        $sp = $this->streamStart($start);
        $end = strpos($this->raw, 'endobj', $sp ?? $start);
        if ($sp !== null) {
            $es = strpos($this->raw, 'endstream', $sp);
            $end = $es === false ? $end : strpos($this->raw, 'endobj', $es);
        }

        return $end === false ? null : rtrim(substr($this->raw, $start, $end - $start));
    }

    /** Position du mot-clé « stream » si l'objet qui commence à $start a un flux. */
    protected function streamStart(int $start): ?int
    {
        $depth = 0;
        $len = strlen($this->raw);
        for ($i = $start; $i < $len; $i++) {
            $c = $this->raw[$i];
            if ($c === '(') {
                $i = $this->skipLiteral($this->raw, $i);
            } elseif ($c === '<' && ($this->raw[$i + 1] ?? '') === '<') {
                $depth++;
                $i++;
            } elseif ($c === '>' && ($this->raw[$i + 1] ?? '') === '>') {
                $depth--;
                $i++;
            } elseif ($depth === 0) {
                if (substr($this->raw, $i, 6) === 'stream') {
                    return $i;
                }
                if (substr($this->raw, $i, 6) === 'endobj') {
                    return null;
                }
            }
        }

        return null;
    }

    protected function skipLiteral(string $s, int $i): int
    {
        $depth = 0;
        $len = strlen($s);
        for (; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $len - 1;
    }

    protected function pdfString(string $dict, string $name): string
    {
        if (! preg_match('~/'.$name.'\s*(<[0-9A-Fa-f\s]*>|\()~', $dict, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $at = $m[1][1];
        if ($dict[$at] === '<') {
            return $this->decodeString($m[1][0]);
        }

        return $this->decodeString(substr($dict, $at, $this->skipLiteral($dict, $at) - $at + 1));
    }

    /** Octets d'une chaîne PDF littérale « (…) » ou hexadécimale « <…> ». */
    protected function decodeString(string $tok): string
    {
        if ($tok !== '' && $tok[0] === '<') {
            $hex = preg_replace('~[^0-9A-Fa-f]~', '', $tok);
            if (strlen($hex) % 2) {
                $hex .= '0';
            }

            return (string) hex2bin($hex);
        }
        $s = substr($tok, 1, -1);
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c !== '\\') {
                $out .= $c;

                continue;
            }
            $n = $s[++$i] ?? '';
            if ($n === "\r") {
                if (($s[$i + 1] ?? '') === "\n") {
                    $i++;
                }

                continue;
            }
            if ($n === "\n") {
                continue;
            }
            if (ctype_digit($n) && $n < '8') {
                $oct = $n;
                while (strlen($oct) < 3 && isset($s[$i + 1]) && $s[$i + 1] >= '0' && $s[$i + 1] <= '7') {
                    $oct .= $s[++$i];
                }
                $out .= chr(octdec($oct) & 255);

                continue;
            }
            $out .= match ($n) { 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", default => $n };
        }

        return $out;
    }

    /** Déchiffre toutes les chaînes d'un objet (hors flux) et les réécrit en hexadécimal. */
    protected function decryptStrings(string $s, int $num, int $gen): string
    {
        if ($this->strF === 'none') {
            return $s;
        }
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '%') { // commentaire
                $e = strcspn($s, "\r\n", $i);
                $out .= substr($s, $i, $e);
                $i += $e - 1;
            } elseif ($c === '(') {
                $e = $this->skipLiteral($s, $i);
                $out .= '<'.bin2hex($this->decryptData($this->decodeString(substr($s, $i, $e - $i + 1)), $num, $gen, $this->strF)).'>';
                $i = $e;
            } elseif ($c === '<' && ($s[$i + 1] ?? '') !== '<') {
                $e = strpos($s, '>', $i);
                $e = $e === false ? $len - 1 : $e;
                $out .= '<'.bin2hex($this->decryptData($this->decodeString(substr($s, $i, $e - $i + 1)), $num, $gen, $this->strF)).'>';
                $i = $e;
            } elseif ($c === '<') {
                $out .= '<<';
                $i++;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    /** Valeur entière d'un /Length, direct ou en référence indirecte. */
    protected function lengthOf(string $dict): ?int
    {
        if (preg_match('~/Length\s+(\d+)\s+(\d+)\s+R~', $dict, $m)) {
            $b = $this->body((int) $m[1]);

            return $b !== null && preg_match('~^\s*(\d+)~', $b, $v) ? (int) $v[1] : null;
        }

        return preg_match('~/Length\s+(\d+)~', $dict, $m) ? (int) $m[1] : null;
    }

    // ------------------------------------------------------------------ reconstruction

    protected function rebuild(string $trailer, int $encNum): string
    {
        $objects = [];
        $gens = [];
        $objStreams = [];
        foreach ($this->offsets as $n => $off) {
            if ($n === $encNum) {
                continue;
            }
            $head = substr($this->raw, $off, 300);
            if (! preg_match('~^\s*(\d+)\s+(\d+)\s+obj\s*~', $head, $hm)) {
                continue;
            }
            $gen = (int) $hm[2];
            $start = $off + strlen($hm[0]);
            $sp = $this->streamStart($start);
            if ($sp === null) {
                $end = strpos($this->raw, 'endobj', $start);
                if ($end === false) {
                    continue;
                }
                $objects[$n] = $this->decryptStrings(rtrim(substr($this->raw, $start, $end - $start)), $n, $gen);
                $gens[$n] = $gen;

                continue;
            }
            $dict = rtrim(substr($this->raw, $start, $sp - $start));
            if (preg_match('~/Type\s*/XRef\b~', $dict)) {
                continue; // remplacée par une table classique ; jamais chiffrée
            }
            $d = $sp + 6;
            if (($this->raw[$d] ?? '') === "\r") {
                $d++;
            }
            if (($this->raw[$d] ?? '') === "\n") {
                $d++;
            }
            $len = $this->lengthOf($dict);
            if ($len === null || substr($this->raw, $d + $len, 30) === false || ! preg_match('~^\s*endstream~', substr($this->raw, $d + $len, 30))) {
                $es = strpos($this->raw, 'endstream', $d);
                $len = ($es === false ? strlen($this->raw) : $es) - $d;
                $data = rtrim(substr($this->raw, $d, $len), "\r\n");
            } else {
                $data = substr($this->raw, $d, $len);
            }
            $skip = (! $this->encryptMetadata && preg_match('~/Type\s*/Metadata\b~', $dict))
                || preg_match('~/Filter\s*/Crypt\b~', $dict);
            $data = $skip ? $data : $this->decryptData($data, $n, $gen, $this->stmF);
            $dict = $this->decryptStrings($dict, $n, $gen);
            if (preg_match('~/Type\s*/ObjStm\b~', $dict)) {
                $objStreams[$n] = [$dict, $data];

                continue;
            }
            $dict = preg_replace('~/Length\s+\d+(\s+\d+\s+R)?~', '/Length '.strlen($data), $dict, 1);
            $objects[$n] = $dict."\nstream\n".$data."\nendstream";
            $gens[$n] = $gen;
        }
        // Objets rangés dans des flux d'objets : leurs chaînes ne sont pas chiffrées individuellement
        foreach ($objStreams as $sn => [$sdict, $sdata]) {
            $sdata = $this->inflate($sdict, $sdata);
            $first = preg_match('~/First\s+(\d+)~', $sdict, $f) ? (int) $f[1] : 0;
            $count = preg_match('~/N\s+(\d+)~', $sdict, $c) ? (int) $c[1] : 0;
            $pairs = preg_split('/\s+/', trim(substr($sdata, 0, $first)));
            $list = [];
            for ($i = 0; $i < $count && isset($pairs[2 * $i + 1]); $i++) {
                $list[] = [(int) $pairs[2 * $i], (int) $pairs[2 * $i + 1]];
            }
            foreach ($list as $i => [$on, $oo]) {
                if (($this->entries[$on][0] ?? null) !== 2 || ($this->entries[$on][1] ?? null) !== $sn || isset($objects[$on])) {
                    continue; // version plus récente ailleurs
                }
                $startO = $first + $oo;
                $stop = isset($list[$i + 1]) ? $first + $list[$i + 1][1] : strlen($sdata);
                $objects[$on] = trim(substr($sdata, $startO, $stop - $startO));
                $gens[$on] = 0;
            }
        }
        $objects || $this->fail('Ce PDF n\'a pas pu être déverrouillé.');
        ksort($objects);
        $max = max(array_keys($objects));
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $pos = [];
        foreach ($objects as $n => $body) {
            $pos[$n] = strlen($out);
            $out .= $n.' '.$gens[$n]." obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($pos[$i]) ? sprintf("%010d %05d n \n", $pos[$i], $gens[$i]) : "0000000000 65535 f \n";
        }
        $keep = '';
        foreach (['Root', 'Info'] as $k) {
            if (preg_match('~/'.$k.'\s+(\d+\s+\d+\s+R)~', $trailer, $m)) {
                $keep .= '/'.$k.' '.$m[1].' ';
            }
        }
        if (preg_match('~/ID\s*(\[[^\]]*\])~', $trailer, $m)) {
            $keep .= '/ID '.$m[1];
        }
        $out .= 'trailer << /Size '.($max + 1).' '.$keep." >>\nstartxref\n".$xref."\n%%EOF\n";

        return $out;
    }

    /** Décompression (FlateDecode + prédicteur PNG) d'un flux d'objets déjà déchiffré. */
    protected function inflate(string $dict, string $data): string
    {
        if (preg_match('~/Filter\s*/FlateDecode|/Filter\s*\[\s*/FlateDecode\s*\]~', $dict)) {
            $dec = @gzuncompress($data);
            if ($dec === false) {
                $dec = @gzinflate(substr($data, 2));
            }
            $dec === false && $this->fail('Mot de passe incorrect ou PDF endommagé.');
            $data = $dec;
        }
        if (preg_match('~/Predictor\s+(\d+)~', $dict, $pr) && (int) $pr[1] >= 10) {
            $data = self::unpredict($data, preg_match('~/Columns\s+(\d+)~', $dict, $c) ? (int) $c[1] : 1);
        }

        return $data;
    }
}
