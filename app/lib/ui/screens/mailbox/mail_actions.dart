import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../theme.dart';
import '../../widgets/common.dart' show showFormDialog;
import '../../../i18n/i18n.dart';
import '../../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Bandeau « intelligent » sous l'en-tête d'un e-mail : invitation reçue, dates citées (cliquables pour planifier
/// une réunion ou un webinaire à ce moment-là), et actions suggérées selon la nature du message.
class MailActionsBar extends StatelessWidget {
  const MailActionsBar({super.key, required this.message, required this.onAction, required this.onAcceptInvite});

  final Map<String, dynamic> message;
  final void Function(String action, [String? start]) onAction;
  final VoidCallback onAcceptInvite;

  static const labels = {
    'meeting': 'Planifier une réunion (Teams, Meet, présentiel)', 'visio': 'Planifier une visio Yomara', 'webinar': 'Programmer un webinaire', 'quote': 'Préparer un devis',
    'ticket': 'Créer un ticket du service client', 'lead': 'Créer une opportunité CRM', 'funds': 'Créer un appel de fonds', 'task': 'Créer une tâche (Ma journée)',
  };

  static const short = {'meeting': 'Planifier une réunion', 'visio': 'Visio', 'webinar': 'Webinaire', 'quote': 'Préparer un devis', 'ticket': 'Créer un ticket', 'lead': 'Opportunité CRM',
    'funds': 'Appel de fonds', 'task': 'Tâche'};

  static const icons = {
    'meeting': Icons.event_available_outlined, 'visio': Icons.videocam_outlined, 'webinar': Icons.podcasts_outlined, 'quote': Icons.request_quote_outlined,
    'ticket': Icons.support_agent, 'lead': Icons.trending_up, 'funds': Icons.account_balance_wallet_outlined, 'task': Icons.task_alt,
  };

  static List<String> allActions(Map<String, dynamic> m) {
    final a = [for (final x in (m['actions'] as List? ?? const ['task'])) '$x'];
    return [for (final k in labels.keys) if (a.contains(k)) k];
  }

  @override
  Widget build(BuildContext context) {
    final ins = message['insights'] as Map?;
    if (ins == null) return const SizedBox();
    final actions = allActions(message);
    final dates = [for (final d in (ins['dates'] as List? ?? const [])) asMap(d)];
    final invite = ins['invite'] as Map?;
    final link = ins['meeting_link'] as Map?;
    final suggested = [for (final i in (ins['intents'] as List? ?? const [])) if (actions.contains(i)) '$i'].take(3).toList();
    if (dates.isEmpty && invite == null && link == null && suggested.isEmpty) return const SizedBox();
    final hint = Theme.of(context).hintColor;
    final canMeet = actions.contains('meeting') || actions.contains('visio');
    final fmt = DateFormat('EEE d MMM', 'fr_FR');

    return Container(
      margin: const EdgeInsets.fromLTRB(20, 2, 20, 12),
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      decoration: BoxDecoration(
        color: const Color(0xFFF5F8FF),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFDCE6FB)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (invite != null) ...[
          Row(children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
              child: const Icon(Icons.event, color: YColors.blue),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('Invitation : ${invite['summary']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                Text(
                  tr([
                    _when(invite['start'], invite['end'], allDay: invite['all_day'] == true),
                    if ((invite['location'] ?? '').toString().isNotEmpty) '${invite['location']}',
                    if ((invite['organizer'] as Map?)?['name'] != null) 'de ${(invite['organizer'] as Map)['name']}',
                  ].join(' · ')),
                  style: TextStyle(color: hint, fontSize: 12.5),
                ),
              ]),
            ),
            if (invite['link'] != null)
              TextButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${invite['link']}'), mode: LaunchMode.externalApplication), icon: const Icon(Icons.videocam_outlined, size: 18), label: Text(tr('Rejoindre'))),
            FilledButton.icon(onPressed: onAcceptInvite, icon: const Icon(Icons.event_available, size: 18), label: Text(tr('Ajouter à mon agenda'))),
          ]),
          if (dates.isNotEmpty || suggested.isNotEmpty) const Divider(height: 18),
        ] else if (link != null) ...[
          Row(children: [
            Icon(Icons.videocam_outlined, size: 18, color: hint),
            const SizedBox(width: 6),
            Expanded(child: Text(tr('Lien de réunion ${link['provider'] == 'teams' ? 'Teams' : (link['provider'] == 'meet' ? 'Google Meet' : 'Zoom')} dans ce message'), style: TextStyle(color: hint, fontSize: 12.5))),
            TextButton(onPressed: () => safeLaunchUrl(Uri.parse('${link['url']}'), mode: LaunchMode.externalApplication), child: Text(tr('Rejoindre'))),
          ]),
        ],
        if (dates.isNotEmpty)
          Wrap(spacing: 6, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            Text(tr(canMeet ? 'Planifier à :' : 'Dates citées :'), style: TextStyle(color: hint, fontSize: 12.5, fontWeight: FontWeight.w600)),
            for (final d in dates)
              Tooltip(
                message: '« ${d['text']} »',
                child: ActionChip(
                  avatar: const Icon(Icons.calendar_month, size: 16, color: YColors.blue),
                  label: Text(tr('${fmt.format(DateTime.parse('${d['start']}').toLocal())}${d['has_time'] == true ? ' · ${DateFormat('HH:mm').format(DateTime.parse('${d['start']}').toLocal())}' : ''}')),
                  onPressed: canMeet || actions.contains('webinar') ? () => onAction(actions.contains('meeting') ? 'meeting' : (actions.contains('visio') ? 'visio' : 'webinar'), '${d['start']}') : null,
                ),
              ),
          ]),
        if (suggested.isNotEmpty) ...[
          if (dates.isNotEmpty) const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            Icon(Icons.auto_awesome, size: 16, color: YColors.blue.withValues(alpha: .8)),
            Text(tr('Suggestions :'), style: TextStyle(color: hint, fontSize: 12.5, fontWeight: FontWeight.w600)),
            for (final s in suggested)
              FilledButton.tonalIcon(
                style: FilledButton.styleFrom(visualDensity: VisualDensity.compact, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8)),
                onPressed: () => onAction(s, dates.isNotEmpty ? '${dates.first['start']}' : null),
                icon: Icon(icons[s], size: 17),
                label: Text(tr(short[s]!)),
              ),
          ]),
        ],
      ]),
    );
  }

  static String _when(dynamic start, dynamic end, {bool allDay = false}) {
    final s = DateTime.tryParse('${start ?? ''}')?.toLocal();
    final e = DateTime.tryParse('${end ?? ''}')?.toLocal();
    if (s == null) return '';
    final d = DateFormat('EEEE d MMMM', 'fr_FR').format(s);
    return allDay ? d : '$d, ${DateFormat('HH:mm').format(s)}${e != null ? ' – ${DateFormat('HH:mm').format(e)}' : ''}';
  }
}

