import 'dart:math' as math;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ======================================================================= Inscriptions

/// Couleurs proposées pour les bornes d'accueil.
const kioskColorPresets = {
  '#0B1B3A': 'Bleu nuit',
  '#0E6B4F': 'Vert',
  '#7A1F2B': 'Bordeaux',
  '#B35A00': 'Orange brûlé',
  '#1F4FB8': 'Bleu roi',
  '#4B2E83': 'Violet',
  '#1F2937': 'Anthracite',
};

const regQuestionTypes = {'text': 'Texte court', 'textarea': 'Texte long', 'select': 'Choix dans une liste', 'checkbox': 'Case à cocher', 'phone': 'Téléphone', 'number': 'Nombre'};

String regSourceLabel(String s) => const {'facebook': 'Facebook', 'whatsapp': 'WhatsApp', 'instagram': 'Instagram', 'linkedin': 'LinkedIn', 'tiktok': 'TikTok', 'google': 'Google', 'email': 'E-mail', 'sms': 'SMS'}[s.toLowerCase()] ?? s;

Map<String, dynamic> regForm(Map<String, dynamic> event) => {
      'ask_email': 'required',
      'ask_company': true,
      'ask_job': false,
      'questions': <dynamic>[],
      'consent_text': 'J\'accepte de recevoir les informations de {societe} (prochains événements, offres).',
      'confirmation_message': 'Votre inscription est confirmée. Présentez le QR code de votre billet à l\'entrée.',
      'global_list': true,
      'ask_city': 'optional',
      'ask_country': 'off',
      'default_country': 'Côte d\'Ivoire',
      'kiosk_welcome': 'Bienvenue ! Inscrivez-vous en une minute pour entrer au salon.',
      'kiosk_type_id': null,
      'kiosk_color': null,
      'kiosk_logo': true,
      ...?(event['registration'] is Map ? asMap(event['registration']) : null),
    };

/// Onglet « Inscriptions » : formulaire, analyse des inscrits, avis, listes Marketing.
class RegistrationsTab extends StatefulWidget {
  const RegistrationsTab({super.key, required this.event});

  final Map<String, dynamic> event;

  @override
  State<RegistrationsTab> createState() => _RegistrationsTabState();
}

