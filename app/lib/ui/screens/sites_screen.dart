import 'dart:math' as math;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'sites_shop.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const siteRequestLabels = {
  'submitted': 'Envoyée', 'quoted': 'Devis reçu', 'accepted': 'Devis accepté', 'refused': 'Devis refusé', 'in_progress': 'En réalisation',
  'delivered': 'Site livré', 'rejected': 'Refusée', 'cancelled': 'Annulée',
};

Color siteRequestColor(String s) => switch (s) {
      'submitted' => YColors.blue,
      'quoted' => YColors.warning,
      'accepted' || 'in_progress' => const Color(0xFF0FA3B1),
      'delivered' => YColors.success,
      _ => YColors.muted,
    };

const siteDomainLabels = {'none': 'Aucun', 'pending': 'En attente du DNS', 'verified': 'Vérifié, à faire pointer', 'active': 'Actif', 'error': 'Erreur'};

Color hexColor(dynamic v, [Color fallback = YColors.blue]) {
  final h = '${v ?? ''}'.replaceAll('#', '');
  final n = h.length == 6 ? int.tryParse(h, radix: 16) : null;
  return n == null ? fallback : Color(0xFF000000 | n);
}

String colorHex(Color c) => '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0').toUpperCase()}';

Future<void> openUrl(String url) => safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);

/// Menu « Site web » : modèles, demande sur mesure, demandes et devis, personnalisation, domaine, messages, statistiques.
class SitesScreen extends StatefulWidget {
  const SitesScreen({super.key});

  @override
  State<SitesScreen> createState() => _SitesScreenState();
}

class _SitesScreenState extends State<SitesScreen> {
  Map<String, dynamic>? _o;
  Map<String, dynamic>? _tpl;
  Map<String, dynamic>? _pricing;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final r = await Future.wait([api.get('sites/overview'), if (_tpl == null) api.get('sites/templates'), if (_pricing == null) api.get('sites/pricing')]);
      if (!mounted) return;
      setState(() {
        _o = asMap(r[0]['data']);
        if (r.length > 1) _tpl = asMap(r[1]);
        if (r.length > 2) _pricing = asMap(r[2]['data']);
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final manage = session.can('sites.manage');
    if (_error != null && _o == null) {
      return Column(children: [
        PageHeader(title: tr('Site web'), subtitle: tr('Votre site vitrine relié à l\'ERP')),
        Expanded(child: OnlineOnlyNotice(error: _error!, onRetry: _load)),
      ]);
    }
    if (_o == null || _tpl == null) return const LoadingSkeleton();
    final site = _o!['site'] is Map ? asMap(_o!['site']) : null;
    final requests = [for (final r in (_o!['requests'] as List? ?? const [])) asMap(r)];
    final pendingQuote = requests.where((r) => r['status'] == 'quoted').length;
    final tabs = <(Tab, Widget)>[
      if (site != null) ...[
        (Tab(icon: Icon(Icons.dashboard_outlined, size: 18), text: tr('Mon site')), _SiteHome(site: site, stats: _o!['stats'] as Map?, reload: _load)),
        if (manage) (Tab(icon: Icon(Icons.brush_outlined, size: 18), text: tr('Personnaliser')), SiteEditor(site: site, templates: _tpl!, onSaved: _load)),
        (Tab(icon: Icon(Icons.storefront_outlined, size: 18), text: tr('Boutique')), const ShopTab()),
        (Tab(icon: Icon(Icons.article_outlined, size: 18), text: tr('Blog')), const BlogTab()),
        (Tab(icon: const Icon(Icons.mail_outline, size: 18), text: tr('Messages${toDouble(site['unread']) > 0 ? ' (${site['unread']})' : ''}')), _Messages(reload: _load)),
        if (manage) (Tab(icon: Icon(Icons.dns_outlined, size: 18), text: tr('Domaine')), _DomainTab(site: site, platform: asMap(_o!['platform']), onChanged: _load)),
      ],
      (Tab(icon: Icon(Icons.grid_view_rounded, size: 18), text: tr('Modèles')), TemplateGallery(data: _tpl!, site: site, canChoose: manage, onDone: _load)),
      if (manage) (Tab(icon: Icon(Icons.design_services_outlined, size: 18), text: tr('Site sur mesure')), _CustomRequest(pricing: _pricing ?? {}, sectors: _tpl!['sectors'] as List, onSent: _load)),
      (Tab(icon: const Icon(Icons.receipt_long_outlined, size: 18), text: tr('Demandes${pendingQuote > 0 ? ' ($pendingQuote)' : ''}')), _Requests(requests: requests, canDecide: manage, onChanged: _load)),
    ];
    return DefaultTabController(
      key: ValueKey('${site != null}-${tabs.length}'),
      length: tabs.length,
      initialIndex: site == null && pendingQuote > 0 ? tabs.length - 1 : 0,
      child: Column(children: [
        PageHeader(
          title: tr('Site web'),
          subtitle: site == null ? 'Choisissez un modèle ou demandez un site sur mesure : il sera relié à votre ERP' : '${site['name']} · ${site['url']}',
          actions: [
            if (site != null) OutlinedButton.icon(onPressed: () => openUrl('${site['status'] == 'published' ? site['url'] : site['preview_url']}'), icon: const Icon(Icons.open_in_new), label: Text(tr(site['status'] == 'published' ? 'Voir le site' : 'Aperçu'))),
            IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
          ],
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) t.$1]),
        const Divider(height: 1),
        Expanded(child: TabBarView(children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

// ================================================================= Mon site

class _SiteHome extends StatelessWidget {
  const _SiteHome({required this.site, required this.stats, required this.reload});

  final Map<String, dynamic> site;
  final Map? stats;
  final Future<void> Function() reload;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final hint = Theme.of(context).hintColor;
    final st = asMap(stats);
    final days = [for (final d in (st['by_day'] as List? ?? const [])) asMap(d)];
    final refs = [for (final d in (st['referrers'] as List? ?? const [])) asMap(d)];
    final published = site['status'] == 'published';
    final suspended = site['status'] == 'suspended';
    final narrow = MediaQuery.of(context).size.width < 700;
    Widget kpi(int i, String l, dynamic v, IconData ic, Color c) => SizedBox(
          width: narrow ? (MediaQuery.of(context).size.width - 44) / 2 : 220,
          child: AnimatedFadeIn(
            index: i,
            child: HoverLift(
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(children: [
                    Container(padding: const EdgeInsets.all(8), decoration: BoxDecoration(color: c.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)), child: Icon(ic, color: c, size: 20)),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        CountUp(value: toDouble(v), format: (x) => '${x.round()}', style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800)),
                        Text(tr(l), style: TextStyle(color: hint, fontSize: 12.5)),
                      ]),
                    ),
                  ]),
                ),
              ),
            ),
          ),
        );
    Future<void> publish(bool on) async {
      try {
        await session.api.post('sites/site/publish', {'published': on});
        await reload();
        if (context.mounted) toast(context, on ? 'Site publié : il est en ligne.' : 'Site repassé en brouillon.');
      } catch (e) {
        if (context.mounted) toast(context, errorText(e), error: true);
      }
    }

    return RefreshIndicator(
      onRefresh: reload,
      child: ListView(padding: EdgeInsets.all(narrow ? 12 : 20), children: [
        AnimatedFadeIn(
          child: Card(
            clipBehavior: Clip.antiAlias,
            child: Container(
              decoration: BoxDecoration(gradient: LinearGradient(colors: [hexColor(site['theme']?['primary']), hexColor(site['theme']?['dark'], YColors.night)], begin: Alignment.topLeft, end: Alignment.bottomRight)),
              padding: const EdgeInsets.all(20),
              child: Wrap(spacing: 16, runSpacing: 14, crossAxisAlignment: WrapCrossAlignment.center, children: [
                SizedBox(
                  width: narrow ? double.infinity : 520,
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Flexible(child: Text(tr('${site['name']}'), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800))),
                      const SizedBox(width: 10),
                      DecoratedBox(
                        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
                        child: StatusChip(suspended ? 'Suspendu' : (published ? 'En ligne' : 'Brouillon'), color: suspended ? YColors.danger : (published ? YColors.success : YColors.warning)),
                      ),
                    ]),
                    const SizedBox(height: 6),
                    SelectableText(tr('${site['url']}'), style: const TextStyle(color: Colors.white70)),
                    Text(tr('Modèle : ${site['template_name']}'), style: const TextStyle(color: Colors.white60, fontSize: 12.5)),
                  ]),
                ),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  if (session.can('sites.manage') && !suspended)
                    FilledButton.icon(
                      style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: YColors.night),
                      onPressed: () => publish(!published),
                      icon: Icon(published ? Icons.visibility_off_outlined : Icons.rocket_launch_outlined),
                      label: Text(tr(published ? 'Dépublier' : 'Publier le site')),
                    ),
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54)),
                    onPressed: () => openUrl('${published ? site['url'] : site['preview_url']}'),
                    icon: const Icon(Icons.open_in_new),
                    label: Text(tr(published ? 'Ouvrir' : 'Aperçu')),
                  ),
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54)),
                    onPressed: () => openUrl('https://wa.me/?text=${Uri.encodeComponent('Découvrez notre site : ${site['url']}')}'),
                    icon: const Icon(Icons.share_outlined),
                    label: Text(tr('Partager')),
                  ),
                ]),
              ]),
            ),
          ),
        ),
        if (!published && !suspended)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(tr('Le site n\'est visible que par vous (lien d\'aperçu). Personnalisez-le puis publiez-le.'), style: TextStyle(color: hint)),
          ),
        const SizedBox(height: 12),
        Wrap(spacing: 10, runSpacing: 10, children: [
          kpi(0, 'Visites (30 jours)', st['views'], Icons.visibility_outlined, YColors.blue),
          kpi(1, 'Messages (30 jours)', st['messages'], Icons.mail_outline, YColors.success),
          kpi(2, 'Messages non lus', st['unread'], Icons.mark_email_unread_outlined, YColors.warning),
          kpi(3, 'Visites depuis le début', st['total_views'], Icons.insights_outlined, YColors.night),
        ]),
        const SizedBox(height: 12),
        SectionCard(title: tr('Visites par jour'), child: SizedBox(height: 170, child: days.isEmpty ? const SizedBox() : _Bars(values: [for (final d in days) toDouble(d['views'])], labels: [for (final d in days) '${d['date']}'.substring(8)]))),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('D\'où viennent les visiteurs'),
          child: refs.isEmpty
              ? Text(tr('Pas encore de visites. Partagez l\'adresse du site sur vos réseaux, vos cartes de visite et WhatsApp.'), style: TextStyle(color: hint))
              : Column(children: [
                  for (final r in refs)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(children: [
                        SizedBox(width: 170, child: Text(tr(r['source'] == 'direct' ? 'Accès direct' : '${r['source']}'), overflow: TextOverflow.ellipsis)),
                        Expanded(
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(6),
                            child: LinearProgressIndicator(minHeight: 10, value: toDouble(r['views']) / math.max(1, toDouble(refs.first['views']))),
                          ),
                        ),
                        SizedBox(width: 50, child: Text(tr('${r['views']}'), textAlign: TextAlign.end)),
                      ]),
                    ),
                ]),
        ),
        const SizedBox(height: 12),
        Card(
          child: ListTile(
            leading: const Icon(Icons.hub_outlined, color: YColors.blue),
            title: Text(tr('Relié à votre ERP')),
            subtitle: Text(tr('Produits et prix, événements avec inscription, prise de rendez-vous et offres d\'emploi s\'affichent d\'eux-mêmes. Chaque message du formulaire crée une opportunité CRM et un contact Marketing.')),
          ),
        ),
      ]),
    );
  }
}

