import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'rh_screen.dart' show empName, myEmployee;
import '../widgets/pdf_viewer.dart';

// ===========================================================================
// Sorties de matériel : caméras, ordinateurs, outillage emportés en intervention puis rendus.
// Hors ligne ; bon de sortie PDF signé par le porteur et le responsable du département.
// ===========================================================================

const matItemStatuses = <String, (String, Color)>{
  'available': ('Disponible', YColors.success),
  'out': ('Sorti', YColors.blue),
  'repair': ('En panne / réparation', YColors.warning),
  'lost': ('Perdu', YColors.danger),
  'retired': ('Réformé', YColors.muted),
};
const matCheckoutStatuses = <String, (String, Color)>{
  'out': ('Sorti', YColors.blue),
  'partial': ('Retour partiel', YColors.warning),
  'returned': ('Rendu', YColors.success),
  'cancelled': ('Annulé', YColors.muted),
};
const matConditions = {'ok': 'Bon état', 'worn': 'Usé', 'damaged': 'Endommagé', 'lost': 'Perdu'};
const _signature = <String, (String, Color)>{
  'sent': ('Signature en attente', YColors.warning),
  'signed': ('Bon signé', YColors.success),
  'declined': ('Signature refusée', YColors.danger),
  'expired': ('Signature expirée', YColors.muted),
  'cancelled': ('Signature annulée', YColors.muted),
  'failed': ('Signature non envoyée', YColors.danger),
};

List<Map<String, dynamic>> _items() => LocalDb.hasEntity('mat_items') ? (LocalDb.all('mat_items')..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()))) : [];
List<Map<String, dynamic>> _checkouts() => LocalDb.hasEntity('mat_checkouts') ? (LocalDb.all('mat_checkouts')..sort((a, b) => '${b['out_at'] ?? b['created_at'] ?? ''}'.compareTo('${a['out_at'] ?? a['created_at'] ?? ''}'))) : [];

bool _open(Map c) => c['status'] == 'out' || c['status'] == 'partial';
bool _late(Map c) => _open(c) && c['expected_back_at'] != null && (DateTime.tryParse('${c['expected_back_at']}')?.isBefore(DateTime.now()) ?? false);

String _holder(Map c) {
  if (c['employee_id'] != null && LocalDb.hasEntity('rh_employees')) {
    final e = LocalDb.get('rh_employees', '${c['employee_id']}');
    if (e != null) return empName(e);
  }
  return '${c['holder_name'] ?? '—'}';
}

List<Map<String, dynamic>> _lines(Map c) => [for (final l in (c['lines'] as List? ?? const [])) asMap(l)];

String _dt(dynamic v) => v == null ? '—' : dateTimeFr('$v');

class MaterielScreen extends StatefulWidget {
  const MaterielScreen({super.key});

  @override
  State<MaterielScreen> createState() => _MaterielScreenState();
}

class _MaterielScreenState extends State<MaterielScreen> with SingleTickerProviderStateMixin {
  late final Session _s = context.read<Session>();
  late final bool _manage = _s.can('materiel.manage');
  late final TabController _tabs = TabController(length: 2, vsync: this);

