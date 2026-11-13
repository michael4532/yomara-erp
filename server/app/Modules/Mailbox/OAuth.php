<?php

namespace App\Modules\Mailbox;

use App\Models\Company;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Protocol\MailProtocolException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Connexion Microsoft 365 (Microsoft Graph) et Google (Gmail IMAP/SMTP en XOAUTH2 + Google Agenda + contacts).
 * Application de la société si elle a saisi la sienne, sinon celle de la plateforme (.env).
 */
class OAuth
{
    public const MS_SCOPES = 'offline_access openid profile email User.Read Mail.ReadWrite Mail.Send Mail.ReadWrite.Shared Mail.Send.Shared Calendars.ReadWrite Contacts.Read MailboxSettings.Read';

    public const GOOGLE_SCOPES = 'openid email profile https://mail.google.com/ https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/contacts.readonly';

    public static function app(Company $c, string $provider): array
    {
        $row = DB::table('mb_settings')->where('company_id', $c->id)->first();
        $dec = fn ($v) => $v ? rescue(fn () => Crypt::decryptString($v), null, false) : null;
        $cfg = config('erp.mailbox', []);
        if ($provider === 'microsoft') {
            $own = $row && $row->microsoft_client_id && $row->microsoft_client_secret;

            return ['own' => (bool) $own, 'client_id' => $own ? $row->microsoft_client_id : ($cfg['microsoft_client_id'] ?? null),
                'client_secret' => $own ? $dec($row->microsoft_client_secret) : ($cfg['microsoft_client_secret'] ?? null),
                'tenant' => ($own ? $row->microsoft_tenant : null) ?: ($cfg['microsoft_tenant'] ?? null) ?: 'common'];
        }
        $own = $row && $row->google_client_id && $row->google_client_secret;

        return ['own' => (bool) $own, 'client_id' => $own ? $row->google_client_id : ($cfg['google_client_id'] ?? null),
            'client_secret' => $own ? $dec($row->google_client_secret) : ($cfg['google_client_secret'] ?? null)];
    }

    public static function configured(Company $c, string $provider): bool
    {
        $a = self::app($c, $provider);

        return ! empty($a['client_id']) && ! empty($a['client_secret']);
    }

    /** Connexion des boîtes Gmail / Outlook autorisée par le super admin (Configuration du serveur). */
    public static function enabled(string $provider): bool
    {
        return (bool) config('erp.mailbox_oauth.'.$provider, true);
    }

    public static function redirectUri(string $provider): string
    {
        return url('/oauth/'.$provider.'/retour');
    }

