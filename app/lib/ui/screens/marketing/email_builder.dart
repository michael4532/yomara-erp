import 'dart:convert';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'blocks.dart';
import 'html_frame.dart';
import 'mk_common.dart';
import '../../../i18n/i18n.dart';

/// Contenu d'un e-mail en cours de conception (partagé entre l'éditeur et l'écran parent).
class EmailDesign {
  EmailDesign({required this.blocks, required this.style, this.html = '', this.htmlMode = false});

  factory EmailDesign.from(Map<String, dynamic> c) {
    final html = '${c['html'] ?? ''}';
    return EmailDesign(
      blocks: mkList(c['blocks']).map((b) => {...b, '_id': b['_id'] ?? newBlockId()}).toList(),
      style: mkMap(c['style']),
      html: html,
      htmlMode: html.trim().isNotEmpty,
    );
  }

  List<Map<String, dynamic>> blocks;
  Map<String, dynamic> style;
  String html;
  bool htmlMode;

  Map<String, dynamic> toJson() => {'blocks': blocks, 'style': style, 'html': htmlMode ? html : null};
}

/// Image hébergée sur le serveur (visible dans les messageries).
Future<void> uploadMarketingImage(BuildContext context, void Function(String url) done) async {
  final r = await FilePicker.pickFiles(type: FileType.image);
  if (r.isEmpty || !context.mounted) return;
  try {
    final res = mkMap(await context.read<Session>().api.upload('marketing/images', 'image', await r.first.readAsBytes(), r.first.name));
    done('${res['url']}');
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ======================================================================
// Aperçu en lecture seule
// ======================================================================

class EmailPreview extends StatelessWidget {
  const EmailPreview({super.key, required this.content, this.narrow = false, this.showInbox = true, this.scroll = true, this.liveHtml = true});

  final Map<String, dynamic> content;
  final bool narrow;
  final bool showInbox;
  final bool scroll;
  final bool liveHtml;

  @override
  Widget build(BuildContext context) {
    final channel = '${content['channel'] ?? 'email'}';
    final company = '${context.read<Session>().company['name'] ?? 'Votre société'}';
    if (channel != 'email') return PhonePreview(channel: channel, text: fillVars('${content['text'] ?? ''}', company));
    final style = mkMap(content['style']);
    final html = '${content['html'] ?? ''}';
    final body = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (showInbox) InboxHeader(subject: '${content['subject'] ?? ''}', preheader: '${content['preheader'] ?? ''}', style: style),
      if (html.trim().isNotEmpty)
        liveHtml
            ? SizedBox(height: 640, child: ClipRRect(borderRadius: BorderRadius.circular(12), child: HtmlFrame(html: fillVars(html, company))))
            : Container(
                height: 420,
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                alignment: Alignment.center,
                child: Column(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.code, size: 80, color: YColors.blue), Text(tr('HTML personnalisé'), style: TextStyle(fontSize: 28, fontWeight: FontWeight.bold))]),
              )
      else
        EmailCard(style: style, narrow: narrow, children: [
          for (final b in mkList(content['blocks'])) EmailBlockView(block: b, style: style, company: company, narrow: narrow),
        ]),
      if ((content['attachments'] as List? ?? const []).isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: Wrap(spacing: 8, children: [for (final a in mkList(content['attachments'])) Chip(avatar: const Icon(Icons.attach_file, size: 16), label: Text(tr('${a['name']}')))]),
        ),
    ]);
    final framed = Center(child: ConstrainedBox(constraints: BoxConstraints(maxWidth: narrow ? 380 : 600), child: body));
    return Container(
      color: hexColor(style['background'], const Color(0xFFF4F6FB)),
      child: scroll ? ListView(padding: const EdgeInsets.all(20), children: [framed]) : Padding(padding: const EdgeInsets.all(20), child: framed),
    );
  }
}

class InboxHeader extends StatelessWidget {
  const InboxHeader({super.key, required this.subject, required this.preheader, required this.style});

  final String subject;
  final String preheader;
  final Map<String, dynamic> style;

  @override
  Widget build(BuildContext context) {
    final name = '${context.read<Session>().company['name'] ?? ''}';
    final primary = hexColor(style['primary'], YColors.blue);
    return Container(
      padding: const EdgeInsets.all(12),
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10), border: Border.all(color: YColors.line)),
      child: Row(children: [
        CircleAvatar(radius: 16, backgroundColor: primary, child: Text(tr(name.isEmpty ? '?' : name[0]), style: const TextStyle(color: Colors.white))),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(name), style: const TextStyle(fontWeight: FontWeight.w700, color: YColors.night)),
            Text.rich(
              TextSpan(children: [
                TextSpan(text: subject.isEmpty ? '(sans objet)' : fillVars(subject, name), style: const TextStyle(fontWeight: FontWeight.w600, color: YColors.night)),
                if (preheader.isNotEmpty) TextSpan(text: ' — ${fillVars(preheader, name)}', style: const TextStyle(color: YColors.muted)),
              ]),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ]),
        ),
      ]),
    );
  }
}

/// Carte blanche de l'e-mail : en-tête (logo), contenu, pied de page légal.
class EmailCard extends StatelessWidget {
  const EmailCard({super.key, required this.style, required this.children, this.narrow = false, this.footer});

