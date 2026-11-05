import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/hebergement.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'document_editor.dart';
import 'documents_screen.dart' show DocConfig;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _icons = {
  'domain': Icons.language,
  'hosting': Icons.dns_outlined,
  'ssl': Icons.lock_outline,
  'email': Icons.alternate_email,
  'maintenance': Icons.build_circle_outlined,
  'license': Icons.key_outlined,
  'cloud': Icons.cloud_outlined,
  'other': Icons.widgets_outlined,
};

String _n(dynamic v) {
  if (v == null || '$v'.isEmpty) return '';
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.toInt()}' : '$d';
}

Widget hbStateChip(Map<String, dynamic> s) {
  final st = hbState(s);
  final d = hbDaysLeft(s);
  return switch (st) {
    'expired' => StatusChip(tr('Expiré depuis ${-d} j'), color: YColors.danger, icon: Icons.error_outline),
    'expiring' => StatusChip(d == 0 ? 'Expire aujourd\'hui' : 'Dans $d j', color: YColors.warning, icon: Icons.schedule),
    'suspended' => StatusChip(tr('Suspendu'), color: YColors.muted),
    'cancelled' => StatusChip(tr('Résilié'), color: YColors.muted),
    _ => StatusChip(tr('Dans $d j'), color: YColors.success),
  };
}

bool _manage(Session s) => s.can('hebergement.manage');

/// Services récurrents des clients : domaines, hébergements, SSL, e-mails pro, maintenance, licences.
class HebergementScreen extends StatefulWidget {
  const HebergementScreen({super.key});

  @override
  State<HebergementScreen> createState() => _HebergementScreenState();
}

class _HebergementScreenState extends State<HebergementScreen> {
  // '' = échéances à 60 jours (vue par défaut), 'all' = tous
  String _tab = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _rk = 0;

