import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'rh_documents_screen.dart' show editRhDocument;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const rcStages = {'new': 'Nouvelles', 'screening': 'Présélection', 'interview': 'Entretien', 'offer': 'Proposition', 'hired': 'Embauchés', 'rejected': 'Non retenues'};
const rcSources = {'website': 'Page Emplois', 'manual': 'Saisie', 'referral': 'Recommandation', 'whatsapp': 'WhatsApp', 'facebook': 'Facebook', 'linkedin': 'LinkedIn', 'other': 'Autre'};
const rcContracts = {'CDI': 'CDI', 'CDD': 'CDD', 'stage': 'Stage', 'journalier': 'Journalier', 'consultant': 'Prestation'};

Color rcStageColor(String? s) => switch (s) {
  'new' => YColors.blue,
  'screening' => const Color(0xFF8E44AD),
  'interview' => YColors.warning,
  'offer' => const Color(0xFF0E9AA7),
  'hired' => YColors.success,
  'rejected' => YColors.danger,
  _ => YColors.muted,
};

String rcName(Map a) => '${a['first_name'] ?? ''} ${a['last_name'] ?? ''}'.trim();
String rcJobTitle(String? id) => id == null ? 'Candidature spontanée' : '${LocalDb.get('rc_jobs', id)?['title'] ?? 'Offre'}';

class RecrutementScreen extends StatelessWidget {
  const RecrutementScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('recrutement.manage');
    final view = manage || s.can('recrutement.view');
    final tabs = [
      if (view) ('Candidatures', const _PipelineTab()),
      if (view) ('Offres', const _JobsTab()),
      ('Entretiens', const _InterviewsTab()),
      if (view) ('Statistiques', const _StatsTab()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(
          title: tr('Recrutement'),
          subtitle: view ? 'Offres, candidatures, entretiens et embauches' : 'Vos entretiens de recrutement',
          actions: [
            if (manage) OutlinedButton.icon(onPressed: () => _careersLink(context), icon: const Icon(Icons.public), label: Text(tr('Page Emplois'))),
            if (manage) OutlinedButton.icon(onPressed: () => editApplication(context), icon: const Icon(Icons.person_add_alt), label: Text(tr('Ajouter un candidat'))),
            if (manage) FilledButton.icon(onPressed: () => editJob(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle offre'))),
          ],
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }

  Future<void> _careersLink(BuildContext context) async {
    final api = context.read<Session>().api;
    try {
      final r = asMap((await api.get('recrutement/settings'))['data']);
      if (!context.mounted) return;
      final intro = TextEditingController(text: '${r['careers_intro'] ?? ''}');
      final retention = TextEditingController(text: '${r['retention_months'] ?? 24}');
      var enabled = r['careers_enabled'] != false;
      final ok = await showDialog<bool>(
        context: context,
        builder: (d) => StatefulBuilder(
          builder: (d, set) => YAlertDialog(
            title: Text(tr('Page Emplois')),
            content: SizedBox(
              width: 480,
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                SelectableText(tr('${r['url']}'), style: const TextStyle(fontWeight: FontWeight.w700, color: YColors.blue)),
                const SizedBox(height: 4),
                Text(tr('À partager sur Facebook, LinkedIn, WhatsApp ou votre site : les offres ouvertes y apparaissent avec le formulaire de candidature.'), style: TextStyle(fontSize: 12, color: Theme.of(d).hintColor)),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: '${r['url']}'));
                      toast(d, tr('Lien copié.'));
                    },
                    icon: const Icon(Icons.copy, size: 16),
                    label: Text(tr('Copier le lien')),
                  ),
                ),
                SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Page en ligne')), value: enabled, onChanged: (v) => set(() => enabled = v)),
                TextField(controller: intro, maxLines: 4, decoration: InputDecoration(labelText: tr('Présentation de l\'entreprise (en tête de page)'))),
                TextField(controller: retention, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Conservation des candidatures non retenues (mois)'), helperText: tr('Effacement automatique ensuite (CV compris)'))),
              ]),
            ),
            actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Fermer'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
          ),
        ),
      );
      if (ok == true) await api.put('recrutement/settings', {'careers_enabled': enabled, 'careers_intro': intro.text.trim().isEmpty ? null : intro.text.trim(), 'retention_months': int.tryParse(retention.text) ?? 24});
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }
}

// ---------- Kanban des candidatures ----------

class _PipelineTab extends StatefulWidget {
  const _PipelineTab();

  @override
  State<_PipelineTab> createState() => _PipelineTabState();
}

class _PipelineTabState extends State<_PipelineTab> {
  String _stage = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  Future<void> _move(Map a, String stage) async {
    if (stage == a['stage']) return;
    final sync = context.read<SyncEngine>();
    if (stage == 'hired') return hireApplication(context, Map<String, dynamic>.from(a));
    String? reason;
    if (stage == 'rejected') {
      reason = await _askReason(context);
      if (reason == null) return;
    }
    await sync.save('rc_applications', {'id': a['id'], 'stage': stage, 'reject_reason': ?reason});
  }

