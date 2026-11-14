import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../state/session.dart';
import '../../i18n/i18n.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/mail_html.dart';

/// Modèles d'e-mails : texte des envois de documents (factures, devis…) et de la bienvenue des employés.
/// [admin] : modèles par défaut de toutes les sociétés (super admin) ; sinon, ceux de la société.
class MailTemplatesScreen extends StatefulWidget {
  const MailTemplatesScreen({super.key, this.admin = false});

  final bool admin;

  @override
  State<MailTemplatesScreen> createState() => _MailTemplatesScreenState();
}

class _MailTemplatesScreenState extends State<MailTemplatesScreen> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;

  String get _base => widget.admin ? 'admin/mail-templates' : 'mail-templates';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get(_base);
      if (mounted) setState(() => (_rows = (r['data'] as List).map(asMap).toList(), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    final body = _error != null
        ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(errorText(_error!))))
        : rows == null
            ? const Center(child: CircularProgressIndicator())
            : ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 40), children: [
                PageHeader(
                  title: tr('Modèles d\'e-mails'),
                  subtitle: widget.admin
                      ? 'Texte par défaut de toutes les sociétés. Chaque société peut ensuite adapter le sien (Paramètres › Modèles d\'e-mails).'
                      : 'Le texte proposé quand vous envoyez une facture, un devis… et l\'e-mail reçu par chaque nouvel employé. Mise en page avec votre logo.',
                ),
                for (final g in rows.map((r) => '${r['group']}').toSet()) ...[
                  Padding(padding: const EdgeInsets.fromLTRB(4, 14, 4, 8), child: Text(tr(g), style: const TextStyle(fontWeight: FontWeight.w700, color: YColors.muted))),
                  for (final r in rows.where((r) => r['group'] == g))
                    Card(
                      child: ListTile(
                        leading: Icon(r['key'] == 'employee_welcome' ? Icons.waving_hand_outlined : Icons.description_outlined, color: YColors.blue),
                        title: Text(tr('${r['label']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                        subtitle: Text(verbatim('${r['subject']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                          if (r['customized'] == true) StatusChip(tr('Personnalisé'), color: YColors.success),
                          const Icon(Icons.chevron_right),
                        ]),
                        onTap: () async {
                          final changed = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => _TemplateEditor(row: r, base: _base)));
                          if (changed == true) _load();
                        },
                      ),
                    ),
                ],
              ]);
    return Scaffold(appBar: AppBar(title: Text(tr('Modèles d\'e-mails'))), body: body);
  }
}

class _TemplateEditor extends StatefulWidget {
  const _TemplateEditor({required this.row, required this.base});

  final Map<String, dynamic> row;
  final String base;

  @override
  State<_TemplateEditor> createState() => _TemplateEditorState();
}

class _TemplateEditorState extends State<_TemplateEditor> {
  late final _subject = TextEditingController(text: '${widget.row['subject']}');
  late final _body = TextEditingController(text: '${widget.row['body']}');
  String? _html;
  bool _busy = false;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _preview();
    _subject.addListener(_schedule);
    _body.addListener(_schedule);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _subject.dispose();
    _body.dispose();
    super.dispose();
  }

  void _schedule() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 600), _preview);
  }

  Future<void> _preview() async {
    try {
      final r = await context.read<Session>().api.post('${widget.base}/preview', {'key': widget.row['key'], 'subject': _subject.text, 'body': _body.text});
      if (mounted) setState(() => _html = '${asMap(r['data'])['html']}');
    } catch (_) {}
  }

  void _insert(String v) {
    final t = _body.text;
    final sel = _body.selection;
    final at = sel.isValid ? sel.start : t.length;
    final end = sel.isValid ? sel.end : t.length;
    _body.value = TextEditingValue(text: t.replaceRange(at, end, '{$v}'), selection: TextSelection.collapsed(offset: at + v.length + 2));
  }

  Future<void> _save({bool reset = false}) async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put(widget.base, {
        'templates': {
          widget.row['key']: reset ? {'reset': true} : {'subject': _subject.text.trim(), 'body': _body.text.trim()},
        },
      });
      if (mounted) {
        toast(context, tr(reset ? 'Modèle par défaut rétabli.' : 'Modèle enregistré.'));
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final vars = (widget.row['vars'] as List? ?? const []).map((e) => '$e').toList();
    final form = ListView(padding: const EdgeInsets.all(16), children: [
      Field(label: tr('Objet'), child: TextField(controller: _subject)),
      Field(label: tr('Message'), child: TextField(controller: _body, maxLines: null, minLines: 10, keyboardType: TextInputType.multiline)),
      Text(tr('Variables : touchez pour insérer à l\'endroit du curseur'), style: const TextStyle(color: YColors.muted, fontSize: 12.5)),
      const SizedBox(height: 6),
      Wrap(spacing: 6, runSpacing: 6, children: [for (final v in vars) ActionChip(label: Text('{$v}'), onPressed: () => _insert(v))]),
      const SizedBox(height: 18),
      Wrap(spacing: 8, runSpacing: 8, children: [
        FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer'))),
        if (widget.row['customized'] == true)
          OutlinedButton.icon(onPressed: _busy ? null : () => _save(reset: true), icon: const Icon(Icons.restart_alt), label: Text(tr('Rétablir le modèle par défaut'))),
      ]),
    ]);
    final preview = Container(
      color: const Color(0xFFEEF2F8),
      child: _html == null ? const Center(child: CircularProgressIndicator()) : MailHtml(key: ValueKey(_html.hashCode), html: _html!),
    );
    return Scaffold(
      appBar: AppBar(title: Text(tr('${widget.row['label']}'))),
      body: LayoutBuilder(builder: (c, k) {
        if (k.maxWidth >= 900) return Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [Expanded(child: form), Expanded(child: preview)]);
        return DefaultTabController(
          length: 2,
          child: Column(children: [
            TabBar(tabs: [Tab(text: tr('Modifier')), Tab(text: tr('Aperçu'))]),
            Expanded(child: TabBarView(children: [form, preview])),
          ]),
        );
      }),
    );
  }
}
