import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/billetterie.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../pdf/tickets_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'evenements_screen.dart' show evColor;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
import '../widgets/pdf_viewer.dart';
import 'kiosk_screen.dart' show kioskAllowed, startKiosk;
bool tkCanManage(Session s) => s.can('billetterie.manage');
bool tkCanSell(Session s) => s.can('billetterie.sell') || s.can('billetterie.manage');
bool tkCanScan(Session s) => s.can('billetterie.scan') || s.can('billetterie.manage');

/// Scan par la caméra : téléphones, tablettes, Mac et navigateur (pas Windows ni Linux, où l'on utilise une douchette).
bool get tkCameraSupported =>
    kIsWeb || const {TargetPlatform.android, TargetPlatform.iOS, TargetPlatform.macOS}.contains(defaultTargetPlatform);

bool _zxingLocal = false;

/// Bibliothèque de lecture du navigateur servie par l'application (fonctionne sans Internet).
void tkPrepareCamera() {
  if (kIsWeb && !_zxingLocal) {
    _zxingLocal = true;
    // Lecteur ZXing-js livré avec l'application (zxing.min.js) : fonctionne sans Internet et sur tous les navigateurs.
    MobileScannerPlatform.instance.setWebBarcodeReader(WebBarcodeReader.zxingJs);
    MobileScannerPlatform.instance.setBarcodeLibraryScriptUrl('zxing.min.js');
  }
}

List<Map<String, dynamic>> tkTypes(String eventId) => LocalDb.hasEntity('ev_ticket_types')
    ? (LocalDb.all('ev_ticket_types').where((t) => t['event_id'] == eventId).toList()..sort((a, b) => toDouble(a['sort']).compareTo(toDouble(b['sort'])) != 0 ? toDouble(a['sort']).compareTo(toDouble(b['sort'])) : toDouble(a['price']).compareTo(toDouble(b['price']))))
    : [];

List<Map<String, dynamic>> tkTickets(String eventId) => LocalDb.hasEntity('ev_tickets') ? LocalDb.all('ev_tickets').where((t) => t['event_id'] == eventId).toList() : [];

String _n(dynamic v) {
  if (v == null || '$v'.isEmpty) return '';
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.toInt()}' : '$d';
}

Future<void> printTickets(BuildContext context, Map<String, dynamic> event, List<Map<String, dynamic>> tickets, {bool roll = false}) async {
  if (tickets.isEmpty) return;
  final session = context.read<Session>();
  final types = {for (final t in tkTypes('${event['id']}')) '${t['id']}': t};
  final bytes = await ticketsPdf(event: event, tickets: tickets, types: types, company: '${session.company['name'] ?? ''}', roll: roll);
  await previewPdf(name: 'Billets ${event['name']}', onLayout: (_) async => Uint8List.fromList(bytes));
}

/// Onglet Billetterie d'un événement : tarifs, guichet, badges, billets émis.
class TicketingTab extends StatefulWidget {
  const TicketingTab({super.key, required this.event});

  final Map<String, dynamic> event;

  @override
  State<TicketingTab> createState() => _TicketingTabState();
}

