import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/report_charts.dart';
import 'sites_screen.dart' show openUrl;
import 'sites_shop.dart' show shopStatusColor;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _channelIcons = {
  'site': Icons.language,
  'whatsapp': Icons.chat_outlined,
  'facebook': Icons.facebook,
  'instagram': Icons.camera_alt_outlined,
  'tiktok': Icons.music_note_outlined,
  'telephone': Icons.phone_outlined,
  'boutique': Icons.storefront_outlined,
};

/// Boutique en ligne : commandes du site et des réseaux (WhatsApp, Facebook…), préparation, expédition,
/// encaissements, statistiques, flux catalogue et réglages.
class EcommerceScreen extends StatefulWidget {
  const EcommerceScreen({super.key});

  @override
  State<EcommerceScreen> createState() => _EcommerceScreenState();
}

class _EcommerceScreenState extends State<EcommerceScreen> with SingleTickerProviderStateMixin {
  late final Session _s = context.read<Session>();
  late final bool _manage = _s.can('ecommerce.manage');
  late final bool _settings = _s.can('ecommerce.settings');
  late final TabController _tabs = TabController(length: 2 + (_settings ? 1 : 0), vsync: this);
  Map<String, dynamic>? _ov;
  Object? _error;
  final _ordersKey = GlobalKey<_OrdersState>();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await _s.api.get('ecommerce/overview');
      if (mounted) setState(() => (_ov = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _refreshAll() async {
    await _load();
    await _ordersKey.currentState?._load();
  }

  @override
  Widget build(BuildContext context) {
    final ov = _ov;
    final site = ov?['site'] as Map?;
    return Column(children: [
      PageHeader(
        title: tr('Boutique en ligne'),
        subtitle: tr('Commandes du site, de WhatsApp et des réseaux · préparation, expédition, encaissement · catalogue Facebook et Google'),
        actions: [
          if (site != null && ov?['shop_enabled'] == true)
            OutlinedButton.icon(onPressed: () => openUrl('${site['shop_url']}'), icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Voir la boutique'))),
          if (_manage && site != null)
            FilledButton.icon(onPressed: () => _newOrder(ov!), icon: const Icon(Icons.add_shopping_cart, size: 18), label: Text(tr('Commande WhatsApp / téléphone'))),
        ],
      ),
      if (_error != null && ov == null)
        Expanded(child: OnlineOnlyNotice(error: _error!, onRetry: _load))
      else if (ov == null)
        const Expanded(child: LoadingSkeleton())
      else if (site == null)
        Expanded(
          child: EmptyState(
            icon: Icons.storefront_outlined,
            title: tr('Pas encore de site'),
            message: tr('La boutique en ligne s\'appuie sur votre site : choisissez un modèle dans « Site web », publiez-le et ouvrez la boutique dans son onglet Boutique.'),
          ),
        )
      else ...[
        if (ov['shop_enabled'] != true)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Card(
              color: noticeFill(context), shape: noticeShape(context),
              child: ListTile(
                leading: Icon(Icons.info_outline, color: YColors.warning),
                title: Text(tr('La boutique du site est fermée')),
                subtitle: Text(tr('Les commandes reçues sur WhatsApp, Facebook ou au téléphone peuvent être saisies ici. Ouvrez la boutique dans Site web › Boutique pour vendre sur le site.')),
              ),
            ),
          ),
        _Kpis(ov: ov),
        TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
          Tab(text: tr('Commandes${toDouble((ov['counts'] as Map?)?['new']) > 0 ? ' (${(ov['counts'] as Map)['new']} nouvelles)' : ''}')),
          Tab(text: tr('Statistiques')),
          if (_settings) Tab(text: tr('Canaux & réglages')),
        ]),
        Expanded(
          child: TabBarView(controller: _tabs, children: [
            _Orders(key: _ordersKey, ov: ov, manage: _manage, onChanged: _load),
            const _Stats(),
            if (_settings) _Settings(ov: ov, onSaved: _load),
          ]),
        ),
      ],
    ]);
  }

  Future<void> _newOrder(Map<String, dynamic> ov) async {
    final ok = await showFormDialog<bool>(context, title: 'Nouvelle commande', builder: (_) => _NewOrderDialog(ov: ov));
    if (ok == true) {
      await _refreshAll();
      if (mounted) toast(context, tr('Commande enregistrée : elle est dans les Ventes et le stock est réservé.'));
    }
  }
}

class _Kpis extends StatelessWidget {
  const _Kpis({required this.ov});

  final Map<String, dynamic> ov;

