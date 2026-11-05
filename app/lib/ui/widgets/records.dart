import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/records_offline.dart';
import '../../state/session.dart';
import '../theme.dart';
import 'add_to_report.dart';
import 'common.dart';
import 'filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Fiches déclaratives : le serveur décrit les champs (`{module}/schema`), l'application affiche
/// la liste filtrable et le formulaire. Utilisé par les espaces de service (marketing, juridique, parc auto).

class RecordSchema {
  RecordSchema(this.module, Map<String, dynamic> d)
      : resources = asMap(d['resources']),
        users = [for (final u in (d['users'] as List? ?? const [])) asMap(u)],
        me = d['me'],
        currency = '${d['currency'] ?? 'XOF'}';

  final String module;
  final Map<String, dynamic> resources;
  final List<Map<String, dynamic>> users;
  final dynamic me;
  final String currency;

  static final Map<String, RecordSchema> _cache = {};

  static Future<RecordSchema> load(BuildContext context, String module, {bool refresh = false}) async {
    if (!refresh && _cache[module] != null) return _cache[module]!;
    final r = await RecordsOffline.get(context.read<Session>().api, '$module/schema');
    return _cache[module] = RecordSchema(module, asMap((r as Map)['data']));
  }

  static void clear() => _cache.clear();

  Map<String, dynamic>? res(String key) => resources[key] == null ? null : asMap(resources[key]);

  List<Map<String, dynamic>> fields(String key) => [for (final f in (res(key)?['fields'] as List? ?? const [])) asMap(f)];

  Map<String, dynamic>? field(String res, String key) {
    for (final f in fields(res)) {
      if (f['key'] == key) return f;
    }
    return null;
  }

  String userName(dynamic id) => users.firstWhere((u) => '${u['id']}' == '$id', orElse: () => const {'name': ''})['name'] as String;
}

List<Map<String, dynamic>> recordOptions(Map f) => [for (final o in (f['options'] as List? ?? const [])) asMap(o)];

Color? _hex(String? h) => h == null || h.length < 7 ? null : Color(int.parse('FF${h.substring(1)}', radix: 16));

Map<String, dynamic>? recordOption(Map f, dynamic v) {
  for (final o in recordOptions(f)) {
    if (o['value'] == '$v') return o;
  }
  return null;
}

/// Texte affiché pour une valeur de champ.
String recordText(Map f, Map row, {String currency = 'XOF'}) {
  final v = row[f['key']];
  if (v == null || v == '') return '';
  // saisies des utilisateurs : affichées telles quelles, jamais traduites
  if (f['computed'] != true && const ['text', 'textarea', 'url', 'email', 'phone', 'ref', 'partner', 'employee', 'user'].contains(f['type'])) {
    final raw = const ['ref', 'partner', 'employee', 'user'].contains(f['type']) ? '${row['${f['key']}_label'] ?? ''}' : '$v';
    return raw.isEmpty ? '' : verbatim(raw);
  }
  switch (f['type']) {
    case 'select':
      return '${recordOption(f, v)?['label'] ?? v}';
    case 'tags':
      return [for (final t in (v as List)) '${recordOption(f, t)?['label'] ?? t}'].join(', ');
    case 'money':
      return money(toDouble(v), currency);
    case 'number':
      return number(toDouble(v), decimals: toDouble(v) % 1 == 0 ? 0 : 1);
    case 'date':
      return dateFr(v);
    case 'datetime':
      return dateTimeFr(v);
    case 'bool':
      return v == true ? 'Oui' : 'Non';
    case 'user':
    case 'ref':
    case 'partner':
    case 'employee':
      return '${row['${f['key']}_label'] ?? ''}';
    default:
      return '$v';
  }
}

