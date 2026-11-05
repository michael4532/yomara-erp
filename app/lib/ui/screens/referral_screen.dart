import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ---------------------------------------------------------------------------
// Outils
// ---------------------------------------------------------------------------

List<Map<String, dynamic>> _list(Object? v) => [for (final x in (v is List ? v : const [])) asMap(x)];

/// 10 → « 10 », 7.5 → « 7,5 ».
String _pct(Object? v) {
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.round()}' : d.toStringAsFixed(1).replaceAll('.', ',');
}

const _mono = TextStyle(fontFamily: 'monospace', fontFamilyFallback: ['Courier New', 'Courier'], fontWeight: FontWeight.w800);

/// Phrase qui résume l'offre avec les réglages en vigueur.
String referralDeal(Map<String, dynamic> s) {
  final discount = toDouble(s['referee_discount']);
  final reward = toDouble(s['reward_percent']);
  final months = toDouble(s['reward_months']).round();
  final guests = discount > 0 ? 'Vos invités ont −${_pct(discount)} % sur leur premier achat ; ' : 'Vos invités s\'inscrivent avec votre lien ; ';
  final period = months > 0 ? 'pendant $months mois' : 'sans limite de durée';
  return tr('$guests'
      'vous gagnez ${_pct(reward)} % de chacun de leurs achats $period, en crédit sur vos achats.');
}

// ---------------------------------------------------------------------------
// Écran « Parrainage »
// ---------------------------------------------------------------------------

/// Parrainage (plateforme SaaS) : partager son lien, suivre ses filleuls et son crédit.
class ReferralScreen extends StatefulWidget {
  const ReferralScreen({super.key});

  @override
  State<ReferralScreen> createState() => _ReferralScreenState();
}

class _ReferralScreenState extends State<ReferralScreen> {
  Map<String, dynamic>? _data;
  Object? _error;
  final _message = TextEditingController();
  bool _messageEdited = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('referral'));
      if (!mounted) return;
      setState(() => (_data = r, _error = null));
      if (!_messageEdited) _message.text = _defaultMessage(r);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  String _defaultMessage(Map<String, dynamic> d) {
    final s = asMap(d['settings']);
    final discount = toDouble(s['referee_discount']);
    final link = '${d['link'] ?? ''}';
    return discount > 0
        ? tr('Je gère mon entreprise avec Yomara ERP : ventes, stock, compta, RH… tout au même endroit. Inscrivez-vous avec mon lien et profitez de −${_pct(discount)} % sur votre premier achat : $link')
        : tr('Je gère mon entreprise avec Yomara ERP : ventes, stock, compta, RH… tout au même endroit. Essayez-le avec mon lien : $link');
  }

  void _resetMessage() {
    final d = _data;
    if (d == null) return;
    setState(() {
      _message.text = _defaultMessage(d);
      _messageEdited = false;
    });
  }

  /// Le message à envoyer contient toujours le lien.
  String get _text {
    final link = '${_data?['link'] ?? ''}';
    final t = _message.text.trim();
    if (t.isEmpty) return link;
    return link.isEmpty || t.contains(link) ? t : '$t\n$link';
  }

  Future<void> _copy(String text, String done) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (mounted) toast(context, tr(done));
  }

  Future<void> _open(String url) async {
    var ok = false;
    try {
      ok = await safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    } catch (_) {
      ok = false;
    }
    if (!ok && mounted) toast(context, tr('Impossible d\'ouvrir l\'application de partage. Copiez le message à la place.'), error: true);
  }

  void _share(String channel) {
    final link = '${_data?['link'] ?? ''}';
    final text = _text;
    final enc = Uri.encodeComponent;
    switch (channel) {
      case 'whatsapp':
        _open('https://wa.me/?text=${enc(text)}');
      case 'email':
        _open('mailto:?subject=${enc(tr('Je vous invite sur Yomara ERP'))}&body=${enc(text)}');
      case 'sms':
        _open('sms:?body=${enc(text)}');
      case 'facebook':
        _open('https://www.facebook.com/sharer/sharer.php?u=${enc(link)}');
      case 'linkedin':
        _open('https://www.linkedin.com/sharing/share-offsite/?url=${enc(link)}');
    }
  }

  @override
  Widget build(BuildContext context) {
    final err = _error;
    if (err != null && _data == null) {
      if (err is ApiException && err.status == 404) return const _NotAvailable();
      return OnlineOnlyNotice(error: err, onRetry: _load);
    }
    if (_data == null) return const LoadingSkeleton(lines: 4);
    final d = _data!;
    final settings = asMap(d['settings']);
    final enabled = settings['enabled'] != false;
    final referees = _list(d['list']);
    final history = _list(d['history']);
    final width = MediaQuery.sizeOf(context).width;
    final pad = width < 700 ? 16.0 : 24.0;

    final refereesCard = _RefereesCard(referees: referees, onShare: () => _share('whatsapp'));
    final historyCard = _HistoryCard(history: history, onShare: () => _share('whatsapp'));

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: EdgeInsets.fromLTRB(pad, pad, pad, 40),
        children: [
          AnimatedFadeIn(child: _Hero(settings: settings, enabled: enabled)),
          const SizedBox(height: 18),
          AnimatedFadeIn(index: 1, child: _Kpis(data: d)),
          const SizedBox(height: 18),
          AnimatedFadeIn(
            index: 2,
            child: _ShareCard(
              link: '${d['link'] ?? ''}',
              code: '${d['code'] ?? ''}',
              message: _message,
              enabled: enabled,
              edited: _messageEdited,
              onEdited: () {
                if (!_messageEdited) setState(() => _messageEdited = true);
              },
              onReset: _resetMessage,
              onCopy: _copy,
              onCopyMessage: () => _copy(_text, 'Message copié dans le presse-papiers'),
              onShare: _share,
            ),
          ),
          const SizedBox(height: 18),
          LayoutBuilder(
            builder: (context, box) {
              if (box.maxWidth < 900) {
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    AnimatedFadeIn(index: 3, child: refereesCard),
                    const SizedBox(height: 18),
                    AnimatedFadeIn(index: 4, child: historyCard),
                  ],
                );
              }
              return Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(flex: 3, child: AnimatedFadeIn(index: 3, child: refereesCard)),
                  const SizedBox(width: 18),
                  Expanded(flex: 2, child: AnimatedFadeIn(index: 4, child: historyCard)),
                ],
              );
            },
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Programme indisponible (installation hors SaaS)
// ---------------------------------------------------------------------------