/// Dialogue « Planifier » depuis un e-mail : date et heure (pré-remplies), durée, type (Teams, Meet, visio Yomara,
/// présentiel, webinaire), titre, lieu et invités (l'expéditeur et les autres destinataires).
Future<Map<String, dynamic>?> showMeetingFromMailDialog(BuildContext context, Map<String, dynamic> m, {String initialType = 'meeting', String? start}) =>
    showFormDialog<Map<String, dynamic>>(context, title: initialType == 'webinar' ? 'Programmer un webinaire' : 'Planifier une réunion', maxWidth: 728, builder: (_) => _MeetingDialog(message: m, initialType: initialType, start: start));

class _MeetingDialog extends StatefulWidget {
  const _MeetingDialog({required this.message, required this.initialType, this.start});

  final Map<String, dynamic> message;
  final String initialType;
  final String? start;

  @override
  State<_MeetingDialog> createState() => _MeetingDialogState();
}

class _MeetingDialogState extends State<_MeetingDialog> {
  late DateTime _at;
  int _duration = 60;
  late String _kind; // teams, meet, visio, onsite, webinar
  late final _title = TextEditingController(text: _subject());
  final _location = TextEditingController();
  final _addGuest = TextEditingController();
  late final List<Map<String, dynamic>> _guests = _initialGuests();
  bool _invite = true;

  List<String> get _actions => MailActionsBar.allActions(widget.message);

  String _subject() {
    final s = '${widget.message['subject'] ?? 'Réunion'}'.replaceFirst(RegExp(r'^\s*((re|tr|fw|fwd)\s*:\s*)+', caseSensitive: false), '');
    return s.isEmpty ? 'Réunion' : s;
  }

