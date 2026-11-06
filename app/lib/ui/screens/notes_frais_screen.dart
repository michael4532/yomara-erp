import 'dart:convert';

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
import '../widgets/project_picker.dart';
import 'approbations_screen.dart' show ApprovalBanner;
import 'analytique_screen.dart' show AnalyticField, analyticEnabled, anDist;
import 'rh_screen.dart' show empName;
import 'tresorerie_screen.dart' show trActive, trAccounts;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const nfStatuses = {'draft': 'Brouillon', 'submitted': 'À valider', 'approved': 'Approuvée', 'rejected': 'Refusée', 'paid': 'Remboursée'};

Color nfStatusColor(String? s) => switch (s) {
  'submitted' => YColors.warning,
  'approved' => YColors.blue,
  'paid' => YColors.success,
  'rejected' => YColors.danger,
  _ => YColors.muted,
};

List<Map<String, dynamic>> nfCategories({bool all = false}) =>
    LocalDb.all('nf_categories').where((c) => all || c['active'] != false).toList()..sort((a, b) => ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0));

String nfCategoryName(String? id) => '${LocalDb.get('nf_categories', id)?['name'] ?? 'Sans catégorie'}';

// ---------- Justificatifs en attente d'envoi (pris hors ligne) ----------

/// Justificatifs en attente : rangés dans l'espace local de l'utilisateur (jamais envoyés avec le compte d'un collègue).
Map<String, dynamic> _pendingReceipts() {
  if (!LocalDb.isOpen) return {};
  final legacy = LocalDb.meta.get('nf_receipts');
  if (legacy != null) {
    // ancien emplacement commun à tous les comptes du poste : repris une fois dans l'espace de l'utilisateur
    final m = {...asMap(LocalDb.state().get('nf_receipts')), ...asMap(legacy)};
    LocalDb.state().put('nf_receipts', m);
    LocalDb.meta.delete('nf_receipts');
    return m;
  }
  return asMap(LocalDb.state().get('nf_receipts'));
}

bool nfReceiptPending(String id) => _pendingReceipts().containsKey(id);

Future<void> _keepReceipt(String id, String name, List<int> bytes, bool submit) async {
  final m = _pendingReceipts();
  m[id] = {'name': name, 'b64': base64Encode(bytes), 'submit': submit};
  await LocalDb.state().put('nf_receipts', m);
}

/// Envoie les justificatifs pris hors ligne (après la synchronisation de leur dépense), puis les dépenses à envoyer.
Future<int> nfUploadPending(BuildContext context) async {
  final m = _pendingReceipts();
  if (m.isEmpty) return 0;
  final api = context.read<Session>().api;
  final sync = context.read<SyncEngine>();
  try {
    await sync.sync();
  } catch (_) {
    return 0;
  }
  var done = 0;
  for (final e in m.entries.toList()) {
    final v = asMap(e.value);
    try {
      await api.uploadForm('notes_frais/expenses/${e.key}/receipt', {}, 'file', base64Decode('${v['b64']}'), '${v['name']}');
      m.remove(e.key);
      await LocalDb.state().put('nf_receipts', m);
      done++;
      if (v['submit'] == true) await sync.save('nf_expenses', {'id': e.key, 'status': 'submitted'});
      final local = LocalDb.get('nf_expenses', e.key);
      if (local != null) await LocalDb.put('nf_expenses', {...local, 'has_receipt': true});
    } catch (_) {
      // toujours hors ligne, ou dépense pas encore reçue par le serveur : on réessaiera
    }
  }
  return done;
}

Future<void> nfShowReceipt(BuildContext context, Map e) async {
  try {
    final bytes = await context.read<Session>().api.download('notes_frais/expenses/${e['id']}/receipt');
    if (!context.mounted) return;
    final name = '${e['receipt_name'] ?? 'justificatif'}';
    final isPdf = name.toLowerCase().endsWith('.pdf') || (bytes.length > 4 && String.fromCharCodes(bytes.take(4)) == '%PDF');
    if (isPdf) {
      final where = await saveFile(name.toLowerCase().endsWith('.pdf') ? name : '$name.pdf', bytes, mime: 'application/pdf');
      if (context.mounted) toast(context, tr('Justificatif enregistré${where.isEmpty ? '' : ' : $where'}'));
      return;
    }
    await showDialog(
      context: context,
      builder: (c) => Dialog(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ListTile(title: Text(tr('${e['description']}')), subtitle: Text(tr(name)), trailing: IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(c))),
          Flexible(child: InteractiveViewer(child: Image.memory(Uint8List.fromList(bytes), fit: BoxFit.contain))),
          const SizedBox(height: 8),
        ]),
      ),
    );
  } catch (err) {
    if (context.mounted) toast(context, errorText(err), error: true);
  }
}

// ---------- Saisie d'une dépense ----------

