<?php

namespace App\Modules\Agenda\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Ressource réservable : salle de réunion, véhicule, matériel. */
class Resource extends Model
{
    use Syncable;

    protected $table = 'ag_resources';

    public static string $syncEntity = 'ag_resources';

    public static string $viewPermission = 'agenda.use|agenda.manage';

    public static string $managePermission = 'agenda.manage';

    protected $fillable = ['name', 'kind', 'color', 'capacity', 'notes', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'capacity' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return ['name' => 'required|string|max:120', 'kind' => 'nullable|in:room,vehicle,equipment', 'color' => 'nullable|string|max:9',
            'capacity' => 'nullable|integer|min:1|max:10000', 'notes' => 'nullable|string|max:255', 'active' => 'nullable|boolean'];
    }

    public function beforeEntityDelete(): void
    {
        if (Event::where('resource_id', $this->id)->where('status', 'confirmed')->where(fn ($q) => $q->whereNotNull('recurrence')->orWhere('ends_at', '>=', now()))->exists()) {
            throw new \DomainException('Des réservations à venir utilisent cette ressource : désactivez-la plutôt.');
        }
    }
}
