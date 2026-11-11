import 'dart:async';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/office_frame.dart';
import '../widgets/pdf_viewer.dart';
import '../navigation.dart' show ShellNav;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ---------- Outils ----------

String gedSize(num? b) {
  final v = (b ?? 0).toDouble();
  if (v >= 1024 * 1024 * 1024) return '${(v / 1073741824).toStringAsFixed(1).replaceAll('.', ',')} Go';
  if (v >= 1024 * 1024) return '${(v / 1048576).toStringAsFixed(1).replaceAll('.', ',')} Mo';
  return '${(v / 1024).ceil()} Ko';
}

IconData gedIcon(String? ext) => switch ((ext ?? '').toLowerCase()) {
  'pdf' => Icons.picture_as_pdf_outlined,
  'doc' || 'docx' || 'odt' || 'rtf' || 'txt' || 'md' => Icons.description_outlined,
  'xls' || 'xlsx' || 'ods' || 'csv' => Icons.table_chart_outlined,
  'ppt' || 'pptx' || 'odp' => Icons.slideshow_outlined,
  'jpg' || 'jpeg' || 'png' || 'gif' || 'webp' || 'heic' => Icons.image_outlined,
  'zip' || 'rar' || '7z' => Icons.folder_zip_outlined,
  'mp4' || 'mov' || 'avi' => Icons.movie_outlined,
  'mp3' || 'wav' || 'm4a' || 'ogg' => Icons.audiotrack_outlined,
  _ => Icons.insert_drive_file_outlined,
};

Color gedColor(String? ext) => switch ((ext ?? '').toLowerCase()) {
  'pdf' => YColors.danger,
  'doc' || 'docx' || 'odt' => YColors.blue,
  'xls' || 'xlsx' || 'ods' || 'csv' => YColors.success,
  'ppt' || 'pptx' || 'odp' => YColors.warning,
  'jpg' || 'jpeg' || 'png' || 'gif' || 'webp' => const Color(0xFF8E44AD),
  _ => YColors.muted,
};

Color? _hex(String? h) {
  if (h == null || h.length < 7) return null;
  final v = int.tryParse(h.substring(1, 7), radix: 16);
  return v == null ? null : Color(0xFF000000 | v);
}

List<Map<String, dynamic>> gedFolders(String? parent) =>
    LocalDb.all('dc_folders').where((f) => f['parent_id'] == parent).toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));

String gedPath(String? id) {
  final parts = <String>[];
  final seen = <String>{};
  while (id != null && !seen.contains(id)) {
    seen.add(id);
    final f = LocalDb.get('dc_folders', id);
    if (f == null) break;
    parts.insert(0, '${f['name']}');
    id = f['parent_id'] as String?;
  }
  return parts.join(' / ');
}

/// Date d'échéance : null, « bientôt » (≤ 30 j) ou « échue ».
const _gedKinds = {'pdf': 'PDF', 'word': 'Texte / Word', 'excel': 'Tableur / Excel', 'slides': 'Présentation', 'image': 'Image', 'video': 'Vidéo', 'audio': 'Audio', 'archive': 'Archive', 'other': 'Autre'};

String _gedKind(String? ext) => switch ((ext ?? '').toLowerCase()) {
  'pdf' => 'pdf',
  'doc' || 'docx' || 'odt' || 'rtf' || 'txt' || 'md' => 'word',
  'xls' || 'xlsx' || 'ods' || 'csv' => 'excel',
  'ppt' || 'pptx' || 'odp' => 'slides',
  'jpg' || 'jpeg' || 'png' || 'gif' || 'webp' || 'heic' => 'image',
  'mp4' || 'mov' || 'avi' => 'video',
  'mp3' || 'wav' || 'm4a' || 'ogg' => 'audio',
  'zip' || 'rar' || '7z' => 'archive',
  _ => 'other',
};

String? gedExpiry(Map f) {
  final d = DateTime.tryParse('${f['expires_at'] ?? ''}');
  if (d == null) return null;
  final days = d.difference(DateUtils.dateOnly(DateTime.now())).inDays;
  if (days < 0) return 'past';
  if (days <= 30) return 'soon';
  return 'later';
}

Future<List<int>?> _download(BuildContext context, Map f, {int? version}) async {
  try {
    return await context.read<Session>().api.download('documents/files/${f['id']}/download', query: {if (version != null) 'v': '$version'});
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return null;
  }
}

const gedOfficeExt = {'docx', 'xlsx', 'xls', 'ods', 'csv', 'pptx'};

/// Ouvre l'éditeur Word / Excel (Yomara Office) : dans l'application (version web), sinon dans le navigateur.
Future<void> gedOffice(BuildContext context, {Map? file, String? newKind, String? folderId}) async {
  final s = context.read<Session>();
  final sync = context.read<SyncEngine>();
  try {
    final r = asMap(await (newKind != null
        ? s.api.post('documents/office/new', {'kind': newKind, 'folder_id': folderId})
        : s.api.post('documents/files/${file!['id']}/office')));
    final d = asMap(r['data']);
    if (!context.mounted) return;
    await openOffice(context, '${d['url']}');
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
  try {
    await sync.sync();
  } catch (_) {}
}

Future<void> openOffice(BuildContext context, String url) async {
  if (!officeEmbedded) {
    await safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    return;
  }
  await Navigator.of(context).push(MaterialPageRoute(
    fullscreenDialog: true,
    builder: (c) => Scaffold(body: SafeArea(child: OfficeFrame(url: url, onClose: () => Navigator.of(c).maybePop()))),
  ));
}

/// Ouvre un document : Word / Excel dans l'éditeur en ligne, PDF dans la visionneuse, image à l'écran, autre format enregistré.
Future<void> gedOpen(BuildContext context, Map f, {int? version}) async {
  if (version == null && gedOfficeExt.contains('${f['extension'] ?? ''}'.toLowerCase())) {
    return gedOffice(context, file: f);
  }
  final bytes = await _download(context, f, version: version);
  if (bytes == null || !context.mounted) return;
  final ext = '${f['extension'] ?? ''}'.toLowerCase();
  if (ext == 'pdf') {
    // Lecture et outils PDF dans l'application ; une modification devient une nouvelle version du document
    final s = context.read<Session>();
    final sync = context.read<SyncEngine>();
    final canEdit = version == null && (s.can('documents.manage') || s.can('documents.admin')) && f['locked'] != true;
    await showPdf(context, bytes, '${f['name']}', onSave: canEdit
        ? (b, name) async {
            await s.api.uploadForm('documents/files/${f['id']}/version', {'comment': 'Modifié avec les outils PDF'}, 'file', b, '${f['name']}');
            try {
              await sync.sync();
            } catch (_) {}
          }
        : null);
  } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].contains(ext)) {
    await showDialog(
      context: context,
      builder: (d) => Dialog(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(title: Text(tr('${f['name']}')), trailing: IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(d))),
          Flexible(child: InteractiveViewer(child: Image.memory(Uint8List.fromList(bytes)))),
        ]),
      ),
    );
  } else {
    final where = await saveFile('${f['name']}', bytes, mime: '${f['mime'] ?? 'application/octet-stream'}');
    if (context.mounted) toast(context, tr('Enregistré${where.isEmpty ? '' : ' : $where'}'));
  }
}

