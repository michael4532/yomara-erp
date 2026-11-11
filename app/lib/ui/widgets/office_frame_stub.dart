import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:yomara_erp/core/safe_launch.dart';
import '../../i18n/i18n.dart';
import 'auto_update.dart';

/// Applications installées : l'éditeur Word / Excel s'affiche dans l'application (Android, iPhone, Mac, Windows) ;
/// sous Linux, dans le navigateur.
bool get officeEmbedded => Platform.isAndroid || Platform.isIOS || Platform.isMacOS || Platform.isWindows;

WebViewEnvironment? _winEnv;
Future<WebViewEnvironment?> _windowsEnvironment() async {
  if (_winEnv != null) return _winEnv;
  if (await WebViewEnvironment.getAvailableVersion() == null) return null;
  final dir = await getApplicationSupportDirectory();
  return _winEnv = await WebViewEnvironment.create(settings: WebViewEnvironmentSettings(userDataFolder: '${dir.path}\\WebView2'));
}

class OfficeFrame extends StatefulWidget {
  const OfficeFrame({super.key, required this.url, this.onClose, this.onSaved});

  final String url;
  final VoidCallback? onClose;
  final VoidCallback? onSaved;

  @override
  State<OfficeFrame> createState() => _OfficeFrameState();
}

class _OfficeFrameState extends State<OfficeFrame> {
  late final Uri _page = Uri.parse(widget.url);
  bool _ready = !Platform.isWindows, _failed = false;
  WebViewEnvironment? _env;

  @override
  void initState() {
    super.initState();
    AutoUpdate.hold.value++;
    if (Platform.isWindows) {
      _windowsEnvironment().then((e) {
        if (mounted) setState(() => (_env = e, _ready = true, _failed = e == null));
      }).catchError((_) {
        if (mounted) setState(() => (_ready = true, _failed = true));
      });
    }
  }

  @override
  void dispose() {
    AutoUpdate.hold.value--;
    super.dispose();
  }

  bool _sameSite(Uri? u) => u != null && u.scheme == _page.scheme && u.host == _page.host && u.port == _page.port;

  @override
  Widget build(BuildContext context) {
    if (!officeEmbedded || _failed) {
      return Center(
        child: FilledButton.icon(
          onPressed: () => safeLaunchUrl(_page, mode: LaunchMode.externalApplication),
          icon: const Icon(Icons.open_in_new),
          label: Text(tr('Ouvrir dans le navigateur')),
        ),
      );
    }
    if (!_ready) return const Center(child: CircularProgressIndicator());
    return InAppWebView(
      webViewEnvironment: _env,
      initialUrlRequest: URLRequest(url: WebUri(widget.url)),
      initialSettings: InAppWebViewSettings(javaScriptEnabled: true, useShouldOverrideUrlLoading: true, isInspectable: false, transparentBackground: false),
      onWebViewCreated: (c) => c.addJavaScriptHandler(
        handlerName: 'office',
        callback: (args) {
          final ev = args.isNotEmpty ? '${args.first}' : '';
          if (ev == 'close') widget.onClose?.call();
          if (ev == 'saved') widget.onSaved?.call();
          return null;
        },
      ),
      shouldOverrideUrlLoading: (c, nav) async {
        final u = nav.request.url;
        if (u == null || _sameSite(u) || ['about', 'blob'].contains(u.scheme)) return NavigationActionPolicy.ALLOW;
        safeLaunchUrl(u, mode: LaunchMode.externalApplication);
        return NavigationActionPolicy.CANCEL;
      },
      onReceivedError: (c, req, err) {
        if (req.isForMainFrame == true && mounted) setState(() => _failed = true);
      },
    );
  }
}
