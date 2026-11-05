<?php

namespace App\Modules\Etiquettes\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Dernière impression d'un produit (une ligne par produit) : sert à repérer les prix changés depuis. */
class LabelPrint extends Model
{
    use Syncable;

    protected $table = 'lb_prints';

    public static string $syncEntity = 'lb_prints';

    public static string $viewPermission = 'etiquettes.print|etiquettes.manage';

    public static string $managePermission = 'etiquettes.print|etiquettes.manage';

    protected $fillable = ['product_id', 'price', 'printed_at'];

    protected function casts(): array
    {
        return ['price' => 'float', 'total' => 'integer', 'printed_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', app('tenant')->id)],
            'price' => 'required|numeric|min:0',
            'printed_at' => 'nullable|date',
            'count' => 'nullable|integer|min:0|max:100000',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $this->total = ($this->total ?? 0) + (int) ($data['count'] ?? 0);
        $data['printed_at'] ??= now()->toIso8601String();
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Historique d\'impression : suppression impossible.');
    }
}
