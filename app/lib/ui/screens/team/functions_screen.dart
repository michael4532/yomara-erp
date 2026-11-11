import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../../files/save_file.dart';
import 'org_export.dart';
import '../../widgets/pdf_viewer.dart';

/// Fonctions & organigramme : l'entreprise crée ses propres fonctions (Directeur commercial, Chef d'équipe…),
/// les range sous leur supérieure et choisit, module par module, ce que chacune peut faire.
class FunctionsScreen extends StatefulWidget {
  const FunctionsScreen({super.key});

  static Future<void> open(BuildContext context) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const FunctionsScreen()));

  @override
  State<FunctionsScreen> createState() => _FunctionsScreenState();
}

class _FunctionsScreenState extends State<FunctionsScreen> {
  Map<String, dynamic>? _meta;
  Map<String, dynamic>? _perms;
  Object? _error;
  bool _chart = false;
  String _q = '';
  String? _module;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final r = await Future.wait([api.get('users/form-meta'), api.get('permissions')]);
      if (mounted) setState(() => (_meta = asMap(r[0]), _perms = asMap(r[1]), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  List<Map<String, dynamic>> get _roles => [for (final r in (_meta?['roles'] as List? ?? const [])) asMap(r)];
  List<Map<String, dynamic>> get _users => [for (final u in (_meta?['users'] as List? ?? const [])) asMap(u)];
  List<Map<String, dynamic>> get _modules => [for (final m in (_meta?['modules'] as List? ?? const [])) asMap(m)].where((m) => m['core'] != true).toList();

  List<Map<String, dynamic>> _children(Object? parent) {
    final ids = {for (final r in _roles) r['id']};
    return _roles.where((r) => parent == null ? (r['parent_id'] == null || !ids.contains(r['parent_id'])) : r['parent_id'] == parent).toList()
      ..sort((a, b) {
        final adm = (b['is_admin'] == true ? 1 : 0).compareTo(a['is_admin'] == true ? 1 : 0);
        return adm != 0 ? adm : '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase());
      });
  }

  List<(Map<String, dynamic>, int)> _tree() {
    final out = <(Map<String, dynamic>, int)>[];
    void walk(Object? parent, int depth) {
      for (final r in _children(parent)) {
        if (out.any((o) => o.$1['id'] == r['id']) || depth > 8) continue;
        out.add((r, depth));
        walk(r['id'], depth + 1);
      }
    }

    walk(null, 0);
    return out;
  }

  int get _levels => _tree().fold(0, (m, t) => t.$2 + 1 > m ? t.$2 + 1 : m);

  bool _matches(Map<String, dynamic> r) {
    final q = _q.toLowerCase();
    final okQ = q.isEmpty || '${r['name']} ${r['description'] ?? ''}'.toLowerCase().contains(q) || _users.any((u) => u['role'] == r['name'] && '${u['name']}'.toLowerCase().contains(q));
    final okM = _module == null || r['is_admin'] == true || (r['modules'] as List? ?? const []).contains(_module);
    return okQ && okM;
  }

  Future<void> _edit([Map<String, dynamic>? role, Map<String, dynamic>? copyFrom]) async {
    final saved = await showDialog<bool>(
      context: context,
      builder: (_) => Dialog(
        insetPadding: const EdgeInsets.all(20),
        clipBehavior: Clip.antiAlias,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 1060, maxHeight: 840),
          child: _FunctionEditor(role: role, copyFrom: copyFrom, roles: _roles, perms: _perms!),
        ),
      ),
    );
    if (saved == true) _load();
  }

  Future<void> _delete(Map<String, dynamic> r) async {
    if (!await confirm(context, tr('Supprimer « ${r['name']} » ?'), tr('Les fonctions rattachées à celle-ci remontent d\'un niveau.'), ok: 'Supprimer', danger: true)) return;
    if (!mounted) return;
    try {
      await context.read<Session>().api.delete('roles/${r['id']}');
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Color _color(Map<String, dynamic> r) {
    final c = '${r['color'] ?? ''}';
    if (c.length == 7 && c.startsWith('#')) return Color(int.parse('FF${c.substring(1)}', radix: 16));
    return r['is_admin'] == true ? const Color(0xFF334155) : YColors.blue;
  }

  @override
  Widget build(BuildContext context) {
    final w = MediaQuery.sizeOf(context).width;
    final narrow = w < 700;
    final side = narrow ? 16.0 : (w > 1240 ? (w - 1180) / 2 : 28.0);
    return Scaffold(
      appBar: AppBar(title: Text(tr('Fonctions & organigramme')), centerTitle: false),
      floatingActionButton: _meta == null || !narrow ? null : FloatingActionButton.extended(onPressed: () => _edit(), icon: const Icon(Icons.add), label: Text(tr('Nouvelle fonction'))),
      body: _error != null
          ? OnlineOnlyNotice(error: _error!, onRetry: _load)
          : _meta == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: EdgeInsets.fromLTRB(side, 8, side, 96), children: [
                    _hero(narrow),
                    const SizedBox(height: 16),
                    _toolbar(narrow),
                    const SizedBox(height: 14),
                    if (_chart) _orgChart() else ..._functionList(narrow),
                  ]),
                ),
    );
  }