/// Cellule de liste : pastille colorée pour les statuts, puces pour les réseaux, texte sinon.
Widget recordCell(Map f, Map row, {String currency = 'XOF', bool strong = false}) {
  final v = row[f['key']];
  if (f['type'] == 'select' && v != null) {
    final o = recordOption(f, v);
    final c = _hex(o?['color'] as String?);
    if (c != null) return StatusChip('${o?['label'] ?? v}', color: c);
  }
  if (f['type'] == 'tags' && v is List && v.isNotEmpty) {
    return Wrap(spacing: 4, runSpacing: 4, children: [
      for (final t in v.take(4))
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
          decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .08), borderRadius: BorderRadius.circular(6)),
          child: Text(tr('${recordOption(f, t)?['label'] ?? t}'), style: const TextStyle(fontSize: 11.5, color: YColors.blue, fontWeight: FontWeight.w600)),
        ),
    ]);
  }
  if (f['type'] == 'bool') return Icon(v == true ? Icons.check_circle : Icons.radio_button_unchecked, size: 18, color: v == true ? YColors.success : YColors.muted);
  if (f['key'] == 'alert' || f['key'] == 'alerts') {
    final t = recordText(f, row, currency: currency);
    return t.isEmpty ? const SizedBox() : StatusChip(t, color: YColors.danger, icon: Icons.warning_amber_rounded);
  }
  final t = recordText(f, row, currency: currency);
  return Text(tr(t.isEmpty ? '—' : t), overflow: TextOverflow.ellipsis, maxLines: 2, style: strong ? const TextStyle(fontWeight: FontWeight.w600) : null);
}

// =====================================================================  liste

/// Liste d'une fiche : recherche, filtres, tableau ou cartes, création et édition.
class RecordsView extends StatefulWidget {
  const RecordsView({
    super.key,
    required this.module,
    required this.resource,
    this.columns,
    this.filters = const {},
    this.actions,
    this.toolbar = const [],
    this.emptyIcon = Icons.inbox_outlined,
    this.emptyMessage,
    this.newLabel,
    this.onChanged,
    this.onOpen,
  });

  final String module;
  final String resource;

  /// Colonnes affichées (clés de champs) ; par défaut les champs marqués `list`.
  final List<String>? columns;
  final Map<String, String> filters;

  /// Boutons supplémentaires du formulaire d'une fiche existante (circuit, PDF…).
  final List<Widget> Function(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload)? actions;
  final List<Widget> toolbar;
  final IconData emptyIcon;
  final String? emptyMessage;
  final String? newLabel;
  final VoidCallback? onChanged;
  final void Function(Map<String, dynamic> row, VoidCallback reload)? onOpen;

  @override
  State<RecordsView> createState() => RecordsViewState();
}

class RecordsViewState extends State<RecordsView> {
  RecordSchema? _schema;
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _q = '';
  final Map<String, String> _filters = {};
  Timer? _debounce;
  List<Map<String, dynamic>> _failed = const [];
  bool _offline = false;

  @override
  void initState() {
    super.initState();
    _filters.addAll(widget.filters);
    RecordsOffline.changes.addListener(_onOffline);
    reload();
  }

  void _onOffline() {
    if (mounted) reload();
  }

