import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../state/session.dart';
import '../navigation.dart';
import '../theme.dart';
import 'common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

// ---------------------------------------------------------------------------
// Données « Ma journée » partagées (barre du haut, tableau de bord, panneau)
// ---------------------------------------------------------------------------

class MyDay extends ChangeNotifier {
  MyDay(this.session);
  final Session session;

  List<Map<String, dynamic>> todos = [];
  int open = 0;
  Map<String, dynamic>? day;
  bool loaded = false;

  Future<void> loadTodos() async {
    try {
      final r = Map<String, dynamic>.from(await session.api.get('me/todos'));
      todos = [for (final t in r['data'] as List) asMap(t)];
      open = (r['open'] as num?)?.toInt() ?? 0;
      loaded = true;
      notifyListeners();
    } catch (_) {}
  }

  Future<Map<String, dynamic>?> loadDay(DateTime date) async {
    try {
      final r = Map<String, dynamic>.from(await session.api.get('me/day', query: {'date': DateFormat('yyyy-MM-dd').format(date), 'month': DateFormat('yyyy-MM').format(date)}));
      if (DateUtils.isSameDay(date, DateTime.now())) {
        day = r;
        notifyListeners();
      }
      return r;
    } catch (_) {
      return null;
    }
  }

  Future<void> add(String title, {DateTime? due, bool important = false}) async {
    await session.api.post('me/todos', {'title': title, if (due != null) 'due_date': DateFormat('yyyy-MM-dd').format(due), 'important': important});
    await loadTodos();
  }

  Future<void> patch(Map<String, dynamic> t, Map<String, dynamic> body) async {
    // Mise à jour immédiate de l'affichage, puis confirmation du serveur
    t.addAll(body);
    if (body.containsKey('done')) open += body['done'] == true ? -1 : 1;
    notifyListeners();
    try {
      await session.api.put('me/todos/${t['id']}', body);
    } finally {
      await loadTodos();
    }
  }

  Future<void> remove(Map<String, dynamic> t) async {
    todos.remove(t);
    notifyListeners();
    await session.api.delete('me/todos/${t['id']}');
    await loadTodos();
  }
}

// ---------------------------------------------------------------------------
// Météo (Open-Meteo, sans clé) pour la ville de la société, mise en cache 1 h
// ---------------------------------------------------------------------------

const _weatherCodes = <int, (String, IconData)>{
  0: ('Ensoleillé', Icons.wb_sunny_outlined),
  1: ('Plutôt dégagé', Icons.wb_sunny_outlined),
  2: ('Partiellement nuageux', Icons.wb_cloudy_outlined),
  3: ('Couvert', Icons.cloud_outlined),
  45: ('Brouillard', Icons.foggy),
  48: ('Brouillard', Icons.foggy),
  51: ('Bruine', Icons.grain),
  53: ('Bruine', Icons.grain),
  55: ('Bruine', Icons.grain),
  61: ('Pluie légère', Icons.water_drop_outlined),
  63: ('Pluie', Icons.water_drop_outlined),
  65: ('Forte pluie', Icons.water_drop),
  80: ('Averses', Icons.umbrella_outlined),
  81: ('Averses', Icons.umbrella_outlined),
  82: ('Fortes averses', Icons.umbrella),
  95: ('Orages', Icons.thunderstorm_outlined),
  96: ('Orages', Icons.thunderstorm_outlined),
  99: ('Orages', Icons.thunderstorm),
};

Future<Map<String, dynamic>?> fetchWeather(String city) async {
  if (city.trim().isEmpty) return null;
  final key = 'weather_${city.toLowerCase()}';
  try {
    final cached = LocalDb.isOpen ? LocalDb.state().get(key) as String? : null;
    if (cached != null) {
      final c = Map<String, dynamic>.from(jsonDecode(cached));
      if (DateTime.now().difference(DateTime.parse('${c['at']}')).inMinutes < 60) return c;
    }
    final g = jsonDecode((await http.get(Uri.parse('https://geocoding-api.open-meteo.com/v1/search?count=1&language=fr&name=${Uri.encodeComponent(city)}')).timeout(const Duration(seconds: 6))).body);
    final r = (g['results'] as List?)?.firstOrNull;
    if (r == null) return null;
    final w = jsonDecode((await http.get(Uri.parse('https://api.open-meteo.com/v1/forecast?latitude=${r['latitude']}&longitude=${r['longitude']}&current=temperature_2m,weather_code&daily=temperature_2m_max,temperature_2m_min&forecast_days=1&timezone=auto'))
            .timeout(const Duration(seconds: 6)))
        .body);
    final out = {
      'at': DateTime.now().toIso8601String(),
      'city': r['name'],
      'temp': w['current']?['temperature_2m'],
      'code': w['current']?['weather_code'],
      'max': (w['daily']?['temperature_2m_max'] as List?)?.firstOrNull,
      'min': (w['daily']?['temperature_2m_min'] as List?)?.firstOrNull,
    };
    if (LocalDb.isOpen) await LocalDb.state().put(key, jsonEncode(out));
    return out;
  } catch (_) {
    return null; // hors ligne : pas de météo
  }
}

