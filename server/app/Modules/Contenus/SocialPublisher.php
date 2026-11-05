<?php

namespace App\Modules\Contenus;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Publication du calendrier éditorial sur les réseaux : page Facebook et compte Instagram professionnel (Graph API de Meta),
 * page entreprise LinkedIn (API Posts). Publication automatique à l'heure prévue et relevé de la portée et des interactions.
 */
class SocialPublisher
{
    public const GRAPH = 'https://graph.facebook.com/v21.0';

    public const LINKEDIN = 'https://api.linkedin.com/rest';

    public const LINKEDIN_VERSION = '202410';

    public const PLATFORMS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn'];

    protected function token(object $acc): string
    {
        return json_decode(Crypt::decryptString($acc->credentials), true)['token'] ?? '';
    }

    protected function fail(string $msg): never
    {
        throw new \DomainException($msg);
    }

    protected function graphError($res): string
    {
        return (string) ($res->json('error.error_user_msg') ?: $res->json('error.message') ?: 'Erreur '.$res->status());
    }

    protected function linkedin(string $token)
    {
        return Http::withToken($token)->acceptJson()->timeout(20)->withHeaders(['LinkedIn-Version' => self::LINKEDIN_VERSION, 'X-Restli-Protocol-Version' => '2.0.0']);
    }

    // ------------------------------------------------------------------ connexion des comptes

    /** Page Facebook (jeton d'accès de page) et, si elle est reliée, le compte Instagram professionnel. */
    public function connectMeta(Company $c, User $u, string $token, string $pageId): array
    {
        $res = Http::timeout(20)->get(self::GRAPH.'/'.rawurlencode(trim($pageId)), ['fields' => 'id,name,instagram_business_account{id,username}', 'access_token' => trim($token)]);
        if (! $res->ok()) {
            $this->fail('Meta refuse la connexion : '.$this->graphError($res));
        }
        $out = [$this->store($c, $u, 'facebook', (string) $res->json('id'), (string) $res->json('name'), null, $token)];
        if ($ig = $res->json('instagram_business_account.id')) {
            $out[] = $this->store($c, $u, 'instagram', (string) $ig, '@'.$res->json('instagram_business_account.username'), $res->json('instagram_business_account.username'), $token);
        }

        return $out;
    }

    /** Page entreprise LinkedIn (jeton avec le droit w_organization_social). */
    public function connectLinkedIn(Company $c, User $u, string $token, string $orgId): array
    {
        $orgId = preg_replace('/\D/', '', $orgId);
        $res = $this->linkedin(trim($token))->get(self::LINKEDIN.'/organizations/'.$orgId);
        if (! $res->ok()) {
            $this->fail('LinkedIn refuse la connexion : '.($res->json('message') ?: 'erreur '.$res->status()));
        }

        return [$this->store($c, $u, 'linkedin', $orgId, (string) ($res->json('localizedName') ?: 'Page LinkedIn '.$orgId), $res->json('vanityName'), $token)];
    }

    protected function store(Company $c, User $u, string $platform, string $id, string $name, ?string $username, string $token): array
    {
        DB::table('mc_social_accounts')->updateOrInsert(['company_id' => $c->id, 'platform' => $platform, 'external_id' => $id], [
            'name' => $name, 'username' => $username, 'credentials' => Crypt::encryptString(json_encode(['token' => trim($token)])), 'status' => 'active', 'last_error' => null,
            'connected_by' => $u->id, 'updated_at' => now(), 'created_at' => now()]);

        return ['platform' => $platform, 'name' => $name];
    }

    public function accounts(Company $c): array
    {
        return DB::table('mc_social_accounts')->where('company_id', $c->id)->orderBy('platform')->get(['id', 'platform', 'name', 'username', 'external_id', 'status', 'last_error', 'updated_at'])
            ->map(fn ($a) => (array) $a + ['platform_label' => self::PLATFORMS[$a->platform] ?? $a->platform])->all();
    }

    // ------------------------------------------------------------------ publication

    protected function message(object $p): string
    {
        return trim(trim((string) $p->caption)."\n\n".trim((string) $p->hashtags)) ?: $p->title;
    }

