import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'sites_screen.dart' show openUrl;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

Color shopStatusColor(String s) => switch (s) {
      'new' => YColors.blue,
      'confirmed' => const Color(0xFF0FA3B1),
      'preparing' => YColors.warning,
      'shipped' => const Color(0xFF8E44AD),
      'delivered' => YColors.success,
      _ => YColors.muted,
    };

const _next = {'new': 'confirmed', 'confirmed': 'preparing', 'preparing': 'shipped', 'shipped': 'delivered'};
const _nextLabel = {'new': 'Confirmer', 'confirmed': 'En préparation', 'preparing': 'Expédiée / prête', 'shipped': 'Livrée'};

/// Onglet « Boutique » du site : commandes reçues et réglages (livraison, paiement, catalogue).
class ShopTab extends StatefulWidget {
  const ShopTab({super.key});

  @override
  State<ShopTab> createState() => _ShopTabState();
}

class _ShopTabState extends State<ShopTab> {
  Map<String, dynamic>? _d;
  Object? _error;
  String _view = 'orders';
  String _filter = 'open';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('sites/shop');
      if (mounted) setState(() => (_d = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _status(Map<String, dynamic> o, String status, {bool paid = false}) async {
    try {
      await context.read<Session>().api.post('sites/orders/${o['id']}/status', {'status': status, if (paid) 'paid': true});
      await _load();
      if (mounted) toast(context, tr('Commande ${o['number']} : ${(_d?['statuses'] as Map?)?[status] ?? status}.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _d == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    final d = _d;
    if (d == null) return const LoadingSkeleton();
    final s = asMap(d['settings']);
    final st = asMap(d['stats']);
    final cur = context.watch<Session>().currency;
    final manage = context.watch<Session>().can('sites.manage');
    final hint = Theme.of(context).hintColor;
    Widget kpi(String l, String v, IconData ic, Color c) => KpiBox(width: 220, child: StatTile(label: l, value: v, icon: ic, color: c));
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Wrap(spacing: 14, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
              const Icon(Icons.storefront_outlined, color: YColors.blue),
              Text(tr(s['enabled'] == true ? 'Boutique en ligne ouverte' : 'Boutique en ligne fermée'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
              if (manage)
                Switch(
                  value: s['enabled'] == true,
                  onChanged: (v) async {
                    try {
                      await context.read<Session>().api.put('sites/shop/settings', {'enabled': v});
                      await _load();
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                ),
              Text(tr('${d['products']} produit(s) en vente'), style: TextStyle(color: hint)),
              if (s['enabled'] == true) ...[
                OutlinedButton.icon(onPressed: () => openUrl('${d['shop_url']}'), icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Voir la boutique'))),
                IconButton(tooltip: tr('Copier le lien'), onPressed: () => Clipboard.setData(ClipboardData(text: '${d['shop_url']}')), icon: const Icon(Icons.copy, size: 18)),
              ],
            ]),
          ),
        ),
        const SizedBox(height: 8),
        KpiWrap(children: [
          kpi('Nouvelles commandes', '${st['new']}', Icons.fiber_new_outlined, YColors.blue),
          kpi('En cours', '${st['open']}', Icons.local_shipping_outlined, YColors.warning),
          kpi('Commandes du mois', '${st['month_count']}', Icons.shopping_bag_outlined, YColors.night),
          kpi('Ventes du mois', money(toDouble(st['month_total']), cur), Icons.payments_outlined, YColors.success),
        ]),
        const SizedBox(height: 12),
        SingleChildScrollView(scrollDirection: Axis.horizontal, child: SegmentedButton<String>(
          segments: [
            ButtonSegment(value: 'orders', icon: Icon(Icons.receipt_long_outlined), label: Text(tr('Commandes'))),
            ButtonSegment(value: 'pages', icon: Icon(Icons.photo_library_outlined), label: Text(tr('Fiches produits'))),
            ButtonSegment(value: 'coupons', icon: Icon(Icons.local_offer_outlined), label: Text(tr('Codes promo'))),
            ButtonSegment(value: 'reviews', icon: const Icon(Icons.star_outline), label: Text(tr('Avis${(d['reviews'] as List).where((r) => (r as Map)['status'] == 'pending').isNotEmpty ? ' (${(d['reviews'] as List).where((r) => (r as Map)['status'] == 'pending').length})' : ''}'))),
            ButtonSegment(value: 'carts', icon: Icon(Icons.remove_shopping_cart_outlined), label: Text(tr('Paniers'))),
            if (manage) ButtonSegment(value: 'settings', icon: Icon(Icons.tune), label: Text(tr('Réglages'))),
          ],
          selected: {_view},
          onSelectionChanged: (v) => setState(() => _view = v.first),
        )),
        const SizedBox(height: 12),
        if (_view == 'orders') ..._orders(d, cur, manage)
        else if (_view == 'pages') _ProductPages(manage: manage, onChanged: _load)
        else if (_view == 'coupons') _Coupons(data: d, manage: manage, onChanged: _load)
        else if (_view == 'reviews') _Reviews(data: d, manage: manage, onChanged: _load)
        else if (_view == 'carts') _Carts(data: d)
        else _ShopSettings(data: d, onSaved: _load),
      ]),
    );
  }

  List<Widget> _orders(Map<String, dynamic> d, String cur, bool manage) {
    final all = [for (final o in d['orders'] as List) asMap(o)];
    final rows = all.where((o) => switch (_filter) {
          'open' => ['new', 'confirmed', 'preparing', 'shipped'].contains(o['status']),
          'delivered' => o['status'] == 'delivered',
          'cancelled' => o['status'] == 'cancelled',
          _ => true,
        }).toList();
    final hint = Theme.of(context).hintColor;
    return [
      Wrap(spacing: 8, children: [
        for (final f in {'open': 'À traiter', 'delivered': 'Livrées', 'cancelled': 'Annulées', 'all': 'Toutes'}.entries)
          ChoiceChip(label: Text(tr(f.value)), selected: _filter == f.key, onSelected: (_) => setState(() => _filter = f.key)),
      ]),
      const SizedBox(height: 10),
      if (rows.isEmpty) EmptyState(icon: Icons.receipt_long_outlined, title: tr('Aucune commande'), message: tr('Les commandes passées sur la boutique du site arrivent ici et dans les Ventes.')),
      for (final (i, o) in rows.indexed)
        AnimatedFadeIn(
          index: i % 10,
          child: Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: () => _detail(o, cur, manage),
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  CircleAvatar(backgroundColor: shopStatusColor('${o['status']}').withValues(alpha: .12), child: Icon(Icons.shopping_bag_outlined, color: shopStatusColor('${o['status']}'))),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${o['number']} · ${o['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(tr('${(o['items'] as List).length} article(s) · ${o['delivery_mode']} · ${dateTimeFr(o['created_at'])}'), style: TextStyle(color: hint, fontSize: 12.5)),
                    ]),
                  ),
                  Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    Text(tr(money(toDouble(o['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 4),
                    Wrap(spacing: 6, children: [
                      StatusChip(o['payment_status'] == 'paid' ? 'Payée' : 'À encaisser', color: o['payment_status'] == 'paid' ? YColors.success : YColors.danger),
                      StatusChip('${o['status_label']}', color: shopStatusColor('${o['status']}')),
                    ]),
                  ]),
                ]),
              ),
            ),
          ),
        ),
    ];
  }

  Future<void> _detail(Map<String, dynamic> o, String cur, bool manage) => showAdaptiveSheet(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (c) => SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(tr('Commande ${o['number']}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
                StatusChip('${o['status_label']}', color: shopStatusColor('${o['status']}')),
              ]),
              const SizedBox(height: 4),
              Text(tr('${o['name']} · ${o['phone']}${o['email'] != null ? ' · ${o['email']}' : ''}'), style: TextStyle(color: Theme.of(c).hintColor)),
              if (o['address'] != null) Text(tr('${o['delivery_mode']} : ${o['address']}')) else Text(tr('${o['delivery_mode']}')),
              const Divider(height: 22),
              for (final it in o['items'] as List)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(children: [Expanded(child: Text(tr('${(it as Map)['quantity']} × ${it['name']}'))), Text(tr(money(toDouble(it['total']), cur)))]),
                ),
              if (toDouble(o['discount']) > 0) Row(children: [Expanded(child: Text(tr('Code promo ${o['coupon_code']}'), style: const TextStyle(color: YColors.success))), Text(tr('−${money(toDouble(o['discount']), cur)}'), style: const TextStyle(color: YColors.success))]),
              if (toDouble(o['delivery_fee']) > 0) Row(children: [Expanded(child: Text(tr('Livraison'))), Text(tr(money(toDouble(o['delivery_fee']), cur)))]),
              const SizedBox(height: 6),
              Row(children: [Expanded(child: Text(tr('Total TTC'), style: TextStyle(fontWeight: FontWeight.w700))), Text(tr(money(toDouble(o['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17))]),
              const SizedBox(height: 6),
              Text(tr('${o['payment_label']} · ${o['payment_status'] == 'paid' ? 'payée' : 'à encaisser'}${o['document'] != null ? ' · ${o['document']['type'] == 'invoice' ? 'Facture' : 'Commande'} ${o['document']['number'] ?? ''}' : ''}'), style: TextStyle(color: Theme.of(c).hintColor)),
              if (o['note'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Note du client : ${o['note']}'))),
              const SizedBox(height: 16),
              Wrap(spacing: 8, runSpacing: 8, children: [
                if (manage && _next[o['status']] != null)
                  FilledButton.icon(
                    onPressed: () {
                      Navigator.pop(c);
                      _status(o, _next[o['status']]!);
                    },
                    icon: const Icon(Icons.arrow_forward),
                    label: Text(tr(_nextLabel[o['status']]!)),
                  ),
                if (manage && o['payment_status'] != 'paid' && o['payment_mode'] != 'online' && o['status'] != 'cancelled')
                  OutlinedButton.icon(
                    onPressed: () {
                      Navigator.pop(c);
                      _status(o, o['status'] == 'shipped' ? 'delivered' : '${o['status']}', paid: true);
                    },
                    icon: const Icon(Icons.payments_outlined),
                    label: Text(tr(o['status'] == 'shipped' ? 'Livrée et encaissée' : 'Marquer encaissée')),
                  ),
                if (o['whatsapp_url'] != null) OutlinedButton.icon(onPressed: () => openUrl('${o['whatsapp_url']}'), icon: const Icon(Icons.chat_outlined), label: Text(tr('WhatsApp'))),
                TextButton.icon(onPressed: () => Clipboard.setData(ClipboardData(text: '${o['track_url']}')), icon: const Icon(Icons.link), label: Text(tr('Lien de suivi'))),
                if (manage && !['delivered', 'cancelled'].contains(o['status']) && o['payment_status'] != 'paid')
                  TextButton(
                    style: TextButton.styleFrom(foregroundColor: YColors.danger),
                    onPressed: () async {
                      Navigator.pop(c);
                      if (await confirm(context, tr('Annuler la commande ${o['number']} ?'), tr('Le document de vente est annulé lui aussi.'), ok: tr('Annuler la commande'), danger: true)) _status(o, 'cancelled');
                    },
                    child: Text(tr('Annuler')),
                  ),
              ]),
            ]),
          ),
        ),
      );
}

class _ShopSettings extends StatefulWidget {
  const _ShopSettings({required this.data, required this.onSaved});

  final Map<String, dynamic> data;
  final Future<void> Function() onSaved;

  @override
  State<_ShopSettings> createState() => _ShopSettingsState();
}

class _ShopSettingsState extends State<_ShopSettings> {
  late final Map<String, dynamic> s = asMap(widget.data['settings']);
  late final _addr = TextEditingController(text: '${s['pickup_address'] ?? ''}');
  late final _momo = TextEditingController(text: '${s['momo_instructions'] ?? ''}');
  late final _note = TextEditingController(text: '${s['note'] ?? ''}');
  late final _free = TextEditingController(text: s['free_from'] == null ? '' : '${toDouble(s['free_from']).round()}');
  late final _min = TextEditingController(text: toDouble(s['min_order']) > 0 ? '${toDouble(s['min_order']).round()}' : '');
  late final List<(TextEditingController, TextEditingController)> _zones = [
    for (final z in (s['zones'] as List? ?? const [])) (TextEditingController(text: '${(z as Map)['name']}'), TextEditingController(text: '${toDouble(z['fee']).round()}')),
  ];
  late final Set<String> _cats = {for (final c in (s['categories'] as List? ?? const [])) '$c'};

  Future<void> _save() async {
    try {
      await context.read<Session>().api.put('sites/shop/settings', {
        'pickup': s['pickup'] == true, 'pickup_address': _addr.text, 'payment_online': s['payment_online'] == true, 'payment_cod': s['payment_cod'] == true,
        'payment_momo': s['payment_momo'] == true, 'momo_instructions': _momo.text, 'note': _note.text, 'hide_out_of_stock': s['hide_out_of_stock'] == true,
        'free_from': _free.text.trim().isEmpty ? null : toDouble(_free.text), 'min_order': toDouble(_min.text),
        'zones': [for (final z in _zones) if (z.$1.text.trim().isNotEmpty) {'name': z.$1.text.trim(), 'fee': toDouble(z.$2.text)}],
        'categories': _cats.toList(),
        for (final k in ['reviews_enabled', 'reviews_auto_publish', 'cart_reminders']) k: s[k] == true,
        'review_delay_days': toDouble(s['review_delay_days']).round(), 'cart_delay_hours': toDouble(s['cart_delay_hours']).round(),
      });
      await widget.onSaved();
      if (mounted) toast(context, tr('Réglages de la boutique enregistrés.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cats = [for (final c in widget.data['categories'] as List) asMap(c)];
    final digits = [FilteringTextInputFormatter.digitsOnly];
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionCard(
        title: tr('Réception de la commande'),
        child: Column(children: [
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Retrait en boutique')), value: s['pickup'] == true, onChanged: (v) => setState(() => s['pickup'] = v)),
          if (s['pickup'] == true) Field(label: tr('Adresse de retrait'), child: TextField(controller: _addr)),
          for (final (i, z) in _zones.indexed)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(children: [
                Expanded(flex: 3, child: TextField(controller: z.$1, decoration: InputDecoration(isDense: true, labelText: tr('Zone de livraison')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: z.$2, inputFormatters: digits, keyboardType: TextInputType.number, decoration: InputDecoration(isDense: true, labelText: tr('Frais')))),
                IconButton(onPressed: () => setState(() => _zones.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
              ]),
            ),
          Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => setState(() => _zones.add((TextEditingController(), TextEditingController(text: '0')))), icon: const Icon(Icons.add), label: Text(tr('Zone de livraison')))),
          Row(children: [
            Expanded(child: Field(label: tr('Livraison offerte dès (facultatif)'), child: TextField(controller: _free, inputFormatters: digits, keyboardType: TextInputType.number))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Commande minimum'), child: TextField(controller: _min, inputFormatters: digits, keyboardType: TextInputType.number))),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Paiement'),
        child: Column(children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('Paiement en ligne (Mobile Money, carte)')),
            subtitle: Text(tr(widget.data['online_available'] == true ? 'Via vos moyens de paiement en ligne' : 'Configurez un moyen de paiement en ligne (Paramètres, rubrique Paiements) pour l\'activer')),
            value: s['payment_online'] == true,
            onChanged: (v) => setState(() => s['payment_online'] = v),
          ),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Paiement à la livraison')), value: s['payment_cod'] == true, onChanged: (v) => setState(() => s['payment_cod'] = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Transfert Mobile Money')), value: s['payment_momo'] == true, onChanged: (v) => setState(() => s['payment_momo'] = v)),
          if (s['payment_momo'] == true) Field(label: tr('Instructions du transfert'), child: TextField(controller: _momo, decoration: InputDecoration(hintText: tr('Envoyez au 07 00 00 00 00 (Orange Money, Wave)')))),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Catalogue'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('Produits actifs avec un prix de vente, affichés TTC. Limitez à certaines catégories (sinon tout le catalogue) :')),
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final c in cats) FilterChip(label: Text(tr('${c['name']}')), selected: _cats.contains(c['id']), onSelected: (v) => setState(() => v ? _cats.add('${c['id']}') : _cats.remove(c['id']))),
          ]),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Masquer les produits en rupture')), value: s['hide_out_of_stock'] == true, onChanged: (v) => setState(() => s['hide_out_of_stock'] = v)),
          Field(label: tr('Message d\'accueil de la boutique'), child: TextField(controller: _note, maxLines: 2)),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Avis et relances'),
        child: Column(children: [
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Demander un avis après la livraison')), subtitle: Text(tr('${toDouble(s['review_delay_days']).round()} jour(s) après la livraison, par e-mail ou WhatsApp')), value: s['reviews_enabled'] == true, onChanged: (v) => setState(() => s['reviews_enabled'] = v)),
          if (s['reviews_enabled'] == true)
            Row(children: [
              Expanded(child: Text(tr('Délai (jours)'))),
              SizedBox(width: 160, child: Slider(min: 0, max: 7, divisions: 7, value: toDouble(s['review_delay_days']).clamp(0, 7), label: '${toDouble(s['review_delay_days']).round()} j', onChanged: (v) => setState(() => s['review_delay_days'] = v.round()))),
            ]),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Publier les avis sans vérification')), subtitle: Text(tr('Sinon, chaque avis attend votre validation')), value: s['reviews_auto_publish'] == true, onChanged: (v) => setState(() => s['reviews_auto_publish'] = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Relancer les paniers abandonnés')), subtitle: Text(tr('Une fois, ${toDouble(s['cart_delay_hours']).round()} h après, par e-mail ou WhatsApp')), value: s['cart_reminders'] == true, onChanged: (v) => setState(() => s['cart_reminders'] = v)),
          if (s['cart_reminders'] == true)
            Row(children: [
              Expanded(child: Text(tr('Délai (heures)'))),
              SizedBox(width: 160, child: Slider(min: 1, max: 48, divisions: 47, value: toDouble(s['cart_delay_hours']).clamp(1, 48), label: '${toDouble(s['cart_delay_hours']).round()} h', onChanged: (v) => setState(() => s['cart_delay_hours'] = v.round()))),
            ]),
        ]),
      ),
      const SizedBox(height: 12),
      Align(alignment: Alignment.centerRight, child: SaveButton(onPressed: _save, icon: const Icon(Icons.save_outlined), child: Text(tr('Enregistrer')))),
    ]);
  }
}

