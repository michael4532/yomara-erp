import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'admin_common.dart';

/// Adoption : qui utilise vraiment le logiciel, quels modules, qui relancer et pourquoi (30 derniers jours).
class AdminAdoptionScreen extends StatefulWidget {
  const AdminAdoptionScreen({super.key});

  @override
  State<AdminAdoptionScreen> createState() => _AdminAdoptionScreenState();
}

const _statusLabels = {
  'active': 'Actif',
  'follow_up': 'À relancer',
  'risk': 'En danger',
  'never': 'Jamais utilisé',
  'new': 'Nouveau',
  'suspended': 'Suspendu',
};

Color _statusColor(String s) => switch (s) {
      'active' => YColors.success,
      'follow_up' => YColors.warning,
      'risk' => YColors.danger,
      'never' || 'new' => YColors.blue,
      _ => YColors.muted,
    };

class _AdminAdoptionScreenState extends State<AdminAdoptionScreen> {
  String _filter = 'todo'; // todo = tout ce qui demande une action
  String _q = '';

  bool _match(Map<String, dynamic> c, [String? filter]) {
    final s = '${c['status']}';
    final f = filter ?? _filter;
    final ok = switch (f) {
      'all' => true,
      'todo' => s != 'active' || asList(c['idle_paid_modules']).isNotEmpty || (c['trial_days_left'] is num && (c['trial_days_left'] as num) <= 5),
      'never' => s == 'never' || s == 'new',
      'trial' => c['trial_days_left'] is num && (c['trial_days_left'] as num) >= 0 && (c['trial_days_left'] as num) <= 5,
      'idle_modules' => asList(c['idle_paid_modules']).isNotEmpty,
      _ => s == f,
    };
    if (!ok) return false;
    final q = _q.trim().toLowerCase();
    return q.isEmpty || '${c['name']} ${c['email']} ${c['phone']} ${c['city']} ${c['plan']}'.toLowerCase().contains(q);
  }

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      path: 'admin/adoption',
      builder: (context, d, reload) {
        final k = asMap(d['kpis']);
        final all = asList(d['companies']).map(asMap).toList();
        final rows = all.where(_match).toList();
        final narrow = MediaQuery.of(context).size.width < 700;
        final pad = narrow ? 12.0 : 24.0;
        Widget kpi(String label, Object? v, IconData icon, Color color, String filter, {String? detail}) => KpiBox(
              width: 200,
              child: StatTile(label: label, value: '${v ?? 0}', icon: icon, color: color, detail: detail, onTap: () => setState(() => _filter = filter)),
            );

        return ListView(
          padding: EdgeInsets.fromLTRB(pad, 16, pad, 32),
          children: [
            PageHeader(
              title: tr('Adoption'),
              subtitle: tr('Qui utilise vraiment le logiciel ces ${d['window_days'] ?? 30} derniers jours, et qui relancer.'),
              icon: Icons.insights_outlined,
              actions: [IconButton(tooltip: tr('Actualiser'), onPressed: reload, icon: const Icon(Icons.refresh))],
            ),
            const SizedBox(height: 12),
            KpiWrap(minTile: 180, children: [
              kpi(tr('Clients'), k['companies'], Icons.business_outlined, YColors.blue, 'all', detail: tr('Score moyen ${k['avg_score'] ?? 0}/100')),
              kpi(tr('Actifs (7 jours)'), k['active'], Icons.check_circle_outline, YColors.success, 'active'),
              kpi(tr('À relancer'), k['follow_up'], Icons.notifications_active_outlined, YColors.warning, 'follow_up', detail: tr('7 à 14 jours sans activité')),
              kpi(tr('En danger'), k['risk'], Icons.warning_amber_rounded, YColors.danger, 'risk', detail: tr('Plus de 14 jours sans activité')),
              kpi(tr('Jamais utilisé'), k['never'], Icons.hourglass_empty, YColors.blue, 'never'),
              kpi(tr('Essais finissant'), k['trial_ending'], Icons.timer_outlined, YColors.warning, 'trial', detail: tr('Dans 5 jours ou moins')),
              kpi(tr('Modules payés inutilisés'), k['idle_paid_modules'], Icons.extension_off_outlined, YColors.danger, 'idle_modules'),
            ]),
            const SizedBox(height: 18),
            Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
              SizedBox(
                width: narrow ? double.infinity : 300,
                child: TextField(
                  onChanged: (v) => setState(() => _q = v),
                  decoration: InputDecoration(isDense: true, prefixIcon: const Icon(Icons.search, size: 20), hintText: tr('Société, e-mail, téléphone, ville…')),
                ),
              ),
              for (final f in const [
                ('todo', 'À traiter'),
                ('all', 'Tous'),
                ('active', 'Actifs'),
                ('follow_up', 'À relancer'),
                ('risk', 'En danger'),
                ('never', 'Jamais utilisé'),
                ('suspended', 'Suspendus'),
              ])
                ChoiceChip(
                  label: Text('${tr(f.$2)} (${all.where((c) => _match(c, f.$1)).length})'),
                  selected: _filter == f.$1,
                  onSelected: (_) => setState(() => _filter = f.$1),
                ),
            ]),
            const SizedBox(height: 14),
            if (rows.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 40),
                child: EmptyState(
                  icon: Icons.celebration_outlined,
                  title: tr(all.isEmpty ? 'Aucun client pour l\'instant' : 'Rien à traiter ici'),
                  message: tr(all.isEmpty ? 'Les sociétés clientes apparaîtront ici dès leur inscription.' : 'Changez le filtre pour voir les autres clients.'),
                ),
              )
            else
              LayoutBuilder(builder: (context, c) {
                final cols = clampCount((c.maxWidth / 430).floor(), 1, 3);
                final w = (c.maxWidth - (cols - 1) * 12) / cols;
                return Wrap(spacing: 12, runSpacing: 12, children: [for (final r in rows) SizedBox(width: w, child: _CompanyCard(r))]);
              }),
            const SizedBox(height: 24),
            _ModulesTable(asList(d['modules']).map(asMap).toList()),
          ],
        );
      },
    );
  }
}

