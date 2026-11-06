import 'dart:convert';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

const _netColors = {'facebook': Color(0xFF1877F2), 'instagram': Color(0xFFE1306C)};

String _ago(dynamic v) {
  final d = DateTime.tryParse('${v ?? ''}');
  if (d == null) return '';
  final diff = DateTime.now().difference(d);
  if (diff.inMinutes < 1) return tr('à l\'instant');
  if (diff.inMinutes < 60) return tr('${diff.inMinutes} min');
  if (diff.inHours < 24) return tr('${diff.inHours} h');
  if (diff.inDays < 7) return tr('${diff.inDays} j');
  return '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
}

/// Boîte de réception des réseaux : commentaires et messages Facebook / Instagram.
class SocialInboxTab extends StatefulWidget {
  const SocialInboxTab({super.key, required this.canSetup});

  final bool canSetup;

  @override
  State<SocialInboxTab> createState() => _SocialInboxTabState();
}

class _SocialInboxTabState extends State<SocialInboxTab> {
  Map<String, dynamic>? _d;
  String _status = 'open';
  String? _kind;
  int? _selected;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('social/inbox', query: {'status': _status, 'kind': ?_kind});
      if (mounted) setState(() => (_d = asMap(r), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  Future<void> _refresh() async {
    try {
      final r = asMap(asMap(await context.read<Session>().api.post('social/inbox/refresh'))['data']);
      if (mounted) toast(context, tr('${r['new']} nouveau(x) message(s).'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    _load();
  }

  Future<void> _accounts() async {
    await showModalBottomSheet<void>(context: context, showDragHandle: true, isScrollControlled: true, builder: (_) => _InboxAccounts(d: _d!, onChanged: _load));
    _load();
  }

  Future<void> _rules() => showDialog<void>(context: context, builder: (_) => const _RulesDialog());

  void _open(Map<String, dynamic> c, bool wide) {
    if (wide) {
      setState(() => _selected = c['id'] as int);
      return;
    }
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => Scaffold(appBar: AppBar(title: Text(verbatim('${c['author']}'))), body: _Thread(id: c['id'] as int, onChanged: _load)))).then((_) => _load());
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) return EmptyState(icon: Icons.error_outline, title: tr('Boîte de réception indisponible'), message: _error!, action: FilledButton(onPressed: _load, child: Text(tr('Réessayer'))));
    final d = _d;
    if (d == null) return const Center(child: CircularProgressIndicator());
    final convs = [for (final c in (d['data'] as List? ?? const [])) asMap(c)];
    final accounts = [for (final a in (d['accounts'] as List? ?? const [])) asMap(a)];
    final enabled = accounts.where((a) => a['enabled'] == true).toList();
    final toolbar = Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
      child: Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
        SegmentedButton<String>(
          showSelectedIcon: false,
          segments: [ButtonSegment(value: 'open', label: Text(tr('À traiter'))), ButtonSegment(value: 'done', label: Text(tr('Traités'))), ButtonSegment(value: 'all', label: Text(tr('Tout')))],
          selected: {_status},
          onSelectionChanged: (v) {
            setState(() => (_status = v.first, _selected = null));
            _load();
          },
        ),
        for (final k in const [('comment', 'Commentaires'), ('message', 'Messages')])
          FilterChip(label: Text(tr(k.$2)), selected: _kind == k.$1, onSelected: (on) {
            setState(() => _kind = on ? k.$1 : null);
            _load();
          }),
        IconButton(tooltip: tr('Relever maintenant'), onPressed: _refresh, icon: const Icon(Icons.refresh)),
        if (widget.canSetup) TextButton.icon(onPressed: _accounts, icon: const Icon(Icons.settings_input_antenna, size: 18), label: Text(tr('Comptes suivis (${enabled.length})'))),
        if (widget.canSetup) TextButton.icon(onPressed: _rules, icon: const Icon(Icons.auto_mode, size: 18), label: Text(tr('Réponses automatiques'))),
      ]),
    );
    if (accounts.isEmpty || enabled.isEmpty) {
      return Column(children: [
        toolbar,
        Expanded(
          child: EmptyState(
            icon: Icons.forum_outlined,
            title: tr(accounts.isEmpty ? 'Reliez une page Facebook ou un compte Instagram' : 'Activez la boîte de réception'),
            message: tr(accounts.isEmpty
                ? 'Onglet « Comptes » : connectez Facebook et Instagram. Les commentaires et messages arriveront ici.'
                : 'Choisissez les pages dont les commentaires et messages arrivent ici. Toute l\'équipe y répond depuis un seul écran.'),
            action: accounts.isNotEmpty && widget.canSetup ? FilledButton(onPressed: _accounts, child: Text(tr('Activer'))) : null,
          ),
        ),
      ]);
    }
    return LayoutBuilder(builder: (context, box) {
      final wide = box.maxWidth >= 900;
      final list = convs.isEmpty
          ? EmptyState(icon: Icons.mark_chat_read_outlined, title: tr(_status == 'open' ? 'Rien à traiter' : 'Aucune conversation'), message: tr('Les nouveaux commentaires et messages arrivent ici.'))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView.separated(
                itemCount: convs.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (_, i) {
                  final c = convs[i];
                  final unread = (c['unread'] as num? ?? 0) > 0;
                  final color = _netColors[c['network']] ?? YColors.blue;
                  return ListTile(
                    selected: _selected == c['id'],
                    onTap: () => _open(c, wide),
                    leading: Stack(clipBehavior: Clip.none, children: [
                      CircleAvatar(backgroundColor: color.withValues(alpha: .12), child: Text(('${c['author']}'.isEmpty ? '?' : '${c['author']}'[0]).toUpperCase(), style: TextStyle(color: color, fontWeight: FontWeight.w700))),
                      Positioned(right: -4, bottom: -4, child: Container(
                        padding: const EdgeInsets.all(3),
                        decoration: BoxDecoration(color: Theme.of(context).cardColor, shape: BoxShape.circle),
                        child: Icon(c['kind'] == 'message' ? Icons.mail : Icons.mode_comment, size: 13, color: color),
                      )),
                    ]),
                    title: Row(children: [
                      Expanded(child: Text(verbatim('${c['author']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: unread ? FontWeight.w800 : FontWeight.w600))),
                      Text(_ago(c['last_at']), style: const TextStyle(fontSize: 12, color: YColors.muted)),
                    ]),
                    subtitle: Row(children: [
                      Expanded(child: Text(verbatim('${c['last_text'] ?? ''}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: unread ? FontWeight.w600 : FontWeight.w400))),
                      if (unread) Badge(label: Text('${c['unread']}')),
                    ]),
                  );
                },
              ),
            );
      if (!wide) return Column(children: [toolbar, Expanded(child: list)]);
      return Column(children: [
        toolbar,
        Expanded(
          child: Row(children: [
            SizedBox(width: 380, child: list),
            const VerticalDivider(width: 1),
            Expanded(
              child: _selected == null
                  ? EmptyState(icon: Icons.forum_outlined, title: tr('Choisissez une conversation'), message: tr('Répondez en public ou en privé, attribuez-la à un collègue ou créez le prospect dans le CRM.'))
                  : _Thread(key: ValueKey(_selected), id: _selected!, onChanged: _load),
            ),
          ]),
        ),
      ]);
    });
  }
}

class _Thread extends StatefulWidget {
  const _Thread({super.key, required this.id, required this.onChanged});

  final int id;
  final VoidCallback onChanged;

  @override
  State<_Thread> createState() => _ThreadState();
}

class _ThreadState extends State<_Thread> {
  Map<String, dynamic>? _c;
  final _text = TextEditingController();
  bool _private = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('social/inbox/${widget.id}');
      if (mounted) setState(() => _c = asMap(asMap(r)['data']));
      widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _do(Future<dynamic> Function() fn, [String? done]) async {
    setState(() => _busy = true);
    try {
      final r = await fn();
      if (r is Map && r['data'] is Map && (r['data'] as Map).containsKey('messages')) {
        if (mounted) setState(() => _c = asMap(r['data']));
      }
      if (done != null && mounted) toast(context, done);
      widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _send() async {
    final t = _text.text.trim();
    if (t.isEmpty) return;
    await _do(() => context.read<Session>().api.post('social/inbox/${widget.id}/reply', {'text': t, 'private': _private}), tr(_private ? 'Message privé envoyé' : 'Réponse publiée'));
    if (mounted) _text.clear();
  }

  @override
  Widget build(BuildContext context) {
    final c = _c;
    if (c == null) return const Center(child: CircularProgressIndicator());
    final s = context.read<Session>();
    final msgs = [for (final m in (c['messages'] as List? ?? const [])) asMap(m)];
    final color = _netColors[c['network']] ?? YColors.blue;
    final me = s.user['id'];
    return Column(children: [
      Material(
        color: Theme.of(context).cardColor,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 8, 10),
          child: Row(children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(verbatim('${c['author']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                Text(tr('${c['network_label']} · ${c['kind'] == 'message' ? 'message privé' : 'commentaire'} · ${c['account'] ?? ''}${c['assigned_name'] != null ? ' · suivi par ${c['assigned_name']}' : ''}'),
                    style: const TextStyle(color: YColors.muted, fontSize: 12.5)),
              ]),
            ),
            if (c['assigned_to'] != me)
              IconButton(tooltip: tr('Je m\'en occupe'), onPressed: _busy ? null : () => _do(() => s.api.put('social/inbox/${widget.id}', {'assigned_to': me}), tr('Conversation attribuée')), icon: const Icon(Icons.person_add_alt)),
            IconButton(
              tooltip: tr(c['lead_id'] != null ? 'Prospect déjà créé' : 'Créer le prospect dans le CRM'),
              onPressed: _busy || !s.hasModule('crm') ? null : () => _do(() => s.api.post('social/inbox/${widget.id}/lead'), tr('Prospect créé dans le CRM')),
              icon: Icon(c['lead_id'] != null ? Icons.how_to_reg : Icons.person_search_outlined),
            ),
            IconButton(
              tooltip: tr(c['status'] == 'done' ? 'Rouvrir' : 'Marquer comme traité'),
              onPressed: _busy ? null : () => _do(() => s.api.put('social/inbox/${widget.id}', {'status': c['status'] == 'done' ? 'open' : 'done'})),
              icon: Icon(c['status'] == 'done' ? Icons.unarchive_outlined : Icons.task_alt),
            ),
          ]),
        ),
      ),
      if ((c['post_text'] ?? '').toString().isNotEmpty)
        Container(
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          color: color.withValues(alpha: .06),
          child: Text(tr('Publication : ${c['post_text']}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12.5, color: YColors.muted)),
        ),
      Expanded(
        child: ListView(padding: const EdgeInsets.all(16), children: [
          for (final m in msgs)
            Align(
              alignment: m['direction'] == 'out' ? Alignment.centerRight : Alignment.centerLeft,
              child: GestureDetector(
                onLongPress: m['direction'] == 'in' && c['kind'] == 'comment'
                    ? () async {
                        final hide = m['hidden'] != true;
                        if (!await confirm(context, tr(hide ? 'Masquer le commentaire' : 'Afficher le commentaire'),
                            tr(hide ? 'Il ne sera plus visible du public (son auteur et ses amis le voient encore).' : 'Le commentaire redevient visible de tous.'))) {
                          return;
                        }
                        _do(() => s.api.post('social/inbox/${widget.id}/messages/${m['id']}/hide', {'hide': hide}));
                      }
                    : null,
                child: Container(
                  constraints: const BoxConstraints(maxWidth: 520),
                  margin: const EdgeInsets.symmetric(vertical: 4),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: m['direction'] == 'out' ? color.withValues(alpha: .14) : Theme.of(context).cardColor,
                    border: Border.all(color: Theme.of(context).dividerColor.withValues(alpha: .5)),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    if (m['author'] != null) Text(verbatim('${m['author']}'), style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: YColors.muted)),
                    Text(verbatim('${m['text'] ?? ''}'), style: TextStyle(decoration: m['hidden'] == true ? TextDecoration.lineThrough : null)),
                    const SizedBox(height: 2),
                    Text(
                        [_ago(m['at']), if (m['private'] == true) tr('en privé'), if (m['hidden'] == true) tr('masqué')].join(' · '),
                        style: const TextStyle(fontSize: 11, color: YColors.muted)),
                  ]),
                ),
              ),
            ),
          if (c['kind'] == 'comment') Text(tr('Appui long sur un commentaire pour le masquer.'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 11.5, color: YColors.muted)),
        ]),
      ),
      SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (c['kind'] == 'comment' && c['network'] == 'facebook')
              FilterChip(
                label: Text(tr('Répondre en message privé')),
                selected: _private,
                onSelected: (v) => setState(() => _private = v),
                avatar: const Icon(Icons.lock_outline, size: 16),
              ),
            Row(children: [
              Expanded(
                child: TextField(
                  controller: _text,
                  minLines: 1,
                  maxLines: 5,
                  textInputAction: TextInputAction.newline,
                  decoration: InputDecoration(hintText: tr(c['kind'] == 'message' || _private ? 'Votre message…' : 'Votre réponse publique…')),
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filled(onPressed: _busy ? null : _send, icon: const Icon(Icons.send)),
            ]),
          ]),
        ),
      ),
    ]);
  }
}

