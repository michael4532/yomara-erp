<?php

namespace App\Platform;

use App\Support\Tr;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Module;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Cycle de vie de l'abonnement côté client : offre d'essai réglable, accueil avec les modules offerts,
 * rappels avant la fin de l'essai ou de l'abonnement, puis modules désactivés à l'échéance.
 * Chaque étape : e-mail + notification dans le logiciel et l'application + bandeau.
 */
class SubscriptionLifecycle
{
    public const STEPS = [7, 3, 1, 0];

    // ------------------------------------------------------------------ offre d'essai (réglée par l'éditeur)

    public static function offer(): array
    {
        $o = (array) PlatformSetting::get('offer.trial', []);
        $standard = (int) ($o['standard_days'] ?? config('erp.trial_days', 14));
        $promoDays = (int) ($o['promo_days'] ?? 0);
        $until = ! empty($o['promo_until']) ? Carbon::parse($o['promo_until'])->endOfDay() : null;
        $promo = $promoDays > 0 && (! $until || $until->isFuture()) && (empty($o['promo_from']) || Carbon::parse($o['promo_from'])->startOfDay()->isPast());

        return [
            'standard_days' => $standard, 'promo_days' => $promoDays, 'promo_from' => $o['promo_from'] ?? null, 'promo_until' => $o['promo_until'] ?? null,
            'promo_label' => $o['promo_label'] ?? null, 'promo_active' => $promo, 'days' => $promo ? $promoDays : $standard,
        ];
    }

    /** Nombre de jours d'essai accordés à une inscription faite maintenant. */
    public static function trialDays(): int
    {
        return max(0, self::offer()['days']);
    }

