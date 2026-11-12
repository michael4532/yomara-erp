import '../state/session.dart';

/// Modèles de documents (factures, proformas, devis / offres, commandes, acomptes, avoirs, achats).
///
/// Un modèle = un style (couleurs, police, en-tête, tableau) + une liste ordonnée de blocs.
/// Les modèles intégrés sont gratuits ou premium (module de la boutique à acheter) ;
/// les modèles de la société sont créés dans le studio par glisser-déposer.

/// Blocs disponibles dans le studio : type → (libellé, description).
const docBlockTypes = <String, (String, String)>{
  'header': ('En-tête', 'Logo, société, titre, numéro et dates'),
  'parties': ('Client / fournisseur', 'Coordonnées du destinataire'),
  'info': ('Références', 'Référence, champs personnalisés, validité de l\'offre'),
  'lines': ('Lignes', 'Tableau des articles et prestations'),
  'totals': ('Totaux', 'HT, TVA par taux, TTC, reste à payer'),
  'words': ('Montant en lettres', '« Arrêtée la présente facture à la somme de… »'),
  'payment': ('Règlement', 'Mode de règlement, banque, QR de paiement'),
  'notes': ('Notes et conditions', 'Notes du document, conditions de paiement'),
  'fne': ('Facture normalisée (FNE)', 'Cachet et QR de la DGI'),
  'receipt': ('Case décharge', 'Signature du client à la remise'),
  'acceptance': ('Bon pour accord', 'Signature du client sur une offre ou un devis'),
  'signature': ('Signature', 'Cadre de signature et cachet de la société'),
  'text': ('Texte libre', 'Paragraphe à votre convenance'),
  'divider': ('Séparateur', 'Trait horizontal'),
  'spacer': ('Espace', 'Espace vertical'),
  'footer_note': ('Mention de pied', 'Mention en pied de facture des paramètres'),
};

/// Blocs du modèle standard, dans l'ordre.
const defaultBlocks = <Map<String, dynamic>>[
  {'type': 'header'},
  {'type': 'parties'},
  {'type': 'info'},
  {'type': 'lines'},
  {'type': 'totals'},
  {'type': 'words'},
  {'type': 'payment'},
  {'type': 'notes'},
  {'type': 'fne'},
  {'type': 'receipt'},
  {'type': 'acceptance'},
  {'type': 'footer_note'},
];

/// Styles d'en-tête et de tableau proposés dans le studio.
const headerStyles = {
  'box': 'Cartouche',
  'plain': 'Épuré',
  'band': 'Bandeau',
  'centered': 'Centré',
  'split': 'Deux tons',
  'stacked': 'Grand titre',
  'overlay': 'Sur le décor (bandeau, vague, oblique)',
};
const tableStyles = {'dark': 'Foncé', 'accent': 'Couleur d\'accent', 'light': 'Clair', 'lines': 'Filets', 'striped': 'Zébré'};
const fontChoices = {'roboto': 'Roboto', 'inter': 'Inter', 'poppins': 'Poppins', 'lora': 'Lora (à empattements)'};

/// Police des titres (nom de la société, titre du document) ; vide = celle du texte.
const titleFontChoices = {'': 'Comme le texte', 'playfair': 'Playfair (élégante)', 'lora': 'Lora (classique)', 'poppins': 'Poppins (géométrique)', 'inter': 'Inter'};

/// Décors de page (arrière-plan).
const decorChoices = {
  'none': 'Aucun',
  'band': 'Bandeau pleine largeur',
  'wave': 'Vague',
  'diagonal': 'Oblique',
  'circles': 'Cercles',
  'kente': 'Frise tissée',
  'frame': 'Double cadre',
  'stripes': 'Filets haut et bas',
};

/// Mise en valeur du total.
const totalsStyles = {'tint': 'Fond clair', 'solid': 'Fond plein', 'line': 'Souligné'};

/// Familles de la bibliothèque (filtre).
const templateFamilies = ['Essentiels', 'Luxe', 'Moderne', 'Corporate', 'Créatif', 'Métiers'];

/// Modèle intégré.
class DocTemplate {
  const DocTemplate({required this.id, required this.name, required this.description, required this.style, this.blocks = defaultBlocks, this.module, this.price, this.titles = const {}, this.tags = const [], this.family = 'Essentiels'});

  final String id;
  final String name;
  final String description;
  final Map<String, dynamic> style;
  final List<Map<String, dynamic>> blocks;

  /// Module premium à acheter (null = gratuit).
  final String? module;

