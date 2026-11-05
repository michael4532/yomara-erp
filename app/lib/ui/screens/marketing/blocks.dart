import 'dart:math';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/local_db.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'mk_common.dart';
import '../../../i18n/i18n.dart';

// ======================================================================
// Catalogue des blocs et des sections prêtes
// ======================================================================

class BlockMeta {
  const BlockMeta(this.type, this.label, this.icon, this.group);

  final String type;
  final String label;
  final IconData icon;
  final String group;
}

const blockCatalog = [
  BlockMeta('heading', 'Titre', Icons.title, 'Texte'),
  BlockMeta('text', 'Texte', Icons.notes, 'Texte'),
  BlockMeta('quote', 'Citation', Icons.format_quote, 'Texte'),
  BlockMeta('footer', 'Mentions', Icons.short_text, 'Texte'),
  BlockMeta('image', 'Image', Icons.image_outlined, 'Médias'),
  BlockMeta('hero', 'Bannière', Icons.wallpaper, 'Médias'),
  BlockMeta('video', 'Vidéo', Icons.smart_display_outlined, 'Médias'),
  BlockMeta('button', 'Bouton', Icons.smart_button_outlined, 'Action'),
  BlockMeta('coupon', 'Code promo', Icons.confirmation_number_outlined, 'Action'),
  BlockMeta('social', 'Réseaux', Icons.share_outlined, 'Action'),
  BlockMeta('menu', 'Menu', Icons.menu, 'Action'),
  BlockMeta('columns', '2 colonnes', Icons.view_column_outlined, 'Mise en page'),
  BlockMeta('columns3', '3 colonnes', Icons.view_week_outlined, 'Mise en page'),
  BlockMeta('divider', 'Séparateur', Icons.horizontal_rule, 'Mise en page'),
  BlockMeta('spacer', 'Espace', Icons.height, 'Mise en page'),
  BlockMeta('product', 'Produit', Icons.sell_outlined, 'Vente'),
  BlockMeta('products', 'Vitrine', Icons.grid_view, 'Vente'),
  BlockMeta('rss', 'Articles RSS', Icons.rss_feed, 'Avancé'),
  BlockMeta('html', 'HTML', Icons.code, 'Avancé'),
];

final blockLabels = {for (final b in blockCatalog) b.type: b.label};
final blockIcons = {for (final b in blockCatalog) b.type: b.icon};

final _rand = Random();
String newBlockId() => '${DateTime.now().microsecondsSinceEpoch.toRadixString(36)}${_rand.nextInt(1 << 20).toRadixString(36)}';

/// Identifiant stable de chaque bloc (pour la sélection et les champs de saisie).
String blockId(Map<String, dynamic> b) => (b['_id'] ??= newBlockId()) as String;

Map<String, dynamic> withId(Map<String, dynamic> b) => {...b, '_id': newBlockId()};

Map<String, dynamic> defaultBlock(String type) => withId({
      'type': type,
      ...switch (type) {
        'heading' => {'text': 'Votre titre'},
        'text' => {'text': 'Votre texte. **Gras**, *italique*, [lien](https://exemple.ci).'},
        'button' => {'label': 'Découvrir', 'url': 'https://', 'align': 'center'},
        'image' => {'url': '', 'width': 100},
        'columns' => {'left': '**Colonne de gauche**\nVotre texte.', 'right': '**Colonne de droite**\nVotre texte.'},
        'columns3' => {
            'cols': [
              {'title': 'Atout 1', 'text': 'Une phrase.'},
              {'title': 'Atout 2', 'text': 'Une phrase.'},
              {'title': 'Atout 3', 'text': 'Une phrase.'},
            ],
          },
        'quote' => {'text': 'Un message à mettre en avant.'},
        'spacer' => {'height': 24},
        'hero' => {'title': 'Votre grand titre', 'text': 'Une phrase d\'accroche.', 'button_label': 'J\'en profite', 'url': 'https://', 'bg_color': '#1F6FEB'},
        'product' => {'name': 'Nom du produit', 'price': '10 000 F', 'button_label': 'Commander', 'url': 'https://'},
        'products' => {
            'title': 'Nos produits',
            'button_label': 'Voir',
            'items': [
              {'name': 'Produit 1', 'price': '5 000 F'},
              {'name': 'Produit 2', 'price': '7 500 F'},
              {'name': 'Produit 3', 'price': '12 000 F'},
            ],
          },
        'coupon' => {'text': 'Votre code personnel', 'code': 'PROMO10', 'expires': 'Valable jusqu\'au …'},
        'video' => {'url': 'https://www.youtube.com/watch?v=', 'caption': 'Regarder la vidéo'},
        'menu' => {'links': 'Accueil|https://\nBoutique|https://\nContact|https://'},
        'footer' => {'text': '{{entreprise}} · Abidjan, Côte d\'Ivoire'},
        'social' => {'website': 'https://', 'facebook': 'https://facebook.com/', 'instagram': '', 'linkedin': '', 'whatsapp': ''},
        'rss' => {'url': 'https://', 'count': 3, 'link_label': 'Lire la suite'},
        'html' => {'html': '<table width="100%"><tr><td style="padding:16px;background:#FFF7DB;border-radius:8px;">Votre HTML</td></tr></table>'},
        _ => <String, dynamic>{},
      },
    });

