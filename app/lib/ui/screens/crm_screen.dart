import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/custom_fields_editor.dart';
import '../widgets/filter_bar.dart';
import 'crm_extras.dart';
import 'document_editor.dart';
import 'documents_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const leadSources = {
  'website': 'Site web',
  'referral': 'Recommandation',
  'social': 'Réseaux sociaux',
  'whatsapp': 'WhatsApp',
  'phone': 'Appel entrant',
  'event': 'Salon / événement',
  'prospecting': 'Prospection',
  'campaign': 'Campagne marketing',
  'ads': 'Publicité en ligne',
  'landing': 'Page de capture',
  'other': 'Autre',
};

const activityTypes = {'call': 'Appel', 'meeting': 'Rendez-vous', 'visit': 'Visite', 'email': 'E-mail', 'whatsapp': 'WhatsApp', 'task': 'Tâche'};

const activityIcons = {
  'call': Icons.call_outlined,
  'meeting': Icons.groups_outlined,
  'visit': Icons.directions_car_outlined,
  'email': Icons.mail_outline,
  'whatsapp': Icons.chat_outlined,
  'task': Icons.task_alt,
};

const lostReasons = ['Prix trop élevé', 'Budget insuffisant', 'Choix d\'un concurrent', 'Projet abandonné', 'Pas de réponse', 'Délai trop long'];

Color _hex(String? c, [Color fallback = YColors.muted]) {
  if (c == null || !c.startsWith('#') || c.length != 7) return fallback;
  return Color(int.parse('FF${c.substring(1)}', radix: 16));
}

List<Map<String, dynamic>> crmStages() => LocalDb.all('crm_stages')..sort((a, b) => ((a['sequence'] as num?) ?? 0).compareTo((b['sequence'] as num?) ?? 0));

DateTime? _dt(dynamic v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();

/// Utilisateurs de la société pour l'attribution (mis en cache pour le hors ligne).
Future<List<Map<String, dynamic>>> crmUsers(Session s) async {
  List<Map<String, dynamic>> cached() => [for (final u in (LocalDb.state().get('crm_users') as List? ?? const [])) asMap(u)];
  try {
    final r = await s.api.get('crm/users');
    final users = List<Map<String, dynamic>>.from(r['users']);
    await LocalDb.state().put('crm_users', users);
    return users;
  } catch (_) {
    final c = cached();
    return c.isEmpty
        ? [
            {'id': s.user['id'], 'name': s.user['name']},
          ]
        : c;
  }
}

/// Champs dérivés de l'étape, appliqués aussi en local pour que l'écran reste juste hors ligne
/// (le serveur refait le même calcul à la synchronisation).
Map<String, dynamic> stageFields(Map<String, dynamic> stage, {String? lostReason}) {
  final kind = stage['kind'] as String? ?? 'open';
  final now = DateTime.now().toIso8601String();
  return {
    'stage_id': stage['id'],
    'probability': stage['probability'],
    'status': kind,
    'won_at': kind == 'won' ? now : null,
    'lost_at': kind == 'lost' ? now : null,
    'lost_reason': kind == 'lost' ? lostReason : null,
  };
}

/// Déplace une opportunité vers une étape (demande le motif si l'étape est « perdu »).
Future<bool> moveLead(BuildContext context, Map<String, dynamic> lead, Map<String, dynamic> stage) async {
  if (lead['stage_id'] == stage['id']) return false;
  String? reason;
  if (stage['kind'] == 'lost') {
    reason = await _askLostReason(context);
    if (reason == null) return false;
  }
  if (!context.mounted) return false;
  final f = stageFields(stage, lostReason: reason);
  await context.read<SyncEngine>().save('crm_leads', {'id': lead['id'], 'stage_id': f['stage_id'], 'lost_reason': ?reason});
  // Miroir local des champs calculés par le serveur
  final local = LocalDb.get('crm_leads', lead['id'] as String);
  if (local != null) await LocalDb.put('crm_leads', {...local, ...f});
  if (context.mounted && stage['kind'] == 'won') toast(context, tr('Bravo ! Opportunité gagnée : ${lead['title']}'));
  return true;
}

Future<String?> _askLostReason(BuildContext context) {
  final c = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Text(tr('Pourquoi cette opportunité est-elle perdue ?')),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [for (final r in lostReasons) ActionChip(label: Text(tr(r)), onPressed: () => Navigator.pop(d, r))],
          ),
          const SizedBox(height: 12),
          TextField(
            controller: c,
            decoration: InputDecoration(hintText: tr('Autre motif…')),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
        FilledButton(onPressed: () => Navigator.pop(d, c.text.trim().isEmpty ? 'Autre' : c.text.trim()), child: Text(tr('Valider'))),
      ],
    ),
  );
}

/// Activité suivante (non faite) d'une opportunité.
Map<String, dynamic>? nextActivity(String leadId, List<Map<String, dynamic>> activities) {
  final list = activities.where((a) => a['lead_id'] == leadId && a['done'] != true && a['due_at'] != null).toList()..sort((a, b) => '${a['due_at']}'.compareTo('${b['due_at']}'));
  return list.isEmpty ? null : list.first;
}

// ===========================================================================
// Écran principal
// ===========================================================================

class CrmScreen extends StatefulWidget {
  const CrmScreen({super.key});

  @override
  State<CrmScreen> createState() => _CrmScreenState();
}