Future<void> gedSave(BuildContext context, Map f) async {
  final bytes = await _download(context, f);
  if (bytes == null || !context.mounted) return;
  final where = await saveFile('${f['name']}', bytes, mime: '${f['mime'] ?? 'application/octet-stream'}');
  if (context.mounted) toast(context, tr('Enregistré${where.isEmpty ? '' : ' : $where'}'));
}

/// Dépôt d'un ou plusieurs fichiers (connexion nécessaire).
Future<int> gedUpload(BuildContext context, {String? folderId, Map<String, dynamic> extra = const {}}) async {
  final picked = await FilePicker.pickFiles();
  if (picked.isEmpty || !context.mounted) return 0;
  final api = context.read<Session>().api;
  final sync = context.read<SyncEngine>();
  var ok = 0;
  final errors = <String>[];
  final progress = ValueNotifier('');
  showDialog(
    context: context,
    barrierDismissible: false,
    builder: (_) => YAlertDialog(content: Row(children: [const CircularProgressIndicator(), const SizedBox(width: 16), Expanded(child: ValueListenableBuilder(valueListenable: progress, builder: (_, v, _) => Text(tr(v))))])),
  );
  for (final (i, f) in picked.indexed) {
    progress.value = 'Envoi ${i + 1}/${picked.length} : ${f.name}';
    try {
      await api.uploadForm('documents/files', {'folder_id': folderId, ...extra}, 'file', await f.readAsBytes(), f.name);
      ok++;
    } catch (e) {
      errors.add('${f.name} : ${errorText(e)}');
    }
  }
  if (context.mounted) Navigator.of(context, rootNavigator: true).pop();
  try {
    await sync.sync();
  } catch (_) {}
  if (context.mounted) {
    toast(context, errors.isEmpty ? '$ok document(s) déposé(s).' : '$ok déposé(s), ${errors.length} refusé(s) : ${errors.first}', error: errors.isNotEmpty);
  }
  return ok;
}

// ---------- Écran ----------

class GedScreen extends StatefulWidget {
  const GedScreen({super.key});

  @override
  State<GedScreen> createState() => _GedScreenState();
}

class _GedScreenState extends State<GedScreen> {
  String? _folder;
  String _mode = 'browse'; // browse, search, trash, expiring
  String _q = '';
  int _rk = 0;
  Timer? _debounce;
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  List<Map<String, dynamic>>? _results;
  Map<String, dynamic>? _usage;
  bool _searching = false;