class _RegistrationsTabState extends State<RegistrationsTab> {
  Map<String, dynamic>? _a;
  Object? _error;
  bool _asking = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('billetterie/events/${widget.event['id']}/analytics');
      if (mounted) setState(() => (_a = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _askFeedback() async {
    if (!await confirm(context, tr('Demander l\'avis des participants ?'), tr('Un e-mail (ou WhatsApp) avec une note de 1 à 5 étoiles est envoyé une fois à chaque participant. Cela se fait aussi automatiquement le lendemain de l\'événement.'), ok: tr('Envoyer')) || !mounted) return;
    setState(() => _asking = true);
    final api = context.read<Session>().api;
    try {
      final r = await api.post('billetterie/events/${widget.event['id']}/feedback-request');
      if (!mounted) return;
      toast(context, tr('${r['data']?['sent'] ?? 0} demande(s) d\'avis envoyée(s).'));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _asking = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final e = widget.event;
    final hint = Theme.of(context).hintColor;
    final narrow = MediaQuery.of(context).size.width < 700;
    final manage = session.can('billetterie.manage');
    final actions = Wrap(spacing: 8, runSpacing: 8, children: [
      if (manage) FilledButton.icon(onPressed: () => editRegistrationForm(context, e).then((_) => _load()), icon: const Icon(Icons.edit_note), label: Text(tr('Formulaire d\'inscription'))),
      if (manage && _a != null && _a!['feedback']?['requested_at'] == null && '${e['end_date'] ?? ''}'.compareTo(DateTime.now().toIso8601String().substring(0, 10)) < 0)
        OutlinedButton.icon(onPressed: _asking ? null : _askFeedback, icon: const Icon(Icons.star_outline), label: Text(tr('Demander les avis'))),
      if (e['ticketing_url'] != null) OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${e['ticketing_url']}'), mode: LaunchMode.externalApplication), icon: const Icon(Icons.open_in_new), label: Text(tr('Page d\'inscription'))),
      if (e['public_token'] != null && e['public_enabled'] == true && session.hasModule('evenements'))
        OutlinedButton.icon(onPressed: () => _share(context, 'Annuaire des exposants', '${e['public_url']}/exposants'), icon: const Icon(Icons.storefront_outlined), label: Text(tr('Annuaire exposants'))),
      IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
    ]);
    if (_error != null && _a == null) {
      return ListView(padding: const EdgeInsets.all(16), children: [actions, const SizedBox(height: 16), OnlineOnlyNotice(error: _error!, onRetry: _load)]);
    }
    final a = _a;
    if (a == null) return const LoadingSkeleton();
    final fb = asMap(a['feedback']);
    final stars = Map<String, dynamic>.from(fb['stars'] is Map ? fb['stars'] as Map : {});
    final byDay = List<Map<String, dynamic>>.from(((a['by_day'] as List?) ?? const []).map((x) => asMap(x)));
    final sources = List<Map<String, dynamic>>.from(((a['sources'] as List?) ?? const []).map((x) => asMap(x)));
    final answers = List<Map<String, dynamic>>.from(((a['answers'] as List?) ?? const []).map((x) => asMap(x)));
    final companies = List<Map<String, dynamic>>.from(((a['top_companies'] as List?) ?? const []).map((x) => asMap(x)));
    final comments = List<Map<String, dynamic>>.from(((fb['comments'] as List?) ?? const []).map((x) => asMap(x)));
    final hours = Map<String, dynamic>.from(a['checkins_by_hour'] is Map ? a['checkins_by_hour'] as Map : {});
    Widget kpi(int i, String label, double v, IconData icon, Color c, {String Function(double)? fmt, String? sub}) => SizedBox(
          width: narrow ? (MediaQuery.of(context).size.width - 42) / 2 : 210,
          child: AnimatedFadeIn(
            index: i,
            child: HoverLift(
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Container(padding: const EdgeInsets.all(7), decoration: BoxDecoration(color: c.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)), child: Icon(icon, color: c, size: 19)),
                      const Spacer(),
                      if (sub != null) Text(tr(sub), style: TextStyle(color: hint, fontSize: 12)),
                    ]),
                    const SizedBox(height: 10),
                    CountUp(value: v, format: fmt ?? (x) => '${x.round()}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
                    Text(tr(label), style: TextStyle(color: hint, fontSize: 12.5)),
                  ]),
                ),
              ),
            ),
          ),
        );
    final regs = toDouble(a['registrations']);
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: EdgeInsets.all(narrow ? 12 : 20), children: [
        actions,
        const SizedBox(height: 14),
        Wrap(spacing: 10, runSpacing: 10, children: [
          kpi(0, 'Inscriptions', regs, Icons.how_to_reg_outlined, YColors.blue, sub: '${a['tickets']} billet(s)'),
          kpi(1, 'Gratuites / payées', toDouble(a['free']), Icons.card_giftcard, YColors.success, sub: '${a['paid']} payée(s)'),
          kpi(2, 'Taux de présence', toDouble(a['attendance_rate'] ?? 0), Icons.qr_code_scanner, YColors.warning, fmt: (x) => a['attendance_rate'] == null ? '—' : '${x.toStringAsFixed(0)} %', sub: '${a['checked_in']} entrés'),
          kpi(3, 'Contacts dans la liste', toDouble(a['list_contacts']), Icons.contacts_outlined, YColors.night, sub: '${a['consent']} consentement(s)'),
          kpi(4, 'Note moyenne', toDouble(fb['rating'] ?? 0), Icons.star_rounded, const Color(0xFFE8A317), fmt: (x) => fb['rating'] == null ? '—' : '${x.toStringAsFixed(1)} / 5', sub: '${fb['count'] ?? 0} avis'),
          kpi(5, 'NPS', toDouble(fb['nps'] ?? 0), Icons.thumb_up_alt_outlined, YColors.success, fmt: (x) => fb['nps'] == null ? '—' : x.round().toString()),
        ]),
        const SizedBox(height: 14),
        if (byDay.isNotEmpty)
          SectionCard(
            title: tr('Inscriptions dans le temps'),
            child: SizedBox(height: 180, child: _DayBars(rows: byDay)),
          ),
        if (byDay.isEmpty)
          Card(child: ListTile(leading: const Icon(Icons.info_outline), title: Text(tr('Aucune inscription pour le moment')), subtitle: Text(tr('Partagez la page d\'inscription : chaque inscrit reçoit son QR code par e-mail et rejoint la liste « Inscrits — ${e['name']} » du module Marketing.')))),
        const SizedBox(height: 12),
        _twoCols(narrow, [
          if (sources.isNotEmpty) SectionCard(title: tr('D\'où viennent les inscrits'), child: _Bars(items: [for (final s in sources) (regSourceLabel('${s['source']}'), toDouble(s['count']))], color: YColors.blue)),
          if (companies.isNotEmpty) SectionCard(title: tr('Entreprises les plus représentées'), child: _Bars(items: [for (final s in companies) ('${s['name']}', toDouble(s['count']))], color: YColors.night)),
        ]),
        for (final q in answers)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: SectionCard(
              title: '${q['label']}',
              trailing: Text(tr('${q['count']} réponse(s)'), style: TextStyle(color: hint, fontSize: 12.5)),
              child: (q['distribution'] as List?)?.isNotEmpty == true
                  ? _Bars(items: [for (final d in q['distribution'] as List) ('${(d as Map)['value']}', toDouble(d['count']))], color: YColors.success, total: toDouble(q['count']))
                  : (q['samples'] as List?)?.isNotEmpty == true
                      ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          for (final s in (q['samples'] as List).reversed) Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Icon(Icons.format_quote, size: 16, color: hint), const SizedBox(width: 6), Expanded(child: Text(tr('$s')))])),
                        ])
                      : Text(tr('Pas encore de réponse.'), style: TextStyle(color: hint)),
            ),
          ),
        const SizedBox(height: 12),
        _twoCols(narrow, [
          SectionCard(
            title: tr('Avis des participants'),
            trailing: fb['requested_at'] != null ? Text(tr('demandés le ${dateFr('${fb['requested_at']}'.substring(0, 10))}'), style: TextStyle(color: hint, fontSize: 12)) : null,
            child: Column(children: [
              for (var s = 5; s >= 1; s--)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(children: [
                    SizedBox(width: 84, child: Row(children: [for (var k = 0; k < s; k++) const Icon(Icons.star_rounded, size: 16, color: Color(0xFFE8A317))])),
                    Expanded(child: _Meter(value: toDouble(fb['count']) == 0 ? 0 : toDouble(stars['$s']) / toDouble(fb['count']), color: const Color(0xFFE8A317))),
                    SizedBox(width: 34, child: Text(tr('${stars['$s'] ?? 0}'), textAlign: TextAlign.end)),
                  ]),
                ),
            ]),
          ),
          if (hours.isNotEmpty) SectionCard(title: tr('Entrées par heure'), child: _Bars(items: [for (final h in hours.entries) (h.key.length >= 13 ? '${dateFr(h.key.substring(0, 10))} ${h.key.substring(11, 13)} h' : h.key, toDouble(h.value))], color: YColors.warning)),
        ]),
        if (comments.isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Commentaires (${comments.length})'),
            child: Column(children: [
              for (final c in comments)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: CircleAvatar(backgroundColor: const Color(0xFFFFF4D6), child: Text(tr('${c['rating']}'), style: const TextStyle(color: Color(0xFF6B4A0E), fontWeight: FontWeight.w800))),
                  title: Text(tr('${c['comment']}')),
                  subtitle: Text(tr([c['name'] ?? 'Anonyme', dateFr(c['date']), if (c['nps'] != null) 'recommande à ${c['nps']}/10'].join(' · '))),
                ),
            ]),
          ),
        ],
        const SizedBox(height: 24),
      ]),
    );
  }
}

