import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/project_picker.dart';
import 'appro_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Portail fournisseurs : appels d'offres, offres comparées, factures déposées, confirmations de commande, accès.

const spTenderStatuses = {'draft': 'Brouillon', 'open': 'Ouvert', 'closed': 'Clos — à attribuer', 'awarded': 'Attribué', 'cancelled': 'Annulé'};
const spInvoiceStatuses = {'received': 'À traiter', 'accepted': 'Acceptée', 'rejected': 'Refusée'};

bool spManage(Session s) => s.can('portail_fournisseurs.manage');

Color spTenderColor(String s) => switch (s) { 'open' => YColors.blue, 'closed' => YColors.warning, 'awarded' => YColors.success, 'cancelled' => YColors.muted, _ => YColors.muted };

List<Map<String, dynamic>> _all(String e) => LocalDb.hasEntity(e) ? LocalDb.all(e) : <Map<String, dynamic>>[];

String _name(dynamic partnerId) => '${LocalDb.get('partners', '${partnerId ?? ''}')?['name'] ?? '—'}';

String _qty(dynamic v) {
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.toInt()}' : d.toStringAsFixed(2).replaceAll('.', ',');
}

class FournisseursScreen extends StatelessWidget {
  const FournisseursScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final narrow = MediaQuery.of(context).size.width < 700;
    return DefaultTabController(
      length: 4,
      child: Column(children: [
        PageHeader(
          title: tr('Portail fournisseurs'),
          subtitle: tr('Appels d\'offres, confirmations de commande, factures déposées et paiements'),
          actions: [if (spManage(session)) FilledButton.icon(onPressed: () => editTender(context), icon: const Icon(Icons.add), label: Text(tr('Appel d\'offres')))],
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
          child: Align(
            alignment: Alignment.centerLeft,
            child: TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Appels d\'offres')), Tab(text: tr('Factures déposées')), Tab(text: tr('Confirmations')), Tab(text: tr('Fournisseurs'))]),
          ),
        ),
        const SizedBox(height: 8),
        const Expanded(child: TabBarView(children: [_TendersTab(), _InvoicesTab(), _AcksTab(), _AccessTab()])),
      ]),
    );
  }
}

// ======================================================================
// Appels d'offres

class _TendersTab extends StatefulWidget {
  const _TendersTab();

  @override
  State<_TendersTab> createState() => _TendersTabState();
}

class _TendersTabState extends State<_TendersTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return AnimatedBuilder(
      animation: Listenable.merge([LocalDb.listen('sp_tenders'), LocalDb.listen('sp_bids')]),
      builder: (context, _) {
        final all = _all('sp_tenders')..sort((a, b) => '${b['created_at'] ?? '9'}'.compareTo('${a['created_at'] ?? '9'}'));
        final bids = _all('sp_bids');
        if (all.isEmpty) {
          return EmptyState(icon: Icons.gavel_outlined, title: tr('Aucun appel d\'offres'), message: tr('Mettez plusieurs fournisseurs en concurrence : ils répondent en ligne et vous comparez les prix ligne par ligne.'));
        }
        final invited = <String, String>{};
        for (final t in all) {
          for (final id in (t['invited'] as List? ?? const [])) {
            final n = _name(id);
            if (n != '—') invited['$id'] = n;
          }
        }
        final base = all.where((t) {
          if (_q.isNotEmpty && !'${t['title']} ${t['number'] ?? ''}'.toLowerCase().contains(_q)) return false;
          final sup = _f['supplier'], bd = _f['bids'];
          if (sup != null && !(t['invited'] as List? ?? const []).any((id) => '$id' == sup)) return false;
          final n = bids.where((b) => b['tender_id'] == t['id']).length;
          if (bd == 'none' && n > 0) return false;
          if (bd == 'some' && n == 0) return false;
          if (_period != null && !_period!.contains(t['deadline'] ?? t['created_at'])) return false;
          return true;
        }).toList();
        final statuses = {'': 'Tous', ...spTenderStatuses};
        final rows = base.where((t) => _status.isEmpty || t['status'] == _status).toList();
        return ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
          FilterBar(
            padding: const EdgeInsets.only(bottom: 12),
            search: _q,
            searchHint: 'Objet ou n° de l\'appel d\'offres…',
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            statuses: statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in statuses.keys) k: k.isEmpty ? base.length : base.where((t) => t['status'] == k).length},
            filters: [
              FilterSpec('supplier', 'Fournisseur invité', Map.fromEntries(invited.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()))), icon: Icons.local_shipping_outlined),
              const FilterSpec('bids', 'Offres', {'none': 'Sans offre', 'some': 'Avec offres'}, icon: Icons.request_quote_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (v) => setState(() => _period = v),
            count: rows.length,
            countLabel: 'appel',
            onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
          ),
          if (rows.isEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
          for (final t in rows)
            Card(
              child: ListTile(
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => TenderScreen(id: '${t['id']}'))),
                leading: CircleAvatar(backgroundColor: spTenderColor('${t['status']}').withValues(alpha: .12), child: Icon(Icons.gavel, color: spTenderColor('${t['status']}'))),
                title: Text(tr('${t['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(tr([
                  '${t['number'] ?? 'Nouveau'}',
                  '${(t['lines'] as List? ?? const []).length} article(s)',
                  '${(t['invited'] as List? ?? const []).length} fournisseur(s) invité(s)',
                  '${bids.where((b) => b['tender_id'] == t['id']).length} offre(s)',
                  'date limite ${dateTimeFr(t['deadline'])}',
                ].join(' · ')), style: TextStyle(color: hint)),
                trailing: StatusChip(spTenderStatuses[t['status']] ?? '${t['status']}', color: spTenderColor('${t['status']}')),
              ),
            ),
        ]);
      },
    );
  }
}

/// Fiche de l'appel d'offres : comparaison des offres et attribution.
class TenderScreen extends StatefulWidget {
  const TenderScreen({super.key, required this.id});

  final String id;

  @override
  State<TenderScreen> createState() => _TenderScreenState();
}

class _TenderScreenState extends State<TenderScreen> {
  bool _busy = false;

  Future<void> _status(String st, String done) async {
    await context.read<SyncEngine>().save('sp_tenders', {'id': widget.id, 'status': st});
    if (mounted) toast(context, done);
  }

