import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/local_db.dart';
import '../../i18n/i18n.dart';
import '../theme.dart';
import '../widgets/common.dart' show YomaraMark;

/// Premier lancement de l'application (téléphone, tablette, ordinateur) : quatre écrans de présentation,
/// puis la connexion. Affiché une seule fois par appareil.
class IntroScreen extends StatefulWidget {
  const IntroScreen({super.key, required this.onDone});

  /// Fin de la présentation : true pour ouvrir directement « Créer mon entreprise ».
  final ValueChanged<bool> onDone;

  static bool get seen => LocalDb.meta.get('intro_seen') == true;

  @override
  State<IntroScreen> createState() => _IntroScreenState();
}

class _Slide {
  const _Slide(this.icon, this.kicker, this.title, this.text, this.chips, this.accent);

  final IconData icon;
  final String kicker;
  final String title;
  final String text;
  final List<(IconData, String)> chips;
  final Color accent;
}

const _slides = [
  _Slide(Icons.business_center_rounded, 'Bienvenue', 'Toute votre entreprise dans votre poche',
      'Ventes, caisse, stock, clients, finances et équipes : le même logiciel sur votre téléphone, votre ordinateur et en ligne.',
      [(Icons.point_of_sale_rounded, 'Caisse'), (Icons.inventory_2_outlined, 'Stock'), (Icons.receipt_long_outlined, 'Factures'), (Icons.groups_2_outlined, 'Équipe')], YColors.yellow),
  _Slide(Icons.forum_rounded, 'Travail d\'équipe', 'Restez joignable, même loin du bureau',
      'YoMail, notifications, demandes internes, agenda et réunions : chaque collaborateur suit son travail depuis son téléphone.',
      [(Icons.mail_outline_rounded, 'YoMail'), (Icons.notifications_active_outlined, 'Alertes'), (Icons.event_outlined, 'Agenda'), (Icons.task_alt_rounded, 'Tâches')], Color(0xFF7CC4FF)),
  _Slide(Icons.cloud_off_rounded, 'Hors ligne', 'Internet coupe ? Vous continuez',
      'Vos ventes et saisies sont gardées sur l\'appareil, puis envoyées automatiquement dès que la connexion revient.',
      [(Icons.sync_rounded, 'Synchronisation'), (Icons.lock_outline_rounded, 'Données protégées'), (Icons.backup_outlined, 'Sauvegardes')], Color(0xFF6EE7B7)),
  _Slide(Icons.login_rounded, 'Connexion', 'Un seul compte, toute votre structure',
      'Connectez-vous avec l\'e-mail et le mot de passe de votre entreprise : l\'application retrouve votre société, vos droits et vos données.',
      [(Icons.verified_user_outlined, 'Vos droits'), (Icons.apartment_rounded, 'Votre société'), (Icons.devices_rounded, 'Tous vos appareils')], YColors.yellow),
];

class _IntroScreenState extends State<IntroScreen> with SingleTickerProviderStateMixin {
  final _page = PageController();
  late final _float = AnimationController(vsync: this, duration: const Duration(seconds: 4))..repeat(reverse: true);
  int _i = 0;

  @override
  void dispose() {
    _page.dispose();
    _float.dispose();
    super.dispose();
  }

  Future<void> _done({bool register = false}) async {
    await LocalDb.meta.put('intro_seen', true);
    if (mounted) widget.onDone(register);
  }

  void _next() => _i == _slides.length - 1 ? _done() : _page.nextPage(duration: const Duration(milliseconds: 380), curve: Curves.easeOutCubic);

