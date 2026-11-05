<?php

namespace App\Modules\RendezVous\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Prestation réservable (coupe, consultation, visite, cours…). */
class Service extends Model
{
    use Syncable;

    protected $table = 'rdv_services';

    public static string $syncEntity = 'rdv_services';

    public static string $viewPermission = 'rendez_vous.view|rendez_vous.own|rendez_vous.manage';

    public static string $managePermission = 'rendez_vous.manage';

    public const LOCATIONS = ['onsite' => 'Sur place', 'home' => 'À domicile', 'online' => 'En visio'];

    protected $fillable = ['name', 'category', 'description', 'duration_minutes', 'buffer_minutes', 'price', 'product_id', 'location', 'staff_ids', 'online', 'requires_approval', 'color', 'sort', 'active'];

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'buffer_minutes' => 'integer', 'price' => 'float', 'staff_ids' => 'array', 'online' => 'boolean',
            'requires_approval' => 'boolean', 'sort' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
            'duration_minutes' => 'required|integer|min:5|max:720',
            'buffer_minutes' => 'nullable|integer|min:0|max:240',
            'price' => 'nullable|numeric|min:0',
            'product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $company)],
            'location' => 'nullable|in:'.implode(',', array_keys(self::LOCATIONS)),
            'staff_ids' => 'nullable|array',
            'staff_ids.*' => 'uuid',
            'online' => 'nullable|boolean',
            'requires_approval' => 'nullable|boolean',
            'color' => 'nullable|string|max:9',
            'sort' => 'nullable|integer|min:0',
            'active' => 'nullable|boolean',
        ];
    }

    public function allowsStaff(string $staffId): bool
    {
        return empty($this->staff_ids) || in_array($staffId, $this->staff_ids, true);
    }
}