  Future<void> _award(Map<String, dynamic> t, Map<String, dynamic> bid) async {
    if (!await confirm(context, tr('Attribuer à ${bid['partner_name']} ?'), tr('Un bon de commande en brouillon est créé avec ses prix ; les autres fournisseurs sont prévenus.'), ok: tr('Attribuer'))) return;
    if (!mounted) return;
    setState(() => _busy = true);
    final sync = context.read<SyncEngine>();
    final api = context.read<Session>().api;
    try {
      await sync.sync();
      final r = await api.post('portail_fournisseurs/tenders/${widget.id}/award', {'partner_id': bid['partner_id']});
      await LocalDb.put('sp_tenders', asMap(r['tender']));
      await LocalDb.put('purchase_documents', asMap(r['order']));
      await sync.sync();
      if (mounted) toast(context, tr('Bon de commande ${r['order']['number'] ?? ''} créé en brouillon dans les Achats.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final hint = Theme.of(context).hintColor;
    return AnimatedBuilder(
      animation: Listenable.merge([LocalDb.listen('sp_tenders'), LocalDb.listen('sp_bids')]),
      builder: (context, _) {
        final t = LocalDb.get('sp_tenders', widget.id);
        if (t == null) return Scaffold(appBar: AppBar(), body: Center(child: Text(tr('Appel d\'offres introuvable.'))));
        final st = '${t['status']}';
        final lines = [for (final l in t['lines'] as List? ?? const []) asMap(l)];
        final bids = _all('sp_bids').where((b) => b['tender_id'] == widget.id).toList()..sort((a, b) => toDouble(a['total']).compareTo(toDouble(b['total'])));
        double? price(Map<String, dynamic> b, String lineId) {
          final x = (b['lines'] as List? ?? const []).cast<Map>().where((e) => e['line_id'] == lineId).firstOrNull;
          return x == null || x['unit_price'] == null ? null : toDouble(x['unit_price']);
        }

        bool complete(Map<String, dynamic> b) => lines.every((l) => price(b, '${l['id']}') != null);
        final completeBids = bids.where(complete).toList();
        final cheapest = completeBids.isEmpty ? null : completeBids.first['partner_id'];
        final cur = '${t['currency'] ?? session.currency}';
        final canAward = spManage(session) && (st == 'open' || st == 'closed') && !_busy;
        return Scaffold(
          appBar: AppBar(title: Text(tr('${t['number'] ?? ''} · ${t['title']}'), overflow: TextOverflow.ellipsis)),
          body: ListView(padding: const EdgeInsets.all(20), children: [
            Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
              StatusChip(spTenderStatuses[st] ?? st, color: spTenderColor(st)),
              Text(tr('Date limite : ${dateTimeFr(t['deadline'])}'), style: TextStyle(color: hint)),
              if (t['delivery_place'] != null) Text(tr('· Livraison : ${t['delivery_place']}'), style: TextStyle(color: hint)),
              const SizedBox(width: 12),
              if (spManage(session) && st == 'draft') ...[
                OutlinedButton.icon(onPressed: () => editTender(context, t), icon: const Icon(Icons.edit_outlined), label: Text(tr('Modifier'))),
                FilledButton.icon(onPressed: () => _status('open', 'Appel d\'offres publié : les fournisseurs sont invités.'), icon: const Icon(Icons.send), label: Text(tr('Publier'))),
              ],
              if (spManage(session) && st == 'open') OutlinedButton(onPressed: () => _status('closed', 'Appel d\'offres clos.'), child: Text(tr('Clore maintenant'))),
              if (st != 'draft' && st != 'cancelled') CompareButton(type: 'tender', id: widget.id, label: tr('Comparatif détaillé')),
              if (spManage(session) && (st == 'open' || st == 'closed' || st == 'draft'))
                TextButton(
                  onPressed: () async {
                    if (await confirm(context, tr('Annuler l\'appel d\'offres ?'), tr('Les fournisseurs qui ont répondu sont prévenus.'), danger: true, ok: tr('Annuler l\'appel d\'offres'))) await _status('cancelled', 'Appel d\'offres annulé.');
                  },
                  child: Text(tr('Annuler'), style: TextStyle(color: YColors.danger)),
                ),
            ]),
            if ('${t['description'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('${t['description']}'))),
            if (st == 'awarded')
              Card(
                color: const Color(0xFFE7F6EE),
                child: ListTile(leading: const Icon(Icons.verified, color: YColors.success), title: Text(tr('Attribué à ${_name(t['awarded_partner_id'])}')), subtitle: Text(tr('Bon de commande ${LocalDb.get('purchase_documents', '${t['order_id']}')?['number'] ?? 'créé'} dans les Achats'))),
              ),
            const SizedBox(height: 14),
            Text(tr('Comparaison des offres (${bids.length} reçue(s) sur ${(t['invited'] as List? ?? const []).length} invité(s))'), style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            if (bids.isEmpty)
              Card(child: Padding(padding: const EdgeInsets.all(16), child: Text(tr(st == 'draft' ? 'Publiez l\'appel d\'offres pour inviter les fournisseurs.' : 'Aucune offre pour le moment.'), style: TextStyle(color: hint))))
            else
              Card(
                clipBehavior: Clip.antiAlias,
                child: SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: DataTable(
                    columnSpacing: 22,
                    headingRowHeight: 64,
                    columns: [
                      DataColumn(label: Text(tr('Article'))),
                      DataColumn(label: Text(tr('Qté')), numeric: true),
                      for (final b in bids)
                        DataColumn(
                          numeric: true,
                          label: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                            Text(tr('${b['partner_name']}'), style: const TextStyle(fontWeight: FontWeight.w800)),
                            Text(tr([if (b['delivery_days'] != null) '${b['delivery_days']} j', if (toDouble(b['revisions']) > 0) 'révisée'].join(' · ')), style: TextStyle(fontSize: 11, color: hint)),
                          ]),
                        ),
                    ],
                    rows: [
                      for (final l in lines)
                        DataRow(cells: [
                          DataCell(ConstrainedBox(constraints: const BoxConstraints(maxWidth: 260), child: Text(tr('${l['description']}'), overflow: TextOverflow.ellipsis))),
                          DataCell(Text(tr('${_qty(l['quantity'])} ${l['unit'] ?? ''}'))),
                          for (final b in bids)
                            DataCell(Builder(builder: (_) {
                              final p = price(b, '${l['id']}');
                              final valid = [for (final x in bids) price(x, '${l['id']}')].whereType<double>().toList();
                              final best = p != null && valid.isNotEmpty && p == valid.reduce((a, c) => a < c ? a : c);
                              return Container(
                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(color: best && valid.length > 1 ? YColors.success.withValues(alpha: .14) : null, borderRadius: BorderRadius.circular(6)),
                                child: Text(tr(p == null ? 'non proposé' : number(p)), style: TextStyle(fontWeight: best ? FontWeight.w800 : FontWeight.w500, color: p == null ? hint : null)),
                              );
                            })),
                        ]),
                      DataRow(color: WidgetStatePropertyAll(YColors.line.withValues(alpha: .4)), cells: [
                        DataCell(Text(tr('Total HT'), style: TextStyle(fontWeight: FontWeight.w800))),
                        const DataCell(Text('')),
                        for (final b in bids)
                          DataCell(Row(mainAxisSize: MainAxisSize.min, children: [
                            if (b['partner_id'] == cheapest) const Padding(padding: EdgeInsets.only(right: 4), child: Icon(Icons.emoji_events, size: 16, color: YColors.warning)),
                            Text(tr(money(toDouble(b['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                            if (!complete(b)) Text(tr(' (partielle)'), style: TextStyle(fontSize: 11, color: hint)),
                          ])),
                      ]),
                      if (canAward || st == 'awarded')
                        DataRow(cells: [
                          const DataCell(Text('')),
                          const DataCell(Text('')),
                          for (final b in bids)
                            DataCell(st == 'awarded'
                                ? StatusChip(b['status'] == 'awarded' ? 'Retenue' : 'Non retenue', color: b['status'] == 'awarded' ? YColors.success : YColors.muted)
                                : FilledButton.tonal(onPressed: () => _award(t, b), child: Text(tr('Attribuer')))),
                        ]),
                    ],
                  ),
                ),
              ),
            for (final b in bids.where((b) => '${b['comment'] ?? ''}'.isNotEmpty))
              Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('« ${b['comment']} » — ${b['partner_name']}'), style: TextStyle(color: hint, fontStyle: FontStyle.italic))),
          ]),
        );
      },
    );
  }
}

/// Création ou modification d'un appel d'offres (brouillon), avec publication immédiate possible.
Future<void> editTender(BuildContext context, [Map<String, dynamic>? t]) async {
  final sync = context.read<SyncEngine>();
  final title = TextEditingController(text: '${t?['title'] ?? ''}');
  final desc = TextEditingController(text: '${t?['description'] ?? ''}');
  final place = TextEditingController(text: '${t?['delivery_place'] ?? ''}');
  final lines = <Map<String, TextEditingController>>[
    for (final l in (t?['lines'] as List? ?? const [{'description': '', 'quantity': 1, 'unit': ''}]))
      {'description': TextEditingController(text: '${l['description'] ?? ''}'), 'quantity': TextEditingController(text: _qty(l['quantity'])), 'unit': TextEditingController(text: '${l['unit'] ?? ''}'), 'id': TextEditingController(text: '${l['id'] ?? ''}')},
  ];
  final suppliers = LocalDb.all('partners').where((p) => p['type'] == 'supplier' || p['type'] == 'both').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
  final invited = <String>{for (final x in (t?['invited'] as List? ?? const [])) '$x'};
  String? project = t?['project_id'] as String?;
  final withProjects = projectsEnabled(context.read<Session>());
  var deadline = DateTime.tryParse('${t?['deadline'] ?? ''}')?.toLocal() ?? DateTime.now().add(const Duration(days: 7)).copyWith(hour: 17, minute: 0, second: 0, millisecond: 0, microsecond: 0);
  Future<void> save(BuildContext ctx, bool publish) async {
    final ls = [
      for (final l in lines)
        if (l['description']!.text.trim().isNotEmpty)
          {if (l['id']!.text.isNotEmpty) 'id': l['id']!.text, 'description': l['description']!.text.trim(), 'quantity': toDouble(l['quantity']!.text), 'unit': l['unit']!.text.trim().isEmpty ? null : l['unit']!.text.trim()},
    ];
    if (title.text.trim().isEmpty) return toast(ctx, tr('Donnez un titre.'), error: true);
    if (ls.isEmpty || ls.any((l) => toDouble(l['quantity']) <= 0)) return toast(ctx, tr('Ajoutez au moins un article avec sa quantité.'), error: true);
    if (invited.isEmpty) return toast(ctx, tr('Invitez au moins un fournisseur.'), error: true);
    if (publish && deadline.isBefore(DateTime.now())) return toast(ctx, tr('La date limite doit être dans le futur.'), error: true);
    await sync.save('sp_tenders', {
      'id': t?['id'] ?? newId(),
      'title': title.text.trim(),
      'description': desc.text.trim().isEmpty ? null : desc.text.trim(),
      'delivery_place': place.text.trim().isEmpty ? null : place.text.trim(),
      'lines': ls,
      if (withProjects) 'project_id': project,
      'invited': invited.toList(),
      'deadline': deadline.toUtc().toIso8601String(),
      'status': publish ? 'open' : 'draft',
      'created_at': t?['created_at'] ?? DateTime.now().toUtc().toIso8601String(),
    });
    if (ctx.mounted) {
      Navigator.pop(ctx);
      toast(ctx, publish ? 'Appel d\'offres publié : les fournisseurs reçoivent leur lien.' : 'Brouillon enregistré.');
    }
  }

  await showFormDialog(
    context,
    title: t == null ? 'Nouvel appel d\'offres' : 'Appel d\'offres',
    maxWidth: 760,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(label: tr('Objet'), child: TextField(controller: title, autofocus: t == null, decoration: InputDecoration(hintText: tr('Ex. Matériaux pour le chantier de Cocody')))),
        Field(label: tr('Description (conditions, qualité attendue…)'), child: TextField(controller: desc, maxLines: 3)),
        Row(children: [
          Expanded(
            child: Field(
              label: tr('Date limite des offres'),
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await showDatePicker(context: ctx, initialDate: deadline, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)));
                  if (d == null || !ctx.mounted) return;
                  final h = await showTimePicker(context: ctx, initialTime: TimeOfDay.fromDateTime(deadline));
                  set(() => deadline = DateTime(d.year, d.month, d.day, h?.hour ?? 17, h?.minute ?? 0));
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr(DateFormat('dd/MM/yyyy HH:mm').format(deadline))),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Lieu de livraison'), child: TextField(controller: place))),
        ]),
        if (withProjects) Field(label: tr('Projet (le bon de commande attribué y sera rattaché)'), child: ProjectDropdown(value: project, onChanged: (v) => set(() => project = v))),
        Text(tr('Articles'), style: Theme.of(ctx).textTheme.titleSmall),
        for (var i = 0; i < lines.length; i++)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(children: [
              Expanded(flex: 5, child: TextField(controller: lines[i]['description'], decoration: InputDecoration(isDense: true, hintText: tr('Désignation')))),
              const SizedBox(width: 8),
              SizedBox(width: 90, child: TextField(controller: lines[i]['quantity'], keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(isDense: true, hintText: tr('Qté')))),
              const SizedBox(width: 8),
              SizedBox(width: 90, child: TextField(controller: lines[i]['unit'], decoration: InputDecoration(isDense: true, hintText: tr('Unité')))),
              IconButton(onPressed: lines.length == 1 ? null : () => set(() => lines.removeAt(i)), icon: const Icon(Icons.remove_circle_outline)),
            ]),
          ),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(onPressed: () => set(() => lines.add({'description': TextEditingController(), 'quantity': TextEditingController(text: '1'), 'unit': TextEditingController(), 'id': TextEditingController()})), icon: const Icon(Icons.add), label: Text(tr('Article'))),
        ),
        Field(
          label: tr('Fournisseurs invités'),
          child: suppliers.isEmpty
              ? Text(tr('Créez d\'abord vos fournisseurs (Clients & fournisseurs).'))
              : Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final p in suppliers) FilterChip(label: Text(tr('${p['name']}')), selected: invited.contains(p['id']), onSelected: (v) => set(() => v ? invited.add('${p['id']}') : invited.remove('${p['id']}'))),
                ]),
        ),
        const SizedBox(height: 8),
        Row(children: [
          if (t != null)
            TextButton(
              onPressed: () async {
                await sync.remove('sp_tenders', '${t['id']}');
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          OutlinedButton(onPressed: () => save(ctx, false), child: Text(tr('Enregistrer le brouillon'))),
          const SizedBox(width: 8),
          FilledButton.icon(onPressed: () => save(ctx, true), icon: const Icon(Icons.send), label: Text(tr('Publier'))),
        ]),
      ]),
    ),
  );
}

