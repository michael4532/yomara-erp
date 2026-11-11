import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:yomara_erp/core/safe_launch.dart';
import '../../../core/local_db.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../widgets/common.dart';
import '../partners_screen.dart' show editPartner;
import '../whatsapp_web_screen.dart' show openWhatsAppChat, waDigits;
import 'compose.dart' show openComposer;
import 'mail_store.dart';

/// Contact de l'ERP correspondant à une adresse : client / fournisseur, ou personne d'un client.
({Map<String, dynamic>? partner, Map<String, dynamic>? person}) _findContact(String email) {
  final e = email.toLowerCase().trim();
  if (e.isEmpty || !LocalDb.hasEntity('partners')) return (partner: null, person: null);
  for (final p in LocalDb.all('partners')) {
    if ('${p['email'] ?? ''}'.toLowerCase().trim() == e) return (partner: p, person: null);
    for (final c in (p['contacts'] as List? ?? const [])) {
      if (c is Map && '${c['email'] ?? ''}'.toLowerCase().trim() == e) return (partner: p, person: Map<String, dynamic>.from(c));
    }
  }
  return (partner: null, person: null);
}

Color _hueColor(String s, {double l = .55}) => HSLColor.fromAHSL(1, ((s.codeUnits.fold<int>(0, (a, b) => a + b) * 37) % 360).toDouble(), .55, l).toColor();

String _initials(String s) {
  final parts = s.replaceAll(RegExp(r'[<>"]'), '').split(RegExp(r'[\s@._-]+')).where((p) => p.isNotEmpty).toList();
  if (parts.isEmpty) return '?';
  return (parts.length == 1 ? parts.first.substring(0, 1) : parts[0].substring(0, 1) + parts[1].substring(0, 1)).toUpperCase();
}

/// Adresse cliquable, surlignée au survol (comme Outlook) : ouvre la fiche contact.
class ContactPill extends StatefulWidget {
  const ContactPill({super.key, required this.email, this.name, required this.store, this.style, this.showEmail = true});

  final String email;
  final String? name;
  final MailStore store;
  final TextStyle? style;
  final bool showEmail;

  @override
  State<ContactPill> createState() => _ContactPillState();
}

class _ContactPillState extends State<ContactPill> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final accent = Theme.of(context).colorScheme.primary;
    final name = (widget.name ?? '').trim();
    return MouseRegion(
      cursor: SystemMouseCursors.click,
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => _hover = false),
      child: GestureDetector(
        onTap: () => showContactCard(context, email: widget.email, name: widget.name, store: widget.store),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 120),
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
          decoration: BoxDecoration(
            color: _hover ? accent.withValues(alpha: .12) : Colors.transparent,
            border: Border.all(color: _hover ? accent.withValues(alpha: .55) : Colors.transparent),
            borderRadius: BorderRadius.circular(20),
          ),
          child: Text.rich(
            TextSpan(children: [
              TextSpan(text: name.isNotEmpty ? name : widget.email, style: widget.style ?? const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              if (widget.showEmail && name.isNotEmpty) TextSpan(text: ' <${widget.email}>', style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
            ]),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ),
    );
  }
}

/// Fiche contact : avatar, actions (appeler, écrire, WhatsApp…), coordonnées, et « Afficher plus » (ERP, échanges).
Future<void> showContactCard(BuildContext context, {required String email, String? name, required MailStore store}) {
  final box = context.findRenderObject() as RenderBox?;
  final at = box == null ? Offset.zero : box.localToGlobal(Offset(0, box.size.height + 6));
  return showGeneralDialog(
    context: context,
    barrierDismissible: true,
    barrierLabel: tr('Fermer'),
    barrierColor: Colors.black.withValues(alpha: .05),
    transitionDuration: const Duration(milliseconds: 140),
    pageBuilder: (dc, _, _) {
      final size = MediaQuery.of(dc).size;
      const w = 380.0;
      final left = at.dx.clamp(8.0, (size.width - w - 8).clamp(8.0, double.infinity));
      final top = at.dy.clamp(8.0, (size.height - 200).clamp(8.0, double.infinity));
      return Stack(children: [
        Positioned(
          left: size.width < w + 16 ? 8 : left,
          right: size.width < w + 16 ? 8 : null,
          top: top,
          width: size.width < w + 16 ? null : w,
          child: Material(
            elevation: 12,
            borderRadius: BorderRadius.circular(18),
            clipBehavior: Clip.antiAlias,
            child: ConstrainedBox(constraints: BoxConstraints(maxHeight: size.height - top - 16), child: _ContactCard(email: email, name: name, store: store, host: context)),
          ),
        ),
      ]);
    },
    transitionBuilder: (_, a, _, child) => FadeTransition(opacity: a, child: ScaleTransition(scale: Tween(begin: .96, end: 1.0).animate(a), alignment: Alignment.topLeft, child: child)),
  );
}

