import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/json.dart';
import '../../files/save_file.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/qr_view.dart';
import '../widgets/report_charts.dart';

import 'package:yomara_erp/core/safe_launch.dart';
Color _hex(dynamic v, [Color f = YColors.blue]) {
  final n = int.tryParse('${v ?? ''}'.replaceAll('#', ''), radix: 16);
  return n == null ? f : Color(0xFF000000 | n);
}

const _kinds = {
  'short': ('Lien court', Icons.link, 'Une adresse courte à votre nom, qui mène à une seule page.'),
  'page': ('Smartlink : page', Icons.view_agenda_outlined, 'Une page avec toutes vos plateformes (Spotify, YouTube, WhatsApp, boutique…).'),
  'smart': ('Smartlink : redirection', Icons.alt_route, 'Chaque visiteur va au bon endroit selon son appareil ou son pays.'),
};

/// Liens courts, smartlinks et QR codes, avec statistiques des clics.
class LiensScreen extends StatefulWidget {
  const LiensScreen({super.key});

  @override
  State<LiensScreen> createState() => _LiensScreenState();
}

class _LiensScreenState extends State<LiensScreen> {
  Map<String, dynamic>? _ov;
  int _rev = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = asMap(await context.read<Session>().api.get('liens/overview'));
      if (mounted) setState(() => (_ov = d, _rev++));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('liens.manage');
    final t = asMap(_ov?['totals']);
    return DefaultTabController(
      length: 3,
      child: Column(children: [
        PageHeader(title: tr('Liens & QR codes'), subtitle: tr('Liens courts, smartlinks et QR codes, avec chaque clic compté'), icon: Icons.link),
        if (_ov != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: StatStrip(tiles: [
              StatTile(label: tr('Liens'), value: '${t['links'] ?? 0}', icon: Icons.link),
              StatTile(label: tr('Clics'), value: number(t['clicks'] as num? ?? 0), icon: Icons.ads_click, color: YColors.success),
              StatTile(label: tr('Visiteurs uniques'), value: number(t['uniques'] as num? ?? 0), icon: Icons.people_outline, color: const Color(0xFF9333EA)),
              StatTile(label: tr('QR codes'), value: '${t['qrs'] ?? 0}', icon: Icons.qr_code_2, color: YColors.warning),
            ]),
          ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Liens')), Tab(text: tr('QR codes')), Tab(text: tr('Statistiques'))]),
        Expanded(
          child: _ov == null
              ? const Center(child: CircularProgressIndicator())
              : TabBarView(physics: const NeverScrollableScrollPhysics(), children: [
                  _LinksTab(key: ValueKey('l$_rev'), ov: _ov!, manage: manage, onChanged: _load),
                  _QrTab(key: ValueKey('q$_rev'), ov: _ov!, manage: manage, onChanged: _load),
                  _StatsTab(key: ValueKey('s$_rev')),
                ]),
        ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Liens
// ---------------------------------------------------------------------------

class _LinksTab extends StatefulWidget {
  const _LinksTab({super.key, required this.ov, required this.manage, required this.onChanged});
  final Map<String, dynamic> ov;
  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_LinksTab> createState() => _LinksTabState();
}

class _LinksTabState extends State<_LinksTab> {
  List<Map<String, dynamic>>? _rows;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('liens/links', query: {if (_q.isNotEmpty) 'q': _q});
      if (mounted) setState(() => _rows = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _edit([Map<String, dynamic>? l]) async {
    final ok = await Navigator.of(context).push<bool>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => LinkEditor(link: l, ov: widget.ov)));
    if (ok == true) {
      _load();
      widget.onChanged();
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: widget.manage ? FloatingActionButton.extended(onPressed: () => _edit(), icon: const Icon(Icons.add_link), label: Text(tr('Nouveau lien'))) : null,
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 4),
          child: SearchField(hint: tr('Rechercher un lien, un alias, une adresse…'), onChanged: (v) {
            _q = v;
            _load();
          }),
        ),
        Expanded(
          child: rows == null
              ? const Center(child: CircularProgressIndicator())
              : rows.isEmpty
                  ? EmptyState(
                      icon: Icons.link,
                      title: tr('Aucun lien pour l\'instant'),
                      message: tr('Raccourcissez une adresse, ou créez un smartlink qui réunit toutes vos plateformes.'),
                      action: widget.manage ? FilledButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.add_link), label: Text(tr('Créer mon premier lien'))) : null,
                    )
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView(padding: const EdgeInsets.fromLTRB(16, 6, 16, 90), children: [
                        for (final (i, l) in rows.indexed) AnimatedFadeIn(index: i, child: _LinkCard(l: l, manage: widget.manage, onEdit: () => _edit(l), onChanged: _load)),
                      ]),
                    ),
        ),
      ]),
    );
  }
}

