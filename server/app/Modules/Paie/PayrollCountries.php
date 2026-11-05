<?php

namespace App\Modules\Paie;

use App\Models\Company;

/**
 * Profils de paie par pays (countries/*.json) et profil propre à la société.
 *
 * Réglage société : paie.country (CI par défaut — moteur ivoirien dédié) ;
 * paie.profiles[CODE] : copie modifiée d'un profil (taux mis à jour, convention collective…) ;
 * le code « XX » désigne un profil libre entièrement paramétré par la société.
 */
class PayrollCountries
{
    public const CUSTOM = 'XX';

    /** Zones monétaires pour l'affichage. */
    public const ZONES = [
        'UEMOA' => ['CI', 'SN', 'BF', 'ML', 'TG', 'BJ', 'NE', 'GW'],
        'CEMAC' => ['CM', 'GA', 'CG', 'TD', 'CF', 'GQ'],
    ];

    protected static ?array $cache = null;

    /** @return array<string, array> profils intégrés indexés par code */
    public static function builtIn(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (glob(__DIR__.'/countries/*.json') ?: [] as $f) {
                $p = json_decode((string) file_get_contents($f), true);
                if (is_array($p) && ! empty($p['code'])) {
                    self::$cache[$p['code']] = $p;
                }
            }
            ksort(self::$cache);
        }

        return self::$cache;
    }

    public static function zoneOf(string $code): ?string
    {
        foreach (self::ZONES as $z => $codes) {
            if (in_array($code, $codes, true)) {
                return $z;
            }
        }

        return null;
    }

    /** Liste pour le choix du pays. */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::builtIn() as $code => $p) {
            $out[] = ['code' => $code, 'name' => $p['name'], 'currency' => $p['currency'] ?? null, 'zone' => self::zoneOf($code),
                'as_of' => $p['as_of'] ?? null, 'confidence' => $p['confidence'] ?? null, 'income_tax' => $p['income_tax']['label'] ?? null];
        }
        $out[] = ['code' => self::CUSTOM, 'name' => 'Profil libre (paramétrable)', 'currency' => null, 'zone' => null, 'as_of' => null, 'confidence' => null, 'income_tax' => null];

        return $out;
    }

    public static function countryOf(Company $company): string
    {
        $c = data_get($company->settings, 'paie.country');
        if ($c) {
            return strtoupper($c);
        }
        // Société hors Côte d'Ivoire : le pays de la fiche société s'il a un profil
        $country = strtoupper((string) ($company->country ?? 'CI'));

        return isset(self::builtIn()[$country]) ? $country : 'CI';
    }

    /** Profil effectif de la société pour un code (copie modifiée ou profil intégré). */
    public static function profile(Company $company, ?string $code = null): array
    {
        $code = strtoupper($code ?? self::countryOf($company));
        $saved = data_get($company->settings, 'paie.profiles.'.$code);
        if (is_array($saved) && $saved) {
            return ['code' => $code, 'customized' => true] + $saved;
        }
        if ($code === self::CUSTOM) {
            // Profil libre : part d'un modèle simple (à compléter par la société)
            return ['code' => self::CUSTOM, 'customized' => false] + self::template();
        }

        return ['customized' => false] + (self::builtIn()[$code] ?? self::builtIn()['CI'] ?? self::template());
    }

    public static function template(): array
    {
        return [
            'code' => self::CUSTOM, 'name' => 'Profil libre', 'currency' => 'XOF', 'smig' => 0, 'days_basis' => 30, 'transport_exempt' => null,
            'seniority' => ['start_years' => 2, 'first_rate' => 2, 'per_year' => 1, 'max' => 25],
            'contributions' => [['code' => 'retraite', 'label' => 'Retraite', 'employee_rate' => 0, 'employer_rate' => 0, 'ceiling' => null]],
            'fixed' => [],
            'income_tax' => ['code' => 'impot', 'label' => 'Impôt sur salaire', 'period' => 'monthly', 'deduct_social' => true, 'professional' => null, 'fixed_abatement' => 0,
                'brackets' => [['up_to' => null, 'rate' => 0]], 'family' => ['method' => 'none']],
            'employee_taxes' => [],
            'employer_taxes' => [],
            'notes' => 'Profil à compléter : cotisations, barème de l\'impôt et taxes patronales.',
        ];
    }

    /** Validation d'un profil envoyé par l'application. */
    public static function validate(array $p): ?string
    {
        foreach (['contributions', 'fixed', 'employee_taxes', 'employer_taxes'] as $k) {
            if (isset($p[$k]) && ! is_array($p[$k])) {
                return "Section $k invalide.";
            }
        }
        $check = function (array $brackets, string $what) {
            $prev = -1;
            foreach (array_values($brackets) as $i => $t) {
                $last = $i === count($brackets) - 1;
                $up = $t['up_to'] ?? null;
                if (! isset($t['fixed']) && (! isset($t['rate']) || ! is_numeric($t['rate']) || $t['rate'] < 0 || $t['rate'] > 100)) {
                    return "Taux invalide dans $what.";
                }
                if ($up === null && ! $last) {
                    return "Seule la dernière tranche de $what peut être sans plafond.";
                }
                if ($up !== null && $up <= $prev) {
                    return "Les tranches de $what doivent être croissantes.";
                }
                $prev = $up ?? INF;
            }

            return null;
        };
        if ($err = $check((array) ($p['income_tax']['brackets'] ?? []), 'l\'impôt')) {
            return $err;
        }
        foreach ((array) ($p['contributions'] ?? []) as $c) {
            if (empty($c['code']) || empty($c['label'])) {
                return 'Chaque cotisation doit avoir un code et un libellé.';
            }
            foreach (['employee_rate', 'employer_rate'] as $f) {
                if (isset($c[$f]) && (! is_numeric($c[$f]) || $c[$f] < 0 || $c[$f] > 100)) {
                    return 'Taux de cotisation invalide ('.$c['label'].').';
                }
            }
        }
        foreach (['employee_taxes', 'employer_taxes', 'fixed'] as $k) {
            foreach ((array) ($p[$k] ?? []) as $t) {
                if (empty($t['code']) || empty($t['label'])) {
                    return 'Chaque ligne doit avoir un code et un libellé.';
                }
            }
        }
        if (! in_array($p['income_tax']['family']['method'] ?? 'none', ['none', 'quotient', 'reduction', 'per_half_part'], true)) {
            return 'Méthode de prise en compte de la famille inconnue.';
        }

        return null;
    }
}
