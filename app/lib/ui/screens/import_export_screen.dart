import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _entityIcons = {'partners': Icons.people_outline, 'products': Icons.sell_outlined, 'stock': Icons.inventory_2_outlined, 'invoices': Icons.receipt_long_outlined};
const _entityHelp = {
  'partners': 'Clients et fournisseurs : nom, type, téléphone, e-mail, ville, NCC… Un réimport met à jour les fiches existantes sans créer de doublon.',
  'products': 'Articles avec prix HT ou TTC, prix d\'achat, TVA, famille (créée au besoin) et stock actuel.',
  'stock': 'Inventaire : quantités comptées par article (et par dépôt). L\'écart avec le stock connu devient un ajustement.',
  'invoices': 'Factures clients encore impayées dans l\'ancien logiciel : seul le reste dû est repris, en à-nouveaux comptables, sans chiffre d\'affaires.',
};

class ImportExportScreen extends StatefulWidget {
  const ImportExportScreen({super.key});

  @override
  State<ImportExportScreen> createState() => _ImportExportScreenState();
}

class _ImportExportScreenState extends State<ImportExportScreen> {
  Map<String, dynamic>? _ent;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('import_export/entities');
      if (mounted) setState(() => (_ent = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final header = PageHeader(title: tr('Import / export'), subtitle: tr('Reprise des données depuis Excel ou CSV, exports de vos listes'));
    if (_ent == null) {
      return Column(children: [header, Expanded(child: _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))]);
    }
    final imports = [for (final e in (_ent!['imports'] as List)) asMap(e)];
    final exports = [for (final e in (_ent!['exports'] as List)) asMap(e)];
    final tabs = [if (imports.isNotEmpty) ('Importer', _ImportTab(defs: imports)), if (exports.isNotEmpty) ('Exporter', _ExportTab(items: exports)), if (imports.isNotEmpty) ('Historique', const _JobsTab())];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        header,
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

// ======================================================================
// Import : type › fichier › correspondance des colonnes › vérification › import
// ======================================================================

class _ImportTab extends StatefulWidget {
  const _ImportTab({required this.defs});

  final List<Map<String, dynamic>> defs;

  @override
  State<_ImportTab> createState() => _ImportTabState();
}

class _ImportTabState extends State<_ImportTab> {
  Map<String, dynamic>? _def;
  Map<String, dynamic>? _analysis; // job_id, headers, rows, sample, mapping
  String? _filename;
  final Map<String, int?> _mapping = {};
  String _mode = 'upsert';
  String? _key;
  bool _atomic = false;
  bool _busy = false;
  Map<String, dynamic>? _report;

  void _choose(Map<String, dynamic> d) => setState(() {
        _def = d;
        _analysis = null;
        _report = null;
        _key = (asMap(d['keys'])).keys.first;
        _mode = d['key'] == 'invoices' ? 'create' : 'upsert';
      });

  Future<void> _template() async {
    final api = context.read<Session>().api;
    try {
      final bytes = await api.download('import_export/template/${_def!['key']}');
      final where = await saveFile('modele_import_${_def!['key']}.xlsx', bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      if (mounted) toast(context, tr('Modèle enregistré : $where'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _pick() async {
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['xlsx', 'csv', 'txt']);
    if (files.isEmpty || !mounted) return;
    final f = files.first;
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.uploadForm('import_export/analyze', {'entity': _def!['key']}, 'file', await f.readAsBytes(), f.name);
      final a = asMap(r['data']);
      final m = asMap(a['mapping']);
      setState(() {
        _analysis = a;
        _filename = f.name;
        _report = null;
        _mapping
          ..clear()
          ..addAll({for (final fd in (_def!['fields'] as List)) '${fd['name']}': (m['${fd['name']}'] as num?)?.toInt()});
      });
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _run({required bool dry}) async {
    final missing = [for (final f in (_def!['fields'] as List)) if (f['required'] == true && _mapping['${f['name']}'] == null) f['label']];
    if (missing.isNotEmpty) {
      toast(context, tr('Associez une colonne à : ${missing.join(', ')}.'), error: true);
      return;
    }
    if (!dry && !await confirm(context, tr('Importer ${_analysis!['rows']} ligne(s) ?'), _atomic ? 'Si une seule ligne est refusée, rien n\'est enregistré.' : 'Les lignes valides sont enregistrées ; les lignes refusées sont listées pour correction.', ok: tr('Importer'))) return;
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.postLong('import_export/jobs/${_analysis!['job_id']}/run', {
        'mapping': {for (final e in _mapping.entries) if (e.value != null) e.key: e.value},
        'mode': _mode,
        'key': _key,
        'dry_run': dry,
        'atomic': _atomic,
      });
      setState(() => _report = asMap(r['data']));
      if (!dry && mounted) await context.read<SyncEngine>().sync();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _errorsFile() async {
    final api = context.read<Session>().api;
    try {
      final bytes = await api.download('import_export/jobs/${_analysis!['job_id']}/errors');
      final where = await saveFile('erreurs_import.xlsx', bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      if (mounted) toast(context, tr('Fichier des erreurs enregistré : $where'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(tr('1. Que voulez-vous importer ?'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
      const SizedBox(height: 8),
      Wrap(spacing: 10, runSpacing: 10, children: [
        for (final d in widget.defs)
          SizedBox(
            width: 260,
            child: Card(
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: _def?['key'] == d['key'] ? YColors.blue : Colors.transparent, width: 2)),
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: _busy ? null : () => _choose(d),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Icon(_entityIcons[d['key']] ?? Icons.table_chart_outlined, color: YColors.blue),
                    const SizedBox(height: 6),
                    Text(tr('${d['label']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 4),
                    Text(tr(_entityHelp[d['key']] ?? ''), style: TextStyle(fontSize: 12, color: hint)),
                  ]),
                ),
              ),
            ),
          ),
      ]),
      if (_def != null) ...[
        const SizedBox(height: 20),
        Text(tr('2. Le fichier'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Text(tr('Excel (.xlsx) ou CSV. La première ligne contient les titres des colonnes. Peu importe leur nom ou leur ordre : vous les associez ensuite.'), style: TextStyle(color: hint)),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: [
          FilledButton.icon(onPressed: _busy ? null : _pick, icon: const Icon(Icons.upload_file), label: Text(tr(_filename == null ? 'Choisir le fichier' : 'Changer de fichier'))),
          OutlinedButton.icon(onPressed: _template, icon: const Icon(Icons.download), label: Text(tr('Télécharger le modèle Excel'))),
          if (_busy) const Padding(padding: EdgeInsets.all(8), child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))),
        ]),
      ],
      if (_analysis != null) ...[
        const SizedBox(height: 20),
        Text(tr('3. Correspondance des colonnes — $_filename (${_analysis!['rows']} ligne(s))'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        _MappingTable(def: _def!, analysis: _analysis!, mapping: _mapping, onChanged: () => setState(() => _report = null)),
        const SizedBox(height: 16),
        Text(tr('4. Options'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        if (_def!['key'] != 'stock')
          SegmentedButton<String>(
            segments: [
              ButtonSegment(value: 'upsert', label: Text(tr('Ajouter et mettre à jour'))),
              ButtonSegment(value: 'create', label: Text(tr('Ajouter seulement'))),
              ButtonSegment(value: 'update', label: Text(tr('Mettre à jour seulement'))),
            ],
            selected: {_mode},
            onSelectionChanged: (v) => setState(() => _mode = v.first),
          ),
        const SizedBox(height: 10),
        Row(children: [
          SizedBox(
            width: 320,
            child: DropdownButtonFormField<String>(
              initialValue: _key,
              decoration: InputDecoration(labelText: tr('Reconnaître une fiche existante par')),
              items: [for (final e in asMap(_def!['keys']).entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
              onChanged: (v) => setState(() => _key = v),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Tout ou rien')),
              subtitle: Text(tr('Annuler tout l\'import si une ligne est refusée')),
              value: _atomic,
              onChanged: (v) => setState(() => _atomic = v),
            ),
          ),
        ]),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          OutlinedButton.icon(onPressed: _busy ? null : () => _run(dry: true), icon: const Icon(Icons.fact_check_outlined), label: Text(tr('Vérifier sans rien enregistrer'))),
          FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: YColors.success), onPressed: _busy ? null : () => _run(dry: false), icon: const Icon(Icons.download_done), label: Text(tr('Importer'))),
        ]),
      ],
      if (_report != null) ...[
        const SizedBox(height: 16),
        _Report(report: _report!, onErrors: _errorsFile),
      ],
    ]);
  }
}

class _MappingTable extends StatelessWidget {
  const _MappingTable({required this.def, required this.analysis, required this.mapping, required this.onChanged});

  final Map<String, dynamic> def;
  final Map<String, dynamic> analysis;
  final Map<String, int?> mapping;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final headers = [for (final h in (analysis['headers'] as List)) '$h'];
    final sample = [for (final r in (analysis['sample'] as List)) List.from(r as List)];
    String preview(int? col) {
      if (col == null) return '';
      return sample.map((r) => col < r.length ? '${r[col] ?? ''}' : '').where((v) => v.isNotEmpty).take(3).join(' · ');
    }

    return Card(
      child: Column(children: [
        for (final f in (def['fields'] as List))
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 2),
            child: LayoutBuilder(builder: (context, box) {
              final name = '${f['name']}';
              final dd = DropdownButtonFormField<int?>(
                initialValue: mapping[name],
                isExpanded: true,
                decoration: InputDecoration(isDense: true, labelText: tr('Colonne du fichier'), fillColor: mapping[name] == null && f['required'] == true ? YColors.danger.withValues(alpha: 0.06) : null),
                items: [
                  DropdownMenuItem<int?>(value: null, child: Text(tr('— Ne pas importer —'))),
                  for (var i = 0; i < headers.length; i++) DropdownMenuItem<int?>(value: i, child: Text(tr(headers[i].isEmpty ? 'Colonne ${i + 1}' : headers[i]), overflow: TextOverflow.ellipsis)),
                ],
                onChanged: (v) {
                  mapping[name] = v;
                  onChanged();
                },
              );
              final label = Text.rich(TextSpan(children: [
                TextSpan(text: '${f['label']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                if (f['required'] == true) const TextSpan(text: ' *', style: TextStyle(color: YColors.danger, fontWeight: FontWeight.w800)),
              ]));
              final ex = Text(tr(preview(mapping[name])), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor));
              return box.maxWidth > 760
                  ? Row(children: [SizedBox(width: 240, child: label), SizedBox(width: 280, child: dd), const SizedBox(width: 16), Expanded(child: ex)])
                  : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [label, const SizedBox(height: 4), dd, ex]);
            }),
          ),
        const SizedBox(height: 6),
      ]),
    );
  }
}

class _Report extends StatelessWidget {
  const _Report({required this.report, required this.onErrors});

  final Map<String, dynamic> report;
  final VoidCallback onErrors;

  @override
  Widget build(BuildContext context) {
    final dry = report['dry_run'] == true;
    final rolled = report['rolled_back'] == true;
    final errors = [for (final e in (report['errors'] as List? ?? [])) asMap(e)];
    final failed = (report['failed'] as num?)?.toInt() ?? 0;
    return SectionCard(
      title: dry ? 'Vérification (rien n\'a été enregistré)' : (rolled ? 'Import annulé : des lignes ont été refusées' : 'Import terminé'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Wrap(spacing: 8, runSpacing: 8, children: [
          StatusChip(tr('${report['rows']} ligne(s) lue(s)'), color: YColors.muted),
          StatusChip('${report['created']} ${dry ? 'à créer' : 'créée(s)'}', color: YColors.success, icon: Icons.add),
          StatusChip('${report['updated']} ${dry ? 'à mettre à jour' : 'mise(s) à jour'}', color: YColors.blue, icon: Icons.edit),
          if ((report['skipped'] as num? ?? 0) > 0) StatusChip(tr('${report['skipped']} ignorée(s)'), color: YColors.muted),
          if (failed > 0) StatusChip(tr('$failed refusée(s)'), color: YColors.danger, icon: Icons.error_outline),
        ]),
        if (errors.isNotEmpty) ...[
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: Text(tr('Lignes refusées'), style: TextStyle(fontWeight: FontWeight.w700))),
            TextButton.icon(onPressed: onErrors, icon: const Icon(Icons.download), label: Text(tr('Fichier des lignes à corriger'))),
          ]),
          for (final e in errors.take(100))
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SizedBox(width: 90, child: Text(tr('Ligne ${e['row']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                Expanded(child: Text(tr('${e['message']}'), style: const TextStyle(color: YColors.danger))),
              ]),
            ),
          if (errors.length > 100) Text(tr('… et ${errors.length - 100} autre(s) dans le fichier à corriger.'), style: TextStyle(color: Theme.of(context).hintColor)),
        ],
        if (dry && failed == 0) Padding(padding: EdgeInsets.only(top: 10), child: Text(tr('Tout est prêt : lancez l\'import.'), style: TextStyle(color: YColors.success, fontWeight: FontWeight.w700))),
      ]),
    );
  }
}

// ======================================================================
// Export
// ======================================================================

class _ExportTab extends StatefulWidget {
  const _ExportTab({required this.items});

