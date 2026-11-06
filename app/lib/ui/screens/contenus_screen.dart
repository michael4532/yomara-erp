import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/records_offline.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Espace du service marketing & communication : stratégie, calendrier éditorial, actions, veille, presse.
class ContenusScreen extends StatefulWidget {
  const ContenusScreen({super.key});

  @override
  State<ContenusScreen> createState() => _ContenusScreenState();
}

class _ContenusScreenState extends State<ContenusScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    final staff = s.can('contenus.view') || s.can('contenus.manage') || s.can('contenus.validate');
    void changed() => _dash.currentState?.reload();
    final tabs = <(String, Widget)>[
      if (staff) ('Calendrier éditorial', _EditorialCalendar(onChanged: changed)),
      if (staff) ('Stratégies', RecordsView(module: 'contenus', resource: 'plans', emptyIcon: Icons.flag_outlined, newLabel: tr('Nouvelle stratégie'), onChanged: changed,
          emptyMessage: tr('Fixez pour chaque période les objectifs, les cibles, le message clé, les canaux et le budget.'))),
      if (staff) ('Actions & campagnes', RecordsView(module: 'contenus', resource: 'actions', emptyIcon: Icons.campaign_outlined, newLabel: tr('Nouvelle action'), onChanged: changed)),
      ('Veille concurrentielle', RecordsView(module: 'contenus', resource: 'observations', emptyIcon: Icons.visibility_outlined, newLabel: tr('Signaler'), onChanged: changed,
          emptyMessage: tr('Notez ce que font vos concurrents : prix, nouveautés, campagnes, publications…'))),
      if (staff) ('Concurrents', RecordsView(module: 'contenus', resource: 'competitors', emptyIcon: Icons.groups_outlined, newLabel: tr('Nouveau concurrent'), onChanged: changed)),
      if (staff) ('Réseaux sociaux', const _SocialTab()),
      if (staff) ('Presse & communication', RecordsView(module: 'contenus', resource: 'press', emptyIcon: Icons.newspaper_outlined, newLabel: tr('Nouveau'), onChanged: changed)),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Stratégie marketing & communication'), subtitle: tr('Plans, calendrier éditorial, campagnes, veille concurrentielle et presse')),
        if (staff)
          ModuleDashboard(
            key: _dash,
            path: 'contenus/dashboard',
            builder: (context, d, _) => StatStrip(tiles: [
              StatTile(label: tr('Publications cette semaine'), value: '${d['week']}', detail: tr('${d['published_month']} publiée(s) ce mois'), icon: Icons.calendar_month_outlined),
              StatTile(label: tr('À valider'), value: '${d['review']}', detail: tr('${d['late']} en retard'), icon: Icons.fact_check_outlined, color: const Color(0xFF9333EA)),
              StatTile(label: tr('Budget des stratégies en cours'), value: money(toDouble(d['budget']), cur), detail: tr('${money(toDouble(d['spent']), cur)} dépensés'), icon: Icons.account_balance_wallet_outlined, color: YColors.success),
              StatTile(label: tr('Veille ce mois'), value: '${d['observations_month']}', detail: tr('${d['threats']} concurrent(s) à forte menace'), icon: Icons.visibility_outlined, color: YColors.danger),
            ]),
          ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

/// Calendrier éditorial : vue mois (publications par jour, couleur du statut) ou liste.
class _EditorialCalendar extends StatefulWidget {
  const _EditorialCalendar({required this.onChanged});

  final VoidCallback onChanged;

  @override
  State<_EditorialCalendar> createState() => _EditorialCalendarState();
}

class _EditorialCalendarState extends State<_EditorialCalendar> {
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  bool _list = false;
  String? _network;
  RecordSchema? _schema;
  List<Map<String, dynamic>> _posts = [];
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  DateTime get _gridStart => _month.subtract(Duration(days: _month.weekday - 1));

  Future<void> _load() async {
    try {
      final s = await RecordSchema.load(context, 'contenus');
      if (!mounted) return;
      final end = _gridStart.add(const Duration(days: 42));
      final r = await RecordsOffline.get(context.read<Session>().api, 'contenus/records/posts', query: {
        'from': isoDate(_gridStart), 'to': isoDate(end), 'networks': ?_network,
      });
      if (mounted) setState(() => (_schema = s, _posts = [for (final x in ((r as Map)['data'] as List)) asMap(x)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _open({Map<String, dynamic>? post, DateTime? day}) async {
    final s = _schema;
    if (s == null) return;
    final r = await editRecord(context, s, 'posts', row: post,
        initial: day == null ? null : {'scheduled_at': DateFormat('yyyy-MM-dd HH:mm:ss').format(DateTime(day.year, day.month, day.day, 10))},
        actions: (dialog, row, _) => _workflow(dialog, row));
    if (r != null) {
      _load();
      widget.onChanged();
    }
  }

  /// Boutons du circuit de validation selon le statut et les droits.
  List<Widget> _workflow(BuildContext dialog, Map<String, dynamic> row) {
    final s = context.read<Session>();
    Future<void> move(String to) async {
      try {
        await RecordsOffline.send(s.api, 'PUT', 'contenus/records/posts/${row['id']}', {'status': to}, 'Publication « ${row['title']} »');
        if (dialog.mounted) Navigator.pop(dialog, <String, dynamic>{'status': to});
      } catch (e) {
        if (dialog.mounted) toast(dialog, errorText(e), error: true);
      }
    }

    final st = row['status'];
    return [
      if (['idea', 'writing'].contains(st) && s.can('contenus.manage'))
        OutlinedButton.icon(onPressed: () => move('review'), icon: const Icon(Icons.send_outlined, size: 18), label: Text(tr('Soumettre à validation'))),
      if (st == 'review' && s.can('contenus.validate')) ...[
        OutlinedButton.icon(onPressed: () => move('writing'), icon: const Icon(Icons.undo, size: 18), label: Text(tr('À reprendre'))),
        FilledButton.tonalIcon(onPressed: () => move('approved'), icon: const Icon(Icons.verified_outlined, size: 18), label: Text(tr('Valider'))),
      ],
      if ((st == 'approved' || (st != 'published' && st != 'cancelled' && s.can('contenus.validate'))) && (s.can('contenus.manage') || s.can('contenus.validate')))
        SaveButton(
          onPressed: () async {
            try {
              final r = asMap(((await s.api.post('contenus/posts/${row['id']}/publish')) as Map)['data']);
              final ok = r.values.where((x) => (x as Map)['id'] != null).length;
              final ko = r.entries.where((e) => (e.value as Map)['error'] != null).map((e) => '${e.key} : ${(e.value as Map)['error']}').toList();
              if (dialog.mounted) {
                Navigator.pop(dialog, <String, dynamic>{'published': true});
                toast(dialog, ko.isEmpty ? 'Publiée sur $ok réseau(x).' : 'Publiée sur $ok réseau(x). ${ko.join(' · ')}', error: ko.isNotEmpty && ok == 0);
              }
            } catch (e) {
              if (dialog.mounted) toast(dialog, errorText(e), error: true);
            }
          },
          icon: const Icon(Icons.rocket_launch_outlined, size: 18),
          child: Text(tr('Publier maintenant')),
        ),
      if (st == 'approved' && (s.can('contenus.manage') || s.can('contenus.validate')))
        OutlinedButton.icon(onPressed: () => move('published'), icon: const Icon(Icons.public, size: 18), label: Text(tr('Marquer publiée'))),
    ];
  }

  @override
  Widget build(BuildContext context) {
    if (_list) {
      return Column(children: [
        _bar(),
        Expanded(child: RecordsView(module: 'contenus', resource: 'posts', emptyIcon: Icons.calendar_month_outlined, newLabel: tr('Nouvelle publication'), onChanged: widget.onChanged,
            actions: (dialog, row, _) => _workflow(dialog, row))),
      ]);
    }
    if (_error != null && _schema == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_schema == null) return const Center(child: CircularProgressIndicator());
    final f = _schema!.field('posts', 'status')!;
    final nets = _schema!.field('posts', 'networks')!;
    final today = DateTime.now();
    final byDay = <String, List<Map<String, dynamic>>>{};
    for (final p in _posts) {
      final d = DateTime.tryParse('${p['scheduled_at']}');
      if (d != null) byDay.putIfAbsent(isoDate(d), () => []).add(p);
    }
    final narrow = MediaQuery.of(context).size.width < 700;
    return Column(children: [
      _bar(),
      Padding(
        padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 8),
        child: Wrap(spacing: 14, runSpacing: 4, children: [
          for (final o in recordOptions(f))
            Row(mainAxisSize: MainAxisSize.min, children: [
              Container(width: 9, height: 9, decoration: BoxDecoration(color: _color(o), shape: BoxShape.circle)),
              const SizedBox(width: 5),
              Text(tr('${o['label']}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
            ]),
        ]),
      ),
      Expanded(
        child: SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(narrow ? 8 : 24, 0, narrow ? 8 : 24, 24),
          child: Card(
            clipBehavior: Clip.antiAlias,
            child: Column(children: [
              Row(children: [
                for (final d in ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'])
                  Expanded(child: Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text(tr(d), textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12, color: Theme.of(context).hintColor)))),
              ]),
              for (var w = 0; w < 6; w++)
                IntrinsicHeight(
                  child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    for (var i = 0; i < 7; i++)
                      Expanded(child: _day(_gridStart.add(Duration(days: w * 7 + i)), byDay, f, nets, today, narrow)),
                  ]),
                ),
            ]),
          ),
        ),
      ),
    ]);
  }

  Color _color(Map o) {
    final h = '${o['color'] ?? ''}';
    final v = h.length == 7 && h.startsWith('#') ? int.tryParse(h.substring(1), radix: 16) : null;
    return Color(0xFF000000 | (v ?? 0x64748B));
  }

  Widget _day(DateTime d, Map<String, List<Map<String, dynamic>>> byDay, Map f, Map nets, DateTime today, bool narrow) {
    final inMonth = d.month == _month.month;
    final isToday = isoDate(d) == isoDate(today);
    final posts = byDay[isoDate(d)] ?? const [];
    final border = Theme.of(context).dividerColor.withValues(alpha: .5);
    return InkWell(
      onTap: context.read<Session>().can('contenus.manage') ? () => _open(day: d) : null,
      child: Container(
        constraints: BoxConstraints(minHeight: narrow ? 64 : 104),
        padding: const EdgeInsets.all(5),
        decoration: BoxDecoration(
          color: inMonth ? null : Theme.of(context).hintColor.withValues(alpha: .04),
          border: Border(top: BorderSide(color: border), left: BorderSide(color: border)),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
            decoration: isToday ? BoxDecoration(color: YColors.blue, borderRadius: BorderRadius.circular(10)) : null,
            child: Text(tr('${d.day}'), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: isToday ? Colors.white : (inMonth ? null : Theme.of(context).hintColor))),
          ),
          const SizedBox(height: 3),
          for (final p in posts.take(narrow ? 2 : 4))
            Padding(
              padding: const EdgeInsets.only(bottom: 3),
              child: InkWell(
                onTap: () => _open(post: p),
                borderRadius: BorderRadius.circular(6),
                child: Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 3),
                  decoration: BoxDecoration(
                    color: _color(recordOption(f, p['status']) ?? const {}).withValues(alpha: .12),
                    border: Border(left: BorderSide(color: _color(recordOption(f, p['status']) ?? const {}), width: 3)),
                    borderRadius: BorderRadius.circular(5),
                  ),
                  child: Text(
                    tr(narrow ? '${p['title']}' : '${DateFormat('HH:mm').format(DateTime.parse('${p['scheduled_at']}'))} ${p['title']}'),
                    maxLines: narrow ? 1 : 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600),
                  ),
                ),
              ),
            ),
          if (posts.length > (narrow ? 2 : 4)) Text(tr('+${posts.length - (narrow ? 2 : 4)}'), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
        ]),
      ),
    );
  }

  Widget _bar() {
    final narrow = MediaQuery.of(context).size.width < 700;
    final nets = _schema?.field('posts', 'networks');
    return Padding(
      padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 14, 24, 10),
      child: Wrap(spacing: 10, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
        SegmentedButton<bool>(
          showSelectedIcon: false,
          segments: [ButtonSegment(value: false, icon: Icon(Icons.calendar_view_month, size: 18), label: Text(tr('Mois'))), ButtonSegment(value: true, icon: Icon(Icons.view_list, size: 18), label: Text(tr('Liste')))],
          selected: {_list},
          onSelectionChanged: (v) => setState(() => _list = v.first),
        ),
        if (!_list) ...[
          IconButton(onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month - 1), _load())), icon: const Icon(Icons.chevron_left)),
          SizedBox(
            width: 150,
            child: Text(tr(toBeginningOfSentenceCase(DateFormat('MMMM yyyy', I18n.dateLocale).format(_month))), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          ),
          IconButton(onPressed: () => setState(() => (_month = DateTime(_month.year, _month.month + 1), _load())), icon: const Icon(Icons.chevron_right)),
          TextButton(onPressed: () => setState(() => (_month = DateTime(DateTime.now().year, DateTime.now().month), _load())), child: Text(tr('Aujourd\'hui'))),
          if (nets != null)
            DropdownButton<String?>(
              value: _network,
              hint: Text(tr('Tous les réseaux')),
              underline: const SizedBox(),
              items: [DropdownMenuItem(value: null, child: Text(tr('Tous les réseaux'))), for (final o in recordOptions(nets)) DropdownMenuItem(value: '${o['value']}', child: Text(tr('${o['label']}')))],
              onChanged: (v) => setState(() => (_network = v, _load())),
            ),
          if (context.read<Session>().can('contenus.manage'))
            FilledButton.icon(onPressed: () => _open(day: DateTime.now()), icon: const Icon(Icons.add, size: 18), label: Text(tr('Nouvelle publication'))),
        ],
      ]),
    );
  }
}


