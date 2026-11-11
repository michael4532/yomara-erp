import 'dart:convert';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/pdf_viewer.dart';

const _finalActions = {'destroy': 'Élimination', 'keep': 'Conservation définitive', 'review': 'Tri (à examiner)'};
const _levels = {'public': 'Public', 'internal': 'Interne', 'confidential': 'Confidentiel', 'secret': 'Secret'};
const _statuses = {'active': 'En conservation', 'on_loan': 'Prêtée', 'pending_disposal': 'Élimination proposée', 'disposed': 'Éliminée'};
const _eventLabels = {
  'archived': 'Archivée', 'viewed': 'Consultée', 'downloaded': 'Téléchargée', 'verified': 'Intégrité contrôlée', 'updated': 'Notice modifiée',
  'hold_on': 'Gel juridique posé', 'hold_off': 'Gel juridique levé', 'loan_requested': 'Prêt demandé', 'loan_approved': 'Prêt accordé',
  'loan_rejected': 'Prêt refusé', 'loan_returned': 'Retour', 'disposal_proposed': 'Élimination proposée', 'disposed': 'Éliminée',
  'disposal_approve': 'Bordereau visé', 'disposal_execute': 'Élimination exécutée', 'disposal_cancel': 'Bordereau annulé', 'series': 'Plan de classement',
};

Color _levelColor(String? l) => switch (l) { 'secret' => YColors.danger, 'confidential' => YColors.warning, 'public' => YColors.success, _ => YColors.blue };

String _size(num? b) {
  final v = (b ?? 0).toDouble();
  if (v < 1024) return '${v.toInt()} o';
  if (v < 1048576) return '${(v / 1024).toStringAsFixed(0)} Ko';
  if (v < 1073741824) return '${(v / 1048576).toStringAsFixed(1)} Mo';
  return '${(v / 1073741824).toStringAsFixed(2)} Go';
}

/// Archives : le poste de travail de l'archiviste.
class ArchivesScreen extends StatefulWidget {
  const ArchivesScreen({super.key});

  @override
  State<ArchivesScreen> createState() => _ArchivesScreenState();
}

