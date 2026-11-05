<?php

namespace App\Modules\Rh;

use App\Models\Company;
use App\Modules\Rh\Models\Employee;
use Carbon\Carbon;

/**
 * Remplit un modèle de document RH : {nom_complet}, {fonction}, {date_embauche}… (même liste que l'application).
 * Les champs inconnus restent tels quels (« [à compléter] » pour ceux qui sont vides).
 */
class DocRenderer
{
    public const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public const CONTRACTS = ['CDI' => 'à durée indéterminée (CDI)', 'CDD' => 'à durée déterminée (CDD)', 'stage' => 'de stage', 'journalier' => 'de travail journalier', 'consultant' => 'de prestation'];

    public static function longDate($d): string
    {
        if (! $d) {
            return '[à compléter]';
        }
        $c = Carbon::parse($d);

        return ($c->day === 1 ? '1er' : $c->day).' '.self::MONTHS[$c->month - 1].' '.$c->year;
    }

    public static function money(float $v, string $currency = 'XOF'): string
    {
        return number_format($v, 0, ',', ' ').' '.($currency === 'XOF' ? 'F CFA' : $currency);
    }

    /** @return array<string, string> */
    public static function fields(Company $company, Employee $e, array $extra = []): array
    {
        $s = $company->settings ?? [];
        $f = $e->gender === 'F';
        $na = '[à compléter]';

        return array_merge([
            'societe' => $company->name,
            'societe_adresse' => trim(($company->address ? $company->address.', ' : '').($company->city ?? '')) ?: $na,
            'societe_ville' => $company->city ?: 'Abidjan',
            'societe_rccm' => $company->trade_register ?: $na,
            'societe_ncc' => $company->tax_id ?: $na,
            'representant' => $s['rh_signatory'] ?? $na,
            // dans une phrase : « Awa Koné, la Gérante de la société… »
            'qualite_representant' => mb_strtolower(mb_substr($s['rh_signatory_title'] ?? 'Le Gérant', 0, 1)).mb_substr($s['rh_signatory_title'] ?? 'Le Gérant', 1),
            'civilite' => $f ? 'Madame' : ($e->gender === 'M' ? 'Monsieur' : 'Monsieur / Madame'),
            'ne_e' => $f ? 'née' : 'né',
            'employe' => $f ? 'employée' : 'employé',
            'il_elle' => $f ? 'elle' : 'il',
            'interesse' => $f ? 'l\'intéressée' : 'l\'intéressé',
            'nom' => $e->last_name,
            'prenoms' => $e->first_name,
            'nom_complet' => trim($e->last_name.' '.$e->first_name),
            'date_naissance' => $e->birth_date ? self::longDate($e->birth_date) : $na,
            'piece_identite' => $e->id_number ?: $na,
            'adresse' => $e->address ?: $na,
            'matricule' => $e->matricule,
            'fonction' => $e->job_title ?: $na,
            'service' => $e->department ?: $na,
            'categorie' => $e->category ?: $na,
            'type_contrat' => self::CONTRACTS[$e->contract_type] ?? (string) $e->contract_type,
            'date_embauche' => self::longDate($e->hire_date),
            'date_sortie' => $e->end_date ? self::longDate($e->end_date) : $na,
            'salaire_base' => self::money((float) $e->base_salary, $company->currency ?? 'XOF'),
            'transport' => self::money((float) $e->transport_allowance, $company->currency ?? 'XOF'),
            'cnps' => $e->cnps_number ?: $na,
            'date' => self::longDate(now()),
            'ville' => $company->city ?: 'Abidjan',
            // Solde de tout compte calculé et validé
            'montant_stc' => ! empty($e->settlement['total']) ? self::money((float) $e->settlement['total'], $company->currency ?? 'XOF') : $na,
            'motif_sortie' => $e->settlement['reason_label'] ?? $na,
        ], array_map('strval', $extra));
    }

    public static function render(string $body, array $fields): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $fields) ? ($fields[$m[1]] !== '' ? $fields[$m[1]] : '[à compléter]') : $m[0], $body);
    }
}