// ======================================================================
// Factures déposées

const spMatchLabels = {'ok': 'Conforme', 'partial': 'Réception partielle', 'not_received': 'Rien de reçu', 'over': 'Dépasse la commande', 'amount_gap': 'Écart de montant', 'no_order': 'Sans commande'};

Color spMatchColor(String s) => switch (s) { 'ok' => YColors.success, 'partial' => YColors.blue, 'no_order' => YColors.muted, _ => YColors.warning };

/// Importe une facture fournisseur (PDF ou photo) : lecture automatique, puis confirmation des champs.
Future<void> importSupplierInvoice(BuildContext context) async {
  final api = context.read<Session>().api;
  final cur = context.read<Session>().currency;
  final sync = context.read<SyncEngine>();
  final picked = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: const ['pdf', 'png', 'jpg', 'jpeg', 'webp']);
  if (picked.isEmpty || !context.mounted) return;
  final f = picked.single;
  final bytes = await f.readAsBytes();
  if (!context.mounted) return;
  Map<String, dynamic> x = {};
  showDialog(context: context, barrierDismissible: false, builder: (_) => YAlertDialog(content: Row(children: [CircularProgressIndicator(), SizedBox(width: 16), Expanded(child: Text(tr('Lecture de la facture…')))])));
  try {
    x = asMap((await api.uploadForm('portail_fournisseurs/invoices/read', {}, 'file', bytes, f.name))['data']);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
  if (!context.mounted) return;
  Navigator.of(context).pop();
  final suppliers = LocalDb.all('partners').where((p) => p['type'] == 'supplier' || p['type'] == 'both' || p['id'] == x['partner_id']).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
  String? partner = x['partner_id'] as String?;
  String? order = x['order_id'] as String?;
  final number = TextEditingController(text: '${x['number'] ?? ''}');
  final amount = TextEditingController(text: x['total'] == null ? '' : _qty(x['total']));
  final comment = TextEditingController();
  var date = DateTime.tryParse('${x['date'] ?? ''}') ?? DateTime.now();
  final readable = x['readable'] == true;
  await showFormDialog(
    context,
    title: tr('Importer une facture fournisseur'),
    maxWidth: 620,
    builder: (ctx) => StatefulBuilder(builder: (ctx, set) {
      final orders = partner == null
          ? <Map<String, dynamic>>[]
          : LocalDb.all('purchase_documents').where((d) => d['type'] == 'purchase_order' && d['status'] == 'validated' && d['partner_id'] == partner).toList();
      if (order != null && !orders.any((o) => o['id'] == order)) order = null;
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Container(
          padding: const EdgeInsets.all(12),
          margin: const EdgeInsets.only(bottom: 12),
          decoration: BoxDecoration(color: (readable ? YColors.success : YColors.warning).withValues(alpha: .08), borderRadius: BorderRadius.circular(10)),
          child: Row(children: [
            Icon(readable ? Icons.auto_awesome : Icons.info_outline, color: readable ? YColors.success : YColors.warning),
            const SizedBox(width: 10),
            Expanded(
              child: Text(tr(readable
                  ? 'Lu automatiquement dans « ${f.name} »${x['ncc'] != null ? ' · NCC ${x['ncc']}' : ''}. Vérifiez avant d\'enregistrer.'
                  : (x['ocr'] == false && !f.name.toLowerCase().endsWith('.pdf')
                      ? 'Image non lue : la reconnaissance de texte (tesseract) n\'est pas installée sur le serveur. Saisissez les champs.'
                      : 'Le document n\'a pas pu être lu (scan de mauvaise qualité ?). Saisissez les champs.'))),
            ),
          ]),
        ),
        Field(
          label: tr('Fournisseur'),
          child: DropdownButtonFormField<String?>(
            initialValue: partner,
            isExpanded: true,
            items: [for (final p in suppliers) DropdownMenuItem(value: '${p['id']}', child: Text(tr('${p['name']}')))],
            onChanged: (v) => set(() => partner = v),
          ),
        ),
        Row(children: [
          Expanded(child: Field(label: tr('N° de facture'), child: TextField(controller: number))),
          const SizedBox(width: 12),
          Expanded(
            child: Field(
              label: tr('Date'),
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await showDatePicker(context: ctx, initialDate: date, firstDate: DateTime(2000), lastDate: DateTime.now());
                  if (d != null) set(() => date = d);
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr(DateFormat('dd/MM/yyyy').format(date))),
              ),
            ),
          ),
        ]),
        Field(
          label: tr('Montant TTC ($cur)${x['subtotal'] != null ? ' — HT lu : ${money(toDouble(x['subtotal']), cur)}' : ''}'),
          child: TextField(controller: amount, keyboardType: const TextInputType.numberWithOptions(decimal: true)),
        ),
        Field(
          label: tr('Bon de commande'),
          child: DropdownButtonFormField<String?>(
            key: ValueKey('po-$partner'),
            initialValue: order,
            isExpanded: true,
            items: [
              DropdownMenuItem(value: null, child: Text(tr('Aucun (facture hors commande)'))),
              for (final o in orders) DropdownMenuItem(value: '${o['id']}', child: Text(tr('${o['number']} · ${dateFr(o['date'])} · ${money(toDouble(o['total']), '${o['currency'] ?? cur}')}'))),
            ],
            onChanged: (v) => set(() => order = v),
          ),
        ),
        Field(label: tr('Commentaire (facultatif)'), child: TextField(controller: comment)),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerRight,
          child: FilledButton.icon(
            icon: const Icon(Icons.check),
            label: Text(tr('Enregistrer la facture')),
            onPressed: () async {
              if (partner == null) return toast(ctx, tr('Choisissez le fournisseur.'), error: true);
              try {
                await api.uploadForm('portail_fournisseurs/invoices', {
                  'partner_id': partner,
                  'number': number.text.trim(),
                  'date': isoDate(date),
                  'amount': amount.text.replaceAll(' ', '').replaceAll(',', '.'),
                  'order_id': ?order,
                  if (comment.text.trim().isNotEmpty) 'comment': comment.text.trim(),
                }, 'file', bytes, f.name);
                await sync.sync();
                if (ctx.mounted) {
                  Navigator.pop(ctx);
                  toast(ctx, tr('Facture enregistrée : contrôlez le rapprochement puis acceptez-la.'));
                }
              } catch (e) {
                if (ctx.mounted) toast(ctx, errorText(e), error: true);
              }
            },
          ),
        ),
      ]);
    }),
  );
}

