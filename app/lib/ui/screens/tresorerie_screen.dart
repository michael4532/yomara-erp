import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'document_editor.dart' show paymentMethods;
import 'bank_sync_tab.dart';
import 'momo_tab.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const trKinds = {'bank': 'Banque', 'cash': 'Caisse', 'mobile_money': 'Mobile Money'};
const trKindIcons = {'bank': Icons.account_balance, 'cash': Icons.point_of_sale, 'mobile_money': Icons.phone_android};

/// Catégories (identiques au serveur) : libellé, sens habituel.
const trCategories = {
  'bank_fees': ('Frais bancaires et agios', -1),
  'rent': ('Loyer et charges', -1),
  'utilities': ('Eau, électricité, carburant', -1),
  'telecom': ('Téléphone et internet', -1),
  'supplies': ('Fournitures et petits achats', -1),
  'transport': ('Transport et déplacements', -1),
  'maintenance': ('Entretien et réparations', -1),
  'fees': ('Honoraires', -1),
  'salaries': ('Salaires versés', -1),
  'social': ('Cotisations sociales (CNPS)', -1),
  'taxes': ('Impôts et taxes', -1),
  'loan': ('Emprunt (reçu ou remboursé)', 1),
  'interest': ('Intérêts d\'emprunt', -1),
  'owner': ('Apport ou retrait de l\'exploitant', 1),
  'other_income': ('Autre recette', 1),
  'other_expense': ('Autre dépense', -1),
  'expense_report': ('Remboursement de notes de frais', -1),
  'transfer': ('Virement entre comptes', 0),
};

bool trActive(Session s) => s.hasModule('tresorerie') && LocalDb.hasEntity('tr_accounts');

List<Map<String, dynamic>> trAccounts({bool all = false}) =>
    LocalDb.all('tr_accounts').where((a) => all || a['active'] != false).toList()..sort((a, b) => '${(a['sort'] ?? 0).toString().padLeft(4, '0')}${a['name']}'.compareTo('${(b['sort'] ?? 0).toString().padLeft(4, '0')}${b['name']}'));

/// Compte par défaut de chaque moyen de paiement (même règle que le serveur).
Map<String, String> trDefaults() {
  final map = <String, String>{};
  for (final a in trAccounts()) {
    for (final m in (a['methods'] as List? ?? [])) {
      map.putIfAbsent('$m', () => '${a['id']}');
    }
  }
  return map;
}

String? trAccountOfPayment(Map<String, dynamic> p, Map<String, String> defaults) => (p['treasury_account_id'] as String?) ?? defaults['${p['method']}'];

/// Mouvements d'un compte (calculés sur l'appareil, donc disponibles hors ligne) : date, libellé, montant signé.
List<Map<String, dynamic>> trMovements(Map<String, dynamic> acc) {
  final id = '${acc['id']}';
  final from = '${acc['opening_date'] ?? ''}';
  final defaults = trDefaults();
  final out = <Map<String, dynamic>>[];
  for (final (entity, sign, docEntity, word) in [('sales_payments', 1, 'sales_documents', 'Encaissement'), ('purchase_payments', -1, 'purchase_documents', 'Règlement')]) {
    if (!LocalDb.hasEntity(entity)) continue;
    for (final p in LocalDb.all(entity)) {
      if (trAccountOfPayment(p, defaults) != id || '${p['date']}'.compareTo(from) < 0) continue;
      final d = LocalDb.hasEntity(docEntity) ? LocalDb.get(docEntity, p['document_id'] as String?) : null;
      out.add({
        'kind': 'payment',
        'id': p['id'],
        'date': '${p['date']}',
        'amount': sign * toDouble(p['amount']),
        'label': '$word ${d?['number'] ?? d?['reference'] ?? ''} ${d?['partner_name'] ?? ''}'.trim(),
        'detail': paymentMethods[p['method']] ?? '${p['method']}',
      });
    }
  }
  for (final o in LocalDb.all('tr_operations').where((o) => o['account_id'] == id && '${o['date']}'.compareTo(from) >= 0)) {
    out.add({'kind': 'operation', 'id': o['id'], 'date': '${o['date']}', 'amount': toDouble(o['amount']), 'label': '${o['label']}', 'detail': trCategories[o['category']]?.$1 ?? '${o['category']}', 'op': o});
  }
  out.sort((a, b) => '${a['date']}${a['id']}'.compareTo('${b['date']}${b['id']}'));
  return out;
}

double trBalance(Map<String, dynamic> acc) => toDouble(acc['opening_balance']) + trMovements(acc).fold<double>(0, (t, m) => t + toDouble(m['amount']));

// ======================================================================
// Écran principal
// ======================================================================

class TresorerieScreen extends StatefulWidget {
  const TresorerieScreen({super.key});

  @override
  State<TresorerieScreen> createState() => _TresorerieScreenState();
}

class _TresorerieScreenState extends State<TresorerieScreen> {
  String? _account;

  @override
  void initState() {
    super.initState();
    // Premier passage : le serveur crée les comptes par défaut, la synchronisation les apporte.
    if (LocalDb.all('tr_accounts').isEmpty) {
      final s = context.read<Session>();
      final sync = context.read<SyncEngine>();
      s.api.get('tresorerie/summary').then((_) => sync.sync()).catchError((_) {});
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('tresorerie.manage');
    final operate = manage || s.can('tresorerie.operate');
    final tabs = [
      ('Comptes', _AccountsTab(onOpen: (id) => setState(() => _account = id))),
      ('Mouvements', _MovementsTab(account: _account, onAccount: (id) => setState(() => _account = id))),
      if (manage) ('Rapprochement bancaire', _ReconcileTab(account: _account, onAccount: (id) => setState(() => _account = id))),
      if (manage) ('Synchronisation bancaire', const BankSyncTab()),
      if (manage) ('Mobile Money', const MomoTab()),
      ('Prévisions', const _ForecastTab()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Builder(builder: (context) {
        return Column(children: [
          PageHeader(
            title: tr('Banque & trésorerie'),
            subtitle: tr('Soldes, dépenses et recettes, rapprochement des relevés, prévisions'),
            actions: [
              if (operate) ...[
                OutlinedButton.icon(onPressed: () => editOperation(context, sign: 1), icon: const Icon(Icons.add), label: Text(tr('Recette'))),
                OutlinedButton.icon(onPressed: () => editOperation(context, sign: -1), icon: const Icon(Icons.remove), label: Text(tr('Dépense'))),
                FilledButton.icon(onPressed: () => newTransfer(context), icon: const Icon(Icons.swap_horiz), label: Text(tr('Virement'))),
              ],
            ],
          ),
          TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
          Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
        ]);
      }),
    );
  }
}

// ======================================================================
// Comptes
// ======================================================================

class _AccountsTab extends StatefulWidget {
  const _AccountsTab({required this.onOpen});

  final ValueChanged<String> onOpen;

  @override
  State<_AccountsTab> createState() => _AccountsTabState();
}

class _AccountsTabState extends State<_AccountsTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  ValueChanged<String> get onOpen => widget.onOpen;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('tr_accounts'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('tr_operations'),
        builder: (context, _, _) {
          final all = trAccounts(all: true);
          final active = all.where((a) => a['active'] != false).toList();
          final total = active.fold<double>(0, (t, a) => t + trBalance(a));
          final narrow = MediaQuery.sizeOf(context).width < 600;
          final cardW = narrow ? double.infinity : 320.0;
          bool base(Map<String, dynamic> a) =>
              (_q.isEmpty || '${a['name']} ${a['bank_name'] ?? ''} ${a['number'] ?? ''}'.toLowerCase().contains(_q)) &&
              (_f['kind'] == null || a['kind'] == _f['kind']) &&
              (_f['method'] == null || ((a['methods'] as List?) ?? []).map((m) => '$m').contains(_f['method']));
          bool st(Map<String, dynamic> a, String k) => k.isEmpty || (k == 'active') == (a['active'] != false);
          final accounts = all.where((a) => base(a) && st(a, _status)).toList();
          final methods = <String, String>{
            for (final a in all)
              for (final m in ((a['methods'] as List?) ?? [])) '$m': paymentMethods[m] ?? '$m',
          };
          return ListView(padding: const EdgeInsets.all(16), children: [
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(gradient: const LinearGradient(colors: [YColors.night, Color(0xFF1F3A70)]), borderRadius: BorderRadius.circular(16)),
              child: Row(children: [
                const Icon(Icons.savings, color: YColors.yellow, size: 34),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('Trésorerie disponible'), style: TextStyle(color: Colors.white70)),
                    FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(tr(money(total, cur)), style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800))),
                  ]),
                ),
                if (!narrow) Text(tr('${active.length} compte(s)'), style: const TextStyle(color: Colors.white70)),
              ]),
            ),
            const SizedBox(height: 12),
            FilterBar(
              padding: EdgeInsets.zero,
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              search: _q,
              searchHint: 'Nom, banque, numéro…',
              statuses: const {'': 'Tous', 'active': 'Actifs', 'inactive': 'Inactifs'},
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in ['', 'active', 'inactive']) k: all.where((a) => base(a) && st(a, k)).length},
              filters: [
                FilterSpec('kind', 'Type', trKinds, icon: Icons.account_balance_wallet_outlined),
                FilterSpec('method', 'Moyen de paiement', methods, icon: Icons.payments_outlined),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: accounts.length,
              countLabel: 'compte',
              onReset: () => setState(() => (_q = '', _status = '', _f = {})),
            ),
            const SizedBox(height: 12),
            Wrap(spacing: 12, runSpacing: 12, children: [
              for (final a in accounts)
                SizedBox(
                  width: cardW,
                  child: Builder(builder: (context) {
                    final bal = trBalance(a);
                    final low = a['low_balance'] != null && bal < toDouble(a['low_balance']);
                    return Card(
                      child: InkWell(
                        borderRadius: BorderRadius.circular(12),
                        onTap: () {
                          onOpen('${a['id']}');
                          DefaultTabController.of(context).animateTo(1);
                        },
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Row(children: [
                              Icon(trKindIcons[a['kind']], color: a['active'] == false ? YColors.muted : YColors.blue),
                              const SizedBox(width: 8),
                              Expanded(child: Text(tr('${a['name']}'), style: TextStyle(fontWeight: FontWeight.w700, decoration: a['active'] == false ? TextDecoration.lineThrough : null))),
                              if (s.can('tresorerie.manage')) IconButton(visualDensity: VisualDensity.compact, onPressed: () => editAccount(context, a), icon: const Icon(Icons.edit_outlined, size: 18)),
                            ]),
                            Text(tr([trKinds[a['kind']], a['bank_name'], a['number']].where((e) => e != null && '$e'.isNotEmpty).join(' · ')), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                            const SizedBox(height: 10),
                            Text(tr(money(bal, cur)), style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: bal < 0 || low ? YColors.danger : null)),
                            if (low) Text(tr('Sous le seuil d\'alerte'), style: TextStyle(color: YColors.danger, fontSize: 12)),
                            const SizedBox(height: 4),
                            Text(tr('Reçoit : ${((a['methods'] as List?) ?? []).map((m) => paymentMethods[m] ?? m).join(', ').ifEmpty('—')}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                          ]),
                        ),
                      ),
                    );
                  }),
                ),
              if (s.can('tresorerie.manage'))
                SizedBox(
                  width: cardW,
                  height: 56,
                  child: OutlinedButton.icon(onPressed: () => editAccount(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau compte'))),
                ),
            ]),
            const SizedBox(height: 12),
            Text(tr('Les soldes se calculent sur l\'appareil, même hors ligne : solde d\'ouverture + encaissements − règlements ± opérations diverses.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          ]);
        },
      ),
    );
  }
}

