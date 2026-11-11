import 'package:flutter/material.dart';

/// Parcours de démarrage par métier : écrans mis en favoris et premiers pas, pour qu'un nouveau client
/// ne voie d'abord que l'essentiel de son activité parmi les dizaines de modules.
class Metier {
  const Metier(this.id, this.label, this.icon, this.favorites, this.steps);

  final String id;
  final String label;
  final IconData icon;

  /// Écrans épinglés dans le menu (identifiants de navigation), dans l'ordre.
  final List<String> favorites;

  /// Premiers pas : (intitulé, écran à ouvrir).
  final List<(String, String)> steps;
}

const metiers = <Metier>[
  Metier('commerce', 'Commerce, boutique', Icons.storefront_outlined, ['pos', 'ventes', 'products', 'stock', 'contacts', 'tresorerie'], [
    ('Ajouter vos articles et leurs prix', 'products'),
    ('Ouvrir la caisse et encaisser une vente', 'pos'),
    ('Suivre le stock et les alertes', 'stock'),
    ('Voir la trésorerie du jour', 'tresorerie'),
  ]),
  Metier('restaurant', 'Restaurant, maquis, café', Icons.restaurant_outlined, ['restaurant', 'pos', 'kitchen', 'products', 'stock', 'tresorerie'], [
    ('Créer le menu et les tables', 'restaurant'),
    ('Prendre une commande et l\'envoyer en cuisine', 'pos'),
    ('Suivre les ingrédients en stock', 'stock'),
    ('Clôturer la caisse du soir', 'tresorerie'),
  ]),
  Metier('services', 'Prestataire de services, agence', Icons.design_services_outlined, ['ventes', 'contacts', 'projets', 'abonnements', 'relances', 'tresorerie'], [
    ('Ajouter vos clients', 'contacts'),
    ('Faire un devis puis la facture', 'ventes'),
    ('Facturer chaque mois automatiquement', 'abonnements'),
    ('Relancer les impayés', 'relances'),
  ]),
  Metier('distribution', 'Grossiste, distribution', Icons.local_shipping_outlined, ['ventes', 'achats', 'stock', 'logistique', 'contacts', 'relances'], [
    ('Ajouter vos articles et fournisseurs', 'products'),
    ('Passer une commande fournisseur', 'achats'),
    ('Livrer et facturer vos clients', 'logistique'),
    ('Suivre les encours et relancer', 'relances'),
  ]),
  Metier('btp', 'BTP, construction', Icons.construction_outlined, ['btp', 'projets', 'ventes', 'achats', 'materiel', 'marches_publics'], [
    ('Créer un chantier et son budget', 'btp'),
    ('Faire un devis et les situations de travaux', 'ventes'),
    ('Suivre le matériel et les achats', 'materiel'),
    ('Répondre aux appels d\'offres', 'marches_publics'),
  ]),
  Metier('hotel', 'Hôtel, résidence, location', Icons.hotel_outlined, ['hebergement', 'rdv', 'ventes', 'pos', 'tresorerie', 'contacts'], [
    ('Créer vos chambres et vos tarifs', 'hebergement'),
    ('Enregistrer une réservation', 'hebergement'),
    ('Facturer le séjour', 'ventes'),
    ('Suivre la trésorerie', 'tresorerie'),
  ]),
  Metier('sante', 'Santé, beauté, rendez-vous', Icons.spa_outlined, ['rdv', 'pos', 'contacts', 'ventes', 'fidelite', 'tresorerie'], [
    ('Ouvrir la prise de rendez-vous en ligne', 'rdv'),
    ('Encaisser une prestation', 'pos'),
    ('Fidéliser vos clients', 'fidelite'),
    ('Suivre la trésorerie', 'tresorerie'),
  ]),
  Metier('pme', 'PME, cabinet, administration', Icons.business_outlined, ['ventes', 'achats', 'compta', 'rh', 'paie', 'ged'], [
    ('Paramétrer la société et les comptes bancaires', 'settings'),
    ('Ajouter les salariés', 'rh'),
    ('Faire la première paie', 'paie'),
    ('Tenir la comptabilité', 'compta'),
  ]),
];

Metier? metierById(String? id) => metiers.where((m) => m.id == id).firstOrNull;