Future<void> editExpense(BuildContext context, {required String employeeId, Map<String, dynamic>? expense}) async {
  await showFormDialog(context, title: expense == null ? 'Nouvelle dépense' : 'Dépense', builder: (_) => _ExpenseForm(employeeId: employeeId, expense: expense));
}

class _ExpenseForm extends StatefulWidget {
  const _ExpenseForm({required this.employeeId, this.expense});

  final String employeeId;
  final Map<String, dynamic>? expense;

  @override
  State<_ExpenseForm> createState() => _ExpenseFormState();
}

class _ExpenseFormState extends State<_ExpenseForm> {
  late final _amount = TextEditingController(text: widget.expense == null ? '' : '${toDouble(widget.expense!['amount']).round()}');
  late final _desc = TextEditingController(text: '${widget.expense?['description'] ?? ''}');
  late final _merchant = TextEditingController(text: '${widget.expense?['merchant'] ?? ''}');
  late final _project = TextEditingController(text: '${widget.expense?['project'] ?? ''}');
  late String? _projectId = widget.expense?['project_id'] as String?;
  late String? _category = (widget.expense?['category_id'] as String?) ?? nfCategories().firstOrNull?['id'] as String?;
  late DateTime _date = DateTime.tryParse('${widget.expense?['date'] ?? ''}') ?? DateTime.now();
  late Map<String, double> _analytic = anDist(widget.expense?['analytic']);
  List<int>? _bytes;
  String? _fileName;
  bool _busy = false;

  bool get _editable => widget.expense == null || ['draft', 'rejected'].contains(widget.expense!['status']);

  Future<void> _pick() async {
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf']);
    if (files.isEmpty) return;
    final b = await files.first.readAsBytes();
    if (b.length > 8 * 1024 * 1024) {
      if (mounted) toast(context, tr('Fichier trop lourd (8 Mo maximum).'), error: true);
      return;
    }
    setState(() => (_bytes = b, _fileName = files.first.name));
  }

