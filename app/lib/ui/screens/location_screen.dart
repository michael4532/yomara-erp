import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../pdf/rh_document_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/records.dart';
import 'insights_view.dart' show compactMoney;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Location de matériel : contrats (devis → réservation → sortie → retour → facture), disponibilités, parc.
class LocationScreen extends StatefulWidget {
  const LocationScreen({super.key});

  @override
  State<LocationScreen> createState() => _LocationScreenState();
}

class _LocationScreenState extends State<LocationScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    void changed() => _dash.currentState?.reload();
    final tabs = <(String, Widget)>[
      ('Contrats', RecordsView(module: 'location', resource: 'rentals', emptyIcon: Icons.key_outlined, newLabel: tr('Nouvelle location'), onChanged: changed, actions: rentalActions,
          emptyMessage: tr('Créez un devis de location : client, dates, puis le matériel ; le prix se calcule selon la durée.'))),
      ('Disponibilités', const _Availability()),
      ('Matériel', RecordsView(module: 'location', resource: 'items', emptyIcon: Icons.construction_outlined, newLabel: tr('Nouveau matériel'), onChanged: changed,
          emptyMessage: tr('Enregistrez le matériel que vous louez, avec ses tarifs et sa caution.'))),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Location de matériel'), subtitle: tr('Réservations, sorties, retours, cautions et factures')),
        ModuleDashboard(
          key: _dash,
          path: 'location/dashboard',
          builder: (context, d, _) => StatStrip(tiles: [
            StatTile(label: tr('Taux d\'utilisation'), value: '${d['utilization']} %', detail: tr('${d['units_out']} / ${d['units']} unités sorties'), icon: Icons.pie_chart_outline),
            StatTile(label: tr('Locations en cours'), value: '${d['out']}', detail: tr('${d['late']} en retard · ${d['returns_today']} retour(s) aujourd\'hui'), icon: Icons.key_outlined, color: const Color(0xFFD97706)),
            StatTile(label: tr('Départs à préparer'), value: '${d['departures']}', detail: tr('aujourd\'hui et demain · ${d['quotes']} devis'), icon: Icons.local_shipping_outlined, color: const Color(0xFF1F6FEB)),
            StatTile(label: tr('Chiffre d\'affaires du mois'), value: compactMoney(toDouble(d['revenue_month']), cur), detail: tr('cautions détenues ${compactMoney(toDouble(d['deposits_held']), cur)}'), icon: Icons.payments_outlined, color: YColors.success),
          ]),
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

Future<void> _run(BuildContext dialog, VoidCallback reload, String id, String action, Map<String, dynamic> body, String done) async {
  try {
    await dialog.read<Session>().api.post('location/rentals/$id/$action', body);
    if (dialog.mounted) {
      Navigator.pop(dialog, <String, dynamic>{action: true});
      toast(dialog, done);
    }
    reload();
  } catch (e) {
    if (dialog.mounted) toast(dialog, errorText(e), error: true);
  }
}

/// Boutons d'un contrat selon son étape.
List<Widget> rentalActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  final st = row['status'] as String?;
  final manage = s.can('location.manage');
  final id = '${row['id']}';
  return [
    OutlinedButton.icon(
      icon: const Icon(Icons.inventory_2_outlined, size: 18),
      label: Text(tr('Matériel du contrat')),
      onPressed: () => showDialog<void>(
        context: dialog,
        builder: (c) => Dialog(
          child: SizedBox(
            width: 980,
            height: 600,
            child: Column(children: [
              ListTile(title: Text(verbatim('${row['number']}')), subtitle: Text(tr('${dateTimeFr(row['start_at'])} → ${dateTimeFr(row['end_at'])}')), trailing: IconButton(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close))),
              Expanded(child: RecordsView(module: 'location', resource: 'lines', filters: {'rental_id': id}, newLabel: tr('Ajouter du matériel'), onChanged: reload)),
            ]),
          ),
        ),
      ),
    ),
    OutlinedButton.icon(icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(tr('Bon de location')), onPressed: () => _rentalPdf(dialog, row)),
    if (manage && st == 'quote')
      OutlinedButton.icon(
        icon: const Icon(Icons.event_available_outlined, size: 18),
        label: Text(tr('Réserver')),
        onPressed: () async {
          final dep = await _depositDialog(dialog, row);
          if (dep == null || !dialog.mounted) return;
          await _run(dialog, reload, id, 'reserve', dep, tr('Matériel réservé sur la période.'));
        },
      ),
    if (manage && (st == 'quote' || st == 'reserved'))
      FilledButton.tonalIcon(
        icon: const Icon(Icons.logout, size: 18),
        label: Text(tr('Sortie du matériel')),
        onPressed: () async {
          final dep = row['deposit_status'] == 'received' ? <String, dynamic>{} : await _depositDialog(dialog, row);
          if (dep == null || !dialog.mounted) return;
          await _run(dialog, reload, id, 'checkout', dep, tr('Sortie enregistrée.'));
        },
      ),
    if (manage && st == 'out')
      FilledButton.tonalIcon(
        icon: const Icon(Icons.login, size: 18),
        label: Text(tr('Retour du matériel')),
        onPressed: () async {
          final body = await _returnDialog(dialog, row);
          if (body == null || !dialog.mounted) return;
          await _run(dialog, reload, id, 'return', body, tr('Retour enregistré : retard et dégâts ajoutés au contrat.'));
        },
      ),
    if (manage && s.hasModule('ventes') && row['invoice_id'] == null && (st == 'reserved' || st == 'out' || st == 'returned'))
      OutlinedButton.icon(
        icon: const Icon(Icons.receipt_long_outlined, size: 18),
        label: Text(tr('Facturer')),
        onPressed: () => _run(dialog, reload, id, 'invoice', {}, tr('Facture créée en brouillon dans Ventes.')),
      ),
    if (manage && (st == 'quote' || st == 'reserved'))
      TextButton.icon(
        icon: const Icon(Icons.cancel_outlined, size: 18),
        label: Text(tr('Annuler')),
        onPressed: () async {
          if (!await confirm(dialog, tr('Annuler la location'), tr('Le matériel est libéré.'), ok: tr('Annuler la location'))) return;
          if (dialog.mounted) await _run(dialog, reload, id, 'cancel', {}, tr('Location annulée.'));
        },
      ),
  ];
}

