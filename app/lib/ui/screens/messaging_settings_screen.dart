import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'admin/admin_common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const channelLabels = {'email': 'E-mail', 'sms': 'SMS', 'whatsapp': 'WhatsApp'};
const channelIcons = {'email': Icons.mail_outline, 'sms': Icons.sms_outlined, 'whatsapp': Icons.chat_outlined};

/// Canaux d'envoi de la société : son serveur e-mail (ou celui de l'ERP), SMS et WhatsApp.
class MessagingSettingsScreen extends StatelessWidget {
  const MessagingSettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Canaux d\'envoi'))),
      body: AdminLoader(
        path: 'messaging/setup',
        builder: (context, d, reload) {
          final providers = Map<String, dynamic>.from(d['providers']);
          final channels = Map<String, dynamic>.from(d['channels'] is Map ? d['channels'] : {});
          final available = Map<String, dynamic>.from(d['available']);
          return ListView(padding: const EdgeInsets.all(24), children: [
            Text(
              tr('Ces canaux servent à envoyer vos factures et devis, vos relances et vos campagnes marketing. '
              'Les clés sont chiffrées sur le serveur et ne sont jamais réaffichées.'),
              style: TextStyle(color: Theme.of(context).hintColor),
            ),
            const SizedBox(height: 16),
            for (final ch in ['email', 'sms', 'whatsapp']) ...[
              _ChannelCard(
                channel: ch,
                providers: List<Map<String, dynamic>>.from((providers[ch] as List).map((e) => Map<String, dynamic>.from(e))),
                current: channels[ch] == null ? null : Map<String, dynamic>.from(channels[ch]),
                active: available[ch] != null,
                platform: d['platform_mail'] == null ? null : Map<String, dynamic>.from(d['platform_mail']),
                onChanged: reload,
              ),
              const SizedBox(height: 16),
            ],
          ]);
        },
      ),
    );
  }
}

class _ChannelCard extends StatefulWidget {
  const _ChannelCard({required this.channel, required this.providers, required this.current, required this.active, required this.platform, required this.onChanged});

  final String channel;
  final List<Map<String, dynamic>> providers;
  final Map<String, dynamic>? current;
  final bool active;
  final Map<String, dynamic>? platform;
  final VoidCallback onChanged;

  @override
  State<_ChannelCard> createState() => _ChannelCardState();
}

class _ChannelCardState extends State<_ChannelCard> {
  late String? _provider = widget.current?['provider'] as String?;
  final Map<String, TextEditingController> _c = {};
  bool _busy = false;

  Map<String, dynamic>? get _meta => widget.providers.where((p) => p['key'] == _provider).firstOrNull;

  TextEditingController _ctrl(String k) => _c.putIfAbsent(k, () {
        final s = widget.current?['provider'] == _provider ? asMap(widget.current?['settings']) : <String, dynamic>{};
        final secret = List<String>.from(_meta?['secret'] ?? const []);
        return TextEditingController(text: secret.contains(k) ? '' : '${s[k] ?? ''}');
      });

