<?php

use App\Support\Utf8Html;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Répare les accents abîmés des messages déjà reçus (« reÃ§u » → « reçu ») : le HTML qui déclarait son propre
 * jeu de caractères (Outlook…) était relu en Latin-1 avant 0.99.44. Seules les lignes touchées sont modifiées.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'mb_messages' => ['subject', 'snippet', 'body_html', 'body_text', 'from_name'],
            'mb_signatures' => ['html'],
            'mb_templates' => ['subject', 'html'],
        ];
        foreach ($tables as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $cols = array_values(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c)));
            if (! $cols) {
                continue;
            }
            DB::table($table)
                ->where(function ($q) use ($cols) {
                    foreach ($cols as $c) {
                        foreach (['%Ã%', '%Â%', '%â€%'] as $p) {
                            $q->orWhere($c, 'like', $p);
                        }
                    }
                })
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $cols) {
                    foreach ($rows as $r) {
                        $set = [];
                        foreach ($cols as $c) {
                            $fixed = Utf8Html::fixMojibake($r->{$c});
                            if ($fixed !== $r->{$c}) {
                                $set[$c] = $fixed;
                            }
                        }
                        if ($set) {
                            DB::table($table)->where('id', $r->id)->update($set);
                        }
                    }
                });
        }
    }

    public function down(): void {}
};
