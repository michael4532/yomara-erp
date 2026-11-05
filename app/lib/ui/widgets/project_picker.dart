import 'package:flutter/material.dart';

import '../../core/local_db.dart';
import '../../state/session.dart';
import '../../i18n/i18n.dart';

/// Rattachement d'un document à un projet (rentabilité du projet) : visible si le module Projets est actif.
bool projectsEnabled(Session s) => s.hasModule('projets') && LocalDb.hasEntity('prj_projects');

/// Projets proposés : en cours / planifiés / en pause, plus le projet déjà choisi.
List<Map<String, dynamic>> projectChoices([String? current]) => (LocalDb.hasEntity('prj_projects') ? LocalDb.all('prj_projects') : <Map<String, dynamic>>[])
    .where((p) => !const ['done', 'cancelled'].contains(p['status']) || p['id'] == current)
    .toList()
  ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));

String projectLabel(Map<String, dynamic> p) => '${(p['code'] ?? '').toString().isEmpty ? '' : '${p['code']} · '}${p['name']}';

class ProjectDropdown extends StatelessWidget {
  const ProjectDropdown({super.key, required this.value, required this.onChanged, this.enabled = true});

  final String? value;
  final ValueChanged<String?> onChanged;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final items = projectChoices(value);
    final v = items.any((p) => p['id'] == value) ? value : null;
    return DropdownButtonFormField<String?>(
      key: ValueKey('prj-$v'),
      initialValue: v,
      isExpanded: true,
      items: [
        DropdownMenuItem(value: null, child: Text(tr('Aucun projet'))),
        for (final p in items) DropdownMenuItem(value: '${p['id']}', child: Text(tr(projectLabel(p)), overflow: TextOverflow.ellipsis)),
      ],
      onChanged: enabled ? onChanged : null,
    );
  }
}