class SectionPreset {
  const SectionPreset(this.name, this.icon, this.blocks);

  final String name;
  final IconData icon;
  final List<Map<String, dynamic>> blocks;
}

const sectionPresets = [
  SectionPreset('Bannière + bouton', Icons.wallpaper, [
    {'type': 'hero', 'title': 'Offre de la semaine', 'text': 'Une accroche courte qui donne envie.', 'button_label': 'J\'en profite', 'url': 'https://', 'bg_color': '#0B1B3A'},
  ]),
  SectionPreset('Article image + texte', Icons.article_outlined, [
    {'type': 'columns', 'left_image': '', 'left': '', 'right': '**Titre de l\'article**\nQuelques lignes pour présenter le sujet.\n\n[Lire la suite](https://)'},
  ]),
  SectionPreset('3 avantages', Icons.view_week_outlined, [
    {
      'type': 'columns3',
      'cols': [
        {'title': 'Livraison rapide', 'text': 'Partout à Abidjan en 24 h'},
        {'title': 'Paiement facile', 'text': 'Mobile Money, carte ou espèces'},
        {'title': 'Service client', 'text': '7 j/7 sur WhatsApp'},
      ],
    },
  ]),
  SectionPreset('Offre avec code promo', Icons.local_offer_outlined, [
    {'type': 'heading', 'text': 'Un cadeau pour vous, {{prenom}}', 'align': 'center'},
    {'type': 'coupon', 'text': '-15 % sur toute la boutique', 'code': 'MERCI15', 'expires': 'Valable 7 jours'},
    {'type': 'button', 'label': 'J\'en profite', 'url': 'https://', 'align': 'center'},
  ]),
  SectionPreset('Vitrine 3 produits', Icons.grid_view, [
    {
      'type': 'products',
      'title': 'Nos coups de cœur',
      'button_label': 'Voir',
      'items': [
        {'name': 'Produit 1', 'price': '5 000 F', 'url': 'https://'},
        {'name': 'Produit 2', 'price': '7 500 F', 'url': 'https://'},
        {'name': 'Produit 3', 'price': '12 000 F', 'url': 'https://'},
      ],
    },
  ]),
  SectionPreset('Témoignage client', Icons.format_quote, [
    {'type': 'quote', 'text': 'Service impeccable, livraison rapide. Je recommande !', 'author': 'Awa, cliente depuis 2024'},
  ]),
  SectionPreset('Invitation (date, lieu)', Icons.event_outlined, [
    {
      'type': 'columns3',
      'cols': [
        {'title': 'Quand', 'text': 'Samedi, 10 h'},
        {'title': 'Où', 'text': 'Nos locaux'},
        {'title': 'Prix', 'text': 'Gratuit'},
      ],
    },
    {'type': 'button', 'label': 'Je m\'inscris', 'url': 'https://', 'align': 'center'},
  ]),
  SectionPreset('Vidéo', Icons.smart_display_outlined, [
    {'type': 'heading', 'text': 'En vidéo', 'size': 'h2', 'align': 'center'},
    {'type': 'video', 'url': 'https://www.youtube.com/watch?v=', 'caption': 'Regarder la vidéo'},
  ]),
  SectionPreset('Signature', Icons.draw_outlined, [
    {'type': 'text', 'text': 'Bien cordialement,\n**L\'équipe {{entreprise}}**'},
    {'type': 'social', 'website': 'https://', 'facebook': 'https://facebook.com/', 'whatsapp': '2250700000000'},
  ]),
  SectionPreset('Pied de page', Icons.vertical_align_bottom, [
    {'type': 'divider'},
    {'type': 'menu', 'links': 'Site web|https://\nContact|https://'},
    {'type': 'footer', 'text': '{{entreprise}} · Adresse · Téléphone\nVous recevez cet e-mail car vous êtes client ou inscrit à notre lettre d\'information.'},
  ]),
  SectionPreset('Derniers articles (RSS)', Icons.rss_feed, [
    {'type': 'heading', 'text': 'À lire', 'size': 'h2'},
    {'type': 'rss', 'url': 'https://', 'count': 3, 'link_label': 'Lire la suite'},
  ]),
];

// ======================================================================
// Rendu d'un bloc (aperçu fidèle au gabarit e-mail du serveur)
// ======================================================================