// ================================================================= Blog

class BlogTab extends StatefulWidget {
  const BlogTab({super.key});

  @override
  State<BlogTab> createState() => _BlogTabState();
}

class _BlogTabState extends State<BlogTab> {
  List<Map<String, dynamic>>? _rows;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('sites/posts');
      if (mounted) setState(() => _rows = [for (final p in r['data'] as List) asMap(p)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    if (rows == null) return const LoadingSkeleton();
    final manage = context.watch<Session>().can('sites.manage');
    final hint = Theme.of(context).hintColor;
    return Stack(children: [
      rows.isEmpty
          ? EmptyState(icon: Icons.article_outlined, title: tr('Aucun article'), message: tr('Publiez vos actualités, conseils et nouveautés : ils apparaissent sur le site et améliorent votre référencement Google.'))
          : ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 90), children: [
              for (final (i, p) in rows.indexed)
                AnimatedFadeIn(
                  index: i % 10,
                  child: Card(
                    clipBehavior: Clip.antiAlias,
                    child: InkWell(
                      onTap: manage ? () => _edit(p) : () => openUrl('${p['url']}'),
                      child: Row(children: [
                        SizedBox(
                          width: 120,
                          height: 90,
                          child: p['cover_url'] != null
                              ? Image.network('${p['cover_url']}', fit: BoxFit.cover, errorBuilder: (_, _, _) => const Icon(Icons.image_outlined))
                              : Container(color: YColors.blue.withValues(alpha: .08), child: const Icon(Icons.article_outlined, color: YColors.blue)),
                        ),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(tr('${p['title']}'), style: const TextStyle(fontWeight: FontWeight.w700), maxLines: 2, overflow: TextOverflow.ellipsis),
                            Text(tr(p['published'] == true ? 'Publié le ${dateFr('${p['published_at']}'.substring(0, 10))} · ${p['views']} lecture(s)' : 'Brouillon'), style: TextStyle(color: hint, fontSize: 12.5)),
                          ]),
                        ),
                        StatusChip(p['published'] == true ? 'Publié' : 'Brouillon', color: p['published'] == true ? YColors.success : YColors.muted),
                        IconButton(tooltip: tr('Ouvrir'), onPressed: () => openUrl('${p['url']}'), icon: const Icon(Icons.open_in_new, size: 18)),
                      ]),
                    ),
                  ),
                ),
            ]),
      if (manage) Positioned(right: 20, bottom: 20, child: FloatingActionButton.extended(heroTag: 'blog', onPressed: () => _edit(null), icon: const Icon(Icons.add), label: Text(tr('Nouvel article')))),
    ]);
  }

  Future<void> _edit(Map<String, dynamic>? p) async {
    final title = TextEditingController(text: '${p?['title'] ?? ''}');
    final excerpt = TextEditingController(text: '${p?['excerpt'] ?? ''}');
    final body = TextEditingController(text: '${p?['body'] ?? ''}');
    var published = p?['published'] == true;
    List<int>? cover;
    String? coverName;
    final api = context.read<Session>().api;
    final saved = await showFormDialog<bool>(context, title: p == null ? 'Nouvel article' : 'Modifier l\'article', maxWidth: 768, builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(controller: title, decoration: InputDecoration(labelText: tr('Titre'))),
              const SizedBox(height: 10),
              TextField(controller: excerpt, maxLines: 2, decoration: InputDecoration(labelText: tr('Résumé (affiché dans la liste et sur Google)'))),
              const SizedBox(height: 10),
              TextField(controller: body, minLines: 10, maxLines: 20, decoration: InputDecoration(labelText: tr('Texte'), alignLabelWithHint: true, hintText: tr('## Intertitre\n\nParagraphe avec **gras** et *italique*.\n\n- point 1\n- point 2\n\n[lien](https://…)'))),
              const SizedBox(height: 6),
              Text(tr('Mise en forme : ## intertitre, **gras**, *italique*, « - » pour une liste, [texte](https://adresse) pour un lien.'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12)),
              const SizedBox(height: 10),
              Row(children: [
                OutlinedButton.icon(
                  onPressed: () async {
                    final picked = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: const ['png', 'jpg', 'jpeg', 'webp']);
                    if (picked.isEmpty) return;
                    final b = await picked.single.readAsBytes();
                    set(() => (cover = b, coverName = picked.single.name));
                  },
                  icon: const Icon(Icons.image_outlined),
                  label: Text(tr(cover != null ? coverName! : (p?['cover_url'] != null ? 'Changer l\'image' : 'Image de couverture'))),
                ),
                const Spacer(),
                Text(tr('Publié')),
                Switch(value: published, onChanged: (v) => set(() => published = v)),
              ]),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                if (p != null)
                  TextButton(
                    style: TextButton.styleFrom(foregroundColor: YColors.danger),
                    onPressed: () async {
                      if (!await confirm(c, tr('Supprimer l\'article ?'), tr('« ${p['title']} » sera retiré du site.'), ok: tr('Supprimer'), danger: true)) return;
                      try {
                        await api.delete('sites/posts/${p['id']}');
                        if (c.mounted) Navigator.pop(c, true);
                      } catch (e) {
                        if (c.mounted) toast(c, errorText(e), error: true);
                      }
                    },
                    child: Text(tr('Supprimer')),
                  ),
                TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
                SaveButton(
                  onPressed: () async {
                    final fields = {'title': title.text.trim(), 'excerpt': excerpt.text.trim(), 'body': body.text, 'published': published ? '1' : '0'};
                    final path = p == null ? 'sites/posts' : 'sites/posts/${p['id']}';
                    try {
                      if (cover != null) {
                        await api.uploadForm(path, fields, 'cover', cover!, coverName ?? 'image.jpg');
                      } else {
                        await api.post(path, {...fields, 'published': published});
                      }
                      if (c.mounted) Navigator.pop(c, true);
                    } catch (e) {
                      if (c.mounted) toast(c, errorText(e), error: true);
                    }
                  },
                  child: Text(tr('Enregistrer')),
                ),
              ]),
            ),
          ]),
        ),
      ));
    if (saved == true) {
      await _load();
      if (mounted) toast(context, tr('Article enregistré.'));
    }
  }
}