  @override
  Widget build(BuildContext context) {
    if (!LocalDb.hasEntity('mat_checkouts')) {
      return Column(children: [
        PageHeader(title: tr('Sorties de matériel')),
        Expanded(child: EmptyState(icon: Icons.sync, title: tr('Synchronisation nécessaire'), message: tr('Synchronisez pour recevoir le matériel et les sorties.'))),
      ]);
    }
    return Column(children: [
      PageHeader(
        title: tr('Sorties de matériel'),
        subtitle: tr('Caméras, ordinateurs, outillage : qui l\'a emporté, pour quelle intervention, et quand il revient'),
        actions: [
          AnimatedBuilder(
            animation: _tabs,
            builder: (_, _) => _tabs.index == 1 && _manage
                ? FilledButton.icon(onPressed: () => editItem(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau matériel')))
                : FilledButton.icon(onPressed: () => newCheckout(context), icon: const Icon(Icons.logout), label: Text(tr('Nouvelle sortie'))),
          ),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Sorties')), Tab(text: tr('Matériel'))]),
      Expanded(child: TabBarView(controller: _tabs, children: [_CheckoutsTab(manage: _manage), _ItemsTab(manage: _manage)])),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Sorties
// ---------------------------------------------------------------------------

class _CheckoutsTab extends StatefulWidget {
  const _CheckoutsTab({required this.manage});

  final bool manage;

  @override
  State<_CheckoutsTab> createState() => _CheckoutsTabState();
}

class _CheckoutsTabState extends State<_CheckoutsTab> {
  String _filter = 'open';
  String _q = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  static bool _statusMatch(Map<String, dynamic> c, String f) => switch (f) {
        'open' => _open(c),
        'late' => _late(c),
        '' => true,
        _ => c['status'] == f,
      };

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return ListenableBuilder(
      listenable: Listenable.merge([LocalDb.listen('mat_checkouts'), LocalDb.listen('mat_items')]),
      builder: (context, _) {
        final all = _checkouts();
        final items = _items();
        final q = _q.toLowerCase();
        final base = all
            .where((c) => q.isEmpty || '${c['number'] ?? ''} ${_holder(c)} ${c['purpose'] ?? ''} ${c['destination'] ?? ''} ${_lines(c).map((l) => '${l['name']} ${l['code'] ?? ''}').join(' ')}'.toLowerCase().contains(q))
            .where((c) => _f['holder'] == null || _holder(c) == _f['holder'])
            .where((c) => _f['item'] == null || _lines(c).any((l) => '${l['item_id']}' == _f['item']))
            .where((c) => _f['department'] == null || '${c['department']}' == _f['department'])
            .where((c) => _f['signature'] == null || '${c['signature_status'] ?? 'none'}' == _f['signature'])
            .where((c) => _period == null || _period!.contains(c['out_at'] ?? c['created_at']))
            .toList();
        final shown = base.where((c) => _statusMatch(c, _filter)).toList();
        const statuses = {'': 'Toutes', 'open': 'En cours', 'late': 'En retard', 'partial': 'Retour partiel', 'returned': 'Rendues', 'cancelled': 'Annulées'};
        final holders = <String, String>{for (final c in all) _holder(c): _holder(c)}..remove('—');
        final itemOpts = <String, String>{for (final c in all) for (final l in _lines(c)) if (l['item_id'] != null) '${l['item_id']}': '${l['name']}'};
        final outItems = items.where((i) => i['status'] == 'out').length;
        return ListView(padding: const EdgeInsets.only(top: 16, bottom: 32), children: [
          StatStrip(tiles: [
            StatTile(label: 'Matériel sorti', value: '$outItems', detail: '${all.where(_open).length} sortie(s) en cours', icon: Icons.logout, color: YColors.blue),
            StatTile(label: 'En retard', value: '${all.where(_late).length}', detail: 'retour prévu dépassé', icon: Icons.alarm, color: all.any(_late) ? YColors.danger : YColors.success),
            StatTile(label: 'Disponible', value: '${items.where((i) => i['status'] == 'available').length}', detail: 'sur ${items.length} article(s)', icon: Icons.inventory_2_outlined, color: YColors.success),
            StatTile(label: 'En panne ou perdu', value: '${items.where((i) => i['status'] == 'repair' || i['status'] == 'lost').length}', icon: Icons.build_circle_outlined, color: YColors.warning),
          ]),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: pad),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              FilterBar(
                key: ValueKey(_gen),
                padding: const EdgeInsets.only(bottom: 12),
                onSearch: (v) => setState(() => _q = v),
                search: _q,
                searchHint: 'N°, porteur, matériel, motif…',
                statuses: statuses,
                status: _filter,
                onStatus: (v) => setState(() => _filter = v),
                statusCounts: {for (final k in statuses.keys) k: base.where((c) => _statusMatch(c, k)).length},
                filters: [
                  FilterSpec('holder', 'Porteur', Map.fromEntries(holders.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()))), icon: Icons.person_outline),
                  FilterSpec('item', 'Matériel', Map.fromEntries(itemOpts.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()))), icon: Icons.videocam_outlined),
                  FilterSpec('department', 'Département', filterOptions(all, 'department'), icon: Icons.apartment_outlined),
                  FilterSpec('signature', 'Signature', {'none': 'Non envoyée', for (final e in _signature.entries) e.key: e.value.$1}, icon: Icons.draw_outlined),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f[k] = v),
                showPeriod: true,
                period: _period,
                onPeriod: (p) => setState(() => _period = p),
                count: shown.length,
                countLabel: 'sortie',
                onReset: () => setState(() {
                  _filter = 'open';
                  _q = '';
                  _f.clear();
                  _period = null;
                  _gen++;
                }),
                trailing: [
                  if (widget.manage) OutlinedButton.icon(onPressed: () => _settings(context), icon: const Icon(Icons.tune, size: 18), label: Text(tr('Signataires'))),
                ],
              ),
              if (shown.isEmpty)
                EmptyState(
                  icon: Icons.videocam_outlined,
                  title: tr(_filter == 'open' ? 'Aucun matériel dehors' : 'Aucune sortie'),
                  message: tr(items.isEmpty ? 'Commencez par enregistrer votre matériel (onglet Matériel), puis chaque sortie en intervention.' : 'Chaque sortie produit un bon PDF à faire signer par le porteur et le responsable du département.'),
                )
              else
                Card(
                  clipBehavior: Clip.antiAlias,
                  child: Column(children: [for (final c in shown) _CheckoutTile(c)]),
                ),
            ]),
          ),
        ]);
      },
    );
  }

  Future<void> _settings(BuildContext context) async {
    final api = context.read<Session>().api;
    Map<String, dynamic> st;
    try {
      st = asMap(await api.get('materiel/settings'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!context.mounted) return;
    final staff = LocalDb.hasEntity('rh_employees') ? LocalDb.all('rh_employees').where((e) => e['active'] != false).toList() : <Map<String, dynamic>>[];
    staff.sort((a, b) => empName(a).compareTo(empName(b)));
    final depts = {for (final e in staff) '${e['department'] ?? ''}'.trim()}.where((d) => d.isNotEmpty).toList()..sort();
    final map = <String, Set<String>>{for (final e in asMap(st['managers']).entries) e.key: {for (final x in (e.value as List? ?? const [])) '$x'}};
    await showFormDialog(context, title: 'Signataires des bons de sortie', builder: (dc) {
      return StatefulBuilder(builder: (dc, setS) {
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
          Text(
            tr('Chaque bon est signé par le porteur et par le(s) responsable(s) de son département. Sans réglage, c\'est le responsable direct du porteur (fiche salarié ou poste supérieur).'),
            style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12.5),
          ),
          if (st['signature'] != true) ...[
            const SizedBox(height: 8),
            Text(tr('Le module Signature électronique n\'est pas actif : les bons s\'impriment et se signent sur papier.'), style: const TextStyle(color: YColors.warning, fontSize: 12.5)),
          ],
          const SizedBox(height: 12),
          if (depts.isEmpty) Text(tr('Renseignez le service des salariés (fiche salarié) pour choisir les responsables par département.'), style: TextStyle(color: Theme.of(dc).hintColor)),
          for (final d in depts)
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr(d), style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final e in staff.where((e) => '${e['department'] ?? ''}'.trim() == d || (map[d]?.contains(e['id']) ?? false)))
                    FilterChip(
                      label: Text(tr(empName(e))),
                      selected: map[d]?.contains(e['id']) ?? false,
                      onSelected: (v) => setS(() => v ? (map[d] ??= {}).add('${e['id']}') : map[d]?.remove(e['id'])),
                    ),
                ]),
              ]),
            ),
          Align(
            alignment: Alignment.centerRight,
            child: SaveButton(
              onPressed: () async {
                try {
                  await api.put('materiel/settings', {'managers': {for (final e in map.entries) if (e.value.isNotEmpty) e.key: e.value.toList()}});
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ),
        ]);
      });
    });
  }
}

