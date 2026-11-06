import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../navigation.dart' show UiPrefs;
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/report_charts.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

// ============================================================================
// Outils communs
// ============================================================================

const _periodsFallback = {
  'today': 'Aujourd\'hui', 'yesterday': 'Hier', 'this_week': 'Cette semaine', 'last_week': 'La semaine dernière',
  'this_month': 'Ce mois-ci', 'last_month': 'Le mois dernier', 'this_quarter': 'Ce trimestre', 'last_quarter': 'Le trimestre dernier',
  'this_year': 'Cette année', 'last_year': 'L\'année dernière', 'ytd': 'Depuis le 1er janvier', 'last_7_days': '7 derniers jours',
  'last_30_days': '30 derniers jours', 'last_90_days': '90 derniers jours', 'last_12_months': '12 derniers mois', 'all': 'Toutes dates', 'custom': 'Dates choisies',
};

const _charts = {'table': 'Tableau', 'bar': 'Histogramme', 'hbar': 'Barres', 'line': 'Courbe', 'pie': 'Camembert', 'kpi': 'Indicateur', 'pivot': 'Tableau croisé'};

const _chartIcons = {
  'table': Icons.table_rows_outlined, 'bar': Icons.bar_chart, 'hbar': Icons.align_horizontal_left, 'line': Icons.show_chart,
  'pie': Icons.donut_large, 'kpi': Icons.speed, 'pivot': Icons.grid_on,
};

const _weekdays = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

/// Catalogue du constructeur (sources, champs) — gardé en cache pour l'affichage hors ligne.
class RapportsMeta {
  static Map<String, dynamic>? _meta;

  static void reset() => _meta = null;

  static Map<String, dynamic>? get cached {
    if (_meta != null) return _meta;
    try {
      final raw = LocalDb.state().get('rp_meta');
      if (raw is String) _meta = asMap(jsonDecode(raw));
    } catch (_) {}
    return _meta;
  }

  static Future<Map<String, dynamic>> load(Session s, {bool force = false}) async {
    if (!force && _meta != null) return _meta!;
    try {
      final r = await s.api.get('rapports/meta');
      _meta = asMap(r['data']);
      try {
        await LocalDb.state().put('rp_meta', jsonEncode(_meta));
      } catch (_) {}
      return _meta!;
    } catch (e) {
      final c = cached;
      if (c != null) return c;
      rethrow;
    }
  }

  static List<Map<String, dynamic>> sources() => [for (final x in (cached?['sources'] as List? ?? const [])) asMap(x)];

  static Map<String, dynamic>? source(String? key) {
    for (final s in sources()) {
      if (s['key'] == key) return s;
    }
    return null;
  }

  static Map<String, String> periods() {
    final p = cached?['periods'] as List?;
    if (p == null) return _periodsFallback;
    return {for (final x in p) '${x['key']}': '${x['label']}'};
  }

  static List<Map<String, dynamic>> roles() => [for (final x in (cached?['roles'] as List? ?? const [])) asMap(x)];
}

/// Derniers résultats affichés, consultables hors ligne.
class _ResultCache {
  static Map<String, dynamic>? get(String key) {
    try {
      final raw = LocalDb.state().get('rp_cache_$key');
      return raw is String ? asMap(jsonDecode(raw)) : null;
    } catch (_) {
      return null;
    }
  }

  static Future<void> put(String key, Map<String, dynamic> data) async {
    try {
      await LocalDb.state().put('rp_cache_$key', jsonEncode({'at': DateTime.now().toIso8601String(), 'data': data}));
    } catch (_) {}
  }
}

bool _canEdit(Session s, Map<String, dynamic> r) {
  final me = s.user['id'];
  final owner = r['owner_id'];
  final mine = owner != null && '$owner' == '$me';
  if (s.can('rapports.manage')) return r['visibility'] != 'private' || owner == null || mine;
  return mine && (r['visibility'] ?? 'private') == 'private';
}

bool _canCreate(Session s) => s.can('rapports.create') || s.can('rapports.manage');

String _visLabel(Map<String, dynamic> r) => switch (r['visibility']) {
      'company' => 'Toute la société',
      'roles' => 'Partagé avec des rôles',
      _ => 'Privé',
    };

List<Map<String, dynamic>> _reports() => LocalDb.all('rp_reports')..sort((a, b) => '${a['folder'] ?? ''} ${a['name']}'.toLowerCase().compareTo('${b['folder'] ?? ''} ${b['name']}'.toLowerCase()));

List<Map<String, dynamic>> _boards() => LocalDb.all('rp_dashboards')
  ..sort((a, b) {
    final c = toDouble(a['sort']).compareTo(toDouble(b['sort']));
    return c != 0 ? c : '${a['name']}'.compareTo('${b['name']}');
  });