  @override
  void dispose() {
    RecordsOffline.changes.removeListener(_onOffline);
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> reload() async {
    try {
      final s = await RecordSchema.load(context, widget.module);
      if (!mounted) return;
      final path = '${widget.module}/records/${widget.resource}';
      final r = await RecordsOffline.get(context.read<Session>().api, path, query: {if (_q.isNotEmpty) 'q': _q, ..._filters});
      final rows = [for (final x in ((r as Map)['data'] as List)) asMap(x)];
      // Saisies faites hors ligne, pas encore envoyées
      final pend = await RecordsOffline.pending(path);
      for (final e in pend) {
        final body = asMap(e['body']);
        if (e['method'] == 'POST' && e['path'] == path) {
          rows.insert(0, {...body, 'id': e['id'], '_pending': true});
        } else if (e['method'] == 'PUT') {
          final id = '${e['path']}'.split('/').last;
          final i = rows.indexWhere((x) => '${x['id']}' == id);
          if (i >= 0) rows[i] = {...rows[i], ...body, '_pending': true};
        } else if (e['method'] == 'DELETE') {
          rows.removeWhere((x) => '${x['id']}' == '${e['path']}'.split('/').last);
        }
      }
      final failed = (await RecordsOffline.failures()).where((f) => '${f['path']}'.startsWith(path)).toList();
      if (!mounted) return;
      setState(() {
        _schema = s;
        _rows = rows;
        _failed = failed;
        _offline = r['offline'] == true || pend.isNotEmpty;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _changed() {
    reload();
    widget.onChanged?.call();
  }

  Future<void> open([Map<String, dynamic>? row, Map<String, dynamic>? initial]) async {
    final s = _schema;
    if (s == null) return;
    if (row != null && widget.onOpen != null) return widget.onOpen!(row, _changed);
    // Nouvelle fiche depuis une liste filtrée (pièces d'un dossier, décomptes d'un marché…) : le lien est prérempli
    if (row == null && initial == null) {
      const linkTypes = {'ref', 'partner', 'employee', 'user'};
      initial = {
        for (final f in s.fields(widget.resource))
          if (linkTypes.contains(f['type']) && (widget.filters[f['key']] ?? '').isNotEmpty && !widget.filters[f['key']]!.contains(','))
            '${f['key']}': f['type'] == 'user' ? int.tryParse(widget.filters[f['key']]!) : widget.filters[f['key']],
      };
    }
    final saved = await editRecord(context, s, widget.resource, row: row, initial: initial, actions: widget.actions, onReload: _changed);
    if (saved != null) _changed();
  }

  @override
  Widget build(BuildContext context) {
    final s = _schema;
    if (_error != null && _rows == null) return OnlineOnlyNotice(error: _error!, onRetry: reload);
    if (s == null || _rows == null) return const Center(child: CircularProgressIndicator());
    final res = s.res(widget.resource);
    if (res == null) return EmptyState(icon: Icons.lock_outline, title: tr('Accès non autorisé'));
    final fields = s.fields(widget.resource);
    final cols = widget.columns != null ? [for (final k in widget.columns!) ...fields.where((f) => f['key'] == k)] : fields.where((f) => f['list'] == true).toList();
    final filterFields = fields.where((f) => f['filter'] == true && (f['type'] == 'select' || f['type'] == 'user' || f['type'] == 'bool' || f['type'] == 'tags' || f['type'] == 'ref')).toList();
    final titleKey = '${res['title_field']}';
    final hint = Theme.of(context).hintColor;
    final rows = _rows!;
    return Column(children: [
      Padding(
        padding: const EdgeInsets.only(top: 14),
        child: FilterBar(
          padding: EdgeInsets.fromLTRB(MediaQuery.of(context).size.width < 700 ? 16 : 24, 0, 24, 10),
          onSearch: (v) {
            _q = v.trim();
            _debounce?.cancel();
            _debounce = Timer(const Duration(milliseconds: 350), reload);
          },
          search: _q,
          filters: [
            for (final f in filterFields.take(6))
              FilterSpec('${f['key']}', '${f['label']}', {for (final o in _filterOptions(s, f)) o.$1: o.$2}, icon: f['type'] == 'user' ? Icons.person_outline_rounded : (f['type'] == 'bool' ? Icons.toggle_on_outlined : Icons.filter_list_rounded)),
          ],
          values: {for (final f in filterFields.take(6)) '${f['key']}': _filters['${f['key']}']},
          onFilter: (k, v) {
            setState(() => v == null || v.isEmpty ? _filters.remove(k) : _filters[k] = v);
            reload();
          },
          count: rows.length,
          countLabel: 'fiche',
          onReset: () {
            setState(() {
              _filters
                ..clear()
                ..addAll(widget.filters);
            });
            reload();
          },
          trailing: [
            ...widget.toolbar,
            if (res['can_manage'] == true) FilledButton.icon(onPressed: () => open(), icon: const Icon(Icons.add, size: 18), label: Text(tr(widget.newLabel ?? 'Nouveau'))),
          ],
        ),
      ),
      if (_failed.isNotEmpty)
        Padding(
          padding: EdgeInsets.fromLTRB(MediaQuery.of(context).size.width < 700 ? 16 : 24, 0, 24, 8),
          child: Card(
            color: YColors.danger.withValues(alpha: .07),
            child: ListTile(
              dense: true,
              leading: const Icon(Icons.sync_problem, color: YColors.danger),
              title: Text(tr('${_failed.length} saisie(s) faite(s) hors ligne refusée(s) par le serveur')),
              subtitle: Text(tr(_failed.map((f) => '${f['label'] ?? f['path']} : ${f['error']}').join('\n'))),
              trailing: TextButton(onPressed: () async {
                await RecordsOffline.clearFailures();
              }, child: Text(tr('J\'ai vu'))),
            ),
          ),
        ),
      if (_offline && rows.any((r) => r['_pending'] == true))
        Padding(
          padding: EdgeInsets.fromLTRB(MediaQuery.of(context).size.width < 700 ? 16 : 24, 0, 24, 8),
          child: Row(children: [
            const Icon(Icons.cloud_upload_outlined, size: 16, color: Color(0xFFD97706)),
            const SizedBox(width: 6),
            Expanded(child: Text(tr('${rows.where((r) => r['_pending'] == true).length} saisie(s) hors ligne : envoyée(s) automatiquement au retour de la connexion.'),
                style: const TextStyle(color: Color(0xFFD97706), fontSize: 12.5))),
          ]),
        ),
      if (res['hint'] != null)
        Padding(
          padding: EdgeInsets.fromLTRB(MediaQuery.of(context).size.width < 700 ? 16 : 24, 0, 24, 8),
          child: Row(children: [
            Icon(Icons.info_outline, size: 15, color: hint),
            const SizedBox(width: 6),
            Expanded(child: Text(tr('${res['hint']}'), style: TextStyle(color: hint, fontSize: 12.5))),
          ]),
        ),
      Expanded(
        child: rows.isEmpty
            ? EmptyState(icon: widget.emptyIcon, title: tr('Aucune fiche « ${res['plural']} »'), message: widget.emptyMessage)
            : RefreshIndicator(
                onRefresh: reload,
                child: ResponsiveList<Map<String, dynamic>>(
                  items: rows,
                  columns: [for (final f in cols) '${f['label']}'],
                  cells: (r) => [
                    for (final f in cols)
                      ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 280),
                        child: f['key'] == titleKey && r['_pending'] == true
                            ? Row(mainAxisSize: MainAxisSize.min, children: [
                                Tooltip(message: tr('En attente d\'envoi (hors ligne)'), child: Icon(Icons.cloud_upload_outlined, size: 15, color: Color(0xFFD97706))),
                                const SizedBox(width: 5),
                                Flexible(child: recordCell(f, r, currency: s.currency, strong: true)),
                              ])
                            : f['key'] == titleKey
                                ? Row(mainAxisSize: MainAxisSize.min, children: [
                                    InitialsAvatar('${r[titleKey] ?? res['label']}', size: 30),
                                    const SizedBox(width: 10),
                                    Flexible(child: recordCell(f, r, currency: s.currency, strong: true)),
                                  ])
                                : recordCell(f, r, currency: s.currency),
                      ),
                  ],
                  mobileTile: (r) => Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    InitialsAvatar('${r[titleKey] ?? res['label']}', size: 36),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text(verbatim('${r[titleKey] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                          for (final f in cols.where((f) => f['type'] == 'select').take(1)) ...[
                            const SizedBox(width: 8),
                            // l'état ne doit jamais écraser le titre (« Évalua/tion… » sur téléphone)
                            ConstrainedBox(constraints: const BoxConstraints(maxWidth: 120), child: recordCell(f, r)),
                          ],
                        ]),
                        const SizedBox(height: 4),
                        Text(
                          tr([for (final f in cols.where((f) => f['key'] != titleKey && f['type'] != 'select')) recordText(f, r, currency: s.currency)].where((t) => t.isNotEmpty).join(' · ')),
                          style: TextStyle(color: hint, fontSize: 12.5),
                        ),
                      ]),
                    ),
                  ]),
                  onTap: (r) => open(r),
                ),
              ),
      ),
    ]);
  }

  List<(String, String)> _filterOptions(RecordSchema s, Map<String, dynamic> f) => switch (f['type']) {
    'user' => [('${s.me}', 'Moi'), for (final u in s.users) ('${u['id']}', '${u['name']}')],
    'bool' => [('1', 'Oui'), ('0', 'Non')],
    'ref' => <(String, String)>[],
    _ => [for (final o in recordOptions(f)) ('${o['value']}', '${o['label']}')],
  };
}

