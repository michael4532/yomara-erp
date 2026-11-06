import '../../core/json.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../pdf/report_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../../core/local_db.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/report_charts.dart';
import '../../i18n/i18n.dart';

part 'compta_cabinet.dart';
part 'compta_closing.dart';
part 'compta_group.dart';

/// Période d'analyse (bornes incluses).
class Period {
  const Period(this.from, this.to, this.label);

  final DateTime from;
  final DateTime to;
  final String label;

  Map<String, String> get query => {'from': isoDate(from), 'to': isoDate(to)};
  String get text => '$label (${dateFr(isoDate(from))} au ${dateFr(isoDate(to))})';

  static Period thisMonth() {
    final n = DateTime.now();
    return Period(DateTime(n.year, n.month), DateTime(n.year, n.month + 1, 0), DateFormat('MMMM yyyy', I18n.dateLocale).format(n));
  }

  static Period lastMonth() {
    final n = DateTime.now();
    final d = DateTime(n.year, n.month - 1);
    return Period(d, DateTime(d.year, d.month + 1, 0), DateFormat('MMMM yyyy', I18n.dateLocale).format(d));
  }

  /// Mois de début de l'exercice (1 = janvier ; 7 = exercice juillet → juin), fourni par le serveur.
  static int fiscalStart = 1;

  static Period fiscalYear(int y) {
    final from = DateTime(y, fiscalStart);
    final to = DateTime(y + 1, fiscalStart, 0);
    return Period(from, to, fiscalStart == 1 ? 'Exercice $y' : 'Exercice $y-${y + 1}');
  }

  static int currentFiscalYear() {
    final n = DateTime.now();
    return n.month >= fiscalStart ? n.year : n.year - 1;
  }

  static Period thisYear() => fiscalYear(currentFiscalYear());

  static Period lastYear() => fiscalYear(currentFiscalYear() - 1);
}

/// Comptabilité selon le référentiel de la société : SYSCOHADA, SMT, SYCEBNL, PCG, CGNC (nécessite la connexion : les écritures sont tenues par le serveur).
class ComptaScreen extends StatefulWidget {
  const ComptaScreen({super.key});

  @override
  State<ComptaScreen> createState() => _ComptaScreenState();
}

