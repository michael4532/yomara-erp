import 'dart:collection' show UnmodifiableListView;
import 'dart:io' show Directory, File, Platform, Process, ProcessStartMode;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show rootBundle;
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import 'windows_webview_env.dart';

import 'package:yomara_erp/core/safe_launch.dart';

import '../../i18n/i18n.dart';

/// Applications installées (Android, iPhone, Mac, Windows) : WhatsApp Web s'affiche dans l'ERP ;
/// la session (QR code scanné une fois) est conservée dans le profil de la vue web.
/// Ordinateur : une seule vue WhatsApp pour tout le logiciel, gardée en vie quand on change d'écran
/// (jamais détruite ni dupliquée : c'est ce qui faisait planter WebView2 sous Windows) ;
/// une conversation ouverte depuis une fiche se charge dans cette même vue.
bool get whatsappEmbedded => Platform.isAndroid || Platform.isIOS || Platform.isMacOS || Platform.isWindows;

bool get _desktop => Platform.isWindows || Platform.isMacOS;
final InAppWebViewKeepAlive _waKeepAlive = InAppWebViewKeepAlive();
InAppWebViewController? _waShared;
String? _waPending;

/// Ordinateur : charge la conversation dans la vue WhatsApp de l'ERP (déjà ouverte, ou à sa prochaine ouverture).
void whatsappShowInErp(String url) {
  if (_waShared != null) {
    _waShared!.loadUrl(urlRequest: URLRequest(url: WebUri(url)));
  } else {
    _waPending = url;
  }
}

Future<void> openWhatsAppWindow(String url) async {
  if (Platform.isWindows && await _windowsApp(url)) return;
  await safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
}

/// Windows : conversation dans l'application WhatsApp si elle est installée, sinon WhatsApp Web dans une fenêtre
/// d'application Edge (ou Chrome) sans barre d'adresse, avec un profil réservé à l'ERP (QR code scanné une seule fois).
Future<bool> _windowsApp(String url) async {
  try {
    final u = Uri.parse(url);
    // Application WhatsApp de bureau : lien direct vers la conversation
    final phone = u.queryParameters['phone'];
    if (phone != null && phone.isNotEmpty) {
      final text = u.queryParameters['text'];
      final app = Uri.parse('whatsapp://send?phone=$phone${text == null || text.isEmpty ? '' : '&text=${Uri.encodeComponent(text)}'}');
      if (await canLaunchUrl(app) && await launchUrl(app, mode: LaunchMode.externalApplication)) return true;
    }
    final env = Platform.environment;
    final candidates = [
      for (final base in [env['ProgramFiles(x86)'], env['ProgramFiles'], env['LOCALAPPDATA']])
        if (base != null) ...['$base\\Microsoft\\Edge\\Application\\msedge.exe', '$base\\Google\\Chrome\\Application\\chrome.exe'],
    ];
    final exe = candidates.where((p) => File(p).existsSync()).firstOrNull;
    if (exe == null) return false;
    final profile = '${(await getApplicationSupportDirectory()).path}\\WhatsApp';
    Directory(profile).createSync(recursive: true);
    await Process.start(exe, ['--app=$url', '--user-data-dir=$profile', '--no-first-run', '--no-default-browser-check', '--window-size=1100,800'], mode: ProcessStartMode.detached);
    return true;
  } catch (_) {
    return false;
  }
}

/// WhatsApp Web exige un navigateur de bureau récent : on se présente comme Chrome sur Windows.
const _desktopUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

