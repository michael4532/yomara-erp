import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../pdf/payslip_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'notes_frais_screen.dart' show MyExpensesCard;
import 'pointage_screen.dart' show SelfPunchCard;
import 'rh_documents_screen.dart' show MyDocumentsCard;
import 'rh_positions_screen.dart' show openEmployeeContract;
import 'rh_requests_screen.dart' show MyRequestsCard;
import 'talents_screen.dart' show MyReviewsCard;
import 'rh_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Espace salarié : sa fiche, son solde et ses demandes de congés (hors ligne), ses bulletins (en ligne).
class MonEspaceScreen extends StatefulWidget {
  const MonEspaceScreen({super.key});

  @override
  State<MonEspaceScreen> createState() => _MonEspaceScreenState();
}

class _MonEspaceScreenState extends State<MonEspaceScreen> {
  List<Map<String, dynamic>>? _slips;
  List<Map<String, dynamic>> _contracts = const [];
  Object? _slipError;

  @override
  void initState() {
    super.initState();
    _loadSlips();
  }

  Future<void> _loadSlips() async {
    try {
      final r = await context.read<Session>().api.get('rh/me');
      if (r['linked'] == false) throw ApiException('${r['message']}');
      if (mounted) {
        setState(() => (_slips = [for (final p in (r['payslips'] as List)) asMap(p)], _contracts = [for (final c in (r['contracts'] as List? ?? const [])) asMap(c)], _slipError = null));
      }
    } catch (e) {
      if (mounted) setState(() => _slipError = e);
    }
  }

