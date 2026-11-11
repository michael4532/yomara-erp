
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/report_charts.dart';
import 'appro_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';
import '../widgets/pdf_viewer.dart';

Color fondsStatusColor(String s) => switch (s) {
      'submitted' => YColors.warning,
      'question' => const Color(0xFF8E44AD),
      'approved' => YColors.blue,
      'partial' => const Color(0xFF0FA3B1),
      'provided' => const Color(0xFF0FA3B1),
      'closed' => YColors.success,
      'rejected' => YColors.danger,
      _ => YColors.muted,
    };

IconData _typeIcon(String t) => switch (t) { 'equipment' => Icons.construction_outlined, 'service' => Icons.handyman_outlined, _ => Icons.payments_outlined };

/// Appels de fonds : un service demande des moyens (argent, matériel, prestation) à sa hiérarchie ou au siège.
class FondsScreen extends StatefulWidget {
  const FondsScreen({super.key});

  @override
  State<FondsScreen> createState() => _FondsScreenState();
}

class _FondsScreenState extends State<FondsScreen> with SingleTickerProviderStateMixin {
  Map<String, dynamic>? _meta;
  Object? _error;
  int _reload = 0;
  late final Session _s = context.read<Session>();
  late final bool _manage = _s.can('fonds.manage');
  late final List<(String, String)> _tabs = [
    ('dashboard', 'Tableau de bord'),
    ('todo', 'À traiter'),
    ('mine', 'Mes demandes'),
    if (_manage || _s.can('fonds.view')) ('all', 'Toutes'),
    if (_manage) ('form', 'Modèle du formulaire'),
    if (_manage) ('settings', 'Réglages'),
  ];
  late final TabController _tc = TabController(length: _tabs.length, vsync: this);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await _s.api.get('fonds/meta');
      if (mounted) setState(() => (_meta = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _new() async {
    final r = await editFundRequest(context, _meta!, null);
    if (r != null && mounted) {
      setState(() => _reload++);
      await openFundRequest(context, _meta!, r['id'] as int);
      if (mounted) setState(() => _reload++);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _meta == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_meta == null) return const Center(child: CircularProgressIndicator());
    final canRequest = _s.can('fonds.request') || _manage;
    return Column(children: [
      PageHeader(
        title: tr('Appels de fonds'),
        subtitle: tr('Demandes de moyens des services à la hiérarchie ou au siège : décision, mise à disposition, réception'),
        actions: [if (canRequest) FilledButton.icon(onPressed: _new, icon: const Icon(Icons.add), label: Text(tr('Nouvel appel de fonds')))],
      ),
      TabBar(controller: _tc, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in _tabs) Tab(text: trn(t.$2))]),
      Expanded(
        child: TabBarView(controller: _tc, physics: const NeverScrollableScrollPhysics(), children: [
          for (final t in _tabs)
            switch (t.$1) {
              'dashboard' => _FondsDashboard(key: ValueKey('d$_reload'), meta: _meta!, onOpen: (id) async {
                  await openFundRequest(context, _meta!, id);
                  if (mounted) setState(() => _reload++);
                }),
              'settings' => _FondsSettings(meta: _meta!, onSaved: _load),
              'form' => _FondsFormTemplate(meta: _meta!, onSaved: _load),
              _ => _FundList(key: ValueKey('${t.$1}$_reload'), box: t.$1, meta: _meta!, onChanged: () => setState(() => _reload++)),
            },
        ]),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------------------------- liste

class _FundList extends StatefulWidget {
  const _FundList({super.key, required this.box, required this.meta, required this.onChanged});

  final String box;
  final Map<String, dynamic> meta;
  final VoidCallback onChanged;

  @override
  State<_FundList> createState() => _FundListState();
}

class _FundListState extends State<_FundList> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _q = '';
  String _status = '';
  String _todo = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final s = context.read<Session>();
      final List<Map<String, dynamic>> rows;
      if (widget.box == 'todo') {
        final a = await s.api.get('fonds/requests', query: {'box': 'decide'});
        final b = await s.api.get('fonds/requests', query: {'box': 'provide'});
        final c = await s.api.get('fonds/requests', query: {'box': 'mine', 'status': 'open'});
        final seen = <int>{};
        rows = [
          for (final x in [...a['data'] as List, ...b['data'] as List, ...(c['data'] as List).where((x) => ['question', 'provided', 'partial'].contains((x as Map)['status']))])
            if (seen.add((x as Map)['id'] as int)) Map<String, dynamic>.from(x),
        ];
      } else {
        final r = await s.api.get('fonds/requests', query: {if (widget.box == 'mine') 'box': 'mine', if (_status.isNotEmpty) 'status': _status});
        rows = [for (final x in r['data'] as List) asMap(x)];
      }
      if (mounted) setState(() => (_rows = rows, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _filter(String st) {
    setState(() => _status = st);
    _load();
  }

  String _todoKey(Map r) {
    final can = asMap(r['can']);
    if (can['decide'] == true) return 'decide';
    if (can['provide'] == true) return 'provide';
    if (can['answer'] == true) return 'answer';
    if (can['confirm'] == true) return 'confirm';
    return 'other';
  }

  String _todoLabel(Map r) {
    final can = asMap(r['can']);
    if (can['decide'] == true) return 'À décider';
    if (can['provide'] == true) return 'À fournir';
    if (can['answer'] == true) return 'Répondre';
    if (can['confirm'] == true) return 'Confirmer la réception';
    return '${r['status_label']}';
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _rows == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    final rows = _rows;
    if (rows == null) return const LoadingSkeleton();
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final types = asMap(widget.meta['types']);
    bool base(Map<String, dynamic> r) =>
        (_q.isEmpty || '${r['number']} ${r['title']} ${r['department']} ${r['requester']}'.toLowerCase().contains(_q.toLowerCase())) &&
        (_f['department'] == null || '${r['department'] ?? ''}' == _f['department']) &&
        (_f['requester'] == null || '${r['requester'] ?? ''}' == _f['requester']) &&
        (_f['approver'] == null || '${r['approver'] ?? ''}' == _f['approver']) &&
        (_f['kind'] == null || ((r['kinds'] as List?) ?? const []).map((k) => '$k').contains(_f['kind'])) &&
        (_f['priority'] == null || (_f['priority'] == 'urgent') == (r['priority'] == 'urgent' || r['priority'] == 'very_urgent')) &&
        (_f['late'] == null || r['late'] == true) &&
        (_period == null || _period!.contains(r['needed_by']));
    bool todoSt(Map<String, dynamic> r, String k) => k.isEmpty || _todoKey(r) == k;
    final todo = widget.box == 'todo';
    final shown = rows.where((r) => base(r) && (!todo || todoSt(r, _todo))).toList();
    final statuses = asMap(widget.meta['statuses']);
    final kinds = <String, String>{
      for (final r in rows)
        for (final k in ((r['kinds'] as List?) ?? const [])) '$k': '${types[k] is Map ? (types[k] as Map)['label'] ?? k : types[k] ?? k}',
    };
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          padding: EdgeInsets.zero,
          onSearch: (v) => setState(() => _q = v),
          search: _q,
          searchHint: 'N°, objet, service, demandeur…',
          statuses: todo
              ? const {'': 'Toutes', 'decide': 'À décider', 'provide': 'À fournir', 'answer': 'Répondre', 'confirm': 'Confirmer la réception'}
              : {'': 'Toutes', 'open': 'En cours', for (final k in ['draft', 'closed', 'rejected', 'cancelled']) k: '${statuses[k] ?? k}'},
          status: todo ? _todo : _status,
          onStatus: (v) => todo ? setState(() => _todo = v) : _filter(v),
          statusCounts: todo ? {for (final k in ['', 'decide', 'provide', 'answer', 'confirm']) k: rows.where((r) => base(r) && todoSt(r, k)).length} : null,
          filters: [
            FilterSpec('department', 'Service', filterOptions(rows, 'department'), icon: Icons.apartment_outlined),
            FilterSpec('requester', 'Demandeur', filterOptions(rows, 'requester'), icon: Icons.person_outline),
            FilterSpec('approver', 'Valideur', filterOptions(rows, 'approver'), icon: Icons.verified_user_outlined),
            FilterSpec('kind', 'Nature', kinds, icon: Icons.category_outlined),
            const FilterSpec('priority', 'Priorité', {'urgent': 'Urgent', 'normal': 'Normale'}, icon: Icons.priority_high),
            const FilterSpec('late', 'Échéance', {'late': 'En retard'}, icon: Icons.alarm),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: shown.length,
          countLabel: 'demande',
          onReset: () {
            final reload = _status.isNotEmpty;
            setState(() => (_q = '', _todo = '', _status = '', _f = {}, _period = null));
            if (reload) _load();
          },
        ),
        const SizedBox(height: 4),
        if (shown.isEmpty)
          EmptyState(
            icon: Icons.request_page_outlined,
            title: widget.box == 'todo' ? 'Rien à traiter' : 'Aucun appel de fonds',
            message: widget.box == 'todo' ? 'Les demandes à décider, à fournir ou à confirmer apparaissent ici.' : 'Un service exprime ses besoins (fonds, matériel, prestations) : la hiérarchie décide et un service les met à disposition.',
          ),
        for (final (i, r) in shown.indexed)
          AnimatedFadeIn(
            index: i % 10,
            child: Card(
              child: InkWell(
                borderRadius: BorderRadius.circular(16),
                onTap: () async {
                  await openFundRequest(context, widget.meta, r['id'] as int);
                  await _load();
                  widget.onChanged();
                },
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(children: [
                    CircleAvatar(
                      backgroundColor: fondsStatusColor('${r['status']}').withValues(alpha: .12),
                      child: Icon(_typeIcon((r['kinds'] as List).isEmpty ? 'funds' : '${(r['kinds'] as List).first}'), color: fondsStatusColor('${r['status']}')),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Flexible(child: Text(tr('${r['number'] ?? 'Brouillon'} · ${r['title']}'), style: const TextStyle(fontWeight: FontWeight.w700), overflow: TextOverflow.ellipsis)),
                          if (r['priority'] == 'urgent' || r['priority'] == 'very_urgent') ...[const SizedBox(width: 6), StatusChip(tr(r['priority'] == 'very_urgent' ? 'Très urgent' : 'Urgent'), color: YColors.danger)],
                        ]),
                        Text(
                          tr([
                            if (r['department'] != null) '${r['department']}',
                            '${r['requester']}',
                            if (r['approver'] != null) 'à ${r['approver']}',
                            if (r['needed_by'] != null) 'pour le ${dateFr(r['needed_by'])}',
                          ].join(' · ')),
                          style: TextStyle(color: r['late'] == true ? YColors.danger : hint, fontSize: 12.5),
                        ),
                      ]),
                    ),
                    Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(tr(money(toDouble(['approved', 'partial', 'provided', 'closed'].contains(r['status']) ? r['total_approved'] : r['total_requested']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                      const SizedBox(height: 4),
                      StatusChip(widget.box == 'todo' ? _todoLabel(r) : '${r['status_label']}', color: fondsStatusColor('${r['status']}')),
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

// ---------------------------------------------------------------------------------------------- formulaire

/// Crée ou modifie un appel de fonds (brouillon, ou demande à compléter).
Future<Map<String, dynamic>?> editFundRequest(BuildContext context, Map<String, dynamic> meta, Map<String, dynamic>? r) {
  return showFormDialog<Map<String, dynamic>>(context, title: r == null ? 'Nouvel appel de fonds' : 'Appel de fonds ${r['number'] ?? ''}', maxWidth: 908, builder: (_) => _FundForm(meta: meta, request: r));
}

class _FundForm extends StatefulWidget {
  const _FundForm({required this.meta, this.request});

  final Map<String, dynamic> meta;
  final Map<String, dynamic>? request;

  @override
  State<_FundForm> createState() => _FundFormState();
}

class _FundLineCtl {
  _FundLineCtl({this.id, this.type = 'funds', String desc = '', double qty = 1, double unit = 0, String note = ''})
      : desc = TextEditingController(text: desc),
        qty = TextEditingController(text: qty == qty.roundToDouble() ? '${qty.round()}' : '$qty'),
        unit = TextEditingController(text: unit > 0 ? '${unit.round()}' : ''),
        note = TextEditingController(text: note);
  final int? id;
  String type;
  final TextEditingController desc, qty, unit, note;
}

class _TaxCtl {
  _TaxCtl(String label, double rate, this.base)
      : label = TextEditingController(text: label),
        rate = TextEditingController(text: rate == 0 ? '' : (rate == rate.roundToDouble() ? '${rate.round()}' : '$rate'));
  final TextEditingController label, rate;
  String base;
}

/// Titre de rubrique du formulaire (comme les bandeaux du PDF).
class _FormSection extends StatelessWidget {
  const _FormSection(this.text);
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(tr(text).toUpperCase(), style: TextStyle(fontSize: 11.5, letterSpacing: .6, fontWeight: FontWeight.w800, color: Theme.of(context).colorScheme.primary)),
      );
}

class _FundFormState extends State<_FundForm> {
  late final r = widget.request;
  late final _title = TextEditingController(text: '${r?['title'] ?? ''}');
  late final _dept = TextEditingController(text: '${r?['department'] ?? ''}');
  late final _to = TextEditingController(text: '${r?['to_department'] ?? 'Direction générale'}');
  late final _purpose = TextEditingController(text: '${r?['purpose'] ?? ''}');
  late int? _approver = r?['approver_id'] as int?;
  late String _priority = '${r?['priority'] ?? 'normal'}';
  late String? _needed = r?['needed_by'] as String?;
  late final List<_FundLineCtl> _lines = r == null
      ? [_FundLineCtl()]
      : [
          for (final l in (r!['lines'] as List? ?? const []))
            _FundLineCtl(id: (l as Map)['id'] as int, type: '${l['type']}', desc: '${l['description']}', qty: toDouble(l['quantity']), unit: toDouble(l['unit_amount']), note: '${l['note'] ?? ''}'),
        ];
  bool _busy = false;
  // Formulaire complet (modèle de la société) : motif, émetteur / bénéficiaire, remise et taxes, paiement, pièces, remarques
  late final Map<String, dynamic> _form = asMap(widget.meta['settings']?['form']);
  late String? _motive = r?['motive'] as String?;
  late final _motiveOther = TextEditingController(text: '${r?['motive_other'] ?? ''}');
  late final _function = TextEditingController(text: '${r?['requester_function'] ?? ''}');
  late final _project = TextEditingController(text: '${r?['project_label'] ?? ''}');
  late final _beneficiary = TextEditingController(text: '${r?['beneficiary'] ?? ''}');
  late final _structure = TextEditingController(text: '${r?['beneficiary_structure'] ?? ''}');
  late String? _payment = r?['payment_method'] as String?;
  late final _paymentOther = TextEditingController(text: '${r?['payment_other'] ?? ''}');
  late final Set<String> _attachments = {for (final a in (r?['attachments'] as List? ?? const [])) '$a'};
  late final _attachmentsOther = TextEditingController(text: '${r?['attachments_other'] ?? ''}');
  late String _discountMode = '${r?['discount_mode'] ?? 'percent'}';
  late final _discount = TextEditingController(
      text: r != null ? _numText(toDouble(r!['discount_value'])) : (_form['default_discount'] == null ? '' : _numText(toDouble(_form['default_discount']))));
  late final List<_TaxCtl> _taxes = r != null
      ? [for (final t in (r!['taxes'] as List? ?? const [])) _TaxCtl('${(t as Map)['label']}', toDouble(t['rate']), '${t['base'] ?? 'net'}')]
      : [if (_form['default_vat'] != null && toDouble(_form['default_vat']) > 0) _TaxCtl('TVA', toDouble(_form['default_vat']), 'net')];
  late final _remarks = TextEditingController(text: '${r?['remarks'] ?? ''}');

  static String _numText(double v) => v == 0 ? '' : (v == v.roundToDouble() ? '${v.round()}' : '$v');
  bool _show(String section) => asMap(_form['sections'])[section] != false;

  double get _gross => _lines.fold(0, (s, l) => s + toDouble(l.qty.text) * toDouble(l.unit.text));
  double get _discountAmount {
    final v = toDouble(_discount.text);
    return _discountMode == 'amount' ? (v > _gross ? _gross : v) : _gross * (v.clamp(0, 100)) / 100;
  }

  double get _net => _gross - _discountAmount;
  double _taxAmount(_TaxCtl t) => (t.base == 'gross' ? _gross : _net) * toDouble(t.rate.text) / 100;
  double get _taxesTotal => _taxes.fold(0, (s, t) => s + _taxAmount(t));
  double get _total => _net + _taxesTotal;

  Future<void> _save({bool submit = false}) async {
    setState(() => _busy = true);
    final api = context.read<Session>().api;
    final body = {
      'title': _title.text.trim(), 'department': _dept.text.trim(), 'to_department': _to.text.trim(), 'approver_id': _approver, 'priority': _priority, 'needed_by': _needed,
      'purpose': _purpose.text.trim(),
      'motive': _motive, 'motive_other': _motive == 'other' ? _motiveOther.text.trim() : null,
      'requester_function': _function.text.trim(), 'project_label': _project.text.trim(), 'beneficiary': _beneficiary.text.trim(), 'beneficiary_structure': _structure.text.trim(),
      'payment_method': _payment, 'payment_other': _payment == 'other' ? _paymentOther.text.trim() : null,
      'attachments': _attachments.toList(), 'attachments_other': _attachmentsOther.text.trim(),
      'discount_mode': _discountMode, 'discount_value': toDouble(_discount.text),
      'taxes': [for (final t in _taxes) if (toDouble(t.rate.text) > 0) {'label': t.label.text.trim().isEmpty ? 'Taxe' : t.label.text.trim(), 'rate': toDouble(t.rate.text), 'base': t.base}],
      'remarks': _remarks.text.trim(),
      'lines': [
        for (final l in _lines.where((l) => l.desc.text.trim().isNotEmpty))
          {'id': l.id, 'type': l.type, 'description': l.desc.text.trim(), 'quantity': toDouble(l.qty.text) > 0 ? toDouble(l.qty.text) : 1, 'unit_amount': toDouble(l.unit.text), 'note': l.note.text.trim()},
      ],
    };
    try {
      var res = r == null ? await api.post('fonds/requests', body) : await api.put('fonds/requests/${r!['id']}', body);
      var data = asMap(res['data']);
      if (submit && data['status'] == 'draft') {
        res = await api.post('fonds/requests/${data['id']}/submit');
        data = asMap(res['data']);
      }
      if (mounted) {
        Navigator.pop(context, data);
        toast(context, submit ? 'Appel de fonds ${data['number']} envoyé à ${data['approver']}.' : 'Brouillon enregistré.');
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _pair(Widget a, Widget b) => MediaQuery.sizeOf(context).width < 640
      ? Column(children: [a, const SizedBox(height: 8), b, const SizedBox(height: 8)])
      : Padding(padding: const EdgeInsets.only(bottom: 8), child: Row(children: [Expanded(child: a), const SizedBox(width: 12), Expanded(child: b)]));

  /// Montant brut, remise (pourcentage ou montant), taxes (TVA et autres, sur le net ou le brut) et total à décaisser.
  Widget _amountsCard(String cur, List<TextInputFormatter> fmt) {
    final hint = Theme.of(context).hintColor;
    Widget row(String label, double v, {bool strong = false, String sign = ''}) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Row(children: [
            Expanded(child: Text(tr(label), style: TextStyle(fontWeight: strong ? FontWeight.w800 : FontWeight.w500, color: strong ? null : hint))),
            Text('$sign${money(v, cur)}', style: TextStyle(fontWeight: strong ? FontWeight.w800 : FontWeight.w600, fontFeatures: const [FontFeature.tabularFigures()])),
          ]),
        );
    final taxTypes = [for (final t in (_form['tax_types'] as List? ?? const [])) asMap(t)];
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .45), borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        row('Montant brut', _gross),
        Row(children: [
          Text(tr('Remise'), style: TextStyle(color: hint)),
          const SizedBox(width: 10),
          DropdownButton<String>(
            value: _discountMode,
            isDense: true,
            underline: const SizedBox(),
            items: [DropdownMenuItem(value: 'percent', child: Text(tr('en %'))), DropdownMenuItem(value: 'amount', child: Text(tr('en montant')))],
            onChanged: (v) => setState(() => _discountMode = v ?? 'percent'),
          ),
          const SizedBox(width: 8),
          SizedBox(width: 90, child: TextField(controller: _discount, inputFormatters: fmt, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, hintText: '0', suffixText: _discountMode == 'percent' ? '%' : null), onChanged: (_) => setState(() {}))),
          const Spacer(),
          Text(_discountAmount > 0 ? '- ${money(_discountAmount, cur)}' : '—', style: const TextStyle(fontWeight: FontWeight.w600)),
        ]),
        if (_discountAmount > 0) row('Net avant taxes', _net),
        for (var i = 0; i < _taxes.length; i++)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Row(children: [
              Expanded(child: TextField(controller: _taxes[i].label, decoration: InputDecoration(isDense: true, hintText: tr('Taxe')), onChanged: (_) => setState(() {}))),
              const SizedBox(width: 8),
              SizedBox(width: 70, child: TextField(controller: _taxes[i].rate, inputFormatters: fmt, textAlign: TextAlign.right, decoration: const InputDecoration(isDense: true, suffixText: '%'), onChanged: (_) => setState(() {}))),
              const SizedBox(width: 8),
              DropdownButton<String>(
                value: _taxes[i].base,
                isDense: true,
                underline: const SizedBox(),
                items: [DropdownMenuItem(value: 'net', child: Text(tr('sur le net'))), DropdownMenuItem(value: 'gross', child: Text(tr('sur le brut')))],
                onChanged: (v) => setState(() => _taxes[i].base = v ?? 'net'),
              ),
              SizedBox(width: 110, child: Text(money(_taxAmount(_taxes[i]), cur), textAlign: TextAlign.right)),
              IconButton(onPressed: () => setState(() => _taxes.removeAt(i)), icon: const Icon(Icons.close, size: 16), visualDensity: VisualDensity.compact),
            ]),
          ),
        Wrap(spacing: 6, children: [
          if (!_taxes.any((t) => t.label.text.trim().toUpperCase() == 'TVA'))
            ActionChip(avatar: const Icon(Icons.add, size: 16), label: Text(tr('TVA')), onPressed: () => setState(() => _taxes.add(_TaxCtl('TVA', toDouble(_form['default_vat'] ?? 18), 'net')))),
          for (final t in taxTypes)
            if (!_taxes.any((x) => x.label.text.trim() == '${t['label']}'))
              ActionChip(avatar: const Icon(Icons.add, size: 16), label: Text(tr('${t['label']}')), onPressed: () => setState(() => _taxes.add(_TaxCtl('${t['label']}', toDouble(t['rate']), '${t['base'] ?? 'net'}')))),
          ActionChip(avatar: const Icon(Icons.add, size: 16), label: Text(tr('Autre taxe')), onPressed: () => setState(() => _taxes.add(_TaxCtl('', 0, 'net')))),
        ]),
        if (_taxes.isNotEmpty) row('Total des taxes', _taxesTotal),
        const Divider(height: 14),
        row('Total à décaisser', _total, strong: true),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final depts = List<String>.from(widget.meta['settings']['departments'] as List? ?? const []);
    final approvers = List<Map>.from(widget.meta['approvers'] as List);
    final types = asMap(widget.meta['types']);
    final fmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    Widget deptField(TextEditingController ctl, String label) => Autocomplete<String>(
          initialValue: TextEditingValue(text: ctl.text),
          optionsBuilder: (v) => depts.where((d) => d.toLowerCase().contains(v.text.toLowerCase())),
          onSelected: (v) => ctl.text = v,
          fieldViewBuilder: (c, t, f, _) => TextField(controller: t, focusNode: f, onChanged: (v) => ctl.text = v, decoration: InputDecoration(labelText: trn(label))),
        );
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (r?['status'] == 'question')
            Card(color: const Color(0xFF8E44AD).withValues(alpha: .08), child: ListTile(leading: Icon(Icons.help_outline, color: Color(0xFF8E44AD)), title: Text(tr('Complétez la demande puis répondez à la question depuis la fiche.')))),
          TextField(controller: _title, autofocus: r == null, decoration: InputDecoration(labelText: tr('Objet (ex. Démarrage du chantier de Bingerville)'))),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: deptField(_dept, 'Service demandeur')),
            const SizedBox(width: 12),
            Expanded(child: deptField(_to, 'Adressé à (direction, siège)')),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(
              child: DropdownButtonFormField<int>(
                initialValue: approvers.any((a) => a['id'] == _approver) ? _approver : null,
                decoration: InputDecoration(labelText: tr('Responsable qui décide')),
                items: [for (final a in approvers) DropdownMenuItem(value: a['id'] as int, child: Text(tr('${a['name']}')))],
                onChanged: (v) => setState(() => _approver = v),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await pickDate(context, firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime(2100), initialDate: DateTime.tryParse(_needed ?? '') ?? DateTime.now().add(const Duration(days: 7)));
                  if (d != null) setState(() => _needed = isoDate(d));
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr(_needed == null ? 'Pour quand ?' : 'Pour le ${dateFr(_needed)}')),
              ),
            ),
          ]),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'normal', label: Text(tr('Normal'))),
                ButtonSegment(value: 'urgent', label: Text(tr('Urgent'))),
                ButtonSegment(value: 'very_urgent', label: Text(tr('Très urgent'))),
              ],
              selected: {_priority},
              showSelectedIcon: false,
              onSelectionChanged: (v) => setState(() => _priority = v.first),
            ),
          ),
          const SizedBox(height: 8),
          TextField(controller: _purpose, maxLines: 3, decoration: InputDecoration(labelText: tr('Justification (pourquoi ces moyens, pour quoi faire)'), alignLabelWithHint: true)),
          if (_show('motive')) ...[
            const SizedBox(height: 14),
            _FormSection('Motif de l\'appel de fonds'),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final m in List<String>.from(_form['motives'] as List? ?? const []))
                ChoiceChip(label: Text(tr(m)), selected: _motive == m, onSelected: (v) => setState(() => _motive = v ? m : null)),
              ChoiceChip(label: Text(tr('Autre')), selected: _motive == 'other', onSelected: (v) => setState(() => _motive = v ? 'other' : null)),
            ]),
            if (_motive == 'other') TextField(controller: _motiveOther, decoration: InputDecoration(labelText: tr('Précisez le motif'))),
          ],
          if (_show('beneficiary')) ...[
            const SizedBox(height: 14),
            _FormSection('Émetteur de la demande / Bénéficiaire'),
            _pair(TextField(controller: _function, decoration: InputDecoration(labelText: tr('Votre fonction'))), TextField(controller: _project, decoration: InputDecoration(labelText: tr('Projet / activité')))),
            _pair(TextField(controller: _beneficiary, decoration: InputDecoration(labelText: tr('Bénéficiaire (personne ou fournisseur payé)'))), TextField(controller: _structure, decoration: InputDecoration(labelText: tr('Structure du bénéficiaire')))),
          ],
          const SizedBox(height: 14),
          Row(children: [
            Expanded(child: Text(tr('Besoins'), style: TextStyle(fontWeight: FontWeight.w800))),
            Text(tr('Brut ${money(_gross, cur)}'), style: const TextStyle(fontWeight: FontWeight.w800)),
          ]),
          const SizedBox(height: 6),
          for (var i = 0; i < _lines.length; i++)
            Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.fromLTRB(10, 6, 2, 8),
              decoration: BoxDecoration(border: Border.all(color: Theme.of(context).dividerColor), borderRadius: BorderRadius.circular(10)),
              child: Column(children: [
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Padding(padding: const EdgeInsets.only(top: 14, right: 8), child: Text(i < 9 ? '0${i + 1}' : '${i + 1}', style: const TextStyle(fontWeight: FontWeight.w700))),
                  Expanded(
                    child: Column(children: [
                      TextField(controller: _lines[i].desc, decoration: InputDecoration(isDense: true, hintText: tr('Description de la dépense (ex. Carburant groupe électrogène)'))),
                      TextField(controller: _lines[i].note, style: const TextStyle(fontSize: 12.5), decoration: InputDecoration(isDense: true, hintText: tr('Précision (facultatif)'))),
                    ]),
                  ),
                  IconButton(onPressed: _lines.length > 1 ? () => setState(() => _lines.removeAt(i)) : null, icon: const Icon(Icons.close, size: 18)),
                ]),
                const SizedBox(height: 6),
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, alignment: WrapAlignment.end, children: [
                    SizedBox(
                      width: 170,
                      child: DropdownButtonFormField<String>(
                        isDense: true,
                        initialValue: _lines[i].type,
                        items: [for (final t in types.entries) DropdownMenuItem(value: t.key, child: Text(tr('${t.value}'), overflow: TextOverflow.ellipsis))],
                        onChanged: (v) => setState(() => _lines[i].type = v ?? 'funds'),
                      ),
                    ),
                    SizedBox(width: 80, child: TextField(controller: _lines[i].qty, inputFormatters: fmt, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Qté')), onChanged: (_) => setState(() {}))),
                    SizedBox(width: 140, child: TextField(controller: _lines[i].unit, inputFormatters: fmt, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Prix unitaire')), onChanged: (_) => setState(() {}))),
                    SizedBox(width: 130, child: Text(tr(money(toDouble(_lines[i].qty.text) * toDouble(_lines[i].unit.text), cur)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w700))),
                  ]),
                ),
              ]),
            ),
          Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => setState(() => _lines.add(_FundLineCtl())), icon: const Icon(Icons.add, size: 18), label: Text(tr('Ajouter un besoin')))),
          const SizedBox(height: 6),
          _amountsCard(cur, fmt),
          if (_show('payment')) ...[
            const SizedBox(height: 14),
            _FormSection('Modalités de paiement'),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final m in List<String>.from(_form['payment_methods'] as List? ?? const []))
                ChoiceChip(label: Text(tr(m)), selected: _payment == m, onSelected: (v) => setState(() => _payment = v ? m : null)),
              ChoiceChip(label: Text(tr('Autre')), selected: _payment == 'other', onSelected: (v) => setState(() => _payment = v ? 'other' : null)),
            ]),
            if (_payment == 'other') TextField(controller: _paymentOther, decoration: InputDecoration(labelText: tr('Précisez le mode de paiement'))),
            const SizedBox(height: 10),
            Text(tr('Pièces jointes'), style: TextStyle(fontSize: 12.5, color: Theme.of(context).hintColor, fontWeight: FontWeight.w600)),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final a in List<String>.from(_form['attachments'] as List? ?? const []))
                FilterChip(label: Text(tr(a)), selected: _attachments.contains(a), onSelected: (v) => setState(() => v ? _attachments.add(a) : _attachments.remove(a))),
            ]),
            TextField(controller: _attachmentsOther, decoration: InputDecoration(labelText: tr('Autre pièce (facultatif)'))),
          ],
          if (_show('remarks')) ...[
            const SizedBox(height: 14),
            TextField(controller: _remarks, maxLines: 2, decoration: InputDecoration(labelText: tr('Remarques / suggestions'), alignLabelWithHint: true)),
          ],
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
            if (r == null || r!['status'] == 'draft') OutlinedButton(onPressed: _busy ? null : () => _save(), child: Text(tr('Enregistrer le brouillon'))),
            FilledButton.icon(
              onPressed: _busy ? null : () => _save(submit: r == null || r!['status'] == 'draft'),
              icon: Icon(r?['status'] == 'question' ? Icons.save_outlined : Icons.send, size: 18),
              label: Text(tr(r?['status'] == 'question' ? 'Enregistrer' : 'Envoyer au responsable')),
            ),
          ]),
        ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------------------------- fiche

