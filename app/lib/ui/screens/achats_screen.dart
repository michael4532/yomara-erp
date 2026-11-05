import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/lots.dart';
import '../widgets/lots_dialog.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'labels_screen.dart' show LabelItem, openLabels;
import 'document_editor.dart';
import 'documents_screen.dart';
import 'appro_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const requestStatuses = {'draft': 'Brouillon', 'submitted': 'À approuver', 'approved': 'Approuvée', 'rejected': 'Refusée', 'ordered': 'Commandée', 'cancelled': 'Annulée'};
const requestColors = {
  'draft': YColors.muted,
  'submitted': YColors.warning,
  'approved': YColors.success,
  'rejected': YColors.danger,
  'ordered': YColors.blue,
  'cancelled': YColors.muted,
};
const receiptLabels = {'none': 'À réceptionner', 'partial': 'Reçu en partie', 'received': 'Reçu'};

double purchaseApprovalLimit(Session s) => toDouble((s.company['settings'] as Map?)?['purchase_approval_limit']);

String _partner(dynamic id) => '${LocalDb.get('partners', id as String?)?['name'] ?? ''}';

void openPurchaseDocument(BuildContext context, String id) {
  final doc = LocalDb.get('purchase_documents', id);
  if (doc == null) {
    toast(context, tr('Document pas encore synchronisé sur cet appareil.'), error: true);
    return;
  }
  Navigator.of(context).push(MaterialPageRoute(builder: (_) => DocumentEditor(config: DocConfig.purchase, document: doc, initialType: '${doc['type']}')));
}

/// Menu des outils d'achat (en-tête de l'écran Achats).
class PurchaseToolsButton extends StatelessWidget {
  const PurchaseToolsButton({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    void go(Widget w) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => w));
    return Wrap(spacing: 8, children: [
      if (LocalDb.hasEntity('purchase_requests'))
        ValueListenableBuilder(
          valueListenable: LocalDb.listen('purchase_requests'),
          builder: (context, _, _) {
            final pending = LocalDb.all('purchase_requests').where((r) => (r['status'] == 'submitted' && (s.can('achats.approve') || s.can('achats.manage'))) || (r['status'] == 'approved' && s.can('achats.manage'))).length;
            return OutlinedButton.icon(
              onPressed: () => go(const PurchaseRequestsScreen()),
              icon: Badge(isLabelVisible: pending > 0, label: Text(tr('$pending')), child: const Icon(Icons.assignment_outlined)),
              label: Text(tr('Demandes d\'achat')),
            );
          },
        ),
      PopupMenuButton<String>(
        tooltip: tr('Outils d\'achat'),
        onSelected: (v) {
          if (v == 'repl') go(const ReplenishmentScreen());
          if (v == 'rec') go(const ReceiptsScreen());
          if (v == 'prices') go(const SupplierPricesScreen());
        },
        itemBuilder: (_) => [
          if (s.can('achats.manage')) PopupMenuItem(value: 'repl', child: ListTile(dense: true, leading: Icon(Icons.autorenew), title: Text(tr('Réapprovisionner')))),
          PopupMenuItem(value: 'rec', child: ListTile(dense: true, leading: Icon(Icons.move_to_inbox_outlined), title: Text(tr('Réceptions')))),
          PopupMenuItem(value: 'prices', child: ListTile(dense: true, leading: Icon(Icons.price_change_outlined), title: Text(tr('Tarifs fournisseurs')))),
        ],
        child: const Padding(padding: EdgeInsets.all(10), child: Icon(Icons.more_horiz)),
      ),
    ]);
  }
}

// ======================================================================
// Bandeau d'un bon de commande : approbation et réceptions
// ======================================================================

class PurchaseOrderPanel extends StatelessWidget {
  const PurchaseOrderPanel({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('purchase_documents'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('purchase_receipts'),
        builder: (context, _, _) {
          final doc = LocalDb.get('purchase_documents', id);
          if (doc == null || doc['type'] != 'purchase_order') return const SizedBox.shrink();
          final limit = purchaseApprovalLimit(s);
          final over = limit > 0 && toDouble(doc['subtotal']) > limit;
          final receipts = LocalDb.all('purchase_receipts').where((r) => r['order_id'] == id).toList()..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
          final hint = Theme.of(context).hintColor;
          final children = <Widget>[];
          if (doc['status'] == 'draft' && over) {
            final approved = doc['approved_at'] != null;
            children.add(Card(
              color: (approved ? YColors.success : YColors.warning).withValues(alpha: .08),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  Icon(approved ? Icons.verified : Icons.approval, color: approved ? YColors.success : YColors.warning),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(tr(approved
                        ? 'Approuvé le ${dateTimeFr(doc['approved_at'])} : vous pouvez le valider.'
                        : 'Montant HT au-delà du seuil d\'approbation (${money(limit, s.currency)}).${doc['approval_requested_at'] != null ? ' Approbation demandée le ${dateTimeFr(doc['approval_requested_at'])}.' : ''}')),
                  ),
                  if (!approved && s.can('achats.approve'))
                    FilledButton(onPressed: () => _call(context, 'achats/documents/$id/approve', 'Bon de commande approuvé.'), child: Text(tr('Approuver')))
                  else if (!approved && s.can('achats.manage') && doc['approval_requested_at'] == null)
                    OutlinedButton(onPressed: () => _call(context, 'achats/documents/$id/request-approval', 'Demande d\'approbation envoyée.'), child: Text(tr('Demander l\'approbation'))),
                ]),
              ),
            ));
          }
          if (doc['status'] == 'validated') {
            final status = '${doc['receipt_status'] ?? 'none'}';
            children.add(SectionCard(
              title: tr('Réceptions'),
              trailing: status != 'received' && (s.can('achats.receive') || s.can('achats.manage'))
                  ? FilledButton.tonalIcon(onPressed: () => receiveOrder(context, doc), icon: const Icon(Icons.move_to_inbox_outlined), label: Text(tr('Réceptionner')))
                  : null,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  StatusChip(receiptLabels[status] ?? status, color: status == 'received' ? YColors.success : (status == 'partial' ? YColors.warning : YColors.blue)),
                  if (doc['due_date'] != null) ...[
                    const SizedBox(width: 8),
                    StatusChip(tr('Livraison prévue le ${dateFr(doc['due_date'])}'),
                        color: status != 'received' && DateTime.tryParse('${doc['due_date']}')?.isBefore(DateTime.now()) == true ? YColors.danger : YColors.muted),
                  ],
                ]),
                const SizedBox(height: 8),
                for (final l in (doc['lines'] as List? ?? const []))
                  Builder(builder: (_) {
                    final got = _received(id, '${l['id']}');
                    final q = toDouble(l['quantity']);
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 3),
                      child: Row(children: [
                        Expanded(child: Text(tr('${l['description']}'))),
                        Text(tr('${qty(got)} / ${qty(q)}'), style: TextStyle(fontWeight: FontWeight.w700, color: got + 0.0005 >= q ? YColors.success : null)),
                      ]),
                    );
                  }),
                if (receipts.isNotEmpty) const Divider(height: 20),
                for (final r in receipts)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(r['status'] == 'cancelled' ? Icons.block : Icons.inventory_2_outlined, color: r['status'] == 'cancelled' ? YColors.muted : YColors.success),
                    title: Text(tr('${r['number'] ?? 'Réception'} · ${dateFr(r['date'])}${r['supplier_reference'] != null ? ' · BL ${r['supplier_reference']}' : ''}'),
                        style: TextStyle(decoration: r['status'] == 'cancelled' ? TextDecoration.lineThrough : null)),
                    subtitle: Text(tr((r['lines'] as List? ?? const []).map((l) => '${qty(toDouble(l['quantity']))} × ${l['description']}').join(' · ')), style: TextStyle(color: hint)),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      if (r['status'] == 'done' && s.hasModule('etiquettes') && (s.can('etiquettes.print') || s.can('etiquettes.manage')))
                        IconButton(
                          tooltip: tr('Étiquettes des articles reçus'),
                          onPressed: () => openLabels(context, [
                            for (final l in (r['lines'] as List? ?? const []))
                              if (LocalDb.get('products', l['product_id'] as String?) != null)
                                LabelItem(Map<String, dynamic>.from(LocalDb.get('products', l['product_id'] as String)!), count: toDouble(l['quantity']).ceil().clamp(1, 999)),
                          ]),
                          icon: const Icon(Icons.qr_code_2),
                        ),
                      if (r['status'] == 'done' && (s.can('achats.receive') || s.can('achats.manage')))
                        TextButton(
                          onPressed: () async {
                            if (!await confirm(context, tr('Annuler la réception ?'), tr('Les quantités sortiront du stock.'), danger: true, ok: tr('Annuler la réception'))) return;
                            if (!context.mounted) return;
                            await context.read<SyncEngine>().save('purchase_receipts', {'id': r['id'], 'status': 'cancelled'});
                          },
                          child: Text(tr('Annuler')),
                        ),
                    ]),
                  ),
              ]),
            ));
          }
          if (children.isEmpty) return const SizedBox.shrink();
          return Padding(padding: const EdgeInsets.only(bottom: 12), child: Column(children: [for (final c in children) Padding(padding: const EdgeInsets.only(bottom: 8), child: c)]));
        },
      ),
    );
  }

  Future<void> _call(BuildContext context, String path, String ok) async {
    try {
      await context.read<Session>().api.post(path);
      if (!context.mounted) return;
      await context.read<SyncEngine>().sync();
      if (context.mounted) toast(context, ok);
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }
}

