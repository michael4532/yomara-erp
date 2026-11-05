<?php

namespace App\Modules\Devises;

use App\Models\Company;
use App\Modules\Devises\Models\FxRate;

/** Cours de change : parités fixes (franc CFA / euro) puis cours saisis, le plus récent à la date. */
class FxService
{
    /** Parités fixes garanties : 1 EUR = 655,957 XOF / XAF / KMF (491,96775). */
    public const FIXED = [
        'XOF' => ['EUR' => 655.957],
        'XAF' => ['EUR' => 655.957],
        'KMF' => ['EUR' => 491.96775],
    ];

    /** Devises proposées. */
    public const COMMON = ['XOF' => 'Franc CFA (UEMOA)', 'XAF' => 'Franc CFA (CEMAC)', 'EUR' => 'Euro', 'USD' => 'Dollar américain', 'GBP' => 'Livre sterling',
        'CNY' => 'Yuan', 'GHS' => 'Cedi ghanéen', 'NGN' => 'Naira', 'GNF' => 'Franc guinéen', 'MAD' => 'Dirham marocain', 'CHF' => 'Franc suisse', 'CAD' => 'Dollar canadien', 'AED' => 'Dirham des Émirats'];

    public static function fixedRate(string $base, string $currency): ?float
    {
        $base = strtoupper($base);
        $currency = strtoupper($currency);
        if (isset(self::FIXED[$base][$currency])) {
            return self::FIXED[$base][$currency];
        }
        // Entre deux francs CFA : parité 1:1 (XOF / XAF)
        if (in_array($base, ['XOF', 'XAF'], true) && in_array($currency, ['XOF', 'XAF'], true)) {
            return 1.0;
        }

        return null;
    }

    /** Cours à la date (1 unité de $currency en devise de la société), null si inconnu. */
    public function rate(Company $company, ?string $currency, ?string $date = null): ?float
    {
        $currency = strtoupper((string) ($currency ?: $company->currency));
        $base = strtoupper((string) $company->currency);
        if ($currency === $base) {
            return 1.0;
        }
        if (($f = self::fixedRate($base, $currency)) !== null) {
            return $f;
        }
        $r = FxRate::withoutGlobalScopes()->where('company_id', $company->id)->where('currency', $currency)
            ->where('date', '<=', $date ?? now()->toDateString())->whereNull('deleted_at')->orderByDesc('date')->value('rate');

        return $r !== null ? (float) $r : null;
    }

    /** Taux obligatoire : sinon message clair. */
    public function require(Company $company, string $currency, ?string $date = null): float
    {
        $r = $this->rate($company, $currency, $date);
        if ($r === null) {
            throw new \DomainException('Aucun cours connu pour '.strtoupper($currency).' au '.\Illuminate\Support\Carbon::parse($date ?? now())->format('d/m/Y').' : saisissez-le dans Devises & cours ou sur le document.');
        }

        return $r;
    }

    public function currencies(Company $company): array
    {
        $base = strtoupper($company->currency);
        $enabled = array_values(array_unique(array_merge([$base], (array) data_get($company->settings, 'devises.currencies', ['EUR', 'USD']))));
        $out = [];
        foreach ($enabled as $c) {
            $out[] = ['code' => $c, 'name' => self::COMMON[$c] ?? $c, 'fixed' => self::fixedRate($base, $c), 'rate' => $this->rate($company, $c), 'base' => $c === $base];
        }

        return $out;
    }
}
