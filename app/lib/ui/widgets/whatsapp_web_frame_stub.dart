import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:yomara_erp/core/safe_launch.dart';
import '../../i18n/i18n.dart';

/// Applications installées (Android, iPhone, Mac, Windows) : WhatsApp Web s'affiche dans l'ERP ;
/// la session (QR code scanné une fois) est conservée dans le profil de la vue web.
bool get whatsappEmbedded => Platform.isAndroid || Platform.isIOS || Platform.isMacOS || Platform.isWindows;

Future<void> openWhatsAppWindow(String url) => safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);

/// WhatsApp Web exige un navigateur de bureau récent : on se présente comme Chrome sur Windows.
const _desktopUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

WebViewEnvironment? _winEnv;
Future<WebViewEnvironment?> _windowsEnvironment() async {
  if (_winEnv != null) return _winEnv;
  if (await WebViewEnvironment.getAvailableVersion() == null) return null;
  final dir = await getApplicationSupportDirectory();
  return _winEnv = await WebViewEnvironment.create(settings: WebViewEnvironmentSettings(userDataFolder: '${dir.path}\\WebView2'));
}

class WhatsAppWebView extends StatefulWidget {
  const WhatsAppWebView({super.key, this.url = 'https://web.whatsapp.com/'});

  final String url;

  @override
  State<WhatsAppWebView> createState() => _WhatsAppWebViewState();
}

class _WhatsAppWebViewState extends State<WhatsAppWebView> {
  bool _ready = !Platform.isWindows, _failed = false;
  WebViewEnvironment? _env;
  InAppWebViewController? _c;

  @override
  void initState() {
    super.initState();
    if (Platform.isWindows) {
      _windowsEnvironment().then((e) {
        if (mounted) setState(() => (_env = e, _ready = true, _failed = e == null));
      }).catchError((_) {
        if (mounted) setState(() => (_ready = true, _failed = true));
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return Center(
        child: FilledButton.icon(onPressed: () => openWhatsAppWindow(widget.url), icon: const Icon(Icons.open_in_new), label: Text(tr('Ouvrir WhatsApp Web dans le navigateur'))),
      );
    }
    if (!_ready) return const Center(child: CircularProgressIndicator());
    return Column(children: [
      Material(
        color: const Color(0xFF075E54),
        child: SizedBox(
          height: 40,
          child: Row(children: [
            const SizedBox(width: 12),
            const Icon(Icons.chat, color: Colors.white, size: 18),
            const SizedBox(width: 8),
            Text(tr('WhatsApp'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
            const Spacer(),
            IconButton(tooltip: tr('Recharger'), color: Colors.white, iconSize: 18, onPressed: () => _c?.reload(), icon: const Icon(Icons.refresh)),
            IconButton(tooltip: tr('Ouvrir dans le navigateur'), color: Colors.white, iconSize: 18, onPressed: () => openWhatsAppWindow('https://web.whatsapp.com/'), icon: const Icon(Icons.open_in_new)),
          ]),
        ),
      ),
      Expanded(
        child: InAppWebView(
          webViewEnvironment: _env,
          initialUrlRequest: URLRequest(url: WebUri(widget.url)),
          initialSettings: InAppWebViewSettings(
            userAgent: _desktopUa,
            preferredContentMode: UserPreferredContentMode.DESKTOP,
            javaScriptEnabled: true,
            mediaPlaybackRequiresUserGesture: false, // notes vocales
            allowsInlineMediaPlayback: true,
            useShouldOverrideUrlLoading: true,
            isInspectable: false,
            supportZoom: false,
          ),
          onWebViewCreated: (c) => _c = c,
          // Micro (notes vocales) et caméra : uniquement pour WhatsApp
          onPermissionRequest: (c, req) async => PermissionResponse(
            resources: req.resources,
            action: req.origin.host.endsWith('whatsapp.com') ? PermissionResponseAction.GRANT : PermissionResponseAction.DENY,
          ),
          // Liens reçus dans les conversations : ouverts dans le navigateur, WhatsApp reste ici
          shouldOverrideUrlLoading: (c, nav) async {
            final u = nav.request.url;
            if (u == null || ['about', 'blob', 'data'].contains(u.scheme) || u.host.endsWith('whatsapp.com') || u.host.endsWith('whatsapp.net')) {
              return NavigationActionPolicy.ALLOW;
            }
            openWhatsAppWindow(u.toString());
            return NavigationActionPolicy.CANCEL;
          },
          onDownloadStartRequest: (c, req) => openWhatsAppWindow(req.url.toString()),
        ),
      ),
    ]);
  }
}