  Future<void> _save(bool submit) async {
    final amount = toDouble(_amount.text);
    if (amount <= 0 || _desc.text.trim().isEmpty) return toast(context, tr('Indiquez le montant et l\'objet de la dépense.'), error: true);
    final cat = LocalDb.get('nf_categories', _category);
    final hasReceipt = widget.expense?['has_receipt'] == true || _bytes != null || nfReceiptPending('${widget.expense?['id']}');
    if (submit && !hasReceipt && cat?['receipt_required'] != false) {
      return toast(context, tr('Joignez la photo du justificatif pour envoyer cette dépense.'), error: true);
    }
    setState(() => _busy = true);
    final sync = context.read<SyncEngine>();
    final api = context.read<Session>().api;
    final id = '${widget.expense?['id'] ?? newId()}';
    final needsUpload = _bytes != null;
    try {
      await sync.save('nf_expenses', {
        'id': id,
        'employee_id': widget.employeeId,
        'category_id': _category,
        'date': isoDate(_date),
        'amount': amount,
        'description': _desc.text.trim(),
        'merchant': _merchant.text.trim().isEmpty ? null : _merchant.text.trim(),
        'project': _project.text.trim().isEmpty ? null : _project.text.trim(),
        if (projectsEnabled(context.read<Session>())) 'project_id': _projectId,
        if (analyticEnabled(context.read<Session>())) 'analytic': _analytic.isEmpty ? null : _analytic,
        // Avec un justificatif à envoyer, la dépense part d'abord en brouillon, puis est envoyée une fois la photo reçue
        'status': submit && !needsUpload ? 'submitted' : 'draft',
      });
      var queued = false;
      if (needsUpload) {
        try {
          await sync.sync();
          await api.uploadForm('notes_frais/expenses/$id/receipt', {}, 'file', _bytes!, _fileName ?? 'justificatif.jpg');
          if (submit) await sync.save('nf_expenses', {'id': id, 'status': 'submitted'});
        } catch (_) {
          await _keepReceipt(id, _fileName ?? 'justificatif.jpg', _bytes!, submit);
          queued = true;
        }
      }
      if (!mounted) return;
      Navigator.pop(context);
      toast(context, queued ? 'Dépense enregistrée : le justificatif partira au retour du réseau${submit ? ', puis la dépense sera envoyée' : ''}.' : (submit ? 'Dépense envoyée pour validation.' : 'Brouillon enregistré.'));
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final cats = nfCategories();
    final cat = LocalDb.get('nf_categories', _category);
    final over = cat?['max_amount'] != null && toDouble(_amount.text) > toDouble(cat!['max_amount']);
    final e = widget.expense;
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      if (e != null) ApprovalBanner(recordId: '${e['id']}'),
      if (e != null && e['status'] == 'rejected' && e['decision_note'] != null)
        Container(
          margin: const EdgeInsets.only(bottom: 12),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(color: YColors.danger.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(10)),
          child: Text(tr('Refusée : ${e['decision_note']}'), style: const TextStyle(color: YColors.danger)),
        ),
      Field(
        label: tr('Montant'),
        child: TextField(
          controller: _amount,
          enabled: _editable,
          autofocus: e == null,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
          onChanged: (_) => setState(() {}),
        ),
      ),
      if (over) Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(tr('Au-delà du plafond de cette catégorie (${money(cat['max_amount'], context.read<Session>().currency)}) : votre responsable peut refuser l\'excédent.'), style: const TextStyle(color: YColors.warning, fontSize: 12))),
      Field(
        label: tr('Catégorie'),
        child: DropdownButtonFormField<String>(
          initialValue: cats.any((c) => c['id'] == _category) ? _category : null,
          isExpanded: true,
          items: [for (final c in cats) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}')))],
          onChanged: _editable ? (v) => setState(() => _category = v) : null,
        ),
      ),
      Field(label: tr('Objet'), child: TextField(controller: _desc, enabled: _editable, decoration: InputDecoration(hintText: tr('Taxi rendez-vous client, déjeuner avec…')))),
      Row(children: [
        Expanded(child: Field(label: tr('Fournisseur (facultatif)'), child: TextField(controller: _merchant, enabled: _editable, decoration: InputDecoration(hintText: tr('Station, restaurant, hôtel…'))))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Mission / client (facultatif)'), child: TextField(controller: _project, enabled: _editable))),
      ]),
      if (projectsEnabled(context.read<Session>()) && projectChoices(_projectId).isNotEmpty)
        Field(label: tr('Projet (coût imputé au projet)'), child: ProjectDropdown(value: _projectId, enabled: _editable, onChanged: (v) => setState(() => _projectId = v))),
      if (analyticEnabled(context.read<Session>()))
        Field(
          label: tr('Analytique'),
          child: AnalyticField(
            value: _analytic,
            showBudget: true,
            enabled: _editable || (e != null && e['status'] == 'submitted' && (context.read<Session>().can('notes_frais.approve') || context.read<Session>().can('notes_frais.manage'))),
            onChanged: (v) async {
              setState(() => _analytic = v);
              if (!_editable && e != null) {
                await context.read<SyncEngine>().save('nf_expenses', {'id': e['id'], 'analytic': v.isEmpty ? null : v});
                if (context.mounted) toast(context, tr('Ventilation enregistrée.'));
              }
            },
          ),
        ),
      Field(
        label: tr('Date'),
        child: OutlinedButton.icon(
          onPressed: !_editable
              ? null
              : () async {
                  final d = await pickDate(context, initialDate: _date, firstDate: DateTime(2020), lastDate: DateTime.now());
                  if (d != null) setState(() => _date = d);
                },
          icon: const Icon(Icons.event),
          label: Text(tr(dateFr(isoDate(_date)))),
        ),
      ),
      Field(
        label: tr('Justificatif (photo du ticket ou de la facture, PDF)'),
        child: Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          if (_editable) OutlinedButton.icon(onPressed: _pick, icon: const Icon(Icons.photo_camera_outlined), label: Text(tr(_bytes == null ? (e?['has_receipt'] == true ? 'Remplacer' : 'Joindre') : 'Changer'))),
          if (_bytes != null) Chip(avatar: const Icon(Icons.attach_file, size: 16), label: Text(tr(_fileName ?? 'justificatif'))),
          if (_bytes == null && e?['has_receipt'] == true) TextButton.icon(onPressed: () => nfShowReceipt(context, e!), icon: const Icon(Icons.visibility_outlined), label: Text(tr('Voir le justificatif'))),
          if (_bytes == null && e != null && nfReceiptPending('${e['id']}')) Chip(avatar: Icon(Icons.cloud_upload_outlined, size: 16), label: Text(tr('En attente d\'envoi'))),
          if (cat?['receipt_required'] == false && _bytes == null && e?['has_receipt'] != true) Text(tr('Facultatif pour cette catégorie'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        ]),
      ),
      const SizedBox(height: 8),
      if (_editable)
        Row(children: [
          if (e != null)
            TextButton(
              onPressed: _busy
                  ? null
                  : () async {
                      if (await confirm(context, tr('Supprimer cette dépense ?'), tr('Elle sera retirée de vos notes de frais.'), danger: true)) {
                        if (!context.mounted) return;
                        await context.read<SyncEngine>().remove('nf_expenses', '${e['id']}');
                        if (context.mounted) Navigator.pop(context);
                      }
                    },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          OutlinedButton(onPressed: _busy ? null : () => _save(false), child: Text(tr('Brouillon'))),
          const SizedBox(width: 8),
          FilledButton.icon(onPressed: _busy ? null : () => _save(true), icon: const Icon(Icons.send), label: Text(tr('Envoyer'))),
        ])
      else if (e?['status'] == 'submitted')
        Align(
          alignment: Alignment.centerRight,
          child: TextButton.icon(
            onPressed: () async {
              await context.read<SyncEngine>().save('nf_expenses', {'id': e!['id'], 'status': 'draft'});
              if (context.mounted) Navigator.pop(context);
            },
            icon: const Icon(Icons.undo),
            label: Text(tr('Reprendre en brouillon pour modifier')),
          ),
        ),
    ]);
  }
}