class _Bars extends StatelessWidget {
  const _Bars({required this.values, required this.labels});

  final List<double> values;
  final List<String> labels;

  @override
  Widget build(BuildContext context) {
    final max = values.fold<double>(1, math.max);
    return LayoutBuilder(
      builder: (context, box) => TweenAnimationBuilder<double>(
        tween: Tween(begin: 0, end: 1),
        duration: const Duration(milliseconds: 700),
        curve: Curves.easeOutCubic,
        builder: (context, t, _) => Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          for (var i = 0; i < values.length; i++)
            Expanded(
              child: Tooltip(
                message: tr('${labels[i]} : ${values[i].round()} visite(s)'),
                child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [
                  Container(
                    height: math.max(2, (box.maxHeight - 18) * values[i] / max * t),
                    margin: const EdgeInsets.symmetric(horizontal: 1.5),
                    decoration: BoxDecoration(color: YColors.blue.withValues(alpha: values[i] == 0 ? .15 : .8), borderRadius: BorderRadius.circular(3)),
                  ),
                  SizedBox(height: 18, child: i % 5 == 0 ? Text(tr(labels[i]), style: TextStyle(fontSize: 10, color: Theme.of(context).hintColor)) : null),
                ]),
              ),
            ),
        ]),
      ),
    );
  }
}

// ================================================================= Galerie

/// Vignette dessinée d'un modèle : barre de navigation, bannière selon le style, cartes.
class TemplateThumb extends StatelessWidget {
  const TemplateThumb({super.key, required this.theme, this.icon = Icons.storefront_outlined, this.title = ''});

  final Map theme;
  final IconData icon;
  final String title;

  @override
  Widget build(BuildContext context) => AspectRatio(aspectRatio: 16 / 10, child: CustomPaint(painter: _ThumbPainter(theme, title), child: const SizedBox.expand()));
}

class _ThumbPainter extends CustomPainter {
  _ThumbPainter(this.t, this.title);

  final Map t;
  final String title;

  @override
  void paint(Canvas canvas, Size s) {
    final p = hexColor(t['primary']), d = hexColor(t['dark'], YColors.night), a = hexColor(t['accent'], YColors.yellow), bg = hexColor(t['background'], Colors.white);
    final r = (toDouble(t['radius']) / 3).clamp(1.0, 8.0);
    final hero = '${t['hero']}';
    canvas.clipRect(Offset.zero & s);
    canvas.drawRect(Offset.zero & s, Paint()..color = bg);
    final navH = s.height * .1;
    final nav = '${t['nav']}';
    canvas.drawRect(Rect.fromLTWH(0, 0, s.width, navH), Paint()..color = nav == 'dark' ? d : Colors.white);
    canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(s.width * .05, navH * .28, navH * .44, navH * .44), Radius.circular(r)), Paint()..color = p);
    for (var i = 0; i < 3; i++) {
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(s.width * (.55 + i * .09), navH * .44, s.width * .06, navH * .12), const Radius.circular(2)), Paint()..color = (nav == 'dark' ? Colors.white : d).withValues(alpha: .35));
    }
    canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(s.width * .83, navH * .25, s.width * .12, navH * .5), Radius.circular(r)), Paint()..color = p);
    final top = navH, hh = s.height * .52;
    final heroRect = Rect.fromLTWH(0, top, s.width, hh);
    Color txt = d;
    double tx = s.width * .07, tw = s.width * .45;
    if (hero == 'gradient') {
      canvas.drawRect(heroRect, Paint()..shader = LinearGradient(colors: [p, d], begin: Alignment.topLeft, end: Alignment.bottomRight).createShader(heroRect));
      canvas.drawCircle(Offset(s.width * .9, top + hh * .15), hh * .5, Paint()..color = a.withValues(alpha: .25));
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(s.width * .62, top + hh * .18, s.width * .28, hh * .64), Radius.circular(r * 2)), Paint()..color = Colors.white.withValues(alpha: .92));
      canvas.drawCircle(Offset(s.width * .76, top + hh * .45), hh * .14, Paint()..color = p.withValues(alpha: .25));
      txt = Colors.white;
    } else if (hero == 'split') {
      canvas.drawRect(Rect.fromLTWH(s.width * .5, top, s.width * .5, hh), Paint()..color = d);
      canvas.drawCircle(Offset(s.width * .8, top + hh * .35), hh * .25, Paint()..color = p);
      canvas.save();
      canvas.translate(s.width * .64, top + hh * .7);
      canvas.rotate(.2);
      canvas.drawRect(Rect.fromCenter(center: Offset.zero, width: hh * .38, height: hh * .38), Paint()..color = a);
      canvas.restore();
    } else {
      final c = Offset(s.width / 2, top + hh / 2);
      for (final f in [.32, .44, .56]) {
        canvas.drawCircle(c, hh * f, Paint()
          ..color = (f == .32 ? a : p).withValues(alpha: .3)
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1);
      }
      tx = s.width * .2;
      tw = s.width * .6;
    }
    final centered = hero != 'gradient' && hero != 'split';
    void bar(double y, double w, double h, Color c) {
      final x = centered ? (s.width - w) / 2 : tx;
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(x, y, w, h), Radius.circular(h / 2)), Paint()..color = c);
    }

    bar(top + hh * .22, tw * .9, hh * .09, txt);
    bar(top + hh * .36, tw * .7, hh * .09, txt);
    bar(top + hh * .53, tw * .8, hh * .045, txt.withValues(alpha: .45));
    final bx = centered ? s.width / 2 - s.width * .1 : tx;
    canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(bx, top + hh * .68, s.width * .17, hh * .12), Radius.circular(r * 1.5)), Paint()..color = hero == 'gradient' ? Colors.white : p);
    final cy = top + hh + s.height * .07, ch = s.height * .26, gap = s.width * .03, cw = (s.width * .86 - gap * 2) / 3;
    final cards = '${t['cards']}';
    for (var i = 0; i < 3; i++) {
      final rr = RRect.fromRectAndRadius(Rect.fromLTWH(s.width * .07 + i * (cw + gap), cy, cw, ch), Radius.circular(r));
      if (cards == 'outline') {
        canvas.drawRRect(rr, Paint()
          ..color = d.withValues(alpha: .2)
          ..style = PaintingStyle.stroke);
      } else {
        if (cards == 'shadow') canvas.drawRRect(rr.shift(const Offset(0, 2)), Paint()..color = Colors.black.withValues(alpha: .07));
        canvas.drawRRect(rr, Paint()..color = cards == 'flat' ? Color.lerp(Colors.white, p, .08)! : Colors.white);
      }
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(rr.left + cw * .12, cy + ch * .16, cw * .2, cw * .2), Radius.circular(r)), Paint()..color = p.withValues(alpha: .2));
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(rr.left + cw * .12, cy + ch * .6, cw * .6, ch * .09), const Radius.circular(2)), Paint()..color = d.withValues(alpha: .55));
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(rr.left + cw * .12, cy + ch * .76, cw * .45, ch * .07), const Radius.circular(2)), Paint()..color = d.withValues(alpha: .22));
    }
  }

  @override
  bool shouldRepaint(_ThumbPainter o) => o.t != t;
}

