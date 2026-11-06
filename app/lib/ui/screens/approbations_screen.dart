import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/approvals.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'document_editor.dart';
import 'documents_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _status = {'pending': 'En attente', 'approved': 'Approuvée', 'rejected': 'Refusée', 'cancelled': 'Annulée'};
const _statusColor = {'pending': YColors.warning, 'approved': YColors.success, 'rejected': YColors.danger, 'cancelled': YColors.muted};
const _decisions = {
  'submitted': 'Demande envoyée', 'approved': 'Accordé', 'rejected': 'Refusé', 'implicit': 'Accord implicite (approbateur de toutes les étapes)',
  'cancelled': 'Demande annulée', 'escalated': 'Escalade',
};

/// Personnes et rôles (formulaires), chargés une fois.
class ApprovalMeta {
  static Map<String, dynamic>? data;

  static Future<Map<String, dynamic>> load(Session s) async {
    if (data != null) return data!;
    final r = await s.api.get('approbations/meta');
    return data = asMap(r['data']);
  }

  static List<Map<String, dynamic>> list(String k) => [for (final x in (data?[k] as List? ?? const [])) asMap(x)];

  static String userName(dynamic id) => '${list('users').firstWhere((u) => '${u['id']}' == '$id', orElse: () => {'name': '#$id'})['name']}';

  static String roleName(dynamic id) => '${list('roles').firstWhere((u) => '${u['id']}' == '$id', orElse: () => {'name': '#$id'})['name']}';
}

/// Bandeau d'état d'approbation (éditeur de document, notes de frais).
class ApprovalBanner extends StatelessWidget {
  const ApprovalBanner({super.key, required this.recordId, this.showApproved = false});

  final String? recordId;
  final bool showApproved;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    if (!s.hasModule('approbations') || recordId == null || !LocalDb.hasEntity('wf_requests')) return const SizedBox.shrink();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('wf_requests'),
      builder: (context, _, _) {
        final r = approvalRequestFor(recordId);
        if (r == null || r['status'] == 'cancelled' || (r['status'] == 'approved' && !showApproved)) return const SizedBox.shrink();
        final steps = (r['steps'] as List?) ?? const [];
        final color = _statusColor[r['status']] ?? YColors.muted;
        final last = ((r['decisions'] as List?) ?? const []).isEmpty ? null : (r['decisions'] as List).last as Map;
        final text = switch (r['status']) {
          'pending' => 'En attente d\'approbation — étape ${toDouble(r['current_step']).toInt() + 1}/${steps.length} : ${r['current_step_name'] ?? ''} (circuit « ${r['rule_name']} »)',
          'rejected' => 'Approbation refusée${last?['user'] != null ? ' par ${last!['user']}' : ''}${last?['comment'] != null ? ' : « ${last!['comment']} »' : ''} — corrigez puis validez à nouveau',
          _ => 'Approuvé (circuit « ${r['rule_name']} »)',
        };
        final mine = approvalMyTurn(r, s.user['id']);
        return Container(
          margin: const EdgeInsets.only(bottom: 12),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(color: color.withValues(alpha: 0.10), borderRadius: BorderRadius.circular(10), border: Border.all(color: color.withValues(alpha: 0.35))),
          child: Row(children: [
            Icon(r['status'] == 'pending' ? Icons.hourglass_top : (r['status'] == 'rejected' ? Icons.block : Icons.verified_outlined), color: color, size: 20),
            const SizedBox(width: 10),
            Expanded(child: Text(tr(text), style: const TextStyle(fontWeight: FontWeight.w600))),
            if (mine) FilledButton(onPressed: () => decideRequest(context, r), child: Text(tr('Décider'))),
            TextButton(onPressed: () => showRequest(context, r), child: Text(tr('Détails'))),
          ]),
        );
      },
    );
  }
}

/// Accord ou refus (motif obligatoire pour un refus).
Future<bool> decideRequest(BuildContext context, Map<String, dynamic> r, {String? preset}) async {
  final comment = TextEditingController();
  final decision = await showDialog<String>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Text(tr('Votre décision')),
      content: SizedBox(
        width: 460,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('${r['label']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 4),
          Text(tr('Demandé par ${r['requester_name'] ?? '—'} · circuit « ${r['rule_name']} » · étape ${toDouble(r['current_step']).toInt() + 1}/${(r['steps'] as List).length}'),
              style: TextStyle(color: Theme.of(d).hintColor, fontSize: 12.5)),
          const SizedBox(height: 12),
          TextField(controller: comment, maxLines: 3, decoration: InputDecoration(labelText: tr('Commentaire'), helperText: tr('Obligatoire en cas de refus'))),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(foregroundColor: YColors.danger),
          onPressed: () {
            if (comment.text.trim().isEmpty) return toast(d, tr('Indiquez le motif du refus.'), error: true);
            Navigator.pop(d, 'rejected');
          },
          icon: const Icon(Icons.close),
          label: Text(tr('Refuser')),
        ),
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: YColors.success),
          onPressed: () => Navigator.pop(d, 'approved'),
          icon: const Icon(Icons.check),
          label: Text(tr('Accorder')),
        ),
      ],
    ),
  );
  if (decision == null || !context.mounted) return false;
  try {
    final res = await context.read<Session>().api.post('approbations/requests/${r['id']}/decide', {'decision': decision, 'comment': comment.text.trim().isEmpty ? null : comment.text.trim()});
    final rec = asMap(res['data']);
    await LocalDb.put('wf_requests', rec);
    if (context.mounted) {
      toast(context, decision == 'rejected' ? 'Demande refusée.' : (rec['status'] == 'approved' ? 'Accordé : demande approuvée.' : 'Accordé : étape suivante.'));
      context.read<SyncEngine>().schedule();
    }
    return true;
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return false;
  }
}