/// Quantité reçue d'une ligne (réceptions locales, y compris celles en attente d'envoi).
double _received(String orderId, String lineId) {
  var q = 0.0;
  for (final r in LocalDb.all('purchase_receipts').where((r) => r['order_id'] == orderId && r['status'] != 'cancelled')) {
    for (final l in (r['lines'] as List? ?? const [])) {
      if (l['order_line_id'] == lineId) q += toDouble(l['quantity']);
    }
  }
  return q;
}

/// Saisie d'une réception (fonctionne hors ligne).
Future<void> receiveOrder(BuildContext context, Map<String, dynamic> order) async {
  final lines = [for (final l in (order['lines'] as List? ?? const [])) asMap(l)];
  final ctl = {
    for (final l in lines) '${l['id']}': TextEditingController(text: qty((toDouble(l['quantity']) - _received('${order['id']}', '${l['id']}')).clamp(0, double.infinity))),
  };
  final ref = TextEditingController();
  final lotsOn = context.read<Session>().hasModule('lots');
  final lotSpecs = <String, List<Map<String, dynamic>>>{};
  final warehouses = LocalDb.hasEntity('warehouses') ? LocalDb.all('warehouses') : <Map<String, dynamic>>[];
  String? wh = order['warehouse_id'] as String?;
  final sync = context.read<SyncEngine>();
  await showDialog(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr('Réception — ${order['number'] ?? ''}')),
        content: SizedBox(
          width: 520,
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('Indiquez les quantités effectivement livrées (0 si absent).'), style: TextStyle(color: Theme.of(c).hintColor)),
              const SizedBox(height: 8),
              for (final l in lines)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${l['description']}')),
                        Text(tr('Commandé ${qty(toDouble(l['quantity']))} · déjà reçu ${qty(_received('${order['id']}', '${l['id']}'))}'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
                        if (lotsOn && isTracked(LocalDb.get('products', l['product_id'] as String?)))
                          Builder(builder: (_) {
                            final p = LocalDb.get('products', l['product_id'] as String?)!;
                            final f = toDouble(l['unit_factor']) > 0 ? toDouble(l['unit_factor']) : 1.0;
                            final spec = lotSpecs['${l['id']}'];
                            return TextButton.icon(
                              style: TextButton.styleFrom(visualDensity: VisualDensity.compact, padding: EdgeInsets.zero, foregroundColor: isSerial(p) && specQuantity(spec) < toDouble(ctl['${l['id']}']!.text) * f ? YColors.danger : null),
                              onPressed: () async {
                                final r = await editLotsSpec(c, product: p, quantity: toDouble(ctl['${l['id']}']!.text) * f, spec: spec, incoming: true);
                                if (r != null) set(() => r.isEmpty ? lotSpecs.remove('${l['id']}') : lotSpecs['${l['id']}'] = r);
                              },
                              icon: Icon(isSerial(p) ? Icons.qr_code_2 : Icons.inventory_2_outlined, size: 16),
                              label: Text(tr(spec != null ? lotsSummary(spec) : (isSerial(p) ? 'Saisir les n° de série' : 'Lots : création automatique')), overflow: TextOverflow.ellipsis),
                            );
                          }),
                      ]),
                    ),
                    SizedBox(width: 90, child: TextField(controller: ctl['${l['id']}'], keyboardType: const TextInputType.numberWithOptions(decimal: true), textAlign: TextAlign.right)),
                  ]),
                ),
              TextField(controller: ref, decoration: InputDecoration(labelText: tr('N° du bon de livraison du fournisseur'))),
              if (warehouses.length > 1)
                DropdownButtonFormField<String?>(
                  initialValue: wh,
                  decoration: InputDecoration(labelText: tr('Entrepôt')),
                  items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Par défaut'))), for (final w in warehouses) DropdownMenuItem<String?>(value: '${w['id']}', child: Text(tr('${w['name']}')))],
                  onChanged: (v) => set(() => wh = v),
                ),
            ]),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton.icon(
            onPressed: () async {
              final out = [
                for (final l in lines)
                  if (toDouble(ctl['${l['id']}']!.text) > 0)
                    {'order_line_id': l['id'], 'product_id': l['product_id'], 'description': l['description'], 'quantity': toDouble(ctl['${l['id']}']!.text), if (lotSpecs['${l['id']}'] != null) 'lots': lotSpecs['${l['id']}']},
              ];
              if (out.isEmpty) {
                toast(c, tr('Indiquez au moins une quantité reçue.'), error: true);
                return;
              }
              for (final l in out) {
                final line = lines.firstWhere((x) => x['id'] == l['order_line_id']);
                if (toDouble(l['quantity']) > toDouble(line['quantity']) - _received('${order['id']}', '${line['id']}') + 0.0005) {
                  toast(c, tr('« ${line['description']} » : plus que le reste à recevoir.'), error: true);
                  return;
                }
                final p = lotsOn ? LocalDb.get('products', line['product_id'] as String?) : null;
                final need = toDouble(l['quantity']) * (toDouble(line['unit_factor']) > 0 ? toDouble(line['unit_factor']) : 1);
                if (isSerial(p) && isTracked(p) && specQuantity(l['lots']) + 0.0001 < need) {
                  toast(c, tr('Indiquez les numéros de série de « ${p!['name']} » (${specQuantity(l['lots']).toInt()} sur ${need.toInt()}).'), error: true);
                  return;
                }
              }
              await sync.save('purchase_receipts', {
                'id': newId(),
                'order_id': order['id'],
                'partner_id': order['partner_id'],
                'date': isoDate(DateTime.now()),
                'warehouse_id': wh,
                'supplier_reference': ref.text.trim().isEmpty ? null : ref.text.trim(),
                'lines': out,
                'status': 'done',
              });
              if (c.mounted) {
                Navigator.pop(c);
                toast(c, tr('Réception enregistrée : le stock est mis à jour à la synchronisation.'));
              }
            },
            icon: const Icon(Icons.check),
            label: Text(tr('Valider la réception')),
          ),
        ],
      ),
    ),
  );
}