class _ContactCard extends StatefulWidget {
  const _ContactCard({required this.email, required this.name, required this.store, required this.host});

  final String email;
  final String? name;
  final MailStore store;
  final BuildContext host;

  @override
  State<_ContactCard> createState() => _ContactCardState();
}

class _ContactCardState extends State<_ContactCard> {
  bool _more = false;

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    final found = _findContact(widget.email);
    final partner = found.partner;
    final person = found.person;
    final display = (widget.name ?? '').trim().isNotEmpty ? widget.name!.trim() : '${person?['name'] ?? partner?['name'] ?? widget.email}';
    final phone = '${person?['phone'] ?? partner?['phone'] ?? ''}'.trim();
    final hint = Theme.of(context).hintColor;
    final color = _hueColor(display);

    Widget action(IconData icon, String label, VoidCallback? onTap) => Expanded(
          child: Tooltip(
            message: tr(label),
            child: Material(
              color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: onTap == null ? .35 : .8),
              borderRadius: BorderRadius.circular(12),
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: onTap,
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Icon(icon, size: 20, color: onTap == null ? Theme.of(context).disabledColor : null),
                    const SizedBox(height: 4),
                    Text(tr(label), style: TextStyle(fontSize: 11, color: onTap == null ? Theme.of(context).disabledColor : null), maxLines: 1, overflow: TextOverflow.ellipsis),
                  ]),
                ),
              ),
            ),
          ),
        );

    void close() => Navigator.of(context).pop();

    final exchanges = _more ? widget.store.query(q: widget.email).take(5).toList() : const <Map<String, dynamic>>[];
    final invoices = _more && partner != null && LocalDb.hasEntity('sales_documents')
        ? (LocalDb.all('sales_documents').where((d) => d['partner_id'] == partner['id'] && d['type'] == 'invoice' && d['status'] == 'validated').toList()
          ..sort((a, b) => '${b['date']}'.compareTo('${a['date']}')))
        : const <Map<String, dynamic>>[];
    final due = invoices.fold<double>(0, (s, d) => s + toDouble(d['total']) - toDouble(d['amount_paid']));

    return SingleChildScrollView(
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 12),
          child: Row(children: [
            CircleAvatar(radius: 34, backgroundColor: color, child: Text(_initials(display), style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w600))),
            const SizedBox(width: 16),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr(display), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800), maxLines: 2, overflow: TextOverflow.ellipsis),
                if (person?['role'] != null) Text(tr('${person!['role']}'), style: TextStyle(color: hint)),
                if (partner != null) Text(tr('${partner['name']}'), style: TextStyle(color: hint, fontWeight: FontWeight.w600)),
              ]),
            ),
          ]),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(children: [
            action(Icons.call_outlined, 'Appeler', phone.isEmpty ? null : () => safeLaunchUrl(Uri.parse('tel:${phone.replaceAll(' ', '')}'))),
            const SizedBox(width: 8),
            action(Icons.mail_outline, 'Écrire', () {
              close();
              openComposer(widget.host, widget.store, to: [{'name': widget.name ?? '', 'email': widget.email}]);
            }),
            const SizedBox(width: 8),
            action(Icons.chat_outlined, 'WhatsApp', waDigits(phone).length < 8 ? null : () {
              close();
              openWhatsAppChat(widget.host, phone, text: 'Bonjour ${(widget.name ?? '').split(' ').first},');
            }),
            const SizedBox(width: 8),
            Expanded(
              child: PopupMenuButton<String>(
                tooltip: tr('Plus'),
                onSelected: (v) async {
                  switch (v) {
                    case 'copy':
                      await Clipboard.setData(ClipboardData(text: widget.email));
                      if (context.mounted) toast(context, tr('Adresse copiée.'));
                    case 'search':
                      close();
                      widget.store.searchFor?.call(widget.email);
                    case 'partner':
                      close();
                      if (widget.host.mounted) await editPartner(widget.host, partner);
                    case 'create':
                      close();
                      if (widget.host.mounted) await editPartner(widget.host, {'name': display, 'email': widget.email, 'type': 'customer'}..remove('id'));
                  }
                },
                itemBuilder: (_) => [
                  PopupMenuItem(value: 'copy', child: ListTile(dense: true, leading: const Icon(Icons.copy), title: Text(tr('Copier l\'adresse')))),
                  if (widget.store.searchFor != null) PopupMenuItem(value: 'search', child: ListTile(dense: true, leading: const Icon(Icons.search), title: Text(tr('Tous les e-mails de ce contact')))),
                  if (partner != null && session.can('core.contacts.manage'))
                    PopupMenuItem(value: 'partner', child: ListTile(dense: true, leading: const Icon(Icons.badge_outlined), title: Text(tr('Ouvrir la fiche ${partner['name']}')))),
                  if (partner == null && session.can('core.contacts.manage'))
                    PopupMenuItem(value: 'create', child: ListTile(dense: true, leading: const Icon(Icons.person_add_alt), title: Text(tr('Ajouter aux contacts')))),
                ],
                child: Container(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  decoration: BoxDecoration(color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .8), borderRadius: BorderRadius.circular(12)),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.more_horiz, size: 20), const SizedBox(height: 4), Text(tr('Plus'), style: const TextStyle(fontSize: 11))]),
                ),
              ),
            ),
          ]),
        ),
        const SizedBox(height: 12),
        const Divider(height: 1),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 14, 20, 4),
          child: Text(tr('Contact'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
        ),
        ListTile(
          dense: true,
          leading: const Icon(Icons.mail_outline),
          title: Text(widget.email, style: TextStyle(color: Theme.of(context).colorScheme.primary)),
          onTap: () {
            close();
            openComposer(widget.host, widget.store, to: [{'name': widget.name ?? '', 'email': widget.email}]);
          },
        ),
        if (phone.isNotEmpty) ListTile(dense: true, leading: const Icon(Icons.phone_outlined), title: Text(phone), onTap: () => safeLaunchUrl(Uri.parse('tel:${phone.replaceAll(' ', '')}'))),
        if (partner?['address'] != null || partner?['city'] != null)
          ListTile(dense: true, leading: const Icon(Icons.place_outlined), title: Text(tr([partner?['address'], partner?['city']].where((e) => e != null && '$e'.isNotEmpty).join(', ')))),
        if ('${partner?['website'] ?? ''}'.isNotEmpty)
          ListTile(
            dense: true,
            leading: const Icon(Icons.language),
            title: Text('${partner!['website']}'),
            onTap: () => safeLaunchUrl(Uri.parse('${partner['website']}'.startsWith('http') ? '${partner['website']}' : 'https://${partner['website']}'), mode: LaunchMode.externalApplication),
          ),
        if (_more) ...[
          if (partner != null) ...[
            Padding(padding: const EdgeInsets.fromLTRB(20, 10, 20, 4), child: Text(tr('Dans l\'ERP'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
            ListTile(
              dense: true,
              leading: const Icon(Icons.receipt_long_outlined),
              title: Text(tr('${invoices.length} facture(s) · reste dû ${money(due, session.currency)}')),
              subtitle: invoices.isEmpty ? null : Text(tr(invoices.take(3).map((d) => '${d['number'] ?? ''} ${money(toDouble(d['total']), '${d['currency'] ?? session.currency}')}').join(' · '))),
            ),
          ],
          Padding(padding: const EdgeInsets.fromLTRB(20, 10, 20, 4), child: Text(tr('Derniers échanges'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
          if (exchanges.isEmpty) Padding(padding: const EdgeInsets.fromLTRB(20, 0, 20, 8), child: Text(tr('Aucun autre e-mail en mémoire.'), style: TextStyle(color: hint))),
          for (final m in exchanges)
            ListTile(dense: true, leading: const Icon(Icons.mail_outline, size: 18), title: Text(tr('${m['subject'] ?? '(sans objet)'}'), maxLines: 1, overflow: TextOverflow.ellipsis), subtitle: Text(tr(dateTimeFr(m['date'])))),
        ],
        InkWell(
          onTap: () => setState(() => _more = !_more),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 10, 20, 16),
            child: Row(children: [
              Text(tr(_more ? 'Afficher moins' : 'Afficher plus'), style: TextStyle(color: Theme.of(context).colorScheme.primary, fontWeight: FontWeight.w600, fontSize: 15)),
              Icon(_more ? Icons.expand_less : Icons.chevron_right, color: Theme.of(context).colorScheme.primary),
            ]),
          ),
        ),
      ]),
    );
  }
}
