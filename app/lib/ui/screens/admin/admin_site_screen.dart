import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/report_charts.dart';
import 'admin_common.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// « Site & prospects » : audience du site de présentation (sans cookie), entonnoir d'inscription,
/// provenance des visiteurs, demandes reçues et nouveaux inscrits à relancer.
class AdminSiteScreen extends StatefulWidget {
  const AdminSiteScreen({super.key, this.onGo});

  final void Function(String id)? onGo;

  @override
  State<AdminSiteScreen> createState() => _AdminSiteScreenState();
}

const _violet = Color(0xFF7C3AED);
const _teal = Color(0xFF0EA5A4);
const _pink = Color(0xFFDB2777);

List<Map<String, dynamic>> _rows(Object? v) => [for (final x in (v is List ? v : const [])) asMap(x)];

int _int(Object? v) => toDouble(v).round();

String _ago(Object? v) {
  final d = DateTime.tryParse('${v ?? ''}')?.toLocal();
  if (d == null) return '—';
  final diff = DateTime.now().difference(d);
  if (diff.inMinutes < 1) return tr('à l\'instant');
  if (diff.inMinutes < 60) return tr('il y a ${diff.inMinutes} min');
  if (diff.inHours < 24) return tr('il y a ${diff.inHours} h');
  if (diff.inDays < 7) return tr('il y a ${diff.inDays} j');
  return dateFr(v);
}

String _digits(String phone) {
  var p = phone.replaceAll(RegExp(r'\D'), '');
  if (p.startsWith('00')) p = p.substring(2);
  return p;
}

class _AdminSiteScreenState extends State<AdminSiteScreen> {
  int _days = 30;
  bool _onlyNew = true;
  final Set<int> _expanded = {};
  final Set<int> _busy = {};
  final Map<int, String> _status = {};

