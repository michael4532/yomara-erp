<?php

namespace App\Modules\Pdf;

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Outils PDF (à la manière d'Acrobat) : fusionner, extraire, réorganiser et pivoter les pages, filigrane,
 * texte et image ajoutés, numéros de page, protection par mot de passe (AES-256), déverrouillage, images → PDF.
 * Chaque opération lit des fichiers temporaires et renvoie le contenu du PDF produit ; rien n'est conservé.
 */
class PdfService
{
    public const MAX_PAGES = 1500;

    protected array $tmp = [];

    public function __destruct()
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    protected function fail(string $m): never
    {
        throw new \DomainException($m);
    }

    protected function tmp(string $ext = 'pdf'): string
    {
        $f = tempnam(sys_get_temp_dir(), 'ypdf').'.'.$ext;
        $this->tmp[] = $f;

        return $f;
    }

    protected function blank(): Fpdi
    {
        $pdf = new Fpdi();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCreator(config('erp.name', 'Yomara ERP'));

        return $pdf;
    }

    /** Ouvre un PDF source (réécrit en PDF classique si besoin) et renvoie son nombre de pages. */
    protected function open(Fpdi $pdf, string $path): int
    {
        $src = PdfNormalizer::normalize($path, $this->tmp());
        try {
            $n = $pdf->setSourceFile($src);
        } catch (\Throwable $e) {
            $this->fail('Ce PDF n\'a pas pu être lu ('.mb_substr($e->getMessage(), 0, 120).'). Essayez de l\'enregistrer à nouveau en PDF depuis votre logiciel.');
        }
        $n > self::MAX_PAGES && $this->fail('PDF trop long ('.$n.' pages, '.self::MAX_PAGES.' au maximum).');

        return $n;
    }

    /** « 1-3, 5, 8-fin » → [1, 2, 3, 5, 8, 9…]. Vide = toutes les pages. */
    public static function pages(?string $spec, int $count): array
    {
        $spec = trim(mb_strtolower((string) $spec));
        if ($spec === '' || $spec === 'toutes' || $spec === 'all') {
            return range(1, $count);
        }
        $out = [];
        foreach (preg_split('/[,;\s]+/', $spec) as $part) {
            if ($part === '') {
                continue;
            }
            $part = str_replace(['fin', 'end', 'n'], (string) $count, $part);
            if (preg_match('/^(\d+)-(\d+)$/', $part, $m)) {
                $a = (int) $m[1];
                $b = (int) $m[2];
                foreach ($a <= $b ? range($a, $b) : range($a, $b, -1) as $p) {
                    $out[] = $p;
                }
            } elseif (ctype_digit($part)) {
                $out[] = (int) $part;
            } else {
                throw new \DomainException('Pages « '.$spec.' » : écrivez par exemple 1-3, 5, 8-fin.');
            }
        }
        $out = array_values(array_filter($out, fn ($p) => $p >= 1 && $p <= $count));
        $out || throw new \DomainException('Aucune page valide dans « '.$spec.' » (le document a '.$count.' page(s)).');

        return $out;
    }

    /** Place la page $tpl, éventuellement pivotée (90, 180, 270). */
    protected function place(Fpdi $pdf, string $tpl, int $rotate = 0): array
    {
        $s = $pdf->getTemplateSize($tpl);
        $w = $s['width'];
        $h = $s['height'];
        $rotate = (($rotate % 360) + 360) % 360;
        $pw = in_array($rotate, [90, 270], true) ? $h : $w;
        $ph = in_array($rotate, [90, 270], true) ? $w : $h;
        $pdf->AddPage($pw > $ph ? 'L' : 'P', [$pw, $ph]);
        if ($rotate === 0) {
            $pdf->useTemplate($tpl, 0, 0, $w, $h);
        } else {
            $pdf->StartTransform();
            $pdf->Rotate(-$rotate, $pw / 2, $ph / 2); // sens horaire
            $pdf->useTemplate($tpl, ($pw - $w) / 2, ($ph - $h) / 2, $w, $h);
            $pdf->StopTransform();
        }

        return [$pw, $ph];
    }

    protected function output(Fpdi $pdf): string
    {
        return $pdf->Output('', 'S');
    }

    // ------------------------------------------------------------------ opérations

    public function info(string $path): array
    {
        $pdf = $this->blank();
        $n = $this->open($pdf, $path);
        $sizes = [];
        for ($i = 1; $i <= min($n, 3); $i++) {
            $s = $pdf->getTemplateSize($pdf->importPage($i));
            $sizes[] = ['width_mm' => round($s['width']), 'height_mm' => round($s['height'])];
        }

        return ['pages' => $n, 'size' => filesize($path), 'first_pages' => $sizes];
    }

    /** Fusion de plusieurs PDF (et pages choisies de chacun). */
    public function merge(array $files, array $ranges = []): string
    {
        count($files) >= 1 || $this->fail('Ajoutez au moins un fichier.');
        $pdf = $this->blank();
        foreach ($files as $i => $f) {
            $n = $this->open($pdf, $f);
            foreach (self::pages($ranges[$i] ?? null, $n) as $p) {
                $this->place($pdf, $pdf->importPage($p));
            }
        }

        return $this->output($pdf);
    }

    /** Réorganiser : pages dans l'ordre donné (les autres sont supprimées), avec rotation par page. */
    public function organize(string $path, ?string $order, array $rotations = []): string
    {
        $pdf = $this->blank();
        $n = $this->open($pdf, $path);
        foreach (self::pages($order, $n) as $p) {
            $this->place($pdf, $pdf->importPage($p), (int) ($rotations[$p] ?? 0));
        }

        return $this->output($pdf);
    }

    public function rotate(string $path, int $angle, ?string $pages): string
    {
        $pdf = $this->blank();
        $n = $this->open($pdf, $path);
        $sel = array_flip(self::pages($pages, $n));
        for ($p = 1; $p <= $n; $p++) {
            $this->place($pdf, $pdf->importPage($p), isset($sel[$p]) ? $angle : 0);
        }

        return $this->output($pdf);
    }

    /** Découpe : un PDF par groupe de pages (ex. « 1-2, 3-5 » ou toutes les N pages). @return array<string, string> nom => contenu */
    public function split(string $path, ?string $groups, int $every = 0): array
    {
        $probe = $this->blank();
        $n = $this->open($probe, $path);
        $sets = [];
        if ($every > 0) {
            for ($a = 1; $a <= $n; $a += $every) {
                $sets[] = range($a, min($n, $a + $every - 1));
            }
        } else {
            foreach (preg_split('/[;,]+/', (string) $groups) as $g) {
                if (trim($g) !== '') {
                    $sets[] = self::pages($g, $n);
                }
            }
        }
        $sets || $this->fail('Indiquez les groupes de pages (ex. 1-2, 3-5) ou « toutes les N pages ».');
        $out = [];
        foreach ($sets as $k => $pages) {
            $pdf = $this->blank();
            $this->open($pdf, $path);
            foreach ($pages as $p) {
                $this->place($pdf, $pdf->importPage($p));
            }
            $out['pages-'.(count($pages) === 1 ? $pages[0] : $pages[0].'-'.end($pages)).'.pdf'] = $this->output($pdf);
        }

        return $out;
    }

    protected function rgb(?string $hex, array $default = [217, 48, 37]): array
    {
        return preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', (string) $hex, $m) ? [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])] : $default;
    }

    /**
     * Filigrane texte (ou image) sur les pages choisies.
     * $o : text, image (chemin), opacity 0-1, size, angle, color, mode (center, diagonal, tile, top, bottom), pages
     */
    public function watermark(string $path, array $o): string
    {
        $pdf = $this->blank();
        $n = $this->open($pdf, $path);
        $sel = array_flip(self::pages($o['pages'] ?? null, $n));
        $text = trim((string) ($o['text'] ?? ''));
        $image = $o['image'] ?? null;
        ($text !== '' || $image) || $this->fail('Saisissez le texte du filigrane ou choisissez une image.');
        $alpha = max(.05, min(1, (float) ($o['opacity'] ?? .25)));
        $mode = $o['mode'] ?? 'diagonal';
        $size = max(8, min(200, (int) ($o['size'] ?? 60)));
        [$r, $g, $b] = $this->rgb($o['color'] ?? null, [120, 120, 120]);
        for ($p = 1; $p <= $n; $p++) {
            [$w, $h] = $this->place($pdf, $pdf->importPage($p));
            if (! isset($sel[$p])) {
                continue;
            }
            $pdf->SetAlpha($alpha);
            if ($image) {
                $iw = $w * max(.1, min(1, (float) ($o['scale'] ?? .5)));
                [$sw, $sh] = getimagesize($image) ?: [1, 1];
                $ih = $iw * $sh / max(1, $sw);
                $pdf->Image($image, ($w - $iw) / 2, ($h - $ih) / 2, $iw, $ih, '', '', '', true, 300);
            } else {
                $pdf->SetFont('helvetica', 'B', $size);
                $pdf->SetTextColor($r, $g, $b);
                $angle = match ($mode) {
                    'diagonal', 'tile' => (float) ($o['angle'] ?? round(rad2deg(atan2($h, $w)))),
                    default => (float) ($o['angle'] ?? 0),
                };
                $tw = $pdf->GetStringWidth($text);
                $positions = match ($mode) {
                    'top' => [[$w / 2, 15]],
                    'bottom' => [[$w / 2, $h - 15]],
                    'tile' => (function () use ($w, $h, $tw, $size) {
                        $pts = [];
                        $stepX = max($tw * .9, 60);
                        $stepY = max($size * 1.6, 40);
                        for ($y = $stepY / 2, $row = 0; $y < $h + $stepY; $y += $stepY, $row++) {
                            for ($x = ($row % 2) * $stepX / 2; $x < $w + $stepX; $x += $stepX) {
                                $pts[] = [$x, $y];
                            }
                        }

                        return $pts;
                    })(),
                    default => [[$w / 2, $h / 2]],
                };
                foreach ($positions as [$cx, $cy]) {
                    $pdf->StartTransform();
                    $pdf->Rotate($angle, $cx, $cy);
                    $pdf->Text($cx - $tw / 2, $cy - $size * .18, $text);
                    $pdf->StopTransform();
                }
            }
            $pdf->SetAlpha(1);
        }

        return $this->output($pdf);
    }

    /**
     * Ajouts sur les pages (édition) : textes et images placés en % de la page.
     * $items : [{type: text|image, page: 1|"all", x, y (en %), text, size, color, bold, width (en % pour une image), image (chemin), opacity}]
     */
    public function annotate(string $path, array $items): string
    {
        $items || $this->fail('Ajoutez au moins un texte ou une image.');
        $pdf = $this->blank();
        $n = $this->open($pdf, $path);
        for ($p = 1; $p <= $n; $p++) {
            [$w, $h] = $this->place($pdf, $pdf->importPage($p));
            foreach ($items as $it) {
                $pg = $it['page'] ?? 1;
                if ($pg !== 'all' && (int) $pg !== $p) {
                    continue;
                }
                $x = $w * max(0, min(100, (float) ($it['x'] ?? 10))) / 100;
                $y = $h * max(0, min(100, (float) ($it['y'] ?? 10))) / 100;
                $pdf->SetAlpha(max(.05, min(1, (float) ($it['opacity'] ?? 1))));
                if (($it['type'] ?? 'text') === 'image' && ! empty($it['image'])) {
                    $iw = $w * max(2, min(100, (float) ($it['width'] ?? 25))) / 100;
                    $pdf->Image($it['image'], $x, $y, $iw, 0, '', '', '', true, 300);
                } else {
                    [$r, $g, $b] = $this->rgb($it['color'] ?? null, [17, 26, 46]);
                    $pdf->SetTextColor($r, $g, $b);
                    $pdf->SetFont('dejavusans', ! empty($it['bold']) ? 'B' : '', max(5, min(120, (int) ($it['size'] ?? 12))));
                    $pdf->SetXY($x, $y);
                    $pdf->MultiCell($w - $x - 5, 0, (string) ($it['text'] ?? ''), 0, 'L', false, 1, $x, $y, true, 0, false, true, 0, 'T');
                }
                $pdf->SetAlpha(1);
            }
        }

        return $this->output($pdf);
    }

    /**
     * Éditeur visuel (« Modifier le PDF ») : tout est exprimé en fractions de la page (0 à 1) pour être indépendant du zoom.
     * Types : text (avec fond facultatif pour remplacer un texte existant), whiteout, rect, ellipse, highlight, line, arrow, ink (dessin, signature), image.
     * $pages : ['delete' => [3, 5], 'rotate' => ['2' => 90]] appliqués en même temps.
     */
    public function edit(string $path, array $items, array $pages = []): string
    {
        $pdf = $this->blank();
        $n = $this->open($pdf, $path);
        $delete = array_map('intval', (array) ($pages['delete'] ?? []));
        count(array_diff(range(1, $n), $delete)) > 0 || $this->fail('Vous ne pouvez pas supprimer toutes les pages.');
        $rot = (array) ($pages['rotate'] ?? []);
        $byPage = [];
        foreach (array_slice($items, 0, 800) as $it) {
            if (is_array($it)) {
                $byPage[max(1, (int) ($it['page'] ?? 1))][] = $it;
            }
        }
        $f = fn ($v, $d = 0) => max(0, min(1, (float) ($v ?? $d)));
        for ($p = 1; $p <= $n; $p++) {
            if (in_array($p, $delete, true)) {
                continue;
            }
            [$w, $h] = $this->place($pdf, $pdf->importPage($p), (int) ($rot[(string) $p] ?? 0));
            foreach ($byPage[$p] ?? [] as $it) {
                $type = (string) ($it['type'] ?? 'text');
                $x = $w * $f($it['x'] ?? 0);
                $y = $h * $f($it['y'] ?? 0);
                $bw = $w * $f($it['w'] ?? .2);
                $bh = $h * $f($it['h'] ?? .05);
                $sw = max(.1, $w * min(.05, (float) ($it['stroke'] ?? .002))); // épaisseur relative à la largeur
                $alpha = max(.05, min(1, (float) ($it['opacity'] ?? 1)));
                [$r, $g, $b] = $this->rgb($it['color'] ?? null, [17, 26, 46]);
                $pdf->SetAlpha($alpha);
                switch ($type) {
                    case 'whiteout':
                        [$fr, $fg, $fb] = $this->rgb($it['fill'] ?? '#FFFFFF', [255, 255, 255]);
                        $pdf->Rect($x, $y, $bw, $bh, 'F', [], [$fr, $fg, $fb]);
                        break;
                    case 'highlight':
                        [$fr, $fg, $fb] = $this->rgb($it['fill'] ?? '#FFE600', [255, 230, 0]);
                        $pdf->SetAlpha(max(.1, min(.8, (float) ($it['opacity'] ?? .4))), 'Multiply');
                        $pdf->Rect($x, $y, $bw, $bh, 'F', [], [$fr, $fg, $fb]);
                        break;
                    case 'rect':
                    case 'ellipse':
                        $fill = ! empty($it['fill']) ? $this->rgb($it['fill'], [255, 255, 255]) : null;
                        $pdf->SetLineStyle(['width' => $sw, 'color' => [$r, $g, $b], 'dash' => 0, 'cap' => 'round', 'join' => 'round']);
                        $style = $fill ? 'DF' : 'D';
                        if ($type === 'rect') {
                            $pdf->Rect($x, $y, $bw, $bh, $style, ['all' => ['width' => $sw, 'color' => [$r, $g, $b]]], $fill ?? []);
                        } else {
                            $pdf->Ellipse($x + $bw / 2, $y + $bh / 2, $bw / 2, $bh / 2, 0, 0, 360, $style, ['width' => $sw, 'color' => [$r, $g, $b]], $fill ?? []);
                        }
                        break;
                    case 'line':
                    case 'arrow':
                        $x2 = $w * $f($it['x2'] ?? .5);
                        $y2 = $h * $f($it['y2'] ?? .5);
                        $pdf->SetLineStyle(['width' => $sw, 'color' => [$r, $g, $b], 'dash' => 0, 'cap' => 'round']);
                        if ($type === 'arrow') {
                            $pdf->Arrow($x, $y, $x2, $y2, 2, max(2, $sw * 5), 18);
                        } else {
                            $pdf->Line($x, $y, $x2, $y2);
                        }
                        break;
                    case 'ink':
                        $pdf->SetLineStyle(['width' => $sw, 'color' => [$r, $g, $b], 'dash' => 0, 'cap' => 'round', 'join' => 'round']);
                        foreach (array_slice((array) ($it['strokes'] ?? []), 0, 200) as $stroke) {
                            $pts = [];
                            foreach (array_slice((array) $stroke, 0, 2000) as $pt) {
                                if (is_array($pt) && count($pt) >= 2) {
                                    $pts[] = $w * $f($pt[0]);
                                    $pts[] = $h * $f($pt[1]);
                                }
                            }
                            if (count($pts) === 2) {
                                $pdf->Circle($pts[0], $pts[1], $sw / 2, 0, 360, 'F', [], [$r, $g, $b]);
                            } elseif (count($pts) >= 4) {
                                $pdf->PolyLine($pts, 'D');
                            }
                        }
                        break;
                    case 'image':
                        if (! empty($it['image'])) {
                            $pdf->Image($it['image'], $x, $y, $bw, $bh ?: 0, '', '', '', true, 300, '', false, false, 0, ! empty($it['keep_ratio']) ? 'CM' : false);
                        }
                        break;
                    default: // texte
                        if (! empty($it['bg'])) { // remplacer un texte existant : on masque, puis on réécrit
                            $pdf->SetAlpha(1);
                            [$fr, $fg, $fb] = $this->rgb($it['bg'], [255, 255, 255]);
                            $pdf->Rect($x, $y, $bw, $bh, 'F', [], [$fr, $fg, $fb]);
                            $pdf->SetAlpha($alpha);
                        }
                        $family = in_array($it['font'] ?? '', ['helvetica', 'times', 'courier'], true) ? $it['font'] : 'dejavusans';
                        $style = (! empty($it['bold']) ? 'B' : '').(! empty($it['italic']) ? 'I' : '').(! empty($it['underline']) ? 'U' : '');
                        // taille : fraction de la hauteur de page → points
                        $pt = max(4, min(200, (float) ($it['size'] ?? .016) * $h / 0.352778));
                        $pdf->SetFont($family, $style, $pt);
                        $pdf->SetTextColor($r, $g, $b);
                        $align = in_array($it['align'] ?? 'L', ['L', 'C', 'R', 'J'], true) ? ($it['align'] ?? 'L') : 'L';
                        $pdf->setCellPaddings(0, 0, 0, 0);
                        $pdf->setCellHeightRatio(1.15);
                        $pdf->MultiCell(max(2, $bw), 0, (string) ($it['text'] ?? ''), 0, $align, false, 1, $x, $y, true, 0, false, true, 0, 'T');
                }
                $pdf->SetAlpha(1);
            }
        }

        return $this->output($pdf);
    }

    /** Numéros de page : format « Page {n} sur {total} », position et taille. */
    public function numbers(string $path, array $o): string
    {
        $pdf = $this->blank();
        $n = $this->open($pdf, $path);
        $fmt = trim((string) ($o['format'] ?? '')) ?: 'Page {n} sur {total}';
        $start = max(1, (int) ($o['start'] ?? 1));
        $skip = max(0, (int) ($o['skip_first'] ?? 0));
        $pos = $o['position'] ?? 'bottom-center';
        $size = max(6, min(30, (int) ($o['size'] ?? 10)));
        for ($p = 1; $p <= $n; $p++) {
            [$w, $h] = $this->place($pdf, $pdf->importPage($p));
            if ($p <= $skip) {
                continue;
            }
            $label = strtr($fmt, ['{n}' => (string) ($p - $skip + $start - 1), '{total}' => (string) ($n - $skip + $start - 1)]);
            $pdf->SetFont('dejavusans', '', $size);
            $pdf->SetTextColor(60, 66, 80);
            $tw = $pdf->GetStringWidth($label);
            $y = str_starts_with($pos, 'top') ? 8 : $h - 12;
            $x = match (true) {
                str_ends_with($pos, 'left') => 12,
                str_ends_with($pos, 'right') => $w - 12 - $tw,
                default => ($w - $tw) / 2,
            };
            $pdf->Text($x, $y, $label);
        }

        return $this->output($pdf);
    }

    /** Protection par mot de passe (AES-256) et restrictions (impression, copie, modification). */
    public function protect(string $path, string $userPassword, ?string $ownerPassword, array $allow = []): string
    {
        (mb_strlen($userPassword) >= 4 || ($ownerPassword && mb_strlen($ownerPassword) >= 4)) || $this->fail('Choisissez un mot de passe d\'au moins 4 caractères.');
        $pdf = $this->blank();
        $n = $this->open($pdf, $path);
        for ($p = 1; $p <= $n; $p++) {
            $this->place($pdf, $pdf->importPage($p));
        }
        // TCPDF : liste des droits RETIRÉS
        $all = ['print' => ['print', 'print-high'], 'copy' => ['copy', 'extract'], 'modify' => ['modify', 'assemble', 'annot-forms', 'fill-forms']];
        $blocked = [];
        foreach ($all as $k => $perms) {
            if (! in_array($k, $allow, true)) {
                array_push($blocked, ...$perms);
            }
        }
        $pdf->SetProtection($blocked, $userPassword, $ownerPassword ?: bin2hex(random_bytes(12)), 3);

        return $this->output($pdf);
    }

    /** Retire le mot de passe (nécessite qpdf sur le serveur et le mot de passe du document). */
    public function unlock(string $path, string $password): string
    {
        $bin = PdfNormalizer::qpdf() ?: $this->fail('Le déverrouillage n\'est pas disponible sur ce serveur (outil qpdf absent). Demandez à votre hébergeur de l\'installer.');
        $out = $this->tmp();
        // mot de passe dans un fichier temporaire : jamais visible dans la liste des processus
        $pw = $this->tmp('txt');
        file_put_contents($pw, $password);
        @chmod($pw, 0600);
        @exec(escapeshellarg($bin).' --password-file='.escapeshellarg($pw).' --decrypt '.escapeshellarg($path).' '.escapeshellarg($out).' 2>&1', $o, $code);
        if (! in_array($code, [0, 3], true) || ! is_file($out) || filesize($out) === 0) {
            $this->fail(str_contains(implode(' ', $o), 'password') ? 'Mot de passe incorrect.' : 'Ce PDF n\'a pas pu être déverrouillé.');
        }

        return (string) file_get_contents($out);
    }

    /** Images (JPG, PNG) → un PDF, une image par page, ajustée à la page (A4 ou taille de l'image). */
    public function images(array $images, string $format = 'A4', int $margin = 10): string
    {
        $images || $this->fail('Ajoutez au moins une image.');
        $pdf = $this->blank();
        foreach ($images as $img) {
            [$iw, $ih] = getimagesize($img) ?: $this->fail('Image illisible.');
            if ($format === 'fit') {
                $w = $iw * 25.4 / 150;
                $h = $ih * 25.4 / 150;
                $pdf->AddPage($w > $h ? 'L' : 'P', [$w, $h]);
                $pdf->Image($img, 0, 0, $w, $h, '', '', '', true, 300);

                continue;
            }
            $land = $iw > $ih;
            $pdf->AddPage($land ? 'L' : 'P', 'A4');
            $pw = $pdf->getPageWidth() - 2 * $margin;
            $ph = $pdf->getPageHeight() - 2 * $margin;
            $k = min($pw / $iw, $ph / $ih);
            $pdf->Image($img, $margin + ($pw - $iw * $k) / 2, $margin + ($ph - $ih * $k) / 2, $iw * $k, $ih * $k, '', '', '', true, 300);
        }

        return $this->output($pdf);
    }
}
