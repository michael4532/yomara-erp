<?php

namespace App\Modules\Dev;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Règles du service développement : numérotation, circuit des tâches, sprints, notes de version. */
class DevHooks
{
    public const KINDS = ['story' => ['Récit utilisateur', '#1F6FEB'], 'bug' => ['Bug', '#DC2626'], 'task' => ['Tâche technique', '#64748B'], 'improvement' => ['Amélioration', '#0891B2'], 'techdebt' => ['Dette technique', '#9333EA']];

    public const STATUSES = ['backlog' => ['Backlog', '#94A3B8'], 'todo' => ['À faire', '#64748B'], 'in_progress' => ['En cours', '#1F6FEB'], 'review' => ['Revue de code', '#9333EA'],
        'testing' => ['Recette', '#D97706'], 'done' => ['Terminé', '#16A34A'], 'cancelled' => ['Abandonné', '#94A3B8']];

    public const PRIORITIES = ['low' => ['Basse', '#94A3B8'], 'normal' => ['Normale', '#64748B'], 'high' => ['Haute', '#D97706'], 'critical' => ['Critique', '#DC2626']];

    public const SPRINT_STATUSES = ['planned' => ['Prévu', '#64748B'], 'active' => ['En cours', '#1F6FEB'], 'closed' => ['Terminé', '#16A34A']];

    public const RELEASE_STATUSES = ['planned' => ['Prévue', '#64748B'], 'released' => ['En production', '#16A34A'], 'rolled_back' => ['Annulée (retour arrière)', '#DC2626']];

    public const ENVIRONMENTS = ['staging' => 'Préproduction / recette', 'production' => 'Production'];

    public const APP_STATUSES = ['active' => ['En développement', '#1F6FEB'], 'maintenance' => ['Maintenance', '#D97706'], 'archived' => ['Archivée', '#94A3B8']];

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        if ($res === 'items') {
            if (! $old) {
                $max = DB::table('dev_items')->where('company_id', app('tenant')->id)->where('number', 'like', '#%')->pluck('number')->map(fn ($x) => (int) ltrim($x, '#'))->max() ?? 0;
                $data['number'] = '#'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
            }
            if ($old && ! $u->hasPermission('dev.manage') && ! in_array($u->id, [(int) $old['assignee_id'], (int) $old['reporter_id'], (int) $old['created_by']], true)) {
                throw new \DomainException('Seuls la personne assignée, le demandeur ou le responsable technique modifient cette tâche.');
            }
            $to = $data['status'] ?? null;
            if ($to && $to !== ($old['status'] ?? null)) {
                if ($to === 'in_progress' && empty($old['started_at'])) {
                    $data['started_at'] = now()->format('Y-m-d H:i:s');
                }
                $data['done_at'] = $to === 'done' ? now()->format('Y-m-d H:i:s') : null;
                if ($to === 'in_progress' && empty(array_merge($old ?? [], $data)['assignee_id'])) {
                    $data['assignee_id'] = $u->id;
                }
            }
        }