// ======================================================================
// Demandes d'achat
// ======================================================================

void openPurchaseRequest(BuildContext context, String id) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => PurchaseRequestScreen(id: id)));

class PurchaseRequestsScreen extends StatefulWidget {
  const PurchaseRequestsScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  State<PurchaseRequestsScreen> createState() => _PurchaseRequestsScreenState();
}

class _PurchaseRequestsScreenState extends State<PurchaseRequestsScreen> {
  late String _status = () {
    final s = context.read<Session>();
    if (s.can('achats.approve')) return 'submitted';
    if (s.can('achats.manage')) return 'approved';
    return '';
  }();
  late bool _mine = _status.isEmpty;
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final me = s.user['id'];
    final cur = s.currency;
    final body = ValueListenableBuilder(
      valueListenable: LocalDb.listen('purchase_requests'),
      builder: (context, _, _) {
        final manager = s.can('achats.manage') || s.can('achats.approve') || s.can('achats.view');
        final mine = _mine || !manager;
        final all = LocalDb.all('purchase_requests');
        final visible = all.where((r) => !mine || r['requester_id'] == me).toList();
        final base = visible.where((r) {
          if (_q.isNotEmpty && !'${r['title']} ${r['number'] ?? ''} ${r['department'] ?? ''} ${r['requester_name'] ?? ''}'.toLowerCase().contains(_q)) return false;
          final dep = _f['department'], rq = _f['requester'];
          if (dep != null && '${r['department'] ?? ''}' != dep) return false;
          if (rq != null && '${r['requester_id']}' != rq) return false;
          if (_period != null && !_period!.contains(r['created_at'] ?? r['needed_by'])) return false;
          return true;
        }).toList();
        final statuses = {'': 'Toutes', ...requestStatuses};
        final rows = base.where((r) => _status.isEmpty || r['status'] == _status).toList()
          ..sort((a, b) => '${b['created_at'] ?? b['number'] ?? ''}'.compareTo('${a['created_at'] ?? a['number'] ?? ''}'));
        return ListView(padding: const EdgeInsets.all(16), children: [
          FilterBar(
            padding: const EdgeInsets.only(bottom: 12),
            search: _q,
            searchHint: 'Objet, n°, service, demandeur…',
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            statuses: statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in statuses.keys) k: k.isEmpty ? base.length : base.where((r) => r['status'] == k).length},
            filters: [
              FilterSpec('department', 'Service', filterOptions(visible, 'department'), icon: Icons.apartment_outlined),
              if (manager && !mine) FilterSpec('requester', 'Demandeur', filterOptions(visible.where((r) => r['requester_name'] != null), 'requester_id', label: (r) => '${r['requester_name']}'), icon: Icons.person_outline),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (v) => setState(() => _period = v),
            count: rows.length,
            countLabel: 'demande',
            onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
            trailing: [
              if (manager) FilterChip(label: Text(tr('Mes demandes')), selected: _mine, onSelected: (v) => setState(() => _mine = v)),
            ],
          ),
          if (rows.isEmpty && visible.isNotEmpty)
            EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
          else if (rows.isEmpty)
            EmptyState(icon: Icons.assignment_outlined, title: tr('Aucune demande'), message: tr('Exprimez un besoin (fournitures, matériel, marchandises) : il est approuvé puis commandé par les achats.')),
          for (final r in rows)
            Card(
              child: ListTile(
                leading: CircleAvatar(backgroundColor: (requestColors[r['status']] ?? YColors.muted).withValues(alpha: .15), child: Icon(Icons.assignment_outlined, color: requestColors[r['status']] ?? YColors.muted)),
                title: Text(tr('${r['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text(tr([
                  r['number'] ?? 'en attente',
                  if (r['department'] != null) r['department'],
                  if (toDouble(r['estimated_total']) > 0) '≈ ${money(toDouble(r['estimated_total']), cur)}',
                  if (r['needed_by'] != null) 'pour le ${dateFr(r['needed_by'])}',
                ].join(' · '))),
                trailing: StatusChip(requestStatuses[r['status']] ?? '', color: requestColors[r['status']] ?? YColors.muted),
                onTap: () => openPurchaseRequest(context, '${r['id']}'),
              ),
            ),
        ]);
      },
    );
    final fab = FloatingActionButton.extended(onPressed: () => editPurchaseRequest(context), icon: const Icon(Icons.add), label: Text(tr('Demande')));
    if (widget.embedded) {
      return Scaffold(body: Column(children: [PageHeader(title: tr('Demandes d\'achat')), Expanded(child: body)]), floatingActionButton: fab);
    }
    return Scaffold(appBar: AppBar(title: Text(tr('Demandes d\'achat'))), body: body, floatingActionButton: fab);
  }
}

class PurchaseRequestScreen extends StatelessWidget {
  const PurchaseRequestScreen({super.key, required this.id});

  final String id;

