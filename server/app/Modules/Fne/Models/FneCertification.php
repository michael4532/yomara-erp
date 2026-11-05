<?php

namespace App\Modules\Fne\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;

/**
 * Certification FNE d'un document (facture, ticket, avoir, bordereau d'achat).
 * Écrite uniquement par le serveur ; synchronisée en lecture pour afficher
 * le QR code et le statut, y compris hors ligne.
 */
class FneCertification extends Model
{
    use Syncable;

    protected $table = 'fne_certifications';

    public static string $syncEntity = 'fne_certifications';

    public static string $viewPermission = 'ventes.view|achats.view|pos.use|fne.view';

    public static string $managePermission = 'fne.manage';

    protected $fillable = [];

    protected $hidden = ['company_id', 'payload', 'response', 'deleted_at'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0, 'invoice_type' => 'sale'];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'payload' => 'array', 'response' => 'array',
            'amount' => 'float', 'vat_amount' => 'float', 'attempts' => 'integer', 'last_status' => 'integer',
            'sticker_balance' => 'integer', 'next_attempt_at' => 'datetime', 'certified_at' => 'datetime', 'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        return [];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        throw new \DomainException('Les certifications FNE sont gérées par le serveur.');
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une certification FNE ne peut pas être supprimée.');
    }
}
