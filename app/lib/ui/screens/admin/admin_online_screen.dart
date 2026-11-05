import 'package:flutter/material.dart';

import '../../theme.dart';
import '../../widgets/common.dart';
import 'admin_common.dart';
import '../../../i18n/i18n.dart';

/// Personnes connectées en temps réel (rafraîchi toutes les 15 secondes).
class AdminOnlineScreen extends StatefulWidget {
  const AdminOnlineScreen({super.key});

  @override
  State<AdminOnlineScreen> createState() => _AdminOnlineScreenState();
}

class _AdminOnlineScreenState extends State<AdminOnlineScreen> {
  String? _company; // filtre par société
  String _q = '';

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      path: 'admin/online',
      autoRefresh: const Duration(seconds: 15),
      builder: (context, d, reload) {
        final all = List<Map<String, dynamic>>.from((d['data'] as List).map((e) => Map<String, dynamic>.from(e)));
        final byCompany = Map<String, dynamic>.from(d['by_company'] is Map ? d['by_company'] : {});
        final minutes = (d['window_seconds'] as num? ?? 120) ~/ 60;
        final q = _q.trim().toLowerCase();
        final users = all.where((u) {
          if (_company != null && '${u['company'] ?? ''}' != _company) return false;
          if (q.isEmpty) return true;
          return '${u['name']} ${u['email']} ${u['company']} ${u['ip']}'.toLowerCase().contains(q);
        }).toList();
        final mobile = all.where((u) => const {'android', 'ios'}.contains(u['platform'])).length;
        final desktop = all.where((u) => const {'windows', 'macos', 'linux'}.contains(u['platform'])).length;
        final web = all.length - mobile - desktop;
        final narrow = MediaQuery.of(context).size.width < 700;

        return ListView(padding: EdgeInsets.fromLTRB(narrow ? 12 : 24, 16, narrow ? 12 : 24, 32), children: [
          _Hero(count: all.length, companies: byCompany.length, minutes: minutes, web: web, mobile: mobile, desktop: desktop, onRefresh: reload),
          const SizedBox(height: 16),
          // Recherche et filtre par société
          Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SizedBox(
              width: narrow ? double.infinity : 300,
              child: TextField(
                onChanged: (v) => setState(() => _q = v),
                decoration: InputDecoration(isDense: true, prefixIcon: const Icon(Icons.search, size: 20), hintText: tr('Nom, e-mail, société, adresse IP…')),
              ),
            ),
            _CompanyChip(label: tr('Toutes'), count: all.length, selected: _company == null, onTap: () => setState(() => _company = null)),
            for (final e in byCompany.entries)
              _CompanyChip(
                label: e.key == '' ? tr('Sans société') : e.key,
                count: (e.value as num).toInt(),
                selected: _company == e.key,
                onTap: () => setState(() => _company = _company == e.key ? null : e.key),
              ),
          ]),
          const SizedBox(height: 16),
          if (users.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 40),
              child: EmptyState(
                icon: Icons.wifi_off,
                title: tr(all.isEmpty ? 'Personne en ligne pour le moment' : 'Aucune personne ne correspond'),
                message: tr(all.isEmpty ? 'La liste se met à jour toute seule dès qu\'un utilisateur se connecte.' : 'Changez la recherche ou le filtre de société.'),
              ),
            )
          else
            LayoutBuilder(builder: (context, c) {
              final cols = (c.maxWidth / 340).floor().clamp(1, 4);
              final w = (c.maxWidth - (cols - 1) * 12) / cols;
              return Wrap(spacing: 12, runSpacing: 12, children: [for (final u in users) SizedBox(width: w, child: _PersonCard(u))]);
            }),
        ]);
      },
    );
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.count, required this.companies, required this.minutes, required this.web, required this.mobile, required this.desktop, required this.onRefresh});
  final int count, companies, minutes, web, mobile, desktop;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    Widget stat(IconData i, String v, String l) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(12), border: Border.all(color: Colors.white.withValues(alpha: .12))),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(i, size: 18, color: Colors.white70),
        const SizedBox(width: 8),
        Text(v, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16)),
        const SizedBox(width: 6),
        Text(tr(l), style: const TextStyle(color: Colors.white70, fontSize: 13)),
      ]),
    );
    return Container(
      padding: const EdgeInsets.fromLTRB(22, 20, 16, 20),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF173A7A), Color(0xFF1F6FEB)], begin: Alignment.topLeft, end: Alignment.bottomRight),
        boxShadow: [BoxShadow(color: YColors.blue.withValues(alpha: .18), blurRadius: 24, offset: const Offset(0, 10))],
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const _LiveDot(),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('En direct'), style: const TextStyle(color: Color(0xFF7CF0B0), fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 2),
              Text(tr(count == 1 ? '1 personne connectée' : '$count personnes connectées'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 26, height: 1.15)),
              const SizedBox(height: 4),
              Text(tr('Activité des $minutes dernières minutes · mise à jour toutes les 15 s'), style: const TextStyle(color: Colors.white70, fontSize: 13.5)),
            ]),
          ),
          IconButton(
            tooltip: tr('Actualiser'),
            onPressed: onRefresh,
            style: IconButton.styleFrom(backgroundColor: Colors.white.withValues(alpha: .12), foregroundColor: Colors.white),
            icon: const Icon(Icons.refresh),
          ),
        ]),
        const SizedBox(height: 16),
        Wrap(spacing: 8, runSpacing: 8, children: [
          stat(Icons.apartment_outlined, '$companies', companies > 1 ? 'sociétés' : 'société'),
          stat(Icons.language, '$web', 'navigateur'),
          stat(Icons.smartphone, '$mobile', 'mobile'),
          stat(Icons.computer, '$desktop', 'ordinateur'),
        ]),
      ]),
    );
  }
}