  Future<void> _status(BuildContext context, String status, [String? reason]) async {
    try {
      await context.read<SyncEngine>().save('purchase_requests', {'id': id, 'status': status, 'rejection_reason': ?reason});
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _order(BuildContext context, Map<String, dynamic> r) async {
    final lines = [for (final l in (r['lines'] as List? ?? const [])) asMap(l)];
    final needSupplier = lines.any((l) => l['supplier_id'] == null && !LocalDb.all('supplier_prices').any((p) => p['product_id'] == l['product_id'] && l['product_id'] != null));
    String? supplier;
    if (needSupplier) {
      final suppliers = LocalDb.all('partners').where((p) => p['type'] == 'supplier' || p['type'] == 'both').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
      supplier = await showDialog<String>(
        context: context,
        builder: (c) => SimpleDialog(
          title: Text(tr('Fournisseur des articles sans fournisseur habituel')),
          children: [for (final p in suppliers) SimpleDialogOption(onPressed: () => Navigator.pop(c, '${p['id']}'), child: Text(tr('${p['name']}')))],
        ),
      );
      if (supplier == null) return;
    }
    if (!context.mounted) return;
    try {
      final res = await context.read<Session>().api.post('achats/requests/$id/order', {'partner_id': supplier});
      if (!context.mounted) return;
      await context.read<SyncEngine>().sync();
      if (!context.mounted) return;
      final ids = [for (final d in (res['data'] as List)) '${d['id']}'];
      toast(context, tr('${ids.length} bon(s) de commande préparé(s) en brouillon.'));
      if (ids.length == 1) openPurchaseDocument(context, ids.first);
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('purchase_requests'),
      builder: (context, _, _) {
        final r = LocalDb.get('purchase_requests', id);
        if (r == null) return Scaffold(appBar: AppBar(), body: const SizedBox());
        final status = '${r['status']}';
        final mine = r['requester_id'] == s.user['id'] || r['requester_id'] == null;
        final total = toDouble(r['estimated_total']) > 0
            ? toDouble(r['estimated_total'])
            : (r['lines'] as List? ?? const []).fold<double>(0, (t, l) => t + toDouble(l['quantity']) * toDouble(l['estimated_price']));
        final limit = purchaseApprovalLimit(s);
        final canApprove = s.can('achats.approve') || (s.can('achats.manage') && (limit <= 0 || total <= limit));
        final hint = Theme.of(context).hintColor;
        return Scaffold(
          appBar: AppBar(
            title: Text(tr('${r['number'] ?? 'Demande d\'achat'}')),
            actions: [
              Padding(padding: const EdgeInsets.only(right: 8), child: Center(child: StatusChip(requestStatuses[status] ?? status, color: requestColors[status] ?? YColors.muted))),
              if (status == 'draft' && (mine || s.can('achats.manage')))
                IconButton(tooltip: tr('Modifier'), onPressed: () => editPurchaseRequest(context, r), icon: const Icon(Icons.edit_outlined)),
            ],
          ),
          body: ListView(padding: const EdgeInsets.all(16), children: [
            Text(tr('${r['title']}'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 4),
            Text(
              tr([
                if (r['requester_name'] != null) 'Par ${r['requester_name']}',
                if (r['department'] != null) r['department'],
                if (r['needed_by'] != null) 'pour le ${dateFr(r['needed_by'])}',
                if (((r['priority'] as num?) ?? 1) >= 2) 'urgent',
              ].join(' · ')),
              style: TextStyle(color: hint),
            ),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (status == 'draft' && mine) FilledButton.icon(onPressed: () => _status(context, 'submitted'), icon: const Icon(Icons.send), label: Text(tr('Envoyer pour approbation'))),
              if (status == 'submitted' && canApprove) ...[
                FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: YColors.success), onPressed: () => _status(context, 'approved'), icon: const Icon(Icons.check), label: Text(tr('Approuver'))),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(foregroundColor: YColors.danger),
                  onPressed: () async {
                    final ctl = TextEditingController();
                    final ok = await showDialog<bool>(
                      context: context,
                      builder: (c) => YAlertDialog(
                        title: Text(tr('Refuser la demande')),
                        content: TextField(controller: ctl, autofocus: true, decoration: InputDecoration(labelText: tr('Motif (communiqué au demandeur)'))),
                        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Refuser')))],
                      ),
                    );
                    if (ok == true && ctl.text.trim().isNotEmpty && context.mounted) _status(context, 'rejected', ctl.text.trim());
                  },
                  icon: const Icon(Icons.close),
                  label: Text(tr('Refuser')),
                ),
              ],
              if (status == 'submitted' && !canApprove && s.can('achats.manage'))
                Text(tr('Au-delà du seuil (${money(limit, s.currency)}) : approbation de la direction.'), style: const TextStyle(color: YColors.warning)),
              if (status == 'approved' && s.can('achats.manage'))
                FilledButton.icon(onPressed: () => _order(context, r), icon: const Icon(Icons.shopping_cart_checkout), label: Text(tr('Créer les bons de commande'))),
              if (['submitted', 'approved', 'ordered'].contains(status) && r['_local'] != true) CompareButton(type: 'request', id: id),
              if (['submitted', 'approved', 'rejected'].contains(status) && (mine || s.can('achats.manage')))
                TextButton(onPressed: () => _status(context, 'draft'), child: Text(tr('Repasser en brouillon'))),
              if (['draft', 'submitted', 'approved'].contains(status) && (mine || s.can('achats.manage')))
                TextButton(onPressed: () => _status(context, 'cancelled'), child: Text(tr('Annuler la demande'), style: TextStyle(color: YColors.danger))),
            ]),
            if (status == 'rejected' && r['rejection_reason'] != null)
              Padding(padding: const EdgeInsets.only(top: 12), child: Text(tr('Refusée : ${r['rejection_reason']}'), style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w600))),
            if (r['approved_at'] != null && status != 'rejected')
              Padding(padding: const EdgeInsets.only(top: 12), child: Text(tr('Approuvée le ${dateTimeFr(r['approved_at'])}${r['approver_name'] != null ? ' par ${r['approver_name']}' : ''}'), style: TextStyle(color: hint))),
            const SizedBox(height: 12),
            if ('${r['reason'] ?? ''}'.isNotEmpty) ...[SectionCard(title: tr('Justification'), child: Text(tr('${r['reason']}'))), const SizedBox(height: 12)],
            SectionCard(
              title: tr('Articles'),
              trailing: total > 0 ? Text(tr('≈ ${money(total, s.currency)} HT'), style: const TextStyle(fontWeight: FontWeight.w700)) : null,
              child: Column(children: [
                for (final l in (r['lines'] as List? ?? const []))
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: CircleAvatar(radius: 16, child: Text(tr(qty(toDouble(l['quantity']))), style: const TextStyle(fontSize: 11))),
                    title: Text(tr('${l['description']}${l['unit'] != null ? ' (${l['unit']})' : ''}')),
                    subtitle: Text(tr([if (l['supplier_id'] != null) _partner(l['supplier_id']), if (toDouble(l['estimated_price']) > 0) '${money(toDouble(l['estimated_price']), s.currency)} l\'unité'].join(' · '))),
                  ),
              ]),
            ),
            if ((r['order_ids'] as List? ?? const []).isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Bons de commande'),
                child: Column(children: [
                  for (final oid in (r['order_ids'] as List))
                    Builder(builder: (context) {
                      final d = LocalDb.get('purchase_documents', '$oid');
                      return ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(Icons.receipt_long_outlined),
                        title: Text(tr('${d?['number'] ?? 'Brouillon'} · ${d?['partner_name'] ?? ''}')),
                        subtitle: d == null ? null : Text(tr('${money(toDouble(d['total']), s.currency)}${d['receipt_status'] != null ? ' · ${receiptLabels[d['receipt_status']] ?? ''}' : ''}')),
                        onTap: s.can('achats.view') ? () => openPurchaseDocument(context, '$oid') : null,
                      );
                    }),
                ]),
              ),
            ],
          ]),
        );
      },
    );
  }
}

