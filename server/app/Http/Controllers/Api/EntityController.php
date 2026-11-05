<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * CRUD REST générique pour toute entité synchronisable.
 *   EntityController::routes('products', Product::class);
 */
class EntityController extends Controller
{
    public static function routes(string $uri, string $class): void
    {
        $view = $class::$viewPermission;
        $manage = $class::$managePermission;
        $routes = [
            Route::get($uri, [static::class, 'index'])->middleware("perm:$view,$manage"),
            Route::get("$uri/{id}", [static::class, 'show'])->middleware("perm:$view,$manage"),
            Route::post($uri, [static::class, 'store'])->middleware("perm:$manage"),
            Route::put("$uri/{id}", [static::class, 'update'])->middleware("perm:$manage"),
            Route::delete("$uri/{id}", [static::class, 'destroy'])->middleware('perm:'.str_replace('|', ',', static::deletePermission($class))),
        ];
        foreach ($routes as $route) {
            $route->setAction(array_merge($route->getAction(), ['entity_class' => $class]));
        }
    }

    /** Droit de suppression : $deletePermission du modèle s'il existe, sinon le droit de gestion. */
    public static function deletePermission(string $class): string
    {
        return property_exists($class, 'deletePermission') ? $class::$deletePermission : $class::$managePermission;
    }

    /** Requête limitée à ce que l'utilisateur peut voir : droit de lecture + visibilité par enregistrement (ses notes de frais, ses pointages…). */
    public static function visibleQuery(string $class, $user, bool $withTrashed = false)
    {
        $q = $withTrashed && method_exists($class, 'bootSoftDeletes') ? $class::withTrashed() : $class::query();
        if (! $user || ! ($user->hasPermission($class::$viewPermission) || $user->hasPermission($class::$managePermission))) {
            return $q->whereRaw('1 = 0');
        }
        if (method_exists($class, 'syncVisibility')) {
            $class::syncVisibility($q, $user);
        }

        return $q;
    }

    /** Enregistrement renvoyé au client seulement s'il a le droit de le voir (sinon null). */
    public static function visibleRecord(string $class, ?string $id, $user)
    {
        return $id ? static::visibleQuery($class, $user, true)->whereKey($id)->first() : null;
    }

    protected function cls(Request $request): string
    {
        return $request->route()->getAction('entity_class');
    }

    public function index(Request $request)
    {
        $class = $this->cls($request);
        $model = new $class;
        $query = static::visibleQuery($class, $request->user());

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($w) use ($class, $q) {
                foreach ($class::$searchable ?? ['name'] as $col) {
                    $w->orWhere($col, 'like', "%$q%");
                }
            });
        }

        foreach ((array) $request->query('filter', []) as $field => $value) {
            if (in_array($field, $model->getFillable(), true)) {
                $query->where($field, $value);
            }
        }

        $sort = $request->query('sort', '-updated_at');
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        if (in_array($col, array_merge($model->getFillable(), ['created_at', 'updated_at']), true)) {
            $query->orderBy($col, $dir);
        }

        if (method_exists($model, 'scopeWithDetails')) {
            $query->withDetails();
        }

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);

        return $query->paginate($perPage);
    }

    public function show(Request $request, string $id)
    {
        $class = $this->cls($request);
        $record = static::visibleQuery($class, $request->user())->whereKey($id)->firstOrFail();

        return ['data' => method_exists($record, 'toSyncArray') ? $record->toSyncArray() : $record];
    }

    public function store(Request $request, EntityWriter $writer)
    {
        $class = $this->cls($request);
        $id = $request->input('id');
        // un identifiant existant mais invisible pour l'utilisateur ne peut pas être réécrit par cette route
        if ($id && $class::withoutGlobalScopes()->whereKey($id)->exists() && ! static::visibleRecord($class, (string) $id, $request->user())) {
            abort(404);
        }

        return $this->respond($writer->upsert($class, $id, $request->all()), 201);
    }

    public function update(Request $request, EntityWriter $writer, string $id)
    {
        $class = $this->cls($request);
        static::visibleQuery($class, $request->user())->whereKey($id)->firstOrFail();
        $base = $request->has('version') ? (int) $request->input('version') : null;

        return $this->respond($writer->upsert($class, $id, $request->except('version'), $base));
    }

    public function destroy(Request $request, EntityWriter $writer, string $id)
    {
        $class = $this->cls($request);
        static::visibleQuery($class, $request->user())->whereKey($id)->firstOrFail();

        return $this->respond($writer->delete($class, $id));
    }

    protected function respond(array $r, int $okStatus = 200)
    {
        $rec = $r['record'];
        if ($rec && $r['status'] !== EntityWriter::APPLIED) {
            // refus ou conflit : la version serveur n'est renvoyée qu'à qui a le droit de la voir
            $rec = static::visibleRecord($rec::class, (string) $rec->getKey(), request()->user());
        }
        $payload = ['data' => $rec?->toSyncArray()];

        return match ($r['status']) {
            EntityWriter::APPLIED => response()->json($payload, $okStatus),
            EntityWriter::CONFLICT => response()->json($payload + ['message' => $r['message'], 'code' => 'conflict'], 409),
            default => response()->json($payload + ['message' => $r['message'], 'errors' => $r['errors']], 422),
        };
    }
}