class _TicketingTabState extends State<TicketingTab> {
  String _q = '';
  int _rk = 0;
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final e = widget.event;
    final cur = '${e['currency'] ?? session.currency}';
    final hint = Theme.of(context).hintColor;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('ev_tickets'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('ev_ticket_types'),
        builder: (context, _, _) {
          final types = tkTypes('${e['id']}');
          final tickets = tkTickets('${e['id']}');
          final valid = tickets.where((t) => t['status'] == 'valid').toList();
          final visitors = valid.where((t) => t['kind'] == 'visitor' || t['kind'] == 'vip').length;
          final revenue = valid.fold<double>(0, (s, t) => s + toDouble(t['price']));
          final inside = valid.where((t) => t['checked_in_at'] != null).length;
          final base = tickets
              .where((t) => _q.isEmpty || '${t['code']} ${t['holder_name'] ?? ''} ${t['company_name'] ?? ''}'.toLowerCase().contains(_q))
              .where((t) => _f['kind'] == null || '${t['kind']}' == _f['kind'])
              .where((t) => _f['type'] == null || '${t['type_id'] ?? ''}' == _f['type'])
              .where((t) => _f['entry'] == null || (t['checked_in_at'] != null) == (_f['entry'] == 'yes'))
              .where((t) => _period == null || _period!.contains(t['created_at']))
              .toList();
          bool stOf(Map<String, dynamic> t, String k) => k.isEmpty || (k == 'valid' ? t['status'] == 'valid' : t['status'] != 'valid');
          final rows = base.where((t) => stOf(t, _status)).toList()
            ..sort((a, b) => '${b['created_at'] ?? ''}'.compareTo('${a['created_at'] ?? ''}'));
          final rowSet = rows.toSet();
          Widget kpi(String l, String v, IconData i, Color c) => KpiBox(width: 220, child: StatTile(label: l, value: v, icon: i, color: c));
          return ListView(padding: const EdgeInsets.all(16), children: [
            if (e['ticketing_enabled'] != true)
              Card(
                color: YColors.warning.withValues(alpha: .08),
                child: ListTile(
                  leading: const Icon(Icons.info_outline, color: YColors.warning),
                  title: Text(tr('Vente en ligne désactivée')),
                  subtitle: Text(tr('Activez « Billetterie en ligne » dans les réglages de l\'événement pour publier la page d\'achat. Le guichet et les badges fonctionnent déjà.')),
                ),
              ),
            KpiWrap(children: [
              kpi('Billets visiteurs${e['capacity'] != null ? ' / ${_n(e['capacity'])}' : ''}', '$visitors', Icons.confirmation_number_outlined, YColors.blue),
              kpi('Badges', '${valid.length - visitors}', Icons.badge_outlined, YColors.night),
              kpi('Recettes (TTC)', money(revenue, cur), Icons.payments_outlined, YColors.success),
              kpi('Entrés au moins une fois', '$inside', Icons.how_to_reg, YColors.warning),
            ]),
            const SizedBox(height: 12),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (tkCanScan(session)) FilledButton.icon(onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ScanScreen(eventId: '${e['id']}'))), icon: const Icon(Icons.qr_code_scanner), label: Text(tr('Contrôle d\'entrée'))),
              if (tkCanSell(session) && types.isNotEmpty) FilledButton.tonalIcon(onPressed: () => boxOfficeSale(context, e), icon: const Icon(Icons.point_of_sale), label: Text(tr('Vendre au guichet'))),
              if (kioskAllowed(session)) FilledButton.tonalIcon(onPressed: () => startKiosk(context, e), icon: const Icon(Icons.tablet_android), label: Text(tr('Mode borne d\'accueil'))),
              if (tkCanManage(session)) OutlinedButton.icon(onPressed: () => issueBadges(context, e), icon: const Icon(Icons.badge_outlined), label: Text(tr('Badges / invitations'))),
              if (tkCanManage(session)) OutlinedButton.icon(onPressed: () => editTicketType(context, e), icon: const Icon(Icons.add), label: Text(tr('Tarif'))),
              if (e['ticketing_url'] != null && e['ticketing_enabled'] == true)
                OutlinedButton.icon(
                  onPressed: () => showDialog(
                    context: context,
                    builder: (c) => YAlertDialog(
                      title: Text(tr('Page de billetterie')),
                      content: SelectableText(tr('${e['ticketing_url']}')),
                      actions: [
                        TextButton(onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent('Billets pour ${e['name']} : ${e['ticketing_url']}')}'), mode: LaunchMode.externalApplication), child: Text(tr('WhatsApp'))),
                        TextButton(onPressed: () => Clipboard.setData(ClipboardData(text: '${e['ticketing_url']}')), child: Text(tr('Copier'))),
                        FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
                      ],
                    ),
                  ),
                  icon: const Icon(Icons.public),
                  label: Text(tr('Page de billetterie')),
                ),
            ]),
            const SizedBox(height: 14),
            SectionCard(
              title: tr('Tarifs et badges'),
              child: types.isEmpty
                  ? Text(tr('Créez vos tarifs : Pass 1 jour, Pass VIP, entrée gratuite sur inscription, badges exposants…'), style: TextStyle(color: hint))
                  : Column(children: [
                      for (final t in types)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          onTap: tkCanManage(session) ? () => editTicketType(context, e, t) : null,
                          leading: Container(width: 8, height: 36, decoration: BoxDecoration(color: evColor(t['color'], t['kind'] == 'exhibitor' ? YColors.success : YColors.blue), borderRadius: BorderRadius.circular(4))),
                          title: Text(tr('${t['name']}'), style: TextStyle(fontWeight: FontWeight.w700, color: t['active'] == false ? Colors.grey : null)),
                          subtitle: Text(tr([
                            tkKinds[t['kind']] ?? '',
                            if (t['valid_from'] != null) t['valid_from'] == t['valid_to'] ? 'le ${dateFr(t['valid_from'])}' : 'du ${dateFr(t['valid_from'])} au ${dateFr(t['valid_to'])}',
                            '${valid.where((x) => x['type_id'] == t['id']).length}${t['quantity'] != null ? ' / ${_n(t['quantity'])}' : ''} émis',
                            if (t['online'] == true) 'en ligne',
                            if (t['reentry'] == true) 'réentrée libre',
                          ].join(' · '))),
                          trailing: Text(tr(toDouble(t['price']) > 0 ? money(toDouble(t['price']), cur) : 'Gratuit'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        ),
                    ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Billets et badges émis (${tickets.length})'),
              trailing: valid.isEmpty ? null : TextButton.icon(onPressed: () => printTickets(context, e, valid.where(rowSet.contains).toList()), icon: const Icon(Icons.print_outlined), label: Text(tr('Imprimer'))),
              child: Column(children: [
                FilterBar(
                  key: ValueKey(_rk),
                  padding: const EdgeInsets.only(bottom: 6),
                  onSearch: (v) => setState(() => _q = v.toLowerCase()),
                  search: _q,
                  searchHint: 'Code, nom, société…',
                  statuses: const {'': 'Tous', 'valid': 'Valides', 'cancelled': 'Annulés'},
                  status: _status,
                  onStatus: (v) => setState(() => _status = v),
                  statusCounts: {for (final k in ['', 'valid', 'cancelled']) k: base.where((t) => stOf(t, k)).length},
                  filters: [
                    FilterSpec('kind', 'Catégorie', {for (final k in tkKinds.entries) if (tickets.any((t) => t['kind'] == k.key)) k.key: k.value}, icon: Icons.category_outlined),
                    FilterSpec('type', 'Tarif', {for (final t in types) '${t['id']}': '${t['name']}'}, icon: Icons.sell_outlined),
                    const FilterSpec('entry', 'Entrée', {'yes': 'Déjà entré', 'no': 'Pas encore entré'}, icon: Icons.how_to_reg_outlined),
                  ],
                  values: _f,
                  onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                  showPeriod: true,
                  period: _period,
                  onPeriod: (p) => setState(() => _period = p),
                  count: rows.length,
                  countLabel: 'billet',
                  onReset: () => setState(() => (_rk++, _q = '', _status = '', _f = {}, _period = null)),
                ),
                if (rows.isEmpty && tickets.isNotEmpty)
                  Padding(padding: const EdgeInsets.all(12), child: Text(tr('Aucun billet ne correspond aux filtres'), style: TextStyle(color: hint))),
                for (final t in rows.take(200))
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(t['kind'] == 'visitor' || t['kind'] == 'vip' ? Icons.confirmation_number_outlined : Icons.badge_outlined, color: t['status'] == 'valid' ? YColors.blue : Colors.grey),
                    title: Text(tr('${t['holder_name'] ?? '—'}${t['company_name'] != null ? ' · ${t['company_name']}' : ''}'), style: TextStyle(decoration: t['status'] == 'valid' ? null : TextDecoration.lineThrough)),
                    subtitle: Text(tr([
                      '${t['code']}',
                      LocalDb.get('ev_ticket_types', t['type_id'] as String?)?['name'] ?? tkKinds[t['kind']] ?? '',
                      if (t['checked_in_at'] != null) 'entré ${toDouble(t['checkins_count']).toInt()} fois' else 'pas encore entré',
                    ].join(' · '))),
                    trailing: PopupMenuButton<String>(
                      onSelected: (v) async {
                        if (v == 'print') printTickets(context, e, [t], roll: true);
                        if (v == 'cancel' && await confirm(context, tr('Annuler ce billet ?'), tr('Il sera refusé à l\'entrée.'), ok: tr('Annuler le billet'), danger: true) && context.mounted) {
                          await context.read<SyncEngine>().save('ev_tickets', {'id': t['id'], 'status': 'cancelled'});
                        }
                      },
                      itemBuilder: (_) => [
                        PopupMenuItem(value: 'print', child: Text(tr('Imprimer'))),
                        if (tkCanManage(session) && t['status'] == 'valid') PopupMenuItem(value: 'cancel', child: Text(tr('Annuler le billet'))),
                      ],
                    ),
                  ),
              ]),
            ),
          ]);
        },
      ),
    );
  }
}

