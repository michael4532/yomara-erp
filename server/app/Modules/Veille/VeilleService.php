<?php

namespace App\Modules\Veille;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tâches automatiques de la veille : sites surveillés, mots-clés (presse et web), analyse des sites et synthèse hebdomadaire. */
class VeilleService
{
    public function __construct(protected VeilleHooks $h) {}

    protected function recipients(int $cid, ?int $owner): array
    {
        $to = $this->h->managers($cid);
        if ($owner && ! in_array($owner, $to, true)) {
            $to[] = $owner;
        }

        return $to;
    }

    // ------------------------------------------------------------------ pages surveillées

    /** Visite une page surveillée : au premier passage, mémorise le contenu ; ensuite, signale ce qui a été ajouté ou retiré. */
    public function check(Company $c, object $w): array
    {
        $now = now();
        try {
            $page = WebFetcher::get($w->url);
            if ($page['status'] >= 400) {
                throw new \DomainException('la page répond avec l\'erreur '.$page['status']);
            }
        } catch (\DomainException $e) {
            DB::table('vc_watches')->where('id', $w->id)->update(['last_checked_at' => $now, 'last_error' => mb_substr(rtrim($e->getMessage(), '.'), 0, 250), 'updated_at' => $now]);

            return ['changed' => false, 'error' => $e->getMessage()];
        }
        $lines = WebFetcher::textLines($page['body']);
        $text = implode("\n", $lines);
        if (mb_strlen($text) > 200000) {
            $text = mb_substr($text, 0, 200000);
            $lines = explode("\n", $text);
        }
        $hash = hash('sha256', $text);
        $base = ['last_checked_at' => $now, 'last_error' => null, 'updated_at' => $now];
        if (! $w->last_hash || $w->last_text === null) {
            DB::table('vc_watches')->where('id', $w->id)->update($base + ['last_hash' => $hash, 'last_text' => $text]);

            return ['changed' => false, 'first' => true];
        }
        if ($hash === $w->last_hash) {
            DB::table('vc_watches')->where('id', $w->id)->update($base);

            return ['changed' => false];
        }
        $old = explode("\n", (string) $w->last_text);
        $added = array_values(array_diff($lines, $old));
        $removed = array_values(array_diff($old, $lines));
        $keywords = array_filter(array_map(fn ($k) => mb_strtolower(trim($k)), explode(',', (string) $w->keywords)));
        $relevant = ! $keywords || collect(array_merge($added, $removed))->contains(fn ($l) => collect($keywords)->contains(fn ($k) => str_contains(mb_strtolower($l), $k)));
        if (! $added && ! $removed) { // seul l'ordre a changé
            DB::table('vc_watches')->where('id', $w->id)->update($base + ['last_hash' => $hash, 'last_text' => $text]);

            return ['changed' => false];
        }
        $summary = $this->diffSummary($added, $removed);
        $update = $base + ['last_hash' => $hash, 'last_text' => $text];
        if ($relevant) {
            $update += ['last_changed_at' => $now, 'last_change' => $summary, 'changes' => DB::raw('changes + 1')];
        }
        DB::table('vc_watches')->where('id', $w->id)->update($update);
        if (! $relevant) {
            return ['changed' => false, 'ignored' => true];
        }
        $name = $w->competitor_id ? DB::table('vc_competitors')->where('id', $w->competitor_id)->value('title') : null;
        $obsId = DB::table('vc_observations')->insertGetId([
            'company_id' => $c->id, 'owner_id' => $w->owner_id, 'created_by' => $w->owner_id, 'competitor_id' => $w->competitor_id,
            'title' => mb_substr('Changement sur « '.$w->title.' »'.($name ? ' ('.$name.')' : ''), 0, 250), 'observed_on' => $now->toDateString(),
            'kind' => $w->kind === 'jobs' ? 'hiring' : ($w->kind === 'prices' || $w->kind === 'promo' ? 'price' : 'website'), 'channel' => 'website',
            'impact' => in_array($w->kind, ['prices', 'promo'], true) ? 'high' : 'medium', 'status' => 'new', 'source' => 'website',
            'details' => $summary, 'source_url' => mb_substr($w->url, 0, 500), 'created_at' => $now, 'updated_at' => $now,
        ]);
        AppNotification::send($c->id, $this->recipients($c->id, $w->owner_id), 'veille', 'Site concurrent modifié : '.Str::limit($w->title, 70),
            Str::limit(str_replace("\n", ' · ', $summary), 220), 'veille', (string) $obsId);

        return ['changed' => true, 'summary' => $summary, 'observation_id' => $obsId];
    }

