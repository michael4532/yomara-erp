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
const headerStyles = {'box': 'Cartouche', 'plain': 'Épuré', 'band': 'Bandeau', 'centered': 'Centré', 'split': 'Deux tons'};
const tableStyles = {'dark': 'Foncé', 'accent': 'Couleur d\'accent', 'light': 'Clair', 'lines': 'Filets', 'striped': 'Zébré'};
const fontChoices = {'roboto': 'Roboto', 'inter': 'Inter'};

/// Modèle intégré.
class DocTemplate {
  const DocTemplate({required this.id, required this.name, required this.description, required this.style, this.blocks = defaultBlocks, this.module, this.price, this.titles = const {}, this.tags = const []});

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
    name: 'Prestige',
    description: 'Noir et or, en-tête centré : pour l\'hôtellerie, le luxe, les cabinets.',
    style: {'primary': '#1A1A1A', 'accent': '#B8935A', 'font': 'inter', 'header': 'centered', 'table': 'lines', 'radius': 2},
    module: 'modele_prestige',
    price: 10000,
    tags: ['Facture', 'Proforma', 'Premium'],
  ),
  DocTemplate(
    id: 'horizon',
    name: 'Horizon',
    description: 'Bandeau émeraude, accents corail, lignes zébrées.',
    style: {'primary': '#0E7C7B', 'accent': '#F4A259', 'font': 'inter', 'header': 'band', 'table': 'striped', 'radius': 10},
    module: 'modele_horizon',
    price: 10000,
    tags: ['Facture', 'Devis', 'Premium'],
  ),
  DocTemplate(
    id: 'offre_pro',
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
    name: 'Ivoire',
    description: 'Orange et vert, deux tons : chaleureux et très lisible.',
    style: {'primary': '#F77F00', 'accent': '#009E60', 'font': 'roboto', 'header': 'split', 'table': 'accent', 'radius': 6},
    module: 'modele_ivoire',
    price: 15000,
    tags: ['Facture', 'Proforma', 'Premium'],
  ),
  DocTemplate(
    id: 'executive',
    name: 'Executive',
    description: 'Bleu ardoise, liseré latéral, cartouche : pour les grands comptes et les marchés publics.',
    style: {'primary': '#23395B', 'accent': '#8EA8C3', 'font': 'inter', 'header': 'box', 'table': 'dark', 'radius': 4, 'sidebar': true},
    module: 'modele_executive',
    price: 20000,
    tags: ['Facture', 'Marchés publics', 'Premium'],
  ),
];

/// Module de la boutique qui donne accès au studio (création de modèles).
const templateStudioModule = 'modeles_studio';

/// Modèles créés par la société (studio).
List<Map<String, dynamic>> customTemplates(Session s) =>
    [for (final t in (s.companySettings['doc_templates'] as List? ?? const [])) if (t is Map) Map<String, dynamic>.from(t)];

DocTemplate? builtinById(String? id) => builtinTemplates.where((t) => t.id == id).firstOrNull;

/// Modèle utilisable ? (gratuit, premium acheté ou modèle de la société)
bool templateUnlocked(Session s, String id) {
  final b = builtinById(id);
  if (b != null) return b.module == null || s.hasModule(b.module!);
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
