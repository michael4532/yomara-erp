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
    final steps = [
      (Icons.open_in_new_rounded, tr('Ouvrez WhatsApp'), tr(kIsWeb ? 'Il s\'ouvre dans une fenêtre accolée à droite de l\'ERP.' : 'Dans l\'application WhatsApp de l\'ordinateur, sinon dans une fenêtre WhatsApp dédiée, sans barre d\'adresse.')),
      (Icons.qr_code_scanner_rounded, tr('Liez votre téléphone une seule fois'), tr('Scannez le QR code avec WhatsApp sur votre téléphone : la session reste ouverte.')),
      (Icons.receipt_long_rounded, tr('Écrivez à vos clients depuis l\'ERP'), tr('Les boutons « WhatsApp » des fiches clients, devis et factures ouvrent directement la bonne conversation.')),
    ];
    final hero = Container(
      padding: const EdgeInsets.all(28),
      decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFF0B3D36), Color(0xFF128C7E)], begin: Alignment.topLeft, end: Alignment.bottomRight)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        Container(
          width: 52,
          height: 52,
          decoration: BoxDecoration(color: const Color(0xFF25D366), borderRadius: BorderRadius.circular(15)),
          child: const Icon(Icons.chat_rounded, color: Colors.white, size: 28),
        ),
        const SizedBox(height: 18),
        Text(tr('WhatsApp, à côté de votre ERP'), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800, height: 1.2)),
        const SizedBox(height: 10),
        Text(
          tr(kIsWeb ? 'WhatsApp n\'autorise pas son affichage dans une page web : il s\'ouvre dans sa propre fenêtre.' : 'WhatsApp s\'ouvre dans sa propre fenêtre : il ne peut jamais bloquer ni fermer l\'ERP.'),
          style: const TextStyle(color: Color(0xDDFFFFFF), height: 1.45),
        ),
      ]),
    );
    final details = Padding(
      padding: const EdgeInsets.all(28),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        for (final (icon, title, text) in steps)
          Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(color: const Color(0xFF25D366).withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
                child: Icon(icon, size: 19, color: const Color(0xFF128C7E)),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 3),
                  Text(text, style: TextStyle(color: hint, fontSize: 13, height: 1.4)),
                ]),
              ),
            ]),
          ),
        const SizedBox(height: 4),
        SizedBox(
          width: double.infinity,
          child: FilledButton.icon(
            style: FilledButton.styleFrom(backgroundColor: const Color(0xFF128C7E), padding: const EdgeInsets.symmetric(vertical: 16), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
            onPressed: () => openWhatsAppWindow(initialUrl),
            icon: const Icon(Icons.open_in_new),
            label: Text(tr('Ouvrir WhatsApp')),
          ),
        ),
        const SizedBox(height: 14),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Icon(Icons.lock_outline, size: 15, color: YColors.blue),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              tr('Vos messages restent privés : l\'ERP n\'enregistre pas vos conversations. Pour un numéro d\'entreprise partagé avec relances automatiques, utilisez le module WhatsApp Business.'),
              style: TextStyle(color: hint, fontSize: 12, height: 1.4),
            ),
          ),
        ]),
      ]),
    );
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 860),
          child: Card(
            clipBehavior: Clip.antiAlias,
            elevation: 3,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            child: LayoutBuilder(
              builder: (c, k) => k.maxWidth >= 680
                  ? IntrinsicHeight(child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [SizedBox(width: 320, child: hero), Expanded(child: details)]))
                  : Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [hero, details]),
            ),
          ),
        ),
      ),
    );
  }
}
