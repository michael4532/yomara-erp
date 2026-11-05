import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Liste et configuration des moyens de paiement (plateforme ou société).
class GatewaysEditor extends StatelessWidget {
  const GatewaysEditor({super.key, required this.drivers, required this.gateways, required this.endpoint, required this.onChanged, this.intro, this.treasuryAccounts = const []});

  final List<Map<String, dynamic>> drivers;
  final List<Map<String, dynamic>> gateways;
  final String endpoint; // admin/payment-gateways ou payments/gateways
  final VoidCallback onChanged;
  final String? intro;
  final List<Map<String, dynamic>> treasuryAccounts;

  @override
  Widget build(BuildContext context) {
    final byDriver = {for (final d in drivers) d['driver']: d};
    final hint = Theme.of(context).hintColor;
    return SectionCard(
      title: tr('Moyens de paiement'),
      trailing: FilledButton.icon(onPressed: () => _edit(context, null), icon: const Icon(Icons.add), label: Text(tr('Ajouter'))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (intro != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(tr(intro!), style: TextStyle(color: hint))),
        if (gateways.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 12), child: Text(tr('Aucun moyen de paiement configuré.'), style: TextStyle(color: hint))),
        for (final g in gateways)
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(
              backgroundColor: (g['online'] == true ? YColors.blue : YColors.muted).withValues(alpha: .12),
              child: Icon(g['online'] == true ? Icons.bolt : Icons.account_balance_outlined, color: g['online'] == true ? YColors.blue : YColors.muted),
            ),
            title: Text(tr('${g['label']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text(tr([
              '${byDriver[g['driver']]?['label'] ?? g['driver']}',
              if (g['online'] == true) (g['mode'] == 'test' ? 'mode test' : 'production'),
              if (g['online'] != true) 'validation manuelle',
            ].join(' · '))),
            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
              if (g['enabled'] != true) StatusChip(tr('Désactivé')),
              const Icon(Icons.chevron_right),
            ]),
            onTap: () => _edit(context, g),
          ),
      ]),
    );
  }

  Future<void> _edit(BuildContext context, Map<String, dynamic>? g) async {
    final changed = await showFormDialog<bool>(context, title: g == null ? 'Nouveau moyen de paiement' : '${g['label']}', builder: (c) => _GatewayForm(drivers: drivers, gateway: g, endpoint: endpoint, treasuryAccounts: treasuryAccounts));
    if (changed == true) onChanged();
  }
}

class _GatewayForm extends StatefulWidget {
  const _GatewayForm({required this.drivers, required this.gateway, required this.endpoint, this.treasuryAccounts = const []});

  final List<Map<String, dynamic>> drivers;
  final Map<String, dynamic>? gateway;
  final String endpoint;
  final List<Map<String, dynamic>> treasuryAccounts;

  @override
  State<_GatewayForm> createState() => _GatewayFormState();
}

class _GatewayFormState extends State<_GatewayForm> {
  late String _driver = '${widget.gateway?['driver'] ?? widget.drivers.first['driver']}';
  late final _label = TextEditingController(text: '${widget.gateway?['label'] ?? ''}');
  late final _instructions = TextEditingController(text: '${widget.gateway?['instructions'] ?? ''}');
  late String _mode = '${widget.gateway?['mode'] ?? 'live'}';
  late bool _enabled = widget.gateway?['enabled'] != false;
  late String? _account = widget.gateway?['treasury_account_id'] as String?;
  final Map<String, TextEditingController> _keys = {};
  bool _busy = false;

  Map<String, dynamic> get _meta => widget.drivers.firstWhere((d) => d['driver'] == _driver, orElse: () => widget.drivers.first);

  TextEditingController _key(String k) => _keys.putIfAbsent(k, () => TextEditingController());

