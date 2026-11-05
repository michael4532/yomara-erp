<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class Activity extends Model
{
    use Syncable;

    protected $table = 'crm_activities';

    public static string $syncEntity = 'crm_activities';

    public static string $viewPermission = 'crm.view|crm.manage';

    public static string $managePermission = 'crm.manage';

    public static array $searchable = ['subject'];

    public const TYPES = ['call' => 'Appel', 'meeting' => 'Rendez-vous', 'visit' => 'Visite', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'task' => 'Tâche'];

    protected $fillable = ['lead_id', 'partner_id', 'type', 'subject', 'due_at', 'done', 'done_at', 'owner_id', 'notes'];

    protected function casts(): array
    {
        return ['done' => 'boolean', 'due_at' => 'datetime', 'done_at' => 'datetime', 'owner_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'lead_id' => ['nullable', 'uuid', Rule::exists('crm_leads', 'id')->where('company_id', $company)],
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'type' => 'sometimes|in:'.implode(',', array_keys(self::TYPES)),
            'subject' => 'required|string|max:255',
            'due_at' => 'nullable|date',
            'done' => 'sometimes|boolean',
            'done_at' => 'nullable|date',
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'notes' => 'nullable|string|max:5000',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->owner_id ??= auth()->id();
        });
        static::saving(function (self $a) {
            if ($a->done && ! $a->done_at) {
                $a->done_at = now();
            }
            if (! $a->done) {
                $a->done_at = null;
            }
        });
        // Prospect (comme Dolibarr) : la première action faite le passe à « Contact en cours »
        static::saved(function (self $a) {
            if ($a->done && ($a->wasRecentlyCreated || $a->wasChanged('done'))) {
                $pid = $a->partner_id ?: ($a->lead_id ? Lead::whereKey($a->lead_id)->value('partner_id') : null);
                \App\Modules\Core\Models\Partner::markContacted($pid);
            }
        });
    }
}