class _InboxAccounts extends StatefulWidget {
  const _InboxAccounts({required this.d, required this.onChanged});

  final Map<String, dynamic> d;
  final VoidCallback onChanged;

  @override
  State<_InboxAccounts> createState() => _InboxAccountsState();
}

class _InboxAccountsState extends State<_InboxAccounts> {
  late List<Map<String, dynamic>> _acc = [for (final a in (widget.d['accounts'] as List? ?? const [])) asMap(a)];
  int? _busy;

  Future<void> _toggle(Map<String, dynamic> a, bool on) async {
    final api = context.read<Session>().api;
    setState(() => _busy = a['id'] as int);
    try {
      if (on) {
        final r = asMap(asMap(await api.post('social/accounts/${a['id']}/inbox'))['data']);
        if (mounted) toast(context, '${r['message']}');
      } else {
        await api.delete('social/accounts/${a['id']}/inbox');
      }
      setState(() => _acc = [for (final x in _acc) x['id'] == a['id'] ? {...x, 'enabled': on} : x]);
      widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  @override
  Widget build(BuildContext context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Comptes suivis dans la boîte de réception'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 17)),
            const SizedBox(height: 6),
            Text(
                tr(widget.d['extended'] == true
                    ? 'Commentaires, messages privés et prospects des publicités arrivent en direct.'
                    : 'Les commentaires sont relevés toutes les 10 minutes. Les messages privés et le direct demandent la validation de l\'application Meta par l\'administrateur de la plateforme.'),
                style: const TextStyle(color: YColors.muted)),
            const SizedBox(height: 8),
            for (final a in _acc)
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: a['enabled'] == true,
                onChanged: _busy != null ? null : (v) => _toggle(a, v),
                secondary: Icon(a['network'] == 'instagram' ? Icons.camera_alt_outlined : Icons.facebook, color: _netColors[a['network']]),
                title: Text(verbatim('${a['name']}')),
                subtitle: Text(tr(a['network'] == 'instagram' ? 'Instagram' : 'Page Facebook')),
              ),
          ]),
        ),
      );
}