  @override
  void initState() {
    super.initState();
    _loadUsage();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  /// Vide la recherche (et le champ de la barre de filtres).
  void _clearQ() {
    _debounce?.cancel();
    _q = '';
    _rk++;
  }

  bool _match(Map<String, dynamic> f) =>
      (_f['kind'] == null || _gedKind(f['extension'] as String?) == _f['kind']) &&
      (_f['tag'] == null || ((f['tags'] as List?) ?? const []).map((e) => '$e').contains(_f['tag'])) &&
      (_f['partner'] == null || '${f['partner_id'] ?? ''}' == _f['partner']) &&
      (_f['employee'] == null || '${f['employee_id'] ?? ''}' == _f['employee']) &&
      (_f['expiry'] == null || (gedExpiry(f) ?? 'none') == _f['expiry']) &&
      (_period == null || _period!.contains('${f['updated_at'] ?? ''}'.split('T').first));

  Future<void> _loadUsage() async {
    try {
      final r = await context.read<Session>().api.get('documents/usage');
      if (mounted) setState(() => _usage = asMap(r['data']));
    } catch (_) {}
  }

  Future<void> _search() async {
    final q = _q.trim();
    if (q.length < 2) {
      setState(() => (_mode = 'browse', _results = null));
      return;
    }
    setState(() => (_mode = 'search', _searching = true));
    try {
      final r = await context.read<Session>().api.get('documents/search', query: {'q': q});
      if (mounted) setState(() => _results = [for (final x in r['data'] as List) asMap(x)]);
    } catch (_) {
      // Hors ligne : recherche sur les noms, mots-clés et descriptions
      final n = q.toLowerCase();
      if (mounted) {
        setState(() => _results = [
              for (final f in LocalDb.all('dc_files'))
                if ('${f['name']} ${f['description'] ?? ''} ${((f['tags'] as List?) ?? []).join(' ')}'.toLowerCase().contains(n))
                  {'id': f['id'], 'name': f['name'], 'folder_id': f['folder_id'], 'path': gedPath(f['folder_id'] as String?), 'extension': f['extension'], 'size': f['size'], 'offline': true},
            ]);
      }
    } finally {
      if (mounted) setState(() => _searching = false);
    }
  }

  void _open(String? folder) {
    _clearQ();
    setState(() => (_folder = folder, _mode = 'browse', _results = null));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('documents.manage') || s.can('documents.admin');
    final wide = MediaQuery.sizeOf(context).width >= 1000;
    final u = _usage;
    return ListenableBuilder(
      listenable: Listenable.merge([LocalDb.listen('dc_folders'), LocalDb.listen('dc_files')]),
      builder: (context, _) {
        if (_folder != null && LocalDb.get('dc_folders', _folder) == null) _folder = null;
        final expiring = LocalDb.all('dc_files').where((f) => ['soon', 'past'].contains(gedExpiry(f))).length;
        return Column(children: [
          PageHeader(
            title: tr('Documents'),
            subtitle: u == null
                ? 'Dossiers et fichiers de l\'entreprise'
                : [
                    '${u['files']} document(s) · société : ${gedSize(u['used'] as num)} sur ${gedSize(u['quota'] as num)}',
                  ].join(' · '),
            actions: [
              OutlinedButton.icon(onPressed: () {
                _clearQ();
                setState(() => _mode = 'expiring');
              }, icon: Icon(Icons.event_busy, color: expiring > 0 ? YColors.warning : null), label: Text(tr('Échéances${expiring > 0 ? ' ($expiring)' : ''}'))),
              if (manage)
                PopupMenuButton<String>(
                  tooltip: tr('Plus'),
                  icon: const Icon(Icons.more_horiz),
                  onSelected: (k) async {
                    if (k == 'trash') {
                      _clearQ();
                      setState(() => _mode = 'trash');
                    } else if (k == 'storage') {
                      final nav = ShellNav.of(context);
                      await showDialog(context: context, builder: (_) => _StorageDialog(onStore: () => nav?.go('store')));
                      _loadUsage();
                    }
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'trash', child: ListTile(leading: const Icon(Icons.delete_outline), title: Text(tr('Corbeille')), contentPadding: EdgeInsets.zero)),
                    if (s.can('documents.admin'))
                      PopupMenuItem(value: 'storage', child: ListTile(leading: const Icon(Icons.cloud_outlined), title: Text(tr('Espace de stockage')), contentPadding: EdgeInsets.zero)),
                  ],
                ),
              if (manage)
                PopupMenuButton<String>(
                  tooltip: tr('Créer'),
                  onSelected: (k) async {
                    if (k == 'folder') {
                      await editFolder(context, parent: _mode == 'browse' ? _folder : null);
                      return;
                    }
                    await gedOffice(context, newKind: k, folderId: _mode == 'browse' ? _folder : null);
                    _loadUsage();
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'docx', child: ListTile(leading: const Icon(Icons.description, color: Color(0xFF2B579A)), title: Text(tr('Document Word')), contentPadding: EdgeInsets.zero)),
                    PopupMenuItem(value: 'xlsx', child: ListTile(leading: const Icon(Icons.table_chart, color: Color(0xFF217346)), title: Text(tr('Classeur Excel')), contentPadding: EdgeInsets.zero)),
                    const PopupMenuDivider(),
                    PopupMenuItem(value: 'folder', child: ListTile(leading: const Icon(Icons.create_new_folder_outlined), title: Text(tr('Dossier')), contentPadding: EdgeInsets.zero)),
                  ],
                  child: IgnorePointer(child: FilledButton.tonalIcon(onPressed: () {}, icon: const Icon(Icons.add), label: Text(tr('Nouveau')))),
                ),
              if (manage)
                FilledButton.icon(
                  onPressed: () async {
                    await gedUpload(context, folderId: _mode == 'browse' ? _folder : null);
                    _loadUsage();
                  },
                  icon: const Icon(Icons.upload_file),
                  label: Text(tr('Déposer des fichiers')),
                ),
            ],
          ),
          Expanded(
            child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (wide)
                Container(
                  width: 270,
                  decoration: BoxDecoration(border: Border(right: BorderSide(color: Theme.of(context).dividerColor))),
                  child: ListView(padding: const EdgeInsets.symmetric(vertical: 8), children: [
                    ListTile(
                      dense: true,
                      selected: _mode == 'browse' && _folder == null,
                      leading: const Icon(Icons.home_outlined),
                      title: Text(tr('Tous les documents')),
                      onTap: () => _open(null),
                    ),
                    ..._tree(null, 0),
                  ]),
                ),
              Expanded(child: _main(context, manage, wide)),
            ]),
          ),
        ]);
      },
    );
  }

  List<Widget> _tree(String? parent, int depth) => [
        for (final f in gedFolders(parent)) ...[
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.only(left: 16.0 + depth * 16, right: 8),
            selected: _mode == 'browse' && _folder == f['id'],
            leading: Icon(f['system_key'] != null ? Icons.folder_special_outlined : Icons.folder, color: _hex(f['color'] as String?) ?? YColors.yellow),
            title: Text(tr('${f['name']}'), overflow: TextOverflow.ellipsis),
            trailing: f['access'] == 'private' ? const Icon(Icons.lock_outline, size: 14) : (f['access'] == 'roles' ? const Icon(Icons.group_outlined, size: 14) : null),
            onTap: () => _open('${f['id']}'),
          ),
          if (_folder != null && (_folder == f['id'] || gedPath(_folder).startsWith('${gedPath(f['id'] as String?)} / '))) ..._tree('${f['id']}', depth + 1),
        ],
      ];

  Widget _breadcrumb() {
    final chain = <Map<String, dynamic>>[];
    var id = _folder;
    final seen = <String>{};
    while (id != null && !seen.contains(id)) {
      seen.add(id);
      final f = LocalDb.get('dc_folders', id);
      if (f == null) break;
      chain.insert(0, f);
      id = f['parent_id'] as String?;
    }
    return Wrap(crossAxisAlignment: WrapCrossAlignment.center, children: [
      TextButton(onPressed: () => _open(null), child: Text(tr('Documents'))),
      for (final f in chain) ...[const Icon(Icons.chevron_right, size: 18), TextButton(onPressed: () => _open('${f['id']}'), child: Text(tr('${f['name']}')))],
    ]);
  }

  Widget _main(BuildContext context, bool manage, bool wide) {
    final allFiles = LocalDb.all('dc_files');
    int? shownCount;
    Widget search() => Padding(
          padding: const EdgeInsets.only(top: 12),
          child: FilterBar(
            key: ValueKey(_rk),
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
            onSearch: (v) {
              _q = v;
              _debounce?.cancel();
              _debounce = Timer(const Duration(milliseconds: 500), _search);
            },
            search: _q,
            searchHint: 'Noms, mots-clés, contenu des PDF et Word…',
            filters: [
              FilterSpec('kind', 'Type', {for (final e in _gedKinds.entries) if (allFiles.any((f) => _gedKind(f['extension'] as String?) == e.key)) e.key: e.value}, icon: Icons.insert_drive_file_outlined),
              FilterSpec('tag', 'Mot-clé', {for (final f in allFiles) for (final t in ((f['tags'] as List?) ?? const [])) '$t': '$t'}, icon: Icons.sell_outlined),
              FilterSpec('partner', 'Client / fournisseur', filterOptions(allFiles, 'partner_id', label: (f) => '${LocalDb.get('partners', '${f['partner_id']}')?['name'] ?? f['partner_id']}'), icon: Icons.business_outlined),
              FilterSpec('employee', 'Salarié', filterOptions(allFiles, 'employee_id', label: (f) {
                final e = LocalDb.get('rh_employees', '${f['employee_id']}');
                return e == null ? '${f['employee_id']}' : '${e['first_name'] ?? ''} ${e['last_name'] ?? ''}'.trim();
              }), icon: Icons.badge_outlined),
              const FilterSpec('expiry', 'Échéance', {'past': 'Échu', 'soon': 'Dans les 30 jours', 'later': 'Plus tard', 'none': 'Sans échéance'}, icon: Icons.event_busy_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: shownCount,
            countLabel: 'fichier',
            onReset: () => setState(() {
              final wasSearch = _mode == 'search';
              _clearQ();
              _f = {};
              _period = null;
              if (wasSearch) (_mode = 'browse', _results = null);
            }),
          ),
        );
    Widget body;
    if (_mode == 'trash') {
      body = _TrashView(onBack: () => _open(_folder), onChanged: _loadUsage);
    } else if (_mode == 'expiring') {
      final all = allFiles.where((f) => gedExpiry(f) != null && gedExpiry(f) != 'later').toList()..sort((a, b) => '${a['expires_at']}'.compareTo('${b['expires_at']}'));
      final rows = all.where(_match).toList();
      shownCount = rows.length;
      body = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(padding: const EdgeInsets.fromLTRB(8, 8, 16, 0), child: Row(children: [IconButton(onPressed: () => _open(_folder), icon: const Icon(Icons.arrow_back)), Text(tr('Documents échus ou à échéance dans les 30 jours'), style: TextStyle(fontWeight: FontWeight.w700))])),
        Expanded(
          child: rows.isEmpty && all.isNotEmpty
              ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun document ne correspond aux filtres'))
              : rows.isEmpty
              ? EmptyState(icon: Icons.event_available, title: tr('Aucune échéance proche'), message: tr('Indiquez une date d\'échéance sur vos contrats, assurances, agréments ou titres : vous serez prévenu 30 jours, 7 jours et le jour même.'))
              : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 16), children: [for (final f in rows) _FileTile(file: f, manage: manage, showPath: true)]),
        ),
      ]);
    } else if (_mode == 'search') {
      final hits = [
        for (final r in _results ?? const <Map<String, dynamic>>[])
          if (LocalDb.get('dc_files', '${r['id']}') case final f? when _match(f)) (r, f),
      ];
      if (!_searching) shownCount = hits.length;
      body = _searching
          ? const Center(child: CircularProgressIndicator())
          : (_results ?? []).isEmpty
              ? EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'), message: tr('Essayez un autre mot, ou une partie du nom.'))
              : hits.isEmpty
                  ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat ne correspond aux filtres'))
                  : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 16), children: [
                      if (_results!.first['offline'] == true) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(tr('Hors ligne : recherche limitée aux noms et mots-clés.'), style: TextStyle(color: Theme.of(context).hintColor))),
                      for (final (r, f) in hits) _FileTile(file: f, manage: manage, showPath: true, snippet: r['in_content'] == true ? r['snippet'] as String? : null),
                    ]);
    } else {
      final filtering = _f.values.any((v) => v != null) || _period != null;
      final folders = gedFolders(_folder);
      final inFolder = allFiles.where((f) => f['folder_id'] == _folder).toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
      final files = inFolder.where(_match).toList();
      shownCount = files.length;
      final cur = _folder == null ? null : LocalDb.get('dc_folders', _folder);
      body = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 0, 16, 0),
          child: Row(children: [
            Expanded(child: _breadcrumb()),
            if (cur != null && manage) _FolderMenu(folder: cur, onDeleted: () => _open(cur['parent_id'] as String?)),
          ]),
        ),
        Expanded(
          child: folders.isEmpty && inFolder.isEmpty
              ? EmptyState(icon: Icons.folder_open, title: tr('Dossier vide'), message: manage ? 'Créez des sous-dossiers ou déposez des fichiers (PDF, Word, Excel, photos…).' : 'Aucun document ici pour le moment.')
              : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 16), children: [
                  if (folders.isNotEmpty)
                    Wrap(spacing: 10, runSpacing: 10, children: [
                      for (final f in folders)
                        SizedBox(
                          width: wide ? 230 : double.infinity,
                          child: Card(
                            margin: EdgeInsets.zero,
                            child: ListTile(
                              leading: Icon(f['system_key'] != null ? Icons.folder_special : Icons.folder, color: _hex(f['color'] as String?) ?? YColors.yellow, size: 30),
                              title: Text(tr('${f['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
                              subtitle: Text(tr('${LocalDb.all('dc_files').where((x) => x['folder_id'] == f['id']).length} fichier(s)${f['access'] == 'private' ? ' · privé' : (f['access'] == 'roles' ? ' · restreint' : '')}'), style: const TextStyle(fontSize: 12)),
                              onTap: () => _open('${f['id']}'),
                            ),
                          ),
                        ),
                    ]),
                  if (folders.isNotEmpty && files.isNotEmpty) const SizedBox(height: 14),
                  if (filtering && files.isEmpty && inFolder.isNotEmpty)
                    Padding(padding: const EdgeInsets.all(16), child: Text(tr('Aucun fichier de ce dossier ne correspond aux filtres.'), style: TextStyle(color: Theme.of(context).hintColor))),
                  for (final f in files) _FileTile(file: f, manage: manage),
                ]),
        ),
      ]);
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [if (_mode != 'trash') search(), Expanded(child: body)]);
  }
}