    public static function saveOffer(array $d): array
    {
        PlatformSetting::put('offer.trial', array_filter([
            'standard_days' => (int) $d['standard_days'], 'promo_days' => (int) ($d['promo_days'] ?? 0),
            'promo_from' => $d['promo_from'] ?? null, 'promo_until' => $d['promo_until'] ?? null, 'promo_label' => $d['promo_label'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        return self::offer();
    }

    // ------------------------------------------------------------------ modules

    /** Modules (hors base) compris dans une formule : leurs noms. */
    public static function planModules(?Plan $plan, bool $lost = false): array
    {
        // $lost : ce qui disparaît à la fin de l'essai ou de l'abonnement (le socle gratuit, lui, reste)
        $keys = ! $plan ? [] : ($lost ? array_values(array_diff((array) ($plan->modules ?? []), Plan::socle())) : $plan->effectiveModules());

        return $keys ? Module::whereIn('key', $keys)->where('is_core', false)->where('is_active', true)->orderBy('sort')->pluck('name')->all() : [];
    }

    protected static function managers(Company $c)
    {
        return User::withoutGlobalScopes()->where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('core.settings.manage'));
    }

    /**
     * Notification (dans l'app) et e-mail aux gestionnaires, chacun dans sa langue.
     * $title, $body et $cta sont des gabarits : ['Votre formule {} expire le {}.', 'Starter', '12/11/2026'].
     */
    protected static function send(Company $c, array $title, array $body, array $modules = [], array $cta = ['Renouveler mon abonnement']): void
    {
        $users = self::managers($c);
        $L = fn (array $t, string $loc) => Tr::f($t[0], array_slice($t, 1), $loc);
        $mods = fn (string $loc) => array_map(fn ($m) => Tr::exactOrSelf($m, $loc), $modules);
        $fr = $mods('fr');
        rescue(fn () => AppNotification::send($c->id, $users->pluck('id'), 'subscription', $L($title, 'fr'), $L($body, 'fr').($fr ? ' Modules : '.implode(', ', array_slice($fr, 0, 12)).(count($fr) > 12 ? '…' : '').'.' : ''), 'store', 'subscription'), null, false);
        // destinataires et leur langue (l'adresse de la société suit la langue de son premier gestionnaire)
        $to = [];
        foreach ($users as $u) {
            if ($u->email) {
                $to[mb_strtolower(trim($u->email))] ??= in_array($u->locale, Tr::LOCALES, true) ? $u->locale : 'fr';
            }
        }
        if ($c->email) {
            $to[mb_strtolower(trim($c->email))] ??= $users->first()?->locale ?: 'fr';
        }
        foreach (array_slice($to, 0, 5, true) as $email => $loc) {
            $t = $L($title, $loc);
            $b = $L($body, $loc);
            $m = $mods($loc);
            $list = $m ? '<p style="margin:16px 0 8px;font-weight:700">'.e(Tr::exactOrSelf(count($m) > 1 ? 'Modules concernés' : 'Module concerné', $loc)).' :</p><p style="margin:0;line-height:1.9">'
                .collect($m)->map(fn ($x) => '<span style="display:inline-block;background:#F1F4FA;border-radius:999px;padding:3px 11px;margin:2px;font-size:13.5px">'.e($x).'</span>')->implode(' ').'</p>' : '';
            $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1530;max-width:580px;margin:0 auto;padding:24px">'
                .'<div style="background:#0A1633;color:#fff;border-radius:16px 16px 0 0;padding:22px 26px"><div style="color:#FFC531;font-weight:700;font-size:13px">'.e(config('erp.name', 'Yomara ERP')).'</div><div style="font-size:21px;font-weight:800;margin-top:4px">'.e($t).'</div></div>'
                .'<div style="background:#fff;border:1px solid #E5E9F2;border-top:0;border-radius:0 0 16px 16px;padding:22px 26px"><p style="line-height:1.6;margin:0">'.e(Tr::exactOrSelf('Bonjour,', $loc)).'</p><p style="line-height:1.6">'.e($b).'</p>'.$list
                .'<p style="margin:22px 0 0"><a href="'.e(url('/app/?ecran=store')).'" style="display:inline-block;background:#2E6BE6;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:10px">'.e($L($cta, $loc)).'</a></p></div></div>';
            rescue(fn () => ClientComms::mail($email, null, $t, $html, $t."\n\n".$b), null, false);
        }
    }

    // ------------------------------------------------------------------ étapes

    /** Inscription : bienvenue avec la durée de l'essai et les modules offerts (la notification ; l'e-mail de bienvenue les liste aussi). */
    public static function welcome(Company $c): void
    {
        $mods = self::planModules($c->plan);
        $body = $c->status === 'trial' && $c->trial_ends_at
            ? 'Votre essai gratuit de la formule '.$c->plan?->name.' est actif jusqu\'au '.$c->trial_ends_at->format('d/m/Y').' : tous ces modules sont à vous pendant ce temps.'
            : 'Votre espace est prêt avec la formule '.($c->plan?->name ?? 'de base').'.';
        rescue(fn () => AppNotification::send($c->id, self::managers($c)->pluck('id'), 'subscription', 'Bienvenue sur '.config('erp.name', 'Yomara ERP').' !',
            $body.($mods ? ' Modules inclus : '.implode(', ', array_slice($mods, 0, 15)).(count($mods) > 15 ? '…' : '').'.' : ''), 'store', 'subscription'), null, false);
    }

    /** Rappels quotidiens : 7, 3, 1 jour(s) avant puis le jour de la fin de l'essai ou de l'abonnement. */
    public static function remind(): int
    {
        $n = 0;
        $q = Company::query()->where(fn ($w) => $w->where(fn ($t) => $t->where('status', 'trial')->whereNotNull('trial_ends_at'))
            ->orWhere(fn ($a) => $a->where('status', 'active')->whereNotNull('subscription_ends_at')));
        foreach ($q->get() as $c) {
            $trial = $c->status === 'trial';
            $end = $trial ? $c->trial_ends_at : $c->subscription_ends_at;
            if (! $end || $end->lt(now()->subDay())) {
                continue;
            }
            $days = (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false);
            $step = collect(self::STEPS)->first(fn ($s) => $days <= $s);
            if ($step === null) {
                continue;
            }
            $key = ($trial ? 'trial' : 'sub').':'.$end->toDateString().':'.$step;
            $settings = $c->settings ?? [];
            if (($settings['sub_notice'] ?? null) === $key) {
                continue;
            }
            $mods = self::planModules($c->plan, true);
            $title = match (true) {
                $days <= 0 => [$trial ? 'Votre essai gratuit se termine aujourd\'hui' : 'Votre abonnement expire aujourd\'hui'],
                $days === 1 => [$trial ? 'Votre essai gratuit se termine demain' : 'Votre abonnement expire demain'],
                default => [$trial ? 'Votre essai gratuit se termine dans {} jours' : 'Votre abonnement expire dans {} jours', $days],
            };
            $body = $trial
                ? ['L\'essai de la formule {} se termine le {}. Choisissez une formule pour garder vos modules : sinon votre espace repassera sur la formule gratuite, et vos données resteront conservées.', (string) $c->plan?->name, $end->format('d/m/Y')]
                : ['Votre formule {} expire le {}. Renouvelez-la pour ne perdre aucun module : la nouvelle période démarre à la fin de l\'actuelle.', (string) $c->plan?->name, $end->format('d/m/Y')];
            self::send($c, $title, $body, $mods, [$trial ? 'Choisir ma formule' : 'Renouveler mon abonnement']);
            $settings['sub_notice'] = $key;
            $c->forceFill(['settings' => $settings])->saveQuietly();
            $n++;
        }

        return $n;
    }

    /** Essai ou abonnement échu : liste des modules désactivés (e-mail, notification, bandeau 30 jours). */
    public static function downgraded(Company $c, ?Plan $from, ?Plan $to, string $reason): void
    {
        $lost = array_values(array_diff((array) ($from?->modules ?? []), (array) ($to?->modules ?? [])));
        // Modules achetés à part et encore valides : ils restent actifs
        $kept = \App\Models\CompanyModule::where('company_id', $c->id)->where('enabled', true)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->pluck('module_key')->all();
        $lost = array_values(array_diff($lost, $kept));
        $names = $lost ? Module::whereIn('key', $lost)->where('is_core', false)->orderBy('sort')->pluck('name')->all() : [];
        $settings = $c->settings ?? [];
        $settings['sub_expired'] = ['plan' => $from?->name, 'to' => $to?->name, 'modules' => $names, 'reason' => $reason, 'at' => now()->toIso8601String()];
        $c->forceFill(['settings' => $settings])->saveQuietly();
        self::send($c, [$reason === 'trial' ? 'Votre essai gratuit est terminé' : 'Votre abonnement a expiré'],
            [($reason === 'trial' ? 'L\'essai de la formule {} est terminé.' : 'Votre formule {} n\'a pas été renouvelée.')
                .' Votre espace est passé sur la formule {} : vos données sont conservées, mais {} module(s) sont désactivés. Ils se réactivent dès que vous choisissez une formule.',
                (string) $from?->name, (string) ($to?->name ?? 'Gratuit'), count($names) ?: '…'],
            $names, ['Réactiver mes modules']);
    }

    /** Bandeau du logiciel et de l'application. */
    public static function banner(Company $c): ?array
    {
        if (config('erp.mode') !== 'saas') {
            return null;
        }
        $exp = $c->settings['sub_expired'] ?? null;
        if ($exp && Carbon::parse($exp['at'])->gt(now()->subDays(30)) && ! ($c->status === 'active' && $c->subscription_ends_at?->isFuture())) {
            return ['kind' => 'expired', 'plan' => $exp['plan'] ?? null, 'modules' => $exp['modules'] ?? [], 'reason' => $exp['reason'] ?? null, 'at' => $exp['at']];
        }
        $trial = $c->status === 'trial';
        $end = $trial ? $c->trial_ends_at : ($c->status === 'active' ? $c->subscription_ends_at : null);
        if (! $end) {
            return null;
        }
        $days = (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false);
        if ($days > ($trial ? 14 : 7)) {
            return $trial ? ['kind' => 'trial', 'days_left' => $days, 'ends_at' => $end->toIso8601String(), 'plan' => $c->plan?->name, 'quiet' => true] : null;
        }

        return ['kind' => $trial ? 'trial' : 'expiring', 'days_left' => max(0, $days), 'ends_at' => $end->toIso8601String(), 'plan' => $c->plan?->name, 'modules' => self::planModules($c->plan)];
    }
}
