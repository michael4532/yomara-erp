import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/filter_bar.dart';
import 'admin_common.dart';
import 'admin_companies_screen.dart' show showCompanyModulesDialog;

const _statusLabels = {'unused': 'À installer', 'active': 'Active', 'suspended': 'Suspendue', 'revoked': 'Révoquée'};
const _targetLabels = {'server': 'Serveur web', 'windows': 'PC Windows', 'mac': 'Mac', 'linux': 'PC Linux'};

Color _statusColor(String? s) => switch (s) {
      'active' => YColors.success,
      'unused' => YColors.blue,
      'suspended' => YColors.warning,
      _ => YColors.danger,
    };

IconData _targetIcon(String? t) => switch (t) {
      'server' => Icons.dns_outlined,
      'windows' => Icons.desktop_windows_outlined,
      'mac' => Icons.laptop_mac,
      'linux' => Icons.computer,
      _ => Icons.devices_other_outlined,
    };

Color _targetColor(String? t) => switch (t) {
      'server' => const Color(0xFF5B3FD8),
      'windows' => YColors.blue,
      'mac' => const Color(0xFF475569),
      'linux' => const Color(0xFFE8730C),
      _ => YColors.muted,
    };

List<Map<String, dynamic>> _list(Object? v) => [for (final e in (v is List ? v : const [])) asMap(e)];

/// « il y a 5 min », « il y a 3 h »… ou la date au-delà d'une semaine.
String _ago(Object? iso) {
  final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
  if (d == null) return 'jamais';
  final diff = DateTime.now().difference(d);
  if (diff.inMinutes < 1) return 'à l\'instant';
  if (diff.inMinutes < 60) return 'il y a ${diff.inMinutes} min';
  if (diff.inHours < 24) return 'il y a ${diff.inHours} h';
  if (diff.inDays < 7) return 'il y a ${diff.inDays} j';
  return 'le ${dateFr(iso)}';
}

/// Éditeur : toutes les installations des clients (serveurs et PC reliés à un compte en ligne).
class AdminLicensesScreen extends StatefulWidget {
  const AdminLicensesScreen({super.key});

  @override
  State<AdminLicensesScreen> createState() => _AdminLicensesScreenState();
}

class _AdminLicensesScreenState extends State<AdminLicensesScreen> {
  String _q = '';
  String _st = '';
  String? _target;
  Key _key = UniqueKey();

  void _reloadAll() => setState(() => _key = UniqueKey());

