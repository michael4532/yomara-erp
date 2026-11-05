<?php

namespace App\Modules\Social;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Publications multi-réseaux : brouillons, validation, planification, publication simultanée, relances et statistiques. */
class SocialService
{
    public const STATUSES = [
        'draft' => 'Brouillon', 'pending' => 'À valider', 'scheduled' => 'Planifiée', 'publishing' => 'Publication en cours',
        'published' => 'Publiée', 'partial' => 'Publiée en partie', 'failed' => 'Échec', 'cancelled' => 'Annulée',
    ];

    public const MAX_ATTEMPTS = 3;

    /** Heures d'audience habituelles (heure locale) quand il n'y a pas encore assez d'historique. */
    public const DEFAULT_TIMES = [
        'facebook' => ['13:00', '19:00'], 'instagram' => ['11:00', '19:30'], 'threads' => ['12:00', '20:00'], 'linkedin' => ['08:30', '12:15'],
        'x' => ['09:00', '17:00'], 'tiktok' => ['18:00', '21:00'], 'pinterest' => ['20:00', '21:30'], 'youtube' => ['17:00', '19:00'],
        'gbp' => ['10:00'], 'telegram' => ['09:00', '18:00'], 'mastodon' => ['10:00', '18:00'], 'bluesky' => ['09:00', '18:00'], 'sensya' => ['12:00', '19:00'],
    ];

    public function __construct(protected Publisher $publisher) {}

    protected function fail(string $m): never
    {
        throw new \DomainException($m);
    }

    // ------------------------------------------------------------------ comptes

    public function accounts(Company $c): array
    {
        return DB::table('so_accounts')->where('company_id', $c->id)->orderBy('network')->orderBy('name')
            ->get(['id', 'network', 'external_id', 'name', 'username', 'avatar_url', 'kind', 'meta', 'status', 'last_error', 'token_expires_at', 'updated_at'])
            ->map(fn ($a) => ['id' => $a->id, 'network' => $a->network, 'network_label' => Networks::label($a->network), 'name' => $a->name, 'username' => $a->username,
                'avatar_url' => $a->avatar_url, 'kind' => $a->kind, 'status' => $a->status, 'last_error' => $a->last_error, 'token_expires_at' => $a->token_expires_at,
                'limit' => Networks::limit($a->network, false, ['meta' => json_decode($a->meta ?? '{}', true)]),
                'boards' => $a->network === 'pinterest' ? (json_decode($a->meta ?? '{}', true)['boards'] ?? []) : null,
                'board_id' => $a->network === 'pinterest' ? (json_decode($a->meta ?? '{}', true)['board_id'] ?? null) : null])->all();
    }

    // ------------------------------------------------------------------ médias

