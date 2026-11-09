import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'dart:math' as math;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import 'sites_screen.dart' show openUrl;

/// Veille concurrentielle : concurrents, terrain, prix, publicités, audience, sites surveillés, presse et comparatif.
class VeilleScreen extends StatefulWidget {
  const VeilleScreen({super.key});

  @override
  State<VeilleScreen> createState() => _VeilleScreenState();
}

class _VeilleScreenState extends State<VeilleScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();
  final _compare = GlobalKey<_CompareViewState>();

  @override
  Widget build(BuildContext context) {
    void changed() {
      _dash.currentState?.reload();
      _compare.currentState?.load();
    }

    final tabs = <(String, Widget)>[
      ('Comparatif', _CompareView(key: _compare)),
      ('Concurrents', RecordsView(module: 'veille', resource: 'competitors', emptyIcon: Icons.groups_outlined, newLabel: tr('Nouveau concurrent'), onChanged: changed, actions: competitorActions,
          columns: const ['title', 'kind', 'threat', 'score', 'price_index', 'observations', 'city'],
          emptyMessage: tr('Ajoutez vos concurrents, puis votre propre entreprise (type « Nous ») pour vous comparer.'))),
      ('Observations', RecordsView(module: 'veille', resource: 'observations', emptyIcon: Icons.visibility_outlined, newLabel: tr('Nouvelle observation'), onChanged: changed, actions: observationActions,
          emptyMessage: tr('Ce que vous et vos commerciaux voyez chez les concurrents : promotions, nouveautés, ouvertures, campagnes…'))),
      ('Prix', RecordsView(module: 'veille', resource: 'prices', emptyIcon: Icons.sell_outlined, newLabel: tr('Nouveau relevé'), onChanged: changed,
          emptyMessage: tr('Relevez le prix d\'un produit concurrent : l\'écart avec votre article est calculé.'))),
      ('Publicités', RecordsView(module: 'veille', resource: 'ads', emptyIcon: Icons.campaign_outlined, newLabel: tr('Nouvelle publicité'), onChanged: changed, actions: adActions)),
      ('Audience', RecordsView(module: 'veille', resource: 'audience', emptyIcon: Icons.trending_up, newLabel: tr('Nouveau relevé'), onChanged: changed,
          emptyMessage: tr('Notez chaque mois les abonnés de leurs réseaux : la progression se calcule toute seule.'))),
      ('Sites surveillés', RecordsView(module: 'veille', resource: 'watches', emptyIcon: Icons.travel_explore, newLabel: tr('Surveiller une page'), onChanged: changed, actions: watchActions,
          emptyMessage: tr('Indiquez une page de leur site (tarifs, promotions, recrutement) : vous êtes prévenu à chaque changement.'))),
      ('Mots-clés', RecordsView(module: 'veille', resource: 'alerts', emptyIcon: Icons.notifications_active_outlined, newLabel: tr('Suivre un mot-clé'), onChanged: changed, actions: alertActions,
          emptyMessage: tr('Le nom d\'un concurrent ou de votre marché : les articles qui en parlent arrivent dans les mentions.'))),
      ('Mentions', RecordsView(module: 'veille', resource: 'mentions', emptyIcon: Icons.newspaper_outlined, onChanged: changed, actions: mentionActions)),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Veille concurrentielle'), subtitle: tr('Concurrents, prix, publicités, sites et presse au même endroit')),
        ModuleDashboard(
          key: _dash,
          path: 'veille/dashboard',
          builder: (context, d, _) {
            final gap = d['avg_gap'];
            return StatStrip(tiles: [
              StatTile(label: tr('Concurrents suivis'), value: '${d['competitors']}', detail: tr('${d['high_threat']} à menace forte'), icon: Icons.groups_outlined),
              StatTile(label: tr('Observations (30 j)'), value: '${d['observations_30']}', detail: tr('${d['high_impact']} à fort impact · ${d['to_act']} à traiter'), icon: Icons.visibility_outlined, color: const Color(0xFF9333EA)),
              StatTile(
                  label: tr('Leurs prix / les nôtres'),
                  value: gap == null ? '—' : '${toDouble(gap) > 0 ? '+' : ''}${toDouble(gap).toStringAsFixed(1).replaceAll('.', ',')} %',
                  detail: tr('${d['cheaper']} produit(s) moins cher(s) chez eux sur ${d['prices_90']} relevé(s)'),
                  icon: Icons.sell_outlined,
                  color: gap != null && toDouble(gap) < 0 ? YColors.danger : YColors.success),
              StatTile(label: tr('Signaux en ligne'), value: '${d['site_changes']}', detail: tr('site(s) modifié(s) cette semaine · ${d['ads_active']} pub(s) en ligne · ${d['mentions_new']} mention(s)'),
                  icon: Icons.travel_explore, color: const Color(0xFFD97706)),
            ]);
          },
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

Future<void> _run(BuildContext dialog, Future<void> Function() fn) async {
  try {
    await fn();
  } catch (e) {
    if (dialog.mounted) toast(dialog, errorText(e), error: true);
  }
}

void _showText(BuildContext context, String title, String text) => showDialog<void>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(title),
        content: SizedBox(width: 620, child: SingleChildScrollView(child: SelectableText(verbatim(text), style: const TextStyle(height: 1.45)))),
        actions: [
          TextButton.icon(
              onPressed: () {
                Clipboard.setData(ClipboardData(text: text));
                toast(c, tr('Texte copié'));
              },
              icon: const Icon(Icons.copy, size: 18),
              label: Text(tr('Copier'))),
          FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
        ],
      ),
    );

void _filtered(BuildContext context, String title, String resource, Map<String, String> filters, String newLabel, VoidCallback reload) => showDialog<void>(
      context: context,
      builder: (c) => Dialog(
        child: SizedBox(
          width: 980,
          height: 640,
          child: Column(children: [
            ListTile(title: Text(title), trailing: IconButton(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close))),
            Expanded(child: RecordsView(module: 'veille', resource: resource, filters: filters, newLabel: newLabel, onChanged: reload, actions: resource == 'observations' ? observationActions : null)),
          ]),
        ),
      ),
    );