// ---------- Carte de l'espace salarié ----------

class MyExpensesCard extends StatefulWidget {
  const MyExpensesCard({super.key, required this.employeeId});

  final String employeeId;

  @override
  State<MyExpensesCard> createState() => _MyExpensesCardState();
}

class _MyExpensesCardState extends State<MyExpensesCard> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (mounted && await nfUploadPending(context) > 0 && mounted) toast(context, tr('Justificatifs en attente envoyés.'));
    });
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('nf_expenses'),
      builder: (context, _, _) {
        final rows = LocalDb.all('nf_expenses').where((e) => e['employee_id'] == widget.employeeId).toList()..sort((a, b) => '${b['date']}${b['created_at'] ?? ''}'.compareTo('${a['date']}${a['created_at'] ?? ''}'));
        double sum(Set<String> st) => rows.where((e) => st.contains(e['status'])).fold(0, (t, e) => t + toDouble(e['amount']));
        return SectionCard(
          title: tr('Mes notes de frais'),
          trailing: TextButton.icon(onPressed: () => editExpense(context, employeeId: widget.employeeId), icon: const Icon(Icons.add, size: 18), label: Text(tr('Nouvelle dépense'))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 16, runSpacing: 6, children: [
              Text(tr('En attente : ${money(sum({'submitted'}), cur)}'), style: const TextStyle(fontWeight: FontWeight.w600)),
              Text(tr('À vous rembourser : ${money(sum({'approved'}), cur)}'), style: const TextStyle(fontWeight: FontWeight.w600, color: YColors.blue)),
              if (rows.any((e) => e['status'] == 'draft' || e['status'] == 'rejected')) Text(tr('${rows.where((e) => e['status'] == 'draft' || e['status'] == 'rejected').length} à compléter'), style: const TextStyle(color: YColors.warning)),
            ]),
            if (rows.isEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('Taxi, carburant, repas d\'affaires… photographiez le ticket et envoyez-le, même sans réseau.'), style: TextStyle(color: Theme.of(context).hintColor))),
            for (final e in rows.take(8))
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(nfReceiptPending('${e['id']}') ? Icons.cloud_upload_outlined : (e['has_receipt'] == true ? Icons.receipt_long : Icons.receipt_outlined), color: nfStatusColor(e['status'])),
                title: Text(tr('${e['description']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text(tr([dateFr(e['date']), nfCategoryName(e['category_id'] as String?), if (e['status'] == 'rejected' && e['decision_note'] != null) 'Refus : ${e['decision_note']}'].join(' · '))),
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text(tr(money(e['amount'], cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                  StatusChip(nfStatuses[e['status']] ?? '${e['status']}', color: nfStatusColor(e['status'])),
                ]),
                onTap: () => editExpense(context, employeeId: widget.employeeId, expense: e),
              ),
          ]),
        );
      },
    );
  }
}

// ======================================================================
// Écran du responsable / de la comptabilité
// ======================================================================