Future<void> editPurchaseRequest(BuildContext context, [Map<String, dynamic>? r]) =>
    showFormDialog(context, title: r == null ? 'Nouvelle demande d\'achat' : 'Demande ${r['number'] ?? ''}', builder: (c) => _RequestForm(r: r));

class _RequestForm extends StatefulWidget {
  const _RequestForm({this.r});

  final Map<String, dynamic>? r;

  @override
  State<_RequestForm> createState() => _RequestFormState();
}

class _RequestFormState extends State<_RequestForm> {
  late final _title = TextEditingController(text: '${widget.r?['title'] ?? ''}');
  late final _dept = TextEditingController(text: '${widget.r?['department'] ?? ''}');
  late final _reason = TextEditingController(text: '${widget.r?['reason'] ?? ''}');
  late DateTime? _needed = DateTime.tryParse('${widget.r?['needed_by'] ?? ''}');
  late int _priority = ((widget.r?['priority'] as num?) ?? 1).toInt();
  late final List<Map<String, dynamic>> _lines = [for (final l in (widget.r?['lines'] as List? ?? const [])) asMap(l)];

  Future<void> _addLine() async {
    final products = LocalDb.all('products').where((p) => p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final desc = TextEditingController();
    final q = TextEditingController(text: '1');
    final price = TextEditingController();
    String? productId;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Article')),
        content: SizedBox(
          width: 420,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Autocomplete<Map<String, dynamic>>(
              optionsBuilder: (v) => v.text.isEmpty ? const [] : products.where((p) => '${p['name']} ${p['sku'] ?? ''}'.toLowerCase().contains(v.text.toLowerCase())).take(20),
              displayStringForOption: (p) => '${p['name']}',
              onSelected: (p) {
                productId = '${p['id']}';
                desc.text = '${p['name']}';
                final best = LocalDb.all('supplier_prices').where((x) => x['product_id'] == p['id']).toList()..sort((a, b) => (b['preferred'] == true ? 1 : 0).compareTo(a['preferred'] == true ? 1 : 0));
                price.text = '${(best.isNotEmpty ? toDouble(best.first['price']) : toDouble(p['purchase_price'])).round()}';
              },
              fieldViewBuilder: (c, ctl, focus, _) => TextField(controller: ctl, focusNode: focus, autofocus: true, decoration: InputDecoration(labelText: tr('Article du catalogue (recherche)'))),
            ),
            TextField(controller: desc, decoration: InputDecoration(labelText: tr('Désignation (ou besoin hors catalogue)'))),
            Row(children: [
              Expanded(child: TextField(controller: q, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('Quantité')))),
              const SizedBox(width: 12),
              Expanded(child: TextField(controller: price, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Prix estimé (HT)')))),
            ]),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Ajouter')))],
      ),
    );
    if (ok != true || desc.text.trim().isEmpty) return;
    setState(() => _lines.add({
          'product_id': productId,
          'description': desc.text.trim(),
          'quantity': toDouble(q.text) > 0 ? toDouble(q.text) : 1,
          'estimated_price': toDouble(price.text),
          'unit': productId == null ? null : LocalDb.get('products', productId)?['unit'],
        }));
  }

  Future<void> _save({bool submit = false}) async {
    if (_title.text.trim().isEmpty || _lines.isEmpty) {
      toast(context, tr('Indiquez l\'objet et au moins un article.'), error: true);
      return;
    }
    try {
      await context.read<SyncEngine>().save('purchase_requests', {
        'id': widget.r?['id'] ?? newId(),
        'title': _title.text.trim(),
        'department': _dept.text.trim().isEmpty ? null : _dept.text.trim(),
        'reason': _reason.text.trim().isEmpty ? null : _reason.text.trim(),
        'needed_by': _needed == null ? null : isoDate(_needed!),
        'priority': _priority,
        'lines': _lines,
        'estimated_total': _lines.fold<double>(0, (t, l) => t + toDouble(l['quantity']) * toDouble(l['estimated_price'])),
        if (widget.r == null) 'requester_id': context.read<Session>().user['id'],
        'status': submit ? 'submitted' : (widget.r?['status'] ?? 'draft'),
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final total = _lines.fold<double>(0, (t, l) => t + toDouble(l['quantity']) * toDouble(l['estimated_price']));
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Field(label: tr('Objet'), child: TextField(controller: _title, decoration: InputDecoration(hintText: tr('Ex. Fournitures de bureau du trimestre')))),
      Row(children: [
        Expanded(child: Field(label: tr('Service'), child: TextField(controller: _dept))),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Pour le'),
            child: OutlinedButton.icon(
              onPressed: () async {
                final d = await showDatePicker(context: context, initialDate: _needed ?? DateTime.now().add(const Duration(days: 7)), firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (d != null) setState(() => _needed = d);
              },
              icon: const Icon(Icons.event, size: 18),
              label: Text(tr(_needed == null ? 'Date souhaitée' : DateFormat('dd/MM/yyyy').format(_needed!))),
            ),
          ),
        ),
      ]),
      Field(
        label: tr('Urgence'),
        child: SegmentedButton<int>(
          showSelectedIcon: false,
          segments: [ButtonSegment(value: 0, label: Text(tr('Peut attendre'))), ButtonSegment(value: 1, label: Text(tr('Normale'))), ButtonSegment(value: 2, label: Text(tr('Urgente')))],
          selected: {_priority.clamp(0, 2)},
          onSelectionChanged: (v) => setState(() => _priority = v.first),
        ),
      ),
      Field(label: tr('Justification'), child: TextField(controller: _reason, maxLines: 2)),
      Field(
        label: tr('Articles'),
        child: Column(children: [
          for (var i = 0; i < _lines.length; i++)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(tr('${qty(toDouble(_lines[i]['quantity']))} × ${_lines[i]['description']}')),
              subtitle: toDouble(_lines[i]['estimated_price']) > 0 ? Text(tr(money(toDouble(_lines[i]['estimated_price']) * toDouble(_lines[i]['quantity']), cur))) : null,
              trailing: IconButton(onPressed: () => setState(() => _lines.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
            ),
          Row(children: [
            TextButton.icon(onPressed: _addLine, icon: const Icon(Icons.add), label: Text(tr('Article'))),
            const Spacer(),
            if (total > 0) Text(tr('≈ ${money(total, cur)} HT'), style: const TextStyle(fontWeight: FontWeight.w700)),
          ]),
        ]),
      ),
      const SizedBox(height: 8),
      Row(children: [
        if (widget.r != null && widget.r!['status'] == 'draft')
          TextButton(
            onPressed: () async {
              await context.read<SyncEngine>().remove('purchase_requests', '${widget.r!['id']}');
              if (context.mounted) Navigator.of(context).popUntil((r) => r.isFirst);
            },
            child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
          ),
        const Spacer(),
        OutlinedButton(onPressed: _save, child: Text(tr('Enregistrer'))),
        const SizedBox(width: 8),
        if (widget.r == null || widget.r!['status'] == 'draft') FilledButton.icon(onPressed: () => _save(submit: true), icon: const Icon(Icons.send), label: Text(tr('Envoyer'))),
      ]),
    ]);
  }
}

// ======================================================================
// Réapprovisionnement
// ======================================================================

class ReplenishmentScreen extends StatefulWidget {
  const ReplenishmentScreen({super.key});

  @override
  State<ReplenishmentScreen> createState() => _ReplenishmentScreenState();
}

class _ReplenishmentScreenState extends State<ReplenishmentScreen> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  final Set<String> _selected = {};
  bool _busy = false;
  String _q = '';
  Map<String, String?> _f = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('achats/replenishment');
      final rows = [for (final x in (r['data'] as List)) asMap(x)];
      if (mounted) {
        setState(() {
          _rows = rows;
          _error = null;
          _selected
            ..clear()
            ..addAll(rows.map((x) => '${x['product_id']}'));
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _order() async {
    final items = _rows!.where((r) => _selected.contains('${r['product_id']}')).toList();
    if (items.any((r) => r['partner_id'] == null)) {
      toast(context, tr('Choisissez un fournisseur pour chaque article coché.'), error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final res = await context.read<Session>().api.post('achats/replenishment', {
        'items': [for (final r in items) {'product_id': r['product_id'], 'partner_id': r['partner_id'], 'quantity': toDouble(r['quantity']), 'unit_price': toDouble(r['unit_price'])}],
      });
      if (!mounted) return;
      await context.read<SyncEngine>().sync();
      if (!mounted) return;
      toast(context, tr('${(res['data'] as List).length} bon(s) de commande préparé(s) en brouillon.'));
      Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<Map<String, dynamic>> _shownReplenishment() => (_rows ?? const <Map<String, dynamic>>[]).where((r) {
        if (_q.isNotEmpty && !'${r['name']}'.toLowerCase().contains(_q)) return false;
        final sup = _f['supplier'], sel = _f['selection'];
        if (sup == 'none' && r['partner_id'] != null) return false;
        if (sup != null && sup != 'none' && '${r['partner_id']}' != sup) return false;
        if (sel != null && (sel == 'checked') != _selected.contains('${r['product_id']}')) return false;
        return true;
      }).toList();

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final suppliers = LocalDb.all('partners').where((p) => p['type'] == 'supplier' || p['type'] == 'both').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final total = (_rows ?? const []).where((r) => _selected.contains('${r['product_id']}')).fold<double>(0, (t, r) => t + toDouble(r['quantity']) * toDouble(r['unit_price']));
    return Scaffold(
      appBar: AppBar(title: Text(tr('Réapprovisionnement'))),
      bottomNavigationBar: _rows == null || _rows!.isEmpty
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(children: [
                  Expanded(child: Text(tr('${_selected.length} article(s) · ≈ ${money(total, cur)} HT'), style: const TextStyle(fontWeight: FontWeight.w700))),
                  FilledButton.icon(onPressed: _busy || _selected.isEmpty ? null : _order, icon: const Icon(Icons.shopping_cart_checkout), label: Text(tr('Créer les bons de commande'))),
                ]),
              ),
            ),
      body: _rows == null
          ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : _rows!.isEmpty
              ? EmptyState(icon: Icons.inventory_2_outlined, title: tr('Stocks suffisants'), message: tr('Aucun article sous son seuil d\'alerte, commandes en cours comprises.'))
              : ListView(padding: const EdgeInsets.all(16), children: [
                  Text(tr('Articles sous le seuil d\'alerte, commandes en cours déduites. Quantité proposée : de quoi revenir à deux fois le seuil.'), style: TextStyle(color: Theme.of(context).hintColor)),
                  const SizedBox(height: 12),
                  Builder(builder: (context) {
                    final shown = _shownReplenishment();
                    return FilterBar(
                      padding: const EdgeInsets.only(bottom: 12),
                      search: _q,
                      searchHint: 'Article…',
                      onSearch: (v) => setState(() => _q = v.toLowerCase()),
                      filters: [
                        FilterSpec('supplier', 'Fournisseur', {'none': 'Sans fournisseur', for (final r in _rows!) if (r['partner_id'] != null) '${r['partner_id']}': _partner(r['partner_id']).isEmpty ? '${r['partner_name'] ?? r['partner_id']}' : _partner(r['partner_id'])}, icon: Icons.local_shipping_outlined),
                        const FilterSpec('selection', 'Sélection', {'checked': 'Cochés', 'unchecked': 'Non cochés'}, icon: Icons.check_box_outlined),
                      ],
                      values: _f,
                      onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                      count: shown.length,
                      countLabel: 'article',
                      onReset: () => setState(() => (_q = '', _f = {})),
                      trailing: [
                        TextButton(onPressed: () => setState(() => _selected.addAll(shown.map((r) => '${r['product_id']}'))), child: Text(tr('Tout cocher'))),
                        TextButton(onPressed: () => setState(() => _selected.removeAll(shown.map((r) => '${r['product_id']}'))), child: Text(tr('Tout décocher'))),
                      ],
                    );
                  }),
                  if (_shownReplenishment().isEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
                  for (final r in _shownReplenishment())
                    Card(
                      key: ValueKey('repl-${r['product_id']}'),
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Checkbox(
                            value: _selected.contains('${r['product_id']}'),
                            onChanged: (v) => setState(() => v == true ? _selected.add('${r['product_id']}') : _selected.remove('${r['product_id']}')),
                          ),
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                              Text(tr('Stock ${qty(toDouble(r['stock']))} · commandé ${qty(toDouble(r['incoming']))} · seuil ${qty(toDouble(r['min_stock']))}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                              const SizedBox(height: 8),
                              Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                                SizedBox(
                                  width: 240,
                                  child: DropdownButtonFormField<String?>(
                                    initialValue: suppliers.any((p) => p['id'] == r['partner_id']) ? r['partner_id'] as String? : null,
                                    isExpanded: true,
                                    decoration: InputDecoration(labelText: tr('Fournisseur'), isDense: true),
                                    items: [for (final p in suppliers) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
                                    onChanged: (v) => setState(() {
                                      r['partner_id'] = v;
                                      final sp = LocalDb.all('supplier_prices').where((x) => x['partner_id'] == v && x['product_id'] == r['product_id']).firstOrNull;
                                      if (sp != null) r['unit_price'] = toDouble(sp['price']);
                                    }),
                                  ),
                                ),
                                SizedBox(
                                  width: 100,
                                  child: TextFormField(
                                    initialValue: qty(toDouble(r['quantity'])),
                                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                    decoration: InputDecoration(labelText: tr('Quantité'), isDense: true),
                                    onChanged: (v) => setState(() => r['quantity'] = toDouble(v)),
                                  ),
                                ),
                                SizedBox(
                                  width: 120,
                                  child: TextFormField(
                                    key: ValueKey('p-${r['product_id']}-${r['partner_id']}'),
                                    initialValue: '${toDouble(r['unit_price']).round()}',
                                    keyboardType: TextInputType.number,
                                    decoration: InputDecoration(labelText: tr('Prix HT'), isDense: true),
                                    onChanged: (v) => setState(() => r['unit_price'] = toDouble(v)),
                                  ),
                                ),
                                if (r['lead_days'] != null) StatusChip(tr('Délai ${r['lead_days']} j'), color: YColors.muted),
                              ]),
                            ]),
                          ),
                        ]),
                      ),
                    ),
                ]),
    );
  }
}