/// Fiche d'une demande : étapes, historique, accès au document.
Future<void> showRequest(BuildContext context, Map<String, dynamic> r) async {
  final s = context.read<Session>();
  try {
    await ApprovalMeta.load(s);
  } catch (_) {}
  if (!context.mounted) return;
  final steps = [for (final x in (r['steps'] as List? ?? const [])) asMap(x)];
  final decisions = [for (final x in (r['decisions'] as List? ?? const [])) asMap(x)];
  final cur = toDouble(r['current_step']).toInt();
  final doc = r['target'] == 'expense' ? null : LocalDb.get(r['target'].toString().startsWith('sale.') ? 'sales_documents' : 'purchase_documents', '${r['record_id']}');
  await showFormDialog(context, title: tr('Demande d\'approbation'), builder: (c) {
    final hint = Theme.of(c).hintColor;
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
      Row(children: [
        Expanded(child: Text(tr('${r['label']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15))),
        StatusChip(_status[r['status']] ?? '${r['status']}', color: _statusColor[r['status']] ?? YColors.muted),
      ]),
      const SizedBox(height: 4),
      Text(tr('${r['target_label']} · circuit « ${r['rule_name']} » · demandé par ${r['requester_name'] ?? '—'} le ${dateTimeFr(r['created_at'])}'), style: TextStyle(color: hint, fontSize: 12.5)),
      if (r['note'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('${r['note']}'), style: const TextStyle(color: YColors.warning))),
      const SizedBox(height: 16),
      Text(tr('Étapes'), style: TextStyle(fontWeight: FontWeight.w700)),
      for (var i = 0; i < steps.length; i++)
        ListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          leading: CircleAvatar(
            radius: 13,
            backgroundColor: (i < cur || r['status'] == 'approved') ? YColors.success : (i == cur && r['status'] == 'pending' ? YColors.warning : Theme.of(c).dividerColor),
            child: (i < cur || r['status'] == 'approved') ? const Icon(Icons.check, size: 14, color: Colors.white) : Text(tr('${i + 1}'), style: const TextStyle(fontSize: 12, color: Colors.white)),
          ),
          title: Text(tr('${steps[i]['name']}${steps[i]['mode'] == 'all' ? ' — tous doivent accorder' : ''}')),
          subtitle: Text(tr([
            if (steps[i]['manager'] == true) 'responsable hiérarchique',
            ...[for (final x in (steps[i]['roles'] as List? ?? const [])) ApprovalMeta.roleName(x)],
            ...[for (final x in (steps[i]['users'] as List? ?? const [])) ApprovalMeta.userName(x)],
            if (steps[i]['escalate_after_hours'] != null) 'escalade après ${steps[i]['escalate_after_hours']} h',
          ].join(' · '))),
        ),
      const SizedBox(height: 8),
      Text(tr('Historique'), style: TextStyle(fontWeight: FontWeight.w700)),
      for (final d in decisions)
        ListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          leading: Icon(switch (d['decision']) { 'approved' || 'implicit' => Icons.check_circle_outline, 'rejected' => Icons.cancel_outlined, 'escalated' => Icons.trending_up, _ => Icons.send_outlined },
              color: switch (d['decision']) { 'approved' || 'implicit' => YColors.success, 'rejected' => YColors.danger, _ => hint }),
          title: Text(tr('${_decisions[d['decision']] ?? d['decision']}${d['user'] != null ? ' — ${d['user']}' : ''}${d['on_behalf_of'] != null ? ' pour ${d['on_behalf_of']}' : ''}')),
          subtitle: Text(tr('${dateTimeFr(d['at'])}${d['comment'] != null ? ' · « ${d['comment']} »' : ''}')),
        ),
      const SizedBox(height: 12),
      Wrap(spacing: 8, runSpacing: 8, children: [
        if (doc != null)
          OutlinedButton.icon(
            onPressed: () {
              Navigator.pop(c);
              final sale = '${r['target']}'.startsWith('sale.');
              Navigator.of(context).push(MaterialPageRoute(builder: (_) => DocumentEditor(config: sale ? DocConfig.sale : DocConfig.purchase, document: doc, initialType: '${doc['type']}')));
            },
            icon: const Icon(Icons.open_in_new),
            label: Text(tr('Ouvrir le document')),
          ),
        if (r['status'] == 'pending' && ('${r['requested_by']}' == '${s.user['id']}' || s.can('approbations.manage')))
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            onPressed: () async {
              if (!await confirm(c, tr('Annuler la demande ?'), tr('Le document reste en brouillon.'), danger: true)) return;
              try {
                final res = await s.api.post('approbations/requests/${r['id']}/cancel');
                await LocalDb.put('wf_requests', asMap(res['data']));
                if (c.mounted) Navigator.pop(c);
              } catch (e) {
                if (c.mounted) toast(c, errorText(e), error: true);
              }
            },
            icon: const Icon(Icons.undo),
            label: Text(tr('Annuler la demande')),
          ),
        if (approvalMyTurn(r, s.user['id']))
          FilledButton.icon(
            onPressed: () async {
              Navigator.pop(c);
              await decideRequest(context, r);
            },
            icon: const Icon(Icons.gavel),
            label: Text(tr('Décider')),
          ),
      ]),
    ]);
  });
}

