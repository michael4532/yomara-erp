<?php

namespace App\Modules\Tresorerie\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Entrée ou sortie prévue (loyer mensuel, échéance de prêt, salaires, rentrée attendue…). */
class Forecast extends Model
{
    use Syncable;

    protected $table = 'tr_forecasts';

    public static string $syncEntity = 'tr_forecasts';

    public static string $viewPermission = 'tresorerie.view|tresorerie.manage';

    public static string $managePermission = 'tresorerie.manage';

    protected $fillable = ['label', 'amount', 'date', 'repeat', 'until', 'category', 'scenarios'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'date' => 'date:Y-m-d', 'until' => 'date:Y-m-d', 'version' => 'integer', 'scenarios' => 'array'];
    }

    public static function syncRules(): array
    {
        return [
            'label' => 'required|string|max:255',
            'amount' => 'required|numeric|not_in:0',
            'date' => 'required|date',
            'repeat' => 'nullable|in:none,weekly,monthly',
            'until' => 'nullable|date|after_or_equal:date',
            'category' => 'nullable|string|max:30',
            'scenarios' => 'nullable|array|min:1',
            'scenarios.*' => 'in:optimistic,realistic,pessimistic',
        ];
    }
}
