<?php

namespace App\Modules\Mailbox;

use App\Modules\Mailbox\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Lecture « intelligente » d'un e-mail, comme Outlook : dates et heures citées (pour proposer une réunion ou un
 * webinaire à ce moment-là), invitation de calendrier jointe (.ics), lien Teams / Meet / Zoom, et nature de la
 * demande (devis, réunion, assistance, appel de fonds, opportunité) pour proposer de créer le bon élément.
 */
class MailInsights
{
    public const MONTHS = ['janvier' => 1, 'janv' => 1, 'jan' => 1, 'fevrier' => 2, 'fevr' => 2, 'fev' => 2, 'mars' => 3, 'avril' => 4, 'avr' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'juil' => 7, 'aout' => 8, 'septembre' => 9, 'sept' => 9, 'sep' => 9, 'octobre' => 10, 'oct' => 10, 'novembre' => 11, 'nov' => 11, 'decembre' => 12, 'dec' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12];

    public const DAYS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7];

    public const INTENTS = [
        'quote' => ['devis', 'cotation', 'proforma', 'pro forma', 'offre de prix', 'offre commerciale', 'tarif', 'liste de prix', 'quotation', 'rfq', 'appel d offres', 'bon de commande', 'commande'],
        'meeting' => ['reunion', 'rendez-vous', 'rendez vous', 'rdv', 'meeting', 'call', 'visio', 'teams', 'google meet', 'zoom', 'webinaire', 'webinar', 'entretien', 'disponibilit', 'creneau'],
        'ticket' => ['panne', 'probleme', 'bug', 'erreur', 'ne fonctionne', 'ne marche', 'reclamation', 'assistance', 'support', 'depannage', 'incident', 'plainte', 'sav'],
        'funds' => ['demande de fonds', 'appel de fonds', 'decaissement', 'avance de fonds', 'avance sur', 'besoin de financement', 'mise a disposition', 'caisse de', 'budget'],
        'lead' => ['partenariat', 'collaboration', 'interesse par', 'interessee par', 'presentation de', 'prospect', 'proposition'],
    ];

    public static function analyze(Message $m, ?Carbon $now = null): array
    {
        $now ??= now(config('erp.defaults.timezone', 'UTC'));
        $text = trim(($m->subject ?? '')."\n".self::plain($m));
        $norm = self::norm($text);
        $intents = [];
        foreach (self::INTENTS as $k => $words) {
            $score = 0;
            foreach ($words as $w) {
                $score += substr_count($norm, $w) * (str_contains(self::norm((string) $m->subject), $w) ? 3 : 1);
            }
            if ($score > 0) {
                $intents[$k] = $score;
            }
        }
        arsort($intents);
        $link = self::meetingLink($text);
        $invite = self::invite($m);
        if ($invite || $link) {
            $intents = ['meeting' => 99] + $intents;
        }

        return ['dates' => self::dates($text, $now), 'intents' => array_keys($intents), 'meeting_link' => $link, 'invite' => $invite, 'amount' => self::amount($text)];
    }

    public static function plain(Message $m): string
    {
        $t = $m->body_text;
        if (! $t && $m->body_html) {
            $t = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/tr)[^>]*>/i', "\n", $m->body_html)), ENT_QUOTES, 'UTF-8');
        }
        $t = preg_replace('/BEGIN:VCALENDAR.*?(END:VCALENDAR|$)/s', '', (string) $t);
        // Le message cité (réponse précédente) n'est pas analysé
        $t = preg_split('/\n(?:>|Le .{5,80} a écrit|On .{5,80} wrote|De\s?: |From: |-----Original)/u', (string) $t)[0];

        return mb_substr((string) $t, 0, 20000);
    }

    public static function norm(string $s): string
    {
        return mb_strtolower(str_replace(["'", '’'], ' ', Str::ascii($s)));
    }

    /** Dates et heures citées, dans le futur proche (au plus 8), avec le texte d'origine. */
    public static function dates(string $text, Carbon $now): array
    {
        $tz = $now->getTimezone();
        $n = self::norm($text);
        $found = [];
        $time = '(?:\s*(?:,|a|des|vers|de)?\s*(\d{1,2})\s*(?:h|:|heures?)\s*(\d{2})?)?';
        $add = function (int $pos, int $len, ?Carbon $d, $h, $min) use (&$found, $text, $n, $now) {
            if (! $d) {
                return;
            }
            $hasTime = $h !== null && $h !== '' && (int) $h < 24;
            if ($hasTime) {
                $d->setTime((int) $h, (int) ($min ?: 0));
            } else {
                $d->setTime(9, 0);
            }
            if ($d->lt($now->copy()->startOfDay())) {
                return;
            }
            $key = $d->format('Y-m-d H:i');
            if (isset($found[$key])) {
                return;
            }
            $found[$key] = ['text' => trim(mb_substr($text, $pos, $len)), 'start' => $d->toIso8601String(), 'has_time' => $hasTime, 'pos' => $pos];
        };
        $year = function (?string $y, int $mo, int $day) use ($now): int {
            if ($y) {
                return strlen($y) === 2 ? 2000 + (int) $y : (int) $y;
            }
            $c = Carbon::create($now->year, $mo, min($day, 28));

            return $c->lt($now->copy()->subMonth()) ? $now->year + 1 : $now->year;
        };
        // 03/11/2026 à 14h30 · 3/11 · 03-11-2026
        if (preg_match_all('/\b(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?\b'.$time.'/u', $n, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                [$day, $mo] = [(int) $x[1][0], (int) $x[2][0]];
                if ($day < 1 || $day > 31 || $mo < 1 || $mo > 12) {
                    continue;
                }
                $y = $year($x[3][0] ?? null ?: null, $mo, $day);
                $add($x[0][1], strlen($x[0][0]), rescue(fn () => Carbon::create($y, $mo, $day, 0, 0, 0, $tz), null, false), $x[4][0] ?? null, $x[5][0] ?? null);
            }
        }
        // (lundi) (le) 3 novembre (2026) (à 10h)
        $months = implode('|', array_keys(self::MONTHS));
        if (preg_match_all('/\b(?:(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+)?(?:le\s+)?(\d{1,2})(?:er)?\s+('.$months.')\.?(?:\s+(\d{4}))?'.$time.'/u', $n, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                $day = (int) $x[2][0];
                $mo = self::MONTHS[$x[3][0]];
                $y = $year(($x[4][0] ?? '') ?: null, $mo, $day);
                $add($x[0][1], strlen($x[0][0]), rescue(fn () => Carbon::create($y, $mo, $day, 0, 0, 0, $tz), null, false), $x[5][0] ?? null, $x[6][0] ?? null);
            }
        }
        // demain / après-demain / aujourd'hui (à 15h)
        if (preg_match_all('/\b(aujourd hui|apres-demain|apres demain|demain)'.$time.'/u', $n, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                $off = str_starts_with($x[1][0], 'apres') ? 2 : ($x[1][0] === 'demain' ? 1 : 0);
                if ($off === 0 && ($x[2][0] ?? '') === '') {
                    continue;
                }
                $add($x[0][1], strlen($x[0][0]), $now->copy()->startOfDay()->addDays($off), $x[2][0] ?? null, $x[3][0] ?? null);
            }
        }
        // lundi (prochain) à 10h — jour seul, sans date
        if (preg_match_all('/\b(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)(\s+prochain)?(?!\s+(?:le\s+)?\d)'.$time.'/u', $n, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                if (($x[3][0] ?? '') === '' && empty($x[2][0])) {
                    continue; // « lundi » isolé sans heure : trop vague
                }
                $d = $now->copy()->startOfDay()->next(self::DAYS[$x[1][0]] % 7);
                $add($x[0][1], strlen($x[0][0]), $d, $x[3][0] ?? null, $x[4][0] ?? null);
            }
        }
        usort($found, fn ($a, $b) => $a['pos'] <=> $b['pos']);

        return array_slice(array_map(fn ($f) => array_diff_key($f, ['pos' => 1]), array_values($found)), 0, 8);
    }

    public static function meetingLink(string $text): ?array
    {
        foreach (['teams' => '~https://teams\.(?:microsoft|live)\.com/[^\s<>"\')]+~i', 'meet' => '~https://meet\.google\.com/[a-z0-9-]+~i', 'zoom' => '~https://[\w.]*zoom\.us/j/[^\s<>"\')]+~i'] as $k => $re) {
            if (preg_match($re, $text, $m)) {
                return ['provider' => $k, 'url' => rtrim($m[0], '.,;')];
            }
        }

        return null;
    }

    /** Montant cité (pour un appel de fonds ou un devis) : « 250 000 F », « 1.500.000 FCFA ». */
    public static function amount(string $text): ?float
    {
        if (preg_match('/(\d{1,3}(?:[ .\x{202F}\x{00A0}]\d{3})+|\d{4,})(?:,\d{1,2})?\s*(?:f\s?cfa|fcfa|xof|francs?|f\b)/iu', $text, $m)) {
            return (float) preg_replace('/\D/', '', $m[1]);
        }

        return null;
    }

    /** Invitation de calendrier jointe (text/calendar) : titre, dates, lieu, organisateur, lien. */
    public static function invite(Message $m): ?array
    {
        $ics = null;
        foreach ((array) $m->attachments as $a) {
            $name = mb_strtolower((string) ($a['name'] ?? ''));
            if (str_contains(mb_strtolower((string) ($a['mime'] ?? '')), 'calendar') || str_ends_with($name, '.ics')) {
                $att = rescue(fn () => app(MailboxService::class)->attachment($m, (string) $a['id']), null, false);
                $ics = $att['content'] ?? null;
                break;
            }
        }
        if (! $ics && $m->body_text && str_contains($m->body_text, 'BEGIN:VEVENT')) {
            $ics = $m->body_text;
        }

        return $ics ? self::parseIcs($ics) : null;
    }

    public static function parseIcs(string $ics): ?array
    {
        $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // lignes repliées
        if (! preg_match('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $ev)) {
            return null;
        }
        $get = function (string $key) use ($ev) {
            return preg_match('/^'.$key.'(;[^:\r\n]*)?:(.*)$/mi', $ev[1], $m) ? [trim($m[1] ?? '', ';'), trim($m[2])] : [null, null];
        };
        $dt = function (string $key) use ($get) {
            [$params, $v] = $get($key);
            if (! $v) {
                return null;
            }
            $tz = preg_match('/TZID=([^;]+)/', (string) $params, $t) ? $t[1] : null;
            try {
                if (strlen($v) === 8) {
                    return Carbon::createFromFormat('Ymd', $v)->startOfDay();
                }
                $z = str_ends_with($v, 'Z');

                return Carbon::createFromFormat('Ymd\THis', rtrim($v, 'Z'), $z ? 'UTC' : ($tz && in_array($tz, timezone_identifiers_list(), true) ? $tz : config('erp.defaults.timezone', 'UTC')));
            } catch (\Throwable) {
                return null;
            }
        };
        $start = $dt('DTSTART');
        if (! $start) {
            return null;
        }
        $end = $dt('DTEND') ?? $start->copy()->addHour();
        $unesc = fn ($s) => $s === null ? null : str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
        [, $org] = $get('ORGANIZER');
        [$orgParams] = $get('ORGANIZER');
        $desc = $unesc($get('DESCRIPTION')[1]);
        $loc = $unesc($get('LOCATION')[1]);

        return ['uid' => $get('UID')[1], 'summary' => $unesc($get('SUMMARY')[1]) ?: 'Réunion', 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(),
            'all_day' => strlen((string) $get('DTSTART')[1]) === 8, 'location' => $loc,
            'organizer' => ['email' => $org ? preg_replace('/^mailto:/i', '', $org) : null, 'name' => preg_match('/CN="?([^";:]+)/', (string) $orgParams, $c) ? $c[1] : null],
            'link' => self::meetingLink(($desc ?? '').' '.($loc ?? '').' '.($get('URL')[1] ?? ''))['url'] ?? null, 'method' => preg_match('/^METHOD:(\w+)/mi', $ics, $mm) ? strtoupper($mm[1]) : null];
    }
}
