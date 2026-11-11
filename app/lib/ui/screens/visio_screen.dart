import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart' show kIsWeb, defaultTargetPlatform;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/visio_frame.dart';
import 'store_screen.dart' show YomeetQuotaBar, showYomeetMinutesShop;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
import 'package:yomara_erp/core/config.dart';
const _violet = Color(0xFF7C3AED);
const _providerNames = {'zego': 'ZEGOCLOUD', 'agora': 'Agora', 'p2p': 'Pair-à-pair (secours)'};
const _roleNames = {'host': 'Organisateur', 'cohost': 'Co-organisateur', 'panelist': 'Intervenant', 'attendee': 'Inscrit', 'guest': 'Invité externe', 'member': 'Membre'};
const _optionLabels = {
  'lobby': ('Salle d\'attente', 'Les invités externes attendent d\'être admis'),
  'guests': ('Invités par lien', 'Toute personne ayant le lien peut demander à entrer'),
  'mute_on_entry': ('Micros coupés à l\'entrée', 'Idéal au-delà de 6 participants'),
  'chat': ('Conversation', 'Messages écrits, publics ou privés'),
  'screen': ('Partage d\'écran pour tous', 'Sinon réservé aux organisateurs'),
  'transcribe': ('Sous-titres et transcription', 'Reconnaissance vocale en français, dans le navigateur'),
  'record_auto': ('Enregistrement automatique', 'Démarre dès l\'arrivée de l\'organisateur'),
  'ai_report': ('Compte rendu par l\'assistant IA', 'Résumé, décisions et actions (module IA)'),
  'send_report': ('Envoyer le compte rendu', 'Par e-mail aux participants, à la fin'),
  'reactions': ('Réactions', 'Applaudissements, pouces, emojis'),
};

/// Ouvre la salle : dans l'application (navigateur web, Android, iPhone, Mac, Windows) ou dans le navigateur par défaut (Linux).
Future<void> openVisioRoom(BuildContext context, String url, String title) async {
  // « app=1 » : la page de la salle ne propose pas d'ouvrir l'application, on en vient
  final u0 = Uri.parse(url);
  final u = u0.replace(queryParameters: {...u0.queryParameters, 'app': '1'});
  if (!visioEmbedded) {
    await safeLaunchUrl(u, mode: LaunchMode.externalApplication);
    return;
  }
  await Navigator.of(context).push(
    MaterialPageRoute(
      fullscreenDialog: true,
      builder: (c) => _RoomPage(url: u.toString(), title: title),
    ),
  );
}

class _RoomPage extends StatelessWidget {
  const _RoomPage({required this.url, required this.title});

  final String url;
  final String title;

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: const Color(0xFF0B1220),
    appBar: AppBar(
      toolbarHeight: 40,
      backgroundColor: const Color(0xFF0B1220),
      foregroundColor: Colors.white,
      titleTextStyle: const TextStyle(color: Colors.white70, fontSize: 13),
      title: Text(tr('$title · la réunion continue tant que cette page est ouverte')),
      actions: [
        // Téléphone : pas de seconde fenêtre (elle rejoindrait la réunion une deuxième fois)
        if (kIsWeb || !(defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS))
        TextButton.icon(
          onPressed: () => safeLaunchUrl(Uri.parse(url), webOnlyWindowName: '_blank'),
          icon: const Icon(Icons.open_in_new, size: 16, color: Colors.white70),
          label: Text(tr('Nouvelle fenêtre'), style: TextStyle(color: Colors.white70)),
        ),
      ],
    ),
    body: VisioFrame(url: url, onLeft: () {}),
  );
}

DateTime? _dt(dynamic v) => v == null ? null : DateTime.tryParse('$v');

/// Réunions & webinaires : planification, salle intégrée, comptes rendus, enregistrements, webinaires et réglages des fournisseurs.
class VisioScreen extends StatefulWidget {
  const VisioScreen({super.key});

  @override
  State<VisioScreen> createState() => _VisioScreenState();
}

class _VisioScreenState extends State<VisioScreen> {
  Map<String, dynamic>? _d;
  Object? _error;
  String _type = 'meeting';
  String _scope = 'upcoming';
  String _q = '';
  Timer? _refresh, _debounce;

  @override
  void initState() {
    super.initState();
    _load();
    _refresh = Timer.periodic(const Duration(seconds: 30), (_) => _load(quiet: true));
  }