  @override
  Widget build(BuildContext context) {
    final manage = context.read<Session>().can('recrutement.manage');
    return ListenableBuilder(
      listenable: Listenable.merge([LocalDb.listen('rc_applications'), LocalDb.listen('rc_interviews'), LocalDb.listen('rc_jobs')]),
      builder: (context, _) {
        final jobs = LocalDb.all('rc_jobs').where((j) => j['status'] == 'open' || LocalDb.all('rc_applications').any((a) => a['job_id'] == j['id'] && !['hired', 'rejected'].contains(a['stage']))).toList()..sort((a, b) => '${a['title']}'.compareTo('${b['title']}'));
        final allApps = LocalDb.all('rc_applications')..sort((a, b) => '${b['created_at'] ?? ''}'.compareTo('${a['created_at'] ?? ''}'));
        final q = _q.toLowerCase();
        bool base(Map<String, dynamic> a) =>
            (q.isEmpty || '${rcName(a)} ${a['email'] ?? ''} ${a['phone'] ?? ''} ${rcJobTitle(a['job_id'] as String?)}'.toLowerCase().contains(q)) &&
            (_f['job'] == null || (_f['job'] == '__spont' ? a['job_id'] == null : a['job_id'] == _f['job'])) &&
            (_f['source'] == null || '${a['source']}' == _f['source']) &&
            (_f['rating'] == null || ((a['rating'] as num?)?.toInt() ?? 0) >= (int.tryParse(_f['rating']!) ?? 0)) &&
            (_f['cv'] == null || (_f['cv'] == 'yes') == (a['has_cv'] == true)) &&
            (_period == null || _period!.contains(a['created_at']));
        final apps = allApps.where(base).toList();
        final stages = rcStages.keys.where((s) => _stage.isEmpty ? s != 'rejected' : s == _stage).toList();
        final narrow = MediaQuery.sizeOf(context).width < 900;
        final shownCount = apps.where((a) => stages.contains(a['stage'])).length;
        return Column(children: [
          const SizedBox(height: 12),
          FilterBar(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Nom, e-mail, téléphone…',
            statuses: const {'': 'En cours', ...rcStages},
            status: _stage,
            onStatus: (v) => setState(() => _stage = v),
            statusCounts: {
              '': apps.where((a) => a['stage'] != 'rejected').length,
              for (final k in rcStages.keys) k: apps.where((a) => a['stage'] == k).length,
            },
            filters: [
              FilterSpec('job', 'Offre', {for (final j in jobs) '${j['id']}': '${j['title']}', '__spont': 'Candidatures spontanées'}, icon: Icons.work_outline),
              const FilterSpec('source', 'Source', rcSources, icon: Icons.call_received),
              const FilterSpec('rating', 'Note', {'1': '1 étoile et +', '2': '2 étoiles et +', '3': '3 étoiles et +', '4': '4 étoiles et +', '5': '5 étoiles'}, icon: Icons.star_border),
              const FilterSpec('cv', 'CV', {'yes': 'Avec CV', 'no': 'Sans CV'}, icon: Icons.attach_file),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: shownCount,
            countLabel: 'candidature',
            onReset: () => setState(() => (_q = '', _stage = '', _f = {}, _period = null)),
          ),
          Expanded(
            child: shownCount == 0
                ? EmptyState(icon: Icons.person_search, title: tr('Aucune candidature'), message: tr('Publiez une offre et partagez votre page Emplois : les candidatures arrivent ici avec le CV.'))
                : narrow
                    ? ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
                        for (final st in stages) ...[
                          if (apps.any((a) => a['stage'] == st)) Padding(padding: const EdgeInsets.fromLTRB(4, 12, 4, 6), child: Text(tr('${rcStages[st]} (${apps.where((a) => a['stage'] == st).length})'), style: TextStyle(fontWeight: FontWeight.w800, color: rcStageColor(st)))),
                          for (final a in apps.where((a) => a['stage'] == st)) _AppCard(app: a),
                        ],
                      ])
                    : ListView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                        children: [
                          for (final st in stages)
                            DragTarget<Map>(
                              onWillAcceptWithDetails: (d) => manage && d.data['stage'] != st && d.data['stage'] != 'hired',
                              onAcceptWithDetails: (d) => _move(d.data, st),
                              builder: (context, cand, _) => Container(
                                width: 260,
                                margin: const EdgeInsets.only(right: 12),
                                padding: const EdgeInsets.all(8),
                                decoration: BoxDecoration(color: cand.isNotEmpty ? rcStageColor(st).withValues(alpha: 0.08) : Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: 0.35), borderRadius: BorderRadius.circular(14)),
                                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                                  Padding(
                                    padding: const EdgeInsets.fromLTRB(6, 4, 6, 8),
                                    child: Row(children: [
                                      Container(width: 8, height: 8, decoration: BoxDecoration(color: rcStageColor(st), shape: BoxShape.circle)),
                                      const SizedBox(width: 8),
                                      Expanded(child: Text(tr(rcStages[st]!), style: const TextStyle(fontWeight: FontWeight.w800))),
                                      Text(tr('${apps.where((a) => a['stage'] == st).length}'), style: TextStyle(color: Theme.of(context).hintColor)),
                                    ]),
                                  ),
                                  Expanded(
                                    child: ListView(children: [
                                      for (final a in apps.where((a) => a['stage'] == st))
                                        manage && st != 'hired'
                                            ? Draggable<Map>(data: a, affinity: Axis.horizontal, feedback: SizedBox(width: 244, child: Material(elevation: 6, borderRadius: BorderRadius.circular(12), child: _AppCard(app: a))), childWhenDragging: Opacity(opacity: 0.35, child: _AppCard(app: a)), child: _AppCard(app: a))
                                            : _AppCard(app: a),
                                    ]),
                                  ),
                                ]),
                              ),
                            ),
                        ],
                      ),
          ),
        ]);
      },
    );
  }
}

Future<String?> _askReason(BuildContext context) async {
  final c = TextEditingController();
  const presets = ['Profil ne correspondant pas', 'Expérience insuffisante', 'Prétentions trop élevées', 'Candidat désisté', 'Poste pourvu', 'Pas venu à l\'entretien'];
  return showDialog<String>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Text(tr('Candidature non retenue')),
      content: SizedBox(
        width: 420,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(spacing: 6, runSpacing: 6, children: [for (final p in presets) ActionChip(label: Text(tr(p)), onPressed: () => Navigator.pop(d, p))]),
          TextField(controller: c, decoration: InputDecoration(labelText: tr('Autre motif (interne, non envoyé au candidat)'))),
        ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, c.text.trim().isEmpty ? null : c.text.trim()), child: Text(tr('Valider')))],
    ),
  );
}

class _AppCard extends StatelessWidget {
  const _AppCard({required this.app});

  final Map<String, dynamic> app;

  @override
  Widget build(BuildContext context) {
    final rating = (app['rating'] as num?)?.toInt() ?? 0;
    final next = (LocalDb.all('rc_interviews').where((i) => i['application_id'] == app['id'] && i['status'] == 'planned').toList()..sort((a, b) => '${a['starts_at']}'.compareTo('${b['starts_at']}'))).firstOrNull;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => showApplication(context, app['id'] as String),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr(rcName(app)), style: const TextStyle(fontWeight: FontWeight.w700))),
              if (app['has_cv'] == true) const Icon(Icons.attach_file, size: 16, color: YColors.muted),
            ]),
            Text(tr(rcJobTitle(app['job_id'] as String?)), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
            const SizedBox(height: 4),
            Row(children: [
              for (var i = 1; i <= 5; i++) Icon(i <= rating ? Icons.star : Icons.star_border, size: 14, color: i <= rating ? YColors.yellow : YColors.muted),
              const Spacer(),
              Text(tr(rcSources[app['source']] ?? ''), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
            ]),
            if (next != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('Entretien ${dateFr('${next['starts_at']}'.substring(0, 10))} ${'${next['starts_at']}'.substring(11, 16)}'), style: const TextStyle(fontSize: 12, color: YColors.warning, fontWeight: FontWeight.w600))),
            if (app['stage'] == 'rejected' && app['reject_reason'] != null) Text(tr('${app['reject_reason']}'), style: const TextStyle(fontSize: 11, color: YColors.danger)),
          ]),
        ),
      ),
    );
  }
}

