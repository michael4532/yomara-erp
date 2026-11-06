import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'analytique_screen.dart' show AnalyticField, analyticEnabled, anDist;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

String abRhythm(Map<String, dynamic> s) {
  final n = (s['interval_count'] as num?)?.toInt() ?? 1;
  final u = '${s['interval_unit'] ?? 'month'}';
  if (u == 'month' && n == 1) return 'Mensuel';
  if (u == 'month' && n == 3) return 'Trimestriel';
  if (u == 'month' && n == 6) return 'Semestriel';
  if (u == 'year' && n == 1) return 'Annuel';
  if (u == 'week' && n == 1) return 'Hebdomadaire';
  return 'Tous les $n ${u == 'week' ? 'semaines' : (u == 'year' ? 'ans' : 'mois')}';
}

Widget abStatusChip(Map<String, dynamic> s) => switch (s['status']) {
      'active' => s['last_error'] != null ? StatusChip(tr('Erreur'), color: YColors.danger, icon: Icons.error_outline) : StatusChip(tr('Actif'), color: YColors.success),
      'paused' => StatusChip(tr('En pause'), color: YColors.warning),
      _ => StatusChip(tr('Terminé'), color: YColors.muted),
    };

class AbonnementsScreen extends StatefulWidget {
  const AbonnementsScreen({super.key});

  @override
  State<AbonnementsScreen> createState() => _AbonnementsScreenState();
}

class _AbonnementsScreenState extends State<AbonnementsScreen> {
  String _status = 'active';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _statuses = {'': 'Tous', 'active': 'Actifs', 'paused': 'En pause', 'ended': 'Terminés', 'error': 'En erreur'};

  static bool _statusOk(Map<String, dynamic> x, String st) => switch (st) {
        '' => true,
        'error' => x['last_error'] != null,
        _ => x['status'] == st,
      };
  Map<String, dynamic>? _stats;

  @override
  void initState() {
    super.initState();
    _loadStats();
  }

