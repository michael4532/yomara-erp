import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'mailbox/mail_theme.dart' show QrBox;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Liens de paiement libres : un montant à régler en ligne (Mobile Money, carte) sans facture préalable.
class PaymentLinksScreen extends StatefulWidget {
  const PaymentLinksScreen({super.key});

  @override
  State<PaymentLinksScreen> createState() => _PaymentLinksScreenState();
}

class _PaymentLinksScreenState extends State<PaymentLinksScreen> {
  List<Map<String, dynamic>>? _list;
  int _online = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('payments/links');
      if (mounted) setState(() => (_list = [for (final x in (r['data'] as List)) asMap(x)], _online = (r['online_methods'] as num?)?.toInt() ?? 0));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  void _share(Map<String, dynamic> l) {
    final cur = context.read<Session>().currency;
    final text = '${l['label']}${l['amount'] != null ? ' : ${money(l['amount'], cur)}' : ''}\nPayez en ligne (Mobile Money, carte) : ${l['url']}';
    showDialog(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('${l['label']}')),
        content: SizedBox(
          width: 360,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            QrBox(data: '${l['url']}', size: 190),
            const SizedBox(height: 10),
            SelectableText(tr('${l['url']}'), style: const TextStyle(fontSize: 12), textAlign: TextAlign.center),
          ]),
        ),
        actions: [
          TextButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent(text)}'), mode: LaunchMode.externalApplication), icon: const Icon(Icons.chat_outlined), label: Text(tr('WhatsApp'))),
          TextButton.icon(
            onPressed: () {
              Clipboard.setData(ClipboardData(text: '${l['url']}'));
              toast(c, tr('Lien copié.'));
            },
            icon: const Icon(Icons.copy),
            label: Text(tr('Copier')),
          ),
          FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
        ],
      ),
    );
  }

  Future<void> _new() async {
    final created = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => const _NewLinkDialog());
    if (created == null) return;
    await _load();
    if (mounted) _share(created);
  }

  String _q = '';
  String _st = '';
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final all = _list;
    final list = all?.where((l) {
      if (_st.isNotEmpty && '${l['status']}' != _st) return false;
      if (_period != null && !_period!.contains(l['created_at'])) return false;
      if (_q.isNotEmpty && !'${l['label']} ${l['customer_name'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) return false;
      return true;
    }).toList();
    final collected = (list ?? []).fold<double>(0, (a, l) => a + ((l['collected'] as num?)?.toDouble() ?? 0));
    return Column(children: [
      PageHeader(title: tr('Liens de paiement'), subtitle: tr('Faites payer un acompte, une prestation ou une commande Instagram en un lien, sans facture préalable'), actions: [
        FilledButton.icon(onPressed: _new, icon: const Icon(Icons.add_link), label: Text(tr('Nouveau lien'))),
      ]),
      if (all != null && all.isNotEmpty)
        FilterBar(
          onSearch: (v) => setState(() => _q = v),
          search: _q,
          searchHint: 'Libellé, client…',
          statuses: const {'': 'Tous', 'open': 'En attente', 'paid': 'Payés', 'expired': 'Expirés', 'cancelled': 'Annulés'},
          status: _st,
          statusCounts: {'': all.length, for (final k in ['open', 'paid', 'expired', 'cancelled']) k: all.where((l) => l['status'] == k).length},
          onStatus: (v) => setState(() => _st = v),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: list?.length,
          countLabel: 'lien',
          onReset: () => setState(() => (_st = '', _period = null)),
        ),
      if (list != null && _online == 0)
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
          child: Card(
            color: const Color(0xFFFFF6DB),
            child: ListTile(
              leading: const Icon(Icons.info_outline, color: Color(0xFF9A6B00)),
              title: Text(tr('Aucun moyen de paiement en ligne n\'est branché')),
              subtitle: Text(tr('Paramètres › Encaissements de vos clients : ajoutez CinetPay, Wave, Orange Money… pour que vos liens soient payables.')),
            ),
          ),
        ),
      Expanded(
        child: list == null
            ? const Center(child: CircularProgressIndicator())
            : list.isEmpty
                ? EmptyState(icon: Icons.link, title: tr('Aucun lien de paiement'), message: tr('Créez un lien, partagez-le sur WhatsApp ou en QR code : le paiement arrive avec sa facture, déjà rapproché en trésorerie.'))
                : ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
                    Text(tr('${list.length} lien(s) · ${money(collected, cur)} encaissés'), style: TextStyle(color: hint)),
                    const SizedBox(height: 8),
                    Card(
                      child: Column(children: [
                        for (final l in list)
                          ListTile(
                            leading: CircleAvatar(
                              backgroundColor: (l['status'] == 'paid' ? YColors.success : (l['status'] == 'open' ? const Color(0xFF1F6FEB) : YColors.muted)).withValues(alpha: .12),
                              child: Icon(l['reusable'] == true ? Icons.all_inclusive : Icons.link, color: l['status'] == 'paid' ? YColors.success : (l['status'] == 'open' ? const Color(0xFF1F6FEB) : YColors.muted)),
                            ),
                            title: Text(tr('${l['label']}')),
                            subtitle: Text(
                              tr([
                                l['amount'] != null ? money(l['amount'], cur) : 'Montant libre${l['min_amount'] != null ? ' (min. ${money(l['min_amount'], cur)})' : ''}',
                                if (l['customer_name'] != null) '${l['customer_name']}',
                                if ((l['payments_count'] ?? 0) > 0) '${l['payments_count']} paiement(s) · ${money(l['collected'], cur)}',
                                if (l['last_document'] != null) 'Facture ${l['last_document']['number']}',
                                if (l['created_at'] != null) DateFormat('d MMM', 'fr_FR').format(DateTime.parse('${l['created_at']}')),
                              ].join(' · ')),
                              style: TextStyle(color: hint, fontSize: 12.5),
                            ),
                            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                              StatusChip(
                                switch ('${l['status']}') { 'paid' => 'Payé', 'open' => l['reusable'] == true ? 'Permanent' : 'En attente', 'expired' => 'Expiré', _ => 'Annulé' },
                                color: switch ('${l['status']}') { 'paid' => YColors.success, 'open' => const Color(0xFF1F6FEB), _ => YColors.muted },
                              ),
                              IconButton(tooltip: tr('Partager'), onPressed: () => _share(l), icon: const Icon(Icons.qr_code_2)),
                              if (l['status'] == 'open')
                                IconButton(
                                  tooltip: tr('Désactiver'),
                                  onPressed: () async {
                                    if (!await confirm(context, tr('Désactiver ce lien ?'), tr('Il ne pourra plus être payé.'), ok: tr('Désactiver'), danger: true)) return;
                                    if (!context.mounted) return;
                                    await context.read<Session>().api.post('payments/links/${l['id']}/cancel');
                                    _load();
                                  },
                                  icon: const Icon(Icons.link_off),
                                ),
                            ]),
                          ),
                      ]),
                    ),
                  ]),
      ),
    ]);
  }
}

