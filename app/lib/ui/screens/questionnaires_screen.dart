import 'package:barcode/barcode.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Sondages & formulaires : questionnaires (questions, lien, QR code, résultats) et réponses.
class QuestionnairesScreen extends StatelessWidget {
  const QuestionnairesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final tabs = <(String, Widget)>[
      ('Questionnaires', RecordsView(module: 'questionnaires', resource: 'forms', emptyIcon: Icons.quiz_outlined, newLabel: tr('Nouveau questionnaire'), actions: formActions,
          emptyMessage: tr('Satisfaction, NPS, évaluation, sondage ou formulaire : les questions de départ sont proposées selon le type.'))),
      ('Réponses', RecordsView(module: 'questionnaires', resource: 'responses', emptyIcon: Icons.how_to_vote_outlined, emptyMessage: tr('Les réponses reçues par le lien ou saisies dans l\'application apparaissent ici.'))),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Sondages & formulaires'), subtitle: tr('Questionnaires en ligne, satisfaction, NPS et résultats')),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

const _types = {'short': 'Texte court', 'long': 'Texte long', 'single': 'Choix unique', 'multi': 'Choix multiples', 'rating': 'Note sur 5', 'nps': 'Recommandation de 0 à 10',
  'yesno': 'Oui / non', 'number': 'Nombre', 'date': 'Date', 'email': 'E-mail', 'phone': 'Téléphone'};

/// Boutons d'un questionnaire.
List<Widget> formActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  final manage = s.can('questionnaires.manage');
  final id = row['id'];
  return [
    if (manage)
      OutlinedButton.icon(
        icon: const Icon(Icons.edit_note, size: 18),
        label: Text(tr('Questions (${row['questions_count'] ?? 0})')),
        onPressed: () async {
          final ok = await showDialog<bool>(context: dialog, builder: (c) => _QuestionsEditor(formId: id));
          if (ok == true) reload();
        },
      ),
    OutlinedButton.icon(
      icon: const Icon(Icons.bar_chart, size: 18),
      label: Text(tr('Résultats')),
      onPressed: () => showDialog<void>(context: dialog, builder: (c) => _Results(formId: id)),
    ),
    OutlinedButton.icon(
      icon: const Icon(Icons.qr_code_2, size: 18),
      label: Text(tr('Partager')),
      onPressed: () => showDialog<void>(context: dialog, builder: (c) => _Share(row: row)),
    ),
    if (row['status'] != 'closed' && (s.can('questionnaires.answer') || manage))
      OutlinedButton.icon(
        icon: const Icon(Icons.edit_outlined, size: 18),
        label: Text(tr('Saisir une réponse')),
        onPressed: () async {
          final ok = await showDialog<bool>(context: dialog, builder: (c) => _AnswerDialog(formId: id, title: '${row['title']}'));
          if (ok == true) {
            reload();
            if (dialog.mounted) toast(dialog, tr('Réponse enregistrée.'));
          }
        },
      ),
    OutlinedButton.icon(
      icon: const Icon(Icons.table_view_outlined, size: 18),
      label: Text(tr('Excel')),
      onPressed: () async {
        try {
          final bytes = await s.api.download('questionnaires/forms/$id/export');
          final where = await saveFile('${row['title']}.xlsx'.replaceAll('/', '-'), bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
          if (dialog.mounted) toast(dialog, tr('Fichier enregistré : $where'));
        } catch (e) {
          if (dialog.mounted) toast(dialog, errorText(e), error: true);
        }
      },
    ),
    if (manage)
      TextButton.icon(
        icon: const Icon(Icons.copy_all_outlined, size: 18),
        label: Text(tr('Dupliquer')),
        onPressed: () async {
          try {
            await s.api.post('questionnaires/forms/$id/duplicate');
            if (dialog.mounted) {
              Navigator.pop(dialog, <String, dynamic>{'duplicated': true});
              toast(dialog, tr('Copie créée en brouillon.'));
            }
            reload();
          } catch (e) {
            if (dialog.mounted) toast(dialog, errorText(e), error: true);
          }
        },
      ),
  ];
}

class _QuestionsEditor extends StatefulWidget {
  const _QuestionsEditor({required this.formId});

  final Object? formId;

  @override
  State<_QuestionsEditor> createState() => _QuestionsEditorState();
}

class _QuestionsEditorState extends State<_QuestionsEditor> {
  List<Map<String, dynamic>>? _q;
  bool _locked = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final r = await context.read<Session>().api.get('questionnaires/forms/${widget.formId}/questions');
    final d = asMap((r as Map)['data']);
    if (mounted) {
      setState(() {
        _q = [for (final x in d['questions'] as List) asMap(x)..['_opts'] = ((x['options'] as List?) ?? const []).join('\n')];
        _locked = d['locked'] == true;
      });
    }
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('questionnaires/forms/${widget.formId}/questions', {
        'questions': [
          for (final q in _q!) {'id': q['id'], 'type': q['type'], 'label': q['label'], 'required': q['required'] == true, 'help': q['help'], 'options': '${q['_opts'] ?? ''}'.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList()},
        ],
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final q = _q;
    return YAlertDialog(
      title: Text(tr('Questions')),
      content: SizedBox(
        width: 680,
        height: 560,
        child: q == null
            ? const Center(child: CircularProgressIndicator())
            : Column(children: [
                if (_locked)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Text(tr('Des réponses existent déjà : modifier ou supprimer une question peut fausser les résultats.'), style: const TextStyle(color: Color(0xFFD97706))),
                  ),
                Expanded(
                  child: ReorderableListView.builder(
                    buildDefaultDragHandles: false,
                    itemCount: q.length,
                    onReorderItem: (a, b) => setState(() => q.insert(b, q.removeAt(a))),
                    itemBuilder: (c, i) {
                      final x = q[i];
                      final choice = x['type'] == 'single' || x['type'] == 'multi';
                      return Card(
                        key: ValueKey('${x['id'] ?? i}-$i'),
                        child: Padding(
                          padding: const EdgeInsets.all(10),
                          child: Column(children: [
                            Row(children: [
                              ReorderableDragStartListener(index: i, child: const Padding(padding: EdgeInsets.only(right: 8), child: Icon(Icons.drag_indicator))),
                              Expanded(
                                child: TextFormField(
                                  initialValue: '${x['label'] ?? ''}',
                                  decoration: InputDecoration(labelText: tr('Question ${i + 1}'), isDense: true),
                                  onChanged: (v) => x['label'] = v,
                                ),
                              ),
                              const SizedBox(width: 8),
                              SizedBox(
                                width: 190,
                                child: DropdownButtonFormField<String>(
                                  initialValue: '${x['type']}',
                                  isExpanded: true,
                                  decoration: const InputDecoration(isDense: true),
                                  items: [for (final t in _types.entries) DropdownMenuItem(value: t.key, child: Text(tr(t.value), overflow: TextOverflow.ellipsis))],
                                  onChanged: (v) => setState(() => x['type'] = v),
                                ),
                              ),
                              IconButton(tooltip: tr('Supprimer'), onPressed: () => setState(() => q.removeAt(i)), icon: const Icon(Icons.delete_outline)),
                            ]),
                            if (choice)
                              TextFormField(
                                initialValue: '${x['_opts'] ?? ''}',
                                minLines: 2,
                                maxLines: 6,
                                decoration: InputDecoration(labelText: tr('Choix proposés (un par ligne)'), isDense: true),
                                onChanged: (v) => x['_opts'] = v,
                              ),
                            Row(children: [
                              Switch(value: x['required'] == true, onChanged: (v) => setState(() => x['required'] = v)),
                              Text(tr('Réponse obligatoire')),
                            ]),
                          ]),
                        ),
                      );
                    },
                  ),
                ),
                Align(
                  alignment: Alignment.centerLeft,
                  child: PopupMenuButton<String>(
                    onSelected: (t) => setState(() => q.add({'type': t, 'label': '', 'required': false, '_opts': t == 'single' || t == 'multi' ? '${tr('Choix 1')}\n${tr('Choix 2')}' : ''})),
                    itemBuilder: (c) => [for (final t in _types.entries) PopupMenuItem(value: t.key, child: Text(tr(t.value)))],
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.add, color: YColors.blue), const SizedBox(width: 6), Text(tr('Ajouter une question'), style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w600))]),
                    ),
                  ),
                ),
              ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(onPressed: _busy || q == null ? null : _save, child: Text(tr('Enregistrer'))),
      ],
    );
  }
}