  Future<void> _loadStats() async {
    try {
      final r = await context.read<Session>().api.get('abonnements/stats');
      if (mounted) setState(() => _stats = asMap(r['data']));
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('abonnements.manage');
    final cur = s.currency;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return Column(children: [
      PageHeader(
        title: tr('Contrats & abonnements'),
        subtitle: tr('Factures émises automatiquement à chaque échéance'),
        actions: [
          if (manage)
            FilledButton.icon(
              onPressed: () async {
                await editSubscription(context);
                _loadStats();
              },
              icon: const Icon(Icons.add),
              label: Text(tr('Nouveau contrat')),
            ),
        ],
      ),
      if (_stats != null)
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
          child: Align(
            alignment: Alignment.centerLeft,
            child: KpiWrap(children: [
              _Kpi('Revenu récurrent mensuel (HT)', money(toDouble(_stats!['mrr']), cur), color: YColors.success),
              _Kpi('Revenu annuel (HT)', money(toDouble(_stats!['arr']), cur)),
              _Kpi('Contrats actifs', '${_stats!['active']}'),
              _Kpi('Échéances sous 7 jours', '${_stats!['due_7_days']}'),
              if ((_stats!['errors'] as num) > 0) _Kpi('Contrats en erreur', '${_stats!['errors']}', color: YColors.danger),
            ]),
          ),
        ),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('ab_subscriptions'),
          builder: (context, _, _) {
            final all = LocalDb.all('ab_subscriptions');
            String partnerName(Map<String, dynamic> x) => '${LocalDb.get('partners', x['partner_id'] as String?)?['name'] ?? ''}';
            final base = all.where((x) {
              if (_q.isNotEmpty && !'${x['number']} ${x['name']} ${partnerName(x)}'.toLowerCase().contains(_q)) return false;
              final cl = _f['client'], rh = _f['rhythm'], dv = _f['currency'];
              if (cl != null && '${x['partner_id']}' != cl) return false;
              if (rh != null && abRhythm(x) != rh) return false;
              if (dv != null && '${x['currency'] ?? cur}' != dv) return false;
              if (_period != null && (x['status'] == 'ended' || !_period!.contains(x['next_date']))) return false;
              return true;
            }).toList();
            final items = base.where((x) => _statusOk(x, _status)).toList()
              ..sort((a, b) => '${a['next_date']}'.compareTo('${b['next_date']}'));
            final currencies = {for (final x in all) '${x['currency'] ?? cur}': '${x['currency'] ?? cur}'};
            final bar = FilterBar(
              padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
              search: _q,
              searchHint: 'Contrat, client, numéro…',
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              statuses: _statuses,
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in _statuses.keys) k: base.where((x) => _statusOk(x, k)).length},
              filters: [
                FilterSpec('client', 'Client', filterOptions(all, 'partner_id', label: partnerName), icon: Icons.person_outline),
                FilterSpec('rhythm', 'Rythme', {for (final x in all) abRhythm(x): abRhythm(x)}, icon: Icons.autorenew),
                if (currencies.length > 1) FilterSpec('currency', 'Devise', currencies, icon: Icons.currency_exchange),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              showPeriod: true,
              period: _period,
              onPeriod: (v) => setState(() => _period = v),
              count: items.length,
              countLabel: 'contrat',
              onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
            );
            if (items.isEmpty) {
              return Column(children: [
                bar,
                Expanded(
                  child: all.isEmpty
                      ? EmptyState(icon: Icons.autorenew, title: tr('Aucun contrat'), message: tr('Abonnements, loyers, maintenance, licences : la facture part toute seule à chaque échéance.'))
                      : EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
                ),
              ]);
            }
            return Column(children: [
              bar,
              Expanded(
                child: ResponsiveList<Map<String, dynamic>>(
              items: items,
              columns: const ['Contrat', 'Client', 'Rythme', 'Montant HT', 'Prochaine facture', 'Statut'],
              onTap: (x) async {
                await Navigator.of(context).push(MaterialPageRoute(builder: (_) => SubscriptionDetail(id: '${x['id']}')));
                _loadStats();
              },
              cells: (x) => [
                Text(tr('${x['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                Text(tr('${LocalDb.get('partners', x['partner_id'] as String?)?['name'] ?? '—'}')),
                Text(tr(abRhythm(x))),
                Text(tr(money(toDouble(x['period_amount']), '${x['currency'] ?? cur}'))),
                Text(tr(x['status'] == 'ended' ? '—' : dateFr(x['next_date']))),
                abStatusChip(x),
              ],
              mobileTile: (x) => Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${x['name']} · ${LocalDb.get('partners', x['partner_id'] as String?)?['name'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(tr('${abRhythm(x)} · ${money(toDouble(x['period_amount']), '${x['currency'] ?? cur}')} · prochaine le ${dateFr(x['next_date'])}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                  ]),
                ),
                abStatusChip(x),
              ]),
                ),
              ),
            ]);
          },
        ),
      ),
    ]);
  }
}

class _Kpi extends StatelessWidget {
  const _Kpi(this.label, this.value, {this.color});

  final String label;
  final String value;
  final Color? color;

  @override
  Widget build(BuildContext context) => KpiBox(
        width: 240,
        child: StatTile(label: label, value: value, color: color ?? YColors.blue, valueColor: color),
      );
}

// ---------------------------------------------------------------------------

Future<void> editSubscription(BuildContext context, [Map<String, dynamic>? sub]) =>
    showFormDialog(context, title: sub == null ? 'Nouveau contrat' : 'Contrat ${sub['number'] ?? ''}', builder: (_) => _SubForm(sub: sub));

class _SubForm extends StatefulWidget {
  const _SubForm({this.sub});
  final Map<String, dynamic>? sub;

  @override
  State<_SubForm> createState() => _SubFormState();
}

