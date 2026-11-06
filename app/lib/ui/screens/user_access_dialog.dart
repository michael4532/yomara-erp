import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Modules et droits d'un utilisateur : modules gratuits / payants qu'il voit, et pour chacun les actions
/// autorisées (héritées de son rôle, ajoutées ou retirées pour lui seul : supprimer, valider…).
Future<void> showUserAccessDialog(BuildContext context, Map<String, dynamic> user) => showDialog(context: context, builder: (_) => _AccessDialog(user: user));

class _AccessDialog extends StatefulWidget {
  const _AccessDialog({required this.user});

  final Map<String, dynamic> user;

  @override
  State<_AccessDialog> createState() => _AccessDialogState();
}

class _AccessDialogState extends State<_AccessDialog> {
  Map<String, dynamic>? _d;
  final Set<String> _denied = {};
  final Set<String> _grants = {};
  final Set<String> _denies = {};
  String? _open;
  String _filter = 'all';
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('users/${widget.user['id']}/access');
      final d = asMap(r);
      if (!mounted) return;
      setState(() {
        _d = d;
        for (final m in (d['modules'] as List)) {
          if (m['allowed'] != true) _denied.add('${m['key']}');
          for (final p in (m['permissions'] as List)) {
            if (p['override'] == 'grant') _grants.add('${p['key']}');
            if (p['override'] == 'deny') _denies.add('${p['key']}');
          }
        }
      });
    } catch (e) {
      if (mounted) {
        Navigator.pop(context);
        toast(context, errorText(e), error: true);
      }
    }
  }

  /// État d'un droit : effectif ou non, compte tenu du rôle et des ajustements en cours.
  bool _effective(Map p) => !_denies.contains(p['key']) && (p['from_role'] == true || _grants.contains(p['key']));

  void _toggle(Map p, bool on) {
    final k = '${p['key']}';
    setState(() {
      _grants.remove(k);
      _denies.remove(k);
      if (on && p['from_role'] != true) _grants.add(k);
      if (!on && p['from_role'] == true) _denies.add(k);
    });
  }

  static const _actionIcons = {'view': Icons.visibility_outlined, 'manage': Icons.edit_outlined, 'delete': Icons.delete_outline, 'validate': Icons.verified_outlined,
    'approve': Icons.verified_outlined, 'request': Icons.outbox_outlined, 'use': Icons.play_circle_outline, 'payments': Icons.payments_outlined};

  IconData _icon(String key) {
    for (final e in _actionIcons.entries) {
      if (key.contains('.${e.key}')) return e.value;
    }
    return Icons.check_circle_outline;
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    final hint = Theme.of(context).hintColor;
    final mods = d == null ? const [] : (d['modules'] as List).where((m) => _filter == 'all' || (_filter == 'free') == (m['free'] == true)).toList();
    return Dialog(
      insetPadding: const EdgeInsets.all(24),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760, maxHeight: 780),
        child: d == null
            ? const SizedBox(height: 240, child: Center(child: CircularProgressIndicator()))
            : Column(mainAxisSize: MainAxisSize.min, children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 20, 12, 8),
                  child: Row(children: [
                    InitialsAvatar('${widget.user['name']}', size: 42),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('Modules et droits — ${widget.user['name']}'), style: Theme.of(context).textTheme.titleLarge),
                        Text(tr('Rôle : ${d['role'] ?? '—'} · les cases cochées en plus ou en moins s\'appliquent à cette personne seulement'), style: TextStyle(color: hint, fontSize: 12.5)),
                      ]),
                    ),
                    IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close)),
                  ]),
                ),
                if (d['is_admin'] == true)
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 24),
                    child: Card(
                      color: const Color(0xFFFFF6DB),
                      child: ListTile(dense: true, leading: const Icon(Icons.admin_panel_settings_outlined, color: Color(0xFF9A6B00)), title: Text(tr('Rôle administrateur : tous les droits, sauf ceux que vous retirez ici.'))),
                    ),
                  ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 10, 24, 6),
                  child: Row(children: [
                    SegmentedButton<String>(
                      showSelectedIcon: false,
                      segments: [ButtonSegment(value: 'all', label: Text(tr('Tous'))), ButtonSegment(value: 'free', label: Text(tr('Gratuits'))), ButtonSegment(value: 'paid', label: Text(tr('Payants')))],
                      selected: {_filter},
                      onSelectionChanged: (v) => setState(() => _filter = v.first),
                    ),
                    const Spacer(),
                    Text(tr('${(d['modules'] as List).length - _denied.length} module(s) visibles'), style: TextStyle(color: hint, fontSize: 12.5)),
                  ]),
                ),
                Flexible(
                  child: ListView(padding: const EdgeInsets.fromLTRB(24, 4, 24, 12), shrinkWrap: true, children: [
                    for (final m in mods) _module(asMap(m)),
                  ]),
                ),
                const Divider(),
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 8, 24, 16),
                  child: Row(children: [
                    TextButton(
                      onPressed: () => setState(() {
                        _denied.clear();
                        _grants.clear();
                        _denies.clear();
                      }),
                      child: Text(tr('Revenir aux droits du rôle')),
                    ),
                    const Spacer(),
                    TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
                    const SizedBox(width: 8),
                    FilledButton(
                      onPressed: _busy
                          ? null
                          : () async {
                              setState(() => _busy = true);
                              try {
                                await context.read<Session>().api.put('users/${widget.user['id']}/access', {'modules_denied': _denied.toList(), 'grants': _grants.toList(), 'denies': _denies.toList()});
                                if (context.mounted) {
                                  Navigator.pop(context);
                                  toast(context, tr('Droits de ${widget.user['name']} enregistrés : ils s\'appliquent à sa prochaine synchronisation.'));
                                }
                              } catch (e) {
                                if (context.mounted) toast(context, errorText(e), error: true);
                              } finally {
                                if (mounted) setState(() => _busy = false);
                              }
                            },
                      child: Text(tr('Enregistrer')),
                    ),
                  ]),
                ),
              ]),
      ),
    );
  }

  Widget _module(Map<String, dynamic> m) {
    final key = '${m['key']}';
    final allowed = m['core'] == true || !_denied.contains(key);
    final perms = [for (final p in (m['permissions'] as List)) asMap(p)];
    final on = perms.where(_effective).length;
    final open = _open == key;
    final hint = Theme.of(context).hintColor;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Column(children: [
        ListTile(
          onTap: perms.isEmpty || !allowed ? null : () => setState(() => _open = open ? null : key),
          leading: Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(color: (allowed ? YColors.blue : YColors.muted).withValues(alpha: .10), borderRadius: BorderRadius.circular(10)),
            child: Icon(Icons.extension_outlined, size: 19, color: allowed ? YColors.blue : YColors.muted),
          ),
          title: Row(children: [
            Flexible(child: Text(tr('${m['name']}'), overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w600, color: allowed ? null : hint))),
            const SizedBox(width: 8),
            StatusChip(m['free'] == true ? 'Gratuit' : 'Payant', color: m['free'] == true ? YColors.success : const Color(0xFF9333EA)),
          ]),
          subtitle: Text(tr(allowed ? (perms.isEmpty ? '${m['category'] ?? ''}' : '$on / ${perms.length} action(s) autorisée(s)') : 'Masqué pour cette personne'), style: TextStyle(fontSize: 12.5, color: hint)),
          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
            if (perms.isNotEmpty && allowed) Icon(open ? Icons.expand_less : Icons.expand_more, color: hint),
            Switch(value: allowed, onChanged: m['core'] == true ? null : (v) => setState(() => v ? _denied.remove(key) : _denied.add(key))),
          ]),
        ),
        if (open && allowed)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: Column(children: [
              for (final p in perms)
                CheckboxListTile(
                  dense: true,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 4),
                  value: _effective(p),
                  onChanged: (v) => _toggle(p, v == true),
                  secondary: Icon(_icon('${p['key']}'), size: 18, color: hint),
                  title: Text(tr('${p['label']}')),
                  subtitle: Text(
                    tr(_grants.contains(p['key']) ? 'Ajouté pour cette personne' : (_denies.contains(p['key']) ? 'Retiré pour cette personne' : (p['from_role'] == true ? 'Donné par le rôle' : 'Non accordé par le rôle'))),
                    style: TextStyle(fontSize: 11.5, color: _grants.contains(p['key']) ? YColors.success : (_denies.contains(p['key']) ? YColors.danger : hint)),
                  ),
                ),
            ]),
          ),
      ]),
    );
  }
}
