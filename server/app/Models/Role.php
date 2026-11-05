<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'parent_id', 'description', 'color', 'permissions', 'is_system'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Fonctions placées au-dessus (de la plus proche à la plus haute). */
    public function ancestorIds(): array
    {
        $ids = [];
        $cur = $this->parent_id;
        while ($cur && ! in_array($cur, $ids, true) && count($ids) < 50) {
            $ids[] = $cur;
            $cur = static::withoutGlobalScopes()->where('id', $cur)->value('parent_id');
        }

        return $ids;
    }

    /**
     * Vérifie une permission. Jokers acceptés : "*" (tout), "ventes.*", "*.view".
     */
    /**
     * Droits sensibles accordés uniquement nommément (ou par « * », l'administrateur) :
     * un joker de module comme « rh.* » ne les donne pas.
     */
    public const EXPLICIT = ['rh.contracts'];

    public static function wildcardGrants(string $granted, string $permission): bool
    {
        return fnmatch($granted, $permission) && ! in_array($permission, self::EXPLICIT, true);
    }

    public function allows(string $permission): bool
    {
        foreach ($this->permissions ?? [] as $granted) {
            if ($granted === '*' || $granted === $permission || self::wildcardGrants($granted, $permission)) {
                return true;
            }
        }

        return false;
    }
}