  /// Prix affiché (F CFA, achat définitif) — le prix réel vient de la boutique.
  final int? price;

  /// Titres par type de document (ex. « OFFRE COMMERCIALE » pour un devis).
  final Map<String, String> titles;
  final List<String> tags;

  /// Famille de la bibliothèque (Luxe, Moderne…).
  final String family;

  bool get premium => module != null;

  Map<String, dynamic> toSpec() => {'id': id, 'name': name, 'style': style, 'blocks': blocks, 'titles': titles, 'builtin': true};
}

const builtinTemplates = <DocTemplate>[
  DocTemplate(
    id: 'classique',
    name: 'Classique',
    description: 'Cartouche bleu nuit et or, le modèle Yomara.',
    style: {'primary': '#0B1B3A', 'accent': '#FFC531', 'font': 'roboto', 'header': 'box', 'table': 'dark', 'radius': 6},
    tags: ['Facture', 'Proforma', 'Devis'],
  ),
  DocTemplate(
    id: 'minimal',
    name: 'Minimal',
    description: 'Noir et blanc, filets fins : sobre et économe en encre.',
    style: {'primary': '#111827', 'accent': '#111827', 'font': 'inter', 'header': 'plain', 'table': 'lines', 'radius': 0},
    tags: ['Facture', 'Impression'],
  ),
  DocTemplate(
    id: 'moderne',
    name: 'Moderne',
    description: 'Bandeau bleu vif et tableau clair.',
    style: {'primary': '#1F6FEB', 'accent': '#1F6FEB', 'font': 'inter', 'header': 'band', 'table': 'light', 'radius': 8},
    tags: ['Facture', 'Devis'],
  ),
  DocTemplate(
    id: 'prestige',
    family: 'Luxe',
    name: 'Prestige',
    description: 'Noir et or, en-tête centré : pour l\'hôtellerie, le luxe, les cabinets.',
    style: {'primary': '#1A1A1A', 'accent': '#B8935A', 'font': 'inter', 'header': 'centered', 'table': 'lines', 'radius': 2},
    module: 'modele_prestige',
    price: 10000,
    tags: ['Facture', 'Proforma', 'Premium'],
  ),
  DocTemplate(
    id: 'horizon',
    family: 'Moderne',
    name: 'Horizon',
    description: 'Bandeau émeraude, accents corail, lignes zébrées.',
    style: {'primary': '#0E7C7B', 'accent': '#F4A259', 'font': 'inter', 'header': 'band', 'table': 'striped', 'radius': 10},
    module: 'modele_horizon',
    price: 10000,
    tags: ['Facture', 'Devis', 'Premium'],
  ),
  DocTemplate(
    id: 'offre_pro',
    family: 'Corporate',
    name: 'Offre commerciale',
    description: 'Devis et proformas présentés en offre : validité, « Bon pour accord » signé par le client.',
    style: {'primary': '#3B2F8F', 'accent': '#7C6CF2', 'font': 'inter', 'header': 'split', 'table': 'accent', 'radius': 8},
    titles: {'quote': 'OFFRE COMMERCIALE', 'proforma': 'FACTURE PROFORMA', 'order': 'BON DE COMMANDE'},
    blocks: [
      {'type': 'header'},
      {'type': 'parties'},
      {'type': 'text', 'text': 'Suite à votre demande, nous avons le plaisir de vous soumettre notre meilleure offre :', 'size': 10},
      {'type': 'info'},
      {'type': 'lines'},
      {'type': 'totals'},
      {'type': 'words'},
      {'type': 'notes'},
      {'type': 'payment'},
      {'type': 'acceptance'},
      {'type': 'footer_note'},
    ],
    module: 'modele_offre',
    price: 10000,
    tags: ['Devis', 'Proforma', 'Offre', 'Premium'],
  ),
  DocTemplate(
    id: 'ivoire',
    family: 'Créatif',
    name: 'Ivoire',
    description: 'Orange et vert, deux tons : chaleureux et très lisible.',
    style: {'primary': '#F77F00', 'accent': '#009E60', 'font': 'roboto', 'header': 'split', 'table': 'accent', 'radius': 6},
    module: 'modele_ivoire',
    price: 15000,
    tags: ['Facture', 'Proforma', 'Premium'],
  ),
  DocTemplate(
    id: 'executive',
    family: 'Corporate',
    name: 'Executive',
    description: 'Bleu ardoise, liseré latéral, cartouche : pour les grands comptes et les marchés publics.',
    style: {'primary': '#23395B', 'accent': '#8EA8C3', 'font': 'inter', 'header': 'box', 'table': 'dark', 'radius': 4, 'sidebar': true},
    module: 'modele_executive',
    price: 20000,
    tags: ['Facture', 'Marchés publics', 'Premium'],
  ),
  // ---------------- Collection premium 2026 ----------------
  DocTemplate(
    id: 'onyx',
    family: 'Luxe',
    name: 'Onyx',
    description: 'Bandeau noir pleine largeur, titres à empattements dorés, total sur fond noir : hôtels, joaillerie, cabinets.',
    style: {'primary': '#141414', 'accent': '#C9A45C', 'font': 'inter', 'title_font': 'playfair', 'header': 'overlay', 'decor': 'band', 'table': 'lines', 'totals': 'solid', 'radius': 2},
    module: 'modele_onyx',
    price: 20000,
    tags: ['Facture', 'Proforma', 'Luxe'],
  ),
  DocTemplate(
    id: 'royal',
    family: 'Luxe',
    name: 'Royal',
    description: 'Violet profond et or, double cadre, filigrane du logo : événementiel, beauté, prestige.',
    style: {'primary': '#3C1F5C', 'accent': '#C9A227', 'font': 'inter', 'title_font': 'playfair', 'header': 'centered', 'decor': 'frame', 'table': 'dark', 'totals': 'solid', 'radius': 2, 'watermark': true},
    module: 'modele_royal',
    price: 20000,
    tags: ['Facture', 'Devis', 'Luxe'],
  ),
  DocTemplate(
    id: 'atelier',
    family: 'Luxe',
    name: 'Atelier',
    description: 'Typographie à empattements, double cadre ardoise et cuivre : architectes, avocats, notaires.',
    style: {'primary': '#2F3E46', 'accent': '#B08968', 'font': 'lora', 'title_font': 'playfair', 'header': 'centered', 'decor': 'frame', 'table': 'lines', 'totals': 'line', 'radius': 0},
    module: 'modele_atelier',
    price: 15000,
    tags: ['Facture', 'Honoraires', 'Luxe'],
  ),
  DocTemplate(
    id: 'lagon',
    family: 'Moderne',
    name: 'Lagon',
    description: 'Vague bleu lagon en tête et en pied, police géométrique : services, tourisme, transport.',
    style: {'primary': '#0B5D7A', 'accent': '#3FC1C9', 'font': 'poppins', 'header': 'overlay', 'decor': 'wave', 'table': 'light', 'totals': 'solid', 'radius': 8},
    module: 'modele_lagon',
    price: 15000,
    tags: ['Facture', 'Devis', 'Moderne'],
  ),
  DocTemplate(
    id: 'geometrie',
    family: 'Créatif',
    name: 'Géométrie',
    description: 'Cercles en arrière-plan, grand titre et montant dû en avant : agences, studios, startups.',
    style: {'primary': '#4B32C3', 'accent': '#FF7A59', 'font': 'poppins', 'header': 'stacked', 'decor': 'circles', 'table': 'striped', 'totals': 'solid', 'radius': 10},
    module: 'modele_geometrie',
    price: 15000,
    tags: ['Facture', 'Devis', 'Créatif'],
  ),
  DocTemplate(
    id: 'monochrome',
    family: 'Moderne',
    name: 'Monochrome',
    description: 'Noir et blanc, très grand titre, cases d\'informations : designers, consultants, freelances. Économe en encre.',
    style: {'primary': '#000000', 'accent': '#000000', 'font': 'inter', 'title_font': 'poppins', 'header': 'stacked', 'table': 'lines', 'totals': 'solid', 'radius': 0},
    module: 'modele_monochrome',
    price: 10000,
    tags: ['Facture', 'Impression', 'Moderne'],
  ),
  DocTemplate(
    id: 'kente',
    family: 'Créatif',
    name: 'Kente',
    description: 'Frise inspirée des tissages, or, vert et rouge en tête et en pied : commerce, artisanat, culture.',
    style: {'primary': '#0B7A3B', 'accent': '#E8B10D', 'third': '#C1272D', 'font': 'poppins', 'header': 'box', 'decor': 'kente', 'table': 'dark', 'totals': 'tint', 'radius': 4},
    module: 'modele_kente',
    price: 15000,
    tags: ['Facture', 'Proforma', 'Créatif'],
  ),
  DocTemplate(
    id: 'oblique',
    family: 'Corporate',
    name: 'Oblique',
    description: 'Bandeau oblique bleu marine et rouge, angle rappelé en pied : BTP, industrie, logistique.',
    style: {'primary': '#1D2A4D', 'accent': '#E63946', 'font': 'poppins', 'header': 'overlay', 'decor': 'diagonal', 'table': 'dark', 'totals': 'line', 'radius': 2},
    module: 'modele_oblique',
    price: 15000,
    tags: ['Facture', 'Marchés', 'Corporate'],
  ),
  DocTemplate(
    id: 'azur',
    family: 'Corporate',
    name: 'Azur',
    description: 'Bandeau bleu roi, filigrane du logo, lignes zébrées : PME, distribution, services.',
    style: {'primary': '#0A3D91', 'accent': '#00A8E8', 'font': 'inter', 'header': 'overlay', 'decor': 'band', 'table': 'striped', 'totals': 'solid', 'radius': 6, 'watermark': true},
    module: 'modele_azur',
    price: 10000,
    tags: ['Facture', 'Proforma', 'Corporate'],
  ),
  DocTemplate(
    id: 'savane',
    family: 'Métiers',
    name: 'Savane',
    description: 'Vague brun et ocre, typographie chaleureuse : restauration, agroalimentaire, hôtellerie.',
    style: {'primary': '#6B4423', 'accent': '#DDA15E', 'font': 'lora', 'title_font': 'lora', 'header': 'overlay', 'decor': 'wave', 'table': 'striped', 'totals': 'tint', 'radius': 6},
    module: 'modele_savane',
    price: 15000,
    tags: ['Facture', 'Restauration', 'Métiers'],
  ),
  DocTemplate(
    id: 'sante',
    family: 'Métiers',
    name: 'Santé',
    description: 'Sarcelle apaisante, filets en tête et en pied, grand titre : cliniques, pharmacies, laboratoires.',
    style: {'primary': '#0F766E', 'accent': '#5EC8B8', 'font': 'inter', 'header': 'stacked', 'decor': 'stripes', 'table': 'light', 'totals': 'solid', 'radius': 6},
    module: 'modele_sante',
    price: 10000,
    tags: ['Facture', 'Santé', 'Métiers'],
  ),
];

