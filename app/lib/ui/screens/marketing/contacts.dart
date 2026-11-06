import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/api_client.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'mk_common.dart';
import '../../../i18n/i18n.dart';

const segmentFieldLabels = {
  'list': 'Appartient à la liste',
  'not_list': 'N\'appartient pas à la liste',
  'tag': 'A l\'étiquette',
  'not_tag': 'N\'a pas l\'étiquette',
  'source': 'Origine',
  'city': 'Ville',
  'opened': 'A ouvert un e-mail depuis (jours)',
  'clicked': 'A cliqué depuis (jours)',
  'inactive': 'Aucune ouverture depuis (jours)',
  'created': 'Ajouté depuis (jours)',
  'score_min': 'Score d\'engagement ≥',
  'birthday_month': 'Anniversaire au mois de',
  'has_email': 'A un e-mail',
  'has_phone': 'A un téléphone',
  'campaign_opened': 'A ouvert la campagne',
  'campaign_not_opened': 'N\'a pas ouvert la campagne',
  'spent_min': 'Total des achats ≥ (montant)',
  'orders_min': 'Nombre d\'achats ≥',
  'bought_within': 'A acheté depuis (jours)',
  'no_purchase': 'Aucun achat depuis (jours)',
  'rfm': 'Profil client (RFM)',
};

const rfmProfiles = {'champions': 'Meilleurs clients', 'loyal': 'Clients fidèles', 'promising': 'Nouveaux clients prometteurs', 'at_risk': 'À risque (ne reviennent plus)', 'lost': 'Perdus', 'others': 'Autres'};

const contactSources = {'manual': 'Saisie', 'import': 'Import', 'partner': 'Client ERP', 'lead': 'Prospect CRM', 'form': 'Formulaire'};
const monthsFr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

class ContactsTab extends StatefulWidget {
  const ContactsTab({super.key, required this.data, required this.reload, required this.canManage});

  final Map<String, dynamic> data;
  final Future<void> Function() reload;
  final bool canManage;

  @override
  State<ContactsTab> createState() => _ContactsTabState();
}

class _ContactsTabState extends State<ContactsTab> {
  String _filter = 'all'; // all, list:ID, segment:ID
  String _q = '';
  bool _byScore = false;
  List<Map<String, dynamic>>? _contacts;
  int _total = 0;
  Timer? _debounce;

  ApiClient get _api => context.read<Session>().api;
  List<Map<String, dynamic>> get _lists => mkList(widget.data['lists']);
  List<Map<String, dynamic>> get _segments => mkList(widget.data['segments']);
  int? get _listId => _filter.startsWith('list:') ? int.parse(_filter.substring(5)) : null;
  int? get _segmentId => _filter.startsWith('segment:') ? int.parse(_filter.substring(8)) : null;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = mkMap(await _api.get('marketing/contacts', query: {
        'list_id': ?_listId?.toString(),
        'segment_id': ?_segmentId?.toString(),
        if (_q.isNotEmpty) 'q': _q,
        if (_byScore) 'sort': 'score',
      }));
      if (mounted) setState(() => (_contacts = mkList(r['data']), _total = (r['total'] as num).toInt()));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _refreshAll() async {
    await widget.reload();
    await _load();
  }

  void _select(String f) => setState(() {
        _filter = f;
        _contacts = null;
        _load();
      });