// =====================================================================  formulaire

/// Ouvre le formulaire d'une fiche (création si [row] est nul). Renvoie la fiche enregistrée.
Future<Map<String, dynamic>?> editRecord(
  BuildContext context,
  RecordSchema schema,
  String resource, {
  Map<String, dynamic>? row,
  Map<String, dynamic>? initial,
  List<Widget> Function(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload)? actions,
  VoidCallback? onReload,
}) {
  final res = schema.res(resource)!;
  return showFormDialog<Map<String, dynamic>>(
    context,
    maxWidth: 720,
    title: row == null ? '${res['label']} — nouveau' : verbatim('${row[res['title_field']] ?? res['label']}'),
    builder: (c) => RecordForm(schema: schema, resource: resource, row: row, initial: initial, actions: actions, onReload: onReload),
  );
}

class RecordForm extends StatefulWidget {
  const RecordForm({super.key, required this.schema, required this.resource, this.row, this.initial, this.actions, this.onReload});

  final RecordSchema schema;
  final String resource;
  final Map<String, dynamic>? row;
  final Map<String, dynamic>? initial;
  final List<Widget> Function(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload)? actions;
  final VoidCallback? onReload;

  @override
  State<RecordForm> createState() => _RecordFormState();
}

class _RecordFormState extends State<RecordForm> {
  late final Map<String, dynamic> _v;
  final Map<String, TextEditingController> _ctl = {};
  bool _busy = false;