  @override
  Widget build(BuildContext context) {
    final st = asMap(ov['stats']);
    final counts = asMap(ov['counts']);
    final cur = context.watch<Session>().currency;
    final open = ['new', 'confirmed', 'preparing', 'shipped'].fold<double>(0, (a, k) => a + toDouble(counts[k]));
    Widget kpi(String l, String v, IconData ic, Color c, {String? sub}) => KpiBox(width: 236, child: StatTile(label: l, value: v, icon: ic, color: c, detail: sub));
    return Padding(
      padding: EdgeInsets.fromLTRB(MediaQuery.sizeOf(context).width < 700 ? 16 : 24, 0, MediaQuery.sizeOf(context).width < 700 ? 16 : 24, 6),
      child: KpiWrap(minTile: 200, children: [
          kpi('Commandes à traiter', number(open), Icons.inventory_2_outlined, YColors.blue, sub: '${number(toDouble(counts['new']))} nouvelle(s)'),
          kpi('Ventes du mois', money(toDouble(st['revenue']), cur), Icons.payments_outlined, YColors.success, sub: '${st['orders'] ?? 0} commande(s)'),
          kpi('Panier moyen', money(toDouble(st['average_basket']), cur), Icons.shopping_basket_outlined, YColors.night),
          kpi('À encaisser', money(toDouble(st['to_collect']), cur), Icons.account_balance_wallet_outlined, YColors.danger),
          kpi('Conversion des paniers', st['conversion'] == null ? '—' : '${number(toDouble(st['conversion']), decimals: 1)} %', Icons.trending_up, const Color(0xFF8E44AD),
              sub: '${st['carts_recovered'] ?? 0} récupéré(s) par relance'),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Commandes
// ---------------------------------------------------------------------------

class _Orders extends StatefulWidget {
  const _Orders({super.key, required this.ov, required this.manage, required this.onChanged});

  final Map<String, dynamic> ov;
  final bool manage;
  final Future<void> Function() onChanged;

  @override
  State<_Orders> createState() => _OrdersState();
}

class _OrdersState extends State<_Orders> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  // '' = à traiter (statut serveur 'open'), 'all' = toutes
  String _status = '';
  String? _channel;
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _rk = 0;
  Timer? _debounce;
  final Set<int> _sel = {};

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }
  bool _busy = false;

  Session get _s => context.read<Session>();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await _s.api.get('ecommerce/orders', query: {'status': _status.isEmpty ? 'open' : _status, if (_channel != null) 'channel': _channel, if (_q.isNotEmpty) 'q': _q});
      if (mounted) {
        setState(() {
          _rows = [for (final o in r['data'] as List) asMap(o)];
          _sel.removeWhere((id) => !_rows!.any((o) => o['id'] == id));
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<Map<String, dynamic>?> _act(Map<String, dynamic> o, String action, [Map<String, dynamic>? body, String? done]) async {
    setState(() => _busy = true);
    try {
      final r = await _s.api.post('ecommerce/orders/${o['id']}/$action', body ?? {});
      await Future.wait([_load(), widget.onChanged()]);
      if (mounted) toast(context, done ?? 'Commande ${o['number']} mise à jour.');
      return asMap(r['data']);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return null;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _picking() async {
    try {
      final bytes = await _s.api.download('ecommerce/picking', query: {'ids': _sel.join(','), if (widget.manage) 'mark': '1'});
      await Printing.layoutPdf(name: 'Bon de préparation', onLayout: (_) async => Uint8List.fromList(bytes));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    final counts = asMap(widget.ov['counts']);
    final statuses = asMap(widget.ov['statuses']);
    final channels = asMap(widget.ov['channels']);
    final filters = {'': 'À traiter', for (final e in statuses.entries) e.key: '${e.value}', 'all': 'Toutes'};
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final all = rows ?? const <Map<String, dynamic>>[];
    final shown = all
        .where((o) => _f['pay'] == null || (o['payment_status'] == 'paid') == (_f['pay'] == 'paid'))
        .where((o) => _f['delivery'] == null || '${o['delivery_mode'] ?? ''}' == _f['delivery'])
        .where((o) => _f['alert'] == null || (_f['alert'] == 'late' ? o['late'] == true : (o['shortages'] as List? ?? const []).isNotEmpty))
        .where((o) => _period == null || _period!.contains(DateTime.tryParse('${o['created_at'] ?? ''}')?.toLocal()))
        .toList();
    return RefreshIndicator(
      onRefresh: () async => await Future.wait([_load(), widget.onChanged()]),
      child: ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          key: ValueKey(_rk),
          padding: const EdgeInsets.only(bottom: 10),
          onSearch: (v) {
            _q = v.trim();
            _debounce?.cancel();
            _debounce = Timer(const Duration(milliseconds: 450), _load);
          },
          search: _q,
          searchHint: 'N°, client ou téléphone',
          statuses: filters,
          status: _status,
          onStatus: (v) {
            setState(() => (_status = v, _rows = null));
            _load();
          },
          statusCounts: {
            for (final k in filters.keys)
              if (toDouble(counts[k.isEmpty ? 'open' : k]) > 0) k: toDouble(counts[k.isEmpty ? 'open' : k]).toInt(),
          },
          filters: [
            FilterSpec('channel', 'Canal', {for (final e in channels.entries) e.key: '${e.value}'}, icon: Icons.storefront_outlined),
            const FilterSpec('pay', 'Paiement', {'paid': 'Payée', 'unpaid': 'À encaisser'}, icon: Icons.payments_outlined),
            FilterSpec('delivery', 'Mode de livraison', filterOptions(all, 'delivery_mode'), icon: Icons.local_shipping_outlined),
            const FilterSpec('alert', 'Alerte', {'late': 'En attente (retard)', 'stock': 'Stock insuffisant'}, icon: Icons.warning_amber_rounded),
          ],
          values: {..._f, 'channel': _channel},
          onFilter: (k, v) {
            if (k == 'channel') {
              if (mounted) setState(() => _channel = v);
              _load();
            } else {
              if (mounted) setState(() => _f = {..._f, k: v});
            }
          },
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows == null ? null : shown.length,
          countLabel: 'commande',
          onReset: () {
            setState(() => (_q = '', _status = '', _channel = null, _f = {}, _period = null, _rk++, _rows = null));
            _load();
          },
        ),
        Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
          if (_sel.isNotEmpty) ...[
            FilledButton.icon(onPressed: _picking, icon: const Icon(Icons.print_outlined, size: 18), label: Text(tr('Bon de préparation (${_sel.length})'))),
            TextButton(onPressed: () => setState(_sel.clear), child: Text(tr('Désélectionner'))),
          ] else if (rows != null && shown.any((o) => ['new', 'confirmed', 'preparing'].contains(o['status'])))
            TextButton.icon(
              onPressed: () => setState(() => _sel.addAll(shown.where((o) => ['new', 'confirmed', 'preparing'].contains(o['status'])).map((o) => o['id'] as int))),
              icon: const Icon(Icons.checklist, size: 18),
              label: Text(tr('Sélectionner les commandes à préparer')),
            ),
        ]),
        const SizedBox(height: 10),
        if (_error != null && rows == null)
          OnlineOnlyNotice(error: _error!, onRetry: _load)
        else if (rows == null)
          const LoadingSkeleton()
        else if (rows.isEmpty)
          EmptyState(icon: Icons.receipt_long_outlined, title: tr('Aucune commande'), message: tr('Les commandes du site arrivent ici ; saisissez celles reçues sur WhatsApp, Facebook ou au téléphone avec le bouton en haut.'))
        else if (shown.isEmpty)
          EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune commande ne correspond aux filtres'))
        else
          for (final (i, o) in shown.indexed)
            AnimatedFadeIn(
              index: i % 10,
              child: Card(
                child: InkWell(
                  borderRadius: BorderRadius.circular(16),
                  onTap: () => _detail(o),
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(6, 10, 14, 10),
                    child: Row(children: [
                      if (['new', 'confirmed', 'preparing'].contains(o['status']))
                        SizedBox(width: 44, child: Checkbox(value: _sel.contains(o['id']), onChanged: (v) => setState(() => v == true ? _sel.add(o['id'] as int) : _sel.remove(o['id']))))
                      else
                        const SizedBox(width: 44),
                      CircleAvatar(
                        backgroundColor: shopStatusColor('${o['status']}').withValues(alpha: .12),
                        child: Icon(_channelIcons[o['channel']] ?? Icons.shopping_bag_outlined, color: shopStatusColor('${o['status']}'), size: 20),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr('${o['number']} · ${o['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                          Text(tr('${o['channel_label']} · ${(o['items'] as List).length} article(s) · ${o['delivery_mode']} · ${dateTimeFr(o['created_at'])}'), style: TextStyle(color: hint, fontSize: 12.5)),
                          if ((o['shortages'] as List).isNotEmpty)
                            Text(tr('Stock insuffisant : ${(o['shortages'] as List).map((x) => '${(x as Map)['name']} (${number(toDouble(x['available']))}/${number(toDouble(x['needed']))})').join(', ')}'),
                                style: const TextStyle(color: YColors.danger, fontSize: 12.5, fontWeight: FontWeight.w600)),
                          if (o['delivery'] != null)
                            Text(tr('Livraison ${o['delivery']['number']} · ${o['delivery']['status_label']}${o['delivery']['driver'] != null ? ' · ${o['delivery']['driver']}' : ''}'), style: TextStyle(color: hint, fontSize: 12.5)),
                        ]),
                      ),
                      Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                        Text(tr(money(toDouble(o['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w800)),
                        const SizedBox(height: 4),
                        Wrap(spacing: 6, runSpacing: 4, alignment: WrapAlignment.end, children: [
                          if (o['late'] == true) StatusChip(tr('En attente'), color: YColors.danger, icon: Icons.schedule),
                          StatusChip(o['payment_status'] == 'paid' ? 'Payée' : 'À encaisser', color: o['payment_status'] == 'paid' ? YColors.success : YColors.warning),
                          StatusChip('${o['status_label']}', color: shopStatusColor('${o['status']}')),
                        ]),
                      ]),
                    ]),
                  ),
                ),
              ),
            ),
      ]),
    );
  }

  Future<void> _detail(Map<String, dynamic> o) => showModalBottomSheet(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (c) {
          final cur = c.read<Session>().currency;
          final hint = Theme.of(c).hintColor;
          final st = '${o['status']}';
          final open = !['delivered', 'cancelled'].contains(st);
          void run(Future<void> Function() f) {
            Navigator.pop(c);
            f();
          }

          return SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Icon(_channelIcons[o['channel']] ?? Icons.shopping_bag_outlined, color: hint),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr('Commande ${o['number']}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
                  StatusChip('${o['status_label']}', color: shopStatusColor(st)),
                ]),
                const SizedBox(height: 4),
                Text(tr('${o['channel_label']} · ${dateTimeFr(o['created_at'])}'), style: TextStyle(color: hint)),
                const SizedBox(height: 8),
                Text(tr('${o['name']} · ${o['phone']}${o['email'] != null ? ' · ${o['email']}' : ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                Text(tr(o['address'] != null ? '${o['delivery_mode']} : ${o['address']}' : '${o['delivery_mode']}')),
                const Divider(height: 22),
                for (final it in o['items'] as List)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 3),
                    child: Row(children: [Expanded(child: Text(tr('${number(toDouble((it as Map)['quantity']))} × ${it['name']}'))), Text(tr(money(toDouble(it['total']), cur)))]),
                  ),
                if (toDouble(o['discount']) > 0)
                  Row(children: [Expanded(child: Text(tr('Code promo ${o['coupon_code']}'), style: const TextStyle(color: YColors.success))), Text(tr('−${money(toDouble(o['discount']), cur)}'), style: const TextStyle(color: YColors.success))]),
                if (toDouble(o['delivery_fee']) > 0) Row(children: [Expanded(child: Text(tr('Livraison'))), Text(tr(money(toDouble(o['delivery_fee']), cur)))]),
                const SizedBox(height: 6),
                Row(children: [Expanded(child: Text(tr('Total TTC'), style: TextStyle(fontWeight: FontWeight.w700))), Text(tr(money(toDouble(o['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17))]),
                if (toDouble(o['to_collect']) > 0)
                  Row(children: [Expanded(child: Text(tr('Reste à encaisser'), style: TextStyle(color: YColors.danger))), Text(tr(money(toDouble(o['to_collect']), cur)), style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w700))]),
                const SizedBox(height: 10),
                Wrap(spacing: 8, runSpacing: 6, children: [
                  StatusChip('${o['payment_label']}', icon: Icons.payments_outlined),
                  if (o['payment_ref'] != null) StatusChip(tr('Réf. ${o['payment_ref']}'), color: YColors.success),
                  if (o['document'] != null) StatusChip('${o['document']['type'] == 'invoice' ? 'Facture' : 'Commande'} ${o['document']['number'] ?? ''}', icon: Icons.description_outlined),
                  if (o['invoice'] != null && o['invoice']['id'] != o['document']?['id']) StatusChip(tr('Facture ${o['invoice']['number']}'), icon: Icons.receipt_outlined, color: YColors.blue),
                  if (o['delivery'] != null) StatusChip(tr('Livraison ${o['delivery']['number']} · ${o['delivery']['status_label']}'), icon: Icons.local_shipping_outlined, color: const Color(0xFF8E44AD)),
                ]),
                if ((o['shortages'] as List).isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Text(tr('Stock insuffisant pour : ${(o['shortages'] as List).map((x) => '${(x as Map)['name']} (disponible ${number(toDouble(x['available']))} sur ${number(toDouble(x['needed']))})').join(', ')}'),
                        style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w600)),
                  ),
                if (o['note'] != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('Note du client : ${o['note']}'))),
                if (o['staff_note'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Note interne : ${o['staff_note']}'), style: TextStyle(color: hint))),
                const SizedBox(height: 16),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  if (widget.manage && open && !_busy) ...[
                    if (st == 'new') FilledButton.icon(onPressed: () => run(() => _act(o, 'confirm', null, 'Commande ${o['number']} confirmée.')), icon: const Icon(Icons.check), label: Text(tr('Confirmer'))),
                    if (st == 'new' || st == 'confirmed')
                      OutlinedButton.icon(onPressed: () => run(() => _act(o, 'prepare', null, 'Commande ${o['number']} en préparation.')), icon: const Icon(Icons.inventory_2_outlined), label: Text(tr('En préparation'))),
                    if (['new', 'confirmed', 'preparing'].contains(st))
                      FilledButton.icon(
                        onPressed: () => run(() => _ship(o)),
                        icon: Icon(o['pickup'] == true ? Icons.storefront_outlined : Icons.local_shipping_outlined),
                        label: Text(tr(o['pickup'] == true ? 'Prête au retrait' : 'Expédier')),
                      ),
                    if (o['payment_status'] != 'paid') OutlinedButton.icon(onPressed: () => run(() => _pay(o)), icon: const Icon(Icons.payments_outlined), label: Text(tr('Encaisser'))),
                    if (st == 'shipped' || o['pickup'] == true)
                      FilledButton.tonalIcon(onPressed: () => run(() => _deliver(o)), icon: const Icon(Icons.task_alt), label: Text(tr(o['pickup'] == true ? 'Remise au client' : 'Livrée'))),
                  ],
                  if (o['whatsapp_url'] != null) OutlinedButton.icon(onPressed: () => openUrl('${o['whatsapp_url']}'), icon: const Icon(Icons.chat_outlined), label: Text(tr('WhatsApp'))),
                  if (o['track_url'] != null)
                    TextButton.icon(
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: '${o['track_url']}'));
                        toast(c, tr('Lien de suivi copié.'));
                      },
                      icon: const Icon(Icons.link),
                      label: Text(tr('Lien de suivi')),
                    ),
                  if (o['pay_url'] != null)
                    TextButton.icon(onPressed: () => Clipboard.setData(ClipboardData(text: '${o['pay_url']}')), icon: const Icon(Icons.credit_card), label: Text(tr('Lien de paiement'))),
                  if (widget.manage) TextButton.icon(onPressed: () => run(() => _note(o)), icon: const Icon(Icons.sticky_note_2_outlined), label: Text(tr('Note interne'))),
                  if (widget.manage && open && o['payment_status'] != 'paid' && st != 'shipped')
                    TextButton(style: TextButton.styleFrom(foregroundColor: YColors.danger), onPressed: () => run(() => _cancel(o)), child: Text(tr('Annuler'))),
                ]),
              ]),
            ),
          );
        },
      );

  Future<void> _ship(Map<String, dynamic> o) async {
    final pickup = o['pickup'] == true;
    final logi = widget.ov['logistique'] == true && !pickup;
    final drivers = [for (final d in (widget.ov['drivers'] as List? ?? [])) asMap(d)];
    var delivery = (widget.ov['settings'] as Map?)?['auto_delivery'] != false;
    int? driver;
    var date = DateTime.now();
    var packages = 1;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr(pickup ? 'Commande ${o['number']} prête au retrait' : 'Expédier ${o['number']}')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(o['invoice'] == null ? 'La facture est créée maintenant : le stock sort et la vente entre dans la comptabilité.' : 'Facture ${o['invoice']['number']} déjà émise.'),
                  style: TextStyle(color: Theme.of(c).hintColor)),
              if (pickup) Padding(padding: EdgeInsets.only(top: 8), child: Text(tr('Le client est prévenu que sa commande l\'attend en boutique.'))),
              if (logi) ...[
                const SizedBox(height: 8),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: delivery,
                  onChanged: (v) => set(() => delivery = v),
                  title: Text(tr('Créer le bon de livraison')),
                  subtitle: Text(tr(o['payment_status'] == 'paid' ? 'Commande payée : rien à encaisser.' : 'Le livreur encaisse ${money(toDouble(o['to_collect']), c.read<Session>().currency)} à la livraison.')),
                ),
                if (delivery) ...[
                  DropdownButtonFormField<int?>(
                    initialValue: driver,
                    decoration: InputDecoration(labelText: tr('Livreur')),
                    items: [DropdownMenuItem(value: null, child: Text(tr('À attribuer plus tard'))), for (final d in drivers) DropdownMenuItem(value: d['id'] as int, child: Text(tr('${d['name']}')))],
                    onChanged: (v) => set(() => driver = v),
                  ),
                  const SizedBox(height: 8),
                  Row(children: [
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: () async {
                          final d = await pickDate(c, initialDate: date, firstDate: DateTime.now().subtract(const Duration(days: 1)), lastDate: DateTime.now().add(const Duration(days: 60)));
                          if (d != null) set(() => date = d);
                        },
                        icon: const Icon(Icons.event, size: 18),
                        label: Text(tr('Livraison le ${dateFr(isoDate(date))}')),
                      ),
                    ),
                    const SizedBox(width: 12),
                    SizedBox(
                      width: 110,
                      child: TextFormField(
                        initialValue: '1',
                        keyboardType: TextInputType.number,
                        decoration: InputDecoration(labelText: tr('Colis')),
                        onChanged: (v) => packages = int.tryParse(v) ?? 1,
                      ),
                    ),
                  ]),
                ],
              ],
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr(pickup ? 'Valider' : 'Expédier'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await _act(o, 'ship', {if (logi) 'delivery': delivery, if (logi && delivery) ...{'driver_id': driver, 'date': isoDate(date), 'packages': packages}},
        pickup ? 'Commande ${o['number']} prête : le client est prévenu.' : 'Commande ${o['number']} expédiée.');
  }

  Future<void> _pay(Map<String, dynamic> o) async {
    final methods = asMap(widget.ov['methods']);
    var method = o['payment_mode'] == 'cod' ? 'cash' : 'mobile_money';
    final ref = TextEditingController();
    final amount = TextEditingController(text: number(toDouble(o['to_collect'])).replaceAll(' ', ' '));
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Encaisser ${o['number']}')),
          content: SizedBox(
            width: 400,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              DropdownButtonFormField<String>(
                initialValue: method,
                decoration: InputDecoration(labelText: tr('Moyen de paiement')),
                items: [for (final e in methods.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                onChanged: (v) => set(() => method = v ?? method),
              ),
              const SizedBox(height: 8),
              TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant reçu'))),
              const SizedBox(height: 8),
              TextField(controller: ref, decoration: InputDecoration(labelText: trn(method == 'mobile_money' ? 'N° de transaction (Orange, MTN, Moov, Wave…)' : 'Référence (facultatif)'))),
              const SizedBox(height: 8),
              Text(tr('Le règlement est enregistré sur la facture (créée au besoin) et dans la trésorerie.'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await _act(o, 'pay', {'method': method, if (ref.text.trim().isNotEmpty) 'reference': ref.text.trim(), if (toDouble(amount.text) > 0) 'amount': toDouble(amount.text)}, 'Encaissement enregistré.');
  }

  Future<void> _deliver(Map<String, dynamic> o) async {
    final methods = asMap(widget.ov['methods']);
    final unpaid = o['payment_status'] != 'paid';
    var collected = unpaid;
    var method = 'cash';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr(o['pickup'] == true ? 'Remise au client — ${o['number']}' : 'Livraison effectuée — ${o['number']}')),
          content: SizedBox(
            width: 400,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              if (unpaid) ...[
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: collected,
                  onChanged: (v) => set(() => collected = v),
                  title: Text(tr('${money(toDouble(o['to_collect']), c.read<Session>().currency)} encaissés')),
                ),
                if (collected)
                  DropdownButtonFormField<String>(
                    initialValue: method,
                    decoration: InputDecoration(labelText: tr('Moyen de paiement')),
                    items: [for (final e in methods.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                    onChanged: (v) => set(() => method = v ?? method),
                  ),
              ] else
                Text(tr('Commande déjà payée.')),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Valider'))),
          ],
        ),
      ),
    );
    if (ok != true) return;
    await _act(o, 'deliver', {'collected': collected, 'method': method}, 'Commande ${o['number']} livrée.');
  }

  Future<void> _cancel(Map<String, dynamic> o) async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Annuler la commande ${o['number']} ?')),
        content: SizedBox(
          width: 400,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(tr('Le document de vente est annulé et le stock réservé est libéré.')),
            const SizedBox(height: 8),
            TextField(controller: reason, decoration: InputDecoration(labelText: tr('Motif (facultatif)'))),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Retour'))),
          FilledButton(style: FilledButton.styleFrom(backgroundColor: YColors.danger), onPressed: () => Navigator.pop(c, true), child: Text(tr('Annuler la commande'))),
        ],
      ),
    );
    if (ok == true) await _act(o, 'cancel', {if (reason.text.trim().isNotEmpty) 'reason': reason.text.trim()}, 'Commande ${o['number']} annulée.');
  }

  Future<void> _note(Map<String, dynamic> o) async {
    final t = TextEditingController(text: '${o['staff_note'] ?? ''}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Note interne')),
        content: SizedBox(width: 420, child: TextField(controller: t, maxLines: 4, decoration: InputDecoration(hintText: tr('Visible seulement par l\'équipe')))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
        ],
      ),
    );
    if (ok == true) await _act(o, 'note', {'note': t.text.trim()}, 'Note enregistrée.');
  }
}