  /// Bandeau : ce que fait l'écran, chiffres clés, actions.
  Widget _hero(bool narrow) {
    final people = _users.length;
    final vacant = _roles.where((r) => (r['users_count'] ?? 0) == 0).length;
    Widget stat(String v, String l) => Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(v, style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800, height: 1)),
          const SizedBox(height: 4),
          Text(tr(l), style: const TextStyle(color: Color(0xFFBFD0F2), fontSize: 12.5)),
        ]);
    return Container(
      padding: EdgeInsets.all(narrow ? 18 : 24),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: const LinearGradient(colors: [Color(0xFF0E1E45), Color(0xFF1D4FBF)], begin: Alignment.topLeft, end: Alignment.bottomRight),
      ),
      child: Wrap(spacing: 32, runSpacing: 18, crossAxisAlignment: WrapCrossAlignment.center, alignment: WrapAlignment.spaceBetween, children: [
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('Votre organisation'), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(
              tr('Créez vos fonctions, rangez-les sous leur supérieure et choisissez ce que chacune peut faire. Le responsable direct de chaque employé valide ses demandes quand vos circuits d\'approbation le prévoient.'),
              style: const TextStyle(color: Color(0xFFD5DFF5), height: 1.45),
            ),
          ]),
        ),
        Wrap(spacing: 28, runSpacing: 14, crossAxisAlignment: WrapCrossAlignment.center, children: [
          stat('${_roles.length}', 'fonctions'),
          stat('$people', 'personnes'),
          stat('$_levels', _levels > 1 ? 'niveaux' : 'niveau'),
          stat('$vacant', 'postes non pourvus'),
          if (!narrow)
            FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: YColors.yellow, foregroundColor: YColors.night, padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16)),
              onPressed: () => _edit(),
              icon: const Icon(Icons.add),
              label: Text(tr('Nouvelle fonction'), style: const TextStyle(fontWeight: FontWeight.w700)),
            ),
        ]),
      ]),
    );
  }

  /// Vue (liste / organigramme), recherche et filtre par module.
  Widget _toolbar(bool narrow) {
    final mods = _modules..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final view = SegmentedButton<bool>(
      showSelectedIcon: false,
      segments: [
        ButtonSegment(value: false, icon: const Icon(Icons.view_list_rounded, size: 18), label: Text(tr('Fonctions'))),
        ButtonSegment(value: true, icon: const Icon(Icons.account_tree_rounded, size: 18), label: Text(tr('Organigramme'))),
      ],
      selected: {_chart},
      onSelectionChanged: (v) => setState(() => _chart = v.first),
    );
    final search = SizedBox(width: narrow ? double.infinity : 300, child: SearchField(hint: tr('Fonction ou personne…'), onChanged: (v) => setState(() => _q = v)));
    final module = SizedBox(
      width: narrow ? double.infinity : 260,
      child: DropdownButtonFormField<String?>(
        initialValue: _module,
        isExpanded: true,
        decoration: InputDecoration(prefixIcon: const Icon(Icons.apps_rounded, size: 20), hintText: tr('Tous les modules')),
        items: [
          DropdownMenuItem<String?>(value: null, child: Text(tr('Tous les modules'))),
          for (final m in mods) DropdownMenuItem<String?>(value: '${m['key']}', child: Text(tr('${m['name']}'), overflow: TextOverflow.ellipsis)),
        ],
        onChanged: (v) => setState(() => _module = v),
      ),
    );
    return Wrap(spacing: 12, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [view, search, module]);
  }

  List<Widget> _functionList(bool narrow) {
    final theme = Theme.of(context);
    final rows = _tree().where((t) => _matches(t.$1)).toList();
    final filtered = _q.isNotEmpty || _module != null;
    if (rows.isEmpty) return [EmptyState(icon: Icons.search_off, title: tr('Aucune fonction ne correspond'), message: tr('Changez la recherche ou le module choisi.'))];
    return [
      if (_module != null)
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Text(tr('${rows.length} fonction(s) avec accès à « ${_modules.firstWhere((m) => m['key'] == _module, orElse: () => {'name': _module})['name']} »'),
              style: TextStyle(color: theme.hintColor, fontWeight: FontWeight.w600)),
        ),
      for (final (r, depth) in rows) _functionCard(theme, r, filtered ? 0 : depth, narrow),
    ];
  }

  Widget _functionCard(ThemeData theme, Map<String, dynamic> r, int depth, bool narrow) {
    final color = _color(r);
    final people = _users.where((u) => u['role'] == r['name']).toList();
    final mods = (r['modules'] as List? ?? const []).length;
    final admin = r['is_admin'] == true;
    final indent = depth * (narrow ? 14.0 : 32.0);
    return Padding(
      padding: EdgeInsets.only(left: indent, bottom: 10),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (depth > 0)
          Padding(
            padding: const EdgeInsets.only(top: 22, right: 8),
            child: Container(width: narrow ? 8 : 18, height: 2, color: color.withValues(alpha: .4)),
          ),
        Expanded(
          child: Material(
            color: theme.cardColor,
            borderRadius: BorderRadius.circular(16),
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: admin ? null : () => _edit(r),
              child: Container(
                padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: theme.dividerColor.withValues(alpha: .6)),
                ),
                child: Row(children: [
                  Container(
                    width: 46,
                    height: 46,
                    decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(13)),
                    alignment: Alignment.center,
                    child: admin
                        ? Icon(Icons.shield_rounded, color: color, size: 22)
                        : Text(_initials('${r['name']}'), style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 15)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Flexible(child: Text(tr('${r['name']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5))),
                        if (depth == 0 && !admin && _children(r['id']).isNotEmpty) ...[
                          const SizedBox(width: 8),
                          StatusChip('${_children(r['id']).length} sous-fonction(s)', color: color),
                        ],
                      ]),
                      if ('${r['description'] ?? ''}'.isNotEmpty) ...[
                        const SizedBox(height: 2),
                        Text(tr('${r['description']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
                      ],
                      const SizedBox(height: 8),
                      Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                        _pill(theme, Icons.apps_rounded, admin ? 'Accès complet' : '$mods module(s)'),
                        if (people.isEmpty)
                          _pill(theme, Icons.person_off_outlined, 'Poste non pourvu', muted: true)
                        else
                          _avatars(people),
                      ]),
                    ]),
                  ),
                  PopupMenuButton<String>(
                    tooltip: tr('Actions'),
                    icon: const Icon(Icons.more_horiz),
                    onSelected: (v) => switch (v) {
                      'edit' => _edit(r),
                      'copy' => _edit(null, r),
                      'sub' => _edit(null, {'parent_id': r['id'], 'name': ''}),
                      _ => _delete(r),
                    },
                    itemBuilder: (_) => [
                      if (!admin) PopupMenuItem(value: 'edit', child: ListTile(dense: true, leading: const Icon(Icons.edit_outlined), title: Text(tr('Modifier')))),
                      PopupMenuItem(value: 'sub', child: ListTile(dense: true, leading: const Icon(Icons.subdirectory_arrow_right), title: Text(tr('Ajouter une fonction en dessous')))),
                      if (!admin) PopupMenuItem(value: 'copy', child: ListTile(dense: true, leading: const Icon(Icons.copy_outlined), title: Text(tr('Dupliquer')))),
                      if (r['is_system'] != true && (r['users_count'] ?? 0) == 0)
                        PopupMenuItem(value: 'delete', child: ListTile(dense: true, leading: const Icon(Icons.delete_outline, color: YColors.danger), title: Text(tr('Supprimer'), style: const TextStyle(color: YColors.danger)))),
                    ],
                  ),
                ]),
              ),
            ),
          ),
        ),
      ]),
    );
  }

  String _initials(String s) {
    final w = s.split(RegExp(r'[\s/&\-]+')).where((x) => x.isNotEmpty && x.length > 1).toList();
    if (w.isEmpty) return s.isEmpty ? '?' : s[0].toUpperCase();
    return (w.length == 1 ? w[0].substring(0, w[0].length >= 2 ? 2 : 1) : '${w[0][0]}${w[1][0]}').toUpperCase();
  }

  Widget _pill(ThemeData theme, IconData icon, String text, {bool muted = false}) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
        decoration: BoxDecoration(color: theme.colorScheme.surfaceContainer, borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 14, color: theme.hintColor),
          const SizedBox(width: 5),
          Text(tr(text), style: TextStyle(fontSize: 12, color: muted ? theme.hintColor : null, fontWeight: FontWeight.w600)),
        ]),
      );

  Widget _avatars(List<Map<String, dynamic>> people) {
    final shown = people.take(5).toList();
    return Tooltip(
      message: people.map((u) => '${u['name']}').join(', '),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        SizedBox(
          width: 22.0 * shown.length + 8,
          height: 28,
          child: Stack(children: [
            for (final (i, u) in shown.indexed)
              Positioned(
                left: 22.0 * i,
                child: Container(
                  decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: Theme.of(context).cardColor, width: 2)),
                  child: InitialsAvatar('${u['name']}', size: 26),
                ),
              ),
          ]),
        ),
        const SizedBox(width: 4),
        Text(tr(people.length == 1 ? '${people.first['name']}' : '${people.length} personnes'), style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
      ]),
    );
  }

  /// Organigramme : par responsable (chaque employé sous la personne à qui il rend compte) ou par fonction.
  String? _orgBy;

  String get _orgMode => _orgBy ?? (_users.any((u) => u['manager_id'] != null) ? 'people' : 'functions');

  int _colorOf(Map<String, dynamic>? r) => r == null ? 0xFF1F6FEB : _color(r).toARGB32();

  /// Arbre des personnes d'après « Responsable direct » ; les isolés (ni responsable ni équipe) à part.
  (List<OrgNode>, List<OrgNode>) _peopleTree() {
    final users = _users.where((u) => _q.isEmpty || '${u['name']} ${u['role']} ${u['job_title'] ?? ''}'.toLowerCase().contains(_q.toLowerCase()) || _users.any((x) => x['manager_id'] == u['id'])).toList();
    final ids = {for (final u in _users) u['id']};
    Map<String, dynamic>? roleOf(Map<String, dynamic> u) => _roles.where((r) => r['name'] == u['role']).firstOrNull;
    OrgNode build(Map<String, dynamic> u, int depth, Set<Object?> seen) {
      seen.add(u['id']);
      final kids = depth > 10 ? <Map<String, dynamic>>[] : users.where((x) => x['manager_id'] == u['id'] && !seen.contains(x['id'])).toList();
      return OrgNode(
        title: '${u['name']}',
        subtitle: [if ('${u['job_title'] ?? ''}'.isNotEmpty) '${u['job_title']}', if (u['role'] != null) '${u['role']}'].join(' · '),
        color: _colorOf(roleOf(u)),
        children: [for (final k in kids) build(k, depth + 1, seen)],
      );
    }

    final tops = users.where((u) => u['manager_id'] == null || !ids.contains(u['manager_id'])).toList();
    final roots = <OrgNode>[], loose = <OrgNode>[];
    for (final u in tops) {
      final n = build(u, 0, {});
      (n.children.isEmpty ? loose : roots).add(n);
    }
    roots.sort((a, b) => b.size.compareTo(a.size));
    return (roots, loose);
  }

  /// Arbre des fonctions (avec leurs titulaires).
  (List<OrgNode>, List<OrgNode>) _functionTree() {
    OrgNode build(Map<String, dynamic> r, int depth) {
      final people = _users.where((u) => u['role'] == r['name']).toList();
      return OrgNode(
        title: '${r['name']}',
        subtitle: people.isEmpty ? 'Poste non pourvu' : (people.length == 1 ? null : '${people.length} personnes'),
        lines: [for (final u in people) '${u['name']}${'${u['job_title'] ?? ''}'.isNotEmpty ? ' — ${u['job_title']}' : ''}'],
        color: _colorOf(r),
        children: depth > 8 ? [] : [for (final k in _children(r['id']).where(_inChart)) build(k, depth + 1)],
      );
    }

    final roots = <OrgNode>[], loose = <OrgNode>[];
    for (final r in _children(null).where(_inChart)) {
      final n = build(r, 0);
      (n.children.isEmpty ? loose : roots).add(n);
    }
    roots.sort((a, b) => b.size.compareTo(a.size));
    return (roots, loose);
  }

  Future<void> _export(String kind) async {
    final (roots, loose) = _orgMode == 'people' ? _peopleTree() : _functionTree();
    final company = '${context.read<Session>().company['name'] ?? ''}';
    final title = _orgMode == 'people' ? 'Organigramme (responsables)' : 'Organigramme des fonctions';
    try {
      if (kind == 'docx') {
        final bytes = orgChartDocx(company: company, title: title, roots: roots, loose: loose);
        final where = await saveFile('organigramme.docx', bytes, mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        if (mounted) toast(context, tr('Document Word enregistré : $where'));
        return;
      }
      final bytes = await orgChartPdf(company: company, title: title, roots: roots, loose: loose);
      if (kind == 'print') {
        await previewPdf(name: title, onLayout: (_) async => bytes);
      } else {
        final where = await saveFile('organigramme.pdf', bytes, mime: 'application/pdf');
        if (mounted) toast(context, tr('PDF enregistré : $where'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Widget _orgChart() {
    final theme = Theme.of(context);
    final people = _orgMode == 'people';
    final (trees, singles) = people ? _peopleTree() : _functionTree();
    final bar = Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
      SegmentedButton<String>(
        showSelectedIcon: false,
        segments: [
          ButtonSegment(value: 'people', icon: const Icon(Icons.supervisor_account_outlined, size: 18), label: Text(tr('Par responsable'))),
          ButtonSegment(value: 'functions', icon: const Icon(Icons.work_outline, size: 18), label: Text(tr('Par fonction'))),
        ],
        selected: {_orgMode},
        onSelectionChanged: (v) => setState(() => _orgBy = v.first),
      ),
      OutlinedButton.icon(onPressed: () => _export('print'), icon: const Icon(Icons.print_outlined, size: 18), label: Text(tr('Imprimer'))),
      OutlinedButton.icon(onPressed: () => _export('pdf'), icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(tr('PDF'))),
      OutlinedButton.icon(onPressed: () => _export('docx'), icon: const Icon(Icons.description_outlined, size: 18), label: Text(tr('Word'))),
    ]);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      bar,
      const SizedBox(height: 14),
      if (trees.isNotEmpty)
        Container(
          decoration: BoxDecoration(color: theme.colorScheme.surfaceContainerLow, borderRadius: BorderRadius.circular(20), border: Border.all(color: theme.dividerColor.withValues(alpha: .5))),
          padding: const EdgeInsets.all(24),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              for (final (i, n) in trees.indexed) Padding(padding: EdgeInsets.only(right: i == trees.length - 1 ? 0 : 40), child: _subtree(n, people)),
            ]),
          ),
        )
      else
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(color: noticeFill(context), borderRadius: BorderRadius.circular(14)),
          child: Row(children: [
            const Icon(Icons.lightbulb_outline, color: YColors.warning),
            const SizedBox(width: 12),
            Expanded(
              child: Text(tr(people
                  ? 'Aucun employé n\'est encore lié à un responsable. Dans « Équipe & accès », ouvrez un employé et choisissez son « Responsable direct » : l\'organigramme se construit tout seul.'
                  : 'Aucune fonction n\'est encore rattachée à une autre. Ouvrez une fonction et choisissez « Rattachée à » pour construire l\'organigramme.')),
            ),
          ]),
        ),
      if (singles.isNotEmpty) ...[
        const SizedBox(height: 22),
        Text(tr(people ? 'Sans responsable ni équipe (${singles.length})' : (trees.isEmpty ? 'Toutes les fonctions (${singles.length})' : 'Fonctions non rattachées (${singles.length})')),
            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
        const SizedBox(height: 12),
        Wrap(spacing: 14, runSpacing: 14, children: [for (final n in singles) _node(n, people)]),
      ],
    ]);
  }

  /// Fonctions affichées dans l'organigramme : occupées, ou au-dessus d'une fonction affichée ; filtre module/recherche appliqué.
  bool _inChart(Map<String, dynamic> r) {
    final kids = _children(r['id']).where(_inChart);
    return kids.isNotEmpty || (((r['users_count'] ?? 0) > 0 || _module != null || _q.isNotEmpty) && _matches(r));
  }

  Widget _subtree(OrgNode n, bool people) {
    final line = Theme.of(context).dividerColor;
    final node = _node(n, people);
    if (n.children.isEmpty) return node;
    return Column(mainAxisSize: MainAxisSize.min, children: [
      node,
      Container(width: 2, height: 18, color: line),
      Row(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        for (final (i, k) in n.children.indexed)
          IntrinsicWidth(
            child: Column(children: [
              Row(children: [
                Expanded(child: Container(height: 2, color: i == 0 ? Colors.transparent : line)),
                Container(width: 2, height: 2, color: n.children.length > 1 ? line : Colors.transparent),
                Expanded(child: Container(height: 2, color: i == n.children.length - 1 ? Colors.transparent : line)),
              ]),
              Container(width: 2, height: 16, color: line),
              Padding(padding: const EdgeInsets.symmetric(horizontal: 10), child: _subtree(k, people)),
            ]),
          ),
      ]),
    ]);
  }

  Widget _node(OrgNode n, bool people) {
    final theme = Theme.of(context);
    final color = Color(n.color);
    return Container(
      width: people ? 210 : 230,
      decoration: BoxDecoration(
        color: theme.cardColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: theme.dividerColor.withValues(alpha: .7)),
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .05), blurRadius: 10, offset: const Offset(0, 3))],
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Container(height: 5, color: color),
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
          child: people
              ? Row(children: [
                  InitialsAvatar(n.title, size: 36),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr(n.title), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800)),
                      if ((n.subtitle ?? '').isNotEmpty) Text(tr(n.subtitle!), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
                      if (n.children.isNotEmpty) Text(tr('${n.children.length} directement rattaché(s)'), style: TextStyle(color: theme.hintColor, fontSize: 11.5)),
                    ]),
                  ),
                ])
              : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr(n.title), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: color)),
                  const SizedBox(height: 8),
                  if (n.lines.isEmpty)
                    Text(tr('Poste non pourvu'), style: TextStyle(color: theme.hintColor, fontSize: 12.5, fontStyle: FontStyle.italic))
                  else
                    for (final l in n.lines.take(4))
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Row(children: [
                          InitialsAvatar(l.split(' — ').first, size: 26),
                          const SizedBox(width: 8),
                          Expanded(child: Text(tr(l), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12.5))),
                        ]),
                      ),
                  if (n.lines.length > 4) Text(tr('+ ${n.lines.length - 4} autre(s)'), style: TextStyle(color: theme.hintColor, fontSize: 12)),
                ]),
        ),
      ]),
    );
  }
}