// ============================================================================
// Écran
// ============================================================================

class ApprobationsScreen extends StatefulWidget {
  const ApprobationsScreen({super.key});

  @override
  State<ApprobationsScreen> createState() => _ApprobationsScreenState();
}

class _ApprobationsScreenState extends State<ApprobationsScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  late final bool _manage;
  late final bool _viewAll;

  @override
  void initState() {
    super.initState();
    final s = context.read<Session>();
    _manage = s.can('approbations.manage');
    _viewAll = _manage || s.can('approbations.view');
    _tabs = TabController(length: 3 + (_viewAll ? 1 : 0) + (_manage ? 1 : 0), vsync: this);
    ApprovalMeta.load(s).then((_) {
      if (mounted) setState(() {});
    }).catchError((_) {});
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      PageHeader(title: tr('Approbations'), subtitle: tr('Remises, gros achats et notes de frais validés par les bonnes personnes, dans le bon ordre')),
      ValueListenableBuilder(
        valueListenable: LocalDb.listen('wf_requests'),
        builder: (context, _, _) {
          final n = LocalDb.all('wf_requests').where((r) => approvalMyTurn(r, s.user['id'])).length;
          return TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
            Tab(child: Row(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('À approuver')),
              if (n > 0) ...[
                const SizedBox(width: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
                  decoration: BoxDecoration(color: YColors.danger, borderRadius: BorderRadius.circular(10)),
                  child: Text(tr('$n'), style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                ),
              ],
            ])),
            Tab(text: tr('Mes demandes')),
            if (_viewAll) Tab(text: tr('Toutes')),
            if (_manage) Tab(text: tr('Circuits')),
            Tab(text: tr('Délégations')),
          ]);
        },
      ),
      const Divider(height: 1),
      Expanded(
        child: TabBarView(controller: _tabs, children: [
          _RequestList(filter: (r) => approvalMyTurn(r, s.user['id']), empty: 'Rien à approuver pour le moment.', decide: true),
          _RequestList(filter: (r) => '${r['requested_by']}' == '${s.user['id']}', empty: 'Vous n\'avez envoyé aucune demande.'),
          if (_viewAll) _RequestList(filter: (_) => true, empty: 'Aucune demande d\'approbation.', withStatusFilter: true),
          if (_manage) const _RulesTab(),
          const _DelegationsTab(),
        ]),
      ),
    ]);
  }
}

class _RequestList extends StatefulWidget {
  const _RequestList({required this.filter, required this.empty, this.decide = false, this.withStatusFilter = false});

  final bool Function(Map<String, dynamic>) filter;
  final String empty;
  final bool decide;
  final bool withStatusFilter;

  @override
  State<_RequestList> createState() => _RequestListState();
}

class _RequestListState extends State<_RequestList> {
  String _st = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _rk = 0;