Future<WebViewEnvironment?> _windowsEnvironment() => windowsWebViewEnvironment();

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

  /// Téléphone : affichage un volet à la fois (liste, puis discussion en plein écran), comme l'application WhatsApp.
  String? _mobileJs;
  bool _mobile = true, _chatOpen = false;
  double _progress = 0;
  bool get _phone => (Platform.isAndroid || Platform.isIOS) && MediaQuery.sizeOf(context).shortestSide < 600;

  @override
  void initState() {
    super.initState();
    if (_desktop && _waShared != null) {
      // Vue déjà créée (gardée en vie) : on la réutilise
      _c = _waShared;
      if (widget.url != 'https://web.whatsapp.com/') _waShared!.loadUrl(urlRequest: URLRequest(url: WebUri(widget.url)));
    }
    if (Platform.isAndroid || Platform.isIOS) {
      rootBundle
          .loadString('assets/js/whatsapp_mobile.js')
          .then((js) {
            if (mounted) setState(() => _mobileJs = js);
          })
          .catchError((_) {});
    }
    if (Platform.isWindows) {
      _windowsEnvironment()
          .then((e) {
            if (mounted) setState(() => (_env = e, _ready = true, _failed = e == null));
          })
          .catchError((_) {
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
    if (!_ready || ((Platform.isAndroid || Platform.isIOS) && _mobileJs == null)) return const _WaLoading();
    final phone = _phone;
    final page = Column(
      children: [
        _WhatsAppBar(
          compact: phone,
          progress: _progress,
          actions: [
            if (phone)
              _BarButton(
                tooltip: _mobile ? tr('Affichage ordinateur') : tr('Affichage téléphone'),
                icon: _mobile ? Icons.desktop_windows_outlined : Icons.smartphone,
                onPressed: () {
                  setState(() => _mobile = !_mobile);
                  _c?.evaluateJavascript(source: 'window.__ymSetMobile && window.__ymSetMobile($_mobile)');
                },
              ),
            _BarButton(tooltip: tr('Recharger'), icon: Icons.refresh, onPressed: () => _c?.reload()),
          ],
        ),
        Expanded(
          child: InAppWebView(
            webViewEnvironment: _env,
            keepAlive: _desktop ? _waKeepAlive : null,
            initialUrlRequest: URLRequest(url: WebUri(_desktop ? (_waPending ?? widget.url) : widget.url)),
            initialSettings: InAppWebViewSettings(
              userAgent: _desktopUa,
              preferredContentMode: UserPreferredContentMode.DESKTOP,
              javaScriptEnabled: true,
              mediaPlaybackRequiresUserGesture: false, // notes vocales
              allowsInlineMediaPlayback: true,
              useShouldOverrideUrlLoading: true,
              isInspectable: false,
              supportZoom: false,
              // Téléphone : la page bureau s'adapte à la largeur de l'écran (script ci-dessous)
              useWideViewPort: !phone,
              loadWithOverviewMode: !phone,
            ),
            initialUserScripts: phone && _mobileJs != null
                ? UnmodifiableListView([UserScript(source: 'window.__ymWaMobileDefault = true;\n${_mobileJs!}', injectionTime: UserScriptInjectionTime.AT_DOCUMENT_END)])
                : null,
            onProgressChanged: (c, p) {
              if (mounted) setState(() => _progress = p / 100);
            },
            onWebViewCreated: (c) {
              _c = c;
              if (_desktop) {
                _waShared = c;
                _waPending = null;
              }
              c.addJavaScriptHandler(
                handlerName: 'ymChat',
                callback: (args) {
                  final open = args.isNotEmpty && args.first == true;
                  if (mounted && open != _chatOpen) setState(() => _chatOpen = open);
                },
              );
            },
            // Micro (notes vocales) et caméra : uniquement pour WhatsApp
            onPermissionRequest: (c, req) async =>
                PermissionResponse(resources: req.resources, action: req.origin.host.endsWith('whatsapp.com') ? PermissionResponseAction.GRANT : PermissionResponseAction.DENY),
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
      ],
    );
    if (!phone) return page;
    // Bouton retour du téléphone : ferme d'abord la discussion ouverte
    return PopScope(
      canPop: !(_mobile && _chatOpen),
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _c?.evaluateJavascript(source: 'window.__ymBack && window.__ymBack()');
      },
      child: page,
    );
  }
}

const _waDark = Color(0xFF0B3D36), _waTeal = Color(0xFF128C7E), _waGreen = Color(0xFF25D366);

/// Barre WhatsApp de l'ERP : identité WhatsApp, mention de la session privée, chargement en fin filet.
class _WhatsAppBar extends StatelessWidget {
  const _WhatsAppBar({required this.compact, required this.progress, required this.actions});

  final bool compact;
  final double progress;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    final loading = progress > 0 && progress < 1;
    return Material(
      elevation: 2,
      shadowColor: Colors.black26,
      child: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(colors: [_waDark, _waTeal], begin: Alignment.centerLeft, end: Alignment.centerRight),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              height: compact ? 48 : 56,
              child: Row(
                children: [
                  const SizedBox(width: 14),
                  Container(
                    width: compact ? 30 : 34,
                    height: compact ? 30 : 34,
                    decoration: BoxDecoration(color: _waGreen, borderRadius: BorderRadius.circular(10)),
                    child: Icon(Icons.chat_rounded, color: Colors.white, size: compact ? 17 : 19),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          tr('WhatsApp'),
                          style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: compact ? 15 : 16, height: 1.1),
                        ),
                        const SizedBox(height: 2),
                        Row(
                          children: [
                            const Icon(Icons.lock_outline, size: 11, color: Color(0xCCFFFFFF)),
                            const SizedBox(width: 4),
                            Flexible(
                              child: Text(
                                loading ? tr('Chargement de vos discussions…') : tr('Session privée de votre téléphone'),
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(color: Color(0xCCFFFFFF), fontSize: 11.5),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  ...actions,
                  const SizedBox(width: 8),
                ],
              ),
            ),
            SizedBox(
              height: 2,
              child: AnimatedOpacity(
                opacity: loading ? 1 : 0,
                duration: const Duration(milliseconds: 250),
                child: LinearProgressIndicator(value: progress, minHeight: 2, backgroundColor: Colors.transparent, color: _waGreen),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _BarButton extends StatelessWidget {
  const _BarButton({required this.tooltip, required this.icon, required this.onPressed});

  final String tooltip;
  final IconData icon;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(left: 6),
    child: IconButton(
      tooltip: tooltip,
      onPressed: onPressed,
      icon: Icon(icon, size: 18),
      color: Colors.white,
      style: IconButton.styleFrom(backgroundColor: Colors.white.withValues(alpha: .12), fixedSize: const Size(36, 36), minimumSize: const Size(36, 36)),
    ),
  );
}

/// Attente de l'ouverture de WhatsApp : écran sobre aux couleurs de WhatsApp plutôt qu'un simple indicateur.
class _WaLoading extends StatelessWidget {
  const _WaLoading();

  @override
  Widget build(BuildContext context) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(
            color: _waGreen,
            borderRadius: BorderRadius.circular(18),
            boxShadow: [BoxShadow(color: _waGreen.withValues(alpha: .35), blurRadius: 18, offset: const Offset(0, 6))],
          ),
          child: const Icon(Icons.chat_rounded, color: Colors.white, size: 32),
        ),
        const SizedBox(height: 18),
        Text(tr('Ouverture de WhatsApp…'), style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 12),
        const SizedBox(width: 140, child: LinearProgressIndicator(minHeight: 3, color: _waGreen)),
      ],
    ),
  );
}
