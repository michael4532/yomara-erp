<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Updates\Publisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Super admin de la plateforme : diffuser les mises à jour aux installations clientes. */
class ReleaseAdminController extends Controller
{
    protected function guard(): void
    {
        abort_unless(config('erp.updates.publisher'), 403, 'Cette installation ne diffuse pas de mises à jour (ERP_UPDATE_PUBLISHER).');
    }

    protected function row(object $r): array
    {
        return ['id' => $r->id, 'version' => $r->version, 'channel' => $r->channel, 'notes' => $r->notes, 'size' => (int) $r->size, 'sha256' => $r->sha256,
            'mandatory' => (bool) $r->mandatory, 'published' => (bool) $r->published, 'published_at' => $r->published_at, 'downloads' => (int) $r->downloads,
            'apps' => json_decode((string) $r->apps, true) ?: (object) [], 'created_at' => $r->created_at, 'file' => $r->path && is_file($r->path)];
    }

    public function index()
    {
        $this->guard();
        $releases = DB::table('upd_releases')->get()->sort(fn ($a, $b) => version_compare($b->version, $a->version))->values();
        $installs = DB::table('upd_installations')->orderByDesc('last_seen_at')->limit(300)->get();
        $latest = $releases->firstWhere('published', true);

        return [
            'current' => config('erp.version'),
            'releases' => $releases->map(fn ($r) => $this->row($r)),
            'installations' => $installs,
            'stats' => [
                'installations' => DB::table('upd_installations')->count(),
                'active_30d' => DB::table('upd_installations')->where('last_seen_at', '>=', now()->subDays(30))->count(),
                'up_to_date' => $latest ? DB::table('upd_installations')->where('version', $latest->version)->count() : 0,
                'latest' => $latest?->version,
            ],
        ];
    }

    public function publishSelf(Request $r, Publisher $p)
    {
        $this->guard();
        $d = $r->validate(['channel' => ['nullable', Rule::in(['stable', 'beta'])], 'notes' => ['nullable', 'string', 'max:5000'], 'mandatory' => ['nullable', 'boolean'], 'publish' => ['nullable', 'boolean']]);
        try {
            $rel = $p->packageSelf($d['channel'] ?? 'stable', $d['notes'] ?? null, (bool) ($d['mandatory'] ?? false));
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
        if ($r->boolean('publish')) {
            abort_unless(! empty($rel['signature']), 422, 'Activez d\'abord la clé de signature (Installations › Activer) : les installations refusent les paquets non signés.');
            DB::table('upd_releases')->where('id', $rel['id'])->update(['published' => true, 'published_at' => now()]);
        }

        return $this->row(DB::table('upd_releases')->find($rel['id']));
    }

    public function upload(Request $r, Publisher $p)
    {
        $this->guard();
        $d = $r->validate(['upload_id' => 'required|string|max:64', 'index' => 'required|integer|min:0', 'total' => 'required|integer|min:1|max:2000',
            'chunk' => 'required|file', 'channel' => ['nullable', Rule::in(['stable', 'beta'])], 'notes' => 'nullable|string|max:5000', 'mandatory' => 'nullable|boolean']);
        $done = $p->chunk($d['upload_id'], (int) $d['index'], (int) $d['total'], (string) file_get_contents($r->file('chunk')->getRealPath()));
        if (! $done) {
            return ['received' => (int) $d['index'] + 1, 'total' => (int) $d['total']];
        }
        try {
            $rel = $p->register($done, $d['channel'] ?? 'stable', $d['notes'] ?? null, $r->boolean('mandatory'));
        } catch (\DomainException $e) {
            @unlink($done);
            abort(422, $e->getMessage());
        }

        return ['release' => $this->row(DB::table('upd_releases')->find($rel['id']))];
    }

    public function update(Request $r, int $id)
    {
        $this->guard();
        $rel = DB::table('upd_releases')->find($id) ?? abort(404);
        $d = $r->validate(['published' => 'nullable|boolean', 'mandatory' => 'nullable|boolean', 'notes' => 'nullable|string|max:5000',
            'apps' => 'nullable|array', 'apps.*' => 'nullable|url|max:500']);
        $u = ['updated_at' => now()];
        if ($r->has('published')) {
            if ($r->boolean('published') && empty($rel->signature)) {
                $sig = \App\Licensing\LicenseSigner::signMessage(\App\Licensing\LicenseSigner::releaseMessage($rel->version, $rel->sha256));
                abort_unless($sig, 422, 'Activez d\'abord la clé de signature (Installations › Activer) : les installations refusent les paquets non signés.');
                $u['signature'] = $sig;
            }
            $u['published'] = $r->boolean('published');
            $u['published_at'] = $r->boolean('published') ? ($rel->published_at ?? now()) : null;
        }
        if ($r->has('mandatory')) {
            $u['mandatory'] = $r->boolean('mandatory');
        }
        if ($r->has('notes')) {
            $u['notes'] = $d['notes'];
        }
        if ($r->has('apps')) {
            $u['apps'] = json_encode(array_filter($d['apps'] ?? []));
        }
        DB::table('upd_releases')->where('id', $id)->update($u);

        return $this->row(DB::table('upd_releases')->find($id));
    }

    /** Installe une version envoyée sur CE serveur (la plateforme se met à jour sans cPanel), puis finalisation par system/update/finish. */
    public function installSelf(Request $r, int $id, \App\Services\Updates\Updater $u)
    {
        $this->guard();
        $rel = DB::table('upd_releases')->find($id) ?? abort(404);
        abort_unless($rel->path && is_file($rel->path), 422, 'Paquet introuvable sur le serveur.');
        try {
            return $u->install($r->user()->id, ['version' => $rel->version, 'sha256' => $rel->sha256, 'size' => $rel->size, 'php_min' => $rel->php_min, 'path' => $rel->path]);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function destroy(int $id)
    {
        $this->guard();
        $rel = DB::table('upd_releases')->find($id) ?? abort(404);
        if ($rel->path) {
            @unlink($rel->path);
        }
        DB::table('upd_releases')->where('id', $id)->delete();

        return response()->noContent();
    }
}