// ---------------------------------------------------------------- tarifs

Future<void> editTicketType(BuildContext context, Map<String, dynamic> event, [Map<String, dynamic>? type]) =>
    showFormDialog(context, title: type == null ? 'Nouveau tarif' : '${type['name']}', builder: (_) => _TypeForm(event: event, type: type));

class _TypeForm extends StatefulWidget {
  const _TypeForm({required this.event, this.type});

  final Map<String, dynamic> event;
  final Map<String, dynamic>? type;

  @override
  State<_TypeForm> createState() => _TypeFormState();
}

class _TypeFormState extends State<_TypeForm> {
  late final t = widget.type ?? {};
  late final _name = TextEditingController(text: '${t['name'] ?? ''}');
  late final _price = TextEditingController(text: _n(t['price']));
  late final _qty = TextEditingController(text: _n(t['quantity']));
  late final _max = TextEditingController(text: _n(t['max_per_order'] ?? 10));
  late final _desc = TextEditingController(text: '${t['description'] ?? ''}');
  late String _kind = '${t['kind'] ?? 'visitor'}';
  late String? _from = (t['valid_from'] ?? widget.event['start_date']) as String?;
  late String? _to = (t['valid_to'] ?? widget.event['end_date']) as String?;
  late bool _reentry = t['reentry'] == true;
  late bool _online = t['online'] ?? true;
  late bool _active = t['active'] != false;
  late String? _color = t['color'] as String?;

