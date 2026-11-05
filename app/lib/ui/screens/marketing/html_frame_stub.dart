import 'package:flutter/material.dart';

import '../../theme.dart';
import '../../../i18n/i18n.dart';

/// Hors navigateur : pas de moteur HTML intégré, on renvoie vers l'aperçu navigateur.
bool get htmlFrameSupported => false;

class HtmlFrame extends StatelessWidget {
  const HtmlFrame({super.key, required this.html});

  final String html;

  @override
  Widget build(BuildContext context) => Container(
        color: const Color(0xFFF4F6FB),
        alignment: Alignment.center,
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.code, size: 40, color: YColors.blue),
          const SizedBox(height: 8),
          Text(tr('E-mail en HTML personnalisé'), style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text(tr('${html.length} caractères · « Aperçu navigateur » affiche le rendu exact.'), textAlign: TextAlign.center, style: const TextStyle(color: YColors.muted)),
        ]),
      );
}
