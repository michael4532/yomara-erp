<?php

namespace App\Modules\Fidelite\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Carte de fidélité d'un client (une par client). Le solde est tenu par le serveur. */
class LoyaltyCard extends Model
{
    use Syncable;

    protected $table = 'lc_cards';

    public static string $syncEntity = 'lc_cards';

    public static string $viewPermission = 'fidelite.view|fidelite.manage|pos.use|ventes.view';

    /** La caisse peut inscrire un client sur place. */
    public static string $managePermission = 'fidelite.manage|pos.use|ventes.manage';

    public static array $searchable = ['number'];

    protected $fillable = ['partner_id', 'number', 'status'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'earned_12m' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'number' => 'nullable|string|max:40',
            'status' => 'nullable|in:active,blocked',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        if ($creating) {
            if (self::where('partner_id', $data['partner_id'])->exists()) {
                throw new \DomainException('Ce client a déjà une carte de fidélité.');
            }
            if (! empty($data['number']) && self::where('number', $data['number'])->exists()) {
                throw new \DomainException('Ce numéro de carte est déjà utilisé.');
            }
        } else {
            unset($data['partner_id']);
            if ($user && ! $user->hasPermission('fidelite.manage')) {
                $data = []; // la caisse inscrit, le responsable bloque ou change le numéro
            }
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if ($creating) {
            app(\App\Modules\Fidelite\LoyaltyService::class)->welcome($this);
        }
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une carte ne se supprime pas : bloquez-la.');
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->number = $c->number ?: Sequence::next($c->company_id, 'loyalty_card', 'FID');
            $c->status ??= 'active';
        });
    }
}
