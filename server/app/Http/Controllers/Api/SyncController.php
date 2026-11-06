<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncChange;
use App\Models\SyncMutation;
use App\Services\EntityWriter;
use App\Support\ModuleRegistry;
use Illuminate\Http\Request;

/**
 * Synchronisation hors ligne.
 *
 * PULL  GET  /api/sync/pull?cursor=N    → changements depuis le curseur N (paginé)
 * PUSH  POST /api/sync/push             → mutations faites hors ligne, appliquées dans l'ordre
 *
 * Garanties :
 *  - idempotence : une mutation (UUID) rejouée n'est appliquée qu'une fois ;
 *  - conflits : si base_version ≠ version serveur, la version serveur est conservée
 *    et renvoyée au client (statut "conflict") ;
 *  - mêmes règles métier qu'en ligne (EntityWriter).
 */
class SyncController extends Controller
{
    public function __construct(protected ModuleRegistry $registry) {}

    /** Entités accessibles : modules activés + permission de lecture. */
    protected function readableEntities(Request $request): array
    {
        $user = $request->user();
        $entities = $this->registry->syncEntities(app('tenant')->enabledModules());

        return array_filter($entities, fn ($class) => $user->hasPermission($class::$viewPermission) || $user->hasPermission($class::$managePermission));
    }

