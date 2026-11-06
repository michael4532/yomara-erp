import 'package:flutter/material.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Affichage d'un e-mail HTML (ordinateur) : rendu natif, liens ouverts dans le navigateur.
class MailHtml extends StatelessWidget {
  const MailHtml({super.key, required this.html, this.dark = false});

  final String html;
  final bool dark;

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(4, 8, 4, 24),
        child: SelectionArea(
          child: HtmlWidget(
            html,
            textStyle: TextStyle(fontSize: 14, height: 1.45, color: dark ? Colors.white : const Color(0xFF1B1F24)),
            onTapUrl: (url) async {
              final uri = Uri.tryParse(url.trim());
              return uri != null && await safeLaunchUrl(uri, mode: LaunchMode.externalApplication);
            },
            renderMode: RenderMode.column,
          ),
        ),
      );
}