// ---------------------------------------------------------------------------
// Commande saisie (WhatsApp, Facebook, téléphone…)
// ---------------------------------------------------------------------------

class _NewOrderDialog extends StatefulWidget {
  const _NewOrderDialog({required this.ov});

  final Map<String, dynamic> ov;

  @override
  State<_NewOrderDialog> createState() => _NewOrderDialogState();
}

class _NewOrderDialogState extends State<_NewOrderDialog> {
  String _channel = 'whatsapp';
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  final _note = TextEditingController();
  late String _delivery = widget.ov['pickup'] == true ? 'pickup' : '${((widget.ov['zones'] as List?)?.firstOrNull as Map?)?['name'] ?? 'pickup'}';
  late String _payment = (widget.ov['payments'] as Map).containsKey('cod') ? 'cod' : '${(widget.ov['payments'] as Map).keys.firstOrNull ?? 'cod'}';
  bool _confirm = true;
  bool _busy = false;
  final Map<String, int> _items = {};
  late final List<Map<String, dynamic>> _products = (LocalDb.hasEntity('products') ? LocalDb.all('products') : <Map<String, dynamic>>[])
      .where((p) => p['active'] != false && p['is_template'] != true && toDouble(p['sale_price']) > 0 && p['deleted_at'] == null)
      .map((p) => Map<String, dynamic>.from(p))
      .toList()
    ..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));

  double _ttc(Map<String, dynamic> p) => (toDouble(p['sale_price']) * (1 + toDouble(p['tax_rate']) / 100)).roundToDouble();

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('ecommerce/orders', {
        'channel': _channel, 'name': _name.text.trim(), 'phone': _phone.text.trim(), 'address': _address.text.trim(), 'delivery': _delivery, 'payment': _payment,
        'note': _note.text.trim(), 'confirm': _confirm,
        'items': [for (final e in _items.entries) {'id': e.key, 'quantity': e.value}],
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
    final cur = context.watch<Session>().currency;
    final channels = asMap(widget.ov['channels'])..remove('site');
    final zones = [for (final z in (widget.ov['zones'] as List? ?? [])) asMap(z)];
    final payments = asMap(widget.ov['payments']);
    final byId = {for (final p in _products) '${p['id']}': p};
    final subtotal = _items.entries.fold<double>(0, (a, e) => a + (byId[e.key] == null ? 0 : _ttc(byId[e.key]!) * e.value));
    final fee = _delivery == 'pickup' ? 0.0 : toDouble(zones.firstWhere((z) => z['name'] == _delivery, orElse: () => {})['fee']);
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final e in channels.entries)
              ChoiceChip(avatar: Icon(_channelIcons[e.key], size: 16), label: Text(tr('${e.value}')), selected: _channel == e.key, onSelected: (_) => setState(() => _channel = e.key)),
          ]),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: TextField(controller: _name, decoration: InputDecoration(labelText: tr('Client *')))),
            const SizedBox(width: 12),
            Expanded(child: TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone *')))),
          ]),
          const SizedBox(height: 12),
          Autocomplete<Map<String, dynamic>>(
            displayStringForOption: (p) => '${p['name']}',
            optionsBuilder: (v) => v.text.trim().isEmpty
                ? const Iterable.empty()
                : _products.where((p) => '${p['name']} ${p['sku'] ?? ''} ${p['barcode'] ?? ''}'.toLowerCase().contains(v.text.trim().toLowerCase())).take(12),
            onSelected: (p) => setState(() => _items['${p['id']}'] = (_items['${p['id']}'] ?? 0) + 1),
            fieldViewBuilder: (c, ctl, focus, submit) => TextField(
              controller: ctl,
              focusNode: focus,
              decoration: InputDecoration(labelText: tr('Ajouter un article'), prefixIcon: Icon(Icons.search)),
              onSubmitted: (_) {
                submit();
                ctl.clear();
              },
            ),
          ),
          const SizedBox(height: 8),
          for (final e in _items.entries)
            if (byId[e.key] != null)
              Row(children: [
                Expanded(child: Text(tr('${byId[e.key]!['name']}'))),
                IconButton(onPressed: () => setState(() => e.value <= 1 ? _items.remove(e.key) : _items[e.key] = e.value - 1), icon: const Icon(Icons.remove_circle_outline, size: 20)),
                Text(tr('${e.value}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                IconButton(onPressed: () => setState(() => _items[e.key] = e.value + 1), icon: const Icon(Icons.add_circle_outline, size: 20)),
                SizedBox(width: 110, child: Text(tr(money(_ttc(byId[e.key]!) * e.value, cur)), textAlign: TextAlign.right)),
              ]),
          if (_items.isEmpty) Text(tr('Aucun article.'), style: TextStyle(color: Theme.of(context).hintColor)),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(
              child: DropdownButtonFormField<String>(
                initialValue: _delivery,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Livraison')),
                items: [
                  if (widget.ov['pickup'] == true) DropdownMenuItem(value: 'pickup', child: Text(tr('Retrait en boutique'))),
                  for (final z in zones) DropdownMenuItem(value: '${z['name']}', child: Text(tr('${z['name']} (${money(toDouble(z['fee']), cur)})'))),
                ],
                onChanged: (v) => setState(() => _delivery = v ?? _delivery),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: DropdownButtonFormField<String>(
                initialValue: payments.containsKey(_payment) ? _payment : null,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Paiement')),
                items: [for (final e in payments.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => setState(() => _payment = v ?? _payment),
              ),
            ),
          ]),
          if (_delivery != 'pickup') ...[
            const SizedBox(height: 8),
            TextField(controller: _address, decoration: InputDecoration(labelText: tr('Adresse de livraison *'))),
          ],
          const SizedBox(height: 8),
          TextField(controller: _note, decoration: InputDecoration(labelText: tr('Note (facultatif)'))),
          CheckboxListTile(contentPadding: EdgeInsets.zero, value: _confirm, onChanged: (v) => setState(() => _confirm = v ?? true), title: Text(tr('Confirmer la commande au client'))),
          const Divider(),
          Row(children: [
            Expanded(child: Text(tr('Total TTC'), style: TextStyle(fontWeight: FontWeight.w700))),
            Text(tr(money(subtotal + fee, cur)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
          ]),
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: _busy || _items.isEmpty ? null : _save, child: Text(tr('Enregistrer la commande'))),
          ]),
        ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Statistiques
// ---------------------------------------------------------------------------

class _Stats extends StatefulWidget {
  const _Stats();

  @override
  State<_Stats> createState() => _StatsState();
}

class _StatsState extends State<_Stats> {
  int _days = 30;
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final now = DateTime.now();
      final r = await context.read<Session>().api.get('ecommerce/stats', query: {'from': isoDate(now.subtract(Duration(days: _days - 1))), 'to': isoDate(now)});
      if (mounted) setState(() => (_d = r['data'] == null ? null : asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (_error != null && d == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (d == null) return const LoadingSkeleton();
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final series = [for (final x in d['series'] as List) asMap(x)];
    final top = [for (final x in d['top_products'] as List) asMap(x)];
    Widget kpi(String l, String v, {String? sub}) => KpiBox(width: 220, child: StatTile(label: l, value: v, detail: sub));
    Widget table(String title, String key) {
      final rows = [for (final x in d[key] as List) asMap(x)];
      return SizedBox(
        width: 360,
        child: SectionCard(
          title: title,
          child: rows.isEmpty
              ? Text(tr('Aucune commande.'), style: TextStyle(color: hint))
              : Column(children: [
                  for (final r in rows)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(children: [
                        Expanded(child: Text(tr('${r['label']}'), overflow: TextOverflow.ellipsis)),
                        Text(tr('${r['orders']}'), style: TextStyle(color: hint)),
                        SizedBox(width: 120, child: Text(tr(money(toDouble(r['revenue']), cur)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
                      ]),
                    ),
                ]),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Wrap(spacing: 8, children: [
          for (final n in [7, 30, 90, 365])
            ChoiceChip(
              label: Text(tr(n == 365 ? '12 mois' : '$n jours')),
              selected: _days == n,
              onSelected: (_) {
                setState(() => _days = n);
                _load();
              },
            ),
        ]),
        const SizedBox(height: 12),
        KpiWrap(children: [
          kpi('Chiffre d\'affaires TTC', money(toDouble(d['revenue']), cur), sub: '${d['orders']} commande(s)'),
          kpi('Panier moyen', money(toDouble(d['average_basket']), cur), sub: '${number(toDouble(d['items']))} article(s) vendus'),
          kpi('Clients', '${d['customers']}', sub: '${d['new_customers']} nouveau(x)'),
          kpi('Encaissé', money(toDouble(d['paid']), cur), sub: 'reste ${money(toDouble(d['to_collect']), cur)}'),
          kpi('Conversion des paniers', d['conversion'] == null ? '—' : '${number(toDouble(d['conversion']), decimals: 1)} %', sub: '${d['carts_converted']} sur ${d['carts']} panier(s)'),
          kpi('Paniers abandonnés', money(toDouble(d['carts_abandoned_value']), cur), sub: '${d['carts_recovered']} récupéré(s) par relance'),
          kpi('Délai de préparation', d['prep_hours'] == null ? '—' : '${number(toDouble(d['prep_hours']), decimals: 1)} h', sub: 'de la commande à l\'expédition'),
          kpi('Annulations', '${number(toDouble(d['cancel_rate']), decimals: 1)} %', sub: '${d['cancelled']} commande(s)'),
        ]),
        const SizedBox(height: 12),
        if (series.length > 1)
          SectionCard(
            title: tr('Ventes en ligne par jour'),
            child: SizedBox(
              height: 240,
              child: CartesianChart(
                data: ChartData([for (final x in series) dateFr(x['date']).substring(0, 5)], [ChartSeries('CA TTC', [for (final x in series) toDouble(x['revenue'])])], 'money'),
                currency: cur,
                line: series.length > 31,
              ),
            ),
          ),
        const SizedBox(height: 12),
        if (top.isNotEmpty)
          SectionCard(
            title: tr('Meilleurs articles'),
            child: SizedBox(
              height: 36.0 + 26 * top.length,
              child: HBarChart(data: ChartData([for (final x in top) '${x['name']}'], [ChartSeries('CA TTC', [for (final x in top) toDouble(x['revenue'])])], 'money'), currency: cur),
            ),
          ),
        const SizedBox(height: 12),
        Wrap(spacing: 12, runSpacing: 12, children: [
          table('Par canal', 'by_channel'),
          table('Par moyen de paiement', 'by_payment'),
          table('Par mode de livraison', 'by_delivery'),
          if ((d['coupons'] as List).isNotEmpty)
            SizedBox(
              width: 360,
              child: SectionCard(
                title: tr('Codes promo'),
                child: Column(children: [
                  for (final c in d['coupons'] as List)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4),
                      child: Row(children: [
                        Expanded(child: Text(tr('${(c as Map)['code']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                        Text(tr('${c['orders']} cde · −${money(toDouble(c['discount']), cur)}'), style: TextStyle(color: hint)),
                      ]),
                    ),
                ]),
              ),
            ),
        ]),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Canaux & réglages
// ---------------------------------------------------------------------------

class _Settings extends StatefulWidget {
  const _Settings({required this.ov, required this.onSaved});

  final Map<String, dynamic> ov;
  final Future<void> Function() onSaved;

  @override
  State<_Settings> createState() => _SettingsState();
}

class _SettingsState extends State<_Settings> {
  late Map<String, dynamic> _s = asMap(widget.ov['settings']);
  late Map<String, dynamic> _feeds = asMap(widget.ov['feeds']);
  late String? _wa = widget.ov['whatsapp_link'] as String?;
  late final _waNumber = TextEditingController(text: '${_s['whatsapp_number'] ?? ''}');
  late final _brand = TextEditingController(text: '${_s['brand'] ?? ''}');

  Future<void> _save(Map<String, dynamic> body) async {
    try {
      final r = await context.read<Session>().api.put('ecommerce/settings', body);
      final d = asMap(r['data']);
      if (mounted) setState(() => (_s = d, _feeds = asMap(d['feeds']), _wa = d['whatsapp_link'] as String?));
      await widget.onSaved();
      if (mounted) toast(context, tr('Réglages enregistrés.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Widget _copyRow(String label, String url, IconData icon) => ListTile(
        contentPadding: EdgeInsets.zero,
        leading: Icon(icon),
        title: Text(tr(label)),
        subtitle: SelectableText(tr(url), style: const TextStyle(fontSize: 12)),
        trailing: IconButton(
          tooltip: tr('Copier'),
          icon: const Icon(Icons.copy, size: 18),
          onPressed: () {
            Clipboard.setData(ClipboardData(text: url));
            toast(context, tr('Adresse copiée.'));
          },
        ),
      );

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return ListView(padding: const EdgeInsets.all(16), children: [
      SectionCard(
        title: tr('Catalogue sur Facebook, Instagram, WhatsApp et Google'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('Collez ces adresses dans le gestionnaire de catalogue Meta (Commerce Manager › Sources de données › Flux programmé) et dans Google Merchant Center. Prix, photos et disponibilité sont mis à jour automatiquement à chaque lecture.'),
              style: TextStyle(color: hint)),
          _copyRow('Meta : Facebook, Instagram, WhatsApp Business (CSV)', '${_feeds['meta']}', Icons.facebook),
          _copyRow('Google Shopping (XML)', '${_feeds['google']}', Icons.shopping_bag_outlined),
          TextButton.icon(
            onPressed: () async {
              if (!await confirm(context, tr('Changer l\'adresse des flux ?'), tr('Les anciennes adresses cessent de fonctionner : il faudra coller les nouvelles chez Meta et Google.'), ok: tr('Changer'))) return;
              if (!context.mounted) return;
              try {
                final r = await context.read<Session>().api.post('ecommerce/settings/feed-token');
                if (mounted) setState(() => _feeds = asMap((r['data'] as Map)['feeds']));
              } catch (e) {
                if (context.mounted) toast(context, errorText(e), error: true);
              }
            },
            icon: const Icon(Icons.autorenew, size: 18),
            label: Text(tr('Générer de nouvelles adresses')),
          ),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _brand, decoration: InputDecoration(labelText: tr('Marque affichée dans les flux'), hintText: tr('Par défaut : le nom de la société')))),
            const SizedBox(width: 8),
            OutlinedButton(onPressed: () => _save({'brand': _brand.text.trim()}), child: Text(tr('Enregistrer'))),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Commandes par WhatsApp'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('Le lien ouvre une discussion avec votre numéro WhatsApp Business : à mettre dans votre statut, vos publications et votre bio Instagram. Les commandes reçues se saisissent ici en quelques secondes.'),
              style: TextStyle(color: hint)),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: TextField(controller: _waNumber, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Numéro WhatsApp de la boutique')))),
            const SizedBox(width: 8),
            OutlinedButton(onPressed: () => _save({'whatsapp_number': _waNumber.text.trim()}), child: Text(tr('Enregistrer'))),
          ]),
          if (_wa != null) _copyRow('Lien « Commander sur WhatsApp »', _wa!, Icons.chat_outlined),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Traitement des commandes'),
        child: Column(children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _s['reserve_stock'] == true,
            onChanged: (v) => _save({'reserve_stock': v}),
            title: Text(tr('Réserver le stock dès la commande')),
            subtitle: Text(tr('Les articles promis aux commandes en cours ne sont plus proposés sur le site ni dans les flux, ce qui évite de vendre deux fois le dernier article.')),
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _s['auto_delivery'] == true,
            onChanged: widget.ov['logistique'] == true ? (v) => _save({'auto_delivery': v}) : null,
            title: Text(tr('Créer le bon de livraison à l\'expédition')),
            subtitle: Text(tr(widget.ov['logistique'] == true ? 'Dans le module Livraisons, avec le montant à encaisser par le livreur.' : 'Nécessite le module Logistique & Livraisons.')),
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _s['notify_customer'] == true,
            onChanged: (v) => _save({'notify_customer': v}),
            title: Text(tr('Prévenir le client à chaque étape')),
            subtitle: Text(tr('Confirmation, expédition ou retrait, livraison : par WhatsApp, SMS ou e-mail selon les canaux configurés.')),
          ),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('Alerte « en attente » après')),
            subtitle: Text(tr('Une commande nouvelle non confirmée après ce délai est signalée en rouge.')),
            trailing: DropdownButton<int>(
              value: [2, 6, 12, 24, 48].contains(_s['alert_hours']) ? _s['alert_hours'] as int : 24,
              items: [for (final h in [2, 6, 12, 24, 48]) DropdownMenuItem(value: h, child: Text(tr('$h h')))],
              onChanged: (v) => _save({'alert_hours': v}),
            ),
          ),
        ]),
      ),
    ]);
  }
}