// ---------- Fiche candidat ----------

Future<void> showApplication(BuildContext context, String id) => showDialog(context: context, builder: (_) => _ApplicationDialog(id: id));

Future<void> _openCv(BuildContext context, Map a) async {
  try {
    final bytes = await context.read<Session>().api.download('recrutement/applications/${a['id']}/cv');
    final name = '${a['cv_name'] ?? 'cv'}';
    final where = await saveFile(name.contains('.') ? name : '$name.pdf', bytes, mime: name.toLowerCase().endsWith('.pdf') ? 'application/pdf' : 'application/octet-stream');
    if (context.mounted) toast(context, tr('CV enregistré${where.isEmpty ? '' : ' : $where'}'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

class _ApplicationDialog extends StatelessWidget {
  const _ApplicationDialog({required this.id});

  final String id;

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final manage = s.can('recrutement.manage');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rc_applications'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('rc_interviews'),
        builder: (context, _, _) {
          final a = LocalDb.get('rc_applications', id);
          if (a == null) return YAlertDialog(content: Text(tr('Candidature introuvable.')));
          final ivs = LocalDb.all('rc_interviews').where((i) => i['application_id'] == id).toList()..sort((x, y) => '${y['starts_at']}'.compareTo('${x['starts_at']}'));
          final rating = (a['rating'] as num?)?.toInt() ?? 0;
          return YAlertDialog(
            titlePadding: const EdgeInsets.fromLTRB(24, 20, 12, 0),
            title: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr(rcName(a))),
                  Text(tr('${rcJobTitle(a['job_id'] as String?)} · ${rcSources[a['source']] ?? ''}'), style: TextStyle(fontSize: 13, color: Theme.of(context).hintColor)),
                ]),
              ),
              StatusChip(rcStages[a['stage']] ?? '', color: rcStageColor(a['stage'])),
              IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close)),
            ]),
            content: SizedBox(
              width: 640,
              child: SingleChildScrollView(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Wrap(spacing: 18, runSpacing: 6, children: [
                    if (a['phone'] != null) _kv(Icons.phone, '${a['phone']}'),
                    if (a['email'] != null) _kv(Icons.mail_outline, '${a['email']}'),
                    if (a['city'] != null) _kv(Icons.place_outlined, '${a['city']}'),
                    if (a['expected_salary'] != null) _kv(Icons.payments_outlined, '${a['expected_salary']}'),
                    if (a['available_from'] != null) _kv(Icons.event_available, 'dès le ${dateFr(a['available_from'])}'),
                  ]),
                  const SizedBox(height: 10),
                  Row(children: [
                    for (var i = 1; i <= 5; i++)
                      IconButton(
                        visualDensity: VisualDensity.compact,
                        onPressed: manage ? () => context.read<SyncEngine>().save('rc_applications', {'id': id, 'rating': i}) : null,
                        icon: Icon(i <= rating ? Icons.star : Icons.star_border, color: i <= rating ? YColors.yellow : YColors.muted),
                      ),
                    const Spacer(),
                    if (a['has_cv'] == true) FilledButton.tonalIcon(onPressed: () => _openCv(context, a), icon: const Icon(Icons.description_outlined), label: Text(tr('Ouvrir le CV'))),
                    if (a['has_cv'] != true && manage)
                      OutlinedButton.icon(
                        onPressed: () async {
                          final f = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['pdf', 'doc', 'docx', 'odt', 'jpg', 'jpeg', 'png']);
                          if (f.isEmpty || !context.mounted) return;
                          try {
                            await context.read<SyncEngine>().sync();
                            if (!context.mounted) return;
                            await context.read<Session>().api.uploadForm('recrutement/applications/$id/cv', {}, 'file', await f.first.readAsBytes(), f.first.name);
                            if (context.mounted) await context.read<SyncEngine>().sync();
                          } catch (e) {
                            if (context.mounted) toast(context, errorText(e), error: true);
                          }
                        },
                        icon: const Icon(Icons.upload_file),
                        label: Text(tr('Joindre le CV')),
                      ),
                  ]),
                  if ((a['cover_letter'] ?? '').toString().isNotEmpty) ...[
                    Text(tr('Message du candidat'), style: TextStyle(fontWeight: FontWeight.w700)),
                    Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Text(tr('${a['cover_letter']}'))),
                  ],
                  if (manage) ...[
                    const SizedBox(height: 6),
                    Text(tr('Étape'), style: TextStyle(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 6),
                    Wrap(spacing: 6, runSpacing: 6, children: [
                      for (final st in ['new', 'screening', 'interview', 'offer'])
                        ChoiceChip(
                          label: Text(tr(rcStages[st]!)),
                          selected: a['stage'] == st,
                          onSelected: a['stage'] == 'hired' ? null : (_) => context.read<SyncEngine>().save('rc_applications', {'id': id, 'stage': st}),
                        ),
                      if (a['stage'] != 'hired') ActionChip(avatar: const Icon(Icons.how_to_reg, size: 16, color: YColors.success), label: Text(tr('Embaucher')), onPressed: () => hireApplication(context, a)),
                      if (a['stage'] != 'hired' && a['stage'] != 'rejected')
                        ActionChip(
                          avatar: const Icon(Icons.block, size: 16, color: YColors.danger),
                          label: Text(tr('Ne pas retenir')),
                          onPressed: () async {
                            final r = await _askReason(context);
                            if (r != null && context.mounted) await context.read<SyncEngine>().save('rc_applications', {'id': id, 'stage': 'rejected', 'reject_reason': r});
                          },
                        ),
                      if (a['stage'] == 'hired' && a['employee_id'] != null) ActionChip(avatar: const Icon(Icons.description_outlined, size: 16), label: Text(tr('Établir le contrat')), onPressed: () => editRhDocument(context, employeeId: '${a['employee_id']}', type: 'contract')),
                    ]),
                    const SizedBox(height: 10),
                    _NotesField(app: a),
                  ],
                  const SizedBox(height: 12),
                  Row(children: [
                    Expanded(child: Text(tr('Entretiens'), style: TextStyle(fontWeight: FontWeight.w700))),
                    if (manage && a['stage'] != 'hired') TextButton.icon(onPressed: () => editInterview(context, applicationId: id), icon: const Icon(Icons.event, size: 18), label: Text(tr('Planifier'))),
                  ]),
                  if (ivs.isEmpty) Text(tr('Aucun entretien.'), style: TextStyle(color: Theme.of(context).hintColor)),
                  for (final i in ivs) _InterviewTile(iv: i, showCandidate: false),
                ]),
              ),
            ),
            actions: [
              if (manage) TextButton.icon(onPressed: () => messageCandidate(context, a), icon: const Icon(Icons.send_outlined), label: Text(tr('Écrire au candidat'))),
              if (manage && a['stage'] != 'hired')
                TextButton(
                  onPressed: () async {
                    if (await confirm(context, tr('Supprimer la candidature ?'), tr('Le CV et les entretiens seront effacés.'), danger: true)) {
                      if (!context.mounted) return;
                      await context.read<SyncEngine>().remove('rc_applications', id);
                      if (context.mounted) Navigator.pop(context);
                    }
                  },
                  child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                ),
            ],
          );
        },
      ),
    );
  }

  Widget _kv(IconData i, String t) => Row(mainAxisSize: MainAxisSize.min, children: [Icon(i, size: 16, color: YColors.muted), const SizedBox(width: 6), SelectableText(tr(t))]);
}

