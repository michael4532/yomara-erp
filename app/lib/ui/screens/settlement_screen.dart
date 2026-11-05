import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Solde de tout compte : calcul (congés, préavis, licenciement, fin de CDD), lignes corrigeables, validation
/// reprise automatiquement dans la paie du mois de sortie. En ligne.
Future<void> showSettlement(BuildContext context, Map<String, dynamic> employee) =>
    showFormDialog(context, title: 'Solde de tout compte — ${employee['last_name'] ?? ''} ${employee['first_name'] ?? ''}', maxWidth: 720, builder: (_) => _SettlementView(employee: employee));

const settlementReasons = {
  'resignation': 'Démission',
  'dismissal': 'Licenciement',
  'economic': 'Licenciement pour motif économique',
  'misconduct': 'Licenciement pour faute lourde',
  'end_cdd': 'Fin de CDD',
  'end_trial': 'Fin de période d\'essai',
  'retirement': 'Départ à la retraite',
  'mutual': 'Rupture d\'un commun accord',
  'death': 'Décès',
};

class _SettlementView extends StatefulWidget {
  const _SettlementView({required this.employee});

  final Map<String, dynamic> employee;

  @override
  State<_SettlementView> createState() => _SettlementViewState();
}

class _SettlementViewState extends State<_SettlementView> {
  late DateTime _end = DateTime.tryParse('${widget.employee['end_date'] ?? ''}') ?? DateTime(DateTime.now().year, DateTime.now().month + 1, 0);
  late String _reason = widget.employee['contract_type'] == 'CDD' ? 'end_cdd' : 'resignation';
  bool _noticeWorked = false;
  final _notice = TextEditingController();
  final _leave = TextEditingController();
  final _average = TextEditingController();
  Map<String, dynamic>? _r;
  List<Map<String, dynamic>> _lines = [];
  Object? _error;
  bool _busy = false;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    final cur = asMap(widget.employee['settlement']);
    if (cur.isNotEmpty) {
      _end = DateTime.tryParse('${cur['end_date']}') ?? _end;
      _reason = '${cur['reason'] ?? _reason}';
    }
    _compute();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  num? _n(TextEditingController c) => num.tryParse(c.text.replaceAll(' ', '').replaceAll(',', '.'));

  Map<String, dynamic> get _input => {
    'end_date': _end.toIso8601String().substring(0, 10),
    'reason': _reason,
    'notice_worked': _noticeWorked,
    if (_n(_notice) != null) 'notice_months': _n(_notice),
    if (_n(_leave) != null) 'leave_days': _n(_leave),
    if (_n(_average) != null) 'average': _n(_average),
  };