class _CrmScreenState extends State<CrmScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 5, vsync: this)..addListener(_onTab);
  String _q = '';
  bool _mine = false;
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  void _onTab() {
    if (!_tabs.indexIsChanging && mounted) setState(() {});
  }

  @override
  void dispose() {
    _tabs.removeListener(_onTab);
    _tabs.dispose();
    super.dispose();
  }

  bool _match(Map<String, dynamic> l, int? me) {
    if (_mine && l['owner_id'] != me) return false;
    final ow = _f['owner'], src = _f['source'], st = _f['stage'], pr = _f['priority'], cl = _f['client'];
    if (ow != null && '${l['owner_id']}' != ow) return false;
    if (src != null && '${l['source'] ?? ''}' != src) return false;
    if (st != null && '${l['stage_id']}' != st) return false;
    if (pr != null && '${(l['priority'] as num?)?.toInt() ?? 0}' != pr) return false;
    if (cl != null && '${l['partner_id']}' != cl) return false;
    if (_period != null && !_period!.contains(l['expected_close_date'])) return false;
    if (_q.isEmpty) return true;
    return '${l['title']} ${l['company_name'] ?? ''} ${l['contact_name'] ?? ''} ${l['phone'] ?? ''} ${l['tags'] ?? ''}'.toLowerCase().contains(_q);
  }

  bool _matchActivity(Map<String, dynamic> a, int? me) {
    if (_mine && a['owner_id'] != me) return false;
    final ow = _f['owner'], ty = _f['atype'], cl = _f['client'];
    if (ow != null && '${a['owner_id']}' != ow) return false;
    if (ty != null && '${a['type']}' != ty) return false;
    if (cl != null && '${a['partner_id']}' != cl) return false;
    if (_period != null && !_period!.contains(_dt(a['due_at']))) return false;
    return _q.isEmpty || '${a['subject']}'.toLowerCase().contains(_q);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('crm.manage');
    final me = s.user['id'] as int?;
    final narrow = MediaQuery.of(context).size.width < 700;

    return Column(
      children: [
        PageHeader(
          title: tr('CRM'),
          subtitle: tr('Opportunités, relances et prévisions · fonctionne hors ligne'),
          actions: [
            if (manage) ...[
              OutlinedButton.icon(onPressed: () => editActivity(context), icon: const Icon(Icons.add_alarm), label: Text(tr('Activité'))),
              FilledButton.icon(onPressed: () => editLead(context), icon: const Icon(Icons.add), label: Text(tr('Opportunité'))),
            ],
          ],
        ),
        TabBar(
          controller: _tabs,
          isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
          tabAlignment: TabAlignment.start,
          tabs: [
            Tab(text: tr('Pipeline')),
            Tab(text: tr('Liste')),
            Tab(text: tr('Activités')),
            Tab(text: tr('Statistiques')),
            Tab(text: tr('Relances')),
          ],
        ),
        Expanded(
          child: ValueListenableBuilder(
            valueListenable: LocalDb.listen('crm_leads'),
            builder: (context, _, _) => ValueListenableBuilder(
              valueListenable: LocalDb.listen('crm_activities'),
              builder: (context, _, _) => ValueListenableBuilder(
                valueListenable: LocalDb.listen('crm_stages'),
                builder: (context, _, _) {
                  final allLeads = LocalDb.all('crm_leads');
                  final allActivities = LocalDb.all('crm_activities');
                  final leads = allLeads.where((l) => _match(l, me)).toList();
                  final activities = allActivities.where((a) => !_mine || a['owner_id'] == me).toList();
                  final shownActivities = allActivities.where((a) => _matchActivity(a, me)).toList();
                  final stages = crmStages();
                  final tab = _tabs.index;
                  final users = {
                    for (final u in (LocalDb.state().get('crm_users') as List? ?? const [])) '${asMap(u)['id']}': '${asMap(u)['name']}',
                    if (me != null) '$me': '${s.user['name'] ?? 'Moi'}',
                  };
                  final source = tab == 2 ? allActivities : allLeads;
                  final owners = {for (final r in source) if (r['owner_id'] != null) '${r['owner_id']}': users['${r['owner_id']}'] ?? 'Utilisateur ${r['owner_id']}'};
                  final partners = {for (final p in LocalDb.all('partners')) '${p['id']}': '${p['name']}'};
                  final clients = {for (final r in source) if (partners.containsKey('${r['partner_id']}')) '${r['partner_id']}': partners['${r['partner_id']}']!};
                  final bar = FilterBar(
                    padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 12, narrow ? 16 : 24, 4),
                    search: _q,
                    searchHint: tab == 2 ? 'Objet de l\'activité…' : 'Opportunité, société, contact, étiquette…',
                    onSearch: (v) => setState(() => _q = v.toLowerCase()),
                    filters: [
                      if (tab < 3) FilterSpec('owner', 'Responsable', owners, icon: Icons.person_outline),
                      if (tab < 3) FilterSpec('client', 'Client', clients, icon: Icons.storefront_outlined),
                      if (tab < 2) ...[
                        FilterSpec('stage', 'Étape', {for (final st in stages) '${st['id']}': '${st['name']}'}, icon: Icons.view_kanban_outlined),
                        FilterSpec('source', 'Source', {for (final l in allLeads) if (leadSources.containsKey(l['source'])) '${l['source']}': leadSources[l['source']]!}, icon: Icons.campaign_outlined),
                        const FilterSpec('priority', 'Priorité', {'0': 'Aucune', '1': '★', '2': '★★', '3': '★★★'}, icon: Icons.star_outline_rounded),
                      ],
                      if (tab == 2) FilterSpec('atype', 'Type', activityTypes, icon: Icons.event_outlined),
                    ],
                    values: _f,
                    onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                    showPeriod: tab < 3,
                    period: _period,
                    onPeriod: (v) => setState(() => _period = v),
                    count: switch (tab) { 0 => leads.length, 2 => shownActivities.length, _ => null },
                    countLabel: tab == 2 ? 'activité' : 'opportunité',
                    onReset: () => setState(() => (_q = '', _f = {}, _period = null, _mine = false)),
                    trailing: [FilterChip(label: Text(tr('Mes affaires')), selected: _mine, onSelected: (v) => setState(() => _mine = v))],
                  );
                  return Column(children: [
                    if (tab != 4) bar,
                    if (tab < 3 && _period != null)
                      Padding(
                        padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
                        child: Align(alignment: Alignment.centerLeft, child: Text(tr(tab == 2 ? 'Période appliquée à l\'échéance des activités' : 'Période appliquée à la date de clôture prévue'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))),
                      ),
                    Expanded(
                      child: TabBarView(
                        controller: _tabs,
                        physics: const NeverScrollableScrollPhysics(),
                        children: [
                          _Pipeline(stages: stages, leads: leads, activities: activities, manage: manage),
                          _LeadList(stages: stages, leads: leads, manage: manage),
                          _ActivityList(activities: shownActivities, manage: manage, filtered: shownActivities.length != activities.length || _q.isNotEmpty),
                          _Stats(ownerId: _mine ? me : null),
                          CrmSequencesView(manage: manage),
                        ],
                      ),
                    ),
                  ]);
                },
              ),
            ),
          ),
        ),
      ],
    );
  }
}

// ===========================================================================
// Pipeline (Kanban)
// ===========================================================================

class _Pipeline extends StatelessWidget {
  const _Pipeline({required this.stages, required this.leads, required this.activities, required this.manage});

  final List<Map<String, dynamic>> stages;
  final List<Map<String, dynamic>> leads;
  final List<Map<String, dynamic>> activities;
  final bool manage;

