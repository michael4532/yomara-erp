import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const prDeliverableLabels = {'draft': 'Brouillon', 'sent': 'Chez le client', 'approved': 'Validé', 'changes': 'Modifications demandées'};

String _h(dynamic v) {
  final d = toDouble(v);
  final s = d == d.roundToDouble() ? '${d.toInt()}' : d.toStringAsFixed(2).replaceAll(RegExp(r'0$'), '').replaceAll('.', ',');
  return '$s h';
}

double prConsumed(String packId) => LocalDb.hasEntity('pr_entries') ? LocalDb.all('pr_entries').where((e) => e['pack_id'] == packId).fold(0.0, (s, e) => s + toDouble(e['hours'])) : 0;

bool _manage(Session s) => s.can('prestations.manage');
bool _log(Session s) => s.can('prestations.log') || s.can('prestations.manage');

Color _deliverableColor(String s) => switch (s) { 'approved' => YColors.success, 'sent' => YColors.blue, 'changes' => YColors.danger, _ => YColors.muted };

/// Forfaits d'heures et livrables à faire valider par les clients.
class PrestationsScreen extends StatefulWidget {
  const PrestationsScreen({super.key});

  @override
  State<PrestationsScreen> createState() => _PrestationsScreenState();
}

class _PrestationsScreenState extends State<PrestationsScreen> {
  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final narrow = MediaQuery.of(context).size.width < 700;
    final reports = session.can('prestations.view') || _manage(session);
    return DefaultTabController(
      length: reports ? 3 : 2,
      child: Column(children: [
        PageHeader(
          title: tr('Forfaits & livrables'),
          subtitle: tr('Heures prépayées des clients, temps consommé, validation des livrables'),
          actions: [
            if (_log(session)) OutlinedButton.icon(onPressed: () => logTime(context), icon: const Icon(Icons.more_time), label: Text(tr('Saisir du temps'))),
            if (_manage(session)) FilledButton.icon(onPressed: () => editPack(context), icon: const Icon(Icons.add), label: Text(tr('Forfait'))),
          ],
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
          child: Row(children: [
            Expanded(
                child: TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
              Tab(text: tr('Forfaits d\'heures')),
              Tab(text: tr('Livrables')),
              if (reports) Tab(text: tr('Rapports clients')),
            ])),
          ]),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: ValueListenableBuilder(
            valueListenable: LocalDb.listen('pr_entries'),
            builder: (context, _, _) => TabBarView(children: [_PacksTab(), _DeliverablesTab(), if (reports) _ReportsTab()]),
          ),
        ),
      ]),
    );
  }
}

class _PacksTab extends StatefulWidget {
  const _PacksTab();

  @override
  State<_PacksTab> createState() => _PacksTabState();
}

class _PacksTabState extends State<_PacksTab> {
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  static String _usage(Map<String, dynamic> p) {
    final hours = toDouble(p['hours']);
    final pct = hours > 0 ? prConsumed('${p['id']}') / hours : 0.0;
    return pct >= 1 ? 'over' : (pct >= toDouble(p['alert_percent'] ?? 80) / 100 ? 'alert' : 'ok');
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pr_packs'),
      builder: (context, _, _) {
        final q = _q;
        final live = LocalDb.all('pr_packs').where((p) => p['status'] != 'cancelled').toList();
        final base = live
            .where((p) => q.isEmpty || '${p['name']} ${p['partner_name']} ${p['number'] ?? ''}'.toLowerCase().contains(q))
            .where((p) => _f['partner'] == null || '${p['partner_id']}' == _f['partner'])
            .where((p) => _f['usage'] == null || _usage(p) == _f['usage'])
            .where((p) => _period == null || _period!.contains(p['start_date']))
            .toList();
        const statuses = {'': 'Tous', 'active': 'En cours', 'exhausted': 'Consommés', 'expired': 'Expirés'};
        final filterBar = FilterBar(
          key: ValueKey(_gen),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Client, forfait, n°…',
          statuses: statuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in statuses.keys) k: base.where((p) => k.isEmpty || p['status'] == k).length},
          filters: [
            FilterSpec('partner', 'Client', filterOptions(live, 'partner_id', label: (p) => '${p['partner_name'] ?? p['partner_id']}'), icon: Icons.business_outlined),
            const FilterSpec('usage', 'Consommation', {'ok': 'Normale', 'alert': 'Seuil d\'alerte atteint', 'over': 'Épuisé ou dépassé'}, icon: Icons.speed_outlined),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f[k] = v),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: base.where((p) => _status.isEmpty || p['status'] == _status).length,
          countLabel: 'forfait',
          onReset: () => setState(() {
            _q = '';
            _status = '';
            _f.clear();
            _period = null;
            _gen++;
          }),
        );
        final packs = base.where((p) => _status.isEmpty || p['status'] == _status).toList()
          ..sort((a, b) {
            final o = {'active': 0, 'exhausted': 1, 'expired': 2};
            final c = (o[a['status']] ?? 3).compareTo(o[b['status']] ?? 3);
            return c != 0 ? c : '${b['start_date']}'.compareTo('${a['start_date']}');
          });
        if (live.isEmpty) {
          return EmptyState(icon: Icons.work_history_outlined, title: tr('Aucun forfait'), message: tr('Vendez des forfaits d\'heures (maintenance, support, community management) et suivez leur consommation.'));
        }
        return Column(children: [
          filterBar,
          Expanded(
            child: packs.isEmpty
                ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucun forfait ne correspond à ces filtres.'))
                : GridView.extent(
                    maxCrossAxisExtent: 420,
                    mainAxisExtent: 196,
                    padding: const EdgeInsets.fromLTRB(24, 4, 24, 24),
                    crossAxisSpacing: 12,
                    mainAxisSpacing: 12,
                    children: [for (final p in packs) _PackCard(pack: p, currency: session.currency)],
                  ),
          ),
        ]);
      },
    );
  }
}