    protected function diffSummary(array $added, array $removed): string
    {
        $fmt = fn (array $l) => implode("\n", array_map(fn ($x) => '• '.Str::limit($x, 160), array_slice($l, 0, 8))).(count($l) > 8 ? "\n… et ".(count($l) - 8).' autre(s)' : '');
        $out = [];
        if ($added) {
            $out[] = 'Ajouté ('.count($added).") :\n".$fmt($added);
        }
        if ($removed) {
            $out[] = 'Retiré ('.count($removed).") :\n".$fmt($removed);
        }

        return implode("\n\n", $out);
    }

    // ------------------------------------------------------------------ mots-clés : presse et web

    public static function googleNewsUrl(string $q, string $country = 'CI'): string
    {
        $country = preg_match('/^[A-Z]{2}$/', $country) ? $country : 'CI';

        return 'https://news.google.com/rss/search?q='.rawurlencode($q.' when:30d').'&hl=fr&gl='.$country.'&ceid='.$country.':fr';
    }

    /** Recherche les nouveaux articles d'un mot-clé (Google Actualités et flux RSS) et les verse dans « Mentions ». */
    public function fetchAlert(Company $c, object $a): array
    {
        $feeds = [];
        if ($a->google_news) {
            $feeds[] = static::googleNewsUrl($a->title, strtoupper((string) ($c->country ?: 'CI')));
        }
        if ($a->feed_url) {
            $feeds[] = $a->feed_url;
        }
        $first = $a->last_checked_at === null;
        $new = 0;
        $errors = [];
        foreach ($feeds as $url) {
            try {
                $r = WebFetcher::get($url, 20, ['Accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.5']);
                if ($r['status'] >= 400) {
                    throw new \DomainException('le flux répond avec l\'erreur '.$r['status']);
                }
                foreach (array_slice(static::parseFeed($r['body']), 0, 40) as $it) {
                    if ($it['published_at'] && Carbon::parse($it['published_at'])->lt(now()->subDays(60))) {
                        continue;
                    }
                    $hash = sha1($it['url']);
                    $ok = DB::table('vc_mentions')->insertOrIgnore([
                        'company_id' => $c->id, 'alert_id' => $a->id, 'competitor_id' => $a->competitor_id, 'owner_id' => $a->owner_id,
                        'title' => mb_substr($it['title'], 0, 300), 'url' => mb_substr($it['url'], 0, 1000), 'url_hash' => $hash, 'source_name' => $it['source'] ? mb_substr($it['source'], 0, 150) : null,
                        'published_at' => $it['published_at'], 'status' => $first ? 'seen' : 'new', 'summary' => $it['summary'] ? mb_substr($it['summary'], 0, 1000) : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $new += $ok;
                }
            } catch (\DomainException $e) {
                $errors[] = $e->getMessage();
            }
        }
        DB::table('vc_alerts')->where('id', $a->id)->update(['last_checked_at' => now(), 'last_error' => $errors ? mb_substr(implode(' ', $errors), 0, 250) : null, 'updated_at' => now()]);
        if ($new && ! $first) {
            AppNotification::send($c->id, $this->recipients($c->id, $a->owner_id), 'veille', $new.' nouvelle(s) mention(s) : '.Str::limit($a->title, 60),
                'Articles et pages web trouvés pour ce mot-clé, à lire dans Veille › Mentions.', 'veille', 'mentions');
        }

        return ['new' => $new, 'errors' => $errors, 'first' => $first];
    }

    /** Articles d'un flux RSS 2.0 ou Atom. */
    public static function parseFeed(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $doc) {
            throw new \DomainException('le flux n\'est pas un flux RSS lisible');
        }
        $items = [];
        $clean = fn ($s) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $date = fn ($s) => ($s = trim((string) $s)) !== '' && ($t = strtotime($s)) ? date('Y-m-d H:i:s', $t) : null;
        if (isset($doc->channel->item)) {
            foreach ($doc->channel->item as $i) {
                $title = $clean($i->title);
                $source = isset($i->source) ? $clean($i->source) : null;
                if ($source && str_ends_with($title, ' - '.$source)) {
                    $title = mb_substr($title, 0, -mb_strlen(' - '.$source));
                }
                $items[] = ['title' => $title, 'url' => trim((string) $i->link), 'source' => $source ?: (parse_url(trim((string) $i->link), PHP_URL_HOST) ?: null),
                    'published_at' => $date($i->pubDate), 'summary' => $source ? null : (Str::limit($clean($i->description), 600) ?: null)];
            }
        } else {
            $doc->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
            foreach ($doc->xpath('//a:entry') ?: ($doc->entry ?? []) as $e) {
                $link = '';
                foreach ($e->link as $l) {
                    if (! isset($l['rel']) || (string) $l['rel'] === 'alternate') {
                        $link = (string) $l['href'];
                        break;
                    }
                }
                $u = parse_url($link);
                if (($u['host'] ?? '') === 'www.google.com' && ($u['path'] ?? '') === '/url') { // alertes Google : lien de redirection
                    parse_str($u['query'] ?? '', $qs);
                    $link = $qs['url'] ?? $link;
                }
                $items[] = ['title' => $clean($e->title), 'url' => $link, 'source' => parse_url($link, PHP_URL_HOST) ?: null,
                    'published_at' => $date($e->published ?: $e->updated), 'summary' => Str::limit($clean($e->content ?: $e->summary), 600) ?: null];
            }
        }

        return array_values(array_filter($items, fn ($i) => $i['title'] !== '' && preg_match('#^https?://#i', $i['url'])));
    }

    // ------------------------------------------------------------------ analyse des sites

    public function audit(Company $c, int $competitorId): array
    {
        $comp = DB::table('vc_competitors')->where('company_id', $c->id)->where('id', $competitorId)->first() ?: throw new \DomainException('Concurrent introuvable.');
        if (! $comp->website) {
            throw new \DomainException('Indiquez d\'abord l\'adresse de son site web dans la fiche.');
        }
        $a = app(SiteAnalyzer::class)->analyze($comp->website);
        $upd = ['site_audit' => json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'audit_at' => now(), 'updated_at' => now()];
        foreach ($a['socials'] ?? [] as $k => $url) { // complète les réseaux encore vides
            if (empty($comp->{$k})) {
                $upd[$k] = mb_substr($url, 0, 500);
            }
        }
        DB::table('vc_competitors')->where('id', $comp->id)->update($upd);

        return $a;
    }

    // ------------------------------------------------------------------ passage automatique

    public function run(Company $c): array
    {
        $n = ['watches' => 0, 'changes' => 0, 'alerts' => 0, 'mentions' => 0, 'audits' => 0, 'digest' => 0];
        $due = DB::table('vc_watches')->where('company_id', $c->id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhere(fn ($w) => $w->where('frequency', 'daily')->where('last_checked_at', '<=', now()->subHours(20)))
                ->orWhere(fn ($w) => $w->where('frequency', 'weekly')->where('last_checked_at', '<=', now()->subDays(6)->subHours(12))))
            ->orderBy('last_checked_at')->limit(30)->get();
        foreach ($due as $w) {
            $r = $this->check($c, $w);
            $n['watches']++;
            $n['changes'] += (int) ! empty($r['changed']);
        }
        foreach (DB::table('vc_alerts')->where('company_id', $c->id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subHours(6)))->orderBy('last_checked_at')->limit(20)->get() as $a) {
            $n['mentions'] += $this->fetchAlert($c, $a)['new'];
            $n['alerts']++;
        }
        // Analyse des sites refaite chaque mois (outils publicitaires ajoutés ou retirés).
        foreach (DB::table('vc_competitors')->where('company_id', $c->id)->where('status', 'watch')->whereNotNull('website')
            ->where(fn ($q) => $q->whereNull('audit_at')->orWhere('audit_at', '<=', now()->subDays(30)))->limit(3)->get(['id', 'site_audit']) as $comp) {
            $before = json_decode((string) $comp->site_audit, true) ?: null;
            try {
                $after = $this->audit($c, $comp->id);
                $n['audits']++;
                $this->toolsChanged($c, $comp->id, $before, $after);
            } catch (\Throwable) {
                DB::table('vc_competitors')->where('id', $comp->id)->update(['audit_at' => now()]);
            }
        }
        if (now()->isMonday() && now()->hour >= 7 && Cache::add('veille-digest:'.$c->id.':'.now()->format('o-W'), 1, now()->addDays(8))) {
            $n['digest'] = (int) $this->digest($c);
        }

        return $n;
    }