// ---------------------------------------------------------------------------
// Accueil du tableau de bord
// ---------------------------------------------------------------------------

/// Bandeau d'accueil : salutation selon l'heure, horloge en direct, météo, faits marquants qui défilent,
/// résumé de la journée (rendez-vous, tâches, échéances) et accès au panneau « Ma journée ».
class MyDayHero extends StatefulWidget {
  const MyDayHero({super.key, required this.headlines, this.actions = const [], this.subtitle});
  final List<String> headlines;
  final List<Widget> actions;
  final String? subtitle;

  @override
  State<MyDayHero> createState() => _MyDayHeroState();
}

class _MyDayHeroState extends State<MyDayHero> {
  late Timer _clock;
  Timer? _rotate;
  DateTime _now = DateTime.now();
  int _h = 0;
  Map<String, dynamic>? _weather;

  @override
  void initState() {
    super.initState();
    _clock = Timer.periodic(const Duration(seconds: 1), (_) => setState(() => _now = DateTime.now()));
    _rotate = Timer.periodic(const Duration(seconds: 7), (_) {
      if (widget.headlines.length > 1) setState(() => _h = (_h + 1) % widget.headlines.length);
    });
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      final s = context.read<Session>();
      final w = await fetchWeather('${s.company['city'] ?? ''}'.isEmpty ? 'Abidjan' : '${s.company['city']}');
      if (!mounted) return;
      setState(() => _weather = w);
      final md = context.read<MyDay>();
      md.loadTodos();
      md.loadDay(DateTime.now());
    });
  }

  @override
  void dispose() {
    _clock.cancel();
    _rotate?.cancel();
    super.dispose();
  }

  String get _greeting {
    final h = _now.hour;
    return h < 5 ? 'Bonne nuit' : (h < 12 ? 'Bonjour' : (h < 18 ? 'Bon après-midi' : 'Bonsoir'));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final md = context.watch<MyDay>();
    final first = '${s.user['name'] ?? ''}'.split(' ').first;
    final wide = MediaQuery.of(context).size.width > 900;
    final date = DateFormat('EEEE d MMMM y', I18n.dateLocale).format(_now);
    final events = [for (final e in (md.day?['events'] as List? ?? const [])) asMap(e)];
    final upcoming = events.where((e) => DateTime.tryParse('${e['ends_at']}')?.isAfter(_now) ?? false).toList();
    final next = upcoming.firstOrNull;
    final due = md.day?['due'] as Map?;
    final headline = widget.headlines.isEmpty ? null : widget.headlines[_h % widget.headlines.length];
    final wc = _weather == null ? null : _weatherCodes[(_weather!['code'] as num?)?.toInt() ?? -1];

    final chips = <Widget>[
      _HeroChip(Icons.event_outlined, events.isEmpty ? 'Aucun rendez-vous' : '${events.length} rendez-vous aujourd\'hui', () => showMyDayPanel(context, tab: 0)),
      _HeroChip(Icons.checklist, md.open == 0 ? 'Aucune tâche' : '${md.open} tâche${md.open > 1 ? 's' : ''} à faire', () => showMyDayPanel(context, tab: 1)),
      if (due != null && (due['count'] as num? ?? 0) > 0) _HeroChip(Icons.schedule, '${due['count']} facture(s) à échéance aujourd\'hui', () => ShellNav.of(context)?.go('ventes')),
    ];

    if (MediaQuery.sizeOf(context).width < 600) return _phone(first, date, headline, next, chips, wc);

    return Container(
      margin: EdgeInsets.fromLTRB(wide ? 24 : 16, 18, wide ? 24 : 16, 6),
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF173A7A), Color(0xFF1F6FEB)], begin: Alignment.topLeft, end: Alignment.bottomRight),
        boxShadow: [BoxShadow(color: YColors.blue.withValues(alpha: .18), blurRadius: 24, offset: const Offset(0, 10))],
      ),
      child: Stack(children: [
        Positioned(right: -30, top: -40, child: _Bubble(160, Colors.white.withValues(alpha: .05))),
        Positioned(right: 120, bottom: -60, child: _Bubble(120, YColors.yellow.withValues(alpha: .08))),
        Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.start, runSpacing: 14, spacing: 16, children: [
            Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('$_greeting $first,'), style: TextStyle(color: Colors.white, fontSize: wide ? 28 : 23, fontWeight: FontWeight.w900, letterSpacing: -.5)),
              const SizedBox(height: 4),
              Text(tr('${date[0].toUpperCase()}${date.substring(1)}${widget.subtitle != null ? ' · ${widget.subtitle}' : ''}'),
                  style: TextStyle(color: Colors.white.withValues(alpha: .78), fontSize: 13.5)),
            ]),
            Row(mainAxisSize: MainAxisSize.min, children: [
              // Horloge
              Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Row(crossAxisAlignment: CrossAxisAlignment.baseline, textBaseline: TextBaseline.alphabetic, children: [
                  Text(tr(DateFormat('HH:mm').format(_now)), style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w800, fontFeatures: [FontFeature.tabularFigures()])),
                  Text(tr(DateFormat(':ss').format(_now)), style: TextStyle(color: Colors.white.withValues(alpha: .6), fontSize: 15, fontWeight: FontWeight.w700, fontFeatures: const [FontFeature.tabularFigures()])),
                ]),
                if (_weather != null && wc != null)
                  Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(wc.$2, size: 16, color: YColors.yellow),
                    const SizedBox(width: 5),
                    Text(tr('${(_weather!['temp'] as num?)?.round() ?? '–'}°  ${wc.$1} · ${_weather!['city']}'), style: TextStyle(color: Colors.white.withValues(alpha: .85), fontSize: 12.5)),
                  ]),
              ]),
              if (widget.actions.isNotEmpty) ...[
                const SizedBox(width: 16),
                Theme(
                  data: Theme.of(context).copyWith(
                    outlinedButtonTheme: OutlinedButtonThemeData(style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white38))),
                    iconButtonTheme: IconButtonThemeData(style: IconButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white38))),
                  ),
                  child: Wrap(spacing: 8, children: widget.actions),
                ),
              ],
            ]),
          ]),
          const SizedBox(height: 16),
          // Prochain rendez-vous et fait marquant
          if (next != null || headline != null)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(14)),
              child: Row(children: [
                Icon(next != null ? Icons.notifications_active_outlined : Icons.auto_awesome, color: YColors.yellow, size: 19),
                const SizedBox(width: 10),
                Expanded(
                  child: AnimatedSwitcher(
                    duration: const Duration(milliseconds: 450),
                    layoutBuilder: (cur, prev) => Stack(alignment: Alignment.centerLeft, children: [...prev, ?cur]),
                    transitionBuilder: (c, a) => FadeTransition(opacity: a, child: SlideTransition(position: Tween(begin: const Offset(0, .3), end: Offset.zero).animate(a), child: c)),
                    child: Text(
                      tr(next != null && (_now.second ~/ 7).isEven
                          ? 'Prochain : ${next['title']} à ${DateFormat('HH:mm').format(DateTime.parse('${next['starts_at']}'))}'
                          : (headline ?? '')),
                      key: ValueKey('${_h}_${next?['id']}_${(_now.second ~/ 7).isEven}'),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 13.5),
                    ),
                  ),
                ),
              ]),
            ),
          const SizedBox(height: 12),
          Wrap(spacing: 8, runSpacing: 8, children: chips),
        ]),
      ]),
    );
  }

  /// Téléphone : bandeau plus court, heure discrète, raccourcis sur une ligne qui défile.
  Widget _phone(String first, String date, String? headline, Map<String, dynamic>? next, List<Widget> chips, (String, IconData)? wc) {
    final line = next != null && (_now.second ~/ 7).isEven
        ? 'Prochain : ${next['title']} à ${DateFormat('HH:mm').format(DateTime.parse('${next['starts_at']}'))}'
        : headline;
    return Container(
      margin: const EdgeInsets.fromLTRB(14, 12, 14, 4),
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF173A7A), Color(0xFF1F6FEB)], begin: Alignment.topLeft, end: Alignment.bottomRight),
        boxShadow: [BoxShadow(color: YColors.blue.withValues(alpha: .18), blurRadius: 20, offset: const Offset(0, 8))],
      ),
      child: Stack(clipBehavior: Clip.none, children: [
        Positioned(right: -40, top: -50, child: _Bubble(140, Colors.white.withValues(alpha: .05))),
        Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('$_greeting $first'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 21, fontWeight: FontWeight.w900, letterSpacing: -.4)),
                const SizedBox(height: 3),
                Text(tr('${date[0].toUpperCase()}${date.substring(1)}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: Colors.white.withValues(alpha: .75), fontSize: 12.5)),
                if (widget.subtitle != null)
                  Text(tr(widget.subtitle!), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: Colors.white.withValues(alpha: .6), fontSize: 12)),
              ]),
            ),
            const SizedBox(width: 10),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(tr(DateFormat('HH:mm').format(_now)), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800, fontFeatures: [FontFeature.tabularFigures()])),
              if (_weather != null && wc != null)
                Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(wc.$2, size: 14, color: YColors.yellow),
                  const SizedBox(width: 4),
                  Text(tr('${(_weather!['temp'] as num?)?.round() ?? '–'}°'), style: TextStyle(color: Colors.white.withValues(alpha: .85), fontSize: 12)),
                ]),
            ]),
          ]),
          if (line != null && line.isNotEmpty) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
              child: Row(children: [
                Icon(next != null ? Icons.notifications_active_outlined : Icons.auto_awesome, color: YColors.yellow, size: 17),
                const SizedBox(width: 8),
                Expanded(child: Text(tr(line), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 12.5, height: 1.3))),
              ]),
            ),
          ],
          const SizedBox(height: 10),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Theme(
              data: Theme.of(context).copyWith(
                outlinedButtonTheme: OutlinedButtonThemeData(style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white38), visualDensity: VisualDensity.compact)),
                iconButtonTheme: IconButtonThemeData(style: IconButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white38), visualDensity: VisualDensity.compact)),
              ),
              child: Row(children: [
                for (final c in chips) Padding(padding: const EdgeInsets.only(right: 8), child: c),
                for (final a in widget.actions) Padding(padding: const EdgeInsets.only(right: 6), child: a),
              ]),
            ),
          ),
        ]),
      ]),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble(this.size, this.color);
  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) => IgnorePointer(child: Container(width: size, height: size, decoration: BoxDecoration(shape: BoxShape.circle, color: color)));
}