  Future<void> _save() async {
    final meta = _meta;
    if (meta == null) return;
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('messaging/channels/${widget.channel}', {
        'provider': _provider,
        'settings': {for (final k in Map<String, dynamic>.from(meta['fields']).keys) k: _ctrl(k).text.trim()},
        'enabled': true,
      });
      if (mounted) toast(context, tr('${channelLabels[widget.channel]} enregistré.'));
      widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _test() async {
    final to = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Tester l\'envoi ${channelLabels[widget.channel]}')),
        content: TextField(controller: to, autofocus: true, decoration: InputDecoration(labelText: widget.channel == 'email' ? 'Adresse e-mail' : 'Numéro (ex. 07 07 07 07 07)')),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Envoyer')))],
      ),
    );
    if (ok != true || to.text.trim().isEmpty || !mounted) return;
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('messaging/test', {'channel': widget.channel, 'to': to.text.trim()});
      if (mounted) toast(context, tr('Message de test envoyé.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final meta = _meta;
    final hint = Theme.of(context).hintColor;
    final fields = asMap(meta?['fields']);
    final secret = List<String>.from(meta?['secret'] ?? const []);
    final masked = widget.current?['provider'] == _provider ? asMap(widget.current?['settings']) : <String, dynamic>{};
    return SectionCard(
      title: channelLabels[widget.channel],
      trailing: StatusChip(widget.active ? 'Actif' : 'Non configuré', color: widget.active ? YColors.success : YColors.muted),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final p in widget.providers)
            ChoiceChip(
              avatar: p['locked'] == true ? const Icon(Icons.lock_outline, size: 16) : null,
              label: Text(tr('${p['label']}')),
              selected: _provider == p['key'],
              onSelected: p['locked'] == true
                  ? (_) => toast(context, tr('Activez le module « ${p['module'] == 'mail_server' ? 'Messagerie hébergée' : (p['module'] == 'sms' ? 'SMS' : 'WhatsApp Business')} » dans la Boutique de modules.'))
                  : (_) => setState(() {
                        _provider = '${p['key']}';
                        _c.clear();
                      }),
            ),
        ]),
        if (meta != null) ...[
          const SizedBox(height: 12),
          if (meta['hint'] != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(tr('${meta['hint']}'), style: TextStyle(color: hint, fontSize: 13))),
          if (_provider == 'platform' && widget.platform != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Text(tr('Ce mois-ci : ${widget.platform!['used']} e-mail(s) envoyé(s) sur ${widget.platform!['quota']}.'), style: const TextStyle(fontWeight: FontWeight.w600)),
            ),
          if (_provider == 'platform' && widget.platform == null)
            Padding(padding: EdgeInsets.only(bottom: 12), child: Text(tr('Le serveur de l\'ERP n\'est pas encore configuré par l\'éditeur.'), style: TextStyle(color: YColors.danger))),
          LayoutBuilder(builder: (context, c) {
            final w = c.maxWidth > 700 ? (c.maxWidth - 12) / 2 : c.maxWidth;
            return Wrap(spacing: 12, children: [
              for (final f in fields.entries)
                SizedBox(
                  width: f.key == 'url' || f.key == 'body' || f.key == 'headers' ? c.maxWidth : w,
                  child: Field(
                    label: '${f.value}',
                    child: TextField(
                      controller: _ctrl(f.key),
                      obscureText: secret.contains(f.key),
                      maxLines: f.key == 'body' || f.key == 'headers' ? 3 : 1,
                      decoration: InputDecoration(hintText: trn(secret.contains(f.key) && '${masked[f.key] ?? ''}'.isNotEmpty ? '${masked[f.key]} (laisser vide pour conserver)' : null)),
                    ),
                  ),
                ),
            ]);
          }),
          Wrap(spacing: 8, runSpacing: 8, children: [
            FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
            OutlinedButton.icon(onPressed: _busy || !widget.active ? null : _test, icon: const Icon(Icons.send_outlined), label: Text(tr('Envoyer un test'))),
            if (meta['docs'] != null) TextButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${meta['docs']}')), icon: const Icon(Icons.open_in_new, size: 16), label: Text(tr('Documentation du fournisseur'))),
          ]),
        ],
      ]),
    );
  }
}

