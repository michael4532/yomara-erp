import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../i18n/i18n.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Hors navigateur : la salle s'ouvre dans le navigateur par défaut (caméra, micro et partage d'écran y sont fiables).
bool get visioEmbedded => false;

class VisioFrame extends StatelessWidget {
  const VisioFrame({super.key, required this.url, this.onLeft});

  final String url;
  final VoidCallback? onLeft;

  @override
  Widget build(BuildContext context) => Center(
        child: FilledButton.icon(
          onPressed: () => safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
          icon: const Icon(Icons.open_in_new),
          label: Text(tr('Ouvrir la réunion')),
        ),
      );
}
