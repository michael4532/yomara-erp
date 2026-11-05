import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../../i18n/i18n.dart';

/// Multi-agences : choix de l'agence de travail (valeur par défaut des ventes, achats, caisse ; filtre des listes).
class BranchSelector extends StatelessWidget {
  const BranchSelector({super.key, this.dark = true});

  final bool dark;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    if (!s.hasBranches) return const SizedBox.shrink();
    final current = s.currentBranch;
    final canAll = !s.branchRestricted || s.branches.length > 1;
    final fg = dark ? Colors.white : Theme.of(context).colorScheme.onSurface;
    return PopupMenuButton<String>(
      tooltip: tr('Agence de travail'),
      onSelected: (v) => s.setBranch(v == '*' ? null : v),
      itemBuilder: (_) => [
        if (canAll)
          CheckedPopupMenuItem(value: '*', checked: current == null, child: Text(tr(s.branchRestricted ? 'Toutes mes agences' : 'Toutes les agences'))),
        for (final b in s.branches)
          CheckedPopupMenuItem(value: '${b['id']}', checked: current?['id'] == b['id'], child: Text(tr('${b['name']}${b['city'] != null ? ' · ${b['city']}' : ''}'))),
      ],
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: dark ? 228 : 170),
        child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        decoration: BoxDecoration(
          color: dark ? Colors.white.withValues(alpha: .08) : YColors.blue.withValues(alpha: .08),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: dark ? Colors.white.withValues(alpha: .12) : YColors.blue.withValues(alpha: .2)),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.store_mall_directory_outlined, size: 18, color: dark ? YColors.yellow : YColors.blue),
          const SizedBox(width: 8),
          Flexible(
            child: Text(tr(current?['name'] as String? ?? (s.branchRestricted ? 'Toutes mes agences' : 'Toutes les agences')),
                maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: fg, fontWeight: FontWeight.w600, fontSize: 13)),
          ),
          const SizedBox(width: 4),
          Icon(Icons.expand_more, size: 18, color: fg.withValues(alpha: .7)),
        ]),
        ),
      ),
    );
  }
}