  @override
  Widget build(BuildContext context) {
    final narrow = MediaQuery.of(context).size.width < 700;
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('wf_requests'),
      builder: (context, _, _) {
        final all = LocalDb.all('wf_requests').where(widget.filter).toList();
        final q = _q.toLowerCase();
        final base = all
            .where((r) => q.isEmpty || '${r['label'] ?? ''} ${r['rule_name'] ?? ''} ${r['requester_name'] ?? ''}'.toLowerCase().contains(q))
            .where((r) => _f['target'] == null || '${r['target']}' == _f['target'])
            .where((r) => _f['rule'] == null || '${r['rule_name'] ?? ''}' == _f['rule'])
            .where((r) => _f['requester'] == null || '${r['requester_name'] ?? ''}' == _f['requester'])
            .where((r) => _period == null || _period!.contains(DateTime.tryParse('${r['created_at'] ?? ''}')?.toLocal()))
            .toList();
        final showStatus = widget.withStatusFilter || !widget.decide;
        final list = base.where((r) => _st.isEmpty || r['status'] == _st).toList()
          ..sort((a, b) => '${b['created_at']}'.compareTo('${a['created_at']}'));
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (all.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: FilterBar(
                key: ValueKey(_rk),
                padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 0),
                onSearch: (v) => setState(() => _q = v),
                search: _q,
                searchHint: 'Document, circuit, demandeur…',
                statuses: showStatus ? {'': 'Toutes', ..._status} : null,
                status: _st,
                onStatus: (v) => setState(() => _st = v),
                statusCounts: {'': base.length, for (final k in _status.keys) k: base.where((r) => r['status'] == k).length},
                filters: [
                  FilterSpec('target', 'Type', {for (final e in approvalTargets.entries) if (all.any((r) => r['target'] == e.key)) e.key: e.value}, icon: Icons.category_outlined),
                  FilterSpec('rule', 'Circuit', filterOptions(all, 'rule_name'), icon: Icons.account_tree_outlined),
                  if (widget.decide || widget.withStatusFilter) FilterSpec('requester', 'Demandeur', filterOptions(all, 'requester_name'), icon: Icons.person_outline),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                showPeriod: true,
                period: _period,
                onPeriod: (p) => setState(() => _period = p),
                count: list.length,
                countLabel: 'demande',
                onReset: () => setState(() => (_q = '', _st = '', _f = {}, _period = null, _rk++)),
              ),
            ),
          Expanded(
            child: list.isEmpty
                ? EmptyState(icon: all.isEmpty ? Icons.approval_outlined : Icons.filter_alt_off_outlined, title: all.isEmpty ? widget.empty : tr('Aucune demande ne correspond aux filtres'))
                : ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 12, narrow ? 16 : 24, 24), children: [
                    for (final r in list)
                      Card(
                        child: ListTile(
                          onTap: () => showRequest(context, r),
                          leading: CircleAvatar(
                            backgroundColor: (_statusColor[r['status']] ?? YColors.muted).withValues(alpha: 0.12),
                            child: Icon(r['target'] == 'expense' ? Icons.receipt_long_outlined : ('${r['target']}'.startsWith('sale') ? Icons.request_quote_outlined : Icons.shopping_cart_outlined),
                                color: _statusColor[r['status']] ?? YColors.muted, size: 20),
                          ),
                          title: Text(tr('${r['label']}'), style: const TextStyle(fontWeight: FontWeight.w600), maxLines: 2, overflow: TextOverflow.ellipsis),
                          subtitle: Text(tr([
                            '${r['rule_name']}',
                            if (r['status'] == 'pending') 'étape ${toDouble(r['current_step']).toInt() + 1}/${(r['steps'] as List).length} : ${r['current_step_name'] ?? ''}',
                            'par ${r['requester_name'] ?? '—'}',
                            dateTimeFr(r['created_at']),
                          ].join(' · '))),
                          trailing: widget.decide && approvalMyTurn(r, s.user['id'])
                              ? FilledButton(onPressed: () => decideRequest(context, r), child: Text(tr('Décider')))
                              : StatusChip(_status[r['status']] ?? '${r['status']}', color: _statusColor[r['status']] ?? YColors.muted),
                        ),
                      ),
                  ]),
          ),
        ]);
      },
    );
  }
}

// ---------------------------------------------------------------- circuits

class _RulesTab extends StatefulWidget {
  const _RulesTab();

  @override
  State<_RulesTab> createState() => _RulesTabState();
}

class _RulesTabState extends State<_RulesTab> {
  String _q = '';
  String _st = '';
  Map<String, String?> _f = {};
  int _rk = 0;