  Future<void> _date(bool from) async {
    final d = await pickDate(context, initialDate: DateTime.tryParse((from ? _from : _to) ?? '') ?? DateTime.now(), firstDate: DateTime(2020), lastDate: DateTime(2100));
    if (d != null) setState(() => from ? _from = isoDate(d) : _to = isoDate(d));
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return toast(context, tr('Nommez le tarif.'), error: true);
    await context.read<SyncEngine>().save('ev_ticket_types', {
      if (widget.type != null) 'id': widget.type!['id'],
      'event_id': widget.event['id'],
      'name': _name.text.trim(),
      'kind': _kind,
      'price': toDouble(_price.text),
      'quantity': _qty.text.trim().isEmpty ? null : toDouble(_qty.text).toInt(),
      'max_per_order': toDouble(_max.text).toInt().clamp(1, 50),
      'valid_from': _from,
      'valid_to': _to,
      'reentry': _reentry,
      'online': _online,
      'active': _active,
      'color': _color,
      'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
    });
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) => ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
        Field(label: tr('Nom'), child: TextField(controller: _name, autofocus: widget.type == null, decoration: InputDecoration(hintText: tr('Pass 1 jour, Pass VIP, Badge exposant…')))),
        Field(
          label: tr('Type'),
          child: Wrap(spacing: 8, children: [
            for (final k in tkKinds.entries)
              ChoiceChip(
                label: Text(tr(k.value)),
                selected: _kind == k.key,
                onSelected: (_) => setState(() {
                  _kind = k.key;
                  if (widget.type == null && ['exhibitor', 'staff', 'press'].contains(k.key)) {
                    _reentry = true;
                    _online = false;
                  }
                }),
              ),
          ]),
        ),
        Row(children: [
          Expanded(child: Field(label: tr('Prix TTC (0 = gratuit)'), child: TextField(controller: _price, keyboardType: TextInputType.number))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Quantité'), child: TextField(controller: _qty, keyboardType: TextInputType.number, decoration: InputDecoration(hintText: tr('Illimitée'))))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Maxi par commande'), child: TextField(controller: _max, keyboardType: TextInputType.number))),
        ]),
        Row(children: [
          Expanded(child: Field(label: tr('Valable du'), child: OutlinedButton(onPressed: () => _date(true), child: Text(tr(_from == null ? '—' : dateFr(_from)))))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('au'), child: OutlinedButton(onPressed: () => _date(false), child: Text(tr(_to == null ? '—' : dateFr(_to)))))),
          const SizedBox(width: 12),
          PopupMenuButton<String>(
            tooltip: tr('Couleur'),
            onSelected: (v) => setState(() => _color = v),
            itemBuilder: (_) => [for (final c in const ['#1F6FEB', '#12A150', '#8E44AD', '#E8A317', '#D93025', '#0FA3B1', '#FF7A00', '#0B1B3A']) PopupMenuItem(value: c, child: Container(width: 60, height: 18, color: evColor(c)))],
            child: Padding(padding: const EdgeInsets.only(top: 18), child: Container(width: 36, height: 36, decoration: BoxDecoration(color: evColor(_color), borderRadius: BorderRadius.circular(8)))),
          ),
        ]),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Vendu en ligne')), value: _online, onChanged: (v) => setState(() => _online = v)),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Réentrée libre')), subtitle: Text(tr('Sinon, une seule entrée par jour.')), value: _reentry, onChanged: (v) => setState(() => _reentry = v)),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Actif')), value: _active, onChanged: (v) => setState(() => _active = v)),
        Field(label: tr('Description (page de billetterie)'), child: TextField(controller: _desc)),
        Align(alignment: Alignment.centerRight, child: SaveButton(onPressed: _save, child: Text(tr('Enregistrer')))),
      ]);
}

// ---------------------------------------------------------------- guichet

/// Vente au guichet (hors ligne) : billets générés sur l'appareil et imprimés tout de suite.
Future<void> boxOfficeSale(BuildContext context, Map<String, dynamic> event) async {
  final session = context.read<Session>();
  final cur = '${event['currency'] ?? session.currency}';
  final types = tkTypes('${event['id']}').where((t) => t['active'] != false).toList();
  final qty = <String, int>{};
  final name = TextEditingController(text: tr('Guichet'));
  final phone = TextEditingController();
  var method = 'cash';
  final sync = context.read<SyncEngine>();
  final done = await showDialog<List<Map<String, dynamic>>>(
    context: context,
    builder: (c) => StatefulBuilder(builder: (c, set) {
      final total = types.fold<double>(0, (s, t) => s + (qty['${t['id']}'] ?? 0) * toDouble(t['price']));
      return YAlertDialog(
        title: Text(tr('Vente au guichet')),
        content: SizedBox(
          width: 460,
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              for (final t in types)
                Builder(builder: (_) {
                  final q = qty['${t['id']}'] ?? 0;
                  final left = tkLeft(t);
                  return ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(tr('${t['name']}')),
                    subtitle: Text(tr('${toDouble(t['price']) > 0 ? money(toDouble(t['price']), cur) : 'Gratuit'}${left != null ? ' · reste $left' : ''}')),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(onPressed: q == 0 ? null : () => set(() => qty['${t['id']}'] = q - 1), icon: const Icon(Icons.remove_circle_outline)),
                      SizedBox(width: 28, child: Text(tr('$q'), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
                      IconButton(onPressed: left != null && q >= left ? null : () => set(() => qty['${t['id']}'] = q + 1), icon: const Icon(Icons.add_circle_outline)),
                    ]),
                  );
                }),
              const Divider(),
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom sur les billets'))),
              TextField(controller: phone, decoration: InputDecoration(labelText: tr('Téléphone (facultatif)'))),
              const SizedBox(height: 10),
              if (total > 0)
                Wrap(spacing: 8, children: [
                  for (final m in const {'cash': 'Espèces', 'mobile_money': 'Mobile Money', 'card': 'Carte'}.entries)
                    ChoiceChip(label: Text(tr(m.value)), selected: method == m.key, onSelected: (_) => set(() => method = m.key)),
                ]),
              const SizedBox(height: 10),
              Align(alignment: Alignment.centerRight, child: Text(tr('Total : ${money(total, cur)}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
            ]),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton.icon(
            onPressed: qty.values.every((q) => q == 0)
                ? null
                : () async {
                    final orderId = newId();
                    final tickets = <Map<String, dynamic>>[];
                    for (final t in types) {
                      for (var i = 0; i < (qty['${t['id']}'] ?? 0); i++) {
                        tickets.add({'id': newId(), 'type_id': t['id'], 'code': tkNewCode(), 'token': tkNewToken(), 'holder_name': name.text.trim().isEmpty ? null : name.text.trim()});
                      }
                    }
                    await sync.save('ev_ticket_orders', {
                      'id': orderId,
                      'event_id': event['id'],
                      'buyer_name': name.text.trim().isEmpty ? 'Guichet' : name.text.trim(),
                      'buyer_phone': phone.text.trim().isEmpty ? null : phone.text.trim(),
                      'items': [for (final e in qty.entries) if (e.value > 0) {'type_id': e.key, 'quantity': e.value}],
                      'payment_method': total > 0 ? method : 'free',
                      'status': 'paid',
                      'tickets': tickets,
                    });
                    // Billets disponibles tout de suite au contrôle de l'entrée (remplacés à la synchronisation)
                    final local = [
                      for (final t in tickets)
                        {
                          ...t,
                          'event_id': event['id'],
                          'order_id': orderId,
                          'kind': types.firstWhere((x) => x['id'] == t['type_id'])['kind'],
                          'price': toDouble(types.firstWhere((x) => x['id'] == t['type_id'])['price']),
                          'valid_from': types.firstWhere((x) => x['id'] == t['type_id'])['valid_from'] ?? event['start_date'],
                          'valid_to': types.firstWhere((x) => x['id'] == t['type_id'])['valid_to'] ?? event['end_date'],
                          'reentry': types.firstWhere((x) => x['id'] == t['type_id'])['reentry'] == true,
                          'status': 'valid',
                          'checkins_count': 0,
                          'created_at': DateTime.now().toUtc().toIso8601String(),
                          '_local': true,
                        },
                    ];
                    await LocalDb.putMany('ev_tickets', local);
                    if (c.mounted) Navigator.pop(c, local);
                  },
            icon: const Icon(Icons.print_outlined),
            label: Text(tr('Encaisser et imprimer')),
          ),
        ],
      );
    }),
  );
  if (done != null && done.isNotEmpty && context.mounted) {
    await printTickets(context, event, done, roll: true);
  }
}