class _CompanyCard extends StatelessWidget {
  const _CompanyCard(this.c);

  final Map<String, dynamic> c;

  String _ago(Object? days) {
    if (days == null) return tr('Aucune activité');
    final n = (days as num).toInt();
    return tr(n == 0 ? 'Actif aujourd\'hui' : (n == 1 ? 'Dernière activité hier' : 'Dernière activité il y a $n jours'));
  }

  static String? _wa(String phone) {
    var d = phone.replaceAll(RegExp(r'\D'), '');
    if (d.isEmpty) return null;
    if (d.startsWith('00')) d = d.substring(2);
    if (d.length == 10 && d.startsWith('0')) d = '225$d'; // numéro ivoirien saisi sans indicatif
    return d;
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final status = '${c['status']}';
    final color = _statusColor(status);
    final score = (c['score'] as num?)?.toInt() ?? 0;
    final used = asList(c['modules_used']).map(asMap).toList();
    final idle = asList(c['idle_paid_modules']).map(asMap).toList();
    final phone = '${c['phone'] ?? ''}'.trim();
    final email = '${c['email'] ?? ''}'.trim();
    final wa = _wa(phone);
    final trial = c['trial_days_left'];

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _Score(score: score, color: color),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${c['name']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15), overflow: TextOverflow.ellipsis),
                      Text(
                        [if (c['plan'] != null) '${c['plan']}', if ('${c['city'] ?? ''}'.isNotEmpty) '${c['city']}', tr('client depuis ${c['age_days']} j')].join(' · '),
                        style: TextStyle(color: hint, fontSize: 12),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
                StatusChip(_statusLabels[status] ?? status, color: color),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(spacing: 14, runSpacing: 4, children: [
              _Fact(Icons.schedule, _ago(c['idle_days'])),
              _Fact(Icons.people_outline, tr('${c['active_users_7d']}/${c['users']} utilisateur(s) actif(s) cette semaine')),
              _Fact(Icons.edit_note, tr('${c['records_30d']} saisie(s) en 30 j')),
              if (trial is num && trial >= 0) _Fact(Icons.timer_outlined, tr('Essai : $trial j restant(s)'), color: trial <= 5 ? YColors.warning : null),
            ]),
            if (used.isNotEmpty) ...[
              const SizedBox(height: 10),
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final m in used.take(6)) StatusChip('${m['name']}', color: YColors.blue),
                if (used.length > 6) StatusChip('+${used.length - 6}', color: YColors.muted),
              ]),
            ],
            if (idle.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(tr('Payé mais pas utilisé : ${idle.map((m) => m['name']).join(', ')}'), style: const TextStyle(color: YColors.danger, fontSize: 12)),
            ],
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: color.withValues(alpha: .07), borderRadius: BorderRadius.circular(10)),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.lightbulb_outline, size: 18, color: color),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr('${c['advice']}'), style: const TextStyle(fontSize: 13, height: 1.35))),
                ],
              ),
            ),
            if (phone.isNotEmpty || email.isNotEmpty) ...[
              const SizedBox(height: 8),
              Wrap(spacing: 4, children: [
                if (phone.isNotEmpty) TextButton.icon(onPressed: () => launchUrl(Uri.parse('tel:$phone')), icon: const Icon(Icons.call_outlined, size: 18), label: Text(tr('Appeler'))),
                if (wa != null)
                  TextButton.icon(
                    onPressed: () => launchUrl(Uri.parse('https://wa.me/$wa'), mode: LaunchMode.externalApplication),
                    icon: const Icon(Icons.chat_outlined, size: 18),
                    label: const Text('WhatsApp'),
                  ),
                if (email.isNotEmpty) TextButton.icon(onPressed: () => launchUrl(Uri.parse('mailto:$email')), icon: const Icon(Icons.mail_outline, size: 18), label: Text(tr('E-mail'))),
              ]),
            ],
          ],
        ),
      ),
    );
  }
}

