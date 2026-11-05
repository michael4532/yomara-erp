<?php

namespace App\Modules\Documents;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Modules\Documents\Models\DocFile;
use App\Modules\Documents\Models\DocShare;
use App\Modules\Documents\Models\DocVersion;
use App\Modules\Documents\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentsController extends Controller
{
    public function __construct(protected DocumentService $svc) {}

    protected function fail(\DomainException $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    protected function file(Request $r, string $id, bool $trashed = false): DocFile
    {
        $f = ($trashed ? DocFile::onlyTrashed() : DocFile::query())->findOrFail($id);
        abort_unless(DocumentAccess::canSee($r->user(), $f->folder_id) || ($trashed && (int) $f->deleted_by === $r->user()->id), 404);

        return $f;
    }

    public function usage()
    {
        return ['data' => $this->svc->usage(app('tenant'))];
    }

    public function upload(Request $r)
    {
        $v = $r->validate([
            'file' => 'required|file', 'folder_id' => 'nullable|uuid', 'name' => 'nullable|string|max:200', 'description' => 'nullable|string|max:5000',
            'tags' => 'nullable|string', 'partner_id' => 'nullable|uuid', 'employee_id' => 'nullable|uuid', 'expires_at' => 'nullable|date',
            'source_type' => 'nullable|string|max:30', 'source_id' => 'nullable|string|max:64',
        ], ['file.required' => 'Choisissez un fichier.', 'file.uploaded' => 'Envoi interrompu ou fichier trop lourd pour le serveur.']);
        if (isset($v['tags'])) {
            $v['tags'] = array_values(array_filter(array_map('trim', is_array($t = json_decode($v['tags'], true)) ? $t : explode(',', $v['tags']))));
        }
        try {
            $f = $this->svc->store(app('tenant'), $r->user(), $r->file('file')->get(), $r->file('file')->getClientOriginalName(), $v['folder_id'] ?? null, $v);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => $f->toSyncArray()], 201);
    }

    public function newVersion(Request $r, string $id)
    {
        $f = $this->file($r, $id);
        $v = $r->validate(['file' => 'required|file', 'comment' => 'nullable|string|max:255']);
        try {
            $f = $this->svc->store(app('tenant'), $r->user(), $r->file('file')->get(), $r->file('file')->getClientOriginalName(), $f->folder_id, [], $f, $v['comment'] ?? null);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['data' => $f->toSyncArray()];
    }

    public function download(Request $r, string $id)
    {
        $f = $this->file($r, $id, $r->boolean('trash'));
        $v = $r->filled('v') ? DocVersion::where('file_id', $f->id)->where('number', (int) $r->query('v'))->firstOrFail() : DocVersion::where('file_id', $f->id)->where('number', $f->current_version)->first();
        $path = $v?->path ?? $f->path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Fichier introuvable sur le serveur.');
        $name = $v && $v->number !== $f->current_version ? pathinfo($f->name, PATHINFO_FILENAME).' (v'.$v->number.')'.($f->extension ? '.'.$f->extension : '') : $f->name;

        return response()->file(Storage::disk('local')->path($path), \App\Support\SafeDownload::headers($v?->mime ?? $f->mime, (string) $name, $r->boolean('inline')));
    }

    public function versions(Request $r, string $id)
    {
        $f = $this->file($r, $id);
        $users = User::where('company_id', $f->company_id)->pluck('name', 'id');

        return ['data' => DocVersion::where('file_id', $f->id)->orderByDesc('number')->get()->map(fn ($v) => $v->toArray() + ['user_name' => $users[$v->user_id] ?? null, 'current' => $v->number === $f->current_version])];
    }

    public function restoreVersion(Request $r, string $id, int $number)
    {
        $f = $this->file($r, $id);
        try {
            $f = $this->svc->restoreVersion(app('tenant'), $r->user(), $f, $number);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['data' => $f->toSyncArray()];
    }

    public function trash(Request $r)
    {
        $q = DocFile::onlyTrashed()->orderByDesc('deleted_at');
        if (! DocumentAccess::isAdmin($r->user())) {
            $q->where('deleted_by', $r->user()->id);
        }
        $users = User::where('company_id', app('tenant')->id)->pluck('name', 'id');

        return ['data' => $q->limit(500)->get()->map(fn ($f) => $f->toArray() + ['deleted_by_name' => $users[$f->deleted_by] ?? null, 'path_label' => DocumentAccess::path($f->folder_id), 'purge_at' => $f->deleted_at->copy()->addDays(30)->toDateString()])];
    }

    public function restore(Request $r, string $id)
    {
        $f = $this->file($r, $id, true);
        $this->svc->restore($f);

        return ['data' => $f->fresh()->toSyncArray()];
    }

    public function purge(Request $r, string $id)
    {
        abort_unless(DocumentAccess::isAdmin($r->user()), 403, 'Réservé aux administrateurs des documents.');
        $this->svc->purge(DocFile::onlyTrashed()->findOrFail($id));

        return ['ok' => true];
    }

    public function emptyTrash(Request $r)
    {
        abort_unless(DocumentAccess::isAdmin($r->user()), 403, 'Réservé aux administrateurs des documents.');
        $n = 0;
        foreach (DocFile::onlyTrashed()->get() as $f) {
            $this->svc->purge($f);
            $n++;
        }

        return ['purged' => $n];
    }

    public function search(Request $r)
    {
        $v = $r->validate(['q' => 'required|string|max:120', 'folder_id' => 'nullable|uuid']);

        return ['data' => $this->svc->search($r->user(), $v['q'], $v['folder_id'] ?? null)];
    }

    public function shares(Request $r)
    {
        $q = DocShare::query()->whereNull('revoked_at')->orderByDesc('created_at');
        if ($r->filled('file_id')) {
            $this->file($r, $r->query('file_id'));
            $q->where('file_id', $r->query('file_id'));
        } elseif ($r->filled('folder_id')) {
            abort_unless(DocumentAccess::canSee($r->user(), $r->query('folder_id')), 404);
            $q->where('folder_id', $r->query('folder_id'));
        }

        return ['data' => $q->get()->filter(fn ($s) => DocumentAccess::canSee($r->user(), $s->folder_id ?? DocFile::find($s->file_id)?->folder_id))
            ->map(fn ($s) => $s->toArray() + ['url' => $s->url(), 'active' => $s->active()])->values()];
    }

    public function share(Request $r)
    {
        $v = $r->validate(['file_id' => 'required_without:folder_id|nullable|uuid', 'folder_id' => 'required_without:file_id|nullable|uuid',
            'expires_days' => 'nullable|integer|min:1|max:365', 'password' => 'nullable|string|min:4|max:60', 'max_downloads' => 'nullable|integer|min:1|max:10000']);
        $file = ! empty($v['file_id']) ? $this->file($r, $v['file_id']) : null;
        $folder = null;
        if (! $file) {
            $folder = Folder::findOrFail($v['folder_id']);
            abort_unless(DocumentAccess::canSee($r->user(), $folder->id), 404);
        }
        $s = $this->svc->share($r->user(), $file, $folder, $v);

        return response()->json(['data' => $s->toArray() + ['url' => $s->url(), 'active' => true]], 201);
    }

    public function revoke(Request $r, int $id)
    {
        $s = DocShare::findOrFail($id);
        abort_unless(DocumentAccess::canSee($r->user(), $s->folder_id ?? DocFile::withTrashed()->find($s->file_id)?->folder_id), 404);
        $s->forceFill(['revoked_at' => now()])->save();

        return ['ok' => true];
    }

    public function roles()
    {
        return ['data' => Role::where('company_id', app('tenant')->id)->orderBy('name')->get(['id', 'name'])];
    }
}
