import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/fx.dart' show currencyNames;
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/payment_terms.dart';
import '../widgets/custom_fields_editor.dart';
import '../widgets/filter_bar.dart';
import 'portal_screen.dart';
import 'fidelite_screen.dart' show LoyaltyBadge;
import 'ged_screen.dart' show LinkedDocuments;
import 'crm_screen.dart' show leadSources, crmUsers;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Prospect : devient client tout seul dès sa première facture (ou ticket de caisse) validée.
const partnerTypes = {'prospect': 'Prospect', 'customer': 'Client', 'supplier': 'Fournisseur', 'both': 'Client & fournisseur'};

/// Suivi des prospects (comme Dolibarr) : passe à « Contact en cours » dès qu'un appel ou un rendez-vous est fait.
const prospectStatuses = {'never': 'Jamais contacté', 'to_contact': 'À contacter', 'in_progress': 'Contact en cours', 'contacted': 'Contacté', 'do_not_contact': 'Ne pas contacter'};
const prospectLevels = {'none': 'Aucun', 'low': 'Faible', 'medium': 'Moyen', 'high': 'Élevé'};

Color prospectStatusColor(String? s) => switch (s) {
      'to_contact' => const Color(0xFFD97706),
      'in_progress' => YColors.blue,
      'contacted' => YColors.success,
      'do_not_contact' => YColors.danger,
      _ => const Color(0xFF64748B),
    };

String _normName(String? v) => (v ?? '').toLowerCase().replaceAll(RegExp(r'[^a-z0-9àâäçéèêëîïôöùûüÿœ]+'), ' ').trim();
String _normPhone(String? v) {
  final d = (v ?? '').replaceAll(RegExp(r'\D'), '');
  return d.length > 8 ? d.substring(d.length - 8) : d;
}

/// Contacts qui ressemblent à celui qu'on enregistre (même nom, e-mail, téléphone ou n° contribuable), comme les
/// alertes de doublons de Dolibarr et d'Odoo. Fonctionne hors ligne (base locale).
List<Map<String, dynamic>> findDuplicatePartners(Map<String, dynamic> d, {String? exceptId}) {
  final name = _normName(d['name'] as String?);
  final email = '${d['email'] ?? ''}'.trim().toLowerCase();
  final phone = _normPhone(d['phone'] as String?);
  final tax = '${d['tax_id'] ?? ''}'.replaceAll(' ', '').toUpperCase();
  return LocalDb.all('partners').where((p) {
    if (p['id'] == exceptId) return false;
    if (name.length >= 3 && _normName(p['name'] as String?) == name) return true;
    if (email.isNotEmpty && '${p['email'] ?? ''}'.trim().toLowerCase() == email) return true;
    if (phone.length >= 8 && _normPhone(p['phone'] as String?) == phone) return true;
    if (tax.length >= 4 && '${p['tax_id'] ?? ''}'.replaceAll(' ', '').toUpperCase() == tax) return true;
    return false;
  }).toList();
}

class PartnersScreen extends StatefulWidget {
  const PartnersScreen({super.key});

  @override
  State<PartnersScreen> createState() => _PartnersScreenState();
}

class _PartnersScreenState extends State<PartnersScreen> {
  String _q = '';
  String _type = '';
  Map<String, String?> _f = {};

  static const _types = {'': 'Tous', 'prospect': 'Prospects', 'customer': 'Clients', 'supplier': 'Fournisseurs'};

  @override
  void initState() {
    super.initState();
    // commerciaux (filtre « Commercial ») : liste mise en cache pour le hors ligne
    crmUsers(context.read<Session>()).then((_) {
      if (mounted) setState(() {});
    }).catchError((_) {});
  }

