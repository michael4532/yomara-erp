<?php

namespace App\Modules\Fidelite\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Mouvement de points : journal en ajout seulement. */
class LoyaltyTransaction extends Model
{
    use Syncable;

    protected $table = 'lc_transactions';

    public static string $syncEntity = 'lc_transactions';

    public static string $viewPermission = 'fidelite.view|fidelite.manage|pos.use|ventes.view';

    public static string $managePermission = 'fidelite.manage';

    public const TYPES = ['earn' => 'Achat', 'redeem' => 'Utilisation', 'bonus' => 'Bonus', 'adjust' => 'Ajustement', 'expire' => 'Expiration', 'reverse' => 'Annulation'];

    protected $fillable = ['card_id', 'type', 'points', 'note'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'remaining' => 'integer', 'amount' => 'float', 'expires_at' => 'date:Y-m-d', 'user_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'card_id' => ['required', 'uuid', Rule::exists('lc_cards', 'id')->where('company_id', $company)],
            'type' => 'required|in:bonus,adjust',
            'points' => 'required|integer|not_in:0|between:-1000000,1000000',
            'note' => 'required|string|max:255',
        ];
    }

    /** Saisie manuelle (bonus, correction) : appliquée par le service, jamais modifiée ensuite. */
    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (! $creating) {
            throw new \DomainException('Un mouvement de points ne se modifie pas : faites un ajustement.');
        }
        $card = LoyaltyCard::find($data['card_id']);
        if ((int) $data['points'] < 0 && $card && $card->points + (int) $data['points'] < 0) {
            throw new \DomainException('Le solde de la carte ne peut pas devenir négatif ('.$card->points.' points).');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        app(\App\Modules\Fidelite\LoyaltyService::class)->applyManual($this);
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Un mouvement de points ne se supprime pas.');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $t) => $t->user_id ??= auth()->id());
    }
}