  @override
  Widget build(BuildContext context) {
    if (stages.isEmpty) {
      return EmptyState(icon: Icons.view_kanban_outlined, title: tr('Pipeline en préparation'), message: tr('Les étapes arrivent à la prochaine synchronisation.'));
    }
    final cur = context.read<Session>().currency;
    final recent = DateTime.now().subtract(const Duration(days: 60));
    final touch = MediaQuery.of(context).size.width < 900;

    return ListView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 16),
      children: [
        for (final st in stages)
          Builder(
            builder: (context) {
              var rows = leads.where((l) => l['stage_id'] == st['id']).toList();
              // Colonnes gagné / perdu : seulement les 60 derniers jours
              if (st['kind'] != 'open') {
                rows = rows.where((l) => (_dt(l['won_at'] ?? l['lost_at'] ?? l['updated_at']) ?? DateTime.now()).isAfter(recent)).toList();
              }
              rows.sort((a, b) {
                final p = ((b['priority'] as num?) ?? 0).compareTo((a['priority'] as num?) ?? 0);
                return p != 0 ? p : '${a['expected_close_date'] ?? '9'}'.compareTo('${b['expected_close_date'] ?? '9'}');
              });
              final total = rows.fold<double>(0, (s, l) => s + toDouble(l['expected_revenue']));
              final color = _hex(st['color'] as String?);

              return DragTarget<Map<String, dynamic>>(
                onWillAcceptWithDetails: (d) => manage && d.data['stage_id'] != st['id'],
                onAcceptWithDetails: (d) => moveLead(context, d.data, st),
                builder: (context, candidate, _) => Container(
                  width: 290,
                  margin: const EdgeInsets.only(right: 12),
                  decoration: BoxDecoration(
                    color: candidate.isNotEmpty ? color.withValues(alpha: .10) : Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .45),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: candidate.isNotEmpty ? color : Colors.transparent, width: 2),
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Container(height: 4, color: color),
                      Container(
                        padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Text(tr('${st['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                                ),
                                StatusChip('${rows.length}', color: color),
                              ],
                            ),
                            const SizedBox(height: 2),
                            Text(tr(st['kind'] == 'open' ? '${money(total, cur)} · ${st['probability']} %' : money(total, cur)), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                          ],
                        ),
                      ),
                      Expanded(
                        child: rows.isEmpty
                            ? Center(
                                child: Text(tr(manage ? 'Glissez une carte ici' : '—'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                              )
                            : ListView.builder(
                                padding: const EdgeInsets.fromLTRB(10, 0, 10, 10),
                                itemCount: rows.length,
                                itemBuilder: (_, i) {
                                  final card = _LeadCard(lead: rows[i], next: nextActivity(rows[i]['id'] as String, activities), stages: stages, manage: manage);
                                  if (!manage) return card;
                                  final feedback = Material(
                                    color: Colors.transparent,
                                    child: SizedBox(width: 270, child: Opacity(opacity: .9, child: card)),
                                  );
                                  return touch
                                      ? LongPressDraggable<Map<String, dynamic>>(
                                          data: rows[i],
                                          feedback: feedback,
                                          childWhenDragging: Opacity(opacity: .3, child: card),
                                          child: card,
                                        )
                                      : Draggable<Map<String, dynamic>>(
                                          data: rows[i],
                                          feedback: feedback,
                                          childWhenDragging: Opacity(opacity: .3, child: card),
                                          child: card,
                                        );
                                },
                              ),
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
      ],
    );
  }
}

class _LeadCard extends StatelessWidget {
  const _LeadCard({required this.lead, required this.next, required this.stages, required this.manage});

  final Map<String, dynamic> lead;
  final Map<String, dynamic>? next;
  final List<Map<String, dynamic>> stages;
  final bool manage;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final due = _dt(next?['due_at']);
    final late = due != null && due.isBefore(DateTime.now());
    final who = [lead['company_name'], lead['contact_name']].where((e) => e != null && '$e'.isNotEmpty).join(' · ');
    final priority = (lead['priority'] as num?)?.toInt() ?? 0;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => editLead(context, lead),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(tr('${lead['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  ),
                  if (lead['_local'] == true)
                    Tooltip(
                      message: tr('En attente de synchronisation'),
                      child: Icon(Icons.cloud_upload_outlined, size: 15, color: YColors.warning),
                    ),
                ],
              ),
              if (who.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(
                    tr(who),
                    style: TextStyle(color: hint, fontSize: 12),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Text(tr(money(toDouble(lead['expected_revenue']), cur)), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                  const Spacer(),
                  for (var i = 0; i < 3; i++) Icon(i < priority ? Icons.star_rounded : Icons.star_outline_rounded, size: 16, color: i < priority ? YColors.yellow : hint.withValues(alpha: .4)),
                ],
              ),
              if (next != null || lead['quote_id'] != null || ((lead['score'] as num?) ?? 0) > 0) ...[
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  children: [
                    LeadScoreTag(lead: lead),
                    if (next != null)
                      _MiniTag(
                        icon: activityIcons[next!['type']] ?? Icons.event,
                        label: due == null ? '${next!['subject']}' : DateFormat('dd/MM HH:mm', I18n.dateLocale).format(due),
                        color: late ? YColors.danger : YColors.blue,
                      ),
                    if (lead['quote_id'] != null) _MiniTag(icon: Icons.request_quote_outlined, label: tr('Devis'), color: YColors.success),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _MiniTag extends StatelessWidget {
  const _MiniTag({required this.icon, required this.label, required this.color});

  final IconData icon;
  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
    decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(8)),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 13, color: color),
        const SizedBox(width: 4),
        Text(
          tr(label),
          style: TextStyle(fontSize: 11, color: color, fontWeight: FontWeight.w600),
        ),
      ],
    ),
  );
}

// ===========================================================================
// Liste
// ===========================================================================

class _LeadList extends StatefulWidget {
  const _LeadList({required this.stages, required this.leads, required this.manage});

  final List<Map<String, dynamic>> stages;
  final List<Map<String, dynamic>> leads;
  final bool manage;

  @override
  State<_LeadList> createState() => _LeadListState();
}

class _LeadListState extends State<_LeadList> {
  String _status = 'open';

  static const _statuses = {'': 'Toutes', 'open': 'En cours', 'won': 'Gagnées', 'lost': 'Perdues'};

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final stageById = {for (final s in widget.stages) s['id']: s};
    final rows = widget.leads.where((l) => _status.isEmpty || (l['status'] ?? 'open') == _status).toList()..sort((a, b) => '${b['updated_at'] ?? ''}'.compareTo('${a['updated_at'] ?? ''}'));
    final total = rows.fold<double>(0, (s, l) => s + toDouble(l['expected_revenue']));

    return Column(
      children: [
        FilterBar(
          padding: EdgeInsets.fromLTRB(MediaQuery.of(context).size.width < 700 ? 16 : 24, 8, MediaQuery.of(context).size.width < 700 ? 16 : 24, 12),
          statuses: _statuses,
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in _statuses.keys) k: widget.leads.where((l) => k.isEmpty || (l['status'] ?? 'open') == k).length},
          trailing: [Text(tr('${rows.length} opportunité(s) · ${money(total, cur)}'), style: TextStyle(color: Theme.of(context).hintColor, fontWeight: FontWeight.w600, fontSize: 12.5))],
        ),
        Expanded(
          child: rows.isEmpty
              ? (widget.leads.isNotEmpty
                  ? EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
                  : EmptyState(icon: Icons.handshake_outlined, title: tr('Aucune opportunité'), message: tr('Ajoutez vos prospects et suivez-les jusqu\'à la signature.')))
              : ResponsiveList<Map<String, dynamic>>(
                  items: rows,
                  columns: const ['Opportunité', 'Client / contact', 'Étape', 'Montant', 'Note', 'Clôture prévue', 'Source'],
                  onTap: (l) => editLead(context, l),
                  cells: (l) {
                    final st = stageById[l['stage_id']];
                    return [
                      Text(tr('${l['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(tr([l['company_name'], l['contact_name']].where((e) => e != null && '$e'.isNotEmpty).join(' · '))),
                      StatusChip('${st?['name'] ?? '—'}', color: _hex(st?['color'] as String?)),
                      Text(tr(money(toDouble(l['expected_revenue']), cur))),
                      Align(alignment: Alignment.centerLeft, child: LeadScoreTag(lead: l)),
                      Text(tr(dateFr(l['expected_close_date']))),
                      Text(tr(leadSources[l['source']] ?? '')),
                    ];
                  },
                  mobileTile: (l) {
                    final st = stageById[l['stage_id']];
                    return Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(tr('${l['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                              Text(tr('${l['company_name'] ?? l['contact_name'] ?? ''}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                              const SizedBox(height: 4),
                              Wrap(spacing: 6, children: [StatusChip('${st?['name'] ?? '—'}', color: _hex(st?['color'] as String?)), LeadScoreTag(lead: l)]),
                            ],
                          ),
                        ),
                        Text(tr(money(toDouble(l['expected_revenue']), cur)), style: const TextStyle(fontWeight: FontWeight.w600)),
                      ],
                    );
                  },
                ),
        ),
      ],
    );
  }
}