  final Map<String, dynamic> style;
  final List<Widget> children;
  final bool narrow;
  final Widget? footer;

  @override
  Widget build(BuildContext context) {
    final company = context.read<Session>().company;
    final name = '${company['name'] ?? ''}';
    final primary = hexColor(style['primary'], YColors.blue);
    final logo = style['logo'] ?? company['logo'];
    final font = '${style['font'] ?? 'Arial'}'.split(',').first.trim();
    return Container(
      decoration: BoxDecoration(color: hexColor(style['card'], Colors.white), borderRadius: BorderRadius.circular(narrow ? 0 : 12)),
      clipBehavior: Clip.antiAlias,
      child: DefaultTextStyle(
        style: TextStyle(color: hexColor(style['text'], YColors.night), fontSize: 15, height: 1.55, fontFamily: font),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (style['show_header'] != false)
            Padding(
              padding: EdgeInsets.fromLTRB(narrow ? 18 : 32, 24, 32, 8),
              child: Align(
                alignment: Alignment.centerLeft,
                child: logo != null && '$logo'.startsWith('http')
                    ? Image.network('$logo', height: 40, errorBuilder: (_, _, _) => Text(tr(name), style: TextStyle(color: primary, fontWeight: FontWeight.bold, fontSize: 18)))
                    : Text(tr(name), style: TextStyle(color: primary, fontWeight: FontWeight.bold, fontSize: 18)),
              ),
            ),
          ...children,
          footer ??
              Padding(
                padding: const EdgeInsets.fromLTRB(32, 24, 32, 24),
                child: Text(
                  tr('${[name, company['address'], company['city'], company['phone'], company['email']].where((e) => e != null && '$e'.isNotEmpty).join(' · ')}\n'
                  'Vous recevez ce message car vous êtes en relation avec $name. Se désinscrire'),
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 12, color: YColors.muted),
                ),
              ),
        ]),
      ),
    );
  }
}

class PhonePreview extends StatelessWidget {
  const PhonePreview({super.key, required this.channel, required this.text});

  final String channel;
  final String text;

  @override
  Widget build(BuildContext context) {
    final wa = channel == 'whatsapp';
    return Container(
      color: const Color(0xFFE9EDF3),
      alignment: Alignment.topCenter,
      padding: const EdgeInsets.all(24),
      child: Container(
        width: 340,
        height: 560,
        decoration: BoxDecoration(color: wa ? const Color(0xFFECE5DD) : Colors.white, borderRadius: BorderRadius.circular(28), border: Border.all(color: YColors.night, width: 8)),
        clipBehavior: Clip.antiAlias,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Container(
            color: wa ? const Color(0xFF075E54) : const Color(0xFFF2F2F7),
            padding: const EdgeInsets.all(12),
            child: Row(children: [
              CircleAvatar(radius: 14, backgroundColor: wa ? Colors.white24 : YColors.line, child: Icon(wa ? Icons.storefront : Icons.sms, size: 16, color: wa ? Colors.white : YColors.muted)),
              const SizedBox(width: 8),
              Expanded(child: Text(tr('${context.read<Session>().company['name'] ?? ''}'), style: TextStyle(fontWeight: FontWeight.w700, color: wa ? Colors.white : YColors.night))),
            ]),
          ),
          Expanded(
            child: ListView(padding: const EdgeInsets.all(12), children: [
              Align(
                alignment: Alignment.centerLeft,
                child: Container(
                  constraints: const BoxConstraints(maxWidth: 260),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(color: wa ? Colors.white : const Color(0xFFE9E9EB), borderRadius: BorderRadius.circular(12)),
                  child: Text(tr(text.isEmpty ? 'Votre message…' : text), style: const TextStyle(color: Colors.black87, fontSize: 14)),
                ),
              ),
            ]),
          ),
        ]),
      ),
    );
  }
}

// ======================================================================
// Choix d'une mise en page
// ======================================================================

/// Galerie des mises en page prêtes ; renvoie la mise en page choisie.
Future<Map<String, dynamic>?> pickLayout(BuildContext context, {String title = 'Choisir une mise en page'}) async {
  List<Map<String, dynamic>> layouts;
  try {
    layouts = mkList(mkMap(await context.read<Session>().api.get('marketing/layouts'))['data']);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return null;
  }
  if (!context.mounted) return null;
  var cat = 'Toutes';
  final cats = ['Toutes', ...{for (final l in layouts) '${l['category']}'}];
  return showDialog<Map<String, dynamic>>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => Dialog(
        insetPadding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 1100, maxHeight: 820),
          child: Column(children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 18, 12, 4),
              child: Row(children: [
                Expanded(child: Text(tr(title), style: Theme.of(c).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700))),
                IconButton(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close)),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Wrap(spacing: 8, children: [for (final k in cats) ChoiceChip(label: Text(tr(k)), selected: cat == k, onSelected: (_) => set(() => cat = k))]),
              ),
            ),
            const SizedBox(height: 8),
            Expanded(
              child: GridView.extent(
                padding: const EdgeInsets.all(20),
                maxCrossAxisExtent: 250,
                childAspectRatio: 0.62,
                mainAxisSpacing: 16,
                crossAxisSpacing: 16,
                children: [
                  for (final l in layouts.where((l) => cat == 'Toutes' || l['category'] == cat))
                    InkWell(
                      borderRadius: BorderRadius.circular(12),
                      onTap: () => Navigator.pop(c, l),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                        Expanded(
                          child: Container(
                            decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), border: Border.all(color: YColors.line)),
                            clipBehavior: Clip.antiAlias,
                            child: LayoutThumbnail(content: {...l, 'channel': 'email'}),
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(tr('${l['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(tr('${l['description']}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
                      ]),
                    ),
                ],
              ),
            ),
          ]),
        ),
      ),
    ),
  );
}