class NotesFraisScreen extends StatelessWidget {
  const NotesFraisScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('notes_frais.manage');
    final tabs = [
      ('À valider', const _ExpenseList(statuses: {'submitted'}, approve: true)),
      if (manage) ('À rembourser', const _ToPayTab()),
      ('Historique', const _ExpenseList(statuses: {'draft', 'submitted', 'approved', 'rejected', 'paid'}, history: true)),
      if (manage) ('Catégories', const _CategoriesTab()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Notes de frais'), subtitle: tr('Dépenses des salariés : validation, remboursement et comptabilisation')),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

String _who(Map e) {
  final emp = LocalDb.hasEntity('rh_employees') ? LocalDb.get('rh_employees', e['employee_id'] as String?) : null;
  return emp == null ? '${e['employee_name'] ?? 'Salarié'}' : empName(emp);
}

class _ExpenseList extends StatefulWidget {
  const _ExpenseList({required this.statuses, this.approve = false, this.history = false});

  final Set<String> statuses;
  final bool approve, history;

  @override
  State<_ExpenseList> createState() => _ExpenseListState();
}

class _ExpenseListState extends State<_ExpenseList> {
  String _status = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  Future<void> _decide(Map e, String status) async {
    String? note;
    if (status == 'rejected') {
      final c = TextEditingController();
      final ok = await showDialog<bool>(
        context: context,
        builder: (d) => YAlertDialog(
          title: Text(tr('Refuser la dépense')),
          content: TextField(controller: c, autofocus: true, decoration: InputDecoration(labelText: tr('Motif (envoyé au salarié)'), hintText: tr('Justificatif illisible, hors politique de frais…'))),
          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Refuser')))],
        ),
      );
      if (ok != true || c.text.trim().isEmpty) return;
      note = c.text.trim();
    }
    if (!mounted) return;
    await context.read<SyncEngine>().save('nf_expenses', {'id': e['id'], 'status': status, 'decision_note': ?note});
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final me = context.read<Session>().user['id'];
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('nf_expenses'),
      builder: (context, _, _) {
        final q = _q.toLowerCase();
        final hasPrj = LocalDb.hasEntity('prj_projects');
        final all = LocalDb.all('nf_expenses').where((e) => widget.statuses.contains(e['status'])).toList();
        bool base(Map<String, dynamic> e) =>
            (q.isEmpty || '${_who(e)} ${e['description']} ${e['merchant'] ?? ''} ${e['project'] ?? ''}'.toLowerCase().contains(q)) &&
            (_f['employee'] == null || '${e['employee_id']}' == _f['employee']) &&
            (_f['category'] == null || '${e['category_id']}' == _f['category']) &&
            (_f['project'] == null || '${e['project_id']}' == _f['project']) &&
            (_f['receipt'] == null || (_f['receipt'] == 'yes') == (e['has_receipt'] == true)) &&
            (_f['limit'] == null || (_f['limit'] == 'over') == (e['over_limit'] == true)) &&
            (_period == null || _period!.contains(e['date']));
        bool st(Map<String, dynamic> e, String k) => k.isEmpty || e['status'] == k;
        final rows = all.where((e) => base(e) && (!widget.history || st(e, _status))).toList()..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
        final total = rows.fold<double>(0, (t, e) => t + toDouble(e['amount']));
        const stKeys = ['', 'submitted', 'approved', 'paid', 'rejected', 'draft'];
        return Column(children: [
          const SizedBox(height: 12),
          FilterBar(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Salarié, objet, mission…',
            statuses: widget.history ? {for (final k in stKeys) k: k.isEmpty ? 'Toutes' : nfStatuses[k]!} : null,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: widget.history ? {for (final k in stKeys) k: all.where((e) => base(e) && st(e, k)).length} : null,
            filters: [
              FilterSpec('employee', 'Salarié', filterOptions(all, 'employee_id', label: _who), icon: Icons.person_outline),
              FilterSpec('category', 'Catégorie', filterOptions(all, 'category_id', label: (e) => nfCategoryName(e['category_id'] as String?)), icon: Icons.category_outlined),
              if (hasPrj)
                FilterSpec('project', 'Projet', filterOptions(all, 'project_id', label: (e) => '${LocalDb.get('prj_projects', '${e['project_id']}')?['name'] ?? e['project_id']}'), icon: Icons.work_outline),
              const FilterSpec('receipt', 'Justificatif', {'yes': 'Avec justificatif', 'no': 'Sans justificatif'}, icon: Icons.receipt_long_outlined),
              const FilterSpec('limit', 'Plafond', {'over': 'Au-delà du plafond', 'ok': 'Dans le plafond'}, icon: Icons.speed),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: rows.length,
            countLabel: 'dépense',
            onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
            trailing: [Text(tr(money(total, cur)), style: const TextStyle(fontWeight: FontWeight.w800))],
          ),
          Expanded(
            child: rows.isEmpty
                ? EmptyState(icon: Icons.request_quote_outlined, title: widget.approve ? 'Rien à valider' : 'Aucune dépense', message: tr('Les salariés saisissent leurs dépenses depuis « Mon espace ».'))
                : ListView.builder(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    itemCount: rows.length,
                    itemBuilder: (_, i) {
                      final e = rows[i];
                      final own = LocalDb.hasEntity('rh_employees') && LocalDb.get('rh_employees', e['employee_id'] as String?)?['user_id'] == me;
                      final narrow = MediaQuery.sizeOf(context).width < 700;
                      final receipt = e['has_receipt'] == true;
                      final meta = [
                        dateFr(e['date']),
                        nfCategoryName(e['category_id'] as String?),
                        if (e['merchant'] != null) '${e['merchant']}',
                        if (e['project'] != null) 'mission : ${e['project']}',
                        if (e['project_id'] != null && LocalDb.hasEntity('prj_projects') && LocalDb.get('prj_projects', '${e['project_id']}') != null) 'projet : ${LocalDb.get('prj_projects', '${e['project_id']}')!['name']}',
                        if (e['over_limit'] == true) 'au-delà du plafond',
                        if (e['decision_note'] != null) '« ${e['decision_note']} »',
                      ].join(' · ');
                      final actions = <Widget>[
                        if (widget.approve && !own) ...[
                          narrow
                              ? OutlinedButton.icon(onPressed: () => _decide(e, 'rejected'), icon: const Icon(Icons.highlight_off, color: YColors.danger, size: 18), label: Text(tr('Refuser')))
                              : IconButton(tooltip: tr('Refuser'), icon: const Icon(Icons.highlight_off, color: YColors.danger), onPressed: () => _decide(e, 'rejected')),
                          narrow
                              ? FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: YColors.success), onPressed: () => _decide(e, 'approved'), icon: const Icon(Icons.check, size: 18), label: Text(tr('Approuver')))
                              : IconButton(tooltip: tr('Approuver'), icon: const Icon(Icons.check_circle_outline, color: YColors.success), onPressed: () => _decide(e, 'approved')),
                        ],
                        if (widget.approve && own) Tooltip(message: tr('Une autre personne doit valider vos dépenses'), child: const Icon(Icons.lock_outline, color: YColors.muted)),
                        if (widget.history && e['status'] == 'approved' && !own)
                          IconButton(tooltip: tr('Annuler la validation'), icon: const Icon(Icons.undo), onPressed: () => _decide(e, 'submitted')),
                      ];
                      final amount = Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisSize: MainAxisSize.min, children: [
                        Text(tr(money(e['amount'], cur)), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: e['over_limit'] == true ? YColors.warning : null)),
                        if (!widget.approve) ...[const SizedBox(height: 4), StatusChip(nfStatuses[e['status']] ?? '', color: nfStatusColor(e['status']))],
                      ]);
                      return Card(
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(12, 12, 14, 12),
                          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              InkWell(
                                borderRadius: BorderRadius.circular(11),
                                onTap: receipt ? () => nfShowReceipt(context, e) : null,
                                child: Tooltip(
                                  message: trn(receipt ? 'Voir le justificatif' : 'Pas de justificatif'),
                                  child: Container(
                                    width: 40,
                                    height: 40,
                                    decoration: BoxDecoration(color: (receipt ? YColors.blue : YColors.muted).withValues(alpha: .1), borderRadius: BorderRadius.circular(11)),
                                    child: Icon(receipt ? Icons.receipt_long : Icons.receipt_outlined, size: 20, color: receipt ? YColors.blue : YColors.muted),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                  Text(tr(_who(e)), style: const TextStyle(fontWeight: FontWeight.w700)),
                                  Text(tr('${e['description']}'), maxLines: 2, overflow: TextOverflow.ellipsis),
                                  const SizedBox(height: 2),
                                  Text(tr(meta), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
                                ]),
                              ),
                              const SizedBox(width: 10),
                              amount,
                              if (!narrow) ...[const SizedBox(width: 6), ...actions],
                            ]),
                            if (narrow && actions.isNotEmpty) ...[
                              const SizedBox(height: 10),
                              Row(mainAxisAlignment: MainAxisAlignment.end, children: [for (final (i, w) in actions.indexed) ...[if (i > 0) const SizedBox(width: 8), w]]),
                            ],
                          ]),
                        ),
                      );
                    },
                  ),
          ),
        ]);
      },
    );
  }
}

