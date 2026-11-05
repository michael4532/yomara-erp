<?php

namespace App\Support\Records;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/** API générique des fiches déclarées dans un records.php de module. */
class RecordController extends Controller
{
    // ------------------------------------------------------------------ droits

    protected static function allows(User $u, string|array|null $perms): bool
    {
        if ($perms === null || $perms === '') {
            return true;
        }
        foreach ((array) (is_string($perms) ? explode('|', $perms) : $perms) as $p) {
            if ($u->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    public static function canView(User $u, array $res): bool
    {
        return static::allows($u, $res['view'] ?? null) || static::canManage($u, $res);
    }

    public static function canManage(User $u, array $res): bool
    {
        return static::allows($u, $res['manage'] ?? null);
    }

    protected function res(Request $r, string $key, bool $manage = false): array
    {
        $res = Records::resource($r->route('_records'), $key);
        abort_unless($manage ? static::canManage($r->user(), $res) : static::canView($r->user(), $res), 403, 'Accès refusé.');
        // Fiches en lecture seule (journal, listes venant d'un autre module) : jamais modifiables par cette route, même avec « tous les droits »
        abort_if($manage && ! empty($res['readonly_resource']), 403, 'Ces fiches ne se modifient pas ici.');

        return $res;
    }

    protected function hooks(array $res): ?object
    {
        return empty($res['_hooks']) ? null : app($res['_hooks']);
    }

    protected function table(array $res)
    {
        return DB::table($res['table'])->where($res['table'].'.company_id', app('tenant')->id);
    }

    // ------------------------------------------------------------------ schéma

    public function schema(Request $r)
    {
        $def = Records::load($r->route('_records'));
        $u = $r->user();
        $out = [];
        foreach ($def['resources'] as $key => $res) {
            if (! static::canView($u, $res)) {
                continue;
            }
            $out[$key] = [
                'key' => $key, 'label' => $res['label'], 'plural' => $res['plural'] ?? $res['label'], 'icon' => $res['icon'] ?? null,
                'title_field' => $res['title_field'], 'can_manage' => empty($res['readonly_resource']) && static::canManage($u, $res),
                'can_delete' => empty($res['readonly_resource']) && static::canManage($u, $res) && static::allows($u, $res['delete'] ?? ($res['manage'] ?? null)),
                'date_field' => $res['date_field'] ?? null, 'hint' => $res['hint'] ?? null,
                'fields' => array_map(fn ($f) => $this->fieldSchema($f), $res['fields']),
            ];
        }
        $c = app('tenant');

        return ['data' => [
            'resources' => $out,
            'users' => User::where('company_id', $c->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($x) => ['id' => $x->id, 'name' => $x->name])->values(),
            'me' => $u->id,
            'currency' => $c->currency ?? config('erp.defaults.currency'),
        ]];
    }

    protected function fieldSchema(array $f): array
    {
        $o = Arr::only($f, ['key', 'label', 'type', 'required', 'list', 'hint', 'default', 'half', 'readonly', 'resource', 'filter', 'computed', 'show_if']);
        if (isset($f['options'])) {
            $o['options'] = collect($f['options'])->map(fn ($v, $k) => is_array($v) ? ['value' => (string) $k, 'label' => $v[0], 'color' => $v[1] ?? null] : ['value' => (string) $k, 'label' => $v])->values();
        }

        return $o;
    }

    // ------------------------------------------------------------------ lecture

    public function index(Request $r, string $resource)
    {
        $res = $this->res($r, $resource);
        $q = $this->table($res);
        foreach ($res['fields'] as $f) {
            if (! empty($f['computed'])) {
                continue;
            }
            $v = $r->query($f['key']);
            if ($v === null || $v === '') {
                continue;
            }
            if ($f['type'] === 'tags') {
                $q->where($f['key'], 'like', '%"'.str_replace(['%', '"'], '', $v).'"%');
            } elseif ($v === '__none') {
                $q->whereNull($f['key']);
            } else {
                $q->whereIn($f['key'], explode(',', (string) $v));
            }
        }
        if ($s = trim((string) $r->query('q'))) {
            $q->where(function ($w) use ($res, $s) {
                foreach ($res['search'] as $col) {
                    $w->orWhere($col, 'like', '%'.$s.'%');
                }
            });
        }
        if ($df = $res['date_field'] ?? null) {
            if ($from = $r->query('from')) {
                $q->where($df, '>=', $from);
            }
            if ($to = $r->query('to')) {
                $q->where($df, '<=', strlen($to) === 10 ? $to.' 23:59:59' : $to);
            }
        }
        if ($scope = $res['scope'] ?? null) {
            $scope($q, $r->user(), static::canManage($r->user(), $res));
        }
        foreach (explode(',', $res['order']) as $o) {
            [$col, $dir] = array_pad(explode(' ', trim($o)), 2, 'asc');
            $q->orderBy($col, $dir);
        }
        $rows = $q->limit(min(1000, (int) $r->query('limit', 500)))->get()->map(fn ($x) => (array) $x)->all();

        return ['data' => $this->present($res, $rows)];
    }

    public function show(Request $r, string $resource, int $id)
    {
        $res = $this->res($r, $resource);

        return ['data' => $this->present($res, [$this->find($res, $id)])[0]];
    }

    protected function find(array $res, int $id): array
    {
        $row = $this->scoped($res, $this->table($res)->where('id', $id))->first();
        abort_unless($row, 404, 'Fiche introuvable.');

        return (array) $row;
    }

    /** Restriction « mes fiches » de la ressource (ex. un commercial ne voit que ses visites), comme dans la liste. */
    protected function scoped(array $res, $q)
    {
        if (($scope = $res['scope'] ?? null) && ($u = request()->user())) {
            $scope($q, $u, static::canManage($u, $res));
        }

        return $q;
    }

    /** Valeurs décodées, libellés des références et champs calculés. */
    public function present(array $res, array $rows): array
    {
        if (! $rows) {
            return [];
        }
        $labels = [];
        foreach ($res['fields'] as $f) {
            $ids = array_values(array_unique(array_filter(array_column($rows, $f['key']), fn ($v) => $v !== null && $v !== '')));
            if (! $ids || ! in_array($f['type'], ['user', 'partner', 'employee', 'ref'], true)) {
                continue;
            }
            $labels[$f['key']] = $this->labels($res, $f, $ids);
        }
        $hooks = $this->hooks($res);
        $out = [];
        foreach ($rows as $row) {
            foreach ($res['fields'] as $f) {
                $k = $f['key'];
                if (! array_key_exists($k, $row)) {
                    continue;
                }
                $v = $row[$k];
                $row[$k] = match ($f['type']) {
                    'tags' => $v ? (json_decode($v, true) ?: []) : [],
                    'bool' => (bool) $v,
                    'number', 'money' => $v === null ? null : (float) $v,
                    default => $v,
                };
                if (isset($labels[$k])) {
                    $row[$k.'_label'] = $labels[$k][(string) $v] ?? null;
                }
            }
            $out[] = $hooks && method_exists($hooks, 'present') ? $hooks->present($res['key'], $row) : $row;
        }

        return $out;
    }

    protected function labels(array $res, array $f, array $ids): array
    {
        $cid = app('tenant')->id;

        return match ($f['type']) {
            'user' => DB::table('users')->where('company_id', $cid)->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all(),
            'partner' => DB::table('partners')->where('company_id', $cid)->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all(),
            'employee' => Schema::hasTable('rh_employees') ? DB::table('rh_employees')->where('company_id', $cid)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($e) => [(string) $e->id => trim($e->first_name.' '.$e->last_name)])->all() : [],
            'ref' => (function () use ($res, $f, $ids, $cid) {
                $t = Records::resource($res['_file'] ?? $this->fileOf(), $f['resource']);

                return DB::table($t['table'])->where('company_id', $cid)->whereIn('id', $ids)->pluck($t['title_field'], 'id')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();
            })(),
            default => [],
        };
    }

    protected function fileOf(): string
    {
        return request()->route('_records');
    }

    /** Recherche pour les listes déroulantes : fiche du module, tiers ou salariés. */
    public function lookup(Request $r, string $resource)
    {
        $s = trim((string) $r->query('q'));
        $cid = app('tenant')->id;
        $like = '%'.$s.'%';
        if (in_array($resource, ['partner', 'employee'], true)) {
            $def = Records::load($r->route('_records'));
            $needs = collect($def['resources'])->filter(fn ($res) => static::canView($r->user(), $res) && collect($res['fields'])->contains(fn ($f) => ($f['type'] ?? '') === $resource));
            abort_if($needs->isEmpty(), 403, 'Accès refusé.');
        }
        $rows = match ($resource) {
            'partner' => DB::table('partners')->where('company_id', $cid)->whereNull('deleted_at')->when($s, fn ($q) => $q->where('name', 'like', $like))
                ->orderBy('name')->limit(30)->get(['id', 'name'])->map(fn ($x) => ['id' => (string) $x->id, 'label' => $x->name]),
            'employee' => Schema::hasTable('rh_employees') ? DB::table('rh_employees')->where('company_id', $cid)->whereNull('deleted_at')
                ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('matricule', 'like', $like)))
                ->orderBy('last_name')->limit(30)->get(['id', 'first_name', 'last_name', 'job_title'])
                ->map(fn ($x) => ['id' => (string) $x->id, 'label' => trim($x->first_name.' '.$x->last_name), 'detail' => $x->job_title]) : collect(),
            default => (function () use ($r, $resource, $s, $like) {
                $res = $this->res($r, $resource);

                return $this->scoped($res, $this->table($res))->when($s, fn ($q) => $q->where($res['title_field'], 'like', $like))->orderByDesc('id')->limit(50)
                    ->get(['id', $res['title_field']])->map(fn ($x) => ['id' => (string) $x->id, 'label' => $x->{$res['title_field']}]);
            })(),
        };

        return ['data' => $rows->values()];
    }