class _LinkCard extends StatelessWidget {
  const _LinkCard({required this.l, required this.manage, required this.onEdit, required this.onChanged});
  final Map<String, dynamic> l;
  final bool manage;
  final VoidCallback onEdit;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final k = _kinds['${l['kind']}'] ?? _kinds['short']!;
    final url = '${l['short_url']}';
    final page = asMap(l['page']);
    final dest = l['kind'] == 'page' ? '${(page['buttons'] as List? ?? []).length} plateforme(s)' : '${l['target_url'] ?? ''}';
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 5),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
            child: Icon(k.$2, color: YColors.blue),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Flexible(child: Text(verbatim('${l['title'] ?? l['slug']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5))),
                const SizedBox(width: 8),
                StatusChip(tr(k.$1), color: YColors.blue),
                if (l['has_password'] == true) const Padding(padding: EdgeInsets.only(left: 6), child: Icon(Icons.lock_outline, size: 16)),
                if (l['active'] != true) Padding(padding: const EdgeInsets.only(left: 6), child: StatusChip(tr('Désactivé'), color: YColors.muted)),
              ]),
              const SizedBox(height: 4),
              InkWell(
                onTap: () {
                  Clipboard.setData(ClipboardData(text: url));
                  toast(context, tr('Lien copié : $url'));
                },
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Flexible(child: Text(url.replaceFirst(RegExp(r'^https?://'), ''), style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w700), overflow: TextOverflow.ellipsis)),
                  const SizedBox(width: 6),
                  const Icon(Icons.copy, size: 14, color: YColors.blue),
                ]),
              ),
              const SizedBox(height: 2),
              Text(verbatim(dest), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
              const SizedBox(height: 8),
              Wrap(spacing: 14, children: [
                _metric(Icons.ads_click, '${l['clicks']} clic(s)'),
                _metric(Icons.person_outline, '${l['uniques']} unique(s)'),
                if (l['last_click_at'] != null) _metric(Icons.schedule, 'Dernier : ${DateFormat('d MMM HH:mm', 'fr').format(DateTime.parse('${l['last_click_at']}').toLocal())}'),
              ]),
            ]),
          ),
          PopupMenuButton<String>(
            onSelected: (v) async {
              final api = context.read<Session>().api;
              switch (v) {
                case 'copy':
                  Clipboard.setData(ClipboardData(text: url));
                  toast(context, tr('Lien copié.'));
                case 'qr':
                  showQrShareDialog(context, data: '$url?q=1', title: '${l['title'] ?? l['slug']}', subtitle: tr('Les scans de ce QR code sont comptés à part.'));
                case 'open':
                  safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
                case 'stats':
                  _openStats(context, l, url);
                case 'edit':
                  onEdit();
                case 'delete':
                  if (!await confirm(context, tr('Supprimer ce lien ?'), tr('Les personnes qui l\'ouvriront tomberont sur une page introuvable.'), danger: true)) return;
                  try {
                    await api.delete('liens/links/${l['id']}');
                    onChanged();
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
              }
            },
            itemBuilder: (_) => [
              PopupMenuItem(value: 'copy', child: ListTile(leading: const Icon(Icons.copy), title: Text(tr('Copier le lien')), dense: true)),
              PopupMenuItem(value: 'qr', child: ListTile(leading: const Icon(Icons.qr_code_2), title: Text(tr('QR code')), dense: true)),
              PopupMenuItem(value: 'stats', child: ListTile(leading: const Icon(Icons.insights), title: Text(tr('Statistiques')), dense: true)),
              PopupMenuItem(value: 'open', child: ListTile(leading: const Icon(Icons.open_in_new), title: Text(tr('Ouvrir')), dense: true)),
              if (manage) PopupMenuItem(value: 'edit', child: ListTile(leading: const Icon(Icons.edit_outlined), title: Text(tr('Modifier')), dense: true)),
              if (manage) PopupMenuItem(value: 'delete', child: ListTile(leading: const Icon(Icons.delete_outline, color: YColors.danger), title: Text(tr('Supprimer')), dense: true)),
            ],
          ),
        ]),
      ),
    );
  }

  Widget _metric(IconData i, String t) => Row(mainAxisSize: MainAxisSize.min, children: [Icon(i, size: 15, color: YColors.muted), const SizedBox(width: 4), Text(tr(t), style: const TextStyle(fontSize: 12.5))]);
}

/// Création / modification d'un lien : court, smartlink page ou smartlink de redirection.
class LinkEditor extends StatefulWidget {
  const LinkEditor({super.key, this.link, required this.ov});
  final Map<String, dynamic>? link;
  final Map<String, dynamic> ov;

  @override
  State<LinkEditor> createState() => _LinkEditorState();
}

class _LinkEditorState extends State<LinkEditor> {
  late String _kind = '${widget.link?['kind'] ?? 'short'}';
  late final Map<String, dynamic> _l = widget.link ?? {};
  late final _title = TextEditingController(text: '${_l['title'] ?? ''}');
  late final _target = TextEditingController(text: '${_l['target_url'] ?? ''}');
  late final _slug = TextEditingController(text: '${_l['slug'] ?? ''}');
  late final _ios = TextEditingController(text: '${asMap(asMap(_l['rules'])['devices'])['ios'] ?? ''}');
  late final _android = TextEditingController(text: '${asMap(asMap(_l['rules'])['devices'])['android'] ?? ''}');
  late final _desktop = TextEditingController(text: '${asMap(asMap(_l['rules'])['devices'])['desktop'] ?? ''}');
  late final List<(TextEditingController, TextEditingController)> _countries = [
    for (final e in asMap(asMap(_l['rules'])['countries']).entries) (TextEditingController(text: e.key), TextEditingController(text: '${e.value}')),
  ];
  late final _subtitle = TextEditingController(text: '${asMap(_l['page'])['subtitle'] ?? ''}');
  late final _description = TextEditingController(text: '${asMap(_l['page'])['description'] ?? ''}');
  late final _cover = TextEditingController(text: '${asMap(_l['page'])['cover_url'] ?? ''}');
  late String _color = '${asMap(_l['page'])['color'] ?? '#0A1633'}';
  late final List<Map<String, dynamic>> _buttons = [
    for (final b in (asMap(_l['page'])['buttons'] as List? ?? const [])) {'platform': '${asMap(b)['platform']}', 'url': TextEditingController(text: '${asMap(b)['url']}'), 'label': TextEditingController(text: '${asMap(b)['label']}')},
  ];
  final _password = TextEditingController();
  late bool _removePassword = false;
  late final _max = TextEditingController(text: '${_l['max_clicks'] ?? ''}');
  late DateTime? _expires = DateTime.tryParse('${_l['expires_at'] ?? ''}');
  late bool _cloak = _l['cloak'] == true;
  late bool _active = _l['active'] != false;
  late final _utmSource = TextEditingController(text: '${asMap(_l['utm'])['utm_source'] ?? ''}');
  late final _utmCampaign = TextEditingController(text: '${asMap(_l['utm'])['utm_campaign'] ?? ''}');
  bool _busy = false;
  Timer? _debounce;

