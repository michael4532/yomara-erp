import 'dart:math' as math;

import 'package:flutter/material.dart';
import '../widgets/common.dart' show YomaraMark;
import 'package:provider/provider.dart';

import '../../i18n/i18n.dart';
import '../../state/session.dart';

/// Premier démarrage : logo animé, barre de progression et étapes pendant que l'ERP charge les données.
/// Même apparence que l'écran de chargement HTML (web/index.html) pour un passage sans saut.
class BootScreen extends StatefulWidget {
  const BootScreen({super.key});

  @override
  State<BootScreen> createState() => _BootScreenState();
}

class _BootScreenState extends State<BootScreen> with TickerProviderStateMixin {
  late final _spin = AnimationController(vsync: this, duration: const Duration(milliseconds: 2400))..repeat();
  late final _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat(reverse: true);
  // Progression affichée : avance doucement seule et rattrape la progression réelle du téléchargement
  late final _tick = AnimationController(vsync: this, duration: const Duration(days: 1))..addListener(_advance);
  double _shown = .32; // l'écran HTML s'est arrêté vers 30 %

  static const _steps = ['Ouverture de votre espace', 'Installation de vos modules', 'Synchronisation de vos données', 'Préparation de vos tableaux de bord', 'Presque prêt'];

  @override
  void initState() {
    super.initState();
    _tick.forward();
  }

  void _advance() {
    final real = context.read<Session>().sync.pullProgress.value;
    final target = real == null ? .9 : .4 + real * .58;
    final next = _shown + (target - _shown) * .02 + .0004;
    if ((next - _shown).abs() > .0005) setState(() => _shown = next.clamp(0, .99));
  }

  @override
  void dispose() {
    _spin.dispose();
    _pulse.dispose();
    _tick.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    final company = '${session.bootstrap?['company']?['name'] ?? ''}';
    final user = '${session.bootstrap?['user']?['name'] ?? ''}'.split(' ').first;
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color(0xFF0B1B3A), Color(0xFF12306B), Color(0xFF0B1B3A)]),
        ),
        child: Stack(
          children: [
            // Halos décoratifs
            Positioned(
              top: -120,
              right: -80,
              child: _Glow(color: const Color(0xFF2E6BE6), size: 380, pulse: _pulse),
            ),
            Positioned(
              bottom: -140,
              left: -100,
              child: _Glow(color: const Color(0xFFFFC531), size: 340, pulse: _pulse, invert: true),
            ),
            Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 28),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      SizedBox(
                        width: 112,
                        height: 112,
                        child: Stack(
                          alignment: Alignment.center,
                          children: [
                            RotationTransition(
                              turns: _spin,
                              child: CustomPaint(size: const Size(112, 112), painter: _RingPainter()),
                            ),
                            ScaleTransition(
                              scale: Tween(begin: .94, end: 1.04).animate(CurvedAnimation(parent: _pulse, curve: Curves.easeInOut)),
                              child: const YomaraMark(size: 72, glow: true),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 26),
                      RichText(
                        text: const TextSpan(
                          style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: Colors.white, letterSpacing: -.3),
                          children: [
                            TextSpan(text: 'Yomara '),
                            TextSpan(
                              text: 'ERP',
                              style: TextStyle(color: Color(0xFFFFC531)),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 10),
                      Text(
                        tr(user.isNotEmpty ? 'Bienvenue $user${company.isNotEmpty ? ' · $company' : ''}' : 'Bienvenue'),
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: Color(0xFFDCE5F7), fontSize: 15, fontWeight: FontWeight.w600),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        tr('Patientez pendant que nous configurons votre interface…'),
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: Color(0xFF9FB0D6), fontSize: 13.5),
                      ),
                      const SizedBox(height: 28),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: Stack(
                          children: [
                            Container(height: 8, color: Colors.white.withValues(alpha: .10)),
                            FractionallySizedBox(
                              widthFactor: _shown,
                              child: Container(
                                height: 8,
                                decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFFFFC531), Color(0xFFFFA62B), Color(0xFF4F8BFF)])),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 12),
                      ValueListenableBuilder<String>(
                        valueListenable: session.bootStep,
                        builder: (context, step, _) {
                          final idx = math.max(0, _steps.indexOf(step));
                          return Column(
                            children: [
                              Row(
                                children: [
                                  Expanded(
                                    child: AnimatedSwitcher(
                                      duration: const Duration(milliseconds: 300),
                                      child: Text(
                                        tr('$step…'),
                                        key: ValueKey(step),
                                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 13),
                                      ),
                                    ),
                                  ),
                                  Text(
                                    '${(_shown * 100).round()} %',
                                    style: const TextStyle(color: Color(0xFF9FB0D6), fontSize: 12.5, fontFeatures: [FontFeature.tabularFigures()]),
                                  ),
                                ],
                              ),
                              const SizedBox(height: 22),
                              Wrap(
                                alignment: WrapAlignment.center,
                                spacing: 8,
                                runSpacing: 8,
                                children: [
                                  for (var i = 0; i < 4; i++)
                                    AnimatedContainer(
                                      duration: const Duration(milliseconds: 400),
                                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                      decoration: BoxDecoration(
                                        color: i < idx ? const Color(0xFF12A150).withValues(alpha: .18) : Colors.white.withValues(alpha: i == idx ? .12 : .05),
                                        borderRadius: BorderRadius.circular(20),
                                      ),
                                      child: Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          if (i < idx)
                                            const Icon(Icons.check_rounded, size: 14, color: Color(0xFF5BE39A))
                                          else if (i == idx)
                                            const SizedBox(width: 12, height: 12, child: CircularProgressIndicator(strokeWidth: 1.8, color: Color(0xFFFFC531)))
                                          else
                                            Icon(Icons.circle_outlined, size: 12, color: Colors.white.withValues(alpha: .35)),
                                          const SizedBox(width: 6),
                                          Text(
                                            tr(['Espace', 'Modules', 'Données', 'Tableaux de bord'][i]),
                                            style: TextStyle(color: Colors.white.withValues(alpha: i <= idx ? .95 : .5), fontSize: 12),
                                          ),
                                        ],
                                      ),
                                    ),
                                ],
                              ),
                            ],
                          );
                        },
                      ),
                    ],
                  ),
                ),
              ),
            ),
            const Positioned(
              left: 0,
              right: 0,
              bottom: 22,
              child: Text(
                'Vos données restent disponibles même sans connexion.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Color(0xFF7084B0), fontSize: 12),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final p = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 4
      ..strokeCap = StrokeCap.round
      ..shader = const SweepGradient(colors: [Color(0x00FFC531), Color(0xFFFFC531), Color(0xFF4F8BFF), Color(0x004F8BFF)], stops: [0, .45, .8, 1]).createShader(rect);
    canvas.drawArc(rect.deflate(4), 0, math.pi * 1.6, false, p);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _Glow extends AnimatedWidget {
  const _Glow({required this.color, required this.size, required Animation<double> pulse, this.invert = false}) : super(listenable: pulse);

  final Color color;
  final double size;
  final bool invert;

  @override
  Widget build(BuildContext context) {
    final v = (listenable as Animation<double>).value;
    final t = invert ? 1 - v : v;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: RadialGradient(
          colors: [
            color.withValues(alpha: .16 + t * .10),
            color.withValues(alpha: 0),
          ],
        ),
      ),
    );
  }
}
