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
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Prospect : devient client tout seul dès sa première facture (ou ticket de caisse) validée.
const partnerTypes = {'prospect': 'Prospect', 'customer': 'Client', 'supplier': 'Fournisseur', 'both': 'Client & fournisseur'};

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
            FilledButton.icon(onPressed: () => editPartner(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau contact'))),
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
            Color typeColor(String? t) => t == 'supplier' ? const Color(0xFF0D9488) : (t == 'both' ? const Color(0xFF9333EA) : (t == 'prospect' ? const Color(0xFFD97706) : YColors.blue));

            final lists = {for (final l in LocalDb.all('pl_lists')) '${l['id']}': '${l['name']}'};
            final base = all.where((p) {
              if (_q.isNotEmpty && !'${p['name']} ${p['email'] ?? ''} ${p['phone'] ?? ''}'.toLowerCase().contains(_q)) return false;
              final city = _f['city'], bal = _f['balance'], curF = _f['currency'], pl = _f['price_list'], st = _f['state'];
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
                StatusChip(partnerTypes[p['type']] ?? 'Client', color: typeColor(p['type'] as String?)),
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
                    Text(tr([partnerTypes[p['type']], p['phone']].where((e) => e != null && '$e'.isNotEmpty).join(' · ')),
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
    _c = {for (final f in ['name', 'phone', 'email', 'address', 'city', 'tax_id', 'notes']) f: TextEditingController(text: '${p[f] ?? ''}')};
  }

  Future<void> _save() async {
    if (!_key.currentState!.validate()) return;
    final champs = context.read<Session>().hasModule('champs');
    final err = champs ? customRequiredError('partners', _custom) : null;
    if (err != null) return toast(context, err, error: true);
    final data = <String, dynamic>{
      if (widget.partner != null) 'id': widget.partner!['id'],
      'type': _type,
      if (champs) 'custom': _custom,
      for (final e in _c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(),
      if (context.read<Session>().hasModule('devises')) 'currency': _currency,
      if (context.read<Session>().hasModule('relances')) 'dunning_paused': _paused,
      if (context.read<Session>().hasModule('tarifs')) 'price_list_id': _type == 'supplier' ? null : _priceList,
      if (ptAvailable()) 'payment_term_id': _type == 'supplier' ? null : _term,
      if (ptAvailable()) 'supplier_payment_term_id': (_type == 'supplier' || _type == 'both') ? _supplierTerm : null,
    };
    final saved = await context.read<SyncEngine>().save('partners', data);
    if (mounted) Navigator.pop(context, saved);
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
        Field(label: tr('Nom ou raison sociale'), child: TextFormField(controller: _c['name'], autofocus: true, validator: (v) => (v ?? '').trim().isEmpty ? 'Obligatoire' : null)),
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
          FilledButton(onPressed: _save, child: Text(tr('Enregistrer'))),
        ]),
      ]),
    );
  }
}