  Future<void> _listDialog([Map<String, dynamic>? l]) async {
    final name = TextEditingController(text: '${l?['name'] ?? ''}');
    final desc = TextEditingController(text: '${l?['description'] ?? ''}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr(l == null ? 'Nouvelle liste' : 'Modifier la liste')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: name, autofocus: true, decoration: InputDecoration(labelText: tr('Nom'))),
          TextField(controller: desc, decoration: InputDecoration(labelText: tr('Description'))),
        ]),
        actions: [
          if (l != null)
            TextButton(
              onPressed: () async {
                await _api.delete('marketing/lists/${l['id']}');
                if (c.mounted) Navigator.pop(c, true);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
          SaveButton(
            onPressed: () async {
              if (name.text.trim().isEmpty) return;
              final body = {'name': name.text.trim(), 'description': desc.text.trim()};
              l == null ? await _api.post('marketing/lists', body) : await _api.put('marketing/lists/${l['id']}', body);
              if (c.mounted) Navigator.pop(c, true);
            },
            child: Text(tr('Enregistrer')),
          ),
        ],
      ),
    );
    if (ok == true) {
      if (l != null && _listId == l['id']) _filter = 'all';
      _refreshAll();
    }
  }

  Future<void> _segmentDialog([Map<String, dynamic>? s]) async {
    final saved = await showSegmentEditor(context, segment: s, lists: _lists, campaigns: mkList(widget.data['campaigns']));
    if (saved == true) {
      if (s != null && _segmentId == s['id']) _filter = 'all';
      _refreshAll();
    }
  }

  Future<void> _contactDialog([Map<String, dynamic>? ct]) async {
    final f = {
      for (final k in ['first_name', 'last_name', 'company_name', 'email', 'phone', 'tags', 'city']) k: TextEditingController(text: '${ct?[k] ?? ''}'),
    };
    var birthday = ct?['birthday'] as String?;
    final lists = <int>{for (final l in mkList(ct?['lists'])) l['id'] as int, if (ct == null && _listId != null) _listId!};
    final unsub = {for (final ch in ['email', 'sms', 'whatsapp']) ch: ct?['unsubscribed_${ch}_at'] != null};
    await showFormDialog(
      context,
      title: ct == null ? 'Nouveau contact' : 'Contact',
      builder: (c) => StatefulBuilder(
        builder: (c, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          if (ct != null) _ContactSummary(contact: ct),
          Row(children: [
            Expanded(child: Field(label: tr('Prénom'), child: TextField(controller: f['first_name']))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Nom'), child: TextField(controller: f['last_name']))),
          ]),
          Field(label: tr('Société'), child: TextField(controller: f['company_name'])),
          Field(label: tr('E-mail'), child: TextField(controller: f['email'], keyboardType: TextInputType.emailAddress)),
          Field(label: tr('Téléphone / WhatsApp'), child: TextField(controller: f['phone'], keyboardType: TextInputType.phone)),
          Row(children: [
            Expanded(child: Field(label: tr('Ville'), child: TextField(controller: f['city']))),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Anniversaire'),
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final d = await pickDate(c, initialDate: DateTime.tryParse(birthday ?? '') ?? DateTime(1990), firstDate: DateTime(1920), lastDate: DateTime.now());
                    if (d != null) set(() => birthday = isoDate(d));
                  },
                  icon: const Icon(Icons.cake_outlined, size: 18),
                  label: Text(tr(birthday == null ? 'Choisir' : dateFr(birthday))),
                ),
              ),
            ),
          ]),
          Field(label: tr('Étiquettes (séparées par des virgules)'), child: TextField(controller: f['tags'], decoration: InputDecoration(hintText: tr('vip, abidjan…')))),
          Field(
            label: tr('Listes'),
            child: Wrap(spacing: 8, runSpacing: 8, children: [
              for (final l in _lists) FilterChip(label: Text(tr('${l['name']}')), selected: lists.contains(l['id']), onSelected: (v) => set(() => v ? lists.add(l['id'] as int) : lists.remove(l['id']))),
            ]),
          ),
          if (ct != null)
            for (final ch in ['email', 'sms', 'whatsapp'])
              SwitchListTile(contentPadding: EdgeInsets.zero, dense: true, title: Text(tr('Désinscrit des ${mkChannelLabels[ch]}')), value: unsub[ch]!, onChanged: (v) => set(() => unsub[ch] = v)),
          if (ct != null) _ContactActivity(id: ct['id'] as int),
          const SizedBox(height: 8),
          if (widget.canManage)
            Row(children: [
              if (ct != null)
                TextButton(
                  onPressed: () async {
                    if (!await confirm(c, tr('Supprimer ce contact ?'), '${ct['full_name'] ?? ct['email']}', danger: true, ok: tr('Supprimer'))) return;
                    await _api.delete('marketing/contacts/${ct['id']}');
                    if (c.mounted) Navigator.pop(c);
                  },
                  child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                ),
              const Spacer(),
              SaveButton(
                onPressed: () async {
                  final body = {
                    for (final e in f.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(),
                    'birthday': birthday,
                    'list_ids': lists.toList(),
                    if (ct != null) for (final ch in unsub.entries) 'unsubscribed_${ch.key}': ch.value,
                  };
                  try {
                    ct == null ? await _api.post('marketing/contacts', body) : await _api.put('marketing/contacts/${ct['id']}', body);
                    if (c.mounted) Navigator.pop(c);
                  } catch (e) {
                    if (c.mounted) toast(c, errorText(e), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
            ]),
        ]),
      ),
    );
    _refreshAll();
  }

  Future<void> _import() async {
    if (_lists.isEmpty) {
      toast(context, tr('Créez d\'abord une liste.'), error: true);
      return;
    }
    var list = _listId ?? _lists.first['id'] as int;
    var source = 'partners';
    var partnerType = 'customer';
    var triggers = true;
    final text = TextEditingController();
    final res = await showFormDialog<Map<String, dynamic>>(
      context,
      title: tr('Importer des contacts'),
      builder: (c) => StatefulBuilder(
        builder: (c, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          Field(
            label: tr('Dans la liste'),
            child: DropdownButtonFormField<int>(
              initialValue: list,
              isExpanded: true,
              items: [for (final l in _lists) DropdownMenuItem(value: l['id'] as int, child: Text(tr('${l['name']}')))],
              onChanged: (v) => set(() => list = v ?? list),
            ),
          ),
          Field(
            label: tr('Source'),
            child: RadioGroup<String>(
              groupValue: source,
              onChanged: (v) => set(() => source = v ?? source),
              child: Column(children: [
                RadioListTile(contentPadding: EdgeInsets.zero, value: 'partners', title: Text(tr('Clients et fournisseurs de l\'ERP'))),
                if (widget.data['has_crm'] == true) RadioListTile(contentPadding: EdgeInsets.zero, value: 'leads', title: Text(tr('Prospects ouverts du CRM'))),
                RadioListTile(contentPadding: EdgeInsets.zero, value: 'text', title: Text(tr('Coller depuis Excel / CSV'))),
              ]),
            ),
          ),
          if (source == 'partners')
            SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'customer', label: Text(tr('Clients'))),
                ButtonSegment(value: 'supplier', label: Text(tr('Fournisseurs'))),
                ButtonSegment(value: 'all', label: Text(tr('Tous'))),
              ],
              selected: {partnerType},
              onSelectionChanged: (s) => set(() => partnerType = s.first),
            ),
          if (source == 'text') ...[
            Text(tr('Une ligne par contact : prénom, nom, société, e-mail, téléphone (séparateur ; , ou tabulation). L\'ordre des colonnes e-mail / téléphone est détecté.'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
            const SizedBox(height: 8),
            TextField(controller: text, maxLines: 10, decoration: InputDecoration(hintText: tr('Awa;Koné;Boutique Awa;awa@exemple.ci;0707070707'))),
          ],
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('Déclencher les automatisations de cette liste')),
            subtitle: Text(tr('Ex. série de bienvenue pour chaque nouveau contact')),
            value: triggers,
            onChanged: (v) => set(() => triggers = v),
          ),
          const SizedBox(height: 16),
          SaveButton(
            onPressed: () async {
              try {
                final r = mkMap(await _api.post('marketing/import', {'list_id': list, 'source': source, 'partner_type': partnerType, 'trigger_automations': triggers, if (source == 'text') 'text': text.text}));
                if (c.mounted) Navigator.pop(c, r);
              } catch (e) {
                if (c.mounted) toast(c, errorText(e), error: true);
              }
            },
            child: Text(tr('Importer')),
          ),
        ]),
      ),
    );
    if (res != null && mounted) {
      toast(context, tr('${res['added']} ajouté(s), ${res['updated']} déjà présent(s), ${res['skipped']} ignoré(s) sans e-mail ni téléphone.'));
      _refreshAll();
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final wide = MediaQuery.of(context).size.width > 900;
    Widget head(String t, VoidCallback? add, String tip) => Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(children: [
            Expanded(child: Text(tr(t), style: const TextStyle(fontWeight: FontWeight.w700))),
            if (widget.canManage && add != null) IconButton(tooltip: trn(tip), onPressed: add, icon: const Icon(Icons.add)),
          ]),
        );
    final side = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      ListTile(dense: true, selected: _filter == 'all', title: Text(tr('Tous les contacts')), trailing: Text(tr('${widget.data['contacts_total']}')), onTap: () => _select('all')),
      head('Listes', () => _listDialog(), 'Nouvelle liste'),
      for (final l in _lists)
        ListTile(
          dense: true,
          selected: _filter == 'list:${l['id']}',
          leading: const Icon(Icons.list_alt, size: 18),
          title: Text(tr('${l['name']}')),
          subtitle: (l['description'] ?? '').toString().isEmpty ? null : Text(tr('${l['description']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
            Text(tr('${l['contacts_count'] ?? 0}')),
            if (widget.canManage) IconButton(visualDensity: VisualDensity.compact, onPressed: () => _listDialog(l), icon: const Icon(Icons.more_horiz, size: 18)),
          ]),
          onTap: () => _select('list:${l['id']}'),
        ),
      head('Segments dynamiques', () => _segmentDialog(), 'Nouveau segment'),
      if (_segments.isEmpty) Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 8), child: Text(tr('Ex. « VIP d\'Abidjan », « inactifs depuis 90 jours ».'), style: TextStyle(fontSize: 12, color: hint))),
      for (final s in _segments)
        ListTile(
          dense: true,
          selected: _filter == 'segment:${s['id']}',
          leading: const Icon(Icons.filter_alt_outlined, size: 18),
          title: Text(tr('${s['name']}')),
          subtitle: Text(tr('${(s['conditions'] as List? ?? []).length} condition(s)'), style: const TextStyle(fontSize: 12)),
          trailing: widget.canManage ? IconButton(visualDensity: VisualDensity.compact, onPressed: () => _segmentDialog(s), icon: const Icon(Icons.more_horiz, size: 18)) : null,
          onTap: () => _select('segment:${s['id']}'),
        ),
    ]);

    final contacts = Column(children: [
      Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          SizedBox(
            width: 280,
            child: SearchField(
              hint: tr('Rechercher un contact'),
              onChanged: (v) {
                _q = v;
                _debounce?.cancel();
                _debounce = Timer(const Duration(milliseconds: 350), _load);
              },
            ),
          ),
          Text(tr('$_total contact(s)'), style: TextStyle(color: hint)),
          FilterChip(
            avatar: const Icon(Icons.local_fire_department_outlined, size: 16),
            label: Text(tr('Les plus engagés')),
            selected: _byScore,
            onSelected: (v) {
              _byScore = v;
              _load();
            },
          ),
          if (widget.canManage) ...[
            OutlinedButton.icon(onPressed: _import, icon: const Icon(Icons.download_outlined), label: Text(tr('Importer'))),
            FilledButton.icon(onPressed: () => _contactDialog(), icon: const Icon(Icons.person_add_alt), label: Text(tr('Contact'))),
          ],
        ]),
      ),
      Expanded(
        child: _contacts == null
            ? const Center(child: CircularProgressIndicator())
            : _contacts!.isEmpty
                ? EmptyState(icon: Icons.people_outline, title: tr('Aucun contact'), message: tr('Importez vos clients, vos prospects du CRM, collez une liste depuis Excel ou publiez un formulaire d\'inscription.'))
                : Card(
                    child: ListView.separated(
                      itemCount: _contacts!.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (_, i) {
                        final ct = _contacts![i];
                        final unsub = ['email', 'sms', 'whatsapp'].where((ch) => ct['unsubscribed_${ch}_at'] != null).toList();
                        final score = (ct['score'] as num?) ?? 0;
                        return ListTile(
                          leading: InitialsAvatar('${(ct['full_name'] ?? '').toString().isEmpty ? (ct['company_name'] ?? ct['email'] ?? '?') : ct['full_name']}', size: 38),
                          title: Text(tr('${(ct['full_name'] ?? '').toString().isEmpty ? (ct['company_name'] ?? ct['email'] ?? ct['phone']) : ct['full_name']}')),
                          subtitle: Text(tr([ct['company_name'], ct['email'], ct['phone'], ct['city']].where((e) => e != null && '$e'.isNotEmpty).join(' · '))),
                          trailing: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                            if (wide) for (final l in mkList(ct['lists']).take(2)) Chip(visualDensity: VisualDensity.compact, label: Text(tr('${l['name']}'), style: const TextStyle(fontSize: 12))),
                            if (ct['opt_in'] == 'pending') StatusChip(tr('À confirmer'), color: YColors.warning),
                            if (unsub.isNotEmpty) StatusChip(tr('Désinscrit ${unsub.map((c) => mkChannelLabels[c]).join(', ')}'), color: YColors.danger),
                            if (score != 0)
                              Tooltip(
                                message: tr('Score d\'engagement'),
                                child: StatusChip('$score', icon: Icons.local_fire_department_outlined, color: score >= 5 ? YColors.success : (score < 0 ? YColors.danger : YColors.blue)),
                              ),
                          ]),
                          onTap: () => _contactDialog(ct),
                        );
                      },
                    ),
                  ),
      ),
    ]);

    if (wide) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 16),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 290, child: Card(child: SingleChildScrollView(padding: const EdgeInsets.all(8), child: side))),
          const SizedBox(width: 16),
          Expanded(child: contacts),
        ]),
      );
    }
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(children: [
        SizedBox(
          height: 44,
          child: ListView(scrollDirection: Axis.horizontal, children: [
            Padding(padding: const EdgeInsets.only(right: 6), child: ChoiceChip(label: Text(tr('Tous')), selected: _filter == 'all', onSelected: (_) => _select('all'))),
            for (final l in _lists)
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ChoiceChip(label: Text(tr('${l['name']} (${l['contacts_count'] ?? 0})')), selected: _filter == 'list:${l['id']}', onSelected: (_) => _select('list:${l['id']}')),
              ),
            for (final s in _segments)
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: ChoiceChip(avatar: const Icon(Icons.filter_alt_outlined, size: 16), label: Text(tr('${s['name']}')), selected: _filter == 'segment:${s['id']}', onSelected: (_) => _select('segment:${s['id']}')),
              ),
            if (widget.canManage) ActionChip(avatar: const Icon(Icons.add, size: 16), label: Text(tr('Liste')), onPressed: () => _listDialog()),
            const SizedBox(width: 6),
            if (widget.canManage) ActionChip(avatar: const Icon(Icons.add, size: 16), label: Text(tr('Segment')), onPressed: () => _segmentDialog()),
          ]),
        ),
        const SizedBox(height: 8),
        Expanded(child: contacts),
      ]),
    );
  }
}