  Future<void> _open(Map<String, dynamic> l) async {
    final wide = MediaQuery.sizeOf(context).width >= 760;
    var changed = false;
    void onChanged() => changed = true;
    if (wide) {
      await showDialog<void>(
        context: context,
        builder: (_) => Dialog(
          insetPadding: const EdgeInsets.all(24),
          clipBehavior: Clip.antiAlias,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 820, maxHeight: 860),
            child: _LicenseDetail(license: l, onChanged: onChanged, dialog: true),
          ),
        ),
      );
    } else {
      await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => Scaffold(
          appBar: AppBar(title: Text(tr('${l['company_name'] ?? 'Installation'}'))),
          body: _LicenseDetail(license: l, onChanged: onChanged),
        ),
      ));
    }
    if (changed && mounted) _reloadAll();
  }

  Future<void> _unlockSigning(VoidCallback reload) async {
    final ctl = TextEditingController();
    final key = await showDialog<String>(
      context: context,
      builder: (c) => YAlertDialog(
        icon: const Icon(Icons.key_rounded),
        title: Text(tr('Clé de signature des licences')),
        subtitle: tr('Saisissez votre clé éditeur (celle de l\'installation de la plateforme).'),
        content: SizedBox(width: 420, child: TextField(controller: ctl, autofocus: true, obscureText: true, decoration: const InputDecoration(hintText: 'YED-…'))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, ctl.text.trim()), child: Text(tr('Activer'))),
        ],
      ),
    );
    if (key == null || key.isEmpty || !mounted) return;
    try {
      await context.read<Session>().api.post('admin/licenses/signing-key', {'editor_key': key});
      if (mounted) toast(context, tr('Signature des licences activée.'));
      reload();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return AdminLoader(
      key: _key,
      path: 'admin/licenses',
      autoRefresh: const Duration(seconds: 30),
      builder: (context, raw, reload) {
        final d = asMap(raw);
        final all = _list(d['data']);
        final st = asMap(d['stats']);
        final targets = {..._targetLabels, ...asMap(d['targets']).map((k, v) => MapEntry(k, '$v'))};
        final items = all
            .where((l) => _st.isEmpty || (_st == 'online' ? l['online'] == true : l['status'] == _st))
            .where((l) => _target == null || l['target'] == _target)
            .where((l) =>
                _q.isEmpty ||
                '${l['company_name']} ${l['owner'] ?? ''} ${l['key'] ?? ''} ${l['device_name'] ?? ''} ${l['install_url'] ?? ''} ${l['installed_company'] ?? ''}'.toLowerCase().contains(_q))
            .toList();
        final width = MediaQuery.sizeOf(context).width;
        final pad = width < 700 ? 16.0 : 24.0;
        final num total = st['total'] as num? ?? all.length;

        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(
            padding: const EdgeInsets.only(bottom: 40),
            children: [
              PageHeader(
                title: tr('Installations'),
                subtitle: tr('ERP installés chez les clients : serveurs web et PC reliés à un compte, modules achetés et activité'),
                actions: [IconButton.outlined(tooltip: tr('Actualiser'), onPressed: reload, icon: const Icon(Icons.refresh))],
              ),
              if (d['signing_ready'] == false)
                Padding(
                  padding: EdgeInsets.fromLTRB(pad, 0, pad, 14),
                  child: Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(color: YColors.warning.withValues(alpha: .1), borderRadius: BorderRadius.circular(12), border: Border.all(color: YColors.warning.withValues(alpha: .4))),
                    child: Row(children: [
                      const Icon(Icons.key_rounded, color: YColors.warning),
                      const SizedBox(width: 12),
                      Expanded(child: Text(tr('Activez la clé officielle de signature : sans elle, les installations de vos clients ne peuvent pas se relier à leur compte.'))),
                      const SizedBox(width: 12),
                      FilledButton(onPressed: () => _unlockSigning(reload), child: Text(tr('Activer'))),
                    ]),
                  ),
                ),
              Padding(padding: EdgeInsets.symmetric(horizontal: pad), child: AnimatedFadeIn(child: _Hero(stats: st, currency: cur))),
              const SizedBox(height: 14),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: _KpiGrid(stats: st, currency: cur, onStatus: (s) => setState(() => _st = s)),
              ),
              const SizedBox(height: 14),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: LayoutBuilder(builder: (context, cs) {
                  final two = cs.maxWidth > 820;
                  final w = two ? (cs.maxWidth - 14) / 2 : cs.maxWidth;
                  return Wrap(spacing: 14, runSpacing: 14, children: [
                    SizedBox(
                      width: w,
                      child: _Panel(
                        title: 'Par type d\'installation',
                        icon: Icons.donut_small_outlined,
                        child: _Bars(
                          rows: [
                            for (final e in targets.entries)
                              (e.key, tr(e.value), (asMap(st['by_target'])[e.key] as num? ?? 0).toDouble(), _targetIcon(e.key), _targetColor(e.key)),
                          ],
                          total: total.toDouble(),
                          onTap: (k) => setState(() => _target = _target == k ? null : k),
                          selected: _target,
                        ),
                      ),
                    ),
                    SizedBox(
                      width: w,
                      child: _Panel(
                        title: 'Versions en service',
                        icon: Icons.new_releases_outlined,
                        child: _Versions(versions: asMap(st['versions'])),
                      ),
                    ),
                  ]);
                }),
              ),
              const SizedBox(height: 18),
              FilterBar(
                padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
                onSearch: (v) => setState(() => _q = v.toLowerCase().trim()),
                search: _q,
                searchHint: 'Société, client, appareil, adresse, identifiant…',
                statuses: const {'': 'Toutes', 'active': 'Actives', 'online': 'En ligne', 'unused': 'À installer', 'suspended': 'Suspendues', 'revoked': 'Révoquées'},
                status: _st,
                statusCounts: {
                  '': all.length,
                  'online': all.where((l) => l['online'] == true).length,
                  for (final k in ['active', 'unused', 'suspended', 'revoked']) k: all.where((l) => l['status'] == k).length,
                },
                onStatus: (v) => setState(() => _st = v),
                filters: [FilterSpec('target', 'Type', targets, icon: Icons.devices_outlined)],
                values: {'target': _target},
                onFilter: (k, v) => setState(() => _target = v),
                count: items.length,
                countLabel: 'installation',
                onReset: () => setState(() => (_q = '', _st = '', _target = null)),
              ),
              if (items.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 20),
                  child: EmptyState(
                    icon: Icons.devices_other_outlined,
                    title: all.isEmpty ? 'Aucune installation pour l\'instant' : 'Aucune installation ne correspond',
                    message: all.isEmpty
                        ? 'Les clients installent l\'ERP gratuitement sur leur serveur ou leur PC, puis le relient à leur compte en ligne (« Compte Yomara & modules ») pour acheter des modules.'
                        : 'Modifiez les filtres ou la recherche.',
                  ),
                )
              else
                Padding(
                  padding: EdgeInsets.symmetric(horizontal: pad),
                  child: Column(children: [
                    for (var i = 0; i < items.length; i++)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: AnimatedFadeIn(
                          key: ValueKey('lic-${items[i]['id']}'),
                          index: i,
                          child: _LicenseRow(license: items[i], currency: cur, onTap: () => _open(items[i])),
                        ),
                      ),
                  ]),
                ),
            ],
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// En-tête et indicateurs
// ---------------------------------------------------------------------------

