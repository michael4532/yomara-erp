<?php

namespace App\Modules\Veille;

/**
 * Analyse d'un site concurrent à partir de sa page d'accueil : référencement de base, rapidité,
 * réseaux sociaux reliés, et outils détectés (site, mesure d'audience, publicité, e-mailing, discussion).
 */
class SiteAnalyzer
{
    /** Signatures dans le code de la page : catégorie => [nom => motif]. */
    public const SIGNATURES = [
        'cms' => [
            'WordPress' => '#/wp-content/|/wp-includes/|wp-json#i', 'WooCommerce' => '#woocommerce#i', 'Shopify' => '#cdn\.shopify\.com|Shopify\.theme#i',
            'Wix' => '#static\.wixstatic\.com|wix\.com/#i', 'Squarespace' => '#squarespace\.com|static1\.squarespace#i', 'Webflow' => '#webflow\.(com|io)|data-wf-page#i',
            'Joomla' => '#/media/jui/|Joomla!#i', 'Drupal' => '#Drupal\.settings|/sites/default/files/#i', 'PrestaShop' => '#prestashop#i', 'Magento' => '#Mage\.Cookies|/static/version\d+/frontend/#i',
            'Odoo' => '#/web/assets/|odoo#i', 'Yomara Sites' => '#yomara#i', 'Elementor' => '#elementor#i', 'Google Sites' => '#sites\.google\.com#i', 'Framer' => '#framerusercontent|framer\.com#i',
        ],
        'analytics' => [
            'Google Analytics' => '#google-analytics\.com|gtag/js\?id=G-|googletagmanager\.com/gtag#i', 'Google Tag Manager' => '#googletagmanager\.com/gtm\.js|GTM-[A-Z0-9]{4,}#',
            'Hotjar' => '#static\.hotjar\.com|hotjar#i', 'Microsoft Clarity' => '#clarity\.ms#i', 'Matomo' => '#matomo|piwik#i', 'Yandex Metrica' => '#mc\.yandex\.ru#i',
        ],
        'ads' => [
            'Google Ads' => '#gtag/js\?id=AW-|googleadservices\.com|AW-\d{6,}#', 'Meta Pixel (Facebook / Instagram)' => '#connect\.facebook\.net/[^"\']*/fbevents\.js|fbq\(\s*[\'"]init#i',
            'TikTok Pixel' => '#analytics\.tiktok\.com#i', 'LinkedIn Insight' => '#snap\.licdn\.com|_linkedin_partner_id#i', 'Snapchat Pixel' => '#sc-static\.net/scevent#i',
            'Pinterest Tag' => '#s\.pinimg\.com/ct/|pintrk\(#i', 'X (Twitter) Pixel' => '#static\.ads-twitter\.com|twq\(#i', 'Microsoft Ads (Bing)' => '#bat\.bing\.com#i',
            'Taboola' => '#cdn\.taboola\.com#i', 'Outbrain' => '#outbrain\.com#i', 'Criteo' => '#criteo\.(com|net)#i', 'Google AdSense' => '#pagead2\.googlesyndication\.com#i',
        ],
        'marketing' => [
            'Mailchimp' => '#list-manage\.com|chimpstatic\.com|mailchimp#i', 'Brevo (Sendinblue)' => '#sibforms\.com|sendinblue|brevo\.com#i', 'HubSpot' => '#js\.hs-scripts\.com|hs-analytics|hubspot#i',
            'Klaviyo' => '#static\.klaviyo\.com|klaviyo#i', 'ActiveCampaign' => '#activehosted\.com|trackcmp\.net#i', 'GetResponse' => '#getresponse#i', 'Mailjet' => '#mailjet#i',
            'Omnisend' => '#omnisend#i', 'Zoho' => '#zoho\.(com|eu)|salesiq#i', 'Salesforce' => '#salesforce|pardot|force\.com#i', 'GoHighLevel' => '#leadconnectorhq|msgsndr|gohighlevel#i',
            'Pipedrive' => '#pipedrive#i', 'Calendly' => '#calendly\.com#i', 'Typeform' => '#typeform\.com#i', 'Google Forms' => '#docs\.google\.com/forms#i',
        ],
        'chat' => [
            'Bouton WhatsApp' => '#wa\.me/|api\.whatsapp\.com/send|whatsapp://send#i', 'Messenger' => '#m\.me/|facebook\.com/customer_chat#i', 'Tawk.to' => '#embed\.tawk\.to#i',
            'Crisp' => '#client\.crisp\.chat#i', 'Intercom' => '#widget\.intercom\.io|intercomcdn#i', 'Zendesk' => '#zdassets\.com|zendesk#i', 'Tidio' => '#tidio#i', 'Chatbot IA' => '#chatbase|botpress|voiceflow#i',
        ],
        'payment' => [
            'CinetPay' => '#cinetpay#i', 'PayDunya' => '#paydunya#i', 'Wave' => '#wave\.com|pay\.wave#i', 'Orange Money' => '#orange-?money#i', 'MTN MoMo' => '#mtn ?momo|momo\.mtn|mtn mobile money#i',
            'Stripe' => '#js\.stripe\.com#i', 'PayPal' => '#paypal\.com/sdk|paypalobjects#i', 'Flutterwave' => '#flutterwave#i', 'Paystack' => '#paystack#i',
        ],
    ];