  static bool _inTab(Map<String, dynamic> s, String tab) => switch (tab) {
        '' => s['status'] == 'active' && (hbDaysLeft(s) <= 60 || s['pending'] != null),
        'todo' => s['provider_todo'] != null || s['pending'] != null,
        _ => true,
      };

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final narrow = MediaQuery.of(context).size.width < 700;
    final hint = Theme.of(context).hintColor;
    return Column(children: [
      PageHeader(
        title: tr('Hébergement, domaines & licences'),
        subtitle: tr('Échéances des services de vos clients, renouvellements et marge'),
        actions: [
          if (_manage(session)) IconButton(tooltip: tr('Rappels automatiques'), onPressed: () => _settings(context), icon: const Icon(Icons.notifications_active_outlined)),
          if (_manage(session)) FilledButton.icon(onPressed: () => editHostedService(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau service'))),
        ],
      ),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('hb_services'),
          builder: (context, _, _) {
            final st = hbStats();
            final all = LocalDb.all('hb_services');
            final base = all
                .where((s) => _f['type'] == null || s['type'] == _f['type'])
                .where((s) => _q.isEmpty || '${s['name']} ${s['partner_name']} ${s['provider'] ?? ''} ${s['number'] ?? ''}'.toLowerCase().contains(_q))
                .where((s) => _f['state'] == null || hbState(s) == _f['state'])
                .where((s) => _f['partner'] == null || '${s['partner_id'] ?? ''}' == _f['partner'])
                .where((s) => _f['provider'] == null || '${s['provider'] ?? ''}' == _f['provider'])
                .where((s) => _f['renew'] == null || (s['auto_renew'] != false) == (_f['renew'] == 'auto'))
                .where((s) => _period == null || _period!.contains(s['expiry_date']))
                .toList();
            var rows = base.where((s) => _inTab(s, _tab)).toList();
            rows = switch (_tab) {
              '' => rows..sort((a, b) => '${a['expiry_date']}'.compareTo('${b['expiry_date']}')),
              'todo' => rows..sort((a, b) => (a['provider_todo'] != null ? 0 : 1).compareTo(b['provider_todo'] != null ? 0 : 1)),
              _ => rows..sort((a, b) => '${a['partner_name']}${a['name']}'.toLowerCase().compareTo('${b['partner_name']}${b['name']}'.toLowerCase())),
            };
            Widget kpi(String label, String value, IconData icon, Color color) => KpiBox(
                  width: 220,
                  child: StatTile(label: label, value: value, icon: icon, color: color),
                );
            return Column(children: [
              Padding(
                padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  KpiWrap(children: [
                    kpi('Services actifs', '${st.active}', Icons.dns_outlined, YColors.blue),
                    kpi('À échéance (30 j)', '${st.expiring}', Icons.schedule, YColors.warning),
                    kpi('Expirés', '${st.expired}', Icons.error_outline, YColors.danger),
                    kpi('Revenu mensuel (HT)', money(st.mrr, session.currency), Icons.autorenew, YColors.success),
                    kpi('Marge mensuelle', money(st.margin, session.currency), Icons.trending_up, YColors.night),
                  ]),
                  const SizedBox(height: 12),
                ]),
              ),
              FilterBar(
                key: ValueKey(_rk),
                onSearch: (v) => setState(() => _q = v.toLowerCase()),
                search: _q,
                searchHint: 'Domaine, client, fournisseur…',
                statuses: const {'': 'Échéances (60 j)', 'todo': 'À faire', 'all': 'Tous'},
                status: _tab,
                onStatus: (v) => setState(() => _tab = v),
                statusCounts: {for (final k in ['', 'todo', 'all']) k: base.where((s) => _inTab(s, k)).length},
                filters: [
                  FilterSpec('type', 'Type', hbTypes, icon: Icons.category_outlined),
                  const FilterSpec('state', 'État', {'ok': 'Actif', 'expiring': 'À échéance', 'expired': 'Expiré', 'suspended': 'Suspendu', 'cancelled': 'Résilié'}, icon: Icons.flag_outlined),
                  FilterSpec('partner', 'Client', filterOptions(all, 'partner_id', label: (s) => '${s['partner_name'] ?? s['partner_id']}'), icon: Icons.person_outline),
                  FilterSpec('provider', 'Fournisseur', filterOptions(all, 'provider'), icon: Icons.storefront_outlined),
                  const FilterSpec('renew', 'Renouvellement', {'auto': 'Automatique', 'manual': 'Manuel'}, icon: Icons.autorenew),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                showPeriod: true,
                period: _period,
                onPeriod: (p) => setState(() => _period = p),
                count: rows.length,
                countLabel: 'service',
                onReset: () => setState(() => (_q = '', _tab = '', _f = {}, _period = null, _rk++)),
              ),
              Expanded(
                child: rows.isEmpty
                    ? EmptyState(icon: Icons.dns_outlined, title: _tab == 'todo' ? 'Rien à faire' : (all.isNotEmpty && base.length != all.length ? 'Aucun service ne correspond aux filtres' : 'Aucun service'), message: all.isEmpty ? 'Enregistrez les domaines, hébergements et licences de vos clients : Yomara facture et relance avant chaque échéance.' : null)
                    : ResponsiveList<Map<String, dynamic>>(
                        items: rows,
                        columns: const ['Service / client', 'Échéance', 'Prix HT', 'Renouvellement', 'État'],
                        onTap: (s) => showHostedService(context, s),
                        cells: (s) => [
                          Row(children: [
                            Icon(_icons[s['type']] ?? Icons.widgets_outlined, size: 18, color: YColors.blue),
                            const SizedBox(width: 10),
                            Flexible(
                              child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text(tr('${s['name']}'), style: const TextStyle(fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis),
                                Text(tr('${s['partner_name'] ?? ''}${s['provider'] != null ? ' · ${s['provider']}' : ''}'), style: TextStyle(fontSize: 12, color: hint), overflow: TextOverflow.ellipsis),
                              ]),
                            ),
                          ]),
                          Text(tr(dateFr(s['expiry_date']))),
                          Text(tr('${money(toDouble(s['sale_price']), session.currency)} / ${hbPeriod(s['period_months'])}'), softWrap: false),
                          _renewalCell(s, session.currency),
                          hbStateChip(s),
                        ],
                        mobileTile: (s) => Row(children: [
                          Icon(_icons[s['type']] ?? Icons.widgets_outlined, color: YColors.blue),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(tr('${s['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                              Text(tr('${s['partner_name'] ?? ''} · ${dateFr(s['expiry_date'])}'), style: TextStyle(color: hint)),
                            ]),
                          ),
                          hbStateChip(s),
                        ]),
                      ),
              ),
            ]);
          },
        ),
      ),
    ]);
  }

  Widget _renewalCell(Map<String, dynamic> s, String cur) {
    final p = s['pending'] as Map?;
    if (s['provider_todo'] != null) return StatusChip(tr('Payé : à renouveler chez le fournisseur'), color: YColors.blue, icon: Icons.published_with_changes);
    if (p != null) return StatusChip('${p['number'] ?? 'Facture'} ${p['payment_status'] == 'partial' ? 'en partie payée' : 'à encaisser'}', color: YColors.danger, icon: Icons.receipt_long_outlined);
    if (s['auto_renew'] == false) return Text(tr('Manuel'));
    return Text(tr('Auto. ${_n(s['renew_days_before'])} j avant'), style: TextStyle(color: Theme.of(context).hintColor));
  }

  Future<void> _settings(BuildContext context) async {
    final api = context.read<Session>().api;
    Map<String, dynamic> cfg = {'reminder_days': [30, 7, 1], 'auto_send': true};
    try {
      cfg = asMap((await api.get('hebergement/settings'))['data']);
    } catch (_) {}
    if (!context.mounted) return;
    final days = TextEditingController(text: (cfg['reminder_days'] as List).join(', '));
    var auto = cfg['auto_send'] == true;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Rappels aux clients')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Field(label: tr('Rappels avant l\'échéance (jours)'), child: TextField(controller: days, decoration: InputDecoration(helperText: tr('Ex. 30, 7, 1 — par e-mail et WhatsApp, avec le lien de paiement')))),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Envoyer la facture de renouvellement dès son émission')), value: auto, onChanged: (v) => set(() => auto = v)),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer')))],
        ),
      ),
    );
    if (ok != true) return;
    try {
      await api.put('hebergement/settings', {'reminder_days': days.text.split(RegExp(r'[,; ]+')).map(int.tryParse).whereType<int>().toList(), 'auto_send': auto});
      if (context.mounted) toast(context, tr('Rappels enregistrés.'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }
}

// ---------------------------------------------------------------- Fiche

Future<void> showHostedService(BuildContext context, Map<String, dynamic> service) =>
    showFormDialog(context, title: '${service['name']}', maxWidth: 640, builder: (_) => _ServiceSheet(id: '${service['id']}'));

class _ServiceSheet extends StatefulWidget {
  const _ServiceSheet({required this.id});

  final String id;

  @override
  State<_ServiceSheet> createState() => _ServiceSheetState();
}

class _ServiceSheetState extends State<_ServiceSheet> {
  List<Map<String, dynamic>>? _history;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  Future<void> _loadHistory() async {
    try {
      final r = await context.read<Session>().api.get('hebergement/services/${widget.id}/history');
      if (mounted) setState(() => _history = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (_) {
      if (mounted) setState(() => _history = const []);
    }
  }

  Future<void> _call(Future<dynamic> Function() fn, String done) async {
    setState(() => _busy = true);
    final sync = context.read<SyncEngine>();
    try {
      await sync.sync();
      final r = await fn();
      if (r is Map && r['data'] is Map && (r['data'] as Map)['partner_id'] != null) await LocalDb.put('hb_services', asMap(r['data']));
      if (r is Map && r['services'] is List) {
        await LocalDb.putMany('hb_services', [for (final x in (r['services'] as List)) asMap(x)]);
      }
      await sync.sync();
      await _loadHistory();
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
    final api = session.api;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('hb_services'),
      builder: (context, _, _) {
        final s = LocalDb.get('hb_services', widget.id);
        if (s == null) return Padding(padding: EdgeInsets.all(24), child: Text(tr('Service introuvable.')));
        final hint = Theme.of(context).hintColor;
        final p = s['pending'] as Map?;
        final todo = s['provider_todo'] as Map?;
        Widget row(String l, String v) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [SizedBox(width: 150, child: Text(tr(l), style: TextStyle(color: hint))), Expanded(child: Text(tr(v), style: const TextStyle(fontWeight: FontWeight.w600)))]),
            );
        return ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), shrinkWrap: true, children: [
          Wrap(spacing: 8, children: [hbStateChip(s), if (s['number'] != null) Text(tr('${s['number']}'), style: TextStyle(color: hint))]),
          const SizedBox(height: 10),
          row('Client', '${s['partner_name'] ?? ''}'),
          row('Échéance', '${dateFr(s['expiry_date'])}${s['start_date'] != null ? ' (depuis le ${dateFr(s['start_date'])})' : ''}'),
          row('Prix de vente', '${money(toDouble(s['sale_price']), session.currency)} HT / ${hbPeriod(s['period_months'])} · TVA ${_n(s['tax_rate'])} %'),
          row('Coût fournisseur', '${money(toDouble(s['cost_price']), session.currency)} · marge ${money(toDouble(s['sale_price']) - toDouble(s['cost_price']), session.currency)}'),
          if (s['provider'] != null || s['plan'] != null) row('Fournisseur / offre', [s['provider'], s['plan']].whereType<Object>().join(' · ')),
          row('Renouvellement', s['auto_renew'] == false ? 'Manuel' : 'Facture automatique ${_n(s['renew_days_before'])} jours avant l\'échéance${s['reminders'] == false ? ' · sans rappel' : ''}'),
          if (s['details'] != null) row('Détails techniques', '${s['details']}'),
          if (s['last_error'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('${s['last_error']}'), style: const TextStyle(color: YColors.danger))),
          if (p != null)
            Card(
              color: YColors.danger.withValues(alpha: .06),
              child: ListTile(
                leading: const Icon(Icons.receipt_long_outlined, color: YColors.danger),
                title: Text(tr('Facture ${p['number'] ?? ''} : ${money(toDouble(p['total']), session.currency)}')),
                subtitle: Text(tr('Période du ${dateFr(p['period_start'])} au ${dateFr(p['period_end'])} · ${p['payment_status'] == 'partial' ? 'en partie payée' : 'en attente de paiement'} — l\'échéance est prolongée à l\'encaissement.')),
                trailing: const Icon(Icons.open_in_new, size: 18),
                onTap: () {
                  final doc = LocalDb.hasEntity('sales_documents') ? LocalDb.get('sales_documents', '${p['document_id']}') : null;
                  if (doc == null) return toast(context, tr('Facture pas encore synchronisée sur cet appareil.'), error: true);
                  Navigator.of(context).push(MaterialPageRoute(builder: (_) => DocumentEditor(config: DocConfig.sale, document: doc, initialType: '${doc['type']}')));
                },
              ),
            ),
          if (todo != null)
            Card(
              color: YColors.blue.withValues(alpha: .06),
              child: ListTile(
                leading: const Icon(Icons.published_with_changes, color: YColors.blue),
                title: Text(tr('Payé par le client : à renouveler chez le fournisseur')),
                subtitle: Text(tr('${s['provider'] != null ? 'Chez ${s['provider']}, ' : ''}jusqu\'au ${dateFr(todo['period_end'])}.')),
              ),
            ),
          const SizedBox(height: 10),
          if (_manage(session))
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (todo != null) FilledButton.icon(onPressed: _busy ? null : () => _call(() => api.post('hebergement/services/${s['id']}/provider-done'), 'Renouvellement fournisseur noté.'), icon: const Icon(Icons.task_alt), label: Text(tr('Renouvelé chez le fournisseur'))),
              if (p == null && s['status'] == 'active')
                FilledButton.tonalIcon(
                  onPressed: _busy ? null : () => _call(() => api.post('hebergement/renew', {'ids': [s['id']], 'send': true}), 'Facture de renouvellement émise.'),
                  icon: const Icon(Icons.request_quote_outlined),
                  label: Text(tr('Facturer le renouvellement')),
                ),
              OutlinedButton.icon(onPressed: _busy ? null : () => _call(() => api.post('hebergement/services/${s['id']}/remind'), 'Rappel envoyé au client.'), icon: const Icon(Icons.send_outlined), label: Text(tr('Envoyer un rappel'))),
              if (p == null)
                OutlinedButton.icon(
                  onPressed: _busy
                      ? null
                      : () async {
                          if (!await confirm(context, tr('Prolonger sans facture ?'), tr('L\'échéance avance d\'une période (${hbPeriod(s['period_months'])}) : paiement reçu autrement, geste commercial…'), ok: tr('Prolonger'))) return;
                          await _call(() => api.post('hebergement/services/${s['id']}/extend', {'provider_done': true}), 'Échéance prolongée.');
                        },
                  icon: const Icon(Icons.update),
                  label: Text(tr('Prolonger sans facture')),
                ),
              TextButton.icon(onPressed: () => editHostedService(context, s), icon: const Icon(Icons.edit_outlined), label: Text(tr('Modifier'))),
            ]),
          const SizedBox(height: 14),
          Text(tr('Historique des renouvellements'), style: Theme.of(context).textTheme.titleSmall),
          if (_history == null) const Padding(padding: EdgeInsets.all(12), child: LinearProgressIndicator()),
          if (_history != null && _history!.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text(tr('Aucun (ou hors ligne).'), style: TextStyle(color: hint))),
          for (final h in _history ?? const <Map<String, dynamic>>[])
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Icon(
                switch (h['status']) { 'paid' => Icons.check_circle, 'manual' => Icons.update, 'cancelled' => Icons.cancel_outlined, _ => Icons.hourglass_top },
                color: switch (h['status']) { 'paid' => YColors.success, 'cancelled' => Colors.grey, 'invoiced' => YColors.warning, _ => YColors.blue },
                size: 20,
              ),
              title: Text(tr('Du ${dateFr(h['period_start'])} au ${dateFr(h['period_end'])}')),
              subtitle: Text(tr([
                switch (h['status']) { 'paid' => 'Payé', 'manual' => 'Prolongé sans facture', 'cancelled' => 'Facture annulée', _ => 'Facturé' },
                if (h['document_number'] != null) 'facture ${h['document_number']}',
                if (toDouble(h['amount']) > 0) money(toDouble(h['amount']), session.currency),
                if (h['provider_done_at'] != null) 'fournisseur renouvelé le ${dateFr(h['provider_done_at'])}${h['provider_done_by'] != null ? ' par ${h['provider_done_by']}' : ''}',
              ].join(' · '))),
            ),
        ]);
      },
    );
  }
}