  List<Map<String, dynamic>> get _platforms => [for (final p in (widget.ov['platforms'] as List? ?? const [])) asMap(p)];

  @override
  void initState() {
    super.initState();
    if (_buttons.isEmpty) _addButton();
  }

  void _addButton() => _buttons.add({'platform': 'website', 'url': TextEditingController(), 'label': TextEditingController()});

  /// Adresse collée : plateforme reconnue, et titre / image de la page proposés.
  void _onTarget(String v) {
    _debounce?.cancel();
    if (v.trim().length < 8 || _title.text.isNotEmpty) return;
    _debounce = Timer(const Duration(milliseconds: 700), () async {
      try {
        final r = asMap(await context.read<Session>().api.post('liens/preview', {'url': v.trim()}));
        if (!mounted) return;
        setState(() {
          if (_title.text.isEmpty && r['title'] != null) _title.text = '${r['title']}';
          if (_kind == 'page' && _cover.text.isEmpty && r['image'] != null) _cover.text = '${r['image']}';
        });
      } catch (_) {}
    });
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final body = {
      'kind': _kind,
      'title': _title.text.trim(),
      'target_url': _target.text.trim(),
      if (_slug.text.trim().isNotEmpty) 'slug': _slug.text.trim(),
      if (_kind == 'smart')
        'rules': {
          'devices': {'ios': _ios.text.trim(), 'android': _android.text.trim(), 'desktop': _desktop.text.trim()},
          'countries': {for (final c in _countries) if (c.$1.text.trim().length == 2) c.$1.text.trim().toUpperCase(): c.$2.text.trim()},
        },
      if (_kind == 'page')
        'page': {
          'subtitle': _subtitle.text.trim(),
          'description': _description.text.trim(),
          'cover_url': _cover.text.trim(),
          'color': _color,
          'buttons': [
            for (final b in _buttons)
              if ((b['url'] as TextEditingController).text.trim().isNotEmpty) {'platform': b['platform'], 'url': (b['url'] as TextEditingController).text.trim(), 'label': (b['label'] as TextEditingController).text.trim()},
          ],
        },
      if (_password.text.isNotEmpty) 'password': _password.text,
      if (_removePassword) 'password': null,
      'max_clicks': int.tryParse(_max.text),
      'expires_at': _expires?.toIso8601String(),
      'cloak': _cloak,
      'active': _active,
      'utm': {if (_utmSource.text.trim().isNotEmpty) 'utm_source': _utmSource.text.trim(), if (_utmCampaign.text.trim().isNotEmpty) 'utm_campaign': _utmCampaign.text.trim()},
    };
    try {
      final api = context.read<Session>().api;
      final r = asMap(widget.link == null ? await api.post('liens/links', body) : await api.put('liens/links/${widget.link!['id']}', body));
      if (!mounted) return;
      Clipboard.setData(ClipboardData(text: '${r['short_url']}'));
      toast(context, tr('Lien enregistré et copié : ${r['short_url']}'));
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final base = '${widget.ov['base_url'] ?? ''}'.replaceFirst(RegExp(r'^https?://'), '');
    return Scaffold(
      appBar: AppBar(
        title: Text(tr(widget.link == null ? 'Nouveau lien' : 'Modifier le lien')),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 12),
            child: FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.check), label: Text(tr('Enregistrer'))),
          ),
        ],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 760),
          child: ListView(padding: const EdgeInsets.all(16), children: [
            // Type de lien : trois cartes
            LayoutBuilder(builder: (context, c) {
              final w = c.maxWidth > 600 ? (c.maxWidth - 20) / 3 : c.maxWidth;
              return Wrap(spacing: 10, runSpacing: 10, children: [
                for (final e in _kinds.entries)
                  SizedBox(
                    width: w,
                    child: InkWell(
                      borderRadius: BorderRadius.circular(16),
                      onTap: () => setState(() => _kind = e.key),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(16),
                          color: _kind == e.key ? YColors.blue.withValues(alpha: .08) : theme.cardColor,
                          border: Border.all(color: _kind == e.key ? YColors.blue : theme.dividerColor.withValues(alpha: .5), width: _kind == e.key ? 2 : 1),
                        ),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Icon(e.value.$2, color: _kind == e.key ? YColors.blue : theme.hintColor),
                          const SizedBox(height: 8),
                          Text(tr(e.value.$1), style: const TextStyle(fontWeight: FontWeight.w800)),
                          const SizedBox(height: 4),
                          Text(tr(e.value.$3), style: TextStyle(color: theme.hintColor, fontSize: 12.5, height: 1.35)),
                        ]),
                      ),
                    ),
                  ),
              ]);
            }),
            const SizedBox(height: 18),
            if (_kind != 'page')
              TextField(
                controller: _target,
                keyboardType: TextInputType.url,
                onChanged: _onTarget,
                decoration: InputDecoration(labelText: tr(_kind == 'smart' ? 'Adresse par défaut' : 'Adresse de destination'), hintText: 'https://…', prefixIcon: const Icon(Icons.public)),
              ),
            const SizedBox(height: 12),
            TextField(controller: _title, decoration: InputDecoration(labelText: tr(_kind == 'page' ? 'Titre de la page (ex. nom du single, de l\'évènement)' : 'Nom du lien (pour vous)'))),
            const SizedBox(height: 12),
            TextField(
              controller: _slug,
              decoration: InputDecoration(labelText: tr('Alias (facultatif)'), prefixText: '$base/', hintText: tr('promo-noel'), helperText: tr('Laissez vide pour un alias généré automatiquement.')),
            ),
            if (_kind == 'smart') ...[
              const SizedBox(height: 22),
              Text(tr('Selon l\'appareil'), style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              TextField(controller: _ios, decoration: InputDecoration(labelText: tr('iPhone / iPad (ex. App Store)'), prefixIcon: const Icon(Icons.phone_iphone))),
              const SizedBox(height: 8),
              TextField(controller: _android, decoration: InputDecoration(labelText: tr('Android (ex. Google Play)'), prefixIcon: const Icon(Icons.android))),
              const SizedBox(height: 8),
              TextField(controller: _desktop, decoration: InputDecoration(labelText: tr('Ordinateur'), prefixIcon: const Icon(Icons.computer))),
              const SizedBox(height: 18),
              Row(children: [
                Expanded(child: Text(tr('Selon le pays'), style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800))),
                TextButton.icon(onPressed: () => setState(() => _countries.add((TextEditingController(), TextEditingController()))), icon: const Icon(Icons.add), label: Text(tr('Ajouter un pays'))),
              ]),
              for (final (i, c) in _countries.indexed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(children: [
                    SizedBox(width: 90, child: TextField(controller: c.$1, maxLength: 2, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(labelText: tr('Pays'), hintText: 'SN', counterText: ''))),
                    const SizedBox(width: 8),
                    Expanded(child: TextField(controller: c.$2, decoration: InputDecoration(labelText: tr('Adresse pour ce pays')))),
                    IconButton(onPressed: () => setState(() => _countries.removeAt(i)), icon: const Icon(Icons.close)),
                  ]),
                ),
              Text(tr('Le pays est connu quand votre site passe par Cloudflare ; sinon il est déduit de la langue du téléphone.'), style: TextStyle(color: theme.hintColor, fontSize: 12)),
            ],
            if (_kind == 'page') ...[
              const SizedBox(height: 12),
              TextField(controller: _subtitle, decoration: InputDecoration(labelText: tr('Sous-titre (ex. nom de l\'artiste, date)'))),
              const SizedBox(height: 12),
              TextField(controller: _description, maxLines: 3, decoration: InputDecoration(labelText: tr('Description (facultatif)'))),
              const SizedBox(height: 12),
              TextField(controller: _cover, decoration: InputDecoration(labelText: tr('Image de couverture (adresse)'), prefixIcon: const Icon(Icons.image_outlined))),
              const SizedBox(height: 12),
              Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                Text(tr('Couleur :')),
                for (final c in ['#0A1633', '#6C3CE1', '#E1306C', '#1DB954', '#F5A000', '#E5484D', '#0EA5E9', '#111111'])
                  InkWell(
                    onTap: () => setState(() => _color = c),
                    child: Container(width: 30, height: 30, decoration: BoxDecoration(color: _hex(c), shape: BoxShape.circle, border: Border.all(color: _color == c ? YColors.yellow : Colors.transparent, width: 3))),
                  ),
              ]),
              const SizedBox(height: 18),
              Row(children: [
                Expanded(child: Text(tr('Boutons de la page'), style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800))),
                TextButton.icon(onPressed: () => setState(_addButton), icon: const Icon(Icons.add), label: Text(tr('Ajouter une plateforme'))),
              ]),
              for (final (i, b) in _buttons.indexed)
                Card(
                  margin: const EdgeInsets.symmetric(vertical: 4),
                  child: Padding(
                    padding: const EdgeInsets.all(10),
                    child: Column(children: [
                      Row(children: [
                        Container(width: 14, height: 14, decoration: BoxDecoration(color: _hex(_platforms.firstWhere((p) => p['key'] == b['platform'], orElse: () => const {})['color']), shape: BoxShape.circle)),
                        const SizedBox(width: 10),
                        Expanded(
                          child: DropdownButtonFormField<String>(
                            initialValue: b['platform'] as String,
                            isDense: true,
                            decoration: InputDecoration(labelText: tr('Plateforme'), isDense: true),
                            items: [for (final p in _platforms) DropdownMenuItem(value: '${p['key']}', child: Text('${p['label']}'))],
                            onChanged: (v) => setState(() => b['platform'] = v ?? 'website'),
                          ),
                        ),
                        IconButton(tooltip: tr('Monter'), onPressed: i == 0 ? null : () => setState(() => _buttons.insert(i - 1, _buttons.removeAt(i))), icon: const Icon(Icons.arrow_upward, size: 18)),
                        IconButton(tooltip: tr('Retirer'), onPressed: () => setState(() => _buttons.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
                      ]),
                      const SizedBox(height: 8),
                      TextField(
                        controller: b['url'] as TextEditingController,
                        keyboardType: TextInputType.url,
                        decoration: InputDecoration(labelText: tr('Adresse'), isDense: true),
                        onChanged: (v) {
                          // Plateforme reconnue d'après l'adresse
                          final h = v.toLowerCase();
                          final guess = {
                            'spotify': 'spotify', 'music.apple': 'apple_music', 'music.youtube': 'youtube_music', 'youtu': 'youtube', 'deezer': 'deezer', 'audiomack': 'audiomack',
                            'boomplay': 'boomplay', 'soundcloud': 'soundcloud', 'tiktok': 'tiktok', 'instagram': 'instagram', 'facebook': 'facebook', 'wa.me': 'whatsapp', 't.me': 'telegram', 'sensya': 'sensya',
                            'apps.apple': 'app_store', 'play.google': 'google_play',
                          }.entries.where((e) => h.contains(e.key)).map((e) => e.value).firstOrNull;
                          if (guess != null && guess != b['platform']) setState(() => b['platform'] = guess);
                        },
                      ),
                      const SizedBox(height: 8),
                      TextField(controller: b['label'] as TextEditingController, decoration: InputDecoration(labelText: tr('Libellé (facultatif)'), isDense: true)),
                    ]),
                  ),
                ),
            ],
            const SizedBox(height: 18),
            Card(
              child: ExpansionTile(
                leading: const Icon(Icons.tune),
                title: Text(tr('Options avancées'), style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text(tr('Mot de passe, date de fin, visites maximum, lien masqué, UTM'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
                childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                children: [
                  SwitchListTile(contentPadding: EdgeInsets.zero, value: _active, onChanged: (v) => setState(() => _active = v), title: Text(tr('Lien actif'))),
                  if (_kind != 'page')
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _cloak,
                      onChanged: (v) => setState(() => _cloak = v),
                      title: Text(tr('Masquer la destination')),
                      subtitle: Text(tr('La page s\'affiche sous votre lien court. Certains sites (Google, Facebook…) l\'interdisent.')),
                    ),
                  TextField(
                    controller: _password,
                    obscureText: true,
                    decoration: InputDecoration(labelText: tr(_l['has_password'] == true ? 'Nouveau mot de passe (vide = garder l\'actuel)' : 'Mot de passe (facultatif)'), prefixIcon: const Icon(Icons.lock_outline)),
                  ),
                  if (_l['has_password'] == true)
                    CheckboxListTile(contentPadding: EdgeInsets.zero, value: _removePassword, onChanged: (v) => setState(() => _removePassword = v ?? false), title: Text(tr('Retirer le mot de passe'))),
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(child: TextField(controller: _max, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Visites maximum')))),
                    const SizedBox(width: 10),
                    Expanded(
                      child: InkWell(
                        onTap: () async {
                          final d = await pickDate(context, initialDate: _expires ?? DateTime.now().add(const Duration(days: 30)), firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 3650)));
                          setState(() => _expires = d);
                        },
                        child: InputDecorator(decoration: InputDecoration(labelText: tr('Date de fin')), child: Text(_expires == null ? tr('Aucune') : DateFormat('d MMM yyyy', 'fr').format(_expires!))),
                      ),
                    ),
                  ]),
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(child: TextField(controller: _utmSource, decoration: const InputDecoration(labelText: 'utm_source', hintText: 'whatsapp'))),
                    const SizedBox(width: 10),
                    Expanded(child: TextField(controller: _utmCampaign, decoration: const InputDecoration(labelText: 'utm_campaign', hintText: 'soldes-noel'))),
                  ]),
                ],
              ),
            ),
          ]),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// QR codes