class _NotAvailable extends StatelessWidget {
  const _NotAvailable();

  @override
  Widget build(BuildContext context) => const EmptyState(
    icon: Icons.card_giftcard_outlined,
    title: 'Parrainage indisponible ici',
    message: 'Le programme de parrainage concerne uniquement la plateforme en ligne Yomara ERP. Votre installation n\'est pas concernée : rien à faire de votre côté.',
  );
}

// ---------------------------------------------------------------------------
// En-tête : l'offre
// ---------------------------------------------------------------------------

class _Hero extends StatefulWidget {
  const _Hero({required this.settings, required this.enabled});

  final Map<String, dynamic> settings;
  final bool enabled;

  @override
  State<_Hero> createState() => _HeroState();
}

class _HeroState extends State<_Hero> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(seconds: 8))..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.settings;
    final still = reduceMotion(context);
    final months = toDouble(s['reward_months']).round();
    final facts = [
      (Icons.local_offer_outlined, '−${_pct(s['referee_discount'])} %', 'pour vos invités, sur leur 1er achat', const Color(0xFF6EE7B7)),
      (Icons.savings_outlined, '${_pct(s['reward_percent'])} %', 'de leurs achats, crédités pour vous', YColors.yellow),
      (Icons.event_repeat_outlined, months > 0 ? '$months mois' : 'Illimité', 'de récompenses par invité', const Color(0xFF93C5FD)),
    ];

    return ClipRRect(
      borderRadius: BorderRadius.circular(26),
      child: AnimatedBuilder(
        animation: _c,
        builder: (context, child) {
          final t = still ? .5 : Curves.easeInOut.transform(_c.value);
          return Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: const [Color(0xFF052E2B), Color(0xFF0B5D4B), Color(0xFF1F4FB8)],
                begin: Alignment(-1 + t * .4, -1),
                end: Alignment(1, 1 - t * .4),
              ),
            ),
            child: Stack(
              children: [
                Positioned(right: -60 + 40 * t, top: -90 + 30 * t, child: _Glow(size: 260, color: const Color(0xFF34D399).withValues(alpha: .30))),
                Positioned(left: -80 + 30 * (1 - t), bottom: -120 + 40 * t, child: _Glow(size: 260, color: YColors.yellow.withValues(alpha: .16))),
                Positioned(
                  right: 26,
                  bottom: -24 + 10 * t,
                  child: Transform.rotate(
                    angle: -math.pi / 12 + t * .18,
                    child: Icon(Icons.card_giftcard_rounded, size: 170, color: Colors.white.withValues(alpha: .06)),
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
              final cols = box.maxWidth > 760 ? 3 : 1;
              final fw = (box.maxWidth - (cols - 1) * 10) / cols;
              return Column(
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
                        const Icon(Icons.volunteer_activism_outlined, size: 14, color: YColors.yellow),
                        const SizedBox(width: 6),
                        Text(tr('Parrainage'), style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: .3)),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    tr('Invitez, gagnez du crédit'),
                    style: TextStyle(color: Colors.white, fontSize: narrow ? 26 : 32, fontWeight: FontWeight.w900, letterSpacing: -.8, height: 1.1),
                  ),
                  const SizedBox(height: 8),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 680),
                    child: Text(referralDeal(s), style: const TextStyle(color: Color(0xFFD1F5E8), fontSize: 14.5, height: 1.5)),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    tr('Votre crédit est déduit automatiquement de vos prochains achats dans la boutique.'),
                    style: const TextStyle(color: Color(0xFFA7D9C8), fontSize: 12.5),
                  ),
                  if (!widget.enabled) ...[
                    const SizedBox(height: 14),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                      decoration: BoxDecoration(color: Colors.white.withValues(alpha: .10), borderRadius: BorderRadius.circular(12)),
                      child: Row(
                        children: [
                          const Icon(Icons.pause_circle_outline_rounded, color: YColors.yellow, size: 18),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              tr('Le programme est momentanément en pause : les nouvelles invitations ne rapportent pas de crédit. Votre crédit déjà gagné reste utilisable.'),
                              style: const TextStyle(color: Colors.white, fontSize: 13),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                  const SizedBox(height: 20),
                  Wrap(
                    spacing: 10,
                    runSpacing: 10,
                    children: [
                      for (final (i, f) in facts.indexed)
                        SizedBox(
                          width: fw,
                          child: AnimatedFadeIn(
                            index: i + 1,
                            child: Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                color: Colors.white.withValues(alpha: .08),
                                borderRadius: BorderRadius.circular(16),
                                border: Border.all(color: Colors.white.withValues(alpha: .12)),
                              ),
                              child: Row(
                                children: [
                                  Container(
                                    width: 40,
                                    height: 40,
                                    decoration: BoxDecoration(color: f.$4.withValues(alpha: .16), borderRadius: BorderRadius.circular(12)),
                                    child: Icon(f.$1, color: f.$4, size: 21),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(tr(f.$2), style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w900, height: 1.1)),
                                        Text(tr(f.$3), style: const TextStyle(color: Color(0xFFC9E9DD), fontSize: 12.5)),
                                      ],
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
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

// ---------------------------------------------------------------------------
// Indicateurs
// ---------------------------------------------------------------------------

class _Kpis extends StatelessWidget {
  const _Kpis({required this.data});

  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final dark = Theme.of(context).brightness == Brightness.dark;
    String n(double v) => number(v.round());
    String m(double v) => money(v);
    final tiles = <(String, double, String Function(double), String, IconData, Color)>[
      ('Filleuls', toDouble(data['referees']), n, 'Inscrits avec votre lien', Icons.group_add_outlined, YColors.blue),
      ('Filleuls qui achètent', toDouble(data['paying']), n, 'Vous rapportent du crédit', Icons.shopping_bag_outlined, const Color(0xFF7C3AED)),
      ('Crédit gagné', toDouble(data['earned_total']), m, 'Depuis le début', Icons.emoji_events_outlined, YColors.warning),
      ('Crédit disponible', toDouble(data['available']), m, 'Déduit de vos prochains achats', Icons.account_balance_wallet_outlined, YColors.success),
    ];
    return LayoutBuilder(
      builder: (context, box) {
        final cols = box.maxWidth > 980 ? 4 : (box.maxWidth > 480 ? 2 : 1);
        final w = (box.maxWidth - (cols - 1) * 12) / cols;
        return Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            for (final (i, k) in tiles.indexed)
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
                        border: Border.all(color: i == 3 && k.$2 > 0 ? YColors.success.withValues(alpha: .45) : (dark ? Colors.white12 : Colors.black.withValues(alpha: .06))),
                        gradient: i == 3 && k.$2 > 0
                            ? LinearGradient(colors: [YColors.success.withValues(alpha: .10), Colors.transparent], begin: Alignment.topLeft, end: Alignment.bottomRight)
                            : null,
                      ),
                      child: Row(
                        children: [
                          DialogIconBadge(icon: Icon(k.$5), color: k.$6, size: 44),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(tr(k.$1), style: TextStyle(color: hint, fontSize: 12.5), maxLines: 1, overflow: TextOverflow.ellipsis),
                                FittedBox(
                                  fit: BoxFit.scaleDown,
                                  alignment: Alignment.centerLeft,
                                  child: CountUp(
                                    value: k.$2,
                                    format: k.$3,
                                    style: TextStyle(fontSize: 21, fontWeight: FontWeight.w900, color: i == 3 && k.$2 > 0 ? YColors.success : null),
                                  ),
                                ),
                                Text(tr(k.$4), style: TextStyle(color: hint, fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis),
                              ],
                            ),
                          ),
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
}

// ---------------------------------------------------------------------------
// Carte de partage : lien, code, message, réseaux
// ---------------------------------------------------------------------------

class _ShareCard extends StatelessWidget {
  const _ShareCard({
    required this.link,
    required this.code,
    required this.message,
    required this.enabled,
    required this.edited,
    required this.onEdited,
    required this.onReset,
    required this.onCopy,
    required this.onCopyMessage,
    required this.onShare,
  });

  final String link;
  final String code;
  final TextEditingController message;
  final bool enabled;
  final bool edited;
  final VoidCallback onEdited;
  final VoidCallback onReset;
  final Future<void> Function(String text, String done) onCopy;
  final VoidCallback onCopyMessage;
  final ValueChanged<String> onShare;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final hint = theme.hintColor;
    final boxColor = dark ? Colors.white.withValues(alpha: .04) : const Color(0xFFF6F9FC);
    final boxBorder = dark ? Colors.white12 : const Color(0xFFE2E8F0);
    final channels = [
      ('whatsapp', 'WhatsApp', Icons.chat_rounded, const Color(0xFF25D366)),
      ('email', 'E-mail', Icons.alternate_email_rounded, const Color(0xFFEA4335)),
      ('sms', 'SMS', Icons.sms_outlined, const Color(0xFF0EA5E9)),
      ('facebook', 'Facebook', Icons.facebook_rounded, const Color(0xFF1877F2)),
      ('linkedin', 'LinkedIn', Icons.work_outline_rounded, const Color(0xFF0A66C2)),
    ];

    Widget box({required String label, required String value, required String copyLabel, required String done, TextStyle? style}) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(tr(label), style: TextStyle(color: hint, fontSize: 12.5, fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        Container(
          padding: const EdgeInsets.fromLTRB(14, 6, 6, 6),
          decoration: BoxDecoration(color: boxColor, borderRadius: BorderRadius.circular(14), border: Border.all(color: boxBorder)),
          child: Row(
            children: [
              Expanded(child: SelectableText(value, maxLines: 1, style: style ?? const TextStyle(fontWeight: FontWeight.w600))),
              const SizedBox(width: 6),
              _CopyButton(label: copyLabel, onCopy: () => onCopy(value, done)),
            ],
          ),
        ),
      ],
    );

    return SectionCard(
      title: 'Votre lien de parrainage',
      trailing: Icon(Icons.ios_share_rounded, size: 18, color: theme.colorScheme.primary),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          LayoutBuilder(
            builder: (context, c) {
              final linkBox = box(label: 'Lien à partager', value: link, copyLabel: 'Copier le lien', done: 'Lien copié dans le presse-papiers');
              final codeBox = box(
                label: 'Code parrain',
                value: code,
                copyLabel: 'Copier le code',
                done: 'Code copié dans le presse-papiers',
                style: _mono.copyWith(fontSize: 17, letterSpacing: 2, color: theme.colorScheme.primary),
              );
              if (c.maxWidth < 640) return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [linkBox, const SizedBox(height: 12), codeBox]);
              return Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [Expanded(flex: 3, child: linkBox), const SizedBox(width: 14), SizedBox(width: 240, child: codeBox)],
              );
            },
          ),
          const SizedBox(height: 18),
          Row(
            children: [
              Expanded(child: Text(tr('Message prêt à envoyer'), style: TextStyle(color: hint, fontSize: 12.5, fontWeight: FontWeight.w600))),
              if (edited)
                TextButton.icon(
                  onPressed: onReset,
                  icon: const Icon(Icons.restart_alt_rounded, size: 16),
                  label: Text(tr('Message d\'origine')),
                  style: TextButton.styleFrom(visualDensity: VisualDensity.compact),
                ),
              TextButton.icon(
                onPressed: onCopyMessage,
                icon: const Icon(Icons.copy_rounded, size: 16),
                label: Text(tr('Copier')),
                style: TextButton.styleFrom(visualDensity: VisualDensity.compact),
              ),
            ],
          ),
          const SizedBox(height: 6),
          TextField(
            controller: message,
            minLines: 3,
            maxLines: 6,
            onChanged: (_) => onEdited(),
            decoration: InputDecoration(
              filled: true,
              fillColor: boxColor,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: boxBorder)),
              enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: boxBorder)),
              helperText: tr('Modifiez-le librement : il est utilisé par WhatsApp, l\'e-mail et le SMS. Le lien est ajouté s\'il manque.'),
              helperMaxLines: 2,
            ),
          ),
          const SizedBox(height: 16),
          Text(tr('Partager sur'), style: TextStyle(color: hint, fontSize: 12.5, fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              for (final (i, ch) in channels.indexed)
                AnimatedFadeIn(
                  index: i,
                  child: HoverLift(
                    scale: 1.04,
                    child: _ShareButton(label: ch.$2, icon: ch.$3, color: ch.$4, onTap: () => onShare(ch.$1)),
                  ),
                ),
            ],
          ),
          if (!enabled) ...[
            const SizedBox(height: 12),
            Text(tr('Le programme est en pause : vous pouvez partager votre lien, mais il ne rapporte pas de crédit pour le moment.'), style: TextStyle(color: hint, fontSize: 12.5)),
          ],
        ],
      ),
    );
  }
}

class _ShareButton extends StatelessWidget {
  const _ShareButton({required this.label, required this.icon, required this.color, required this.onTap});

  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
    color: color.withValues(alpha: .10),
    borderRadius: BorderRadius.circular(14),
    child: InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        decoration: BoxDecoration(borderRadius: BorderRadius.circular(14), border: Border.all(color: color.withValues(alpha: .35))),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 20, color: color),
            const SizedBox(width: 8),
            Text(tr(label), style: TextStyle(color: Color.lerp(color, Colors.black, .2), fontWeight: FontWeight.w800)),
          ],
        ),
      ),
    ),
  );
}

