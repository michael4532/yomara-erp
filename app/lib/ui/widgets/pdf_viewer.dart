import 'dart:convert';
import 'dart:typed_data';

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart' show PdfPageFormat;
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../core/app_navigator.dart';
import '../../files/save_file.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../screens/pdf_editor_screen.dart';
import '../screens/pdf_tools_screen.dart' show PdfToolsScreenPicker;
import '../theme.dart';
import 'common.dart';

/// Enregistre un PDF modifié (nouvelle version d'un document, par exemple).
typedef PdfSave = Future<void> Function(Uint8List bytes, String name);

/// Ouvre un PDF dans l'application : lecture, zoom, impression, téléchargement, et les outils PDF
/// (modifier, annoter, signer, protéger par mot de passe, retirer la protection…) sans quitter l'application.
Future<void> showPdf(BuildContext context, List<int> bytes, String name, {PdfSave? onSave}) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => PdfViewerScreen(bytes: Uint8List.fromList(bytes), name: name, onSave: onSave)));

/// Remplace l'aperçu du système (fenêtre d'impression) par la lecture dans l'application, avec la même signature
/// que Printing.layoutPdf : utilisable partout, même sans contexte.
Future<bool> previewPdf({required String name, required FutureOr<Uint8List> Function(PdfPageFormat format) onLayout, PdfPageFormat format = PdfPageFormat.standard}) async {
  final nav = appNavigatorKey.currentState;
  final bytes = await onLayout(format);
  if (nav == null) return Printing.layoutPdf(name: name, format: format, onLayout: (_) async => bytes);
  await nav.push(MaterialPageRoute(builder: (_) => PdfViewerScreen(bytes: bytes, name: name)));
  return true;
}

class PdfViewerScreen extends StatefulWidget {
  const PdfViewerScreen({super.key, required this.bytes, required this.name, this.onSave});

  final Uint8List bytes;
  final String name;
  final PdfSave? onSave;

  @override
  State<PdfViewerScreen> createState() => _PdfViewerScreenState();
}

class _PdfViewerScreenState extends State<PdfViewerScreen> {
  late Uint8List _bytes = widget.bytes;
  late final String _name = widget.name.toLowerCase().endsWith('.pdf') ? widget.name : '${widget.name}.pdf';
  bool _busy = false, _changed = false;
  int _rev = 0;

  bool get _encrypted {
    // « /Encrypt » dans le dictionnaire de fin de fichier : PDF protégé par mot de passe
    final tail = _bytes.length > 4096 ? _bytes.sublist(_bytes.length - 4096) : _bytes;
    return latin1.decode(tail, allowInvalid: true).contains('/Encrypt') || latin1.decode(_bytes.length > 2048 ? _bytes.sublist(0, 2048) : _bytes, allowInvalid: true).contains('/Encrypt');
  }

  /// Nouveau contenu (après modification, protection…) : affiché tout de suite, et enregistré si l'écran l'autorise.
  Future<void> _apply(Uint8List bytes, String name, String done) async {
    setState(() => (_bytes = bytes, _rev++, _changed = true));
    if (widget.onSave != null) {
      setState(() => _busy = true);
      try {
        await widget.onSave!(bytes, _name);
        _changed = false;
        if (mounted) toast(context, tr('$done Enregistré dans Documents (nouvelle version).'));
      } catch (e) {
        if (mounted) toast(context, errorText(e), error: true);
      } finally {
        if (mounted) setState(() => _busy = false);
      }
    } else if (mounted) {
      toast(context, tr('$done Pensez à télécharger le fichier.'));
    }
  }

