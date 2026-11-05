<?php

namespace App\Modules\Pos\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class PosRegister extends Model
{
    use BelongsToBranch, Syncable;

    public static string $syncEntity = 'pos_registers';

    public static array $searchable = ['name', 'code'];

    public static string $viewPermission = 'pos.use';

    public static string $managePermission = 'pos.manage';

    public static bool $branchShared = true;

    protected $fillable = ['name', 'code', 'warehouse_id', 'active', 'branch_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }

    public function branchFallback(): ?string
    {
        return $this->warehouse_id ? \Illuminate\Support\Facades\DB::table('warehouses')->where('id', $this->warehouse_id)->value('branch_id') : null;
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:20',
            'warehouse_id' => 'nullable|uuid',
            'branch_id' => 'nullable|uuid',
            'active' => 'sometimes|boolean',
        ];
    }
}
