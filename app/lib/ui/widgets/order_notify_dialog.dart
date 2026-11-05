import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import 'common.dart';

/// Ventes › Notifications de commande : accusé de réception automatique au client (e-mail, WhatsApp ou SMS),
/// ticket du service client pour suivre ses réponses, confirmation, annulation et alerte de l'équipe.
Future<void> showOrderNotifyDialog(BuildContext context) => showDialog(context: context, builder: (_) => const _OrderNotifyDialog());

class _OrderNotifyDialog extends StatefulWidget {
  const _OrderNotifyDialog();

  @override
  State<_OrderNotifyDialog> createState() => _OrderNotifyDialogState();
}

class _OrderNotifyDialogState extends State<_OrderNotifyDialog> {
  Map<String, dynamic>? _d, _meta;
  Object? _error;
  bool _busy = false;
  final _team = TextEditingController(), _signature = TextEditingController();
  final Map<String, TextEditingController> _texts = {};

  static const _labels = {'received': 'Commande reçue', 'confirmed': 'Commande confirmée', 'cancelled': 'Commande annulée'};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _team.dispose();
    _signature.dispose();
    for (final c in _texts.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('ventes/order-notifications'));
      if (!mounted) return;
      final d = asMap(r['data']);
      final texts = asMap(d['texts']);
      setState(() {
        _meta = r;
        _d = d;
        _team.text = [for (final e in (d['team_emails'] as List? ?? const [])) '$e'].join(', ');
        _signature.text = '${d['signature'] ?? ''}';
        for (final k in _labels.keys) {
          _texts[k] = TextEditingController(text: '${texts[k] ?? ''}');
        }
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _save() async {
    final d = _d!;
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('ventes/order-notifications', {
        for (final k in ['enabled', 'scope', 'email', 'whatsapp', 'sms', 'ticket', 'team']) k: d[k],
        'events': asMap(d['events']),
        'team_emails': [for (final e in _team.text.split(RegExp(r'[,;\s]+'))) if (e.trim().isNotEmpty) e.trim()],
        'texts': {for (final e in _texts.entries) e.key: e.value.text.trim()},
        'signature': _signature.text.trim(),
      });
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Notifications de commande enregistrées.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _set(String k, Object? v) => setState(() => _d![k] = v);

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final d = _d;
    return YAlertDialog(
      title: Text(tr('Notifications de commande')),
      content: SizedBox(
        width: 600,
        child: d == null
            ? (_error != null ? Text(errorText(_error!)) : const SizedBox(height: 120, child: Center(child: CircularProgressIndicator())))
            : SingleChildScrollView(child: _form(d, hint)),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(onPressed: d == null || _busy ? null : _save, child: Text(tr('Enregistrer'))),
      ],
    );
  }

  Widget _form(Map<String, dynamic> d, Color hint) {
    final channels = asMap(_meta?['channels']);
    final events = asMap(d['events']);
    final helpdesk = _meta?['helpdesk'] == true;
    final recent = [for (final x in (_meta?['recent'] as List? ?? const [])) asMap(x)];
    Widget note(String t) => Padding(padding: const EdgeInsets.only(left: 16, right: 16, bottom: 6), child: Text(tr(t), style: TextStyle(color: hint, fontSize: 12.5, height: 1.35)));
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      Text(
        tr('Chaque commande reçue (site WordPress / WooCommerce, boutique en ligne, ou saisie) déclenche un accusé de réception au client avec le détail de la commande. Sa réponse à l\'e-mail arrive dans le service client.'),
        style: TextStyle(color: hint, height: 1.4),
      ),
      const SizedBox(height: 8),
      SwitchListTile(value: d['enabled'] == true, onChanged: (v) => _set('enabled', v), title: Text(tr('Notifications automatiques activées'))),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: SegmentedButton<String>(
          showSelectedIcon: false,
          segments: [
            ButtonSegment(value: 'online', label: Text(tr('Commandes en ligne'))),
            ButtonSegment(value: 'all', label: Text(tr('Toutes les commandes'))),
          ],
          selected: {'${d['scope'] ?? 'online'}'},
          onSelectionChanged: (v) => _set('scope', v.first),
        ),
      ),
      const SizedBox(height: 10),
      Text(tr('Messages envoyés au client'), style: const TextStyle(fontWeight: FontWeight.w700)),
      for (final k in _labels.keys) ...[
        CheckboxListTile(
          dense: true,
          value: events[k] != false,
          onChanged: (v) => setState(() => events[k] = v ?? false),
          title: Text(tr(_labels[k]!)),
          controlAffinity: ListTileControlAffinity.leading,
        ),
        if (events[k] != false)
          Padding(
            padding: const EdgeInsets.only(left: 16, right: 16, bottom: 8),
            child: TextField(controller: _texts[k], maxLines: 3, minLines: 2, decoration: InputDecoration(labelText: tr('Texte'), isDense: true)),
          ),
      ],
      note('Variables : {numero}, {client}, {total}, {date}, {entreprise}.'),
      const Divider(height: 24),
      Text(tr('Canaux'), style: const TextStyle(fontWeight: FontWeight.w700)),
      for (final (k, l) in const [('email', 'E-mail (avec le détail de la commande)'), ('whatsapp', 'WhatsApp'), ('sms', 'SMS (si WhatsApp n\'est pas disponible)')])
        CheckboxListTile(
          dense: true,
          value: d[k] == true,
          onChanged: (v) => _set(k, v ?? false),
          controlAffinity: ListTileControlAffinity.leading,
          title: Text(tr(l)),
          subtitle: channels[k] == null ? Text(tr('Non configuré : Paramètres › Canaux d\'envoi'), style: const TextStyle(color: YColors.warning, fontSize: 12)) : null,
        ),
      CheckboxListTile(
        dense: true,
        value: d['ticket'] == true,
        onChanged: helpdesk ? (v) => _set('ticket', v ?? false) : null,
        controlAffinity: ListTileControlAffinity.leading,
        title: Text(tr('Ouvrir un ticket du service client (référence SUP-… dans l\'e-mail)')),
        subtitle: helpdesk ? null : Text(tr('Activez le module Service client pour suivre les réponses des clients.'), style: TextStyle(color: hint, fontSize: 12)),
      ),
      const Divider(height: 24),
      SwitchListTile(value: d['team'] == true, onChanged: (v) => _set('team', v), title: Text(tr('Prévenir l\'équipe à chaque nouvelle commande'))),
      note('Notification dans l\'application pour les responsables des ventes, et e-mail aux adresses ci-dessous.'),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: TextField(controller: _team, decoration: InputDecoration(labelText: tr('E-mails de l\'équipe (séparés par des virgules)'), hintText: 'ventes@monentreprise.ci', isDense: true)),
      ),
      const SizedBox(height: 10),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: TextField(controller: _signature, maxLines: 2, decoration: InputDecoration(labelText: tr('Signature (facultatif)'), isDense: true)),
      ),
      if (recent.isNotEmpty) ...[
        const Divider(height: 24),
        Text(tr('Derniers envois'), style: const TextStyle(fontWeight: FontWeight.w700)),
        for (final r in recent.take(6))
          ListTile(
            dense: true,
            leading: Icon('${r['channels'] ?? ''}'.isEmpty ? Icons.block : Icons.mark_email_read_outlined, size: 18, color: '${r['channels'] ?? ''}'.isEmpty ? hint : YColors.success),
            title: Text(tr(_labels['${r['event']}'] ?? '${r['event']}')),
            subtitle: Text(tr('${r['channels'] ?? ''}'.isEmpty ? 'Aucun canal disponible (client sans e-mail ni téléphone ?)' : '${r['channels']}'.replaceAll(',', ' · '))),
            trailing: Text('${r['created_at'] ?? ''}'.split('.').first.replaceFirst('T', ' '), style: TextStyle(color: hint, fontSize: 12)),
          ),
      ],
    ]);
  }
}
