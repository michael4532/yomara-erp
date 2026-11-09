import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../pdf/payslip_pdf.dart';
import '../../pdf/report_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'paie_profile_editor.dart';
import 'rh_screen.dart' show maritalStatuses, salaryPaymentMethods;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const runStatuses = {'draft': 'Brouillon', 'validated': 'Validée', 'paid': 'Payée'};

Color runStatusColor(String? s) => switch (s) {
  'paid' => YColors.success,
  'validated' => YColors.blue,
  _ => YColors.warning,
};

String _m(BuildContext c, num? v) => money(v, c.read<Session>().currency);

/// Paie multi-pays (nécessite la connexion).
class PaieScreen extends StatefulWidget {
  const PaieScreen({super.key});

  @override
  State<PaieScreen> createState() => _PaieScreenState();
}

class _PaieScreenState extends State<PaieScreen> with SingleTickerProviderStateMixin {
  late final bool _manage = context.read<Session>().can('paie.manage');
  late final TabController _tabs = TabController(length: _manage ? 3 : 2, vsync: this);
  final _runsKey = GlobalKey<_RunsTabState>();

  Future<void> _newRun() async {
    final now = DateTime.now();
    final choices = [for (var i = -1; i <= 2; i++) DateTime(now.year, now.month - i)];
    final picked = await showAdaptiveSheet<DateTime>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              title: Text(tr('Préparer la paie de…'), style: TextStyle(fontWeight: FontWeight.w700)),
            ),
            for (final d in choices) ListTile(leading: const Icon(Icons.calendar_month), title: Text(tr(_cap(periodLabel(_period(d))))), onTap: () => Navigator.pop(c, d)),
          ],
        ),
      ),
    );
    if (picked == null || !mounted) return;
    try {
      final r = await context.read<Session>().api.post('paie/runs', {'period': _period(picked)});
      _tabs.animateTo(0);
      _runsKey.currentState?.open(Map<String, dynamic>.from(r['run']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }


  /// Paie en un clic : tous les salariés avec leur salaire, puis validation et paiement.
  Future<void> _quickPay() async {
    final api = context.read<Session>().api;
    final cur = context.read<Session>().currency;
    var period = _period(DateTime.now());
    Map<String, dynamic>? data;
    Object? error;
    var method = 'bank_transfer';
    var busy = false;
    Future<void> load(void Function(void Function()) setS) async {
      setS(() => (data = null, error = null));
      try {
        final r = await api.post('paie/quick/preview', {'period': period});
        setS(() => data = Map<String, dynamic>.from(r));
      } catch (e) {
        setS(() => error = e);
      }
    }

    var started = false;
    await showFormDialog(context, title: 'Payer les salaires du mois', maxWidth: 760, builder: (dc) {
      return StatefulBuilder(builder: (dc, setS) {
        if (!started) {
          started = true;
          load(setS);
        }
        final now = DateTime.now();
        final slips = [for (final p in (data?['payslips'] as List? ?? const [])) Map<String, dynamic>.from(p)];
        final run = data?['run'] == null ? null : Map<String, dynamic>.from(data!['run']);
        final totals = run?['totals'] == null ? <String, dynamic>{} : Map<String, dynamic>.from(run!['totals']);
        final paid = run?['status'] == 'paid';
        final undeclared = slips.where((p) => p['declared'] == false).length;
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (var i = 1; i >= -1; i--)
              ChoiceChip(
                label: Text(tr(_cap(periodLabel(_period(DateTime(now.year, now.month - i)))))),
                selected: period == _period(DateTime(now.year, now.month - i)),
                onSelected: (_) {
                  period = _period(DateTime(now.year, now.month - i));
                  load(setS);
                },
              ),
          ]),
          const SizedBox(height: 14),
          if (error != null)
            Text(errorText(error!), style: const TextStyle(color: YColors.danger))
          else if (data == null)
            const Padding(padding: EdgeInsets.all(30), child: Center(child: CircularProgressIndicator()))
          else ...[
            Wrap(spacing: 10, runSpacing: 10, children: [
              KpiBox(width: 220, child: StatTile(label: 'Salariés payés', value: '${slips.length}', detail: undeclared > 0 ? '$undeclared non déclaré(s), sans retenues' : 'tous déclarés', icon: Icons.groups_2_outlined)),
              KpiBox(width: 220, child: StatTile(label: 'Net à verser', value: money(totals['net'] ?? 0, cur), icon: Icons.payments_outlined, color: YColors.success)),
              KpiBox(width: 220, child: StatTile(label: 'Coût employeur', value: money(totals['cost'] ?? 0, cur), detail: 'charges comprises', icon: Icons.account_balance_outlined, color: const Color(0xFF7C3AED))),
            ]),
            const SizedBox(height: 12),
            Container(
              constraints: const BoxConstraints(maxHeight: 320),
              decoration: BoxDecoration(border: Border.all(color: Theme.of(dc).dividerColor), borderRadius: BorderRadius.circular(12)),
              child: ListView(shrinkWrap: true, children: [
                for (final p in slips)
                  ListTile(
                    dense: true,
                    leading: InitialsAvatar('${p['name']}', size: 32),
                    title: Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text(tr([p['job_title'], p['department'], if (p['declared'] == false) 'non déclaré : payé sans retenues', if ((p['days'] ?? 30) < 30) '${number(p['days'], decimals: 1)} j'].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
                    trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(tr(money(p['net'], cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(tr('brut ${money(p['gross'], cur)}'), style: TextStyle(fontSize: 11.5, color: Theme.of(dc).hintColor)),
                    ]),
                  ),
              ]),
            ),
            const SizedBox(height: 12),
            if (paid)
              Text(tr('Les salaires de ce mois sont déjà payés${run?['payment_date'] != null ? ' (le ${dateFr(run!['payment_date'])})' : ''}.'), style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w600))
            else ...[
              Text(tr('Les bulletins reprennent le salaire de chaque fiche, les primes fixes et l\'ancienneté. Pour modifier un bulletin (absences, primes du mois), ouvrez la paie du mois avant de payer.'),
                  style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12.5)),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                for (final m in const [('bank_transfer', 'Virement'), ('mobile_money', 'Mobile Money'), ('cash', 'Espèces'), ('cheque', 'Chèque')])
                  ChoiceChip(label: Text(tr(m.$2)), selected: method == m.$1, onSelected: (_) => setS(() => method = m.$1)),
              ]),
              const SizedBox(height: 14),
              Row(children: [
                TextButton(
                  onPressed: () {
                    Navigator.pop(dc);
                    _tabs.animateTo(0);
                    if (run != null) _runsKey.currentState?.open(run);
                  },
                  child: Text(tr('Ouvrir la paie du mois')),
                ),
                const Spacer(),
                FilledButton.icon(
                  onPressed: busy || slips.isEmpty
                      ? null
                      : () async {
                          setS(() => busy = true);
                          try {
                            await api.post('paie/quick/pay', {'period': period, 'method': method});
                            if (dc.mounted) Navigator.pop(dc);
                            if (mounted) toast(context, tr('Salaires payés : ${slips.length} bulletin(s) créés et paiement enregistré.'));
                            _runsKey.currentState?.load();
                          } catch (e) {
                            setS(() => busy = false);
                            if (dc.mounted) toast(dc, errorText(e), error: true);
                          }
                        },
                  icon: const Icon(Icons.check, size: 18),
                  label: Text(tr(busy ? 'Paiement…' : 'Valider et payer ${money(totals['net'] ?? 0, cur)}')),
                ),
              ]),
            ],
          ],
        ]);
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        PageHeader(
          title: tr('Paie'),
          subtitle: tr('Bulletins multi-pays · Côte d\'Ivoire, UEMOA, CEMAC ou profil libre'),
          actions: [
            if (_manage) OutlinedButton.icon(onPressed: _newRun, icon: const Icon(Icons.add), label: Text(tr('Nouvelle paie'))),
            if (_manage) FilledButton.icon(onPressed: _quickPay, icon: const Icon(Icons.payments_outlined), label: Text(tr('Payer les salaires du mois'))),
          ],
        ),
        TabBar(
          controller: _tabs,
          isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
          tabAlignment: TabAlignment.start,
          tabs: [
            Tab(text: tr('Paies')),
            Tab(text: tr('Simulateur')),
            if (_manage) Tab(text: tr('Paramètres')),
          ],
        ),
        Expanded(
          child: TabBarView(
            controller: _tabs,
            children: [
              _RunsTab(key: _runsKey, manage: _manage),
              const _SimulatorTab(),
              if (_manage) const _SettingsTab(),
            ],
          ),
        ),
      ],
    );
  }
}

