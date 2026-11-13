<?php

namespace App\Modules\Mailbox;

use App\Modules\Mailbox\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * YoMail : liens des e-mails envoyés.
 *  - suivi des clics (option à l'envoi) : chaque lien http(s) passe par /y/c/{jeton}, signé pour qu'aucune adresse
 *    choisie par un tiers ne puisse servir de redirection ; le message envoyé affiche le nombre de clics par lien ;
 *  - lien de l'application (option de la société) : « Envoyé avec YoMail · Télécharger l'application » vers le lien intelligent.
 */
class LinkTracking
{
    public static function sign(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', $token.'|'.$url, (string) config('app.key')), 0, 20);
    }

    public static function valid(string $token, string $url, string $sig): bool
    {
        return $sig !== '' && hash_equals(self::sign($token, $url), $sig);
    }

    public static function clickUrl(string $token, string $url): string
    {
        return url('/y/c/'.$token).'?u='.rtrim(strtr(base64_encode($url), '+/', '-_'), '=').'&s='.self::sign($token, $url);
    }

    /** Options de la société (gestionnaire de YoMail). */
    public static function settings(int $companyId): array
    {
        $row = DB::table('mb_settings')->where('company_id', $companyId)->first();

        return ['app_link_footer' => (bool) ($row->app_link_footer ?? false), 'track_clicks' => (bool) ($row->track_clicks ?? false)];
    }

    /** Appliqué au moment de l'envoi réel (file d'envoi) : jeton stable par envoi, réessais compris. */
    public static function apply(Account $a, array $mail, array $payload, string $outboxId): array
    {
        $opts = self::settings((int) $a->company_id);
        $html = $mail['html'];
        if (! empty($payload['track_clicks'])) {
            $token = substr(hash_hmac('sha256', 'yomail-track|'.$outboxId, (string) config('app.key')), 0, 40);
            $count = 0;
            $html = preg_replace_callback('#(<a\b[^>]*?\bhref\s*=\s*)(["\'])(https?://[^"\']+)\2#i', function ($m) use ($token, &$count) {
                $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Lien de désinscription ou déjà suivi : laissé tel quel
                if (str_contains($url, '/y/c/') || preg_match('#unsubscribe|desinscri#i', $url) || strlen($url) > 1900) {
                    return $m[0];
                }
                $count++;

                return $m[1].$m[2].e(self::clickUrl($token, $url)).$m[2];
            }, $html) ?? $html;
            if ($count > 0) {
                DB::table('mb_link_tracks')->updateOrInsert(['token' => $token], [
                    'company_id' => $a->company_id, 'account_id' => $a->id, 'outbox_id' => $outboxId,
                    'message_id' => Mime::mid($mail['message_id'] ?? null), 'subject' => mb_substr((string) ($mail['subject'] ?? ''), 0, 300),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        // Après le suivi : le lien de l'application n'est pas compté comme un clic du destinataire
        if ($opts['app_link_footer']) {
            $link = url('/yomail');
            $footer = '<div style="margin-top:22px;padding-top:10px;border-top:1px solid #e3e8ef;font-size:12px;color:#6b7686">'
                .'Envoyé avec YoMail · <a href="'.e($link).'" style="color:#2E6BE6;text-decoration:none">Télécharger l\'application</a></div>';
            $html = str_contains($html, '</body>') ? str_replace('</body>', $footer.'</body>', $html) : $html.$footer;
            $mail['text'] = rtrim($mail['text'] ?? '')."\n\n--\nEnvoyé avec YoMail · ".$link;
        }
        $mail['html'] = $html;

        return $mail;
    }

    public static function click(string $token, string $url): void
    {
        $t = DB::table('mb_link_tracks')->where('token', $token)->first();
        if (! $t) {
            return;
        }
        DB::table('mb_link_tracks')->where('id', $t->id)->update(['clicks' => $t->clicks + 1, 'first_click_at' => $t->first_click_at ?? now(), 'last_click_at' => now(), 'updated_at' => now()]);
        DB::table('mb_link_clicks')->insert(['token' => $token, 'url' => mb_substr($url, 0, 2000), 'clicked_at' => now()]);
    }

    /** Clics d'un message envoyé (par son Message-ID). */
    public static function stats(int $companyId, ?string $messageId): ?array
    {
        $mid = Mime::mid($messageId);
        $t = $mid ? DB::table('mb_link_tracks')->where('company_id', $companyId)->where('message_id', $mid)->first() : null;
        if (! $t) {
            return null;
        }
        $links = DB::table('mb_link_clicks')->where('token', $t->token)->selectRaw('url, count(*) as clicks, max(clicked_at) as last_at')->groupBy('url')->orderByDesc('clicks')->get()
            ->map(fn ($l) => ['url' => $l->url, 'clicks' => (int) $l->clicks, 'last_at' => $l->last_at])->all();

        return ['clicks' => (int) $t->clicks, 'first_click_at' => $t->first_click_at, 'last_click_at' => $t->last_click_at, 'links' => $links];
    }
}
