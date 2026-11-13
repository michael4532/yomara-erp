import 'package:flutter/material.dart';
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