    /** Un concurrent qui ajoute un pixel publicitaire ou un outil d'e-mailing prépare souvent une campagne : on le signale. */
    protected function toolsChanged(Company $c, int $competitorId, ?array $before, array $after): void
    {
        if (! $before || ($after['status'] ?? 200) >= 400) {
            return;
        }
        $old = array_merge($before['ads'] ?? [], $before['marketing'] ?? [], $before['analytics'] ?? []);
        $new = array_merge($after['ads'] ?? [], $after['marketing'] ?? [], $after['analytics'] ?? []);
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        if (! $added && ! $removed) {
            return;
        }
        $comp = DB::table('vc_competitors')->where('id', $competitorId)->first();
        $details = trim(($added ? 'Nouveaux outils : '.implode(', ', $added).".\n" : '').($removed ? 'Outils retirés : '.implode(', ', $removed).'.' : ''));
        $id = DB::table('vc_observations')->insertGetId(['company_id' => $c->id, 'owner_id' => $comp->owner_id, 'created_by' => $comp->owner_id, 'competitor_id' => $competitorId,
            'title' => mb_substr($comp->title.' : outils marketing modifiés sur son site', 0, 250), 'observed_on' => now()->toDateString(), 'kind' => 'website', 'channel' => 'website',
            'impact' => $added ? 'medium' : 'low', 'status' => 'new', 'source' => 'website', 'details' => $details, 'source_url' => $comp->website, 'created_at' => now(), 'updated_at' => now()]);
        AppNotification::send($c->id, $this->recipients($c->id, $comp->owner_id), 'veille', Str::limit($comp->title, 60).' change ses outils marketing', $details, 'veille', (string) $id);
    }