    public static function authorizeUrl(Company $c, int $userId, string $provider, array $meta = []): string
    {
        $app = self::app($c, $provider);
        if (! $app['client_id']) {
            throw new \DomainException($provider === 'microsoft'
                ? 'Microsoft 365 n\'est pas encore paramétré : renseignez l\'application Azure dans YoMail › Paramètres (guide fourni).'
                : 'Google n\'est pas encore paramétré : renseignez l\'application Google Cloud dans YoMail › Paramètres (guide fourni).');
        }
        DB::table('mb_oauth_states')->where('created_at', '<', now()->subHour())->delete();
        $state = Str::random(48);
        DB::table('mb_oauth_states')->insert(['state' => $state, 'company_id' => $c->id, 'user_id' => $userId, 'provider' => $provider, 'meta' => json_encode($meta), 'created_at' => now()]);
        if ($provider === 'microsoft') {
            return 'https://login.microsoftonline.com/'.$app['tenant'].'/oauth2/v2.0/authorize?'.http_build_query([
                'client_id' => $app['client_id'], 'response_type' => 'code', 'redirect_uri' => self::redirectUri('microsoft'), 'response_mode' => 'query',
                'scope' => self::MS_SCOPES, 'state' => $state, 'prompt' => 'select_account', 'login_hint' => $meta['email'] ?? null,
            ]);
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $app['client_id'], 'response_type' => 'code', 'redirect_uri' => self::redirectUri('google'), 'scope' => self::GOOGLE_SCOPES,
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state, 'login_hint' => $meta['email'] ?? null,
        ]);
    }

    /** Retour du fournisseur : échange du code, création ou mise à jour du compte. */
    public static function complete(string $provider, string $state, string $code): Account
    {
        $st = DB::table('mb_oauth_states')->where('state', $state)->where('provider', $provider)->first();
        if (! $st || now()->diffInMinutes($st->created_at, true) > 60) {
            throw new \DomainException('Le lien de connexion a expiré : recommencez depuis la boîte mail.');
        }
        DB::table('mb_oauth_states')->where('state', $state)->delete();
        $company = Company::findOrFail($st->company_id);
        app()->instance('tenant', $company);
        $meta = json_decode((string) $st->meta, true) ?: [];
        $app = self::app($company, $provider);
        $tok = self::tokenRequest($provider, $app, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri($provider)]);
        if ($provider === 'microsoft') {
            $me = Http::withToken($tok['access_token'])->acceptJson()->get('https://graph.microsoft.com/v1.0/me?$select=displayName,mail,userPrincipalName')->throw()->json();
            $email = mb_strtolower($me['mail'] ?? $me['userPrincipalName']);
            $name = $me['displayName'] ?? null;
        } else {
            $me = Http::withToken($tok['access_token'])->acceptJson()->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();
            $email = mb_strtolower($me['email']);
            $name = $me['name'] ?? null;
        }
        $acc = Account::withoutGlobalScopes()->firstOrNew(['company_id' => $company->id, 'user_id' => $st->user_id, 'email' => $email]);
        $acc->company_id = $company->id;
        $acc->provider = $provider;
        $acc->display_name = $acc->display_name ?: $name;
        $acc->status = 'active';
        $acc->error = null;
        $acc->next_sync_at = now();
        if (! $acc->exists) {
            $acc->calendar_sync = (bool) ($meta['calendar'] ?? true);
            $acc->sync_days = (int) ($meta['sync_days'] ?? 30);
            $acc->color = $provider === 'microsoft' ? '#0F6CBD' : '#EA4335';
        }
        $acc->setSecret(['access_token' => $tok['access_token'], 'refresh_token' => $tok['refresh_token'] ?? ($acc->exists ? ($acc->secret()['refresh_token'] ?? null) : null),
            'expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 3600) - 60)->timestamp]);
        $acc->save();
        if ($provider === 'microsoft' && ! $acc->avatar_url) { // photo de profil Microsoft 365, si elle existe
            try {
                $ph = Http::withToken($tok['access_token'])->timeout(15)->get('https://graph.microsoft.com/v1.0/me/photos/240x240/$value');
                if ($ph->successful() && strlen($ph->body()) > 100) {
                    $acc->forceFill(['avatar_url' => Media::store($ph->body(), str_contains((string) $ph->header('Content-Type'), 'png') ? 'png' : 'jpg')])->save();
                }
            } catch (\Throwable) {
            }
        } elseif ($provider === 'google' && ! $acc->avatar_url && ! empty($me['picture'])) {
            $acc->forceFill(['avatar_url' => $me['picture']])->save();
        }

        return $acc;
    }

    /** Jeton d'accès valide (renouvelé si besoin). Une boîte partagée Microsoft utilise le jeton du compte qui y a accès. */
    public static function token(Account $a): string
    {
        if ($src = $a->setting('token_account_id')) {
            $owner = Account::withoutGlobalScopes()->find($src);
            if (! $owner) {
                throw new MailProtocolException('Le compte Microsoft qui donnait accès à cette boîte partagée a été supprimé.');
            }

            return self::token($owner);
        }
        $s = $a->secret();
        if (! empty($s['access_token']) && ($s['expires_at'] ?? 0) > time() + 30) {
            return $s['access_token'];
        }
        if (empty($s['refresh_token'])) {
            throw new MailProtocolException('Connexion expirée : reconnectez le compte '.$a->email.'.');
        }
        $company = Company::find($a->company_id);
        $app = self::app($company, $a->provider);
        try {
            $tok = self::tokenRequest($a->provider, $app, ['grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']]);
        } catch (MailProtocolException $e) {
            throw new MailProtocolException('Connexion expirée ou révoquée : reconnectez le compte '.$a->email.'. ('.$e->getMessage().')', 0, $e);
        }
        $a->setSecret(['access_token' => $tok['access_token'], 'refresh_token' => $tok['refresh_token'] ?? $s['refresh_token'], 'expires_at' => time() + (int) ($tok['expires_in'] ?? 3600) - 60]);
        $a->saveQuietly();

        return $tok['access_token'];
    }

    protected static function tokenRequest(string $provider, array $app, array $params): array
    {
        $url = $provider === 'microsoft' ? 'https://login.microsoftonline.com/'.$app['tenant'].'/oauth2/v2.0/token' : 'https://oauth2.googleapis.com/token';
        $params += ['client_id' => $app['client_id'], 'client_secret' => $app['client_secret']];
        if ($provider === 'microsoft') {
            $params['scope'] = self::MS_SCOPES;
        }
        $r = Http::asForm()->acceptJson()->timeout(20)->post($url, $params);
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new MailProtocolException($r->json('error_description') ?: ($r->json('error') ?: 'Réponse '.$r->status()));
        }

        return $r->json();
    }
}