// ===========================================================================
// Activités
// ===========================================================================

class _ActivityList extends StatelessWidget {
  const _ActivityList({required this.activities, required this.manage, this.filtered = false});

  final List<Map<String, dynamic>> activities;
  final bool manage;
  final bool filtered;

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final endOfDay = DateTime(now.year, now.month, now.day, 23, 59, 59);
    final open = activities.where((a) => a['done'] != true).toList()..sort((a, b) => '${a['due_at'] ?? '9'}'.compareTo('${b['due_at'] ?? '9'}'));
    final late = open.where((a) => (_dt(a['due_at']) ?? endOfDay.add(const Duration(days: 1))).isBefore(now)).toList();
    final today = open.where((a) {
      final d = _dt(a['due_at']);
      return d != null && !d.isBefore(now) && !d.isAfter(endOfDay);
    }).toList();
    final later = open.where((a) => !late.contains(a) && !today.contains(a)).toList();
    final done = activities.where((a) => a['done'] == true).toList()..sort((a, b) => '${b['done_at'] ?? ''}'.compareTo('${a['done_at'] ?? ''}'));

    if (activities.isEmpty && filtered) return EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'));
    if (activities.isEmpty) {
      return EmptyState(icon: Icons.alarm_outlined, title: tr('Aucune activité'), message: tr('Planifiez vos appels, rendez-vous et relances : ils apparaîtront ici et sur les cartes du pipeline.'));
    }

    Widget group(String title, List<Map<String, dynamic>> rows, Color color) {
      if (rows.isEmpty) return const SizedBox();
      return Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: SectionCard(
          title: '$title (${rows.length})',
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: Column(
            children: [for (final a in rows) _ActivityTile(activity: a, color: color, manage: manage)],
          ),
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      children: [
        group('En retard', late, YColors.danger),
        group('Aujourd\'hui', today, YColors.warning),
        group('À venir', later, YColors.blue),
        group('Terminées récemment', done.take(20).toList(), YColors.success),
      ],
    );
  }
}

class _ActivityTile extends StatelessWidget {
  const _ActivityTile({required this.activity, required this.color, required this.manage});

  final Map<String, dynamic> activity;
  final Color color;
  final bool manage;

  @override
  Widget build(BuildContext context) {
    final a = activity;
    final lead = LocalDb.get('crm_leads', a['lead_id'] as String?);
    final partner = LocalDb.get('partners', a['partner_id'] as String?);
    final due = _dt(a['due_at']);
    final done = a['done'] == true;
    final sub = [if (due != null) DateFormat('EEE dd/MM HH:mm', I18n.dateLocale).format(due), if (lead != null) '${lead['title']}', if (partner != null) '${partner['name']}'].join(' · ');

    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: CircleAvatar(
        backgroundColor: color.withValues(alpha: .12),
        child: Icon(activityIcons[a['type']] ?? Icons.event, color: color, size: 20),
      ),
      title: Text(
        tr('${a['subject']}'),
        style: TextStyle(decoration: done ? TextDecoration.lineThrough : null, fontWeight: FontWeight.w600),
      ),
      subtitle: sub.isEmpty ? null : Text(tr(sub)),
      onTap: () => editActivity(context, activity: a),
      trailing: manage
          ? Checkbox(value: done, onChanged: (v) => context.read<SyncEngine>().save('crm_activities', {'id': a['id'], 'done': v, 'done_at': v == true ? DateTime.now().toIso8601String() : null}))
          : null,
    );
  }
}

// ===========================================================================
// Statistiques (serveur)
// ===========================================================================

class _Stats extends StatefulWidget {
  const _Stats({required this.ownerId});

  final int? ownerId;

  @override
  State<_Stats> createState() => _StatsState();
}

class _StatsState extends State<_Stats> {
  Map<String, dynamic>? _data;
  Object? _error;
  int? _loadedFor = -1;