const sectorIcons = {
  'utensils': Icons.restaurant, 'bag': Icons.shopping_bag_outlined, 'scissors': Icons.content_cut, 'heart': Icons.favorite_border, 'hammer': Icons.construction,
  'briefcase': Icons.business_center_outlined, 'home': Icons.home_work_outlined, 'book': Icons.school_outlined, 'calendar': Icons.celebration_outlined, 'bed': Icons.hotel_outlined,
  'truck': Icons.local_shipping_outlined, 'leaf': Icons.agriculture_outlined, 'cpu': Icons.memory, 'scale': Icons.balance, 'users': Icons.volunteer_activism_outlined,
  'dumbbell': Icons.fitness_center, 'camera': Icons.photo_camera_outlined, 'car': Icons.directions_car_outlined,
};

class TemplateGallery extends StatefulWidget {
  const TemplateGallery({super.key, required this.data, required this.site, required this.canChoose, required this.onDone});

  final Map<String, dynamic> data;
  final Map<String, dynamic>? site;
  final bool canChoose;
  final Future<void> Function() onDone;

  @override
  State<TemplateGallery> createState() => _TemplateGalleryState();
}

class _TemplateGalleryState extends State<TemplateGallery> {
  String? _sector;
  String? _style;

  Future<void> _choose(Map<String, dynamic> t) async {
    final api = context.read<Session>().api;
    if (widget.site != null) {
      if (!await confirm(context, tr('Passer au modèle « ${t['name']} » ?'), tr('Vos textes, photos et sections sont conservés ; la mise en page et les couleurs par défaut changent (vos couleurs personnalisées restent).'), ok: tr('Changer de modèle'))) return;
      try {
        await api.put('sites/site', {'template': t['key']});
        await widget.onDone();
        if (mounted) toast(context, tr('Modèle appliqué.'));
      } catch (e) {
        if (mounted) toast(context, errorText(e), error: true);
      }
      return;
    }
    final name = TextEditingController(text: '${context.read<Session>().company['name'] ?? ''}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Demander le site « ${t['name']} »')),
        content: SizedBox(
          width: 440,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            TemplateThumb(theme: t['theme'] as Map),
            const SizedBox(height: 12),
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom affiché sur le site'))),
            const SizedBox(height: 10),
            Text(tr('Votre demande est envoyée à l\'équipe Yomara. Dès sa création, le site reprend vos coordonnées, votre logo, vos produits, événements et rendez-vous ; vous le personnalisez puis le publiez.'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 13)),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Envoyer la demande')))],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await api.post('sites/requests', {'type': 'template', 'template': t['key'], 'site_name': name.text.trim()});
      await widget.onDone();
      if (mounted) toast(context, tr('Demande envoyée : vous serez prévenu dès que votre site est prêt.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final all = [for (final t in (widget.data['data'] as List)) asMap(t)];
    final sectors = [for (final s in (widget.data['sectors'] as List)) asMap(s)];
    final styles = [for (final s in (widget.data['styles'] as List)) asMap(s)];
    final rows = all.where((t) => (_sector == null || t['sector'] == _sector) && (_style == null || t['style'] == _style)).toList();
    final current = widget.site?['template'];
    final w = MediaQuery.of(context).size.width;
    return CustomScrollView(slivers: [
      SliverPadding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 6),
        sliver: SliverToBoxAdapter(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${all.length} modèles · ${sectors.length} secteurs · ${styles.length} styles — l\'aperçu montre votre nom, vos coordonnées et vos produits.'), style: TextStyle(color: Theme.of(context).hintColor)),
            const SizedBox(height: 10),
            SizedBox(
              height: 40,
              child: ListView(scrollDirection: Axis.horizontal, children: [
                Padding(padding: const EdgeInsets.only(right: 6), child: ChoiceChip(label: Text(tr('Tous les secteurs')), selected: _sector == null, onSelected: (_) => setState(() => _sector = null))),
                for (final s in sectors)
                  Padding(
                    padding: const EdgeInsets.only(right: 6),
                    child: ChoiceChip(avatar: Icon(sectorIcons[s['icon']] ?? Icons.storefront_outlined, size: 16), label: Text(tr('${s['name']}')), selected: _sector == s['key'], onSelected: (v) => setState(() => _sector = v ? '${s['key']}' : null)),
                  ),
              ]),
            ),
            const SizedBox(height: 8),
            Wrap(spacing: 6, children: [
              ChoiceChip(label: Text(tr('Tous les styles')), selected: _style == null, onSelected: (_) => setState(() => _style = null)),
              for (final s in styles) Tooltip(message: '${s['description']}', child: ChoiceChip(label: Text(tr('${s['name']}')), selected: _style == s['key'], onSelected: (v) => setState(() => _style = v ? '${s['key']}' : null))),
            ]),
          ]),
        ),
      ),
      SliverPadding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        sliver: SliverGrid(
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: w < 560 ? 1 : (w < 1000 ? 2 : (w < 1400 ? 3 : 4)), mainAxisSpacing: 14, crossAxisSpacing: 14, childAspectRatio: 1.02),
          delegate: SliverChildBuilderDelegate(childCount: rows.length, (context, i) {
            final t = rows[i];
            final mine = t['key'] == current;
            return AnimatedFadeIn(
              key: ValueKey(t['key']),
              index: i % 8,
              child: HoverLift(
                child: Card(
                  clipBehavior: Clip.antiAlias,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: mine ? const BorderSide(color: YColors.yellow, width: 2.5) : BorderSide.none),
                  child: InkWell(
                    onTap: () => openUrl('${t['preview_url']}'),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      TemplateThumb(theme: t['theme'] as Map),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(14, 10, 14, 4),
                        child: Row(children: [
                          Icon(sectorIcons[t['icon']] ?? Icons.storefront_outlined, size: 18, color: hexColor(t['theme']['primary'])),
                          const SizedBox(width: 8),
                          Expanded(child: Text(tr('${t['sector_name']}'), style: const TextStyle(fontWeight: FontWeight.w700), overflow: TextOverflow.ellipsis)),
                          StatusChip('${t['style_name']}', color: hexColor(t['theme']['primary'])),
                        ]),
                      ),
                      Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: Text(tr('« ${t['hero_title']} »'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
                      const Spacer(),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
                        child: Row(children: [
                          TextButton.icon(onPressed: () => openUrl('${t['preview_url']}'), icon: const Icon(Icons.visibility_outlined, size: 18), label: Text(tr('Aperçu'))),
                          const Spacer(),
                          if (mine)
                            StatusChip(tr('Votre modèle'), color: YColors.success, icon: Icons.check)
                          else if (widget.canChoose)
                            FilledButton.tonal(onPressed: () => _choose(t), child: Text(tr(widget.site == null ? 'Choisir' : 'Appliquer'))),
                        ]),
                      ),
                    ]),
                  ),
                ),
              ),
            );
          }),
        ),
      ),
    ]);
  }
}