  final List<Map<String, dynamic>> items;

  @override
  State<_ExportTab> createState() => _ExportTabState();
}

class _ExportTabState extends State<_ExportTab> {
  DateTime _from = DateTime(DateTime.now().year, 1, 1);
  DateTime _to = DateTime.now();
  String? _busy;

  Future<void> _export(Map<String, dynamic> e, String fmt) async {
    final api = context.read<Session>().api;
    setState(() => _busy = '${e['key']}$fmt');
    try {
      final bytes = await api.download('import_export/export/${e['key']}', query: {'format': fmt, if (e['period'] == true) 'from': isoDate(_from), if (e['period'] == true) 'to': isoDate(_to)});
      final name = '${e['key']}_${isoDate(DateTime.now())}.$fmt';
      final where = await saveFile(name, bytes, mime: fmt == 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      if (mounted) toast(context, tr('Export enregistré : $where'));
    } catch (err) {
      if (mounted) toast(context, errorText(err), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    Future<void> pick(bool from) async {
      final d = await showDatePicker(context: context, initialDate: from ? _from : _to, firstDate: DateTime(2015), lastDate: DateTime(2100));
      if (d != null) setState(() => from ? _from = d : _to = d);
    }

    return ListView(padding: const EdgeInsets.all(16), children: [
      Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
        Text(tr('Période (documents et mouvements) :')),
        OutlinedButton(onPressed: () => pick(true), child: Text(tr('du ${dateFr(isoDate(_from))}'))),
        OutlinedButton(onPressed: () => pick(false), child: Text(tr('au ${dateFr(isoDate(_to))}'))),
      ]),
      const SizedBox(height: 12),
      for (final e in widget.items)
        Card(
          child: ListTile(
            leading: const Icon(Icons.table_view_outlined, color: YColors.blue),
            title: Text(tr('${e['label']}')),
            subtitle: Text(tr(e['period'] == true ? 'Sur la période choisie' : 'Liste complète${['partners', 'products'].contains(e['key']) ? ' — réimportable telle quelle' : ''}')),
            trailing: Wrap(spacing: 6, children: [
              FilledButton.tonal(onPressed: _busy != null ? null : () => _export(e, 'xlsx'), child: _busy == '${e['key']}xlsx' ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : Text(tr('Excel'))),
              OutlinedButton(onPressed: _busy != null ? null : () => _export(e, 'csv'), child: Text(tr('CSV'))),
            ]),
          ),
        ),
    ]);
  }
}

class _JobsTab extends StatefulWidget {
  const _JobsTab();

