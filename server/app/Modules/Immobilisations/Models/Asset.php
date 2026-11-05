<?php

namespace App\Modules\Immobilisations\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Core\Models\TradeDocument;
use App\Modules\Immobilisations\AssetService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Immobilisation : brouillon (saisissable hors ligne) → en service (plan figé, dotations) → sortie (cession ou mise au rebut).
 */
class Asset extends Model
{
    use Syncable;

    protected $table = 'im_assets';

    public static string $syncEntity = 'im_assets';

    public static string $viewPermission = 'immobilisations.view|immobilisations.manage';

    public static string $managePermission = 'immobilisations.manage';

    public static array $searchable = ['code', 'name', 'serial_number', 'location'];

    public const STATUSES = ['draft' => 'Brouillon', 'running' => 'En service', 'disposed' => 'Sortie'];

    /** Modifiables une fois en service. */
    public const LIVE_FIELDS = ['name', 'serial_number', 'location', 'employee_id', 'notes', 'analytic'];

    protected $fillable = [
        'name', 'category_id', 'acquisition_date', 'in_service_date', 'value', 'salvage_value', 'method', 'years', 'coefficient', 'frequency',
        'opening_date', 'serial_number', 'location', 'employee_id', 'partner_id', 'post_acquisition', 'acquisition_account', 'analytic', 'notes',
    ];

    protected $attributes = ['status' => 'draft', 'method' => 'linear', 'years' => 5, 'frequency' => 'annual', 'value' => 0, 'salvage_value' => 0, 'post_acquisition' => false];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date:Y-m-d', 'in_service_date' => 'date:Y-m-d', 'opening_date' => 'date:Y-m-d', 'disposal_date' => 'date:Y-m-d',
            'value' => 'float', 'salvage_value' => 'float', 'coefficient' => 'float', 'years' => 'integer', 'disposal_amount' => 'float',
            'post_acquisition' => 'boolean', 'analytic' => 'array', 'version' => 'integer', 'created_by' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $c = app('tenant')->id;

        return [
            'name' => 'required|string|max:255',
            'category_id' => ['nullable', 'uuid', Rule::exists('im_categories', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'acquisition_date' => 'nullable|date',
            'in_service_date' => 'nullable|date',
            'value' => 'numeric|min:0|max:100000000000',
            'salvage_value' => 'numeric|min:0',
            'method' => 'in:linear,degressive,none',
            'years' => 'integer|min:1|max:100',
            'coefficient' => 'nullable|numeric|min:1|max:4',
            'frequency' => 'in:annual,monthly',
            'opening_date' => 'nullable|date',
            'serial_number' => 'nullable|string|max:120',
            'location' => 'nullable|string|max:120',
            'employee_id' => 'nullable|uuid',
            'partner_id' => 'nullable|uuid',
            'post_acquisition' => 'boolean',
            'acquisition_account' => ['nullable', 'regex:/^[1-9]\d{1,9}$/'],
            'analytic' => 'nullable|array',
            'notes' => 'nullable|string|max:5000',
            'status' => 'sometimes|in:draft,running',
        ];
    }

    public ?string $pendingStatus = null;

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->pendingStatus = $data['status'] ?? null;
        unset($data['status']);
        if (array_key_exists('analytic', $data)) {
            $data['analytic'] = TradeDocument::cleanAnalytic($data['analytic']);
        }
        if (! $creating && $this->status !== 'draft') {
            $locked = array_diff(array_keys(array_intersect_key($data, array_flip($this->fillable))), self::LIVE_FIELDS);
            $changed = array_filter($locked, fn ($k) => (string) ($data[$k] ?? '') !== (string) ($this->{$k} instanceof \DateTimeInterface ? $this->{$k}->format('Y-m-d') : ($this->{$k} ?? '')));
            if ($changed) {
                throw new \DomainException('Immobilisation en service : seuls le nom, le n° de série, l\'emplacement, le responsable, la ventilation et les notes se modifient. Pour corriger le plan, sortez-la puis recréez-la.');
            }
        }
        if ($creating) {
            $this->created_by = auth()->id();
            $cat = ! empty($data['category_id']) ? AssetCategory::find($data['category_id']) : null;
            if ($cat) {
                $data['method'] ??= $cat->method;
                $data['years'] ??= $cat->years;
                $data['coefficient'] ??= $cat->coefficient;
            }
            $data['frequency'] ??= data_get(app('tenant')->settings, 'immo.frequency', 'annual');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        $svc = app(AssetService::class);
        if ($this->pendingStatus === 'running' && $this->status === 'draft') {
            $svc->activate($this);
        } elseif ($this->status !== 'draft' && $this->wasChanged('analytic')) {
            $svc->syncAnalytic($this);
        }
    }

    public function beforeEntityDelete(): void
    {
        if ($this->status !== 'draft') {
            throw new \DomainException('Une immobilisation en service ne se supprime pas : enregistrez sa sortie (cession ou mise au rebut).');
        }
    }

    public function category()
    {
        return $this->belongsTo(AssetCategory::class, 'category_id')->withTrashed();
    }

    public function lines()
    {
        return $this->hasMany(AssetLine::class, 'asset_id')->orderBy('seq');
    }

    public function toSyncArray(): array
    {
        $posted = AssetLine::where('asset_id', $this->id)->whereNotNull('posted_at');
        $depreciated = (float) $posted->sum('amount');
        $next = AssetLine::where('asset_id', $this->id)->whereNull('posted_at')->orderBy('seq')->first(['period_end', 'amount']);

        return $this->toArray() + [
            'depreciated' => round($depreciated, 2),
            'nbv' => round((float) $this->value - $depreciated, 2),
            'next_period_end' => $next?->period_end?->toDateString(),
            'next_amount' => $next?->amount,
        ];
    }
}
