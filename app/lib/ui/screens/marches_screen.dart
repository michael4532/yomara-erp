import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../pdf/rh_document_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import 'insights_view.dart' show compactMoney;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Appels d'offres & marchés : veille, dossier de soumission, pièces administratives, cautions, marchés et décomptes.
class MarchesScreen extends StatefulWidget {
  const MarchesScreen({super.key});

  @override
  State<MarchesScreen> createState() => _MarchesScreenState();
}

class _MarchesScreenState extends State<MarchesScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    void changed() => _dash.currentState?.reload();
    final tabs = <(String, Widget)>[
      ('Appels d\'offres', RecordsView(module: 'marches_publics', resource: 'tenders', emptyIcon: Icons.campaign_outlined, newLabel: tr('Nouvel appel d\'offres'), onChanged: changed,
          emptyMessage: tr('Enregistrez les appels d\'offres repérés : compte à rebours, pièces à réunir et rappels avant la date de dépôt.'), actions: tenderActions)),
      ('Pièces du dossier', RecordsView(module: 'marches_publics', resource: 'pieces', emptyIcon: Icons.checklist_outlined, newLabel: tr('Nouvelle pièce'), onChanged: changed,
          emptyMessage: tr('La liste des pièces est créée dès que vous décidez de soumissionner.'))),
      ('Marchés', RecordsView(module: 'marches_publics', resource: 'contracts', emptyIcon: Icons.handshake_outlined, newLabel: tr('Nouveau marché'), onChanged: changed, actions: contractActions)),
      ('Décomptes', RecordsView(module: 'marches_publics', resource: 'statements', emptyIcon: Icons.request_quote_outlined, newLabel: tr('Nouveau décompte'), onChanged: changed, actions: statementActions)),
      ('Cautions', RecordsView(module: 'marches_publics', resource: 'bonds', emptyIcon: Icons.account_balance_outlined, newLabel: tr('Nouvelle caution'), onChanged: changed)),
      ('Pièces administratives', RecordsView(module: 'marches_publics', resource: 'documents', emptyIcon: Icons.folder_shared_outlined, newLabel: tr('Nouvelle pièce administrative'), onChanged: changed,
          emptyMessage: tr('Attestation fiscale, CNPS, RCCM, DFE… : saisies une fois, reprises dans chaque dossier, avec alerte avant expiration.'))),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Appels d\'offres & marchés'), subtitle: tr('Soumissions, cautions, marchés, décomptes et réceptions')),
        ModuleDashboard(
          key: _dash,
          path: 'marches_publics/dashboard',
          builder: (context, d, _) => StatStrip(tiles: [
            StatTile(label: tr('Appels d\'offres en cours'), value: '${d['open']}', detail: tr('${d['due_week']} dépôt(s) dans les 7 jours · ${d['submitted']} offre(s) déposée(s)'), icon: Icons.campaign_outlined),
            StatTile(label: tr('Taux de réussite'), value: d['win_rate'] == null ? '—' : '${d['win_rate']} %', detail: tr('${d['won_year']} marché(s) gagné(s) cette année'), icon: Icons.emoji_events_outlined, color: YColors.success),
            StatTile(label: tr('Reste à exécuter'), value: compactMoney(toDouble(d['backlog']), cur), detail: tr('${d['contracts']} marché(s) · ${d['late']} en retard · retenues ${compactMoney(toDouble(d['retention']), cur)}'), icon: Icons.handshake_outlined, color: const Color(0xFFD97706)),
            StatTile(label: tr('Cautions en cours'), value: compactMoney(toDouble(d['bonds_amount']), cur), detail: tr('${d['bonds_count']} caution(s) · ${d['docs_expiring']} pièce(s) à renouveler'), icon: Icons.account_balance_outlined, color: const Color(0xFF9333EA)),
          ]),
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

/// Appel d'offres : pièces du dossier, bordereau en PDF, création du marché.
List<Widget> tenderActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  return [
    OutlinedButton.icon(
      icon: const Icon(Icons.checklist_outlined, size: 18),
      label: Text(tr('Pièces du dossier')),
      onPressed: () => showDialog<void>(
        context: dialog,
        builder: (c) => Dialog(
          child: SizedBox(
            width: 980,
            height: 640,
            child: Column(children: [
              ListTile(title: Text(tr('Pièces du dossier')), subtitle: Text(verbatim('${row['title']}')), trailing: IconButton(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close))),
              Expanded(child: RecordsView(module: 'marches_publics', resource: 'pieces', filters: {'tender_id': '${row['id']}'}, newLabel: tr('Nouvelle pièce'), onChanged: reload)),
            ]),
          ),
        ),
      ),
    ),
    OutlinedButton.icon(
      icon: const Icon(Icons.picture_as_pdf_outlined, size: 18),
      label: Text(tr('Bordereau des pièces')),
      onPressed: () async {
        try {
          final r = await s.api.get('marches_publics/records/pieces', query: {'tender_id': '${row['id']}'});
          final list = [for (final x in ((r as Map)['data'] as List)) asMap(x)].where((p) => p['status'] != 'na').toList();
          const cats = {'admin': 'Dossier administratif', 'technical': 'Offre technique', 'financial': 'Offre financière'};
          final b = StringBuffer('${tr('Objet')} : ${row['title']}\n');
          if (row['reference'] != null) b.writeln('${tr('Appel d\'offres n°')} ${row['reference']}');
          if (row['buyer'] != null) b.writeln('${tr('Autorité contractante')} : ${row['buyer']}');
          if (row['lot'] != null) b.writeln('${tr('Lot(s)')} : ${row['lot']}');
          var n = 0;
          for (final c in cats.entries) {
            final ps = list.where((p) => p['category'] == c.key).toList();
            if (ps.isEmpty) continue;
            b.writeln('\n${tr(c.value).toUpperCase()}');
            for (final p in ps) {
              n++;
              b.writeln('$n. ${p['title']}${p['status'] == 'ready' ? '' : '   [${tr('à fournir')}]'}');
            }
          }
          final st = s.companySettings;
          final bytes = await rhDocumentPdf(company: s.company, doc: {'type': 'bordereau', 'title': tr('Bordereau des pièces du dossier de soumission'), 'number': row['reference'], 'date': isoDate(DateTime.now()), 'body': b.toString()},
              employeeName: '${row['buyer'] ?? ''}', signatory: st['legal_signatory'] as String?, signatoryTitle: st['legal_signatory_title'] as String?, partyLabel: tr('Reçu par'), companyLabel: tr('Le soumissionnaire'));
          final where = await saveFile('Bordereau ${row['reference'] ?? row['id']}.pdf'.replaceAll('/', '-'), bytes, mime: 'application/pdf');
          if (dialog.mounted) toast(dialog, tr('PDF enregistré : $where'));
        } catch (e) {
          if (dialog.mounted) toast(dialog, errorText(e), error: true);
        }
      },
    ),
    if (s.can('marches.manage') && row['status'] != 'won' && row['status'] != 'lost')
      OutlinedButton.icon(
        icon: const Icon(Icons.emoji_events_outlined, size: 18),
        label: Text(tr('Marché gagné')),
        onPressed: () async {
          if (!await confirm(dialog, tr('Marché gagné'), tr('L\'appel d\'offres passe « Attribué » et le marché est créé à partir de votre offre.'), ok: tr('Créer le marché'))) return;
          try {
            await s.api.post('marches_publics/tenders/${row['id']}/award');
            if (dialog.mounted) {
              Navigator.pop(dialog, <String, dynamic>{'awarded': true});
              toast(dialog, tr('Marché créé : complétez l\'ordre de service, le délai, l\'avance et la retenue dans l\'onglet Marchés.'));
            }
            reload();
          } catch (e) {
            if (dialog.mounted) toast(dialog, errorText(e), error: true);
          }
        },
      ),
  ];
}