    /** Publie la publication sur les réseaux cochés qui sont connectés. Renvoie les résultats par réseau. */
    public function publish(Company $c, int $postId, ?User $by = null): array
    {
        $p = DB::table('mc_posts')->where('company_id', $c->id)->where('id', $postId)->first() ?: $this->fail('Publication introuvable.');
        $nets = array_values(array_intersect(json_decode($p->networks ?? '[]', true) ?: [], array_keys(self::PLATFORMS)));
        $accounts = DB::table('mc_social_accounts')->where('company_id', $c->id)->where('status', '!=', 'disabled')->whereIn('platform', $nets)->get()->keyBy('platform');
        if ($accounts->isEmpty()) {
            $this->fail('Aucun des réseaux cochés (Facebook, Instagram, LinkedIn) n\'est connecté.');
        }
        $results = json_decode($p->social ?? '[]', true) ?: [];
        foreach ($accounts as $net => $acc) {
            if (! empty($results[$net]['id'])) {
                continue; // déjà publiée sur ce réseau
            }
            try {
                $results[$net] = $this->{'publish'.ucfirst($net)}($acc, $p) + ['at' => now()->toIso8601String()];
                DB::table('mc_social_accounts')->where('id', $acc->id)->update(['status' => 'active', 'last_error' => null]);
            } catch (\Throwable $e) {
                $results[$net] = ['error' => mb_substr($e->getMessage(), 0, 300), 'at' => now()->toIso8601String()];
                DB::table('mc_social_accounts')->where('id', $acc->id)->update(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            }
        }
        $ok = collect($results)->filter(fn ($r) => ! empty($r['id']));
        $set = ['social' => json_encode($results), 'updated_at' => now()];
        if ($ok->isNotEmpty()) {
            $set += ['status' => 'published', 'published_url' => $p->published_url ?: $ok->first()['url'] ?? null];
        }
        DB::table('mc_posts')->where('id', $p->id)->update($set);
        $errors = collect($results)->filter(fn ($r) => ! empty($r['error']));
        if ($errors->isNotEmpty()) {
            AppNotification::send($c->id, [$p->owner_id], 'contenus', 'Publication non publiée sur '.$errors->keys()->map(fn ($k) => self::PLATFORMS[$k])->implode(', '),
                '« '.$p->title.' » : '.$errors->first()['error'], 'contenus', (string) $p->id, $by?->id);
        }

        return $results;
    }

    protected function publishFacebook(object $acc, object $p): array
    {
        $t = $this->token($acc);
        $msg = $this->message($p);
        $res = $p->visual_url
            ? Http::asForm()->timeout(30)->post(self::GRAPH.'/'.$acc->external_id.'/photos', ['url' => $p->visual_url, 'caption' => $msg.($p->link_url ? "\n".$p->link_url : ''), 'access_token' => $t])
            : Http::asForm()->timeout(30)->post(self::GRAPH.'/'.$acc->external_id.'/feed', array_filter(['message' => $msg, 'link' => $p->link_url, 'access_token' => $t]));
        if (! $res->ok()) {
            $this->fail($this->graphError($res));
        }
        $id = (string) ($res->json('post_id') ?: $res->json('id'));

        return ['id' => $id, 'url' => 'https://www.facebook.com/'.$id];
    }

    protected function publishInstagram(object $acc, object $p): array
    {
        if (! $p->visual_url) {
            $this->fail('Instagram exige un visuel : renseignez le lien public de l\'image.');
        }
        $t = $this->token($acc);
        $isVideo = (bool) preg_match('/\.(mp4|mov)(\?|$)/i', $p->visual_url);
        $c = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$acc->external_id.'/media', array_filter([
            'image_url' => $isVideo ? null : $p->visual_url, 'video_url' => $isVideo ? $p->visual_url : null, 'media_type' => $isVideo ? 'REELS' : null, 'caption' => $this->message($p), 'access_token' => $t]));
        if (! $c->ok()) {
            $this->fail($this->graphError($c));
        }
        $pub = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.$acc->external_id.'/media_publish', ['creation_id' => $c->json('id'), 'access_token' => $t]);
        if (! $pub->ok()) {
            $this->fail($this->graphError($pub));
        }
        $id = (string) $pub->json('id');
        $link = Http::timeout(15)->get(self::GRAPH.'/'.$id, ['fields' => 'permalink', 'access_token' => $t])->json('permalink');