  Future<Map<String, dynamic>?> _slip(int id) async {
    try {
      return Map<String, dynamic>.from(await context.read<Session>().api.get('rh/me/payslips/$id'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return null;
    }
  }

  Future<void> _print(Map<String, dynamic> p) async {
    final s = context.read<Session>();
    final slip = await _slip((p['id'] as num).toInt());
    if (slip == null) return;
    await printPayslips(company: s.company, currency: s.currency, period: '${p['period']}', payslips: [slip]);
  }

  Future<void> _download(Map<String, dynamic> p) async {
    final s = context.read<Session>();
    final slip = await _slip((p['id'] as num).toInt());
    if (slip == null) return;
    final bytes = await buildPayslipsPdf(company: s.company, currency: s.currency, period: '${p['period']}', payslips: [slip]);
    final where = await saveFile('bulletin_${p['period']}.pdf', bytes, mime: 'application/pdf');
    if (mounted) toast(context, tr('Bulletin enregistré${where.isEmpty ? '' : ' : $where'}'));
  }

  Future<void> _editContact(Map<String, dynamic> e) async {
    final api = context.read<Session>().api;
    final sync = context.read<SyncEngine>();
    final c = {for (final f in ['phone', 'email', 'address', 'emergency_contact']) f: TextEditingController(text: '${e[f] ?? ''}')};
    final ok = await showFormDialog<bool>(context, title: 'Mes coordonnées', builder: (d) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: c['phone'], keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone'))),
            TextField(controller: c['email'], keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: tr('E-mail'))),
            TextField(controller: c['address'], decoration: InputDecoration(labelText: tr('Adresse'))),
            TextField(controller: c['emergency_contact'], decoration: InputDecoration(labelText: tr('Personne à prévenir en cas d\'urgence'), hintText: tr('Nom, lien, téléphone'))),
            const SizedBox(height: 8),
            Text(tr('Pour le reste (situation familiale, RIB, contrat), adressez-vous au service RH.'), style: TextStyle(fontSize: 12, color: Theme.of(d).hintColor)),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ));
    if (ok != true) return;
    try {
      await api.put('rh/me', {for (final f in c.keys) f: c[f]!.text.trim().isEmpty ? null : c[f]!.text.trim()});
      await sync.sync();
      if (mounted) toast(context, tr('Coordonnées mises à jour.'));
    } catch (err) {
      if (mounted) toast(context, errorText(err), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_employees'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('rh_leaves'),
        builder: (context, _, _) {
          final e = myEmployee(s);
          if (e == null) {
            return Column(children: [
              PageHeader(title: tr('Mon espace'), subtitle: s.user['name'] as String?),
              Expanded(
                child: EmptyState(
                  icon: Icons.badge_outlined,
                  title: tr('Aucune fiche salarié reliée'),
                  message: tr('Le service RH doit relier votre compte à votre fiche (champ « Compte de connexion »). Vos bulletins et vos congés apparaîtront ensuite ici.'),
                ),
              ),
            ]);
          }
          final leaves = LocalDb.all('rh_leaves').where((l) => l['employee_id'] == e['id']).toList()..sort((a, b) => '${b['start_date']}'.compareTo('${a['start_date']}'));
          final today = isoDate(DateTime.now());
          final next = leaves.where((l) => l['status'] == 'approved' && '${l['end_date']}'.compareTo(today) >= 0).toList()..sort((a, b) => '${a['start_date']}'.compareTo('${b['start_date']}'));
          final pending = leaves.where((l) => l['status'] == 'requested').length;
          final balance = leaveBalance(e);
          final narrow = MediaQuery.sizeOf(context).width < 700;
          final kpiW = narrow ? (MediaQuery.sizeOf(context).width - 44) / 2 : 230.0;

          return Column(children: [
            PageHeader(
              title: tr('Mon espace'),
              subtitle: '${empName(e)} · ${[e['job_title'], e['department']].where((x) => x != null && '$x'.isNotEmpty).join(' · ')}',
              actions: [FilledButton.icon(onPressed: () => editLeave(context, employeeId: '${e['id']}', self: true), icon: const Icon(Icons.beach_access_outlined), label: Text(tr('Demander un congé')))],
            ),
            Expanded(
              child: RefreshIndicator(
                onRefresh: () async {
                  await context.read<SyncEngine>().sync();
                  await _loadSlips();
                },
                child: ListView(padding: const EdgeInsets.all(16), children: [
                  Wrap(spacing: 12, runSpacing: 12, children: [
                    _Kpi(width: kpiW, icon: Icons.beach_access_outlined, value: '${number(balance, decimals: 1)} j', label: tr('Congés payés disponibles'), color: balance < 0 ? YColors.danger : YColors.success),
                    _Kpi(
                      width: kpiW,
                      icon: Icons.event_available,
                      value: next.isEmpty ? '—' : dateFr(next.first['start_date']),
                      label: next.isEmpty ? 'Aucun congé à venir' : 'Prochain congé (${number(next.first['days'], decimals: 1)} j)',
                    ),
                    _Kpi(width: kpiW, icon: Icons.hourglass_top, value: '$pending', label: tr('Demande(s) en attente'), color: pending > 0 ? YColors.warning : YColors.blue),
                    _Kpi(width: kpiW, icon: Icons.receipt_long_outlined, value: _slips == null ? '…' : '${_slips!.length}', label: tr('Bulletins disponibles')),
                  ]),
                  if (LocalDb.hasEntity('rh_requests')) ...[
                    const SizedBox(height: 12),
                    MyRequestsCard(employeeId: '${e['id']}'),
                  ],
                  if (s.hasModule('talents') && LocalDb.hasEntity('tal_reviews')) ...[
                    const SizedBox(height: 12),
                    MyReviewsCard(employeeId: '${e['id']}'),
                  ],
                  if (s.hasModule('pointage') && LocalDb.hasEntity('pt_badges') && LocalDb.get('pt_badges', '${e['id']}') != null) ...[
                    const SizedBox(height: 12),
                    SelfPunchCard(employeeId: '${e['id']}'),
                  ],
                  if (s.hasModule('notes_frais') && s.can('notes_frais.self') && LocalDb.hasEntity('nf_expenses')) ...[
                    const SizedBox(height: 12),
                    MyExpensesCard(employeeId: '${e['id']}'),
                  ],
                  const SizedBox(height: 12),
                  LayoutBuilder(builder: (context, box) {
                    final wide = box.maxWidth > 900;
                    final leavesCard = SectionCard(
                      title: tr('Mes congés et absences'),
                      trailing: TextButton.icon(onPressed: () => editLeave(context, employeeId: '${e['id']}', self: true), icon: const Icon(Icons.add, size: 18), label: Text(tr('Demander'))),
                      child: Column(children: [
                        if (leaves.isEmpty) Padding(padding: const EdgeInsets.all(12), child: Text(tr('Aucune demande pour l\'instant.'), style: TextStyle(color: Theme.of(context).hintColor))),
                        for (final l in leaves.take(30))
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: Icon(l['_local'] == true ? Icons.cloud_upload_outlined : Icons.event_note, color: leaveStatusColor(l['status'])),
                            title: Text(tr('${leaveTypes[l['type']] ?? l['type']} · ${number(l['days'], decimals: 1)} j')),
                            subtitle: Text(tr([
                              'Du ${dateFr(l['start_date'])} au ${dateFr(l['end_date'])}',
                              if (l['reason'] != null) '${l['reason']}',
                              if (l['decision_note'] != null) 'RH : ${l['decision_note']}',
                              if (l['_local'] == true) 'En attente d\'envoi',
                            ].join('\n'))),
                            isThreeLine: l['reason'] != null || l['decision_note'] != null,
                            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                              StatusChip(leaveStatuses[l['status']] ?? '${l['status']}', color: leaveStatusColor(l['status'])),
                              if (l['status'] == 'requested')
                                IconButton(
                                  tooltip: tr('Annuler ma demande'),
                                  icon: const Icon(Icons.close),
                                  onPressed: () async {
                                    if (await confirm(context, tr('Annuler cette demande ?'), tr('Le service RH en sera informé à la prochaine synchronisation.'), ok: tr('Annuler la demande'))) {
                                      if (!context.mounted) return;
                                      await context.read<SyncEngine>().save('rh_leaves', {'id': l['id'], 'status': 'cancelled'});
                                    }
                                  },
                                ),
                            ]),
                          ),
                      ]),
                    );
                    final slipsCard = SectionCard(
                      title: tr('Mes bulletins de paie'),
                      child: _slipError != null && _slips == null
                          ? OnlineOnlyNotice(error: _slipError!, onRetry: _loadSlips)
                          : _slips == null
                              ? const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()))
                              : Column(children: [
                                  if (_slips!.isEmpty)
                                    Padding(padding: const EdgeInsets.all(12), child: Text(tr('Vos bulletins apparaîtront ici dès la validation de la paie.'), style: TextStyle(color: Theme.of(context).hintColor))),
                                  for (final p in _slips!)
                                    ListTile(
                                      contentPadding: EdgeInsets.zero,
                                      leading: const Icon(Icons.description_outlined, color: YColors.blue),
                                      title: Text(tr('${periodLabel('${p['period']}')[0].toUpperCase()}${periodLabel('${p['period']}').substring(1)}')),
                                      subtitle: Text(tr('Net à payer ${money(p['net'], cur)}${p['status'] == 'paid' ? ' · payé${p['payment_date'] != null ? ' le ${dateFr(p['payment_date'])}' : ''}' : ''}')),
                                      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                                        IconButton(tooltip: tr('Imprimer'), icon: const Icon(Icons.print_outlined), onPressed: () => _print(p)),
                                        IconButton(tooltip: tr('Télécharger le PDF'), icon: const Icon(Icons.download_outlined), onPressed: () => _download(p)),
                                      ]),
                                      onTap: () => _print(p),
                                    ),
                                ]),
                    );
                    return wide
                        ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: leavesCard), const SizedBox(width: 12), Expanded(child: slipsCard)])
                        : Column(children: [leavesCard, const SizedBox(height: 12), slipsCard]);
                  }),
                  if (_contracts.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    SectionCard(
                      title: tr('Mes contrats de travail'),
                      child: Column(children: [
                        for (final c in _contracts)
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: Icon(c['status'] == 'to_sign' ? Icons.draw_outlined : Icons.description_outlined, color: YColors.blue),
                            title: Text(tr('${c['title']}')),
                            subtitle: Text(tr([
                              if (c['start_date'] != null) 'Depuis le ${dateFr(c['start_date'])}',
                              if (c['end_date'] != null) 'jusqu\'au ${dateFr(c['end_date'])}',
                              if (c['signed_on'] != null) 'signé le ${dateFr(c['signed_on'])}',
                              if (c['status'] == 'to_sign') 'à signer : rapprochez-vous du service RH',
                            ].join(' · '))),
                            trailing: StatusChip('${c['status_label']}', color: Color(0xFF000000 | (int.tryParse('${c['status_color']}'.replaceAll('#', ''), radix: 16) ?? 0x64748B))),
                            onTap: () => openEmployeeContract(context, c, 'rh/me/contracts/${c['id']}', self: true),
                          ),
                      ]),
                    ),
                  ],
                  if (LocalDb.hasEntity('rh_documents')) ...[
                    const SizedBox(height: 12),
                    MyDocumentsCard(employeeId: '${e['id']}'),
                  ],
                  const SizedBox(height: 12),
                  SectionCard(
                    title: tr('Ma fiche'),
                    trailing: TextButton.icon(onPressed: () => _editContact(e), icon: const Icon(Icons.edit_outlined, size: 18), label: Text(tr('Mes coordonnées'))),
                    child: Wrap(spacing: 28, runSpacing: 12, children: [
                      _Info('Matricule', e['matricule']),
                      _Info('Contrat', contractTypes[e['contract_type']] ?? e['contract_type']),
                      _Info('Embauche', e['hire_date'] == null ? null : dateFr(e['hire_date'])),
                      _Info('Salaire de base', money(e['base_salary'], cur)),
                      _Info('N° CNPS', e['cnps_number']),
                      _Info('Paiement', salaryPaymentMethods[e['payment_method']]),
                      _Info('Téléphone', e['phone']),
                      _Info('E-mail', e['email']),
                      _Info('Adresse', e['address']),
                      _Info('Urgence', e['emergency_contact']),
                    ]),
                  ),
                ]),
              ),
            ),
          ]);
        },
      ),
    );
  }
}

class _Kpi extends StatelessWidget {
  const _Kpi({required this.width, required this.icon, required this.value, required this.label, this.color = YColors.blue});

  final double width;
  final IconData icon;
  final String value, label;
  final Color color;

  @override
  Widget build(BuildContext context) => SizedBox(width: width, child: StatTile(label: label, value: value, icon: icon, color: color));
}

class _Info extends StatelessWidget {
  const _Info(this.label, this.value);

  final String label;
  final Object? value;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 200,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(label), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          Text(tr(value == null || '$value'.isEmpty ? '—' : '$value'), style: const TextStyle(fontWeight: FontWeight.w600)),
        ]),
      );
}