class _FileTile extends StatelessWidget {
  const _FileTile({required this.file, required this.manage, this.showPath = false, this.snippet});

  final Map<String, dynamic> file;
  final bool manage;
  final bool showPath;
  final String? snippet;

  @override
  Widget build(BuildContext context) {
    final f = file;
    final exp = gedExpiry(f);
    final tags = ((f['tags'] as List?) ?? []).cast<Object>();
    final partner = f['partner_id'] == null ? null : LocalDb.get('partners', '${f['partner_id']}');
    final emp = f['employee_id'] == null ? null : LocalDb.get('rh_employees', '${f['employee_id']}');
    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => gedOpen(context, f),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 4, 10),
          child: Row(children: [
            Icon(gedIcon(f['extension'] as String?), color: gedColor(f['extension'] as String?), size: 30),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Flexible(child: Text(tr('${f['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600))),
                  if (f['locked'] == true) const Padding(padding: EdgeInsets.only(left: 6), child: Icon(Icons.lock, size: 14, color: YColors.muted)),
                  if ((f['current_version'] as num? ?? 1) > 1) Padding(padding: const EdgeInsets.only(left: 6), child: Text(tr('v${f['current_version']}'), style: const TextStyle(fontSize: 11, color: YColors.blue, fontWeight: FontWeight.w700))),
                ]),
                Text(tr([
                  if (showPath) gedPath(f['folder_id'] as String?).isEmpty ? 'Racine' : gedPath(f['folder_id'] as String?),
                  gedSize(f['size'] as num?),
                  dateFr('${f['updated_at'] ?? ''}'.split('T').first),
                  if (partner != null) '${partner['name']}',
                  if (emp != null) '${emp['first_name'] ?? ''} ${emp['last_name'] ?? ''}'.trim(),
                ].join(' · ')), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                if (snippet != null) Padding(padding: const EdgeInsets.only(top: 3), child: Text(tr(snippet!), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, fontStyle: FontStyle.italic))),
                if (tags.isNotEmpty || exp != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Wrap(spacing: 4, runSpacing: 4, children: [
                      if (exp != null && exp != 'later') StatusChip(exp == 'past' ? 'Échu le ${dateFr(f['expires_at'])}' : 'Échéance ${dateFr(f['expires_at'])}', color: exp == 'past' ? YColors.danger : YColors.warning),
                      if (exp == 'later') StatusChip(tr('Échéance ${dateFr(f['expires_at'])}'), color: YColors.muted),
                      for (final t in tags) StatusChip('$t', color: YColors.blue),
                    ]),
                  ),
              ]),
            ),
            _FileMenu(file: f, manage: manage),
          ]),
        ),
      ),
    );
  }
}

class _FileMenu extends StatelessWidget {
  const _FileMenu({required this.file, required this.manage});

  final Map<String, dynamic> file;
  final bool manage;

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final canEdit = manage && (file['locked'] != true || file['owner_id'] == s.user['id'] || s.can('documents.admin'));
    return PopupMenuButton<String>(
      onSelected: (v) async {
        switch (v) {
          case 'open':
            await gedOpen(context, file);
          case 'save':
            await gedSave(context, file);
          case 'edit':
            await editDocFile(context, file);
          case 'version':
            await _newVersion(context, file);
          case 'versions':
            await showDialog(context: context, builder: (_) => _VersionsDialog(file: file, canEdit: canEdit));
          case 'share':
            await showShareDialog(context, file: file);
          case 'archive':
            // Copie scellée dans les Archives (le document reste dans la GED)
            try {
              final r = await context.read<Session>().api.post('archives/records/from-ged', {'file_id': file['id']});
              if (context.mounted) toast(context, tr('Archivé et scellé (${r['data']['id'].toString().substring(0, 8)}…) : retrouvez-le dans Archives.'));
            } catch (e) {
              if (context.mounted) toast(context, errorText(e), error: true);
            }
          case 'lock':
            await context.read<SyncEngine>().save('dc_files', {'id': file['id'], 'locked': file['locked'] != true});
          case 'delete':
            if (await confirm(context, tr('Mettre à la corbeille ?'), tr('« ${file['name']} » restera récupérable 30 jours dans la corbeille.'), ok: tr('Supprimer'), danger: true) && context.mounted) {
              await context.read<SyncEngine>().remove('dc_files', '${file['id']}');
            }
        }
      },
      itemBuilder: (_) => [
        PopupMenuItem(value: 'open', child: Text(tr('Ouvrir'))),
        PopupMenuItem(value: 'save', child: Text(tr('Télécharger'))),
        if (canEdit) PopupMenuItem(value: 'edit', child: Text(tr('Renommer, classer, échéance…'))),
        if (canEdit) PopupMenuItem(value: 'version', child: Text(tr('Déposer une nouvelle version'))),
        PopupMenuItem(value: 'versions', child: Text(tr('Historique des versions'))),
        if (manage) PopupMenuItem(value: 'share', child: Text(tr('Partager par lien'))),
        if (s.hasModule('archives') && s.can('archives.manage')) PopupMenuItem(value: 'archive', child: Text(tr('Verser aux archives (copie scellée)'))),
        if (manage && (file['owner_id'] == s.user['id'] || s.can('documents.admin'))) PopupMenuItem(value: 'lock', child: Text(tr(file['locked'] == true ? 'Déverrouiller' : 'Verrouiller (lecture seule pour les autres)'))),
        if (canEdit) PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
      ],
    );
  }
}