Widget _twoCols(bool narrow, List<Widget> children) {
  if (children.isEmpty) return const SizedBox.shrink();
  if (narrow || children.length == 1) return Column(children: [for (final c in children) Padding(padding: const EdgeInsets.only(bottom: 12), child: c)]);
  return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
    for (var i = 0; i < children.length; i++) ...[if (i > 0) const SizedBox(width: 12), Expanded(child: children[i])],
  ]);
}

void _share(BuildContext context, String title, String url) => showDialog(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr(title)),
        content: SelectableText(tr(url)),
        actions: [
          TextButton(onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent(url)}'), mode: LaunchMode.externalApplication), child: Text(tr('WhatsApp'))),
          TextButton(onPressed: () => safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication), child: Text(tr('Ouvrir'))),
          TextButton(onPressed: () => Clipboard.setData(ClipboardData(text: url)), child: Text(tr('Copier'))),
          FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
        ],
      ),
    );

class _Meter extends StatelessWidget {
  const _Meter({required this.value, required this.color});

  final double value;
  final Color color;

  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(6),
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: value.clamp(0, 1)),
          duration: const Duration(milliseconds: 700),
          curve: Curves.easeOutCubic,
          builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 10, color: color, backgroundColor: color.withValues(alpha: .12)),
        ),
      );
}

class _Bars extends StatelessWidget {
  const _Bars({required this.items, required this.color, this.total});

  final List<(String, double)> items;
  final Color color;
  final double? total;

  @override
  Widget build(BuildContext context) {
    final max = items.fold<double>(0, (m, x) => math.max(m, x.$2));
    final sum = total ?? items.fold<double>(0, (s, x) => s + x.$2);
    return Column(children: [
      for (final (label, v) in items.take(12))
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            SizedBox(width: 150, child: Text(tr(label), maxLines: 1, overflow: TextOverflow.ellipsis)),
            Expanded(child: _Meter(value: max == 0 ? 0 : v / max, color: color)),
            SizedBox(width: 74, child: Text(tr('${v.round()}${sum > 0 ? ' · ${(100 * v / sum).round()} %' : ''}'), textAlign: TextAlign.end, style: const TextStyle(fontSize: 12.5))),
          ]),
        ),
    ]);
  }
}

/// Barres par jour + courbe cumulée.
class _DayBars extends StatelessWidget {
  const _DayBars({required this.rows});