class _RulesDialog extends StatefulWidget {
  const _RulesDialog();

  @override
  State<_RulesDialog> createState() => _RulesDialogState();
}

class _RulesDialogState extends State<_RulesDialog> {
  List<Map<String, TextEditingController>>? _rules;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('social/inbox-rules').then((r) {
      if (!mounted) return;
      setState(() => _rules = [for (final x in (asMap(r)['data'] as List? ?? const [])) _row(asMap(x))]);
    }).catchError((Object e) {
      if (mounted) toast(context, errorText(e), error: true);
    });
  }

  Map<String, TextEditingController> _row([Map<String, dynamic> x = const {}]) => {
        'keywords': TextEditingController(text: '${x['keywords'] ?? ''}'),
        'public': TextEditingController(text: '${x['public'] ?? ''}'),
        'private': TextEditingController(text: '${x['private'] ?? ''}'),
      };

  Future<void> _save() async {
    try {
      await context.read<Session>().api.put('social/inbox-rules', {
        'rules': [
          for (final r in _rules!)
            if (r['keywords']!.text.trim().isNotEmpty) {'keywords': r['keywords']!.text.trim(), 'public': r['public']!.text.trim(), 'private': r['private']!.text.trim()},
        ],
      });
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Réponses automatiques enregistrées'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(tr('Réponses automatiques aux commentaires')),
        content: SizedBox(
          width: 600,
          child: _rules == null
              ? const SizedBox(height: 100, child: Center(child: CircularProgressIndicator()))
              : SingleChildScrollView(
                  child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    Text(tr('Quand un commentaire contient un mot-clé (ex. « prix », « combien », « dispo »), Yomara répond publiquement et envoie un message privé à son auteur. Variables : {{prenom}}, {{entreprise}}.'),
                        style: const TextStyle(color: YColors.muted)),
                    for (final (i, r) in _rules!.indexed)
                      Card(
                        margin: const EdgeInsets.only(top: 12),
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(children: [
                            Row(children: [
                              Expanded(child: TextField(controller: r['keywords'], decoration: InputDecoration(labelText: tr('Mots-clés (séparés par des virgules)')))),
                              IconButton(onPressed: () => setState(() => _rules!.removeAt(i)), icon: const Icon(Icons.delete_outline)),
                            ]),
                            const SizedBox(height: 8),
                            TextField(controller: r['public'], maxLines: 2, decoration: InputDecoration(labelText: tr('Réponse publique'), hintText: tr('Bonjour {{prenom}}, on vous écrit en privé !'))),
                            const SizedBox(height: 8),
                            TextField(controller: r['private'], maxLines: 3, decoration: InputDecoration(labelText: tr('Message privé (Facebook)'), hintText: tr('Bonjour {{prenom}}, voici nos prix…'))),
                          ]),
                        ),
                      ),
                    const SizedBox(height: 8),
                    Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => setState(() => _rules!.add(_row())), icon: const Icon(Icons.add), label: Text(tr('Ajouter une règle')))),
                  ]),
                ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))), FilledButton(onPressed: _rules == null ? null : _save, child: Text(tr('Enregistrer')))],
      );
}