class _SubFormState extends State<_SubForm> {
  late final x = widget.sub ?? <String, dynamic>{};
  late final _name = TextEditingController(text: '${x['name'] ?? ''}');
  late final _notes = TextEditingController(text: '${x['notes'] ?? ''}');
  late final _max = TextEditingController(text: x['max_invoices'] != null ? '${x['max_invoices']}' : '');
  late String? _partner = x['partner_id'] as String?;
  late DateTime _start = DateTime.tryParse('${x['start_date'] ?? ''}') ?? DateTime(DateTime.now().year, DateTime.now().month + 1, 1);
  late DateTime? _end = DateTime.tryParse('${x['end_date'] ?? ''}');
  late String _rhythm = () {
    final n = (x['interval_count'] as num?)?.toInt() ?? 1;
    final u = '${x['interval_unit'] ?? 'month'}';
    return '$u:$n';
  }();
  late String? _currency = x['currency'] as String?;
  late bool _validate = x['auto_validate'] != false;
  late bool _send = x['auto_send'] == true;
  late bool _advance = x['in_advance'] != false;
  late Map<String, double> _analytic = anDist(x['analytic']);
  late final List<Map<String, dynamic>> _lines = [for (final l in (x['lines'] as List? ?? [])) asMap(l)];
  bool _busy = false;

  static const _rhythms = {'week:1': 'Chaque semaine', 'month:1': 'Chaque mois', 'month:2': 'Tous les 2 mois', 'month:3': 'Chaque trimestre', 'month:6': 'Chaque semestre', 'year:1': 'Chaque année', 'year:2': 'Tous les 2 ans'};