  Map<String, dynamic> get _res => widget.schema.res(widget.resource)!;
  bool get _editable => _res['can_manage'] == true;

  @override
  void initState() {
    super.initState();
    _v = {...?widget.row};
    if (widget.row == null) {
      for (final f in widget.schema.fields(widget.resource)) {
        if (f.containsKey('default') && f['default'] != null) {
          _v[f['key']] = switch (f['default']) {
            '@me' => widget.schema.me,
            '@today' => isoDate(DateTime.now()),
            '@now' => DateTime.now().toIso8601String(),
            _ => f['default'],
          };
        }
      }
    }
    _v.addAll(widget.initial ?? const {});
  }

  @override
  void dispose() {
    for (final c in _ctl.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _c(String k, {bool numeric = false}) => _ctl.putIfAbsent(k, () {
        final v = _v[k];
        return TextEditingController(text: v == null ? '' : (numeric && v is num && v % 1 == 0 ? '${v.toInt()}' : '$v'));
      });

  bool _visible(Map f) {
    final cond = f['show_if'];
    if (cond is! Map) return true;
    for (final e in cond.entries) {
      final allowed = [for (final x in (e.value as List)) '$x'];
      if (!allowed.contains('${_v[e.key]}')) return false;
    }
    return true;
  }

  Future<void> _save() async {
    final body = <String, dynamic>{};
    for (final f in widget.schema.fields(widget.resource)) {
      if (f['computed'] == true || f['readonly'] == true) continue;
      final k = '${f['key']}';
      dynamic v = _v[k];
      if (_ctl.containsKey(k)) {
        final t = _ctl[k]!.text.trim();
        v = (f['type'] == 'number' || f['type'] == 'money') ? (t.isEmpty ? null : toDouble(t)) : (t.isEmpty ? null : t);
      }
      if (f['required'] == true && (v == null || v == '')) {
        toast(context, tr('« ${f['label']} » est obligatoire.'), error: true);
        return;
      }
      if (widget.row == null && v == null) continue;
      body[k] = v;
    }
    setState(() => _busy = true);
    final api = context.read<Session>().api;
    try {
      final path = '${widget.schema.module}/records/${widget.resource}';
      final label = '${_res['label']} « ${body[_res['title_field']] ?? widget.row?[_res['title_field']] ?? ''} »';
      final r = widget.row == null ? await RecordsOffline.send(api, 'POST', path, body, label) : await RecordsOffline.send(api, 'PUT', '$path/${widget.row!['id']}', body, label);
      if (mounted) {
        if ((r as Map)['offline'] == true) toast(context, tr('Pas de connexion : enregistré sur cet appareil, envoyé automatiquement au retour du réseau.'));
        Navigator.pop(context, asMap(r['data']));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    if (!await confirm(context, tr('Supprimer'), tr('Supprimer définitivement cette fiche ?'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    try {
      await RecordsOffline.send(context.read<Session>().api, 'DELETE', '${widget.schema.module}/records/${widget.resource}/${widget.row!['id']}', null, '${_res['label']} supprimé(e)');
      if (mounted) Navigator.pop(context, <String, dynamic>{'deleted': true});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final fields = widget.schema.fields(widget.resource).where((f) {
      if (!_visible(f)) return false;
      if (f['computed'] == true || f['readonly'] == true) return widget.row != null && recordText(f, _v).isNotEmpty;
      return true;
    }).toList();
    final pendingRow = '${widget.row?['id'] ?? ''}'.startsWith('local-');
    final extra = widget.row != null && widget.actions != null && !pendingRow ? widget.actions!(context, widget.row!, () => widget.onReload?.call()) : const <Widget>[];
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Flexible(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 4, 24, 8),
          child: LayoutBuilder(builder: (context, c) {
            final half = c.maxWidth >= 520 ? (c.maxWidth - 14) / 2 : c.maxWidth;
            return Wrap(spacing: 14, children: [
              for (final f in fields) SizedBox(width: f['half'] == true && f['type'] != 'textarea' ? half : c.maxWidth, child: _field(f)),
            ]);
          }),
        ),
      ),
      const Divider(height: 1),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 14),
        child: Wrap(alignment: WrapAlignment.end, crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 8, children: [
          if (widget.row != null && _res['can_delete'] == true)
            TextButton.icon(onPressed: _delete, icon: const Icon(Icons.delete_outline, size: 18), label: Text(tr('Supprimer')), style: TextButton.styleFrom(foregroundColor: YColors.danger)),
          if (widget.row != null && !pendingRow)
            AddToReportButton(title: _reportTitle(), module: widget.schema.module, type: widget.resource, id: '${widget.row!['id']}'),
          ...extra,
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr(_editable ? 'Annuler' : 'Fermer'))),
          if (_editable) FilledButton(onPressed: _busy ? null : _save, child: Text(tr(widget.row == null ? 'Créer' : 'Enregistrer'))),
        ]),
      ),
    ]);
  }

