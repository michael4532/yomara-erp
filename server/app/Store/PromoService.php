<?php

namespace App\Store;

use App\Models\Company;
use App\Models\ModuleOrder;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Codes promo : vérification, calcul de la remise et enregistrement de l'utilisation. */
class PromoService
{
    public static function normalize(?string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $code));
    }

    /**
     * @param  string  $scope  plan | module (les packs comptent comme modules)
     * @param  string  $target  code de la formule ou clé du module / pack
     * @param  string  $kind  new | renew
     * @return array{promo: PromoCode, discount: float}
     */
    public function check(string $code, Company $company, User $user, string $scope, string $target, string $kind, float $amount): array
    {
        $promo = PromoCode::where('code', self::normalize($code))->first();
        if (! $promo || ! $promo->isValidNow()) {
            throw new \DomainException($promo && $promo->valid_until?->isPast() ? 'Ce code promo a expiré.' : 'Code promo invalide ou expiré.');
        }
        if ($promo->applies_to !== 'all' && $promo->applies_to !== $scope) {
            throw new \DomainException($promo->applies_to === 'plan' ? 'Ce code s\'applique aux formules d\'abonnement.' : 'Ce code s\'applique aux modules.');
        }
        if ($promo->target && $promo->target !== $target && ! ($scope === 'module' && 'pack:'.$promo->target === $target)) {
            throw new \DomainException('Ce code ne s\'applique pas à cet achat.');
        }
        if ($promo->usage !== 'any' && $promo->usage !== $kind) {
            throw new \DomainException($promo->usage === 'new' ? 'Ce code est réservé aux nouveaux abonnements.' : 'Ce code est réservé aux renouvellements.');
        }
        // Utilisations réservées par des commandes en attente : comptent pour le plafond
        if ($promo->max_uses && $promo->uses + ModuleOrder::where('promo_code_id', $promo->id)->whereIn('status', ['pending', 'awaiting_validation'])->count() >= $promo->max_uses) {
            throw new \DomainException('Ce code promo a atteint son nombre d\'utilisations.');
        }
        if ($promo->once_per_user) {
            // Une fois par client : par personne et par société (un collègue ne peut pas le réutiliser)
            $used = DB::table('promo_redemptions')->where('promo_code_id', $promo->id)->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('company_id', $company->id))->exists()
                || ModuleOrder::where('promo_code_id', $promo->id)->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('company_id', $company->id))->whereIn('status', ['pending', 'awaiting_validation'])->exists();
            if ($used) {
                throw new \DomainException('Vous avez déjà utilisé ce code promo.');
            }
        }
        $discount = $promo->type === 'percent' ? round($amount * min(100, $promo->value) / 100) : min($amount, $promo->value);

        return ['promo' => $promo, 'discount' => max(0.0, (float) $discount)];
    }

    /** Commande payée : l'utilisation est comptée. */
    public function redeem(ModuleOrder $order): void
    {
        if (! $order->promo_code_id || DB::table('promo_redemptions')->where('order_id', $order->id)->exists()) {
            return;
        }
        DB::table('promo_redemptions')->insert([
            'promo_code_id' => $order->promo_code_id, 'user_id' => $order->user_id ?? 0, 'company_id' => $order->company_id,
            'order_id' => $order->id, 'discount' => $order->discount, 'created_at' => now(),
        ]);
        PromoCode::where('id', $order->promo_code_id)->increment('uses');
    }

    /** Génère des codes uniques (ex. YOM-7KQ2-9X). */
    public static function generate(string $prefix = 'YOM'): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $s = '';
            for ($i = 0; $i < 8; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = strtoupper($prefix).'-'.substr($s, 0, 4).'-'.substr($s, 4);
        } while (PromoCode::where('code', $code)->exists());

        return $code;
    }
}
