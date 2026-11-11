import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'document_editor.dart';
import 'documents_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
bool canManagePortal(Session s) => s.hasModule('portail') && (s.can('portail.manage') || s.can('ventes.manage'));

const _channelLabels = {'email': 'E-mail', 'whatsapp': 'WhatsApp', 'sms': 'SMS'};
const _channelIcons = {'email': Icons.alternate_email, 'whatsapp': Icons.chat_outlined, 'sms': Icons.sms_outlined};
const _eventIcons = {
  'invite': Icons.send_outlined,
  'login': Icons.login,
  'view': Icons.visibility_outlined,
  'quote_accepted': Icons.verified_outlined,
  'quote_refused': Icons.thumb_down_alt_outlined,
  'message': Icons.mail_outline,
  'reply': Icons.reply,
  'pay': Icons.payments_outlined,
};
const _eventColors = {'quote_accepted': YColors.success, 'quote_refused': YColors.danger, 'message': YColors.blue, 'reply': YColors.blue, 'pay': YColors.success};

void openPortalPartner(BuildContext context, String partnerId) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => PortalPartnerScreen(partnerId: partnerId)));

/// Espace client d'un contact : accès, invitation, activité et messages.
class PortalPartnerScreen extends StatefulWidget {
  const PortalPartnerScreen({super.key, required this.partnerId});

  final String partnerId;

  @override
  State<PortalPartnerScreen> createState() => _PortalPartnerScreenState();
}