        if ($res === 'apps' && ! $old) {
            $data['git_token'] = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(40));
            $data['git_secret'] = \Illuminate\Support\Str::random(40);
        }

        return $data;
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        if ($res !== 'items') {
            return;
        }
        $label = ($row['number'] ? $row['number'].' ' : '').$row['title'];
        if ($row['assignee_id'] && (int) $row['assignee_id'] !== (int) ($old['assignee_id'] ?? 0)) {
            AppNotification::send($row['company_id'], [$row['assignee_id']], 'dev', 'Tâche qui vous est assignée', $label, 'dev', (string) $row['id'], $u->id);
        }
        if ($old && $row['status'] !== $old['status'] && in_array($row['status'], ['review', 'testing', 'done'], true)) {
            AppNotification::send($row['company_id'], [$row['reporter_id']], 'dev', $label, 'Passée « '.self::STATUSES[$row['status']][0].' » par '.$u->name.'.', 'dev', (string) $row['id'], $u->id);
        }
    }

    public function present(string $res, array $row): array
    {
        if ($res === 'sprints') {
            $q = DB::table('dev_items')->where('sprint_id', $row['id'])->where('status', '!=', 'cancelled');
            $all = (float) (clone $q)->sum('points');
            $done = (float) (clone $q)->where('status', 'done')->sum('points');
            $n = (clone $q)->count();
            $row['progress'] = $all > 0 ? round($done).' / '.round($all).' pts ('.round($done / $all * 100).' %)' : ((clone $q)->where('status', 'done')->count().' / '.$n.' tâches');
        } elseif ($res === 'releases') {
            $row['items'] = DB::table('dev_items')->where('release_id', $row['id'])->count();
        } elseif ($res === 'apps') {
            $row['open_items'] = DB::table('dev_items')->where('app_id', $row['id'])->whereNotIn('status', ['done', 'cancelled'])->count();
            $manager = request()->user()?->hasPermission('dev.manage');
            $row['webhook'] = $manager && ! empty($row['git_token'])
                ? 'Adresse : '.url('/api/dev-git/'.$row['git_token'])."\nSecret : ".$row['git_secret']."\nType de contenu : application/json — événements : push, pull requests (GitHub) ou Push, Tag push, Merge request (GitLab)."
                    .(! empty($row['git_last_event_at']) ? "\nDernier événement reçu : ".date('d/m/Y H:i', strtotime($row['git_last_event_at'])) : "\nAucun événement reçu pour l'instant.")
                : null;
            unset($row['git_secret'], $row['git_token']);
        } elseif ($res === 'items') {
            $ev = DB::table('dev_item_events')->where('item_id', $row['id'])->orderByDesc('id')->limit(6)->get();
            $row['git_activity'] = $ev->isEmpty() ? null : $ev->map(fn ($e) => date('d/m H:i', strtotime($e->created_at)).' · '.(GitWebhook::EVENT_KINDS[$e->kind] ?? $e->kind).' : '.$e->title.($e->author ? ' ('.$e->author.')' : ''))->implode("\n");
        }

        return $row;
    }

    /** Notes de version à partir des tâches terminées rattachées à la version. */
    public function changelog(Company $c, int $releaseId): string
    {
        $r = DB::table('dev_releases')->where('company_id', $c->id)->where('id', $releaseId)->first() ?: throw new \DomainException('Version introuvable.');
        $items = DB::table('dev_items')->where('release_id', $r->id)->where('status', 'done')->orderBy('kind')->orderBy('id')->get();
        if ($items->isEmpty()) {
            throw new \DomainException('Aucune tâche terminée n\'est rattachée à cette version.');
        }
        $groups = ['story' => 'Nouveautés', 'improvement' => 'Améliorations', 'bug' => 'Corrections', 'task' => 'Technique', 'techdebt' => 'Technique'];
        $out = 'Version '.$r->title.($r->date ? ' — '.date('d/m/Y', strtotime($r->date)) : '')."\n";
        foreach ($items->groupBy(fn ($i) => $groups[$i->kind] ?? 'Divers') as $g => $list) {
            $out .= "\n".$g." :\n".$list->map(fn ($i) => '• '.$i->title.($i->number ? ' ('.$i->number.')' : ''))->implode("\n")."\n";
        }
        DB::table('dev_releases')->where('id', $r->id)->update(['notes' => trim($out), 'updated_at' => now()]);

        return trim($out);
    }

    public function dashboard(Company $c, User $u): array
    {
        $items = DB::table('dev_items')->where('company_id', $c->id);
        $sprint = DB::table('dev_sprints')->where('company_id', $c->id)->where('status', 'active')->orderBy('end_date')->first();

        return [
            'sprint' => $sprint ? $this->present('sprints', (array) $sprint) : null,
            'mine' => (clone $items)->where('assignee_id', $u->id)->whereNotIn('status', ['done', 'cancelled', 'backlog'])->count(),
            'in_progress' => (clone $items)->where('status', 'in_progress')->count(),
            'review' => (clone $items)->whereIn('status', ['review', 'testing'])->count(),
            'bugs' => (clone $items)->where('kind', 'bug')->whereNotIn('status', ['done', 'cancelled'])->count(),
            'critical' => (clone $items)->where('priority', 'critical')->whereNotIn('status', ['done', 'cancelled'])->count(),
            'done_week' => (clone $items)->where('status', 'done')->where('done_at', '>=', now()->startOfWeek())->count(),
            'releases_month' => DB::table('dev_releases')->where('company_id', $c->id)->where('status', 'released')->where('date', '>=', now()->startOfMonth()->toDateString())->count(),
        ];
    }
}
