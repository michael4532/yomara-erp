<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Segment dynamique : contacts répondant à des conditions (recalculé à chaque utilisation). */
class Segment extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_segments';

    protected $fillable = ['name', 'match', 'conditions'];

    protected function casts(): array
    {
        return ['conditions' => 'array'];
    }

    public const FIELDS = [
        'list' => 'Appartient à la liste',
        'not_list' => 'N\'appartient pas à la liste',
        'tag' => 'A l\'étiquette',
        'not_tag' => 'N\'a pas l\'étiquette',
        'source' => 'Origine',
        'city' => 'Ville',
        'opened' => 'A ouvert un e-mail depuis (jours)',
        'clicked' => 'A cliqué depuis (jours)',
        'inactive' => 'Aucune ouverture depuis (jours)',
        'created' => 'Ajouté depuis (jours)',
        'score_min' => 'Score d\'engagement ≥',
        'birthday_month' => 'Anniversaire au mois de',
        'has_email' => 'A un e-mail',
        'has_phone' => 'A un téléphone',
        'campaign_opened' => 'A ouvert la campagne',
        'campaign_not_opened' => 'N\'a pas ouvert la campagne',
        'spent_min' => 'Total des achats ≥ (montant)',
        'orders_min' => 'Nombre d\'achats ≥',
        'bought_within' => 'A acheté depuis (jours)',
        'no_purchase' => 'Aucun achat depuis (jours)',
        'rfm' => 'Profil client (RFM)',
    ];

    /** Profils RFM : récence, fréquence et montant des achats, comparés aux autres clients. */
    public const RFM = ['champions' => 'Meilleurs clients', 'loyal' => 'Clients fidèles', 'promising' => 'Nouveaux clients prometteurs', 'at_risk' => 'À risque (ne reviennent plus)', 'lost' => 'Perdus', 'others' => 'Autres'];

    /** Achats par client : nombre, montant, date de la dernière facture validée. */
    public static function purchases(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('trade_documents')->where('company_id', app('tenant')->id)->where('direction', 'sale')->whereIn('type', ['invoice'])
            ->where('status', 'validated')->whereNull('deleted_at')->whereNotNull('partner_id')
            ->groupBy('partner_id')->selectRaw('partner_id, count(*) as n, sum(total) as amount, max(date) as last')->get()->keyBy('partner_id');
    }

    /** Profil RFM de chaque client (notes de 1 à 5 par quintile). */
    public static function rfm(): array
    {
        $rows = self::purchases();
        if ($rows->isEmpty()) {
            return [];
        }
        $rank = function (string $key, bool $desc) use ($rows) {
            $vals = $rows->pluck($key)->map(fn ($v) => $key === 'last' ? strtotime((string) $v) : (float) $v)->sort()->values();
            $cnt = $vals->count();

            return function ($v) use ($vals, $cnt, $key) {
                $v = $key === 'last' ? strtotime((string) $v) : (float) $v;
                $pos = $vals->search(fn ($x) => $x >= $v);

                return min(5, 1 + (int) floor(($pos === false ? $cnt - 1 : $pos) / max(1, $cnt) * 5));
            };
        };
        [$r, $f, $m] = [$rank('last', false), $rank('n', false), $rank('amount', false)];
        $out = [];
        foreach ($rows as $pid => $x) {
            $R = $r($x->last);
            $F = $f($x->n);
            $M = $m($x->amount);
            $days = now()->diffInDays(\Illuminate\Support\Carbon::parse($x->last));
            $out[$pid] = match (true) {
                $R >= 4 && $F >= 4 && $M >= 3 => 'champions',
                $F >= 3 && $R >= 3 => 'loyal',
                $R >= 4 && $F <= 2 => 'promising',
                $F >= 2 && $R <= 2 && $days < 365 => 'at_risk',
                $R <= 2 => 'lost',
                default => 'others',
            };
        }

        return $out;
    }

    public static function apply(Builder $q, string $match, array $conditions): Builder
    {
        $conditions = array_values(array_filter($conditions, fn ($c) => isset(self::FIELDS[$c['field'] ?? ''])));
        if (! $conditions) {
            return $q;
        }

        return $q->where(function (Builder $w) use ($match, $conditions) {
            foreach ($conditions as $c) {
                $method = $match === 'any' ? 'orWhere' : 'where';
                $w->{$method}(fn (Builder $x) => self::condition($x, $c['field'], $c['value'] ?? null));
            }
        });
    }

    /** Clients (partner_id) dont les ventes validées remplissent la condition, en sous-requête SQL (pas de liste d'identifiants). */
    protected static function buyers(string $having, array $bind = [])
    {
        return fn ($sub) => $sub->from('trade_documents')->select('partner_id')->where('company_id', app('tenant')->id)->where('direction', 'sale')->where('type', 'invoice')
            ->where('status', 'validated')->whereNull('deleted_at')->whereNotNull('partner_id')->groupBy('partner_id')->havingRaw($having, $bind);
    }

    protected static function condition(Builder $q, string $field, $v): void
    {
        $v = is_scalar($v) || $v === null ? $v : null; // valeur simple uniquement
        $days = max(0, (int) $v);
        $since = now()->subDays($days ?: 30);
        $msg = fn ($col) => fn ($m) => $m->from('mk_messages')->whereColumn('mk_messages.contact_id', 'mk_contacts.id')->where($col, '>=', $since);
        match ($field) {
            'list' => $q->whereHas('lists', fn ($l) => $l->where('mk_lists.id', (int) $v)),
            'not_list' => $q->whereDoesntHave('lists', fn ($l) => $l->where('mk_lists.id', (int) $v)),
            'tag' => $q->whereRaw(self::tagSql().' LIKE ?', ['%,'.mb_strtolower(trim((string) $v)).',%']),
            'not_tag' => $q->whereRaw(self::tagSql().' NOT LIKE ?', ['%,'.mb_strtolower(trim((string) $v)).',%']),
            'source' => $q->where('source', (string) $v),
            'city' => $q->where('city', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $v).'%'),
            'opened' => $q->whereExists($msg('opened_at')),
            'clicked' => $q->whereExists($msg('clicked_at')),
            'inactive' => $q->whereNotExists($msg('opened_at')),
            'created' => $q->where('created_at', '>=', $since),
            'score_min' => $q->where('score', '>=', (int) $v),
            'birthday_month' => $q->whereNotNull('birthday')->whereMonth('birthday', (int) $v),
            'has_email' => $q->whereNotNull('email')->where('email', '!=', ''),
            'has_phone' => $q->whereNotNull('phone')->where('phone', '!=', ''),
            'campaign_opened' => $q->whereExists(fn ($m) => $m->from('mk_messages')->whereColumn('mk_messages.contact_id', 'mk_contacts.id')->where('campaign_id', (int) $v)->whereNotNull('opened_at')),
            'spent_min' => $q->whereIn('partner_id', self::buyers('SUM(total) >= (? + 0)', [(float) $v])),
            'orders_min' => $q->whereIn('partner_id', self::buyers('COUNT(*) >= (? + 0)', [max(1, (int) $v)])),
            'bought_within' => $q->whereIn('partner_id', self::buyers('MAX(date) >= ?', [now()->subDays($days ?: 30)->toDateString()])),
            'no_purchase' => $q->whereIn('partner_id', self::buyers('MAX(date) < ?', [now()->subDays($days ?: 90)->toDateString()])),
            'rfm' => $q->whereIn('partner_id', array_keys(array_filter(self::rfm(), fn ($p) => $p === (string) $v))),
            'campaign_not_opened' => $q->whereExists(fn ($m) => $m->from('mk_messages')->whereColumn('mk_messages.contact_id', 'mk_contacts.id')->where('campaign_id', (int) $v)->whereNull('opened_at')),
        };
    }

    /** « ,tag1,tag2, » en minuscules, quel que soit le moteur (MySQL ou SQLite). */
    public static function tagSql(): string
    {
        $inner = "REPLACE(LOWER(COALESCE(tags,'')), ', ', ',')";

        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite' ? "(',' || $inner || ',')" : "CONCAT(',', $inner, ',')";
    }

    public function contacts(): Builder
    {
        return self::apply(Contact::query(), $this->match ?? 'all', (array) $this->conditions);
    }
}