  @override
  Widget build(BuildContext context) {
    final canEdit = context.watch<Session>().can('core.contacts.manage');
    return Column(children: [
      PageHeader(
        title: tr('Clients & fournisseurs'),
        actions: [
          if (canManagePortal(context.read<Session>()))
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const PortalInboxScreen())),
              icon: const Icon(Icons.forum_outlined),
              label: Text(tr('Portail client')),
            ),
          if (canEdit)
            FilledButton.icon(onPressed: () => editPartner(context, null, _type.isEmpty ? 'customer' : _type), icon: const Icon(Icons.add), label: Text(tr(_type == 'prospect' ? 'Nouveau prospect' : 'Nouveau contact'))),
        ],
      ),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('partners'),
          builder: (context, _, _) {
            // Créances clients : factures validées non soldées
            final owed = <String, double>{};
            for (final d in LocalDb.all('sales_documents')) {
              if ((d['type'] == 'invoice' || d['type'] == 'receipt') && d['status'] == 'validated' && d['partner_id'] != null) {
                final bal = toDouble(d['total']) - toDouble(d['amount_paid']);
                if (bal > 0.5) owed[d['partner_id'] as String] = (owed[d['partner_id']] ?? 0) + bal;
              }
            }
            final all = LocalDb.all('partners');
            final cur = context.read<Session>().currency;
            final hint = Theme.of(context).hintColor;
            Color typeColor(String? t) => t == 'supplier' ? YColors.blue : (t == 'both' ? const Color(0xFF9333EA) : (t == 'prospect' ? const Color(0xFFD97706) : YColors.success));

            final lists = {for (final l in LocalDb.all('pl_lists')) '${l['id']}': '${l['name']}'};
            final base = all.where((p) {
              if (_q.isNotEmpty && !'${p['name']} ${p['email'] ?? ''} ${p['phone'] ?? ''} ${p['code'] ?? ''}'.toLowerCase().contains(_q)) return false;
              final city = _f['city'], bal = _f['balance'], curF = _f['currency'], pl = _f['price_list'], st = _f['state'];
              final ps = _f['prospect_status'], lv = _f['prospect_level'], sp = _f['salesperson'], src = _f['source'];
              if (ps != null && (p['type'] != 'prospect' || '${p['prospect_status'] ?? 'never'}' != ps)) return false;
              if (lv != null && '${p['prospect_level'] ?? ''}' != lv) return false;
              if (sp != null && '${p['salesperson_id'] ?? ''}' != sp) return false;
              if (src != null && '${p['source'] ?? ''}' != src) return false;
              if (city != null && '${p['city'] ?? ''}' != city) return false;
              if (bal != null && (bal == 'owed') != owed.containsKey(p['id'])) return false;
              if (curF != null && '${p['currency'] ?? ''}' != curF) return false;
              if (pl != null && '${p['price_list_id'] ?? ''}' != pl) return false;
              if (st == 'pending' && p['_local'] != true) return false;
              if (st == 'inactive' && p['active'] != false) return false;
              if (st == 'active' && p['active'] == false) return false;
              return true;
            }).toList();
            bool typeOk(Map<String, dynamic> p, String t) => t.isEmpty || p['type'] == t || (t != 'prospect' && p['type'] == 'both');
            final items = base.where((p) => typeOk(p, _type)).toList()
              ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
            final filtering = _q.isNotEmpty || _type.isNotEmpty || _f.values.any((v) => v != null);

            return Column(children: [
              StatStrip(tiles: [
                StatTile(label: tr('Contacts'), value: '${all.length}', icon: Icons.contacts_outlined, color: YColors.blue),
                StatTile(label: tr('Clients'), value: '${all.where((p) => p['type'] == 'customer' || p['type'] == 'both').length}', icon: Icons.storefront_outlined, color: const Color(0xFF4F46E5)),
                StatTile(label: tr('Prospects'), value: '${all.where((p) => p['type'] == 'prospect').length}', detail: tr('client dès la 1re facture'), icon: Icons.person_search_outlined, color: const Color(0xFFD97706)),
                StatTile(label: tr('Fournisseurs'), value: '${all.where((p) => p['type'] == 'supplier' || p['type'] == 'both').length}', icon: Icons.local_shipping_outlined, color: const Color(0xFF0D9488)),
                StatTile(label: tr('À encaisser'), value: money(owed.values.fold<double>(0, (a, b) => a + b), cur), detail: tr('${owed.length} client(s) avec un solde'), icon: Icons.account_balance_wallet_outlined, color: YColors.warning),
              ]),
              FilterBar(
                search: _q,
                searchHint: 'Nom, e-mail ou téléphone…',
                onSearch: (v) => setState(() => _q = v.toLowerCase()),
                statuses: _types,
                status: _type,
                onStatus: (v) => setState(() => _type = v),
                statusCounts: {for (final k in _types.keys) k: base.where((p) => typeOk(p, k)).length},
                filters: [
                  FilterSpec('city', 'Ville', filterOptions(all, 'city'), icon: Icons.location_city_outlined),
                  if (all.any((p) => p['type'] == 'prospect')) const FilterSpec('prospect_status', 'Prospection', prospectStatuses, icon: Icons.person_search_outlined),
                  if (all.any((p) => p['prospect_level'] != null)) const FilterSpec('prospect_level', 'Potentiel', prospectLevels, icon: Icons.trending_up_rounded),
                  if (all.any((p) => p['salesperson_id'] != null))
                    FilterSpec('salesperson', 'Commercial', {for (final p in all) if (p['salesperson_id'] != null) '${p['salesperson_id']}': _userName(p['salesperson_id'])}, icon: Icons.badge_outlined),
                  if (all.any((p) => leadSources.containsKey(p['source']))) FilterSpec('source', 'Origine', {for (final p in all) if (leadSources.containsKey(p['source'])) '${p['source']}': leadSources[p['source']]!}, icon: Icons.campaign_outlined),
                  const FilterSpec('balance', 'Solde', {'owed': 'Avec solde dû', 'clear': 'Sans solde'}, icon: Icons.account_balance_wallet_outlined),
                  FilterSpec('currency', 'Devise', filterOptions(all, 'currency', label: (p) => currencyNames[p['currency']] ?? '${p['currency']}'), icon: Icons.currency_exchange),
                  if (lists.isNotEmpty) FilterSpec('price_list', 'Liste de prix', filterOptions(all.where((p) => lists.containsKey('${p['price_list_id']}')), 'price_list_id', label: (p) => lists['${p['price_list_id']}']!), icon: Icons.price_change_outlined),
                  const FilterSpec('state', 'État', {'active': 'Actifs', 'inactive': 'Archivés', 'pending': 'Non synchronisés'}, icon: Icons.toggle_on_outlined),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                count: items.length,
                countLabel: 'contact',
                onReset: () => setState(() => (_q = '', _type = '', _f = {})),
              ),
              Expanded(child: items.isEmpty
                  ? EmptyState(
                      icon: Icons.people_outline,
                      title: !filtering ? 'Aucun contact pour l\'instant' : 'Aucun résultat',
                      message: !filtering ? 'Ajoutez vos clients et fournisseurs, même hors ligne.' : null,
                    )
                  : ResponsiveList<Map<String, dynamic>>(
              items: items,
              columns: const ['Nom', 'Type', 'Téléphone', 'Ville', 'Solde dû'],
              onTap: canEdit ? (p) => editPartner(context, p) : null,
              cells: (p) => [
                Row(children: [
                  InitialsAvatar('${p['name']}', size: 34),
                  const SizedBox(width: 12),
                  Flexible(
                    child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(mainAxisSize: MainAxisSize.min, children: [Flexible(child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600))), if (p['_local'] == true) const _PendingDot()]),
                      if ((p['email'] ?? '').toString().isNotEmpty) Text(tr('${p['email']}'), overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12)),
                    ]),
                  ),
                ]),
                p['type'] == 'prospect'
                    ? StatusChip(prospectStatuses[p['prospect_status'] ?? 'never'] ?? 'Prospect', color: prospectStatusColor(p['prospect_status'] as String? ?? 'never'))
                    : StatusChip(partnerTypes[p['type']] ?? 'Client', color: typeColor(p['type'] as String?)),
                Text(tr('${p['phone'] ?? ''}'), style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()])),
                Text(tr('${p['city'] ?? ''}')),
                Text(tr(owed[p['id']] == null ? '—' : money(owed[p['id']], cur)), style: TextStyle(fontWeight: owed[p['id']] == null ? null : FontWeight.w600, color: owed[p['id']] == null ? hint : null, fontFeatures: const [FontFeature.tabularFigures()])),
              ],
              mobileTile: (p) => Row(children: [
                InitialsAvatar('${p['name']}', size: 40),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(tr([partnerTypes[p['type']], if (p['type'] == 'prospect') prospectStatuses[p['prospect_status'] ?? 'never'], p['phone']].where((e) => e != null && '$e'.isNotEmpty).join(' · ')),
                        style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                    CustomFieldsView(entity: 'partners', values: p['custom'] as Map<String, dynamic>?, listOnly: true),
                  ]),
                ),
                if (p['_local'] == true) const _PendingDot(),
              ]),
            )),
            ]);
          },
        ),
      ),
    ]);
  }
}