class _PackCard extends StatelessWidget {
  const _PackCard({required this.pack, required this.currency});

  final Map<String, dynamic> pack;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final used = prConsumed('${pack['id']}');
    final hours = toDouble(pack['hours']);
    final pct = hours > 0 ? used / hours : 0.0;
    final alert = toDouble(pack['alert_percent'] ?? 80) / 100;
    final color = pct >= 1 ? YColors.danger : (pct >= alert ? YColors.warning : YColors.success);
    final hint = Theme.of(context).hintColor;
    final status = '${pack['status']}';
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => showPack(context, '${pack['id']}'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr('${pack['partner_name'] ?? ''}'), style: TextStyle(color: hint, fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis)),
              StatusChip({'active': 'En cours', 'exhausted': 'Consommé', 'expired': 'Expiré'}[status] ?? status, color: status == 'active' ? YColors.success : (status == 'expired' ? YColors.muted : YColors.danger)),
            ]),
            const SizedBox(height: 4),
            Text(tr('${pack['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16), maxLines: 1, overflow: TextOverflow.ellipsis),
            const Spacer(),
            Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(tr(_h(used)), style: TextStyle(fontSize: 26, fontWeight: FontWeight.w900, color: color)),
              Text(tr('  / ${_h(hours)}'), style: TextStyle(fontSize: 16, color: hint)),
              const Spacer(),
              Text(tr(used <= hours ? 'reste ${_h(hours - used)}' : 'dépassement ${_h(used - hours)}'), style: TextStyle(fontWeight: FontWeight.w700, color: used > hours ? YColors.danger : null)),
            ]),
            const SizedBox(height: 6),
            ClipRRect(borderRadius: BorderRadius.circular(6), child: LinearProgressIndicator(value: pct.clamp(0, 1).toDouble(), minHeight: 9, color: color, backgroundColor: YColors.line)),
            const SizedBox(height: 8),
            Text(tr([
              '${pack['number'] ?? ''}',
              if (pack['expiry_date'] != null) 'jusqu\'au ${dateFr(pack['expiry_date'])}',
              if (pack['invoice_id'] != null) 'facturé ${money(toDouble(pack['price']), currency)}',
            ].join(' · ')), style: TextStyle(fontSize: 12, color: hint), maxLines: 1, overflow: TextOverflow.ellipsis),
          ]),
        ),
      ),
    );
  }
}

Future<void> showPack(BuildContext context, String id) =>
    showFormDialog(context, title: tr('Forfait'), maxWidth: 680, builder: (_) => _PackSheet(id: id));

class _PackSheet extends StatefulWidget {
  const _PackSheet({required this.id});

  final String id;

  @override
  State<_PackSheet> createState() => _PackSheetState();
}

class _PackSheetState extends State<_PackSheet> {
  bool _busy = false;

