import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/order_notify_dialog.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';

/// Plateforme affichée (WooCommerce ou Shopify : mêmes écrans, mêmes données côté serveur).
class _Pf {
  static String key = 'woocommerce';
  static bool get shopify => key == 'shopify';
  static Color get color => shopify ? const Color(0xFF008060) : const Color(0xFF7F54B3);
  static String get label => shopify ? 'Shopify' : 'WooCommerce';
}

Color get _woo => _Pf.color;

String _ago(dynamic iso) {
  final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
  if (d == null) return 'jamais';
  final m = DateTime.now().difference(d).inMinutes;
  if (m < 1) return 'à l\'instant';
  if (m < 60) return 'il y a $m min';
  if (m < 1440) return 'il y a ${m ~/ 60} h';
  return DateFormat('d MMM à HH:mm', 'fr_FR').format(d);
}

/// Connecteur WooCommerce : boutiques, produits publiés, commandes importées, journal.
class WoocommerceScreen extends StatefulWidget {
  const WoocommerceScreen({super.key, this.platform = 'woocommerce'});

  final String platform;

  @override
  State<WoocommerceScreen> createState() => _WoocommerceScreenState();
}

class _WoocommerceScreenState extends State<WoocommerceScreen> {
  List<Map<String, dynamic>>? _stores;
  Map<String, dynamic> _meta = {};
  int? _sel;
  Object? _error;
  bool _busy = false;

  ApiClient get api => context.read<Session>().api;
  bool get canManage => context.read<Session>().can('woo.manage');

  @override
  void initState() {
    super.initState();
    _Pf.key = widget.platform;
    _load();
  }