class _ToPayTab extends StatefulWidget {
  const _ToPayTab();

  @override
  State<_ToPayTab> createState() => _ToPayTabState();
}

class _ToPayTabState extends State<_ToPayTab> {
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  Future<void> _pay(BuildContext context, String employeeId, List<Map<String, dynamic>> rows) async {
    final s = context.read<Session>();
    final cur = s.currency;
    final selected = {for (final e in rows) '${e['id']}'};
    final accounts = trActive(s) ? trAccounts() : <Map<String, dynamic>>[];
    var via = accounts.isNotEmpty ? 'treasury' : 'cash';
    String? account = accounts.where((a) => a['kind'] == 'cash').firstOrNull?['id'] as String? ?? accounts.firstOrNull?['id'] as String?;
    var method = 'cash';
    var date = DateTime.now();
    List<Map<String, dynamic>> runs = [];
    int? runId;
    if (s.hasModule('paie') && s.can('paie.view')) {
      try {
        final r = await s.api.get('paie/runs');
        runs = [for (final x in ((r is Map ? (r['runs'] ?? r['data']) : r) as List)) if (x['status'] == 'draft') asMap(x)];
        runId = runs.firstOrNull?['id'] as int?;
      } catch (_) {}
    }
    if (!context.mounted) return;
    final ok = await showFormDialog<bool>(context, title: 'Rembourser ${_who(rows.first)}', builder: (c) => StatefulBuilder(
        builder: (c, set) {
          final sum = rows.where((e) => selected.contains(e['id'])).fold<double>(0, (t, e) => t + toDouble(e['amount']));
          return SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
              Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                for (final e in rows)
                  CheckboxListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    value: selected.contains(e['id']),
                    onChanged: (v) => set(() => v == true ? selected.add('${e['id']}') : selected.remove(e['id'])),
                    title: Text(tr('${e['description']}')),
                    subtitle: Text(tr(dateFr(e['date']))),
                    secondary: Text(tr(money(e['amount'], cur))),
                  ),
                const Divider(),
                Text(tr('Total : ${money(sum, cur)}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                const SizedBox(height: 10),
                SegmentedButton<String>(
                  segments: [
                    if (accounts.isNotEmpty) ButtonSegment(value: 'treasury', label: Text(tr('Caisse / banque')), icon: Icon(Icons.savings_outlined)),
                    if (accounts.isEmpty) ButtonSegment(value: 'cash', label: Text(tr('Paiement direct')), icon: Icon(Icons.payments_outlined)),
                    if (runs.isNotEmpty) ButtonSegment(value: 'payroll', label: Text(tr('Sur la paie')), icon: Icon(Icons.receipt_long_outlined)),
                  ],
                  selected: {via},
                  onSelectionChanged: (v) => set(() => via = v.first),
                ),
                const SizedBox(height: 8),
                if (via == 'treasury')
                  DropdownButtonFormField<String>(
                    initialValue: account,
                    decoration: InputDecoration(labelText: tr('Payé depuis')),
                    items: [for (final a in accounts) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}')))],
                    onChanged: (v) => set(() => account = v),
                  ),
                if (via == 'cash')
                  DropdownButtonFormField<String>(
                    initialValue: method,
                    decoration: InputDecoration(labelText: tr('Mode')),
                    items: [DropdownMenuItem(value: 'cash', child: Text(tr('Espèces'))), DropdownMenuItem(value: 'mobile_money', child: Text(tr('Mobile Money'))), DropdownMenuItem(value: 'bank_transfer', child: Text(tr('Virement')))],
                    onChanged: (v) => set(() => method = v!),
                  ),
                if (via != 'payroll')
                  TextButton.icon(
                    onPressed: () async {
                      final d = await pickDate(c, initialDate: date, firstDate: DateTime(2020), lastDate: DateTime.now());
                      if (d != null) set(() => date = d);
                    },
                    icon: const Icon(Icons.event),
                    label: Text(tr('Le ${dateFr(isoDate(date))}')),
                  ),
                if (via == 'payroll') ...[
                  DropdownButtonFormField<int>(
                    initialValue: runId,
                    decoration: InputDecoration(labelText: tr('Paie (brouillon)')),
                    items: [for (final r in runs) DropdownMenuItem(value: r['id'] as int, child: Text(tr('${r['label'] ?? r['period']}')))],
                    onChanged: (v) => set(() => runId = v),
                  ),
                  Text(tr('Ajouté au bulletin hors salaire : ni imposé ni soumis à la CNPS.'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
                ],
              ]),
              const SizedBox(height: 20),
              Align(
                alignment: Alignment.centerRight,
                child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                  TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
                  FilledButton(onPressed: selected.isEmpty ? null : () => Navigator.pop(c, true), child: Text(tr('Rembourser'))),
                ]),
              ),
            ]),
          );
        },
      ));
    if (ok != true || !context.mounted) return;
    try {
      final r = await s.api.post('notes_frais/pay', {
        'employee_id': employeeId,
        'expense_ids': selected.toList(),
        'via': via,
        'account_id': ?account,
        'method': method,
        'date': isoDate(date),
        'run_id': ?runId,
      });
      if (context.mounted) {
        await context.read<SyncEngine>().sync();
        if (context.mounted) toast(context, tr('${money(r['data']['total'], cur)} remboursés${via == 'payroll' ? ' sur la paie' : ''}.'));
      }
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('nf_expenses'),
      builder: (context, _, _) {
        final approved = LocalDb.all('nf_expenses').where((e) => e['status'] == 'approved').toList();
        if (approved.isEmpty) return EmptyState(icon: Icons.payments_outlined, title: tr('Rien à rembourser'), message: tr('Les dépenses approuvées apparaissent ici, regroupées par salarié.'));
        final q = _q.toLowerCase();
        final byEmp = <String, List<Map<String, dynamic>>>{};
        for (final e in approved.where((e) =>
            (q.isEmpty || '${_who(e)} ${e['description']} ${e['merchant'] ?? ''}'.toLowerCase().contains(q)) &&
            (_f['employee'] == null || '${e['employee_id']}' == _f['employee']) &&
            (_f['category'] == null || '${e['category_id']}' == _f['category']) &&
            (_period == null || _period!.contains(e['date'])))) {
          byEmp.putIfAbsent('${e['employee_id']}', () => []).add(e);
        }
        return ListView(padding: const EdgeInsets.all(16), children: [
          FilterBar(
            padding: const EdgeInsets.only(bottom: 8),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Salarié, objet…',
            filters: [
              FilterSpec('employee', 'Salarié', filterOptions(approved, 'employee_id', label: _who), icon: Icons.person_outline),
              FilterSpec('category', 'Catégorie', filterOptions(approved, 'category_id', label: (e) => nfCategoryName(e['category_id'] as String?)), icon: Icons.category_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: byEmp.length,
            countLabel: 'salarié',
            onReset: () => setState(() => (_q = '', _f = {}, _period = null)),
          ),
          if (byEmp.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucune dépense approuvée ne correspond aux filtres.')),
          for (final entry in byEmp.entries)
            Card(
              child: ListTile(
                leading: InitialsAvatar(_who(entry.value.first), size: 38),
                title: Text(tr(_who(entry.value.first)), style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text(tr('${entry.value.length} dépense(s) approuvée(s) — ${entry.value.map((e) => e['description']).take(3).join(', ')}${entry.value.length > 3 ? '…' : ''}')),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  Text(tr(money(entry.value.fold<double>(0, (t, e) => t + toDouble(e['amount'])), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  const SizedBox(width: 12),
                  FilledButton(onPressed: () => _pay(context, entry.key, entry.value), child: Text(tr('Rembourser'))),
                ]),
              ),
            ),
        ]);
      },
    );
  }
}

class _CategoriesTab extends StatefulWidget {
  const _CategoriesTab();

  @override
  State<_CategoriesTab> createState() => _CategoriesTabState();
}

class _CategoriesTabState extends State<_CategoriesTab> {
  Map<String, dynamic>? _settings;
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('notes_frais/settings').then((r) {
      if (mounted) setState(() => _settings = asMap(r['data']));
    }).catchError((_) {});
  }

  Future<void> _edit([Map<String, dynamic>? c]) async {
    final sync = context.read<SyncEngine>();
    final name = TextEditingController(text: '${c?['name'] ?? ''}');
    final gl = TextEditingController(text: '${c?['gl_code'] ?? '638'}');
    final max = TextEditingController(text: c?['max_amount'] == null ? '' : '${toDouble(c!['max_amount']).round()}');
    var receipt = c?['receipt_required'] != false;
    var active = c?['active'] != false;
    final ok = await showFormDialog<bool>(context, title: c == null ? 'Nouvelle catégorie' : '${c['name']}', builder: (d) => StatefulBuilder(
        builder: (d, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'))),
              TextField(controller: gl, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Compte de charge'), helperText: tr('618 transport, 6053 carburant, 6383 réceptions, 6384 missions…'))),
              TextField(controller: max, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Plafond par dépense (facultatif)'))),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Justificatif obligatoire')), value: receipt, onChanged: (v) => set(() => receipt = v)),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Active')), value: active, onChanged: (v) => set(() => active = v)),
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
    if (ok != true || name.text.trim().isEmpty) return;
    await sync.save('nf_categories', {
      'id': c?['id'] ?? newId(),
      'name': name.text.trim(),
      'gl_code': gl.text.trim(),
      'max_amount': max.text.trim().isEmpty ? null : toDouble(max.text),
      'receipt_required': receipt,
      'active': active,
      'sort': c?['sort'] ?? nfCategories(all: true).length,
    });
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('nf_categories'),
      builder: (context, _, _) {
        final all = nfCategories(all: true);
        bool base(Map<String, dynamic> c) =>
            (_q.isEmpty || '${c['name']} ${c['gl_code'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
            (_f['receipt'] == null || (_f['receipt'] == 'required') == (c['receipt_required'] != false)) &&
            (_f['max'] == null || (_f['max'] == 'yes') == (c['max_amount'] != null));
        bool st(Map<String, dynamic> c, String k) => k.isEmpty || (k == 'active') == (c['active'] != false);
        final cats = all.where((c) => base(c) && st(c, _status)).toList();
        return ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          padding: const EdgeInsets.only(bottom: 12),
          onSearch: (v) => setState(() => _q = v),
          search: _q,
          searchHint: 'Nom, compte…',
          statuses: const {'': 'Toutes', 'active': 'Actives', 'inactive': 'Inactives'},
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {for (final k in ['', 'active', 'inactive']) k: all.where((c) => base(c) && st(c, k)).length},
          filters: const [
            FilterSpec('receipt', 'Justificatif', {'required': 'Obligatoire', 'optional': 'Facultatif'}, icon: Icons.receipt_long_outlined),
            FilterSpec('max', 'Plafond', {'yes': 'Avec plafond', 'no': 'Sans plafond'}, icon: Icons.speed),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          count: cats.length,
          countLabel: 'catégorie',
          onReset: () => setState(() => (_q = '', _status = '', _f = {})),
        ),
        SectionCard(
          title: tr('Catégories de dépenses'),
          trailing: TextButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.add, size: 18), label: Text(tr('Catégorie'))),
          child: Column(children: [
            for (final c in cats)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('${c['name']}'), style: TextStyle(decoration: c['active'] == false ? TextDecoration.lineThrough : null)),
                subtitle: Text(tr(['compte ${c['gl_code']}', if (c['max_amount'] != null) 'plafond ${money(c['max_amount'], cur)}', c['receipt_required'] == false ? 'justificatif facultatif' : 'justificatif obligatoire'].join(' · '))),
                onTap: () => _edit(c),
              ),
          ]),
        ),
        const SizedBox(height: 12),
        if (_settings != null)
          Text(tr('Dépenses approuvées : charge au débit, compte ${_settings!['payable_account']} (personnel, frais à rembourser) au crédit ; soldé au remboursement.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
      ]);
      },
    );
  }
}