  final List<Map<String, dynamic>> rows;

  @override
  Widget build(BuildContext context) => TweenAnimationBuilder<double>(
        tween: Tween(begin: 0, end: 1),
        duration: const Duration(milliseconds: 800),
        curve: Curves.easeOutCubic,
        builder: (context, t, _) => CustomPaint(painter: _DayPainter(rows, t, Theme.of(context).hintColor), size: Size.infinite),
      );
}

class _DayPainter extends CustomPainter {
  _DayPainter(this.rows, this.t, this.hint);

  final List<Map<String, dynamic>> rows;
  final double t;
  final Color hint;

  @override
  void paint(Canvas canvas, Size size) {
    if (rows.isEmpty) return;
    const bottom = 22.0;
    final h = size.height - bottom;
    final maxDay = rows.fold<double>(1, (m, r) => math.max(m, toDouble(r['tickets'])));
    final maxCum = math.max(1.0, toDouble(rows.last['cumulative']));
    final w = size.width / rows.length;
    final bar = Paint()..color = YColors.blue.withValues(alpha: .75);
    final line = Paint()
      ..color = YColors.yellow
      ..strokeWidth = 3
      ..style = PaintingStyle.stroke;
    final path = Path();
    final tp = TextPainter(textDirection: TextDirection.ltr);
    final step = math.max(1, (rows.length / 8).ceil());
    for (var i = 0; i < rows.length; i++) {
      final v = toDouble(rows[i]['tickets']) / maxDay * h * .85 * t;
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(i * w + w * .18, h - v, w * .64, v), const Radius.circular(4)), bar);
      final cy = h - toDouble(rows[i]['cumulative']) / maxCum * h * .95 * t;
      final cx = i * w + w / 2;
      i == 0 ? path.moveTo(cx, cy) : path.lineTo(cx, cy);
      if (i % step == 0) {
        final d = '${rows[i]['date']}';
        tp.text = TextSpan(text: d.length >= 10 ? '${d.substring(8, 10)}/${d.substring(5, 7)}' : d, style: TextStyle(color: hint, fontSize: 10.5));
        tp.layout();
        tp.paint(canvas, Offset(cx - tp.width / 2, h + 5));
      }
    }
    canvas.drawPath(path, line);
    tp.text = TextSpan(text: tr('Cumul : ${toDouble(rows.last['cumulative']).round()}'), style: TextStyle(color: hint, fontSize: 11.5, fontWeight: FontWeight.w700));
    tp.layout();
    tp.paint(canvas, Offset(size.width - tp.width - 4, 0));
  }

  @override
  bool shouldRepaint(_DayPainter old) => old.t != t || old.rows != rows;
}

// ------------------------------------------------------------------- formulaire

Future<void> editRegistrationForm(BuildContext context, Map<String, dynamic> event) =>
    showFormDialog(context, title: tr('Formulaire d\'inscription'), maxWidth: 680, builder: (_) => _RegForm(event: event));

class _RegForm extends StatefulWidget {
  const _RegForm({required this.event});

  final Map<String, dynamic> event;

  @override
  State<_RegForm> createState() => _RegFormState();
}

class _RegFormState extends State<_RegForm> {
  late final Map<String, dynamic> f = regForm(widget.event);
  late String _email = '${f['ask_email']}';
  late bool _company = f['ask_company'] == true, _job = f['ask_job'] == true, _global = f['global_list'] != false;
  late bool _feedback = widget.event['feedback_enabled'] != false;
  late String _city = '${f['ask_city'] ?? 'optional'}', _country = '${f['ask_country'] ?? 'off'}';
  late final _defaultCountry = TextEditingController(text: '${f['default_country'] ?? ''}');
  late final _welcome = TextEditingController(text: '${f['kiosk_welcome'] ?? ''}');
  late String? _kioskColor = f['kiosk_color'] as String?;
  late bool _kioskLogo = f['kiosk_logo'] != false;
  late final _consent = TextEditingController(text: '${f['consent_text'] ?? ''}');
  late final _message = TextEditingController(text: '${f['confirmation_message'] ?? ''}');
  late final List<Map<String, dynamic>> _q = [
    for (final q in (f['questions'] as List? ?? const [])) {...asMap(q), '_label': TextEditingController(text: '${q['label']}'), '_options': TextEditingController(text: ((q['options'] as List?) ?? const []).join('\n'))},
  ];

