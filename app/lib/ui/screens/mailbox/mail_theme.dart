import 'dart:math' as math;

import 'package:barcode/barcode.dart';
import 'package:flutter/material.dart';

import '../../../core/local_db.dart';
import '../../../i18n/i18n.dart';
import '../../navigation.dart' show UiPrefs;
import '../../widgets/common.dart' show YAlertDialog;

import 'package:yomara_erp/core/safe_launch.dart';
/// Thèmes d'arrière-plan de la boîte mail (comme dans Outlook) : couleur d'accent, bandeau dégradé et motif.
class MailTheme {
  const MailTheme(this.key, this.name, this.accent, this.colors, {this.pattern = 'none', this.dark = false});

  final String key, name;
  final Color accent;
  final List<Color> colors;
  final String pattern; // none, dots, waves, wax, triangles, bubbles
  final bool dark;

  Color get onBand => dark || colors.first.computeLuminance() < .45 ? Colors.white : const Color(0xFF13203A);

  static const all = [
    MailTheme('yomara', 'Yomara', Color(0xFF2E6BE6), [Color(0xFFEAF1FF), Color(0xFFF6F9FF)]),
    MailTheme('corail', 'Corail', Color(0xFFE5532D), [Color(0xFFE5532D), Color(0xFFF08A4B)], pattern: 'none'),
    MailTheme('ciel', 'Ciel', Color(0xFF0F6CBD), [Color(0xFF0F6CBD), Color(0xFF4FA3F7)], pattern: 'bubbles'),
    MailTheme('lagune', 'Lagune Ébrié', Color(0xFF00897B), [Color(0xFF00695C), Color(0xFF26C6DA)], pattern: 'waves'),
    MailTheme('savane', 'Savane', Color(0xFFB7791F), [Color(0xFFF6C453), Color(0xFFE59A3C)], pattern: 'triangles'),
    MailTheme('wax', 'Wax', Color(0xFFC2185B), [Color(0xFF8E24AA), Color(0xFFF57C00)], pattern: 'wax'),
    MailTheme('foret', 'Forêt du Banco', Color(0xFF2E7D32), [Color(0xFF1B5E20), Color(0xFF66BB6A)], pattern: 'triangles'),
    MailTheme('lavande', 'Lavande', Color(0xFF6A4BD8), [Color(0xFF7E57C2), Color(0xFFB39DDB)], pattern: 'dots'),
    MailTheme('coucher', 'Coucher de soleil', Color(0xFFD84315), [Color(0xFFFF7043), Color(0xFFAB47BC)], pattern: 'waves'),
    MailTheme('ardoise', 'Ardoise', Color(0xFF455A64), [Color(0xFF37474F), Color(0xFF78909C)], pattern: 'dots'),
    MailTheme('nuit', 'Nuit', Color(0xFF64B5F6), [Color(0xFF0B1B3A), Color(0xFF1E3A6E)], pattern: 'bubbles', dark: true),
    MailTheme('kente', 'Kente', Color(0xFF2E7D32), [Color(0xFFFBC02D), Color(0xFFE53935)], pattern: 'wax'),
  ];

  /// Thème suivi d'un appareil à l'autre (préférence gardée sur le serveur).
  static MailTheme get current {
    final synced = UiPrefs.list('mb_theme');
    final k = synced.isNotEmpty ? synced.first : LocalDb.meta.get('mb_theme') as String?;
    return all.firstWhere((t) => t.key == k, orElse: () => all.first);
  }

  static Future<void> save(MailTheme t) async {
    await LocalDb.meta.put('mb_theme', t.key);
    await UiPrefs.save('mb_theme', [t.key]);
  }

  /// Dégradé du bouton « Nouveau courrier » (sarcelle → bleu pour le thème par défaut, couleurs du thème sinon).
  List<Color> get buttonGradient {
    if (key == 'yomara') return const [Color(0xFF0EA5A4), Color(0xFF2563EB)];
    if (onBand == Colors.white) return colors;
    final h = HSLColor.fromColor(accent);
    return [h.withLightness((h.lightness + .1).clamp(0, .6)).toColor(), accent];
  }
}

/// Jetons de couleur de la messagerie (fond de page, cartes, lignes, sélection), clairs ou sombres selon le thème de l'appli.
class MailPalette {
  const MailPalette._(this.dark, this.accent, this.page, this.card, this.line, this.selected, this.soft, this.ink, this.muted, this.shadow);

  factory MailPalette.of(BuildContext context, Color accent) {
    final t = Theme.of(context);
    final dark = t.brightness == Brightness.dark;
    return MailPalette._(
      dark,
      accent,
      dark ? const Color(0xFF0B1322) : const Color(0xFFEEF3F8),
      t.colorScheme.surface,
      dark ? Colors.white.withValues(alpha: .08) : const Color(0xFFE6ECF3),
      dark ? accent.withValues(alpha: .22) : Color.alphaBlend(accent.withValues(alpha: .11), Colors.white),
      dark ? Colors.white.withValues(alpha: .05) : const Color(0xFFF3F6FA),
      dark ? const Color(0xFFE6ECF5) : const Color(0xFF0F172A),
      t.hintColor,
      dark ? Colors.black.withValues(alpha: .35) : const Color(0xFF1E3A5F).withValues(alpha: .07),
    );
  }

