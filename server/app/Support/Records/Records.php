<?php

namespace App\Support\Records;

use Illuminate\Support\Facades\Route;

/**
 * Fiches déclaratives : un module décrit ses fiches (table, champs, droits) dans un fichier records.php,
 * et obtient l'API complète (schéma, liste filtrée, fiche, création, modification, suppression, recherche).
 * L'application affiche les listes et formulaires à partir du schéma.
 */
class Records
{
    /** @var array<string, array> */
    protected static array $cache = [];

    /** Déclare les routes des fiches dans le groupe de routes du module (préfixe api/{module}). */
    public static function routes(string $file): void
    {
        $c = RecordController::class;
        Route::get('schema', [$c, 'schema'])->defaults('_records', $file);
        Route::get('lookup/{resource}', [$c, 'lookup'])->defaults('_records', $file);
        Route::get('records/{resource}', [$c, 'index'])->defaults('_records', $file);
        Route::get('records/{resource}/{id}', [$c, 'show'])->whereNumber('id')->defaults('_records', $file);
        Route::post('records/{resource}', [$c, 'store'])->defaults('_records', $file);
        Route::put('records/{resource}/{id}', [$c, 'update'])->whereNumber('id')->defaults('_records', $file);
        Route::delete('records/{resource}/{id}', [$c, 'destroy'])->whereNumber('id')->defaults('_records', $file);
    }

    public static function load(string $file): array
    {
        return static::$cache[$file] ??= static::normalize(require $file);
    }

    protected static function normalize(array $def): array
    {
        foreach ($def['resources'] as $key => &$res) {
            $res['key'] = $key;
            $res['title_field'] ??= 'title';
            $res['order'] ??= 'id desc';
            $res['search'] ??= [$res['title_field']];
            foreach ($res['fields'] as &$f) {
                $f['type'] ??= 'text';
                $f['label'] ??= ucfirst($f['key']);
            }
        }

        return $def;
    }

    public static function resource(string $file, string $key): array
    {
        $def = static::load($file);
        abort_unless(isset($def['resources'][$key]), 404, 'Fiche inconnue.');

        return $def['resources'][$key] + ['_hooks' => $def['hooks'] ?? null, '_file' => $file];
    }
}