  Future<void> _save() async {
    final questions = [
      for (final q in _q)
        if ((q['_label'] as TextEditingController).text.trim().isNotEmpty)
          {
            if (q['id'] != null) 'id': q['id'],
            'label': (q['_label'] as TextEditingController).text.trim(),
            'type': q['type'] ?? 'text',
            'required': q['required'] == true,
            'options': q['type'] == 'select' ? (q['_options'] as TextEditingController).text.split('\n').map((s) => s.trim()).where((s) => s.isNotEmpty).toList() : [],
          },
    ];
    if (questions.any((q) => q['type'] == 'select' && (q['options'] as List).isEmpty)) return toast(context, tr('Ajoutez les choix proposés pour chaque liste.'), error: true);
    await context.read<SyncEngine>().save('ev_events', {
      'id': widget.event['id'],
      'registration': {'ask_email': _email, 'ask_company': _company, 'ask_job': _job, 'global_list': _global, 'consent_text': _consent.text.trim(), 'confirmation_message': _message.text.trim(), 'questions': questions,
        'ask_city': _city, 'ask_country': _country, 'default_country': _defaultCountry.text.trim(), 'kiosk_welcome': _welcome.text.trim(), 'kiosk_type_id': f['kiosk_type_id'], 'kiosk_color': _kioskColor, 'kiosk_logo': _kioskLogo},
      'feedback_enabled': _feedback,
    });
    if (mounted) {
      Navigator.pop(context);
      toast(context, tr('Formulaire enregistré.'));
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return ListView(padding: const EdgeInsets.fromLTRB(24, 4, 24, 24), shrinkWrap: true, children: [
      Text(tr('Le nom est toujours demandé, et le téléphone quand l\'e-mail n\'est pas fourni. L\'inscrit reçoit un e-mail de confirmation avec son QR code (PDF) et l\'événement pour son agenda.'), style: TextStyle(color: hint, fontSize: 13)),
      const SizedBox(height: 8),
      Field(
        label: tr('E-mail'),
        child: SegmentedButton<String>(
          segments: [ButtonSegment(value: 'required', label: Text(tr('Obligatoire'))), ButtonSegment(value: 'optional', label: Text(tr('Facultatif')))],
          selected: {_email},
          onSelectionChanged: (s) => setState(() => _email = s.first),
        ),
      ),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Demander l\'entreprise')), value: _company, onChanged: (v) => setState(() => _company = v)),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Demander la fonction')), value: _job, onChanged: (v) => setState(() => _job = v)),
      // Provenance des visiteurs (statistiques de fréquentation)
      for (final (label, value, set) in [
        ('Ville / commune', _city, (String v) => _city = v),
        ('Pays', _country, (String v) => _country = v),
      ])
        Field(
          label: tr(label),
          child: SegmentedButton<String>(
            segments: [ButtonSegment(value: 'off', label: Text(tr('Non demandé'))), ButtonSegment(value: 'optional', label: Text(tr('Facultatif'))), ButtonSegment(value: 'required', label: Text(tr('Obligatoire')))],
            selected: {value},
            onSelectionChanged: (s) => setState(() => set(s.first)),
          ),
        ),
      if (_country != 'off') Field(label: tr('Pays proposé par défaut'), child: TextField(controller: _defaultCountry)),
      Field(label: tr('Message d\'accueil des bornes'), child: TextField(controller: _welcome, maxLines: 2)),
      Field(
        label: tr('Couleur des bornes'),
        child: Wrap(spacing: 10, runSpacing: 10, children: [
          for (final c in kioskColorPresets.entries)
            Tooltip(
              message: trn(c.value),
              child: InkWell(
                borderRadius: BorderRadius.circular(22),
                onTap: () => setState(() => _kioskColor = c.key),
                child: Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: Color(int.parse('FF${c.key.substring(1)}', radix: 16)),
                    shape: BoxShape.circle,
                    border: Border.all(color: (_kioskColor ?? '#0B1B3A') == c.key ? YColors.yellow : Colors.transparent, width: 3),
                  ),
                  child: (_kioskColor ?? '#0B1B3A') == c.key ? const Icon(Icons.check, color: Colors.white, size: 20) : null,
                ),
              ),
            ),
          SizedBox(
            width: 130,
            child: TextFormField(
              key: ValueKey(_kioskColor),
              initialValue: _kioskColor ?? '',
              decoration: InputDecoration(isDense: true, hintText: tr('#RRGGBB'), labelText: tr('Autre')),
              onChanged: (v) {
                if (RegExp(r'^#[0-9A-Fa-f]{6}$').hasMatch(v.trim())) setState(() => _kioskColor = v.trim().toUpperCase());
              },
            ),
          ),
        ]),
      ),
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        title: Text(tr('Logo de la société sur les bornes et les tickets')),
        subtitle: Text(tr('Le logo des Paramètres de la société ; il est gardé sur la borne pour fonctionner sans Internet.')),
        value: _kioskLogo,
        onChanged: (v) => setState(() => _kioskLogo = v),
      ),
      const Divider(),
      Row(children: [
        Expanded(child: Text(tr('Questions personnalisées'), style: Theme.of(context).textTheme.titleSmall)),
        TextButton.icon(
          onPressed: _q.length >= 20 ? null : () => setState(() => _q.add({'type': 'text', 'required': false, '_label': TextEditingController(), '_options': TextEditingController()})),
          icon: const Icon(Icons.add),
          label: Text(tr('Question')),
        ),
      ]),
      if (_q.isEmpty) Text(tr('Ex. : secteur d\'activité, atelier choisi, régime alimentaire, comment avez-vous connu l\'événement…'), style: TextStyle(color: hint, fontSize: 13)),
      for (var i = 0; i < _q.length; i++)
        AnimatedFadeIn(
          key: ObjectKey(_q[i]),
          child: Card(
            margin: const EdgeInsets.symmetric(vertical: 6),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
              child: Column(children: [
                Row(children: [
                  Expanded(flex: 3, child: TextField(controller: _q[i]['_label'] as TextEditingController, decoration: InputDecoration(isDense: true, labelText: tr('Question ${i + 1}')))),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 2,
                    child: DropdownButtonFormField<String>(
                      initialValue: regQuestionTypes.containsKey(_q[i]['type']) ? '${_q[i]['type']}' : 'text',
                      isExpanded: true,
                      decoration: InputDecoration(isDense: true, labelText: tr('Type')),
                      items: [for (final t in regQuestionTypes.entries) DropdownMenuItem(value: t.key, child: Text(tr(t.value)))],
                      onChanged: (v) => setState(() => _q[i]['type'] = v),
                    ),
                  ),
                  IconButton(tooltip: tr('Retirer'), onPressed: () => setState(() => _q.removeAt(i)), icon: const Icon(Icons.delete_outline, color: YColors.danger)),
                ]),
                if (_q[i]['type'] == 'select')
                  Padding(
                    padding: const EdgeInsets.only(top: 8, right: 8),
                    child: TextField(controller: _q[i]['_options'] as TextEditingController, minLines: 2, maxLines: 6, decoration: InputDecoration(isDense: true, labelText: tr('Choix proposés (un par ligne)'))),
                  ),
                CheckboxListTile(dense: true, contentPadding: EdgeInsets.zero, controlAffinity: ListTileControlAffinity.leading, title: Text(tr('Réponse obligatoire')), value: _q[i]['required'] == true, onChanged: (v) => setState(() => _q[i]['required'] = v)),
              ]),
            ),
          ),
        ),
      const Divider(),
      Field(label: tr('Texte du consentement Marketing ({societe} = votre société)'), child: TextField(controller: _consent, maxLines: 2)),
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        title: Text(tr('Ajouter les inscrits consentants à « Abonnés événements »')),
        subtitle: Text(tr('Tous les inscrits rejoignent la liste de l\'événement dans Marketing ; ceux qui acceptent rejoignent aussi la liste générale.')),
        value: _global,
        onChanged: (v) => setState(() => _global = v),
      ),
      Field(label: tr('Message de confirmation'), child: TextField(controller: _message, maxLines: 3)),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Demander l\'avis des participants après l\'événement')), value: _feedback, onChanged: (v) => setState(() => _feedback = v)),
      const SizedBox(height: 8),
      Align(alignment: Alignment.centerRight, child: SaveButton(onPressed: _save, child: Text(tr('Enregistrer')))),
    ]);
  }
}