class _PortalPartnerScreenState extends State<PortalPartnerScreen> {
  Map<String, dynamic>? _d;
  Object? _error;
  bool _busy = false;
  final _reply = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final d = Map<String, dynamic>.from(await api.get('portail/partners/${widget.partnerId}'));
      if (mounted) setState(() => (_d = d, _error = null));
      if ((d['events'] as List).any((e) => e['type'] == 'message' && e['read_at'] == null)) {
        await api.post('portail/partners/${widget.partnerId}/read');
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _invite(String channel) async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('portail/partners/${widget.partnerId}/invite', {'channel': channel});
      if (!mounted) return;
      final via = r['sent_via'];
      if (via == null) {
        await Clipboard.setData(ClipboardData(text: '${r['url']}'));
        if (mounted) toast(context, channel == 'none' ? 'Accès ouvert, lien copié : envoyez-le vous-même.' : 'Envoi impossible, lien copié dans le presse-papiers.', error: channel != 'none');
      } else {
        toast(context, tr('Lien envoyé par ${_channelLabels[via]}.'));
      }
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _revoke() async {
    if (!await confirm(context, tr('Couper l\'accès ?'), tr('Le lien actuel ne fonctionnera plus. Vous pourrez réinviter le client plus tard avec un nouveau lien.'), ok: tr('Couper l\'accès'), danger: true)) return;
    if (!mounted) return;
    try {
      await context.read<Session>().api.post('portail/partners/${widget.partnerId}/revoke');
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _send() async {
    final body = _reply.text.trim();
    if (body.isEmpty) return;
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('portail/partners/${widget.partnerId}/reply', {'body': body});
      _reply.clear();
      await _load();
      if (mounted) toast(context, tr('Réponse envoyée au client.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final partner = LocalDb.get('partners', widget.partnerId);
    final hint = Theme.of(context).hintColor;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Espace client · ${partner?['name'] ?? ''}'))),
      body: _d == null
          ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                _accessCard(context, partner),
                const SizedBox(height: 12),
                _conversation(context, hint),
                const SizedBox(height: 12),
                SectionCard(
                  title: tr('Activité'),
                  child: (_d!['events'] as List).isEmpty
                      ? Text(tr('Aucune activité pour l\'instant.'), style: TextStyle(color: hint))
                      : Column(children: [
                          for (final e in (_d!['events'] as List).where((e) => !['message', 'reply'].contains(e['type'])))
                            ListTile(
                              dense: true,
                              contentPadding: EdgeInsets.zero,
                              leading: Icon(_eventIcons[e['type']] ?? Icons.circle_outlined, color: _eventColors[e['type']] ?? hint, size: 20),
                              title: Text(tr(_eventText(Map<String, dynamic>.from(e)))),
                              subtitle: Text(tr(dateTimeFr(e['created_at']))),
                              onTap: e['document_id'] == null ? null : () => _openDoc(context, '${e['document_id']}', '${asMap(e['data'])['order_id'] ?? ''}'),
                            ),
                        ]),
                ),
              ]),
            ),
    );
  }

  String _eventText(Map<String, dynamic> e) {
    final data = asMap(e['data']);
    final doc = LocalDb.get('sales_documents', e['document_id'] as String?);
    final num = doc?['number'] ?? data['number'] ?? '';
    return switch (e['type']) {
      'invite' => 'Invitation${data['via'] != null ? ' envoyée par ${_channelLabels[data['via']]}' : ' (lien copié)'}',
      'view' => 'A consulté $num',
      'pay' => 'A ouvert le paiement en ligne de $num',
      'quote_accepted' => 'Devis $num accepté par ${data['signer'] ?? ''}${data['order_number'] != null ? ' › commande ${data['order_number']}' : ''}',
      'quote_refused' => 'Devis $num refusé${data['reason'] != null ? ' : ${data['reason']}' : ''}',
      _ => '${e['label']}',
    };
  }

  void _openDoc(BuildContext context, String docId, String orderId) {
    final id = orderId.isNotEmpty && LocalDb.get('sales_documents', orderId) != null ? orderId : docId;
    openSalesDocument(context, id);
  }

  Widget _accessCard(BuildContext context, Map<String, dynamic>? partner) {
    final access = asMapOrNull(_d!['access']);
    final channels = List<String>.from(_d!['channels'] as List);
    final active = access?['active'] == true;
    final hint = Theme.of(context).hintColor;
    return SectionCard(
      title: tr('Accès au portail'),
      trailing: StatusChip(active ? 'Actif' : (access == null ? 'Jamais invité' : 'Coupé'), color: active ? YColors.success : YColors.muted),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(
          tr('Le client consulte ses factures et les paie en ligne, accepte ses devis, suit commandes et livraisons, télécharge son relevé et vous écrit. Sans mot de passe : un lien personnel suffit.'),
          style: TextStyle(color: hint),
        ),
        if (access != null) ...[
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (access['invited_at'] != null) StatusChip(tr('Invité le ${dateFr(access['invited_at'])}'), icon: Icons.send_outlined, color: YColors.blue),
            StatusChip(access['last_login_at'] == null ? 'Jamais connecté' : 'Dernière visite ${dateTimeFr(access['last_login_at'])}', icon: Icons.login, color: YColors.muted),
            if ((access['login_count'] ?? 0) > 0) StatusChip(tr('${access['login_count']} visite(s)'), color: YColors.muted),
          ]),
        ],
        const SizedBox(height: 12),
        if (channels.isEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              tr(partner?['email'] == null && partner?['phone'] == null
                  ? 'Ajoutez un e-mail ou un téléphone au contact pour lui envoyer le lien.'
                  : 'Aucun canal d\'envoi configuré (Paramètres › Messagerie) : copiez le lien et envoyez-le vous-même.'),
              style: const TextStyle(color: YColors.warning),
            ),
          ),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final c in channels)
            FilledButton.tonalIcon(onPressed: _busy ? null : () => _invite(c), icon: Icon(_channelIcons[c]), label: Text(tr('${active ? 'Renvoyer' : 'Inviter'} par ${_channelLabels[c]}'))),
          OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    if (active && access?['url'] != null) {
                      await Clipboard.setData(ClipboardData(text: '${access?['url']}'));
                      if (context.mounted) toast(context, tr('Lien copié.'));
                    } else {
                      await _invite('none');
                    }
                  },
            icon: const Icon(Icons.link),
            label: Text(tr(active ? 'Copier le lien' : 'Ouvrir l\'accès et copier le lien')),
          ),
          if (active && access?['url'] != null)
            OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${access?['url']}'), mode: LaunchMode.externalApplication), icon: const Icon(Icons.open_in_new), label: Text(tr('Voir comme le client'))),
          if (active) TextButton(onPressed: _revoke, child: Text(tr('Couper l\'accès'), style: TextStyle(color: YColors.danger))),
        ]),
      ]),
    );
  }

  Widget _conversation(BuildContext context, Color hint) {
    final msgs = (_d!['events'] as List).where((e) => ['message', 'reply'].contains(e['type'])).toList().reversed.toList();
    return SectionCard(
      title: tr('Messages'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (msgs.isEmpty) Text(tr('Aucun message. Le client peut vous écrire depuis son espace.'), style: TextStyle(color: hint)),
        for (final m in msgs)
          Align(
            alignment: m['type'] == 'reply' ? Alignment.centerRight : Alignment.centerLeft,
            child: Container(
              constraints: const BoxConstraints(maxWidth: 560),
              margin: const EdgeInsets.symmetric(vertical: 4),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: m['type'] == 'reply' ? YColors.blue.withValues(alpha: .08) : Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .6),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${m['type'] == 'reply' ? (m['data']?['author'] ?? 'Équipe') : 'Client'} · ${dateTimeFr(m['created_at'])}'), style: TextStyle(fontSize: 12, color: hint)),
                if (m['data']?['subject'] != null) Text(tr('${m['data']['subject']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                if (m['document_id'] != null)
                  InkWell(
                    onTap: () => openSalesDocument(context, '${m['document_id']}'),
                    child: Text(tr('Au sujet de ${LocalDb.get('sales_documents', m['document_id'] as String?)?['number'] ?? 'un document'}'), style: const TextStyle(color: YColors.blue, fontSize: 13)),
                  ),
                const SizedBox(height: 4),
                Text(tr('${m['data']?['body'] ?? ''}')),
              ]),
            ),
          ),
        const SizedBox(height: 8),
        Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Expanded(child: TextField(controller: _reply, minLines: 1, maxLines: 5, decoration: InputDecoration(hintText: tr('Répondre au client (visible dans son espace et envoyé par e-mail ou WhatsApp)')))),
          const SizedBox(width: 8),
          FilledButton.icon(onPressed: _busy ? null : _send, icon: const Icon(Icons.send), label: Text(tr('Envoyer'))),
        ]),
      ]),
    );
  }
}

