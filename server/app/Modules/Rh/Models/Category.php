<?php

namespace App\Modules\Rh\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Catégorie professionnelle (grille de la convention collective) et son salaire minimum. */
class Category extends Model
{
    use Syncable;

    protected $table = 'rh_categories';

    public static string $syncEntity = 'rh_categories';

    public static string $viewPermission = 'rh.view|rh.manage|paie.view|paie.manage';

    public static string $managePermission = 'rh.manage';

    protected $fillable = ['code', 'label', 'group', 'min_salary', 'description', 'sort', 'active'];

    protected function casts(): array
    {
        return ['min_salary' => 'float', 'sort' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'code' => 'required|string|max:30',
            'label' => 'nullable|string|max:120',
            'group' => 'nullable|string|max:40',
            'min_salary' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:2000',
            'sort' => 'nullable|integer|min:0|max:9999',
            'active' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        foreach (['min_salary' => 0, 'sort' => 0, 'active' => true] as $k => $v) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = $v;
            }
        }
        if (isset($data['code'])) {
            $data['code'] = trim($data['code']);
            if (static::where('code', $data['code'])->where('id', '!=', $this->id ?? '')->exists()) {
                throw new \DomainException('La catégorie '.$data['code'].' existe déjà.');
            }
        }
    }

    public function beforeEntityDelete(): void
    {
        if (Position::where('category_id', $this->id)->exists()) {
            throw new \DomainException('Des fiches de poste utilisent cette catégorie : changez-les d\'abord, ou désactivez la catégorie.');
        }
    }
}