  Future<void> _load() async {
    _loadedFor = widget.ownerId;
    setState(() => _error = null);
    try {
      final r = await context.read<Session>().api.get('crm/stats', query: {'owner_id': ?widget.ownerId?.toString()});
      if (mounted) setState(() => _data = Map<String, dynamic>.from(r));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loadedFor != widget.ownerId) WidgetsBinding.instance.addPostFrameCallback((_) => _load());
    if (_error != null && _data == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_data == null) return const Center(child: CircularProgressIndicator());
    final d = _data!;
    final k = Map<String, dynamic>.from(d['kpi']);
    final cur = context.read<Session>().currency;
    String m(num? v) => money(v, cur);

    Widget kpi(String label, String value, {IconData icon = Icons.insights, Color color = YColors.blue}) => KpiBox(width: 220, child: StatTile(label: label, value: value, icon: icon, color: color));

    final pipeline = List<Map<String, dynamic>>.from(d['pipeline']).where((p) => p['kind'] == 'open').toList();
    final maxAmount = pipeline.fold<double>(1, (s, p) => toDouble(p['amount']) > s ? toDouble(p['amount']) : s);

    Widget bars(List rows, String Function(Map) label, num Function(Map) value, {Color color = YColors.blue, String Function(num)? fmt}) {
      final max = rows.fold<double>(1, (s, r) => value(r as Map).toDouble() > s ? value(r).toDouble() : s);
      if (rows.isEmpty) return Text(tr('Aucune donnée sur la période'), style: TextStyle(color: Theme.of(context).hintColor));
      return Column(
        children: [
          for (final r in rows.cast<Map>())
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 5),
              child: Row(
                children: [
                  SizedBox(width: 150, child: Text(tr(label(r)), overflow: TextOverflow.ellipsis)),
                  Expanded(
                    child: LayoutBuilder(
                      builder: (_, c) => Align(
                        alignment: Alignment.centerLeft,
                        child: Container(
                          height: 14,
                          width: (c.maxWidth * value(r) / max).clamp(2, c.maxWidth).toDouble(),
                          decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(4)),
                        ),
                      ),
                    ),
                  ),
                  SizedBox(width: 130, child: Text(tr((fmt ?? m)(value(r))), textAlign: TextAlign.right)),
                ],
              ),
            ),
        ],
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        children: [
          Text(tr('Du ${dateFr(d['from'])} au ${dateFr(d['to'])}'), style: TextStyle(color: Theme.of(context).hintColor)),
          const SizedBox(height: 10),
          KpiWrap(children: [
              kpi('Pipeline en cours (${k['open_count']})', m(k['open_amount']), icon: Icons.view_kanban_outlined),
              kpi('Pipeline pondéré', m(k['weighted']), icon: Icons.trending_up),
              kpi('Gagné (${k['won_count']})', m(k['won_amount']), icon: Icons.emoji_events_outlined, color: YColors.success),
              kpi('Taux de réussite', k['win_rate'] == null ? '—' : '${number(k['win_rate'], decimals: 1)} %', icon: Icons.percent, color: YColors.success),
              kpi('Affaire moyenne', k['avg_deal'] == null ? '—' : m(k['avg_deal']), icon: Icons.payments_outlined),
              kpi('Délai moyen de signature', k['avg_days_to_win'] == null ? '—' : '${number(k['avg_days_to_win'], decimals: 0)} j', icon: Icons.timelapse),
              kpi('Nouvelles opportunités', '${k['new_count']}', icon: Icons.fiber_new_outlined),
              kpi('Relances en retard', '${k['late_activities']}', icon: Icons.alarm, color: (k['late_activities'] as num) > 0 ? YColors.danger : YColors.success),
            ],
          ),
          const SizedBox(height: 16),
          SectionCard(
            title: tr('Pipeline par étape'),
            child: Column(
              children: [
                for (final p in pipeline)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 5),
                    child: Row(
                      children: [
                        SizedBox(width: 150, child: Text(tr('${p['name']} (${p['count']})'), overflow: TextOverflow.ellipsis)),
                        Expanded(
                          child: LayoutBuilder(
                            builder: (_, c) => Stack(
                              children: [
                                Container(
                                  height: 16,
                                  width: (c.maxWidth * toDouble(p['amount']) / maxAmount).clamp(2, c.maxWidth).toDouble(),
                                  decoration: BoxDecoration(color: _hex(p['color'] as String?).withValues(alpha: .25), borderRadius: BorderRadius.circular(4)),
                                ),
                                Container(
                                  height: 16,
                                  width: (c.maxWidth * toDouble(p['weighted']) / maxAmount).clamp(2, c.maxWidth).toDouble(),
                                  decoration: BoxDecoration(color: _hex(p['color'] as String?), borderRadius: BorderRadius.circular(4)),
                                ),
                              ],
                            ),
                          ),
                        ),
                        SizedBox(
                          width: 200,
                          child: Text(tr('${m(p['amount'])} · pondéré ${m(p['weighted'])}'), textAlign: TextAlign.right, style: const TextStyle(fontSize: 12)),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          SectionCard(
            title: tr('Prévisions (pondérées) par mois de clôture'),
            child: bars(
              d['forecast'] as List,
              (r) => r['month'] == 'sans date' ? 'Sans date' : _cap(DateFormat('MMMM yyyy', I18n.dateLocale).format(DateTime.parse('${r['month']}-01'))),
              (r) => toDouble(r['weighted']),
              color: YColors.night,
            ),
          ),
          const SizedBox(height: 16),
          LayoutBuilder(
            builder: (context, c) {
              final cards = [
                SectionCard(title: tr('Nouvelles opportunités par source'), child: bars(d['by_source'] as List, (r) => '${r['label']} (${r['count']})', (r) => toDouble(r['amount']))),
                SectionCard(
                  title: tr('Chiffre gagné par commercial'),
                  child: bars(d['by_owner'] as List, (r) => '${r['label']}', (r) => toDouble(r['amount']), color: YColors.success),
                ),
                SectionCard(
                  title: tr('Motifs de perte'),
                  child: bars(d['lost_reasons'] as List, (r) => '${r['label']}', (r) => r['count'] as num, color: YColors.danger, fmt: (v) => '$v'),
                ),
              ];
              final temp = asMap(d['temperature']);
              final hot = [for (final x in (d['hot_leads'] as List? ?? const [])) asMap(x)];
              final camps = (d['by_campaign'] as List? ?? const []);
              cards.add(SectionCard(
                title: tr('Prospects à rappeler en premier'),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Wrap(spacing: 8, runSpacing: 6, children: [
                    for (final t in const [('hot', 'Chauds', 75), ('warm', 'Tièdes', 50), ('cold', 'Froids', 10)])
                      Chip(avatar: CircleAvatar(backgroundColor: leadTemperature(t.$3).$1, radius: 6), label: Text(tr('${temp[t.$1] ?? 0} ${t.$2.toLowerCase()}'))),
                  ]),
                  const SizedBox(height: 6),
                  for (final h in hot)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      onTap: () {
                        final l = LocalDb.get('crm_leads', '${h['id']}');
                        if (l != null) editLead(context, l);
                      },
                      title: Text(verbatim('${h['title']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                      subtitle: Text(verbatim([h['contact'], h['source']].where((x) => x != null).join(' · '))),
                      trailing: LeadScoreTag(lead: {'score': h['score'], 'status': 'open'}),
                    ),
                ]),
              ));
              if (camps.isNotEmpty) {
                cards.add(SectionCard(title: tr('Opportunités par campagne'), child: bars(camps, (r) => '${r['label']} (${r['count']} · ${r['won']} gagnée(s))', (r) => r['count'] as num, color: const Color(0xFF9333EA), fmt: (v) => '$v')));
              }
              if (c.maxWidth < 1000) {
                return Column(
                  children: [for (final w in cards) Padding(padding: const EdgeInsets.only(bottom: 16), child: w)],
                );
              }
              final w = (c.maxWidth - 32) / 3;
              return Wrap(spacing: 16, runSpacing: 16, children: [for (final x in cards) SizedBox(width: w, child: x)]);
            },
          ),
        ],
      ),
    );
  }
}

String _cap(String s) => s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);

// ===========================================================================
// Fiche opportunité
// ===========================================================================

Future<void> editLead(BuildContext context, [Map<String, dynamic>? lead]) async {
  final nav = Navigator.of(context);
  final res = await showFormDialog<Map<String, dynamic>>(context, title: lead == null ? 'Nouvelle opportunité' : '${lead['title']}', builder: (c) => _LeadForm(lead: lead));
  final quote = res?['quote'];
  if (quote is Map<String, dynamic>) {
    // Le devis s'ouvre une fois la fiche fermée
    await nav.push(MaterialPageRoute(builder: (_) => DocumentEditor(config: DocConfig.sale, document: quote, initialType: 'quote')));
  }
}