class _Hero extends StatelessWidget {
  const _Hero({required this.stats, required this.currency});

  final Map<String, dynamic> stats;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final online = (stats['online'] as num? ?? 0).toDouble();
    final active = (stats['active'] as num? ?? 0).toDouble();
    final total = (stats['total'] as num? ?? 0).toDouble();
    const sub = TextStyle(color: Color(0xFFBFD0F5), fontSize: 12.5);
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF13436B), Color(0xFF0E7C66)], begin: Alignment.topLeft, end: Alignment.bottomRight),
      ),
      child: Wrap(spacing: 30, runSpacing: 18, crossAxisAlignment: WrapCrossAlignment.center, children: [
        Row(mainAxisSize: MainAxisSize.min, children: [
          const _Pulse(size: 28),
          const SizedBox(width: 14),
          Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            CountUp(value: online, format: (v) => '${v.round()}', style: const TextStyle(color: Colors.white, fontSize: 44, fontWeight: FontWeight.w900, height: 1)),
            Text(tr('installations en ligne (vues depuis moins de 7 h)'), style: sub),
          ]),
        ]),
        Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          CountUp(value: total, format: (v) => '${v.round()}', style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
          Text(tr('installations reliées aux comptes'), style: sub),
        ]),
        Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          CountUp(value: toDouble(stats['revenue_month']), format: (v) => money(v, currency), style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
          Text(tr('modules vendus ce mois'), style: sub),
        ]),
        SizedBox(
          width: 220,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Text(tr('Taux d\'activation'), style: sub),
            const SizedBox(height: 6),
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: total == 0 ? 0 : active / total),
                duration: const Duration(milliseconds: 900),
                curve: Curves.easeOutCubic,
                builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 8, backgroundColor: Colors.white12, color: const Color(0xFF34D399)),
              ),
            ),
            const SizedBox(height: 4),
            Text(tr('${active.round()} active(s) sur ${total.round()}'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 12.5)),
          ]),
        ),
      ]),
    );
  }
}

class _KpiGrid extends StatelessWidget {
  const _KpiGrid({required this.stats, required this.currency, required this.onStatus});

  final Map<String, dynamic> stats;
  final String currency;
  final ValueChanged<String> onStatus;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    num n(String k) => stats[k] as num? ?? 0;
    final tiles = <(String, double, String Function(double), String, IconData, Color, String?, bool)>[
      ('Installations', n('total').toDouble(), (v) => '${v.round()}', 'Toutes les installations', Icons.devices_other_outlined, YColors.blue, '', false),
      ('Actives', n('active').toDouble(), (v) => '${v.round()}', 'Liées à un appareil', Icons.verified_outlined, YColors.success, 'active', false),
      ('En ligne maintenant', n('online').toDouble(), (v) => '${v.round()}', 'Vérification < 7 h', Icons.wifi_tethering, const Color(0xFF0E9F6E), 'online', true),
      ('À installer', n('unused').toDouble(), (v) => '${v.round()}', 'Pas encore reliée à un appareil', Icons.hourglass_empty_rounded, const Color(0xFF6366F1), 'unused', false),
      ('Suspendues / révoquées', n('suspended').toDouble(), (v) => '${v.round()}', 'Plus de modules actifs', Icons.block_outlined, YColors.danger, 'suspended', false),
      ('Clients propriétaires', n('owners').toDouble(), (v) => '${v.round()}', 'Comptes ayant relié une installation', Icons.business_center_outlined, const Color(0xFF7C3AED), null, false),
      ('Utilisateurs', n('users').toDouble(), (v) => number(v.round()), 'Déclarés par les installations', Icons.people_alt_outlined, const Color(0xFF0891B2), null, false),
      ('Revenus du mois', n('revenue_month').toDouble(), (v) => money(v, currency), 'Total : ${money(n('revenue_total'), currency)}', Icons.payments_outlined, YColors.warning, null, false),
    ];
    return LayoutBuilder(builder: (context, cs) {
      final cols = cs.maxWidth > 1100
          ? 4
          : cs.maxWidth > 560
              ? 2
              : 1;
      final w = (cs.maxWidth - (cols - 1) * 12) / cols;
      return Wrap(spacing: 12, runSpacing: 12, children: [
        for (var i = 0; i < tiles.length; i++)
          SizedBox(
            width: w,
            child: AnimatedFadeIn(
              index: i,
              child: HoverLift(
                child: Material(
                  color: Theme.of(context).cardColor,
                  borderRadius: BorderRadius.circular(18),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(18),
                    onTap: tiles[i].$7 == null ? null : () => onStatus(tiles[i].$7!),
                    child: Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(borderRadius: BorderRadius.circular(18), border: Border.all(color: Colors.black.withValues(alpha: .06))),
                      child: Row(children: [
                        DialogIconBadge(icon: Icon(tiles[i].$5), color: tiles[i].$6, size: 44),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Row(children: [
                              Flexible(child: Text(tr(tiles[i].$1), style: TextStyle(color: hint, fontSize: 12.5), overflow: TextOverflow.ellipsis)),
                              if (tiles[i].$8 && tiles[i].$2 > 0) ...[const SizedBox(width: 6), const _Pulse(size: 14)],
                            ]),
                            CountUp(value: tiles[i].$2, format: tiles[i].$3, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                            Text(tr(tiles[i].$4), style: TextStyle(color: hint, fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis),
                          ]),
                        ),
                      ]),
                    ),
                  ),
                ),
              ),
            ),
          ),
      ]);
    });
  }
}