    /** Synthèse de la semaine pour les responsables de la veille. */
    public function digest(Company $c): bool
    {
        $since = now()->subDays(7);
        $obs = DB::table('vc_observations')->where('company_id', $c->id)->where('created_at', '>=', $since);
        $total = (clone $obs)->count();
        $prices = DB::table('vc_prices')->where('company_id', $c->id)->where('created_at', '>=', $since)->count();
        $ads = DB::table('vc_ads')->where('company_id', $c->id)->where('created_at', '>=', $since)->count();
        $mentions = DB::table('vc_mentions')->where('company_id', $c->id)->where('created_at', '>=', $since)->where('status', '!=', 'seen')->count();
        if (! $total && ! $prices && ! $ads && ! $mentions) {
            return false;
        }
        $high = (clone $obs)->where('impact', 'high')->orderByDesc('id')->limit(3)->pluck('title')->all();
        $body = collect([
            $total ? $total.' observation(s)' : null, $prices ? $prices.' relevé(s) de prix' : null, $ads ? $ads.' publicité(s) repérée(s)' : null, $mentions ? $mentions.' mention(s) dans la presse et sur le web' : null,
        ])->filter()->implode(', ').'.'.($high ? ' À fort impact : '.implode(' ; ', array_map(fn ($t) => Str::limit($t, 70), $high)).'.' : '');
        AppNotification::send($c->id, $this->h->managers($c->id), 'veille', 'Veille concurrentielle : la semaine en bref', $body, 'veille', 'digest');

        return true;
    }