// ---------------------------------------------------------------------------

class _QrTab extends StatefulWidget {
  const _QrTab({super.key, required this.ov, required this.manage, required this.onChanged});
  final Map<String, dynamic> ov;
  final bool manage;
  final VoidCallback onChanged;

  @override
  State<_QrTab> createState() => _QrTabState();
}

class _QrTabState extends State<_QrTab> {
  List<Map<String, dynamic>>? _rows;
  final Map<int, Uint8List> _img = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final r = await api.get('liens/qrs');
      final rows = [for (final x in (r['data'] as List)) asMap(x)];
      if (mounted) setState(() => _rows = rows);
      for (final q in rows.take(40)) {
        final id = q['id'] as int;
        if (_img.containsKey(id)) continue;
        final b = await api.download('liens/qrs/$id/png', query: {'size': '360'});
        if (mounted) setState(() => _img[id] = Uint8List.fromList(b));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _edit([Map<String, dynamic>? q]) async {
    final ok = await Navigator.of(context).push<bool>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => QrEditor(qr: q, types: asMap(widget.ov['qr_types']))));
    if (ok == true) {
      if (q != null) _img.remove(q['id']);
      _load();
      widget.onChanged();
    }
  }

  Future<void> _download(Map<String, dynamic> q, String fmt) async {
    try {
      final b = await context.read<Session>().api.download('liens/qrs/${q['id']}/$fmt', query: {'size': '1600'});
      await saveFile('qr-${'${q['name']}'.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]+'), '-')}.$fmt', b, mime: fmt == 'svg' ? 'image/svg+xml' : 'image/png');
      if (mounted) toast(context, tr('QR code enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: widget.manage ? FloatingActionButton.extended(onPressed: () => _edit(), icon: const Icon(Icons.qr_code_2), label: Text(tr('Nouveau QR code'))) : null,
      body: rows == null
          ? const Center(child: CircularProgressIndicator())
          : rows.isEmpty
              ? EmptyState(
                  icon: Icons.qr_code_2,
                  title: tr('Aucun QR code'),
                  message: tr('Site, WhatsApp, Wi-Fi de la boutique, carte de visite, paiement… à vos couleurs, avec votre logo.'),
                  action: widget.manage ? FilledButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.qr_code_2), label: Text(tr('Créer un QR code'))) : null,
                )
              : LayoutBuilder(builder: (context, c) {
                  final cols = (c.maxWidth / 230).floor().clamp(2, 6);
                  return GridView.count(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 90),
                    crossAxisCount: cols,
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                    childAspectRatio: .72,
                    children: [
                      for (final (i, q) in rows.indexed)
                        AnimatedFadeIn(
                          index: i,
                          child: Card(
                            margin: EdgeInsets.zero,
                            clipBehavior: Clip.antiAlias,
                            child: InkWell(
                              onTap: widget.manage ? () => _edit(q) : null,
                              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                                Expanded(
                                  child: Container(
                                    color: Colors.white,
                                    padding: const EdgeInsets.all(10),
                                    child: _img[q['id']] != null ? Image.memory(_img[q['id']]!, fit: BoxFit.contain) : const Center(child: CircularProgressIndicator(strokeWidth: 2)),
                                  ),
                                ),
                                Padding(
                                  padding: const EdgeInsets.fromLTRB(12, 10, 4, 8),
                                  child: Row(children: [
                                    Expanded(
                                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                        Text(verbatim('${q['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800)),
                                        Text(tr('${q['type_label']}${q['dynamic'] == true ? ' · ${q['scans']} scan(s)' : ''}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                                      ]),
                                    ),
                                    PopupMenuButton<String>(
                                      onSelected: (v) async {
                                        if (v == 'png' || v == 'svg') return _download(q, v);
                                        if (v == 'delete' && await confirm(context, tr('Supprimer ce QR code ?'), tr('Les QR codes déjà imprimés continueront de fonctionner s\'ils ne sont pas dynamiques.'), danger: true)) {
                                          if (!context.mounted) return;
                                          await context.read<Session>().api.delete('liens/qrs/${q['id']}');
                                          _load();
                                        }
                                      },
                                      itemBuilder: (_) => [
                                        PopupMenuItem(value: 'png', child: Text(tr('Télécharger en PNG'))),
                                        PopupMenuItem(value: 'svg', child: Text(tr('Télécharger en SVG (impression)'))),
                                        if (widget.manage) PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'))),
                                      ],
                                    ),
                                  ]),
                                ),
                              ]),
                            ),
                          ),
                        ),
                    ],
                  );
                }),
    );
  }
}