  Future<void> _open(Uri uri) async {
    try {
      final ok = await safeLaunchUrl(uri, mode: LaunchMode.externalApplication);
      if (!ok && mounted) toast(context, 'Impossible d\'ouvrir ce lien.', error: true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _setStatus(Map<String, dynamic> r, String status, VoidCallback reload) async {
    final id = _int(r['id']);
    setState(() => _busy.add(id));
    try {
      await context.read<Session>().api.put('admin/landing-requests/$id', {'status': status});
      if (!mounted) return;
      setState(() => _status[id] = status);
      toast(context, status == 'handled' ? 'Demande marquée comme traitée.' : 'Demande remise à traiter.');
      reload();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(id));
    }
  }

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      key: ValueKey(_days),
      path: 'admin/site-analytics?days=$_days',
      autoRefresh: const Duration(seconds: 60),
      builder: (context, raw, reload) {
        final d = asMap(raw);
        final width = MediaQuery.sizeOf(context).width;
        final pad = width < 700 ? 16.0 : 24.0;
        final header = _header(context, reload);
        if (d.isEmpty) {
          return ListView(
            padding: const EdgeInsets.only(bottom: 40),
            children: [
              header,
              const SizedBox(height: 40),
              const EmptyState(
                icon: Icons.insights_outlined,
                title: 'Mesure d\'audience pas encore active',
                message: 'Lancez les migrations du serveur : les visites du site de présentation apparaîtront ici dès les premiers visiteurs.',
              ),
            ],
          );
        }
        final totals = asMap(d['totals']);
        final today = asMap(d['today']);
        final daily = _rows(d['daily']);
        final requests = [
          for (final r in _rows(d['requests'])) {...r, 'status': _status[_int(r['id'])] ?? r['status']},
        ];
        final signups = _rows(d['signups_list']);
        final newCount = requests.where((r) => r['status'] != 'handled').length;
        final requestsNew = requests.isEmpty ? _int(d['requests_new']) : newCount;

        Widget section(int i, Widget child) => Padding(
          padding: EdgeInsets.fromLTRB(pad, 0, pad, 16),
          child: AnimatedFadeIn(index: i, child: child),
        );

        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(
            padding: const EdgeInsets.only(bottom: 40),
            children: [
              header,
              section(0, _hero(context, totals, today, daily, requestsNew)),
              section(1, _kpis(context, totals, requestsNew, daily)),
              section(
                2,
                LayoutBuilder(
                  builder: (context, cs) {
                    final wide = cs.maxWidth > 980;
                    final chart = _dailyChart(context, daily);
                    final funnel = _funnel(context, _rows(d['funnel']));
                    if (!wide) return Column(children: [chart, const SizedBox(height: 16), funnel]);
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(flex: 62, child: chart),
                        const SizedBox(width: 16),
                        Expanded(flex: 38, child: funnel),
                      ],
                    );
                  },
                ),
              ),
              section(3, _breakdowns(context, d)),
              section(
                4,
                LayoutBuilder(
                  builder: (context, cs) {
                    final wide = cs.maxWidth > 1080;
                    final req = _requests(context, requests, reload);
                    final sig = _signups(context, signups);
                    if (!wide) return Column(children: [req, const SizedBox(height: 16), sig]);
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(flex: 56, child: req),
                        const SizedBox(width: 16),
                        Expanded(flex: 44, child: sig),
                      ],
                    );
                  },
                ),
              ),
              section(5, _followUp(context, asMap(d['editor_company']), signups, requests)),
            ],
          ),
        );
      },
    );
  }

  // ---------------------------------------------------------------------------
  // En-tête
  // ---------------------------------------------------------------------------

  Widget _header(BuildContext context, VoidCallback reload) {
    final narrow = MediaQuery.sizeOf(context).width < 520;
    return PageHeader(
      title: 'Site & prospects',
      icon: Icons.travel_explore_rounded,
      subtitle: 'Visites du site de présentation, provenance, inscriptions et demandes à traiter',
      actions: [
        SegmentedButton<int>(
          showSelectedIcon: false,
          segments: [
            for (final n in [7, 30, 90, 365]) ButtonSegment(value: n, label: Text(n == 365 ? tr('1 an') : tr('$n j'))),
          ],
          selected: {_days},
          onSelectionChanged: (s) => setState(() => _days = s.first),
        ),
        IconButton.outlined(tooltip: tr('Actualiser'), onPressed: reload, icon: const Icon(Icons.refresh)),
        narrow
            ? IconButton.filled(
                tooltip: tr('Voir le site'),
                onPressed: () => _open(Uri.parse(context.read<Session>().api.baseUrl)),
                icon: const Icon(Icons.open_in_new_rounded),
              )
            : FilledButton.icon(
                onPressed: () => _open(Uri.parse(context.read<Session>().api.baseUrl)),
                icon: const Icon(Icons.open_in_new_rounded, size: 18),
                label: Text(tr('Voir le site')),
              ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Bandeau « aujourd'hui »
  // ---------------------------------------------------------------------------

  Widget _hero(BuildContext context, Map<String, dynamic> totals, Map<String, dynamic> today, List<Map<String, dynamic>> daily, int requestsNew) {
    final narrow = MediaQuery.sizeOf(context).width < 600;
    final visitors = daily.map((r) => toDouble(r['visitors'])).toList();
    final todayItems = [
      (_int(today['visitors']), 'visiteurs aujourd\'hui', Icons.person_outline_rounded),
      (_int(today['views']), 'pages vues', Icons.visibility_outlined),
      (_int(today['signups']), 'inscription(s)', Icons.how_to_reg_outlined),
      (_int(today['contacts']), 'demande(s)', Icons.mark_email_unread_outlined),
    ];
    return Container(
      padding: EdgeInsets.all(narrow ? 18 : 24),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF1A3F8F), Color(0xFF0E7490)], begin: Alignment.topLeft, end: Alignment.bottomRight),
        boxShadow: [BoxShadow(color: const Color(0xFF1A3F8F).withValues(alpha: .25), blurRadius: 24, offset: const Offset(0, 10))],
      ),
      child: Stack(
        children: [
          if (visitors.length > 1 && !narrow)
            Positioned.fill(
              child: IgnorePointer(
                child: Opacity(opacity: .22, child: _Sparkline(values: visitors)),
              ),
            ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const _PulseDot(),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      tr('AUJOURD\'HUI SUR VOTRE SITE'),
                      style: const TextStyle(color: Color(0xFFBFD0F5), fontSize: 11.5, fontWeight: FontWeight.w800, letterSpacing: 1.2),
                    ),
                  ),
                  if (requestsNew > 0)
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(color: YColors.yellow, borderRadius: BorderRadius.circular(20)),
                      child: Text(
                        tr('$requestsNew demande(s) à traiter'),
                        style: const TextStyle(color: YColors.night, fontWeight: FontWeight.w800, fontSize: 12),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 16),
              Wrap(
                spacing: narrow ? 18 : 34,
                runSpacing: 16,
                children: [
                  for (final (i, t) in todayItems.indexed)
                    SizedBox(
                      width: narrow ? 130 : null,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(t.$3, size: 15, color: const Color(0xFFBFD0F5)),
                              const SizedBox(width: 6),
                              Flexible(
                                child: Text(tr(t.$2), style: const TextStyle(color: Color(0xFFBFD0F5), fontSize: 12.5), overflow: TextOverflow.ellipsis),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          CountUp(
                            value: t.$1.toDouble(),
                            format: (v) => number(v.round()),
                            style: TextStyle(color: Colors.white, fontSize: i == 0 ? 40 : 28, fontWeight: FontWeight.w900, height: 1.05),
                          ),
                        ],
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 14),
              Text(
                tr('Sur $_days jours : ${number(_int(totals['visitors']))} visiteurs uniques, ${number(_int(totals['views']))} pages vues. Mesure sans cookie, robots exclus.'),
                style: const TextStyle(color: Color(0xFFBFD0F5), fontSize: 12.5),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Indicateurs
  // ---------------------------------------------------------------------------

  Widget _kpis(BuildContext context, Map<String, dynamic> totals, int requestsNew, List<Map<String, dynamic>> daily) {
    final hint = Theme.of(context).hintColor;
    final visitors = _int(totals['visitors']);
    final views = _int(totals['views']);
    final perVisitor = visitors > 0 ? views / visitors : 0.0;
    final avg = daily.isEmpty ? 0.0 : visitors / daily.length;
    final tiles = [
      (
        'Visiteurs uniques',
        visitors.toDouble(),
        (double v) => number(v.round()),
        '≈ ${number(avg, decimals: avg < 10 ? 1 : 0)} par jour',
        Icons.people_alt_outlined,
        YColors.blue,
        0,
      ),
      ('Pages vues', views.toDouble(), (double v) => number(v.round()), '${number(perVisitor, decimals: 1)} page(s) par visiteur', Icons.visibility_outlined, _teal, 0),
      (
        'Inscriptions',
        toDouble(totals['signups']),
        (double v) => number(v.round()),
        '${number(_int(totals['signup_forms']))} ont ouvert le formulaire',
        Icons.how_to_reg_outlined,
        YColors.success,
        0,
      ),
      (
        'Taux de conversion',
        toDouble(totals['conversion']),
        (double v) => '${number(v, decimals: 1)} %',
        'Visiteurs devenus inscrits',
        Icons.trending_up_rounded,
        _violet,
        0,
      ),
      (
        'Demandes reçues',
        toDouble(totals['contacts']),
        (double v) => number(v.round()),
        requestsNew > 0 ? '$requestsNew à traiter' : 'Toutes traitées',
        Icons.mark_email_unread_outlined,
        YColors.warning,
        requestsNew,
      ),
      ('Téléchargements', toDouble(totals['downloads']), (double v) => number(v.round()), 'Applications et serveur', Icons.download_rounded, _pink, 0),
    ];
    return LayoutBuilder(
      builder: (context, cs) {
        final cols = cs.maxWidth > 1250
            ? 6
            : cs.maxWidth > 760
            ? 3
            : cs.maxWidth > 440
            ? 2
            : 1;
        final w = (cs.maxWidth - (cols - 1) * 12) / cols;
        return Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            for (final (i, t) in tiles.indexed)
              SizedBox(
                width: w,
                child: AnimatedFadeIn(
                  index: i,
                  child: HoverLift(
                    child: Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Theme.of(context).cardColor,
                        borderRadius: BorderRadius.circular(18),
                        border: Border.all(color: Colors.black.withValues(alpha: .06)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              DialogIconBadge(icon: Icon(t.$5), color: t.$6, size: 38),
                              const Spacer(),
                              if (t.$7 > 0)
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                  decoration: BoxDecoration(color: YColors.danger, borderRadius: BorderRadius.circular(20)),
                                  child: Text(
                                    tr('${t.$7} nouv.'),
                                    style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w800),
                                  ),
                                ),
                            ],
                          ),
                          const SizedBox(height: 12),
                          Text(tr(t.$1), style: TextStyle(color: hint, fontSize: 12.5), maxLines: 1, overflow: TextOverflow.ellipsis),
                          CountUp(
                            value: t.$2,
                            format: t.$3,
                            style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, letterSpacing: -.5),
                          ),
                          Text(tr(t.$4), style: TextStyle(color: hint, fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  // ---------------------------------------------------------------------------
  // Carte générique
  // ---------------------------------------------------------------------------

  Widget _card(BuildContext context, String title, Widget child, {IconData? icon, Color color = YColors.blue, Widget? trailing, String? subtitle}) {
    final hint = Theme.of(context).hintColor;
    return Container(
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
              if (icon != null) ...[
                Container(
                  width: 30,
                  height: 30,
                  decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(9)),
                  child: Icon(icon, size: 17, color: color),
                ),
                const SizedBox(width: 10),
              ],
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                    if (subtitle != null) Text(tr(subtitle), style: TextStyle(color: hint, fontSize: 12)),
                  ],
                ),
              ),
              ?trailing,
            ],
          ),
          const SizedBox(height: 14),
          child,
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Courbe journalière
  // ---------------------------------------------------------------------------

  Widget _dailyChart(BuildContext context, List<Map<String, dynamic>> daily) {
    final hint = Theme.of(context).hintColor;
    final empty = daily.every((r) => _int(r['views']) == 0 && _int(r['signups']) == 0 && _int(r['contacts']) == 0);
    final fmt = DateFormat(_days > 90 ? 'MMM yy' : 'd MMM', I18n.dateLocale);
    return _card(
      context,
      'Visites par jour',
      subtitle: 'Visiteurs uniques, inscriptions et demandes',
      icon: Icons.show_chart_rounded,
      SizedBox(
        height: 260,
        child: empty
            ? Center(
                child: Text(
                  tr('Aucune visite sur la période. Partagez le lien de votre site pour voir la courbe se remplir.'),
                  textAlign: TextAlign.center,
                  style: TextStyle(color: hint),
                ),
              )
            : CartesianChart(
                data: ChartData(
                  [for (final r in daily) DateTime.tryParse('${r['day']}') == null ? '${r['day']}' : fmt.format(DateTime.parse('${r['day']}'))],
                  [
                    ChartSeries(tr('Visiteurs'), [for (final r in daily) toDouble(r['visitors'])]),
                    ChartSeries(tr('Inscriptions'), [for (final r in daily) toDouble(r['signups'])]),
                    ChartSeries(tr('Demandes'), [for (final r in daily) toDouble(r['contacts'])]),
                  ],
                  'number',
                ),
                currency: 'XOF',
                line: true,
                height: 260,
              ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Entonnoir
  // ---------------------------------------------------------------------------

  Widget _funnel(BuildContext context, List<Map<String, dynamic>> steps) {
    final hint = Theme.of(context).hintColor;
    final top = steps.isEmpty ? 0 : _int(steps.first['value']);
    const colors = [YColors.blue, _teal, YColors.success, _violet];
    const icons = [Icons.people_alt_outlined, Icons.edit_note_rounded, Icons.how_to_reg_outlined, Icons.rocket_launch_outlined];
    return _card(
      context,
      'Entonnoir de conversion',
      subtitle: 'Du visiteur au client qui utilise l\'ERP',
      icon: Icons.filter_alt_outlined,
      color: _violet,
      steps.isEmpty
          ? Text(tr('Pas encore de données.'), style: TextStyle(color: hint))
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final (i, s) in steps.indexed) ...[
                  if (i > 0)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(
                        children: [
                          const SizedBox(width: 6),
                          Icon(Icons.south_rounded, size: 15, color: hint),
                          const SizedBox(width: 6),
                          Text(
                            _int(steps[i - 1]['value']) > 0
                                ? tr('${number(_int(s['value']) * 100 / _int(steps[i - 1]['value']), decimals: 1)} % de l\'étape précédente')
                                : tr('—'),
                            style: TextStyle(color: hint, fontSize: 11.5, fontWeight: FontWeight.w600),
                          ),
                        ],
                      ),
                    ),
                  Row(
                    children: [
                      Icon(icons[i % icons.length], size: 16, color: colors[i % colors.length]),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(tr('${s['label']}'), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13), overflow: TextOverflow.ellipsis),
                      ),
                      Text(number(_int(s['value'])), style: const TextStyle(fontWeight: FontWeight.w800)),
                    ],
                  ),
                  const SizedBox(height: 6),
                  _Bar(
                    fraction: top > 0 ? _int(s['value']) / top : 0,
                    color: colors[i % colors.length],
                    height: 14,
                    delay: i,
                  ),
                ],
                const SizedBox(height: 12),
                if (top > 0)
                  Text(
                    tr('${number(_int(steps.last['value']) * 100 / top, decimals: 1)} % des visiteurs utilisent réellement l\'ERP.'),
                    style: TextStyle(color: hint, fontSize: 12),
                  ),
              ],
            ),
    );
  }

  // ---------------------------------------------------------------------------
  // Répartitions
  // ---------------------------------------------------------------------------

  static IconData _sourceIcon(String s) {
    final l = s.toLowerCase();
    if (l == 'direct') return Icons.link_rounded;
    if (l.contains('google') || l.contains('bing') || l.contains('yahoo')) return Icons.search_rounded;
    if (l.contains('facebook') || l.contains('instagram') || l.contains('tiktok') || l.contains('twitter') || l.contains('linkedin')) {
      return Icons.thumb_up_alt_outlined;
    }
    if (l.contains('whatsapp')) return Icons.chat_outlined;
    if (l.contains('youtube')) return Icons.play_circle_outline_rounded;
    if (l.contains('mail')) return Icons.alternate_email_rounded;
    return Icons.public_rounded;
  }

  Widget _breakdowns(BuildContext context, Map<String, dynamic> d) {
    String sourceLabel(String s) => s == 'direct' ? tr('Accès direct / lien partagé') : verbatim(s);
    String downloadLabel(String s) => s == 'serveur' ? tr('Serveur (ZIP)') : tr(platformLabels[s] ?? s);
    IconData downloadIcon(String s) => s == 'serveur' ? Icons.dns_outlined : platformIcon(s);
    IconData deviceIcon(String s) => switch (s) {
      'mobile' => Icons.smartphone_rounded,
      'tablette' => Icons.tablet_mac_rounded,
      _ => Icons.desktop_windows_outlined,
    };
    IconData osIcon(String s) => switch (s) {
      'Android' => Icons.android_rounded,
      'iOS' => Icons.phone_iphone,
      'Windows' => Icons.desktop_windows_outlined,
      'macOS' => Icons.laptop_mac,
      'Linux' => Icons.computer,
      _ => Icons.devices_other_rounded,
    };

    final cards = [
      _BreakdownSpec(
        'Sources de trafic',
        Icons.alt_route_rounded,
        YColors.blue,
        _rows(d['sources']),
        'Aucune visite pour l\'instant.',
        label: sourceLabel,
        itemIcon: _sourceIcon,
      ),
      _BreakdownSpec(
        'Sites référents',
        Icons.link_rounded,
        _teal,
        _rows(d['referrers']),
        'Les visiteurs arrivent directement. Les sites qui renvoient vers vous (Google, Facebook…) s\'afficheront ici.',
        itemIcon: _sourceIcon,
      ),
      _BreakdownSpec('Pages les plus vues', Icons.article_outlined, _violet, _rows(d['pages']), 'Aucune page vue sur la période.', itemIcon: (_) => Icons.description_outlined),
      _BreakdownSpec(
        'Campagnes',
        Icons.campaign_outlined,
        _pink,
        _rows(d['campaigns']),
        'Ajoutez ?utm_source=facebook&utm_campaign=lancement à vos liens (publicités, WhatsApp, e-mails) pour suivre vos campagnes.',
        itemIcon: (_) => Icons.campaign_outlined,
      ),
      _BreakdownSpec(
        'Appareils',
        Icons.devices_rounded,
        YColors.success,
        _rows(d['devices']),
        'Aucune visite pour l\'instant.',
        label: (s) => tr(s.isEmpty ? s : s[0].toUpperCase() + s.substring(1)),
        itemIcon: deviceIcon,
      ),
      _BreakdownSpec('Systèmes', Icons.memory_rounded, YColors.warning, _rows(d['os']), 'Aucune visite pour l\'instant.', label: tr, itemIcon: osIcon),
      _BreakdownSpec(
        'Téléchargements par plateforme',
        Icons.download_rounded,
        YColors.night,
        _rows(d['downloads']),
        'Aucun téléchargement sur la période. Les boutons « Télécharger » du site sont comptés ici.',
        label: downloadLabel,
        itemIcon: downloadIcon,
      ),
    ];

    return LayoutBuilder(
      builder: (context, cs) {
        final cols = cs.maxWidth > 1150
            ? 3
            : cs.maxWidth > 720
            ? 2
            : 1;
        final w = (cs.maxWidth - (cols - 1) * 16) / cols;
        return Wrap(
          spacing: 16,
          runSpacing: 16,
          children: [
            for (final (i, c) in cards.indexed)
              SizedBox(
                width: w,
                child: AnimatedFadeIn(index: i, child: _breakdownCard(context, c)),
              ),
          ],
        );
      },
    );
  }

  Widget _breakdownCard(BuildContext context, _BreakdownSpec c) {
    final hint = Theme.of(context).hintColor;
    final total = c.items.fold<int>(0, (s, r) => s + _int(r['count']));
    final max = c.items.fold<int>(0, (s, r) => _int(r['count']) > s ? _int(r['count']) : s);
    return _card(
      context,
      c.title,
      icon: c.icon,
      color: c.color,
      trailing: total > 0 ? Text(number(total), style: TextStyle(color: hint, fontWeight: FontWeight.w700)) : null,
      c.items.isEmpty
          ? Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: c.color.withValues(alpha: .06), borderRadius: BorderRadius.circular(12)),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.lightbulb_outline_rounded, size: 18, color: c.color),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(tr(c.empty), style: TextStyle(color: hint, fontSize: 12.5)),
                  ),
                ],
              ),
            )
          : Column(
              children: [
                for (final (i, r) in c.items.indexed)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(
                          children: [
                            Icon(c.itemIcon('${r['label'] ?? ''}'), size: 15, color: c.color),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                c.label('${r['label'] ?? '—'}'),
                                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            const SizedBox(width: 8),
                            Text(number(_int(r['count'])), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
                            SizedBox(
                              width: 46,
                              child: Text(
                                total > 0 ? '${(_int(r['count']) * 100 / total).round()} %' : '',
                                textAlign: TextAlign.right,
                                style: TextStyle(color: hint, fontSize: 12),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 5),
                        _Bar(fraction: max > 0 ? _int(r['count']) / max : 0, color: c.color, height: 6, delay: i),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }

  // ---------------------------------------------------------------------------
  // Demandes reçues
  // ---------------------------------------------------------------------------

  static Color _typeColor(String t) => switch (t) {
    'custom_module' => _violet,
    'demo' => YColors.blue,
    'quote' => YColors.success,
    'install' => _teal,
    _ => YColors.muted,
  };

  static IconData _typeIcon(String t) => switch (t) {
    'custom_module' => Icons.extension_outlined,
    'demo' => Icons.slideshow_outlined,
    'quote' => Icons.request_quote_outlined,
    'install' => Icons.dns_outlined,
    _ => Icons.help_outline_rounded,
  };

  Widget _requests(BuildContext context, List<Map<String, dynamic>> all, VoidCallback reload) {
    final pending = all.where((r) => r['status'] != 'handled').toList();
    final shown = _onlyNew ? pending : all;
    return _card(
      context,
      'Demandes reçues',
      subtitle: 'Modules sur mesure, démonstrations, devis, installations, questions',
      icon: Icons.inbox_outlined,
      color: YColors.warning,
      Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ChoiceChip(
                label: Text(tr('À traiter (${pending.length})')),
                selected: _onlyNew,
                onSelected: (_) => setState(() => _onlyNew = true),
              ),
              ChoiceChip(
                label: Text(tr('Toutes (${all.length})')),
                selected: !_onlyNew,
                onSelected: (_) => setState(() => _onlyNew = false),
              ),
            ],
          ),
          const SizedBox(height: 12),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 250),
            child: shown.isEmpty
                ? Padding(
                    key: ValueKey('empty$_onlyNew'),
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: EmptyState(
                      icon: _onlyNew ? Icons.task_alt_rounded : Icons.inbox_outlined,
                      title: _onlyNew ? 'Tout est traité' : 'Aucune demande reçue',
                      message: _onlyNew
                          ? 'Les nouvelles demandes du formulaire de contact du site arriveront ici.'
                          : 'Le formulaire « Nous contacter / module sur mesure » du site alimente cette liste.',
                    ),
                  )
                : Column(
                    key: ValueKey('list$_onlyNew'),
                    children: [for (final (i, r) in shown.take(40).indexed) AnimatedFadeIn(index: i, child: _requestTile(context, r, reload))],
                  ),
          ),
        ],
      ),
    );
  }

  Widget _requestTile(BuildContext context, Map<String, dynamic> r, VoidCallback reload) {
    final hint = Theme.of(context).hintColor;
    final id = _int(r['id']);
    final type = '${r['type'] ?? 'contact'}';
    final color = _typeColor(type);
    final handled = r['status'] == 'handled';
    final expanded = _expanded.contains(id);
    final email = '${r['email'] ?? ''}'.trim();
    final phone = '${r['phone'] ?? ''}'.trim();
    final name = '${r['name'] ?? ''}'.trim();
    final company = '${r['company'] ?? ''}'.trim();
    final budget = '${r['budget'] ?? ''}'.trim();
    final message = '${r['message'] ?? ''}'.trim();
    final typeLabel = '${r['type_label'] ?? type}';
    final busy = _busy.contains(id);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 250),
        decoration: BoxDecoration(
          color: handled ? Colors.transparent : color.withValues(alpha: .045),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: handled ? Colors.black.withValues(alpha: .07) : color.withValues(alpha: .28)),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () => setState(() => expanded ? _expanded.remove(id) : _expanded.add(id)),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    InitialsAvatar(name.isEmpty ? '?' : name, size: 36),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            verbatim(name.isEmpty ? '—' : name),
                            style: const TextStyle(fontWeight: FontWeight.w700),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          if (company.isNotEmpty)
                            Text(verbatim(company), style: TextStyle(color: hint, fontSize: 12.5), maxLines: 1, overflow: TextOverflow.ellipsis),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(_ago(r['created_at']), style: TextStyle(color: hint, fontSize: 11.5)),
                  ],
                ),
                const SizedBox(height: 10),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    StatusChip(typeLabel, color: color, icon: _typeIcon(type)),
                    if (budget.isNotEmpty) StatusChip(tr('Budget : ${verbatim(budget)}'), color: YColors.success, icon: Icons.account_balance_wallet_outlined),
                    StatusChip(
                      handled ? 'Traitée' : 'À traiter',
                      color: handled ? YColors.success : YColors.danger,
                      icon: handled ? Icons.check_circle_outline : Icons.schedule_rounded,
                    ),
                  ],
                ),
                if (message.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  AnimatedSize(
                    duration: const Duration(milliseconds: 220),
                    curve: Curves.easeOutCubic,
                    alignment: Alignment.topCenter,
                    child: Text(
                      verbatim(message),
                      maxLines: expanded ? null : 2,
                      overflow: expanded ? TextOverflow.visible : TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 13.5, height: 1.45),
                    ),
                  ),
                  if (!expanded && message.length > 120)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        tr('Afficher tout'),
                        style: const TextStyle(color: YColors.blue, fontSize: 12, fontWeight: FontWeight.w600),
                      ),
                    ),
                ],
                if (expanded && (email.isNotEmpty || phone.isNotEmpty)) ...[
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 14,
                    runSpacing: 4,
                    children: [
                      if (email.isNotEmpty) _contactLine(context, Icons.alternate_email_rounded, email),
                      if (phone.isNotEmpty) _contactLine(context, Icons.phone_outlined, phone),
                      _contactLine(context, Icons.event_outlined, dateTimeFr(r['created_at'])),
                    ],
                  ),
                ],
                const SizedBox(height: 10),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    if (email.isNotEmpty)
                      _action(
                        Icons.mail_outline_rounded,
                        'Répondre par e-mail',
                        YColors.blue,
                        () => _open(
                          Uri.parse(
                            'mailto:$email?subject=${Uri.encodeComponent(tr('Votre demande : $typeLabel'))}&body=${Uri.encodeComponent(tr('Bonjour ${name.isEmpty ? '' : name},\n\n'))}',
                          ),
                        ),
                      ),
                    if (_digits(phone).length >= 6) ...[
                      _action(Icons.chat_outlined, 'WhatsApp', const Color(0xFF25D366), () => _open(Uri.parse('https://wa.me/${_digits(phone)}'))),
                      _action(Icons.call_outlined, 'Appeler', _teal, () => _open(Uri(scheme: 'tel', path: phone.replaceAll(RegExp(r'[^\d+]'), '')))),
                    ],
                    busy
                        ? const Padding(
                            padding: EdgeInsets.all(8),
                            child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
                          )
                        : _action(
                            handled ? Icons.undo_rounded : Icons.check_rounded,
                            handled ? 'Remettre à traiter' : 'Marquer traitée',
                            handled ? YColors.muted : YColors.success,
                            () => _setStatus(r, handled ? 'new' : 'handled', reload),
                            filled: !handled,
                          ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _contactLine(BuildContext context, IconData icon, String text) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(icon, size: 14, color: Theme.of(context).hintColor),
      const SizedBox(width: 5),
      Flexible(
        child: SelectableText(verbatim(text), style: const TextStyle(fontSize: 12.5)),
      ),
    ],
  );

  Widget _action(IconData icon, String label, Color color, VoidCallback onTap, {bool filled = false}) {
    final style = ButtonStyle(
      visualDensity: VisualDensity.compact,
      padding: const WidgetStatePropertyAll(EdgeInsets.symmetric(horizontal: 12, vertical: 6)),
      shape: WidgetStatePropertyAll(RoundedRectangleBorder(borderRadius: BorderRadius.circular(10))),
    );
    if (filled) {
      return FilledButton.icon(
        style: style.copyWith(backgroundColor: WidgetStatePropertyAll(color)),
        onPressed: onTap,
        icon: Icon(icon, size: 16),
        label: Text(tr(label), style: const TextStyle(fontSize: 12.5)),
      );
    }
    return OutlinedButton.icon(
      style: style.copyWith(
        foregroundColor: WidgetStatePropertyAll(color),
        side: WidgetStatePropertyAll(BorderSide(color: color.withValues(alpha: .4))),
      ),
      onPressed: onTap,
      icon: Icon(icon, size: 16),
      label: Text(tr(label), style: const TextStyle(fontSize: 12.5)),
    );
  }

  // ---------------------------------------------------------------------------
  // Nouveaux inscrits
  // ---------------------------------------------------------------------------

  Widget _signups(BuildContext context, List<Map<String, dynamic>> list) {
    final hint = Theme.of(context).hintColor;
    final idle = list.where((s) => _int(s['active_users']) == 0).length;
    return _card(
      context,
      'Nouveaux inscrits',
      subtitle: idle > 0 ? '$idle inscrit(s) n\'ont pas encore utilisé l\'ERP : à relancer' : 'Sociétés créées sur la période',
      icon: Icons.how_to_reg_outlined,
      color: YColors.success,
      trailing: widget.onGo == null ? null : TextButton(onPressed: () => widget.onGo!('admin_companies'), child: Text(tr('Sociétés'))),
      list.isEmpty
          ? const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: EmptyState(
                icon: Icons.person_add_alt_1_outlined,
                title: 'Aucune inscription sur la période',
                message: 'Les sociétés qui s\'inscrivent depuis le site apparaîtront ici.',
              ),
            )
          : Column(
              children: [
                for (final (i, s) in list.indexed)
                  AnimatedFadeIn(
                    index: i,
                    child: Builder(
                      builder: (context) {
                        final active = _int(s['active_users']) > 0;
                        final status = '${s['status'] ?? ''}';
                        final email = '${s['email'] ?? ''}'.trim();
                        final phone = '${s['phone'] ?? ''}'.trim();
                        final (statusLabel, statusColor) = switch (status) {
                          'active' => ('Active', YColors.success),
                          'trial' => ('Essai', YColors.warning),
                          'suspended' => ('Suspendue', YColors.danger),
                          _ => (status.isEmpty ? '—' : status, YColors.muted),
                        };
                        return Container(
                          margin: const EdgeInsets.only(bottom: 8),
                          padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
                          decoration: BoxDecoration(
                            color: active ? Colors.transparent : YColors.warning.withValues(alpha: .07),
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(color: active ? Colors.black.withValues(alpha: .06) : YColors.warning.withValues(alpha: .35)),
                          ),
                          child: Row(
                            children: [
                              InitialsAvatar('${s['name'] ?? '?'}', size: 34),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      verbatim('${s['name'] ?? '—'}'),
                                      style: const TextStyle(fontWeight: FontWeight.w700),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                    Text(
                                      verbatim([if (email.isNotEmpty) email, if (phone.isNotEmpty) phone].join(' · ')),
                                      style: TextStyle(color: hint, fontSize: 12),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                    const SizedBox(height: 5),
                                    Wrap(
                                      spacing: 6,
                                      runSpacing: 4,
                                      crossAxisAlignment: WrapCrossAlignment.center,
                                      children: [
                                        StatusChip(statusLabel, color: statusColor),
                                        active
                                            ? StatusChip(
                                                tr('Utilise l\'ERP (${_int(s['active_users'])} utilisateur(s))'),
                                                color: YColors.success,
                                                icon: Icons.bolt_rounded,
                                              )
                                            : const StatusChip('À relancer', color: YColors.warning, icon: Icons.notifications_active_outlined),
                                        Text(_ago(s['created_at']), style: TextStyle(color: hint, fontSize: 11.5)),
                                      ],
                                    ),
                                  ],
                                ),
                              ),
                              if (!active) ...[
                                if (email.isNotEmpty)
                                  IconButton(
                                    tooltip: tr('Relancer par e-mail'),
                                    visualDensity: VisualDensity.compact,
                                    icon: const Icon(Icons.mail_outline_rounded, size: 20, color: YColors.blue),
                                    onPressed: () => _open(
                                      Uri.parse(
                                        'mailto:$email?subject=${Uri.encodeComponent(tr('Bienvenue sur Yomara ERP : on vous aide à démarrer ?'))}',
                                      ),
                                    ),
                                  ),
                                if (_digits(phone).length >= 6)
                                  IconButton(
                                    tooltip: tr('Relancer sur WhatsApp'),
                                    visualDensity: VisualDensity.compact,
                                    icon: const Icon(Icons.chat_outlined, size: 20, color: Color(0xFF25D366)),
                                    onPressed: () => _open(Uri.parse('https://wa.me/${_digits(phone)}')),
                                  ),
                              ],
                            ],
                          ),
                        );
                      },
                    ),
                  ),
              ],
            ),
    );
  }

  // ---------------------------------------------------------------------------
  // Relance des prospects
  // ---------------------------------------------------------------------------

  Widget _followUp(BuildContext context, Map<String, dynamic> editor, List<Map<String, dynamic>> signups, List<Map<String, dynamic>> requests) {
    final narrow = MediaQuery.sizeOf(context).width < 700;
    final editorName = '${editor['name'] ?? ''}'.trim();
    final steps = [
      (Icons.how_to_reg_outlined, 'Chaque inscrit est ajouté à la liste Marketing « Site — inscrits » et au CRM.'),
      (Icons.mark_email_unread_outlined, 'Chaque demande est ajoutée à la liste « Site — demandes » et devient une piste CRM.'),
      (Icons.campaign_outlined, 'Relancez-les par e-mail, WhatsApp ou SMS avec une campagne, et suivez les pistes jusqu\'à la vente.'),
    ];
    return Container(
      padding: EdgeInsets.all(narrow ? 18 : 24),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: LinearGradient(
          colors: [YColors.yellow.withValues(alpha: .20), YColors.blue.withValues(alpha: .10), _violet.withValues(alpha: .10)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: YColors.yellow.withValues(alpha: .45)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const DialogIconBadge(icon: Icon(Icons.rocket_launch_outlined), color: YColors.warning, size: 44),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(tr('Relancer vos prospects'), style: Theme.of(context).textTheme.titleLarge),
                    Text(
                      editorName.isEmpty
                          ? tr('Vos prospects sont transférés automatiquement dans votre société éditeur.')
                          : tr('Transférés automatiquement dans la société ${verbatim(editorName)}.'),
                      style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          for (final (i, s) in steps.indexed)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 26,
                    height: 26,
                    alignment: Alignment.center,
                    decoration: const BoxDecoration(color: YColors.night, shape: BoxShape.circle),
                    child: Text('${i + 1}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12)),
                  ),
                  const SizedBox(width: 10),
                  Icon(s.$1, size: 18, color: YColors.blue),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr(s.$2))),
                ],
              ),
            ),
          const SizedBox(height: 4),
          Text(
            tr('${signups.length} inscrit(s) et ${requests.length} demande(s) sur la période.'),
            style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              FilledButton.icon(
                onPressed: widget.onGo == null ? null : () => widget.onGo!.call('marketing'),
                icon: const Icon(Icons.campaign_outlined, size: 18),
                label: Text(tr('Créer une campagne Marketing')),
              ),
              OutlinedButton.icon(
                onPressed: widget.onGo == null ? null : () => widget.onGo!.call('crm'),
                icon: const Icon(Icons.handshake_outlined, size: 18),
                label: Text(tr('Suivre les pistes dans le CRM')),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _BreakdownSpec {
  _BreakdownSpec(this.title, this.icon, this.color, this.items, this.empty, {String Function(String)? label, IconData Function(String)? itemIcon})
    : label = label ?? verbatim,
      itemIcon = itemIcon ?? ((_) => Icons.circle_outlined);

  final String title;
  final IconData icon;
  final Color color;
  final List<Map<String, dynamic>> items;
  final String empty;
  final String Function(String) label;
  final IconData Function(String) itemIcon;
}

/// Barre horizontale qui se remplit à l'affichage.
class _Bar extends StatelessWidget {
  const _Bar({required this.fraction, required this.color, this.height = 8, this.delay = 0});

  final double fraction;
  final Color color;
  final double height;
  final int delay;

  @override
  Widget build(BuildContext context) {
    final f = fraction.isNaN ? 0.0 : fraction.clamp(0.0, 1.0);
    final track = Container(
      height: height,
      decoration: BoxDecoration(color: color.withValues(alpha: .10), borderRadius: BorderRadius.circular(height)),
    );
    Widget fill(double v) => FractionallySizedBox(
      widthFactor: v == 0 ? 0 : v.clamp(.02, 1.0),
      child: Container(
        height: height,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(height),
          gradient: LinearGradient(colors: [color, Color.lerp(color, Colors.white, .25)!]),
        ),
      ),
    );
    return Stack(
      alignment: Alignment.centerLeft,
      children: [
        track,
        if (reduceMotion(context))
          fill(f)
        else
          TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: f),
            duration: Duration(milliseconds: 700 + delay * 80),
            curve: Curves.easeOutCubic,
            builder: (_, v, _) => fill(v),
          ),
      ],
    );
  }
}

