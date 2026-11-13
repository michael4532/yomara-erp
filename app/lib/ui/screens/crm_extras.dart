import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Couleur et libellé de la note d'un prospect (chaud, tiède, froid).
(Color, String) leadTemperature(int score) =>
    score >= 70 ? (const Color(0xFFDC2626), 'Chaud') : (score >= 40 ? (const Color(0xFFD97706), 'Tiède') : (const Color(0xFF2563EB), 'Froid'));

class LeadScoreTag extends StatelessWidget {
  const LeadScoreTag({super.key, required this.lead});

  final Map<String, dynamic> lead;

  @override
  Widget build(BuildContext context) {
    final score = (lead['score'] as num?)?.toInt() ?? 0;
    if (score <= 0 || (lead['status'] ?? 'open') != 'open') return const SizedBox.shrink();
    final (c, l) = leadTemperature(score);
    return Tooltip(
      message: tr('Note du prospect : $score/100 (coordonnées, origine, avancement, échanges récents)'),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
        decoration: BoxDecoration(color: c.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(score >= 70 ? Icons.local_fire_department : Icons.thermostat, size: 13, color: c),
          const SizedBox(width: 3),
          Text('${tr(l)} $score', style: TextStyle(color: c, fontSize: 11.5, fontWeight: FontWeight.w700)),
        ]),
      ),
    );
  }
}

const seqChannels = {'task': 'Tâche pour le commercial', 'call': 'Appel à passer', 'whatsapp': 'WhatsApp', 'sms': 'SMS', 'email': 'E-mail'};
const seqIcons = {'task': Icons.task_alt, 'call': Icons.call_outlined, 'whatsapp': Icons.chat_outlined, 'sms': Icons.sms_outlined, 'email': Icons.mail_outline};

/// Relances automatiques : modèles de relance (étapes espacées dans le temps).
class CrmSequencesView extends StatefulWidget {
  const CrmSequencesView({super.key, required this.manage});

  final bool manage;

  @override
  State<CrmSequencesView> createState() => _CrmSequencesViewState();
}

class _CrmSequencesViewState extends State<CrmSequencesView> {
  List<Map<String, dynamic>>? _rows;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('crm/sequences'));
      if (mounted) setState(() => (_rows = [for (final x in (r['data'] as List? ?? const [])) asMap(x)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  /// Bibliothèque de relances prêtes à l'emploi : le modèle choisi est copié puis ouvert pour être adapté.
  Future<void> _library() async {
    final api = context.read<Session>().api;
    List<Map<String, dynamic>> lib;
    try {
      lib = [for (final x in (asMap(await api.get('crm/sequences/library'))['data'] as List? ?? const [])) asMap(x)];
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!mounted) return;
    final picked = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => _SequenceLibraryDialog(items: lib));
    if (picked == null || !mounted) return;
    try {
      final r = asMap(await api.post('crm/sequences', {'preset': picked['key']}));
      await _load();
      if (!mounted) return;
      toast(context, tr('« ${picked['name']} » ajoutée à vos relances : adaptez les messages à votre entreprise.'));
      await _edit(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _edit([Map<String, dynamic>? seq]) async {
    final ok = await showFormDialog<bool>(context, title: seq == null ? 'Nouvelle relance automatique' : 'Relance automatique', maxWidth: 688, builder: (_) => _SequenceEditor(seq: seq));
    if (ok == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    final rows = _rows;
    if (rows == null) return const Center(child: CircularProgressIndicator());
    return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 40), children: [
      Row(children: [
        Expanded(
          child: Text(tr('Une suite de messages et de tâches envoyés tout seuls à un prospect (J0, J+2, J+5…). Elle s\'arrête quand l\'affaire est gagnée ou perdue.'),
              style: const TextStyle(color: YColors.muted)),
        ),
        if (widget.manage) ...[
          FilledButton.icon(onPressed: _library, icon: const Icon(Icons.auto_awesome_motion_outlined), label: Text(tr('Modèles prêts à l\'emploi'))),
          const SizedBox(width: 8),
          OutlinedButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.add), label: Text(tr('Relance vierge'))),
        ],
      ]),
      const SizedBox(height: 12),
      if (rows.isEmpty)
        EmptyState(
          icon: Icons.schedule_send_outlined,
          title: tr('Aucune relance automatique'),
          message: tr('Partez d\'un modèle prêt à l\'emploi (après un devis, prospect qui ne répond plus, après un salon, immobilier, formation…) et adaptez-le.'),
          action: widget.manage ? FilledButton.icon(onPressed: _library, icon: const Icon(Icons.auto_awesome_motion_outlined), label: Text(tr('Voir les modèles'))) : null,
        ),
      for (final r in rows)
        Card(
          child: ListTile(
            onTap: widget.manage ? () => _edit(r) : null,
            leading: Icon(Icons.schedule_send_outlined, color: r['active'] == true ? YColors.blue : YColors.muted),
            title: Text(verbatim('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Wrap(spacing: 4, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                for (final st in (r['steps'] as List? ?? const []).map(asMap))
                  Chip(
                    visualDensity: VisualDensity.compact,
                    avatar: Icon(seqIcons[st['channel']] ?? Icons.circle, size: 15),
                    label: Text(tr('J+${st['delay_days']}')),
                  ),
                Text(tr('  ${r['running']} en cours · ${r['finished']} terminée(s) · ${r['won']} gagnée(s)${r['active'] == true ? '' : ' · désactivée'}'), style: const TextStyle(color: YColors.muted, fontSize: 12.5)),
              ]),
            ),
            trailing: widget.manage ? const Icon(Icons.chevron_right) : null,
          ),
        ),
    ]);
  }
}

