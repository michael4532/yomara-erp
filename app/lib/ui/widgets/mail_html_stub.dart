import 'dart:collection' show UnmodifiableListView;
import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:yomara_erp/core/safe_launch.dart';

import 'mail_html_dark.dart';
/// Affichage d'un e-mail HTML (ordinateur, téléphone) : rendu natif, liens ouverts dans le navigateur.
/// Avec [scroll] : rendu à pleine hauteur dans la page qui défile (lecture d'un seul tenant).
class MailHtml extends StatelessWidget {
  const MailHtml({super.key, required this.html, this.dark = false, this.scroll});

  final String html;
  final bool dark;
  final ScrollController? scroll;

  @override
  Widget build(BuildContext context) {
    // Mode sombre : e-mail simple en texte clair, e-mail mis en page sur fond clair (voir mail_html_dark.dart)
    final (darkText, shown) = mailForTheme(html, dark);
    final paper = dark && !darkText;
    // Lecture d'un e-mail sur téléphone et Mac : vrai moteur web (feuilles de style, boutons colorés, mises en page de
    // lettres d'information), comme Gmail et Outlook. Le contenu est déjà nettoyé par le serveur (aucun script).
    if (scroll != null && (Platform.isAndroid || Platform.isIOS || Platform.isMacOS)) {
      return Padding(padding: const EdgeInsets.fromLTRB(0, 4, 0, 12), child: _WebMail(html: shown, darkText: darkText, paper: paper));
    }
    final content = Container(
        padding: paper ? const EdgeInsets.all(12) : EdgeInsets.zero,
        decoration: paper ? BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)) : null,
        child: SelectionArea(
          child: HtmlWidget(
            shown,
            textStyle: TextStyle(fontSize: 15, height: 1.55, color: darkText ? const Color(0xFFE8ECF3) : const Color(0xFF1B1F24)),
            onTapUrl: (url) async {
              final uri = Uri.tryParse(url.trim());
              return uri != null && await safeLaunchUrl(uri, mode: LaunchMode.externalApplication);
            },
            renderMode: RenderMode.column,
          ),
        ),
      );
    if (scroll != null) return Padding(padding: const EdgeInsets.fromLTRB(2, 4, 2, 12), child: content);
    return SingleChildScrollView(padding: const EdgeInsets.fromLTRB(4, 8, 4, 24), child: content);
  }
}


/// E-mail affiché dans une vue web à hauteur automatique, intégrée à la page qui défile.
class _WebMail extends StatefulWidget {
  const _WebMail({required this.html, required this.darkText, required this.paper});

  final String html;
  final bool darkText, paper;

  @override
  State<_WebMail> createState() => _WebMailState();
}

class _WebMailState extends State<_WebMail> {
  double _h = 160;

  String get _doc {
    final fg = widget.darkText ? '#E8ECF3' : '#1B1F24';
    final bg = widget.paper ? '#FFFFFF' : 'transparent';
    return '<!doctype html><html><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        '<style>html,body{margin:0;padding:0;background:$bg;color:$fg;font:15px/1.55 -apple-system,Roboto,"Segoe UI",sans-serif;'
        'word-wrap:break-word;overflow-wrap:anywhere;-webkit-text-size-adjust:100%}'
        'body{padding:${widget.paper ? 12 : 2}px}img{max-width:100%!important;height:auto!important}'
        'table{max-width:100%!important}pre{white-space:pre-wrap}a{color:#2E6BE6}</style></head><body>'
        '${widget.html}</body></html>';
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: _h,
      child: InAppWebView(
        initialData: InAppWebViewInitialData(data: _doc, mimeType: 'text/html', encoding: 'utf-8', baseUrl: WebUri('about:blank')),
        initialSettings: InAppWebViewSettings(
          transparentBackground: true,
          disableVerticalScroll: true,
          supportZoom: false,
          verticalScrollBarEnabled: false,
          horizontalScrollBarEnabled: false,
          javaScriptCanOpenWindowsAutomatically: false,
          mediaPlaybackRequiresUserGesture: true,
          useShouldOverrideUrlLoading: true,
        ),
        initialUserScripts: UnmodifiableListView([
          // Hauteur réelle du contenu (y compris après chargement des images) transmise à l'application
          UserScript(
            source: "(function(){function s(){window.flutter_inappwebview&&window.flutter_inappwebview.callHandler('h',Math.ceil(document.documentElement.scrollHeight));}"
                "new ResizeObserver(s).observe(document.body);window.addEventListener('load',s);s();})();",
            injectionTime: UserScriptInjectionTime.AT_DOCUMENT_END,
          ),
        ]),
        onWebViewCreated: (c) => c.addJavaScriptHandler(
          handlerName: 'h',
          callback: (a) {
            final v = a.isNotEmpty && a.first is num ? (a.first as num).toDouble() : null;
            if (v != null && v > 0 && mounted && (v - _h).abs() > 2) setState(() => _h = v.clamp(40, 60000).toDouble());
          },
        ),
        shouldOverrideUrlLoading: (c, nav) async {
          final uri = nav.request.url;
          if (uri == null || uri.toString().startsWith('about:')) return NavigationActionPolicy.ALLOW;
          await safeLaunchUrl(Uri.parse(uri.toString()), mode: LaunchMode.externalApplication);
          return NavigationActionPolicy.CANCEL;
        },
      ),
    );
  }
}