/// Marché : décomptes du marché.
List<Widget> contractActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final cur = dialog.read<Session>().currency;
  return [
    OutlinedButton.icon(
      icon: const Icon(Icons.request_quote_outlined, size: 18),
      label: Text(tr('Décomptes')),
      onPressed: () => showDialog<void>(
        context: dialog,
        builder: (c) => Dialog(
          child: SizedBox(
            width: 980,
            height: 640,
            child: Column(children: [
              ListTile(
                title: Text(verbatim('${row['title']}')),
                subtitle: Text(tr('Exécuté ${money(toDouble(row['executed']), cur)} · retenues ${money(toDouble(row['retention_held']), cur)} · payé ${money(toDouble(row['paid']), cur)}')),
                trailing: IconButton(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close)),
              ),
              Expanded(child: RecordsView(module: 'marches_publics', resource: 'statements', filters: {'contract_id': '${row['id']}'}, newLabel: tr('Nouveau décompte'), actions: statementActions, onChanged: reload)),
            ]),
          ),
        ),
      ),
    ),
  ];
}

/// Décompte : facture brouillon au service ventes.
List<Widget> statementActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  if (row['invoice_id'] != null || !s.can('marches.manage') || !s.hasModule('ventes')) return const [];
  return [
    FilledButton.tonalIcon(
      icon: const Icon(Icons.receipt_long_outlined, size: 18),
      label: Text(tr('Facturer')),
      onPressed: () async {
        try {
          await s.api.post('marches_publics/statements/${row['id']}/invoice');
          if (dialog.mounted) {
            Navigator.pop(dialog, <String, dynamic>{'invoiced': true});
            toast(dialog, tr('Facture créée en brouillon dans Ventes : montant du décompte, retenue de garantie et remboursement de l\'avance.'));
          }
          reload();
        } catch (e) {
          if (dialog.mounted) toast(dialog, errorText(e), error: true);
        }
      },
    ),
  ];
}
