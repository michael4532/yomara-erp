import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/fx.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';

/// Devises proposées et cours de change (hors ligne : cours synchronisés).
class DevisesScreen extends StatefulWidget {
  const DevisesScreen({super.key});

  @override
  State<DevisesScreen> createState() => _DevisesScreenState();
}

class _DevisesScreenState extends State<DevisesScreen> {
  Future<void> _addRate([String? currency]) async {
    final base = context.read<Session>().currency;
    final cur = TextEditingController(text: currency ?? 'USD');
    final rate = TextEditingController();
    var date = DateTime.now();
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) => YAlertDialog(
          title: Text(tr('Nouveau cours')),
          content: SizedBox(
            width: 400,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: cur, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(labelText: tr('Devise (code ISO, ex. USD)'))),
              TextField(controller: rate, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('1 unité = … $base'), helperText: tr('Cours de votre banque ou de la BCEAO'))),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: () async {
                  final x = await pickDate(d, initialDate: date, firstDate: DateTime(2015), lastDate: DateTime(2100));
                  if (x != null) set(() => date = x);
                },
                icon: const Icon(Icons.event),
                label: Text(tr('Applicable dès le ${dateFr(isoDate(date))}')),
              ),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    final r = double.tryParse(rate.text.replaceAll(',', '.').replaceAll(RegExp(r'\s'), ''));
    if (r == null || r <= 0) return toast(context, tr('Cours invalide.'), error: true);
    try {
      await context.read<SyncEngine>().save('fx_rates', {'id': newId(), 'currency': cur.text.trim().toUpperCase(), 'date': isoDate(date), 'rate': r});
      if (mounted) toast(context, tr('Cours enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _choose() async {
    final s = context.read<Session>();
    final current = {...((s.companySettings['devises'] as Map?)?['currencies'] as List? ?? const ['EUR', 'USD']).map((e) => '$e')};
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) => YAlertDialog(
          title: Text(tr('Devises proposées sur les documents')),
          content: SizedBox(
            width: 420,
            child: ListView(shrinkWrap: true, children: [
              for (final e in currencyNames.entries)
                if (e.key != s.currency)
                  CheckboxListTile(
                    dense: true,
                    value: current.contains(e.key),
                    onChanged: (v) => set(() => v == true ? current.add(e.key) : current.remove(e.key)),
                    title: Text(tr('${e.key} — ${e.value}')),
                  ),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await s.api.put('devises/currencies', {'currencies': current.toList()});
      await s.refreshBootstrap();
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final base = s.currency;
    final manage = s.can('devises.manage');
    final enabled = {...((s.companySettings['devises'] as Map?)?['currencies'] as List? ?? const ['EUR', 'USD']).map((e) => '$e')}..remove(base);
    return Column(children: [
      PageHeader(
        title: tr('Devises & cours'),
        subtitle: tr('Montants convertis en $base au cours du document ; écarts de change comptabilisés au paiement'),
        actions: [
          if (manage) OutlinedButton.icon(onPressed: _choose, icon: const Icon(Icons.checklist), label: Text(tr('Devises proposées'))),
          if (manage) FilledButton.icon(onPressed: () => _addRate(), icon: const Icon(Icons.add), label: Text(tr('Nouveau cours'))),
        ],
      ),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('fx_rates'),
          builder: (context, _, _) {
            final rates = LocalDb.all('fx_rates')..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
            return ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
              Wrap(spacing: 12, runSpacing: 12, children: [
                for (final c in enabled)
                  Card(
                    child: Container(
                      width: 230,
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Text(tr(c), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                          const SizedBox(width: 8),
                          Expanded(child: Text(tr(currencyNames[c] ?? ''), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12), overflow: TextOverflow.ellipsis)),
                        ]),
                        const SizedBox(height: 6),
                        Builder(builder: (_) {
                          final f = fixedRate(base, c);
                          final r = localRate(base, c, null);
                          return Text(tr(r == null ? 'Aucun cours' : '1 $c = ${number(r, decimals: r == r.roundToDouble() ? 0 : 3)} ${base == 'XOF' ? 'F CFA' : base}${f != null ? ' (parité fixe)' : ''}'),
                              style: TextStyle(color: r == null ? YColors.warning : null, fontWeight: FontWeight.w600));
                        }),
                        if (manage && fixedRate(base, c) == null)
                          Align(alignment: Alignment.centerLeft, child: TextButton(onPressed: () => _addRate(c), child: Text(tr('Mettre à jour')))),
                      ]),
                    ),
                  ),
              ]),
              const SizedBox(height: 16),
              SectionCard(
                title: tr('Historique des cours'),
                child: rates.isEmpty
                    ? Text(tr(['XOF', 'XAF'].contains(base) ? 'Aucun cours saisi. L\'euro est converti à la parité fixe de 655,957 F CFA.' : 'Aucun cours saisi.'), style: TextStyle(color: Theme.of(context).hintColor))
                    : Column(children: [
                        for (final r in rates.take(100))
                          ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.zero,
                            leading: Text(tr('${r['currency']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                            title: Text(tr('1 ${r['currency']} = ${number(toDouble(r['rate']), decimals: 3)} $base')),
                            subtitle: Text(tr('À partir du ${dateFr(r['date'])}${r['note'] != null ? ' · ${r['note']}' : ''}')),
                            trailing: manage
                                ? IconButton(
                                    icon: const Icon(Icons.delete_outline, size: 18),
                                    onPressed: () async {
                                      if (await confirm(context, tr('Supprimer ce cours ?'), tr('Les documents déjà établis gardent leur cours.'), danger: true) && context.mounted) {
                                        await context.read<SyncEngine>().remove('fx_rates', '${r['id']}');
                                      }
                                    },
                                  )
                                : null,
                          ),
                      ]),
              ),
            ]);
          },
        ),
      ),
    ]);
  }
}