// ================================================================= Codes promo

class _Coupons extends StatelessWidget {
  const _Coupons({required this.data, required this.manage, required this.onChanged});

  final Map<String, dynamic> data;
  final bool manage;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final rows = [for (final c in data['coupons'] as List) asMap(c)];
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    String what(Map<String, dynamic> c) => switch ('${c['type']}') {
          'percent' => '-${toDouble(c['value']) == toDouble(c['value']).roundToDouble() ? toDouble(c['value']).round() : c['value']} %',
          'amount' => '-${money(toDouble(c['value']), cur)}',
          _ => 'Livraison offerte',
        };
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (manage) Align(alignment: Alignment.centerRight, child: FilledButton.icon(onPressed: () => _edit(context, null), icon: const Icon(Icons.add), label: Text(tr('Code promo')))),
      const SizedBox(height: 8),
      if (rows.isEmpty) EmptyState(icon: Icons.local_offer_outlined, title: tr('Aucun code promo'), message: tr('Créez BIENVENUE, SOLDES ou LIVRAISON pour vos réseaux sociaux : le client le saisit au moment de commander.')),
      for (final c in rows)
        Card(
          child: ListTile(
            onTap: manage ? () => _edit(context, c) : null,
            leading: CircleAvatar(backgroundColor: (c['active'] == true ? YColors.success : YColors.muted).withValues(alpha: .12), child: Icon(Icons.local_offer_outlined, color: c['active'] == true ? YColors.success : YColors.muted)),
            title: Text(tr('${c['code']} · ${what(c)}'), style: const TextStyle(fontWeight: FontWeight.w800, letterSpacing: .5)),
            subtitle: Text(tr([
              if (c['description'] != null) '${c['description']}',
              if (toDouble(c['min_order']) > 0) 'dès ${money(toDouble(c['min_order']), cur)}',
              if (c['starts_on'] != null || c['ends_on'] != null) 'du ${c['starts_on'] != null ? dateFr('${c['starts_on']}'.substring(0, 10)) : '…'} au ${c['ends_on'] != null ? dateFr('${c['ends_on']}'.substring(0, 10)) : '…'}',
              if (c['once_per_customer'] == true) '1 fois par client',
            ].join(' · ')), style: TextStyle(color: hint)),
            trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(tr('${c['uses']}${c['max_uses'] != null ? ' / ${c['max_uses']}' : ''} utilisation(s)'), style: const TextStyle(fontWeight: FontWeight.w700)),
              Text(tr('${money(toDouble(c['revenue']), cur)} de ventes · ${money(toDouble(c['discounts']), cur)} offerts'), style: TextStyle(color: hint, fontSize: 12)),
            ]),
          ),
        ),
    ]);
  }

  Future<void> _edit(BuildContext context, Map<String, dynamic>? c) async {
    final code = TextEditingController(text: '${c?['code'] ?? ''}');
    final value = TextEditingController(text: c == null ? '' : '${toDouble(c['value']).round()}');
    final min = TextEditingController(text: toDouble(c?['min_order']) > 0 ? '${toDouble(c!['min_order']).round()}' : '');
    final max = TextEditingController(text: '${c?['max_uses'] ?? ''}');
    final desc = TextEditingController(text: '${c?['description'] ?? ''}');
    var type = '${c?['type'] ?? 'percent'}';
    var once = c?['once_per_customer'] == true, active = c?['active'] != false;
    String? from = c?['starts_on'] != null ? '${c!['starts_on']}'.substring(0, 10) : null, to = c?['ends_on'] != null ? '${c!['ends_on']}'.substring(0, 10) : null;
    final api = context.read<Session>().api;
    await showFormDialog(context, title: c == null ? 'Nouveau code promo' : 'Code ${c['code']}', builder: (dc) => StatefulBuilder(
        builder: (dc, set) {
          Future<void> pick(bool start) async {
            final d = await pickDate(dc, firstDate: DateTime(2024), lastDate: DateTime(2035), initialDate: DateTime.tryParse((start ? from : to) ?? '') ?? DateTime.now());
            if (d != null) set(() => start ? from = d.toIso8601String().substring(0, 10) : to = d.toIso8601String().substring(0, 10));
          }

          return SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
              Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                TextField(controller: code, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(labelText: tr('Code (ex. BIENVENUE)'))),
                const SizedBox(height: 10),
                SegmentedButton<String>(
                  segments: [ButtonSegment(value: 'percent', label: Text('%')), ButtonSegment(value: 'amount', label: Text(tr('Montant'))), ButtonSegment(value: 'shipping', label: Text(tr('Livraison offerte')))],
                  selected: {type},
                  onSelectionChanged: (v) => set(() => type = v.first),
                ),
                if (type != 'shipping') TextField(controller: value, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: trn(type == 'percent' ? 'Remise (%)' : 'Remise (montant TTC)'))),
                TextField(controller: min, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Achat minimum (facultatif)'))),
                TextField(controller: max, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Nombre maximal d\'utilisations (facultatif)'))),
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(child: OutlinedButton(onPressed: () => pick(true), child: Text(tr(from == null ? 'Début : immédiat' : 'Du ${dateFr(from)}')))),
                  const SizedBox(width: 8),
                  Expanded(child: OutlinedButton(onPressed: () => pick(false), child: Text(tr(to == null ? 'Fin : aucune' : 'Au ${dateFr(to)}')))),
                ]),
                SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Une seule fois par client')), value: once, onChanged: (v) => set(() => once = v)),
                SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Actif')), value: active, onChanged: (v) => set(() => active = v)),
                TextField(controller: desc, decoration: InputDecoration(labelText: tr('Note interne (facultatif)'))),
              ]),
              const SizedBox(height: 20),
              Align(
                alignment: Alignment.centerRight,
                child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                  if (c != null)
                    TextButton(
                      style: TextButton.styleFrom(foregroundColor: YColors.danger),
                      onPressed: () async {
                        await api.delete('sites/coupons/${c['id']}');
                        if (dc.mounted) Navigator.pop(dc);
                        await onChanged();
                      },
                      child: Text(tr('Supprimer')),
                    ),
                  TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))),
                  SaveButton(
                    onPressed: () async {
                      try {
                        await api.post(c == null ? 'sites/coupons' : 'sites/coupons/${c['id']}', {
                          'code': code.text.trim(), 'type': type, 'value': toDouble(value.text), 'min_order': toDouble(min.text), 'max_uses': max.text.trim().isEmpty ? null : toDouble(max.text).round(),
                          'starts_on': from, 'ends_on': to, 'once_per_customer': once, 'active': active, 'description': desc.text.trim(),
                        });
                        if (dc.mounted) Navigator.pop(dc);
                        await onChanged();
                      } catch (e) {
                        if (dc.mounted) toast(dc, errorText(e), error: true);
                      }
                    },
                    child: Text(tr('Enregistrer')),
                  ),
                ]),
              ),
            ]),
          );
        },
      ));
  }
}

