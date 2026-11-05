<?php

namespace App\Modules\Mailbox;

use App\Modules\Mailbox\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Protection de la réputation d'envoi (notre serveur et ceux des clients) avant chaque message :
 * quotas horaires et journaliers (plus stricts pour les adresses hébergées par Yomara et pendant leur rodage),
 * destinataires par message, pièces jointes dangereuses, envoi massif d'un même message, contenu typique des pourriels,
 * suspension automatique après des refus répétés du serveur.
 */
class Antispam
{
    public const BLOCKED_EXT = ['exe', 'scr', 'bat', 'cmd', 'com', 'pif', 'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'msi', 'msp', 'hta', 'cpl', 'jar', 'ps1', 'reg', 'lnk', 'iso', 'img', 'apk', 'dll'];

    public const SHORTENERS = ['bit.ly', 'tinyurl.com', 'goo.gl', 'ow.ly', 't.co', 'is.gd', 'buff.ly', 'cutt.ly', 'rebrand.ly', 'shorturl.at', 'tiny.cc', 'rb.gy'];

    public const SPAM_WORDS = ['viagra', 'casino', 'crypto gratuit', 'bitcoin gratuit', 'gagnez de l\'argent', 'argent facile', 'cliquez ici', 'offre exclusive', '100% gratuit',
        'félicitations vous avez gagné', 'vous avez gagné', 'loterie', 'héritage', 'transfert de fonds', 'western union', 'mot de passe expiré', 'vérifiez votre compte', 'compte suspendu',
        'act now', 'click here', 'free money', 'winner', 'lottery', 'urgent response', 'verify your account', 'limited time offer'];

    public static function limits(Account $a): array
    {
        $hosted = $a->setting('hosted') === true;
        $cfg = config('erp.mailbox.limits.'.($hosted ? 'hosted' : 'own'), []);
        $l = ['per_hour' => (int) ($cfg['per_hour'] ?? ($hosted ? 60 : 200)), 'per_day' => (int) ($cfg['per_day'] ?? ($hosted ? 300 : 1500)), 'recipients' => (int) ($cfg['recipients'] ?? ($hosted ? 30 : 100))];
        // rodage : une adresse hébergée neuve envoie moins pendant 7 jours
        if ($hosted && $a->created_at && $a->created_at->gt(now()->subDays(7))) {
            $l['per_hour'] = max(10, intdiv($l['per_hour'], 3));
            $l['per_day'] = max(30, intdiv($l['per_day'], 3));
        }

        return $l;
    }

    /** Lève une DomainException (message clair) si l'envoi doit être refusé. */
    public static function check(Account $a, array $mail): void
    {
        if ($until = $a->stateValue('send_blocked_until')) {
            if ($until > time()) {
                throw new \DomainException('Envois suspendus jusqu\'à '.date('H:i', $until).' : le serveur a refusé plusieurs messages de suite ('.($a->stateValue('send_block_reason') ?: 'destinataires invalides ou contenu signalé').'). Vérifiez vos destinataires.');
            }
        }
        $l = self::limits($a);
        $rcpt = count($mail['to']) + count($mail['cc']) + count($mail['bcc']);
        if ($rcpt > $l['recipients']) {
            throw new \DomainException("Trop de destinataires ($rcpt, maximum {$l['recipients']} par message) : pour un envoi groupé, utilisez une campagne du module Marketing (désinscription et suivi inclus).");
        }
        foreach ($mail['attachments'] as $x) {
            $ext = strtolower(pathinfo((string) $x['name'], PATHINFO_EXTENSION));
            if (in_array($ext, self::BLOCKED_EXT, true) || preg_match('/\.(exe|scr|js|vbs|bat)\.[a-z0-9]+$/i', (string) $x['name'])) {
                throw new \DomainException('« '.$x['name'].' » : ce type de fichier est bloqué par les messageries (risque de virus). Compressez-le dans un .zip ou partagez un lien.');
            }
        }
        $sent = fn ($q) => $q->where('account_id', $a->id)->whereIn('status', ['sent', 'sending', 'queued']);
        $hour = $sent(DB::table('mb_outbox'))->where('created_at', '>=', now()->subHour())->count();
        $day = $sent(DB::table('mb_outbox'))->where('created_at', '>=', now()->subDay())->count();
        if ($hour >= $l['per_hour']) {
            throw new \DomainException("Limite de {$l['per_hour']} messages par heure atteinte pour {$a->email} : réessayez un peu plus tard. Cette limite protège votre adresse contre le classement en spam.");
        }
        if ($day >= $l['per_day']) {
            throw new \DomainException("Limite de {$l['per_day']} messages par jour atteinte pour {$a->email}. Pour un envoi à grande échelle, utilisez une campagne du module Marketing.");
        }
        // même message envoyé à beaucoup de personnes différentes en peu de temps
        $sig = self::fingerprint($mail);
        $same = DB::table('mb_outbox')->where('account_id', $a->id)->where('created_at', '>=', now()->subHour())->where('fingerprint', $sig)->whereIn('status', ['sent', 'sending', 'queued'])->count();
        if ($same >= ($a->setting('hosted') ? 10 : 25)) {
            throw new \DomainException('Ce même message a déjà été envoyé à de nombreux destinataires dans l\'heure : c\'est un comportement d\'envoi en masse. Utilisez une campagne du module Marketing.');
        }
        $score = self::score($mail);
        if ($score['score'] >= 7) {
            throw new \DomainException('Ce message ressemble à un pourriel et risque d\'être bloqué ('.implode(', ', $score['reasons']).'). Reformulez-le avant de l\'envoyer.');
        }
    }

    public static function fingerprint(array $mail): string
    {
        $body = mb_strtolower(preg_replace('/\s+/u', ' ', strip_tags((string) ($mail['text'] ?? $mail['html'] ?? ''))));

        return sha1(mb_strtolower(trim((string) $mail['subject'])).'|'.mb_substr($body, 0, 2000));
    }

    /** Score de contenu (0 = normal, 7+ = bloqué) avec les raisons, en français. */
    public static function score(array $mail): array
    {
        $score = 0;
        $why = [];
        $subject = (string) $mail['subject'];
        $html = (string) $mail['html'];
        $text = mb_strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8').' '.$subject);
        $letters = preg_replace('/[^\p{L}]/u', '', $subject);
        if (mb_strlen($letters) >= 12 && mb_strtoupper($letters) === $letters) {
            $score += 2;
            $why[] = 'objet en majuscules';
        }
        if (preg_match('/[!?]{3,}|€€|\$\$\$/u', $subject)) {
            $score += 2;
            $why[] = 'ponctuation excessive';
        }
        preg_match_all('#https?://([^/\s"\'<>]+)#i', $html, $m);
        $hosts = array_map('strtolower', $m[1] ?? []);
        if (array_intersect($hosts, self::SHORTENERS)) {
            $score += 3;
            $why[] = 'liens raccourcis';
        }
        if (count($hosts) > 25) {
            $score += 2;
            $why[] = 'très nombreux liens';
        }
        if (array_filter($hosts, fn ($h) => preg_match('/^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/', $h))) {
            $score += 3;
            $why[] = 'lien vers une adresse IP';
        }
        $words = array_filter(self::SPAM_WORDS, fn ($w) => str_contains($text, $w));
        if ($words) {
            $score += min(6, 2 * count($words));
            $why[] = 'expressions typiques des pourriels (« '.implode(' », « ', array_slice(array_values($words), 0, 3)).' »)';
        }
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('#<img[^>]*>#i', ' ', $html))));
        if (preg_match_all('#<img#i', $html) >= 1 && mb_strlen($plain) < 20 && ! $mail['attachments']) {
            $score += 3;
            $why[] = 'message fait uniquement d\'images';
        }

        return ['score' => $score, 'reasons' => $why];
    }

    /** Après un refus du serveur d'envoi : 5 refus en une heure suspendent les envois du compte pendant une heure. */
    public static function recordFailure(Account $a, string $error): void
    {
        $fails = array_values(array_filter((array) $a->stateValue('send_failures', []), fn ($t) => $t > time() - 3600));
        $fails[] = time();
        $state = ['send_failures' => $fails];
        if (count($fails) >= 5) {
            $state['send_blocked_until'] = time() + 3600;
            $state['send_block_reason'] = mb_substr($error, 0, 160);
            $state['send_failures'] = [];
        }
        $a->putState($state);
        $a->saveQuietly();
    }

    public static function recordSuccess(Account $a): void
    {
        if ($a->stateValue('send_failures')) {
            $a->putState(['send_failures' => []]);
            $a->saveQuietly();
        }
    }

    /** Délivrabilité d'un domaine : SPF, DKIM (sélecteurs courants), DMARC, MX — avec conseils. */
    public static function deliverability(string $domain, ?string $smtpHost = null): array
    {
        $txt = function (string $name): array {
            $r = function_exists('dns_get_record') ? (@dns_get_record($name, DNS_TXT) ?: []) : [];

            return array_map(fn ($x) => is_array($x['entries'] ?? null) ? implode('', $x['entries']) : (string) ($x['txt'] ?? ''), $r);
        };
        $spf = array_values(array_filter($txt($domain), fn ($t) => str_starts_with(strtolower($t), 'v=spf1')));
        $dmarc = array_values(array_filter($txt('_dmarc.'.$domain), fn ($t) => str_starts_with(strtolower($t), 'v=dmarc1')));
        $dkim = null;
        foreach (['default', 'selector1', 'selector2', 'google', 'k1', 'mail', 'dkim', 's1', 'smtp'] as $sel) {
            $r = array_values(array_filter($txt($sel.'._domainkey.'.$domain), fn ($t) => str_contains(strtolower($t), 'p=')));
            if ($r) {
                $dkim = $sel;
                break;
            }
        }
        $mx = function_exists('dns_get_record') ? array_map(fn ($x) => $x['target'] ?? '', (@dns_get_record($domain, DNS_MX) ?: [])) : [];
        $checks = [
            ['key' => 'mx', 'label' => 'Serveur de réception (MX)', 'ok' => (bool) $mx, 'detail' => $mx ? implode(', ', $mx) : 'Aucun MX : ce domaine ne peut pas recevoir d\'e-mails.'],
            ['key' => 'spf', 'label' => 'SPF (serveurs autorisés à envoyer)', 'ok' => count($spf) === 1, 'detail' => $spf ? (count($spf) > 1 ? 'Plusieurs enregistrements SPF : n\'en gardez qu\'un.' : $spf[0]) : 'Absent : ajoutez un enregistrement TXT « v=spf1 a mx include:… ~all » (votre hébergeur le fournit).'],
            ['key' => 'dkim', 'label' => 'DKIM (signature des messages)', 'ok' => $dkim !== null, 'detail' => $dkim ? 'Clé trouvée (sélecteur « '.$dkim.' »).' : 'Non trouvé : activez DKIM dans votre hébergement (cPanel › Délivrabilité des e-mails) ou chez Microsoft / Google.'],
            ['key' => 'dmarc', 'label' => 'DMARC (politique anti-usurpation)', 'ok' => (bool) $dmarc, 'detail' => $dmarc ? $dmarc[0] : 'Absent : ajoutez un TXT sur _dmarc.'.$domain.' « v=DMARC1; p=none; rua=mailto:dmarc@'.$domain.' ».'],
        ];
        $ok = count(array_filter($checks, fn ($c) => $c['ok']));

        return ['domain' => $domain, 'score' => $ok, 'max' => count($checks), 'checks' => $checks,
            'verdict' => $ok === 4 ? 'Excellent : vos messages ont toutes les chances d\'arriver en boîte de réception.' : ($ok >= 2 ? 'Correct, mais à compléter pour éviter le dossier spam.' : 'Risque élevé de classement en spam : complétez la configuration DNS.')];
    }
}