/// Rapprochement à trois : commandé ↔ reçu ↔ facturé, ligne par ligne.
Future<void> showInvoiceMatch(BuildContext context, Map<String, dynamic> i) async {
  final cur = '${i['currency'] ?? context.read<Session>().currency}';
  Map<String, dynamic> m;
  try {
    m = asMap((await context.read<Session>().api.get('portail_fournisseurs/invoices/${i['id']}/match'))['data']);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return;
  }
  if (!context.mounted) return;
  final st = '${m['status']}';
  final lines = (m['lines'] as List? ?? const []).cast<Map>();
  final warnings = ((i['extracted'] as Map?)?['warnings'] as List? ?? const []);
  Widget kv(String k, String v, {bool strong = false, Color? color}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(children: [Expanded(child: Text(tr(k))), Text(tr(v), style: TextStyle(fontWeight: strong ? FontWeight.w800 : FontWeight.w500, color: color))]),
      );
  await showFormDialog(
    context,
    title: tr('Rapprochement — facture ${i['number']}'),
    maxWidth: 720,
    builder: (ctx) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Row(children: [StatusChip(spMatchLabels[st] ?? st, color: spMatchColor(st)), const SizedBox(width: 10), Expanded(child: Text(tr('${m['label']}')))]),
      const SizedBox(height: 12),
      if (st != 'no_order') ...[
        kv('Commandé (${m['order_number']})', money(toDouble(m['ordered']), cur)),
        kv('Reçu${m['tracks_receipts'] == false ? ' (sans suivi de réception : commande entière)' : ''}', money(toDouble(m['received']), cur)),
        if (toDouble(m['invoiced_before']) > 0) kv('Déjà facturé sur la commande', '− ${money(toDouble(m['invoiced_before']), cur)}'),
        kv('Attendu', money(toDouble(m['expected']), cur), strong: true),
        kv('Facturé', money(toDouble(m['amount']), cur), strong: true),
        kv('Écart (tolérance ${_qty(m['tolerance'])} %)', money(toDouble(m['gap']), cur), color: st == 'ok' || st == 'partial' ? YColors.success : YColors.warning),
        const SizedBox(height: 12),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: DataTable(columnSpacing: 18, headingRowHeight: 36, dataRowMinHeight: 34, dataRowMaxHeight: 48, columns: [
            DataColumn(label: Text(tr('Article'))),
            DataColumn(label: Text(tr('Commandé')), numeric: true),
            DataColumn(label: Text(tr('Reçu')), numeric: true),
            DataColumn(label: Text(tr('Valeur reçue')), numeric: true),
            DataColumn(label: Text('')),
          ], rows: [
            for (final l in lines)
              DataRow(cells: [
                DataCell(ConstrainedBox(constraints: const BoxConstraints(maxWidth: 220), child: Text(tr('${l['description']}'), overflow: TextOverflow.ellipsis))),
                DataCell(Text(tr('${_qty(l['ordered'])} ${l['unit'] ?? ''}'))),
                DataCell(Text(tr(_qty(l['received'])))),
                DataCell(Text(tr(money(toDouble(l['received_total']), cur)))),
                DataCell(Icon(l['state'] == 'ok' ? Icons.check_circle : (l['state'] == 'partial' ? Icons.timelapse : Icons.remove_circle_outline),
                    size: 18, color: l['state'] == 'ok' ? YColors.success : (l['state'] == 'partial' ? YColors.blue : YColors.warning))),
              ]),
          ]),
        ),
      ] else
        Text(tr('Facture sans bon de commande : vérifiez-la avec le demandeur avant de l\'accepter.')),
      if (warnings.isNotEmpty) ...[
        const SizedBox(height: 14),
        Text(tr('Contrôle du document'), style: Theme.of(ctx).textTheme.titleSmall),
        for (final w in warnings) Padding(padding: const EdgeInsets.only(top: 4), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Icon(Icons.warning_amber, size: 16, color: YColors.warning), const SizedBox(width: 6), Expanded(child: Text(tr('$w')))])),
      ],
    ]),
  );
}