TextSpan richText(String s, Color link, String company) {
  s = fillVars(s, company);
  final spans = <InlineSpan>[];
  final re = RegExp(r'\*\*(.+?)\*\*|\*(.+?)\*|\[([^\]]+)\]\(([^)\s]+)\)', dotAll: true);
  var pos = 0;
  for (final m in re.allMatches(s)) {
    if (m.start > pos) spans.add(TextSpan(text: s.substring(pos, m.start)));
    if (m[1] != null) {
      spans.add(TextSpan(text: m[1], style: const TextStyle(fontWeight: FontWeight.bold)));
    } else if (m[2] != null) {
      spans.add(TextSpan(text: m[2], style: const TextStyle(fontStyle: FontStyle.italic)));
    } else {
      spans.add(TextSpan(text: m[3], style: TextStyle(color: link, decoration: TextDecoration.underline)));
    }
    pos = m.end;
  }
  if (pos < s.length) spans.add(TextSpan(text: s.substring(pos)));
  return TextSpan(children: spans);
}

String? youtubeThumb(String? url) {
  final m = RegExp(r'(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([A-Za-z0-9_-]{6,})').firstMatch(url ?? '');
  return m == null ? null : 'https://img.youtube.com/vi/${m[1]}/hqdefault.jpg';
}

String stripTags(String h) =>
    h.replaceAll(RegExp(r'<style[^>]*>.*?</style>', dotAll: true), '').replaceAll(RegExp(r'<[^>]+>'), ' ').replaceAll(RegExp(r'\s+'), ' ').trim();

class EmailBlockView extends StatelessWidget {
  const EmailBlockView({super.key, required this.block, required this.style, required this.company, this.narrow = false});

  final Map<String, dynamic> block;
  final Map<String, dynamic> style;
  final String company;
  final bool narrow;

  Color get primary => hexColor(style['primary'], YColors.blue);
  Color get text => hexColor(style['text'], YColors.night);

  TextAlign _align([String def = 'left']) => switch (block['align'] ?? def) {
        'center' => TextAlign.center,
        'right' => TextAlign.right,
        _ => TextAlign.left,
      };

  Alignment _alignment([String def = 'left']) => switch (block['align'] ?? def) {
        'center' => Alignment.center,
        'right' => Alignment.centerRight,
        _ => Alignment.centerLeft,
      };

  static Widget img(String? url, {double height = 120, BoxFit fit = BoxFit.cover, double radius = 8}) => url == null || url.isEmpty || !url.startsWith('http')
      ? Container(
          height: height,
          decoration: BoxDecoration(color: const Color(0xFFE9EEF6), borderRadius: BorderRadius.circular(radius)),
          alignment: Alignment.center,
          child: const Icon(Icons.image_outlined, color: YColors.muted, size: 36),
        )
      : ClipRRect(
          borderRadius: BorderRadius.circular(radius),
          child: Image.network(url, fit: fit, width: double.infinity, errorBuilder: (_, _, _) => Container(height: height, color: const Color(0xFFE9EEF6), alignment: Alignment.center, child: const Icon(Icons.broken_image_outlined))),
        );

