<?php

namespace App\Modules\Analytique;

use App\Modules\Analytique\Models\Section;

/**
 * Ventilation analytique : {"<section>": pourcentage, …}.
 * Sur chaque axe, le total ne dépasse pas 100 % (le reste est « non ventilé »).
 */
class Distribution
{
    /** Normalise et contrôle une ventilation ; null/[] = pas de ventilation. */
    public static function clean(mixed $raw): ?array
    {
        if ($raw === null || $raw === [] || $raw === '') {
            return null;
        }
        if (! is_array($raw)) {
            throw new \DomainException('Ventilation analytique invalide.');
        }
        $dist = [];
        foreach ($raw as $k => $v) {
            if (is_array($v)) { // [{section_id, percent}]
                $k = $v['section_id'] ?? null;
                $v = $v['percent'] ?? null;
            }
            if (! is_string($k) || ! is_numeric($v)) {
                throw new \DomainException('Ventilation analytique invalide.');
            }
            $p = round((float) $v, 3);
            if ($p < 0 || $p > 100) {
                throw new \DomainException('Un pourcentage de ventilation doit être compris entre 0 et 100.');
            }
            if ($p > 0) {
                $dist[$k] = round(($dist[$k] ?? 0) + $p, 3);
            }
        }
        if (! $dist) {
            return null;
        }
        $sections = Section::whereIn('id', array_keys($dist))->get(['id', 'axis_id', 'name', 'active'])->keyBy('id');
        $perAxis = [];
        foreach ($dist as $id => $p) {
            $s = $sections->get($id);
            if (! $s) {
                throw new \DomainException('Section analytique introuvable.');
            }
            $perAxis[$s->axis_id] = ($perAxis[$s->axis_id] ?? 0) + $p;
        }
        foreach ($perAxis as $sum) {
            if ($sum > 100.001) {
                throw new \DomainException('La ventilation dépasse 100 % sur un même axe.');
            }
        }

        return $dist;
    }

    /** Axes couverts à 100 % par une ventilation. */
    public static function fullAxes(?array $dist): array
    {
        if (! $dist) {
            return [];
        }
        $sum = [];
        foreach (Section::whereIn('id', array_keys($dist))->pluck('axis_id', 'id') as $id => $axis) {
            $sum[$axis] = ($sum[$axis] ?? 0) + $dist[$id];
        }

        return array_keys(array_filter($sum, fn ($s) => $s >= 99.999));
    }

    /** @var array<string, string> section => axe (cache de la requête) */
    private static array $axes = [];

    public static function axesOf(array $sectionIds): array
    {
        $missing = array_values(array_diff($sectionIds, array_keys(self::$axes)));
        if ($missing) {
            self::$axes += Section::withTrashed()->whereIn('id', $missing)->pluck('axis_id', 'id')->all();
        }

        return array_intersect_key(self::$axes, array_flip($sectionIds));
    }

    /**
     * Fusion par axe, de la moins à la plus prioritaire (règle, document, ligne) :
     * un axe présent dans une ventilation plus prioritaire remplace entièrement cet axe.
     */
    public static function merge(?array ...$dists): ?array
    {
        $dists = array_values(array_filter($dists));
        if (! $dists) {
            return null;
        }
        if (count($dists) === 1) {
            return $dists[0];
        }
        $axes = self::axesOf(array_keys(array_merge(...$dists)));
        $result = [];
        foreach ($dists as $d) {
            $here = array_unique(array_map(fn ($sid) => $axes[$sid] ?? null, array_keys($d)));
            $result = array_filter($result, fn ($p, $sid) => ! in_array($axes[$sid] ?? null, $here, true), ARRAY_FILTER_USE_BOTH);
            foreach ($d as $sid => $p) {
                $result[$sid] = $p;
            }
        }

        return $result ?: null;
    }
}