// ======================================================================= Stands

const evPayMethods = {'cash': 'Espèces', 'mobile_money': 'Mobile Money', 'bank_transfer': 'Virement', 'cheque': 'Chèque', 'card': 'Carte', 'other': 'Autre'};

/// Encaissement hors ligne d'une réservation (acompte + solde en une fois).
Future<void> markBookingPaid(BuildContext context, Map<String, dynamic> booking) async {
  var method = 'cash';
  final ref = TextEditingController();
  final api = context.read<Session>().api;
  final sync = context.read<SyncEngine>();
  await showDialog(
    context: context,
    builder: (dc) => StatefulBuilder(
      builder: (dc, set) => YAlertDialog(
        title: Text(tr('Marquer ${booking['number'] ?? ''} comme payée')),
        content: SizedBox(
          width: 420,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('Les factures de la réservation (acompte et solde) sont soldées : le stand passe en « Payé » sur le plan.'), style: TextStyle(color: Theme.of(dc).hintColor)),
            const SizedBox(height: 12),
            Wrap(spacing: 6, runSpacing: 6, children: [for (final m in evPayMethods.entries) ChoiceChip(label: Text(tr(m.value)), selected: method == m.key, onSelected: (_) => set(() => method = m.key))]),
            const SizedBox(height: 10),
            TextField(controller: ref, decoration: InputDecoration(labelText: tr('Référence (n° de transaction, chèque…)'))),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))),
          SaveButton(
            onPressed: () async {
              try {
                await sync.sync();
                final r = await api.post('evenements/bookings/${booking['id']}/mark-paid', {'method': method, if (ref.text.trim().isNotEmpty) 'reference': ref.text.trim()});
                await LocalDb.put('ev_bookings', asMap(r['data']['booking']));
                await sync.sync();
                if (dc.mounted) {
                  Navigator.pop(dc);
                  toast(dc, tr('Encaissé : ${money(toDouble(r['data']['amount']), context.read<Session>().currency)}.'));
                }
              } catch (e) {
                if (dc.mounted) toast(dc, errorText(e), error: true);
              }
            },
            icon: const Icon(Icons.check),
            child: Text(tr('Encaisser')),
          ),
        ],
      ),
    ),
  );
}

