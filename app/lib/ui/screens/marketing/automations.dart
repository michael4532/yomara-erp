import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/api_client.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'email_builder.dart';
import 'mk_common.dart';
import 'templates.dart';
import '../../../i18n/i18n.dart';

const stepIcons = {
  'email': Icons.mail_outline,
  'sms': Icons.sms_outlined,
  'whatsapp': Icons.chat_outlined,
  'wait': Icons.hourglass_bottom,
  'condition': Icons.call_split,
  'add_list': Icons.playlist_add,
  'remove_list': Icons.playlist_remove,
  'add_tag': Icons.sell_outlined,
  'remove_tag': Icons.label_off_outlined,
  'notify': Icons.notifications_active_outlined,
  'crm_lead': Icons.handshake_outlined,
  'exit': Icons.flag_outlined,
};

const stepColors = {
  'email': YColors.blue,
  'sms': Color(0xFF0E7C86),
  'whatsapp': Color(0xFF128C7E),
  'wait': YColors.warning,
  'condition': Color(0xFF7B3FE4),
  'exit': YColors.muted,
};

const triggerIcons = {'list_join': Icons.playlist_add_check, 'form': Icons.dynamic_form_outlined, 'tag': Icons.sell_outlined, 'birthday': Icons.cake_outlined, 'manual': Icons.touch_app_outlined,
  'lead_ads': Icons.ads_click, 'purchase': Icons.shopping_bag_outlined, 'inactive_customer': Icons.hourglass_bottom};
const _leadPlatforms = {'': 'Toutes les origines', 'meta': 'Publicités Facebook / Instagram', 'google': 'Formulaires Google Ads', 'tiktok': 'TikTok', 'linkedin': 'LinkedIn', 'landing': 'Pages de capture', 'website': 'Site externe / Zapier'};

const runStatus = {'active': 'En cours', 'done': 'Terminé', 'exited': 'Sorti', 'failed': 'Erreur'};

// ======================================================================
// Liste des automatisations
// ======================================================================

class AutomationsTab extends StatefulWidget {
  const AutomationsTab({super.key, required this.overview, required this.canManage});

  final Map<String, dynamic> overview;
  final bool canManage;

  @override
  State<AutomationsTab> createState() => _AutomationsTabState();
}

class _AutomationsTabState extends State<AutomationsTab> {
  Map<String, dynamic>? _d;

  ApiClient get _api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = mkMap(await _api.get('marketing/automations'));
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _create() async {
    final d = _d!;
    final recipes = mkList(d['recipes']);
    final triggers = mkMap(d['triggers']);
    final choice = await showFormDialog<Map<String, dynamic>>(
      context,
      title: tr('Nouvelle automatisation'),
      builder: (c) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Text(tr('Scénarios prêts à l\'emploi'), style: Theme.of(c).textTheme.titleSmall),
        const SizedBox(height: 8),
        for (final r in recipes)
          Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: ListTile(
              leading: CircleAvatar(backgroundColor: YColors.blue.withValues(alpha: .1), child: Icon(triggerIcons[r['trigger']], color: YColors.blue)),
              title: Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr('${r['description']}')),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.pop(c, {'recipe': r['key'], 'name': r['name'], 'trigger': r['trigger']}),
            ),
          ),
        const SizedBox(height: 12),
        Text(tr('Partir de zéro'), style: Theme.of(c).textTheme.titleSmall),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final t in triggers.entries)
            ActionChip(avatar: Icon(triggerIcons[t.key], size: 18), label: Text(tr('${t.value}')), onPressed: () => Navigator.pop(c, {'name': 'Nouveau scénario', 'trigger': t.key})),
        ]),
      ]),
    );
    if (choice == null || !mounted) return;
    try {
      final a = mkMap(await _api.post('marketing/automations', choice));
      if (!mounted) return;
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => AutomationScreen(id: a['id'] as int, meta: d, overview: widget.overview)));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) return const Center(child: CircularProgressIndicator());
    final list = mkList(d['data']);
    final hint = Theme.of(context).hintColor;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
        Row(children: [
          Expanded(
            child: Text(tr('Des scénarios qui travaillent pour vous : bienvenue, anniversaires, relances, passage au CRM… déclenchés par une inscription, une liste, une étiquette ou une date.'),
                style: TextStyle(color: hint)),
          ),
          if (widget.canManage) FilledButton.icon(onPressed: _create, icon: const Icon(Icons.add), label: Text(tr('Automatisation'))),
        ]),
        const SizedBox(height: 12),
        if (list.isEmpty)
          EmptyState(
            icon: Icons.account_tree_outlined,
            title: tr('Aucune automatisation'),
            message: tr('Commencez par la série de bienvenue ou les vœux d\'anniversaire : ils tournent ensuite tout seuls.'),
            action: widget.canManage ? FilledButton.icon(onPressed: _create, icon: const Icon(Icons.add), label: Text(tr('Créer'))) : null,
          )
        else
          Card(
            child: Column(children: [
              for (final a in list) ...[
                ListTile(
                  leading: CircleAvatar(backgroundColor: YColors.blue.withValues(alpha: .1), child: Icon(triggerIcons[a['trigger']], color: YColors.blue)),
                  title: Text(tr('${a['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text(tr('${a['trigger_label']} · ${_countSteps(mkList(a['steps']))} étape(s)')),
                  trailing: Wrap(spacing: 14, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    if (MediaQuery.of(context).size.width > 800) ...[
                      _MiniStat('Entrés', '${mkMap(a['stats'])['entered'] ?? 0}'),
                      _MiniStat('En cours', '${mkMap(a['stats'])['active'] ?? 0}'),
                      _MiniStat('Envoyés', '${mkMap(a['stats'])['sent'] ?? 0}'),
                    ],
                    StatusChip({'active': 'Actif', 'paused': 'En pause', 'draft': 'Brouillon'}[a['status']] ?? '${a['status']}', color: mkStatusColors[a['status']] ?? YColors.muted),
                  ]),
                  onTap: () async {
                    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => AutomationScreen(id: a['id'] as int, meta: d, overview: widget.overview)));
                    _load();
                  },
                ),
                const Divider(height: 1),
              ],
            ]),
          ),
      ]),
    );
  }
}

