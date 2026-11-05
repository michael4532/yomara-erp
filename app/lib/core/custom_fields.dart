import 'local_db.dart';

/// Champs personnalisés (module « champs ») : définitions synchronisées, valeurs dans « custom ».
const customEntities = {
  'partners': 'Clients & fournisseurs', 'products': 'Articles', 'sales_documents': 'Documents de vente', 'purchase_documents': 'Documents d\'achat',
  'rh_employees': 'Salariés', 'crm_leads': 'Opportunités', 'prj_projects': 'Projets',
};

const customTypes = {
  'text': 'Texte', 'textarea': 'Texte long', 'number': 'Nombre', 'money': 'Montant', 'date': 'Date', 'select': 'Liste de choix',
  'multiselect': 'Choix multiples', 'checkbox': 'Case à cocher', 'url': 'Lien', 'email': 'E-mail', 'phone': 'Téléphone',
};

/// Champs actifs d'un objet, dans l'ordre d'affichage.
List<Map<String, dynamic>> customFieldsFor(String entity) {
  if (!LocalDb.hasEntity('cf_fields')) return const [];
  final all = LocalDb.all('cf_fields').where((f) => f['entity'] == entity && f['active'] != false && f['key'] != null).toList()
    ..sort((a, b) {
      final c = ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0);
      return c != 0 ? c : '${a['label']}'.toLowerCase().compareTo('${b['label']}'.toLowerCase());
    });
  return all;
}

bool customEmpty(dynamic v) => v == null || v == '' || (v is List && v.isEmpty);

/// Valeur affichable (fiches, listes, PDF).
String customDisplay(Map<String, dynamic> f, dynamic v) {
  if (customEmpty(v)) return '';
  switch (f['type']) {
    case 'checkbox':
      return (v == true || v == 1 || v == '1' || v == 'true') ? 'Oui' : 'Non';
    case 'multiselect':
      return (v is List ? v : [v]).join(', ');
    case 'date':
      final d = DateTime.tryParse('$v');
      return d == null ? '$v' : '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';
    case 'number':
    case 'money':
      final n = v is num ? v : num.tryParse('$v'.replaceAll(' ', '').replaceAll(',', '.'));
      if (n == null) return '$v';
      final neg = n < 0;
      final parts = n.abs().toStringAsFixed(n == n.roundToDouble() ? 0 : 2).split('.');
      final buf = StringBuffer();
      final s = parts[0];
      for (var i = 0; i < s.length; i++) {
        if (i > 0 && (s.length - i) % 3 == 0) buf.write(' ');
        buf.write(s[i]);
      }
      return '${neg ? '-' : ''}$buf${parts.length > 1 ? ',${parts[1]}' : ''}';
    default:
      return '$v';
  }
}

/// Champs obligatoires non remplis (contrôle avant enregistrement, hors ligne).
List<String> customMissing(String entity, Map<String, dynamic>? values) {
  final out = <String>[];
  for (final f in customFieldsFor(entity)) {
    if (f['required'] != true) continue;
    final v = values?['${f['key']}'];
    if (customEmpty(v) || (f['type'] == 'checkbox' && v != true)) {
      if (!(customEmpty(v) && !customEmpty(f['default_value']) && values?.containsKey('${f['key']}') != true)) out.add('${f['label']}');
    }
  }
  return out;
}
