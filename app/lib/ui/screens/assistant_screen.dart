import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Assistant IA : questions sur les données de la société (dans la limite des droits de l'utilisateur) et rédaction de messages.
class AssistantScreen extends StatefulWidget {
  const AssistantScreen({super.key});

  @override
  State<AssistantScreen> createState() => _AssistantScreenState();
}

class _AssistantScreenState extends State<AssistantScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 2, vsync: this);
  Map<String, dynamic>? _status;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('ia/status');
      if (mounted) setState(() => (_status = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _used(dynamic n) {
    if (n != null && _status != null) setState(() => _status!['used'] = n);
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _status == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_status == null) return const Center(child: CircularProgressIndicator());
    final s = _status!;
    final manage = context.read<Session>().can('ia.manage');
    final limit = toDouble(s['monthly_limit']).round();
    return Column(children: [
      PageHeader(
        title: tr('Assistant IA'),
        subtitle: tr('Posez vos questions sur les ventes, impayés, stock, trésorerie et dépenses · rédaction de relances et de messages'),
        actions: [
          if (s['configured'] == true) Chip(avatar: const Icon(Icons.bolt, size: 16), label: Text(tr(limit > 0 ? '${s['used']} / $limit ce mois' : '${s['used']} demandes ce mois'))),
          if (manage) IconButton(tooltip: tr('Paramètres de l\'assistant'), onPressed: _settings, icon: const Icon(Icons.tune)),
        ],
      ),
      if (s['configured'] != true)
        Padding(
          padding: const EdgeInsets.all(24),
          child: SectionCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('L\'assistant n\'est pas encore configuré'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
              const SizedBox(height: 6),
              Text(tr('Saisissez la clé de votre fournisseur d\'IA (Anthropic Claude, OpenAI, Mistral, DeepSeek…). Elle est chiffrée sur votre serveur ; seules les données nécessaires à chaque question lui sont envoyées.')),
              const SizedBox(height: 12),
              if (manage) FilledButton.icon(onPressed: _settings, icon: const Icon(Icons.key), label: Text(tr('Configurer'))) else Text(tr('Demandez à l\'administrateur de la configurer.')),
            ]),
          ),
        )
      else ...[
        TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Questions')), Tab(text: tr('Rédaction'))]),
        Expanded(
          child: TabBarView(controller: _tabs, children: [
            _Chat(suggestions: List<String>.from(s['suggestions'] as List? ?? const []), onUsed: _used),
            _Draft(kinds: asMap(s['drafts']), onUsed: _used),
          ]),
        ),
      ],
    ]);
  }

  Future<void> _settings() async {
    final s = _status!;
    var provider = '${s['own_key'] == true ? s['provider'] : 'anthropic'}';
    final model = TextEditingController(text: s['own_key'] == true ? '${s['model'] ?? ''}' : '');
    final base = TextEditingController(text: s['own_key'] == true ? '${s['base_url'] ?? ''}' : '');
    final key = TextEditingController();
    final limit = TextEditingController(text: s['own_key'] == true ? '${s['monthly_limit'] ?? 0}' : '');
    var enabled = s['enabled'] != false;
    await showFormDialog(context, title: tr('Paramètres de l\'assistant IA'), builder: (dc) {
      return StatefulBuilder(builder: (dc, set) {
        final hint = Theme.of(dc).hintColor;
        return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
          Text(tr(s['own_key'] == true ? 'Votre société utilise sa propre clé (${s['key_hint']}).' : 'Clé de la plateforme Yomara${s['configured'] == true ? ' (${s['monthly_limit']} demandes par mois)' : ' : non fournie'}. Vous pouvez saisir la vôtre.'),
              style: TextStyle(color: hint)),
          const SizedBox(height: 10),
          Field(
            label: tr('Fournisseur'),
            child: DropdownButtonFormField<String>(
              initialValue: provider,
              items: [
                DropdownMenuItem(value: 'anthropic', child: Text(tr('Anthropic (Claude)'))),
                DropdownMenuItem(value: 'openai', child: Text(tr('OpenAI ou compatible (Mistral, DeepSeek, Groq, Ollama…)'))),
              ],
              onChanged: (v) => set(() => provider = v ?? provider),
            ),
          ),
          Field(label: tr('Clé API'), child: TextField(controller: key, obscureText: true, decoration: InputDecoration(hintText: trn(s['own_key'] == true ? 'Laisser vide pour garder la clé actuelle' : 'sk-…')))),
          Field(label: tr('Modèle'), child: TextField(controller: model, decoration: InputDecoration(hintText: trn(provider == 'anthropic' ? 'claude-haiku-5-5 (par défaut)' : 'gpt-4o-mini, mistral-small-latest…')))),
          if (provider == 'openai') Field(label: tr('Adresse de l\'API (compatible OpenAI)'), child: TextField(controller: base, decoration: InputDecoration(hintText: tr('https://api.mistral.ai/v1 — vide : OpenAI')))),
          Field(label: tr('Limite de demandes par mois (0 = sans limite)'), child: TextField(controller: limit, keyboardType: TextInputType.number)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Assistant activé')), value: enabled, onChanged: (v) => set(() => enabled = v)),
          const SizedBox(height: 8),
          Wrap(alignment: WrapAlignment.end, spacing: 8, children: [
            if (s['own_key'] == true)
              TextButton(
                onPressed: () async {
                  await context.read<Session>().api.put('ia/settings', {'api_key': null, 'enabled': enabled});
                  if (dc.mounted) Navigator.pop(dc);
                  await _load();
                },
                child: Text(tr('Revenir à la clé de la plateforme')),
              ),
            SaveButton(
              onPressed: () async {
                try {
                  await context.read<Session>().api.put('ia/settings', {
                    'provider': provider,
                    'model': model.text.trim().isEmpty ? null : model.text.trim(),
                    'base_url': provider == 'openai' && base.text.trim().isNotEmpty ? base.text.trim() : null,
                    if (key.text.trim().isNotEmpty) 'api_key': key.text.trim(),
                    'monthly_limit': limit.text.trim().isEmpty ? null : int.tryParse(limit.text.trim()),
                    'enabled': enabled,
                  });
                  if (dc.mounted) Navigator.pop(dc);
                  await _load();
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ]),
        ]);
      });
    });
  }
}

// ---------------------------------------------------------------------------
// Questions
// ---------------------------------------------------------------------------

class _Chat extends StatefulWidget {
  const _Chat({required this.suggestions, required this.onUsed});

  final List<String> suggestions;
  final void Function(dynamic) onUsed;

  @override
  State<_Chat> createState() => _ChatState();
}

class _ChatState extends State<_Chat> {
  List<Map> _convs = const [];
  int? _conv;
  final List<Map<String, dynamic>> _msgs = [];
  final _input = TextEditingController();
  final _scroll = ScrollController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadConvs();
  }

  Future<void> _loadConvs() async {
    try {
      final r = await context.read<Session>().api.get('ia/conversations');
      if (mounted) setState(() => _convs = List<Map>.from(r['data'] as List));
    } catch (_) {}
  }

  Future<void> _open(int id) async {
    final r = await context.read<Session>().api.get('ia/conversations/$id');
    if (!mounted) return;
    setState(() {
      _conv = id;
      _msgs
        ..clear()
        ..addAll([for (final m in (r['data']['messages'] as List)) asMap(m)]);
    });
    _down();
  }

  void _down() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) _scroll.animateTo(_scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      });

  Future<void> _send([String? text]) async {
    final q = (text ?? _input.text).trim();
    if (q.isEmpty || _busy) return;
    _input.clear();
    setState(() {
      _busy = true;
      _msgs.add({'role': 'user', 'content': q});
    });
    _down();
    try {
      final r = await context.read<Session>().api.post('ia/ask', {'message': q, 'conversation_id': ?_conv});
      final d = asMap(r['data']);
      if (!mounted) return;
      setState(() {
        _conv = d['conversation_id'] as int?;
        _msgs.add({'role': 'assistant', 'content': d['answer'], 'tools': d['tools']});
      });
      widget.onUsed(d['used']);
      _loadConvs();
    } catch (e) {
      if (mounted) setState(() => _msgs.add({'role': 'error', 'content': errorText(e)}));
    } finally {
      if (mounted) setState(() => _busy = false);
      _down();
    }
  }

  static const _toolLabels = {
    'ventes_resume': 'ventes', 'ventes_par_mois': 'ventes par mois', 'meilleurs_articles': 'articles', 'meilleurs_clients': 'clients', 'factures_impayees': 'impayés',
    'rechercher_tiers': 'tiers', 'document': 'document', 'achats_resume': 'achats', 'stock_alertes': 'alertes de stock', 'stock_article': 'stock', 'tresorerie_soldes': 'trésorerie',
    'depenses': 'dépenses', 'agences_comparatif': 'agences',
  };

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.of(context).size.width >= 1000;
    final hint = Theme.of(context).hintColor;
    final chat = Column(children: [
      Expanded(
        child: _msgs.isEmpty
            ? ListView(padding: const EdgeInsets.all(24), children: [
                const Icon(Icons.auto_awesome, size: 40, color: YColors.blue),
                const SizedBox(height: 8),
                Text(tr('Que voulez-vous savoir ?'), textAlign: TextAlign.center, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4),
                Text(tr('L\'assistant lit vos données en direct, dans la limite de vos droits et de vos agences.'), textAlign: TextAlign.center, style: TextStyle(color: hint)),
                const SizedBox(height: 16),
                Wrap(alignment: WrapAlignment.center, spacing: 8, runSpacing: 8, children: [
                  for (final s in widget.suggestions) ActionChip(label: Text(tr(s)), onPressed: () => _send(s)),
                ]),
              ])
            : ListView.builder(
                controller: _scroll,
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                itemCount: _msgs.length + (_busy ? 1 : 0),
                itemBuilder: (c, i) {
                  if (i == _msgs.length) {
                    return const Align(alignment: Alignment.centerLeft, child: Padding(padding: EdgeInsets.all(12), child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4))));
                  }
                  final m = _msgs[i];
                  final mine = m['role'] == 'user';
                  final err = m['role'] == 'error';
                  final tools = List<String>.from(m['tools'] as List? ?? const []);
                  return Align(
                    alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 760),
                      child: Container(
                        margin: const EdgeInsets.only(bottom: 10),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: mine ? YColors.blue : (err ? YColors.danger.withValues(alpha: .08) : Theme.of(c).cardColor),
                          borderRadius: BorderRadius.circular(14),
                          border: mine ? null : Border.all(color: (err ? YColors.danger : hint).withValues(alpha: .2)),
                        ),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          if (mine) SelectableText(tr('${m['content']}'), style: const TextStyle(color: Colors.white)) else MiniMarkdown('${m['content']}'),
                          if (tools.isNotEmpty || (!mine && !err))
                            Padding(
                              padding: const EdgeInsets.only(top: 6),
                              child: Row(mainAxisSize: MainAxisSize.min, children: [
                                if (tools.isNotEmpty) Text(tr('Données : ${tools.map((t) => _toolLabels[t] ?? t).toSet().join(', ')}'), style: TextStyle(fontSize: 11.5, color: hint)),
                                if (!err)
                                  IconButton(
                                    visualDensity: VisualDensity.compact,
                                    tooltip: tr('Copier'),
                                    iconSize: 16,
                                    onPressed: () {
                                      Clipboard.setData(ClipboardData(text: '${m['content']}'));
                                      toast(c, tr('Réponse copiée.'));
                                    },
                                    icon: const Icon(Icons.copy),
                                  ),
                              ]),
                            ),
                        ]),
                      ),
                    ),
                  );
                },
              ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
        child: Row(children: [
          Expanded(
            child: TextField(
              controller: _input,
              minLines: 1,
              maxLines: 4,
              textInputAction: TextInputAction.send,
              onSubmitted: (_) => _send(),
              decoration: InputDecoration(hintText: tr('Ex. : quel client nous doit le plus ?')),
            ),
          ),
          const SizedBox(width: 8),
          IconButton.filled(onPressed: _busy ? null : _send, icon: const Icon(Icons.send)),
        ]),
      ),
    ]);
    if (!wide) return chat;
    return Row(children: [
      SizedBox(
        width: 260,
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(onPressed: () => setState(() { _conv = null; _msgs.clear(); }), icon: const Icon(Icons.add), label: Text(tr('Nouvelle conversation'))),
            ),
          ),
          Expanded(
            child: ListView(children: [
              for (final c in _convs)
                ListTile(
                  dense: true,
                  selected: c['id'] == _conv,
                  title: Text(tr('${c['title']}'), maxLines: 2, overflow: TextOverflow.ellipsis),
                  subtitle: Text(tr(dateFr('${c['updated_at']}'.substring(0, 10)))),
                  onTap: () => _open(c['id'] as int),
                  trailing: IconButton(
                    tooltip: tr('Supprimer'),
                    iconSize: 18,
                    onPressed: () async {
                      await context.read<Session>().api.delete('ia/conversations/${c['id']}');
                      if (c['id'] == _conv) setState(() { _conv = null; _msgs.clear(); });
                      _loadConvs();
                    },
                    icon: const Icon(Icons.delete_outline),
                  ),
                ),
            ]),
          ),
        ]),
      ),
      const VerticalDivider(width: 1),
      Expanded(child: chat),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Rédaction