// ================================================================= Avis

class _Reviews extends StatelessWidget {
  const _Reviews({required this.data, required this.manage, required this.onChanged});

  final Map<String, dynamic> data;
  final bool manage;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final rows = [for (final r in data['reviews'] as List) asMap(r)];
    final rt = asMap(data['ratings']);
    final hint = Theme.of(context).hintColor;
    final api = context.read<Session>().api;
    Future<void> upd(Map<String, dynamic> r, Map<String, dynamic> body) async {
      try {
        await api.post('sites/reviews/${r['id']}', body);
        await onChanged();
      } catch (e) {
        if (context.mounted) toast(context, errorText(e), error: true);
      }
    }

    Widget stars(int n, [double size = 18]) => Row(mainAxisSize: MainAxisSize.min, children: [for (var i = 1; i <= 5; i++) Icon(i <= n ? Icons.star_rounded : Icons.star_outline_rounded, size: size, color: const Color(0xFFE8A317))]);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Card(
        child: ListTile(
          leading: const Icon(Icons.verified_outlined, color: YColors.success),
          title: Text(tr(rt['count'] == 0 ? 'Pas encore d\'avis publié' : '${toDouble(rt['average']).toStringAsFixed(1)} / 5 · ${rt['count']} avis vérifiés')),
          subtitle: Text(tr('Chaque avis est lié à une commande livrée (« Achat vérifié »). Les clients reçoivent le lien après la livraison.')),
        ),
      ),
      if (rows.isEmpty) EmptyState(icon: Icons.star_outline, title: tr('Aucun avis')),
      for (final r in rows)
        Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                stars(toDouble(r['rating']).round()),
                const SizedBox(width: 10),
                Expanded(child: Text(tr('${r['name']} · ${r['order_number'] ?? ''} · ${dateFr('${r['created_at']}'.substring(0, 10))}'), style: TextStyle(color: hint, fontSize: 12.5))),
                StatusChip(switch ('${r['status']}') { 'published' => 'Publié', 'pending' => 'À valider', _ => 'Masqué' }, color: switch ('${r['status']}') { 'published' => YColors.success, 'pending' => YColors.warning, _ => YColors.muted }),
              ]),
              if (r['comment'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('${r['comment']}'))),
              if (r['products'] is Map && (r['products'] as Map).isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Wrap(spacing: 12, runSpacing: 4, children: [
                    for (final e in (r['products'] as Map).entries) Row(mainAxisSize: MainAxisSize.min, children: [Text(tr('${(r['items'] as Map?)?[e.key] ?? 'Article'} '), style: TextStyle(color: hint, fontSize: 12.5)), stars(toDouble(e.value).round(), 14)]),
                  ]),
                ),
              if (r['reply'] != null) Container(margin: const EdgeInsets.only(top: 8), padding: const EdgeInsets.only(left: 10), decoration: const BoxDecoration(border: Border(left: BorderSide(color: YColors.blue, width: 3))), child: Text(tr('Votre réponse : ${r['reply']}'), style: TextStyle(color: hint))),
              if (manage)
                Wrap(spacing: 8, children: [
                  if (r['status'] != 'published') TextButton.icon(onPressed: () => upd(r, {'status': 'published'}), icon: const Icon(Icons.check, size: 18), label: Text(tr('Publier'))),
                  if (r['status'] == 'published') TextButton.icon(onPressed: () => upd(r, {'status': 'hidden'}), icon: const Icon(Icons.visibility_off_outlined, size: 18), label: Text(tr('Masquer'))),
                  TextButton.icon(
                    onPressed: () async {
                      final c = TextEditingController(text: '${r['reply'] ?? ''}');
                      final ok = await showDialog<bool>(
                        context: context,
                        builder: (d) => YAlertDialog(
                          title: Text(tr('Répondre publiquement')),
                          content: TextField(controller: c, maxLines: 4, decoration: InputDecoration(hintText: tr('Merci pour votre confiance…'))),
                          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Publier la réponse')))],
                        ),
                      );
                      if (ok == true) await upd(r, {'reply': c.text});
                    },
                    icon: const Icon(Icons.reply, size: 18),
                    label: Text(tr(r['reply'] == null ? 'Répondre' : 'Modifier la réponse')),
                  ),
                ]),
            ]),
          ),
        ),
    ]);
  }
}