/// Concurrent : analyse du site, bibliothèques publicitaires, fiche de combat, dossier.
List<Widget> competitorActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  final manage = s.can('veille.manage');
  final id = row['id'];
  final libs = [for (final l in (row['ad_libraries'] as List? ?? const [])) asMap(l)];
  return [
    if ('${row['website'] ?? ''}'.isNotEmpty) OutlinedButton.icon(icon: const Icon(Icons.public, size: 18), label: Text(tr('Ouvrir le site')), onPressed: () => openUrl('${row['website']}')),
    if (manage && '${row['website'] ?? ''}'.isNotEmpty)
      OutlinedButton.icon(
        icon: const Icon(Icons.manage_search, size: 18),
        label: Text(tr('Analyser son site')),
        onPressed: () => _run(dialog, () async {
          toast(dialog, tr('Analyse en cours…'));
          final r = asMap((await s.api.postLong('veille/competitors/$id/audit') as Map)['data']);
          reload();
          if (dialog.mounted) _showText(dialog, tr('Analyse du site'), '${r['audit_summary'] ?? ''}\n\n${r['tools'] ?? ''}');
        }),
      ),
    if (libs.isNotEmpty)
      OutlinedButton.icon(
        icon: const Icon(Icons.campaign_outlined, size: 18),
        label: Text(tr('Ses publicités en ligne')),
        onPressed: () => showAdaptiveSheet<void>(
          context: dialog,
          showDragHandle: true,
          builder: (c) => SafeArea(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: Text(tr('Bibliothèques publiques des publicités, déjà filtrées sur « ${row['title']} ». Notez ce que vous y trouvez dans l\'onglet Publicités.'),
                    style: const TextStyle(color: YColors.muted)),
              ),
              for (final l in libs)
                ListTile(leading: const Icon(Icons.open_in_new), title: Text(tr('${l['label']}')), onTap: () {
                  Navigator.pop(c);
                  openUrl('${l['url']}');
                }),
            ]),
          ),
        ),
      ),
    if (manage)
      OutlinedButton.icon(
        icon: const Icon(Icons.auto_awesome_outlined, size: 18),
        label: Text(tr('Fiche de combat (IA)')),
        onPressed: () => _run(dialog, () async {
          if ('${row['battle_card'] ?? ''}'.trim().isNotEmpty &&
              !await confirm(dialog, tr('Fiche de combat'), tr('La fiche actuelle sera remplacée par celle rédigée par l\'IA à partir de toute la veille sur ce concurrent.'), ok: tr('Générer'))) {
            return;
          }
          if (dialog.mounted) toast(dialog, tr('Rédaction en cours…'));
          final r = asMap((await s.api.postLong('veille/competitors/$id/battle-card') as Map)['data']);
          reload();
          if (dialog.mounted) {
            Navigator.pop(dialog, r);
            _showText(dialog, tr('Fiche de combat — ${row['title']}'), '${r['battle_card'] ?? ''}');
          }
        }),
      ),
    OutlinedButton.icon(icon: const Icon(Icons.visibility_outlined, size: 18), label: Text(tr('Observations')),
        onPressed: () => _filtered(dialog, tr('Observations — ${row['title']}'), 'observations', {'competitor_id': '$id'}, tr('Nouvelle observation'), reload)),
    OutlinedButton.icon(icon: const Icon(Icons.sell_outlined, size: 18), label: Text(tr('Relevés de prix')),
        onPressed: () => _filtered(dialog, tr('Prix — ${row['title']}'), 'prices', {'competitor_id': '$id'}, tr('Nouveau relevé'), reload)),
  ];
}

