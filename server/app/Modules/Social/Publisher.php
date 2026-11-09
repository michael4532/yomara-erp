<?php

namespace App\Modules\Social;

use App\Models\Company;
use App\Models\User;
use App\Support\UrlGuard;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Publication sur chaque réseau. Chaque méthode reçoit le compte, le texte déjà adapté au réseau et les médias,
 * et renvoie ['id' => identifiant chez le réseau, 'url' => lien public (si connu)].
 */
class Publisher
{
    public const GRAPH = 'https://graph.facebook.com/v21.0';

    public const THREADS = 'https://graph.threads.net/v1.0';

    public const LINKEDIN = 'https://api.linkedin.com/rest';

    public const SENSYA_URL = 'https://sensya.yomaratechnologies.com';

    public function __construct(protected SocialOAuth $oauth) {}

    protected function fail(string $m): never
    {
        throw new \DomainException($m);
    }

    public static function linkedinHttp(string $token)
    {
        return Http::withToken($token)->acceptJson()->timeout(30)->withHeaders(['LinkedIn-Version' => '202501', 'X-Restli-Protocol-Version' => '2.0.0']);
    }

    protected function check($res, string $who): void
    {
        if (! $res->successful()) {
            $m = $res->json('error.error_user_msg') ?: $res->json('error.message') ?: $res->json('detail') ?: $res->json('message') ?: $res->json('errors.0.message')
                ?: $res->json('error.description') ?: $res->json('description') ?: (is_string($res->json('error')) ? $res->json('error') : null) ?: 'erreur '.$res->status();
            $code = $res->status() === 429 || $res->status() >= 500 ? ' (erreur '.$res->status().')' : '';
            $this->fail($who.' : '.mb_substr((string) $m, 0, 280).$code);
        }
    }

    // ------------------------------------------------------------------ connexion par jeton (Telegram, Mastodon, Bluesky, Sensya)

    public function connectToken(Company $c, User $u, string $network, array $d): array
    {
        return match ($network) {
            'telegram' => $this->connectTelegram($c, $u, $d),
            'mastodon' => $this->connectMastodon($c, $u, $d),
            'bluesky' => $this->connectBluesky($c, $u, $d),
            'sensya' => $this->connectSensya($c, $u, $d),
            default => $this->fail('Ce réseau se connecte avec le bouton « Connecter ».'),
        };
    }

    protected function connectTelegram(Company $c, User $u, array $d): array
    {
        $tok = trim((string) ($d['token'] ?? ''));
        $chat = trim((string) ($d['chat'] ?? ''));
        preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $tok) || $this->fail('Jeton du bot invalide : copiez celui donné par @BotFather (forme 123456:ABC…).');
        $chat !== '' || $this->fail('Indiquez le canal ou le groupe (@moncanal ou son identifiant numérique).');
        $me = Http::timeout(15)->get('https://api.telegram.org/bot'.$tok.'/getMe');
        $me->ok() || $this->fail('Telegram refuse ce jeton de bot.');
        $info = Http::timeout(15)->get('https://api.telegram.org/bot'.$tok.'/getChat', ['chat_id' => $chat]);
        $info->ok() || $this->fail('Le bot @'.$me->json('result.username').' n\'a pas accès à « '.$chat.' » : ajoutez-le comme administrateur du canal.');
        $r = $info->json('result');