Future<Map<String, dynamic>?> _depositDialog(BuildContext context, Map<String, dynamic> row) {
  var received = false;
  var method = 'cash';
  const methods = {'cash': 'Espèces', 'mobile_money': 'Mobile Money', 'cheque': 'Chèque', 'transfer': 'Virement', 'card': 'Carte'};
  return showDialog<Map<String, dynamic>>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr('Caution')),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(row['deposit_amount'] == null ? tr('Montant calculé d\'après le matériel (caution par unité).') : tr('Caution demandée : ${money(toDouble(row['deposit_amount']), context.read<Session>().currency)}')),
          CheckboxListTile(contentPadding: EdgeInsets.zero, value: received, onChanged: (v) => set(() => received = v ?? false), title: Text(tr('Caution encaissée'))),
          if (received)
            DropdownButtonFormField<String>(
              initialValue: method,
              decoration: InputDecoration(labelText: tr('Versée par')),
              items: [for (final m in methods.entries) DropdownMenuItem(value: m.key, child: Text(tr(m.value)))],
              onChanged: (v) => set(() => method = v ?? method),
            ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, <String, dynamic>{if (received) 'deposit_received': true, if (received) 'deposit_method': method}), child: Text(tr('Valider'))),
        ],
      ),
    ),
  );
}

Future<Map<String, dynamic>?> _returnDialog(BuildContext context, Map<String, dynamic> row) {
  final kept = TextEditingController();
  final late = TextEditingController();
  return showDialog<Map<String, dynamic>>(
    context: context,
    builder: (c) => YAlertDialog(
      title: Text(tr('Retour du matériel')),
      content: SizedBox(
        width: 420,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('Notez d\'abord l\'état au retour et les dégâts éventuels dans « Matériel du contrat ».'), style: TextStyle(color: Theme.of(c).hintColor)),
          const SizedBox(height: 12),
          TextField(controller: late, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Retard facturé'), helperText: tr('Laissez vide : calculé au tarif journalier'))),
          if (row['deposit_status'] == 'received')
            TextField(controller: kept, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Retenu sur la caution'), helperText: tr('0 : caution restituée en totalité'))),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: () => Navigator.pop(c, <String, dynamic>{
            if (late.text.trim().isNotEmpty) 'late_fee': toDouble(late.text),
            if (kept.text.trim().isNotEmpty) 'deposit_kept': toDouble(kept.text),
          }),
          child: Text(tr('Enregistrer le retour')),
        ),
      ],
    ),
  );
}