    // ------------------------------------------------------------------ IA

    /** Données récentes d'un concurrent (ou de tous) mises en forme pour l'assistant IA. */
    public function context(Company $c, ?int $competitorId = null, int $days = 60): string
    {
        $comps = DB::table('vc_competitors')->where('company_id', $c->id)->where('status', '!=', 'archived')->when($competitorId, fn ($q) => $q->where('id', $competitorId))->get();
        if ($competitorId && $comps->isEmpty()) {
            throw new \DomainException('Concurrent introuvable.');
        }
        $since = now()->subDays($days)->toDateString();
        $out = [];
        $idx = $this->h->priceIndexes($c->id);
        foreach ($comps as $x) {
            $l = ['## '.$x->title.' — '.(VeilleHooks::KINDS[$x->kind][0] ?? $x->kind).', menace '.mb_strtolower(VeilleHooks::LEVELS[$x->threat][0] ?? $x->threat)
                .($x->positioning ? ', '.mb_strtolower(VeilleHooks::POSITIONING[$x->positioning]) : '').($x->city ? ', '.$x->city : '')];
            $scores = collect(VeilleHooks::SCORE_FIELDS)->map(fn ($lab, $k) => $x->{$k} ? $lab.' '.$x->{$k}.'/5' : null)->filter()->implode(', ');
            foreach (['Notes' => $scores, 'Indice de prix (100 = nos prix)' => $idx[$x->id] ?? null, 'Note Google' => $x->rating ? $x->rating.'/5 ('.$x->reviews_count.' avis)' : null,
                'Offre' => $x->offer, 'Forces' => $x->strengths, 'Faiblesses' => $x->weaknesses, 'Opportunités' => $x->opportunities, 'Menaces' => $x->threats,
                'Fiche de combat actuelle' => $x->battle_card] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $l[] = $k.' : '.Str::limit((string) $v, 1200);
                }
            }
            if ($a = json_decode((string) $x->site_audit, true)) {
                $l[] = 'Site : '.str_replace("\n", ' ; ', SiteAnalyzer::summary($a)).' ; '.str_replace("\n", ' ; ', (string) SiteAnalyzer::toolsText($a));
            }
            foreach (DB::table('vc_observations')->where('company_id', $c->id)->where('competitor_id', $x->id)->where('observed_on', '>=', $since)->orderByDesc('observed_on')->limit(25)->get() as $o) {
                $l[] = '- '.$o->observed_on.' ['.(VeilleHooks::OBS_KINDS[$o->kind] ?? $o->kind).', impact '.(VeilleHooks::LEVELS[$o->impact][0] ?? '').'] '.$o->title.($o->details ? ' : '.Str::limit(str_replace("\n", ' ', $o->details), 300) : '');
            }
            foreach (DB::table('vc_prices')->where('company_id', $c->id)->where('competitor_id', $x->id)->where('observed_on', '>=', $since)->orderByDesc('observed_on')->limit(20)->get() as $p) {
                $l[] = '- Prix '.$p->observed_on.' : '.$p->title.' '.number_format((float) $p->competitor_price, 0, ',', ' ').($p->promo ? ' (promo)' : '').($p->our_price ? ' contre '.number_format((float) $p->our_price, 0, ',', ' ').' chez nous' : '');
            }
            foreach (DB::table('vc_ads')->where('company_id', $c->id)->where('competitor_id', $x->id)->where(fn ($q) => $q->where('active', true)->orWhere('started_on', '>=', $since))->limit(15)->get() as $ad) {
                $l[] = '- Publicité '.(VeilleHooks::PLATFORMS[$ad->platform] ?? $ad->platform).' : « '.$ad->title.' »'.($ad->offer ? ', offre : '.$ad->offer : '').($ad->cta ? ', bouton : '.$ad->cta : '');
            }
            foreach (DB::table('vc_mentions')->where('company_id', $c->id)->where('competitor_id', $x->id)->where('status', '!=', 'ignored')->where('published_at', '>=', $since)->orderByDesc('published_at')->limit(10)->get() as $m) {
                $l[] = '- Presse '.substr((string) $m->published_at, 0, 10).' ('.$m->source_name.') : '.$m->title;
            }
            $out[] = implode("\n", $l);
        }
        $ctx = implode("\n\n", $out);