  @override
  void dispose() {
    _refresh?.cancel();
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _load({bool quiet = false}) async {
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.get('visio', query: {'type': _type, 'scope': _scope, if (_q.isNotEmpty) 'q': _q}));
      if (mounted) {
        setState(() {
          _d = d;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted && !quiet) setState(() => _error = e);
    }
  }

  List<Map<String, dynamic>> get _meetings => [for (final m in (_d?['meetings'] as List? ?? const [])) asMap(m)];

  Future<void> _instant() async {
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.post('visio/instant', {}));
      if (!mounted) return;
      final url = '${r['join_url']}';
      await Clipboard.setData(ClipboardData(text: '${r['data']['url']}'));
      if (!mounted) return;
      toast(context, tr('Réunion créée — lien d\'invitation copié.'));
      await openVisioRoom(context, url, '${r['data']['title']}');
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _edit({Map<String, dynamic>? m, String type = 'meeting'}) async {
    final saved = await showFormDialog<bool>(
      context,
      title: m != null ? 'Modifier' : (type == 'webinar' ? 'Nouveau webinaire' : 'Planifier une réunion'),
      maxWidth: 720,
      builder: (_) => _MeetingForm(meeting: m, type: m?['type'] ?? type, users: [for (final u in (_d?['users'] as List? ?? const [])) asMap(u)], defaults: asMap(_d?['defaults'])),
    );
    if (saved == true) _load();
  }

  Future<void> _join(Map<String, dynamic> m) async {
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.post('visio/meetings/${m['id']}/join'));
      if (mounted) await openVisioRoom(context, '${r['url']}', '${m['title']}');
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _open(Map<String, dynamic> m) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => _MeetingDetail(id: m['id'] as int, onJoin: () => _join(m)),
      ),
    );
    _load();
  }

  Future<void> _settings() async {
    await showFormDialog(context, title: tr('Paramètres Yomeet'), maxWidth: 760, builder: (_) => const _SettingsForm());
    _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? Center(child: Text(tr(errorText(_error!)))) : const Center(child: CircularProgressIndicator());
    final width = MediaQuery.of(context).size.width;
    final pad = width < 700 ? 16.0 : 24.0;
    final stats = asMap(_d!['stats']);
    final canWebinar = _d!['can_webinar'] == true, canManage = _d!['can_manage'] == true;
    final list = _meetings;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.only(bottom: 40),
        children: [
          PageHeader(
            title: tr('Yomeet'),
            subtitle: tr('Réunions & webinaires intégrés à l\'ERP : lien et code à partager, comptes rendus automatiques, inscriptions'),
            actions: [
              if (canManage) IconButton(tooltip: tr('Paramètres'), onPressed: _settings, icon: const Icon(Icons.tune)),
              if (canWebinar)
                OutlinedButton.icon(
                  onPressed: () => _edit(type: 'webinar'),
                  icon: const Icon(Icons.podcasts),
                  label: Text(tr('Webinaire')),
                ),
              OutlinedButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.event), label: Text(tr('Planifier'))),
              FilledButton.icon(onPressed: _d!['configured'] == true ? _instant : null, icon: const Icon(Icons.video_call), label: Text(tr('Réunion instantanée'))),
            ],
          ),
          if (_d!['quota'] is Map)
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 0, pad, 14),
              child: YomeetQuotaBar(
                quota: Map<String, dynamic>.from(_d!['quota'] as Map),
                compact: width < 700,
                onBuy: _d!['can_buy'] == true && !AppConfig.purchasesHidden ? () => showYomeetMinutesShop(context, onChanged: _load) : null,
              ),
            ),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: pad),
            child: _Hero(stats: stats, onInstant: _d!['configured'] == true ? _instant : null),
          ),
          Padding(
            padding: EdgeInsets.fromLTRB(pad, 18, pad, 8),
            child: Wrap(
              spacing: 12,
              runSpacing: 10,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                SegmentedButton<String>(
                  segments: [
                    ButtonSegment(value: 'meeting', icon: Icon(Icons.groups_outlined), label: Text(tr('Réunions'))),
                    ButtonSegment(value: 'webinar', icon: Icon(Icons.podcasts), label: Text(tr('Webinaires'))),
                  ],
                  selected: {_type},
                  onSelectionChanged: (s) => setState(() {
                    _type = s.first;
                    _load();
                  }),
                ),
                SegmentedButton<String>(
                  segments: [
                    ButtonSegment(value: 'upcoming', label: Text(tr('À venir'))),
                    ButtonSegment(value: 'past', label: Text(tr('Passées'))),
                  ],
                  selected: {_scope},
                  onSelectionChanged: (s) => setState(() {
                    _scope = s.first;
                    _load();
                  }),
                ),
                SizedBox(
                  width: 260,
                  child: SearchField(
                    onChanged: (v) {
                      _q = v.trim();
                      _debounce?.cancel();
                      _debounce = Timer(const Duration(milliseconds: 400), () => _load(quiet: true));
                    },
                  ),
                ),
              ],
            ),
          ),
          if (list.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 30),
              child: EmptyState(
                icon: _type == 'webinar' ? Icons.podcasts : Icons.video_camera_front_outlined,
                title: _scope == 'past' ? 'Aucune ${_type == 'webinar' ? 'session passée' : 'réunion passée'}' : (_type == 'webinar' ? 'Aucun webinaire programmé' : 'Aucune réunion à venir'),
                message: _type == 'webinar'
                    ? 'Créez un webinaire : une page d\'inscription est générée, les inscrits reçoivent leur lien et des rappels.'
                    : 'Lancez une réunion instantanée ou planifiez-la : les invités reçoivent un lien et une invitation pour leur calendrier.',
              ),
            )
          else
            Padding(
              padding: EdgeInsets.symmetric(horizontal: pad),
              child: Column(
                children: [
                  for (final m in list)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _MeetingCard(
                        m: m,
                        onJoin: () => _join(m),
                        onOpen: () => _open(m),
                        onEdit: () => _edit(m: m),
                        onChanged: _load,
                      ),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.stats, this.onInstant});

  final Map<String, dynamic> stats;
  final VoidCallback? onInstant;

  @override
  Widget build(BuildContext context) {
    Widget kpi(String label, String value, IconData icon, double w) => Container(
      width: w,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.white.withValues(alpha: .1)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: Colors.white70, size: 18),
          const SizedBox(height: 8),
          Text(
            tr(value),
            style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800),
          ),
          Text(
            tr(label),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(color: Colors.white70, fontSize: 12),
          ),
        ],
      ),
    );
    final intro = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          tr('Réunissez-vous en un clic'),
          style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800),
        ),
        const SizedBox(height: 6),
        Text(tr('Vidéo HD, partage d\'écran, transcription et compte rendu automatique. Double infrastructure avec bascule invisible.'), style: TextStyle(color: Colors.white70, height: 1.4)),
        const SizedBox(height: 12),
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: YColors.night),
          onPressed: onInstant,
          icon: const Icon(Icons.bolt),
          label: Text(tr('Démarrer maintenant')),
        ),
      ],
    );
    final items = [
      ('En cours', '${stats['live'] ?? 0}', Icons.sensors),
      ('À venir', '${stats['upcoming'] ?? 0}', Icons.event_available),
      ('Tenues ce mois', '${stats['month'] ?? 0}', Icons.task_alt),
      ('Minutes ce mois', NumberFormat.decimalPattern('fr').format(stats['minutes'] ?? 0), Icons.timer_outlined),
      ('Inscrits webinaires', '${stats['webinar_registrants'] ?? 0}', Icons.how_to_reg),
    ];
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        gradient: const LinearGradient(colors: [Color(0xFF0B1220), Color(0xFF1E3A8A), _violet], begin: Alignment.topLeft, end: Alignment.bottomRight, stops: [0, .6, 1]),
      ),
      child: LayoutBuilder(
        builder: (context, c) {
          if (c.maxWidth >= 920) {
            return Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                SizedBox(width: 280, child: intro),
                const SizedBox(width: 18),
                Expanded(
                  child: LayoutBuilder(
                    builder: (context, k) {
                      final w = (k.maxWidth - 4 * 10) / 5;
                      return Row(
                        children: [
                          for (final (i, it) in items.indexed)
                            Padding(
                              padding: EdgeInsets.only(left: i == 0 ? 0 : 10),
                              child: kpi(it.$1, it.$2, it.$3, w),
                            ),
                        ],
                      );
                    },
                  ),
                ),
              ],
            );
          }
          final w = c.maxWidth < 520 ? (c.maxWidth - 10) / 2 : 150.0;
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              intro,
              const SizedBox(height: 14),
              Wrap(spacing: 10, runSpacing: 10, children: [for (final it in items) kpi(it.$1, it.$2, it.$3, w)]),
            ],
          );
        },
      ),
    );
  }
}

class _MeetingCard extends StatelessWidget {
  const _MeetingCard({required this.m, required this.onJoin, required this.onOpen, required this.onEdit, required this.onChanged});

  final Map<String, dynamic> m;
  final VoidCallback onJoin, onOpen, onEdit, onChanged;

