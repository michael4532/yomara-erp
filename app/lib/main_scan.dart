import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:provider/provider.dart';

import 'core/evenements.dart';
import 'core/local_db.dart';
import 'core/sync_engine.dart';
import 'state/session.dart';
import 'ui/screens/billetterie_screen.dart' show ScanScreen, tkCanScan, tkTickets;
import 'ui/screens/login_screen.dart';
import 'ui/theme.dart';
import 'ui/widgets/common.dart';
import 'i18n/i18n.dart';

/// Yomara Scan : application de contrôle d'entrée reliée à l'ERP (mêmes comptes, mêmes données, fonctionne hors ligne).
/// flutter run -t lib/main_scan.dart · flutter build apk -t lib/main_scan.dart
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('fr');
  await LocalDb.init();
  final session = Session();
  await session.restore();
  runApp(MultiProvider(
    providers: [ChangeNotifierProvider.value(value: session), ChangeNotifierProvider.value(value: session.sync)],
    child: const YomaraScanApp(),
  ));
}

class YomaraScanApp extends StatelessWidget {
  const YomaraScanApp({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return MaterialApp(
      title: tr('Yomara Scan'),
      debugShowCheckedModeBanner: false,
      theme: buildTheme(Brightness.light),
      locale: const Locale('fr'),
      supportedLocales: const [Locale('fr')],
      localizationsDelegates: const [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
      home: session.isLoggedIn ? const ScanHome() : const LoginScreen(),
    );
  }
}

/// Accueil : événements du jour et à venir, compteurs d'entrées, accès direct au scanner.
class ScanHome extends StatefulWidget {
  const ScanHome({super.key});

  @override
  State<ScanHome> createState() => _ScanHomeState();
}

class _ScanHomeState extends State<ScanHome> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => context.read<SyncEngine>().sync().catchError((_) {}));
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final sync = context.watch<SyncEngine>();
    final today = DateTime.now().toIso8601String().substring(0, 10);
    final allowed = session.hasModule('billetterie') && tkCanScan(session);
    return Scaffold(
      appBar: AppBar(
        title: Row(children: [
          Container(
            padding: const EdgeInsets.all(6),
            decoration: BoxDecoration(color: YColors.yellow, borderRadius: BorderRadius.circular(10)),
            child: const Icon(Icons.qr_code_scanner, color: YColors.night, size: 20),
          ),
          const SizedBox(width: 10),
          Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('Yomara Scan'), style: TextStyle(fontWeight: FontWeight.w800)),
            Text(tr('${session.company['name'] ?? ''}'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w400)),
          ]),
        ]),
        actions: [
          IconButton(
            tooltip: trn(sync.pendingCount > 0 ? '${sync.pendingCount} entrée(s) à envoyer' : 'Synchroniser'),
            onPressed: () => sync.sync().catchError((_) {}),
            icon: Badge(isLabelVisible: sync.pendingCount > 0, label: Text(tr('${sync.pendingCount}')), child: const Icon(Icons.sync)),
          ),
          PopupMenuButton<String>(
            onSelected: (v) async {
              if (v == 'logout' && await confirm(context, tr('Se déconnecter ?'), sync.pendingCount > 0 ? 'Des entrées ne sont pas encore envoyées : elles seront perdues.' : 'Vous pourrez vous reconnecter à tout moment.', ok: tr('Déconnexion'), danger: sync.pendingCount > 0)) {
                await session.logout();
              }
            },
            itemBuilder: (_) => [PopupMenuItem(value: 'logout', child: Text(tr('Déconnexion (${session.user['name'] ?? ''})')))],
          ),
        ],
      ),
      body: !allowed
          ? EmptyState(icon: Icons.lock_outline, title: tr('Accès non autorisé'), message: tr('Votre compte n\'a pas le droit de contrôler les billets, ou le module Billetterie n\'est pas activé. Demandez l\'accès « Contrôle d\'entrée » à votre responsable.'))
          : ValueListenableBuilder(
              valueListenable: LocalDb.listen('ev_events'),
              builder: (context, _, _) => ValueListenableBuilder(
                valueListenable: LocalDb.listen('ev_tickets'),
                builder: (context, _, _) {
                  final events = (LocalDb.hasEntity('ev_events') ? LocalDb.all('ev_events') : <Map<String, dynamic>>[])
                      .where((e) => e['status'] != 'cancelled' && '${e['end_date'] ?? e['start_date']}'.compareTo(today) >= 0)
                      .toList()
                    ..sort((a, b) => '${a['start_date']}'.compareTo('${b['start_date']}'));
                  if (events.isEmpty) {
                    return EmptyState(icon: Icons.event_busy, title: tr('Aucun événement à venir'), message: tr('Les événements de l\'ERP apparaissent ici après synchronisation.'));
                  }
                  return RefreshIndicator(
                    onRefresh: () => sync.sync().catchError((_) {}),
                    child: ListView(padding: const EdgeInsets.all(16), children: [
                      for (var i = 0; i < events.length; i++) AnimatedFadeIn(index: i, child: _EventTile(event: events[i], live: '${events[i]['start_date']}'.compareTo(today) <= 0)),
                      const SizedBox(height: 8),
                      Text(tr(sync.lastSyncAt == null ? 'Pas encore synchronisé' : 'Synchronisé à ${TimeOfDay.fromDateTime(sync.lastSyncAt!).format(context)} · les scans fonctionnent aussi sans connexion'),
                          textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                    ]),
                  );
                },
              ),
            ),
    );
  }
}

