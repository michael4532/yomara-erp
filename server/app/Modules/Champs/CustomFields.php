<?php

namespace App\Modules\Champs;

use App\Modules\Champs\Models\Field;

/** Contrôle et mise en forme des valeurs de champs personnalisés. */
class CustomFields
{
    public const ENTITIES = [
        'partners' => 'Clients & fournisseurs', 'products' => 'Articles', 'sales_documents' => 'Documents de vente', 'purchase_documents' => 'Documents d\'achat',
        'rh_employees' => 'Salariés', 'crm_leads' => 'Opportunités', 'prj_projects' => 'Projets',
    ];

    public const TYPES = [
        'text' => 'Texte', 'textarea' => 'Texte long', 'number' => 'Nombre', 'money' => 'Montant', 'date' => 'Date', 'select' => 'Liste de choix',
        'multiselect' => 'Choix multiples', 'checkbox' => 'Case à cocher', 'url' => 'Lien', 'email' => 'E-mail', 'phone' => 'Téléphone',
    ];

    public const READERS = 'core.contacts.view|core.contacts.manage|core.products.view|core.products.manage|ventes.view|ventes.manage|achats.view|achats.manage|achats.request|rh.view|rh.manage|crm.view|crm.manage|projets.view|projets.manage|pos.use|champs.manage';

    /** Cache lié au conteneur (une requête, une tâche) : jamais partagé d'une société à l'autre. */
    private static function store(): \ArrayObject
    {
        if (! app()->bound('champs.cache')) {
            app()->instance('champs.cache', new \ArrayObject);
        }

        return app('champs.cache');
    }

    public static function flush(): void
    {
        app()->instance('champs.cache', new \ArrayObject);
    }

    public static function enabled(): bool
    {
        return app()->bound('tenant') && app('tenant')->hasModule('champs');
    }

    /** Champs actifs d'un objet pour la société courante. */
    public static function forEntity(string $entity): array
    {
        if (! self::enabled()) {
            return [];
        }
        $k = app('tenant')->id.':'.$entity;

        $c = self::store();
        if (! isset($c[$k])) {
            $c[$k] = Field::where('entity', $entity)->where('active', true)->orderBy('sort')->orderBy('label')->get()->keyBy('key')->all();
        }

        return $c[$k];
    }

    public static function coerce(Field $f, mixed $v): mixed
    {
        if ($v === null || $v === '' || $v === []) {
            return null;
        }
        $err = fn () => throw new \DomainException('Valeur invalide pour « '.$f->label.' ».');

        return match ($f->type) {
            'number', 'money' => is_numeric(str_replace([' ', ','], ['', '.'], (string) $v)) ? round((float) str_replace([' ', ','], ['', '.'], (string) $v), $f->type === 'money' ? 2 : 4) : $err(),
            'checkbox' => in_array($v, [true, 1, '1', 'true', 'oui', 'on'], true),
            'date' => (function () use ($v, $err) {
                try {
                    return \Illuminate\Support\Carbon::parse((string) $v)->toDateString();
                } catch (\Throwable) {
                    return $err();
                }
            })(),
            'select' => in_array((string) $v, $f->options ?? [], true) ? (string) $v : $err(),
            'multiselect' => (function () use ($v, $f, $err) {
                $vals = is_array($v) ? $v : array_map('trim', explode(',', (string) $v));
                $vals = array_values(array_unique(array_filter(array_map('strval', $vals), fn ($x) => $x !== '')));
                foreach ($vals as $x) {
                    if (! in_array($x, $f->options ?? [], true)) {
                        $err();
                    }
                }

                return $vals ?: null;
            })(),
            'email' => filter_var(trim((string) $v), FILTER_VALIDATE_EMAIL) ? strtolower(trim((string) $v)) : $err(),
            'url' => preg_match('#^https?://#i', trim((string) $v)) ? mb_substr(trim((string) $v), 0, 500) : (preg_match('/^[\w.-]+\.[a-z]{2,}/i', trim((string) $v)) ? 'https://'.mb_substr(trim((string) $v), 0, 490) : $err()),
            'textarea' => mb_substr((string) $v, 0, 5000),
            default => mb_substr(trim((string) $v), 0, 500),
        };
    }

    /**
     * Valeurs nettoyées : clés connues uniquement, types contrôlés. Les valeurs de champs supprimés
     * ou désactivés sont conservées (réactivation possible).
     */
    public static function clean(string $entity, mixed $values, ?array $previous = null): ?array
    {
        $fields = self::forEntity($entity);
        $values = is_array($values) ? $values : [];
        // Valeurs des champs désactivés ou supprimés conservées ; clés inconnues écartées.
        $c = self::store();
        $ck = 'all:'.app('tenant')->id.':'.$entity;
        if (! isset($c[$ck])) {
            $c[$ck] = Field::withTrashed()->where('entity', $entity)->pluck('key')->flip()->all();
        }
        $known = $c[$ck];
        $out = array_filter($previous ?? [], fn ($k) => ! isset($fields[$k]) && isset($known[$k]), ARRAY_FILTER_USE_KEY);
        foreach ($fields as $key => $f) {
            $raw = array_key_exists($key, $values) ? $values[$key] : ($previous[$key] ?? null);
            $v = self::coerce($f, $raw);
            if ($v !== null && $v !== false) {
                $out[$key] = $v;
            } elseif ($v === false) {
                $out[$key] = false;
            }
        }

        return $out ?: null;
    }

    /** Champs obligatoires (saisie depuis l'application ou l'API) ; valeurs par défaut à la création. */
    public static function required(string $entity, array &$data, ?array $current, bool $creating): void
    {
        $fields = self::forEntity($entity);
        if (! $fields || (! $creating && ! array_key_exists('custom', $data))) {
            return;
        }
        $vals = is_array($data['custom'] ?? null) ? $data['custom'] : [];
        $merged = array_merge($current ?? [], $vals);
        foreach ($fields as $key => $f) {
            if ($creating && ! array_key_exists($key, $vals) && $f->default_value !== null && $f->default_value !== '') {
                $vals[$key] = $f->type === 'multiselect' ? array_map('trim', explode(',', $f->default_value)) : $f->default_value;
                $merged[$key] = $vals[$key];
            }
            $v = $merged[$key] ?? null;
            if ($f->required && ($v === null || $v === '' || $v === [] || ($f->type === 'checkbox' && ! in_array($v, [true, 1, '1', 'true'], true)))) {
                throw new \DomainException('Champ obligatoire : « '.$f->label.' ».');
            }
        }
        $data['custom'] = $vals;
    }

    /** Texte affichable d'une valeur. */
    public static function display(Field $f, mixed $v): string
    {
        return match (true) {
            $v === null || $v === '' => '',
            $f->type === 'checkbox' => $v ? 'Oui' : 'Non',
            $f->type === 'multiselect' => implode(', ', (array) $v),
            $f->type === 'date' => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y'),
            $f->type === 'money' || $f->type === 'number' => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ','),
            default => (string) $v,
        };
    }
}
