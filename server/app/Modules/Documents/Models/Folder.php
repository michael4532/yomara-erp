<?php

namespace App\Modules\Documents\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Documents\DocumentAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class Folder extends Model
{
    use Syncable;

    protected $table = 'dc_folders';

    public static string $syncEntity = 'dc_folders';

    public static string $viewPermission = 'documents.view|documents.manage|documents.admin';

    public static string $managePermission = 'documents.manage|documents.admin';

    protected $fillable = ['parent_id', 'name', 'color', 'access', 'role_ids'];

    protected function casts(): array
    {
        return ['role_ids' => 'array', 'version' => 'integer', 'owner_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DocumentAccess::flush());
        static::deleted(fn () => DocumentAccess::flush());
        static::restored(fn () => DocumentAccess::flush());
    }

    public static function syncRules(): array
    {
        return [
            'parent_id' => ['nullable', 'uuid', Rule::exists('dc_folders', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
            'name' => 'required|string|max:120',
            'color' => 'nullable|string|max:9',
            'access' => 'nullable|in:inherit,all,roles,private',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'integer',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        $ids = DocumentAccess::visibleFolderIds($user);
        if ($ids !== null) {
            $q->whereIn('id', $ids ?: ['00000000-0000-0000-0000-000000000000']);
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $data['name'] = trim((string) ($data['name'] ?? $this->name));
        $parent = array_key_exists('parent_id', $data) ? $data['parent_id'] : $this->parent_id;
        if (! $creating && ! DocumentAccess::canSee($user, $this->id)) {
            throw new \DomainException('Dossier inaccessible.');
        }
        if (! DocumentAccess::canSee($user, $parent)) {
            throw new \DomainException('Vous n\'avez pas accès au dossier de destination.');
        }
        if (! $creating && $parent && DocumentAccess::within($parent, $this->id)) {
            throw new \DomainException('Un dossier ne peut pas être déplacé dans l\'un de ses sous-dossiers.');
        }
        if (! $creating && $this->system_key && array_key_exists('parent_id', $data) && $data['parent_id'] !== $this->parent_id) {
            throw new \DomainException('Ce dossier est géré par l\'application : il ne peut pas être déplacé.');
        }
        $access = $data['access'] ?? ($creating ? 'inherit' : $this->access);
        $changingAccess = $creating ? $access !== 'inherit' : ($access !== $this->access || (array_key_exists('role_ids', $data) && array_map('intval', (array) $data['role_ids']) != array_map('intval', (array) $this->role_ids)));
        if ($changingAccess && ! DocumentAccess::isAdmin($user) && ! ($access === 'private' && ($creating || (int) $this->owner_id === (int) $user?->id))) {
            throw new \DomainException('Seul un administrateur des documents modifie les droits d\'un dossier (vous pouvez créer un dossier privé).');
        }
        if ($access === 'roles' && empty($data['role_ids'] ?? $this->role_ids)) {
            throw new \DomainException('Choisissez au moins un rôle autorisé.');
        }
        $dup = static::where('name', $data['name'])->where(fn ($q) => $parent ? $q->where('parent_id', $parent) : $q->whereNull('parent_id'));
        if (! $creating) {
            $dup->where('id', '!=', $this->id);
        }
        if ($dup->exists()) {
            throw new \DomainException('Un dossier porte déjà ce nom ici.');
        }
        if ($creating) {
            $this->owner_id = $user?->id;
            $data['access'] = $access;
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->system_key) {
            throw new \DomainException('Ce dossier est géré par l\'application et ne peut pas être supprimé.');
        }
        if (! DocumentAccess::canSee(auth()->user(), $this->id)) {
            throw new \DomainException('Dossier inaccessible.');
        }
        if (static::where('parent_id', $this->id)->exists() || DocFile::where('folder_id', $this->id)->exists()) {
            throw new \DomainException('Le dossier n\'est pas vide : déplacez ou supprimez d\'abord son contenu.');
        }
    }
}