Future<void> _download(BuildContext context, String path, String name, String mime, {Map<String, dynamic>? query}) async {
  try {
    final bytes = await context.read<Session>().api.download(path, query: query);
    final where = await saveFile(name, bytes, mime: mime);
    if (context.mounted) toast(context, tr('Fichier enregistré : $where'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

String _slug(String s) {
  const from = 'àâäáãåçéèêëíìîïñóòôöõúùûüýÿ';
  const to = 'aaaaaaceeeeiiiinooooouuuuyy';
  var out = s.toLowerCase();
  for (var i = 0; i < from.length; i++) {
    out = out.replaceAll(from[i], to[i]);
  }
  out = out.replaceAll(RegExp(r'[^a-z0-9]+'), '_').replaceAll(RegExp(r'^_|_$'), '');
  return out.isEmpty ? 'rapport' : out;
}

// ============================================================================
// Écran principal
// ============================================================================

class RapportsScreen extends StatefulWidget {
  const RapportsScreen({super.key});

  @override
  State<RapportsScreen> createState() => _RapportsScreenState();
}

class _RapportsScreenState extends State<RapportsScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs;
  late final bool _manage;

  @override
  void initState() {
    super.initState();
    final s = context.read<Session>();
    _manage = s.can('rapports.manage');
    _tabs = TabController(length: _manage ? 3 : 2, vsync: this);
    unawaited(RapportsMeta.load(s).then((_) {
      if (mounted) setState(() {});
    }).catchError((_) {}));
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      PageHeader(title: tr('Rapports & tableaux de bord'), subtitle: tr('Vos chiffres regroupés, filtrés et comparés — partagés selon les droits de chacun')),
      TabBar(
        controller: _tabs,
        isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
        tabAlignment: TabAlignment.start,
        tabs: [
          Tab(text: tr('Tableaux de bord')),
          Tab(text: tr('Rapports')),
          if (_manage) Tab(text: tr('Envois programmés')),
        ],
      ),
      const Divider(height: 1),
      Expanded(
        child: TabBarView(controller: _tabs, children: [
          const _BoardsTab(),
          const _ReportsTab(),
          if (_manage) const _SchedulesTab(),
        ]),
      ),
    ]);
  }
}

// ============================================================================
// Tableaux de bord
// ============================================================================

class _BoardsTab extends StatefulWidget {
  const _BoardsTab();

  @override
  State<_BoardsTab> createState() => _BoardsTabState();
}

class _BoardsTabState extends State<_BoardsTab> {
  String? _boardId;
  String? _period; // null : période de chaque rapport (ou celle du tableau)
  Map<String, dynamic>? _run;
  String? _cachedAt;
  Object? _error;
  bool _loading = false;
  bool _edit = false;

  @override
  void initState() {
    super.initState();
    try {
      final syncedBoard = UiPrefs.list('rp_board');
      _boardId = syncedBoard.isNotEmpty ? syncedBoard.first : LocalDb.state().get('rp_board') as String?;
    } catch (_) {}
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Map<String, dynamic>? get _board {
    final all = _boards();
    if (all.isEmpty) return null;
    return all.firstWhere((b) => b['id'] == _boardId, orElse: () => all.first);
  }

  Future<void> _load() async {
    final b = _board;
    if (b == null || !mounted) return;
    final key = 'board_${b['id']}_${_period ?? ''}';
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r = await context.read<Session>().api.get('rapports/dashboards/${b['id']}/run', query: {if (_period != null) 'period': _period});
      final data = asMap(r['data']);
      await _ResultCache.put(key, data);
      if (mounted) {
        setState(() {
          _run = data;
          _cachedAt = null;
        });
      }
    } catch (e) {
      final c = _ResultCache.get(key);
      if (mounted) {
        setState(() {
          if (c != null) {
            _run = asMap(c['data']);
            _cachedAt = '${c['at']}';
          } else {
            _run = null;
          }
          _error = e;
        });
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _select(String id) {
    setState(() {
      _boardId = id;
      _run = null;
      _edit = false;
    });
    try {
      LocalDb.state().put('rp_board', id);
      UiPrefs.save('rp_board', [id]);
    } catch (_) {}
    _load();
  }

  Future<void> _saveWidgets(List<Map<String, dynamic>> widgets) async {
    final b = _board!;
    await context.read<SyncEngine>().save('rp_dashboards', {'id': b['id'], 'widgets': widgets});
    if (mounted) setState(() {});
  }

  Future<void> _addWidget() async {
    final reports = _reports();
    final picked = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (d) => SimpleDialog(
        title: Text(tr('Ajouter un rapport')),
        children: [
          if (reports.isEmpty) Padding(padding: EdgeInsets.all(16), child: Text(tr('Aucun rapport : créez-en un depuis l\'onglet « Rapports ».'))),
          for (final r in reports)
            SimpleDialogOption(
              onPressed: () => Navigator.pop(d, r),
              child: Row(children: [
                Icon(_chartIcons[(r['config'] as Map?)?['chart']] ?? Icons.insights, size: 18),
                const SizedBox(width: 10),
                Expanded(child: Text(tr('${r['name']}'))),
                if (r['folder'] != null) Text(tr('${r['folder']}'), style: TextStyle(color: Theme.of(d).hintColor, fontSize: 12)),
              ]),
            ),
        ],
      ),
    );
    if (picked == null || !mounted) return;
    final chart = (picked['config'] as Map?)?['chart'];
    final widgets = [for (final w in (_board!['widgets'] as List? ?? const [])) asMap(w)];
    widgets.add({'id': newId().substring(0, 12), 'report_id': picked['id'], 'title': null, 'w': chart == 'kpi' ? 1 : 2, 'h': chart == 'kpi' ? 1 : 2});
    await _saveWidgets(widgets);
    unawaited(Future.delayed(const Duration(seconds: 2), _load));
  }

  Future<void> _editBoard({Map<String, dynamic>? board}) async {
    final s = context.read<Session>();
    final res = await showFormDialog<Map<String, dynamic>>(context, title: board == null ? 'Nouveau tableau de bord' : 'Paramètres du tableau', builder: (c) => _BoardForm(board: board, manage: s.can('rapports.manage')));
    if (res == null || !mounted) return;
    final id = board?['id'] as String? ?? newId();
    try {
      await context.read<SyncEngine>().save('rp_dashboards', {'id': id, ...res, if (board == null) 'widgets': <dynamic>[]});
      if (board == null) {
        _select(id);
        setState(() => _edit = true);
      } else {
        setState(() {});
        unawaited(Future.delayed(const Duration(seconds: 2), _load));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rp_dashboards'),
      builder: (context, _, _) {
        final boards = _boards();
        final b = _board;
        if (b == null) {
          return EmptyState(
            icon: Icons.dashboard_customize_outlined,
            title: tr('Aucun tableau de bord'),
            message: tr('Assemblez vos rapports préférés sur une même page.'),
            action: _canCreate(s) ? FilledButton.icon(onPressed: () => _editBoard(), icon: const Icon(Icons.add), label: Text(tr('Créer un tableau de bord'))) : null,
          );
        }
        final editable = _canEdit(s, b);
        final widgets = [for (final w in (b['widgets'] as List? ?? const [])) asMap(w)];
        final periods = Map<String, String>.from(RapportsMeta.periods())..remove('custom');
        final narrow = MediaQuery.of(context).size.width < 700;
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 14, narrow ? 16 : 24, 6),
            child: Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
              SizedBox(
                width: 280,
                child: DropdownButtonFormField<String>(
                  initialValue: b['id'] as String,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: tr('Tableau de bord'), isDense: true),
                  items: [for (final x in boards) DropdownMenuItem(value: x['id'] as String, child: Text(tr('${x['name']}'), overflow: TextOverflow.ellipsis))],
                  onChanged: (v) => v == null ? null : _select(v),
                ),
              ),
              SizedBox(
                width: 230,
                child: DropdownButtonFormField<String?>(
                  initialValue: _period,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: tr('Période'), isDense: true),
                  items: [
                    DropdownMenuItem<String?>(value: null, child: Text(tr(b['period'] != null ? 'Période du tableau (${periods[b['period']] ?? b['period']})' : 'Période de chaque rapport'), overflow: TextOverflow.ellipsis)),
                    for (final e in periods.entries) DropdownMenuItem<String?>(value: e.key, child: Text(tr(e.value))),
                  ],
                  onChanged: (v) {
                    setState(() => _period = v);
                    _load();
                  },
                ),
              ),
              IconButton(tooltip: tr('Actualiser'), onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh)),
              IconButton(
                tooltip: tr('Exporter en PDF'),
                onPressed: () => _download(context, 'rapports/dashboards/${b['id']}/export', '${_slug('${b['name']}')}.pdf', 'application/pdf', query: {if (_period != null) 'period': _period}),
                icon: const Icon(Icons.picture_as_pdf_outlined),
              ),
              if (editable)
                FilterChip(selected: _edit, onSelected: (v) => setState(() => _edit = v), avatar: const Icon(Icons.edit_outlined, size: 16), label: Text(tr('Modifier la disposition'))),
              if (editable && _edit) OutlinedButton.icon(onPressed: _addWidget, icon: const Icon(Icons.add_chart), label: Text(tr('Ajouter un rapport'))),
              if (editable && _edit) TextButton.icon(onPressed: () => _editBoard(board: b), icon: const Icon(Icons.tune), label: Text(tr('Paramètres'))),
              if (editable && _edit)
                TextButton.icon(
                  style: TextButton.styleFrom(foregroundColor: YColors.danger),
                  onPressed: () async {
                    if (await confirm(context, tr('Supprimer « ${b['name']} » ?'), tr('Les rapports eux-mêmes sont conservés.'), danger: true) && context.mounted) {
                      await context.read<SyncEngine>().remove('rp_dashboards', b['id'] as String);
                      setState(() {
                        _edit = false;
                        _run = null;
                      });
                      _load();
                    }
                  },
                  icon: const Icon(Icons.delete_outline),
                  label: Text(tr('Supprimer')),
                ),
              if (_canCreate(s) && !_edit) TextButton.icon(onPressed: () => _editBoard(), icon: const Icon(Icons.add), label: Text(tr('Nouveau tableau'))),
            ]),
          ),
          if (_cachedAt != null)
            Padding(
              padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24, vertical: 4),
              child: Row(children: [
                const Icon(Icons.cloud_off, size: 16, color: YColors.warning),
                const SizedBox(width: 6),
                Expanded(child: Text(tr('Hors connexion — chiffres du ${dateTimeFr(_cachedAt)}'), style: const TextStyle(color: YColors.warning, fontSize: 12.5))),
              ]),
            ),
          if (_loading) const LinearProgressIndicator(minHeight: 2),
          Expanded(
            child: _run == null && _error != null
                ? OnlineOnlyNotice(error: _error!, onRetry: _load)
                : widgets.isEmpty
                    ? EmptyState(
                        icon: Icons.add_chart,
                        title: tr('Tableau vide'),
                        message: editable ? 'Passez en mode « Modifier la disposition » puis ajoutez des rapports.' : 'Aucun rapport sur ce tableau.',
                        action: editable ? FilledButton.icon(onPressed: () {
                          setState(() => _edit = true);
                          _addWidget();
                        }, icon: const Icon(Icons.add_chart), label: Text(tr('Ajouter un rapport'))) : null,
                      )
                    : _grid(widgets, narrow),
          ),
        ]);
      },
    );
  }

  Widget _grid(List<Map<String, dynamic>> widgets, bool narrow) {
    final results = asMap(_run?['widgets']);
    return LayoutBuilder(builder: (context, box) {
      final pad = narrow ? 16.0 : 24.0;
      final cols = box.maxWidth >= 980 ? 4 : (box.maxWidth >= 640 ? 2 : 1);
      final unit = (box.maxWidth - pad * 2 - (cols - 1) * 12) / cols;
      return SingleChildScrollView(
        padding: EdgeInsets.fromLTRB(pad, 8, pad, 24),
        child: Wrap(spacing: 12, runSpacing: 12, children: [
          for (var i = 0; i < widgets.length; i++)
            Builder(builder: (context) {
              final w = widgets[i];
              final span = (toDouble(w['w']).toInt()).clamp(1, cols);
              final h = toDouble(w['h']).toInt().clamp(1, 3);
              return SizedBox(
                width: unit * span + (span - 1) * 12,
                height: h == 1 ? 150 : (h == 2 ? 330 : 500),
                child: _WidgetCard(
                  widget: w,
                  result: results[w['id']] == null ? null : asMap(results[w['id']]),
                  loading: _loading && _run == null,
                  edit: _edit,
                  onOpen: () {
                    final r = LocalDb.get('rp_reports', w['report_id'] as String?);
                    if (r != null) Navigator.of(context).push(MaterialPageRoute(builder: (_) => ReportDetailPage(reportId: r['id'] as String)));
                  },
                  onChange: (nw) {
                    final list = [...widgets];
                    if (nw == null) {
                      list.removeAt(i);
                    } else {
                      list[i] = nw;
                    }
                    _saveWidgets(list);
                  },
                  onMove: (dir) {
                    final j = i + dir;
                    if (j < 0 || j >= widgets.length) return;
                    final list = [...widgets];
                    final t = list[i];
                    list[i] = list[j];
                    list[j] = t;
                    _saveWidgets(list);
                  },
                ),
              );
            }),
        ]),
      );
    });
  }
}

class _WidgetCard extends StatelessWidget {
  const _WidgetCard({required this.widget, required this.result, required this.loading, required this.edit, required this.onOpen, required this.onChange, required this.onMove});