  List<Map<String, dynamic>> _initialGuests() {
    final out = <String, Map<String, dynamic>>{};
    void add(dynamic p) {
      final e = '${(p as Map?)?['email'] ?? ''}'.toLowerCase();
      if (e.isNotEmpty && !out.containsKey(e)) out[e] = {'email': e, 'name': p?['name']};
    }

    add({'email': widget.message['from_email'], 'name': widget.message['from_name']});
    for (final t in (widget.message['to'] as List? ?? const [])) {
      add(t);
    }
    for (final t in (widget.message['cc'] as List? ?? const [])) {
      add(t);
    }
    return out.values.toList();
  }

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _at = DateTime.tryParse(widget.start ?? '')?.toLocal() ?? DateTime(now.year, now.month, now.day + 1, 10);
    _kind = widget.initialType == 'webinar' ? 'webinar' : (widget.initialType == 'visio' ? 'visio' : (_actions.contains('meeting') ? 'teams' : 'visio'));
    if (_kind == 'webinar') _duration = 90;
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final kinds = [
      if (_actions.contains('meeting')) ('teams', 'Teams', Icons.groups_2_outlined),
      if (_actions.contains('meeting')) ('meet', 'Meet', Icons.video_call_outlined),
      if (_actions.contains('visio')) ('visio', 'Visio Yomara', Icons.videocam_outlined),
      if (_actions.contains('meeting')) ('onsite', 'Présentiel', Icons.location_on_outlined),
      if (_actions.contains('webinar')) ('webinar', 'Webinaire', Icons.podcasts_outlined),
    ];
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: SegmentedButton<String>(
              showSelectedIcon: false,
              segments: [for (final k in kinds) ButtonSegment(value: k.$1, icon: Icon(k.$3, size: 17), label: Text(tr(k.$2)))],
              selected: {_kind},
              onSelectionChanged: (v) => setState(() {
                _kind = v.first;
                if (_kind == 'webinar' && _duration < 90) _duration = 90;
              }),
            ),
          ),
          const SizedBox(height: 12),
          TextField(controller: _title, decoration: InputDecoration(labelText: tr('Titre'))),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await showDatePicker(context: context, initialDate: _at, firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime.now().add(const Duration(days: 730)));
                  if (d != null) setState(() => _at = DateTime(d.year, d.month, d.day, _at.hour, _at.minute));
                },
                icon: const Icon(Icons.calendar_month, size: 18),
                label: Text(tr(DateFormat('EEE d MMM y', 'fr_FR').format(_at))),
              ),
            ),
            const SizedBox(width: 8),
            OutlinedButton.icon(
              onPressed: () async {
                final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_at));
                if (t != null) setState(() => _at = DateTime(_at.year, _at.month, _at.day, t.hour, t.minute));
              },
              icon: const Icon(Icons.schedule, size: 18),
              label: Text(tr(DateFormat('HH:mm').format(_at))),
            ),
            const SizedBox(width: 8),
            DropdownButton<int>(
              value: _duration,
              underline: const SizedBox(),
              items: [for (final d in const [15, 30, 45, 60, 90, 120, 180]) DropdownMenuItem(value: d, child: Text(tr(d < 60 ? '$d min' : '${d ~/ 60} h${d % 60 == 0 ? '' : ' ${d % 60}'}')))],
              onChanged: (v) => setState(() => _duration = v ?? 60),
            ),
          ]),
          if (_kind == 'onsite') ...[const SizedBox(height: 10), TextField(controller: _location, decoration: InputDecoration(labelText: tr('Lieu'), hintText: tr('Nos bureaux, Plateau…')))],
          if (_kind != 'webinar') ...[
            const SizedBox(height: 12),
            Text(tr('Invités'), style: TextStyle(color: hint, fontWeight: FontWeight.w600, fontSize: 12.5)),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final g in _guests)
                InputChip(
                  label: Text(tr((g['name'] ?? '').toString().isNotEmpty ? '${g['name']}' : '${g['email']}')),
                  tooltip: trn('${g['email']}'),
                  onDeleted: () => setState(() => _guests.remove(g)),
                ),
              SizedBox(
                width: 220,
                child: TextField(
                  controller: _addGuest,
                  decoration: InputDecoration(isDense: true, hintText: tr('Ajouter une adresse'), prefixIcon: Icon(Icons.person_add_alt, size: 18)),
                  onSubmitted: (v) {
                    final e = v.trim().toLowerCase();
                    if (RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(e)) setState(() => _guests.add({'email': e}));
                    _addGuest.clear();
                  },
                ),
              ),
            ]),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              value: _invite,
              onChanged: (v) => setState(() => _invite = v),
              title: Text(tr('Envoyer les invitations')),
              subtitle: Text(tr(_kind == 'teams' || _kind == 'meet' ? 'Par ${_kind == 'teams' ? 'Outlook (lien Teams)' : 'Google (lien Meet)'} si votre compte est branché, sinon par e-mail' : 'Par e-mail, avec le fichier d\'agenda')),
            ),
          ] else
            Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('Le webinaire est créé avec sa page d\'inscription à partager.'), style: TextStyle(color: hint, fontSize: 12.5))),
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
            FilledButton.icon(
              onPressed: () => Navigator.pop(context, {
                'type': _kind == 'webinar' ? 'webinar' : (_kind == 'visio' ? 'visio' : 'meeting'),
                if (_kind == 'teams' || _kind == 'meet' || _kind == 'onsite') 'provider': _kind,
                'title': _title.text.trim(),
                'start': _at.toUtc().toIso8601String(),
                'duration': _duration,
                if (_kind == 'onsite' && _location.text.trim().isNotEmpty) 'location': _location.text.trim(),
                if (_kind != 'webinar') 'guests': _guests,
                'send_invites': _invite,
              }),
              icon: const Icon(Icons.event_available, size: 18),
              label: Text(tr('Planifier')),
            ),
          ]),
        ),
      ]),
    );
  }
}
