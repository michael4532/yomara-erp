<?php

namespace App\Modules\Social;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Visuels prêts à publier, dessinés avec la charte de la société : promotion produit, nouveauté,
 * annonce, témoignage, liste de prix. Formats carré, portrait, story et paysage.
 */
class VisualRenderer
{
    public const FORMATS = ['square' => ['Carré (fil d\'actualité)', 1080, 1080], 'portrait' => ['Portrait 4:5 (Instagram)', 1080, 1350], 'story' => ['Story / Reel / Statut', 1080, 1920], 'landscape' => ['Paysage (Facebook, LinkedIn, site)', 1200, 630]];

    public const TEMPLATES = [
        'promo' => ['Promotion produit', 'Photo, prix barré, nouveau prix et réduction'],
        'new' => ['Nouveauté', 'Photo et bandeau « Nouveau »'],
        'announce' => ['Annonce', 'Grand titre sur fond de la marque (horaires, événement, recrutement…)'],
        'quote' => ['Avis client', 'Citation d\'un client avec son nom'],
        'price_list' => ['Liste de prix', 'Jusqu\'à 6 articles avec leur prix'],
    ];

    protected $im;

    protected int $W;

    protected int $H;

    protected float $k;

    protected array $brand;

    public static function font(string $w = 'bold'): string
    {
        return resource_path('fonts/Inter-'.['regular' => 'Regular', 'semibold' => 'SemiBold', 'bold' => 'Bold', 'black' => 'ExtraBold'][$w].'.ttf');
    }