  final Map<String, dynamic> widget;
  final Map<String, dynamic>? result;
  final bool loading;
  final bool edit;
  final VoidCallback onOpen;
  final void Function(Map<String, dynamic>?) onChange;
  final void Function(int dir) onMove;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final rep = LocalDb.get('rp_reports', widget['report_id'] as String?);
    final title = '${widget['title'] ?? result?['title'] ?? rep?['name'] ?? 'Rapport'}';
    Widget body;
    if (result == null) {
      body = loading ? const Center(child: CircularProgressIndicator(strokeWidth: 2)) : Center(child: Text(tr('Pas encore de données.'), style: TextStyle(color: hint)));
    } else if (result!['ok'] != true) {
      body = Center(
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(result!['forbidden'] == true ? Icons.lock_outline : Icons.error_outline, size: 18, color: hint),
          const SizedBox(width: 6),
          Flexible(child: Text(tr('${result!['error']}'), style: TextStyle(color: hint, fontSize: 12.5))),
        ]),
      );
    } else {
      final data = ReportData(asMap(result!['result']));
      final view = ReportView(data: data, compact: true);
      body = data.chart == 'table' || data.chart == 'pivot' ? SingleChildScrollView(child: view) : view;
    }
    final period = result?['ok'] == true ? '${(result!['result'] as Map)['period']?['label'] ?? ''}' : '';
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: edit ? null : onOpen,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 6, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Expanded(
                child: Tooltip(
                  message: period,
                  child: Text(tr(title), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                ),
              ),
              if (edit) ...[
                IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Avant'), onPressed: () => onMove(-1), icon: const Icon(Icons.chevron_left, size: 18)),
                IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Après'), onPressed: () => onMove(1), icon: const Icon(Icons.chevron_right, size: 18)),
                PopupMenuButton<String>(
                  icon: const Icon(Icons.more_vert, size: 18),
                  onSelected: (v) async {
                    if (v == 'remove') return onChange(null);
                    if (v == 'rename') {
                      final c = TextEditingController(text: '${widget['title'] ?? ''}');
                      final ok = await showDialog<bool>(
                        context: context,
                        builder: (d) => YAlertDialog(
                          title: Text(tr('Titre affiché')),
                          content: TextField(controller: c, autofocus: true, decoration: InputDecoration(hintText: trn('${rep?['name'] ?? ''}'), helperText: tr('Vide : nom du rapport'))),
                          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('OK')))],
                        ),
                      );
                      if (ok == true) onChange({...widget, 'title': c.text.trim().isEmpty ? null : c.text.trim()});
                      return;
                    }
                    final parts = v.split(':');
                    onChange({...widget, parts[0]: int.parse(parts[1])});
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'rename', child: Text(tr('Renommer'))),
                    const PopupMenuDivider(),
                    for (final n in [1, 2, 3, 4]) CheckedPopupMenuItem(value: 'w:$n', checked: toDouble(widget['w']).toInt() == n, child: Text(tr('Largeur : $n colonne${n > 1 ? 's' : ''}'))),
                    const PopupMenuDivider(),
                    for (final n in [1, 2, 3]) CheckedPopupMenuItem(value: 'h:$n', checked: toDouble(widget['h']).toInt() == n, child: Text(tr(['Hauteur : petite', 'Hauteur : moyenne', 'Hauteur : grande'][n - 1]))),
                    const PopupMenuDivider(),
                    PopupMenuItem(value: 'remove', child: Text(tr('Retirer du tableau'), style: TextStyle(color: YColors.danger))),
                  ],
                ),
              ] else
                const SizedBox(width: 8),
            ]),
            const SizedBox(height: 8),
            Expanded(child: Padding(padding: const EdgeInsets.only(right: 8), child: body)),
          ]),
        ),
      ),
    );
  }
}

class _BoardForm extends StatefulWidget {
  const _BoardForm({this.board, required this.manage});

  final Map<String, dynamic>? board;
  final bool manage;

  @override
  State<_BoardForm> createState() => _BoardFormState();
}

class _BoardFormState extends State<_BoardForm> {
  late final _name = TextEditingController(text: '${widget.board?['name'] ?? ''}');
  late final _desc = TextEditingController(text: '${widget.board?['description'] ?? ''}');
  late String? _period = widget.board?['period'] as String?;
  late String _vis = '${widget.board?['visibility'] ?? 'private'}';
  late Set<int> _roles = {for (final r in (widget.board?['role_ids'] as List? ?? const [])) toDouble(r).toInt()};

  @override
  Widget build(BuildContext context) {
    final periods = Map<String, String>.from(RapportsMeta.periods())..remove('custom');
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Field(label: tr('Nom'), child: TextField(controller: _name, autofocus: true)),
      Field(label: tr('Description'), child: TextField(controller: _desc, maxLines: 2)),
      Field(
        label: tr('Période imposée à tous les rapports'),
        child: DropdownButtonFormField<String?>(
          initialValue: _period,
          items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Aucune : période de chaque rapport'))), for (final e in periods.entries) DropdownMenuItem<String?>(value: e.key, child: Text(tr(e.value)))],
          onChanged: (v) => setState(() => _period = v),
        ),
      ),
      if (widget.manage) _VisibilityPicker(visibility: _vis, roles: _roles, onChanged: (v, r) => setState(() {
            _vis = v;
            _roles = r;
          })),
      const SizedBox(height: 8),
      FilledButton(
        onPressed: () {
          if (_name.text.trim().isEmpty) return toast(context, tr('Indiquez un nom.'), error: true);
          if (_vis == 'roles' && _roles.isEmpty) return toast(context, tr('Choisissez au moins un rôle.'), error: true);
          Navigator.pop(context, {
            'name': _name.text.trim(), 'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(), 'period': _period,
            if (widget.manage) 'visibility': _vis, if (widget.manage) 'role_ids': _roles.toList(),
          });
        },
        child: Text(tr('Enregistrer')),
      ),
    ]);
  }
}

class _VisibilityPicker extends StatelessWidget {
  const _VisibilityPicker({required this.visibility, required this.roles, required this.onChanged});

  final String visibility;
  final Set<int> roles;
  final void Function(String, Set<int>) onChanged;

  @override
  Widget build(BuildContext context) {
    final all = RapportsMeta.roles();
    return Field(
      label: tr('Partage'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SegmentedButton<String>(
          segments: [
            ButtonSegment(value: 'private', label: Text(tr('Privé')), icon: Icon(Icons.lock_outline, size: 16)),
            ButtonSegment(value: 'roles', label: Text(tr('Rôles')), icon: Icon(Icons.badge_outlined, size: 16)),
            ButtonSegment(value: 'company', label: Text(tr('Société')), icon: Icon(Icons.groups_outlined, size: 16)),
          ],
          selected: {visibility},
          onSelectionChanged: (v) => onChanged(v.first, roles),
        ),
        if (visibility == 'roles') ...[
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final r in all)
              FilterChip(
                label: Text(tr('${r['name']}')),
                selected: roles.contains(toDouble(r['id']).toInt()),
                onSelected: (v) {
                  final n = {...roles};
                  v ? n.add(toDouble(r['id']).toInt()) : n.remove(toDouble(r['id']).toInt());
                  onChanged(visibility, n);
                },
              ),
          ]),
        ],
        const SizedBox(height: 6),
        Text(tr('Chacun ne voit que les données auxquelles ses droits lui donnent accès.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
      ]),
    );
  }
}

// ============================================================================
// Liste des rapports
// ============================================================================

class _ReportsTab extends StatefulWidget {
  const _ReportsTab();

  @override
  State<_ReportsTab> createState() => _ReportsTabState();
}

class _ReportsTabState extends State<_ReportsTab> {
  String _q = '';

  Future<void> _new() async {
    final s = context.read<Session>();
    final choice = await showDialog<Object>(context: context, builder: (_) => const _TemplatesDialog());
    if (choice == null || !mounted) return;
    if (choice == 'blank') {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ReportBuilderPage()));
      return;
    }
    try {
      final r = await s.api.post('rapports/duplicate', {'template': choice});
      final rec = asMap(r['data']);
      await LocalDb.put('rp_reports', rec);
      if (mounted) await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ReportDetailPage(reportId: rec['id'] as String)));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final narrow = MediaQuery.of(context).size.width < 700;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rp_reports'),
      builder: (context, _, _) {
        final q = _q.toLowerCase();
        final list = _reports().where((r) => q.isEmpty || '${r['name']} ${r['description'] ?? ''} ${r['folder'] ?? ''}'.toLowerCase().contains(q)).toList();
        final groups = <String, List<Map<String, dynamic>>>{};
        for (final r in list) {
          groups.putIfAbsent('${r['folder'] ?? 'Sans dossier'}', () => []).add(r);
        }
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 14, narrow ? 16 : 24, 8),
            child: Row(children: [
              Expanded(child: SearchField(onChanged: (v) => setState(() => _q = v), hint: tr('Rechercher un rapport…'))),
              if (_canCreate(s)) ...[const SizedBox(width: 12), FilledButton.icon(onPressed: _new, icon: const Icon(Icons.add), label: Text(tr('Nouveau rapport')))],
            ]),
          ),
          Expanded(
            child: list.isEmpty
                ? EmptyState(icon: Icons.insights, title: tr('Aucun rapport'), message: _canCreate(s) ? 'Partez d\'un des 25 modèles ou d\'une page blanche.' : 'Aucun rapport n\'est partagé avec vous.')
                : ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24), children: [
                    for (final g in groups.entries) ...[
                      Padding(
                        padding: const EdgeInsets.only(top: 12, bottom: 6),
                        child: Text(tr(g.key.toUpperCase()), style: TextStyle(fontSize: 11.5, letterSpacing: 0.8, fontWeight: FontWeight.w700, color: Theme.of(context).hintColor)),
                      ),
                      Card(
                        child: Column(children: [
                          for (final r in g.value)
                            ListTile(
                              leading: CircleAvatar(
                                backgroundColor: YColors.blue.withValues(alpha: 0.1),
                                child: Icon(_chartIcons[(r['config'] as Map?)?['chart']] ?? Icons.insights, color: YColors.blue, size: 20),
                              ),
                              title: Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                              subtitle: Text(tr([
                                RapportsMeta.source('${r['source']}')?['label'] ?? '',
                                RapportsMeta.periods()[(r['config'] as Map?)?['period']] ?? '',
                                if (r['description'] != null && '${r['description']}'.isNotEmpty) '${r['description']}',
                              ].where((x) => '$x'.isNotEmpty).join(' · ')), maxLines: 2, overflow: TextOverflow.ellipsis),
                              trailing: Icon(r['visibility'] == 'private' ? Icons.lock_outline : Icons.groups_outlined, size: 18, color: Theme.of(context).hintColor),
                              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ReportDetailPage(reportId: r['id'] as String))),
                            ),
                        ]),
                      ),
                    ],
                  ]),
          ),
        ]);
      },
    );
  }
}

