import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/records_offline.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Espace du service commercial : performances et objectifs, visites et comptes rendus, tournées.
class CommercialScreen extends StatefulWidget {
  const CommercialScreen({super.key});

  @override
  State<CommercialScreen> createState() => _CommercialScreenState();
}

class _CommercialScreenState extends State<CommercialScreen> {
  final _perf = GlobalKey<_PerformanceState>();

  @override
  Widget build(BuildContext context) {
    void changed() => _perf.currentState?._load();
    final tabs = <(String, Widget)>[
      ('Performances', _Performance(key: _perf)),
      ('Visites & comptes rendus', RecordsView(module: 'commercial', resource: 'visits', emptyIcon: Icons.storefront_outlined, newLabel: tr('Planifier une visite'), onChanged: changed,
          emptyMessage: tr('Planifiez vos visites puis remplissez le compte rendu : résultat, montant en jeu et prochaine étape.'))),
      ('Tournées', RecordsView(module: 'commercial', resource: 'tours', emptyIcon: Icons.route_outlined, newLabel: tr('Nouvelle tournée'), onChanged: changed, actions: (d, row, reload) => _tourActions(d, row, reload))),
      ('Objectifs', RecordsView(module: 'commercial', resource: 'targets', emptyIcon: Icons.flag_outlined, newLabel: tr('Fixer un objectif'), onChanged: changed)),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Force de vente & terrain'), subtitle: tr('Objectifs, classement de l\'équipe, tournées, visites et comptes rendus')),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }

  List<Widget> _tourActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
    final s = dialog.read<Session>();
    if (!s.hasModule('flotte') || (row['vehicle'] != null && !'${row['vehicle']}'.contains('Refusée') && !'${row['vehicle']}'.contains('Annulée'))) return const [];
    return [
      OutlinedButton.icon(
        icon: const Icon(Icons.directions_car_outlined, size: 18),
        label: Text(tr('Demander un véhicule')),
        onPressed: () async {
          var start = const TimeOfDay(hour: 8, minute: 0), end = const TimeOfDay(hour: 17, minute: 0);
          final pass = TextEditingController();
          String fmt(TimeOfDay t) => '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
          final ok = await showDialog<bool>(
            context: dialog,
            builder: (c) => StatefulBuilder(
              builder: (c, set) => YAlertDialog(
                title: Text(tr('Véhicule pour la tournée')),
                content: SizedBox(
                  width: 400,
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Text(tr('${row['title']} — ${dateFr(row['date'])}'), style: TextStyle(color: Theme.of(c).hintColor)),
                    const SizedBox(height: 10),
                    Row(children: [
                      Expanded(child: OutlinedButton.icon(onPressed: () async {
                        final t = await showTimePicker(context: c, initialTime: start);
                        if (t != null) set(() => start = t);
                      }, icon: const Icon(Icons.logout, size: 18), label: Text(tr('Départ ${fmt(start)}')))),
                      const SizedBox(width: 10),
                      Expanded(child: OutlinedButton.icon(onPressed: () async {
                        final t = await showTimePicker(context: c, initialTime: end);
                        if (t != null) set(() => end = t);
                      }, icon: const Icon(Icons.login, size: 18), label: Text(tr('Retour ${fmt(end)}')))),
                    ]),
                    const SizedBox(height: 10),
                    TextField(controller: pass, decoration: InputDecoration(labelText: tr('Accompagnants (facultatif)'))),
                    const SizedBox(height: 8),
                    Text(tr('La demande part au responsable du parc avec la liste des visites de la tournée.'), style: TextStyle(fontSize: 12.5, color: Theme.of(c).hintColor)),
                  ]),
                ),
                actions: [
                  TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                  FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Envoyer la demande'))),
                ],
              ),
            ),
          );
          if (ok != true) return;
          try {
            final r = await s.api.post('commercial/tours/${row['id']}/vehicle', {'start': fmt(start), 'end': fmt(end), if (pass.text.trim().isNotEmpty) 'passengers': pass.text.trim()});
            if (dialog.mounted) {
              Navigator.pop(dialog, <String, dynamic>{'vehicle': true});
              toast(dialog, tr('Demande de véhicule ${(r as Map)['data']['number']} envoyée au parc automobile.'));
            }
            reload();
          } catch (e) {
            if (dialog.mounted) toast(dialog, errorText(e), error: true);
          }
        },
      ),
    ];
  }
}

class _Performance extends StatefulWidget {
  const _Performance({super.key});

  @override
  State<_Performance> createState() => _PerformanceState();
}