// ================================================================= Sur mesure

class _CustomRequest extends StatefulWidget {
  const _CustomRequest({required this.pricing, required this.sectors, required this.onSent});

  final Map<String, dynamic> pricing;
  final List sectors;
  final Future<void> Function() onSent;

  @override
  State<_CustomRequest> createState() => _CustomRequestState();
}

class _CustomRequestState extends State<_CustomRequest> {
  late final _name = TextEditingController(text: '${context.read<Session>().company['name'] ?? ''}');
  final _desc = TextEditingController(), _refs = TextEditingController(), _colors = TextEditingController(), _budget = TextEditingController(), _domain = TextEditingController(), _page = TextEditingController();
  final _pages = <String>['Accueil', 'À propos', 'Services', 'Contact'];
  final _features = <String>{};
  String? _sector;
  bool _urgent = false, _logo = false, _content = false, _busy = false;
  DateTime? _deadline;

  Map<String, dynamic> get _f => asMap(widget.pricing['features']);

  /// Même calcul que le serveur (estimation indicative, le devis officiel fait foi).
  ({List<(String, double, double)> lines, double sub, double tax, double total}) _estimate() {
    final p = widget.pricing;
    final lines = <(String, double, double)>[('${p['base']?['label'] ?? 'Conception du site'}', 1, toDouble(p['base']?['price']))];
    final included = toDouble(p['included_pages'] ?? 5);
    final pages = math.max(1, _pages.length).toDouble();
    if (pages > included) lines.add(('${p['extra_page']?['label'] ?? 'Page supplémentaire'}', pages - included, toDouble(p['extra_page']?['price'])));
    for (final k in _features) {
      final f = _f[k];
      if (f is Map) lines.add(('${f['label']}', 1, toDouble(f['price'])));
    }
    var sub = lines.fold<double>(0, (s, l) => s + l.$2 * l.$3);
    if (_urgent) {
      final pc = toDouble(p['urgent']?['percent'] ?? 25);
      lines.add(('${p['urgent']?['label'] ?? 'Livraison express'} (+${pc.round()} %)', 1, (sub * pc / 100).roundToDouble()));
      sub = lines.fold<double>(0, (s, l) => s + l.$2 * l.$3);
    }
    final tax = (sub * toDouble(p['tax_rate'] ?? 18) / 100).roundToDouble();
    return (lines: lines, sub: sub, tax: tax, total: sub + tax);
  }

  Future<void> _send() async {
    if (_desc.text.trim().isEmpty && _features.isEmpty) return toast(context, tr('Décrivez votre projet ou choisissez des fonctionnalités.'), error: true);
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('sites/requests', {
        'type': 'custom',
        'site_name': _name.text.trim(),
        'sector': ?_sector,
        'needs': {
          'pages': _pages, 'features': _features.toList(), 'description': _desc.text.trim(), 'references': _refs.text.trim(), 'colors': _colors.text.trim(),
          if (_budget.text.trim().isNotEmpty) 'budget': toDouble(_budget.text), if (_deadline != null) 'deadline': _deadline!.toIso8601String().substring(0, 10),
          'urgent': _urgent, 'has_logo': _logo, 'has_content': _content, 'domain': _domain.text.trim(),
        },
      });
      await widget.onSent();
      if (mounted) toast(context, tr('Demande envoyée : vous recevrez le devis dans le menu « Demandes ».'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final est = _estimate();
    final hint = Theme.of(context).hintColor;
    final wide = MediaQuery.of(context).size.width > 1100;
    final form = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionCard(
        title: tr('Votre projet'),
        child: Column(children: [
          Row(children: [
            Expanded(child: Field(label: tr('Nom du site'), child: TextField(controller: _name))),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Secteur'),
                child: DropdownButtonFormField<String?>(
                  initialValue: _sector,
                  isExpanded: true,
                  items: [const DropdownMenuItem(value: null, child: Text('—')), for (final s in widget.sectors) DropdownMenuItem(value: '${(s as Map)['key']}', child: Text(tr('${s['name']}')))],
                  onChanged: (v) => setState(() => _sector = v),
                ),
              ),
            ),
          ]),
          Field(label: tr('Décrivez ce que vous voulez'), child: TextField(controller: _desc, minLines: 3, maxLines: 8, decoration: InputDecoration(hintText: tr('Objectifs, public visé, ce que les visiteurs doivent pouvoir faire…')))),
          Field(label: tr('Sites que vous aimez (exemples)'), child: TextField(controller: _refs, decoration: InputDecoration(hintText: tr('Adresses de sites pour l\'inspiration')))),
          Row(children: [
            Expanded(child: Field(label: tr('Couleurs souhaitées'), child: TextField(controller: _colors, decoration: InputDecoration(hintText: tr('Ex. : vert et or, comme le logo'))))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Nom de domaine (si vous en avez un)'), child: TextField(controller: _domain, decoration: InputDecoration(hintText: tr('monentreprise.ci'))))),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Pages (${_pages.length})'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final p in _pages) InputChip(label: Text(tr(p)), onDeleted: _pages.length > 1 ? () => setState(() => _pages.remove(p)) : null),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _page, decoration: InputDecoration(isDense: true, hintText: tr('Ajouter une page (Équipe, Galerie, Tarifs…)')), onSubmitted: (_) => _addPage())),
            IconButton(onPressed: _addPage, icon: const Icon(Icons.add_circle_outline)),
          ]),
          const SizedBox(height: 4),
          Text(tr('${toDouble(widget.pricing['included_pages'] ?? 5).round()} pages incluses, puis ${money(toDouble(widget.pricing['extra_page']?['price']), cur)} HT par page.'), style: TextStyle(color: hint, fontSize: 12.5)),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Fonctionnalités'),
        child: Wrap(spacing: 8, runSpacing: 8, children: [
          for (final e in _f.entries)
            FilterChip(
              label: Text(tr('${(e.value as Map)['label']} · ${money(toDouble(e.value['price']), cur)}')),
              selected: _features.contains(e.key),
              onSelected: (v) => setState(() => v ? _features.add(e.key) : _features.remove(e.key)),
            ),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Délais et contenus'),
        child: Column(children: [
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('${widget.pricing['urgent']?['label'] ?? 'Livraison express'}')), subtitle: Text(tr('+${toDouble(widget.pricing['urgent']?['percent'] ?? 25).round()} %')), value: _urgent, onChanged: (v) => setState(() => _urgent = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('J\'ai déjà un logo')), value: _logo, onChanged: (v) => setState(() => _logo = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('J\'ai mes textes et mes photos')), value: _content, onChanged: (v) => setState(() => _content = v)),
          Row(children: [
            Expanded(child: Field(label: tr('Budget indicatif (facultatif)'), child: TextField(controller: _budget, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly]))),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Date souhaitée'),
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final d = await showDatePicker(context: context, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)), initialDate: _deadline ?? DateTime.now().add(const Duration(days: 30)));
                    if (d != null) setState(() => _deadline = d);
                  },
                  icon: const Icon(Icons.event),
                  label: Text(tr(_deadline == null ? 'Choisir' : dateFr(_deadline!.toIso8601String().substring(0, 10)))),
                ),
              ),
            ),
          ]),
        ]),
      ),
    ]);
    final summary = Card(
      color: YColors.night,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr('Estimation immédiate'), style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          TweenAnimationBuilder<double>(
            tween: Tween(end: est.total),
            duration: const Duration(milliseconds: 500),
            builder: (_, v, _) => Text(tr(money(v.roundToDouble(), cur)), style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w800)),
          ),
          Text(tr('TTC · ${money(est.sub, cur)} HT + TVA ${money(est.tax, cur)}'), style: const TextStyle(color: Colors.white60, fontSize: 12.5)),
          const Divider(color: Colors.white24, height: 26),
          for (final l in est.lines)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(child: Text(tr('${l.$2 > 1 ? '${l.$2.round()} × ' : ''}${l.$1}'), style: const TextStyle(color: Colors.white, fontSize: 13))),
                Text(tr(money(l.$2 * l.$3, cur)), style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
              ]),
            ),
          const SizedBox(height: 14),
          FilledButton.icon(
            style: FilledButton.styleFrom(backgroundColor: YColors.yellow, foregroundColor: YColors.night, padding: const EdgeInsets.symmetric(vertical: 16)),
            onPressed: _busy ? null : _send,
            icon: const Icon(Icons.send),
            label: Text(tr('Demander le devis')),
          ),
          const SizedBox(height: 8),
          Text(tr('Estimation indicative : le devis officiel vous est envoyé dans l\'ERP, à accepter en un clic.'), style: TextStyle(color: Colors.white60, fontSize: 12)),
        ]),
      ),
    );
    return ListView(padding: const EdgeInsets.all(16), children: [
      if (wide) Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(flex: 3, child: form), const SizedBox(width: 16), Expanded(flex: 2, child: summary)]) else ...[form, const SizedBox(height: 12), summary],
    ]);
  }

  void _addPage() {
    final p = _page.text.trim();
    if (p.isEmpty || _pages.contains(p)) return;
    setState(() {
      _pages.add(p);
      _page.clear();
    });
  }
}