class _ComptaScreenState extends State<ComptaScreen> with SingleTickerProviderStateMixin {
  late final bool _manage = context.read<Session>().can('compta.manage');
  late final List<(String, String)> _tabsDef = [
    ('dashboard', 'Tableau de bord'),
    ('purchases', 'Factures fournisseurs'),
    ('reports', 'États'),
    ('entries', 'Écritures'),
    ('tiers', 'Tiers'),
    ('ledger', 'Grand livre'),
    ('closing', 'Clôture & liasse'),
    ('group', 'Consolidation'),
    if (_manage) ('settings', 'Paramètres'),
  ];
  late final TabController _tabs = TabController(length: _tabsDef.length, vsync: this);
  Period _period = Period.thisYear();
  Map<String, dynamic>? _setup;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _loadSetup();
  }

  Future<void> _loadSetup() async {
    try {
      final s = Map<String, dynamic>.from(await context.read<Session>().api.get('compta/setup'));
      final fs = toDouble(s['fiscal_start_month']).round();
      final changed = fs >= 1 && fs <= 12 && fs != Period.fiscalStart;
      if (changed) Period.fiscalStart = fs;
      if (mounted) {
        setState(() {
          _setup = s;
          _error = null;
          if (changed && _period.label.startsWith('Exercice')) _period = Period.thisYear();
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _open(String tab) {
    final i = _tabsDef.indexWhere((t) => t.$1 == tab);
    if (i >= 0) _tabs.animateTo(i);
  }

  Future<void> _pickPeriod() async {
    final presets = [Period.thisMonth(), Period.lastMonth(), Period.thisYear(), Period.lastYear()];
    final choice = await showModalBottomSheet<Object>(
      context: context,
      builder: (c) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          for (final p in presets) ListTile(title: Text(tr(p.label)), onTap: () => Navigator.pop(c, p)),
          ListTile(leading: const Icon(Icons.date_range), title: Text(tr('Période personnalisée…')), onTap: () => Navigator.pop(c, 'custom')),
        ]),
      ),
    );
    if (!mounted || choice == null) return;
    if (choice is Period) {
      setState(() => _period = choice);
    } else {
      final r = await showDateRangePicker(
        context: context,
        firstDate: DateTime(2015),
        lastDate: DateTime(2100),
        initialDateRange: DateTimeRange(start: _period.from, end: _period.to),
      );
      if (r != null) setState(() => _period = Period(r.start, r.end, 'Période'));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _setup == null) return OnlineOnlyNotice(error: _error!, onRetry: _loadSetup);
    if (_setup == null) return const Center(child: CircularProgressIndicator());
    final toReview = toDouble(_setup!['to_review']).round();

    return Column(children: [
      PageHeader(
        title: tr('Comptabilité'),
        subtitle: tr('${_setup!['referential']?['label'] ?? 'SYSCOHADA révisé'} · écritures automatiques depuis les ventes, la caisse et les achats · états financiers'),
        actions: [
          ActionChip(avatar: const Icon(Icons.calendar_month, size: 18), label: Text(tr(_period.label)), onPressed: _pickPeriod),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        for (final t in _tabsDef) Tab(text: t.$1 == 'purchases' && toReview > 0 ? '${t.$2} ($toReview)' : t.$2),
      ]),
      Expanded(
        child: TabBarView(controller: _tabs, children: [
          for (final t in _tabsDef)
            switch (t.$1) {
              'dashboard' => _DashboardTab(period: _period, onOpen: _open, key: ValueKey('d${_period.query}')),
              'purchases' => _PurchasesTab(setup: _setup!, manage: _manage, onChanged: _loadSetup, key: ValueKey('p${_setup!['purchase_review']}')),
              'reports' => _ReportsTab(period: _period, key: ValueKey('r${_period.query}')),
              'entries' => _EntriesTab(period: _period, setup: _setup!, manage: _manage, key: ValueKey('e${_period.query}')),
              'tiers' => _TiersTab(period: _period, key: ValueKey('t${_period.query}')),
              'ledger' => _LedgerTab(period: _period, setup: _setup!, key: ValueKey('l${_period.query}')),
              'closing' => _ClosingTab(manage: _manage, onOpen: _open, key: ValueKey('c${_setup!['fiscal_start_month']}')),
              'group' => _GroupTab(manage: _manage),
              _ => _SettingsTab(setup: _setup!, period: _period, onChanged: _loadSetup),
            },
        ]),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Chargement générique
// ---------------------------------------------------------------------------

class _Fetch extends StatefulWidget {
  const _Fetch({super.key, required this.path, this.query, required this.builder});

  final String path;
  final Map<String, String>? query;
  final Widget Function(BuildContext, Map<String, dynamic>, VoidCallback) builder;

  @override
  State<_Fetch> createState() => _FetchState();
}

class _FetchState extends State<_Fetch> {
  Map<String, dynamic>? _data;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant _Fetch old) {
    super.didUpdateWidget(old);
    if (old.path != widget.path || old.query.toString() != widget.query.toString()) {
      _data = null; // autre état : ne pas afficher les données du précédent pendant le chargement
      _load();
    }
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.get(widget.path, query: widget.query));
      if (mounted) setState(() => _data = r);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_data == null) return const Center(child: CircularProgressIndicator());
    return widget.builder(context, _data!, _load);
  }
}

String _m(BuildContext c, num? v) => money(v, c.read<Session>().currency);

String _company(BuildContext c) => '${c.read<Session>().company['name'] ?? ''}';

TextStyle get _num => const TextStyle(fontFeatures: [FontFeature.tabularFigures()]);

// ---------------------------------------------------------------------------
// États : balance, compte de résultat, bilan, TVA
// ---------------------------------------------------------------------------

class _ReportsTab extends StatefulWidget {
  const _ReportsTab({super.key, required this.period});

  final Period period;

  @override
  State<_ReportsTab> createState() => _ReportsTabState();
}

class _ReportsTabState extends State<_ReportsTab> {
  String _report = 'income';

  @override
  Widget build(BuildContext context) {
    final p = widget.period;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
      SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: SegmentedButton<String>(
          showSelectedIcon: false,
          segments: [
            ButtonSegment(value: 'income', label: Text(tr('Compte de résultat'))),
            ButtonSegment(value: 'sheet', label: Text(tr('Bilan'))),
            ButtonSegment(value: 'cashflow', label: Text(tr('Flux de trésorerie'))),
            ButtonSegment(value: 'balance', label: Text(tr('Balance'))),
            ButtonSegment(value: 'vat', label: Text(tr('TVA'))),
          ],
          selected: {_report},
          onSelectionChanged: (s) => setState(() => _report = s.first),
        ),
      ),
      const SizedBox(height: 16),
      switch (_report) {
        'income' => _Fetch(path: 'compta/statements/income', query: p.query, builder: (c, d, _) => _incomeStatement(c, d['data'], p)),
        'sheet' => _Fetch(path: 'compta/statements/sheet', query: p.query, builder: (c, d, _) => _sheetStatement(c, d['data'], p)),
        'cashflow' => _Fetch(path: 'compta/statements/cashflow', query: p.query, builder: (c, d, _) => _cashflowStatement(c, d['data'], p)),
        'balance' => _Fetch(path: 'compta/balance', query: p.query, builder: (c, d, _) => _balance(c, d['data'], p)),
        _ => _Fetch(path: 'compta/vat', query: p.query, builder: (c, d, _) => _vat(c, d['data'], p)),
      },
    ]);
  }

  Widget _printButton(VoidCallback onTap) => Align(
        alignment: Alignment.centerRight,
        child: TextButton.icon(onPressed: onTap, icon: const Icon(Icons.print_outlined, size: 18), label: Text(tr('Imprimer / PDF'))),
      );

  Widget _row(String label, String value, {bool bold = false, Color? color, String? code}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(children: [
          if (code != null) SizedBox(width: 44, child: Text(tr(code), style: const TextStyle(color: YColors.muted))),
          Expanded(child: Text(tr(label), style: TextStyle(fontWeight: bold ? FontWeight.w700 : null))),
          Text(tr(value), style: _num.copyWith(fontWeight: bold ? FontWeight.w700 : null, color: color)),
        ]),
      );

  Widget _balance(BuildContext c, Map d, Period p) {
    final rows = List<Map>.from(d['rows']);
    final t = Map<String, dynamic>.from(d['totals']);
    if (rows.isEmpty) return EmptyState(icon: Icons.balance, title: tr('Aucune écriture sur la période'));
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _printButton(() => printReport(
            company: _company(c),
            title: tr('Balance générale'),
            period: p.text,
            landscape: true,
            headers: const ['Compte', 'Intitulé', 'Débit', 'Crédit', 'Solde débiteur', 'Solde créditeur'],
            sections: [
              ReportSection(
                rows: [for (final r in rows) ['${r['code']}', '${r['name']}', _m(c, r['debit']), _m(c, r['credit']), _m(c, r['debit_balance']), _m(c, r['credit_balance'])]],
                total: ['', 'Totaux', _m(c, t['debit']), _m(c, t['credit']), _m(c, t['debit_balance']), _m(c, t['credit_balance'])],
              ),
            ],
          )),
      Card(
        clipBehavior: Clip.antiAlias,
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: DataTable(
            columnSpacing: 24,
            columns: [
              DataColumn(label: Text(tr('Compte'))),
              DataColumn(label: Text(tr('Intitulé'))),
              DataColumn(label: Text(tr('Débit')), numeric: true),
              DataColumn(label: Text(tr('Crédit')), numeric: true),
              DataColumn(label: Text(tr('Solde')), numeric: true),
            ],
            rows: [
              for (final r in rows)
                DataRow(cells: [
                  DataCell(Text(tr('${r['code']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                  DataCell(ConstrainedBox(constraints: const BoxConstraints(maxWidth: 320), child: Text(tr('${r['name']}'), overflow: TextOverflow.ellipsis))),
                  DataCell(Text(tr(_m(c, r['debit'])), style: _num)),
                  DataCell(Text(tr(_m(c, r['credit'])), style: _num)),
                  DataCell(Text(tr('${_m(c, toDouble(r['balance']).abs())} ${toDouble(r['balance']) >= 0 ? 'D' : 'C'}'), style: _num)),
                ]),
              DataRow(cells: [
                const DataCell(Text('')),
                DataCell(Text(tr('Totaux'), style: TextStyle(fontWeight: FontWeight.w700))),
                DataCell(Text(tr(_m(c, t['debit'])), style: _num.copyWith(fontWeight: FontWeight.w700))),
                DataCell(Text(tr(_m(c, t['credit'])), style: _num.copyWith(fontWeight: FontWeight.w700))),
                const DataCell(Text('')),
              ]),
            ],
          ),
        ),
      ),
    ]);
  }

  Widget _vat(BuildContext c, Map d, Period p) {
    final sales = List<Map>.from(d['sales_bases']);
    final purchases = List<Map>.from(d['purchase_bases']);
    final due = toDouble(d['due']);
    final credit = toDouble(d['credit']);
    Widget bases(String title, List<Map> rows) => SectionCard(
          title: title,
          child: rows.isEmpty
              ? Text(tr('Aucune opération.'), style: TextStyle(color: YColors.muted))
              : Column(children: [for (final r in rows) _row('Taux ${number(toDouble(r['rate']))} % · base HT ${_m(c, r['base'])}', _m(c, r['tax']))]),
        );
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _printButton(() => printReport(
            company: _company(c),
            title: tr('Déclaration de TVA'),
            period: p.text,
            headers: const ['Élément', 'Montant'],
            sections: [
              ReportSection(title: tr('Ventes'), rows: [for (final r in sales) ['Base HT à ${number(toDouble(r['rate']))} % : ${_m(c, r['base'])}', _m(c, r['tax'])]]),
              ReportSection(title: tr('Achats'), rows: [for (final r in purchases) ['Base HT à ${number(toDouble(r['rate']))} % : ${_m(c, r['base'])}', _m(c, r['tax'])]]),
              ReportSection(rows: [
                ['TVA collectée (${d['accounts']['collected']})', _m(c, d['collected'])],
                ['TVA déductible (${d['accounts']['deductible']})', _m(c, d['deductible'])],
              ], total: [due > 0 ? 'TVA À PAYER' : 'CRÉDIT DE TVA À REPORTER', _m(c, due > 0 ? due : credit)]),
            ],
            footnote: 'Document de travail : à reporter sur votre déclaration (e-impôts).',
          )),
      Align(
        alignment: Alignment.centerRight,
        child: Wrap(spacing: 8, children: [
          TextButton.icon(onPressed: () => _openServerPdf(c, 'compta/vat/return/pdf', 'Etat annexe TVA', query: p.query), icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(tr('État annexé (PDF)'))),
          TextButton.icon(onPressed: () => _saveServerXlsx(c, 'compta/vat/return/xlsx', 'tva_${isoDate(p.from)}_${isoDate(p.to)}.xlsx', query: p.query), icon: const Icon(Icons.grid_on_outlined, size: 18), label: Text(tr('État annexé (Excel)'))),
        ]),
      ),
      Wrap(spacing: 16, runSpacing: 16, children: [
        SizedBox(width: 520, child: bases('TVA sur ventes', sales)),
        SizedBox(width: 520, child: bases('TVA sur achats', purchases)),
      ]),
      const SizedBox(height: 16),
      SectionCard(child: Column(children: [
        _row('TVA collectée', _m(c, d['collected'])),
        _row('TVA déductible', '− ${_m(c, d['deductible'])}'),
        const Divider(),
        due > 0
            ? _row('TVA à payer', _m(c, due), bold: true, color: YColors.danger)
            : _row('Crédit de TVA à reporter', _m(c, credit), bold: true, color: YColors.success),
      ])),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Écritures
// ---------------------------------------------------------------------------

class _EntriesTab extends StatefulWidget {
  const _EntriesTab({super.key, required this.period, required this.setup, required this.manage});

  final Period period;
  final Map<String, dynamic> setup;
  final bool manage;

  @override
  State<_EntriesTab> createState() => _EntriesTabState();
}

class _EntriesTabState extends State<_EntriesTab> {
  String? _journal;
  String _q = '';
  int _reload = 0;
  String _status = '';
  Map<String, String?> _f = {};
  Map<String, dynamic>? _raw;
  List<Map> _all = [];

  static String _kindOf(Map e) {
    if (e['kind'] == 'reversal') return 'reversal';
    if (e['reversed_by_id'] != null) return 'reversed';
    return e['kind'] == 'manual' ? 'manual' : 'auto';
  }

  bool _base(Map e) => _f['account'] == null || List<Map>.from(e['lines'] ?? const []).any((l) => '${l['account']?['id'] ?? l['account']?['code']}' == _f['account']);

  bool _st(Map e, String k) => k.isEmpty || _kindOf(e) == k;

  @override
  Widget build(BuildContext context) {
    final journals = List<Map>.from(widget.setup['journals']);
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final accounts = <String, String>{
      for (final e in _all)
        for (final l in List<Map>.from(e['lines'] ?? const []))
          if (l['account'] != null) '${l['account']['id'] ?? l['account']['code']}': '${l['account']['code']} · ${l['account']['name']}',
    };
    final sortedAccounts = Map.fromEntries(accounts.entries.toList()..sort((a, b) => a.value.compareTo(b.value)));
    return Column(children: [
      const SizedBox(height: 16),
      FilterBar(
        padding: EdgeInsets.fromLTRB(pad, 0, pad, 8),
        onSearch: (v) => setState(() => _q = v),
        search: _q,
        searchHint: 'N° de pièce, libellé, référence…',
        statuses: const {'': 'Toutes', 'manual': 'Manuelles', 'auto': 'Automatiques', 'reversal': 'Contre-passations', 'reversed': 'Contre-passées'},
        status: _status,
        onStatus: (v) => setState(() => _status = v),
        statusCounts: {for (final k in ['', 'manual', 'auto', 'reversal', 'reversed']) k: _all.where((e) => _base(e) && _st(e, k)).length},
        filters: [
          FilterSpec('journal', 'Journal', {for (final j in journals) '${j['code']}': '${j['code']} · ${j['name']}'}, icon: Icons.book_outlined),
          FilterSpec('account', 'Compte', sortedAccounts, icon: Icons.account_tree_outlined),
        ],
        values: {..._f, 'journal': _journal},
        onFilter: (k, v) => setState(() => k == 'journal' ? _journal = v : _f = {..._f, k: v}),
        count: _all.where((e) => _base(e) && _st(e, _status)).length,
        countLabel: 'écriture',
        onReset: () => setState(() => (_q = '', _journal = null, _status = '', _f = {})),
        trailing: [
          if (widget.manage) FilledButton.icon(onPressed: _newEntry, icon: const Icon(Icons.add), label: Text(tr('Écriture'))),
        ],
      ),
      Expanded(
        child: _Fetch(
          key: ValueKey('$_journal|$_q|$_reload'),
          path: 'compta/entries',
          query: {...widget.period.query, 'per_page': '200', 'journal': ?_journal, if (_q.isNotEmpty) 'q': _q},
          builder: (c, d, reload) {
            final all = List<Map>.from(d['data']);
            if (!identical(_raw, d)) {
              _raw = d;
              WidgetsBinding.instance.addPostFrameCallback((_) {
                if (mounted) setState(() => _all = all);
              });
            }
            final entries = all.where((e) => _base(e) && _st(e, _status)).toList();
            if (entries.isEmpty) {
              return EmptyState(icon: Icons.menu_book_outlined, title: tr('Aucune écriture'), message: tr('Validez une facture, un ticket ou un paiement : l\'écriture se crée toute seule.'));
            }
            return ListView.builder(
              padding: EdgeInsets.fromLTRB(pad, 4, pad, 24),
              itemCount: entries.length,
              itemBuilder: (_, i) => _EntryCard(entry: entries[i], manage: widget.manage, onReverse: () => _reverse(entries[i])),
            );
          },
        ),
      ),
    ]);
  }

  Future<void> _reverse(Map e) async {
    if (!await confirm(context, tr('Contre-passer ${e['number']} ?'), tr('Une écriture inverse sera créée. L\'écriture d\'origine reste visible (traçabilité).'), ok: tr('Contre-passer'))) return;
    if (!mounted) return;
    try {
      await context.read<Session>().api.post('compta/entries/${e['id']}/reverse');
      if (mounted) setState(() => _reload++);
    } catch (err) {
      if (mounted) toast(context, errorText(err), error: true);
    }
  }

  Future<void> _newEntry() async {
    final ok = await showFormDialog<bool>(context, title: 'Saisie d\'une écriture', maxWidth: 808, builder: (_) => _EntryForm(setup: widget.setup));
    if (ok == true && mounted) setState(() => _reload++);
  }
}

class _EntryCard extends StatelessWidget {
  const _EntryCard({required this.entry, required this.manage, required this.onReverse});

  final Map entry;
  final bool manage;
  final VoidCallback onReverse;

  @override
  Widget build(BuildContext context) {
    final e = entry;
    final reversed = e['reversed_by_id'] != null;
    final isReversal = e['kind'] == 'reversal';
    final lines = List<Map>.from(e['lines']);
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ExpansionTile(
        shape: const Border(),
        title: Row(children: [
          Text(tr('${e['number']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(width: 10),
          Expanded(child: Text(tr('${e['label']}'), overflow: TextOverflow.ellipsis)),
          Text(tr(_m(context, e['total'])), style: _num.copyWith(fontWeight: FontWeight.w600)),
        ]),
        subtitle: Row(children: [
          Text(tr('${dateFr(e['date'])} · ${e['journal']?['name'] ?? ''}')),
          if (e['kind'] != 'manual' && !isReversal) ...[const SizedBox(width: 8), StatusChip(tr('Automatique'), color: YColors.blue)],
          if (isReversal) ...[const SizedBox(width: 8), StatusChip(tr('Contre-passation'), color: YColors.warning)],
          if (reversed) ...[const SizedBox(width: 8), StatusChip(tr('Contre-passée'), color: YColors.muted)],
        ]),
        childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        children: [
          for (final l in lines)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(children: [
                SizedBox(width: 64, child: Text(tr('${l['account']?['code']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                Expanded(child: Text(tr('${l['account']?['name']}${l['label'] != null && l['label'] != e['label'] ? ' — ${l['label']}' : ''}'), overflow: TextOverflow.ellipsis)),
                SizedBox(width: 120, child: Text(tr(toDouble(l['debit']) > 0 ? _m(context, l['debit']) : ''), textAlign: TextAlign.right, style: _num)),
                SizedBox(width: 120, child: Text(tr(toDouble(l['credit']) > 0 ? _m(context, l['credit']) : ''), textAlign: TextAlign.right, style: _num)),
              ]),
            ),
          if (manage && !reversed && !isReversal)
            Align(alignment: Alignment.centerRight, child: TextButton.icon(onPressed: onReverse, icon: const Icon(Icons.undo, size: 18), label: Text(tr('Contre-passer')))),
        ],
      ),
    );
  }
}

/// Saisie d'une écriture d'opérations diverses (loyer, salaires, frais bancaires…).
class _EntryForm extends StatefulWidget {
  const _EntryForm({required this.setup});

  final Map<String, dynamic> setup;

  @override
  State<_EntryForm> createState() => _EntryFormState();
}

class _EntryFormState extends State<_EntryForm> {
  late final journals = List<Map>.from(widget.setup['journals']);
  late final accounts = List<Map>.from(widget.setup['accounts']).where((a) => a['active'] != false).toList();
  late int _journal = journals.firstWhere((j) => j['code'] == 'OD', orElse: () => journals.first)['id'];
  DateTime _date = DateTime.now();
  final _label = TextEditingController();
  final _ref = TextEditingController();
  final _lines = <_Line>[_Line(), _Line()];
  bool _busy = false;

  bool _isTiers(_Line l) {
    final code = '${accounts.firstWhere((a) => a['id'] == l.account, orElse: () => const {})['code'] ?? ''}';
    return code.startsWith('40') || code.startsWith('41');
  }

  late final _partners = LocalDb.all('partners').where((p) => p['deleted_at'] == null).toList();

  double get _debit => _lines.fold(0, (s, l) => s + toDouble(l.debit.text));
  double get _credit => _lines.fold(0, (s, l) => s + toDouble(l.credit.text));

  Future<void> _save() async {
    if (_label.text.trim().isEmpty) return toast(context, tr('Indiquez un libellé.'), error: true);
    final lines = _lines.where((l) => l.account != null && (toDouble(l.debit.text) > 0 || toDouble(l.credit.text) > 0)).toList();
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('compta/entries', {
        'journal_id': _journal,
        'date': isoDate(_date),
        'label': _label.text.trim(),
        'reference': _ref.text.trim().isEmpty ? null : _ref.text.trim(),
        'lines': [for (final l in lines) {'account_id': l.account, 'debit': toDouble(l.debit.text), 'credit': toDouble(l.credit.text), if (_isTiers(l)) 'partner_id': l.partner}],
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final diff = (_debit - _credit).abs();
    final fmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(spacing: 12, runSpacing: 4, children: [
            SizedBox(
              width: 220,
              child: Field(
                label: tr('Journal'),
                child: DropdownButtonFormField<int>(
                  initialValue: _journal,
                  items: [for (final j in journals) DropdownMenuItem(value: j['id'] as int, child: Text(tr('${j['code']} · ${j['name']}')))],
                  onChanged: (v) => setState(() => _journal = v!),
                ),
              ),
            ),
            SizedBox(
              width: 160,
              child: Field(
                label: tr('Date'),
                child: OutlinedButton(
                  onPressed: () async {
                    final d = await showDatePicker(context: context, initialDate: _date, firstDate: DateTime(2015), lastDate: DateTime(2100));
                    if (d != null) setState(() => _date = d);
                  },
                  child: Text(tr(dateFr(isoDate(_date)))),
                ),
              ),
            ),
            SizedBox(width: 340, child: Field(label: tr('Libellé'), child: TextField(controller: _label, autofocus: true))),
            SizedBox(width: 180, child: Field(label: tr('Référence (facultatif)'), child: TextField(controller: _ref))),
          ]),
          const SizedBox(height: 4),
          for (var i = 0; i < _lines.length; i++) ...[
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(children: [
                Expanded(
                  child: DropdownButtonFormField<int>(
                    isExpanded: true,
                    initialValue: _lines[i].account,
                    hint: Text(tr('Compte')),
                    items: [for (final a in accounts) DropdownMenuItem(value: a['id'] as int, child: Text(tr('${a['code']} · ${a['name']}'), overflow: TextOverflow.ellipsis))],
                    onChanged: (v) => setState(() => _lines[i].account = v),
                  ),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  width: 120,
                  child: TextField(
                    controller: _lines[i].debit,
                    inputFormatters: fmt,
                    textAlign: TextAlign.right,
                    decoration: InputDecoration(hintText: tr('Débit')),
                    onChanged: (_) => setState(() {}),
                  ),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  width: 120,
                  child: TextField(
                    controller: _lines[i].credit,
                    inputFormatters: fmt,
                    textAlign: TextAlign.right,
                    decoration: InputDecoration(hintText: tr('Crédit')),
                    onChanged: (_) => setState(() {}),
                  ),
                ),
                IconButton(
                  onPressed: _lines.length > 2 ? () => setState(() => _lines.removeAt(i)) : null,
                  icon: const Icon(Icons.close, size: 18),
                ),
              ]),
            ),
            if (_isTiers(_lines[i]))
              Padding(
                padding: const EdgeInsets.only(left: 16, bottom: 10, right: 40),
                child: Autocomplete<Map<String, dynamic>>(
                  key: ValueKey('tiers$i${_lines[i].account}'),
                  initialValue: TextEditingValue(text: _lines[i].partnerName ?? ''),
                  optionsBuilder: (v) => _partners.where((p) => '${p['name']} ${p['code'] ?? ''}'.toLowerCase().contains(v.text.toLowerCase())).take(20),
                  displayStringForOption: (p) => '${p['name']}',
                  onSelected: (p) => setState(() => (_lines[i].partner = '${p['id']}', _lines[i].partnerName = '${p['name']}')),
                  fieldViewBuilder: (c, ctl, focus, _) => TextField(
                    controller: ctl,
                    focusNode: focus,
                    decoration: InputDecoration(isDense: true, prefixIcon: Icon(Icons.person_outline, size: 18), labelText: tr('Compte de tiers (client ou fournisseur)')),
                  ),
                ),
              ),
          ],
          Row(children: [
            TextButton.icon(
              onPressed: () => setState(() {
                final l = _Line();
                final gap = _debit - _credit;
                if (gap > 0) l.credit.text = '${gap.round()}';
                if (gap < 0) l.debit.text = '${(-gap).round()}';
                _lines.add(l);
              }),
              icon: const Icon(Icons.add, size: 18),
              label: Text(tr('Ligne')),
            ),
            const Spacer(),
            Text(tr('Débit ${_m(context, _debit)} · Crédit ${_m(context, _credit)}'), style: _num),
            const SizedBox(width: 12),
            _debit <= 0 && _credit <= 0
                ? StatusChip(tr('Montants à saisir'), color: YColors.muted)
                : diff < 0.005
                    ? StatusChip(tr('Équilibrée'), color: YColors.success)
                    : StatusChip(tr('Écart ${_m(context, diff)}'), color: YColors.danger),
          ]),
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
            FilledButton(onPressed: _busy || diff >= 0.005 || _debit <= 0 ? null : _save, child: Text(tr('Enregistrer'))),
          ]),
        ),
      ]),
    );
  }
}