/// Bouton « copier » qui se transforme brièvement en coche.
class _CopyButton extends StatefulWidget {
  const _CopyButton({required this.label, required this.onCopy});

  final String label;
  final Future<void> Function() onCopy;

  @override
  State<_CopyButton> createState() => _CopyButtonState();
}

class _CopyButtonState extends State<_CopyButton> {
  bool _done = false;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _tap() async {
    await widget.onCopy();
    if (!mounted) return;
    setState(() => _done = true);
    _timer?.cancel();
    _timer = Timer(const Duration(milliseconds: 1600), () {
      if (mounted) setState(() => _done = false);
    });
  }

  @override
  Widget build(BuildContext context) => IconButton.filledTonal(
    tooltip: tr(widget.label),
    onPressed: _tap,
    icon: AnimatedSwitcher(
      duration: const Duration(milliseconds: 220),
      transitionBuilder: (c, a) => ScaleTransition(scale: a, child: c),
      child: _done
          ? const Icon(Icons.check_rounded, key: ValueKey('done'), color: YColors.success, size: 20)
          : const Icon(Icons.copy_rounded, key: ValueKey('copy'), size: 20),
    ),
  );
}

// ---------------------------------------------------------------------------
// Filleuls
// ---------------------------------------------------------------------------

class _RefereesCard extends StatelessWidget {
  const _RefereesCard({required this.referees, required this.onShare});