Future<void> openFundRequest(BuildContext context, Map<String, dynamic> meta, int id) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => _FundDetail(meta: meta, id: id)));

class _FundDetail extends StatefulWidget {
  const _FundDetail({required this.meta, required this.id});

  final Map<String, dynamic> meta;
  final int id;

  @override
  State<_FundDetail> createState() => _FundDetailState();
}

class _FundDetailState extends State<_FundDetail> {
  Map<String, dynamic>? _r;
  Object? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('fonds/requests/${widget.id}');
      if (mounted) setState(() => (_r = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _act(String action, [Map<String, dynamic> body = const {}, String? done]) async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('fonds/requests/${widget.id}/$action', body);
      if (mounted) {
        setState(() => _r = asMap(r['data']));
        if (done != null) toast(context, done);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<String?> _ask(String title, String label, {bool required = true, String ok = 'Envoyer'}) async {
    final ctl = TextEditingController();
    final v = await showDialog<String>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr(title)),
        content: SizedBox(width: 480, child: TextField(controller: ctl, autofocus: true, maxLines: 4, decoration: InputDecoration(labelText: trn(label)))),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, ctl.text.trim()), child: Text(tr(ok)))],
      ),
    );
    if (v == null || (required && v.isEmpty)) return null;
    return v;
  }

  Future<void> _pdf() async {
    try {
      final bytes = await context.read<Session>().api.download('fonds/requests/${widget.id}/pdf');
      await previewPdf(name: 'Appel de fonds ${_r?['number'] ?? ''}', onLayout: (_) async => Uint8List.fromList(bytes));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _approve() async {
    final r = _r!;
    final lines = List<Map>.from(r['lines'] as List);
    final qty = {for (final l in lines) l['id'] as int: TextEditingController(text: _num(toDouble(l['quantity'])))};
    final amt = {for (final l in lines) l['id'] as int: TextEditingController(text: '${toDouble(l['amount']).round()}')};
    final providers = List<Map>.from(widget.meta['providers'] as List);
    final dept = TextEditingController(text: '${widget.meta['settings']['providers']?['funds'] ?? 'Direction financière'}');
    final note = TextEditingController();
    int? provider = providers.isNotEmpty ? providers.first['id'] as int : null;
    final cur = context.read<Session>().currency;
    final ok = await showFormDialog<bool>(context, title: 'Approuver l\'appel de fonds', maxWidth: 768, builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(tr('Montants accordés (modifiez pour accorder moins) :'), style: TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              for (final l in lines)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(children: [
                    Expanded(child: Text(tr('${l['description']}\ndemandé : ${_num(toDouble(l['quantity']))} × ${money(toDouble(l['unit_amount']), cur)} = ${money(toDouble(l['amount']), cur)}'), style: const TextStyle(fontSize: 13))),
                    SizedBox(
                      width: 80,
                      child: TextField(
                        controller: qty[l['id']],
                        textAlign: TextAlign.right,
                        decoration: InputDecoration(isDense: true, labelText: tr('Qté')),
                        onChanged: (v) => amt[l['id']]!.text = '${(toDouble(v) * toDouble(l['unit_amount'])).round()}',
                      ),
                    ),
                    const SizedBox(width: 8),
                    SizedBox(width: 130, child: TextField(controller: amt[l['id']], textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Montant accordé')))),
                  ]),
                ),
              const Divider(),
              DropdownButtonFormField<int>(
                initialValue: provider,
                decoration: InputDecoration(labelText: tr('Personne chargée de mettre à disposition')),
                items: [for (final p in providers) DropdownMenuItem(value: p['id'] as int, child: Text(tr('${p['name']}')))],
                onChanged: (v) => set(() => provider = v),
              ),
              TextField(controller: dept, decoration: InputDecoration(labelText: tr('Service qui fournit (ex. Direction financière, Logistique)'))),
              TextField(controller: note, maxLines: 2, decoration: InputDecoration(labelText: tr('Commentaire (facultatif)'))),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Approuver'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true) return;
    await _act('approve', {
      'lines': [for (final l in lines) {'id': l['id'], 'approved_quantity': toDouble(qty[l['id']]!.text), 'approved_amount': toDouble(amt[l['id']]!.text)}],
      'provider_id': provider, 'provider_department': dept.text.trim(), 'note': note.text.trim().isEmpty ? null : note.text.trim(),
    }, 'Appel de fonds approuvé.');
  }

  Future<void> _provide() async {
    final r = _r!;
    final lines = List<Map>.from(r['lines'] as List).where((l) => toDouble(l['approved_amount']) - toDouble(l['provided_amount']) > 0.004 || toDouble(l['approved_quantity']) - toDouble(l['provided_quantity']) > 0.0005).toList();
    final pick = {for (final l in lines) l['id'] as int: true};
    final amt = {for (final l in lines) l['id'] as int: TextEditingController(text: '${(toDouble(l['approved_amount']) - toDouble(l['provided_amount'])).round()}')};
    final methods = asMap(widget.meta['methods']);
    final treasury = List<Map>.from(widget.meta['treasury'] as List? ?? const []);
    var method = 'bank_transfer';
    String? src, dst;
    final ref = TextEditingController();
    final note = TextEditingController();
    final cur = context.read<Session>().currency;
    final ok = await showFormDialog<bool>(context, title: 'Mise à disposition', maxWidth: 688, builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              for (final l in lines)
                Row(children: [
                  Checkbox(value: pick[l['id']], onChanged: (v) => set(() => pick[l['id'] as int] = v ?? false)),
                  Expanded(child: Text(tr('${l['description']}'), overflow: TextOverflow.ellipsis)),
                  SizedBox(width: 140, child: TextField(controller: amt[l['id']], enabled: pick[l['id']], textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Montant')))),
                ]),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: method,
                decoration: InputDecoration(labelText: tr('Moyen')),
                items: [for (final m in methods.entries) DropdownMenuItem(value: m.key, child: Text(tr('${m.value}')))],
                onChanged: (v) => set(() => method = v ?? 'other'),
              ),
              if (treasury.isNotEmpty && ['bank_transfer', 'cash', 'mobile_money', 'cheque'].contains(method)) ...[
                Row(children: [
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      initialValue: src,
                      decoration: InputDecoration(labelText: tr('Depuis le compte (siège)')),
                      items: [for (final a in treasury) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}'), overflow: TextOverflow.ellipsis))],
                      onChanged: (v) => set(() => src = v),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      initialValue: dst,
                      decoration: InputDecoration(labelText: tr('Vers le compte (service)')),
                      items: [for (final a in treasury) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}'), overflow: TextOverflow.ellipsis))],
                      onChanged: (v) => set(() => dst = v),
                    ),
                  ),
                ]),
                Text(tr('Avec les deux comptes, un virement interne est enregistré en trésorerie (et en comptabilité).'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
              ],
              TextField(controller: ref, decoration: InputDecoration(labelText: tr('Référence (n° de virement, bon de sortie…)'))),
              TextField(controller: note, decoration: InputDecoration(labelText: tr('Commentaire (facultatif)'))),
              const SizedBox(height: 8),
              Text(tr('Total : ${money(lines.where((l) => pick[l['id']] == true).fold<double>(0, (s, l) => s + toDouble(amt[l['id']]!.text)), cur)}'), style: const TextStyle(fontWeight: FontWeight.w800)),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true) return;
    await _act('provide', {
      'items': [
        for (final l in lines)
          if (pick[l['id']] == true) {'id': l['id'], 'amount': toDouble(amt[l['id']]!.text)},
      ],
      'method': method, 'reference': ref.text.trim().isEmpty ? null : ref.text.trim(), 'source_account_id': src, 'dest_account_id': dst, 'note': note.text.trim().isEmpty ? null : note.text.trim(),
    }, 'Mise à disposition enregistrée : le service est prévenu.');
  }

  static String _num(double v) => v == v.roundToDouble() ? '${v.round()}' : v.toStringAsFixed(2);

  /// Motif, bénéficiaire, paiement, pièces et cadre de la comptabilité (formulaire complet).
  List<Widget> _formInfo(Map<String, dynamic> r, Color hint) {
    String? motive = r['motive'] == 'other' ? (r['motive_other'] as String? ?? 'Autre') : r['motive'] as String?;
    final pay = r['payment_method'] == 'other' ? (r['payment_other'] as String? ?? 'Autre') : r['payment_method'] as String?;
    final att = [...List<String>.from((r['attachments'] as List? ?? const []).map((e) => '$e')), if ('${r['attachments_other'] ?? ''}'.isNotEmpty) '${r['attachments_other']}'];
    String yn(dynamic v, String yes, String no) => v == null ? 'non vérifié' : (v == true ? yes : no);
    final items = <(IconData, String)>[
      if (motive != null) (Icons.label_outline, 'Motif : $motive'),
      if ('${r['requester_function'] ?? ''}'.isNotEmpty) (Icons.badge_outlined, 'Fonction : ${r['requester_function']}'),
      if ('${r['project_label'] ?? ''}'.isNotEmpty) (Icons.work_outline, 'Projet : ${r['project_label']}'),
      if ('${r['beneficiary'] ?? ''}'.isNotEmpty) (Icons.person_pin_outlined, 'Bénéficiaire : ${r['beneficiary']}${'${r['beneficiary_structure'] ?? ''}'.isNotEmpty ? ' (${r['beneficiary_structure']})' : ''}'),
      if (pay != null) (Icons.payments_outlined, 'Paiement : $pay'),
      if (att.isNotEmpty) (Icons.attach_file, 'Pièces : ${att.join(', ')}'),
      if (r['budget_available'] != null || r['documents_compliant'] != null)
        (Icons.fact_check_outlined, 'Comptabilité : budget ${yn(r['budget_available'], 'disponible', 'indisponible')}, pièces ${yn(r['documents_compliant'], 'conformes', 'non conformes')}'),
    ];
    return [
      if (items.isNotEmpty) const SizedBox(height: 10),
      if (items.isNotEmpty)
        Wrap(spacing: 18, runSpacing: 6, children: [
          for (final i in items) Row(mainAxisSize: MainAxisSize.min, children: [Icon(i.$1, size: 16, color: hint), const SizedBox(width: 6), Flexible(child: Text(tr(i.$2)))]),
        ]),
      if ('${r['remarks'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Remarques : ${r['remarks']}'), style: TextStyle(color: hint))),
    ];
  }

  /// Cadre réservé à la comptabilité : budget disponible, conformité des pièces, observation.
  Future<void> _accounting(Map<String, dynamic> r) async {
    bool? budget = r['budget_available'] as bool?;
    bool? docs = r['documents_compliant'] as bool?;
    final note = TextEditingController(text: '${r['accounting_note'] ?? ''}');
    Widget tri(StateSetter set, String label, bool? v, String yes, String no, void Function(bool?) on) => Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(label), style: const TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 6),
            SegmentedButton<int>(
              segments: [ButtonSegment(value: 1, label: Text(tr(yes))), ButtonSegment(value: 0, label: Text(tr(no))), ButtonSegment(value: -1, label: Text(tr('Non vérifié')))],
              selected: {v == null ? -1 : (v ? 1 : 0)},
              showSelectedIcon: false,
              onSelectionChanged: (x) => set(() => on(x.first == -1 ? null : x.first == 1)),
            ),
          ]),
        );
    final ok = await showDialog<bool>(
      context: context,
      builder: (dc) => StatefulBuilder(
        builder: (dc, set) => YAlertDialog(
          title: Text(tr('Cadre réservé à la comptabilité')),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              tri(set, 'Budget disponible', budget, 'Oui', 'Non', (v) => budget = v),
              tri(set, 'Vérification des pièces', docs, 'Conforme', 'Non conforme', (v) => docs = v),
              TextField(controller: note, maxLines: 2, decoration: InputDecoration(labelText: tr('Observation (facultatif)'))),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    );
    if (ok == true) await _act('accounting', {'budget_available': budget, 'documents_compliant': docs, 'accounting_note': note.text.trim()}, 'Cadre de la comptabilité enregistré.');
  }

  @override
  Widget build(BuildContext context) {
    final r = _r;
    return Scaffold(
      appBar: AppBar(title: Text(tr(r == null ? 'Appel de fonds' : '${r['number'] ?? 'Brouillon'} · ${r['title']}')), actions: [
        if (r != null) IconButton(tooltip: tr('Formulaire PDF'), onPressed: _pdf, icon: const Icon(Icons.picture_as_pdf_outlined)),
        if (r != null) IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
      ]),
      body: _error != null && r == null
          ? OnlineOnlyNotice(error: _error!, onRetry: _load)
          : r == null
              ? const Center(child: CircularProgressIndicator())
              : _body(context, r),
    );
  }

  Widget _body(BuildContext context, Map<String, dynamic> r) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final can = asMap(r['can']);
    final lines = List<Map>.from(r['lines'] as List);
    final events = List<Map>.from(r['events'] as List).reversed.toList();
    final decided = ['approved', 'partial', 'provided', 'closed'].contains(r['status']);
    final color = fondsStatusColor('${r['status']}');
    Widget amount(String l, dynamic v, {Color? c}) => Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(l), style: TextStyle(color: hint, fontSize: 12)),
            Text(tr(money(toDouble(v), cur)), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18, color: c)),
          ]),
        );
    return ListView(padding: const EdgeInsets.all(16), children: [
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              StatusChip('${r['status_label']}', color: color),
              if (r['priority'] == 'urgent' || r['priority'] == 'very_urgent') ...[const SizedBox(width: 6), StatusChip(tr(r['priority'] == 'very_urgent' ? 'Très urgent' : 'Urgent'), color: YColors.danger)],
              if (r['late'] == true) ...[const SizedBox(width: 6), StatusChip(tr('En retard'), color: YColors.danger)],
              const Spacer(),
              if (r['needed_by'] != null) Text(tr('Pour le ${dateFr(r['needed_by'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 10),
            Wrap(spacing: 24, runSpacing: 6, children: [
              Text(tr('Service : ${r['department'] ?? '—'}')),
              Text(tr('Demandeur : ${r['requester']}')),
              Text(tr('Adressé à : ${r['to_department'] ?? '—'}${r['approver'] != null ? ' (${r['approver']})' : ''}')),
              if (r['provider'] != null || r['provider_department'] != null) Text(tr('Fourni par : ${r['provider_department'] ?? ''}${r['provider'] != null ? ' (${r['provider']})' : ''}')),
            ]),
            if ('${r['purpose'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('${r['purpose']}'))),
            ..._formInfo(r, hint),
            const Divider(height: 24),
            Row(children: [
              amount('Demandé', r['total_requested']),
              amount('Accordé', r['total_approved'], c: decided ? YColors.blue : hint),
              amount('Fourni', r['total_provided'], c: toDouble(r['total_provided']) > 0 ? YColors.success : hint),
            ]),
            if (toDouble(r['total_discount']) > 0 || toDouble(r['total_taxes']) > 0)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  tr(['Brut ${money(toDouble(r['total_gross']), cur)}', if (toDouble(r['total_discount']) > 0) 'remise ${money(toDouble(r['total_discount']), cur)}',
                    if (toDouble(r['total_taxes']) > 0) 'taxes ${money(toDouble(r['total_taxes']), cur)}'].join(' · ')),
                  style: TextStyle(color: hint, fontSize: 12.5),
                ),
              ),
            if (r['decision_note'] != null)
              Container(
                margin: const EdgeInsets.only(top: 10),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: color.withValues(alpha: .08), borderRadius: BorderRadius.circular(10)),
                child: Text(tr('Décision : ${r['decision_note']}')),
              ),
          ]),
        ),
      ),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 8, children: [
        if (can['edit'] == true)
          OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final x = await editFundRequest(context, widget.meta, r);
                    if (x != null) await _load();
                  },
            icon: const Icon(Icons.edit_outlined),
            label: Text(tr('Modifier')),
          ),
        if (can['submit'] == true) FilledButton.icon(onPressed: _busy ? null : () => _act('submit', const {}, 'Envoyé au responsable.'), icon: const Icon(Icons.send), label: Text(tr('Envoyer au responsable'))),
        if (r['status'] != 'draft' && r['status'] != 'cancelled' && (r['lines'] as List? ?? const []).any((l) => (l as Map)['type'] != 'funds')) CompareButton(type: 'fund', id: '${r['id']}'),
        if (can['answer'] == true)
          FilledButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final v = await _ask('Répondre au responsable', 'Votre explication');
                    if (v != null) await _act('answer', {'body': v}, 'Réponse envoyée : la demande repart en décision.');
                  },
            icon: const Icon(Icons.reply),
            label: Text(tr('Répondre')),
          ),
        if (can['decide'] == true) ...[
          FilledButton.icon(onPressed: _busy ? null : _approve, icon: const Icon(Icons.check), label: Text(tr('Approuver'))),
          OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final v = await _ask('Demander une explication', 'Votre question au service');
                    if (v != null) await _act('question', {'body': v}, 'Question envoyée au demandeur.');
                  },
            icon: const Icon(Icons.help_outline),
            label: Text(tr('Demander une explication')),
          ),
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            onPressed: _busy
                ? null
                : () async {
                    final v = await _ask('Refuser l\'appel de fonds', 'Motif du refus', ok: tr('Refuser'));
                    if (v != null) await _act('reject', {'note': v}, 'Demande refusée.');
                  },
            icon: const Icon(Icons.close),
            label: Text(tr('Refuser')),
          ),
        ],
        if (can['provide'] == true) FilledButton.icon(onPressed: _busy ? null : _provide, icon: const Icon(Icons.local_shipping_outlined), label: Text(tr('Mettre à disposition'))),
        if (can['accounting'] == true) OutlinedButton.icon(onPressed: _busy ? null : () => _accounting(r), icon: const Icon(Icons.fact_check_outlined), label: Text(tr('Comptabilité'))),
        if (can['confirm'] == true)
          FilledButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final v = await _ask('Confirmer la réception', 'Commentaire (facultatif)', required: false, ok: tr('Confirmer'));
                    if (v != null) await _act('confirm', {'note': v.isEmpty ? null : v}, 'Réception confirmée.');
                  },
            icon: const Icon(Icons.task_alt),
            label: Text(tr('Confirmer la réception')),
          ),
        OutlinedButton.icon(
          onPressed: _busy
              ? null
              : () async {
                  final v = await _ask('Message', 'Votre message (demandeur, responsable et fournisseur sont prévenus)');
                  if (v != null) await _act('comment', {'body': v});
                },
          icon: const Icon(Icons.chat_bubble_outline),
          label: Text(tr('Message')),
        ),
        if (can['cancel'] == true)
          TextButton(
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            onPressed: _busy
                ? null
                : () async {
                    if (await confirm(context, tr('Annuler la demande ?'), tr('Le responsable est prévenu.'), ok: tr('Annuler la demande'), danger: true)) await _act('cancel', const {}, 'Demande annulée.');
                  },
            child: Text(tr('Annuler la demande')),
          ),
      ]),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Besoins'),
        child: Column(children: [
          for (final l in lines)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(children: [
                Icon(_typeIcon('${l['type']}'), size: 20, color: hint),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${l['description']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(tr('${l['type_label']} · ${_num(toDouble(l['quantity']))} × ${money(toDouble(l['unit_amount']), cur)}${l['note'] != null ? ' · ${l['note']}' : ''}'), style: TextStyle(color: hint, fontSize: 12.5)),
                  ]),
                ),
                SizedBox(width: 120, child: Text(tr(money(toDouble(l['amount']), cur)), textAlign: TextAlign.right)),
                if (decided) ...[
                  SizedBox(width: 120, child: Text(tr(money(toDouble(l['approved_amount']), cur)), textAlign: TextAlign.right, style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w700))),
                  SizedBox(width: 120, child: Text(tr(money(toDouble(l['provided_amount']), cur)), textAlign: TextAlign.right, style: TextStyle(color: toDouble(l['provided_amount']) > 0 ? YColors.success : hint, fontWeight: FontWeight.w700))),
                ],
              ]),
            ),
          if (decided)
            Row(children: [
              const Spacer(),
              SizedBox(width: 120, child: Text(tr('Demandé'), textAlign: TextAlign.right, style: TextStyle(color: hint, fontSize: 11.5))),
              SizedBox(width: 120, child: Text(tr('Accordé'), textAlign: TextAlign.right, style: TextStyle(color: hint, fontSize: 11.5))),
              SizedBox(width: 120, child: Text(tr('Fourni'), textAlign: TextAlign.right, style: TextStyle(color: hint, fontSize: 11.5))),
            ]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Historique'),
        child: Column(children: [
          for (final e in events)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: CircleAvatar(radius: 16, backgroundColor: YColors.blue.withValues(alpha: .1), child: Icon(_eventIcon('${e['type']}'), size: 16, color: YColors.blue)),
              title: Text(tr('${e['label']}${e['user'] != null ? ' · ${e['user']}' : ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text(tr([
                if (e['type'] == 'provided' && e['data'] is Map) '${money(toDouble(e['data']['total']), cur)} · ${e['data']['method_label']}${e['data']['reference'] != null ? ' · réf. ${e['data']['reference']}' : ''}',
                if (e['type'] == 'approved' && e['data'] is Map) '${money(toDouble(e['data']['approved']), cur)} accordés sur ${money(toDouble(e['data']['requested']), cur)}',
                if (e['body'] != null) '${e['body']}',
              ].join('\n'))),
              trailing: Text(tr(DateFormat('dd/MM/yyyy HH:mm').format(DateTime.parse('${e['at']}').toLocal())), style: TextStyle(color: hint, fontSize: 12)),
            ),
        ]),
      ),
    ]);
  }

  static IconData _eventIcon(String t) => switch (t) {
        'submitted' => Icons.send,
        'question' => Icons.help_outline,
        'answer' => Icons.reply,
        'approved' => Icons.check,
        'rejected' => Icons.close,
        'provided' => Icons.local_shipping_outlined,
        'closed' => Icons.task_alt,
        'comment' => Icons.chat_bubble_outline,
        'reminder' => Icons.alarm,
        'cancelled' => Icons.block,
        _ => Icons.edit_outlined,
      };
}