const evProfileFields = [
  ('tagline', 'Accroche'), ('sector', 'Secteur d\'activité'), ('description', 'Présentation'), ('products', 'Produits et services présentés'), ('offer', 'Offre spéciale salon'),
  ('website', 'Site web'), ('email', 'E-mail'), ('phone', 'Téléphone'), ('whatsapp', 'WhatsApp'), ('address', 'Adresse'),
  ('facebook', 'Facebook'), ('instagram', 'Instagram'), ('linkedin', 'LinkedIn'), ('tiktok', 'TikTok'), ('youtube', 'YouTube'),
];

/// Fiche exposant / sponsor : saisie par l'équipe, ou lien envoyé à l'exposant pour qu'il présente lui-même son entreprise.
Future<void> editExhibitorProfile(BuildContext context, Map<String, dynamic> booking) =>
    showFormDialog(context, title: tr('Fiche de ${booking['exhibitor_name'] ?? booking['partner_name'] ?? 'l\'exposant'}'), maxWidth: 640, builder: (_) => _ProfileForm(booking: booking));

class _ProfileForm extends StatefulWidget {
  const _ProfileForm({required this.booking});

  final Map<String, dynamic> booking;

  @override
  State<_ProfileForm> createState() => _ProfileFormState();
}

class _ProfileFormState extends State<_ProfileForm> {
  late final Map<String, dynamic> p = widget.booking['profile'] is Map ? asMap(widget.booking['profile']) : {};
  late final _c = {for (final (k, _) in evProfileFields) k: TextEditingController(text: '${p[k] ?? ''}')};
  late bool _published = widget.booking['profile_published'] == true;
  List<int>? _logo;
  String? _logoName;
  bool _busy = false;

  Future<void> _pick() async {
    final picked = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: const ['png', 'jpg', 'jpeg', 'webp']);
    if (picked.isEmpty) return;
    final bytes = await picked.single.readAsBytes();
    if (bytes.length > 2 * 1024 * 1024) {
      if (mounted) toast(context, tr('Logo trop lourd (2 Mo au plus).'), error: true);
      return;
    }
    setState(() => (_logo = bytes, _logoName = picked.single.name));
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final api = context.read<Session>().api;
    final fields = {for (final e in _c.entries) e.key: e.value.text.trim(), if (widget.booking['status'] == 'confirmed') 'published': _published ? '1' : '0'};
    try {
      final r = _logo != null
          ? await api.uploadForm('evenements/bookings/${widget.booking['id']}/profile', fields, 'logo', _logo!, _logoName ?? 'logo.png')
          : await api.post('evenements/bookings/${widget.booking['id']}/profile', {...fields, if (fields['published'] != null) 'published': _published});
      await LocalDb.put('ev_bookings', asMap(r['data']));
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Fiche enregistrée.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = widget.booking;
    final hint = Theme.of(context).hintColor;
    return ListView(padding: const EdgeInsets.fromLTRB(24, 4, 24, 24), shrinkWrap: true, children: [
      if (b['space_url'] != null)
        Card(
          color: YColors.blue.withValues(alpha: .06),
          child: ListTile(
            leading: const Icon(Icons.link, color: YColors.blue),
            title: Text(tr('Espace en ligne de l\'exposant')),
            subtitle: Text(tr('Envoyez ce lien : l\'exposant ou le sponsor complète lui-même sa fiche et son logo.')),
            trailing: IconButton(tooltip: tr('Partager'), icon: const Icon(Icons.share_outlined), onPressed: () => _share(context, 'Espace exposant', '${b['space_url']}')),
          ),
        ),
      const SizedBox(height: 8),
      Row(children: [
        Container(
          width: 72,
          height: 72,
          decoration: BoxDecoration(border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(14), color: Colors.white),
          clipBehavior: Clip.antiAlias,
          child: _logo != null
              ? Image.memory(Uint8List.fromList(_logo!), fit: BoxFit.contain)
              : b['logo_url'] != null
                  ? Image.network('${b['logo_url']}', fit: BoxFit.contain, errorBuilder: (_, _, _) => const Icon(Icons.image_not_supported_outlined))
                  : const Icon(Icons.add_photo_alternate_outlined, color: YColors.muted),
        ),
        const SizedBox(width: 12),
        OutlinedButton.icon(onPressed: _pick, icon: const Icon(Icons.upload_outlined), label: Text(tr(_logo == null ? 'Choisir un logo' : 'Changer'))),
      ]),
      const SizedBox(height: 8),
      for (final (k, l) in evProfileFields)
        Field(label: l, child: TextField(controller: _c[k], minLines: k == 'description' || k == 'products' ? 3 : 1, maxLines: k == 'description' || k == 'products' ? 6 : 1)),
      if (b['status'] == 'confirmed')
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Publier dans l\'annuaire des exposants')), value: _published, onChanged: (v) => setState(() => _published = v))
      else
        Text(tr('La fiche pourra être publiée dans l\'annuaire une fois la réservation confirmée.'), style: TextStyle(color: hint, fontSize: 13)),
      const SizedBox(height: 8),
      Align(alignment: Alignment.centerRight, child: FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer')))),
    ]);
  }
}