class _NewLinkDialog extends StatefulWidget {
  const _NewLinkDialog();

  @override
  State<_NewLinkDialog> createState() => _NewLinkDialogState();
}

class _NewLinkDialogState extends State<_NewLinkDialog> {
  final _label = TextEditingController(), _desc = TextEditingController(), _amount = TextEditingController(), _min = TextEditingController();
  bool _free = false, _reusable = false, _busy = false;
  Map<String, dynamic>? _partner;

  @override
  Widget build(BuildContext context) {
    final partners = LocalDb.all('partners').where((p) => p['type'] != 'supplier' && p['deleted_at'] == null).toList();
    return YAlertDialog(
      title: Text(tr('Nouveau lien de paiement')),
      content: SizedBox(
        width: 480,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            TextField(controller: _label, autofocus: true, decoration: InputDecoration(labelText: tr('Objet du paiement'), hintText: tr('Acompte formation, robe wax taille M, cotisation…'))),
            TextField(controller: _desc, maxLines: 2, decoration: InputDecoration(labelText: tr('Détails (facultatif)'))),
            const SizedBox(height: 8),
            SegmentedButton<bool>(
              segments: [ButtonSegment(value: false, label: Text(tr('Montant fixe'))), ButtonSegment(value: true, label: Text(tr('Montant libre')))],
              selected: {_free},
              onSelectionChanged: (v) => setState(() => _free = v.first),
            ),
            if (!_free) TextField(controller: _amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant TTC')))
            else TextField(controller: _min, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant minimum (facultatif)'))),
            const SizedBox(height: 8),
            Autocomplete<Map<String, dynamic>>(
              displayStringForOption: (p) => '${p['name']}',
              optionsBuilder: (v) => v.text.length < 2 ? const [] : partners.where((p) => '${p['name']}'.toLowerCase().contains(v.text.toLowerCase())).take(8),
              onSelected: (p) => setState(() => _partner = p),
              fieldViewBuilder: (c, ctrl, f, s) => TextField(controller: ctrl, focusNode: f, decoration: InputDecoration(labelText: tr('Client (facultatif)'), prefixIcon: Icon(Icons.person_search_outlined))),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: _reusable,
              onChanged: (v) => setState(() => _reusable = v),
              title: Text(tr('Lien permanent')),
              subtitle: Text(tr('Payable plusieurs fois (dons, cotisations, bio Instagram)')),
            ),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: _busy
              ? null
              : () async {
                  if (_label.text.trim().isEmpty) return;
                  setState(() => _busy = true);
                  try {
                    final r = await context.read<Session>().api.post('payments/links', {
                      'label': _label.text.trim(), if (_desc.text.trim().isNotEmpty) 'description': _desc.text.trim(),
                      if (!_free) 'amount': num.tryParse(_amount.text.replaceAll(' ', '')), if (_free && _min.text.trim().isNotEmpty) 'min_amount': num.tryParse(_min.text.replaceAll(' ', '')),
                      if (_partner != null) 'partner_id': _partner!['id'], 'reusable': _reusable,
                    });
                    if (context.mounted) Navigator.pop(context, asMap(r['data']));
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  } finally {
                    if (mounted) setState(() => _busy = false);
                  }
                },
          child: Text(tr('Créer le lien')),
        ),
      ],
    );
  }
}
