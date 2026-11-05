<?php

namespace App\Modules\Rapports\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Rapports\ReportEngine;
use App\Modules\Rapports\Sources;
use Illuminate\Database\Eloquent\Model;

/** Rapport enregistré : source, regroupements, mesures, filtres, période relative, présentation. */
class Report extends Model
{
    use SharedVisibility, Syncable;

    protected $table = 'rp_reports';

    public static string $syncEntity = 'rp_reports';

    public static string $viewPermission = 'rapports.view|rapports.create|rapports.manage';

    public static string $managePermission = 'rapports.create|rapports.manage';

    protected $fillable = ['name', 'description', 'source', 'config', 'folder', 'visibility', 'role_ids'];

    protected $attributes = ['visibility' => 'private'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['config' => 'array', 'role_ids' => 'array', 'owner_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'source' => 'required|string|max:40',
            'config' => 'required|array',
            'folder' => 'nullable|string|max:80',
            'visibility' => 'in:private,company,roles',
            'role_ids' => 'nullable|array',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->guardSharing($data, $creating);
        $source = $data['source'] ?? $this->source;
        if (! Sources::get((string) $source)) {
            throw new \DomainException('Source de données inconnue.');
        }
        if (array_key_exists('config', $data) || array_key_exists('source', $data)) {
            $data['config'] = app(ReportEngine::class)->normalize($source, $data['config'] ?? $this->config ?? []);
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
    }

    public function beforeEntityDelete(): void
    {
        if (! $this->canEdit(auth()->user())) {
            throw new \DomainException('Seul son auteur ou un responsable des rapports peut le supprimer.');
        }
        Board::query()->get()->each(function (Board $b) {
            $w = array_values(array_filter($b->widgets ?? [], fn ($x) => ($x['report_id'] ?? null) !== $this->id));
            if (count($w) !== count($b->widgets ?? [])) {
                $b->widgets = $w;
                $b->save();
            }
        });
        Schedule::where('report_id', $this->id)->delete();
    }

    public function toSyncArray(): array
    {
        return $this->toArray();
    }
}
