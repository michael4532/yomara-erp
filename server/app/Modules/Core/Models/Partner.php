<?php

namespace App\Modules\Core\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use \App\Models\Concerns\HasCustomFields, Syncable;

    public static string $syncEntity = 'partners';

    public static array $searchable = ['name', 'email', 'phone', 'code'];

    protected $fillable = [
        'type', 'is_company', 'name', 'code', 'email', 'phone', 'address', 'city',
        'country', 'tax_id', 'credit_limit', 'notes', 'active', 'currency', 'dunning_paused',
        'price_list_id', 'payment_term_id', 'supplier_payment_term_id',
        'prospect_status', 'prospect_level', 'source', 'salesperson_id', 'website', 'contacts',
    ];

    /** Statut de prospection (comme Dolibarr) : suivi des prospects jusqu'à leur première facture. */
    public const PROSPECT_STATUSES = ['never' => 'Jamais contacté', 'to_contact' => 'À contacter', 'in_progress' => 'Contact en cours', 'contacted' => 'Contacté', 'do_not_contact' => 'Ne pas contacter'];

    /** Potentiel du prospect. */
    public const PROSPECT_LEVELS = ['none' => 'Aucun', 'low' => 'Faible', 'medium' => 'Moyen', 'high' => 'Élevé'];

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            if (trim((string) $p->code) === '') {
                $p->code = self::nextCode($p->company_id, $p->type ?: 'customer');
            }
            if ($p->type === 'prospect' && ! $p->prospect_status) {
                $p->prospect_status = 'never';
            }
        });
    }

    /** Code automatique à la création, comme Dolibarr : C2611-0001 (client ou prospect), F2611-0001 (fournisseur). */
    public static function nextCode(?int $companyId, string $type): string
    {
        $prefix = ($type === 'supplier' ? 'F' : 'C').now()->format('ym').'-';
        $last = static::withoutGlobalScopes()->withTrashed()->where('company_id', $companyId)->where('code', 'like', $prefix.'%')
            ->pluck('code')->map(fn ($c) => (int) substr((string) $c, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Une action commerciale faite (appel, rendez-vous…) fait passer un prospect pas encore contacté à « Contact en cours ». */
    public static function markContacted(?string $partnerId): void
    {
        if (! $partnerId) {
            return;
        }
        $p = static::find($partnerId);
        if ($p && $p->type === 'prospect' && in_array($p->prospect_status, [null, 'never', 'to_contact'], true)) {
            $p->prospect_status = 'in_progress';
            $p->save();
        }
    }

    protected function casts(): array
    {
        return ['is_company' => 'boolean', 'active' => 'boolean', 'credit_limit' => 'float', 'version' => 'integer', 'dunning_paused' => 'boolean',
            'salesperson_id' => 'integer', 'contacts' => 'array'];
    }

    public static function syncRules(): array
    {
        return [
            'type' => 'sometimes|in:prospect,customer,supplier,both',
            'is_company' => 'sometimes|boolean',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|size:2',
            'tax_id' => 'nullable|string|max:100',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'active' => 'sometimes|boolean',
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'dunning_paused' => 'sometimes|boolean',
            'prospect_status' => 'nullable|in:'.implode(',', array_keys(self::PROSPECT_STATUSES)),
            'prospect_level' => 'nullable|in:'.implode(',', array_keys(self::PROSPECT_LEVELS)),
            'source' => 'nullable|in:'.implode(',', array_keys(\App\Modules\Crm\Models\Lead::SOURCES)),
            'salesperson_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('company_id', app('tenant')->id)],
            'website' => 'nullable|string|max:255',
            'contacts' => 'nullable|array|max:30',
            'contacts.*.name' => 'required|string|max:160',
            'contacts.*.role' => 'nullable|string|max:120',
            'contacts.*.phone' => 'nullable|string|max:50',
            'contacts.*.email' => 'nullable|email|max:255',
            'price_list_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('pl_lists')],
            'payment_term_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
            'supplier_payment_term_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
        ];
    }

    public static string $viewPermission = 'core.contacts.view';

    public static string $managePermission = 'core.contacts.manage';

    /** Droit de suppression (distinct de la modification). */
    public static string $deletePermission = 'core.contacts.delete';
}