// ---------------------------------------------------------------- badges

/// Badges ou invitations nominatifs (organisation, presse, VIP, exposant).
Future<void> issueBadges(BuildContext context, Map<String, dynamic> event, {String? kind, String? companyName}) async {
  final types = tkTypes('${event['id']}').where((t) => t['active'] != false).toList();
  final names = TextEditingController();
  final company = TextEditingController(text: companyName ?? '');
  String? typeId = types.where((t) => t['kind'] == (kind ?? 'staff')).firstOrNull?['id'] as String? ?? types.firstOrNull?['id'] as String?;
  var k = kind ?? 'staff';
  final sync = context.read<SyncEngine>();
  final done = await showDialog<List<Map<String, dynamic>>>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr('Badges et invitations')),
        content: SizedBox(
          width: 440,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String?>(
              initialValue: typeId,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Tarif / type de badge')),
              items: [DropdownMenuItem(value: null, child: Text(tr('Sans tarif'))), for (final t in types) DropdownMenuItem(value: '${t['id']}', child: Text(tr('${t['name']} (${tkKinds[t['kind']]})')))],
              onChanged: (v) => set(() {
                typeId = v;
                k = '${types.where((t) => t['id'] == v).firstOrNull?['kind'] ?? k}';
              }),
            ),
            if (typeId == null)
              Wrap(spacing: 6, children: [for (final e in tkKinds.entries) ChoiceChip(label: Text(tr(e.value)), selected: k == e.key, onSelected: (_) => set(() => k = e.key))]),
            TextField(controller: company, decoration: InputDecoration(labelText: tr('Société / média (facultatif)'))),
            const SizedBox(height: 8),
            TextField(controller: names, minLines: 4, maxLines: 10, autofocus: true, decoration: InputDecoration(labelText: tr('Noms (un par ligne)'), hintText: tr('Awa Koné\nYao Kouassi'))),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          SaveButton(
            onPressed: () async {
              final list = names.text.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
              if (list.isEmpty) return;
              final type = types.where((t) => t['id'] == typeId).firstOrNull;
              final out = <Map<String, dynamic>>[];
              for (final n in list) {
                final rec = {
                  'id': newId(),
                  'event_id': event['id'],
                  'type_id': typeId,
                  'kind': type?['kind'] ?? k,
                  'holder_name': n,
                  'company_name': company.text.trim().isEmpty ? null : company.text.trim(),
                  'code': tkNewCode(),
                  'token': tkNewToken(),
                };
                await sync.save('ev_tickets', rec);
                out.add({
                  ...rec,
                  'status': 'valid',
                  'valid_from': type?['valid_from'] ?? event['start_date'],
                  'valid_to': type?['valid_to'] ?? event['end_date'],
                  'reentry': type?['reentry'] ?? true,
                });
              }
              if (c.mounted) Navigator.pop(c, out);
            },
            icon: const Icon(Icons.print_outlined),
            child: Text(tr('Créer et imprimer')),
          ),
        ],
      ),
    ),
  );
  if (done != null && done.isNotEmpty && context.mounted) await printTickets(context, event, done);
}

