import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Historique d'activité de toute la société : qui a fait quoi, quand, sur quelle fiche.
/// Réservé au droit sensible « Voir l'historique d'activité » (administrateur ou accordé nommément).
class ActivityLogScreen extends StatefulWidget {
  const ActivityLogScreen({super.key, this.subjectId, this.title});

  /// Historique d'une seule fiche (ex. une facture).
  final String? subjectId;
  final String? title;

  @override
  State<ActivityLogScreen> createState() => _ActivityLogScreenState();
}

class _ActivityLogScreenState extends State<ActivityLogScreen> {
  final _rows = <Map<String, dynamic>>[];
  List<Map<String, dynamic>> _users = [];
  List<Map<String, dynamic>> _modules = [];
  bool _loading = true;
  bool _more = false;
  String? _error;
  int? _user;
  String? _module;
  String? _kind;
  DateTimeRange? _range;
  final _q = TextEditingController();

  @override
  void initState() {
    super.initState();
    _meta();
    _load();
  }

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  String _d(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _meta() async {
    if (widget.subjectId != null) return;
    try {
      final m = asMap(await context.read<Session>().api.get('activity/meta'));
      if (!mounted) return;
      setState(() {
        _users = [for (final u in (m['users'] as List? ?? [])) asMap(u)];
        _modules = [for (final x in (m['modules'] as List? ?? [])) asMap(x)];
      });
    } catch (_) {}
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _loading = true;
      _error = null;
      if (!more) _rows.clear();
    });
    try {
      final r = asMap(await context.read<Session>().api.get('activity', query: {
        if (widget.subjectId != null) 'subject_id': widget.subjectId,
        if (_user != null) 'user_id': '$_user',
        if (_module != null) 'module': _module,
        if (_kind != null) 'kind': _kind,
        if (_q.text.trim().isNotEmpty) 'q': _q.text.trim(),
        if (_range != null) 'from': _d(_range!.start),
        if (_range != null) 'to': _d(_range!.end),
        if (more && _rows.isNotEmpty) 'before_id': '${_rows.last['id']}',
      }));
      if (!mounted) return;
      setState(() {
        _rows.addAll([for (final x in (r['data'] as List? ?? [])) asMap(x)]);
        _more = r['more'] == true;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => (_error = e.isNetwork ? tr('L\'historique se consulte en ligne : vérifiez la connexion.') : e.message, _loading = false));
    }
  }

  IconData _icon(Map r) => switch (r['kind']) {
        'created' => Icons.add_circle_outline,
        'updated' => Icons.edit_outlined,
        'deleted' => Icons.delete_outline,
        _ => switch (r['action']) {
            'auth.login' => Icons.login,
            'document.validated' => Icons.verified_outlined,
            'document.reopened' => Icons.lock_open_outlined,
            'document.purged' => Icons.delete_forever_outlined,
            'document.cancelled' => Icons.block,
            _ => Icons.bolt_outlined,
          },
      };

  Color _color(Map r) => switch (r['kind']) {
        'created' => YColors.success,
        'deleted' => YColors.danger,
        _ => r['action'] == 'document.purged' || r['action'] == 'document.reopened' ? YColors.warning : YColors.blue,
      };

  String _value(dynamic v) => v == null || v == '' ? '—' : '$v';

  void _details(Map<String, dynamic> r) {
    final before = r['before'] is Map ? asMap(r['before']) : null;
    final changes = r['changes'] is Map ? asMap(r['changes']) : null;
    showDialog(
      context: context,
      builder: (c) => AlertDialog(
        title: Text('${tr(r['label'] ?? '')}${r['subject'] != null ? ' · ${r['subject']}' : ''}'),
        content: SizedBox(
          width: 520,
          child: SingleChildScrollView(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              Text('${dateTimeFr(r['at'])} · ${r['user']} · ${tr(r['module_label'] ?? '')}', style: const TextStyle(color: YColors.muted)),
              if (r['reason'] != null) ...[const SizedBox(height: 10), Text('${tr('Motif')} : ${r['reason']}', style: const TextStyle(fontWeight: FontWeight.w600))],
              if (changes != null && changes.isNotEmpty) ...[
                const SizedBox(height: 12),
                for (final e in changes.entries)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Text('${e.key} : ${_value((e.value as List?)?.first)} → ${_value((e.value as List?)?.last)}'),
                  ),
              ] else if ((r['fields'] as List?)?.isNotEmpty == true) ...[
                const SizedBox(height: 12),
                Text('${tr('Champs modifiés')} : ${(r['fields'] as List).join(', ')}'),
              ],
              if (before != null) ...[
                const SizedBox(height: 14),
                Text(tr('Le document avant l\'opération'), style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                Text('${_value(before['number'])} · ${_value(before['partner'])} · ${_value(before['date'])}'),
                Text('${tr('Total')} : ${money(toDouble(before['total']), (before['currency'] as String?) ?? 'XOF')} · ${tr('Payé')} : ${money(toDouble(before['amount_paid']), (before['currency'] as String?) ?? 'XOF')}'),
                const SizedBox(height: 6),
                for (final l in (before['lines'] as List? ?? []))
                  Text('• ${asMap(l)['description']} — ${asMap(l)['quantity']} × ${money(toDouble(asMap(l)['unit_price']), (before['currency'] as String?) ?? 'XOF')}'),
                for (final p in (before['payments'] as List? ?? []))
                  Text('${tr('Paiement supprimé')} : ${money(toDouble(asMap(p)['amount']), (before['currency'] as String?) ?? 'XOF')} (${asMap(p)['method']}) ${asMap(p)['date'] ?? ''}'),
              ],
              if (r['ip'] != null) ...[const SizedBox(height: 12), Text('${tr('Adresse IP')} : ${r['ip']}', style: const TextStyle(color: YColors.muted, fontSize: 12))],
            ]),
          ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
      ),
    );
  }

