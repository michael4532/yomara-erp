<?php

namespace App\Modules\Abonnements\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\TradeDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Contrat d'abonnement : facture générée à chaque échéance (mensuelle, trimestrielle, annuelle…). */
class Subscription extends Model
{
    use Syncable;

    protected $table = 'ab_subscriptions';

    public static string $syncEntity = 'ab_subscriptions';

    public static string $viewPermission = 'abonnements.view|abonnements.manage';

    public static string $managePermission = 'abonnements.manage';

    public static array $searchable = ['number', 'name'];

    protected $fillable = ['partner_id', 'name', 'status', 'start_date', 'next_date', 'interval_unit', 'interval_count', 'end_date', 'max_invoices',
        'lines', 'currency', 'auto_validate', 'auto_send', 'in_advance', 'analytic', 'notes'];

    protected $attributes = ['status' => 'active', 'interval_unit' => 'month', 'interval_count' => 1, 'auto_validate' => true, 'auto_send' => false, 'in_advance' => true, 'generated_count' => 0];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d', 'next_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'lines' => 'array', 'analytic' => 'array',
            'auto_validate' => 'boolean', 'auto_send' => 'boolean', 'in_advance' => 'boolean', 'interval_count' => 'integer',
            'max_invoices' => 'integer', 'generated_count' => 'integer', 'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'name' => 'required|string|max:255',
            'status' => 'in:active,paused,ended',
            'start_date' => 'required|date',
            'next_date' => 'nullable|date',
            'interval_unit' => 'in:week,month,year',
            'interval_count' => 'integer|min:1|max:36',
            'end_date' => 'nullable|date',
            'max_invoices' => 'nullable|integer|min:1|max:1000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $c)],
            'lines.*.unit' => 'nullable|string|max:30',
            'currency' => ['nullable', 'string', 'size:3'],
            'auto_validate' => 'boolean',
            'auto_send' => 'boolean',
            'in_advance' => 'boolean',
            'analytic' => 'nullable|array',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (array_key_exists('analytic', $data)) {
            $data['analytic'] = TradeDocument::cleanAnalytic($data['analytic']);
        }
        if ($creating) {
            $data['next_date'] ??= $data['start_date'];
            $this->number = Sequence::next(app('tenant')->id, 'abonnement', 'ABO', (int) substr((string) $data['start_date'], 0, 4));
        }
        if (! empty($data['end_date']) && $data['end_date'] < ($data['start_date'] ?? $this->start_date?->toDateString())) {
            throw new \DomainException('La date de fin précède le début du contrat.');
        }
        if (($data['status'] ?? null) === 'active' && $this->status === 'ended' && $this->max_invoices && $this->generated_count >= $this->max_invoices) {
            throw new \DomainException('Ce contrat a atteint son nombre de factures : augmentez-le pour le réactiver.');
        }
        if (isset($data['lines'])) {
            $data['lines'] = array_values(array_map(fn ($l) => [
                'product_id' => $l['product_id'] ?? null, 'description' => $l['description'], 'quantity' => (float) $l['quantity'], 'unit' => $l['unit'] ?? null,
                'unit_price' => (float) $l['unit_price'], 'tax_rate' => (float) ($l['tax_rate'] ?? 0), 'discount_percent' => (float) ($l['discount_percent'] ?? 0),
            ], $data['lines']));
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->generated_count > 0) {
            throw new \DomainException('Ce contrat a déjà généré des factures : terminez-le plutôt.');
        }
    }

    /** Montant HT d'une échéance (devise du contrat). */
    public function periodAmount(): float
    {
        return round(collect($this->lines ?? [])->sum(fn ($l) => (float) $l['quantity'] * (float) $l['unit_price'] * (1 - (float) ($l['discount_percent'] ?? 0) / 100)), 2);
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + [
            'period_amount' => $this->periodAmount(),
            'invoices_count' => (int) DB::table('ab_invoices')->where('subscription_id', $this->id)->count(),
        ];
    }
}