// ---------------------------------------------------------------------------

class _Draft extends StatefulWidget {
  const _Draft({required this.kinds, required this.onUsed});

  final Map<String, dynamic> kinds;
  final void Function(dynamic) onUsed;

  @override
  State<_Draft> createState() => _DraftState();
}

class _DraftState extends State<_Draft> {
  String _kind = 'relance';
  String _tone = 'cordial';
  String _channel = 'email';
  final _doc = TextEditingController();
  final _instr = TextEditingController();
  final _out = TextEditingController();
  bool _busy = false;

  bool get _needsDoc => ['relance', 'envoi_devis', 'envoi_facture'].contains(_kind);

  Future<void> _go() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('ia/draft', {
        'kind': _kind,
        'tone': _tone,
        'channel': _channel,
        if (_doc.text.trim().isNotEmpty) 'document': _doc.text.trim(),
        'instructions': _instr.text.trim(),
      });
      _out.text = '${r['data']['text']}';
      widget.onUsed(r['data']['used']);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
      SectionCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(spacing: 12, runSpacing: 8, children: [
            SizedBox(
              width: 300,
              child: Field(
                label: tr('Texte à rédiger'),
                child: DropdownButtonFormField<String>(
                  initialValue: _kind,
                  items: [for (final e in widget.kinds.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                  onChanged: (v) => setState(() => _kind = v ?? _kind),
                ),
              ),
            ),
            SizedBox(
              width: 170,
              child: Field(
                label: tr('Ton'),
                child: DropdownButtonFormField<String>(
                  initialValue: _tone,
                  items: [
                    DropdownMenuItem(value: 'cordial', child: Text(tr('Cordial'))),
                    DropdownMenuItem(value: 'formel', child: Text(tr('Formel'))),
                    DropdownMenuItem(value: 'chaleureux', child: Text(tr('Chaleureux'))),
                    DropdownMenuItem(value: 'ferme', child: Text(tr('Ferme'))),
                  ],
                  onChanged: (v) => setState(() => _tone = v ?? _tone),
                ),
              ),
            ),
            SizedBox(
              width: 170,
              child: Field(
                label: tr('Canal'),
                child: DropdownButtonFormField<String>(
                  initialValue: _channel,
                  items: [DropdownMenuItem(value: 'email', child: Text(tr('E-mail'))), DropdownMenuItem(value: 'whatsapp', child: Text(tr('WhatsApp'))), DropdownMenuItem(value: 'sms', child: Text(tr('SMS')))],
                  onChanged: (v) => setState(() => _channel = v ?? _channel),
                ),
              ),
            ),
            if (_needsDoc) SizedBox(width: 220, child: Field(label: tr('N° du document'), child: TextField(controller: _doc, decoration: InputDecoration(hintText: tr('FAC-2026-00012'))))),
          ]),
          Field(label: tr('Consignes (facultatif)'), child: TextField(controller: _instr, minLines: 2, maxLines: 5, decoration: InputDecoration(hintText: tr('Ex. : rappeler que le client est fidèle, proposer un paiement en deux fois…')))),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton.icon(onPressed: _busy ? null : _go, icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.auto_awesome), label: Text(tr('Rédiger'))),
          ),
        ]),
      ),
      if (_out.text.isNotEmpty || _busy) ...[
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Proposition'),
          trailing: Wrap(children: [
            IconButton(
              tooltip: tr('Copier'),
              onPressed: () {
                Clipboard.setData(ClipboardData(text: _out.text));
                toast(context, tr('Texte copié.'));
              },
              icon: const Icon(Icons.copy),
            ),
            if (_channel == 'whatsapp')
              IconButton(tooltip: tr('Ouvrir WhatsApp'), onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent(_out.text)}'), mode: LaunchMode.externalApplication), icon: const Icon(Icons.chat)),
          ]),
          child: TextField(controller: _out, minLines: 6, maxLines: 20, decoration: InputDecoration(helperText: tr('Relisez et modifiez le texte avant de l\'envoyer.'))),
        ),
      ],
    ]);
  }
}

