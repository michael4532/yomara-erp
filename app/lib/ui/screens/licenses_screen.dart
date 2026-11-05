import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ---------------------------------------------------------------------------
// Présentation des installations (reliées au compte en ligne)
// ---------------------------------------------------------------------------

IconData licenseTargetIcon(String? t) => switch (t) {
  'server' => Icons.dns_outlined,
  'windows' => Icons.desktop_windows_outlined,
  'mac' => Icons.laptop_mac,
  'linux' => Icons.computer,
  _ => Icons.devices_other_outlined,
};

Color _targetColor(String? t) => switch (t) {
  'server' => const Color(0xFF4F46E5),
  'windows' => const Color(0xFF0284C7),
  'mac' => const Color(0xFF475569),
  'linux' => const Color(0xFFEA580C),
  _ => YColors.blue,
};

Color _statusColor(String? s) => switch (s) {
  'active' => YColors.success,
  'unused' => YColors.blue,
  'suspended' => YColors.warning,
  _ => YColors.danger,
};

IconData _statusIcon(String? s) => switch (s) {
  'active' => Icons.verified_rounded,
  'unused' => Icons.hourglass_empty_rounded,
  'suspended' => Icons.pause_circle_outline_rounded,
  _ => Icons.block_rounded,
};

IconData _eventIcon(String? t) => switch (t) {
  'created' => Icons.add_link_rounded,
  'activated' => Icons.rocket_launch_outlined,
  'refused' => Icons.gpp_bad_outlined,
  'reset' => Icons.link_off_rounded,
  'suspended' => Icons.pause_circle_outline_rounded,
  'reactivated' => Icons.play_circle_outline_rounded,
  'revoked' => Icons.block_rounded,
  'renamed' => Icons.edit_outlined,
  'module' => Icons.extension_outlined,
  _ => Icons.circle_outlined,
};

Color _eventColor(String? t) => switch (t) {
  'created' => YColors.blue,
  'activated' || 'reactivated' => YColors.success,
  'module' => const Color(0xFF7C3AED),
  'refused' || 'revoked' => YColors.danger,
  'reset' || 'suspended' => YColors.warning,
  _ => YColors.muted,
};

/// « il y a 3 h », « il y a 2 j »…
String _ago(dynamic v) {
  final d = DateTime.tryParse('${v ?? ''}')?.toLocal();
  if (d == null) return '—';
  final m = DateTime.now().difference(d).inMinutes;
  if (m < 1) return 'à l\'instant';
  if (m < 60) return 'il y a $m min';
  if (m < 1440) return 'il y a ${m ~/ 60} h';
  final days = m ~/ 1440;
  if (days < 30) return 'il y a $days j';
  return 'le ${dateFr(v)}';
}

int? _daysUntil(dynamic v) {
  final e = DateTime.tryParse('${v ?? ''}')?.toLocal();
  if (e == null) return null;
  final n = DateTime.now();
  return DateTime.utc(e.year, e.month, e.day).difference(DateTime.utc(n.year, n.month, n.day)).inDays;
}

List<Map<String, dynamic>> _list(Object? v) => [for (final x in (v is List ? v : const [])) asMap(x)];

const _mono = TextStyle(fontFamily: 'monospace', fontFamilyFallback: ['Courier New', 'Courier'], fontWeight: FontWeight.w700);

/// Libellés des évènements, formulés côté utilisateur (« installation » plutôt que « licence »).
const _eventLabels = {
  'created': 'Installation ajoutée au compte',
  'activated': 'Installation reliée',
  'refused': 'Liaison refusée (autre appareil)',
  'reset': 'Appareil libéré',
  'suspended': 'Installation suspendue',
  'reactivated': 'Installation réactivée',
  'revoked': 'Installation révoquée',
  'renamed': 'Installation modifiée',
  'module': 'Module activé',
};

Future<void> _copyId(BuildContext context, String id) async {
  await Clipboard.setData(ClipboardData(text: id));
  if (context.mounted) toast(context, 'Identifiant technique copié dans le presse-papiers');
}

Future<void> _openZip(BuildContext context, String url) async {
  final ok = await safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  if (!ok && context.mounted) toast(context, 'Impossible d\'ouvrir le lien de téléchargement', error: true);
}

// ---------------------------------------------------------------------------
// Écran « Mes installations »
// ---------------------------------------------------------------------------

/// Mes installations : les ERP installés gratuitement sur un serveur ou un PC et reliés à ce compte en ligne
/// (plateforme SaaS). Les modules s'achètent installation par installation.
class LicensesScreen extends StatefulWidget {
  const LicensesScreen({super.key, this.onBuy});

  /// Ouvre la boutique de modules pour l'installation choisie.
  final void Function(int licenseId)? onBuy;

  @override
  State<LicensesScreen> createState() => _LicensesScreenState();
}