class _Line {
  int? account;
  String? partner;
  String? partnerName;
  final debit = TextEditingController();
  final credit = TextEditingController();
}

// ---------------------------------------------------------------------------
// Grand livre
// ---------------------------------------------------------------------------

class _LedgerTab extends StatefulWidget {
  const _LedgerTab({super.key, required this.period, required this.setup});

  final Period period;
  final Map<String, dynamic> setup;

  @override
  State<_LedgerTab> createState() => _LedgerTabState();
}

class _LedgerTabState extends State<_LedgerTab> {
  int? _account;

  @override
  void initState() {
    super.initState();
    final a = List<Map>.from(widget.setup['accounts']).firstWhere((a) => a['code'] == '411', orElse: () => {});
    _account = a['id'] as int?;
  }

  @override
  Widget build(BuildContext context) {
    final accounts = List<Map>.from(widget.setup['accounts']);
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return Column(children: [
      Padding(
        padding: EdgeInsets.fromLTRB(pad, 16, pad, 8),
        child: DropdownButtonFormField<int>(
          isExpanded: true,
          initialValue: _account,
          decoration: InputDecoration(labelText: tr('Compte')),
          items: [for (final a in accounts) DropdownMenuItem(value: a['id'] as int, child: Text(tr('${a['code']} · ${a['name']}'), overflow: TextOverflow.ellipsis))],
          onChanged: (v) => setState(() => _account = v),
        ),
      ),
      if (_account != null)
        Expanded(
          child: _Fetch(
            path: 'compta/ledger/$_account',
            query: widget.period.query,
            builder: (c, d, _) {
              final data = Map<String, dynamic>.from(d['data']);
              final rows = List<Map>.from(data['rows']);
              final acc = data['account'];
              return ListView(padding: EdgeInsets.fromLTRB(pad, 0, pad, 24), children: [
                Row(children: [
                  Text(tr('Report à nouveau : ${_m(c, data['opening'])}'), style: const TextStyle(color: YColors.muted)),
                  const Spacer(),
                  TextButton.icon(
                    icon: const Icon(Icons.print_outlined, size: 18),
                    label: Text(tr('Imprimer / PDF')),
                    onPressed: () => printReport(
                      company: _company(c),
                      title: tr('Grand livre ${acc['code']} · ${acc['name']}'),
                      period: widget.period.text,
                      landscape: true,
                      headers: const ['Date', 'Pièce', 'Jnl', 'Libellé', 'Tiers', 'Débit', 'Crédit', 'Solde'],
                      align: {5: pw.Alignment.centerRight, 6: pw.Alignment.centerRight, 7: pw.Alignment.centerRight},
                      sections: [
                        ReportSection(
                          rows: [
                            ['', '', '', 'Report à nouveau', '', '', '', _m(c, data['opening'])],
                            for (final r in rows)
                              [dateFr(r['date']), '${r['number']}', '${r['journal']}', '${r['label'] ?? ''}', '${r['partner'] ?? ''}',
                                toDouble(r['debit']) > 0 ? _m(c, r['debit']) : '', toDouble(r['credit']) > 0 ? _m(c, r['credit']) : '', _m(c, r['balance'])],
                          ],
                          total: ['', '', '', 'Totaux / solde final', '', _m(c, data['debit']), _m(c, data['credit']), _m(c, data['closing'])],
                        ),
                      ],
                    ),
                  ),
                ]),
                const SizedBox(height: 8),
                if (rows.isEmpty)
                  EmptyState(icon: Icons.menu_book_outlined, title: tr('Aucun mouvement sur la période'))
                else
                  Card(
                    clipBehavior: Clip.antiAlias,
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: DataTable(
                        columnSpacing: 20,
                        columns: [
                          DataColumn(label: Text(tr('Date'))),
                          DataColumn(label: Text(tr('Pièce'))),
                          DataColumn(label: Text(tr('Libellé'))),
                          DataColumn(label: Text(tr('Débit')), numeric: true),
                          DataColumn(label: Text(tr('Crédit')), numeric: true),
                          DataColumn(label: Text(tr('Solde')), numeric: true),
                        ],
                        rows: [
                          for (final r in rows)
                            DataRow(cells: [
                              DataCell(Text(tr(dateFr(r['date'])))),
                              DataCell(Text(tr('${r['number']}'))),
                              DataCell(ConstrainedBox(
                                constraints: const BoxConstraints(maxWidth: 340),
                                child: Text(
                                  tr('${r['label'] ?? ''}${r['partner'] != null && !'${r['label'] ?? ''}'.contains('${r['partner']}') ? ' · ${r['partner']}' : ''}'),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              )),
                              DataCell(Text(tr(toDouble(r['debit']) > 0 ? _m(c, r['debit']) : ''), style: _num)),
                              DataCell(Text(tr(toDouble(r['credit']) > 0 ? _m(c, r['credit']) : ''), style: _num)),
                              DataCell(Text(tr(_m(c, r['balance'])), style: _num.copyWith(fontWeight: FontWeight.w600))),
                            ]),
                        ],
                      ),
                    ),
                  ),
                const SizedBox(height: 8),
                Align(
                  alignment: Alignment.centerRight,
                  child: Text(tr('Solde final : ${_m(c, data['closing'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                ),
              ]);
            },
          ),
        ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Paramètres
// ---------------------------------------------------------------------------

class _SettingsTab extends StatefulWidget {
  const _SettingsTab({required this.setup, required this.period, required this.onChanged});

  final Map<String, dynamic> setup;
  final Period period;
  final Future<void> Function() onChanged;

  @override
  State<_SettingsTab> createState() => _SettingsTabState();
}

class _SettingsTabState extends State<_SettingsTab> {
  bool _busy = false;

  Future<void> _run(Future<void> Function() fn) async {
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      await fn();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final accounts = List<Map>.from(widget.setup['accounts']);
    final mapping = Map<String, dynamic>.from(widget.setup['mapping']);
    final labels = Map<String, dynamic>.from(widget.setup['mapping_labels']);
    final lock = widget.setup['lock_date'] as String?;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;

    return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
      _ReferentialCard(setup: widget.setup, onChanged: widget.onChanged),
      const SizedBox(height: 12),
      _FiscalStartCard(setup: widget.setup, onChanged: widget.onChanged),
      const SizedBox(height: 16),
      _ReviewSettings(setup: widget.setup, onChanged: widget.onChanged),
      const SizedBox(height: 16),
      SectionCard(
        title: tr('Outils'),
        child: Wrap(spacing: 12, runSpacing: 12, children: [
          OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () => _run(() async {
                      final r = await api.post('compta/rebuild');
                      if (context.mounted) toast(context, tr('${r['data']['documents']} document(s) et ${r['data']['payments']} paiement(s) comptabilisés.'));
                    }),
            icon: const Icon(Icons.history),
            label: Text(tr('Comptabiliser l\'historique')),
          ),
          OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () => _run(() async {
                      final bytes = await api.download('compta/export', query: widget.period.query);
                      final where = await saveFile('ecritures_${isoDate(widget.period.from)}_${isoDate(widget.period.to)}.csv', bytes, mime: 'text/csv');
                      if (context.mounted) toast(context, tr('Export enregistré : $where'));
                    }),
            icon: const Icon(Icons.download),
            label: Text(tr('Exporter les écritures (${widget.period.label})')),
          ),
        ]),
      ),
      const SizedBox(height: 16),
      SectionCard(
        title: tr('Verrouillage de période'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(lock == null ? 'Aucune période verrouillée.' : 'Écritures verrouillées jusqu\'au ${dateFr(lock)} inclus.')),
          const SizedBox(height: 4),
          Text(tr('Après une déclaration ou un arrêté de comptes, verrouillez la période : plus aucune écriture ne peut y être ajoutée ou modifiée ; les corrections se font par contre-passation.'),
              style: TextStyle(fontSize: 12.5, color: YColors.muted)),
          const SizedBox(height: 10),
          Wrap(spacing: 8, children: [
            FilledButton.tonalIcon(
              onPressed: _busy
                  ? null
                  : () async {
                      final d = await showDatePicker(
                        context: context,
                        initialDate: lock != null ? DateTime.parse(lock) : DateTime(DateTime.now().year, DateTime.now().month, 0),
                        firstDate: DateTime(2015),
                        lastDate: DateTime.now(),
                      );
                      if (d == null) return;
                      await _run(() async {
                        await api.put('compta/settings', {'lock_date': isoDate(d)});
                        await widget.onChanged();
                      });
                    },
              icon: const Icon(Icons.lock_outline),
              label: Text(tr('Verrouiller jusqu\'au…')),
            ),
            if (lock != null)
              TextButton(
                onPressed: _busy
                    ? null
                    : () => _run(() async {
                          await api.put('compta/settings', {'lock_date': null});
                          await widget.onChanged();
                        }),
                child: Text(tr('Déverrouiller')),
              ),
          ]),
        ]),
      ),
      const SizedBox(height: 16),
      SectionCard(
        title: tr('Comptes des écritures automatiques'),
        child: Column(children: [
          for (final e in labels.entries)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(children: [
                Expanded(child: Text(tr('${e.value}'))),
                SizedBox(
                  width: 360,
                  child: DropdownButtonFormField<String>(
                    isExpanded: true,
                    initialValue: accounts.any((a) => a['code'] == mapping[e.key]) ? mapping[e.key] as String : null,
                    items: [for (final a in accounts) DropdownMenuItem(value: '${a['code']}', child: Text(tr('${a['code']} · ${a['name']}'), overflow: TextOverflow.ellipsis))],
                    onChanged: _busy
                        ? null
                        : (v) => _run(() async {
                              await api.put('compta/settings', {'mapping': {e.key: v}});
                              await widget.onChanged();
                            }),
                  ),
                ),
              ]),
            ),
        ]),
      ),
      const SizedBox(height: 16),
      SectionCard(
        title: tr('Plan comptable (${accounts.length} comptes)'),
        trailing: TextButton.icon(onPressed: _busy ? null : _addAccount, icon: const Icon(Icons.add, size: 18), label: Text(tr('Sous-compte'))),
        child: Column(children: [
          for (final cls in ['1', '2', '3', '4', '5', '6', '7', '8'])
            ExpansionTile(
              tilePadding: EdgeInsets.zero,
              title: Text(tr('Classe $cls · ${_classNames[cls]}')),
              children: [
                for (final a in accounts.where((a) => '${a['code']}'.startsWith(cls)))
                  ListTile(
                    dense: true,
                    contentPadding: const EdgeInsets.only(left: 8),
                    leading: SizedBox(width: 60, child: Text(tr('${a['code']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                    title: Text(tr('${a['name']}')),
                    trailing: a['is_system'] == true ? null : StatusChip(tr('Ajouté'), color: YColors.blue),
                  ),
              ],
            ),
        ]),
      ),
    ]);
  }

  static const _classNames = {
    '1': 'Ressources durables', '2': 'Actif immobilisé', '3': 'Stocks', '4': 'Tiers', '5': 'Trésorerie',
    '6': 'Charges', '7': 'Produits', '8': 'Hors activités ordinaires',
  };

  Future<void> _addAccount() async {
    final code = TextEditingController();
    final name = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Nouveau compte')),
        content: SizedBox(
          width: 420,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Field(label: tr('Numéro (ex. 5211 pour une banque, 4111 pour un client)'), child: TextField(controller: code, autofocus: true, inputFormatters: [FilteringTextInputFormatter.digitsOnly])),
            Field(label: tr('Intitulé'), child: TextField(controller: name)),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Créer'))),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    await _run(() async {
      await context.read<Session>().api.post('compta/accounts', {'code': code.text.trim(), 'name': name.text.trim()});
      await widget.onChanged();
    });
  }
}

/// Référentiel comptable : choisi d'après le pays, modifiable tant qu'aucune écriture n'existe.
class _ReferentialCard extends StatefulWidget {
  const _ReferentialCard({required this.setup, required this.onChanged});

  final Map<String, dynamic> setup;
  final Future<void> Function() onChanged;

  @override
  State<_ReferentialCard> createState() => _ReferentialCardState();
}

class _ReferentialCardState extends State<_ReferentialCard> {
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final cur = Map<String, dynamic>.from(widget.setup['referential'] as Map? ?? const {'key': 'syscohada', 'label': 'SYSCOHADA révisé'});
    final options = List<Map>.from(widget.setup['referentials'] as List? ?? const []);
    final canChange = widget.setup['can_change_referential'] == true && context.read<Session>().can('compta.manage');
    final hint = Theme.of(context).hintColor;
    return SectionCard(
      title: tr('Référentiel comptable'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr('${cur['label']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        if (cur['zone'] != null) Text(tr('${cur['zone']}'), style: TextStyle(color: hint, fontSize: 12.5)),
        const SizedBox(height: 6),
        Text(tr('${cur['description'] ?? ''}'), style: const TextStyle(fontSize: 13)),
        const SizedBox(height: 10),
        if (!canChange)
          Text(tr('Le référentiel se choisit avant la première écriture : il ne peut plus être changé.'), style: TextStyle(color: hint, fontSize: 12.5))
        else
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final o in options.where((o) => o['key'] != cur['key']))
              OutlinedButton(
                onPressed: _busy
                    ? null
                    : () async {
                        final ok = await confirm(context, tr('Passer au ${o['short']} ?'),
                            tr('${o['description']}\n\nLe plan de comptes est recréé et les comptes paramétrés (immobilisations, notes de frais, trésorerie, paie) sont convertis. Aucune écriture n\'existe encore.'),
                            ok: tr('Changer de référentiel'));
                        if (!ok || !context.mounted) return;
                        setState(() => _busy = true);
                        try {
                          await context.read<Session>().api.put('compta/settings', {'referential': o['key']});
                          await widget.onChanged();
                          if (context.mounted) toast(context, tr('Référentiel ${o['short']} appliqué.'));
                        } catch (e) {
                          if (context.mounted) toast(context, errorText(e), error: true);
                        } finally {
                          if (mounted) setState(() => _busy = false);
                        }
                      },
                child: Text(tr('${o['short']}')),
              ),
          ]),
      ]),
    );
  }
}