Future<void> _matchSettings(BuildContext context) async {
  final api = context.read<Session>().api;
  try {
    final s = asMap((await api.get('portail_fournisseurs/settings'))['data']);
    if (!context.mounted) return;
    final tol = TextEditingController(text: _qty(s['match_tolerance']));
    await showFormDialog(
      context,
      title: tr('Rapprochement des factures'),
      maxWidth: 460,
      builder: (ctx) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(label: tr('Tolérance d\'écart (%)'), child: TextField(controller: tol, keyboardType: const TextInputType.numberWithOptions(decimal: true))),
        Text(tr(s['ocr'] == true ? 'Lecture des factures scannées (photos) : disponible.' : 'Lecture des photos indisponible (tesseract absent du serveur) ; les PDF sont lus normalement.'),
            style: TextStyle(color: Theme.of(ctx).hintColor)),
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerRight,
          child: FilledButton(
            onPressed: () async {
              try {
                await api.put('portail_fournisseurs/settings', {'match_tolerance': toDouble(tol.text)});
                if (ctx.mounted) Navigator.pop(ctx);
              } catch (e) {
                if (ctx.mounted) toast(ctx, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ),
      ]),
    );
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

class _InvoicesTab extends StatefulWidget {
  const _InvoicesTab();

  @override
  State<_InvoicesTab> createState() => _InvoicesTabState();
}

class _InvoicesTabState extends State<_InvoicesTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  Future<void> _open(BuildContext context, Map<String, dynamic> i) async {
    try {
      final bytes = await context.read<Session>().api.download('portail_fournisseurs/invoices/${i['id']}/file');
      final name = '${i['file_name'] ?? 'facture-${i['number']}.pdf'}';
      final where = await saveFile(name, bytes, mime: name.toLowerCase().endsWith('.pdf') ? 'application/pdf' : 'image/jpeg');
      if (context.mounted) toast(context, tr('Facture enregistrée${where.isEmpty ? '' : ' : $where'}'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _decide(BuildContext context, Map<String, dynamic> i, bool accept) async {
    String? reason;
    final m = i['match'] as Map?;
    if (!accept) {
      final c = TextEditingController(text: m != null && const ['over', 'amount_gap', 'not_received'].contains(m['status']) ? '${m['label']}' : '');
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => YAlertDialog(
          title: Text(tr('Refuser la facture')),
          content: SizedBox(width: 420, child: TextField(controller: c, autofocus: true, maxLines: 3, decoration: InputDecoration(labelText: tr('Motif (envoyé au fournisseur)')))),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim().isNotEmpty), child: Text(tr('Refuser')))],
        ),
      );
      if (ok != true) return;
      reason = c.text.trim();
    } else if (!await confirm(
        context,
        tr('Accepter la facture ${i['number']} ?'),
        tr('${m != null && !const ['ok', 'partial', 'no_order'].contains(m['status']) ? 'Attention : ${m['label']}.\n\n' : ''}Une facture fournisseur est créée en brouillon dans les Achats (quantités reçues du bon de commande, ou montant global), à valider puis payer.'),
        ok: tr('Accepter'))) {
      return;
    }
    if (!context.mounted) return;
    await context.read<SyncEngine>().save('sp_invoices', {'id': i['id'], 'status': accept ? 'accepted' : 'rejected', 'reject_reason': ?reason});
    if (context.mounted) toast(context, accept ? 'Facture acceptée : retrouvez-la dans les Achats.' : 'Facture refusée, le fournisseur est prévenu.');
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final hint = Theme.of(context).hintColor;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('sp_invoices'),
      builder: (context, _, _) {
        final all = _all('sp_invoices')..sort((a, b) => ('${a['status']}' == 'received' ? 0 : 1).compareTo('${b['status']}' == 'received' ? 0 : 1) != 0
              ? ('${a['status']}' == 'received' ? 0 : 1).compareTo('${b['status']}' == 'received' ? 0 : 1)
              : '${b['created_at']}'.compareTo('${a['created_at']}'));
        final bar = spManage(session)
            ? Padding(
                padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
                child: Row(children: [
                  Expanded(child: Text(tr('Factures déposées sur le portail ou importées (PDF, photo) : lues automatiquement et rapprochées de la commande et de la réception.'), style: TextStyle(color: hint))),
                  IconButton(tooltip: tr('Tolérance du rapprochement'), onPressed: () => _matchSettings(context), icon: const Icon(Icons.tune)),
                  FilledButton.tonalIcon(onPressed: () => importSupplierInvoice(context), icon: const Icon(Icons.upload_file), label: Text(tr('Importer une facture'))),
                ]),
              )
            : const SizedBox.shrink();
        if (all.isEmpty) {
          return Column(children: [bar, Expanded(child: EmptyState(icon: Icons.receipt_long_outlined, title: tr('Aucune facture'), message: tr('Vos fournisseurs déposent leurs factures sur leur portail, ou importez un PDF reçu par e-mail.')))]);
        }
        final base = all.where((i) {
          if (_q.isNotEmpty && !'${i['partner_name'] ?? ''} ${i['number'] ?? ''} ${(i['match'] as Map?)?['order_number'] ?? ''}'.toLowerCase().contains(_q)) return false;
          final sup = _f['supplier'], ma = _f['match'], src = _f['source'];
          if (sup != null && '${i['partner_name'] ?? ''}' != sup) return false;
          if (ma != null && '${(i['match'] as Map?)?['status'] ?? ''}' != ma) return false;
          if (src != null && (i['source'] == 'upload' ? 'upload' : 'portal') != src) return false;
          if (_period != null && !_period!.contains(i['date'])) return false;
          return true;
        }).toList();
        final statuses = {'': 'Toutes', ...spInvoiceStatuses};
        final rows = base.where((i) => _status.isEmpty || i['status'] == _status).toList();
        return ListView(padding: const EdgeInsets.only(bottom: 24), children: [
          bar,
          FilterBar(
            search: _q,
            searchHint: 'Fournisseur, n° de facture ou de bon…',
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            statuses: statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in statuses.keys) k: k.isEmpty ? base.length : base.where((i) => i['status'] == k).length},
            filters: [
              FilterSpec('supplier', 'Fournisseur', filterOptions(all, 'partner_name'), icon: Icons.local_shipping_outlined),
              FilterSpec('match', 'Rapprochement', {for (final i in all) if (spMatchLabels.containsKey((i['match'] as Map?)?['status'])) '${(i['match'] as Map)['status']}': spMatchLabels[(i['match'] as Map)['status']]!}, icon: Icons.compare_arrows),
              const FilterSpec('source', 'Origine', {'portal': 'Déposée sur le portail', 'upload': 'Importée'}, icon: Icons.upload_file),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (v) => setState(() => _period = v),
            count: rows.length,
            countLabel: 'facture',
            onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
          ),
          if (rows.isEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
          for (final i in rows)
            Builder(builder: (context) {
              final order = i['order_id'] == null ? null : LocalDb.get('purchase_documents', '${i['order_id']}');
              final st = '${i['status']}';
              final m = i['match'] as Map?;
              final ms = '${m?['status'] ?? ''}';
              final warnings = ((i['extracted'] as Map?)?['warnings'] as List? ?? const []);
              return Padding(
                padding: const EdgeInsets.symmetric(horizontal: 24),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Icon(i['source'] == 'upload' ? Icons.upload_file : Icons.receipt_long, color: YColors.blue),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr('${i['partner_name']} — facture ${i['number']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                          Text(tr([
                            dateFr(i['date']),
                            money(toDouble(i['amount']), '${i['currency'] ?? session.currency}'),
                            if (order != null) 'bon ${order['number']}' else if (m?['order_number'] != null) 'bon ${m!['order_number']}',
                            i['source'] == 'upload' ? 'importée${i['created_by'] != null ? ' par ${i['created_by']}' : ''}' : 'déposée sur le portail',
                            if (st == 'rejected') 'motif : ${i['reject_reason']}',
                          ].join(' · ')), style: TextStyle(color: hint)),
                          if (m != null && st == 'received')
                            Padding(
                              padding: const EdgeInsets.only(top: 6),
                              child: Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                                InkWell(onTap: () => showInvoiceMatch(context, i), child: StatusChip(spMatchLabels[ms] ?? ms, color: spMatchColor(ms))),
                                Text(tr('${m['label']}'), style: TextStyle(fontSize: 12.5, color: spMatchColor(ms) == YColors.warning ? YColors.warning : hint)),
                                TextButton(onPressed: () => showInvoiceMatch(context, i), child: Text(tr('Détail'))),
                              ]),
                            ),
                          if (warnings.isNotEmpty && st == 'received')
                            for (final w in warnings)
                              Padding(
                                padding: const EdgeInsets.only(top: 2),
                                child: Row(children: [
                                  const Icon(Icons.warning_amber_rounded, size: 15, color: YColors.warning),
                                  const SizedBox(width: 4),
                                  Expanded(child: Text(tr('$w'), style: const TextStyle(fontSize: 12.5, color: YColors.warning))),
                                ]),
                              ),
                        ]),
                      ),
                      if (i['has_file'] == true) IconButton(tooltip: tr('Ouvrir la facture'), onPressed: () => _open(context, i), icon: const Icon(Icons.picture_as_pdf_outlined)),
                      if (st == 'received' && spManage(session)) ...[
                        TextButton(onPressed: () => _decide(context, i, false), child: Text(tr('Refuser'))),
                        FilledButton(onPressed: () => _decide(context, i, true), child: Text(tr('Accepter'))),
                      ] else
                        StatusChip(spInvoiceStatuses[st] ?? st, color: st == 'accepted' ? YColors.success : (st == 'rejected' ? YColors.danger : YColors.warning)),
                    ]),
                  ),
                ),
              );
            }),
        ]);
      },
    );
  }
}