/// Miniature d'un e-mail (rendu réel réduit, non interactif).
class LayoutThumbnail extends StatelessWidget {
  const LayoutThumbnail({super.key, required this.content});

  final Map<String, dynamic> content;

  @override
  Widget build(BuildContext context) {
    final style = mkMap(content['style']);
    return IgnorePointer(
      child: Container(
        color: hexColor(style['background'], const Color(0xFFF4F6FB)),
        child: ClipRect(
          child: OverflowBox(
            alignment: Alignment.topCenter,
            maxHeight: double.infinity,
            child: FittedBox(
              fit: BoxFit.fitWidth,
              alignment: Alignment.topCenter,
              child: SizedBox(width: 640, child: EmailPreview(content: content, showInbox: false, scroll: false, liveHtml: false)),
            ),
          ),
        ),
      ),
    );
  }
}

// ======================================================================
// Éditeur glisser-déposer
// ======================================================================

class _Drag {
  const _Drag({this.blocks = const [], this.from});

  final List<Map<String, dynamic>> blocks; // nouveaux blocs (palette)
  final int? from; // bloc existant déplacé
}

class EmailBuilder extends StatefulWidget {
  const EmailBuilder({
    super.key,
    required this.design,
    this.onChanged,
    this.subject = '',
    this.preheader = '',
    this.allowHtml = true,
    this.showInbox = true,
    this.footer,
    this.hint,
  });

  final EmailDesign design;
  final VoidCallback? onChanged;
  final String subject;
  final String preheader;
  final bool allowHtml;
  final bool showInbox;

  /// Remplace le pied de page légal (pages de destination : aperçu du formulaire).
  final Widget? footer;
  final String? hint;

  @override
  State<EmailBuilder> createState() => EmailBuilderState();
}

class EmailBuilderState extends State<EmailBuilder> with SingleTickerProviderStateMixin, AutomaticKeepAliveClientMixin {
  late final TabController _side = TabController(length: 3, vsync: this);
  final _undo = <String>[];
  final _redo = <String>[];
  String? _selected;
  String? _lastEditKey;
  DateTime _lastEditAt = DateTime(2000);
  bool _mobile = false;
  late final _html = TextEditingController(text: widget.design.html);

  EmailDesign get d => widget.design;

  @override
  bool get wantKeepAlive => true;

  @override
  void dispose() {
    _side.dispose();
    _html.dispose();
    super.dispose();
  }

  String _snap() => jsonEncode({'blocks': d.blocks, 'style': d.style});

  /// Applique une modification avec historique (les frappes successives d'un même champ sont regroupées).
  void _commit(VoidCallback change, {String? editKey}) {
    final now = DateTime.now();
    final coalesce = editKey != null && editKey == _lastEditKey && now.difference(_lastEditAt) < const Duration(seconds: 2);
    if (!coalesce) {
      _undo.add(_snap());
      if (_undo.length > 80) _undo.removeAt(0);
      _redo.clear();
    }
    _lastEditKey = editKey;
    _lastEditAt = now;
    setState(change);
    widget.onChanged?.call();
  }

  void _restore(String snap) {
    final m = jsonDecode(snap) as Map<String, dynamic>;
    d.blocks = mkList(m['blocks']);
    d.style = mkMap(m['style']);
    for (final b in d.blocks) {
      BlockSettings.refresh(blockId(b));
    }
    if (!d.blocks.any((b) => blockId(b) == _selected)) _selected = null;
    _lastEditKey = null;
  }

  void undo() {
    if (_undo.isEmpty) return;
    _redo.add(_snap());
    setState(() => _restore(_undo.removeLast()));
    widget.onChanged?.call();
  }

  void redo() {
    if (_redo.isEmpty) return;
    _undo.add(_snap());
    setState(() => _restore(_redo.removeLast()));
    widget.onChanged?.call();
  }

  int get _selectedIndex => d.blocks.indexWhere((b) => blockId(b) == _selected);

  void _insert(List<Map<String, dynamic>> blocks, int at, {bool openSettings = true}) {
    final fresh = [for (final b in blocks) withId(b)];
    _commit(() {
      d.blocks.insertAll(at.clamp(0, d.blocks.length), fresh);
      _selected = blockId(fresh.first);
    });
    if (openSettings && fresh.length == 1) _side.animateTo(1);
  }

  void _drop(_Drag data, int at) {
    if (data.from != null) {
      final from = data.from!;
      if (from == at || from + 1 == at) return;
      _commit(() {
        final b = d.blocks.removeAt(from);
        d.blocks.insert(from < at ? at - 1 : at, b);
        _selected = blockId(b);
      });
    } else {
      _insert(data.blocks, at);
    }
  }