class _CheckoutTile extends StatelessWidget {
  const _CheckoutTile(this.c);

  final Map<String, dynamic> c;

  @override
  Widget build(BuildContext context) {
    final st = matCheckoutStatuses['${c['status']}'] ?? ('${c['status']}', YColors.muted);
    final lines = _lines(c);
    final left = lines.where((l) => l['returned'] != true).length;
    final late = _late(c);
    final sig = _signature['${c['signature_status'] ?? ''}'];
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      leading: InitialsAvatar(_holder(c), size: 38),
      title: Text(tr('${_holder(c)} · ${c['purpose'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w600), maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        tr([
          if (c['number'] != null) '${c['number']}',
          lines.map((l) => '${l['name']}').join(', '),
          'sorti ${_dt(c['out_at'])}',
          if (_open(c) && c['expected_back_at'] != null) 'retour prévu ${_dt(c['expected_back_at'])}',
          if (c['status'] == 'partial') '$left article(s) encore dehors',
          if (c['_local'] == true) 'en attente d\'envoi',
        ].join(' · ')),
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: Wrap(spacing: 6, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, alignment: WrapAlignment.end, children: [
        if (late) const StatusChip('En retard', color: YColors.danger, icon: Icons.alarm),
        if (sig != null) StatusChip(sig.$1, color: sig.$2, icon: Icons.draw_outlined),
        StatusChip(st.$1, color: st.$2),
      ]),
      onTap: () => showCheckout(context, '${c['id']}'),
    );
  }
}

// ---------------------------------------------------------------------------
// Nouvelle sortie
// ---------------------------------------------------------------------------

Future<void> newCheckout(BuildContext context) => showFormDialog(context, title: 'Nouvelle sortie de matériel', maxWidth: 680, builder: (_) => const _CheckoutForm());

class _CheckoutForm extends StatefulWidget {
  const _CheckoutForm();

  @override
  State<_CheckoutForm> createState() => _CheckoutFormState();
}