class _HeroChip extends StatelessWidget {
  const _HeroChip(this.icon, this.label, this.onTap);
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: Colors.white.withValues(alpha: .14),
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          borderRadius: BorderRadius.circular(20),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(icon, size: 16, color: Colors.white),
              const SizedBox(width: 6),
              Text(tr(label), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 12.5)),
            ]),
          ),
        ),
      );
}

// ---------------------------------------------------------------------------
// Boutons de la barre du haut
// ---------------------------------------------------------------------------

class MyDayButtons extends StatefulWidget {
  const MyDayButtons({super.key});

  @override
  State<MyDayButtons> createState() => _MyDayButtonsState();
}

class _MyDayButtonsState extends State<MyDayButtons> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final md = context.read<MyDay>();
      if (!md.loaded) md.loadTodos();
    });
  }

  @override
  Widget build(BuildContext context) {
    final md = context.watch<MyDay>();
    final hint = Theme.of(context).hintColor;
    return Row(mainAxisSize: MainAxisSize.min, children: [
      IconButton(
        tooltip: tr('Calendrier (${DateFormat('d MMMM', I18n.dateLocale).format(DateTime.now())})'),
        onPressed: () => showMyDayPanel(context, tab: 0),
        icon: Stack(clipBehavior: Clip.none, alignment: Alignment.center, children: [
          Icon(Icons.calendar_today_outlined, color: hint, size: 22),
          Positioned(top: 7, child: Text(tr('${DateTime.now().day}'), style: TextStyle(fontSize: 9.5, fontWeight: FontWeight.w900, color: hint))),
        ]),
      ),
      IconButton(
        tooltip: tr('Mes tâches'),
        onPressed: () => showMyDayPanel(context, tab: 1),
        icon: Badge(isLabelVisible: md.open > 0, label: Text(tr('${md.open}')), backgroundColor: YColors.blue, child: Icon(Icons.checklist_rtl, color: hint)),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Panneau latéral « Ma journée » (calendrier + tâches)
// ---------------------------------------------------------------------------

Future<void> showMyDayPanel(BuildContext context, {int tab = 0}) {
  final md = context.read<MyDay>();
  final session = context.read<Session>();
  final nav = ShellNav.of(context);
  return showGeneralDialog(
    context: context,
    barrierDismissible: true,
    barrierLabel: 'Fermer',
    barrierColor: YColors.night.withValues(alpha: .25),
    transitionDuration: const Duration(milliseconds: 260),
    pageBuilder: (c, a, b) => MultiProvider(
      providers: [ChangeNotifierProvider.value(value: md), ChangeNotifierProvider.value(value: session)],
      child: Align(alignment: Alignment.centerRight, child: _MyDayPanel(tab: tab, nav: nav)),
    ),
    transitionBuilder: (c, a, b, child) => SlideTransition(position: Tween(begin: const Offset(1, 0), end: Offset.zero).animate(CurvedAnimation(parent: a, curve: Curves.easeOutCubic)), child: child),
  );
}

class _MyDayPanel extends StatefulWidget {
  const _MyDayPanel({required this.tab, this.nav});
  final int tab;
  final ShellNav? nav;

  @override
  State<_MyDayPanel> createState() => _MyDayPanelState();
}

class _MyDayPanelState extends State<_MyDayPanel> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 2, vsync: this, initialIndex: widget.tab);
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  DateTime _sel = DateUtils.dateOnly(DateTime.now());
  Map<String, dynamic>? _day;
  bool _loadingDay = true;
  final _input = TextEditingController();
  DateTime? _due;
  bool _important = false;
  bool _showDone = false;

  @override
  void initState() {
    super.initState();
    _loadDay();
    context.read<MyDay>().loadTodos();
  }

  Future<void> _loadDay() async {
    setState(() => _loadingDay = true);
    final r = await context.read<MyDay>().loadDay(_sel);
    if (mounted) setState(() => (_day = r, _loadingDay = false));
  }

  Future<void> _add() async {
    final t = _input.text.trim();
    if (t.isEmpty) return;
    _input.clear();
    final due = _due, imp = _important;
    setState(() => (_due = null, _important = false));
    try {
      await context.read<MyDay>().add(t, due: due, important: imp);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final dark = t.brightness == Brightness.dark;
    final width = MediaQuery.of(context).size.width;
    return Material(
      color: dark ? const Color(0xFF111A2E) : Colors.white,
      elevation: 16,
      shadowColor: Colors.black38,
      child: SafeArea(
        child: SizedBox(
          width: width < 480 ? width : 440,
          height: double.infinity,
          child: Column(children: [
            // En-tête : salutation, date et chiffres du jour, puis un sélecteur Agenda / Tâches
            Container(
              padding: const EdgeInsets.fromLTRB(20, 16, 8, 16),
              decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFF0E1E45), Color(0xFF1D4FBF)], begin: Alignment.topLeft, end: Alignment.bottomRight)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.wb_twilight, color: Color(0xFFFFD45E)),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('Ma journée'), style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
                      Text(tr(toBeginningOfSentenceCase(DateFormat('EEEE d MMMM', I18n.dateLocale).format(DateTime.now()))), style: const TextStyle(color: Color(0xFFBFD0F2), fontSize: 12.5)),
                    ]),
                  ),
                  IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close, color: Colors.white)),
                ]),
                const SizedBox(height: 14),
                Builder(builder: (context) {
                  final md = context.watch<MyDay>();
                  final today = [for (final e in (_day?['events'] as List? ?? const [])) e].length;
                  final open = md.todos.where((x) => x['done'] != true).length;
                  final late = md.todos.where((x) => x['done'] != true && x['due_date'] != null && '${x['due_date']}'.compareTo(DateFormat('yyyy-MM-dd').format(DateTime.now())) < 0).length;
                  Widget stat(String v, String l, IconData i) => Expanded(
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                          decoration: BoxDecoration(color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
                          child: Row(children: [
                            Icon(i, size: 16, color: const Color(0xFFBFD0F2)),
                            const SizedBox(width: 6),
                            Text(v, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16)),
                            const SizedBox(width: 4),
                            Flexible(child: Text(tr(l), overflow: TextOverflow.ellipsis, style: const TextStyle(color: Color(0xFFBFD0F2), fontSize: 11.5))),
                          ]),
                        ),
                      );
                  return Padding(
                    padding: const EdgeInsets.only(right: 12),
                    child: Row(children: [
                      stat('$today', 'RDV', Icons.event_outlined),
                      const SizedBox(width: 8),
                      stat('$open', 'tâches', Icons.checklist_rtl),
                      const SizedBox(width: 8),
                      stat('$late', 'en retard', Icons.schedule),
                    ]),
                  );
                }),
                const SizedBox(height: 14),
                Padding(
                  padding: const EdgeInsets.only(right: 12),
                  child: AnimatedBuilder(
                    animation: _tabs,
                    builder: (context, _) => Container(
                      padding: const EdgeInsets.all(4),
                      decoration: BoxDecoration(color: Colors.black.withValues(alpha: .18), borderRadius: BorderRadius.circular(14)),
                      child: Row(children: [
                        for (final (i, l, ic) in [(0, 'Agenda', Icons.calendar_month_outlined), (1, 'Tâches', Icons.checklist_rtl)])
                          Expanded(
                            child: InkWell(
                              borderRadius: BorderRadius.circular(11),
                              onTap: () => _tabs.animateTo(i),
                              child: AnimatedContainer(
                                duration: const Duration(milliseconds: 180),
                                padding: const EdgeInsets.symmetric(vertical: 9),
                                decoration: BoxDecoration(color: _tabs.index == i ? Colors.white : Colors.transparent, borderRadius: BorderRadius.circular(11)),
                                child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                                  Icon(ic, size: 18, color: _tabs.index == i ? YColors.blue : Colors.white),
                                  const SizedBox(width: 6),
                                  Text(tr(l), style: TextStyle(fontWeight: FontWeight.w700, color: _tabs.index == i ? YColors.blue : Colors.white)),
                                ]),
                              ),
                            ),
                          ),
                      ]),
                    ),
                  ),
                ),
              ]),
            ),
            Expanded(child: TabBarView(controller: _tabs, children: [_agenda(context), _todos(context)])),
          ]),
        ),
      ),
    );
  }

  Widget _agenda(BuildContext context) {
    final t = Theme.of(context);
    final hint = t.hintColor;
    final days = asMap(_day?['month_days']);
    final events = [for (final e in (_day?['events'] as List? ?? const [])) asMap(e)];
    final tasks = [for (final e in (_day?['tasks'] as List? ?? const [])) asMap(e)];
    final first = DateTime(_month.year, _month.month);
    final offset = (first.weekday + 6) % 7; // lundi en premier
    final count = DateUtils.getDaysInMonth(_month.year, _month.month);
    final today = DateUtils.dateOnly(DateTime.now());
    return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 24), children: [
      Row(children: [
        IconButton(
            onPressed: () {
              setState(() => _month = DateTime(_month.year, _month.month - 1));
            },
            icon: const Icon(Icons.chevron_left)),
        Expanded(
            child: Text(tr(toBeginningOfSentenceCase(DateFormat('MMMM y', I18n.dateLocale).format(_month))),
                textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
        IconButton(onPressed: () => setState(() => _month = DateTime(_month.year, _month.month + 1)), icon: const Icon(Icons.chevron_right)),
      ]),
      Row(children: [
        for (final d in ['L', 'M', 'M', 'J', 'V', 'S', 'D'])
          Expanded(child: Center(child: Text(tr(d), style: TextStyle(color: hint, fontWeight: FontWeight.w700, fontSize: 12)))),
      ]),
      const SizedBox(height: 4),
      GridView.count(
        crossAxisCount: 7,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        childAspectRatio: 1.05,
        children: [
          for (var i = 0; i < offset; i++) const SizedBox.shrink(),
          for (var d = 1; d <= count; d++)
            Builder(builder: (context) {
              final date = DateTime(_month.year, _month.month, d);
              final key = DateFormat('yyyy-MM-dd').format(date);
              final sel = DateUtils.isSameDay(date, _sel);
              final isToday = DateUtils.isSameDay(date, today);
              final n = (days[key] as num?)?.toInt() ?? 0;
              return InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: () {
                  setState(() => _sel = date);
                  _loadDay();
                },
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 160),
                  margin: const EdgeInsets.all(2),
                  decoration: BoxDecoration(
                    color: sel ? YColors.blue : (isToday ? YColors.blue.withValues(alpha: .1) : null),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                    Text(tr('$d'), style: TextStyle(fontWeight: isToday || sel ? FontWeight.w900 : FontWeight.w500, color: sel ? Colors.white : (isToday ? YColors.blue : null))),
                    const SizedBox(height: 2),
                    Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                      for (var k = 0; k < (n > 3 ? 3 : n); k++)
                        Container(width: 4, height: 4, margin: const EdgeInsets.symmetric(horizontal: 1), decoration: BoxDecoration(color: sel ? Colors.white : YColors.warning, shape: BoxShape.circle)),
                    ]),
                  ]),
                ),
              );
            }),
        ],
      ),
      const Divider(height: 24),
      Row(children: [
        Expanded(child: Text(tr(DateUtils.isSameDay(_sel, today) ? 'Aujourd\'hui' : toBeginningOfSentenceCase(DateFormat('EEEE d MMMM', I18n.dateLocale).format(_sel))), style: const TextStyle(fontWeight: FontWeight.w800))),
        if (widget.nav?.available.contains('agenda') == true)
          TextButton.icon(
              onPressed: () {
                Navigator.pop(context);
                widget.nav!.go('agenda');
              },
              icon: const Icon(Icons.open_in_new, size: 16),
              label: Text(tr('Ouvrir l\'agenda'))),
      ]),
      if (_loadingDay)
        const Padding(padding: EdgeInsets.all(16), child: LinearProgressIndicator())
      else if (events.isEmpty && tasks.isEmpty)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 18),
          child: Column(children: [
            Icon(Icons.event_available, size: 36, color: hint.withValues(alpha: .5)),
            const SizedBox(height: 6),
            Text(tr('Rien de prévu'), style: TextStyle(color: hint)),
          ]),
        )
      else ...[
        for (final e in events)
          Container(
            margin: const EdgeInsets.only(bottom: 8),
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              color: _hexColor('${e['color']}').withValues(alpha: .08),
              border: Border(left: BorderSide(color: _hexColor('${e['color']}'), width: 3)),
            ),
            child: Row(children: [
              SizedBox(
                width: 54,
                child: Text(tr(e['all_day'] == true ? 'Journée' : DateFormat('HH:mm').format(DateTime.parse('${e['starts_at']}'))),
                    style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5)),
              ),
              Expanded(child: Text(tr('${e['title']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
            ]),
          ),
        if (tasks.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(tr('Mes tâches de projet'), style: TextStyle(color: hint, fontWeight: FontWeight.w700, fontSize: 12.5)),
          for (final k in tasks)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.assignment_outlined, size: 20),
              title: Text(tr('${k['title']}')),
              subtitle: Text(tr('${k['project'] ?? ''}${k['due_date'] != null ? ' · pour le ${dateFr('${k['due_date']}')}' : ''}')),
            ),
        ],
      ],
    ]);
  }

  Widget _todos(BuildContext context) {
    final md = context.watch<MyDay>();
    final hint = Theme.of(context).hintColor;
    final open = md.todos.where((t) => t['done'] != true).toList();
    final done = md.todos.where((t) => t['done'] == true).toList();
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 6),
        child: TextField(
          controller: _input,
          onSubmitted: (_) => _add(),
          decoration: InputDecoration(
            hintText: tr('Ajouter une tâche…'),
            prefixIcon: const Icon(Icons.add_task),
            suffixIcon: Row(mainAxisSize: MainAxisSize.min, children: [
              IconButton(
                tooltip: tr('Échéance'),
                onPressed: () async {
                  final d = await pickDate(context, firstDate: DateTime.now().subtract(const Duration(days: 365)), lastDate: DateTime.now().add(const Duration(days: 730)), initialDate: _due ?? DateTime.now());
                  setState(() => _due = d);
                },
                icon: Icon(Icons.event, color: _due != null ? YColors.blue : null),
              ),
              IconButton(tooltip: tr('Importante'), onPressed: () => setState(() => _important = !_important), icon: Icon(_important ? Icons.star : Icons.star_border, color: _important ? YColors.yellow : null)),
              IconButton(tooltip: tr('Ajouter'), onPressed: _add, icon: const Icon(Icons.send, color: YColors.blue)),
            ]),
          ),
        ),
      ),
      if (_due != null)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Align(alignment: Alignment.centerLeft, child: InputChip(label: Text(tr('Pour le ${DateFormat('d MMM', I18n.dateLocale).format(_due!)}')), onDeleted: () => setState(() => _due = null))),
        ),
      Expanded(
        child: !md.loaded
            ? const Center(child: CircularProgressIndicator())
            : ListView(padding: const EdgeInsets.fromLTRB(8, 4, 8, 24), children: [
                if (open.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 36),
                    child: Column(children: [
                      Container(
                        width: 76,
                        height: 76,
                        decoration: BoxDecoration(color: YColors.success.withValues(alpha: .1), shape: BoxShape.circle),
                        child: const Icon(Icons.task_alt, size: 38, color: YColors.success),
                      ),
                      const SizedBox(height: 12),
                      Text(tr('Tout est fait. Belle journée !'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                      const SizedBox(height: 4),
                      Text(tr('Ajoutez une tâche ci-dessus : avec une date, elle apparaît aussi dans votre agenda.'), textAlign: TextAlign.center, style: TextStyle(color: hint, fontSize: 12.5)),
                    ]),
                  ),
                for (final t in open) _TodoTile(t: t),
                if (done.isNotEmpty) ...[
                  TextButton.icon(
                    onPressed: () => setState(() => _showDone = !_showDone),
                    icon: Icon(_showDone ? Icons.expand_less : Icons.expand_more),
                    label: Text(tr('Terminées (${done.length})')),
                  ),
                  if (_showDone) for (final t in done) _TodoTile(t: t),
                ],
              ]),
      ),
    ]);
  }
}

