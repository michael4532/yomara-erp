import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import 'insights_view.dart' show compactMoney;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Qualité : non-conformités et réclamations, actions correctives et préventives, contrôles, plans de contrôle.
class QualiteScreen extends StatefulWidget {
  const QualiteScreen({super.key});

  @override
  State<QualiteScreen> createState() => _QualiteScreenState();
}

class _QualiteScreenState extends State<QualiteScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    final staff = s.can('qualite.view') || s.can('qualite.inspect') || s.can('qualite.manage');
    void changed() => _dash.currentState?.reload();
    final tabs = <(String, Widget)>[
      ('Non-conformités', RecordsView(module: 'qualite', resource: 'nonconformities', emptyIcon: Icons.report_outlined, newLabel: tr('Signaler un problème'), onChanged: changed, actions: ncActions,
          emptyMessage: tr('Un produit défectueux, une réclamation client, un incident ? Déclarez-le ici pour qu\'il soit traité jusqu\'au bout.'))),
      if (staff) ('Actions', RecordsView(module: 'qualite', resource: 'actions', emptyIcon: Icons.task_alt, newLabel: tr('Nouvelle action'), onChanged: changed)),
      if (staff)
        ('Contrôles', RecordsView(module: 'qualite', resource: 'inspections', emptyIcon: Icons.fact_check_outlined, newLabel: tr('Nouveau contrôle'), onChanged: changed, actions: inspectionActions,
            emptyMessage: tr('Choisissez un plan de contrôle puis remplissez-le critère par critère.'))),
      if (staff) ('Plans de contrôle', RecordsView(module: 'qualite', resource: 'plans', emptyIcon: Icons.checklist, newLabel: tr('Nouveau plan'), onChanged: changed)),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Qualité'), subtitle: tr('Contrôles, non-conformités, réclamations et actions correctives')),
        if (staff)
          ModuleDashboard(
            key: _dash,
            path: 'qualite/dashboard',
            builder: (context, d, _) => StatStrip(tiles: [
              StatTile(label: tr('Non-conformités ouvertes'), value: '${d['open']}', detail: tr('${d['critical']} majeure(s) ou critique(s) · ${d['complaints']} réclamation(s) en 30 j'), icon: Icons.report_outlined, color: YColors.danger),
              StatTile(label: tr('Actions en cours'), value: '${d['actions_open']}', detail: tr('${d['actions_late']} en retard'), icon: Icons.task_alt, color: const Color(0xFFD97706)),
              StatTile(label: tr('Taux de conformité'), value: d['conformity'] == null ? '—' : '${d['conformity']} %', detail: tr('${d['inspections']} contrôle(s) en 30 jours'), icon: Icons.fact_check_outlined, color: YColors.success),
              StatTile(label: tr('Coût de non-qualité'), value: compactMoney(toDouble(d['cost_year']), cur), detail: tr('cette année · clôture en ${d['closing_days'] ?? '—'} j en moyenne'), icon: Icons.money_off, color: const Color(0xFF9333EA)),
            ]),
          ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

/// Non-conformité : ses actions correctives et préventives.
List<Widget> ncActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  if (!(s.can('qualite.view') || s.can('qualite.inspect') || s.can('qualite.manage'))) return const [];
  return [
    OutlinedButton.icon(
      icon: const Icon(Icons.task_alt, size: 18),
      label: Text(tr('Actions (${row['actions_done'] ?? 0}/${row['actions_total'] ?? 0})')),
      onPressed: () => showDialog<void>(
        context: dialog,
        builder: (c) => Dialog(
          child: SizedBox(
            width: 960,
            height: 580,
            child: Column(children: [
              ListTile(title: Text(verbatim('${row['number']} — ${row['title']}')), trailing: IconButton(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close))),
              Expanded(child: RecordsView(module: 'qualite', resource: 'actions', filters: {'nc_id': '${row['id']}'}, newLabel: tr('Nouvelle action'), onChanged: reload)),
            ]),
          ),
        ),
      ),
    ),
  ];
}