class _CheckoutFormState extends State<_CheckoutForm> {
  late final Session _s = context.read<Session>();
  late final bool _manage = _s.can('materiel.manage');
  late String? _employee = _manage ? null : myEmployee(_s)?['id'] as String?;
  final _holderName = TextEditingController();
  final _purpose = TextEditingController();
  final _destination = TextEditingController();
  final _notes = TextEditingController();
  final _search = TextEditingController();
  DateTime? _back = DateTime.now().add(const Duration(hours: 8));
  final Set<String> _picked = {};
  bool _busy = false;

  Future<void> _pickBack() async {
    final d = await pickDate(context, firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime(2100), initialDate: _back ?? DateTime.now());
    if (d == null || !mounted) return;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_back ?? DateTime.now()));
    setState(() => _back = DateTime(d.year, d.month, d.day, t?.hour ?? 18, t?.minute ?? 0));
  }

  Future<void> _save() async {
    if (_picked.isEmpty) return toast(context, tr('Cochez le matériel emporté.'), error: true);
    if (_purpose.text.trim().isEmpty) return toast(context, tr('Indiquez le motif de la sortie (intervention, tournage, chantier…).'), error: true);
    if (_manage && _employee == null && _holderName.text.trim().isEmpty) return toast(context, tr('Indiquez qui emporte le matériel.'), error: true);
    setState(() => _busy = true);
    final emp = _employee == null ? null : LocalDb.get('rh_employees', _employee!);
    try {
      await context.read<SyncEngine>().save('mat_checkouts', {
        'id': newId(),
        'employee_id': _employee,
        'holder_name': _employee == null ? (_holderName.text.trim().isEmpty ? _s.user['name'] : _holderName.text.trim()) : null,
        'department': emp?['department'],
        'purpose': _purpose.text.trim(),
        'destination': _destination.text.trim().isEmpty ? null : _destination.text.trim(),
        'out_at': DateTime.now().toIso8601String(),
        'expected_back_at': _back?.toIso8601String(),
        'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        'status': 'out',
        'lines': [
          for (final id in _picked) {'item_id': id, 'name': LocalDb.get('mat_items', id)?['name'], 'condition_out': 'ok', 'returned': false},
        ],
      });
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr(_s.hasModule('signature') ? 'Sortie enregistrée : le bon part en signature au porteur et au responsable.' : 'Sortie enregistrée : imprimez le bon pour le faire signer.'));
      }
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final staff = LocalDb.hasEntity('rh_employees') ? (LocalDb.all('rh_employees').where((e) => e['active'] != false).toList()..sort((a, b) => empName(a).compareTo(empName(b)))) : <Map<String, dynamic>>[];
    final q = _search.text.toLowerCase();
    final avail = _items().where((i) => i['status'] == 'available' && (q.isEmpty || '${i['name']} ${i['code'] ?? ''} ${i['serial'] ?? ''} ${i['category'] ?? ''}'.toLowerCase().contains(q))).toList();
    final narrow = MediaQuery.of(context).size.width < 600;
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      if (_manage) ...[
        Field(
          label: tr('Qui emporte le matériel ?'),
          child: DropdownButtonFormField<String?>(
            isExpanded: true,
            initialValue: _employee,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr(staff.isEmpty ? 'Saisir le nom' : '— Autre personne (saisir le nom)'))),
              for (final e in staff) DropdownMenuItem<String?>(value: '${e['id']}', child: Text(tr([empName(e), e['department']].where((x) => x != null && '$x'.isNotEmpty).join(' · ')), overflow: TextOverflow.ellipsis)),
            ],
            onChanged: (v) => setState(() => _employee = v),
          ),
        ),
        if (_employee == null) Field(label: tr('Nom du porteur'), child: TextField(controller: _holderName)),
      ] else
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Row(children: [
            InitialsAvatar('${_s.user['name']}', size: 32),
            const SizedBox(width: 10),
            Expanded(child: Text(tr('Matériel emporté par vous : ${_s.user['name']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
          ]),
        ),
      Field(label: tr('Motif de la sortie *'), child: TextField(controller: _purpose, decoration: InputDecoration(hintText: tr('ex. Intervention vidéosurveillance — Clinique Les Palmiers')))),
      if (narrow) ...[
        Field(label: tr('Destination'), child: TextField(controller: _destination, decoration: InputDecoration(hintText: tr('ex. Cocody Riviera 3')))),
        _backField(theme),
      ] else
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(child: Field(label: tr('Destination'), child: TextField(controller: _destination, decoration: InputDecoration(hintText: tr('ex. Cocody Riviera 3'))))),
          const SizedBox(width: 12),
          Expanded(child: _backField(theme)),
        ]),
      Row(children: [
        Text(tr('Matériel emporté'), style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(width: 8),
        if (_picked.isNotEmpty) StatusChip('${_picked.length} coché(s)', color: YColors.blue),
      ]),
      const SizedBox(height: 8),
      TextField(controller: _search, onChanged: (_) => setState(() {}), decoration: InputDecoration(prefixIcon: const Icon(Icons.search, size: 20), hintText: tr('Rechercher : nom, code, n° de série'))),
      const SizedBox(height: 8),
      Container(
        constraints: const BoxConstraints(maxHeight: 280),
        decoration: BoxDecoration(border: Border.all(color: theme.dividerColor), borderRadius: BorderRadius.circular(12)),
        child: avail.isEmpty
            ? Padding(padding: const EdgeInsets.all(16), child: Text(tr('Aucun matériel disponible.'), style: TextStyle(color: theme.hintColor)))
            : ListView(shrinkWrap: true, children: [
                for (final i in avail)
                  CheckboxListTile(
                    dense: true,
                    value: _picked.contains(i['id']),
                    onChanged: (v) => setState(() => v == true ? _picked.add('${i['id']}') : _picked.remove(i['id'])),
                    title: Text(tr('${i['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text(tr([i['code'], i['serial'] == null ? null : 'S/N ${i['serial']}', i['category']].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
                    controlAffinity: ListTileControlAffinity.leading,
                  ),
              ]),
      ),
      const SizedBox(height: 12),
      Field(label: tr('Observations'), child: TextField(controller: _notes, minLines: 1, maxLines: 4)),
      Row(children: [
        Icon(Icons.draw_outlined, size: 18, color: theme.hintColor),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            tr(_s.hasModule('signature')
                ? 'Un bon de sortie PDF est envoyé en signature au porteur et au responsable du département.'
                : 'Un bon de sortie PDF est prêt à imprimer, avec les cadres de signature du porteur et du responsable du département.'),
            style: TextStyle(color: theme.hintColor, fontSize: 12.5),
          ),
        ),
      ]),
      const SizedBox(height: 16),
      FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.logout, size: 18), label: Text(tr(_busy ? 'Enregistrement…' : 'Enregistrer la sortie'))),
    ]);
  }

  Widget _backField(ThemeData theme) => Field(
        label: tr('Retour prévu'),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: _pickBack,
          child: InputDecorator(
            decoration: const InputDecoration(suffixIcon: Icon(Icons.event, size: 18)),
            child: Text(tr(_back == null ? '—' : dateTimeFr(_back!.toIso8601String()))),
          ),
        ),
      );
}