// ======================================================================
// Réceptions
// ======================================================================

class ReceiptsScreen extends StatefulWidget {
  const ReceiptsScreen({super.key});

  @override
  State<ReceiptsScreen> createState() => _ReceiptsScreenState();
}

class _ReceiptsScreenState extends State<ReceiptsScreen> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _statuses = {'': 'Tout', 'waiting': 'À réceptionner', 'history': 'Historique'};

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return Scaffold(
      appBar: AppBar(title: Text(tr('Réceptions'))),
      body: ValueListenableBuilder(
        valueListenable: LocalDb.listen('purchase_documents'),
        builder: (context, _, _) => ValueListenableBuilder(
          valueListenable: LocalDb.listen('purchase_receipts'),
          builder: (context, _, _) {
            final allWaiting = LocalDb.all('purchase_documents').where((d) => d['type'] == 'purchase_order' && d['status'] == 'validated' && d['receipt_status'] != 'received').toList();
            final allDone = LocalDb.all('purchase_receipts');
            final sup = _f['supplier'], rs = _f['receipt'], hs = _f['state'];
            final waiting = allWaiting.where((d) {
              if (_q.isNotEmpty && !'${d['number'] ?? ''} ${d['partner_name'] ?? ''} ${d['reference'] ?? ''}'.toLowerCase().contains(_q)) return false;
              if (sup != null && '${d['partner_id']}' != sup) return false;
              if (rs != null && '${d['receipt_status'] ?? 'none'}' != rs) return false;
              if (hs != null) return false;
              if (_period != null && !_period!.contains(d['due_date'] ?? d['date'])) return false;
              return true;
            }).toList()
              ..sort((a, b) => '${a['due_date'] ?? '9'}'.compareTo('${b['due_date'] ?? '9'}'));
            final done = allDone.where((r) {
              if (_q.isNotEmpty && !'${r['number'] ?? ''} ${_partner(r['partner_id'])} ${(r['lines'] as List? ?? const []).map((l) => l['description']).join(' ')}'.toLowerCase().contains(_q)) return false;
              if (sup != null && '${r['partner_id']}' != sup) return false;
              if (rs != null) return false;
              if (hs != null && (hs == 'cancelled') != (r['status'] == 'cancelled')) return false;
              if (_period != null && !_period!.contains(r['date'])) return false;
              return true;
            }).toList()
              ..sort((a, b) => '${b['date']}${b['number'] ?? ''}'.compareTo('${a['date']}${a['number'] ?? ''}'));
            final suppliers = {
              for (final d in allWaiting) if (d['partner_id'] != null) '${d['partner_id']}': '${d['partner_name'] ?? _partner(d['partner_id'])}',
              for (final r in allDone) if (r['partner_id'] != null && _partner(r['partner_id']).isNotEmpty) '${r['partner_id']}': _partner(r['partner_id']),
            };
            final canReceive = s.can('achats.receive') || s.can('achats.manage');
            final showWaiting = _status != 'history', showDone = _status != 'waiting';
            return ListView(padding: const EdgeInsets.all(16), children: [
              FilterBar(
                padding: const EdgeInsets.only(bottom: 12),
                search: _q,
                searchHint: 'N°, fournisseur, article…',
                onSearch: (v) => setState(() => _q = v.toLowerCase()),
                statuses: _statuses,
                status: _status,
                onStatus: (v) => setState(() => _status = v),
                statusCounts: {'': waiting.length + done.length, 'waiting': waiting.length, 'history': done.length},
                filters: [
                  FilterSpec('supplier', 'Fournisseur', Map.fromEntries(suppliers.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()))), icon: Icons.local_shipping_outlined),
                  if (showWaiting) FilterSpec('receipt', 'Avancement', {'none': receiptLabels['none']!, 'partial': receiptLabels['partial']!}, icon: Icons.pending_actions_outlined),
                  if (showDone) const FilterSpec('state', 'État', {'done': 'Validées', 'cancelled': 'Annulées'}, icon: Icons.fact_check_outlined),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                showPeriod: true,
                period: _period,
                onPeriod: (v) => setState(() => _period = v),
                count: (showWaiting ? waiting.length : 0) + (showDone ? done.length : 0),
                countLabel: 'élément',
                onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
              ),
              if (showWaiting)
              SectionCard(
                title: tr('À réceptionner (${waiting.length})'),
                child: waiting.isEmpty
                    ? Text(tr('Aucune commande en attente.'), style: TextStyle(color: Theme.of(context).hintColor))
                    : Column(children: [
                        for (final d in waiting)
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.local_shipping_outlined),
                            title: Text(tr('${d['number'] ?? ''} · ${d['partner_name'] ?? ''}')),
                            subtitle: Text(tr([
                              receiptLabels[d['receipt_status'] ?? 'none'] ?? '',
                              if (d['due_date'] != null) 'prévue le ${dateFr(d['due_date'])}',
                            ].join(' · '))),
                            trailing: canReceive ? FilledButton.tonal(onPressed: () => receiveOrder(context, d), child: Text(tr('Réceptionner'))) : null,
                            onTap: s.can('achats.view') ? () => openPurchaseDocument(context, '${d['id']}') : null,
                          ),
                      ]),
              ),
              if (showWaiting && showDone) const SizedBox(height: 12),
              if (showDone)
              SectionCard(
                title: tr('Historique (${done.length})'),
                child: done.isEmpty
                    ? Text(tr('Aucune réception.'), style: TextStyle(color: Theme.of(context).hintColor))
                    : Column(children: [
                        for (final r in done.take(200))
                          ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            leading: Icon(r['status'] == 'cancelled' ? Icons.block : Icons.inventory_2_outlined, color: r['status'] == 'cancelled' ? YColors.muted : YColors.success),
                            title: Text(tr('${r['number'] ?? 'en attente'} · ${_partner(r['partner_id'])} · ${dateFr(r['date'])}')),
                            subtitle: Text(tr((r['lines'] as List? ?? const []).map((l) => '${qty(toDouble(l['quantity']))} × ${l['description']}').join(' · '))),
                            onTap: s.can('achats.view') ? () => openPurchaseDocument(context, '${r['order_id']}') : null,
                          ),
                      ]),
              ),
            ]);
          },
        ),
      ),
    );
  }
}

