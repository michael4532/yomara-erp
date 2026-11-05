import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import 'common.dart';
import 'mail_html.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Annonce de l'éditeur (promotion, nouveauté, rappel) reçue en notification : message complet et bouton d'action.
Future<void> showAnnouncement(BuildContext context, Map<String, dynamic> notif) async {
  final ref = '${notif['ref'] ?? ''}';
  await showDialog<void>(
    context: context,
    builder: (c) {
      final wide = MediaQuery.sizeOf(c).width >= 700;
      return YAlertDialog(
        icon: const Icon(Icons.campaign_outlined),
        title: Text(tr('${notif['title'] ?? 'Annonce'}')),
        content: SizedBox(
          width: wide ? 640 : double.maxFinite,
          height: wide ? 520 : MediaQuery.sizeOf(c).height * .62,
          child: RegExp(r'^[ca]\d+$').hasMatch(ref)
              ? FutureBuilder<dynamic>(
                  future: context.read<Session>().api.get('announcements/$ref'),
                  builder: (c, snap) {
                    if (snap.hasError) return SingleChildScrollView(child: Text(tr('${notif['body'] ?? ''}')));
                    if (!snap.hasData) return const LoadingSkeleton(lines: 6);
                    final d = asMap(snap.data);
                    final html = '${d['html'] ?? ''}';
                    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Expanded(
                        child: html.isNotEmpty
                            ? ClipRRect(borderRadius: BorderRadius.circular(12), child: MailHtml(html: html))
                            : SingleChildScrollView(child: SelectableText('${d['text'] ?? notif['body'] ?? ''}', style: const TextStyle(fontSize: 15, height: 1.5))),
                      ),
                      if ('${d['cta_url'] ?? ''}'.startsWith('http') && '${d['cta_label'] ?? ''}'.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 12),
                          child: FilledButton.icon(
                            onPressed: () => safeLaunchUrl(Uri.parse('${d['cta_url']}'), mode: LaunchMode.externalApplication),
                            icon: const Icon(Icons.open_in_new_rounded),
                            label: Text(tr('${d['cta_label']}')),
                          ),
                        ),
                    ]);
                  },
                )
              : SingleChildScrollView(child: SelectableText(tr('${notif['body'] ?? ''}'), style: const TextStyle(fontSize: 15, height: 1.5))),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
      );
    },
  );
}