// ================================================================= Demandes et devis

class _Requests extends StatelessWidget {
  const _Requests({required this.requests, required this.canDecide, required this.onChanged});

  final List<Map<String, dynamic>> requests;
  final bool canDecide;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    if (requests.isEmpty) {
      return EmptyState(icon: Icons.receipt_long_outlined, title: tr('Aucune demande'), message: tr('Choisissez un modèle ou décrivez votre site sur mesure.'));
    }
    Future<void> act(String path, Map<String, dynamic> body, String done) async {
      try {
        await context.read<Session>().api.post(path, body);
        await onChanged();
        if (context.mounted) toast(context, done);
      } catch (e) {
        if (context.mounted) toast(context, errorText(e), error: true);
      }
    }

    return ListView(padding: const EdgeInsets.all(16), children: [
      for (final (i, r) in requests.indexed)
        AnimatedFadeIn(
          index: i,
          child: Card(
            margin: const EdgeInsets.only(bottom: 12),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Icon(r['type'] == 'custom' ? Icons.design_services_outlined : Icons.grid_view_rounded, color: YColors.blue),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${r['site_name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                      Text(tr('${r['number']} · ${r['type'] == 'custom' ? 'Site sur mesure' : 'Modèle ${r['template_name'] ?? ''}'} · ${dateFr('${r['created_at']}'.substring(0, 10))}'), style: TextStyle(color: hint, fontSize: 12.5)),
                    ]),
                  ),
                  StatusChip(siteRequestLabels[r['status']] ?? '${r['status']}', color: siteRequestColor('${r['status']}')),
                ]),
                if (r['type'] == 'custom' && r['quote'] == null && toDouble(r['estimate']) > 0)
                  Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('Estimation : ${money(toDouble(r['estimate']), cur)} TTC — le devis officiel arrive bientôt.'), style: TextStyle(color: hint))),
                if (r['quote'] is Map) ...[
                  const Divider(height: 24),
                  Row(children: [
                    Expanded(child: Text(tr('Devis ${r['quote']['number']} · ${r['quote']['provider']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                    if (r['quote']['valid_until'] != null) Text(tr('valable jusqu\'au ${dateFr(r['quote']['valid_until'])}'), style: TextStyle(color: hint, fontSize: 12.5)),
                  ]),
                  const SizedBox(height: 6),
                  for (final l in (r['quote']['lines'] as List? ?? const []))
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: Row(children: [
                        Expanded(child: Text(tr('${toDouble((l as Map)['quantity']) != 1 ? '${toDouble(l['quantity']).round()} × ' : ''}${l['description']}'))),
                        Text(tr(money(toDouble(l['total']), cur))),
                      ]),
                    ),
                  const SizedBox(height: 6),
                  Row(children: [
                    if (toDouble(r['quote']['discount_percent']) > 0) Text(tr('Remise ${toDouble(r['quote']['discount_percent']).round()} % incluse'), style: TextStyle(color: hint)),
                    const Spacer(),
                    Text(tr('Total TTC  '), style: TextStyle(color: hint)),
                    Text(tr(money(toDouble(r['quote']['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                  ]),
                  if (r['quote']['terms'] != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('${r['quote']['terms']}'), style: TextStyle(color: hint, fontSize: 12.5))),
                ],
                if (r['admin_note'] != null && '${r['admin_note']}'.trim().isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('${r['admin_note']}'), style: TextStyle(color: hint))),
                if (canDecide && (r['status'] == 'quoted' || r['status'] == 'submitted'))
                  Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.end, children: [
                      TextButton(
                        onPressed: () async {
                          if (await confirm(context, tr('Annuler la demande ?'), tr('Vous pourrez en faire une nouvelle.'), ok: tr('Annuler la demande'), danger: true)) {
                            await act('sites/requests/${r['id']}/cancel', {}, 'Demande annulée.');
                          }
                        },
                        child: Text(tr('Annuler la demande')),
                      ),
                      if (r['status'] == 'quoted') ...[
                        OutlinedButton(
                          onPressed: () async {
                            final reason = TextEditingController();
                            final ok = await showDialog<bool>(
                              context: context,
                              builder: (c) => YAlertDialog(
                                title: Text(tr('Refuser le devis')),
                                content: TextField(controller: reason, maxLines: 3, decoration: InputDecoration(labelText: tr('Motif (aide à vous proposer mieux)'))),
                                actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Retour'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Refuser')))],
                              ),
                            );
                            if (ok == true) await act('sites/requests/${r['id']}/decide', {'accept': false, 'reason': reason.text.trim()}, 'Devis refusé.');
                          },
                          child: Text(tr('Refuser')),
                        ),
                        FilledButton.icon(
                          onPressed: () async {
                            if (await confirm(context, tr('Accepter le devis ?'), tr('Montant : ${money(toDouble(r['quote']['total']), cur)} TTC. L\'équipe lance la réalisation et vous envoie la facture d\'acompte.'), ok: tr('Accepter'))) {
                              await act('sites/requests/${r['id']}/decide', {'accept': true}, 'Devis accepté : la réalisation démarre.');
                            }
                          },
                          icon: const Icon(Icons.check),
                          label: Text(tr('Accepter le devis')),
                        ),
                      ],
                    ]),
                  ),
              ]),
            ),
          ),
        ),
    ]);
  }
}

// ================================================================= Personnalisation

class SiteEditor extends StatefulWidget {
  const SiteEditor({super.key, required this.site, required this.templates, required this.onSaved});

  final Map<String, dynamic> site;
  final Map<String, dynamic> templates;
  final Future<void> Function() onSaved;

  @override
  State<SiteEditor> createState() => _SiteEditorState();
}

class _SiteEditorState extends State<SiteEditor> {
  late Map<String, dynamic> s = widget.site;
  late Map<String, dynamic> c = asMap(s['content']);
  late final Map<String, Color> _colors = {for (final k in ['primary', 'dark', 'accent', 'background']) k: hexColor(s['theme'][k])};
  late String _hero = '${s['theme']['hero']}', _hf = '${s['theme']['heading_font']}', _bf = '${s['theme']['body_font']}';
  late final _t = {
    for (final k in ['hero_title', 'hero_subtitle', 'cta_label', 'about_title', 'about_text', 'services_title', 'hours', 'announcement', 'whatsapp', 'address'])
      k: TextEditingController(text: '${(s['overrides']['content'] as Map?)?[k] ?? (['hours', 'announcement', 'whatsapp', 'address'].contains(k) ? '' : c[k] ?? '')}'),
    'name': TextEditingController(text: '${s['name']}'),
    'seo_title': TextEditingController(text: '${s['seo']?['title'] ?? ''}'),
    'seo_description': TextEditingController(text: '${s['seo']?['description'] ?? ''}'),
    for (final k in const ['meta_pixel', 'ga4', 'gtm', 'tiktok_pixel', 'google_ads', 'gsc']) 'tk_$k': TextEditingController(text: '${(s['seo']?['tracking'] as Map?)?[k] ?? ''}'),
    for (final k in ['facebook', 'instagram', 'linkedin', 'tiktok', 'youtube']) 'so_$k': TextEditingController(text: '${(c['socials'] as Map?)?[k] ?? ''}'),
  };
  late String _cta = '${c['cta_target'] ?? 'contact'}';
  late final List<Map<String, String>> _services = _rows(c['services'], ['icon', 'title', 'text']);
  late final List<Map<String, String>> _stats = _rows(c['stats'], ['value', 'label']);
  late final List<Map<String, String>> _testi = _rows(c['testimonials'], ['name', 'role', 'text']);
  late final List<Map<String, String>> _faq = _rows(c['faq'], ['q', 'a']);
  late List<String> _sections = [for (final x in (s['sections'] as List)) '$x'];
  bool _busy = false;