class _Panel extends StatelessWidget {
  const _Panel({required this.title, required this.child, this.icon});

  final String title;
  final Widget child;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => AnimatedFadeIn(
        child: Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: Theme.of(context).cardColor,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: Colors.black.withValues(alpha: .06)),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              if (icon != null) ...[Icon(icon, size: 18, color: YColors.blue), const SizedBox(width: 8)],
              Expanded(child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
            ]),
            const SizedBox(height: 14),
            child,
          ]),
        ),
      );
}

/// Barres horizontales animées (répartition).
class _Bars extends StatelessWidget {
  const _Bars({required this.rows, required this.total, this.onTap, this.selected});

  final List<(String, String, double, IconData, Color)> rows;
  final double total;
  final ValueChanged<String>? onTap;
  final String? selected;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final max = rows.fold<double>(0, (m, r) => r.$3 > m ? r.$3 : m);
    return Column(children: [
      for (final r in rows)
        InkWell(
          borderRadius: BorderRadius.circular(10),
          onTap: onTap == null ? null : () => onTap!(r.$1),
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 7),
            decoration: BoxDecoration(color: selected == r.$1 ? r.$5.withValues(alpha: .08) : null, borderRadius: BorderRadius.circular(10)),
            child: Row(children: [
              Icon(r.$4, size: 18, color: r.$5),
              const SizedBox(width: 10),
              SizedBox(width: 104, child: Text(r.$2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13))),
              Expanded(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(6),
                  child: TweenAnimationBuilder<double>(
                    tween: Tween(begin: 0, end: max == 0 ? 0 : r.$3 / max),
                    duration: const Duration(milliseconds: 800),
                    curve: Curves.easeOutCubic,
                    builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 10, color: r.$5, backgroundColor: r.$5.withValues(alpha: .10)),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              SizedBox(
                width: 64,
                child: Text(
                  tr('${r.$3.round()} · ${total == 0 ? 0 : (r.$3 / total * 100).round()} %'),
                  textAlign: TextAlign.right,
                  style: TextStyle(color: hint, fontSize: 12, fontWeight: FontWeight.w600),
                ),
              ),
            ]),
          ),
        ),
    ]);
  }
}

class _Versions extends StatelessWidget {
  const _Versions({required this.versions});

  final Map<String, dynamic> versions;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    if (versions.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 18),
        child: Text(tr('Aucune installation n\'a encore communiqué sa version.'), style: TextStyle(color: hint)),
      );
    }
    final rows = versions.entries.toList()..sort((a, b) => _cmpVersion(b.key, a.key));
    final latest = rows.first.key;
    final sum = rows.fold<num>(0, (s, e) => s + (e.value as num? ?? 0));
    return Wrap(spacing: 8, runSpacing: 8, children: [
      for (final e in rows)
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            color: (e.key == latest ? YColors.success : YColors.muted).withValues(alpha: .08),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: (e.key == latest ? YColors.success : YColors.muted).withValues(alpha: .25)),
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Icon(e.key == latest ? Icons.verified_rounded : Icons.history_rounded, size: 16, color: e.key == latest ? YColors.success : hint),
            const SizedBox(width: 6),
            Text(tr('v${e.key}'), style: const TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(width: 8),
            Text(tr('${e.value} · ${sum == 0 ? 0 : ((e.value as num? ?? 0) / sum * 100).round()} %'), style: TextStyle(color: hint, fontSize: 12.5)),
          ]),
        ),
    ]);
  }
}

