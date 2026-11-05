<?php

namespace App\Modules\Immobilisations\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    use Syncable;

    protected $table = 'im_categories';

    public static string $syncEntity = 'im_categories';

    public static string $viewPermission = 'immobilisations.view|immobilisations.manage';

    public static string $managePermission = 'immobilisations.manage';

    public const DEFAULTS = [
        // nom, compte, amortissements, durée, méthode
        ['Logiciels et sites internet', '213', '2813', 3, 'linear'],
        ['Bâtiments', '231', '2831', 20, 'linear'],
        ['Agencements et installations', '234', '2834', 10, 'linear'],
        ['Matériel et outillage', '241', '2841', 5, 'linear'],
        ['Matériel de bureau', '2441', '2844', 5, 'linear'],
        ['Matériel informatique', '2442', '2844', 3, 'linear'],
        ['Mobilier de bureau', '2444', '2844', 10, 'linear'],
        ['Matériel de transport', '245', '2845', 4, 'linear'],
    ];

    protected $fillable = ['name', 'gl_asset', 'gl_depreciation', 'gl_expense', 'method', 'years', 'coefficient', 'active'];

    protected $attributes = ['gl_expense' => '681', 'method' => 'linear', 'years' => 5, 'active' => true];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['years' => 'integer', 'coefficient' => 'float', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'gl_asset' => ['required', 'regex:/^2\d{1,9}$/'],
            'gl_depreciation' => ['required', 'regex:/^28\d{0,8}$/'],
            'gl_expense' => ['sometimes', 'regex:/^6\d{1,9}$/'],
            'method' => 'in:linear,degressive,none',
            'years' => 'integer|min:1|max:100',
            'coefficient' => 'nullable|numeric|min:1|max:4',
            'active' => 'boolean',
        ];
    }

    public function beforeEntityDelete(): void
    {
        if (Asset::where('category_id', $this->id)->exists()) {
            throw new \DomainException('Des immobilisations utilisent cette catégorie : désactivez-la plutôt.');
        }
    }
}