  final bool dark;
  final Color accent, page, card, line, selected, soft, ink, muted, shadow;

  /// Carte blanche arrondie (16 px) à ombre douce : les trois volets de la boîte.
  BoxDecoration get cardBox => BoxDecoration(
        color: card,
        borderRadius: BorderRadius.circular(16),
        border: dark ? Border.all(color: line) : null,
        boxShadow: [BoxShadow(color: shadow, blurRadius: 18, offset: const Offset(0, 4))],
      );

  /// Couleur d'un dossier personnel (stable selon son nom).
  static const folderColors = [Color(0xFF3B82F6), Color(0xFFF59E0B), Color(0xFF6366F1), Color(0xFFA855F7), Color(0xFF14B8A6), Color(0xFFEC4899), Color(0xFF22C55E), Color(0xFFEF4444)];

  static Color folderColor(String name) => folderColors[name.codeUnits.fold<int>(0, (a, b) => a + b) % folderColors.length];
}

/// Petite pastille de statut arrondie (« Synchronisé », « En direct »…).
class MailStatusChip extends StatelessWidget {
  const MailStatusChip({super.key, required this.leading, required this.label, this.color, this.onTap, this.tooltip});

  final Widget leading;
  final String label;
  final Color? color;
  final VoidCallback? onTap;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).colorScheme.onSurface;
    final chip = Material(
      color: c.withValues(alpha: .09),
      shape: StadiumBorder(side: BorderSide(color: c.withValues(alpha: .16))),
      child: InkWell(
        customBorder: const StadiumBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            leading,
            const SizedBox(width: 6),
            Text(tr(label), style: TextStyle(color: c, fontSize: 12.5, fontWeight: FontWeight.w600)),
          ]),
        ),
      ),
    );
    return tooltip == null ? chip : Tooltip(message: tooltip!, child: chip);
  }
}

/// Bouton principal « Nouveau courrier » : grand, arrondi, en dégradé.
class ComposeButton extends StatelessWidget {
  const ComposeButton({super.key, required this.theme, required this.onPressed, this.compact = false});

  final MailTheme theme;
  final VoidCallback onPressed;
  final bool compact;

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(colors: theme.buttonGradient),
          borderRadius: BorderRadius.circular(compact ? 22 : 16),
          boxShadow: [BoxShadow(color: theme.buttonGradient.last.withValues(alpha: .28), blurRadius: 12, offset: const Offset(0, 4))],
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            borderRadius: BorderRadius.circular(compact ? 22 : 16),
            onTap: onPressed,
            child: Padding(
              padding: EdgeInsets.symmetric(horizontal: compact ? 16 : 18, vertical: compact ? 10 : 15),
              child: Row(mainAxisSize: compact ? MainAxisSize.min : MainAxisSize.max, mainAxisAlignment: MainAxisAlignment.center, children: [
                const Icon(Icons.mail_outline_rounded, color: Colors.white, size: 21),
                const SizedBox(width: 10),
                Text(tr('Nouveau courrier'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 15)),
              ]),
            ),
          ),
        ),
      );
}

/// Bandeau du thème (dégradé + motif dessiné).
class ThemeBand extends StatelessWidget {
  const ThemeBand({super.key, required this.theme, required this.child, this.radius = 0});

  final MailTheme theme;
  final Widget child;
  final double radius;

  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(radius),
        child: CustomPaint(
          painter: _PatternPainter(theme),
          child: child,
        ),
      );
}

class _PatternPainter extends CustomPainter {
  _PatternPainter(this.t);

  final MailTheme t;