int _cmpVersion(String a, String b) {
  final pa = a.split(RegExp(r'[^0-9]+')).where((s) => s.isNotEmpty).map(int.parse).toList();
  final pb = b.split(RegExp(r'[^0-9]+')).where((s) => s.isNotEmpty).map(int.parse).toList();
  for (var i = 0; i < pa.length && i < pb.length; i++) {
    if (pa[i] != pb[i]) return pa[i].compareTo(pb[i]);
  }
  return pa.length != pb.length ? pa.length.compareTo(pb.length) : a.compareTo(b);
}

// ---------------------------------------------------------------------------
// Ligne de la liste
// ---------------------------------------------------------------------------

class _LicenseRow extends StatelessWidget {
  const _LicenseRow({required this.license, required this.currency, required this.onTap});

  final Map<String, dynamic> license;
  final String currency;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final l = license;
    final hint = Theme.of(context).hintColor;
    final target = l['target'] as String?;
    final online = l['online'] == true;
    final status = '${l['status']}';
    final title = Text(tr('${l['company_name'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15), overflow: TextOverflow.ellipsis);
    final owner = Text(tr('${l['owner'] ?? 'Client inconnu'}'), style: TextStyle(color: hint, fontSize: 12.5), overflow: TextOverflow.ellipsis);
    final chip = StatusChip(_statusLabels[status] ?? '${l['status_label'] ?? status}', color: _statusColor(status));

    Widget meta(IconData icon, String text) => Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 14, color: hint),
          const SizedBox(width: 4),
          Text(tr(text), style: TextStyle(color: hint, fontSize: 12.5)),
        ]);

    final metas = [
      meta(Icons.devices_outlined, '${l['device_name'] ?? 'Aucun appareil'}'),
      if (l['app_version'] != null) meta(Icons.new_releases_outlined, 'v${l['app_version']}'),
      meta(Icons.people_alt_outlined, '${l['users_count'] ?? 0} util.'),
      meta(Icons.schedule_rounded, 'vue ${_ago(l['last_seen_at'])}'),
      meta(Icons.extension_outlined, '${l['modules_count'] ?? _list(l['modules']).length} module(s)'),
    ];
    final revenue = Text(tr(money(l['revenue'] as num? ?? 0, currency)), style: const TextStyle(fontWeight: FontWeight.w800));

    final avatar = Stack(clipBehavior: Clip.none, children: [
      DialogIconBadge(icon: Icon(_targetIcon(target)), color: _targetColor(target), size: 46),
      if (online) const Positioned(right: -5, top: -5, child: _Pulse(size: 16)),
    ]);

    return HoverLift(
      child: Material(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: online ? YColors.success.withValues(alpha: .35) : Colors.black.withValues(alpha: .06)),
            ),
            child: LayoutBuilder(builder: (context, cs) {
              if (cs.maxWidth < 640) {
                return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    avatar,
                    const SizedBox(width: 12),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [title, owner])),
                    const SizedBox(width: 8),
                    chip,
                  ]),
                  const SizedBox(height: 10),
                  Wrap(spacing: 12, runSpacing: 6, children: metas),
                  const SizedBox(height: 8),
                  Row(children: [Text(tr('Revenus'), style: TextStyle(color: hint, fontSize: 12.5)), const Spacer(), revenue]),
                ]);
              }
              return Row(children: [
                avatar,
                const SizedBox(width: 14),
                Expanded(
                  flex: 4,
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [Flexible(child: title), const SizedBox(width: 8), chip]),
                    owner,
                    const SizedBox(height: 6),
                    Wrap(spacing: 14, runSpacing: 4, children: metas),
                  ]),
                ),
                const SizedBox(width: 12),
                Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                  revenue,
                  Text(tr(online ? 'en ligne' : (_targetLabels[target] ?? '${l['target_label'] ?? ''}')), style: TextStyle(color: online ? YColors.success : hint, fontSize: 12, fontWeight: FontWeight.w600)),
                ]),
                const SizedBox(width: 6),
                Icon(Icons.chevron_right_rounded, color: hint),
              ]);
            }),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Détail d'une installation
// ---------------------------------------------------------------------------

class _LicenseDetail extends StatefulWidget {
  const _LicenseDetail({required this.license, required this.onChanged, this.dialog = false});

  final Map<String, dynamic> license;
  final VoidCallback onChanged;
  final bool dialog;

  @override
  State<_LicenseDetail> createState() => _LicenseDetailState();
}

