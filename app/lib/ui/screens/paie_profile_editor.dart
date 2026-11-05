import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Méthodes de prise en compte de la famille dans l'impôt sur salaires.
const familyMethods = {
  'none': 'Aucune',
  'quotient': 'Quotient familial (parts)',
  'per_half_part': 'Réduction fixe par demi-part',
  'reduction': 'Réduction selon les parts / personnes à charge',
};

const levyBases = {'gross': 'Brut', 'taxable': 'Brut imposable', 'tax_base': 'Base de l\'impôt', 'income_tax': 'Montant de l\'impôt', 'social': 'Base cotisable'};

/// Carte « Pays de paie » : choix du pays (moteur ivoirien ou profil générique).
class PayrollCountryCard extends StatelessWidget {
  const PayrollCountryCard({super.key, required this.country, required this.countries, required this.profile, required this.onChanged});

  final String country;
  final List countries;
  final Map<String, dynamic> profile;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final current = countries.cast<Map>().firstWhere((c) => c['code'] == country, orElse: () => {'name': country});
    final zones = <String, List<Map>>{};
    for (final c in countries.cast<Map>()) {
      zones.putIfAbsent('${c['zone'] ?? 'Autre'}', () => []).add(c);
    }
    final conf = '${current['confidence'] ?? ''}';
    return SectionCard(
      title: tr('Pays de paie'),
      trailing: profile['customized'] == true ? StatusChip(tr('Profil modifié'), color: YColors.blue, icon: Icons.edit_outlined) : null,
      child: Wrap(
        spacing: 16,
        runSpacing: 12,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SizedBox(
            width: 340,
            child: DropdownButtonFormField<String>(
              initialValue: country,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Législation appliquée')),
              items: [
                for (final z in zones.entries)
                  for (final c in z.value)
                    DropdownMenuItem(
                      value: '${c['code']}',
                      child: Text('${c['name']}${c['zone'] != null ? ' · ${c['zone']}' : ''}', overflow: TextOverflow.ellipsis),
                    ),
              ],
              onChanged: (v) {
                if (v != null && v != country) onChanged(v);
              },
            ),
          ),
          if (current['as_of'] != null) StatusChip('${tr('Barèmes au')} ${current['as_of']}', icon: Icons.event_outlined),
          if (conf.isNotEmpty)
            StatusChip(
              switch (conf) {
                'high' => tr('Sources officielles'),
                'medium' => tr('À faire valider'),
                _ => tr('Données partielles — à vérifier'),
              },
              color: switch (conf) {
                'high' => YColors.success,
                'medium' => YColors.warning,
                _ => YColors.danger,
              },
              icon: Icons.verified_outlined,
            ),
          if ('${profile['notes'] ?? ''}'.isNotEmpty)
            Text(tr('${profile['notes']}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
        ],
      ),
    );
  }
}

/// Éditeur du profil de paie d'un pays : cotisations, impôt, taxes. Les modifications restent propres à la société.
class PayrollProfileEditor extends StatefulWidget {
  const PayrollProfileEditor({super.key, required this.country, required this.profile, required this.onSaved});

  final String country;
  final Map<String, dynamic> profile;
  final VoidCallback onSaved;

  @override
  State<PayrollProfileEditor> createState() => _PayrollProfileEditorState();
}