/// Courbe décorative du bandeau.
class _Sparkline extends StatelessWidget {
  const _Sparkline({required this.values});

  final List<double> values;

  @override
  Widget build(BuildContext context) => TweenAnimationBuilder<double>(
    tween: Tween(begin: 0, end: 1),
    duration: Duration(milliseconds: reduceMotion(context) ? 0 : 1200),
    curve: Curves.easeOutCubic,
    builder: (_, t, _) => CustomPaint(painter: _SparkPainter(values, t)),
  );
}

class _SparkPainter extends CustomPainter {
  _SparkPainter(this.values, this.t);

  final List<double> values;
  final double t;

  @override
  void paint(Canvas canvas, Size size) {
    if (values.length < 2) return;
    final max = values.reduce((a, b) => a > b ? a : b);
    if (max <= 0) return;
    final top = size.height * .35;
    final h = size.height - top;
    final path = Path();
    for (var i = 0; i < values.length; i++) {
      final x = size.width * i / (values.length - 1);
      final y = top + h - h * (values[i] / max) * t;
      i == 0 ? path.moveTo(x, y) : path.lineTo(x, y);
    }
    final area = Path.from(path)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(
      area,
      Paint()
        ..shader = LinearGradient(
          colors: [Colors.white.withValues(alpha: .55), Colors.white.withValues(alpha: 0)],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ).createShader(Offset(0, top) & Size(size.width, h)),
    );
    canvas.drawPath(
      path,
      Paint()
        ..color = Colors.white
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2,
    );
  }

  @override
  bool shouldRepaint(_SparkPainter old) => old.t != t || old.values != values;
}

class _PulseDot extends StatefulWidget {
  const _PulseDot();

  @override
  State<_PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<_PulseDot> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SizedBox(
    width: 20,
    height: 20,
    child: AnimatedBuilder(
      animation: _c,
      builder: (_, _) => Stack(
        alignment: Alignment.center,
        children: [
          Container(
            width: 8 + 12 * _c.value,
            height: 8 + 12 * _c.value,
            decoration: BoxDecoration(shape: BoxShape.circle, color: const Color(0xFF34D399).withValues(alpha: .5 * (1 - _c.value))),
          ),
          Container(
            width: 8,
            height: 8,
            decoration: const BoxDecoration(shape: BoxShape.circle, color: Color(0xFF34D399)),
          ),
        ],
      ),
    ),
  );
}