/// Ouvre un document de vente synchronisé.
void openSalesDocument(BuildContext context, String id) {
  final doc = LocalDb.get('sales_documents', id);
  if (doc == null) {
    toast(context, tr('Document pas encore synchronisé sur cet appareil.'), error: true);
    return;
  }
  Navigator.of(context).push(MaterialPageRoute(builder: (_) => DocumentEditor(config: DocConfig.sale, document: doc, initialType: '${doc['type']}')));
}

/// Boîte de réception du portail : messages et réponses aux devis de tous les clients.
class PortalInboxScreen extends StatefulWidget {
  const PortalInboxScreen({super.key});

  @override
  State<PortalInboxScreen> createState() => _PortalInboxScreenState();
}

class _PortalInboxScreenState extends State<PortalInboxScreen> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.get('portail/inbox'));
      if (mounted) setState(() => (_d = d, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Portail client'))),
      body: _d == null
          ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Wrap(spacing: 8, runSpacing: 8, children: [
                  StatusChip(tr('${_d!['accesses']} client(s) avec accès'), icon: Icons.people_outline, color: YColors.blue),
                  StatusChip(tr('${_d!['active_30d']} actif(s) sur 30 jours'), icon: Icons.login, color: YColors.success),
                  if ((_d!['unread'] ?? 0) > 0) StatusChip(tr('${_d!['unread']} message(s) non lu(s)'), icon: Icons.mark_email_unread_outlined, color: YColors.warning),
                ]),
                const SizedBox(height: 12),
                Text(tr('Pour inviter un client : ouvrez sa fiche dans Clients & fournisseurs, puis « Espace client ».'), style: TextStyle(color: hint)),
                const SizedBox(height: 12),
                if ((_d!['data'] as List).isEmpty)
                  EmptyState(icon: Icons.forum_outlined, title: tr('Rien pour l\'instant'), message: tr('Les messages de vos clients et leurs réponses aux devis apparaîtront ici.')),
                for (final e in (_d!['data'] as List))
                  Card(
                    child: ListTile(
                      leading: Icon(_eventIcons[e['type']] ?? Icons.circle_outlined, color: _eventColors[e['type']] ?? hint),
                      title: Text(tr('${e['partner_name']} · ${e['label']}'), style: TextStyle(fontWeight: e['type'] == 'message' && e['read_at'] == null ? FontWeight.w700 : FontWeight.w500)),
                      subtitle: Text(
                        tr([
                          if (e['data']?['subject'] != null) '${e['data']['subject']}',
                          if (e['data']?['body'] != null) '${e['data']['body']}',
                          if (e['data']?['signer'] != null) 'Signé par ${e['data']['signer']}${e['data']['order_number'] != null ? ' · commande ${e['data']['order_number']}' : ''}',
                          if (e['data']?['reason'] != null) '${e['data']['reason']}',
                          dateTimeFr(e['created_at']),
                        ].join('\n')),
                        maxLines: 4,
                        overflow: TextOverflow.ellipsis,
                      ),
                      onTap: () async {
                        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PortalPartnerScreen(partnerId: '${e['partner_id']}')));
                        _load();
                      },
                    ),
                  ),
              ]),
            ),
    );
  }
}
