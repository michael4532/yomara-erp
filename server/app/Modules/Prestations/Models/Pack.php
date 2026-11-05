<?php

namespace App\Modules\Prestations\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\Partner;
use App\Modules\Prestations\PrestationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Forfait d'heures prépayé d'un client. */
class Pack extends Model
{
    use Syncable;

    protected $table = 'pr_packs';

    public static string $syncEntity = 'pr_packs';

    public static string $viewPermission = 'prestations.view|prestations.log|prestations.manage';

    public static string $managePermission = 'prestations.manage';

    public static array $searchable = ['number', 'name', 'partner_name'];

    protected $fillable = ['partner_id', 'name', 'hours', 'price', 'tax_rate', 'hourly_rate', 'start_date', 'expiry_date', 'status', 'alert_percent', 'notes'];

    protected $attributes = ['status' => 'active', 'tax_rate' => 18, 'alert_percent' => 80, 'price' => 0, 'overage_billed' => 0];

    protected $hidden = ['company_id', 'deleted_at'];

    /** Facturer le forfait à la création (option de l'appareil). */
    public bool $invoiceNow = false;

    protected function casts(): array
    {
        return ['hours' => 'float', 'price' => 'float', 'tax_rate' => 'float', 'hourly_rate' => 'float', 'start_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d',
            'alert_percent' => 'integer', 'overage_billed' => 'float', 'overage_invoices' => 'array', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'name' => 'required|string|max:255',
            'hours' => 'required|numeric|gt:0|max:100000',
            'price' => 'numeric|min:0',
            'tax_rate' => 'numeric|min:0|max:100',
            'hourly_rate' => 'nullable|numeric|min:0',
            'start_date' => 'required|date',
            'expiry_date' => 'nullable|date',
            'status' => 'in:active,exhausted,expired,cancelled',
            'alert_percent' => 'integer|min:10|max:99',
            'notes' => 'nullable|string|max:3000',
            'invoice' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->invoiceNow = $creating && ! empty($data['invoice']);
        unset($data['invoice']);
        if (isset($data['partner_id']) || $creating) {
            if (! $creating && $this->invoice_id && ($data['partner_id'] ?? $this->partner_id) !== $this->partner_id) {
                throw new \DomainException('Forfait déjà facturé : le client ne change plus.');
            }
            $this->partner_name = Partner::find($data['partner_id'] ?? $this->partner_id)?->name;
        }
        if ($creating) {
            $this->number = Sequence::next(app('tenant')->id, 'pr_pack', 'FOR');
        }
        $start = $data['start_date'] ?? $this->start_date?->toDateString();
        $exp = array_key_exists('expiry_date', $data) ? $data['expiry_date'] : $this->expiry_date?->toDateString();
        if ($start && $exp && $exp < $start) {
            throw new \DomainException('La date d\'expiration précède le début du forfait.');
        }
        if (! $creating && isset($data['hours']) && $this->invoice_id && (float) $data['hours'] !== (float) $this->hours) {
            throw new \DomainException('Forfait facturé : le nombre d\'heures ne change plus (créez un complément).');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        $svc = app(PrestationService::class);
        if ($this->invoiceNow && ! $this->invoice_id && $this->price > 0) {
            $svc->invoicePack($this);
        }
        $svc->refreshPack($this->fresh());
    }

    public function beforeEntityDelete(): void
    {
        if ($this->invoice_id || Entry::where('pack_id', $this->id)->exists()) {
            throw new \DomainException('Ce forfait a du temps saisi ou une facture : annulez-le plutôt.');
        }
    }

    public function consumed(): float
    {
        return round((float) Entry::where('pack_id', $this->id)->sum('hours'), 2);
    }

    public function toSyncArray(): array
    {
        $used = $this->consumed();

        return $this->toArray() + ['number' => $this->number, 'partner_name' => $this->partner_name, 'invoice_id' => $this->invoice_id, 'overage_billed' => $this->overage_billed,
            'consumed' => $used, 'remaining' => round($this->hours - $used, 2), 'percent' => $this->hours > 0 ? round(100 * $used / $this->hours, 1) : 0,
            'overage_unbilled' => round(max(0, $used - $this->hours) - $this->overage_billed, 2)];
    }
}
