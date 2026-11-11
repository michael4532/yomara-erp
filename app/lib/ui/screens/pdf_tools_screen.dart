import 'dart:convert';
import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/pdf_viewer.dart';
import '../widgets/common.dart';
import 'pdf_editor_screen.dart';

/// Un outil PDF : opération côté serveur, couleur, description.
class _Tool {
  const _Tool(this.op, this.title, this.desc, this.icon, this.color, {this.multi = false, this.images = false});
  final String op;
  final String title;
  final String desc;
  final IconData icon;
  final Color color;
  final bool multi;
  final bool images;
}

const _tools = [
  _Tool('edit', 'Modifier le PDF', 'Corriger un texte, masquer, surligner, signer, ajouter un cachet, cocher, supprimer des pages.', Icons.edit_document, Color(0xFFE5484D)),
  _Tool('merge', 'Fusionner', 'Réunir plusieurs PDF en un seul, dans l\'ordre choisi.', Icons.merge_type, Color(0xFF2E6BE6), multi: true),
  _Tool('organize', 'Organiser les pages', 'Réordonner, supprimer et pivoter les pages, une par une.', Icons.view_module_outlined, Color(0xFF9333EA)),
  _Tool('split', 'Découper', 'Séparer en plusieurs PDF : par groupes de pages ou toutes les N pages.', Icons.call_split, Color(0xFF0EA5E9)),
  _Tool('watermark', 'Filigrane', 'CONFIDENTIEL, COPIE ou votre logo, en transparence.', Icons.branding_watermark_outlined, Color(0xFFE5484D)),
  _Tool('annotate', 'Ajouter texte, image, signature', 'Écrire sur le document, apposer un tampon ou une signature.', Icons.draw_outlined, Color(0xFF12B76A)),
  _Tool('numbers', 'Numéroter les pages', '« Page 1 sur 12 » en bas ou en haut de chaque page.', Icons.format_list_numbered, Color(0xFF0F766E)),
  _Tool('protect', 'Protéger par mot de passe', 'Chiffrement AES-256, impression et copie autorisées ou non.', Icons.lock_outline, Color(0xFF0A1633)),
  _Tool('unlock', 'Retirer le mot de passe', 'Enlever la protection d\'un PDF dont vous connaissez le mot de passe.', Icons.lock_open_outlined, Color(0xFF64748B)),
  _Tool('rotate', 'Pivoter', 'Tourner tout le document ou certaines pages.', Icons.rotate_90_degrees_cw_outlined, Color(0xFFF5A000)),
  _Tool('images', 'Images en PDF', 'Photos et scans (JPG, PNG) réunis en un PDF propre.', Icons.photo_library_outlined, Color(0xFFD946EF), images: true),
];

/// Outils PDF : fusion, découpe, organisation, filigrane, ajouts, numérotation, mot de passe.
class PdfToolsScreen extends StatelessWidget {
  const PdfToolsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return ListView(padding: const EdgeInsets.only(bottom: 40), children: [
      PageHeader(title: tr('Outils PDF'), subtitle: tr('Vos fichiers sont traités puis effacés aussitôt : rien n\'est conservé'), icon: Icons.picture_as_pdf_outlined),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: LayoutBuilder(builder: (context, c) {
          final cols = c.maxWidth > 1100 ? 4 : (c.maxWidth > 760 ? 3 : (c.maxWidth > 420 ? 2 : 1));
          final w = (c.maxWidth - (cols - 1) * 12) / cols;
          return Wrap(spacing: 12, runSpacing: 12, children: [
            for (final (i, t) in _tools.indexed)
              SizedBox(
                width: w,
                child: AnimatedFadeIn(
                  index: i,
                  child: HoverLift(
                    child: Card(
                      margin: EdgeInsets.zero,
                      clipBehavior: Clip.antiAlias,
                      child: InkWell(
                        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => t.op == 'edit' ? const PdfEditorScreen() : _ToolPage(tool: t))),
                        child: Padding(
                          padding: const EdgeInsets.all(18),
                          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Container(
                              width: 48,
                              height: 48,
                              decoration: BoxDecoration(color: t.color.withValues(alpha: .12), borderRadius: BorderRadius.circular(14)),
                              child: Icon(t.icon, color: t.color),
                            ),
                            const SizedBox(width: 14),
                            Expanded(
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text(tr(t.title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                                const SizedBox(height: 4),
                                Text(tr(t.desc), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13, height: 1.35)),
                              ]),
                            ),
                          ]),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
          ]);
        }),
      ),
    ]);
  }
}