Future<void> _newVersion(BuildContext context, Map f) async {
  final picked = await FilePicker.pickFiles();
  if (picked.isEmpty || !context.mounted) return;
  final comment = TextEditingController();
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Text(tr('Nouvelle version')),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.upload_file), title: Text(tr(picked.first.name)), subtitle: Text(tr('remplace la version ${f['current_version']} de « ${f['name']} »'))),
        TextField(controller: comment, decoration: InputDecoration(labelText: tr('Ce qui a changé (facultatif)'))),
      ]),
      actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Déposer')))],
    ),
  );
  if (ok != true || !context.mounted) return;
  try {
    await context.read<Session>().api.uploadForm('documents/files/${f['id']}/version', {'comment': comment.text.trim().isEmpty ? null : comment.text.trim()}, 'file', await picked.first.readAsBytes(), picked.first.name);
    if (context.mounted) {
      await context.read<SyncEngine>().sync();
      if (context.mounted) toast(context, tr('Nouvelle version déposée.'));
    }
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

class _VersionsDialog extends StatefulWidget {
  const _VersionsDialog({required this.file, required this.canEdit});

  final Map<String, dynamic> file;
  final bool canEdit;

  @override
  State<_VersionsDialog> createState() => _VersionsDialogState();
}

class _VersionsDialogState extends State<_VersionsDialog> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('documents/files/${widget.file['id']}/versions');
      if (mounted) setState(() => _rows = [for (final x in r['data'] as List) asMap(x)]);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return YAlertDialog(
      title: Text(tr('Versions — ${widget.file['name']}')),
      content: SizedBox(
        width: 560,
        child: _rows == null
            ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const SizedBox(height: 120, child: Center(child: CircularProgressIndicator())))
            : ListView(shrinkWrap: true, children: [
                for (final v in _rows!)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: CircleAvatar(radius: 16, backgroundColor: v['current'] == true ? YColors.success.withValues(alpha: 0.15) : null, child: Text(tr('${v['number']}'), style: const TextStyle(fontWeight: FontWeight.w800))),
                    title: Text(tr('${v['name']}${v['current'] == true ? '  (en cours)' : ''}')),
                    subtitle: Text(tr([dateFr('${v['created_at']}'.split('T').first), gedSize(v['size'] as num?), if (v['user_name'] != null) '${v['user_name']}', if (v['comment'] != null) '« ${v['comment']} »'].join(' · '))),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(tooltip: tr('Ouvrir'), icon: const Icon(Icons.open_in_new), onPressed: () => gedOpen(context, widget.file, version: (v['number'] as num).toInt())),
                      if (widget.canEdit && v['current'] != true)
                        IconButton(
                          tooltip: tr('Revenir à cette version'),
                          icon: const Icon(Icons.restore),
                          onPressed: () async {
                            try {
                              await context.read<Session>().api.post('documents/files/${widget.file['id']}/versions/${v['number']}/restore');
                              if (!context.mounted) return;
                              await context.read<SyncEngine>().sync();
                              _load();
                              if (context.mounted) toast(context, tr('Version ${v['number']} rétablie (nouvelle version créée).'));
                            } catch (e) {
                              if (context.mounted) toast(context, errorText(e), error: true);
                            }
                          },
                        ),
                    ]),
                  ),
              ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

/// Propriétés d'un document (hors ligne) : nom, dossier, description, mots-clés, échéance, liens.
Future<void> editDocFile(BuildContext context, Map f) async {
  final sync = context.read<SyncEngine>();
  final name = TextEditingController(text: '${f['name']}'.replaceAll(RegExp('\\.${RegExp.escape('${f['extension'] ?? '#'}')}\$', caseSensitive: false), ''));
  final desc = TextEditingController(text: '${f['description'] ?? ''}');
  final tags = TextEditingController(text: ((f['tags'] as List?) ?? []).join(', '));
  String? folder = f['folder_id'] as String?;
  String? partner = f['partner_id'] as String?;
  String? employee = f['employee_id'] as String?;
  DateTime? exp = DateTime.tryParse('${f['expires_at'] ?? ''}');
  final folders = LocalDb.all('dc_folders').map((x) => (id: '${x['id']}', path: gedPath('${x['id']}'))).toList()..sort((a, b) => a.path.toLowerCase().compareTo(b.path.toLowerCase()));
  final partners = LocalDb.hasEntity('partners') ? (LocalDb.all('partners')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'))) : <Map<String, dynamic>>[];
  final employees = LocalDb.hasEntity('rh_employees') ? (LocalDb.all('rh_employees')..sort((a, b) => '${a['last_name']}'.compareTo('${b['last_name']}'))) : <Map<String, dynamic>>[];
  final ok = await showFormDialog<bool>(context, title: 'Propriétés du document', builder: (d) => StatefulBuilder(
      builder: (d, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'), suffixText: trn(f['extension'] != null ? '.${f['extension']}' : null))),
            DropdownButtonFormField<String?>(
              initialValue: folders.any((x) => x.id == folder) ? folder : null,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Dossier')),
              items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Racine'))), for (final x in folders) DropdownMenuItem<String?>(value: x.id, child: Text(tr(x.path), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => set(() => folder = v),
            ),
            TextField(controller: desc, maxLines: 2, decoration: InputDecoration(labelText: tr('Description'))),
            TextField(controller: tags, decoration: InputDecoration(labelText: tr('Mots-clés (séparés par des virgules)'), hintText: tr('contrat, assurance, 2026'))),
            if (partners.isNotEmpty)
              DropdownButtonFormField<String?>(
                initialValue: partners.any((p) => p['id'] == partner) ? partner : null,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Client / fournisseur lié')),
                items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun'))), for (final p in partners) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => set(() => partner = v),
              ),
            if (employees.isNotEmpty)
              DropdownButtonFormField<String?>(
                initialValue: employees.any((p) => p['id'] == employee) ? employee : null,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Salarié lié')),
                items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun'))), for (final e in employees) DropdownMenuItem<String?>(value: '${e['id']}', child: Text(tr('${e['last_name'] ?? ''} ${e['first_name'] ?? ''}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => set(() => employee = v),
              ),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final x = await pickDate(d, initialDate: exp ?? DateTime.now().add(const Duration(days: 365)), firstDate: DateTime(2000), lastDate: DateTime(2100));
                    if (x != null) set(() => exp = x);
                  },
                  icon: const Icon(Icons.event),
                  label: Text(tr(exp == null ? 'Date d\'échéance (contrat, assurance…)' : 'Échéance le ${dateFr(isoDate(exp!))}')),
                ),
              ),
              if (exp != null) IconButton(tooltip: tr('Retirer l\'échéance'), onPressed: () => set(() => exp = null), icon: const Icon(Icons.close)),
            ]),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true) return;
  await sync.save('dc_files', {
    'id': f['id'],
    'name': name.text.trim().isEmpty ? f['name'] : name.text.trim(),
    'folder_id': folder,
    'description': desc.text.trim().isEmpty ? null : desc.text.trim(),
    'tags': [for (final t in tags.text.split(',')) if (t.trim().isNotEmpty) t.trim()],
    'partner_id': partner,
    'employee_id': employee,
    'expires_at': exp == null ? null : isoDate(exp!),
  });
}

