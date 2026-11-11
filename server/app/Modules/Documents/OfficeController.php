<?php

namespace App\Modules\Documents;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Modules\Documents\Models\DocFile;
use App\Modules\Documents\Models\DocVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Bureautique en ligne (Yomara Office) : Word, Excel et PowerPoint ouverts et modifiés dans l'application.
 * L'éditeur tourne dans le navigateur (ou la vue intégrée de l'application) ; chaque enregistrement crée une
 * nouvelle version du document. Une seule personne modifie à la fois (verrou renouvelé toutes les 15 s),
 * les autres voient le fichier en lecture et savent qui le modifie.
 */
class OfficeController extends Controller
{
    public const DOCS = ['docx'];
    public const SHEETS = ['xlsx', 'xls', 'ods', 'csv'];
    public const SLIDES = ['pptx'];
    public const LOCK_SECONDS = 60;

    public function __construct(protected DocumentService $svc) {}

    public static function kindOf(?string $ext): ?string
    {
        $ext = strtolower((string) $ext);

        return in_array($ext, self::DOCS, true) ? 'doc' : (in_array($ext, self::SHEETS, true) ? 'sheet' : (in_array($ext, self::SLIDES, true) ? 'slides' : null));
    }

    protected function session(DocFile $f, User $u, bool $canEdit): array
    {
        $token = Str::random(40);
        Cache::put('office:'.$token, ['file' => $f->id, 'user' => $u->id, 'company' => $f->company_id, 'edit' => $canEdit], now()->addHours(12));

        return ['url' => url('/office/'.$token), 'token' => $token, 'kind' => self::kindOf($f->extension)];
    }

    /** Ouvrir un document dans l'éditeur en ligne (connexion de l'application). */
    public function open(Request $r, string $id)
    {
        $f = DocFile::findOrFail($id);
        abort_unless(DocumentAccess::canSee($r->user(), $f->folder_id), 404);
        abort_unless(self::kindOf($f->extension), 422, 'Ce format ne s\'ouvre pas dans l\'éditeur en ligne (Word .docx, Excel .xlsx/.xls/.ods/.csv, PowerPoint .pptx).');
        $canEdit = ($r->user()->hasPermission('documents.manage') || $r->user()->hasPermission('documents.admin')) && $f->canEdit($r->user()) && self::kindOf($f->extension) !== 'slides';

        return ['data' => $this->session($f, $r->user(), $canEdit)];
    }

