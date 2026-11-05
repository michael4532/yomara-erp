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
    ];

    protected function casts(): array
    {
        return ['is_company' => 'boolean', 'active' => 'boolean', 'credit_limit' => 'float', 'version' => 'integer', 'dunning_paused' => 'boolean'];
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
            'price_list_id' => 'nullable|uuid',
            'payment_term_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
            'supplier_payment_term_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('payment_terms', 'id')->where('company_id', app('tenant')->id)->whereNull('deleted_at')],
        ];
    }

    public static string $viewPermission = 'core.contacts.view';

    public static string $managePermission = 'core.contacts.manage';

    /** Droit de suppression (distinct de la modification). */
    public static string $deletePermission = 'core.contacts.delete';
}
