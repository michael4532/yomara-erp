<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Historique d'activité de tout l'ERP : chaque fiche créée, modifiée ou supprimée (factures, contacts, produits,
 * paiements, salariés, tickets… tout ce qui se synchronise), avec l'auteur, l'heure et les champs touchés.
 * Les actions métier (validation, réouverture, suppression d'une facture payée, connexion…) ajoutent leurs propres lignes.
 */
class ActivityJournal
{
    /** Champs techniques jamais listés comme « modifiés ». */
    protected const IGNORED = ['updated_at', 'created_at', 'version', 'synced_at', 'last_seen_at', 'last_login_at', 'remember_token',
        'password', 'two_factor_secret', 'api_token', 'search_text', 'amount_paid', 'payment_status', 'stock_posted'];

    /** Champs dont la valeur avant / après est conservée (petits et parlants). */
    protected const TRACKED = ['status', 'stage', 'payment_status', 'total', 'amount', 'date', 'due_date', 'name', 'title', 'partner_name',
        'quantity', 'price', 'sale_price', 'role_id', 'is_active', 'type', 'number', 'method'];

    /** Fiches déjà inscrites pendant cette requête (une seule ligne par fiche, même si elle est enregistrée plusieurs fois). */
    protected static array $seen = [];

    public static bool $enabled = true;

    protected static int $scope = -1;

    public static function reset(): void
    {
        static::$seen = [];
    }

    public static function model(Model $m, string $op): void
    {
        if (! static::$enabled || ! static::tableReady()) {
            return;
        }
        rescue(function () use ($m, $op) {
            $company = $m->getAttribute('company_id') ?? (app()->bound('tenant') ? app('tenant')->id : null);
            if (! $company) {
                return;
            }
            $scope = app()->bound('request') ? spl_object_id(app('request')) : 0;
            if ($scope !== static::$scope) {
                static::$scope = $scope;
                static::$seen = [];
            }
            $key = $m::class.'#'.$m->getKey();
            $action = $op === 'deleted' ? 'deleted' : ($m->wasRecentlyCreated ? 'created' : 'updated');
            if ($action !== 'deleted' && isset(static::$seen[$key])) {
                // déjà inscrite dans cette requête : on complète la liste des champs touchés
                static::merge(static::$seen[$key], $m);

                return;
            }
            $props = ['label' => static::label($m)];
            if ($action === 'updated') {
                $changes = array_diff(array_keys($m->getChanges()), static::IGNORED);
                if (! $changes) {
                    return; // seul un champ technique a bougé
                }
                $props['fields'] = array_values($changes);
                foreach (array_intersect($changes, static::TRACKED) as $f) {
                    $props['changes'][$f] = [static::scalar($m->getOriginal($f)), static::scalar($m->getAttribute($f))];
                }
            }
            $entity = property_exists($m, 'syncEntity') ? $m::$syncEntity : strtolower(class_basename($m));
            $id = DB::table('activity_logs')->insertGetId([
                'company_id' => $company,
                'user_id' => auth()->id(),
                'action' => $entity.'.'.$action,
                'subject_type' => class_basename($m),
                'subject_id' => (string) $m->getKey(),
                'properties' => json_encode(array_filter($props, fn ($v) => $v !== null && $v !== []), JSON_UNESCAPED_UNICODE),
                'ip' => app()->runningInConsole() ? null : request()?->ip(),
                'created_at' => now(),
            ]);
            if ($action !== 'deleted') {
                static::$seen[$key] = $id;
            }
            if (count(static::$seen) > 5000) {
                static::$seen = [];
            }
        }, null, false);
    }

    protected static function merge(int $id, Model $m): void
    {
        $changes = array_diff(array_keys($m->getChanges()), static::IGNORED);
        if (! $changes) {
            return;
        }
        $row = DB::table('activity_logs')->where('id', $id)->first(['action', 'properties']);
        if (! $row || str_ends_with($row->action, '.created')) {
            return; // une création reste une création
        }
        $p = json_decode((string) $row->properties, true) ?: [];
        $p['fields'] = array_values(array_unique(array_merge($p['fields'] ?? [], $changes)));
        foreach (array_intersect($changes, static::TRACKED) as $f) {
            $p['changes'][$f] = [($p['changes'][$f][0] ?? static::scalar($m->getOriginal($f))), static::scalar($m->getAttribute($f))];
        }
        DB::table('activity_logs')->where('id', $id)->update(['properties' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
    }

    protected static function scalar(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_array($v) || is_object($v)) {
            return null;
        }

        return is_string($v) ? mb_substr($v, 0, 120) : $v;
    }

    /** Nom lisible de la fiche (numéro, nom, titre…). */
    public static function label(Model $m): ?string
    {
        foreach (['number', 'name', 'title', 'full_name', 'subject', 'reference', 'partner_name', 'description', 'email'] as $f) {
            $v = $m->getAttribute($f);
            if (is_string($v) && trim($v) !== '') {
                return mb_substr(trim($v), 0, 120);
            }
        }
        $first = trim(($m->getAttribute('first_name') ?? '').' '.($m->getAttribute('last_name') ?? ''));

        return $first !== '' ? $first : null;
    }

    protected static ?bool $ready = null;

    protected static function tableReady(): bool
    {
        return static::$ready ??= rescue(fn () => \Illuminate\Support\Facades\Schema::hasTable('activity_logs'), false, false);
    }
}