class _LeadForm extends StatefulWidget {
  const _LeadForm({this.lead});

  final Map<String, dynamic>? lead;

  @override
  State<_LeadForm> createState() => _LeadFormState();
}

class _LeadFormState extends State<_LeadForm> {
  final _key = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _c;
  late Map<String, dynamic> _l;
  List<Map<String, dynamic>> _users = [];
  bool _busy = false;

  static const _text = ['title', 'contact_name', 'company_name', 'email', 'phone', 'city', 'tags', 'notes'];

  bool get _isNew => widget.lead == null;
  late final Map<String, dynamic> _custom = asMap(widget.lead?['custom']);

  @override
  void initState() {
    super.initState();
    final s = context.read<Session>();
    final stages = crmStages();
    _l = widget.lead != null
        ? Map<String, dynamic>.from(widget.lead!)
        : {
            'id': newId(),
            'stage_id': stages.where((x) => x['kind'] == 'open').firstOrNull?['id'],
            'owner_id': s.user['id'],
            'priority': 1,
            'source': null,
            'expected_close_date': isoDate(DateTime.now().add(const Duration(days: 30))),
          };
    _c = {for (final f in _text) f: TextEditingController(text: '${_l[f] ?? ''}')};
    _c['expected_revenue'] = TextEditingController(text: _l['expected_revenue'] == null ? '' : '${toDouble(_l['expected_revenue']).round()}');
    _c['probability'] = TextEditingController(text: _l['probability'] == null ? '' : '${_l['probability']}');
    crmUsers(s).then((u) {
      if (mounted) setState(() => _users = u);
    });
  }

  Map<String, dynamic> _payload() {
    String? t(String f) => _c[f]!.text.trim().isEmpty ? null : _c[f]!.text.trim();
    final prob = int.tryParse(_c['probability']!.text.trim());
    return {
      'id': _l['id'],
      for (final f in _text) f: t(f),
      'partner_id': _l['partner_id'],
      'source': _l['source'],
      'stage_id': _l['stage_id'],
      'owner_id': _l['owner_id'],
      'priority': _l['priority'] ?? 0,
      'expected_close_date': _l['expected_close_date'],
      'expected_revenue': toDouble(_c['expected_revenue']!.text),
      if (prob != null && (widget.lead == null || prob != widget.lead!['probability'] || _l['stage_id'] == widget.lead!['stage_id'])) 'probability': prob,
      if (context.read<Session>().hasModule('champs')) 'custom': _custom,
    };
  }

  Future<Map<String, dynamic>?> _save({bool close = true}) async {
    if (!_key.currentState!.validate()) return null;
    final err = context.read<Session>().hasModule('champs') ? customRequiredError('crm_leads', _custom) : null;
    if (err != null) {
      toast(context, err, error: true);
      return null;
    }
    final sync = context.read<SyncEngine>();
    final before = widget.lead;
    final data = _payload();
    final stageChanged = before != null && before['stage_id'] != data['stage_id'];
    final stage = crmStages().where((s) => s['id'] == data['stage_id']).firstOrNull;
    if (stage != null && stage['kind'] == 'lost' && (before == null || stageChanged)) {
      final reason = await _askLostReason(context);
      if (reason == null) return null;
      data['lost_reason'] = reason;
    }
    if (stageChanged) data.remove('probability');
    final saved = await sync.save('crm_leads', data);
    if (stage != null && (before == null || stageChanged)) {
      final f = stageFields(stage, lostReason: data['lost_reason'] as String?);
      if (data.containsKey('probability')) f.remove('probability');
      await LocalDb.put('crm_leads', {...saved, ...f});
    }
    _l = LocalDb.get('crm_leads', _l['id'] as String) ?? saved;
    if (close && mounted) Navigator.pop(context);
    return _l;
  }