  static List<Map<String, String>> _rows(dynamic v, List<String> keys) => [for (final r in (v as List? ?? const [])) {for (final k in keys) k: '${(r as Map)[k] ?? ''}'}];

  Map<String, String> get _sectionLabels => Map<String, String>.from(widget.templates['sections'] as Map);

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.put('sites/site', {
        'name': _t['name']!.text.trim(),
        'theme': {for (final e in _colors.entries) e.key: colorHex(e.value), 'hero': _hero, 'heading_font': _hf, 'body_font': _bf},
        'content': {
          for (final k in ['hero_title', 'hero_subtitle', 'cta_label', 'about_title', 'about_text', 'services_title', 'hours', 'announcement', 'whatsapp', 'address']) k: _t[k]!.text,
          'cta_target': _cta, 'services': _services, 'stats': _stats, 'testimonials': _testi, 'faq': _faq,
          'socials': {for (final k in ['facebook', 'instagram', 'linkedin', 'tiktok', 'youtube']) k: _t['so_$k']!.text},
        },
        'sections': _sections,
        'seo': {
          'title': _t['seo_title']!.text,
          'description': _t['seo_description']!.text,
          'tracking': {for (final k in const ['meta_pixel', 'ga4', 'gtm', 'tiktok_pixel', 'google_ads', 'gsc']) k: _t['tk_$k']!.text.trim()},
        },
      });
      s = asMap(r['data']);
      await widget.onSaved();
      if (mounted) toast(context, tr('Modifications enregistrées.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _upload(String slot) async {
    final picked = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: const ['png', 'jpg', 'jpeg', 'webp']);
    if (picked.isEmpty || !mounted) return;
    final f = picked.single;
    final bytes = await f.readAsBytes();
    if (!mounted) return;
    try {
      final r = await context.read<Session>().api.uploadForm('sites/site/images', {'slot': slot}, 'file', bytes, f.name);
      setState(() => s = asMap(r['data']));
      if (mounted) toast(context, tr('Image ajoutée.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _remove(String path) async {
    try {
      final r = await context.read<Session>().api.post('sites/site/images/remove', {'path': path});
      setState(() => s = asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Widget _tf(String k, String label, {int lines = 1, String? hint}) => Field(label: label, child: TextField(controller: _t[k], minLines: lines, maxLines: lines == 1 ? 1 : lines + 3, decoration: InputDecoration(hintText: trn(hint))));

  Widget _colorPick(String k, String label) {
    const swatches = ['#E4572E', '#D6336C', '#7C3AED', '#4F46E5', '#2563EB', '#0EA5E9', '#0891B2', '#10B981', '#16A34A', '#F59E0B', '#FFC531', '#111827', '#0B1B3A', '#3E2723', '#FFFFFF', '#F7F5F2'];
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(children: [
        SizedBox(width: 120, child: Text(tr(label), style: const TextStyle(fontWeight: FontWeight.w600))),
        PopupMenuButton<String>(
          tooltip: tr('Choisir'),
          onSelected: (v) => setState(() => _colors[k] = hexColor(v)),
          itemBuilder: (_) => [
            PopupMenuItem(
              enabled: false,
              child: SizedBox(
                width: 220,
                child: Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final sw in swatches)
                    InkWell(
                      onTap: () => Navigator.pop(context, sw),
                      child: Container(width: 30, height: 30, decoration: BoxDecoration(color: hexColor(sw), borderRadius: BorderRadius.circular(8), border: Border.all(color: YColors.line))),
                    ),
                ]),
              ),
            ),
          ],
          child: Container(width: 40, height: 32, decoration: BoxDecoration(color: _colors[k], borderRadius: BorderRadius.circular(8), border: Border.all(color: YColors.line))),
        ),
        const SizedBox(width: 10),
        SizedBox(
          width: 110,
          child: TextFormField(
            key: ValueKey(colorHex(_colors[k]!)),
            initialValue: colorHex(_colors[k]!),
            decoration: const InputDecoration(isDense: true),
            onFieldSubmitted: (v) => setState(() => _colors[k] = hexColor(v.startsWith('#') ? v : '#$v', _colors[k]!)),
          ),
        ),
      ]),
    );
  }

  Widget _list(String title, List<Map<String, String>> rows, List<(String, String)> fields, {int max = 8, bool icons = false}) => SectionCard(
        title: '$title (${rows.length})',
        trailing: rows.length >= max ? null : TextButton.icon(onPressed: () => setState(() => rows.add({for (final f in fields) f.$1: '', if (icons) 'icon': 'check'})), icon: const Icon(Icons.add), label: Text(tr('Ajouter'))),
        child: Column(children: [
          if (rows.isEmpty) Align(alignment: Alignment.centerLeft, child: Text(tr('Section masquée tant qu\'elle est vide.'), style: TextStyle(color: Theme.of(context).hintColor))),
          for (var i = 0; i < rows.length; i++)
            Padding(
              key: ObjectKey(rows[i]),
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (icons)
                  PopupMenuButton<String>(
                    tooltip: tr('Pictogramme'),
                    onSelected: (v) => setState(() => rows[i]['icon'] = v),
                    itemBuilder: (_) => [for (final ic in (widget.templates['icons'] as List)) PopupMenuItem(value: '$ic', child: Row(children: [Icon(sectorIcons[ic] ?? _extraIcons[ic] ?? Icons.check, size: 18), const SizedBox(width: 8), Text(tr('$ic'))]))],
                    child: Padding(padding: const EdgeInsets.all(8), child: Icon(sectorIcons[rows[i]['icon']] ?? _extraIcons[rows[i]['icon']] ?? Icons.check, color: _colors['primary'])),
                  ),
                for (final f in fields)
                  Expanded(
                    flex: f.$1 == 'text' || f.$1 == 'a' ? 3 : 2,
                    child: Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: TextFormField(
                        initialValue: rows[i][f.$1],
                        minLines: 1,
                        maxLines: f.$1 == 'text' || f.$1 == 'a' ? 4 : 1,
                        decoration: InputDecoration(isDense: true, labelText: f.$2),
                        onChanged: (v) => rows[i][f.$1] = v,
                      ),
                    ),
                  ),
                IconButton(onPressed: () => setState(() => rows.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
              ]),
            ),
        ]),
      );

  static const _extraIcons = {'sparkles': Icons.auto_awesome, 'shield': Icons.verified_user_outlined, 'award': Icons.emoji_events_outlined, 'clock': Icons.schedule, 'phone': Icons.phone_outlined,
    'map': Icons.place_outlined, 'mail': Icons.mail_outline, 'check': Icons.check, 'star': Icons.star_outline, 'globe': Icons.public, 'chat': Icons.chat_bubble_outline, 'gift': Icons.card_giftcard};

  @override
  Widget build(BuildContext context) {
    final images = asMap(s['images']);
    final gallery = [for (final g in (images['gallery'] as List? ?? const [])) asMap(g)];
    final wide = MediaQuery.of(context).size.width > 1150;
    const fonts = ['Poppins', 'Playfair Display', 'Space Grotesk', 'Inter', 'Lato', 'Montserrat', 'Raleway', 'Lora', 'Nunito', 'DM Serif Display', 'Merriweather', 'Open Sans', 'Roboto'];
    const bodyFonts = ['Poppins', 'Inter', 'Lato', 'Montserrat', 'Raleway', 'Lora', 'Nunito', 'Open Sans', 'Roboto'];
    Widget img(String slot, String label) {
      final url = images[slot];
      return SizedBox(
        width: 170,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(label), style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          InkWell(
            onTap: () => _upload(slot),
            borderRadius: BorderRadius.circular(12),
            child: Container(
              height: 100,
              decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), border: Border.all(color: YColors.line), color: YColors.blue.withValues(alpha: .04)),
              clipBehavior: Clip.antiAlias,
              child: url != null ? Image.network('$url', fit: BoxFit.cover, width: double.infinity, errorBuilder: (_, _, _) => const Icon(Icons.broken_image_outlined)) : const Center(child: Icon(Icons.add_photo_alternate_outlined, color: YColors.muted)),
            ),
          ),
          if (url != null) TextButton(onPressed: () => _remove(_pathOf('$url')), child: Text(tr('Retirer'))),
        ]),
      );
    }

    final design = Column(children: [
      SectionCard(
        title: tr('Apparence'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          TemplateThumb(theme: {...?(s['theme'] as Map?), for (final e in _colors.entries) e.key: colorHex(e.value), 'hero': _hero}),
          const SizedBox(height: 12),
          _colorPick('primary', 'Couleur principale'),
          _colorPick('dark', 'Couleur foncée'),
          _colorPick('accent', 'Accent'),
          _colorPick('background', 'Fond'),
          Field(
            label: tr('Bannière d\'accueil'),
            child: SegmentedButton<String>(
              segments: [ButtonSegment(value: 'gradient', label: Text(tr('Dégradé'))), ButtonSegment(value: 'centered', label: Text(tr('Centrée'))), ButtonSegment(value: 'split', label: Text(tr('Deux colonnes'))), ButtonSegment(value: 'image', label: Text(tr('Photo')))],
              selected: {_hero},
              onSelectionChanged: (v) => setState(() => _hero = v.first),
            ),
          ),
          Row(children: [
            Expanded(child: Field(label: tr('Police des titres'), child: DropdownButtonFormField<String>(initialValue: fonts.contains(_hf) ? _hf : fonts.first, isExpanded: true, items: [for (final f in fonts) DropdownMenuItem(value: f, child: Text(tr(f)))], onChanged: (v) => setState(() => _hf = v!)))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Police du texte'), child: DropdownButtonFormField<String>(initialValue: bodyFonts.contains(_bf) ? _bf : bodyFonts.first, isExpanded: true, items: [for (final f in bodyFonts) DropdownMenuItem(value: f, child: Text(tr(f)))], onChanged: (v) => setState(() => _bf = v!)))),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Photos'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(spacing: 14, runSpacing: 10, children: [img('hero', 'Bannière'), img('about', 'Présentation'), img('logo', 'Logo')]),
          const SizedBox(height: 12),
          Row(children: [
            Text(tr('Galerie (${gallery.length}/12)'), style: const TextStyle(fontWeight: FontWeight.w600)),
            const Spacer(),
            if (gallery.length < 12) TextButton.icon(onPressed: () => _upload('gallery'), icon: const Icon(Icons.add_photo_alternate_outlined), label: Text(tr('Ajouter'))),
          ]),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final g in gallery)
              Stack(children: [
                ClipRRect(borderRadius: BorderRadius.circular(10), child: Image.network('${g['url']}', width: 96, height: 96, fit: BoxFit.cover, errorBuilder: (_, _, _) => const SizedBox(width: 96, height: 96, child: Icon(Icons.broken_image_outlined)))),
                Positioned(right: 2, top: 2, child: IconButton.filledTonal(iconSize: 16, visualDensity: VisualDensity.compact, onPressed: () => _remove('${g['path']}'), icon: const Icon(Icons.close))),
              ]),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Sections et ordre'),
        child: ReorderableListView(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          buildDefaultDragHandles: false,
          onReorderItem: (a, b) => setState(() {
            final all = [..._sections, ..._sectionLabels.keys.where((k) => !_sections.contains(k))];
            final x = all.removeAt(a);
            all.insert(b, x);
            _sections = all.where((k) => _sections.contains(k)).toList();
          }),
          children: [
            for (final (i, k) in [..._sections, ..._sectionLabels.keys.where((k) => !_sections.contains(k))].indexed)
              ListTile(
                key: ValueKey(k),
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: ReorderableDragStartListener(index: i, child: const Icon(Icons.drag_indicator)),
                title: Text(tr(_sectionLabels[k] ?? k)),
                trailing: Switch(
                  value: _sections.contains(k),
                  onChanged: k == 'hero' ? null : (v) => setState(() => v ? _sections.add(k) : _sections.remove(k)),
                ),
              ),
          ],
        ),
      ),
    ]);
    final texts = Column(children: [
      SectionCard(
        title: tr('Accueil'),
        child: Column(children: [
          _tf('name', 'Nom affiché'),
          _tf('announcement', 'Bandeau d\'annonce (facultatif)', hint: tr('Ex. : Ouvert ce dimanche — livraison offerte dès 10 000 F')),
          _tf('hero_title', 'Titre principal'),
          _tf('hero_subtitle', 'Sous-titre', lines: 2),
          Row(children: [
            Expanded(child: _tf('cta_label', 'Bouton principal')),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Le bouton mène vers'),
                child: DropdownButtonFormField<String>(
                  initialValue: _cta,
                  isExpanded: true,
                  items: [
                    DropdownMenuItem(value: 'contact', child: Text(tr('Formulaire de contact'))),
                    DropdownMenuItem(value: 'whatsapp', child: Text(tr('WhatsApp'))),
                    DropdownMenuItem(value: 'phone', child: Text(tr('Appel'))),
                    DropdownMenuItem(value: 'booking', child: Text(tr('Prise de rendez-vous'))),
                    DropdownMenuItem(value: 'products', child: Text(tr('Produits'))),
                    DropdownMenuItem(value: 'events', child: Text(tr('Événements'))),
                  ],
                  onChanged: (v) => setState(() => _cta = v!),
                ),
              ),
            ),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(title: tr('Présentation'), child: Column(children: [_tf('about_title', 'Titre'), _tf('about_text', 'Texte', lines: 4)])),
      const SizedBox(height: 12),
      _list('Services', _services, [('title', 'Titre'), ('text', 'Description')], icons: true),
      const SizedBox(height: 12),
      _list('Chiffres clés', _stats, [('value', 'Valeur (ex. 15 ans)'), ('label', 'Libellé')], max: 4),
      const SizedBox(height: 12),
      _list('Témoignages de vos clients', _testi, [('name', 'Nom'), ('role', 'Qualité'), ('text', 'Témoignage')]),
      const SizedBox(height: 12),
      _list('Questions fréquentes', _faq, [('q', 'Question'), ('a', 'Réponse')], max: 12),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Coordonnées et réseaux'),
        child: Column(children: [
          _tf('address', 'Adresse affichée (sinon celle de la société)'),
          _tf('hours', 'Horaires', lines: 2, hint: tr('Lun–Ven : 8 h – 18 h')),
          _tf('whatsapp', 'Numéro WhatsApp (sinon le téléphone de la société)'),
          Wrap(spacing: 12, children: [for (final k in ['facebook', 'instagram', 'linkedin', 'tiktok', 'youtube']) SizedBox(width: 260, child: _tf('so_$k', k[0].toUpperCase() + k.substring(1)))]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(title: tr('Référencement (Google)'), child: Column(children: [_tf('seo_title', 'Titre de la page (70 caractères)'), _tf('seo_description', 'Description (170 caractères)', lines: 2)])),
      const SizedBox(height: 16),
      SectionCard(
        title: tr('Suivi des visiteurs et des ventes'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr('Les commandes et demandes de contact sont signalées comme conversions. Vide : réglages de « Publicité & acquisition ».'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
          const SizedBox(height: 8),
          _tf('tk_meta_pixel', 'Pixel Meta (Facebook / Instagram)', hint: '123456789012345'),
          _tf('tk_ga4', 'Google Analytics 4', hint: 'G-XXXXXXXXXX'),
          _tf('tk_gtm', 'Google Tag Manager', hint: 'GTM-XXXXXXX'),
          _tf('tk_google_ads', 'Google Ads', hint: 'AW-123456789'),
          _tf('tk_tiktok_pixel', 'Pixel TikTok'),
          _tf('tk_gsc', 'Search Console : code de vérification'),
        ]),
      ),
    ]);
    return Stack(children: [
      ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 90), children: [
        if (wide) Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: design), const SizedBox(width: 16), Expanded(child: texts)]) else ...[design, const SizedBox(height: 12), texts],
      ]),
      Positioned(
        right: 20,
        bottom: 20,
        child: Wrap(spacing: 10, children: [
          FloatingActionButton.extended(heroTag: 'pv', backgroundColor: Colors.white, foregroundColor: YColors.night, onPressed: () => openUrl('${s['preview_url']}'), icon: const Icon(Icons.visibility_outlined), label: Text(tr('Aperçu'))),
          FloatingActionButton.extended(heroTag: 'sv', onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer'))),
        ]),
      ),
    ]);
  }

  String _pathOf(String url) {
    final i = url.indexOf('/storage/');
    return i < 0 ? url : url.substring(i + 9);
  }
}

// ================================================================= Domaine

class _DomainTab extends StatefulWidget {
  const _DomainTab({required this.site, required this.platform, required this.onChanged});

  final Map<String, dynamic> site;
  final Map<String, dynamic> platform;
  final Future<void> Function() onChanged;

  @override
  State<_DomainTab> createState() => _DomainTabState();
}

class _DomainTabState extends State<_DomainTab> {
  late final _d = TextEditingController(text: '${widget.site['custom_domain'] ?? ''}');
  bool _busy = false;

  Future<void> _run(Future<dynamic> Function() f, String done) async {
    setState(() => _busy = true);
    try {
      await f();
      await widget.onChanged();
      if (mounted) toast(context, done);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final s = widget.site;
    final hint = Theme.of(context).hintColor;
    final st = '${s['domain_status'] ?? 'none'}';
    final dns = [for (final r in (s['dns'] as List? ?? const [])) asMap(r)];
    final color = switch (st) { 'active' => YColors.success, 'verified' => const Color(0xFF0FA3B1), 'pending' => YColors.warning, 'error' => YColors.danger, _ => YColors.muted };
    return ListView(padding: const EdgeInsets.all(16), children: [
      SectionCard(
        title: tr('Adresse de votre site'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Icon(Icons.link, color: YColors.blue),
            const SizedBox(width: 8),
            Expanded(child: SelectableText(tr('${s['url']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
            IconButton(tooltip: tr('Copier'), onPressed: () => Clipboard.setData(ClipboardData(text: '${s['url']}')), icon: const Icon(Icons.copy, size: 18)),
          ]),
          if (widget.platform['base_domain'] != null) Text(tr('Sous-domaine offert : ${s['slug']}.${widget.platform['base_domain']}'), style: TextStyle(color: hint)),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Votre propre nom de domaine'),
        trailing: s['custom_domain'] != null ? StatusChip(siteDomainLabels[st] ?? st, color: color) : null,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('Vous avez déjà un domaine (monentreprise.ci, .com…) ? Reliez-le : vos visiteurs arriveront directement sur ce site.'), style: TextStyle(color: hint)),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(child: TextField(controller: _d, decoration: InputDecoration(labelText: tr('Nom de domaine'), hintText: tr('monentreprise.ci')))),
            const SizedBox(width: 10),
            FilledButton(onPressed: _busy ? null : () => _run(() => api.put('sites/site/domain', {'domain': _d.text.trim()}), 'Domaine enregistré : créez les enregistrements ci-dessous.'), child: Text(tr('Relier'))),
            if (s['custom_domain'] != null)
              TextButton(onPressed: _busy ? null : () => _run(() => api.put('sites/site/domain', {'domain': null}), 'Domaine retiré.'), child: Text(tr('Retirer'))),
          ]),
          if (dns.isNotEmpty) ...[
            const SizedBox(height: 18),
            Text(tr('Chez votre registraire (zone DNS), créez :'), style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 8),
            Table(
              columnWidths: const {0: FixedColumnWidth(80), 1: FixedColumnWidth(110), 2: FlexColumnWidth(), 3: FixedColumnWidth(44)},
              defaultVerticalAlignment: TableCellVerticalAlignment.middle,
              children: [
                TableRow(decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .06)), children: [for (final h in ['Type', 'Nom', 'Valeur', '']) Padding(padding: const EdgeInsets.all(8), child: Text(tr(h), style: const TextStyle(fontWeight: FontWeight.w700)))]),
                for (final r in dns)
                  TableRow(children: [
                    Padding(padding: const EdgeInsets.all(8), child: StatusChip('${r['type']}', color: YColors.night)),
                    Padding(padding: const EdgeInsets.all(8), child: Text(tr('${r['host']}'), style: const TextStyle(fontFamily: 'monospace'))),
                    Padding(
                      padding: const EdgeInsets.all(8),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        SelectableText(tr('${r['value']}'), style: const TextStyle(fontFamily: 'monospace', fontWeight: FontWeight.w600)),
                        Text(tr('${r['purpose']}'), style: TextStyle(color: hint, fontSize: 12)),
                      ]),
                    ),
                    IconButton(tooltip: tr('Copier'), onPressed: () => Clipboard.setData(ClipboardData(text: '${r['value']}')), icon: const Icon(Icons.copy, size: 16)),
                  ]),
              ],
            ),
            const SizedBox(height: 12),
            if (s['domain_error'] != null) Text(tr('${s['domain_error']}'), style: TextStyle(color: st == 'active' ? YColors.success : YColors.warning)),
            if (s['domain_checked_at'] != null) Text(tr('Dernière vérification : ${dateTimeFr(s['domain_checked_at'])} · vérification automatique toutes les heures'), style: TextStyle(color: hint, fontSize: 12.5)),
            const SizedBox(height: 10),
            OutlinedButton.icon(onPressed: _busy ? null : () => _run(() => api.post('sites/site/domain/check'), 'Vérification effectuée.'), icon: const Icon(Icons.refresh), label: Text(tr('Vérifier maintenant'))),
          ],
        ]),
      ),
    ]);
  }
}

