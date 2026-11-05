<?php

namespace App\Modules\Liens;

/**
 * QR codes : contenu selon le type (site, WhatsApp, Wi-Fi, carte de visite…) et rendu SVG / PNG
 * avec couleurs, forme des points, coins arrondis et logo au centre (correction d'erreur H : 30 % masquable).
 */
class QrRenderer
{
    public const TYPES = [
        'url' => 'Site web', 'text' => 'Texte', 'whatsapp' => 'WhatsApp', 'wifi' => 'Wi-Fi', 'vcard' => 'Carte de visite',
        'email' => 'E-mail', 'sms' => 'SMS', 'phone' => 'Appel', 'geo' => 'Localisation', 'payment' => 'Lien de paiement',
    ];

    /** Contenu encodé dans le QR selon son type. */
    public static function encode(string $type, array $p): string
    {
        $esc = fn ($v) => str_replace(['\\', ';', ',', ':', '"'], ['\\\\', '\\;', '\\,', '\\:', '\\"'], (string) $v);
        $digits = fn ($v) => preg_replace('/[^\d+]/', '', (string) $v);

        $out = match ($type) {
            'url', 'payment' => self::url((string) ($p['url'] ?? '')),
            'text' => (string) ($p['text'] ?? ''),
            'whatsapp' => 'https://wa.me/'.ltrim($digits($p['phone'] ?? ''), '+').(! empty($p['message']) ? '?text='.rawurlencode($p['message']) : ''),
            'wifi' => 'WIFI:T:'.(($p['security'] ?? 'WPA') === 'none' ? 'nopass' : ($p['security'] ?? 'WPA')).';S:'.$esc($p['ssid'] ?? '').';P:'.$esc($p['password'] ?? '').';'.(! empty($p['hidden']) ? 'H:true;' : '').';',
            'vcard' => implode("\n", array_filter([
                'BEGIN:VCARD', 'VERSION:3.0',
                'N:'.$esc($p['last_name'] ?? '').';'.$esc($p['first_name'] ?? ''),
                'FN:'.$esc(trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''))),
                ! empty($p['company']) ? 'ORG:'.$esc($p['company']) : null,
                ! empty($p['job']) ? 'TITLE:'.$esc($p['job']) : null,
                ! empty($p['phone']) ? 'TEL;TYPE=CELL:'.$digits($p['phone']) : null,
                ! empty($p['phone2']) ? 'TEL;TYPE=WORK:'.$digits($p['phone2']) : null,
                ! empty($p['email']) ? 'EMAIL:'.$p['email'] : null,
                ! empty($p['website']) ? 'URL:'.$p['website'] : null,
                ! empty($p['address']) ? 'ADR:;;'.$esc($p['address']).';;;;' : null,
                'END:VCARD',
            ])),
            'email' => 'mailto:'.($p['email'] ?? '').(($p['subject'] ?? '') || ($p['body'] ?? '') ? '?'.http_build_query(array_filter(['subject' => $p['subject'] ?? null, 'body' => $p['body'] ?? null]), '', '&', PHP_QUERY_RFC3986) : ''),
            'sms' => 'SMSTO:'.$digits($p['phone'] ?? '').':'.($p['message'] ?? ''),
            'phone' => 'tel:'.$digits($p['phone'] ?? ''),
            'geo' => 'geo:'.((float) ($p['lat'] ?? 0)).','.((float) ($p['lng'] ?? 0)),
            default => throw new \DomainException('Type de QR code inconnu.'),
        };
        if (trim($out) === '' || mb_strlen($out) > 2000) {
            throw new \DomainException(trim($out) === '' ? 'Le contenu du QR code est vide.' : 'Contenu trop long pour un QR code (2 000 caractères au maximum).');
        }

        return $out;
    }

    public static function url(string $u): string
    {
        $u = trim($u);
        if ($u !== '' && ! preg_match('~^[a-z][a-z0-9+.-]*:~i', $u)) {
            $u = 'https://'.$u;
        }
        if ($u !== '' && ! preg_match('~^(https?|mailto|tel):~i', $u)) {
            throw new \DomainException('Adresse non autorisée (http ou https).');
        }

        return $u;
    }

    /** @return array{0: int, 1: array<int, array<int, int>>} taille et matrice de modules */
    public static function matrix(string $data): array
    {
        $b = new \TCPDF2DBarcode($data, 'QRCODE,H');
        $a = $b->getBarcodeArray();
        if (empty($a['bcode'])) {
            throw new \DomainException('QR code impossible à générer avec ce contenu.');
        }

        return [(int) $a['num_rows'], $a['bcode']];
    }

    protected static function color(?string $c, string $default): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', (string) $c) ? strtoupper($c) : $default;
    }

    /** Les trois carrés de repérage (coins) : dessinés à part pour leur donner une forme propre. */
    protected static function isFinder(int $r, int $c, int $n): bool
    {
        return ($r < 7 && $c < 7) || ($r < 7 && $c >= $n - 7) || ($r >= $n - 7 && $c < 7);
    }

    public static function svg(string $data, array $style = [], ?string $logoDataUri = null): string
    {
        [$n, $m] = self::matrix($data);
        $fg = self::color($style['fg'] ?? null, '#0A1633');
        $bg = self::color($style['bg'] ?? null, '#FFFFFF');
        $eye = self::color($style['eye'] ?? null, $fg);
        $dots = $style['dots'] ?? 'square'; // square, dots, rounded
        $q = 4; // marge (zone calme)
        $size = $n + 2 * $q;
        $hole = $logoDataUri ? (int) round($n * .22) | 1 : 0; // zone libre au centre pour le logo (impair)
        $h0 = intdiv($n - $hole, 2);
        $out = [];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if (! $m[$r][$c] || self::isFinder($r, $c, $n) || ($hole && $r >= $h0 && $r < $h0 + $hole && $c >= $h0 && $c < $h0 + $hole)) {
                    continue;
                }
                $x = $c + $q;
                $y = $r + $q;
                $out[] = match ($dots) {
                    'dots' => '<circle cx="'.($x + .5).'" cy="'.($y + .5).'" r=".45"/>',
                    'rounded' => '<rect x="'.($x + .05).'" y="'.($y + .05).'" width=".9" height=".9" rx=".3"/>',
                    default => '<rect x="'.$x.'" y="'.$y.'" width="1.02" height="1.02"/>',
                };
            }
        }
        $round = $dots === 'square' ? 0 : 1.6;
        $eyes = '';
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r, $c]) {
            $x = $c + $q;
            $y = $r + $q;
            $eyes .= '<path fill-rule="evenodd" d="'.self::roundRect($x, $y, 7, 7, $round).' '.self::roundRect($x + 1, $y + 1, 5, 5, $round * .7).'"/>'
                .'<path d="'.self::roundRect($x + 2, $y + 2, 3, 3, $round * .5).'"/>';
        }
        $logo = '';
        if ($logoDataUri) {
            $lx = $q + $h0 + .4;
            $lw = $hole - .8;
            $logo = '<rect x="'.($q + $h0).'" y="'.($q + $h0).'" width="'.$hole.'" height="'.$hole.'" rx="1" fill="'.$bg.'"/>'
                .'<image href="'.htmlspecialchars($logoDataUri, ENT_QUOTES).'" x="'.$lx.'" y="'.$lx.'" width="'.$lw.'" height="'.$lw.'" preserveAspectRatio="xMidYMid meet"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" shape-rendering="'.($dots === 'square' ? 'crispEdges' : 'geometricPrecision').'">'
            .'<rect width="100%" height="100%" fill="'.$bg.'"/><g fill="'.$fg.'">'.implode('', $out).'</g><g fill="'.$eye.'">'.$eyes.'</g>'.$logo.'</svg>';
    }

    protected static function roundRect(float $x, float $y, float $w, float $h, float $r): string
    {
        if ($r <= 0) {
            return "M$x {$y}h{$w}v{$h}h-{$w}z";
        }
        $r = min($r, $w / 2, $h / 2);

        return 'M'.($x + $r).' '.$y.'h'.($w - 2 * $r).'a'.$r.' '.$r.' 0 0 1 '.$r.' '.$r.'v'.($h - 2 * $r).'a'.$r.' '.$r.' 0 0 1 -'.$r.' '.$r.'h-'.($w - 2 * $r).'a'.$r.' '.$r.' 0 0 1 -'.$r.' -'.$r.'v-'.($h - 2 * $r).'a'.$r.' '.$r.' 0 0 1 '.$r.' -'.$r.'z';
    }

    /** PNG (GD) au pixel près : px = taille de l'image. */
    public static function png(string $data, array $style = [], int $px = 800, ?string $logoPath = null): string
    {
        [$n, $m] = self::matrix($data);
        $q = 4;
        $size = $n + 2 * $q;
        $scale = max(2, (int) floor($px / $size));
        $img = imagecreatetruecolor($size * $scale, $size * $scale);
        imagealphablending($img, true);
        imagesavealpha($img, true);
        $rgb = fn ($hex) => sscanf($hex, '#%02x%02x%02x');
        [$r1, $g1, $b1] = $rgb(self::color($style['bg'] ?? null, '#FFFFFF'));
        [$r2, $g2, $b2] = $rgb(self::color($style['fg'] ?? null, '#0A1633'));
        [$r3, $g3, $b3] = $rgb(self::color($style['eye'] ?? null, self::color($style['fg'] ?? null, '#0A1633')));
        $bgc = imagecolorallocate($img, $r1, $g1, $b1);
        $fgc = imagecolorallocate($img, $r2, $g2, $b2);
        $eyc = imagecolorallocate($img, $r3, $g3, $b3);
        imagefill($img, 0, 0, $bgc);
        $dots = $style['dots'] ?? 'square';
        $hole = $logoPath ? (int) round($n * .22) | 1 : 0;
        $h0 = intdiv($n - $hole, 2);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if (! $m[$r][$c] || ($hole && $r >= $h0 && $r < $h0 + $hole && $c >= $h0 && $c < $h0 + $hole)) {
                    continue;
                }
                $x = ($c + $q) * $scale;
                $y = ($r + $q) * $scale;
                $col = self::isFinder($r, $c, $n) ? $eyc : $fgc;
                if ($dots === 'dots' && ! self::isFinder($r, $c, $n)) {
                    imagefilledellipse($img, (int) ($x + $scale / 2), (int) ($y + $scale / 2), (int) ($scale * .9), (int) ($scale * .9), $col);
                } else {
                    imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $col);
                }
            }
        }
        if ($logoPath && is_file($logoPath) && ($logo = @imagecreatefromstring((string) file_get_contents($logoPath)))) {
            $box = ($hole - 1) * $scale;
            $lx = ($q + $h0) * $scale + (int) ($scale / 2);
            $w = imagesx($logo);
            $h = imagesy($logo);
            $k = min($box / $w, $box / $h);
            imagecopyresampled($img, $logo, (int) ($lx + ($box - $w * $k) / 2), (int) ($lx + ($box - $h * $k) / 2), 0, 0, (int) ($w * $k), (int) ($h * $k), $w, $h);
            imagedestroy($logo);
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