  Future<void> _delete() async {
    if (!await confirm(context, tr('Supprimer cette opportunité ?'), tr('Ses activités resteront dans la liste.'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    await context.read<SyncEngine>().remove('crm_leads', _l['id'] as String);
    if (mounted) Navigator.pop(context);
  }

  /// Crée (hors ligne si besoin) le client et un devis brouillon prérempli, puis ouvre l'éditeur.
  Future<void> _quote() async {
    final s = context.read<Session>();
    if (!s.hasModule('ventes')) return toast(context, tr('Le module Ventes n\'est pas activé.'), error: true);
    setState(() => _busy = true);
    try {
      final lead = await _save(close: false);
      if (lead == null || !mounted) return;
      final sync = context.read<SyncEngine>();
      final existing = LocalDb.get('sales_documents', lead['quote_id'] as String?);
      if (existing != null) {
        Navigator.pop(context, {'quote': existing});
        return;
      }
      var partnerId = lead['partner_id'] as String?;
      var partnerName = LocalDb.get('partners', partnerId)?['name'] as String?;
      if (partnerId == null) {
        partnerName = '${lead['company_name'] ?? lead['contact_name'] ?? lead['title']}';
        final p = await sync.save('partners', {
          'type': 'customer',
          'is_company': lead['company_name'] != null,
          'name': partnerName,
          'phone': lead['phone'],
          'email': lead['email'],
          'city': lead['city'],
        });
        partnerId = p['id'] as String;
      }
      final tax = toDouble(s.companySettings['tax_rate'] ?? 18);
      final line = {'id': newId(), 'description': '${lead['title']}', 'quantity': 1.0, 'unit_price': toDouble(lead['expected_revenue']), 'discount_percent': 0.0, 'tax_rate': tax};
      final c = computeLine(line);
      final doc = await sync.save('sales_documents', {
        'type': 'quote',
        'status': 'draft',
        'date': isoDate(DateTime.now()),
        'partner_id': partnerId,
        'partner_name': partnerName,
        'notes': lead['notes'],
        'lines': [
          {...line, ...c..remove('gross')},
        ],
        'subtotal': c['subtotal'],
        'discount_total': 0,
        'tax_total': c['tax_amount'],
        'total': c['total'],
      });
      await sync.save('crm_leads', {'id': lead['id'], 'partner_id': partnerId, 'quote_id': doc['id']});
      if (mounted) Navigator.pop(context, {'quote': doc});
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _row(List<Widget> children) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      for (var i = 0; i < children.length; i++) ...[if (i > 0) const SizedBox(width: 12), Expanded(child: children[i])],
    ],
  );

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('crm.manage');
    final stages = crmStages();
    final partners = LocalDb.all('partners').where((p) => p['type'] != 'supplier').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final hint = Theme.of(context).hintColor;

    return Form(
      key: _key,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
        shrinkWrap: true,
        children: [
          Field(
            label: tr('Opportunité'),
            child: TextFormField(
              controller: _c['title'],
              autofocus: _isNew,
              readOnly: !manage,
              decoration: InputDecoration(hintText: tr('Ex. Site e-commerce, 20 licences, contrat de maintenance…')),
              validator: (v) => (v ?? '').trim().isEmpty ? 'Obligatoire' : null,
            ),
          ),
          if (stages.isNotEmpty)
            Field(
              label: tr('Étape'),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final st in stages)
                    ChoiceChip(
                      label: Text(tr('${st['name']}')),
                      selected: _l['stage_id'] == st['id'],
                      selectedColor: _hex(st['color'] as String?).withValues(alpha: .2),
                      onSelected: manage
                          ? (_) => setState(() {
                              _l['stage_id'] = st['id'];
                              _c['probability']!.text = '${st['probability']}';
                            })
                          : null,
                    ),
                ],
              ),
            ),
          _row([
            Field(
              label: tr('Montant estimé (HT)'),
              child: TextFormField(controller: _c['expected_revenue'], readOnly: !manage, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly]),
            ),
            Field(
              label: tr('Probabilité %'),
              child: TextFormField(controller: _c['probability'], readOnly: !manage, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly]),
            ),
            Field(
              label: tr('Clôture prévue'),
              child: InkWell(
                onTap: !manage
                    ? null
                    : () async {
                        final d = await showDatePicker(
                          context: context,
                          firstDate: DateTime(2015),
                          lastDate: DateTime(2100),
                          initialDate: DateTime.tryParse('${_l['expected_close_date']}') ?? DateTime.now(),
                        );
                        if (d != null) setState(() => _l['expected_close_date'] = isoDate(d));
                      },
                child: InputDecorator(
                  decoration: const InputDecoration(suffixIcon: Icon(Icons.event, size: 18)),
                  child: Text(tr(dateFr(_l['expected_close_date']))),
                ),
              ),
            ),
          ]),
          _row([
            Field(
              label: tr('Client existant'),
              child: DropdownButtonFormField<String?>(
                isExpanded: true,
                initialValue: partners.any((p) => p['id'] == _l['partner_id']) ? _l['partner_id'] as String? : null,
                items: [
                  DropdownMenuItem(value: null, child: Text(tr('Nouveau prospect'))),
                  for (final p in partners)
                    DropdownMenuItem(
                      value: p['id'] as String,
                      child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: !manage
                    ? null
                    : (v) => setState(() {
                        _l['partner_id'] = v;
                        final p = LocalDb.get('partners', v);
                        if (p != null) {
                          if (_c['company_name']!.text.isEmpty) _c['company_name']!.text = '${p['name']}';
                          if (_c['phone']!.text.isEmpty) _c['phone']!.text = '${p['phone'] ?? ''}';
                          if (_c['email']!.text.isEmpty) _c['email']!.text = '${p['email'] ?? ''}';
                        }
                      }),
              ),
            ),
            Field(
              label: tr('Source'),
              child: DropdownButtonFormField<String?>(
                isExpanded: true,
                initialValue: _l['source'] as String?,
                items: [
                  const DropdownMenuItem(value: null, child: Text('—')),
                  for (final e in leadSources.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value))),
                ],
                onChanged: !manage ? null : (v) => setState(() => _l['source'] = v),
              ),
            ),
          ]),
          _row([
            Field(
              label: tr('Société'),
              child: TextFormField(controller: _c['company_name'], readOnly: !manage),
            ),
            Field(
              label: tr('Contact'),
              child: TextFormField(controller: _c['contact_name'], readOnly: !manage),
            ),
          ]),
          _row([
            Field(
              label: tr('Téléphone'),
              child: TextFormField(controller: _c['phone'], readOnly: !manage, keyboardType: TextInputType.phone),
            ),
            Field(
              label: tr('E-mail'),
              child: TextFormField(controller: _c['email'], readOnly: !manage, keyboardType: TextInputType.emailAddress),
            ),
            Field(
              label: tr('Ville'),
              child: TextFormField(controller: _c['city'], readOnly: !manage),
            ),
          ]),
          _row([
            Field(
              label: tr('Responsable'),
              child: DropdownButtonFormField<int?>(
                isExpanded: true,
                initialValue: _users.any((u) => u['id'] == _l['owner_id']) ? _l['owner_id'] as int? : null,
                items: [
                  const DropdownMenuItem(value: null, child: Text('—')),
                  for (final u in _users) DropdownMenuItem(value: u['id'] as int, child: Text(tr('${u['name']}'))),
                ],
                onChanged: !manage ? null : (v) => setState(() => _l['owner_id'] = v),
              ),
            ),
            Field(
              label: tr('Priorité'),
              child: Row(
                children: [
                  for (var i = 1; i <= 3; i++)
                    IconButton(
                      tooltip: trn(['Normale', 'Importante', 'Très importante'][i - 1]),
                      onPressed: !manage ? null : () => setState(() => _l['priority'] = _l['priority'] == i ? i - 1 : i),
                      icon: Icon(i <= ((_l['priority'] as num?) ?? 0) ? Icons.star_rounded : Icons.star_outline_rounded, color: YColors.yellow),
                    ),
                ],
              ),
            ),
          ]),
          Field(
            label: tr('Étiquettes'),
            child: TextFormField(
              controller: _c['tags'],
              readOnly: !manage,
              decoration: InputDecoration(hintText: tr('Ex. site web, urgent, grand compte')),
            ),
          ),
          Field(
            label: tr('Notes'),
            child: TextFormField(controller: _c['notes'], readOnly: !manage, maxLines: 3),
          ),
          if (context.read<Session>().hasModule('champs')) CustomFieldsEditor(entity: 'crm_leads', values: _custom, creating: _isNew, readOnly: !manage),
          if (_l['status'] == 'lost' && _l['lost_reason'] != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(tr('Motif de perte : ${_l['lost_reason']}'), style: const TextStyle(color: YColors.danger)),
            ),
          if (!_isNew) ...[
            const Divider(height: 24),
            Row(
              children: [
                Expanded(
                  child: Text(tr('Activités'), style: TextStyle(fontWeight: FontWeight.w700)),
                ),
                if (manage)
                  TextButton.icon(
                    onPressed: () => editActivity(context, leadId: _l['id'] as String, partnerId: _l['partner_id'] as String?),
                    icon: const Icon(Icons.add_alarm),
                    label: Text(tr('Planifier')),
                  ),
              ],
            ),
            ValueListenableBuilder(
              valueListenable: LocalDb.listen('crm_activities'),
              builder: (context, _, _) {
                final acts = LocalDb.all('crm_activities').where((a) => a['lead_id'] == _l['id']).toList()..sort((a, b) => '${b['due_at'] ?? ''}'.compareTo('${a['due_at'] ?? ''}'));
                if (acts.isEmpty) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text(tr('Aucune activité planifiée.'), style: TextStyle(color: hint)),
                  );
                }
                return Column(
                  children: [
                    for (final a in acts)
                      _ActivityTile(activity: a, color: a['done'] == true ? YColors.success : ((_dt(a['due_at'])?.isBefore(DateTime.now()) ?? false) ? YColors.danger : YColors.blue), manage: manage),
                  ],
                );
              },
            ),
          ],
          if (!_isNew && _l['_local'] != true) LeadSequencePanel(leadId: '${_l['id']}', manage: manage),
          if (!_isNew && (_l['utm_campaign'] ?? _l['utm_source']) != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(tr('Origine : ${[_l['utm_source'], _l['utm_medium'], _l['utm_campaign']].where((x) => x != null && '$x'.isNotEmpty).join(' · ')}'), style: TextStyle(color: hint, fontSize: 12.5)),
            ),
          const SizedBox(height: 16),
          if (manage)
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                FilledButton(onPressed: _busy ? null : () => _save(), child: Text(tr('Enregistrer'))),
                if (s.hasModule('ventes') && s.can('ventes.manage'))
                  OutlinedButton.icon(
                    onPressed: _busy ? null : _quote,
                    icon: const Icon(Icons.request_quote_outlined),
                    label: Text(tr(_l['quote_id'] != null && LocalDb.get('sales_documents', _l['quote_id'] as String?) != null ? 'Ouvrir le devis' : 'Créer le devis')),
                  ),
                if (!_isNew)
                  TextButton.icon(
                    onPressed: _delete,
                    icon: const Icon(Icons.delete_outline, color: YColors.danger),
                    label: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}

