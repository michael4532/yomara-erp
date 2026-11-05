<?php

namespace App\Modules\Rh\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Rh\DocTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class DocTemplate extends Model
{
    use Syncable;

    protected $table = 'rh_doc_templates';

    public static string $syncEntity = 'rh_doc_templates';

    /** Le salarié reçoit les modèles en libre-service (pour savoir ce qu'il peut demander). */
    public static string $viewPermission = 'rh.view|rh.manage|rh.self';

    public static string $managePermission = 'rh.manage';

    protected $fillable = ['type', 'name', 'body', 'self_service', 'active'];

    protected function casts(): array
    {
        return ['self_service' => 'boolean', 'active' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(DocTemplates::TYPES))],
            'name' => 'required|string|max:160',
            'body' => 'required|string|max:60000',
            'self_service' => 'nullable|boolean',
            'active' => 'nullable|boolean',
        ];
    }

    public static function syncVisibility(\Illuminate\Database\Eloquent\Builder $q, $user): void
    {
        if (! $user->hasPermission('rh.view|rh.manage')) {
            $q->where('active', true)->where('self_service', true);
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (($data['self_service'] ?? $this->self_service) && ! in_array($data['type'] ?? $this->type, ['attestation', 'other'], true)) {
            throw new \DomainException('Seules les attestations peuvent être délivrées en libre-service.');
        }
    }
}