String _period(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}';
String _cap(String s) => s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);

// ---------------------------------------------------------------------------
// Lots de paie
// ---------------------------------------------------------------------------

class _RunsTab extends StatefulWidget {
  const _RunsTab({super.key, required this.manage});

  final bool manage;

  @override
  State<_RunsTab> createState() => _RunsTabState();
}

class _RunsTabState extends State<_RunsTab> {
  List<Map<String, dynamic>>? _runs;
  Object? _error;
  Map<String, dynamic>? _open;
  String _status = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await context.read<Session>().api.get('paie/runs');
      if (mounted) setState(() => (_runs = List<Map<String, dynamic>>.from(r['runs']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void open(Map<String, dynamic> run) => setState(() => _open = run);

  @override
  Widget build(BuildContext context) {
    if (_open != null) {
      return _RunDetail(
        key: ValueKey(_open!['id']),
        runId: _open!['id'] as int,
        manage: widget.manage,
        onClose: () {
          setState(() => _open = null);
          load();
        },
      );
    }
    if (_error != null && _runs == null) return OnlineOnlyNotice(error: _error!, onRetry: load);
    if (_runs == null) return const Center(child: CircularProgressIndicator());
    if (_runs!.isEmpty) {
      return EmptyState(
        icon: Icons.payments_outlined,
        title: tr('Aucune paie'),
        message: tr('Créez vos salariés dans Ressources humaines, puis « Nouvelle paie » : les bulletins du mois sont préparés automatiquement.'),
      );
    }
    final all = _runs!;
    bool inPeriod(Map<String, dynamic> r) {
      if (_period == null) return true;
      final m = DateTime.tryParse('${r['period']}-01');
      if (m == null) return false;
      return !m.isAfter(_period!.end) && !DateTime(m.year, m.month + 1, 0).isBefore(_period!.start);
    }

    bool base(Map<String, dynamic> r) =>
        (_q.isEmpty || '${periodLabel('${r['period']}')} ${r['period']}'.toLowerCase().contains(_q.toLowerCase())) &&
        (_f['year'] == null || '${r['period']}'.startsWith('${_f['year']}')) &&
        inPeriod(r);
    bool st(Map<String, dynamic> r, String k) => k.isEmpty || r['status'] == k;
    final runs = all.where((r) => base(r) && st(r, _status)).toList();
    final years = <String, String>{for (final r in all) if ('${r['period']}'.length >= 4) '${r['period']}'.substring(0, 4): '${r['period']}'.substring(0, 4)};
    return Column(children: [
      const SizedBox(height: 16),
      FilterBar(
        onSearch: (v) => setState(() => _q = v),
        search: _q,
        searchHint: 'Mois, année…',
        statuses: const {'': 'Toutes', ...runStatuses},
        status: _status,
        onStatus: (v) => setState(() => _status = v),
        statusCounts: {for (final k in ['', ...runStatuses.keys]) k: all.where((r) => base(r) && st(r, k)).length},
        filters: [FilterSpec('year', 'Année', Map.fromEntries(years.entries.toList()..sort((a, b) => b.key.compareTo(a.key))), icon: Icons.calendar_today_outlined)],
        values: _f,
        onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
        showPeriod: true,
        period: _period,
        onPeriod: (p) => setState(() => _period = p),
        count: runs.length,
        countLabel: 'paie',
        onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
      ),
      Expanded(
        child: runs.isEmpty
            ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune paie'), message: tr('Aucune paie ne correspond aux filtres.'))
            : ResponsiveList<Map<String, dynamic>>(
                  items: runs,
                  columns: const ['Période', 'Statut', 'Salariés', 'Brut', 'Net à payer', 'Coût employeur'],
                  cells: (r) => [
                    Text(tr(_cap(periodLabel('${r['period']}'))), style: const TextStyle(fontWeight: FontWeight.w600)),
                    StatusChip(runStatuses[r['status']] ?? '${r['status']}', color: runStatusColor(r['status'])),
                    Text(tr('${r['totals']?['headcount'] ?? 0}')),
                    Text(tr(_m(context, r['totals']?['gross']))),
                    Text(tr(_m(context, r['totals']?['net']))),
                    Text(tr(_m(context, r['totals']?['cost']))),
                  ],
                  mobileTile: (r) => Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(tr(_cap(periodLabel('${r['period']}'))), style: const TextStyle(fontWeight: FontWeight.w600)),
                            Text(tr('${r['totals']?['headcount'] ?? 0} salarié(s) · net ${_m(context, r['totals']?['net'])}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                          ],
                        ),
                      ),
                      StatusChip(runStatuses[r['status']] ?? '${r['status']}', color: runStatusColor(r['status'])),
                    ],
                  ),
                  onTap: open,
                ),
      ),
    ]);
  }
}

class _RunDetail extends StatefulWidget {
  const _RunDetail({super.key, required this.runId, required this.manage, required this.onClose});