// ---------------------------------------------------------------------------------------------- tableau de bord

class _FondsDashboard extends StatelessWidget {
  const _FondsDashboard({super.key, required this.meta, required this.onOpen});

  final Map<String, dynamic> meta;
  final void Function(int id) onOpen;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder(
      future: context.read<Session>().api.get('fonds/dashboard'),
      builder: (c, snap) {
        if (snap.hasError) return OnlineOnlyNotice(error: snap.error!, onRetry: () {});
        if (!snap.hasData) return const LoadingSkeleton();
        final d = asMap((snap.data as Map)['data']);
        final k = asMap(d['kpis']);
        final mine = asMap(d['mine']);
        final cur = context.watch<Session>().currency;
        final hint = Theme.of(c).hintColor;
        String hours(dynamic h) => h == null ? '—' : (toDouble(h) < 48 ? '${toDouble(h).round()} h' : '${(toDouble(h) / 24).toStringAsFixed(1)} j');
        Widget kpi(String l, String v, IconData ic, Color col, {String? sub}) => KpiBox(
              width: 250,
              child: StatTile(label: l, value: v, detail: sub, icon: ic, color: col),
            );
        final months = List<Map>.from(d['months'] as List);
        final chart = ChartData(
          [for (final m in months) DateFormat('MMM yy', I18n.dateLocale).format(DateTime.parse('${m['month']}-01'))],
          [ChartSeries('Demandé', [for (final m in months) toDouble(m['requested'])]), ChartSeries('Fourni', [for (final m in months) toDouble(m['provided'])])],
          'money',
        );
        final todo = [
          if (toDouble(mine['decide']) > 0) '${mine['decide']} à décider',
          if (toDouble(mine['provide']) > 0) '${mine['provide']} à fournir',
          if (toDouble(mine['answer']) > 0) '${mine['answer']} explication(s) à donner',
          if (toDouble(mine['confirm']) > 0) '${mine['confirm']} réception(s) à confirmer',
        ];
        return ListView(padding: const EdgeInsets.all(16), children: [
          if (todo.isNotEmpty)
            Card(color: YColors.warning.withValues(alpha: .08), child: ListTile(leading: const Icon(Icons.inbox_outlined, color: YColors.warning), title: Text(tr('Pour vous : ${todo.join(' · ')}')))),
          KpiWrap(children: [
            kpi('Appels de fonds de l\'année', '${k['count']}', Icons.request_page_outlined, YColors.blue, sub: '${money(toDouble(k['requested']), cur)} demandés'),
            kpi('Accordé', money(toDouble(k['approved']), cur), Icons.check_circle_outline, YColors.success, sub: k['approval_rate'] == null ? null : 'taux d\'accord ${k['approval_rate']} %'),
            kpi('Mis à disposition', money(toDouble(k['provided']), cur), Icons.local_shipping_outlined, const Color(0xFF0FA3B1)),
            kpi('En cours', '${k['open']}', Icons.hourglass_bottom, YColors.warning, sub: '${money(toDouble(k['open_amount']), cur)} en attente'),
            kpi('Délai moyen de décision', hours(k['decision_hours']), Icons.timer_outlined, YColors.night),
            kpi('Délai de mise à disposition', hours(k['provision_hours']), Icons.schedule, YColors.night),
            kpi('En retard', '${k['late']}', Icons.warning_amber, YColors.danger, sub: 'date souhaitée dépassée'),
          ]),
          const SizedBox(height: 12),
          SectionCard(title: tr('Demandé et fourni, 12 derniers mois'), child: SizedBox(height: 240, child: CartesianChart(data: chart, currency: cur, line: false))),
          const SizedBox(height: 12),
          Wrap(spacing: 12, runSpacing: 12, children: [
            SizedBox(
              width: 520,
              child: SectionCard(
                title: tr('Par service'),
                child: Column(children: [
                  for (final x in List<Map>.from(d['by_department'] as List))
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 5),
                      child: Row(children: [
                        Expanded(child: Text(tr('${x['department']} (${x['count']})'), overflow: TextOverflow.ellipsis)),
                        SizedBox(width: 120, child: Text(tr(money(toDouble(x['requested']), cur)), textAlign: TextAlign.right, style: TextStyle(color: hint))),
                        SizedBox(width: 120, child: Text(tr(money(toDouble(x['provided']), cur)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w700))),
                      ]),
                    ),
                  if ((d['by_department'] as List).isEmpty) Text(tr('Aucune demande sur la période.'), style: TextStyle(color: hint)),
                ]),
              ),
            ),
            SizedBox(
              width: 520,
              child: SectionCard(
                title: tr('Demandes urgentes en cours'),
                child: Column(children: [
                  for (final x in List<Map>.from(d['urgent'] as List))
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      onTap: () => onOpen(x['id'] as int),
                      title: Text(tr('${x['number']} · ${x['title']}'), overflow: TextOverflow.ellipsis),
                      subtitle: Text(tr('${x['department'] ?? ''}${x['needed_by'] != null ? ' · pour le ${dateFr(x['needed_by'])}' : ''}')),
                      trailing: StatusChip('${x['status_label']}', color: fondsStatusColor('${x['status']}')),
                    ),
                  if ((d['urgent'] as List).isEmpty) Text(tr('Aucune demande urgente.'), style: TextStyle(color: hint)),
                ]),
              ),
            ),
          ]),
        ]);
      },
    );
  }
}