  Future<void> _api(String path, String done) async {
    setState(() => _busy = true);
    final sync = context.read<SyncEngine>();
    final api = context.read<Session>().api;
    try {
      await sync.sync();
      final r = await api.post(path);
      for (final k in ['pack', 'old', 'data']) {
        if (r is Map && r[k] is Map && asMap(r[k])['hours'] != null) await LocalDb.put('pr_packs', asMap(r[k]));
      }
      await sync.sync();
      if (mounted) toast(context, done);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pr_packs'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('pr_entries'),
        builder: (context, _, _) {
          final p = LocalDb.get('pr_packs', widget.id);
          if (p == null) return Padding(padding: EdgeInsets.all(24), child: Text(tr('Forfait introuvable.')));
          final entries = LocalDb.all('pr_entries').where((e) => e['pack_id'] == p['id']).toList()..sort((a, b) => '${b['date']}${b['created_at'] ?? ''}'.compareTo('${a['date']}${a['created_at'] ?? ''}'));
          final used = entries.fold<double>(0, (s, e) => s + toDouble(e['hours']));
          final hours = toDouble(p['hours']);
          final overUnbilled = (used - hours).clamp(0, double.infinity) - toDouble(p['overage_billed']);
          final hint = Theme.of(context).hintColor;
          return ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), shrinkWrap: true, children: [
            Text(tr('${p['name']}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            Text(tr('${p['partner_name'] ?? ''} · ${p['number'] ?? ''} · depuis le ${dateFr(p['start_date'])}${p['expiry_date'] != null ? ' · jusqu\'au ${dateFr(p['expiry_date'])}' : ''}'), style: TextStyle(color: hint)),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: _stat('Consommé', _h(used))),
              Expanded(child: _stat('Forfait', _h(hours))),
              Expanded(child: _stat(used <= hours ? 'Reste' : 'Dépassement', _h((hours - used).abs()), color: used > hours ? YColors.danger : YColors.success)),
              Expanded(child: _stat('Prix HT', money(toDouble(p['price']), session.currency))),
            ]),
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (_log(session) && p['status'] != 'expired') FilledButton.icon(onPressed: () => logTime(context, packId: '${p['id']}'), icon: const Icon(Icons.more_time), label: Text(tr('Saisir du temps'))),
              if (_manage(session) && p['invoice_id'] == null && toDouble(p['price']) > 0) OutlinedButton.icon(onPressed: _busy ? null : () => _api('prestations/packs/${p['id']}/invoice', 'Forfait facturé.'), icon: const Icon(Icons.request_quote_outlined), label: Text(tr('Facturer le forfait'))),
              if (_manage(session) && overUnbilled > 0.001) OutlinedButton.icon(onPressed: _busy ? null : () => _api('prestations/packs/${p['id']}/overage', 'Dépassement facturé.'), icon: const Icon(Icons.add_card), label: Text(tr('Facturer ${_h(overUnbilled)} de dépassement'))),
              if (_manage(session) && p['status'] != 'active' || (_manage(session) && used / (hours == 0 ? 1 : hours) >= .8))
                OutlinedButton.icon(onPressed: _busy ? null : () => _api('prestations/packs/${p['id']}/renew', 'Forfait renouvelé et facturé.'), icon: const Icon(Icons.autorenew), label: Text(tr('Renouveler'))),
              if (_manage(session)) TextButton.icon(onPressed: () => editPack(context, p), icon: const Icon(Icons.edit_outlined), label: Text(tr('Modifier'))),
            ]),
            const SizedBox(height: 14),
            Text(tr('Temps passé (${entries.length})'), style: Theme.of(context).textTheme.titleSmall),
            if (entries.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 10), child: Text(tr('Aucune saisie.'), style: TextStyle(color: hint))),
            for (final e in entries)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(tr('${e['description']}')),
                subtitle: Text(tr([dateFr(e['date']), if (e['project_name'] != null) 'Projet ${e['project_name']}', if (e['user_name'] != null) '${e['user_name']}'].join(' · '))),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  Text(tr(_h(e['hours'])), style: const TextStyle(fontWeight: FontWeight.w700)),
                  if (e['timesheet_id'] != null)
                    Tooltip(message: tr('Saisi dans le projet : modifiez-le dans le projet'), child: Padding(padding: EdgeInsets.only(left: 8), child: Icon(Icons.task_alt, size: 18, color: YColors.blue)))
                  else if (_manage(session) || e['user_id'] == session.user['id'])
                    IconButton(
                      icon: const Icon(Icons.delete_outline, size: 18),
                      onPressed: () async {
                        if (await confirm(context, tr('Supprimer ce temps ?'), '${e['description']} — ${_h(e['hours'])}', ok: tr('Supprimer'), danger: true) && context.mounted) {
                          await context.read<SyncEngine>().remove('pr_entries', '${e['id']}');
                        }
                      },
                    ),
                ]),
              ),
          ]);
        },
      ),
    );
  }

  Widget _stat(String l, String v, {Color? color}) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr(v), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: color)),
        Text(tr(l), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
      ]);
}

/// Saisie de temps sur un forfait (hors ligne).
Future<void> logTime(BuildContext context, {String? packId}) async {
  final packs = LocalDb.all('pr_packs').where((p) => p['status'] == 'active' || p['status'] == 'exhausted').toList()..sort((a, b) => '${a['partner_name']}'.compareTo('${b['partner_name']}'));
  if (packs.isEmpty) return toast(context, tr('Aucun forfait en cours.'), error: true);
  String? pid = packId ?? (packs.length == 1 ? '${packs.first['id']}' : null);
  final hours = TextEditingController();
  final desc = TextEditingController();
  var date = DateTime.now();
  final sync = context.read<SyncEngine>();
  await showDialog(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr('Saisir du temps')),
        content: SizedBox(
          width: 420,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String?>(
              initialValue: pid,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Forfait')),
              items: [for (final p in packs) DropdownMenuItem(value: '${p['id']}', child: Text(tr('${p['partner_name']} — ${p['name']}'), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => set(() => pid = v),
            ),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: TextField(
                  controller: hours,
                  autofocus: true,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                  decoration: InputDecoration(labelText: tr('Durée (heures)'), hintText: '1,5'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton(
                  onPressed: () async {
                    final d = await pickDate(c, initialDate: date, firstDate: DateTime(2020), lastDate: DateTime.now());
                    if (d != null) set(() => date = d);
                  },
                  child: Text(tr(dateFr(isoDate(date)))),
                ),
              ),
            ]),
            const SizedBox(height: 8),
            Wrap(spacing: 6, children: [for (final q in const [0.25, 0.5, 1.0, 2.0, 4.0]) ActionChip(label: Text(tr(_h(q))), onPressed: () => hours.text = '$q'.replaceAll('.', ','))]),
            TextField(controller: desc, decoration: InputDecoration(labelText: tr('Travail réalisé'))),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          SaveButton(
            onPressed: () async {
              final h = toDouble(hours.text);
              if (pid == null || h <= 0 || h > 24 || desc.text.trim().isEmpty) return toast(c, tr('Forfait, durée (24 h au plus) et description obligatoires.'), error: true);
              await sync.save('pr_entries', {'pack_id': pid, 'date': isoDate(date), 'hours': h, 'description': desc.text.trim()});
              if (c.mounted) Navigator.pop(c);
            },
            child: Text(tr('Enregistrer')),
          ),
        ],
      ),
    ),
  );
}

Future<void> editPack(BuildContext context, [Map<String, dynamic>? pack]) =>
    showFormDialog(context, title: pack == null ? 'Nouveau forfait d\'heures' : 'Modifier le forfait', builder: (_) => _PackForm(pack: pack));

