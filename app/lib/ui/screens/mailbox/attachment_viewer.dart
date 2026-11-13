import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:url_launcher/url_launcher.dart';

import 'package:yomara_erp/core/safe_launch.dart';
import '../../../files/save_file.dart';
import '../../../i18n/i18n.dart';
import '../../widgets/pdf_viewer.dart';

/// Pièces jointes de la boîte mail : images et PDF s'ouvrent dans l'application (zoom, défilement, téléchargement),
/// les autres fichiers dans l'application adaptée de l'appareil.
bool attachmentIsImage(Map<String, dynamic> a) {
  final mime = '${a['mime'] ?? ''}'.toLowerCase();
  final name = '${a['name'] ?? ''}'.toLowerCase();
  return mime.startsWith('image/') && !mime.contains('tiff') || RegExp(r'\.(png|jpe?g|gif|webp|bmp|heic)$').hasMatch(name);
}

bool attachmentIsPdf(Map<String, dynamic> a) =>
    '${a['mime'] ?? ''}'.toLowerCase().contains('pdf') || '${a['name'] ?? ''}'.toLowerCase().endsWith('.pdf');

Future<void> openMailAttachment(BuildContext context, Map<String, dynamic> a, List<Map<String, dynamic>> all) async {
  final url = a['url'] as String?;
  if (url == null) return;
  if (attachmentIsImage(a)) {
    final images = all.where((x) => x['url'] != null && attachmentIsImage(x)).toList();
    await Navigator.of(context).push(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (_) => _ImageViewer(images: images, initial: images.indexOf(a).clamp(0, images.length - 1)),
    ));
    return;
  }
  if (attachmentIsPdf(a)) {
    final messenger = ScaffoldMessenger.maybeOf(context);
    final nav = Navigator.of(context);
    showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (_) => const Center(child: Card(child: Padding(padding: EdgeInsets.all(22), child: CircularProgressIndicator()))),
    );
    Uint8List? bytes;
    try {
      final r = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 60));
      if (r.statusCode == 200) bytes = r.bodyBytes;
    } catch (_) {}
    nav.pop();
    if (bytes == null) {
      messenger?.showSnackBar(SnackBar(content: Text(tr('Impossible d\'ouvrir la pièce jointe. Vérifiez la connexion.'))));
      return;
    }
    if (context.mounted) await showPdf(context, bytes, '${a['name'] ?? 'document.pdf'}');
    return;
  }
  await safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank');
}

class _ImageViewer extends StatefulWidget {
  const _ImageViewer({required this.images, required this.initial});

  final List<Map<String, dynamic>> images;
  final int initial;

  @override
  State<_ImageViewer> createState() => _ImageViewerState();
}

class _ImageViewerState extends State<_ImageViewer> {
  late final PageController _pc = PageController(initialPage: widget.initial);
  late int _i = widget.initial;
  bool _saving = false;

  Map<String, dynamic> get _cur => widget.images[_i];

  Future<void> _save() async {
    setState(() => _saving = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final r = await http.get(Uri.parse('${_cur['url']}')).timeout(const Duration(seconds: 60));
      if (r.statusCode != 200) throw Exception();
      final where = await saveFile('${_cur['name'] ?? 'image'}', r.bodyBytes, mime: '${_cur['mime'] ?? 'image/jpeg'}');
      messenger.showSnackBar(SnackBar(content: Text(where.isEmpty ? tr('Image enregistrée') : tr('Image enregistrée : $where'))));
    } catch (_) {
      messenger.showSnackBar(SnackBar(content: Text(tr('Enregistrement impossible. Vérifiez la connexion.'))));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  void dispose() {
    _pc.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final n = widget.images.length;
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${_cur['name'] ?? tr('Image')}', overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
          if (n > 1) Text(tr('${_i + 1} sur $n'), style: const TextStyle(fontSize: 12, color: Colors.white70)),
        ]),
        actions: [
          IconButton(
            tooltip: tr('Enregistrer'),
            onPressed: _saving ? null : _save,
            icon: _saving ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.download_rounded),
          ),
          IconButton(
            tooltip: tr('Ouvrir avec une autre application'),
            onPressed: () => safeLaunchUrl(Uri.parse('${_cur['url']}'), mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank'),
            icon: const Icon(Icons.open_in_new),
          ),
        ],
      ),
      body: PageView.builder(
        controller: _pc,
        itemCount: n,
        onPageChanged: (i) => setState(() => _i = i),
        itemBuilder: (_, i) => InteractiveViewer(
          minScale: 1,
          maxScale: 5,
          child: Center(
            child: Image.network(
              '${widget.images[i]['url']}',
              fit: BoxFit.contain,
              loadingBuilder: (c, child, p) => p == null ? child : const Center(child: CircularProgressIndicator(color: Colors.white)),
              errorBuilder: (c, e, s) => Column(mainAxisSize: MainAxisSize.min, children: [
                const Icon(Icons.broken_image_outlined, color: Colors.white54, size: 48),
                const SizedBox(height: 8),
                Text(tr('Image illisible ici'), style: const TextStyle(color: Colors.white70)),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// Enregistre une pièce jointe sur l'appareil (Téléchargements, ou partage sur téléphone selon l'appareil).
/// Renvoie false si le fichier n'a pas pu être récupéré.
Future<bool> saveMailAttachment(Map<String, dynamic> a) async {
  final url = a['url'] as String?;
  if (url == null) return false;
  try {
    final r = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 90));
    if (r.statusCode != 200) return false;
    await saveFile('${a['name'] ?? 'piece-jointe'}', r.bodyBytes, mime: '${a['mime'] ?? 'application/octet-stream'}');
    return true;
  } catch (_) {
    return false;
  }
}

Future<void> saveMailAttachments(BuildContext context, List<Map<String, dynamic>> atts) async {
  final messenger = ScaffoldMessenger.maybeOf(context);
  var ok = 0;
  for (final a in atts.where((x) => x['url'] != null)) {
    if (await saveMailAttachment(a)) ok++;
  }
  final n = atts.where((x) => x['url'] != null).length;
  messenger?.showSnackBar(SnackBar(
    content: Text(ok == n
        ? tr(n > 1 ? '$n pièces jointes enregistrées' : 'Pièce jointe enregistrée')
        : tr('$ok sur $n enregistrées. Vérifiez la connexion.')),
  ));
}