  final List<Map<String, dynamic>> referees;
  final VoidCallback onShare;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return SectionCard(
      title: 'Vos filleuls',
      trailing: referees.isEmpty ? null : StatusChip('${referees.length} inscrit(s)', color: YColors.blue, icon: Icons.group_outlined),
      child: referees.isEmpty
          ? EmptyState(
              icon: Icons.group_add_outlined,
              title: 'Aucun filleul pour le moment',
              message: 'Partagez votre lien à vos partenaires, clients ou confrères : chaque société qui s\'inscrit avec lui apparaîtra ici.',
              action: FilledButton.icon(onPressed: onShare, icon: const Icon(Icons.chat_rounded), label: Text(tr('Inviter sur WhatsApp'))),
            )
          : Column(
              children: [
                for (final (i, r) in referees.indexed) ...[
                  if (i > 0) const Divider(height: 1),
                  AnimatedFadeIn(
                    index: i.clamp(0, 12),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      child: Row(
                        children: [
                          InitialsAvatar('${r['name'] ?? '?'}', size: 38),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text('${r['name'] ?? '—'}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700)),
                                Text(tr('Inscrit le ${dateFr(r['joined_at'])}'), style: TextStyle(color: hint, fontSize: 12.5)),
                              ],
                            ),
                          ),
                          const SizedBox(width: 8),
                          if (toDouble(r['earned']) > 0)
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                Text('+ ${money(toDouble(r['earned']))}', style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w800)),
                                Text(tr('gagnés'), style: TextStyle(color: hint, fontSize: 11.5)),
                              ],
                            )
                          else
                            const StatusChip('Pas encore d\'achat', color: YColors.muted),
                        ],
                      ),
                    ),
                  ),
                ],
              ],
            ),
    );
  }
}

