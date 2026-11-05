<?php

namespace App\Modules\Interventions\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Fiche d'intervention chez un client. */
class Intervention extends Model
{
    use Syncable;

    protected $table = 'it_interventions';

    public static string $syncEntity = 'it_interventions';

    public static string $viewPermission = 'interventions.view|interventions.do|interventions.manage';

    public static string $managePermission = 'interventions.manage|interventions.do';

    public static array $searchable = ['number', 'title', 'description', 'address', 'contact_name'];

    public const STATUSES = ['requested' => 'Demandée', 'planned' => 'Planifiée', 'en_route' => 'En route', 'in_progress' => 'En cours', 'done' => 'Terminée', 'cancelled' => 'Annulée'];

    public const TYPES = ['corrective' => 'Dépannage', 'preventive' => 'Maintenance préventive', 'installation' => 'Installation', 'other' => 'Autre'];

    public const PRIORITIES = ['Basse', 'Normale', 'Haute', 'Urgente'];

    /** Ce qu'un technicien peut modifier sur ses interventions. */
    public const TECH_FIELDS = ['status', 'started_at', 'finished_at', 'worked_minutes', 'report', 'recommendations', 'checklist', 'parts', 'signature', 'signed_by', 'signed_at', 'satisfaction', 'equipment_id'];

