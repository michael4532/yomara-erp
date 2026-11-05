<?php

namespace App\Modules\Rapports\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Rapports\Period;
use Illuminate\Database\Eloquent\Model;

/** Tableau de bord : grille de rapports (largeur 1 à 4 colonnes, hauteur 1 à 3). */
class Board extends Model
{
    use SharedVisibility, Syncable;

    protected $table = 'rp_dashboards';

    public static string $syncEntity = 'rp_dashboards';

    public static string $viewPermission = 'rapports.view|rapports.create|rapports.manage';

    public static string $managePermission = 'rapports.create|rapports.manage';

    protected $fillable = ['name', 'description', 'widgets', 'period', 'visibility', 'role_ids', 'sort'];

    protected $attributes = ['visibility' => 'private', 'sort' => 0];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['widgets' => 'array', 'role_ids' => 'array', 'owner_id' => 'integer', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'widgets' => 'nullable|array|max:30',
            'period' => 'nullable|string|max:20',
            'visibility' => 'in:private,company,roles',
            'role_ids' => 'nullable|array',
            'sort' => 'integer|min:0|max:9999',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->guardSharing($data, $creating);
        if (array_key_exists('period', $data) && $data['period'] !== null && ($data['period'] === 'custom' || ! isset(Period::PRESETS[$data['period']]))) {
            throw new \DomainException('Période de tableau de bord inconnue.');
        }
        if (array_key_exists('widgets', $data)) {
            $out = [];
            $ids = Report::whereIn('id', array_filter(array_map(fn ($w) => is_array($w) ? ($w['report_id'] ?? null) : null, (array) $data['widgets'])))->pluck('id')->all();
            foreach ((array) $data['widgets'] as $w) {
                if (! is_array($w) || ! in_array($w['report_id'] ?? null, $ids, true)) {
                    continue;
                }
                $out[] = [
                    'id' => preg_match('/^[\w-]{1,40}$/', (string) ($w['id'] ?? '')) ? (string) $w['id'] : substr(md5(uniqid('', true)), 0, 12),
                    'report_id' => $w['report_id'],
                    'title' => isset($w['title']) && trim((string) $w['title']) !== '' ? mb_substr(trim((string) $w['title']), 0, 120) : null,
                    'w' => max(1, min(4, (int) ($w['w'] ?? 2))),
                    'h' => max(1, min(3, (int) ($w['h'] ?? 1))),
                ];
            }
            $data['widgets'] = $out;
        }
    }
}
