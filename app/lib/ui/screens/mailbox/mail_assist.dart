import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../widgets/common.dart';
import 'mail_store.dart';

/// Assistant de messagerie (inclus avec le module) : résumé, actions à faire, réponse rédigée, traduction.
class MailAssistBar extends StatefulWidget {
  const MailAssistBar({super.key, required this.store, required this.message, required this.onReply});

  final MailStore store;
  final Map<String, dynamic> message;
  final void Function(String body) onReply;

  @override
  State<MailAssistBar> createState() => _MailAssistBarState();
}

class _MailAssistBarState extends State<MailAssistBar> {
  static const _accent = Color(0xFF7C3AED);
  String? _action;
  String? _text;
  bool _ai = false;
  bool _busy = false;

  static const _titles = {'summary': 'Résumé', 'tasks': 'Actions à faire', 'reply': 'Proposition de réponse', 'translate': 'Traduction'};

  @override
  void didUpdateWidget(covariant MailAssistBar old) {
    super.didUpdateWidget(old);
    if (old.message['id'] != widget.message['id']) {
      _action = null;
      _text = null;
    }
  }

  Future<void> _run(String action, {String? tone, String? lang}) async {
    setState(() {
      _busy = true;
      _action = action;
      _text = null;
    });
    try {
      final r = asMap(await widget.store.api.post('mailbox/messages/${widget.message['id']}/assist', {'action': action, 'tone': ?tone, 'lang': ?lang}));
      final d = asMap(r['data']);
      if (mounted) {
        setState(() {
          _text = '${d['text'] ?? ''}';
          _ai = d['ai'] == true;
        });
      }
    } catch (e) {
      if (mounted) {
        toast(context, errorText(e), error: true);
        setState(() => _action = null);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    Widget chip(IconData icon, String label, VoidCallback? onTap) => Padding(
          padding: const EdgeInsets.only(right: 6),
          child: ActionChip(
            avatar: Icon(icon, size: 16, color: _accent),
            label: Text(tr(label)),
            onPressed: _busy ? null : onTap,
            visualDensity: VisualDensity.compact,
            shape: StadiumBorder(side: BorderSide(color: _accent.withValues(alpha: .25))),
            backgroundColor: _accent.withValues(alpha: .05),
          ),
        );
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 2, 20, 6),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(children: [
            const Icon(Icons.auto_awesome, size: 18, color: _accent),
            const SizedBox(width: 6),
            Text(tr('Assistant'), style: const TextStyle(fontWeight: FontWeight.w700, color: _accent)),
            const SizedBox(width: 10),
            chip(Icons.short_text_rounded, 'Résumer', () => _run('summary')),
            chip(Icons.checklist_rounded, 'Actions à faire', () => _run('tasks')),
            PopupMenuButton<String>(
              tooltip: tr('Rédiger une réponse'),
              enabled: !_busy,
              onSelected: (t) => _run('reply', tone: t),
              itemBuilder: (_) => [
                for (final t in const ['professionnel et cordial', 'chaleureux', 'bref et direct', 'formel'])
                  PopupMenuItem(value: t, child: Text(tr('Ton ${t.split(' ').first}'))),
              ],
              child: IgnorePointer(child: chip(Icons.edit_note_rounded, 'Rédiger une réponse', () {})),
            ),
            PopupMenuButton<String>(
              tooltip: tr('Traduire'),
              enabled: !_busy,
              onSelected: (l) => _run('translate', lang: l),
              itemBuilder: (_) => [
                for (final e in const {'fr': 'Français', 'en': 'Anglais', 'es': 'Espagnol', 'pt': 'Portugais', 'ar': 'Arabe', 'zh': 'Chinois', 'de': 'Allemand'}.entries)
                  PopupMenuItem(value: e.key, child: Text(tr(e.value))),
              ],
              child: IgnorePointer(child: chip(Icons.translate_rounded, 'Traduire', () {})),
            ),
          ]),
        ),
        if (_action != null)
          AnimatedSize(
            duration: const Duration(milliseconds: 200),
            child: Container(
              margin: const EdgeInsets.only(top: 8),
              padding: const EdgeInsets.fromLTRB(14, 10, 8, 10),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [_accent.withValues(alpha: .08), const Color(0xFF2E6BE6).withValues(alpha: .04)]),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: _accent.withValues(alpha: .2)),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Text(tr(_titles[_action]!), style: const TextStyle(fontWeight: FontWeight.w800)),
                  if (_text != null && !_ai) ...[const SizedBox(width: 8), Text(tr('· analyse locale'), style: TextStyle(color: hint, fontSize: 11.5))],
                  const Spacer(),
                  if (_text != null) ...[
                    if (_action == 'reply')
                      TextButton.icon(onPressed: () => widget.onReply(_text!), icon: const Icon(Icons.reply, size: 16), label: Text(tr('Répondre avec ce texte'))),
                    IconButton(
                      tooltip: tr('Copier'),
                      visualDensity: VisualDensity.compact,
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: _text!));
                        toast(context, tr('Copié.'));
                      },
                      icon: const Icon(Icons.copy_rounded, size: 16),
                    ),
                  ],
                  IconButton(visualDensity: VisualDensity.compact, onPressed: () => setState(() => _action = null), icon: const Icon(Icons.close_rounded, size: 16)),
                ]),
                if (_busy)
                  const Padding(padding: EdgeInsets.symmetric(vertical: 10), child: LinearProgressIndicator(minHeight: 3, color: _accent))
                else
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxHeight: 220),
                    child: SingleChildScrollView(child: SelectableText(_text ?? '', style: const TextStyle(height: 1.45))),
                  ),
              ]),
            ),
          ),
      ]),
    );
  }
}
