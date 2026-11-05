import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _xlsxMime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

Color _hex(String h) => Color(int.parse('FF$h', radix: 16));

/// Tableaux de bord Excel : galerie de modèles (aperçu dans le thème choisi), génération, tableaux enregistrés et envois programmés.
class TableauxExcelScreen extends StatefulWidget {
  const TableauxExcelScreen({super.key});

  @override
  State<TableauxExcelScreen> createState() => _TableauxExcelScreenState();
}

class _TableauxExcelScreenState extends State<TableauxExcelScreen> {
  Map<String, dynamic>? _d;
  String _theme = 'ocean';
  String? _busy;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.get('tableaux_excel'));
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  List<Map<String, dynamic>> _list(String k) => [for (final e in (_d?[k] as List? ?? const [])) asMap(e)];
  Map<String, dynamic> get _themeData => _list('themes').firstWhere((t) => t['key'] == _theme, orElse: () => _list('themes').first);
  Map<String, dynamic>? _tpl(String key) => _list('templates').where((t) => t['key'] == key).firstOrNull;

  Future<void> _download(String path, String name, {Map<String, dynamic>? query, String? busy}) async {
    setState(() => _busy = busy ?? path);
    try {
      final bytes = await context.read<Session>().api.download(path, query: query);
      final where = await saveFile(name, bytes, mime: _xlsxMime);
      if (mounted) toast(context, tr('Classeur Excel prêt${where.isEmpty ? '' : ' : $where'}'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  String _file(String name) {
    final slug = name.toLowerCase().replaceAll(RegExp(r"[àâä]"), 'a').replaceAll(RegExp(r"[éèêë]"), 'e').replaceAll(RegExp(r"[îï]"), 'i').replaceAll(RegExp(r"[ôö]"), 'o')
        .replaceAll(RegExp(r"[ùûü]"), 'u').replaceAll('ç', 'c').replaceAll(RegExp(r'[^a-z0-9]+'), '-').replaceAll(RegExp(r'^-|-$'), '');
    final now = DateTime.now();
    return '${slug.isEmpty ? 'tableau-de-bord' : slug}-${now.year}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}.xlsx';
  }

  Future<void> _configure(Map<String, dynamic> tpl, {Map<String, dynamic>? existing}) async {
    final res = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _ConfigDialog(
        tpl: tpl,
        themes: _list('themes'),
        years: [for (final y in (_d?['years'] as List? ?? const [])) (y as num).toInt()],
        branches: _list('branches'),
        frequencies: asMap(_d?['frequencies']),
        canManage: _d?['can_manage'] == true,
        initialTheme: existing?['theme'] as String? ?? _theme,
        existing: existing,
      ),
    );
    if (res == null || !mounted) return;
    final o = asMap(res['options']);
    if (res['action'] == 'download') {
      await _download('tableaux_excel/generate', _file('${o['title'] ?? tpl['name']}'),
          query: {for (final e in o.entries) if (e.value != null) e.key: e.value is bool ? (e.value == true ? '1' : '0') : '${e.value}'}, busy: tpl['key'] as String);
      return;
    }
    try {
      final api = context.read<Session>().api;
      final body = {...o, 'name': o['title'], 'template': tpl['key']};
      if (existing != null) {
        await api.put('tableaux_excel/workbooks/${existing['id']}', body);
      } else {
        await api.post('tableaux_excel/workbooks', body);
      }
      if (mounted) toast(context, existing != null ? 'Tableau mis à jour' : 'Tableau enregistré dans « Mes tableaux »');
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _send(Map<String, dynamic> w) async {
    setState(() => _busy = 'send${w['id']}');
    try {
      await context.read<Session>().api.post('tableaux_excel/workbooks/${w['id']}/send', {});
      if (mounted) toast(context, tr('Classeur envoyé à ${(w['recipients'] as List).join(', ')}'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
      _load();
    }
  }

  Future<void> _delete(Map<String, dynamic> w) async {
    if (!await confirm(context, tr('Supprimer « ${w['name']} » ?'), tr('Le tableau enregistré et son envoi programmé seront supprimés.'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    try {
      await context.read<Session>().api.delete('tableaux_excel/workbooks/${w['id']}');
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return const Center(child: CircularProgressIndicator());
    final width = MediaQuery.of(context).size.width;
    final pad = width < 700 ? 16.0 : 24.0;
    final theme = _themeData;
    final workbooks = _list('workbooks');
    final hint = Theme.of(context).hintColor;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
        PageHeader(title: tr('Tableaux de bord Excel'), subtitle: tr('Des classeurs interactifs et soignés, générés depuis vos données en un clic')),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: pad),
          child: _Hero(theme: theme),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 22, pad, 10),
          child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 14, runSpacing: 10, children: [
            Text(tr('Modèles'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
            Text(tr('Thème :'), style: TextStyle(color: hint)),
            for (final t in _list('themes'))
              Tooltip(
                message: '${t['name']}',
                child: InkWell(
                  borderRadius: BorderRadius.circular(20),
                  onTap: () => setState(() => _theme = t['key'] as String),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      color: _theme == t['key'] ? _hex('${t['primary']}').withValues(alpha: .12) : null,
                      border: Border.all(color: _theme == t['key'] ? _hex('${t['primary']}') : Theme.of(context).dividerColor),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      for (final c in (t['chart'] as List).take(3))
                        Container(width: 10, height: 10, margin: const EdgeInsets.only(right: 3), decoration: BoxDecoration(color: _hex('$c'), shape: BoxShape.circle)),
                      const SizedBox(width: 4),
                      Text(tr('${t['name']}'), style: TextStyle(fontSize: 12.5, fontWeight: _theme == t['key'] ? FontWeight.w800 : FontWeight.w500)),
                    ]),
                  ),
                ),
              ),
          ]),
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: pad),
          child: LayoutBuilder(builder: (context, c) {
            final cols = c.maxWidth > 1100 ? 3 : (c.maxWidth > 680 ? 2 : 1);
            final w = (c.maxWidth - (cols - 1) * 16) / cols;
            return Wrap(spacing: 16, runSpacing: 16, children: [
              for (final t in _list('templates'))
                SizedBox(width: w, child: _TemplateCard(tpl: t, theme: theme, busy: _busy == t['key'], onUse: t['available'] == true ? () => _configure(t) : null)),
            ]);
          }),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 26, pad, 10),
          child: Text(tr('Mes tableaux'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: pad),
          child: workbooks.isEmpty
              ? SectionCard(
                  child: Row(children: [
                    Icon(Icons.bookmark_add_outlined, color: hint),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(tr('Enregistrez un modèle avec vos réglages (thème, année, agence) pour le retrouver ici et le recevoir automatiquement par e-mail chaque lundi ou chaque 1er du mois.'),
                          style: TextStyle(color: hint)),
                    ),
                  ]),
                )
              : Card(
                  child: Column(children: [
                    for (final (i, w) in workbooks.indexed) ...[
                      if (i > 0) const Divider(height: 1),
                      _WorkbookTile(
                        w: w,
                        tpl: _tpl('${w['template']}'),
                        theme: _list('themes').where((t) => t['key'] == w['theme']).firstOrNull ?? theme,
                        frequencies: asMap(_d?['frequencies']),
                        busy: _busy == 'wb${w['id']}' || _busy == 'send${w['id']}',
                        canManage: _d?['can_manage'] == true,
                        onDownload: () => _download('tableaux_excel/workbooks/${w['id']}/download', _file('${w['name']}'), busy: 'wb${w['id']}').then((_) => _load()),
                        onEdit: () {
                          final t = _tpl('${w['template']}');
                          if (t != null) _configure(t, existing: w);
                        },
                        onSend: () => _send(w),
                        onDelete: () => _delete(w),
                      ),
                    ],
                  ]),
                ),
        ),
      ]),
    );
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.theme});
  final Map<String, dynamic> theme;

  @override
  Widget build(BuildContext context) {
    final p = _hex('${theme['primary']}');
    final deep = _hex('${theme['deep']}');
    final steps = [
      (Icons.dashboard_customize_outlined, 'Choisissez un modèle', 'Direction, ventes, trésorerie, créances, stock, achats'),
      (Icons.palette_outlined, 'Personnalisez', 'Thème, année, agence, titre'),
      (Icons.filter_alt_outlined, 'Filtrez dans Excel', 'Listes déroulantes : tout se recalcule'),
      (Icons.schedule_send_outlined, 'Recevez-le', 'Par e-mail chaque lundi ou chaque mois'),
    ];
    return AnimatedContainer(
      duration: const Duration(milliseconds: 300),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        gradient: LinearGradient(colors: [deep, Color.lerp(deep, p, .55)!], begin: Alignment.topLeft, end: Alignment.bottomRight),
      ),
      child: LayoutBuilder(builder: (context, c) {
        final cols = c.maxWidth > 900 ? 4 : (c.maxWidth > 520 ? 2 : 1);
        final w = (c.maxWidth - (cols - 1) * 14) / cols;
        return Wrap(spacing: 14, runSpacing: 14, children: [
          for (final (i, s) in steps.indexed)
            SizedBox(
              width: w,
              child: Row(children: [
                Container(
                  width: 40,
                  height: 40,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(12)),
                  child: Icon(s.$1, color: Colors.white, size: 21),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${i + 1}. ${s.$2}'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                    Text(tr(s.$3), style: TextStyle(color: Colors.white.withValues(alpha: .78), fontSize: 12.5)),
                  ]),
                ),
              ]),
            ),
        ]);
      }),
    );
  }
}