class _TemplatesDialog extends StatefulWidget {
  const _TemplatesDialog();

  @override
  State<_TemplatesDialog> createState() => _TemplatesDialogState();
}

class _TemplatesDialogState extends State<_TemplatesDialog> {
  List<Map<String, dynamic>>? _t;
  Object? _error;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('rapports/templates').then((r) {
      if (mounted) setState(() => _t = [for (final x in r['data'] as List) asMap(x)]);
    }).catchError((Object e) {
      if (mounted) setState(() => _error = e);
    });
  }

  @override
  Widget build(BuildContext context) {
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final t in _t ?? const <Map<String, dynamic>>[]) {
      groups.putIfAbsent('${t['folder']}', () => []).add(t);
    }
    return Dialog(
      insetPadding: const EdgeInsets.all(24),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760, maxHeight: 720),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 20, 12, 4),
            child: Row(children: [
              Expanded(child: Text(tr('Nouveau rapport'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700))),
              IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close)),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
            child: OutlinedButton.icon(onPressed: () => Navigator.pop(context, 'blank'), icon: const Icon(Icons.tune), label: Text(tr('Partir d\'une page blanche'))),
          ),
          Padding(padding: const EdgeInsets.fromLTRB(24, 8, 24, 4), child: Text(tr('Ou choisissez un modèle'), style: TextStyle(color: Theme.of(context).hintColor))),
          Flexible(
            child: _t == null
                ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: () {}) : const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())))
                : ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
                    for (final g in groups.entries) ...[
                      Padding(padding: const EdgeInsets.fromLTRB(8, 12, 8, 6), child: Text(tr(g.key), style: const TextStyle(fontWeight: FontWeight.w700))),
                      Wrap(spacing: 8, runSpacing: 8, children: [
                        for (final t in g.value)
                          SizedBox(
                            width: 230,
                            child: Card(
                              margin: EdgeInsets.zero,
                              child: InkWell(
                                borderRadius: BorderRadius.circular(12),
                                onTap: () => Navigator.pop(context, t['key']),
                                child: Padding(
                                  padding: const EdgeInsets.all(12),
                                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                    Icon(_chartIcons[(t['config'] as Map?)?['chart']] ?? Icons.insights, color: YColors.blue, size: 20),
                                    const SizedBox(width: 10),
                                    Expanded(
                                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                        Text(tr('${t['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                        const SizedBox(height: 2),
                                        Text(tr('${t['description']}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                                      ]),
                                    ),
                                  ]),
                                ),
                              ),
                            ),
                          ),
                      ]),
                    ],
                  ]),
          ),
        ]),
      ),
    );
  }
}

// ============================================================================
// Détail d'un rapport
// ============================================================================

class ReportDetailPage extends StatefulWidget {
  const ReportDetailPage({super.key, required this.reportId});

  final String reportId;

  @override
  State<ReportDetailPage> createState() => _ReportDetailPageState();
}

class _ReportDetailPageState extends State<ReportDetailPage> {
  Map<String, dynamic>? _res;
  String? _cachedAt;
  Object? _error;
  String? _period;
  String? _chart;
  bool _loading = false;

  Map<String, dynamic>? get _r => LocalDb.get('rp_reports', widget.reportId);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final key = 'report_${widget.reportId}_${_period ?? ''}';
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r = await context.read<Session>().api.get('rapports/reports/${widget.reportId}/run', query: {if (_period != null) 'period': _period});
      final data = asMap(r['data']);
      await _ResultCache.put(key, data);
      if (mounted) {
        setState(() {
          _res = data;
          _cachedAt = null;
        });
      }
    } catch (e) {
      final c = _ResultCache.get(key);
      if (mounted) {
        setState(() {
          _error = e;
          if (c != null) {
            _res = asMap(c['data']);
            _cachedAt = '${c['at']}';
          }
        });
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _addToBoard() async {
    final s = context.read<Session>();
    final boards = _boards().where((b) => _canEdit(s, b)).toList();
    if (boards.isEmpty) return toast(context, tr('Créez d\'abord un tableau de bord (onglet « Tableaux de bord »).'), error: true);
    final b = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (d) => SimpleDialog(title: Text(tr('Ajouter au tableau de bord')), children: [
        for (final b in boards) SimpleDialogOption(onPressed: () => Navigator.pop(d, b), child: Text(tr('${b['name']}'))),
      ]),
    );
    if (b == null || !mounted) return;
    final chart = (_r?['config'] as Map?)?['chart'];
    final widgets = [
      for (final w in (b['widgets'] as List? ?? const [])) asMap(w),
      {'id': newId().substring(0, 12), 'report_id': widget.reportId, 'title': null, 'w': chart == 'kpi' ? 1 : 2, 'h': chart == 'kpi' ? 1 : 2},
    ];
    await context.read<SyncEngine>().save('rp_dashboards', {'id': b['id'], 'widgets': widgets});
    if (mounted) toast(context, tr('Ajouté à « ${b['name']} ».'));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final r = _r;
    if (r == null) return Scaffold(appBar: AppBar(), body: EmptyState(icon: Icons.search_off, title: tr('Rapport introuvable')));
    final periods = Map<String, String>.from(RapportsMeta.periods())..remove('custom');
    final data = _res == null ? null : ReportData(_res!);
    final chart = _chart ?? data?.chart ?? 'table';
    final editable = _canEdit(s, r);
    final narrow = MediaQuery.of(context).size.width < 700;
    final hasDims = data != null && data.dims.isNotEmpty;
    final allowed = <String>[
      if (hasDims) ...['table', 'bar', 'hbar', 'line', 'pie'],
      if (hasDims && data.dims.length == 2) 'pivot',
      'kpi',
    ];
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('${r['name']}')),
        actions: [
          IconButton(tooltip: tr('Actualiser'), onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh)),
          PopupMenuButton<String>(
            onSelected: (v) async {
              switch (v) {
                case 'edit':
                  await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ReportBuilderPage(report: r)));
                  if (mounted) {
                    setState(() => _chart = null);
                    _load();
                  }
                case 'copy':
                  try {
                    final res = await s.api.post('rapports/duplicate', {'report_id': r['id']});
                    final rec = asMap(res['data']);
                    await LocalDb.put('rp_reports', rec);
                    if (context.mounted) await Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => ReportDetailPage(reportId: rec['id'] as String)));
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                case 'xlsx':
                  await _download(context, 'rapports/reports/${r['id']}/export', '${_slug('${r['name']}')}.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', query: {'format': 'xlsx', if (_period != null) 'period': _period});
                case 'pdf':
                  await _download(context, 'rapports/reports/${r['id']}/export', '${_slug('${r['name']}')}.pdf', 'application/pdf', query: {'format': 'pdf', if (_period != null) 'period': _period});
                case 'board':
                  await _addToBoard();
                case 'schedule':
                  if (context.mounted) await editSchedule(context, reportId: r['id'] as String);
                case 'delete':
                  if (await confirm(context, tr('Supprimer « ${r['name']} » ?'), tr('Il sera retiré des tableaux de bord et ses envois programmés seront supprimés.'), danger: true) && context.mounted) {
                    await context.read<SyncEngine>().remove('rp_reports', r['id'] as String);
                    if (context.mounted) Navigator.pop(context);
                  }
              }
            },
            itemBuilder: (_) => [
              if (editable) PopupMenuItem(value: 'edit', child: ListTile(leading: Icon(Icons.tune), title: Text(tr('Modifier')))),
              if (_canCreate(s)) PopupMenuItem(value: 'copy', child: ListTile(leading: Icon(Icons.copy_all_outlined), title: Text(tr('Dupliquer dans mes rapports')))),
              PopupMenuItem(value: 'xlsx', child: ListTile(leading: Icon(Icons.grid_on), title: Text(tr('Exporter en Excel')))),
              PopupMenuItem(value: 'pdf', child: ListTile(leading: Icon(Icons.picture_as_pdf_outlined), title: Text(tr('Exporter en PDF')))),
              if (_canCreate(s)) PopupMenuItem(value: 'board', child: ListTile(leading: Icon(Icons.dashboard_customize_outlined), title: Text(tr('Ajouter à un tableau de bord')))),
              if (s.can('rapports.manage')) PopupMenuItem(value: 'schedule', child: ListTile(leading: Icon(Icons.schedule_send_outlined), title: Text(tr('Programmer l\'envoi par e-mail')))),
              if (editable) PopupMenuItem(value: 'delete', child: ListTile(leading: Icon(Icons.delete_outline, color: YColors.danger), title: Text(tr('Supprimer')))),
            ],
          ),
        ],
      ),
      body: ListView(padding: EdgeInsets.all(narrow ? 16 : 24), children: [
        Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
          SizedBox(
            width: 240,
            child: DropdownButtonFormField<String?>(
              initialValue: _period,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Période'), isDense: true),
              items: [
                DropdownMenuItem<String?>(value: null, child: Text(tr('Période du rapport (${RapportsMeta.periods()[(r['config'] as Map?)?['period']] ?? '—'})'), overflow: TextOverflow.ellipsis)),
                for (final e in periods.entries) DropdownMenuItem<String?>(value: e.key, child: Text(tr(e.value))),
              ],
              onChanged: (v) {
                setState(() => _period = v);
                _load();
              },
            ),
          ),
          if (data != null)
            SegmentedButton<String>(
              showSelectedIcon: false,
              segments: [for (final c in allowed) ButtonSegment(value: c, tooltip: _charts[c], icon: Icon(_chartIcons[c], size: 18))],
              selected: {allowed.contains(chart) ? chart : allowed.first},
              onSelectionChanged: (v) => setState(() => _chart = v.first),
            ),
          Chip(avatar: Icon(r['visibility'] == 'private' ? Icons.lock_outline : Icons.groups_outlined, size: 16), label: Text(tr(_visLabel(r)))),
        ]),
        const SizedBox(height: 12),
        if (_cachedAt != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(tr('Hors connexion — chiffres du ${dateTimeFr(_cachedAt)}'), style: const TextStyle(color: YColors.warning, fontSize: 12.5)),
          ),
        if (_loading && data == null) const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator())),
        if (data == null && _error != null && !_loading) SizedBox(height: 320, child: OnlineOnlyNotice(error: _error!, onRetry: _load)),
        if (data != null) ...[
          Text(tr([data.raw['source_label'], data.periodLabel, if (data.filtersLabel != null) data.filtersLabel].join(' · ')), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
          if (r['description'] != null && '${r['description']}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('${r['description']}'))),
          const SizedBox(height: 12),
          SectionCard(
            child: chart == 'table' || chart == 'pivot' || chart == 'kpi'
                ? ReportView(data: data, chart: chart)
                : SizedBox(height: 380, child: ReportView(data: data, chart: chart)),
          ),
          if (chart != 'table' && chart != 'pivot' && data.dims.isNotEmpty) ...[
            const SizedBox(height: 12),
            SectionCard(title: tr('Données'), child: ReportTable(data: data)),
          ],
          if (data.previous != null && chart != 'kpi')
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: SectionCard(title: tr('Comparaison : ${data.previous!['label']}'), child: KpiView(data: data)),
            ),
        ],
      ]),
    );
  }
}