  Widget _btn(String label, {Color? color}) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
        decoration: BoxDecoration(color: color ?? primary, borderRadius: BorderRadius.circular(8)),
        child: Text(tr(fillVars(label, company)), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15)),
      );

  Widget _price(Map item) => Text.rich(TextSpan(children: [
        if ('${item['old_price'] ?? ''}'.isNotEmpty)
          TextSpan(text: '${item['old_price']}  ', style: const TextStyle(decoration: TextDecoration.lineThrough, color: YColors.muted, fontSize: 13)),
        if ('${item['price'] ?? ''}'.isNotEmpty) TextSpan(text: '${item['price']}', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 17, color: primary)),
      ]));

  Widget _cols(List<Widget> children, {double gap = 12}) => narrow
      ? Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [for (final c in children) Padding(padding: EdgeInsets.only(bottom: gap), child: c)])
      : Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          for (var i = 0; i < children.length; i++) ...[if (i > 0) SizedBox(width: gap), Expanded(child: children[i])],
        ]);

  @override
  Widget build(BuildContext context) {
    final b = block;
    final pad = EdgeInsets.symmetric(horizontal: narrow ? 18 : 32, vertical: 8);
    switch (b['type']) {
      case 'heading':
        return Padding(
          padding: pad,
          child: Text(tr(fillVars('${b['text'] ?? ''}', company)), textAlign: _align(), style: TextStyle(fontSize: b['size'] == 'h2' ? 20 : 26, fontWeight: FontWeight.bold, height: 1.3, color: text)),
        );
      case 'text':
        return Padding(padding: pad, child: Text.rich(richText('${b['text'] ?? ''}', primary, company), textAlign: _align()));
      case 'footer':
        return Padding(padding: pad.copyWith(top: 14, bottom: 14), child: Text.rich(richText('${b['text'] ?? ''}', YColors.muted, company), textAlign: _align(), style: const TextStyle(fontSize: 12, color: YColors.muted)));
      case 'quote':
        return Padding(
          padding: pad,
          child: Container(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            decoration: BoxDecoration(border: Border(left: BorderSide(color: primary, width: 4))),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text.rich(richText('${b['text'] ?? ''}', primary, company), style: const TextStyle(fontStyle: FontStyle.italic)),
              if ('${b['author'] ?? ''}'.isNotEmpty) Text(tr('— ${b['author']}'), style: const TextStyle(fontWeight: FontWeight.bold)),
            ]),
          ),
        );
      case 'image':
        return Padding(
          padding: pad,
          child: LayoutBuilder(builder: (context, c) => Center(child: SizedBox(width: c.maxWidth * (((b['width'] as num?) ?? 100) / 100), child: img(b['url'] as String?, height: 160)))),
        );
      case 'hero':
        final fg = hexColor(b['text_color'] as String?, Colors.white);
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if ('${b['image'] ?? ''}'.isNotEmpty) img(b['image'] as String?, height: 180, radius: 0),
          Container(
            color: hexColor(b['bg_color'] as String?, primary),
            padding: EdgeInsets.all(narrow ? 22 : 32),
            child: Column(crossAxisAlignment: switch (b['align'] ?? 'center') { 'left' => CrossAxisAlignment.start, 'right' => CrossAxisAlignment.end, _ => CrossAxisAlignment.center }, children: [
              if ('${b['title'] ?? ''}'.isNotEmpty)
                Text(tr(fillVars('${b['title']}', company)), textAlign: _align('center'), style: TextStyle(fontSize: narrow ? 24 : 28, fontWeight: FontWeight.bold, color: fg, height: 1.25)),
              if ('${b['text'] ?? ''}'.isNotEmpty)
                Padding(padding: const EdgeInsets.only(top: 10), child: Text.rich(richText('${b['text']}', fg, company), textAlign: _align('center'), style: TextStyle(color: fg, fontSize: 16))),
              if ('${b['button_label'] ?? ''}'.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 18),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                    decoration: BoxDecoration(color: hexColor(b['button_color'] as String?, YColors.yellow), borderRadius: BorderRadius.circular(8)),
                    child: Text(tr(fillVars('${b['button_label']}', company)), style: TextStyle(fontWeight: FontWeight.bold, color: b['button_color'] == null ? YColors.night : Colors.white)),
                  ),
                ),
            ]),
          ),
        ]);
      case 'button':
        return Padding(padding: pad.copyWith(top: 12, bottom: 12), child: Align(alignment: _alignment('center'), child: _btn('${b['label'] ?? 'En savoir plus'}', color: hexColor(b['color'] as String?, primary))));
      case 'coupon':
        return Padding(
          padding: pad.copyWith(top: 12, bottom: 12),
          child: CustomPaint(
            painter: _DashedBorder(primary),
            child: Padding(
              padding: const EdgeInsets.all(18),
              child: Column(children: [
                if ('${b['text'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text.rich(richText('${b['text']}', primary, company), textAlign: TextAlign.center)),
                Text(tr('${b['code'] ?? 'CODE'}'), style: TextStyle(fontSize: 26, fontWeight: FontWeight.bold, letterSpacing: 3, color: primary)),
                if ('${b['expires'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('${b['expires']}'), style: const TextStyle(fontSize: 12, color: YColors.muted))),
              ]),
            ),
          ),
        );
      case 'video':
        final thumb = '${b['thumbnail'] ?? ''}'.isNotEmpty ? b['thumbnail'] as String : youtubeThumb(b['url'] as String?);
        return Padding(
          padding: pad,
          child: Column(children: [
            Stack(alignment: Alignment.center, children: [
              img(thumb, height: 200),
              Container(width: 64, height: 64, decoration: const BoxDecoration(color: Colors.black54, shape: BoxShape.circle), child: const Icon(Icons.play_arrow, color: Colors.white, size: 40)),
            ]),
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(Icons.play_arrow, color: primary, size: 18),
                Text(tr('${b['caption'] ?? 'Regarder la vidéo'}'), style: TextStyle(color: primary, fontWeight: FontWeight.bold)),
              ]),
            ),
          ]),
        );
      case 'divider':
        return Padding(padding: pad.copyWith(top: 12, bottom: 12), child: const Divider(color: YColors.line, height: 1));
      case 'spacer':
        return SizedBox(height: ((b['height'] as num?) ?? 24).toDouble());
      case 'columns':
        return Padding(
          padding: EdgeInsets.symmetric(horizontal: narrow ? 14 : 26, vertical: 8),
          child: _cols([
            for (final side in ['left', 'right'])
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if ('${b['${side}_image'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 8), child: img(b['${side}_image'] as String?)),
                Text.rich(richText('${b[side] ?? ''}', primary, company), style: const TextStyle(fontSize: 14)),
              ]),
          ]),
        );
      case 'columns3':
        final cols = mkList(b['cols']);
        while (cols.length < 3) {
          cols.add({});
        }
        return Padding(
          padding: EdgeInsets.symmetric(horizontal: narrow ? 14 : 26, vertical: 8),
          child: _cols([
            for (final col in cols.take(3))
              Column(children: [
                if ('${col['image'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 8), child: img(col['image'] as String?, height: 90)),
                if ('${col['title'] ?? ''}'.isNotEmpty) Text(tr('${col['title']}'), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.bold)),
                Text.rich(richText('${col['text'] ?? ''}', primary, company), textAlign: TextAlign.center, style: const TextStyle(fontSize: 14)),
              ]),
          ], gap: 10),
        );
      case 'product':
        return Padding(
          padding: EdgeInsets.symmetric(horizontal: narrow ? 14 : 26, vertical: 10),
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(10)),
            child: _cols([
              img(b['image'] as String?, height: 150),
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${b['name'] ?? ''}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                if ('${b['description'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text.rich(richText('${b['description']}', primary, company), style: const TextStyle(fontSize: 14))),
                Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: _price(b)),
                _btn('${b['button_label'] ?? 'Commander'}'),
              ]),
            ]),
          ),
        );
      case 'products':
        final items = mkList(b['items']).take(6).toList();
        final per = min(3, max(1, items.length));
        return Padding(
          padding: EdgeInsets.symmetric(horizontal: narrow ? 14 : 24, vertical: 8),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if ('${b['title'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.fromLTRB(8, 4, 8, 8), child: Text(tr('${b['title']}'), style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: text))),
            for (var r = 0; r < items.length; r += per)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: _cols([
                  for (final it in items.skip(r).take(per))
                    Column(children: [
                      img(it['image'] as String?, height: 110),
                      const SizedBox(height: 6),
                      Text(tr('${it['name'] ?? ''}'), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.bold)),
                      _price(it),
                      if ('${it['url'] ?? ''}'.isNotEmpty) Text(tr('${b['button_label'] ?? 'Voir'}'), style: TextStyle(color: primary, fontWeight: FontWeight.bold, decoration: TextDecoration.underline)),
                    ]),
                  for (var k = items.skip(r).take(per).length; k < per; k++) const SizedBox(),
                ], gap: 10),
              ),
          ]),
        );
      case 'menu':
        final links = '${b['links'] ?? ''}'.split('\n').map((l) => l.split('|').first.trim()).where((l) => l.isNotEmpty).toList();
        return Padding(
          padding: pad.copyWith(top: 10, bottom: 10),
          child: Text(tr(links.join('   |   ')), textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold, color: text, fontSize: 14)),
        );
      case 'social':
        final names = [
          if ('${b['website'] ?? ''}'.isNotEmpty) 'Site web',
          if ('${b['facebook'] ?? ''}'.isNotEmpty) 'Facebook',
          if ('${b['instagram'] ?? ''}'.isNotEmpty) 'Instagram',
          if ('${b['linkedin'] ?? ''}'.isNotEmpty) 'LinkedIn',
          if ('${b['whatsapp'] ?? ''}'.isNotEmpty) 'WhatsApp',
        ];
        return Padding(
          padding: pad.copyWith(top: 12, bottom: 12),
          child: Text(tr(names.join('  ·  ')), textAlign: TextAlign.center, style: TextStyle(color: primary, decoration: TextDecoration.underline)),
        );
      case 'rss':
        return Padding(padding: pad, child: RssPreview(block: b, primary: primary, text: text, narrow: narrow));
      case 'html':
        return Padding(
          padding: pad,
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: const Color(0xFFF7F9FC), borderRadius: BorderRadius.circular(8), border: Border.all(color: YColors.line)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [Icon(Icons.code, size: 16, color: YColors.blue), SizedBox(width: 6), Text(tr('Section HTML'), style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13))]),
              const SizedBox(height: 4),
              Text(tr(stripTags('${b['html'] ?? ''}')), maxLines: 4, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13)),
            ]),
          ),
        );
    }
    return const SizedBox.shrink();
  }
}