const _monthsFr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/// Exercice décalé : mois de début de l'exercice comptable.
class _FiscalStartCard extends StatefulWidget {
  const _FiscalStartCard({required this.setup, required this.onChanged});

  final Map<String, dynamic> setup;
  final Future<void> Function() onChanged;

  @override
  State<_FiscalStartCard> createState() => _FiscalStartCardState();
}

class _FiscalStartCardState extends State<_FiscalStartCard> {
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final cur = toDouble(widget.setup['fiscal_start_month'] ?? 1).round().clamp(1, 12);
    final canChange = widget.setup['can_change_fiscal_start'] != false && context.read<Session>().can('compta.manage');
    final hint = Theme.of(context).hintColor;
    final endMonth = _monthsFr[(cur + 10) % 12];
    return SectionCard(
      title: tr('Exercice comptable'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr(cur == 1 ? 'Année civile : du 1er janvier au 31 décembre' : 'Exercice décalé : du 1er ${_monthsFr[cur - 1]} au dernier jour de $endMonth de l\'année suivante'),
            style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        Row(children: [
          Text(tr('Début de l\'exercice : ')),
          const SizedBox(width: 8),
          DropdownButton<int>(
            value: cur,
            onChanged: !canChange || _busy
                ? null
                : (m) async {
                    if (m == null || m == cur) return;
                    setState(() => _busy = true);
                    try {
                      await context.read<Session>().api.put('compta/settings', {'fiscal_start_month': m});
                      await widget.onChanged();
                      if (context.mounted) toast(context, tr('Exercice : début en ${_monthsFr[m - 1]}.'));
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            items: [for (var m = 1; m <= 12; m++) DropdownMenuItem(value: m, child: Text(tr('1er ${_monthsFr[m - 1]}')))],
          ),
        ]),
        Text(
          tr(canChange
              ? 'Clôture, impôt, affectation, liasse, comparaison N-1 et périodes « Exercice » suivent ce calendrier.'
              : 'Un exercice est déjà clôturé : le calendrier ne peut plus être changé (rouvrez-le d\'abord).'),
          style: TextStyle(color: hint, fontSize: 12.5),
        ),
      ]),
    );
  }
}