class _NotesField extends StatefulWidget {
  const _NotesField({required this.app});

  final Map<String, dynamic> app;

  @override
  State<_NotesField> createState() => _NotesFieldState();
}

class _NotesFieldState extends State<_NotesField> {
  late final _c = TextEditingController(text: '${widget.app['notes'] ?? ''}');
  bool _dirty = false;

  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
      TextField(controller: _c, maxLines: 3, minLines: 2, onChanged: (_) => setState(() => _dirty = true), decoration: InputDecoration(labelText: tr('Notes internes'))),
      if (_dirty)
        TextButton(
          onPressed: () async {
            await context.read<SyncEngine>().save('rc_applications', {'id': widget.app['id'], 'notes': _c.text.trim().isEmpty ? null : _c.text.trim()});
            setState(() => _dirty = false);
          },
          child: Text(tr('Enregistrer les notes')),
        ),
    ]);
  }
}

// ---------- Saisie d'un candidat ----------

Future<void> editApplication(BuildContext context) async {
  final sync = context.read<SyncEngine>();
  final api = context.read<Session>().api;
  final c = {for (final f in ['first_name', 'last_name', 'phone', 'email', 'city', 'cover_letter']) f: TextEditingController()};
  final jobs = LocalDb.all('rc_jobs').where((j) => j['status'] == 'open').toList();
  String? job = jobs.firstOrNull?['id'] as String?;
  var source = 'manual';
  List<int>? cv;
  String? cvName;
  final ok = await showFormDialog<bool>(context, title: 'Ajouter un candidat', builder: (d) => StatefulBuilder(
      builder: (d, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              Expanded(child: TextField(controller: c['first_name'], decoration: InputDecoration(labelText: tr('Prénoms *')))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: c['last_name'], decoration: InputDecoration(labelText: tr('Nom *')))),
            ]),
            Row(children: [
              Expanded(child: TextField(controller: c['phone'], keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone')))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: c['email'], keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: tr('E-mail')))),
            ]),
            TextField(controller: c['city'], decoration: InputDecoration(labelText: tr('Ville / commune'))),
            DropdownButtonFormField<String?>(
              initialValue: job,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Offre')),
              items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Candidature spontanée'))), for (final j in jobs) DropdownMenuItem<String?>(value: '${j['id']}', child: Text(tr('${j['title']}')))],
              onChanged: (v) => set(() => job = v),
            ),
            DropdownButtonFormField<String>(
              initialValue: source,
              decoration: InputDecoration(labelText: tr('Source')),
              items: [for (final e in rcSources.entries) if (e.key != 'website') DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
              onChanged: (v) => set(() => source = v!),
            ),
            TextField(controller: c['cover_letter'], maxLines: 3, decoration: InputDecoration(labelText: tr('Commentaire'))),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                onPressed: () async {
                  final f = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['pdf', 'doc', 'docx', 'odt', 'jpg', 'jpeg', 'png']);
                  if (f.isEmpty) return;
                  final b = await f.first.readAsBytes();
                  set(() => (cv = b, cvName = f.first.name));
                },
                icon: const Icon(Icons.attach_file),
                label: Text(tr(cvName ?? 'Joindre le CV')),
              ),
            ),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true || c['first_name']!.text.trim().isEmpty || c['last_name']!.text.trim().isEmpty) return;
  final id = newId();
  String? t(String f) => c[f]!.text.trim().isEmpty ? null : c[f]!.text.trim();
  await sync.save('rc_applications', {'id': id, 'job_id': job, 'first_name': t('first_name'), 'last_name': t('last_name'), 'phone': t('phone'), 'email': t('email'), 'city': t('city'), 'cover_letter': t('cover_letter'), 'source': source, 'stage': 'new'});
  if (cv != null) {
    try {
      await sync.sync();
      await api.uploadForm('recrutement/applications/$id/cv', {}, 'file', cv!, cvName ?? 'cv.pdf');
      await sync.sync();
    } catch (e) {
      if (context.mounted) toast(context, tr('Candidat enregistré ; le CV n\'a pas pu être envoyé (${errorText(e)}). Joignez-le depuis sa fiche une fois en ligne.'), error: true);
    }
  }
}

// ---------- Embauche ----------

Future<void> hireApplication(BuildContext context, Map<String, dynamic> a) async {
  final s = context.read<Session>();
  final job = a['job_id'] == null ? null : LocalDb.get('rc_jobs', a['job_id'] as String?);
  final title = TextEditingController(text: '${job?['title'] ?? ''}');
  final dep = TextEditingController(text: '${job?['department'] ?? ''}');
  final salary = TextEditingController();
  var contract = '${job?['contract_type'] ?? 'CDI'}';
  var date = DateTime.tryParse('${a['available_from'] ?? ''}') ?? DateTime.now().add(const Duration(days: 7));
  final ok = await showFormDialog<bool>(context, title: 'Embaucher ${rcName(a)}', builder: (d) => StatefulBuilder(
      builder: (d, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: title, decoration: InputDecoration(labelText: tr('Fonction'))),
            TextField(controller: dep, decoration: InputDecoration(labelText: tr('Service'))),
            DropdownButtonFormField<String>(initialValue: contract, decoration: InputDecoration(labelText: tr('Contrat')), items: [for (final e in rcContracts.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))], onChanged: (v) => set(() => contract = v!)),
            TextField(controller: salary, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Salaire de base mensuel'))),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: () async {
                final x = await showDatePicker(context: d, initialDate: date, firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (x != null) set(() => date = x);
              },
              icon: const Icon(Icons.event),
              label: Text(tr('Embauche le ${dateFr(isoDate(date))}')),
            ),
            const SizedBox(height: 6),
            Text(tr('Une fiche salarié est créée avec ces informations ; complétez-la ensuite (situation familiale, CNPS, paiement).'), style: TextStyle(fontSize: 12, color: Theme.of(d).hintColor)),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Embaucher'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true || !context.mounted) return;
  final sync = context.read<SyncEngine>();
  try {
    await sync.sync();
    final r = await s.api.post('recrutement/applications/${a['id']}/hire', {
      'hire_date': isoDate(date),
      'contract_type': contract,
      if (title.text.trim().isNotEmpty) 'job_title': title.text.trim(),
      if (dep.text.trim().isNotEmpty) 'department': dep.text.trim(),
      if (salary.text.trim().isNotEmpty) 'base_salary': toDouble(salary.text),
    });
    await sync.sync();
    if (!context.mounted) return;
    final empId = '${r['data']['employee_id']}';
    final next = await showDialog<bool>(
      context: context,
      builder: (d) => YAlertDialog(
        icon: const Icon(Icons.how_to_reg, color: YColors.success, size: 40),
        title: Text(tr('${rcName(a)} rejoint l\'équipe')),
        content: Text(tr('Sa fiche salarié est créée dans « Salariés & congés ». Établir son contrat de travail maintenant ?')),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Plus tard'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Établir le contrat')))],
      ),
    );
    if (next == true && context.mounted && s.can('rh.manage')) await editRhDocument(context, employeeId: empId, type: 'contract');
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ---------- Messages ----------