const _templateIcons = {
  'direction': Icons.insights,
  'ventes': Icons.shopping_cart_outlined,
  'tresorerie': Icons.account_balance_wallet_outlined,
  'creances': Icons.request_quote_outlined,
  'stock': Icons.inventory_2_outlined,
  'achats': Icons.local_shipping_outlined,
};

class _TemplateCard extends StatelessWidget {
  const _TemplateCard({required this.tpl, required this.theme, required this.onUse, required this.busy});
  final Map<String, dynamic> tpl, theme;
  final VoidCallback? onUse;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final available = onUse != null;
    return Opacity(
      opacity: available ? 1 : .55,
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onUse,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            AspectRatio(aspectRatio: 16 / 8.5, child: _Thumb(kind: '${tpl['key']}', theme: theme, title: '${tpl['name']}')),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Icon(_templateIcons[tpl['key']] ?? Icons.table_chart_outlined, size: 19, color: _hex('${theme['primary']}')),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr('${tpl['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5))),
                ]),
                const SizedBox(height: 6),
                Text(tr('${tpl['description']}'), maxLines: 3, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12.8, height: 1.35)),
                const SizedBox(height: 10),
                Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final f in (tpl['filters'] as List? ?? const []))
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(color: _hex('${theme['primary']}').withValues(alpha: .08), borderRadius: BorderRadius.circular(20)),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        Icon(Icons.filter_alt_outlined, size: 12, color: _hex('${theme['primary']}')),
                        const SizedBox(width: 3),
                        Text(tr('$f'), style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: _hex('${theme['primary']}'))),
                      ]),
                    ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(color: hint.withValues(alpha: .08), borderRadius: BorderRadius.circular(20)),
                    child: Text(tr('${tpl['charts']} graphiques'), style: TextStyle(fontSize: 11.5, color: hint, fontWeight: FontWeight.w600)),
                  ),
                ]),
                const SizedBox(height: 12),
                Row(children: [
                  if (!available)
                    Expanded(child: Text(tr('Nécessite le module ${(tpl['requires'] as List).join(', ')}'), style: TextStyle(color: hint, fontSize: 12)))
                  else
                    const Spacer(),
                  FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: _hex('${theme['primary']}')),
                    onPressed: busy ? null : onUse,
                    icon: busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.grid_on, size: 18),
                    label: Text(tr(busy ? 'Génération…' : 'Créer le classeur')),
                  ),
                ]),
              ]),
            ),
          ]),
        ),
      ),
    );
  }
}