  Future<void> _save() async {
    if (_partner == null || _name.text.trim().isEmpty) return toast(context, tr('Choisissez le client et nommez le contrat.'), error: true);
    final lines = _lines.where((l) => '${l['description'] ?? ''}'.trim().isNotEmpty).toList();
    if (lines.isEmpty) return toast(context, tr('Ajoutez au moins une ligne à facturer.'), error: true);
    final p = _rhythm.split(':');
    setState(() => _busy = true);
    try {
      await context.read<SyncEngine>().save('ab_subscriptions', {
        'id': x['id'] ?? newId(),
        'partner_id': _partner,
        'name': _name.text.trim(),
        'start_date': isoDate(_start),
        if (x['id'] == null) 'next_date': isoDate(_start),
        'interval_unit': p[0],
        'interval_count': int.parse(p[1]),
        'end_date': _end == null ? null : isoDate(_end!),
        'max_invoices': int.tryParse(_max.text),
        'currency': _currency,
        'auto_validate': _validate,
        'auto_send': _send && _validate,
        'in_advance': _advance,
        if (analyticEnabled(context.read<Session>())) 'analytic': _analytic.isEmpty ? null : _analytic,
        'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        'lines': [
          for (final l in lines)
            {'description': '${l['description']}'.trim(), 'quantity': toDouble(l['quantity'] ?? 1), 'unit_price': toDouble(l['unit_price']), 'tax_rate': toDouble(l['tax_rate'] ?? 18), 'discount_percent': toDouble(l['discount_percent']), 'product_id': l['product_id']},
        ],
      });
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Contrat enregistré.'));
      }
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final customers = LocalDb.all('partners').where((p) => p['type'] != 'supplier' && p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final cur = _currency ?? s.currency;
    final total = _lines.fold<double>(0, (t, l) => t + toDouble(l['quantity'] ?? 1) * toDouble(l['unit_price']) * (1 - toDouble(l['discount_percent']) / 100));
    return Column(children: [
      Expanded(
        child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 8), children: [
          Field(
            label: tr('Client'),
            child: DropdownButtonFormField<String>(
              initialValue: customers.any((c) => c['id'] == _partner) ? _partner : null,
              isExpanded: true,
              items: [for (final c in customers) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => setState(() {
                _partner = v;
                final pc = LocalDb.get('partners', v)?['currency'] as String?;
                if (s.hasModule('devises') && pc != null) _currency = pc;
              }),
            ),
          ),
          Field(label: tr('Nom du contrat'), child: TextField(controller: _name, decoration: InputDecoration(hintText: tr('Maintenance informatique, loyer bureau, abonnement Pro…')))),
          Row(children: [
            Expanded(
              child: Field(
                label: tr('Rythme'),
                child: DropdownButtonFormField<String>(
                  initialValue: _rhythms.containsKey(_rhythm) ? _rhythm : 'month:1',
                  items: [for (final e in _rhythms.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                  onChanged: (v) => setState(() => _rhythm = v ?? 'month:1'),
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Première échéance'),
                child: OutlinedButton.icon(
                  onPressed: x['id'] != null && (x['generated_count'] as num? ?? 0) > 0
                      ? null
                      : () async {
                          final d = await pickDate(context, initialDate: _start, firstDate: DateTime(2015), lastDate: DateTime(2100));
                          if (d != null) setState(() => _start = d);
                        },
                  icon: const Icon(Icons.event, size: 18),
                  label: Text(tr(dateFr(isoDate(_start)))),
                ),
              ),
            ),
          ]),
          Row(children: [
            Expanded(
              child: Field(
                label: tr('Fin du contrat (facultatif)'),
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final d = await pickDate(context, initialDate: _end ?? _start.add(const Duration(days: 365)), firstDate: _start, lastDate: DateTime(2100));
                    setState(() => _end = d);
                  },
                  icon: const Icon(Icons.event_busy, size: 18),
                  label: Text(tr(_end == null ? 'Sans fin' : dateFr(isoDate(_end!)))),
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Nombre d\'échéances (facultatif)'), child: TextField(controller: _max, keyboardType: TextInputType.number, decoration: InputDecoration(hintText: tr('Illimité'))))),
          ]),
          if (s.hasModule('devises'))
            Field(
              label: tr('Devise'),
              child: DropdownButtonFormField<String?>(
                initialValue: _currency,
                items: [
                  DropdownMenuItem(value: null, child: Text(tr(s.currency))),
                  for (final c in ((s.companySettings['devises'] as Map?)?['currencies'] as List? ?? const ['EUR', 'USD'])) if ('$c' != s.currency) DropdownMenuItem(value: '$c', child: Text(tr('$c'))),
                ],
                onChanged: (v) => setState(() => _currency = v),
              ),
            ),
          const SizedBox(height: 6),
          Row(children: [
            Text(tr('Lignes facturées à chaque échéance'), style: TextStyle(fontWeight: FontWeight.w700)),
            const Spacer(),
            TextButton.icon(onPressed: () => setState(() => _lines.add({'description': '', 'quantity': 1, 'unit_price': 0, 'tax_rate': 18})), icon: const Icon(Icons.add, size: 18), label: Text(tr('Ligne'))),
          ]),
          for (var i = 0; i < _lines.length; i++) _LineEditor(key: ObjectKey(_lines[i]), line: _lines[i], onChanged: () => setState(() {}), onDelete: () => setState(() => _lines.removeAt(i))),
          if (_lines.isEmpty) Text(tr('Aucune ligne.'), style: TextStyle(color: Theme.of(context).hintColor)),
          Align(alignment: Alignment.centerRight, child: Text(tr('Par échéance : ${money(total, cur)} HT'), style: const TextStyle(fontWeight: FontWeight.w700))),
          const SizedBox(height: 8),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Facturer en début de période')), subtitle: Text(tr('Sinon, la facture est datée de la fin de la période')), value: _advance, onChanged: (v) => setState(() => _advance = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Valider la facture automatiquement')), subtitle: Text(tr('Sinon, elle est créée en brouillon pour vérification')), value: _validate, onChanged: (v) => setState(() => _validate = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Envoyer la facture au client par e-mail')), subtitle: Text(tr('Avec le lien de paiement en ligne')), value: _send && _validate, onChanged: _validate ? (v) => setState(() => _send = v) : null),
          if (analyticEnabled(s)) Field(label: tr('Analytique'), child: AnalyticField(value: _analytic, onChanged: (v) => setState(() => _analytic = v))),
          Field(label: tr('Mention ajoutée aux factures'), child: TextField(controller: _notes, maxLines: 2)),
        ]),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 16),
        child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          const SizedBox(width: 8),
          FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
        ]),
      ),
    ]);
  }
}

class _LineEditor extends StatefulWidget {
  const _LineEditor({super.key, required this.line, required this.onChanged, required this.onDelete});
  final Map<String, dynamic> line;
  final VoidCallback onChanged;
  final VoidCallback onDelete;