class _LicenseDetailState extends State<_LicenseDetail> {
  late Map<String, dynamic> _l = widget.license;
  bool _loading = true;
  String? _busy;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('admin/licenses/${widget.license['id']}');
      if (mounted) setState(() => _l = asMap(asMap(r)['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _action(String action) async {
    final name = '${_l['company_name'] ?? ''}';
    final ask = switch (action) {
      'suspend' => (
          'Suspendre l\'installation ?',
          'L\'installation « $name » perdra ses modules achetés à sa prochaine vérification (sous 6 h). Vous pourrez la réactiver à tout moment.',
          'Suspendre',
          true,
        ),
      'revoke' => (
          'Révoquer définitivement ?',
          'L\'installation « $name » perdra ses modules et ne pourra plus jamais être reliée sous cet identifiant. Cette action est destinée aux fraudes ou aux résiliations.',
          'Révoquer',
          true,
        ),
      'reset' => (
          'Libérer l\'appareil ?',
          'Le client pourra relier un autre appareil. L\'appareil actuel perdra ses modules à sa prochaine vérification et devra être relié de nouveau.',
          'Libérer',
          false,
        ),
      _ => null,
    };
    final ok = ask == null || await confirm(context, ask.$1, ask.$2, ok: ask.$3, danger: ask.$4);
    if (!ok || !mounted) return;
    setState(() => _busy = action);
    try {
      final r = await context.read<Session>().api.post('admin/licenses/${_l['id']}/status', {'action': action});
      if (!mounted) return;
      setState(() => _l = asMap(asMap(r)['data']));
      widget.onChanged();
      toast(context, switch (action) {
        'suspend' => 'Installation suspendue.',
        'reactivate' => 'Installation réactivée.',
        'revoke' => 'Installation révoquée.',
        _ => 'Appareil libéré.',
      });
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  Future<void> _modules() async {
    final id = _l['company_id'];
    if (id is! int) return;
    await showCompanyModulesDialog(context, companyId: id, title: tr('Modules de ${_l['company_name'] ?? 'l\'installation'}'), subtitle: tr('Installation ${_l['target_label'] ?? _targetLabels[_l['target']] ?? ''}'));
    widget.onChanged();
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = _l;
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final status = '${l['status']}';
    final target = l['target'] as String?;
    final online = l['online'] == true;
    final device = asMap(l['device']);
    final mods = _list(l['modules']);
    final events = _list(l['events']);
    final narrow = MediaQuery.sizeOf(context).width < 700;

    Widget info(String label, Object? value, {bool copy = false}) {
      final v = '${value ?? ''}'.trim();
      return SizedBox(
        width: narrow ? double.infinity : 240,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(label), style: TextStyle(color: hint, fontSize: 12)),
          const SizedBox(height: 2),
          Row(children: [
            Flexible(child: SelectableText(v.isEmpty ? '—' : v, style: const TextStyle(fontWeight: FontWeight.w600))),
            if (copy && v.isNotEmpty)
              IconButton(
                visualDensity: VisualDensity.compact,
                iconSize: 16,
                tooltip: tr('Copier'),
                onPressed: () {
                  Clipboard.setData(ClipboardData(text: v));
                  toast(context, 'Copié.');
                },
                icon: const Icon(Icons.copy_rounded),
              ),
          ]),
        ]),
      );
    }

    final header = Container(
      padding: EdgeInsets.fromLTRB(22, 20, widget.dialog ? 12 : 22, 20),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [const Color(0xFF0B1B3A), Color.lerp(const Color(0xFF0B1B3A), _statusColor(status), .55)!], begin: Alignment.topLeft, end: Alignment.bottomRight),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Stack(clipBehavior: Clip.none, children: [
          Container(
            width: 52,
            height: 52,
            decoration: BoxDecoration(color: Colors.white.withValues(alpha: .12), borderRadius: BorderRadius.circular(14)),
            child: Icon(_targetIcon(target), color: Colors.white, size: 28),
          ),
          if (online) const Positioned(right: -6, top: -6, child: _Pulse(size: 18)),
        ]),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${l['company_name'] ?? '—'}'), style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 2),
            Text(tr('${l['owner'] ?? 'Client inconnu'} · ${_targetLabels[target] ?? l['target_label'] ?? ''}'), style: const TextStyle(color: Color(0xFFBFD0F5))),
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 6, children: [
              _HeaderPill(label: _statusLabels[status] ?? '${l['status_label'] ?? status}', color: _statusColor(status)),
              _HeaderPill(label: online ? 'En ligne' : 'Vue ${_ago(l['last_seen_at'])}', color: online ? const Color(0xFF34D399) : Colors.white70),
              if (l['app_version'] != null) _HeaderPill(label: 'v${l['app_version']}', color: Colors.white70),
            ]),
          ]),
        ),
        if (widget.dialog) IconButton(onPressed: () => Navigator.of(context).maybePop(), icon: const Icon(Icons.close, color: Colors.white)),
      ]),
    );

    final actions = Wrap(spacing: 8, runSpacing: 8, children: [
      if (status == 'active' || status == 'unused')
        OutlinedButton.icon(
          onPressed: _busy != null ? null : () => _action('suspend'),
          icon: const Icon(Icons.pause_circle_outline),
          label: Text(tr('Suspendre')),
          style: OutlinedButton.styleFrom(foregroundColor: YColors.warning),
        ),
      if (status == 'suspended' || status == 'revoked')
        FilledButton.icon(
          onPressed: _busy != null ? null : () => _action('reactivate'),
          icon: const Icon(Icons.play_circle_outline),
          label: Text(tr('Réactiver')),
          style: FilledButton.styleFrom(backgroundColor: YColors.success),
        ),
      if (l['device_name'] != null || l['activated_at'] != null)
        OutlinedButton.icon(onPressed: _busy != null ? null : () => _action('reset'), icon: const Icon(Icons.link_off_rounded), label: Text(tr('Libérer l\'appareil'))),
      if (l['company_id'] is int) FilledButton.tonalIcon(onPressed: _busy != null ? null : _modules, icon: const Icon(Icons.extension_outlined), label: Text(tr('Gérer les modules'))),
      if (status != 'revoked')
        TextButton.icon(
          onPressed: _busy != null ? null : () => _action('revoke'),
          icon: const Icon(Icons.gpp_bad_outlined),
          label: Text(tr('Révoquer')),
          style: TextButton.styleFrom(foregroundColor: YColors.danger),
        ),
      if (_busy != null) const Padding(padding: EdgeInsets.all(8), child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))),
    ]);

    final body = ListView(padding: const EdgeInsets.all(18), children: [
      if (_loading) const Padding(padding: EdgeInsets.only(bottom: 10), child: LinearProgressIndicator(minHeight: 2)),
      actions,
      const SizedBox(height: 14),
      SectionCard(
        title: 'Installation',
        child: Wrap(spacing: 18, runSpacing: 14, children: [
          info('Identifiant technique', l['key'] ?? l['masked_key'], copy: true),
          info('Société prévue', l['company_name']),
          info('Société déclarée par l\'installation', l['installed_company']),
          info('Client propriétaire', l['owner']),
          info('Type', _targetLabels[target] ?? l['target_label']),
          info('Ajoutée le', dateFr(l['created_at'])),
          info('Reliée le', l['activated_at'] == null ? null : dateTimeFr(l['activated_at'])),
          info('Dernière vérification', l['last_seen_at'] == null ? 'jamais' : '${dateTimeFr(l['last_seen_at'])} (${_ago(l['last_seen_at'])})'),
          info('Revenus (modules payés)', money(l['revenue'] as num? ?? 0, cur)),
          if (l['notes'] != null && '${l['notes']}'.isNotEmpty) info('Notes', l['notes']),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: 'Appareil',
        child: Wrap(spacing: 18, runSpacing: 14, children: [
          info('Nom de l\'appareil', l['device_name']),
          info('Adresse de l\'installation', l['install_url'], copy: true),
          info('Système', [device['os'], device['os_version']].where((e) => e != null).join(' ')),
          info('Architecture', device['arch']),
          info('PHP', device['php']),
          info('Base de données', device['db']),
          info('Hôte', device['host']),
          info('Dernière IP', l['last_ip']),
          info('Version de l\'application', l['app_version']),
          info('Utilisateurs', l['users_count']),
          info('Appareils libérés', l['resets'] ?? 0),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: 'Modules de l\'installation (${mods.length})',
        trailing: l['company_id'] is int ? TextButton(onPressed: _modules, child: Text(tr('Gérer'))) : null,
        child: mods.isEmpty
            ? Text(tr(status == 'suspended' || status == 'revoked' ? 'Installation inactive : aucun module transmis.' : 'Seuls les modules de base sont actifs.'), style: TextStyle(color: hint))
            : Column(children: [
                for (final m in mods)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.extension_outlined, color: YColors.blue),
                    title: Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    trailing: _ExpiryChip(m['expires_at']),
                  ),
              ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: 'Historique',
        child: events.isEmpty
            ? Text(tr(_loading ? 'Chargement…' : 'Aucun événement.'), style: TextStyle(color: hint))
            : Column(children: [for (var i = 0; i < events.length; i++) _EventTile(event: events[i], last: i == events.length - 1)]),
      ),
    ]);

    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      header,
      Flexible(child: body),
    ]);
  }
}