/// Envoi d'un document (PDF) ou d'un message à un client, par e-mail, WhatsApp ou SMS.
Future<void> sendMessageDialog(
  BuildContext context, {
  String? email,
  String? phone,
  String? name,
  required String subject,
  required String message,
  Future<List<int>> Function()? attachment,
  String? attachmentName,
  String? contextRef,
  Map<String, String>? docFields, // fiche du document dans l'e-mail (numéro, montant, échéance, lien de paiement)
}) async {
  final api = context.read<Session>().api;
  Map<String, dynamic> setup;
  try {
    setup = Map<String, dynamic>.from(await api.get('messaging/setup'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return;
  }
  if (!context.mounted) return;
  final available = Map<String, dynamic>.from(setup['available']).entries.where((e) => e.value != null).map((e) => e.key).toList();
  // Boîtes mail de l'utilisateur : l'e-mail part de sa propre adresse (son SMTP, sa signature, copie dans « Envoyés »)
  final mailboxes = [for (final m in (setup['mailboxes'] as List? ?? const [])) asMap(m)];
  final companyMail = available.contains('email');
  if (mailboxes.isNotEmpty && !available.contains('email')) available.insert(0, 'email');
  int? fromAccount = mailboxes.isNotEmpty ? mailboxes.first['id'] as int : null;
  final cc = TextEditingController();
  if (available.isEmpty) {
    toast(context, tr('Aucun canal d\'envoi configuré : Paramètres › Canaux d\'envoi.'), error: true);
    return;
  }
  var channel = available.contains('email') && (email ?? '').isNotEmpty ? 'email' : (available.contains('whatsapp') ? 'whatsapp' : available.first);
  final to = TextEditingController(text: channel == 'email' ? email ?? '' : phone ?? '');
  final subj = TextEditingController(text: subject);
  final body = TextEditingController(text: message);
  var attach = attachment != null;
  var busy = false;
  await showFormDialog(context, title: 'Envoyer${name != null ? ' à $name' : ''}', builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            SegmentedButton<String>(
              segments: [for (final ch in available) ButtonSegment(value: ch, icon: Icon(channelIcons[ch]), label: Text(tr(channelLabels[ch]!)))],
              selected: {channel},
              onSelectionChanged: (s) => set(() {
                channel = s.first;
                to.text = channel == 'email' ? email ?? '' : phone ?? '';
              }),
            ),
            const SizedBox(height: 12),
            if (channel == 'email' && mailboxes.isNotEmpty)
              DropdownButtonFormField<int?>(
                initialValue: fromAccount,
                decoration: InputDecoration(labelText: tr('Envoyer depuis'), prefixIcon: Icon(Icons.alternate_email, size: 18)),
                items: [
                  for (final m in mailboxes) DropdownMenuItem(value: m['id'] as int, child: Text(tr('${m['display_name'] ?? ''} <${m['email']}>'.trim()))),
                  if (companyMail) DropdownMenuItem(value: null, child: Text(tr('Adresse d\'envoi de la société'))),
                ],
                onChanged: (v) => set(() => fromAccount = v),
              ),
            if (channel == 'email' && mailboxes.isNotEmpty) const SizedBox(height: 8),
            TextField(controller: to, decoration: InputDecoration(labelText: trn(channel == 'email' ? 'Adresse e-mail' : 'Numéro'))),
            if (channel == 'email' && fromAccount != null) TextField(controller: cc, decoration: InputDecoration(labelText: tr('Copie à (facultatif, séparées par des virgules)'))),
            if (channel == 'email') TextField(controller: subj, decoration: InputDecoration(labelText: tr('Objet'))),
            const SizedBox(height: 8),
            TextField(controller: body, minLines: 6, maxLines: 12, decoration: InputDecoration(labelText: tr('Message'), alignLabelWithHint: true)),
            if (attachment != null)
              CheckboxListTile(contentPadding: EdgeInsets.zero, value: attach, onChanged: (v) => set(() => attach = v == true), title: Text(tr('Joindre ${attachmentName ?? 'le document'} (PDF)'))),
            if (channel == 'sms' && attach) Text(tr('Par SMS, le document est envoyé sous forme de lien.'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
              FilledButton.icon(
                onPressed: busy
                    ? null
                    : () async {
                        if (to.text.trim().isEmpty) return;
                        set(() => busy = true);
                        try {
                          final fields = {
                            'channel': channel,
                            'to': to.text.trim(),
                            'to_name': name,
                            'subject': subj.text.trim(),
                            'message': body.text.trim(),
                            'context': contextRef,
                            if (channel == 'email' && fromAccount != null) 'from_account_id': '$fromAccount',
                            if (channel == 'email' && fromAccount != null && cc.text.trim().isNotEmpty) 'cc': cc.text.trim(),
                            if (channel == 'email') ...?docFields,
                          };
                          if (attach && attachment != null) {
                            await api.uploadForm('messaging/send', fields, 'attachment', await attachment(), attachmentName ?? 'document.pdf');
                          } else {
                            await api.post('messaging/send', fields);
                          }
                          if (c.mounted) {
                            Navigator.pop(c);
                            toast(context, channel == 'email' && fromAccount != null ? 'E-mail envoyé depuis votre boîte (copie dans « Envoyés »).' : 'Envoyé par ${channelLabels[channel]}.');
                          }
                        } catch (e) {
                          if (c.mounted) toast(c, errorText(e), error: true);
                        } finally {
                          if (c.mounted) set(() => busy = false);
                        }
                      },
                icon: const Icon(Icons.send),
                label: Text(tr(busy ? 'Envoi…' : 'Envoyer')),
              ),
            ]),
          ),
        ]),
      ),
    ));
}