Future<void> messageCandidate(BuildContext context, Map a) async {
  final s = context.read<Session>();
  final company = '${s.company['name'] ?? ''}';
  final job = rcJobTitle(a['job_id'] as String?);
  final next = (LocalDb.all('rc_interviews').where((i) => i['application_id'] == a['id'] && i['status'] == 'planned').toList()..sort((x, y) => '${x['starts_at']}'.compareTo('${y['starts_at']}'))).firstOrNull;
  final templates = {
    'Convocation à un entretien':
        'Bonjour ${a['first_name']},\n\nSuite à votre candidature${a['job_id'] != null ? ' au poste de « $job »' : ''}, nous souhaitons vous rencontrer${next != null ? ' le ${dateFr('${next['starts_at']}'.substring(0, 10))} à ${'${next['starts_at']}'.substring(11, 16)}${next['location'] != null ? ' (${next['location']})' : ''}' : ''}.\nMerci de nous confirmer votre présence.\n\nCordialement,\n$company',
    'Réponse négative':
        'Bonjour ${a['first_name']},\n\nNous vous remercions de l\'intérêt porté à $company. Après étude attentive, nous ne donnerons pas suite à votre candidature${a['job_id'] != null ? ' au poste de « $job »' : ''}. Nous conservons votre CV pour de prochains recrutements, sauf avis contraire de votre part.\n\nNous vous souhaitons pleine réussite.\n$company',
    'Proposition d\'embauche':
        'Bonjour ${a['first_name']},\n\nNous avons le plaisir de vous proposer de rejoindre $company${a['job_id'] != null ? ' au poste de « $job »' : ''}. Nous vous contacterons pour convenir des modalités (date d\'arrivée, contrat).\n\nCordialement,\n$company',
  };
  final text = TextEditingController(text: templates.values.first);
  var channel = 'auto';
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr('Écrire à ${rcName(a)}')),
        content: SizedBox(
          width: 540,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Wrap(spacing: 6, children: [for (final e in templates.entries) ActionChip(label: Text(tr(e.key)), onPressed: () => set(() => text.text = e.value))]),
            const SizedBox(height: 8),
            TextField(controller: text, maxLines: 10, minLines: 6),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'auto', label: Text(tr('Automatique'))),
                if (a['email'] != null) ButtonSegment(value: 'email', label: Text(tr('E-mail'))),
                if (a['phone'] != null) ButtonSegment(value: 'whatsapp', label: Text(tr('WhatsApp'))),
                if (a['phone'] != null) ButtonSegment(value: 'sms', label: Text(tr('SMS'))),
              ],
              selected: {channel},
              onSelectionChanged: (v) => set(() => channel = v.first),
            ),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton.icon(onPressed: () => Navigator.pop(d, true), icon: const Icon(Icons.send), label: Text(tr('Envoyer')))],
      ),
    ),
  );
  if (ok != true) return;
  try {
    final r = await s.api.post('recrutement/applications/${a['id']}/message', {'text': text.text, 'channel': channel, 'subject': 'Votre candidature — $company'});
    if (context.mounted) toast(context, tr('Message envoyé (${{'email': 'e-mail', 'whatsapp': 'WhatsApp', 'sms': 'SMS'}[r['data']['channel']] ?? r['data']['channel']}).'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ---------- Offres ----------

class _JobsTab extends StatefulWidget {
  const _JobsTab();

  @override
  State<_JobsTab> createState() => _JobsTabState();
}

class _JobsTabState extends State<_JobsTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final manage = context.read<Session>().can('recrutement.manage');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rc_jobs'),
      builder: (context, _, _) {
        final all = LocalDb.all('rc_jobs')..sort((a, b) => '${a['status'] == 'open' ? 0 : (a['status'] == 'draft' ? 1 : 2)}${b['created_at'] ?? ''}'.compareTo('${b['status'] == 'open' ? 0 : (b['status'] == 'draft' ? 1 : 2)}${a['created_at'] ?? ''}'));
        final apps = LocalDb.all('rc_applications');
        if (all.isEmpty) return EmptyState(icon: Icons.work_outline, title: tr('Aucune offre'), message: tr('Créez une offre : publiée, elle apparaît sur votre page Emplois.'));
        bool base(Map<String, dynamic> j) =>
            (_q.isEmpty || '${j['title']} ${j['department'] ?? ''} ${j['location'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
            (_f['contract'] == null || '${j['contract_type']}' == _f['contract']) &&
            (_f['department'] == null || '${j['department'] ?? ''}' == _f['department']) &&
            (_f['location'] == null || '${j['location'] ?? ''}' == _f['location']) &&
            (_f['published'] == null || (_f['published'] == 'yes') == (j['published'] != false)) &&
            (_period == null || _period!.contains(j['created_at']));
        bool st(Map<String, dynamic> j, String k) => k.isEmpty || j['status'] == k;
        final jobs = all.where((j) => base(j) && st(j, _status)).toList();
        const stKeys = ['', 'open', 'draft', 'closed'];
        return ListView(padding: const EdgeInsets.all(16), children: [
          FilterBar(
            padding: const EdgeInsets.only(bottom: 8),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Intitulé, service, lieu…',
            statuses: const {'': 'Toutes', 'open': 'Ouvertes', 'draft': 'Brouillons', 'closed': 'Clôturées'},
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in stKeys) k: all.where((j) => base(j) && st(j, k)).length},
            filters: [
              const FilterSpec('contract', 'Contrat', rcContracts, icon: Icons.description_outlined),
              FilterSpec('department', 'Service', filterOptions(all, 'department'), icon: Icons.apartment_outlined),
              FilterSpec('location', 'Lieu', filterOptions(all, 'location'), icon: Icons.place_outlined),
              const FilterSpec('published', 'Page Emplois', {'yes': 'Publiée', 'no': 'Non publiée'}, icon: Icons.public),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: jobs.length,
            countLabel: 'offre',
            onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
          ),
          for (final j in jobs)
            Card(
              child: ListTile(
                leading: Icon(Icons.work_outline, color: j['status'] == 'open' ? YColors.success : YColors.muted),
                title: Text(tr('${j['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(tr([
                  rcContracts[j['contract_type']] ?? '${j['contract_type']}',
                  if (j['location'] != null) '${j['location']}',
                  '${apps.where((a) => a['job_id'] == j['id']).length} candidature(s)',
                  '${apps.where((a) => a['job_id'] == j['id'] && a['stage'] == 'hired').length}/${j['positions'] ?? 1} pourvu(s)',
                  if (j['closes_at'] != null) 'jusqu\'au ${dateFr(j['closes_at'])}',
                ].join(' · '))),
                trailing: StatusChip({'draft': 'Brouillon', 'open': j['published'] == false ? 'Ouverte (non publiée)' : 'En ligne', 'closed': 'Clôturée'}[j['status']] ?? '', color: j['status'] == 'open' ? YColors.success : YColors.muted),
                onTap: manage ? () => editJob(context, j) : null,
              ),
            ),
        ]);
      },
    );
  }
}

Future<void> editJob(BuildContext context, [Map<String, dynamic>? j]) async {
  final sync = context.read<SyncEngine>();
  final c = {for (final f in ['title', 'department', 'location', 'salary', 'description', 'requirements']) f: TextEditingController(text: '${j?[f] ?? ''}')};
  final positions = TextEditingController(text: '${j?['positions'] ?? 1}');
  var contract = '${j?['contract_type'] ?? 'CDI'}';
  var status = '${j?['status'] ?? 'open'}';
  var published = j?['published'] != false;
  DateTime? closes = DateTime.tryParse('${j?['closes_at'] ?? ''}');
  final r = await showFormDialog<String>(context, title: j == null ? 'Nouvelle offre d\'emploi' : '${j['title']}', maxWidth: 668, builder: (d) => StatefulBuilder(
      builder: (d, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: c['title'], decoration: InputDecoration(labelText: tr('Intitulé du poste *'))),
            Row(children: [
              Expanded(child: TextField(controller: c['department'], decoration: InputDecoration(labelText: tr('Service')))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: c['location'], decoration: InputDecoration(labelText: tr('Lieu')))),
            ]),
            Row(children: [
              Expanded(child: DropdownButtonFormField<String>(initialValue: contract, decoration: InputDecoration(labelText: tr('Contrat')), items: [for (final e in rcContracts.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))], onChanged: (v) => set(() => contract = v!))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: c['salary'], decoration: InputDecoration(labelText: tr('Salaire affiché (facultatif)')))),
              const SizedBox(width: 8),
              SizedBox(width: 90, child: TextField(controller: positions, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Postes')))),
            ]),
            TextField(controller: c['description'], maxLines: 6, minLines: 4, decoration: InputDecoration(labelText: tr('Le poste (missions, horaires…) *'))),
            TextField(controller: c['requirements'], maxLines: 4, minLines: 2, decoration: InputDecoration(labelText: tr('Profil recherché'))),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: SegmentedButton<String>(
                  segments: [ButtonSegment(value: 'draft', label: Text(tr('Brouillon'))), ButtonSegment(value: 'open', label: Text(tr('Ouverte'))), ButtonSegment(value: 'closed', label: Text(tr('Clôturée')))],
                  selected: {status},
                  onSelectionChanged: (v) => set(() => status = v.first),
                ),
              ),
            ]),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Publier sur la page Emplois')), value: published, onChanged: (v) => set(() => published = v)),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: () async {
                  final x = await showDatePicker(context: d, initialDate: closes ?? DateTime.now().add(const Duration(days: 30)), firstDate: DateTime.now(), lastDate: DateTime(2100));
                  set(() => closes = x);
                },
                icon: const Icon(Icons.event),
                label: Text(tr(closes == null ? 'Date limite (facultative)' : 'Candidatures jusqu\'au ${dateFr(isoDate(closes!))}')),
              ),
            ),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              if (j != null) TextButton(onPressed: () => Navigator.pop(d, 'delete'), child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
              TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
              FilledButton(onPressed: () => Navigator.pop(d, 'save'), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  try {
    if (r == 'delete') await sync.remove('rc_jobs', '${j!['id']}');
    if (r != 'save' || c['title']!.text.trim().isEmpty) return;
    String? t(String f) => c[f]!.text.trim().isEmpty ? null : c[f]!.text.trim();
    await sync.save('rc_jobs', {
      'id': j?['id'] ?? newId(),
      'title': t('title'),
      'department': t('department'),
      'location': t('location'),
      'salary': t('salary'),
      'description': t('description'),
      'requirements': t('requirements'),
      'contract_type': contract,
      'positions': int.tryParse(positions.text) ?? 1,
      'status': status,
      'published': published,
      'closes_at': closes == null ? null : isoDate(closes!),
    });
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ---------- Entretiens ----------

Future<void> editInterview(BuildContext context, {required String applicationId, Map<String, dynamic>? iv}) async {
  final s = context.read<Session>();
  final sync = context.read<SyncEngine>();
  List<Map<String, dynamic>> users = [];
  try {
    users = [for (final u in ((await s.api.get('recrutement/users'))['data'] as List)) asMap(u)];
  } catch (_) {}
  if (!context.mounted) return;
  var at = DateTime.tryParse('${iv?['starts_at'] ?? ''}') ?? DateTime.now().add(const Duration(days: 1)).copyWith(hour: 10, minute: 0);
  final loc = TextEditingController(text: '${iv?['location'] ?? ''}');
  var duration = (iv?['duration'] as num?)?.toInt() ?? 45;
  final who = <int>{...((iv?['interviewer_ids'] as List?) ?? [s.user['id']]).map((e) => (e as num).toInt())};
  final ok = await showDialog<String>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr('Entretien')),
        content: SizedBox(
          width: 460,
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      final x = await showDatePicker(context: d, initialDate: at, firstDate: DateTime(2020), lastDate: DateTime(2100));
                      if (x != null) set(() => at = DateTime(x.year, x.month, x.day, at.hour, at.minute));
                    },
                    icon: const Icon(Icons.event),
                    label: Text(tr(dateFr(isoDate(at)))),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      final t = await showTimePicker(context: d, initialTime: TimeOfDay.fromDateTime(at));
                      if (t != null) set(() => at = DateTime(at.year, at.month, at.day, t.hour, t.minute));
                    },
                    icon: const Icon(Icons.schedule),
                    label: Text(tr('${at.hour.toString().padLeft(2, '0')}:${at.minute.toString().padLeft(2, '0')}')),
                  ),
                ),
              ]),
              DropdownButtonFormField<int>(initialValue: [30, 45, 60, 90, 120].contains(duration) ? duration : 45, decoration: InputDecoration(labelText: tr('Durée')), items: [for (final m in [30, 45, 60, 90, 120]) DropdownMenuItem(value: m, child: Text(tr('$m min')))], onChanged: (v) => set(() => duration = v!)),
              TextField(controller: loc, decoration: InputDecoration(labelText: tr('Lieu'), hintText: tr('Bureau, lien de visioconférence, par téléphone…'))),
              const SizedBox(height: 10),
              Text(tr('Intervenants'), style: TextStyle(fontWeight: FontWeight.w600)),
              if (users.isEmpty) Text(tr('Liste disponible en ligne.'), style: TextStyle(color: Theme.of(d).hintColor)),
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final u in users.where((u) => u['is_active'] != false))
                  FilterChip(label: Text(tr('${u['name']}')), selected: who.contains((u['id'] as num).toInt()), onSelected: (v) => set(() => v ? who.add((u['id'] as num).toInt()) : who.remove((u['id'] as num).toInt()))),
              ]),
            ]),
          ),
        ),
        actions: [
          if (iv != null) TextButton(onPressed: () => Navigator.pop(d, 'cancel'), child: Text(tr('Annuler l\'entretien'), style: TextStyle(color: YColors.danger))),
          TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Fermer'))),
          FilledButton(onPressed: () => Navigator.pop(d, 'save'), child: Text(tr('Enregistrer'))),
        ],
      ),
    ),
  );
  if (ok == 'cancel') await sync.save('rc_interviews', {'id': iv!['id'], 'status': 'cancelled'});
  if (ok != 'save') return;
  String p(int v) => v.toString().padLeft(2, '0');
  await sync.save('rc_interviews', {
    'id': iv?['id'] ?? newId(),
    'application_id': applicationId,
    'starts_at': '${isoDate(at)} ${p(at.hour)}:${p(at.minute)}:00',
    'duration': duration,
    'location': loc.text.trim().isEmpty ? null : loc.text.trim(),
    'interviewer_ids': who.toList(),
  });
}