  @override
  void paint(Canvas canvas, Size s) {
    final rect = Offset.zero & s;
    canvas.drawRect(rect, Paint()..shader = LinearGradient(colors: t.colors, begin: Alignment.centerLeft, end: Alignment.centerRight).createShader(rect));
    final p = Paint()..color = Colors.white.withValues(alpha: t.dark ? .07 : .16);
    switch (t.pattern) {
      case 'dots':
        for (var x = 8.0; x < s.width; x += 18) {
          for (var y = 6.0; y < s.height; y += 16) {
            canvas.drawCircle(Offset(x + ((y ~/ 16) % 2) * 9.0, y), 1.8, p);
          }
        }
      case 'bubbles':
        final r = math.Random(7);
        for (var i = 0; i < s.width / 40; i++) {
          canvas.drawCircle(Offset(r.nextDouble() * s.width, r.nextDouble() * s.height), 8 + r.nextDouble() * 26, p);
        }
      case 'waves':
        p
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2;
        for (var k = 0; k < 4; k++) {
          final path = Path()..moveTo(0, s.height * (.25 + k * .2));
          for (var x = 0.0; x <= s.width; x += 8) {
            path.lineTo(x, s.height * (.25 + k * .2) + math.sin((x / 60) + k) * 6);
          }
          canvas.drawPath(path, p);
        }
      case 'triangles':
        for (var x = -20.0; x < s.width; x += 46) {
          final path = Path()
            ..moveTo(x, s.height)
            ..lineTo(x + 23, s.height * .35)
            ..lineTo(x + 46, s.height)
            ..close();
          canvas.drawPath(path, p);
        }
      case 'wax':
        final stroke = Paint()
          ..color = Colors.white.withValues(alpha: .22)
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2;
        for (var x = 14.0; x < s.width; x += 36) {
          for (var y = 10.0; y < s.height + 20; y += 36) {
            canvas.drawCircle(Offset(x, y), 10, stroke);
            canvas.drawCircle(Offset(x, y), 4, p);
            canvas.drawLine(Offset(x + 14, y - 14), Offset(x + 22, y - 22), stroke);
          }
        }
    }
  }

  @override
  bool shouldRepaint(covariant _PatternPainter old) => old.t.key != t.key;
}

/// Choix du thème (aperçus, comme dans Outlook).
Future<MailTheme?> pickMailTheme(BuildContext context, MailTheme current) => showDialog<MailTheme>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Thème de la boîte mail')),
        content: SizedBox(
          width: 560,
          child: GridView.count(
            shrinkWrap: true,
            crossAxisCount: MediaQuery.sizeOf(c).width < 600 ? 2 : 3,
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            childAspectRatio: 1.6,
            children: [
              for (final t in MailTheme.all)
                InkWell(
                  borderRadius: BorderRadius.circular(12),
                  onTap: () => Navigator.pop(c, t),
                  child: Container(
                    decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), border: Border.all(color: t.key == current.key ? t.accent : Colors.black12, width: t.key == current.key ? 3 : 1)),
                    child: Column(children: [
                      Expanded(child: ThemeBand(theme: t, radius: 10, child: const SizedBox.expand())),
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                          CircleAvatar(radius: 5, backgroundColor: t.accent),
                          const SizedBox(width: 6),
                          Text(tr(t.name), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12.5)),
                        ]),
                      ),
                    ]),
                  ),
                ),
            ],
          ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
      ),
    );

/// QR code dessiné localement (paquet barcode).
class QrBox extends StatelessWidget {
  const QrBox({super.key, required this.data, this.size = 200});

  final String data;
  final double size;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: Colors.black12)),
        child: SizedBox(width: size, height: size, child: CustomPaint(painter: _QrPainter(data))),
      );
}

class _QrPainter extends CustomPainter {
  _QrPainter(this.data);

  final String data;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()..color = const Color(0xFF0B1B3A);
    for (final e in Barcode.qrCode().make(data, width: size.width, height: size.height)) {
      if (e is BarcodeBar && e.black) canvas.drawRect(Rect.fromLTWH(e.left, e.top, e.width + .3, e.height + .3), p);
    }
  }

  @override
  bool shouldRepaint(covariant _QrPainter old) => old.data != data;
}

/// Après la configuration d'une boîte : inviter à installer l'application mobile.
Future<void> showMobileAppQr(BuildContext context, String url, {String? email, bool ready = true}) => showDialog(
      context: context,
      builder: (c) => YAlertDialog(
        title: Row(children: [Icon(ready ? Icons.check_circle : Icons.smartphone, color: const Color(0xFF12A150)), const SizedBox(width: 10), Expanded(child: Text(tr(ready ? 'Votre boîte est prête !' : 'Yomara sur votre téléphone')))]),
        content: SizedBox(
          width: 420,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(tr(email == null ? 'Retrouvez vos e-mails sur votre téléphone.' : 'Retrouvez $email sur votre téléphone.'), textAlign: TextAlign.center),
            const SizedBox(height: 4),
            Text(tr('Scannez ce code avec l\'appareil photo pour installer l\'application Yomara.'), textAlign: TextAlign.center, style: TextStyle(color: Theme.of(c).hintColor, fontSize: 13)),
            const SizedBox(height: 16),
            QrBox(data: url),
            const SizedBox(height: 10),
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              const Icon(Icons.android, color: Color(0xFF12A150)),
              const SizedBox(width: 8),
              const Icon(Icons.phone_iphone),
              const SizedBox(width: 8),
              Flexible(child: SelectableText(tr(url), style: const TextStyle(fontSize: 12))),
            ]),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => safeLaunchUrl(Uri.parse(url), webOnlyWindowName: '_blank'), child: Text(tr('Ouvrir la page'))),
          FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Terminer'))),
        ],
      ),
    );
