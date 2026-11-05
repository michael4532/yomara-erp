<?php

namespace App\Modules\Social;

use App\Models\Company;
use App\Models\User;
use App\Modules\Ia\AiClient;
use App\Modules\Ia\AiService;
use Illuminate\Support\Facades\DB;

/**
 * Textes adaptés à chaque réseau : respect de la limite de caractères (et du titre quand il y en a un),
 * hashtags, idées de publications. Avec l'IA de la société si elle est disponible, sinon par des règles locales.
 */
class TextAdapter
{
    /** Ajuste un texte à la limite du réseau sans couper un mot ni perdre le lien ou les premiers hashtags. */
    public static function fit(string $network, string $text, bool $withMedia = false, ?int $limit = null, ?string $link = null): string
    {
        $d = Networks::get($network);
        $limit ??= Networks::limit($network, $withMedia);
        $text = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n{3,}/u", "\n\n", $text)));
        // Instagram et TikTok : liens non cliquables dans la légende
        if (! $d['links']) {
            $text = trim(preg_replace('~\s*https?://\S+~u', '', $text));
            if ($link && $network === 'instagram') {
                $text .= "\n\n🔗 Lien dans la bio";
            }
        } elseif ($link && ! str_contains($text, $link) && in_array($network, ['x', 'threads', 'mastodon', 'bluesky', 'telegram', 'linkedin', 'facebook', 'sensya'], true)) {
            $text .= "\n".$link;
        }
        // Instagram : 30 hashtags au plus
        if (! empty($d['hashtags'])) {
            $n = 0;
            $max = $d['hashtags'];
            $text = trim(preg_replace('/ {2,}/', ' ', preg_replace_callback('/(?<![\w#])#[\p{L}\p{N}_]+/u', function ($m) use (&$n, $max) {
                return ++$n > $max ? '' : $m[0];
            }, $text)));
        }
        if (Networks::count($network, $text) <= $limit) {
            return $text;
        }
        // 1) Retirer les hashtags en trop, en partant de la fin
        preg_match_all('/(?<![\w#])#[\p{L}\p{N}_]+/u', $text, $tags);
        $tags = $tags[0];
        $body = trim(preg_replace('/\s*(?<![\w#])#[\p{L}\p{N}_]+/u', '', $text));
        $url = null;
        if ($d['links'] && preg_match('~https?://\S+$~u', $body, $um)) {
            $url = $um[0];
            $body = trim(mb_substr($body, 0, mb_strlen($body) - mb_strlen($url)));
        }
        $join = fn (string $b, array $t) => trim($b.($url ? "\n".$url : '').($t ? "\n".implode(' ', $t) : ''));
        while ($tags && Networks::count($network, $join($body, $tags)) > $limit) {
            array_pop($tags);
        }
        if (Networks::count($network, $join($body, $tags)) <= $limit) {
            return $join($body, $tags);
        }
        // 2) Couper le corps à la dernière phrase entière, sinon au dernier mot, avec « … »
        $room = $limit - Networks::count($network, $join('', [])) - ($url ? 1 : 0) - 1;
        $cut = self::cut($body, max(10, $room), $network);

        return $join($cut, []);
    }

    protected static function cut(string $body, int $room, string $network): string
    {
        if (Networks::count($network, $body) <= $room) {
            return $body;
        }
        $slice = mb_substr($body, 0, $room);
        while (Networks::count($network, $slice.'…') > $room && $slice !== '') {
            $slice = mb_substr($slice, 0, -1);
        }
        if (preg_match('/^(.*[.!?…])\s/su', $slice.' ', $m) && mb_strlen($m[1]) >= $room * .5) {
            return trim($m[1]);
        }
        $sp = mb_strrpos($slice, ' ');

        return rtrim(($sp && $sp > $room * .5 ? mb_substr($slice, 0, $sp) : $slice), " ,;:-–").'…';
    }

    /** Titre court (Pinterest, YouTube, TikTok photo) tiré de la première phrase. */
    public static function title(string $text, int $max): string
    {
        $first = trim(preg_split('/(?<=[.!?])\s|\n/u', trim(preg_replace('/(?<![\w#])#[\p{L}\p{N}_]+|https?:\/\/\S+/u', '', $text)))[0] ?? '');

        return self::cut(rtrim($first, '.'), $max, 'facebook');
    }