/// Observation : photo prise sur le terrain, lien.
List<Widget> observationActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  final id = row['id'];
  final canEdit = s.can('veille.manage') || (s.can('veille.report') && row['created_by'] == s.user['id']);
  return [
    if (row['has_photo'] == true)
      OutlinedButton.icon(
        icon: const Icon(Icons.image_outlined, size: 18),
        label: Text(tr('Voir la photo')),
        onPressed: () => _run(dialog, () async {
          final bytes = await s.api.download('veille/observations/$id/photo');
          if (!dialog.mounted) return;
          await showDialog<void>(
            context: dialog,
            builder: (c) => Dialog(
              child: Stack(children: [
                InteractiveViewer(child: Image.memory(Uint8List.fromList(bytes), fit: BoxFit.contain, errorBuilder: (_, _, _) => Padding(padding: const EdgeInsets.all(40), child: Text(tr('Aperçu indisponible (PDF) : le fichier a été téléchargé.'))))),
                Positioned(right: 4, top: 4, child: IconButton.filledTonal(onPressed: () => Navigator.pop(c), icon: const Icon(Icons.close))),
              ]),
            ),
          );
        }),
      ),
    if (canEdit)
      OutlinedButton.icon(
        icon: const Icon(Icons.photo_camera_outlined, size: 18),
        label: Text(tr(row['has_photo'] == true ? 'Remplacer la photo' : 'Ajouter une photo')),
        onPressed: () => _run(dialog, () async {
          final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf']);
          if (files.isEmpty) return;
          final b = await files.first.readAsBytes();
          if (b.length > 8 * 1024 * 1024) {
            if (dialog.mounted) toast(dialog, tr('Fichier trop lourd (8 Mo maximum).'), error: true);
            return;
          }
          await s.api.uploadForm('veille/observations/$id/photo', {}, 'file', b, files.first.name);
          reload();
          if (dialog.mounted) toast(dialog, tr('Photo ajoutée'));
        }),
      ),
    if ('${row['source_url'] ?? ''}'.isNotEmpty) OutlinedButton.icon(icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Ouvrir le lien')), onPressed: () => openUrl('${row['source_url']}')),
  ];
}

