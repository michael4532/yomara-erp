import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// « À propos » : version, copyright, licence AGPL et lien vers le code source (article 13 de l'AGPL).
Future<void> showYomaraAbout(BuildContext context) {
  final s = context.read<Session>();
  final server = '${s.bootstrap?['app']?['version'] ?? '—'}';
  final source = '${s.bootstrap?['app']?['source_url'] ?? 'https://erp.yomaratechnologies.com/telecharger/source'}';
  return showDialog<void>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Row(children: [
        Image.asset('assets/brand/mark.png', width: 34, height: 34, errorBuilder: (_, _, _) => const Icon(Icons.apps, color: YColors.blue)),
        const SizedBox(width: 12),
        const Text('Yomara ERP'),
      ]),
      content: SizedBox(
        width: 420,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('Application ${AppConfig.appVersion} · serveur $server'), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          Text(tr('© 2026 Yomara Technologies. Tous droits réservés sur les marques « Yomara » et « Yomara ERP ».')),
          const SizedBox(height: 10),
          Text(
            tr('Logiciel libre distribué sous licence GNU Affero General Public License v3 (AGPL-3.0). Vous pouvez obtenir le code source de cette version et le modifier selon les termes de la licence.'),
            style: TextStyle(color: Theme.of(ctx).hintColor, height: 1.4),
          ),
        ]),
      ),
      actions: [
        TextButton.icon(
          onPressed: () => safeLaunchUrl(Uri.parse('https://www.gnu.org/licenses/agpl-3.0.html'), mode: LaunchMode.externalApplication),
          icon: const Icon(Icons.gavel_outlined, size: 18),
          label: Text(tr('Licence')),
        ),
        TextButton.icon(
          onPressed: () => safeLaunchUrl(Uri.parse(source), mode: LaunchMode.externalApplication),
          icon: const Icon(Icons.code, size: 18),
          label: Text(tr('Code source')),
        ),
        FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Fermer'))),
      ],
    ),
  );
}