class _PackForm extends StatefulWidget {
  const _PackForm({this.pack});

  final Map<String, dynamic>? pack;

  @override
  State<_PackForm> createState() => _PackFormState();
}

class _PackFormState extends State<_PackForm> {
  late final p = widget.pack ?? {};
  late String? _partner = p['partner_id'] as String?;
  late final _c = {
    'name': TextEditingController(text: '${p['name'] ?? ''}'),
    'hours': TextEditingController(text: p['hours'] == null ? '' : '${toDouble(p['hours'])}'.replaceAll(RegExp(r'\.0$'), '')),
    'price': TextEditingController(text: p['price'] == null ? '' : '${toDouble(p['price']).round()}'),
    'hourly_rate': TextEditingController(text: p['hourly_rate'] == null ? '' : '${toDouble(p['hourly_rate']).round()}'),
    'tax_rate': TextEditingController(text: '${toDouble(p['tax_rate'] ?? 18)}'.replaceAll(RegExp(r'\.0$'), '')),
    'alert_percent': TextEditingController(text: '${p['alert_percent'] ?? 80}'),
    'notes': TextEditingController(text: '${p['notes'] ?? ''}'),
  };
  late String _start = '${p['start_date'] ?? isoDate(DateTime.now())}';
  late String? _expiry = p['expiry_date'] as String?;
  bool _invoice = true;

  Future<void> _save() async {
    if (_partner == null || _c['name']!.text.trim().isEmpty || toDouble(_c['hours']!.text) <= 0) return toast(context, tr('Client, nom et nombre d\'heures obligatoires.'), error: true);
    await context.read<SyncEngine>().save('pr_packs', {
      if (widget.pack != null) 'id': widget.pack!['id'],
      'partner_id': _partner,
      'name': _c['name']!.text.trim(),
      if (widget.pack?['invoice_id'] == null) 'hours': toDouble(_c['hours']!.text),
      'price': toDouble(_c['price']!.text),
      'hourly_rate': _c['hourly_rate']!.text.trim().isEmpty ? null : toDouble(_c['hourly_rate']!.text),
      'tax_rate': toDouble(_c['tax_rate']!.text),
      'alert_percent': toDouble(_c['alert_percent']!.text).toInt().clamp(10, 99),
      'start_date': _start,
      'expiry_date': _expiry,
      'notes': _c['notes']!.text.trim().isEmpty ? null : _c['notes']!.text.trim(),
      if (widget.pack == null) 'invoice': _invoice,
    });
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final customers = LocalDb.all('partners').where((x) => x['type'] != 'supplier' && x['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    Widget f(String k, String label, {String? hint, bool enabled = true}) => Expanded(child: Field(label: label, child: TextField(controller: _c[k], enabled: enabled, keyboardType: TextInputType.number, decoration: InputDecoration(hintText: trn(hint)))));
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Field(
        label: tr('Client'),
        child: DropdownButtonFormField<String?>(
          initialValue: customers.any((c) => c['id'] == _partner) ? _partner : null,
          isExpanded: true,
          items: [for (final c in customers) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis))],
          onChanged: widget.pack?['invoice_id'] != null ? null : (v) => setState(() => _partner = v),
        ),
      ),
      Field(label: tr('Nom du forfait'), child: TextField(controller: _c['name'], decoration: InputDecoration(hintText: tr('Maintenance site web, Community management, Support informatique…')))),
      Row(children: [
        f('hours', 'Heures', enabled: widget.pack?['invoice_id'] == null),
        const SizedBox(width: 12),
        f('price', 'Prix HT'),
        const SizedBox(width: 12),
        f('tax_rate', 'TVA %'),
      ]),
      Row(children: [
        f('hourly_rate', 'Taux horaire de dépassement', hint: tr('Facultatif')),
        const SizedBox(width: 12),
        f('alert_percent', 'Alerte à (%)'),
      ]),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Début'),
            child: OutlinedButton(
              onPressed: () async {
                final d = await pickDate(context, initialDate: DateTime.tryParse(_start) ?? DateTime.now(), firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (d != null) setState(() => _start = isoDate(d));
              },
              child: Text(tr(dateFr(_start))),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Valable jusqu\'au (facultatif)'),
            child: OutlinedButton(
              onPressed: () async {
                final d = await pickDate(context, initialDate: DateTime.tryParse(_expiry ?? '') ?? DateTime.now().add(const Duration(days: 90)), firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (d != null) setState(() => _expiry = isoDate(d));
              },
              child: Text(tr(_expiry == null ? 'Sans limite' : dateFr(_expiry))),
            ),
          ),
        ),
      ]),
      if (widget.pack == null) SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Facturer le forfait maintenant')), value: _invoice, onChanged: (v) => setState(() => _invoice = v)),
      Field(label: tr('Notes'), child: TextField(controller: _c['notes'], maxLines: 2)),
      Align(alignment: Alignment.centerRight, child: SaveButton(onPressed: _save, child: Text(tr('Enregistrer')))),
    ]);
  }
}

// ---------------------------------------------------------------- livrables

class _DeliverablesTab extends StatefulWidget {
  const _DeliverablesTab();

  @override
  State<_DeliverablesTab> createState() => _DeliverablesTabState();
}

