import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../../core/local_db.dart';
import '../screens/achats_screen.dart' show openPurchaseRequest, openPurchaseDocument;
import '../screens/interventions_screen.dart' show openIntervention;
import '../screens/rdv_screen.dart' show openAppointment;
import '../screens/restaurant_screen.dart' show openRestaurantOrder;
import '../screens/production_screen.dart' show openProductionOrder;
import '../screens/portal_screen.dart';
import '../screens/tickets_screen.dart';
import '../theme.dart';
import '../navigation.dart' show ShellNav;
import 'announcement_dialog.dart';
import 'common.dart';
import '../../i18n/i18n.dart';

/// Cloche des notifications (tickets, rapports, commandes…), rafraîchie chaque minute.
class NotificationBell extends StatefulWidget {
  const NotificationBell({super.key, this.light = true});

  final bool light;

  @override
  State<NotificationBell> createState() => _NotificationBellState();
}

class _NotificationBellState extends State<NotificationBell> {
  int _unread = 0;
  List<Map<String, dynamic>> _items = [];
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 60), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('notifications');
      if (!mounted) return;
      setState(() {
        _unread = (r['unread'] as num?)?.toInt() ?? 0;
        _items = List<Map<String, dynamic>>.from((r['data'] as List).map((e) => Map<String, dynamic>.from(e)));
      });
    } catch (_) {
      // hors ligne : on garde le dernier état
    }
  }

  Future<void> _open() async {
    await _load();
    if (!mounted) return;
    final api = context.read<Session>().api;
    // Panneau latéral, comme « Ma journée » : en-tête, filtre Toutes / Non lues, notifications regroupées par jour
    final picked = await showGeneralDialog<Map<String, dynamic>>(
      context: context,
      barrierDismissible: true,
      barrierLabel: 'Fermer',
      barrierColor: YColors.night.withValues(alpha: .25),
      transitionDuration: const Duration(milliseconds: 240),
      pageBuilder: (c, a, b) => Align(
        alignment: Alignment.centerRight,
        child: _NotificationsPanel(
          items: _items,
          onReadAll: () async {
            await api.post('notifications/read');
            _load();
          },
        ),
      ),
      transitionBuilder: (c, a, b, child) => SlideTransition(position: Tween(begin: const Offset(1, 0), end: Offset.zero).animate(CurvedAnimation(parent: a, curve: Curves.easeOutCubic)), child: child),
    );
    if (picked == null || !mounted) return;
    api.post('notifications/${picked['id']}/read').then((_) => _load()).catchError((_) {});
    if (picked['module'] == 'announcement') {
      await showAnnouncement(context, picked);
      return;
    }
    // Notifications qui ouvrent un écran : console éditeur (commandes, live chat) ou boutique côté client
    const screens = {'admin_livechat': 'admin_livechat', 'admin_store': 'admin_store', 'admin_companies': 'admin_companies', 'store': 'store', 'social': 'social'};
    if (screens.containsKey(picked['module'])) {
      ShellNav.of(context)?.go(screens[picked['module']]!);
      return;
    }
    final ref = int.tryParse('${picked['ref'] ?? ''}');
    if (picked['module'] == 'tickets' && ref != null) {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TicketDetailScreen(ticketId: ref)));
    }
    final uuid = '${picked['ref'] ?? ''}';
    if (!mounted || uuid.length != 36) return;
    if (picked['module'] == 'portail') openPortalPartner(context, uuid);
    if (picked['module'] == 'ventes') openSalesDocument(context, uuid);
    if (picked['module'] == 'interventions') openIntervention(context, uuid);
    if (picked['module'] == 'rendez_vous') openAppointment(context, uuid);
    if (picked['module'] == 'restaurant') openRestaurantOrder(context, uuid);
    if (picked['module'] == 'production') openProductionOrder(context, uuid);
    if (picked['module'] == 'achats') {
      if (LocalDb.get('purchase_requests', uuid) != null) {
        openPurchaseRequest(context, uuid);
      } else {
        openPurchaseDocument(context, uuid);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: tr('Notifications'),
      onPressed: _open,
      icon: Badge(
        isLabelVisible: _unread > 0,
        label: Text(tr(_unread > 99 ? '99+' : '$_unread')),
        child: Icon(Icons.notifications_outlined, color: widget.light ? const Color(0xFFD5DEF2) : null),
      ),
    );
  }
}


class _NotificationsPanel extends StatefulWidget {
  const _NotificationsPanel({required this.items, required this.onReadAll});
  final List<Map<String, dynamic>> items;
  final Future<void> Function() onReadAll;

  @override
  State<_NotificationsPanel> createState() => _NotificationsPanelState();
}

class _NotificationsPanelState extends State<_NotificationsPanel> {
  bool _unreadOnly = false;