class _ContactSummary extends StatelessWidget {
  const _ContactSummary({required this.contact});

  final Map<String, dynamic> contact;

  @override
  Widget build(BuildContext context) {
    final ct = contact;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Wrap(spacing: 8, runSpacing: 8, children: [
        StatusChip(tr('Score ${ct['score'] ?? 0}'), color: YColors.blue),
        StatusChip(tr('Origine : ${contactSources[ct['source']] ?? ct['source']}')),
        if (ct['opt_in'] == 'pending') StatusChip(tr('Inscription à confirmer'), color: YColors.warning),
        if (ct['opt_in'] == 'confirmed') StatusChip(tr('Inscription confirmée'), color: YColors.success),
        if (ct['consent_at'] != null) StatusChip(tr('Consentement le ${dateFr(ct['consent_at'])}'), color: YColors.success),
        if (ct['last_activity_at'] != null) StatusChip(tr('Dernière activité le ${dateFr(ct['last_activity_at'])}')),
      ]),
    );
  }
}

/// Historique d'un contact : messages reçus, ouvertures, clics, automatisations.
class _ContactActivity extends StatefulWidget {
  const _ContactActivity({required this.id});

  final int id;

  @override
  State<_ContactActivity> createState() => _ContactActivityState();
}

class _ContactActivityState extends State<_ContactActivity> {
  Map<String, dynamic>? _d;
  bool _open = false;

