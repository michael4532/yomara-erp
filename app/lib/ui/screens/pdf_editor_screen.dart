import 'dart:convert';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/pdf_viewer.dart';
import '../widgets/common.dart';

/// Outils de l'éditeur. Les positions sont des fractions de page (0 à 1) : indépendantes du zoom et identiques côté serveur.
enum _T { select, text, replace, whiteout, highlight, rect, ellipse, line, arrow, pen, signature, image, check, cross, date }

class _ToolDef {
  const _ToolDef(this.t, this.label, this.icon, this.hint);
  final _T t;
  final String label;
  final IconData icon;
  final String hint;
}

const _toolDefs = [
  _ToolDef(_T.select, 'Sélection', Icons.near_me_outlined, 'Cliquez un élément pour le déplacer, le redimensionner ou le modifier (double-clic sur un texte).'),
  _ToolDef(_T.text, 'Texte', Icons.title, 'Tracez une zone puis écrivez.'),
  _ToolDef(_T.replace, 'Remplacer un texte', Icons.find_replace, 'Encadrez le texte existant : il est masqué et vous écrivez le nouveau à sa place.'),
  _ToolDef(_T.whiteout, 'Masquer', Icons.format_color_reset_outlined, 'Encadrez ce qui doit disparaître (texte, tampon, chiffre…).'),
  _ToolDef(_T.highlight, 'Surligner', Icons.border_color_outlined, 'Encadrez le passage à surligner.'),
  _ToolDef(_T.rect, 'Rectangle', Icons.crop_square, 'Tracez le rectangle.'),
  _ToolDef(_T.ellipse, 'Cercle', Icons.circle_outlined, 'Tracez le cercle ou l\'ellipse.'),
  _ToolDef(_T.line, 'Ligne', Icons.horizontal_rule, 'Tracez la ligne.'),
  _ToolDef(_T.arrow, 'Flèche', Icons.north_east, 'Tracez la flèche, de la base vers la pointe.'),
  _ToolDef(_T.pen, 'Dessin', Icons.gesture, 'Dessinez à main levée.'),
  _ToolDef(_T.signature, 'Signature', Icons.draw_outlined, 'Créez votre signature puis cliquez où la placer.'),
  _ToolDef(_T.image, 'Image / tampon', Icons.image_outlined, 'Choisissez une image (logo, cachet) puis cliquez où la placer.'),
  _ToolDef(_T.check, 'Coche', Icons.check, 'Cliquez pour cocher une case.'),
  _ToolDef(_T.cross, 'Croix', Icons.close, 'Cliquez pour placer une croix.'),
  _ToolDef(_T.date, 'Date', Icons.today_outlined, 'Cliquez pour placer la date du jour.'),
];

const _palette = ['#111A2E', '#E5484D', '#2E6BE6', '#12B76A', '#F5A000', '#9333EA', '#FFFFFF', '#000000'];

Color _hex(String? h, [Color d = Colors.black]) {
  final s = (h ?? '').replaceAll('#', '');
  final v = int.tryParse(s.length == 6 ? 'FF$s' : s, radix: 16);
  return v == null ? d : Color(v);
}

class _Page {
  _Page(this.index, this.png, this.aspect);
  final int index; // 1-based (page d'origine)
  final Uint8List png;
  final double aspect; // hauteur / largeur
  int rotation = 0;
  bool deleted = false;
  double get shownAspect => rotation % 180 == 0 ? aspect : 1 / aspect;
}

/// « Modifier le PDF » : texte, remplacement, masquage, surlignage, formes, dessin, signature, images, pages.
class PdfEditorScreen extends StatefulWidget {
  const PdfEditorScreen({super.key, this.bytes, this.name, this.onSaved});
  final Uint8List? bytes;
  final String? name;

  /// Ouvert depuis un document : le PDF modifié le remplace (nouvelle version) au lieu d'être téléchargé.
  final Future<void> Function(Uint8List bytes, String name)? onSaved;

  @override
  State<PdfEditorScreen> createState() => _PdfEditorScreenState();
}

class _PdfEditorScreenState extends State<PdfEditorScreen> {
  Uint8List? _bytes;
  String _name = 'document.pdf';
  final List<_Page> _pages = [];
  bool _loading = false;
  bool _busy = false;
  String? _error;

  List<Map<String, dynamic>> _items = [];
  final List<String> _undo = [];
  final List<String> _redo = [];
  final Map<int, Uint8List> _imgs = {}; // images par identifiant (non sérialisées dans l'historique)
  int _imgSeq = 0;

  _T _tool = _T.select;
  Map<String, dynamic>? _sel;
  Map<String, dynamic>? _editing; // texte en cours de saisie
  final _textCtl = TextEditingController();
  final _textFocus = FocusNode();
  double _zoom = 1;
  int _currentPage = 1;
  final _scroll = ScrollController();
  final Map<int, GlobalKey> _pageKeys = {};

  // Réglages par défaut des nouveaux éléments
  String _color = '#111A2E';
  double _fontSize = .016; // fraction de la hauteur de page
  bool _bold = false;
  bool _italic = false;
  String _font = 'dejavusans';
  double _stroke = .003;
  Uint8List? _pendingImage; // signature ou image à placer
  double _pendingAspect = .4;