  void _move(int i, int delta) {
    final j = i + delta;
    if (j < 0 || j >= d.blocks.length) return;
    _commit(() {
      final b = d.blocks.removeAt(i);
      d.blocks.insert(j, b);
    });
  }

  void _duplicate(int i) => _commit(() {
        final copy = withId(Map<String, dynamic>.from(jsonDecode(jsonEncode(d.blocks[i]))));
        d.blocks.insert(i + 1, copy);
        _selected = blockId(copy);
      });

  void _delete(int i) => _commit(() {
        final removed = d.blocks.removeAt(i);
        if (blockId(removed) == _selected) _selected = null;
      });

  void _select(String id, {bool narrow = false}) {
    setState(() => _selected = id);
    if (narrow) {
      _openSheet(1);
    } else {
      _side.animateTo(1);
    }
  }

  Future<void> applyLayout() async {
    final l = await pickLayout(context, title: tr('Remplacer par une mise en page'));
    if (l == null || !mounted) return;
    if (d.blocks.isNotEmpty && !await confirm(context, tr('Remplacer le contenu ?'), tr('Le contenu actuel sera remplacé par « ${l['name']} » (annulable).'))) return;
    _commit(() {
      d.blocks = [for (final b in mkList(l['blocks'])) withId(b)];
      d.style = {...d.style, ...mkMap(l['style'])};
      d.htmlMode = false;
      _selected = null;
    });
  }