int _countSteps(List<Map<String, dynamic>> steps) => steps.fold(0, (n, s) => n + 1 + _countSteps(mkList(s['yes'])) + _countSteps(mkList(s['no'])));

class _MiniStat extends StatelessWidget {
  const _MiniStat(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(tr(value), style: const TextStyle(fontWeight: FontWeight.w700)),
        Text(tr(label), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
      ]);
}

// ======================================================================
// Éditeur de scénario
// ======================================================================

class AutomationScreen extends StatefulWidget {
  const AutomationScreen({super.key, required this.id, required this.meta, required this.overview});

  final int id;
  final Map<String, dynamic> meta;
  final Map<String, dynamic> overview;

  @override
  State<AutomationScreen> createState() => _AutomationScreenState();
}

class _AutomationScreenState extends State<AutomationScreen> {
  Map<String, dynamic>? _a;
  List<Map<String, dynamic>> _steps = [];
  Map<String, dynamic> _trigger = {};
  String _triggerType = 'list_join';
  bool _reentry = false;
  String? _selected; // chemin de l'étape (« 2.yes.0 ») ou « trigger »
  bool _dirty = false;
  bool _busy = false;
  List<Map<String, dynamic>> _forms = [];
  List<Map<String, dynamic>> _templates = [];
  late final _name = TextEditingController();
  int _rev = 0;

  ApiClient get _api => context.read<Session>().api;
  Map<String, dynamic> get _stepLabels => mkMap(widget.meta['steps']);
  Map<String, dynamic> get _conditionLabels => mkMap(widget.meta['conditions']);
  List<Map<String, dynamic>> get _lists => mkList(widget.overview['lists']);

  @override
  void initState() {
    super.initState();
    _templates = mkList(widget.overview['templates']);
    _load();
    _api.get('marketing/forms').then((r) {
      if (mounted) setState(() => _forms = mkList(mkMap(r)['data']));
    }).catchError((_) {});
  }

