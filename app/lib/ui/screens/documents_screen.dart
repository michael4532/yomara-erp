import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../../core/approvals.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/order_notify_dialog.dart';
import 'achats_screen.dart' show PurchaseToolsButton;
import 'document_editor.dart';
import '../../i18n/i18n.dart';

enum DocDirection { sale, purchase }

/// Paramètres propres aux ventes / achats.
class DocConfig {
  const DocConfig({
    required this.entity,
    required this.paymentEntity,
    required this.title,
    required this.types,
    required this.payableTypes,
    required this.conversions,
    required this.partnerLabel,
    required this.partnerType,
    required this.managePerm,
    required this.paymentPerm,
    this.deletePerm,
    this.validatePerm,
  });

  final String entity;
  final String paymentEntity;
  final String title;
  final Map<String, String> types;
  final List<String> payableTypes;
  final Map<String, List<String>> conversions;
  final String partnerLabel;
  final String partnerType;
  final String managePerm;

  /// Droit de supprimer (distinct de la modification) et de valider ; null = droit de gestion.
  final String? deletePerm;
  final String? validatePerm;
  final String paymentPerm;

  static DocConfig of(DocDirection d) => d == DocDirection.sale ? sale : purchase;

  static final sale = DocConfig(
    entity: 'sales_documents',
    paymentEntity: 'sales_payments',
    title: tr('Ventes'),
    types: {'quote': 'Devis', 'proforma': 'Facture proforma', 'order': 'Commande', 'invoice': 'Facture', 'credit_note': 'Avoir'},
    payableTypes: ['invoice'],
    conversions: {
      'quote': ['proforma', 'order', 'invoice'],
      'proforma': ['invoice', 'order'],
      'order': ['invoice'],
      'invoice': ['credit_note'],
    },
    partnerLabel: 'Client',
    partnerType: 'customer',
    managePerm: 'ventes.manage',
    paymentPerm: 'ventes.payments.manage',
    deletePerm: 'ventes.delete',
    validatePerm: 'ventes.validate',
  );

  static final purchase = DocConfig(
    entity: 'purchase_documents',
    paymentEntity: 'purchase_payments',
    title: tr('Achats'),
    types: {'rfq': 'Demande de prix', 'purchase_order': 'Bon de commande', 'supplier_invoice': 'Facture fournisseur'},
    payableTypes: ['supplier_invoice'],
    conversions: {
      'rfq': ['purchase_order'],
      'purchase_order': ['supplier_invoice'],
    },
    partnerLabel: 'Fournisseur',
    partnerType: 'supplier',
    managePerm: 'achats.manage',
    paymentPerm: 'achats.manage',
    deletePerm: 'achats.delete',
  );
}

/// Libellé du bouton de création (accordé en genre).
const newLabels = {
  'quote': 'Nouveau devis',
  'proforma': 'Nouvelle facture proforma',
  'order': 'Nouvelle commande',
  'invoice': 'Nouvelle facture',
  'credit_note': 'Nouvel avoir',
  'rfq': 'Nouvelle demande de prix',
  'purchase_order': 'Nouveau bon de commande',
  'supplier_invoice': 'Nouvelle facture fournisseur',
};

const statusLabels = {'draft': 'Brouillon', 'validated': 'Validé', 'cancelled': 'Annulé'};
const paymentLabels = {'unpaid': 'Non payé', 'partial': 'Partiel', 'paid': 'Payé'};

