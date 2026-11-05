<?php

namespace App\Modules\Helpdesk\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class Category extends Model
{
    use Syncable;

    protected $table = 'hd_categories';

    public static string $syncEntity = 'hd_categories';

    public static string $viewPermission = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

    public static string $managePermission = 'helpdesk.manage';

    protected $fillable = ['name', 'color', 'assignee_id', 'public', 'sort'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['assignee_id' => 'integer', 'public' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'color' => 'nullable|string|max:9',
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', app('tenant')->id)],
            'public' => 'boolean',
            'sort' => 'nullable|integer|min:0|max:999',
        ];
    }
}
