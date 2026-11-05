<?php

namespace App\Modules\Fournisseurs\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Offre d'un fournisseur à un appel d'offres (déposée sur le portail). */
class Bid extends Model
{
    use Syncable;

    protected $table = 'sp_bids';

    public static string $syncEntity = 'sp_bids';

    public static string $viewPermission = 'portail_fournisseurs.view|portail_fournisseurs.manage';

    public static string $managePermission = 'portail_fournisseurs.manage';

    protected $fillable = [];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'total' => 'float', 'delivery_days' => 'integer', 'valid_until' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'revisions' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        throw new \DomainException('Les offres sont déposées par les fournisseurs sur leur portail.');
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une offre ne se supprime pas.');
    }
}