Widget docStatusChip(Map<String, dynamic> d, DocConfig cfg) {
  if (d['status'] == 'cancelled') return StatusChip(tr('Annulé'), color: Colors.grey);
  if (d['status'] == 'draft') {
    final wf = approvalRequestFor(d['id'] as String?);
    if (wf?['status'] == 'pending') return StatusChip(tr('En approbation'), color: YColors.warning, icon: Icons.hourglass_top);
    if (wf?['status'] == 'rejected') return StatusChip(tr('Approbation refusée'), color: YColors.danger);
  }
  if (d['status'] == 'draft') return d['approved_at'] != null ? StatusChip(tr('Brouillon approuvé'), color: YColors.success) : StatusChip(tr('Brouillon'), color: YColors.muted);
  if (d['number'] == null) return StatusChip(tr('Validé · en attente'), color: YColors.warning);
  if (cfg.payableTypes.contains(d['type'])) {
    final ps = d['payment_status'] as String? ?? 'unpaid';
    final overdue = ps != 'paid' && d['due_date'] != null && DateTime.tryParse(d['due_date'])?.isBefore(DateTime.now()) == true;
    if (overdue) return StatusChip(tr('En retard'), color: YColors.danger);
    return StatusChip(paymentLabels[ps]!, color: ps == 'paid' ? YColors.success : (ps == 'partial' ? YColors.warning : YColors.blue));
  }
  if (d['type'] == 'purchase_order' && d['receipt_status'] != null) {
    final r = '${d['receipt_status']}';
    return StatusChip(r == 'received' ? 'Reçu' : (r == 'partial' ? 'Reçu en partie' : 'À réceptionner'), color: r == 'received' ? YColors.success : (r == 'partial' ? YColors.warning : YColors.blue));
  }
  return StatusChip(tr('Validé'), color: YColors.success);
}

class DocumentsScreen extends StatefulWidget {
  const DocumentsScreen({super.key, required this.direction});

  final DocDirection direction;

  @override
  State<DocumentsScreen> createState() => _DocumentsScreenState();
}

class _DocumentsScreenState extends State<DocumentsScreen> {
  late String _type;
  String _q = '';
  String _status = 'all';
  String? _partner; // identifiant du tiers (ou nom pour les documents sans fiche)
  FilterPeriod? _period;

  static String _partnerKey(Map d) => '${d['partner_id'] ?? 'n:${d['partner_name'] ?? ''}'}';

  /// Filtre par statut : brouillon, payé, impayé, paiement en cours (partiel), en retard, annulé.
  static bool _matches(Map d, String status, bool payable, String today) {
    final st = d['status'];
    final ps = d['payment_status'] as String? ?? 'unpaid';
    final open = st == 'validated' && ps != 'paid';
    final late = payable && open && d['due_date'] != null && '${d['due_date']}'.compareTo(today) < 0;
    return switch (status) {
      'draft' => st == 'draft',
      'validated' => st == 'validated',
      'unpaid' => payable && open && ps == 'unpaid',
      'partial' => payable && st == 'validated' && ps == 'partial',
      'paid' => payable && st == 'validated' && ps == 'paid',
      'late' => late,
      'cancelled' => st == 'cancelled',
      _ => true,
    };
  }

  DocConfig get cfg => DocConfig.of(widget.direction);

  @override
  void initState() {
    super.initState();
    _type = widget.direction == DocDirection.sale ? 'invoice' : 'supplier_invoice';
  }

  void _open([Map<String, dynamic>? doc]) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => DocumentEditor(config: cfg, document: doc, initialType: _type),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final canEdit = session.can(cfg.managePerm);
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;