Color _hexColor(String h) {
  final v = int.tryParse(h.replaceAll('#', ''), radix: 16);
  return v == null ? YColors.blue : Color(0xFF000000 | v);
}

class _TodoTile extends StatelessWidget {
  const _TodoTile({required this.t});
  final Map<String, dynamic> t;

  @override
  Widget build(BuildContext context) {
    final md = context.read<MyDay>();
    final done = t['done'] == true;
    final hint = Theme.of(context).hintColor;
    final due = t['due_date'] != null ? DateTime.tryParse('${t['due_date']}') : null;
    final late = due != null && !done && due.isBefore(DateUtils.dateOnly(DateTime.now()));
    return Dismissible(
      key: ValueKey('todo${t['id']}'),
      direction: DismissDirection.endToStart,
      background: Container(alignment: Alignment.centerRight, padding: const EdgeInsets.only(right: 20), color: YColors.danger.withValues(alpha: .12), child: const Icon(Icons.delete_outline, color: YColors.danger)),
      onDismissed: (_) => md.remove(t),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 8),
        leading: Checkbox(shape: const CircleBorder(), value: done, onChanged: (v) => md.patch(t, {'done': v == true})),
        title: Text(tr('${t['title']}'), style: TextStyle(decoration: done ? TextDecoration.lineThrough : null, color: done ? hint : null, fontWeight: FontWeight.w600)),
        subtitle: due == null
            ? null
            : Row(children: [
                Icon(Icons.event, size: 13, color: late ? YColors.danger : hint),
                const SizedBox(width: 4),
                Text(tr(DateUtils.isSameDay(due, DateTime.now()) ? 'Aujourd\'hui' : DateFormat('EEE d MMM', I18n.dateLocale).format(due)), style: TextStyle(fontSize: 12, color: late ? YColors.danger : hint)),
              ]),
        trailing: IconButton(
          onPressed: () => md.patch(t, {'important': t['important'] != true}),
          icon: Icon(t['important'] == true ? Icons.star : Icons.star_border, color: t['important'] == true ? YColors.yellow : hint),
        ),
      ),
    );
  }
}