  Future<void> _load() async {
    try {
      final d = mkMap(await context.read<Session>().api.get('marketing/contacts/${widget.id}'));
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return ExpansionTile(
      tilePadding: EdgeInsets.zero,
      title: Text(tr('Activité'), style: TextStyle(fontWeight: FontWeight.w700)),
      onExpansionChanged: (v) {
        if (v && !_open) {
          _open = true;
          _load();
        }
      },
      children: [
        if (_d == null)
          const Padding(padding: EdgeInsets.all(12), child: LinearProgressIndicator())
        else ...[
          if (mkList(_d!['messages']).isEmpty && mkList(_d!['automations']).isEmpty) Padding(padding: const EdgeInsets.all(8), child: Text(tr('Aucun envoi pour l\'instant.'), style: TextStyle(color: hint))),
          for (final m in mkList(_d!['messages']))
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Icon(m['kind'] == 'automation' ? Icons.account_tree_outlined : channelIcons[m['channel']], size: 20),
              title: Text(tr('${m['source']}')),
              subtitle: Text(tr([
                m['status'] == 'failed' ? 'échec' : (m['sent_at'] != null ? 'reçu le ${dateTimeFr(m['sent_at'])}' : 'en attente'),
                if (m['opened_at'] != null) 'ouvert ${m['opens']}×',
                if (m['clicked_at'] != null) 'cliqué ${m['clicks']}×',
                if (m['unsubscribed_at'] != null) 'désinscrit',
              ].join(' · '))),
            ),
          for (final a in mkList(_d!['automations']))
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.account_tree_outlined, size: 20),
              title: Text(tr('Scénario « ${a['automation']} »')),
              subtitle: Text(tr({'active': 'en cours', 'done': 'terminé', 'exited': 'sorti', 'failed': 'erreur'}[a['status']] ?? '${a['status']}')),
            ),
        ],
      ],
    );
  }
}