/// Badges d'un exposant confirmé (en ligne).
Future<void> exhibitorBadges(BuildContext context, Map<String, dynamic> event, Map<String, dynamic> booking) async {
  final names = TextEditingController();
  final api = context.read<Session>().api;
  final sync = context.read<SyncEngine>();
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => YAlertDialog(
      title: Text(tr('Badges — ${booking['exhibitor_name'] ?? booking['partner_name']}')),
      content: SizedBox(width: 400, child: TextField(controller: names, minLines: 4, maxLines: 10, autofocus: true, decoration: InputDecoration(labelText: tr('Noms des personnes (un par ligne)')))),
      actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Créer et imprimer')))],
    ),
  );
  if (ok != true) return;
  try {
    final r = await api.post('billetterie/bookings/${booking['id']}/badges', {'names': names.text.split('\n')});
    final list = [for (final t in (r['data'] as List)) asMap(t)];
    await LocalDb.putMany('ev_tickets', list);
    unawaited(sync.sync());
    if (context.mounted) await printTickets(context, event, list);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ---------------------------------------------------------------- contrôle d'entrée

/// Contrôle d'accès : lecteur de QR / code-barres (clavier), saisie du code ; fonctionne hors ligne.
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key, required this.eventId});

  final String eventId;

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> {
  final _code = TextEditingController();
  final _focus = FocusNode();
  late final _gate = TextEditingController(text: '${LocalDb.meta.get('scan_gate') ?? 'Entrée principale'}');
  TkResult? _last;
  final _history = <(TkResult, DateTime)>[];
  Timer? _clear;
  late bool _camera = tkCameraSupported && (LocalDb.meta.get('scan_camera') ?? const {TargetPlatform.android, TargetPlatform.iOS}.contains(defaultTargetPlatform)) == true;
  MobileScannerController? _cam;
  String? _lastRaw;
  DateTime _lastAt = DateTime(2000);

  @override
  void initState() {
    super.initState();
    if (_camera) _startCamera();
  }

  void _startCamera() {
    tkPrepareCamera();
    _cam = MobileScannerController(detectionSpeed: DetectionSpeed.normal, detectionTimeoutMs: 400, formats: const [BarcodeFormat.qrCode, BarcodeFormat.code128]);
  }

  void _toggleCamera() {
    setState(() {
      _camera = !_camera;
      if (_camera) {
        _startCamera();
      } else {
        _cam?.dispose();
        _cam = null;
      }
    });
    LocalDb.meta.put('scan_camera', _camera);
  }

  /// Un même QR code laissé devant la caméra n'est compté qu'une fois (15 s), et 1,5 s entre deux billets.
  void _onDetect(BarcodeCapture capture) {
    final raw = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
    if (raw == null || raw.trim().isEmpty) return;
    final now = DateTime.now();
    final since = now.difference(_lastAt);
    if (since < const Duration(milliseconds: 1500) || (raw == _lastRaw && since < const Duration(seconds: 15))) return;
    _lastRaw = raw;
    _lastAt = now;
    _scan(raw);
  }

  @override
  void dispose() {
    _clear?.cancel();
    _cam?.dispose();
    super.dispose();
  }

  Future<void> _scan(String raw) async {
    final code = raw.trim();
    if (code.isEmpty) return;
    final r = tkEvaluate(widget.eventId, code);
    final sync = context.read<SyncEngine>();
    final now = DateTime.now();
    await sync.save('ev_checkins', {
      'id': newId(),
      'event_id': widget.eventId,
      'code': code.length > 80 ? code.substring(0, 80) : code,
      'scanned_at': now.toUtc().toIso8601String(),
      'gate': _gate.text.trim().isEmpty ? null : _gate.text.trim(),
      'ticket_id': r.ticket?['id'],
      'result': r.result,
      'reason': r.reason,
    });
    if (r.ok && r.ticket != null) {
      final t = r.ticket!;
      await LocalDb.put('ev_tickets', {...t, 'checked_in_at': t['checked_in_at'] ?? now.toUtc().toIso8601String(), 'last_checkin_at': now.toUtc().toIso8601String(), 'checkins_count': toDouble(t['checkins_count']).toInt() + 1});
    }
    HapticFeedback.mediumImpact();
    if (!r.ok) SystemSound.play(SystemSoundType.alert);
    setState(() {
      _last = r;
      _history.insert(0, (r, now));
      if (_history.length > 30) _history.removeLast();
    });
    _code.clear();
    if (!_camera) _focus.requestFocus();
    _clear?.cancel();
    _clear = Timer(const Duration(seconds: 6), () => mounted ? setState(() => _last = null) : null);
  }

  @override
  Widget build(BuildContext context) {
    final event = LocalDb.get('ev_events', widget.eventId) ?? const {};
    final today = DateTime.now().toIso8601String().substring(0, 10);
    final entries = LocalDb.hasEntity('ev_checkins') ? LocalDb.all('ev_checkins').where((c) => c['event_id'] == widget.eventId && c['result'] == 'ok' && '${c['scanned_at'] ?? ''}'.startsWith(today)).length : 0;
    final r = _last;
    final (Color bg, IconData icon, String title) = r == null
        ? (YColors.night, Icons.qr_code_scanner, 'Présentez le billet')
        : switch (r.result) {
            'ok' => (YColors.success, Icons.check_circle, 'ENTRÉE VALIDÉE'),
            'duplicate' => (YColors.warning, Icons.replay_circle_filled, 'DÉJÀ ENTRÉ'),
            _ => (YColors.danger, Icons.cancel, 'REFUSÉ'),
          };
    final t = r?.ticket;
    final type = t == null ? null : LocalDb.get('ev_ticket_types', t['type_id'] as String?);
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('Contrôle — ${event['name'] ?? ''}')),
        actions: [
          if (tkCameraSupported)
            IconButton(
              tooltip: trn(_camera ? 'Arrêter la caméra' : 'Scanner avec la caméra'),
              onPressed: _toggleCamera,
              icon: Icon(_camera ? Icons.videocam_off_outlined : Icons.photo_camera_outlined),
            ),
          Center(child: Padding(padding: const EdgeInsets.only(right: 16), child: Text(tr('$entries entrée(s) aujourd\'hui'), style: const TextStyle(fontWeight: FontWeight.w700)))),
        ],
      ),
      body: GestureDetector(
        onTap: () => _camera ? null : _focus.requestFocus(),
        child: ListView(padding: const EdgeInsets.all(16), children: [
          if (_camera && _cam != null) ...[
            ClipRRect(
              borderRadius: BorderRadius.circular(20),
              child: SizedBox(
                height: 280,
                child: Stack(fit: StackFit.expand, children: [
                  MobileScanner(
                    controller: _cam,
                    onDetect: _onDetect,
                    errorBuilder: (context, error) => Container(
                      color: YColors.night,
                      padding: const EdgeInsets.all(20),
                      alignment: Alignment.center,
                      child: Text(
                        tr(error.errorCode == MobileScannerErrorCode.permissionDenied
                            ? 'Accès à la caméra refusé : autorisez-le dans les réglages, ou utilisez la saisie du code.'
                            : 'Caméra indisponible (${error.errorDetails?.message ?? error.errorCode.name}). Utilisez la saisie du code ou une douchette.'),
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: Colors.white),
                      ),
                    ),
                  ),
                  IgnorePointer(
                    child: Center(
                      child: Container(
                        width: 190,
                        height: 190,
                        decoration: BoxDecoration(border: Border.all(color: _last == null ? Colors.white : bg, width: 4), borderRadius: BorderRadius.circular(18)),
                      ),
                    ),
                  ),
                  Positioned(
                    right: 8,
                    top: 8,
                    child: Row(children: [
                      IconButton.filledTonal(tooltip: tr('Lampe'), onPressed: () => _cam?.toggleTorch(), icon: const Icon(Icons.flashlight_on_outlined)),
                      const SizedBox(width: 6),
                      IconButton.filledTonal(tooltip: tr('Changer de caméra'), onPressed: () => _cam?.switchCamera(), icon: const Icon(Icons.cameraswitch_outlined)),
                    ]),
                  ),
                ]),
              ),
            ),
            const SizedBox(height: 12),
          ],
          AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            padding: const EdgeInsets.symmetric(vertical: 34, horizontal: 20),
            decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
            child: Column(children: [
              Icon(icon, color: Colors.white, size: 84),
              const SizedBox(height: 8),
              Text(tr(title), style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w900, letterSpacing: 1)),
              if (r?.reason != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr(r!.reason!), style: const TextStyle(color: Colors.white, fontSize: 17))),
              if (t != null) ...[
                const SizedBox(height: 14),
                Text(tr('${t['holder_name'] ?? ''}'), style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
                Text(tr([type?['name'] ?? tkKinds[t['kind']] ?? '', if (t['company_name'] != null) '${t['company_name']}', '${t['code']}'].join(' · ')), style: const TextStyle(color: Colors.white70, fontSize: 15)),
              ],
            ]),
          ),
          const SizedBox(height: 16),
          Row(children: [
            Expanded(
              child: TextField(
                controller: _code,
                focusNode: _focus,
                autofocus: !_camera,
                textCapitalization: TextCapitalization.characters,
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, letterSpacing: 2),
                decoration: InputDecoration(prefixIcon: Icon(Icons.qr_code_2), hintText: tr('Scannez ou tapez le code (ABCD-1234)')),
                onSubmitted: _scan,
              ),
            ),
            const SizedBox(width: 10),
            FilledButton(onPressed: () => _scan(_code.text), style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 20)), child: Text(tr('Valider'))),
          ]),
          const SizedBox(height: 8),
          Wrap(spacing: 12, runSpacing: 8, alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, children: [
            Text(
                tr(_camera
                    ? 'Caméra : présentez le QR code dans le cadre. Fonctionne hors ligne.'
                    : 'Lecteur USB ou Bluetooth : le code est validé automatiquement. Fonctionne hors ligne.'),
                style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
            SizedBox(
              width: 200,
              child: TextField(
                controller: _gate,
                decoration: InputDecoration(isDense: true, labelText: tr('Point de contrôle')),
                onChanged: (v) => LocalDb.meta.put('scan_gate', v),
              ),
            ),
          ]),
          const SizedBox(height: 16),
          for (final (h, at) in _history)
            ListTile(
              dense: true,
              leading: Icon(h.ok ? Icons.check_circle : (h.result == 'duplicate' ? Icons.replay : Icons.cancel), color: h.ok ? YColors.success : (h.result == 'duplicate' ? YColors.warning : YColors.danger)),
              title: Text(tr('${h.ticket?['holder_name'] ?? 'Inconnu'} · ${h.ticket?['code'] ?? ''}')),
              subtitle: Text(tr(h.reason ?? 'Entrée validée')),
              trailing: Text(tr('${at.hour.toString().padLeft(2, '0')}:${at.minute.toString().padLeft(2, '0')}:${at.second.toString().padLeft(2, '0')}')),
            ),
        ]),
      ),
    );
  }
}

