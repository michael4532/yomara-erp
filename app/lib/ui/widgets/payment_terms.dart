import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import 'common.dart';
import '../../i18n/i18n.dart';

/// Conditions de paiement (synchronisées, utilisables hors ligne).
bool ptAvailable() => LocalDb.hasEntity('payment_terms') && LocalDb.all('payment_terms').isNotEmpty;

List<Map<String, dynamic>> ptChoices([String? current]) => (LocalDb.hasEntity('payment_terms') ? LocalDb.all('payment_terms') : <Map<String, dynamic>>[])
    .where((t) => t['active'] != false || t['id'] == current)
    .toList()
  ..sort((a, b) => toDouble(a['sort']).compareTo(toDouble(b['sort'])));

Map<String, dynamic>? ptGet(dynamic id) => id == null || !LocalDb.hasEntity('payment_terms') ? null : LocalDb.get('payment_terms', '$id');

/// Condition par défaut d'un tiers (client ou fournisseur), sinon de la société — même règle que le serveur.
String? ptDefaultFor(dynamic partnerId, {required bool purchase}) {
  final p = partnerId == null ? null : LocalDb.get('partners', '$partnerId');
  final own = p?[purchase ? 'supplier_payment_term_id' : 'payment_term_id'];
  if (own != null && ptGet(own) != null) return '$own';
  return ptChoices().where((t) => t[purchase ? 'is_default_purchase' : 'is_default_sale'] == true).map((t) => '${t['id']}').firstOrNull;
}

/// Libellé lisible d'une liste d'échéances (repris du serveur).
String ptDescribe(List lines) {
  final one = lines.length == 1;
  return lines.map((l) {
    final d = toDouble(l['days']).round();
    final eom = l['end_of_month'] == true;
    final when = d == 0 ? (eom ? 'fin de mois' : 'à réception') : 'à $d jours${eom ? ' fin de mois' : ''}';
    final p = toDouble(l['percent']);
    return one ? when : '${p == p.roundToDouble() ? p.toInt() : p.toStringAsFixed(2).replaceAll('.', ',')} % $when';
  }).join(', ');
}

class PaymentTermDropdown extends StatelessWidget {
  const PaymentTermDropdown({super.key, required this.value, required this.onChanged, this.enabled = true, this.noneLabel = 'Aucune (délai par défaut)'});

  final String? value;
  final ValueChanged<String?> onChanged;
  final bool enabled;
  final String noneLabel;

  @override
  Widget build(BuildContext context) {
    final items = ptChoices(value);
    final v = items.any((t) => t['id'] == value) ? value : null;
    return DropdownButtonFormField<String?>(
      key: ValueKey('pt-$v'),
      initialValue: v,
      isExpanded: true,
      items: [
        DropdownMenuItem(value: null, child: Text(tr(noneLabel), overflow: TextOverflow.ellipsis)),
        for (final t in items) DropdownMenuItem(value: '${t['id']}', child: Text(tr('${t['name']}'), overflow: TextOverflow.ellipsis)),
      ],
      onChanged: enabled ? onChanged : null,
    );
  }
}

/// Échéancier d'un document validé.
class ScheduleView extends StatelessWidget {
  const ScheduleView({super.key, required this.doc});

  final Map<String, dynamic> doc;

  @override
  Widget build(BuildContext context) {
    final sch = (doc['schedule'] as List?) ?? const [];
    if (sch.length < 2) return const SizedBox.shrink();
    final cur = '${doc['currency'] ?? context.read<Session>().currency}';
    var paid = toDouble(doc['amount_paid']);
    final today = DateTime.now();
    return Wrap(spacing: 8, runSpacing: 6, children: [
      for (final i in sch)
        Builder(builder: (context) {
          final amount = toDouble(i['amount']);
          final settled = paid >= amount - 0.01;
          paid = (paid - amount).clamp(0, double.infinity).toDouble();
          final late = !settled && (DateTime.tryParse('${i['date']}')?.isBefore(DateTime(today.year, today.month, today.day)) ?? false);
          final color = settled ? YColors.success : (late ? YColors.danger : YColors.blue);
          return Chip(
            avatar: Icon(settled ? Icons.check_circle : (late ? Icons.warning_amber_rounded : Icons.schedule), size: 16, color: color),
            label: Text(tr('${dateFr(i['date'])} · ${money(amount, cur)}${settled ? ' · réglée' : (late ? ' · en retard' : '')}'), style: const TextStyle(fontSize: 12.5)),
            side: BorderSide(color: color.withValues(alpha: .4)),
            backgroundColor: color.withValues(alpha: .06),
          );
        }),
    ]);
  }
}