// ======================================================================
// Éditeur de segment (avec comptage en direct)
// ======================================================================

Future<bool?> showSegmentEditor(BuildContext context, {Map<String, dynamic>? segment, required List<Map<String, dynamic>> lists, required List<Map<String, dynamic>> campaigns}) {
  return showFormDialog<bool>(
    context,
    title: segment == null ? 'Nouveau segment' : 'Segment',
    builder: (c) => _SegmentEditor(segment: segment, lists: lists, campaigns: campaigns),
  );
}

class _SegmentEditor extends StatefulWidget {
  const _SegmentEditor({this.segment, required this.lists, required this.campaigns});

  final Map<String, dynamic>? segment;
  final List<Map<String, dynamic>> lists;
  final List<Map<String, dynamic>> campaigns;

  @override
  State<_SegmentEditor> createState() => _SegmentEditorState();
}

class _SegmentEditorState extends State<_SegmentEditor> {
  late final _name = TextEditingController(text: '${widget.segment?['name'] ?? ''}');
  late String _match = '${widget.segment?['match'] ?? 'all'}';
  late final List<Map<String, dynamic>> _conds = mkList(widget.segment?['conditions']).isEmpty ? [{'field': 'tag', 'value': ''}] : mkList(widget.segment?['conditions']);
  int? _count;
  List<Map<String, dynamic>> _sample = [];
  Timer? _t;
  int _rev = 0;