/// Création / modification d'une fonction : nom, rattachement, couleur, droits par module.
class _FunctionEditor extends StatefulWidget {
  const _FunctionEditor({this.role, this.copyFrom, required this.roles, required this.perms});
  final Map<String, dynamic>? role;
  final Map<String, dynamic>? copyFrom;
  final List<Map<String, dynamic>> roles;
  final Map<String, dynamic> perms;

  @override
  State<_FunctionEditor> createState() => _FunctionEditorState();
}

class _FunctionEditorState extends State<_FunctionEditor> {
  late final _name = TextEditingController(text: widget.role?['name'] ?? (widget.copyFrom?['name'] != null && '${widget.copyFrom!['name']}'.isNotEmpty ? '${widget.copyFrom!['name']} (copie)' : ''));
  late final _desc = TextEditingController(text: '${widget.role?['description'] ?? ''}');
  int? _parent;
  String? _color;
  final Set<String> _granted = {};
  bool _loading = true;
  bool _busy = false;
  String _q = '';
  String _f = ''; // '' tous, 'on' avec accès, 'off' sans accès

  static const _palette = ['#1F6FEB', '#0E9F6E', '#F59E0B', '#DC2626', '#7C3AED', '#0891B2', '#DB2777', '#334155'];

  Map<String, dynamic> get _defs => asMap(widget.perms['data']);
  Map<String, dynamic> get _moduleNames => asMap(widget.perms['modules']);

