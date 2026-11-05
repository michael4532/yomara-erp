<?php

namespace App\Modules\Fournisseurs\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/** Réponse du fournisseur à un bon de commande : acceptée (avec date de livraison) ou refusée. */
class OrderAck extends Model
{
    use Syncable;

    protected $table = 'sp_order_acks';

    public static string $syncEntity = 'sp_order_acks';

    public static string $viewPermission = 'portail_fournisseurs.view|portail_fournisseurs.manage|achats.view';

    public static string $managePermission = 'portail_fournisseurs.manage';

    protected $fillable = [];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['delivery_date' => 'date:Y-m-d', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        throw new \DomainException('Le fournisseur répond depuis son portail.');
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une réponse du fournisseur ne se supprime pas.');
    }
}