/// Point vert qui pulse : la liste est vivante.
class _LiveDot extends StatefulWidget {
  const _LiveDot();
  @override
  State<_LiveDot> createState() => _LiveDotState();
}

class _LiveDotState extends State<_LiveDot> with SingleTickerProviderStateMixin {
  late final _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1600))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.of(context).disableAnimations;
    return SizedBox(
      width: 46,
      height: 46,
      child: AnimatedBuilder(
        animation: _c,
        builder: (_, _) => Stack(alignment: Alignment.center, children: [
          if (!reduce)
            Container(
              width: 18 + 28 * _c.value,
              height: 18 + 28 * _c.value,
              decoration: BoxDecoration(shape: BoxShape.circle, color: const Color(0xFF34D399).withValues(alpha: .35 * (1 - _c.value))),
            ),
          Container(width: 18, height: 18, decoration: BoxDecoration(shape: BoxShape.circle, color: const Color(0xFF34D399), border: Border.all(color: Colors.white, width: 3))),
        ]),
      ),
    );
  }
}

class _CompanyChip extends StatelessWidget {
  const _CompanyChip({required this.label, required this.count, required this.selected, required this.onTap});
  final String label;
  final int count;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => FilterChip(
    selected: selected,
    showCheckmark: false,
    onSelected: (_) => onTap(),
    label: Row(mainAxisSize: MainAxisSize.min, children: [
      ConstrainedBox(constraints: const BoxConstraints(maxWidth: 220), child: Text(label, overflow: TextOverflow.ellipsis)),
      const SizedBox(width: 6),
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
        decoration: BoxDecoration(color: selected ? YColors.blue : YColors.blue.withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
        child: Text('$count', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: selected ? Colors.white : YColors.blue)),
      ),
    ]),
  );
}

class _PersonCard extends StatelessWidget {
  const _PersonCard(this.u);
  final Map<String, dynamic> u;

  String _ago() {
    final t = DateTime.tryParse('${u['last_seen_at'] ?? ''}')?.toLocal();
    if (t == null) return '—';
    final s = DateTime.now().difference(t).inSeconds;
    if (s < 45) return tr('à l\'instant');
    if (s < 3600) return tr('il y a ${(s / 60).round()} min');
    return dateTimeFr(u['last_seen_at']);
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final p = '${u['platform'] ?? ''}';
    Widget meta(IconData i, String t) => Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(i, size: 15, color: hint),
      const SizedBox(width: 5),
      Flexible(child: Text(t, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, color: hint))),
    ]);
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Theme.of(context).dividerColor.withValues(alpha: .6)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Stack(clipBehavior: Clip.none, children: [
            InitialsAvatar('${u['name']}', size: 42),
            Positioned(right: -1, bottom: -1, child: Container(width: 13, height: 13, decoration: BoxDecoration(color: YColors.success, shape: BoxShape.circle, border: Border.all(color: Theme.of(context).cardColor, width: 2.5)))),
          ]),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${u['name']}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              Text('${u['email'] ?? ''}', maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, color: hint)),
            ]),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(color: YColors.success.withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
            child: Text(_ago(), style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700, color: YColors.success)),
          ),
        ]),
        const SizedBox(height: 12),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
          decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .06), borderRadius: BorderRadius.circular(10)),
          child: Row(children: [
            const Icon(Icons.apartment_outlined, size: 16, color: YColors.blue),
            const SizedBox(width: 6),
            Expanded(child: Text('${u['company'] ?? tr('Sans société')}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13))),
          ]),
        ),
        const SizedBox(height: 10),
        Wrap(spacing: 14, runSpacing: 6, children: [
          meta(platformIcon(p), tr(platformLabels[p] ?? (p.isEmpty ? '—' : p))),
          meta(Icons.public, '${u['ip'] ?? '—'}'),
        ]),
      ]),
    );
  }
}