// ---------- Dossiers ----------

class _FolderMenu extends StatelessWidget {
  const _FolderMenu({required this.folder, required this.onDeleted});

  final Map<String, dynamic> folder;
  final VoidCallback onDeleted;

  @override
  Widget build(BuildContext context) {
    return PopupMenuButton<String>(
      tooltip: tr('Dossier'),
      icon: const Icon(Icons.more_vert),
      onSelected: (v) async {
        if (v == 'edit') await editFolder(context, folder: folder);
        if (v == 'share' && context.mounted) await showShareDialog(context, folder: folder);
        if (v == 'delete' && context.mounted && await confirm(context, tr('Supprimer le dossier ?'), tr('Le dossier doit être vide.'), danger: true) && context.mounted) {
          await context.read<SyncEngine>().remove('dc_folders', '${folder['id']}');
          onDeleted();
        }
      },
      itemBuilder: (_) => [
        PopupMenuItem(value: 'edit', child: Text(tr('Renommer, couleur, droits d\'accès'))),
        PopupMenuItem(value: 'share', child: Text(tr('Partager le dossier par lien'))),
        if (folder['system_key'] == null) PopupMenuItem(value: 'delete', child: Text(tr('Supprimer le dossier'), style: TextStyle(color: YColors.danger))),
      ],
    );
  }
}

const _folderColors = ['#FFC531', '#1F6FEB', '#12A150', '#D93025', '#8E44AD', '#0E9AA7', '#E67E22', '#5B6B85'];

Future<void> editFolder(BuildContext context, {Map<String, dynamic>? folder, String? parent}) async {
  final s = context.read<Session>();
  final sync = context.read<SyncEngine>();
  final admin = s.can('documents.admin');
  final name = TextEditingController(text: '${folder?['name'] ?? ''}');
  var color = '${folder?['color'] ?? _folderColors.first}';
  var access = '${folder?['access'] ?? 'inherit'}';
  final roleIds = <int>{...((folder?['role_ids'] as List?) ?? []).map((e) => (e as num).toInt())};
  final mineOrNew = folder == null || folder['owner_id'] == s.user['id'];
  List<Map<String, dynamic>> roles = [];
  if (admin) {
    try {
      roles = [for (final r in (await s.api.get('documents/roles'))['data'] as List) asMap(r)];
    } catch (_) {}
  }
  if (!context.mounted) return;
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr(folder == null ? 'Nouveau dossier' : 'Dossier « ${folder['name']} »')),
        content: SizedBox(
          width: 480,
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (folder == null && parent != null) Text(tr('Dans : ${gedPath(parent)}'), style: TextStyle(color: Theme.of(d).hintColor)),
              TextField(controller: name, autofocus: true, decoration: InputDecoration(labelText: tr('Nom du dossier'))),
              const SizedBox(height: 10),
              Wrap(spacing: 8, children: [
                for (final c in _folderColors)
                  InkWell(
                    onTap: () => set(() => color = c),
                    child: CircleAvatar(radius: 14, backgroundColor: _hex(c), child: color == c ? const Icon(Icons.check, size: 16, color: Colors.white) : null),
                  ),
              ]),
              const SizedBox(height: 14),
              Text(tr('Qui peut voir ce dossier ?'), style: TextStyle(fontWeight: FontWeight.w700)),
              if (!admin && !mineOrNew) Text(tr('Seul un administrateur des documents peut modifier les droits.'), style: TextStyle(color: Theme.of(d).hintColor, fontSize: 12)),
              RadioGroup<String>(
                groupValue: access,
                onChanged: (v) => set(() => access = v ?? access),
                child: Column(children: [
                  RadioListTile<String>(contentPadding: EdgeInsets.zero, value: 'inherit', enabled: admin, title: Text(tr('Comme le dossier parent'))),
                  RadioListTile<String>(contentPadding: EdgeInsets.zero, value: 'all', enabled: admin, title: Text(tr('Tous les utilisateurs autorisés aux documents'))),
                  RadioListTile<String>(contentPadding: EdgeInsets.zero, value: 'roles', enabled: admin, title: Text(tr('Certains rôles seulement'))),
                  RadioListTile<String>(contentPadding: EdgeInsets.zero, value: 'private', enabled: admin || mineOrNew, title: Text(tr('Privé')), subtitle: Text(tr('Vous seul (et les administrateurs des documents)'))),
                ]),
              ),
              if (access == 'roles')
                Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final r in roles) FilterChip(label: Text(tr('${r['name']}')), selected: roleIds.contains((r['id'] as num).toInt()), onSelected: admin ? (v) => set(() => v ? roleIds.add((r['id'] as num).toInt()) : roleIds.remove((r['id'] as num).toInt())) : null),
                ]),
            ]),
          ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
      ),
    ),
  );
  if (ok != true || name.text.trim().isEmpty) return;
  try {
    await sync.save('dc_folders', {
      'id': folder?['id'] ?? newId(),
      if (folder == null) 'parent_id': parent,
      'name': name.text.trim(),
      'color': color,
      'access': access,
      'role_ids': access == 'roles' ? roleIds.toList() : null,
    });
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ---------- Partage ----------

Future<void> showShareDialog(BuildContext context, {Map? file, Map? folder}) => showDialog(context: context, builder: (_) => _ShareDialog(file: file, folder: folder));

class _ShareDialog extends StatefulWidget {
  const _ShareDialog({this.file, this.folder});

  final Map? file, folder;

  @override
  State<_ShareDialog> createState() => _ShareDialogState();
}

class _ShareDialogState extends State<_ShareDialog> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  final _days = TextEditingController(text: '7');
  final _password = TextEditingController();
  final _max = TextEditingController();

  String get _q => widget.file != null ? 'file_id=${widget.file!['id']}' : 'folder_id=${widget.folder!['id']}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('documents/shares?$_q');
      if (mounted) setState(() => _rows = [for (final x in r['data'] as List) asMap(x)]);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _create() async {
    try {
      final r = await context.read<Session>().api.post('documents/shares', {
        if (widget.file != null) 'file_id': widget.file!['id'] else 'folder_id': widget.folder!['id'],
        if (int.tryParse(_days.text) != null) 'expires_days': int.parse(_days.text),
        if (_password.text.trim().isNotEmpty) 'password': _password.text.trim(),
        if (int.tryParse(_max.text) != null) 'max_downloads': int.parse(_max.text),
      });
      final url = '${r['data']['url']}';
      await Clipboard.setData(ClipboardData(text: url));
      if (mounted) toast(context, tr('Lien créé et copié.'));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final title = widget.file != null ? '${widget.file!['name']}' : '${widget.folder!['name']}';
    return YAlertDialog(
      title: Text(tr('Partager « $title »')),
      content: SizedBox(
        width: 560,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('Toute personne ayant le lien pourra ${widget.file != null ? 'télécharger ce document' : 'télécharger les fichiers de ce dossier'}, sans compte.'), style: TextStyle(color: Theme.of(context).hintColor)),
            Row(children: [
              Expanded(child: TextField(controller: _days, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Valable (jours)'), helperText: tr('vide : sans limite')))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: _max, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Téléch. max.')))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: _password, decoration: InputDecoration(labelText: tr('Mot de passe')))),
            ]),
            const SizedBox(height: 8),
            Align(alignment: Alignment.centerRight, child: FilledButton.icon(onPressed: _create, icon: const Icon(Icons.add_link), label: Text(tr('Créer le lien')))),
            const Divider(),
            Text(tr('Liens actifs'), style: TextStyle(fontWeight: FontWeight.w700)),
            if (_rows == null) _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Padding(padding: EdgeInsets.all(12), child: Center(child: CircularProgressIndicator())),
            if (_rows != null && _rows!.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text(tr('Aucun lien.'), style: TextStyle(color: Theme.of(context).hintColor))),
            for (final r in _rows ?? <Map<String, dynamic>>[])
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(r['active'] == true ? Icons.link : Icons.link_off, color: r['active'] == true ? YColors.success : YColors.muted),
                title: Text(tr('${r['url']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13)),
                subtitle: Text(tr([
                  r['active'] == true ? 'actif' : 'expiré',
                  if (r['expires_at'] != null) 'jusqu\'au ${dateFr('${r['expires_at']}'.split('T').first)}',
                  if (r['has_password'] == true) 'mot de passe',
                  '${r['downloads']}${r['max_downloads'] != null ? '/${r['max_downloads']}' : ''} téléchargement(s)',
                ].join(' · '))),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  IconButton(tooltip: tr('Copier'), icon: const Icon(Icons.copy), onPressed: () {
                    Clipboard.setData(ClipboardData(text: '${r['url']}'));
                    toast(context, tr('Lien copié.'));
                  }),
                  IconButton(tooltip: tr('WhatsApp'), icon: const Icon(Icons.chat_outlined, color: Color(0xFF25D366)), onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent('$title : ${r['url']}')}'), mode: LaunchMode.externalApplication)),
                  IconButton(
                    tooltip: tr('Désactiver'),
                    icon: const Icon(Icons.block, color: YColors.danger),
                    onPressed: () async {
                      try {
                        await context.read<Session>().api.delete('documents/shares/${r['id']}');
                        _load();
                      } catch (e) {
                        if (context.mounted) toast(context, errorText(e), error: true);
                      }
                    },
                  ),
                ]),
              ),
          ]),
        ),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