        return [SocialOAuth::store($c, $u, 'telegram', (string) $r['id'], (string) ($r['title'] ?? $r['username'] ?? $chat), ['provider' => null, 'bot_token' => $tok],
            ['kind' => $r['type'] ?? 'channel', 'username' => $r['username'] ?? null, 'meta' => ['bot' => $me->json('result.username')]])];
    }

    protected function connectMastodon(Company $c, User $u, array $d): array
    {
        $base = rtrim(trim((string) ($d['instance'] ?? '')), '/');
        if ($base !== '' && ! str_starts_with($base, 'http')) {
            $base = 'https://'.$base;
        }
        (str_starts_with($base, 'https://') && UrlGuard::isPublic($base)) || $this->fail('Adresse de l\'instance invalide (ex. https://mastodon.social).');
        $tok = trim((string) ($d['token'] ?? ''));
        $me = Http::withToken($tok)->timeout(15)->get($base.'/api/v1/accounts/verify_credentials');
        $me->ok() || $this->fail('L\'instance refuse ce jeton (Préférences › Développement › Nouvelle application, droits write:statuses et write:media).');

        return [SocialOAuth::store($c, $u, 'mastodon', parse_url($base, PHP_URL_HOST).':'.$me->json('id'), (string) ($me->json('display_name') ?: $me->json('username')),
            ['provider' => null, 'access_token' => $tok, 'base' => $base], ['kind' => 'profile', 'username' => $me->json('acct'), 'avatar' => $me->json('avatar')])];
    }

    protected function connectBluesky(Company $c, User $u, array $d): array
    {
        $handle = ltrim(trim((string) ($d['handle'] ?? '')), '@');
        $pass = trim((string) ($d['password'] ?? ''));
        preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/i', $pass) || $this->fail('Utilisez un « mot de passe d\'application » Bluesky (Paramètres › Confidentialité et sécurité › Mots de passe d\'application), jamais votre mot de passe principal.');
        $s = Http::timeout(15)->post('https://bsky.social/xrpc/com.atproto.server.createSession', ['identifier' => $handle, 'password' => $pass]);
        $s->ok() || $this->fail('Bluesky refuse ces identifiants.');
        $p = Http::withToken((string) $s->json('accessJwt'))->timeout(15)->get('https://bsky.social/xrpc/app.bsky.actor.getProfile', ['actor' => $s->json('did')]);

        return [SocialOAuth::store($c, $u, 'bluesky', (string) $s->json('did'), (string) ($p->json('displayName') ?: $s->json('handle')),
            ['provider' => null, 'identifier' => $s->json('handle'), 'app_password' => $pass], ['kind' => 'profile', 'username' => $s->json('handle'), 'avatar' => $p->json('avatar')])];
    }

    protected function connectSensya(Company $c, User $u, array $d): array
    {
        $base = rtrim(trim((string) ($d['base_url'] ?? '')) ?: self::SENSYA_URL, '/');
        (str_starts_with($base, 'https://') && UrlGuard::isPublic($base)) || $this->fail('Adresse Sensya invalide.');
        $tok = trim((string) ($d['token'] ?? ''));
        $tok !== '' || $this->fail('Collez le jeton d\'accès créé dans Sensya › Paramètres › Applications connectées.');
        $me = Http::withToken($tok)->acceptJson()->timeout(15)->get($base.'/api/v1/external/me');
        $me->ok() || $this->fail('Sensya refuse ce jeton ('.$me->status().').');
        $m = $me->json('data') ?: $me->json();

        return [SocialOAuth::store($c, $u, 'sensya', (string) ($m['id'] ?? $m['username'] ?? 'sensya'), (string) ($m['name'] ?? $m['username'] ?? 'Sensya'),
            ['provider' => null, 'access_token' => $tok, 'base' => $base],
            ['kind' => $m['type'] ?? 'profile', 'username' => $m['username'] ?? null, 'avatar' => $m['avatar_url'] ?? $m['avatar'] ?? null, 'meta' => array_filter(['limit' => (int) ($m['max_length'] ?? 0) ?: null])])];
    }

    // ------------------------------------------------------------------ publication

    /**
     * @param  array  $media  [['kind' => image|video, 'url' => lien public, 'path' => chemin local, 'mime' => …], …]
     * @param  array  $o  options (title, link, first_comment, tiktok_privacy, youtube_privacy, board_id)
     */
    public function publish(object $acc, string $text, array $media, array $o = []): array
    {
        return $this->{'to'.ucfirst($acc->network)}($acc, $text, $media, $o);
    }

    protected function images(array $media): array
    {
        return array_values(array_filter($media, fn ($m) => $m['kind'] === 'image'));
    }

    protected function video(array $media): ?array
    {
        return collect($media)->firstWhere('kind', 'video');
    }

    protected function toFacebook(object $acc, string $text, array $media, array $o): array
    {
        $t = $this->oauth->token($acc);
        $page = $acc->external_id;
        if ($v = $this->video($media)) {
            $r = Http::asForm()->timeout(120)->post(self::GRAPH.'/'.$page.'/videos', ['file_url' => $v['url'], 'description' => $text, 'access_token' => $t]);
            $this->check($r, 'Facebook');
            $id = (string) $r->json('id');

            return $this->firstComment('facebook', $t, $id, $o) + ['id' => $id, 'url' => 'https://www.facebook.com/'.$page.'/videos/'.$id];
        }
        $imgs = $this->images($media);
        if (count($imgs) === 1) {
            $r = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$page.'/photos', ['url' => $imgs[0]['url'], 'caption' => $text, 'access_token' => $t]);
        } else {
            $att = [];
            foreach ($imgs as $i => $img) {
                $p = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$page.'/photos', ['url' => $img['url'], 'published' => 'false', 'access_token' => $t]);
                $this->check($p, 'Facebook');
                $att['attached_media['.$i.']'] = json_encode(['media_fbid' => $p->json('id')]);
            }
            $r = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$page.'/feed', array_filter(['message' => $text, 'link' => $imgs ? null : ($o['link'] ?? null), 'access_token' => $t]) + $att);
        }
        $this->check($r, 'Facebook');
        $id = (string) ($r->json('post_id') ?: $r->json('id'));
        $this->firstComment('facebook', $t, $id, $o);

        return ['id' => $id, 'url' => 'https://www.facebook.com/'.$id];
    }

    protected function firstComment(string $net, string $t, string $id, array $o): array
    {
        if (! empty($o['first_comment'])) {
            rescue(fn () => Http::asForm()->timeout(30)->post(($net === 'threads' ? self::THREADS : self::GRAPH).'/'.$id.'/comments', ['message' => $o['first_comment'], 'access_token' => $t]), null, false);
        }

        return [];
    }

    /** Conteneur Meta (Instagram / Threads) : attend la fin du traitement d'une vidéo avant de publier. */
    protected function waitContainer(string $base, string $id, string $t, string $field = 'status_code'): void
    {
        for ($i = 0; $i < 40; $i++) {
            $s = Http::timeout(15)->get($base.'/'.$id, ['fields' => $field, 'access_token' => $t])->json($field);
            if (in_array($s, ['FINISHED', 'PUBLISHED'], true) || $s === null) {
                return;
            }
            if (in_array($s, ['ERROR', 'EXPIRED'], true)) {
                $this->fail('Le réseau n\'a pas pu traiter la vidéo (format ou durée non acceptés).');
            }
            if (! app()->runningUnitTests()) {
                sleep(3);
            }
        }
        $this->fail('Le traitement de la vidéo prend trop de temps : la publication sera retentée.');
    }

    protected function toInstagram(object $acc, string $text, array $media, array $o): array
    {
        $media ?: $this->fail('Instagram exige au moins une image ou une vidéo.');
        $t = $this->oauth->token($acc);
        $ig = $acc->external_id;
        $item = fn (array $m, bool $child) => array_filter([
            'image_url' => $m['kind'] === 'image' ? $m['url'] : null, 'video_url' => $m['kind'] === 'video' ? $m['url'] : null,
            'media_type' => $m['kind'] === 'video' ? ($child ? 'VIDEO' : 'REELS') : null, 'is_carousel_item' => $child ? 'true' : null,
            'caption' => $child ? null : $text, 'access_token' => $t]);
        if (count($media) === 1) {
            $cr = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$ig.'/media', $item($media[0], false));
            $this->check($cr, 'Instagram');
            if ($media[0]['kind'] === 'video') {
                $this->waitContainer(self::GRAPH, (string) $cr->json('id'), $t);
            }
        } else {
            $kids = [];
            foreach (array_slice($media, 0, 10) as $m) {
                $k = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$ig.'/media', $item($m, true));
                $this->check($k, 'Instagram');
                if ($m['kind'] === 'video') {
                    $this->waitContainer(self::GRAPH, (string) $k->json('id'), $t);
                }
                $kids[] = $k->json('id');
            }
            $cr = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$ig.'/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => $text, 'access_token' => $t]);
            $this->check($cr, 'Instagram');
        }
        $pub = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$ig.'/media_publish', ['creation_id' => $cr->json('id'), 'access_token' => $t]);
        $this->check($pub, 'Instagram');
        $id = (string) $pub->json('id');
        $this->firstComment('instagram', $t, $id, $o);
        $link = rescue(fn () => Http::timeout(15)->get(self::GRAPH.'/'.$id, ['fields' => 'permalink', 'access_token' => $t])->json('permalink'), null, false);

        return ['id' => $id, 'url' => $link ?: 'https://www.instagram.com/'.$acc->username];
    }

    protected function toThreads(object $acc, string $text, array $media, array $o): array
    {
        $t = $this->oauth->token($acc);
        $uid = $acc->external_id;
        $one = fn (array $m, bool $child) => array_filter(['media_type' => strtoupper($m['kind']), 'image_url' => $m['kind'] === 'image' ? $m['url'] : null,
            'video_url' => $m['kind'] === 'video' ? $m['url'] : null, 'is_carousel_item' => $child ? 'true' : null, 'text' => $child ? null : $text, 'access_token' => $t]);
        if (! $media) {
            $cr = Http::asForm()->timeout(30)->post(self::THREADS.'/'.$uid.'/threads', ['media_type' => 'TEXT', 'text' => $text, 'access_token' => $t]);
        } elseif (count($media) === 1) {
            $cr = Http::asForm()->timeout(60)->post(self::THREADS.'/'.$uid.'/threads', $one($media[0], false));
        } else {
            $kids = [];
            foreach (array_slice($media, 0, 20) as $m) {
                $k = Http::asForm()->timeout(60)->post(self::THREADS.'/'.$uid.'/threads', $one($m, true));
                $this->check($k, 'Threads');
                $kids[] = $k->json('id');
            }
            $cr = Http::asForm()->timeout(60)->post(self::THREADS.'/'.$uid.'/threads', ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'text' => $text, 'access_token' => $t]);
        }
        $this->check($cr, 'Threads');
        if ($media) {
            $this->waitContainer(self::THREADS, (string) $cr->json('id'), $t, 'status');
        }
        $pub = Http::asForm()->timeout(60)->post(self::THREADS.'/'.$uid.'/threads_publish', ['creation_id' => $cr->json('id'), 'access_token' => $t]);
        $this->check($pub, 'Threads');
        $id = (string) $pub->json('id');
        $link = rescue(fn () => Http::timeout(15)->get(self::THREADS.'/'.$id, ['fields' => 'permalink', 'access_token' => $t])->json('permalink'), null, false);

        return ['id' => $id, 'url' => $link ?: 'https://www.threads.net/@'.$acc->username];
    }

    protected function toLinkedin(object $acc, string $text, array $media, array $o): array
    {
        $t = $this->oauth->token($acc);
        [$kind, $id] = explode(':', $acc->external_id, 2) + [1 => ''];
        $author = 'urn:li:'.($kind === 'organization' ? 'organization' : 'person').':'.$id;
        $body = ['author' => $author, 'commentary' => $this->linkedinEscape($text), 'visibility' => 'PUBLIC', 'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false,
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []]];
        if ($v = $this->video($media)) {
            $body['content'] = ['media' => ['id' => $this->linkedinVideo($t, $author, $v), 'title' => mb_substr((string) ($o['title'] ?? ''), 0, 200)]];
        } elseif ($imgs = $this->images($media)) {
            $urns = array_map(fn ($m) => $this->linkedinImage($t, $author, $m), array_slice($imgs, 0, 20));
            $body['content'] = count($urns) === 1 ? ['media' => ['id' => $urns[0]]] : ['multiImage' => ['images' => array_map(fn ($u) => ['id' => $u], $urns)]];
        } elseif (! empty($o['link'])) {
            $body['content'] = ['article' => ['source' => $o['link'], 'title' => mb_substr((string) ($o['title'] ?: $o['link']), 0, 200)]];
        }
        $r = self::linkedinHttp($t)->post(self::LINKEDIN.'/posts', $body);
        $this->check($r, 'LinkedIn');
        $urn = (string) ($r->header('x-restli-id') ?: $r->json('id'));

        return ['id' => $urn, 'url' => 'https://www.linkedin.com/feed/update/'.$urn];
    }

    /** LinkedIn interprète certains caractères (little text format) : on les protège. */
    protected function linkedinEscape(string $s): string
    {
        return preg_replace('/([\\\\|{}@\[\]()<>*_~])/u', '\\\\$1', $s);
    }

    protected function linkedinImage(string $t, string $author, array $m): string
    {
        $init = self::linkedinHttp($t)->post(self::LINKEDIN.'/images?action=initializeUpload', ['initializeUploadRequest' => ['owner' => $author]]);
        $this->check($init, 'LinkedIn');
        $up = Http::withToken($t)->timeout(120)->withBody(Utils::streamFor(fopen($m['path'], 'r')), $m['mime'])->put((string) $init->json('value.uploadUrl'));
        $this->check($up, 'LinkedIn (envoi de l\'image)');

        return (string) $init->json('value.image');
    }

    protected function linkedinVideo(string $t, string $author, array $m): string
    {
        $init = self::linkedinHttp($t)->post(self::LINKEDIN.'/videos?action=initializeUpload', ['initializeUploadRequest' => [
            'owner' => $author, 'fileSizeBytes' => filesize($m['path']), 'uploadCaptions' => false, 'uploadThumbnail' => false]]);
        $this->check($init, 'LinkedIn');
        $fh = fopen($m['path'], 'r');
        $etags = [];
        foreach ((array) $init->json('value.uploadInstructions') as $ins) {
            fseek($fh, (int) $ins['firstByte']);
            $chunk = fread($fh, (int) $ins['lastByte'] - (int) $ins['firstByte'] + 1);
            $put = Http::timeout(180)->withBody($chunk, 'application/octet-stream')->put($ins['uploadUrl']);
            $this->check($put, 'LinkedIn (envoi de la vidéo)');
            $etags[] = trim((string) $put->header('etag'), '"');
        }
        fclose($fh);
        $video = (string) $init->json('value.video');
        $fin = self::linkedinHttp($t)->post(self::LINKEDIN.'/videos?action=finalizeUpload', ['finalizeUploadRequest' => [
            'video' => $video, 'uploadToken' => (string) $init->json('value.uploadToken'), 'uploadedPartIds' => $etags]]);
        $this->check($fin, 'LinkedIn');

        return $video;
    }

    protected function toX(object $acc, string $text, array $media, array $o): array
    {
        $t = $this->oauth->token($acc);
        $ids = [];
        if ($v = $this->video($media)) {
            $ids[] = $this->xVideo($t, $v);
        } else {
            foreach (array_slice($this->images($media), 0, 4) as $img) {
                $r = Http::withToken($t)->timeout(120)->attach('media', fopen($img['path'], 'r'), basename($img['path']))
                    ->post('https://api.x.com/2/media/upload', ['media_category' => 'tweet_image', 'media_type' => $img['mime']]);
                $this->check($r, 'X (envoi de l\'image)');
                $ids[] = (string) ($r->json('data.id') ?: $r->json('media_id_string'));
            }
        }
        $r = Http::withToken($t)->timeout(30)->post('https://api.x.com/2/tweets', array_filter(['text' => $text, 'media' => $ids ? ['media_ids' => $ids] : null]));
        $this->check($r, 'X');
        $id = (string) $r->json('data.id');

        return ['id' => $id, 'url' => 'https://x.com/'.($acc->username ?: 'i').'/status/'.$id];
    }

    protected function xVideo(string $t, array $v): string
    {
        $init = Http::withToken($t)->timeout(30)->post('https://api.x.com/2/media/upload/initialize', ['media_type' => $v['mime'], 'total_bytes' => filesize($v['path']), 'media_category' => 'tweet_video']);
        $this->check($init, 'X (vidéo)');
        $id = (string) $init->json('data.id');
        $fh = fopen($v['path'], 'r');
        for ($i = 0; ! feof($fh); $i++) {
            $chunk = fread($fh, 4 * 1024 * 1024);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $a = Http::withToken($t)->timeout(180)->attach('media', $chunk, 'chunk')->post('https://api.x.com/2/media/upload/'.$id.'/append', ['segment_index' => $i]);
            $this->check($a, 'X (vidéo)');
        }
        fclose($fh);
        $fin = Http::withToken($t)->timeout(60)->post('https://api.x.com/2/media/upload/'.$id.'/finalize');
        $this->check($fin, 'X (vidéo)');
        for ($k = 0; $k < 40 && in_array($fin->json('data.processing_info.state'), ['pending', 'in_progress'], true); $k++) {
            app()->runningUnitTests() || sleep(max(1, (int) $fin->json('data.processing_info.check_after_secs', 3)));
            $fin = Http::withToken($t)->timeout(30)->get('https://api.x.com/2/media/upload', ['command' => 'STATUS', 'media_id' => $id]);
        }
        $fin->json('data.processing_info.state') === 'failed' && $this->fail('X n\'a pas accepté la vidéo (format, durée ou taille).');

        return $id;
    }

    protected function toTiktok(object $acc, string $text, array $media, array $o): array
    {
        $media ?: $this->fail('TikTok exige une vidéo ou des photos.');
        $t = $this->oauth->token($acc);
        $info = Http::withToken($t)->timeout(20)->post('https://open.tiktokapis.com/v2/post/publish/creator_info/query/');
        $this->check($info, 'TikTok');
        $levels = (array) $info->json('data.privacy_level_options');
        $privacy = in_array($o['tiktok_privacy'] ?? null, $levels, true) ? $o['tiktok_privacy'] : (in_array('PUBLIC_TO_EVERYONE', $levels, true) ? 'PUBLIC_TO_EVERYONE' : ($levels[0] ?? 'SELF_ONLY'));
        if ($v = $this->video($media)) {
            $r = Http::withToken($t)->timeout(30)->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                'post_info' => ['title' => mb_substr($text, 0, 2200), 'privacy_level' => $privacy, 'disable_comment' => false, 'disable_duet' => false, 'disable_stitch' => false],
                'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $v['url']]]);
        } else {
            $r = Http::withToken($t)->timeout(30)->post('https://open.tiktokapis.com/v2/post/publish/content/init/', [
                'post_info' => ['title' => mb_substr((string) ($o['title'] ?? ''), 0, 90), 'description' => mb_substr($text, 0, 4000), 'privacy_level' => $privacy, 'disable_comment' => false, 'auto_add_music' => true],
                'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => array_map(fn ($m) => $m['url'], array_slice($this->images($media), 0, 35))],
                'post_mode' => 'DIRECT_POST', 'media_type' => 'PHOTO']);
        }
        $this->check($r, 'TikTok');
        if (($r->json('error.code') ?? 'ok') !== 'ok') {
            $this->fail('TikTok : '.$r->json('error.message'));
        }

        // Traitement asynchrone chez TikTok : le lien public est récupéré lors du relevé des statistiques
        return ['id' => 'publish:'.$r->json('data.publish_id'), 'url' => $acc->username ? 'https://www.tiktok.com/@'.$acc->username : null];
    }

    protected function toPinterest(object $acc, string $text, array $media, array $o): array
    {
        $imgs = $this->images($media) ?: $this->fail('Pinterest exige une image.');
        $meta = json_decode($acc->meta ?? '{}', true) ?: [];
        $board = $o['board_id'] ?? $meta['board_id'] ?? null;
        $board ?: $this->fail('Choisissez un tableau Pinterest pour ce compte.');
        $src = count($imgs) === 1 ? ['source_type' => 'image_url', 'url' => $imgs[0]['url']]
            : ['source_type' => 'multiple_image_urls', 'items' => array_map(fn ($m) => ['url' => $m['url']], array_slice($imgs, 0, 5))];
        $r = Http::withToken($this->oauth->token($acc))->timeout(60)->post('https://api.pinterest.com/v5/pins', array_filter([
            'board_id' => (string) $board, 'title' => mb_substr((string) ($o['title'] ?? ''), 0, 100) ?: null, 'description' => mb_substr($text, 0, 500),
            'link' => $o['link'] ?? null, 'media_source' => $src]));
        $this->check($r, 'Pinterest');

        return ['id' => (string) $r->json('id'), 'url' => 'https://www.pinterest.com/pin/'.$r->json('id').'/'];
    }

    protected function toYoutube(object $acc, string $text, array $media, array $o): array
    {
        $v = $this->video($media) ?: $this->fail('YouTube exige une vidéo.');
        $t = $this->oauth->token($acc);
        $title = trim((string) ($o['title'] ?? '')) ?: mb_substr(strtok($text, "\n") ?: 'Vidéo', 0, 100);
        $init = Http::withToken($t)->timeout(30)->withHeaders(['X-Upload-Content-Type' => $v['mime'], 'X-Upload-Content-Length' => (string) filesize($v['path'])])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
                'snippet' => ['title' => mb_substr(str_replace(['<', '>'], '', $title), 0, 100), 'description' => mb_substr(str_replace(['<', '>'], '', $text), 0, 5000), 'categoryId' => '22'],
                'status' => ['privacyStatus' => in_array($o['youtube_privacy'] ?? '', ['public', 'unlisted', 'private'], true) ? $o['youtube_privacy'] : 'public', 'selfDeclaredMadeForKids' => false]]);
        $this->check($init, 'YouTube');
        $up = Http::withToken($t)->timeout(900)->withBody(Utils::streamFor(fopen($v['path'], 'r')), $v['mime'])->put((string) $init->header('Location'));
        $this->check($up, 'YouTube (envoi de la vidéo)');

        return ['id' => (string) $up->json('id'), 'url' => 'https://youtu.be/'.$up->json('id')];
    }

    protected function toGbp(object $acc, string $text, array $media, array $o): array
    {
        $img = $this->images($media)[0] ?? null;
        $r = Http::withToken($this->oauth->token($acc))->timeout(30)->post('https://mybusiness.googleapis.com/v4/'.$acc->external_id.'/localPosts', array_filter([
            'languageCode' => 'fr', 'summary' => mb_substr($text, 0, 1500), 'topicType' => 'STANDARD',
            'media' => $img ? [['mediaFormat' => 'PHOTO', 'sourceUrl' => $img['url']]] : null,
            'callToAction' => ! empty($o['link']) ? ['actionType' => 'LEARN_MORE', 'url' => $o['link']] : null]));
        $this->check($r, 'Google Business Profile');

        return ['id' => (string) $r->json('name'), 'url' => $r->json('searchUrl')];
    }

    protected function toTelegram(object $acc, string $text, array $media, array $o): array
    {
        $cr = SocialOAuth::creds($acc);
        $api = 'https://api.telegram.org/bot'.$cr['bot_token'].'/';
        $chat = $acc->external_id;
        if (! $media) {
            $r = Http::timeout(30)->post($api.'sendMessage', ['chat_id' => $chat, 'text' => $text]);
        } elseif (count($media) === 1) {
            $m = $media[0];
            $field = $m['kind'] === 'video' ? 'video' : 'photo';
            $r = Http::timeout(180)->attach($field, fopen($m['path'], 'r'), basename($m['path']))->post($api.($m['kind'] === 'video' ? 'sendVideo' : 'sendPhoto'), ['chat_id' => $chat, 'caption' => $text]);
        } else {
            $req = Http::timeout(300);
            $items = [];
            foreach (array_slice($media, 0, 10) as $i => $m) {
                $req = $req->attach('f'.$i, fopen($m['path'], 'r'), basename($m['path']));
                $items[] = array_filter(['type' => $m['kind'] === 'video' ? 'video' : 'photo', 'media' => 'attach://f'.$i, 'caption' => $i === 0 ? $text : null]);
            }
            $r = $req->post($api.'sendMediaGroup', ['chat_id' => $chat, 'media' => json_encode($items)]);
        }
        $this->check($r, 'Telegram');
        $msg = $r->json('result.message_id') ?? $r->json('result.0.message_id');

        return ['id' => (string) $msg, 'url' => $acc->username ? 'https://t.me/'.$acc->username.'/'.$msg : null];
    }

    protected function toMastodon(object $acc, string $text, array $media, array $o): array
    {
        $cr = SocialOAuth::creds($acc);
        UrlGuard::isPublic($cr['base']) || $this->fail('Instance Mastodon non autorisée.');
        $ids = [];
        foreach (array_slice($media, 0, 4) as $m) {
            $u = Http::withToken($cr['access_token'])->timeout(180)->attach('file', fopen($m['path'], 'r'), basename($m['path']))->post($cr['base'].'/api/v2/media');
            $this->check($u, 'Mastodon (média)');
            $ids[] = (string) $u->json('id');
        }
        $r = Http::withToken($cr['access_token'])->timeout(30)->withHeaders(['Idempotency-Key' => sha1($acc->id.$text.implode(',', $ids))])
            ->post($cr['base'].'/api/v1/statuses', array_filter(['status' => $text, 'media_ids' => $ids ?: null, 'visibility' => 'public']));
        $this->check($r, 'Mastodon');

        return ['id' => (string) $r->json('id'), 'url' => $r->json('url')];
    }

    protected function toBluesky(object $acc, string $text, array $media, array $o): array
    {
        $cr = SocialOAuth::creds($acc);
        $s = Http::timeout(20)->post('https://bsky.social/xrpc/com.atproto.server.createSession', ['identifier' => $cr['identifier'], 'password' => $cr['app_password']]);
        $this->check($s, 'Bluesky');
        $jwt = (string) $s->json('accessJwt');
        $record = ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'langs' => ['fr']];
        if ($facets = $this->blueskyFacets($text)) {
            $record['facets'] = $facets;
        }
        $imgs = array_slice($this->images($media), 0, 4);
        if ($imgs) {
            $list = [];
            foreach ($imgs as $img) {
                filesize($img['path']) <= 1_000_000 || $this->fail('Bluesky : chaque image doit faire moins de 1 Mo.');
                $b = Http::withToken($jwt)->timeout(60)->withBody(file_get_contents($img['path']), $img['mime'])->post('https://bsky.social/xrpc/com.atproto.repo.uploadBlob');
                $this->check($b, 'Bluesky (image)');
                $list[] = ['alt' => '', 'image' => $b->json('blob')];
            }
            $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $list];
        } elseif (! empty($o['link'])) {
            $record['embed'] = ['$type' => 'app.bsky.embed.external', 'external' => ['uri' => $o['link'], 'title' => mb_substr((string) ($o['title'] ?: $o['link']), 0, 300), 'description' => '']];
        }
        $r = Http::withToken($jwt)->timeout(30)->post('https://bsky.social/xrpc/com.atproto.repo.createRecord', ['repo' => $s->json('did'), 'collection' => 'app.bsky.feed.post', 'record' => $record]);
        $this->check($r, 'Bluesky');
        $uri = (string) $r->json('uri');

        return ['id' => $uri, 'url' => 'https://bsky.app/profile/'.$s->json('handle').'/post/'.basename($uri)];
    }

    /** Liens et hashtags cliquables sur Bluesky (positions en octets UTF-8). */
    protected function blueskyFacets(string $text): array
    {
        $out = [];
        preg_match_all('~https?://[^\s<>"]+~u', $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$url, $pos]) {
            $url = rtrim($url, '.,;:!?)');
            $out[] = ['index' => ['byteStart' => $pos, 'byteEnd' => $pos + strlen($url)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]];
        }
        preg_match_all('/(?<![\w#])#([\p{L}\p{N}_]{1,64})/u', $text, $h, PREG_OFFSET_CAPTURE);
        foreach ($h[0] as $i => [$tag, $pos]) {
            $out[] = ['index' => ['byteStart' => $pos, 'byteEnd' => $pos + strlen($tag)], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $h[1][$i][0]]]];
        }

        return $out;
    }

    protected function toSensya(object $acc, string $text, array $media, array $o): array
    {
        $cr = SocialOAuth::creds($acc);
        UrlGuard::isPublic($cr['base']) || $this->fail('Adresse Sensya non autorisée.');
        $req = Http::withToken($cr['access_token'])->acceptJson()->timeout(300)->withHeaders(['Idempotency-Key' => 'yomara-'.($o['target_id'] ?? sha1($text))]);
        foreach (array_slice($media, 0, 10) as $i => $m) {
            $req = $req->attach('media[]', fopen($m['path'], 'r'), basename($m['path']));
        }
        $r = $req->post($cr['base'].'/api/v1/external/posts', array_filter(['text' => $text, 'title' => $o['title'] ?? null, 'link' => $o['link'] ?? null, 'source' => 'yomara-erp']));
        $this->check($r, 'Sensya');
        $d = $r->json('data') ?: $r->json();

        return ['id' => (string) ($d['id'] ?? ''), 'url' => $d['url'] ?? null];
    }

    // ------------------------------------------------------------------ statistiques

    /** Interactions d'une publication : ['likes', 'comments', 'shares', 'views'] (null si le réseau ne les fournit pas). */
    public function stats(object $acc, object $target): ?array
    {
        $id = (string) $target->external_id;
        $num = fn ($v) => $v === null ? null : (int) $v;

        return match ($acc->network) {
            'facebook' => ($r = Http::timeout(15)->get(self::GRAPH.'/'.$id, ['fields' => 'likes.summary(true).limit(0),comments.summary(true).limit(0),shares', 'access_token' => $this->oauth->token($acc)]))->ok()
                ? ['likes' => $num($r->json('likes.summary.total_count')), 'comments' => $num($r->json('comments.summary.total_count')), 'shares' => $num($r->json('shares.count') ?? 0)] : null,
            'instagram' => ($r = Http::timeout(15)->get(self::GRAPH.'/'.$id, ['fields' => 'like_count,comments_count', 'access_token' => $this->oauth->token($acc)]))->ok()
                ? ['likes' => $num($r->json('like_count')), 'comments' => $num($r->json('comments_count'))] : null,
            'threads' => ($r = Http::timeout(15)->get(self::THREADS.'/'.$id.'/insights', ['metric' => 'views,likes,replies,reposts', 'access_token' => $this->oauth->token($acc)]))->ok()
                ? collect((array) $r->json('data'))->mapWithKeys(fn ($m) => [['views' => 'views', 'likes' => 'likes', 'replies' => 'comments', 'reposts' => 'shares'][$m['name']] ?? $m['name'] => (int) ($m['values'][0]['value'] ?? 0)])->all() : null,
            'x' => ($r = Http::withToken($this->oauth->token($acc))->timeout(15)->get('https://api.x.com/2/tweets/'.$id, ['tweet.fields' => 'public_metrics']))->ok()
                ? ['likes' => $num($r->json('data.public_metrics.like_count')), 'comments' => $num($r->json('data.public_metrics.reply_count')),
                    'shares' => $num($r->json('data.public_metrics.retweet_count')), 'views' => $num($r->json('data.public_metrics.impression_count'))] : null,
            'youtube' => ($r = Http::withToken($this->oauth->token($acc))->timeout(15)->get('https://www.googleapis.com/youtube/v3/videos', ['part' => 'statistics', 'id' => $id]))->ok()
                ? ['likes' => $num($r->json('items.0.statistics.likeCount')), 'comments' => $num($r->json('items.0.statistics.commentCount')), 'views' => $num($r->json('items.0.statistics.viewCount'))] : null,
            'mastodon' => ($cr = SocialOAuth::creds($acc)) && ($r = Http::withToken($cr['access_token'])->timeout(15)->get($cr['base'].'/api/v1/statuses/'.$id))->ok()
                ? ['likes' => $num($r->json('favourites_count')), 'comments' => $num($r->json('replies_count')), 'shares' => $num($r->json('reblogs_count'))] : null,
            'sensya' => ($cr = SocialOAuth::creds($acc)) && ($r = Http::withToken($cr['access_token'])->acceptJson()->timeout(15)->get($cr['base'].'/api/v1/external/posts/'.rawurlencode($id)))->ok()
                ? array_map(fn ($v) => $v === null ? null : (int) $v, array_intersect_key((array) ($r->json('data.stats') ?? $r->json('stats') ?? []), array_flip(['likes', 'comments', 'shares', 'views']))) : null,
            'tiktok' => $this->tiktokStatus($acc, $target),
            default => null,
        };
    }

    /** TikTok publie en différé : on récupère l'identifiant public de la vidéo une fois en ligne. */
    protected function tiktokStatus(object $acc, object $target): ?array
    {
        if (! str_starts_with((string) $target->external_id, 'publish:')) {
            return null;
        }
        $r = Http::withToken($this->oauth->token($acc))->timeout(15)->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', ['publish_id' => substr($target->external_id, 8)]);
        $status = $r->json('data.status');
        if ($status === 'PUBLISH_COMPLETE' && ($vid = $r->json('data.publicaly_available_post_id.0'))) {
            DB::table('so_targets')->where('id', $target->id)->update(['external_id' => (string) $vid, 'url' => $acc->username ? 'https://www.tiktok.com/@'.$acc->username.'/video/'.$vid : null]);
        } elseif ($status === 'FAILED') {
            DB::table('so_targets')->where('id', $target->id)->update(['status' => 'failed', 'error' => 'TikTok : '.mb_substr((string) $r->json('data.fail_reason'), 0, 400)]);
        }

        return null;
    }

    public static function encrypt(array $creds): string
    {
        return Crypt::encryptString(json_encode($creds));
    }
}