    protected $fillable = ['partner_id', 'contract_id', 'equipment_id', 'type', 'priority', 'title', 'description', 'address', 'city', 'contact_name', 'contact_phone',
        'scheduled_at', 'duration_minutes', 'technician_id', 'status', 'source', 'started_at', 'finished_at', 'worked_minutes', 'report', 'recommendations',
        'checklist', 'parts', 'signature', 'signed_by', 'signed_at', 'satisfaction', 'billable', 'labor_rate', 'travel_fee', 'notify'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'signed_at' => 'datetime',
            'priority' => 'integer', 'duration_minutes' => 'integer', 'worked_minutes' => 'integer', 'technician_id' => 'integer', 'satisfaction' => 'integer',
            'checklist' => 'array', 'parts' => 'array', 'photos' => 'array', 'billable' => 'boolean', 'notify' => 'boolean', 'stock_posted' => 'boolean',
            'labor_rate' => 'float', 'travel_fee' => 'float', 'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'contract_id' => ['nullable', 'uuid', Rule::exists('it_contracts', 'id')->where('company_id', $company)],
            'equipment_id' => ['nullable', 'uuid', Rule::exists('it_equipments', 'id')->where('company_id', $company)],
            'type' => 'nullable|in:'.implode(',', array_keys(self::TYPES)),
            'priority' => 'nullable|integer|between:0,3',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'contact_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:40',
            'scheduled_at' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:5|max:1440',
            'technician_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'status' => 'nullable|in:'.implode(',', array_keys(self::STATUSES)),
            'source' => 'nullable|in:manual,portal,contract',
            'started_at' => 'nullable|date',
            'finished_at' => 'nullable|date',
            'worked_minutes' => 'nullable|integer|min:0|max:100000',
            'report' => 'nullable|string|max:10000',
            'recommendations' => 'nullable|string|max:5000',
            'checklist' => 'nullable|array|max:100',
            'checklist.*.text' => 'required|string|max:255',
            'checklist.*.done' => 'nullable|boolean',
            'parts' => 'nullable|array|max:100',
            'parts.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $company)],
            'parts.*.description' => 'required|string|max:255',
            'parts.*.quantity' => 'required|numeric|min:0.001',
            'parts.*.unit_price' => 'nullable|numeric|min:0',
            'signature' => 'nullable|string|max:300000',
            'signed_by' => 'nullable|string|max:120',
            'signed_at' => 'nullable|date',
            'satisfaction' => 'nullable|integer|between:1,5',
            'billable' => 'nullable|boolean',
            'labor_rate' => 'nullable|numeric|min:0',
            'travel_fee' => 'nullable|numeric|min:0',
            'notify' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('interventions.manage')) {
            if ($creating) {
                throw new \DomainException('Seul le responsable crée les interventions.');
            }
            if ($this->technician_id !== $user->id) {
                throw new \DomainException('Cette intervention n\'est pas attribuée à vous.');
            }
            if ($this->invoice_id || ($this->status === 'done' && ($data['status'] ?? 'done') === 'done' && $this->signed_at)) {
                throw new \DomainException('Ce rapport est signé : demandez au responsable pour le modifier.');
            }
            $data = array_intersect_key($data, array_flip(self::TECH_FIELDS));
            if (isset($data['status']) && ! in_array($data['status'], ['planned', 'en_route', 'in_progress', 'done'], true)) {
                throw new \DomainException('Statut non autorisé.');
            }
            // Le technicien ne fixe pas les prix des pièces
            if (isset($data['parts'])) {
                $data['parts'] = array_map(function ($p) {
                    unset($p['unit_price']);

                    return $p;
                }, $data['parts']);
            }
        }
        if ($this->invoice_id && array_intersect(array_keys($data), ['parts', 'worked_minutes', 'labor_rate', 'travel_fee', 'billable', 'partner_id'])) {
            throw new \DomainException('Cette intervention est facturée : modifiez la facture.');
        }
        if (! empty($data['signature']) && ! preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $data['signature'])) {
            throw new \DomainException('Signature invalide.');
        }
        if (($data['status'] ?? null) === 'done' && empty($data['report']) && empty($this->report)) {
            throw new \DomainException('Rédigez le rapport (travaux réalisés) avant de terminer.');
        }
        // Prix des pièces : celui du catalogue si non indiqué
        if (isset($data['parts'])) {
            $prices = Product::whereIn('id', collect($data['parts'])->pluck('product_id')->filter())->pluck('sale_price', 'id');
            $old = collect($this->parts ?? [])->keyBy(fn ($p) => $p['product_id'] ?? $p['description']);
            $data['parts'] = array_values(array_map(function ($p) use ($prices, $old) {
                if (! isset($p['unit_price'])) {
                    $p['unit_price'] = $old[$p['product_id'] ?? $p['description']]['unit_price'] ?? (isset($p['product_id']) ? (float) ($prices[$p['product_id']] ?? 0) : 0);
                }

                return ['product_id' => $p['product_id'] ?? null, 'description' => $p['description'], 'quantity' => (float) $p['quantity'], 'unit_price' => (float) $p['unit_price']];
            }, $data['parts']));
        }
    }

    public function beforeEntityDelete(): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('interventions.manage')) {
            throw new \DomainException('Seul le responsable supprime une intervention.');
        }
        if ($this->invoice_id) {
            throw new \DomainException('Cette intervention est facturée.');
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $i) {
            $i->report_token ??= Str::random(40);
            $i->number ??= Sequence::next($i->company_id, 'intervention', app('tenant')?->settings['intervention_prefix'] ?? 'INT');
            $i->status ??= $i->scheduled_at || $i->technician_id ? 'planned' : 'requested';
            $settings = app('tenant')?->settings ?? [];
            if (! $i->labor_rate) {
                $i->labor_rate = (float) ($settings['intervention_hourly_rate'] ?? 0);
            }
            if (! $i->travel_fee && $i->type !== 'preventive') {
                $i->travel_fee = (float) ($settings['intervention_travel_fee'] ?? 0);
            }
        });
        static::saving(function (self $i) {
            if ($i->status === 'requested' && $i->technician_id && $i->scheduled_at && $i->isDirty(['technician_id', 'scheduled_at'])) {
                $i->status = 'planned';
            }
            if ($i->isDirty('status')) {
                if (in_array($i->status, ['in_progress'], true)) {
                    $i->started_at ??= now();
                }
                if ($i->status === 'done') {
                    $i->finished_at ??= now();
                    if ($i->worked_minutes === null && $i->started_at) {
                        $i->worked_minutes = max(1, (int) round($i->started_at->diffInMinutes($i->finished_at)));
                    }
                }
                if (in_array($i->status, ['planned', 'en_route', 'in_progress'], true) && $i->getOriginal('status') === 'done') {
                    $i->finished_at = null;
                }
            }
            if ($i->isDirty('signature') && $i->signature) {
                $i->signed_at ??= now();
            }
        });
    }

    public function reportUrl(): string
    {
        return url('/r/'.$this->report_token);
    }

    public function partsTotal(): float
    {
        return round(collect($this->parts ?? [])->sum(fn ($p) => (float) $p['quantity'] * (float) ($p['unit_price'] ?? 0)), 2);
    }
}