Future<void> interviewFeedback(BuildContext context, Map<String, dynamic> iv) async {
  final sync = context.read<SyncEngine>();
  var score = (iv['score'] as num?)?.toInt() ?? 0;
  var rec = iv['recommendation'] as String?;
  final fb = TextEditingController(text: '${iv['feedback'] ?? ''}');
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr('Mon avis sur l\'entretien')),
        content: SizedBox(
          width: 460,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [for (var i = 1; i <= 5; i++) IconButton(onPressed: () => set(() => score = i), icon: Icon(i <= score ? Icons.star : Icons.star_border, color: i <= score ? YColors.yellow : YColors.muted, size: 30))]),
            SegmentedButton<String>(
              emptySelectionAllowed: true,
              segments: [ButtonSegment(value: 'yes', label: Text(tr('À retenir'))), ButtonSegment(value: 'maybe', label: Text(tr('À voir'))), ButtonSegment(value: 'no', label: Text(tr('Ne pas retenir')))],
              selected: {?rec},
              onSelectionChanged: (v) => set(() => rec = v.firstOrNull),
            ),
            const SizedBox(height: 8),
            TextField(controller: fb, maxLines: 6, minLines: 3, decoration: InputDecoration(labelText: tr('Compte rendu (points forts, réserves)'))),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: score == 0 ? null : () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
      ),
    ),
  );
  if (ok != true) return;
  await sync.save('rc_interviews', {'id': iv['id'], 'status': 'done', 'score': score, 'recommendation': rec, 'feedback': fb.text.trim().isEmpty ? null : fb.text.trim()});
}

