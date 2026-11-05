import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/records_offline.dart';
import '../../state/session.dart';
import 'common.dart';
import '../../i18n/i18n.dart';

/// Bouton « Ajouter au rapport » : note l'activité (avec commentaire et temps passé) dans le rapport de la semaine.
Future<void> addToReport(BuildContext context, {required String title, String? module, String? type, String? id, String? details}) async {
  final s = context.read<Session>();
  final t = TextEditingController(text: title);
  final d = TextEditingController(text: details ?? '');
  var day = DateTime.now();
  var minutes = 0;
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Row(children: [Icon(Icons.post_add, size: 22), SizedBox(width: 8), Text(tr('Ajouter au rapport'))]),
        content: SizedBox(
          width: 460,
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: t, decoration: InputDecoration(labelText: tr('Ce que j\'ai fait *')), maxLines: 2, minLines: 1),
              const SizedBox(height: 10),
              TextField(controller: d, decoration: InputDecoration(labelText: tr('Détails, résultat (facultatif)'), alignLabelWithHint: true), maxLines: 5, minLines: 2),
              const SizedBox(height: 10),
              Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.event, size: 18),
                    label: Text(tr(dateFr(isoDate(day)))),
                    onPressed: () async {
                      final x = await showDatePicker(context: c, initialDate: day, firstDate: DateTime.now().subtract(const Duration(days: 60)), lastDate: DateTime.now().add(const Duration(days: 7)));
                      if (x != null) set(() => day = x);
                    },
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: DropdownButtonFormField<int>(
                    initialValue: minutes,
                    decoration: InputDecoration(labelText: tr('Temps passé')),
                    items: [
                      DropdownMenuItem(value: 0, child: Text('—')),
                      DropdownMenuItem(value: 15, child: Text(tr('15 min'))),
                      DropdownMenuItem(value: 30, child: Text(tr('30 min'))),
                      DropdownMenuItem(value: 60, child: Text('1 h')),
                      DropdownMenuItem(value: 90, child: Text('1 h 30')),
                      DropdownMenuItem(value: 120, child: Text('2 h')),
                      DropdownMenuItem(value: 180, child: Text('3 h')),
                      DropdownMenuItem(value: 240, child: Text(tr('½ journée'))),
                      DropdownMenuItem(value: 480, child: Text(tr('1 journée'))),
                    ],
                    onChanged: (v) => set(() => minutes = v ?? 0),
                  ),
                ),
              ]),
            ]),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => t.text.trim().isEmpty ? null : Navigator.pop(c, true), child: Text(tr('Ajouter'))),
        ],
      ),
    ),
  );
  if (ok != true || !context.mounted) return;
  try {
    final r = await RecordsOffline.send(s.api, 'POST', 'activite/entries', {
      'title': t.text.trim(), if (d.text.trim().isNotEmpty) 'details': d.text.trim(), 'date': isoDate(day), if (minutes > 0) 'minutes': minutes,
      'source_module': ?module, 'source_type': ?type, 'source_id': ?id,
    }, 'Activité « ${t.text.trim()} »');
    if (!context.mounted) return;
    if ((r as Map)['offline'] == true) return toast(context, tr('Pas de connexion : activité notée sur l\'appareil, ajoutée au rapport au retour du réseau.'));
    final sent = r['data']?['report_already_sent'] == true;
    toast(context, sent ? 'Ajouté. Le rapport de cette période est déjà envoyé : l\'activité figurera dans la période suivante si vous changez la date.' : 'Ajouté à votre rapport d\'activité.');
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

/// Bouton prêt à l'emploi (rien si le module n'est pas actif).
class AddToReportButton extends StatelessWidget {
  const AddToReportButton({super.key, required this.title, this.module, this.type, this.id, this.details, this.compact = false});

  final String title;
  final String? module;
  final String? type;
  final String? id;
  final String? details;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    if (!s.hasModule('activite') || !s.can('activite.use')) return const SizedBox.shrink();
    void tap() => addToReport(context, title: title, module: module, type: type, id: id, details: details);
    return compact
        ? IconButton(tooltip: tr('Ajouter au rapport'), onPressed: tap, icon: const Icon(Icons.post_add))
        : OutlinedButton.icon(onPressed: tap, icon: const Icon(Icons.post_add, size: 18), label: Text(tr('Ajouter au rapport')));
  }
}