    // ------------------------------------------------------------------ écriture

    public function store(Request $r, string $resource)
    {
        $res = $this->res($r, $resource, true);
        $data = $this->validated($r, $res, null);

        return $this->run(function () use ($r, $res, $data) {
            $u = $r->user();
            $hooks = $this->hooks($res);
            foreach ($res['fields'] as $f) {
                if (! array_key_exists($f['key'], $data) && array_key_exists('default', $f) && empty($f['computed'])) {
                    $data[$f['key']] = match ($f['default']) {
                        '@me' => $u->id, '@today' => now()->toDateString(), '@now' => now()->format('Y-m-d H:i:s'), default => $f['default'],
                    };
                }
            }
            if ($hooks && method_exists($hooks, 'saving')) {
                $data = $hooks->saving($res['key'], $data, null, $u);
            }
            $row = $this->encode($res, $data) + ['company_id' => app('tenant')->id, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()];
            $id = DB::table($res['table'])->insertGetId($row);
            $saved = $this->find($res, $id);
            if ($hooks && method_exists($hooks, 'saved')) {
                $hooks->saved($res['key'], $saved, null, $u);
                $saved = $this->find($res, $id);
            }

            return response()->json(['data' => $this->present($res, [$saved])[0]], 201);
        });
    }

    public function update(Request $r, string $resource, int $id)
    {
        $res = $this->res($r, $resource, true);
        $old = $this->find($res, $id);
        $data = $this->validated($r, $res, $old);

        return $this->run(function () use ($r, $res, $data, $old, $id) {
            $u = $r->user();
            $hooks = $this->hooks($res);
            if ($hooks && method_exists($hooks, 'saving')) {
                $data = $hooks->saving($res['key'], $data, $old, $u);
            }
            DB::table($res['table'])->where('id', $id)->update($this->encode($res, $data) + ['updated_at' => now()]);
            $saved = $this->find($res, $id);
            if ($hooks && method_exists($hooks, 'saved')) {
                $hooks->saved($res['key'], $saved, $old, $u);
                $saved = $this->find($res, $id);
            }

            return ['data' => $this->present($res, [$saved])[0]];
        });
    }