  @override
  Widget build(BuildContext context) {
    final start = _dt(m['starts_at']);
    final live = m['status'] == 'live', ended = m['status'] == 'ended', cancelled = m['status'] == 'cancelled';
    final webinar = m['type'] == 'webinar';
    final color = live ? YColors.success : (webinar ? _violet : YColors.blue);
    final soon = start != null && start.difference(DateTime.now()).inMinutes < 15 && !ended && !cancelled;
    final hint = Theme.of(context).hintColor;
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onOpen,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 64,
                padding: const EdgeInsets.symmetric(vertical: 8),
                decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
                child: Column(
                  children: [
                    Text(
                      tr(start == null ? '—' : DateFormat('d', I18n.dateLocale).format(start)),
                      style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: color),
                    ),
                    Text(
                      tr(start == null ? '' : DateFormat('MMM', I18n.dateLocale).format(start).toUpperCase()),
                      style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: color),
                    ),
                    Text(tr(start == null ? '' : DateFormat('HH:mm').format(start)), style: TextStyle(fontSize: 11, color: hint)),
                  ],
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 8,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(tr('${m['title']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                        if (live) StatusChip(tr('En direct'), color: YColors.success, icon: Icons.sensors),
                        if (cancelled) StatusChip(tr('Annulée'), color: YColors.danger),
                        if (webinar) StatusChip(tr('Webinaire'), color: _violet, icon: Icons.podcasts),
                        if (m['has_report'] == true) StatusChip(tr('Compte rendu'), color: YColors.blue, icon: Icons.summarize_outlined),
                        if ((m['recordings'] ?? 0) > 0) StatusChip(tr('Enregistrée'), color: YColors.danger, icon: Icons.fiber_manual_record),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Text(
                      tr(
                        [
                          if (m['host'] != null) 'Par ${m['host']}',
                          '${m['duration_minutes']} min',
                          webinar ? '${m['invited']} inscrit(s) et intervenant(s)' : '${m['invited']} invité(s)',
                          if (ended) '${m['present']} présent(s)',
                          if (live) '${m['online']} connecté(s)',
                        ].join(' · '),
                      ),
                      style: TextStyle(color: hint, fontSize: 13),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              if (!ended && !cancelled)
                FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: live ? YColors.success : null),
                  onPressed: onJoin,
                  icon: const Icon(Icons.videocam, size: 18),
                  label: Text(tr(live ? 'Rejoindre' : (soon ? 'Démarrer' : 'Ouvrir la salle'))),
                )
              else if (m['has_report'] == true)
                OutlinedButton.icon(onPressed: onOpen, icon: const Icon(Icons.summarize_outlined, size: 18), label: Text(tr('Compte rendu'))),
              PopupMenuButton<String>(
                onSelected: (v) async {
                  final api = context.read<Session>().api;
                  if (v == 'copy') {
                    await Clipboard.setData(ClipboardData(text: '${webinar && m['registration_url'] != null ? m['registration_url'] : m['url']}'));
                    if (context.mounted) toast(context, webinar ? 'Lien de la page d\'inscription copié.' : 'Lien de la réunion copié.');
                  } else if (v == 'edit') {
                    onEdit();
                  } else if (v == 'ics') {
                    final bytes = await api.download('visio/meetings/${m['id']}/calendar.ics');
                    await saveFile('reunion.ics', bytes, mime: 'text/calendar');
                  } else if (v == 'page') {
                    safeLaunchUrl(Uri.parse('${m['registration_url']}'), webOnlyWindowName: '_blank');
                  } else if (v == 'cancel') {
                    if (!await confirm(context, tr('Annuler la réunion ?'), tr('Les invités verront la réunion annulée dans leur agenda.'), ok: tr('Annuler la réunion'), danger: true)) return;
                    try {
                      await api.delete('visio/meetings/${m['id']}');
                      onChanged();
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  }
                },
                itemBuilder: (_) => [
                  PopupMenuItem(value: 'copy', child: Text(tr(webinar ? 'Copier le lien d\'inscription' : 'Copier le lien'))),
                  if (webinar && m['registration_url'] != null) PopupMenuItem(value: 'page', child: Text(tr('Voir la page d\'inscription'))),
                  if (!ended && !cancelled) PopupMenuItem(value: 'edit', child: Text(tr('Modifier, inviter'))),
                  PopupMenuItem(value: 'ics', child: Text(tr('Ajouter à mon calendrier (.ics)'))),
                  if (!live)
                    PopupMenuItem(
                      value: 'cancel',
                      child: Text(tr(ended || cancelled ? 'Supprimer' : 'Annuler'), style: const TextStyle(color: YColors.danger)),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------- Formulaire

class _MeetingForm extends StatefulWidget {
  const _MeetingForm({this.meeting, required this.type, required this.users, required this.defaults});

  final Map<String, dynamic>? meeting;
  final String type;
  final List<Map<String, dynamic>> users;
  final Map<String, dynamic> defaults;

  @override
  State<_MeetingForm> createState() => _MeetingFormState();
}

class _MeetingFormState extends State<_MeetingForm> {
  late final _title = TextEditingController(text: widget.meeting?['title'] ?? '');
  late final _desc = TextEditingController(text: widget.meeting?['description'] ?? '');
  late final _password = TextEditingController(text: widget.meeting?['password'] ?? '');
  late final _capacity = TextEditingController(text: '${widget.meeting?['capacity'] ?? 0}');
  late final _slug = TextEditingController(text: widget.meeting?['slug'] ?? '');
  final _guestName = TextEditingController(), _guestEmail = TextEditingController();
  late DateTime _start = _dt(widget.meeting?['starts_at']) ?? _nextSlot();
  late int _duration = (widget.meeting?['duration_minutes'] as num?)?.toInt() ?? (widget.type == 'webinar' ? 90 : 60);
  late final Map<String, bool> _opts = {for (final k in _optionLabels.keys) k: ((widget.meeting?['options'] as Map?)?[k] ?? widget.defaults[k] ?? false) == true};
  final Set<int> _users = {};
  final List<Map<String, String>> _guests = [];
  late bool _regOpen = widget.meeting?['registration_open'] ?? true;
  late bool _replay = widget.meeting?['replay_public'] ?? true;
  late bool _leads = widget.meeting?['create_leads'] ?? false;
  bool _send = true, _busy = false;

  bool get _webinar => widget.type == 'webinar';

  static DateTime _nextSlot() {
    final n = DateTime.now().add(const Duration(minutes: 30));
    return DateTime(n.year, n.month, n.day, n.hour, n.minute < 30 ? 30 : 0).add(Duration(hours: n.minute < 30 ? 0 : 1));
  }

  Future<void> _pickDate() async {
    final d = await pickDate(context, initialDate: _start, firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime.now().add(const Duration(days: 730)));
    if (d == null || !mounted) return;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_start));
    setState(() => _start = DateTime(d.year, d.month, d.day, t?.hour ?? _start.hour, t?.minute ?? _start.minute));
  }

  void _addGuest() {
    final e = _guestEmail.text.trim();
    if (e.isEmpty && _guestName.text.trim().isEmpty) return;
    setState(() {
      _guests.add({'name': _guestName.text.trim(), 'email': e});
      _guestName.clear();
      _guestEmail.clear();
    });
  }

  Future<void> _save() async {
    if (_title.text.trim().isEmpty) {
      toast(context, tr('Donnez un titre.'), error: true);
      return;
    }
    _addGuest();
    setState(() => _busy = true);
    final body = {
      'type': widget.type,
      'title': _title.text.trim(),
      'description': _desc.text.trim(),
      'starts_at': DateFormat('yyyy-MM-dd HH:mm:ss').format(_start),
      'duration_minutes': _duration,
      'options': _opts,
      'password': _password.text.trim().isEmpty ? null : _password.text.trim(),
      'user_ids': _users.toList(),
      'guests': _guests,
      'send_invites': _send,
      if (_webinar) ...{
        'capacity': int.tryParse(_capacity.text) ?? 0,
        'registration_open': _regOpen,
        'replay_public': _replay,
        'create_leads': _leads,
        if (_slug.text.trim().isNotEmpty) 'slug': _slug.text.trim(),
      },
    };
    try {
      final api = context.read<Session>().api;
      final r = Map<String, dynamic>.from(widget.meeting == null ? await api.post('visio/meetings', body) : await api.put('visio/meetings/${widget.meeting!['id']}', body));
      if (!mounted) return;
      final sent = r['sent'] ?? 0;
      toast(context, sent > 0 ? '$sent invitation(s) envoyée(s) par e-mail.' : (widget.meeting == null ? 'Réunion planifiée.' : 'Modifications enregistrées.'));
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Field(
                label: _webinar ? 'Titre du webinaire' : 'Objet de la réunion',
                child: TextField(controller: _title, autofocus: widget.meeting == null),
              ),
              Field(
                label: _webinar ? 'Présentation (affichée sur la page d\'inscription)' : 'Ordre du jour',
                child: TextField(controller: _desc, maxLines: 4, minLines: 2),
              ),
              Wrap(
                spacing: 12,
                runSpacing: 4,
                children: [
                  SizedBox(
                    width: 280,
                    child: Field(
                      label: tr('Date et heure'),
                      child: OutlinedButton.icon(
                        onPressed: _pickDate,
                        icon: const Icon(Icons.event, size: 18),
                        label: Text(tr(DateFormat('EEEE d MMMM yyyy · HH:mm', I18n.dateLocale).format(_start))),
                      ),
                    ),
                  ),
                  SizedBox(
                    width: 200,
                    child: Field(
                      label: tr('Durée'),
                      child: DropdownButtonFormField<int>(
                        initialValue: [15, 30, 45, 60, 90, 120, 180, 240].contains(_duration) ? _duration : 60,
                        items: [
                          for (final d in [15, 30, 45, 60, 90, 120, 180, 240]) DropdownMenuItem(value: d, child: Text(tr(d < 60 ? '$d min' : '${d ~/ 60} h${d % 60 == 0 ? '' : ' ${d % 60}'}'))),
                        ],
                        onChanged: (v) => setState(() => _duration = v ?? 60),
                      ),
                    ),
                  ),
                  if (!_webinar)
                    SizedBox(
                      width: 180,
                      child: Field(
                        label: tr('Code d\'accès (facultatif)'),
                        child: TextField(controller: _password),
                      ),
                    ),
                ],
              ),
              Text(tr(_webinar ? 'Intervenants de l\'équipe' : 'Participants de l\'équipe'), style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final u in widget.users)
                    FilterChip(label: Text(tr('${u['name']}')), selected: _users.contains(u['id']), onSelected: (s) => setState(() => s ? _users.add(u['id'] as int) : _users.remove(u['id']))),
                ],
              ),
              const SizedBox(height: 16),
              Text(tr(_webinar ? 'Intervenants externes' : 'Invités externes'), style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 4),
              Text(
                tr(_webinar ? 'Le public s\'inscrit sur la page du webinaire ; ajoutez ici les orateurs invités.' : 'Clients, partenaires… ils reçoivent un lien personnel, sans compte à créer.'),
                style: TextStyle(color: hint, fontSize: 12),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _guestName,
                      decoration: InputDecoration(hintText: tr('Nom')),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 2,
                    child: TextField(
                      controller: _guestEmail,
                      decoration: InputDecoration(hintText: tr('E-mail')),
                      onSubmitted: (_) => _addGuest(),
                    ),
                  ),
                  IconButton(onPressed: _addGuest, icon: const Icon(Icons.add_circle_outline)),
                ],
              ),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final g in _guests) InputChip(label: Text(tr(g['name']!.isNotEmpty ? '${g['name']} <${g['email']}>' : g['email']!)), onDeleted: () => setState(() => _guests.remove(g))),
                ],
              ),
              if (_webinar) ...[
                const SizedBox(height: 16),
                Text(tr('Inscriptions'), style: TextStyle(fontWeight: FontWeight.w700)),
                Wrap(
                  spacing: 12,
                  children: [
                    SizedBox(
                      width: 160,
                      child: Field(
                        label: tr('Places (0 = illimité)'),
                        child: TextField(controller: _capacity, keyboardType: TextInputType.number),
                      ),
                    ),
                    SizedBox(
                      width: 300,
                      child: Field(
                        label: tr('Adresse de la page (/webinaire/…)'),
                        child: TextField(
                          controller: _slug,
                          decoration: InputDecoration(hintText: tr('générée depuis le titre')),
                        ),
                      ),
                    ),
                  ],
                ),
                SwitchListTile(contentPadding: EdgeInsets.zero, value: _regOpen, onChanged: (v) => setState(() => _regOpen = v), title: Text(tr('Inscriptions ouvertes'))),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _replay,
                  onChanged: (v) => setState(() => _replay = v),
                  title: Text(tr('Rediffusion publique après l\'événement')),
                  subtitle: Text(tr('L\'enregistrement est proposé sur la page du webinaire')),
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _leads,
                  onChanged: (v) => setState(() => _leads = v),
                  title: Text(tr('Créer un prospect CRM par inscrit')),
                  subtitle: Text(tr('Module CRM : source « webinaire »')),
                ),
              ],
              const SizedBox(height: 12),
              ExpansionTile(
                tilePadding: EdgeInsets.zero,
                title: Text(tr('Options de la salle'), style: TextStyle(fontWeight: FontWeight.w700)),
                children: [
                  for (final e in _optionLabels.entries)
                    if (!_webinar || !['lobby', 'guests'].contains(e.key))
                      SwitchListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        value: _opts[e.key] ?? false,
                        onChanged: (v) => setState(() => _opts[e.key] = v),
                        title: Text(tr(e.value.$1)),
                        subtitle: Text(tr(e.value.$2)),
                      ),
                ],
              ),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: _send,
                onChanged: (v) => setState(() => _send = v ?? true),
                title: Text(tr('Envoyer les invitations par e-mail (avec fichier calendrier)')),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
              const SizedBox(width: 8),
              FilledButton(onPressed: _busy ? null : _save, child: Text(tr(widget.meeting == null ? (_webinar ? 'Créer le webinaire' : 'Planifier') : 'Enregistrer'))),
            ],
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------- Fiche