  @override
  State<_LineEditor> createState() => _LineEditorState();
}

class _LineEditorState extends State<_LineEditor> {
  late final _d = TextEditingController(text: '${widget.line['description'] ?? ''}');
  late final _q = TextEditingController(text: qty(toDouble(widget.line['quantity'] ?? 1)).replaceAll(RegExp(r'\s'), ''));
  late final _p = TextEditingController(text: toDouble(widget.line['unit_price']) == 0 ? '' : '${widget.line['unit_price']}');
  late final _t = TextEditingController(text: number(toDouble(widget.line['tax_rate'] ?? 18)));

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(children: [
          Expanded(flex: 4, child: TextField(controller: _d, decoration: InputDecoration(labelText: tr('Désignation'), isDense: true), onChanged: (v) {
            widget.line['description'] = v;
            widget.onChanged();
          })),
          const SizedBox(width: 6),
          SizedBox(width: 60, child: TextField(controller: _q, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Qté'), isDense: true), onChanged: (v) {
            widget.line['quantity'] = toDouble(v.replaceAll(',', '.'));
            widget.onChanged();
          })),
          const SizedBox(width: 6),
          SizedBox(width: 110, child: TextField(controller: _p, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('Prix HT'), isDense: true), onChanged: (v) {
            widget.line['unit_price'] = toDouble(v.replaceAll(',', '.').replaceAll(RegExp(r'\s'), ''));
            widget.onChanged();
          })),
          const SizedBox(width: 6),
          SizedBox(width: 60, child: TextField(controller: _t, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('TVA %'), isDense: true), onChanged: (v) {
            widget.line['tax_rate'] = toDouble(v);
            widget.onChanged();
          })),
          IconButton(onPressed: widget.onDelete, icon: const Icon(Icons.close, size: 18)),
        ]),
      );
}

// ---------------------------------------------------------------------------

class SubscriptionDetail extends StatefulWidget {
  const SubscriptionDetail({super.key, required this.id});
  final String id;

  @override
  State<SubscriptionDetail> createState() => _SubscriptionDetailState();
}

