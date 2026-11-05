import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../core/records_offline.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/add_to_report.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _sectionIcons = {
  'ventes': Icons.receipt_long_outlined, 'commercial': Icons.storefront_outlined, 'crm': Icons.handshake_outlined, 'contenus': Icons.campaign_outlined, 'juridique': Icons.gavel_outlined,
  'dev': Icons.code, 'projets': Icons.view_kanban_outlined, 'tickets': Icons.confirmation_number_outlined, 'helpdesk': Icons.support_agent, 'interventions': Icons.build_outlined,
  'flotte': Icons.directions_car_outlined, 'achats': Icons.shopping_cart_outlined, 'fonds': Icons.request_page_outlined, 'agenda': Icons.event_outlined, 'autres': Icons.edit_note,
};

Color _statusColor(String? s) => switch (s) { 'sent' => YColors.blue, 'approved' => YColors.success, 'returned' => YColors.danger, _ => YColors.muted };

/// Rapports d'activité : mon rapport (rédigé automatiquement), mes rapports envoyés, rapports reçus de mon équipe.
class ActiviteScreen extends StatelessWidget {
  const ActiviteScreen({super.key});

  @override
  Widget build(BuildContext context) => DefaultTabController(
        length: 3,
        child: Column(children: [
          PageHeader(title: tr('Rapports d\'activité'), subtitle: tr('Votre rapport s\'écrit avec ce que vous faites dans le logiciel : relisez, complétez, envoyez')),
          TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Mon rapport')), Tab(text: tr('Mes rapports')), Tab(text: tr('Rapports reçus'))]),
          const Expanded(child: TabBarView(physics: NeverScrollableScrollPhysics(), children: [ReportView(), _ReportList(box: 'mine'), _ReportList(box: 'team')])),
        ]),
      );
}

class _ReportList extends StatefulWidget {
  const _ReportList({required this.box});

  final String box;

  @override
  State<_ReportList> createState() => _ReportListState();
}

class _ReportListState extends State<_ReportList> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await RecordsOffline.get(context.read<Session>().api, 'activite/reports', query: {'box': widget.box});
      if (mounted) setState(() => (_rows = [for (final x in ((r as Map)['data'] as List)) asMap(x)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_rows == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    final team = widget.box == 'team';
    if (_rows!.isEmpty) {
      return EmptyState(icon: Icons.summarize_outlined, title: team ? 'Aucun rapport reçu' : 'Aucun rapport enregistré',
          message: team ? 'Les rapports que vos collaborateurs vous envoient arrivent ici.' : 'Votre rapport de la semaine se prépare dans l\'onglet « Mon rapport ».');
    }
    return Padding(
      padding: const EdgeInsets.only(top: 14),
      child: RefreshIndicator(
        onRefresh: _load,
        child: ResponsiveList<Map<String, dynamic>>(
          items: _rows!,
          columns: [if (team) 'Collaborateur', 'Période', 'Activités', if (!team) 'Destinataire', 'Statut'],
          cells: (r) => [
            if (team) Row(children: [InitialsAvatar('${r['author']}', size: 28), const SizedBox(width: 8), Text(tr('${r['author']}'), style: const TextStyle(fontWeight: FontWeight.w600))]),
            Text(tr('${r['period_label']}')),
            Text(tr(r['count'] == null ? '—' : '${r['count']}')),
            if (!team) Text(tr('${r['supervisor'] ?? '—'}')),
            StatusChip('${r['status_label']}', color: _statusColor(r['status'] as String?)),
          ],
          mobileTile: (r) => Row(children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(team ? '${r['author']}' : '${r['period_label']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              Text(tr(team ? '${r['period_label']}' : 'à ${r['supervisor'] ?? '—'}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
            ])),
            StatusChip('${r['status_label']}', color: _statusColor(r['status'] as String?)),
          ]),
          onTap: (r) async {
            await showFormDialog(context, maxWidth: 900, title: '${r['title']}', builder: (_) => ReportView(reportId: r['id'] as int));
            _load();
          },
        ),
      ),
    );
  }
}

/// Un rapport : celui de la période en cours (modifiable) ou un rapport existant (lecture, validation).
class ReportView extends StatefulWidget {
  const ReportView({super.key, this.reportId});

  final int? reportId;

  @override
  State<ReportView> createState() => _ReportViewState();
}

