<?php

namespace App\Modules\Prestations\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\Partner;
use App\Modules\Prestations\PrestationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Livrable soumis à la validation du client (maquette, site de recette, rapport, vidéo…). */
class Deliverable extends Model
{
    use Syncable;

    protected $table = 'pr_deliverables';

    public static string $syncEntity = 'pr_deliverables';

    public static string $viewPermission = 'prestations.view|prestations.log|prestations.manage';

    public static string $managePermission = 'prestations.log|prestations.manage';

    public static array $searchable = ['number', 'title', 'partner_name'];

    public const STATUSES = ['draft' => 'Brouillon', 'sent' => 'Envoyé au client', 'approved' => 'Validé', 'changes' => 'Modifications demandées'];

    protected $fillable = ['partner_id', 'pack_id', 'project_id', 'title', 'description', 'url', 'due_date', 'status'];

    protected $attributes = ['status' => 'draft', 'version_no' => 1];

    protected $hidden = ['company_id', 'deleted_at', 'file_path'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'sent_at' => 'datetime', 'decided_at' => 'datetime', 'history' => 'array', 'version_no' => 'integer', 'file_size' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'pack_id' => ['nullable', 'uuid', Rule::exists('pr_packs', 'id')->where('company_id', $c)],
            'project_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('prj_projects')],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'url' => 'nullable|url|max:500',
            'due_date' => 'nullable|date',
            'status' => 'in:draft,sent',
            'new_version' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $newVersion = ! empty($data['new_version']);
        unset($data['new_version']);
        if (isset($data['partner_id']) || $creating) {
            if (! $creating && $this->sent_at && $data['partner_id'] !== $this->partner_id) {
                throw new \DomainException('Livrable déjà envoyé : le client ne change plus.');
            }
            $this->partner_name = Partner::find($data['partner_id'] ?? $this->partner_id)?->name;
        }
        if ($creating) {
            $this->number = Sequence::next(app('tenant')->id, 'pr_deliverable', 'LIV');
            $this->token = Str::random(40);
        }
        if ($newVersion) {
            if (! in_array($this->status, ['changes', 'approved'], true)) {
                throw new \DomainException('Une nouvelle version suit une décision du client.');
            }
            $this->version_no++;
            $this->status = 'draft';
            $this->decided_at = null;
            $this->decided_by = null;
            $this->client_comment = null;
            $this->reminded = false;
        } elseif (! $creating && in_array($this->status, ['approved'], true) && array_diff(array_keys($data), ['status'])) {
            throw new \DomainException('Livrable validé par le client : créez une nouvelle version pour le modifier.');
        }
        $to = $data['status'] ?? null;
        if ($to === 'sent' && $this->status !== 'sent') {
            $u = auth()->user();
            if ($u && ! $u->hasPermission('prestations.manage')) {
                throw new \DomainException('L\'envoi au client est réservé aux responsables.');
            }
            if (empty($data['url'] ?? $this->url) && ! $this->file_path && empty($data['description'] ?? $this->description)) {
                throw new \DomainException('Ajoutez un fichier, un lien ou une description avant l\'envoi.');
            }
            $this->sent_at = now();
            $this->history = array_merge($this->history ?? [], [['v' => $this->version_no, 'event' => 'sent', 'at' => now()->toIso8601String(), 'by' => $u?->name]]);
        } elseif ($to === 'draft' && $this->status === 'sent') {
            throw new \DomainException('Livrable déjà envoyé : attendez la décision du client.');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->wasChanged('sent_at') && $this->sent_at && $this->status === 'sent') {
            app(PrestationService::class)->sendDeliverable(app('tenant'), $this);
        }
    }

    public function publicUrl(): string
    {
        return url('/livrable/'.$this->token);
    }

    public function beforeEntityDelete(): void
    {
        if ($this->sent_at) {
            throw new \DomainException('Livrable déjà envoyé au client : il reste dans l\'historique.');
        }
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['number' => $this->number, 'partner_name' => $this->partner_name, 'version_no' => $this->version_no, 'file_name' => $this->file_name,
            'file_size' => $this->file_size, 'sent_at' => $this->sent_at?->toIso8601String(), 'decided_at' => $this->decided_at?->toIso8601String(),
            'decided_by' => $this->decided_by, 'client_comment' => $this->client_comment, 'history' => $this->history ?? [], 'public_url' => $this->token ? $this->publicUrl() : null];
    }
}
