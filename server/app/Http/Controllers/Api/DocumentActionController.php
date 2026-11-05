<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Actions sur les documents commerciaux : valider, annuler, convertir.
 *   DocumentActionController::routes('documents', SalesDocument::class);
 */
class DocumentActionController extends Controller
{
    public static function routes(string $uri, string $class): void
    {
        $manage = $class::$managePermission;
        foreach ([
            Route::post("$uri/{id}/validate", [static::class, 'validate'])->middleware("perm:$manage"),
            Route::post("$uri/{id}/cancel", [static::class, 'cancel'])->middleware("perm:$manage"),
            Route::post("$uri/{id}/convert", [static::class, 'convert'])->middleware("perm:$manage"),
        ] as $route) {
            $route->setAction(array_merge($route->getAction(), ['entity_class' => $class]));
        }
    }

    protected function find(Request $request, string $id)
    {
        $class = $request->route()->getAction('entity_class');

        return $class::with('lines')->findOrFail($id);
    }

    protected function run(callable $fn)
    {
        try {
            return DB::transaction($fn);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function validate(Request $request, string $id)
    {
        $doc = $this->find($request, $id);

        return $this->run(function () use ($doc) {
            $doc->validateDocument();
            ActivityLog::record('document.validated', $doc, ['number' => $doc->number]);

            return ['data' => $doc->fresh()->toSyncArray()];
        });
    }

    public function cancel(Request $request, string $id)
    {
        $doc = $this->find($request, $id);

        return $this->run(function () use ($doc) {
            $doc->cancelDocument();
            ActivityLog::record('document.cancelled', $doc);

            return ['data' => $doc->fresh()->toSyncArray()];
        });
    }

    public function convert(Request $request, string $id)
    {
        $doc = $this->find($request, $id);
        $type = $request->validate(['type' => 'required|string'])['type'];

        return $this->run(fn () => response()->json(['data' => $doc->convertTo($type)->toSyncArray()], 201));
    }
}
