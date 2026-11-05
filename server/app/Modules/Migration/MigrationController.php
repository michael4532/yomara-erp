<?php

namespace App\Modules\Migration;

use App\Http\Controllers\Controller;
use App\Modules\Migration\Models\MigrationJob;
use App\Modules\Migration\Sources\FileSource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MigrationController extends Controller
{
    public function __construct(protected MigrationService $svc) {}

    protected function row(MigrationJob $j): array
    {
        return $j->only(['id', 'source', 'vendor', 'label', 'options', 'status', 'progress', 'counts', 'warnings', 'error'])
            + ['started_at' => $j->started_at?->toIso8601String(), 'finished_at' => $j->finished_at?->toIso8601String(), 'created_at' => $j->created_at?->toIso8601String(),
                'files' => array_keys($j->files ?? [])];
    }

    public function index()
    {
        $c = app('tenant');

        return [
            'sources' => collect(MigrationService::SOURCES)->map(fn ($s, $k) => ['key' => $k, 'name' => $s[0], 'protocol' => $s[1], 'fields' => $s[2]])->values(),
            'vendors' => collect(FileSource::VENDORS)->map(fn ($v, $k) => ['key' => $k, 'name' => $v[0], 'help' => $v[1]])->values(),
            'currency' => $c->currency,
            'modules' => ['achats' => $c->hasModule('achats'), 'stock' => $c->hasModule('stock')],
            'jobs' => MigrationJob::latest('id')->limit(20)->get()->map(fn ($j) => $this->row($j)),
        ];
    }

    public function connect(Request $r)
    {
        $d = $r->validate(['source' => ['required', Rule::in(array_keys(MigrationService::SOURCES))], 'url' => ['required', 'url:http,https', 'max:300'],
            'api_key' => ['required', 'string', 'max:500'], 'api_secret' => ['nullable', 'string', 'max:500'], 'database' => ['nullable', 'string', 'max:120'], 'login' => ['nullable', 'string', 'max:190']]);
        try {
            \App\Support\UrlGuard::assertPublic($d['url']);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
        if ($d['source'] === 'odoo' && (empty($d['database']) || empty($d['login']))) {
            abort(422, 'Indiquez la base de données et l\'identifiant Odoo.');
        }
        if ($d['source'] === 'erpnext' && empty($d['api_secret'])) {
            abort(422, 'Indiquez le secret d\'API ERPNext.');
        }
        $job = new MigrationJob(['source' => $d['source'], 'user_id' => $r->user()->id, 'secret' => array_diff_key($d, ['source' => 1])]);

        return $this->probe($job);
    }

    public function files(Request $r)
    {
        $r->validate(['vendor' => ['required', Rule::in(array_keys(FileSource::VENDORS))],
            ...collect(['partners', 'products', 'sales', 'purchases'])->mapWithKeys(fn ($k) => [$k => ['nullable', 'file', 'max:30720', 'mimes:xlsx,xls,csv,txt,ods']])->all()]);
        $files = [];
        foreach (['partners', 'products', 'sales', 'purchases'] as $k) {
            if ($f = $r->file($k)) {
                $files[$k] = $f->storeAs('migrations/'.app('tenant')->id, uniqid($k.'-').'.'.strtolower($f->getClientOriginalExtension() ?: 'xlsx'), 'local');
            }
        }
        abort_if(! $files, 422, 'Ajoutez au moins un fichier.');
        $job = new MigrationJob(['source' => 'file', 'vendor' => $r->input('vendor'), 'files' => $files, 'user_id' => $r->user()->id]);

        return $this->probe($job);
    }

    protected function probe(MigrationJob $job)
    {
        try {
            $info = $this->svc->source($job)->probe();
        } catch (\DomainException $e) {
            foreach ($job->files ?? [] as $p) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($p);
            }
            abort(422, $e->getMessage());
        }
        $job->fill(['label' => mb_substr(($info['name'] ?? '').($info['version'] ? ' '.$info['version'] : ''), 0, 120), 'status' => 'ready', 'progress' => ['probe' => $info]]);
        $job->save();

        return $this->row($job) + ['probe' => $info];
    }

    public function run(Request $r, int $id)
    {
        $job = MigrationJob::findOrFail($id);
        abort_if(in_array($job->status, ['queued', 'running'], true), 422, 'Migration déjà en cours.');
        abort_if($job->source !== 'file' && ! $job->secret, 422, 'Connexion expirée : reconnectez la source.');
        $d = $r->validate(['what' => ['required', 'array', 'min:1'], 'what.*' => [Rule::in(['partners', 'products', 'stock', 'sales', 'purchases'])],
            'since' => ['nullable', 'date'], 'rate' => ['nullable', 'numeric', 'min:0.000001', 'max:1000000']]);
        $job->forceFill(['options' => $d, 'status' => 'queued', 'error' => null, 'counts' => null, 'warnings' => null])->save();
        RunMigration::dispatch($job->id, app('tenant')->id);

        return $this->row($job->fresh());
    }

    public function show(int $id)
    {
        return $this->row(MigrationJob::findOrFail($id));
    }

    public function destroy(int $id)
    {
        $job = MigrationJob::findOrFail($id);
        abort_if($job->status === 'running', 422, 'Migration en cours.');
        foreach ($job->files ?? [] as $p) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($p);
        }
        $job->delete();

        return response()->noContent();
    }
}