/// Choix d'une relance dans la bibliothèque : par situation ou par métier, avec ses étapes.
class _SequenceLibraryDialog extends StatefulWidget {
  const _SequenceLibraryDialog({required this.items});

  final List<Map<String, dynamic>> items;

  @override
  State<_SequenceLibraryDialog> createState() => _SequenceLibraryDialogState();
}

class _SequenceLibraryDialogState extends State<_SequenceLibraryDialog> {
  String _cat = '';

  @override
  Widget build(BuildContext context) {
    final cats = {for (final i in widget.items) '${i['category']}'}.toList();
    final rows = widget.items.where((i) => _cat.isEmpty || i['category'] == _cat).toList();
    const labels = {'email': 'E-mail', 'sms': 'SMS', 'whatsapp': 'WhatsApp', 'task': 'Tâche', 'call': 'Appel'};
    return Dialog(
      insetPadding: const EdgeInsets.all(20),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 980, maxHeight: 760),
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 18, 12, 4),
            child: Row(children: [
              Expanded(child: Text(tr('Modèles de relance prospect'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800))),
              IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close)),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Wrap(spacing: 8, children: [
                ChoiceChip(label: Text(tr('Tous (${widget.items.length})')), selected: _cat.isEmpty, onSelected: (_) => setState(() => _cat = '')),
                for (final c in cats) ChoiceChip(label: Text(tr(c)), selected: _cat == c, onSelected: (_) => setState(() => _cat = c)),
              ]),
            ),
          ),
          Expanded(
            child: LayoutBuilder(builder: (context, box) {
              final cols = box.maxWidth >= 860 ? 3 : (box.maxWidth >= 560 ? 2 : 1);
              final w = (box.maxWidth - 48 - 14 * (cols - 1)) / cols;
              return SingleChildScrollView(
                padding: const EdgeInsets.all(24),
                child: Wrap(spacing: 14, runSpacing: 14, children: [
                  for (final i in rows)
                    SizedBox(
                      width: w,
                      child: Card(
                        margin: EdgeInsets.zero,
                        child: InkWell(
                          borderRadius: BorderRadius.circular(12),
                          onTap: () => Navigator.pop(context, i),
                          child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(tr('${i['category']}'), style: const TextStyle(color: YColors.blue, fontSize: 12, fontWeight: FontWeight.w700)),
                              const SizedBox(height: 4),
                              Text(tr('${i['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                              const SizedBox(height: 6),
                              Text(tr('${i['description']}'), style: const TextStyle(color: YColors.muted, fontSize: 13, height: 1.35)),
                              const SizedBox(height: 10),
                              for (final (n, st) in (i['steps'] as List? ?? const []).map(asMap).indexed)
                                Padding(
                                  padding: const EdgeInsets.only(bottom: 4),
                                  child: Row(children: [
                                    Icon(seqIcons[st['channel']] ?? Icons.circle, size: 15, color: YColors.muted),
                                    const SizedBox(width: 6),
                                    // Jour réel de l'étape : délais cumulés depuis l'inscription du prospect
                                    Builder(builder: (_) {
                                      final day = (i['steps'] as List).take(n + 1).fold<int>(0, (t, x) => t + ((asMap(x)['delay_days'] as num?)?.toInt() ?? 0));
                                      return Text(tr(day == 0 ? 'Jour J' : 'J+$day'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5));
                                    }),
                                    const SizedBox(width: 6),
                                    Expanded(child: Text(tr(labels[st['channel']] ?? ''), style: const TextStyle(fontSize: 12.5, color: YColors.muted))),
                                  ]),
                                ),
                              const SizedBox(height: 6),
                              Align(alignment: Alignment.centerRight, child: TextButton(onPressed: () => Navigator.pop(context, i), child: Text(tr('Utiliser ce modèle')))),
                            ]),
                          ),
                        ),
                      ),
                    ),
                ]),
              );
            }),
          ),
        ]),
      ),
    );
  }
}

