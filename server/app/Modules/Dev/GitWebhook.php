<?php

namespace App\Modules\Dev;

use App\Models\AppNotification;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Webhooks GitHub et GitLab d'une application : les numéros de tâche (#0044) cités dans les branches, titres de pull / merge request
 * et messages de commit font avancer les tâches ; un tag de version met la version en production.
 */
class GitWebhook
{
    public const EVENT_KINDS = ['commit' => 'Commit', 'pr_opened' => 'Pull request ouverte', 'pr_merged' => 'Fusionnée', 'pr_closed' => 'Pull request fermée'];

    /** Numéros de tâche cités : « #44 », « #0044 », branche « feature/0044-suivi » ou « 44-suivi ». */
    public static function refs(string ...$texts): array
    {
        $n = [];
        foreach ($texts as $t) {
            preg_match_all('/#0*(\d{1,6})\b/', $t, $m);
            $n = array_merge($n, $m[1]);
            if (preg_match('#(?:^|/)0*(\d{1,6})[-_]#', $t, $b)) {
                $n[] = $b[1];
            }
        }

        return array_values(array_unique(array_map('intval', $n)));
    }

    public function verify(object $app, string $body, array $headers): bool
    {
        if ($sig = $headers['x-hub-signature-256'] ?? null) {
            return hash_equals('sha256='.hash_hmac('sha256', $body, (string) $app->git_secret), $sig);
        }
        if ($tok = $headers['x-gitlab-token'] ?? null) {
            return hash_equals((string) $app->git_secret, $tok);
        }

        return false;
    }

    protected function items(object $app, array $nums)
    {
        if (! $nums) {
            return collect();
        }
        $labels = array_map(fn ($n) => '#'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), $nums);