List<Widget> adActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) => [
      if ('${row['library_url'] ?? ''}'.isNotEmpty) OutlinedButton.icon(icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Voir la publicité')), onPressed: () => openUrl('${row['library_url']}')),
      if ('${row['landing_url'] ?? ''}'.isNotEmpty) OutlinedButton.icon(icon: const Icon(Icons.link, size: 18), label: Text(tr('Page de destination')), onPressed: () => openUrl('${row['landing_url']}')),
    ];

List<Widget> watchActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  return [
    OutlinedButton.icon(icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Ouvrir la page')), onPressed: () => openUrl('${row['url']}')),
    if (s.can('veille.manage'))
      OutlinedButton.icon(
        icon: const Icon(Icons.refresh, size: 18),
        label: Text(tr('Vérifier maintenant')),
        onPressed: () => _run(dialog, () async {
          final r = asMap(asMap((await s.api.postLong('veille/watches/${row['id']}/check') as Map)['data'])['result']);
          reload();
          if (!dialog.mounted) return;
          if (r['error'] != null) return toast(dialog, '${r['error']}', error: true);
          if (r['changed'] == true) return _showText(dialog, tr('La page a changé'), '${r['summary']}');
          toast(dialog, tr(r['first'] == true ? 'Contenu mémorisé : vous serez prévenu au prochain changement.' : (r['ignored'] == true ? 'Changements sans vos mots-clés : ignorés.' : 'Aucun changement depuis la dernière visite.')));
        }),
      ),
  ];
}

List<Widget> alertActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  return [
    if (s.can('veille.manage'))
      OutlinedButton.icon(
        icon: const Icon(Icons.search, size: 18),
        label: Text(tr('Chercher maintenant')),
        onPressed: () => _run(dialog, () async {
          final r = asMap(asMap((await s.api.postLong('veille/alerts/${row['id']}/check') as Map)['data'])['result']);
          reload();
          if (!dialog.mounted) return;
          final errs = (r['errors'] as List? ?? const []);
          toast(dialog, errs.isNotEmpty ? errs.join(' ') : tr('${r['new']} nouvelle(s) mention(s) : voir l\'onglet Mentions.'), error: errs.isNotEmpty);
        }),
      ),
  ];
}

List<Widget> mentionActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  return [
    OutlinedButton.icon(icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Lire l\'article')), onPressed: () => openUrl('${row['url']}')),
    if (s.can('veille.manage') || s.can('veille.report'))
      OutlinedButton.icon(
        icon: const Icon(Icons.bookmark_add_outlined, size: 18),
        label: Text(tr('Ajouter aux observations')),
        onPressed: () => _run(dialog, () async {
          await s.api.post('veille/mentions/${row['id']}/observe');
          reload();
          if (dialog.mounted) {
            Navigator.pop(dialog, <String, dynamic>{'observed': true});
            toast(dialog, tr('Ajoutée aux observations du concurrent.'));
          }
        }),
      ),
  ];
}

// ------------------------------------------------------------------ comparatif

class _CompareView extends StatefulWidget {
  const _CompareView({super.key});

  @override
  State<_CompareView> createState() => _CompareViewState();
}