class _SequenceEditor extends StatefulWidget {
  const _SequenceEditor({this.seq});

  final Map<String, dynamic>? seq;

  @override
  State<_SequenceEditor> createState() => _SequenceEditorState();
}

class _SequenceEditorState extends State<_SequenceEditor> {
  late final _name = TextEditingController(text: '${widget.seq?['name'] ?? ''}');
  late bool _active = widget.seq?['active'] != false;
  late final List<Map<String, dynamic>> _steps = [
    for (final s in (widget.seq?['steps'] as List? ?? const [])) {...asMap(s), '_s': TextEditingController(text: '${asMap(s)['subject'] ?? ''}'), '_t': TextEditingController(text: '${asMap(s)['text'] ?? ''}')},
  ];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (_steps.isEmpty) {
      _add('whatsapp', 0, 'Bonjour {{prenom}}, merci pour votre intérêt ! Je suis {{commercial}} de {{entreprise}}. Quand puis-je vous appeler ?');
      _add('call', 1, 'Appeler {{prenom}} pour comprendre son besoin');
      _add('email', 3, 'Bonjour {{prenom}},\n\nComme convenu, voici les informations sur notre offre.\n\nBien cordialement,\n{{commercial}}', subject: 'Votre projet avec {{entreprise}}');
    }
  }

  void _add(String ch, int days, String text, {String subject = ''}) =>
      _steps.add({'channel': ch, 'delay_days': days, '_s': TextEditingController(text: subject), '_t': TextEditingController(text: text)});

  @override
  void dispose() {
    _name.dispose();
    for (final s in _steps) {
      (s['_s'] as TextEditingController).dispose();
      (s['_t'] as TextEditingController).dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final api = context.read<Session>().api;
    final body = {
      'name': _name.text.trim(),
      'active': _active,
      'steps': [
        for (final s in _steps) {'channel': s['channel'], 'delay_days': s['delay_days'], 'subject': (s['_s'] as TextEditingController).text.trim(), 'text': (s['_t'] as TextEditingController).text.trim()},
      ],
    };
    setState(() => _busy = true);
    try {
      widget.seq == null ? await api.post('crm/sequences', body) : await api.put('crm/sequences/${widget.seq!['id']}', body);
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete() async {
    if (!await confirm(context, tr('Supprimer la relance'), tr('Les prospects qui la suivent s\'arrêtent.'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    final api = context.read<Session>().api;
    try {
      await api.delete('crm/sequences/${widget.seq!['id']}');
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
    padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom'), hintText: tr('Ex. Relance prospects Facebook'))),
        SwitchListTile(contentPadding: EdgeInsets.zero, value: _active, onChanged: (v) => setState(() => _active = v), title: Text(tr('Active'))),
        Text(tr('Variables : {{prenom}}, {{nom}}, {{societe}}, {{entreprise}}, {{commercial}}, {{telephone_commercial}}'), style: const TextStyle(color: YColors.muted, fontSize: 12.5)),
        for (final (i, s) in _steps.indexed)
          Card(
            margin: const EdgeInsets.only(top: 10),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(children: [
                Row(children: [
                  CircleAvatar(radius: 13, child: Text('${i + 1}', style: const TextStyle(fontSize: 12))),
                  const SizedBox(width: 10),
                  Expanded(
                    child: DropdownButtonFormField<String>(
                      initialValue: '${s['channel']}',
                      decoration: InputDecoration(labelText: tr('Action'), isDense: true),
                      items: [for (final e in seqChannels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                      onChanged: (v) => setState(() => s['channel'] = v),
                    ),
                  ),
                  const SizedBox(width: 10),
                  SizedBox(
                    width: 120,
                    child: DropdownButtonFormField<int>(
                      initialValue: (s['delay_days'] as num?)?.toInt() ?? 0,
                      decoration: InputDecoration(labelText: tr(i == 0 ? 'Après l\'inscription' : 'Après l\'étape d\'avant'), isDense: true),
                      items: [for (final d in const [0, 1, 2, 3, 4, 5, 7, 10, 14, 21, 30]) DropdownMenuItem(value: d, child: Text(tr(d == 0 ? 'Aussitôt' : '$d jour(s)')))],
                      onChanged: (v) => setState(() => s['delay_days'] = v),
                    ),
                  ),
                  IconButton(onPressed: _steps.length > 1 ? () => setState(() => _steps.removeAt(i)) : null, icon: const Icon(Icons.delete_outline)),
                ]),
                if (s['channel'] == 'email') Padding(padding: const EdgeInsets.only(top: 8), child: TextField(controller: s['_s'] as TextEditingController, decoration: InputDecoration(labelText: tr('Objet')))),
                const SizedBox(height: 8),
                TextField(
                  controller: s['_t'] as TextEditingController,
                  minLines: 1,
                  maxLines: 6,
                  decoration: InputDecoration(labelText: tr(['task', 'call'].contains(s['channel']) ? 'Tâche' : 'Message')),
                ),
              ]),
            ),
          ),
        const SizedBox(height: 8),
        Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => setState(() => _add('task', 2, '')), icon: const Icon(Icons.add), label: Text(tr('Ajouter une étape')))),
      ]),
      const SizedBox(height: 20),
      Align(
        alignment: Alignment.centerRight,
        child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
          if (widget.seq != null) TextButton(onPressed: _delete, child: Text(tr('Supprimer'), style: const TextStyle(color: YColors.danger))),
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
        ]),
      ),
    ]),
  );
}