    /** Hashtags tirés des mots les plus porteurs du texte (sans IA). */
    public static function localHashtags(string $text, int $n = 6): array
    {
        $stop = array_flip(explode(' ', 'avec pour dans vous nous votre notre leurs cette cela comme plus moins tous toutes tout sont être avoir faire chez entre depuis pendant aussi très bien encore jusqu dont quand alors ainsi après avant mais donc nouveau nouvelle découvrez profitez offre'));
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(preg_replace('~https?://\S+|#\S+~u', '', $text)), -1, PREG_SPLIT_NO_EMPTY);
        $freq = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 5 && ! isset($stop[$w]) && ! ctype_digit($w)) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }
        arsort($freq);

        return array_map(fn ($w) => '#'.self::camel($w), array_slice(array_keys($freq), 0, $n));
    }

    protected static function camel(string $w): string
    {
        $w = \Normalizer::normalize($w, \Normalizer::FORM_D) ?: $w;

        return ucfirst(preg_replace('/[^\p{L}\p{N}]/u', '', preg_replace('/\p{Mn}/u', '', $w)));
    }

    // ------------------------------------------------------------------ IA

    protected function ai(Company $c, User $u, string $prompt, string $kind, int $max = 1800): ?string
    {
        $s = app(AiService::class)->settings($c);
        if (! $s['enabled'] || ! $s['configured'] || ($s['monthly_limit'] !== 0 && $s['used'] >= $s['monthly_limit'])) {
            return null;
        }
        try {
            $res = (new AiClient($s['provider'], (string) $s['key'], $s['model'], $s['base_url']))->chat(
                'Tu es le community manager d\'une entreprise d\'Afrique de l\'Ouest. Tu écris des publications naturelles, engageantes, sans fautes, en français sauf demande contraire. '
                .'Tu n\'inventes ni prix, ni date, ni promesse absents de la demande. Tu réponds uniquement avec le JSON demandé.',
                [['role' => 'user', 'content' => $prompt]], [], null, $max);
            DB::table('ia_messages')->insert(['conversation_id' => null, 'company_id' => $c->id, 'user_id' => $u->id, 'role' => 'draft', 'content' => $res['text'],
                'tools' => json_encode(['kind' => 'social_'.$kind]), 'tokens_in' => $res['tokens_in'], 'tokens_out' => $res['tokens_out'], 'created_at' => now(), 'updated_at' => now()]);

            return $res['text'];
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function json(?string $s): ?array
    {
        if (! $s) {
            return null;
        }
        $s = trim(preg_replace('/^```(?:json)?|```$/m', '', $s));
        $a = strpos($s, '{');
        $b = strrpos($s, '}');
        $data = ($a !== false && $b !== false) ? json_decode(substr($s, $a, $b - $a + 1), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Version de la publication pour chaque réseau demandé, dans sa limite de caractères.
     *
     * @return array{variants: array<string, array{text: string, title: ?string, count: int, limit: int}>, ai: bool}
     */
    public function adapt(Company $c, User $u, string $text, array $networks, array $o = []): array
    {
        $networks = array_values(array_intersect($networks, array_keys(Networks::ALL)));
        $o = self::withBrand($c, $o);
        $withMedia = ! empty($o['with_media']);
        $limits = [];
        foreach ($networks as $n) {
            $limits[$n] = Networks::limit($n, $withMedia);
        }
        $ai = null;
        if ($networks && trim($text) !== '') {
            $spec = collect($networks)->map(fn ($n) => '- '.$n.' ('.Networks::label($n).') : '.$limits[$n].' caractères maximum'.(Networks::ALL[$n]['title'] ?? null ? ', plus un "title" de '.Networks::ALL[$n]['title'].' caractères maximum' : '')
                .(Networks::ALL[$n]['links'] ? '' : ', liens non cliquables (n\'en mets pas)').(! empty(Networks::ALL[$n]['hashtags']) ? ', '.Networks::ALL[$n]['hashtags'].' hashtags maximum' : ''))->implode("\n");
            $ai = self::json($this->ai($c, $u, "Adapte cette publication à chaque réseau ci-dessous en respectant STRICTEMENT la limite de caractères (espaces, émojis et hashtags compris ; sur X un lien compte 23 caractères). "
                .'Ton '.($o['tone'] ?? 'professionnel et chaleureux').'. Garde le sens, les informations clés et l\'appel à l\'action ; style propre à chaque réseau (X et Bluesky : court et percutant ; LinkedIn : professionnel, aéré ; Instagram et TikTok : émojis et hashtags en fin ; Google Business : informatif, sans hashtag). '
                .($o['link'] ?? null ? 'Lien à mettre là où il est cliquable : '.$o['link'].'. ' : '')
                ."\nRéseaux :\n".$spec."\n\nRéponds en JSON : {\"<réseau>\": {\"text\": \"…\", \"title\": \"…\"}} (title seulement si demandé).\n\nPublication d'origine :\n".$text, 'adapt', 3000));
        }
        $out = [];
        foreach ($networks as $n) {
            $cand = is_array($ai[$n] ?? null) ? (string) ($ai[$n]['text'] ?? '') : (is_string($ai[$n] ?? null) ? $ai[$n] : '');
            // Garde-fou : le texte de l'IA est ré-ajusté si elle a dépassé la limite
            $t = self::fit($n, $cand !== '' ? $cand : $text, $withMedia, $limits[$n], $cand !== '' ? null : ($o['link'] ?? null));
            $titleMax = Networks::ALL[$n]['title'] ?? null;
            $title = $titleMax ? self::cut(trim((string) ($ai[$n]['title'] ?? '')) ?: self::title($text, $titleMax), $titleMax, 'facebook') : null;
            $out[$n] = ['text' => $t, 'title' => $title, 'count' => Networks::count($n, $t), 'limit' => $limits[$n]];
        }

        return ['variants' => $out, 'ai' => $ai !== null];
    }

    /** Ton, slogan, hashtags et mots à éviter de la charte de marque, ajoutés aux consignes de l'IA. */
    protected static function withBrand(Company $c, array $o): array
    {
        $b = (array) $c->setting('brand', []);
        if (empty($o['tone']) && ! empty($b['tone'])) {
            $o['tone'] = StudioController::TONES[$b['tone']] ?? $b['tone'];
        }
        $extra = array_filter([! empty($b['slogan']) ? 'slogan de la marque : « '.$b['slogan'].' »' : null, ! empty($b['hashtags']) ? 'hashtags de la marque à privilégier : '.$b['hashtags'] : null,
            ! empty($b['words_avoid']) ? 'mots à ne jamais employer : '.$b['words_avoid'] : null]);
        if ($extra) {
            $o['tone'] = ($o['tone'] ?? 'professionnel et chaleureux').' ('.implode(' ; ', $extra).')';
        }

        return $o;
    }

    /** Idées de publications à partir d'un sujet (offre, évènement, conseil…). */
    public function ideas(Company $c, User $u, string $topic, int $n = 5, array $o = []): array
    {
        $n = max(1, min(10, $n));
        $o = self::withBrand($c, $o);
        $data = self::json($this->ai($c, $u, 'Propose '.$n.' publications différentes pour les réseaux sociaux de '.$c->name.' sur ce sujet : « '.$topic.' ». '
            .'Varie les angles (annonce, question au public, coulisses, témoignage, conseil, offre). Chaque texte entre 150 et 600 caractères, avec un appel à l\'action et 3 à 6 hashtags pertinents en fin. '
            .'Ton '.($o['tone'] ?? 'professionnel et chaleureux').'. Réponds en JSON : {"ideas": [{"angle": "…", "text": "…"}]}', 'ideas', 2500));
        $list = collect($data['ideas'] ?? [])->filter(fn ($i) => is_array($i) && trim((string) ($i['text'] ?? '')) !== '')
            ->map(fn ($i) => ['angle' => mb_substr((string) ($i['angle'] ?? 'Idée'), 0, 60), 'text' => trim((string) $i['text'])])->take($n)->values()->all();
        if ($list) {
            return ['ideas' => $list, 'ai' => true];
        }
        // Sans IA : modèles éprouvés à compléter
        $tags = implode(' ', self::localHashtags($topic.' '.$c->name, 4));
        $t = rtrim(trim($topic), '.');
        $local = [
            ['Annonce', "📣 Nouveau chez {$c->name} : {$t} !\n\nPassez nous voir ou écrivez-nous pour en savoir plus. 👇\n\n{$tags}"],
            ['Question au public', "Et vous, qu'attendez-vous de {$t} ? 🤔\nDites-le-nous en commentaire, on lit tout !\n\n{$tags}"],
            ['Coulisses', "Dans les coulisses de {$c->name} : voici comment nous préparons {$t}. 🎬\nUne équipe passionnée, au service de nos clients.\n\n{$tags}"],
            ['Conseil', "💡 Notre conseil du jour sur {$t} :\n[votre astuce ici]\n\nEnregistrez cette publication pour la retrouver !\n\n{$tags}"],
            ['Offre', "🎁 Offre spéciale : {$t}.\nValable jusqu'au [date] chez {$c->name}. Ne la manquez pas !\n\n{$tags}"],
        ];

        return ['ideas' => array_map(fn ($i) => ['angle' => $i[0], 'text' => $i[1]], array_slice($local, 0, $n)), 'ai' => false];
    }

    public function hashtags(Company $c, User $u, string $text, int $n = 8): array
    {
        $data = self::json($this->ai($c, $u, 'Propose '.$n.' hashtags pertinents (mélange de populaires et de précis, en français et locaux si utile) pour cette publication. Réponds en JSON : {"hashtags": ["#…"]}'."\n\n".$text, 'hashtags', 400));
        $tags = collect($data['hashtags'] ?? [])->filter(fn ($h) => is_string($h))->map(fn ($h) => '#'.ltrim(preg_replace('/[^\p{L}\p{N}_#]/u', '', $h), '#'))->filter(fn ($h) => mb_strlen($h) > 2)->unique()->take($n)->values()->all();

        return $tags ? ['hashtags' => $tags, 'ai' => true] : ['hashtags' => self::localHashtags($text, $n), 'ai' => false];
    }
}
