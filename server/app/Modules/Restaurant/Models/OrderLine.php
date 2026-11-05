<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Core\Models\Product;
use App\Modules\Restaurant\RestaurantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Ligne de commande : à envoyer (pending) → envoyée (sent) → en préparation → prête → servie.
 * Une ligne envoyée ne change plus de contenu : on l'annule (avec un motif) et on en ajoute une autre.
 */
class OrderLine extends Model
{
    use Syncable;

    protected $table = 'rs_order_lines';

    public static string $syncEntity = 'rs_order_lines';

    public static string $viewPermission = 'restaurant.serve|restaurant.kitchen|restaurant.manage|pos.use';

    public static string $managePermission = 'restaurant.serve|restaurant.kitchen|restaurant.manage';

    public const FLOW = ['pending' => 0, 'sent' => 1, 'preparing' => 2, 'ready' => 3, 'served' => 4];

    protected $fillable = ['order_id', 'product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'course', 'note', 'station_id', 'status', 'cancel_reason'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_price' => 'float',
            'tax_rate' => 'float',
            'course' => 'integer',
            'sent_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'order_id' => ['required', 'uuid', Rule::exists('rs_orders', 'id')->where('company_id', $company)],
            'product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $company)],
            'description' => 'nullable|string|max:255',
            'quantity' => 'required|numeric|gt:0|max:9999',
            'unit_price' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'course' => 'nullable|integer|min:0|max:9',
            'note' => 'nullable|string|max:255',
            'station_id' => ['nullable', 'uuid', Rule::exists('rs_stations', 'id')->where('company_id', $company)],
            'status' => 'nullable|in:pending,sent,preparing,ready,served,cancelled',
            'cancel_reason' => 'nullable|string|max:255',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $can = fn (string $p) => $user?->hasPermission($p) ?? true;
        $serve = $can('restaurant.serve') || $can('restaurant.manage');
        $manager = $can('restaurant.manage');
        $svc = app(RestaurantService::class);

        $order = Order::find($data['order_id'] ?? $this->order_id);
        if (! $order) {
            throw new \DomainException('Commande introuvable.');
        }

        if ($creating) {
            if (! $serve) {
                throw new \DomainException('Seul le personnel de salle prend les commandes.');
            }
            if ($order->status !== 'open') {
                throw new \DomainException('Cette commande est déjà réglée ou annulée.');
            }
            $product = ! empty($data['product_id']) ? Product::find($data['product_id']) : null;
            if ($product) {
                $data['description'] = ($data['description'] ?? null) ?: $product->name;
                if (! $manager || ! array_key_exists('unit_price', $data)) {
                    $data['unit_price'] = $product->sale_price; // prix du catalogue imposé au serveur
                }
                $data['tax_rate'] = $product->tax_rate ?? ($data['tax_rate'] ?? 0);
                $data['station_id'] = ($data['station_id'] ?? null) ?: $svc->stationFor($product)?->id;
            } elseif (empty($data['description'])) {
                throw new \DomainException('Choisissez un article.');
            } elseif (! $manager) {
                throw new \DomainException('Seul un responsable peut ajouter un article hors carte.');
            }
            $data['status'] ??= 'pending';
            if (! in_array($data['status'], ['pending', 'sent'], true)) {
                $data['status'] = 'sent';
            }
            $this->user_id = $user?->id;
            $this->applyStatus($data['status']);

            return;
        }

        // Mise à jour
        $from = $this->status;
        $to = $data['status'] ?? $from;
        if ($from !== 'pending') {
            $content = array_intersect_key($data, array_flip(['order_id', 'product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'station_id']));
            $locked = array_filter($content, fn ($v, $k) => is_numeric($v) && is_numeric($this->getAttribute($k)) ? abs((float) $v - (float) $this->getAttribute($k)) > 0.0001 : (string) $v !== (string) $this->getAttribute($k), ARRAY_FILTER_USE_BOTH);
            if ($locked) {
                throw new \DomainException('Ligne déjà envoyée en cuisine : annulez-la et ajoutez-en une nouvelle.');
            }
            if (array_key_exists('note', $data) || array_key_exists('course', $data)) {
                unset($data['note'], $data['course']);
            }
        } elseif (! $serve) {
            throw new \DomainException('Cette ligne n\'a pas encore été envoyée.');
        }

        if ($to !== $from) {
            if ($from === 'cancelled') {
                throw new \DomainException('Cette ligne a été annulée.');
            }
            if ($to === 'cancelled') {
                if (! $serve) {
                    throw new \DomainException('La cuisine ne peut pas annuler une ligne : prévenez la salle.');
                }
                if ($from === 'served' && ! $manager) {
                    throw new \DomainException('Seul un responsable peut annuler un plat déjà servi.');
                }
                if ($from !== 'pending' && blank($data['cancel_reason'] ?? $this->cancel_reason)) {
                    throw new \DomainException('Indiquez le motif de l\'annulation.');
                }
                if (($svc->paid($order)[$this->id] ?? 0) > 0) {
                    throw new \DomainException('Cette ligne est déjà encaissée : annulez d\'abord le ticket.');
                }
            } elseif (self::FLOW[$to] < self::FLOW[$from] && ! ($manager || ($serve && $to === 'ready'))) {
                throw new \DomainException('Retour en arrière impossible.');
            } elseif (! $serve && ! in_array($to, ['preparing', 'ready', 'served'], true)) {
                throw new \DomainException('Action réservée à la salle.');
            }
            $this->applyStatus($to);
        }
    }

    protected function applyStatus(string $to): void
    {
        $now = now();
        if (in_array($to, ['sent', 'preparing', 'ready', 'served'], true)) {
            $this->sent_at ??= $now;
        }
        if (in_array($to, ['ready', 'served'], true)) {
            $this->ready_at ??= $now;
        }
        if ($to === 'served') {
            $this->served_at ??= $now;
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($this->wasChanged('status') || $creating) {
            app(RestaurantService::class)->lineChanged($this, $creating);
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->status !== 'pending') {
            throw new \DomainException('Ligne déjà envoyée en cuisine : annulez-la avec un motif.');
        }
    }

    public function total(): float
    {
        return round($this->quantity * $this->unit_price * (1 + $this->tax_rate / 100), 2);
    }
}
