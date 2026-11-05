import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'admin_common.dart';
import '../../../i18n/i18n.dart';
import '../../../core/json.dart';

class AdminOverviewScreen extends StatelessWidget {
  const AdminOverviewScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return AdminLoader(
      path: 'admin/overview',
      autoRefresh: const Duration(seconds: 30),
      builder: (context, d, reload) {
        final c = d['companies'];
        final u = d['users'];
        final byPlatform = Map<String, dynamic>.from(u['online_by_platform'] is Map ? u['online_by_platform'] : {});
        final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
        final kpis = [
          ('Connectés maintenant', '${u['online']}', Icons.wifi_tethering, YColors.success),
          ('Appareils actifs', '${u['devices_online']}', Icons.devices, YColors.blue),
          ('Sociétés actives', '${c['active']} / ${c['total']}', Icons.business, YColors.blue),
          ('Revenu mensuel (MRR)', money(d['mrr'] as num, d['currency'] ?? session.currency), Icons.trending_up, YColors.warning),
          ('Utilisateurs', '${u['active']}', Icons.people_outline, YColors.blue),
          ('En essai', '${c['trial']}', Icons.hourglass_bottom, YColors.muted),
          ('Suspendues', '${c['suspended']}', Icons.block, YColors.danger),
          ('Modules disponibles', '${d['modules']['available']} / ${d['modules']['catalog']}', Icons.extension_outlined, YColors.blue),
        ];

        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
            PageHeader(
              title: tr('Super administration'),
              subtitle: tr('Mode ${d['mode'] == 'saas' ? 'SaaS (plusieurs sociétés)' : 'installation unique'} · version ${d['version']}'),
              actions: [IconButton.outlined(onPressed: reload, icon: const Icon(Icons.refresh))],
            ),
            Padding(
              padding: EdgeInsets.symmetric(horizontal: pad),
              child: LayoutBuilder(builder: (context, cs) {
                final cols = cs.maxWidth > 1100 ? 4 : cs.maxWidth > 700 ? 3 : 2;
                final w = (cs.maxWidth - (cols - 1) * 12) / cols;
                return Wrap(spacing: 12, runSpacing: 12, children: [
                  for (final k in kpis)
                    SizedBox(width: w, child: StatTile(label: k.$1, value: k.$2, icon: k.$3, color: k.$4)),
                ]);
              }),
            ),
            const SizedBox(height: 16),
            Padding(
              padding: EdgeInsets.symmetric(horizontal: pad),
              child: SectionCard(
                title: tr('Connectés par plateforme'),
                child: byPlatform.isEmpty
                    ? Text(tr('Personne en ligne.'), style: TextStyle(color: Theme.of(context).hintColor))
                    : Wrap(spacing: 12, runSpacing: 12, children: [
                        for (final e in byPlatform.entries)
                          Chip(avatar: Icon(platformIcon(e.key), size: 18), label: Text(tr('${platformLabels[e.key] ?? e.key} : ${e.value}'))),
                      ]),
              ),
            ),
            const SizedBox(height: 16),
            Padding(padding: EdgeInsets.symmetric(horizontal: pad), child: const _SystemCard()),
          ]),
        );
      },
    );
  }
}

class _SystemCard extends StatefulWidget {
  const _SystemCard();

  @override
  State<_SystemCard> createState() => _SystemCardState();
}

class _SystemCardState extends State<_SystemCard> {
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    return AdminLoader(
      path: 'admin/system',
      builder: (context, d, reload) => SectionCard(
        title: tr('Système'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('PHP ${d['php']} · base ${d['database']} · ${d['pending_migrations']} mise(s) à jour de base en attente')),
          const SizedBox(height: 8),
          Text(tr('Après avoir remplacé les fichiers du serveur (FTP ou gestionnaire de fichiers), appliquez la mise à jour ici : aucune commande nécessaire.'),
              style: TextStyle(color: Theme.of(context).hintColor)),
          const SizedBox(height: 12),
          FilledButton.tonalIcon(
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      await session.api.post('admin/system/update');
                      if (context.mounted) toast(context, tr('Mise à jour appliquée.'));
                      reload();
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            icon: const Icon(Icons.system_update_alt),
            label: Text(tr(_busy ? 'Mise à jour…' : 'Appliquer la mise à jour')),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: () => showDialog<void>(context: context, builder: (c) => const _ChecksDialog()),
            icon: const Icon(Icons.health_and_safety_outlined),
            label: Text(tr('Contrôle de production')),
          ),
        ]),
      ),
    );
  }
}

/// Contrôle de production : serveur, configuration, base, tâche cron, e-mail, sécurité, sauvegardes.
class _ChecksDialog extends StatefulWidget {
  const _ChecksDialog();

  @override
  State<_ChecksDialog> createState() => _ChecksDialogState();
}

class _ChecksDialogState extends State<_ChecksDialog> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final r = await context.read<Session>().api.get('admin/system/checks');
      if (mounted) setState(() => _d = asMap((r as Map)['data']));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    const colors = {'ok': YColors.success, 'warn': Color(0xFFD97706), 'fail': YColors.danger};
    const icons = {'ok': Icons.check_circle, 'warn': Icons.error_outline, 'fail': Icons.cancel};
    String? group;
    return YAlertDialog(
      title: Text(tr('Contrôle de production')),
      content: SizedBox(
        width: 640,
        height: 600,
        child: _error != null
            ? Center(child: Text(errorText(_error!)))
            : d == null
                ? const Center(child: CircularProgressIndicator())
                : ListView(children: [
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(color: (d['ready'] == true ? YColors.success : YColors.danger).withValues(alpha: .08), borderRadius: BorderRadius.circular(10)),
                      child: Text(
                          d['ready'] == true
                              ? tr('Prêt pour la production : ${d['summary']['ok']} point(s) OK, ${d['summary']['warn']} à vérifier.')
                              : tr('${d['summary']['fail']} point(s) bloquant(s) à corriger avant d\'ouvrir la plateforme.'),
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                    ),
                    for (final c in [for (final x in d['checks'] as List) asMap(x)]) ...[
                      if (c['group'] != group) ...[
                        Builder(builder: (_) {
                          group = c['group'] as String;
                          return Padding(padding: const EdgeInsets.only(top: 14, bottom: 4), child: Text(tr('${c['group']}'), style: const TextStyle(fontWeight: FontWeight.w800)));
                        }),
                      ],
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(icons[c['state']], color: colors[c['state']]),
                        title: Text(tr('${c['label']}')),
                        subtitle: c['state'] == 'ok' && c['detail'] == null
                            ? null
                            : Text([if (c['detail'] != null) tr('${c['detail']}'), if (c['state'] != 'ok' && c['fix'] != null) '→ ${tr('${c['fix']}')}'].join('\n')),
                      ),
                    ],
                  ]),
      ),
      actions: [
        TextButton.icon(onPressed: _load, icon: const Icon(Icons.refresh), label: Text(tr('Relancer'))),
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
      ],
    );
  }
}