  Future<void> _load() async {
    _Pf.key = widget.platform;
    try {
      final r = await api.get(_Pf.key);
      if (!mounted) return;
      setState(() {
        _stores = [for (final x in (r['data'] as List)) asMap(x)];
        _meta = asMap(r);
        if (_sel == null || !_stores!.any((s) => s['id'] == _sel)) _sel = _stores!.isEmpty ? null : _stores!.first['id'] as int;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Map<String, dynamic>? get _store => _stores?.firstWhere((s) => s['id'] == _sel, orElse: () => {});

  Future<void> _sync([String what = 'all']) async {
    setState(() => _busy = true);
    try {
      final r = await api.postLong('${_Pf.key}/stores/$_sel/sync', {'what': what});
      final d = asMap(r['data']);
      final p = d['products'] as Map?;
      if (mounted) {
        toast(
          context,
          [
            if (d['orders'] != null) '${d['orders']} commande(s) importée(s) ou mise(s) à jour',
            if (p != null) '${p['created']} produit(s) créé(s), ${p['updated']} mis à jour${(p['errors'] ?? 0) > 0 ? ', ${p['errors']} en erreur' : ''}',
            if (what == 'webhooks') 'Webhooks vérifiés',
          ].join(' · '),
        );
      }
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _connect() async {
    final ok = await showFormDialog<bool>(context, title: 'Connecter une boutique ${_Pf.label}', builder: (_) => const _ConnectDialog());
    if (ok == true) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final stores = _stores;
    if (stores == null) return _error != null ? Center(child: Text(tr(errorText(_error!)))) : const Center(child: CircularProgressIndicator());
    if (stores.isEmpty) return _empty();
    final s = _store!;
    return DefaultTabController(
      key: ValueKey(_sel),
      length: 3,
      child: Column(
        children: [
          _header(s, stores),
          TabBar(
            isScrollable: true,
            padding: const EdgeInsets.symmetric(horizontal: 8),
            tabAlignment: TabAlignment.start,
            labelColor: _woo,
            indicatorColor: _woo,
            tabs: [
              Tab(icon: Icon(Icons.inventory_2_outlined, size: 18), text: tr('Produits sur le site')),
              Tab(icon: Icon(Icons.shopping_bag_outlined, size: 18), text: tr('Commandes du site')),
              Tab(icon: Icon(Icons.history, size: 18), text: tr('Journal')),
            ],
          ),
          Expanded(
            child: TabBarView(
              children: [
                _ProductsTab(store: s, onChanged: _load),
                _OrdersTab(store: s, statuses: asMap(_meta['statuses'])),
                _LogsTab(storeId: s['id'] as int),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _header(Map<String, dynamic> s, List<Map<String, dynamic>> stores) {
    final hint = Theme.of(context).hintColor;
    final err = s['status'] == 'error';
    Widget kpi(String v, String l, [Color? c]) => Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          tr(v),
          style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: c ?? _woo),
        ),
        Text(tr(l), style: TextStyle(color: hint, fontSize: 12)),
      ],
    );
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 16, 14, 14),
      decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFFF3EEFA), Color(0xFFFFFFFF)])),
      child: Wrap(
        spacing: 26,
        runSpacing: 12,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(color: _woo, borderRadius: BorderRadius.circular(12)),
                child: const Icon(Icons.storefront, color: Colors.white),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (stores.length > 1)
                    DropdownButton<int>(
                      value: _sel,
                      underline: const SizedBox(),
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: Color(0xFF1F1F1F)),
                      items: [for (final x in stores) DropdownMenuItem(value: x['id'] as int, child: Text(tr('${x['name']}')))],
                      onChanged: (v) => setState(() => _sel = v),
                    )
                  else
                    Text(tr('${s['name']}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                  InkWell(
                    onTap: () => safeLaunchUrl(Uri.parse('${s['url']}'), webOnlyWindowName: '_blank'),
                    child: Text(tr('${s['url']}'.replaceFirst(RegExp('^https?://'), '')), style: TextStyle(color: _woo, fontSize: 12.5)),
                  ),
                ],
              ),
            ],
          ),
          kpi('${s['products_online']}', 'produits en ligne'),
          kpi('${s['orders_30']}', 'commandes sur 30 jours'),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    err ? Icons.error_outline : (s['status'] == 'paused' ? Icons.pause_circle_outline : Icons.check_circle),
                    size: 16,
                    color: err ? YColors.danger : (s['status'] == 'paused' ? YColors.warning : YColors.success),
                  ),
                  const SizedBox(width: 4),
                  Text(tr(err ? 'Erreur' : (s['status'] == 'paused' ? 'En pause' : 'Synchronisé')), style: const TextStyle(fontWeight: FontWeight.w700)),
                ],
              ),
              Text(tr('Commandes ${_ago(s['last_orders_sync_at'])} · stock ${_ago(s['last_products_sync_at'])}'), style: TextStyle(color: hint, fontSize: 12)),
              Text(tr(s['webhooks'] == true ? 'Commandes reçues en direct' : 'Commandes relevées toutes les 5 min'), style: TextStyle(color: hint, fontSize: 12)),
            ],
          ),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (canManage)
                FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: _woo),
                  onPressed: _busy ? null : () => _sync(),
                  icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.sync, size: 18),
                  label: Text(tr('Synchroniser')),
                ),
              if (canManage) IconButton(tooltip: tr('Réglages'), onPressed: () => _settings(s), icon: const Icon(Icons.tune)),
              if (context.read<Session>().can('ventes.manage'))
                IconButton(tooltip: tr('Notifications de commande aux clients'), onPressed: () => showOrderNotifyDialog(context), icon: const Icon(Icons.mark_email_unread_outlined)),
              if (canManage) IconButton(tooltip: tr('Connecter une autre boutique'), onPressed: _connect, icon: const Icon(Icons.add_business_outlined)),
            ],
          ),
          if (s['error'] != null) Text(tr('${s['error']}'), style: const TextStyle(color: YColors.danger, fontSize: 12.5)),
        ],
      ),
    );
  }

  Widget _empty() => Center(
    child: SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 620),
        child: Column(
          children: [
            Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(color: _woo, borderRadius: BorderRadius.circular(18)),
              child: const Icon(Icons.storefront, color: Colors.white, size: 38),
            ),
            const SizedBox(height: 16),
            Text(
              tr('Reliez votre boutique ${_Pf.label}'),
              style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              tr(
                'Vos produits, prix et stock sont publiés et tenus à jour sur le site depuis le logiciel. Chaque commande du site arrive ici en quelques secondes : client, commande, facture et encaissement.',
              ),
              textAlign: TextAlign.center,
              style: TextStyle(color: Theme.of(context).hintColor, height: 1.5),
            ),
            const SizedBox(height: 20),
            for (final t in const [
              (Icons.sell_outlined, 'Prix et stock à jour sur le site, sans ressaisie'),
              (Icons.bolt, 'Commandes importées en direct, client reconnu ou créé'),
              (Icons.receipt_long_outlined, 'Facture et encaissement des commandes payées'),
              (Icons.swap_horiz, 'Statut renvoyé au site quand vous encaissez'),
            ])
              ListTile(
                dense: true,
                leading: Icon(t.$1, color: _woo),
                title: Text(tr(t.$2)),
              ),
            const SizedBox(height: 16),
            if (canManage)
              FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: _woo, padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 16)),
                onPressed: _connect,
                icon: const Icon(Icons.link),
                label: Text(tr('Connecter ma boutique')),
              )
            else
              Text(tr('Demandez à un responsable de connecter la boutique.')),
          ],
        ),
      ),
    ),
  );

  Future<void> _settings(Map<String, dynamic> s) async {
    final changed = await showFormDialog<bool>(
      context,
      title: 'Réglages — ${s['name']}',
      builder: (_) => _SettingsDialog(store: s, statuses: asMap(_meta['statuses']), warehouses: _meta['warehouses'] as List? ?? const []),
    );
    if (changed == true) await _load();
  }
}

// ---------------------------------------------------------------------------- connexion

class _ConnectDialog extends StatefulWidget {
  const _ConnectDialog();

  @override
  State<_ConnectDialog> createState() => _ConnectDialogState();
}