// ================================================================= Paniers abandonnés

class _Carts extends StatelessWidget {
  const _Carts({required this.data});

  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final rows = [for (final c in data['carts'] as List) asMap(c)];
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final open = rows.where((c) => c['state'] == 'abandoned' || c['state'] == 'reminded').toList();
    final recovered = rows.where((c) => c['state'] == 'recovered').toList();
    const labels = {'abandoned': 'Abandonné', 'reminded': 'Relancé', 'recovered': 'Récupéré', 'ordered': 'Commandé'};
    const colors = {'abandoned': YColors.danger, 'reminded': YColors.warning, 'recovered': YColors.success, 'ordered': YColors.blue};
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Card(
        child: ListTile(
          leading: const Icon(Icons.remove_shopping_cart_outlined, color: YColors.warning),
          title: Text(tr('${open.length} panier(s) en attente · ${money(open.fold<double>(0, (s, c) => s + toDouble(c['total'])), cur)}')),
          subtitle: Text(tr('${recovered.length} récupéré(s) après relance sur 30 jours (${money(recovered.fold<double>(0, (s, c) => s + toDouble(c['total'])), cur)}). Le panier est enregistré dès que le client saisit son téléphone ou son e-mail.')),
        ),
      ),
      if (rows.isEmpty) EmptyState(icon: Icons.shopping_cart_outlined, title: tr('Aucun panier enregistré')),
      for (final c in rows)
        Card(
          child: ListTile(
            title: Text(tr('${c['name'] ?? c['phone'] ?? c['email']} · ${money(toDouble(c['total']), cur)}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(tr([
              (c['items'] as List).map((i) => '${(i as Map)['quantity']} × ${i['name']}').join(', '),
              dateTimeFr(c['updated_at']),
              if (c['reminded_at'] != null) 'relancé par ${c['reminder_channel'] == 'whatsapp' ? 'WhatsApp' : 'e-mail'}',
            ].join(' · ')), style: TextStyle(color: hint)),
            trailing: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
              StatusChip(labels[c['state']] ?? '${c['state']}', color: colors[c['state']] ?? YColors.muted),
              if (c['whatsapp_url'] != null && (c['state'] == 'abandoned' || c['state'] == 'reminded')) IconButton(tooltip: tr('Relancer sur WhatsApp'), onPressed: () => openUrl('${c['whatsapp_url']}'), icon: const Icon(Icons.chat_outlined, color: YColors.success)),
            ]),
          ),
        ),
    ]);
  }
}