  // ---------------------------------------------------------------- build

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return LayoutBuilder(builder: (context, c) {
      final narrow = c.maxWidth < 760;
      final canvas = Column(children: [
        _toolbar(narrow),
        const Divider(height: 1),
        Expanded(child: d.htmlMode ? _htmlEditor(narrow) : _canvas(narrow)),
      ]);
      if (narrow) {
        return Stack(children: [
          canvas,
          if (!d.htmlMode)
            Positioned(right: 16, bottom: 16, child: FloatingActionButton.extended(heroTag: null, onPressed: () => _openSheet(0), icon: const Icon(Icons.add), label: Text(tr('Ajouter')))),
        ]);
      }
      return Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (!d.htmlMode) SizedBox(width: 340, child: _sidePanel(false)),
        if (!d.htmlMode) const VerticalDivider(width: 1),
        Expanded(child: canvas),
      ]);
    });
  }

  Widget _toolbar(bool narrow) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      child: Wrap(spacing: 4, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
        IconButton(tooltip: tr('Annuler'), onPressed: _undo.isEmpty ? null : undo, icon: const Icon(Icons.undo)),
        IconButton(tooltip: tr('Rétablir'), onPressed: _redo.isEmpty ? null : redo, icon: const Icon(Icons.redo)),
        const SizedBox(width: 8),
        if (!d.htmlMode)
          SegmentedButton<bool>(
            showSelectedIcon: false,
            segments: [
              ButtonSegment(value: false, icon: Icon(Icons.desktop_windows_outlined), tooltip: tr('Ordinateur')),
              ButtonSegment(value: true, icon: Icon(Icons.smartphone), tooltip: tr('Mobile')),
            ],
            selected: {_mobile},
            onSelectionChanged: (s) => setState(() => _mobile = s.first),
          ),
        if (widget.allowHtml) ...[
          const SizedBox(width: 8),
          SegmentedButton<bool>(
            segments: [
              ButtonSegment(value: false, icon: Icon(Icons.dashboard_customize_outlined), label: Text(tr('Glisser-déposer'))),
              ButtonSegment(value: true, icon: Icon(Icons.code), label: Text(tr('HTML'))),
            ],
            selected: {d.htmlMode},
            onSelectionChanged: (s) => _switchMode(s.first),
          ),
        ],
        const SizedBox(width: 8),
        if (!d.htmlMode) TextButton.icon(onPressed: applyLayout, icon: const Icon(Icons.auto_awesome_mosaic_outlined), label: Text(tr('Mises en page'))),
      ]),
    );
  }

  Future<void> _switchMode(bool html) async {
    if (html == d.htmlMode) return;
    if (html && _html.text.trim().isEmpty && d.blocks.isNotEmpty) {
      final convert = await showDialog<bool>(
        context: context,
        builder: (c) => YAlertDialog(
          title: Text(tr('Passer en HTML')),
          content: Text(tr('Partir du design actuel (converti en HTML modifiable) ou d\'une page vide ?')),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Page vide'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Convertir le design'))),
          ],
        ),
      );
      if (convert == null || !mounted) return;
      if (convert) await _convertToHtml();
    }
    if (!mounted) return;
    if (!html && d.html.trim().isNotEmpty && !await confirm(context, tr('Revenir au glisser-déposer ?'), tr('Le HTML personnalisé ne sera plus utilisé (il reste disponible si vous revenez en mode HTML).'))) return;
    setState(() => d.htmlMode = html);
    widget.onChanged?.call();
  }

  Future<void> _convertToHtml() async {
    try {
      final r = mkMap(await context.read<Session>().api.post('marketing/preview', {'blocks': d.blocks, 'style': d.style, 'subject': widget.subject, 'preheader': widget.preheader}));
      // Les variables sont remplacées par l'aperçu : on les remet
      var html = '${r['html']}';
      for (final e in sampleVars.entries) {
        if (e.key != 'lien_desinscription') html = html.replaceAll(e.value, '{{${e.key}}}');
      }
      _html.text = html;
      d.html = html;
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Widget _htmlEditor(bool narrow) {
    final editor = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 4),
        child: Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
          OutlinedButton.icon(
            onPressed: () async {
              final r = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['html', 'htm']);
              if (r.isEmpty) return;
              final text = utf8.decode(await r.first.readAsBytes(), allowMalformed: true);
              if (!mounted) return;
              setState(() {
                _html.text = text;
                d.html = text;
              });
              widget.onChanged?.call();
            },
            icon: const Icon(Icons.file_open_outlined),
            label: Text(tr('Importer un fichier .html')),
          ),
          TextButton.icon(
            onPressed: () async {
              await _convertToHtml();
              if (mounted) setState(() {});
              widget.onChanged?.call();
            },
            icon: const Icon(Icons.transform),
            label: Text(tr('Repartir du design')),
          ),
          Text(tr('Variables : {{prenom}}, {{nom_complet}}, {{lien_desinscription}}…'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        ]),
      ),
      Expanded(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: TextField(
            controller: _html,
            expands: true,
            maxLines: null,
            textAlignVertical: TextAlignVertical.top,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 12.5, height: 1.4),
            decoration: InputDecoration(hintText: tr('<!doctype html>…'), alignLabelWithHint: true),
            onChanged: (v) {
              d.html = v;
              setState(() {});
              widget.onChanged?.call();
            },
          ),
        ),
      ),
    ]);
    final preview = Container(color: const Color(0xFFF4F6FB), padding: const EdgeInsets.all(12), child: HtmlFrame(html: fillVars(d.html, '${context.read<Session>().company['name'] ?? ''}')));
    if (narrow) {
      return DefaultTabController(
        length: 2,
        child: Column(children: [TabBar(tabs: [Tab(text: tr('Code')), Tab(text: tr('Aperçu'))]), Expanded(child: TabBarView(children: [editor, preview]))]),
      );
    }
    return Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [Expanded(child: editor), const VerticalDivider(width: 1), Expanded(child: preview)]);
  }

  // ---------------------------------------------------------------- panneau latéral

  Widget _sidePanel(bool sheet) {
    return Column(children: [
      TabBar(controller: _side, tabs: [Tab(text: tr('Ajouter')), Tab(text: tr('Réglages')), Tab(text: tr('Apparence'))]),
      Expanded(child: TabBarView(controller: _side, children: [_palette(sheet), _settings(sheet), _appearance()])),
    ]);
  }

  Widget _palette(bool sheet) {
    final hint = Theme.of(context).hintColor;
    final groups = <String, List<BlockMeta>>{};
    for (final b in blockCatalog) {
      groups.putIfAbsent(b.group, () => []).add(b);
    }
    final at = _selectedIndex >= 0 ? _selectedIndex + 1 : d.blocks.length;
    return ListView(padding: const EdgeInsets.all(12), children: [
      Text(tr(sheet ? 'Touchez un élément pour l\'ajouter.' : 'Glissez un élément sur l\'e-mail, ou cliquez pour l\'ajouter ${_selectedIndex >= 0 ? 'sous le bloc sélectionné' : 'à la fin'}.'),
          style: TextStyle(fontSize: 12, color: hint)),
      for (final g in groups.entries) ...[
        Padding(padding: const EdgeInsets.only(top: 14, bottom: 6), child: Text(tr(g.key.toUpperCase()), style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: .8, color: hint))),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final b in g.value)
            _PaletteTile(
              icon: b.icon,
              label: b.label,
              data: _Drag(blocks: [defaultBlock(b.type)]),
              onTap: () {
                _insert([defaultBlock(b.type)], at, openSettings: !sheet);
                if (sheet) Navigator.pop(context);
              },
            ),
        ]),
      ],
      Padding(padding: const EdgeInsets.only(top: 18, bottom: 6), child: Text(tr('SECTIONS PRÊTES'), style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: .8, color: hint))),
      for (final s in sectionPresets)
        _SectionTile(
          preset: s,
          data: _Drag(blocks: s.blocks),
          onTap: () {
            _insert(s.blocks, at, openSettings: false);
            if (sheet) Navigator.pop(context);
          },
        ),
    ]);
  }

  Widget _settings(bool sheet) {
    final i = _selectedIndex;
    if (i < 0) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(tr('Cliquez sur un bloc de l\'e-mail pour le modifier.'), textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).hintColor)),
        ),
      );
    }
    final b = d.blocks[i];
    final type = '${b['type']}';
    return ListView(padding: const EdgeInsets.all(12), children: [
      Row(children: [
        Icon(blockIcons[type], color: YColors.blue, size: 20),
        const SizedBox(width: 8),
        Expanded(child: Text(tr(blockLabels[type] ?? type), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
        IconButton(tooltip: tr('Monter'), visualDensity: VisualDensity.compact, onPressed: i == 0 ? null : () => _move(i, -1), icon: const Icon(Icons.arrow_upward, size: 18)),
        IconButton(tooltip: tr('Descendre'), visualDensity: VisualDensity.compact, onPressed: i == d.blocks.length - 1 ? null : () => _move(i, 1), icon: const Icon(Icons.arrow_downward, size: 18)),
        IconButton(tooltip: tr('Dupliquer'), visualDensity: VisualDensity.compact, onPressed: () => _duplicate(i), icon: const Icon(Icons.copy, size: 18)),
        IconButton(
          tooltip: tr('Supprimer'),
          visualDensity: VisualDensity.compact,
          onPressed: () {
            _delete(i);
            if (sheet) Navigator.pop(context);
          },
          icon: const Icon(Icons.delete_outline, size: 18, color: YColors.danger),
        ),
      ]),
      const SizedBox(height: 8),
      BlockSettings(
        block: b,
        onUpload: (done) => uploadMarketingImage(context, done),
        onChanged: (nb) => _commit(() {
          final j = d.blocks.indexWhere((x) => blockId(x) == blockId(b));
          if (j >= 0) d.blocks[j] = nb;
        }, editKey: blockId(b)),
      ),
    ]);
  }

  Widget _appearance() {
    const primaries = ['#1F6FEB', '#0B1B3A', '#12A150', '#E8A317', '#D93025', '#7B3FE4', '#E4407B', '#0E7C86'];
    const bgs = ['#F4F6FB', '#FFFFFF', '#FFF7DB', '#EEF7F0', '#F3EEFF', '#FDECEF', '#0B1B3A', '#13274F'];
    const fonts = {'Arial, Helvetica, sans-serif': 'Arial', 'Georgia, serif': 'Georgia', 'Verdana, sans-serif': 'Verdana', 'Trebuchet MS, sans-serif': 'Trebuchet', 'Tahoma, sans-serif': 'Tahoma'};
    void set(String k, dynamic v) => _commit(() => d.style = {...d.style, k: v});
    Widget swatches(String key, List<String> colors, String def) => Wrap(spacing: 8, runSpacing: 8, children: [
          for (final c in colors)
            InkWell(
              onTap: () => set(key, c),
              child: Container(
                width: 28,
                height: 28,
                decoration: BoxDecoration(
                  color: hexColor(c, Colors.white),
                  shape: BoxShape.circle,
                  border: Border.all(color: (d.style[key] ?? def) == c ? YColors.blue : YColors.line, width: (d.style[key] ?? def) == c ? 3 : 1),
                ),
              ),
            ),
        ]);
    return ListView(padding: const EdgeInsets.all(12), children: [
      Text(tr('Couleur principale'), style: TextStyle(fontWeight: FontWeight.w600)),
      const SizedBox(height: 8),
      swatches('primary', primaries, '#1F6FEB'),
      const SizedBox(height: 16),
      Text(tr('Fond'), style: TextStyle(fontWeight: FontWeight.w600)),
      const SizedBox(height: 8),
      swatches('background', bgs, '#F4F6FB'),
      const SizedBox(height: 16),
      Text(tr('Couleur du texte'), style: TextStyle(fontWeight: FontWeight.w600)),
      const SizedBox(height: 8),
      swatches('text', const ['#0B1B3A', '#333333', '#5B6B85', '#000000'], '#0B1B3A'),
      const SizedBox(height: 16),
      DropdownButtonFormField<String>(
        initialValue: fonts.containsKey(d.style['font']) ? d.style['font'] as String : 'Arial, Helvetica, sans-serif',
        decoration: InputDecoration(labelText: tr('Police')),
        items: [for (final f in fonts.entries) DropdownMenuItem(value: f.key, child: Text(tr(f.value), style: TextStyle(fontFamily: f.value)))],
        onChanged: (v) => set('font', v),
      ),
      const SizedBox(height: 12),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('En-tête avec logo')), value: d.style['show_header'] != false, onChanged: (v) => set('show_header', v)),
      Row(children: [
        OutlinedButton.icon(onPressed: () => uploadMarketingImage(context, (u) => set('logo', u)), icon: const Icon(Icons.upload, size: 18), label: Text(tr(d.style['logo'] == null ? 'Logo spécifique' : 'Changer le logo'))),
        if (d.style['logo'] != null) TextButton(onPressed: () => _commit(() => d.style = {...d.style}..remove('logo')), child: Text(tr('Logo de la société'))),
      ]),
    ]);
  }

  void _openSheet(int tab) {
    _side.index = tab;
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (c) => SizedBox(height: MediaQuery.of(c).size.height * .75, child: StatefulBuilder(builder: (c, _) => _sidePanel(true))),
    ).then((_) => setState(() {}));
  }

  // ---------------------------------------------------------------- canevas

  Widget _canvas(bool narrow) {
    final company = '${context.read<Session>().company['name'] ?? ''}';
    final mobile = _mobile || narrow;
    final width = mobile ? 380.0 : 600.0;
    return Container(
      color: hexColor(d.style['background'], const Color(0xFFF4F6FB)),
      child: GestureDetector(
        onTap: () => setState(() => _selected = null),
        child: ListView(padding: EdgeInsets.fromLTRB(12, 20, 12, narrow ? 96 : 40), children: [
          Center(
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 250),
              width: width,
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (widget.showInbox) InboxHeader(subject: widget.subject, preheader: widget.preheader, style: d.style),
                if (widget.hint != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(tr(widget.hint!), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))),
                EmailCard(
                  style: d.style,
                  narrow: mobile,
                  footer: widget.footer,
                  children: [
                    for (var i = 0; i < d.blocks.length; i++)
                      _BlockFrame(
                        key: ValueKey(blockId(d.blocks[i])),
                        index: i,
                        count: d.blocks.length,
                        selected: blockId(d.blocks[i]) == _selected,
                        touch: narrow,
                        label: blockLabels['${d.blocks[i]['type']}'] ?? '',
                        onTap: () => _select(blockId(d.blocks[i]), narrow: narrow),
                        onDrop: _drop,
                        onMove: (delta) => _move(i, delta),
                        onDuplicate: () => _duplicate(i),
                        onDelete: () => _delete(i),
                        child: EmailBlockView(block: d.blocks[i], style: d.style, company: company, narrow: mobile),
                      ),
                    _EndZone(empty: d.blocks.isEmpty, onDrop: (data) => _drop(data, d.blocks.length), onLayout: applyLayout),
                  ],
                ),
              ]),
            ),
          ),
        ]),
      ),
    );
  }
}