    protected static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    protected function col(string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = self::rgb($hex);

        return imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);
    }

    /** Couleur de texte lisible sur un fond donné. */
    public static function onColor(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111827' : '#FFFFFF';
    }

    protected static function shade(string $hex, float $f): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $m = fn ($c) => max(0, min(255, (int) round($f < 0 ? $c * (1 + $f) : $c + (255 - $c) * $f)));

        return sprintf('#%02X%02X%02X', $m($r), $m($g), $m($b));
    }

    protected function s(float $v): int
    {
        return (int) round($v * $this->k);
    }

    protected function gradient(int $x, int $y, int $w, int $h, string $from, string $to): void
    {
        [$r1, $g1, $b1] = self::rgb($from);
        [$r2, $g2, $b2] = self::rgb($to);
        for ($i = 0; $i < $h; $i++) {
            $t = $h > 1 ? $i / ($h - 1) : 0;
            $c = imagecolorallocate($this->im, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($this->im, $x, $y + $i, $x + $w - 1, $y + $i, $c);
        }
    }

    protected function rounded(int $x, int $y, int $w, int $h, int $r, int $color): void
    {
        $r = min($r, intdiv($w, 2), intdiv($h, 2));
        imagefilledrectangle($this->im, $x + $r, $y, $x + $w - $r, $y + $h, $color);
        imagefilledrectangle($this->im, $x, $y + $r, $x + $w, $y + $h - $r, $color);
        foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
            imagefilledellipse($this->im, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    /** Coupe le texte en lignes tenant dans la largeur ; réduit la taille si besoin (jusqu'à $min). */
    protected function fit(string $text, string $font, float $size, int $maxW, int $maxLines, float $min = 0): array
    {
        $min = $min ?: $size * .6;
        if ($min > $size) {
            $min = $size * .6;
        }
        for ($sz = $size; $sz >= $min; $sz -= 2) {
            $lines = $this->wrap($text, $font, $sz, $maxW);
            if (count($lines) <= $maxLines) {
                return [$lines, $sz];
            }
        }
        $lines = array_slice($this->wrap($text, $font, $min, $maxW), 0, $maxLines);
        $lines[$maxLines - 1] = rtrim(mb_substr($lines[$maxLines - 1], 0, max(1, mb_strlen($lines[$maxLines - 1]) - 1))).'…';

        return [$lines, $min];
    }

    protected function width(string $t, string $font, float $size): int
    {
        $b = imagettfbbox($size, 0, $font, $t);

        return (int) abs($b[2] - $b[0]);
    }

    protected function wrap(string $text, string $font, float $size, int $maxW): array
    {
        $out = [];
        foreach (preg_split('/\R/u', trim($text)) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                $try = $line === '' ? $word : $line.' '.$word;
                if ($line !== '' && $this->width($try, $font, $size) > $maxW) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }

        return $out;
    }

    /** Écrit des lignes ; renvoie la position y sous le dernier texte. */
    protected function lines(array $lines, string $font, float $size, int $x, int $y, int $color, float $lh = 1.18, string $align = 'left', int $boxW = 0): int
    {
        foreach ($lines as $l) {
            $y += (int) round($size * 1.333 * max(1.0, $lh - 0.08)); // taille GD en points (96 ppp)
            $lx = $align === 'center' ? $x + intdiv($boxW - $this->width($l, $font, $size), 2) : $x;
            imagettftext($this->im, $size, 0, $lx, $y, $color, $font, $l);
        }

        return $y;
    }

    /** Image (fichier local, adresse web ou data:) recadrée pour remplir le rectangle. */
    protected function cover(?string $src, int $x, int $y, int $w, int $h, bool $contain = false): bool
    {
        $img = self::load($src);
        if (! $img) {
            return false;
        }
        $iw = imagesx($img);
        $ih = imagesy($img);
        if ($contain) {
            $ratio = min($w / $iw, $h / $ih);
            $nw = (int) ($iw * $ratio);
            $nh = (int) ($ih * $ratio);
            imagecopyresampled($this->im, $img, $x + intdiv($w - $nw, 2), $y + intdiv($h - $nh, 2), 0, 0, $nw, $nh, $iw, $ih);
        } else {
            $ratio = max($w / $iw, $h / $ih);
            $sw = (int) ($w / $ratio);
            $sh = (int) ($h / $ratio);
            imagecopyresampled($this->im, $img, $x, $y, intdiv($iw - $sw, 2), intdiv($ih - $sh, 2), $w, $h, $sw, $sh);
        }
        imagedestroy($img);

        return true;
    }

    /** Dossiers d'où une image locale peut être lue (fichiers déjà publics) ; jamais les fichiers privés ni le reste du serveur. */
    protected static function localFile(string $path): ?string
    {
        $real = realpath($path);
        if (! $real || ! is_file($real)) {
            return null;
        }
        foreach ([public_path('uploads'), storage_path('app/public')] as $root) {
            $root = realpath($root);
            if ($root && str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    public static function load(?string $src)
    {
        if (! $src) {
            return null;
        }
        $data = null;
        if (str_starts_with($src, 'data:image/')) {
            $data = base64_decode(substr($src, strpos($src, ',') + 1));
        } elseif (preg_match('#^https?://#i', $src)) {
            if (parse_url(url('/'), PHP_URL_HOST) === parse_url($src, PHP_URL_HOST)) {
                $local = self::localFile(public_path(ltrim(rawurldecode((string) parse_url($src, PHP_URL_PATH)), '/')));
                $data = $local ? file_get_contents($local) : null;
            } else {
                $data = rescue(fn () => \App\Modules\Veille\WebFetcher::get($src, 15)['body'], null, false);
            }
        } else {
            $local = self::localFile(str_starts_with($src, '/') ? $src : public_path($src)) ?? self::localFile(storage_path('app/public/'.ltrim($src, '/')));
            $data = $local ? file_get_contents($local) : null;
        }
        if (! $data || strlen($data) > 15_000_000) {
            return null;
        }
        $img = @imagecreatefromstring($data);

        return $img ?: null;
    }

    protected function logo(int $x, int $y, int $maxH, bool $chip = true): int
    {
        $img = self::load($this->brand['logo'] ?? null);
        if (! $img) {
            return 0;
        }
        $iw = imagesx($img);
        $ih = imagesy($img);
        $h = $maxH;
        $w = (int) round($iw * $h / $ih);
        if ($w > $maxH * 3) {
            $w = $maxH * 3;
            $h = (int) round($ih * $w / $iw);
        }
        if ($chip) {
            $pad = $this->s(14);
            $this->rounded($x, $y, $w + $pad * 2, $h + $pad * 2, $this->s(18), $this->col('#FFFFFF'));
            $x += $pad;
            $y += $pad;
        }
        imagecopyresampled($this->im, $img, $x, $y, 0, 0, $w, $h, $iw, $ih);
        imagedestroy($img);

        return $w;
    }

    public static function money(float $v, string $currency): string
    {
        return number_format($v, 0, ',', ' ').' '.(in_array($currency, ['XOF', 'XAF', ''], true) ? 'F' : $currency);
    }

    /**
     * @param  array  $d  template, format, title, subtitle, price, old_price, cta, image, author, items [[name, price]], badge
     * @return string PNG
     */
    public function render(array $brand, array $d, float $scale = 1.0): string
    {
        $this->brand = $brand + ['primary' => '#1F6FEB', 'accent' => '#FFC531', 'dark' => '#0B1B3A', 'currency' => 'XOF'];
        [, $W, $H] = self::FORMATS[$d['format'] ?? 'square'] ?? self::FORMATS['square'];
        $this->W = (int) round($W * $scale);
        $this->H = (int) round($H * $scale);
        $this->k = $this->W / 1080;
        $this->im = imagecreatetruecolor($this->W, $this->H);
        imagealphablending($this->im, true);
        imageantialias($this->im, true);
        match ($d['template'] ?? 'promo') {
            'announce' => $this->announce($d),
            'quote' => $this->quote($d),
            'price_list' => $this->priceList($d),
            default => $this->product($d, ($d['template'] ?? 'promo') === 'new'),
        };
        ob_start();
        imagepng($this->im, null, 6);
        imagedestroy($this->im);

        return (string) ob_get_clean();
    }

    protected function footer(?string $cta, int $y0): void
    {
        $line = trim(implode('   ·   ', array_filter([$cta ?: null, $this->brand['contact'] ?? null])));
        if ($line === '') {
            return;
        }
        $h = $this->H - $y0;
        imagefilledrectangle($this->im, 0, $y0, $this->W, $this->H, $this->col($this->brand['dark']));
        [$l, $sz] = $this->fit($line, self::font('semibold'), $this->s(30), $this->W - $this->s(100), 1, $this->s(18));
        $this->lines($l, self::font('semibold'), $sz, 0, $y0 + intdiv($h, 2) - (int) ($sz * .75), $this->col(self::onColor($this->brand['dark'])), 1.18, 'center', $this->W);
    }

    protected function product(array $d, bool $isNew): void
    {
        $p = $this->brand['primary'];
        $land = $this->W > $this->H * 1.4;
        $footH = ($d['cta'] ?? null) || ($this->brand['contact'] ?? null) ? $this->s($land ? 70 : 96) : 0;
        if ($land) {
            $imgW = (int) ($this->W * .48);
            imagefilledrectangle($this->im, 0, 0, $this->W, $this->H, $this->col('#F4F5F8'));
            $this->cover($d['image'] ?? null, 0, 0, $imgW, $this->H - $footH) || $this->gradient(0, 0, $imgW, $this->H - $footH, self::shade($p, .3), $p);
            $this->gradient($imgW, 0, $this->W - $imgW, $this->H - $footH, $p, self::shade($p, -.35));
            $tx = $imgW + $this->s(50);
            $tw = $this->W - $imgW - $this->s(90);
            $ty = $this->s(60);
        } else {
            $imgH = (int) (($this->H - $footH) * ($this->H > $this->W * 1.5 ? .55 : .47));
            imagefilledrectangle($this->im, 0, 0, $this->W, $this->H, $this->col('#F4F5F8'));
            $this->cover($d['image'] ?? null, 0, 0, $this->W, $imgH) || $this->gradient(0, 0, $this->W, $imgH, self::shade($p, .35), $p);
            $this->gradient(0, $imgH, $this->W, $this->H - $footH - $imgH, $p, self::shade($p, -.35));
            $tx = $this->s(70);
            $tw = $this->W - $this->s(140);
            $ty = $imgH + $this->s(36);
        }
        $on = $this->col(self::onColor($p));
        $this->logo($this->s(40), $this->s(40), $this->s(70));
        // pastille réduction ou bandeau « Nouveau »
        $badge = $d['badge'] ?? null;
        if (! $badge && ! $isNew && ($d['old_price'] ?? 0) > 0 && ($d['price'] ?? 0) > 0 && $d['old_price'] > $d['price']) {
            $badge = '-'.round((1 - $d['price'] / $d['old_price']) * 100).'%';
        }
        if ($isNew) {
            $badge = $badge ?: 'NOUVEAU';
            $bw = $this->width($badge, self::font('black'), $this->s(34)) + $this->s(60);
            $bx = ($land ? (int) ($this->W * .48) : $this->W) - $bw - $this->s(40);
            $this->rounded($bx, $this->s(48), $bw, $this->s(78), $this->s(39), $this->col($this->brand['accent']));
            $this->lines([$badge], self::font('black'), $this->s(34), $bx, $this->s(50), $this->col(self::onColor($this->brand['accent'])), 1.6, 'center', $bw);
        } elseif ($badge) {
            $r = $this->s($land ? 90 : 110);
            $cx = ($land ? (int) ($this->W * .48) : $this->W) - $this->s(40) - $r;
            $cy = $this->s(60) + $r;
            imagefilledellipse($this->im, $cx, $cy, $r * 2, $r * 2, $this->col($this->brand['accent']));
            [$bl, $bsz] = $this->fit($badge, self::font('black'), $this->s(58), $r * 2 - $this->s(30), 2, $this->s(30));
            $this->lines($bl, self::font('black'), $bsz, $cx - $r, $cy - (int) (count($bl) * $bsz * 1.15 / 2) - (int) ($bsz * .2), $this->col(self::onColor($this->brand['accent'])), 1.15, 'center', $r * 2);
        }
        $tall = $this->H > $this->W * 1.5;
        [$tl, $tsz] = $this->fit((string) ($d['title'] ?? ''), self::font('black'), $this->s($land ? 54 : ($tall ? 86 : 64)), $tw, $land ? 3 : 2, $this->s(34));
        $y = $this->lines($tl, self::font('black'), $tsz, $tx, $ty, $on, 1.2);
        if (! empty($d['subtitle'])) {
            [$sl, $ssz] = $this->fit((string) $d['subtitle'], self::font('regular'), $this->s($tall ? 42 : 32), $tw, 2, $this->s(22));
            $y = $this->lines($sl, self::font('regular'), $ssz, $tx, $y + $this->s(10), $this->col(self::onColor($p), 25), 1.3);
        }
        if (($d['price'] ?? 0) > 0) {
            $y += $this->s(20);
            $cur = $this->brand['currency'];
            if (($d['old_price'] ?? 0) > ($d['price'] ?? 0)) {
                $old = self::money((float) $d['old_price'], $cur);
                $osz = $this->s(36);
                $ow = $this->width($old, self::font('semibold'), $osz);
                imagettftext($this->im, $osz, 0, $tx, $y + $osz, $this->col(self::onColor($p), 45), self::font('semibold'), $old);
                imagesetthickness($this->im, max(2, $this->s(4)));
                imageline($this->im, $tx - $this->s(4), $y + (int) ($osz * .62), $tx + $ow + $this->s(4), $y + (int) ($osz * .62), $this->col($this->brand['accent']));
                imagesetthickness($this->im, 1);
                $y += (int) ($osz * 1.35);
            }
            $price = self::money((float) $d['price'], $cur);
            $psz = $this->s($land ? 64 : ($tall ? 104 : 78));
            [$pl, $psz] = $this->fit($price, self::font('black'), $psz, $tw, 1, $this->s(36));
            $this->lines($pl, self::font('black'), $psz, $tx, $y - (int) ($psz * .1), $this->col($this->brand['accent']), 1.05);
        }
        $this->footer($d['cta'] ?? null, $this->H - $footH);
    }

    protected function announce(array $d): void
    {
        $p = $this->brand['primary'];
        $this->gradient(0, 0, $this->W, $this->H, $p, self::shade($p, -.45));
        $on = self::onColor($p);
        // formes décoratives discrètes
        imagefilledellipse($this->im, $this->W - $this->s(80), $this->s(120), $this->s(520), $this->s(520), $this->col($this->brand['accent'], 105));
        imagefilledellipse($this->im, $this->s(60), $this->H - $this->s(140), $this->s(360), $this->s(360), $this->col('#FFFFFF', 118));
        $this->logo($this->s(70), $this->s(70), $this->s(80));
        $hasImg = ! empty($d['image']) && $this->H > $this->W;
        $footH = ($d['cta'] ?? null) || ($this->brand['contact'] ?? null) ? $this->s(110) : 0;
        if ($hasImg) {
            $ih = (int) ($this->H * .34);
            $this->cover($d['image'], $this->s(70), $this->H - $footH - $ih - $this->s(70), $this->W - $this->s(140), $ih);
        }
        $tw = $this->W - $this->s(140);
        $land = $this->W > $this->H * 1.4;
        [$tl, $tsz] = $this->fit((string) ($d['title'] ?? ''), self::font('black'), $this->s($land ? 76 : 96), $tw, $land ? 3 : 4, $this->s(44));
        $top = $this->s($land ? 190 : ($this->H > $this->W ? 300 : 230));
        $y = $this->lines($tl, self::font('black'), $tsz, $this->s(70), $top, $this->col($on), 1.14);
        imagefilledrectangle($this->im, $this->s(70), $y + $this->s(28), $this->s(190), $y + $this->s(36), $this->col($this->brand['accent']));
        if (! empty($d['subtitle'])) {
            [$sl, $ssz] = $this->fit((string) $d['subtitle'], self::font('regular'), $this->s(38), $tw, $land ? 2 : 5, $this->s(24));
            $this->lines($sl, self::font('regular'), $ssz, $this->s(70), $y + $this->s(50), $this->col($on, 20), 1.3);
        }
        $this->footer($d['cta'] ?? null, $this->H - $footH);
    }

    protected function quote(array $d): void
    {
        $p = $this->brand['primary'];
        imagefilledrectangle($this->im, 0, 0, $this->W, $this->H, $this->col('#F6F7FA'));
        imagefilledrectangle($this->im, 0, 0, $this->s(22), $this->H, $this->col($p));
        $this->logo($this->s(70), $this->s(60), $this->s(70), false);
        imagettftext($this->im, $this->s(260), 0, $this->s(50), $this->s(420), $this->col($this->brand['accent']), self::font('black'), '“');
        $tw = $this->W - $this->s(160);
        $land = $this->W > $this->H * 1.4;
        [$tl, $tsz] = $this->fit((string) ($d['title'] ?? ''), self::font('bold'), $this->s($land ? 46 : 56), $tw, $land ? 4 : 7, $this->s(28));
        $y = $this->lines($tl, self::font('bold'), $tsz, $this->s(80), $this->s($land ? 200 : 360), $this->col('#111827'), 1.3);
        $stars = str_repeat('★', max(1, min(5, (int) ($d['stars'] ?? 5))));
        imagettftext($this->im, $this->s(44), 0, $this->s(80), $y + $this->s(90), $this->col($this->brand['accent']), self::font('regular'), $stars);
        if (! empty($d['author'])) {
            imagettftext($this->im, $this->s(34), 0, $this->s(80), $y + $this->s(150), $this->col($p), self::font('bold'), '— '.Str::limit((string) $d['author'], 60));
        }
        $footH = ($d['cta'] ?? null) || ($this->brand['contact'] ?? null) ? $this->s(96) : 0;
        $this->footer($d['cta'] ?? null, $this->H - $footH);
    }

    protected function priceList(array $d): void
    {
        $p = $this->brand['primary'];
        $this->gradient(0, 0, $this->W, $this->H, self::shade($p, -.2), self::shade($p, -.55));
        $on = self::onColor(self::shade($p, -.3));
        $this->logo($this->s(60), $this->s(60), $this->s(70));
        $land = $this->W > $this->H * 1.4;
        [$tl, $tsz] = $this->fit((string) ($d['title'] ?? 'Nos prix'), self::font('black'), $this->s($land ? 50 : 72), $land ? $this->W - $this->s(360) : $this->W - $this->s(120), $land ? 1 : 2, $this->s(30));
        $y = $this->lines($tl, self::font('black'), $tsz, $land ? $this->s(300) : $this->s(60), $land ? $this->s(40) : $this->s(170), $this->col($on), 1.1);
        $items = array_slice(array_values(array_filter((array) ($d['items'] ?? []), fn ($i) => trim((string) ($i['name'] ?? '')) !== '')), 0, 6);
        $footH = ($d['cta'] ?? null) || ($this->brand['contact'] ?? null) ? $this->s(96) : 0;
        $avail = $this->H - $footH - $y - $this->s(50);
        $rowH = $items ? min($this->s(150), intdiv($avail, count($items))) : 0;
        $y += $this->s(30);
        foreach ($items as $it) {
            $this->rounded($this->s(60), $y, $this->W - $this->s(120), $rowH - $this->s(18), $this->s(22), $this->col(self::shade($p, -.12)));
            $price = ($it['price'] ?? null) !== null && $it['price'] !== '' ? self::money((float) $it['price'], $this->brand['currency']) : '';
            $psz = max($this->s(20), min($this->s(44), (int) ($rowH * .34)));
            $pw = $price ? $this->width($price, self::font('black'), $psz) : 0;
            [$nl, $nsz] = $this->fit((string) $it['name'], self::font('semibold'), $psz * .9, $this->W - $this->s(220) - $pw, 1, $this->s(20));
            $mid = $y + intdiv($rowH - $this->s(18), 2) + (int) ($psz * .36);
            imagettftext($this->im, $nsz, 0, $this->s(95), $mid, $this->col($on), self::font('semibold'), $nl[0]);
            if ($price) {
                imagettftext($this->im, $psz, 0, $this->W - $this->s(95) - $pw, $mid, $this->col($this->brand['accent']), self::font('black'), $price);
            }
            $y += $rowH;
        }
        $this->footer($d['cta'] ?? null, $this->H - $footH);
    }
}
