<?php

namespace App\Modules\Sites;

/** Variables communes aux pages d'un site vitrine (accueil, boutique, blog, suivi de commande). */
class SiteView
{
    public static function vars(array $d): array
    {
        $t = $d['theme'];
        $c = $d['c'];
        $site = $d['site'];
        $company = $d['company'];
        $page = $d['page'] ?? 'home';
        $base = $d['base'] ?? '';
        $qs = ! empty($d['preview']) && $site->preview_token && empty($d['template_preview']) ? '?apercu='.$site->preview_token : '';
        $name = $site->name ?: $company->name;
        $anchors = ['about' => 'apropos', 'services' => 'services', 'products' => 'produits', 'events' => 'evenements', 'gallery' => 'galerie', 'faq' => 'faq', 'contact' => 'contact'];
        $navLabels = ['about' => 'À propos', 'services' => 'Services', 'products' => 'Produits', 'events' => 'Événements', 'gallery' => 'Galerie', 'faq' => 'FAQ', 'contact' => 'Contact'];
        $shopOn = ! empty($d['shop_enabled']);
        $blogOn = ! empty($d['has_blog']);
        $nav = collect($d['sections'])->filter(fn ($k) => isset($anchors[$k]) && ! ($k === 'products' && $shopOn))->take($shopOn || $blogOn ? 4 : 5);
        $whatsapp = $d['whatsapp'] ?? null;

        return [
            't' => $t, 'name' => $name, 'page' => $page, 'qs' => $qs,
            'ic' => fn ($n, $s = 24) => SiteIcons::svg($n, $s),
            'navMode' => $t['nav'] ?? 'light',
            'fonts' => collect([$t['heading_font'] ?? 'Poppins', $t['body_font'] ?? 'Inter'])->unique()->map(fn ($f) => 'family='.str_replace(' ', '+', $f).':wght@400;500;600;700;800')->implode('&'),
            'anchors' => $anchors, 'navLabels' => $navLabels, 'nav' => $nav,
            'href' => fn (string $a) => ($page === 'home' ? '' : $base.'/'.$qs).'#'.$a,
            'home' => ($base ?: '').'/'.$qs,
            'shopUrl' => $base.'/boutique'.$qs, 'blogUrl' => $base.'/blog'.$qs,
            'address' => $c['address'] ?? trim(($company->address ?? '').' '.($company->city ?? '')),
            'socials' => (array) ($c['socials'] ?? []),
            'waText' => fn ($p) => $whatsapp ? 'https://wa.me/'.$whatsapp.'?text='.rawurlencode('Bonjour '.$name.', je suis intéressé(e) par : '.$p) : '#contact',
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'shopOn' => $shopOn, 'blogOn' => $blogOn,
        ];
    }
}