class _SubscriptionDetailState extends State<SubscriptionDetail> {
  List<Map<String, dynamic>>? _invoices;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('abonnements/subscriptions/${widget.id}/invoices');
      if (mounted) setState(() => _invoices = [for (final x in r['data'] as List) asMap(x)]);
    } catch (_) {
      if (mounted) setState(() => _invoices = []);
    }
  }

  Future<void> _status(String st) async {
    await context.read<SyncEngine>().save('ab_subscriptions', {'id': widget.id, 'status': st});
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('abonnements.manage');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('ab_subscriptions'),
      builder: (context, _, _) {
        final x = LocalDb.get('ab_subscriptions', widget.id);
        if (x == null) return Scaffold(appBar: AppBar(), body: EmptyState(icon: Icons.autorenew, title: tr('Contrat introuvable')));
        final cur = '${x['currency'] ?? s.currency}';
        return Scaffold(
          appBar: AppBar(
            title: Row(children: [Flexible(child: Text(tr('${x['number'] ?? ''} ${x['name']}'), overflow: TextOverflow.ellipsis)), const SizedBox(width: 10), abStatusChip(x)]),
            actions: [
              if (manage && x['status'] == 'active')
                FilledButton.icon(
                  onPressed: _busy
                      ? null
                      : () async {
                          if (!await confirm(context, tr('Générer la prochaine facture ?'), tr('Échéance du ${dateFr(x['next_date'])}, émise dès maintenant.'))) return;
                          if (!context.mounted) return;
                          setState(() => _busy = true);
                          try {
                            final r = (await s.api.post('abonnements/subscriptions/${widget.id}/generate'))['data'] as Map;
                            if (!context.mounted) return;
                            await context.read<SyncEngine>().sync();
                            if (context.mounted) toast(context, tr('Facture ${r['number'] ?? '(brouillon)'} créée.'));
                            _load();
                          } catch (e) {
                            if (context.mounted) toast(context, errorText(e), error: true);
                          } finally {
                            if (mounted) setState(() => _busy = false);
                          }
                        },
                  icon: const Icon(Icons.receipt_long),
                  label: Text(tr('Facturer l\'échéance')),
                ),
              if (manage)
                PopupMenuButton<String>(
                  onSelected: (v) async {
                    if (v == 'edit') await editSubscription(context, x);
                    if (v == 'pause') await _status('paused');
                    if (v == 'resume') await _status('active');
                    if (v == 'end' && context.mounted && await confirm(context, tr('Terminer le contrat ?'), tr('Plus aucune facture ne sera émise.'), danger: true)) await _status('ended');
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'edit', child: Text(tr('Modifier'))),
                    if (x['status'] == 'active') PopupMenuItem(value: 'pause', child: Text(tr('Mettre en pause'))),
                    if (x['status'] == 'paused') PopupMenuItem(value: 'resume', child: Text(tr('Reprendre'))),
                    if (x['status'] != 'ended') PopupMenuItem(value: 'end', child: Text(tr('Terminer'))),
                  ],
                ),
              const SizedBox(width: 8),
            ],
          ),
          body: ListView(padding: const EdgeInsets.all(16), children: [
            if (x['last_error'] != null)
              Container(
                margin: const EdgeInsets.only(bottom: 12),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: YColors.danger.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(10)),
                child: Text(tr('Dernière facture refusée : ${x['last_error']}'), style: const TextStyle(color: YColors.danger)),
              ),
            SectionCard(
              child: Wrap(spacing: 32, runSpacing: 10, children: [
                _info('Client', '${LocalDb.get('partners', x['partner_id'] as String?)?['name'] ?? '—'}'),
                _info('Rythme', abRhythm(x)),
                _info('Montant par échéance', '${money(toDouble(x['period_amount']), cur)} HT'),
                _info('Début', dateFr(x['start_date'])),
                _info('Prochaine facture', x['status'] == 'ended' ? '—' : dateFr(x['next_date'])),
                _info('Fin', x['end_date'] != null ? dateFr(x['end_date']) : (x['max_invoices'] != null ? '${x['max_invoices']} échéances' : 'Sans fin')),
                _info('Factures émises', '${x['generated_count'] ?? 0}'),
                _info('Automatismes', [if (x['auto_validate'] == true) 'validation', if (x['auto_send'] == true) 'envoi par e-mail', if (x['in_advance'] == true) 'début de période' else 'fin de période'].join(', ')),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Lignes facturées'),
              child: Column(children: [
                for (final l in (x['lines'] as List? ?? []))
                  ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${l['description']}')), subtitle: Text(tr('${qty(toDouble(l['quantity']))} × ${money(toDouble(l['unit_price']), cur)} · TVA ${number(toDouble(l['tax_rate']))} %'))),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Factures du contrat'),
              child: _invoices == null
                  ? const Center(child: CircularProgressIndicator())
                  : _invoices!.isEmpty
                      ? Text(tr('Aucune facture pour l\'instant.'), style: TextStyle(color: Theme.of(context).hintColor))
                      : Column(children: [
                          for (final i in _invoices!)
                            ListTile(
                              dense: true,
                              contentPadding: EdgeInsets.zero,
                              title: Text(tr('${i['number'] ?? 'Brouillon'} · ${dateFr(i['period_start'])} – ${dateFr(i['period_end'])}')),
                              trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                                Text(tr(money(toDouble(i['total']), '${i['currency'] ?? cur}')), style: const TextStyle(fontWeight: FontWeight.w600)),
                                const SizedBox(width: 8),
                                StatusChip(i['status'] == 'draft' ? 'Brouillon' : (i['payment_status'] == 'paid' ? 'Payée' : 'À payer'), color: i['payment_status'] == 'paid' ? YColors.success : (i['status'] == 'draft' ? YColors.blue : YColors.warning)),
                              ]),
                            ),
                        ]),
            ),
          ]),
        );
      },
    );
  }

  Widget _info(String label, String value) => SizedBox(
        width: 220,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(label), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          const SizedBox(height: 2),
          Text(tr(value), style: const TextStyle(fontWeight: FontWeight.w600)),
        ]),
      );
}