class _Results extends StatefulWidget {
  const _Results({required this.formId});

  final Object? formId;

  @override
  State<_Results> createState() => _ResultsState();
}

class _ResultsState extends State<_Results> {
  Map<String, dynamic>? _d;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('questionnaires/forms/${widget.formId}/results').then((r) {
      if (mounted) setState(() => _d = asMap((r as Map)['data']));
    }).catchError((Object e) {
      if (mounted) toast(context, errorText(e), error: true);
    });
  }

  Widget _bar(String label, num pct, String right, {Color color = YColors.blue}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          SizedBox(width: 170, child: Text(label, overflow: TextOverflow.ellipsis)),
          Expanded(child: LinearProgressIndicator(value: pct / 100, minHeight: 12, color: color, backgroundColor: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(6))),
          SizedBox(width: 90, child: Text(right, textAlign: TextAlign.right)),
        ]),
      );

  @override
  Widget build(BuildContext context) {
    final d = _d;
    return YAlertDialog(
      title: Text(d == null ? tr('Résultats') : verbatim('${d['form']['title']}')),
      content: SizedBox(
        width: 720,
        height: 600,
        child: d == null
            ? const Center(child: CircularProgressIndicator())
            : ListView(children: [
                Wrap(spacing: 10, runSpacing: 8, children: [
                  StatusChip(tr('${d['responses']} réponse(s)'), color: YColors.blue),
                  if (d['satisfaction'] != null) StatusChip(tr('${d['satisfaction']} % satisfaits'), color: YColors.success),
                  if (asMap(d['moods'])['unhappy'] != 0) StatusChip(tr('${asMap(d['moods'])['unhappy']} mécontent(s)'), color: YColors.danger),
                ]),
                const SizedBox(height: 12),
                for (final q in [for (final x in d['questions'] as List) asMap(x)])
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(verbatim('${q['label']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(tr('${q['answered']} réponse(s)'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                        const SizedBox(height: 8),
                        if (q['options'] != null)
                          for (final o in q['options'] as List) _bar(q['type'] == 'yesno' ? tr(o['label'] == 'oui' ? 'Oui' : 'Non') : verbatim('${o['label']}'), o['pct'] as num, '${o['count']} · ${o['pct']} %'),
                        if (q['type'] == 'rating') ...[
                          Text(tr('Moyenne : ${q['average'] ?? '—'} / 5 · ${q['satisfied'] ?? '—'} % de 4 ou 5'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          for (var i = 4; i >= 0; i--)
                            _bar('${i + 1} ★', q['answered'] == 0 ? 0 : (q['distribution'] as List)[i] / (q['answered'] as num) * 100, '${(q['distribution'] as List)[i]}',
                                color: i >= 3 ? YColors.success : (i == 2 ? const Color(0xFFD97706) : YColors.danger)),
                        ],
                        if (q['type'] == 'nps') ...[
                          Text(tr('NPS : ${q['nps'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 20)),
                          _bar(tr('Promoteurs (9-10)'), q['promoters'] as num, '${q['promoters']} %', color: YColors.success),
                          _bar(tr('Passifs (7-8)'), q['passives'] as num, '${q['passives']} %', color: const Color(0xFFD97706)),
                          _bar(tr('Détracteurs (0-6)'), q['detractors'] as num, '${q['detractors']} %', color: YColors.danger),
                        ],
                        if (q['type'] == 'number') Text(tr('Moyenne ${q['average'] ?? '—'} · min ${q['min'] ?? '—'} · max ${q['max'] ?? '—'}')),
                        if (q['answers'] != null)
                          for (final a in (q['answers'] as List).take(15))
                            Padding(padding: const EdgeInsets.symmetric(vertical: 3), child: Text(verbatim('« $a »'), style: const TextStyle(fontStyle: FontStyle.italic))),
                      ]),
                    ),
                  ),
              ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

class _Share extends StatelessWidget {
  const _Share({required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final url = '${row['url']}';
    final open = row['status'] == 'open';
    return YAlertDialog(
      title: Text(tr('Partager le questionnaire')),
      content: SizedBox(
        width: 420,
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          if (!open)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(tr('Le questionnaire n\'est pas ouvert : passez son état à « Ouvert » pour recevoir des réponses.'), style: const TextStyle(color: YColors.danger)),
            ),
          Container(color: Colors.white, padding: const EdgeInsets.all(10), child: SizedBox(width: 220, height: 220, child: CustomPaint(painter: _QrPainter(url)))),
          const SizedBox(height: 10),
          SelectableText(verbatim(url), textAlign: TextAlign.center),
          const SizedBox(height: 6),
          Text(tr('Imprimez le QR code (comptoir, ticket, table) ou envoyez le lien.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12), textAlign: TextAlign.center),
        ]),
      ),
      actions: [
        TextButton.icon(
          icon: const Icon(Icons.copy, size: 18),
          label: Text(tr('Copier le lien')),
          onPressed: () {
            Clipboard.setData(ClipboardData(text: url));
            toast(context, tr('Lien copié.'));
          },
        ),
        TextButton.icon(
          icon: const Icon(Icons.chat_outlined, size: 18),
          label: Text(tr('WhatsApp')),
          onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent('${tr('Votre avis nous intéresse :')} ${row['title']}\n$url')}'), mode: LaunchMode.externalApplication),
        ),
        TextButton.icon(icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Ouvrir')), onPressed: () => safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication)),
      ],
    );
  }
}

class _QrPainter extends CustomPainter {
  _QrPainter(this.data);

  final String data;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()..color = Colors.black;
    for (final e in Barcode.qrCode().make(data, width: size.width, height: size.height)) {
      if (e is BarcodeBar && e.black) canvas.drawRect(Rect.fromLTWH(e.left, e.top, e.width, e.height), p);
    }
  }

  @override
  bool shouldRepaint(covariant _QrPainter old) => old.data != data;
}

/// Saisie d'une réponse dans l'application (enquête sur papier, au comptoir, par téléphone).
class _AnswerDialog extends StatefulWidget {
  const _AnswerDialog({required this.formId, required this.title});

  final Object? formId;
  final String title;

  @override
  State<_AnswerDialog> createState() => _AnswerDialogState();
}

class _AnswerDialogState extends State<_AnswerDialog> {
  List<Map<String, dynamic>>? _q;
  final Map<String, dynamic> _a = {};
  Map<String, dynamic> _err = {};
  final _name = TextEditingController();
  final _phone = TextEditingController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('questionnaires/forms/${widget.formId}/questions').then((r) {
      if (mounted) setState(() => _q = [for (final x in ((r as Map)['data']['questions'] as List)) asMap(x)]);
    });
  }

  Widget _input(Map<String, dynamic> q) {
    final id = '${q['id']}';
    Widget scale(int from, int to) => Wrap(spacing: 4, runSpacing: 4, children: [
          for (var i = from; i <= to; i++) ChoiceChip(label: Text('$i'), selected: _a[id] == i, onSelected: (_) => setState(() => _a[id] = i)),
        ]);
    switch (q['type']) {
      case 'single':
        return Wrap(spacing: 6, runSpacing: 6, children: [for (final o in q['options'] as List) ChoiceChip(label: Text(verbatim('$o')), selected: _a[id] == o, onSelected: (_) => setState(() => _a[id] = o))]);
      case 'multi':
        final sel = (_a[id] as List?)?.cast<Object>() ?? <Object>[];
        return Wrap(spacing: 6, runSpacing: 6, children: [
          for (final o in q['options'] as List)
            FilterChip(label: Text(verbatim('$o')), selected: sel.contains(o), onSelected: (v) => setState(() => _a[id] = v ? [...sel, o] : sel.where((x) => x != o).toList())),
        ]);
      case 'yesno':
        return Wrap(spacing: 6, children: [for (final o in const ['oui', 'non']) ChoiceChip(label: Text(tr(o == 'oui' ? 'Oui' : 'Non')), selected: _a[id] == o, onSelected: (_) => setState(() => _a[id] = o))]);
      case 'rating':
        return scale(1, 5);
      case 'nps':
        return scale(0, 10);
      default:
        return TextFormField(
          maxLines: q['type'] == 'long' ? 3 : 1,
          keyboardType: q['type'] == 'number' ? TextInputType.number : (q['type'] == 'email' ? TextInputType.emailAddress : (q['type'] == 'phone' ? TextInputType.phone : TextInputType.text)),
          decoration: InputDecoration(isDense: true, hintText: q['type'] == 'date' ? tr('AAAA-MM-JJ') : null),
          onChanged: (v) => _a[id] = v,
        );
    }
  }

  Future<void> _send() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('questionnaires/forms/${widget.formId}/responses', {
        'answers': _a, if (_name.text.trim().isNotEmpty) 'name': _name.text.trim(), if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() => _err = e is ApiException ? asMap(e.errors) : {});
        toast(context, errorText(e), error: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final q = _q;
    return YAlertDialog(
      title: Text(verbatim(widget.title)),
      content: SizedBox(
        width: 560,
        height: 560,
        child: q == null
            ? const Center(child: CircularProgressIndicator())
            : ListView(children: [
                for (final x in q)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(verbatim('${x['label']}${x['required'] == true ? ' *' : ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 6),
                      _input(x),
                      if (_err[x['id']] != null) Text(tr('${_err[x['id']]}'), style: const TextStyle(color: YColors.danger, fontSize: 12)),
                    ]),
                  ),
                const Divider(),
                TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom du répondant (facultatif)'))),
                TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone (facultatif)'))),
              ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(onPressed: _busy || q == null ? null : _send, child: Text(tr('Enregistrer'))),
      ],
    );
  }
}
