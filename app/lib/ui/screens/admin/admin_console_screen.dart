import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/report_charts.dart';
import 'admin_common.dart';

/// Console éditeur : connectés en temps réel, activité par jour / mois, ventes de modules et support.
class AdminConsoleScreen extends StatefulWidget {
  const AdminConsoleScreen({super.key, this.onGo});

  final void Function(String id)? onGo;

  @override
  State<AdminConsoleScreen> createState() => _AdminConsoleScreenState();
}

class _AdminConsoleScreenState extends State<AdminConsoleScreen> {
  int _days = 30;
  String _metric = 'users';

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      key: ValueKey(_days),
      path: 'admin/analytics?days=$_days',
      autoRefresh: const Duration(seconds: 20),
      builder: (context, raw, reload) {
        final d = asMap(raw);
        final cur = '${d['currency'] ?? 'XOF'}';
        final now = asMap(d['now']);
        final today = asMap(d['today']);
        final rev = asMap(d['revenue']);
        final comp = asMap(d['companies']);
        final sup = asMap(d['support']);
        final daily = [for (final x in (d['daily'] as List? ?? const [])) asMap(x)];
        final monthly = [for (final x in (d['monthly'] as List? ?? const [])) asMap(x)];
        final series = [for (final x in (d['online_series'] as List? ?? const [])) asMap(x)];
        final width = MediaQuery.sizeOf(context).width;
        final pad = width < 700 ? 16.0 : 24.0;
        final hint = Theme.of(context).hintColor;
        final prev = toDouble(rev['previous_month']);
        final growth = prev > 0 ? ((toDouble(rev['month']) - prev) / prev * 100).round() : null;

        Widget card(String title, Widget child, {Widget? trailing, IconData? icon}) => Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: Theme.of(context).cardColor,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: Colors.black.withValues(alpha: .06)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  if (icon != null) ...[Icon(icon, size: 18, color: YColors.blue), const SizedBox(width: 8)],
                  Expanded(
                    child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  ),
                  ?trailing,
                ],
              ),
              const SizedBox(height: 14),
              child,
            ],
          ),
        );

        final metricLabels = {'users': 'Utilisateurs actifs', 'companies': 'Sociétés actives', 'hours': 'Heures d\'utilisation', 'revenue': 'Ventes', 'signups': 'Inscriptions'};
        ChartData chart(List<Map<String, dynamic>> rows, String labelKey, String Function(String) fmt) => ChartData(
          [for (final r in rows) fmt('${r[labelKey]}')],
          [
            ChartSeries(tr(metricLabels[_metric]!), [for (final r in rows) toDouble(r[_metric])]),
          ],
          _metric == 'revenue' ? 'money' : 'number',
        );

        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(
            padding: const EdgeInsets.only(bottom: 40),
            children: [
              PageHeader(
                title: tr('Console éditeur'),
                subtitle: tr('Utilisation en temps réel, ventes d\'abonnements et support de toutes vos sociétés clientes'),
                actions: [
                  SegmentedButton<int>(
                    segments: [
                      for (final n in [7, 30, 90]) ButtonSegment(value: n, label: Text(tr('$n j'))),
                    ],
                    selected: {_days},
                    onSelectionChanged: (s) => setState(() => _days = s.first),
                  ),
                  IconButton.outlined(tooltip: tr('Actualiser'), onPressed: reload, icon: const Icon(Icons.refresh)),
                ],
              ),
              ServerUpdateBanner(padding: EdgeInsets.fromLTRB(pad, 0, pad, 16)),
              // Bandeau temps réel
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: Container(
                  padding: const EdgeInsets.all(22),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(22),
                    gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF1A3F8F), Color(0xFF5B3FD8)], begin: Alignment.topLeft, end: Alignment.bottomRight),
                  ),
                  child: Wrap(
                    spacing: 28,
                    runSpacing: 18,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const _LiveDot(),
                          const SizedBox(width: 14),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${now['users'] ?? 0}',
                                style: const TextStyle(color: Colors.white, fontSize: 44, fontWeight: FontWeight.w900, height: 1),
                              ),
                              Text(tr('connectés en ce moment'), style: const TextStyle(color: Color(0xFFBFD0F5))),
                            ],
                          ),
                        ],
                      ),
                      for (final k in [
                        ('${now['companies'] ?? 0}', 'sociétés en ligne'),
                        ('${[(now['peak_24h'] as num? ?? 0).toInt(), (now['users'] as num? ?? 0).toInt()].reduce((a, b) => a > b ? a : b)}', 'pic sur 24 h'),
                        ('${today['users'] ?? 0}', 'utilisateurs aujourd\'hui'),
                        ('${today['hours'] ?? 0} h', 'd\'utilisation aujourd\'hui'),
                      ])
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              k.$1,
                              style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800),
                            ),
                            Text(tr(k.$2), style: const TextStyle(color: Color(0xFFBFD0F5), fontSize: 12.5)),
                          ],
                        ),
                      Wrap(
                        spacing: 6,
                        runSpacing: 6,
                        children: [
                          for (final e in asMap(now['by_platform']).entries)
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                              decoration: BoxDecoration(color: Colors.white.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Icon(platformIcon(e.key), size: 16, color: Colors.white),
                                  const SizedBox(width: 6),
                                  Text(
                                    tr('${platformLabels[e.key] ?? e.key} · ${e.value}'),
                                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                                  ),
                                ],
                              ),
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
              // Indicateurs clés
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: LayoutBuilder(
                  builder: (context, cs) {
                    final cols = cs.maxWidth > 1050
                        ? 5
                        : cs.maxWidth > 700
                        ? 3
                        : 1;
                    final w = (cs.maxWidth - (cols - 1) * 12) / cols;
                    final tiles = [
                      (
                        'Ventes du mois',
                        money(rev['month'] as num? ?? 0, cur),
                        growth == null ? 'Mois précédent : ${money(prev, cur)}' : '${growth >= 0 ? '+' : ''}$growth % vs mois précédent',
                        Icons.payments_outlined,
                        YColors.success,
                        'admin_store',
                      ),
                      ('Revenu mensuel récurrent', money(rev['mrr'] as num? ?? 0, cur), 'Abonnements actifs', Icons.autorenew_rounded, YColors.blue, 'admin_store'),
                      ('Commandes à traiter', '${rev['pending_orders'] ?? 0}', '${rev['to_validate'] ?? 0} paiement(s) à vérifier', Icons.receipt_long_outlined, YColors.warning, 'admin_store'),
                      ('Demandes de support ouvertes', '${sup['open'] ?? 0}', '${sup['unread'] ?? 0} message(s) non lu(s)', Icons.support_agent_rounded, const Color(0xFF7C3AED), 'admin_support'),
                      (
                        'Sociétés clientes',
                        '${comp['active'] ?? 0} / ${comp['total'] ?? 0}',
                        '${comp['trial'] ?? 0} en essai · ${comp['new_month'] ?? 0} nouvelle(s) ce mois',
                        Icons.business_outlined,
                        YColors.blue,
                        'admin_companies',
                      ),
                    ];
                    return Wrap(
                      spacing: 12,
                      runSpacing: 12,
                      children: [
                        for (final t in tiles)
                          SizedBox(
                            width: w,
                            child: HoverLift(
                              child: Material(
                                color: Theme.of(context).cardColor,
                                borderRadius: BorderRadius.circular(18),
                                child: InkWell(
                                  borderRadius: BorderRadius.circular(18),
                                  onTap: widget.onGo == null ? null : () => widget.onGo!(t.$6),
                                  child: Container(
                                    padding: const EdgeInsets.all(16),
                                    decoration: BoxDecoration(
                                      borderRadius: BorderRadius.circular(18),
                                      border: Border.all(color: Colors.black.withValues(alpha: .06)),
                                    ),
                                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                      Row(children: [
                                        DialogIconBadge(icon: Icon(t.$4), color: t.$5, size: 38),
                                        const SizedBox(width: 10),
                                        Expanded(child: Text(tr(t.$1), maxLines: 2, style: TextStyle(color: hint, fontSize: 12.5, fontWeight: FontWeight.w600, height: 1.25))),
                                      ]),
                                      const SizedBox(height: 12),
                                      FittedBox(
                                        fit: BoxFit.scaleDown,
                                        alignment: Alignment.centerLeft,
                                        child: Text(t.$2, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: -.4, fontFeatures: [FontFeature.tabularFigures()])),
                                      ),
                                      const SizedBox(height: 2),
                                      Text(tr(t.$3), style: TextStyle(color: hint, fontSize: 12), maxLines: 2, overflow: TextOverflow.ellipsis),
                                    ]),
                                  ),
                                ),
                              ),
                            ),
                          ),
                      ],
                    );
                  },
                ),
              ),
              const SizedBox(height: 16),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: LayoutBuilder(
                  builder: (context, cs) {
                    final cw = cs.maxWidth;
                    return Wrap(
                      spacing: 16,
                      runSpacing: 16,
                      children: [
                        SizedBox(
                          width: cw > 900 ? (cw - 16) * .62 : double.infinity,
                          child: card(
                            'Activité par jour',
                            icon: Icons.show_chart_rounded,
                            trailing: DropdownButton<String>(
                              value: _metric,
                              underline: const SizedBox(),
                              items: [for (final e in metricLabels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                              onChanged: (v) => setState(() => _metric = v ?? _metric),
                            ),
                            SizedBox(
                              height: 240,
                              child: CartesianChart(data: chart(daily, 'day', (s) => DateFormat('d MMM', 'fr').format(DateTime.parse(s))), currency: cur, line: true, height: 240),
                            ),
                          ),
                        ),
                        SizedBox(
                          width: cw > 900 ? (cw - 16) * .38 - 1 : double.infinity,
                          child: card(
                            'Connectés sur 24 h',
                            icon: Icons.wifi_tethering,
                            series.length < 2
                                ? SizedBox(
                                    height: 240,
                                    child: Center(
                                      child: Text(
                                        tr('La courbe se remplit au fil de la journée (relevé toutes les 5 minutes).'),
                                        textAlign: TextAlign.center,
                                        style: TextStyle(color: hint),
                                      ),
                                    ),
                                  )
                                : SizedBox(
                                    height: 240,
                                    child: CartesianChart(
                                      data: ChartData(
                                        [for (final s in series) DateFormat('HH:mm').format(DateTime.parse('${s['at']}').toLocal())],
                                        [
                                          ChartSeries(tr('Connectés'), [for (final s in series) toDouble(s['users'])]),
                                        ],
                                        'number',
                                      ),
                                      currency: cur,
                                      line: true,
                                      height: 240,
                                    ),
                                  ),
                          ),
                        ),
                        card(
                          'Par mois (12 derniers mois)',
                          icon: Icons.calendar_month_outlined,
                          SizedBox(
                            height: 220,
                            child: CartesianChart(data: chart(monthly, 'month', (s) => DateFormat('MMM yy', 'fr').format(DateTime.parse('$s-01'))), currency: cur, line: false, height: 220),
                          ),
                        ),
                        SizedBox(
                          width: cw > 900 ? (cw - 16) / 2 : double.infinity,
                          child: card(
                            'Modules les plus vendus (90 jours)',
                            icon: Icons.extension_outlined,
                            (d['top_modules'] as List? ?? const []).isEmpty
                                ? Text(tr('Aucune vente sur la période.'), style: TextStyle(color: hint))
                                : Column(
                                    children: [
                                      for (final m in (d['top_modules'] as List).map(asMap))
                                        ListTile(
                                          dense: true,
                                          contentPadding: EdgeInsets.zero,
                                          leading: const Icon(Icons.extension_outlined, color: YColors.blue),
                                          title: Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                          subtitle: Text(tr('${m['orders']} commande(s)')),
                                          trailing: Text(money(m['revenue'] as num, cur), style: const TextStyle(fontWeight: FontWeight.w800)),
                                        ),
                                    ],
                                  ),
                          ),
                        ),
                        SizedBox(
                          width: cw > 900 ? (cw - 16) / 2 - 1 : double.infinity,
                          child: card(
                            'Qui est connecté',
                            icon: Icons.people_alt_outlined,
                            trailing: widget.onGo == null ? null : TextButton(onPressed: () => widget.onGo!('admin_online'), child: Text(tr('Tout voir'))),
                            (now['list'] as List? ?? const []).isEmpty
                                ? Text(tr('Personne en ligne.'), style: TextStyle(color: hint))
                                : Column(
                                    children: [
                                      for (final u in (now['list'] as List).take(8).map(asMap))
                                        ListTile(
                                          dense: true,
                                          contentPadding: EdgeInsets.zero,
                                          leading: InitialsAvatar('${u['name']}', size: 32),
                                          title: Text(tr('${u['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                          subtitle: Text(tr('${u['company'] ?? '—'}')),
                                          trailing: Icon(platformIcon(u['platform'] as String?), size: 18, color: hint),
                                        ),
                                    ],
                                  ),
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ),
              if (asMap(d['licenses']).isNotEmpty || asMap(d['platforms_30d']).isNotEmpty) ...[
                const SizedBox(height: 16),
                Padding(
                  padding: EdgeInsets.symmetric(horizontal: pad),
                  child: card(
                    'En ligne vs installations sous licence',
                    icon: Icons.compare_arrows_rounded,
                    trailing: widget.onGo == null
                        ? null
                        : TextButton.icon(onPressed: () => widget.onGo!('admin_licenses'), icon: const Icon(Icons.vpn_key_outlined, size: 18), label: Text(tr('Voir les licences'))),
                    _LicenseVsOnline(licenses: asMap(d['licenses']), platforms: asMap(d['platforms_30d']), currency: cur),
                  ),
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}

/// Comparaison abonnés en ligne (SaaS) / installations chez les clients (licences) et appareils utilisés sur 30 jours.
class _LicenseVsOnline extends StatelessWidget {
  const _LicenseVsOnline({required this.licenses, required this.platforms, required this.currency});

  final Map<String, dynamic> licenses;
  final Map<String, dynamic> platforms;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    int n(String k) => (licenses[k] as num? ?? 0).toInt();
    final saas = n('saas_subscriptions');
    final active = n('active');
    final share = saas + active == 0 ? .5 : saas / (saas + active);
    const saasColor = YColors.blue;
    const licColor = Color(0xFF0E9F6E);

    Widget side(String title, IconData icon, Color color, List<(String, String)> rows) => Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(color: color.withValues(alpha: .06), borderRadius: BorderRadius.circular(14), border: Border.all(color: color.withValues(alpha: .18))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(icon, size: 18, color: color),
              const SizedBox(width: 8),
              Expanded(child: Text(tr(title), style: TextStyle(fontWeight: FontWeight.w800, color: color))),
            ]),
            const SizedBox(height: 10),
            for (final r in rows)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(children: [
                  Expanded(child: Text(tr(r.$1), style: TextStyle(color: hint, fontSize: 13))),
                  Text(r.$2, style: const TextStyle(fontWeight: FontWeight.w800)),
                ]),
              ),
          ]),
        );

    final online = side('En ligne (abonnements)', Icons.cloud_outlined, saasColor, [
      ('Abonnements actifs', '$saas'),
      ('Utilisateurs actifs (30 j)', '${platforms.values.fold<num>(0, (s, v) => s + (v is num ? v : 0))}'),
    ]);
    final licensed = side('Installations sous licence', Icons.dns_outlined, licColor, [
      ('Licences actives', '$active / ${n('total')}'),
      ('Licences payantes', '${n('paying')}'),
      ('Modules en cours', '${n('modules_running')}'),
      ('Revenus licences du mois', money(licenses['revenue_month'] as num? ?? 0, currency)),
      ('En ligne maintenant', '${n('online')}'),
      ('Nouvelles ce mois', '${n('new_month')}'),
    ]);

    final total = platforms.values.fold<num>(0, (s, v) => s + (v is num ? v : 0));
    final order = ['web', 'windows', 'macos', 'linux', 'android', 'ios'];
    final keys = [...order.where(platforms.containsKey), ...platforms.keys.where((k) => !order.contains(k))];
    final devices = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text(tr('Appareils des utilisateurs en ligne (30 jours)'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
      const SizedBox(height: 10),
      if (keys.isEmpty)
        Text(tr('Aucune connexion sur 30 jours.'), style: TextStyle(color: hint))
      else
        for (final k in keys)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(children: [
              Icon(platformIcon(k), size: 16, color: hint),
              const SizedBox(width: 8),
              SizedBox(width: 86, child: Text(tr(platformLabels[k] ?? k), style: const TextStyle(fontSize: 13), overflow: TextOverflow.ellipsis)),
              Expanded(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(5),
                  child: LinearProgressIndicator(
                    value: total == 0 ? 0 : (platforms[k] as num? ?? 0) / total,
                    minHeight: 8,
                    color: YColors.blue,
                    backgroundColor: YColors.blue.withValues(alpha: .10),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(width: 72, child: Text(tr('${platforms[k]} · ${total == 0 ? 0 : ((platforms[k] as num? ?? 0) / total * 100).round()} %'), textAlign: TextAlign.right, style: TextStyle(color: hint, fontSize: 12))),
            ]),
          ),
    ]);

    return LayoutBuilder(builder: (context, cs) {
      final wide = cs.maxWidth > 900;
      final mid = cs.maxWidth > 560;
      final split = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Text(tr('En ligne $saas'), style: const TextStyle(fontWeight: FontWeight.w700, color: saasColor, fontSize: 12.5)),
          const Spacer(),
          Text(tr('Sous licence $active'), style: const TextStyle(fontWeight: FontWeight.w700, color: licColor, fontSize: 12.5)),
        ]),
        const SizedBox(height: 6),
        SizedBox(
          height: 12,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(value: share, color: saasColor, backgroundColor: licColor),
          ),
        ),
      ]);
      final compare = mid
          ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: online), const SizedBox(width: 12), Expanded(child: licensed)])
          : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [online, const SizedBox(height: 12), licensed]);
      final left = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [split, const SizedBox(height: 14), compare]);
      if (!wide) return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [left, const SizedBox(height: 18), devices]);
      return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(flex: 3, child: left), const SizedBox(width: 24), Expanded(flex: 2, child: devices)]);
    });
  }
}

class _LiveDot extends StatefulWidget {
  const _LiveDot();

  @override
  State<_LiveDot> createState() => _LiveDotState();
}

class _LiveDotState extends State<_LiveDot> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 28,
    height: 28,
    child: AnimatedBuilder(
      animation: _c,
      builder: (_, _) => Stack(
        alignment: Alignment.center,
        children: [
          Container(
            width: 12 + 16 * _c.value,
            height: 12 + 16 * _c.value,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: const Color(0xFF34D399).withValues(alpha: .5 * (1 - _c.value)),
            ),
          ),
          Container(
            width: 12,
            height: 12,
            decoration: const BoxDecoration(shape: BoxShape.circle, color: Color(0xFF34D399)),
          ),
        ],
      ),
    ),
  );
}