class _CompareViewState extends State<_CompareView> {
  Map<String, dynamic>? _d;
  bool _ai = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    final api = context.read<Session>().api;
    try {
      final r = await api.get('veille/compare');
      final dash = await api.get('veille/dashboard');
      if (!mounted) return;
      setState(() {
        _d = asMap((r as Map)['data']);
        _ai = asMap((dash as Map)['data'])['ai'] == true;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  Future<void> _analysis() async {
    final s = context.read<Session>();
    toast(context, tr('Analyse en cours…'));
    try {
      final r = await s.api.postLong('veille/analysis', {});
      if (mounted) _showText(context, tr('Analyse de la veille'), '${asMap((r as Map)['data'])['text']}');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) return EmptyState(icon: Icons.error_outline, title: tr('Comparatif indisponible'), message: _error!);
    if (_d == null) return const Center(child: CircularProgressIndicator());
    final comps = [for (final c in (_d!['competitors'] as List? ?? const [])) asMap(c)];
    final criteria = asMap(_d!['criteria']);
    if (comps.isEmpty) {
      return EmptyState(icon: Icons.radar, title: tr('Aucun concurrent'), message: tr('Créez vos fiches concurrents et notez-les sur six critères : le comparatif et la carte de positionnement apparaissent ici.'));
    }
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
        Wrap(spacing: 8, runSpacing: 8, children: [
          if (_ai) FilledButton.icon(onPressed: _analysis, icon: const Icon(Icons.auto_awesome, size: 18), label: Text(tr('Synthèse et actions par l\'IA'))),
          OutlinedButton.icon(onPressed: load, icon: const Icon(Icons.refresh, size: 18), label: Text(tr('Actualiser'))),
        ]),
        const SizedBox(height: 12),
        LayoutBuilder(builder: (context, box) {
          final map = SectionCard(title: 'Carte de positionnement', child: _PositionMap(comps: comps));
          final table = SectionCard(title: 'Notes par critère', child: _ScoreTable(comps: comps, criteria: criteria));
          if (box.maxWidth < 900) return Column(children: [map, const SizedBox(height: 12), table]);
          return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: map), const SizedBox(width: 12), Expanded(child: table)]);
        }),
        const SizedBox(height: 12),
        SectionCard(title: 'Présence et activité', child: Column(children: [for (final c in comps) _PresenceRow(c: c)])),
      ]),
    );
  }
}

Color _kindColor(String? k) => switch (k) {
      'us' => YColors.success,
      'indirect' => const Color(0xFFD97706),
      'substitute' => const Color(0xFF9333EA),
      'newcomer' => YColors.blue,
      _ => YColors.danger,
    };

/// Prix (indice 100 = nos prix, ou positionnement déclaré) en abscisse, note globale en ordonnée.
class _PositionMap extends StatelessWidget {
  const _PositionMap({required this.comps});

  final List<Map<String, dynamic>> comps;

  double? _x(Map<String, dynamic> c) {
    if (c['kind'] == 'us') return 100;
    if (c['price_index'] != null) return toDouble(c['price_index']);
    return switch (c['positioning']) { 'low' => 80.0, 'mid' => 100.0, 'high' => 125.0, _ => null };
  }

  @override
  Widget build(BuildContext context) {
    final pts = [
      for (final c in comps)
        if (_x(c) != null && c['score'] != null) (c, _x(c)!, toDouble(c['score'])),
    ];
    final missing = comps.where((c) => _x(c) == null || c['score'] == null).map((c) => '${c['title']}').toList();
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      AspectRatio(
        aspectRatio: 1.35,
        child: pts.isEmpty
            ? Center(child: Text(tr('Notez les concurrents et relevez leurs prix pour les placer sur la carte.'), textAlign: TextAlign.center, style: const TextStyle(color: YColors.muted)))
            : CustomPaint(painter: _MapPainter(pts, dark ? Colors.white : YColors.ink, Theme.of(context).dividerColor, tr('Moins cher'), tr('Plus cher'), tr('Mieux noté'))),
      ),
      const SizedBox(height: 8),
      Text(tr('Horizontal : prix par rapport aux nôtres (relevés de prix, sinon positionnement). Vertical : note globale sur 5.'), style: const TextStyle(color: YColors.muted, fontSize: 12)),
      if (missing.isNotEmpty) Text(tr('Pas encore placés : ${missing.join(', ')}.'), style: const TextStyle(color: YColors.muted, fontSize: 12)),
    ]);
  }
}

class _MapPainter extends CustomPainter {
  _MapPainter(this.pts, this.ink, this.line, this.cheaper, this.dearer, this.better);

  final List<(Map<String, dynamic>, double, double)> pts;
  final Color ink;
  final Color line;
  final String cheaper, dearer, better;