class _PaletteTile extends StatelessWidget {
  const _PaletteTile({required this.icon, required this.label, required this.data, required this.onTap});

  final IconData icon;
  final String label;
  final _Drag data;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final tile = Container(
      width: 92,
      height: 76,
      decoration: BoxDecoration(color: Theme.of(context).colorScheme.surface, borderRadius: BorderRadius.circular(10), border: Border.all(color: YColors.line)),
      child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
        Icon(icon, color: YColors.blue),
        const SizedBox(height: 6),
        Text(tr(label), textAlign: TextAlign.center, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
      ]),
    );
    return Draggable<_Drag>(
      data: data,
      dragAnchorStrategy: pointerDragAnchorStrategy,
      feedback: _DragChip(icon: icon, label: label),
      childWhenDragging: Opacity(opacity: .4, child: tile),
      child: MouseRegion(cursor: SystemMouseCursors.grab, child: InkWell(borderRadius: BorderRadius.circular(10), onTap: onTap, child: tile)),
    );
  }
}

class _SectionTile extends StatelessWidget {
  const _SectionTile({required this.preset, required this.data, required this.onTap});

  final SectionPreset preset;
  final _Drag data;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final tile = Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        dense: true,
        leading: Icon(preset.icon, color: YColors.blue),
        title: Text(tr(preset.name), style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(tr(preset.blocks.map((b) => blockLabels['${b['type']}'] ?? '').join(' + ')), style: const TextStyle(fontSize: 12)),
        onTap: onTap,
      ),
    );
    return Draggable<_Drag>(
      data: data,
      dragAnchorStrategy: pointerDragAnchorStrategy,
      feedback: _DragChip(icon: preset.icon, label: preset.name),
      childWhenDragging: Opacity(opacity: .4, child: tile),
      child: MouseRegion(cursor: SystemMouseCursors.grab, child: tile),
    );
  }
}