// ---------------------------------------------------------------------------
// Rendu simple des réponses : titres, listes, gras, tableaux
// ---------------------------------------------------------------------------

class MiniMarkdown extends StatelessWidget {
  const MiniMarkdown(this.text, {super.key});

  final String text;

  List<InlineSpan> _inline(String s, TextStyle base) {
    final spans = <InlineSpan>[];
    final re = RegExp(r'\*\*(.+?)\*\*');
    var i = 0;
    for (final m in re.allMatches(s)) {
      if (m.start > i) spans.add(TextSpan(text: s.substring(i, m.start), style: base));
      spans.add(TextSpan(text: m.group(1), style: base.copyWith(fontWeight: FontWeight.w800)));
      i = m.end;
    }
    if (i < s.length) spans.add(TextSpan(text: s.substring(i), style: base));
    return spans;
  }

  @override
  Widget build(BuildContext context) {
    final base = DefaultTextStyle.of(context).style.copyWith(fontSize: 14, height: 1.4);
    final lines = text.split('\n');
    final out = <Widget>[];
    var i = 0;
    while (i < lines.length) {
      final l = lines[i].trimRight();
      if (l.trim().startsWith('|')) {
        final rows = <List<String>>[];
        while (i < lines.length && lines[i].trim().startsWith('|')) {
          final cells = lines[i].trim().replaceAll(RegExp(r'^\||\|$'), '').split('|').map((c) => c.trim()).toList();
          if (!cells.every((c) => RegExp(r'^:?-{2,}:?$').hasMatch(c))) rows.add(cells);
          i++;
        }
        if (rows.isNotEmpty) {
          final n = rows.map((r) => r.length).reduce((a, b) => a > b ? a : b);
          out.add(Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Table(
                defaultColumnWidth: const IntrinsicColumnWidth(),
                border: TableBorder.all(color: Theme.of(context).dividerColor),
                children: [
                  for (var r = 0; r < rows.length; r++)
                    TableRow(
                      decoration: r == 0 ? BoxDecoration(color: YColors.blue.withValues(alpha: .07)) : null,
                      children: [
                        for (var c = 0; c < n; c++)
                          Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
                            child: Text.rich(TextSpan(children: _inline(c < rows[r].length ? rows[r][c] : '', base.copyWith(fontWeight: r == 0 ? FontWeight.w700 : null, fontSize: 13)))),
                          ),
                      ],
                    ),
                ],
              ),
            ),
          ));
        }
        continue;
      }
      if (l.startsWith('#')) {
        out.add(Padding(padding: const EdgeInsets.only(top: 6, bottom: 2), child: Text(tr(l.replaceFirst(RegExp(r'^#+\s*'), '')), style: base.copyWith(fontWeight: FontWeight.w800, fontSize: 15))));
      } else if (RegExp(r'^\s*([-*•]|\d+[.)])\s+').hasMatch(l)) {
        final m = RegExp(r'^(\s*)([-*•]|\d+[.)])\s+(.*)$').firstMatch(l)!;
        final bullet = RegExp(r'\d').hasMatch(m.group(2)!) ? m.group(2)! : '•';
        out.add(Padding(
          padding: EdgeInsets.only(left: 4.0 + m.group(1)!.length * 6, top: 2),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SizedBox(width: 18, child: Text(tr(bullet), style: base)),
            Expanded(child: Text.rich(TextSpan(children: _inline(m.group(3)!, base)))),
          ]),
        ));
      } else if (l.trim().isEmpty) {
        out.add(const SizedBox(height: 6));
      } else {
        out.add(Text.rich(TextSpan(children: _inline(l, base))));
      }
      i++;
    }
    return SelectionArea(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: out));
  }
}