class _EventTile extends StatelessWidget {
  const _EventTile({required this.event, required this.live});

  final Map<String, dynamic> event;
  final bool live;

  @override
  Widget build(BuildContext context) {
    final tickets = tkTickets('${event['id']}').where((t) => t['status'] == 'valid').toList();
    final inside = tickets.where((t) => t['checked_in_at'] != null).length;
    final exhibitors = evBookings('${event['id']}').where((b) => b['status'] == 'confirmed').toList();
    final arrived = exhibitors.where((b) => b['checked_in_at'] != null).length;
    final rate = tickets.isEmpty ? 0.0 : inside / tickets.length;
    return HoverLift(
      child: Card(
        margin: const EdgeInsets.only(bottom: 12),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ScanScreen(eventId: '${event['id']}'))),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(tr('${event['name']}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
                if (live) StatusChip(tr('En cours'), color: YColors.success, icon: Icons.circle),
              ]),
              const SizedBox(height: 4),
              Text(tr([dateFr(event['start_date']), if (event['venue'] != null) '${event['venue']}', if (event['city'] != null) '${event['city']}'].join(' · ')), style: TextStyle(color: Theme.of(context).hintColor)),
              const SizedBox(height: 14),
              Row(children: [
                Expanded(child: _Count(label: tr('Entrés'), value: inside, total: tickets.length, color: YColors.blue)),
                if (exhibitors.isNotEmpty) Expanded(child: _Count(label: tr('Exposants arrivés'), value: arrived, total: exhibitors.length, color: YColors.success)),
              ]),
              const SizedBox(height: 10),
              ClipRRect(borderRadius: BorderRadius.circular(6), child: LinearProgressIndicator(value: rate, minHeight: 8, backgroundColor: YColors.blue.withValues(alpha: .1))),
              const SizedBox(height: 14),
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 16)),
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ScanScreen(eventId: '${event['id']}'))),
                  icon: const Icon(Icons.qr_code_scanner),
                  label: Text(tr('Scanner les billets'), style: TextStyle(fontSize: 16)),
                ),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

class _Count extends StatelessWidget {
  const _Count({required this.label, required this.value, required this.total, required this.color});

  final String label;
  final int value, total;
  final Color color;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.baseline, textBaseline: TextBaseline.alphabetic, children: [
          CountUp(value: value.toDouble(), format: (v) => '${v.round()}', style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: color)),
          Text(tr(' / $total'), style: TextStyle(color: Theme.of(context).hintColor)),
        ]),
        Text(tr(label), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
      ]);
}