  String _reportTitle() {
    final r = widget.row!;
    final label = '${_res['label']}';
    String? partner;
    for (final k in ['partner_id_label', 'competitor_id_label', 'vehicle_id_label', 'case_id_label']) {
      if (r[k] != null) partner = '${r[k]}';
    }
    return '$label : ${r[_res['title_field']] ?? ''}${partner != null ? ' — $partner' : ''}';
  }

  InputDecoration _dec(Map f) => InputDecoration(labelText: trn('${tr('${f['label']}')}${f['required'] == true ? ' *' : ''}'), helperText: trn(f['hint'] as String?), helperMaxLines: 2);

  Widget _field(Map<String, dynamic> f) {
    final k = '${f['key']}';
    final ro = !_editable || f['computed'] == true || f['readonly'] == true;
    Widget w;
    if (ro) {
      w = InputDecorator(decoration: _dec(f).copyWith(helperText: null), child: Text(tr(recordText(f, _v, currency: widget.schema.currency).isEmpty ? '—' : recordText(f, _v, currency: widget.schema.currency))));
      return Padding(padding: const EdgeInsets.only(bottom: 14), child: w);
    }
    switch (f['type']) {
      case 'textarea':
        w = TextField(controller: _c(k), minLines: 3, maxLines: 14, decoration: _dec(f).copyWith(alignLabelWithHint: true));
      case 'number':
      case 'money':
        w = TextField(controller: _c(k, numeric: true), keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: _dec(f).copyWith(suffixText: trn(f['type'] == 'money' ? widget.schema.currency : null)));
      case 'date':
      case 'datetime':
        w = _DateInput(label: _dec(f), value: _v[k] as String?, withTime: f['type'] == 'datetime', onChanged: (v) => setState(() => _v[k] = v));
      case 'bool':
        w = SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('${f['label']}')), value: _v[k] == true, onChanged: (v) => setState(() => _v[k] = v));
      case 'select':
        w = DropdownButtonFormField<String>(
          initialValue: recordOption(f, _v[k]) == null ? null : '${_v[k]}',
          isExpanded: true,
          decoration: _dec(f),
          items: [
            if (f['required'] != true) const DropdownMenuItem(value: '', child: Text('—')),
            for (final o in recordOptions(f)) DropdownMenuItem(value: '${o['value']}', child: Text(tr('${o['label']}'), overflow: TextOverflow.ellipsis)),
          ],
          onChanged: (v) => setState(() => _v[k] = (v == null || v.isEmpty) ? null : v),
        );
      case 'tags':
        final sel = [for (final x in (_v[k] as List? ?? const [])) '$x'];
        w = InputDecorator(
          decoration: _dec(f),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final o in recordOptions(f))
              FilterChip(
                label: Text(tr('${o['label']}')),
                selected: sel.contains(o['value']),
                visualDensity: VisualDensity.compact,
                onSelected: (on) => setState(() => _v[k] = on ? [...sel, o['value']] : sel.where((x) => x != o['value']).toList()),
              ),
          ]),
        );
      case 'user':
        final ids = widget.schema.users.map((u) => '${u['id']}').toList();
        w = DropdownButtonFormField<String>(
          initialValue: ids.contains('${_v[k]}') ? '${_v[k]}' : null,
          isExpanded: true,
          decoration: _dec(f),
          items: [
            const DropdownMenuItem(value: '', child: Text('—')),
            for (final u in widget.schema.users) DropdownMenuItem(value: '${u['id']}', child: Text(tr('${u['name']}'), overflow: TextOverflow.ellipsis)),
          ],
          onChanged: (v) => setState(() => _v[k] = (v == null || v.isEmpty) ? null : int.tryParse(v)),
        );
      case 'ref':
      case 'partner':
      case 'employee':
        w = _LookupInput(
          module: widget.schema.module,
          lookup: f['type'] == 'ref' ? '${f['resource']}' : '${f['type']}',
          decoration: _dec(f),
          value: _v[k] == null ? null : '${_v[k]}',
          label: _v['${k}_label'] as String?,
          onChanged: (id, label) => setState(() {
            _v[k] = id == null ? null : (f['type'] == 'ref' ? int.tryParse(id) : id);
            _v['${k}_label'] = label;
          }),
        );
      default:
        w = TextField(controller: _c(k), decoration: _dec(f), keyboardType: f['type'] == 'url' ? TextInputType.url : (f['type'] == 'email' ? TextInputType.emailAddress : null));
    }
    return Padding(padding: const EdgeInsets.only(bottom: 14), child: w);
  }
}