class _DragChip extends StatelessWidget {
  const _DragChip({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Material(
        color: Colors.transparent,
        child: Transform.translate(
          offset: const Offset(8, 8),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(color: YColors.blue, borderRadius: BorderRadius.circular(20), boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 8)]),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(icon, color: Colors.white, size: 18),
              const SizedBox(width: 6),
              Text(tr(label), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
            ]),
          ),
        ),
      );
}

/// Bloc posé sur l'e-mail : sélection, survol, barre d'outils, cible de dépôt (au-dessus / en dessous).
class _BlockFrame extends StatefulWidget {
  const _BlockFrame({
    super.key,
    required this.index,
    required this.count,
    required this.selected,
    required this.touch,
    required this.label,
    required this.onTap,
    required this.onDrop,
    required this.onMove,
    required this.onDuplicate,
    required this.onDelete,
    required this.child,
  });

  final int index;
  final int count;
  final bool selected;
  final bool touch;
  final String label;
  final VoidCallback onTap;
  final void Function(_Drag, int) onDrop;
  final void Function(int) onMove;
  final VoidCallback onDuplicate;
  final VoidCallback onDelete;
  final Widget child;

  @override
  State<_BlockFrame> createState() => _BlockFrameState();
}

class _BlockFrameState extends State<_BlockFrame> {
  bool _hover = false;
  bool? _before; // position de dépôt pendant un glisser

  void _track(Offset global) {
    final box = context.findRenderObject() as RenderBox?;
    if (box == null) return;
    final local = box.globalToLocal(global);
    final before = local.dy < box.size.height / 2;
    if (before != _before) setState(() => _before = before);
  }

