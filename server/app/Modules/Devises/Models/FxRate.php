<?php

namespace App\Modules\Devises\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Cours d'une devise à une date : 1 unité = rate × devise de la société. */
class FxRate extends Model
{
    use Syncable;

    protected $table = 'fx_rates';

    public static string $syncEntity = 'fx_rates';

    public static string $viewPermission = 'ventes.view|achats.view|devises.manage|compta.view|pos.use';

    public static string $managePermission = 'devises.manage';

    protected $fillable = ['currency', 'date', 'rate', 'note'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'rate' => 'float', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'date' => 'required|date',
            'rate' => 'required|numeric|gt:0|max:1000000000',
            'note' => 'nullable|string|max:255',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $cur = strtoupper($data['currency'] ?? $this->currency);
        if ($cur === strtoupper(app('tenant')->currency)) {
            throw new \DomainException('Inutile de saisir un cours pour la devise de la société.');
        }
        if (\App\Modules\Devises\FxService::fixedRate(app('tenant')->currency, $cur) !== null) {
            throw new \DomainException('La parité '.$cur.' / '.app('tenant')->currency.' est fixe : aucun cours à saisir.');
        }
    }
}
