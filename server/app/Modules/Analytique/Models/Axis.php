<?php

namespace App\Modules\Analytique\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Axe analytique (plan) : Activité, Site, Projets… */
class Axis extends Model
{
    use Syncable;

    protected $table = 'an_axes';

    public static string $syncEntity = 'an_axes';

    public static string $viewPermission = 'analytique.view|analytique.manage|ventes.manage|achats.manage|achats.request|notes_frais.manage|rh.self';

    public static string $managePermission = 'analytique.manage';

    protected $fillable = ['name', 'code', 'required', 'sort', 'active'];

    protected $attributes = ['required' => false, 'active' => true, 'sort' => 0];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'active' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:80',
            'code' => 'nullable|string|max:20',
            'required' => 'boolean',
            'sort' => 'integer|min:0|max:999',
            'active' => 'boolean',
        ];
    }

    public function beforeEntityDelete(): void
    {
        if ($this->system) {
            throw new \DomainException('L\'axe « '.$this->name.' » est géré automatiquement : désactivez-le plutôt.');
        }
        if (AnLine::where('axis_id', $this->id)->exists()) {
            throw new \DomainException('Des montants sont ventilés sur cet axe : désactivez-le plutôt.');
        }
        Section::where('axis_id', $this->id)->get()->each->delete();
    }
}
