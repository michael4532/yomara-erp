<?php

namespace App\Modules\Materiel\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Matériel de l'entreprise : caméra, ordinateur, outillage, instrument de mesure… */
class Item extends Model
{
    use Syncable;

    protected $table = 'mat_items';

    public static string $syncEntity = 'mat_items';

    public static string $viewPermission = 'materiel.use|materiel.view|materiel.manage';

    public static string $managePermission = 'materiel.manage';

    public const STATUSES = ['available' => 'Disponible', 'out' => 'Sorti', 'repair' => 'En panne / réparation', 'lost' => 'Perdu', 'retired' => 'Réformé'];

    protected $fillable = ['name', 'code', 'category', 'brand', 'model', 'serial', 'department', 'location', 'value', 'status', 'notes'];

    protected function casts(): array
    {
        return ['value' => 'float', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:160',
            'code' => 'nullable|string|max:40',
            'category' => 'nullable|string|max:80',
            'brand' => 'nullable|string|max:80',
            'model' => 'nullable|string|max:80',
            'serial' => 'nullable|string|max:80',
            'department' => 'nullable|string|max:120',
            'location' => 'nullable|string|max:120',
            'value' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'notes' => 'nullable|string|max:5000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (array_key_exists('status', $data) && $data['status'] === null) {
            $data['status'] = 'available';
        }
        $status = $data['status'] ?? ($creating ? 'available' : $this->status);
        $open = $creating ? null : Checkout::openFor($this->id);
        // « Sorti » ne se pose et ne s'enlève que par une sortie / un retour
        if ($status === 'out' && ! $open) {
            throw new \DomainException('Pour sortir ce matériel, enregistrez une sortie.');
        }
        if ($open && $status !== 'out') {
            throw new \DomainException('Ce matériel est sorti ('.$open->number.') : enregistrez d\'abord son retour.');
        }
        if (! empty($data['code']) && static::where('code', $data['code'])->where('id', '!=', $this->id ?? '')->exists()) {
            throw new \DomainException('Le code '.$data['code'].' est déjà utilisé.');
        }
        $data['status'] = $status;
    }

    public function beforeEntityDelete(): void
    {
        if (Checkout::openFor($this->id)) {
            throw new \DomainException('Ce matériel est sorti : enregistrez son retour avant de le supprimer.');
        }
    }
}