// ======================================================================
// Confirmations de commande

class _AcksTab extends StatefulWidget {
  const _AcksTab();

  @override
  State<_AcksTab> createState() => _AcksTabState();
}

class _AcksTabState extends State<_AcksTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _statuses = {'': 'Toutes', 'accepted': 'Confirmées', 'rejected': 'Refusées'};

  static bool _statusOk(Map<String, dynamic> a, String st) => st.isEmpty || (st == 'accepted') == (a['status'] == 'accepted');

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('sp_order_acks'),
      builder: (context, _, _) {
        final all = _all('sp_order_acks')..sort((a, b) => '${b['created_at']}'.compareTo('${a['created_at']}'));
        final base = all.where((a) {
          if (_q.isNotEmpty && !'${LocalDb.get('purchase_documents', '${a['order_id']}')?['number'] ?? ''} ${_name(a['partner_id'])} ${a['comment'] ?? ''}'.toLowerCase().contains(_q)) return false;
          final sup = _f['supplier'];
          if (sup != null && '${a['partner_id']}' != sup) return false;
          if (_period != null && !_period!.contains(a['delivery_date'] ?? a['created_at'])) return false;
          return true;
        }).toList();
        final rows = base.where((a) => _statusOk(a, _status)).toList();
        if (all.isEmpty) return EmptyState(icon: Icons.mark_email_read_outlined, title: tr('Aucune confirmation'), message: tr('Les fournisseurs confirment vos bons de commande validés et leur date de livraison depuis leur portail.'));
        return ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
          FilterBar(
            padding: const EdgeInsets.only(bottom: 12),
            search: _q,
            searchHint: 'N° de bon, fournisseur…',
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            statuses: _statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in _statuses.keys) k: base.where((a) => _statusOk(a, k)).length},
            filters: [
              FilterSpec('supplier', 'Fournisseur', filterOptions(all.where((a) => _name(a['partner_id']) != '—'), 'partner_id', label: (a) => _name(a['partner_id'])), icon: Icons.local_shipping_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (v) => setState(() => _period = v),
            count: rows.length,
            countLabel: 'confirmation',
            onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
          ),
          if (rows.isEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
          for (final a in rows)
            Card(
              child: ListTile(
                leading: Icon(a['status'] == 'accepted' ? Icons.check_circle : Icons.cancel, color: a['status'] == 'accepted' ? YColors.success : YColors.danger),
                title: Text(tr('${LocalDb.get('purchase_documents', '${a['order_id']}')?['number'] ?? 'Bon de commande'} — ${_name(a['partner_id'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(tr([
                  a['status'] == 'accepted' ? 'Confirmée, livraison le ${dateFr(a['delivery_date'])}' : 'Refusée',
                  if (a['by_name'] != null) 'par ${a['by_name']}',
                  dateTimeFr(a['created_at']),
                  if (a['comment'] != null) '« ${a['comment']} »',
                ].join(' · ')), style: TextStyle(color: hint)),
              ),
            ),
        ]);
      },
    );
  }
}

