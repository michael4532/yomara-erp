<?php

use App\Support\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Modules rangés par espace de travail (Ventes & clients, Achats & stocks, Finance…) : mise à jour des catégories existantes. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }
        $registry = app(ModuleRegistry::class);
        foreach (config('erp_catalog.modules', []) as $key => $def) {
            $category = $registry->manifest($key)['category'] ?? $def['category'] ?? null;
            if ($category) {
                DB::table('modules')->where('key', $key)->update(['category' => $category]);
            }
        }
    }

    public function down(): void {}
};