class _ConnectDialogState extends State<_ConnectDialog> {
  final _url = TextEditingController(), _key = TextEditingController(), _secret = TextEditingController(), _name = TextEditingController();
  int _days = 7;
  bool _busy = false;
  // Connexion en un clic (WooCommerce) : approbation sur le site, puis l'ERP reçoit les clés
  bool _manual = _Pf.shopify;
  String? _state;
  Timer? _poll;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _oneClick() async {
    if (_url.text.trim().isEmpty) return toast(context, tr('Indiquez l\'adresse du site.'), error: true);
    setState(() => _busy = true);
    try {
      final api = context.read<Session>().api;
      final r = asMap(await api.post('woocommerce/authorize', {'url': _url.text.trim(), 'import_days': _days, if (_name.text.trim().isNotEmpty) 'name': _name.text.trim()}));
      _state = '${r['state']}';
      await safeLaunchUrl(Uri.parse('${r['url']}'), webOnlyWindowName: '_blank');
      var n = 0;
      _poll?.cancel();
      _poll = Timer.periodic(const Duration(seconds: 3), (t) async {
        if (++n > 200) {
          t.cancel();
          if (mounted) setState(() => _busy = false);
          return;
        }
        try {
          final st = asMap(await api.get('woocommerce/authorize/$_state'));
          if (!mounted) return t.cancel();
          if (st['status'] == 'connected') {
            t.cancel();
            Navigator.pop(context, true);
            toast(context, tr('Boutique connectée. Choisissez maintenant les produits à publier.'));
          } else if (st['status'] == 'error') {
            t.cancel();
            setState(() => _busy = false);
            toast(context, tr('${st['message'] ?? 'Connexion impossible.'}'), error: true);
          }
        } catch (_) {}
      });
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    if (!_manual) return _oneClickView(hint);
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: const Color(0xFFF3EEFA), borderRadius: BorderRadius.circular(10)),
              child: Text(
                tr(
                  _Pf.shopify
                      ? 'Dans Shopify : Paramètres › Applications › Développer des applications › Créer une application. Accordez les accès read/write_products, read/write_orders, read/write_inventory et read_locations, installez-la, puis copiez le jeton de l\'API Admin (shpat_…) et la clé secrète de l\'API.'
                      : 'Dans WordPress : WooCommerce › Réglages › Avancé › API REST › Ajouter une clé. Choisissez votre utilisateur administrateur, droits « Lecture/Écriture », puis copiez la clé client (ck_…) et la clé secrète (cs_…).',
                ),
                style: const TextStyle(height: 1.45, fontSize: 13),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _url,
              decoration: InputDecoration(
                labelText: trn(_Pf.shopify ? 'Adresse de la boutique Shopify' : 'Adresse du site'),
                hintText: trn(_Pf.shopify ? 'maboutique.myshopify.com' : 'www.maboutique.ci'),
              ),
            ),
            TextField(
              controller: _key,
              decoration: InputDecoration(labelText: trn(_Pf.shopify ? 'Jeton d\'accès API Admin' : 'Clé client'), hintText: trn(_Pf.shopify ? 'shpat_…' : 'ck_…')),
            ),
            TextField(
              controller: _secret,
              obscureText: true,
              decoration: InputDecoration(
                labelText: trn(_Pf.shopify ? 'Clé secrète de l\'API (signature des webhooks)' : 'Clé secrète'),
                hintText: trn(_Pf.shopify ? 'shpss_… ou clé secrète' : 'cs_…'),
              ),
            ),
            TextField(
              controller: _name,
              decoration: InputDecoration(labelText: tr('Nom de la boutique (facultatif)')),
            ),
            const SizedBox(height: 10),
            DropdownButtonFormField<int>(
              initialValue: _days,
              decoration: InputDecoration(labelText: tr('Importer les commandes existantes')),
              items: [
                DropdownMenuItem(value: 0, child: Text(tr('Aucune, seulement les nouvelles'))),
                DropdownMenuItem(value: 7, child: Text(tr('Des 7 derniers jours'))),
                DropdownMenuItem(value: 30, child: Text(tr('Des 30 derniers jours'))),
              ],
              onChanged: (v) => _days = v ?? 7,
            ),
            const SizedBox(height: 8),
            Text(tr('Le logiciel crée lui-même les webhooks de commandes sur le site.'), style: TextStyle(color: hint, fontSize: 12)),
          ],
        ),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: _woo),
              onPressed: _busy
                  ? null
                  : () async {
                      setState(() => _busy = true);
                      try {
                        await context.read<Session>().api.postLong('${_Pf.key}/stores', {
                          'url': _url.text.trim(),
                          'consumer_key': _key.text.trim(),
                          'consumer_secret': _secret.text.trim(),
                          if (_name.text.trim().isNotEmpty) 'name': _name.text.trim(),
                          'import_days': _days,
                        });
                        if (context.mounted) {
                          Navigator.pop(context, true);
                          toast(context, tr('Boutique connectée. Choisissez maintenant les produits à publier.'));
                        }
                      } catch (e) {
                        if (context.mounted) toast(context, errorText(e), error: true);
                      } finally {
                        if (mounted) setState(() => _busy = false);
                      }
                    },
              child: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Text(tr('Connecter')),
            ),
          ]),
        ),
      ]),
    );
  }

  Widget _oneClickView(Color hint) => YAlertDialog(
    title: Text(tr('Connecter ma boutique WooCommerce')),
    content: SizedBox(
      width: 520,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            tr(
              'Indiquez l\'adresse de votre site WordPress. Une page de votre site s\'ouvre : connectez-vous en administrateur et cliquez sur « Approuver ». C\'est tout : clés, webhooks et réglages se font tout seuls.',
            ),
            style: const TextStyle(height: 1.45),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _url,
            enabled: !_busy,
            decoration: InputDecoration(labelText: tr('Adresse du site'), hintText: 'www.maboutique.ci'),
          ),
          TextField(
            controller: _name,
            enabled: !_busy,
            decoration: InputDecoration(labelText: tr('Nom de la boutique (facultatif)')),
          ),
          const SizedBox(height: 10),
          DropdownButtonFormField<int>(
            initialValue: _days,
            decoration: InputDecoration(labelText: tr('Importer les commandes existantes')),
            items: [
              DropdownMenuItem(value: 0, child: Text(tr('Aucune, seulement les nouvelles'))),
              DropdownMenuItem(value: 7, child: Text(tr('Des 7 derniers jours'))),
              DropdownMenuItem(value: 30, child: Text(tr('Des 30 derniers jours'))),
            ],
            onChanged: _busy ? null : (v) => _days = v ?? 7,
          ),
          if (_busy) ...[
            const SizedBox(height: 16),
            Row(
              children: [
                const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(tr('En attente de votre approbation sur le site…'), style: TextStyle(color: hint)),
                ),
              ],
            ),
          ],
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(onPressed: _busy ? null : () => setState(() => _manual = true), child: Text(tr('Saisir les clés API à la main'))),
          ),
        ],
      ),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
      FilledButton.icon(
        style: FilledButton.styleFrom(backgroundColor: _woo),
        onPressed: _busy ? null : _oneClick,
        icon: const Icon(Icons.open_in_new, size: 18),
        label: Text(tr('Autoriser sur mon site')),
      ),
    ],
  );
}