String _userName(dynamic id) {
  for (final u in (LocalDb.state().get('crm_users') as List? ?? const [])) {
    final m = asMap(u);
    if ('${m['id']}' == '$id') return '${m['name']}';
  }
  return 'Utilisateur $id';
}

class _PendingDot extends StatelessWidget {
  const _PendingDot();

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(left: 6),
        child: Tooltip(message: tr('En attente de synchronisation'), child: Icon(Icons.cloud_upload_outlined, size: 16, color: YColors.warning)),
      );
}

Future<Map<String, dynamic>?> editPartner(BuildContext context, [Map<String, dynamic>? partner, String defaultType = 'customer']) {
  return showFormDialog<Map<String, dynamic>>(context, title: partner == null ? 'Nouveau contact' : 'Modifier le contact', builder: (c) => _PartnerForm(partner: partner, defaultType: defaultType));
}

class _PartnerForm extends StatefulWidget {
  const _PartnerForm({this.partner, required this.defaultType});

  final Map<String, dynamic>? partner;
  final String defaultType;

  @override
  State<_PartnerForm> createState() => _PartnerFormState();
}

class _PartnerFormState extends State<_PartnerForm> {
  final _key = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _c;
  late String _type;
  String? _currency;
  String? _priceList;
  bool _paused = false;
  String? _term;
  String? _supplierTerm;
  late final Map<String, dynamic> _custom = asMap(widget.partner?['custom']);
  bool _isCompany = true;
  String? _prospectStatus;
  String? _prospectLevel;
  String? _source;
  int? _salesperson;
  List<Map<String, dynamic>> _users = [];
  final List<Map<String, TextEditingController>> _people = [];