        return ['id' => $id, 'url' => $link ?: 'https://www.instagram.com/'.$acc->username];
    }

    protected function publishLinkedin(object $acc, object $p): array
    {
        $body = [
            'author' => 'urn:li:organization:'.$acc->external_id, 'commentary' => $this->message($p), 'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false,
        ];
        if ($p->link_url) {
            $body['content'] = ['article' => ['source' => $p->link_url, 'title' => $p->title]];
        }
        $res = $this->linkedin($this->token($acc))->post(self::LINKEDIN.'/posts', $body);
        if (! $res->successful()) {
            $this->fail((string) ($res->json('message') ?: 'Erreur LinkedIn '.$res->status()));
        }
        $urn = (string) ($res->header('x-restli-id') ?: $res->json('id'));

        return ['id' => $urn, 'url' => 'https://www.linkedin.com/feed/update/'.$urn];
    }

    /** Publications validées dont l'heure est venue (publication automatique cochée). */
    public function due(Company $c): int
    {
        $n = 0;
        foreach (DB::table('mc_posts')->where('company_id', $c->id)->where('auto_publish', true)->where('status', 'approved')->where('scheduled_at', '<=', now())
            ->where('scheduled_at', '>=', now()->subDays(2))->pluck('id') as $id) {
            rescue(fn () => $this->publish($c, $id), null, false);
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ statistiques

    /** Portée et interactions des publications des 30 derniers jours, additionnées sur les réseaux. */
    public function syncStats(Company $c, ?int $postId = null): int
    {
        $accounts = DB::table('mc_social_accounts')->where('company_id', $c->id)->get()->keyBy('platform');
        $n = 0;
        $q = DB::table('mc_posts')->where('company_id', $c->id)->whereNotNull('social')->where('status', 'published')
            ->when($postId, fn ($w) => $w->where('id', $postId), fn ($w) => $w->where('scheduled_at', '>=', now()->subDays(30)));
        foreach ($q->get() as $p) {
            $reach = 0;
            $eng = 0;
            $found = false;
            foreach (json_decode($p->social, true) ?: [] as $net => $r) {
                $acc = $accounts[$net] ?? null;
                if (empty($r['id']) || ! $acc) {
                    continue;
                }
                $s = rescue(fn () => $this->{'stats'.ucfirst($net)}($acc, $r['id']), null, false);
                if ($s) {
                    $reach += $s['reach'];
                    $eng += $s['engagements'];
                    $found = true;
                }
            }
            if ($found) {
                DB::table('mc_posts')->where('id', $p->id)->update(['reach' => $reach ?: $p->reach, 'engagements' => $eng, 'stats_synced_at' => now()]);
                $n++;
            }
        }

        return $n;
    }

    protected function statsFacebook(object $acc, string $id): ?array
    {
        $res = Http::timeout(15)->get(self::GRAPH.'/'.$id, ['fields' => 'insights.metric(post_impressions_unique),reactions.summary(true).limit(0),comments.summary(true).limit(0),shares', 'access_token' => $this->token($acc)]);
        if (! $res->ok()) {
            return null;
        }

        return ['reach' => (int) $res->json('insights.data.0.values.0.value', 0),
            'engagements' => (int) $res->json('reactions.summary.total_count', 0) + (int) $res->json('comments.summary.total_count', 0) + (int) $res->json('shares.count', 0)];
    }

    protected function statsInstagram(object $acc, string $id): ?array
    {
        $res = Http::timeout(15)->get(self::GRAPH.'/'.$id.'/insights', ['metric' => 'reach,total_interactions', 'access_token' => $this->token($acc)]);
        if (! $res->ok()) {
            return null;
        }
        $m = collect($res->json('data', []))->mapWithKeys(fn ($d) => [$d['name'] => (int) ($d['values'][0]['value'] ?? $d['total_value']['value'] ?? 0)]);

        return ['reach' => $m['reach'] ?? 0, 'engagements' => $m['total_interactions'] ?? 0];
    }

    protected function statsLinkedin(object $acc, string $urn): ?array
    {
        $res = $this->linkedin($this->token($acc))->get(self::LINKEDIN.'/socialActions/'.rawurlencode($urn));
        if (! $res->ok()) {
            return null;
        }

        return ['reach' => 0, 'engagements' => (int) $res->json('likesSummary.totalLikes', 0) + (int) $res->json('commentsSummary.aggregatedTotalComments', $res->json('commentsSummary.totalFirstLevelComments', 0))];
    }
}
