<?php

namespace App\Modules\Juridique;

use App\Models\Company;
use App\Support\ServiceRoles;
use Illuminate\Support\Facades\DB;

/** Rôle « Juriste », droits de la direction et modèles de contrats de départ (à faire relire par votre conseil). */
class JuridiqueSetup
{
    public const ROLE = 'Juriste';

    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, ['Direction' => ['juridique.view']], [
            self::ROLE => ['visio.use', 'mailbox.use', 'whatsapp.use', 'achats.request', 'fonds.request', 'juridique.*', 'signature.*', 'documents.*', 'core.contacts.view', 'rh.view', 'relances.view'],
        ]);
        if (DB::table('jr_templates')->where('company_id', $company->id)->exists()) {
            return;
        }
        foreach (self::templates() as [$title, $kind, $body]) {
            DB::table('jr_templates')->insert(['company_id' => $company->id, 'title' => $title, 'kind' => $kind, 'body' => $body, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function templates(): array
    {
        $parties = "ENTRE LES SOUSSIGNÉS :\n\n{societe}, immatriculée au RCCM sous le n° {societe_rccm}, compte contribuable n° {societe_ncc}, dont le siège est situé {societe_adresse}, représentée par {representant}, en sa qualité de {qualite_representant},\nci-après « la Société »,\n\nET\n\n{tiers}, demeurant ou ayant son siège {tiers_adresse},\nci-après « le Cocontractant »,\n\nIL A ÉTÉ CONVENU CE QUI SUIT :\n\n";
        $end = "\n\nFait à {ville}, le {date}, en deux exemplaires originaux.";

        return [
            ['Contrat de prestation de services', 'service', "CONTRAT DE PRESTATION DE SERVICES\n\n".$parties.
                "Article 1 — Objet\nLe présent contrat a pour objet : {objet}.\n\n".
                "Article 2 — Durée\nLe contrat prend effet le {date_debut} et prend fin le {date_fin}. Il peut être renouvelé par avenant écrit.\n\n".
                "Article 3 — Prix et modalités de paiement\nEn contrepartie des prestations, la Société versera la somme de {montant}, payable selon une périodicité {periodicite}, à réception de facture, par virement ou Mobile Money.\n\n".
                "Article 4 — Obligations du prestataire\nLe prestataire s'engage à exécuter les prestations avec diligence et selon les règles de l'art, et à informer la Société de toute difficulté.\n\n".
                "Article 5 — Confidentialité\nChaque partie garde confidentielles les informations reçues de l'autre pendant la durée du contrat et deux (2) ans après son terme.\n\n".
                "Article 6 — Résiliation\nEn cas de manquement grave non réparé dans les quinze (15) jours d'une mise en demeure, l'autre partie peut résilier le contrat. Chaque partie peut y mettre fin moyennant un préavis de {preavis}.\n\n".
                "Article 7 — Litiges\nLes parties rechercheront d'abord une solution amiable. À défaut, le litige sera porté devant le Tribunal de Commerce compétent du siège de la Société.".$end],
            ['Accord de confidentialité (NDA)', 'nda', "ACCORD DE CONFIDENTIALITÉ\n\n".$parties.
                "Article 1 — Objet\nDans le cadre de : {objet}, les parties seront amenées à échanger des informations confidentielles (techniques, commerciales, financières, données clients).\n\n".
                "Article 2 — Engagements\nLa partie qui reçoit une information confidentielle s'engage à ne l'utiliser que pour l'objet ci-dessus, à ne la communiquer qu'aux personnes qui en ont besoin et qui sont tenues au même secret, et à la protéger comme ses propres informations.\n\n".
                "Article 3 — Exceptions\nNe sont pas confidentielles les informations déjà publiques, déjà connues de la partie qui les reçoit, ou dont la communication est exigée par la loi ou une autorité.\n\n".
                "Article 4 — Durée\nLe présent accord prend effet le {date_debut}. Les obligations de confidentialité durent pendant les échanges et cinq (5) ans après leur fin.\n\n".
                "Article 5 — Restitution\nÀ la demande de l'autre partie, chaque partie restitue ou détruit les documents confidentiels reçus.".$end],
            ['Contrat de travail', 'employment', "CONTRAT DE TRAVAIL {type_contrat}\n\nENTRE {societe}, immatriculée au RCCM sous le n° {societe_rccm}, dont le siège est situé {societe_adresse}, représentée par {representant}, {qualite_representant}, ci-après « l'Employeur »,\n\nET {civilite} {nom_complet}, {ne_e} le {date_naissance}, pièce d'identité n° {piece_identite}, demeurant {adresse}, ci-après « le Salarié »,\n\nIL A ÉTÉ CONVENU CE QUI SUIT, conformément au Code du travail et à la convention collective applicable :\n\n".
                "Article 1 — Engagement\nLe Salarié est engagé en qualité de {fonction}, catégorie {categorie}, au sein du service {service}, à compter du {date_embauche}.\n\n".
                "Article 2 — Période d'essai\nLe présent contrat est soumis à une période d'essai dont la durée est celle prévue par la convention collective pour la catégorie du Salarié.\n\n".
                "Article 3 — Rémunération\nLe Salarié perçoit un salaire de base mensuel brut de {salaire_base}, ainsi qu'une indemnité de transport de {transport}, et les primes prévues par la réglementation.\n\n".
                "Article 4 — Durée du travail\nLa durée du travail est fixée à quarante (40) heures par semaine, selon les horaires en vigueur dans l'entreprise.\n\n".
                "Article 5 — Congés\nLe Salarié bénéficie des congés payés prévus par le Code du travail.\n\n".
                "Article 6 — Obligations\nLe Salarié s'engage à respecter le règlement intérieur, à exécuter ses fonctions avec loyauté et à garder le secret professionnel.\n\n".
                "Article 7 — Rupture\nLe contrat peut être rompu dans les conditions prévues par le Code du travail et la convention collective, préavis compris.\n\nFait à {ville}, le {date}, en deux exemplaires originaux."],
            ['Bail à usage professionnel', 'lease', "BAIL À USAGE PROFESSIONNEL\n\n".$parties.
                "Article 1 — Objet\nLe bailleur donne à bail au preneur les locaux suivants : {objet}, à usage exclusivement professionnel.\n\n".
                "Article 2 — Durée\nLe bail est consenti à compter du {date_debut} jusqu'au {date_fin}, conformément à l'Acte uniforme OHADA relatif au droit commercial général. Le preneur peut demander son renouvellement dans les conditions prévues par ce texte.\n\n".
                "Article 3 — Loyer\nLe loyer est fixé à {montant}, payable selon une périodicité {periodicite}, d'avance. Le loyer peut être révisé dans les conditions légales.\n\n".
                "Article 4 — Dépôt de garantie\nUn dépôt de garantie, dont le montant est convenu entre les parties, est versé à la signature et restitué en fin de bail, déduction faite des sommes dues.\n\n".
                "Article 5 — Entretien et réparations\nLe preneur assure l'entretien courant ; les grosses réparations restent à la charge du bailleur.\n\n".
                "Article 6 — Congé\nChaque partie peut donner congé en respectant un préavis de {preavis}, par acte extrajudiciaire ou tout moyen écrit permettant d'en établir la réception.".$end],
            ['Contrat de fourniture / vente', 'supplier', "CONTRAT DE FOURNITURE\n\n".$parties.
                "Article 1 — Objet\nLe fournisseur s'engage à livrer à la Société : {objet}, selon les commandes qui lui seront adressées.\n\n".
                "Article 2 — Durée\nLe contrat est conclu du {date_debut} au {date_fin}.\n\n".
                "Article 3 — Prix\nLes prix sont ceux de la liste annexée ; le montant estimé du contrat est de {montant}. Les prix sont fermes pendant la durée du contrat, sauf accord écrit.\n\n".
                "Article 4 — Livraison et réception\nLes livraisons ont lieu aux dates et lieux indiqués dans chaque commande. La Société contrôle les marchandises à réception et peut refuser celles qui ne sont pas conformes.\n\n".
                "Article 5 — Paiement\nLes factures sont payables à trente (30) jours de leur réception, par virement.\n\n".
                "Article 6 — Garantie\nLe fournisseur garantit les produits contre tout vice caché et tout défaut de conformité.\n\n".
                "Article 7 — Résiliation\nChaque partie peut résilier le contrat moyennant un préavis de {preavis}, ou immédiatement en cas de manquement grave non réparé après mise en demeure.".$end],
        ];
    }
}
