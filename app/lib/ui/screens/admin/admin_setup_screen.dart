import 'package:flutter/material.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'admin_common.dart';

/// Super admin › Ma configuration : tout ce qu'il faut régler pour que les clients utilisent la plateforme sans accroc.
class AdminSetupScreen extends StatefulWidget {
  const AdminSetupScreen({super.key, this.onGo});
  final void Function(String id)? onGo;

  @override
  State<AdminSetupScreen> createState() => _AdminSetupScreenState();
}

class _AdminSetupScreenState extends State<AdminSetupScreen> {
  Key _key = UniqueKey();
  bool _onlyTodo = false;

  static const _levels = {
    'essential': ('Essentiel', YColors.danger),
    'recommended': ('Recommandé', YColors.warning),
    'optional': ('Facultatif', YColors.muted),
  };

  static const _icons = {
    'Serveur': Icons.dns_outlined,
    'E-mails': Icons.mark_email_read_outlined,
    'Paiements': Icons.payments_outlined,
    'Offre': Icons.workspace_premium_outlined,
    'Site et contact': Icons.travel_explore_rounded,
    'Services connectés': Icons.hub_outlined,
  };

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      key: _key,
      path: 'admin/setup',
      builder: (context, raw, reload) {
        final d = asMap(raw);
        final progress = (d['progress'] as num?)?.toInt() ?? 0;
        final left = (d['essentials_left'] as num?)?.toInt() ?? 0;
        final sections = [for (final s in (d['sections'] as List? ?? const [])) asMap(s)];
        final pad = MediaQuery.sizeOf(context).width < 700 ? 16.0 : 24.0;
        final hint = Theme.of(context).hintColor;
        return ListView(padding: const EdgeInsets.only(bottom: 40), children: [
          PageHeader(
            title: tr('Ma configuration'),
            subtitle: tr('Tout ce qu\'il faut régler pour que vos clients utilisent la plateforme sans problème'),
            icon: Icons.fact_check_outlined,
            actions: [IconButton.outlined(tooltip: tr('Vérifier à nouveau'), onPressed: () => setState(() => _key = UniqueKey()), icon: const Icon(Icons.refresh))],
          ),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: pad),
            child: Card(
              margin: EdgeInsets.zero,
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Row(children: [
                  SizedBox(
                    width: 78,
                    height: 78,
                    child: Stack(alignment: Alignment.center, children: [
                      SizedBox.expand(
                        child: CircularProgressIndicator(
                          value: progress / 100,
                          strokeWidth: 8,
                          backgroundColor: YColors.line,
                          color: left > 0 ? YColors.warning : YColors.success,
                          strokeCap: StrokeCap.round,
                        ),
                      ),
                      Text('$progress %', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                    ]),
                  ),
                  const SizedBox(width: 18),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(
                        tr(left == 0 ? 'Les réglages essentiels sont faits' : (left == 1 ? 'Encore 1 réglage essentiel' : 'Encore $left réglages essentiels')),
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        tr(left == 0
                            ? 'Vos clients peuvent s\'inscrire, payer et recevoir leurs e-mails. Les points recommandés améliorent encore leur expérience.'
                            : 'Commencez par les points marqués « Essentiel » : sans eux, une partie de vos clients sera bloquée.'),
                        style: TextStyle(color: hint, height: 1.35),
                      ),
                    ]),
                  ),
                  if (pad > 16) ...[
                    const SizedBox(width: 12),
                    FilterChip(label: Text(tr('À faire seulement')), selected: _onlyTodo, onSelected: (v) => setState(() => _onlyTodo = v)),
                  ],
                ]),
              ),
            ),
          ),
          if (pad <= 16)
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 12, pad, 0),
              child: Align(alignment: Alignment.centerLeft, child: FilterChip(label: Text(tr('À faire seulement')), selected: _onlyTodo, onSelected: (v) => setState(() => _onlyTodo = v))),
            ),
          for (final s in sections) ...[
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 22, pad, 8),
              child: Row(children: [
                Icon(_icons[s['name']] ?? Icons.settings_outlined, size: 20, color: YColors.blue),
                const SizedBox(width: 8),
                Text(tr('${s['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                const SizedBox(width: 8),
                Text(tr('${s['done']} / ${s['total']}'), style: TextStyle(color: hint, fontWeight: FontWeight.w600)),
              ]),
            ),
            Padding(
              padding: EdgeInsets.symmetric(horizontal: pad),
              child: Card(
                margin: EdgeInsets.zero,
                clipBehavior: Clip.antiAlias,
                child: Column(children: [
                  for (final (i, it) in [for (final x in (s['items'] as List)) asMap(x)].where((x) => !_onlyTodo || x['ok'] != true).indexed) ...[
                    if (i > 0) const Divider(height: 1),
                    _row(it, hint),
                  ],
                  if (_onlyTodo && (s['items'] as List).every((x) => asMap(x)['ok'] == true))
                    Padding(padding: const EdgeInsets.all(16), child: Row(children: [const Icon(Icons.check_circle, color: YColors.success, size: 18), const SizedBox(width: 8), Text(tr('Tout est réglé ici.'))])),
                ]),
              ),
            ),
          ],
        ]);
      },
    );
  }

  Widget _row(Map<String, dynamic> it, Color hint) {
    final ok = it['ok'] == true;
    final lv = _levels[it['level']] ?? _levels['optional']!;
    final screen = it['screen'] as String?;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 12, 14),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: const EdgeInsets.only(top: 1),
          child: Icon(ok ? Icons.check_circle : Icons.radio_button_unchecked, color: ok ? YColors.success : lv.$2, size: 22),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
              Text(tr('${it['label']}'), style: TextStyle(fontWeight: FontWeight.w700, color: ok ? null : Theme.of(context).colorScheme.onSurface)),
              if (!ok)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(color: lv.$2.withValues(alpha: .12), borderRadius: BorderRadius.circular(999)),
                  child: Text(tr(lv.$1), style: TextStyle(color: lv.$2, fontSize: 11.5, fontWeight: FontWeight.w700)),
                ),
            ]),
            if ('${it['detail'] ?? ''}'.isNotEmpty) ...[
              const SizedBox(height: 3),
              SelectableText(tr('${it['detail']}'), style: TextStyle(color: hint, fontSize: 13, height: 1.35)),
            ],
            if (screen != null && widget.onGo != null && MediaQuery.sizeOf(context).width < 700) ...[
              const SizedBox(height: 8),
              ok
                  ? TextButton(onPressed: () => widget.onGo!(screen), child: Text(tr('Voir')))
                  : FilledButton.tonal(onPressed: () => widget.onGo!(screen), child: Text(tr('${it['action'] ?? 'Configurer'}'))),
            ],
          ]),
        ),
        if (screen != null && widget.onGo != null && MediaQuery.sizeOf(context).width >= 700) ...[
          const SizedBox(width: 10),
          ok
              ? TextButton(onPressed: () => widget.onGo!(screen), child: Text(tr('Voir')))
              : FilledButton.tonal(onPressed: () => widget.onGo!(screen), child: Text(tr('${it['action'] ?? 'Configurer'}'))),
        ],
      ]),
    );
  }
}