class _InterviewTile extends StatelessWidget {
  const _InterviewTile({required this.iv, this.showCandidate = true});

  final Map<String, dynamic> iv;
  final bool showCandidate;

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final manage = s.can('recrutement.manage');
    final mine = ((iv['interviewer_ids'] as List?) ?? []).map((e) => (e as num).toInt()).contains(s.user['id']);
    final app = LocalDb.get('rc_applications', iv['application_id'] as String?);
    final at = '${iv['starts_at']}';
    final past = at.compareTo(isoDate(DateTime.now())) < 0;
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: Icon(iv['status'] == 'done' ? Icons.task_alt : (iv['status'] == 'cancelled' ? Icons.event_busy : Icons.event), color: iv['status'] == 'done' ? YColors.success : (iv['status'] == 'cancelled' ? YColors.muted : (past ? YColors.danger : YColors.warning))),
      title: Text(tr([if (showCandidate) app == null ? 'Candidat' : rcName(app), '${dateFr(at.substring(0, 10))} à ${at.substring(11, 16)}'].join(' — '))),
      subtitle: Text(tr([
        if (showCandidate && app != null) rcJobTitle(app['job_id'] as String?),
        if (iv['location'] != null) '${iv['location']}',
        if (iv['status'] == 'done') '${(iv['score'] as num?)?.toInt() ?? 0}/5${{'yes': ', à retenir', 'maybe': ', à voir', 'no': ', ne pas retenir'}[iv['recommendation']] ?? ''}',
        if (iv['feedback'] != null) '« ${iv['feedback']} »',
        if (iv['status'] == 'cancelled') 'annulé',
      ].join(' · '))),
      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
        if (iv['status'] != 'cancelled' && (mine || (manage && iv['status'] != 'done'))) TextButton(onPressed: () => interviewFeedback(context, iv), child: Text(tr(iv['status'] == 'done' ? 'Modifier mon avis' : 'Donner mon avis'))),
        if (manage && iv['status'] == 'planned') IconButton(tooltip: tr('Modifier'), icon: const Icon(Icons.edit_outlined), onPressed: () => editInterview(context, applicationId: '${iv['application_id']}', iv: iv)),
      ]),
      onTap: showCandidate && app != null ? () => showApplication(context, '${app['id']}') : null,
    );
  }
}

class _InterviewsTab extends StatefulWidget {
  const _InterviewsTab();