// ---------------------------------------------------------------------------- produits

class _ProductsTab extends StatefulWidget {
  const _ProductsTab({required this.store, required this.onChanged});

  final Map<String, dynamic> store;
  final VoidCallback onChanged;

  @override
  State<_ProductsTab> createState() => _ProductsTabState();
}

class _ProductsTabState extends State<_ProductsTab> {
  List<Map<String, dynamic>>? _list;
  // '' = tous (filtre serveur 'all')
  String _filter = 'published';
  String _q = '';
  Map<String, String?> _f = {};
  int _rk = 0;
  Timer? _debounce;
  int _total = 0;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  final Set<String> _sel = {};
  bool _busy = false;

  ApiClient get api => context.read<Session>().api;
  int get sid => widget.store['id'] as int;

  @override
  void initState() {
    super.initState();
    _filter = (widget.store['products'] ?? 0) == 0 ? 'unpublished' : 'published';
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await api.get('${_Pf.key}/stores/$sid/products', query: {'filter': _filter.isEmpty ? 'all' : _filter, if (_q.isNotEmpty) 'q': _q});
      if (mounted) setState(() => (_list = [for (final x in (r['data'] as List)) asMap(x)], _total = (r['total'] as num?)?.toInt() ?? 0));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _choose(bool publish, {bool all = false, String? category}) async {
    setState(() => _busy = true);
    try {
      final r = await api.postLong('${_Pf.key}/stores/$sid/products', {
        'publish': publish,
        if (!all && category == null) 'product_ids': _sel.toList(),
        if (all) 'all_active': true,
        'category_id': ?category,
      });
      final p = r['push'] as Map?;
      if (mounted) {
        toast(
          context,
          publish ? '${r['changed']} produit(s) ajouté(s)${p != null ? ' · ${p['created']} créé(s) sur le site' : ''}.' : '${r['changed']} produit(s) retiré(s) du site (passés en brouillon).',
        );
      }
      _sel.clear();
      await _load();
      widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _link() async {
    final create = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Relier les produits déjà en ligne')),
        content: SizedBox(
          width: 440,
          child: Text(tr('Les produits du site sont reliés à ceux du logiciel par leur référence (SKU), variations comprises. Ensuite, le prix et le stock du logiciel font foi.')),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          OutlinedButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Relier et importer les manquants'))),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: _woo),
            onPressed: () => Navigator.pop(c, false),
            child: Text(tr('Relier')),
          ),
        ],
      ),
    );
    if (create == null) return;
    setState(() => _busy = true);
    try {
      final r = await api.postLong('${_Pf.key}/stores/$sid/link', {'create_missing': create});
      final d = r['data'] as Map;
      if (mounted) toast(context, tr('${d['linked']} produit(s) relié(s)${(d['created'] ?? 0) > 0 ? ', ${d['created']} importé(s)' : ''}, ${d['unmatched']} sans correspondance.'));
      await _load();
      widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final can = context.read<Session>().can('woo.manage');
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final cats = LocalDb.all('product_categories').where((c) => c['deleted_at'] == null).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final all = _list ?? const <Map<String, dynamic>>[];
    final shown = all
        .where((p) => _f['category'] == null || '${p['category'] ?? ''}' == _f['category'])
        .where(
          (p) =>
              _f['stock'] == null ||
              switch (_f['stock']) {
                'none' => p['stock'] == null,
                'out' => p['stock'] != null && toDouble(p['stock']) <= 0,
                _ => p['stock'] != null && toDouble(p['stock']) > 0,
              },
        )
        .where(
          (p) =>
              _f['state'] == null ||
              switch (_f['state']) {
                'error' => p['error'] != null,
                'online' => p['error'] == null && p['online'] == true,
                'pending' => p['error'] == null && p['online'] != true && p['published'] == true,
                _ => p['error'] == null && p['online'] != true && p['published'] != true,
              },
        )
        .toList();
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: FilterBar(
            key: ValueKey(_rk),
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
            onSearch: (v) {
              _q = v.trim();
              _debounce?.cancel();
              _debounce = Timer(const Duration(milliseconds: 450), _load);
            },
            search: _q,
            searchHint: 'Nom ou référence',
            statuses: const {'published': 'Sur le site', 'unpublished': 'Pas encore en ligne', 'errors': 'En erreur', '': 'Tous'},
            status: _filter,
            onStatus: (v) {
              setState(() {
                _filter = v;
                _sel.clear();
              });
              _load();
            },
            filters: [
              FilterSpec('category', 'Catégorie', filterOptions(all, 'category'), icon: Icons.category_outlined),
              const FilterSpec('stock', 'Stock', {'in': 'En stock', 'out': 'Rupture', 'none': 'Stock non suivi'}, icon: Icons.inventory_outlined),
              const FilterSpec('state', 'État sur le site', {'online': 'En ligne', 'pending': 'En attente', 'offline': 'Hors ligne', 'error': 'Erreur'}, icon: Icons.cloud_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            count: _list == null ? null : shown.length,
            countLabel: 'produit',
            onReset: () {
              _sel.clear();
              setState(() => (_q = '', _filter = '', _f = {}, _rk++));
              _load();
            },
          ),
        ),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              if (can) ...[
                if (_sel.isNotEmpty && _filter != 'published')
                  FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: _woo),
                    onPressed: _busy ? null : () => _choose(true),
                    icon: const Icon(Icons.cloud_upload_outlined, size: 18),
                    label: Text(tr('Mettre en ligne (${_sel.length})')),
                  ),
                if (_sel.isNotEmpty && _filter == 'published')
                  OutlinedButton.icon(onPressed: _busy ? null : () => _choose(false), icon: const Icon(Icons.visibility_off_outlined, size: 18), label: Text(tr('Retirer du site (${_sel.length})'))),
                PopupMenuButton<String>(
                  tooltip: tr('Plus'),
                  enabled: !_busy,
                  onSelected: (v) {
                    if (v == 'all') _choose(true, all: true);
                    if (v == 'link') _link();
                    if (v.startsWith('cat:')) _choose(true, category: v.substring(4));
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(
                      value: 'all',
                      child: ListTile(dense: true, leading: Icon(Icons.done_all), title: Text(tr('Mettre en ligne tous les produits actifs'))),
                    ),
                    PopupMenuItem(
                      value: 'link',
                      child: ListTile(dense: true, leading: Icon(Icons.link), title: Text(tr('Relier les produits déjà sur le site (SKU)'))),
                    ),
                    if (cats.isNotEmpty) const PopupMenuDivider(),
                    for (final c in cats.take(15)) PopupMenuItem(value: 'cat:${c['id']}', child: Text(tr('Mettre en ligne : ${c['name']}'))),
                  ],
                  child: const Padding(padding: EdgeInsets.all(8), child: Icon(Icons.more_horiz)),
                ),
              ],
              if (_busy) const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
            ],
          ),
        ),
        Expanded(
          child: _list == null
              ? const Center(child: CircularProgressIndicator())
              : _list!.isEmpty
              ? EmptyState(
                  icon: Icons.inventory_2_outlined,
                  title: _filter == 'published' ? 'Aucun produit en ligne' : 'Aucun produit',
                  message: _filter == 'published' ? 'Choisissez « Pas encore en ligne » pour sélectionner les produits à publier.' : null,
                )
              : shown.isEmpty
              ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun produit ne correspond aux filtres'))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                  children: [
                    Text(tr('$_total produit(s) · prix TTC publiés, stock disponible (commandes en cours déduites)'), style: TextStyle(color: hint, fontSize: 12)),
                    const SizedBox(height: 6),
                    Card(
                      child: Column(
                        children: [
                          for (final p in shown)
                            ListTile(
                              leading: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  if (can) Checkbox(value: _sel.contains(p['id']), onChanged: (v) => setState(() => v == true ? _sel.add('${p['id']}') : _sel.remove(p['id']))),
                                  ClipRRect(
                                    borderRadius: BorderRadius.circular(6),
                                    child: p['image'] != null ? Image.network('${p['image']}', width: 40, height: 40, fit: BoxFit.cover, errorBuilder: (_, _, _) => const _Thumb()) : const _Thumb(),
                                  ),
                                ],
                              ),
                              title: Text(tr('${p['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                              subtitle: Text(
                                tr([if (p['sku'] != null) 'Réf. ${p['sku']}', if (p['category'] != null) '${p['category']}', if (p['error'] != null) '${p['error']}'].join(' · ')),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: p['error'] != null ? const TextStyle(color: YColors.danger) : null,
                              ),
                              trailing: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      Text(tr(money(p['price'], cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
                                      Text(
                                        tr(p['stock'] == null ? 'stock non suivi' : '${p['stock']} en stock'),
                                        style: TextStyle(color: (p['stock'] ?? 1) == 0 ? YColors.danger : hint, fontSize: 12),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(width: 12),
                                  SizedBox(
                                    width: 110,
                                    child: Align(
                                      alignment: Alignment.centerRight,
                                      child: p['error'] != null
                                          ? StatusChip(tr('Erreur'), color: YColors.danger)
                                          : p['online'] == true
                                          ? InkWell(
                                              onTap: () => safeLaunchUrl(Uri.parse('${p['remote_url']}'), webOnlyWindowName: '_blank'),
                                              child: StatusChip(p['variation'] == true ? 'Variation' : 'En ligne', color: YColors.success),
                                            )
                                          : (p['published'] == true ? StatusChip(tr('En attente'), color: YColors.warning) : StatusChip(tr('Hors ligne'), color: YColors.muted)),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
        ),
      ],
    );
  }
}

class _Thumb extends StatelessWidget {
  const _Thumb();

  @override
  Widget build(BuildContext context) => Container(
    width: 40,
    height: 40,
    color: const Color(0xFFF1ECF8),
    child: Icon(Icons.image_outlined, size: 18, color: _woo),
  );
}

// ---------------------------------------------------------------------------- commandes

class _OrdersTab extends StatefulWidget {
  const _OrdersTab({required this.store, required this.statuses});

  final Map<String, dynamic> store;
  final Map<String, dynamic> statuses;

  @override
  State<_OrdersTab> createState() => _OrdersTabState();
}

class _OrdersTabState extends State<_OrdersTab> {
  List<Map<String, dynamic>>? _list;
  String _q = '';
  String _st = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _rk = 0;

  ApiClient get api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await api.get('${_Pf.key}/stores/${widget.store['id']}/orders');
      if (mounted) setState(() => _list = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (e) {
      // Hors ligne ou serveur injoignable : on arrête le chargement au lieu de tourner sans fin
      if (mounted) {
        setState(() => _list = []);
        toast(context, e is ApiException ? e.message : tr('Connexion impossible : réessayez plus tard.'), error: true);
      }
    }
  }

  Color _color(String? s) => switch (s) {
    'completed' => YColors.success,
    'processing' => const Color(0xFF1A73E8),
    'on-hold' => YColors.warning,
    'cancelled' || 'refunded' || 'failed' => YColors.danger,
    _ => YColors.muted,
  };

  Future<void> _status(Map<String, dynamic> o, String st) async {
    try {
      await api.post('${_Pf.key}/stores/${widget.store['id']}/orders/${o['id']}/status', {'status': st});
      if (mounted) toast(context, tr('Commande n° ${o['number']} : ${st == 'paid' ? 'marquée payée' : '« ${widget.statuses[st]} »'} sur ${_Pf.label}.'));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final can = context.read<Session>().can('woo.manage');
    if (_list == null) return const Center(child: CircularProgressIndicator());
    if (_list!.isEmpty) {
      return EmptyState(icon: Icons.shopping_bag_outlined, title: tr('Aucune commande importée'), message: tr('Les nouvelles commandes du site apparaîtront ici en quelques secondes.'));
    }
    final all = _list!;
    final q = _q.toLowerCase();
    final base = all
        .where((o) => q.isEmpty || '${o['number'] ?? ''} ${o['customer'] ?? ''} ${o['order']?['number'] ?? ''} ${o['invoice']?['number'] ?? ''}'.toLowerCase().contains(q))
        .where((o) => _f['payment'] == null || '${o['payment'] ?? ''}' == _f['payment'])
        .where(
          (o) =>
              _f['invoice'] == null ||
              switch (_f['invoice']) {
                'paid' => o['invoice']?['payment_status'] == 'paid',
                'unpaid' => o['invoice'] != null && o['invoice']['payment_status'] != 'paid',
                _ => o['invoice'] == null,
              },
        )
        .where((o) => _period == null || _period!.contains(o['date']))
        .toList();
    final rows = base.where((o) => _st.isEmpty || '${o['status']}' == _st).toList();
    final stLabels = {
      for (final o in all)
        if (o['status'] != null) '${o['status']}': '${o['status_label'] ?? widget.statuses[o['status']] ?? o['status']}',
    };
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          FilterBar(
            key: ValueKey(_rk),
            padding: const EdgeInsets.only(bottom: 10),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'N°, client, facture…',
            statuses: {'': 'Toutes', ...stLabels},
            status: _st,
            onStatus: (v) => setState(() => _st = v),
            statusCounts: {'': base.length, for (final k in stLabels.keys) k: base.where((o) => '${o['status']}' == k).length},
            filters: [
              FilterSpec('payment', 'Paiement', filterOptions(all, 'payment'), icon: Icons.payments_outlined),
              const FilterSpec('invoice', 'Facture', {'paid': 'Facture payée', 'unpaid': 'Facture à encaisser', 'none': 'Sans facture'}, icon: Icons.receipt_long_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: rows.length,
            countLabel: 'commande',
            onReset: () => setState(() => (_q = '', _st = '', _f = {}, _period = null, _rk++)),
          ),
          if (rows.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune commande ne correspond aux filtres')),
          if (rows.isNotEmpty)
            Card(
              child: Column(
                children: [
                  for (final o in rows)
                    ListTile(
                      leading: CircleAvatar(
                        backgroundColor: _color(o['status'] as String?).withValues(alpha: .12),
                        child: Icon(Icons.shopping_bag_outlined, color: _color(o['status'] as String?), size: 20),
                      ),
                      title: Row(
                        children: [
                          Text(tr('n° ${o['number']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                          const SizedBox(width: 8),
                          Flexible(child: Text(tr('${o['customer'] ?? ''}'), overflow: TextOverflow.ellipsis)),
                        ],
                      ),
                      subtitle: Text(
                        tr(
                          [
                            if (o['date'] != null) DateFormat('d MMM HH:mm', 'fr_FR').format(DateTime.parse('${o['date']}')),
                            if (o['payment'] != null) '${o['payment']}',
                            if (o['order'] != null) 'Commande ${o['order']['number']}${o['order']['status'] == 'cancelled' ? ' (annulée)' : ''}',
                            if (o['invoice'] != null) 'Facture ${o['invoice']['number']}${o['invoice']['payment_status'] == 'paid' ? ' payée' : ''}',
                          ].join(' · '),
                        ),
                        style: TextStyle(color: hint, fontSize: 12.5),
                      ),
                      trailing: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(tr(money(o['total'], '${o['currency'] ?? context.read<Session>().currency}')), style: const TextStyle(fontWeight: FontWeight.w700)),
                          const SizedBox(width: 10),
                          StatusChip('${o['status_label']}', color: _color(o['status'] as String?)),
                          PopupMenuButton<String>(
                            onSelected: (v) => v == 'admin' ? safeLaunchUrl(Uri.parse('${o['admin_url']}'), webOnlyWindowName: '_blank') : _status(o, v),
                            itemBuilder: (_) => [
                              PopupMenuItem(value: 'admin', child: Text(tr('Ouvrir dans ${_Pf.label}'))),
                              if (can && _Pf.shopify && o['status'] != 'completed') PopupMenuItem(value: 'paid', child: Text(tr('Marquer payée sur Shopify'))),
                              if (can && _Pf.shopify && o['status'] != 'completed') PopupMenuItem(value: 'completed', child: Text(tr('Clôturer sur Shopify'))),
                              if (can && !_Pf.shopify && o['status'] != 'completed') PopupMenuItem(value: 'completed', child: Text(tr('Marquer « Terminée » sur le site'))),
                              if (can && !_Pf.shopify && o['status'] == 'on-hold') PopupMenuItem(value: 'processing', child: Text(tr('Marquer « En cours » sur le site'))),
                            ],
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------- journal

class _LogsTab extends StatefulWidget {
  const _LogsTab({required this.storeId});

  final int storeId;

  @override
  State<_LogsTab> createState() => _LogsTabState();
}

class _LogsTabState extends State<_LogsTab> {
  List<Map<String, dynamic>>? _list;
  String _q = '';
  String _level = '';
  FilterPeriod? _period;
  int _rk = 0;

  static String _lv(Map<String, dynamic> l) => l['level'] == 'error' ? 'error' : (l['level'] == 'warning' ? 'warning' : 'ok');

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('${_Pf.key}/stores/${widget.storeId}/logs').then((r) {
      if (mounted) setState(() => _list = [for (final x in (r['data'] as List)) asMap(x)]);
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_list == null) return const Center(child: CircularProgressIndicator());
    if (_list!.isEmpty) return EmptyState(icon: Icons.history, title: tr('Journal vide'));
    final q = _q.toLowerCase();
    final base = _list!
        .where((l) => q.isEmpty || '${l['message'] ?? ''}'.toLowerCase().contains(q))
        .where((l) => _period == null || _period!.contains(DateTime.tryParse('${l['created_at'] ?? ''}')?.toLocal()))
        .toList();
    final rows = base.where((l) => _level.isEmpty || _lv(l) == _level).toList();
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        FilterBar(
          key: ValueKey(_rk),
          padding: const EdgeInsets.only(bottom: 10),
          onSearch: (v) => setState(() => _q = v),
          search: _q,
          searchHint: 'Rechercher dans le journal…',
          statuses: const {'': 'Tout', 'error': 'Erreurs', 'warning': 'Avertissements', 'ok': 'Succès'},
          status: _level,
          onStatus: (v) => setState(() => _level = v),
          statusCounts: {
            '': base.length,
            for (final k in ['error', 'warning', 'ok']) k: base.where((l) => _lv(l) == k).length,
          },
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows.length,
          countLabel: 'entrée',
          onReset: () => setState(() => (_q = '', _level = '', _period = null, _rk++)),
        ),
        if (rows.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune entrée ne correspond aux filtres')),
        if (rows.isNotEmpty)
          Card(
            child: Column(
              children: [
                for (final l in rows)
                  ListTile(
                    dense: true,
                    leading: Icon(
                      l['level'] == 'error' ? Icons.error_outline : (l['level'] == 'warning' ? Icons.warning_amber : Icons.check_circle_outline),
                      color: l['level'] == 'error' ? YColors.danger : (l['level'] == 'warning' ? YColors.warning : YColors.success),
                      size: 20,
                    ),
                    title: Text(tr('${l['message']}')),
                    trailing: Text(tr(_ago(l['created_at'])), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------- réglages

class _SettingsDialog extends StatefulWidget {
  const _SettingsDialog({required this.store, required this.statuses, required this.warehouses});

  final Map<String, dynamic> store;
  final Map<String, dynamic> statuses;
  final List warehouses;

  @override
  State<_SettingsDialog> createState() => _SettingsDialogState();
}

class _SettingsDialogState extends State<_SettingsDialog> {
  late final Map<String, dynamic> s = asMap(widget.store['settings']);
  late final Set<String> _st = {for (final x in (s['import_statuses'] as List? ?? const [])) '$x'};
  late bool _paused = widget.store['status'] == 'paused';

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final hint = Theme.of(context).hintColor;
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Expanded(
          child: ListView(
            children: [
              Text(tr('Produits'), style: TextStyle(fontWeight: FontWeight.w800)),
              SwitchListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                value: s['publish_prices'] == true,
                onChanged: (v) => setState(() => s['publish_prices'] = v),
                title: Text(tr('Publier les prix du logiciel')),
              ),
              SwitchListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                value: s['publish_stock'] == true,
                onChanged: (v) => setState(() => s['publish_stock'] = v),
                title: Text(tr('Publier le stock disponible')),
              ),
              Row(
                children: [
                  Expanded(
                    child: TextFormField(
                      initialValue: '${s['stock_buffer'] ?? 0}',
                      keyboardType: TextInputType.number,
                      decoration: InputDecoration(labelText: tr('Marge de sécurité (unités retenues)')),
                      onChanged: (v) => s['stock_buffer'] = int.tryParse(v) ?? 0,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: DropdownButtonFormField<String?>(
                      initialValue: s['warehouse_id'] as String?,
                      decoration: InputDecoration(labelText: tr('Dépôt du stock publié')),
                      items: [
                        DropdownMenuItem(value: null, child: Text(tr('Tous les dépôts'))),
                        for (final w in widget.warehouses) DropdownMenuItem(value: '${w['id']}', child: Text(tr('${w['name']}'))),
                      ],
                      onChanged: (v) => s['warehouse_id'] = v,
                    ),
                  ),
                ],
              ),
              DropdownButtonFormField<String>(
                initialValue: '${s['publish_new_as']}',
                decoration: InputDecoration(labelText: tr('Nouveaux produits créés sur le site')),
                items: [
                  DropdownMenuItem(value: 'publish', child: Text(tr('Publiés directement'))),
                  DropdownMenuItem(value: 'draft', child: Text(tr('En brouillon (à relire sur le site)'))),
                ],
                onChanged: (v) => s['publish_new_as'] = v,
              ),
              const SizedBox(height: 16),
              Text(tr('Commandes'), style: TextStyle(fontWeight: FontWeight.w800)),
              SwitchListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                value: s['import_orders'] == true,
                onChanged: (v) => setState(() => s['import_orders'] = v),
                title: Text(tr('Importer les commandes du site')),
              ),
              Text(tr('Statuts importés :'), style: TextStyle(color: hint, fontSize: 12.5)),
              Wrap(
                spacing: 6,
                children: [
                  for (final k in const ['pending', 'processing', 'on-hold', 'completed'])
                    FilterChip(label: Text(tr('${widget.statuses[k] ?? k}')), selected: _st.contains(k), onSelected: (v) => setState(() => v ? _st.add(k) : _st.remove(k))),
                ],
              ),
              SwitchListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                value: s['invoice_paid'] == true,
                onChanged: (v) => setState(() => s['invoice_paid'] = v),
                title: Text(tr('Facturer et encaisser les commandes payées')),
                subtitle: Text(tr('Paiement en ligne, ou commande terminée sur le site')),
              ),
              SwitchListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                value: s['push_status'] == true,
                onChanged: (v) => setState(() => s['push_status'] = v),
                title: Text(tr('Renvoyer le statut au site')),
                subtitle: Text(
                  tr(
                    _Pf.shopify ? 'Commande marquée payée sur Shopify dès l\'encaissement dans le logiciel' : 'À la livraison : « Terminée » une fois encaissée ; virement : « En cours » une fois reçu',
                  ),
                ),
              ),
              TextFormField(
                initialValue: '${s['order_prefix'] ?? 'WOO'}',
                decoration: InputDecoration(labelText: tr('Préfixe de la référence des commandes')),
                onChanged: (v) => s['order_prefix'] = v.trim(),
              ),
              const SizedBox(height: 16),
              Text(tr('Connexion'), style: TextStyle(fontWeight: FontWeight.w800)),
              SwitchListTile(dense: true, contentPadding: EdgeInsets.zero, value: _paused, onChanged: (v) => setState(() => _paused = v), title: Text(tr('Mettre la synchronisation en pause'))),
              SelectableText(tr('Webhook : ${widget.store['webhook_url']}'), style: TextStyle(color: hint, fontSize: 12)),
              if (widget.store['webhook_error'] != null) Text(tr('${widget.store['webhook_error']}'), style: const TextStyle(color: YColors.warning, fontSize: 12)),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: () async {
                    try {
                      await api.postLong('${_Pf.key}/stores/${widget.store['id']}/sync', {'what': 'webhooks'});
                      if (context.mounted) toast(context, tr('Webhooks vérifiés.'));
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                  icon: const Icon(Icons.bolt, size: 18),
                  label: Text(tr('Recréer les webhooks')),
                ),
              ),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: () async {
                    final ok = await confirm(
                      context,
                      tr('Déconnecter la boutique ?'),
                      tr('Les produits restent sur le site, mais ne seront plus mis à jour ; les commandes déjà importées restent dans les Ventes.'),
                      ok: tr('Déconnecter'),
                      danger: true,
                    );
                    if (!ok) return;
                    await api.delete('${_Pf.key}/stores/${widget.store['id']}');
                    if (context.mounted) Navigator.pop(context, true);
                  },
                  icon: const Icon(Icons.link_off, size: 18, color: YColors.danger),
                  label: Text(tr('Déconnecter la boutique'), style: TextStyle(color: YColors.danger)),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
            SaveButton(
              style: FilledButton.styleFrom(backgroundColor: _woo),
              onPressed: () async {
                try {
                  await api.put('${_Pf.key}/stores/${widget.store['id']}', {
                    'status': _paused ? 'paused' : 'active',
                    'settings': {...s, 'import_statuses': _st.toList()},
                  });
                  if (context.mounted) {
                    Navigator.pop(context, true);
                    toast(context, tr('Réglages enregistrés.'));
                  }
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ]),
        ),
      ]),
    );
  }
}