/// Aperçu dessiné du tableau de bord Excel dans le thème choisi.
class _Thumb extends StatelessWidget {
  const _Thumb({required this.kind, required this.theme, required this.title});
  final String kind, title;
  final Map<String, dynamic> theme;

  @override
  Widget build(BuildContext context) => TweenAnimationBuilder<double>(
        key: ValueKey('${theme['key']}$kind'),
        tween: Tween(begin: 0, end: 1),
        duration: const Duration(milliseconds: 700),
        curve: Curves.easeOutCubic,
        builder: (context, t, _) => CustomPaint(painter: _ThumbPainter(kind, theme, title, t)),
      );
}

class _ThumbPainter extends CustomPainter {
  _ThumbPainter(this.kind, this.theme, this.title, this.t);
  final String kind, title;
  final Map<String, dynamic> theme;
  final double t;

  @override
  void paint(Canvas canvas, Size s) {
    final dark = theme['dark'] == true;
    final p = _hex('${theme['primary']}');
    final deep = _hex('${theme['deep']}');
    final tint = _hex('${theme['tint']}');
    final colors = [for (final c in theme['chart'] as List) _hex('$c')];
    final bg = dark ? _hex('0B1B3A') : const Color(0xFFF7F9FC);
    final card = dark ? tint : Colors.white;
    final line = dark ? Colors.white12 : const Color(0xFFE3E8F1);
    canvas.drawRect(Offset.zero & s, Paint()..color = bg);
    // Feuille Excel : en-têtes de colonnes
    final colH = s.height * .06;
    canvas.drawRect(Rect.fromLTWH(0, 0, s.width, colH), Paint()..color = dark ? const Color(0xFF13274F) : const Color(0xFFE9EDF3));
    for (var i = 1; i < 12; i++) {
      final x = s.width * i / 12;
      canvas.drawLine(Offset(x, 0), Offset(x, colH), Paint()..color = line);
    }
    final m = s.width * .035;
    var y = colH + m * .6;
    // Bandeau
    final banner = Rect.fromLTWH(m, y, s.width - 2 * m, s.height * .13);
    canvas.drawRRect(RRect.fromRectAndRadius(banner, const Radius.circular(4)), Paint()..color = dark ? deep : p);
    final tp = TextPainter(
      text: TextSpan(text: title, style: TextStyle(color: Colors.white, fontSize: s.height * .055, fontWeight: FontWeight.w800)),
      textDirection: TextDirection.ltr,
      maxLines: 1,
      ellipsis: '…',
    )..layout(maxWidth: banner.width * .7);
    tp.paint(canvas, Offset(banner.left + 8, banner.center.dy - tp.height / 2));
    y = banner.bottom + m * .6;
    // Filtres
    for (var i = 0; i < 3; i++) {
      final r = Rect.fromLTWH(m + i * (s.width - 2 * m) / 4 + s.width * .06, y, (s.width - 2 * m) / 4 - s.width * .08, s.height * .05);
      canvas.drawRRect(RRect.fromRectAndRadius(r, const Radius.circular(2)), Paint()..color = dark ? deep : tint);
      canvas.drawRRect(RRect.fromRectAndRadius(r, const Radius.circular(2)), Paint()
        ..color = p
        ..style = PaintingStyle.stroke
        ..strokeWidth = .8);
    }
    y += s.height * .05 + m * .6;
    // Indicateurs
    final kw = (s.width - 2 * m - 3 * m * .5) / 4;
    final kh = s.height * .14;
    for (var i = 0; i < 4; i++) {
      final r = Rect.fromLTWH(m + i * (kw + m * .5), y, kw, kh);
      canvas.drawRRect(RRect.fromRectAndRadius(r, const Radius.circular(3)), Paint()..color = i == 0 ? p : card);
      if (i > 0) canvas.drawRect(Rect.fromLTWH(r.left, r.top, r.width, 2), Paint()..color = colors[i % colors.length]);
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(r.left + 5, r.top + kh * .5, kw * .6 * t, kh * .2), const Radius.circular(2)),
          Paint()..color = i == 0 ? Colors.white : (dark ? Colors.white70 : deep.withValues(alpha: .75)));
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(r.left + 5, r.top + kh * .22, kw * .45, kh * .1), const Radius.circular(2)),
          Paint()..color = (i == 0 ? Colors.white : (dark ? Colors.white : deep)).withValues(alpha: .35));
    }
    y += kh + m * .6;
    // Graphiques
    final gh = s.height - y - m * .6;
    final left = Rect.fromLTWH(m, y, (s.width - 2 * m) * .62, gh);
    final right = Rect.fromLTWH(left.right + m * .5, y, s.width - 2 * m - left.width - m * .5, gh);
    for (final r in [left, right]) {
      canvas.drawRRect(RRect.fromRectAndRadius(r, const Radius.circular(3)), Paint()..color = card);
    }
    final seed = kind.codeUnits.fold<int>(0, (a, b) => a + b);
    final rnd = math.Random(seed);
    final inner = left.deflate(8);
    if (kind == 'tresorerie' || kind == 'creances' || kind == 'stock') {
      final n = kind == 'creances' ? 5 : 12;
      final bw = inner.width / n;
      for (var i = 0; i < n; i++) {
        final h1 = inner.height * (kind == 'creances' ? [.9, .55, .35, .25, .4][i] : (.35 + rnd.nextDouble() * .6)) * t;
        canvas.drawRect(Rect.fromLTWH(inner.left + i * bw + bw * .15, inner.bottom - h1, bw * (kind == 'tresorerie' ? .35 : .7), h1), Paint()..color = colors[0]);
        if (kind == 'tresorerie') {
          final h2 = inner.height * (.2 + rnd.nextDouble() * .5) * t;
          canvas.drawRect(Rect.fromLTWH(inner.left + i * bw + bw * .52, inner.bottom - h2, bw * .35, h2), Paint()..color = colors[1]);
        }
      }
    } else {
      Path path(double base, double amp) {
        final pth = Path();
        for (var i = 0; i < 12; i++) {
          final x = inner.left + inner.width * i / 11;
          final v = base + amp * math.sin(i * .9 + seed) + rnd.nextDouble() * .12;
          final yy = inner.bottom - inner.height * v.clamp(.05, .95) * t;
          i == 0 ? pth.moveTo(x, yy) : pth.lineTo(x, yy);
        }
        return pth;
      }

      canvas.drawPath(path(.4, .15), Paint()
        ..color = (dark ? Colors.white38 : const Color(0xFFA0AEC0))
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.4);
      canvas.drawPath(path(.6, .2), Paint()
        ..color = colors[0]
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.4
        ..strokeJoin = StrokeJoin.round);
    }
    // Anneau ou barres horizontales
    final c = right.center;
    final rad = math.min(right.width, right.height) * .32;
    if (kind == 'achats' || kind == 'creances') {
      final ir = right.deflate(10);
      for (var i = 0; i < 5; i++) {
        final w = ir.width * (1 - i * .17) * t;
        canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(ir.left, ir.top + i * ir.height / 5 + 2, w, ir.height / 5 - 5), const Radius.circular(2)), Paint()..color = colors[0].withValues(alpha: 1 - i * .12));
      }
    } else {
      var a = -math.pi / 2;
      for (final (i, f) in [.42, .24, .16, .1, .08].indexed) {
        final sw = f * 2 * math.pi * t;
        canvas.drawArc(Rect.fromCircle(center: c, radius: rad), a, sw - .03, false, Paint()
          ..color = colors[i % colors.length]
          ..style = PaintingStyle.stroke
          ..strokeWidth = rad * .45);
        a += sw;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _ThumbPainter o) => o.t != t || o.theme != theme;
}