/// Contrôle : remplir critère par critère.
List<Widget> inspectionActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  if (!(s.can('qualite.inspect') || s.can('qualite.manage'))) return const [];
  return [
    FilledButton.icon(
      icon: const Icon(Icons.fact_check_outlined, size: 18),
      label: Text(tr(row['result'] == 'pending' ? 'Remplir le contrôle' : 'Revoir les réponses')),
      onPressed: () async {
        try {
          final r = await s.api.get('qualite/plans/${row['plan_id']}/criteria');
          final crit = [for (final x in ((r as Map)['data']['criteria'] as List)) '$x'];
          if (!dialog.mounted) return;
          final prev = row['answers'] is List ? [for (final a in row['answers'] as List) asMap(a)] : <Map<String, dynamic>>[];
          final body = await showDialog<Map<String, dynamic>>(context: dialog, builder: (c) => _FillDialog(title: '${row['plan_id_label'] ?? ''}', criteria: crit, previous: prev));
          if (body == null || !dialog.mounted) return;
          final res = await s.api.post('qualite/inspections/${row['id']}/answers', body);
          final d = asMap((res as Map)['data']);
          if (dialog.mounted) {
            Navigator.pop(dialog, <String, dynamic>{'filled': true});
            toast(dialog, d['result'] == 'nok' ? tr('Non conforme (${d['score']} %) : une non-conformité a été ouverte.') : tr('Contrôle enregistré : ${d['score']} % de conformité.'), error: d['result'] == 'nok');
          }
          reload();
        } catch (e) {
          if (dialog.mounted) toast(dialog, errorText(e), error: true);
        }
      },
    ),
  ];
}

class _FillDialog extends StatefulWidget {
  const _FillDialog({required this.title, required this.criteria, required this.previous});

  final String title;
  final List<String> criteria;
  final List<Map<String, dynamic>> previous;

  @override
  State<_FillDialog> createState() => _FillDialogState();
}

class _FillDialogState extends State<_FillDialog> {
  late final List<String?> _v;
  late final List<TextEditingController> _c;
  final _notes = TextEditingController();

  @override
  void initState() {
    super.initState();
    _v = [for (var i = 0; i < widget.criteria.length; i++) i < widget.previous.length ? widget.previous[i]['value'] as String? : null];
    _c = [for (var i = 0; i < widget.criteria.length; i++) TextEditingController(text: i < widget.previous.length ? '${widget.previous[i]['comment'] ?? ''}' : '')];
  }

  @override
  Widget build(BuildContext context) {
    final done = _v.where((x) => x != null).length;
    return YAlertDialog(
      title: Text(verbatim(widget.title)),
      content: SizedBox(
        width: 560,
        child: ListView(shrinkWrap: true, children: [
          Text(tr('$done / ${widget.criteria.length} critère(s) renseigné(s)'), style: TextStyle(color: Theme.of(context).hintColor)),
          const SizedBox(height: 8),
          for (var i = 0; i < widget.criteria.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(verbatim('${i + 1}. ${widget.criteria[i]}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 6),
                SegmentedButton<String>(
                  emptySelectionAllowed: true,
                  segments: [
                    ButtonSegment(value: 'ok', label: Text(tr('Conforme')), icon: const Icon(Icons.check, size: 16)),
                    ButtonSegment(value: 'nok', label: Text(tr('Non conforme')), icon: const Icon(Icons.close, size: 16)),
                    ButtonSegment(value: 'na', label: Text(tr('Sans objet'))),
                  ],
                  selected: {?_v[i]},
                  onSelectionChanged: (s) => setState(() => _v[i] = s.isEmpty ? null : s.first),
                ),
                if (_v[i] == 'nok') TextField(controller: _c[i], decoration: InputDecoration(labelText: tr('Constat'), isDense: true)),
              ]),
            ),
          TextField(controller: _notes, maxLines: 2, decoration: InputDecoration(labelText: tr('Observations'))),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: done == widget.criteria.length
              ? () => Navigator.pop(context, <String, dynamic>{
                    'answers': [for (var i = 0; i < _v.length; i++) {'value': _v[i], 'comment': _c[i].text.trim().isEmpty ? null : _c[i].text.trim()}],
                    if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
                  })
              : null,
          child: Text(tr('Enregistrer')),
        ),
      ],
    );
  }
}