  @override
  State<_JobsTab> createState() => _JobsTabState();
}

class _JobsTabState extends State<_JobsTab> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('import_export/jobs');
      if (mounted) setState(() => (_rows = [for (final e in (r['data'] as List)) asMap(e)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_rows == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    const labels = {'partners': 'Clients & fournisseurs', 'products': 'Articles', 'stock': 'Inventaire', 'invoices': 'Factures impayées'};
    const status = {'analyzed': 'Fichier déposé', 'checked': 'Vérifié', 'done': 'Importé'};
    return RefreshIndicator(
      onRefresh: _load,
      child: _rows!.isEmpty
          ? ListView(children: [EmptyState(icon: Icons.history, title: tr('Aucun import'), message: tr('Les imports réalisés apparaissent ici avec leur résultat.'))])
          : ListView(padding: const EdgeInsets.all(16), children: [
              for (final j in _rows!)
                Card(
                  child: ListTile(
                    leading: Icon(j['status'] == 'done' ? Icons.check_circle : Icons.pending_outlined, color: j['status'] == 'done' ? YColors.success : YColors.muted),
                    title: Text(tr('${labels[j['entity']] ?? j['entity']} — ${j['filename']}')),
                    subtitle: Text(tr('${dateTimeFr(j['created_at'])} · ${j['user_name'] ?? ''} · ${status[j['status']] ?? j['status']}')),
                    trailing: Text(tr('${j['created']} créé(s) · ${j['updated']} maj · ${j['failed']} refusé(s)'), style: const TextStyle(fontSize: 12)),
                  ),
                ),
            ]),
    );
  }
}