// ============================================================================
// Constructeur de rapport
// ============================================================================

class ReportBuilderPage extends StatefulWidget {
  const ReportBuilderPage({super.key, this.report});

  final Map<String, dynamic>? report;

  @override
  State<ReportBuilderPage> createState() => _ReportBuilderPageState();
}

class _ReportBuilderPageState extends State<ReportBuilderPage> {
  late final Map<String, dynamic> _c = asMap(widget.report?['config']);
  late String? _source = widget.report?['source'] as String?;
  late final _name = TextEditingController(text: '${widget.report?['name'] ?? ''}');
  late final _desc = TextEditingController(text: '${widget.report?['description'] ?? ''}');
  late final _folder = TextEditingController(text: '${widget.report?['folder'] ?? ''}');
  late String _vis = '${widget.report?['visibility'] ?? 'private'}';
  late Set<int> _roles = {for (final r in (widget.report?['role_ids'] as List? ?? const [])) toDouble(r).toInt()};
  final Map<String, String> _valueLabels = {};
  Map<String, dynamic>? _preview;
  String? _previewError;
  bool _previewing = false;
  Timer? _debounce;
  bool _metaReady = false;

  List<String> get _dims => [for (final d in (_c['dimensions'] as List? ?? const [])) '$d'];
  List<String> get _measures => [for (final d in (_c['measures'] as List? ?? const [])) '$d'];
  List<Map<String, dynamic>> get _filters => [for (final f in (_c['filters'] as List? ?? const [])) asMap(f)];

  @override
  void initState() {
    super.initState();
    _c['period'] ??= 'this_year';
    RapportsMeta.load(context.read<Session>()).then((_) {
      if (!mounted) return;
      setState(() {
        _metaReady = true;
        if (_source == null && RapportsMeta.sources().isNotEmpty) _setSource('${RapportsMeta.sources().first['key']}');
      });
      _resolveFilterLabels();
      _schedulePreview();
    }).catchError((Object e) {
      if (mounted) setState(() => _previewError = errorText(e));
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Map<String, dynamic>? get _src => RapportsMeta.source(_source);

  Map<String, dynamic>? _dim(String key) {
    for (final d in (_src?['dimensions'] as List? ?? const [])) {
      if (d['key'] == key) return asMap(d);
    }
    return null;
  }

  void _setSource(String key) {
    _source = key;
    final src = RapportsMeta.source(key);
    final dimKeys = {for (final d in (src?['dimensions'] as List? ?? const [])) '${d['key']}'};
    final mKeys = [for (final m in (src?['measures'] as List? ?? const [])) '${m['key']}'];
    _c['dimensions'] = _dims.where(dimKeys.contains).toList();
    final keep = _measures.where(mKeys.contains).toList();
    _c['measures'] = keep.isEmpty && mKeys.isNotEmpty ? [mKeys.first] : keep;
    _c['filters'] = _filters.where((f) => dimKeys.contains(f['field'])).toList();
    _c['having'] = <dynamic>[];
    _c['sort'] = null;
    if (_dims.isEmpty && dimKeys.contains('month') && widget.report == null) _c['dimensions'] = ['month'];
    _fixChart();
  }

  void _fixChart() {
    final n = _dims.length;
    var chart = '${_c['chart'] ?? (n == 0 ? 'kpi' : 'table')}';
    if (n == 0) chart = 'kpi';
    if (chart == 'pivot' && n != 2) chart = 'table';
    _c['chart'] = chart;
    final sort = _c['sort'] as Map?;
    if (sort != null && !_dims.contains(sort['by']) && !_measures.contains(sort['by'])) _c['sort'] = null;
  }

  void _changed() {
    _fixChart();
    setState(() {});
    _schedulePreview();
  }

  void _schedulePreview() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 450), _runPreview);
  }

