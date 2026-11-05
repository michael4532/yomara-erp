<?php

namespace App\Modules\Evenements\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Offre vendue avec ou sans stand : pack sponsor, électricité, mobilier, badge, page du catalogue… */
class Package extends Model
{
    use Syncable;

    protected $table = 'ev_packages';

    public static string $syncEntity = 'ev_packages';

    public static string $viewPermission = 'evenements.view|evenements.sell|evenements.manage';

    public static string $managePermission = 'evenements.manage';

    protected $fillable = ['event_id', 'kind', 'name', 'description', 'price', 'quantity', 'benefits', 'sort', 'active', 'online'];

    protected $attributes = ['kind' => 'option', 'price' => 0, 'sort' => 0, 'active' => true, 'online' => true];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['price' => 'float', 'quantity' => 'integer', 'benefits' => 'array', 'sort' => 'integer', 'active' => 'boolean', 'online' => 'boolean', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'event_id' => ['required', 'uuid', Rule::exists('ev_events', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'kind' => 'in:sponsor,option',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'numeric|min:0',
            'quantity' => 'nullable|integer|min:0|max:100000',
            'benefits' => 'nullable|array|max:30',
            'benefits.*' => 'nullable|string|max:200',
            'sort' => 'integer|min:0|max:999',
            'active' => 'boolean',
            'online' => 'boolean',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            unset($data['event_id']);
        }
        if (isset($data['benefits'])) {
            $data['benefits'] = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $data['benefits']), fn ($v) => $v !== ''));
        }
        if (! $creating && array_key_exists('quantity', $data) && $data['quantity'] !== null && (int) $data['quantity'] < $this->sold()) {
            throw new \DomainException($this->sold().' « '.$this->name.' » déjà réservé(s) : la quantité ne peut pas descendre en dessous.');
        }
    }

    /** Quantité réservée (options et réservations confirmées). */
    public function sold(?string $exceptBookingId = null): int
    {
        $n = 0;
        foreach (Booking::where('event_id', $this->event_id)->whereIn('status', Booking::ACTIVE)->when($exceptBookingId, fn ($q) => $q->where('id', '<>', $exceptBookingId))->get(['items']) as $b) {
            foreach ($b->items ?? [] as $it) {
                if (($it['package_id'] ?? null) === $this->id) {
                    $n += (int) $it['quantity'];
                }
            }
        }

        return $n;
    }

    public function beforeEntityDelete(): void
    {
        if ($this->sold() > 0) {
            throw new \DomainException('Cette offre figure dans des réservations : désactivez-la plutôt.');
        }
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['sold' => $this->sold()];
    }
}