  Widget _filters() => Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
        SizedBox(
          width: 240,
          child: TextField(
            controller: _q,
            decoration: InputDecoration(isDense: true, prefixIcon: const Icon(Icons.search), hintText: tr('N° de facture, nom, action…')),
            onSubmitted: (_) => _load(),
          ),
        ),
        DropdownButton<int?>(
          value: _user,
          hint: Text(tr('Toutes les personnes')),
          items: [DropdownMenuItem(value: null, child: Text(tr('Toutes les personnes'))), for (final u in _users) DropdownMenuItem(value: u['id'] as int, child: Text('${u['name']}'))],
          onChanged: (v) => setState(() => (_user = v, _load())),
        ),
        DropdownButton<String?>(
          value: _module,
          hint: Text(tr('Tous les modules')),
          items: [DropdownMenuItem(value: null, child: Text(tr('Tous les modules'))), for (final m in _modules) DropdownMenuItem(value: '${m['key']}', child: Text(tr('${m['label']}')))],
          onChanged: (v) => setState(() => (_module = v, _load())),
        ),
        DropdownButton<String?>(
          value: _kind,
          items: [
            DropdownMenuItem(value: null, child: Text(tr('Toutes les actions'))),
            DropdownMenuItem(value: 'created', child: Text(tr('Créations'))),
            DropdownMenuItem(value: 'updated', child: Text(tr('Modifications'))),
            DropdownMenuItem(value: 'deleted', child: Text(tr('Suppressions'))),
            DropdownMenuItem(value: 'other', child: Text(tr('Validations, connexions…'))),
          ],
          onChanged: (v) => setState(() => (_kind = v, _load())),
        ),
        OutlinedButton.icon(
          icon: const Icon(Icons.date_range, size: 18),
          label: Text(_range == null ? tr('Toutes les dates') : '${dateFr(_d(_range!.start))} → ${dateFr(_d(_range!.end))}'),
          onPressed: () async {
            final r = await showDateRangePicker(context: context, firstDate: DateTime(2024), lastDate: DateTime.now().add(const Duration(days: 1)), initialDateRange: _range);
            if (r != null) setState(() => (_range = r, _load()));
          },
        ),
        if (_range != null || _user != null || _module != null || _kind != null || _q.text.isNotEmpty)
          TextButton(
            onPressed: () => setState(() {
              _range = null;
              _user = null;
              _module = null;
              _kind = null;
              _q.clear();
              _load();
            }),
            child: Text(tr('Effacer les filtres')),
          ),
      ]);

  @override
  Widget build(BuildContext context) {
    final narrow = MediaQuery.of(context).size.width < 700;
    final list = _error != null
        ? EmptyState(icon: Icons.cloud_off, title: tr('Historique indisponible'), message: _error, action: FilledButton(onPressed: _load, child: Text(tr('Réessayer'))))
        : (_rows.isEmpty && !_loading)
            ? EmptyState(icon: Icons.history, title: tr('Aucune activité'), message: tr('Rien ne correspond à ces filtres.'))
            : ListView.separated(
                padding: EdgeInsets.fromLTRB(narrow ? 8 : 16, 4, narrow ? 8 : 16, 24),
                itemCount: _rows.length + 1,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (c, i) {
                  if (i == _rows.length) {
                    if (_loading) return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
                    return _more ? Center(child: TextButton(onPressed: () => _load(more: true), child: Text(tr('Afficher plus')))) : const SizedBox(height: 8);
                  }
                  final r = _rows[i];
                  final fields = (r['fields'] as List?)?.join(', ');
                  return ListTile(
                    leading: CircleAvatar(backgroundColor: _color(r).withValues(alpha: .12), child: Icon(_icon(r), color: _color(r), size: 20)),
                    title: Text('${tr(r['label'] ?? '')}${r['subject'] != null ? ' · ${r['subject']}' : ''}', maxLines: 2, overflow: TextOverflow.ellipsis),
                    subtitle: Text(
                      '${r['user']} · ${tr(r['module_label'] ?? '')} · ${dateTimeFr(r['at'])}${fields != null && fields.isNotEmpty ? '\n${tr('Champs')} : $fields' : ''}${r['reason'] != null ? '\n${tr('Motif')} : ${r['reason']}' : ''}',
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                    ),
                    isThreeLine: fields != null && fields.isNotEmpty || r['reason'] != null,
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => _details(r),
                  );
                },
              );
    final body = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (widget.subjectId == null)
        PageHeader(
          title: tr('Historique d\'activité'),
          subtitle: tr('Tout ce qui est créé, modifié, validé ou supprimé dans l\'ERP, avec son auteur et l\'heure'),
          actions: [IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh))],
        ),
      if (widget.subjectId == null) Padding(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 8), child: _filters()),
      Expanded(child: list),
    ]);
    if (widget.subjectId == null) return body;
    return Scaffold(appBar: AppBar(title: Text(widget.title ?? tr('Historique'))), body: body);
  }
}
