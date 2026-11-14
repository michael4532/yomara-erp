import 'package:flutter/material.dart';

import '../../i18n/i18n.dart';
import '../navigation.dart' show UiPrefs;

/// Bandeau « Comment ça marche » : trois étapes illustrées, masquable une fois compris ([prefKey]).
class HowItWorksCard extends StatelessWidget {
  const HowItWorksCard({super.key, required this.title, required this.icon, required this.steps, required this.prefKey, this.dismissible = true, this.onDismiss});

  final String title;
  final IconData icon;
  final List<(IconData, String, String)> steps;
  final String prefKey;
  final bool dismissible;
  final VoidCallback? onDismiss;

  static bool seen(String prefKey) => UiPrefs.flag(prefKey);

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.fromLTRB(18, 16, 10, 16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: const LinearGradient(colors: [Color(0xFF0A1633), Color(0xFF1D3E86)], begin: Alignment.topLeft, end: Alignment.bottomRight),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(icon, color: const Color(0xFFFFC531), size: 20),
          const SizedBox(width: 8),
          Expanded(child: Text(tr(title), style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w800))),
          if (dismissible)
            TextButton(
              onPressed: () async {
                await UiPrefs.setFlag(prefKey, true);
                onDismiss?.call();
              },
              child: Text(tr('J\'ai compris'), style: const TextStyle(color: Colors.white70)),
            ),
        ]),
        const SizedBox(height: 12),
        LayoutBuilder(builder: (c, k) {
          final cols = k.maxWidth > 760 ? steps.length : 1;
          final w = (k.maxWidth - 8 - (cols - 1) * 12) / cols;
          return Wrap(spacing: 12, runSpacing: 12, children: [
            for (final (i, s) in steps.indexed)
              SizedBox(
                width: w,
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Container(
                    width: 36,
                    height: 36,
                    decoration: BoxDecoration(color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(10)),
                    child: Icon(s.$1, color: const Color(0xFFFFC531), size: 20),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${i + 1}. ${s.$2}'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
                      const SizedBox(height: 2),
                      Text(tr(s.$3), style: TextStyle(color: Colors.white.withValues(alpha: .78), fontSize: 12.5, height: 1.35)),
                    ]),
                  ),
                ]),
              ),
          ]);
        }),
      ]),
    );
  }
}

/// Avancement en étapes (pastilles reliées) : [step] étapes franchies sur [labels].
class FlowSteps extends StatelessWidget {
  const FlowSteps({super.key, required this.labels, required this.step, this.color, this.stoppedLabel});

  final List<String> labels;
  final int step;
  final Color? color;
  final String? stoppedLabel;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final c = color ?? Theme.of(context).colorScheme.primary;
    return Row(children: [
      for (var i = 0; i < labels.length; i++) ...[
        if (i > 0) Expanded(child: Container(height: 2, margin: const EdgeInsets.symmetric(horizontal: 4), color: i < step ? c : hint.withValues(alpha: .25))),
        Container(
          width: 10,
          height: 10,
          decoration: BoxDecoration(shape: BoxShape.circle, color: i < step ? c : Colors.transparent, border: Border.all(color: i < step ? c : hint.withValues(alpha: .5), width: 1.5)),
        ),
        const SizedBox(width: 4),
        Text(tr(stoppedLabel != null && i == step - 1 ? stoppedLabel! : labels[i]),
            style: TextStyle(fontSize: 11, color: i < step ? c : hint, fontWeight: i == step - 1 ? FontWeight.w700 : FontWeight.w500)),
      ],
    ]);
  }
}
