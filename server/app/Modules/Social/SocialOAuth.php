<?php

namespace App\Modules\Social;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Connexion des comptes par OAuth 2 (Meta, Threads, LinkedIn, X, TikTok, Pinterest, Google) : lien d'autorisation,
 * échange du code, découverte des pages / profils / chaînes, puis rafraîchissement automatique des jetons.
 */
class SocialOAuth
{
    public const GRAPH = 'https://graph.facebook.com/v21.0';

    /** Remarques à afficher après la connexion (ex. YouTube connecté mais fiche Google Business inaccessible). */
    public array $notes = [];

    public const SCOPES = [
        'meta' => 'pages_show_list,pages_read_engagement,pages_manage_posts,read_insights,instagram_basic,instagram_content_publish,business_management',
        'threads' => 'threads_basic,threads_content_publish,threads_manage_insights',
        'linkedin' => 'openid profile w_member_social',
        'x' => 'tweet.read tweet.write users.read media.write offline.access',
        'tiktok' => 'user.info.basic,video.publish',
        'pinterest' => 'boards:read,pins:read,pins:write,user_accounts:read',
        'google' => 'openid email https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/business.manage',
    ];

    /** Meta : messages, commentaires et prospects des publicités, seulement une fois ces autorisations validées par Meta (App Review). */
    public const META_EXTENDED_SCOPES = 'pages_manage_metadata,pages_messaging,pages_read_user_content,pages_manage_engagement,leads_retrieval,ads_read,instagram_manage_comments,instagram_manage_messages';

    public static function metaExtended(): bool
    {
        return ! empty((array) (\App\Models\PlatformSetting::get('social.apps', [])['meta']['extended'] ?? false));
    }

    /** LinkedIn : pages entreprise, seulement si l'application a le produit « Community Management API ». */
    public const LINKEDIN_ORG_SCOPES = 'w_organization_social r_organization_admin';

    protected function fail(string $m): never
    {
        throw new \DomainException($m);
    }

    protected function app(string $provider): array
    {
        $a = Networks::app($provider);
        if (! $a['client_id'] || ! $a['client_secret']) {
            $this->fail('La connexion '.Networks::PROVIDERS[$provider]['label'].' n\'est pas encore activée sur la plateforme : le super administrateur doit renseigner l\'application dans Réseaux sociaux › Comptes › Applications.');
        }

        return $a;
    }

    public function authorizeUrl(Company $c, User $u, string $provider): string
    {
        abort_unless(isset(Networks::PROVIDERS[$provider]), 404);
        $app = $this->app($provider);
        DB::table('so_oauth_states')->where('created_at', '<', now()->subHour())->delete();
        $state = Str::random(48);
        $verifier = Str::random(64);
        DB::table('so_oauth_states')->insert(['state' => $state, 'company_id' => $c->id, 'user_id' => $u->id, 'provider' => $provider, 'verifier' => $verifier, 'created_at' => now()]);
        $redirect = Networks::redirectUri($provider);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $scope = self::SCOPES[$provider];
        if ($provider === 'linkedin' && ! empty((array) \App\Models\PlatformSetting::get('social.apps', [])['linkedin']['organizations'])) {
            $scope .= ' '.self::LINKEDIN_ORG_SCOPES;
        }
        if ($provider === 'meta' && self::metaExtended()) {
            $scope .= ','.self::META_EXTENDED_SCOPES;
        }

        return match ($provider) {
            'meta' => 'https://www.facebook.com/v21.0/dialog/oauth?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => $scope, 'response_type' => 'code']),
            'threads' => 'https://threads.net/oauth/authorize?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => $scope, 'response_type' => 'code']),
            'linkedin' => 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query(['response_type' => 'code', 'client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => $scope]),
            'x' => 'https://x.com/i/oauth2/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'scope' => $scope, 'state' => $state,
                'code_challenge' => $challenge, 'code_challenge_method' => 'S256']),
            'tiktok' => 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query(['client_key' => $app['client_id'], 'response_type' => 'code', 'scope' => $scope, 'redirect_uri' => $redirect, 'state' => $state]),
            'pinterest' => 'https://www.pinterest.com/oauth/?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => $scope, 'state' => $state]),
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => $scope,
                'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state]),
        };
    }