  Future<void> _run(String op, Map<String, dynamic> options, String done) async {
    setState(() => _busy = true);
    try {
      final (out, _) = await context.read<Session>().api.uploadForFile('pdf/$op', {'options': jsonEncode(options)}, [('files[0]', _bytes, _name)]);
      if (!mounted) return;
      setState(() => _busy = false);
      await _apply(Uint8List.fromList(out), _name, done);
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  Future<String?> _askPassword({required bool confirm}) async {
    final a = TextEditingController(), b = TextEditingController();
    String? err;
    return showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => YAlertDialog(
          icon: Icon(confirm ? Icons.lock_outline : Icons.lock_open_outlined, color: YColors.blue, size: 34),
          title: Text(tr(confirm ? 'Protéger par mot de passe' : 'Retirer la protection')),
          content: SizedBox(
            width: 380,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: a, obscureText: true, autofocus: true, decoration: InputDecoration(labelText: tr(confirm ? 'Mot de passe' : 'Mot de passe actuel'), errorText: err)),
              if (confirm) ...[
                const SizedBox(height: 10),
                TextField(controller: b, obscureText: true, decoration: InputDecoration(labelText: tr('Confirmez le mot de passe'))),
                const SizedBox(height: 10),
                Text(tr('Chiffrement AES-256. Gardez le mot de passe en lieu sûr : il ne peut pas être retrouvé.'), style: TextStyle(fontSize: 12.5, color: Theme.of(ctx).hintColor)),
              ],
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
            FilledButton(
              onPressed: () {
                if (a.text.length < 4) return set(() => err = tr('Au moins 4 caractères.'));
                if (confirm && a.text != b.text) return set(() => err = tr('Les deux mots de passe sont différents.'));
                Navigator.pop(ctx, a.text);
              },
              child: Text(tr(confirm ? 'Protéger' : 'Déverrouiller')),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _protect() async {
    final p = await _askPassword(confirm: true);
    if (p != null) await _run('protect', {'password': p, 'allow': ['print', 'copy']}, 'PDF protégé.');
  }

  Future<void> _unlock() async {
    final p = await _askPassword(confirm: false);
    if (p != null) await _run('unlock', {'password': p}, 'Protection retirée.');
  }

  Future<void> _edit() async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => PdfEditorScreen(bytes: _bytes, name: _name, onSaved: (b, n) async {
        await _apply(b, n, 'PDF modifié.');
      }),
    ));
  }

  Future<void> _tools() async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => PdfToolsScreenPicker(bytes: _bytes, name: _name, onSaved: (b, n) async {
        await _apply(b, n, 'PDF transformé.');
      }),
    ));
  }

  Future<void> _download() async {
    final where = await saveFile(_name, _bytes, mime: 'application/pdf');
    if (mounted) toast(context, tr('Enregistré${where.isEmpty ? '' : ' : $where'}'));
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final wide = MediaQuery.sizeOf(context).width >= 760;
    final locked = _encrypted;
    final usePdf = context.read<Session>().can('pdf.use');
    final actions = <(IconData, String, VoidCallback?)>[
      if (usePdf && !locked) (Icons.edit_document, 'Modifier', _busy ? null : _edit),
      if (usePdf && !locked) (Icons.lock_outline, 'Protéger', _busy ? null : _protect),
      if (usePdf && locked) (Icons.lock_open_outlined, 'Retirer la protection', _busy ? null : _unlock),
      if (usePdf && !locked) (Icons.auto_fix_high_outlined, 'Autres outils', _busy ? null : _tools),
      (Icons.print_outlined, 'Imprimer', () => Printing.layoutPdf(name: _name, onLayout: (_) async => _bytes)),
      (Icons.download_outlined, 'Télécharger', _download),
    ];
    return PopScope(
      canPop: !_changed || widget.onSave != null,
      onPopInvokedWithResult: (did, _) async {
        if (did) return;
        if (await confirm(context, tr('Quitter sans télécharger ?'), tr('Le PDF modifié n\'est enregistré nulle part.'), ok: tr('Quitter')) && context.mounted) {
          setState(() => _changed = false);
          Navigator.pop(context);
        }
      },
      child: Scaffold(
        backgroundColor: cs.surfaceContainerHighest.withValues(alpha: .6),
        appBar: AppBar(
          title: Row(children: [
            const Icon(Icons.picture_as_pdf, color: Color(0xFFE5484D)),
            const SizedBox(width: 10),
            Expanded(child: Text(_name, overflow: TextOverflow.ellipsis)),
            if (locked) Padding(padding: const EdgeInsets.only(left: 8), child: StatusChip(tr('Protégé'), color: YColors.warning)),
          ]),
          actions: [
            if (_busy) const Padding(padding: EdgeInsets.all(16), child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))),
            if (wide)
              for (final a in actions) Padding(padding: const EdgeInsets.only(right: 4), child: TextButton.icon(onPressed: a.$3, icon: Icon(a.$1, size: 19), label: Text(tr(a.$2))))
            else
              PopupMenuButton<int>(
                onSelected: (i) => actions[i].$3?.call(),
                itemBuilder: (_) => [for (final (i, a) in actions.indexed) PopupMenuItem(value: i, enabled: a.$3 != null, child: ListTile(leading: Icon(a.$1), title: Text(tr(a.$2)), contentPadding: EdgeInsets.zero))],
              ),
            const SizedBox(width: 6),
          ],
        ),
        body: locked
            ? Center(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  const Icon(Icons.lock_outline, size: 56, color: YColors.warning),
                  const SizedBox(height: 12),
                  Text(tr('Ce PDF est protégé par un mot de passe.'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                  const SizedBox(height: 6),
                  Text(tr('Retirez la protection pour le lire et le modifier ici.'), style: TextStyle(color: Theme.of(context).hintColor)),
                  const SizedBox(height: 16),
                  if (usePdf) FilledButton.icon(onPressed: _busy ? null : _unlock, icon: const Icon(Icons.lock_open_outlined), label: Text(tr('Saisir le mot de passe'))),
                ]),
              )
            : PdfPreview(
                key: ValueKey(_rev),
                build: (_) async => _bytes,
                useActions: false,
                canChangePageFormat: false,
                canChangeOrientation: false,
                canDebug: false,
                maxPageWidth: 900,
                pdfFileName: _name,
                scrollViewDecoration: BoxDecoration(color: cs.surfaceContainerHighest.withValues(alpha: .6)),
                pdfPreviewPageDecoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(4), boxShadow: const [BoxShadow(color: Color(0x22000000), blurRadius: 12, offset: Offset(0, 4))]),
                loadingWidget: const Center(child: CircularProgressIndicator()),
                onError: (ctx, e) => Center(child: Text(tr('Ce PDF ne peut pas être affiché : $e'), textAlign: TextAlign.center)),
              ),
      ),
    );
  }
}
