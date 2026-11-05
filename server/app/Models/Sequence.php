<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Sequence extends Model
{
    protected $fillable = ['company_id', 'key', 'year', 'last_number'];

    /**
     * Réserve le prochain numéro (verrou de ligne : pas de doublon même en concurrence).
     * Format : PREFIX-2026-00001
     */
    public static function next(int $companyId, string $key, string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        $attrs = ['company_id' => $companyId, 'key' => $key, 'year' => $year];

        return DB::transaction(function () use ($attrs, $prefix, $year) {
            static::query()->firstOrCreate($attrs, ['last_number' => 0]);

            $seq = static::query()->where($attrs)->lockForUpdate()->first();
            $seq->last_number++;
            $seq->save();

            return sprintf('%s-%d-%05d', $prefix, $year, $seq->last_number);
        });
    }
}
