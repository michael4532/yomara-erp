<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Stage extends Model
{
    use Syncable;

    protected $table = 'crm_stages';

    public static string $syncEntity = 'crm_stages';

    public static string $viewPermission = 'crm.view|crm.manage';

    public static string $managePermission = 'crm.manage';

    protected $fillable = ['name', 'sequence', 'probability', 'kind', 'color'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'probability' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'sequence' => 'sometimes|integer|min:0|max:999',
            'probability' => 'sometimes|integer|min:0|max:100',
            'kind' => 'sometimes|in:open,won,lost',
            'color' => 'nullable|string|max:9',
        ];
    }

    public function beforeEntityDelete(): void
    {
        if (Lead::where('stage_id', $this->id)->exists()) {
            throw new \DomainException('Cette étape contient des opportunités : déplacez-les avant de la supprimer.');
        }
        if ($this->kind !== 'open' && static::where('kind', $this->kind)->count() <= 1) {
            throw new \DomainException('Il faut garder au moins une étape « gagné » et une étape « perdu ».');
        }
    }
}
