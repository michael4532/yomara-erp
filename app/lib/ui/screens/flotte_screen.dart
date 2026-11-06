import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/records_offline.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Parc automobile (service logistique) : demandes et sorties de véhicules, carburant, entretien, échéances.
class FlotteScreen extends StatefulWidget {
  const FlotteScreen({super.key});

  @override
  State<FlotteScreen> createState() => _FlotteScreenState();
}

class _FlotteScreenState extends State<FlotteScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();
  final _trips = GlobalKey<RecordsViewState>();

  bool _staff(Session s) => s.can('flotte.manage') || s.can('flotte.approve') || s.can('flotte.view');

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final staff = _staff(s);
    void changed() {
      _dash.currentState?.reload();
      _trips.currentState?.reload();
    }

    final tabs = <(String, Widget)>[
      if (staff) ('Aujourd\'hui', _Today(dashKey: _dash, onChanged: changed)),
      (staff ? 'Demandes & sorties' : 'Mes sorties', RecordsView(key: _trips, module: 'flotte', resource: 'trips', emptyIcon: Icons.route_outlined, newLabel: tr('Demander un véhicule'), onChanged: changed,
          emptyMessage: tr('Demandez un véhicule : motif, destination, client visité, départ et retour prévus.'), actions: (d, row, reload) => tripActions(d, row, reload))),
      ('Véhicules', RecordsView(module: 'flotte', resource: 'vehicles', emptyIcon: Icons.directions_car_outlined, newLabel: tr('Nouveau véhicule'), onChanged: changed)),
      if (staff || s.can('flotte.request')) ('Carburant', RecordsView(module: 'flotte', resource: 'fuel', emptyIcon: Icons.local_gas_station_outlined, newLabel: tr('Saisir un plein'), onChanged: changed)),
      if (staff) ('Entretien & frais', RecordsView(module: 'flotte', resource: 'costs', emptyIcon: Icons.build_outlined, newLabel: tr('Nouveau frais'), onChanged: changed)),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Parc automobile'), subtitle: tr('Demandes de véhicule, sorties et retours, carburant, entretien')),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

class _Today extends StatelessWidget {
  const _Today({required this.dashKey, required this.onChanged});

  final GlobalKey<ModuleDashboardState> dashKey;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ModuleDashboard(
      key: dashKey,
      path: 'flotte/dashboard',
      builder: (context, d, reload) {
        final out = [for (final x in (d['out_now'] as List)) asMap(x)];
        final watch = [for (final x in (d['watch'] as List)) asMap(x)];
        final byV = [for (final x in (d['by_vehicle'] as List)) asMap(x)];
        final hint = Theme.of(context).hintColor;
        final narrow = MediaQuery.of(context).size.width < 700;
        return RefreshIndicator(
          onRefresh: reload,
          child: ListView(padding: const EdgeInsets.only(top: 14, bottom: 24), children: [
            StatStrip(tiles: [
              StatTile(label: tr('Véhicules disponibles'), value: '${d['available']} / ${d['vehicles']}', detail: tr('${d['garage']} au garage'), icon: Icons.directions_car_outlined, color: YColors.success),
              StatTile(label: tr('En sortie'), value: '${d['out']}', detail: tr('${d['requests']} demande(s) à traiter'), icon: Icons.route_outlined, color: const Color(0xFFD97706)),
              StatTile(label: tr('Km ce mois'), value: number(toDouble(d['km_month'])), detail: tr('${d['trips_month']} sortie(s)'), icon: Icons.speed_outlined),
              StatTile(label: tr('Carburant ce mois'), value: money(toDouble(d['fuel_month']), cur), detail: tr('${number(toDouble(d['liters_month']))} L · frais ${money(toDouble(d['costs_month']), cur)}'), icon: Icons.local_gas_station_outlined, color: YColors.danger),
            ]),
            Padding(
              padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
              child: Wrap(spacing: 14, runSpacing: 14, children: [
                SizedBox(
                  width: narrow ? double.infinity : 560,
                  child: SectionCard(
                    title: tr('Véhicules en sortie'),
                    child: out.isEmpty
                        ? Text(tr('Tous les véhicules sont au parc.'), style: TextStyle(color: hint))
                        : Column(children: [
                            for (final t in out)
                              ListTile(
                                contentPadding: EdgeInsets.zero,
                                leading: CircleAvatar(backgroundColor: (t['late'] == true ? YColors.danger : const Color(0xFFD97706)).withValues(alpha: .12),
                                    child: Icon(Icons.directions_car, color: t['late'] == true ? YColors.danger : const Color(0xFFD97706))),
                                title: Text(tr('${t['vehicle']} · ${t['driver']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                subtitle: Text(tr('${t['title']}${t['destination'] != null ? ' — ${t['destination']}' : ''}\nSorti ${dateTimeFr(t['out_at'])} · retour prévu ${dateTimeFr(t['planned_end'])}')),
                                isThreeLine: true,
                                trailing: t['late'] == true ? StatusChip(tr('En retard'), color: YColors.danger) : null,
                              ),
                          ]),
                  ),
                ),
                SizedBox(
                  width: narrow ? double.infinity : 420,
                  child: Column(children: [
                    SectionCard(
                      title: tr('À surveiller'),
                      child: watch.isEmpty
                          ? Text(tr('Assurances, visites techniques et vidanges à jour.'), style: TextStyle(color: hint))
                          : Column(children: [
                              for (final v in watch)
                                ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  leading: const Icon(Icons.warning_amber_rounded, color: YColors.danger),
                                  title: Text(tr('${v['title']}${v['plate'] != null ? ' (${v['plate']})' : ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                  subtitle: Text(tr('${v['alerts']}')),
                                ),
                            ]),
                    ),
                    const SizedBox(height: 14),
                    SectionCard(
                      title: tr('Carburant par véhicule (mois)'),
                      child: byV.isEmpty
                          ? Text(tr('Aucun plein ce mois.'), style: TextStyle(color: hint))
                          : Column(children: [
                              for (final v in byV)
                                Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 5),
                                  child: Row(children: [
                                    Expanded(child: Text(tr('${v['vehicle']}'))),
                                    SizedBox(
                                      width: 120,
                                      child: ClipRRect(
                                        borderRadius: BorderRadius.circular(4),
                                        child: LinearProgressIndicator(value: toDouble(v['amount']) / toDouble(byV.first['amount']), minHeight: 8, color: YColors.blue, backgroundColor: YColors.blue.withValues(alpha: .1)),
                                      ),
                                    ),
                                    const SizedBox(width: 10),
                                    SizedBox(width: 110, child: Text(tr(money(toDouble(v['amount']), cur)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
                                  ]),
                                ),
                            ]),
                    ),
                  ]),
                ),
              ]),
            ),
          ]),
        );
      },
    );
  }
}

/// Boutons du circuit d'une sortie selon son statut et le rôle de la personne.
List<Widget> tripActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  final staff = s.can('flotte.manage') || s.can('flotte.approve');
  final mine = '${row['requester_id']}' == '${s.user['id']}' || '${row['driver_id']}' == '${s.user['id']}';
  Future<void> act(String action, [Map<String, dynamic> body = const {}]) async {
    try {
      final r = await RecordsOffline.send(s.api, 'POST', 'flotte/trips/${row['id']}/$action', {...body, 'at': DateTime.now().toIso8601String()},
          '${const {'approve': 'Approbation', 'refuse': 'Refus', 'checkout': 'Départ', 'return': 'Retour', 'cancel': 'Annulation'}[action]} : ${row['title']}');
      if (dialog.mounted) {
        if ((r as Map?)?['offline'] == true) toast(dialog, tr('Pas de connexion : enregistré sur l\'appareil (heure conservée), envoyé au retour du réseau.'));
        Navigator.pop(dialog, <String, dynamic>{'action': action});
      }
      reload();
    } catch (e) {
      if (dialog.mounted) toast(dialog, errorText(e), error: true);
    }
  }

  final st = row['status'];
  return [
    if (['requested', 'approved'].contains(st) && (mine || staff))
      TextButton(onPressed: () async => await confirm(dialog, tr('Annuler la sortie'), tr('Annuler cette demande de véhicule ?'), ok: tr('Annuler la sortie'), danger: true) ? act('cancel') : null, child: Text(tr('Annuler la sortie'))),
    if (['requested', 'approved'].contains(st) && staff) ...[
      OutlinedButton.icon(
        icon: const Icon(Icons.block, size: 18),
        label: Text(tr('Refuser')),
        onPressed: () async {
          final note = await _ask(dialog, 'Refuser la demande', 'Motif (envoyé au demandeur)');
          if (note != null) act('refuse', {'note': note});
        },
      ),
      FilledButton.tonalIcon(
        icon: const Icon(Icons.check, size: 18),
        label: Text(tr(st == 'approved' ? 'Changer de véhicule' : 'Approuver')),
        onPressed: () async {
          final r = await showDialog<Map<String, dynamic>>(context: dialog, builder: (_) => _ApproveDialog(trip: row));
          if (r != null) act('approve', r);
        },
      ),
    ],
    if (st == 'approved' && (mine || staff))
      FilledButton.icon(
        icon: const Icon(Icons.logout, size: 18),
        label: Text(tr('Départ')),
        onPressed: () async {
          final km = await _ask(dialog, 'Départ du véhicule', 'Compteur au départ (km)', number: true, optional: true);
          if (km != null) act('checkout', {if (km.isNotEmpty) 'km': int.tryParse(km.replaceAll(' ', ''))});
        },
      ),
    if (st == 'out' && (mine || staff))
      FilledButton.icon(
        icon: const Icon(Icons.login, size: 18),
        label: Text(tr('Retour')),
        onPressed: () async {
          final r = await showFormDialog<Map<String, dynamic>>(dialog, title: 'Retour du véhicule', builder: (_) => _ReturnDialog(trip: row));
          if (r != null) act('return', r);
        },
      ),
  ];
}

Future<String?> _ask(BuildContext context, String title, String label, {bool number = false, bool optional = false}) {
  final c = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Text(tr(title)),
      content: SizedBox(
        width: 380,
        child: TextField(controller: c, autofocus: true, keyboardType: number ? TextInputType.number : null, decoration: InputDecoration(labelText: trn(label), helperText: trn(optional ? 'Laisser vide : dernier relevé connu' : null))),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
        FilledButton(onPressed: () => Navigator.pop(d, c.text.trim()), child: Text(tr('Valider'))),
      ],
    ),
  );
}

class _ApproveDialog extends StatefulWidget {
  const _ApproveDialog({required this.trip});

  final Map<String, dynamic> trip;

  @override
  State<_ApproveDialog> createState() => _ApproveDialogState();
}

class _ApproveDialogState extends State<_ApproveDialog> {
  List<Map<String, dynamic>>? _vehicles;
  int? _vehicle;
  final _note = TextEditingController();

  @override
  void initState() {
    super.initState();
    _vehicle = widget.trip['vehicle_id'] as int?;
    context.read<Session>().api.get('flotte/availability', query: {'start': '${widget.trip['planned_start']}', 'end': '${widget.trip['planned_end']}', 'except': '${widget.trip['id']}'}).then((r) {
      if (mounted) setState(() => _vehicles = [for (final x in ((r as Map)['data'] as List)) asMap(x)]);
    });
  }

  @override
  Widget build(BuildContext context) => YAlertDialog(
        title: Text(tr('Attribuer un véhicule')),
        content: SizedBox(
          width: 460,
          child: _vehicles == null
              ? const SizedBox(height: 120, child: Center(child: CircularProgressIndicator()))
              : Column(mainAxisSize: MainAxisSize.min, children: [
                  Text(tr('Du ${dateTimeFr(widget.trip['planned_start'])} au ${dateTimeFr(widget.trip['planned_end'])}'), style: TextStyle(color: Theme.of(context).hintColor)),
                  const SizedBox(height: 8),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxHeight: 300),
                    child: ListView(shrinkWrap: true, children: [
                      for (final v in _vehicles!)
                        ListTile(
                          enabled: v['free'] == true,
                          selected: _vehicle == v['id'],
                          onTap: () => setState(() => _vehicle = v['id'] as int),
                          leading: Icon(_vehicle == v['id'] ? Icons.radio_button_checked : Icons.radio_button_off, color: _vehicle == v['id'] ? YColors.blue : null),
                          title: Text(tr('${v['title']}${v['plate'] != null ? ' · ${v['plate']}' : ''}')),
                          subtitle: Text(tr(v['free'] == true ? 'Libre' : (v['status'] == 'maintenance' ? 'Au garage' : 'Déjà réservé sur ce créneau'))),
                          trailing: Icon(Icons.directions_car, color: v['free'] == true ? YColors.success : YColors.muted),
                        ),
                    ]),
                  ),
                  TextField(controller: _note, decoration: InputDecoration(labelText: tr('Message au demandeur (facultatif)'))),
                ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          FilledButton(onPressed: _vehicle == null ? null : () => Navigator.pop(context, {'vehicle_id': _vehicle, if (_note.text.trim().isNotEmpty) 'note': _note.text.trim()}), child: Text(tr('Approuver'))),
        ],
      );
}

class _ReturnDialog extends StatefulWidget {
  const _ReturnDialog({required this.trip});

  final Map<String, dynamic> trip;

  @override
  State<_ReturnDialog> createState() => _ReturnDialogState();
}

class _ReturnDialogState extends State<_ReturnDialog> {
  final _km = TextEditingController();
  final _amount = TextEditingController();
  final _liters = TextEditingController();
  final _report = TextEditingController();
  String _level = 'half';
  bool _issue = false;

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
    padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: _km, autofocus: true, keyboardType: TextInputType.number,
            decoration: InputDecoration(labelText: tr('Compteur au retour (km) *'), helperText: tr('Au départ : ${number(toDouble(widget.trip['out_km']))} km'))),
        const SizedBox(height: 10),
        DropdownButtonFormField<String>(
          initialValue: _level,
          decoration: InputDecoration(labelText: tr('Niveau de carburant')),
          items: [
            DropdownMenuItem(value: 'empty', child: Text(tr('Réserve'))),
            DropdownMenuItem(value: 'quarter', child: Text('1/4')),
            DropdownMenuItem(value: 'half', child: Text('1/2')),
            DropdownMenuItem(value: 'three', child: Text('3/4')),
            DropdownMenuItem(value: 'full', child: Text(tr('Plein'))),
          ],
          onChanged: (v) => setState(() => _level = v ?? 'half'),
        ),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: TextField(controller: _amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Carburant payé (montant)')))),
          const SizedBox(width: 10),
          Expanded(child: TextField(controller: _liters, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Litres')))),
        ]),
        const SizedBox(height: 10),
        TextField(controller: _report, minLines: 2, maxLines: 5, decoration: InputDecoration(labelText: tr('Compte rendu (clients vus, incidents…)'))),
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          value: _issue,
          onChanged: (v) => setState(() => _issue = v == true),
          title: Text(tr('Signaler un problème sur le véhicule')),
          subtitle: Text(tr('Le véhicule passe « au garage » et le parc est prévenu')),
        ),
      ]),
      const SizedBox(height: 20),
      Align(
        alignment: Alignment.centerRight,
        child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          FilledButton(
            onPressed: () {
              final km = int.tryParse(_km.text.replaceAll(' ', ''));
              if (km == null) return toast(context, tr('Indiquez le compteur au retour.'), error: true);
              Navigator.pop(context, {
                'km': km, 'fuel_level': _level, 'issue': _issue,
                if (_report.text.trim().isNotEmpty) 'report': _report.text.trim(),
                if (toDouble(_amount.text) > 0) 'fuel_amount': toDouble(_amount.text),
                if (toDouble(_liters.text) > 0) 'fuel_liters': toDouble(_liters.text),
              });
            },
            child: Text(tr('Enregistrer le retour')),
          ),
        ]),
      ),
    ]),
  );
}