  /// Vérifie les clés enregistrées auprès du prestataire (enregistrez d'abord les modifications).
  Future<void> _test() async {
    setState(() => _busy = true);
    try {
      final r = asMap(await context.read<Session>().api.post('${widget.endpoint}/${widget.gateway!['id']}/test'));
      if (mounted) toast(context, tr('${r['message']}'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _save() async {
    final api = context.read<Session>().api;
    final body = {
      'driver': _driver,
      'label': _label.text.trim().isEmpty ? _meta['label'] : _label.text.trim(),
      'mode': _mode,
      'enabled': _enabled,
      if (widget.treasuryAccounts.isNotEmpty) 'treasury_account_id': _account,
      'instructions': _instructions.text.trim().isEmpty ? null : _instructions.text.trim(),
      'credentials': {for (final k in asMap(_meta['fields']).keys) k: _key(k).text.trim()},
    };
    setState(() => _busy = true);
    try {
      if (widget.gateway == null) {
        await api.post(widget.endpoint, body);
      } else {
        body.remove('driver');
        await api.put('${widget.endpoint}/${widget.gateway!['id']}', body);
      }
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    if (!await confirm(context, tr('Supprimer ce moyen de paiement ?'), tr('Il ne sera plus proposé.'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    await context.read<Session>().api.delete('${widget.endpoint}/${widget.gateway!['id']}');
    if (mounted) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    final meta = _meta;
    final fields = asMap(meta['fields']);
    final masked = asMap(widget.gateway?['credentials_masked']);
    final online = meta['online'] == true;
    final hint = Theme.of(context).hintColor;
    final online1 = widget.drivers.where((d) => d['online'] == true).toList();
    final offline1 = widget.drivers.where((d) => d['online'] != true).toList();

    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Field(
        label: tr('Prestataire'),
        child: DropdownButtonFormField<String>(
          isExpanded: true,
          initialValue: _driver,
          items: [
            for (final d in [...online1, ...offline1])
              DropdownMenuItem(value: '${d['driver']}', child: Text(tr('${d['label']}${d['online'] == true ? '' : ' (hors ligne)'}'))),
          ],
          onChanged: widget.gateway != null ? null : (v) => setState(() => _driver = v!),
        ),
      ),
      if (meta['channels'] != null) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text(tr('Moyens : ${meta['channels']}'), style: TextStyle(color: hint, fontSize: 12))),
      if (meta['countries'] != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(tr('Pays : ${meta['countries']}'), style: TextStyle(color: hint, fontSize: 12))),
      Field(label: tr('Nom affiché au payeur'), child: TextField(controller: _label, decoration: InputDecoration(hintText: trn('${meta['label']}')))),
      if (online) ...[
        SegmentedButton<String>(
          segments: [ButtonSegment(value: 'test', label: Text(tr('Mode test'))), ButtonSegment(value: 'live', label: Text(tr('Production')))],
          selected: {_mode},
          onSelectionChanged: (s) => setState(() => _mode = s.first),
        ),
        const SizedBox(height: 14),
        for (final f in fields.entries)
          Field(
            label: '${f.value}',
            child: TextField(
              controller: _key(f.key),
              obscureText: f.key.contains('secret') || f.key.contains('private') || f.key.contains('key') || f.key == 'token',
              decoration: InputDecoration(hintText: trn(masked[f.key] != null && '${masked[f.key]}'.isNotEmpty ? '${masked[f.key]} (laisser vide pour conserver)' : null)),
            ),
          ),
        if (meta['docs'] != null)
          Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${meta['docs']}')), icon: const Icon(Icons.open_in_new, size: 16), label: Text(tr('Où trouver ces clés ?')))),
        Text(tr('Les clés sont chiffrées sur le serveur et ne sont jamais réaffichées.'), style: TextStyle(color: hint, fontSize: 12)),
        const SizedBox(height: 12),
        if (meta['webhook_url'] != null)
          Container(
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .07), borderRadius: BorderRadius.circular(12)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                const Icon(Icons.bolt, size: 18, color: YColors.blue),
                const SizedBox(width: 6),
                Expanded(child: Text(tr('Activation instantanée (recommandé)'), style: const TextStyle(fontWeight: FontWeight.w700))),
              ]),
              const SizedBox(height: 6),
              Text(
                tr('Dans le tableau de bord ${meta['label']}, ajoutez cette adresse de notification (webhook) pour l\'événement « ${meta['webhook_event']} ». Sans elle, les paiements sont vérifiés toutes les 5 minutes.'),
                style: TextStyle(color: hint, fontSize: 12.5),
              ),
              const SizedBox(height: 6),
              Row(children: [
                Expanded(child: SelectableText('${meta['webhook_url']}', style: const TextStyle(fontFamily: 'monospace', fontSize: 12.5))),
                IconButton(
                  tooltip: tr('Copier'),
                  icon: const Icon(Icons.copy, size: 18),
                  onPressed: () {
                    Clipboard.setData(ClipboardData(text: '${meta['webhook_url']}'));
                    toast(context, tr('Adresse copiée.'));
                  },
                ),
              ]),
            ]),
          ),
      ] else
        Field(
          label: tr('Instructions de paiement'),
          child: TextField(controller: _instructions, maxLines: 5, decoration: InputDecoration(hintText: trn('${meta['hint'] ?? ''}'))),
        ),
      if (widget.treasuryAccounts.isNotEmpty && online)
        Field(
          label: tr('Compte de trésorerie qui reçoit ces paiements'),
          child: DropdownButtonFormField<String?>(
            initialValue: _account,
            items: [DropdownMenuItem(value: null, child: Text(tr('Aucun (à rapprocher à la main)'))), for (final t in widget.treasuryAccounts) DropdownMenuItem(value: '${t['id']}', child: Text(tr('${t['name']}')))],
            onChanged: (v) => setState(() => _account = v),
          ),
        ),
      SwitchListTile(contentPadding: EdgeInsets.zero, value: _enabled, onChanged: (v) => setState(() => _enabled = v), title: Text(tr('Proposé aux payeurs'))),
      const SizedBox(height: 12),
      Row(children: [
        FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
        const Spacer(),
        if (widget.gateway != null)
          TextButton.icon(
            onPressed: _busy ? null : _test,
            icon: const Icon(Icons.wifi_tethering, size: 18),
            label: Text(tr('Tester la connexion')),
          ),
        if (widget.gateway != null) TextButton.icon(onPressed: _delete, icon: const Icon(Icons.delete_outline, color: YColors.danger), label: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
      ]),
    ]);
  }
}

/// Comptes bancaires (affichés sur les factures / pour les paiements hors ligne).
class BankAccountsEditor extends StatefulWidget {
  const BankAccountsEditor({super.key, required this.accounts, required this.onSave, this.withInvoiceFlag = false, this.extra});