extension on String {
  String ifEmpty(String other) => isEmpty ? other : this;
}

Future<void> editAccount(BuildContext context, [Map<String, dynamic>? a]) async {
  final sync = context.read<SyncEngine>();
  final name = TextEditingController(text: '${a?['name'] ?? ''}');
  final bank = TextEditingController(text: '${a?['bank_name'] ?? ''}');
  final number = TextEditingController(text: '${a?['number'] ?? ''}');
  final opening = TextEditingController(text: a == null ? '0' : '${toDouble(a['opening_balance']).round()}');
  final low = TextEditingController(text: a?['low_balance'] == null ? '' : '${toDouble(a!['low_balance']).round()}');
  final gl = TextEditingController(text: '${a?['gl_code'] ?? ''}');
  var kind = '${a?['kind'] ?? 'bank'}';
  var date = DateTime.tryParse('${a?['opening_date'] ?? ''}') ?? DateTime(DateTime.now().year, 1, 1);
  final methods = <String>{...((a?['methods'] as List?) ?? (kind == 'bank' ? ['bank_transfer', 'card', 'cheque'] : [])).map((e) => '$e')};
  var active = a?['active'] != false;
  final ok = await showFormDialog<bool>(context, title: a == null ? 'Nouveau compte' : '${a['name']}', builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (a == null)
              SegmentedButton<String>(
                segments: [for (final e in trKinds.entries) ButtonSegment(value: e.key, label: Text(tr(e.value)), icon: Icon(trKindIcons[e.key]))],
                selected: {kind},
                onSelectionChanged: (v) => set(() {
                  kind = v.first;
                  methods
                    ..clear()
                    ..addAll(switch (kind) { 'cash' => ['cash'], 'mobile_money' => ['mobile_money'], _ => ['bank_transfer', 'card', 'cheque'] });
                }),
              ),
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'), hintText: trn(kind == 'bank' ? 'SGCI Plateau' : (kind == 'cash' ? 'Caisse boutique' : 'Orange Money marchand')))),
            if (kind != 'cash') TextField(controller: bank, decoration: InputDecoration(labelText: trn(kind == 'bank' ? 'Banque' : 'Opérateur (Orange, MTN, Moov, Wave)'))),
            if (kind != 'cash') TextField(controller: number, decoration: InputDecoration(labelText: trn(kind == 'bank' ? 'N° de compte / RIB' : 'N° marchand'))),
            Row(children: [
              Expanded(child: TextField(controller: opening, keyboardType: const TextInputType.numberWithOptions(signed: true), decoration: InputDecoration(labelText: tr('Solde d\'ouverture')))),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton(
                  onPressed: () async {
                    final d = await pickDate(c, initialDate: date, firstDate: DateTime(2015), lastDate: DateTime(2100));
                    if (d != null) set(() => date = d);
                  },
                  child: Text(tr('au ${dateFr(isoDate(date))}')),
                ),
              ),
            ]),
            TextField(controller: low, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Seuil d\'alerte (facultatif)'))),
            const SizedBox(height: 10),
            Text(tr('Moyens de paiement encaissés ici par défaut'), style: TextStyle(fontWeight: FontWeight.w600)),
            Wrap(spacing: 6, children: [
              for (final m in ['cash', 'mobile_money', 'card', 'bank_transfer', 'cheque', 'other'])
                FilterChip(label: Text(tr(paymentMethods[m] ?? m)), selected: methods.contains(m), onSelected: (v) => set(() => v ? methods.add(m) : methods.remove(m))),
            ]),
            TextField(controller: gl, decoration: InputDecoration(labelText: tr('Compte comptable (facultatif)'), helperText: tr('Vide : compte du plan (521 banque, 571 caisse, 5215 Mobile Money)'))),
            if (a != null) SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Compte actif')), value: active, onChanged: (v) => set(() => active = v)),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true || name.text.trim().isEmpty) return;
  try {
    await sync.save('tr_accounts', {
      'id': a?['id'] ?? newId(),
      'name': name.text.trim(),
      'kind': kind,
      'bank_name': bank.text.trim().isEmpty ? null : bank.text.trim(),
      'number': number.text.trim().isEmpty ? null : number.text.trim(),
      'opening_balance': toDouble(opening.text),
      'opening_date': isoDate(date),
      'low_balance': low.text.trim().isEmpty ? null : toDouble(low.text),
      'methods': methods.toList(),
      'gl_code': gl.text.trim().isEmpty ? null : gl.text.trim(),
      'active': active,
    });
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ======================================================================
// Opérations diverses et virements
// ======================================================================

Future<void> editOperation(BuildContext context, {int sign = -1, Map<String, dynamic>? op, String? accountId}) async {
  final sync = context.read<SyncEngine>();
  final accounts = trAccounts();
  if (accounts.isEmpty) {
    toast(context, tr('Créez d\'abord un compte (onglet Comptes).'), error: true);
    return;
  }
  var s = op == null ? sign : (toDouble(op['amount']) >= 0 ? 1 : -1);
  var account = (op?['account_id'] as String?) ?? accountId ?? '${accounts.firstWhere((a) => a['kind'] == 'cash', orElse: () => accounts.first)['id']}';
  var category = '${op?['category'] ?? (s < 0 ? 'other_expense' : 'other_income')}';
  final amount = TextEditingController(text: op == null ? '' : '${toDouble(op['amount']).abs().round()}');
  final label = TextEditingController(text: '${op?['label'] ?? ''}');
  final ref = TextEditingController(text: '${op?['reference'] ?? ''}');
  var date = DateTime.tryParse('${op?['date'] ?? ''}') ?? DateTime.now();
  final ok = await showFormDialog<String>(context, title: op != null ? 'Opération' : (s < 0 ? 'Nouvelle dépense' : 'Nouvelle recette'), builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            SegmentedButton<int>(
              segments: [ButtonSegment(value: -1, label: Text(tr('Dépense (sortie)'))), ButtonSegment(value: 1, label: Text(tr('Recette (entrée)')))],
              selected: {s},
              onSelectionChanged: (v) => set(() => s = v.first),
            ),
            const SizedBox(height: 8),
            TextField(controller: amount, autofocus: true, keyboardType: TextInputType.number, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700), decoration: InputDecoration(labelText: tr('Montant'))),
            DropdownButtonFormField<String>(
              initialValue: account,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Compte')),
              items: [for (final a in accounts) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}')))],
              onChanged: (v) => set(() => account = v!),
            ),
            DropdownButtonFormField<String>(
              initialValue: category,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Catégorie')),
              items: [for (final e in trCategories.entries) if (e.key != 'transfer' && e.key != 'expense_report') DropdownMenuItem(value: e.key, child: Text(tr(e.value.$1)))],
              onChanged: (v) => set(() {
                category = v!;
                if (label.text.isEmpty) label.text = trCategories[v]!.$1;
              }),
            ),
            TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé'))),
            Row(children: [
              Expanded(child: TextField(controller: ref, decoration: InputDecoration(labelText: tr('Référence (facultatif)')))),
              const SizedBox(width: 8),
              OutlinedButton(
                onPressed: () async {
                  final d = await pickDate(c, initialDate: date, firstDate: DateTime(2015), lastDate: DateTime(2100));
                  if (d != null) set(() => date = d);
                },
                child: Text(tr(dateFr(isoDate(date)))),
              ),
            ]),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              if (op != null) TextButton(onPressed: () => Navigator.pop(c, 'delete'), child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
              FilledButton(onPressed: () => Navigator.pop(c, 'save'), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  try {
    if (ok == 'delete') await sync.remove('tr_operations', '${op!['id']}');
    if (ok != 'save' || toDouble(amount.text) <= 0) return;
    await sync.save('tr_operations', {
      'id': op?['id'] ?? newId(),
      'account_id': account,
      'date': isoDate(date),
      'amount': s * toDouble(amount.text),
      'label': label.text.trim().isEmpty ? trCategories[category]!.$1 : label.text.trim(),
      'category': category,
      'reference': ref.text.trim().isEmpty ? null : ref.text.trim(),
    });
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

/// Virement interne : deux opérations liées (sortie d'un compte, entrée dans l'autre).
Future<void> newTransfer(BuildContext context) async {
  final sync = context.read<SyncEngine>();
  final accounts = trAccounts();
  if (accounts.length < 2) {
    toast(context, tr('Il faut au moins deux comptes.'), error: true);
    return;
  }
  var from = '${accounts.firstWhere((a) => a['kind'] == 'bank', orElse: () => accounts.first)['id']}';
  var to = '${accounts.firstWhere((a) => a['id'] != from)['id']}';
  final amount = TextEditingController();
  final label = TextEditingController();
  var date = DateTime.now();
  final ok = await showFormDialog<bool>(context, title: 'Virement entre comptes', builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String>(
              initialValue: from,
              decoration: InputDecoration(labelText: tr('Depuis')),
              items: [for (final a in accounts) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}')))],
              onChanged: (v) => set(() => from = v!),
            ),
            DropdownButtonFormField<String>(
              initialValue: to,
              decoration: InputDecoration(labelText: tr('Vers')),
              items: [for (final a in accounts) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}')))],
              onChanged: (v) => set(() => to = v!),
            ),
            TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant'))),
            TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé'), hintText: tr('Alimentation caisse, versement espèces en banque…'))),
            OutlinedButton(
              onPressed: () async {
                final d = await pickDate(c, initialDate: date, firstDate: DateTime(2015), lastDate: DateTime(2100));
                if (d != null) set(() => date = d);
              },
              child: Text(tr('Le ${dateFr(isoDate(date))}')),
            ),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: from == to ? null : () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true || toDouble(amount.text) <= 0) return;
  final tid = newId();
  String name(String id) => '${accounts.firstWhere((a) => a['id'] == id)['name']}';
  final l = label.text.trim().isEmpty ? 'Virement ${name(from)} vers ${name(to)}' : label.text.trim();
  try {
    await sync.save('tr_operations', {'id': newId(), 'account_id': from, 'date': isoDate(date), 'amount': -toDouble(amount.text), 'label': l, 'category': 'transfer', 'transfer_id': tid});
    await sync.save('tr_operations', {'id': newId(), 'account_id': to, 'date': isoDate(date), 'amount': toDouble(amount.text), 'label': l, 'category': 'transfer', 'transfer_id': tid});
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ======================================================================
// Mouvements d'un compte
// ======================================================================

class _MovementsTab extends StatefulWidget {
  const _MovementsTab({required this.account, required this.onAccount});

  final String? account;
  final ValueChanged<String> onAccount;

  @override
  State<_MovementsTab> createState() => _MovementsTabState();
}

class _MovementsTabState extends State<_MovementsTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('tr_operations'),
      builder: (context, _, _) {
        final accounts = trAccounts(all: true);
        if (accounts.isEmpty) return EmptyState(icon: Icons.account_balance_outlined, title: tr('Aucun compte'), message: tr('Les comptes apparaissent après la première synchronisation.'));
        final acc = accounts.firstWhere((a) => a['id'] == widget.account, orElse: () => accounts.first);
        final moves = trMovements(acc);
        var running = toDouble(acc['opening_balance']);
        final allRows = <Map<String, dynamic>>[
          for (final m in moves) {...m, 'balance': running += toDouble(m['amount'])},
        ].reversed.toList();
        String type(Map<String, dynamic> m) => m['kind'] == 'operation' ? 'operation' : (toDouble(m['amount']) >= 0 ? 'in_payment' : 'out_payment');
        bool base(Map<String, dynamic> m) =>
            (_q.isEmpty || '${m['label']} ${m['detail']}'.toLowerCase().contains(_q)) &&
            (_f['type'] == null || type(m) == _f['type']) &&
            (_f['detail'] == null || '${m['detail']}' == _f['detail']) &&
            (_period == null || _period!.contains(m['date']));
        bool st(Map<String, dynamic> m, String k) => k.isEmpty || (k == 'in') == (toDouble(m['amount']) >= 0);
        final rows = allRows.where((m) => base(m) && st(m, _status)).toList();
        return Column(children: [
          const SizedBox(height: 12),
          FilterBar(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            search: _q,
            searchHint: 'Rechercher un mouvement…',
            statuses: const {'': 'Tous', 'in': 'Entrées', 'out': 'Sorties'},
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in ['', 'in', 'out']) k: allRows.where((m) => base(m) && st(m, k)).length},
            filters: [
              FilterSpec('account', 'Compte', {for (final a in accounts) '${a['id']}': '${a['name']}'}, icon: Icons.account_balance_outlined),
              FilterSpec('type', 'Origine', const {'in_payment': 'Encaissements', 'out_payment': 'Règlements', 'operation': 'Opérations diverses'}, icon: Icons.swap_vert),
              FilterSpec('detail', 'Catégorie / moyen', filterOptions(allRows, 'detail'), icon: Icons.category_outlined),
            ],
            values: {..._f, 'account': '${acc['id']}'},
            onFilter: (k, v) {
              if (k == 'account') {
                if (v != null) widget.onAccount(v);
                return;
              }
              setState(() => _f = {..._f, k: v});
            },
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: rows.length,
            countLabel: 'mouvement',
            onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
            trailing: [
              Text(tr('Solde : ${money(running, cur)}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            ],
          ),
          Expanded(
            child: rows.isEmpty
                ? EmptyState(icon: Icons.receipt_long_outlined, title: tr('Aucun mouvement'), message: tr('Encaissements, règlements et opérations diverses de ce compte.'))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    itemCount: rows.length,
                    separatorBuilder: (_, _) => const Divider(height: 1),
                    itemBuilder: (_, i) {
                      final m = rows[i];
                      final amt = toDouble(m['amount']);
                      return ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(amt >= 0 ? Icons.south_west : Icons.north_east, color: amt >= 0 ? YColors.success : YColors.danger),
                        title: Text(tr('${m['label']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                        subtitle: Text(tr('${dateFr(m['date'])} · ${m['detail']}')),
                        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                          Text(tr('${amt >= 0 ? '+' : ''}${money(amt, cur)}'), style: TextStyle(fontWeight: FontWeight.w700, color: amt >= 0 ? YColors.success : YColors.danger)),
                          Text(tr(money(toDouble(m['balance']), cur)), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                        ]),
                        onTap: m['kind'] == 'operation' && m['op']['category'] != 'transfer' && (s.can('tresorerie.operate') || s.can('tresorerie.manage'))
                            ? () => editOperation(context, op: asMap(m['op']))
                            : null,
                      );
                    },
                  ),
          ),
        ]);
      },
    );
  }
}

// ======================================================================
// Rapprochement bancaire
// ======================================================================

class _ReconcileTab extends StatefulWidget {
  const _ReconcileTab({required this.account, required this.onAccount});

  final String? account;
  final ValueChanged<String> onAccount;

  @override
  State<_ReconcileTab> createState() => _ReconcileTabState();
}

class _ReconcileTabState extends State<_ReconcileTab> {
  Map<String, dynamic>? _data;
  List<Map<String, dynamic>> _summary = [];
  String _status = 'open';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  bool _busy = false;
  Object? _error;
  String? _loaded;

  String get _acc {
    final accounts = trAccounts(all: true);
    return '${accounts.firstWhere((a) => a['id'] == widget.account, orElse: () => accounts.firstWhere((a) => a['kind'] == 'bank', orElse: () => accounts.first))['id']}';
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    final acc = _acc;
    try {
      final r = await api.get('tresorerie/accounts/$acc/statement/lines', query: {'status': _status.isEmpty ? 'all' : _status});
      final sm = await api.get('tresorerie/summary');
      if (!mounted) return;
      setState(() {
        _data = asMap(r['data']);
        _summary = [for (final e in (sm['data'] as List)) asMap(e)];
        _loaded = acc;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _import() async {
    final api = context.read<Session>().api;
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['xlsx', 'csv', 'txt', 'ofx', 'qfx', 'sta', '940', 'mt940', 'xml', 'cfonb', 'dat']);
    if (files.isEmpty || !mounted) return;
    setState(() => _busy = true);
    try {
      final r = await api.uploadForm('tresorerie/accounts/$_acc/statement/analyze', {}, 'file', await files.first.readAsBytes(), files.first.name);
      final a = asMap(r['data']);
      if (!mounted) return;
      final res = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => a['format'] != null ? _StandardStatementDialog(analysis: a) : _StatementMappingDialog(analysis: a));
      if (res == null || !mounted) return;
      final done = await api.postLong('tresorerie/accounts/$_acc/statement/import', {'file': a['file'], 'filename': a['filename'], if (res['mapping'] != null) 'mapping': res['mapping'], 'invert': res['invert']});
      final d = asMap(done['data']);
      if (mounted) {
        toast(context, tr('${d['imported']} ligne(s) importée(s), ${d['matched']} rapprochée(s) automatiquement${(d['rules'] ?? 0) > 0 ? ', ${d['rules']} classée(s) par vos règles' : ''}${(d['duplicates'] as num) > 0 ? ', ${d['duplicates']} déjà connue(s)' : ''}.'));
      }
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _auto() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('tresorerie/accounts/$_acc/statement/auto');
      if (mounted) toast(context, tr('${r['data']['matched']} ligne(s) rapprochée(s).'));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _act(Map<String, dynamic> line, Map<String, dynamic> body) async {
    try {
      await context.read<Session>().api.post('tresorerie/statement-lines/${line['id']}', body);
      if (mounted) await context.read<SyncEngine>().sync();
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _resolve(Map<String, dynamic> line) async {
    final api = context.read<Session>().api;
    final cur = context.read<Session>().currency;
    Map<String, dynamic> c;
    try {
      c = asMap((await api.get('tresorerie/statement-lines/${line['id']}/candidates'))['data']);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!mounted) return;
    final amount = toDouble(line['amount']);
    var category = amount < 0 ? 'bank_fees' : 'other_income';
    final pick = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) => YAlertDialog(
          title: Text(tr('${dateFr(line['date'])} · ${amount >= 0 ? '+' : ''}${money(amount, cur)}')),
          content: SizedBox(
            width: 560,
            height: 480,
            child: ListView(children: [
              Text(tr('${line['label']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 12),
              Text(tr('Mouvements enregistrés proches'), style: TextStyle(fontWeight: FontWeight.w700)),
              if ((c['movements'] as List).isEmpty) Text(tr('Aucun.'), style: TextStyle(color: Theme.of(d).hintColor)),
              for (final m in (c['movements'] as List))
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: Icon((toDouble(m['amount']) - amount).abs() < 0.5 ? Icons.check_circle_outline : Icons.radio_button_unchecked, color: (toDouble(m['amount']) - amount).abs() < 0.5 ? YColors.success : YColors.muted),
                  title: Text(tr('${m['label']}')),
                  subtitle: Text(tr('${dateFr(m['date'])}${m['reference'] != null ? ' · ${m['reference']}' : ''}')),
                  trailing: Text(tr(money(toDouble(m['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => Navigator.pop(d, {'action': 'match', 'type': m['type'], 'id': m['id']}),
                ),
              const Divider(height: 24),
              Text(tr(amount > 0 ? 'Virement d\'un client pour une facture' : 'Règlement d\'une facture fournisseur'), style: const TextStyle(fontWeight: FontWeight.w700)),
              if ((c['documents'] as List).isEmpty) Text(tr('Aucune facture ouverte.'), style: TextStyle(color: Theme.of(d).hintColor)),
              for (final doc in (c['documents'] as List))
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(doc['exact'] == true ? Icons.receipt_long : Icons.receipt_outlined, color: doc['exact'] == true ? YColors.success : YColors.muted),
                  title: Text(tr('${doc['number'] ?? ''} · ${doc['partner'] ?? ''}')),
                  subtitle: Text(tr('${dateFr(doc['date'])} · reste ${money(toDouble(doc['balance']), cur)}${doc['named'] == true ? ' · cité dans le libellé' : ''}')),
                  trailing: Text(tr('Créer le paiement')),
                  onTap: () => Navigator.pop(d, {'action': 'payment', 'document_id': doc['id']}),
                ),
              const Divider(height: 24),
              Text(tr('Opération sans facture (frais, loyer, salaires…)'), style: TextStyle(fontWeight: FontWeight.w700)),
              Row(children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: category,
                    isExpanded: true,
                    items: [for (final e in trCategories.entries) if (e.key != 'transfer' && e.key != 'expense_report') DropdownMenuItem(value: e.key, child: Text(tr(e.value.$1)))],
                    onChanged: (v) => set(() => category = v!),
                  ),
                ),
                const SizedBox(width: 8),
                FilledButton.tonal(onPressed: () => Navigator.pop(d, {'action': 'operation', 'category': category}), child: Text(tr('Créer'))),
              ]),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(d, {'action': 'ignore'}), child: Text(tr('Ignorer cette ligne'))),
            TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Fermer'))),
          ],
        ),
      ),
    );
    if (pick != null) await _act(line, pick);
  }

  @override
  Widget build(BuildContext context) {
    final accounts = trAccounts(all: true);
    if (accounts.isEmpty) return EmptyState(icon: Icons.account_balance_outlined, title: tr('Aucun compte'), message: tr('Les comptes apparaissent après la première synchronisation.'));
    if (_loaded != _acc && _error == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _load());
    }
    final cur = context.read<Session>().currency;
    final sm = _summary.where((x) => x['id'] == _acc).firstOrNull;
    final allLines = [for (final l in (_data?['lines'] as List? ?? [])) asMap(l)];
    final lines = allLines
        .where((l) =>
            (_q.isEmpty || '${l['label']} ${l['match']?['label'] ?? ''}'.toLowerCase().contains(_q)) &&
            (_f['sign'] == null || (_f['sign'] == 'in') == (toDouble(l['amount']) >= 0)) &&
            (_f['by'] == null || (_f['by'] == 'auto') == (l['matched_by'] == 'auto')) &&
            (_period == null || _period!.contains(l['date'])))
        .toList();
    final book = [for (final l in (_data?['unmatched_book'] as List? ?? [])) asMap(l)];
    return ListView(padding: const EdgeInsets.all(16), children: [
      if (_error != null) OnlineOnlyNotice(error: _error!, onRetry: _load),
      if (sm != null)
        KpiWrap(children: [
          _Kpi('Solde du livre', money(toDouble(sm['balance']), cur), Icons.menu_book_outlined),
          _Kpi(
            'Solde du relevé${sm['statement_date'] != null ? ' au ${dateFr(sm['statement_date'])}' : ''}',
            sm['statement_balance'] == null ? '—' : money(toDouble(sm['statement_balance']), cur),
            Icons.account_balance_outlined,
          ),
          if (sm['statement_balance'] != null)
            Builder(builder: (_) {
              final gap = toDouble(sm['statement_balance']) - toDouble(sm['book_at_statement']);
              return _Kpi('Écart à cette date', money(gap, cur), gap.abs() < 1 ? Icons.check_circle_outline : Icons.warning_amber_rounded, color: gap.abs() < 1 ? YColors.success : YColors.danger);
            }),
          _Kpi('Lignes à rapprocher', '${sm['open_lines']}', Icons.compare_arrows, color: (sm['open_lines'] as num) > 0 ? YColors.warning : YColors.success),
        ]),
      const SizedBox(height: 12),
      Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
        FilledButton.icon(onPressed: _busy ? null : _import, icon: const Icon(Icons.upload_file), label: Text(tr('Importer un relevé'))),
        OutlinedButton.icon(onPressed: _busy ? null : _auto, icon: const Icon(Icons.auto_fix_high), label: Text(tr('Rapprocher automatiquement'))),
        if (_busy) const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
      ]),
      const SizedBox(height: 8),
      Text(tr('Relevé Excel ou CSV exporté de votre banque en ligne ou de votre espace Mobile Money. Les opérations déjà importées sont reconnues.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
      const SizedBox(height: 12),
      FilterBar(
        padding: EdgeInsets.zero,
        onSearch: (v) => setState(() => _q = v.toLowerCase()),
        search: _q,
        searchHint: 'Libellé de la ligne…',
        statuses: const {'open': 'À rapprocher', 'matched': 'Rapprochées', 'ignored': 'Ignorées', '': 'Toutes'},
        status: _status,
        onStatus: (v) {
          setState(() => _status = v);
          _load();
        },
        filters: [
          FilterSpec('account', 'Compte', {for (final a in accounts) '${a['id']}': '${a['name']}'}, icon: Icons.account_balance_outlined),
          const FilterSpec('sign', 'Sens', {'in': 'Crédits (entrées)', 'out': 'Débits (sorties)'}, icon: Icons.swap_vert),
          const FilterSpec('by', 'Rapprochement', {'auto': 'Automatique', 'manual': 'Manuel'}, icon: Icons.auto_fix_high),
        ],
        values: {..._f, 'account': _acc},
        onFilter: (k, v) {
          if (k == 'account') {
            if (v != null && v != _acc) {
              widget.onAccount(v);
              setState(() => _data = null);
            }
            return;
          }
          setState(() => _f = {..._f, k: v});
        },
        showPeriod: true,
        period: _period,
        onPeriod: (p) => setState(() => _period = p),
        count: _data == null ? null : lines.length,
        countLabel: 'ligne',
        onReset: () {
          final reload = _status != 'open';
          setState(() => (_q = '', _f = {}, _period = null, _status = 'open'));
          if (reload) _load();
        },
      ),
      const SizedBox(height: 12),
      if (_data != null && lines.isEmpty)
        EmptyState(icon: Icons.fact_check_outlined, title: _status == 'open' ? 'Tout est rapproché' : 'Aucune ligne', message: tr('Importez le relevé du mois pour vérifier que votre livre correspond à la banque.')),
      for (final l in lines)
        Card(
          child: ListTile(
            leading: Icon(
              l['status'] == 'matched' ? Icons.link : (l['status'] == 'ignored' ? Icons.visibility_off_outlined : Icons.help_outline),
              color: l['status'] == 'matched' ? YColors.success : (l['status'] == 'ignored' ? YColors.muted : YColors.warning),
            ),
            title: Text(tr('${l['label']}'), maxLines: 2, overflow: TextOverflow.ellipsis),
            subtitle: Text(tr([
              dateFr(l['date']),
              if (l['match'] != null) 'avec ${l['match']['label']}',
              if (l['matched_by'] == 'auto') 'automatique',
            ].join(' · '))),
            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('${toDouble(l['amount']) >= 0 ? '+' : ''}${money(toDouble(l['amount']), cur)}'), style: TextStyle(fontWeight: FontWeight.w700, color: toDouble(l['amount']) >= 0 ? YColors.success : YColors.danger)),
              if (l['status'] == 'open') TextButton(onPressed: () => _resolve(l), child: Text(tr('Traiter'))),
              if (l['status'] != 'open') TextButton(onPressed: () => _act(l, {'action': 'unmatch'}), child: Text(tr('Annuler'))),
            ]),
          ),
        ),
      if (book.isNotEmpty) ...[
        const SizedBox(height: 16),
        Text(tr('Dans le livre mais pas sur le relevé (${book.length})'), style: const TextStyle(fontWeight: FontWeight.w700)),
        Text(tr('Chèque pas encore encaissé, paiement saisi sur le mauvais compte, doublon…'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        for (final m in book.take(50))
          ListTile(
            dense: true,
            title: Text(tr('${m['label']}')),
            subtitle: Text(tr(dateFr(m['date']))),
            trailing: Text(tr(money(toDouble(m['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w600)),
          ),
      ],
    ]);
  }
}

class _Kpi extends StatelessWidget {
  const _Kpi(this.label, this.value, this.icon, {this.color = YColors.blue});

  final String label, value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => KpiBox(width: 250, child: StatTile(label: label, value: value, icon: icon, color: color));
}

/// Relevé dans un format bancaire normalisé (OFX, MT940, CAMT.053, CFONB) : aperçu puis import, sans correspondance de colonnes.
class _StandardStatementDialog extends StatefulWidget {
  const _StandardStatementDialog({required this.analysis});

  final Map<String, dynamic> analysis;

  @override
  State<_StandardStatementDialog> createState() => _StandardStatementDialogState();
}

class _StandardStatementDialogState extends State<_StandardStatementDialog> {
  bool _invert = false;

  @override
  Widget build(BuildContext context) {
    final a = widget.analysis;
    final sample = [for (final r in (a['sample'] as List)) List.from(r as List)];
    final cur = '${a['currency'] ?? context.read<Session>().currency}';
    return YAlertDialog(
      title: Text(tr('Relevé ${a['format_label']}')),
      content: SizedBox(
        width: 620,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Icon(Icons.verified_outlined, color: YColors.success, size: 18),
              const SizedBox(width: 8),
              Expanded(child: Text(tr('Format reconnu automatiquement : ${a['rows']} opération(s)${a['closing'] != null ? ' · solde final ${money(toDouble(a['closing']), cur)}' : ''}.'))),
            ]),
            const SizedBox(height: 12),
            for (final r in sample)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(tr('${r[1]}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text(tr('${dateFr(r[0])}${r[3] != null ? ' · ${r[3]}' : ''}')),
                trailing: Text(tr(money(toDouble(r[2]), cur)), style: TextStyle(fontWeight: FontWeight.w700, color: toDouble(r[2]) < 0 ? YColors.danger : YColors.success)),
              ),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Inverser le signe')), value: _invert, onChanged: (v) => setState(() => _invert = v)),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(onPressed: () => Navigator.pop(context, {'invert': _invert}), child: Text(tr('Importer et rapprocher'))),
      ],
    );
  }
}

class _StatementMappingDialog extends StatefulWidget {
  const _StatementMappingDialog({required this.analysis});

  final Map<String, dynamic> analysis;

  @override
  State<_StatementMappingDialog> createState() => _StatementMappingDialogState();
}

class _StatementMappingDialogState extends State<_StatementMappingDialog> {
  late final Map<String, int?> _m = {
    for (final f in ['date', 'label', 'debit', 'credit', 'amount', 'balance', 'reference']) f: ((widget.analysis['mapping'] as Map?)?[f] as num?)?.toInt(),
  };
  bool _invert = false;

  static const _labels = {
    'date': 'Date *',
    'label': 'Libellé',
    'debit': 'Débit (sorties)',
    'credit': 'Crédit (entrées)',
    'amount': 'Montant signé (si une seule colonne)',
    'balance': 'Solde',
    'reference': 'Référence',
  };

  @override
  Widget build(BuildContext context) {
    final headers = [for (final h in (widget.analysis['headers'] as List)) '$h'];
    final sample = [for (final r in (widget.analysis['sample'] as List)) List.from(r as List)];
    return YAlertDialog(
      title: Text(tr('Relevé : ${widget.analysis['filename']}')),
      content: SizedBox(
        width: 620,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${widget.analysis['rows']} ligne(s). Vérifiez les colonnes reconnues :'), style: TextStyle(color: Theme.of(context).hintColor)),
            const SizedBox(height: 8),
            for (final f in _labels.keys)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(children: [
                  SizedBox(width: 220, child: Text(tr(_labels[f]!))),
                  Expanded(
                    child: DropdownButtonFormField<int?>(
                      initialValue: _m[f],
                      isExpanded: true,
                      decoration: const InputDecoration(isDense: true),
                      items: [
                        const DropdownMenuItem<int?>(value: null, child: Text('—')),
                        for (var i = 0; i < headers.length; i++)
                          DropdownMenuItem<int?>(value: i, child: Text(tr('${headers[i].isEmpty ? 'Colonne ${i + 1}' : headers[i]}  (${sample.isNotEmpty && i < sample.first.length ? sample.first[i] ?? '' : ''})'), overflow: TextOverflow.ellipsis)),
                      ],
                      onChanged: (v) => setState(() => _m[f] = v),
                    ),
                  ),
                ]),
              ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Inverser le signe')),
              subtitle: Text(tr('Si les sorties apparaissent en positif dans la colonne Montant')),
              value: _invert,
              onChanged: (v) => setState(() => _invert = v),
            ),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: _m['date'] == null || (_m['amount'] == null && _m['debit'] == null && _m['credit'] == null)
              ? null
              : () => Navigator.pop(context, {
                    'mapping': {for (final e in _m.entries) if (e.value != null) e.key: e.value},
                    'invert': _invert,
                  }),
          child: Text(tr('Importer et rapprocher')),
        ),
      ],
    );
  }
}

// ======================================================================
// Prévisions
// ======================================================================

/// Catégories d'une semaine (le serveur renvoie [] quand il n'y a rien).
Map _cm(dynamic v) => v is Map ? v : const {};

const trScenarios = {'optimistic': 'Optimiste', 'realistic': 'Réaliste', 'pessimistic': 'Pessimiste'};
const trScenarioColors = {'optimistic': YColors.success, 'realistic': YColors.blue, 'pessimistic': YColors.warning};
const trCategoryIcons = {
  'clients': Icons.receipt_long_outlined, 'commandes': Icons.shopping_bag_outlined, 'abonnements': Icons.autorenew, 'pipeline': Icons.trending_up,
  'fournisseurs': Icons.local_shipping_outlined, 'achats': Icons.shopping_cart_outlined, 'salaires': Icons.badge_outlined, 'charges': Icons.account_balance_outlined,
  'notes_frais': Icons.receipt_outlined, 'tva': Icons.account_balance, 'previsions': Icons.edit_calendar_outlined,
};

class _ForecastTab extends StatefulWidget {
  const _ForecastTab();

  @override
  State<_ForecastTab> createState() => _ForecastTabState();
}

class _ForecastTabState extends State<_ForecastTab> {
  Map<String, dynamic>? _d;
  Object? _error;
  int? _week;
  String _scenario = 'realistic';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('tresorerie/forecast');
      if (mounted) setState(() => (_d = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  List<Map<String, dynamic>> _weeks() {
    final src = _scenario == 'realistic' ? _d!['weeks'] : (_d!['scenario_weeks'] as Map?)?[_scenario] ?? _d!['weeks'];
    return [for (final w in (src as List)) asMap(w)];
  }

  Future<void> _settings() async {
    final api = context.read<Session>().api;
    final cur = context.read<Session>().currency;
    Map<String, dynamic> s;
    try {
      s = asMap((await api.get('tresorerie/forecast-settings'))['data']);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!mounted) return;
    final c = {for (final k in ['threshold', 'pessimistic_extra_days', 'doubtful_days', 'orders_pessimistic', 'invoice_lag_days', 'supplier_terms_days']) k: TextEditingController(text: '${toDouble(s[k]).round()}')};
    var pipeline = s['include_pipeline'] == true;
    var vat = s['vat_enabled'] != false;
    final vatDay = TextEditingController(text: '${toDouble(s['vat_day'] ?? 15).round()}');
    var weeks = toDouble(s['weeks']).round();
    final saved = await showFormDialog<bool>(
      context,
      title: tr('Paramètres de la prévision'),
      maxWidth: 560,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          Field(label: tr('Seuil d\'alerte ($cur) — alerte quand le solde réaliste prévu passe dessous'), child: TextField(controller: c['threshold'], keyboardType: const TextInputType.numberWithOptions(signed: true))),
          Field(
            label: tr('Horizon'),
            child: SegmentedButton<int>(segments: [ButtonSegment(value: 8, label: Text(tr('8 sem.'))), ButtonSegment(value: 13, label: Text(tr('13 sem.'))), ButtonSegment(value: 26, label: Text(tr('26 sem.')))], selected: {weeks}, onSelectionChanged: (v) => set(() => weeks = v.first)),
          ),
          Text(tr('Scénario pessimiste'), style: Theme.of(ctx).textTheme.titleSmall),
          Row(children: [
            Expanded(child: Field(label: tr('Retard client supplémentaire (jours)'), child: TextField(controller: c['pessimistic_extra_days'], keyboardType: TextInputType.number))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Créance douteuse après (jours)'), child: TextField(controller: c['doubtful_days'], keyboardType: TextInputType.number))),
          ]),
          Field(label: tr('Part des commandes clients encaissée (%)'), child: TextField(controller: c['orders_pessimistic'], keyboardType: TextInputType.number)),
          Text(tr('Délais'), style: Theme.of(ctx).textTheme.titleSmall),
          Row(children: [
            Expanded(child: Field(label: tr('Délai commande-facture (jours)'), child: TextField(controller: c['invoice_lag_days'], keyboardType: TextInputType.number))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Paiement des fournisseurs (jours)'), child: TextField(controller: c['supplier_terms_days'], keyboardType: TextInputType.number))),
          ]),
          SwitchListTile(contentPadding: EdgeInsets.zero, value: pipeline, onChanged: (v) => set(() => pipeline = v), title: Text(tr('Opportunités du CRM dans le scénario optimiste')), subtitle: Text(tr('Montant pondéré par la probabilité, encaissé après la date de signature prévue'))),
          SwitchListTile(contentPadding: EdgeInsets.zero, value: vat, onChanged: (v) => set(() => vat = v), title: Text(tr('TVA à reverser calculée automatiquement')), subtitle: Text(tr('Collectée − déductible : mois écoulé réel, mois suivants estimés sur la moyenne des 3 derniers mois'))),
          if (vat) Field(label: tr('Jour de paiement de la TVA (mois suivant)'), child: TextField(controller: vatDay, keyboardType: TextInputType.number)),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerRight,
            child: SaveButton(
              onPressed: () async {
                try {
                  await api.put('tresorerie/forecast-settings', {
                    for (final e in c.entries) e.key: e.key == 'threshold' ? toDouble(e.value.text.replaceAll(RegExp(r'[\s ]'), '')) : toDouble(e.value.text).round(),
                    'include_pipeline': pipeline,
                    'weeks': weeks,
                    'vat_enabled': vat,
                    'vat_day': toDouble(vatDay.text).round().clamp(1, 28),
                  });
                  if (ctx.mounted) Navigator.pop(ctx, true);
                } catch (e) {
                  if (ctx.mounted) toast(ctx, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ),
        ]),
      ),
    );
    if (saved == true) {
      setState(() => _week = null);
      await _load();
    }
  }

  Future<void> _edit([Map<String, dynamic>? f]) async {
    final sync = context.read<SyncEngine>();
    final label = TextEditingController(text: '${f?['label'] ?? ''}');
    final amount = TextEditingController(text: f == null ? '' : '${toDouble(f['amount']).abs().round()}');
    var sign = f == null ? -1 : (toDouble(f['amount']) >= 0 ? 1 : -1);
    var repeat = '${f?['repeat'] ?? 'monthly'}';
    var date = DateTime.tryParse('${f?['date'] ?? ''}') ?? DateTime.now().add(const Duration(days: 7));
    final scenarios = <String>{...((f?['scenarios'] as List?)?.map((e) => '$e') ?? trScenarios.keys)};
    final r = await showFormDialog<String>(context, title: 'Entrée ou sortie prévue', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              SegmentedButton<int>(segments: [ButtonSegment(value: -1, label: Text(tr('Sortie'))), ButtonSegment(value: 1, label: Text(tr('Entrée')))], selected: {sign}, onSelectionChanged: (v) => set(() => sign = v.first)),
              TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé'), hintText: tr('Loyer, échéance de prêt, subvention, TVA…'))),
              TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant'))),
              const SizedBox(height: 8),
              SegmentedButton<String>(
                segments: [ButtonSegment(value: 'none', label: Text(tr('Une fois'))), ButtonSegment(value: 'weekly', label: Text(tr('Chaque semaine'))), ButtonSegment(value: 'monthly', label: Text(tr('Chaque mois')))],
                selected: {repeat},
                onSelectionChanged: (v) => set(() => repeat = v.first),
              ),
              OutlinedButton(
                onPressed: () async {
                  final d = await pickDate(c, initialDate: date, firstDate: DateTime(2015), lastDate: DateTime(2100));
                  if (d != null) set(() => date = d);
                },
                child: Text(tr(repeat == 'none' ? 'Le ${dateFr(isoDate(date))}' : 'À partir du ${dateFr(isoDate(date))}')),
              ),
              const SizedBox(height: 8),
              Align(alignment: Alignment.centerLeft, child: Text(tr('Scénarios concernés'), style: Theme.of(c).textTheme.labelLarge)),
              Wrap(spacing: 6, children: [
                for (final e in trScenarios.entries)
                  FilterChip(label: Text(tr(e.value)), selected: scenarios.contains(e.key), onSelected: (v) => set(() => v ? scenarios.add(e.key) : (scenarios.length > 1 ? scenarios.remove(e.key) : null))),
              ]),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                if (f != null) TextButton(onPressed: () => Navigator.pop(c, 'delete'), child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
                TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                FilledButton(onPressed: () => Navigator.pop(c, 'save'), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    try {
      if (r == 'delete') await sync.remove('tr_forecasts', '${f!['id']}');
      if (r == 'save' && toDouble(amount.text) > 0 && label.text.trim().isNotEmpty) {
        await sync.save('tr_forecasts', {
          'id': f?['id'] ?? newId(), 'label': label.text.trim(), 'amount': sign * toDouble(amount.text), 'date': isoDate(date), 'repeat': repeat,
          'scenarios': scenarios.length == trScenarios.length ? null : scenarios.toList(),
        });
      }
      if (r != null) {
        await sync.sync();
        await _load();
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final weeks = _weeks();
    final sc = Map<String, dynamic>.from((_d!['scenarios'] as Map?)?[_scenario] as Map? ?? {'lowest': _d!['lowest'], 'end': weeks.last['balance'], 'first_below': _d!['first_below']});
    final threshold = toDouble(_d!['threshold']);
    final lowest = toDouble(sc['lowest']);
    final manage = context.read<Session>().can('tresorerie.manage');
    final n = weeks.length;
    final cats = Map<String, String>.from((_d!['categories'] as Map?) ?? const {});
    final lines = {
      for (final e in ((_d!['scenarios'] as Map?) ?? const {}).entries) '${e.key}': [for (final v in ((e.value as Map)['balances'] as List)) toDouble(v)],
    };
    final sel = _week == null || _week! >= n ? null : weeks[_week!];
    final usedCats = cats.keys.where((k) => weeks.any((w) => toDouble(_cm(w['categories'])[k]) != 0)).toList();
    final delays = _d!['customer_delays'] as Map?;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Row(children: [
          SegmentedButton<String>(
            segments: [for (final e in trScenarios.entries) ButtonSegment(value: e.key, label: Text(tr(e.value)))],
            selected: {_scenario},
            onSelectionChanged: (v) => setState(() => _scenario = v.first),
          ),
          const Spacer(),
          if (manage) IconButton(tooltip: tr('Paramètres et seuil d\'alerte'), onPressed: _settings, icon: const Icon(Icons.tune)),
        ]),
        const SizedBox(height: 12),
        KpiWrap(children: [
          _Kpi('Trésorerie aujourd\'hui', money(toDouble(_d!['start_balance']), cur), Icons.savings_outlined),
          _Kpi('Point le plus bas ($n semaines)', money(lowest, cur), lowest < threshold ? Icons.warning_amber_rounded : Icons.trending_flat, color: lowest < threshold ? YColors.danger : YColors.success),
          _Kpi('Dans $n semaines', money(toDouble(sc['end']), cur), Icons.flag_outlined),
          _Kpi('Sous le seuil (${money(threshold, cur)})', sc['first_below'] == null ? 'Jamais' : 'Sem. du ${dateFr(sc['first_below'])}', Icons.notifications_active_outlined,
              color: sc['first_below'] == null ? YColors.success : YColors.danger),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Solde prévu semaine par semaine — ${trScenarios[_scenario]}'),
          trailing: manage ? TextButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.add, size: 18), label: Text(tr('Prévision'))) : null,
          child: Column(children: [
            SizedBox(
              height: 260,
              child: LayoutBuilder(builder: (context, box) {
                return GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTapUp: (d) => setState(() => _week = (d.localPosition.dx / (box.maxWidth / n)).floor().clamp(0, n - 1)),
                  child: CustomPaint(size: Size(box.maxWidth, 260), painter: _ForecastPainter(weeks, _week, lines: lines, current: _scenario, threshold: threshold)),
                );
              }),
            ),
            const SizedBox(height: 6),
            Wrap(spacing: 14, runSpacing: 4, children: [
              const _Legend(YColors.success, 'Entrées'),
              const _Legend(YColors.danger, 'Sorties'),
              for (final e in trScenarios.entries) _Legend(trScenarioColors[e.key]!, 'Solde ${e.value.toLowerCase()}'),
              const _Legend(Color(0xFFD93025), 'Seuil'),
            ]),
            const SizedBox(height: 4),
            Text(
                tr('Factures clients au retard moyen de chaque client${delays != null && toDouble(delays['clients']) > 0 ? ' (${delays['average']} j en moyenne sur ${delays['clients']} client(s))' : ''}, commandes à facturer, abonnements (à leurs conditions de paiement et échéanciers), factures fournisseurs, bons de commande, paie, TVA, notes de frais et vos prévisions. Optimiste : paiement à l\'échéance et opportunités du CRM ; pessimiste : retards allongés, créances anciennes écartées. Touchez une semaine pour le détail.'),
                style: TextStyle(fontSize: 12, color: hint)),
          ]),
        ),
        if (sel != null) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Semaine du ${dateFr(sel['week'])} — solde ${money(toDouble(sel['balance']), cur)}'),
            trailing: IconButton(onPressed: () => setState(() => _week = null), icon: const Icon(Icons.close)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if ((sel['details'] as List).isEmpty) Text(tr('Rien de prévu.'), style: TextStyle(color: hint)),
              for (final k in cats.keys.where((k) => (sel['details'] as List).any((d) => d['category'] == k))) ...[
                Padding(
                  padding: const EdgeInsets.only(top: 8, bottom: 2),
                  child: Row(children: [
                    Icon(trCategoryIcons[k] ?? Icons.circle, size: 16, color: hint),
                    const SizedBox(width: 6),
                    Expanded(child: Text(tr(cats[k]!), style: const TextStyle(fontWeight: FontWeight.w700))),
                    Text(tr(money(toDouble(_cm(sel['categories'])[k]), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                  ]),
                ),
                for (final d in (sel['details'] as List).where((d) => d['category'] == k))
                  Padding(
                    padding: const EdgeInsets.only(left: 22, top: 2),
                    child: Row(children: [
                      Expanded(child: Text(tr('${d['label']}'), style: const TextStyle(fontSize: 13))),
                      Text(tr(dateFr(d['date'])), style: TextStyle(fontSize: 12, color: hint)),
                      const SizedBox(width: 12),
                      SizedBox(
                        width: 120,
                        child: Text(tr(money(toDouble(d['amount']), cur)), textAlign: TextAlign.right, style: TextStyle(fontSize: 13, color: toDouble(d['amount']) >= 0 ? YColors.success : YColors.danger)),
                      ),
                    ]),
                  ),
              ],
            ]),
          ),
        ],
        if (usedCats.isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Par catégorie'),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                columnSpacing: 16,
                headingRowHeight: 34,
                dataRowMinHeight: 30,
                dataRowMaxHeight: 34,
                columns: [
                  const DataColumn(label: Text('')),
                  for (final w in weeks) DataColumn(numeric: true, label: Text(tr(_short(w['week'])))),
                ],
                rows: [
                  for (final k in usedCats)
                    DataRow(cells: [
                      DataCell(Text(tr(cats[k]!), style: const TextStyle(fontSize: 12.5))),
                      for (final w in weeks) DataCell(Text(tr(_k(toDouble(_cm(w['categories'])[k]))), style: TextStyle(fontSize: 12, color: toDouble(_cm(w['categories'])[k]) < 0 ? YColors.danger : null))),
                    ]),
                  DataRow(cells: [
                    DataCell(Text(tr('Solde fin de semaine'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5))),
                    for (final w in weeks)
                      DataCell(Text(tr(_k(toDouble(w['balance']))), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12, color: toDouble(w['balance']) < threshold ? YColors.danger : null))),
                  ]),
                ],
              ),
            ),
          ),
        ],
        const SizedBox(height: 12),
        ValueListenableBuilder(
          valueListenable: LocalDb.listen('tr_forecasts'),
          builder: (context, _, _) {
            final rows = LocalDb.all('tr_forecasts')..sort((a, b) => '${a['date']}'.compareTo('${b['date']}'));
            return SectionCard(
              title: tr('Prévisions saisies'),
              child: Column(children: [
                if (rows.isEmpty) Text(tr('Ajoutez le loyer, les échéances de prêt, une subvention attendue… pour une prévision complète.'), style: TextStyle(color: hint)),
                for (final f in rows)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(f['repeat'] == 'none' ? Icons.event : Icons.repeat),
                    title: Text(tr('${f['label']}')),
                    subtitle: Text(tr([
                      '${{'none': 'Le', 'weekly': 'Chaque semaine depuis le', 'monthly': 'Chaque mois depuis le'}[f['repeat']] ?? ''} ${dateFr(f['date'])}',
                      if (f['scenarios'] is List) 'scénario ${(f['scenarios'] as List).map((e) => trScenarios[e]?.toLowerCase() ?? e).join(', ')}',
                    ].join(' · '))),
                    trailing: Text(tr(money(toDouble(f['amount']), cur)), style: TextStyle(fontWeight: FontWeight.w700, color: toDouble(f['amount']) >= 0 ? YColors.success : YColors.danger)),
                    onTap: manage ? () => _edit(f) : null,
                  ),
              ]),
            );
          },
        ),
      ]),
    );
  }

  static String _short(dynamic iso) {
    final d = DateTime.tryParse('$iso');
    return d == null ? '' : '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
  }

  /// Montant compact (k / M) pour le tableau.
  static String _k(double v) {
    if (v == 0) return '—';
    final a = v.abs();
    final s = a >= 1000000 ? '${(a / 1000000).toStringAsFixed(a >= 10000000 ? 0 : 1).replaceAll('.', ',')} M' : (a >= 1000 ? '${(a / 1000).round()} k' : a.round().toString());
    return v < 0 ? '−$s' : s;
  }
}

class _Legend extends StatelessWidget {
  const _Legend(this.color, this.label);

  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 10, height: 10, decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(2))),
        const SizedBox(width: 4),
        Text(tr(label), style: const TextStyle(fontSize: 12)),
      ]);
}

class _ForecastPainter extends CustomPainter {
  _ForecastPainter(this.weeks, this.selected, {this.lines = const {}, this.current = 'realistic', this.threshold = 0});

  final List<Map<String, dynamic>> weeks;
  final int? selected;
  final Map<String, List<double>> lines;
  final String current;
  final double threshold;

  @override
  void paint(Canvas canvas, Size size) {
    if (weeks.isEmpty) return;
    const bottom = 22.0;
    final slot = size.width / weeks.length;
    // Bandeau haut : soldes (échelle propre) ; bandeau bas : entrées / sorties de la semaine
    final lineH = (size.height - bottom) * 0.64;
    final barTop = lineH + 14;
    final barH = size.height - bottom - barTop;
    final series = lines.isEmpty ? {current: [for (final w in weeks) toDouble(w['balance'])]} : lines;
    final lv = [for (final l in series.values) ...l, threshold];
    var maxL = lv.reduce((a, b) => a > b ? a : b);
    var minL = lv.reduce((a, b) => a < b ? a : b);
    final pad = ((maxL - minL).abs() < 1 ? (maxL.abs() * 0.1 + 1) : (maxL - minL)) * 0.08;
    maxL += pad;
    minL -= pad;
    double ly(double v) => 6 + (maxL - v) / (maxL - minL) * (lineH - 6);
    final maxIn = weeks.map((w) => toDouble(w['in'])).fold<double>(1, (a, b) => b > a ? b : a);
    final maxOut = weeks.map((w) => -toDouble(w['out'])).fold<double>(1, (a, b) => b > a ? b : a);
    final barMax = maxIn > maxOut ? maxIn : maxOut;
    final zero = barTop + barH / 2;
    double by(double v) => zero - v / barMax * (barH / 2);
    final grid = Paint()..color = YColors.line;
    canvas.drawLine(Offset(0, zero), Offset(size.width, zero), grid);
    // Repères de solde (min / max)
    for (final v in [maxL - pad, minL + pad]) {
      final tp = TextPainter(text: TextSpan(text: _ForecastTabState._k(v), style: const TextStyle(fontSize: 9.5, color: YColors.muted)), textDirection: TextDirection.ltr)..layout();
      tp.paint(canvas, Offset(2, ly(v) - tp.height - 1));
      for (double x = 0; x < size.width; x += 6) {
        canvas.drawLine(Offset(x, ly(v)), Offset(x + 2, ly(v)), grid);
      }
    }
    for (var i = 0; i < weeks.length; i++) {
      final w = weeks[i];
      final x = i * slot;
      if (i == selected) canvas.drawRect(Rect.fromLTWH(x, 0, slot, size.height - bottom + 4), Paint()..color = YColors.blue.withValues(alpha: 0.07));
      final bw = slot * 0.3;
      canvas.drawRect(Rect.fromLTRB(x + slot / 2 - bw - 1, by(toDouble(w['in'])), x + slot / 2 - 1, zero), Paint()..color = YColors.success.withValues(alpha: 0.7));
      canvas.drawRect(Rect.fromLTRB(x + slot / 2 + 1, zero, x + slot / 2 + bw + 1, by(toDouble(w['out']))), Paint()..color = YColors.danger.withValues(alpha: 0.7));
      final d = DateTime.tryParse('${w['week']}');
      if (d != null && (weeks.length <= 14 || i.isEven)) {
        final tp = TextPainter(text: TextSpan(text: '${d.day}/${d.month}', style: const TextStyle(fontSize: 10, color: YColors.muted)), textDirection: TextDirection.ltr)..layout();
        tp.paint(canvas, Offset(x + (slot - tp.width) / 2, size.height - bottom + 6));
      }
    }
    // Seuil (pointillés rouges)
    final ty = ly(threshold);
    for (double x = 0; x < size.width; x += 10) {
      canvas.drawLine(Offset(x, ty), Offset(x + 5, ty), Paint()
        ..color = const Color(0xFFD93025)
        ..strokeWidth = 1.5);
    }
    for (final key in [...series.keys.where((k) => k != current), current]) {
      final vals = series[key];
      if (vals == null || vals.isEmpty) continue;
      final color = trScenarioColors[key] ?? YColors.blue;
      final path = Path();
      for (var i = 0; i < vals.length && i < weeks.length; i++) {
        final p = Offset(i * slot + slot / 2, ly(vals[i]));
        i == 0 ? path.moveTo(p.dx, p.dy) : path.lineTo(p.dx, p.dy);
      }
      canvas.drawPath(path, Paint()
        ..color = key == current ? color : color.withValues(alpha: 0.6)
        ..strokeWidth = key == current ? 3 : 1.8
        ..style = PaintingStyle.stroke);
      if (key == current) {
        for (var i = 0; i < vals.length && i < weeks.length; i++) {
          canvas.drawCircle(Offset(i * slot + slot / 2, ly(vals[i])), 3.5, Paint()..color = vals[i] < threshold ? YColors.danger : color);
        }
      }
    }
  }

  @override
  bool shouldRepaint(covariant _ForecastPainter old) => old.weeks != weeks || old.selected != selected || old.current != current || old.threshold != threshold;
}