  @override
  void initState() {
    super.initState();
    if (widget.bytes != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _load(widget.bytes!, widget.name ?? 'document.pdf'));
    }
  }

  @override
  void dispose() {
    _textCtl.dispose();
    _textFocus.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final res = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['pdf']);
    if (res.isEmpty || !mounted) return;
    await _load(Uint8List.fromList(await res.first.readAsBytes()), res.first.name);
  }

  Future<void> _load(Uint8List bytes, String name) async {
    setState(() {
      _loading = true;
      _error = null;
      _pages.clear();
      _items = [];
      _undo.clear();
      _redo.clear();
      _sel = null;
      _bytes = bytes;
      _name = name;
    });
    try {
      var i = 0;
      await for (final p in Printing.raster(bytes, dpi: 110).timeout(const Duration(seconds: 40))) {
        i++;
        final png = await p.toPng();
        if (!mounted) return;
        setState(() => _pages.add(_Page(i, png, p.height / p.width)));
        if (i >= 300) break;
      }
      if (_pages.isEmpty) _error = tr('Ce PDF n\'a pas pu être affiché.');
    } catch (e) {
      _error = tr('Ce PDF n\'a pas pu être ouvert (protégé par mot de passe ou endommagé).');
    }
    if (mounted) setState(() => _loading = false);
  }

  // ------------------------------------------------------------------ historique

  String _snapshot() => jsonEncode({
        'items': _items,
        'pages': [for (final p in _pages) [p.rotation, p.deleted]],
      });

  void _commit() {
    _undo.add(_snapshot());
    if (_undo.length > 80) _undo.removeAt(0);
    _redo.clear();
  }

  void _restore(String s) {
    final d = jsonDecode(s) as Map<String, dynamic>;
    _items = [for (final it in d['items'] as List) Map<String, dynamic>.from(it as Map)];
    final pg = d['pages'] as List;
    for (final (i, p) in _pages.indexed) {
      if (i < pg.length) {
        p.rotation = (pg[i] as List)[0] as int;
        p.deleted = (pg[i] as List)[1] as bool;
      }
    }
    _sel = null;
    _editing = null;
  }

  void _doUndo() {
    if (_undo.isEmpty) return;
    _finishEditing();
    setState(() {
      _redo.add(_snapshot());
      _restore(_undo.removeLast());
    });
  }

  void _doRedo() {
    if (_redo.isEmpty) return;
    setState(() {
      _undo.add(_snapshot());
      _restore(_redo.removeLast());
    });
  }

  // ------------------------------------------------------------------ éléments

  bool _isBox(Map<String, dynamic> it) => !{'line', 'arrow', 'ink'}.contains(it['type']);

  Rect _bounds(Map<String, dynamic> it) {
    final t = it['type'];
    if (t == 'line' || t == 'arrow') {
      return Rect.fromPoints(Offset((it['x'] as num).toDouble(), (it['y'] as num).toDouble()), Offset((it['x2'] as num).toDouble(), (it['y2'] as num).toDouble()));
    }
    if (t == 'ink') {
      final pts = [for (final s in it['strokes'] as List) for (final p in s as List) Offset(((p as List)[0] as num).toDouble(), (p[1] as num).toDouble())];
      if (pts.isEmpty) return Rect.zero;
      var r = Rect.fromPoints(pts.first, pts.first);
      for (final p in pts) {
        r = r.expandToInclude(Rect.fromPoints(p, p));
      }
      return r;
    }
    return Rect.fromLTWH((it['x'] as num).toDouble(), (it['y'] as num).toDouble(), (it['w'] as num).toDouble(), (it['h'] as num).toDouble());
  }

  void _translate(Map<String, dynamic> it, double dx, double dy) {
    double c(num v) => v.toDouble().clamp(0.0, 1.0);
    if (it['type'] == 'ink') {
      it['strokes'] = [
        for (final s in it['strokes'] as List) [for (final p in s as List) [c(((p as List)[0] as num) + dx), c((p[1] as num) + dy)]]
      ];
      return;
    }
    it['x'] = c((it['x'] as num) + dx);
    it['y'] = c((it['y'] as num) + dy);
    if (it.containsKey('x2')) {
      it['x2'] = c((it['x2'] as num) + dx);
      it['y2'] = c((it['y2'] as num) + dy);
    }
  }

  Map<String, dynamic>? _hit(int page, Offset f, double aspect) {
    // tolérance : ~8 px sur une page de 800 px
    const tol = .012;
    for (final it in _items.reversed) {
      if (it['page'] != page) continue;
      final b = _bounds(it).inflate(tol);
      if (b.contains(f)) return it;
    }
    return null;
  }

  void _delete(Map<String, dynamic> it) {
    _commit();
    setState(() {
      _items.remove(it);
      if (_sel == it) _sel = null;
    });
  }

  void _duplicate(Map<String, dynamic> it) {
    _commit();
    final c = Map<String, dynamic>.from(jsonDecode(jsonEncode(it)) as Map);
    _translate(c, .02, .02);
    setState(() {
      _items.add(c);
      _sel = c;
    });
  }

  void _startEditing(Map<String, dynamic> it) {
    _editing = it;
    _sel = it;
    _textCtl.text = '${it['text'] ?? ''}';
    _textCtl.selection = TextSelection(baseOffset: 0, extentOffset: _textCtl.text.length);
    WidgetsBinding.instance.addPostFrameCallback((_) => _textFocus.requestFocus());
  }

  void _finishEditing() {
    final it = _editing;
    if (it == null) return;
    setState(() {
      it['text'] = _textCtl.text;
      _editing = null;
      // un texte vide sans fond ne sert à rien ; avec fond, c'est un masquage
      if (_textCtl.text.trim().isEmpty && it['bg'] == null) _items.remove(it);
    });
  }

  Map<String, dynamic> _textItem(int page, double x, double y, String text, {double? w, double? h, String? bg, double? size, double aspect = 1.414}) {
    final s = size ?? _fontSize;
    // taille relative à la hauteur de page, largeur relative à la largeur : on convertit avec le rapport hauteur / largeur
    return {
      'type': 'text', 'page': page, 'x': x, 'y': y, 'w': w ?? math.min(.9, math.max(.06, (text.length * .62 + 1) * s * aspect)), 'h': h ?? s * 1.4,
      'text': text, 'size': s, 'color': _color, 'bold': _bold, 'italic': _italic, 'font': _font, 'align': 'L', 'bg': ?bg,
    };
  }

  /// Création par clic (coche, date, image, signature).
  Future<void> _tap(int page, Offset f, double aspect) async {
    _finishEditing();
    if (_tool == _T.select) {
      setState(() => _sel = _hit(page, f, aspect));
      return;
    }
    if (_tool == _T.check || _tool == _T.cross || _tool == _T.date) {
      _commit();
      final txt = _tool == _T.check ? '✓' : (_tool == _T.cross ? '✗' : DateFormat('dd/MM/yyyy').format(DateTime.now()));
      final s = _tool == _T.date ? _fontSize : _fontSize * 1.5;
      final it = _textItem(page, f.dx, f.dy - s * .7, txt, size: s, aspect: aspect);
      setState(() {
        _items.add(it);
        _sel = it;
      });
      return;
    }
    if (_tool == _T.signature || _tool == _T.image) {
      if (_pendingImage == null) {
        _tool == _T.signature ? await _makeSignature() : await _pickImage();
        if (_pendingImage == null) return;
      }
      _commit();
      final id = ++_imgSeq;
      _imgs[id] = _pendingImage!;
      final w = _tool == _T.signature ? .28 : .2;
      final h = w * _pendingAspect / aspect;
      final it = {'type': 'image', 'page': page, 'x': (f.dx - w / 2).clamp(0.0, 1 - w), 'y': (f.dy - h / 2).clamp(0.0, 1 - h), 'w': w, 'h': h, 'img': id, 'keep_ratio': true};
      setState(() {
        _items.add(it);
        _sel = it;
        _tool = _T.select;
      });
      return;
    }
    if (_tool == _T.text || _tool == _T.replace) {
      // simple clic : zone de texte par défaut
      _commit();
      final it = _textItem(page, f.dx, f.dy - _fontSize * .7, '', w: .35, bg: _tool == _T.replace ? '#FFFFFF' : null, aspect: aspect);
      setState(() => _items.add(it));
      _startEditing(it);
    }
  }

  Future<void> _pickImage() async {
    final res = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['png', 'jpg', 'jpeg']);
    if (res.isEmpty) return;
    final b = Uint8List.fromList(await res.first.readAsBytes());
    final img = await decodeImageFromList(b);
    if (!mounted) return;
    setState(() {
      _pendingImage = b;
      _pendingAspect = img.height / img.width;
    });
  }

  Future<void> _makeSignature() async {
    final r = await showDialog<(Uint8List, double)>(context: context, builder: (_) => const _SignatureDialog());
    if (r == null) return;
    setState(() {
      _pendingImage = r.$1;
      _pendingAspect = r.$2;
    });
  }

  void _setTool(_T t) {
    _finishEditing();
    setState(() {
      _tool = t;
      if (t != _T.select) _sel = null;
      if (t != _T.signature && t != _T.image) _pendingImage = null;
    });
    if (t == _T.signature) _makeSignature();
    if (t == _T.image) _pickImage();
  }

  /// Applique un réglage à l'élément sélectionné (et le garde pour les suivants).
  void _apply(void Function(Map<String, dynamic> it) f) {
    final it = _sel;
    if (it == null) return setState(() {});
    _commit();
    setState(() => f(it));
  }

  // ------------------------------------------------------------------ export

  Future<void> _save() async {
    _finishEditing();
    if (_bytes == null) return;
    setState(() => _busy = true);
    try {
      final imgOrder = <int>[];
      final items = <Map<String, dynamic>>[];
      for (final it in _items) {
        final c = Map<String, dynamic>.from(it);
        if (c['type'] == 'image') {
          final id = c.remove('img') as int;
          if (!imgOrder.contains(id)) imgOrder.add(id);
          c['image_index'] = imgOrder.indexOf(id);
        }
        if (c['type'] == 'text' && '${c['text']}'.trim().isEmpty) {
          if (c['bg'] == null) continue;
          c['type'] = 'whiteout';
          c['fill'] = c['bg'];
        }
        items.add(c);
      }
      final options = {
        'items': items,
        'pages': {
          'delete': [for (final p in _pages) if (p.deleted) p.index],
          'rotate': {for (final p in _pages) if (p.rotation != 0) '${p.index}': p.rotation},
        },
      };
      final files = <(String, List<int>, String)>[
        ('files[0]', _bytes!, _name),
        for (final (i, id) in imgOrder.indexed) ('images[$i]', _imgs[id]!, 'image$i.png'),
      ];
      final (bytes, name) = await context.read<Session>().api.uploadForFile('pdf/edit', {'options': jsonEncode(options)}, files);
      if (!mounted) return;
      await _result(Uint8List.fromList(bytes), name ?? 'document-modifie.pdf');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _result(Uint8List bytes, String name) async {
    if (widget.onSaved != null) {
      await widget.onSaved!(bytes, name);
      if (mounted) Navigator.of(context).pop();
      return;
    }
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.check_circle, color: YColors.success, size: 40),
        title: Text(tr('Votre PDF modifié est prêt')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
          Text('${(bytes.length / 1024).toStringAsFixed(0)} Ko', style: TextStyle(color: Theme.of(ctx).hintColor)),
          const SizedBox(height: 8),
          Text(tr('Vos modifications restent ouvertes ici : vous pouvez continuer et enregistrer à nouveau.'), textAlign: TextAlign.center, style: TextStyle(color: Theme.of(ctx).hintColor, fontSize: 13)),
        ]),
        actions: [
          TextButton.icon(onPressed: () => showPdf(context, bytes, name), icon: const Icon(Icons.visibility_outlined), label: Text(tr('Ouvrir'))),
          FilledButton.icon(
            onPressed: () async {
              await saveFile(name, bytes, mime: 'application/pdf');
              if (ctx.mounted) {
                toast(ctx, tr('Fichier enregistré.'));
                Navigator.pop(ctx);
              }
            },
            icon: const Icon(Icons.download),
            label: Text(tr('Télécharger')),
          ),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ interface

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= 900;
    return CallbackShortcuts(
      bindings: {
        const SingleActivator(LogicalKeyboardKey.keyZ, control: true): _doUndo,
        const SingleActivator(LogicalKeyboardKey.keyZ, meta: true): _doUndo,
        const SingleActivator(LogicalKeyboardKey.keyY, control: true): _doRedo,
        const SingleActivator(LogicalKeyboardKey.keyZ, meta: true, shift: true): _doRedo,
        const SingleActivator(LogicalKeyboardKey.delete): () {
          if (_sel != null && _editing == null) _delete(_sel!);
        },
        const SingleActivator(LogicalKeyboardKey.escape): () {
          _finishEditing();
          setState(() {
            _sel = null;
            _tool = _T.select;
          });
        },
      },
      child: Focus(
        autofocus: true,
        child: Scaffold(
          appBar: AppBar(
            title: Row(children: [
              const Icon(Icons.edit_document, color: Color(0xFFE5484D)),
              const SizedBox(width: 10),
              Flexible(child: Text(_bytes == null ? tr('Modifier un PDF') : _name, overflow: TextOverflow.ellipsis)),
            ]),
            actions: [
              if (_bytes != null) ...[
                IconButton(tooltip: tr('Annuler (Ctrl+Z)'), onPressed: _undo.isEmpty ? null : _doUndo, icon: const Icon(Icons.undo)),
                IconButton(tooltip: tr('Rétablir (Ctrl+Y)'), onPressed: _redo.isEmpty ? null : _doRedo, icon: const Icon(Icons.redo)),
                IconButton(tooltip: tr('Ouvrir un autre PDF'), onPressed: _busy ? null : _pick, icon: const Icon(Icons.folder_open_outlined)),
                const SizedBox(width: 6),
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: FilledButton.icon(
                    onPressed: _busy || _pages.isEmpty ? null : _save,
                    icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.save_alt),
                    label: Text(tr(_busy ? 'Enregistrement…' : 'Enregistrer le PDF')),
                  ),
                ),
              ],
            ],
          ),
          body: _bytes == null
              ? _empty()
              : Column(children: [
                  _toolbar(),
                  _props(),
                  const Divider(height: 1),
                  Expanded(
                    child: Row(children: [
                      if (wide) SizedBox(width: 150, child: _thumbs()),
                      if (wide) const VerticalDivider(width: 1),
                      Expanded(child: _canvas()),
                    ]),
                  ),
                ]),
        ),
      ),
    );
  }

  Widget _empty() => Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Container(
                width: 84,
                height: 84,
                decoration: BoxDecoration(color: const Color(0xFFE5484D).withValues(alpha: .1), borderRadius: BorderRadius.circular(24)),
                child: const Icon(Icons.edit_document, size: 42, color: Color(0xFFE5484D)),
              ),
              const SizedBox(height: 18),
              Text(tr('Modifiez votre PDF comme sur papier'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Text(tr('Corriger un montant ou un nom, masquer une information, surligner, signer, ajouter un cachet, cocher des cases, supprimer ou pivoter des pages.'),
                  textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).hintColor, height: 1.4)),
              const SizedBox(height: 22),
              FilledButton.icon(onPressed: _loading ? null : _pick, icon: const Icon(Icons.upload_file), label: Text(tr('Choisir un PDF'))),
              if (_loading) const Padding(padding: EdgeInsets.only(top: 16), child: CircularProgressIndicator()),
              if (_error != null) Padding(padding: const EdgeInsets.only(top: 16), child: Text(_error!, style: const TextStyle(color: YColors.danger))),
            ]),
          ),
        ),
      );

  Widget _toolbar() {
    final hint = Theme.of(context).hintColor;
    return Container(
      width: double.infinity,
      color: Theme.of(context).colorScheme.surface,
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      alignment: Alignment.centerLeft,
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(children: [
          for (final d in _toolDefs) ...[
            Tooltip(
              message: '${tr(d.label)} — ${tr(d.hint)}',
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 2),
                child: Material(
                  color: _tool == d.t ? YColors.blue.withValues(alpha: .12) : Colors.transparent,
                  borderRadius: BorderRadius.circular(10),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(10),
                    onTap: () => _setTool(d.t),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                        Icon(d.icon, size: 20, color: _tool == d.t ? YColors.blue : null),
                        const SizedBox(height: 2),
                        Text(tr(d.label), style: TextStyle(fontSize: 11, fontWeight: _tool == d.t ? FontWeight.w800 : FontWeight.w500, color: _tool == d.t ? YColors.blue : hint)),
                      ]),
                    ),
                  ),
                ),
              ),
            ),
            if (d.t == _T.select || d.t == _T.highlight || d.t == _T.pen) Container(width: 1, height: 34, color: Theme.of(context).dividerColor, margin: const EdgeInsets.symmetric(horizontal: 4)),
          ],
        ]),
      ),
    );
  }

  /// Barre des réglages : élément sélectionné, sinon outil en cours.
  Widget _props() {
    final it = _sel;
    final hint = Theme.of(context).hintColor;
    final type = it?['type'] as String? ?? switch (_tool) {
      _T.text || _T.replace || _T.check || _T.cross || _T.date => 'text',
      _T.rect || _T.ellipse => 'rect',
      _T.line || _T.arrow || _T.pen => 'line',
      _ => null,
    };
    final def = _toolDefs.firstWhere((d) => d.t == _tool);
    final color = '${it?['color'] ?? _color}';
    final children = <Widget>[];
    if (it == null) {
      children.add(Flexible(child: Text(tr(def.hint), overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12.5))));
      if ((_tool == _T.signature || _tool == _T.image) && _pendingImage != null) {
        children.addAll([
          const SizedBox(width: 10),
          Container(height: 30, padding: const EdgeInsets.all(2), decoration: BoxDecoration(color: Colors.white, border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(6)), child: Image.memory(_pendingImage!)),
          TextButton(onPressed: _tool == _T.signature ? _makeSignature : _pickImage, child: Text(tr('Changer'))),
        ]);
      }
    } else {
      children.add(Text(tr(switch (type) { 'text' => it['bg'] != null ? 'Texte de remplacement' : 'Texte', 'image' => 'Image', 'whiteout' => 'Masquage', 'highlight' => 'Surlignage', 'ink' => 'Dessin', _ => 'Forme' }),
          style: const TextStyle(fontWeight: FontWeight.w800)));
    }
    if (type != null && type != 'image' && type != 'whiteout') {
      children.addAll([
        const SizedBox(width: 12),
        for (final c in _palette)
          GestureDetector(
            onTap: () {
              _color = c;
              _apply((x) => x['color'] = c);
            },
            child: Container(
              width: 20,
              height: 20,
              margin: const EdgeInsets.symmetric(horizontal: 2),
              decoration: BoxDecoration(color: _hex(c), shape: BoxShape.circle, border: Border.all(color: color.toUpperCase() == c ? YColors.blue : YColors.line, width: color.toUpperCase() == c ? 2.5 : 1)),
            ),
          ),
      ]);
    }
    if (type == 'text') {
      final size = ((it?['size'] as num?)?.toDouble() ?? _fontSize);
      children.addAll([
        const SizedBox(width: 12),
        DropdownButton<String>(
          value: '${it?['font'] ?? _font}',
          underline: const SizedBox(),
          isDense: true,
          items: [
            for (final f in const [('dejavusans', 'Sans'), ('helvetica', 'Helvetica'), ('times', 'Times'), ('courier', 'Courier')]) DropdownMenuItem(value: f.$1, child: Text(f.$2)),
          ],
          onChanged: (v) {
            _font = v!;
            _apply((x) => x['font'] = v);
          },
        ),
        IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Plus petit'), icon: const Icon(Icons.text_decrease, size: 18), onPressed: () {
          _fontSize = math.max(.006, size / 1.12);
          _apply((x) => x['size'] = _fontSize);
        }),
        Text('${(size * 842).round()} pt', style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700)),
        IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Plus grand'), icon: const Icon(Icons.text_increase, size: 18), onPressed: () {
          _fontSize = math.min(.12, size * 1.12);
          _apply((x) => x['size'] = _fontSize);
        }),
        _toggle(Icons.format_bold, 'Gras', it?['bold'] == true || (it == null && _bold), () {
          _bold = !(it?['bold'] == true || (it == null && _bold));
          _apply((x) => x['bold'] = _bold);
        }),
        _toggle(Icons.format_italic, 'Italique', it?['italic'] == true || (it == null && _italic), () {
          _italic = !(it?['italic'] == true || (it == null && _italic));
          _apply((x) => x['italic'] = _italic);
        }),
        if (it != null) ...[
          for (final a in const [('L', Icons.format_align_left), ('C', Icons.format_align_center), ('R', Icons.format_align_right)])
            _toggle(a.$2, 'Alignement', (it['align'] ?? 'L') == a.$1, () => _apply((x) => x['align'] = a.$1)),
          const SizedBox(width: 6),
          FilterChip(
            label: Text(tr('Fond blanc (remplace)')),
            selected: it['bg'] != null,
            onSelected: (v) => _apply((x) => v ? x['bg'] = '#FFFFFF' : x.remove('bg')),
          ),
        ],
      ]);
    }
    if (type == 'rect' || type == 'line' || type == 'ellipse' || type == 'ink' || type == 'arrow') {
      final sw = ((it?['stroke'] as num?)?.toDouble() ?? _stroke);
      children.addAll([
        const SizedBox(width: 12),
        Text(tr('Épaisseur'), style: TextStyle(fontSize: 12, color: hint)),
        SizedBox(
          width: 120,
          child: Slider(value: sw.clamp(.001, .012), min: .001, max: .012, onChanged: (v) {
            _stroke = v;
            if (it != null) setState(() => it['stroke'] = v);
          }, onChangeStart: (_) => it != null ? _commit() : null),
        ),
      ]);
      if (it != null && (type == 'rect' || type == 'ellipse')) {
        children.add(FilterChip(label: Text(tr('Rempli')), selected: it['fill'] != null, onSelected: (v) => _apply((x) => v ? x['fill'] = x['color'] ?? _color : x.remove('fill'))));
      }
    }
    if (it != null && (type == 'highlight' || type == 'image' || type == 'rect')) {
      final op = ((it['opacity'] as num?)?.toDouble() ?? (type == 'highlight' ? .4 : 1));
      children.addAll([
        const SizedBox(width: 12),
        Text(tr('Opacité'), style: TextStyle(fontSize: 12, color: hint)),
        SizedBox(width: 110, child: Slider(value: op.clamp(.1, 1), min: .1, max: 1, onChangeStart: (_) => _commit(), onChanged: (v) => setState(() => it['opacity'] = v))),
      ]);
      if (type == 'highlight') {
        for (final c in const ['#FFE600', '#7CF29C', '#7CC4FF', '#FF9EDB']) {
          children.add(GestureDetector(
            onTap: () => _apply((x) => x['fill'] = c),
            child: Container(width: 20, height: 20, margin: const EdgeInsets.symmetric(horizontal: 2), decoration: BoxDecoration(color: _hex(c), shape: BoxShape.circle, border: Border.all(color: YColors.line))),
          ));
        }
      }
    }
    if (it != null) {
      children.addAll([
        const SizedBox(width: 12),
        if (type == 'text') IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Modifier le texte'), icon: const Icon(Icons.edit_outlined, size: 18), onPressed: () => setState(() => _startEditing(it))),
        IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Dupliquer'), icon: const Icon(Icons.copy_all_outlined, size: 18), onPressed: () => _duplicate(it)),
        IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Supprimer (Suppr)'), icon: const Icon(Icons.delete_outline, size: 18, color: YColors.danger), onPressed: () => _delete(it)),
      ]);
    }
    children.addAll([
      const SizedBox(width: 16),
      IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Zoom arrière'), icon: const Icon(Icons.zoom_out, size: 19), onPressed: () => setState(() => _zoom = math.max(.5, _zoom - .15))),
      Text('${(_zoom * 100).round()} %', style: const TextStyle(fontSize: 12)),
      IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Zoom avant'), icon: const Icon(Icons.zoom_in, size: 19), onPressed: () => setState(() => _zoom = math.min(2.5, _zoom + .15))),
    ]);
    return Container(
      height: 46,
      width: double.infinity,
      alignment: Alignment.centerLeft,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      color: YColors.blue.withValues(alpha: .035),
      child: SingleChildScrollView(scrollDirection: Axis.horizontal, child: Row(children: children)),
    );
  }

  Widget _toggle(IconData icon, String tip, bool on, VoidCallback f) => IconButton(
        visualDensity: VisualDensity.compact,
        tooltip: tr(tip),
        isSelected: on,
        style: IconButton.styleFrom(backgroundColor: on ? YColors.blue.withValues(alpha: .14) : null),
        icon: Icon(icon, size: 18, color: on ? YColors.blue : null),
        onPressed: f,
      );

  Widget _thumbs() {
    return ListView(padding: const EdgeInsets.all(10), children: [
      for (final p in _pages)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Column(children: [
            InkWell(
              onTap: () {
                final k = _pageKeys[p.index];
                if (k?.currentContext != null) Scrollable.ensureVisible(k!.currentContext!, duration: const Duration(milliseconds: 300));
              },
              child: Opacity(
                opacity: p.deleted ? .3 : 1,
                child: Container(
                  decoration: BoxDecoration(border: Border.all(color: _currentPage == p.index ? YColors.blue : YColors.line, width: _currentPage == p.index ? 2 : 1), borderRadius: BorderRadius.circular(4)),
                  child: RotatedBox(quarterTurns: p.rotation ~/ 90, child: Image.memory(p.png, fit: BoxFit.contain)),
                ),
              ),
            ),
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              Text('${p.index}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
              IconButton(visualDensity: VisualDensity.compact, iconSize: 16, tooltip: tr('Pivoter'), icon: const Icon(Icons.rotate_right), onPressed: () {
                _commit();
                setState(() => p.rotation = (p.rotation + 90) % 360);
              }),
              IconButton(visualDensity: VisualDensity.compact, iconSize: 16, tooltip: tr(p.deleted ? 'Garder la page' : 'Supprimer la page'),
                  icon: Icon(p.deleted ? Icons.restore_from_trash_outlined : Icons.delete_outline, color: p.deleted ? YColors.success : null), onPressed: () {
                if (!p.deleted && _pages.where((x) => !x.deleted).length <= 1) return toast(context, tr('Le document doit garder au moins une page.'), error: true);
                _commit();
                setState(() => p.deleted = !p.deleted);
              }),
            ]),
          ]),
        ),
    ]);
  }

  Widget _canvas() {
    return Container(
      color: const Color(0xFFE9ECF2),
      child: LayoutBuilder(builder: (context, c) {
        final base = math.min(c.maxWidth - 48, 900.0);
        final pw = base * _zoom;
        return NotificationListener<ScrollNotification>(
          onNotification: (_) {
            for (final p in _pages) {
              final ctx = _pageKeys[p.index]?.currentContext;
              final box = ctx?.findRenderObject() as RenderBox?;
              if (box == null || !box.attached) continue;
              final y = box.localToGlobal(Offset.zero).dy;
              if (y > -box.size.height / 2 && y < c.maxHeight / 2) {
                if (_currentPage != p.index) setState(() => _currentPage = p.index);
                break;
              }
            }
            return false;
          },
          child: Scrollbar(
            controller: _scroll,
            child: SingleChildScrollView(
              controller: _scroll,
              padding: const EdgeInsets.symmetric(vertical: 20),
              child: Center(
                child: SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Column(children: [
                    for (final p in _pages) _pageView(p, pw),
                    if (_loading) const Padding(padding: EdgeInsets.all(20), child: CircularProgressIndicator()),
                  ]),
                ),
              ),
            ),
          ),
        );
      }),
    );
  }

  Widget _pageView(_Page p, double pw) {
    final ph = pw * p.shownAspect;
    final key = _pageKeys.putIfAbsent(p.index, () => GlobalKey());
    Offset frac(Offset local) => Offset((local.dx / pw).clamp(0.0, 1.0), (local.dy / ph).clamp(0.0, 1.0));
    final items = [for (final it in _items) if (it['page'] == p.index) it];
    return Padding(
      key: key,
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 18),
      child: Column(children: [
        Container(
          width: pw,
          height: ph,
          decoration: BoxDecoration(color: Colors.white, boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .12), blurRadius: 10, offset: const Offset(0, 3))]),
          child: Stack(clipBehavior: Clip.none, children: [
            Positioned.fill(child: RotatedBox(quarterTurns: p.rotation ~/ 90, child: Image.memory(p.png, fit: BoxFit.fill, gaplessPlayback: true))),
            if (p.deleted)
              Positioned.fill(
                child: Container(
                  color: Colors.white.withValues(alpha: .75),
                  alignment: Alignment.center,
                  child: Text(tr('Page supprimée'), style: const TextStyle(color: YColors.danger, fontSize: 22, fontWeight: FontWeight.w800)),
                ),
              ),
            // dessin des éléments
            Positioned.fill(child: IgnorePointer(child: CustomPaint(painter: _ItemsPainter(items, _imgCache, pw, ph, _editing)))),
            // interactions
            Positioned.fill(child: _gestures(p, pw, ph, frac)),
            for (final it in items)
              if (it['type'] == 'image' && _imgs[it['img']] != null)
                Positioned(
                  left: (it['x'] as num) * pw,
                  top: (it['y'] as num) * ph,
                  width: (it['w'] as num) * pw,
                  height: (it['h'] as num) * ph,
                  child: IgnorePointer(child: Opacity(opacity: ((it['opacity'] as num?) ?? 1).toDouble().clamp(.05, 1), child: Image.memory(_imgs[it['img']]!, fit: BoxFit.contain))),
                ),
            if (_sel != null && _sel!['page'] == p.index && _editing == null) ..._handles(_sel!, pw, ph),
            if (_editing != null && _editing!['page'] == p.index) _editor(_editing!, pw, ph),
          ]),
        ),
        const SizedBox(height: 4),
        Text(tr('Page ${p.index} / ${_pages.length}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
      ]),
    );
  }

  final Map<int, ui.Image> _imgCache = {};

  // Geste en cours
  Map<String, dynamic>? _drawing;
  Offset? _dragStart;
  String? _dragMode; // move, resize, p1, p2

  Widget _gestures(_Page p, double pw, double ph, Offset Function(Offset) frac) {
    return MouseRegion(
      cursor: _tool == _T.select ? SystemMouseCursors.basic : SystemMouseCursors.precise,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        dragStartBehavior: DragStartBehavior.down, // la zone part de l'endroit exact où l'on appuie
        onTapUp: (d) => _tap(p.index, frac(d.localPosition), ph / pw),
        onDoubleTapDown: (d) {
          final it = _hit(p.index, frac(d.localPosition), ph / pw);
          if (it != null && it['type'] == 'text') setState(() => _startEditing(it));
        },
        onPanStart: (d) {
          _finishEditing();
          final f = frac(d.localPosition);
          _dragStart = f;
          if (_tool == _T.select) {
            final it = _sel != null && _sel!['page'] == p.index && _bounds(_sel!).inflate(.012).contains(f) ? _sel : _hit(p.index, f, ph / pw);
            if (it == null) {
              setState(() => _sel = null);
              _dragMode = null;
              return;
            }
            _commit();
            setState(() => _sel = it);
            _dragMode = 'move';
            return;
          }
          if (_tool == _T.signature || _tool == _T.image || _tool == _T.check || _tool == _T.cross || _tool == _T.date) return;
          _commit();
          final type = switch (_tool) {
            _T.text || _T.replace => 'text',
            _T.whiteout => 'whiteout',
            _T.highlight => 'highlight',
            _T.rect => 'rect',
            _T.ellipse => 'ellipse',
            _T.line => 'line',
            _T.arrow => 'arrow',
            _ => 'ink',
          };
          final it = <String, dynamic>{'type': type, 'page': p.index, 'x': f.dx, 'y': f.dy, 'color': _color, 'stroke': _stroke};
          if (type == 'ink') {
            it['strokes'] = [
              [[f.dx, f.dy]]
            ];
          } else if (type == 'line' || type == 'arrow') {
            it['x2'] = f.dx;
            it['y2'] = f.dy;
          } else {
            it['w'] = 0.0;
            it['h'] = 0.0;
          }
          if (type == 'text') {
            it.addAll({'text': '', 'size': _fontSize, 'bold': _bold, 'italic': _italic, 'font': _font, 'align': 'L'});
            if (_tool == _T.replace) it['bg'] = '#FFFFFF';
          }
          if (type == 'highlight') it.addAll({'fill': '#FFE600', 'opacity': .4});
          setState(() {
            _items.add(it);
            _drawing = it;
          });
        },
        onPanUpdate: (d) {
          final f = frac(d.localPosition);
          final s = _dragStart;
          if (s == null) return;
          if (_tool == _T.select && _sel != null && _dragMode == 'move') {
            final prev = s;
            setState(() => _translate(_sel!, f.dx - prev.dx, f.dy - prev.dy));
            _dragStart = f;
            return;
          }
          final it = _drawing;
          if (it == null) return;
          setState(() {
            switch (it['type']) {
              case 'ink':
                ((it['strokes'] as List).last as List).add([f.dx, f.dy]);
              case 'line' || 'arrow':
                it['x2'] = f.dx;
                it['y2'] = f.dy;
              default:
                it['x'] = math.min(s.dx, f.dx);
                it['y'] = math.min(s.dy, f.dy);
                it['w'] = (f.dx - s.dx).abs();
                it['h'] = (f.dy - s.dy).abs();
            }
          });
        },
        onPanEnd: (_) {
          final it = _drawing;
          _drawing = null;
          _dragStart = null;
          _dragMode = null;
          if (it == null) return;
          // zone trop petite : taille par défaut
          if (_isBox(it) && ((it['w'] as num) < .01 || (it['h'] as num) < .005)) {
            if (it['type'] == 'text') {
              it['w'] = .35;
              it['h'] = (it['size'] as num) * 1.4;
            } else {
              it['w'] = .2;
              it['h'] = it['type'] == 'highlight' || it['type'] == 'whiteout' ? .02 : .08;
            }
          }
          if (it['type'] == 'text') {
            // remplacement : taille du texte calée sur la hauteur de la zone encadrée
            if (it['bg'] != null && (it['h'] as num) > .008 && (it['h'] as num) < .08) it['size'] = (it['h'] as num) * .72;
            setState(() => _startEditing(it));
          } else {
            setState(() => _sel = it);
          }
        },
      ),
    );
  }

  List<Widget> _handles(Map<String, dynamic> it, double pw, double ph) {
    final b = _bounds(it);
    final r = Rect.fromLTWH(b.left * pw, b.top * ph, b.width * pw, b.height * ph);
    final out = <Widget>[
      Positioned(
        left: r.left - 3,
        top: r.top - 3,
        width: r.width + 6,
        height: r.height + 6,
        child: IgnorePointer(child: Container(decoration: BoxDecoration(border: Border.all(color: YColors.blue, width: 1.5)))),
      ),
    ];
    Widget handle(Offset c, void Function(Offset delta) onDrag) => Positioned(
          left: c.dx - 7,
          top: c.dy - 7,
          width: 14,
          height: 14,
          child: MouseRegion(
            cursor: SystemMouseCursors.resizeDownRight,
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onPanStart: (_) => _commit(),
              onPanUpdate: (d) => setState(() => onDrag(Offset(d.delta.dx / pw, d.delta.dy / ph))),
              child: Container(decoration: BoxDecoration(color: Colors.white, shape: BoxShape.circle, border: Border.all(color: YColors.blue, width: 2))),
            ),
          ),
        );
    if (it['type'] == 'line' || it['type'] == 'arrow') {
      out.add(handle(Offset((it['x'] as num) * pw, (it['y'] as num) * ph), (d) {
        it['x'] = ((it['x'] as num) + d.dx).clamp(0.0, 1.0);
        it['y'] = ((it['y'] as num) + d.dy).clamp(0.0, 1.0);
      }));
      out.add(handle(Offset((it['x2'] as num) * pw, (it['y2'] as num) * ph), (d) {
        it['x2'] = ((it['x2'] as num) + d.dx).clamp(0.0, 1.0);
        it['y2'] = ((it['y2'] as num) + d.dy).clamp(0.0, 1.0);
      }));
    } else if (_isBox(it)) {
      out.add(handle(r.bottomRight, (d) {
        if (it['keep_ratio'] == true) {
          final ratio = (it['h'] as num) / (it['w'] as num);
          it['w'] = ((it['w'] as num) + d.dx).clamp(.02, 1.0);
          it['h'] = (it['w'] as num) * ratio;
        } else {
          it['w'] = ((it['w'] as num) + d.dx).clamp(.01, 1.0);
          it['h'] = ((it['h'] as num) + d.dy).clamp(.004, 1.0);
        }
      }));
    }
    return out;
  }

  Widget _editor(Map<String, dynamic> it, double pw, double ph) {
    final size = (it['size'] as num).toDouble() * ph;
    final align = switch (it['align']) { 'C' => TextAlign.center, 'R' => TextAlign.right, _ => TextAlign.left };
    return Positioned(
      left: (it['x'] as num) * pw,
      top: (it['y'] as num) * ph,
      width: math.max(60, (it['w'] as num) * pw),
      child: Container(
        decoration: BoxDecoration(color: it['bg'] != null ? _hex('${it['bg']}', Colors.white) : Colors.white.withValues(alpha: .5), border: Border.all(color: YColors.blue, width: 1.5)),
        child: TextField(
          controller: _textCtl,
          focusNode: _textFocus,
          maxLines: null,
          textAlign: align,
          style: TextStyle(fontSize: size, height: 1.15, color: _hex('${it['color']}'), fontWeight: it['bold'] == true ? FontWeight.w700 : FontWeight.w400,
              fontStyle: it['italic'] == true ? FontStyle.italic : FontStyle.normal, fontFamily: _family('${it['font']}')),
          decoration: InputDecoration(isDense: true, border: InputBorder.none, contentPadding: EdgeInsets.zero, hintText: tr(it['bg'] != null ? 'Nouveau texte' : 'Votre texte')),
          onTapOutside: (_) => _finishEditing(),
          onChanged: (v) {
            // la zone s'agrandit en hauteur avec le texte
            final lines = '\n'.allMatches(v).length + 1;
            it['h'] = math.max((it['h'] as num).toDouble(), (it['size'] as num) * 1.15 * lines + .002);
          },
        ),
      ),
    );
  }
}