class _WorkbookTile extends StatelessWidget {
  const _WorkbookTile(
      {required this.w,
      required this.tpl,
      required this.theme,
      required this.frequencies,
      required this.busy,
      required this.canManage,
      required this.onDownload,
      required this.onEdit,
      required this.onSend,
      required this.onDelete});
  final Map<String, dynamic> w, theme, frequencies;
  final Map<String, dynamic>? tpl;
  final bool busy, canManage;
  final VoidCallback onDownload, onEdit, onSend, onDelete;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final p = _hex('${theme['primary']}');
    final recipients = (w['recipients'] as List? ?? const []).length;
    final scheduled = w['frequency'] != null && w['frequency'] != 'none';
    final status = w['last_status'] as String?;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(children: [
        Container(
          width: 44,
          height: 44,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: p.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
          child: Icon(_templateIcons[w['template']] ?? Icons.table_chart_outlined, color: p),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${w['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 2),
            Wrap(spacing: 10, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
              Text(tr('${tpl?['name'] ?? w['template']} · ${theme['name']}${w['year'] != null ? ' · ${w['year']}' : ''}'), style: TextStyle(color: hint, fontSize: 12.5)),
              if (scheduled)
                Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.schedule_send_outlined, size: 14, color: p),
                  const SizedBox(width: 3),
                  Text(tr('${frequencies[w['frequency']] ?? ''} · $recipients destinataire(s)'), style: TextStyle(color: p, fontSize: 12.5, fontWeight: FontWeight.w600)),
                ]),
              if (status == 'failed')
                Tooltip(
                  message: '${w['last_error'] ?? ''}',
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(Icons.error_outline, size: 14, color: YColors.danger),
                    SizedBox(width: 3),
                    Text(tr('Dernier envoi en échec'), style: TextStyle(color: YColors.danger, fontSize: 12.5)),
                  ]),
                )
              else if (w['last_sent_at'] != null)
                Text(tr('Envoyé le ${dateTimeFr(w['last_sent_at'] as String)}'), style: TextStyle(color: hint, fontSize: 12.5)),
              if ((w['downloads'] as num? ?? 0) > 0) Text(tr('${w['downloads']} téléchargement(s)'), style: TextStyle(color: hint, fontSize: 12.5)),
            ]),
          ]),
        ),
        if (busy)
          const Padding(padding: EdgeInsets.all(10), child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)))
        else ...[
          IconButton(tooltip: tr('Télécharger le classeur à jour'), onPressed: onDownload, icon: Icon(Icons.download_rounded, color: p)),
          if (canManage) ...[
            if (recipients > 0) IconButton(tooltip: tr('Envoyer maintenant'), onPressed: onSend, icon: const Icon(Icons.send_outlined)),
            IconButton(tooltip: tr('Modifier'), onPressed: onEdit, icon: const Icon(Icons.tune)),
            IconButton(tooltip: tr('Supprimer'), onPressed: onDelete, icon: const Icon(Icons.delete_outline)),
          ],
        ],
      ]),
    );
  }
}