// ---------- Corbeille ----------

class _TrashView extends StatefulWidget {
  const _TrashView({required this.onBack, required this.onChanged});

  final VoidCallback onBack, onChanged;

  @override
  State<_TrashView> createState() => _TrashViewState();
}

class _TrashViewState extends State<_TrashView> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _rk = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('documents/trash');
      if (mounted) setState(() => (_rows = [for (final x in r['data'] as List) asMap(x)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _do(Future<dynamic> Function() f, String ok) async {
    try {
      await f();
      if (!mounted) return;
      await context.read<SyncEngine>().sync();
      widget.onChanged();
      _load();
      if (mounted) toast(context, ok);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final admin = s.can('documents.admin');
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(8, 8, 16, 0),
        child: Row(children: [
          IconButton(onPressed: widget.onBack, icon: const Icon(Icons.arrow_back)),
          Expanded(child: Text(tr(admin ? 'Corbeille — suppression définitive après 30 jours' : 'Vos documents supprimés — récupérables 30 jours'), style: const TextStyle(fontWeight: FontWeight.w700))),
          if (admin && (_rows ?? []).isNotEmpty)
            TextButton.icon(
              onPressed: () async {
                if (await confirm(context, tr('Vider la corbeille ?'), tr('Les ${_rows!.length} fichier(s) et toutes leurs versions seront définitivement effacés.'), ok: tr('Vider'), danger: true)) {
                  await _do(() => s.api.delete('documents/trash'), 'Corbeille vidée.');
                }
              },
              icon: const Icon(Icons.delete_forever, color: YColors.danger),
              label: Text(tr('Vider la corbeille'), style: TextStyle(color: YColors.danger)),
            ),
        ]),
      ),
      Expanded(
        child: _rows == null
            ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
            : _rows!.isEmpty
                ? EmptyState(icon: Icons.delete_outline, title: tr('Corbeille vide'), message: tr('Les documents supprimés restent ici 30 jours.'))
                : Builder(builder: (context) {
                    final q = _q.toLowerCase();
                    final rows = _rows!
                        .where((f) => q.isEmpty || '${f['name'] ?? ''} ${f['path_label'] ?? ''}'.toLowerCase().contains(q))
                        .where((f) => _f['kind'] == null || _gedKind(f['extension'] as String?) == _f['kind'])
                        .where((f) => _f['by'] == null || '${f['deleted_by_name'] ?? ''}' == _f['by'])
                        .where((f) => _period == null || _period!.contains('${f['deleted_at'] ?? ''}'.split('T').first))
                        .toList();
                    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: FilterBar(
                          key: ValueKey(_rk),
                          padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
                          onSearch: (v) => setState(() => _q = v),
                          search: _q,
                          searchHint: 'Nom, dossier…',
                          filters: [
                            FilterSpec('kind', 'Type', {for (final e in _gedKinds.entries) if (_rows!.any((f) => _gedKind(f['extension'] as String?) == e.key)) e.key: e.value}, icon: Icons.insert_drive_file_outlined),
                            FilterSpec('by', 'Supprimé par', filterOptions(_rows!, 'deleted_by_name'), icon: Icons.person_outline),
                          ],
                          values: _f,
                          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                          showPeriod: true,
                          period: _period,
                          onPeriod: (p) => setState(() => _period = p),
                          count: rows.length,
                          countLabel: 'fichier',
                          onReset: () => setState(() => (_q = '', _f = {}, _period = null, _rk++)),
                        ),
                      ),
                      Expanded(
                        child: rows.isEmpty
                            ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun fichier ne correspond aux filtres'))
                            : ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 16), children: [
                    for (final f in rows)
                      Card(
                        child: ListTile(
                          leading: Icon(gedIcon(f['extension'] as String?), color: gedColor(f['extension'] as String?)),
                          title: Text(tr('${f['name']}')),
                          subtitle: Text(tr([if ('${f['path_label'] ?? ''}'.isNotEmpty) '${f['path_label']}', gedSize(f['size'] as num?), 'supprimé le ${dateFr('${f['deleted_at']}'.split('T').first)}', if (f['deleted_by_name'] != null) 'par ${f['deleted_by_name']}', 'effacé le ${dateFr(f['purge_at'])}'].join(' · '))),
                          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                            TextButton.icon(onPressed: () => _do(() => s.api.post('documents/files/${f['id']}/restore'), 'Document restauré.'), icon: const Icon(Icons.restore), label: Text(tr('Restaurer'))),
                            if (admin)
                              IconButton(
                                tooltip: tr('Supprimer définitivement'),
                                icon: const Icon(Icons.delete_forever, color: YColors.danger),
                                onPressed: () async {
                                  if (await confirm(context, tr('Supprimer définitivement ?'), tr('« ${f['name']} » et toutes ses versions seront effacés.'), danger: true)) await _do(() => s.api.delete('documents/files/${f['id']}/purge'), 'Document effacé.');
                                },
                              ),
                          ]),
                        ),
                      ),
                              ]),
                      ),
                    ]);
                  }),
      ),
    ]);
  }
}