// ======================================================================
// Accès des fournisseurs

class _AccessTab extends StatefulWidget {
  const _AccessTab();

  @override
  State<_AccessTab> createState() => _AccessTabState();
}

class _AccessTabState extends State<_AccessTab> {
  List<Map<String, dynamic>>? _rows;
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  static const _statuses = {'': 'Tous', 'active': 'Actifs', 'revoked': 'Révoqués'};

  static bool _statusOk(Map<String, dynamic> a, String st) => st.isEmpty || (st == 'active') == (a['active'] == true);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('portail_fournisseurs/accesses');
      if (mounted) setState(() => _rows = [for (final x in r['data'] as List) asMap(x)]);
    } catch (_) {
      if (mounted) setState(() => _rows = const []);
    }
  }

  Future<void> _invite() async {
    final suppliers = LocalDb.all('partners').where((p) => p['type'] == 'supplier' || p['type'] == 'both').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final p = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (ctx) => SimpleDialog(title: Text(tr('Inviter un fournisseur')), children: [for (final s in suppliers) SimpleDialogOption(onPressed: () => Navigator.pop(ctx, s), child: Text(tr('${s['name']}${s['email'] != null ? ' · ${s['email']}' : ''}')))]),
    );
    if (p == null || !mounted) return;
    try {
      final r = await context.read<Session>().api.post('portail_fournisseurs/partners/${p['id']}/invite', {'send': true});
      await Clipboard.setData(ClipboardData(text: '${r['url']}'));
      if (mounted) toast(context, (r['sent_via'] as List).isEmpty ? 'Lien créé et copié : transmettez-le au fournisseur.' : 'Invitation envoyée par ${(r['sent_via'] as List).join(' et ')} (lien copié).');
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final hint = Theme.of(context).hintColor;
    final all = _rows;
    final base = (all ?? const <Map<String, dynamic>>[]).where((a) {
      if (_q.isNotEmpty && !'${a['name']}'.toLowerCase().contains(_q)) return false;
      final seen = _f['seen'];
      if (seen == 'never' && a['last_seen_at'] != null) return false;
      if (seen == 'seen' && a['last_seen_at'] == null) return false;
      return true;
    }).toList();
    final rows = all == null ? null : base.where((a) => _statusOk(a, _status)).toList();
    return ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Wrap(spacing: 12, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
            const Icon(Icons.storefront, color: YColors.blue, size: 28),
            ConstrainedBox(constraints: const BoxConstraints(maxWidth: 560), child: Text(tr('Chaque fournisseur reçoit un lien personnel : il répond aux appels d\'offres, confirme vos commandes, dépose ses factures et suit ses paiements, sans mot de passe.'), style: TextStyle(color: hint))),
            if (spManage(session)) FilledButton.icon(onPressed: _invite, icon: const Icon(Icons.person_add_alt), label: Text(tr('Inviter'))),
          ]),
        ),
      ),
      if (all != null && all.isNotEmpty)
        FilterBar(
          padding: const EdgeInsets.only(top: 12, bottom: 4),
          search: _q,
          searchHint: 'Fournisseur…',
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          statuses: _statuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in _statuses.keys) k: base.where((a) => _statusOk(a, k)).length},
          filters: const [FilterSpec('seen', 'Connexion', {'seen': 'Déjà connecté', 'never': 'Jamais connecté'}, icon: Icons.login)],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          count: rows?.length,
          countLabel: 'fournisseur',
          onReset: () => setState(() => (_q = '', _status = '', _f = {})),
        ),
      if (rows == null) const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
      if (all != null && all.isEmpty) EmptyState(icon: Icons.storefront_outlined, title: tr('Aucun fournisseur invité'))
      else if (rows != null && rows.isEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
      for (final a in rows ?? const <Map<String, dynamic>>[])
        Card(
          child: ListTile(
            leading: Opacity(opacity: a['active'] == true ? 1 : .45, child: InitialsAvatar('${a['name']}', size: 38)),
            title: Text(tr('${a['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(tr(a['active'] == true ? (a['last_seen_at'] == null ? 'Jamais connecté' : 'Dernière visite ${dateTimeFr(a['last_seen_at'])}') : 'Accès révoqué'), style: TextStyle(color: hint)),
            trailing: Wrap(spacing: 4, children: [
              if (a['url'] != null)
                IconButton(
                  tooltip: tr('Copier le lien'),
                  onPressed: () {
                    Clipboard.setData(ClipboardData(text: '${a['url']}'));
                    toast(context, tr('Lien copié.'));
                  },
                  icon: const Icon(Icons.link),
                ),
              if (spManage(session) && a['active'] == true)
                IconButton(
                  tooltip: tr('Révoquer l\'accès'),
                  onPressed: () async {
                    await context.read<Session>().api.post('portail_fournisseurs/partners/${a['partner_id']}/revoke');
                    _load();
                  },
                  icon: const Icon(Icons.block, color: YColors.danger),
                ),
            ]),
          ),
        ),
    ]);
  }
}
