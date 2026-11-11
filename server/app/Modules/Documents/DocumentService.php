<?php

namespace App\Modules\Documents;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\Documents\Models\DocFile;
use App\Modules\Documents\Models\DocShare;
use App\Modules\Documents\Models\DocVersion;
use App\Modules\Documents\Models\Folder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    public const BLOCKED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'sh', 'cgi', 'pl', 'htaccess', 'jsp', 'asp', 'aspx', 'vbs', 'ps1', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'js', 'mjs', 'hta'];

    /** Espace en plus acheté dans la boutique des modules (packs « Stockage »), en Mo. */
    public const PACKS = ['stockage_10' => 10240, 'stockage_20' => 20480, 'stockage_50' => 51200, 'stockage_100' => 102400];

    public function packActive(Company $company, string $key): bool
    {
        return (bool) $company->moduleOverrides()->where('module_key', $key)->get()->first(fn ($o) => $o->isEffective());
    }

    public function extraMb(Company $company): int
    {
        // Seuls les packs réellement achetés (ou offerts) comptent, pas « tous les modules » d'une formule ou d'une installation
        $mb = 0;
        foreach ($company->moduleOverrides()->whereIn('module_key', array_keys(self::PACKS))->get() as $o) {
            if ($o->isEffective()) {
                $mb += self::PACKS[$o->module_key];
            }
        }

        return $mb;
    }

    /** Plateforme en ligne (SaaS) : l'espace est fourni par l'éditeur, donc limité et vendu par packs. */
    public static function hosted(): bool
    {
        return config('erp.mode') === 'saas';
    }

    public function userUsed(Company $company, int $userId): int
    {
        return (int) DocVersion::withoutGlobalScopes()->where('company_id', $company->id)->where('user_id', $userId)->sum('size');
    }

    public function settings(Company $company): array
    {
        // Espace partagé par toute la société : 2 Go en ligne (ou le quota fixé par l'éditeur), plus les packs achetés.
        // Installation sur le serveur du client : son propre disque, pas de limite par défaut.
        $base = (int) ($company->setting('documents_quota_mb') ?: config('erp.documents_quota_mb')
            ?: (self::hosted() ? config('erp.documents_company_quota_mb', 2048) : 1048576));

        return [
            'quota_mb' => $base + $this->extraMb($company),
            'max_file_mb' => (int) config('erp.documents_max_file_mb', 50),
            'trash_days' => 30,
            'expiry_notices' => [30, 7, 0],
        ];
    }

    public function usage(Company $company): array
    {
        $used = (int) DocVersion::withoutGlobalScopes()->where('company_id', $company->id)->sum('size');
        $s = $this->settings($company);

        $me = request()?->user();
        $mine = $me && $me->company_id === $company->id ? ['my_used' => $this->userUsed($company, $me->id)] : [];

        return $mine + ['used' => $used, 'quota' => $s['quota_mb'] * 1024 * 1024, 'extra' => $this->extraMb($company) * 1048576, 'files' => DocFile::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            'trash' => DocFile::withoutGlobalScopes()->where('company_id', $company->id)->whereNotNull('deleted_at')->count(), 'max_file' => $s['max_file_mb'] * 1024 * 1024];
    }

    public static function human(int|float $bytes): string
    {
        return $bytes >= 1073741824 ? rtrim(rtrim(number_format($bytes / 1073741824, 1, ',', ''), '0'), ',').' Go' : round($bytes / 1048576).' Mo';
    }

    /** Espace utilisé par chaque personne, sur l'espace partagé de la société (administrateur des documents). */
    public function people(Company $company): array
    {
        $used = DocVersion::withoutGlobalScopes()->where('company_id', $company->id)->selectRaw('user_id, SUM(size) as total')->groupBy('user_id')->pluck('total', 'user_id');
        return User::where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'used' => (int) ($used[$u->id] ?? 0)])
            ->sortByDesc('used')->values()->all();
    }

    /** Contenu de la version en cours d'un fichier (pièce jointe d'un e-mail…), où qu'il soit stocké. */
    public function bytes(DocFile $file): ?string
    {
        $v = DocVersion::withoutGlobalScopes()->where('file_id', $file->id)->where('number', $file->current_version)->first();
        try {
            return $file->path ? app(\App\Storage\StoragePool::class)->get($v?->backend_id, $v?->path ?? $file->path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Réponse de téléchargement d'une version, où qu'elle soit stockée (serveur ou stockage externe). */
    public function serve(?DocVersion $v, ?string $path, array $headers)
    {
        $pool = app(\App\Storage\StoragePool::class);
        $path = $v?->path ?? $path;
        abort_unless($path && $pool->exists($v?->backend_id, $path), 404, 'Fichier introuvable sur le serveur.');
        try {
            [$local, $temp] = $pool->localPath($v?->backend_id, $path);
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'Le stockage de ce fichier ne répond pas. Réessayez dans un instant.');
        }

        $res = response()->file($local, $headers);

        return $temp ? $res->deleteFileAfterSend(true) : $res;
    }

    protected function disk()
    {
        return Storage::disk('local');
    }

    /**
     * Dépôt d'un fichier, ou d'une nouvelle version si $file est fourni.
     *
     * @param  array  $meta  description, tags, partner_id, employee_id, source_type, source_id, expires_at
     */
    public function store(Company $company, ?User $user, string $bytes, string $originalName, ?string $folderId, array $meta = [], ?DocFile $file = null, ?string $comment = null, bool $checkAccess = true): DocFile
    {
        $originalName = trim(str_replace(['/', '\\', "\0"], '-', $originalName)) ?: 'document';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED, true) || str_starts_with($originalName, '.')) {
            throw new \DomainException('Ce type de fichier n\'est pas autorisé.');
        }
        $s = $this->settings($company);
        if (strlen($bytes) > $s['max_file_mb'] * 1024 * 1024) {
            throw new \DomainException('Fichier trop lourd ('.$s['max_file_mb'].' Mo maximum).');
        }
        $u = $this->usage($company);
        if ($u['used'] + strlen($bytes) > $u['quota']) {
            throw new \DomainException('Espace de stockage de la société plein ('.self::human($u['quota']).'). Videz la corbeille, supprimez d\'anciennes versions ou ajoutez de l\'espace (Documents › Espace de stockage).');
        }
        if ($checkAccess) {
            if ($file && (! DocumentAccess::canSee($user, $file->folder_id) || ! $file->canEdit($user))) {
                throw new \DomainException('Document verrouillé ou inaccessible.');
            }
            if (! $file && ! DocumentAccess::canSee($user, $folderId)) {
                throw new \DomainException('Vous n\'avez pas accès à ce dossier.');
            }
        }
        if ($folderId && ! Folder::find($folderId)) {
            throw new \DomainException('Dossier introuvable.');
        }

        return DB::transaction(function () use ($company, $user, $bytes, $originalName, $ext, $folderId, $meta, $file, $comment) {
            $creating = ! $file;
            $file ??= new DocFile;
            if ($creating) {
                $file->id = (string) Str::uuid();
                $file->company_id = $company->id;
                $file->owner_id = $user?->id;
                $file->folder_id = $folderId;
                $file->current_version = 0;
            }
            $n = $file->current_version + 1;
            $path = 'documents/'.$company->id.'/'.$file->id.'/v'.$n.'-'.Str::random(6).($ext ? '.'.$ext : '');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
            // Stockage : le premier disponible par ordre de priorité (serveur, R2, B2…), bascule automatique si plein
            $backend = app(\App\Storage\StoragePool::class)->put($path, $bytes, $mime);
            $v = new DocVersion(['file_id' => $file->id, 'number' => $n, 'path' => $path, 'name' => mb_substr($originalName, 0, 200), 'mime' => $mime, 'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes), 'user_id' => $user?->id, 'backend_id' => $backend, 'comment' => $comment ? mb_substr($comment, 0, 255) : ($creating ? null : 'Nouvelle version')]);
            $v->company_id = $company->id;
            $v->save();
            if ($creating) {
                $file->fill(array_intersect_key($meta, array_flip(['description', 'tags', 'partner_id', 'employee_id', 'expires_at'])));
                $file->name = mb_substr($meta['name'] ?? $originalName, 0, 200);
                $file->source_type = $meta['source_type'] ?? null;
                $file->source_id = $meta['source_id'] ?? null;
                if ($ext && ! str_ends_with(mb_strtolower($file->name), '.'.$ext)) {
                    $file->name .= '.'.$ext;
                }
            }
            $file->forceFill(['path' => $path, 'mime' => $mime, 'extension' => $ext ?: null, 'size' => strlen($bytes), 'sha256' => $v->sha256, 'current_version' => $n]);
            $tmp = tempnam(sys_get_temp_dir(), 'gedx');
            file_put_contents($tmp, $bytes);
            try {
                $file->content_text = TextExtractor::extract($tmp, $ext);
            } finally {
                @unlink($tmp);
            }
            $file->refreshSearch();
            $file->save();

            return $file->fresh();
        });
    }

    /** Dossier créé et entretenu par l'application (ex. « Documents signés »). */
    public function systemFolder(Company $company, string $key, string $name, array $permissions = []): Folder
    {
        $f = Folder::where('system_key', $key)->first();
        if ($f) {
            return $f;
        }
        $roles = Role::withoutGlobalScopes()->where('company_id', $company->id)->get()
            ->filter(fn ($r) => collect($r->permissions ?? [])->contains(fn ($p) => $p === '*' || $p === 'documents.admin' || $p === 'documents.*' || in_array($p, $permissions, true) || collect($permissions)->contains(fn ($w) => str_ends_with($p, '.*') && str_starts_with($w, substr($p, 0, -1)))))
            ->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        $f = new Folder(['name' => $name, 'access' => $roles ? 'roles' : 'inherit', 'role_ids' => $roles ?: null]);
        $f->id = (string) Str::uuid();
        $f->company_id = $company->id;
        $f->system_key = $key;
        $f->save();

        return $f;
    }

    /** Classement automatique d'un document produit par un autre module. */
    public function fileFromBytes(Company $company, string $key, string $folderName, string $bytes, string $name, array $meta = [], array $permissions = []): ?DocFile
    {
        if (! $company->hasModule('documents')) {
            return null;
        }
        $folder = $this->systemFolder($company, $key, $folderName, $permissions);
        if (! empty($meta['source_id']) && ($existing = DocFile::where('source_type', $meta['source_type'] ?? null)->where('source_id', $meta['source_id'])->first())) {
            return $existing;
        }

        return $this->store($company, null, $bytes, $name, $folder->id, $meta, null, null, false);
    }

    public function restoreVersion(Company $company, User $user, DocFile $file, int $number): DocFile
    {
        $v = DocVersion::where('file_id', $file->id)->where('number', $number)->firstOrFail();
        if ($number === $file->current_version) {
            throw new \DomainException('C\'est déjà la version en cours.');
        }

        return $this->store($company, $user, app(\App\Storage\StoragePool::class)->get($v->backend_id, $v->path), $v->name, $file->folder_id, [], $file, 'Retour à la version '.$number);
    }

    public function restore(DocFile $file): void
    {
        if ($file->folder_id && ! Folder::find($file->folder_id)) {
            $file->folder_id = null; // dossier supprimé entre-temps : retour à la racine
        }
        $file->deleted_by = null;
        $file->restore();
    }

    public function purge(DocFile $file): void
    {
        foreach (DocVersion::where('file_id', $file->id)->get() as $v) {
            app(\App\Storage\StoragePool::class)->delete($v->backend_id, $v->path, (int) $v->size);
            $v->delete();
        }
        $this->disk()->deleteDirectory('documents/'.$file->company_id.'/'.$file->id);
        DocShare::where('file_id', $file->id)->delete();
        $file->forceDelete();
    }

    public function share(User $user, ?DocFile $file, ?Folder $folder, array $o): DocShare
    {
        $token = Str::random(32);
        $s = new DocShare(['file_id' => $file?->id, 'folder_id' => $folder?->id, 'max_downloads' => ! empty($o['max_downloads']) ? (int) $o['max_downloads'] : null,
            'expires_at' => ! empty($o['expires_days']) ? now()->addDays((int) $o['expires_days']) : null]);
        $s->company_id = ($file ?? $folder)->company_id;
        $s->token = $token;
        $s->token_hash = hash('sha256', $token);
        $s->password_hash = ! empty($o['password']) ? Hash::make($o['password']) : null;
        $s->created_by = $user->id;
        $s->save();

        return $s;
    }

    /** Recherche dans le nom, les mots-clés, la description et le contenu des documents visibles. */
    public function search(User $user, string $q, ?string $folderId = null, int $limit = 100): array
    {
        $norm = DocFile::normalize($q);
        if (mb_strlen($norm) < 2) {
            return [];
        }
        $query = DocFile::query()->where('search_text', 'like', '%'.$norm.'%');
        DocFile::syncVisibility($query, $user);
        if ($folderId) {
            $ids = DocumentAccess::folders()->keys()->filter(fn ($id) => DocumentAccess::within($id, $folderId))->values()->all();
            $query->whereIn('folder_id', $ids);
        }
        $rows = $query->orderByDesc('updated_at')->limit($limit)->get(['id', 'name', 'folder_id', 'content_text', 'description', 'tags', 'updated_at', 'extension', 'size']);

        return $rows->map(function ($f) use ($q, $norm) {
            $where = DocFile::normalize($f->name.' '.implode(' ', (array) $f->tags).' '.$f->description);
            return ['id' => $f->id, 'name' => $f->name, 'folder_id' => $f->folder_id, 'path' => DocumentAccess::path($f->folder_id), 'extension' => $f->extension, 'size' => $f->size,
                'in_content' => ! str_contains($where, $norm), 'snippet' => $this->snippet((string) $f->content_text, $q)];
        })->values()->all();
    }

    protected function snippet(string $text, string $q): ?string
    {
        if ($text === '') {
            return null;
        }
        $word = collect(preg_split('/\s+/u', trim($q)))->sortByDesc(fn ($w) => mb_strlen($w))->first();
        $pos = $word ? mb_stripos($text, $word) : false;
        if ($pos === false) {
            return null;
        }
        $start = max(0, $pos - 70);

        return ($start > 0 ? '…' : '').trim(preg_replace('/\s+/u', ' ', mb_substr($text, $start, 180))).'…';
    }

    /** Tâche quotidienne : rappels d'échéance, corbeille vidée après 30 jours. */
    public function maintenance(Company $company): array
    {
        $s = $this->settings($company);
        $notified = 0;
        $admins = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('documents.admin'))->pluck('id')->all();
        foreach (DocFile::whereNotNull('expires_at')->where('expires_at', '<=', today()->addDays(max($s['expiry_notices'])))->get() as $f) {
            $days = (int) today()->diffInDays($f->expires_at, false);
            $step = collect($s['expiry_notices'])->sort()->first(fn ($n) => $days <= $n);
            if ($step === null || ($f->expiry_notice !== null && $f->expiry_notice <= $step)) {
                continue;
            }
            $title = $days < 0 ? 'Document échu' : ($days === 0 ? 'Document échu aujourd\'hui' : 'Échéance dans '.$days.' jour'.($days > 1 ? 's' : ''));
            AppNotification::send($company->id, array_unique(array_filter([$f->owner_id, ...$admins])), 'documents', $title, $f->name.' — '.$f->expires_at->format('d/m/Y'), 'documents', $f->id);
            $f->forceFill(['expiry_notice' => $step])->saveQuietly();
            $notified++;
        }
        $purged = 0;
        foreach (DocFile::onlyTrashed()->where('deleted_at', '<', now()->subDays($s['trash_days']))->get() as $f) {
            $this->purge($f);
            $purged++;
        }
        foreach (DocShare::whereNotNull('expires_at')->where('expires_at', '<', now()->subDays(30))->get() as $sh) {
            $sh->delete();
        }

        return ['notified' => $notified, 'purged' => $purged];
    }
}
