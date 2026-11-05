<?php

namespace App\Modules\Interventions\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Équipement installé chez un client (climatiseur, groupe électrogène, serveur…). */
class Equipment extends Model
{
    use Syncable;

    protected $table = 'it_equipments';

    public static string $syncEntity = 'it_equipments';

    public static string $viewPermission = 'interventions.view|interventions.do|interventions.manage';

    public static string $managePermission = 'interventions.manage|interventions.do';

    public static array $searchable = ['name', 'brand', 'model', 'serial_number', 'location'];

    protected $fillable = ['partner_id', 'contract_id', 'product_id', 'name', 'category', 'brand', 'model', 'serial_number', 'location', 'installed_at', 'warranty_until', 'status', 'notes'];

    protected function casts(): array
    {
        return ['installed_at' => 'date:Y-m-d', 'warranty_until' => 'date:Y-m-d', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'contract_id' => ['nullable', 'uuid', Rule::exists('it_contracts', 'id')->where('company_id', $company)],
            'product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $company)],
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:60',
            'brand' => 'nullable|string|max:80',
            'model' => 'nullable|string|max:80',
            'serial_number' => 'nullable|string|max:120',
            'location' => 'nullable|string|max:255',
            'installed_at' => 'nullable|date',
            'warranty_until' => 'nullable|date',
            'status' => 'nullable|in:active,broken,retired',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    /** Un technicien peut enregistrer un équipement trouvé sur place et noter son état, pas le supprimer. */
    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('interventions.manage')) {
            $data = array_intersect_key($data, array_flip($creating ? ['partner_id', 'name', 'category', 'brand', 'model', 'serial_number', 'location', 'installed_at', 'status', 'notes'] : ['status', 'serial_number', 'location', 'notes', 'brand', 'model']));
        }
    }

    public function beforeEntityDelete(): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('interventions.manage')) {
            throw new \DomainException('Seul le responsable supprime un équipement.');
        }
    }
}