  String _conditions(Map<String, dynamic> r, String cur) {
    final ht = ((r['targets'] as List?) ?? const []).any((t) => t != 'expense') ? ' HT' : '';
    return [
        if (r['min_amount'] != null) 'à partir de ${money(toDouble(r['min_amount']), cur)}$ht',
        if (r['max_amount'] != null) 'jusqu\'à ${money(toDouble(r['max_amount']), cur)}$ht',
        if (r['discount_over'] != null) 'remise > ${number(toDouble(r['discount_over']), decimals: toDouble(r['discount_over']) == toDouble(r['discount_over']).roundToDouble() ? 0 : 1)} %',
        if ((r['partner_ids'] as List?)?.isNotEmpty == true) '${(r['partner_ids'] as List).length} tiers',
        if ((r['category_ids'] as List?)?.isNotEmpty == true) '${(r['category_ids'] as List).length} catégorie(s)',
      ].join(' · ');
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final narrow = MediaQuery.of(context).size.width < 700;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('wf_rules'),
      builder: (context, _, _) {
        final allRules = LocalDb.all('wf_rules')..sort((a, b) => toDouble(a['sort']).compareTo(toDouble(b['sort'])));
        final q = _q.toLowerCase();
        final base = allRules
            .where((r) => q.isEmpty || '${r['name'] ?? ''} ${[for (final st in (r['steps'] as List? ?? const [])) '${st['name']}'].join(' ')}'.toLowerCase().contains(q))
            .where((r) => _f['target'] == null || ((r['targets'] as List?) ?? const []).contains(_f['target']))
            .where((r) => _f['auto'] == null || (r['auto_validate'] == true) == (_f['auto'] == 'yes'))
            .toList();
        bool st(Map<String, dynamic> r, String k) => k.isEmpty || (k == 'active' ? r['active'] != false : r['active'] == false);
        final rules = base.where((r) => st(r, _st)).toList();
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 14, narrow ? 16 : 24, 8),
            child: Row(children: [
              Expanded(child: Text(tr('Le premier circuit qui correspond s\'applique (ordre, puis montant le plus élevé).'), style: TextStyle(color: Theme.of(context).hintColor))),
              FilledButton.icon(onPressed: () => editRule(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau circuit'))),
            ]),
          ),
          if (allRules.isNotEmpty)
            FilterBar(
              key: ValueKey(_rk),
              padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 10),
              onSearch: (v) => setState(() => _q = v),
              search: _q,
              searchHint: 'Nom du circuit, étape…',
              statuses: const {'': 'Tous', 'active': 'Actifs', 'inactive': 'Désactivés'},
              status: _st,
              onStatus: (v) => setState(() => _st = v),
              statusCounts: {for (final k in ['', 'active', 'inactive']) k: base.where((r) => st(r, k)).length},
              filters: [
                FilterSpec('target', 'Document', {for (final e in approvalTargets.entries) if (allRules.any((r) => ((r['targets'] as List?) ?? const []).contains(e.key))) e.key: e.value}, icon: Icons.category_outlined),
                const FilterSpec('auto', 'Validation auto', {'yes': 'Avec validation automatique', 'no': 'Sans validation automatique'}, icon: Icons.bolt_outlined),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: rules.length,
              countLabel: 'circuit',
              onReset: () => setState(() => (_q = '', _st = '', _f = {}, _rk++)),
            ),
          Expanded(
            child: allRules.isNotEmpty && rules.isEmpty
                ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun circuit ne correspond aux filtres'))
                : rules.isEmpty
                ? EmptyState(icon: Icons.account_tree_outlined, title: tr('Aucun circuit'), message: tr('Exemple : devis avec plus de 15 % de remise : accord de la Direction.'))
                : ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24), children: [
                    for (final r in rules)
                      Card(
                        child: ListTile(
                          onTap: () => editRule(context, r),
                          leading: Icon(Icons.account_tree_outlined, color: r['active'] == false ? Theme.of(context).hintColor : YColors.blue),
                          title: Text(tr('${r['name']}'), style: TextStyle(fontWeight: FontWeight.w600, decoration: r['active'] == false ? TextDecoration.lineThrough : null)),
                          subtitle: Text(tr([
                            ((r['targets'] as List?) ?? const []).map((t) => approvalTargets[t] ?? t).join(', '),
                            _conditions(r, cur),
                            [for (final st in (r['steps'] as List? ?? const [])) '${st['name']}'].join(' › '),
                          ].where((x) => x.isNotEmpty).join('\n'))),
                          isThreeLine: true,
                          trailing: r['auto_validate'] == true ? Tooltip(message: tr('Validation automatique après accord'), child: Icon(Icons.bolt, color: YColors.warning)) : null,
                        ),
                      ),
                  ]),
          ),
        ]);
      },
    );
  }
}

Future<void> editRule(BuildContext context, [Map<String, dynamic>? rule]) async {
  try {
    await ApprovalMeta.load(context.read<Session>());
  } catch (e) {
    if (context.mounted) toast(context, tr('Connexion nécessaire pour charger les rôles et les personnes.'), error: true);
    return;
  }
  if (!context.mounted) return;
  await showFormDialog(context, title: rule == null ? 'Nouveau circuit' : 'Modifier le circuit', builder: (_) => _RuleForm(rule: rule));
}

class _RuleForm extends StatefulWidget {
  const _RuleForm({this.rule});

  final Map<String, dynamic>? rule;

  @override
  State<_RuleForm> createState() => _RuleFormState();
}

class _RuleFormState extends State<_RuleForm> {
  late final _name = TextEditingController(text: '${widget.rule?['name'] ?? ''}');
  late final Set<String> _targets = {for (final t in (widget.rule?['targets'] as List? ?? const [])) '$t'};
  late final _min = TextEditingController(text: widget.rule?['min_amount'] == null ? '' : '${toDouble(widget.rule!['min_amount']).round()}');
  late final _max = TextEditingController(text: widget.rule?['max_amount'] == null ? '' : '${toDouble(widget.rule!['max_amount']).round()}');
  late final _disc = TextEditingController(text: widget.rule?['discount_over'] == null ? '' : number(toDouble(widget.rule!['discount_over']), decimals: 1).replaceAll(',0', ''));
  late final Set<String> _cats = {for (final t in (widget.rule?['category_ids'] as List? ?? const [])) '$t'};
  late final List<Map<String, dynamic>> _steps = [for (final s in (widget.rule?['steps'] as List? ?? const [{'name': 'Direction', 'mode': 'any', 'roles': [], 'users': []}])) asMap(s)];
  late bool _auto = widget.rule?['auto_validate'] != false;
  late bool _active = widget.rule?['active'] != false;
  late int _remind = toDouble(widget.rule?['reminder_hours'] ?? 24).toInt();
  late final _sort = TextEditingController(text: '${widget.rule?['sort'] ?? 0}');

