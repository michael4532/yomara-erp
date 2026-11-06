import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../pdf/rh_document_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'rh_screen.dart' show empName, rhEmployees, leaveBalance;
import 'signature_screen.dart' show newSignatureRequest;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const docTypes = {'attestation': 'Attestation de travail', 'certificate': 'Certificat de travail', 'contract': 'Contrat de travail', 'warning': 'Avertissement', 'stc': 'Reçu pour solde de tout compte', 'other': 'Autre document'};
const docStatuses = {'requested': 'Demandé', 'draft': 'Brouillon', 'issued': 'Émis', 'cancelled': 'Annulé'};

Color docStatusColor(String? s) => switch (s) {
  'requested' => YColors.warning,
  'issued' => YColors.success,
  'cancelled' => YColors.danger,
  _ => YColors.muted,
};

const _contracts = {'CDI': 'à durée indéterminée (CDI)', 'CDD': 'à durée déterminée (CDD)', 'stage': 'de stage', 'journalier': 'de travail journalier', 'consultant': 'de prestation'};

/// Libellés des champs que la RH complète au moment d'établir le document.
const docExtraLabels = {
  'lieu_travail': 'Lieu de travail',
  'duree_essai': 'Durée de la période d\'essai (ex. 2 mois)',
  'motif_cdd': 'Motif du CDD (surcroît d\'activité, remplacement…)',
  'date_fin_cdd': 'Date de fin du CDD',
  'faits': 'Faits reprochés',
  'montant_stc': 'Montant total',
  'motif_sortie': 'Motif de la sortie',
};

String _moneyFr(num v) {
  final s = v.round().abs().toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(' ');
    b.write(s[i]);
  }
  return '${v < 0 ? '-' : ''}$b F CFA';
}

/// Mêmes champs que le serveur (DocRenderer), calculés sur l'appareil pour travailler hors ligne.
Map<String, String> docFields(Session s, Map e, [Map<String, String> extra = const {}]) {
  final c = s.company;
  final st = s.companySettings;
  final f = e['gender'] == 'F';
  const na = '[à compléter]';
  String opt(Object? v) => v == null || '$v'.trim().isEmpty ? na : '$v';
  return {
    'societe': '${c['name'] ?? ''}',
    'societe_adresse': [c['address'], c['city']].where((x) => x != null && '$x'.isNotEmpty).join(', ').ifEmptyNa(),
    'societe_ville': '${c['city'] ?? 'Abidjan'}',
    'societe_rccm': opt(c['trade_register']),
    'societe_ncc': opt(c['tax_id']),
    'representant': opt(st['rh_signatory']),
    'qualite_representant': () {
      final q = '${st['rh_signatory_title'] ?? 'Le Gérant'}';
      return q.isEmpty ? q : q[0].toLowerCase() + q.substring(1);
    }(),
    'civilite': f ? 'Madame' : (e['gender'] == 'M' ? 'Monsieur' : 'Monsieur / Madame'),
    'ne_e': f ? 'née' : 'né',
    'employe': f ? 'employée' : 'employé',
    'il_elle': f ? 'elle' : 'il',
    'interesse': f ? 'l\'intéressée' : 'l\'intéressé',
    'nom': '${e['last_name'] ?? ''}',
    'prenoms': '${e['first_name'] ?? ''}',
    'nom_complet': empName(e),
    'date_naissance': e['birth_date'] == null ? na : longDateFr('${e['birth_date']}'),
    'piece_identite': opt(e['id_number']),
    'adresse': opt(e['address']),
    'matricule': '${e['matricule'] ?? ''}',
    'fonction': opt(e['job_title']),
    'service': opt(e['department']),
    'categorie': opt(e['category']),
    'type_contrat': _contracts[e['contract_type']] ?? '${e['contract_type'] ?? ''}',
    'date_embauche': longDateFr(e['hire_date'] as String?),
    'date_sortie': e['end_date'] == null ? na : longDateFr('${e['end_date']}'),
    'salaire_base': _moneyFr(toDouble(e['base_salary'])),
    'transport': _moneyFr(toDouble(e['transport_allowance'])),
    'cnps': opt(e['cnps_number']),
    'date': longDateFr(isoDate(DateTime.now())),
    'ville': '${c['city'] ?? 'Abidjan'}',
    ...extra,
  };
}

extension on String {
  String ifEmptyNa() => trim().isEmpty ? '[à compléter]' : this;
}

String docRender(String body, Map<String, String> fields) =>
    body.replaceAllMapped(RegExp(r'\{([a-z_]+)\}'), (m) => fields.containsKey(m.group(1)) ? (fields[m.group(1)]!.isEmpty ? '[à compléter]' : fields[m.group(1)]!) : m.group(0)!);

