<?php

namespace App\Modules\Documents\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Documents\DocumentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Fichier de la GED : les métadonnées se synchronisent (hors ligne), le contenu se télécharge à la demande. */
class DocFile extends Model
{
    use Syncable;

    protected $table = 'dc_files';

    public static string $syncEntity = 'dc_files';

    public static string $viewPermission = 'documents.view|documents.manage|documents.admin';

    public static string $managePermission = 'documents.manage|documents.admin';

    protected $fillable = ['folder_id', 'name', 'description', 'tags', 'partner_id', 'employee_id', 'expires_at', 'locked'];

    protected $hidden = ['path', 'content_text', 'search_text', 'company_id'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'expires_at' => 'date:Y-m-d', 'locked' => 'boolean', 'size' => 'integer', 'current_version' => 'integer', 'version' => 'integer', 'owner_id' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'folder_id' => ['nullable', 'uuid', Rule::exists('dc_folders', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:40',
            'partner_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('partners')],
            'employee_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('rh_employees')],
            'expires_at' => 'nullable|date',
            'locked' => 'nullable|boolean',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        $ids = DocumentAccess::visibleFolderIds($user);
        if ($ids !== null) {
            $q->where(fn ($w) => $w->whereNull('folder_id')->orWhereIn('folder_id', $ids ?: ['00000000-0000-0000-0000-000000000000']));
        }
    }

    public function canEdit($user): bool
    {
        return ! $this->locked || (int) $this->owner_id === (int) $user?->id || DocumentAccess::isAdmin($user);
    }

    public static function normalize(?string $s): string
    {
        $s = mb_strtolower((string) $s);
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n']);

        return preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
    }

    public function refreshSearch(): void
    {
        $this->search_text = mb_substr(static::normalize($this->name.' '.$this->description.' '.implode(' ', (array) $this->tags)).' '.static::normalize($this->content_text), 0, 400000);
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($creating) {
            throw new \DomainException('Déposez le fichier depuis l\'application connectée.');
        }
        if (! DocumentAccess::canSee($user, $this->folder_id)) {
            throw new \DomainException('Document inaccessible.');
        }
        if (array_key_exists('folder_id', $data) && ! DocumentAccess::canSee($user, $data['folder_id'])) {
            throw new \DomainException('Vous n\'avez pas accès au dossier de destination.');
        }
        $lockChange = array_key_exists('locked', $data) && (bool) $data['locked'] !== (bool) $this->locked;
        if (! $this->canEdit($user) || ($lockChange && (int) $this->owner_id !== (int) $user?->id && ! DocumentAccess::isAdmin($user))) {
            throw new \DomainException('Document verrouillé par son propriétaire.');
        }
        if (isset($data['name'])) {
            $name = trim(str_replace(['/', '\\'], '-', $data['name']));
            if ($this->extension && ! str_ends_with(mb_strtolower($name), '.'.mb_strtolower($this->extension))) {
                $name .= '.'.$this->extension;
            }
            $data['name'] = $name;
        }
        if (isset($data['tags'])) {
            $data['tags'] = array_values(array_unique(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 40), (array) $data['tags']))));
        }
        if (array_key_exists('expires_at', $data) && ($data['expires_at'] ?? null) !== optional($this->expires_at)->toDateString()) {
            $this->expiry_notice = null;
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if (array_intersect(array_keys($data), ['name', 'description', 'tags'])) {
            $this->refreshSearch();
            $this->saveQuietly();
        }
    }

    public function beforeEntityDelete(): void
    {
        $user = auth()->user();
        if (! DocumentAccess::canSee($user, $this->folder_id) || ! $this->canEdit($user)) {
            throw new \DomainException('Document verrouillé ou inaccessible.');
        }
        $this->deleted_by = $user?->id;
        $this->saveQuietly();
    }

    public function toSyncArray(): array
    {
        return $this->toArray();
    }
}
