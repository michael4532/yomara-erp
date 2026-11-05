<?php

namespace App\Modules\Veille;

use App\Http\Controllers\Controller;
use App\Support\Records\RecordController;
use App\Support\Records\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VeilleController extends Controller
{
    public function __construct(private VeilleHooks $h, private VeilleService $svc) {}

    protected function out(string $res, array $row): array
    {
        return app(RecordController::class)->present(Records::resource(__DIR__.'/records.php', $res), [$row])[0];
    }

    protected function row(string $table, int $id): array
    {
        $r = DB::table($table)->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($r, 404, 'Fiche introuvable.');

        return (array) $r;
    }

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function aiReady(): bool
    {
        $c = app('tenant');

        return $c->hasModule('ia') && (bool) rescue(fn () => ($a = app(\App\Modules\Ia\AiService::class)->settings($c)) && $a['enabled'] && $a['configured'], false, false);
    }

    public function dashboard()
    {
        return ['data' => $this->h->dashboard(app('tenant')) + ['ai' => $this->aiReady()]];
    }

    public function compare()
    {
        return ['data' => $this->h->compare(app('tenant'))];
    }

    public function libraries(int $id)
    {
        return ['data' => VeilleHooks::adLibraries($this->row('vc_competitors', $id))];
    }

    public function audit(int $id)
    {
        return $this->guard(function () use ($id) {
            $this->svc->audit(app('tenant'), $id);

            return ['data' => $this->out('competitors', $this->row('vc_competitors', $id))];
        });
    }

    public function battleCard(Request $r, int $id)
    {
        abort_unless($this->aiReady(), 422, 'Activez et configurez le module Assistant IA pour générer la fiche de combat.');

        return $this->guard(function () use ($r, $id) {
            $text = $this->svc->aiBattleCard(app('tenant'), $r->user(), $id);
            DB::table('vc_competitors')->where('company_id', app('tenant')->id)->where('id', $id)->update(['battle_card' => $text, 'updated_at' => now()]);

            return ['data' => $this->out('competitors', $this->row('vc_competitors', $id))];
        });
    }

    public function analysis(Request $r)
    {
        abort_unless($this->aiReady(), 422, 'Activez et configurez le module Assistant IA pour obtenir l\'analyse.');
        $v = $r->validate(['competitor_id' => 'nullable|integer']);

        return $this->guard(fn () => ['data' => ['text' => $this->svc->aiAnalysis(app('tenant'), $r->user(), $v['competitor_id'] ?? null)]]);
    }

    public function checkWatch(int $id)
    {
        $w = (object) $this->row('vc_watches', $id);

        return $this->guard(function () use ($w, $id) {
            $res = $this->svc->check(app('tenant'), $w);

            return ['data' => ['result' => $res, 'watch' => $this->out('watches', $this->row('vc_watches', $id))]];
        });
    }

    public function checkAlert(int $id)
    {
        $a = (object) $this->row('vc_alerts', $id);

        return $this->guard(function () use ($a, $id) {
            $res = $this->svc->fetchAlert(app('tenant'), $a);

            return ['data' => ['result' => $res, 'alert' => $this->out('alerts', $this->row('vc_alerts', $id))]];
        });
    }

    /** Une mention utile devient une observation dans le dossier du concurrent. */
    public function mentionToObservation(Request $r, int $id)
    {
        $m = $this->row('vc_mentions', $id);
        $cid = app('tenant')->id;
        $obs = DB::table('vc_observations')->where('company_id', $cid)->where('source', 'mention')->where('source_url', mb_substr($m['url'], 0, 500))->value('id');
        if (! $obs) {
            $obs = DB::table('vc_observations')->insertGetId(['company_id' => $cid, 'created_by' => $r->user()->id, 'owner_id' => $r->user()->id, 'competitor_id' => $m['competitor_id'],
                'title' => mb_substr($m['title'], 0, 250), 'observed_on' => $m['published_at'] ? substr($m['published_at'], 0, 10) : now()->toDateString(), 'kind' => 'press', 'channel' => 'press',
                'impact' => 'medium', 'status' => 'new', 'source' => 'mention', 'details' => trim(($m['source_name'] ? 'Source : '.$m['source_name']."\n" : '').($m['summary'] ?? '')) ?: null,
                'source_url' => mb_substr($m['url'], 0, 500), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('vc_mentions')->where('id', $id)->update(['status' => 'useful', 'updated_at' => now()]);

        return ['data' => $this->out('observations', $this->row('vc_observations', $obs))];
    }

    public function uploadPhoto(Request $r, int $id)
    {
        $o = $this->row('vc_observations', $id);
        $u = $r->user();
        abort_if(! $u->hasPermission('veille.manage') && (int) $o['created_by'] !== $u->id, 403, 'Seul l\'auteur de l\'observation ou le responsable de la veille peut ajouter la photo.');
        $r->validate(['file' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,heic,heif,pdf'], ['file.mimes' => 'Photo attendue (JPG, PNG, WEBP, HEIC) ou PDF.']);
        $ext = strtolower($r->file('file')->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'], true)) {
            return response()->json(['message' => 'Photo attendue (JPG, PNG, WEBP) ou PDF.'], 422);
        }
        $path = $r->file('file')->storeAs('veille/'.$o['company_id'], $o['id'].'-'.Str::random(8).'.'.$ext, 'local');
        DB::table('vc_observations')->where('id', $id)->update(['photo_path' => $path, 'photo_name' => mb_substr($r->file('file')->getClientOriginalName(), 0, 150), 'updated_at' => now()]);
        if ($o['photo_path'] && $o['photo_path'] !== $path) {
            Storage::disk('local')->delete($o['photo_path']);
        }

        return ['data' => $this->out('observations', $this->row('vc_observations', $id))];
    }

    public function photo(int $id)
    {
        $o = $this->row('vc_observations', $id);
        abort_unless($o['photo_path'] && Storage::disk('local')->exists($o['photo_path']), 404, 'Pas de photo.');

        $path = Storage::disk('local')->path($o['photo_path']);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $safe = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'], true);
        $name = preg_replace('/[^\w.\- ]+/u', '_', $o['photo_name'] ?: basename($o['photo_path']));

        return response()->file($path, ['Content-Type' => $safe ? $mime : 'application/octet-stream', 'Content-Disposition' => ($safe ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff']);
    }
}