class _DashedBorder extends CustomPainter {
  _DashedBorder(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()
      ..color = color
      ..strokeWidth = 2
      ..style = PaintingStyle.stroke;
    final path = Path()..addRRect(RRect.fromRectAndRadius(Offset.zero & size, const Radius.circular(10)));
    for (final m in path.computeMetrics()) {
      for (double d = 0; d < m.length; d += 12) {
        canvas.drawPath(m.extractPath(d, d + 7), p);
      }
    }
  }

  @override
  bool shouldRepaint(covariant _DashedBorder old) => old.color != color;
}

/// Aperçu d'un bloc RSS : interroge le serveur (qui lit le flux) avec un cache.
class RssPreview extends StatefulWidget {
  const RssPreview({super.key, required this.block, required this.primary, required this.text, required this.narrow});

  final Map<String, dynamic> block;
  final Color primary;
  final Color text;
  final bool narrow;

  static final Map<String, List<Map<String, dynamic>>> cache = {};

  @override
  State<RssPreview> createState() => _RssPreviewState();
}

class _RssPreviewState extends State<RssPreview> {
  String? _loaded;

  String get _url => '${widget.block['url'] ?? ''}';

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _fetch();
  }

  @override
  void didUpdateWidget(covariant RssPreview old) {
    super.didUpdateWidget(old);
    _fetch();
  }

  void _fetch() {
    final url = _url;
    if (_loaded == url || !RegExp(r'^https?://[^/]+\.[a-z]{2,}').hasMatch(url) || RssPreview.cache.containsKey(url)) return;
    _loaded = url;
    context.read<Session>().api.get('marketing/rss', query: {'url': url, 'count': '10'}).then((r) {
      RssPreview.cache[url] = mkList(mkMap(r)['data']);
      if (mounted) setState(() {});
    }).catchError((_) {
      RssPreview.cache[url] = [];
    });
  }

  @override
  Widget build(BuildContext context) {
    final b = widget.block;
    final count = ((b['count'] as num?) ?? 3).toInt();
    final items = (RssPreview.cache[_url] ?? []).take(count).toList();
    if (items.isEmpty) {
      return Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: const Color(0xFFF7F9FC), borderRadius: BorderRadius.circular(8), border: Border.all(color: YColors.line)),
        child: Row(children: [
          Icon(Icons.rss_feed, color: widget.primary),
          const SizedBox(width: 8),
          Expanded(child: Text(tr('Les $count derniers articles du flux ${_url.length > 8 ? _url : '(adresse à renseigner)'} seront insérés à l\'envoi.'), style: const TextStyle(fontSize: 13))),
        ]),
      );
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if ('${b['title'] ?? ''}'.isNotEmpty) Text(tr('${b['title']}'), style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: widget.text)),
      for (final it in items)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (b['show_image'] != false && it['image'] != null) SizedBox(width: widget.narrow ? 90 : 160, child: EmailBlockView.img(it['image'] as String?, height: widget.narrow ? 70 : 100)),
            if (b['show_image'] != false && it['image'] != null) const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${it['title']}'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                Text(tr('${it['text']}'), maxLines: 3, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 14)),
                Text(tr('${b['link_label'] ?? 'Lire la suite'}'), style: TextStyle(color: widget.primary, fontWeight: FontWeight.bold)),
              ]),
            ),
          ]),
        ),
    ]);
  }
}