class _DeliverablesTabState extends State<_DeliverablesTab> {
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pr_deliverables'),
      builder: (context, _, _) {
        final q = _q;
        final all = LocalDb.all('pr_deliverables');
        final base = all
            .where((d) => q.isEmpty || '${d['title']} ${d['partner_name']} ${d['number'] ?? ''}'.toLowerCase().contains(q))
            .where((d) => _f['partner'] == null || '${d['partner_id']}' == _f['partner'])
            .where((d) => _period == null || _period!.contains(d['due_date'] ?? d['updated_at']))
            .toList();
        final statuses = {'': 'Tous', ...prDeliverableLabels};
        final rows = base.where((d) => _status.isEmpty || d['status'] == _status).toList()
          ..sort((a, b) {
            final o = {'changes': 0, 'draft': 1, 'sent': 2, 'approved': 3};
            final c = (o[a['status']] ?? 4).compareTo(o[b['status']] ?? 4);
            return c != 0 ? c : '${b['updated_at'] ?? ''}'.compareTo('${a['updated_at'] ?? ''}');
          });
        return Column(children: [
          FilterBar(
            key: ValueKey(_gen),
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            search: _q,
            searchHint: 'Livrable, client, n°…',
            statuses: statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in statuses.keys) k: base.where((d) => k.isEmpty || d['status'] == k).length},
            filters: [FilterSpec('partner', 'Client', filterOptions(all, 'partner_id', label: (d) => '${d['partner_name'] ?? d['partner_id']}'), icon: Icons.business_outlined)],
            values: _f,
            onFilter: (k, v) => setState(() => _f[k] = v),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: rows.length,
            countLabel: 'livrable',
            onReset: () => setState(() {
              _q = '';
              _status = '';
              _f.clear();
              _period = null;
              _gen++;
            }),
            trailing: [
              if (_log(session)) FilledButton.tonalIcon(onPressed: () => editDeliverable(context), icon: const Icon(Icons.add), label: Text(tr('Livrable'))),
            ],
          ),
          Expanded(
            child: rows.isEmpty
                ? EmptyState(icon: Icons.rate_review_outlined, title: tr('Aucun livrable'), message: tr('Envoyez maquettes, rapports ou sites de recette à vos clients : ils valident ou demandent des modifications en un clic.'))
                : ResponsiveList<Map<String, dynamic>>(
                    items: rows,
                    columns: const ['Livrable', 'Client', 'Version', 'Échéance', 'État'],
                    onTap: (d) => editDeliverable(context, d),
                    cells: (d) => [
                      Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${d['title']}'), style: const TextStyle(fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis),
                        if (d['client_comment'] != null && d['status'] == 'changes') Text(tr('« ${d['client_comment']} »'), style: const TextStyle(fontSize: 12, color: YColors.danger), maxLines: 1, overflow: TextOverflow.ellipsis),
                      ]),
                      Row(children: [InitialsAvatar('${d['partner_name'] ?? ''}'), const SizedBox(width: 10), Flexible(child: Text(tr('${d['partner_name'] ?? ''}'), overflow: TextOverflow.ellipsis))]),
                      Text(tr('v${d['version_no'] ?? 1}')),
                      Text(tr(d['due_date'] == null ? '—' : dateFr(d['due_date']))),
                      StatusChip(prDeliverableLabels[d['status']] ?? '${d['status']}', color: _deliverableColor('${d['status']}')),
                    ],
                    mobileTile: (d) => Row(children: [
                      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${d['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text(tr('${d['partner_name'] ?? ''} · v${d['version_no'] ?? 1}'), style: TextStyle(color: Theme.of(context).hintColor)),
                      ])),
                      StatusChip(prDeliverableLabels[d['status']] ?? '${d['status']}', color: _deliverableColor('${d['status']}')),
                    ]),
                  ),
          ),
        ]);
      },
    );
  }
}

Future<void> editDeliverable(BuildContext context, [Map<String, dynamic>? d]) =>
    showFormDialog(context, title: d == null ? 'Nouveau livrable' : '${d['number'] ?? 'Livrable'} · v${d['version_no'] ?? 1}', maxWidth: 640, builder: (_) => _DeliverableForm(id: d?['id'] as String?));

class _DeliverableForm extends StatefulWidget {
  const _DeliverableForm({this.id});

  final String? id;

  @override
  State<_DeliverableForm> createState() => _DeliverableFormState();
}

class _DeliverableFormState extends State<_DeliverableForm> {
  late final String _id = widget.id ?? newId();
  late final Map<String, dynamic> d = asMap(LocalDb.get('pr_deliverables', _id));
  late String? _partner = d['partner_id'] as String?;
  late String? _pack = d['pack_id'] as String?;
  late final _title = TextEditingController(text: '${d['title'] ?? ''}');
  late final _desc = TextEditingController(text: '${d['description'] ?? ''}');
  late final _url = TextEditingController(text: '${d['url'] ?? ''}');
  late String? _due = d['due_date'] as String?;
  bool _busy = false;

  String get _status => '${d['status'] ?? 'draft'}';
  bool get _editable => _status == 'draft' || _status == 'changes';

  Map<String, dynamic> _payload() => {
        'id': _id,
        'partner_id': _partner,
        'pack_id': _pack,
        'title': _title.text.trim(),
        'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
        'url': _url.text.trim().isEmpty ? null : _url.text.trim(),
        'due_date': _due,
      };

  Future<bool> _save({String? status, bool newVersion = false}) async {
    if (_partner == null || _title.text.trim().isEmpty) {
      toast(context, tr('Client et titre obligatoires.'), error: true);
      return false;
    }
    final u = _url.text.trim();
    if (u.isNotEmpty && !RegExp(r'^https?://').hasMatch(u)) {
      toast(context, tr('Le lien doit commencer par https://'), error: true);
      return false;
    }
    final saved = await context.read<SyncEngine>().save('pr_deliverables', {..._payload(), 'status': ?status, if (newVersion) 'new_version': true});
    if (!mounted) return true;
    setState(() => d
      ..clear()
      ..addAll(saved));
    return true;
  }