// ======================================================================= Super admin

/// Tous les événements de la plateforme : inscriptions, présence, stands, avis, recettes.
class AdminEventsScreen extends StatefulWidget {
  const AdminEventsScreen({super.key});

  @override
  State<AdminEventsScreen> createState() => _AdminEventsScreenState();
}

class _AdminEventsScreenState extends State<AdminEventsScreen> {
  String _period = 'upcoming';
  String _q = '';
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('admin/events', query: {'period': _period == 'all' ? '' : _period, 'q': _q});
      if (mounted) setState(() => (_d = asMap(r), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final d = _d;
    final rows = d == null ? <Map<String, dynamic>>[] : List<Map<String, dynamic>>.from((d['data'] as List).map((e) => asMap(e)));
    final t = d == null ? <String, dynamic>{} : Map<String, dynamic>.from(d['totals'] is Map ? d['totals'] as Map : {});
    final hint = Theme.of(context).hintColor;
    return Column(children: [
      PageHeader(
        title: tr('Événements de la plateforme'),
        subtitle: d == null ? 'Inscriptions, stands et avis de toutes les sociétés' : '${t['events']} événement(s) · ${t['companies']} société(s) · ${t['registrations']} inscrit(s) · ${money(toDouble(t['ticket_revenue']), cur)} de billetterie',
        actions: [IconButton.outlined(onPressed: _load, icon: const Icon(Icons.refresh))],
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
        child: Row(children: [
          Expanded(child: SearchField(hint: tr('Événement, ville…'), onChanged: (v) => _q = v)),
          const SizedBox(width: 10),
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'upcoming', label: Text(tr('À venir'))), ButtonSegment(value: 'past', label: Text(tr('Passés'))), ButtonSegment(value: 'all', label: Text(tr('Tous')))],
            selected: {_period},
            onSelectionChanged: (s) {
              setState(() => _period = s.first);
              _load();
            },
          ),
          IconButton(onPressed: _load, icon: const Icon(Icons.search)),
        ]),
      ),
      Expanded(
        child: _error != null && d == null
            ? EmptyState(icon: Icons.cloud_off, title: tr('Chargement impossible'), message: errorText(_error!))
            : d == null
                ? const LoadingSkeleton()
                : rows.isEmpty
                    ? EmptyState(icon: Icons.event_busy, title: tr('Aucun événement'))
                    : ResponsiveList<Map<String, dynamic>>(
                        items: rows,
                        columns: const ['Événement', 'Société', 'Dates', 'Inscrits', 'Présence', 'Stands', 'Avis', 'Billetterie'],
                        cells: (e) => [
                          Text(tr('${e['name']}'), style: const TextStyle(fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis),
                          Text(tr('${e['company']}'), overflow: TextOverflow.ellipsis),
                          Text(tr('${dateFr(e['start_date'])}${e['city'] != null ? ' · ${e['city']}' : ''}')),
                          Text(tr('${e['registrations']}')),
                          Text(tr(toDouble(e['registrations']) > 0 ? '${(100 * toDouble(e['checked_in']) / toDouble(e['registrations'])).round()} %' : '—')),
                          Text(tr(toDouble(e['spaces']) > 0 ? '${e['spaces_taken']}/${e['spaces']} (${toDouble(e['fill_rate']).round()} %)' : '—')),
                          e['rating'] != null ? Row(children: [const Icon(Icons.star_rounded, size: 16, color: Color(0xFFE8A317)), Text(tr(' ${toDouble(e['rating']).toStringAsFixed(1)} (${e['feedback']})'))]) : const Text('—'),
                          Text(tr(money(toDouble(e['ticket_revenue']), cur))),
                        ],
                        mobileTile: (e) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr('${e['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          Text(tr('${e['company']} · ${dateFr(e['start_date'])} · ${e['registrations']} inscrit(s) · stands ${e['spaces_taken']}/${e['spaces']}'), style: TextStyle(color: hint)),
                        ]),
                      ),
      ),
    ]);
  }
}