// ======================================================================
// Réglages d'un bloc
// ======================================================================

typedef UploadImage = Future<void> Function(void Function(String url) done);

class BlockSettings extends StatelessWidget {
  const BlockSettings({super.key, required this.block, required this.onChanged, required this.onUpload});

  final Map<String, dynamic> block;
  final void Function(Map<String, dynamic>) onChanged;
  final UploadImage onUpload;

  String get type => '${block['type']}';
  String get id => blockId(block);

  static final Map<String, int> _uploadRev = {};
  static final Map<String, int> _blockRev = {};

  /// Force l'affichage des nouvelles valeurs (après un choix au catalogue, une annulation…).
  static void refresh(String blockId) => _blockRev[blockId] = (_blockRev[blockId] ?? 0) + 1;

  void _set(String k, dynamic v) => onChanged({...block, k: v});

  Widget _f(String key, String label, {int lines = 1, String? hint, bool mono = false, Map<String, dynamic>? target, void Function(String)? onText, String? keyName}) {
    final src = target ?? block;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextFormField(
        key: ValueKey('$id-${_blockRev[id] ?? 0}-${keyName ?? key}'),
        initialValue: '${src[key] ?? ''}',
        maxLines: lines,
        minLines: lines > 1 ? 2 : 1,
        style: mono ? const TextStyle(fontFamily: 'monospace', fontSize: 12) : null,
        decoration: InputDecoration(labelText: trn(label), hintText: trn(hint), isDense: true),
        onChanged: onText ?? (v) => _set(key, v),
      ),
    );
  }

  Widget _imageField(BuildContext context, String key, String label, {Map<String, dynamic>? target, void Function(String)? onUrl, String? keyName}) {
    final set = onUrl ?? (String v) => _set(key, v);
    final k = '$id-${keyName ?? key}';
    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Expanded(child: _f(key, label, hint: tr('https://…'), target: target, onText: set, keyName: '${keyName ?? key}-${_uploadRev[k] ?? 0}')),
      IconButton(
        tooltip: tr('Importer une image'),
        onPressed: () => onUpload((u) {
          _uploadRev[k] = (_uploadRev[k] ?? 0) + 1; // recrée le champ pour afficher la nouvelle adresse
          set(u);
        }),
        icon: const Icon(Icons.upload),
      ),
    ]);
  }

  Widget _align(String def) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: SegmentedButton<String>(
          showSelectedIcon: false,
          segments: const [
            ButtonSegment(value: 'left', icon: Icon(Icons.format_align_left)),
            ButtonSegment(value: 'center', icon: Icon(Icons.format_align_center)),
            ButtonSegment(value: 'right', icon: Icon(Icons.format_align_right)),
          ],
          selected: {'${block['align'] ?? def}'},
          onSelectionChanged: (s) => _set('align', s.first),
        ),
      );

  Widget _colors(String key, List<String> colors, {String label = 'Couleur'}) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(children: [
          SizedBox(width: 90, child: Text(tr(label), style: const TextStyle(fontSize: 13))),
          Expanded(
            child: Wrap(spacing: 6, runSpacing: 6, children: [
              for (final c in colors)
                InkWell(
                  onTap: () => _set(key, c),
                  child: Container(
                    width: 24,
                    height: 24,
                    decoration: BoxDecoration(color: hexColor(c, Colors.blue), shape: BoxShape.circle, border: Border.all(color: block[key] == c ? YColors.night : YColors.line, width: block[key] == c ? 2.5 : 1)),
                  ),
                ),
            ]),
          ),
        ]),
      );

  static const palette = ['#1F6FEB', '#0B1B3A', '#12A150', '#E8A317', '#D93025', '#7B3FE4', '#E4407B', '#0E7C86', '#25D366', '#FFFFFF'];

  /// Choix d'un produit du catalogue de l'ERP (données locales, fonctionne hors ligne).
  Future<Map<String, dynamic>?> _pickProduct(BuildContext context) async {
    final session = context.read<Session>();
    final products = LocalDb.all('products').where((p) => p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    if (products.isEmpty) {
      toast(context, tr('Aucun produit dans le catalogue.'), error: true);
      return null;
    }
    var q = '';
    final p = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Produit du catalogue')),
          content: SizedBox(
            width: 420,
            height: 420,
            child: Column(children: [
              SearchField(onChanged: (v) => set(() => q = v.toLowerCase())),
              const SizedBox(height: 8),
              Expanded(
                child: ListView(children: [
                  for (final p in products.where((p) => '${p['name']} ${p['sku'] ?? ''}'.toLowerCase().contains(q)))
                    ListTile(dense: true, title: Text(tr('${p['name']}')), trailing: Text(tr(money(num.tryParse('${p['sale_price']}'), session.currency))), onTap: () => Navigator.pop(c, p)),
                ]),
              ),
            ]),
          ),
        ),
      ),
    );
    if (p == null) return null;
    final image = '${p['image'] ?? ''}';
    return {
      'name': p['name'],
      'price': money(num.tryParse('${p['sale_price']}'), session.currency),
      'description': p['description'],
      if (image.isNotEmpty) 'image': image.startsWith('http') ? image : '${session.serverUrl}/${image.replaceFirst(RegExp('^/'), '')}',
    };
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final children = switch (type) {
      'heading' => [
          _f('text', 'Titre'),
          _align('left'),
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'h1', label: Text(tr('Grand'))), ButtonSegment(value: 'h2', label: Text(tr('Moyen')))],
            selected: {'${block['size'] ?? 'h1'}'},
            onSelectionChanged: (s) => _set('size', s.first),
          ),
        ],
      'text' => [_f('text', 'Texte', lines: 8, hint: tr('**gras**, *italique*, [lien](https://…)')), _align('left')],
      'footer' => [_f('text', 'Texte', lines: 4), _align('center')],
      'quote' => [_f('text', 'Texte', lines: 4), _f('author', 'Auteur (facultatif)')],
      'image' => [
          _imageField(context, 'url', 'Adresse de l\'image'),
          _f('alt', 'Texte alternatif'),
          _f('link', 'Lien au clic (facultatif)'),
          Row(children: [
            Text(tr('Largeur')),
            Expanded(
              child: Slider(
                value: ((block['width'] as num?) ?? 100).toDouble().clamp(20, 100),
                min: 20,
                max: 100,
                divisions: 8,
                label: '${block['width'] ?? 100} %',
                onChanged: (v) => _set('width', v.round()),
              ),
            ),
          ]),
        ],
      'hero' => [
          _imageField(context, 'image', 'Image de la bannière (facultatif)'),
          _f('title', 'Titre'),
          _f('text', 'Texte', lines: 3),
          _f('button_label', 'Texte du bouton (vide = sans bouton)'),
          _f('url', 'Lien du bouton'),
          _colors('bg_color', palette, label: tr('Fond')),
          _colors('text_color', const ['#FFFFFF', '#0B1B3A'], label: tr('Texte')),
          _colors('button_color', palette, label: tr('Bouton')),
          _align('center'),
        ],
      'button' => [_f('label', 'Texte du bouton'), _f('url', 'Lien', hint: tr('https://… , mailto:… ou tel:…')), _align('center'), _colors('color', palette)],
      'coupon' => [_f('text', 'Texte au-dessus'), _f('code', 'Code'), _f('expires', 'Validité (facultatif)')],
      'video' => [
          _f('url', 'Lien de la vidéo (YouTube, Facebook…)'),
          _f('caption', 'Légende'),
          _imageField(context, 'thumbnail', 'Image (automatique pour YouTube)'),
        ],
      'menu' => [_f('links', 'Liens : un par ligne « Libellé|https://… »', lines: 5)],
      'social' => [_f('website', 'Site web'), _f('facebook', 'Facebook'), _f('instagram', 'Instagram'), _f('linkedin', 'LinkedIn'), _f('whatsapp', 'WhatsApp (numéro)')],
      'columns' => [
          _imageField(context, 'left_image', 'Image gauche'),
          _f('left', 'Texte gauche', lines: 5),
          _imageField(context, 'right_image', 'Image droite'),
          _f('right', 'Texte droite', lines: 5),
        ],
      'columns3' => [
          for (var i = 0; i < 3; i++) ..._subItem(context, 'cols', i, 'Colonne ${i + 1}', const ['image', 'title', 'text']),
        ],
      'product' => [
          OutlinedButton.icon(
            onPressed: () async {
              final p = await _pickProduct(context);
              if (p != null) {
                refresh(id);
                onChanged({...block, ...p});
              }
            },
            icon: const Icon(Icons.inventory_2_outlined),
            label: Text(tr('Choisir dans le catalogue')),
          ),
          const SizedBox(height: 10),
          _imageField(context, 'image', 'Image'),
          _f('name', 'Nom'),
          _f('description', 'Description', lines: 3),
          Row(children: [Expanded(child: _f('price', 'Prix')), const SizedBox(width: 8), Expanded(child: _f('old_price', 'Ancien prix'))]),
          _f('button_label', 'Bouton'),
          _f('url', 'Lien'),
        ],
      'products' => [
          _f('title', 'Titre de la vitrine'),
          _f('button_label', 'Texte des liens'),
          for (var i = 0; i < mkList(block['items']).length; i++) ..._subItem(context, 'items', i, 'Produit ${i + 1}', const ['image', 'name', 'price', 'old_price', 'url'], removable: true),
          Wrap(spacing: 8, children: [
            if (mkList(block['items']).length < 6)
              OutlinedButton.icon(
                onPressed: () async {
                  final p = await _pickProduct(context);
                  if (p != null) _set('items', [...mkList(block['items']), {...p, 'url': 'https://'}..remove('description')]);
                },
                icon: const Icon(Icons.inventory_2_outlined),
                label: Text(tr('Depuis le catalogue')),
              ),
            if (mkList(block['items']).length < 6)
              TextButton.icon(onPressed: () => _set('items', [...mkList(block['items']), {'name': 'Produit', 'price': ''}]), icon: const Icon(Icons.add), label: Text(tr('Produit vide'))),
          ]),
        ],
      'rss' => [
          _f('url', 'Adresse du flux RSS (ex. https://monblog.ci/feed)'),
          _f('title', 'Titre (facultatif)'),
          Row(children: [
            Text(tr('Articles')),
            Expanded(
              child: Slider(
                value: ((block['count'] as num?) ?? 3).toDouble().clamp(1, 10),
                min: 1,
                max: 10,
                divisions: 9,
                label: '${block['count'] ?? 3}',
                onChanged: (v) => _set('count', v.round()),
              ),
            ),
          ]),
          _f('link_label', 'Texte du lien'),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Afficher les images')), value: block['show_image'] != false, onChanged: (v) => _set('show_image', v)),
          Text(tr('Les articles sont relus au moment de l\'envoi : idéal pour une lettre d\'information hebdomadaire.'), style: TextStyle(fontSize: 12, color: hint)),
        ],
      'spacer' => [
          Slider(
            value: ((block['height'] as num?) ?? 24).toDouble().clamp(4, 120),
            min: 4,
            max: 120,
            divisions: 29,
            label: tr('${block['height'] ?? 24} px'),
            onChanged: (v) => _set('height', v.round()),
          ),
        ],
      'html' => [
          Text(tr('Section HTML libre (tableaux et styles en ligne conseillés). Les scripts sont retirés à l\'envoi.'), style: TextStyle(fontSize: 12, color: hint)),
          const SizedBox(height: 6),
          _f('html', 'HTML', lines: 14, mono: true),
        ],
      _ => [Text(tr('Aucun réglage pour ce bloc.'), style: TextStyle(color: hint))],
    };
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children);
  }

  /// Sous-éléments (colonnes, produits d'une vitrine).
  List<Widget> _subItem(BuildContext context, String listKey, int i, String title, List<String> fields, {bool removable = false}) {
    final list = mkList(block[listKey]);
    while (list.length <= i) {
      list.add({});
    }
    final item = list[i];
    void setField(String k, String v) {
      final copy = mkList(block[listKey]);
      while (copy.length <= i) {
        copy.add({});
      }
      copy[i] = {...copy[i], k: v};
      _set(listKey, copy);
    }

    const labels = {'image': 'Image', 'title': 'Titre', 'text': 'Texte', 'name': 'Nom', 'price': 'Prix', 'old_price': 'Ancien prix', 'url': 'Lien'};
    return [
      Padding(
        padding: const EdgeInsets.only(top: 6, bottom: 6),
        child: Row(children: [
          Expanded(child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w700))),
          if (removable)
            IconButton(
              visualDensity: VisualDensity.compact,
              onPressed: () => _set(listKey, mkList(block[listKey])..removeAt(i)),
              icon: const Icon(Icons.close, size: 18),
            ),
        ]),
      ),
      for (final f in fields)
        f == 'image'
            ? _imageField(context, 'image', labels[f]!, target: item, onUrl: (v) => setField('image', v), keyName: '$listKey-$i-$f-${list.length}')
            : _f(f, labels[f]!, target: item, lines: f == 'text' ? 3 : 1, onText: (v) => setField(f, v), keyName: '$listKey-$i-$f-${list.length}'),
    ];
  }
}