    public function pull(Request $request)
    {
        $cursor = max(0, (int) $request->query('cursor', 0));
        $limit = max(1, min((int) $request->query('limit', config('erp.sync.page_size')), 2000));
        $entities = $this->readableEntities($request);
        app(\App\Support\ModuleRegistry::class)->ensureSetup(app('tenant'));

        $changes = SyncChange::query()
            ->where('company_id', app('tenant')->id)
            ->where('id', '>', $cursor)
            ->whereIn('entity', array_keys($entities))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'entity', 'record_id']);

        $hasMore = $changes->count() > $limit;
        $changes = $changes->take($limit);
        $newCursor = $changes->last()?->id ?? $this->latestCursor();

        $out = [];
        foreach ($changes->groupBy('entity') as $entity => $rows) {
            $class = $entities[$entity];
            $ids = $rows->pluck('record_id')->unique()->values();
            $query = $class::withTrashed()->whereIn((new $class)->getKeyName(), $ids);
            if (method_exists($class, 'syncVisibility')) {
                // Visibilité par enregistrement (ex. le salarié ne reçoit que sa fiche) : le reste part en suppression.
                $class::syncVisibility($query, $request->user());
            }
            $records = $query->get()->keyBy(fn ($r) => $r->getKey());

            $upserts = [];
            $deletes = [];
            foreach ($ids as $id) {
                $r = $records->get($id);
                if (! $r || $r->trashed()) {
                    $deletes[] = $id;
                } else {
                    $upserts[] = $r->toSyncArray();
                }
            }
            $out[$entity] = ['upserts' => $upserts, 'deletes' => $deletes];
        }

        return response()->json([
            'cursor' => $newCursor,
            'has_more' => $hasMore,
            // Dernier curseur de la société : permet d'afficher la progression du premier chargement
            'latest' => $hasMore ? (int) SyncChange::where('company_id', app('tenant')->id)->max('id') : $newCursor,
            'changes' => (object) $out,
            'entities' => array_keys($entities),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function push(Request $request, EntityWriter $writer)
    {
        $data = $request->validate([
            'mutations' => 'required|array|max:'.config('erp.sync.max_push'),
            'mutations.*.id' => 'required|uuid',
            'mutations.*.entity' => 'required|string',
            'mutations.*.op' => 'required|in:upsert,delete',
            'mutations.*.record_id' => 'required|uuid',
            'mutations.*.data' => 'nullable|array',
            'mutations.*.base_version' => 'nullable|integer',
        ]);

        $user = $request->user();
        $company = app('tenant');
        $entities = $this->registry->syncEntities($company->enabledModules());
        $results = [];

        foreach ($data['mutations'] as $m) {
            $class = $entities[$m['entity']] ?? null;

            // Déjà traitée (renvoi après coupure réseau) → même réponse, sans réappliquer.
            $done = SyncMutation::where('company_id', $company->id)->find($m['id']);
            if ($done) {
                // renvoi d'une mutation déjà traitée : seulement pour son auteur et la même fiche
                $same = (int) $done->user_id === (int) $user->id && $done->entity === $m['entity'] && $done->record_id === $m['record_id'];
                $results[] = $this->resultRow($m, $same ? $done->status : EntityWriter::REJECTED, $class, $same ? $done->message : 'Identifiant de modification déjà utilisé.');
                continue;
            }
            if (SyncMutation::withoutGlobalScopes()->whereKey($m['id'])->exists()) {
                // identifiant pris par une autre société : refus propre (pas d'erreur serveur)
                $results[] = $this->resultRow($m, EntityWriter::REJECTED, $class, 'Identifiant de modification déjà utilisé.');
                continue;
            }

            if (! $class) {
                $results[] = $this->store($m, $company->id, $user->id, EntityWriter::REJECTED, null, 'Module désactivé ou entité inconnue : '.$m['entity']);
                continue;
            }
            if (! $user->hasPermission($class::$managePermission)) {
                $results[] = $this->store($m, $company->id, $user->id, EntityWriter::REJECTED, $class, 'Permission refusée.');
                continue;
            }

            if ($m['op'] === 'delete' && ! $user->hasPermission(EntityController::deletePermission($class))) {
                $results[] = $this->store($m, $company->id, $user->id, EntityWriter::REJECTED, $class, 'Vous n\'avez pas le droit de supprimer : demandez-le à l\'administrateur.');
                continue;
            }

            // Même règle que l'API REST : on ne supprime que ce que l'utilisateur a le droit de voir
            // (ex. évaluation de quelqu'un d'autre, note de frais d'un collègue).
            if ($m['op'] === 'delete' && $class::query()->whereKey($m['record_id'])->exists()
                && ! EntityController::visibleRecord($class, $m['record_id'], $user)) {
                $results[] = $this->store($m, $company->id, $user->id, EntityWriter::REJECTED, $class, 'Suppression refusée : cet élément ne vous appartient pas.');
                continue;
            }
            // Même règle que l'API REST pour l'écriture : un identifiant existant (autre société, ou fiche que l'utilisateur
            // ne voit pas) n'est jamais réécrit — ni erreur serveur ni écrasement de la fiche d'un collègue.
            if ($m['op'] === 'upsert' && $class::withoutGlobalScopes()->whereKey($m['record_id'])->exists()
                && ! EntityController::visibleRecord($class, $m['record_id'], $user)) {
                $results[] = $this->store($m, $company->id, $user->id, EntityWriter::REJECTED, $class, 'Modification refusée : cet élément ne vous est pas accessible.');
                continue;
            }

            $r = $m['op'] === 'delete'
                ? $writer->delete($class, $m['record_id'], $m['base_version'] ?? null)
                : $writer->upsert($class, $m['record_id'], $m['data'] ?? [], $m['base_version'] ?? null);

            $results[] = $this->store($m, $company->id, $user->id, $r['status'], $r['status'] === EntityWriter::APPLIED ? $r['record'] : $class, $r['message'], $r['errors']);
        }

        return response()->json(['results' => $results, 'cursor' => $this->latestCursor()]);
    }

    protected function store(array $m, int $companyId, int $userId, string $status, $record, ?string $message, ?array $errors = null): array
    {
        SyncMutation::create([
            'id' => $m['id'], 'company_id' => $companyId, 'user_id' => $userId,
            'entity' => $m['entity'], 'record_id' => $m['record_id'], 'status' => $status,
            'message' => $message,
        ]);

        return $this->resultRow($m, $status, $record, $message, $errors);
    }

    /**
     * $record : l'enregistrement écrit (succès), ou la classe de l'entité (refus, conflit, renvoi) — dans ce cas la version
     * serveur n'est renvoyée que si l'utilisateur a le droit de la voir (droit de lecture + visibilité par fiche).
     */
    protected function resultRow(array $m, string $status, $record, ?string $message, ?array $errors = null): array
    {
        $hidden = false;
        if (is_string($record)) {
            $class = $record;
            $exists = $class::withoutGlobalScopes()->whereKey($m['record_id'])->where('company_id', app('tenant')->id)->exists();
            $record = EntityController::visibleRecord($class, $m['record_id'], request()->user());
            $hidden = $exists && ! $record; // fiche existante mais pas pour cet utilisateur : la copie locale n'est pas effacée à tort
        }

        return [
            'mutation_id' => $m['id'],
            'entity' => $m['entity'],
            'record_id' => $m['record_id'],
            'status' => $status,
            'message' => $message,
            'errors' => $errors,
            'record' => $record?->toSyncArray(),
            'deleted' => ! $hidden && ($record === null || (method_exists($record, 'trashed') && $record->trashed())),
        ];
    }

    protected function latestCursor(): int
    {
        return (int) SyncChange::where('company_id', app('tenant')->id)->max('id');
    }
}
