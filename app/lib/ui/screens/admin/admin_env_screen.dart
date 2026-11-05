import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/api_client.dart';
import '../../../core/json.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../../i18n/i18n.dart';

/// Configuration du serveur : les réglages du fichier .env modifiables depuis l'application.
class AdminEnvScreen extends StatefulWidget {
  const AdminEnvScreen({super.key});

  @override
  State<AdminEnvScreen> createState() => _AdminEnvScreenState();
}

class _AdminEnvScreenState extends State<AdminEnvScreen> {
  Map<String, dynamic>? _d;
  Object? _error;
  final Map<String, dynamic> _edits = {};
  final Map<String, TextEditingController> _ctl = {};
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final r = asMap(await context.read<Session>().api.get('admin/system/env'));
      _apply(asMap(r['data']));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _apply(Map<String, dynamic> d) {
    if (!mounted) return;
    setState(() {
      _d = d;
      _edits.clear();
      for (final g in d['groups'] as List) {
        for (final f in (g as Map)['fields'] as List) {
          final m = asMap(f);
          _ctl.putIfAbsent('${m['key']}', TextEditingController.new).text = m['type'] == 'secret' ? '' : '${m['value'] ?? ''}';
        }
      }
    });
  }

  Future<void> _save(List<Map<String, dynamic>> fields) async {
    final values = <String, dynamic>{};
    for (final f in fields) {
      final k = '${f['key']}';
      if (_edits.containsKey(k)) {
        values[k] = _edits[k];
      } else if (f['type'] != 'bool' && f['type'] != 'select') {
        final t = _ctl[k]!.text.trim();
        final before = f['type'] == 'secret' ? '' : '${f['value'] ?? ''}';
        if (t != before) values[k] = t;
      }
    }
    if (values.isEmpty) {
      toast(context, tr('Aucune modification.'));
      return;
    }
    setState(() => _busy = true);
    try {
      final r = asMap(await context.read<Session>().api.put('admin/system/env', {'values': values}));
      _apply(asMap(r['data']));
      if (mounted) toast(context, tr('Réglages enregistrés : ils s\'appliquent dès la prochaine requête.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _testMail() async {
    final to = TextEditingController(text: '${context.read<Session>().user['email'] ?? ''}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('E-mail de test')),
        content: TextField(controller: to, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: tr('Envoyer à'))),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Envoyer')))],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = asMap(await context.read<Session>().api.post('admin/system/env/test-mail', {'to': to.text.trim()}));
      if (mounted) toast(context, tr('${r['message']}'));
    } catch (e) {
      if (mounted) toast(context, e is ApiException ? tr(e.message) : errorText(e), error: true);
    }
  }

  Widget _source(Map<String, dynamic> f) {
    final s = '${f['source']}';
    final (label, color) = switch (s) {
      'settings' => ('Paramètres', YColors.blue),
      'env' => ('Fichier .env', YColors.success),
      _ => ('Par défaut', YColors.muted),
    };
    return StatusChip(tr(label), color: color);
  }

  Widget _field(Map<String, dynamic> f) {
    final k = '${f['key']}';
    final type = '${f['type']}';
    final label = tr('${f['label']}');
    final hint = f['hint'] == null ? null : tr('${f['hint']}');
    Widget input;
    if (type == 'bool') {
      final cur = _edits.containsKey(k) ? _edits[k] : (f['source'] == 'default' ? null : '${f['value']}' == 'true');
      input = DropdownButtonFormField<String>(
        initialValue: cur == null ? '' : (cur == true || cur == 'true' ? 'true' : 'false'),
        decoration: InputDecoration(labelText: label, helperText: hint, isDense: true),
        items: [DropdownMenuItem(value: '', child: Text(tr('Valeur par défaut'))), DropdownMenuItem(value: 'true', child: Text(tr('Oui'))), DropdownMenuItem(value: 'false', child: Text(tr('Non')))],
        onChanged: (v) => setState(() => _edits[k] = v == '' ? '' : v),
      );
    } else if (type == 'select') {
      final opts = asMap(f['options']);
      final cur = _edits.containsKey(k) ? '${_edits[k]}' : '${f['value'] ?? ''}';
      input = DropdownButtonFormField<String>(
        initialValue: opts.containsKey(cur) ? cur : '',
        isExpanded: true,
        decoration: InputDecoration(labelText: label, helperText: hint, isDense: true),
        items: [
          DropdownMenuItem(value: '', child: Text(tr('Valeur par défaut'))),
          for (final o in opts.entries) DropdownMenuItem(value: o.key, child: Text(tr('${o.value}'), overflow: TextOverflow.ellipsis)),
        ],
        onChanged: (v) => setState(() => _edits[k] = v ?? ''),
      );
    } else {
      input = TextField(
        controller: _ctl[k],
        obscureText: type == 'secret',
        keyboardType: type == 'number' ? TextInputType.number : (type == 'email' ? TextInputType.emailAddress : (type == 'url' ? TextInputType.url : TextInputType.text)),
        decoration: InputDecoration(
          labelText: label,
          isDense: true,
          helperText: type == 'secret' ? (f['is_set'] == true ? tr('Enregistré (${f['masked']}) : laissez vide pour le conserver') : hint) : hint,
          suffixIcon: type == 'secret' && f['source'] == 'settings'
              ? IconButton(tooltip: tr('Effacer'), icon: const Icon(Icons.backspace_outlined, size: 18), onPressed: () => setState(() => _edits[k] = '__clear__'))
              : null,
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: MediaQuery.sizeOf(context).width < 700
          ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              input,
              const SizedBox(height: 4),
              _edits[k] == '__clear__' ? StatusChip(tr('Sera effacé'), color: YColors.danger) : _source(f),
            ])
          : Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(child: input),
              const SizedBox(width: 10),
              Padding(padding: const EdgeInsets.only(top: 6), child: _edits[k] == '__clear__' ? StatusChip(tr('Sera effacé'), color: YColors.danger) : _source(f)),
            ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    return Column(children: [
      PageHeader(title: tr('Configuration du serveur'), subtitle: tr('Les réglages du fichier .env, modifiables ici : e-mail, adresse publique, assistant IA, services externes…')),
      Expanded(
        child: _error != null
            ? OnlineOnlyNotice(error: _error!, onRetry: _load)
            : d == null
                ? const Center(child: CircularProgressIndicator())
                : ListView(padding: EdgeInsets.symmetric(horizontal: MediaQuery.sizeOf(context).width < 700 ? 16 : 24, vertical: 8), children: [
                    SectionCard(
                      title: tr('Fixé dans le fichier .env'),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Wrap(spacing: 10, runSpacing: 10, children: [
                          for (final l in d['locked'] as List)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                              decoration: BoxDecoration(color: Theme.of(context).colorScheme.surfaceContainer, borderRadius: BorderRadius.circular(10)),
                              child: Text.rich(TextSpan(children: [
                                TextSpan(text: '${tr('${(l as Map)['label']}')}  ', style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
                                TextSpan(text: '${l['value']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                              ])),
                            ),
                        ]),
                        const SizedBox(height: 10),
                        Text(tr('La base de données, la clé de l\'application et le mode restent dans le fichier .env du serveur. Une valeur enregistrée ici remplace celle du .env ; videz le champ pour revenir au .env.'),
                            style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
                      ]),
                    ),
                    const SizedBox(height: 12),
                    for (final g in [for (final x in d['groups'] as List) asMap(x)]) ...[
                      SectionCard(
                        title: tr('${g['name']}'),
                        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                          if (g['name'] == 'E-mail d\'envoi') TextButton.icon(onPressed: _testMail, icon: const Icon(Icons.send_outlined, size: 18), label: Text(tr('Tester'))),
                          FilledButton.icon(onPressed: _busy ? null : () => _save([for (final f in g['fields'] as List) asMap(f)]), icon: const Icon(Icons.save_outlined, size: 18), label: Text(tr('Enregistrer'))),
                        ]),
                        // deux colonnes sur grand écran
                        child: LayoutBuilder(builder: (context, c) {
                          final two = c.maxWidth >= 900;
                          final w = two ? (c.maxWidth - 20) / 2 : c.maxWidth;
                          return Wrap(spacing: 20, children: [for (final f in g['fields'] as List) SizedBox(width: w, child: _field(asMap(f)))]);
                        }),
                      ),
                      const SizedBox(height: 12),
                    ],
                  ]),
      ),
    ]);
  }
}