// ======================================================================
// Tarifs fournisseurs
// ======================================================================

class SupplierPricesScreen extends StatefulWidget {
  const SupplierPricesScreen({super.key});

  @override
  State<SupplierPricesScreen> createState() => _SupplierPricesScreenState();
}

class _SupplierPricesScreenState extends State<SupplierPricesScreen> {
  String _q = '';
  Map<String, String?> _f = {};

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final canEdit = s.can('achats.manage');
    return Scaffold(
      appBar: AppBar(title: Text(tr('Tarifs fournisseurs'))),
      floatingActionButton: canEdit ? FloatingActionButton.extended(onPressed: () => _edit(context), icon: const Icon(Icons.add), label: Text(tr('Tarif'))) : null,
      body: ValueListenableBuilder(
        valueListenable: LocalDb.listen('supplier_prices'),
        builder: (context, _, _) {
          final allPrices = LocalDb.all('supplier_prices');
          final sup = _f['supplier'], pref = _f['preferred'];
          final byProduct = <String, List<Map<String, dynamic>>>{};
          for (final p in allPrices) {
            if (sup != null && '${p['partner_id']}' != sup) continue;
            if (pref == 'yes' && p['preferred'] != true) continue;
            byProduct.putIfAbsent('${p['product_id']}', () => []).add(p);
          }
          final products = byProduct.keys.map((id) => LocalDb.get('products', id)).whereType<Map<String, dynamic>>().where((p) => _q.isEmpty || '${p['name']} ${p['sku'] ?? ''}'.toLowerCase().contains(_q)).toList()
            ..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
          return ListView(padding: const EdgeInsets.all(16), children: [
            FilterBar(
              padding: EdgeInsets.zero,
              search: _q,
              searchHint: 'Article…',
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              filters: [
                FilterSpec('supplier', 'Fournisseur', filterOptions(allPrices.where((p) => _partner(p['partner_id']).isNotEmpty), 'partner_id', label: (p) => _partner(p['partner_id'])), icon: Icons.local_shipping_outlined),
                const FilterSpec('preferred', 'Préférence', {'yes': 'Fournisseur préféré'}, icon: Icons.star_outline),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: products.length,
              countLabel: 'article',
              onReset: () => setState(() => (_q = '', _f = {})),
            ),
            const SizedBox(height: 8),
            Text(tr('Mémorisés à chaque bon de commande validé. Le fournisseur préféré est proposé en premier.'), style: TextStyle(color: Theme.of(context).hintColor)),
            const SizedBox(height: 12),
            if (products.isEmpty && allPrices.isNotEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
            else if (products.isEmpty) EmptyState(icon: Icons.price_change_outlined, title: tr('Aucun tarif'), message: tr('Validez un bon de commande ou ajoutez un tarif.')),
            for (final p in products)
              SectionCard(
                title: '${p['name']}',
                child: Column(children: [
                  for (final sp in (byProduct['${p['id']}']!..sort((a, b) => toDouble(a['price']).compareTo(toDouble(b['price'])))))
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(sp['preferred'] == true ? Icons.star : Icons.storefront_outlined, color: sp['preferred'] == true ? YColors.warning : null),
                      title: Text(tr(_partner(sp['partner_id']))),
                      subtitle: Text(tr([
                        if (toDouble(sp['min_qty']) > 1) 'à partir de ${qty(toDouble(sp['min_qty']))}',
                        if (sp['lead_days'] != null) 'délai ${sp['lead_days']} j',
                        if (sp['supplier_ref'] != null) 'réf. ${sp['supplier_ref']}',
                        if (sp['last_purchase_at'] != null) 'dernier achat ${dateFr(sp['last_purchase_at'])}',
                      ].join(' · '))),
                      trailing: Text(tr(money(toDouble(sp['price']), s.currency)), style: const TextStyle(fontWeight: FontWeight.w700)),
                      onTap: canEdit ? () => _edit(context, sp) : null,
                    ),
                ]),
              ),
          ]);
        },
      ),
    );
  }