String? _family(String f) => switch (f) { 'times' => 'serif', 'courier' => 'monospace', _ => null };

class _ItemsPainter extends CustomPainter {
  _ItemsPainter(this.items, this.cache, this.pw, this.ph, this.editing);
  final List<Map<String, dynamic>> items;
  final Map<int, ui.Image> cache;
  final double pw, ph;
  final Map<String, dynamic>? editing;

  @override
  void paint(Canvas canvas, Size size) {
    for (final it in items) {
      final color = _hex('${it['color'] ?? '#111A2E'}');
      final op = ((it['opacity'] as num?) ?? 1).toDouble().clamp(.05, 1.0);
      final sw = math.max(1.0, ((it['stroke'] as num?) ?? .003).toDouble() * pw);
      Rect box() => Rect.fromLTWH((it['x'] as num) * pw, (it['y'] as num) * ph, ((it['w'] as num?) ?? 0) * pw, ((it['h'] as num?) ?? 0) * ph);
      final stroke = Paint()
        ..color = color.withValues(alpha: op)
        ..style = PaintingStyle.stroke
        ..strokeWidth = sw
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round;
      switch (it['type']) {
        case 'whiteout':
          canvas.drawRect(box(), Paint()..color = _hex('${it['fill'] ?? '#FFFFFF'}', Colors.white));
          canvas.drawRect(box(), Paint()
            ..color = Colors.black12
            ..style = PaintingStyle.stroke);
        case 'highlight':
          canvas.drawRect(box(), Paint()
            ..color = _hex('${it['fill'] ?? '#FFE600'}').withValues(alpha: ((it['opacity'] as num?) ?? .4).toDouble())
            ..blendMode = BlendMode.multiply);
        case 'rect':
          if (it['fill'] != null) canvas.drawRect(box(), Paint()..color = _hex('${it['fill']}').withValues(alpha: op));
          canvas.drawRect(box(), stroke);
        case 'ellipse':
          if (it['fill'] != null) canvas.drawOval(box(), Paint()..color = _hex('${it['fill']}').withValues(alpha: op));
          canvas.drawOval(box(), stroke);
        case 'line' || 'arrow':
          final a = Offset((it['x'] as num) * pw, (it['y'] as num) * ph);
          final b = Offset((it['x2'] as num) * pw, (it['y2'] as num) * ph);
          canvas.drawLine(a, b, stroke);
          if (it['type'] == 'arrow' && (b - a).distance > 2) {
            final ang = math.atan2(b.dy - a.dy, b.dx - a.dx);
            final l = math.max(10.0, sw * 5);
            final p = Path()
              ..moveTo(b.dx, b.dy)
              ..lineTo(b.dx - l * math.cos(ang - .4), b.dy - l * math.sin(ang - .4))
              ..lineTo(b.dx - l * math.cos(ang + .4), b.dy - l * math.sin(ang + .4))
              ..close();
            canvas.drawPath(p, Paint()..color = color.withValues(alpha: op));
          }
        case 'ink':
          for (final s in it['strokes'] as List) {
            final pts = [for (final p in s as List) Offset(((p as List)[0] as num) * pw, (p[1] as num) * ph)];
            if (pts.length == 1) {
              canvas.drawCircle(pts.first, sw / 2, Paint()..color = color.withValues(alpha: op));
              continue;
            }
            final path = Path()..moveTo(pts.first.dx, pts.first.dy);
            for (final q in pts.skip(1)) {
              path.lineTo(q.dx, q.dy);
            }
            canvas.drawPath(path, stroke);
          }
        case 'text':
          if (identical(it, editing)) break;
          final r = box();
          if (it['bg'] != null) canvas.drawRect(r, Paint()..color = _hex('${it['bg']}', Colors.white));
          final tp = TextPainter(
            text: TextSpan(
              text: '${it['text'] ?? ''}',
              style: TextStyle(
                fontSize: (it['size'] as num) * ph,
                height: 1.15,
                color: color.withValues(alpha: op),
                fontWeight: it['bold'] == true ? FontWeight.w700 : FontWeight.w400,
                fontStyle: it['italic'] == true ? FontStyle.italic : FontStyle.normal,
                fontFamily: _family('${it['font']}'),
              ),
            ),
            textAlign: switch (it['align']) { 'C' => TextAlign.center, 'R' => TextAlign.right, _ => TextAlign.left },
            textDirection: ui.TextDirection.ltr,
          )..layout(maxWidth: math.max(10, r.width));
          tp.paint(canvas, Offset(r.left + (it['align'] == 'C' ? (r.width - tp.width) / 2 : it['align'] == 'R' ? r.width - tp.width : 0), r.top));
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => true;
}

/// Signature : dessinée au doigt / à la souris, ou tapée.
class _SignatureDialog extends StatefulWidget {
  const _SignatureDialog();

  @override
  State<_SignatureDialog> createState() => _SignatureDialogState();
}

class _SignatureDialogState extends State<_SignatureDialog> {
  final List<List<Offset>> _strokes = [];
  final _typed = TextEditingController();
  int _tab = 0;
  Color _ink = const Color(0xFF1D3B8F);
  static const _w = 460.0, _h = 180.0;

  Future<(Uint8List, double)?> _export() async {
    final rec = ui.PictureRecorder();
    final c = Canvas(rec);
    const scale = 3.0;
    c.scale(scale);
    Rect? bounds;
    if (_tab == 0) {
      if (_strokes.isEmpty) return null;
      final p = Paint()
        ..color = _ink
        ..strokeWidth = 2.6
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round;
      for (final s in _strokes) {
        if (s.length == 1) {
          c.drawCircle(s.first, 1.3, Paint()..color = _ink);
        } else {
          final path = Path()..moveTo(s.first.dx, s.first.dy);
          for (final q in s.skip(1)) {
            path.lineTo(q.dx, q.dy);
          }
          c.drawPath(path, p);
        }
        for (final q in s) {
          bounds = bounds == null ? Rect.fromCenter(center: q, width: 4, height: 4) : bounds.expandToInclude(Rect.fromCenter(center: q, width: 4, height: 4));
        }
      }
    } else {
      if (_typed.text.trim().isEmpty) return null;
      final tp = TextPainter(
        text: TextSpan(text: _typed.text.trim(), style: TextStyle(fontSize: 54, fontStyle: FontStyle.italic, fontWeight: FontWeight.w300, color: _ink, fontFamily: 'serif', letterSpacing: -1)),
        textDirection: ui.TextDirection.ltr,
      )..layout(maxWidth: _w);
      tp.paint(c, Offset(10, (_h - tp.height) / 2));
      bounds = Rect.fromLTWH(6, (_h - tp.height) / 2, tp.width + 8, tp.height);
    }
    final pic = rec.endRecording();
    final b = bounds!.inflate(4).intersect(const Rect.fromLTWH(0, 0, _w, _h));
    final full = await pic.toImage((_w * scale).round(), (_h * scale).round());
    // recadrage sur la signature
    final rec2 = ui.PictureRecorder();
    final c2 = Canvas(rec2);
    c2.drawImageRect(full, Rect.fromLTWH(b.left * scale, b.top * scale, b.width * scale, b.height * scale), Rect.fromLTWH(0, 0, b.width * scale, b.height * scale), Paint());
    final img = await rec2.endRecording().toImage((b.width * scale).round(), (b.height * scale).round());
    final data = await img.toByteData(format: ui.ImageByteFormat.png);
    return (data!.buffer.asUint8List(), b.height / b.width);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(tr('Votre signature')),
      content: SizedBox(
        width: _w,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          SegmentedButton<int>(
            segments: [ButtonSegment(value: 0, label: Text(tr('Dessiner')), icon: const Icon(Icons.gesture)), ButtonSegment(value: 1, label: Text(tr('Taper')), icon: const Icon(Icons.keyboard))],
            selected: {_tab},
            onSelectionChanged: (s) => setState(() => _tab = s.first),
          ),
          const SizedBox(height: 12),
          Container(
            width: _w,
            height: _h,
            decoration: BoxDecoration(color: Colors.white, border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(10)),
            child: _tab == 0
                ? ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: GestureDetector(
                      dragStartBehavior: DragStartBehavior.down,
                      onPanStart: (d) => setState(() => _strokes.add([d.localPosition])),
                      onPanUpdate: (d) => setState(() => _strokes.last.add(d.localPosition)),
                      child: CustomPaint(painter: _PadPainter(_strokes, _ink), child: Stack(children: [
                        Positioned(left: 20, right: 20, bottom: 40, child: Container(height: 1, color: YColors.line)),
                        if (_strokes.isEmpty) Center(child: Text(tr('Signez ici'), style: const TextStyle(color: Colors.black26, fontSize: 18))),
                      ])),
                    ),
                  )
                : Center(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: TextField(
                        controller: _typed,
                        autofocus: true,
                        textAlign: TextAlign.center,
                        onChanged: (_) => setState(() {}),
                        style: TextStyle(fontSize: 40, fontStyle: FontStyle.italic, fontWeight: FontWeight.w300, color: _ink, fontFamily: 'serif'),
                        decoration: InputDecoration(hintText: tr('Prénom Nom'), border: InputBorder.none),
                      ),
                    ),
                  ),
          ),
          const SizedBox(height: 10),
          Row(children: [
            for (final c in const [Color(0xFF1D3B8F), Color(0xFF111A2E), Color(0xFF0E7C3A)])
              GestureDetector(
                onTap: () => setState(() => _ink = c),
                child: Container(width: 24, height: 24, margin: const EdgeInsets.only(right: 8), decoration: BoxDecoration(color: c, shape: BoxShape.circle, border: Border.all(color: _ink == c ? YColors.blue : Colors.transparent, width: 3))),
              ),
            const Spacer(),
            if (_tab == 0) TextButton.icon(onPressed: () => setState(_strokes.clear), icon: const Icon(Icons.refresh, size: 18), label: Text(tr('Effacer'))),
          ]),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: () async {
            final r = await _export();
            if (!context.mounted) return;
            if (r == null) return toast(context, tr(_tab == 0 ? 'Signez dans le cadre.' : 'Tapez votre nom.'), error: true);
            if (context.mounted) Navigator.pop(context, r);
          },
          child: Text(tr('Utiliser cette signature')),
        ),
      ],
    );
  }
}

class _PadPainter extends CustomPainter {
  _PadPainter(this.strokes, this.ink);
  final List<List<Offset>> strokes;
  final Color ink;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()
      ..color = ink
      ..strokeWidth = 2.6
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    for (final s in strokes) {
      if (s.length == 1) {
        canvas.drawCircle(s.first, 1.3, Paint()..color = ink);
        continue;
      }
      final path = Path()..moveTo(s.first.dx, s.first.dy);
      for (final q in s.skip(1)) {
        path.lineTo(q.dx, q.dy);
      }
      canvas.drawPath(path, p);
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => true;
}
