<?php

namespace App\Modules\Social;

use App\Models\Company;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;

/**
 * Crédits de publication X (plateforme en ligne) : X facture chaque publication à l'éditeur,
 * donc chaque société dispose d'un nombre de crédits par mois (crédits inclus + packs achetés).
 * Une publication coûte 1 crédit, ou davantage si elle contient un lien (X la facture bien plus cher).
 */
class XCredits
{
    /** Packs mensuels vendus dans la boutique : module => crédits par mois. */
    public const PACKS = ['credits_x_50' => 50, 'credits_x_200' => 200, 'credits_x_1000' => 1000];

    public const DEFAULT_INCLUDED = 10;

    public const DEFAULT_LINK_WEIGHT = 10;

    /** Les crédits ne s'appliquent qu'à la plateforme en ligne : une installation privée utilise sa propre application X. */
    public static function enabled(): bool
    {
        return config('erp.mode') === 'saas';
    }

    public static function settings(): array
    {
        $x = (array) (((array) PlatformSetting::get('social.apps', []))['x'] ?? []);

        return [
            'included' => isset($x['included_credits']) ? max(0, (int) $x['included_credits']) : self::DEFAULT_INCLUDED,
            'link_weight' => isset($x['link_weight']) ? max(1, (int) $x['link_weight']) : self::DEFAULT_LINK_WEIGHT,
        ];
    }

    /** Crédits demandés par une publication X selon son texte final (lien compris). */
    public static function cost(string $text, ?string $link = null): int
    {
        $hasLink = ($link && trim($link) !== '') || preg_match('~https?://|www\.[a-z0-9-]+\.[a-z]~i', $text);

        return $hasLink ? self::settings()['link_weight'] : 1;
    }

    public static function packCredits(Company $c): int
    {
        $n = 0;
        foreach ($c->moduleOverrides()->whereIn('module_key', array_keys(self::PACKS))->get() as $o) {
            if ($o->isEffective()) {
                $n += self::PACKS[$o->module_key];
            }
        }

        return $n;
    }

    public static function used(Company $c): int
    {
        $q = fn () => DB::table('so_targets')->where('company_id', $c->id)->where('network', 'x');

        // publiées ce mois-ci (les anciennes publications sans coût enregistré comptent pour 1), plus celles en cours d'envoi
        return (int) $q()->where('status', 'published')->where('published_at', '>=', now()->startOfMonth())->sum(DB::raw('COALESCE(credits, 1)'))
            + (int) $q()->where('status', 'publishing')->sum('credits');
    }

    /** Solde du mois pour l'écran Réseaux sociaux. */
    public static function summary(Company $c): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $s = self::settings();
        $quota = $s['included'] + self::packCredits($c);
        $used = self::used($c);

        return ['quota' => $quota, 'included' => $s['included'], 'packs' => $quota - $s['included'], 'used' => $used, 'left' => max(0, $quota - $used),
            'link_weight' => $s['link_weight'], 'resets_at' => now()->startOfMonth()->addMonth()->toDateString()];
    }

    /** Message d'erreur si la société n'a plus assez de crédits pour cette publication, sinon null. */
    public static function shortage(Company $c, int $cost): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $sum = self::summary($c);
        if ($cost <= $sum['left']) {
            return null;
        }
        $why = $cost > 1 ? ' (une publication avec un lien compte pour '.$cost.' crédits)' : '';

        return 'Crédits X insuffisants : il vous reste '.$sum['left'].' crédit(s) ce mois-ci et cette publication en demande '.$cost.$why
            .'. Ajoutez un pack « Crédits X » dans la Boutique de modules, ou publiez sans lien.';
    }
}