class _PayrollProfileEditorState extends State<PayrollProfileEditor> {
  late Map<String, dynamic> _p;
  int _gen = 0;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _reset();
  }

  @override
  void didUpdateWidget(covariant PayrollProfileEditor old) {
    super.didUpdateWidget(old);
    if (old.profile != widget.profile) _reset();
  }

  void _reset() {
    _p = jsonDecode(jsonEncode(widget.profile)) as Map<String, dynamic>;
    _p['income_tax'] ??= <String, dynamic>{'brackets': [], 'family': {'method': 'none'}};
    _p['income_tax']['family'] ??= <String, dynamic>{'method': 'none'};
    for (final k in ['contributions', 'fixed', 'employee_taxes', 'employer_taxes']) {
      _p[k] ??= <dynamic>[];
    }
    _gen++;
  }

  num? _n(String v) => num.tryParse(v.replaceAll(' ', '').replaceAll(' ', '').replaceAll(',', '.'));
  String _f(dynamic v) => v == null ? '' : (v is num && v == v.roundToDouble() ? v.toInt().toString() : '$v');

  Widget _num(String label, dynamic value, ValueChanged<num?> set, {double width = 150, String? hint}) => SizedBox(
    width: width,
    child: TextFormField(
      key: ValueKey('$_gen$label${identityHashCode(set)}'),
      initialValue: _f(value),
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      decoration: InputDecoration(labelText: tr(label), hintText: hint == null ? null : tr(hint), isDense: true),
      onChanged: (v) => set(_n(v)),
    ),
  );

  Widget _text(String label, dynamic value, ValueChanged<String> set, {double width = 220}) => SizedBox(
    width: width,
    child: TextFormField(
      key: ValueKey('$_gen$label${identityHashCode(set)}'),
      initialValue: '${value ?? ''}',
      decoration: InputDecoration(labelText: tr(label), isDense: true),
      onChanged: set,
    ),
  );

  Widget _wrap(List<Widget> c) => Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: c);

  Widget _rowCard(List<Widget> fields, VoidCallback onDelete) => Container(
    margin: const EdgeInsets.only(bottom: 10),
    padding: const EdgeInsets.fromLTRB(12, 12, 4, 12),
    decoration: BoxDecoration(border: Border.all(color: Theme.of(context).colorScheme.outlineVariant.withValues(alpha: .6)), borderRadius: BorderRadius.circular(12)),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: _wrap(fields)),
        IconButton(tooltip: tr('Supprimer'), onPressed: () => setState(onDelete), icon: const Icon(Icons.delete_outline)),
      ],
    ),
  );

  Widget _add(String label, VoidCallback onAdd) => Align(
    alignment: Alignment.centerLeft,
    child: TextButton.icon(onPressed: () => setState(() {
      onAdd();
      _gen++;
    }), icon: const Icon(Icons.add), label: Text(tr(label))),
  );

  Widget _brackets(List list, {String unit = ''}) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      for (var i = 0; i < list.length; i++)
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Row(
            children: [
              Expanded(child: _num('Jusqu\'à$unit', list[i]['up_to'], (v) => list[i]['up_to'] = v, width: double.infinity, hint: i == list.length - 1 ? 'Au-delà' : null)),
              const SizedBox(width: 12),
              _num('Taux %', list[i]['rate'], (v) => list[i]['rate'] = v ?? 0, width: 110),
              IconButton(onPressed: () => setState(() => list.removeAt(i)), icon: const Icon(Icons.delete_outline)),
            ],
          ),
        ),
      _add('Ajouter une tranche', () => list.add(<String, dynamic>{'up_to': null, 'rate': 0})),
    ],
  );

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final body = Map<String, dynamic>.from(_p)..remove('customized');
      await context.read<Session>().api.put('paie/profile', {'country': widget.country, 'profile': body});
      if (mounted) toast(context, tr('Profil de paie enregistré. Recalculez les paies en brouillon pour l\'appliquer.'));
      widget.onSaved();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _restore() async {
    if (!await confirm(context, 'Revenir au profil de référence', 'Les modifications propres à votre société seront abandonnées.', ok: 'Rétablir')) return;
    if (!mounted) return;
    try {
      await context.read<Session>().api.delete('paie/profile/${widget.country}');
      widget.onSaved();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final it = _p['income_tax'] as Map<String, dynamic>;
    final fam = it['family'] as Map<String, dynamic>;
    final parts = (fam['parts'] ??= <String, dynamic>{}) as Map;
    final annual = it['period'] == 'annual';
    final sen = _p['seniority'] as Map?;
    final gap = const SizedBox(height: 12);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionCard(
          title: tr('Général'),
          child: _wrap([
            _num('SMIG mensuel', _p['smig'], (v) => _p['smig'] = v ?? 0),
            _num('Base de prorata (jours)', _p['days_basis'], (v) => _p['days_basis'] = v ?? 30),
            _num('Transport exonéré jusqu\'à', _p['transport_exempt'], (v) => _p['transport_exempt'] = v, width: 190, hint: 'Sans limite'),
            SizedBox(
              width: 260,
              child: SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: sen != null,
                title: Text(tr('Prime d\'ancienneté')),
                onChanged: (v) => setState(() {
                  _p['seniority'] = v ? {'start_years': 2, 'first_rate': 2, 'per_year': 1, 'max': 25} : null;
                  _gen++;
                }),
              ),
            ),
            if (sen != null) ...[
              _num('Dès (années)', sen['start_years'], (v) => sen['start_years'] = v ?? 2, width: 120),
              _num('Taux initial %', sen['first_rate'], (v) => sen['first_rate'] = v ?? 2, width: 120),
              _num('+ par an %', sen['per_year'], (v) => sen['per_year'] = v ?? 1, width: 110),
              _num('Maximum %', sen['max'], (v) => sen['max'] = v ?? 25, width: 110),
            ],
          ]),
        ),
        gap,
        SectionCard(
          title: tr('Cotisations sociales (% du salaire cotisable)'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final c in (_p['contributions'] as List).cast<Map>())
                _rowCard([
                  _text('Libellé', c['label'], (v) => c['label'] = v, width: 240),
                  _num('Salarié %', c['employee_rate'], (v) => c['employee_rate'] = v ?? 0, width: 110),
                  _num('Employeur %', c['employer_rate'], (v) => c['employer_rate'] = v ?? 0, width: 120),
                  _num('Plafond mensuel', c['ceiling'], (v) => c['ceiling'] = v, width: 160, hint: 'Aucun'),
                ], () => (_p['contributions'] as List).remove(c)),
              _add('Ajouter une cotisation', () => (_p['contributions'] as List).add(<String, dynamic>{'code': 'c${DateTime.now().millisecondsSinceEpoch % 100000}', 'label': 'Nouvelle cotisation', 'employee_rate': 0, 'employer_rate': 0, 'ceiling': null})),
            ],
          ),
        ),
        gap,
        SectionCard(
          title: tr('Cotisations forfaitaires (montant par mois)'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final c in (_p['fixed'] as List).cast<Map>())
                _rowCard([
                  _text('Libellé', c['label'], (v) => c['label'] = v, width: 200),
                  _num('Salarié', c['employee_amount'], (v) => c['employee_amount'] = v ?? 0, width: 110),
                  _num('Employeur', c['employer_amount'], (v) => c['employer_amount'] = v ?? 0, width: 110),
                  SizedBox(
                    width: 190,
                    child: DropdownButtonFormField<String>(
                      initialValue: c['per'] == 'person' ? 'person' : 'employee',
                      decoration: InputDecoration(labelText: tr('Par'), isDense: true),
                      items: [DropdownMenuItem(value: 'employee', child: Text(tr('Salarié'))), DropdownMenuItem(value: 'person', child: Text(tr('Personne couverte')))],
                      onChanged: (v) => setState(() => c['per'] = v),
                    ),
                  ),
                ], () => (_p['fixed'] as List).remove(c)),
              _add('Ajouter un forfait', () => (_p['fixed'] as List).add(<String, dynamic>{'code': 'f${DateTime.now().millisecondsSinceEpoch % 100000}', 'label': 'Forfait', 'employee_amount': 0, 'employer_amount': 0, 'per': 'employee'})),
            ],
          ),
        ),
        gap,
        SectionCard(
          title: '${tr('Impôt sur salaires')} — ${it['label'] ?? ''}',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _wrap([
                _text('Nom de l\'impôt', it['label'], (v) => it['label'] = v, width: 200),
                SegmentedButton<String>(
                  segments: [ButtonSegment(value: 'monthly', label: Text(tr('Barème mensuel'))), ButtonSegment(value: 'annual', label: Text(tr('Barème annuel')))],
                  selected: {annual ? 'annual' : 'monthly'},
                  onSelectionChanged: (v) => setState(() => it['period'] = v.first),
                ),
                SizedBox(
                  width: 320,
                  child: SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    value: it['deduct_social'] == true,
                    title: Text(tr('Cotisations salariales déduites de la base')),
                    onChanged: (v) => setState(() => it['deduct_social'] = v),
                  ),
                ),
              ]),
              const SizedBox(height: 12),
              _wrap([
                _num('Abattement frais pro %', (it['professional'] as Map?)?['rate'], (v) {
                  it['professional'] = {...?(it['professional'] as Map?), 'rate': v ?? 0};
                }, width: 190),
                _num(annual ? 'Plafond annuel' : 'Plafond mensuel', (it['professional'] as Map?)?['cap'], (v) {
                  it['professional'] = {...?(it['professional'] as Map?), 'cap': v};
                }, width: 170, hint: 'Aucun'),
                _num(annual ? 'Abattement fixe annuel' : 'Abattement fixe mensuel', it['fixed_abatement'], (v) => it['fixed_abatement'] = v ?? 0, width: 200),
              ]),
              const SizedBox(height: 16),
              Text(tr(annual ? 'Tranches (montants annuels)' : 'Tranches (montants mensuels)'), style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              _brackets((it['brackets'] ??= <dynamic>[]) as List),
              const SizedBox(height: 8),
              Text(tr('Charges de famille'), style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              _wrap([
                SizedBox(
                  width: 360,
                  child: DropdownButtonFormField<String>(
                    initialValue: familyMethods.containsKey(fam['method']) ? fam['method'] : 'none',
                    isExpanded: true,
                    decoration: InputDecoration(labelText: tr('Méthode'), isDense: true),
                    items: [for (final m in familyMethods.entries) DropdownMenuItem(value: m.key, child: Text(tr(m.value)))],
                    onChanged: (v) => setState(() => fam['method'] = v),
                  ),
                ),
                if (fam['method'] == 'per_half_part') _num('Réduction par demi-part', fam['amount'], (v) => fam['amount'] = v ?? 0, width: 190),
                if (fam['method'] != 'none') ...[
                  _num('Célibataire', parts['single'], (v) => parts['single'] = v ?? 1, width: 110),
                  _num('Marié', parts['married'], (v) => parts['married'] = v ?? 2, width: 100),
                  _num('Par enfant', parts['per_child'], (v) => parts['per_child'] = v ?? 0.5, width: 110),
                  _num('Parts max', parts['max'], (v) => parts['max'] = v ?? 5, width: 110),
                ],
              ]),
              if (fam['method'] == 'reduction') ...[
                const SizedBox(height: 10),
                for (final red in ((fam['reductions'] ??= <dynamic>[]) as List).cast<Map>())
                  _rowCard([
                    _num(fam['by'] == 'dependants' ? 'À partir de (pers.)' : 'À partir de (parts)', red['from'], (v) => red['from'] = v ?? 0, width: 160),
                    _num('Réduction %', red['rate'], (v) => red['rate'] = v, width: 120),
                    _num('Minimum', red['min'], (v) => red['min'] = v, width: 120),
                    _num('Maximum', red['max'], (v) => red['max'] = v, width: 120),
                    _num('ou montant', red['amount'], (v) => red['amount'] = v, width: 120),
                  ], () => (fam['reductions'] as List).remove(red)),
                _add('Ajouter une réduction', () => (fam['reductions'] as List).add(<String, dynamic>{'from': 1, 'rate': 0})),
              ],
            ],
          ),
        ),
        gap,
        SectionCard(
          title: tr('Autres prélèvements sur le salarié'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final t in (_p['employee_taxes'] as List).cast<Map>())
                _rowCard([
                  _text('Libellé', t['label'], (v) => t['label'] = v, width: 220),
                  if (t['table'] != null || t['brackets'] != null)
                    StatusChip(tr(t['table'] != null ? 'Montant selon barème' : 'Barème progressif'), icon: Icons.table_rows_outlined)
                  else ...[
                    SizedBox(
                      width: 190,
                      child: DropdownButtonFormField<String>(
                        initialValue: levyBases.containsKey(t['base']) ? t['base'] : 'gross',
                        isExpanded: true,
                        decoration: InputDecoration(labelText: tr('Base'), isDense: true),
                        items: [for (final b in levyBases.entries) DropdownMenuItem(value: b.key, child: Text(tr(b.value)))],
                        onChanged: (v) => setState(() => t['base'] = v),
                      ),
                    ),
                    _num('Taux %', t['rate'], (v) => t['rate'] = v ?? 0, width: 100),
                  ],
                ], () => (_p['employee_taxes'] as List).remove(t)),
              _add('Ajouter un prélèvement', () => (_p['employee_taxes'] as List).add(<String, dynamic>{'code': 't${DateTime.now().millisecondsSinceEpoch % 100000}', 'label': 'Prélèvement', 'base': 'gross', 'rate': 0})),
            ],
          ),
        ),
        gap,
        SectionCard(
          title: tr('Taxes patronales sur salaires'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final t in (_p['employer_taxes'] as List).cast<Map>())
                _rowCard([
                  _text('Libellé', t['label'], (v) => t['label'] = v, width: 220),
                  SizedBox(
                    width: 170,
                    child: DropdownButtonFormField<String>(
                      initialValue: const ['gross', 'taxable', 'social'].contains(t['base']) ? t['base'] : 'gross',
                      isExpanded: true,
                      decoration: InputDecoration(labelText: tr('Base'), isDense: true),
                      items: [for (final b in const ['gross', 'taxable', 'social']) DropdownMenuItem(value: b, child: Text(tr(levyBases[b]!)))],
                      onChanged: (v) => setState(() => t['base'] = v),
                    ),
                  ),
                  _num('Taux %', t['rate'], (v) => t['rate'] = v ?? 0, width: 100),
                  _num('Expatriés %', t['expat_rate'], (v) => t['expat_rate'] = v, width: 120, hint: 'Idem'),
                ], () => (_p['employer_taxes'] as List).remove(t)),
              _add('Ajouter une taxe', () => (_p['employer_taxes'] as List).add(<String, dynamic>{'code': 'e${DateTime.now().millisecondsSinceEpoch % 100000}', 'label': 'Taxe', 'base': 'gross', 'rate': 0})),
            ],
          ),
        ),
        if ((_p['sources'] as List?)?.isNotEmpty ?? false) ...[
          gap,
          SectionCard(
            title: tr('Sources'),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [for (final s in _p['sources'] as List) SelectableText('$s', style: TextStyle(fontSize: 12.5, color: Theme.of(context).hintColor))],
            ),
          ),
        ],
        const SizedBox(height: 16),
        Wrap(
          spacing: 12,
          runSpacing: 8,
          children: [
            FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer le profil'))),
            if (widget.profile['customized'] == true && widget.country != 'XX')
              OutlinedButton.icon(onPressed: _restore, icon: const Icon(Icons.restore), label: Text(tr('Revenir au profil de référence'))),
          ],
        ),
      ],
    );
  }
}