  double? _num(TextEditingController c) => c.text.trim().isEmpty ? null : double.tryParse(c.text.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.'));

  Set<int> _ids(dynamic l) => {for (final x in (l as List? ?? const [])) toDouble(x).toInt()};

  @override
  Widget build(BuildContext context) {
    final roles = ApprovalMeta.list('roles');
    final users = ApprovalMeta.list('users');
    final cur = context.read<Session>().currency;
    final expenseCats = LocalDb.hasEntity('nf_categories') ? LocalDb.all('nf_categories') : <Map<String, dynamic>>[];
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Field(label: tr('Nom du circuit'), child: TextField(controller: _name, decoration: InputDecoration(hintText: tr('Ex. : Remises importantes')))),
      Field(
        label: tr('S\'applique à'),
        child: Wrap(spacing: 6, runSpacing: 6, children: [
          for (final e in approvalTargets.entries)
            FilterChip(label: Text(tr(e.value)), selected: _targets.contains(e.key), onSelected: (v) => setState(() => v ? _targets.add(e.key) : _targets.remove(e.key))),
        ]),
      ),
      Field(
        label: tr('Conditions (toutes doivent être remplies)'),
        child: Column(children: [
          Row(children: [
            Expanded(child: TextField(controller: _min, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant HT à partir de'), suffixText: trn(cur)))),
            const SizedBox(width: 8),
            Expanded(child: TextField(controller: _max, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('jusqu\'à (facultatif)'), suffixText: trn(cur)))),
          ]),
          const SizedBox(height: 8),
          TextField(controller: _disc, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('Remise sur une ligne supérieure à'), suffixText: '%', helperText: tr('Ventes : déclenche le circuit dès qu\'une ligne dépasse cette remise'))),
          if (_targets.contains('expense') && expenseCats.isNotEmpty) ...[
            const SizedBox(height: 10),
            Align(alignment: Alignment.centerLeft, child: Text(tr('Catégories de frais concernées (toutes si aucune)'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
            const SizedBox(height: 4),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final c in expenseCats)
                FilterChip(label: Text(tr('${c['name']}')), selected: _cats.contains(c['id']), onSelected: (v) => setState(() => v ? _cats.add('${c['id']}') : _cats.remove('${c['id']}'))),
            ]),
          ],
        ]),
      ),
      Text(tr('Étapes'), style: TextStyle(fontWeight: FontWeight.w700)),
      const SizedBox(height: 6),
      for (var i = 0; i < _steps.length; i++)
        Card(
          margin: const EdgeInsets.only(bottom: 10),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(children: [
                CircleAvatar(radius: 12, child: Text(tr('${i + 1}'), style: const TextStyle(fontSize: 12))),
                const SizedBox(width: 10),
                Expanded(
                  child: TextFormField(
                    initialValue: '${_steps[i]['name'] ?? ''}',
                    decoration: InputDecoration(isDense: true, labelText: tr('Nom de l\'étape')),
                    onChanged: (v) => _steps[i]['name'] = v,
                  ),
                ),
                if (_steps.length > 1) IconButton(tooltip: tr('Retirer'), onPressed: () => setState(() => _steps.removeAt(i)), icon: const Icon(Icons.delete_outline, size: 20)),
              ]),
              const SizedBox(height: 8),
              SegmentedButton<String>(
                segments: [ButtonSegment(value: 'any', label: Text(tr('L\'un d\'eux'))), ButtonSegment(value: 'all', label: Text(tr('Tous')))],
                selected: {'${_steps[i]['mode'] ?? 'any'}'},
                onSelectionChanged: (v) => setState(() => _steps[i]['mode'] = v.first),
              ),
              const SizedBox(height: 8),
              // Hiérarchie : le responsable direct du demandeur (sinon la fonction au-dessus de la sienne)
              FilterChip(
                avatar: const Icon(Icons.supervisor_account_outlined, size: 18),
                label: Text(tr('Responsable hiérarchique du demandeur')),
                selected: _steps[i]['manager'] == true,
                onSelected: (v) => setState(() => _steps[i]['manager'] = v),
              ),
              const SizedBox(height: 8),
              Text(tr('Rôles'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
              Wrap(spacing: 6, runSpacing: 4, children: [
                for (final r in roles)
                  FilterChip(
                    label: Text(tr('${r['name']}')),
                    selected: _ids(_steps[i]['roles']).contains(toDouble(r['id']).toInt()),
                    onSelected: (v) => setState(() {
                      final s = _ids(_steps[i]['roles']);
                      v ? s.add(toDouble(r['id']).toInt()) : s.remove(toDouble(r['id']).toInt());
                      _steps[i]['roles'] = s.toList();
                    }),
                  ),
              ]),
              const SizedBox(height: 6),
              Text(tr('Ou des personnes'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
              Wrap(spacing: 6, runSpacing: 4, children: [
                for (final u in users)
                  FilterChip(
                    label: Text(tr('${u['name']}')),
                    selected: _ids(_steps[i]['users']).contains(toDouble(u['id']).toInt()),
                    onSelected: (v) => setState(() {
                      final s = _ids(_steps[i]['users']);
                      v ? s.add(toDouble(u['id']).toInt()) : s.remove(toDouble(u['id']).toInt());
                      _steps[i]['users'] = s.toList();
                    }),
                  ),
              ]),
              const SizedBox(height: 8),
              Row(children: [
                SizedBox(
                  width: 170,
                  child: DropdownButtonFormField<int?>(
                    initialValue: _steps[i]['escalate_after_hours'] == null ? null : toDouble(_steps[i]['escalate_after_hours']).toInt(),
                    isExpanded: true,
                    decoration: InputDecoration(isDense: true, labelText: tr('Escalade')),
                    items: [
                      DropdownMenuItem<int?>(value: null, child: Text(tr('Aucune'))),
                      DropdownMenuItem<int?>(value: 4, child: Text(tr('après 4 h'))),
                      DropdownMenuItem<int?>(value: 8, child: Text(tr('après 8 h'))),
                      DropdownMenuItem<int?>(value: 24, child: Text(tr('après 24 h'))),
                      DropdownMenuItem<int?>(value: 48, child: Text(tr('après 48 h'))),
                      DropdownMenuItem<int?>(value: 72, child: Text(tr('après 72 h'))),
                    ],
                    onChanged: (v) => setState(() => _steps[i]['escalate_after_hours'] = v),
                  ),
                ),
                const SizedBox(width: 8),
                if (_steps[i]['escalate_after_hours'] != null)
                  Expanded(
                    child: DropdownButtonFormField<int?>(
                      initialValue: _ids(_steps[i]['escalate_roles']).isEmpty ? null : _ids(_steps[i]['escalate_roles']).first,
                      isExpanded: true,
                      decoration: InputDecoration(isDense: true, labelText: tr('vers le rôle')),
                      items: [for (final r in roles) DropdownMenuItem<int?>(value: toDouble(r['id']).toInt(), child: Text(tr('${r['name']}')))],
                      onChanged: (v) => setState(() => _steps[i]['escalate_roles'] = v == null ? <int>[] : [v]),
                    ),
                  ),
              ]),
            ]),
          ),
        ),
      if (_steps.length < 5)
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: () => setState(() => _steps.add({'name': 'Étape ${_steps.length + 1}', 'mode': 'any', 'roles': <int>[], 'users': <int>[]})),
            icon: const Icon(Icons.add),
            label: Text(tr('Ajouter une étape')),
          ),
        ),
      const SizedBox(height: 6),
      SwitchListTile(contentPadding: EdgeInsets.zero, value: _auto, onChanged: (v) => setState(() => _auto = v), title: Text(tr('Valider le document automatiquement après le dernier accord'))),
      Row(children: [
        Expanded(
          child: DropdownButtonFormField<int>(
            initialValue: [0, 4, 8, 24, 48, 72].contains(_remind) ? _remind : 24,
            decoration: InputDecoration(labelText: tr('Relance des approbateurs')),
            items: [
              DropdownMenuItem(value: 0, child: Text(tr('Jamais'))),
              DropdownMenuItem(value: 4, child: Text(tr('Toutes les 4 h'))),
              DropdownMenuItem(value: 8, child: Text(tr('Toutes les 8 h'))),
              DropdownMenuItem(value: 24, child: Text(tr('Chaque jour'))),
              DropdownMenuItem(value: 48, child: Text(tr('Tous les 2 jours'))),
              DropdownMenuItem(value: 72, child: Text(tr('Tous les 3 jours'))),
            ],
            onChanged: (v) => setState(() => _remind = v ?? 24),
          ),
        ),
        const SizedBox(width: 12),
        SizedBox(width: 90, child: TextField(controller: _sort, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Ordre')))),
      ]),
      SwitchListTile(contentPadding: EdgeInsets.zero, value: _active, onChanged: (v) => setState(() => _active = v), title: Text(tr('Circuit actif'))),
      const SizedBox(height: 8),
      Row(children: [
        if (widget.rule != null)
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer ce circuit ?'), tr('Les demandes en cours continuent jusqu\'à leur terme.'), danger: true) || !context.mounted) return;
              await context.read<SyncEngine>().remove('wf_rules', '${widget.rule!['id']}');
              if (context.mounted) Navigator.pop(context);
            },
            icon: const Icon(Icons.delete_outline),
            label: Text(tr('Supprimer')),
          ),
        const Spacer(),
        SaveButton(
          onPressed: () async {
            if (_name.text.trim().isEmpty) return toast(context, tr('Donnez un nom au circuit.'), error: true);
            if (_targets.isEmpty) return toast(context, tr('Choisissez au moins un type de document.'), error: true);
            for (var i = 0; i < _steps.length; i++) {
              if (_ids(_steps[i]['roles']).isEmpty && _ids(_steps[i]['users']).isEmpty && _steps[i]['manager'] != true) return toast(context, tr('Étape ${i + 1} : choisissez le responsable hiérarchique, un rôle ou une personne.'), error: true);
            }
            await context.read<SyncEngine>().save('wf_rules', {
              'id': widget.rule?['id'] ?? newId(), 'name': _name.text.trim(), 'targets': _targets.toList(),
              'min_amount': _num(_min), 'max_amount': _num(_max), 'discount_over': _num(_disc), 'category_ids': _cats.isEmpty ? null : _cats.toList(),
              'steps': _steps, 'auto_validate': _auto, 'reminder_hours': _remind, 'sort': int.tryParse(_sort.text) ?? 0, 'active': _active,
            });
            if (context.mounted) Navigator.pop(context);
          },
          child: Text(tr('Enregistrer')),
        ),
      ]),
    ]);
  }
}

