<?php

namespace App\Modules\Fournisseurs\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Core\Models\Partner;
use App\Modules\Fournisseurs\FournisseursService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Appel d'offres envoyé à plusieurs fournisseurs. */
class Tender extends Model
{
    use Syncable;

    protected $table = 'sp_tenders';

    public static string $syncEntity = 'sp_tenders';

    public static string $viewPermission = 'portail_fournisseurs.view|portail_fournisseurs.manage';

    public static string $managePermission = 'portail_fournisseurs.manage';

    public static array $searchable = ['number', 'title'];

    public const STATUSES = ['draft' => 'Brouillon', 'open' => 'Ouvert', 'closed' => 'Clos', 'awarded' => 'Attribué', 'cancelled' => 'Annulé'];

    protected $fillable = ['title', 'description', 'lines', 'deadline', 'status', 'invited', 'currency', 'delivery_place', 'project_id'];

    protected $attributes = ['status' => 'draft'];

    protected $hidden = ['company_id', 'deleted_at'];

    public ?string $fromStatus = null;

    protected function casts(): array
    {
        return ['lines' => 'array', 'invited' => 'array', 'deadline' => 'datetime', 'published_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:10000',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.id' => 'nullable|string|max:40',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit' => 'nullable|string|max:30',
            'lines.*.product_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('products')],
            'deadline' => 'required|date',
            'status' => 'in:draft,open,closed,cancelled',
            'invited' => 'required|array|min:1|max:100',
            'invited.*' => 'uuid',
            'currency' => 'nullable|string|size:3',
            'delivery_place' => 'nullable|string|max:255',
            'project_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('prj_projects')],
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->fromStatus = $creating ? 'draft' : $this->status;
        if (! $creating && in_array($this->status, ['awarded', 'cancelled'], true)) {
            throw new \DomainException('Appel d\'offres '.mb_strtolower(self::STATUSES[$this->status]).' : il ne se modifie plus.');
        }
        if (! $creating && $this->status !== 'draft' && array_intersect(array_keys($data), ['lines', 'currency'])) {
            throw new \DomainException('Appel d\'offres publié : les lignes ne changent plus (annulez-le et créez-en un autre).');
        }
        if (isset($data['lines'])) {
            $data['lines'] = array_values(array_map(fn ($l) => ['id' => $l['id'] ?? Str::random(8), 'description' => $l['description'], 'quantity' => (float) $l['quantity'],
                'unit' => $l['unit'] ?? null, 'product_id' => $l['product_id'] ?? null], $data['lines']));
        }
        foreach ((array) ($data['invited'] ?? []) as $pid) {
            if (! Partner::whereKey($pid)->exists()) {
                throw new \DomainException('Fournisseur inconnu.');
            }
        }
        if ($creating) {
            $this->number = Sequence::next(app('tenant')->id, 'sp_tender', 'AO');
            $this->created_by = auth()->user()?->name;
            $this->currency ??= app('tenant')->currency;
        }
        $to = $data['status'] ?? $this->fromStatus;
        if ($to === 'open' && $this->fromStatus === 'draft') {
            if (($data['deadline'] ?? $this->deadline?->toDateTimeString()) <= now()->toDateTimeString()) {
                throw new \DomainException('La date limite des offres doit être dans le futur.');
            }
            $this->published_at = now();
        } elseif ($to !== $this->fromStatus && ! ($to === 'cancelled' || ($to === 'closed' && $this->fromStatus === 'open'))) {
            throw new \DomainException('Changement impossible : '.self::STATUSES[$this->fromStatus].' → '.(self::STATUSES[$to] ?? $to).'.');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(FournisseursService::class)->afterTenderWrite($this);
    }

    public function beforeEntityDelete(): void
    {
        if ($this->status !== 'draft') {
            throw new \DomainException('Seul un brouillon se supprime : annulez l\'appel d\'offres.');
        }
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && $this->deadline->isFuture();
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['number' => $this->number, 'awarded_partner_id' => $this->awarded_partner_id, 'order_id' => $this->order_id, 'published_at' => $this->published_at?->toIso8601String(), 'created_by' => $this->created_by];
    }
}
