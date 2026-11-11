import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:yomara_erp/core/safe_launch.dart';

/// Applications installées 1.0.8 : WhatsApp Web s'ouvre dans le navigateur
/// (la version 1.0.9 l'affiche dans l'application).
bool get whatsappEmbedded => false;

/// Ouvre WhatsApp Web (ou une conversation) hors de l'application.
Future<void> openWhatsAppWindow(String url) => safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);

class WhatsAppWebView extends StatelessWidget {
  const WhatsAppWebView({super.key, this.url = 'https://web.whatsapp.com/'});

  final String url;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