/// Création d'un QR code : type de contenu, champs, style, aperçu immédiat.
class QrEditor extends StatefulWidget {
  const QrEditor({super.key, this.qr, required this.types});
  final Map<String, dynamic>? qr;
  final Map<String, dynamic> types;

  @override
  State<QrEditor> createState() => _QrEditorState();
}

class _QrEditorState extends State<QrEditor> {
  static const _fields = {
    'url': [('url', 'Adresse du site', TextInputType.url)],
    'payment': [('url', 'Lien de paiement', TextInputType.url)],
    'text': [('text', 'Texte', TextInputType.multiline)],
    'whatsapp': [('phone', 'Numéro WhatsApp (avec l\'indicatif)', TextInputType.phone), ('message', 'Message prérempli (facultatif)', TextInputType.text)],
    'wifi': [('ssid', 'Nom du réseau Wi-Fi', TextInputType.text), ('password', 'Mot de passe', TextInputType.text)],
    'vcard': [
      ('first_name', 'Prénom', TextInputType.name), ('last_name', 'Nom', TextInputType.name), ('company', 'Entreprise', TextInputType.text), ('job', 'Fonction', TextInputType.text),
      ('phone', 'Téléphone', TextInputType.phone), ('email', 'E-mail', TextInputType.emailAddress), ('website', 'Site web', TextInputType.url), ('address', 'Adresse', TextInputType.streetAddress),
    ],
    'email': [('email', 'Adresse e-mail', TextInputType.emailAddress), ('subject', 'Objet', TextInputType.text), ('body', 'Message', TextInputType.multiline)],
    'sms': [('phone', 'Numéro', TextInputType.phone), ('message', 'Message', TextInputType.text)],
    'phone': [('phone', 'Numéro à appeler', TextInputType.phone)],
    'geo': [('lat', 'Latitude (ex. 5.3364)', TextInputType.number), ('lng', 'Longitude (ex. -4.0267)', TextInputType.number)],
  };
  static const _icons = {
    'url': Icons.public, 'text': Icons.notes, 'whatsapp': Icons.chat, 'wifi': Icons.wifi, 'vcard': Icons.contact_page_outlined, 'email': Icons.mail_outline,
    'sms': Icons.sms_outlined, 'phone': Icons.call_outlined, 'geo': Icons.place_outlined, 'payment': Icons.payments_outlined,
  };