class _DateInput extends StatelessWidget {
  const _DateInput({required this.label, required this.value, required this.withTime, required this.onChanged});

  final InputDecoration label;
  final String? value;
  final bool withTime;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context) {
    final d = DateTime.tryParse(value ?? '');
    return InkWell(
      borderRadius: BorderRadius.circular(10),
      onTap: () async {
        final day = await showDatePicker(context: context, initialDate: d ?? DateTime.now(), firstDate: DateTime(2000), lastDate: DateTime(2100), locale: Locale(I18n.lang));
        if (day == null || !context.mounted) return;
        if (!withTime) return onChanged(isoDate(day));
        final t = await showTimePicker(context: context, initialTime: d == null ? const TimeOfDay(hour: 9, minute: 0) : TimeOfDay.fromDateTime(d));
        final dt = DateTime(day.year, day.month, day.day, t?.hour ?? 9, t?.minute ?? 0);
        onChanged(DateFormat('yyyy-MM-dd HH:mm:ss').format(dt));
      },
      child: InputDecorator(
        decoration: label.copyWith(
          suffixIcon: value == null ? const Icon(Icons.event, size: 18) : IconButton(icon: const Icon(Icons.close, size: 18), onPressed: () => onChanged(null)),
        ),
        child: Text(tr(d == null ? '—' : (withTime ? DateFormat('EEE d MMM yyyy · HH:mm', I18n.dateLocale).format(d) : DateFormat('EEE d MMM yyyy', I18n.dateLocale).format(d)))),
      ),
    );
  }
}