/// Champs du modèle que la fiche ne connaît pas (à saisir).
List<String> docMissing(String body, Map<String, String> known) =>
    RegExp(r'\{([a-z_]+)\}').allMatches(body).map((m) => m.group(1)!).where((k) => !known.containsKey(k)).toSet().toList();

Future<void> openRhDocument(BuildContext context, Map doc, {bool download = false}) async {
  final s = context.read<Session>();
  final emp = LocalDb.get('rh_employees', doc['employee_id'] as String?);
  final name = emp == null ? '' : empName(emp);
  final st = s.companySettings;
  try {
    if (download) {
      final bytes = await rhDocumentPdf(company: s.company, doc: Map<String, dynamic>.from(doc), employeeName: name, signatory: st['rh_signatory'] as String?, signatoryTitle: st['rh_signatory_title'] as String?);
      final where = await saveFile('${doc['number'] ?? 'document'}.pdf', bytes, mime: 'application/pdf');
      if (context.mounted) toast(context, tr('Document enregistré${where.isEmpty ? '' : ' : $where'}'));
    } else {
      await printRhDocument(company: s.company, doc: Map<String, dynamic>.from(doc), employeeName: name, signatory: st['rh_signatory'] as String?, signatoryTitle: st['rh_signatory_title'] as String?);
    }
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

/// Signature électronique d'un document RH : le salarié par lien, l'employeur dans l'application.
Future<void> signRhDocument(BuildContext context, Map doc) async {
  final s = context.read<Session>();
  final emp = LocalDb.get('rh_employees', doc['employee_id'] as String?);
  final name = emp == null ? '' : empName(emp);
  final st = s.companySettings;
  try {
    final bytes = await rhDocumentPdf(company: s.company, doc: Map<String, dynamic>.from(doc), employeeName: name, signatory: st['rh_signatory'] as String?, signatoryTitle: st['rh_signatory_title'] as String?);
    if (!context.mounted) return;
    await newSignatureRequest(
      context,
      bytes: bytes,
      fileName: '${doc['number'] ?? 'document'}.pdf',
      title: tr('${doc['title']} — ${'${emp?['first_name'] ?? ''} ${emp?['last_name'] ?? ''}'.trim()}'),
      sourceType: 'rh_document',
      sourceId: '${doc['id']}',
      signers: [
        {'name': '${emp?['first_name'] ?? ''} ${emp?['last_name'] ?? ''}'.trim(), 'label': doc['type'] == 'warning' ? 'Le Salarié (reçu)' : 'Le Salarié', 'email': emp?['email'], 'phone': emp?['phone']},
        if (doc['type'] != 'warning') {'user_id': s.user['id'], 'name': s.user['name'], 'label': 'L\'Employeur'},
      ],
    );
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ======================================================================
// Onglet « Documents » de l'écran RH
// ======================================================================

class RhDocumentsTab extends StatefulWidget {
  const RhDocumentsTab({super.key, required this.manage});

  final bool manage;

  @override
  State<RhDocumentsTab> createState() => _RhDocumentsTabState();
}

class _RhDocumentsTabState extends State<RhDocumentsTab> {
  String _status = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_documents'),
      builder: (context, _, _) {
        final all = LocalDb.all('rh_documents')..sort((a, b) => '${b['date']}${b['number'] ?? ''}'.compareTo('${a['date']}${a['number'] ?? ''}'));
        String who(Map<String, dynamic> d) {
          final e = LocalDb.get('rh_employees', d['employee_id'] as String?);
          return e == null ? '—' : empName(e);
        }

        bool base(Map<String, dynamic> d) =>
            (_q.isEmpty || '${d['title'] ?? ''} ${d['number'] ?? ''} ${who(d)} ${d['note'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
            (_f['type'] == null || '${d['type']}' == _f['type']) &&
            (_f['employee'] == null || '${d['employee_id']}' == _f['employee']) &&
            (_f['department'] == null || '${LocalDb.get('rh_employees', d['employee_id'] as String?)?['department'] ?? ''}' == _f['department']) &&
            (_period == null || _period!.contains(d['date']));
        bool st(Map<String, dynamic> d, String k) => k.isEmpty || d['status'] == k;
        final rows = all.where((d) => base(d) && st(d, _status)).toList();
        const stKeys = ['', 'requested', 'draft', 'issued', 'cancelled'];
        return Column(children: [
          const SizedBox(height: 16),
          FilterBar(
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Titre, numéro, salarié…',
            statuses: const {'': 'Tous', 'requested': 'Demandes', 'draft': 'Brouillons', 'issued': 'Émis', 'cancelled': 'Annulés'},
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in stKeys) k: all.where((d) => base(d) && st(d, k)).length},
            filters: [
              const FilterSpec('type', 'Type', docTypes, icon: Icons.description_outlined),
              FilterSpec('employee', 'Salarié', filterOptions(all, 'employee_id', label: who), icon: Icons.person_outline),
              FilterSpec('department', 'Service', filterOptions(rhEmployees(all: true), 'department'), icon: Icons.apartment_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: rows.length,
            countLabel: 'document',
            onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
            trailing: [
              if (widget.manage) TextButton.icon(onPressed: () => showDialog(context: context, builder: (_) => const _TemplatesDialog()), icon: const Icon(Icons.description_outlined, size: 18), label: Text(tr('Modèles et signataire'))),
            ],
          ),
          Expanded(
            child: rows.isEmpty
                ? EmptyState(icon: Icons.description_outlined, title: tr('Aucun document'), message: tr('Contrats, attestations, certificats de travail, avertissements et soldes de tout compte, établis à partir de la fiche du salarié.'))
                : ListView.builder(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    itemCount: rows.length,
                    itemBuilder: (_, i) {
                      final d = rows[i];
                      final emp = LocalDb.get('rh_employees', d['employee_id'] as String?);
                      return Card(
                        child: ListTile(
                          leading: DialogIconBadge(
                            icon: Icon(d['type'] == 'contract' ? Icons.handshake_outlined : (d['type'] == 'stc' ? Icons.receipt_long_outlined : (d['type'] == 'warning' ? Icons.report_gmailerrorred_outlined : Icons.description_outlined))),
                            color: docStatusColor(d['status']),
                            size: 40,
                          ),
                          title: Text(tr('${d['title']} — ${emp == null ? '' : empName(emp)}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          subtitle: Text(tr([dateFr(d['date']), if (d['number'] != null) '${d['number']}', if (d['note'] != null) '« ${d['note']} »'].join(' · '))),
                          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                            StatusChip(docStatuses[d['status']] ?? '', color: docStatusColor(d['status'])),
                            if (d['status'] == 'issued' || d['status'] == 'cancelled') ...[
                              IconButton(tooltip: tr('Imprimer'), icon: const Icon(Icons.print_outlined), onPressed: () => openRhDocument(context, d)),
                              IconButton(tooltip: tr('PDF'), icon: const Icon(Icons.download_outlined), onPressed: () => openRhDocument(context, d, download: true)),
                            ],
                          ]),
                          onTap: widget.manage ? () => editRhDocument(context, doc: d) : null,
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

// ---------- Établir un document ----------

Future<void> editRhDocument(BuildContext context, {Map<String, dynamic>? doc, String? employeeId, String? type}) =>
    showFormDialog(context, title: doc == null ? 'Nouveau document' : '${doc['title']}', builder: (_) => _DocForm(doc: doc, employeeId: employeeId, type: type));

class _DocForm extends StatefulWidget {
  const _DocForm({this.doc, this.employeeId, this.type});

  final Map<String, dynamic>? doc;
  final String? employeeId;
  final String? type;

  @override
  State<_DocForm> createState() => _DocFormState();
}

class _DocFormState extends State<_DocForm> {
  late String? _emp = (widget.doc?['employee_id'] as String?) ?? widget.employeeId;
  late String? _tpl = widget.doc?['status'] == 'draft' && LocalDb.get('rh_doc_templates', widget.doc?['template_id'] as String?) != null ? widget.doc!['template_id'] as String : null;
  late final _title = TextEditingController(text: '${widget.doc?['title'] ?? ''}');
  late final _body = TextEditingController(text: '${widget.doc?['body'] ?? ''}');
  final Map<String, TextEditingController> _extra = {};
  late String _type = '${widget.doc?['type'] ?? 'attestation'}';
  late DateTime _date = DateTime.tryParse('${widget.doc?['date'] ?? ''}') ?? DateTime.now();
  List<Map<String, dynamic>> _lines = [];
  String _motif = 'démission';
  bool _busy = false;

  bool get _locked => widget.doc != null && ['issued', 'cancelled'].contains(widget.doc!['status']);

  @override
  void initState() {
    super.initState();
    final d = widget.doc;
    if (d?['data'] is Map) {
      _lines = [for (final l in ((d!['data']['lines'] as List?) ?? [])) asMap(l)];
      _motif = '${d['data']['motif'] ?? _motif}';
    }
    // Demande d'un salarié : on part du modèle correspondant
    if (d != null && d['status'] == 'requested') {
      final tpl = (d['template_id'] != null ? LocalDb.get('rh_doc_templates', d['template_id'] as String?) : null) ?? _templates().where((t) => t['type'] == d['type']).firstOrNull;
      if (tpl != null) WidgetsBinding.instance.addPostFrameCallback((_) => _apply('${tpl['id']}'));
    }
    // Contrat proposé après une embauche : modèle CDD si le salarié est en CDD, sinon CDI
    if (d == null && widget.type == 'contract' && _emp != null) {
      final cdd = LocalDb.get('rh_employees', _emp)?['contract_type'] == 'CDD';
      final list = _templates().where((t) => t['type'] == 'contract').toList();
      final tpl = list.where((t) => '${t['name']}'.contains(cdd ? 'déterminée' : 'indéterminée')).firstOrNull ?? list.firstOrNull;
      if (tpl != null) WidgetsBinding.instance.addPostFrameCallback((_) => _apply('${tpl['id']}'));
    }
  }

  List<Map<String, dynamic>> _templates() => LocalDb.all('rh_doc_templates').where((t) => t['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));

  Map<String, String> _known() {
    final e = LocalDb.get('rh_employees', _emp);
    if (e == null) return {};
    final total = _lines.fold<double>(0, (t, l) => t + toDouble(l['amount']));
    return docFields(context.read<Session>(), e, {
      for (final k in _extra.keys) if (_extra[k]!.text.trim().isNotEmpty) k: _extra[k]!.text.trim(),
      if (_type == 'stc') 'montant_stc': _moneyFr(total),
      if (_type == 'stc') 'motif_sortie': _motif,
      // Sortie pas encore saisie sur la fiche : date du document
      if ((_type == 'stc' || _type == 'certificate') && e['end_date'] == null) 'date_sortie': longDateFr(isoDate(_date)),
    });
  }

  void _apply(String tplId) {
    final t = LocalDb.get('rh_doc_templates', tplId);
    if (t == null) return;
    setState(() {
      _tpl = tplId;
      _type = '${t['type']}';
      _title.text = '${t['name']}'.replaceAll(RegExp(r' à durée (in)?déterminée$'), '');
      if (_type == 'contract') _title.text = '${t['name']}';
      for (final k in docMissing('${t['body']}', _known())) {
        if (k == 'montant_stc' || k == 'motif_sortie') continue;
        _extra.putIfAbsent(k, () => TextEditingController());
      }
      if (_type == 'stc' && _lines.isEmpty) _suggestStc();
      _body.text = docRender('${t['body']}', _known());
    });
  }

  void _rerender() {
    if (_tpl == null) return;
    final t = LocalDb.get('rh_doc_templates', _tpl);
    if (t != null) setState(() => _body.text = docRender('${t['body']}', _known()));
  }

  /// Propositions indicatives (à vérifier) : salaire du dernier mois au prorata, congés non pris, indemnité de licenciement.
  void _suggestStc() {
    final e = LocalDb.get('rh_employees', _emp);
    if (e == null) return;
    final end = DateTime.tryParse('${e['end_date'] ?? ''}') ?? _date;
    final fixed = ((e['fixed_items'] as List?) ?? []).fold<double>(0, (t, i) => t + toDouble((i as Map)['amount']));
    final monthly = toDouble(e['base_salary']) + fixed;
    final lines = <Map<String, dynamic>>[
      {'label': 'Salaire du ${1}er au ${end.day} ${['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][end.month - 1]} (prorata)', 'amount': ((monthly + toDouble(e['transport_allowance'])) / 30 * end.day).roundToDouble()},
    ];
    final days = leaveBalance(e, at: end);
    if (days > 0) lines.add({'label': 'Indemnité compensatrice de congés payés (${number(days, decimals: 1)} j)', 'amount': (monthly / 26 * days).roundToDouble()});
    if (_motif == 'licenciement') {
      final hire = DateTime.tryParse('${e['hire_date'] ?? ''}');
      if (hire != null) {
        final years = end.difference(hire).inDays / 365.25;
        var ind = 0.0;
        for (var y = 0; y < years.ceil(); y++) {
          final part = (years - y).clamp(0, 1).toDouble();
          ind += monthly * part * (y < 5 ? 0.30 : (y < 10 ? 0.35 : 0.40));
        }
        lines.add({'label': 'Indemnité de licenciement (${number(years, decimals: 1)} ans d\'ancienneté)', 'amount': ind.roundToDouble()});
      }
    }
    _lines = lines;
  }

  Future<void> _save(String status) async {
    if (_emp == null || _title.text.trim().isEmpty) return toast(context, tr('Choisissez le salarié et le modèle.'), error: true);
    if (status == 'issued' && _body.text.contains('[à compléter]') && !await confirm(context, tr('Champs à compléter'), tr('Le document contient encore « [à compléter] ». L\'émettre quand même ?'))) return;
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      final total = _lines.fold<double>(0, (t, l) => t + toDouble(l['amount']));
      await context.read<SyncEngine>().save('rh_documents', {
        'id': widget.doc?['id'] ?? newId(),
        'employee_id': _emp,
        'template_id': _tpl ?? widget.doc?['template_id'],
        'type': _type,
        'title': _title.text.trim(),
        'date': isoDate(_date),
        'body': _body.text.trim(),
        if (_type == 'stc') 'data': {'lines': _lines, 'total': total, 'motif': _motif},
        'status': status,
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = widget.doc;
    if (_locked) {
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Text(tr('${d!['number'] ?? ''} · ${dateFr(d['date'])} · ${docStatuses[d['status']]}'), style: TextStyle(color: Theme.of(context).hintColor)),
        const SizedBox(height: 10),
        Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(10)), child: SelectableText(tr('${d['body'] ?? ''}'))),
        const SizedBox(height: 12),
        Row(children: [
          if (d['status'] == 'issued')
            TextButton(
              onPressed: () async {
                final c = TextEditingController();
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (x) => YAlertDialog(
                    title: Text(tr('Annuler le document')),
                    content: TextField(controller: c, decoration: InputDecoration(labelText: tr('Motif'))),
                    actions: [TextButton(onPressed: () => Navigator.pop(x, false), child: Text(tr('Retour'))), FilledButton(onPressed: () => Navigator.pop(x, true), child: Text(tr('Annuler le document')))],
                  ),
                );
                if (ok == true && context.mounted) {
                  await context.read<SyncEngine>().save('rh_documents', {'id': d['id'], 'status': 'cancelled', 'note': c.text.trim().isEmpty ? null : c.text.trim()});
                  if (context.mounted) Navigator.pop(context);
                }
              },
              child: Text(tr('Annuler le document'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          if (d['status'] == 'issued' && ['contract', 'stc', 'warning'].contains(d['type']) && context.read<Session>().hasModule('signature') && context.read<Session>().can('signature.manage'))
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: OutlinedButton.icon(onPressed: () => signRhDocument(context, d), icon: const Icon(Icons.draw_outlined), label: Text(tr('Faire signer'))),
            ),
          OutlinedButton.icon(onPressed: () => openRhDocument(context, d, download: true), icon: const Icon(Icons.download_outlined), label: Text(tr('PDF'))),
          const SizedBox(width: 8),
          FilledButton.icon(onPressed: () => openRhDocument(context, d), icon: const Icon(Icons.print_outlined), label: Text(tr('Imprimer'))),
        ]),
      ]);
    }
    final employees = rhEmployees(all: true);
    final templates = _templates();
    final cur = context.read<Session>().currency;
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      if (d?['status'] == 'requested')
        Container(
          margin: const EdgeInsets.only(bottom: 12),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(color: YColors.warning.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(10)),
          child: Text(tr('Demandé par le salarié le ${dateFr(d!['date'])}${d['note'] != null ? ' : ${d['note']}' : ''}')),
        ),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Salarié'),
            child: DropdownButtonFormField<String>(
              initialValue: employees.any((e) => e['id'] == _emp) ? _emp : null,
              isExpanded: true,
              items: [for (final e in employees) DropdownMenuItem(value: '${e['id']}', child: Text(tr('${empName(e)}${e['active'] == false ? ' (sorti)' : ''}')))],
              onChanged: d?['status'] == 'requested'
                  ? null
                  : (v) {
                      setState(() => _emp = v);
                      if (_type == 'stc') _suggestStc();
                      _rerender();
                    },
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Modèle'),
            child: DropdownButtonFormField<String>(
              initialValue: _tpl,
              isExpanded: true,
              hint: Text(tr('Choisir un modèle')),
              items: [for (final t in templates) DropdownMenuItem(value: '${t['id']}', child: Text(tr('${t['name']}'), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => _apply(v!),
            ),
          ),
        ),
      ]),
      Row(children: [
        Expanded(child: Field(label: tr('Titre'), child: TextField(controller: _title))),
        const SizedBox(width: 12),
        SizedBox(
          width: 170,
          child: Field(
            label: tr('Date'),
            child: OutlinedButton.icon(
              onPressed: () async {
                final x = await pickDate(context, initialDate: _date, firstDate: DateTime(2015), lastDate: DateTime(2100));
                if (x != null) setState(() => _date = x);
              },
              icon: const Icon(Icons.event),
              label: Text(tr(dateFr(isoDate(_date)))),
            ),
          ),
        ),
      ]),
      for (final k in _extra.keys)
        Field(
          label: docExtraLabels[k] ?? k.replaceAll('_', ' '),
          child: TextField(controller: _extra[k], maxLines: k == 'faits' ? 3 : 1, onChanged: (_) => _rerender()),
        ),
      if (_type == 'stc') ...[
        Field(
          label: tr('Motif de la sortie'),
          child: Wrap(spacing: 6, children: [
            for (final m in ['démission', 'fin de CDD', 'licenciement', 'rupture d\'un commun accord', 'retraite'])
              ChoiceChip(
                label: Text(tr(m)),
                selected: _motif == m,
                onSelected: (_) {
                  setState(() {
                    _motif = m;
                    _suggestStc();
                  });
                  _rerender();
                },
              ),
          ]),
        ),
        Text(tr('Sommes dues (propositions à vérifier)'), style: TextStyle(fontWeight: FontWeight.w600)),
        for (var i = 0; i < _lines.length; i++)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(children: [
              Expanded(flex: 3, child: TextFormField(key: ValueKey('l$i${_lines[i]['label']}'), initialValue: '${_lines[i]['label']}', onChanged: (v) => _lines[i]['label'] = v)),
              const SizedBox(width: 8),
              SizedBox(
                width: 140,
                child: TextFormField(
                  key: ValueKey('a$i${_lines[i]['amount']}'),
                  initialValue: '${toDouble(_lines[i]['amount']).round()}',
                  keyboardType: const TextInputType.numberWithOptions(signed: true),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[-0-9]'))],
                  textAlign: TextAlign.right,
                  onChanged: (v) {
                    _lines[i]['amount'] = toDouble(v);
                    _rerender();
                  },
                ),
              ),
              IconButton(
                icon: const Icon(Icons.close),
                onPressed: () {
                  setState(() => _lines.removeAt(i));
                  _rerender();
                },
              ),
            ]),
          ),
        Row(children: [
          TextButton.icon(onPressed: () => setState(() => _lines.add({'label': 'Préavis non effectué', 'amount': 0})), icon: const Icon(Icons.add), label: Text(tr('Ligne'))),
          TextButton.icon(onPressed: () => setState(() => _lines.add({'label': 'Retenue : avance sur salaire', 'amount': 0})), icon: const Icon(Icons.remove), label: Text(tr('Retenue'))),
          const Spacer(),
          Text(tr('Total : ${money(_lines.fold<double>(0, (t, l) => t + toDouble(l['amount'])), cur)}'), style: const TextStyle(fontWeight: FontWeight.w800)),
        ]),
        Text(tr('Indemnité de congés : jours restants × salaire ÷ 26 ; licenciement : 30 % du salaire mensuel par année jusqu\'à 5 ans, 35 % de 6 à 10 ans, 40 % au-delà. Vérifiez avec la convention collective applicable.'),
            style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
        const SizedBox(height: 10),
      ],
      Field(label: tr('Texte du document (modifiable)'), child: TextField(controller: _body, maxLines: 14, minLines: 8, style: const TextStyle(fontSize: 13, height: 1.4))),
      Text(tr('**Texte en gras** entre doubles astérisques. Les champs « [à compléter] » sont à renseigner sur la fiche ou ci-dessus.'), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
      const SizedBox(height: 12),
      Row(children: [
        if (d != null && d['status'] != 'issued')
          TextButton(
            onPressed: () async {
              if (d['status'] == 'requested') {
                final c = TextEditingController();
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (x) => YAlertDialog(
                    title: Text(tr('Refuser la demande')),
                    content: TextField(controller: c, autofocus: true, decoration: InputDecoration(labelText: tr('Motif (envoyé au salarié)'))),
                    actions: [TextButton(onPressed: () => Navigator.pop(x, false), child: Text(tr('Retour'))), FilledButton(onPressed: () => Navigator.pop(x, true), child: Text(tr('Refuser')))],
                  ),
                );
                if (ok != true || !context.mounted) return;
                await context.read<SyncEngine>().save('rh_documents', {'id': d['id'], 'status': 'cancelled', 'note': c.text.trim().isEmpty ? 'Demande refusée' : c.text.trim()});
              } else {
                await context.read<SyncEngine>().remove('rh_documents', '${d['id']}');
              }
              if (context.mounted) Navigator.pop(context);
            },
            child: Text(tr(d['status'] == 'requested' ? 'Refuser la demande' : 'Supprimer'), style: const TextStyle(color: YColors.danger)),
          ),
        const Spacer(),
        OutlinedButton(onPressed: _busy ? null : () => _save('draft'), child: Text(tr('Brouillon'))),
        const SizedBox(width: 8),
        FilledButton.icon(onPressed: _busy ? null : () => _save('issued'), icon: const Icon(Icons.verified_outlined), label: Text(tr('Émettre'))),
      ]),
    ]);
  }
}

// ---------- Modèles et signataire ----------

class _TemplatesDialog extends StatefulWidget {
  const _TemplatesDialog();

  @override
  State<_TemplatesDialog> createState() => _TemplatesDialogState();
}

class _TemplatesDialogState extends State<_TemplatesDialog> {
  Future<void> _signatory() async {
    final s = context.read<Session>();
    final n = TextEditingController(text: '${s.companySettings['rh_signatory'] ?? ''}');
    final q = TextEditingController(text: '${s.companySettings['rh_signatory_title'] ?? 'Le Gérant'}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr('Signataire des documents')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: n, decoration: InputDecoration(labelText: tr('Nom'))),
          TextField(controller: q, decoration: InputDecoration(labelText: tr('Qualité'), hintText: tr('Le Gérant, La Directrice des ressources humaines…'))),
        ]),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok != true) return;
    try {
      await s.api.put('rh/documents/settings', {'rh_signatory': n.text.trim(), 'rh_signatory_title': q.text.trim()});
      await s.refreshBootstrap();
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _edit([Map<String, dynamic>? t]) async {
    final sync = context.read<SyncEngine>();
    final name = TextEditingController(text: '${t?['name'] ?? ''}');
    final body = TextEditingController(text: '${t?['body'] ?? ''}');
    var type = '${t?['type'] ?? 'other'}';
    var self = t?['self_service'] == true;
    var active = t?['active'] != false;
    final ok = await showFormDialog<bool>(context, title: t == null ? 'Nouveau modèle' : '${t['name']}', maxWidth: 688, builder: (d) => StatefulBuilder(
        builder: (d, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'))),
              DropdownButtonFormField<String>(
                initialValue: type,
                decoration: InputDecoration(labelText: tr('Type')),
                items: [for (final e in docTypes.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => set(() => type = v!),
              ),
              const SizedBox(height: 8),
              TextField(controller: body, maxLines: 16, minLines: 8, style: const TextStyle(fontSize: 13), decoration: InputDecoration(labelText: tr('Texte'))),
              const SizedBox(height: 6),
              Text(
                tr('Champs : {nom_complet} {civilite} {ne_e} {date_naissance} {matricule} {fonction} {service} {categorie} {type_contrat} {date_embauche} {date_sortie} {salaire_base} {transport} {cnps} {adresse} {piece_identite} {societe} {societe_adresse} {societe_rccm} {societe_ncc} {representant} {qualite_representant} {date} {ville}. Tout autre {champ} est demandé au moment d\'établir le document.'),
                style: TextStyle(fontSize: 11, color: Theme.of(d).hintColor),
              ),
              if (type == 'attestation' || type == 'other') SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Libre-service')), subtitle: Text(tr('Le salarié l\'obtient seul depuis son espace')), value: self, onChanged: (v) => set(() => self = v)),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Actif')), value: active, onChanged: (v) => set(() => active = v)),
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
    if (ok != true || name.text.trim().isEmpty || body.text.trim().isEmpty) return;
    await sync.save('rh_doc_templates', {'id': t?['id'] ?? newId(), 'name': name.text.trim(), 'type': type, 'body': body.text, 'self_service': self && (type == 'attestation' || type == 'other'), 'active': active});
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return YAlertDialog(
      title: Text(tr('Modèles de documents')),
      content: SizedBox(
        width: 600,
        height: 460,
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('rh_doc_templates'),
          builder: (context, _, _) {
            final rows = LocalDb.all('rh_doc_templates')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
            return ListView(children: [
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.draw_outlined),
                title: Text(tr('Signataire : ${s.companySettings['rh_signatory'] ?? 'à définir'}')),
                subtitle: Text(tr('${s.companySettings['rh_signatory_title'] ?? 'Le Gérant'}')),
                trailing: TextButton(onPressed: _signatory, child: Text(tr('Modifier'))),
              ),
              const Divider(),
              for (final t in rows)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(tr('${t['name']}'), style: TextStyle(decoration: t['active'] == false ? TextDecoration.lineThrough : null)),
                  subtitle: Text(tr('${docTypes[t['type']] ?? t['type']}${t['self_service'] == true ? ' · libre-service' : ''}')),
                  onTap: () => _edit(t),
                ),
              const SizedBox(height: 8),
              Text(tr('Modèles fournis à titre indicatif : faites relire vos contrats par un conseil.'), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
            ]);
          },
        ),
      ),
      actions: [TextButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.add), label: Text(tr('Modèle'))), TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

// ======================================================================
// Espace salarié : mes documents
// ======================================================================

class MyDocumentsCard extends StatelessWidget {
  const MyDocumentsCard({super.key, required this.employeeId});

  final String employeeId;

  Future<void> _request(BuildContext context) async {
    final sync = context.read<SyncEngine>();
    final self = LocalDb.hasEntity('rh_doc_templates') ? LocalDb.all('rh_doc_templates').where((t) => t['self_service'] == true && t['active'] != false).toList() : <Map<String, dynamic>>[];
    final note = TextEditingController();
    final choice = await showDialog<String>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr('Obtenir un document')),
        content: SizedBox(
          width: 440,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            for (final t in self)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.flash_on, color: YColors.success),
                title: Text(tr('${t['name']}')),
                subtitle: Text(tr('Immédiat : établie à partir de votre fiche')),
                onTap: () => Navigator.pop(d, 'tpl:${t['id']}'),
              ),
            const Divider(),
            Text(tr('Demander au service RH'), style: TextStyle(fontWeight: FontWeight.w600)),
            TextField(controller: note, decoration: InputDecoration(labelText: tr('Précisions (facultatif)'), hintText: tr('Pour un dossier de prêt, un visa…'))),
            const SizedBox(height: 6),
            Wrap(spacing: 8, children: [
              if (self.isEmpty) OutlinedButton(onPressed: () => Navigator.pop(d, 'attestation'), child: Text(tr('Attestation de travail'))),
              OutlinedButton(onPressed: () => Navigator.pop(d, 'certificate'), child: Text(tr('Certificat de travail'))),
              OutlinedButton(onPressed: () => Navigator.pop(d, 'other'), child: Text(tr('Autre document'))),
            ]),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler')))],
      ),
    );
    if (choice == null) return;
    final tpl = choice.startsWith('tpl:') ? choice.substring(4) : null;
    final type = tpl == null ? choice : '${LocalDb.get('rh_doc_templates', tpl)?['type'] ?? 'attestation'}';
    try {
      await sync.save('rh_documents', {
        'id': newId(),
        'employee_id': employeeId,
        'template_id': tpl,
        'type': type,
        'title': tpl == null ? docTypes[type]! : '${LocalDb.get('rh_doc_templates', tpl)?['name']}',
        'date': isoDate(DateTime.now()),
        'status': tpl == null ? 'requested' : 'issued',
        'note': note.text.trim().isEmpty ? null : note.text.trim(),
      });
      await sync.sync();
      if (context.mounted) toast(context, tpl == null ? 'Demande envoyée au service RH.' : 'Votre document est prêt (il apparaît dès la synchronisation).');
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_documents'),
      builder: (context, _, _) {
        final rows = LocalDb.all('rh_documents').where((d) => d['employee_id'] == employeeId && d['status'] != 'cancelled').toList()..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
        return SectionCard(
          title: tr('Mes documents'),
          trailing: TextButton.icon(onPressed: () => _request(context), icon: const Icon(Icons.add, size: 18), label: Text(tr('Obtenir un document'))),
          child: Column(children: [
            if (rows.isEmpty) Padding(padding: const EdgeInsets.all(8), child: Text(tr('Attestation de travail, certificat… obtenez-les ici.'), style: TextStyle(color: Theme.of(context).hintColor))),
            for (final d in rows.take(10))
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(Icons.description_outlined, color: docStatusColor(d['status'])),
                title: Text(tr('${d['title']}')),
                subtitle: Text(tr([dateFr(d['date']), if (d['number'] != null) '${d['number']}', if (d['status'] == 'requested' && d['note'] != null) '${d['note']}', if (d['status'] == 'requested') 'en attente du service RH', if (d['status'] == 'issued' && (d['body'] == null || '${d['body']}'.isEmpty)) 'en préparation…'].join(' · '))),
                trailing: d['status'] == 'issued' && '${d['body'] ?? ''}'.isNotEmpty
                    ? Row(mainAxisSize: MainAxisSize.min, children: [
                        IconButton(tooltip: tr('Imprimer'), icon: const Icon(Icons.print_outlined), onPressed: () => openRhDocument(context, d)),
                        IconButton(tooltip: tr('PDF'), icon: const Icon(Icons.download_outlined), onPressed: () => openRhDocument(context, d, download: true)),
                      ])
                    : StatusChip(docStatuses[d['status']] ?? '', color: docStatusColor(d['status'])),
              ),
          ]),
        );
      },
    );
  }
}