/// Comptes connectés pour la publication automatique : page Facebook (+ Instagram professionnel relié), page LinkedIn.
class _SocialTab extends StatefulWidget {
  const _SocialTab();

  @override
  State<_SocialTab> createState() => _SocialTabState();
}

class _SocialTabState extends State<_SocialTab> {
  List<Map<String, dynamic>>? _rows;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('contenus/social/accounts');
      if (mounted) setState(() => _rows = [for (final x in ((r as Map)['data'] as List)) asMap(x)]);
    } catch (e) {
      if (mounted) setState(() => _rows = []);
    }
  }

  Future<void> _connect(String platform) async {
    final token = TextEditingController(), id = TextEditingController();
    final meta = platform == 'meta';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr(meta ? 'Connecter Facebook et Instagram' : 'Connecter LinkedIn')),
        content: SizedBox(
          width: 500,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(
              tr(meta
                  ? 'Dans Meta Business Suite (ou l\'Explorateur de l\'API Graph), générez un jeton d\'accès de page longue durée avec les droits pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish et instagram_manage_insights. Le compte Instagram professionnel relié à la page est connecté en même temps.'
                  : 'Créez une application sur LinkedIn Developers avec le produit « Community Management API », générez un jeton avec le droit w_organization_social et r_organization_social, et indiquez l\'identifiant numérique de votre page entreprise.'),
              style: TextStyle(fontSize: 12.5, color: Theme.of(c).hintColor),
            ),
            const SizedBox(height: 12),
            TextField(controller: id, decoration: InputDecoration(labelText: trn(meta ? 'Identifiant de la page Facebook' : 'Identifiant de la page LinkedIn'))),
            const SizedBox(height: 10),
            TextField(controller: token, decoration: InputDecoration(labelText: tr('Jeton d\'accès')), obscureText: true),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Connecter'))),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = await context.read<Session>().api.post('contenus/social/connect/$platform', {'token': token.text.trim(), 'account_id': id.text.trim()});
      if (mounted) toast(context, tr('Connecté : ${[for (final a in ((r as Map)['data'] as List)) a['name']].join(', ')}.'));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    if (rows == null) return const Center(child: CircularProgressIndicator());
    final api = context.read<Session>().api;
    const icons = {'facebook': Icons.facebook, 'instagram': Icons.camera_alt_outlined, 'linkedin': Icons.business_center_outlined};
    const colors = {'facebook': Color(0xFF1877F2), 'instagram': Color(0xFFE1306C), 'linkedin': Color(0xFF0A66C2)};
    return ListView(padding: const EdgeInsets.all(24), children: [
      Text(tr('Les publications validées avec « Publier automatiquement » partent à l\'heure prévue sur les réseaux cochés ; la portée et les interactions sont relevées toutes les 3 heures.'),
          style: TextStyle(color: Theme.of(context).hintColor)),
      const SizedBox(height: 12),
      Wrap(spacing: 8, runSpacing: 8, children: [
        FilledButton.icon(onPressed: () => _connect('meta'), icon: const Icon(Icons.facebook, size: 18), label: Text(tr('Connecter Facebook & Instagram'))),
        OutlinedButton.icon(onPressed: () => _connect('linkedin'), icon: const Icon(Icons.business_center_outlined, size: 18), label: Text(tr('Connecter LinkedIn'))),
        OutlinedButton.icon(
          onPressed: () async {
            final r = await api.post('contenus/social/stats');
            if (context.mounted) toast(context, tr('Statistiques mises à jour pour ${(r as Map)['data']['updated']} publication(s).'));
          },
          icon: const Icon(Icons.insights, size: 18),
          label: Text(tr('Actualiser les statistiques')),
        ),
      ]),
      const SizedBox(height: 16),
      if (rows.isEmpty)
        EmptyState(icon: Icons.share_outlined, title: tr('Aucun réseau connecté'), message: tr('Connectez votre page Facebook, votre compte Instagram professionnel et votre page LinkedIn.'))
      else
        for (final a in rows)
          Card(
            child: ListTile(
              leading: CircleAvatar(backgroundColor: (colors[a['platform']] ?? YColors.blue).withValues(alpha: .12), child: Icon(icons[a['platform']], color: colors[a['platform']])),
              title: Text(tr('${a['platform_label']} · ${a['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text(tr(a['last_error'] != null ? '${a['last_error']}' : 'Connecté · identifiant ${a['external_id']}')),
              trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                StatusChip(a['status'] == 'error' ? 'Erreur' : 'Actif', color: a['status'] == 'error' ? YColors.danger : YColors.success),
                IconButton(
                  tooltip: tr('Déconnecter'),
                  icon: const Icon(Icons.link_off),
                  onPressed: () async {
                    if (!await confirm(context, tr('Déconnecter ${a['platform_label']} ?'), tr('Les publications ne partiront plus sur ce compte.'), danger: true, ok: tr('Déconnecter'))) return;
                    await api.delete('contenus/social/accounts/${a['id']}');
                    _load();
                  },
                ),
              ]),
            ),
          ),
    ]);
  }
}