  Future<void> _upload() async {
    final r = await FilePicker.pickFiles();
    if (r.isEmpty || !mounted) return;
    if (!await _save()) return;
    if (!mounted) return;
    setState(() => _busy = true);
    final sync = context.read<SyncEngine>();
    final api = context.read<Session>().api;
    try {
      await sync.sync();
      final res = await api.upload('prestations/deliverables/$_id/file', 'file', await r.first.readAsBytes(), r.first.name);
      final rec = asMap(res['data']);
      await LocalDb.put('pr_deliverables', rec);
      if (!mounted) return;
      setState(() => d
        ..clear()
        ..addAll(rec));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final customers = LocalDb.all('partners').where((x) => x['type'] != 'supplier' && x['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final packs = LocalDb.all('pr_packs').where((p) => p['partner_id'] == _partner && p['status'] != 'cancelled').toList();
    final hint = Theme.of(context).hintColor;
    final history = [for (final h in (d['history'] as List? ?? const [])) asMap(h)];
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      if (d.isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 8), child: Row(children: [StatusChip(prDeliverableLabels[_status] ?? _status, color: _deliverableColor(_status)), const SizedBox(width: 8), if (d['sent_at'] != null) Text(tr('envoyé le ${dateFr(d['sent_at'])}'), style: TextStyle(color: hint))])),
      if (_status == 'changes' && d['client_comment'] != null)
        Card(color: YColors.danger.withValues(alpha: .06), child: ListTile(leading: const Icon(Icons.feedback_outlined, color: YColors.danger), title: Text(tr('${d['decided_by'] ?? 'Le client'} demande :')), subtitle: Text(tr('${d['client_comment']}')))),
      if (_status == 'approved') Card(color: YColors.success.withValues(alpha: .08), child: ListTile(leading: const Icon(Icons.verified, color: YColors.success), title: Text(tr('Validé par ${d['decided_by']} le ${dateFr(d['decided_at'])}')), subtitle: d['client_comment'] == null ? null : Text(tr('${d['client_comment']}')))),
      Field(
        label: tr('Client'),
        child: DropdownButtonFormField<String?>(
          initialValue: customers.any((c) => c['id'] == _partner) ? _partner : null,
          isExpanded: true,
          items: [for (final c in customers) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis))],
          onChanged: d['sent_at'] != null ? null : (v) => setState(() => _partner = v),
        ),
      ),
      Field(label: tr('Titre'), child: TextField(controller: _title, enabled: _editable, decoration: InputDecoration(hintText: tr('Maquette page d\'accueil, Rapport mensuel, Vidéo promotionnelle…')))),
      Field(label: tr('Message au client'), child: TextField(controller: _desc, enabled: _editable, maxLines: 3)),
      Field(label: tr('Lien (site de recette, Figma, vidéo…)'), child: TextField(controller: _url, enabled: _editable, decoration: InputDecoration(hintText: tr('https://')))),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Fichier'),
            child: OutlinedButton.icon(
              onPressed: !_editable || _busy || !_log(session) ? null : _upload,
              icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.attach_file),
              label: Text(tr(d['file_name'] != null ? '${d['file_name']}' : 'Joindre un fichier (en ligne)'), overflow: TextOverflow.ellipsis),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Réponse attendue avant le'),
            child: OutlinedButton(
              onPressed: !_editable
                  ? null
                  : () async {
                      final x = await pickDate(context, initialDate: DateTime.tryParse(_due ?? '') ?? DateTime.now().add(const Duration(days: 3)), firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime(2100));
                      if (x != null) setState(() => _due = isoDate(x));
                    },
              child: Text(tr(_due == null ? '—' : dateFr(_due))),
            ),
          ),
        ),
      ]),
      if (packs.isNotEmpty)
        Field(
          label: tr('Forfait lié (facultatif)'),
          child: DropdownButtonFormField<String?>(
            initialValue: packs.any((p) => p['id'] == _pack) ? _pack : null,
            isExpanded: true,
            items: [const DropdownMenuItem(value: null, child: Text('—')), for (final p in packs) DropdownMenuItem(value: '${p['id']}', child: Text(tr('${p['name']}')))],
            onChanged: _editable ? (v) => setState(() => _pack = v) : null,
          ),
        ),
      if (history.isNotEmpty) ...[
        Text(tr('Historique'), style: Theme.of(context).textTheme.titleSmall),
        for (final h in history.reversed)
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: Icon(switch (h['event']) { 'approved' => Icons.verified, 'changes' => Icons.feedback_outlined, _ => Icons.send_outlined }, size: 18, color: _deliverableColor(h['event'] == 'sent' ? 'sent' : '${h['event']}')),
            title: Text(tr('v${h['v']} · ${switch (h['event']) { 'sent' => 'envoyé', 'approved' => 'validé', _ => 'modifications demandées' }}${h['by'] != null ? ' par ${h['by']}' : ''}')),
            subtitle: Text(tr('${dateFr(h['at'])}${h['comment'] != null ? ' — ${h['comment']}' : ''}')),
          ),
      ],
      const SizedBox(height: 12),
      Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.end, children: [
        if (d['public_url'] != null && d['sent_at'] != null) TextButton.icon(onPressed: () => Clipboard.setData(ClipboardData(text: '${d['public_url']}')).then((_) => context.mounted ? toast(context, tr('Lien copié.')) : null), icon: const Icon(Icons.link), label: Text(tr('Copier le lien client'))),
        if (d['public_url'] != null && d['sent_at'] != null) TextButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${d['public_url']}'), mode: LaunchMode.externalApplication), icon: const Icon(Icons.open_in_new), label: Text(tr('Ouvrir'))),
        if (_status == 'sent' && _manage(session))
          OutlinedButton.icon(
            onPressed: () => context.read<Session>().api.post('prestations/deliverables/$_id/resend').then((_) {
              if (context.mounted) toast(context, tr('Relance envoyée.'));
            }).catchError((e) {
              if (context.mounted) toast(context, errorText(e), error: true);
            }),
            icon: const Icon(Icons.notifications_active_outlined),
            label: Text(tr('Relancer')),
          ),
        if ((_status == 'changes' || _status == 'approved') && _log(session))
          OutlinedButton.icon(onPressed: () => _save(newVersion: true), icon: const Icon(Icons.upgrade), label: Text(tr('Nouvelle version (v${toDouble(d['version_no'] ?? 1).toInt() + 1})'))),
        if (_editable && _log(session) && _status == 'draft' || d.isEmpty)
          OutlinedButton(onPressed: () async {
            if (await _save() && context.mounted) Navigator.pop(context);
          }, child: Text(tr('Enregistrer'))),
        if (_editable && _manage(session) && _status != 'changes')
          FilledButton.icon(
            onPressed: () async {
              if (_url.text.trim().isEmpty && d['file_name'] == null && _desc.text.trim().isEmpty) return toast(context, tr('Ajoutez un fichier, un lien ou un message avant l\'envoi.'), error: true);
              if (await _save(status: 'sent') && context.mounted) {
                Navigator.pop(context);
                toast(context, tr('Livrable envoyé au client pour validation.'));
              }
            },
            icon: const Icon(Icons.send),
            label: Text(tr('Envoyer au client')),
          ),
      ]),
    ]);
  }
}