    public const CATEGORY_LABELS = ['cms' => 'Site', 'analytics' => 'Mesure d\'audience', 'ads' => 'Publicité', 'marketing' => 'E-mailing & CRM', 'chat' => 'Discussion', 'payment' => 'Paiement'];

    public const SOCIALS = ['facebook' => '#https?://(www\.|m\.|web\.)?facebook\.com/(?!sharer|share|tr\?|plugins|dialog)[^"\'\s<>]+#i', 'instagram' => '#https?://(www\.)?instagram\.com/[^"\'\s<>]+#i',
        'tiktok' => '#https?://(www\.)?tiktok\.com/@[^"\'\s<>]+#i', 'linkedin' => '#https?://([a-z]{2,3}\.)?linkedin\.com/(company|in|school)/[^"\'\s<>]+#i',
        'youtube' => '#https?://(www\.)?youtube\.com/(@|channel/|c/|user/)[^"\'\s<>]+#i', 'x_account' => '#https?://(www\.)?(twitter|x)\.com/(?!intent|share|home)[A-Za-z0-9_]+#i'];

    public function analyze(string $url): array
    {
        $page = WebFetcher::get($url, 25);
        $html = $page['body'];
        $a = ['url' => $url, 'final_url' => $page['final_url'], 'status' => $page['status'], 'https' => str_starts_with(strtolower($page['final_url']), 'https://'),
            'ms' => $page['ms'], 'kb' => (int) round($page['bytes'] / 1024)];
        if ($page['status'] >= 400) {
            $a['issues'] = ['La page répond avec l\'erreur '.$page['status'].'.'];
            $a['seo_score'] = 0;

            return $a;
        }
        $a += $this->seo($html);
        foreach (self::SIGNATURES as $cat => $sigs) {
            $a[$cat] = array_keys(array_filter($sigs, fn ($re) => preg_match($re, $html) === 1));
        }
        // Un site WooCommerce est aussi un site WordPress, Elementor un constructeur WordPress : on garde l'information utile.
        $a['socials'] = [];
        foreach (self::SOCIALS as $k => $re) {
            if (preg_match($re, $html, $m)) {
                $a['socials'][$k] = rtrim(html_entity_decode($m[0]), '/\\');
            }
        }
        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $page['final_url']);
        $a['robots'] = $this->exists($origin.'/robots.txt', 'user-agent');
        $a['sitemap'] = $this->exists($origin.'/sitemap.xml', '<urlset') || $this->exists($origin.'/sitemap_index.xml', '<sitemapindex') || $this->exists($origin.'/wp-sitemap.xml', '<sitemapindex');
        [$a['seo_score'], $a['issues']] = $this->score($a);