  ApiClient get _api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _recount();
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  void _recount() {
    _t?.cancel();
    _t = Timer(const Duration(milliseconds: 400), () async {
      try {
        final r = mkMap(await _api.post('marketing/segments/count', {'match': _match, 'conditions': _conds}));
        if (mounted) setState(() => (_count = (r['count'] as num).toInt(), _sample = mkList(r['sample'])));
      } catch (_) {}
    });
  }

  void _set(int i, Map<String, dynamic> v) {
    setState(() => _conds[i] = v);
    _recount();
  }

  Widget _value(int i) {
    final c = _conds[i];
    final f = '${c['field']}';
    final v = c['value'];
    Widget dropdown<T>(List<DropdownMenuItem<T>> items) => DropdownButtonFormField<T>(
          key: ValueKey('v-$i-$f-$_rev'),
          initialValue: items.any((e) => e.value == v) ? v as T : null,
          isExpanded: true,
          items: items,
          onChanged: (x) => _set(i, {...c, 'value': x}),
        );
    switch (f) {
      case 'list':
      case 'not_list':
        return dropdown<int>([for (final l in widget.lists) DropdownMenuItem(value: l['id'] as int, child: Text(tr('${l['name']}')))]);
      case 'source':
        return dropdown<String>([for (final s in contactSources.entries) DropdownMenuItem(value: s.key, child: Text(tr(s.value)))]);
      case 'birthday_month':
        return dropdown<int>([for (var m = 1; m <= 12; m++) DropdownMenuItem(value: m, child: Text(tr(monthsFr[m - 1])))]);
      case 'campaign_opened':
      case 'campaign_not_opened':
        return dropdown<int>([for (final cp in widget.campaigns.where((x) => x['channel'] == 'email' && x['status'] != 'draft')) DropdownMenuItem(value: cp['id'] as int, child: Text(tr('${cp['name']}')))]);
      case 'rfm':
        return dropdown<String>([for (final e in rfmProfiles.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))]);
      case 'has_email':
      case 'has_phone':
        return const SizedBox.shrink();
      default:
        final numeric = ['opened', 'clicked', 'inactive', 'created', 'score_min', 'spent_min', 'orders_min', 'bought_within', 'no_purchase'].contains(f);
        return TextFormField(
          key: ValueKey('t-$i-$f-$_rev'),
          initialValue: '${v ?? ''}',
          keyboardType: numeric ? TextInputType.number : null,
          decoration: InputDecoration(hintText: trn(numeric ? (f == 'score_min' ? '5' : '30') : 'valeur'), isDense: true),
          onChanged: (x) => _set(i, {...c, 'value': numeric ? int.tryParse(x) : x}),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Field(label: tr('Nom du segment'), child: TextField(controller: _name, autofocus: widget.segment == null, decoration: InputDecoration(hintText: tr('Ex. VIP d\'Abidjan')))),
      Row(children: [
        Text(tr('Contacts qui remplissent ')),
        DropdownButton<String>(
          value: _match,
          items: [DropdownMenuItem(value: 'all', child: Text(tr('toutes les conditions'))), DropdownMenuItem(value: 'any', child: Text(tr('au moins une condition')))],
          onChanged: (v) {
            setState(() => _match = v ?? 'all');
            _recount();
          },
        ),
      ]),
      const SizedBox(height: 8),
      for (var i = 0; i < _conds.length; i++)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Row(children: [
            Expanded(
              flex: 5,
              child: DropdownButtonFormField<String>(
                key: ValueKey('f-$i-$_rev'),
                initialValue: '${_conds[i]['field']}',
                isExpanded: true,
                items: [for (final e in segmentFieldLabels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => _set(i, {'field': v, 'value': null}),
                decoration: const InputDecoration(isDense: true),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(flex: 4, child: _value(i)),
            IconButton(
              onPressed: _conds.length == 1
                  ? null
                  : () {
                      setState(() {
                        _conds.removeAt(i);
                        _rev++;
                      });
                      _recount();
                    },
              icon: const Icon(Icons.close),
            ),
          ]),
        ),
      Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(onPressed: () => setState(() => _conds.add({'field': 'tag', 'value': ''})), icon: const Icon(Icons.add), label: Text(tr('Ajouter une condition'))),
      ),
      const SizedBox(height: 8),
      Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .06), borderRadius: BorderRadius.circular(10)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(_count == null ? 'Calcul…' : '$_count contact(s) correspondent'), style: const TextStyle(fontWeight: FontWeight.w700)),
          if (_sample.isNotEmpty)
            Text(
              tr(_sample.map((s) => [s['first_name'], s['last_name']].whereType<String>().join(' ').trim().isEmpty ? (s['email'] ?? s['phone']) : [s['first_name'], s['last_name']].whereType<String>().join(' ')).join(', ') +
                  ((_count ?? 0) > _sample.length ? '…' : '')),
              style: const TextStyle(fontSize: 13),
            ),
          const SizedBox(height: 4),
          Text(tr('Le segment se met à jour tout seul : un contact qui remplit les conditions plus tard y entrera automatiquement.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        ]),
      ),
      const SizedBox(height: 16),
      Row(children: [
        if (widget.segment != null)
          TextButton(
            onPressed: () async {
              await _api.delete('marketing/segments/${widget.segment!['id']}');
              if (context.mounted) Navigator.pop(context, true);
            },
            child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
          ),
        const Spacer(),
        SaveButton(
          onPressed: () async {
            if (_name.text.trim().isEmpty) {
              toast(context, tr('Donnez un nom au segment.'), error: true);
              return;
            }
            final body = {'name': _name.text.trim(), 'match': _match, 'conditions': _conds};
            try {
              widget.segment == null ? await _api.post('marketing/segments', body) : await _api.put('marketing/segments/${widget.segment!['id']}', body);
              if (context.mounted) Navigator.pop(context, true);
            } catch (e) {
              if (context.mounted) toast(context, errorText(e), error: true);
            }
          },
          child: Text(tr('Enregistrer')),
        ),
      ]),
    ]);
  }
}