  final int runId;
  final bool manage;
  final VoidCallback onClose;

  @override
  State<_RunDetail> createState() => _RunDetailState();
}

class _RunDetailState extends State<_RunDetail> {
  Map<String, dynamic>? _run;
  List<Map<String, dynamic>> _slips = [];
  Object? _error;
  bool _busy = false;
  String _q = '';
  Map<String, String?> _f = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _apply(dynamic r) {
    setState(() {
      _run = Map<String, dynamic>.from(r['run']);
      _slips = List<Map<String, dynamic>>.from(r['payslips']);
      _error = null;
    });
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('paie/runs/${widget.runId}');
      if (mounted) _apply(r);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _act(Future<dynamic> Function() f, [String? done]) async {
    setState(() => _busy = true);
    try {
      final r = await f();
      if (mounted && r is Map && r['run'] != null) _apply(r);
      if (mounted && (done ?? (r is String ? r : null)) != null) toast(context, done ?? r as String);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pay() async {
    var method = 'bank_transfer';
    var date = DateTime.now();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Payer les salaires')),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(tr('Net à payer : ${_m(context, _run!['totals']['net'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 16),
              DropdownButtonFormField<String>(
                initialValue: method,
                decoration: InputDecoration(labelText: tr('Moyen de paiement')),
                items: [for (final e in salaryPaymentMethods.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => set(() => method = v!),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                icon: const Icon(Icons.event),
                label: Text(tr('Date : ${dateFr(isoDate(date))}')),
                onPressed: () async {
                  final d = await pickDate(c, firstDate: DateTime(2015), lastDate: DateTime(2100), initialDate: date);
                  if (d != null) set(() => date = d);
                },
              ),
              const SizedBox(height: 12),
              Text(tr('L\'écriture de règlement (débit 422, crédit trésorerie) est passée en comptabilité.'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Confirmer le paiement'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await _act(() => context.read<Session>().api.post('paie/runs/${widget.runId}/pay', {'method': method, 'date': isoDate(date)}), 'Paie marquée comme payée.');
  }

  Future<void> _printSlips([List<Map<String, dynamic>>? only]) async {
    final s = context.read<Session>();
    await printPayslips(company: s.company, currency: s.currency, period: '${_run!['period']}', payslips: only ?? _slips);
  }

  Future<void> _printBook() async {
    final s = context.read<Session>();
    String n(num? v) => number(v);
    final t = Map<String, dynamic>.from(_run!['totals']);
    await printReport(
      company: '${s.company['name'] ?? ''}',
      title: tr('Livre de paie'),
      period: _cap(periodLabel('${_run!['period']}')),
      landscape: true,
      headers: const ['Matricule', 'Nom', 'Jours', 'Brut', 'Brut imposable', 'Cotisations', 'CMU', 'Impôts', 'Retenues', 'Net', 'Charges pat.', 'Coût'],
      align: {for (var i = 2; i < 12; i++) i: pw.Alignment.centerRight},
      sections: [
        ReportSection(
          rows: [
            for (final p in _slips)
              [
                '${p['employee']['matricule']}',
                '${p['employee']['full_name']}',
                number(p['days_worked'], decimals: 1),
                n(p['result']['totals']['gross']),
                n(p['result']['totals']['taxable']),
                n(p['result']['totals']['cnps_salarie']),
                n(p['result']['totals']['cmu_salarie']),
                n(p['result']['totals']['its']),
                n(p['result']['totals']['deductions']),
                n(p['result']['totals']['net']),
                n(p['result']['totals']['employer_total']),
                n(p['result']['totals']['cost']),
              ],
          ],
          total: ['', 'TOTAL', '', n(t['gross']), n(t['taxable']), n(t['cnps_salarie']), n(t['cmu_salarie']), n(t['its']), n(t['deductions']), n(t['net']), n(t['employer_total']), n(t['cost'])],
        ),
      ],
    );
  }

  Future<void> _declaration() async {
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.get('paie/runs/${widget.runId}/declaration'));
      if (!mounted) return;
      await showFormDialog(
        context,
        title: tr('Déclarations · ${_cap(periodLabel('${_run!['period']}'))}'),
        builder: (c) => _DeclarationView(data: d),
      );
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _run == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_run == null) return const Center(child: CircularProgressIndicator());
    final status = _run!['status'] as String;
    final t = asMap(_run!['totals']);
    final api = context.read<Session>().api;
    final draft = status == 'draft';

    Widget kpi(String label, num? v, {bool money = true}) => KpiBox(width: 220, child: StatTile(label: label, value: money ? _m(context, v) : '${v ?? 0}'));
    final emps = [for (final p in _slips) asMap(p['employee'])];
    final shownSlips = _slips.where((p) {
      final e = asMap(p['employee']);
      return (_q.isEmpty || '${e['full_name']} ${e['matricule'] ?? ''} ${e['job_title'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
          (_f['job_title'] == null || '${e['job_title'] ?? ''}' == _f['job_title']) &&
          (_f['department'] == null || '${e['department'] ?? ''}' == _f['department']) &&
          (_f['payment_method'] == null || '${e['payment_method'] ?? ''}' == _f['payment_method']) &&
          (_f['deductions'] == null || (_f['deductions'] == 'yes') == (toDouble(p['result']?['totals']?['deductions']) > 0));
    }).toList();

    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 24),
      children: [
        Row(
          children: [
            IconButton(onPressed: widget.onClose, icon: const Icon(Icons.arrow_back), tooltip: tr('Toutes les paies')),
            const SizedBox(width: 4),
            Text(tr(_cap(periodLabel('${_run!['period']}'))), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(width: 12),
            StatusChip(runStatuses[status] ?? status, color: runStatusColor(status)),
            if (_busy)
              const Padding(
                padding: EdgeInsets.only(left: 12),
                child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
              ),
          ],
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (widget.manage && draft) ...[
              FilledButton.icon(
                onPressed: _busy
                    ? null
                    : () async {
                        if (await confirm(context, tr('Valider la paie ?'), tr('Les bulletins sont figés et l\'écriture de salaires est passée en comptabilité (journal SA).'), ok: tr('Valider'))) {
                          await _act(() => api.post('paie/runs/${widget.runId}/validate'), 'Paie validée.');
                        }
                      },
                icon: const Icon(Icons.verified_outlined),
                label: Text(tr('Valider')),
              ),
              OutlinedButton.icon(
                onPressed: _busy ? null : () => _act(() => api.post('paie/runs/${widget.runId}/refresh'), 'Bulletins recalculés avec les fiches à jour.'),
                icon: const Icon(Icons.refresh),
                label: Text(tr('Recalculer')),
              ),
            ],
            if (widget.manage && status == 'validated') ...[
              FilledButton.icon(onPressed: _busy ? null : _pay, icon: const Icon(Icons.payments_outlined), label: Text(tr('Payer'))),
              OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () async {
                        if (await confirm(
                          context,
                          tr('Rouvrir la paie ?'),
                          tr('L\'écriture comptable est retirée (ou contre-passée si la période est verrouillée) et les bulletins redeviennent modifiables.'),
                          ok: tr('Rouvrir'),
                        )) {
                          await _act(() => api.post('paie/runs/${widget.runId}/reopen'), 'Paie rouverte.');
                        }
                      },
                icon: const Icon(Icons.lock_open),
                label: Text(tr('Rouvrir')),
              ),
            ],
            OutlinedButton.icon(onPressed: _slips.isEmpty ? null : () => _printSlips(), icon: const Icon(Icons.picture_as_pdf_outlined), label: Text(tr('Bulletins'))),
            OutlinedButton.icon(onPressed: _slips.isEmpty ? null : _printBook, icon: const Icon(Icons.menu_book_outlined), label: Text(tr('Livre de paie'))),
            OutlinedButton.icon(onPressed: _declaration, icon: const Icon(Icons.assignment_outlined), label: Text(tr('Déclarations'))),
            OutlinedButton.icon(
              onPressed: () => _act(() async {
                final bytes = await api.download('paie/runs/${widget.runId}/export');
                final where = await saveFile('livre-de-paie-${_run!['period']}.csv', bytes, mime: 'text/csv');
                return 'Export enregistré : $where';
              }).then((_) {}),
              icon: const Icon(Icons.download),
              label: Text(tr('Excel (CSV)')),
            ),
            if (widget.manage && draft)
              TextButton.icon(
                onPressed: () async {
                  if (!await confirm(context, tr('Supprimer cette paie ?'), tr('Les bulletins en brouillon seront supprimés.'), ok: tr('Supprimer'), danger: true)) return;
                  await _act(() => api.delete('paie/runs/${widget.runId}'));
                  widget.onClose();
                },
                icon: const Icon(Icons.delete_outline, color: YColors.danger),
                label: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
              ),
          ],
        ),
        const SizedBox(height: 16),
        KpiWrap(children: [
            kpi('Salariés', t['headcount'] as num?, money: false),
            kpi('Masse salariale brute', t['gross'] as num?),
            kpi('Net à payer', t['net'] as num?),
            kpi('Charges patronales', t['employer_total'] as num?),
            kpi('Coût total employeur', t['cost'] as num?),
          ],
        ),
        const SizedBox(height: 16),
        if (_slips.isNotEmpty)
          FilterBar(
            padding: const EdgeInsets.only(bottom: 12),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Nom, matricule, fonction…',
            filters: [
              FilterSpec('department', 'Service', filterOptions(emps, 'department'), icon: Icons.apartment_outlined),
              FilterSpec('job_title', 'Fonction', filterOptions(emps, 'job_title'), icon: Icons.work_outline),
              FilterSpec('payment_method', 'Mode de paiement', filterOptions(emps, 'payment_method', label: (e) => salaryPaymentMethods[e['payment_method']] ?? '${e['payment_method']}'), icon: Icons.payments_outlined),
              const FilterSpec('deductions', 'Retenues', {'yes': 'Avec retenues', 'no': 'Sans retenue'}, icon: Icons.remove_circle_outline),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            count: shownSlips.length,
            countLabel: 'bulletin',
            onReset: () => setState(() => (_q = '', _f = {})),
          ),
        Card(
          clipBehavior: Clip.antiAlias,
          child: LayoutBuilder(
            builder: (context, box) => SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: ConstrainedBox(
                constraints: BoxConstraints(minWidth: box.maxWidth),
                child: DataTable(
                  showCheckboxColumn: false,
                  columnSpacing: 22,
                  columns: [
                    DataColumn(label: Text(tr('Salarié'))),
                    DataColumn(label: Text(tr('Jours')), numeric: true),
                    DataColumn(label: Text(tr('Brut')), numeric: true),
                    DataColumn(label: Text(tr('Cotisations')), numeric: true),
                    DataColumn(label: Text(tr('Impôts')), numeric: true),
                    DataColumn(label: Text(tr('Retenues')), numeric: true),
                    DataColumn(label: Text(tr('Net à payer')), numeric: true),
                    DataColumn(label: Text(tr('Coût')), numeric: true),
                  ],
                  rows: [
                    for (final p in shownSlips)
                      DataRow(
                        onSelectChanged: (_) async {
                          final changed = await showFormDialog<bool>(
                            context,
                            title: '${p['employee']['full_name']}',
                            builder: (c) => _PayslipView(slip: p, editable: widget.manage && draft, period: '${_run!['period']}'),
                          );
                          if (changed == true) _load();
                        },
                        cells: [
                          DataCell(
                            Row(children: [
                              InitialsAvatar('${p['employee']['full_name']}'),
                              const SizedBox(width: 10),
                              Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(tr('${p['employee']['full_name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                  Text(tr('${p['employee']['matricule']} · ${p['employee']['job_title'] ?? ''}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                                ],
                              ),
                            ]),
                          ),
                          DataCell(Text(tr(number(p['days_worked'], decimals: 1)))),
                          DataCell(Text(tr(number(p['gross'])))),
                          DataCell(Text(tr(number((p['result']['totals']['cnps_salarie'] as num) + (p['result']['totals']['cmu_salarie'] as num))))),
                          DataCell(Text(tr(number(p['result']['totals']['its'])))),
                          DataCell(Text(tr(number(p['result']['totals']['deductions'])))),
                          DataCell(Text(tr(number(p['net'])), style: const TextStyle(fontWeight: FontWeight.w700))),
                          DataCell(Text(tr(number(p['cost'])))),
                        ],
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
        if (_slips.isEmpty) EmptyState(icon: Icons.person_off_outlined, title: tr('Aucun salarié présent sur ce mois')),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Bulletin : détail et saisie des éléments variables
// ---------------------------------------------------------------------------

class _PayslipView extends StatefulWidget {
  const _PayslipView({required this.slip, required this.editable, required this.period});

  final Map<String, dynamic> slip;
  final bool editable;
  final String period;

  @override
  State<_PayslipView> createState() => _PayslipViewState();
}

class _PayslipViewState extends State<_PayslipView> {
  late Map<String, dynamic> _slip = widget.slip;
  late final _days = TextEditingController(text: number(widget.slip['days_worked'], decimals: 1).replaceAll(' ', ''));
  late List<Map<String, dynamic>> _items = [for (final i in (widget.slip['items'] as List? ?? const [])) Map<String, dynamic>.from(i)];
  bool _dirty = false, _changed = false, _busy = false;

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.put('paie/payslips/${_slip['id']}', {
        'days_worked': num.tryParse(_days.text.replaceAll(',', '.')) ?? 30,
        'items': _items.where((i) => '${i['label']}'.trim().isNotEmpty).map((i) => {...i, 'amount': i['amount'] ?? 0}).toList(),
      });
      if (!mounted) return;
      setState(() {
        _slip = Map<String, dynamic>.from(r);
        _items = [for (final i in (_slip['items'] as List? ?? const [])) Map<String, dynamic>.from(i)];
        _dirty = false;
        _changed = true;
      });
      if (mounted) toast(context, tr('Bulletin recalculé.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final r = Map<String, dynamic>.from(_slip['result']);
    final t = Map<String, dynamic>.from(r['totals']);
    final hint = Theme.of(context).hintColor;

    Widget line(String label, num? amount, {bool bold = false, Color? color, String? sub}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr(label), style: TextStyle(fontWeight: bold ? FontWeight.w700 : null)),
                if (sub != null) Text(tr(sub), style: TextStyle(fontSize: 12, color: hint)),
              ],
            ),
          ),
          Text(
            tr(_m(context, amount)),
            style: TextStyle(fontWeight: bold ? FontWeight.w700 : null, color: color),
          ),
        ],
      ),
    );

    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
      shrinkWrap: true,
      children: [
        Text(
          tr('${_slip['number'] ?? ''} · ${number(t['parts'], decimals: 1)} part(s) · ${maritalStatuses[_slip['employee']['marital_status']] ?? ''}, ${_slip['employee']['children_count']} enfant(s)'),
          style: TextStyle(color: hint),
        ),
        const SizedBox(height: 12),
        if (widget.editable) ...[
          Row(
            children: [
              SizedBox(
                width: 140,
                child: Field(
                  label: tr('Jours payés (/30)'),
                  child: TextField(controller: _days, keyboardType: TextInputType.number, onChanged: (_) => setState(() => _dirty = true)),
                ),
              ),
            ],
          ),
          Text(tr('Éléments variables du mois'), style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          for (var i = 0; i < _items.length; i++)
            Padding(
              key: ObjectKey(_items[i]),
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  SizedBox(
                    width: 110,
                    child: DropdownButtonFormField<String>(
                      isExpanded: true,
                      initialValue: _items[i]['kind'] ?? 'gain',
                      isDense: true,
                      items: [
                        DropdownMenuItem(value: 'gain', child: Text(tr('Gain'))),
                        DropdownMenuItem(value: 'deduction', child: Text(tr('Retenue'))),
                      ],
                      onChanged: (v) => setState(() => (_items[i]['kind'] = v, _dirty = true)),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 3,
                    child: TextFormField(
                      initialValue: '${_items[i]['label'] ?? ''}',
                      decoration: InputDecoration(hintText: tr('Libellé'), isDense: true),
                      onChanged: (v) => setState(() {
                        _items[i]['label'] = v;
                        _dirty = true;
                      }),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 2,
                    child: TextFormField(
                      initialValue: _items[i]['amount'] == null ? '' : '${(_items[i]['amount'] as num).round()}',
                      keyboardType: TextInputType.number,
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: InputDecoration(hintText: tr('Montant'), isDense: true),
                      onChanged: (v) => setState(() {
                        _items[i]['amount'] = num.tryParse(v) ?? 0;
                        _dirty = true;
                      }),
                    ),
                  ),
                  if (_items[i]['kind'] != 'deduction')
                    Tooltip(
                      message: tr('Imposable et soumis à cotisations'),
                      child: Checkbox(value: _items[i]['taxable'] != false, onChanged: (v) => setState(() => (_items[i]['taxable'] = v, _items[i]['social'] = v, _dirty = true))),
                    ),
                  IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => setState(() => (_items.removeAt(i), _dirty = true))),
                ],
              ),
            ),
          Wrap(
            spacing: 8,
            children: [
              for (final preset in const [('Heures supplémentaires', 'gain'), ('Prime exceptionnelle', 'gain'), ('Avance sur salaire', 'deduction'), ('Remboursement de prêt', 'deduction')])
                ActionChip(
                  avatar: const Icon(Icons.add, size: 16),
                  label: Text(tr(preset.$1)),
                  onPressed: () => setState(() {
                    _items.add({'label': preset.$1, 'amount': 0, 'kind': preset.$2, 'taxable': true, 'social': true});
                    _dirty = true;
                  }),
                ),
            ],
          ),
          const SizedBox(height: 12),
          if (_dirty) FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.calculate_outlined), label: Text(tr('Recalculer le bulletin'))),
          const Divider(height: 32),
        ],
        for (final g in r['gains'] as List) line('${g['label']}', g['amount'] as num, sub: g['base'] is String ? g['base'] as String : null),
        line('Salaire brut', t['gross'] as num, bold: true),
        const SizedBox(height: 6),
        for (final w in r['withholdings'] as List)
          line(
            '${w['label']}',
            -(w['amount'] as num),
            sub: w['detail'] is Map && w['detail']['brut'] != null
                ? 'Base ${_m(context, (w['base'] ?? t['taxable']) as num)} · barème ${_m(context, w['detail']['brut'] as num)} − réduction ${_m(context, (w['detail']['ricf'] ?? w['detail']['reduction'] ?? 0) as num)}'
                : null,
          ),
        for (final d in r['deductions'] as List) line('${d['label']}', -(d['amount'] as num)),
        const Divider(),
        line('Net à payer', t['net'] as num, bold: true, color: Theme.of(context).colorScheme.primary),
        const SizedBox(height: 10),
        ExpansionTile(
          tilePadding: EdgeInsets.zero,
          title: Text(tr('Charges patronales : ${_m(context, t['employer_total'])} · coût ${_m(context, t['cost'])}')),
          children: [
            for (final c in r['employer'] as List)
              if ((c['amount'] as num) > 0) line('${c['label']}', c['amount'] as num),
          ],
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            OutlinedButton.icon(
              onPressed: _dirty
                  ? null
                  : () {
                      final s = context.read<Session>();
                      printPayslips(company: s.company, currency: s.currency, period: widget.period, payslips: [_slip]);
                    },
              icon: const Icon(Icons.picture_as_pdf_outlined),
              label: Text(tr('Imprimer le bulletin')),
            ),
            const Spacer(),
            TextButton(onPressed: () => Navigator.pop(context, _changed), child: Text(tr('Fermer'))),
          ],
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Déclarations
// ---------------------------------------------------------------------------

class _DeclarationView extends StatelessWidget {
  const _DeclarationView({required this.data});

  final Map<String, dynamic> data;

  static const _legacy = [('cnps', 'CNPS — déclaration mensuelle des salaires et cotisations'), ('dgi', 'Impôts (DGI) — ITS et contribution employeur'), ('fdfp', 'FDFP — taxes de formation')];

  /// Blocs de la déclaration (format multi-pays, sinon ancien format ivoirien).
  List<Map<String, dynamic>> get _blocks => data['blocks'] is List
      ? (data['blocks'] as List).map((b) => Map<String, dynamic>.from(b as Map)).toList()
      : [for (final b in _legacy) if (data[b.$1] is Map) {'title': b.$2, ...Map<String, dynamic>.from(data[b.$1] as Map)}];

  List get _employees => data['employees'] as List? ?? (data['cnps']?['employees'] as List? ?? []);

  String _rate(dynamic r) => r == null ? '' : '${number(r as num, decimals: rateDecimals(r))} %';

  Future<void> _print(BuildContext context) async {
    final s = context.read<Session>();
    await printReport(
      company: '${s.company['name'] ?? ''}',
      title: tr('Déclarations sociales et fiscales'),
      period: '${_cap(periodLabel('${data['period']}'))} · ${data['headcount']} salarié(s)',
      headers: const ['Rubrique', 'Base', 'Taux', 'Montant'],
      sections: [
        for (final b in _blocks)
          ReportSection(
            title: '${b['title']}',
            rows: [
              for (final r in b['rows'] as List)
                if ((r['amount'] as num) != 0 || (r['base'] as num? ?? 0) != 0) ['${r['label']}', r['base'] == null ? '' : number(r['base'] as num), _rate(r['rate']), number(r['amount'] as num)],
            ],
            total: ['Total à payer', '', '', number(b['total'] as num)],
          ),
        ReportSection(
          title: tr('${data['employees_title'] ?? 'Détail CNPS par salarié'}'),
          rows: [
            for (final e in _employees)
              ['${e['matricule']} ${e['name']} · N° ${e['number'] ?? e['cnps_number'] ?? '—'}', number(e['gross'] as num), number(e['days'] as num, decimals: 1), number((e['amount'] ?? e['cnps']) as num)],
          ],
        ),
      ],
      footnote: 'Montants calculés selon les paramètres de paie de la société. ${data['footnote'] ?? 'À vérifier avant dépôt sur les portails CNPS (e-CNPS) et DGI (e-impots).'}',
    );
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
      shrinkWrap: true,
      children: [
        for (final b in _blocks) ...[
          Padding(
            padding: const EdgeInsets.only(top: 12, bottom: 6),
            child: Text(tr('${b['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
          for (final r in b['rows'] as List)
            if ((r['amount'] as num) != 0)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  children: [
                    Expanded(child: Text(tr('${r['label']}${r['rate'] != null ? ' (${_rate(r['rate'])})' : ''}'))),
                    Text(tr(_m(context, r['amount'] as num))),
                  ],
                ),
              ),
          Row(
            children: [
              Expanded(
                child: Text(tr('Total'), style: TextStyle(fontWeight: FontWeight.w700)),
              ),
              Text(tr(_m(context, b['total'] as num)), style: const TextStyle(fontWeight: FontWeight.w700)),
            ],
          ),
          const Divider(),
        ],
        const SizedBox(height: 8),
        FilledButton.icon(onPressed: () => _print(context), icon: const Icon(Icons.print), label: Text(tr('Imprimer / PDF'))),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Simulateur brut ↔ net
// ---------------------------------------------------------------------------

class _SimulatorTab extends StatefulWidget {
  const _SimulatorTab();

  @override
  State<_SimulatorTab> createState() => _SimulatorTabState();
}

class _SimulatorTabState extends State<_SimulatorTab> {
  bool _fromNet = false;
  final _amount = TextEditingController(text: '300000');
  final _transport = TextEditingController(text: '30000');
  final _children = TextEditingController(text: '0');
  final _seniority = TextEditingController(text: '0');
  String _marital = 'single';
  bool _spouse = false, _expat = false, _busy = false;
  Map<String, dynamic>? _result;

  Future<void> _run() async {
    setState(() => _busy = true);
    try {
      final amount = num.tryParse(_amount.text.replaceAll(' ', '')) ?? 0;
      final r = await context.read<Session>().api.post('paie/simulate', {
        _fromNet ? 'target_net' : 'base_salary': amount,
        'transport': num.tryParse(_transport.text.replaceAll(' ', '')) ?? 0,
        'children_count': int.tryParse(_children.text) ?? 0,
        'seniority_years': int.tryParse(_seniority.text) ?? 0,
        'marital_status': _marital,
        'spouse_dependent': _spouse,
        'is_expatriate': _expat,
      });
      if (mounted) setState(() => _result = Map<String, dynamic>.from(r));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final digits = [FilteringTextInputFormatter.digitsOnly];
    final form = SectionCard(
      title: tr('Hypothèses'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SegmentedButton<bool>(
            segments: [
              ButtonSegment(value: false, label: Text(tr('Du brut au net'))),
              ButtonSegment(value: true, label: Text(tr('Du net au brut'))),
            ],
            selected: {_fromNet},
            onSelectionChanged: (s) => setState(() => _fromNet = s.first),
          ),
          const SizedBox(height: 16),
          Field(
            label: _fromNet ? 'Net souhaité' : 'Salaire de base',
            child: TextField(controller: _amount, keyboardType: TextInputType.number, inputFormatters: digits),
          ),
          Field(
            label: tr('Indemnité de transport'),
            child: TextField(controller: _transport, keyboardType: TextInputType.number, inputFormatters: digits),
          ),
          Row(
            children: [
              Expanded(
                child: Field(
                  label: tr('Situation'),
                  child: DropdownButtonFormField<String>(
                    isExpanded: true,
                    initialValue: _marital,
                    items: [for (final e in maritalStatuses.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                    onChanged: (v) => setState(() => _marital = v!),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(
                width: 90,
                child: Field(
                  label: tr('Enfants'),
                  child: TextField(controller: _children, keyboardType: TextInputType.number, inputFormatters: digits),
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(
                width: 110,
                child: Field(
                  label: tr('Ancienneté (ans)'),
                  child: TextField(controller: _seniority, keyboardType: TextInputType.number, inputFormatters: digits),
                ),
              ),
            ],
          ),
          SwitchListTile(contentPadding: EdgeInsets.zero, value: _spouse, onChanged: (v) => setState(() => _spouse = v), title: Text(tr('Conjoint sans emploi (CMU)'))),
          SwitchListTile(contentPadding: EdgeInsets.zero, value: _expat, onChanged: (v) => setState(() => _expat = v), title: Text(tr('Expatrié'))),
          const SizedBox(height: 8),
          FilledButton.icon(onPressed: _busy ? null : _run, icon: const Icon(Icons.calculate_outlined), label: Text(tr('Calculer'))),
        ],
      ),
    );

    Widget result() {
      final r = _result!;
      final t = Map<String, dynamic>.from(r['totals']);
      Widget l(String k, num? v, {bool bold = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          children: [
            Expanded(
              child: Text(tr(k), style: TextStyle(fontWeight: bold ? FontWeight.w700 : null)),
            ),
            Text(tr(_m(context, v)), style: TextStyle(fontWeight: bold ? FontWeight.w700 : null)),
          ],
        ),
      );
      return SectionCard(
        title: tr('Résultat'),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_fromNet) l('Salaire de base nécessaire', r['base_salary'] as num, bold: true),
            for (final g in r['gains'] as List) l('${g['label']}', g['amount'] as num),
            l('Salaire brut', t['gross'] as num, bold: true),
            for (final w in r['withholdings'] as List)
              l(
                (w['detail'] is Map && w['detail']['parts'] != null)
                    ? '${w['label']} (${number(w['detail']['parts'] as num, decimals: 1)} parts${((w['detail']['ricf'] ?? w['detail']['reduction'] ?? 0) as num) > 0 ? ', réduction ${_m(context, (w['detail']['ricf'] ?? w['detail']['reduction']) as num)}' : ''})'
                    : '${w['label']}',
                -(w['amount'] as num),
              ),
            const Divider(),
            l('Net à payer', t['net'] as num, bold: true),
            const SizedBox(height: 12),
            l('Charges patronales', t['employer_total'] as num),
            l('Coût total employeur', t['cost'] as num, bold: true),
          ],
        ),
      );
    }

    return LayoutBuilder(
      builder: (context, c) => SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: c.maxWidth > 900
            ? Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(child: form),
                  const SizedBox(width: 16),
                  Expanded(child: _result == null ? const SizedBox() : result()),
                ],
              )
            : Column(
                children: [
                  form,
                  if (_result != null) ...[const SizedBox(height: 16), result()],
                ],
              ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Paramètres
// ---------------------------------------------------------------------------

class _SettingsTab extends StatefulWidget {
  const _SettingsTab();

  @override
  State<_SettingsTab> createState() => _SettingsTabState();
}

class _SettingsTabState extends State<_SettingsTab> {
  Map<String, dynamic>? _s;
  Map<String, dynamic> _meta = {};
  Object? _error;
  final Map<String, TextEditingController> _c = {};

  bool get _ci => _meta['engine'] != 'profile';

  Future<void> _changeCountry(String code) async {
    final name = ((_meta['countries'] as List?) ?? []).cast<Map>().firstWhere((c) => c['code'] == code, orElse: () => {'name': code})['name'];
    if (!await confirm(context, 'Changer de législation', 'La paie sera calculée selon : $name. Les paies déjà validées ne changent pas ; recalculez les brouillons.', ok: 'Appliquer')) return;
    if (!mounted) return;
    try {
      await context.read<Session>().api.put('paie/profile', {'country': code});
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }
  List<Map<String, TextEditingController>> _bareme = [];
  bool _seniority = true, _busy = false;

  static const _groups = [
    ('Général', [('smig', 'SMIG mensuel'), ('days_basis', 'Base de prorata (jours)'), ('transport_exonere', 'Transport exonéré jusqu\'à'), ('anciennete_max', 'Ancienneté : taux maximum (%)')]),
    ('CNPS — retraite', [('retraite_salarie', 'Part salariale (%)'), ('retraite_employeur', 'Part patronale (%)'), ('plafond_retraite', 'Plafond mensuel')]),
    (
      'CNPS — autres branches (employeur)',
      [('prestations_familiales', 'Prestations familiales (%)'), ('maternite', 'Assurance maternité (%)'), ('accident_travail', 'Accidents du travail (%)'), ('plafond_autres', 'Plafond mensuel')],
    ),
    ('CMU (par personne et par mois)', [('cmu_salarie', 'Part salariale'), ('cmu_employeur', 'Part patronale'), ('cmu_max_enfants', 'Enfants couverts au maximum')]),
    ('ITS', [('ricf_par_demi_part', 'Réduction par demi-part'), ('parts_max', 'Nombre de parts maximum')]),
    (
      'Impôts patronaux (% du brut imposable)',
      [
        ('contribution_nationaux', 'Contribution — nationaux'),
        ('contribution_expatries', 'Contribution — expatriés'),
        ('fdfp_apprentissage', 'FDFP apprentissage'),
        ('fdfp_formation', 'FDFP formation continue'),
      ],
    ),
  ];

  static const _accounts = {
    'salaires': 'Salaires bruts',
    'charges_sociales': 'Charges sociales',
    'impots_employeur': 'Impôts sur salaires',
    'net_a_payer': 'Rémunérations dues',
    'avances': 'Avances et acomptes',
    'cnps': 'Organismes sociaux (CNPS…)',
    'its': 'Impôts sur salaires retenus',
    'impots_dus': 'Impôts patronaux dus',
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  String _fmt(dynamic v) => v == null ? '' : (v is num && v == v.roundToDouble() ? v.toInt().toString() : '$v');

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('paie/settings');
      final s = Map<String, dynamic>.from(r['settings']);
      if (!mounted) return;
      setState(() {
        _s = s;
        _meta = Map<String, dynamic>.from(r)..remove('settings')..remove('defaults');
        for (final g in _groups) {
          for (final f in g.$2) {
            _c[f.$1] = TextEditingController(text: _fmt(s[f.$1]));
          }
        }
        for (final a in _accounts.keys) {
          _c['acc_$a'] = TextEditingController(text: '${(s['comptes'] as Map?)?[a] ?? ''}');
        }
        _bareme = [
          for (final t in s['its_bareme'] as List) {'up_to': TextEditingController(text: _fmt(t['up_to'])), 'rate': TextEditingController(text: _fmt(t['rate']))},
        ];
        _seniority = s['anciennete_auto'] != false;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  num? _n(String v) => num.tryParse(v.replaceAll(' ', '').replaceAll(',', '.'));

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final data = <String, dynamic>{
        if (_ci) ...{
          for (final g in _groups)
            for (final f in g.$2)
              if (_n(_c[f.$1]!.text) != null) f.$1: _n(_c[f.$1]!.text),
          'anciennete_auto': _seniority,
          'its_bareme': [
            for (final t in _bareme) {'up_to': _n(t['up_to']!.text), 'rate': _n(t['rate']!.text) ?? 0},
          ],
        },
        'comptes': {for (final a in _accounts.keys) a: _c['acc_$a']!.text.trim()},
      };
      await context.read<Session>().api.put('paie/settings', data);
      if (mounted) toast(context, tr('Paramètres enregistrés. Recalculez les paies en brouillon pour les appliquer.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _s == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_s == null) return const Center(child: CircularProgressIndicator());

    Widget grid(List<Widget> children) => LayoutBuilder(
      builder: (context, c) {
        final cols = c.maxWidth > 900 ? 4 : (c.maxWidth > 560 ? 2 : 1);
        final w = (c.maxWidth - (cols - 1) * 12) / cols;
        return Wrap(
          spacing: 12,
          children: [for (final ch in children) SizedBox(width: w, child: ch)],
        );
      },
    );

    final accounts = SectionCard(
      title: tr('Comptes comptables'),
      child: grid([
        for (final a in _accounts.entries)
          Field(
            label: a.value,
            child: TextField(controller: _c['acc_${a.key}'], keyboardType: TextInputType.number),
          ),
      ]),
    );
    final countryCard = _meta['countries'] == null
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: PayrollCountryCard(
              country: '${_meta['country']}',
              countries: _meta['countries'] as List,
              profile: Map<String, dynamic>.from(_meta['profile'] as Map),
              onChanged: _changeCountry,
            ),
          );
    if (!_ci) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          countryCard,
          PayrollProfileEditor(country: '${_meta['country']}', profile: Map<String, dynamic>.from(_meta['profile'] as Map), onSaved: _load),
          const SizedBox(height: 24),
          accounts,
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer les comptes'))),
          ),
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        countryCard,
        Card(
          color: noticeFill(context), shape: noticeShape(context),
          child: Padding(
            padding: EdgeInsets.all(14),
            child: Text(
              tr('Taux préremplis selon la législation ivoirienne en vigueur depuis le 1er janvier 2024 (réforme ITS). '
              'Faites-les valider par votre expert-comptable, notamment le taux accidents du travail propre à votre activité (2 à 5 %) et le plafond des prestations familiales.'),
            ),
          ),
        ),
        const SizedBox(height: 12),
        for (final g in _groups)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: SectionCard(
              title: g.$1,
              child: grid([
                for (final f in g.$2)
                  Field(
                    label: f.$2,
                    child: TextField(controller: _c[f.$1], keyboardType: const TextInputType.numberWithOptions(decimal: true)),
                  ),
                if (g.$1 == 'Général')
                  SwitchListTile(contentPadding: EdgeInsets.zero, value: _seniority, onChanged: (v) => setState(() => _seniority = v), title: Text(tr('Prime d\'ancienneté automatique'))),
              ]),
            ),
          ),
        SectionCard(
          title: tr('Barème ITS mensuel'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (var i = 0; i < _bareme.length; i++)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _bareme[i]['up_to'],
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(labelText: tr('Jusqu\'à'), hintText: trn(i == _bareme.length - 1 ? 'Au-delà' : null)),
                        ),
                      ),
                      const SizedBox(width: 12),
                      SizedBox(
                        width: 120,
                        child: TextField(
                          controller: _bareme[i]['rate'],
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(labelText: tr('Taux %')),
                        ),
                      ),
                      IconButton(onPressed: () => setState(() => _bareme.removeAt(i)), icon: const Icon(Icons.delete_outline)),
                    ],
                  ),
                ),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: () => setState(() => _bareme.add({'up_to': TextEditingController(), 'rate': TextEditingController()})),
                  icon: const Icon(Icons.add),
                  label: Text(tr('Ajouter une tranche')),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        accounts,
        const SizedBox(height: 16),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer les paramètres'))),
        ),
      ],
    );
  }
}