    /** Retour du réseau : renvoie la liste des comptes connectés (nom et réseau). */
    public function complete(string $provider, string $state, string $code): array
    {
        $st = DB::table('so_oauth_states')->where('state', $state)->where('provider', $provider)->where('created_at', '>=', now()->subHour())->first();
        if (! $st || $code === '') {
            $this->fail('Lien de connexion expiré : relancez la connexion depuis Yomara.');
        }
        DB::table('so_oauth_states')->where('state', $state)->delete(); // usage unique
        $c = Company::findOrFail($st->company_id);
        $u = User::where('company_id', $c->id)->findOrFail($st->user_id);
        $app = $this->app($provider);
        $redirect = Networks::redirectUri($provider);

        return match ($provider) {
            'meta' => $this->meta($c, $u, $app, $redirect, $code),
            'threads' => $this->threads($c, $u, $app, $redirect, $code),
            'linkedin' => $this->linkedin($c, $u, $app, $redirect, $code),
            'x' => $this->x($c, $u, $app, $redirect, $code, (string) $st->verifier),
            'tiktok' => $this->tiktok($c, $u, $app, $redirect, $code),
            'pinterest' => $this->pinterest($c, $u, $app, $redirect, $code),
            'google' => $this->google($c, $u, $app, $redirect, $code),
        };
    }

    protected function err($res, string $who): never
    {
        $m = $res->json('error.message') ?: $res->json('error_description') ?: $res->json('message') ?: $res->json('error.description')
            ?: (is_string($res->json('error')) ? $res->json('error') : null) ?: 'erreur '.$res->status();
        $this->fail($who.' refuse la connexion : '.mb_substr((string) $m, 0, 250));
    }

    /** Enregistre (ou met à jour) un compte connecté. */
    public static function store(Company $c, ?User $u, string $network, string $externalId, string $name, array $creds, array $extra = []): array
    {
        $row = [
            'name' => mb_substr($name, 0, 190) ?: Networks::label($network), 'username' => $extra['username'] ?? null, 'avatar_url' => isset($extra['avatar']) ? mb_substr((string) $extra['avatar'], 0, 1000) : null,
            'kind' => $extra['kind'] ?? null, 'meta' => json_encode($extra['meta'] ?? []), 'credentials' => Crypt::encryptString(json_encode($creds)),
            'token_expires_at' => ! empty($creds['expires_at']) ? date('Y-m-d H:i:s', (int) $creds['expires_at']) : null,
            'status' => 'active', 'last_error' => null, 'updated_at' => now(),
        ];
        $q = DB::table('so_accounts')->where('company_id', $c->id)->where('network', $network)->where('external_id', $externalId);
        if ($q->exists()) {
            $q->update($row);
        } else {
            DB::table('so_accounts')->insert($row + ['company_id' => $c->id, 'network' => $network, 'external_id' => $externalId, 'connected_by' => $u?->id, 'created_at' => now()]);
        }

        return ['network' => $network, 'label' => Networks::label($network), 'name' => $row['name']];
    }

    protected static function exp($res): ?int
    {
        $s = (int) ($res->json('expires_in') ?? 0);

        return $s > 0 ? time() + $s : null;
    }

    // ------------------------------------------------------------------ fournisseurs