// ------------------------------------------------------------------ charte et visuels

/// Studio visuel : charte de marque et visuels générés à partir du catalogue.
class SocialStudioTab extends StatefulWidget {
  const SocialStudioTab({super.key, required this.canWrite, this.onCreated});

  final bool canWrite;
  final void Function(Map<String, dynamic> media)? onCreated;

  @override
  State<SocialStudioTab> createState() => _SocialStudioTabState();
}

class _SocialStudioTabState extends State<SocialStudioTab> {
  List<Map<String, dynamic>> _templates = [];
  List<Map<String, dynamic>> _formats = [];
  String _template = 'promo';
  String _format = 'square';
  String? _productId;
  final _title = TextEditingController();
  final _subtitle = TextEditingController();
  final _price = TextEditingController();
  final _old = TextEditingController();
  final _cta = TextEditingController();
  final _author = TextEditingController();
  final Set<String> _items = {};
  int? _mediaId;
  String? _preview;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('social/visuals/templates').then((r) {
      if (mounted) {
        setState(() {
          _templates = [for (final t in (asMap(r)['templates'] as List)) asMap(t)];
          _formats = [for (final t in (asMap(r)['formats'] as List)) asMap(t)];
        });
      }
    }).catchError((Object e) {
      if (mounted) toast(context, errorText(e), error: true);
    });
  }

  @override
  void dispose() {
    for (final c in [_title, _subtitle, _price, _old, _cta, _author]) {
      c.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> _body() => {
        'template': _template,
        'format': _format,
        if (_productId != null && _template != 'price_list') 'product_id': _productId,
        if (_title.text.trim().isNotEmpty) 'title': _title.text.trim(),
        if (_subtitle.text.trim().isNotEmpty) 'subtitle': _subtitle.text.trim(),
        if (toDouble(_price.text) > 0) 'price': toDouble(_price.text),
        if (toDouble(_old.text) > 0) 'old_price': toDouble(_old.text),
        if (_cta.text.trim().isNotEmpty) 'cta': _cta.text.trim(),
        if (_author.text.trim().isNotEmpty) 'author': _author.text.trim(),
        if (_template == 'price_list' && _items.isNotEmpty) 'product_ids': _items.toList(),
        if (_mediaId != null) 'media_id': _mediaId,
      };

  Future<void> _doPreview() async {
    setState(() => _busy = true);
    try {
      final r = asMap(asMap(await context.read<Session>().api.post('social/visuals/preview', _body()))['data']);
      if (mounted) setState(() => _preview = '${r['image']}');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _create() async {
    setState(() => _busy = true);
    try {
      final m = asMap(asMap(await context.read<Session>().api.post('social/visuals', _body()))['data']);
      if (!mounted) return;
      toast(context, tr('Visuel ajouté à vos médias.'));
      widget.onCreated?.call(m);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _upload() async {
    final files = await FilePicker.pickFiles(type: FileType.image);
    if (files.isEmpty || !mounted) return;
    final api = context.read<Session>().api;
    try {
      final b = await files.first.readAsBytes();
      final r = asMap(await api.upload('social/media', 'file', b, files.first.name));
      if (mounted) setState(() => _mediaId = (asMap(r['data'] ?? r)['id'] as num?)?.toInt());
      if (mounted) toast(context, tr('Photo prête.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _brand() => showFormDialog<void>(context, title: 'Charte de marque', builder: (_) => const _BrandDialog());

  @override
  Widget build(BuildContext context) {
    final products = LocalDb.hasEntity('products') ? (LocalDb.all('products').where((p) => p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'))) : <Map<String, dynamic>>[];
    final form = ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        Expanded(child: Text(tr('Créer un visuel'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18))),
        TextButton.icon(onPressed: _brand, icon: const Icon(Icons.palette_outlined, size: 18), label: Text(tr('Charte de marque'))),
      ]),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final t in _templates)
          ChoiceChip(label: Text(tr('${t['label']}')), tooltip: tr('${t['hint']}'), selected: _template == t['key'], onSelected: (_) => setState(() => (_template = '${t['key']}', _preview = null))),
      ]),
      const SizedBox(height: 12),
      DropdownButtonFormField<String>(
        initialValue: _format,
        decoration: InputDecoration(labelText: tr('Format')),
        items: [for (final f in _formats) DropdownMenuItem(value: '${f['key']}', child: Text(tr('${f['label']}')))],
        onChanged: (v) => setState(() => (_format = v ?? 'square', _preview = null)),
      ),
      if (_template == 'promo' || _template == 'new') ...[
        const SizedBox(height: 10),
        DropdownButtonFormField<String?>(
          initialValue: _productId,
          isExpanded: true,
          decoration: InputDecoration(labelText: tr('Article du catalogue (photo, nom et prix repris)')),
          items: [
            DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun : je remplis moi-même'))),
            for (final p in products.take(400)) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(verbatim('${p['name']}'), overflow: TextOverflow.ellipsis)),
          ],
          onChanged: (v) => setState(() => _productId = v),
        ),
      ],
      if (_template == 'price_list') ...[
        const SizedBox(height: 10),
        Text(tr('Articles (6 au maximum)'), style: const TextStyle(fontWeight: FontWeight.w600)),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final p in products.take(60))
            FilterChip(
              label: Text(verbatim('${p['name']}')),
              selected: _items.contains('${p['id']}'),
              onSelected: (on) => setState(() => on ? (_items.length < 6 ? _items.add('${p['id']}') : null) : _items.remove('${p['id']}')),
            ),
        ]),
      ],
      const SizedBox(height: 10),
      TextField(controller: _title, decoration: InputDecoration(labelText: tr(_template == 'quote' ? 'Avis du client' : 'Titre'), hintText: _template == 'promo' ? tr('Vide : nom de l\'article') : null), maxLines: _template == 'quote' ? 3 : 1),
      if (_template != 'quote' && _template != 'price_list') ...[const SizedBox(height: 8), TextField(controller: _subtitle, decoration: InputDecoration(labelText: tr('Texte secondaire')))],
      if (_template == 'quote') ...[const SizedBox(height: 8), TextField(controller: _author, decoration: InputDecoration(labelText: tr('Nom du client')))],
      if (_template == 'promo' || _template == 'new')
        Row(children: [
          Expanded(child: Padding(padding: const EdgeInsets.only(top: 8), child: TextField(controller: _price, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Prix'))))),
          const SizedBox(width: 10),
          if (_template == 'promo') Expanded(child: Padding(padding: const EdgeInsets.only(top: 8), child: TextField(controller: _old, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Ancien prix (barré)'))))),
        ]),
      const SizedBox(height: 8),
      TextField(controller: _cta, decoration: InputDecoration(labelText: tr('Appel à l\'action (bas du visuel)'), hintText: tr('Ex. Commandez sur WhatsApp'))),
      if (_template != 'price_list' && _template != 'quote') ...[
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerLeft,
          child: OutlinedButton.icon(onPressed: _upload, icon: const Icon(Icons.add_photo_alternate_outlined), label: Text(tr(_mediaId == null ? 'Utiliser ma photo' : 'Photo choisie : changer'))),
        ),
      ],
      const SizedBox(height: 16),
      Wrap(spacing: 10, runSpacing: 10, children: [
        OutlinedButton.icon(onPressed: _busy ? null : _doPreview, icon: const Icon(Icons.visibility_outlined), label: Text(tr('Aperçu'))),
        if (widget.canWrite) FilledButton.icon(onPressed: _busy ? null : _create, icon: const Icon(Icons.auto_awesome), label: Text(tr('Créer et ajouter aux médias'))),
      ]),
    ]);
    final preview = Container(
      color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .4),
      alignment: Alignment.center,
      padding: const EdgeInsets.all(16),
      child: _busy && _preview == null
          ? const CircularProgressIndicator()
          : _preview == null
              ? Text(tr('Cliquez sur « Aperçu » pour voir le visuel avec vos couleurs.'), textAlign: TextAlign.center, style: const TextStyle(color: YColors.muted))
              : ClipRRect(borderRadius: BorderRadius.circular(12), child: Image.memory(base64Decode(_preview!.split(',').last), fit: BoxFit.contain)),
    );
    return LayoutBuilder(builder: (c, box) {
      if (box.maxWidth < 860) {
        return ListView(children: [SizedBox(height: 760, child: form), if (_preview != null) SizedBox(height: 520, child: preview)]);
      }
      return Row(children: [SizedBox(width: 440, child: form), const VerticalDivider(width: 1), Expanded(child: preview)]);
    });
  }
}

class _BrandDialog extends StatefulWidget {
  const _BrandDialog();

  @override
  State<_BrandDialog> createState() => _BrandDialogState();
}

class _BrandDialogState extends State<_BrandDialog> {
  final _c = <String, TextEditingController>{for (final k in const ['primary', 'accent', 'dark', 'slogan', 'contact', 'hashtags', 'words_avoid']) k: TextEditingController()};
  Map<String, dynamic> _tones = {};
  String _tone = 'chaleureux';
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('social/brand').then((r) {
      final d = asMap(asMap(r)['data']);
      if (!mounted) return;
      setState(() {
        for (final e in _c.entries) {
          e.value.text = '${d[e.key] ?? ''}';
        }
        _tone = '${d['tone'] ?? 'chaleureux'}';
        _tones = asMap(asMap(r)['tones']);
        _ready = true;
      });
    });
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  Color? _hex(String v) {
    final h = v.replaceAll('#', '');
    return h.length == 6 ? Color(int.tryParse('FF$h', radix: 16) ?? 0) : null;
  }

  Future<void> _save() async {
    try {
      await context.read<Session>().api.put('social/brand', {for (final e in _c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(), 'tone': _tone});
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Charte enregistrée'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    Widget color(String k, String label) => Expanded(
          child: TextField(
            controller: _c[k],
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(labelText: tr(label), prefixIcon: Padding(padding: const EdgeInsets.all(12), child: CircleAvatar(radius: 9, backgroundColor: _hex(_c[k]!.text) ?? Colors.transparent))),
          ),
        );
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        !_ready
      ? const SizedBox(height: 100, child: Center(child: CircularProgressIndicator()))
      : SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(tr('Utilisée pour les visuels et les textes proposés par l\'IA. Le logo est celui de la société (Paramètres).'), style: const TextStyle(color: YColors.muted)),
            const SizedBox(height: 10),
            Row(children: [color('primary', 'Couleur principale'), const SizedBox(width: 8), color('accent', 'Couleur d\'accent')]),
            const SizedBox(height: 8),
            Row(children: [color('dark', 'Couleur sombre'), const SizedBox(width: 8), const Spacer()]),
            const SizedBox(height: 8),
            TextField(controller: _c['slogan'], decoration: InputDecoration(labelText: tr('Slogan'))),
            const SizedBox(height: 8),
            TextField(controller: _c['contact'], decoration: InputDecoration(labelText: tr('Coordonnées au bas des visuels'), hintText: tr('Ex. Tél. / WhatsApp 07 00 00 00 00'))),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              initialValue: _tones.containsKey(_tone) ? _tone : null,
              decoration: InputDecoration(labelText: tr('Ton des messages')),
              items: [for (final e in _tones.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
              onChanged: (v) => setState(() => _tone = v ?? _tone),
            ),
            const SizedBox(height: 8),
            TextField(controller: _c['hashtags'], decoration: InputDecoration(labelText: tr('Hashtags de la marque'))),
            const SizedBox(height: 8),
            TextField(controller: _c['words_avoid'], decoration: InputDecoration(labelText: tr('Mots à éviter'))),
          ]),
        ),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))), FilledButton(onPressed: _ready ? _save : null, child: Text(tr('Enregistrer'))),
          ]),
        ),
      ]),
    );
  }
}
