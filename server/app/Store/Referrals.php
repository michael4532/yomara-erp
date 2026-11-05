<?php

namespace App\Store;

use App\Models\Company;
use App\Models\ModuleOrder;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Parrainage : le parrain gagne un crédit (en % des achats de ses filleuls) utilisable sur ses propres achats ;
 * le filleul obtient une remise sur son premier achat.
 */
class Referrals
{
    public static function settings(): array
    {
        return array_replace(['enabled' => true, 'reward_percent' => 10, 'referee_discount' => 10, 'reward_months' => 12],
            (array) PlatformSetting::get('referral.settings', []));
    }

    public static function code(Company $c): string
    {
        if (! $c->referral_code) {
            do {
                $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', \Illuminate\Support\Str::ascii($c->name)) ?: 'YOM', 0, 4)).random_int(1000, 9999);
            } while (Company::withoutGlobalScope('real')->where('referral_code', $code)->exists());
            $c->forceFill(['referral_code' => $code])->saveQuietly();
        }

        return $c->referral_code;
    }

    public static function link(Company $c): string
    {
        return rtrim(config('app.url'), '/').'/inscription?parrain='.self::code($c);
    }

    /** À l'inscription : rattache le filleul à son parrain. */
    public static function attach(Company $new, ?string $code): void
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || ! self::settings()['enabled']) {
            return;
        }
        $referrer = Company::where('referral_code', $code)->where('id', '!=', $new->id)->first();
        // Auto-parrainage évident (même téléphone ou même e-mail que la société marraine) : ignoré
        $digits = fn ($v) => substr(preg_replace('/\D/', '', (string) $v), -8);
        if ($referrer && (($digits($new->phone) !== '' && $digits($new->phone) === $digits($referrer->phone))
            || ($new->email && strcasecmp((string) $new->email, (string) $referrer->email) === 0))) {
            return;
        }
        if ($referrer) {
            $new->forceFill(['referred_by' => $referrer->id])->saveQuietly();
        }
    }

    /** Remise de bienvenue du filleul : sur son tout premier achat payant. */
    public static function refereeDiscount(Company $c, float $amount): float
    {
        $s = self::settings();
        if (! $c->referred_by || ! $s['enabled'] || $s['referee_discount'] <= 0 || $amount <= 0) {
            return 0.0;
        }
        if (ModuleOrder::where('company_id', $c->id)->where('status', 'paid')->where('amount', '>', 0)->exists()) {
            return 0.0;
        }

        return round($amount * $s['referee_discount'] / 100);
    }

    /** Crédit utilisable (moins ce qui est déjà réservé par des commandes en attente). */
    public static function available(Company $c): float
    {
        $reserved = (float) ModuleOrder::where('company_id', $c->id)->whereIn('status', ['pending', 'awaiting_validation'])->sum('credit_used');

        return max(0.0, (float) $c->referral_credit - $reserved);
    }

    /** Commande payée : débite le crédit utilisé et récompense le parrain. */
    public static function onPaid(ModuleOrder $order): void
    {
        $c = Company::withoutGlobalScope('real')->find($order->company_id);
        if (! $c) {
            return;
        }
        DB::transaction(function () use ($order, $c) {
            if ($order->credit_used > 0 && ! DB::table('referral_rewards')->where('order_id', $order->id)->where('kind', 'spent')->exists()) {
                $fresh = (float) Company::withoutGlobalScope('real')->where('id', $c->id)->value('referral_credit');
                Company::withoutGlobalScope('real')->where('id', $c->id)->update(['referral_credit' => max(0, $fresh - (float) $order->credit_used)]);
                DB::table('referral_rewards')->insert(['referrer_id' => $c->id, 'referee_id' => $c->id, 'order_id' => $order->id, 'amount' => $order->credit_used, 'kind' => 'spent', 'created_at' => now()]);
            }
            $s = self::settings();
            if (! $c->referred_by || ! $s['enabled'] || $order->amount <= 0 || DB::table('referral_rewards')->where('order_id', $order->id)->where('kind', 'earned')->exists()) {
                return;
            }
            // Récompense pendant N mois après l'inscription du filleul
            if ($s['reward_months'] > 0 && $c->created_at && $c->created_at->lt(now()->subMonths((int) $s['reward_months']))) {
                return;
            }
            $reward = round($order->amount * $s['reward_percent'] / 100);
            if ($reward <= 0) {
                return;
            }
            Company::withoutGlobalScope('real')->where('id', $c->referred_by)->increment('referral_credit', $reward);
            DB::table('referral_rewards')->insert(['referrer_id' => $c->referred_by, 'referee_id' => $c->id, 'order_id' => $order->id, 'amount' => $reward, 'kind' => 'earned', 'created_at' => now()]);
            $admins = User::where('company_id', $c->referred_by)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('core.settings.manage'));
            rescue(fn () => \App\Models\AppNotification::send((int) $c->referred_by, $admins->pluck('id')->all(), 'store', 'Parrainage : +'.number_format($reward, 0, ',', ' ').' F de crédit',
                $c->name.' vient d\'acheter dans la boutique. Votre crédit est utilisable sur vos prochains achats.', 'referral'), null, false);
        });
    }

    public static function summary(Company $c): array
    {
        $refs = Company::where('referred_by', $c->id)->latest('id')->get(['id', 'name', 'created_at', 'status']);
        $earned = DB::table('referral_rewards')->where('referrer_id', $c->id)->where('kind', 'earned');
        $byReferee = (clone $earned)->selectRaw('referee_id, sum(amount) as total')->groupBy('referee_id')->pluck('total', 'referee_id');

        return [
            'code' => self::code($c),
            'link' => self::link($c),
            'settings' => self::settings(),
            'credit' => round((float) $c->referral_credit, 2),
            'available' => round(self::available($c), 2),
            'earned_total' => round((float) (clone $earned)->sum('amount'), 2),
            'referees' => $refs->count(),
            'paying' => $byReferee->count(),
            'list' => $refs->map(fn ($r) => ['name' => $r->name, 'joined_at' => $r->created_at?->toIso8601String(), 'earned' => round((float) ($byReferee[$r->id] ?? 0), 2)])->values(),
            'history' => DB::table('referral_rewards')->where('referrer_id', $c->id)->latest('id')->limit(50)->get(['amount', 'kind', 'referee_id', 'created_at'])
                ->map(fn ($h) => ['amount' => (float) $h->amount, 'kind' => $h->kind, 'at' => $h->created_at])->values(),
        ];
    }
}