  @override
  Widget build(BuildContext context) {
    final active = widget.selected || _hover;
    Widget tool(IconData icon, String tip, VoidCallback? onTap, {Color? color}) => InkWell(
          onTap: onTap,
          child: Tooltip(message: tip, child: Padding(padding: const EdgeInsets.all(5), child: Icon(icon, size: 16, color: onTap == null ? Colors.white38 : (color ?? Colors.white)))),
        );
    final body = GestureDetector(
      onTap: widget.onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        foregroundDecoration: BoxDecoration(
          border: Border.all(color: widget.selected ? YColors.blue : (_hover ? YColors.blue.withValues(alpha: .45) : Colors.transparent), width: widget.selected ? 2 : 1),
        ),
        child: widget.child,
      ),
    );
    final draggable = widget.touch
        ? LongPressDraggable<_Drag>(
            data: _Drag(from: widget.index),
            dragAnchorStrategy: pointerDragAnchorStrategy,
            feedback: _DragChip(icon: Icons.open_with, label: widget.label),
            childWhenDragging: Opacity(opacity: .3, child: body),
            child: body,
          )
        : Draggable<_Drag>(
            data: _Drag(from: widget.index),
            dragAnchorStrategy: pointerDragAnchorStrategy,
            feedback: _DragChip(icon: Icons.open_with, label: widget.label),
            childWhenDragging: Opacity(opacity: .3, child: body),
            child: body,
          );
    return DragTarget<_Drag>(
      onWillAcceptWithDetails: (d) => d.data.from != widget.index,
      onMove: (d) => _track(d.offset),
      onLeave: (_) => setState(() => _before = null),
      onAcceptWithDetails: (d) {
        final at = (_before ?? true) ? widget.index : widget.index + 1;
        setState(() => _before = null);
        widget.onDrop(d.data, at);
      },
      builder: (context, candidates, _) => MouseRegion(
        onEnter: (_) => setState(() => _hover = true),
        onExit: (_) => setState(() => _hover = false),
        cursor: SystemMouseCursors.click,
        child: Stack(clipBehavior: Clip.none, children: [
          draggable,
          if (active && !widget.touch)
            Positioned(
              left: 6,
              top: 4,
              child: IgnorePointer(
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(color: YColors.blue, borderRadius: BorderRadius.circular(4)),
                  child: Text(tr(widget.label), style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600)),
                ),
              ),
            ),
          if (active)
            Positioned(
              right: 6,
              top: 4,
              child: Container(
                decoration: BoxDecoration(color: YColors.night, borderRadius: BorderRadius.circular(6)),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  if (!widget.touch) const Padding(padding: EdgeInsets.all(5), child: MouseRegion(cursor: SystemMouseCursors.grab, child: Icon(Icons.drag_indicator, size: 16, color: Colors.white))),
                  tool(Icons.arrow_upward, 'Monter', widget.index == 0 ? null : () => widget.onMove(-1)),
                  tool(Icons.arrow_downward, 'Descendre', widget.index == widget.count - 1 ? null : () => widget.onMove(1)),
                  tool(Icons.copy, 'Dupliquer', widget.onDuplicate),
                  tool(Icons.delete_outline, 'Supprimer', widget.onDelete, color: const Color(0xFFFF8A80)),
                ]),
              ),
            ),
          if (candidates.isNotEmpty && _before != null)
            Positioned(
              left: 0,
              right: 0,
              top: _before! ? -3 : null,
              bottom: _before! ? null : -3,
              child: IgnorePointer(
                child: Container(
                  height: 6,
                  decoration: BoxDecoration(color: YColors.blue, borderRadius: BorderRadius.circular(3), boxShadow: [BoxShadow(color: YColors.blue.withValues(alpha: .4), blurRadius: 6)]),
                ),
              ),
            ),
        ]),
      ),
    );
  }
}

class _EndZone extends StatelessWidget {
  const _EndZone({required this.empty, required this.onDrop, required this.onLayout});

  final bool empty;
  final void Function(_Drag) onDrop;
  final VoidCallback onLayout;

  @override
  Widget build(BuildContext context) {
    return DragTarget<_Drag>(
      onAcceptWithDetails: (d) => onDrop(d.data),
      builder: (context, cand, _) => AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        margin: const EdgeInsets.symmetric(horizontal: 24, vertical: 10),
        height: empty ? 220 : (cand.isNotEmpty ? 70 : 44),
        decoration: BoxDecoration(
          color: cand.isNotEmpty ? YColors.blue.withValues(alpha: .08) : Colors.transparent,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: cand.isNotEmpty ? YColors.blue : YColors.line, width: cand.isNotEmpty ? 2 : 1),
        ),
        alignment: Alignment.center,
        child: empty
            ? Column(mainAxisSize: MainAxisSize.min, children: [
                const Icon(Icons.dashboard_customize_outlined, size: 40, color: YColors.blue),
                const SizedBox(height: 8),
                Text(tr('Glissez des blocs ici'), style: TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                Text.rich(
                  TextSpan(children: [
                    TextSpan(text: tr('ou partez d\'une ')),
                    TextSpan(text: tr('mise en page prête'), style: const TextStyle(color: YColors.blue, decoration: TextDecoration.underline), recognizer: TapGestureRecognizer()..onTap = onLayout),
                  ]),
                  style: const TextStyle(fontSize: 13, color: YColors.muted),
                ),
              ])
            : Text(tr(cand.isNotEmpty ? 'Déposer ici' : '+ Déposez un bloc à la fin'), style: TextStyle(fontSize: 12, color: cand.isNotEmpty ? YColors.blue : YColors.muted)),
      ),
    );
  }
}
