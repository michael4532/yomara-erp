import 'package:flutter/material.dart';
import '../../i18n/i18n.dart';

/// Animations partagées : apparition douce, survol, compteurs. Courtes et discrètes pour rester fluides
/// sur les petits appareils ; respectent le réglage « réduire les animations » du système.

bool reduceMotion(BuildContext context) => MediaQuery.maybeDisableAnimationsOf(context) ?? false;

/// Apparition : fondu + léger glissement vers le haut. [index] décale les éléments d'une liste (cascade).
class AnimatedFadeIn extends StatefulWidget {
  const AnimatedFadeIn({super.key, required this.child, this.index = 0, this.offset = 14, this.duration = const Duration(milliseconds: 360)});

  final Widget child;
  final int index;
  final double offset;
  final Duration duration;

  @override
  State<AnimatedFadeIn> createState() => _AnimatedFadeInState();
}

class _AnimatedFadeInState extends State<AnimatedFadeIn> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: widget.duration);
  late final Animation<double> _t = CurvedAnimation(parent: _c, curve: Curves.easeOutCubic);

  @override
  void initState() {
    super.initState();
    final delay = Duration(milliseconds: (widget.index.clamp(0, 12)) * 35);
    Future.delayed(delay, () {
      if (mounted) _c.forward();
    });
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (reduceMotion(context)) return widget.child;
    return AnimatedBuilder(
      animation: _t,
      child: widget.child,
      builder: (context, child) => Opacity(
        opacity: _t.value,
        child: Transform.translate(offset: Offset(0, (1 - _t.value) * widget.offset), child: child),
      ),
    );
  }
}

/// Survol (souris) : la carte se soulève légèrement ; appui : léger enfoncement.
class HoverLift extends StatefulWidget {
  const HoverLift({super.key, required this.child, this.enabled = true, this.scale = 1.012});

  final Widget child;
  final bool enabled;
  final double scale;

  @override
  State<HoverLift> createState() => _HoverLiftState();
}

class _HoverLiftState extends State<HoverLift> {
  bool _hover = false;
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    if (!widget.enabled || reduceMotion(context)) return widget.child;
    final dark = Theme.of(context).brightness == Brightness.dark;
    return MouseRegion(
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => (_hover = false, _down = false)),
      child: Listener(
        onPointerDown: (_) => setState(() => _down = true),
        onPointerUp: (_) => setState(() => _down = false),
        onPointerCancel: (_) => setState(() => _down = false),
        child: AnimatedScale(
          scale: _down ? 0.985 : (_hover ? widget.scale : 1),
          duration: const Duration(milliseconds: 160),
          curve: Curves.easeOut,
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              boxShadow: [
                if (_hover) BoxShadow(color: (dark ? Colors.black : const Color(0xFF1F3A6B)).withValues(alpha: dark ? .45 : .12), blurRadius: 22, offset: const Offset(0, 8)),
              ],
            ),
            child: widget.child,
          ),
        ),
      ),
    );
  }
}

/// Nombre qui défile jusqu'à sa valeur (tableaux de bord).
class CountUp extends StatelessWidget {
  const CountUp({super.key, required this.value, required this.format, this.style});

  final double value;
  final String Function(double) format;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    if (reduceMotion(context)) return Text(tr(format(value)), style: style);
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: value),
      duration: const Duration(milliseconds: 900),
      curve: Curves.easeOutCubic,
      builder: (context, v, _) => Text(tr(format(v)), style: style),
    );
  }
}

/// Transition entre les pages du menu : fondu et glissement léger.
Widget pageSwitchTransition(Widget child, Animation<double> animation) {
  final curved = CurvedAnimation(parent: animation, curve: Curves.easeOutCubic);
  return FadeTransition(
    opacity: curved,
    child: SlideTransition(position: Tween(begin: const Offset(0, .012), end: Offset.zero).animate(curved), child: child),
  );
}

/// Effet de chargement (squelette) en attendant les données.
class Shimmer extends StatefulWidget {
  const Shimmer({super.key, this.height = 16, this.width = double.infinity, this.radius = 8});

  final double height;
  final double width;
  final double radius;

  @override
  State<Shimmer> createState() => _ShimmerState();
}

class _ShimmerState extends State<Shimmer> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1200))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final base = dark ? Colors.white10 : const Color(0xFFE8EDF6);
    final hi = dark ? Colors.white24 : const Color(0xFFF6F8FC);
    return AnimatedBuilder(
      animation: _c,
      builder: (context, _) => Container(
        width: widget.width,
        height: widget.height,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(widget.radius),
          gradient: LinearGradient(
            begin: Alignment(-1.5 + _c.value * 3, 0),
            end: Alignment(-0.5 + _c.value * 3, 0),
            colors: [base, hi, base],
          ),
        ),
      ),
    );
  }
}

/// Chargement d'une page : quelques lignes squelette plutôt qu'un simple rond.
class LoadingSkeleton extends StatelessWidget {
  const LoadingSkeleton({super.key, this.lines = 5});

  final int lines;

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
        physics: const NeverScrollableScrollPhysics(),
        padding: const EdgeInsets.all(24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Shimmer(height: 26, width: 260),
          const SizedBox(height: 18),
          for (var i = 0; i < lines; i++) ...[
            FractionallySizedBox(widthFactor: i.isEven ? 1 : .7, child: const Shimmer(height: 54, radius: 12)),
            const SizedBox(height: 12),
          ],
        ]),
      );
}