    protected function meta(Company $c, User $u, array $app, string $redirect, string $code): array
    {
        $t = Http::timeout(20)->get(self::GRAPH.'/oauth/access_token', ['client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'redirect_uri' => $redirect, 'code' => $code]);
        $t->ok() || $this->err($t, 'Meta');
        $long = Http::timeout(20)->get(self::GRAPH.'/oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
            'fb_exchange_token' => $t->json('access_token')]);
        $userToken = (string) ($long->ok() ? $long->json('access_token') : $t->json('access_token'));
        $pages = Http::timeout(20)->get(self::GRAPH.'/me/accounts', ['fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username,profile_picture_url}', 'limit' => 100, 'access_token' => $userToken]);
        $pages->ok() || $this->err($pages, 'Meta');
        $out = [];
        foreach ((array) $pages->json('data') as $p) {
            // Le jeton de page obtenu à partir d'un jeton utilisateur longue durée n'expire pas
            $creds = ['provider' => 'meta', 'access_token' => $p['access_token']];
            $out[] = self::store($c, $u, 'facebook', (string) $p['id'], (string) $p['name'], $creds, ['kind' => 'page', 'avatar' => $p['picture']['data']['url'] ?? null]);
            if ($ig = $p['instagram_business_account'] ?? null) {
                $out[] = self::store($c, $u, 'instagram', (string) $ig['id'], '@'.($ig['username'] ?? $ig['id']), $creds,
                    ['kind' => 'business', 'username' => $ig['username'] ?? null, 'avatar' => $ig['profile_picture_url'] ?? null, 'meta' => ['page_id' => $p['id']]]);
            }
        }
        $out ?: $this->fail('Aucune page Facebook trouvée : à l\'autorisation, cochez les pages (et comptes Instagram professionnels reliés) à gérer.');

        return $out;
    }

    protected function threads(Company $c, User $u, array $app, string $redirect, string $code): array
    {
        $t = Http::asForm()->timeout(20)->post('https://graph.threads.net/oauth/access_token', ['client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
            'grant_type' => 'authorization_code', 'redirect_uri' => $redirect, 'code' => $code]);
        $t->ok() || $this->err($t, 'Threads');
        $long = Http::timeout(20)->get('https://graph.threads.net/access_token', ['grant_type' => 'th_exchange_token', 'client_secret' => $app['client_secret'], 'access_token' => $t->json('access_token')]);
        $tok = (string) ($long->ok() ? $long->json('access_token') : $t->json('access_token'));
        $me = Http::timeout(20)->get('https://graph.threads.net/v1.0/me', ['fields' => 'id,username,threads_profile_picture_url', 'access_token' => $tok]);
        $me->ok() || $this->err($me, 'Threads');

        return [self::store($c, $u, 'threads', (string) $me->json('id'), '@'.$me->json('username'), ['provider' => 'threads', 'access_token' => $tok, 'expires_at' => $long->ok() ? self::exp($long) : self::exp($t)],
            ['kind' => 'profile', 'username' => $me->json('username'), 'avatar' => $me->json('threads_profile_picture_url')])];
    }

    protected function linkedin(Company $c, User $u, array $app, string $redirect, string $code): array
    {
        $t = Http::asForm()->timeout(20)->post('https://www.linkedin.com/oauth/v2/accessToken', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect,
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret']]);
        $t->ok() || $this->err($t, 'LinkedIn');
        $tok = (string) $t->json('access_token');
        $creds = ['provider' => 'linkedin', 'access_token' => $tok, 'refresh_token' => $t->json('refresh_token'), 'expires_at' => self::exp($t)];
        $me = Http::withToken($tok)->timeout(20)->get('https://api.linkedin.com/v2/userinfo');
        $me->ok() || $this->err($me, 'LinkedIn');
        $out = [self::store($c, $u, 'linkedin', 'person:'.$me->json('sub'), (string) $me->json('name'), $creds, ['kind' => 'profile', 'avatar' => $me->json('picture')])];
        // Pages entreprise administrées (si l'application y a droit)
        $acl = Publisher::linkedinHttp($tok)->get('https://api.linkedin.com/rest/organizationAcls', ['q' => 'roleAssignee', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED']);
        foreach ($acl->ok() ? (array) $acl->json('elements') : [] as $e) {
            $id = preg_replace('/\D/', '', (string) ($e['organization'] ?? ''));
            if ($id === '') {
                continue;
            }
            $org = Publisher::linkedinHttp($tok)->get('https://api.linkedin.com/rest/organizations/'.$id);
            $out[] = self::store($c, $u, 'linkedin', 'organization:'.$id, (string) ($org->json('localizedName') ?: 'Page '.$id), $creds, ['kind' => 'organization', 'username' => $org->json('vanityName')]);
        }

        return $out;
    }

    protected function x(Company $c, User $u, array $app, string $redirect, string $code, string $verifier): array
    {
        $t = Http::asForm()->withBasicAuth($app['client_id'], $app['client_secret'])->timeout(20)->post('https://api.x.com/2/oauth2/token', [
            'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirect, 'code_verifier' => $verifier, 'client_id' => $app['client_id']]);
        $t->ok() || $this->err($t, 'X');
        $tok = (string) $t->json('access_token');
        $me = Http::withToken($tok)->timeout(20)->get('https://api.x.com/2/users/me', ['user.fields' => 'profile_image_url,username,name']);
        $me->ok() || $this->err($me, 'X');

        return [self::store($c, $u, 'x', (string) $me->json('data.id'), (string) $me->json('data.name'), ['provider' => 'x', 'access_token' => $tok, 'refresh_token' => $t->json('refresh_token'), 'expires_at' => self::exp($t)],
            ['kind' => 'profile', 'username' => $me->json('data.username'), 'avatar' => $me->json('data.profile_image_url')])];
    }

    protected function tiktok(Company $c, User $u, array $app, string $redirect, string $code): array
    {
        $t = Http::asForm()->timeout(20)->post('https://open.tiktokapis.com/v2/oauth/token/', ['client_key' => $app['client_id'], 'client_secret' => $app['client_secret'],
            'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirect]);
        ($t->ok() && $t->json('access_token')) || $this->err($t, 'TikTok');
        $tok = (string) $t->json('access_token');
        $me = Http::withToken($tok)->timeout(20)->get('https://open.tiktokapis.com/v2/user/info/', ['fields' => 'open_id,display_name,avatar_url,username']);

        return [self::store($c, $u, 'tiktok', (string) $t->json('open_id'), (string) ($me->json('data.user.display_name') ?: 'TikTok'),
            ['provider' => 'tiktok', 'access_token' => $tok, 'refresh_token' => $t->json('refresh_token'), 'expires_at' => self::exp($t)],
            ['kind' => 'profile', 'username' => $me->json('data.user.username'), 'avatar' => $me->json('data.user.avatar_url')])];
    }

    protected function pinterest(Company $c, User $u, array $app, string $redirect, string $code): array
    {
        $t = Http::asForm()->withBasicAuth($app['client_id'], $app['client_secret'])->timeout(20)->post('https://api.pinterest.com/v5/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect]);
        $t->ok() || $this->err($t, 'Pinterest');
        $tok = (string) $t->json('access_token');
        $me = Http::withToken($tok)->timeout(20)->get('https://api.pinterest.com/v5/user_account');
        $me->ok() || $this->err($me, 'Pinterest');
        $boards = Http::withToken($tok)->timeout(20)->get('https://api.pinterest.com/v5/boards', ['page_size' => 100]);
        $list = collect($boards->ok() ? (array) $boards->json('items') : [])->map(fn ($b) => ['id' => (string) $b['id'], 'name' => (string) $b['name']])->values()->all();

        return [self::store($c, $u, 'pinterest', (string) $me->json('username'), (string) ($me->json('business_name') ?: $me->json('username')),
            ['provider' => 'pinterest', 'access_token' => $tok, 'refresh_token' => $t->json('refresh_token'), 'expires_at' => self::exp($t)],
            ['kind' => 'profile', 'username' => $me->json('username'), 'avatar' => $me->json('profile_image'), 'meta' => ['boards' => $list, 'board_id' => $list[0]['id'] ?? null]])];
    }

    protected function google(Company $c, User $u, array $app, string $redirect, string $code): array
    {
        $t = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', ['code' => $code, 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
            'redirect_uri' => $redirect, 'grant_type' => 'authorization_code']);
        $t->ok() || $this->err($t, 'Google');
        $tok = (string) $t->json('access_token');
        $creds = ['provider' => 'google', 'access_token' => $tok, 'refresh_token' => $t->json('refresh_token'), 'expires_at' => self::exp($t)];
        $out = [];
        $yt = Http::withToken($tok)->timeout(20)->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'snippet', 'mine' => 'true']);
        foreach ($yt->ok() ? (array) $yt->json('items') : [] as $ch) {
            $out[] = self::store($c, $u, 'youtube', (string) $ch['id'], (string) ($ch['snippet']['title'] ?? 'YouTube'), $creds,
                ['kind' => 'channel', 'username' => $ch['snippet']['customUrl'] ?? null, 'avatar' => $ch['snippet']['thumbnails']['default']['url'] ?? null]);
        }
        $gbp = 0;
        $why = null;
        $accs = Http::withToken($tok)->timeout(20)->get('https://mybusinessaccountmanagement.googleapis.com/v1/accounts');
        if (! $accs->ok()) {
            $why = self::gbpReason($accs);
        }
        foreach ($accs->ok() ? (array) $accs->json('accounts') : [] as $a) {
            $locs = Http::withToken($tok)->timeout(20)->get('https://mybusinessbusinessinformation.googleapis.com/v1/'.$a['name'].'/locations', ['readMask' => 'name,title', 'pageSize' => 100]);
            if (! $locs->ok()) {
                $why ??= self::gbpReason($locs);
            }
            foreach ($locs->ok() ? (array) $locs->json('locations') : [] as $l) {
                $out[] = self::store($c, $u, 'gbp', $a['name'].'/'.$l['name'], (string) ($l['title'] ?? 'Établissement'), $creds, ['kind' => 'location']);
                $gbp++;
            }
        }
        if (! $gbp) {
            $why ??= 'aucune fiche d\'établissement n\'est gérée par ce compte Google (vérifiez sur business.google.com que ce compte est propriétaire ou gestionnaire de la fiche).';
            $note = 'Google Business Profile : '.$why;
            $out ?: $this->fail($note);
            $this->notes[] = $note;
        }

        return $out;
    }

    /** Raison lisible d'un refus des API Google Business Profile. */
    protected static function gbpReason($res): string
    {
        $m = (string) ($res->json('error.message') ?? '');
        $status = (string) ($res->json('error.status') ?? '');
        if ($res->status() === 429 || $status === 'RESOURCE_EXHAUSTED' || str_contains($m, 'Quota')) {
            return 'Google n\'a pas encore ouvert l\'accès aux API Business Profile pour cette application (quota à 0). '
                .'Il faut en faire la demande à Google (formulaire « Business Profile API access request ») depuis le projet Google Cloud ; '
                .'une fois l\'accès accordé, reconnectez le compte.';
        }
        if (str_contains($m, 'has not been used') || str_contains($m, 'is disabled') || $status === 'PERMISSION_DENIED' && str_contains($m, 'API')) {
            return 'les API « My Business Account Management » et « My Business Business Information » ne sont pas activées dans le projet Google Cloud de l\'application. '
                .'Activez-les (APIs et services › Bibliothèque), puis reconnectez le compte.';
        }

        return 'Google refuse l\'accès aux fiches ('.$res->status().($m ? ' : '.mb_substr($m, 0, 200) : '').').';
    }

    // ------------------------------------------------------------------ rafraîchissement

    /** Jeton d'accès valide pour ce compte (rafraîchi s'il expire dans moins de 5 minutes). */
    public function token(object $acc): string
    {
        $cr = self::creds($acc);
        $exp = (int) ($cr['expires_at'] ?? 0);
        if ($exp && $exp < time() + 300) {
            $cr = $this->refresh($acc, $cr);
        }

        return (string) ($cr['access_token'] ?? '');
    }

    public static function creds(object $acc): array
    {
        return json_decode(Crypt::decryptString($acc->credentials), true) ?: [];
    }

    protected function refresh(object $acc, array $cr): array
    {
        $p = $cr['provider'] ?? null;
        $rt = $cr['refresh_token'] ?? null;
        $app = $p ? Networks::app($p) : [];
        $res = match (true) {
            $p === 'threads' => Http::timeout(20)->get('https://graph.threads.net/refresh_access_token', ['grant_type' => 'th_refresh_token', 'access_token' => $cr['access_token'] ?? '']),
            ! $rt || empty($app['client_id']) => null,
            $p === 'x' => Http::asForm()->withBasicAuth($app['client_id'], $app['client_secret'])->timeout(20)->post('https://api.x.com/2/oauth2/token',
                ['grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $app['client_id']]),
            $p === 'tiktok' => Http::asForm()->timeout(20)->post('https://open.tiktokapis.com/v2/oauth/token/', ['client_key' => $app['client_id'], 'client_secret' => $app['client_secret'],
                'grant_type' => 'refresh_token', 'refresh_token' => $rt]),
            $p === 'pinterest' => Http::asForm()->withBasicAuth($app['client_id'], $app['client_secret'])->timeout(20)->post('https://api.pinterest.com/v5/oauth/token',
                ['grant_type' => 'refresh_token', 'refresh_token' => $rt]),
            $p === 'google' => Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', ['client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
                'grant_type' => 'refresh_token', 'refresh_token' => $rt]),
            $p === 'linkedin' => Http::asForm()->timeout(20)->post('https://www.linkedin.com/oauth/v2/accessToken', ['grant_type' => 'refresh_token', 'refresh_token' => $rt,
                'client_id' => $app['client_id'], 'client_secret' => $app['client_secret']]),
            default => null,
        };
        if (! $res || ! $res->ok() || ! $res->json('access_token')) {
            DB::table('so_accounts')->where('id', $acc->id)->update(['status' => 'expired', 'last_error' => 'Accès expiré : reconnectez ce compte.', 'updated_at' => now()]);
            $this->fail('L\'accès au compte '.$acc->name.' a expiré : reconnectez-le dans Réseaux sociaux › Comptes.');
        }
        $cr['access_token'] = (string) $res->json('access_token');
        $cr['expires_at'] = self::exp($res);
        if ($res->json('refresh_token')) {
            $cr['refresh_token'] = (string) $res->json('refresh_token'); // X et TikTok renouvellent aussi ce jeton
        }
        $enc = Crypt::encryptString(json_encode($cr));
        // Comptes issus de la même connexion (même jeton de rafraîchissement) : mis à jour ensemble
        $siblings = DB::table('so_accounts')->where('company_id', $acc->company_id)->get(['id', 'credentials'])
            ->filter(fn ($a) => $a->id === $acc->id || (($x = rescue(fn () => self::creds($a), [], false)) && $rt && ($x['refresh_token'] ?? null) === $rt))->pluck('id');
        DB::table('so_accounts')->whereIn('id', $siblings)->update(['credentials' => $enc, 'token_expires_at' => $cr['expires_at'] ? date('Y-m-d H:i:s', $cr['expires_at']) : null,
            'status' => 'active', 'last_error' => null, 'updated_at' => now()]);
        $acc->credentials = $enc;

        return $cr;
    }
}