        return mb_strlen($ctx) > 24000 ? mb_substr($ctx, 0, 24000)."\n(…)" : $ctx;
    }

    public function aiBattleCard(Company $c, User $u, int $competitorId): string
    {
        $ctx = $this->context($c, $competitorId, 180);
        $us = DB::table('vc_competitors')->where('company_id', $c->id)->where('kind', 'us')->first();
        $prompt = "Notre entreprise : {$c->name}".($us ? ".\nNotre fiche :\n".$this->context($c, $us->id, 30) : '.')."\n\nDonnées de veille sur le concurrent :\n".$ctx."\n\n"
            .'Rédige la fiche de combat que nos commerciaux liront avant un rendez-vous où ce concurrent est en face. Sections courtes : '
            .'1) En une phrase : qui il est et qui il cible. 2) Où nous gagnons (3 à 5 arguments concrets). 3) Où il est plus fort et comment répondre. '
            .'4) Ses objections ou arguments habituels contre nous, avec notre réponse. 5) Questions à poser au client pour faire ressortir nos points forts. 6) Pièges à éviter. '
            .'Appuie-toi uniquement sur les données fournies ; si une information manque, ne l\'invente pas. Français, texte simple sans markdown lourd, 250 mots maximum.';

        return app(\App\Modules\Ia\AiService::class)->complete($c, $u, 'Tu es un analyste en intelligence économique et en stratégie commerciale pour des entreprises d\'Afrique de l\'Ouest.', $prompt, 'veille_battle_card', 1200);
    }

    public function aiAnalysis(Company $c, User $u, ?int $competitorId = null): string
    {
        $ctx = $this->context($c, $competitorId, 45);
        if (trim($ctx) === '') {
            throw new \DomainException('Pas encore de données de veille à analyser : ajoutez des concurrents et des observations.');
        }
        $prompt = "Entreprise : {$c->name}.\nDonnées de veille des 45 derniers jours :\n".$ctx."\n\n"
            .'Fais la synthèse pour la direction : 1) Ce qui a bougé chez les concurrents (prix, offres, campagnes, communication). 2) Les signaux à surveiller. '
            .'3) Trois à cinq actions concrètes à mener cette semaine (marketing, prix, commerciaux), par ordre de priorité. '
            .'Base-toi seulement sur ces données, cite le concurrent concerné, ne donne pas de chiffre absent des données. Français, 300 mots maximum, listes courtes.';

        return app(\App\Modules\Ia\AiService::class)->complete($c, $u, 'Tu es un analyste en veille concurrentielle et en marketing pour des entreprises d\'Afrique de l\'Ouest.', $prompt, 'veille_analysis', 1400);
    }
}