    public function destroy(Request $r, string $resource, int $id)
    {
        $res = $this->res($r, $resource, true);
        abort_unless(static::allows($r->user(), $res['delete'] ?? ($res['manage'] ?? null)), 403, 'Vous n\'avez pas le droit de supprimer cette fiche.');
        $old = $this->find($res, $id);

        return $this->run(function () use ($r, $res, $old, $id) {
            $hooks = $this->hooks($res);
            if ($hooks && method_exists($hooks, 'deleting')) {
                $hooks->deleting($res['key'], $old, $r->user());
            }
            DB::table($res['table'])->where('id', $id)->delete();

            return response()->noContent();
        });
    }

    protected function run(callable $fn)
    {
        try {
            return DB::transaction($fn);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function encode(array $res, array $data): array
    {
        foreach ($res['fields'] as $f) {
            if (! array_key_exists($f['key'], $data)) {
                continue;
            }
            if ($f['type'] === 'tags') {
                $data[$f['key']] = json_encode(array_values((array) ($data[$f['key']] ?? [])), JSON_UNESCAPED_UNICODE);
            } elseif ($f['type'] === 'bool') {
                $data[$f['key']] = (bool) $data[$f['key']];
            } elseif ($f['type'] === 'datetime' && $data[$f['key']]) {
                $data[$f['key']] = \Illuminate\Support\Carbon::parse($data[$f['key']])->format('Y-m-d H:i:s');
            } elseif ($f['type'] === 'date' && $data[$f['key']]) {
                $data[$f['key']] = substr((string) $data[$f['key']], 0, 10);
            }
        }

        return $data;
    }

    protected function validated(Request $r, array $res, ?array $old): array
    {
        $rules = [];
        $creating = $old === null;
        foreach ($res['fields'] as $f) {
            if (! empty($f['computed']) || ! empty($f['readonly'])) {
                continue;
            }
            $k = $f['key'];
            $base = ! empty($f['required']) ? ($creating && ! array_key_exists('default', $f) ? ['required'] : ['sometimes', 'required']) : ['nullable'];
            $rules[$k] = array_merge($base, match ($f['type']) {
                'text', 'phone' => ['string', 'max:'.($f['max'] ?? 255)],
                'textarea' => ['string', 'max:'.($f['max'] ?? 60000)],
                'email' => ['email', 'max:190'],
                'url' => ['string', 'max:'.($f['max'] ?? 500), 'not_regex:/^\s*(javascript|data|vbscript|file):/i'],
                'number', 'money' => ['numeric'],
                'date', 'datetime' => ['date'],
                'bool' => ['boolean'],
                'select' => [Rule::in(array_map('strval', array_keys($f['options'])))],
                'tags' => ['array'],
                'user' => ['integer', Rule::exists('users', 'id')->where('company_id', app('tenant')->id)],
                'ref' => ['max:40'],
                'partner' => ['max:40', \App\Support\TenantRef::of('partners')],
                'employee' => ['max:40', \App\Support\TenantRef::of('rh_employees')],
                default => [],
            });
            if ($f['type'] === 'tags' && isset($f['options'])) {
                $rules[$k.'.*'] = [Rule::in(array_map('strval', array_keys($f['options'])))];
            }
        }
        $data = $r->validate($rules);
        foreach ($res['fields'] as $f) {
            if (($f['type'] ?? '') === 'ref' && ! empty($data[$f['key']])) {
                $t = Records::resource($r->route('_records'), $f['resource']);
                abort_unless(DB::table($t['table'])->where('company_id', app('tenant')->id)->where('id', $data[$f['key']])->exists(), 422, $f['label'].' introuvable.');
            }
        }

        return $data;
    }
}
