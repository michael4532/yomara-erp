<?php

namespace App\Modules\Core\Models;

use App\Models\Concerns\Syncable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Conditions de paiement : une ou plusieurs échéances.
 * Chaque ligne : pourcentage du total, jours après la date du document, « fin de mois » (l'échéance est reportée au dernier jour du mois).
 * Ex. « 30 % à la commande, solde à 30 jours fin de mois » = [{30, 0, false}, {70, 30, true}].
 */
class PaymentTerm extends Model
{
    use Syncable;

    protected $table = 'payment_terms';

    public static string $syncEntity = 'payment_terms';

    public static string $viewPermission = 'core.contacts.view|core.products.view|core.settings.manage|ventes.view|ventes.sell|achats.view|pos.sell';

    public static string $managePermission = 'core.settings.manage';

    protected $fillable = ['name', 'lines', 'is_default_sale', 'is_default_purchase', 'active', 'sort'];

    protected $attributes = ['active' => true, 'is_default_sale' => false, 'is_default_purchase' => false];

    protected $hidden = ['company_id', 'deleted_at'];

    protected $appends = ['description'];

    public function getDescriptionAttribute(): string
    {
        return self::describe($this->lines ?? []);
    }

    protected function casts(): array
    {
        return ['lines' => 'array', 'is_default_sale' => 'boolean', 'is_default_purchase' => 'boolean', 'active' => 'boolean', 'sort' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'lines' => 'required|array|min:1|max:6',
            'lines.*.percent' => 'required|numeric|gt:0|max:100',
            'lines.*.days' => 'required|integer|min:0|max:365',
            'lines.*.end_of_month' => 'nullable|boolean',
            'is_default_sale' => 'nullable|boolean',
            'is_default_purchase' => 'nullable|boolean',
            'active' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0|max:1000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (isset($data['lines'])) {
            $lines = array_values(array_map(fn ($l) => ['percent' => round((float) $l['percent'], 2), 'days' => (int) $l['days'], 'end_of_month' => (bool) ($l['end_of_month'] ?? false)], $data['lines']));
            if (abs(array_sum(array_column($lines, 'percent')) - 100) > 0.01) {
                throw new \DomainException('Les échéances doivent totaliser 100 %.');
            }
            usort($lines, fn ($a, $b) => $a['days'] <=> $b['days']);
            $data['lines'] = $lines;
        }
        if (array_key_exists('active', $data) && ! $data['active'] && ($this->is_default_sale || $this->is_default_purchase || ! empty($data['is_default_sale']) || ! empty($data['is_default_purchase']))) {
            throw new \DomainException('Choisissez d\'abord une autre condition par défaut.');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        foreach (['is_default_sale', 'is_default_purchase'] as $k) {
            if ($this->{$k}) {
                static::where('id', '!=', $this->id)->where($k, true)->get()->each(fn ($t) => $t->forceFill([$k => false])->save());
            }
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->is_default_sale || $this->is_default_purchase) {
            throw new \DomainException('Condition utilisée par défaut : choisissez-en une autre avant de la supprimer.');
        }
        if (DB::table('trade_documents')->where('payment_term_id', $this->id)->whereNull('deleted_at')->exists()) {
            throw new \DomainException('Condition utilisée par des documents : désactivez-la plutôt que de la supprimer.');
        }
        Partner::where('payment_term_id', $this->id)->update(['payment_term_id' => null]);
        Partner::where('supplier_payment_term_id', $this->id)->update(['supplier_payment_term_id' => null]);
    }

    /** Date d'une échéance à partir de la date du document. */
    public static function dueDate(Carbon $from, int $days, bool $endOfMonth): Carbon
    {
        $d = $from->copy()->addDays($days);

        return $endOfMonth ? $d->endOfMonth()->startOfDay() : $d;
    }

    /**
     * Échéancier : [{date, percent, amount}], arrondi reporté sur la dernière échéance.
     *
     * @return array<int, array{date: string, percent: float, amount: float}>
     */
    public function schedule(Carbon $from, float $total, int $decimals = 2): array
    {
        $out = [];
        $done = 0.0;
        $lines = $this->lines ?: [['percent' => 100, 'days' => 0, 'end_of_month' => false]];
        foreach ($lines as $i => $l) {
            $amount = $i === count($lines) - 1 ? round($total - $done, $decimals) : round($total * (float) $l['percent'] / 100, $decimals);
            $done += $amount;
            $out[] = ['date' => self::dueDate($from, (int) $l['days'], (bool) ($l['end_of_month'] ?? false))->toDateString(), 'percent' => (float) $l['percent'], 'amount' => $amount];
        }

        return $out;
    }

    /** Libellé lisible : « 30 % à réception, 70 % à 30 jours fin de mois ». */
    public static function describe(array $lines): string
    {
        $one = count($lines) === 1;

        return implode(', ', array_map(function ($l) use ($one) {
            $when = (int) $l['days'] === 0 ? ((bool) ($l['end_of_month'] ?? false) ? 'fin de mois' : 'à réception') : 'à '.(int) $l['days'].' jours'.((bool) ($l['end_of_month'] ?? false) ? ' fin de mois' : '');

            return ($one ? '' : rtrim(rtrim(number_format((float) $l['percent'], 2, ',', ''), '0'), ',').' % ').$when;
        }, $lines));
    }

    /** Conditions par défaut d'une société (création au premier passage). */
    public static function ensureDefaults(int $legacyDays = 30): void
    {
        if (static::withTrashed()->exists()) {
            return;
        }
        $mk = function (string $name, array $lines, int $sort, bool $sale = false, bool $purchase = false) {
            $t = new static(['name' => $name, 'lines' => $lines, 'sort' => $sort, 'is_default_sale' => $sale, 'is_default_purchase' => $purchase]);
            $t->save();
        };
        $l = fn (float $p, int $d, bool $eom = false) => ['percent' => $p, 'days' => $d, 'end_of_month' => $eom];
        $mk('Comptant (à réception)', [$l(100, 0)], 1, $legacyDays === 0, $legacyDays === 0);
        if (! in_array($legacyDays, [0, 30, 60], true)) {
            $mk($legacyDays.' jours', [$l(100, $legacyDays)], 2, true, true);
        }
        $mk('30 jours', [$l(100, 30)], 3, $legacyDays === 30, $legacyDays === 30);
        $mk('30 jours fin de mois', [$l(100, 30, true)], 4);
        $mk('45 jours fin de mois', [$l(100, 45, true)], 5);
        $mk('60 jours', [$l(100, 60)], 6, $legacyDays === 60, $legacyDays === 60);
        $mk('Acompte 30 % à la commande, solde à 30 jours', [$l(30, 0), $l(70, 30)], 7);
        $mk('50 % à 30 jours, 50 % à 60 jours', [$l(50, 30), $l(50, 60)], 8);
    }
}