class _HeaderPill extends StatelessWidget {
  const _HeaderPill({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: Colors.white.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Container(width: 7, height: 7, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Text(tr(label), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 12.5)),
        ]),
      );
}

class _ExpiryChip extends StatelessWidget {
  const _ExpiryChip(this.expiresAt);

  final Object? expiresAt;

  @override
  Widget build(BuildContext context) {
    final d = DateTime.tryParse('${expiresAt ?? ''}');
    if (d == null) return const StatusChip('Sans échéance', color: YColors.success, icon: Icons.all_inclusive_rounded);
    final days = d.difference(DateTime.now()).inDays;
    final color = days < 0
        ? YColors.danger
        : days <= 7
            ? YColors.warning
            : YColors.blue;
    return StatusChip(days < 0 ? 'Expiré le ${dateFr(expiresAt)}' : 'Jusqu\'au ${dateFr(expiresAt)}', color: color, icon: Icons.event_outlined);
  }
}

class _EventTile extends StatelessWidget {
  const _EventTile({required this.event, required this.last});

  final Map<String, dynamic> event;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final type = '${event['type']}';
    final (icon, color) = switch (type) {
      'created' => (Icons.add_circle_outline, YColors.blue),
      'activated' => (Icons.check_circle_outline, YColors.success),
      'refused' => (Icons.do_not_disturb_on_outlined, YColors.danger),
      'reset' => (Icons.link_off_rounded, const Color(0xFF6366F1)),
      'suspended' => (Icons.pause_circle_outline, YColors.warning),
      'reactivated' => (Icons.play_circle_outline, YColors.success),
      'revoked' => (Icons.gpp_bad_outlined, YColors.danger),
      'module' => (Icons.extension_outlined, const Color(0xFF7C3AED)),
      _ => (Icons.edit_note_rounded, YColors.muted),
    };
    final data = asMap(event['data']);
    final details = [
      for (final e in data.entries)
        if (e.value != null && '${e.value}'.isNotEmpty) '${_dataLabels[e.key] ?? e.key} : ${e.value}',
      if (event['ip'] != null) 'IP ${event['ip']}',
    ].join(' · ');
    return IntrinsicHeight(
      child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        SizedBox(
          width: 30,
          child: Column(children: [
            Container(
              width: 28,
              height: 28,
              decoration: BoxDecoration(color: color.withValues(alpha: .12), shape: BoxShape.circle),
              child: Icon(icon, size: 16, color: color),
            ),
            if (!last) Expanded(child: Container(width: 2, color: color.withValues(alpha: .18))),
          ]),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Padding(
            padding: EdgeInsets.only(bottom: last ? 0 : 14, top: 4),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(tr(_eventLabels[type] ?? '${event['label'] ?? type}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                Text(tr(dateTimeFr(event['at'])), style: TextStyle(color: hint, fontSize: 12)),
              ]),
              if (details.isNotEmpty) Text(tr(details), style: TextStyle(color: hint, fontSize: 12.5)),
            ]),
          ),
        ),
      ]),
    );
  }
}