  final List<Map<String, dynamic>> accounts;
  final Future<void> Function(List<Map<String, dynamic>>) onSave;
  final bool withInvoiceFlag;
  final Widget? extra;

  @override
  State<BankAccountsEditor> createState() => _BankAccountsEditorState();
}

class _BankAccountsEditorState extends State<BankAccountsEditor> {
  late final List<Map<String, TextEditingController>> _rows = [for (final a in widget.accounts) _ctrl(a)];
  late final List<bool> _show = [for (final a in widget.accounts) a['on_invoices'] != false];
  bool _busy = false;

  static const _fields = {'bank': 'Banque', 'holder': 'Titulaire', 'account': 'RIB / n° de compte', 'iban': 'IBAN', 'swift': 'SWIFT / BIC'};

  Map<String, TextEditingController> _ctrl(Map<String, dynamic> a) => {for (final f in _fields.keys) f: TextEditingController(text: '${a[f] ?? ''}')};

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: tr('Coordonnées bancaires'),
      trailing: TextButton.icon(onPressed: () => setState(() {
        _rows.add(_ctrl({}));
        _show.add(true);
      }), icon: const Icon(Icons.add), label: Text(tr('Ajouter un compte'))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (_rows.isEmpty)
          Container(
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: Theme.of(context).colorScheme.surfaceContainer, borderRadius: BorderRadius.circular(12)),
            child: Row(children: [
              Icon(Icons.account_balance_outlined, color: Theme.of(context).hintColor),
              const SizedBox(width: 12),
              Expanded(child: Text(tr('Aucun compte bancaire. Ajoutez votre RIB : il s\'imprime sur vos factures pour les virements.'), style: TextStyle(color: Theme.of(context).hintColor))),
            ]),
          ),
        for (var i = 0; i < _rows.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Card(
              color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .4),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(children: [
                  Wrap(spacing: 12, children: [
                    for (final f in _fields.entries) SizedBox(width: f.key == 'account' || f.key == 'iban' ? 300 : 200, child: Field(label: f.value, child: TextField(controller: _rows[i][f.key]))),
                  ]),
                  Row(children: [
                    if (widget.withInvoiceFlag) ...[
                      Switch(value: _show[i], onChanged: (v) => setState(() => _show[i] = v)),
                      Text(tr('Afficher sur les factures')),
                    ],
                    const Spacer(),
                    IconButton(onPressed: () => setState(() => (_rows.removeAt(i), _show.removeAt(i))), icon: const Icon(Icons.delete_outline)),
                  ]),
                ]),
              ),
            ),
          ),
        ?widget.extra,
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      await widget.onSave([
                        for (var i = 0; i < _rows.length; i++)
                          if (_rows[i]['bank']!.text.trim().isNotEmpty)
                            {
                              for (final f in _fields.keys) f: _rows[i][f]!.text.trim().isEmpty ? null : _rows[i][f]!.text.trim(),
                              if (widget.withInvoiceFlag) 'on_invoices': _show[i],
                            },
                      ]);
                      if (context.mounted) toast(context, tr('Coordonnées enregistrées.'));
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            child: Text(tr('Enregistrer les coordonnées')),
          ),
        ),
      ]),
    );
  }
}
