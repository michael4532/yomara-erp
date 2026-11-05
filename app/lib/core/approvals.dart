import 'local_db.dart';

/// Circuits d'approbation (module « approbations ») : circuits et demandes synchronisés.
const approvalTargets = {
  'sale.quote': 'Devis', 'sale.proforma': 'Facture proforma', 'sale.order': 'Commande client', 'sale.invoice': 'Facture client', 'sale.credit_note': 'Avoir client',
  'purchase.rfq': 'Demande de prix', 'purchase.purchase_order': 'Bon de commande', 'purchase.supplier_invoice': 'Facture fournisseur',
  'expense': 'Note de frais',
};

double _d(dynamic v) => v is num ? v.toDouble() : double.tryParse('${v ?? ''}') ?? 0;

/// Dernière demande d'approbation d'un document ou d'une note de frais.
Map<String, dynamic>? approvalRequestFor(String? recordId) {
  if (recordId == null || !LocalDb.hasEntity('wf_requests')) return null;
  final list = LocalDb.all('wf_requests').where((r) => r['record_id'] == recordId).toList()
    ..sort((a, b) => '${b['created_at']}'.compareTo('${a['created_at']}'));
  return list.isEmpty ? null : list.first;
}

/// Circuit applicable (même règle de choix que le serveur : ordre, puis montant minimum décroissant).
Map<String, dynamic>? approvalRuleFor({required String target, required double amount, double discount = 0, String? partnerId, String? categoryId}) {
  if (!LocalDb.hasEntity('wf_rules')) return null;
  final rules = LocalDb.all('wf_rules').where((r) => r['active'] != false).toList()
    ..sort((a, b) {
      final c = _d(a['sort']).compareTo(_d(b['sort']));
      return c != 0 ? c : _d(b['min_amount']).compareTo(_d(a['min_amount']));
    });
  for (final r in rules) {
    if (!((r['targets'] as List?) ?? const []).contains(target)) continue;
    if (r['min_amount'] != null && amount < _d(r['min_amount']) - 0.004) continue;
    if (r['max_amount'] != null && amount > _d(r['max_amount']) + 0.004) continue;
    if (r['discount_over'] != null && discount <= _d(r['discount_over'])) continue;
    final partners = (r['partner_ids'] as List?) ?? const [];
    if (partners.isNotEmpty && !partners.contains(partnerId)) continue;
    final cats = (r['category_ids'] as List?) ?? const [];
    if (cats.isNotEmpty && !cats.contains(categoryId)) continue;
    return r;
  }
  return null;
}

/// L'utilisateur est approbateur de toutes les étapes : le serveur accordera sans attente.
bool approvalImplicitFor(Map<String, dynamic> rule, Map<String, dynamic> user) {
  final me = (user['id'] as num?)?.toInt();
  final role = (user['role_id'] as num?)?.toInt();
  for (final s in (rule['steps'] as List? ?? const [])) {
    final users = [for (final u in (s['users'] as List? ?? const [])) (u as num).toInt()];
    final roles = [for (final x in (s['roles'] as List? ?? const [])) (x as num).toInt()];
    if (!users.contains(me) && !roles.contains(role)) return false;
    if (s['mode'] == 'all' && (users.length + roles.length) > 1) return false;
  }
  return true;
}

/// La demande attend une décision de cet utilisateur.
bool approvalMyTurn(Map<String, dynamic> r, dynamic userId) =>
    r['status'] == 'pending' && ((r['current_approver_ids'] as List?) ?? const []).map((e) => '$e').contains('$userId');