// ---------------------------------------------------------------------------------------------- réglages

class _FondsSettings extends StatefulWidget {
  const _FondsSettings({required this.meta, required this.onSaved});

  final Map<String, dynamic> meta;
  final Future<void> Function() onSaved;

  @override
  State<_FondsSettings> createState() => _FondsSettingsState();
}

class _FondsSettingsState extends State<_FondsSettings> {
  late final s = asMap(widget.meta['settings']);
  late final _depts = TextEditingController(text: List<String>.from(s['departments'] as List).join('\n'));
  late final Map<String, int?> _approvers = {for (final e in Map<String, dynamic>.from(s['approvers'] is Map ? s['approvers'] : const {}).entries) e.key: (e.value as num?)?.toInt()};
  late int? _default = (s['default_approver'] as num?)?.toInt();
  late double _remind = toDouble(s['remind_days']);
  late bool _email = s['email'] != false;

  @override
  Widget build(BuildContext context) {
    final approvers = List<Map>.from(widget.meta['approvers'] as List);
    final depts = _depts.text.split('\n').map((x) => x.trim()).where((x) => x.isNotEmpty).toList();
    DropdownButtonFormField<int> pick(int? value, ValueChanged<int?> on, String label) => DropdownButtonFormField<int>(
          initialValue: approvers.any((a) => a['id'] == value) ? value : null,
          isDense: true,
          decoration: InputDecoration(labelText: trn(label)),
          items: [const DropdownMenuItem<int>(value: null, child: Text('—')), for (final a in approvers) DropdownMenuItem(value: a['id'] as int, child: Text(tr('${a['name']}')))],
          onChanged: on,
        );
    return ListView(padding: const EdgeInsets.all(16), children: [
      SectionCard(
        title: tr('Services'),
        child: TextField(controller: _depts, maxLines: 6, onChanged: (_) => setState(() {}), decoration: InputDecoration(hintText: tr('Un service par ligne (Chantier Bingerville, Agence Yopougon, Logistique…)'))),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Responsable qui décide, par service'),
        child: Column(children: [
          pick(_default, (v) => setState(() => _default = v), 'Responsable par défaut'),
          for (final d in depts) Padding(padding: const EdgeInsets.only(top: 8), child: pick(_approvers[d], (v) => setState(() => _approvers[d] = v), d)),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Relances et e-mails'),
        child: Column(children: [
          Row(children: [
            Expanded(child: Text(tr('Relancer le responsable ou le fournisseur après'))),
            SizedBox(width: 240, child: Slider(value: _remind.clamp(1, 15), min: 1, max: 15, divisions: 14, label: '${_remind.round()} j', onChanged: (v) => setState(() => _remind = v))),
            Text(tr('${_remind.round()} jour(s)')),
          ]),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Envoyer la demande par e-mail au responsable, avec le PDF')), value: _email, onChanged: (v) => setState(() => _email = v)),
        ]),
      ),
      const SizedBox(height: 12),
      Align(
        alignment: Alignment.centerRight,
        child: FilledButton(
          onPressed: () async {
            try {
              await context.read<Session>().api.put('fonds/settings', {
                'departments': depts, 'approvers': {for (final e in _approvers.entries) if (e.value != null && depts.contains(e.key)) e.key: e.value},
                'default_approver': _default, 'remind_days': _remind.round(), 'email': _email,
              });
              await widget.onSaved();
              if (context.mounted) toast(context, tr('Réglages enregistrés.'));
            } catch (e) {
              if (context.mounted) toast(context, errorText(e), error: true);
            }
          },
          child: Text(tr('Enregistrer')),
        ),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------------------------- modèle du formulaire

/// Modèle du formulaire d'appel de fonds propre à la société : logo, couleurs, titre, numérotation, rubriques,
/// listes de choix, visas, taux par défaut et pied de page légal. Un aperçu PDF montre le rendu avec des données d'exemple.
class _FondsFormTemplate extends StatefulWidget {
  const _FondsFormTemplate({required this.meta, required this.onSaved});

  final Map<String, dynamic> meta;
  final Future<void> Function() onSaved;

  @override
  State<_FondsFormTemplate> createState() => _FondsFormTemplateState();
}

class _FondsFormTemplateState extends State<_FondsFormTemplate> {
  late Map<String, dynamic> f = asMap(asMap(widget.meta['settings'])['form']);
  late final _title = TextEditingController(text: '${f['title'] ?? ''}');
  late final _prefix = TextEditingController(text: '${f['prefix'] ?? 'AF'}');
  late String _logoMode = '${f['logo_mode'] ?? 'company'}';
  late String _logoPos = '${f['logo_position'] ?? 'center'}';
  late double _logoH = toDouble(f['logo_height'] ?? 16);
  late final Map<String, TextEditingController> _colors = {
    for (final k in ['color_primary', 'color_accent', 'color_visas']) k: TextEditingController(text: '${f[k] ?? ''}'),
  };
  late final Map<String, TextEditingController> _lists = {
    for (final k in ['motives', 'payment_methods', 'attachments', 'visas']) k: TextEditingController(text: List<String>.from(f[k] as List? ?? const []).join('\n')),
  };
  late final Map<String, bool> _sections = {for (final e in asMap(f['sections']).entries) e.key: e.value != false};
  late final _discount = TextEditingController(text: f['default_discount'] == null ? '' : '${f['default_discount']}');
  late final _vat = TextEditingController(text: f['default_vat'] == null ? '' : '${f['default_vat']}');
  late final List<_TaxCtl> _taxTypes = [for (final t in (f['tax_types'] as List? ?? const [])) _TaxCtl('${(t as Map)['label']}', toDouble(t['rate']), '${t['base'] ?? 'net'}')];
  late final Map<String, TextEditingController> _footer = {for (final e in asMap(f['footer']).entries) e.key: TextEditingController(text: '${e.value ?? ''}')};
  bool _busy = false;

  static const _sectionLabels = {
    'motive': 'Motif de l\'appel de fonds',
    'beneficiary': 'Émetteur et bénéficiaire',
    'payment': 'Modalités de paiement et pièces jointes',
    'accounting': 'Cadre réservé à la comptabilité',
    'visas': 'Visas et approbations',
    'remarks': 'Remarques / suggestions',
  };
  static const _footerLabels = {
    'postal_address': 'Adresse postale', 'address': 'Adresse physique', 'phone': 'Téléphone', 'email': 'E-mail', 'website': 'Site web',
    'rccm': 'RCCM', 'tax_id': 'Compte contribuable (CC)', 'legal': 'Autres informations légales', 'text': 'Texte complémentaire du pied de page',
  };
  static const _swatches = ['#0B4C8C', '#1F6FEB', '#0F766E', '#006C3B', '#7C3AED', '#B91C1C', '#E07600', '#D4A017', '#111827', '#475569'];

  List<String> _lines(String k) => _lists[k]!.text.split('\n').map((x) => x.trim()).where((x) => x.isNotEmpty).toList();

  Map<String, dynamic> get _body => {
        'title': _title.text.trim(), 'prefix': _prefix.text.trim(), 'logo_mode': _logoMode, 'logo_position': _logoPos, 'logo_height': _logoH.round(),
        for (final e in _colors.entries) if (RegExp(r'^#[0-9A-Fa-f]{6}$').hasMatch(e.value.text.trim())) e.key: e.value.text.trim().toUpperCase(),
        for (final k in _lists.keys) k: _lines(k),
        'sections': _sections,
        'default_discount': _discount.text.trim().isEmpty ? null : toDouble(_discount.text),
        'default_vat': _vat.text.trim().isEmpty ? null : toDouble(_vat.text),
        'tax_types': [for (final t in _taxTypes) if (t.label.text.trim().isNotEmpty) {'label': t.label.text.trim(), 'rate': toDouble(t.rate.text), 'base': t.base}],
        'footer': {for (final e in _footer.entries) e.key: e.value.text.trim()},
      };

  Future<bool> _save({bool quiet = false}) async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.put('fonds/form', _body);
      f = asMap(r['data']);
      await widget.onSaved();
      if (mounted && !quiet) toast(context, tr('Modèle du formulaire enregistré.'));
      return true;
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return false;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _preview() async {
    if (!await _save(quiet: true) || !mounted) return;
    try {
      final bytes = await context.read<Session>().api.download('fonds/form/preview');
      await previewPdf(name: 'Aperçu appel de fonds', onLayout: (_) async => Uint8List.fromList(bytes));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _uploadLogo() async {
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['png', 'jpg', 'jpeg']);
    if (files.isEmpty || !mounted) return;
    final api = context.read<Session>().api;
    final bytes = await files.first.readAsBytes();
    if (!mounted) return;
    if (bytes.length > 2 * 1024 * 1024) {
      toast(context, tr('Logo trop lourd : 2 Mo au plus (PNG ou JPG).'), error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final r = await api.uploadForm('fonds/form/logo', const {}, 'logo', bytes, files.first.name);
      if (!mounted) return;
      setState(() {
        f = asMap(r['data']);
        _logoMode = 'custom';
      });
      await widget.onSaved();
      if (mounted) toast(context, tr('Logo enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _deleteLogo() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.delete('fonds/form/logo');
      if (!mounted) return;
      setState(() {
        f = asMap(r['data']);
        _logoMode = '${f['logo_mode'] ?? 'company'}';
      });
      await widget.onSaved();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Color? _hex(String v) {
    final m = RegExp(r'^#?([0-9A-Fa-f]{6})$').firstMatch(v.trim());
    return m == null ? null : Color(int.parse('FF${m.group(1)}', radix: 16));
  }

  Widget _colorField(String key, String label) {
    final ctl = _colors[key]!;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr(label), style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        Wrap(spacing: 6, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
          for (final c in _swatches)
            InkWell(
              onTap: () => setState(() => ctl.text = c),
              customBorder: const CircleBorder(),
              child: Container(
                width: 28,
                height: 28,
                decoration: BoxDecoration(
                  color: _hex(c),
                  shape: BoxShape.circle,
                  border: Border.all(color: ctl.text.toUpperCase() == c ? Theme.of(context).colorScheme.onSurface : Colors.transparent, width: 2.5),
                ),
              ),
            ),
          SizedBox(
            width: 150,
            child: TextField(
              controller: ctl,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(isDense: true, hintText: '#0B4C8C', prefixIcon: Padding(padding: const EdgeInsets.all(10), child: CircleAvatar(radius: 8, backgroundColor: _hex(ctl.text) ?? Colors.transparent))),
            ),
          ),
        ]),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final logoUrl = f['custom_logo_url'] as String?;
    Widget list(String key, String title, String help) => SectionCard(
          title: tr(title),
          child: TextField(controller: _lists[key], maxLines: 7, minLines: 4, decoration: InputDecoration(hintText: tr(help), helperText: tr('Un choix par ligne. « Autre » est toujours proposé en plus.'))),
        );
    return ListView(padding: const EdgeInsets.all(16), children: [
      Card(
        color: YColors.blue.withValues(alpha: .06),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            const Icon(Icons.description_outlined, color: YColors.blue),
            const SizedBox(width: 12),
            Expanded(child: Text(tr('Personnalisez le formulaire imprimé et le PDF envoyé avec chaque appel de fonds : votre logo, vos couleurs, vos rubriques et votre pied de page légal.'))),
            const SizedBox(width: 12),
            OutlinedButton.icon(onPressed: _busy ? null : _preview, icon: const Icon(Icons.visibility_outlined, size: 18), label: Text(tr('Aperçu PDF'))),
          ]),
        ),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('En-tête'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'company', label: Text(tr('Logo de la société'))),
                ButtonSegment(value: 'custom', label: Text(tr('Logo du formulaire')), enabled: logoUrl != null),
                ButtonSegment(value: 'none', label: Text(tr('Sans logo'))),
              ],
              selected: {_logoMode},
              showSelectedIcon: false,
              onSelectionChanged: (v) => setState(() => _logoMode = v.first),
            ),
            FilledButton.tonalIcon(onPressed: _busy ? null : _uploadLogo, icon: const Icon(Icons.upload_outlined, size: 18), label: Text(tr(logoUrl == null ? 'Importer un logo' : 'Changer le logo'))),
            if (logoUrl != null) TextButton(onPressed: _busy ? null : _deleteLogo, child: Text(tr('Retirer'))),
          ]),
          if (_logoMode == 'company' && f['company_logo'] != true)
            Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('La société n\'a pas encore de logo : importez celui du formulaire, ou le nom de la société s\'affichera.'), style: TextStyle(color: hint, fontSize: 12.5))),
          if (logoUrl != null && _logoMode == 'custom')
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Align(alignment: _logoPos == 'left' ? Alignment.centerLeft : Alignment.center, child: Image.network(logoUrl, height: _logoH * 3.2, errorBuilder: (_, _, _) => const SizedBox())),
            ),
          const SizedBox(height: 10),
          Wrap(spacing: 16, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SegmentedButton<String>(
              segments: [ButtonSegment(value: 'center', label: Text(tr('Centré'))), ButtonSegment(value: 'left', label: Text(tr('À gauche')))],
              selected: {_logoPos},
              showSelectedIcon: false,
              onSelectionChanged: (v) => setState(() => _logoPos = v.first),
            ),
            Row(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('Hauteur')),
              SizedBox(width: 200, child: Slider(value: _logoH.clamp(8, 40), min: 8, max: 40, divisions: 32, label: '${_logoH.round()} mm', onChanged: (v) => setState(() => _logoH = v))),
              Text('${_logoH.round()} mm'),
            ]),
          ]),
          const SizedBox(height: 10),
          TextField(controller: _title, decoration: InputDecoration(labelText: tr('Titre du formulaire'))),
          const SizedBox(height: 8),
          TextField(
            controller: _prefix,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(labelText: tr('Préfixe des numéros'), helperText: tr('Exemple : ${(_prefix.text.trim().isEmpty ? 'AF' : _prefix.text.trim().toUpperCase())}-${DateTime.now().year}-00001')),
          ),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Couleurs'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          _colorField('color_primary', 'Couleur principale (titre, bandeaux, tableaux)'),
          _colorField('color_accent', 'Couleur d\'accent (cadre du motif, filets de l\'en-tête)'),
          _colorField('color_visas', 'Couleur des visas et approbations'),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Rubriques du formulaire'),
        child: Column(children: [
          for (final e in _sectionLabels.entries)
            SwitchListTile(contentPadding: EdgeInsets.zero, dense: true, title: Text(tr(e.value)), value: _sections[e.key] ?? true, onChanged: (v) => setState(() => _sections[e.key] = v)),
        ]),
      ),
      const SizedBox(height: 12),
      list('motives', 'Motifs proposés', 'Fournisseur / prestataire\nSalaires et charges sociales\nFrais de mission…'),
      const SizedBox(height: 12),
      list('payment_methods', 'Modes de paiement', 'Espèces\nChèque\nVirement bancaire\nMobile Money…'),
      const SizedBox(height: 12),
      list('attachments', 'Pièces jointes', 'Devis\nFacture\nBon de commande…'),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Visas et approbations'),
        child: TextField(
          controller: _lists['visas'],
          maxLines: 8,
          minLines: 4,
          decoration: InputDecoration(helperText: tr('Un rôle par ligne, dans l\'ordre de signature. Le demandeur, le responsable qui décide et le bénéficiaire sont remplis d\'après le circuit.')),
        ),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Remise et taxes proposées'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr('Aucun taux n\'est imposé : ceux que vous indiquez sont proposés sur chaque nouvel appel de fonds et restent modifiables. Les appels déjà faits ne changent pas.'),
              style: TextStyle(color: hint, fontSize: 12.5)),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _discount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('Remise par défaut'), suffixText: '%'))),
            const SizedBox(width: 12),
            Expanded(child: TextField(controller: _vat, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('TVA par défaut'), suffixText: '%'))),
          ]),
          const SizedBox(height: 10),
          Text(tr('Taxes types (ajoutées en un clic dans le formulaire)'), style: const TextStyle(fontWeight: FontWeight.w600)),
          for (var i = 0; i < _taxTypes.length; i++)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(children: [
                Expanded(child: TextField(controller: _taxTypes[i].label, decoration: InputDecoration(isDense: true, hintText: tr('Libellé (ex. Retenue à la source)')))),
                const SizedBox(width: 8),
                SizedBox(width: 80, child: TextField(controller: _taxTypes[i].rate, textAlign: TextAlign.right, decoration: const InputDecoration(isDense: true, suffixText: '%'))),
                const SizedBox(width: 8),
                DropdownButton<String>(
                  value: _taxTypes[i].base,
                  underline: const SizedBox(),
                  items: [DropdownMenuItem(value: 'net', child: Text(tr('sur le net'))), DropdownMenuItem(value: 'gross', child: Text(tr('sur le brut')))],
                  onChanged: (v) => setState(() => _taxTypes[i].base = v ?? 'net'),
                ),
                IconButton(onPressed: () => setState(() => _taxTypes.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
              ]),
            ),
          Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => setState(() => _taxTypes.add(_TaxCtl('', 0, 'net'))), icon: const Icon(Icons.add, size: 18), label: Text(tr('Ajouter une taxe type')))),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Pied de page'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr('Affiché en bas de chaque page. Les champs laissés vides n\'apparaissent pas.'), style: TextStyle(color: hint, fontSize: 12.5)),
          for (final e in _footerLabels.entries)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: TextField(controller: _footer.putIfAbsent(e.key, TextEditingController.new), decoration: InputDecoration(labelText: tr(e.value), isDense: true)),
            ),
        ]),
      ),
      const SizedBox(height: 12),
      Row(mainAxisAlignment: MainAxisAlignment.end, children: [
        OutlinedButton.icon(onPressed: _busy ? null : _preview, icon: const Icon(Icons.visibility_outlined, size: 18), label: Text(tr('Aperçu PDF'))),
        const SizedBox(width: 8),
        FilledButton(onPressed: _busy ? null : () => _save(), child: Text(tr('Enregistrer'))),
      ]),
    ]);
  }
}