/// Collection premium : un seul achat débloque tous les modèles premium, présents et à venir.
const templateCollectionModule = 'modeles_collection';
const templateCollectionPrice = 60000;

/// Module de la boutique qui donne accès au studio (création de modèles).
const templateStudioModule = 'modeles_studio';

/// Modèles créés par la société (studio).
List<Map<String, dynamic>> customTemplates(Session s) =>
    [for (final t in (s.companySettings['doc_templates'] as List? ?? const [])) if (t is Map) Map<String, dynamic>.from(t)];

DocTemplate? builtinById(String? id) => builtinTemplates.where((t) => t.id == id).firstOrNull;

/// Modèle utilisable ? (gratuit, premium acheté ou modèle de la société)
bool templateUnlocked(Session s, String id) {
  final b = builtinById(id);
  if (b != null) return b.module == null || s.hasModule(b.module!) || s.hasModule(templateCollectionModule);
  return customTemplates(s).any((t) => t['id'] == id);
}

/// Spécification d'un modèle par son identifiant (null si inconnu).
Map<String, dynamic>? templateSpec(Session s, String? id) {
  if (id == null) return null;
  final b = builtinById(id);
  if (b != null) return b.toSpec();
  return customTemplates(s).where((t) => t['id'] == id).firstOrNull;
}

/// Modèle par défaut d'un type de document (« sales_documents.invoice ») ; sinon défaut général ; sinon Classique.
String defaultTemplateId(Session s, String entity, String? type) {
  final d = Map<String, dynamic>.from(s.companySettings['doc_template_defaults'] as Map? ?? const {});
  return '${d['$entity.$type'] ?? d['*'] ?? 'classique'}';
}

/// Modèle appliqué à un document : choisi sur le document, sinon défaut du type ; un premium non acheté retombe sur Classique.
Map<String, dynamic> resolveTemplate(Session s, Map<String, dynamic> doc, String entity) {
  for (final id in [doc['template'] as String?, defaultTemplateId(s, entity, doc['type'] as String?)]) {
    if (id != null && templateUnlocked(s, id)) {
      final spec = templateSpec(s, id);
      if (spec != null) return spec;
    }
  }
  return builtinTemplates.first.toSpec();
}

/// Tous les modèles utilisables (intégrés débloqués + ceux de la société) : id → nom.
Map<String, String> usableTemplates(Session s) => {
      for (final t in builtinTemplates)
        if (templateUnlocked(s, t.id)) t.id: t.name,
      for (final t in customTemplates(s)) '${t['id']}': '${t['name']}',
    };