  Future<void> _runPreview() async {
    if (_source == null || !mounted) return;
    setState(() => _previewing = true);
    try {
      final r = await context.read<Session>().api.post('rapports/preview', {'source': _source, 'config': _c});
      if (mounted) {
        setState(() {
          _preview = asMap(r['data']);
          _previewError = null;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _previewError = e is ApiException && e.isNetwork ? 'Aperçu indisponible hors connexion — le rapport peut tout de même être enregistré.' : errorText(e));
      }
    } finally {
      if (mounted) setState(() => _previewing = false);
    }
  }

  Future<void> _resolveFilterLabels() async {
    final s = context.read<Session>();
    for (final f in _filters) {
      final d = _dim('${f['field']}');
      if (d == null) continue;
      if (d['values'] is Map) {
        (d['values'] as Map).forEach((k, v) => _valueLabels['${f['field']}|$k'] = '$v');
        continue;
      }
      if (!['eq', 'neq', 'in', 'nin'].contains(f['op'])) continue;
      try {
        final r = await s.api.get('rapports/values', query: {'source': _source, 'field': f['field']});
        for (final x in r['data'] as List) {
          _valueLabels['${f['field']}|${x['key']}'] = '${x['label']}';
        }
      } catch (_) {}
    }
    if (mounted) setState(() {});
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return toast(context, tr('Donnez un nom au rapport.'), error: true);
    if (_source == null) return;
    if (_vis == 'roles' && _roles.isEmpty) return toast(context, tr('Choisissez au moins un rôle.'), error: true);
    final manage = context.read<Session>().can('rapports.manage');
    final id = widget.report?['id'] as String? ?? newId();
    try {
      await context.read<SyncEngine>().save('rp_reports', {
        'id': id, 'name': _name.text.trim(), 'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
        'folder': _folder.text.trim().isEmpty ? null : _folder.text.trim(), 'source': _source, 'config': _c,
        if (manage || widget.report == null) 'visibility': manage ? _vis : 'private', if (manage) 'role_ids': _roles.toList(),
        if (widget.report == null) 'owner_id': context.read<Session>().user['id'],
      });
      if (!mounted) return;
      toast(context, tr('Rapport enregistré.'));
      if (widget.report == null) {
        await Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => ReportDetailPage(reportId: id)));
      } else {
        Navigator.pop(context);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  // ---------------------------------------------------------------- filtres

  Future<void> _editFilter([int? index]) async {
    final src = _src;
    if (src == null) return;
    final res = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _FilterDialog(source: src, filter: index == null ? null : _filters[index], labels: _valueLabels),
    );
    if (res == null) return;
    final list = _filters;
    if (res['_delete'] == true) {
      if (index != null) list.removeAt(index);
    } else {
      final labels = Map<String, String>.from(res.remove('_labels') as Map? ?? {});
      labels.forEach((k, v) => _valueLabels['${res['field']}|$k'] = v);
      index == null ? list.add(res) : list[index] = res;
    }
    _c['filters'] = list;
    _changed();
  }

  String _filterText(Map<String, dynamic> f) {
    final d = _dim('${f['field']}');
    const ops = {'eq': '=', 'neq': '≠', 'in': 'parmi', 'nin': 'hors', 'contains': 'contient', 'gt': '>', 'gte': '≥', 'lt': '<', 'lte': '≤'};
    final name = '${d?['label'] ?? f['field']}';
    if (f['op'] == 'empty') return '$name non renseigné';
    if (f['op'] == 'not_empty') return '$name renseigné';
    final vals = (f['value'] is List ? f['value'] as List : [f['value']]).map((v) => _valueLabels['${f['field']}|$v'] ?? '$v').join(', ');
    return '$name ${ops[f['op']] ?? f['op']} $vals';
  }

  // ---------------------------------------------------------------- interface

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final wide = MediaQuery.of(context).size.width >= 1000;
    final form = _form(s);
    final preview = _previewPane();
    return Scaffold(
      appBar: AppBar(
        title: Text(tr(widget.report == null ? 'Nouveau rapport' : 'Modifier le rapport')),
        actions: [
          Padding(padding: const EdgeInsets.only(right: 12), child: FilledButton.icon(onPressed: _metaReady ? _save : null, icon: const Icon(Icons.check), label: Text(tr('Enregistrer')))),
        ],
      ),
      body: !_metaReady && RapportsMeta.cached == null
          ? (_previewError != null ? Center(child: Text(tr(_previewError!))) : const Center(child: CircularProgressIndicator()))
          : wide
              ? Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  SizedBox(width: 420, child: form),
                  const VerticalDivider(width: 1),
                  Expanded(child: preview),
                ])
              : ListView(children: [form, SizedBox(height: 460, child: preview)]),
    );
  }

  Widget _form(Session s) {
    final src = _src;
    final dims = [for (final d in (src?['dimensions'] as List? ?? const [])) asMap(d)];
    final measures = [for (final m in (src?['measures'] as List? ?? const [])) asMap(m)];
    final periods = RapportsMeta.periods();
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final x in RapportsMeta.sources()) {
      groups.putIfAbsent('${x['group']}', () => []).add(x);
    }
    final wide = MediaQuery.of(context).size.width >= 1000;
    final children = <Widget>[
      Field(label: tr('Nom du rapport'), child: TextField(controller: _name, decoration: InputDecoration(hintText: tr('Ex. : CA par commercial')))),
      Field(
        label: tr('Données'),
        child: DropdownButtonFormField<String>(
          initialValue: _source,
          isExpanded: true,
          items: [
            for (final g in groups.entries)
              for (final x in g.value) DropdownMenuItem(value: '${x['key']}', child: Text(tr('${g.key} › ${x['label']}'), overflow: TextOverflow.ellipsis)),
          ],
          onChanged: (v) {
            if (v == null) return;
            _setSource(v);
            _changed();
          },
        ),
      ),
      if (src != null) Padding(padding: const EdgeInsets.only(bottom: 14), child: Text(tr('${src['description']}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
      Field(
        label: tr('Regrouper par (2 au plus)'),
        child: Column(children: [
          for (var i = 0; i < 2; i++)
            if (i == 0 || _dims.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: DropdownButtonFormField<String?>(
                  initialValue: i < _dims.length ? _dims[i] : null,
                  isExpanded: true,
                  decoration: InputDecoration(isDense: true, labelText: trn(i == 0 ? 'Lignes' : 'Puis par (colonnes / séries)')),
                  items: [
                    DropdownMenuItem<String?>(value: null, child: Text(tr(i == 0 ? 'Aucun — total seul (indicateur)' : 'Aucun'))),
                    for (final d in dims)
                      if (i == 0 || d['key'] != _dims[0]) DropdownMenuItem<String?>(value: '${d['key']}', child: Text(tr('${d['date'] == true ? '📅 ' : ''}${d['label']}'))),
                  ],
                  onChanged: (v) {
                    final l = _dims;
                    if (v == null) {
                      if (i == 0) {
                        l.clear();
                      } else if (l.length > 1) {
                        l.removeAt(1);
                      }
                    } else if (i < l.length) {
                      l[i] = v;
                    } else {
                      l.add(v);
                    }
                    _c['dimensions'] = l;
                    if (l.length == 2 && _c['chart'] == 'table') _c['chart'] = 'pivot';
                    if (l.isNotEmpty && _c['chart'] == 'kpi') _c['chart'] = 'table';
                    _changed();
                  },
                ),
              ),
        ]),
      ),
      Field(
        label: tr('Mesures'),
        child: Wrap(spacing: 6, runSpacing: 6, children: [
          for (final m in measures)
            FilterChip(
              label: Text(tr('${m['label']}')),
              selected: _measures.contains(m['key']),
              onSelected: (v) {
                final l = _measures;
                if (v) {
                  if (l.length >= 6) return toast(context, tr('Six mesures au maximum.'), error: true);
                  l.add('${m['key']}');
                } else {
                  if (l.length == 1) return;
                  l.remove(m['key']);
                }
                _c['measures'] = l;
                _changed();
              },
            ),
        ]),
      ),
      Field(
        label: tr('Période'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          DropdownButtonFormField<String>(
            initialValue: '${_c['period']}',
            isExpanded: true,
            items: [for (final e in periods.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
            onChanged: (v) {
              _c['period'] = v;
              if (v == 'custom') {
                _c['from'] ??= isoDate(DateTime(DateTime.now().year, 1, 1));
                _c['to'] ??= isoDate(DateTime.now());
              }
              _changed();
            },
          ),
          if (_c['period'] == 'custom')
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(children: [
                for (final k in ['from', 'to'])
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(right: k == 'from' ? 8 : 0),
                      child: OutlinedButton.icon(
                        onPressed: () async {
                          final x = await showDatePicker(context: context, initialDate: DateTime.tryParse('${_c[k]}') ?? DateTime.now(), firstDate: DateTime(2000), lastDate: DateTime(2100));
                          if (x != null) {
                            _c[k] = isoDate(x);
                            _changed();
                          }
                        },
                        icon: const Icon(Icons.event, size: 16),
                        label: Text(tr('${k == 'from' ? 'Du' : 'Au'} ${dateFr(_c[k])}')),
                      ),
                    ),
                  ),
              ]),
            ),
          const SizedBox(height: 8),
          DropdownButtonFormField<String?>(
            initialValue: _c['compare'] as String?,
            isExpanded: true,
            decoration: InputDecoration(isDense: true, labelText: tr('Comparer avec')),
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr('Pas de comparaison'))),
              DropdownMenuItem<String?>(value: 'previous_period', child: Text(tr('La période précédente'))),
              DropdownMenuItem<String?>(value: 'previous_year', child: Text(tr('La même période l\'an dernier'))),
            ],
            onChanged: (v) {
              _c['compare'] = v;
              _changed();
            },
          ),
        ]),
      ),
      Field(
        label: tr('Filtres'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (var i = 0; i < _filters.length; i++) InputChip(label: Text(tr(_filterText(_filters[i]))), onPressed: () => _editFilter(i), onDeleted: () {
              final l = _filters..removeAt(i);
              _c['filters'] = l;
              _changed();
            }),
            ActionChip(avatar: const Icon(Icons.filter_alt_outlined, size: 16), label: Text(tr('Ajouter un filtre')), onPressed: () => _editFilter()),
          ]),
        ]),
      ),
      if (_dims.isNotEmpty)
        Field(
          label: tr('Tri et limite'),
          child: Column(children: [
            Row(children: [
              Expanded(
                child: DropdownButtonFormField<String?>(
                  initialValue: (_c['sort'] as Map?)?['by'] as String?,
                  isExpanded: true,
                  decoration: InputDecoration(isDense: true, labelText: tr('Trier par')),
                  items: [
                    DropdownMenuItem<String?>(value: null, child: Text(tr('Automatique'))),
                    for (final d in _dims) DropdownMenuItem<String?>(value: d, child: Text(tr('${_dim(d)?['label'] ?? d}'))),
                    for (final m in _measures) DropdownMenuItem<String?>(value: m, child: Text(tr('${measures.firstWhere((x) => x['key'] == m, orElse: () => {'label': m})['label']}'))),
                  ],
                  onChanged: (v) {
                    _c['sort'] = v == null ? null : {'by': v, 'dir': (_c['sort'] as Map?)?['dir'] ?? 'desc'};
                    _changed();
                  },
                ),
              ),
              if (_c['sort'] != null)
                IconButton(
                  tooltip: trn((_c['sort'] as Map)['dir'] == 'asc' ? 'Croissant' : 'Décroissant'),
                  onPressed: () {
                    final sort = asMap(_c['sort']);
                    sort['dir'] = sort['dir'] == 'asc' ? 'desc' : 'asc';
                    _c['sort'] = sort;
                    _changed();
                  },
                  icon: Icon((_c['sort'] as Map)['dir'] == 'asc' ? Icons.arrow_upward : Icons.arrow_downward),
                ),
              const SizedBox(width: 8),
              SizedBox(
                width: 130,
                child: DropdownButtonFormField<int?>(
                  initialValue: _c['limit'] == null ? null : toDouble(_c['limit']).toInt(),
                  decoration: InputDecoration(isDense: true, labelText: tr('Limite')),
                  items: [
                    DropdownMenuItem<int?>(value: null, child: Text(tr('Tout'))),
                    DropdownMenuItem<int?>(value: 5, child: Text(tr('Top 5'))),
                    DropdownMenuItem<int?>(value: 10, child: Text(tr('Top 10'))),
                    DropdownMenuItem<int?>(value: 20, child: Text(tr('Top 20'))),
                    DropdownMenuItem<int?>(value: 50, child: Text(tr('Top 50'))),
                  ],
                  onChanged: (v) {
                    _c['limit'] = v;
                    _changed();
                  },
                ),
              ),
            ]),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: Text(tr('Cumul progressif')),
              subtitle: Text(tr('Chaque ligne additionne les précédentes (ex. CA cumulé depuis janvier)')),
              value: _c['cumulative'] == true,
              onChanged: (v) {
                _c['cumulative'] = v;
                _changed();
              },
            ),
          ]),
        ),
      Field(
        label: tr('Présentation'),
        child: Wrap(spacing: 6, runSpacing: 6, children: [
          for (final c in _charts.entries)
            if ((c.key == 'kpi') || (_dims.isNotEmpty && c.key != 'pivot') || (c.key == 'pivot' && _dims.length == 2))
              ChoiceChip(
                avatar: Icon(_chartIcons[c.key], size: 16),
                label: Text(tr(c.value)),
                selected: _c['chart'] == c.key,
                onSelected: (_) {
                  _c['chart'] = c.key;
                  _changed();
                },
              ),
        ]),
      ),
      ExpansionTile(
        tilePadding: EdgeInsets.zero,
        title: Text(tr('Description, dossier et partage'), style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
        children: [
          Field(label: tr('Description'), child: TextField(controller: _desc, maxLines: 2)),
          Field(label: tr('Dossier'), child: TextField(controller: _folder, decoration: InputDecoration(hintText: tr('Ex. : Ventes, Direction…')))),
          if (s.can('rapports.manage'))
            _VisibilityPicker(visibility: _vis, roles: _roles, onChanged: (v, r) => setState(() {
                  _vis = v;
                  _roles = r;
                }))
          else
            Text(tr('Ce rapport reste privé. Un responsable des rapports peut le partager.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
        ],
      ),
    ];
    return wide
        ? ListView(padding: const EdgeInsets.all(20), children: children)
        : Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children));
  }

  Widget _previewPane() {
    final data = _preview == null ? null : ReportData(_preview!);
    final chart = '${_c['chart'] ?? 'table'}';
    return Container(
      color: Theme.of(context).scaffoldBackgroundColor,
      padding: const EdgeInsets.all(20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Text(tr('Aperçu'), style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          const SizedBox(width: 10),
          if (_previewing) const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)),
          const Spacer(),
          if (data != null) Flexible(child: Text(tr(data.periodLabel), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5), overflow: TextOverflow.ellipsis)),
        ]),
        const SizedBox(height: 12),
        if (_previewError != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(children: [
              const Icon(Icons.info_outline, color: YColors.warning, size: 18),
              const SizedBox(width: 8),
              Expanded(child: Text(tr(_previewError!), style: const TextStyle(color: YColors.warning))),
            ]),
          ),
        Expanded(
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: data == null
                  ? Center(child: Text(tr('Choisissez les données et les mesures.'), style: TextStyle(color: Theme.of(context).hintColor)))
                  : (chart == 'table' || chart == 'pivot' || chart == 'kpi')
                      ? SingleChildScrollView(child: ReportView(data: data, chart: chart))
                      : ReportView(data: data, chart: chart),
            ),
          ),
        ),
      ]),
    );
  }
}