  Future<void> _edit(BuildContext context, [Map<String, dynamic>? sp]) async {
    final sync = context.read<SyncEngine>();
    final products = LocalDb.all('products').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final suppliers = LocalDb.all('partners').where((p) => p['type'] == 'supplier' || p['type'] == 'both').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    String? product = sp?['product_id'] as String?;
    String? partner = sp?['partner_id'] as String?;
    var preferred = sp?['preferred'] == true;
    final price = TextEditingController(text: sp == null ? '' : '${toDouble(sp['price']).round()}');
    final minQ = TextEditingController(text: sp == null ? '1' : qty(toDouble(sp['min_qty'])));
    final lead = TextEditingController(text: '${sp?['lead_days'] ?? ''}');
    final ref = TextEditingController(text: '${sp?['supplier_ref'] ?? ''}');
    await showDialog(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Tarif fournisseur')),
          content: SizedBox(
            width: 440,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              DropdownButtonFormField<String?>(
                initialValue: product,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Article')),
                items: [for (final p in products) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: sp == null ? (v) => set(() => product = v) : null,
              ),
              DropdownButtonFormField<String?>(
                initialValue: partner,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Fournisseur')),
                items: [for (final p in suppliers) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: sp == null ? (v) => set(() => partner = v) : null,
              ),
              Row(children: [
                Expanded(child: TextField(controller: price, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Prix HT')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: minQ, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Quantité minimale')))),
              ]),
              Row(children: [
                Expanded(child: TextField(controller: lead, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Délai (jours)')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: ref, decoration: InputDecoration(labelText: tr('Référence fournisseur')))),
              ]),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Fournisseur préféré')), value: preferred, onChanged: (v) => set(() => preferred = v)),
            ]),
          ),
          actions: [
            if (sp != null)
              TextButton(
                onPressed: () async {
                  await sync.remove('supplier_prices', '${sp['id']}');
                  if (c.mounted) Navigator.pop(c);
                },
                child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
              ),
            TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
            FilledButton(
              onPressed: () async {
                if (product == null || partner == null) return;
                await sync.save('supplier_prices', {
                  'id': sp?['id'] ?? newId(),
                  'product_id': product,
                  'partner_id': partner,
                  'price': toDouble(price.text),
                  'min_qty': toDouble(minQ.text) > 0 ? toDouble(minQ.text) : 1,
                  'lead_days': int.tryParse(lead.text),
                  'supplier_ref': ref.text.trim().isEmpty ? null : ref.text.trim(),
                  'preferred': preferred,
                });
                if (c.mounted) Navigator.pop(c);
              },
              child: Text(tr('Enregistrer')),
            ),
          ],
        ),
      ),
    );
  }
}