  @override
  void initState() {
    super.initState();
    _parent = (widget.role ?? widget.copyFrom)?['parent_id'] as int?;
    _color = (widget.role ?? widget.copyFrom)?['color'] as String?;
    _loadPerms();
  }

  /// Droits actuels de la fonction (ou de celle qu'on duplique), dépliés en actions connues.
  Future<void> _loadPerms() async {
    final src = widget.role ?? (widget.copyFrom?['id'] != null ? widget.copyFrom : null);
    if (src != null) {
      try {
        final all = (await context.read<Session>().api.get('roles'))['data'] as List;
        final r = asMap(all.firstWhere((x) => asMap(x)['id'] == src['id'], orElse: () => {}));
        final raw = [for (final p in (r['permissions'] as List? ?? const [])) '$p'];
        for (final k in _defs.keys) {
          if (raw.any((g) => g == k || _match(g, k))) _granted.add(k);
        }
      } catch (_) {}
    }
    if (mounted) setState(() => _loading = false);
  }

  bool _match(String pattern, String key) {
    if (!pattern.contains('*')) return false;
    final re = RegExp('^${RegExp.escape(pattern).replaceAll(r'\*', '.*')}\$');
    return re.hasMatch(key) && key != 'rh.contracts';
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return toast(context, tr('Donnez un nom à la fonction.'), error: true);
    setState(() => _busy = true);
    final api = context.read<Session>().api;
    final body = {'name': _name.text.trim(), 'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(), 'parent_id': _parent, 'color': _color, 'permissions': _granted.toList()..sort()};
    try {
      widget.role == null ? await api.post('roles', body) : await api.put('roles/${widget.role!['id']}', body);
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Fonctions possibles au-dessus : toutes sauf elle-même et ses subordonnées.
  List<Map<String, dynamic>> get _parents {
    final me = widget.role?['id'];
    if (me == null) return widget.roles;
    final below = <Object?>{me};
    var grew = true;
    while (grew) {
      grew = false;
      for (final r in widget.roles) {
        if (below.contains(r['parent_id']) && below.add(r['id'])) grew = true;
      }
    }
    return widget.roles.where((r) => !below.contains(r['id'])).toList();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final wide = MediaQuery.sizeOf(context).width >= 860;
    final byModule = <String, List<MapEntry<String, dynamic>>>{};
    for (final e in _defs.entries) {
      byModule.putIfAbsent('${asMap(e.value)['module']}', () => []).add(e);
    }
    final q = _q.toLowerCase();
    bool has(String m) => byModule[m]!.any((p) => _granted.contains(p.key));
    final modules = byModule.keys
        .where((m) => q.isEmpty || '${_moduleNames[m] ?? m}'.toLowerCase().contains(q))
        .where((m) => _f == '' || (_f == 'on' ? has(m) : !has(m)))
        .toList()
      ..sort((a, b) => a == 'core' ? -1 : (b == 'core' ? 1 : '${_moduleNames[a] ?? a}'.compareTo('${_moduleNames[b] ?? b}')));

    final left = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom de la fonction'), hintText: tr('Ex. Directeur commercial, Chef d\'équipe'), prefixIcon: const Icon(Icons.work_outline))),
      const SizedBox(height: 12),
      DropdownButtonFormField<int?>(
        initialValue: _parents.any((r) => r['id'] == _parent) ? _parent : null,
        isExpanded: true,
        decoration: InputDecoration(labelText: tr('Rattachée à (supérieure)'), prefixIcon: const Icon(Icons.account_tree_outlined)),
        items: [
          DropdownMenuItem<int?>(value: null, child: Text(tr('Aucune (sommet de l\'entreprise)'), overflow: TextOverflow.ellipsis)),
          for (final r in _parents) DropdownMenuItem<int?>(value: r['id'] as int, child: Text(tr('${r['name']}'), overflow: TextOverflow.ellipsis)),
        ],
        onChanged: (v) => setState(() => _parent = v),
      ),
      const SizedBox(height: 12),
      TextField(controller: _desc, maxLines: 3, decoration: InputDecoration(labelText: tr('Description (facultatif)'), hintText: tr('Missions, périmètre…'))),
      const SizedBox(height: 14),
      Text(tr('Couleur dans l\'organigramme'), style: TextStyle(color: theme.hintColor, fontSize: 12.5, fontWeight: FontWeight.w600)),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final c in _palette)
          InkWell(
            borderRadius: BorderRadius.circular(20),
            onTap: () => setState(() => _color = c),
            child: Container(
              width: 30,
              height: 30,
              decoration: BoxDecoration(
                color: Color(int.parse('FF${c.substring(1)}', radix: 16)),
                shape: BoxShape.circle,
                border: Border.all(color: _color == c ? theme.colorScheme.onSurface : Colors.transparent, width: 2.5),
              ),
              child: _color == c ? const Icon(Icons.check, color: Colors.white, size: 16) : null,
            ),
          ),
      ]),
      const SizedBox(height: 16),
      Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: theme.colorScheme.surfaceContainer, borderRadius: BorderRadius.circular(12)),
        child: Text(
          tr('${_granted.length} action(s) autorisée(s) dans ${byModule.entries.where((e) => e.value.any((p) => _granted.contains(p.key))).length} module(s). '
              'Vous ne pouvez donner que des droits que vous avez vous-même.'),
          style: TextStyle(fontSize: 12.5, color: theme.hintColor, height: 1.35),
        ),
      ),
    ]);

    final right = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        const Icon(Icons.apps_rounded, size: 18, color: YColors.blue),
        const SizedBox(width: 8),
        Expanded(child: Text(tr('Ce que cette fonction peut faire'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
      ]),
      const SizedBox(height: 10),
      SearchField(hint: tr('Rechercher un module…'), onChanged: (v) => setState(() => _q = v)),
      const SizedBox(height: 10),
      Wrap(spacing: 6, runSpacing: 6, children: [
        for (final (k, l) in [('', 'Tous les modules (${byModule.length})'), ('on', 'Avec accès (${byModule.keys.where(has).length})'), ('off', 'Sans accès (${byModule.keys.where((m) => !has(m)).length})')])
          ChoiceChip(label: Text(tr(l)), selected: _f == k, showCheckmark: false, onSelected: (_) => setState(() => _f = k)),
      ]),
      const SizedBox(height: 10),
      if (modules.isEmpty) Padding(padding: const EdgeInsets.all(20), child: Center(child: Text(tr('Aucun module ne correspond.'), style: TextStyle(color: theme.hintColor)))),
      for (final m in modules) _moduleCard(theme, m, byModule[m]!),
    ]);

    return Material(
      child: Column(children: [
        Container(
          padding: const EdgeInsets.fromLTRB(24, 18, 12, 18),
          decoration: BoxDecoration(border: Border(bottom: BorderSide(color: theme.dividerColor.withValues(alpha: .5)))),
          child: Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
              child: const Icon(Icons.work_outline, color: YColors.blue),
            ),
            const SizedBox(width: 14),
            Expanded(child: Text(tr(widget.role == null ? 'Nouvelle fonction' : 'Fonction : ${widget.role!['name']}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
            IconButton(onPressed: () => Navigator.pop(context, false), icon: const Icon(Icons.close)),
          ]),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : wide
                  ? Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      SizedBox(width: 360, child: SingleChildScrollView(padding: const EdgeInsets.all(20), child: left)),
                      VerticalDivider(width: 1, color: theme.dividerColor.withValues(alpha: .5)),
                      Expanded(child: SingleChildScrollView(padding: const EdgeInsets.all(20), child: right)),
                    ])
                  : ListView(padding: const EdgeInsets.all(16), children: [left, const SizedBox(height: 20), right]),
        ),
        Container(
          padding: const EdgeInsets.fromLTRB(24, 12, 24, 14),
          decoration: BoxDecoration(color: theme.colorScheme.surfaceContainerLow, border: Border(top: BorderSide(color: theme.dividerColor.withValues(alpha: .5)))),
          child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler'))),
            const SizedBox(width: 8),
            FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.check, size: 18), label: Text(tr(widget.role == null ? 'Créer la fonction' : 'Enregistrer'))),
          ]),
        ),
      ]),
    );
  }

  Widget _moduleCard(ThemeData theme, String module, List<MapEntry<String, dynamic>> perms) {
    final on = perms.where((p) => _granted.contains(p.key)).length;
    final all = on == perms.length;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      clipBehavior: Clip.antiAlias,
      child: ExpansionTile(
        shape: const Border(),
        collapsedShape: const Border(),
        leading: Checkbox(
          tristate: true,
          value: on == 0 ? false : (all ? true : null),
          onChanged: (_) => setState(() => all ? _granted.removeAll(perms.map((p) => p.key)) : _granted.addAll(perms.map((p) => p.key).where((k) => k != 'rh.contracts'))),
        ),
        title: Text(tr('${_moduleNames[module] ?? module}'), style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(tr(on == 0 ? 'Aucun accès' : (all ? 'Accès complet' : '$on / ${perms.length} action(s)')), style: TextStyle(fontSize: 12.5, color: on == 0 ? theme.hintColor : YColors.blue)),
        children: [
          for (final p in perms)
            CheckboxListTile(
              dense: true,
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: const EdgeInsets.only(left: 28, right: 16),
              value: _granted.contains(p.key),
              onChanged: (v) => setState(() => v == true ? _granted.add(p.key) : _granted.remove(p.key)),
              title: Text(tr('${asMap(p.value)['label']}')),
            ),
        ],
      ),
    );
  }
}