  late String _type = '${widget.qr?['type'] ?? 'url'}';
  late final _name = TextEditingController(text: '${widget.qr?['name'] ?? ''}');
  final Map<String, TextEditingController> _c = {};
  late String _security = '${asMap(widget.qr?['payload'])['security'] ?? 'WPA'}';
  late String _fg = '${asMap(widget.qr?['style'])['fg'] ?? '#0A1633'}';
  late String _dots = '${asMap(widget.qr?['style'])['dots'] ?? 'square'}';
  late bool _logo = asMap(widget.qr?['style'])['logo'] == true;
  late bool _dynamic = widget.qr?['dynamic'] == true;
  String? _png;
  String? _err;
  bool _busy = false;
  Timer? _t;

  TextEditingController _ctl(String k) => _c.putIfAbsent(k, () => TextEditingController(text: '${asMap(widget.qr?['payload'])[k] ?? ''}')..addListener(_refresh));

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void dispose() {
    _t?.cancel();
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get _payload => {for (final f in _fields[_type] ?? const []) f.$1: _ctl(f.$1).text.trim(), if (_type == 'wifi') 'security': _security};
  Map<String, dynamic> get _style => {'fg': _fg, 'bg': '#FFFFFF', 'dots': _dots, 'logo': _logo};

  void _refresh() {
    _t?.cancel();
    _t = Timer(const Duration(milliseconds: 450), () async {
      try {
        final r = asMap(await context.read<Session>().api.post('liens/qrs/render', {'type': _type, 'payload': _payload, 'style': _style}));
        if (mounted) setState(() => (_png = '${r['png']}', _err = null));
      } catch (e) {
        if (mounted) setState(() => _err = errorText(e));
      }
    });
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final api = context.read<Session>().api;
      final body = {'name': _name.text.trim(), 'type': _type, 'payload': _payload, 'style': _style, 'dynamic': _dynamic};
      widget.qr == null ? await api.post('liens/qrs', body) : await api.put('liens/qrs/${widget.qr!['id']}', body);
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width > 820;
    final preview = Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(children: [
          AspectRatio(
            aspectRatio: 1,
            child: Container(
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: YColors.line)),
              padding: const EdgeInsets.all(8),
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 250),
                child: _png != null
                    ? Image.memory(base64Decode(_png!.split(',').last), key: ValueKey(_png.hashCode), fit: BoxFit.contain, gaplessPlayback: true)
                    : const Center(child: CircularProgressIndicator()),
              ),
            ),
          ),
          if (_err != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_err!, style: const TextStyle(color: YColors.danger, fontSize: 12.5), textAlign: TextAlign.center)),
          const SizedBox(height: 10),
          Text(tr('Aperçu : scannez-le avec votre téléphone pour le tester.'), textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
        ]),
      ),
    );
    final form = [
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final e in widget.types.entries)
          ChoiceChip(
            avatar: Icon(_icons[e.key] ?? Icons.qr_code, size: 18),
            label: Text(tr('${e.value}')),
            selected: _type == e.key,
            onSelected: (_) {
              setState(() => _type = e.key);
              _refresh();
            },
          ),
      ]),
      const SizedBox(height: 16),
      TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom (pour vous retrouver)'), hintText: tr('Ex. Wi-Fi de la boutique'))),
      const SizedBox(height: 12),
      for (final f in _fields[_type] ?? const <(String, String, TextInputType)>[])
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: TextField(controller: _ctl(f.$1), keyboardType: f.$3, maxLines: f.$3 == TextInputType.multiline ? 4 : 1, obscureText: false, decoration: InputDecoration(labelText: tr(f.$2))),
        ),
      if (_type == 'wifi')
        SegmentedButton<String>(
          segments: const [ButtonSegment(value: 'WPA', label: Text('WPA/WPA2')), ButtonSegment(value: 'WEP', label: Text('WEP')), ButtonSegment(value: 'none', label: Text('Ouvert'))],
          selected: {_security},
          onSelectionChanged: (v) {
            setState(() => _security = v.first);
            _refresh();
          },
        ),
      if (_type == 'url' || _type == 'payment')
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _dynamic,
          onChanged: (v) => setState(() => _dynamic = v),
          title: Text(tr('QR code dynamique')),
          subtitle: Text(tr('Passe par un lien suivi : les scans sont comptés et la destination peut changer sans réimprimer.')),
        ),
      const SizedBox(height: 14),
      Text(tr('Style'), style: const TextStyle(fontWeight: FontWeight.w800)),
      const SizedBox(height: 8),
      Wrap(spacing: 8, children: [
        for (final c in ['#0A1633', '#000000', '#2E6BE6', '#12B76A', '#E5484D', '#6C3CE1', '#F5A000', '#E1306C'])
          InkWell(
            onTap: () {
              setState(() => _fg = c);
              _refresh();
            },
            child: Container(width: 30, height: 30, decoration: BoxDecoration(color: _hex(c), shape: BoxShape.circle, border: Border.all(color: _fg == c ? YColors.yellow : Colors.transparent, width: 3))),
          ),
      ]),
      const SizedBox(height: 12),
      SegmentedButton<String>(
        segments: [ButtonSegment(value: 'square', label: Text(tr('Carrés'))), ButtonSegment(value: 'rounded', label: Text(tr('Arrondis'))), ButtonSegment(value: 'dots', label: Text(tr('Points')))],
        selected: {_dots},
        onSelectionChanged: (v) {
          setState(() => _dots = v.first);
          _refresh();
        },
      ),
      SwitchListTile(
        contentPadding: EdgeInsets.zero,
        value: _logo,
        onChanged: (v) {
          setState(() => _logo = v);
          _refresh();
        },
        title: Text(tr('Logo de l\'entreprise au centre')),
      ),
    ];
    return Scaffold(
      appBar: AppBar(
        title: Text(tr(widget.qr == null ? 'Nouveau QR code' : 'Modifier le QR code')),
        actions: [Padding(padding: const EdgeInsets.only(right: 12), child: FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.check), label: Text(tr('Enregistrer'))))],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 1000),
          child: wide
              ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Expanded(flex: 3, child: ListView(padding: const EdgeInsets.all(18), children: form)),
                  SizedBox(width: 340, child: Padding(padding: const EdgeInsets.all(18), child: preview)),
                ])
              : ListView(padding: const EdgeInsets.all(16), children: [preview, const SizedBox(height: 12), ...form]),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Statistiques
