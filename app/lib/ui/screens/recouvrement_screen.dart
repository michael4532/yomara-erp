import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/report_charts.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';
import '../widgets/pdf_viewer.dart';

Color recoveryColor(String s) => switch (s) {
      'open' => YColors.warning,
      'promise' => YColors.blue,
      'plan' => const Color(0xFF0FA3B1),
      'notice' => const Color(0xFFE67E22),
      'bailiff' || 'litigation' => YColors.danger,
      'recovered' => YColors.success,
      _ => YColors.muted,
    };

IconData _actionIcon(String t) => switch (t) {
      'call' => Icons.call_outlined,
      'visit' => Icons.directions_walk,
      'email' => Icons.mail_outline,
      'sms' => Icons.sms_outlined,
      'whatsapp' => Icons.chat_outlined,
      'letter' => Icons.markunread_mailbox_outlined,
      'promise' => Icons.handshake_outlined,
      'payment' => Icons.payments_outlined,
      'plan' => Icons.calendar_month_outlined,
      'notice' => Icons.gavel_outlined,
      'bailiff' => Icons.account_balance_outlined,
      'litigation' => Icons.balance,
      'fees' => Icons.receipt_outlined,
      'write_off' => Icons.money_off,
      'broken' => Icons.heart_broken_outlined,
      'close' => Icons.task_alt,
      _ => Icons.sticky_note_2_outlined,
    };

Future<Map<String, dynamic>> _meta(BuildContext context) async => asMap((await context.read<Session>().api.get('relances/recovery/meta'))['data']);

// ---------------------------------------------------------------------------------------------- tableau de bord

class RecoveryDashboard extends StatelessWidget {
  const RecoveryDashboard({super.key});