    public function upload(Company $c, User $u, UploadedFile $f): array
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $f->getRealPath()); // type détecté sur le contenu, pas sur le nom
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov'][$mime]
            ?? $this->fail('Format non accepté : images JPG, PNG, WEBP, GIF ou vidéos MP4, MOV.');
        $kind = str_starts_with($mime, 'video/') ? 'video' : 'image';
        if ($kind === 'image' && $f->getSize() > 8 * 1024 * 1024) {
            $this->fail('Image trop lourde (8 Mo au maximum).');
        }
        $dir = 'uploads/social/'.$c->id;
        File::ensureDirectoryExists(public_path($dir));
        $name = Str::random(32).'.'.$ext;
        $f->move(public_path($dir), $name);
        $size = @getimagesize(public_path($dir.'/'.$name)) ?: [null, null];
        $id = DB::table('so_media')->insertGetId(['company_id' => $c->id, 'user_id' => $u->id, 'kind' => $kind, 'path' => $dir.'/'.$name, 'mime' => $mime,
            'size' => filesize(public_path($dir.'/'.$name)), 'width' => $size[0], 'height' => $size[1], 'original_name' => mb_substr($f->getClientOriginalName(), 0, 190),
            'created_at' => now(), 'updated_at' => now()]);

        return $this->mediaRow(DB::table('so_media')->find($id));
    }

    public function mediaRow(object $m): array
    {
        return ['id' => $m->id, 'kind' => $m->kind, 'url' => url($m->path), 'mime' => $m->mime, 'size' => (int) $m->size, 'width' => $m->width, 'height' => $m->height, 'name' => $m->original_name];
    }

    /** Médias d'une publication, dans l'ordre, avec chemin local et lien public. */
    protected function mediaFor(object $post): array
    {
        $ids = array_map('intval', json_decode($post->media ?? '[]', true) ?: []);
        if (! $ids) {
            return [];
        }
        $rows = DB::table('so_media')->where('company_id', $post->company_id)->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $rows[$id] ?? null)->filter()->map(fn ($m) => ['kind' => $m->kind, 'url' => url($m->path), 'path' => public_path($m->path), 'mime' => $m->mime])->values()->all();
    }

    public function deleteMedia(Company $c, int $id): void
    {
        $m = DB::table('so_media')->where('company_id', $c->id)->where('id', $id)->first() ?: $this->fail('Média introuvable.');
        $used = DB::table('so_posts')->where('company_id', $c->id)->whereIn('status', ['draft', 'pending', 'scheduled', 'publishing'])->get(['media'])
            ->contains(fn ($p) => in_array($id, array_map('intval', json_decode($p->media ?? '[]', true) ?: []), true));
        $used && $this->fail('Ce média est utilisé par une publication à venir.');
        if (str_starts_with($m->path, 'uploads/social/'.$c->id.'/')) {
            File::delete(public_path($m->path));
        }
        DB::table('so_media')->where('id', $id)->delete();
    }

    // ------------------------------------------------------------------ publications

    public function show(Company $c, int $id): array
    {
        $p = DB::table('so_posts')->where('company_id', $c->id)->where('id', $id)->first() ?: $this->fail('Publication introuvable.');

        return $this->row($p, true);
    }

    public function row(object $p, bool $full = false): array
    {
        $targets = DB::table('so_targets as t')->join('so_accounts as a', 'a.id', '=', 't.account_id')->where('t.post_id', $p->id)
            ->get(['t.id', 't.account_id', 't.network', 't.status', 't.url', 't.error', 't.attempts', 't.published_at', 't.stats', 'a.name', 'a.username', 'a.avatar_url']);
        $mediaIds = array_map('intval', json_decode($p->media ?? '[]', true) ?: []);
        $media = $mediaIds ? DB::table('so_media')->where('company_id', $p->company_id)->whereIn('id', $mediaIds)->get()->keyBy('id') : collect();
        $stats = ['likes' => 0, 'comments' => 0, 'shares' => 0, 'views' => 0];
        foreach ($targets as $t) {
            foreach ((array) json_decode($t->stats ?? '[]', true) as $k => $v) {
                if (isset($stats[$k])) {
                    $stats[$k] += (int) $v;
                }
            }
        }

        return [
            'id' => $p->id, 'title' => $p->title, 'body' => $p->body, 'link_url' => $p->link_url, 'status' => $p->status, 'status_label' => self::STATUSES[$p->status] ?? $p->status,
            'scheduled_at' => $p->scheduled_at ? \Illuminate\Support\Carbon::parse($p->scheduled_at)->toIso8601String() : null,
            'published_at' => $p->published_at ? \Illuminate\Support\Carbon::parse($p->published_at)->toIso8601String() : null,
            'campaign' => $p->campaign, 'rejection' => $p->rejection, 'user_id' => $p->user_id,
            'user_name' => $p->user_id ? DB::table('users')->where('id', $p->user_id)->value('name') : null,
            'variants' => json_decode($p->variants ?? '{}', true) ?: (object) [], 'options' => json_decode($p->options ?? '{}', true) ?: (object) [],
            'media' => collect($mediaIds)->map(fn ($id) => isset($media[$id]) ? $this->mediaRow($media[$id]) : null)->filter()->values()->all(),
            'targets' => $targets->map(fn ($t) => ['id' => $t->id, 'account_id' => $t->account_id, 'network' => $t->network, 'network_label' => Networks::label($t->network),
                'account' => $t->name, 'username' => $t->username, 'avatar_url' => $t->avatar_url, 'status' => $t->status, 'url' => $t->url, 'error' => $t->error,
                'attempts' => $t->attempts, 'published_at' => $t->published_at, 'stats' => json_decode($t->stats ?? 'null', true)])->all(),
            'stats' => $stats, 'created_at' => $p->created_at, 'updated_at' => $p->updated_at,
        ];
    }

    public function list(Company $c, array $f): array
    {
        $q = DB::table('so_posts')->where('company_id', $c->id);
        if (! empty($f['status'])) {
            $q->whereIn('status', (array) $f['status']);
        }
        if (! empty($f['from'])) {
            $q->where(fn ($w) => $w->where('scheduled_at', '>=', $f['from'])->orWhere('published_at', '>=', $f['from']));
        }
        if (! empty($f['to'])) {
            $q->where(fn ($w) => $w->where('scheduled_at', '<=', $f['to'])->orWhere('published_at', '<=', $f['to']));
        }
        if (! empty($f['q'])) {
            $q->where(fn ($w) => $w->where('body', 'like', '%'.$f['q'].'%')->orWhere('title', 'like', '%'.$f['q'].'%')->orWhere('campaign', 'like', '%'.$f['q'].'%'));
        }
        if (! empty($f['network'])) {
            $q->whereExists(fn ($e) => $e->from('so_targets')->whereColumn('so_targets.post_id', 'so_posts.id')->where('so_targets.network', $f['network']));
        }
        $q->orderByRaw('COALESCE(scheduled_at, published_at, created_at) '.(($f['order'] ?? '') === 'asc' ? 'asc' : 'desc'));

        return $q->limit(min(500, (int) ($f['limit'] ?? 200)))->get()->map(fn ($p) => $this->row($p))->all();
    }

    /**
     * Crée ou modifie une publication. $action : draft (brouillon), schedule (planifier), now (publier maintenant).
     * Sans le droit « social.publish », planifier ou publier la soumet à validation.
     */
    public function save(Company $c, User $u, array $d, ?int $id = null): array
    {
        $p = $id ? (DB::table('so_posts')->where('company_id', $c->id)->where('id', $id)->first() ?: $this->fail('Publication introuvable.')) : null;
        if ($p && in_array($p->status, ['publishing', 'published', 'partial'], true)) {
            $this->fail('Une publication déjà en ligne ne peut plus être modifiée : dupliquez-la.');
        }
        if ($p && ! $u->hasPermission('social.publish') && (int) $p->user_id !== $u->id) {
            $this->fail('Seul son auteur ou un valideur peut modifier cette publication.');
        }
        $action = $d['action'] ?? 'draft';
        $accounts = DB::table('so_accounts')->where('company_id', $c->id)->whereIn('id', array_map('intval', (array) ($d['accounts'] ?? [])))->get()->keyBy('id');
        $mediaIds = DB::table('so_media')->where('company_id', $c->id)->whereIn('id', array_map('intval', (array) ($d['media'] ?? [])))->pluck('id')->all();
        $mediaIds = array_values(array_filter(array_map('intval', (array) ($d['media'] ?? [])), fn ($m) => in_array($m, $mediaIds, true)));
        $variants = [];
        foreach ((array) ($d['variants'] ?? []) as $net => $v) {
            if (isset(Networks::ALL[$net]) && is_array($v)) {
                $variants[$net] = array_filter(['text' => isset($v['text']) ? (string) $v['text'] : null, 'title' => isset($v['title']) ? mb_substr((string) $v['title'], 0, 120) : null], fn ($x) => $x !== null && $x !== '');
            }
        }
        $at = ! empty($d['scheduled_at']) ? \Illuminate\Support\Carbon::parse($d['scheduled_at']) : null;
        $row = [
            'title' => isset($d['title']) ? mb_substr((string) $d['title'], 0, 190) : null, 'body' => (string) ($d['body'] ?? ''),
            'link_url' => $d['link_url'] ?? null, 'media' => json_encode($mediaIds), 'variants' => json_encode((object) array_filter($variants)),
            'options' => json_encode((object) array_intersect_key((array) ($d['options'] ?? []), array_flip(['first_comment', 'tiktok_privacy', 'youtube_privacy', 'board_id']))),
            'campaign' => isset($d['campaign']) ? mb_substr((string) $d['campaign'], 0, 120) : null, 'updated_at' => now(), 'rejection' => null,
        ];

        if ($action !== 'draft') {
            $accounts->isNotEmpty() || $this->fail('Choisissez au moins un compte sur lequel publier.');
            $this->validateFor($accounts, $row['body'], $variants, $mediaIds, $c);
            if ($action === 'schedule') {
                ($at && $at->isAfter(now()->subMinute())) || $this->fail('Choisissez une date de publication à venir.');
            }
        }
        $canPublish = $u->hasPermission('social.publish');
        $row['status'] = match (true) {
            $action === 'draft' => 'draft',
            ! $canPublish => 'pending',
            default => 'scheduled',
        };
        $row['scheduled_at'] = $action === 'now' ? now() : $at;
        if ($canPublish && $action !== 'draft') {
            $row += ['approved_by' => $u->id, 'approved_at' => now()];
        }
        if ($p) {
            DB::table('so_posts')->where('id', $p->id)->update($row);
            $postId = $p->id;
        } else {
            $postId = DB::table('so_posts')->insertGetId($row + ['company_id' => $c->id, 'user_id' => $u->id, 'created_at' => now()]);
        }
        // Comptes ciblés : on garde ceux déjà présents, on retire les autres (tant que rien n'est publié)
        DB::table('so_targets')->where('post_id', $postId)->whereNotIn('account_id', $accounts->keys())->where('status', '!=', 'published')->delete();
        foreach ($accounts as $a) {
            DB::table('so_targets')->insertOrIgnore(['post_id' => $postId, 'company_id' => $c->id, 'account_id' => $a->id, 'network' => $a->network, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($row['status'] === 'pending') {
            $this->notifyApprovers($c, $u, $postId);
        }
        if ($action === 'now' && $canPublish) {
            $this->publish($c, $postId);
        }

        return $this->show($c, $postId);
    }

    /** Vérifie, réseau par réseau, la longueur du texte et les médias exigés. */
    protected function validateFor($accounts, string $body, array $variants, array $mediaIds, Company $c): void
    {
        $media = $mediaIds ? DB::table('so_media')->whereIn('id', $mediaIds)->pluck('kind')->all() : [];
        $hasVideo = in_array('video', $media, true);
        $hasImage = in_array('image', $media, true);
        $errors = [];
        foreach ($accounts->groupBy('network') as $net => $accs) {
            $d = Networks::get($net);
            $text = (string) ($variants[$net]['text'] ?? $body);
            if (trim($text) === '' && ! in_array($net, ['instagram', 'tiktok', 'youtube', 'pinterest'], true)) {
                $errors[] = $d['label'].' : le texte est vide.';
            }
            $limit = Networks::limit($net, (bool) $media, ['meta' => json_decode($accs->first()->meta ?? '{}', true)]);
            $len = Networks::count($net, $text);
            if ($len > $limit) {
                $errors[] = $d['label'].' : '.$len.' caractères pour '.$limit.' autorisés. Utilisez « Adapter pour chaque réseau ».';
            }
            $missing = match ($d['media']) {
                'required' => $media ? null : 'ajoutez une image ou une vidéo.',
                'image' => $hasImage ? null : 'ajoutez une image.',
                'video' => $hasVideo ? null : 'ajoutez une vidéo.',
                default => null,
            };
            if ($missing) {
                $errors[] = $d['label'].' : '.$missing;
            }
            if ($net === 'x' && $hasVideo && count($media) > 1) {
                $errors[] = 'X : une seule vidéo, sans image, par publication.';
            }
            if (in_array($net, ['x', 'mastodon', 'bluesky'], true) && count($media) > 4) {
                $errors[] = $d['label'].' : 4 médias au maximum.';
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => $errors]);
        }
    }

    protected function notifyApprovers(Company $c, User $u, int $postId): void
    {
        $ids = User::where('company_id', $c->id)->where('is_active', true)->where('id', '!=', $u->id)->get()->filter(fn ($x) => $x->hasPermission('social.publish'))->pluck('id')->all();
        if ($ids) {
            AppNotification::send($c->id, $ids, 'social', 'Publication à valider', $u->name.' propose une publication pour les réseaux sociaux.', 'social', (string) $postId, $u->id);
        }
    }

    public function approve(Company $c, User $u, int $id): array
    {
        $p = DB::table('so_posts')->where('company_id', $c->id)->where('id', $id)->where('status', 'pending')->first() ?: $this->fail('Cette publication n\'attend pas de validation.');
        $at = $p->scheduled_at && \Illuminate\Support\Carbon::parse($p->scheduled_at)->isFuture() ? $p->scheduled_at : now();
        DB::table('so_posts')->where('id', $id)->update(['status' => 'scheduled', 'scheduled_at' => $at, 'approved_by' => $u->id, 'approved_at' => now(), 'updated_at' => now()]);
        if ($p->user_id && $p->user_id !== $u->id) {
            AppNotification::send($c->id, [$p->user_id], 'social', 'Publication validée', $u->name.' a validé votre publication.', 'social', (string) $id, $u->id);
        }
        if (! \Illuminate\Support\Carbon::parse($at)->isFuture()) {
            $this->publish($c, $id);
        }

        return $this->show($c, $id);
    }

    public function reject(Company $c, User $u, int $id, string $reason): array
    {
        $p = DB::table('so_posts')->where('company_id', $c->id)->where('id', $id)->where('status', 'pending')->first() ?: $this->fail('Cette publication n\'attend pas de validation.');
        DB::table('so_posts')->where('id', $id)->update(['status' => 'draft', 'rejection' => mb_substr($reason, 0, 500), 'updated_at' => now()]);
        if ($p->user_id && $p->user_id !== $u->id) {
            AppNotification::send($c->id, [$p->user_id], 'social', 'Publication à revoir', $u->name.' : '.$reason, 'social', (string) $id, $u->id);
        }

        return $this->show($c, $id);
    }

    public function cancel(Company $c, int $id): array
    {
        $n = DB::table('so_posts')->where('company_id', $c->id)->where('id', $id)->whereIn('status', ['draft', 'pending', 'scheduled', 'failed'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        $n || $this->fail('Cette publication ne peut plus être annulée.');

        return $this->show($c, $id);
    }

    public function duplicate(Company $c, User $u, int $id): array
    {
        $p = DB::table('so_posts')->where('company_id', $c->id)->where('id', $id)->first() ?: $this->fail('Publication introuvable.');
        $new = DB::table('so_posts')->insertGetId(['company_id' => $c->id, 'user_id' => $u->id, 'title' => $p->title, 'body' => $p->body, 'link_url' => $p->link_url, 'media' => $p->media,
            'variants' => $p->variants, 'options' => $p->options, 'campaign' => $p->campaign, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('so_targets')->where('post_id', $id)->get() as $t) {
            DB::table('so_targets')->insert(['post_id' => $new, 'company_id' => $c->id, 'account_id' => $t->account_id, 'network' => $t->network, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $this->show($c, $new);
    }

    public function destroy(Company $c, int $id): void
    {
        $p = DB::table('so_posts')->where('company_id', $c->id)->where('id', $id)->first() ?: $this->fail('Publication introuvable.');
        $p->status === 'publishing' && $this->fail('Publication en cours : patientez quelques secondes.');
        DB::table('so_posts')->where('id', $id)->delete();
    }

    // ------------------------------------------------------------------ publication

    /** Texte, titre et options envoyés à un réseau donné pour cette publication. */
    public function payloadFor(object $post, object $acc, bool $withMedia): array
    {
        $variants = json_decode($post->variants ?? '{}', true) ?: [];
        $opts = json_decode($post->options ?? '{}', true) ?: [];
        $v = $variants[$acc->network] ?? [];
        $limit = Networks::limit($acc->network, $withMedia, ['meta' => json_decode($acc->meta ?? '{}', true)]);
        $text = TextAdapter::fit($acc->network, (string) ($v['text'] ?? $post->body), $withMedia, $limit, isset($v['text']) ? null : $post->link_url);
        $titleMax = Networks::ALL[$acc->network]['title'] ?? 190;
        $title = $v['title'] ?? $post->title ?? null;
        $title = $title ? mb_substr($title, 0, $titleMax) : (in_array($acc->network, ['youtube', 'pinterest', 'tiktok'], true) ? TextAdapter::title((string) $post->body, $titleMax) : null);

        return [$text, $opts + ['title' => $title, 'link' => $post->link_url]];
    }

    /**
     * Publie sur tous les comptes ciblés qui ne l'ont pas encore été (publication simultanée).
     * Verrou par publication : un double clic ou deux planificateurs ne publient jamais deux fois.
     */
    public function publish(Company $c, int $postId, bool $retryOnly = false): array
    {
        $lock = Cache::lock('social:post:'.$postId, 900);
        if (! $lock->get()) {
            $this->fail('Publication déjà en cours.');
        }
        try {
            $post = DB::table('so_posts')->where('company_id', $c->id)->where('id', $postId)->first() ?: $this->fail('Publication introuvable.');
            in_array($post->status, ['scheduled', 'publishing', 'partial', 'failed'], true) || $this->fail('Cette publication n\'est pas prête à être publiée.');
            DB::table('so_posts')->where('id', $postId)->update(['status' => 'publishing', 'updated_at' => now()]);
            $media = $this->mediaFor($post);
            $targets = DB::table('so_targets')->where('post_id', $postId)->whereIn('status', $retryOnly ? ['failed'] : ['pending', 'failed'])->get();
            foreach ($targets as $t) {
                $acc = DB::table('so_accounts')->where('id', $t->account_id)->first();
                DB::table('so_targets')->where('id', $t->id)->update(['status' => 'publishing', 'attempts' => $t->attempts + 1, 'updated_at' => now()]);
                try {
                    ($acc && $acc->status !== 'disabled') || $this->fail('Compte déconnecté.');
                    [$text, $opts] = $this->payloadFor($post, $acc, (bool) $media);
                    $res = $this->publisher->publish($acc, $text, $media, $opts + ['target_id' => $t->id]);
                    DB::table('so_targets')->where('id', $t->id)->update(['status' => 'published', 'external_id' => mb_substr((string) $res['id'], 0, 190), 'url' => $res['url'] ?? null,
                        'error' => null, 'retry_at' => null, 'published_at' => now(), 'updated_at' => now()]);
                    if ($acc->status !== 'active') {
                        DB::table('so_accounts')->where('id', $acc->id)->update(['status' => 'active', 'last_error' => null]);
                    }
                } catch (\Throwable $e) {
                    $msg = $e instanceof \DomainException ? $e->getMessage() : 'Erreur technique : '.$e->getMessage();
                    $transient = ! ($e instanceof \DomainException) || str_contains($msg, 'trop de temps') || preg_match('/\b(429|500|502|503|504)\b|rate|limit|timeout|temporar/i', $msg);
                    DB::table('so_targets')->where('id', $t->id)->update(['status' => 'failed', 'error' => mb_substr($msg, 0, 500),
                        'retry_at' => $transient && $t->attempts + 1 < self::MAX_ATTEMPTS ? now()->addMinutes(5 * ($t->attempts + 1)) : null, 'updated_at' => now()]);
                    if ($acc && ! $transient && $acc->status === 'active') {
                        DB::table('so_accounts')->where('id', $acc->id)->update(['last_error' => mb_substr($msg, 0, 500)]);
                    }
                }
            }

            return $this->finalize($c, $postId);
        } finally {
            $lock->release();
        }
    }

    protected function finalize(Company $c, int $postId): array
    {
        $all = DB::table('so_targets')->where('post_id', $postId)->pluck('status');
        $ok = $all->filter(fn ($s) => $s === 'published')->count();
        $status = match (true) {
            $all->isEmpty() => 'failed',
            $ok === $all->count() => 'published',
            $ok > 0 => 'partial',
            default => 'failed',
        };
        $post = DB::table('so_posts')->find($postId);
        DB::table('so_posts')->where('id', $postId)->update(['status' => $status, 'published_at' => $ok ? ($post->published_at ?? now()) : $post->published_at, 'updated_at' => now()]);
        if ($status !== 'published') {
            $failed = DB::table('so_targets')->where('post_id', $postId)->where('status', 'failed')->whereNull('retry_at')->get();
            if ($failed->isNotEmpty() && $post->user_id) {
                AppNotification::send($c->id, [$post->user_id], 'social', 'Publication non publiée sur '.$failed->pluck('network')->unique()->map(fn ($n) => Networks::label($n))->implode(', '),
                    (string) $failed->first()->error, 'social', (string) $postId);
            }
        }

        return $this->show($c, $postId);
    }

    /** Planificateur (chaque minute) : publications arrivées à échéance, puis relances automatiques des échecs passagers. */
    public function tick(Company $c): int
    {
        $n = 0;
        $due = DB::table('so_posts')->where('company_id', $c->id)->where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(20)->pluck('id');
        foreach ($due as $id) {
            rescue(fn () => $this->publish($c, $id), null, false);
            $n++;
        }
        $retry = DB::table('so_targets')->where('company_id', $c->id)->where('status', 'failed')->whereNotNull('retry_at')->where('retry_at', '<=', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)->distinct()->limit(20)->pluck('post_id');
        foreach ($retry as $id) {
            rescue(fn () => $this->publish($c, $id, true), null, false);
            $n++;
        }
        // Publication restée bloquée (processus interrompu) : on la remet en circulation
        DB::table('so_posts')->where('company_id', $c->id)->where('status', 'publishing')->where('updated_at', '<', now()->subMinutes(20))->update(['status' => 'partial', 'updated_at' => now()]);
        DB::table('so_targets')->where('company_id', $c->id)->where('status', 'publishing')->where('updated_at', '<', now()->subMinutes(20))
            ->update(['status' => 'failed', 'error' => 'Publication interrompue : vérifiez sur le réseau avant de relancer.', 'retry_at' => null]);

        return $n;
    }

    /** Relevé des interactions des publications des 30 derniers jours (toutes les 6 heures par publication). */
    public function syncStats(Company $c, int $max = 40): int
    {
        $n = 0;
        $targets = DB::table('so_targets')->where('company_id', $c->id)->where('status', 'published')->where('published_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->whereNull('stats_at')->orWhere('stats_at', '<', now()->subHours(6)))->orderBy('stats_at')->limit($max)->get();
        foreach ($targets as $t) {
            $acc = DB::table('so_accounts')->find($t->account_id);
            $s = $acc ? rescue(fn () => $this->publisher->stats($acc, $t), null, false) : null;
            DB::table('so_targets')->where('id', $t->id)->update(array_filter(['stats' => $s ? json_encode(array_filter($s, fn ($v) => $v !== null)) : null, 'stats_at' => now()]));
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ statistiques et meilleures heures

    public function analytics(Company $c, int $days = 30): array
    {
        $from = now()->subDays($days);
        $t = DB::table('so_targets')->where('company_id', $c->id)->where('status', 'published')->where('published_at', '>=', $from)->get(['network', 'stats', 'published_at']);
        $by = [];
        foreach ($t as $row) {
            $s = json_decode($row->stats ?? '[]', true) ?: [];
            $b = &$by[$row->network];
            $b['posts'] = ($b['posts'] ?? 0) + 1;
            foreach (['likes', 'comments', 'shares', 'views'] as $k) {
                $b[$k] = ($b[$k] ?? 0) + (int) ($s[$k] ?? 0);
            }
            unset($b);
        }
        $networks = collect($by)->map(fn ($v, $k) => $v + ['network' => $k, 'label' => Networks::label($k), 'engagement' => $v['likes'] + $v['comments'] + $v['shares']])->sortByDesc('engagement')->values()->all();
        $posts = DB::table('so_posts')->where('company_id', $c->id);

        return [
            'days' => $days,
            'totals' => ['published' => (clone $posts)->whereIn('status', ['published', 'partial'])->where('published_at', '>=', $from)->count(),
                'scheduled' => (clone $posts)->where('status', 'scheduled')->count(), 'pending' => (clone $posts)->where('status', 'pending')->count(),
                'failed' => DB::table('so_targets')->where('company_id', $c->id)->where('status', 'failed')->where('updated_at', '>=', $from)->count(),
                'likes' => array_sum(array_column($networks, 'likes')), 'comments' => array_sum(array_column($networks, 'comments')),
                'shares' => array_sum(array_column($networks, 'shares')), 'views' => array_sum(array_column($networks, 'views'))],
            'networks' => $networks,
            'top' => DB::table('so_posts')->where('company_id', $c->id)->whereIn('status', ['published', 'partial'])->where('published_at', '>=', $from)->orderByDesc('published_at')->limit(100)->get()
                ->map(fn ($p) => $this->row($p))->sortByDesc(fn ($p) => $p['stats']['likes'] + $p['stats']['comments'] + $p['stats']['shares'])->take(5)->values()->all(),
            'accounts' => DB::table('so_accounts')->where('company_id', $c->id)->count(),
        ];
    }

    /** Meilleurs créneaux : d'après l'engagement passé (au moins 8 publications), sinon les habitudes d'audience du réseau. */
    public function bestTimes(Company $c, array $networks): array
    {
        $out = [];
        foreach (array_intersect($networks ?: array_keys(Networks::ALL), array_keys(Networks::ALL)) as $net) {
            $rows = DB::table('so_targets')->where('company_id', $c->id)->where('network', $net)->where('status', 'published')->whereNotNull('stats')->where('published_at', '>=', now()->subDays(120))->get(['published_at', 'stats']);
            if ($rows->count() >= 8) {
                $score = [];
                foreach ($rows as $r) {
                    $s = json_decode($r->stats, true) ?: [];
                    $h = \Illuminate\Support\Carbon::parse($r->published_at)->format('H');
                    $score[$h][] = (int) ($s['likes'] ?? 0) + 2 * (int) ($s['comments'] ?? 0) + 3 * (int) ($s['shares'] ?? 0);
                }
                $avg = collect($score)->map(fn ($v) => array_sum($v) / count($v))->sortDesc()->keys()->take(2)->map(fn ($h) => sprintf('%02d:00', $h))->values()->all();
                $out[$net] = ['times' => $avg, 'source' => 'history'];
            } else {
                $out[$net] = ['times' => self::DEFAULT_TIMES[$net] ?? ['12:00'], 'source' => 'default'];
            }
        }

        // Prochain créneau conseillé commun : la première heure proposée encore à venir
        $slots = collect($out)->flatMap(fn ($v) => $v['times'])->countBy()->sortDesc()->keys()->all();
        $next = null;
        foreach ([0, 1, 2] as $day) {
            foreach (collect($slots)->sort()->values() as $hm) {
                $cand = now()->startOfDay()->addDays($day)->setTimeFromTimeString($hm);
                if ($cand->isAfter(now()->addMinutes(15))) {
                    $next = $cand->toIso8601String();
                    break 2;
                }
            }
        }

        return ['networks' => $out, 'next' => $next];
    }
}