  @override
  State<_InterviewsTab> createState() => _InterviewsTabState();
}

class _InterviewsTabState extends State<_InterviewsTab> {
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final me = context.read<Session>().user['id'];
    final view = context.read<Session>().can('recrutement.view') || context.read<Session>().can('recrutement.manage');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rc_interviews'),
      builder: (context, _, _) {
        bool mine(Map i) => ((i['interviewer_ids'] as List?) ?? []).map((e) => (e as num).toInt()).contains(me);
        final visible = LocalDb.all('rc_interviews').where((i) => view || mine(i)).toList();
        Map<String, dynamic>? appOf(Map i) => LocalDb.get('rc_applications', i['application_id'] as String?);
        final q = _q.toLowerCase();
        final all = visible.where((i) {
          final app = appOf(i);
          return (q.isEmpty || '${app == null ? '' : rcName(app)} ${i['location'] ?? ''} ${i['feedback'] ?? ''}'.toLowerCase().contains(q)) &&
              (_f['job'] == null || '${app?['job_id'] ?? '__spont'}' == _f['job']) &&
              (_f['mine'] == null || mine(i)) &&
              (_f['recommendation'] == null || '${i['recommendation']}' == _f['recommendation']) &&
              (_period == null || _period!.contains('${i['starts_at']}'.length >= 10 ? '${i['starts_at']}'.substring(0, 10) : i['starts_at']));
        }).toList();
        final jobOpts = <String, String>{
          for (final i in visible)
            if (appOf(i) != null) '${appOf(i)!['job_id'] ?? '__spont'}': rcJobTitle(appOf(i)!['job_id'] as String?),
        };
        final today = isoDate(DateTime.now());
        final upcoming = all.where((i) => i['status'] == 'planned' && '${i['starts_at']}'.compareTo(today) >= 0).toList()..sort((a, b) => '${a['starts_at']}'.compareTo('${b['starts_at']}'));
        final toReview = all.where((i) => i['status'] == 'planned' && '${i['starts_at']}'.compareTo(today) < 0).toList();
        final done = all.where((i) => i['status'] == 'done').toList()..sort((a, b) => '${b['starts_at']}'.compareTo('${a['starts_at']}'));
        if (visible.isEmpty) return EmptyState(icon: Icons.event, title: tr('Aucun entretien'), message: tr('Les entretiens planifiés depuis la fiche d\'un candidat apparaissent ici.'));
        return ListView(padding: const EdgeInsets.all(16), children: [
          FilterBar(
            padding: const EdgeInsets.only(bottom: 12),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Candidat, lieu, avis…',
            filters: [
              FilterSpec('job', 'Offre', Map.fromEntries(jobOpts.entries.toList()..sort((a, b) => a.value.compareTo(b.value))), icon: Icons.work_outline),
              const FilterSpec('mine', 'Participants', {'me': 'Mes entretiens'}, icon: Icons.person_outline),
              const FilterSpec('recommendation', 'Avis', {'yes': 'À retenir', 'maybe': 'À voir', 'no': 'Ne pas retenir'}, icon: Icons.thumbs_up_down_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: all.length,
            countLabel: 'entretien',
            onReset: () => setState(() => (_q = '', _f = {}, _period = null)),
          ),
          if (toReview.isNotEmpty) SectionCard(title: tr('Avis attendus (${toReview.length})'), child: Column(children: [for (final i in toReview) _InterviewTile(iv: i)])),
          if (toReview.isNotEmpty) const SizedBox(height: 12),
          SectionCard(title: tr('À venir (${upcoming.length})'), child: Column(children: [if (upcoming.isEmpty) Text(tr('Rien de prévu.'), style: TextStyle(color: Theme.of(context).hintColor)), for (final i in upcoming) _InterviewTile(iv: i)])),
          const SizedBox(height: 12),
          if (done.isNotEmpty) SectionCard(title: tr('Réalisés'), child: Column(children: [for (final i in done.take(30)) _InterviewTile(iv: i)])),
        ]);
      },
    );
  }
}

// ---------- Statistiques ----------

class _StatsTab extends StatefulWidget {
  const _StatsTab();

  @override
  State<_StatsTab> createState() => _StatsTabState();
}

class _StatsTabState extends State<_StatsTab> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('recrutement/stats');
      if (mounted) setState(() => (_d = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final d = _d!;
    final byStage = asMap(d['by_stage']);
    final bySource = asMap(d['by_source']);
    final reasons = asMap(d['reasons']);
    final total = (d['total'] as num).toInt();
    Widget bar(String label, num v, Color c) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            SizedBox(width: 160, child: Text(tr(label))),
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(value: total == 0 ? 0 : v / total, minHeight: 14, color: c, backgroundColor: c.withValues(alpha: 0.12)),
              ),
            ),
            SizedBox(width: 44, child: Text(tr('$v'), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w700))),
          ]),
        );
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Wrap(spacing: 12, runSpacing: 12, children: [
          for (final k in [('Candidatures', '$total', Icons.groups_outlined), ('Embauches', '${byStage['hired'] ?? 0}', Icons.how_to_reg), ('Délai moyen d\'embauche', d['days_to_hire'] == null ? '—' : '${number(d['days_to_hire'], decimals: 1)} j', Icons.timer_outlined), ('Entretiens à venir', '${d['interviews']}', Icons.event)])
            KpiBox(width: 230, child: StatTile(label: k.$1, value: k.$2, icon: k.$3)),
        ]),
        const SizedBox(height: 12),
        SectionCard(title: tr('Par étape'), child: Column(children: [for (final e in rcStages.entries) bar(e.value, (byStage[e.key] as num?) ?? 0, rcStageColor(e.key))])),
        const SizedBox(height: 12),
        SectionCard(title: tr('D\'où viennent les candidats'), child: Column(children: [for (final e in bySource.entries) bar(rcSources[e.key] ?? e.key, e.value as num, YColors.blue)])),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Par offre'),
          child: Column(children: [
            for (final j in (d['jobs'] as List))
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('${j['title']}')),
                subtitle: Text(tr('${j['applications']} candidature(s) · ${j['in_progress']} en cours · ${j['hired']}/${j['positions']} pourvu(s)')),
                trailing: StatusChip({'draft': 'Brouillon', 'open': 'Ouverte', 'closed': 'Clôturée'}[j['status']] ?? '', color: j['status'] == 'open' ? YColors.success : YColors.muted),
              ),
          ]),
        ),
        if (reasons.isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(title: tr('Motifs de non-sélection'), child: Column(children: [for (final e in reasons.entries) bar(e.key, e.value as num, YColors.danger)])),
        ],
      ]),
    );
  }
}