        return DB::table('dev_items')->where('company_id', $app->company_id)->where(fn ($q) => $q->whereNull('app_id')->orWhere('app_id', $app->id))
            ->whereIn('number', $labels)->get();
    }

    protected function event(object $item, string $kind, string $title, ?string $url, ?string $author, ?string $ref = null): void
    {
        $exists = DB::table('dev_item_events')->where('item_id', $item->id)->where('kind', $kind)->where('url', $url)->exists();
        if (! $exists) {
            DB::table('dev_item_events')->insert(['company_id' => $item->company_id, 'item_id' => $item->id, 'kind' => $kind, 'title' => mb_substr($title, 0, 255),
                'url' => $url, 'author' => $author ? mb_substr($author, 0, 120) : null, 'ref' => $ref, 'created_at' => now()]);
        }
    }

    protected function move(object $item, string $to, array $extra = []): void
    {
        $order = ['backlog' => 0, 'todo' => 1, 'in_progress' => 2, 'review' => 3, 'testing' => 4, 'done' => 5];
        $set = $extra;
        $back = $to === 'in_progress' && in_array($item->status, ['review', 'testing'], true) && ($extra['_force'] ?? false);
        unset($set['_force']);
        if ($item->status !== 'cancelled' && (($order[$to] ?? 0) > ($order[$item->status] ?? 0) || $back)) {
            $set['status'] = $to;
            if ($to === 'in_progress' && ! $item->started_at) {
                $set['started_at'] = now();
            }
            $set['done_at'] = $to === 'done' ? now() : null;
            AppNotification::send($item->company_id, array_filter([$item->assignee_id, $item->reporter_id]), 'dev', trim($item->number.' '.$item->title),
                'Passée « '.DevHooks::STATUSES[$to][0].' » par Git.', 'dev', (string) $item->id);
        }
        if ($set) {
            DB::table('dev_items')->where('id', $item->id)->update($set + ['updated_at' => now()]);
        }
    }

    /** Traite un événement ; renvoie le nombre de tâches touchées. */
    public function handle(object $app, array $headers, array $p): int
    {
        $gh = $headers['x-github-event'] ?? null;
        $gl = $headers['x-gitlab-event'] ?? null;
        $n = 0;
        DB::table('dev_apps')->where('id', $app->id)->update(['git_last_event_at' => now(), 'git_provider' => $gh ? 'github' : ($gl ? 'gitlab' : $app->git_provider)]);

        // Pull / merge requests
        if ($gh === 'pull_request' || $gl === 'Merge Request Hook') {
            $pr = $gh ? ($p['pull_request'] ?? []) : ($p['object_attributes'] ?? []);
            $action = $gh ? ($p['action'] ?? '') : ($pr['action'] ?? '');
            $title = (string) ($pr['title'] ?? '');
            $branch = (string) ($gh ? ($pr['head']['ref'] ?? '') : ($pr['source_branch'] ?? ''));
            $url = $gh ? ($pr['html_url'] ?? null) : ($pr['url'] ?? null);
            $author = $gh ? ($pr['user']['login'] ?? null) : ($p['user']['name'] ?? null);
            $merged = $gh ? ($action === 'closed' && ! empty($pr['merged'])) : $action === 'merge';
            $closed = $gh ? ($action === 'closed' && empty($pr['merged'])) : $action === 'close';
            $opened = in_array($action, ['opened', 'reopened', 'ready_for_review', 'open', 'reopen'], true);
            foreach ($this->items($app, self::refs($title, $branch, (string) ($pr['body'] ?? $pr['description'] ?? ''))) as $item) {
                if ($opened) {
                    $this->event($item, 'pr_opened', $title, $url, $author, $branch);
                    $this->move($item, 'review', ['pr_url' => $url, 'branch' => $item->branch ?: $branch]);
                } elseif ($merged) {
                    $this->event($item, 'pr_merged', $title, $url, $author, $branch);
                    $this->move($item, $app->on_merge === 'done' ? 'done' : 'testing', ['pr_url' => $url]);
                } elseif ($closed) {
                    $this->event($item, 'pr_closed', $title, $url, $author, $branch);
                    $this->move($item, 'in_progress', ['_force' => true]);
                }
                $n++;
            }
        }

        // Commits poussés
        if ($gh === 'push' || $gl === 'Push Hook') {
            $branch = preg_replace('#^refs/heads/#', '', (string) ($p['ref'] ?? ''));
            foreach ($p['commits'] ?? [] as $cm) {
                $msg = (string) ($cm['message'] ?? '');
                foreach ($this->items($app, self::refs($msg, $branch)) as $item) {
                    $this->event($item, 'commit', strtok($msg, "\n") ?: $msg, $cm['url'] ?? null, $cm['author']['name'] ?? ($cm['author']['username'] ?? null), $branch);
                    if (in_array($item->status, ['backlog', 'todo'], true)) {
                        $this->move($item, 'in_progress', ['branch' => $item->branch ?: $branch]);
                    }
                    $n++;
                }
            }
        }

        // Tag de version → mise en production
        $tagRef = (string) ($p['ref'] ?? '');
        if ((($gh === 'push' && str_starts_with($tagRef, 'refs/tags/')) || $gl === 'Tag Push Hook') && empty($p['deleted'])) {
            $tag = preg_replace('#^refs/tags/v?#i', '', $tagRef);
            $rel = DB::table('dev_releases')->where('company_id', $app->company_id)->where(fn ($q) => $q->whereNull('app_id')->orWhere('app_id', $app->id))
                ->where(fn ($q) => $q->where('title', $tag)->orWhere('title', 'v'.$tag))->first();
            if ($rel && $rel->status !== 'released') {
                DB::table('dev_releases')->where('id', $rel->id)->update(['status' => 'released', 'date' => now()->toDateString(), 'updated_at' => now()]);
                $done = DB::table('dev_items')->where('release_id', $rel->id)->where('status', 'testing')->update(['status' => 'done', 'done_at' => now(), 'updated_at' => now()]);
                AppNotification::send($app->company_id, array_filter([$rel->owner_id]), 'dev', 'Version '.$rel->title.' en production',
                    'Tag '.$tag.' poussé'.($done ? ' : '.$done.' tâche(s) en recette passée(s) « terminé ».' : '.'), 'dev', 'r'.$rel->id);
                $n++;
            }
        }

        return $n;
    }

    public static function company(object $app): Company
    {
        return Company::findOrFail($app->company_id);
    }
}