  Future<void> _load() async {
    try {
      final a = mkMap(await _api.get('marketing/automations/${widget.id}'));
      if (!mounted) return;
      setState(() {
        _a = a;
        _steps = mkList(jsonDecode(jsonEncode(a['steps'] ?? [])));
        _trigger = mkMap(a['trigger_config']);
        _triggerType = '${a['trigger']}';
        _reentry = a['allow_reentry'] == true;
        _name.text = '${a['name']}';
        _dirty = false;
        _rev++;
      });
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _reloadTemplates() async {
    try {
      final o = mkMap(await _api.get('marketing/overview'));
      if (mounted) setState(() => _templates = mkList(o['templates']));
    } catch (_) {}
  }

  Future<bool> _save({bool quiet = false}) async {
    setState(() => _busy = true);
    try {
      final a = mkMap(await _api.put('marketing/automations/${widget.id}', {
        'name': _name.text.trim().isEmpty ? _a!['name'] : _name.text.trim(),
        'trigger': _triggerType,
        'trigger_config': _trigger,
        'steps': _steps,
        'allow_reentry': _reentry,
      }));
      if (mounted) {
        setState(() {
          _a = a;
          _steps = mkList(jsonDecode(jsonEncode(a['steps'] ?? [])));
          _dirty = false;
        });
        if (!quiet) toast(context, tr('Scénario enregistré.'));
      }
      return true;
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return false;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _status(String s) async {
    if (_dirty && !await _save(quiet: true)) return;
    try {
      final a = mkMap(await _api.post('marketing/automations/${widget.id}/status', {'status': s}));
      if (mounted) {
        setState(() => _a = a);
        toast(context, s == 'active' ? 'Scénario activé : il démarre dès le prochain déclenchement.' : 'Scénario mis en pause.');
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _enroll() async {
    var mode = 'list';
    int? list;
    int? segment;
    final segments = mkList(widget.overview['segments']);
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Faire entrer des contacts')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              SegmentedButton<String>(
                segments: [ButtonSegment(value: 'list', label: Text(tr('Une liste'))), ButtonSegment(value: 'segment', label: Text(tr('Un segment')))],
                selected: {mode},
                onSelectionChanged: (s) => set(() => mode = s.first),
              ),
              const SizedBox(height: 12),
              if (mode == 'list')
                DropdownButtonFormField<int>(
                  initialValue: list,
                  isExpanded: true,
                  hint: Text(tr('Choisir une liste')),
                  items: [for (final l in _lists) DropdownMenuItem(value: l['id'] as int, child: Text(tr('${l['name']} (${l['contacts_count'] ?? 0})')))],
                  onChanged: (v) => set(() => list = v),
                )
              else
                DropdownButtonFormField<int>(
                  initialValue: segment,
                  isExpanded: true,
                  hint: Text(tr('Choisir un segment')),
                  items: [for (final s in segments) DropdownMenuItem(value: s['id'] as int, child: Text(tr('${s['name']}')))],
                  onChanged: (v) => set(() => segment = v),
                ),
              const SizedBox(height: 8),
              Text(tr('Les contacts déjà passés par ce scénario n\'y entrent pas une seconde fois (sauf si la réentrée est autorisée).'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Lancer')))],
        ),
      ),
    );
    if (ok != true || (list == null && segment == null)) return;
    try {
      final r = mkMap(await _api.post('marketing/automations/${widget.id}/enroll', {'list_id': ?(mode == 'list' ? list : null), 'segment_id': ?(mode == 'segment' ? segment : null)}));
      if (mounted) {
        setState(() => _a = r);
        toast(context, tr('${r['enrolled']} contact(s) entré(s) dans le scénario.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  // ---------------------------------------------------------------- manipulation de l'arbre

  List<Map<String, dynamic>> _listAt(List<String> listPath) {
    var list = _steps;
    for (var i = 0; i < listPath.length; i += 2) {
      final step = list[int.parse(listPath[i])];
      final branch = listPath[i + 1];
      step[branch] = mkList(step[branch]);
      list = step[branch] as List<Map<String, dynamic>>;
    }
    return list;
  }

  Map<String, dynamic>? _stepAt(String? path) {
    if (path == null || path == 'trigger') return null;
    final parts = path.split('.');
    try {
      return _listAt(parts.sublist(0, parts.length - 1))[int.parse(parts.last)];
    } catch (_) {
      return null;
    }
  }

  void _change(VoidCallback f) => setState(() {
        f();
        _dirty = true;
      });

  void _insert(List<String> listPath, int index, String type) {
    _change(() {
      final step = <String, dynamic>{
        'type': type,
        'config': switch (type) {
          'wait' => {'amount': 1, 'unit': 'days'},
          'condition' => {'check': 'opened'},
          'notify' => {'message': '{{nom_complet}} est arrivé à cette étape.'},
          'crm_lead' => {'title': 'Opportunité — {{nom_complet}}'},
          'sms' => {'text': 'Bonjour {{prenom}}, '},
          'whatsapp' => {'text': 'Bonjour {{prenom}} 👋\n'},
          _ => <String, dynamic>{},
        },
        if (type == 'condition') 'yes': <Map<String, dynamic>>[],
        if (type == 'condition') 'no': <Map<String, dynamic>>[],
      };
      _listAt(listPath).insert(index, step);
      _selected = [...listPath, '$index'].join('.');
      _rev++;
    });
  }

  void _remove(String path) {
    final parts = path.split('.');
    _change(() {
      _listAt(parts.sublist(0, parts.length - 1)).removeAt(int.parse(parts.last));
      _selected = null;
      _rev++;
    });
  }

  void _moveStep(String path, int delta) {
    final parts = path.split('.');
    final list = _listAt(parts.sublist(0, parts.length - 1));
    final i = int.parse(parts.last);
    final j = i + delta;
    if (j < 0 || j >= list.length) return;
    _change(() {
      final s = list.removeAt(i);
      list.insert(j, s);
      _selected = [...parts.sublist(0, parts.length - 1), '$j'].join('.');
      _rev++;
    });
  }

  Future<void> _addMenu(List<String> listPath, int index, Offset pos) async {
    final types = _stepLabels.keys.toList();
    final t = await showMenu<String>(
      context: context,
      position: RelativeRect.fromLTRB(pos.dx, pos.dy, pos.dx, pos.dy),
      items: [
        for (final k in types)
          PopupMenuItem(value: k, child: Row(children: [Icon(stepIcons[k], size: 18, color: stepColors[k] ?? YColors.night), const SizedBox(width: 10), Text(tr('${_stepLabels[k]}'))])),
      ],
    );
    if (t != null) _insert(listPath, index, t);
  }

  // ---------------------------------------------------------------- rendu

  @override
  Widget build(BuildContext context) {
    final a = _a;
    if (a == null) return Scaffold(appBar: AppBar(), body: const Center(child: CircularProgressIndicator()));
    final canManage = context.watch<Session>().can('marketing.manage');
    final status = '${a['status']}';
    final wide = MediaQuery.of(context).size.width > 1000;
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(tr('${a['name']}')),
          bottom: TabBar(tabs: [Tab(text: tr('Scénario')), Tab(text: tr('Contacts'))]),
          actions: [
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: Center(child: StatusChip({'active': 'Actif', 'paused': 'En pause', 'draft': 'Brouillon'}[status] ?? status, color: mkStatusColors[status] ?? YColors.muted)),
            ),
            if (canManage) ...[
              if (status == 'active') IconButton(tooltip: tr('Faire entrer des contacts'), onPressed: _enroll, icon: const Icon(Icons.group_add_outlined)),
              IconButton(tooltip: tr('Enregistrer'), onPressed: _busy || !_dirty ? null : () => _save(), icon: const Icon(Icons.save_outlined)),
              PopupMenuButton<String>(
                onSelected: (v) async {
                  if (v == 'delete') {
                    if (!await confirm(context, tr('Supprimer ce scénario ?'), tr('Les contacts en cours en sortent.'), danger: true, ok: tr('Supprimer'))) return;
                    await _api.delete('marketing/automations/${widget.id}');
                    if (context.mounted) Navigator.pop(context);
                  }
                },
                itemBuilder: (_) => [PopupMenuItem(value: 'delete', child: Text(tr('Supprimer')))],
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: status == 'active'
                    ? OutlinedButton.icon(onPressed: () => _status('paused'), icon: const Icon(Icons.pause), label: Text(tr('Pause')))
                    : FilledButton.icon(onPressed: () => _status('active'), icon: const Icon(Icons.play_arrow), label: Text(tr('Activer'))),
              ),
            ],
          ],
        ),
        body: TabBarView(children: [
          wide
              ? Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Expanded(child: _flow(canManage)),
                  const VerticalDivider(width: 1),
                  SizedBox(width: 380, child: _panel(canManage)),
                ])
              : _flow(canManage, narrow: true),
          _runs(),
        ]),
      ),
    );
  }

  Widget _flow(bool canManage, {bool narrow = false}) {
    final stats = mkMap(_a!['stats']);
    return Container(
      color: const Color(0xFFF4F6FB),
      child: ListView(padding: const EdgeInsets.all(24), children: [
        Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center, children: [
          MkKpi('Entrés', '${stats['entered'] ?? 0}', Icons.login, width: 150),
          MkKpi('En cours', '${stats['active'] ?? 0}', Icons.timelapse, width: 150),
          MkKpi('Terminés', '${stats['done'] ?? 0}', Icons.flag_outlined, width: 150),
          MkKpi('Messages', '${stats['sent'] ?? 0}', Icons.send_outlined, width: 150),
          MkKpi('Ouverture', stats['open_rate'] == null ? '—' : '${stats['open_rate']} %', Icons.drafts_outlined, width: 150),
        ]),
        const SizedBox(height: 20),
        Center(
          child: _Node(
            color: YColors.night,
            icon: triggerIcons[_triggerType] ?? Icons.bolt,
            title: tr('Déclencheur : ${mkMap(widget.meta['triggers'])[_triggerType] ?? _triggerType}'),
            subtitle: _triggerSummary(),
            selected: _selected == 'trigger',
            onTap: () => _select('trigger', narrow),
          ),
        ),
        _StepList(state: this, steps: _steps, listPath: const [], canManage: canManage, narrow: narrow),
        const _Connector(),
        Center(child: Chip(avatar: Icon(Icons.flag, size: 16), label: Text(tr('Fin du scénario')))),
      ]),
    );
  }

  void _select(String path, bool narrow) {
    setState(() => _selected = path);
    if (narrow) {
      showAdaptiveSheet(context: context, isScrollControlled: true, showDragHandle: true, builder: (_) => SizedBox(height: MediaQuery.of(context).size.height * .7, child: StatefulBuilder(builder: (c, _) => _panel(true))))
          .then((_) {
          if (mounted) setState(() {});
        });
    }
  }

  String _triggerSummary() {
    final c = _trigger;
    return switch (_triggerType) {
      'list_join' => 'Liste : ${_lists.where((l) => l['id'] == c['list_id']).map((l) => l['name']).firstOrNull ?? 'à choisir'}',
      'form' => c['form_id'] == null ? 'N\'importe quel formulaire' : 'Formulaire : ${_forms.where((f) => f['id'] == c['form_id']).map((f) => f['name']).firstOrNull ?? '…'}',
      'tag' => 'Étiquette « ${c['tag'] ?? '…'} »',
      'birthday' => (c['days_before'] ?? 0) == 0 ? 'Le jour de l\'anniversaire, à 8 h' : '${c['days_before']} jour(s) avant, à 8 h',
      'lead_ads' => 'Prospect reçu : ${_leadPlatforms['${c['platform'] ?? ''}'] ?? 'toutes origines'}',
      'purchase' => 'Achat${(c['min_amount'] ?? 0) != 0 ? ' d\'au moins ${c['min_amount']}' : ''}${c['first_only'] == true ? ' (premier achat)' : ''}',
      'inactive_customer' => 'Client sans achat depuis ${c['days'] ?? 90} jours',
      _ => 'Contacts ajoutés à la main (liste ou segment)',
    };
  }

  String stepSummary(Map<String, dynamic> s) {
    final c = mkMap(s['config']);
    switch (s['type']) {
      case 'email':
        final t = _templates.where((t) => t['id'] == c['template_id']).firstOrNull;
        return t == null ? 'Modèle à choisir' : '« ${t['name']} »${'${c['subject'] ?? ''}'.isNotEmpty ? ' · objet : ${c['subject']}' : ''}';
      case 'sms':
      case 'whatsapp':
        final t = '${c['text'] ?? ''}';
        return t.isEmpty ? 'Message à écrire' : (t.length > 60 ? '${t.substring(0, 60)}…' : t);
      case 'wait':
        return '${c['amount'] ?? 1} ${{'minutes': 'minute(s)', 'hours': 'heure(s)', 'days': 'jour(s)', 'weeks': 'semaine(s)'}[c['unit'] ?? 'days']}';
      case 'condition':
        final v = c['value'];
        final label = _conditionLabels[c['check']] ?? '…';
        final val = c['check'] == 'in_list' ? (_lists.where((l) => l['id'] == v).map((l) => l['name']).firstOrNull ?? '') : (v ?? '');
        return 'Si le contact $label $val'.trim();
      case 'add_list':
      case 'remove_list':
        return _lists.where((l) => l['id'] == c['list_id']).map((l) => '${l['name']}').firstOrNull ?? 'Liste à choisir';
      case 'add_tag':
      case 'remove_tag':
        return '« ${c['tag'] ?? '…'} »';
      case 'notify':
        return '${c['message'] ?? ''}';
      case 'crm_lead':
        return '${c['title'] ?? ''}';
    }
    return '';
  }

  // ---------------------------------------------------------------- panneau de réglages

  Widget _panel(bool canManage) {
    final hint = Theme.of(context).hintColor;
    if (_selected == 'trigger' || _selected == null) {
      return ListView(padding: const EdgeInsets.all(16), children: [
        Field(label: tr('Nom du scénario'), child: TextField(controller: _name, onChanged: (_) => setState(() => _dirty = true))),
        Field(
          label: tr('Déclencheur'),
          child: DropdownButtonFormField<String>(
            initialValue: _triggerType,
            isExpanded: true,
            items: [for (final t in mkMap(widget.meta['triggers']).entries) DropdownMenuItem(value: t.key, child: Text(tr('${t.value}')))],
            onChanged: (v) => _change(() {
              _triggerType = v ?? _triggerType;
              _trigger = {};
            }),
          ),
        ),
        if (_triggerType == 'list_join')
          Field(
            label: tr('Liste'),
            child: DropdownButtonFormField<int>(
              key: ValueKey('tl-$_rev-$_triggerType'),
              initialValue: _lists.any((l) => l['id'] == _trigger['list_id']) ? _trigger['list_id'] as int : null,
              isExpanded: true,
              hint: Text(tr('Choisir')),
              items: [for (final l in _lists) DropdownMenuItem(value: l['id'] as int, child: Text(tr('${l['name']}')))],
              onChanged: (v) => _change(() => _trigger = {..._trigger, 'list_id': v}),
            ),
          ),
        if (_triggerType == 'form')
          Field(
            label: tr('Formulaire'),
            child: DropdownButtonFormField<int?>(
              key: ValueKey('tf-$_rev-${_forms.length}'),
              initialValue: _forms.any((f) => f['id'] == _trigger['form_id']) ? _trigger['form_id'] as int : null,
              isExpanded: true,
              items: [
                DropdownMenuItem<int?>(value: null, child: Text(tr('N\'importe quel formulaire'))),
                for (final f in _forms) DropdownMenuItem<int?>(value: f['id'] as int, child: Text(tr('${f['name']}'))),
              ],
              onChanged: (v) => _change(() => _trigger = {..._trigger, 'form_id': v}),
            ),
          ),
        if (_triggerType == 'tag')
          Field(
            label: tr('Étiquette'),
            child: TextFormField(key: ValueKey('tt-$_rev'), initialValue: '${_trigger['tag'] ?? ''}', onChanged: (v) => _change(() => _trigger = {..._trigger, 'tag': v.trim()})),
          ),
        if (_triggerType == 'birthday')
          Field(
            label: tr('Envoyer'),
            child: DropdownButtonFormField<int>(
              initialValue: ((_trigger['days_before'] as num?) ?? 0).toInt(),
              items: [
                DropdownMenuItem(value: 0, child: Text(tr('le jour même'))),
                DropdownMenuItem(value: 1, child: Text(tr('la veille'))),
                DropdownMenuItem(value: 3, child: Text(tr('3 jours avant'))),
                DropdownMenuItem(value: 7, child: Text(tr('une semaine avant'))),
              ],
              onChanged: (v) => _change(() => _trigger = {..._trigger, 'days_before': v}),
            ),
          ),
        if (_triggerType == 'lead_ads')
          Field(
            label: tr('Origine du prospect'),
            child: DropdownButtonFormField<String>(
              initialValue: _leadPlatforms.containsKey('${_trigger['platform'] ?? ''}') ? '${_trigger['platform'] ?? ''}' : '',
              items: [for (final e in _leadPlatforms.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
              onChanged: (v) => _change(() => _trigger = {..._trigger, 'platform': (v ?? '').isEmpty ? null : v}),
            ),
          ),
        if (_triggerType == 'purchase') ...[
          Field(
            label: tr('Montant minimum de l\'achat'),
            child: TextFormField(key: ValueKey('pm-$_rev'), initialValue: '${_trigger['min_amount'] ?? ''}', keyboardType: TextInputType.number,
                onChanged: (v) => _change(() => _trigger = {..._trigger, 'min_amount': num.tryParse(v.replaceAll(' ', '')) ?? 0})),
          ),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Seulement au premier achat (nouveau client)')), value: _trigger['first_only'] == true,
              onChanged: (v) => _change(() => _trigger = {..._trigger, 'first_only': v})),
        ],
        if (_triggerType == 'inactive_customer')
          Field(
            label: tr('Sans achat depuis'),
            child: DropdownButtonFormField<int>(
              initialValue: const [30, 60, 90, 180, 365].contains((_trigger['days'] as num?)?.toInt()) ? (_trigger['days'] as num).toInt() : 90,
              items: [for (final d in const [30, 60, 90, 180, 365]) DropdownMenuItem(value: d, child: Text(tr('$d jours')))],
              onChanged: (v) => _change(() => _trigger = {..._trigger, 'days': v}),
            ),
          ),
        if (_triggerType == 'manual')
          Text(tr('Utilisez le bouton « Faire entrer des contacts » une fois le scénario activé (une liste ou un segment, par exemple vos inactifs).'), style: TextStyle(color: hint)),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr('Autoriser plusieurs passages')),
          subtitle: Text(tr('Un contact peut refaire le scénario après l\'avoir terminé')),
          value: _reentry,
          onChanged: (v) => _change(() => _reentry = v),
        ),
        const Divider(),
        Text(tr('Cliquez sur une étape pour la régler, ou sur « + » pour en ajouter.'), style: TextStyle(color: hint, fontSize: 12)),
      ]);
    }
    final s = _stepAt(_selected);
    if (s == null) return const SizedBox.shrink();
    final type = '${s['type']}';
    final c = mkMap(s['config']);
    void setCfg(String k, dynamic v) => _change(() => s['config'] = {...mkMap(s['config']), k: v});
    final perStep = mkMap(mkMap(_a!['per_step'])[s['id']]);
    final key = '${s['id'] ?? _selected}-$_rev';
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        Icon(stepIcons[type], color: stepColors[type] ?? YColors.night),
        const SizedBox(width: 8),
        Expanded(child: Text(tr('${_stepLabels[type] ?? type}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
        IconButton(tooltip: tr('Monter'), onPressed: () => _moveStep(_selected!, -1), icon: const Icon(Icons.arrow_upward, size: 18)),
        IconButton(tooltip: tr('Descendre'), onPressed: () => _moveStep(_selected!, 1), icon: const Icon(Icons.arrow_downward, size: 18)),
        IconButton(tooltip: tr('Supprimer'), onPressed: () => _remove(_selected!), icon: const Icon(Icons.delete_outline, color: YColors.danger, size: 18)),
      ]),
      if (perStep.isNotEmpty)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Wrap(spacing: 8, children: [
            StatusChip(tr('${perStep['sent']} envoyé(s)'), color: YColors.success),
            if (type == 'email') StatusChip(tr('${perStep['opened']} ouvert(s)'), color: YColors.blue),
            StatusChip(tr('${perStep['clicked']} clic(s)'), color: YColors.warning),
          ]),
        ),
      const SizedBox(height: 8),
      ...switch (type) {
        'email' => [
            Field(
              label: tr('Modèle d\'e-mail'),
              child: DropdownButtonFormField<int>(
                key: ValueKey('tpl-$key-${_templates.length}'),
                initialValue: _templates.any((t) => t['id'] == c['template_id']) ? c['template_id'] as int : null,
                isExpanded: true,
                hint: Text(tr('Choisir un modèle')),
                items: [for (final t in _templates.where((t) => t['channel'] == 'email')) DropdownMenuItem(value: t['id'] as int, child: Text(tr('${t['name']}')))],
                onChanged: (v) => setCfg('template_id', v),
              ),
            ),
            if (c['template_id'] != null)
              SizedBox(
                height: 260,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: LayoutThumbnail(content: _templates.firstWhere((t) => t['id'] == c['template_id'], orElse: () => <String, dynamic>{})),
                ),
              ),
            Wrap(spacing: 8, children: [
              TextButton.icon(
                onPressed: () async {
                  final l = await pickLayout(context, title: tr('Créer un modèle à partir de…'));
                  if (l == null || !mounted) return;
                  try {
                    final t = mkMap(await _api.post('marketing/templates', {'name': '${l['name']} — ${_name.text}', 'channel': 'email', 'layout': l['key']}));
                    await _reloadTemplates();
                    setCfg('template_id', t['id']);
                    if (!mounted) return;
                    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TemplateScreen(id: t['id'] as int)));
                    _reloadTemplates();
                  } catch (e) {
                    if (mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.add),
                label: Text(tr('Nouveau modèle')),
              ),
              if (c['template_id'] != null)
                TextButton.icon(
                  onPressed: () async {
                    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TemplateScreen(id: c['template_id'] as int)));
                    _reloadTemplates();
                  },
                  icon: const Icon(Icons.edit_outlined),
                  label: Text(tr('Modifier le modèle')),
                ),
            ]),
            Field(
              label: tr('Objet (facultatif, remplace celui du modèle)'),
              child: TextFormField(key: ValueKey('subj-$key'), initialValue: '${c['subject'] ?? ''}', onChanged: (v) => setCfg('subject', v)),
            ),
          ],
        'sms' || 'whatsapp' => [
            Field(
              label: type == 'sms' ? 'Texte du SMS' : 'Message WhatsApp',
              child: TextFormField(key: ValueKey('txt-$key'), initialValue: '${c['text'] ?? ''}', maxLines: 6, minLines: 3, onChanged: (v) => setCfg('text', v)),
            ),
            Text(tr('Variables : {{prenom}}, {{nom_complet}}, {{societe}}, {{entreprise}}'), style: TextStyle(fontSize: 12, color: hint)),
          ],
        'wait' => [
            Row(children: [
              SizedBox(
                width: 90,
                child: TextFormField(
                  key: ValueKey('amt-$key'),
                  initialValue: '${c['amount'] ?? 1}',
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: tr('Durée')),
                  onChanged: (v) => setCfg('amount', int.tryParse(v) ?? 1),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: DropdownButtonFormField<String>(
                  initialValue: '${c['unit'] ?? 'days'}',
                  items: [
                    DropdownMenuItem(value: 'minutes', child: Text(tr('minute(s)'))),
                    DropdownMenuItem(value: 'hours', child: Text(tr('heure(s)'))),
                    DropdownMenuItem(value: 'days', child: Text(tr('jour(s)'))),
                    DropdownMenuItem(value: 'weeks', child: Text(tr('semaine(s)'))),
                  ],
                  onChanged: (v) => setCfg('unit', v),
                ),
              ),
            ]),
          ],
        'condition' => [
            Field(
              label: tr('Si le contact…'),
              child: DropdownButtonFormField<String>(
                key: ValueKey('chk-$key'),
                initialValue: _conditionLabels.containsKey(c['check']) ? '${c['check']}' : null,
                isExpanded: true,
                items: [for (final e in _conditionLabels.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                onChanged: (v) => _change(() => s['config'] = {'check': v}),
              ),
            ),
            if (c['check'] == 'has_tag')
              Field(label: tr('Étiquette'), child: TextFormField(key: ValueKey('cv-$key'), initialValue: '${c['value'] ?? ''}', onChanged: (v) => setCfg('value', v))),
            if (c['check'] == 'spent_min')
              Field(label: tr('Montant total'), child: TextFormField(key: ValueKey('sp-$key'), initialValue: '${c['value'] ?? ''}', keyboardType: TextInputType.number, onChanged: (v) => setCfg('value', num.tryParse(v.replaceAll(' ', ''))))),
            if (c['check'] == 'score_min')
              Field(label: tr('Score'), child: TextFormField(key: ValueKey('cs-$key'), initialValue: '${c['value'] ?? ''}', keyboardType: TextInputType.number, onChanged: (v) => setCfg('value', int.tryParse(v)))),
            if (c['check'] == 'in_list')
              Field(
                label: tr('Liste'),
                child: DropdownButtonFormField<int>(
                  initialValue: _lists.any((l) => l['id'] == c['value']) ? c['value'] as int : null,
                  isExpanded: true,
                  items: [for (final l in _lists) DropdownMenuItem(value: l['id'] as int, child: Text(tr('${l['name']}')))],
                  onChanged: (v) => setCfg('value', v),
                ),
              ),
            if (c['check'] == 'opened' || c['check'] == 'clicked')
              Text(tr('Porte sur le dernier e-mail envoyé par ce scénario. Placez une attente avant la condition pour laisser au contact le temps de l\'ouvrir.'), style: TextStyle(fontSize: 12, color: hint)),
          ],
        'add_list' || 'remove_list' => [
            Field(
              label: tr('Liste'),
              child: DropdownButtonFormField<int>(
                key: ValueKey('al-$key'),
                initialValue: _lists.any((l) => l['id'] == c['list_id']) ? c['list_id'] as int : null,
                isExpanded: true,
                items: [for (final l in _lists) DropdownMenuItem(value: l['id'] as int, child: Text(tr('${l['name']}')))],
                onChanged: (v) => setCfg('list_id', v),
              ),
            ),
          ],
        'add_tag' || 'remove_tag' => [Field(label: tr('Étiquette'), child: TextFormField(key: ValueKey('tg-$key'), initialValue: '${c['tag'] ?? ''}', onChanged: (v) => setCfg('tag', v)))],
        'notify' => [
            Field(label: tr('Message à l\'équipe'), child: TextFormField(key: ValueKey('nt-$key'), initialValue: '${c['message'] ?? ''}', maxLines: 3, onChanged: (v) => setCfg('message', v))),
            Text(tr('Envoyé aux utilisateurs qui gèrent le marketing (cloche de notifications).'), style: TextStyle(fontSize: 12, color: hint)),
          ],
        'crm_lead' => [
            Field(label: tr('Titre de l\'opportunité'), child: TextFormField(key: ValueKey('cl-$key'), initialValue: '${c['title'] ?? ''}', onChanged: (v) => setCfg('title', v))),
            Field(
              label: tr('Montant estimé'),
              child: TextFormField(key: ValueKey('ca-$key'), initialValue: '${c['amount'] ?? ''}', keyboardType: TextInputType.number, onChanged: (v) => setCfg('amount', num.tryParse(v))),
            ),
            Text(tr('Nécessite le module CRM ; ignoré si le contact a déjà une opportunité.'), style: TextStyle(fontSize: 12, color: hint)),
          ],
        _ => [Text(tr('Le contact sort du scénario à cette étape.'), style: TextStyle(color: hint))],
      },
    ]);
  }

  // ---------------------------------------------------------------- contacts dans le scénario

  Widget _runs() {
    final runs = mkList(_a!['runs']);
    if (runs.isEmpty) return EmptyState(icon: Icons.people_outline, title: tr('Aucun contact pour l\'instant'), message: tr('Les contacts apparaissent ici dès que le déclencheur se produit.'));
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: runs.length,
        separatorBuilder: (_, _) => const Divider(height: 1),
        itemBuilder: (_, i) {
          final r = runs[i];
          final ct = mkMap(r['contact']);
          final name = [ct['first_name'], ct['last_name']].whereType<String>().join(' ').trim();
          final current = _stepAt('${r['cursor']}');
          return ExpansionTile(
            leading: Icon(r['status'] == 'active' ? Icons.timelapse : (r['status'] == 'failed' ? Icons.error_outline : Icons.check_circle_outline),
                color: r['status'] == 'failed' ? YColors.danger : (r['status'] == 'active' ? YColors.blue : YColors.success)),
            title: Text(tr(name.isEmpty ? '${ct['email'] ?? ct['phone'] ?? 'Contact'}' : name)),
            subtitle: Text(tr([
              runStatus[r['status']] ?? '${r['status']}',
              if (r['status'] == 'active' && current != null) 'prochaine étape : ${_stepLabels[current['type']] ?? current['type']}',
              if (r['status'] == 'active' && r['next_run_at'] != null) 'le ${dateTimeFr(r['next_run_at'])}',
              'entré le ${dateTimeFr(r['created_at'])}',
            ].join(' · '))),
            children: [
              for (final h in mkList(r['history']))
                ListTile(
                  dense: true,
                  leading: Icon(stepIcons[h['step']] ?? Icons.circle, size: 18),
                  title: Text(tr('${_stepLabels[h['step']] ?? h['step']} — ${h['result']}')),
                  subtitle: Text(tr(dateTimeFr(h['at']))),
                ),
              if (r['error'] != null) ListTile(dense: true, title: Text(tr('${r['error']}'), style: const TextStyle(color: YColors.danger))),
            ],
          );
        },
      ),
    );
  }
}

