import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr/qr.dart';

import '../../files/save_file.dart';
import '../../i18n/i18n.dart';
import '../theme.dart';
import 'common.dart';

/// QR code dessiné dans l'application (sans réseau) : utilisable depuis n'importe quel écran.
class QrView extends StatelessWidget {
  const QrView({super.key, required this.data, this.size = 220, this.color = YColors.night, this.background = Colors.white, this.dots = false});

  final String data;
  final double size;
  final Color color;
  final Color background;
  final bool dots;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: size,
        height: size,
        child: CustomPaint(painter: QrPainter(data: data, color: color, background: background, dots: dots)),
      );
}

class QrPainter extends CustomPainter {
  QrPainter({required this.data, this.color = YColors.night, this.background = Colors.white, this.dots = false})
      : _qr = QrImage(QrCode.fromData(data: data, errorCorrectLevel: QrErrorCorrectLevel.M));

  final String data;
  final Color color;
  final Color background;
  final bool dots;
  final QrImage _qr;

  @override
  void paint(Canvas canvas, Size size) {
    final n = _qr.moduleCount;
    const quiet = 3;
    final cell = size.width / (n + 2 * quiet);
    canvas.drawRect(Offset.zero & size, Paint()..color = background);
    final p = Paint()
      ..color = color
      ..isAntiAlias = dots;
    bool finder(int r, int c) => (r < 7 && c < 7) || (r < 7 && c >= n - 7) || (r >= n - 7 && c < 7);
    for (var r = 0; r < n; r++) {
      for (var c = 0; c < n; c++) {
        if (!_qr.isDark(r, c)) continue;
        final x = (c + quiet) * cell;
        final y = (r + quiet) * cell;
        if (dots && !finder(r, c)) {
          canvas.drawCircle(Offset(x + cell / 2, y + cell / 2), cell * .45, p);
        } else {
          canvas.drawRect(Rect.fromLTWH(x, y, cell + .5, cell + .5), p);
        }
      }
    }
  }

  @override
  bool shouldRepaint(QrPainter old) => old.data != data || old.color != color || old.background != background || old.dots != dots;

  /// Image PNG du QR code (pour l'enregistrer ou l'imprimer).
  static Future<List<int>> png(String data, {double size = 1024, Color color = YColors.night, bool dots = false}) async {
    final rec = ui.PictureRecorder();
    QrPainter(data: data, color: color, dots: dots).paint(Canvas(rec), Size(size, size));
    final img = await rec.endRecording().toImage(size.round(), size.round());
    final bytes = await img.toByteData(format: ui.ImageByteFormat.png);
    return bytes!.buffer.asUint8List();
  }
}

/// Fenêtre « QR code » : affiche, copie le lien et enregistre l'image. À appeler depuis n'importe quel outil.
Future<void> showQrShareDialog(BuildContext context, {required String data, String? title, String? subtitle}) {
  return showDialog<void>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(tr(title ?? 'QR code')),
      content: SizedBox(
        width: 320,
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          if (subtitle != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(subtitle, textAlign: TextAlign.center, style: TextStyle(color: Theme.of(ctx).hintColor))),
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: YColors.line)),
            child: QrView(data: data, size: 240),
          ),
          const SizedBox(height: 12),
          SelectableText(data, textAlign: TextAlign.center, style: const TextStyle(fontSize: 12.5)),
        ]),
      ),
      actions: [
        TextButton.icon(
          onPressed: () {
            Clipboard.setData(ClipboardData(text: data));
            toast(ctx, tr('Lien copié.'));
          },
          icon: const Icon(Icons.copy, size: 18),
          label: Text(tr('Copier')),
        ),
        FilledButton.icon(
          onPressed: () async {
            final bytes = await QrPainter.png(data);
            final name = 'qr-${(title ?? 'code').toLowerCase().replaceAll(RegExp(r'[^a-z0-9]+'), '-')}.png';
            await saveFile(name, bytes, mime: 'image/png');
            if (ctx.mounted) toast(ctx, tr('Image du QR code enregistrée.'));
          },
          icon: const Icon(Icons.download, size: 18),
          label: Text(tr('Télécharger')),
        ),
      ],
    ),
  );
}