/// Bon de location à signer au départ : matériel, période, prix, caution, conditions.
Future<void> _rentalPdf(BuildContext dialog, Map<String, dynamic> row) async {
  final s = dialog.read<Session>();
  final cur = s.currency;
  try {
    final r = await s.api.get('location/records/lines', query: {'rental_id': '${row['id']}'});
    final lines = [for (final x in ((r as Map)['data'] as List)) asMap(x)];
    final client = '${row['partner_id_label'] ?? row['customer_name'] ?? ''}';
    final b = StringBuffer()
      ..writeln('${tr('Client')} : $client${row['phone'] != null ? ' — ${row['phone']}' : ''}')
      ..writeln('${tr('Période')} : ${dateTimeFr(row['start_at'])} → ${dateTimeFr(row['end_at'])} (${row['period']})');
    if (row['delivery'] == true) b.writeln('${tr('Livraison')} : ${row['delivery_address'] ?? ''}');
    b.writeln('\n${tr('MATÉRIEL LOUÉ')}');
    for (final l in lines) {
      b.writeln('• ${l['quantity']} × ${l['item_id_label']} — ${money(toDouble(l['amount']), cur)}${(l['condition_out'] ?? '').toString().isNotEmpty ? ' (${tr('état')} : ${l['condition_out']})' : ''}');
    }
    if (toDouble(row['discount_percent']) > 0) b.writeln('${tr('Remise')} : ${row['discount_percent']} %');
    if (toDouble(row['delivery_fee']) > 0) b.writeln('${tr('Transport')} : ${money(toDouble(row['delivery_fee']), cur)}');
    b
      ..writeln('${tr('Total HT')} : ${money(toDouble(row['total']), cur)}')
      ..writeln('${tr('Caution')} : ${row['deposit_amount'] == null ? '—' : money(toDouble(row['deposit_amount']), cur)}')
      ..writeln('\n${tr('Le matériel est rendu propre et en bon état à la date prévue. Toute journée de retard entamée est facturée au tarif journalier ; les dégâts et pertes sont facturés ou retenus sur la caution.')}');
    if ((row['notes'] ?? '').toString().isNotEmpty) b.writeln('\n${row['notes']}');
    final st = s.companySettings;
    final bytes = await rhDocumentPdf(company: s.company, doc: {'type': 'rental', 'title': tr('Bon de location'), 'number': row['number'], 'date': isoDate(DateTime.now()), 'body': b.toString()},
        employeeName: client, signatory: st['legal_signatory'] as String?, signatoryTitle: st['legal_signatory_title'] as String?, partyLabel: tr('Le locataire'), companyLabel: tr('Le loueur'));
    final where = await saveFile('${row['number']}.pdf', bytes, mime: 'application/pdf');
    if (dialog.mounted) toast(dialog, tr('PDF enregistré : $where'));
  } catch (e) {
    if (dialog.mounted) toast(dialog, errorText(e), error: true);
  }
}

/// Matériel libre sur une période.
class _Availability extends StatefulWidget {
  const _Availability();

  @override
  State<_Availability> createState() => _AvailabilityState();
}

class _AvailabilityState extends State<_Availability> {
  late DateTime _start;
  late DateTime _end;
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  static String _state(Map<String, dynamic> r) {
    if (r['status'] != 'available') return 'repair';
    final free = (r['free'] as num? ?? 0).toInt();
    final q = (r['quantity'] as num? ?? 0).toInt();
    return free == 0 ? 'full' : (free < q ? 'partial' : 'free');
  }

