import '../state/session.dart';
import '../ui/widgets/common.dart' show toDouble;

/// Taux normal de TVA par pays (code ISO à 2 lettres).
const vatRatesByCountry = <String, double>{
  // UEMOA
  'CI': 18, 'SN': 18, 'BF': 18, 'ML': 18, 'BJ': 18, 'TG': 18, 'NE': 19, 'GW': 19,
  // CEMAC
  'CM': 19.25, 'GA': 18, 'CG': 18, 'TD': 18, 'CF': 19, 'GQ': 15,
  // Autres pays d'Afrique
  'GN': 18, 'CD': 16, 'MA': 20, 'TN': 19, 'DZ': 19, 'MR': 16, 'MG': 20, 'RW': 18, 'BI': 18, 'KM': 10, 'DJ': 10,
  'GH': 15, 'NG': 7.5, 'KE': 16, 'ZA': 15, 'EG': 14, 'ET': 15, 'UG': 18, 'TZ': 18, 'MU': 15, 'SC': 15, 'CV': 15, 'LR': 10, 'SL': 15, 'GM': 15,
  // Europe et Amérique du Nord
  'FR': 20, 'BE': 21, 'LU': 17, 'CH': 8.1, 'DE': 19, 'ES': 21, 'IT': 22, 'PT': 23, 'NL': 21, 'GB': 20, 'IE': 23, 'CA': 5, 'US': 0,
  // Moyen-Orient
  'AE': 5, 'SA': 15, 'LB': 11,
};

/// Noms des pays proposés dans les informations de la société.
const vatCountryNames = <String, String>{
  'CI': 'Côte d\'Ivoire', 'SN': 'Sénégal', 'BF': 'Burkina Faso', 'ML': 'Mali', 'BJ': 'Bénin', 'TG': 'Togo', 'NE': 'Niger', 'GW': 'Guinée-Bissau',
  'CM': 'Cameroun', 'GA': 'Gabon', 'CG': 'Congo', 'TD': 'Tchad', 'CF': 'Centrafrique', 'GQ': 'Guinée équatoriale',
  'GN': 'Guinée', 'CD': 'RD Congo', 'MA': 'Maroc', 'TN': 'Tunisie', 'DZ': 'Algérie', 'MR': 'Mauritanie', 'MG': 'Madagascar', 'RW': 'Rwanda', 'BI': 'Burundi',
  'KM': 'Comores', 'DJ': 'Djibouti', 'GH': 'Ghana', 'NG': 'Nigeria', 'KE': 'Kenya', 'ZA': 'Afrique du Sud', 'EG': 'Égypte', 'ET': 'Éthiopie', 'UG': 'Ouganda',
  'TZ': 'Tanzanie', 'MU': 'Maurice', 'SC': 'Seychelles', 'CV': 'Cap-Vert', 'LR': 'Liberia', 'SL': 'Sierra Leone', 'GM': 'Gambie',
  'FR': 'France', 'BE': 'Belgique', 'LU': 'Luxembourg', 'CH': 'Suisse', 'DE': 'Allemagne', 'ES': 'Espagne', 'IT': 'Italie', 'PT': 'Portugal', 'NL': 'Pays-Bas',
  'GB': 'Royaume-Uni', 'IE': 'Irlande', 'CA': 'Canada', 'US': 'États-Unis', 'AE': 'Émirats arabes unis', 'SA': 'Arabie saoudite', 'LB': 'Liban',
};

/// Taux normal du pays (18 % par défaut, celui de la Côte d'Ivoire).
double countryVatRate(String? country) => vatRatesByCountry[(country ?? 'CI').toUpperCase()] ?? 18;

/// La société facture-t-elle la TVA ? (oui par défaut)
bool vatSubject(Session s) => s.companySettings['vat_subject'] != false;

/// Taux normal de la société : réglage « TVA par défaut », sinon taux du pays.
double standardVatRate(Session s) {
  final set = s.companySettings['tax_rate'];
  return set == null ? countryVatRate(s.company['country'] as String?) : toDouble(set);
}

/// Taux appliqué par défaut aux nouvelles lignes de vente.
/// Client établi dans un autre pays (exportation) : 0 % par défaut, comme le veut la règle générale.
double defaultVatRate(Session s, [Map<String, dynamic>? partner]) {
  if (!vatSubject(s)) return 0;
  return isExportClient(s, partner) ? 0 : standardVatRate(s);
}

/// Client situé hors du pays de la société.
bool isExportClient(Session s, Map<String, dynamic>? partner) {
  final pc = '${partner?['country'] ?? ''}'.toUpperCase();
  final cc = '${s.company['country'] ?? 'CI'}'.toUpperCase();
  return pc.length == 2 && pc != cc;
}

/// Comptes bancaires imprimés sur les factures : « banque|numéro » identifie un compte.
List<Map<String, dynamic>> invoiceBanks(Session s) =>
    [for (final b in (s.companySettings['bank_accounts'] as List? ?? const [])) if (b is Map && b['on_invoices'] != false) Map<String, dynamic>.from(b)];

String bankKey(Map b) => '${b['bank'] ?? ''}|${b['account'] ?? b['iban'] ?? ''}';

/// Formes juridiques proposées (droit OHADA et usages courants).
const legalForms = ['Entreprise individuelle', 'Entreprenant', 'SARL', 'SARLU', 'SA', 'SAS', 'SASU', 'SCS', 'SNC', 'GIE', 'Coopérative (SCOOPS)', 'Association', 'ONG'];

/// Taux proposés sur une ligne : taux normal ou exonéré (0 %), plus le taux déjà saisi s'il est différent.
List<double> vatChoices(Session s, [double? current]) {
  if (!vatSubject(s)) return const [0];
  final list = <double>[standardVatRate(s), 0];
  if (current != null && !list.contains(current)) list.add(current);
  return list;
}

/// Prix HT à partir d'un prix TTC (arrondi au centime, comme la base de données).
double htFromTtc(double ttc, double rate) => ((ttc / (1 + rate / 100)) * 100).roundToDouble() / 100;

/// Prix TTC à partir d'un prix HT.
double ttcFromHt(double ht, double rate, int decimals) {
  final v = ht * (1 + rate / 100);
  return decimals == 0 ? v.roundToDouble() : (v * 100).roundToDouble() / 100;
}

/// Taux affiché sans décimales inutiles (18, 19.25).
String vatPct(double v) => v == v.roundToDouble() ? '${v.toInt()}' : '$v';