class _MeetingDetail extends StatefulWidget {
  const _MeetingDetail({required this.id, required this.onJoin});

  final int id;
  final VoidCallback onJoin;

  @override
  State<_MeetingDetail> createState() => _MeetingDetailState();
}

class _MeetingDetailState extends State<_MeetingDetail> {
  Map<String, dynamic>? _d;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.get('visio/meetings/${widget.id}'));
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _act(Future<dynamic> Function() f, String ok) async {
    setState(() => _busy = true);
    try {
      final r = await f();
      if (r is Map && r['data'] != null) setState(() => _d = Map<String, dynamic>.from(r));
      if (mounted) toast(context, ok);
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _editReport(Map<String, dynamic> r) async {
    final summary = TextEditingController(text: '${r['summary'] ?? ''}');
    final decisions = TextEditingController(text: (r['decisions'] as List? ?? []).join('\n'));
    final actions = TextEditingController(text: [for (final a in (r['actions'] as List? ?? [])) '${a['title']}${a['owner'] != null ? ' | ${a['owner']}' : ''}'].join('\n'));
    final ok = await showFormDialog<bool>(
      context,
      title: tr('Modifier le compte rendu'),
      maxWidth: 720,
      builder: (c) => Column(
        children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                Field(
                  label: tr('Résumé'),
                  child: TextField(controller: summary, maxLines: 8, minLines: 4),
                ),
                Field(
                  label: tr('Décisions (une par ligne)'),
                  child: TextField(controller: decisions, maxLines: 6, minLines: 2),
                ),
                Field(
                  label: tr('Actions (une par ligne : action | responsable)'),
                  child: TextField(controller: actions, maxLines: 6, minLines: 2),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
              ],
            ),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final acts = [
      for (final l in actions.text.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty)) {'title': l.split('|').first.trim(), 'owner': l.contains('|') ? l.split('|')[1].trim() : null},
    ];
    _act(
      () => context.read<Session>().api.put('visio/meetings/${widget.id}/report', {
        'summary': summary.text.trim(),
        'decisions': decisions.text.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList(),
        'actions': acts,
      }),
      'Compte rendu mis à jour.',
    );
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) {
      return Scaffold(
        appBar: AppBar(),
        body: const Center(child: CircularProgressIndicator()),
      );
    }
    final m = asMap(d['data']);
    final report = d['report'] == null ? null : asMap(d['report']);
    final parts = [for (final p in (d['participants'] as List? ?? [])) asMap(p)];
    final recs = [for (final r in (d['recordings'] as List? ?? [])) asMap(r)];
    final webinar = m['type'] == 'webinar', ended = m['status'] == 'ended', canManage = d['can_manage'] == true;
    final api = context.read<Session>().api;
    final start = _dt(m['starts_at']);
    final hint = Theme.of(context).hintColor;
    final tabs = <(String, IconData, Widget)>[
      if (report != null) ('Compte rendu', Icons.summarize_outlined, _report(report, canManage, ended)),
      ('${webinar ? 'Inscrits' : 'Participants'} (${parts.length})', Icons.people_outline, _participants(parts, m, canManage)),
      if (recs.isNotEmpty) ('Enregistrements (${recs.length})', Icons.video_library_outlined, _recordings(recs, canManage)),
      ('Détails', Icons.info_outline, _info(m)),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: Text(tr('${m['title']}')),
          actions: [
            if (!ended && m['status'] != 'cancelled')
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: FilledButton.icon(onPressed: widget.onJoin, icon: const Icon(Icons.videocam, size: 18), label: Text(tr(m['status'] == 'live' ? 'Rejoindre' : 'Ouvrir la salle'))),
              ),
            if (m['status'] == 'live' && canManage)
              TextButton(
                onPressed: _busy ? null : () => _act(() => api.post('visio/meetings/${widget.id}/end'), 'Réunion terminée : compte rendu généré.'),
                child: Text(tr('Terminer'), style: TextStyle(color: YColors.danger)),
              ),
            if (webinar && canManage)
              IconButton(
                tooltip: tr('Exporter les inscrits (CSV)'),
                onPressed: () async {
                  final b = await api.download('visio/meetings/${widget.id}/participants.csv');
                  final where = await saveFile('inscrits-${m['slug'] ?? m['id']}.csv', b, mime: 'text/csv');
                  if (context.mounted) toast(context, tr('Export enregistré : $where'));
                },
                icon: const Icon(Icons.download),
              ),
          ],
          bottom: TabBar(
            isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
            tabAlignment: TabAlignment.start,
            tabs: [for (final t in tabs) Tab(icon: Icon(t.$2, size: 18), text: t.$1)],
          ),
        ),
        body: Column(
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
              color: Theme.of(context).colorScheme.surfaceContainerLowest,
              child: Wrap(
                spacing: 18,
                runSpacing: 6,
                children:
                    [
                          _meta(Icons.event, start == null ? '—' : DateFormat('EEEE d MMMM yyyy · HH:mm', I18n.dateLocale).format(start)),
                          _meta(Icons.timer_outlined, '${m['duration_minutes']} min prévues'),
                          if (m['host'] != null) _meta(Icons.person_outline, 'Organisée par ${m['host']}'),
                          _meta(Icons.how_to_reg, '${m['present']} présent(s) sur ${m['invited']}'),
                          if (m['provider'] != null)
                            _meta(
                              Icons.cloud_outlined,
                              'Infrastructure : ${_providerNames[m['provider']] ?? m['provider']}${(m['switches'] ?? 0) > 0 ? ' · ${m['switches']} bascule(s) automatique(s)' : ''}',
                            ),
                        ]
                        .map(
                          (w) => DefaultTextStyle.merge(
                            style: TextStyle(color: hint),
                            child: w,
                          ),
                        )
                        .toList(),
              ),
            ),
            Expanded(child: TabBarView(children: [for (final t in tabs) t.$3])),
          ],
        ),
      ),
    );
  }

  Widget _meta(IconData i, String t) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(i, size: 16, color: YColors.muted),
      const SizedBox(width: 6),
      Text(tr(t)),
    ],
  );

  Widget _report(Map<String, dynamic> r, bool canManage, bool ended) {
    final api = context.read<Session>().api;
    final stats = asMap(r['stats']);
    final decisions = (r['decisions'] as List? ?? []).cast<dynamic>();
    final actions = [for (final a in (r['actions'] as List? ?? [])) asMap(a)];
    final att = [for (final a in (r['attendance'] as List? ?? [])) asMap(a)];
    Widget stat(String l, dynamic v, IconData i, Color c) => Container(
      width: 150,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: c.withValues(alpha: .07), borderRadius: BorderRadius.circular(12)),
      child: Row(
        children: [
          Icon(i, color: c),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr('$v'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                Text(tr(l), style: const TextStyle(fontSize: 12)),
              ],
            ),
          ),
        ],
      ),
    );
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            stat('minutes', stats['duration_minutes'] ?? 0, Icons.timer_outlined, YColors.blue),
            stat('présents', stats['present'] ?? 0, Icons.people_outline, YColors.success),
            stat('taux de présence', '${stats['attendance_rate'] ?? 0} %', Icons.pie_chart_outline, _violet),
            stat('messages', stats['messages'] ?? 0, Icons.chat_bubble_outline, YColors.warning),
            if ((stats['questions'] ?? 0) > 0) stat('questions', stats['questions'], Icons.help_outline, YColors.blue),
            if ((stats['polls'] ?? 0) > 0) stat('sondages', stats['polls'], Icons.poll_outlined, _violet),
          ],
        ),
        const SizedBox(height: 14),
        if (canManage)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilledButton.icon(
                onPressed: _busy ? null : () => _act(() => api.post('visio/meetings/${widget.id}/report/send'), 'Compte rendu envoyé aux participants.'),
                icon: const Icon(Icons.send, size: 18),
                label: Text(tr(r['sent_at'] != null ? 'Renvoyer aux participants' : 'Envoyer aux participants')),
              ),
              OutlinedButton.icon(
                onPressed: _busy || !ended ? null : () => _act(() => api.post('visio/meetings/${widget.id}/report', {'ai': true}), 'Compte rendu régénéré.'),
                icon: const Icon(Icons.auto_awesome, size: 18),
                label: Text(tr('Régénérer (IA)')),
              ),
              OutlinedButton.icon(onPressed: () => _editReport(r), icon: const Icon(Icons.edit_outlined, size: 18), label: Text(tr('Modifier'))),
              OutlinedButton.icon(
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: '${r['url']}'));
                  if (mounted) toast(context, tr('Lien du compte rendu copié.'));
                },
                icon: const Icon(Icons.link, size: 18),
                label: Text(tr('Copier le lien')),
              ),
              TextButton.icon(
                onPressed: () => safeLaunchUrl(Uri.parse('${r['url']}'), webOnlyWindowName: '_blank'),
                icon: const Icon(Icons.print_outlined, size: 18),
                label: Text(tr('Version imprimable')),
              ),
            ],
          ),
        const SizedBox(height: 14),
        SectionCard(
          title: tr('Résumé'),
          trailing: r['source'] == 'ai' ? StatusChip(tr('Assistant IA'), color: _violet, icon: Icons.auto_awesome) : StatusChip(tr('Automatique'), icon: Icons.bolt),
          child: SelectableText(tr('${r['summary'] ?? ''}'), style: const TextStyle(height: 1.5)),
        ),
        if (decisions.isNotEmpty)
          SectionCard(
            title: tr('Décisions'),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final x in decisions)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.gavel, size: 18, color: YColors.success),
                    title: Text(tr('$x')),
                  ),
              ],
            ),
          ),
        if (actions.isNotEmpty)
          SectionCard(
            title: tr('Actions à mener'),
            trailing: (r['todos_created'] ?? 0) > 0 ? StatusChip(tr('${r['todos_created']} tâche(s) créée(s)'), color: YColors.blue, icon: Icons.checklist) : null,
            child: Column(
              children: [
                for (final a in actions)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.check_box_outline_blank, size: 18),
                    title: Text(tr('${a['title']}')),
                    subtitle: a['owner'] != null || a['due'] != null ? Text(tr([if (a['owner'] != null) '${a['owner']}', if (a['due'] != null) 'avant le ${dateFr(a['due'])}'].join(' · '))) : null,
                  ),
              ],
            ),
          ),
        SectionCard(
          title: tr('Présence'),
          child: Column(
            children: [
              for (final a in att)
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: CircleAvatar(
                    radius: 15,
                    backgroundColor: (a['present'] == true ? YColors.success : YColors.danger).withValues(alpha: .12),
                    child: Icon(a['present'] == true ? Icons.check : Icons.close, size: 16, color: a['present'] == true ? YColors.success : YColors.danger),
                  ),
                  title: Text(tr('${a['name']}${a['organization'] != null ? ' (${a['organization']})' : ''}')),
                  subtitle: Text(tr(_roleNames[a['role']] ?? '${a['role']}')),
                  trailing: Text(tr(a['present'] == true ? '${a['joined'] ?? ''} – ${a['left'] ?? '…'} · ${a['minutes']} min' : 'absent')),
                ),
            ],
          ),
        ),
        for (final p in (stats['polls_detail'] as List? ?? []))
          SectionCard(
            title: tr('Sondage : ${p['question']}'),
            child: Column(
              children: [
                for (final o in (p['options'] as List))
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        Expanded(flex: 3, child: Text(tr('${o['label']}'))),
                        Expanded(
                          flex: 4,
                          child: LinearProgressIndicator(
                            value: (p['voters'] ?? 0) == 0 ? 0 : (o['votes'] as num) / ((p['options'] as List).fold<num>(0, (s, x) => s + (x['votes'] as num)).clamp(1, 1 << 30)),
                            minHeight: 8,
                            borderRadius: BorderRadius.circular(4),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Text(tr('${o['votes']}')),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        if ((stats['questions_detail'] as List? ?? []).isNotEmpty)
          SectionCard(
            title: tr('Questions du public'),
            child: Column(
              children: [
                for (final q in (stats['questions_detail'] as List))
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(tr('${q['question']}')),
                    subtitle: Text(tr('${q['by'] ?? ''} · ${q['votes']} vote(s)${q['answer'] != null ? '\n↳ ${q['answer']}' : ''}')),
                  ),
              ],
            ),
          ),
        if ('${r['transcript'] ?? ''}'.isNotEmpty)
          SectionCard(
            title: tr('Transcription'),
            trailing: IconButton(
              tooltip: tr('Télécharger'),
              onPressed: () => saveFile('transcription.txt', utf8.encode('${r['transcript']}'), mime: 'text/plain'),
              icon: const Icon(Icons.download, size: 18),
            ),
            child: SelectableText(tr('${r['transcript']}'), style: const TextStyle(height: 1.5, fontSize: 13)),
          ),
      ],
    );
  }

  Widget _participants(List<Map<String, dynamic>> parts, Map<String, dynamic> m, bool canManage) {
    final api = context.read<Session>().api;
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        if (canManage && m['status'] != 'ended')
          Align(
            alignment: Alignment.centerLeft,
            child: OutlinedButton.icon(
              onPressed: () async {
                final name = TextEditingController(), email = TextEditingController();
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (c) => YAlertDialog(
                    title: Text(tr('Inviter une personne')),
                    content: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        TextField(
                          controller: name,
                          decoration: InputDecoration(labelText: tr('Nom')),
                        ),
                        const SizedBox(height: 8),
                        TextField(
                          controller: email,
                          decoration: InputDecoration(labelText: tr('E-mail')),
                        ),
                      ],
                    ),
                    actions: [
                      TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                      FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Inviter'))),
                    ],
                  ),
                );
                if (ok == true) {
                  _act(
                    () => api.post('visio/meetings/${widget.id}/invite', {
                      'guests': [
                        {'name': name.text.trim(), 'email': email.text.trim(), if (m['type'] == 'webinar') 'role': 'panelist'},
                      ],
                    }),
                    'Invitation envoyée.',
                  );
                }
              },
              icon: const Icon(Icons.person_add_alt, size: 18),
              label: Text(tr(m['type'] == 'webinar' ? 'Ajouter un intervenant' : 'Inviter une personne')),
            ),
          ),
        const SizedBox(height: 8),
        for (final p in parts)
          Card(
            child: ListTile(
              leading: CircleAvatar(
                backgroundColor: (p['online'] == true ? YColors.success : YColors.blue).withValues(alpha: .12),
                child: Text(
                  tr('${p['name']}'.isEmpty ? '?' : '${p['name']}'[0].toUpperCase()),
                  style: TextStyle(color: p['online'] == true ? YColors.success : YColors.blue, fontWeight: FontWeight.w800),
                ),
              ),
              title: Text(tr('${p['name']}${p['organization'] != null ? ' · ${p['organization']}' : ''}')),
              subtitle: Text(
                tr(
                  [
                    _roleNames[p['role']] ?? '${p['role']}',
                    if (p['email'] != null) '${p['email']}',
                    if (p['online'] == true) 'connecté',
                    if (p['joined'] != null) 'présent ${p['minutes']} min' else if (m['status'] == 'ended') 'absent',
                    if (p['source'] != null) 'via ${p['source']}',
                  ].join(' · '),
                ),
              ),
              trailing: canManage && p['role'] != 'host'
                  ? PopupMenuButton<String>(
                      onSelected: (v) async {
                        if (v == 'copy') {
                          await Clipboard.setData(ClipboardData(text: '${p['link']}'));
                          if (mounted) toast(context, tr('Lien personnel copié.'));
                        } else if (v == 'resend') {
                          _act(
                            () => api.post('visio/meetings/${widget.id}/invite', {
                              'resend': [p['id']],
                            }),
                            'Invitation renvoyée.',
                          );
                        } else if (v == 'remove') {
                          _act(() => api.delete('visio/meetings/${widget.id}/participants/${p['id']}'), 'Participant retiré.');
                        }
                      },
                      itemBuilder: (_) => [
                        PopupMenuItem(value: 'copy', child: Text(tr('Copier son lien personnel'))),
                        if (p['email'] != null) PopupMenuItem(value: 'resend', child: Text(tr('Renvoyer l\'invitation'))),
                        PopupMenuItem(
                          value: 'remove',
                          child: Text(tr('Retirer'), style: TextStyle(color: YColors.danger)),
                        ),
                      ],
                    )
                  : null,
            ),
          ),
      ],
    );
  }

  Widget _recordings(List<Map<String, dynamic>> recs, bool canManage) {
    final api = context.read<Session>().api;
    String size(int b) => b > 1048576 ? '${(b / 1048576).toStringAsFixed(1)} Mo' : '${(b / 1024).round()} Ko';
    String dur(int s) => '${s ~/ 60} min ${(s % 60).toString().padLeft(2, '0')} s';
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        for (final r in recs)
          Card(
            child: ListTile(
              leading: const CircleAvatar(
                backgroundColor: Color(0x1FD93025),
                child: Icon(Icons.movie_outlined, color: YColors.danger),
              ),
              title: Text(tr('Enregistrement du ${dateTimeFr(r['created_at'])}')),
              subtitle: Text(
                tr(
                  '${dur((r['duration'] as num?)?.toInt() ?? 0)} · ${size((r['size'] as num?)?.toInt() ?? 0)} · ${r['mime'] == 'video/mp4' ? 'MP4' : 'WebM'}${r['public_url'] != null ? ' · rediffusion publique' : ''}',
                ),
              ),
              trailing: Wrap(
                children: [
                  IconButton(
                    tooltip: tr('Télécharger'),
                    onPressed: () async {
                      toast(context, tr('Téléchargement…'));
                      final b = await api.download('visio/recordings/${r['id']}', query: {'download': 1});
                      final where = await saveFile('reunion-${r['id']}.${r['mime'] == 'video/mp4' ? 'mp4' : 'webm'}', b, mime: '${r['mime']}');
                      if (mounted) toast(context, tr('Enregistré : $where'));
                    },
                    icon: const Icon(Icons.download),
                  ),
                  if (r['public_url'] != null)
                    IconButton(
                      tooltip: tr('Page de rediffusion'),
                      onPressed: () => safeLaunchUrl(Uri.parse('${r['public_url']}'), webOnlyWindowName: '_blank'),
                      icon: const Icon(Icons.public),
                    ),
                  if (canManage)
                    IconButton(
                      tooltip: tr('Supprimer'),
                      onPressed: () async {
                        if (await confirm(context, tr('Supprimer l\'enregistrement ?'), tr('Le fichier vidéo sera définitivement supprimé.'), danger: true, ok: tr('Supprimer'))) {
                          _act(() => api.delete('visio/recordings/${r['id']}'), 'Enregistrement supprimé.');
                        }
                      },
                      icon: const Icon(Icons.delete_outline),
                    ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _info(Map<String, dynamic> m) {
    final opts = asMap(m['options']);
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        if ('${m['description'] ?? ''}'.isNotEmpty) SectionCard(title: m['type'] == 'webinar' ? 'Présentation' : 'Ordre du jour', child: Text(tr('${m['description']}'))),
        SectionCard(
          title: tr('Accès'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _copyRow('Lien de la salle', '${m['url']}'),
              if (m['registration_url'] != null) _copyRow('Page d\'inscription', '${m['registration_url']}'),
              if (m['password'] != null) _copyRow('Code d\'accès', '${m['password']}'),
              _copyRow('Code de la réunion', '${m['code']}'),
            ],
          ),
        ),
        SectionCard(
          title: tr('Options'),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final e in _optionLabels.entries) StatusChip(e.value.$1, color: opts[e.key] == true ? YColors.success : YColors.muted, icon: opts[e.key] == true ? Icons.check : Icons.close),
            ],
          ),
        ),
      ],
    );
  }

  Widget _copyRow(String label, String value) => ListTile(
    dense: true,
    contentPadding: EdgeInsets.zero,
    title: Text(tr(label), style: const TextStyle(fontSize: 12)),
    subtitle: SelectableText(tr(value), style: const TextStyle(fontSize: 14)),
    trailing: IconButton(
      icon: const Icon(Icons.copy, size: 18),
      onPressed: () async {
        await Clipboard.setData(ClipboardData(text: value));
        if (mounted) toast(context, tr('Copié.'));
      },
    ),
  );
}