class _Doc {
  _Doc(this.name, this.bytes);
  final String name;
  final Uint8List bytes;
  int pages = 0;
  final List<Uint8List> thumbs = [];
}

class _ToolPage extends StatefulWidget {
  const _ToolPage({super.key, required this.tool, this.onSaved});
  final _Tool tool;
  final Future<void> Function(Uint8List bytes, String name)? onSaved;

  @override
  State<_ToolPage> createState() => _ToolPageState();
}

class _ToolPageState extends State<_ToolPage> {
  final List<_Doc> _docs = [];
  bool _busy = false;
  String? _loading;

  // Options
  final _pages = TextEditingController();
  final _groups = TextEditingController();
  int _every = 0;
  int _angle = 90;
  final _wmText = TextEditingController(text: 'CONFIDENTIEL');
  String _wmMode = 'diagonal';
  double _opacity = .2;
  double _size = 60;
  String _color = '#787878';
  Uint8List? _wmImage;
  String _numFormat = 'Page {n} sur {total}';
  String _numPos = 'bottom-center';
  final _pwd = TextEditingController();
  final _pwd2 = TextEditingController();
  final Set<String> _allow = {'print'};
  String _imgFormat = 'A4';
  // Organisation : ordre des pages (numéros d'origine), rotations, pages retirées
  List<int> _order = [];
  final Map<int, int> _rot = {};
  final Set<int> _removed = {};
  // Ajouts
  final List<Map<String, dynamic>> _items = [];

  _Tool get t => widget.tool;

