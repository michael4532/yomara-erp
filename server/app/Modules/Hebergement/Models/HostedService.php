<?php

namespace App\Modules\Hebergement\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\Partner;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Service récurrent d'un client : nom de domaine, hébergement, SSL, e-mail pro, maintenance, licence… */
class HostedService extends Model
{
    use Syncable;

    protected $table = 'hb_services';

    public static string $syncEntity = 'hb_services';

    public static string $viewPermission = 'hebergement.view|hebergement.manage';

    public static string $managePermission = 'hebergement.manage';

    public static array $searchable = ['name', 'partner_name', 'number'];

    public const TYPES = ['domain' => 'Nom de domaine', 'hosting' => 'Hébergement', 'ssl' => 'Certificat SSL', 'email' => 'E-mail professionnel',
        'maintenance' => 'Maintenance', 'license' => 'Licence logicielle', 'cloud' => 'Serveur / cloud', 'other' => 'Autre service'];

    protected $fillable = ['partner_id', 'type', 'name', 'provider', 'supplier_id', 'plan', 'details', 'cost_price', 'sale_price', 'tax_rate', 'period_months',
        'start_date', 'expiry_date', 'auto_renew', 'renew_days_before', 'reminders', 'status', 'product_id', 'notes'];

    protected $attributes = ['type' => 'domain', 'cost_price' => 0, 'sale_price' => 0, 'tax_rate' => 18, 'period_months' => 12, 'auto_renew' => true,
        'renew_days_before' => 30, 'reminders' => true, 'status' => 'active'];

    protected $hidden = ['company_id', 'deleted_at', 'last_reminder'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d', 'cost_price' => 'float', 'sale_price' => 'float', 'tax_rate' => 'float',
            'period_months' => 'integer', 'renew_days_before' => 'integer', 'auto_renew' => 'boolean', 'reminders' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'type' => 'in:'.implode(',', array_keys(self::TYPES)),
            'name' => 'required|string|max:255',
            'provider' => 'nullable|string|max:120',
            'supplier_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $c)],
            'plan' => 'nullable|string|max:120',
            'details' => 'nullable|string|max:3000',
            'cost_price' => 'numeric|min:0',
            'sale_price' => 'numeric|min:0',
            'tax_rate' => 'numeric|min:0|max:100',
            'period_months' => 'integer|min:1|max:120',
            'start_date' => 'nullable|date',
            'expiry_date' => 'required|date',
            'auto_renew' => 'boolean',
            'renew_days_before' => 'integer|min:0|max:365',
            'reminders' => 'boolean',
            'status' => 'in:active,suspended,cancelled',
            'product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $c)],
            'notes' => 'nullable|string|max:3000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if (($data['type'] ?? $this->type) === 'domain') {
                $data['name'] = mb_strtolower(preg_replace('#^https?://(www\.)?#i', '', rtrim($data['name'], '/')));
            }
        }
        if (isset($data['partner_id']) || $creating) {
            $this->partner_name = Partner::find($data['partner_id'] ?? $this->partner_id)?->name;
        }
        if ($creating) {
            $this->number = Sequence::next(app('tenant')->id, 'hb_service', 'SRV');
        }
        $start = $data['start_date'] ?? $this->start_date?->toDateString();
        $exp = $data['expiry_date'] ?? $this->expiry_date?->toDateString();
        if ($start && $exp && $exp < $start) {
            throw new \DomainException('L\'échéance précède la date de début.');
        }
        $type = $data['type'] ?? $this->type;
        $name = $data['name'] ?? $this->name;
        if (in_array($type, ['domain', 'ssl'], true) && static::where('type', $type)->where('id', '<>', (string) $this->id)->where('status', '<>', 'cancelled')->where('name', $name)->exists()) {
            throw new \DomainException('« '.$name.' » est déjà suivi ('.self::TYPES[$type].').');
        }
    }

    public function beforeEntityDelete(): void
    {
        if (Renewal::where('service_id', $this->id)->where('status', '<>', 'cancelled')->exists()) {
            throw new \DomainException('Ce service a un historique de renouvellements : résiliez-le plutôt.');
        }
    }

    /** Renouvellement facturé en attente de paiement. */
    public function pendingRenewal(): ?Renewal
    {
        return Renewal::where('service_id', $this->id)->where('status', 'invoiced')->orderByDesc('period_start')->first();
    }

    public function daysLeft(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->expiry_date->copy()->startOfDay(), false);
    }

    public function state(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }
        $d = $this->daysLeft();

        return match (true) {
            $d < 0 => 'expired',
            $d <= max(30, $this->renew_days_before) => 'expiring',
            default => 'ok',
        };
    }

    public function toSyncArray(): array
    {
        $p = $this->pendingRenewal();
        $doc = $p?->document_id ? SalesDocument::find($p->document_id) : null;
        $todo = Renewal::where('service_id', $this->id)->whereIn('status', ['paid'])->whereNull('provider_done_at')->orderBy('period_start')->first();

        return $this->toArray() + [
            'number' => $this->number, 'partner_name' => $this->partner_name, 'last_error' => $this->last_error,
            'state' => $this->state(), 'days_left' => $this->daysLeft(),
            'monthly' => round($this->sale_price / max(1, $this->period_months), 2), 'margin' => round($this->sale_price - $this->cost_price, 2),
            'pending' => $p ? ['renewal_id' => $p->id, 'document_id' => $p->document_id, 'number' => $doc?->number, 'total' => (float) ($doc?->total ?? 0),
                'payment_status' => $doc?->payment_status, 'period_start' => $p->period_start->toDateString(), 'period_end' => $p->period_end->toDateString()] : null,
            'provider_todo' => $todo ? ['renewal_id' => $todo->id, 'period_end' => $todo->period_end->toDateString(), 'paid_at' => $todo->paid_at?->toIso8601String()] : null,
        ];
    }
}
