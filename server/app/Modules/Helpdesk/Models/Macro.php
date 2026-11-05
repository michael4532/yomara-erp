<?php

namespace App\Modules\Helpdesk\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Réponse type ({client} et {agent} remplacés à l'insertion). */
class Macro extends Model
{
    use Syncable;

    protected $table = 'hd_macros';

    public static string $syncEntity = 'hd_macros';

    public static string $viewPermission = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

    public static string $managePermission = 'helpdesk.manage';

    protected $fillable = ['name', 'body', 'set_status'];

    protected $hidden = ['company_id', 'deleted_at'];

    public static function syncRules(): array
    {
        return ['name' => 'required|string|max:120', 'body' => 'required|string|max:5000', 'set_status' => 'nullable|in:open,pending,resolved'];
    }
}