  @override
  void paint(Canvas canvas, Size size) {
    const pad = 28.0;
    final w = size.width - pad * 2, h = size.height - pad * 2;
    final xs = pts.map((p) => p.$2).toList();
    var minX = math.min(70.0, xs.reduce(math.min) - 5), maxX = math.max(130.0, xs.reduce(math.max) + 5);
    final span = math.max(maxX - 100, 100 - minX);
    minX = 100 - span;
    maxX = 100 + span;
    final ys = pts.map((p) => p.$3).toList();
    final minY = math.max(1.0, (ys.reduce(math.min) - .5).floorToDouble()), maxY = math.min(5.0, (ys.reduce(math.max) + .5).ceilToDouble());
    final midY = (minY + maxY) / 2;
    Offset at(double x, double y) => Offset(pad + (x - minX) / (maxX - minX) * w, pad + (1 - (y - minY) / math.max(.5, maxY - minY)) * h);
    final grid = Paint()
      ..color = line
      ..strokeWidth = 1;
    canvas.drawRect(Rect.fromLTWH(pad, pad, w, h), grid..style = PaintingStyle.stroke);
    canvas.drawLine(at(100, minY), at(100, maxY), grid);
    canvas.drawLine(at(minX, midY), at(maxX, midY), grid);
    TextPainter tp(String t, {Color? color, double size = 11, FontWeight weight = FontWeight.w500}) =>
        TextPainter(text: TextSpan(text: t, style: TextStyle(color: color ?? ink.withValues(alpha: .55), fontSize: size, fontWeight: weight)), textDirection: TextDirection.ltr)..layout(maxWidth: 150);

    tp(cheaper).paint(canvas, Offset(pad, size.height - pad + 6));
    final d = tp(dearer);
    d.paint(canvas, Offset(size.width - pad - d.width, size.height - pad + 6));
    tp(better).paint(canvas, const Offset(pad + 4, 6));
    // Points d'abord, puis les noms placés là où ils ne chevauchent ni un autre nom ni un point.
    final dots = <Rect>[];
    for (final (c, x, y) in pts) {
      final o = at(x, y);
      final r = c['kind'] == 'us' ? 9.0 : 7.0;
      dots.add(Rect.fromCircle(center: o, radius: r + 1));
      canvas.drawCircle(o, r, Paint()..color = _kindColor('${c['kind']}').withValues(alpha: .9));
      canvas.drawCircle(o, r, Paint()
        ..color = Colors.white
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2);
    }
    final placed = <Rect>[];
    final bounds = Rect.fromLTWH(0, 0, size.width, size.height);
    for (final (c, x, y) in pts) {
      final o = at(x, y);
      final t = tp('${c['title']}', color: ink, size: 11.5, weight: c['kind'] == 'us' ? FontWeight.w800 : FontWeight.w600);
      final candidates = [
        Offset(o.dx - t.width / 2, o.dy + 11),
        Offset(o.dx - t.width / 2, o.dy - 11 - t.height),
        Offset(o.dx + 12, o.dy - t.height / 2),
        Offset(o.dx - 12 - t.width, o.dy - t.height / 2),
        Offset(o.dx - t.width / 2, o.dy + 11 + t.height),
        Offset(o.dx - t.width / 2, o.dy - 11 - t.height * 2),
      ];
      var best = candidates.first;
      for (final cand in candidates) {
        final rect = cand & Size(t.width, t.height);
        if (!placed.any((p) => p.overlaps(rect)) && !dots.any((p) => p.overlaps(rect)) && bounds.contains(rect.topLeft) && bounds.contains(rect.bottomRight)) {
          best = cand;
          break;
        }
      }
      placed.add(best & Size(t.width, t.height));
      t.paint(canvas, best);
    }
  }

  @override
  bool shouldRepaint(covariant _MapPainter old) => old.pts != pts || old.ink != ink;
}

class _ScoreTable extends StatelessWidget {
  const _ScoreTable({required this.comps, required this.criteria});

  final List<Map<String, dynamic>> comps;
  final Map<String, dynamic> criteria;

  Color _cell(int? v) => switch (v) {
        null => Colors.transparent,
        >= 4 => YColors.success.withValues(alpha: .16),
        3 => YColors.warning.withValues(alpha: .18),
        _ => YColors.danger.withValues(alpha: .14),
      };