// ---------------------------------------------------------------- délégations

class _DelegationsTab extends StatefulWidget {
  const _DelegationsTab();

  @override
  State<_DelegationsTab> createState() => _DelegationsTabState();
}

class _DelegationsTabState extends State<_DelegationsTab> {
  List<Map<String, dynamic>>? _list;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('approbations/delegations');
      if (mounted) setState(() => _list = [for (final x in r['data'] as List) asMap(x)]);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _add() async {
    final s = context.read<Session>();
    try {
      await ApprovalMeta.load(s);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!mounted) return;
    int? delegate;
    int? from;
    var range = DateTimeRange(start: DateTime.now(), end: DateTime.now().add(const Duration(days: 7)));
    final note = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) => YAlertDialog(
          title: Text(tr('Déléguer mes approbations')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              if (s.can('approbations.manage'))
                DropdownButtonFormField<int?>(
                  initialValue: from,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: tr('Approbations de')),
                  items: [DropdownMenuItem<int?>(value: null, child: Text(tr('Moi'))), for (final u in ApprovalMeta.list('users')) DropdownMenuItem<int?>(value: toDouble(u['id']).toInt(), child: Text(tr('${u['name']}')))],
                  onChanged: (v) => set(() => from = v),
                ),
              DropdownButtonFormField<int>(
                initialValue: delegate,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Confiées à')),
                items: [
                  for (final u in ApprovalMeta.list('users'))
                    if ('${u['id']}' != '${from ?? s.user['id']}') DropdownMenuItem(value: toDouble(u['id']).toInt(), child: Text(tr('${u['name']}'))),
                ],
                onChanged: (v) => set(() => delegate = v),
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: () async {
                  final r = await showDateRangePicker(context: d, firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime(2100), initialDateRange: range);
                  if (r != null) set(() => range = r);
                },
                icon: const Icon(Icons.date_range),
                label: Text(tr('Du ${dateFr(isoDate(range.start))} au ${dateFr(isoDate(range.end))}')),
              ),
              TextField(controller: note, decoration: InputDecoration(labelText: tr('Motif (facultatif)'), hintText: tr('Congés, mission…'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Déléguer')))],
        ),
      ),
    );
    if (ok != true || delegate == null || !mounted) return;
    try {
      await s.api.post('approbations/delegations', {'user_id': ?from, 'delegate_id': delegate, 'starts_on': isoDate(range.start), 'ends_on': isoDate(range.end), 'note': note.text.trim().isEmpty ? null : note.text.trim()});
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_list == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final narrow = MediaQuery.of(context).size.width < 700;
    final me = context.read<Session>().user['id'];
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Padding(
        padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 14, narrow ? 16 : 24, 8),
        child: Row(children: [
          Expanded(child: Text(tr('Pendant une absence, un collègue approuve à votre place (et pour votre compte).'), style: TextStyle(color: Theme.of(context).hintColor))),
          FilledButton.icon(onPressed: _add, icon: const Icon(Icons.person_add_alt), label: Text(tr('Déléguer'))),
        ]),
      ),
      Expanded(
        child: _list!.isEmpty
            ? EmptyState(icon: Icons.people_outline, title: tr('Aucune délégation'))
            : ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24), children: [
                for (final d in _list!)
                  Card(
                    child: ListTile(
                      leading: Icon(Icons.swap_horiz, color: d['active'] == true ? YColors.success : Theme.of(context).hintColor),
                      title: Text(tr('${d['user_name']} › ${d['delegate_name']}')),
                      subtitle: Text(tr('Du ${dateFr(d['starts_on'])} au ${dateFr(d['ends_on'])}${d['note'] != null ? ' · ${d['note']}' : ''}${d['active'] == true ? ' · en cours' : ''}')),
                      trailing: '${d['user_id']}' == '$me' || context.read<Session>().can('approbations.manage')
                          ? IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                try {
                                  await context.read<Session>().api.delete('approbations/delegations/${d['id']}');
                                  _load();
                                } catch (e) {
                                  if (context.mounted) toast(context, errorText(e), error: true);
                                }
                              },
                            )
                          : null,
                    ),
                  ),
              ]),
      ),
    ]);
  }
}