class _FilterDialog extends StatefulWidget {
  const _FilterDialog({required this.source, this.filter, required this.labels});

  final Map<String, dynamic> source;
  final Map<String, dynamic>? filter;
  final Map<String, String> labels;

  @override
  State<_FilterDialog> createState() => _FilterDialogState();
}

class _FilterDialogState extends State<_FilterDialog> {
  late String _field = '${widget.filter?['field'] ?? (widget.source['dimensions'] as List).firstWhere((d) => d['date'] != true, orElse: () => (widget.source['dimensions'] as List).first)['key']}';
  late String _op = '${widget.filter?['op'] ?? 'in'}';
  late final Set<String> _selected = {
    for (final v in (widget.filter?['value'] is List ? widget.filter!['value'] as List : [if (widget.filter?['value'] != null) widget.filter!['value']])) '$v',
  };
  late final _text = TextEditingController(text: widget.filter?['value'] is List ? '' : '${widget.filter?['value'] ?? ''}');
  final Map<String, String> _labels = {};
  List<Map<String, dynamic>>? _values;
  String _q = '';
  Timer? _debounce;

  bool get _picker => ['eq', 'neq', 'in', 'nin'].contains(_op);

  @override
  void initState() {
    super.initState();
    widget.labels.forEach((k, v) {
      if (k.startsWith('$_field|')) _labels[k.substring(_field.length + 1)] = v;
    });
    _loadValues();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _loadValues() async {
    try {
      final r = await context.read<Session>().api.get('rapports/values', query: {'source': widget.source['key'], 'field': _field, if (_q.isNotEmpty) 'q': _q});
      if (mounted) setState(() => _values = [for (final x in r['data'] as List) asMap(x)]);
    } catch (_) {
      if (mounted) setState(() => _values = []);
    }
  }

  @override
  Widget build(BuildContext context) {
    final dims = [for (final d in widget.source['dimensions'] as List) asMap(d)];
    const ops = {
      'in': 'Est parmi', 'nin': 'N\'est pas parmi', 'eq': 'Est égal à', 'neq': 'Est différent de', 'contains': 'Contient le texte',
      'gt': 'Supérieur à', 'gte': 'Supérieur ou égal à', 'lt': 'Inférieur à', 'lte': 'Inférieur ou égal à', 'empty': 'N\'est pas renseigné', 'not_empty': 'Est renseigné',
    };
    return YAlertDialog(
      title: Text(tr(widget.filter == null ? 'Ajouter un filtre' : 'Modifier le filtre')),
      content: SizedBox(
        width: 460,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          DropdownButtonFormField<String>(
            initialValue: _field,
            isExpanded: true,
            decoration: InputDecoration(labelText: tr('Champ')),
            items: [for (final d in dims) DropdownMenuItem(value: '${d['key']}', child: Text(tr('${d['label']}')))],
            onChanged: (v) {
              setState(() {
                _field = v!;
                _selected.clear();
                _values = null;
                _labels.clear();
              });
              _loadValues();
            },
          ),
          const SizedBox(height: 8),
          DropdownButtonFormField<String>(
            initialValue: _op,
            isExpanded: true,
            decoration: InputDecoration(labelText: tr('Condition')),
            items: [for (final e in ops.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
            onChanged: (v) => setState(() => _op = v!),
          ),
          const SizedBox(height: 10),
          if (_picker) ...[
            TextField(
              decoration: InputDecoration(prefixIcon: Icon(Icons.search, size: 18), hintText: tr('Rechercher une valeur…'), isDense: true),
              onChanged: (v) {
                _q = v;
                _debounce?.cancel();
                _debounce = Timer(const Duration(milliseconds: 300), _loadValues);
              },
            ),
            const SizedBox(height: 6),
            SizedBox(
              height: 260,
              child: _values == null
                  ? const Center(child: CircularProgressIndicator())
                  : _values!.isEmpty
                      ? Center(child: Text(tr('Aucune valeur (connexion nécessaire).'), style: TextStyle(color: Theme.of(context).hintColor)))
                      : ListView(children: [
                          for (final v in _values!)
                            CheckboxListTile(
                              dense: true,
                              controlAffinity: ListTileControlAffinity.leading,
                              value: _selected.contains('${v['key']}'),
                              title: Text(tr('${v['label']}')),
                              onChanged: (c) => setState(() {
                                _labels['${v['key']}'] = '${v['label']}';
                                if (_op == 'eq' || _op == 'neq') _selected.clear();
                                c == true ? _selected.add('${v['key']}') : _selected.remove('${v['key']}');
                              }),
                            ),
                        ]),
            ),
          ] else if (_op != 'empty' && _op != 'not_empty')
            TextField(controller: _text, autofocus: true, decoration: InputDecoration(labelText: tr('Valeur'))),
        ]),
      ),
      actions: [
        if (widget.filter != null) TextButton(onPressed: () => Navigator.pop(context, {'_delete': true}), style: TextButton.styleFrom(foregroundColor: YColors.danger), child: Text(tr('Supprimer'))),
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: () {
            dynamic value;
            if (_picker) {
              if (_selected.isEmpty) return toast(context, tr('Choisissez au moins une valeur.'), error: true);
              value = _op == 'eq' || _op == 'neq' ? _selected.first : _selected.toList();
            } else if (_op != 'empty' && _op != 'not_empty') {
              if (_text.text.trim().isEmpty) return toast(context, tr('Indiquez une valeur.'), error: true);
              value = _text.text.trim();
            }
            Navigator.pop(context, {'field': _field, 'op': _op, 'value': value, '_labels': _labels});
          },
          child: Text(tr('Appliquer')),
        ),
      ],
    );
  }
}

// ============================================================================
// Envois programmés
// ============================================================================

class _SchedulesTab extends StatefulWidget {
  const _SchedulesTab();

  @override
  State<_SchedulesTab> createState() => _SchedulesTabState();
}

