<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Historique d'activité de la société : qui a fait quoi, quand, sur quelle fiche, dans tout l'ERP.
 * Réservé au droit sensible « core.activity.view » (administrateur, ou accordé nommément).
 */
class ActivityController extends Controller
{
    /** Libellés des actions métier (les créations / modifications / suppressions sont générées). */
    public const ACTIONS = [
        'auth.login' => 'Connexion', 'auth.logout' => 'Déconnexion',
        'document.validated' => 'Document validé', 'document.cancelled' => 'Document annulé',
        'document.reopened' => 'Document rouvert en brouillon', 'document.purged' => 'Document validé supprimé',
        'invoice.paid_online' => 'Facture payée en ligne',
    ];

    public const VERBS = ['created' => 'Création', 'updated' => 'Modification', 'deleted' => 'Suppression'];

    /** Entités synchronisées → [module, libellé du module]. */
    protected function entityModules(): array
    {
        $reg = app(ModuleRegistry::class);
        $out = [];
        foreach ($reg->manifests() as $key => $m) {
            foreach (array_keys($m['sync'] ?? []) as $entity) {
                $out[$entity] = [$key, (string) ($m['name'] ?? $key)];
            }
        }
        $out['user'] = ['core', 'Utilisateurs'];
        $out['role'] = ['core', 'Rôles'];

        return $out;
    }

    public function index(Request $r)
    {
        $d = $r->validate([
            'user_id' => 'nullable|integer', 'module' => 'nullable|string|max:60', 'kind' => 'nullable|in:created,updated,deleted,other',
            'q' => 'nullable|string|max:100', 'from' => 'nullable|date', 'to' => 'nullable|date', 'subject_id' => 'nullable|string|max:64',
            'before_id' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:200',
        ]);
        $company = app('tenant');
        $map = $this->entityModules();
        $q = DB::table('activity_logs')->where('company_id', $company->id);
        if (! empty($d['user_id'])) {
            $q->where('user_id', $d['user_id']);
        }
        if (! empty($d['subject_id'])) {
            $q->where('subject_id', $d['subject_id']);
        }
        if (! empty($d['from'])) {
            $q->where('created_at', '>=', $d['from'].' 00:00:00');
        }
        if (! empty($d['to'])) {
            $q->where('created_at', '<=', $d['to'].' 23:59:59');
        }
        if (! empty($d['module'])) {
            $entities = array_keys(array_filter($map, fn ($v) => $v[0] === $d['module']));
            $q->where(function ($w) use ($entities, $d) {
                foreach ($entities as $e) {
                    $w->orWhere('action', 'like', $e.'.%');
                }
                $w->orWhere('action', 'like', $d['module'].'.%');
                if ($d['module'] === 'ventes' || $d['module'] === 'achats') {
                    $w->orWhere('action', 'like', 'document.%');
                }
            });
        }
        if (! empty($d['kind'])) {
            $d['kind'] === 'other'
                ? $q->where('action', 'not like', '%.created')->where('action', 'not like', '%.updated')->where('action', 'not like', '%.deleted')
                : $q->where('action', 'like', '%.'.$d['kind']);
        }
        if (! empty($d['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $d['q']).'%';
            $q->where(fn ($w) => $w->where('properties', 'like', $term)->orWhere('action', 'like', $term));
        }
        if (! empty($d['before_id'])) {
            $q->where('id', '<', $d['before_id']);
        }
        $limit = (int) ($d['limit'] ?? 50);
        $rows = $q->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $users = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return [
            'data' => $rows->map(fn ($row) => $this->present($row, $map, $users))->values(),
            'more' => $more,
        ];
    }

    protected function present(object $row, array $map, $users): array
    {
        $p = json_decode((string) $row->properties, true) ?: [];
        $parts = explode('.', $row->action);
        $verb = end($parts);
        $entity = implode('.', array_slice($parts, 0, -1));
        if (isset(static::ACTIONS[$row->action])) {
            $label = static::ACTIONS[$row->action];
            $kind = 'other';
        } elseif (isset(static::VERBS[$verb]) && isset($map[$entity])) {
            $label = static::VERBS[$verb];
            $kind = $verb;
        } else {
            $label = ucfirst(str_replace(['.', '_'], [' › ', ' '], $row->action));
            $kind = 'other';
        }
        $module = $map[$entity] ?? (in_array($parts[0], ['document', 'invoice'], true) ? ['ventes', 'Ventes & Facturation'] : [$parts[0], ucfirst($parts[0])]);

        return [
            'id' => $row->id,
            'at' => $row->created_at,
            'user_id' => $row->user_id,
            'user' => $row->user_id ? ($users[$row->user_id] ?? 'Utilisateur supprimé') : 'Système',
            'action' => $row->action,
            'kind' => $kind,
            'label' => $label,
            'module' => $module[0],
            'module_label' => $module[1],
            'entity' => $entity,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'subject' => $p['label'] ?? ($p['number'] ?? null),
            'fields' => $p['fields'] ?? [],
            'changes' => $p['changes'] ?? null,
            'reason' => $p['reason'] ?? null,
            'before' => $p['before'] ?? null,
            'details' => array_diff_key($p, array_flip(['label', 'fields', 'changes', 'reason', 'before'])) ?: null,
            'ip' => $row->ip,
        ];
    }

    /** Filtres : personnes et modules présents. */
    public function meta()
    {
        $company = app('tenant');
        $modules = collect($this->entityModules())->map(fn ($v) => ['key' => $v[0], 'label' => $v[1]])->unique('key')
            ->filter(fn ($m) => $m['key'] === 'core' || $company->hasModule($m['key']))->sortBy('label')->values();

        return [
            'users' => User::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name'])->values(),
            'modules' => $modules,
        ];
    }
}
