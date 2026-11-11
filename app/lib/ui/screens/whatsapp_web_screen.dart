import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:yomara_erp/core/safe_launch.dart';
import '../../i18n/i18n.dart';
import '../theme.dart';
import '../widgets/whatsapp_web_frame.dart';

/// Numéro au format international sans « + » (0X XX… ivoirien → 225…).
String waDigits(String phone) {
  var d = phone.replaceAll(RegExp(r'\D'), '');
  if (d.startsWith('00')) d = d.substring(2);
  if (d.length == 10 && d.startsWith('0')) d = '225$d'; // numéro ivoirien à 10 chiffres
  return d;
}

/// Ouvre une conversation WhatsApp avec un contact, au mieux selon l'appareil :
/// application WhatsApp sur téléphone, WhatsApp Web intégré (1.0.9) ou fenêtre accolée sur ordinateur.
Future<void> openWhatsAppChat(BuildContext context, String phone, {String? text}) async {
  final d = waDigits(phone);
  if (d.isEmpty) return;
  final q = text == null || text.isEmpty ? '' : '&text=${Uri.encodeComponent(text)}';
  final mobile = !kIsWeb && (Theme.of(context).platform == TargetPlatform.android || Theme.of(context).platform == TargetPlatform.iOS);
  if (mobile) {
    await safeLaunchUrl(Uri.parse('https://wa.me/$d${q.isEmpty ? '' : '?${q.substring(1)}'}'), mode: LaunchMode.externalApplication);
    return;
  }
  final url = 'https://web.whatsapp.com/send?phone=$d$q';
  if (whatsappEmbedded) {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => WhatsAppWebScreen(initialUrl: url, standalone: true)));
  } else {
    await openWhatsAppWindow(url);
  }
}

/// WhatsApp Web dans l'ERP : les utilisateurs restent concentrés dans l'application.
class WhatsAppWebScreen extends StatelessWidget {
  const WhatsAppWebScreen({super.key, this.initialUrl = 'https://web.whatsapp.com/', this.standalone = false});

  final String initialUrl;
  final bool standalone;

  @override
  Widget build(BuildContext context) {
    final body = whatsappEmbedded ? WhatsAppWebView(url: initialUrl) : _launcher(context);
    if (!standalone) return body;
    return Scaffold(appBar: AppBar(title: Text(tr('WhatsApp'))), body: body);
  }

  Widget _launcher(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 560),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 84,
              height: 84,
              decoration: BoxDecoration(color: const Color(0xFF25D366).withValues(alpha: .12), shape: BoxShape.circle),
              child: const Icon(Icons.chat, size: 42, color: Color(0xFF25D366)),
            ),
            const SizedBox(height: 16),
            Text(tr('WhatsApp Web'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            Text(
              tr(kIsWeb
                  ? 'WhatsApp n\'autorise pas son affichage à l\'intérieur d\'une page web : il s\'ouvre dans une fenêtre accolée à droite de l\'ERP. '
                      'Scannez le QR code une fois avec votre téléphone, la session reste ouverte. Les boutons « WhatsApp » des fiches clients et des factures y ouvrent directement la conversation.'
                  : 'Dans la version 1.0.9 de l\'application, WhatsApp Web s\'affiche ici même. En attendant la mise à jour, il s\'ouvre dans votre navigateur.'),
              textAlign: TextAlign.center,
              style: TextStyle(color: hint),
            ),
            const SizedBox(height: 20),
            FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: const Color(0xFF25D366), padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 16)),
              onPressed: () => openWhatsAppWindow(initialUrl),
              icon: const Icon(Icons.open_in_new),
              label: Text(tr('Ouvrir WhatsApp Web')),
            ),
            const SizedBox(height: 16),
            Card(
              child: ListTile(
                leading: const Icon(Icons.lock_outline, color: YColors.blue),
                title: Text(tr('Vos messages restent privés')),
                subtitle: Text(tr('La session WhatsApp est celle de votre téléphone ; l\'ERP n\'enregistre pas vos conversations. Pour un numéro d\'entreprise partagé avec relances automatiques, utilisez le module WhatsApp Business.')),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}
