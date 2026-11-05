import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../pdf/rh_document_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import 'signature_screen.dart' show newSignatureRequest;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Espace du service juridique : registre des contrats, audiences et échéances, contentieux, modèles.
class JuridiqueScreen extends StatefulWidget {
  const JuridiqueScreen({super.key});

  @override
  State<JuridiqueScreen> createState() => _JuridiqueScreenState();
}

class _JuridiqueScreenState extends State<JuridiqueScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    void changed() => _dash.currentState?.reload();
    final tabs = <(String, Widget)>[
      ('Contrats', RecordsView(module: 'juridique', resource: 'contracts', emptyIcon: Icons.description_outlined, newLabel: tr('Nouveau contrat'), onChanged: changed,
          emptyMessage: tr('Enregistrez vos contrats en cours pour ne plus manquer une échéance ni un préavis.'), actions: (d, row, reload) => contractActions(d, row, reload))),
      ('Audiences & échéances', RecordsView(module: 'juridique', resource: 'deadlines', emptyIcon: Icons.event_outlined, newLabel: tr('Nouvelle échéance'), onChanged: changed, filters: const {'done': '0'})),
      ('Contentieux', RecordsView(module: 'juridique', resource: 'cases', emptyIcon: Icons.gavel_outlined, newLabel: tr('Nouveau dossier'), onChanged: changed)),
      ('Modèles', RecordsView(module: 'juridique', resource: 'templates', emptyIcon: Icons.article_outlined, newLabel: tr('Nouveau modèle'))),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Juridique & contrats'), subtitle: tr('Contrats, échéances et préavis, contentieux, audiences')),
        ModuleDashboard(
          key: _dash,
          path: 'juridique/dashboard',
          builder: (context, d, _) => StatStrip(tiles: [
            StatTile(label: tr('Contrats en vigueur'), value: '${d['active']}', detail: tr('${d['to_sign']} en négociation ou à signer'), icon: Icons.description_outlined),
            StatTile(label: tr('À renouveler ou résilier'), value: '${d['alerts']}', detail: tr('préavis dans les 60 jours'), icon: Icons.notification_important_outlined, color: YColors.danger),
            StatTile(label: tr('Dossiers ouverts'), value: '${d['open_cases']}', detail: tr('${money(toDouble(d['at_stake']), cur)} en jeu · ${d['high_risk']} à risque élevé'), icon: Icons.gavel_outlined, color: const Color(0xFF9333EA)),
            StatTile(label: tr('Audiences & échéances'), value: '${d['deadlines_week']}', detail: tr('dans les 7 prochains jours'), icon: Icons.event_outlined, color: const Color(0xFFD97706)),
          ]),
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

/// Boutons d'un contrat : rédaction depuis un modèle, PDF, signature électronique.
List<Widget> contractActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  Future<Map<String, dynamic>> pdfDoc() async => {
        'type': 'contract', 'title': row['title'], 'number': row['reference'], 'date': row['signed_on'] ?? isoDate(DateTime.now()), 'body': row['body'] ?? '',
      };
  final party = '${row['partner_id_label'] ?? row['employee_id_label'] ?? row['counterparty'] ?? ''}';
  final st = s.companySettings;
  return [
    if (s.can('juridique.manage'))
      OutlinedButton.icon(
        icon: const Icon(Icons.auto_fix_high_outlined, size: 18),
        label: Text(tr('Rédiger depuis un modèle')),
        onPressed: () async {
          try {
            final r = await s.api.get('juridique/records/templates', query: {'active': '1'});
            final list = [for (final x in ((r as Map)['data'] as List)) asMap(x)];
            if (!dialog.mounted) return;
            final sorted = [...list.where((t) => t['kind'] == row['kind']), ...list.where((t) => t['kind'] != row['kind'])];
            final tpl = await showDialog<Map<String, dynamic>>(
              context: dialog,
              builder: (c) => SimpleDialog(title: Text(tr('Choisir un modèle')), children: [
                for (final t in sorted)
                  SimpleDialogOption(
                    onPressed: () => Navigator.pop(c, t),
                    child: ListTile(dense: true, leading: const Icon(Icons.article_outlined), title: Text(tr('${t['title']}')), trailing: t['kind'] == row['kind'] ? StatusChip(tr('Conseillé'), color: YColors.success) : null),
                  ),
              ]),
            );
            if (tpl == null || !dialog.mounted) return;
            if ((row['body'] ?? '').toString().trim().isNotEmpty &&
                !await confirm(dialog, tr('Remplacer le texte'), tr('Le texte actuel du contrat sera remplacé par le modèle rempli.'), ok: tr('Remplacer'))) {
              return;
            }
            await s.api.post('juridique/contracts/${row['id']}/draft', {'template_id': tpl['id']});
            if (dialog.mounted) {
              Navigator.pop(dialog, <String, dynamic>{'drafted': true});
              toast(dialog, tr('Texte du contrat rédigé à partir de « ${tpl['title']} » : relisez-le et complétez les mentions [à compléter].'));
            }
            reload();
          } catch (e) {
            if (dialog.mounted) toast(dialog, errorText(e), error: true);
          }
        },
      ),
    if ((row['body'] ?? '').toString().trim().isNotEmpty) ...[
      OutlinedButton.icon(
        icon: const Icon(Icons.picture_as_pdf_outlined, size: 18),
        label: Text(tr('PDF')),
        onPressed: () async {
          try {
            final bytes = await rhDocumentPdf(company: s.company, doc: await pdfDoc(), employeeName: party, signatory: st['legal_signatory'] as String? ?? st['rh_signatory'] as String?,
                signatoryTitle: st['legal_signatory_title'] as String? ?? st['rh_signatory_title'] as String?, partyLabel: row['kind'] == 'employment' ? 'Le Salarié' : 'Le Cocontractant',
                companyLabel: row['kind'] == 'employment' ? 'L\'Employeur' : 'Pour la Société');
            final where = await saveFile('${row['reference'] ?? row['title']}.pdf'.replaceAll('/', '-'), bytes, mime: 'application/pdf');
            if (dialog.mounted) toast(dialog, tr('PDF enregistré : $where'));
          } catch (e) {
            if (dialog.mounted) toast(dialog, errorText(e), error: true);
          }
        },
      ),
      if (s.hasModule('signature'))
        OutlinedButton.icon(
          icon: const Icon(Icons.draw_outlined, size: 18),
          label: Text(tr('Faire signer')),
          onPressed: () async {
            try {
              final bytes = await rhDocumentPdf(company: s.company, doc: await pdfDoc(), employeeName: party, signatory: st['rh_signatory'] as String?, signatoryTitle: st['rh_signatory_title'] as String?,
                  partyLabel: row['kind'] == 'employment' ? 'Le Salarié' : 'Le Cocontractant', companyLabel: row['kind'] == 'employment' ? 'L\'Employeur' : 'Pour la Société');
              if (!dialog.mounted) return;
              await newSignatureRequest(dialog, bytes: bytes, fileName: '${row['reference'] ?? 'contrat-${row['id']}'}.pdf', title: '${row['title']}', sourceType: 'jr_contract', sourceId: 'jr-${row['id']}',
                  signers: [
                    {'name': party, 'label': row['kind'] == 'employment' ? 'Le Salarié' : 'Le Cocontractant'},
                    {'user_id': s.user['id'], 'name': s.user['name'], 'label': 'Pour la Société'},
                  ]);
            } catch (e) {
              if (dialog.mounted) toast(dialog, errorText(e), error: true);
            }
          },
        ),
    ],
  ];
}
