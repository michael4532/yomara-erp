<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Updates\Publisher;
use App\Services\Updates\Updater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Mises à jour : diffusion publique (plateforme) et mise à jour de l'installation (administrateur). */
class UpdateController extends Controller
{
    // ---------- Côté plateforme (public) ----------

    public function latest(Request $r, Publisher $p)
    {
        abort_unless(config('erp.updates.publisher'), 404);
        $p->recordInstallation($r->query(), $r->ip());

        return ['latest' => $p->latest((string) $r->query('channel', 'stable'), $r->query('version'))];
    }

    public function download(int $id, string $sha)
    {
        abort_unless(config('erp.updates.publisher'), 404);
        $rel = DB::table('upd_releases')->where('id', $id)->where('sha256', $sha)->where('published', true)->first();
        abort_unless($rel && is_file($rel->path), 404);
        DB::table('upd_releases')->where('id', $id)->increment('downloads');

        return response()->download($rel->path, basename($rel->path), ['Content-Type' => 'application/zip']);
    }

    // ---------- Côté installation ----------

    protected function authorizeUpdate(Request $r): void
    {
        $u = $r->user();
        abort_unless($u && ($u->is_super_admin || (config('erp.mode') !== 'saas' && $u->hasPermission('core.settings.manage'))), 403, 'Réservé à l\'administrateur du logiciel.');
    }

    public function status(Request $r, Updater $u)
    {
        $this->authorizeUpdate($r);

        return $u->status($r->boolean('force')) + ['history' => DB::table('upd_history')->latest('id')->limit(10)->get()];
    }

    public function preflight(Request $r, Updater $u)
    {
        $this->authorizeUpdate($r);

        return $u->preflight();
    }

    public function install(Request $r, Updater $u)
    {
        $this->authorizeUpdate($r);
        try {
            return $u->install($r->user()->id);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function finish(Request $r, Updater $u)
    {
        $this->authorizeUpdate($r);
        try {
            return $u->finish();
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function rollback(Request $r, Updater $u)
    {
        $this->authorizeUpdate($r);
        $res = $u->rollback();
        abort_unless($res['ok'], 422, implode(' ', $res['log']));

        return $res;
    }
}