    /** Nouveau document Word, Excel ou PowerPoint vierge, ouvert aussitôt dans l'éditeur. */
    public function create(Request $r)
    {
        $v = $r->validate(['kind' => 'required|in:docx,xlsx', 'name' => 'nullable|string|max:150', 'folder_id' => 'nullable|uuid']);
        $name = trim($v['name'] ?? '') ?: ($v['kind'] === 'docx' ? 'Nouveau document' : 'Nouveau classeur');
        $name = preg_replace('/\.(docx|xlsx)$/i', '', $name).'.'.$v['kind'];
        try {
            $f = $this->svc->store(app('tenant'), $r->user(), $v['kind'] === 'docx' ? BlankFiles::docx() : BlankFiles::xlsx(), $name, $v['folder_id'] ?? null, [], null, 'Création');
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->session($f, $r->user(), true) + ['file' => $f->toSyncArray()]], 201);
    }

    /** @return array{0: array, 1: DocFile, 2: User, 3: Company} */
    protected function ctx(string $token): array
    {
        $s = Cache::get('office:'.$token);
        abort_unless($s, 410, 'Session expirée : rouvrez le document depuis l\'application.');
        $company = Company::findOrFail($s['company']);
        app()->instance('tenant', $company);
        $user = User::where('company_id', $company->id)->findOrFail($s['user']);
        auth()->setUser($user);
        $f = DocFile::findOrFail($s['file']);

        return [$s, $f, $user, $company];
    }

    /** Fichier d'entrée de l'éditeur (nom avec empreinte, lu dans le manifeste de construction). */
    public static function entry(): string
    {
        $m = json_decode((string) @file_get_contents(public_path('office/manifest.json')), true);

        return $m['src/main.jsx']['file'] ?? 'office.js';
    }

    /** Page de l'éditeur. */
    public function page(string $token)
    {
        [$s, $f, $u] = $this->ctx($token);
        $lock = $this->lock($f, $u, $token, $s['edit']);

        return response()->view('office.editor', [
            'config' => [
                'name' => $f->name, 'ext' => strtolower((string) $f->extension), 'kind' => self::kindOf($f->extension), 'version' => $f->current_version,
                'mode' => $s['edit'] && $lock['mine'] ? 'edit' : 'view', 'lockedBy' => $lock['mine'] ? null : $lock['name'],
                'canEdit' => $s['edit'], 'user' => ['name' => $u->name, 'email' => $u->email],
                'content' => url('/api/office/'.$token.'/content'), 'save' => url('/api/office/'.$token.'/save'), 'ping' => url('/api/office/'.$token.'/ping'),
            ],
            'ver' => config('erp.version'),
            'entry' => self::entry(),
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function content(string $token)
    {
        [, $f] = $this->ctx($token);
        $v = DocVersion::where('file_id', $f->id)->where('number', $f->current_version)->first();
        $bytes = app(\App\Storage\StoragePool::class)->get($v?->backend_id, $v?->path ?? $f->path);
        $ext = strtolower((string) $f->extension);
        // Anciens formats de tableur : convertis en .xlsx pour l'éditeur
        if (in_array($ext, ['xls', 'ods'], true)) {
            $bytes = BlankFiles::toXlsx($bytes, $ext);
        }

        return response($bytes, 200, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store']);
    }

    public function save(Request $r, string $token)
    {
        [$s, $f, $u, $company] = $this->ctx($token);
        abort_unless($s['edit'], 403, 'Vous ne pouvez pas modifier ce document.');
        $lock = $this->lock($f, $u, $token, true);
        if (! $lock['mine']) {
            return response()->json(['message' => $lock['name'].' modifie ce document en ce moment : vos changements n\'ont pas été enregistrés.'], 409);
        }
        $r->validate(['file' => 'required|file|max:102400']);
        $ext = strtolower((string) $f->extension);
        $name = in_array($ext, ['xls', 'ods'], true) ? preg_replace('/\.(xls|ods)$/i', '.xlsx', $f->name) : $f->name;
        try {
            $f = $this->svc->store($company, $u, $r->file('file')->get(), $name, $f->folder_id, [], $f, 'Modifié en ligne', false);
            if ($name !== $f->name) {
                $f->forceFill(['name' => $name])->save();
            }
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => ['version' => $f->current_version, 'saved_at' => now()->toIso8601String()]];
    }

    /** Présence et verrou de modification (appelé toutes les 15 s par l'éditeur). */
    public function ping(Request $r, string $token)
    {
        [$s, $f, $u] = $this->ctx($token);
        $lock = $this->lock($f, $u, $token, $s['edit'] && $r->boolean('editing'));
        $key = 'office-presence:'.$f->id;
        $people = collect(Cache::get($key, []))->filter(fn ($p) => $p['at'] > now()->subSeconds(45)->timestamp);
        $people[$token] = ['name' => $u->name, 'at' => now()->timestamp];
        Cache::put($key, $people->all(), now()->addMinutes(5));
        $f->refresh();

        return ['data' => [
            'version' => $f->current_version, 'lock' => $lock,
            'people' => $people->map(fn ($p, $t) => ['name' => $p['name'], 'me' => $t === $token])->values(),
        ]];
    }

    /** Prend ou renouvelle le verrou si possible ; renvoie qui le détient. */
    protected function lock(DocFile $f, User $u, string $token, bool $want): array
    {
        $key = 'office-lock:'.$f->id;
        $cur = Cache::get($key);
        if ($cur && $cur['token'] !== $token && $cur['at'] > now()->subSeconds(self::LOCK_SECONDS)->timestamp) {
            return ['mine' => false, 'name' => $cur['name']];
        }
        if ($want) {
            Cache::put($key, ['token' => $token, 'name' => $u->name, 'at' => now()->timestamp], now()->addSeconds(self::LOCK_SECONDS * 2));

            return ['mine' => true, 'name' => $u->name];
        }

        return ['mine' => false, 'name' => null];
    }
}