/// Choix d'une fiche liée (fiche du module, client/fournisseur, salarié) avec recherche.
class _LookupInput extends StatelessWidget {
  const _LookupInput({required this.module, required this.lookup, required this.decoration, required this.value, required this.label, required this.onChanged});

  final String module;
  final String lookup;
  final InputDecoration decoration;
  final String? value;
  final String? label;
  final void Function(String? id, String? label) onChanged;

  @override
  Widget build(BuildContext context) => InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () async {
          final r = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => _LookupDialog(module: module, lookup: lookup, title: decoration.labelText ?? ''));
          if (r != null) onChanged(r['id'] as String?, r['label'] as String?);
        },
        child: InputDecorator(
          decoration: decoration.copyWith(
            suffixIcon: value == null ? const Icon(Icons.search, size: 18) : IconButton(icon: const Icon(Icons.close, size: 18), onPressed: () => onChanged(null, null)),
          ),
          child: Text(tr(value == null ? '—' : (label ?? '#$value')), overflow: TextOverflow.ellipsis),
        ),
      );
}

class _LookupDialog extends StatefulWidget {
  const _LookupDialog({required this.module, required this.lookup, required this.title});

  final String module;
  final String lookup;
  final String title;

  @override
  State<_LookupDialog> createState() => _LookupDialogState();
}

class _LookupDialogState extends State<_LookupDialog> {
  List<Map<String, dynamic>> _rows = [];
  Timer? _t;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _search('');
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  Future<void> _search(String q) async {
    try {
      final r = await RecordsOffline.get(context.read<Session>().api, '${widget.module}/lookup/${widget.lookup}', query: {'q': q});
      if (mounted) setState(() => (_rows = [for (final x in ((r as Map)['data'] as List)) asMap(x)], _loading = false));
    } catch (e) {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => YAlertDialog(
        title: Text(tr(widget.title.replaceAll(' *', ''))),
        content: SizedBox(
          width: 440,
          height: 420,
          child: Column(children: [
            SearchField(
              hint: tr('Rechercher…'),
              onChanged: (v) {
                _t?.cancel();
                _t = Timer(const Duration(milliseconds: 300), () => _search(v));
              },
            ),
            const SizedBox(height: 8),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _rows.isEmpty
                      ? Center(child: Text(tr('Aucun résultat'), style: TextStyle(color: Theme.of(context).hintColor)))
                      : ListView(children: [
                          for (final r in _rows)
                            ListTile(
                              dense: true,
                              leading: InitialsAvatar('${r['label']}', size: 30),
                              title: Text(tr('${r['label']}')),
                              subtitle: r['detail'] == null ? null : Text(tr('${r['detail']}')),
                              onTap: () => Navigator.pop(context, r),
                            ),
                        ]),
            ),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler')))],
      );
}

/// Charge le tableau de bord d'un module (`{module}/dashboard`).
class ModuleDashboard extends StatefulWidget {
  const ModuleDashboard({super.key, required this.path, required this.builder});

  final String path;
  final Widget Function(BuildContext context, Map<String, dynamic> data, Future<void> Function() reload) builder;

  @override
  State<ModuleDashboard> createState() => ModuleDashboardState();
}

class ModuleDashboardState extends State<ModuleDashboard> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    try {
      final r = await RecordsOffline.get(context.read<Session>().api, widget.path);
      if (mounted) setState(() => (_d = asMap((r as Map)['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? const SizedBox.shrink() : const SizedBox(height: 88, child: Center(child: CircularProgressIndicator()));
    return widget.builder(context, _d!, reload);
  }
}