// ===========================================================================
// Activité
// ===========================================================================

Future<void> editActivity(BuildContext context, {Map<String, dynamic>? activity, String? leadId, String? partnerId}) {
  return showFormDialog(
    context,
    title: activity == null ? 'Planifier une activité' : 'Activité',
    builder: (c) => _ActivityForm(activity: activity, leadId: leadId, partnerId: partnerId),
  );
}

class _ActivityForm extends StatefulWidget {
  const _ActivityForm({this.activity, this.leadId, this.partnerId});

  final Map<String, dynamic>? activity;
  final String? leadId;
  final String? partnerId;

  @override
  State<_ActivityForm> createState() => _ActivityFormState();
}

class _ActivityFormState extends State<_ActivityForm> {
  final _key = GlobalKey<FormState>();
  late final Map<String, dynamic> _a;
  late final _subject = TextEditingController(text: '${_a['subject'] ?? ''}');
  late final _notes = TextEditingController(text: '${_a['notes'] ?? ''}');

  @override
  void initState() {
    super.initState();
    final tomorrow = DateTime.now().add(const Duration(days: 1));
    _a = widget.activity != null
        ? Map<String, dynamic>.from(widget.activity!)
        : {
            'id': newId(),
            'type': 'call',
            'lead_id': widget.leadId,
            'partner_id': widget.partnerId,
            'due_at': DateTime(tomorrow.year, tomorrow.month, tomorrow.day, 9).toIso8601String(),
            'done': false,
            'owner_id': context.read<Session>().user['id'],
          };
  }

  Future<void> _save() async {
    if (!_key.currentState!.validate()) return;
    await context.read<SyncEngine>().save('crm_activities', {
      'id': _a['id'],
      'type': _a['type'],
      'subject': _subject.text.trim(),
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'lead_id': _a['lead_id'],
      'partner_id': _a['partner_id'],
      'due_at': _a['due_at'],
      'done': _a['done'] == true,
      'done_at': _a['done'] == true ? (_a['done_at'] ?? DateTime.now().toIso8601String()) : null,
      'owner_id': _a['owner_id'],
    });
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final manage = context.read<Session>().can('crm.manage');
    final leads = LocalDb.all('crm_leads').where((l) => l['status'] == 'open' || l['id'] == _a['lead_id']).toList()..sort((a, b) => '${a['title']}'.compareTo('${b['title']}'));
    final due = _dt(_a['due_at']);

    return Form(
      key: _key,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
        shrinkWrap: true,
        children: [
          Field(
            label: tr('Type'),
            child: Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final e in activityTypes.entries)
                  ChoiceChip(avatar: Icon(activityIcons[e.key], size: 16), label: Text(tr(e.value)), selected: _a['type'] == e.key, onSelected: manage ? (_) => setState(() => _a['type'] = e.key) : null),
              ],
            ),
          ),
          Field(
            label: tr('Objet'),
            child: TextFormField(
              controller: _subject,
              autofocus: widget.activity == null,
              readOnly: !manage,
              decoration: InputDecoration(hintText: tr('Ex. Relancer pour le devis, présenter la maquette…')),
              validator: (v) => (v ?? '').trim().isEmpty ? 'Obligatoire' : null,
            ),
          ),
          Field(
            label: tr('Échéance'),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.event),
                    label: Text(tr(due == null ? 'Sans date' : DateFormat('EEEE dd/MM/yyyy', I18n.dateLocale).format(due))),
                    onPressed: !manage
                        ? null
                        : () async {
                            final d = await showDatePicker(context: context, firstDate: DateTime(2015), lastDate: DateTime(2100), initialDate: due ?? DateTime.now());
                            if (d == null) return;
                            final base = due ?? DateTime(d.year, d.month, d.day, 9);
                            setState(() => _a['due_at'] = DateTime(d.year, d.month, d.day, base.hour, base.minute).toIso8601String());
                          },
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  icon: const Icon(Icons.schedule),
                  label: Text(tr(due == null ? '--:--' : DateFormat('HH:mm').format(due))),
                  onPressed: !manage || due == null
                      ? null
                      : () async {
                          final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(due));
                          if (t != null) setState(() => _a['due_at'] = DateTime(due.year, due.month, due.day, t.hour, t.minute).toIso8601String());
                        },
                ),
              ],
            ),
          ),
          Field(
            label: tr('Opportunité'),
            child: DropdownButtonFormField<String?>(
              isExpanded: true,
              initialValue: leads.any((l) => l['id'] == _a['lead_id']) ? _a['lead_id'] as String? : null,
              items: [
                const DropdownMenuItem(value: null, child: Text('—')),
                for (final l in leads)
                  DropdownMenuItem(
                    value: l['id'] as String,
                    child: Text(tr('${l['title']}'), overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: !manage
                  ? null
                  : (v) => setState(() {
                      _a['lead_id'] = v;
                      _a['partner_id'] = LocalDb.get('crm_leads', v)?['partner_id'] ?? _a['partner_id'];
                    }),
            ),
          ),
          Field(
            label: tr('Notes / compte rendu'),
            child: TextFormField(controller: _notes, readOnly: !manage, maxLines: 3),
          ),
          SwitchListTile(contentPadding: EdgeInsets.zero, value: _a['done'] == true, onChanged: manage ? (v) => setState(() => _a['done'] = v) : null, title: Text(tr('Faite'))),
          const SizedBox(height: 12),
          if (manage)
            Row(
              children: [
                FilledButton(onPressed: _save, child: Text(tr('Enregistrer'))),
                const Spacer(),
                if (widget.activity != null)
                  TextButton.icon(
                    onPressed: () async {
                      await context.read<SyncEngine>().remove('crm_activities', _a['id'] as String);
                      if (context.mounted) Navigator.pop(context);
                    },
                    icon: const Icon(Icons.delete_outline, color: YColors.danger),
                    label: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}
