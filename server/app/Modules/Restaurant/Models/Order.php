<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Restaurant\RestaurantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Commande de table (ou à emporter). Les lignes vivent à part (rs_order_lines)
 * pour que la cuisine et la salle les mettent à jour sans conflit. Les additions
 * sont des tickets de caisse reliés à la commande (rs_order_id + quantités réglées) :
 * la commande se clôt toute seule quand tout est réglé, et se rouvre si un ticket est annulé.
 */
class Order extends Model
{
    use Syncable;

    protected $table = 'rs_orders';

    public static string $syncEntity = 'rs_orders';

    public static array $searchable = ['number', 'customer_name', 'waiter_name'];

    public static string $viewPermission = 'restaurant.serve|restaurant.kitchen|restaurant.manage|pos.use';

    public static string $managePermission = 'restaurant.serve|restaurant.manage|pos.use';

    public const STATUSES = ['open', 'closed', 'cancelled'];

    protected $fillable = ['kind', 'table_id', 'guests', 'customer_name', 'partner_id', 'notes', 'opened_at', 'bill_requested_at', 'waiter_id'];

    protected function casts(): array
    {
        return [
            'guests' => 'integer',
            'opened_at' => 'datetime',
            'bill_requested_at' => 'datetime',
            'closed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'kind' => 'nullable|in:dine_in,takeaway',
            'table_id' => ['nullable', 'uuid', Rule::exists('rs_tables', 'id')->where('company_id', $company)],
            'status' => 'nullable|in:open,closed,cancelled',
            'guests' => 'nullable|integer|min:1|max:200',
            'customer_name' => 'nullable|string|max:255',
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'notes' => 'nullable|string|max:2000',
            'opened_at' => 'nullable|date',
            'bill_requested_at' => 'nullable|date',
            'waiter_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'cancel_reason' => 'nullable|string|max:255',
        ];
    }

    public function lines()
    {
        return $this->hasMany(OrderLine::class, 'order_id');
    }

    public function table()
    {
        return $this->belongsTo(Table::class, 'table_id');
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $manager = $user?->hasPermission('restaurant.manage') ?? true;
        $requested = $data['status'] ?? null;
        unset($data['status']);

        if ($creating) {
            $this->number = Sequence::next(app('tenant')->id, 'rs_order', 'CO');
            $this->status = 'open';
            $data['opened_at'] ??= now()->toIso8601String();
            $data['kind'] ??= empty($data['table_id']) ? 'takeaway' : 'dine_in';
            if (empty($data['waiter_id']) || ! $manager) {
                $data['waiter_id'] = $user?->id;
            }
            $this->waiter_name = $user?->name;
        } else {
            if ($this->status !== 'open' && $requested !== 'open') {
                // Commande close : on n'accepte plus que les notes
                if (array_diff(array_keys($data), ['notes', 'bill_requested_at'])) {
                    throw new \DomainException('Cette commande est déjà '.($this->status === 'closed' ? 'réglée' : 'annulée').'.');
                }
            }
            if (array_key_exists('waiter_id', $data) && (int) $data['waiter_id'] !== (int) $this->waiter_id) {
                if (! $manager) {
                    unset($data['waiter_id']);
                } else {
                    $this->waiter_name = \App\Models\User::find($data['waiter_id'])?->name;
                }
            }
        }
        if (! empty($data['table_id'])) {
            $data['kind'] = 'dine_in';
        }

        if ($requested && $requested !== ($this->status ?? 'open')) {
            $svc = app(RestaurantService::class);
            match ($requested) {
                'cancelled' => $svc->assertCancellable($this, $data['cancel_reason'] ?? null, $manager),
                'closed' => $svc->assertClosable($this),
                'open' => $manager ? null : throw new \DomainException('Seul un responsable peut rouvrir une commande.'),
            };
            $this->status = $requested;
            $this->closed_at = $requested === 'open' ? null : now();
            $this->cancel_reason = $requested === 'cancelled' ? ($data['cancel_reason'] ?? null) : null;
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->status === 'cancelled') {
            // Lignes encore à préparer : retirées de l'écran cuisine
            foreach (OrderLine::where('order_id', $this->id)->whereNotIn('status', ['served', 'cancelled'])->get() as $l) {
                $l->forceFill(['status' => 'cancelled', 'cancel_reason' => $l->cancel_reason ?? 'Commande annulée'])->save();
            }
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->lines()->where('status', '!=', 'pending')->exists() || app(RestaurantService::class)->bills($this)->isNotEmpty()) {
            throw new \DomainException('Une commande envoyée en cuisine ou encaissée ne se supprime pas : annulez-la.');
        }
        $this->lines()->get()->each->delete();
    }

    public function label(): string
    {
        if ($this->table_id && $t = Table::find($this->table_id)) {
            return 'Table '.$t->name;
        }

        return $this->customer_name ? 'À emporter — '.$this->customer_name : 'À emporter '.$this->number;
    }
}
