<?php

namespace App\Modules\Helpdesk\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Délais garantis (première réponse et résolution) par priorité, en minutes. */
class SlaPolicy extends Model
{
    use Syncable;

    protected $table = 'hd_sla_policies';

    public static string $syncEntity = 'hd_sla_policies';

    public static string $viewPermission = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

    public static string $managePermission = 'helpdesk.manage';

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    public const DEFAULT_TARGETS = ['urgent' => ['first' => 30, 'resolve' => 240], 'high' => ['first' => 60, 'resolve' => 480],
        'normal' => ['first' => 240, 'resolve' => 1440], 'low' => ['first' => 480, 'resolve' => 2880]];

    protected $fillable = ['name', 'targets', 'business_hours', 'is_default', 'partner_ids'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['targets' => 'array', 'business_hours' => 'boolean', 'is_default' => 'boolean', 'partner_ids' => 'array', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'targets' => 'required|array',
            'targets.*.first' => 'required|integer|min:5|max:43200',
            'targets.*.resolve' => 'required|integer|min:5|max:129600',
            'business_hours' => 'boolean',
            'is_default' => 'boolean',
            'partner_ids' => 'nullable|array',
            'partner_ids.*' => 'uuid',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        foreach (array_keys((array) ($data['targets'] ?? [])) as $p) {
            if (! in_array($p, self::PRIORITIES, true)) {
                throw new \DomainException('Priorité inconnue : '.$p);
            }
        }
        foreach ((array) ($data['targets'] ?? []) as $p => $t) {
            if ($t['resolve'] < $t['first']) {
                throw new \DomainException('La résolution ne peut pas être plus rapide que la première réponse.');
            }
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->is_default) {
            static::where('id', '!=', $this->id)->where('is_default', true)->get()->each(fn ($p) => $p->forceFill(['is_default' => false])->save());
        }
    }

    public function target(string $priority): array
    {
        return ($this->targets ?? [])[$priority] ?? self::DEFAULT_TARGETS[$priority] ?? self::DEFAULT_TARGETS['normal'];
    }
}