// ================================================================= Fiches produits

/// Fiches produits de la boutique : photos (avec étiquette de couleur), description longue, badge, prix barré, produits liés.
class _ProductPages extends StatefulWidget {
  const _ProductPages({required this.manage, required this.onChanged});

  final bool manage;
  final Future<void> Function() onChanged;

  @override
  State<_ProductPages> createState() => _ProductPagesState();
}

class _ProductPagesState extends State<_ProductPages> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _q = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('sites/shop/products');
      if (mounted) setState(() => (_rows = [for (final p in r['data'] as List) asMap(p)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _rows == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    final rows = _rows;
    if (rows == null) return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final shown = rows.where((p) => _q.isEmpty || '${p['name']} ${p['category'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())).toList();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      TextField(
        decoration: InputDecoration(prefixIcon: Icon(Icons.search), hintText: tr('Rechercher un produit…'), isDense: true),
        onChanged: (v) => setState(() => _q = v.trim()),
      ),
      const SizedBox(height: 6),
      Text(tr('Chaque produit a sa page sur le site : photos, description détaillée, choix des variantes (taille, couleur…) et « Vous aimerez aussi ». Les variantes se gèrent dans Articles.'),
          style: TextStyle(color: hint, fontSize: 12.5)),
      const SizedBox(height: 10),
      if (rows.isEmpty) EmptyState(icon: Icons.inventory_2_outlined, title: tr('Aucun produit en vente'), message: tr('Ajoutez des articles avec un prix de vente dans Articles : ils apparaissent ici et dans la boutique.')),
      for (final (i, p) in shown.indexed)
        AnimatedFadeIn(
          index: i % 10,
          child: Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: widget.manage ? () => _edit(p) : () => openUrl('${p['url']}'),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: SizedBox(
                      width: 64,
                      height: 64,
                      child: p['image'] != null
                          ? Image.network('${p['image']}', fit: BoxFit.cover, errorBuilder: (_, _, _) => _ph())
                          : _ph(),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      const SizedBox(height: 2),
                      Text(tr([
                        if (p['category'] != null) '${p['category']}',
                        if (p['is_template'] == true) '${p['variants']} variante(s)',
                        '${(p['page']['photos'] as List).length} photo(s)',
                        if ((p['page']['related'] as List).isNotEmpty) '${(p['page']['related'] as List).length} produit(s) lié(s)',
                        if (p['visible'] != true) 'masqué de la boutique',
                      ].join(' · ')), style: TextStyle(color: hint, fontSize: 12.5)),
                      const SizedBox(height: 4),
                      Wrap(spacing: 6, runSpacing: 4, children: [
                        if (p['page']['badge'] != null) StatusChip('${p['page']['badge']}', color: YColors.warning),
                        if (p['rating'] != null) StatusChip(tr('${p['rating']['average']}/5 · ${p['rating']['count']} avis'), color: YColors.success),
                        if (p['page']['description'] == null) StatusChip(tr('Sans description'), color: YColors.muted),
                      ]),
                    ]),
                  ),
                  Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    if (p['price_from'] == true) Text(tr('à partir de'), style: TextStyle(color: hint, fontSize: 11.5)),
                    Text(tr(money(toDouble(p['price']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                    if (p['page']['compare_at'] != null) Text(tr(money(toDouble(p['page']['compare_at']), cur)), style: TextStyle(color: hint, fontSize: 12, decoration: TextDecoration.lineThrough)),
                  ]),
                ]),
              ),
            ),
          ),
        ),
    ]);
  }

  Widget _ph() => Container(color: YColors.blue.withValues(alpha: .08), child: const Icon(Icons.image_outlined, color: YColors.blue));

  Future<void> _edit(Map<String, dynamic> start) async {
    final api = context.read<Session>().api;
    var p = start;
    final desc = TextEditingController(text: '${p['page']['description'] ?? ''}');
    final badge = TextEditingController(text: '${p['page']['badge'] ?? ''}');
    final compare = TextEditingController(text: p['page']['compare_at'] != null ? '${toDouble(p['page']['compare_at']).round()}' : '');
    var related = [for (final r in p['page']['related'] as List) '$r'];
    var busy = false;
    final values = [for (final v in p['attribute_values'] as List) '$v'];
    final names = {for (final r in _rows!) '${r['id']}': '${r['name']}'};
    final cur = context.read<Session>().currency;
    await showFormDialog(context, title: '${p['name']}', maxWidth: 808, builder: (dc) => StatefulBuilder(builder: (dc, set) {
        final photos = [for (final ph in p['page']['photos'] as List) asMap(ph)];
        Future<bool> run(Future<dynamic> Function() f) async {
          set(() => busy = true);
          var ok = false;
          try {
            final r = await f();
            set(() => p = asMap(r['data']));
            ok = true;
          } catch (e) {
            if (dc.mounted) toast(dc, errorText(e), error: true);
          }
          if (dc.mounted) set(() => busy = false);
          return ok;
        }

        Future<void> addPhotos() async {
          final picked = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: const ['png', 'jpg', 'jpeg', 'webp']);
          for (final f in picked) {
            final b = await f.readAsBytes();
            await run(() => api.uploadForm('sites/shop/products/${p['id']}/photos', {}, 'file', b, f.name));
          }
        }

        Future<void> pickRelated() async {
          final chosen = [...related];
          await showDialog(
            context: dc,
            builder: (rc) => StatefulBuilder(builder: (rc, rset) => YAlertDialog(
                  title: Text(tr('Produits liés')),
                  content: SizedBox(
                    width: 420,
                    height: 420,
                    child: ListView(children: [
                      for (final r in _rows!.where((r) => r['id'] != p['id']))
                        CheckboxListTile(
                          dense: true,
                          value: chosen.contains('${r['id']}'),
                          title: Text(tr('${r['name']}')),
                          subtitle: Text(tr('${r['category'] ?? 'Sans catégorie'} · ${money(toDouble(r['price']), cur)}')),
                          onChanged: chosen.length >= 8 && !chosen.contains('${r['id']}') ? null : (v) => rset(() => v == true ? chosen.add('${r['id']}') : chosen.remove('${r['id']}')),
                        ),
                    ]),
                  ),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(rc), child: Text(tr('Annuler'))),
                    FilledButton(onPressed: () {
                      set(() => related = chosen);
                      Navigator.pop(rc);
                    }, child: Text(tr('Valider'))),
                  ],
                )),
          );
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(children: [
                Text(tr('Photos'), style: TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(width: 8),
                Text(tr('${photos.length}/10'), style: TextStyle(color: Theme.of(dc).hintColor)),
                const Spacer(),
                if (busy) const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
                TextButton.icon(onPressed: busy || photos.length >= 10 ? null : addPhotos, icon: const Icon(Icons.add_photo_alternate_outlined), label: Text(tr('Ajouter'))),
              ]),
              if (photos.isEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Text(tr('Sans photo, la fiche utilise l\'image de l\'article. Ajoutez plusieurs vues : face, dos, détail, porté…'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12.5)),
                ),
              if (photos.isNotEmpty)
                SizedBox(
                  height: 196,
                  child: ListView(scrollDirection: Axis.horizontal, children: [
                    for (final (i, ph) in photos.indexed)
                      Container(
                        width: 140,
                        margin: const EdgeInsets.only(right: 10, top: 6),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                          Stack(children: [
                            ClipRRect(borderRadius: BorderRadius.circular(10), child: SizedBox(height: 120, child: Image.network('${ph['url']}', fit: BoxFit.cover, width: 140))),
                            if (i == 0) Positioned(left: 6, top: 6, child: StatusChip(tr('Principale'), color: YColors.blue)),
                            Positioned(
                              right: 0,
                              top: 0,
                              child: Row(children: [
                                if (i > 0)
                                  IconButton(
                                    tooltip: tr('Avancer'),
                                    visualDensity: VisualDensity.compact,
                                    style: IconButton.styleFrom(backgroundColor: Colors.white70),
                                    onPressed: busy ? null : () {
                                      final order = [for (final x in photos) '${x['path']}'];
                                      order.insert(i - 1, order.removeAt(i));
                                      run(() => api.post('sites/shop/products/${p['id']}', {'photo_order': order}));
                                    },
                                    icon: const Icon(Icons.arrow_back, size: 16),
                                  ),
                                IconButton(
                                  tooltip: tr('Retirer'),
                                  visualDensity: VisualDensity.compact,
                                  style: IconButton.styleFrom(backgroundColor: Colors.white70),
                                  onPressed: busy ? null : () => run(() => api.post('sites/shop/products/${p['id']}/photos/remove', {'path': ph['path']})),
                                  icon: const Icon(Icons.close, size: 16, color: YColors.danger),
                                ),
                              ]),
                            ),
                          ]),
                          if (values.isNotEmpty)
                            DropdownButton<String>(
                              isExpanded: true,
                              isDense: true,
                              value: values.contains(ph['tag']) ? '${ph['tag']}' : '',
                              items: [
                                DropdownMenuItem(value: '', child: Text(tr('Toutes variantes'), style: TextStyle(fontSize: 12.5))),
                                for (final v in values) DropdownMenuItem(value: v, child: Text(tr(v), style: const TextStyle(fontSize: 12.5))),
                              ],
                              onChanged: busy ? null : (v) => run(() => api.post('sites/shop/products/${p['id']}', {'photo_tags': {'${ph['path']}': v ?? ''}})),
                            ),
                        ]),
                      ),
                  ]),
                ),
              if (values.isNotEmpty)
                Text(tr('Étiquetez une photo avec une valeur (ex. Noir) : elle s\'affiche quand le client choisit cette variante.'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12)),
              const Divider(height: 26),
              Row(children: [
                Expanded(child: TextField(controller: badge, maxLength: 28, decoration: InputDecoration(labelText: tr('Badge (ex. Nouveau, Best-seller)'), counterText: ''))),
                const SizedBox(width: 12),
                Expanded(child: TextField(controller: compare, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Prix barré TTC (facultatif)'), helperText: tr('Prix actuel : ${money(toDouble(p['price']), cur)}')))),
              ]),
              const SizedBox(height: 12),
              TextField(
                controller: desc,
                minLines: 6,
                maxLines: 14,
                decoration: InputDecoration(labelText: tr('Description détaillée'), alignLabelWithHint: true, hintText: tr('## Matière\n100 % coton.\n\n## Entretien\n- Lavage à 30 °C\n- Repassage doux')),
              ),
              const SizedBox(height: 4),
              Text(tr('Mise en forme : ## intertitre, **gras**, « - » pour une liste. Vide : la description de l\'article est reprise.'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12)),
              const Divider(height: 26),
              Row(children: [
                Expanded(child: Text(tr('Vous aimerez aussi'), style: TextStyle(fontWeight: FontWeight.w800))),
                TextButton.icon(onPressed: pickRelated, icon: const Icon(Icons.playlist_add), label: Text(tr('Choisir'))),
              ]),
              if (related.isEmpty)
                Text(tr('Automatique : produits de la même catégorie.'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12.5))
              else
                Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final r in related) InputChip(label: Text(tr(names[r] ?? 'Produit')), onDeleted: () => set(() => related.remove(r))),
                ]),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton.icon(onPressed: () => openUrl('${p['url']}'), icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Voir la fiche sur le site'))),
                TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Fermer'))),
                FilledButton(
                  onPressed: busy
                      ? null
                      : () async {
                          final ok = await run(() => api.post('sites/shop/products/${p['id']}', {
                                'description': desc.text, 'badge': badge.text.trim(), 'compare_at': toDouble(compare.text), 'related': related,
                              }));
                          if (!ok) return;
                          if (dc.mounted) Navigator.pop(dc);
                          if (mounted) toast(context, tr('Fiche « ${p['name']} » enregistrée.'));
                        },
                  child: Text(tr('Enregistrer')),
                ),
              ]),
            ),
          ]),
        );
      }));
    await _load();
  }
}