class _ArchivesScreenState extends State<ArchivesScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  Map<String, dynamic>? _dash;
  List<Map<String, dynamic>> _series = [];
  List<Map<String, dynamic>> _records = [];
  int _total = 0;
  String _q = '';
  int? _seriesFilter;
  String? _status;
  bool _expired = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 6, vsync: this);
    _reload();
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  bool get _manage => context.read<Session>().can('archives.manage');

  Future<void> _reload() async {
    final api = context.read<Session>().api;
    try {
      final r = await Future.wait([api.get('archives/dashboard'), api.get('archives/series'), _fetch()]);
      if (!mounted) return;
      setState(() {
        _dash = Map<String, dynamic>.from(r[0]['data']);
        _series = [for (final s in r[1]['data'] as List) Map<String, dynamic>.from(s)];
        _loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _loading = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  Future<dynamic> _fetch() async {
    final r = await context.read<Session>().api.get('archives/records', query: {
      if (_q.isNotEmpty) 'q': _q,
      if (_seriesFilter != null) 'series_id': '$_seriesFilter',
      if (_status != null) 'status': _status,
      if (_expired) 'expired': '1',
    });
    if (mounted) {
      setState(() {
        _records = [for (final x in r['data'] as List) Map<String, dynamic>.from(x)];
        _total = r['total'] as int? ?? _records.length;
      });
    }
    return r;
  }

  String _seriesLabel(int? id) {
    final s = _series.where((x) => x['id'] == id).firstOrNull;
    return s == null ? '—' : '${s['code']} ${s['name']}';
  }

  // ---------- Versement ----------

  Future<void> _deposit() async {
    final picked = await FilePicker.pickFiles();
    if (picked.isEmpty || !mounted) return;
    final title = TextEditingController();
    final location = TextEditingController();
    final keywords = TextEditingController();
    final dept = TextEditingController();
    final transfer = TextEditingController();
    final date = TextEditingController(text: isoDate(DateTime.now()));
    int? series = _seriesFilter;
    String? level;
    final ok = await showFormDialog<bool>(
      context,
      title: 'Verser aux archives',
      subtitle: '${picked.length} fichier(s) : ${picked.map((f) => f.name).take(3).join(', ')}${picked.length > 3 ? '…' : ''}',
      icon: Icons.move_to_inbox_outlined,
      builder: (dc) => StatefulBuilder(builder: (dc, setD) {
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          Field(
            label: tr('Série du plan de classement'),
            child: DropdownButtonFormField<int?>(
              initialValue: series,
              isExpanded: true,
              items: [
                DropdownMenuItem(value: null, child: Text(tr('Non classé'))),
                for (final s in _series) DropdownMenuItem(value: s['id'] as int, child: Text(tr('${s['code']} ${s['name']} · ${s['retention_years'] == 0 ? 'définitif' : '${s['retention_years']} ans'}'), overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setD(() => series = v),
            ),
          ),
          Field(label: tr('Intitulé (facultatif : sinon le nom du fichier)'), child: TextField(controller: title)),
          Row(children: [
            Expanded(child: Field(label: tr('Date du document'), child: TextField(controller: date, decoration: const InputDecoration(hintText: 'AAAA-MM-JJ')))),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Confidentialité'),
                child: DropdownButtonFormField<String?>(
                  initialValue: level,
                  items: [DropdownMenuItem(value: null, child: Text(tr('Celle de la série'))), for (final e in _levels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                  onChanged: (v) => setD(() => level = v),
                ),
              ),
            ),
          ]),
          Field(label: tr('Emplacement physique'), child: TextField(controller: location, decoration: InputDecoration(hintText: tr('Salle B · Rayon 3 · Boîte 12')))),
          Row(children: [
            Expanded(child: Field(label: tr('Service versant'), child: TextField(controller: dept))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Bordereau de versement'), child: TextField(controller: transfer, decoration: InputDecoration(hintText: tr('VER-2026-01'))))),
          ]),
          Field(label: tr('Mots-clés'), child: TextField(controller: keywords)),
          const SizedBox(height: 8),
          Align(alignment: Alignment.centerRight, child: FilledButton.icon(onPressed: () => Navigator.pop(dc, true), icon: const Icon(Icons.lock_outline), label: Text(tr('Verser et sceller')))),
        ]);
      }),
    );
    if (ok != true || !mounted) return;
    final files = <String, (List<int>, String)>{};
    for (final (i, f) in picked.indexed) {
      files['files[$i]'] = (await f.readAsBytes(), f.name);
    }
    if (!mounted) return;
    try {
      final r = await context.read<Session>().api.uploadFiles('archives/records', {
        'title': title.text.trim().isEmpty ? null : title.text.trim(),
        'doc_date': date.text.trim().isEmpty ? null : date.text.trim(),
        'series_id': series,
        'confidentiality': level,
        'location': location.text.trim().isEmpty ? null : location.text.trim(),
        'keywords': keywords.text.trim().isEmpty ? null : keywords.text.trim(),
        'department': dept.text.trim().isEmpty ? null : dept.text.trim(),
        'transfer_ref': transfer.text.trim().isEmpty ? null : transfer.text.trim(),
      }, files);
      if (mounted) toast(context, tr('${r['data']['count']} archive(s) versée(s), cotée(s) et scellée(s).'));
      await _reload();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  // ---------- Fiche d'une archive ----------

  Future<void> _open(String id) async {
    await showFormDialog(context, title: 'Archive', icon: Icons.inventory_2_outlined, maxWidth: 820, builder: (_) => _RecordSheet(id: id, series: _series, manage: _manage));
    if (mounted) _fetch();
  }

  Widget _recordTile(Map<String, dynamic> x) {
    final hint = Theme.of(context).hintColor;
    final expired = x['retention_until'] != null && '${x['retention_until']}'.compareTo(isoDate(DateTime.now())) < 0;
    return Card(
      child: ListTile(
        onTap: () => _open('${x['id']}'),
        leading: CircleAvatar(
          backgroundColor: _levelColor(x['confidentiality'] as String?).withValues(alpha: .12),
          child: Icon(x['source_type'] != null && x['file_name'] == null ? Icons.receipt_long_outlined : Icons.description_outlined, color: _levelColor(x['confidentiality'] as String?)),
        ),
        title: Text(tr('${x['title']}'), style: const TextStyle(fontWeight: FontWeight.w700), maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: Text(
          tr([
            '${x['cote']}',
            if (x['doc_date'] != null) dateFr(x['doc_date']),
            x['series'] ?? 'Non classé',
            if (x['location'] != null) 'Emplacement : ${x['location']}',
          ].join(' · ')),
          style: TextStyle(color: hint),
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
          if (x['legal_hold'] == true) StatusChip(tr('Gel juridique'), color: YColors.danger, icon: Icons.gavel),
          if (x['status'] != 'active') StatusChip(tr(_statuses[x['status']] ?? '${x['status']}'), color: x['status'] == 'disposed' ? Colors.grey : YColors.warning),
          if (x['status'] == 'active' && expired) StatusChip(tr('Terme atteint'), color: YColors.warning, icon: Icons.hourglass_bottom),
          if (x['sha256'] != null) Tooltip(message: tr('Scellée : empreinte SHA-256'), child: const Icon(Icons.verified_user_outlined, size: 18, color: YColors.success)),
        ]),
      ),
    );
  }

  // ---------- Onglets ----------

  Widget _dashboard() {
    final d = _dash ?? const {};
    Widget stat(String label, String value, IconData icon, Color color, {VoidCallback? onTap}) => SizedBox(
          width: 230,
          child: Card(
            child: InkWell(
              onTap: onTap,
              borderRadius: BorderRadius.circular(12),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Row(children: [
                  CircleAvatar(backgroundColor: color.withValues(alpha: .12), child: Icon(icon, color: color)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(value, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                      Text(tr(label), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                    ]),
                  ),
                ]),
              ),
            ),
          ),
        );
    final years = Map<String, dynamic>.from(d['by_year'] as Map? ?? const {});
    final maxN = years.values.fold<num>(1, (a, b) => (b as num) > a ? b : a);
    return ListView(padding: const EdgeInsets.all(16), children: [
      Wrap(spacing: 12, runSpacing: 12, children: [
        stat('Archives conservées', '${d['total'] ?? 0}', Icons.inventory_2_outlined, YColors.blue, onTap: () => _tabs.animateTo(1)),
        stat('Volume occupé', _size(d['bytes'] as num?), Icons.storage_outlined, const Color(0xFF7C3AED)),
        stat('Versées cette année', '${d['this_year'] ?? 0}', Icons.move_to_inbox_outlined, YColors.success),
        stat('À éliminer (terme atteint)', '${d['to_dispose'] ?? 0}', Icons.delete_sweep_outlined, YColors.warning, onTap: () => _tabs.animateTo(4)),
        stat('À trier (terme atteint)', '${d['to_review'] ?? 0}', Icons.rule_folder_outlined, YColors.warning, onTap: () {
          setState(() => _expired = true);
          _fetch();
          _tabs.animateTo(1);
        }),
        stat('Prêts en cours', '${d['on_loan'] ?? 0}${(d['overdue_loans'] ?? 0) > 0 ? ' (${d['overdue_loans']} en retard)' : ''}', Icons.outbox_outlined, YColors.blue, onTap: () => _tabs.animateTo(3)),
        stat('Demandes à traiter', '${d['loan_requests'] ?? 0}', Icons.pending_actions_outlined, YColors.danger, onTap: () => _tabs.animateTo(3)),
        stat('Gels juridiques', '${d['legal_hold'] ?? 0}', Icons.gavel_outlined, YColors.danger),
      ]),
      const SizedBox(height: 16),
      SectionCard(
        title: tr('Fonds par année des documents'),
        child: years.isEmpty
            ? Text(tr('Aucune archive pour l\'instant : les factures validées sont archivées automatiquement, et vous pouvez verser vos scans.'))
            : Column(children: [
                for (final e in years.entries)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 3),
                    child: Row(children: [
                      SizedBox(width: 50, child: Text(e.key)),
                      Expanded(
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(4),
                          child: LinearProgressIndicator(value: (e.value as num) / maxN, minHeight: 12, backgroundColor: YColors.line, color: YColors.blue),
                        ),
                      ),
                      SizedBox(width: 60, child: Text('${e.value}', textAlign: TextAlign.right)),
                    ]),
                  ),
              ]),
      ),
      if (_manage) ...[
        const SizedBox(height: 16),
        SectionCard(
          title: tr('Intégrité du fonds'),
          child: Row(children: [
            Expanded(child: Text(tr('Chaque archive est scellée par une empreinte SHA-256 au versement. Le contrôle recalcule les empreintes et signale toute altération.'))),
            const SizedBox(width: 12),
            FilledButton.tonalIcon(onPressed: _verifyAll, icon: const Icon(Icons.verified_user_outlined), label: Text(tr('Contrôler tout le fonds'))),
          ]),
        ),
      ],
    ]);
  }

  Future<void> _verifyAll() async {
    try {
      final r = Map<String, dynamic>.from((await context.read<Session>().api.post('archives/verify-all'))['data']);
      if (!mounted) return;
      final bad = r['altered'] as List;
      await showDialog(
        context: context,
        builder: (dc) => YAlertDialog(
          title: Text(tr(bad.isEmpty ? 'Fonds intègre' : 'Altérations détectées')),
          content: Text(tr(bad.isEmpty
              ? '${r['checked']} archive(s) contrôlée(s) : toutes les empreintes sont conformes.'
              : '${r['ok']} conforme(s), ${bad.length} altérée(s) :\n${bad.map((b) => '• ${b['cote']} — ${b['message']}').join('\n')}')),
          actions: [TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Fermer')))],
        ),
      );
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Widget _search() => Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SizedBox(
              width: 320,
              child: TextField(
                decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: tr('Cote, intitulé, mots-clés, emplacement…'), isDense: true),
                onSubmitted: (v) {
                  _q = v.trim();
                  _fetch();
                },
              ),
            ),
            SizedBox(
              width: 260,
              child: DropdownButtonFormField<int?>(
                initialValue: _seriesFilter,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Série'), isDense: true),
                items: [DropdownMenuItem(value: null, child: Text(tr('Toutes les séries'))), for (final s in _series) DropdownMenuItem(value: s['id'] as int, child: Text(tr('${s['code']} ${s['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) {
                  _seriesFilter = v;
                  _fetch();
                },
              ),
            ),
            SizedBox(
              width: 200,
              child: DropdownButtonFormField<String?>(
                initialValue: _status,
                decoration: InputDecoration(labelText: tr('État'), isDense: true),
                items: [DropdownMenuItem(value: null, child: Text(tr('Conservées'))), for (final e in _statuses.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) {
                  _status = v;
                  _fetch();
                },
              ),
            ),
            FilterChip(label: Text(tr('Terme atteint')), selected: _expired, onSelected: (v) {
              _expired = v;
              _fetch();
            }),
            Text(tr('$_total archive(s)'), style: TextStyle(color: Theme.of(context).hintColor)),
          ]),
        ),
        Expanded(
          child: _records.isEmpty
              ? EmptyState(icon: Icons.inventory_2_outlined, title: tr('Aucune archive'), message: tr('Versez vos scans et documents, ou validez une facture : elle sera archivée automatiquement.'), action: _manage ? FilledButton.icon(onPressed: _deposit, icon: const Icon(Icons.move_to_inbox_outlined), label: Text(tr('Verser'))) : null)
              : ListView(padding: const EdgeInsets.all(16), children: [for (final x in _records) _recordTile(x)]),
        ),
      ]);

  Widget _plan() {
    List<Widget> rows(int? parent, int depth) => [
          for (final s in _series.where((x) => x['parent_id'] == parent)) ...[
            Card(
              margin: EdgeInsets.only(left: depth * 24.0, bottom: 6),
              child: ListTile(
                onTap: _manage ? () => _editSeries(s) : null,
                leading: CircleAvatar(child: Text('${s['code']}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800))),
                title: Text(tr('${s['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(tr([
                  s['retention_years'] == 0 ? 'Conservation définitive' : 'DUA ${s['retention_years']} ans',
                  _finalActions[s['final_action']] ?? '${s['final_action']}',
                  _levels[s['confidentiality']] ?? '',
                  if ((s['sources'] as List).isNotEmpty) 'Auto : ${(s['sources'] as List).length} type(s) de documents',
                ].join(' · '))),
                trailing: Text(tr('${s['count']} · ${_size(s['bytes'] as num?)}'), style: TextStyle(color: Theme.of(context).hintColor)),
              ),
            ),
            ...rows(s['id'] as int, depth + 1),
          ],
        ];
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        Expanded(child: Text(tr('Durées de conservation (DUA) et sort final proposés d\'après l\'Acte uniforme OHADA (pièces comptables : 10 ans). Adaptez-les avec votre conseil.'), style: TextStyle(color: Theme.of(context).hintColor))),
        if (_manage) FilledButton.icon(onPressed: () => _editSeries(null), icon: const Icon(Icons.add), label: Text(tr('Série'))),
      ]),
      const SizedBox(height: 12),
      ...rows(null, 0),
    ]);
  }

  Future<void> _editSeries(Map<String, dynamic>? s) async {
    final code = TextEditingController(text: '${s?['code'] ?? ''}');
    final name = TextEditingController(text: '${s?['name'] ?? ''}');
    final desc = TextEditingController(text: '${s?['description'] ?? ''}');
    final years = TextEditingController(text: '${s?['retention_years'] ?? 10}');
    String action = '${s?['final_action'] ?? 'destroy'}';
    String level = '${s?['confidentiality'] ?? 'internal'}';
    int? parent = s?['parent_id'] as int?;
    final sources = <String>{...((s?['sources'] as List?) ?? const []).map((e) => '$e')};
    final allSources = Map<String, dynamic>.from(_dash?['sources'] as Map? ?? const {});
    await showFormDialog(
      context,
      title: s == null ? 'Nouvelle série' : 'Série ${s['code']}',
      icon: Icons.account_tree_outlined,
      builder: (dc) => StatefulBuilder(builder: (dc, setD) {
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          Row(children: [
            SizedBox(width: 110, child: Field(label: tr('Code'), child: TextField(controller: code))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Intitulé'), child: TextField(controller: name))),
          ]),
          Field(
            label: tr('Série parente'),
            child: DropdownButtonFormField<int?>(
              initialValue: parent,
              isExpanded: true,
              items: [DropdownMenuItem(value: null, child: Text(tr('Aucune (série principale)'))), for (final p in _series.where((p) => p['id'] != s?['id'])) DropdownMenuItem(value: p['id'] as int, child: Text(tr('${p['code']} ${p['name']}')))],
              onChanged: (v) => setD(() => parent = v),
            ),
          ),
          Row(children: [
            SizedBox(width: 150, child: Field(label: tr('Durée (ans, 0 = définitif)'), child: TextField(controller: years, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly]))),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Sort final'),
                child: DropdownButtonFormField<String>(initialValue: action, items: [for (final e in _finalActions.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))], onChanged: (v) => setD(() => action = v ?? action)),
              ),
            ),
          ]),
          Field(
            label: tr('Confidentialité par défaut'),
            child: DropdownButtonFormField<String>(initialValue: level, items: [for (final e in _levels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))], onChanged: (v) => setD(() => level = v ?? level)),
          ),
          Field(label: tr('Description'), child: TextField(controller: desc, maxLines: 2)),
          Text(tr('Archivage automatique à la validation'), style: const TextStyle(fontWeight: FontWeight.w700)),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final e in allSources.entries)
              FilterChip(label: Text(tr('${e.value}')), selected: sources.contains(e.key), onSelected: (v) => setD(() => v ? sources.add(e.key) : sources.remove(e.key))),
          ]),
          const SizedBox(height: 12),
          Row(children: [
            if (s != null)
              TextButton.icon(
                style: TextButton.styleFrom(foregroundColor: YColors.danger),
                onPressed: () async {
                  try {
                    final r = await context.read<Session>().api.delete('archives/series/${s['id']}');
                    setState(() => _series = [for (final x in r['data'] as List) Map<String, dynamic>.from(x)]);
                    if (dc.mounted) Navigator.pop(dc);
                  } catch (e) {
                    if (dc.mounted) toast(dc, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.delete_outline),
                label: Text(tr('Supprimer')),
              ),
            const Spacer(),
            SaveButton(
              onPressed: () async {
                final body = {'code': code.text.trim(), 'name': name.text.trim(), 'description': desc.text.trim().isEmpty ? null : desc.text.trim(), 'parent_id': parent,
                  'retention_years': int.tryParse(years.text) ?? 0, 'final_action': action, 'confidentiality': level, 'sources': sources.toList()};
                try {
                  final api = context.read<Session>().api;
                  final r = s == null ? await api.post('archives/series', body) : await api.put('archives/series/${s['id']}', body);
                  setState(() => _series = [for (final x in r['data'] as List) Map<String, dynamic>.from(x)]);
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ]),
        ]);
      }),
    );
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return Scaffold(
      body: Column(children: [
        PageHeader(
          title: tr('Archives'),
          subtitle: tr('Conservation légale : plan de classement, cotes, intégrité, prêts et éliminations'),
          icon: Icons.inventory_2_outlined,
          actions: [
            IconButton(tooltip: tr('Actualiser'), onPressed: _reload, icon: const Icon(Icons.refresh)),
            if (session.can('archives.manage')) FilledButton.icon(onPressed: _deposit, icon: const Icon(Icons.move_to_inbox_outlined), label: Text(tr('Verser'))),
          ],
        ),
        TabBar(controller: _tabs, isScrollable: true, tabAlignment: TabAlignment.start, tabs: [
          Tab(text: tr('Tableau de bord')),
          Tab(text: tr('Recherche')),
          Tab(text: tr('Plan de classement')),
          Tab(text: tr('Prêts et consultations')),
          Tab(text: tr('Éliminations')),
          Tab(text: tr('Journal')),
        ]),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : TabBarView(controller: _tabs, children: [
                  _dashboard(),
                  _search(),
                  _plan(),
                  _manage ? const _LoansTab() : _noAccess(),
                  _manage || session.can('archives.dispose') ? _DisposalsTab(seriesLabel: _seriesLabel, onChanged: _reload) : _noAccess(),
                  _manage ? const _JournalTab() : _noAccess(),
                ]),
        ),
      ]),
    );
  }

  Widget _noAccess() => EmptyState(icon: Icons.lock_outline, title: tr('Réservé à l\'archiviste'), message: tr('Demandez le droit « Archives : archiviste » à l\'administrateur.'));
}