  static IconData _icon(String? m) => switch (m) {
        'tickets' || 'helpdesk' => Icons.confirmation_number_outlined,
        'announcement' => Icons.campaign_outlined,
        'ventes' => Icons.receipt_long_outlined,
        'achats' => Icons.shopping_cart_outlined,
        'approbations' => Icons.fact_check_outlined,
        'rh' => Icons.badge_outlined,
        'stock' => Icons.inventory_2_outlined,
        'agenda' || 'rendez_vous' => Icons.event_outlined,
        'woocommerce' || 'ecommerce' => Icons.storefront_outlined,
        _ => Icons.notifications_outlined,
      };

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final width = MediaQuery.sizeOf(context).width;
    final unread = widget.items.where((n) => n['read_at'] == null).length;
    final list = widget.items.where((n) => !_unreadOnly || n['read_at'] == null).toList();
    final today = DateTime.now().toIso8601String().substring(0, 10);
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final n in list) {
      final d = '${n['created_at'] ?? ''}';
      groups.putIfAbsent(d.startsWith(today) ? 'Aujourd\'hui' : 'Plus tôt', () => []).add(n);
    }
    return Material(
      color: t.brightness == Brightness.dark ? const Color(0xFF111A2E) : Colors.white,
      elevation: 16,
      shadowColor: Colors.black38,
      child: SafeArea(
        child: SizedBox(
          width: width < 480 ? width : 440,
          height: double.infinity,
          child: Column(children: [
            Container(
              padding: const EdgeInsets.fromLTRB(20, 16, 8, 16),
              decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFF0E1E45), Color(0xFF1D4FBF)], begin: Alignment.topLeft, end: Alignment.bottomRight)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.notifications_active_outlined, color: Color(0xFFFFD45E)),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('Notifications'), style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
                      Text(tr(unread == 0 ? 'Tout est lu' : '$unread non lue(s)'), style: const TextStyle(color: Color(0xFFBFD0F2), fontSize: 12.5)),
                    ]),
                  ),
                  IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close, color: Colors.white)),
                ]),
                const SizedBox(height: 14),
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: Row(children: [
                    for (final (v, l) in [(false, 'Toutes'), (true, 'Non lues')])
                      Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(20),
                          onTap: () => setState(() => _unreadOnly = v),
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 160),
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
                            decoration: BoxDecoration(color: _unreadOnly == v ? Colors.white : Colors.white.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
                            child: Text(tr(l), style: TextStyle(fontWeight: FontWeight.w700, color: _unreadOnly == v ? YColors.blue : Colors.white)),
                          ),
                        ),
                      ),
                    const Spacer(),
                    if (unread > 0)
                      TextButton(
                        style: TextButton.styleFrom(foregroundColor: Colors.white),
                        onPressed: () async {
                          await widget.onReadAll();
                          if (context.mounted) Navigator.pop(context);
                        },
                        child: Text(tr('Tout marquer comme lu')),
                      ),
                  ]),
                ),
              ]),
            ),
            Expanded(
              child: list.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(24),
                        child: Column(mainAxisSize: MainAxisSize.min, children: [
                          Container(
                            width: 76,
                            height: 76,
                            decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .08), shape: BoxShape.circle),
                            child: const Icon(Icons.notifications_none_rounded, size: 38, color: YColors.blue),
                          ),
                          const SizedBox(height: 12),
                          Text(tr(_unreadOnly ? 'Aucune notification non lue' : 'Aucune notification'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                          const SizedBox(height: 4),
                          Text(tr('Commandes, demandes à valider, messages : tout arrive ici.'), textAlign: TextAlign.center, style: TextStyle(color: t.hintColor, fontSize: 12.5)),
                        ]),
                      ),
                    )
                  : ListView(padding: const EdgeInsets.fromLTRB(12, 10, 12, 24), children: [
                      for (final g in groups.entries) ...[
                        Padding(padding: const EdgeInsets.fromLTRB(8, 8, 8, 6), child: Text(tr(g.key), style: TextStyle(color: t.hintColor, fontWeight: FontWeight.w700, fontSize: 12.5))),
                        for (final n in g.value)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 6),
                            child: Material(
                              color: n['read_at'] == null ? YColors.blue.withValues(alpha: .05) : Colors.transparent,
                              borderRadius: BorderRadius.circular(14),
                              child: InkWell(
                                borderRadius: BorderRadius.circular(14),
                                onTap: () => Navigator.pop(context, n),
                                child: Padding(
                                  padding: const EdgeInsets.all(10),
                                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                    Container(
                                      width: 38,
                                      height: 38,
                                      decoration: BoxDecoration(color: (n['read_at'] == null ? YColors.blue : t.hintColor).withValues(alpha: .1), borderRadius: BorderRadius.circular(11)),
                                      child: Icon(_icon(n['module'] as String?), size: 19, color: n['read_at'] == null ? YColors.blue : t.hintColor),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                        Text(tr('${n['title']}'), style: TextStyle(fontWeight: n['read_at'] == null ? FontWeight.w800 : FontWeight.w600)),
                                        if (n['body'] != null) Text(tr('${n['body']}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: t.hintColor, fontSize: 12.5)),
                                        const SizedBox(height: 3),
                                        Text(tr(dateTimeFr(n['created_at'])), style: TextStyle(color: t.hintColor, fontSize: 11.5)),
                                      ]),
                                    ),
                                    if (n['read_at'] == null) Container(width: 8, height: 8, margin: const EdgeInsets.only(top: 6, left: 6), decoration: const BoxDecoration(color: YColors.blue, shape: BoxShape.circle)),
                                  ]),
                                ),
                              ),
                            ),
                          ),
                      ],
                    ]),
            ),
          ]),
        ),
      ),
    );
  }
}
