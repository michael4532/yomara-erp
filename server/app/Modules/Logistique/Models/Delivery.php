<?php

namespace App\Modules\Logistique\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Bon de livraison. */
class Delivery extends Model
{
    use Syncable;

    protected $table = 'lg_deliveries';

    public static string $syncEntity = 'lg_deliveries';

    /** Écriture faite par le système (commande en ligne expédiée) : pas de contrôle des droits du livreur. */
    public static bool $systemWrite = false;

    public static string $viewPermission = 'logistique.view|logistique.deliver|logistique.manage';

    public static string $managePermission = 'logistique.manage|logistique.deliver';

    public static array $searchable = ['number', 'recipient_name', 'recipient_phone', 'address', 'city'];

    public const STATUSES = ['draft' => 'Brouillon', 'planned' => 'À livrer', 'in_transit' => 'En route', 'delivered' => 'Livrée', 'failed' => 'Échec', 'returned' => 'Retournée', 'cancelled' => 'Annulée'];

    /** Champs qu'un livreur peut modifier sur ses livraisons. */
    public const DRIVER_FIELDS = ['status', 'cod_collected', 'cod_method', 'signature', 'signed_by', 'proof_lat', 'proof_lng', 'failed_reason', 'notes', 'delivered_at'];

    protected $fillable = ['source_document_id', 'partner_id', 'recipient_name', 'recipient_phone', 'address', 'city', 'zone_id', 'latitude', 'longitude',
        'scheduled_date', 'time_slot', 'status', 'driver_id', 'tour_id', 'tour_seq', 'items', 'packages', 'weight_kg', 'fee', 'cod_amount', 'cod_collected',
        'cod_method', 'notes', 'signature', 'signed_by', 'proof_lat', 'proof_lng', 'delivered_at', 'failed_reason', 'notify'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d', 'items' => 'array', 'packages' => 'integer', 'weight_kg' => 'float', 'fee' => 'float',
            'cod_amount' => 'float', 'cod_collected' => 'float', 'latitude' => 'float', 'longitude' => 'float', 'proof_lat' => 'float', 'proof_lng' => 'float',
            'driver_id' => 'integer', 'tour_seq' => 'integer', 'attempts' => 'integer', 'notify' => 'boolean',
            'delivered_at' => 'datetime', 'cod_remitted_at' => 'datetime', 'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'source_document_id' => ['nullable', 'uuid', Rule::exists('trade_documents', 'id')->where('company_id', $company)],
            'partner_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'recipient_name' => 'nullable|string|max:255',
            'recipient_phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'zone_id' => ['nullable', 'uuid', Rule::exists('lg_zones', 'id')->where('company_id', $company)],
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'scheduled_date' => 'nullable|date',
            'time_slot' => 'nullable|string|max:30',
            'status' => 'nullable|in:'.implode(',', array_keys(self::STATUSES)),
            'driver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'tour_id' => ['nullable', 'uuid', Rule::exists('lg_tours', 'id')->where('company_id', $company)],
            'tour_seq' => 'nullable|integer|min:0',
            'items' => 'nullable|array|max:200',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'nullable|numeric',
            'items.*.unit' => 'nullable|string|max:30',
            'packages' => 'nullable|integer|min:0|max:10000',
            'weight_kg' => 'nullable|numeric|min:0',
            'fee' => 'nullable|numeric|min:0',
            'cod_amount' => 'nullable|numeric|min:0',
            'cod_collected' => 'nullable|numeric|min:0',
            'cod_method' => 'nullable|in:cash,mobile_money,card,cheque',
            'notes' => 'nullable|string|max:2000',
            'signature' => 'nullable|string|max:300000',
            'signed_by' => 'nullable|string|max:120',
            'proof_lat' => 'nullable|numeric|between:-90,90',
            'proof_lng' => 'nullable|numeric|between:-180,180',
            'delivered_at' => 'nullable|date',
            'failed_reason' => 'nullable|string|max:255',
            'notify' => 'nullable|boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($user && ! static::$systemWrite && ! $user->hasPermission('logistique.manage')) {
            if ($creating) {
                throw new \DomainException('Seul le service logistique crée les livraisons.');
            }
            if ($this->driver_id !== $user->id) {
                throw new \DomainException('Cette livraison n\'est pas attribuée à vous.');
            }
            $data = array_intersect_key($data, array_flip(self::DRIVER_FIELDS));
        }
        if (! empty($data['signature']) && ! preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $data['signature'])) {
            throw new \DomainException('Signature invalide.');
        }
        if (! $creating && $this->cod_remitted_at && array_key_exists('cod_collected', $data) && (float) $data['cod_collected'] !== (float) $this->cod_collected) {
            throw new \DomainException('L\'encaissement a déjà été remis à la caisse.');
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            $d->tracking_token ??= Str::random(32);
            $d->number ??= Sequence::next($d->company_id, 'delivery', app('tenant')?->settings['delivery_prefix'] ?? 'LIV');
            $d->status ??= 'planned';
        });
        static::saving(function (self $d) {
            if ($d->isDirty('status')) {
                $d->delivered_at = $d->status === 'delivered' ? ($d->delivered_at ?? now()) : null;
                if ($d->status === 'failed') {
                    $d->attempts = ($d->attempts ?? 0) + 1;
                }
                if (! in_array($d->status, ['failed', 'planned', 'returned'], true)) {
                    $d->failed_reason = null;
                }
            }
        });
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class, 'delivery_id');
    }

    public function trackingUrl(): string
    {
        return url('/t/'.$this->tracking_token);
    }
}
