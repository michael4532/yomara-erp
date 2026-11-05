import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'rh_documents_screen.dart' show docTypes;
import 'rh_screen.dart' show leaveTypes;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _statusFr = {
  'requested': 'Demandé', 'approved': 'Accordé', 'rejected': 'Refusé', 'cancelled': 'Annulé', 'issued': 'Émis', 'draft': 'Brouillon', 'validated': 'Validé', 'paid': 'Payé',
  'active': 'En vigueur', 'expired': 'Échu', 'terminated': 'Résilié', 'submitted': 'Soumise',
};

/// Dossier complet d'un salarié : contrats et documents, salaires versés année par année, congés, notes de frais.
Future<void> showEmployeeDossier(BuildContext context, Map<String, dynamic> employee) => showFormDialog(
      context,
      maxWidth: 860,
      title: tr('Dossier — ${'${employee['first_name'] ?? ''} ${employee['last_name'] ?? ''}'.trim()}'),
      builder: (_) => _Dossier(id: '${employee['id']}'),
    );

class _Dossier extends StatefulWidget {
  const _Dossier({required this.id});

  final String id;

  @override
  State<_Dossier> createState() => _DossierState();
}

class _DossierState extends State<_Dossier> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('rh/employees/${widget.id}/dossier').then((r) {
      if (mounted) setState(() => _d = asMap(r));
    }, onError: (e) {
      if (mounted) setState(() => _error = e);
    });
  }

  List<Map<String, dynamic>> _list(String k) => [for (final x in (_d![k] as List? ?? const [])) asMap(x)];

  @override
  Widget build(BuildContext context) {
    if (_error != null) return SizedBox(height: 300, child: OnlineOnlyNotice(error: _error!, onRetry: () {}));
    if (_d == null) return const SizedBox(height: 300, child: Center(child: CircularProgressIndicator()));
    final cur = context.read<Session>().currency;
    final e = asMap(_d!['employee']);
    final t = asMap(_d!['totals']);
    final months = _d!['seniority_months'] as int?;
    final hint = Theme.of(context).hintColor;
    final docs = _list('documents'), contracts = _list('contracts'), years = _list('salary_years'), slips = _list('payslips'), leaves = _list('leaves'), exp = _list('expenses');
    Widget empty(String s) => Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Text(tr(s), style: TextStyle(color: hint)));
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          InitialsAvatar('${e['first_name']} ${e['last_name']}', size: 52),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('${e['first_name']} ${e['last_name']}'), style: Theme.of(context).textTheme.titleLarge),
              Text(tr([e['matricule'], e['job_title'], e['department'], e['contract_type']].where((x) => x != null && '$x'.isNotEmpty).join(' · ')), style: TextStyle(color: hint)),
              Text(tr('Embauche le ${dateFr(e['hire_date'])}${months != null ? ' · ancienneté ${months ~/ 12} an(s) ${months % 12} mois' : ''}${e['active'] == false ? ' · sorti le ${dateFr(e['end_date'])}' : ''}'),
                  style: TextStyle(color: hint, fontSize: 12.5)),
            ]),
          ),
        ]),
        const SizedBox(height: 14),
        StatStrip(padding: EdgeInsets.zero, tiles: [
          StatTile(label: tr('Salaire de base'), value: money(toDouble(e['base_salary']), cur), icon: Icons.payments_outlined),
          StatTile(label: tr('Net versé (total)'), value: money(toDouble(t['net_paid']), cur), detail: tr('${t['months']} bulletin(s)'), icon: Icons.account_balance_wallet_outlined, color: YColors.success),
          StatTile(label: tr('Coût employeur'), value: money(toDouble(t['cost']), cur), detail: tr('brut ${money(toDouble(t['gross']), cur)}'), icon: Icons.business_center_outlined, color: const Color(0xFF9333EA)),
          StatTile(label: tr('Solde de congés'), value: '${number(toDouble(_d!['leave_balance']), decimals: 1)} j', icon: Icons.beach_access_outlined, color: const Color(0xFFD97706)),
        ]),
        const SizedBox(height: 14),
        SectionCard(
          title: tr('Contrats & documents'),
          child: docs.isEmpty && contracts.isEmpty
              ? empty('Aucun contrat ni document. Rédigez-les dans l\'onglet Documents (RH) ou au Juridique.')
              : Column(children: [
                  for (final c in contracts)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.gavel_outlined, color: YColors.blue),
                      title: Text(tr('${c['title']}')),
                      subtitle: Text(tr('Juridique · ${dateFr(c['start_date'])}${c['end_date'] != null ? ' → ${dateFr(c['end_date'])}' : ''}')),
                      trailing: StatusChip(_statusFr[c['status']] ?? '${c['status']}', color: c['status'] == 'active' ? YColors.success : YColors.muted),
                    ),
                  for (final d in docs)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(d['type'] == 'contract' ? Icons.description_outlined : Icons.article_outlined, color: YColors.blue),
                      title: Text(tr('${d['title']}')),
                      subtitle: Text(tr('${docTypes[d['type']] ?? d['type']}${d['number'] != null ? ' n° ${d['number']}' : ''} · ${dateFr(d['date'])}')),
                      trailing: StatusChip(_statusFr[d['status']] ?? '${d['status']}', color: d['status'] == 'issued' ? YColors.success : YColors.muted),
                    ),
                ]),
        ),
        const SizedBox(height: 14),
        SectionCard(
          title: tr('Salaires versés'),
          child: years.isEmpty
              ? empty(context.read<Session>().hasModule('paie') ? 'Aucun bulletin validé pour l\'instant.' : 'Activez le module Paie pour suivre chaque salaire versé.')
              : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  for (final y in years)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(children: [
                        SizedBox(width: 60, child: Text(tr('${y['year']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                        Expanded(child: Text(tr('${y['months']} mois'), style: TextStyle(color: hint))),
                        SizedBox(width: 150, child: Text(tr('brut ${money(toDouble(y['gross']), cur)}'), textAlign: TextAlign.right)),
                        SizedBox(width: 150, child: Text(tr('net ${money(toDouble(y['net']), cur)}'), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
                      ]),
                    ),
                  const Divider(),
                  for (final p in slips.take(12))
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 3),
                      child: Row(children: [
                        Expanded(child: Text(tr('${p['label'] ?? p['period']}'))),
                        StatusChip(_statusFr[p['status']] ?? '${p['status']}', color: p['status'] == 'paid' ? YColors.success : (p['status'] == 'validated' ? YColors.blue : YColors.muted)),
                        SizedBox(width: 140, child: Text(tr(money(toDouble(p['net']), cur)), textAlign: TextAlign.right)),
                      ]),
                    ),
                ]),
        ),
        const SizedBox(height: 14),
        SectionCard(
          title: tr('Congés & absences'),
          child: leaves.isEmpty
              ? empty('Aucun congé enregistré.')
              : Column(children: [
                  for (final l in leaves.take(10))
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 3),
                      child: Row(children: [
                        Expanded(child: Text(tr('${leaveTypes[l['type']] ?? l['type']} · ${dateFr(l['start_date'])} → ${dateFr(l['end_date'])}'))),
                        Text(tr('${number(toDouble(l['days']), decimals: 1)} j  '), style: TextStyle(color: hint)),
                        StatusChip(_statusFr[l['status']] ?? '${l['status']}', color: l['status'] == 'approved' ? YColors.success : YColors.muted),
                      ]),
                    ),
                ]),
        ),
        if (exp.isNotEmpty) ...[
          const SizedBox(height: 14),
          SectionCard(
            title: tr('Notes de frais · ${money(toDouble(_d!['expenses_total']), cur)} remboursables'),
            child: Column(children: [
              for (final x in exp.take(10))
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(children: [
                    Expanded(child: Text(tr('${dateFr(x['date'])} · ${x['description']}'), overflow: TextOverflow.ellipsis)),
                    StatusChip(_statusFr[x['status']] ?? '${x['status']}', color: x['status'] == 'paid' ? YColors.success : YColors.muted),
                    SizedBox(width: 120, child: Text(tr(money(toDouble(x['amount']), cur)), textAlign: TextAlign.right)),
                  ]),
                ),
            ]),
          ),
        ],
      ]),
    );
  }
}