// ---------- Documents liés (fiche client, fiche salarié) ----------

/// Documents rattachés à un client / fournisseur (`partner_id`) ou à un salarié (`employee_id`).
class LinkedDocuments extends StatelessWidget {
  const LinkedDocuments({super.key, required this.field, required this.id});

  final String field;
  final String id;

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    if (!s.hasModule('documents') || !(s.can('documents.view') || s.can('documents.manage') || s.can('documents.admin')) || !LocalDb.hasEntity('dc_files')) return const SizedBox.shrink();
    final manage = s.can('documents.manage') || s.can('documents.admin');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('dc_files'),
      builder: (context, _, _) {
        final rows = LocalDb.all('dc_files').where((f) => f[field] == id).toList()..sort((a, b) => '${b['updated_at']}'.compareTo('${a['updated_at']}'));
        return Padding(
          padding: const EdgeInsets.only(top: 8, bottom: 4),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr('Documents (${rows.length})'), style: const TextStyle(fontWeight: FontWeight.w700))),
              if (manage) TextButton.icon(onPressed: () => gedUpload(context, extra: {field: id}), icon: const Icon(Icons.attach_file, size: 18), label: Text(tr('Joindre'))),
            ]),
            if (rows.isEmpty) Text(tr('Aucun document lié.'), style: TextStyle(fontSize: 13, color: Theme.of(context).hintColor)),
            for (final f in rows.take(8))
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: Icon(gedIcon(f['extension'] as String?), color: gedColor(f['extension'] as String?)),
                title: Text(tr('${f['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text(tr([gedPath(f['folder_id'] as String?).isEmpty ? 'Racine' : gedPath(f['folder_id'] as String?), gedSize(f['size'] as num?), if (f['expires_at'] != null) 'échéance ${dateFr(f['expires_at'])}'].join(' · '))),
                onTap: () => gedOpen(context, f),
              ),
          ]),
        );
      },
    );
  }
}



/// Espace de stockage partagé par toute la société : utilisation, part de chacun, packs d'espace en plus.
class _StorageDialog extends StatefulWidget {
  const _StorageDialog({required this.onStore});

  final VoidCallback onStore;

  @override
  State<_StorageDialog> createState() => _StorageDialogState();
}

class _StorageDialogState extends State<_StorageDialog> {
  Map<String, dynamic>? _d;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('documents/storage');
      if (mounted) setState(() => (_d = asMap(asMap(r)['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    final cs = Theme.of(context).colorScheme;
    final hint = Theme.of(context).hintColor;
    final u = asMap(d?['usage']);
    final people = [for (final p in (d?['people'] as List? ?? const [])) asMap(p)].where((p) => (p['used'] as num) > 0).toList();
    final packs = [for (final p in (d?['packs'] as List? ?? const [])) asMap(p)];
    final used = (u['used'] as num?) ?? 0, quota = (u['quota'] as num?) ?? 1;
    final r = (used / quota).clamp(0, 1).toDouble();
    final tone = r >= .9 ? YColors.danger : (r >= .75 ? YColors.warning : YColors.blue);
    return YAlertDialog(
      title: Text(tr('Espace de stockage')),
      content: SizedBox(
        width: 560,
        height: 540,
        child: d == null
            ? Center(child: _error == null ? const CircularProgressIndicator() : Text(_error!))
            : ListView(children: [
                // Jauge de l'espace partagé
                Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(color: tone.withValues(alpha: .07), borderRadius: BorderRadius.circular(16)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(gedSize(used), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800, height: 1)),
                      const SizedBox(width: 8),
                      Padding(padding: const EdgeInsets.only(bottom: 3), child: Text(tr('utilisés sur ${gedSize(quota)}'), style: TextStyle(color: hint))),
                      const Spacer(),
                      Text('${(r * 100).round()} %', style: TextStyle(fontWeight: FontWeight.w800, color: tone)),
                    ]),
                    const SizedBox(height: 12),
                    LinearProgressIndicator(value: r, minHeight: 10, borderRadius: BorderRadius.circular(6), color: tone, backgroundColor: cs.surface),
                    const SizedBox(height: 10),
                    Text(tr('Espace partagé par tout le personnel : chacun dépose ses fichiers, la limite vaut pour toute la société.'), style: TextStyle(color: hint, fontSize: 12.5)),
                  ]),
                ),
                if (d['hosted'] == true) ...[
                  const SizedBox(height: 20),
                  Text(tr('Ajouter de l\'espace'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 10),
                  LayoutBuilder(builder: (context, c) {
                    final n = c.maxWidth < 420 ? 2 : 4;
                    final w = (c.maxWidth - (n - 1) * 10) / n;
                    return Wrap(spacing: 10, runSpacing: 10, children: [
                      for (final p in packs)
                        SizedBox(
                          width: w,
                          child: Material(
                            color: p['active'] == true ? YColors.success.withValues(alpha: .08) : Colors.transparent,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: p['active'] == true ? YColors.success : Theme.of(context).dividerColor)),
                            child: InkWell(
                              borderRadius: BorderRadius.circular(14),
                              onTap: p['active'] == true
                                  ? null
                                  : () {
                                      Navigator.pop(context);
                                      widget.onStore();
                                    },
                              child: Padding(
                                padding: const EdgeInsets.all(12),
                                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                  Icon(p['active'] == true ? Icons.check_circle : Icons.cloud_upload_outlined, color: p['active'] == true ? YColors.success : YColors.blue),
                                  const SizedBox(height: 8),
                                  Text('+${gedSize(p['size'] as num)}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                                  Text(p['active'] == true ? tr('Actif') : tr('${money(toDouble(p['price_monthly']), '${p['currency']}')} / mois'), style: TextStyle(color: hint, fontSize: 12)),
                                ]),
                              ),
                            ),
                          ),
                        ),
                    ]);
                  }),
                ],
                if (people.isNotEmpty) ...[
                  const SizedBox(height: 22),
                  Text(tr('Qui utilise l\'espace'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 6),
                  for (final p in people)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Row(children: [
                        CircleAvatar(radius: 15, backgroundColor: YColors.blue.withValues(alpha: .12), child: Text('${p['name']}'.characters.first.toUpperCase(), style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w700, fontSize: 13))),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5)),
                            const SizedBox(height: 4),
                            LinearProgressIndicator(value: ((p['used'] as num) / quota).clamp(0, 1).toDouble(), minHeight: 5, borderRadius: BorderRadius.circular(3)),
                          ]),
                        ),
                        const SizedBox(width: 12),
                        Text(gedSize(p['used'] as num), style: TextStyle(color: hint, fontSize: 12.5)),
                      ]),
                    ),
                ],
              ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}