class _LicensesScreenState extends State<LicensesScreen> {
  Map<String, dynamic>? _data;
  Object? _error;
  String _filter = 'all';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('licenses'));
      if (mounted) setState(() => (_data = r, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  String? get _zip {
    final z = _data?['server_zip'];
    return z is String && z.isNotEmpty ? z : null;
  }

  Future<void> _edit(Map<String, dynamic> l) async {
    final saved = await showFormDialog<bool>(
      context,
      title: 'Modifier l\'installation',
      subtitle: '${l['target_label'] ?? l['company_name'] ?? ''}',
      icon: Icons.edit_outlined,
      builder: (c) => _EditForm(license: l),
    );
    if (saved == true) {
      await _load();
      if (mounted) toast(context, 'Installation mise à jour');
    }
  }

  Future<void> _history(Map<String, dynamic> l) =>
      showDialog<void>(context: context, builder: (c) => _HistoryDialog(license: l));

  Future<void> _reset(Map<String, dynamic> l) async {
    final ok = await confirm(
      context,
      'Libérer l\'appareil ?',
      '« ${l['company_name']} » ne sera plus liée à ${l['device_name'] ?? 'l\'appareil actuel'}. Vous pourrez relier ce compte depuis un autre serveur ou ordinateur ; l\'ancien appareil perdra ses modules achetés à sa prochaine vérification (les fonctions gratuites restent actives).',
      ok: 'Libérer',
    );
    if (!ok || !mounted) return;
    await _act(() => context.read<Session>().api.post('licenses/${l['id']}/reset'), 'Appareil libéré : vous pouvez relier un autre appareil');
  }

  Future<void> _revoke(Map<String, dynamic> l) async {
    final ok = await confirm(
      context,
      'Révoquer l\'installation ?',
      'L\'installation « ${l['company_name']} » perdra définitivement ses modules achetés à sa prochaine vérification (les fonctions gratuites restent actives). Cette action est définitive.',
      ok: 'Révoquer',
      danger: true,
    );
    if (!ok || !mounted) return;
    await _act(() => context.read<Session>().api.post('licenses/${l['id']}/revoke'), 'Installation révoquée');
  }

  Future<void> _act(Future<dynamic> Function() call, String done) async {
    try {
      await call();
      await _load();
      if (mounted) toast(context, done);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  /// « Acheter des modules » depuis « Comment ça marche » : directement s'il n'y a qu'une installation, sinon on la choisit.
  Future<void> _buyFromSteps() async {
    final onBuy = widget.onBuy;
    if (onBuy == null) return;
    final usable = [for (final l in _list(_data?['data'])) if (l['status'] != 'revoked' && l['id'] is int) l];
    if (usable.isEmpty) {
      toast(context, 'Reliez d\'abord une installation à ce compte (étape 2), puis achetez ses modules.', error: true);
      return;
    }
    if (usable.length == 1) return onBuy(usable.first['id'] as int);
    final id = await showDialog<int>(
      context: context,
      builder: (c) => SimpleDialog(
        title: Text(tr('Acheter pour quelle installation ?')),
        children: [
          for (final l in usable)
            SimpleDialogOption(
              onPressed: () => Navigator.pop(c, l['id'] as int),
              child: Row(children: [
                Icon(licenseTargetIcon('${l['target']}'), size: 20, color: _targetColor('${l['target']}')),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${l['company_name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text(tr('${l['target_label'] ?? l['target'] ?? ''}'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12.5)),
                  ]),
                ),
              ]),
            ),
        ],
      ),
    );
    if (id != null) onBuy(id);
  }

  bool _match(Map<String, dynamic> l) => switch (_filter) {
    'online' => l['online'] == true,
    'active' => l['status'] == 'active',
    'unused' => l['status'] == 'unused',
    'revoked' => l['status'] == 'revoked' || l['status'] == 'suspended',
    _ => true,
  };

  @override
  Widget build(BuildContext context) {
    if (_error != null && _data == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_data == null) return const LoadingSkeleton(lines: 4);
    final d = _data!;
    final all = _list(d['data']);
    final stats = asMap(d['stats']);
    final canManage = d['can_manage'] == true;
    final width = MediaQuery.sizeOf(context).width;
    final pad = width < 700 ? 16.0 : 24.0;
    final shown = all.where(_match).toList();
    int count(String f) => all.where((l) => switch (f) {
      'online' => l['online'] == true,
      'active' => l['status'] == 'active',
      'unused' => l['status'] == 'unused',
      'revoked' => l['status'] == 'revoked' || l['status'] == 'suspended',
      _ => true,
    }).length;
    final filters = [('all', 'Toutes'), ('online', 'En ligne'), ('active', 'Reliées'), ('unused', 'En attente'), ('revoked', 'Suspendues / révoquées')];

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: EdgeInsets.fromLTRB(pad, pad, pad, 40),
        children: [
          AnimatedFadeIn(child: _Hero(stats: stats, canManage: canManage, zip: _zip)),
          const SizedBox(height: 18),
          AnimatedFadeIn(index: 1, child: _HowItWorks(zip: _zip, onBuy: widget.onBuy == null ? null : _buyFromSteps)),
          const SizedBox(height: 26),
          if (all.isEmpty)
            AnimatedFadeIn(
              index: 2,
              child: Card(
                child: EmptyState(
                  icon: Icons.add_link_rounded,
                  title: 'Aucune installation reliée pour le moment',
                  message: 'Installez l\'ERP gratuitement sur votre serveur ou votre PC, ouvrez « Compte Yomara & modules » dans l\'installation, '
                      'puis cliquez « Relier à mon compte Yomara » avec l\'e-mail et le mot de passe de ce compte. L\'installation apparaîtra ici et vous pourrez lui acheter des modules.',
                  action: _zip == null
                      ? null
                      : FilledButton.icon(onPressed: () => _openZip(context, _zip!), icon: const Icon(Icons.download_rounded), label: Text(tr('Télécharger l\'ERP'))),
                ),
              ),
            )
          else ...[
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 10,
              runSpacing: 6,
              children: [
                Text(tr('Vos installations'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
                Text(tr('${all.length} installation(s) · vérification automatique toutes les 6 h'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
              ],
            ),
            const SizedBox(height: 12),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final f in filters)
                    if (f.$1 == 'all' || count(f.$1) > 0)
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: ChoiceChip(
                          label: Text(tr('${f.$2} (${count(f.$1)})')),
                          selected: _filter == f.$1,
                          onSelected: (_) => setState(() => _filter = f.$1),
                        ),
                      ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            if (shown.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 30),
                child: Center(child: Text(tr('Aucune installation dans ce filtre.'), style: TextStyle(color: Theme.of(context).hintColor))),
              )
            else
              LayoutBuilder(
                builder: (context, box) {
                  final cols = box.maxWidth > 1180 ? 3 : (box.maxWidth > 720 ? 2 : 1);
                  final w = (box.maxWidth - (cols - 1) * 16) / cols;
                  return Wrap(
                    spacing: 16,
                    runSpacing: 16,
                    children: [
                      for (final (i, l) in shown.indexed)
                        SizedBox(
                          key: ValueKey('lic-${l['id']}'),
                          width: w,
                          child: AnimatedFadeIn(
                            index: i + 2,
                            child: HoverLift(
                              child: _LicenseCard(
                                license: l,
                                canManage: canManage,
                                onBuy: widget.onBuy == null || l['status'] == 'revoked' ? null : () => widget.onBuy!(l['id'] as int),
                                onEdit: () => _edit(l),
                                onHistory: () => _history(l),
                                onReset: () => _reset(l),
                                onRevoke: () => _revoke(l),
                              ),
                            ),
                          ),
                        ),
                    ],
                  );
                },
              ),
          ],
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// En-tête : concept, indicateurs et bouton principal
// ---------------------------------------------------------------------------

class _Hero extends StatefulWidget {
  const _Hero({required this.stats, required this.canManage, required this.zip});

  final Map<String, dynamic> stats;
  final bool canManage;
  final String? zip;

  @override
  State<_Hero> createState() => _HeroState();
}

class _HeroState extends State<_Hero> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(seconds: 9))..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.stats;
    final still = reduceMotion(context);
    final tiles = [
      ('Installations', toDouble(s['total']), Icons.devices_other_outlined, const Color(0xFFFFC531), false),
      ('Reliées', toDouble(s['active']), Icons.verified_outlined, const Color(0xFF34D399), false),
      ('En ligne', toDouble(s['online']), Icons.wifi_tethering_rounded, const Color(0xFF34D399), true),
      ('En attente', toDouble(s['unused']), Icons.hourglass_empty_rounded, const Color(0xFF93C5FD), false),
      ('Modules actifs', toDouble(s['modules']), Icons.extension_outlined, const Color(0xFFC4B5FD), false),
    ];

    final zip = widget.zip;
    final Widget button = zip == null
        ? const SizedBox.shrink()
        : FilledButton.icon(
      onPressed: () => _openZip(context, zip),
      style: FilledButton.styleFrom(
        backgroundColor: YColors.yellow,
        foregroundColor: YColors.night,
        disabledBackgroundColor: Colors.white.withValues(alpha: .12),
        disabledForegroundColor: Colors.white54,
        padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 18),
        textStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w800, fontSize: 15),
        elevation: 0,
      ),
      icon: const Icon(Icons.download_rounded),
      label: Text(tr('Télécharger l\'ERP')),
    );

    return ClipRRect(
      borderRadius: BorderRadius.circular(26),
      child: AnimatedBuilder(
        animation: _c,
        builder: (context, child) {
          final t = still ? .5 : Curves.easeInOut.transform(_c.value);
          return Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: const [Color(0xFF0B1B3A), Color(0xFF16357A), Color(0xFF4C3BCF)],
                begin: Alignment(-1 + t * .4, -1),
                end: Alignment(1, 1 - t * .4),
              ),
            ),
            child: Stack(
              children: [
                // Halos décoratifs qui dérivent lentement
                Positioned(
                  right: -60 + 40 * t,
                  top: -80 + 30 * t,
                  child: _Glow(size: 260, color: const Color(0xFF5B8CFF).withValues(alpha: .35)),
                ),
                Positioned(
                  left: -70 + 30 * (1 - t),
                  bottom: -110 + 40 * t,
                  child: _Glow(size: 240, color: const Color(0xFFFFC531).withValues(alpha: .16)),
                ),
                Positioned(
                  right: 30,
                  bottom: -30,
                  child: Transform.rotate(
                    angle: -math.pi / 9 + t * .15,
                    child: Icon(Icons.hub_outlined, size: 170, color: Colors.white.withValues(alpha: .05)),
                  ),
                ),
                child!,
              ],
            ),
          );
        },
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: LayoutBuilder(
            builder: (context, box) {
              final narrow = box.maxWidth < 680;
              final intro = Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: .10),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: Colors.white.withValues(alpha: .14)),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.cloud_download_outlined, size: 14, color: YColors.yellow),
                        const SizedBox(width: 6),
                        Text(tr('Auto-hébergement'), style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: .3)),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    tr('Mes installations'),
                    style: TextStyle(color: Colors.white, fontSize: narrow ? 26 : 32, fontWeight: FontWeight.w900, letterSpacing: -.8, height: 1.1),
                  ),
                  const SizedBox(height: 8),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 620),
                    child: Text(
                      tr(
                        'L\'ERP est gratuit : installez-le sur votre propre serveur ou PC, sans clé. Reliez ensuite chaque installation à ce compte pour lui acheter des modules ; elle les reçoit automatiquement.',
                      ),
                      style: const TextStyle(color: Color(0xFFC9D6F5), fontSize: 14.5, height: 1.5),
                    ),
                  ),
                ],
              );
              final kpiCols = box.maxWidth > 900 ? 5 : (box.maxWidth > 520 ? 3 : 2);
              final kw = (box.maxWidth - (kpiCols - 1) * 10) / kpiCols;
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (narrow) ...[intro, const SizedBox(height: 16), button]
                  else
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [Expanded(child: intro), const SizedBox(width: 20), button],
                    ),
                  const SizedBox(height: 22),
                  Wrap(
                    spacing: 10,
                    runSpacing: 10,
                    children: [
                      for (final (i, k) in tiles.indexed)
                        SizedBox(
                          width: kw,
                          child: AnimatedFadeIn(
                            index: i + 1,
                            child: Container(
                              padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                              decoration: BoxDecoration(
                                color: Colors.white.withValues(alpha: .08),
                                borderRadius: BorderRadius.circular(16),
                                border: Border.all(color: Colors.white.withValues(alpha: .12)),
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      if (k.$5 && k.$2 > 0) _PulseDot(color: k.$4, size: 9) else Icon(k.$3, size: 16, color: k.$4),
                                      const SizedBox(width: 6),
                                      Expanded(
                                        child: Text(tr(k.$1), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Color(0xFFBFD0F5), fontSize: 12.5, fontWeight: FontWeight.w600)),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(height: 6),
                                  CountUp(
                                    value: k.$2,
                                    format: (v) => number(v.round()),
                                    style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w900, height: 1.1),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                  if (!widget.canManage) ...[
                    const SizedBox(height: 12),
                    Text(
                      tr('Seuls les administrateurs du compte peuvent gérer les installations.'),
                      style: const TextStyle(color: Color(0xFFBFD0F5), fontSize: 12.5),
                    ),
                  ],
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _Glow extends StatelessWidget {
  const _Glow({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) => IgnorePointer(
    child: Container(
      width: size,
      height: size,
      decoration: BoxDecoration(shape: BoxShape.circle, gradient: RadialGradient(colors: [color, color.withValues(alpha: 0)])),
    ),
  );
}

/// Point vert qui pulse (installation en ligne).
class _PulseDot extends StatefulWidget {
  const _PulseDot({this.color = const Color(0xFF22C55E), this.size = 10});

  final Color color;
  final double size;

  @override
  State<_PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<_PulseDot> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1500))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.size;
    final dot = Container(width: s, height: s, decoration: BoxDecoration(shape: BoxShape.circle, color: widget.color));
    if (reduceMotion(context)) return SizedBox(width: s * 2.2, height: s * 2.2, child: Center(child: dot));
    return SizedBox(
      width: s * 2.2,
      height: s * 2.2,
      child: AnimatedBuilder(
        animation: _c,
        builder: (_, _) => Stack(
          alignment: Alignment.center,
          children: [
            Container(
              width: s + s * 1.2 * _c.value,
              height: s + s * 1.2 * _c.value,
              decoration: BoxDecoration(shape: BoxShape.circle, color: widget.color.withValues(alpha: .45 * (1 - _c.value))),
            ),
            dot,
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Comment ça marche
// ---------------------------------------------------------------------------

class _HowItWorks extends StatelessWidget {
  const _HowItWorks({required this.zip, this.onBuy});

  final String? zip;
  final VoidCallback? onBuy;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final steps = [
      (
        Icons.cloud_download_outlined,
        'Téléchargez et installez gratuitement',
        'Sur votre hébergement, VPS ou PC : aucune clé n\'est demandée et les fonctions de base sont tout de suite actives.',
        zip != null
            ? OutlinedButton.icon(
                onPressed: () => _openZip(context, zip!),
                icon: const Icon(Icons.download_rounded, size: 18),
                label: Text(tr('Télécharger')),
                style: OutlinedButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10)),
              )
            : Text(tr('Lien bientôt disponible'), style: TextStyle(color: hint, fontStyle: FontStyle.italic, fontSize: 12.5)),
      ),
      (
        Icons.add_link_rounded,
        'Reliez l\'installation à ce compte',
        'Dans l\'installation, ouvrez « Compte Yomara & modules » et cliquez « Relier à mon compte Yomara » avec l\'e-mail et le mot de passe de ce compte.',
        null as Widget?,
      ),
      (
        Icons.extension_outlined,
        'Achetez les modules pour cette installation',
        'Choisissez-la dans « Acheter pour » : elle reçoit ses modules à sa prochaine vérification (6 h au plus) ou tout de suite avec « Vérifier maintenant ».',
        onBuy == null
            ? null
            : FilledButton.tonalIcon(
                onPressed: onBuy,
                icon: const Icon(Icons.add_shopping_cart_rounded, size: 18),
                label: Text(tr('Acheter des modules')),
                style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10)),
              ),
      ),
    ];

    return SectionCard(
      title: 'Comment ça marche',
      trailing: Icon(Icons.auto_awesome_rounded, size: 18, color: theme.colorScheme.primary),
      child: LayoutBuilder(
        builder: (context, box) {
          final row = box.maxWidth > 760;
          final items = [
            for (final (i, s) in steps.indexed)
              _Step(n: i + 1, icon: s.$1, title: s.$2, text: s.$3, action: s.$4, index: i),
          ];
          if (!row) {
            return Column(
              children: [
                for (final (i, w) in items.indexed) ...[
                  w,
                  if (i < items.length - 1)
                    Padding(
                      padding: const EdgeInsets.only(left: 21),
                      child: Align(alignment: Alignment.centerLeft, child: Container(width: 2, height: 16, color: theme.colorScheme.primary.withValues(alpha: .18))),
                    ),
                ],
              ],
            );
          }
          return IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final (i, w) in items.indexed) ...[
                  Expanded(child: w),
                  if (i < items.length - 1)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 6),
                      child: Center(child: Icon(Icons.arrow_forward_rounded, color: theme.colorScheme.primary.withValues(alpha: .4))),
                    ),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({required this.n, required this.icon, required this.title, required this.text, this.action, this.index = 0});

  final int n;
  final IconData icon;
  final String title;
  final String text;
  final Widget? action;
  final int index;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final c = [YColors.blue, const Color(0xFF7C3AED), YColors.success][(n - 1) % 3];
    return AnimatedFadeIn(
      index: index + 2,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: LinearGradient(colors: [c.withValues(alpha: dark ? .16 : .08), c.withValues(alpha: 0)], begin: Alignment.topLeft, end: Alignment.bottomRight),
          border: Border.all(color: c.withValues(alpha: .16)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(14),
                gradient: LinearGradient(colors: [c, Color.lerp(c, Colors.black, .25)!], begin: Alignment.topLeft, end: Alignment.bottomRight),
                boxShadow: [BoxShadow(color: c.withValues(alpha: .3), blurRadius: 12, offset: const Offset(0, 4))],
              ),
              child: Stack(
                children: [
                  Center(child: Icon(icon, color: Colors.white, size: 21)),
                  Positioned(
                    right: 3,
                    top: 2,
                    child: Text('$n', style: const TextStyle(color: Colors.white70, fontSize: 10, fontWeight: FontWeight.w900)),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(tr('$n. $title'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5)),
                  const SizedBox(height: 3),
                  Text(tr(text), style: TextStyle(color: theme.hintColor, fontSize: 12.8, height: 1.4)),
                  if (action != null) ...[const SizedBox(height: 10), action!],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Carte d'une installation
// ---------------------------------------------------------------------------

class _LicenseCard extends StatelessWidget {
  const _LicenseCard({
    required this.license,
    required this.canManage,
    required this.onBuy,
    required this.onEdit,
    required this.onHistory,
    required this.onReset,
    required this.onRevoke,
  });

  final Map<String, dynamic> license;
  final bool canManage;
  final VoidCallback? onBuy;
  final VoidCallback onEdit;
  final VoidCallback onHistory;
  final VoidCallback onReset;
  final VoidCallback onRevoke;

  @override
  Widget build(BuildContext context) {
    final l = license;
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final hint = theme.hintColor;
    final status = '${l['status']}';
    final revoked = status == 'revoked';
    final online = l['online'] == true;
    final tc = _targetColor('${l['target']}');
    final sc = _statusColor(status);
    final mods = _list(l['modules']);
    final expiry = _daysUntil(l['next_expiry']);
    final expColor = expiry == null ? hint : (expiry <= 3 ? YColors.danger : (expiry <= 14 ? YColors.warning : YColors.success));
    final installed = '${l['installed_company'] ?? ''}';

    final presence = Row(
      children: [
        if (online)
          const _PulseDot(size: 9)
        else
          SizedBox(
            width: 19.8,
            height: 19.8,
            child: Center(
              child: Container(width: 9, height: 9, decoration: BoxDecoration(shape: BoxShape.circle, color: status == 'unused' ? YColors.blue.withValues(alpha: .5) : Colors.grey)),
            ),
          ),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            tr(switch (status) {
              'unused' => 'En attente de liaison',
              'revoked' => 'Installation révoquée : modules bloqués',
              'suspended' => 'Installation suspendue',
              _ => online ? 'En ligne · dernier contact ${_ago(l['last_seen_at'])}' : 'Hors ligne · vu ${_ago(l['last_seen_at'])}',
            }),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: 12.8,
              fontWeight: FontWeight.w600,
              color: online ? const Color(0xFF16A34A) : (revoked ? YColors.danger : hint),
            ),
          ),
        ),
      ],
    );

    Widget info(IconData icon, String label, String value) => Row(
      children: [
        Container(
          padding: const EdgeInsets.all(6),
          decoration: BoxDecoration(color: theme.colorScheme.onSurface.withValues(alpha: .05), borderRadius: BorderRadius.circular(9)),
          child: Icon(icon, size: 15, color: hint),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(tr(label), style: TextStyle(color: hint, fontSize: 11.5)),
              Text(value, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            ],
          ),
        ),
      ],
    );

    final menu = PopupMenuButton<String>(
      tooltip: tr('Plus d\'actions'),
      icon: const Icon(Icons.more_horiz_rounded),
      onSelected: (v) => switch (v) {
        'edit' => onEdit(),
        'history' => onHistory(),
        'reset' => onReset(),
        'revoke' => onRevoke(),
        _ => null,
      },
      itemBuilder: (c) => [
        PopupMenuItem(value: 'history', child: _MenuRow(Icons.timeline_rounded, 'Historique et détails')),
        if (canManage && !revoked) PopupMenuItem(value: 'edit', child: _MenuRow(Icons.edit_outlined, 'Modifier')),
        if (canManage && !revoked && l['device_name'] != null) PopupMenuItem(value: 'reset', child: _MenuRow(Icons.link_off_rounded, 'Libérer l\'appareil')),
        if (canManage && !revoked) ...[
          const PopupMenuDivider(),
          PopupMenuItem(value: 'revoke', child: _MenuRow(Icons.block_rounded, 'Révoquer', color: YColors.danger)),
        ],
      ],
    );

    return Opacity(
      opacity: revoked ? .62 : 1,
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Bandeau supérieur teinté
            Container(
              padding: const EdgeInsets.fromLTRB(16, 16, 12, 14),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [tc.withValues(alpha: dark ? .22 : .10), tc.withValues(alpha: 0)], begin: Alignment.topLeft, end: Alignment.bottomRight),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Stack(
                    clipBehavior: Clip.none,
                    children: [
                      Container(
                        width: 48,
                        height: 48,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(15),
                          gradient: LinearGradient(colors: [tc, Color.lerp(tc, Colors.black, .3)!], begin: Alignment.topLeft, end: Alignment.bottomRight),
                          boxShadow: [BoxShadow(color: tc.withValues(alpha: .3), blurRadius: 14, offset: const Offset(0, 5))],
                        ),
                        child: Icon(licenseTargetIcon('${l['target']}'), color: Colors.white, size: 24),
                      ),
                      if (online)
                        Positioned(
                          right: -3,
                          bottom: -3,
                          child: Container(
                            width: 15,
                            height: 15,
                            decoration: BoxDecoration(shape: BoxShape.circle, color: const Color(0xFF22C55E), border: Border.all(color: theme.colorScheme.surface, width: 2.5)),
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${l['company_name'] ?? ''}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16.5, letterSpacing: -.2)),
                        const SizedBox(height: 2),
                        Text(
                          '${tr('Installation')} · ${tr('${l['target_label'] ?? l['target']}')}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(color: hint, fontSize: 12.5),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  StatusChip('${l['status_label'] ?? status}', color: sc, icon: _statusIcon(status)),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  presence,
                  if (installed.isNotEmpty && installed != '${l['company_name']}') ...[
                    const SizedBox(height: 2),
                    Padding(
                      padding: const EdgeInsets.only(left: 26),
                      child: Text(tr('Installée sous le nom « $installed »'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12)),
                    ),
                  ],
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(child: info(Icons.memory_rounded, 'Appareil', '${l['device_name'] ?? '—'}')),
                      const SizedBox(width: 10),
                      Expanded(child: info(Icons.schedule_rounded, 'Dernier contact', tr(l['last_seen_at'] == null ? 'jamais' : _ago(l['last_seen_at'])))),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(child: info(Icons.new_releases_outlined, 'Version', l['app_version'] == null ? '—' : 'v${l['app_version']}')),
                      const SizedBox(width: 10),
                      Expanded(child: info(Icons.group_outlined, 'Utilisateurs', l['users_count'] == null ? '—' : '${l['users_count']}')),
                    ],
                  ),
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      Text(tr('Modules'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
                        decoration: BoxDecoration(color: const Color(0xFF7C3AED).withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
                        child: Text('${l['modules_count'] ?? mods.length}', style: const TextStyle(color: Color(0xFF7C3AED), fontWeight: FontWeight.w800, fontSize: 11.5)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  if (mods.isEmpty)
                    Text(tr('Socle seul pour l\'instant : ajoutez des modules depuis la boutique.'), style: TextStyle(color: hint, fontSize: 12.5))
                  else
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        for (final m in mods.take(5))
                          Tooltip(
                            message: m['expires_at'] == null ? tr('Sans échéance') : tr('Jusqu\'au ${dateFr(m['expires_at'])}'),
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                              decoration: BoxDecoration(
                                color: const Color(0xFF7C3AED).withValues(alpha: dark ? .2 : .08),
                                borderRadius: BorderRadius.circular(20),
                                border: Border.all(color: const Color(0xFF7C3AED).withValues(alpha: .18)),
                              ),
                              child: Text(tr('${m['name'] ?? m['key']}'), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: dark ? const Color(0xFFD8CCFF) : const Color(0xFF5B21B6))),
                            ),
                          ),
                        if (mods.length > 5)
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                            decoration: BoxDecoration(color: theme.colorScheme.onSurface.withValues(alpha: .06), borderRadius: BorderRadius.circular(20)),
                            child: Text('+${mods.length - 5}', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
                          ),
                      ],
                    ),
                  if (expiry != null) ...[
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        Icon(Icons.event_rounded, size: 15, color: expColor),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            tr(
                              'Prochaine échéance : ${dateFr(l['next_expiry'])} · ${expiry < 0 ? 'expirée' : (expiry == 0 ? 'aujourd\'hui' : 'dans $expiry j')}',
                            ),
                            style: TextStyle(color: expColor, fontSize: 12.5, fontWeight: FontWeight.w600),
                          ),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
            Divider(height: 1, color: dark ? Colors.white10 : const Color(0xFFEDF1F7)),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
              child: Row(
                children: [
                  Expanded(
                    child: onBuy == null
                        ? Text(tr(revoked ? 'Installation révoquée' : ''), style: TextStyle(color: hint, fontSize: 12.5))
                        : Align(
                            alignment: Alignment.centerLeft,
                            child: FilledButton.tonalIcon(
                              onPressed: onBuy,
                              style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10)),
                              icon: const Icon(Icons.add_shopping_cart_rounded, size: 18),
                              label: Text(tr('Acheter des modules'), overflow: TextOverflow.ellipsis),
                            ),
                          ),
                  ),
                  IconButton(tooltip: tr('Historique et détails'), onPressed: onHistory, icon: const Icon(Icons.timeline_rounded, size: 20)),
                  menu,
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _MenuRow extends StatelessWidget {
  const _MenuRow(this.icon, this.label, {this.color});

  final IconData icon;
  final String label;
  final Color? color;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Icon(icon, size: 18, color: color),
      const SizedBox(width: 10),
      Text(tr(label), style: TextStyle(color: color, fontWeight: color == null ? null : FontWeight.w600)),
    ],
  );
}

// ---------------------------------------------------------------------------
// Modification
// ---------------------------------------------------------------------------

class _EditForm extends StatefulWidget {
  const _EditForm({required this.license});

  final Map<String, dynamic> license;

  @override
  State<_EditForm> createState() => _EditFormState();
}

class _EditFormState extends State<_EditForm> {
  late final _name = TextEditingController(text: '${widget.license['company_name'] ?? ''}');
  late final _notes = TextEditingController(text: '${widget.license['notes'] ?? ''}');
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) {
      toast(context, 'Indiquez le nom de l\'entreprise', error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('licenses/${widget.license['id']}', {
        'company_name': _name.text.trim(),
        'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    mainAxisSize: MainAxisSize.min,
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Flexible(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 18, 24, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextField(
                controller: _name,
                autofocus: true,
                decoration: InputDecoration(labelText: tr('Nom de l\'entreprise'), prefixIcon: const Icon(Icons.business_outlined)),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _notes,
                maxLines: 4,
                minLines: 2,
                decoration: InputDecoration(labelText: tr('Notes'), alignLabelWithHint: true),
              ),
            ],
          ),
        ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(24, 6, 24, 20),
        child: OverflowBar(
          alignment: MainAxisAlignment.end,
          spacing: 10,
          children: [
            TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: Text(tr('Annuler'))),
            FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    ],
  );
}

// ---------------------------------------------------------------------------
// Historique et détails de l'installation
// ---------------------------------------------------------------------------

class _HistoryDialog extends StatefulWidget {
  const _HistoryDialog({required this.license});

  final Map<String, dynamic> license;

  @override
  State<_HistoryDialog> createState() => _HistoryDialogState();
}

class _HistoryDialogState extends State<_HistoryDialog> {
  late final Future<Map<String, dynamic>> _future = context
      .read<Session>()
      .api
      .get('licenses/${widget.license['id']}')
      .then((r) => asMap(asMap(r)['data']));

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final width = math.min(580.0, MediaQuery.sizeOf(context).width - 76);
    final currency = context.read<Session>().currency;
    return YAlertDialog(
      icon: const Icon(Icons.timeline_rounded),
      title: Text(tr('Historique de l\'installation')),
      subtitle: '${widget.license['company_name'] ?? ''}',
      content: SizedBox(
        width: width,
        height: math.min(560.0, MediaQuery.sizeOf(context).height - 260),
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snap) {
            if (snap.hasError) return Center(child: Text(errorText(snap.error!), textAlign: TextAlign.center));
            if (!snap.hasData) return const Center(child: CircularProgressIndicator());
            final l = snap.data!;
            final dev = asMap(l['device']);
            final events = _list(l['events']);
            final facts = [
              ('Reliée le', l['activated_at'] == null ? '—' : dateTimeFr(l['activated_at'])),
              ('Ajoutée le', dateTimeFr(l['created_at'])),
              ('Appareil libéré', '${l['resets'] ?? 0} fois'),
              ('Investi en modules', money(toDouble(l['revenue']), currency)),
              if (l['last_ip'] != null) ('Dernière IP', '${l['last_ip']}'),
              if (l['install_url'] != null) ('Adresse', '${l['install_url']}'),
            ];
            final devBits = [
              for (final k in ['os', 'os_version', 'php', 'db', 'host', 'arch'])
                if ((dev[k] ?? '').toString().isNotEmpty) '${{'os': 'Système', 'os_version': 'Version', 'php': 'PHP', 'db': 'Base', 'host': 'Hôte', 'arch': 'Architecture'}[k]} : ${dev[k]}',
            ];
            final techId = '${l['key'] ?? widget.license['key'] ?? l['masked_key'] ?? widget.license['masked_key'] ?? ''}';
            return ListView(
              children: [
                if (techId.isNotEmpty) ...[
                  Container(
                    padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
                    decoration: BoxDecoration(color: theme.colorScheme.onSurface.withValues(alpha: .04), borderRadius: BorderRadius.circular(12)),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(tr('Identifiant technique (pour le support)'), style: TextStyle(color: hint, fontSize: 11.5)),
                              SelectableText(techId, style: _mono.copyWith(fontSize: 13, letterSpacing: .5)),
                            ],
                          ),
                        ),
                        IconButton(tooltip: tr('Copier'), onPressed: () => _copyId(context, techId), icon: const Icon(Icons.copy_rounded, size: 18)),
                      ],
                    ),
                  ),
                  const SizedBox(height: 8),
                ],
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final f in facts)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(color: theme.colorScheme.onSurface.withValues(alpha: .04), borderRadius: BorderRadius.circular(12)),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(tr(f.$1), style: TextStyle(color: hint, fontSize: 11.5)),
                            ConstrainedBox(
                              constraints: BoxConstraints(maxWidth: width - 40),
                              child: Text(f.$2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
                if (devBits.isNotEmpty) ...[
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      Icon(Icons.memory_rounded, size: 16, color: hint),
                      const SizedBox(width: 6),
                      Text(tr('Appareil : ${l['device_name'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Wrap(spacing: 6, runSpacing: 6, children: [for (final b in devBits) StatusChip(b, color: YColors.blue)]),
                ],
                const SizedBox(height: 18),
                Text(tr('Chronologie'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                const SizedBox(height: 10),
                if (events.isEmpty)
                  Text(tr('Aucun évènement enregistré.'), style: TextStyle(color: hint))
                else
                  for (final (i, e) in events.indexed)
                    AnimatedFadeIn(
                      index: i,
                      child: IntrinsicHeight(
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            SizedBox(
                              width: 34,
                              child: Column(
                                children: [
                                  Container(
                                    width: 30,
                                    height: 30,
                                    decoration: BoxDecoration(shape: BoxShape.circle, color: _eventColor('${e['type']}').withValues(alpha: .14)),
                                    child: Icon(_eventIcon('${e['type']}'), size: 16, color: _eventColor('${e['type']}')),
                                  ),
                                  if (i < events.length - 1)
                                    Expanded(child: Container(width: 2, margin: const EdgeInsets.symmetric(vertical: 2), color: theme.dividerColor)),
                                ],
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Padding(
                                padding: const EdgeInsets.only(bottom: 14, top: 4),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(tr(_eventLabels['${e['type']}'] ?? '${e['label'] ?? e['type']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                                    Text(
                                      [
                                        '${dateTimeFr(e['at'])} (${tr(_ago(e['at']))})',
                                        if (e['ip'] != null) 'IP ${e['ip']}',
                                        ..._eventDetails(e['data']),
                                      ].join(' · '),
                                      style: TextStyle(color: hint, fontSize: 12.5),
                                    ),
                                  ],
                                ),
                              ),
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
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

/// Quelques détails lisibles d'un évènement (appareil, module, nouveau nom…).
List<String> _eventDetails(Object? data) {
  final d = asMap(data);
  final out = <String>[];
  for (final k in ['device', 'name', 'module', 'company_name', 'by']) {
    final v = d[k];
    if (v is String && v.isNotEmpty) out.add(v);
    if (v is Map && v['name'] is String) out.add('${v['name']}');
  }
  return out;
}