// ---------------------------------------------------------------------------

/// Statistiques d'un lien : grande fenêtre sur ordinateur, plein écran sur téléphone, avec l'en-tête du lien.
void _openStats(BuildContext context, Map<String, dynamic> l, String url) {
  final narrow = MediaQuery.sizeOf(context).width < 760;
  Widget body(BuildContext c) => Column(children: [
        Container(
          padding: const EdgeInsets.fromLTRB(20, 16, 10, 16),
          decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFF0E1E45), Color(0xFF1D4FBF)])),
          child: Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(12)),
              child: const Icon(Icons.insights_rounded, color: Colors.white),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(verbatim('${l['title'] ?? l['slug']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800)),
                const SizedBox(height: 2),
                InkWell(
                  onTap: () {
                    Clipboard.setData(ClipboardData(text: url));
                    toast(c, tr('Lien copié.'));
                  },
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Flexible(child: Text(url.replaceFirst(RegExp('^https?://'), ''), overflow: TextOverflow.ellipsis, style: const TextStyle(color: Color(0xFFFFD45E), fontWeight: FontWeight.w700))),
                    const SizedBox(width: 6),
                    const Icon(Icons.copy_rounded, size: 14, color: Color(0xFFBFD0F2)),
                  ]),
                ),
              ]),
            ),
            IconButton(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close, color: Colors.white)),
          ]),
        ),
        Expanded(child: _StatsTab(linkId: l['id'] as int)),
      ]);
  if (narrow) {
    Navigator.of(context).push(MaterialPageRoute(fullscreenDialog: true, builder: (c) => Scaffold(body: SafeArea(child: body(c)))));
  } else {
    showDialog<void>(
      context: context,
      builder: (c) => Dialog(
        insetPadding: const EdgeInsets.all(24),
        clipBehavior: Clip.antiAlias,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
        child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 1000, maxHeight: 860), child: Material(child: body(c))),
      ),
    );
  }
}

class _StatsTab extends StatefulWidget {
  const _StatsTab({super.key, this.linkId});
  final int? linkId;

  @override
  State<_StatsTab> createState() => _StatsTabState();
}