// ---------------------------------------------------------------- Formulaire

Future<void> editHostedService(BuildContext context, [Map<String, dynamic>? service]) =>
    showFormDialog(context, title: service == null ? 'Nouveau service client' : 'Modifier le service', maxWidth: 640, builder: (_) => _ServiceForm(service: service));

class _ServiceForm extends StatefulWidget {
  const _ServiceForm({this.service});

  final Map<String, dynamic>? service;

  @override
  State<_ServiceForm> createState() => _ServiceFormState();
}

class _ServiceFormState extends State<_ServiceForm> {
  late final s = widget.service ?? {};
  late String? _partner = s['partner_id'] as String?;
  late String? _supplier = s['supplier_id'] as String?;
  late String _type = '${s['type'] ?? 'domain'}';
  late int _period = toDouble(s['period_months'] ?? 12).toInt();
  late String? _start = s['start_date'] as String?;
  late String? _expiry = s['expiry_date'] as String?;
  late bool _auto = s['auto_renew'] != false;
  late bool _reminders = s['reminders'] != false;
  late String _status = '${s['status'] ?? 'active'}';
  late final _c = {
    for (final k in ['name', 'provider', 'plan', 'details', 'notes']) k: TextEditingController(text: '${s[k] ?? ''}'),
    'cost_price': TextEditingController(text: _n(s['cost_price'])),
    'sale_price': TextEditingController(text: _n(s['sale_price'])),
    'tax_rate': TextEditingController(text: _n(s['tax_rate'] ?? 18)),
    'renew_days_before': TextEditingController(text: _n(s['renew_days_before'] ?? 30)),
  };