    return Column(
      children: [
        PageHeader(
          title: cfg.title,
          actions: [
            if (widget.direction == DocDirection.purchase && session.hasModule('achats')) const PurchaseToolsButton(),
            if (widget.direction == DocDirection.sale && session.can('ventes.manage'))
              IconButton.outlined(tooltip: tr('Notifications de commande'), onPressed: () => showOrderNotifyDialog(context), icon: const Icon(Icons.mark_email_unread_outlined)),
            if (canEdit) FilledButton.icon(onPressed: () => _open(), icon: const Icon(Icons.add), label: Text(tr(newLabels[_type] ?? 'Nouveau'))),
          ],
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: pad),
          child: LayoutBuilder(
            builder: (context, c) {
              final tabs = SegmentedButton<String>(
                showSelectedIcon: false,
                segments: [for (final t in cfg.types.entries) ButtonSegment(value: t.key, label: Text(tr(t.value)))],
                selected: {_type},
                onSelectionChanged: (v) => setState(() => (_type = v.first, _status = 'all')),
              );
              final search = SearchField(hint: tr('Numéro, client, référence…'), onChanged: (v) => setState(() => _q = v.toLowerCase()));
              return c.maxWidth > 980
                  ? Row(
                      children: [
                        tabs,
                        const SizedBox(width: 16),
                        Expanded(child: search),
                      ],
                    )
                  : Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        SingleChildScrollView(scrollDirection: Axis.horizontal, child: tabs),
                        const SizedBox(height: 10),
                        search,
                      ],
                    );
            },
          ),
        ),
        const SizedBox(height: 16),
        Expanded(
          child: ValueListenableBuilder(
            valueListenable: LocalDb.listen(cfg.entity),
            builder: (context, _, _) {
              final session = context.watch<Session>();
              final ofType = LocalDb.all(cfg.entity).where((d) => d['type'] == _type).where(session.inCurrentBranch).toList();
              // Tiers présents dans ce type de document (pour le filtre client / fournisseur)
              final partners = <String, String>{};
              for (final d in ofType) {
                final n = '${d['partner_name'] ?? ''}'.trim();
                if (n.isNotEmpty) partners[_partnerKey(d)] = n;
              }
              final partnerList = partners.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()));
              final payableType = cfg.payableTypes.contains(_type);
              final todayIso = DateTime.now().toIso8601String().substring(0, 10);
              final docs =
                  ofType
                      .where((d) => _partner == null || _partnerKey(d) == _partner)
                      .where((d) => _period == null || _period!.contains(d['date']))
                      .where((d) => _q.isEmpty || '${d['number'] ?? ''} ${d['partner_name'] ?? ''} ${d['reference'] ?? ''}'.toLowerCase().contains(_q))
                      .toList()
                    ..sort((a, b) {
                      final c = '${b['date']}'.compareTo('${a['date']}');
                      return c != 0 ? c : '${b['created_at'] ?? b['id']}'.compareTo('${a['created_at'] ?? a['id']}');
                    });
              final statusOptions = payableType
                  ? const {'all': 'Toutes', 'draft': 'Brouillons', 'unpaid': 'Impayées', 'partial': 'Paiement en cours', 'paid': 'Payées', 'late': 'En retard', 'cancelled': 'Annulées'}
                  : const {'all': 'Tous', 'draft': 'Brouillons', 'validated': 'Validés', 'cancelled': 'Annulés'};
              final shown = docs.where((d) => _matches(d, _status, payableType, todayIso)).toList();
              final filters = FilterBar(
                padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
                statuses: {for (final o in statusOptions.entries) o.key == 'all' ? '' : o.key: o.value},
                status: _status == 'all' ? '' : _status,
                statusCounts: {for (final o in statusOptions.keys) o == 'all' ? '' : o: docs.where((d) => _matches(d, o, payableType, todayIso)).length},
                onStatus: (v) => setState(() => _status = v.isEmpty ? 'all' : v),
                filters: [
                  FilterSpec('partner', widget.direction == DocDirection.sale ? 'Client' : 'Fournisseur', {for (final p in partnerList) p.key: p.value}, icon: Icons.person_search_outlined),
                ],
                values: {'partner': _partner},
                onFilter: (k, v) => setState(() => _partner = v),
                showPeriod: true,
                period: _period,
                onPeriod: (p) => setState(() => _period = p),
                count: shown.length,
                countLabel: 'document',
                onReset: () => setState(() => (_partner = null, _status = 'all', _period = null)),
              );

              if (ofType.isEmpty) {
                return EmptyState(icon: Icons.receipt_long_outlined, title: tr('Aucun document'), message: tr('${newLabels[_type]} : cela fonctionne aussi hors ligne.'));
              }

              double rate(Map d) => toDouble(d['exchange_rate'] ?? 1) == 0 ? 1 : toDouble(d['exchange_rate'] ?? 1);
              final total = docs.where((d) => d['status'] == 'validated').fold<double>(0, (s, d) => s + toDouble(d['total']) * rate(d));
              final due = docs
                  .where((d) => d['status'] == 'validated' && cfg.payableTypes.contains(_type))
                  .fold<double>(0, (s, d) => s + (toDouble(d['total']) - toDouble(d['amount_paid'])) * rate(d));

              final payable = cfg.payableTypes.contains(_type);
              final today = DateTime.now().toIso8601String().substring(0, 10);
              final late = docs
                  .where((d) => payable && d['status'] == 'validated' && toDouble(d['total']) - toDouble(d['amount_paid']) > 0.5 && d['due_date'] != null && '${d['due_date']}'.compareTo(today) < 0)
                  .toList();
              final lateAmount = late.fold<double>(0, (s, d) => s + (toDouble(d['total']) - toDouble(d['amount_paid'])) * rate(d));
              final hint = Theme.of(context).hintColor;
              const tab = TextStyle(fontFeatures: [FontFeature.tabularFigures()]);

              return Column(
                children: [
                  StatStrip(
                    padding: EdgeInsets.fromLTRB(pad, 0, pad, 14),
                    tiles: [
                      StatTile(
                        label: cfg.types[_type] ?? 'Documents',
                        value: '${docs.length}',
                        detail: tr('${docs.where((d) => d['status'] == 'draft').length} brouillon(s)'),
                        icon: Icons.description_outlined,
                        color: YColors.blue,
                      ),
                      StatTile(label: tr('Total validé'), value: money(total, session.currency), icon: Icons.summarize_outlined, color: const Color(0xFF4F46E5)),
                      if (payable)
                        StatTile(
                          label: tr('Encaissé'),
                          value: money(total - due, session.currency),
                          detail: total > 0 ? '${((total - due) * 100 / total).round()} % du total' : null,
                          icon: Icons.check_circle_outline,
                          color: YColors.success,
                        ),
                      if (payable)
                        StatTile(
                          label: tr('Reste dû'),
                          value: money(due, session.currency),
                          detail: late.isEmpty ? 'Aucun retard' : '${late.length} en retard · ${money(lateAmount, session.currency)}',
                          icon: Icons.schedule,
                          color: late.isEmpty ? YColors.warning : YColors.danger,
                        ),
                    ],
                  ),
                  filters,
                  Expanded(
                    child: shown.isEmpty
                        ? EmptyState(icon: Icons.filter_alt_outlined, title: tr('Aucun document pour ce filtre'), message: tr('Changez de statut ou de client, ou effacez les filtres.'))
                        : ResponsiveList<Map<String, dynamic>>(
                            items: shown,
                            columns: payable ? const ['Numéro', 'Date', 'Tiers', 'Total TTC', 'Reste dû', 'Statut'] : const ['Numéro', 'Date', 'Tiers', 'Total TTC', 'Statut'],
                            onTap: _open,
                            cells: (d) {
                              final bal = toDouble(d['total']) - toDouble(d['amount_paid']);
                              final cur = '${d['currency'] ?? session.currency}';
                              return [
                                Column(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      tr('${d['number'] ?? 'Brouillon'}'),
                                      style: TextStyle(fontWeight: FontWeight.w600, color: d['number'] == null ? hint : null),
                                    ),
                                    if ((d['reference'] ?? '').toString().isNotEmpty) Text(tr('${d['reference']}'), style: TextStyle(color: hint, fontSize: 12)),
                                  ],
                                ),
                                Text(tr(dateFr(d['date'])), style: tab),
                                Row(
                                  children: [
                                    InitialsAvatar('${d['partner_name'] ?? '?'}', size: 28),
                                    const SizedBox(width: 10),
                                    Flexible(child: Text(tr('${d['partner_name'] ?? '—'}'), overflow: TextOverflow.ellipsis)),
                                  ],
                                ),
                                Text(tr(money(toDouble(d['total']), cur)), style: tab.copyWith(fontWeight: FontWeight.w600)),
                                if (payable)
                                  Text(tr(d['status'] == 'validated' && bal > 0.5 ? money(bal, cur) : '—'), style: tab.copyWith(color: late.contains(d) ? YColors.danger : (bal > 0.5 ? null : hint))),
                                docStatusChip(d, cfg),
                              ];
                            },
                            mobileTile: (d) => Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(tr('${d['number'] ?? 'Brouillon'} · ${d['partner_name'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                      const SizedBox(height: 2),
                                      Text(
                                        tr('${dateFr(d['date'])} · ${money(toDouble(d['total']), '${d['currency'] ?? session.currency}')}'),
                                        style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13),
                                      ),
                                    ],
                                  ),
                                ),
                                docStatusChip(d, cfg),
                              ],
                            ),
                          ),
                  ),
                ],
              );
            },
          ),
        ),
      ],
    );
  }
}