// ---------------------------------------------------------------------------
// Historique du crédit
// ---------------------------------------------------------------------------

class _HistoryCard extends StatelessWidget {
  const _HistoryCard({required this.history, required this.onShare});

  final List<Map<String, dynamic>> history;
  final VoidCallback onShare;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    return SectionCard(
      title: 'Historique du crédit',
      trailing: Icon(Icons.history_rounded, size: 18, color: theme.colorScheme.primary),
      child: history.isEmpty
          ? EmptyState(
              icon: Icons.savings_outlined,
              title: 'Pas encore de crédit',
              message: 'Dès qu\'un filleul achète dans la boutique, votre part est créditée ici et déduite de vos prochains achats.',
              action: OutlinedButton.icon(onPressed: onShare, icon: const Icon(Icons.ios_share_rounded), label: Text(tr('Partager mon lien'))),
            )
          : Column(
              children: [
                for (final (i, h) in history.indexed) ...[
                  if (i > 0) const Divider(height: 1),
                  AnimatedFadeIn(
                    index: i.clamp(0, 12),
                    child: Builder(
                      builder: (context) {
                        final earned = h['kind'] == 'earned';
                        final color = earned ? YColors.success : hint;
                        return Padding(
                          padding: const EdgeInsets.symmetric(vertical: 10),
                          child: Row(
                            children: [
                              Container(
                                width: 36,
                                height: 36,
                                decoration: BoxDecoration(
                                  color: (earned ? YColors.success : YColors.muted).withValues(alpha: .12),
                                  borderRadius: BorderRadius.circular(11),
                                ),
                                child: Icon(earned ? Icons.trending_up_rounded : Icons.shopping_cart_checkout_rounded, size: 18, color: earned ? YColors.success : YColors.muted),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(tr(earned ? 'Achat d\'un filleul' : 'Utilisé sur un achat'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                    Text(dateTimeFr(h['at']), style: TextStyle(color: hint, fontSize: 12)),
                                  ],
                                ),
                              ),
                              Text(
                                '${earned ? '+' : '−'} ${money(toDouble(h['amount']))}',
                                style: TextStyle(color: earned ? YColors.success : color, fontWeight: FontWeight.w800),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
                  ),
                ],
              ],
            ),
    );
  }
}