class _SchedulesTabState extends State<_SchedulesTab> {
  List<Map<String, dynamic>>? _list;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('rapports/schedules');
      if (mounted) {
        setState(() {
          _list = [for (final x in r['data'] as List) asMap(x)];
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  String _when(Map<String, dynamic> s) {
    final h = '${toDouble(s['hour']).toInt()} h';
    return switch (s['frequency']) {
      'weekly' => 'Chaque ${_weekdays[(toDouble(s['weekday']).toInt() - 1).clamp(0, 6)].toLowerCase()} à $h',
      'monthly' => 'Le ${toDouble(s['monthday']).toInt()} de chaque mois à $h',
      _ => 'Chaque jour à $h',
    };
  }

  @override
  Widget build(BuildContext context) {
    if (_list == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final narrow = MediaQuery.of(context).size.width < 700;
    final api = context.read<Session>().api;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Padding(
        padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 14, narrow ? 16 : 24, 8),
        child: Row(children: [
          Expanded(child: Text(tr('Rapports et tableaux de bord envoyés automatiquement par e-mail, avec les droits de leur auteur.'), style: TextStyle(color: Theme.of(context).hintColor))),
          FilledButton.icon(
            onPressed: () async {
              if (await editSchedule(context)) _load();
            },
            icon: const Icon(Icons.add),
            label: Text(tr('Nouvel envoi')),
          ),
        ]),
      ),
      Expanded(
        child: _list!.isEmpty
            ? EmptyState(icon: Icons.schedule_send_outlined, title: tr('Aucun envoi programmé'), message: tr('Recevez par exemple le chiffre d\'affaires de la semaine chaque lundi à 7 h.'))
            : ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24), children: [
                for (final s in _list!)
                  Card(
                    child: ListTile(
                      leading: Icon(s['target_kind'] == 'dashboard' ? Icons.dashboard_outlined : Icons.insights, color: s['active'] == true ? YColors.blue : Theme.of(context).hintColor),
                      title: Text(tr('${s['target_name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      subtitle: Text(tr([
                        _when(s),
                        (s['format'] == 'xlsx' ? 'Excel' : 'PDF'),
                        (s['recipients'] as List).join(', '),
                        if (s['last_sent_at'] != null) 'dernier envoi ${dateTimeFr(s['last_sent_at'])}',
                        if (s['last_status'] == 'failed') 'échec : ${s['last_error']}',
                      ].join(' · ')), maxLines: 3, overflow: TextOverflow.ellipsis),
                      isThreeLine: true,
                      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                        if (s['last_status'] == 'failed') const Icon(Icons.error_outline, color: YColors.danger, size: 18),
                        Switch(
                          value: s['active'] == true,
                          onChanged: (v) async {
                            try {
                              await api.put('rapports/schedules/${s['id']}', {'active': v});
                              _load();
                            } catch (e) {
                              if (context.mounted) toast(context, errorText(e), error: true);
                            }
                          },
                        ),
                        PopupMenuButton<String>(
                          onSelected: (v) async {
                            try {
                              if (v == 'send') {
                                await api.post('rapports/schedules/${s['id']}/send');
                                if (context.mounted) toast(context, tr('Envoyé.'));
                              } else if (v == 'edit') {
                                if (!await editSchedule(context, schedule: s)) return;
                              } else if (v == 'delete') {
                                if (!await confirm(context, tr('Supprimer cet envoi ?'), '${s['target_name']} — ${_when(s)}', danger: true)) return;
                                await api.delete('rapports/schedules/${s['id']}');
                              }
                              _load();
                            } catch (e) {
                              if (context.mounted) toast(context, errorText(e), error: true);
                              _load();
                            }
                          },
                          itemBuilder: (_) => [
                            PopupMenuItem(value: 'send', child: Text(tr('Envoyer maintenant'))),
                            PopupMenuItem(value: 'edit', child: Text(tr('Modifier'))),
                            PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
                          ],
                        ),
                      ]),
                    ),
                  ),
              ]),
      ),
    ]);
  }
}

/// Création ou modification d'un envoi programmé. Renvoie vrai si enregistré.
Future<bool> editSchedule(BuildContext context, {Map<String, dynamic>? schedule, String? reportId}) async {
  final ok = await showFormDialog<bool>(context, title: schedule == null ? 'Programmer un envoi' : 'Modifier l\'envoi', builder: (_) => _ScheduleForm(schedule: schedule, reportId: reportId));
  return ok == true;
}

class _ScheduleForm extends StatefulWidget {
  const _ScheduleForm({this.schedule, this.reportId});

  final Map<String, dynamic>? schedule;
  final String? reportId;

  @override
  State<_ScheduleForm> createState() => _ScheduleFormState();
}

class _ScheduleFormState extends State<_ScheduleForm> {
  late String _target = widget.schedule != null
      ? (widget.schedule!['report_id'] != null ? 'r:${widget.schedule!['report_id']}' : 'd:${widget.schedule!['dashboard_id']}')
      : (widget.reportId != null ? 'r:${widget.reportId}' : '');
  late String _freq = '${widget.schedule?['frequency'] ?? 'weekly'}';
  late int _weekday = toDouble(widget.schedule?['weekday'] ?? 1).toInt();
  late int _monthday = toDouble(widget.schedule?['monthday'] ?? 1).toInt();
  late int _hour = toDouble(widget.schedule?['hour'] ?? 7).toInt();
  late String _format = '${widget.schedule?['format'] ?? 'pdf'}';
  late String? _period = widget.schedule?['period'] as String?;
  late final _to = TextEditingController(text: ((widget.schedule?['recipients'] as List?) ?? [context.read<Session>().user['email'] ?? '']).join(', '));
  late final _msg = TextEditingController(text: '${widget.schedule?['message'] ?? ''}');
  bool _saving = false;

  @override
  Widget build(BuildContext context) {
    final reports = _reports();
    final boards = _boards();
    final periods = Map<String, String>.from(RapportsMeta.periods())..remove('custom');
    final isBoard = _target.startsWith('d:');
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Field(
        label: tr('Quoi'),
        child: DropdownButtonFormField<String>(
          initialValue: _target.isEmpty ? null : _target,
          isExpanded: true,
          hint: Text(tr('Choisir un rapport ou un tableau de bord')),
          items: [
            for (final b in boards) DropdownMenuItem(value: 'd:${b['id']}', enabled: widget.schedule == null, child: Text(tr('Tableau de bord › ${b['name']}'), overflow: TextOverflow.ellipsis)),
            for (final r in reports) DropdownMenuItem(value: 'r:${r['id']}', enabled: widget.schedule == null, child: Text(tr('Rapport › ${r['name']}'), overflow: TextOverflow.ellipsis)),
          ],
          onChanged: widget.schedule != null ? null : (v) => setState(() {
                _target = v ?? '';
                if (_target.startsWith('d:')) _format = 'pdf';
              }),
        ),
      ),
      Field(
        label: tr('Quand'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'daily', label: Text(tr('Chaque jour'))), ButtonSegment(value: 'weekly', label: Text(tr('Semaine'))), ButtonSegment(value: 'monthly', label: Text(tr('Mois')))],
            selected: {_freq},
            onSelectionChanged: (v) => setState(() => _freq = v.first),
          ),
          const SizedBox(height: 8),
          Row(children: [
            if (_freq == 'weekly')
              Expanded(
                child: DropdownButtonFormField<int>(
                  initialValue: _weekday,
                  decoration: InputDecoration(isDense: true, labelText: tr('Jour')),
                  items: [for (var i = 1; i <= 7; i++) DropdownMenuItem(value: i, child: Text(tr(_weekdays[i - 1])))],
                  onChanged: (v) => setState(() => _weekday = v!),
                ),
              ),
            if (_freq == 'monthly')
              Expanded(
                child: DropdownButtonFormField<int>(
                  initialValue: _monthday,
                  decoration: InputDecoration(isDense: true, labelText: tr('Jour du mois')),
                  items: [for (var i = 1; i <= 28; i++) DropdownMenuItem(value: i, child: Text(tr('Le $i')))],
                  onChanged: (v) => setState(() => _monthday = v!),
                ),
              ),
            if (_freq != 'daily') const SizedBox(width: 8),
            Expanded(
              child: DropdownButtonFormField<int>(
                initialValue: _hour,
                decoration: InputDecoration(isDense: true, labelText: tr('Heure')),
                items: [for (var i = 0; i < 24; i++) DropdownMenuItem(value: i, child: Text(tr('$i h')))],
                onChanged: (v) => setState(() => _hour = v!),
              ),
            ),
          ]),
        ]),
      ),
      Field(
        label: tr('Période des chiffres'),
        child: DropdownButtonFormField<String?>(
          initialValue: _period,
          isExpanded: true,
          items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Celle du rapport'))), for (final e in periods.entries) DropdownMenuItem<String?>(value: e.key, child: Text(tr(e.value)))],
          onChanged: (v) => setState(() => _period = v),
        ),
      ),
      Field(label: tr('Destinataires (e-mails séparés par des virgules)'), child: TextField(controller: _to, maxLines: 2)),
      Field(
        label: tr('Pièce jointe'),
        child: SegmentedButton<String>(
          segments: [ButtonSegment(value: 'pdf', label: Text(tr('PDF'))), ButtonSegment(value: 'xlsx', label: Text(tr('Excel')), enabled: !isBoard)],
          selected: {_format},
          onSelectionChanged: (v) => setState(() => _format = v.first),
        ),
      ),
      Field(label: tr('Message (facultatif)'), child: TextField(controller: _msg, maxLines: 3, decoration: InputDecoration(hintText: tr('Bonjour, voici les chiffres de la semaine…')))),
      FilledButton(
        onPressed: _saving
            ? null
            : () async {
                final to = _to.text.split(RegExp(r'[,;\s]+')).map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
                if (_target.isEmpty) return toast(context, tr('Choisissez un rapport ou un tableau de bord.'), error: true);
                if (to.isEmpty) return toast(context, tr('Indiquez au moins un destinataire.'), error: true);
                final body = <String, dynamic>{
                  'frequency': _freq, 'weekday': _weekday, 'monthday': _monthday, 'hour': _hour, 'recipients': to, 'format': _format, 'period': _period,
                  'message': _msg.text.trim().isEmpty ? null : _msg.text.trim(),
                  if (widget.schedule == null) (_target.startsWith('r:') ? 'report_id' : 'dashboard_id'): _target.substring(2),
                };
                setState(() => _saving = true);
                try {
                  final api = context.read<Session>().api;
                  widget.schedule == null ? await api.post('rapports/schedules', body) : await api.put('rapports/schedules/${widget.schedule!['id']}', body);
                  if (context.mounted) {
                    toast(context, tr('Envoi programmé.'));
                    Navigator.pop(context, true);
                  }
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                } finally {
                  if (mounted) setState(() => _saving = false);
                }
              },
        child: Text(tr('Enregistrer')),
      ),
    ]);
  }
}
