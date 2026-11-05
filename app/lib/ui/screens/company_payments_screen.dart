import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'admin/admin_common.dart';
import 'payment_widgets.dart';
import '../../i18n/i18n.dart';

/// Moyens de paiement de la société pour encaisser ses factures (ses propres comptes) et coordonnées bancaires.
class CompanyPaymentsScreen extends StatelessWidget {
  const CompanyPaymentsScreen({super.key, this.embedded = false});

  /// Ouvert depuis le menu (dans la coque) plutôt que depuis les Paramètres (page empilée).
  final bool embedded;

  @override
  Widget build(BuildContext context) {
    final body = AdminLoader(
        path: 'payments/setup',
        builder: (context, d, reload) {
          final api = context.read<Session>().api;
          final instructions = TextEditingController(text: '${d['payment_instructions'] ?? ''}');
          List<Map<String, dynamic>> maps(String k) => List<Map<String, dynamic>>.from((d[k] as List? ?? []).map((e) => Map<String, dynamic>.from(e)));
          final narrow = MediaQuery.sizeOf(context).width < 700;
          return ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 8, narrow ? 16 : 24, 24), children: [
            // Comment ça marche : trois étapes illustrées plutôt qu'un paragraphe
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Wrap(spacing: 16, runSpacing: 14, children: [
                  for (final (i, t) in const [
                    (Icons.link_rounded, 'Branchez votre compte', 'CinetPay, Wave, PayDunya, Orange Money, MTN MoMo, PayPal… ou un lien de paiement marchand.'),
                    (Icons.receipt_long_outlined, 'Envoyez vos factures', 'Chaque facture validée part avec un bouton « Payer en ligne ».'),
                    (Icons.verified_outlined, 'Encaissement automatique', 'Le paiement est enregistré sur la facture et en comptabilité. L\'argent arrive directement sur votre compte.'),
                  ].indexed)
                    SizedBox(
                      width: narrow ? double.infinity : 330,
                      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Container(
                          width: 40,
                          height: 40,
                          decoration: BoxDecoration(color: [YColors.blue, YColors.warning, YColors.success][i].withValues(alpha: .11), borderRadius: BorderRadius.circular(11)),
                          child: Icon(t.$1, size: 20, color: [YColors.blue, YColors.warning, YColors.success][i]),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(tr(t.$2), style: const TextStyle(fontWeight: FontWeight.w700)),
                            const SizedBox(height: 2),
                            Text(tr(t.$3), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5, height: 1.35)),
                          ]),
                        ),
                      ]),
                    ),
                ]),
              ),
            ),
            const SizedBox(height: 16),
            GatewaysEditor(drivers: maps('drivers'), gateways: maps('gateways'), endpoint: 'payments/gateways', onChanged: reload, treasuryAccounts: maps('treasury_accounts')),
            const SizedBox(height: 16),
            BankAccountsEditor(
              accounts: maps('bank_accounts'),
              withInvoiceFlag: true,
              extra: Field(
                label: tr('Mention de paiement sur les factures'),
                child: TextField(controller: instructions, maxLines: 2, decoration: InputDecoration(hintText: tr('Ex. Paiement à 30 jours. Merci d\'indiquer le numéro de facture.'))),
              ),
              onSave: (list) async {
                await api.put('payments/bank-accounts', {'bank_accounts': list, 'payment_instructions': instructions.text.trim().isEmpty ? null : instructions.text.trim()});
                if (context.mounted) await context.read<Session>().refreshBootstrap();
                reload();
              },
            ),
          ]);
        },
      );
    if (embedded) {
      return Column(children: [
        PageHeader(title: tr('Moyens de paiement'), subtitle: tr('Vos propres comptes pour encaisser vos clients : paiement en ligne, Mobile Money, virement')),
        Expanded(child: body),
      ]);
    }
    return Scaffold(appBar: AppBar(title: Text(tr('Encaissements de vos clients'))), body: body);
  }
}