// ================================================================= Messages

class _Messages extends StatefulWidget {
  const _Messages({required this.reload});

  final Future<void> Function() reload;

  @override
  State<_Messages> createState() => _MessagesState();
}

class _MessagesState extends State<_Messages> {
  List<Map<String, dynamic>>? _rows;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('sites/messages');
      if (mounted) setState(() => _rows = [for (final m in (r['data'] as List)) asMap(m)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _open(Map<String, dynamic> m) async {
    final api = context.read<Session>().api;
    if (m['read_at'] == null) {
      api.post('sites/messages/${m['id']}/read').then((_) {
        _load();
        widget.reload();
      }).catchError((_) {});
    }
    final phone = '${m['phone'] ?? ''}'.replaceAll(RegExp(r'\D'), '');
    await showDialog(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('${m['subject'] ?? 'Message de ${m['name']}'}')),
        content: SizedBox(
          width: 480,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${m['name']} · ${dateTimeFr(m['created_at'])}'), style: TextStyle(color: Theme.of(c).hintColor)),
            if (m['email'] != null) SelectableText(tr('${m['email']}')),
            if (m['phone'] != null) SelectableText(tr('${m['phone']}')),
            const Divider(height: 20),
            SelectableText(tr('${m['message']}')),
            if (m['lead_id'] != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(tr('Opportunité créée dans le CRM.'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12.5))),
          ]),
        ),
        actions: [
          if (m['email'] != null) TextButton(onPressed: () => openUrl('mailto:${m['email']}?subject=${Uri.encodeComponent('Re: ${m['subject'] ?? 'votre message'}')}'), child: Text(tr('Répondre par e-mail'))),
          if (phone.length >= 8) TextButton(onPressed: () => openUrl('https://wa.me/${phone.length == 10 ? '225$phone' : phone}'), child: Text(tr('WhatsApp'))),
          FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    if (rows == null) return const LoadingSkeleton();
    if (rows.isEmpty) return EmptyState(icon: Icons.mail_outline, title: tr('Aucun message'), message: tr('Les messages envoyés depuis le formulaire de contact du site arrivent ici.'));
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.all(12),
        itemCount: rows.length,
        separatorBuilder: (_, _) => const Divider(height: 1),
        itemBuilder: (context, i) {
          final m = rows[i];
          final unread = m['read_at'] == null;
          return ListTile(
            onTap: () => _open(m),
            leading: CircleAvatar(backgroundColor: (unread ? YColors.blue : YColors.muted).withValues(alpha: .12), child: Icon(unread ? Icons.mark_email_unread_outlined : Icons.drafts_outlined, color: unread ? YColors.blue : YColors.muted)),
            title: Text(tr('${m['name']}${m['subject'] != null ? ' — ${m['subject']}' : ''}'), style: TextStyle(fontWeight: unread ? FontWeight.w800 : FontWeight.w500), overflow: TextOverflow.ellipsis),
            subtitle: Text(tr('${m['message']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
            trailing: Text(tr(dateTimeFr(m['created_at'])), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          );
        },
      ),
    );
  }
}