/// Fiche d'une archive : notice, contenu, intégrité, prêts, historique.
class _RecordSheet extends StatefulWidget {
  const _RecordSheet({required this.id, required this.series, required this.manage});

  final String id;
  final List<Map<String, dynamic>> series;
  final bool manage;

  @override
  State<_RecordSheet> createState() => _RecordSheetState();
}

class _RecordSheetState extends State<_RecordSheet> {
  Map<String, dynamic>? _x;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('archives/records/${widget.id}');
      if (mounted) setState(() => _x = Map<String, dynamic>.from(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _update(Map<String, dynamic> body) async {
    try {
      await context.read<Session>().api.put('archives/records/${widget.id}', body);
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _openContent() async {
    final x = _x!;
    try {
      final bytes = await context.read<Session>().api.download('archives/records/${widget.id}/download');
      if (!mounted) return;
      final name = '${x['file_name'] ?? '${x['cote']}.json'}';
      if ('${x['mime']}'.contains('pdf') || name.toLowerCase().endsWith('.pdf')) {
        await showPdf(context, bytes, name);
      } else {
        await saveFile(name, bytes, mime: '${x['mime'] ?? 'application/octet-stream'}');
        if (mounted) toast(context, tr('Fichier enregistré.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _verify() async {
    try {
      final r = Map<String, dynamic>.from((await context.read<Session>().api.post('archives/records/${widget.id}/verify'))['data']);
      if (mounted) toast(context, tr('${r['message']}'), error: r['ok'] != true);
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _loan() async {
    final purpose = TextEditingController();
    final borrower = TextEditingController(text: '${context.read<Session>().user['name'] ?? ''}');
    String kind = 'consultation';
    DateTime? due;
    final ok = await showFormDialog<bool>(
      context,
      title: 'Demande de consultation ou de prêt',
      builder: (dc) => StatefulBuilder(builder: (dc, setD) {
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'consultation', label: Text(tr('Consultation'))), ButtonSegment(value: 'pret', label: Text(tr('Prêt (sortie du rayon)')))],
            selected: {kind},
            onSelectionChanged: (v) => setD(() => kind = v.first),
          ),
          const SizedBox(height: 12),
          Field(label: tr('Demandeur'), child: TextField(controller: borrower)),
          Field(label: tr('Motif'), child: TextField(controller: purpose, maxLines: 2, decoration: InputDecoration(hintText: tr('Contrôle fiscal, litige, audit…')))),
          if (kind == 'pret')
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event),
              title: Text(tr(due == null ? 'Date de retour prévue' : 'Retour le ${dateFr(isoDate(due!))}')),
              onTap: () async {
                final d = await showDatePicker(context: dc, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)), initialDate: DateTime.now().add(const Duration(days: 14)));
                if (d != null) setD(() => due = d);
              },
            ),
          Align(alignment: Alignment.centerRight, child: FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Envoyer la demande')))),
        ]);
      }),
    );
    if (ok != true || !mounted) return;
    try {
      await context.read<Session>().api.post('archives/records/${widget.id}/loans', {'kind': kind, 'borrower': borrower.text.trim(), 'purpose': purpose.text.trim(), if (due != null) 'due_date': isoDate(due!)});
      if (mounted) toast(context, tr('Demande transmise à l\'archiviste.'));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _editText(String field, String label) async {
    final ctl = TextEditingController(text: '${_x![field] ?? ''}');
    final v = await showDialog<String>(
      context: context,
      builder: (dc) => YAlertDialog(
        title: Text(tr(label)),
        content: SizedBox(width: 420, child: TextField(controller: ctl, autofocus: true, maxLines: field == 'description' ? 4 : 1)),
        actions: [TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, ctl.text), child: Text(tr('Enregistrer')))],
      ),
    );
    if (v != null) await _update({field: v.trim().isEmpty ? null : v.trim()});
  }

  @override
  Widget build(BuildContext context) {
    final x = _x;
    if (x == null) return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
    final hint = Theme.of(context).hintColor;
    final disposed = x['status'] == 'disposed';
    Widget row(String k, String? v, {String? field}) => ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          title: Text(tr(k), style: TextStyle(color: hint, fontSize: 12)),
          subtitle: Text(tr(v == null || v.isEmpty ? '—' : v), style: const TextStyle(fontSize: 14)),
          trailing: field != null && widget.manage && !disposed ? IconButton(icon: const Icon(Icons.edit_outlined, size: 18), onPressed: () => _editText(field, k)) : null,
        );
    final snapshot = x['snapshot'] as Map?;
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
      Text(tr('${x['title']}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
      const SizedBox(height: 6),
      Wrap(spacing: 6, runSpacing: 6, children: [
        StatusChip(tr('${x['cote']}'), color: YColors.blue, icon: Icons.tag),
        StatusChip(tr(_levels[x['confidentiality']] ?? ''), color: _levelColor(x['confidentiality'] as String?), icon: Icons.shield_outlined),
        StatusChip(tr(_statuses[x['status']] ?? ''), color: x['status'] == 'active' ? YColors.success : YColors.warning),
        if (x['legal_hold'] == true) StatusChip(tr('Gel juridique'), color: YColors.danger, icon: Icons.gavel),
      ]),
      const SizedBox(height: 10),
      Wrap(spacing: 8, runSpacing: 8, children: [
        if (x['has_content'] == true && !disposed) FilledButton.icon(onPressed: _openContent, icon: const Icon(Icons.visibility_outlined), label: Text(tr(x['file_name'] == null ? 'Télécharger l\'instantané' : 'Ouvrir'))),
        if (!disposed) OutlinedButton.icon(onPressed: _verify, icon: const Icon(Icons.verified_user_outlined), label: Text(tr('Contrôler l\'intégrité'))),
        if (!disposed && x['status'] != 'pending_disposal') OutlinedButton.icon(onPressed: _loan, icon: const Icon(Icons.outbox_outlined), label: Text(tr('Demander'))),
        if (widget.manage && !disposed)
          OutlinedButton.icon(
            onPressed: () => _update({'legal_hold': x['legal_hold'] != true}),
            icon: Icon(x['legal_hold'] == true ? Icons.lock_open : Icons.gavel_outlined),
            label: Text(tr(x['legal_hold'] == true ? 'Lever le gel juridique' : 'Geler (litige)')),
          ),
      ]),
      const Divider(height: 28),
      Wrap(spacing: 24, children: [
        SizedBox(
          width: 360,
          child: Column(children: [
            row('Intitulé', '${x['title']}', field: 'title'),
            row('Description', x['description'] as String?, field: 'description'),
            row('Date du document', x['doc_date'] == null ? null : dateFr(x['doc_date'])),
            row('Emplacement physique', x['location'] as String?, field: 'location'),
            row('Mots-clés', x['keywords'] as String?, field: 'keywords'),
            row('Service versant', x['department'] as String?, field: 'department'),
            row('Bordereau de versement', x['transfer_ref'] as String?, field: 'transfer_ref'),
          ]),
        ),
        SizedBox(
          width: 360,
          child: Column(children: [
            if (widget.manage && !disposed)
              DropdownButtonFormField<int?>(
                initialValue: x['series_id'] as int?,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Série (reclasser)')),
                items: [DropdownMenuItem(value: null, child: Text(tr('Non classé'))), for (final s in widget.series) DropdownMenuItem(value: s['id'] as int, child: Text(tr('${s['code']} ${s['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => _update({'series_id': v}),
              )
            else
              row('Série', x['series'] as String?),
            row('Conservation jusqu\'au', x['retention_until'] == null ? 'Définitive' : dateFr(x['retention_until'])),
            row('Sort final', _finalActions[x['final_action']]),
            row('Fichier', x['file_name'] == null ? (snapshot != null ? 'Instantané du document de l\'ERP' : null) : '${x['file_name']} · ${_size(x['size'] as num?)}'),
            row('Scellée le', x['sealed_at'] == null ? null : dateFr(x['sealed_at'])),
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Empreinte SHA-256'), style: TextStyle(color: hint, fontSize: 12)),
              subtitle: SelectableText('${x['sha256'] ?? '—'}', style: const TextStyle(fontFamily: 'monospace', fontSize: 11)),
            ),
          ]),
        ),
      ]),
      if (snapshot != null) ...[
        const SizedBox(height: 8),
        SectionCard(
          title: tr('Contenu archivé'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${snapshot['number'] ?? ''} · ${snapshot['partner_name'] ?? ''} · ${dateFr(snapshot['date'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            for (final l in (snapshot['lines'] as List? ?? const [])) Text(tr('• ${l['description']} — ${l['quantity']} × ${money(toDouble(l['unit_price']), '${snapshot['currency'] ?? 'XOF'}')}')),
            const SizedBox(height: 4),
            Text(tr('Total TTC : ${money(toDouble(snapshot['total']), '${snapshot['currency'] ?? 'XOF'}')}'), style: const TextStyle(fontWeight: FontWeight.w700)),
          ]),
        ),
      ],
      if ((x['loans'] as List?)?.isNotEmpty == true) ...[
        const SizedBox(height: 8),
        Text(tr('Prêts et consultations'), style: const TextStyle(fontWeight: FontWeight.w800)),
        for (final l in x['loans'] as List) Text(tr('• ${l['kind'] == 'pret' ? 'Prêt' : 'Consultation'} — ${l['borrower']} — ${l['status']}${l['due_date'] != null ? ' (retour ${dateFr(l['due_date'])})' : ''}')),
      ],
      const SizedBox(height: 12),
      Text(tr('Historique'), style: const TextStyle(fontWeight: FontWeight.w800)),
      for (final e in (x['events'] as List? ?? const []))
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Text(tr('${dateFr(e['at'])} ${'${e['at']}'.length > 16 ? '${e['at']}'.substring(11, 16) : ''} · ${_eventLabels[e['action']] ?? e['action']}${e['user'] != null ? ' · ${e['user']}' : ''}${e['details'] != null ? ' — ${e['details']}' : ''}'),
              style: TextStyle(fontSize: 12, color: hint)),
        ),
    ]);
  }
}

class _LoansTab extends StatefulWidget {
  const _LoansTab();

  @override
  State<_LoansTab> createState() => _LoansTabState();
}

class _LoansTabState extends State<_LoansTab> {
  List<Map<String, dynamic>>? _rows;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('archives/loans');
      if (mounted) setState(() => _rows = [for (final x in r['data'] as List) Map<String, dynamic>.from(x)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _act(int id, String action) async {
    try {
      await context.read<Session>().api.post('archives/loans/$id', {'action': action});
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    if (rows == null) return const Center(child: CircularProgressIndicator());
    if (rows.isEmpty) return EmptyState(icon: Icons.outbox_outlined, title: tr('Aucune demande'), message: tr('Les demandes de consultation et de prêt apparaissent ici pour validation.'));
    return ListView(padding: const EdgeInsets.all(16), children: [
      for (final l in rows)
        Card(
          child: ListTile(
            leading: Icon(l['kind'] == 'pret' ? Icons.outbox_outlined : Icons.visibility_outlined, color: l['overdue'] == true ? YColors.danger : YColors.blue),
            title: Text(tr('${l['cote']} — ${l['title']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
            subtitle: Text(tr([
              '${l['kind'] == 'pret' ? 'Prêt' : 'Consultation'} pour ${l['borrower']}',
              if (l['purpose'] != null) '${l['purpose']}',
              if (l['due_date'] != null) 'retour ${dateFr(l['due_date'])}${l['overdue'] == true ? ' (EN RETARD)' : ''}',
              if (l['location'] != null) 'Emplacement : ${l['location']}',
            ].join(' · '))),
            trailing: Wrap(spacing: 6, children: [
              if (l['status'] == 'pending') ...[
                FilledButton(onPressed: () => _act(l['id'] as int, 'approve'), child: Text(tr('Accorder'))),
                OutlinedButton(onPressed: () => _act(l['id'] as int, 'reject'), child: Text(tr('Refuser'))),
              ] else if (l['status'] == 'approved' && l['kind'] == 'pret')
                FilledButton.tonal(onPressed: () => _act(l['id'] as int, 'return'), child: Text(tr('Retour enregistré')))
              else
                StatusChip(tr({'approved': 'Accordée', 'rejected': 'Refusée', 'returned': 'Rendue'}[l['status']] ?? '${l['status']}')),
            ]),
          ),
        ),
    ]);
  }
}

class _DisposalsTab extends StatefulWidget {
  const _DisposalsTab({required this.seriesLabel, required this.onChanged});

  final String Function(int?) seriesLabel;
  final Future<void> Function() onChanged;

  @override
  State<_DisposalsTab> createState() => _DisposalsTabState();
}

class _DisposalsTabState extends State<_DisposalsTab> {
  Map<String, dynamic>? _d;
  final _sel = <String>{};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('archives/disposals');
      if (mounted) setState(() => _d = Map<String, dynamic>.from(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _propose() async {
    try {
      await context.read<Session>().api.post('archives/disposals', {'ids': _sel.toList()});
      _sel.clear();
      await _load();
      await widget.onChanged();
      if (mounted) toast(context, tr('Bordereau d\'élimination créé : il doit être visé avant exécution.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _act(Map d, String action) async {
    final api = context.read<Session>().api;
    if (action == 'execute' &&
        !await confirm(context, tr('Exécuter l\'élimination'), tr('Les ${(d['records'] as List).length} archive(s) du bordereau ${d['number']} seront détruites définitivement. Leur notice et ce procès-verbal sont conservés.'), ok: tr('Éliminer'), danger: true)) {
      return;
    }
    try {
      final r = await api.post('archives/disposals/${d['id']}', {'action': action});
      if (mounted) setState(() => _d = Map<String, dynamic>.from(r['data']));
      await widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  /// Procès-verbal / bordereau d'élimination en PDF.
  Future<void> _pv(Map d) async {
    final company = context.read<Session>().company;
    final doc = pw.Document();
    final rows = List<Map>.from(d['records'] as List);
    doc.addPage(pw.MultiPage(
      pageFormat: PdfPageFormat.a4,
      build: (_) => [
        pw.Text(tr('${company['name'] ?? ''}'), style: pw.TextStyle(fontSize: 14, fontWeight: pw.FontWeight.bold)),
        pw.SizedBox(height: 12),
        pw.Text(tr(d['status'] == 'executed' ? 'PROCÈS-VERBAL D\'ÉLIMINATION D\'ARCHIVES' : 'BORDEREAU D\'ÉLIMINATION D\'ARCHIVES'), style: pw.TextStyle(fontSize: 16, fontWeight: pw.FontWeight.bold)),
        pw.Text(tr('N° ${d['number']} · établi le ${dateFr(d['created_at'])}${d['proposed_by'] != null ? ' par ${d['proposed_by']}' : ''}')),
        pw.SizedBox(height: 10),
        pw.Text(tr('Les documents ci-dessous ont atteint le terme de leur durée de conservation et ne font l\'objet d\'aucun gel juridique ni prêt en cours.')),
        pw.SizedBox(height: 10),
        pw.TableHelper.fromTextArray(
          headers: [tr('Cote'), tr('Intitulé'), tr('Date'), tr('Conservation jusqu\'au'), tr('Volume')],
          data: [for (final r in rows) [r['cote'], tr('${r['title']}'), dateFr(r['doc_date']), dateFr(r['retention_until']), _size(r['size'] as num?)]],
          cellStyle: const pw.TextStyle(fontSize: 8),
          headerStyle: pw.TextStyle(fontSize: 8, fontWeight: pw.FontWeight.bold),
        ),
        pw.SizedBox(height: 10),
        pw.Text(tr('Total : ${rows.length} archive(s), ${_size(rows.fold<num>(0, (a, r) => a + ((r['size'] as num?) ?? 0)))}.')),
        if (d['notes'] != null) pw.Text(tr('Observations : ${d['notes']}')),
        pw.SizedBox(height: 24),
        pw.Row(children: [
          pw.Expanded(child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [pw.Text(tr('L\'archiviste')), pw.SizedBox(height: 40), pw.Text(tr('${d['proposed_by'] ?? ''}'))])),
          pw.Expanded(child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [pw.Text(tr('Visa de la direction')), pw.SizedBox(height: 40), pw.Text(tr('${d['approved_by'] ?? ''}${d['approved_at'] != null ? ' le ${dateFr(d['approved_at'])}' : ''}'))])),
        ]),
        if (d['executed_at'] != null) ...[pw.SizedBox(height: 16), pw.Text(tr('Destruction exécutée le ${dateFr(d['executed_at'])} par ${d['executed_by'] ?? ''}.'))],
      ],
    ));
    final bytes = await doc.save();
    if (mounted) await showPdf(context, bytes, '${d['number']}.pdf');
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) return const Center(child: CircularProgressIndicator());
    final session = context.watch<Session>();
    final eligible = List<Map>.from(d['eligible'] as List);
    final list = List<Map>.from(d['disposals'] as List);
    return ListView(padding: const EdgeInsets.all(16), children: [
      SectionCard(
        title: tr('Arrivées au terme de leur conservation (${eligible.length})'),
        trailing: session.can('archives.manage') && _sel.isNotEmpty ? FilledButton.icon(onPressed: _propose, icon: const Icon(Icons.playlist_add_check), label: Text(tr('Proposer l\'élimination (${_sel.length})'))) : null,
        child: eligible.isEmpty
            ? Text(tr('Aucune archive à éliminer pour l\'instant.'))
            : Column(children: [
                CheckboxListTile(
                  dense: true,
                  value: _sel.length == eligible.length,
                  title: Text(tr('Tout sélectionner')),
                  onChanged: (v) => setState(() => v == true ? _sel.addAll(eligible.map((e) => '${e['id']}')) : _sel.clear()),
                ),
                for (final e in eligible)
                  CheckboxListTile(
                    dense: true,
                    value: _sel.contains('${e['id']}'),
                    title: Text(tr('${e['cote']} — ${e['title']}')),
                    subtitle: Text(tr('Document du ${dateFr(e['doc_date'])} · terme ${dateFr(e['retention_until'])} · ${_size(e['size'] as num?)}')),
                    onChanged: (v) => setState(() => v == true ? _sel.add('${e['id']}') : _sel.remove('${e['id']}')),
                  ),
              ]),
      ),
      const SizedBox(height: 16),
      Text(tr('Bordereaux'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      const SizedBox(height: 8),
      for (final x in list)
        Card(
          child: ListTile(
            leading: Icon(x['status'] == 'executed' ? Icons.delete_forever_outlined : Icons.assignment_outlined, color: x['status'] == 'executed' ? Colors.grey : YColors.warning),
            title: Text(tr('${x['number']} · ${(x['records'] as List).length} archive(s)')),
            subtitle: Text(tr({'proposed': 'À viser', 'approved': 'Visé, à exécuter', 'executed': 'Exécuté le ${dateFr(x['executed_at'])}', 'cancelled': 'Annulé'}[x['status']] ?? '${x['status']}')),
            trailing: Wrap(spacing: 6, children: [
              IconButton(tooltip: tr('Bordereau / procès-verbal PDF'), onPressed: () => _pv(x), icon: const Icon(Icons.picture_as_pdf_outlined)),
              if (x['status'] == 'proposed' && session.can('archives.dispose')) FilledButton(onPressed: () => _act(x, 'approve'), child: Text(tr('Viser'))),
              if (x['status'] == 'approved' && session.can('archives.dispose')) FilledButton(style: FilledButton.styleFrom(backgroundColor: YColors.danger), onPressed: () => _act(x, 'execute'), child: Text(tr('Exécuter'))),
              if (['proposed', 'approved'].contains(x['status']) && session.can('archives.dispose')) TextButton(onPressed: () => _act(x, 'cancel'), child: Text(tr('Annuler'))),
            ]),
          ),
        ),
    ]);
  }
}

class _JournalTab extends StatefulWidget {
  const _JournalTab();

  @override
  State<_JournalTab> createState() => _JournalTabState();
}

class _JournalTabState extends State<_JournalTab> {
  List<Map<String, dynamic>>? _rows;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('archives/journal').then((r) {
      if (mounted) setState(() => _rows = [for (final x in r['data'] as List) Map<String, dynamic>.from(x)]);
    }).catchError((e) {
      if (mounted) toast(context, errorText(e), error: true);
    });
  }

  Future<void> _export() async {
    final rows = _rows ?? const [];
    final csv = StringBuffer('﻿Date;Action;Utilisateur;Cote;Intitulé;Détails;IP\r\n');
    String c(dynamic v) => '"${'${v ?? ''}'.replaceAll('"', '""')}"';
    for (final r in rows) {
      csv.write('${[r['at'], _eventLabels[r['action']] ?? r['action'], r['user'], r['cote'], r['title'], r['details'], r['ip']].map(c).join(';')}\r\n');
    }
    await saveFile('journal-archives.csv', utf8.encode(csv.toString()), mime: 'text/csv');
    if (mounted) toast(context, tr('Journal exporté.'));
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    if (rows == null) return const Center(child: CircularProgressIndicator());
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        Expanded(child: Text(tr('Toutes les opérations sur le fonds : versements, consultations, téléchargements, contrôles d\'intégrité, prêts, éliminations.'), style: TextStyle(color: Theme.of(context).hintColor))),
        OutlinedButton.icon(onPressed: _export, icon: const Icon(Icons.download_outlined), label: Text(tr('Exporter (Excel)'))),
      ]),
      const SizedBox(height: 8),
      for (final r in rows)
        ListTile(
          dense: true,
          leading: const Icon(Icons.history, size: 18),
          title: Text(tr('${_eventLabels[r['action']] ?? r['action']}${r['cote'] != null ? ' · ${r['cote']}' : ''}${r['title'] != null ? ' — ${r['title']}' : ''}'), maxLines: 1, overflow: TextOverflow.ellipsis),
          subtitle: Text(tr('${dateFr(r['at'])} ${'${r['at']}'.length > 16 ? '${r['at']}'.substring(11, 16) : ''} · ${r['user'] ?? 'Système'}${r['details'] != null ? ' · ${r['details']}' : ''}')),
        ),
    ]);
  }
}
