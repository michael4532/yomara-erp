import 'package:flutter/material.dart';
import 'package:web/web.dart' as web;

/// Navigateur : WhatsApp interdit son affichage dans une page (iframe) ; il s'ouvre dans une fenêtre
/// accolée à droite de l'ERP, toujours la même (réutilisée à chaque conversation).
bool get whatsappEmbedded => false;

Future<void> openWhatsAppWindow(String url) async {
  final sw = web.window.screen.availWidth;
  final sh = web.window.screen.availHeight;
  const w = 560;
  final left = (sw - w).clamp(0, sw);
  final win = web.window.open(url, 'yomara_whatsapp', 'popup=yes,width=$w,height=$sh,left=$left,top=0');
  win?.focus();
}

class WhatsAppWebView extends StatelessWidget {
  const WhatsAppWebView({super.key, this.url = 'https://web.whatsapp.com/'});

  final String url;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