class _ConfigDialog extends StatefulWidget {
  const _ConfigDialog(
      {required this.tpl,
      required this.themes,
      required this.years,
      required this.branches,
      required this.frequencies,
      required this.canManage,
      required this.initialTheme,
      this.existing});
  final Map<String, dynamic> tpl;
  final List<Map<String, dynamic>> themes, branches;
  final List<int> years;
  final Map<String, dynamic> frequencies;
  final bool canManage;
  final String initialTheme;
  final Map<String, dynamic>? existing;

  @override
  State<_ConfigDialog> createState() => _ConfigDialogState();
}

class _ConfigDialogState extends State<_ConfigDialog> {
  late final _title = TextEditingController(text: '${widget.existing?['name'] ?? widget.tpl['name']}');
  late final _to = TextEditingController(text: ((widget.existing?['recipients'] as List?) ?? const []).join(', '));
  late final _message = TextEditingController(text: '${widget.existing?['message'] ?? ''}');
  late String _theme = widget.initialTheme;
  late int? _year = (widget.existing?['year'] as num?)?.toInt();
  late String? _branch = widget.existing?['branch_id'] as String?;
  late bool _protect = widget.existing?['protect'] == true;
  late String _frequency = '${widget.existing?['frequency'] ?? 'none'}';
  late bool _save = widget.existing != null;