/// Accueil billetterie (guichet et contrôle d'entrée) : événements avec des tarifs.
class BilletterieHome extends StatefulWidget {
  const BilletterieHome({super.key});

  @override
  State<BilletterieHome> createState() => _BilletterieHomeState();
}

class _BilletterieHomeState extends State<BilletterieHome> {
  String _q = '';
  int _rk = 0;
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return Column(children: [
      PageHeader(title: tr('Billetterie & contrôle d\'entrée'), subtitle: tr('Vente au guichet, badges et contrôle des QR codes, même hors ligne')),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('ev_events'),
          builder: (context, _, _) {
            final today = DateTime.now().toIso8601String().substring(0, 10);
            final events = LocalDb.all('ev_events').where((e) => '${e['end_date']}'.compareTo(today) >= 0 && e['status'] != 'cancelled').toList()
              ..sort((a, b) => '${a['start_date']}'.compareTo('${b['start_date']}'));
            if (events.isEmpty) return EmptyState(icon: Icons.confirmation_number_outlined, title: tr('Aucun événement à venir'));
            final q = _q.toLowerCase();
            final shown = events
                .where((e) => q.isEmpty || '${e['name'] ?? ''} ${e['venue'] ?? ''} ${e['city'] ?? ''}'.toLowerCase().contains(q))
                .where((e) => _f['city'] == null || '${e['city'] ?? ''}' == _f['city'])
                .where((e) => _f['types'] == null || (tkTypes('${e['id']}').isNotEmpty) == (_f['types'] == 'yes'))
                .where((e) => _period == null || _period!.contains(e['start_date']) || _period!.contains(e['end_date']))
                .toList();
            return Column(children: [
              FilterBar(
                key: ValueKey(_rk),
                onSearch: (v) => setState(() => _q = v),
                search: _q,
                searchHint: 'Nom, lieu, ville…',
                filters: [
                  FilterSpec('city', 'Ville', filterOptions(events, 'city'), icon: Icons.location_city_outlined),
                  const FilterSpec('types', 'Tarifs', {'yes': 'Avec tarifs', 'no': 'Sans tarif'}, icon: Icons.sell_outlined),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                showPeriod: true,
                period: _period,
                onPeriod: (p) => setState(() => _period = p),
                count: shown.length,
                countLabel: 'événement',
                onReset: () => setState(() => (_rk++, _q = '', _f = {}, _period = null)),
              ),
              Expanded(
                child: shown.isEmpty
                    ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun événement ne correspond aux filtres'))
                    : ListView(padding: EdgeInsets.symmetric(horizontal: MediaQuery.sizeOf(context).width < 700 ? 16 : 24), children: [
              for (final e in shown)
                Builder(builder: (context) {
                  final narrow = MediaQuery.sizeOf(context).width < 700;
                  final buttons = [
                    if (tkCanSell(session) && tkTypes('${e['id']}').isNotEmpty)
                      OutlinedButton.icon(onPressed: () => boxOfficeSale(context, e), icon: const Icon(Icons.point_of_sale), label: Text(tr('Guichet'))),
                    if (tkCanScan(session))
                      FilledButton.icon(onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ScanScreen(eventId: '${e['id']}'))), icon: const Icon(Icons.qr_code_scanner), label: Text(tr('Contrôle'))),
                  ];
                  final info = Row(children: [
                    Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
                      child: const Icon(Icons.event, size: 22, color: YColors.blue),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${e['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                        const SizedBox(height: 2),
                        Text(tr('${dateFr(e['start_date'])}${e['end_date'] != e['start_date'] ? ' au ${dateFr(e['end_date'])}' : ''} · ${tkTickets('${e['id']}').where((t) => t['status'] == 'valid').length} billet(s) et badge(s)'),
                            style: TextStyle(color: Theme.of(context).hintColor)),
                      ]),
                    ),
                  ]);
                  return Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: narrow
                          ? Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                              info,
                              if (buttons.isNotEmpty) ...[
                                const SizedBox(height: 12),
                                Row(children: [for (final (i, b) in buttons.indexed) ...[if (i > 0) const SizedBox(width: 8), Expanded(child: b)]]),
                              ],
                            ])
                          : Row(children: [
                              Expanded(child: info),
                              for (final b in buttons) ...[const SizedBox(width: 8), b],
                            ]),
                    ),
                  );
                }),
                      ]),
              ),
            ]);
          },
        ),
      ),
    ]);
  }
}