class _Score extends StatelessWidget {
  const _Score({required this.score, required this.color});

  final int score;
  final Color color;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 46,
        height: 46,
        child: Stack(alignment: Alignment.center, children: [
          CircularProgressIndicator(value: score / 100, strokeWidth: 4, color: color, backgroundColor: color.withValues(alpha: .15)),
          Text('$score', style: TextStyle(fontWeight: FontWeight.w800, color: color)),
        ]),
      );
}

class _Fact extends StatelessWidget {
  const _Fact(this.icon, this.text, {this.color});

  final IconData icon;
  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).hintColor;
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 15, color: c),
      const SizedBox(width: 4),
      Flexible(child: Text(text, style: TextStyle(fontSize: 12, color: c))),
    ]);
  }
}

/// Adoption par module : sociétés équipées et part qui s'en sert vraiment.
class _ModulesTable extends StatelessWidget {
  const _ModulesTable(this.modules);

  final List<Map<String, dynamic>> modules;

  @override
  Widget build(BuildContext context) {
    if (modules.isEmpty) return const SizedBox.shrink();
    final hint = Theme.of(context).hintColor;
    final sorted = [...modules]..sort((a, b) => ((b['used'] as num?) ?? 0).compareTo((a['used'] as num?) ?? 0));
    return SectionCard(
      title: tr('Utilisation par module'),
      trailing: Text(tr('Sociétés qui s\'en servent / sociétés qui l\'ont'), style: TextStyle(color: hint, fontSize: 12)),
      child: Column(children: [
        for (final m in sorted.take(40))
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: LayoutBuilder(builder: (context, c) {
              final rate = ((m['rate'] as num?) ?? 0).toDouble();
              final color = rate >= 50 ? YColors.success : (rate >= 20 ? YColors.warning : YColors.danger);
              final label = Text('${m['name']}', overflow: TextOverflow.ellipsis);
              final value = Text('${m['used']}/${m['enabled']} · ${rate.round()} %', style: TextStyle(fontSize: 12, color: hint));
              final bar = ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(value: rate / 100, minHeight: 8, color: color, backgroundColor: color.withValues(alpha: .12)),
              );
              return c.maxWidth < 520
                  ? Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Row(children: [Expanded(child: label), value]),
                      const SizedBox(height: 4),
                      bar,
                    ])
                  : Row(children: [
                      SizedBox(width: 220, child: label),
                      Expanded(child: bar),
                      SizedBox(width: 120, child: Align(alignment: Alignment.centerRight, child: value)),
                    ]);
            }),
          ),
      ]),
    );
  }
}