  Future<void> _pick() async {
    final res = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: t.images ? ['jpg', 'jpeg', 'png'] : ['pdf']);
    if (res.isEmpty || !mounted) return;
    for (final f in res) {
      final b = Uint8List.fromList(await f.readAsBytes());
      final d = _Doc(f.name, b);
      if (mounted) setState(() => _docs.add(d));
      if (!t.images) await _inspect(d);
      if (!t.multi && !t.images) break;
    }
  }

  /// Nombre de pages et vignettes (rendues sur l'appareil).
  Future<void> _inspect(_Doc d) async {
    setState(() => _loading = d.name);
    try {
      var i = 0;
      final want = t.op == 'organize' ? 400 : 1;
      await for (final p in Printing.raster(d.bytes, dpi: t.op == 'organize' ? 30 : 40)) {
        if (i < want) d.thumbs.add(await p.toPng());
        i++;
      }
      d.pages = i;
      if (t.op == 'organize') _order = [for (var k = 1; k <= i; k++) k];
    } catch (_) {
      // Aperçu impossible (PDF protégé…) : le serveur le dira
    } finally {
      if (mounted) setState(() => _loading = null);
    }
  }

  Map<String, dynamic> _options() => switch (t.op) {
        'organize' => {'order': [for (final p in _order) if (!_removed.contains(p)) p].join(','), 'rotations': {for (final e in _rot.entries) '${e.key}': e.value}},
        'rotate' => {'angle': _angle, 'pages': _pages.text},
        'split' => {'groups': _groups.text, 'every': _every},
        'watermark' => {'text': _wmText.text, 'mode': _wmMode, 'opacity': _opacity, 'size': _size.round(), 'color': _color, 'pages': _pages.text},
        'numbers' => {'format': _numFormat, 'position': _numPos},
        'protect' => {'password': _pwd.text, 'allow': _allow.toList()},
        'unlock' => {'password': _pwd.text},
        'images' => {'format': _imgFormat},
        'annotate' => {
            'items': [
              for (final it in _items)
                {...(Map.of(it)..remove('bytes')), if (it['type'] == 'image') 'image_index': _items.where((x) => x['type'] == 'image').toList().indexOf(it)},
            ],
          },
        _ => {},
      };

  Future<void> _run() async {
    if (_docs.isEmpty) return toast(context, tr(t.images ? 'Ajoutez au moins une image.' : 'Ajoutez un fichier PDF.'), error: true);
    if (t.op == 'protect' && (_pwd.text.length < 4 || _pwd.text != _pwd2.text)) {
      return toast(context, tr(_pwd.text.length < 4 ? 'Mot de passe : 4 caractères au minimum.' : 'Les deux mots de passe ne correspondent pas.'), error: true);
    }
    setState(() => _busy = true);
    try {
      final images = [
        if (t.images) for (final d in _docs) d,
      ];
      final annotImages = [
        for (final it in _items)
          if (it['type'] == 'image') it['bytes'] as Uint8List,
      ];
      final files = <(String, List<int>, String)>[
        if (!t.images) for (final (i, d) in _docs.indexed) ('files[$i]', d.bytes, d.name),
        for (final (i, d) in images.indexed) ('images[$i]', d.bytes, d.name),
        for (final (i, b) in annotImages.indexed) ('images[$i]', b, 'image$i.png'),
        if (t.op == 'watermark' && _wmImage != null) ('image', _wmImage!, 'filigrane.png'),
      ];
      final (bytes, name) = await context.read<Session>().api.uploadForFile('pdf/${t.op}', {'options': jsonEncode(_options())}, files);
      if (!mounted) return;
      await _result(Uint8List.fromList(bytes), name ?? 'document.pdf');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _result(Uint8List bytes, String name) async {
    final isPdf = name.endsWith('.pdf');
    // Ouvert depuis un document : le résultat le remplace (nouvelle version), on revient à la lecture
    if (isPdf && widget.onSaved != null) {
      await widget.onSaved!(bytes, name);
      if (mounted) Navigator.of(context).pop();
      return;
    }
    Uint8List? thumb;
    if (isPdf && t.op != 'protect') {
      try {
        await for (final p in Printing.raster(bytes, pages: [0], dpi: 50)) {
          thumb = await p.toPng();
        }
      } catch (_) {}
    }
    if (!mounted) return;
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.check_circle, color: YColors.success, size: 40),
        title: Text(tr('C\'est prêt !')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          if (thumb != null)
            Container(
              height: 220,
              margin: const EdgeInsets.only(bottom: 12),
              decoration: BoxDecoration(border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(8)),
              child: Image.memory(thumb),
            ),
          Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
          Text('${(bytes.length / 1024).toStringAsFixed(0)} Ko', style: TextStyle(color: Theme.of(ctx).hintColor)),
        ]),
        actions: [
          if (isPdf && t.op != 'protect')
            TextButton.icon(
              onPressed: () => showPdf(context, bytes, name),
              icon: const Icon(Icons.visibility_outlined),
              label: Text(tr('Ouvrir')),
            ),
          if (isPdf && t.op != 'protect')
            TextButton.icon(
              onPressed: () {
                Navigator.pop(ctx);
                Navigator.of(context).push(MaterialPageRoute(builder: (_) => PdfToolsScreenPicker(bytes: bytes, name: name)));
              },
              icon: const Icon(Icons.auto_fix_high),
              label: Text(tr('Autre outil sur ce fichier')),
            ),
          FilledButton.icon(
            onPressed: () async {
              await saveFile(name, bytes, mime: isPdf ? 'application/pdf' : 'application/zip');
              if (ctx.mounted) toast(ctx, tr('Fichier enregistré.'));
            },
            icon: const Icon(Icons.download),
            label: Text(tr('Télécharger')),
          ),
        ],
      ),
    );
  }

  Future<void> _addItem(String type) async {
    if (type == 'image') {
      final res = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['png', 'jpg', 'jpeg']);
      if (res.isEmpty) return;
      final b = Uint8List.fromList(await res.first.readAsBytes());
      if (mounted) setState(() => _items.add({'type': 'image', 'page': 1, 'x': 60.0, 'y': 80.0, 'width': 25.0, 'bytes': b}));
    } else {
      if (mounted) setState(() => _items.add({'type': 'text', 'page': 1, 'x': 10.0, 'y': 85.0, 'text': 'Lu et approuvé', 'size': 12, 'color': '#111A2E', 'bold': false}));
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: Row(children: [Icon(t.icon, color: t.color), const SizedBox(width: 10), Text(tr(t.title))])),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: SizedBox(
            height: 52,
            child: FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: t.color, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
              onPressed: _busy || _docs.isEmpty ? null : _run,
              icon: _busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Icon(t.icon),
              label: Text(tr(_busy ? 'Traitement…' : t.title), style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
          ),
        ),
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 900),
          child: ListView(padding: const EdgeInsets.all(16), children: [
            Text(tr(t.desc), style: TextStyle(color: theme.hintColor)),
            const SizedBox(height: 14),
            // Zone de dépôt
            InkWell(
              borderRadius: BorderRadius.circular(18),
              onTap: (_docs.isNotEmpty && !t.multi && !t.images) ? null : _pick,
              child: Container(
                padding: const EdgeInsets.symmetric(vertical: 26, horizontal: 16),
                decoration: BoxDecoration(
                  color: t.color.withValues(alpha: .05),
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: t.color.withValues(alpha: .45), width: 1.6),
                ),
                child: Column(children: [
                  Icon(t.images ? Icons.add_photo_alternate_outlined : Icons.upload_file, size: 38, color: t.color),
                  const SizedBox(height: 8),
                  Text(
                    tr(_docs.isEmpty
                        ? (t.images ? 'Choisir des images (JPG, PNG)' : (t.multi ? 'Choisir les PDF à fusionner' : 'Choisir un PDF'))
                        : (t.multi || t.images ? 'Ajouter d\'autres fichiers' : 'Fichier choisi')),
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  if (_loading != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Lecture de $_loading…'), style: TextStyle(color: theme.hintColor))),
                ]),
              ),
            ),
            const SizedBox(height: 12),
            if (_docs.isNotEmpty && t.op != 'organize')
              ReorderableListView(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                onReorderItem: (a, b) => setState(() => _docs.insert(b, _docs.removeAt(a))),
                children: [
                  for (final (i, d) in _docs.indexed)
                    Card(
                      key: ValueKey('${d.name}$i'),
                      margin: const EdgeInsets.symmetric(vertical: 4),
                      child: ListTile(
                        leading: d.thumbs.isNotEmpty
                            ? ClipRRect(borderRadius: BorderRadius.circular(4), child: Image.memory(d.thumbs.first, width: 40, height: 52, fit: BoxFit.cover))
                            : t.images
                                ? ClipRRect(borderRadius: BorderRadius.circular(4), child: Image.memory(d.bytes, width: 40, height: 52, fit: BoxFit.cover))
                                : const Icon(Icons.picture_as_pdf, color: YColors.danger),
                        title: Text(d.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                        subtitle: Text(tr([if (d.pages > 0) '${d.pages} page(s)', '${(d.bytes.length / 1024).toStringAsFixed(0)} Ko'].join(' · '))),
                        trailing: IconButton(onPressed: () => setState(() => _docs.removeAt(i)), icon: const Icon(Icons.close)),
                      ),
                    ),
                ],
              ),
            if (_docs.isNotEmpty) ...[
              const SizedBox(height: 10),
              ..._optionsUi(theme),
            ],
          ]),
        ),
      ),
    );
  }

  List<Widget> _optionsUi(ThemeData theme) {
    Widget label(String s) => Padding(padding: const EdgeInsets.only(top: 12, bottom: 8), child: Text(tr(s), style: const TextStyle(fontWeight: FontWeight.w800)));
    switch (t.op) {
      case 'organize':
        final d = _docs.first;
        if (d.thumbs.isEmpty) return [Text(tr('Lecture des pages…'))];
        return [
          label('Touchez une page pour la pivoter, la croix pour la retirer, faites glisser pour changer l\'ordre.'),
          SizedBox(
            height: 210,
            child: ReorderableListView(
              scrollDirection: Axis.horizontal,
              onReorderItem: (a, b) => setState(() => _order.insert(b, _order.removeAt(a))),
              children: [
                for (final p in _order)
                  Padding(
                    key: ValueKey(p),
                    padding: const EdgeInsets.only(right: 10),
                    child: Opacity(
                      opacity: _removed.contains(p) ? .3 : 1,
                      child: Column(children: [
                        Stack(children: [
                          InkWell(
                            onTap: () => setState(() => _rot[p] = ((_rot[p] ?? 0) + 90) % 360),
                            child: Container(
                              width: 120,
                              height: 160,
                              decoration: BoxDecoration(color: Colors.white, border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(6)),
                              child: AnimatedRotation(turns: (_rot[p] ?? 0) / 360, duration: const Duration(milliseconds: 250), child: Image.memory(d.thumbs[p - 1], fit: BoxFit.contain)),
                            ),
                          ),
                          Positioned(
                            right: 2,
                            top: 2,
                            child: InkWell(
                              onTap: () => setState(() => _removed.contains(p) ? _removed.remove(p) : _removed.add(p)),
                              child: CircleAvatar(radius: 12, backgroundColor: _removed.contains(p) ? YColors.success : YColors.danger, child: Icon(_removed.contains(p) ? Icons.undo : Icons.close, size: 14, color: Colors.white)),
                            ),
                          ),
                        ]),
                        const SizedBox(height: 6),
                        Text(tr('Page $p${(_rot[p] ?? 0) != 0 ? ' · ${_rot[p]}°' : ''}'), style: const TextStyle(fontSize: 12)),
                      ]),
                    ),
                  ),
              ],
            ),
          ),
          Text(tr('${_order.length - _removed.length} page(s) dans le nouveau document.'), style: TextStyle(color: theme.hintColor)),
        ];
      case 'rotate':
        return [
          label('Sens et angle'),
          SegmentedButton<int>(
            segments: const [ButtonSegment(value: 90, label: Text('↻ 90°')), ButtonSegment(value: 180, label: Text('180°')), ButtonSegment(value: 270, label: Text('↺ 90°'))],
            selected: {_angle},
            onSelectionChanged: (v) => setState(() => _angle = v.first),
          ),
          const SizedBox(height: 12),
          TextField(controller: _pages, decoration: InputDecoration(labelText: tr('Pages (vide = toutes)'), hintText: '1-3, 5, 8-fin')),
        ];
      case 'split':
        return [
          label('Comment découper ?'),
          SegmentedButton<int>(
            segments: [ButtonSegment(value: 0, label: Text(tr('Groupes de pages'))), ButtonSegment(value: 1, label: Text(tr('Chaque page'))), ButtonSegment(value: 2, label: Text(tr('Toutes les 2 pages')))],
            selected: {_every},
            onSelectionChanged: (v) => setState(() => _every = v.first),
          ),
          if (_every == 0) ...[
            const SizedBox(height: 12),
            TextField(controller: _groups, decoration: InputDecoration(labelText: tr('Groupes (un PDF par groupe, séparés par ;)'), hintText: '1-2; 3-5; 6-fin')),
          ],
          const SizedBox(height: 8),
          Text(tr('Vous recevrez un fichier .zip contenant les PDF.'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
        ];
      case 'watermark':
        return [
          TextField(controller: _wmText, decoration: InputDecoration(labelText: tr('Texte du filigrane'))),
          Row(children: [
            TextButton.icon(
              onPressed: () async {
                final r = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['png', 'jpg', 'jpeg']);
                if (r.isNotEmpty) {
                  final b = Uint8List.fromList(await r.first.readAsBytes());
                  if (mounted) setState(() => _wmImage = b);
                }
              },
              icon: const Icon(Icons.image_outlined),
              label: Text(tr(_wmImage == null ? 'Ou utiliser une image (logo)' : 'Image choisie : changer')),
            ),
            if (_wmImage != null) IconButton(onPressed: () => setState(() => _wmImage = null), icon: const Icon(Icons.close)),
          ]),
          label('Disposition'),
          SegmentedButton<String>(
            segments: [
              ButtonSegment(value: 'diagonal', label: Text(tr('Diagonale'))),
              ButtonSegment(value: 'tile', label: Text(tr('Mosaïque'))),
              ButtonSegment(value: 'center', label: Text(tr('Centre'))),
              ButtonSegment(value: 'bottom', label: Text(tr('Bas'))),
            ],
            selected: {_wmMode},
            onSelectionChanged: (v) => setState(() => _wmMode = v.first),
          ),
          label('Transparence : ${(_opacity * 100).round()} %'),
          Slider(value: _opacity, min: .05, max: 1, onChanged: (v) => setState(() => _opacity = v)),
          label('Taille du texte : ${_size.round()}'),
          Slider(value: _size, min: 12, max: 140, onChanged: (v) => setState(() => _size = v)),
          Wrap(spacing: 8, children: [
            for (final c in ['#787878', '#E5484D', '#2E6BE6', '#0A1633', '#12B76A'])
              InkWell(
                onTap: () => setState(() => _color = c),
                child: Container(width: 30, height: 30, decoration: BoxDecoration(color: Color(int.parse('FF${c.substring(1)}', radix: 16)), shape: BoxShape.circle, border: Border.all(color: _color == c ? YColors.yellow : Colors.transparent, width: 3))),
              ),
          ]),
          const SizedBox(height: 12),
          TextField(controller: _pages, decoration: InputDecoration(labelText: tr('Pages (vide = toutes)'), hintText: '1-3, 5')),
          const SizedBox(height: 12),
          // Aperçu indicatif sur la première page
          if (_docs.first.thumbs.isNotEmpty)
            Center(
              child: Container(
                width: 200,
                decoration: BoxDecoration(border: Border.all(color: YColors.line)),
                child: Stack(alignment: Alignment.center, children: [
                  Image.memory(_docs.first.thumbs.first),
                  if (_wmImage == null)
                    Transform.rotate(
                      angle: _wmMode == 'diagonal' ? -.9 : 0,
                      child: Text(_wmText.text, style: TextStyle(fontSize: _size / 4, fontWeight: FontWeight.w900, color: Color(int.parse('FF${_color.substring(1)}', radix: 16)).withValues(alpha: _opacity))),
                    )
                  else
                    Opacity(opacity: _opacity, child: Image.memory(_wmImage!, width: 100)),
                ]),
              ),
            ),
        ];
      case 'annotate':
        return [
          label('Éléments à ajouter (position en % de la page, depuis le coin en haut à gauche)'),
          for (final (i, it) in _items.indexed)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(children: [
                  Row(children: [
                    Icon(it['type'] == 'image' ? Icons.image_outlined : Icons.text_fields),
                    const SizedBox(width: 8),
                    Expanded(
                      child: it['type'] == 'image'
                          ? Row(children: [Image.memory(it['bytes'] as Uint8List, height: 36), const SizedBox(width: 8), Text(tr('Image / signature'))])
                          : TextFormField(initialValue: '${it['text']}', decoration: InputDecoration(labelText: tr('Texte'), isDense: true), onChanged: (v) => it['text'] = v),
                    ),
                    IconButton(onPressed: () => setState(() => _items.removeAt(i)), icon: const Icon(Icons.close)),
                  ]),
                  const SizedBox(height: 8),
                  Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    SizedBox(
                      width: 110,
                      child: TextFormField(
                        initialValue: '${it['page']}',
                        decoration: InputDecoration(labelText: tr('Page (ou all)'), isDense: true),
                        onChanged: (v) => it['page'] = v.trim() == 'all' ? 'all' : (int.tryParse(v) ?? 1),
                      ),
                    ),
                    SizedBox(width: 180, child: Row(children: [Text(tr('X')), Expanded(child: Slider(value: (it['x'] as num).toDouble(), max: 100, onChanged: (v) => setState(() => it['x'] = v)))])),
                    SizedBox(width: 180, child: Row(children: [Text(tr('Y')), Expanded(child: Slider(value: (it['y'] as num).toDouble(), max: 100, onChanged: (v) => setState(() => it['y'] = v)))])),
                    if (it['type'] == 'image')
                      SizedBox(width: 200, child: Row(children: [Text(tr('Largeur')), Expanded(child: Slider(value: (it['width'] as num).toDouble(), min: 5, max: 100, onChanged: (v) => setState(() => it['width'] = v)))]))
                    else ...[
                      SizedBox(
                        width: 90,
                        child: TextFormField(initialValue: '${it['size']}', keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Taille'), isDense: true), onChanged: (v) => it['size'] = int.tryParse(v) ?? 12),
                      ),
                      FilterChip(label: Text(tr('Gras')), selected: it['bold'] == true, onSelected: (v) => setState(() => it['bold'] = v)),
                    ],
                  ]),
                ]),
              ),
            ),
          Wrap(spacing: 8, children: [
            OutlinedButton.icon(onPressed: () => _addItem('text'), icon: const Icon(Icons.text_fields), label: Text(tr('Ajouter un texte'))),
            OutlinedButton.icon(onPressed: () => _addItem('image'), icon: const Icon(Icons.draw_outlined), label: Text(tr('Ajouter une image ou une signature'))),
          ]),
          if (_docs.first.thumbs.isNotEmpty) ...[
            const SizedBox(height: 14),
            Center(
              child: LayoutBuilder(builder: (context, c) {
                const w = 260.0;
                return Container(
                  width: w,
                  decoration: BoxDecoration(border: Border.all(color: YColors.line)),
                  child: Stack(children: [
                    Image.memory(_docs.first.thumbs.first, width: w),
                    for (final it in _items.where((x) => x['page'] == 1 || x['page'] == 'all'))
                      Positioned(
                        left: w * (it['x'] as num) / 100,
                        top: w * 1.414 * (it['y'] as num) / 100,
                        child: it['type'] == 'image'
                            ? Image.memory(it['bytes'] as Uint8List, width: w * (it['width'] as num) / 100)
                            : Text('${it['text']}', style: TextStyle(fontSize: ((it['size'] as num) * .45).toDouble(), fontWeight: it['bold'] == true ? FontWeight.w800 : FontWeight.w400, color: Colors.black)),
                      ),
                  ]),
                );
              }),
            ),
            const SizedBox(height: 6),
            Center(child: Text(tr('Aperçu approximatif de la page 1'), style: TextStyle(color: theme.hintColor, fontSize: 12))),
          ],
        ];
      case 'numbers':
        return [
          label('Format'),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final f in ['Page {n} sur {total}', '{n} / {total}', '{n}', '- {n} -'])
              ChoiceChip(label: Text(f.replaceAll('{n}', '1').replaceAll('{total}', '12')), selected: _numFormat == f, onSelected: (_) => setState(() => _numFormat = f)),
          ]),
          label('Position'),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final (k, l) in const [('bottom-center', 'Bas, centre'), ('bottom-right', 'Bas, droite'), ('bottom-left', 'Bas, gauche'), ('top-right', 'Haut, droite'), ('top-center', 'Haut, centre')])
              ChoiceChip(label: Text(tr(l)), selected: _numPos == k, onSelected: (_) => setState(() => _numPos = k)),
          ]),
        ];
      case 'protect':
        return [
          TextField(controller: _pwd, obscureText: true, decoration: InputDecoration(labelText: tr('Mot de passe pour ouvrir le document'), prefixIcon: const Icon(Icons.lock_outline))),
          const SizedBox(height: 10),
          TextField(controller: _pwd2, obscureText: true, decoration: InputDecoration(labelText: tr('Confirmez le mot de passe'), prefixIcon: const Icon(Icons.lock_outline))),
          label('Ce que le lecteur pourra faire'),
          Wrap(spacing: 8, children: [
            for (final (k, l) in const [('print', 'Imprimer'), ('copy', 'Copier le texte'), ('modify', 'Modifier')])
              FilterChip(label: Text(tr(l)), selected: _allow.contains(k), onSelected: (v) => setState(() => v ? _allow.add(k) : _allow.remove(k))),
          ]),
          const SizedBox(height: 10),
          Text(tr('Chiffrement AES-256. Gardez le mot de passe en lieu sûr : il ne peut pas être retrouvé.'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
        ];
      case 'unlock':
        return [TextField(controller: _pwd, obscureText: true, decoration: InputDecoration(labelText: tr('Mot de passe actuel du document'), prefixIcon: const Icon(Icons.key)))];
      case 'images':
        return [
          label('Taille des pages'),
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'A4', label: Text(tr('A4 avec marges'))), ButtonSegment(value: 'fit', label: Text(tr('Taille de l\'image')))],
            selected: {_imgFormat},
            onSelectionChanged: (v) => setState(() => _imgFormat = v.first),
          ),
          const SizedBox(height: 8),
          Text(tr('Faites glisser les images pour changer leur ordre.'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
        ];
      case 'merge':
        return [Text(tr('Faites glisser les fichiers pour choisir l\'ordre. ${_docs.fold<int>(0, (a, d) => a + d.pages)} page(s) au total.'), style: TextStyle(color: theme.hintColor))];
      default:
        return [];
    }
  }
}

