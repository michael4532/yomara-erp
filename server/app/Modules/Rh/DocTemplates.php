<?php

namespace App\Modules\Rh;

/** Modèles de départ (à adapter par l'entreprise ; à faire relire par un conseil pour les contrats). */
class DocTemplates
{
    public const TYPES = ['attestation' => 'Attestation de travail', 'certificate' => 'Certificat de travail', 'contract' => 'Contrat de travail', 'warning' => 'Avertissement', 'stc' => 'Reçu pour solde de tout compte', 'other' => 'Autre document'];

    public static function defaults(): array
    {
        return [
            ['attestation', 'Attestation de travail', true, <<<'TXT'
Je soussigné(e), {representant}, {qualite_representant} de la société {societe}, sise {societe_adresse}, RCCM {societe_rccm}, atteste que {civilite} {nom_complet}, {ne_e} le {date_naissance}, matricule {matricule}, est {employe} au sein de notre entreprise depuis le {date_embauche}, en qualité de {fonction}, sous contrat de travail {type_contrat}.

La présente attestation est délivrée à {interesse}, à sa demande, pour servir et valoir ce que de droit.
TXT],
            ['certificate', 'Certificat de travail', false, <<<'TXT'
Je soussigné(e), {representant}, {qualite_representant} de la société {societe}, sise {societe_adresse}, certifie que {civilite} {nom_complet}, {ne_e} le {date_naissance}, a été {employe} dans notre entreprise du {date_embauche} au {date_sortie}, en qualité de {fonction} (catégorie {categorie}).

{civilite} {nom} nous quitte libre de tout engagement.

Le présent certificat est délivré à {interesse} pour servir et valoir ce que de droit.
TXT],
            ['contract', 'Contrat de travail à durée indéterminée', false, <<<'TXT'
ENTRE LES SOUSSIGNÉS :

La société {societe}, sise {societe_adresse}, RCCM {societe_rccm}, NCC {societe_ncc}, représentée par {representant}, {qualite_representant}, ci-après « l'Employeur »,

ET

{civilite} {nom_complet}, {ne_e} le {date_naissance}, titulaire de la pièce d'identité n° {piece_identite}, demeurant à {adresse}, ci-après « le Salarié »,

IL A ÉTÉ CONVENU CE QUI SUIT :

**Article 1 – Engagement**
L'Employeur engage le Salarié, qui accepte, par contrat de travail à durée indéterminée régi par le Code du travail de Côte d'Ivoire et la convention collective applicable, à compter du {date_embauche}.

**Article 2 – Fonctions**
Le Salarié occupe le poste de {fonction}, au sein du service {service}, catégorie {categorie}. Il exerce ses fonctions à {lieu_travail}.

**Article 3 – Période d'essai**
Le présent contrat ne deviendra définitif qu'à l'issue d'une période d'essai de {duree_essai}, pendant laquelle chacune des parties peut y mettre fin sans préavis ni indemnité, dans les conditions prévues par la réglementation.

**Article 4 – Rémunération**
Le Salarié percevra un salaire de base mensuel brut de {salaire_base}, auquel s'ajoute une indemnité de transport de {transport}, ainsi que les primes et avantages prévus par la convention collective et les usages de l'entreprise.

**Article 5 – Durée du travail**
La durée du travail est fixée à quarante (40) heures par semaine, selon l'horaire en vigueur dans l'entreprise. Les heures supplémentaires éventuelles sont rémunérées conformément à la réglementation.

**Article 6 – Congés payés**
Le Salarié bénéficie des congés payés prévus par le Code du travail, soit 2,2 jours ouvrables par mois de service effectif.

**Article 7 – Obligations**
Le Salarié s'engage à respecter le règlement intérieur, les instructions de sa hiérarchie et à observer la plus stricte discrétion sur les informations dont il aurait connaissance dans l'exercice de ses fonctions.

**Article 8 – Rupture du contrat**
Le présent contrat peut être rompu par l'une ou l'autre des parties dans les conditions prévues par le Code du travail et la convention collective, notamment en ce qui concerne le préavis.

Fait à {ville}, le {date}, en deux exemplaires originaux.
TXT],
            ['contract', 'Contrat de travail à durée déterminée', false, <<<'TXT'
ENTRE LES SOUSSIGNÉS :

La société {societe}, sise {societe_adresse}, RCCM {societe_rccm}, représentée par {representant}, {qualite_representant}, ci-après « l'Employeur »,

ET

{civilite} {nom_complet}, {ne_e} le {date_naissance}, demeurant à {adresse}, ci-après « le Salarié »,

IL A ÉTÉ CONVENU CE QUI SUIT :

**Article 1 – Objet et durée**
L'Employeur engage le Salarié par contrat de travail à durée déterminée pour le motif suivant : {motif_cdd}. Le contrat prend effet le {date_embauche} et prendra fin le {date_fin_cdd}.

**Article 2 – Fonctions**
Le Salarié occupe le poste de {fonction}, catégorie {categorie}, à {lieu_travail}.

**Article 3 – Période d'essai**
Le contrat comporte une période d'essai de {duree_essai}.

**Article 4 – Rémunération**
Salaire de base mensuel brut : {salaire_base} ; indemnité de transport : {transport}.

**Article 5 – Durée du travail et congés**
Quarante (40) heures par semaine ; congés payés de 2,2 jours ouvrables par mois de service effectif.

**Article 6 – Fin du contrat**
Le contrat prend fin de plein droit à son terme. Il ne peut être rompu avant terme que dans les cas prévus par la loi.

Fait à {ville}, le {date}, en deux exemplaires originaux.
TXT],
            ['warning', 'Avertissement', false, <<<'TXT'
Objet : avertissement

{civilite},

Nous avons eu à déplorer de votre part les faits suivants : {faits}.

Ces agissements constituent un manquement à vos obligations professionnelles. Nous vous notifions par la présente un avertissement, qui sera versé à votre dossier.

Nous vous demandons de veiller à ce que de tels faits ne se reproduisent pas, faute de quoi nous serions amenés à envisager une sanction plus sévère.

Veuillez agréer, {civilite}, l'expression de nos salutations distinguées.
TXT],
            ['stc', 'Reçu pour solde de tout compte', false, <<<'TXT'
Je soussigné(e), {civilite} {nom_complet}, matricule {matricule}, reconnais avoir reçu de la société {societe} la somme de {montant_stc} pour solde de tout compte, en paiement des sommes détaillées ci-dessous, dues au titre de l'exécution et de la cessation de mon contrat de travail, ayant pris fin le {date_sortie} (motif : {motif_sortie}).

Le présent reçu est établi en deux exemplaires, dont un m'a été remis.
TXT],
        ];
    }
}