        return $a;
    }

    protected function exists(string $url, string $needle): bool
    {
        try {
            $r = WebFetcher::get($url, 10);

            return $r['status'] === 200 && stripos($r['body'], $needle) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function seo(string $html): array
    {
        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $x = new \DOMXPath($doc);
        $meta = function (string $attr, string $name) use ($x): ?string {
            $n = $x->query('//meta[translate(@'.$attr.',"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="'.$name.'"]/@content')->item(0);

            return $n ? trim($n->nodeValue) : null;
        };
        $title = trim((string) ($x->query('//title')->item(0)?->textContent ?? ''));
        $h1 = [];
        foreach ($x->query('//h1') as $n) {
            if (($t = trim(preg_replace('/\s+/u', ' ', $n->textContent))) !== '') {
                $h1[] = mb_substr($t, 0, 150);
            }
        }
        $imgs = $x->query('//img');
        $noAlt = 0;
        foreach ($imgs as $i) {
            if (trim((string) $i->getAttribute('alt')) === '') {
                $noAlt++;
            }
        }
        $words = count(preg_split('/\s+/u', trim(implode(' ', WebFetcher::textLines($html)))) ?: []);
        $desc = $meta('name', 'description');

        return [
            'title' => mb_substr($title, 0, 200), 'title_len' => mb_strlen($title),
            'description' => $desc ? mb_substr($desc, 0, 320) : null, 'description_len' => $desc ? mb_strlen($desc) : 0,
            'h1' => array_slice($h1, 0, 5), 'h1_count' => count($h1), 'h2_count' => $x->query('//h2')->length,
            'words' => $words, 'images' => $imgs->length, 'images_no_alt' => $noAlt,
            'lang' => $x->query('//html/@lang')->item(0)?->nodeValue,
            'viewport' => (bool) $meta('name', 'viewport'),
            'canonical' => (bool) $x->query('//link[@rel="canonical"]')->length,
            'open_graph' => (bool) $meta('property', 'og:title') || (bool) $meta('property', 'og:image'),
            'structured_data' => (bool) $x->query('//script[@type="application/ld+json"]')->length,
            'keywords' => $this->topWords(WebFetcher::textLines($html)),
        ];
    }

    /** Mots les plus présents sur la page (hors mots courants) : les thèmes que le concurrent met en avant. */
    protected function topWords(array $lines): array
    {
        static $stop = null;
        $stop ??= array_flip(explode(' ', 'les des une pour que qui dans sur avec par est vos nos son ses aux plus pas vous nous leur votre notre tout tous toute toutes cette ces ceux être avoir fait faire mais comme ainsi entre sans sous chez très bien aussi elle ils elles the and for you your with our are from this that www http https com accueil contact menu voir lire suite cookies cookie politique confidentialité mentions légales droits réservés copyright newsletter inscrire connexion panier rechercher'));
        $count = [];
        foreach ($lines as $l) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($l)) ?: [] as $w) {
                if (mb_strlen($w) >= 4 && ! isset($stop[$w]) && ! ctype_digit($w)) {
                    $count[$w] = ($count[$w] ?? 0) + 1;
                }
            }
        }
        arsort($count);

        return array_slice(array_keys(array_filter($count, fn ($n) => $n >= 2)), 0, 12);
    }

    /** Note de référencement de base sur 100 et points à améliorer (ce que le concurrent fait mal, ou bien). */
    protected function score(array $a): array
    {
        $checks = [
            [$a['https'], 10, 'Site sans HTTPS (cadenas).'],
            [$a['title_len'] >= 20 && $a['title_len'] <= 65, 10, $a['title_len'] ? 'Titre de page de '.$a['title_len'].' caractères (idéal 20 à 65).' : 'Pas de titre de page.'],
            [$a['description_len'] >= 70 && $a['description_len'] <= 170, 10, $a['description_len'] ? 'Description de '.$a['description_len'].' caractères (idéal 70 à 170).' : 'Pas de description pour Google.'],
            [$a['h1_count'] === 1, 10, $a['h1_count'] ? $a['h1_count'].' titres principaux (H1) au lieu d\'un seul.' : 'Pas de titre principal (H1).'],
            [$a['viewport'], 10, 'Page non adaptée aux téléphones (pas de balise viewport).'],
            [$a['words'] >= 300, 10, 'Peu de texte sur l\'accueil ('.$a['words'].' mots).'],
            [$a['images'] === 0 || $a['images_no_alt'] / max(1, $a['images']) <= 0.2, 5, $a['images_no_alt'].' image(s) sur '.$a['images'].' sans texte alternatif.'],
            [$a['canonical'], 5, 'Pas d\'adresse canonique.'],
            [$a['open_graph'], 5, 'Pas d\'aperçu pour les partages sur les réseaux (Open Graph).'],
            [$a['structured_data'], 5, 'Pas de données structurées (fiche entreprise pour Google).'],
            [$a['sitemap'], 10, 'Pas de plan du site (sitemap.xml).'],
            [$a['robots'], 5, 'Pas de fichier robots.txt.'],
            [$a['ms'] <= 3000, 5, 'Page lente : '.round($a['ms'] / 1000, 1).' s pour répondre.'],
        ];
        $score = 0;
        $issues = [];
        foreach ($checks as [$ok, $pts, $msg]) {
            if ($ok) {
                $score += $pts;
            } else {
                $issues[] = $msg;
            }
        }

        return [$score, $issues];
    }

    public static function toolsText(array $a): ?string
    {
        $lines = [];
        foreach (self::CATEGORY_LABELS as $k => $label) {
            if (! empty($a[$k])) {
                $lines[] = $label.' : '.implode(', ', $a[$k]);
            }
        }

        return $lines ? implode("\n", $lines) : 'Aucun outil marketing détecté sur la page d\'accueil.';
    }

    public static function summary(array $a, ?string $at = null): string
    {
        if (($a['status'] ?? 200) >= 400) {
            return 'La page d\'accueil répond avec l\'erreur '.$a['status'].($at ? ' (analyse du '.date('d/m/Y', strtotime($at)).')' : '').'.';
        }
        $l = [];
        $l[] = 'Référencement : '.($a['seo_score'] ?? '—').'/100 · réponse en '.round(($a['ms'] ?? 0) / 1000, 1).' s · '.($a['kb'] ?? 0).' Ko'.($at ? ' (analyse du '.date('d/m/Y', strtotime($at)).')' : '');
        if (! empty($a['title'])) {
            $l[] = 'Titre : '.$a['title'];
        }
        if (! empty($a['description'])) {
            $l[] = 'Description : '.$a['description'];
        }
        if (! empty($a['h1'])) {
            $l[] = 'Message principal (H1) : '.implode(' | ', $a['h1']);
        }
        if (! empty($a['keywords'])) {
            $l[] = 'Thèmes mis en avant : '.implode(', ', $a['keywords']);
        }
        if (! empty($a['socials'])) {
            $l[] = 'Réseaux reliés : '.implode(', ', array_map(fn ($k) => ['x_account' => 'X'][$k] ?? ucfirst($k), array_keys($a['socials'])));
        }
        if (! empty($a['issues'])) {
            $l[] = 'Points faibles : '.implode(' ', $a['issues']);
        }

        return implode("\n", $l);
    }
}