/// Liste d'étapes (récursive pour les branches des conditions).
class _StepList extends StatelessWidget {
  const _StepList({required this.state, required this.steps, required this.listPath, required this.canManage, required this.narrow});

  final _AutomationScreenState state;
  final List<Map<String, dynamic>> steps;
  final List<String> listPath;
  final bool canManage;
  final bool narrow;

  @override
  Widget build(BuildContext context) {
    final perStep = mkMap(state._a!['per_step']);
    Widget plus(int index) => Column(children: [
          const _Connector(),
          if (canManage)
            Builder(
              builder: (b) => Center(
                child: InkWell(
                  customBorder: const CircleBorder(),
                  onTapDown: (d) => state._addMenu(listPath, index, d.globalPosition),
                  child: Container(
                    width: 30,
                    height: 30,
                    decoration: BoxDecoration(color: Colors.white, shape: BoxShape.circle, border: Border.all(color: YColors.blue)),
                    child: const Icon(Icons.add, size: 18, color: YColors.blue),
                  ),
                ),
              ),
            ),
        ]);
    return Column(children: [
      for (var i = 0; i < steps.length; i++) ...[
        plus(i),
        const _Connector(),
        Builder(builder: (context) {
          final s = steps[i];
          final path = [...listPath, '$i'].join('.');
          final type = '${s['type']}';
          final st = mkMap(perStep[s['id']]);
          final node = _Node(
            color: stepColors[type] ?? YColors.night,
            icon: stepIcons[type] ?? Icons.circle,
            title: '${state._stepLabels[type] ?? type}',
            subtitle: state.stepSummary(s),
            badge: st.isEmpty ? null : '${st['sent']} envoyé(s)${type == 'email' ? ' · ${st['opened']} ouvert(s)' : ''}',
            selected: state._selected == path,
            onTap: () => state._select(path, narrow),
          );
          if (type != 'condition') return Center(child: node);
          final branches = [
            for (final b in ['yes', 'no'])
              Column(children: [
                Container(
                  margin: const EdgeInsets.only(top: 8),
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(color: b == 'yes' ? YColors.success : YColors.danger, borderRadius: BorderRadius.circular(12)),
                  child: Text(tr(b == 'yes' ? 'OUI' : 'NON'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 12)),
                ),
                _StepList(state: state, steps: mkList(s[b]), listPath: [...listPath, '$i', b], canManage: canManage, narrow: narrow),
              ]),
          ];
          return Column(children: [
            Center(child: node),
            Container(
              margin: const EdgeInsets.only(top: 4),
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(border: Border.all(color: const Color(0xFF7B3FE4).withValues(alpha: .3)), borderRadius: BorderRadius.circular(12)),
              child: narrow
                  ? Column(children: branches)
                  : Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: branches[0]), const SizedBox(width: 8), Expanded(child: branches[1])]),
            ),
          ]);
        }),
      ],
      plus(steps.length),
    ]);
  }
}

class _Connector extends StatelessWidget {
  const _Connector();

  @override
  Widget build(BuildContext context) => Center(child: Container(width: 2, height: 16, color: YColors.line));
}

class _Node extends StatelessWidget {
  const _Node({required this.color, required this.icon, required this.title, required this.subtitle, required this.selected, required this.onTap, this.badge});

  final Color color;
  final IconData icon;
  final String title;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;
  final String? badge;

  @override
  Widget build(BuildContext context) => ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 340, minWidth: 220),
        child: Material(
          color: Theme.of(context).colorScheme.surface,
          elevation: selected ? 3 : 0,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: selected ? YColors.blue : YColors.line, width: selected ? 2 : 1)),
          child: InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
                  child: Icon(icon, color: color, size: 20),
                ),
                const SizedBox(width: 10),
                Flexible(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                    Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w700)),
                    if (subtitle.isNotEmpty) Text(tr(subtitle), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, color: YColors.muted)),
                    if (badge != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr(badge!), style: const TextStyle(fontSize: 11, color: YColors.success, fontWeight: FontWeight.w600))),
                  ]),
                ),
              ]),
            ),
          ),
        ),
      );
}