/// Relance automatique suivie par une opportunité : état, historique, lancement et arrêt.
class LeadSequencePanel extends StatefulWidget {
  const LeadSequencePanel({super.key, required this.leadId, required this.manage});

  final String leadId;
  final bool manage;

  @override
  State<LeadSequencePanel> createState() => _LeadSequencePanelState();
}

class _LeadSequencePanelState extends State<LeadSequencePanel> {
  List<Map<String, dynamic>>? _runs;
  List<Map<String, dynamic>> _seqs = [];
  bool _offline = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final r = asMap(await api.get('crm/leads/${widget.leadId}/sequence'));
      final s = asMap(await api.get('crm/sequences'));
      if (mounted) {
        setState(() {
          _runs = [for (final x in (r['data'] as List? ?? const [])) asMap(x)];
          _seqs = [for (final x in (s['data'] as List? ?? const [])) asMap(x)].where((x) => x['active'] == true).toList();
        });
      }
    } catch (_) {
      if (mounted) setState(() => _offline = true);
    }
  }

  Future<void> _start() async {
    final id = await showDialog<int>(
      context: context,
      builder: (c) => SimpleDialog(title: Text(tr('Lancer une relance automatique')), children: [
        for (final s in _seqs) SimpleDialogOption(onPressed: () => Navigator.pop(c, s['id'] as int), child: Text(verbatim('${s['name']}'))),
        if (_seqs.isEmpty) Padding(padding: const EdgeInsets.all(16), child: Text(tr('Créez d\'abord une relance dans l\'onglet « Relances ».'))),
      ]),
    );
    if (id == null || !mounted) return;
    final api = context.read<Session>().api;
    try {
      final r = asMap(await api.post('crm/leads/${widget.leadId}/sequence', {'sequence_id': id}));
      if (mounted) setState(() => _runs = [for (final x in (r['data'] as List? ?? const [])) asMap(x)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _stop() async {
    final api = context.read<Session>().api;
    try {
      final r = asMap(await api.delete('crm/leads/${widget.leadId}/sequence'));
      if (mounted) setState(() => _runs = [for (final x in (r['data'] as List? ?? const [])) asMap(x)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_offline) return const SizedBox.shrink();
    final runs = _runs;
    if (runs == null) return const SizedBox.shrink();
    final active = runs.where((r) => r['status'] == 'active').firstOrNull;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const Divider(height: 24),
      Row(children: [
        Expanded(child: Text(tr('Relance automatique'), style: const TextStyle(fontWeight: FontWeight.w700))),
        if (widget.manage && active == null) TextButton.icon(onPressed: _start, icon: const Icon(Icons.schedule_send_outlined), label: Text(tr('Lancer'))),
        if (widget.manage && active != null) TextButton.icon(onPressed: _stop, icon: const Icon(Icons.stop_circle_outlined), label: Text(tr('Arrêter'))),
      ]),
      if (runs.isEmpty) Text(tr('Aucune relance en cours.'), style: const TextStyle(color: YColors.muted)),
      for (final r in runs.take(2))
        Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(
                tr('${r['sequence']} — ${r['status'] == 'active' ? 'étape ${r['step']}/${r['steps']}, prochaine : ${seqChannels[r['next']] ?? ''} le ${_fmt(r['next_at'])}' : (r['status'] == 'done' ? 'terminée' : 'arrêtée')}'),
                style: const TextStyle(fontWeight: FontWeight.w600)),
            for (final h in (r['history'] as List? ?? const []).map(asMap).toList().reversed.take(5))
              Text('• ${_fmt(h['at'])} : ${h['label']}', style: const TextStyle(color: YColors.muted, fontSize: 12.5)),
          ]),
        ),
    ]);
  }

  String _fmt(dynamic v) {
    final d = DateTime.tryParse('${v ?? ''}');
    if (d == null) return '';
    String t(int n) => n.toString().padLeft(2, '0');
    return '${t(d.day)}/${t(d.month)} ${t(d.hour)}:${t(d.minute)}';
  }
}