  Map<String, dynamic> _options() => {
        'title': _title.text.trim().isEmpty ? widget.tpl['name'] : _title.text.trim(),
        'theme': _theme,
        'year': _year,
        'branch_id': _branch,
        'protect': _protect,
        if (_save) ...{
          'frequency': _frequency,
          'recipients': _to.text.split(RegExp(r'[,;\s]+')).map((e) => e.trim()).where((e) => e.isNotEmpty).toList(),
          'message': _message.text.trim().isEmpty ? null : _message.text.trim(),
        },
      };

  @override
  Widget build(BuildContext context) {
    final theme = widget.themes.firstWhere((t) => t['key'] == _theme, orElse: () => widget.themes.first);
    final hint = Theme.of(context).hintColor;
    return YAlertDialog(
      title: Text(tr(widget.existing != null ? 'Modifier « ${widget.existing!['name']} »' : widget.tpl['name'] as String)),
      content: SizedBox(
        width: 560,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            ClipRRect(borderRadius: BorderRadius.circular(12), child: AspectRatio(aspectRatio: 16 / 7, child: _Thumb(kind: '${widget.tpl['key']}', theme: theme, title: _title.text))),
            const SizedBox(height: 14),
            TextField(controller: _title, decoration: InputDecoration(labelText: tr('Titre du classeur')), onChanged: (_) => setState(() {})),
            const SizedBox(height: 14),
            Text(tr('Thème'), style: TextStyle(color: hint, fontSize: 12.5)),
            const SizedBox(height: 6),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final t in widget.themes)
                ChoiceChip(
                  selected: _theme == t['key'],
                  onSelected: (_) => setState(() => _theme = t['key'] as String),
                  avatar: CircleAvatar(backgroundColor: _hex('${t['primary']}'), radius: 7),
                  label: Text(tr('${t['name']}')),
                ),
            ]),
            const SizedBox(height: 14),
            Row(children: [
              if (widget.tpl['period'] == true)
                Expanded(
                  child: DropdownButtonFormField<int?>(
                    initialValue: _year,
                    decoration: InputDecoration(labelText: tr('Exercice')),
                    items: [
                      DropdownMenuItem(value: null, child: Text(tr('Année en cours'))),
                      for (final y in widget.years) DropdownMenuItem(value: y, child: Text(tr('$y (et ${y - 1})'))),
                    ],
                    onChanged: (v) => setState(() => _year = v),
                  ),
                ),
              if (widget.tpl['period'] == true && widget.branches.isNotEmpty) const SizedBox(width: 12),
              if (widget.branches.isNotEmpty)
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    initialValue: _branch,
                    decoration: InputDecoration(labelText: tr('Agence')),
                    items: [
                      DropdownMenuItem(value: null, child: Text(tr('Toutes les agences'))),
                      for (final b in widget.branches) DropdownMenuItem(value: b['id'] as String, child: Text(tr('${b['name']}'))),
                    ],
                    onChanged: (v) => setState(() => _branch = v),
                  ),
                ),
            ]),
            const SizedBox(height: 6),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: _protect,
              onChanged: (v) => setState(() => _protect = v),
              title: Text(tr('Protéger la mise en page')),
              subtitle: Text(tr('Seuls les filtres restent modifiables sur le tableau de bord')),
            ),
            if (widget.canManage) ...[
              const Divider(),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: _save,
                onChanged: widget.existing != null ? null : (v) => setState(() => _save = v),
                title: Text(tr('Enregistrer dans « Mes tableaux »')),
                subtitle: Text(tr('Pour le retrouver et le recevoir automatiquement par e-mail')),
              ),
              if (_save) ...[
                DropdownButtonFormField<String>(
                  initialValue: _frequency,
                  decoration: InputDecoration(labelText: tr('Envoi automatique')),
                  items: [for (final e in widget.frequencies.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                  onChanged: (v) => setState(() => _frequency = v ?? 'none'),
                ),
                if (_frequency != 'none') ...[
                  const SizedBox(height: 10),
                  TextField(controller: _to, decoration: InputDecoration(labelText: tr('Destinataires'), hintText: tr('dg@societe.ci, compta@societe.ci'))),
                  const SizedBox(height: 10),
                  TextField(controller: _message, maxLines: 2, decoration: InputDecoration(labelText: tr('Message (facultatif)'))),
                ],
              ],
            ],
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        if (_save) OutlinedButton.icon(onPressed: () => Navigator.pop(context, {'action': 'save', 'options': _options()}), icon: const Icon(Icons.bookmark_add_outlined, size: 18), label: Text(tr('Enregistrer'))),
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: _hex('${theme['primary']}')),
          onPressed: () => Navigator.pop(context, {'action': 'download', 'options': _options()}),
          icon: const Icon(Icons.download_rounded, size: 18),
          label: Text(tr('Télécharger')),
        ),
      ],
    );
  }
}