  @override
  void initState() {
    super.initState();
    final p = widget.partner ?? {};
    _type = (p['type'] as String?) ?? widget.defaultType;
    _currency = p['currency'] as String?;
    _priceList = p['price_list_id'] as String?;
    _paused = p['dunning_paused'] == true;
    _term = p['payment_term_id'] as String?;
    _supplierTerm = p['supplier_payment_term_id'] as String?;
    _c = {for (final f in ['name', 'phone', 'email', 'address', 'city', 'tax_id', 'website', 'notes']) f: TextEditingController(text: '${p[f] ?? ''}')};
    _isCompany = p['is_company'] != false;
    _prospectStatus = p['prospect_status'] as String?;
    _prospectLevel = p['prospect_level'] as String?;
    _source = leadSources.containsKey(p['source']) ? p['source'] as String : null;
    _salesperson = (p['salesperson_id'] as num?)?.toInt() ?? (widget.partner == null && widget.defaultType != 'supplier' ? (context.read<Session>().user['id'] as num?)?.toInt() : null);
    for (final c in (p['contacts'] as List? ?? const [])) {
      _addPerson(asMap(c));
    }
    crmUsers(context.read<Session>()).then((u) {
      if (mounted) setState(() => _users = u);
    }).catchError((_) {});
  }

  void _addPerson([Map<String, dynamic> c = const {}]) =>
      _people.add({for (final f in ['name', 'role', 'phone', 'email']) f: TextEditingController(text: '${c[f] ?? ''}')});

