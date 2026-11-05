<?php

namespace App\Modules\Marketing;

use App\Models\Company;

/**
 * Transforme les blocs de l'éditeur en e-mail HTML compatible avec les messageries
 * (mise en page en tableaux, styles en ligne, 600 px, lisible sur mobile).
 *
 * Blocs : heading, text, image, button, divider, spacer, columns, html, social, quote,
 *         hero (bannière), product, products (grille), coupon, video, columns3, menu, footer, rss (derniers articles d'un flux).
 * Variables : {{prenom}}, {{nom}}, {{nom_complet}}, {{societe}}, {{email}}, {{telephone}},
 *             {{entreprise}}, {{lien_desinscription}}.
 */
class EmailRenderer
{
    public const BLOCK_TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'columns', 'html', 'social', 'quote',
        'hero', 'product', 'products', 'coupon', 'video', 'columns3', 'menu', 'footer', 'rss'];

    public function __construct(protected Company $company) {}

    public function style(?array $style): array
    {
        $s = array_replace([
            'primary' => '#1F6FEB', 'background' => '#F4F6FB', 'card' => '#FFFFFF', 'text' => '#0B1B3A',
            'font' => 'Arial, Helvetica, sans-serif', 'logo' => null, 'show_header' => true,
        ], array_filter((array) $style, fn ($v) => $v !== null && $v !== ''));
        // valeurs reprises dans des attributs style : couleurs hexadécimales et noms de polices seulement
        foreach (['primary' => '#1F6FEB', 'background' => '#F4F6FB', 'card' => '#FFFFFF', 'text' => '#0B1B3A'] as $k => $def) {
            if (! preg_match('/^#[0-9a-f]{3,8}$/i', (string) $s[$k])) {
                $s[$k] = $def;
            }
        }
        if (! preg_match('/^[A-Za-z0-9 ,\'"-]{1,120}$/', (string) $s['font'])) {
            $s['font'] = 'Arial, Helvetica, sans-serif';
        }

        return $s;
    }

    public static function vars(string $s, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $s);
    }

    /** Texte enrichi minimal : **gras**, *italique*, [lien](url), retours à la ligne. */
    public static function inline(string $text, string $linkColor): string
    {
        $t = e($text);
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
        $t = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $t);
        $t = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\{\{lien_[a-z_]+\}\})\)/', fn ($m) => '<a href="'.$m[2].'" style="color:'.$linkColor.';">'.$m[1].'</a>', $t);
        $t = preg_replace('/\R\R+/', '</p><p style="margin:0 0 12px;">', $t);

        return nl2br($t, false);
    }

    protected static function safeUrl(?string $u): string
    {
        $u = trim((string) $u);

        // Adresse web, e-mail, téléphone, ou variable de lien de la plateforme seule ({{lien_desinscription}}) ;
        // jamais « {{prenom}}javascript:… » (la variable remplacée laisserait un schéma dangereux).
        return preg_match('#^(https?://|mailto:|tel:)#i', $u) || preg_match('/^\{\{\s*lien_[a-z_]+\s*\}\}$/', $u) ? e($u) : '#';
    }

    protected function block(array $b, array $s): string
    {
        $p = e($s['primary']);
        $c = e($s['text']);
        $align = in_array($b['align'] ?? 'left', ['left', 'center', 'right'], true) ? ($b['align'] ?? 'left') : 'left';

        return match ($b['type'] ?? '') {
            'heading' => '<tr><td style="padding:8px 32px;text-align:'.$align.';"><'.(($b['size'] ?? 'h1') === 'h2' ? 'h2' : 'h1').' style="margin:0;font-size:'.(($b['size'] ?? 'h1') === 'h2' ? '20' : '26').'px;line-height:1.3;color:'.$c.';">'.e($b['text'] ?? '').'</'.(($b['size'] ?? 'h1') === 'h2' ? 'h2' : 'h1').'></td></tr>',
            'text' => '<tr><td style="padding:8px 32px;font-size:15px;line-height:1.6;color:'.$c.';text-align:'.$align.';"><p style="margin:0 0 12px;">'.self::inline((string) ($b['text'] ?? ''), $p).'</p></td></tr>',
            'quote' => '<tr><td style="padding:8px 32px;"><div style="border-left:4px solid '.$p.';padding:8px 16px;font-style:italic;color:'.$c.';font-size:15px;line-height:1.6;">'.self::inline((string) ($b['text'] ?? ''), $p).(! empty($b['author']) ? '<br><span style="font-style:normal;font-weight:bold;">— '.e($b['author']).'</span>' : '').'</div></td></tr>',
            'image' => empty($b['url']) ? '' : '<tr><td style="padding:8px 32px;text-align:center;">'.(! empty($b['link']) ? '<a href="'.self::safeUrl($b['link']).'">' : '').'<img src="'.self::safeUrl($b['url']).'" alt="'.e($b['alt'] ?? '').'" width="'.min(536, (int) (536 * (($b['width'] ?? 100) / 100))).'" style="max-width:100%;height:auto;border:0;border-radius:8px;display:inline-block;">'.(! empty($b['link']) ? '</a>' : '').'</td></tr>',
            'button' => '<tr><td style="padding:12px 32px;text-align:'.(in_array($b['align'] ?? 'center', ['left', 'center', 'right'], true) ? ($b['align'] ?? 'center') : 'center').';"><a href="'.self::safeUrl($b['url'] ?? '#').'" style="display:inline-block;background:'.e(preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($b['color'] ?? '')) ? $b['color'] : $s['primary']).';color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:13px 26px;border-radius:8px;">'.e($b['label'] ?? 'En savoir plus').'</a></td></tr>',
            'divider' => '<tr><td style="padding:12px 32px;"><div style="border-top:1px solid #E3E8F1;"></div></td></tr>',
            'spacer' => '<tr><td style="height:'.max(4, min(120, (int) ($b['height'] ?? 24))).'px;line-height:1px;font-size:1px;">&nbsp;</td></tr>',
            'columns' => '<tr><td style="padding:8px 26px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
                .'<td class="col" width="50%" valign="top" style="padding:6px;font-size:14px;line-height:1.6;color:'.$c.';">'.(! empty($b['left_image']) ? '<img src="'.self::safeUrl($b['left_image']).'" width="262" style="max-width:100%;border-radius:8px;display:block;margin-bottom:8px;">' : '').self::inline((string) ($b['left'] ?? ''), $p).'</td>'
                .'<td class="col" width="50%" valign="top" style="padding:6px;font-size:14px;line-height:1.6;color:'.$c.';">'.(! empty($b['right_image']) ? '<img src="'.self::safeUrl($b['right_image']).'" width="262" style="max-width:100%;border-radius:8px;display:block;margin-bottom:8px;">' : '').self::inline((string) ($b['right'] ?? ''), $p).'</td>'
                .'</tr></table></td></tr>',
            'social' => '<tr><td style="padding:12px 32px;text-align:center;font-size:14px;">'.implode(' &nbsp;·&nbsp; ', array_filter([
                ! empty($b['website']) ? '<a href="'.self::safeUrl($b['website']).'" style="color:'.$p.';">Site web</a>' : null,
                ! empty($b['facebook']) ? '<a href="'.self::safeUrl($b['facebook']).'" style="color:'.$p.';">Facebook</a>' : null,
                ! empty($b['instagram']) ? '<a href="'.self::safeUrl($b['instagram']).'" style="color:'.$p.';">Instagram</a>' : null,
                ! empty($b['linkedin']) ? '<a href="'.self::safeUrl($b['linkedin']).'" style="color:'.$p.';">LinkedIn</a>' : null,
                ! empty($b['whatsapp']) ? '<a href="https://wa.me/'.preg_replace('/\D/', '', (string) $b['whatsapp']).'" style="color:'.$p.';">WhatsApp</a>' : null,
            ])).'</td></tr>',
            'hero' => $this->hero($b, $s),
            'product' => $this->product($b, $s),
            'products' => $this->products($b, $s),
            'coupon' => '<tr><td style="padding:12px 32px;"><div style="border:2px dashed '.$p.';border-radius:10px;padding:18px;text-align:center;color:'.$c.';">'
                .(! empty($b['text']) ? '<div style="font-size:15px;margin-bottom:8px;">'.self::inline((string) $b['text'], $p).'</div>' : '')
                .'<div style="font-size:26px;font-weight:bold;letter-spacing:3px;color:'.$p.';">'.e($b['code'] ?? 'CODE').'</div>'
                .(! empty($b['expires']) ? '<div style="font-size:12px;color:#5B6B85;margin-top:6px;">'.e($b['expires']).'</div>' : '').'</div></td></tr>',
            'video' => $this->video($b, $s),
            'columns3' => $this->columns3($b, $s),
            'menu' => '<tr><td style="padding:10px 32px;text-align:center;font-size:14px;">'.implode(' &nbsp;|&nbsp; ', array_map(
                fn ($l) => '<a href="'.self::safeUrl($l[1] ?? '#').'" style="color:'.$c.';text-decoration:none;font-weight:bold;">'.e($l[0]).'</a>',
                self::links((string) ($b['links'] ?? '')))).'</td></tr>',
            'footer' => '<tr><td style="padding:16px 32px;font-size:12px;line-height:1.5;color:#5B6B85;text-align:'.$align.';">'.self::inline((string) ($b['text'] ?? ''), '#5B6B85').'</td></tr>',
            'rss' => $this->rss($b, $s),
            // Section HTML conçue par le client : insérée telle quelle (seuls les scripts sont retirés)
            'html' => '<tr><td style="padding:8px 32px;">'.self::stripScripts((string) ($b['html'] ?? '')).'</td></tr>',
            default => '',
        };
    }

    /** « Libellé|https://… » une ligne par lien */
    public static function links(string $s): array
    {
        $out = [];
        foreach (preg_split('/\R/', $s) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if ($parts[0] !== '') {
                $out[] = [$parts[0], $parts[1] ?? '#'];
            }
        }

        return $out;
    }

    protected function btn(array $b, array $s, string $labelKey = 'button_label', string $urlKey = 'url', ?string $color = null): string
    {
        if (empty($b[$labelKey])) {
            return '';
        }

        return '<a href="'.self::safeUrl($b[$urlKey] ?? '#').'" style="display:inline-block;background:'.e($color ?? $b['button_color'] ?? $s['primary']).';color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 24px;border-radius:8px;">'.e($b[$labelKey]).'</a>';
    }

    protected function hero(array $b, array $s): string
    {
        $bg = e($b['bg_color'] ?? $s['primary']);
        $fg = e($b['text_color'] ?? '#FFFFFF');
        $align = in_array($b['align'] ?? 'center', ['left', 'center', 'right'], true) ? ($b['align'] ?? 'center') : 'center';

        return '<tr><td style="padding:0;">'
            .(! empty($b['image']) ? '<img src="'.self::safeUrl($b['image']).'" alt="" width="600" style="width:100%;max-width:600px;height:auto;display:block;border:0;">' : '')
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td style="background:'.$bg.';padding:32px;text-align:'.$align.';color:'.$fg.';">'
            .(! empty($b['title']) ? '<h1 style="margin:0 0 10px;font-size:28px;line-height:1.25;color:'.$fg.';">'.e($b['title']).'</h1>' : '')
            .(! empty($b['text']) ? '<p style="margin:0 0 18px;font-size:16px;line-height:1.6;color:'.$fg.';">'.self::inline((string) $b['text'], $fg).'</p>' : '')
            .$this->btn($b, $s, 'button_label', 'url', $b['button_color'] ?? '#FFC531')
            .'</td></tr></table></td></tr>';
    }

    protected function priceHtml(array $item, array $s): string
    {
        return (! empty($item['old_price']) ? '<span style="text-decoration:line-through;color:#5B6B85;font-size:13px;">'.e($item['old_price']).'</span> ' : '')
            .(! empty($item['price']) ? '<span style="font-weight:bold;font-size:17px;color:'.e($s['primary']).';">'.e($item['price']).'</span>' : '');
    }

    protected function product(array $b, array $s): string
    {
        $c = e($s['text']);

        return '<tr><td style="padding:10px 26px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #E3E8F1;border-radius:10px;"><tr>'
            .'<td class="col" width="42%" valign="middle" style="padding:12px;">'.(! empty($b['image']) ? '<img src="'.self::safeUrl($b['image']).'" alt="'.e($b['name'] ?? '').'" width="210" style="max-width:100%;height:auto;border-radius:8px;display:block;">' : '').'</td>'
            .'<td class="col" valign="middle" style="padding:12px;color:'.$c.';font-size:14px;line-height:1.5;">'
            .'<div style="font-size:18px;font-weight:bold;margin-bottom:4px;">'.e($b['name'] ?? '').'</div>'
            .(! empty($b['description']) ? '<div style="margin-bottom:8px;">'.self::inline((string) $b['description'], e($s['primary'])).'</div>' : '')
            .'<div style="margin-bottom:12px;">'.$this->priceHtml($b, $s).'</div>'
            .$this->btn(['button_label' => $b['button_label'] ?? 'Commander', 'url' => $b['url'] ?? '#'], $s)
            .'</td></tr></table></td></tr>';
    }

    protected function products(array $b, array $s): string
    {
        $items = array_slice(array_values(array_filter((array) ($b['items'] ?? []), 'is_array')), 0, 6);
        if (! $items) {
            return '';
        }
        $per = min(3, count($items));
        $w = (int) floor(100 / $per);
        $rows = '';
        foreach (array_chunk($items, $per) as $chunk) {
            $rows .= '<tr>';
            foreach ($chunk as $it) {
                $rows .= '<td class="col" width="'.$w.'%" valign="top" style="padding:8px;text-align:center;font-size:14px;color:'.e($s['text']).';">'
                    .(! empty($it['image']) ? '<img src="'.self::safeUrl($it['image']).'" alt="" width="'.(int) (536 / $per - 16).'" style="max-width:100%;height:auto;border-radius:8px;display:block;margin:0 auto 8px;">' : '')
                    .'<div style="font-weight:bold;margin-bottom:4px;">'.e($it['name'] ?? '').'</div><div style="margin-bottom:8px;">'.$this->priceHtml($it, $s).'</div>'
                    .(! empty($it['url']) ? '<a href="'.self::safeUrl($it['url']).'" style="color:'.e($s['primary']).';font-weight:bold;">'.e($b['button_label'] ?? 'Voir').'</a>' : '')
                    .'</td>';
            }
            $rows .= str_repeat('<td class="col"></td>', $per - count($chunk)).'</tr>';
        }

        return '<tr><td style="padding:8px 24px;">'.(! empty($b['title']) ? '<h2 style="margin:4px 8px 8px;font-size:20px;color:'.e($s['text']).';">'.e($b['title']).'</h2>' : '')
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0">'.$rows.'</table></td></tr>';
    }

    public static function videoThumb(?string $url): ?string
    {
        if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})#', (string) $url, $m)) {
            return 'https://img.youtube.com/vi/'.$m[1].'/hqdefault.jpg';
        }

        return null;
    }

    protected function video(array $b, array $s): string
    {
        $thumb = ($b['thumbnail'] ?? null) ?: self::videoThumb($b['url'] ?? null);

        return '<tr><td style="padding:8px 32px;text-align:center;">'
            .'<a href="'.self::safeUrl($b['url'] ?? '#').'" style="text-decoration:none;">'
            .($thumb ? '<img src="'.self::safeUrl($thumb).'" alt="Vidéo" width="536" style="max-width:100%;height:auto;border-radius:8px;display:block;border:0;">' : '')
            .'<div style="margin-top:8px;color:'.e($s['primary']).';font-weight:bold;font-size:15px;">&#9654; '.e($b['caption'] ?? 'Regarder la vidéo').'</div></a></td></tr>';
    }

    protected function columns3(array $b, array $s): string
    {
        $cols = array_slice(array_values((array) ($b['cols'] ?? [])), 0, 3);
        while (count($cols) < 3) {
            $cols[] = [];
        }
        $cells = '';
        foreach ($cols as $col) {
            $cells .= '<td class="col" width="33%" valign="top" style="padding:6px;font-size:14px;line-height:1.5;text-align:center;color:'.e($s['text']).';">'
                .(! empty($col['image']) ? '<img src="'.self::safeUrl($col['image']).'" width="168" style="max-width:100%;border-radius:8px;display:block;margin:0 auto 8px;">' : '')
                .(! empty($col['title']) ? '<div style="font-weight:bold;margin-bottom:4px;">'.e($col['title']).'</div>' : '')
                .self::inline((string) ($col['text'] ?? ''), e($s['primary'])).'</td>';
        }

        return '<tr><td style="padding:8px 26px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'.$cells.'</tr></table></td></tr>';
    }

    /** Derniers articles d'un flux RSS / Atom (mis en cache 30 min). */
    public static function fetchRss(string $url, int $count = 3): array
    {
        if (! preg_match('#^https?://#i', $url) || ! \App\Support\UrlGuard::isPublic($url)) {
            return [];
        }

        $all = \Illuminate\Support\Facades\Cache::remember('mk_rss_'.md5($url), 1800, function () use ($url) {
            try {
                $body = \Illuminate\Support\Facades\Http::timeout(8)->withOptions(\App\Support\UrlGuard::pinned($url))->get($url)->body();
                $prev = libxml_use_internal_errors(true);
                $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
                libxml_use_internal_errors($prev);
                if (! $xml) {
                    return [];
                }
                $items = [];
                $entries = isset($xml->channel->item) ? $xml->channel->item : ($xml->entry ?? []);
                foreach ($entries as $it) {
                    $link = (string) ($it->link['href'] ?? '') ?: (string) $it->link;
                    $desc = (string) ($it->description ?? $it->summary ?? '');
                    $img = null;
                    if (isset($it->enclosure['url'])) {
                        $img = (string) $it->enclosure['url'];
                    } elseif ($media = $it->children('media', true)) {
                        $img = (string) ($media->content?->attributes()?->url ?? $media->thumbnail?->attributes()?->url ?? '') ?: null;
                    }
                    if (! $img && preg_match('/<img[^>]+src="([^"]+)"/i', $desc, $m)) {
                        $img = $m[1];
                    }
                    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($desc))));
                    $items[] = ['title' => trim((string) $it->title), 'url' => $link, 'text' => mb_strimwidth($text, 0, 180, '…'), 'image' => $img,
                        'date' => (string) ($it->pubDate ?? $it->updated ?? '')];
                    if (count($items) >= 10) {
                        break;
                    }
                }

                return $items;
            } catch (\Throwable) {
                return [];
            }
        });

        return array_slice((array) $all, 0, max(1, min(10, $count)));
    }

    protected function rss(array $b, array $s): string
    {
        $items = self::fetchRss((string) ($b['url'] ?? ''), (int) ($b['count'] ?? 3));
        if (! $items) {
            return '';
        }
        $out = '';
        foreach ($items as $it) {
            $out .= '<tr><td style="padding:10px 32px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
                .(($b['show_image'] ?? true) && $it['image'] ? '<td class="col" width="34%" valign="top" style="padding-right:12px;"><img src="'.self::safeUrl($it['image']).'" width="170" style="max-width:100%;border-radius:8px;display:block;"></td>' : '')
                .'<td class="col" valign="top" style="font-size:14px;line-height:1.5;color:'.e($s['text']).';"><a href="'.self::safeUrl($it['url']).'" style="font-size:16px;font-weight:bold;color:'.e($s['text']).';text-decoration:none;">'.e($it['title']).'</a>'
                .'<div style="margin:4px 0 6px;">'.e($it['text']).'</div><a href="'.self::safeUrl($it['url']).'" style="color:'.e($s['primary']).';font-weight:bold;">'.e($b['link_label'] ?? 'Lire la suite').'</a></td></tr></table></td></tr>';
        }

        return (! empty($b['title']) ? '<tr><td style="padding:8px 32px 0;"><h2 style="margin:0;font-size:20px;color:'.e($s['text']).';">'.e($b['title']).'</h2></td></tr>' : '').$out;
    }

    public static function stripScripts(string $html): string
    {
        return \App\Support\HtmlSanitizer::clean($html);
    }

    /** Lignes de tableau des blocs (réutilisées par les pages de destination). */
    public function rows(?array $blocks, array $s): string
    {
        $rows = '';
        foreach ((array) $blocks as $b) {
            if (is_array($b)) {
                $rows .= $this->block($b, $s);
            }
        }

        return $rows;
    }

    /**
     * @param array $blocks blocs de l'éditeur
     * @param string|null $html HTML libre complet (prioritaire s'il est renseigné)
     */
    public function render(?array $blocks, ?string $html, ?array $style, array $vars = [], ?string $preheader = null): string
    {
        $s = $this->style($style);
        $vars += ['entreprise' => $this->company->name, 'lien_desinscription' => '#'];
        if ($html !== null && trim($html) !== '') {
            $doc = self::stripScripts($html);
            if (! str_contains($doc, '{{lien_desinscription}}') && ! stripos($doc, 'désinscri')) {
                $doc .= '<p style="font-size:12px;color:#5B6B85;text-align:center;">'.e($this->company->name).' · <a href="{{lien_desinscription}}">Se désinscrire</a></p>';
            }

            return self::vars($doc, array_map(fn ($v) => e((string) $v), $vars));
        }

        $rows = $this->rows($blocks, $s);
        $logo = $s['logo'] ?? $this->company->logo;
        $header = $s['show_header'] ? '<tr><td style="padding:24px 32px 8px;text-align:left;">'
            .($logo ? '<img src="'.e(preg_match('#^https?://#', $logo) ? $logo : url($logo)).'" alt="'.e($this->company->name).'" height="40" style="height:40px;border:0;">' : '<span style="font-size:18px;font-weight:bold;color:'.e($s['primary']).';">'.e($this->company->name).'</span>')
            .'</td></tr>' : '';
        $address = implode(' · ', array_filter([$this->company->name, $this->company->address, $this->company->city, $this->company->phone, $this->company->email]));
        $footer = '<tr><td style="padding:24px 32px;font-size:12px;line-height:1.5;color:#5B6B85;text-align:center;">'.e($address)
            .'<br>Vous recevez ce message car vous êtes en relation avec '.e($this->company->name).'. <a href="{{lien_desinscription}}" style="color:#5B6B85;">Se désinscrire</a></td></tr>';

        $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting">'
            .'<title>'.e($vars['subject'] ?? $this->company->name).'</title>'
            .'<style>@media (max-width:620px){.card{width:100%!important;border-radius:0!important}.col{display:block!important;width:100%!important}}</style></head>'
            .'<body style="margin:0;padding:0;background:'.e($s['background']).';font-family:'.e($s['font']).';">'
            .($preheader ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'.e($preheader).'</div>' : '')
            .'<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:'.e($s['background']).';"><tr><td align="center" style="padding:24px 8px;">'
            .'<table role="presentation" class="card" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:600px;background:'.e($s['card']).';border-radius:12px;">'
            .$header.$rows.$footer
            .'</table></td></tr></table></body></html>';

        return self::vars($doc, array_map(fn ($v) => e((string) $v), $vars));
    }

    /** Signature d'un lien suivi : seul un lien réellement envoyé peut rediriger. */
    public static function linkSig(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', 'mk|'.$token.'|'.$url, (string) config('app.key')), 0, 24);
    }

    /** Suivi : liens redirigés (clics) et pixel d'ouverture. */
    public static function track(string $html, string $token): string
    {
        $html = preg_replace_callback('/href="(https?:\/\/[^"]+)"/i', function ($m) use ($token) {
            $u = html_entity_decode($m[1]);
            if (str_contains($u, '/m/u/')) {
                return $m[0];
            }

            return 'href="'.url('/m/c/'.$token).'?u='.rtrim(strtr(base64_encode($u), '+/', '-_'), '=').'&amp;s='.self::linkSig($token, $u).'"';
        }, $html);
        $pixel = '<img src="'.url('/m/o/'.$token.'.gif').'" width="1" height="1" alt="" style="display:block;border:0;">';

        return str_contains($html, '</body>') ? str_replace('</body>', $pixel.'</body>', $html) : $html.$pixel;
    }
}