  void _later() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 500), _compute);
  }

  Future<void> _compute() async {
    try {
      final r = asMap(await context.read<Session>().api.post('rh/employees/${widget.employee['id']}/settlement', _input));
      if (!mounted) return;
      setState(() {
        _r = r;
        _lines = [for (final l in (r['lines'] as List? ?? const [])) Map<String, dynamic>.from(l as Map)];
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _apply() async {
    if (!await confirm(context, 'Valider le solde de tout compte',
        'La date de sortie est enregistrée sur la fiche et les montants sont repris dans la paie de ${monthLabel(_end)}. Le reçu se génère depuis les documents RH.', ok: 'Valider')) {
      return;
    }
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('rh/employees/${widget.employee['id']}/settlement/apply', {..._input, 'lines': _lines});
      if (!mounted) return;
      toast(context, tr('Solde de tout compte validé : il sera payé avec la paie du mois de sortie.'));
      Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel() async {
    if (!await confirm(context, 'Annuler le solde de tout compte', 'Les montants sont retirés de la paie du mois de sortie (si elle est en brouillon).', danger: true, ok: 'Annuler le solde')) return;
    if (!mounted) return;
    try {
      await context.read<Session>().api.delete('rh/employees/${widget.employee['id']}/settlement');
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  static String monthLabel(DateTime d) => '${const ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][d.month - 1]} ${d.year}';

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final r = _r;
    final existing = asMap(widget.employee['settlement']);
    final total = _lines.fold<double>(0, (a, l) => a + toDouble(l['amount']));
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (existing.isNotEmpty)
          Card(
            color: YColors.success.withValues(alpha: .08),
            child: ListTile(
              leading: const Icon(Icons.verified, color: YColors.success),
              title: Text(tr('Solde validé : ${money(existing['total'], cur)} (${existing['reason_label'] ?? ''})')),
              subtitle: Text(tr('Sortie le ${dateFr('${existing['end_date']}')}${existing['validated_by'] != null ? ' · par ${existing['validated_by']}' : ''}')),
              trailing: TextButton(onPressed: _cancel, child: Text(tr('Annuler'))),
            ),
          ),
        Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.end, children: [
          SizedBox(
            width: 300,
            child: DropdownButtonFormField<String>(
              initialValue: _reason,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Motif de sortie')),
              items: [for (final e in settlementReasons.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
              onChanged: (v) {
                setState(() => _reason = v ?? _reason);
                _compute();
              },
            ),
          ),
          OutlinedButton.icon(
            onPressed: () async {
              final d = await showDatePicker(context: context, firstDate: DateTime(2020), lastDate: DateTime(2100), initialDate: _end);
              if (d == null) return;
              setState(() => _end = d);
              _compute();
            },
            icon: const Icon(Icons.event),
            label: Text(tr('Dernier jour : ${dateFr(_end.toIso8601String())}')),
          ),
        ]),
        if (['dismissal', 'economic', 'retirement'].contains(_reason))
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _noticeWorked,
            title: Text(tr('Préavis effectué')),
            subtitle: Text(tr('Sinon, l\'indemnité compensatrice de préavis est due.')),
            onChanged: (v) {
              setState(() => _noticeWorked = v);
              _compute();
            },
          ),
        if (r != null) ...[
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: [
            StatusChip(tr('Ancienneté ${r['seniority_label']}'), icon: Icons.workspace_premium_outlined),
            StatusChip(tr('Salaire moyen ${money(r['average_gross'], cur)}${(r['average_sample'] as num? ?? 0) > 0 ? ' (${r['average_sample']} mois payés)' : ' (salaire de base)'}'), icon: Icons.payments_outlined),
            StatusChip(tr('Congés restants ${number(r['leave_days'] as num, decimals: 1)} j'), icon: Icons.beach_access_outlined),
          ]),
          const SizedBox(height: 12),
          Wrap(spacing: 12, runSpacing: 8, children: [
            SizedBox(width: 170, child: TextField(controller: _average, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Salaire moyen'), hintText: number(r['average_gross'] as num), isDense: true), onChanged: (_) => _later())),
            SizedBox(width: 150, child: TextField(controller: _leave, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Jours de congés'), hintText: number(r['leave_days'] as num, decimals: 1), isDense: true), onChanged: (_) => _later())),
            if (r['notice_due'] == true || _notice.text.isNotEmpty)
              SizedBox(width: 150, child: TextField(controller: _notice, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Préavis (mois)'), hintText: number(r['notice_months'] as num, decimals: 1), isDense: true), onChanged: (_) => _later())),
          ]),
        ],
        const SizedBox(height: 16),
        SectionCard(
          title: tr('Montants dus'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (_error != null) Text(errorText(_error!), style: const TextStyle(color: YColors.danger)),
            if (r == null && _error == null) const Center(child: Padding(padding: EdgeInsets.all(12), child: CircularProgressIndicator())),
            if (r != null && _lines.isEmpty) Text(tr('Aucune indemnité due pour ce motif (hors salaire du dernier mois).'), style: TextStyle(color: hint)),
            for (final l in _lines)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${l['label']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(tr('${l['detail'] ?? ''}${l['taxable'] == false ? ' · non imposable' : ''}'), style: TextStyle(color: hint, fontSize: 12)),
                    ]),
                  ),
                  SizedBox(
                    width: 130,
                    child: TextFormField(
                      key: ValueKey('${l['code']}-${l['amount']}-${_r.hashCode}'),
                      initialValue: number(toDouble(l['amount'])),
                      textAlign: TextAlign.right,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(isDense: true),
                      onChanged: (v) => setState(() => l['amount'] = num.tryParse(v.replaceAll(RegExp(r'[\s  ]'), '').replaceAll(',', '.')) ?? 0),
                    ),
                  ),
                ]),
              ),
            const Divider(),
            Row(children: [
              Expanded(child: Text(tr('Total brut des indemnités'), style: const TextStyle(fontWeight: FontWeight.w800))),
              Text(money(total, cur), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            ]),
            const SizedBox(height: 6),
            Text(tr('${r?['note'] ?? ''}'), style: TextStyle(color: hint, fontSize: 12)),
          ]),
        ),
        const SizedBox(height: 8),
        Text(
          tr('Calcul selon le Code du travail ivoirien et la convention interprofessionnelle : congés = jours restants × salaire moyen ÷ 26 ; licenciement = 30 % du salaire moyen par année jusqu\'à 5 ans, 35 % de 6 à 10 ans, 40 % au-delà ; fin de CDD = 3 % des salaires. Chaque montant reste modifiable.'),
          style: TextStyle(color: hint, fontSize: 12),
        ),
        const SizedBox(height: 16),
        FilledButton.icon(onPressed: _busy || r == null ? null : _apply, icon: const Icon(Icons.task_alt), label: Text(tr('Valider le solde de tout compte'))),
      ]),
    );
  }
}