  Future<void> _date(bool start) async {
    final cur = DateTime.tryParse((start ? _start : _expiry) ?? '') ?? DateTime.now();
    final d = await showDatePicker(context: context, initialDate: cur, firstDate: DateTime(2000), lastDate: DateTime(2100));
    if (d == null) return;
    setState(() {
      if (start) {
        _start = isoDate(d);
        _expiry ??= isoDate(DateTime(d.year, d.month + _period, d.day).subtract(const Duration(days: 1)));
      } else {
        _expiry = isoDate(d);
      }
    });
  }

  Future<void> _save() async {
    if (_partner == null || _c['name']!.text.trim().isEmpty || _expiry == null) return toast(context, tr('Client, nom du service et échéance obligatoires.'), error: true);
    await context.read<SyncEngine>().save('hb_services', {
      if (widget.service != null) 'id': widget.service!['id'],
      'partner_id': _partner,
      'supplier_id': _supplier,
      'type': _type,
      for (final k in ['name', 'provider', 'plan', 'details', 'notes']) k: _c[k]!.text.trim().isEmpty ? null : _c[k]!.text.trim(),
      for (final k in ['cost_price', 'sale_price', 'tax_rate']) k: toDouble(_c[k]!.text),
      'renew_days_before': toDouble(_c['renew_days_before']!.text).toInt(),
      'period_months': _period,
      'start_date': _start,
      'expiry_date': _expiry,
      'auto_renew': _auto,
      'reminders': _reminders,
      'status': _status,
    });
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final partners = LocalDb.all('partners').where((p) => p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final customers = partners.where((p) => p['type'] != 'supplier').toList();
    final suppliers = partners.where((p) => p['type'] == 'supplier' || p['type'] == 'both').toList();
    final numFmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    final sale = toDouble(_c['sale_price']!.text), cost = toDouble(_c['cost_price']!.text);
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Field(
        label: tr('Client'),
        child: DropdownButtonFormField<String?>(
          initialValue: customers.any((c) => c['id'] == _partner) ? _partner : null,
          isExpanded: true,
          items: [for (final c in customers) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis))],
          onChanged: (v) => setState(() => _partner = v),
        ),
      ),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Type'),
            child: DropdownButtonFormField<String>(
              initialValue: _type,
              isExpanded: true,
              items: [for (final t in hbTypes.entries) DropdownMenuItem(value: t.key, child: Row(children: [Icon(_icons[t.key], size: 18), const SizedBox(width: 8), Text(tr(t.value))]))],
              onChanged: (v) => setState(() => _type = v!),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(flex: 2, child: Field(label: _type == 'domain' ? 'Nom de domaine' : 'Nom du service', child: TextField(controller: _c['name'], decoration: InputDecoration(hintText: trn(_type == 'domain' ? 'monclient.ci' : 'Hébergement monclient.ci'))))),
      ]),
      Row(children: [
        Expanded(child: Field(label: tr('Fournisseur (registrar, hébergeur…)'), child: TextField(controller: _c['provider'], decoration: InputDecoration(hintText: tr('NIC CI, OVH, o2switch, Microsoft…'))))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Offre'), child: TextField(controller: _c['plan'], decoration: InputDecoration(hintText: tr('Pro 10 Go, 5 boîtes…'))))),
      ]),
      if (suppliers.isNotEmpty)
        Field(
          label: tr('Fiche fournisseur (facultatif)'),
          child: DropdownButtonFormField<String?>(
            initialValue: suppliers.any((c) => c['id'] == _supplier) ? _supplier : null,
            isExpanded: true,
            items: [const DropdownMenuItem(value: null, child: Text('—')), for (final c in suppliers) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis))],
            onChanged: (v) => setState(() => _supplier = v),
          ),
        ),
      Row(children: [
        Expanded(child: Field(label: tr('Prix de vente HT'), child: TextField(controller: _c['sale_price'], inputFormatters: numFmt, keyboardType: TextInputType.number, onChanged: (_) => setState(() {})))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Coût fournisseur'), child: TextField(controller: _c['cost_price'], inputFormatters: numFmt, keyboardType: TextInputType.number, onChanged: (_) => setState(() {})))),
        const SizedBox(width: 12),
        SizedBox(width: 80, child: Field(label: tr('TVA %'), child: TextField(controller: _c['tax_rate'], inputFormatters: numFmt))),
        const SizedBox(width: 12),
        SizedBox(
          width: 120,
          child: Field(
            label: tr('Période'),
            child: DropdownButtonFormField<int>(
              initialValue: const [1, 3, 6, 12, 24, 36].contains(_period) ? _period : 12,
              items: [for (final m in const [1, 3, 6, 12, 24, 36]) DropdownMenuItem(value: m, child: Text(tr(hbPeriod(m) == 'mois' ? '1 mois' : (hbPeriod(m) == 'an' ? '1 an' : hbPeriod(m)))))],
              onChanged: (v) => setState(() => _period = v!),
            ),
          ),
        ),
      ]),
      if (sale > 0) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(tr('Marge : ${money(sale - cost, context.read<Session>().currency)} par période (${(100 * (sale - cost) / sale).toStringAsFixed(0)} %)'), style: TextStyle(color: Theme.of(context).hintColor))),
      Row(children: [
        Expanded(child: Field(label: tr('Début'), child: OutlinedButton(onPressed: () => _date(true), child: Text(tr(_start == null ? '—' : dateFr(_start)))))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Échéance'), child: OutlinedButton(onPressed: () => _date(false), child: Text(tr(_expiry == null ? 'Choisir' : dateFr(_expiry)))))),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('État'),
            child: DropdownButtonFormField<String>(
              initialValue: _status,
              items: [DropdownMenuItem(value: 'active', child: Text(tr('Actif'))), DropdownMenuItem(value: 'suspended', child: Text(tr('Suspendu'))), DropdownMenuItem(value: 'cancelled', child: Text(tr('Résilié')))],
              onChanged: (v) => setState(() => _status = v!),
            ),
          ),
        ),
      ]),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Facture de renouvellement automatique')), subtitle: Text(tr('Émise avant l\'échéance, regroupée par client ; l\'échéance avance à l\'encaissement.')), value: _auto, onChanged: (v) => setState(() => _auto = v)),
      if (_auto) Field(label: tr('Facturer combien de jours avant l\'échéance'), child: TextField(controller: _c['renew_days_before'], keyboardType: TextInputType.number)),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Rappels au client')), subtitle: Text(tr('E-mail et WhatsApp aux paliers réglés (ex. J-30, J-7, J-1).')), value: _reminders, onChanged: (v) => setState(() => _reminders = v)),
      Field(label: tr('Détails techniques'), child: TextField(controller: _c['details'], maxLines: 2, decoration: InputDecoration(hintText: tr('Serveur, DNS, compte client… (pas de mot de passe)')))),
      Field(label: tr('Notes'), child: TextField(controller: _c['notes'], maxLines: 2)),
      Row(children: [
        if (widget.service != null)
          TextButton(
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer ce service ?'), tr('Possible seulement sans historique de renouvellement (sinon, résiliez-le).'), ok: tr('Supprimer'), danger: true)) return;
              if (!context.mounted) return;
              await context.read<SyncEngine>().remove('hb_services', '${widget.service!['id']}');
              if (context.mounted) Navigator.pop(context);
            },
            child: Text(tr('Supprimer')),
          ),
        const Spacer(),
        FilledButton(onPressed: _save, child: Text(tr('Enregistrer'))),
      ]),
    ]);
  }
}