  @override
  Widget build(BuildContext context) {
    const first = 96.0, col = 84.0;
    Widget head(String t, {bool us = false}) => Container(
        width: col, padding: const EdgeInsets.fromLTRB(6, 4, 6, 8), alignment: Alignment.bottomCenter,
        child: Text(verbatim(t), maxLines: 2, overflow: TextOverflow.ellipsis, textAlign: TextAlign.center, style: TextStyle(fontWeight: us ? FontWeight.w800 : FontWeight.w600, fontSize: 12.5)));
    Widget cell(String t, Color bg, {bool bold = false}) => Container(
        width: col, height: 34, margin: const EdgeInsets.all(1.5), alignment: Alignment.center,
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6)),
        child: Text(t, style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w600)));
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.end, children: [const SizedBox(width: first), for (final c in comps) head('${c['title']}', us: c['kind'] == 'us')]),
        for (final e in criteria.entries)
          Row(children: [
            SizedBox(width: first, child: Text(tr('${e.value}'), style: const TextStyle(color: YColors.muted, fontSize: 13))),
            for (final c in comps)
              () {
                final v = asMap(c['scores'])[e.key];
                final n = v == null ? null : toDouble(v).round();
                return cell(n == null ? '—' : '$n', _cell(n));
              }(),
          ]),
        Row(children: [
          SizedBox(width: first, child: Text(tr('Note globale'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13))),
          for (final c in comps) cell(c['score'] == null ? '—' : toDouble(c['score']).toStringAsFixed(1).replaceAll('.', ','), _cell(c['score'] == null ? null : toDouble(c['score']).round()), bold: true),
        ]),
        Row(children: [
          SizedBox(width: first, child: Text(tr('Indice de prix'), style: const TextStyle(color: YColors.muted, fontSize: 13))),
          for (final c in comps) cell(c['kind'] == 'us' ? '100' : (c['price_index'] == null ? '—' : '${toDouble(c['price_index']).round()}'), Colors.transparent),
        ]),
      ]),
    );
  }
}

class _PresenceRow extends StatelessWidget {
  const _PresenceRow({required this.c});

  final Map<String, dynamic> c;

  @override
  Widget build(BuildContext context) {
    String n(dynamic v) => v == null ? '—' : number(toDouble(v));
    final tools = [for (final t in (c['tools'] as List? ?? const [])) '$t'];
    final facts = <(IconData, String)>[
      (Icons.star_rate_rounded, c['rating'] == null ? tr('Google : —') : tr('Google ${toDouble(c['rating']).toStringAsFixed(1).replaceAll('.', ',')} (${n(c['reviews'])} avis)')),
      (Icons.people_outline, tr('${n(c['audience'])} abonnés')),
      (Icons.campaign_outlined, tr('${c['ads']} pub(s) en ligne')),
      (Icons.visibility_outlined, tr('${c['observations']} observation(s) en 90 j')),
      if (c['seo'] != null) (Icons.manage_search, tr('Référencement ${c['seo']}/100')),
      if (c['market_share'] != null) (Icons.pie_chart_outline, tr('Part de marché ${n(c['market_share'])} %')),
    ];
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(border: Border(bottom: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: .6)))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Container(width: 10, height: 10, decoration: BoxDecoration(color: _kindColor('${c['kind']}'), shape: BoxShape.circle)),
          const SizedBox(width: 8),
          Expanded(child: Text(verbatim('${c['title']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
        ]),
        const SizedBox(height: 6),
        Wrap(spacing: 14, runSpacing: 4, children: [
          for (final f in facts)
            Row(mainAxisSize: MainAxisSize.min, children: [Icon(f.$1, size: 15, color: YColors.muted), const SizedBox(width: 4), Text(f.$2, style: const TextStyle(fontSize: 12.5))]),
        ]),
        if (tools.isNotEmpty) ...[
          const SizedBox(height: 6),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final t in tools)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
                child: Text(verbatim(t), style: const TextStyle(fontSize: 11.5, color: YColors.blue, fontWeight: FontWeight.w600)),
              ),
          ]),
        ],
      ]),
    );
  }
}
