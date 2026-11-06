<?php

namespace App\Modules\Core\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    use Syncable;

    public static string $syncEntity = 'product_categories';

    public static array $searchable = ['name'];

    public static string $viewPermission = 'core.products.view';

    public static string $managePermission = 'core.products.manage';

    protected $fillable = ['parent_id', 'name'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'parent_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('product_categories')],
            'name' => 'required|string|max:255',
        ];
    }
}