class _PerformanceState extends State<_Performance> {
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await RecordsOffline.get(context.read<Session>().api, 'commercial/dashboard', query: {'month': '${_month.year}-${_month.month.toString().padLeft(2, '0')}'});
      if (mounted) setState(() => (_d = asMap((r as Map)['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    final d = _d!;
    final cur = context.read<Session>().currency;
    final team = [for (final x in (d['team'] as List)) asMap(x)];
    final today = [for (final x in (d['today'] as List)) asMap(x)];
    final target = toDouble(d['target']);
    final narrow = MediaQuery.of(context).size.width < 700;
    final hint = Theme.of(context).hintColor;
    final outcomes = asMap(d['outcomes']);
    const outLabels = {'order': 'Commandes', 'quote': 'Devis demandés', 'interested': 'Intéressés', 'follow_up': 'À relancer', 'not_interested': 'Pas intéressés', 'payment': 'Paiements', 'complaint': 'Réclamations'};
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
        Padding(
          padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 12, 24, 8),
          child: Row(children: [
            IconButton(onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month - 1), _load())), icon: const Icon(Icons.chevron_left)),
            Text(tr('${d['label']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            IconButton(onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month + 1), _load())), icon: const Icon(Icons.chevron_right)),
          ]),
        ),
        StatStrip(tiles: [
          StatTile(label: tr('Chiffre d\'affaires HT'), value: money(toDouble(d['revenue']), cur), detail: target > 0 ? '${(toDouble(d['revenue']) / target * 100).round()} % de ${money(target, cur)}' : 'aucun objectif fixé', icon: Icons.trending_up, color: YColors.success),
          StatTile(label: tr('Visites faites'), value: '${d['visits']}', detail: tr('${d['reports_due']} compte(s) rendu(s) en attente'), icon: Icons.storefront_outlined),
          StatTile(label: tr('Nouveaux clients'), value: '${d['customers']}', detail: tr('première facture ce mois'), icon: Icons.person_add_alt, color: const Color(0xFF9333EA)),
          StatTile(label: tr('Visites aujourd\'hui'), value: '${today.length}', detail: tr('${today.where((v) => v['status'] == 'done').length} faite(s)'), icon: Icons.today_outlined, color: const Color(0xFFD97706)),
        ]),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
          child: Wrap(spacing: 14, runSpacing: 14, children: [
            SizedBox(
              width: narrow ? double.infinity : 640,
              child: SectionCard(
                title: tr('Classement de l\'équipe'),
                child: team.isEmpty
                    ? Text(tr('Aucun commercial. Donnez le droit « terrain » aux commerciaux et fixez leurs objectifs.'), style: TextStyle(color: hint))
                    : Column(children: [
                        for (var i = 0; i < team.length; i++) _seller(i + 1, team[i], cur),
                      ]),
              ),
            ),
            SizedBox(
              width: narrow ? double.infinity : 400,
              child: Column(children: [
                SectionCard(
                  title: tr('Visites du jour'),
                  child: today.isEmpty
                      ? Text(tr('Aucune visite prévue aujourd\'hui.'), style: TextStyle(color: hint))
                      : Column(children: [
                          for (final v in today)
                            ListTile(
                              dense: true,
                              contentPadding: EdgeInsets.zero,
                              leading: Icon(v['status'] == 'done' ? Icons.check_circle : Icons.schedule, color: v['status'] == 'done' ? YColors.success : YColors.blue),
                              title: Text(tr('${v['client'] ?? '—'} · ${v['title']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                              subtitle: Text(tr('${dateTimeFr(v['planned_at']).split(' ').last} · ${v['seller']}${v['address'] != null ? ' · ${v['address']}' : ''}')),
                            ),
                        ]),
                ),
                const SizedBox(height: 14),
                SectionCard(
                  title: tr('Résultats des visites'),
                  child: outcomes.isEmpty
                      ? Text(tr('Pas encore de compte rendu ce mois.'), style: TextStyle(color: hint))
                      : Column(children: [
                          for (final e in outcomes.entries)
                            Padding(
                              padding: const EdgeInsets.symmetric(vertical: 4),
                              child: Row(children: [Expanded(child: Text(tr(outLabels[e.key] ?? e.key))), Text(tr('${e.value}'), style: const TextStyle(fontWeight: FontWeight.w700))]),
                            ),
                        ]),
                ),
              ]),
            ),
          ]),
        ),
      ]),
    );
  }

  Widget _seller(int rank, Map<String, dynamic> r, String cur) {
    final hint = Theme.of(context).hintColor;
    final p = r['progress'] as num?;
    final color = p == null ? YColors.blue : (p >= 100 ? YColors.success : (p >= 60 ? const Color(0xFFD97706) : YColors.danger));
    String vs(dynamic done, dynamic goal) => goal == null ? '$done' : '$done / $goal';
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(width: 26, child: Text(tr('$rank'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: rank == 1 ? const Color(0xFFD97706) : hint))),
        InitialsAvatar('${r['name']}', size: 36),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
              Text(tr(money(toDouble(r['revenue']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 5),
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(value: p == null ? 0 : (p / 100).clamp(0, 1).toDouble(), minHeight: 7, color: color, backgroundColor: color.withValues(alpha: .12)),
            ),
            const SizedBox(height: 4),
            Text(
              tr('${p == null ? 'Pas d\'objectif' : '$p % de ${money(toDouble(r['revenue_target']), cur)}'} · visites ${vs(r['visits'], r['visits_target'])} · clients ${vs(r['customers'], r['customers_target'])} · devis ${vs(r['quotes'], r['quotes_target'])}'
              '${toDouble(r['pipeline']) > 0 ? ' · opportunités ${money(toDouble(r['pipeline']), cur)}' : ''}'),
              style: TextStyle(color: hint, fontSize: 12),
            ),
          ]),
        ),
      ]),
    );
  }
}