/// Enchaîner : choisir un autre outil pour le fichier qu'on vient de produire.
class PdfToolsScreenPicker extends StatelessWidget {
  const PdfToolsScreenPicker({super.key, required this.bytes, required this.name, this.onSaved});
  final Uint8List bytes;
  final String name;
  final Future<void> Function(Uint8List bytes, String name)? onSaved;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Que faire de « $name » ?'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        for (final t in _tools.where((t) => !t.images && t.op != 'unlock'))
          Card(
            child: ListTile(
              leading: Icon(t.icon, color: t.color),
              title: Text(tr(t.title)),
              subtitle: Text(tr(t.desc)),
              onTap: () => Navigator.of(context).pushReplacement(MaterialPageRoute(
                  builder: (_) => t.op == 'edit' ? PdfEditorScreen(bytes: bytes, name: name, onSaved: onSaved) : _ChainedTool(tool: t, bytes: bytes, name: name, onSaved: onSaved))),
            ),
          ),
      ]),
    );
  }
}

class _ChainedTool extends StatefulWidget {
  const _ChainedTool({required this.tool, required this.bytes, required this.name, this.onSaved});
  final _Tool tool;
  final Uint8List bytes;
  final String name;
  final Future<void> Function(Uint8List bytes, String name)? onSaved;

  @override
  State<_ChainedTool> createState() => _ChainedToolState();
}

class _ChainedToolState extends State<_ChainedTool> {
  final _key = GlobalKey<_ToolPageState>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      final s = _key.currentState;
      if (s == null) return;
      final d = _Doc(widget.name, widget.bytes);
      s.setState(() => s._docs.add(d));
      await s._inspect(d);
    });
  }

  @override
  Widget build(BuildContext context) => _ToolPage(key: _key, tool: widget.tool, onSaved: widget.onSaved);
}