  @override
  Widget build(BuildContext context) {
    final last = _i == _slides.length - 1;
    final size = MediaQuery.sizeOf(context);
    final wide = size.width >= 760;
    return Scaffold(
      backgroundColor: YColors.night,
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color(0xFF0B1530), Color(0xFF12306B), Color(0xFF0B1530)]),
        ),
        child: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: Column(children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 12, 8, 0),
                  child: Row(children: [
                    const YomaraMark(size: 30),
                    const SizedBox(width: 10),
                    const Text('Yomara ERP', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 17)),
                    const Spacer(),
                    if (!last) TextButton(onPressed: _done, child: Text(tr('Passer'), style: const TextStyle(color: Color(0xFFC9D6F5)))),
                  ]),
                ),
                Expanded(
                  child: PageView.builder(
                    controller: _page,
                    itemCount: _slides.length,
                    onPageChanged: (i) => setState(() => _i = i),
                    itemBuilder: (context, i) => _SlideView(slide: _slides[i], float: _float, compact: !wide && size.height < 700),
                  ),
                ),
                Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  for (var k = 0; k < _slides.length; k++)
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 260),
                      margin: const EdgeInsets.symmetric(horizontal: 4),
                      width: k == _i ? 26 : 8,
                      height: 8,
                      decoration: BoxDecoration(color: k == _i ? YColors.yellow : Colors.white24, borderRadius: BorderRadius.circular(8)),
                    ),
                ]),
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 22, 24, 18),
                  child: Column(children: [
                    SizedBox(
                      width: double.infinity,
                      height: 52,
                      child: FilledButton(
                        style: FilledButton.styleFrom(backgroundColor: YColors.yellow, foregroundColor: YColors.night, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
                        onPressed: _next,
                        child: Text(tr(last ? 'Se connecter' : 'Suivant'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                      ),
                    ),
                    AnimatedSize(
                      duration: const Duration(milliseconds: 250),
                      child: last
                          ? Padding(
                              padding: const EdgeInsets.only(top: 8),
                              child: TextButton(
                                onPressed: () => _done(register: true),
                                child: Text(tr('Pas encore de compte ? Créer mon entreprise'), style: const TextStyle(color: Colors.white)),
                              ),
                            )
                          : const SizedBox(width: double.infinity),
                    ),
                  ]),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

class _SlideView extends StatelessWidget {
  const _SlideView({required this.slide, required this.float, required this.compact});

  final _Slide slide;
  final Animation<double> float;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final art = compact ? 150.0 : 210.0;
    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 28),
      child: Column(children: [
        SizedBox(height: compact ? 8 : 28),
        // Illustration : grand pictogramme et pastilles qui flottent doucement autour
        SizedBox(
          height: art + 70,
          child: AnimatedBuilder(
            animation: float,
            builder: (context, _) {
              final t = Curves.easeInOut.transform(float.value);
              return Stack(alignment: Alignment.center, children: [
                Container(
                  width: art,
                  height: art,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: RadialGradient(colors: [slide.accent.withValues(alpha: .35), slide.accent.withValues(alpha: 0)]),
                  ),
                ),
                Transform.translate(
                  offset: Offset(0, -6 + 12 * t),
                  child: Container(
                    width: art * .52,
                    height: art * .52,
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: .08),
                      borderRadius: BorderRadius.circular(art * .16),
                      border: Border.all(color: Colors.white24),
                      boxShadow: [BoxShadow(color: slide.accent.withValues(alpha: .25), blurRadius: 40, spreadRadius: 2)],
                    ),
                    child: Icon(slide.icon, size: art * .27, color: slide.accent),
                  ),
                ),
                for (var k = 0; k < slide.chips.length; k++)
                  Builder(builder: (context) {
                    final angle = -math.pi / 2 + k * 2 * math.pi / slide.chips.length + .35;
                    final r = art * .62 + 6 * math.sin((t + k * .25) * math.pi);
                    return Transform.translate(
                      offset: Offset(math.cos(angle) * r, math.sin(angle) * r * .78),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(color: const Color(0xFF16264A), borderRadius: BorderRadius.circular(20), border: Border.all(color: Colors.white24)),
                        child: Row(mainAxisSize: MainAxisSize.min, children: [
                          Icon(slide.chips[k].$1, size: 15, color: slide.accent),
                          const SizedBox(width: 6),
                          Text(tr(slide.chips[k].$2), style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600)),
                        ]),
                      ),
                    );
                  }),
              ]);
            },
          ),
        ),
        SizedBox(height: compact ? 8 : 22),
        Text(tr(slide.kicker).toUpperCase(), style: TextStyle(color: slide.accent, fontWeight: FontWeight.w800, fontSize: 12.5, letterSpacing: 1.2)),
        const SizedBox(height: 10),
        Text(tr(slide.title), textAlign: TextAlign.center, style: TextStyle(color: Colors.white, fontSize: compact ? 24 : 28, fontWeight: FontWeight.w800, height: 1.2)),
        const SizedBox(height: 14),
        Text(tr(slide.text), textAlign: TextAlign.center, style: const TextStyle(color: Color(0xFFC9D6F5), fontSize: 15.5, height: 1.5)),
        const SizedBox(height: 16),
      ]),
    );
  }
}