const _eventLabels = {
  'created': 'Installation ajoutée',
  'activated': 'Installation reliée',
  'refused': 'Liaison refusée (autre appareil)',
  'reset': 'Appareil libéré',
  'suspended': 'Installation suspendue',
  'reactivated': 'Installation réactivée',
  'revoked': 'Installation révoquée',
  'renamed': 'Installation modifiée',
  'module': 'Module activé',
};

const _dataLabels = {'by': 'par', 'device': 'appareil', 'url': 'adresse', 'company': 'société', 'target': 'type', 'module': 'module'};

/// Point vert qui pulse : installation en ligne.
class _Pulse extends StatefulWidget {
  const _Pulse({this.size = 20});

  final double size;

  @override
  State<_Pulse> createState() => _PulseState();
}

class _PulseState extends State<_Pulse> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.size;
    final dot = s * .43;
    return SizedBox(
      width: s,
      height: s,
      child: AnimatedBuilder(
        animation: _c,
        builder: (_, _) => Stack(alignment: Alignment.center, children: [
          Container(
            width: dot + (s - dot) * _c.value,
            height: dot + (s - dot) * _c.value,
            decoration: BoxDecoration(shape: BoxShape.circle, color: const Color(0xFF34D399).withValues(alpha: .5 * (1 - _c.value))),
          ),
          Container(
            width: dot,
            height: dot,
            decoration: BoxDecoration(shape: BoxShape.circle, color: const Color(0xFF34D399), border: Border.all(color: Colors.white, width: s > 16 ? 1.5 : 1)),
          ),
        ]),
      ),
    );
  }
}
