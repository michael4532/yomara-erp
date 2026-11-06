<?php

namespace App\Modules\Rapports;

use App\Models\Company;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Exécute un rapport : regroupement sur 0 à 2 dimensions, 1 à 6 mesures, filtres, période relative,
 * tri, limite (« top 10 » avec ligne « Autres »), comparaison avec la période précédente.
 */
class ReportEngine
{
    public const CHARTS = [
        'table' => 'Tableau', 'bar' => 'Histogramme', 'hbar' => 'Barres horizontales', 'line' => 'Courbe',
        'pie' => 'Camembert', 'kpi' => 'Indicateur', 'pivot' => 'Tableau croisé',
    ];

    public const OPS = ['eq', 'neq', 'in', 'nin', 'contains', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte'];

    public const MAX_ROWS = 2000;

    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    private const WEEKDAYS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

    /** Contrôle et complète la définition d'un rapport (refus explicite des champs inconnus). */
    public function normalize(string $source, array $c): array
    {
        $src = Sources::get($source) ?? throw new \DomainException('Source de données inconnue : '.$source.'.');
        $dims = Sources::dimensions($src);
        $out = [];

        $out['dimensions'] = array_values(array_unique(array_map('strval', (array) ($c['dimensions'] ?? []))));
        if (count($out['dimensions']) > 2) {
            throw new \DomainException('Deux regroupements au maximum.');
        }
        foreach ($out['dimensions'] as $d) {
            if (! isset($dims[$d])) {
                throw new \DomainException('Regroupement inconnu pour « '.$src['label'].' » : '.$d.'.');
            }
        }

        $out['measures'] = array_values(array_unique(array_map('strval', (array) ($c['measures'] ?? []))));
        if (! $out['measures']) {
            $out['measures'] = [array_key_first($src['measures'])];
        }
        if (count($out['measures']) > 6) {
            throw new \DomainException('Six mesures au maximum.');
        }
        foreach ($out['measures'] as $m) {
            if (! isset($src['measures'][$m])) {
                throw new \DomainException('Mesure inconnue pour « '.$src['label'].' » : '.$m.'.');
            }
        }

        $out['filters'] = [];
        foreach (array_slice((array) ($c['filters'] ?? []), 0, 10) as $f) {
            $field = (string) ($f['field'] ?? '');
            $op = (string) ($f['op'] ?? 'eq');
            if (! isset($dims[$field])) {
                throw new \DomainException('Filtre sur un champ inconnu : '.$field.'.');
            }
            if (! in_array($op, self::OPS, true)) {
                throw new \DomainException('Opérateur de filtre inconnu : '.$op.'.');
            }
            $value = $f['value'] ?? null;
            if (in_array($op, ['in', 'nin'], true)) {
                $value = array_values(array_map(fn ($v) => (string) $v, array_slice((array) $value, 0, 200)));
                if (! $value) {
                    continue;
                }
            } elseif (! in_array($op, ['empty', 'not_empty'], true)) {
                if ($value === null || $value === '' || is_array($value)) {
                    continue;
                }
                $value = (string) $value;
            } else {
                $value = null;
            }
            $out['filters'][] = ['field' => $field, 'op' => $op, 'value' => $value];
        }

        $out['having'] = [];
        foreach (array_slice((array) ($c['having'] ?? []), 0, 5) as $h) {
            $m = (string) ($h['measure'] ?? '');
            $op = (string) ($h['op'] ?? 'gt');
            if (! isset($src['measures'][$m]) || ! in_array($op, ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'], true) || ! is_numeric($h['value'] ?? null)) {
                throw new \DomainException('Condition sur les totaux invalide.');
            }
            $out['having'][] = ['measure' => $m, 'op' => $op, 'value' => (float) $h['value']];
        }

        $out['period'] = (string) ($c['period'] ?? 'this_year');
        if (! isset(Period::PRESETS[$out['period']])) {
            throw new \DomainException('Période inconnue.');
        }
        $out['from'] = $out['period'] === 'custom' ? $this->date($c['from'] ?? null) : null;
        $out['to'] = $out['period'] === 'custom' ? $this->date($c['to'] ?? null) : null;
        if ($out['period'] === 'custom' && ! $out['from'] && ! $out['to']) {
            throw new \DomainException('Indiquez au moins une date de début ou de fin.');
        }
        if ($out['from'] && $out['to'] && $out['from'] > $out['to']) {
            throw new \DomainException('La date de début doit précéder la date de fin.');
        }

        $out['compare'] = isset(Period::COMPARE[$c['compare'] ?? '']) ? $c['compare'] : null;

        $allowedSort = array_merge($out['dimensions'], $out['measures']);
        $by = (string) ($c['sort']['by'] ?? '');
        $out['sort'] = in_array($by, $allowedSort, true) ? ['by' => $by, 'dir' => ($c['sort']['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'] : null;
        $limit = (int) ($c['limit'] ?? 0);
        $out['limit'] = $limit > 0 ? min($limit, 1000) : null;
        $out['others'] = (bool) ($c['others'] ?? true);
        $out['cumulative'] = (bool) ($c['cumulative'] ?? false);

        $out['chart'] = isset(self::CHARTS[$c['chart'] ?? '']) ? $c['chart'] : ($out['dimensions'] ? 'table' : 'kpi');
        if ($out['chart'] === 'pivot' && count($out['dimensions']) !== 2) {
            throw new \DomainException('Le tableau croisé demande deux regroupements (lignes et colonnes).');
        }
        if (in_array($out['chart'], ['bar', 'hbar', 'line', 'pie'], true) && ! $out['dimensions']) {
            throw new \DomainException('Un graphique demande au moins un regroupement.');
        }

        return $out;
    }

    private function date($v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Carbon::parse((string) $v)->toDateString();
        } catch (\Throwable) {
            throw new \DomainException('Date invalide : '.$v.'.');
        }
    }

    public function assertAccess(Company $company, $user, string $source): array
    {
        $src = Sources::get($source) ?? throw new \DomainException('Source de données inconnue.');
        if (! $company->hasModule($src['module'])) {
            throw new AccessException('Le module nécessaire à « '.$src['label'].' » n\'est pas actif.');
        }
        if (! $user || ! $user->hasPermission($src['permission'])) {
            throw new AccessException('Vous n\'avez pas accès aux données « '.$src['label'].' ».');
        }

        return $src;
    }

    /**
     * @param  array  $override  période imposée par un tableau de bord ou un envoi (period, from, to)
     */
    public function run(Company $company, $user, string $source, array $config, array $override = [], ?Carbon $today = null): array
    {
        $src = $this->assertAccess($company, $user, $source);
        if (! empty($override['period']) && $override['period'] !== 'report') {
            $config = array_merge($config, ['period' => $override['period'], 'from' => $override['from'] ?? null, 'to' => $override['to'] ?? null]);
        }
        $c = $this->normalize($source, $config);
        $dims = Sources::dimensions($src);
        [$from, $to] = Period::range($c['period'], $c['from'], $c['to'], $today);

        // Lignes regroupées
        $q = $this->base($company, $src, $c, $from, $to);
        $usedJoins = [];
        foreach ($c['dimensions'] as $i => $d) {
            $def = $dims[$d];
            $q->selectRaw($def['sql'].' as k'.$i);
            if (isset($def['display'])) {
                $q->selectRaw('MAX('.$def['display'].') as l'.$i);
            }
            $q->groupByRaw('k'.$i); // alias : MariaDB ne rapproche pas toujours deux expressions identiques
            $usedJoins = array_merge($usedJoins, $def['join'] ?? []);
        }
        foreach ($c['measures'] as $m) {
            $q->selectRaw($src['measures'][$m]['sql'].' as m_'.$m);
            $usedJoins = array_merge($usedJoins, $src['measures'][$m]['join'] ?? []);
        }
        foreach ($c['having'] as $h) {
            $q->havingRaw($src['measures'][$h['measure']]['sql'].' '.$this->sqlOp($h['op']).' (? + 0)', [$h['value']]);
            $usedJoins = array_merge($usedJoins, $src['measures'][$h['measure']]['join'] ?? []);
        }
        $this->joins($q, $src, $c, $usedJoins, $company->id);

        $dateFirst = $c['dimensions'] && isset($dims[$c['dimensions'][0]]['date']);
        $sort = $c['sort'] ?? ($dateFirst ? ['by' => $c['dimensions'][0], 'dir' => 'asc'] : ($c['dimensions'] ? ['by' => $c['measures'][0], 'dir' => 'desc'] : null));
        if ($sort) {
            $idx = array_search($sort['by'], $c['dimensions'], true);
            $q->orderByRaw(($idx !== false ? 'k'.$idx : 'm_'.$sort['by']).' '.$sort['dir']);
            if ($idx === false && $c['dimensions']) {
                $q->orderByRaw('k0 asc');
            }
        }
        $cap = $c['limit'] ?? self::MAX_ROWS;
        $raw = $c['dimensions'] ? $q->limit($cap + 1)->get() : collect();
        $truncated = ! $c['limit'] && $raw->count() > self::MAX_ROWS;
        $raw = $raw->take($cap);

        $rows = [];
        foreach ($raw as $r) {
            $r = (array) $r;
            $row = ['keys' => [], 'labels' => [], 'values' => []];
            foreach ($c['dimensions'] as $i => $d) {
                $key = $r['k'.$i];
                $row['keys'][$d] = $key === null ? null : (string) $key;
                $row['labels'][$d] = $this->label($dims[$d], $key, $r['l'.$i] ?? null);
            }
            foreach ($c['measures'] as $m) {
                $row['values'][$m] = $this->num($r['m_'.$m]);
            }
            $rows[] = $row;
        }

        // Totaux sur l'ensemble (hors limite) ; avec une condition sur les totaux, somme des lignes affichées.
        $totals = $c['having'] ? $this->sumRows($src, $c, $rows) : $this->totals($company, $src, $c, $from, $to);

        $others = null;
        if ($c['limit'] && $c['others'] && count($rows) === $c['limit'] && ! $c['having'] && count($c['dimensions']) === 1) {
            $shown = $this->sumRows($src, $c, $rows);
            $o = [];
            foreach ($c['measures'] as $m) {
                $o[$m] = ($src['measures'][$m]['additive'] ?? true) && $totals[$m] !== null ? round($totals[$m] - $shown[$m], 2) : null;
            }
            if (array_filter($o, fn ($v) => $v !== null && abs($v) > 0.004)) {
                $others = $o;
            }
        }

        if (count($c['dimensions']) === 1 && $dateFirst && ($sort['by'] ?? null) === $c['dimensions'][0] && $from && $to && ! $c['limit'] && ! $c['having']
            && ! array_filter($c['filters'], fn ($f) => $f['field'] === $c['dimensions'][0])) {
            // En cumul, la courbe s'arrête à aujourd'hui (pas de plateau sur les mois à venir).
            $until = $c['cumulative'] ? $to->copy()->min(($today ?? now())->copy()->startOfDay()) : $to;
            $rows = $this->fillGaps($rows, $c, $src, $dims[$c['dimensions'][0]]['date'], $from, $until, $sort['dir']);
        }
        if ($c['cumulative'] && $c['dimensions']) {
            $run = [];
            foreach ($rows as &$row) {
                foreach ($c['measures'] as $m) {
                    if (($src['measures'][$m]['additive'] ?? true) && $row['values'][$m] !== null) {
                        $run[$m] = round(($run[$m] ?? 0) + $row['values'][$m], 2);
                        $row['values'][$m] = $run[$m];
                    }
                }
            }
            unset($row);
        }

        $previous = null;
        if ($c['compare'] && $from && $to) {
            [$pf, $pt] = Period::previous($from, $to, $c['compare']);
            $previous = ['from' => $pf->toDateString(), 'to' => $pt->toDateString(), 'label' => Period::COMPARE[$c['compare']].' (du '.$pf->format('d/m/Y').' au '.$pt->format('d/m/Y').')',
                'totals' => $this->totals($company, $src, array_merge($c, ['period' => 'custom']), $pf, $pt)];
        }

        $columns = [];
        foreach ($c['dimensions'] as $d) {
            $columns[] = ['key' => $d, 'label' => $dims[$d]['label'], 'kind' => 'dimension', 'date' => $dims[$d]['date'] ?? null];
        }
        foreach ($c['measures'] as $m) {
            $columns[] = ['key' => $m, 'label' => $src['measures'][$m]['label'], 'kind' => 'measure', 'format' => $src['measures'][$m]['format'], 'additive' => $src['measures'][$m]['additive'] ?? true];
        }

        return [
            'source' => $source, 'source_label' => $src['label'], 'config' => $c,
            'period' => ['preset' => $c['period'], 'from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'label' => Period::label($c['period'], $from, $to)],
            'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'others' => $others, 'previous' => $previous,
            'filters_label' => $this->filtersLabel($src, $c), 'truncated' => $truncated, 'currency' => $company->currency,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function base(Company $company, array $src, array $c, ?Carbon $from, ?Carbon $to): Builder
    {
        $q = DB::table($src['from']);
        foreach ($src['inner'] ?? [] as $j) {
            $this->tenantJoin($q, $j, $company->id, 'inner');
        }
        $q->where($src['company'], $company->id);
        ($src['where'])($q);
        // Utilisateur limité à certaines agences : seulement leurs documents
        if (! empty($src['branch']) && ($ids = \App\Support\BranchScope::filter()) !== null) {
            $q->whereIn($src['branch'], $ids ?: ['__none__']);
        }
        $day = Sql::day($src['date']);
        if ($from) {
            $q->whereRaw("$day >= ?", [$from->toDateString()]);
        }
        if ($to) {
            $q->whereRaw("$day <= ?", [$to->toDateString()]);
        }
        $dims = Sources::dimensions($src);
        foreach ($c['filters'] as $f) {
            $def = $dims[$f['field']];
            $sql = $def['sql'];
            match ($f['op']) {
                'eq' => $q->whereRaw("$sql = ?", [$f['value']]),
                'neq' => $q->whereRaw("($sql IS NULL OR $sql <> ?)", [$f['value']]),
                'in' => $q->whereIn(DB::raw($sql), $f['value']),
                'nin' => $q->where(fn ($w) => $w->whereNull(DB::raw($sql))->orWhereNotIn(DB::raw($sql), $f['value'])),
                'contains' => $q->whereRaw('LOWER('.($def['display'] ?? $sql).') LIKE ?', ['%'.mb_strtolower($f['value']).'%']),
                'empty' => $q->whereRaw("($sql IS NULL OR $sql = '')"),
                'not_empty' => $q->whereRaw("($sql IS NOT NULL AND $sql <> '')"),
                default => is_numeric($f['value']) ? $q->whereRaw("$sql ".$this->sqlOp($f['op']).' (? + 0)', [$f['value']]) : $q->whereRaw("$sql ".$this->sqlOp($f['op']).' ?', [$f['value']]),
            };
        }

        return $q;
    }

    private function joins(Builder $q, array $src, array $c, array $used, int $companyId): void
    {
        $dims = Sources::dimensions($src);
        foreach ($c['filters'] as $f) {
            $used = array_merge($used, $dims[$f['field']]['join'] ?? []);
        }
        foreach (array_unique($used) as $alias) {
            $this->join($q, $src, $alias, $companyId);
        }
    }

    private function join(Builder $q, array $src, string $alias, int $companyId): void
    {
        $j = $src['joins'][$alias] ?? null;
        if (is_callable($j)) {
            $j($q);
        } elseif ($j) {
            $this->tenantJoin($q, $j, $companyId, 'left');
        }
    }

    /** Tables jointes : présence d'une colonne company_id (mise en cache). */
    private static array $companyColumn = [];

    /**
     * Jointure cloisonnée : si la table jointe porte company_id, la condition exige la même société
     * (un identifiant étranger pointant vers une autre société ne remonte jamais ses données).
     */
    private function tenantJoin(Builder $q, array $j, int $companyId, string $type): void
    {
        [$table, $first, $op, $second] = $j;
        $parts = preg_split('/\s+as\s+/i', trim($table));
        $name = $parts[0];
        $alias = $parts[1] ?? $name;
        $scoped = self::$companyColumn[$name] ??= \Illuminate\Support\Facades\Schema::hasColumn($name, 'company_id');
        $q->join($table, function ($join) use ($first, $op, $second, $scoped, $alias, $companyId) {
            $join->on($first, $op, $second);
            if ($scoped) {
                $join->where($alias.'.company_id', '=', $companyId);
            }
        }, null, null, $type);
    }

    private function totals(Company $company, array $src, array $c, ?Carbon $from, ?Carbon $to): array
    {
        $q = $this->base($company, $src, $c, $from, $to);
        $used = [];
        foreach ($c['measures'] as $m) {
            $q->selectRaw($src['measures'][$m]['sql'].' as m_'.$m);
            $used = array_merge($used, $src['measures'][$m]['join'] ?? []);
        }
        $this->joins($q, $src, $c, $used, $company->id);
        $r = (array) $q->first();
        $out = [];
        foreach ($c['measures'] as $m) {
            $out[$m] = $this->num($r['m_'.$m] ?? null) ?? (($src['measures'][$m]['additive'] ?? true) ? 0.0 : null);
        }

        return $out;
    }

    private function sumRows(array $src, array $c, array $rows): array
    {
        $out = [];
        foreach ($c['measures'] as $m) {
            $out[$m] = ($src['measures'][$m]['additive'] ?? true) ? round(array_sum(array_map(fn ($r) => $r['values'][$m] ?? 0, $rows)), 2) : null;
        }

        return $out;
    }

    private function fillGaps(array $rows, array $c, array $src, string $unit, Carbon $from, Carbon $to, string $dir): array
    {
        if (! in_array($unit, ['day', 'month', 'quarter', 'year'], true)) {
            return $rows;
        }
        $keys = [];
        $d = $from->copy();
        $d = match ($unit) {
            'month' => $d->startOfMonth(), 'quarter' => $d->startOfQuarter(), 'year' => $d->startOfYear(), default => $d,
        };
        while ($d->lte($to) && count($keys) <= 400) {
            $keys[] = match ($unit) {
                'day' => $d->toDateString(), 'month' => $d->format('Y-m'), 'quarter' => $d->year.'-T'.$d->quarter, 'year' => (string) $d->year,
            };
            match ($unit) {
                'day' => $d->addDay(), 'month' => $d->addMonthNoOverflow(), 'quarter' => $d->addQuarterNoOverflow(), 'year' => $d->addYearNoOverflow(),
            };
        }
        if (count($keys) > 400) {
            return $rows;
        }
        $dim = $c['dimensions'][0];
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['keys'][$dim]] = $r;
        }
        $dims = Sources::dimensions($src);
        $out = [];
        foreach ($keys as $k) {
            if (isset($byKey[$k])) {
                $out[] = $byKey[$k];
                unset($byKey[$k]);
            } else {
                $values = [];
                foreach ($c['measures'] as $m) {
                    $values[$m] = ($src['measures'][$m]['additive'] ?? true) ? 0.0 : null;
                }
                $out[] = ['keys' => [$dim => $k], 'labels' => [$dim => $this->label($dims[$dim], $k, null)], 'values' => $values];
            }
        }
        // Clés hors grille (dates hors période impossibles en principe) : conservées à la fin.
        $out = array_merge($out, array_values($byKey));

        return $dir === 'desc' ? array_reverse($out) : $out;
    }

    private function num($v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }

    private function sqlOp(string $op): string
    {
        return ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op];
    }

    public function label(array $def, $key, $display): string
    {
        if (isset($def['date'])) {
            return self::dateLabel($def['date'], $key);
        }
        if ($display !== null && $display !== '') {
            return trim((string) $display);
        }
        if ($key === null || $key === '') {
            return $def['empty'] ?? 'Non renseigné';
        }

        return $def['labels'][(string) $key] ?? (string) $key;
    }

    public static function dateLabel(string $unit, $key): string
    {
        $k = (string) $key;
        if ($key === null || $k === '') {
            return 'Sans date';
        }

        return match ($unit) {
            'day' => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $k, $x) ? "$x[3]/$x[2]/$x[1]" : $k,
            'month' => preg_match('/^(\d{4})-(\d{2})/', $k, $x) ? self::MONTHS[(int) $x[2] - 1].' '.$x[1] : $k,
            'quarter' => preg_match('/^(\d{4})-T(\d)/', $k, $x) ? "T$x[2] $x[1]" : $k,
            'week' => preg_match('/^(\d{4})-S(\d+)/', $k, $x) ? 'S'.(int) $x[2].' '.$x[1] : $k,
            'weekday' => self::WEEKDAYS[(int) $k] ?? $k,
            default => $k,
        };
    }

    public function filtersLabel(array $src, array $c): ?string
    {
        if (! $c['filters'] && ! $c['having']) {
            return null;
        }
        $dims = Sources::dimensions($src);
        $ops = ['eq' => '=', 'neq' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'in' => 'parmi', 'nin' => 'hors', 'contains' => 'contient'];
        $parts = [];
        foreach ($c['filters'] as $f) {
            $def = $dims[$f['field']];
            $name = $def['label'];
            if ($f['op'] === 'empty' || $f['op'] === 'not_empty') {
                $parts[] = $name.($f['op'] === 'empty' ? ' non renseigné' : ' renseigné');
                continue;
            }
            $vals = array_map(fn ($v) => isset($def['labels']) ? ($def['labels'][$v] ?? $v) : (isset($def['date']) ? self::dateLabel($def['date'], $v) : $v), (array) $f['value']);
            if (isset($def['display']) && in_array($f['op'], ['eq', 'neq', 'in', 'nin'], true)) {
                $vals = $this->displayNames($src, $def, $vals);
            }
            $parts[] = $name.' '.$ops[$f['op']].' '.implode(', ', $vals);
        }
        foreach ($c['having'] as $h) {
            $parts[] = $src['measures'][$h['measure']]['label'].' '.$ops[$h['op']].' '.rtrim(rtrim(number_format($h['value'], 2, ',', ' '), '0'), ',');
        }

        return implode(' · ', $parts);
    }

    /** Libellés des identifiants filtrés (client, article…), pour l'affichage. */
    private function displayNames(array $src, array $def, array $ids): array
    {
        try {
            $cid = app()->bound('tenant') ? (int) app('tenant')->id : 0;
            $q = DB::table($src['from']);
            foreach ($src['inner'] ?? [] as $j) {
                $this->tenantJoin($q, $j, $cid, 'inner');
            }
            foreach ($def['join'] ?? [] as $alias) {
                $this->join($q, $src, $alias, $cid);
            }
            $q->where($src['company'], $cid);
            $map = $q->whereIn(DB::raw($def['sql']), $ids)->selectRaw($def['sql'].' as k, MAX('.$def['display'].') as l')->groupByRaw('k')->pluck('l', 'k')->all();

            return array_map(fn ($id) => $map[$id] ?? $id, $ids);
        } catch (\Throwable) {
            return $ids;
        }
    }

    /** Valeurs possibles d'un regroupement (choix des filtres). */
    public function values(Company $company, $user, string $source, string $field, ?string $search = null): array
    {
        $src = $this->assertAccess($company, $user, $source);
        $dims = Sources::dimensions($src);
        $def = $dims[$field] ?? throw new \DomainException('Champ inconnu.');
        if (isset($def['labels']) && ! isset($def['display'])) {
            $out = [];
            foreach ($def['labels'] as $k => $l) {
                $out[] = ['key' => (string) $k, 'label' => $l];
            }

            return $out;
        }
        $q = DB::table($src['from']);
        foreach ($src['inner'] ?? [] as $j) {
            $this->tenantJoin($q, $j, $company->id, 'inner');
        }
        foreach ($def['join'] ?? [] as $alias) {
            $this->join($q, $src, $alias, $company->id);
        }
        $q->where($src['company'], $company->id);
        ($src['where'])($q);
        $q->selectRaw($def['sql'].' as k'.(isset($def['display']) ? ', MAX('.$def['display'].') as l' : ''))->groupByRaw('k');
        if ($search !== null && $search !== '') {
            $q->havingRaw('LOWER('.(isset($def['display']) ? 'MAX('.$def['display'].')' : $def['sql']).') LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        $rows = $q->orderByRaw(isset($def['display']) ? 'l' : 'k')->limit(isset($def['date']) ? 400 : 100)->get();

        return $rows->map(fn ($r) => ['key' => $r->k === null ? '' : (string) $r->k, 'label' => $this->label($def, $r->k, $r->l ?? null)])->values()->all();
    }
}