  @override
  void dispose() {
    for (final c in [..._c.values, for (final p in _people) ...p.values]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (!_key.currentState!.validate()) return;
    final champs = context.read<Session>().hasModule('champs');
    final err = champs ? customRequiredError('partners', _custom) : null;
    if (err != null) return toast(context, err, error: true);
    final data = <String, dynamic>{
      if (widget.partner != null) 'id': widget.partner!['id'],
      'type': _type,
      'is_company': _isCompany,
      'prospect_status': _type == 'prospect' ? (_prospectStatus ?? 'never') : _prospectStatus,
      'prospect_level': _type == 'supplier' ? null : _prospectLevel,
      'source': _source,
      'salesperson_id': _type == 'supplier' ? null : _salesperson,
      'contacts': [
        for (final p in _people)
          if (p['name']!.text.trim().isNotEmpty) {for (final e in p.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim()},
      ],
      if (champs) 'custom': _custom,
      for (final e in _c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(),
      if (context.read<Session>().hasModule('devises')) 'currency': _currency,
      if (context.read<Session>().hasModule('relances')) 'dunning_paused': _paused,
      if (context.read<Session>().hasModule('tarifs')) 'price_list_id': _type == 'supplier' ? null : _priceList,
      if (ptAvailable()) 'payment_term_id': _type == 'supplier' ? null : _term,
      if (ptAvailable()) 'supplier_payment_term_id': (_type == 'supplier' || _type == 'both') ? _supplierTerm : null,
    };
    // Doublons (comme Dolibarr et Odoo) : avant de créer, ou si le nom, l'e-mail ou le téléphone change
    final before = widget.partner ?? const {};
    final changed = widget.partner == null || ['name', 'email', 'phone', 'tax_id'].any((f) => '${before[f] ?? ''}' != '${data[f] ?? ''}');
    if (changed) {
      final dups = findDuplicatePartners(data, exceptId: widget.partner?['id'] as String?);
      if (dups.isNotEmpty) {
        final pick = await _askDuplicate(dups);
        if (!mounted || pick == null) return;
        if (pick.isNotEmpty) {
          Navigator.pop(context, LocalDb.get('partners', pick));
          return;
        }
      }
    }
    if (!mounted) return;
    final saved = await context.read<SyncEngine>().save('partners', data);
    if (mounted) Navigator.pop(context, saved);
  }

  /// null = annuler, '' = créer quand même, sinon l'id de la fiche existante à garder.
  Future<String?> _askDuplicate(List<Map<String, dynamic>> dups) => showDialog<String>(
        context: context,
        builder: (dc) => AlertDialog(
          title: Text(tr(dups.length == 1 ? 'Ce contact existe peut-être déjà' : '${dups.length} contacts ressemblent à celui-ci')),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              for (final d in dups.take(5))
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: InitialsAvatar('${d['name']}', size: 34),
                  title: Text(tr('${d['name']}')),
                  subtitle: Text(tr([partnerTypes[d['type']], d['code'], d['phone'], d['email']].where((e) => e != null && '$e'.isNotEmpty).join(' · '))),
                  trailing: widget.partner == null ? TextButton(onPressed: () => Navigator.pop(dc, '${d['id']}'), child: Text(tr('Utiliser'))) : null,
                ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Revenir à la fiche'))),
            FilledButton.tonal(onPressed: () => Navigator.pop(dc, ''), child: Text(tr(widget.partner == null ? 'Créer quand même' : 'Enregistrer quand même'))),
          ],
        ),
      );

  Widget _personRow(int i) {
    final p = _people[i];
    final narrow = MediaQuery.sizeOf(context).width < 600;
    Widget f(String k, String label, {TextInputType? kb}) => TextField(controller: p[k], keyboardType: kb, decoration: InputDecoration(isDense: true, labelText: tr(label)));
    final fields = [f('name', 'Nom'), f('role', 'Fonction'), f('phone', 'Téléphone', kb: TextInputType.phone), f('email', 'E-mail', kb: TextInputType.emailAddress)];
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(
          child: narrow
              ? Column(children: [
                  Row(children: [Expanded(child: fields[0]), const SizedBox(width: 8), Expanded(child: fields[1])]),
                  const SizedBox(height: 8),
                  Row(children: [Expanded(child: fields[2]), const SizedBox(width: 8), Expanded(child: fields[3])]),
                ])
              : Row(children: [for (var j = 0; j < 4; j++) ...[if (j > 0) const SizedBox(width: 8), Expanded(child: fields[j])]]),
        ),
        IconButton(
          tooltip: tr('Retirer'),
          onPressed: () => setState(() {
            for (final c in _people.removeAt(i).values) {
              c.dispose();
            }
          }),
          icon: const Icon(Icons.close_rounded, size: 20),
        ),
      ]),
    );
  }

  Future<void> _delete() async {
    if (!await confirm(context, tr('Supprimer ce contact ?'), tr('Cette action est synchronisée sur tous les appareils.'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    await context.read<SyncEngine>().remove('partners', widget.partner!['id']);
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _key,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
        Field(
          label: tr('Type'),
          child: DropdownButtonFormField<String>(
            initialValue: _type,
            items: [for (final e in partnerTypes.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
            onChanged: (v) => setState(() => _type = v!),
          ),
        ),
        if (_type == 'prospect')
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Row(children: [
              const Icon(Icons.auto_awesome_outlined, size: 16, color: Color(0xFFD97706)),
              const SizedBox(width: 6),
              Expanded(child: Text(tr('Devient client automatiquement dès sa première facture validée.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))),
            ]),
          ),
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Row(children: [
            SegmentedButton<bool>(
              segments: [
                ButtonSegment(value: true, icon: const Icon(Icons.apartment_rounded, size: 18), label: Text(tr('Entreprise'))),
                ButtonSegment(value: false, icon: const Icon(Icons.person_outline_rounded, size: 18), label: Text(tr('Particulier'))),
              ],
              selected: {_isCompany},
              showSelectedIcon: false,
              onSelectionChanged: (v) => setState(() => _isCompany = v.first),
            ),
            const Spacer(),
            if ('${widget.partner?['code'] ?? ''}'.isNotEmpty)
              Text(tr('Code ${widget.partner!['code']}'), style: TextStyle(color: Theme.of(context).hintColor, fontWeight: FontWeight.w600, fontSize: 12.5))
            else if (widget.partner == null)
              Text(tr('Code attribué automatiquement'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
          ]),
        ),
        Field(
          label: tr(_isCompany ? 'Raison sociale' : 'Nom et prénom'),
          child: TextFormField(controller: _c['name'], autofocus: widget.partner == null, validator: (v) => (v ?? '').trim().isEmpty ? 'Obligatoire' : null),
        ),
        Row(children: [
          Expanded(child: Field(label: tr('Téléphone'), child: TextFormField(controller: _c['phone'], keyboardType: TextInputType.phone))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('E-mail'), child: TextFormField(controller: _c['email'], keyboardType: TextInputType.emailAddress))),
        ]),
        Field(label: tr('Adresse'), child: TextFormField(controller: _c['address'])),
        Row(children: [
          Expanded(child: Field(label: tr('Ville'), child: TextFormField(controller: _c['city']))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('N° contribuable (NCC)'), child: TextFormField(controller: _c['tax_id']))),
        ]),
        if (_isCompany) ...[
          Field(label: tr('Site web'), child: TextFormField(controller: _c['website'], keyboardType: TextInputType.url, decoration: const InputDecoration(hintText: 'www.exemple.ci'))),
          Padding(
            padding: const EdgeInsets.only(top: 4, bottom: 8),
            child: Row(children: [
              Expanded(child: Text(tr('Interlocuteurs'), style: const TextStyle(fontWeight: FontWeight.w600))),
              TextButton.icon(onPressed: () => setState(() => _addPerson()), icon: const Icon(Icons.person_add_alt_1_outlined, size: 18), label: Text(tr('Ajouter'))),
            ]),
          ),
          if (_people.isEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(tr('Les personnes à joindre dans l\'entreprise : gérant, comptable, acheteur…'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
            ),
          for (var i = 0; i < _people.length; i++) _personRow(i),
        ],
        if (_type != 'supplier') ...[
          if (_type == 'prospect')
            Row(children: [
              Expanded(
                child: Field(
                  label: tr('Prospection'),
                  child: DropdownButtonFormField<String>(
                    initialValue: _prospectStatus ?? 'never',
                    isExpanded: true,
                    items: [for (final e in prospectStatuses.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value), overflow: TextOverflow.ellipsis))],
                    onChanged: (v) => setState(() => _prospectStatus = v),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Field(
                  label: tr('Potentiel'),
                  child: DropdownButtonFormField<String?>(
                    initialValue: _prospectLevel,
                    isExpanded: true,
                    items: [DropdownMenuItem(value: null, child: Text(tr('Non évalué'))), for (final e in prospectLevels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                    onChanged: (v) => setState(() => _prospectLevel = v),
                  ),
                ),
              ),
            ]),
          Row(children: [
            Expanded(
              child: Field(
                label: tr('Origine'),
                child: DropdownButtonFormField<String?>(
                  initialValue: _source,
                  isExpanded: true,
                  items: [DropdownMenuItem(value: null, child: Text(tr('Non précisée'))), for (final e in leadSources.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value), overflow: TextOverflow.ellipsis))],
                  onChanged: (v) => setState(() => _source = v),
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Commercial'),
                child: DropdownButtonFormField<int?>(
                  initialValue: _users.any((u) => u['id'] == _salesperson) ? _salesperson : null,
                  isExpanded: true,
                  items: [DropdownMenuItem(value: null, child: Text(tr('Personne'))), for (final u in _users) DropdownMenuItem(value: (u['id'] as num).toInt(), child: Text(tr('${u['name']}'), overflow: TextOverflow.ellipsis))],
                  onChanged: (v) => setState(() => _salesperson = v),
                ),
              ),
            ),
          ]),
        ],
        if (context.read<Session>().hasModule('devises'))
          Field(
            label: tr('Devise de facturation'),
            child: DropdownButtonFormField<String?>(
              initialValue: _currency,
              items: [
                DropdownMenuItem(value: null, child: Text(tr('Devise de la société (${context.read<Session>().currency})'))),
                for (final e in currencyNames.entries)
                  if (e.key != context.read<Session>().currency) DropdownMenuItem(value: e.key, child: Text(tr('${e.key} — ${e.value}'))),
              ],
              onChanged: (v) => setState(() => _currency = v),
            ),
          ),
        if (context.read<Session>().hasModule('tarifs') && _type != 'supplier' && LocalDb.hasEntity('pl_lists'))
          Field(
            label: tr('Liste de prix'),
            child: DropdownButtonFormField<String?>(
              initialValue: LocalDb.get('pl_lists', _priceList) != null ? _priceList : null,
              isExpanded: true,
              items: [
                DropdownMenuItem(value: null, child: Text(tr('Liste par défaut${LocalDb.all('pl_lists').where((l) => l['is_default'] == true).map((l) => ' (${l['name']})').firstOrNull ?? ' (prix catalogue)'}'))),
                for (final l in LocalDb.all('pl_lists').where((l) => l['active'] != false)) DropdownMenuItem(value: '${l['id']}', child: Text(tr('${l['name']}'))),
              ],
              onChanged: (v) => setState(() => _priceList = v),
            ),
          ),
        if (ptAvailable() && _type != 'supplier')
          Field(label: _type == 'both' ? 'Conditions de paiement (client)' : 'Conditions de paiement', child: PaymentTermDropdown(value: _term, noneLabel: 'Condition par défaut des ventes', onChanged: (v) => setState(() => _term = v))),
        if (ptAvailable() && (_type == 'supplier' || _type == 'both'))
          Field(label: _type == 'both' ? 'Conditions de paiement (fournisseur)' : 'Conditions de paiement', child: PaymentTermDropdown(value: _supplierTerm, noneLabel: 'Condition par défaut des achats', onChanged: (v) => setState(() => _supplierTerm = v))),
        if (context.read<Session>().hasModule('relances') && _type != 'supplier')
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('Suspendre les relances automatiques')),
            subtitle: Text(tr('Client en négociation, échéancier accordé…')),
            value: _paused,
            onChanged: (v) => setState(() => _paused = v),
          ),
        if (context.read<Session>().hasModule('champs')) CustomFieldsEditor(entity: 'partners', values: _custom, creating: widget.partner == null),
        Field(label: tr('Notes'), child: TextFormField(controller: _c['notes'], maxLines: 3)),
        if (widget.partner != null && widget.partner!['type'] != 'supplier') Align(alignment: Alignment.centerLeft, child: LoyaltyBadge(partnerId: '${widget.partner!['id']}')),
        if (widget.partner != null && widget.partner!['_local'] != true) LinkedDocuments(field: 'partner_id', id: '${widget.partner!['id']}'),
        const SizedBox(height: 8),
        Row(children: [
          if (widget.partner != null && widget.partner!['type'] != 'supplier' && widget.partner!['_local'] != true && canManagePortal(context.read<Session>()))
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: OutlinedButton.icon(
                onPressed: () => openPortalPartner(context, '${widget.partner!['id']}'),
                icon: const Icon(Icons.web_outlined),
                label: Text(tr('Espace client')),
              ),
            ),
          if (widget.partner != null) TextButton.icon(onPressed: _delete, icon: const Icon(Icons.delete_outline, color: YColors.danger), label: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
          const Spacer(),
          SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
        ]),
      ]),
    );
  }
}
