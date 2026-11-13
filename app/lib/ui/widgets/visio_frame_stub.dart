import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:url_launcher/url_launcher.dart';
import 'windows_webview_env.dart';

import '../../i18n/i18n.dart';
import 'auto_update.dart';
import 'package:yomara_erp/core/safe_launch.dart';

/// Applications installées : la salle s'ouvre dans l'application (vue web intégrée avec caméra et micro)
/// sur Android, iPhone, Mac et Windows ; sous Linux, dans le navigateur par défaut.
bool get visioEmbedded => Platform.isAndroid || Platform.isIOS || Platform.isMacOS || Platform.isWindows;

/// Windows : WebView2 range ses données dans le dossier de l'utilisateur (le dossier d'installation du Store est en lecture seule).
Future<WebViewEnvironment?> _windowsEnvironment() => windowsWebViewEnvironment();

class VisioFrame extends StatefulWidget {
  const VisioFrame({super.key, required this.url, this.onLeft});

  final String url;
  final VoidCallback? onLeft;

  @override
  State<VisioFrame> createState() => _VisioFrameState();
}

class _VisioFrameState extends State<VisioFrame> {
  late final Uri _room = Uri.parse(widget.url);
  bool _ready = !(Platform.isAndroid || Platform.isWindows), _failed = false;
  WebViewEnvironment? _env;

  @override
  void initState() {
    super.initState();
    AutoUpdate.hold.value++; // pas de redémarrage pour mise à jour pendant la réunion
    if (Platform.isAndroid) _askAndroid();
    if (Platform.isWindows) _prepareWindows();
  }

  Future<void> _prepareWindows() async {
    try {
      _env = await _windowsEnvironment();
    } catch (_) {}
    if (mounted) setState(() => (_ready = true, _failed = _env == null));
  }

  @override
  void dispose() {
    AutoUpdate.hold.value--;
    super.dispose();
  }

  /// Android : la vue web n'obtient caméra et micro que si l'application les a reçus du système.
  Future<void> _askAndroid() async {
    try {
      await [Permission.camera, Permission.microphone].request();
    } catch (_) {}
    if (mounted) setState(() => _ready = true);
  }

  bool _sameSite(Uri? u) => u != null && u.scheme == _room.scheme && u.host == _room.host && u.port == _room.port;

  Widget _browser() => Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(tr('La réunion ne peut pas s\'afficher ici.'), style: const TextStyle(color: Colors.white70)),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: () => safeLaunchUrl(_room, mode: LaunchMode.externalApplication),
            icon: const Icon(Icons.open_in_new),
            label: Text(tr('Ouvrir dans le navigateur')),
          ),
        ]),
      );

  @override
  Widget build(BuildContext context) {
    if (!visioEmbedded || _failed) return _browser();
    if (!_ready) return const Center(child: CircularProgressIndicator());
    return InAppWebView(
      webViewEnvironment: _env,
      initialUrlRequest: URLRequest(url: WebUri(widget.url)),
      initialSettings: InAppWebViewSettings(
        mediaPlaybackRequiresUserGesture: false,
        allowsInlineMediaPlayback: true,
        javaScriptEnabled: true,
        transparentBackground: false,
        isInspectable: false,
        useShouldOverrideUrlLoading: true,
        iframeAllow: 'camera; microphone; display-capture; autoplay; fullscreen',
      ),
      onWebViewCreated: (c) {
        // La page de la salle prévient quand on la quitte
        c.addJavaScriptHandler(handlerName: 'visio', callback: (args) {
          if (args.isNotEmpty && args.first == 'left') widget.onLeft?.call();
          return null;
        });
      },
      // Caméra et micro : seulement pour le serveur de la réunion
      onPermissionRequest: (c, req) async => PermissionResponse(
            resources: req.resources,
            action: _sameSite(req.origin) ? PermissionResponseAction.GRANT : PermissionResponseAction.DENY,
          ),
      // Liens vers d'autres sites (compte rendu, site de la société…) : dans le navigateur
      shouldOverrideUrlLoading: (c, nav) async {
        final u = nav.request.url;
        if (u == null || _sameSite(u) || u.scheme == 'about' || u.scheme == 'blob' || u.scheme == 'data') return NavigationActionPolicy.ALLOW;
        safeLaunchUrl(u, mode: LaunchMode.externalApplication);
        return NavigationActionPolicy.CANCEL;
      },
      onReceivedError: (c, req, err) {
        if (req.isForMainFrame == true && mounted) setState(() => _failed = true);
      },
    );
  }
}
