<?php

namespace App\Modules\Mailbox;

use App\Models\Company;
use App\Modules\Mailbox\Models\Account;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Adresses fournies par Yomara pour les sociétés sans serveur de messagerie : boîtes créées sur le cPanel de la plateforme
 * (API UAPI Email), stockées là-bas, utilisées ensuite comme un compte IMAP / SMTP ordinaire — avec les quotas anti-spam renforcés.
 * Réservé au module « Messagerie hébergée » (mail_server).
 */
class HostedMail
{
    public static function config(): array
    {
        return config('erp.mailbox.hosted', []);
    }

    public static function available(?Company $c = null): bool
    {
        $cfg = self::config();

        return ! empty($cfg['cpanel_host']) && ! empty($cfg['cpanel_user']) && ! empty($cfg['cpanel_token']) && ! empty($cfg['domain']) && (! $c || $c->hasModule('mail_server'));
    }

    public static function summary(Company $c): array
    {
        $used = Account::where('settings->hosted', true)->count();

        return ['available' => self::available($c), 'module' => $c->hasModule('mail_server'), 'configured' => self::available(), 'domain' => self::config()['domain'] ?? null,
            'used' => $used, 'max' => (int) (self::config()['max_per_company'] ?? 10), 'quota_mb' => (int) (self::config()['quota_mb'] ?? 2048), 'suffix' => '.'.self::slug($c)];
    }

    public static function slug(Company $c): string
    {
        return substr(trim(preg_replace('/[^a-z0-9]+/', '-', Str::ascii(mb_strtolower($c->slug ?: $c->name))), '-'), 0, 20) ?: 'societe'.$c->id;
    }

    /** Adresse finale : partie-locale.societe@domaine-de-la-plateforme (unique entre sociétés). */
    public static function address(Company $c, string $local): string
    {
        $local = trim(preg_replace('/[^a-z0-9._-]+/', '', Str::ascii(mb_strtolower($local))), '.-_');

        return $local.'.'.self::slug($c).'@'.self::config()['domain'];
    }

    protected static function uapi(string $fn, array $params): array
    {
        $cfg = self::config();
        $r = Http::withHeaders(['Authorization' => 'cpanel '.$cfg['cpanel_user'].':'.$cfg['cpanel_token']])->acceptJson()->timeout(30)
            ->withOptions(['verify' => (bool) ($cfg['verify_tls'] ?? true)])
            ->get('https://'.$cfg['cpanel_host'].':'.($cfg['cpanel_port'] ?? 2083).'/execute/Email/'.$fn, $params);
        $j = $r->json() ?? [];
        if (! $r->successful() || (int) ($j['status'] ?? 0) !== 1) {
            throw new \DomainException('Serveur de messagerie Yomara : '.(implode(' ', (array) ($j['errors'] ?? [])) ?: 'erreur '.$r->status()));
        }

        return $j;
    }

    public static function create(Company $c, int $userId, string $local, ?string $name, bool $shared = false, array $sharedWith = []): Account
    {
        if (! self::available($c)) {
            throw new \DomainException($c->hasModule('mail_server') ? 'Les adresses Yomara ne sont pas encore paramétrées sur ce serveur.' : 'Les adresses fournies par Yomara font partie du module « Messagerie hébergée ».');
        }
        $s = self::summary($c);
        if ($s['used'] >= $s['max']) {
            throw new \DomainException("Votre formule comprend {$s['max']} adresse(s) Yomara : retirez-en une ou contactez-nous pour en ajouter.");
        }
        if (strlen(trim($local)) < 2) {
            throw new \DomainException('Choisissez un nom d\'adresse d\'au moins 2 caractères (ex. contact, compta, awa.traore).');
        }
        if (in_array(strtolower(trim($local)), ['postmaster', 'abuse', 'root', 'admin', 'webmaster', 'hostmaster', 'noreply', 'no-reply', 'mailer-daemon'], true)) {
            throw new \DomainException('Ce nom est réservé : choisissez-en un autre.');
        }
        $email = self::address($c, $local);
        if (Account::withoutGlobalScopes()->where('email', $email)->exists()) {
            throw new \DomainException("L'adresse $email existe déjà.");
        }
        [$user, $domain] = explode('@', $email);
        $password = Str::password(20, symbols: false);
        self::uapi('add_pop', ['email' => $user, 'domain' => $domain, 'password' => $password, 'quota' => $s['quota_mb'], 'skip_update_db' => 0]);
        $cfg = self::config();
        $a = new Account(['provider' => 'imap', 'email' => $email, 'display_name' => $name ?: $c->name, 'color' => '#0B1B3A', 'sync_days' => 30,
            'user_id' => $shared ? null : $userId, 'shared_with' => $shared ? array_values(array_unique(array_map('intval', $sharedWith))) : null,
            'settings' => ['hosted' => true, 'username' => $email, 'imap_host' => $cfg['imap_host'] ?? $cfg['cpanel_host'], 'imap_port' => 993, 'imap_security' => 'ssl',
                'smtp_host' => $cfg['smtp_host'] ?? $cfg['cpanel_host'], 'smtp_port' => 465, 'smtp_security' => 'ssl', 'quota_mb' => $s['quota_mb']], 'next_sync_at' => now()]);
        $a->company_id = $c->id;
        $a->setSecret(['password' => $password]);
        $a->save();

        return $a;
    }

    public static function remove(Account $a): void
    {
        [$user, $domain] = explode('@', $a->email);
        self::uapi('delete_pop', ['email' => $user, 'domain' => $domain]);
    }

    /** Mot de passe pour configurer la boîte sur un téléphone (régénéré). */
    public static function resetPassword(Account $a): string
    {
        [$user, $domain] = explode('@', $a->email);
        $password = Str::password(20, symbols: false);
        self::uapi('passwd_pop', ['email' => $user, 'domain' => $domain, 'password' => $password]);
        $a->setSecret(array_merge($a->secret(), ['password' => $password]));
        $a->save();

        return $password;
    }

    public static function usage(Account $a): ?array
    {
        try {
            [$user, $domain] = explode('@', $a->email);
            $j = self::uapi('get_disk_usage', ['user' => $user, 'domain' => $domain]);

            return ['used_mb' => round(((float) ($j['data']['diskused'] ?? 0)), 1), 'quota_mb' => (int) ($a->setting('quota_mb') ?? 0)];
        } catch (\Throwable) {
            return null;
        }
    }
}