// ======================================================================
// Rapports d'activité mensuels des clients

String _ym(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}';

String prMonthLabel(String ym) {
  const m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  final i = int.tryParse(ym.length >= 7 ? ym.substring(5, 7) : '') ?? 0;
  return i < 1 ? ym : '${m[i - 1]} ${ym.substring(0, 4)}';
}

class _ReportsTab extends StatefulWidget {
  const _ReportsTab();

  @override
  State<_ReportsTab> createState() => _ReportsTabState();
}

class _ReportsTabState extends State<_ReportsTab> {
  Map<String, dynamic>? _settings;
  String? _busy;
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('prestations/settings');
      if (mounted) setState(() => _settings = asMap(r['data']));
    } catch (_) {}
  }

  Future<void> _save(Map<String, dynamic> v) async {
    try {
      final r = await context.read<Session>().api.put('prestations/settings', v);
      if (mounted) setState(() => _settings = asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _send(Map<String, dynamic> r) async {
    setState(() => _busy = '${r['id']}');
    try {
      final res = await context.read<Session>().api.post('prestations/reports/${r['id']}/send');
      await LocalDb.put('pr_reports', asMap(res['data']));
      if (mounted) toast(context, tr('Rapport envoyé au client.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final hint = Theme.of(context).hintColor;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pr_reports'),
      builder: (context, _, _) {
        final all = LocalDb.hasEntity('pr_reports') ? LocalDb.all('pr_reports') : <Map<String, dynamic>>[];
        final base = all
            .where((r) => _q.isEmpty || '${r['partner_name']} ${r['label']}'.toLowerCase().contains(_q))
            .where((r) => _f['partner'] == null || '${r['partner_id']}' == _f['partner'])
            .where((r) => _period == null || _period!.contains('${r['period']}'.length == 7 ? '${r['period']}-01' : r['period']))
            .toList();
        bool sentMatch(Map<String, dynamic> r, String k) => k.isEmpty || (k == 'sent') == (r['sent_at'] != null);
        const statuses = {'': 'Tous', 'sent': 'Envoyés', 'unsent': 'Non envoyés'};
        final rows = base.where((r) => sentMatch(r, _status)).toList()
          ..sort((a, b) => '${b['period']}${b['partner_name']}'.compareTo('${a['period']}${a['partner_name']}'));
        return ListView(padding: const EdgeInsets.fromLTRB(24, 4, 24, 24), children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Wrap(spacing: 16, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
                const Icon(Icons.summarize_outlined, color: YColors.blue, size: 30),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 520),
                  child: Text(tr('Chaque client reçoit le rapport de son mois : temps passé, livrables et reste de ses forfaits, en PDF par e-mail (lien par WhatsApp). Il le retrouve aussi sur son espace client.'),
                      style: TextStyle(color: hint)),
                ),
                if (_manage(session) && _settings != null) ...[
                  FilterChip(
                    avatar: const Icon(Icons.schedule_send, size: 18),
                    label: Text(tr('Envoi automatique le 1er du mois')),
                    selected: _settings!['monthly_reports'] == true,
                    onSelected: (v) => _save({'monthly_reports': v}),
                  ),
                  FilterChip(
                    label: Text(tr('Inclure le temps des projets')),
                    selected: _settings!['include_projects'] != false,
                    onSelected: (v) => _save({'include_projects': v}),
                  ),
                ],
                if (_manage(session)) FilledButton.icon(onPressed: () => generateReport(context), icon: const Icon(Icons.picture_as_pdf_outlined), label: Text(tr('Générer un rapport'))),
              ]),
            ),
          ),
          const SizedBox(height: 12),
          FilterBar(
            key: ValueKey(_gen),
            padding: const EdgeInsets.only(bottom: 8),
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            search: _q,
            searchHint: 'Client, mois…',
            statuses: statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in statuses.keys) k: base.where((r) => sentMatch(r, k)).length},
            filters: [FilterSpec('partner', 'Client', filterOptions(all, 'partner_id', label: (r) => '${r['partner_name'] ?? r['partner_id']}'), icon: Icons.business_outlined)],
            values: _f,
            onFilter: (k, v) => setState(() => _f[k] = v),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: rows.length,
            countLabel: 'rapport',
            onReset: () => setState(() {
              _q = '';
              _status = '';
              _f.clear();
              _period = null;
              _gen++;
            }),
          ),
          if (rows.isEmpty) EmptyState(icon: Icons.summarize_outlined, title: tr('Aucun rapport'), message: tr('Générez le rapport d\'un client ou activez l\'envoi automatique.')),
          for (final r in rows)
            Card(
              child: ListTile(
                leading: const CircleAvatar(backgroundColor: YColors.line, child: Icon(Icons.picture_as_pdf, color: YColors.danger)),
                title: Text(tr('${r['partner_name'] ?? ''} — ${r['label'] ?? prMonthLabel('${r['period']}')}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(tr([
                  _h(r['hours']),
                  '${r['deliverables'] ?? 0} livrable(s)',
                  r['sent_at'] != null ? 'envoyé le ${dateFr(r['sent_at'])} à ${r['sent_to'] ?? ''}' : 'non envoyé',
                ].join(' · '))),
                trailing: Wrap(spacing: 4, children: [
                  IconButton(tooltip: tr('Ouvrir le PDF'), onPressed: () => safeLaunchUrl(Uri.parse('${r['url']}')), icon: const Icon(Icons.open_in_new)),
                  IconButton(
                    tooltip: tr('Copier le lien'),
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: '${r['url']}'));
                      toast(context, tr('Lien copié.'));
                    },
                    icon: const Icon(Icons.link),
                  ),
                  if (_manage(session))
                    IconButton(tooltip: tr('Envoyer au client'), onPressed: _busy == null ? () => _send(r) : null, icon: _busy == '${r['id']}' ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send_outlined)),
                ]),
              ),
            ),
        ]);
      },
    );
  }
}