// ---------------------------------------------------------------------------- Paramètres

class _SettingsForm extends StatefulWidget {
  const _SettingsForm();

  @override
  State<_SettingsForm> createState() => _SettingsFormState();
}

class _SettingsFormState extends State<_SettingsForm> {
  Map<String, dynamic>? _s;
  final _c = <String, TextEditingController>{};
  List<String> _priority = ['zego', 'agora', 'p2p'];
  bool _p2p = true, _busy = false;
  Map<String, bool> _defaults = {};

  TextEditingController _t(String k) => _c.putIfAbsent(k, () => TextEditingController());

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final s = Map<String, dynamic>.from(await context.read<Session>().api.get('visio/settings'));
      if (!mounted) return;
      setState(() {
        _s = s;
        _priority = [for (final p in (s['priority'] as List)) '$p'];
        _p2p = s['p2p_fallback'] == true;
        _defaults = {for (final e in (s['defaults'] as Map).entries) '${e.key}': e.value == true};
        _t('zego_app_id').text = '${s['zego_app_id'] ?? ''}';
        _t('zego_secret').text = '${s['zego_secret_hint'] ?? ''}';
        _t('zego_server').text = '${s['zego_server'] ?? ''}';
        _t('agora_app_id').text = '${s['agora_app_id'] ?? ''}';
        _t('agora_certificate').text = '${s['agora_certificate_hint'] ?? ''}';
        _t('zego_budget_minutes').text = '${s['zego_budget_minutes'] ?? 0}';
        _t('agora_budget_minutes').text = '${s['agora_budget_minutes'] ?? 0}';
        _t('turn_url').text = '${s['turn_url'] ?? ''}';
        _t('turn_username').text = '${s['turn_username'] ?? ''}';
      });
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _save() async {
    final sAdmin = _s?['can_configure_providers'] == true;
    setState(() => _busy = true);
    try {
      final body = sAdmin
          ? <String, dynamic>{
              for (final k in ['zego_app_id', 'zego_server', 'agora_app_id', 'turn_url', 'turn_username']) k: _t(k).text.trim(),
              for (final k in ['zego_secret', 'agora_certificate', 'turn_credential'])
                if (!_t(k).text.startsWith('••••')) k: _t(k).text.trim(),
              'zego_budget_minutes': int.tryParse(_t('zego_budget_minutes').text) ?? 0,
              'agora_budget_minutes': int.tryParse(_t('agora_budget_minutes').text) ?? 0,
              'priority': _priority,
              'p2p_fallback': _p2p,
              'defaults': _defaults,
            }
          : <String, dynamic>{'defaults': _defaults};
      await context.read<Session>().api.put('visio/settings', body);
      if (!mounted) return;
      toast(context, tr('Paramètres enregistrés.'));
      Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _test(String p) async {
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.post('visio/settings/test/$p'));
      if (mounted) toast(context, '${r['message']}', error: r['ok'] != true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    if (s == null) return const Center(child: CircularProgressIndicator());
    final providers = {for (final p in (s['providers'] as List)) '${p['key']}': asMap(p)};
    final platform = asMap(s['platform']);
    final hint = Theme.of(context).hintColor;
    Widget status(String k) {
      final p = providers[k]!;
      return Wrap(
        spacing: 6,
        runSpacing: 4,
        children: [
          if (k == 'p2p')
            StatusChip(p['configured'] == true ? 'Activé' : 'Désactivé', color: p['configured'] == true ? YColors.success : YColors.muted, icon: Icons.hub_outlined)
          else if (p['configured'] == true)
            StatusChip(p['own'] == true ? 'Votre compte' : 'Compte de la plateforme', color: YColors.success, icon: Icons.check_circle_outline)
          else
            StatusChip(tr('Non configuré'), color: YColors.warning),
          if (p['down'] == true) StatusChip(tr('Mis à l\'écart jusqu\'à ${DateFormat('HH:mm').format(DateTime.parse('${p['down_until']}'))}'), color: YColors.danger, icon: Icons.warning_amber),
          if (k != 'p2p') StatusChip(tr('${p['minutes']} min ce mois${(p['budget'] ?? 0) > 0 ? ' / ${p['budget']}' : ''}'), icon: Icons.timer_outlined),
        ],
      );
    }

    Widget providerCard(String k) {
      final p = providers[k]!;
      final fields = switch (k) {
        'zego' => [
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _t('zego_app_id'),
                  decoration: InputDecoration(labelText: tr('AppID')),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                flex: 2,
                child: TextField(
                  controller: _t('zego_secret'),
                  decoration: InputDecoration(labelText: tr('ServerSecret')),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                flex: 2,
                child: TextField(
                  controller: _t('zego_server'),
                  decoration: InputDecoration(labelText: tr('Serveur (facultatif)'), hintText: tr('wss://webliveroom…-api.zegocloud.com/ws')),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: TextField(
                  controller: _t('zego_budget_minutes'),
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: tr('Quota mensuel (min, 0 = ∞)')),
                ),
              ),
            ],
          ),
        ],
        'agora' => [
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _t('agora_app_id'),
                  decoration: InputDecoration(labelText: tr('App ID')),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: TextField(
                  controller: _t('agora_certificate'),
                  decoration: InputDecoration(labelText: tr('App Certificate')),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _t('agora_budget_minutes'),
            keyboardType: TextInputType.number,
            decoration: InputDecoration(labelText: tr('Quota mensuel (min, 0 = ∞)')),
          ),
        ],
        _ => [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _p2p,
            onChanged: (v) => setState(() => _p2p = v),
            title: Text(tr('Mode de secours pair-à-pair')),
            subtitle: Text(tr('Si les deux infrastructures sont indisponibles : connexion directe entre navigateurs (jusqu\'à 6 personnes), sans coût.')),
          ),
          ExpansionTile(
            tilePadding: EdgeInsets.zero,
            title: Text(tr('Relais TURN (réseaux d\'entreprise filtrés)')),
            children: [
              TextField(
                controller: _t('turn_url'),
                decoration: InputDecoration(labelText: tr('Adresse'), hintText: tr('turn:turn.exemple.com:3478')),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _t('turn_username'),
                      decoration: InputDecoration(labelText: tr('Utilisateur')),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: TextField(
                      controller: _t('turn_credential'),
                      decoration: InputDecoration(labelText: tr('Mot de passe')),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ],
      };
      return Card(
        key: ValueKey(k),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  ReorderableDragStartListener(
                    index: _priority.indexOf(k),
                    child: const Padding(
                      padding: EdgeInsets.only(right: 8),
                      child: Icon(Icons.drag_indicator, color: YColors.muted),
                    ),
                  ),
                  CircleAvatar(
                    radius: 12,
                    backgroundColor: YColors.blue.withValues(alpha: .12),
                    child: Text(
                      tr('${_priority.indexOf(k) + 1}'),
                      style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: YColors.blue),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(tr(_providerNames[k]!), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  ),
                  if (k != 'p2p' && p['configured'] == true) TextButton(onPressed: () => _test(k), child: Text(tr('Tester'))),
                  if (p['down'] == true)
                    TextButton(
                      onPressed: () async {
                        await context.read<Session>().api.post('visio/settings/reset/$k');
                        _load();
                      },
                      child: Text(tr('Réactiver')),
                    ),
                ],
              ),
              const SizedBox(height: 6),
              status(k),
              if (k != 'p2p' && (platform[k] == true) && p['own'] != true)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(tr('Le compte de la plateforme est utilisé tant que vous ne saisissez pas le vôtre.'), style: TextStyle(color: hint, fontSize: 12)),
                ),
              if (p['last_reason'] != null && p['failures'] > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(tr('Dernier incident : ${p['last_reason']}'), style: const TextStyle(color: YColors.danger, fontSize: 12)),
                ),
              const SizedBox(height: 10),
              ...fields,
            ],
          ),
        ),
      );
    }

    final admin = s['can_configure_providers'] == true;
    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              if (s['quota'] is Map) ...[YomeetQuotaBar(quota: Map<String, dynamic>.from(s['quota'] as Map)), const SizedBox(height: 16)],
              if (admin) ...[
                Text(tr('Infrastructure de la plateforme (réservé à l\'éditeur)'), style: const TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .06), borderRadius: BorderRadius.circular(12)),
                  child: Row(
                    children: [
                      const Icon(Icons.swap_horiz, color: YColors.blue),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          tr(
                            'Bascule automatique : si le premier fournisseur refuse la connexion (crédit épuisé, quota atteint) ou devient instable, la salle passe au suivant en quelques secondes, sans que les participants aient à faire quoi que ce soit. ${s['switches_month'] ?? 0} bascule(s) ce mois-ci. Glissez les cartes pour changer l\'ordre.',
                          ),
                          style: const TextStyle(height: 1.4),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                ReorderableListView(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  buildDefaultDragHandles: false,
                  onReorderItem: (a, b) => setState(() => _priority.insert(b, _priority.removeAt(a))),
                  children: [for (final k in _priority) providerCard(k)],
                ),
                const SizedBox(height: 12),
              ],
              Text(tr('Réglages par défaut des nouvelles réunions'), style: TextStyle(fontWeight: FontWeight.w700)),
              for (final e in _optionLabels.entries)
                SwitchListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  value: _defaults[e.key] ?? false,
                  onChanged: (v) => setState(() => _defaults[e.key] = v),
                  title: Text(tr(e.value.$1)),
                  subtitle: Text(tr(e.value.$2)),
                ),
              const SizedBox(height: 8),
              if (admin)
                Text(
                  tr(
                    'Créez vos comptes sur console.zegocloud.com et console.agora.io (projets « avec jeton »). Les secrets restent sur votre serveur : les navigateurs ne reçoivent que des jetons temporaires.',
                  ),
                  style: TextStyle(color: hint, fontSize: 12),
                ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
              const SizedBox(width: 8),
              FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
            ],
          ),
        ),
      ],
    );
  }
}