class _StatsTabState extends State<_StatsTab> {
  Map<String, dynamic>? _d;
  int _days = 30;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = asMap(await context.read<Session>().api.get('liens/stats', query: {'days': '$_days', if (widget.linkId != null) 'link': '${widget.linkId}'}));
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Widget _bars(String title, dynamic raw, {Map<String, String> labels = const {}}) {
    final m = raw is Map ? raw : const {};
    final total = m.values.fold<num>(0, (a, b) => a + (b as num));
    final theme = Theme.of(context);
    final icon = switch (title) {
      'Pays' => Icons.public,
      'Appareils' => Icons.devices_other_outlined,
      'Systèmes' => Icons.memory_outlined,
      'Navigateurs' => Icons.travel_explore_outlined,
      'Sites d\'origine' => Icons.call_split_rounded,
      _ => Icons.touch_app_outlined,
    };
    final entries = m.entries.toList()..sort((a, b) => (b.value as num).compareTo(a.value as num));
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: theme.cardColor, borderRadius: BorderRadius.circular(16), border: Border.all(color: theme.dividerColor.withValues(alpha: .6))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .1), borderRadius: BorderRadius.circular(9)),
            child: Icon(icon, size: 17, color: YColors.blue),
          ),
          const SizedBox(width: 10),
          Expanded(child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5))),
          if (total > 0) Text(tr('$total'), style: TextStyle(color: theme.hintColor, fontWeight: FontWeight.w600)),
        ]),
        const SizedBox(height: 12),
        if (entries.isEmpty)
          Text(tr('Pas encore de données.'), style: TextStyle(color: theme.hintColor))
        else
          for (final e in entries.take(6))
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 5),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(children: [
                  Expanded(child: Text(tr(labels['${e.key}'] ?? ('${e.key}'.isEmpty ? 'Inconnu' : '${e.key}')), overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600))),
                  Text('${e.value}', style: const TextStyle(fontWeight: FontWeight.w800)),
                  SizedBox(width: 48, child: Text(total == 0 ? '' : '${((e.value as num) * 100 / total).round()} %', textAlign: TextAlign.right, style: TextStyle(color: theme.hintColor, fontSize: 12))),
                ]),
                const SizedBox(height: 5),
                ClipRRect(
                  borderRadius: BorderRadius.circular(6),
                  child: LinearProgressIndicator(value: total == 0 ? 0 : (e.value as num) / total, minHeight: 7, backgroundColor: theme.colorScheme.surfaceContainerHigh, color: YColors.blue),
                ),
              ]),
            ),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) return const Center(child: CircularProgressIndicator());
    final series = [for (final s in (d['series'] as List)) asMap(s)];
    final cur = context.read<Session>().currency;
    return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 40), children: [
      Row(children: [
        SegmentedButton<int>(
          segments: const [ButtonSegment(value: 7, label: Text('7 j')), ButtonSegment(value: 30, label: Text('30 j')), ButtonSegment(value: 90, label: Text('90 j'))],
          selected: {_days},
          onSelectionChanged: (v) {
            setState(() => _days = v.first);
            _load();
          },
        ),
      ]),
      const SizedBox(height: 12),
      KpiWrap(minTile: 190, children: [
        StatTile(label: tr('Clics'), value: number(d['clicks'] as num), icon: Icons.ads_click),
        StatTile(label: tr('Visiteurs uniques (portée)'), value: number(d['uniques'] as num), icon: Icons.people_outline, color: const Color(0xFF9333EA)),
        StatTile(label: tr('Scans de QR code'), value: number(d['qr_scans'] as num), icon: Icons.qr_code_scanner, color: YColors.warning),
        if (d['ctr'] != null) StatTile(label: tr('Taux de clic sur les boutons'), value: '${d['ctr']} %', icon: Icons.touch_app_outlined, color: YColors.success),
      ].map((t) => KpiBox(child: t)).toList()),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Clics par jour'),
        child: SizedBox(
          height: 220,
          child: CartesianChart(
            data: ChartData([for (final s in series) DateFormat('d MMM', 'fr').format(DateTime.parse('${s['date']}'))],
                [ChartSeries(tr('Clics'), [for (final s in series) toDouble(s['clicks'])]), ChartSeries(tr('Uniques'), [for (final s in series) toDouble(s['uniques'])])], 'number'),
            currency: cur,
            line: true,
            height: 220,
          ),
        ),
      ),
      const SizedBox(height: 12),
      if (d['buttons'] is List && (d['buttons'] as List).isNotEmpty) ...[
        _bars('Boutons les plus touchés', {for (final b in (d['buttons'] as List)) '${asMap(b)['label']}': asMap(b)['clicks']}),
        const SizedBox(height: 12),
      ],
      LayoutBuilder(builder: (context, c) {
        final cols = c.maxWidth >= 900 ? 3 : (c.maxWidth >= 560 ? 2 : 1);
        final w = (c.maxWidth - (cols - 1) * 12) / cols;
        return Wrap(spacing: 12, runSpacing: 12, children: [
        for (final (t, k, l) in [
          ('Pays', 'countries', const <String, String>{'CI': 'Côte d\'Ivoire', 'SN': 'Sénégal', 'FR': 'France', 'BF': 'Burkina Faso', 'ML': 'Mali', 'CM': 'Cameroun', 'BJ': 'Bénin', 'TG': 'Togo', 'GH': 'Ghana', 'US': 'États-Unis'}),
          ('Appareils', 'devices', const {'mobile': 'Téléphone', 'tablet': 'Tablette', 'desktop': 'Ordinateur'}),
          ('Systèmes', 'os', const <String, String>{}),
          ('Navigateurs', 'browsers', const <String, String>{}),
          ('Sites d\'origine', 'referrers', const <String, String>{'': 'Direct (WhatsApp, SMS, saisi)'}),
        ])
          SizedBox(width: w, child: _bars(t, d[k], labels: l)),
        ]);
      }),
      if (d['top'] is List && (d['top'] as List).isNotEmpty) ...[
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Liens les plus visités'),
          child: Column(children: [
            for (final l in (d['top'] as List).map(asMap))
              ListTile(contentPadding: EdgeInsets.zero, title: Text(verbatim('${l['title'] ?? l['slug']}')), subtitle: Text('${l['short_url']}'), trailing: Text(tr('${l['clicks']} clics'), style: const TextStyle(fontWeight: FontWeight.w800))),
          ]),
        ),
      ],
    ]);
  }
}