class _ReportViewState extends State<ReportView> {
  Map<String, dynamic>? _r;
  Object? _error;
  String? _from, _to;
  final _summary = TextEditingController(), _diff = TextEditingController(), _next = TextEditingController();
  Timer? _autosave;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _autosave?.cancel();
    for (final c in [_summary, _diff, _next]) {
      c.dispose();
    }
    super.dispose();
  }

  Session get _s => context.read<Session>();
  bool get _editable => _r?['can']?['edit'] == true;
  Map<String, dynamic> get _period => {'from': ?_from, 'to': ?_to};

  void _apply(Map<String, dynamic> d) {
    _r = d;
    _from = d['period_start'] as String?;
    _to = d['period_end'] as String?;
    _summary.text = '${d['summary'] ?? ''}';
    _diff.text = '${d['difficulties'] ?? ''}';
    _next.text = '${d['next_steps'] ?? ''}';
  }

  Future<void> _load() async {
    try {
      final r = widget.reportId != null ? await RecordsOffline.get(_s.api, 'activite/reports/${widget.reportId}') : await RecordsOffline.get(_s.api, 'activite/report', query: _period);
      if (mounted) {
        setState(() {
          _apply(asMap((r as Map)['data']));
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  List<String> get _excluded => [for (final i in (_r!['items'] as List)) if (i['included'] == false) '${i['key']}'];

  Future<Map<String, dynamic>?> _save({bool quiet = false}) async {
    try {
      final r = await _s.api.post('activite/report?from=$_from&to=$_to', {'summary': _summary.text, 'difficulties': _diff.text, 'next_steps': _next.text, 'excluded': _excluded});
      final d = asMap((r as Map)['data']);
      if (mounted) setState(() => _r = {..._r!, 'id': d['id'], 'stats': d['stats']});
      if (!quiet && mounted) toast(context, tr('Rapport enregistré.'));
      return d;
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return null;
    }
  }

  Future<void> _download(String format) async {
    var id = _r!['id'];
    if (_editable) id = (await _save(quiet: true))?['id'];
    if (id == null || !mounted) return;
    try {
      final bytes = await _s.api.download('activite/reports/$id/file/$format');
      final name = 'rapport-activite-${_r!['period_start']}.${format == 'pdf' ? 'pdf' : 'doc'}';
      final where = await saveFile(name, bytes, mime: format == 'pdf' ? 'application/pdf' : 'application/msword');
      if (mounted) toast(context, tr('${format == 'pdf' ? 'PDF' : 'Document Word'} enregistré : $where'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _send() async {
    final saved = await _save(quiet: true);
    if (saved == null || !mounted) return;
    final sup = asMap(((await _s.api.get('activite/supervisors')) as Map)['data']);
    if (!mounted) return;
    int? to = (sup['current'] as int?) ?? (_r!['supervisor_id'] as int?);
    final msg = TextEditingController();
    final users = [for (final u in (sup['users'] as List)) asMap(u)];
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Envoyer mon rapport')),
          content: SizedBox(
            width: 440,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              DropdownButtonFormField<int>(
                initialValue: users.any((u) => u['id'] == to) ? to : null,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Mon supérieur *')),
                items: [for (final u in users) DropdownMenuItem(value: u['id'] as int, child: Text(tr('${u['name']}')))],
                onChanged: (v) => set(() => to = v),
              ),
              const SizedBox(height: 10),
              TextField(controller: msg, maxLines: 3, minLines: 1, decoration: InputDecoration(labelText: tr('Message (facultatif)'))),
              const SizedBox(height: 8),
              Text(tr('${_r!['stats']['count']} activité(s). Il le reçoit dans l\'application et par e-mail avec le PDF.'), style: TextStyle(fontSize: 12.5, color: Theme.of(c).hintColor)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
            FilledButton.icon(onPressed: to == null ? null : () => Navigator.pop(c, true), icon: const Icon(Icons.send, size: 18), label: Text(tr('Envoyer'))),
          ],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final r = await _s.api.post('activite/reports/${saved['id']}/send', {'supervisor_id': to, if (msg.text.trim().isNotEmpty) 'message': msg.text.trim()});
      if (mounted) {
        setState(() => _apply(asMap((r as Map)['data'])));
        toast(context, tr('Rapport envoyé à ${_r!['supervisor']}.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _review(String decision) async {
    final c = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr(decision == 'approved' ? 'Valider le rapport' : 'Demander un complément')),
        content: SizedBox(width: 420, child: TextField(controller: c, maxLines: 4, minLines: 2, decoration: InputDecoration(labelText: trn(decision == 'approved' ? 'Commentaire (facultatif)' : 'Ce qu\'il faut compléter *')))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr(decision == 'approved' ? 'Valider' : 'Renvoyer'))),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = await _s.api.post('activite/reports/${_r!['id']}/review', {'decision': decision, if (c.text.trim().isNotEmpty) 'comment': c.text.trim()});
      if (mounted) setState(() => _apply(asMap((r as Map)['data'])));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  void _shiftWeek(int weeks) {
    final f = DateTime.parse(_from!).add(Duration(days: 7 * weeks));
    final mon = f.subtract(Duration(days: f.weekday - 1));
    _from = isoDate(mon);
    _to = isoDate(mon.add(const Duration(days: 4)));
    _load();
  }

  Future<void> _pickPeriod() async {
    final r = await showDateRangePicker(context: context, firstDate: DateTime(2020), lastDate: DateTime.now().add(const Duration(days: 31)),
        initialDateRange: DateTimeRange(start: DateTime.parse(_from!), end: DateTime.parse(_to!)));
    if (r == null) return;
    _from = isoDate(r.start);
    _to = isoDate(r.end);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_r == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const SizedBox(height: 300, child: Center(child: CircularProgressIndicator()));
    final r = _r!;
    final items = [for (final i in (r['items'] as List)) asMap(i)];
    final sections = asMap(r['sections']);
    final cur = _s.currency;
    final hint = Theme.of(context).hintColor;
    final narrow = MediaQuery.of(context).size.width < 700;
    final mins = (r['stats']['minutes'] as num?)?.toInt() ?? 0;
    final included = items.where((i) => i['included'] != false).length;
    final mine = widget.reportId == null;
    final grouped = <String, List<Map<String, dynamic>>>{};
    for (final i in items) {
      grouped.putIfAbsent('${i['section']}', () => []).add(i);
    }
    return ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 14, narrow ? 16 : 24, 32), children: [
      Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
        if (mine) ...[
          IconButton(tooltip: tr('Semaine précédente'), onPressed: () => _shiftWeek(-1), icon: const Icon(Icons.chevron_left)),
          InkWell(
            onTap: _pickPeriod,
            borderRadius: BorderRadius.circular(8),
            child: Padding(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6), child: Text(I18n.lang == 'fr' ? 'Semaine ${r['period_label']}' : verbatim('Week of ${r['period_label']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
          ),
          IconButton(tooltip: tr('Semaine suivante'), onPressed: () => _shiftWeek(1), icon: const Icon(Icons.chevron_right)),
        ] else
          Text(tr('${r['author']} · ${r['period_label']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        StatusChip('${r['status_label']}', color: _statusColor(r['status'] as String?)),
        if (r['supervisor'] != null && r['status'] != 'draft') Text(tr('→ ${r['supervisor']}'), style: TextStyle(color: hint)),
      ]),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 8, children: [
        if (_editable) ...[
          OutlinedButton.icon(onPressed: () => _save(), icon: const Icon(Icons.save_outlined, size: 18), label: Text(tr('Enregistrer'))),
          OutlinedButton.icon(
            onPressed: () async {
              await addToReport(context, title: '');
              _load();
            },
            icon: const Icon(Icons.add, size: 18),
            label: Text(tr('Ajouter une activité')),
          ),
        ],
        OutlinedButton.icon(onPressed: () => _download('pdf'), icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(tr('PDF'))),
        OutlinedButton.icon(onPressed: () => _download('word'), icon: const Icon(Icons.description_outlined, size: 18), label: Text(tr('Word'))),
        if (_editable) FilledButton.icon(onPressed: _busy ? null : _send, icon: const Icon(Icons.send, size: 18), label: Text(tr('Envoyer à mon supérieur'))),
        if (r['can']?['review'] == true) ...[
          OutlinedButton.icon(onPressed: () => _review('returned'), icon: const Icon(Icons.undo, size: 18), label: Text(tr('À compléter'))),
          FilledButton.icon(onPressed: () => _review('approved'), icon: const Icon(Icons.verified_outlined, size: 18), label: Text(tr('Valider'))),
        ],
      ]),
      if (r['status'] == 'returned' || (r['review_comment'] != null && r['status'] == 'approved'))
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: Card(
            color: (r['status'] == 'returned' ? YColors.danger : YColors.success).withValues(alpha: .07),
            child: ListTile(
              leading: Icon(r['status'] == 'returned' ? Icons.feedback_outlined : Icons.verified, color: r['status'] == 'returned' ? YColors.danger : YColors.success),
              title: Text(tr(r['status'] == 'returned' ? 'À compléter — ${r['reviewed_by'] ?? ''}' : 'Validé par ${r['reviewed_by'] ?? ''}')),
              subtitle: Text(tr('${r['review_comment'] ?? ''}')),
            ),
          ),
        ),
      const SizedBox(height: 12),
      StatStrip(padding: EdgeInsets.zero, tiles: [
        StatTile(label: tr('Activités dans le rapport'), value: '$included', detail: tr('${items.length - included} retirée(s)'), icon: Icons.checklist),
        StatTile(label: tr('Temps déclaré'), value: mins == 0 ? '—' : '${mins ~/ 60} h ${(mins % 60).toString().padLeft(2, '0')}', icon: Icons.timer_outlined, color: const Color(0xFF9333EA)),
        StatTile(label: tr('Rubriques'), value: '${grouped.length}', detail: grouped.keys.take(3).map((k) => sections[k] ?? k).join(', '), icon: Icons.category_outlined, color: YColors.success),
      ]),
      const SizedBox(height: 14),
      if (items.isEmpty)
        SectionCard(
          child: Text(tr(mine ? 'Rien n\'a encore été enregistré dans le logiciel sur cette période. Vos ventes, visites, publications, tâches… apparaîtront ici automatiquement ; vous pouvez aussi ajouter une activité.' : 'Aucune activité.'), style: TextStyle(color: hint)),
        ),
      for (final e in grouped.entries)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: SectionCard(
            title: '${sections[e.key] ?? e.key} (${e.value.where((i) => i['included'] != false).length})',
            trailing: Icon(_sectionIcons[e.key] ?? Icons.label_outline, color: YColors.blue, size: 20),
            child: Column(children: [for (final i in e.value) _item(i, cur)]),
          ),
        ),
      const SizedBox(height: 4),
      SectionCard(
        title: tr('Synthèse et commentaires'),
        child: Column(children: [
          _text(_summary, 'Synthèse de la période', 'Les faits marquants, résultats obtenus…'),
          _text(_diff, 'Difficultés rencontrées', 'Blocages, besoins, alertes pour votre supérieur'),
          _text(_next, 'Priorités de la période suivante', null),
        ]),
      ),
    ]);
  }

  Widget _text(TextEditingController c, String label, String? hint) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(controller: c, readOnly: !_editable, minLines: 2, maxLines: 8, decoration: InputDecoration(labelText: trn(label), hintText: trn(hint), alignLabelWithHint: true)),
      );

  Widget _item(Map<String, dynamic> i, String cur) {
    final hint = Theme.of(context).hintColor;
    final on = i['included'] != false;
    final amount = (i['amount'] as num?)?.toDouble();
    final mins = (i['minutes'] as num?)?.toInt();
    final value = amount != null && amount != 0 ? money(amount, cur) : (mins != null && mins > 0 ? '${mins ~/ 60} h ${(mins % 60).toString().padLeft(2, '0')}' : null);
    return Opacity(
      opacity: on ? 1 : .45,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (_editable)
            Checkbox(
              value: on,
              onChanged: (v) {
                setState(() => i['included'] = v == true);
                _autosave?.cancel();
                _autosave = Timer(const Duration(milliseconds: 600), () => _save(quiet: true));
              },
            ),
          SizedBox(width: 48, child: Padding(padding: const EdgeInsets.only(top: 12), child: Text(tr(dateFr(i['date']).substring(0, 5)), style: TextStyle(color: hint, fontSize: 12.5)))),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Flexible(child: Text(tr('${i['title']}'), style: TextStyle(fontWeight: FontWeight.w600, decoration: on ? null : TextDecoration.lineThrough))),
                  if (i['manual'] == true) Padding(padding: EdgeInsets.only(left: 6), child: Tooltip(message: tr('Ajoutée par vous'), child: Icon(Icons.person_outline, size: 15, color: YColors.blue))),
                ]),
                if ((i['details'] ?? '').toString().isNotEmpty) Text(tr('${i['details']}'), style: TextStyle(color: hint, fontSize: 12.5)),
              ]),
            ),
          ),
          if (value != null) Padding(padding: const EdgeInsets.only(top: 10, left: 8), child: Text(tr(value), style: const TextStyle(fontWeight: FontWeight.w600))),
          if (_editable && i['manual'] == true)
            IconButton(
              tooltip: tr('Supprimer'),
              icon: const Icon(Icons.delete_outline, size: 18),
              onPressed: () async {
                await _s.api.delete('activite/entries/${i['entry_id']}');
                _load();
              },
            ),
        ]),
      ),
    );
  }
}
