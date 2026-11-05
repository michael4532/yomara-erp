import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import 'insights_view.dart' show compactMoney;
import '../../i18n/i18n.dart';

/// Maintenance (GMAO) : demandes et ordres de travail, équipements, plans préventifs, relevés de compteur.
class MaintenanceScreen extends StatefulWidget {
  const MaintenanceScreen({super.key});

  @override
  State<MaintenanceScreen> createState() => _MaintenanceScreenState();
}

class _MaintenanceScreenState extends State<MaintenanceScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();
  final _orders = GlobalKey<RecordsViewState>();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    final staff = s.can('maintenance.view') || s.can('maintenance.work') || s.can('maintenance.manage');
    void changed() {
      _dash.currentState?.reload();
      _orders.currentState?.reload();
    }

    final tabs = <(String, Widget)>[
      ('Ordres de travail', RecordsView(key: _orders, module: 'maintenance', resource: 'orders', emptyIcon: Icons.build_outlined, newLabel: tr('Signaler une panne'), onChanged: changed, actions: orderActions,
          emptyMessage: tr('Une machine en panne ? Signalez-la ici : le responsable maintenance est prévenu tout de suite.'))),
      ('Équipements', RecordsView(module: 'maintenance', resource: 'equipment', emptyIcon: Icons.precision_manufacturing_outlined, newLabel: tr('Nouvel équipement'), onChanged: changed, actions: equipmentActions,
          emptyMessage: tr('Enregistrez vos machines et installations avec leur compteur pour suivre leur entretien.'))),
      if (staff)
        ('Plans préventifs', RecordsView(module: 'maintenance', resource: 'plans', emptyIcon: Icons.event_repeat_outlined, newLabel: tr('Nouveau plan'), onChanged: changed,
            emptyMessage: tr('Tous les 3 mois, toutes les 250 heures… : l\'ordre de travail sera créé à l\'avance.'),
            toolbar: [
              if (s.can('maintenance.manage'))
                OutlinedButton.icon(
                  icon: const Icon(Icons.playlist_add_check, size: 18),
                  label: Text(tr('Créer les OT à échéance')),
                  onPressed: () async {
                    try {
                      final r = await s.api.post('maintenance/generate');
                      if (context.mounted) toast(context, tr('${(r as Map)['data']['created']} ordre(s) de travail préventif(s) créé(s).'));
                      changed();
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                ),
            ])),
      if (staff) ('Relevés de compteur', RecordsView(module: 'maintenance', resource: 'readings', emptyIcon: Icons.speed_outlined, newLabel: tr('Nouveau relevé'), onChanged: changed)),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Maintenance'), subtitle: tr('Pannes, ordres de travail, préventif et historique des équipements')),
        if (staff)
          ModuleDashboard(
            key: _dash,
            path: 'maintenance/dashboard',
            builder: (context, d, _) => StatStrip(tiles: [
              StatTile(label: tr('Équipements en panne'), value: '${d['down']}', detail: tr('sur ${d['equipment']} · disponibilité ${d['availability'] == null ? '—' : number(toDouble(d['availability']), decimals: 1)} %'), icon: Icons.precision_manufacturing_outlined, color: YColors.danger),
              StatTile(label: tr('Interventions ouvertes'), value: '${d['open']}', detail: tr('${d['requests']} demande(s) · ${d['late']} en retard · ${d['urgent']} urgente(s)'), icon: Icons.build_outlined, color: const Color(0xFFD97706)),
              StatTile(label: tr('Délai moyen de réparation'), value: d['mttr'] == null ? '—' : tr('${number(toDouble(d['mttr']), decimals: 1)} h'), detail: tr('pannes des 90 derniers jours'), icon: Icons.timer_outlined),
              StatTile(label: tr('Respect du préventif'), value: d['preventive_rate'] == null ? '—' : '${d['preventive_rate']} %', detail: tr('coût du mois ${compactMoney(toDouble(d['cost_month']), cur)}'), icon: Icons.event_repeat_outlined, color: YColors.success),
            ]),
          ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

Future<void> _act(BuildContext dialog, VoidCallback reload, Map<String, dynamic> row, String action, Map<String, dynamic> body, String done) async {
  try {
    await dialog.read<Session>().api.post('maintenance/orders/${row['id']}/$action', body);
    if (dialog.mounted) {
      Navigator.pop(dialog, <String, dynamic>{action: true});
      toast(dialog, done);
    }
    reload();
  } catch (e) {
    if (dialog.mounted) toast(dialog, errorText(e), error: true);
  }
}

/// Boutons d'un ordre de travail selon son étape et le rôle.
List<Widget> orderActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  final st = row['status'] as String?;
  final manage = s.can('maintenance.manage');
  final mine = row['assignee_id'] == s.user['id'] && s.can('maintenance.work');
  final worker = manage || mine;
  return [
    if (manage && st == 'requested')
      FilledButton.tonalIcon(
        icon: const Icon(Icons.event_available_outlined, size: 18),
        label: Text(tr('Approuver et planifier')),
        onPressed: () => _act(dialog, reload, row, 'approve', {}, tr('Intervention planifiée ; le technicien est prévenu.')),
      ),
    if (worker && (st == 'requested' || st == 'planned' || st == 'on_hold'))
      FilledButton.tonalIcon(icon: const Icon(Icons.play_arrow, size: 18), label: Text(tr('Démarrer')), onPressed: () => _act(dialog, reload, row, 'start', {}, tr('Intervention démarrée.'))),
    if (worker && st == 'in_progress')
      OutlinedButton.icon(
        icon: const Icon(Icons.pause, size: 18),
        label: Text(tr('En attente de pièces')),
        onPressed: () async {
          final note = TextEditingController();
          final ok = await showDialog<bool>(
            context: dialog,
            builder: (c) => YAlertDialog(
              title: Text(tr('Mettre en attente')),
              content: TextField(controller: note, decoration: InputDecoration(labelText: tr('Pièce ou information attendue'))),
              actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Valider')))],
            ),
          );
          if (ok == true && dialog.mounted) await _act(dialog, reload, row, 'hold', {'note': note.text}, tr('Intervention en attente.'));
        },
      ),
    if (worker && st != 'done' && st != 'cancelled')
      FilledButton.icon(
        icon: const Icon(Icons.check, size: 18),
        label: Text(tr('Terminer')),
        onPressed: () async {
          final body = await _finishDialog(dialog, row);
          if (body != null && dialog.mounted) await _act(dialog, reload, row, 'finish', body, tr('Intervention terminée : l\'équipement est remis en service.'));
        },
      ),
    if (manage && st != 'done' && st != 'cancelled')
      TextButton.icon(
        icon: const Icon(Icons.cancel_outlined, size: 18),
        label: Text(tr('Annuler')),
        onPressed: () async {
          if (!await confirm(dialog, tr('Annuler l\'intervention'), tr('L\'ordre de travail est annulé.'), ok: tr('Annuler l\'intervention'))) return;
          if (dialog.mounted) await _act(dialog, reload, row, 'cancel', {}, tr('Intervention annulée.'));
        },
      ),
  ];
}

Future<Map<String, dynamic>?> _finishDialog(BuildContext context, Map<String, dynamic> row) {
  final report = TextEditingController(text: '${row['report'] ?? ''}');
  final parts = TextEditingController(text: '${row['parts_used'] ?? ''}');
  final hours = TextEditingController();
  final labour = TextEditingController();
  final partsCost = TextEditingController();
  final external = TextEditingController();
  final meter = TextEditingController();
  String? cause = row['cause'] as String?;
  const causes = {'wear': 'Usure', 'mechanical': 'Mécanique', 'electrical': 'Électrique', 'hydraulic': 'Hydraulique / fuite', 'misuse': 'Mauvaise utilisation', 'power': 'Coupure / surtension',
    'lack': 'Défaut d\'entretien', 'external': 'Cause extérieure', 'unknown': 'Indéterminée'};
  Widget num(TextEditingController c, String label, [String? help]) => SizedBox(
      width: 200, child: TextField(controller: c, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr(label), helperText: trn(help))));
  return showDialog<Map<String, dynamic>>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr('Terminer l\'intervention')),
        content: SizedBox(
          width: 460,
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: report, maxLines: 4, decoration: InputDecoration(labelText: tr('Rapport : travaux réalisés'))),
              if (row['kind'] == 'corrective')
                DropdownButtonFormField<String>(
                  initialValue: cause,
                  decoration: InputDecoration(labelText: tr('Cause de la panne')),
                  items: [for (final e in causes.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                  onChanged: (v) => set(() => cause = v),
                ),
              TextField(controller: parts, decoration: InputDecoration(labelText: tr('Pièces utilisées'))),
              const SizedBox(height: 8),
              Wrap(spacing: 12, children: [
                num(hours, 'Temps passé (heures)', 'Vide : calculé'),
                num(meter, 'Compteur relevé'),
                num(labour, 'Main-d\'œuvre'),
                num(partsCost, 'Pièces'),
                num(external, 'Sous-traitance'),
              ]),
            ]),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton(
            onPressed: () {
              double? v(TextEditingController x) => x.text.trim().isEmpty ? null : toDouble(x.text);
              Navigator.pop(c, <String, dynamic>{
                'report': report.text, 'parts_used': parts.text, 'cause': ?cause,
                'labour_hours': ?v(hours), 'meter_reading': ?v(meter), 'labour_cost': ?v(labour), 'parts_cost': ?v(partsCost), 'external_cost': ?v(external),
              });
            },
            child: Text(tr('Terminer')),
          ),
        ],
      ),
    ),
  );
}

/// Équipement : historique des interventions.
List<Widget> equipmentActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final cur = dialog.read<Session>().currency;
  return [
    OutlinedButton.icon(
      icon: const Icon(Icons.history, size: 18),
      label: Text(tr('Historique')),
      onPressed: () => showDialog<void>(
        context: dialog,
        builder: (c) => Dialog(
          child: SizedBox(
            width: 1000,
            height: 620,
            child: Column(children: [
              ListTile(
                title: Text(verbatim('${row['title']}')),
                subtitle: Text(tr('12 mois : ${row['breakdowns'] ?? 0} panne(s) · ${row['downtime'] ?? 0} h d\'arrêt · ${money(toDouble(row['cost_year']), cur)}')),
                trailing: IconButton(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close)),
              ),
              Expanded(child: RecordsView(module: 'maintenance', resource: 'orders', filters: {'equipment_id': '${row['id']}'}, newLabel: tr('Signaler une panne'), actions: orderActions, onChanged: reload)),
            ]),
          ),
        ),
      ),
    ),
  ];
}