  @override
  void initState() {
    super.initState();
    final t = DateTime.now().add(const Duration(days: 1));
    _start = DateTime(t.year, t.month, t.day, 8);
    _end = _start.add(const Duration(days: 1));
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final r = await context.read<Session>().api.get('location/availability', query: {'start': _start.toIso8601String(), 'end': _end.toIso8601String()});
      if (mounted) setState(() => _rows = [for (final x in ((r as Map)['data'] as List)) asMap(x)]);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _pick(bool start) async {
    final cur = start ? _start : _end;
    final d = await showDatePicker(context: context, initialDate: cur, firstDate: DateTime(2020), lastDate: DateTime(2100), locale: Locale(I18n.lang));
    if (d == null || !mounted) return;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(cur));
    final v = DateTime(d.year, d.month, d.day, t?.hour ?? cur.hour, t?.minute ?? cur.minute);
    setState(() {
      if (start) {
        _start = v;
        if (!_end.isAfter(_start)) _end = _start.add(const Duration(days: 1));
      } else if (v.isAfter(_start)) {
        _end = v;
      }
    });
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final all = _rows ?? const <Map<String, dynamic>>[];
    final base = all
        .where((r) => _q.isEmpty || '${r['title']} ${r['category'] ?? ''}'.toLowerCase().contains(_q))
        .where((r) => _f['category'] == null || '${r['category']}' == _f['category'])
        .toList();
    final shown = base.where((r) => _status.isEmpty || _state(r) == _status).toList();
    const statuses = {'': 'Tous', 'free': 'Disponibles', 'partial': 'Partiellement loués', 'full': 'Indisponibles', 'repair': 'En réparation'};
    return Column(children: [
      const SizedBox(height: 12),
      FilterBar(
        key: ValueKey(_gen),
        onSearch: (v) => setState(() => _q = v.toLowerCase()),
        search: _q,
        searchHint: 'Matériel ou catégorie…',
        statuses: statuses,
        status: _status,
        onStatus: (v) => setState(() => _status = v),
        statusCounts: {for (final k in statuses.keys) k: base.where((r) => k.isEmpty || _state(r) == k).length},
        filters: [FilterSpec('category', 'Catégorie', filterOptions(all, 'category'), icon: Icons.category_outlined)],
        values: _f,
        onFilter: (k, v) => setState(() => _f[k] = v),
        count: _rows == null ? null : shown.length,
        countLabel: 'matériel',
        onReset: () => setState(() {
          _q = '';
          _status = '';
          _f.clear();
          _gen++;
        }),
        trailing: [
          OutlinedButton.icon(onPressed: () => _pick(true), icon: const Icon(Icons.event, size: 18), label: Text(tr('Du ${dateTimeFr(_start.toIso8601String())}'))),
          OutlinedButton.icon(onPressed: () => _pick(false), icon: const Icon(Icons.event, size: 18), label: Text(tr('Au ${dateTimeFr(_end.toIso8601String())}'))),
          IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      Expanded(
        child: _error != null
            ? OnlineOnlyNotice(error: _error!, onRetry: _load)
            : _rows == null
                ? const Center(child: CircularProgressIndicator())
                : shown.isEmpty
                    ? EmptyState(icon: Icons.construction_outlined, title: tr('Aucun matériel'))
                    : ListView.separated(
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        itemCount: shown.length,
                        separatorBuilder: (_, _) => const Divider(height: 1),
                        itemBuilder: (c, i) {
                          final r = shown[i];
                          final free = (r['free'] as num).toInt();
                          final q = (r['quantity'] as num).toInt();
                          final color = free == 0 ? YColors.danger : (free < q ? const Color(0xFFD97706) : YColors.success);
                          return ListTile(
                            leading: Icon(Icons.construction_outlined, color: color),
                            title: Text(verbatim('${r['title']}')),
                            subtitle: Text(tr('${r['category'] ?? 'Sans catégorie'} · ${money(toDouble(r['rate_day']), cur)} / jour')),
                            trailing: StatusChip(r['status'] != 'available' ? tr('En réparation') : tr('$free / $q libre(s)'), color: color),
                          );
                        },
                      ),
      ),
    ]);
  }
}