/// Paramètres → Conditions de paiement.
class PaymentTermsScreen extends StatelessWidget {
  const PaymentTermsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final manage = context.watch<Session>().can('core.settings.manage');
    final hint = Theme.of(context).hintColor;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Conditions de paiement')), actions: [
        if (manage) Padding(padding: const EdgeInsets.only(right: 12), child: FilledButton.icon(onPressed: () => editPaymentTerm(context), icon: const Icon(Icons.add), label: Text(tr('Condition')))),
      ]),
      body: ValueListenableBuilder(
        valueListenable: LocalDb.listen('payment_terms'),
        builder: (context, _, _) {
          final rows = ptChoices()..addAll(LocalDb.all('payment_terms').where((t) => t['active'] == false));
          return ListView(padding: const EdgeInsets.all(16), children: [
            Text(tr('Appliquées aux devis, commandes et factures : celles du client ou du fournisseur (fiche contact), sinon la condition par défaut. '
                'À la validation, la facture reçoit son échéancier ; les relances et la prévision de trésorerie suivent chaque échéance.'), style: TextStyle(color: hint)),
            const SizedBox(height: 12),
            for (final t in rows)
              Card(
                child: ListTile(
                  onTap: manage ? () => editPaymentTerm(context, t) : null,
                  leading: Icon(((t['lines'] as List?)?.length ?? 1) > 1 ? Icons.call_split : Icons.event_available, color: t['active'] == false ? hint : YColors.blue),
                  title: Text(tr('${t['name']}'), style: TextStyle(fontWeight: FontWeight.w700, color: t['active'] == false ? hint : null)),
                  subtitle: Text(tr('${t['description'] ?? ptDescribe((t['lines'] as List?) ?? const [])}${t['active'] == false ? ' · désactivée' : ''}')),
                  trailing: Wrap(spacing: 6, children: [
                    if (t['is_default_sale'] == true) StatusChip(tr('Ventes par défaut'), color: YColors.success),
                    if (t['is_default_purchase'] == true) StatusChip(tr('Achats par défaut'), color: YColors.blue),
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

Future<void> editPaymentTerm(BuildContext context, [Map<String, dynamic>? t]) async {
  final sync = context.read<SyncEngine>();
  final name = TextEditingController(text: '${t?['name'] ?? ''}');
  final lines = <Map<String, dynamic>>[
    for (final l in ((t?['lines'] as List?) ?? const [{'percent': 100, 'days': 30, 'end_of_month': false}]))
      {'percent': TextEditingController(text: _n(l['percent'])), 'days': TextEditingController(text: '${toDouble(l['days']).round()}'), 'eom': l['end_of_month'] == true},
  ];
  var sale = t?['is_default_sale'] == true;
  var purchase = t?['is_default_purchase'] == true;
  var active = t?['active'] != false;
  await showFormDialog(
    context,
    title: t == null ? 'Nouvelle condition de paiement' : 'Condition de paiement',
    maxWidth: 600,
    builder: (ctx) => StatefulBuilder(builder: (ctx, set) {
      final total = lines.fold<double>(0, (s, l) => s + toDouble((l['percent'] as TextEditingController).text.replaceAll(',', '.')));
      final preview = [
        for (final l in lines) {'percent': toDouble((l['percent'] as TextEditingController).text.replaceAll(',', '.')), 'days': toDouble((l['days'] as TextEditingController).text), 'end_of_month': l['eom']},
      ];
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(label: tr('Nom'), child: TextField(controller: name, decoration: InputDecoration(hintText: tr('Ex. 30 jours fin de mois, Acompte 30 %…')))),
        Text(tr('Échéances'), style: Theme.of(ctx).textTheme.titleSmall),
        for (var i = 0; i < lines.length; i++)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(children: [
              SizedBox(width: 90, child: TextField(controller: lines[i]['percent'], keyboardType: const TextInputType.numberWithOptions(decimal: true), onChanged: (_) => set(() {}), decoration: const InputDecoration(isDense: true, suffixText: '%'))),
              const SizedBox(width: 8),
              SizedBox(width: 100, child: TextField(controller: lines[i]['days'], keyboardType: TextInputType.number, onChanged: (_) => set(() {}), decoration: InputDecoration(isDense: true, suffixText: tr('jours')))),
              const SizedBox(width: 4),
              Expanded(child: CheckboxListTile(dense: true, contentPadding: EdgeInsets.zero, controlAffinity: ListTileControlAffinity.leading, value: lines[i]['eom'] as bool, onChanged: (v) => set(() => lines[i]['eom'] = v == true), title: Text(tr('fin de mois')))),
              IconButton(onPressed: lines.length == 1 ? null : () => set(() => lines.removeAt(i)), icon: const Icon(Icons.remove_circle_outline)),
            ]),
          ),
        Row(children: [
          TextButton.icon(
            onPressed: lines.length >= 6 ? null : () => set(() => lines.add({'percent': TextEditingController(text: _n((100 - total).clamp(0, 100))), 'days': TextEditingController(text: '30'), 'eom': false})),
            icon: const Icon(Icons.add),
            label: Text(tr('Échéance')),
          ),
          const Spacer(),
          Text(tr('Total ${_n(total)} %'), style: TextStyle(fontWeight: FontWeight.w700, color: (total - 100).abs() < 0.01 ? YColors.success : YColors.danger)),
        ]),
        Text(tr(ptDescribe(preview)), style: TextStyle(color: Theme.of(ctx).hintColor)),
        SwitchListTile(contentPadding: EdgeInsets.zero, value: sale, onChanged: (v) => set(() => sale = v), title: Text(tr('Par défaut pour les ventes'))),
        SwitchListTile(contentPadding: EdgeInsets.zero, value: purchase, onChanged: (v) => set(() => purchase = v), title: Text(tr('Par défaut pour les achats'))),
        if (t != null) SwitchListTile(contentPadding: EdgeInsets.zero, value: active, onChanged: (v) => set(() => active = v), title: Text(tr('Active'))),
        const SizedBox(height: 8),
        Row(children: [
          if (t != null)
            TextButton(
              onPressed: () async {
                try {
                  await sync.remove('payment_terms', '${t['id']}');
                  if (ctx.mounted) Navigator.pop(ctx);
                } catch (e) {
                  if (ctx.mounted) toast(ctx, errorText(e), error: true);
                }
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              if (name.text.trim().isEmpty) return toast(ctx, tr('Donnez un nom.'), error: true);
              if ((total - 100).abs() > 0.01) return toast(ctx, tr('Les échéances doivent totaliser 100 %.'), error: true);
              try {
                await sync.save('payment_terms', {
                  'id': t?['id'] ?? newId(),
                  'name': name.text.trim(),
                  'lines': [for (final l in preview) {...l, 'days': (l['days'] as double).round()}],
                  'is_default_sale': sale,
                  'is_default_purchase': purchase,
                  'active': active,
                  'sort': t?['sort'] ?? 50,
                });
                if (ctx.mounted) Navigator.pop(ctx);
              } catch (e) {
                if (ctx.mounted) toast(ctx, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]);
    }),
  );
}

String _n(dynamic v) {
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.toInt()}' : d.toStringAsFixed(2).replaceAll('.', ',');
}