  @override
  Widget build(BuildContext context) {
    return FutureBuilder(
      future: context.read<Session>().api.get('relances/recovery/dashboard'),
      builder: (c, snap) {
        if (snap.hasError) return OnlineOnlyNotice(error: snap.error!, onRetry: () {});
        if (!snap.hasData) return const LoadingSkeleton();
        final d = asMap((snap.data as Map)['data']);
        final k = asMap(d['kpis']);
        final cur = context.watch<Session>().currency;
        final hint = Theme.of(c).hintColor;
        Widget kpi(String l, String v, IconData ic, Color col, {String? sub}) => KpiBox(
              width: 250,
              child: StatTile(label: l, value: v, detail: sub, icon: ic, color: col),
            );
        final months = List<Map>.from(d['months'] as List);
        final chart = ChartData([for (final m in months) DateFormat('MMM yy', I18n.dateLocale).format(DateTime.parse('${m['month']}-01'))], [ChartSeries('Recouvré', [for (final m in months) toDouble(m['recovered'])])], 'money');
        final aging = List<Map>.from(d['aging'] as List);
        return ListView(padding: const EdgeInsets.all(16), children: [
          if (toDouble(k['candidates']) > 0)
            Card(
              color: YColors.warning.withValues(alpha: .08),
              child: ListTile(
                leading: const Icon(Icons.priority_high, color: YColors.warning),
                title: Text(tr('${k['candidates']} client(s) à mettre en recouvrement : ${money(toDouble(k['candidates_amount']), cur)} impayés depuis longtemps')),
                subtitle: Text(tr('Ouvrez un dossier depuis l\'onglet « Dossiers de recouvrement ».')),
              ),
            ),
          KpiWrap(children: [
            kpi('Dossiers ouverts', '${k['open']}', Icons.folder_open_outlined, YColors.warning, sub: '${money(toDouble(k['open_amount']), cur)} à recouvrer'),
            kpi('Recouvré sur la période', money(toDouble(k['recovered']), cur), Icons.savings_outlined, YColors.success),
            kpi('Taux de recouvrement', k['rate'] == null ? '—' : '${k['rate']} %', Icons.percent, YColors.blue, sub: 'tous dossiers confondus'),
            kpi('Promesses', '${k['promises_kept']} tenue(s) / ${k['promises']}', Icons.handshake_outlined, const Color(0xFF0FA3B1), sub: '${k['promises_broken']} non tenue(s)'),
            kpi('À surveiller cette semaine', '${k['due_week']}', Icons.event_outlined, YColors.night, sub: 'promesses et échéances'),
            kpi('Actions en retard', '${k['late_actions']}', Icons.alarm, YColors.danger),
            kpi('Passé en perte', money(toDouble(k['written_off']), cur), Icons.money_off, YColors.muted),
            kpi('Frais engagés', money(toDouble(k['fees']), cur), Icons.receipt_outlined, YColors.muted, sub: 'huissier, procédure'),
          ]),
          const SizedBox(height: 12),
          SectionCard(title: tr('Montants recouvrés, 12 derniers mois'), child: SizedBox(height: 220, child: CartesianChart(data: chart, currency: cur, line: false))),
          const SizedBox(height: 12),
          Wrap(spacing: 12, runSpacing: 12, children: [
            SizedBox(
              width: 420,
              child: SectionCard(
                title: tr('Ancienneté des sommes à recouvrer'),
                child: Column(children: [
                  for (final a in aging)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 5),
                      child: Row(children: [Expanded(child: Text(tr(a['bucket'] == '365+' ? 'Plus d\'un an' : '${a['bucket']} jours'))), Text(tr(money(toDouble(a['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w700))]),
                    ),
                ]),
              ),
            ),
            SizedBox(
              width: 420,
              child: SectionCard(
                title: tr('Par étape'),
                child: Column(children: [
                  for (final s in List<Map>.from(d['by_status'] as List))
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(children: [
                        StatusChip('${s['label']}', color: recoveryColor('${s['status']}')),
                        const Spacer(),
                        Text(tr('${s['count']} · ${money(toDouble(s['amount']), cur)}')),
                      ]),
                    ),
                  if ((d['by_status'] as List).isEmpty) Text(tr('Aucun dossier.'), style: TextStyle(color: hint)),
                ]),
              ),
            ),
            SizedBox(
              width: 420,
              child: SectionCard(
                title: tr('Plus gros dossiers ouverts'),
                child: Column(children: [
                  for (final x in List<Map>.from(d['top'] as List))
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      onTap: () => openRecoveryCase(context, x['id'] as int),
                      title: Text(tr('${x['partner']}'), overflow: TextOverflow.ellipsis),
                      subtitle: Text(tr('${x['number']} · ${x['status_label']}${x['assignee'] != null ? ' · ${x['assignee']}' : ''}')),
                      trailing: Text(tr(money(toDouble(x['balance']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                    ),
                  if ((d['top'] as List).isEmpty) Text(tr('Aucun dossier ouvert.'), style: TextStyle(color: hint)),
                ]),
              ),
            ),
            SizedBox(
              width: 420,
              child: SectionCard(
                title: tr('Par chargé de recouvrement'),
                child: Column(children: [
                  for (final x in List<Map>.from(d['by_assignee'] as List))
                    Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(children: [Expanded(child: Text(tr('${x['assignee']} (${x['count']})'))), Text(tr(money(toDouble(x['amount']), cur)))])),
                  if ((d['by_assignee'] as List).isEmpty) Text('—', style: TextStyle(color: hint)),
                ]),
              ),
            ),
          ]),
        ]);
      },
    );
  }
}

// ---------------------------------------------------------------------------------------------- liste

class RecoveryCasesTab extends StatefulWidget {
  const RecoveryCasesTab({super.key});

  @override
  State<RecoveryCasesTab> createState() => _RecoveryCasesTabState();
}

class _RecoveryCasesTabState extends State<RecoveryCasesTab> {
  String _status = 'open';
  bool _mine = false;
  String _q = '';
  Map<String, String?> _f = {};
  bool _busy = false;
  List<Map<String, dynamic>>? _rows;
  List<Map<String, dynamic>> _cand = [];
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final api = context.read<Session>().api;
      final r = await api.get('relances/cases', query: {'status': _status.isEmpty ? 'all' : _status, if (_mine) 'assignee': 'me'});
      final c = _status == 'open' ? await api.get('relances/recovery/candidates') : {'data': []};
      if (mounted) {
        setState(() => (_rows = [for (final x in r['data'] as List) asMap(x)], _cand = [for (final x in c['data'] as List) asMap(x)], _error = null, _busy = false));
      }
    } catch (e) {
      if (mounted) setState(() => (_error = e, _busy = false));
    }
  }

  Future<void> _open(Map<String, dynamic> cand) async {
    final meta = await _meta(context);
    if (!mounted) return;
    final users = List<Map>.from(meta['users'] as List);
    int? assignee = context.read<Session>().user['id'] as int?;
    final note = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Ouvrir un dossier : ${cand['name']}')),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(tr('${cand['invoices']} facture(s) impayée(s), ${money(toDouble(cand['amount']), context.read<Session>().currency)}, la plus ancienne en retard de ${cand['oldest_days']} jours.')),
              const SizedBox(height: 8),
              Text(tr('Les relances automatiques s\'arrêtent pour ce client : le dossier prend le relais.'), style: TextStyle(fontSize: 12.5, color: YColors.muted)),
              DropdownButtonFormField<int>(
                initialValue: users.any((u) => u['id'] == assignee) ? assignee : null,
                decoration: InputDecoration(labelText: tr('Chargé du dossier')),
                items: [for (final u in users) DropdownMenuItem(value: u['id'] as int, child: Text(tr('${u['name']}')))],
                onChanged: (v) => set(() => assignee = v),
              ),
              TextField(controller: note, maxLines: 2, decoration: InputDecoration(labelText: tr('Note (contexte, interlocuteur…)'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Ouvrir le dossier')))],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = await context.read<Session>().api.post('relances/cases', {'partner_id': cand['partner_id'], 'assigned_to': assignee, 'note': note.text.trim().isEmpty ? null : note.text.trim()});
      if (!mounted) return;
      await openRecoveryCase(context, r['data']['id'] as int);
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _rows == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    final rows = _rows;
    if (rows == null) return const LoadingSkeleton();
    final cur = context.watch<Session>().currency;
    final manage = context.watch<Session>().can('relances.manage');
    final hint = Theme.of(context).hintColor;
    final shown = rows.where((r) {
      if (_q.isNotEmpty && !'${r['partner']} ${r['number']} ${r['aux_code']}'.toLowerCase().contains(_q.toLowerCase())) return false;
      final st = _f['stage'], asg = _f['assignee'], late = _f['late'], fu = _f['follow'];
      if (st != null && '${r['status']}' != st) return false;
      if (asg != null && '${r['assignee'] ?? ''}' != asg) return false;
      if (late != null) {
        final d = toDouble(r['oldest_days']);
        final b = d <= 30 ? 'd30' : (d <= 60 ? 'd60' : (d <= 90 ? 'd90' : 'over90'));
        if (b != late) return false;
      }
      if (fu == 'late_action' && r['late_action'] != true) return false;
      if (fu == 'promise' && r['promise'] == null) return false;
      if (fu == 'plan' && r['next_installment'] == null) return false;
      return true;
    }).toList();
    void reload() {
      setState(() => _busy = true);
      _load();
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          padding: const EdgeInsets.only(bottom: 12),
          search: _q,
          searchHint: 'Client, n° de dossier…',
          onSearch: (v) => setState(() => _q = v),
          statuses: const {'': 'Tous', 'open': 'En cours', 'closed': 'Clos'},
          status: _status,
          onStatus: (v) {
            _status = v;
            reload();
          },
          filters: [
            FilterSpec('stage', 'Étape', filterOptions(rows, 'status', label: (r) => '${r['status_label'] ?? r['status']}'), icon: Icons.flag_outlined),
            FilterSpec('assignee', 'Chargé du dossier', filterOptions(rows, 'assignee'), icon: Icons.person_outline),
            const FilterSpec('late', 'Retard', {'d30': '1-30 j', 'd60': '31-60 j', 'd90': '61-90 j', 'over90': '+ 90 j'}, icon: Icons.schedule),
            const FilterSpec('follow', 'Suivi', {'late_action': 'Action en retard', 'promise': 'Promesse de paiement', 'plan': 'Échéancier en cours'}, icon: Icons.event_note_outlined),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          count: shown.length,
          countLabel: 'dossier',
          onReset: () {
            _q = '';
            _f = {};
            _mine = false;
            _status = 'open';
            reload();
          },
          trailing: [
            FilterChip(label: Text(tr('Mes dossiers')), selected: _mine, onSelected: (v) {
              _mine = v;
              reload();
            }),
          ],
        ),
        if (_busy) const Padding(padding: EdgeInsets.only(bottom: 8), child: LinearProgressIndicator(minHeight: 2)),
        if (_cand.isNotEmpty && manage) ...[
          Text(tr('À METTRE EN RECOUVREMENT'), style: TextStyle(fontWeight: FontWeight.w800, color: hint, letterSpacing: 1, fontSize: 12)),
          const SizedBox(height: 6),
          for (final c in _cand.take(8))
            Card(
              color: YColors.warning.withValues(alpha: .05),
              child: ListTile(
                leading: InitialsAvatar('${c['name']}', size: 38),
                title: Text(tr('${c['name']}')),
                subtitle: Text(tr('${c['invoices']} facture(s) · plus ancienne en retard de ${c['oldest_days']} jours${c['phone'] != null ? ' · ${c['phone']}' : ''}')),
                trailing: Wrap(spacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  Text(tr(money(toDouble(c['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                  FilledButton.tonal(onPressed: () => _open(c), child: Text(tr('Ouvrir un dossier'))),
                ]),
              ),
            ),
          const SizedBox(height: 12),
        ],
        if (shown.isEmpty && rows.isNotEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
        else if (shown.isEmpty) EmptyState(icon: Icons.gavel_outlined, title: tr('Aucun dossier'), message: tr('Quand les relances ne suffisent plus, ouvrez un dossier : appels, promesses, échéancier, mise en demeure, huissier.')),
        for (final (i, r) in shown.indexed)
          AnimatedFadeIn(
            index: i % 10,
            child: Card(
              child: InkWell(
                borderRadius: BorderRadius.circular(16),
                onTap: () async {
                  await openRecoveryCase(context, r['id'] as int);
                  await _load();
                },
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(children: [
                    CircleAvatar(backgroundColor: recoveryColor('${r['status']}').withValues(alpha: .12), child: Icon(Icons.gavel_outlined, color: recoveryColor('${r['status']}'))),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${r['partner']} · ${r['number']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(
                          tr([
                            '${r['invoices_count']} facture(s), ${r['oldest_days']} j de retard',
                            if (r['assignee'] != null) '${r['assignee']}',
                            if (r['promise'] != null) 'promesse ${money(toDouble(r['promise']['amount']), cur)} le ${dateFr(r['promise']['date'])}',
                            if (r['next_installment'] != null) 'échéance du ${dateFr(r['next_installment']['due_date'])}',
                            if (r['next_action_at'] != null) 'prochaine action ${dateFr(r['next_action_at'])}',
                          ].join(' · ')),
                          style: TextStyle(color: r['late_action'] == true ? YColors.danger : hint, fontSize: 12.5),
                        ),
                        if (toDouble(r['amount_initial']) > 0) ...[
                          const SizedBox(height: 6),
                          ClipRRect(
                            borderRadius: BorderRadius.circular(4),
                            child: LinearProgressIndicator(value: (toDouble(r['rate']) / 100).clamp(0, 1), minHeight: 5, color: YColors.success, backgroundColor: YColors.muted.withValues(alpha: .15)),
                          ),
                        ],
                      ]),
                    ),
                    const SizedBox(width: 12),
                    Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(tr(money(toDouble(r['balance']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                      Text(tr('recouvré ${r['rate']} %'), style: TextStyle(color: hint, fontSize: 12)),
                      const SizedBox(height: 4),
                      StatusChip('${r['status_label']}', color: recoveryColor('${r['status']}')),
                    ]),
                  ]),
                ),
              ),
            ),
          ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------------------------- dossier

Future<void> openRecoveryCase(BuildContext context, int id) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => _CaseDetail(id: id)));

class _CaseDetail extends StatefulWidget {
  const _CaseDetail({required this.id});

  final int id;

  @override
  State<_CaseDetail> createState() => _CaseDetailState();
}

class _CaseDetailState extends State<_CaseDetail> {
  Map<String, dynamic>? _c;
  Map<String, dynamic>? _meta;
  Object? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final api = context.read<Session>().api;
      final r = await api.get('relances/cases/${widget.id}');
      _meta ??= asMap((await api.get('relances/recovery/meta'))['data']);
      if (mounted) setState(() => (_c = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _post(String path, Map<String, dynamic> body, String done) async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('relances/cases/${widget.id}/$path', body);
      if (mounted) {
        setState(() => _c = asMap(r['data']));
        toast(context, done);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Formulaire d'action : champs selon le type.
  Future<void> _action(String type) async {
    final c = _c!;
    final cur = context.read<Session>().currency;
    final body = TextEditingController();
    final amount = TextEditingController(text: type == 'payment' ? '${toDouble(c['balance']).round()}' : '');
    final ref = TextEditingController();
    final bailiff = TextEditingController(text: '${c['bailiff'] ?? ''}');
    final count = TextEditingController(text: '3');
    final days = TextEditingController(text: '${_meta?['settings']?['notice_days'] ?? 8}');
    String? date = isoDate(DateTime.now().add(Duration(days: type == 'plan' ? 30 : 7)));
    String? next;
    var method = 'mobile_money';
    var every = 'month';
    var send = (c['email'] ?? '').toString().isNotEmpty;
    String? treasury;
    final treasuryList = List<Map>.from(_meta?['treasury'] as List? ?? const []);
    final title = '${asMap(_meta?['actions'])[type] ?? type}';
    final fmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    final ok = await showFormDialog<bool>(context, title: title, builder: (dc) => StatefulBuilder(builder: (dc, set) {
        Future<void> chooseDate(void Function(String) on, String? init) async {
          final d = await pickDate(dc, firstDate: DateTime(2024), lastDate: DateTime(2100), initialDate: DateTime.tryParse(init ?? '') ?? DateTime.now());
          if (d != null) set(() => on(isoDate(d)));
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (type == 'promise' || type == 'payment' || type == 'fees' || type == 'bailiff')
                TextField(controller: amount, inputFormatters: fmt, decoration: InputDecoration(labelText: trn(type == 'bailiff' ? 'Frais d\'huissier (facultatif)' : type == 'promise' ? 'Montant promis' : 'Montant'), suffixText: trn(cur == 'XOF' ? 'F CFA' : cur))),
              if (type == 'promise') OutlinedButton(onPressed: () => chooseDate((v) => date = v, date), child: Text(tr('Payé au plus tard le ${dateFr(date)}'))),
              if (type == 'payment') ...[
                DropdownButtonFormField<String>(
                  initialValue: method,
                  decoration: InputDecoration(labelText: tr('Moyen de paiement')),
                  items: [
                    DropdownMenuItem(value: 'mobile_money', child: Text(tr('Mobile Money'))),
                    DropdownMenuItem(value: 'cash', child: Text(tr('Espèces'))),
                    DropdownMenuItem(value: 'bank_transfer', child: Text(tr('Virement'))),
                    DropdownMenuItem(value: 'cheque', child: Text(tr('Chèque'))),
                    DropdownMenuItem(value: 'other', child: Text(tr('Autre'))),
                  ],
                  onChanged: (v) => set(() => method = v ?? 'cash'),
                ),
                if (treasuryList.isNotEmpty)
                  DropdownButtonFormField<String>(
                    initialValue: treasury,
                    decoration: InputDecoration(labelText: tr('Encaissé sur le compte')),
                    items: [for (final a in treasuryList) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}')))],
                    onChanged: (v) => set(() => treasury = v),
                  ),
                TextField(controller: ref, decoration: InputDecoration(labelText: tr('Référence (n° de transaction, de chèque…)'))),
                Text(tr('Le paiement est enregistré dans les Ventes sur les factures les plus anciennes du dossier.'), style: TextStyle(fontSize: 12, color: Theme.of(dc).hintColor)),
              ],
              if (type == 'plan') ...[
                Text(tr('Reste à recouvrer : ${money(toDouble(c['balance']), cur)}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                Row(children: [
                  Expanded(child: TextField(controller: count, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Nombre d\'échéances')))),
                  const SizedBox(width: 12),
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      initialValue: every,
                      decoration: InputDecoration(labelText: tr('Fréquence')),
                      items: [DropdownMenuItem(value: 'month', child: Text(tr('Mensuelle'))), DropdownMenuItem(value: 'week', child: Text(tr('Hebdomadaire')))],
                      onChanged: (v) => set(() => every = v ?? 'month'),
                    ),
                  ),
                ]),
                OutlinedButton(onPressed: () => chooseDate((v) => date = v, date), child: Text(tr('Première échéance le ${dateFr(date)}'))),
              ],
              if (type == 'notice') ...[
                TextField(controller: days, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Délai accordé (jours)'))),
                SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Envoyer par e-mail${c['email'] != null ? ' à ${c['email']}' : ' (aucun e-mail sur la fiche client)'}')), value: send, onChanged: c['email'] == null ? null : (v) => set(() => send = v)),
                Text(tr('Imprimez aussi la lettre (bouton PDF) pour l\'envoi en recommandé.'), style: TextStyle(fontSize: 12, color: Theme.of(dc).hintColor)),
              ],
              if (type == 'bailiff') TextField(controller: bailiff, decoration: InputDecoration(labelText: tr('Huissier (étude, nom, ville)'))),
              TextField(controller: body, maxLines: 3, decoration: InputDecoration(labelText: trn(['call', 'visit', 'email', 'sms', 'whatsapp', 'letter', 'note'].contains(type) ? 'Ce qui s\'est dit / résultat' : type == 'write_off' ? 'Raison du passage en perte' : 'Commentaire'))),
              if (!['payment', 'write_off'].contains(type))
                OutlinedButton.icon(onPressed: () => chooseDate((v) => next = v, next), icon: const Icon(Icons.event_repeat, size: 18), label: Text(tr(next == null ? 'Prochaine action le… (facultatif)' : 'Prochaine action le ${dateFr(next)}'))),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        );
      }));
    if (ok != true) return;
    await _post('actions/$type', {
      if (body.text.trim().isNotEmpty) 'body': body.text.trim(),
      if (amount.text.trim().isNotEmpty) 'amount': toDouble(amount.text),
      if (type == 'promise') 'promise_date': date,
      if (type == 'payment') ...{'method': method, 'reference': ref.text.trim().isEmpty ? null : ref.text.trim(), 'treasury_account_id': treasury},
      if (type == 'plan') ...{'count': int.tryParse(count.text) ?? 3, 'every': every, 'first_date': date},
      if (type == 'notice') ...{'days': int.tryParse(days.text) ?? 8, 'send': send},
      if (type == 'bailiff') 'bailiff': bailiff.text.trim(),
      'next_action_at': ?next,
    }, '$title enregistré(e).');
  }

  Future<void> _notice() async {
    try {
      final bytes = await context.read<Session>().api.download('relances/cases/${widget.id}/notice');
      await previewPdf(name: 'Mise en demeure ${_c?['number'] ?? ''}', onLayout: (_) async => Uint8List.fromList(bytes));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = _c;
    return Scaffold(
      appBar: AppBar(title: Text(tr(c == null ? 'Dossier de recouvrement' : '${c['number']} · ${c['partner']}')), actions: [
        if (c != null) IconButton(tooltip: tr('Mise en demeure (PDF)'), onPressed: _notice, icon: const Icon(Icons.picture_as_pdf_outlined)),
        if (c != null) IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
      ]),
      body: _error != null && c == null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : c == null ? const Center(child: CircularProgressIndicator()) : _body(context, c),
    );
  }

  Widget _body(BuildContext context, Map<String, dynamic> c) {
    final cur = context.watch<Session>().currency;
    final manage = context.watch<Session>().can('relances.manage');
    final hint = Theme.of(context).hintColor;
    final open = ['open', 'promise', 'plan', 'notice', 'bailiff', 'litigation'].contains(c['status']);
    final invoices = List<Map>.from(c['invoices'] as List);
    final actions = List<Map>.from(c['actions'] as List);
    final inst = List<Map>.from(c['installments'] as List);
    Widget amount(String l, dynamic v, Color? col) => Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(tr(l), style: TextStyle(color: hint, fontSize: 12)), Text(tr(money(toDouble(v), cur)), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18, color: col))]),
        );
    return ListView(padding: const EdgeInsets.all(16), children: [
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              StatusChip('${c['status_label']}', color: recoveryColor('${c['status']}')),
              const SizedBox(width: 8),
              Expanded(child: Text(tr([if (c['aux_code'] != null) '${c['aux_code']}', if (c['phone'] != null) '${c['phone']}', if (c['email'] != null) '${c['email']}'].join(' · ')), style: TextStyle(color: hint))),
              Text(tr('Ouvert le ${dateFr(c['opened_at'])}${c['assignee'] != null ? ' · ${c['assignee']}' : ''}'), style: TextStyle(color: hint)),
            ]),
            const Divider(height: 24),
            Row(children: [
              amount('Montant initial', c['amount_initial'], null),
              amount('Recouvré', c['recovered'], YColors.success),
              amount('Reste à recouvrer', c['balance'], toDouble(c['balance']) > 0 ? YColors.danger : null),
              if (toDouble(c['fees']) > 0) amount('Frais engagés', c['fees'], hint),
              if (toDouble(c['written_off']) > 0) amount('Passé en perte', c['written_off'], hint),
            ]),
            const SizedBox(height: 8),
            ClipRRect(borderRadius: BorderRadius.circular(4), child: LinearProgressIndicator(value: (toDouble(c['rate']) / 100).clamp(0, 1), minHeight: 6, color: YColors.success, backgroundColor: YColors.muted.withValues(alpha: .15))),
            if (c['notice_deadline'] != null && c['status'] == 'notice') Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Mise en demeure : délai jusqu\'au ${dateFr(c['notice_deadline'])}'), style: const TextStyle(fontWeight: FontWeight.w700))),
            if (c['bailiff'] != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('Huissier : ${c['bailiff']}'))),
            if (c['note'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('${c['note']}'), style: TextStyle(color: hint))),
          ]),
        ),
      ),
      if (open && manage) ...[
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: [
          FilledButton.icon(onPressed: _busy ? null : () => _action('payment'), icon: const Icon(Icons.payments_outlined), label: Text(tr('Encaissement'))),
          for (final t in ['call', 'visit', 'promise', 'plan', 'notice', 'bailiff'])
            OutlinedButton.icon(onPressed: _busy ? null : () => _action(t), icon: Icon(_actionIcon(t), size: 18), label: Text(tr('${asMap(_meta?['actions'])[t] ?? t}'))),
          PopupMenuButton<String>(
            tooltip: tr('Autres actions'),
            onSelected: (v) async {
              if (v == 'close') {
                final ctl = TextEditingController();
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (dc) => YAlertDialog(
                    title: Text(tr('Clôturer le dossier')),
                    content: TextField(controller: ctl, decoration: InputDecoration(labelText: tr('Raison (accord trouvé, litige commercial, avoir…)'))),
                    actions: [TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Clôturer')))],
                  ),
                );
                if (ok == true) await _post('close', {'note': ctl.text.trim()}, 'Dossier clôturé.');
              } else {
                await _action(v);
              }
            },
            itemBuilder: (_) => [
              for (final t in ['email', 'sms', 'whatsapp', 'letter', 'note', 'fees', 'litigation', 'write_off']) PopupMenuItem(value: t, child: Text(tr('${asMap(_meta?['actions'])[t] ?? t}'))),
              PopupMenuItem(value: 'close', child: Text(tr('Clôturer le dossier'))),
            ],
            child: const Padding(padding: EdgeInsets.all(10), child: Icon(Icons.more_horiz)),
          ),
        ]),
      ],
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Factures du dossier'),
        child: Column(children: [
          for (final i in invoices)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 5),
              child: Row(children: [
                SizedBox(width: 140, child: Text(tr('${i['number']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                Expanded(child: Text(tr('du ${dateFr(i['date'])}${i['due_date'] != null ? ', échue le ${dateFr(i['due_date'])}' : ''} · ${i['days']} j de retard'), style: TextStyle(color: hint, fontSize: 12.5))),
                SizedBox(width: 120, child: Text(tr(money(toDouble(i['total']), cur)), textAlign: TextAlign.right, style: TextStyle(color: hint))),
                SizedBox(width: 130, child: Text(tr(money(toDouble(i['balance']), cur)), textAlign: TextAlign.right, style: TextStyle(fontWeight: FontWeight.w800, color: toDouble(i['balance']) > 0 ? YColors.danger : YColors.success))),
              ]),
            ),
        ]),
      ),
      if (inst.isNotEmpty) ...[
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Échéancier négocié'),
          child: Column(children: [
            for (final i in inst)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(children: [
                  Icon(toDouble(i['paid']) + 0.5 >= toDouble(i['amount']) ? Icons.check_circle : i['late'] == true ? Icons.error_outline : Icons.radio_button_unchecked,
                      size: 18, color: toDouble(i['paid']) + 0.5 >= toDouble(i['amount']) ? YColors.success : i['late'] == true ? YColors.danger : hint),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr(dateFr(i['due_date'])))),
                  Text(tr('${money(toDouble(i['paid']), cur)} / ${money(toDouble(i['amount']), cur)}')),
                ]),
              ),
          ]),
        ),
      ],
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Historique des actions'),
        child: Column(children: [
          for (final a in actions)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: CircleAvatar(radius: 16, backgroundColor: YColors.blue.withValues(alpha: .1), child: Icon(_actionIcon('${a['type']}'), size: 16, color: YColors.blue)),
              title: Row(children: [
                Flexible(child: Text(tr('${a['label']}${a['amount'] != null && toDouble(a['amount']) > 0 ? ' · ${money(toDouble(a['amount']), cur)}' : ''}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                if (a['promise_status'] != null) ...[
                  const SizedBox(width: 6),
                  StatusChip(switch ('${a['promise_status']}') { 'kept' => 'Tenue', 'broken' => 'Non tenue', _ => 'pour le ${dateFr(a['promise_date'])}' },
                      color: switch ('${a['promise_status']}') { 'kept' => YColors.success, 'broken' => YColors.danger, _ => YColors.blue }),
                ],
              ]),
              subtitle: a['body'] == null ? null : Text(tr('${a['body']}')),
              trailing: Text(tr('${dateFr(a['date'])}${a['user'] != null ? '\n${a['user']}' : ''}'), textAlign: TextAlign.right, style: TextStyle(color: hint, fontSize: 12)),
            ),
        ]),
      ),
    ]);
  }
}
