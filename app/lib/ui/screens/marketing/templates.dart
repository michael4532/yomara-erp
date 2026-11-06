import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'campaigns.dart';
import 'email_builder.dart';
import 'mk_common.dart';
import '../../../i18n/i18n.dart';

import 'package:yomara_erp/core/safe_launch.dart';
class TemplatesTab extends StatelessWidget {
  const TemplatesTab({super.key, required this.data, required this.reload, required this.canManage});

  final Map<String, dynamic> data;
  final Future<void> Function() reload;
  final bool canManage;

  Future<void> _create(BuildContext context) async {
    final name = TextEditingController();
    var channel = 'email';
    var start = 'layout';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Nouveau modèle')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(controller: name, autofocus: true, decoration: InputDecoration(labelText: tr('Nom'))),
              const SizedBox(height: 12),
              SegmentedButton<String>(
                segments: [for (final ch in ['email', 'sms', 'whatsapp']) ButtonSegment(value: ch, label: Text(tr(mkChannelLabels[ch]!)))],
                selected: {channel},
                onSelectionChanged: (s) => set(() => channel = s.first),
              ),
              if (channel == 'email') ...[
                const SizedBox(height: 12),
                RadioGroup<String>(
                  groupValue: start,
                  onChanged: (v) => set(() => start = v ?? start),
                  child: Column(children: [
                    RadioListTile(contentPadding: EdgeInsets.zero, value: 'layout', title: Text(tr('Partir d\'une mise en page'))),
                    RadioListTile(contentPadding: EdgeInsets.zero, value: 'blank', title: Text(tr('Page blanche (glisser-déposer)'))),
                    RadioListTile(contentPadding: EdgeInsets.zero, value: 'html', title: Text(tr('HTML personnalisé'))),
                  ]),
                ),
              ],
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Créer')))],
        ),
      ),
    );
    if (ok != true || name.text.trim().isEmpty || !context.mounted) return;
    Map<String, dynamic>? layout;
    if (channel == 'email' && start == 'layout') {
      layout = await pickLayout(context);
      if (layout == null || !context.mounted) return;
    }
    try {
      final t = mkMap(await context.read<Session>().api.post('marketing/templates', {
        'name': name.text.trim(),
        'channel': channel,
        'layout': ?layout?['key'],
        if (start == 'html' && channel == 'email') 'html': '<!doctype html>\n<html><body style="font-family:Arial">\n  <p>Bonjour {{prenom}},</p>\n  <p><a href="{{lien_desinscription}}">Se désinscrire</a></p>\n</body></html>',
      }));
      if (context.mounted) await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TemplateScreen(id: t['id'] as int)));
      reload();
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final templates = mkList(data['templates']);
    return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
      Row(children: [
        Expanded(child: Text(tr('Modèles réutilisables pour vos campagnes, automatisations et propositions commerciales.'), style: TextStyle(color: Theme.of(context).hintColor))),
        if (canManage) FilledButton.tonalIcon(onPressed: () => _create(context), icon: const Icon(Icons.add), label: Text(tr('Modèle'))),
      ]),
      const SizedBox(height: 12),
      Wrap(spacing: 14, runSpacing: 14, children: [
        for (final t in templates)
          SizedBox(
            width: 220,
            child: Card(
              clipBehavior: Clip.antiAlias,
              child: InkWell(
                onTap: canManage
                    ? () async {
                        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TemplateScreen(id: t['id'] as int)));
                        reload();
                      }
                    : null,
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  SizedBox(
                    height: 200,
                    child: t['channel'] == 'email'
                        ? LayoutThumbnail(content: t)
                        : Container(
                            color: const Color(0xFFECE5DD),
                            padding: const EdgeInsets.all(12),
                            alignment: Alignment.topLeft,
                            child: Container(
                              padding: const EdgeInsets.all(8),
                              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
                              child: Text(tr('${t['text'] ?? ''}'), maxLines: 7, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, color: Color(0xFF111B21))),
                            ),
                          ),
                  ),
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Icon(channelIcons[t['channel']], color: YColors.blue, size: 18),
                        const SizedBox(width: 6),
                        Expanded(child: Text(tr('${t['name']}'), style: const TextStyle(fontWeight: FontWeight.w700), maxLines: 1, overflow: TextOverflow.ellipsis)),
                      ]),
                      const SizedBox(height: 4),
                      Text(tr('${t['subject'] ?? mkChannelLabels[t['channel']]}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                    ]),
                  ),
                ]),
              ),
            ),
          ),
      ]),
    ]);
  }
}

class TemplateScreen extends StatefulWidget {
  const TemplateScreen({super.key, required this.id});

  final int id;

  @override
  State<TemplateScreen> createState() => _TemplateScreenState();
}

class _TemplateScreenState extends State<TemplateScreen> {
  Map<String, dynamic>? _t;
  final _editor = GlobalKey<ContentEditorState>();

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('marketing/templates/${widget.id}').then((t) {
      if (mounted) setState(() => _t = mkMap(t));
    }).catchError((Object e) {
      if (mounted) toast(context, errorText(e), error: true);
    });
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    return Scaffold(
      appBar: AppBar(
        title: Text(tr(_t == null ? 'Modèle' : '${_t!['name']}')),
        actions: [
          if (_t != null) ...[
            if (_t!['channel'] == 'email')
              IconButton(
                tooltip: tr('Aperçu dans le navigateur'),
                onPressed: () async {
                  try {
                    final r = mkMap(await api.post('marketing/preview', _editor.currentState?.value ?? _t!));
                    await safeLaunchUrl(Uri.parse('${r['url']}'));
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.open_in_browser),
              ),
            IconButton(
              tooltip: tr('Supprimer'),
              onPressed: () async {
                if (!await confirm(context, tr('Supprimer ce modèle ?'), '${_t!['name']}', danger: true, ok: tr('Supprimer'))) return;
                await api.delete('marketing/templates/${widget.id}');
                if (context.mounted) Navigator.pop(context);
              },
              icon: const Icon(Icons.delete_outline),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: FilledButton.icon(
                onPressed: () async {
                  try {
                    final v = Map<String, dynamic>.from(_editor.currentState!.value)..remove('channel');
                    await api.put('marketing/templates/${widget.id}', v);
                    if (context.mounted) toast(context, tr('Modèle enregistré.'));
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.save_outlined),
                label: Text(tr('Enregistrer')),
              ),
            ),
          ],
        ],
      ),
      body: _t == null ? const Center(child: CircularProgressIndicator()) : ContentEditor(key: _editor, initial: _t!),
    );
  }
}