// ---------------------------------------------------------------------------
// Fiche d'une sortie : retour article par article, bon PDF, signature
// ---------------------------------------------------------------------------

Future<void> showCheckout(BuildContext context, String id) {
  final c = LocalDb.get('mat_checkouts', id);
  return showFormDialog(context, title: 'Sortie ${c?['number'] ?? ''}', maxWidth: 760, builder: (_) => _CheckoutView(id: id));
}

class _CheckoutView extends StatefulWidget {
  const _CheckoutView({required this.id});

  final String id;

  @override
  State<_CheckoutView> createState() => _CheckoutViewState();
}

class _CheckoutViewState extends State<_CheckoutView> {
  final Map<String, String> _cond = {}; // item_id → état au retour (articles cochés)
  final Map<String, TextEditingController> _note = {};
  bool _busy = false;

  Future<void> _pdf(Map c) async {
    try {
      final bytes = await context.read<Session>().api.download('materiel/checkouts/${widget.id}/pdf');
      await previewPdf(name: 'Bon de sortie ${c['number'] ?? ''}', onLayout: (_) async => Uint8List.fromList(bytes));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _sign() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('materiel/checkouts/${widget.id}/signature', {});
      if (mounted) {
        await context.read<SyncEngine>().sync();
        if (mounted) toast(context, tr('Bon envoyé en signature.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _return(Map c) async {
    if (_cond.isEmpty) return toast(context, tr('Cochez le matériel rendu.'), error: true);
    setState(() => _busy = true);
    try {
      await context.read<SyncEngine>().save('mat_checkouts', {
        'id': widget.id,
        'lines': [
          for (final l in _lines(c))
            {
              ...l,
              if (_cond.containsKey(l['item_id'])) ...{'returned': true, 'condition_in': _cond[l['item_id']], 'note': _note[l['item_id']]?.text.trim().isEmpty ?? true ? l['note'] : _note[l['item_id']]!.text.trim()},
            },
        ],
      });
      _cond.clear();
      if (mounted) toast(context, tr('Retour enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _cancel() async {
    if (!await confirm(context, tr('Annuler cette sortie ?'), tr('Le matériel redevient disponible.'), ok: tr('Annuler la sortie'), danger: true)) return;
    if (!mounted) return;
    try {
      await context.read<SyncEngine>().save('mat_checkouts', {'id': widget.id, 'status': 'cancelled'});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('mat_checkouts'),
      builder: (context, _, _) {
        final c = LocalDb.get('mat_checkouts', widget.id);
        if (c == null) return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
        final theme = Theme.of(context);
        final st = matCheckoutStatuses['${c['status']}'] ?? ('${c['status']}', YColors.muted);
        final lines = _lines(c);
        final open = _open(c);
        final local = c['_local'] == true;
        final sig = _signature['${c['signature_status'] ?? ''}'];
        final signers = [for (final x in (c['signers'] as List? ?? const [])) asMap(x)];
        final rows = <(String, String)>[
          ('Porteur', _holder(c)),
          if ('${c['department'] ?? ''}'.isNotEmpty) ('Département', '${c['department']}'),
          ('Motif', '${c['purpose'] ?? ''}'),
          if ('${c['destination'] ?? ''}'.isNotEmpty) ('Destination', '${c['destination']}'),
          ('Sorti le', _dt(c['out_at'])),
          if (c['expected_back_at'] != null) ('Retour prévu', _dt(c['expected_back_at'])),
          if (c['back_at'] != null) ('Rendu le', _dt(c['back_at'])),
          if (signers.isNotEmpty) ('Signataires', signers.map((x) => '${x['name']} (${'${x['label'] ?? ''}'.toLowerCase()})').join(', ')),
        ];
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
          Wrap(spacing: 8, runSpacing: 6, children: [
            StatusChip(local ? 'En attente d\'envoi' : st.$1, color: local ? YColors.warning : st.$2),
            if (_late(c)) const StatusChip('En retard', color: YColors.danger, icon: Icons.alarm),
            if (sig != null) StatusChip(sig.$1, color: sig.$2, icon: Icons.draw_outlined),
          ]),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: theme.colorScheme.surfaceContainerLow, borderRadius: BorderRadius.circular(12)),
            child: Column(children: [
              for (final r in rows)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    SizedBox(width: 130, child: Text(tr(r.$1), style: TextStyle(color: theme.hintColor))),
                    Expanded(child: Text(tr(r.$2), style: const TextStyle(fontWeight: FontWeight.w600))),
                  ]),
                ),
            ]),
          ),
          const SizedBox(height: 16),
          Text(tr(open ? 'Matériel — cochez ce qui est rendu' : 'Matériel'), style: TextStyle(fontWeight: FontWeight.w700, color: theme.colorScheme.primary)),
          const SizedBox(height: 6),
          for (final l in lines)
            Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.fromLTRB(4, 4, 12, 4),
              decoration: BoxDecoration(border: Border.all(color: theme.dividerColor), borderRadius: BorderRadius.circular(12)),
              child: Column(children: [
                Row(children: [
                  if (open && l['returned'] != true && !local)
                    Checkbox(
                      value: _cond.containsKey(l['item_id']),
                      onChanged: (v) => setState(() => v == true ? _cond['${l['item_id']}'] = 'ok' : _cond.remove(l['item_id'])),
                    )
                  else
                    Padding(
                      padding: const EdgeInsets.all(12),
                      child: Icon(l['returned'] == true ? Icons.check_circle : Icons.radio_button_unchecked, color: l['returned'] == true ? YColors.success : theme.hintColor, size: 22),
                    ),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${l['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(
                        tr([
                          l['code'],
                          if (l['serial'] != null) 'S/N ${l['serial']}',
                          if (l['returned'] == true) 'rendu ${_dt(l['returned_at'])} · ${matConditions['${l['condition_in']}'] ?? ''}${l['received_by'] != null ? ' · reçu par ${l['received_by']}' : ''}',
                          if ('${l['note'] ?? ''}'.isNotEmpty) '${l['note']}',
                        ].where((x) => x != null && '$x'.isNotEmpty).join(' · ')),
                        style: TextStyle(color: theme.hintColor, fontSize: 12.5),
                      ),
                    ]),
                  ),
                  if (l['returned'] == true && (l['condition_in'] == 'damaged' || l['condition_in'] == 'lost'))
                    StatusChip(matConditions['${l['condition_in']}']!, color: l['condition_in'] == 'lost' ? YColors.danger : YColors.warning),
                ]),
                if (_cond.containsKey(l['item_id']))
                  Padding(
                    padding: const EdgeInsets.fromLTRB(44, 0, 0, 8),
                    child: Row(children: [
                      SizedBox(
                        width: 170,
                        child: DropdownButtonFormField<String>(
                          isExpanded: true,
                          initialValue: _cond[l['item_id']],
                          decoration: const InputDecoration(isDense: true),
                          items: [for (final e in matConditions.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                          onChanged: (v) => setState(() => _cond['${l['item_id']}'] = v!),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextField(
                          controller: _note.putIfAbsent('${l['item_id']}', TextEditingController.new),
                          decoration: InputDecoration(isDense: true, hintText: tr('Remarque (ex. batterie manquante)')),
                        ),
                      ),
                    ]),
                  ),
              ]),
            ),
          if ('${c['notes'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('Observations : ${c['notes']}'), style: TextStyle(color: theme.hintColor))),
          const SizedBox(height: 16),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (open && !local) FilledButton.icon(onPressed: _busy || _cond.isEmpty ? null : () => _return(c), icon: const Icon(Icons.login, size: 18), label: Text(tr('Enregistrer le retour'))),
            if (!local) OutlinedButton.icon(onPressed: () => _pdf(c), icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(tr('Bon PDF'))),
            if (!local && s.hasModule('signature') && !const ['sent', 'signed'].contains(c['signature_status']) && c['status'] != 'cancelled')
              OutlinedButton.icon(onPressed: _busy ? null : _sign, icon: const Icon(Icons.draw_outlined, size: 18), label: Text(tr('Envoyer en signature'))),
            if (open && lines.every((l) => l['returned'] != true))
              TextButton.icon(style: TextButton.styleFrom(foregroundColor: YColors.danger), onPressed: _cancel, icon: const Icon(Icons.undo, size: 18), label: Text(tr('Annuler la sortie'))),
          ]),
          if (!s.hasModule('signature'))
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Text(tr('Imprimez le bon et faites-le signer par le porteur et le responsable du département (signature électronique : module Signature).'),
                  style: TextStyle(color: theme.hintColor, fontSize: 12)),
            ),
        ]);
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Matériel
// ---------------------------------------------------------------------------

class _ItemsTab extends StatefulWidget {
  const _ItemsTab({required this.manage});

  final bool manage;

  @override
  State<_ItemsTab> createState() => _ItemsTabState();
}

class _ItemsTabState extends State<_ItemsTab> {
  String _q = '';
  String? _status;
  final Map<String, String?> _f = {};
  int _gen = 0;

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final cur = context.read<Session>().currency;
    return ListenableBuilder(
      listenable: Listenable.merge([LocalDb.listen('mat_items'), LocalDb.listen('mat_checkouts')]),
      builder: (context, _) {
        final all = _items();
        final q = _q.toLowerCase();
        final base = all
            .where((i) => q.isEmpty || '${i['name']} ${i['code'] ?? ''} ${i['serial'] ?? ''} ${i['category'] ?? ''} ${i['brand'] ?? ''}'.toLowerCase().contains(q))
            .where((i) => _f['category'] == null || '${i['category'] ?? ''}'.trim() == _f['category'])
            .where((i) => _f['department'] == null || '${i['department']}' == _f['department'])
            .where((i) => _f['brand'] == null || '${i['brand']}' == _f['brand'])
            .toList();
        final shown = base.where((i) => _status == null || i['status'] == _status).toList();
        final statuses = {'': 'Tout', for (final e in matItemStatuses.entries) if (all.any((i) => i['status'] == e.key)) e.key: e.value.$1};
        final groups = <String, List<Map<String, dynamic>>>{};
        for (final i in shown) {
          (groups['${i['category'] ?? ''}'.trim().isEmpty ? 'Sans catégorie' : '${i['category']}'.trim()] ??= []).add(i);
        }
        final open = _checkouts().where(_open).toList();
        String? whereIs(String id) {
          for (final c in open) {
            if (_lines(c).any((l) => l['item_id'] == id && l['returned'] != true)) return '${_holder(c)} · ${c['number'] ?? ''}';
          }
          return null;
        }

        return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
          FilterBar(
            key: ValueKey(_gen),
            padding: const EdgeInsets.only(bottom: 12),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Nom, code, n° de série…',
            statuses: statuses,
            status: _status ?? '',
            onStatus: (v) => setState(() => _status = v.isEmpty ? null : v),
            statusCounts: {for (final k in statuses.keys) k: base.where((i) => k.isEmpty || i['status'] == k).length},
            filters: [
              FilterSpec('category', 'Catégorie', {for (final i in all) if ('${i['category'] ?? ''}'.trim().isNotEmpty) '${i['category']}'.trim(): '${i['category']}'.trim()}, icon: Icons.category_outlined),
              FilterSpec('department', 'Département', filterOptions(all, 'department'), icon: Icons.apartment_outlined),
              FilterSpec('brand', 'Marque', filterOptions(all, 'brand'), icon: Icons.sell_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f[k] = v),
            count: shown.length,
            countLabel: 'article',
            onReset: () => setState(() {
              _q = '';
              _status = null;
              _f.clear();
              _gen++;
            }),
          ),
          if (all.isEmpty)
            EmptyState(
              icon: Icons.videocam_outlined,
              title: tr('Aucun matériel'),
              message: tr('Enregistrez le matériel qui part en intervention : caméras, ordinateurs, outillage, appareils de mesure…'),
              action: widget.manage ? FilledButton.icon(onPressed: () => editItem(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau matériel'))) : null,
            ),
          if (all.isNotEmpty && shown.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucun matériel ne correspond à ces filtres.')),
          for (final g in groups.entries) ...[
            Padding(padding: const EdgeInsets.fromLTRB(4, 8, 4, 8), child: Text(tr('${g.key} · ${g.value.length}'), style: const TextStyle(fontWeight: FontWeight.w700))),
            Card(
              clipBehavior: Clip.antiAlias,
              child: Column(children: [
                for (final i in g.value)
                  Builder(builder: (context) {
                    final st = matItemStatuses['${i['status']}'] ?? ('${i['status']}', YColors.muted);
                    final at = i['status'] == 'out' ? whereIs('${i['id']}') : null;
                    return ListTile(
                      leading: Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(color: st.$2.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
                        child: Icon(Icons.videocam_outlined, color: st.$2, size: 20),
                      ),
                      title: Text(tr('${i['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      subtitle: Text(tr([
                        i['code'],
                        if (i['serial'] != null) 'S/N ${i['serial']}',
                        [i['brand'], i['model']].where((x) => x != null && '$x'.isNotEmpty).join(' '),
                        i['department'],
                        if (toDouble(i['value']) > 0) money(i['value'], cur),
                        if (at != null) 'chez $at',
                      ].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
                      trailing: StatusChip(st.$1, color: st.$2),
                      onTap: widget.manage ? () => editItem(context, i) : null,
                    );
                  }),
              ]),
            ),
          ],
        ]);
      },
    );
  }
}

Future<void> editItem(BuildContext context, [Map<String, dynamic>? item]) {
  final x = item ?? {};
  final c = {for (final f in ['name', 'code', 'category', 'brand', 'model', 'serial', 'department', 'location', 'notes']) f: TextEditingController(text: '${x[f] ?? ''}')};
  final value = TextEditingController(text: toDouble(x['value']) > 0 ? '${toDouble(x['value']).round()}' : '');
  var status = '${x['status'] ?? 'available'}';
  return showFormDialog(context, title: item == null ? 'Nouveau matériel' : '${x['name']}', builder: (dc) {
    return StatefulBuilder(builder: (dc, setS) {
      Widget tf(String f, String label, {String? hint}) => Field(label: label, child: TextField(controller: c[f], decoration: InputDecoration(hintText: hint == null ? null : trn(hint))));
      Widget pair(Widget a, Widget b) => Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: a), const SizedBox(width: 12), Expanded(child: b)]);
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        tf('name', 'Nom *', hint: 'ex. Caméra Sony FX3'),
        pair(tf('code', 'Code / n° d\'inventaire', hint: 'ex. CAM-01'), tf('serial', 'N° de série')),
        pair(tf('category', 'Catégorie', hint: 'ex. Vidéo, Informatique, Outillage'), tf('department', 'Département propriétaire')),
        pair(tf('brand', 'Marque'), tf('model', 'Modèle')),
        pair(
          tf('location', 'Rangement', hint: 'ex. Magasin, armoire 2'),
          Field(label: tr('Valeur'), child: TextField(controller: value, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9\s]'))])),
        ),
        if (status == 'out')
          Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(tr('Matériel actuellement sorti : son état change au retour.'), style: TextStyle(color: Theme.of(dc).hintColor)))
        else
          Field(
            label: tr('État'),
            child: DropdownButtonFormField<String>(
              isExpanded: true,
              initialValue: status,
              items: [for (final e in matItemStatuses.entries) if (e.key != 'out') DropdownMenuItem(value: e.key, child: Text(tr(e.value.$1)))],
              onChanged: (v) => setS(() => status = v!),
            ),
          ),
        Field(label: tr('Notes'), child: TextField(controller: c['notes'], minLines: 1, maxLines: 4)),
        Row(children: [
          if (item != null && status != 'out')
            TextButton.icon(
              style: TextButton.styleFrom(foregroundColor: YColors.danger),
              onPressed: () async {
                final sync = dc.read<SyncEngine>();
                if (!await confirm(dc, tr('Supprimer ce matériel ?'), tr('Son historique de sorties est conservé.'), danger: true, ok: tr('Supprimer'))) return;
                try {
                  await sync.remove('mat_items', '${x['id']}');
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.delete_outline),
              label: Text(tr('Supprimer')),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              if (c['name']!.text.trim().isEmpty) return toast(dc, tr('Le nom est obligatoire.'), error: true);
              try {
                await dc.read<SyncEngine>().save('mat_items', {
                  'id': x['id'] ?? newId(),
                  for (final e in c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(),
                  'value': num.tryParse(value.text.replaceAll(' ', '')),
                  if (status != 'out') 'status': status,
                });
                if (dc.mounted) Navigator.pop(dc);
              } catch (e) {
                if (dc.mounted) toast(dc, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]);
    });
  });
}