/// Génère (ou régénère) le rapport d'un client pour un mois, et l'envoie si demandé.
Future<void> generateReport(BuildContext context, {String? partnerId}) async {
  final api = context.read<Session>().api;
  final ids = <String>{
    for (final p in LocalDb.all('pr_packs')) '${p['partner_id']}',
    if (LocalDb.hasEntity('pr_deliverables')) for (final d in LocalDb.all('pr_deliverables')) '${d['partner_id']}',
    if (LocalDb.hasEntity('prj_projects')) for (final p in LocalDb.all('prj_projects')) if (p['partner_id'] != null) '${p['partner_id']}',
  };
  final partners = LocalDb.all('partners').where((p) => ids.contains(p['id'])).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
  final now = DateTime.now();
  final months = [for (var i = 0; i < 12; i++) _ym(DateTime(now.year, now.month - i))];
  var partner = partnerId ?? (partners.isEmpty ? null : partners.first['id'] as String);
  var period = months[now.day <= 10 ? 1 : 0];
  var send = false;
  var busy = false;
  await showFormDialog(
    context,
    title: tr('Rapport d\'activité'),
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(
          label: tr('Client'),
          child: DropdownButtonFormField<String>(
            initialValue: partner,
            isExpanded: true,
            items: [for (final p in partners) DropdownMenuItem(value: p['id'] as String, child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
            onChanged: (v) => set(() => partner = v),
          ),
        ),
        Field(
          label: tr('Mois'),
          child: DropdownButtonFormField<String>(
            initialValue: period,
            items: [for (final m in months) DropdownMenuItem(value: m, child: Text(tr(prMonthLabel(m))))],
            onChanged: (v) => set(() => period = v ?? period),
          ),
        ),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Envoyer au client (e-mail avec le PDF, lien WhatsApp)')), value: send, onChanged: (v) => set(() => send = v)),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerRight,
          child: FilledButton.icon(
            onPressed: busy || partner == null
                ? null
                : () async {
                    set(() => busy = true);
                    try {
                      final r = await api.post('prestations/reports', {'partner_id': partner, 'period': period, 'send': send});
                      final rep = asMap(r['data']);
                      await LocalDb.put('pr_reports', rep);
                      if (ctx.mounted) {
                        Navigator.pop(ctx);
                        toast(ctx, send ? (r['sent'] == true ? 'Rapport généré et envoyé.' : 'Rapport généré ; aucun canal pour l\'envoyer : partagez le lien.') : 'Rapport généré.');
                      }
                      await safeLaunchUrl(Uri.parse('${rep['url']}'));
                    } catch (e) {
                      if (ctx.mounted) toast(ctx, errorText(e), error: true);
                      set(() => busy = false);
                    }
                  },
            icon: const Icon(Icons.picture_as_pdf_outlined),
            label: Text(tr('Générer')),
          ),
        ),
      ]),
    ),
  );
}
