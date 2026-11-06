import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/pricing.dart';
import '../../core/stock_calc.dart';
import '../../core/sync_engine.dart';
import '../../display/customer_display.dart';
import '../../printing/pos_printer.dart';
import 'customer_display_dialog.dart';
import 'printer_settings_dialog.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/mobile_money_collect.dart';
import 'document_editor.dart' show computeLine, paymentMethods;
import 'fidelite_screen.dart' show LoyaltyBadge, fidActive, fidMinRedeem, fidPointValue, giftCardByCode, giftUsable, loyaltyCardOf;
import '../../i18n/i18n.dart';

/// Ligne du panier (prix stockés HT, affichés TTC).
class CartLine {
  CartLine(this.product, {this.packaging}) : quantity = 1;

  final Map<String, dynamic> product;
  /// Conditionnement vendu (carton, pack…) : quantité exprimée dans ce conditionnement.
  final Map<String, dynamic>? packaging;
  double quantity;
  double discount = 0;
  bool manualDiscount = false;
  double? listPrice;
  String? priceLabel;

  String get key => '${product['id']}|${packaging?['name'] ?? ''}';
  String get label => packaging == null ? '${product['name']}' : '${product['name']} — ${packaging!['name']}';
  double get factor => packaging == null ? 1 : toDouble(packaging!['factor']);

  /// Liste de prix du client (module tarifs) : prix et remise par quantité.
  void applyPricing(String? partnerId) {
    final r = resolvePrice(partnerId: partnerId, product: product, quantity: quantity, packaging: packaging);
    listPrice = r.unitPrice;
    priceLabel = r.label;
    if (!manualDiscount) discount = r.discount;
  }

  double get unitHt => listPrice ?? basePrice(product, packaging);
  double get taxRate => toDouble(product['tax_rate']);
  double get unitTtc => unitHt * (1 + taxRate / 100);

  Map<String, dynamic> toLine() => {
        'id': newId(),
        'product_id': product['id'],
        'description': label,
        'unit': packaging?['name'] ?? product['unit'],
        if (packaging != null) 'unit_factor': factor,
        'quantity': quantity,
        'unit_price': unitHt,
        'discount_percent': discount,
        'tax_rate': taxRate,
      };

  double get total => computeLine(toLine())['total']!;
}

/// Caisse tactile. Tout fonctionne hors ligne : tickets, encaissements,
/// ouverture et clôture de session ; la synchronisation se fait en arrière-plan.
class PosScreen extends StatefulWidget {
  const PosScreen({super.key});

  @override
  State<PosScreen> createState() => _PosScreenState();
}

class _PosScreenState extends State<PosScreen> {
  final _cart = <CartLine>[];
  final _search = TextEditingController();
  final _searchFocus = FocusNode();
  String? _category;
  Map<String, dynamic>? _customer;
  String? _registerId;

  @override
  void initState() {
    super.initState();
    _registerId = LocalDb.meta.get('pos_register_id') as String?;
    CustomerDisplay.instance.ensureStarted();
  }

  String? _displaySig;

  /// Répercute le panier sur l'écran client (seulement s'il a changé).
  void _syncDisplay(Session session) {
    final cur = session.currency;
    final sig = '${_customer?['id']}|${_cart.map((l) => '${l.key}:${l.quantity}:${l.discount}:${l.unitHt}').join(',')}';
    if (sig == _displaySig) return;
    _displaySig = sig;
    final display = CustomerDisplay.instance..setBranding(company: '${session.company['name'] ?? ''}', footer: '${session.companySettings['receipt_footer'] ?? ''}');
    WidgetsBinding.instance.addPostFrameCallback((_) => display.showCart(
          lines: [
            for (final l in _cart)
              {
                'key': l.key,
                'name': l.label,
                'detail': '${qty(l.quantity)} × ${money(l.unitTtc, cur)}${l.discount > 0 ? ' · remise ${qty(l.discount)} %' : ''}',
                'total': money(l.total, cur),
              },
          ],
          total: money(_total, cur),
          count: '${qty(_count)} article${_count > 1 ? 's' : ''}',
          customer: _customer?['name'] as String?,
        ));
  }

  Map<String, dynamic>? get _register {
    final regs = _registers;
    if (regs.isEmpty) return null;
    return regs.firstWhere((r) => r['id'] == _registerId, orElse: () => regs.first);
  }

  /// Caisses de l'agence de travail (multi-agences), et celles qui ne sont rattachées à aucune agence.
  List<Map<String, dynamic>> get _registers {
    final b = context.read<Session>().currentBranchId;
    return LocalDb.all('pos_registers').where((r) => r['active'] != false && (b == null || r['branch_id'] == null || r['branch_id'] == b)).toList();
  }

  Map<String, dynamic>? _openSession(String registerId) {
    final open = LocalDb.all('pos_sessions').where((s) => s['register_id'] == registerId && s['status'] == 'open').toList()
      ..sort((a, b) => '${b['opened_at']}'.compareTo('${a['opened_at']}'));
    return open.isEmpty ? null : open.first;
  }

  double get _total => _cart.fold(0, (s, l) => s + l.total);
  double get _count => _cart.fold(0, (s, l) => s + l.quantity);

  void _add(Map<String, dynamic> product, {Map<String, dynamic>? packaging}) {
    if (product['is_template'] == true) return;
    setState(() {
      final key = '${product['id']}|${packaging?['name'] ?? ''}';
      final existing = _cart.where((l) => l.key == key);
      if (existing.isNotEmpty) {
        existing.first.quantity++;
      } else {
        _cart.add(CartLine(product, packaging: packaging));
      }
    });
  }

  /// Prix des lignes selon la liste de prix du client (recalculé à chaque changement).
  void _applyPricing(Session session) {
    if (!session.hasModule('tarifs')) return;
    for (final l in _cart) {
      l.applyPricing(_customer?['id'] as String?);
    }
  }

  /// Saisie / lecteur de code-barres : Entrée ajoute le produit correspondant.
  void _onScan(String code) {
    final c = code.trim().toLowerCase();
    if (c.isEmpty) return;
    final products = LocalDb.all('products').where((p) => p['active'] != false && p['is_template'] != true).toList();
    // Code-barres d'un conditionnement (carton, pack) : ajoute le conditionnement entier
    final pk = packagingByBarcode(c);
    if (pk != null && pk.$1['active'] != false && pk.$1['is_template'] != true) {
      _add(pk.$1, packaging: pk.$2);
      _search.clear();
      _searchFocus.requestFocus();
      return;
    }
    // Étiquette de pesée (21 + code balance + poids en grammes + clé) : article au poids
    if (RegExp(r'^21\d{11}$').hasMatch(c) && !products.any((p) => p['barcode'] == c)) {
      final plu = c.substring(2, 7);
      final p = products.where((p) => '${p['plu'] ?? ''}' == plu).firstOrNull;
      if (p != null) {
        final kg = int.parse(c.substring(7, 12)) / 1000;
        setState(() => _cart.add(CartLine(p)..quantity = kg));
        _search.clear();
        _searchFocus.requestFocus();
        return;
      }
    }
    final exact = products.where((p) => '${p['barcode'] ?? ''}'.toLowerCase() == c || '${p['sku'] ?? ''}'.toLowerCase() == c).toList();
    final matches = exact.isNotEmpty ? exact : products.where((p) => '${p['name']}'.toLowerCase().contains(c)).toList();
    if (matches.length == 1) {
      _add(matches.first);
      _search.clear();
      setState(() {});
    } else if (matches.isEmpty) {
      toast(context, tr('Aucun produit pour « $code ».'), error: true);
    }
    _searchFocus.requestFocus();
  }

  // ------------------------------------------------------------------
  // Caisse & session
  // ------------------------------------------------------------------

  Future<void> _createRegister() async {
    final sync = context.read<SyncEngine>();
    final wh = LocalDb.all('warehouses');
    final branch = context.read<Session>().currentBranch;
    final reg = await sync.save('pos_registers', {
      'name': branch != null ? 'Caisse ${branch['name']}' : 'Caisse ${LocalDb.all('pos_registers').length + 1}',
      'code': 'C${LocalDb.all('pos_registers').length + 1}',
      if (branch != null) 'branch_id': branch['id'],
      if (branch?['warehouse_id'] != null) 'warehouse_id': branch!['warehouse_id']
      else if (wh.isNotEmpty) 'warehouse_id': (wh.firstWhere((w) => w['is_default'] == true, orElse: () => wh.first))['id'],
    });
    await LocalDb.meta.put('pos_register_id', reg['id']);
    setState(() => _registerId = reg['id']);
  }

  Future<void> _openSessionDialog(Map<String, dynamic> register) async {
    final ctrl = TextEditingController(text: '0');
    final sync = context.read<SyncEngine>();
    final userName = context.read<Session>().user['name'];
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Ouvrir ${register['name']}')),
        content: SizedBox(
          width: 360,
          child: Field(
            label: tr('Fond de caisse (espèces au départ)'),
            child: TextField(
              controller: ctrl,
              autofocus: true,
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
              onSubmitted: (_) => Navigator.pop(c, true),
            ),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Ouvrir la caisse'))),
        ],
      ),
    );
    if (ok != true) return;
    await sync.save('pos_sessions', {
      'register_id': register['id'],
      'status': 'open',
      'opening_cash': toDouble(ctrl.text),
      'opened_at': DateTime.now().toUtc().toIso8601String(),
      'user_name': userName,
    });
    setState(() {});
  }

  /// Rapport calculé localement (identique au rapport Z serveur), utilisable hors ligne.
  Map<String, dynamic> _localReport(Map<String, dynamic> session) {
    final tickets = LocalDb.all('sales_documents').where((d) => d['pos_session_id'] == session['id'] && d['type'] == 'receipt').toList();
    final valid = tickets.where((d) => d['status'] == 'validated').toList();
    final ids = valid.map((d) => d['id']).toSet();
    final byMethod = <String, Map<String, double>>{};
    for (final p in LocalDb.all('sales_payments').where((p) => ids.contains(p['document_id']))) {
      final m = byMethod.putIfAbsent('${p['method']}', () => {'total': 0, 'count': 0});
      m['total'] = m['total']! + toDouble(p['amount']);
      m['count'] = m['count']! + 1;
    }
    final cash = byMethod['cash']?['total'] ?? 0;
    final opening = toDouble(session['opening_cash']);
    return {
      ...session,
      'tickets': valid,
      'tickets_count': valid.length,
      'total_sales': valid.fold<double>(0, (s, d) => s + toDouble(d['total'])),
      'tax_total': valid.fold<double>(0, (s, d) => s + toDouble(d['tax_total'])),
      'by_method': byMethod,
      'expected_cash': opening + cash,
    };
  }

  Future<void> _closeSession(Map<String, dynamic> register, Map<String, dynamic> session) async {
    final session0 = context.read<Session>();
    final sync = context.read<SyncEngine>();
    final report = _localReport(session);
    final counted = TextEditingController();
    final cur = session0.currency;

    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(builder: (c, setS) {
        final diff = counted.text.isEmpty ? null : toDouble(counted.text) - toDouble(report['expected_cash']);
        return YAlertDialog(
          title: Text(tr('Clôturer la caisse')),
          content: SizedBox(
            width: 400,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              _kv('Tickets', '${report['tickets_count']}'),
              _kv('Ventes TTC', money(report['total_sales'], cur), bold: true),
              const Divider(height: 20),
              for (final e in (report['by_method'] as Map<String, Map<String, double>>).entries) _kv(paymentMethods[e.key] ?? e.key, money(e.value['total'], cur)),
              const Divider(height: 20),
              _kv('Fond de caisse', money(toDouble(session['opening_cash']), cur)),
              _kv('Espèces attendues', money(report['expected_cash'], cur), bold: true),
              const SizedBox(height: 12),
              Field(
                label: tr('Espèces comptées dans le tiroir'),
                child: TextField(
                  controller: counted,
                  autofocus: true,
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
                  onChanged: (_) => setS(() {}),
                ),
              ),
              if (diff != null)
                Text(
                  tr(diff == 0 ? 'Caisse juste' : (diff > 0 ? 'Excédent de ${money(diff, cur)}' : 'Manque de ${money(-diff, cur)}')),
                  style: TextStyle(fontWeight: FontWeight.w700, color: diff == 0 ? YColors.success : YColors.danger),
                ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: counted.text.isEmpty ? null : () => Navigator.pop(c, true), child: Text(tr('Clôturer'))),
          ],
        );
      }),
    );
    if (ok != true) return;

    final closed = await sync.save('pos_sessions', {
      'id': session['id'],
      'status': 'closed',
      'counted_cash': toDouble(counted.text),
      'closed_at': DateTime.now().toUtc().toIso8601String(),
    });
    final z = {
      ..._localReport(closed),
      'counted_cash': toDouble(counted.text),
      'difference': toDouble(counted.text) - toDouble(report['expected_cash']),
    };
    setState(() {});
    if (!mounted) return;
    if (await confirm(context, tr('Caisse clôturée'), tr('Imprimer le rapport de caisse (Z) ?'), ok: tr('Imprimer'))) {
      final warn = await PosPrinter.printZReport(session: session0, report: z, registerName: '${register['name']}');
      if (warn != null && mounted) toast(context, warn, error: true);
    }
  }

  // ------------------------------------------------------------------
  // Encaissement
  // ------------------------------------------------------------------

  Future<void> _checkout(Map<String, dynamic> register, Map<String, dynamic> session) async {
    if (_cart.isEmpty) return;
    final doc = await posCheckout(
      context,
      register: register,
      session: session,
      lines: [for (final l in _cart) l.toLine()],
      customer: _customer,
      onSaved: () => setState(() {
        _cart.clear();
        _customer = null;
      }),
    );
    if (doc != null) _searchFocus.requestFocus();
  }

  Future<void> _kickDrawer() async {
    try {
      await PosPrinter.openDrawer();
    } on PrintFailure catch (e) {
      if (mounted) toast(context, e.message, error: true);
    }
  }

  Future<void> _printerSettings() async {
    final saved = await showDialog<bool>(context: context, builder: (_) => PrinterSettingsDialog(companyName: '${context.read<Session>().company['name'] ?? ''}'));
    if (saved == true && mounted) {
      setState(() {});
      toast(context, tr('Réglages d\'impression enregistrés pour ce poste.'));
    }
    _searchFocus.requestFocus();
  }

  Future<void> _pickCustomer() async {
    final customers = LocalDb.all('partners').where((p) => p['type'] != 'supplier' && p['active'] != false).toList()
      ..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final picked = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (c) => SimpleDialog(title: Text(tr('Client')), children: [
        SimpleDialogOption(onPressed: () => Navigator.pop(c, <String, dynamic>{}), child: Text(tr('Client comptoir (aucun)'))),
        for (final p in customers.take(200)) SimpleDialogOption(onPressed: () => Navigator.pop(c, p), child: Text(tr('${p['name']}'))),
      ]),
    );
    if (picked != null) setState(() => _customer = picked.isEmpty ? null : picked);
  }

  void _history(Map<String, dynamic> register, Map<String, dynamic> session) {
    final sessionState = context.read<Session>();
    final report = _localReport(session);
    final tickets = List<Map<String, dynamic>>.from(report['tickets'])..sort((a, b) => '${b['reference']}'.compareTo('${a['reference']}'));
    showFormDialog(context, title: tr('Tickets de la session (${tickets.length})'), builder: (c) {
      if (tickets.isEmpty) return Padding(padding: EdgeInsets.all(32), child: Text(tr('Aucun ticket pour le moment.')));
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
        _kv('Total de la session', money(report['total_sales'], sessionState.currency), bold: true),
        const Divider(),
        for (final t in tickets)
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('${t['number'] ?? t['reference']}')),
            subtitle: Text(tr('${dateTimeFr(t['created_at'])}${t['partner_name'] != null ? ' · ${t['partner_name']}' : ''}${t['number'] == null ? ' · en attente de synchro' : ''}')),
            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
              Text(tr(money(toDouble(t['total']), sessionState.currency)), style: const TextStyle(fontWeight: FontWeight.w600)),
              IconButton(
                tooltip: tr('Réimprimer'),
                icon: const Icon(Icons.print_outlined),
                onPressed: () async {
                  final warn = await PosPrinter.printReceipt(
                    session: sessionState,
                    doc: t,
                    payments: LocalDb.all('sales_payments').where((p) => p['document_id'] == t['id']).toList(),
                    registerName: '${register['name']}',
                  );
                  if (warn != null && c.mounted) toast(c, warn, error: true);
                },
              ),
            ]),
          ),
      ]);
    });
  }

  // ------------------------------------------------------------------
  // Interface
  // ------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    // Redessiner à la fin d'une synchronisation (nouveaux produits, prix), au passage en ligne / hors ligne et
    // quand la file d'envoi change — pas à chaque étape intermédiaire d'une synchronisation (toutes les 20 s).
    context.select<SyncEngine, (DateTime?, bool, int)>((s) => (s.lastSyncAt, s.online, s.pendingCount));
    _applyPricing(session);
    _syncDisplay(session);

    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pos_sessions'),
      builder: (context, _, _) {
        final register = _register;
        if (register == null) {
          return EmptyState(
            icon: Icons.point_of_sale,
            title: tr('Aucune caisse configurée'),
            message: session.can('pos.manage') ? 'Créez votre première caisse pour commencer à vendre.' : 'Demandez à un administrateur de créer une caisse.',
            action: session.can('pos.manage') ? FilledButton.icon(onPressed: _createRegister, icon: const Icon(Icons.add), label: Text(tr('Créer une caisse'))) : null,
          );
        }
        final open = _openSession(register['id']);
        if (open == null) {
          return EmptyState(
            icon: Icons.lock_outline,
            title: tr('${register['name']} est fermée'),
            message: tr('Ouvrez une session avec votre fond de caisse pour commencer à vendre.'),
            action: Wrap(spacing: 8, children: [
              if (_registers.length > 1) _registerMenu(register),
              FilledButton.icon(onPressed: () => _openSessionDialog(register), icon: const Icon(Icons.lock_open), label: Text(tr('Ouvrir la caisse'))),
            ]),
          );
        }
        return _terminal(context, session, register, open);
      },
    );
  }

  Widget _registerMenu(Map<String, dynamic> current) => PopupMenuButton<String>(
        tooltip: tr('Changer de caisse'),
        onSelected: (id) async {
          await LocalDb.meta.put('pos_register_id', id);
          setState(() => _registerId = id);
        },
        itemBuilder: (_) => [for (final r in _registers) PopupMenuItem(value: r['id'] as String, child: Text(tr('${r['name']}')))],
        child: Chip(avatar: const Icon(Icons.point_of_sale, size: 18), label: Text(tr('${current['name']}'))),
      );

  Widget _terminal(BuildContext context, Session session, Map<String, dynamic> register, Map<String, dynamic> open) {
    final wide = MediaQuery.of(context).size.width >= 900;
    final topBar = Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: Row(children: [
        _registerMenu(register),
        const SizedBox(width: 8),
        Expanded(
          child: Text(tr('Ouverte ${dateTimeFr(open['opened_at'])} · ${open['user_name'] ?? ''}'),
              overflow: TextOverflow.ellipsis, style: TextStyle(color: Theme.of(context).hintColor)),
        ),
        if (PosPrinter.load().drawerControlled)
          IconButton(tooltip: tr('Ouvrir le tiroir-caisse'), onPressed: _kickDrawer, icon: const Icon(Icons.inbox_outlined)),
        ListenableBuilder(
          listenable: CustomerDisplay.instance,
          builder: (context, _) => IconButton(
            tooltip: tr('Écran client'),
            onPressed: () async {
              await showDialog<void>(context: context, builder: (_) => const CustomerDisplayDialog());
              _displaySig = null;
              if (mounted) setState(() {});
              _searchFocus.requestFocus();
            },
            icon: Icon(CustomerDisplay.instance.running ? Icons.connected_tv : Icons.tv_off_outlined),
            color: CustomerDisplay.instance.running ? YColors.success : null,
          ),
        ),
        IconButton(tooltip: tr('Imprimante de caisse'), onPressed: _printerSettings, icon: const Icon(Icons.print_outlined)),
        IconButton(tooltip: tr('Tickets de la session'), onPressed: () => _history(register, open), icon: const Icon(Icons.receipt_long_outlined)),
        const SizedBox(width: 4),
        OutlinedButton.icon(onPressed: () => _closeSession(register, open), icon: const Icon(Icons.lock_outline, size: 18), label: Text(tr('Clôturer'))),
      ]),
    );

    final catalog = _Catalog(
      search: _search,
      focus: _searchFocus,
      category: _category,
      currency: session.currency,
      onCategory: (c) => setState(() => _category = c),
      onSearch: () => setState(() {}),
      onScan: _onScan,
      onAdd: _add,
    );

    final cart = _CartPanel(
      lines: _cart,
      customer: _customer,
      currency: session.currency,
      onChanged: () => setState(() {}),
      onCustomer: _pickCustomer,
      onClear: () => setState(_cart.clear),
      onCheckout: () => _checkout(register, open),
      total: _total,
    );

    if (wide) {
      return Column(children: [
        topBar,
        Expanded(
          child: Row(children: [
            Expanded(child: catalog),
            SizedBox(width: 400, child: Padding(padding: const EdgeInsets.fromLTRB(0, 0, 16, 16), child: cart)),
          ]),
        ),
      ]);
    }

    return Column(children: [
      topBar,
      Expanded(child: catalog),
      SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: FilledButton(
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
            onPressed: _cart.isEmpty
                ? null
                : () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (c) => Scaffold(
                        appBar: AppBar(title: Text(tr('Panier'))),
                        body: StatefulBuilder(
                          builder: (c, setS) => Padding(
                            padding: const EdgeInsets.all(12),
                            child: _CartPanel(
                              lines: _cart,
                              customer: _customer,
                              currency: session.currency,
                              total: _total,
                              onChanged: () {
                                setS(() {});
                                setState(() {});
                              },
                              onCustomer: () async {
                                await _pickCustomer();
                                setS(() {});
                              },
                              onClear: () {
                                setState(_cart.clear);
                                Navigator.pop(c);
                              },
                              onCheckout: () async {
                                Navigator.pop(c);
                                await _checkout(register, open);
                              },
                            ),
                          ),
                        ),
                      ),
                    )),
            child: Text(tr('Panier · ${qty(_count)} article(s) · ${money(_total, session.currency)}')),
          ),
        ),
      ),
    ]);
  }
}

/// Encaissement d'un ticket de caisse : paiement, enregistrement hors ligne, tiroir, impression.
/// Utilisé par la caisse et par le restaurant (addition). Retourne le ticket enregistré.
Future<Map<String, dynamic>?> posCheckout(
BuildContext context, {
required Map<String, dynamic> register,
required Map<String, dynamic> session,
required List<Map<String, dynamic>> lines,
Map<String, dynamic>? customer,
Map<String, dynamic> extra = const {},
int guests = 1,
String? title,
VoidCallback? onSaved,
}) async {
if (lines.isEmpty) return null;
final computed = [for (final l in lines) {...l, ...computeLine(l)..remove('gross')}];
final total0 = computed.fold<double>(0, (s, l) => s + toDouble(l['total']));
  final result = await showDialog<_PaymentResult>(context: context, barrierDismissible: false, builder: (_) => _PaymentDialog(total: total0, partnerId: customer?['id'] as String?, guests: guests, title: title));
  if (result == null || !context.mounted) return null;

  final sessionState = context.read<Session>();
  final sync = context.read<SyncEngine>();

  // Numéro provisoire propre à la caisse (le serveur attribue le numéro définitif)
  final counterKey = 'pos_counter_${register['id']}';
  final n = ((LocalDb.meta.get(counterKey) as int?) ?? 0) + 1;
  await LocalDb.meta.put(counterKey, n);
  final reference = '${register['code'] ?? 'C'}-${n.toString().padLeft(6, '0')}';

  double sum(String k) => computed.fold(0, (s, l) => s + toDouble(l[k]));
  final total = sum('total');
  final doc = await sync.save('sales_documents', {
    'type': 'receipt',
    'status': 'validated',
    'date': isoDate(DateTime.now()),
    'reference': reference,
    'pos_session_id': session['id'],
    'warehouse_id': register['warehouse_id'],
    'branch_id': ?register['branch_id'],
    'partner_id': customer?['id'],
    'partner_name': customer?['name'],
    ...extra,
    'lines': computed,
    'subtotal': sum('subtotal'),
    'tax_total': sum('tax_amount'),
    'total': total,
  });

  for (final p in result.payments) {
    await sync.save('sales_payments', {
      'document_id': doc['id'],
      'date': isoDate(DateTime.now()),
      'amount': p['amount'],
      'method': p['method'],
      if (p['reference'] != null) 'reference': p['reference'],
    });
  }
  final paid = result.payments.fold<double>(0, (s, p) => s + toDouble(p['amount']));
  final localDoc = {...doc, 'amount_paid': paid, 'payment_status': paid + 0.009 >= total ? 'paid' : 'partial', 'created_at': DateTime.now().toIso8601String()};
  await LocalDb.put('sales_documents', localDoc);

  onSaved?.call();
  if (!context.mounted) return localDoc;

  CustomerDisplay.instance.showPaid(
    total: money(total, sessionState.currency),
    change: result.change > 0 ? money(result.change, sessionState.currency) : null,
  );

  // Tiroir-caisse et impression automatique
  final printer = PosPrinter.load();
  final cashUsed = result.payments.any((p) => p['method'] == 'cash');
  Future<void> doPrint({bool drawer = false}) async {
    final warn = await PosPrinter.printReceipt(
      session: sessionState,
      doc: localDoc,
      payments: result.payments,
      change: result.change,
      registerName: '${register['name']}',
      openDrawer: drawer,
      config: printer,
    );
    if (warn != null && context.mounted) toast(context, warn, error: true);
  }

  if (printer.autoPrint) {
    await doPrint(drawer: cashUsed);
  } else if (cashUsed && printer.drawerControlled && printer.openDrawer) {
    try {
      await PosPrinter.openDrawer(printer);
    } on PrintFailure catch (e) {
      if (context.mounted) toast(context, tr('Tiroir : ${e.message}'), error: true);
    }
  }
  if (!context.mounted) return localDoc;

  final print = await showDialog<bool>(
    context: context,
    builder: (c) => YAlertDialog(
      icon: const Icon(Icons.check_circle, color: YColors.success, size: 48),
      title: Text(tr('Ticket $reference')),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text(tr(money(total, sessionState.currency)), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800)),
        if (result.change > 0) ...[
          const SizedBox(height: 12),
          Text(tr('Monnaie à rendre : ${money(result.change, sessionState.currency)}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: YColors.blue)),
        ],
      ]),
      actions: [
        OutlinedButton.icon(
          onPressed: () => Navigator.pop(c, true),
          icon: const Icon(Icons.print_outlined),
          label: Text(tr(printer.autoPrint ? 'Réimprimer' : 'Imprimer')),
        ),
        FilledButton(autofocus: true, onPressed: () => Navigator.pop(c, false), child: Text(tr('Nouvelle vente'))),
      ],
    ),
  );
  if (print == true) await doPrint();
  return localDoc;
}


Widget _kv(String k, String v, {bool bold = false}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(children: [
        Expanded(child: Text(tr(k), style: TextStyle(fontWeight: bold ? FontWeight.w700 : null))),
        Text(tr(v), style: TextStyle(fontWeight: bold ? FontWeight.w700 : null)),
      ]),
    );

class _Catalog extends StatelessWidget {
  const _Catalog({
    required this.search,
    required this.focus,
    required this.category,
    required this.currency,
    required this.onCategory,
    required this.onSearch,
    required this.onScan,
    required this.onAdd,
  });

  final TextEditingController search;
  final FocusNode focus;
  final String? category;
  final String currency;
  final ValueChanged<String?> onCategory;
  final VoidCallback onSearch;
  final ValueChanged<String> onScan;
  final ValueChanged<Map<String, dynamic>> onAdd;

  @override
  Widget build(BuildContext context) {
    final categories = LocalDb.all('product_categories')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final q = search.text.trim().toLowerCase();
    final stock = StockCalc.totals();
    final products = LocalDb.all('products')
        .where((p) => p['active'] != false && p['is_template'] != true && toDouble(p['sale_price']) > 0) // matières premières (prix 0) : lisibles au scanner, absentes de la grille
        .where((p) => category == null || p['category_id'] == category)
        .where((p) => q.isEmpty || '${p['name']} ${p['sku'] ?? ''} ${p['barcode'] ?? ''}'.toLowerCase().contains(q))
        .toList()
      ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));

    return Column(children: [
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: TextField(
          controller: search,
          focusNode: focus,
          autofocus: true,
          decoration: InputDecoration(prefixIcon: Icon(Icons.qr_code_scanner), hintText: tr('Scanner un code-barres ou rechercher…')),
          onChanged: (_) => onSearch(),
          onSubmitted: onScan,
        ),
      ),
      if (categories.isNotEmpty)
        SizedBox(
          height: 48,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.fromLTRB(16, 8, 16, 0), children: [
            Padding(padding: const EdgeInsets.only(right: 8), child: ChoiceChip(label: Text(tr('Tout')), selected: category == null, onSelected: (_) => onCategory(null))),
            for (final c in categories)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(label: Text(tr('${c['name']}')), selected: category == c['id'], onSelected: (_) => onCategory(c['id'] as String)),
              ),
          ]),
        ),
      const SizedBox(height: 8),
      Expanded(
        child: products.isEmpty
            ? EmptyState(icon: Icons.sell_outlined, title: tr('Aucun produit'), message: tr('Ajoutez des produits dans « Produits & services ».'))
            : GridView.builder(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
                gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(maxCrossAxisExtent: 178, mainAxisExtent: 136, crossAxisSpacing: 12, mainAxisSpacing: 12),
                itemCount: products.length,
                itemBuilder: (_, i) {
                  final p = products[i];
                  final ttc = toDouble(p['sale_price']) * (1 + toDouble(p['tax_rate']) / 100);
                  final tracked = p['type'] != 'service' && p['track_stock'] != false;
                  final s = stock[p['id']] ?? 0;
                  final img = p['image'] is String && '${p['image']}'.startsWith('http') ? '${p['image']}' : null;
                  return Material(
                    color: Theme.of(context).colorScheme.surface,
                    borderRadius: BorderRadius.circular(14),
                    elevation: 0,
                    child: InkWell(
                      borderRadius: BorderRadius.circular(14),
                      onTap: () => onAdd(p),
                      child: Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: const Color(0xFFE8ECF4)),
                          boxShadow: const [BoxShadow(color: Color(0x0A0B1B3A), blurRadius: 6, offset: Offset(0, 2))],
                        ),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            img != null
                                ? ClipRRect(borderRadius: BorderRadius.circular(9), child: Image.network(img, width: 34, height: 34, fit: BoxFit.cover, errorBuilder: (_, _, _) => InitialsAvatar('${p['name']}', size: 34)))
                                : InitialsAvatar('${p['name']}', size: 34),
                            const Spacer(),
                            if (tracked)
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(color: (s <= 0 ? YColors.danger : const Color(0xFF64748B)).withValues(alpha: .10), borderRadius: BorderRadius.circular(6)),
                                child: Text(tr(s <= 0 ? 'Rupture' : qty(s)), style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: s <= 0 ? YColors.danger : const Color(0xFF475569))),
                              ),
                          ]),
                          const SizedBox(height: 8),
                          Expanded(child: Text(tr('${p['name']}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, height: 1.25, fontSize: 13.5))),
                          Text(tr(money(ttc, currency)), style: TextStyle(fontWeight: FontWeight.w800, color: Theme.of(context).colorScheme.primary, letterSpacing: -.2)),
                        ]),
                      ),
                    ),
                  );
                },
              ),
      ),
    ]);
  }
}

class _CartPanel extends StatelessWidget {
  const _CartPanel({
    required this.lines,
    required this.customer,
    required this.currency,
    required this.total,
    required this.onChanged,
    required this.onCustomer,
    required this.onClear,
    required this.onCheckout,
  });

  final List<CartLine> lines;
  final Map<String, dynamic>? customer;
  final String currency;
  final double total;
  final VoidCallback onChanged;
  final VoidCallback onCustomer;
  final VoidCallback onClear;
  final VoidCallback onCheckout;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Column(children: [
        ListTile(
          leading: const Icon(Icons.person_outline),
          title: Text(tr(customer?['name'] ?? 'Client comptoir')),
          trailing: const Icon(Icons.chevron_right),
          onTap: onCustomer,
        ),
        if (customer != null) Padding(padding: const EdgeInsets.fromLTRB(12, 0, 12, 4), child: Align(alignment: Alignment.centerLeft, child: LoyaltyBadge(partnerId: '${customer!['id']}'))),
        const Divider(),
        Expanded(
          child: lines.isEmpty
              ? Center(child: Text(tr('Touchez un produit pour l\'ajouter'), style: TextStyle(color: Theme.of(context).hintColor)))
              : ListView.separated(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  itemCount: lines.length,
                  separatorBuilder: (_, _) => const Divider(indent: 12, endIndent: 12),
                  itemBuilder: (_, i) {
                    final l = lines[i];
                    return Padding(
                      padding: const EdgeInsets.fromLTRB(12, 4, 4, 4),
                      child: Row(children: [
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(tr(l.label), style: const TextStyle(fontWeight: FontWeight.w600)),
                            Text(tr('${money(l.unitTtc, currency)}${l.priceLabel != null && !l.manualDiscount ? ' · ${l.priceLabel}' : (l.discount > 0 ? ' · remise ${number(l.discount)} %' : '')}'),
                                style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                          ]),
                        ),
                        IconButton(
                          visualDensity: VisualDensity.compact,
                          icon: const Icon(Icons.remove_circle_outline),
                          onPressed: () {
                            l.quantity > 1 ? l.quantity-- : lines.removeAt(i);
                            onChanged();
                          },
                        ),
                        InkWell(
                          onTap: () async {
                            final v = await _askNumber(context, 'Quantité', l.quantity);
                            if (v != null) {
                              v <= 0 ? lines.removeAt(i) : l.quantity = v;
                              onChanged();
                            }
                          },
                          child: SizedBox(width: 36, child: Text(tr(qty(l.quantity)), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
                        ),
                        IconButton(
                          visualDensity: VisualDensity.compact,
                          icon: const Icon(Icons.add_circle_outline),
                          onPressed: () {
                            l.quantity++;
                            onChanged();
                          },
                        ),
                        SizedBox(width: 92, child: Text(tr(money(l.total, currency)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
                        PopupMenuButton<String>(
                          padding: EdgeInsets.zero,
                          onSelected: (v) async {
                            if (v == 'remove') lines.removeAt(i);
                            if (v == 'discount') {
                              final d = await _askNumber(context, 'Remise (%)', l.discount);
                              if (d != null) {
                                l.discount = d.clamp(0, 100).toDouble();
                                l.manualDiscount = true;
                              }
                            }
                            onChanged();
                          },
                          itemBuilder: (_) => [
                            PopupMenuItem(value: 'discount', child: Text(tr('Remise'))),
                            PopupMenuItem(value: 'remove', child: Text(tr('Retirer'))),
                          ],
                        ),
                      ]),
                    );
                  },
                ),
        ),
        const Divider(),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(children: [
            Row(children: [
              Text(tr('Total'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              const Spacer(),
              Text(tr(money(total, currency)), style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              IconButton.outlined(tooltip: tr('Vider le panier'), onPressed: lines.isEmpty ? null : onClear, icon: const Icon(Icons.delete_outline)),
              const SizedBox(width: 8),
              Expanded(
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56), backgroundColor: YColors.success),
                  onPressed: lines.isEmpty ? null : onCheckout,
                  icon: const Icon(Icons.payments_outlined),
                  label: Text(tr('Encaisser'), style: TextStyle(fontSize: 18)),
                ),
              ),
            ]),
          ]),
        ),
      ]),
    );
  }
}

Future<double?> _askNumber(BuildContext context, String label, double current) async {
  final c = TextEditingController(text: qty(current).replaceAll(' ', '').replaceAll(' ', ''));
  final r = await showDialog<bool>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Text(tr(label)),
      content: TextField(controller: c, autofocus: true, keyboardType: const TextInputType.numberWithOptions(decimal: true), onSubmitted: (_) => Navigator.pop(d, true)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))),
        FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('OK'))),
      ],
    ),
  );
  return r == true ? toDouble(c.text) : null;
}

class _PaymentResult {
  _PaymentResult(this.payments, this.change);

  final List<Map<String, dynamic>> payments;
  final double change;
}

/// Encaissement : espèces avec rendu de monnaie, Mobile Money, carte, ou paiement mixte.
class _PaymentDialog extends StatefulWidget {
  const _PaymentDialog({required this.total, this.partnerId, this.guests = 1, this.title});

  final double total;
  final String? partnerId;
  final int guests;
  final String? title;

  @override
  State<_PaymentDialog> createState() => _PaymentDialogState();
}

class _PaymentDialogState extends State<_PaymentDialog> {
  final _payments = <Map<String, dynamic>>[];
  final _amount = TextEditingController();
  final _ref = TextEditingController();
  String _method = 'cash';
  double _change = 0;

  double get _paid => _payments.fold(0, (s, p) => s + toDouble(p['amount']));
  double get _remaining => (widget.total - _paid).clamp(0, double.infinity).toDouble();

  @override
  void initState() {
    super.initState();
    _amount.text = '${widget.total.round()}';
  }

  Map<String, dynamic>? get _card => widget.partnerId == null ? null : loyaltyCardOf(widget.partnerId);

  /// Valeur des points encore disponibles (déduction faite des paiements déjà ajoutés).
  double get _loyaltyAvailable {
    final c = _card;
    if (c == null || c['status'] == 'blocked') return 0;
    final s = context.read<Session>();
    final used = _payments.where((p) => p['method'] == 'loyalty').fold<double>(0, (t, p) => t + toDouble(p['amount']));
    return (((c['points'] as num?) ?? 0) * fidPointValue(s) - used).clamp(0, double.infinity).toDouble();
  }

  double _giftAvailable(String code) {
    final g = giftCardByCode(code);
    if (g == null || !giftUsable(g)) return -1;
    final used = _payments.where((p) => p['method'] == 'gift_card' && '${p['reference']}'.toUpperCase() == '${g['code']}'.toUpperCase()).fold<double>(0, (t, p) => t + toDouble(p['amount']));
    return toDouble(g['balance']) - used;
  }

  /// Paiement Mobile Money direct : demande sur le téléphone du client ou QR code, ajouté dès la confirmation.
  Future<void> _collect() async {
    final want = toDouble(_amount.text);
    final amount = want > 0 && want < _remaining ? want : _remaining;
    final r = await showMobileMoneyCollect(context, amount: amount, label: widget.title ?? 'Vente en caisse');
    if (r == null || !mounted) return;
    setState(() {
      _payments.add({'method': 'mobile_money', 'amount': double.parse(r.amount.toStringAsFixed(2)), 'reference': r.reference});
      _amount.text = _remaining > 0 ? '${_remaining.round()}' : '';
      _ref.clear();
    });
    if (_remaining <= 0.009) Navigator.pop(context, _PaymentResult(_payments, _change));
  }

  void _selectMethod(String m) {
    setState(() {
      _method = m;
      if (m == 'loyalty') {
        final max = _loyaltyAvailable < _remaining ? _loyaltyAvailable : _remaining;
        _amount.text = '${max.floor()}';
      }
    });
  }

  void _addPayment() {
    final given = toDouble(_amount.text);
    if (given <= 0 || _remaining <= 0) return;
    final applied = given > _remaining ? _remaining : given;
    if (given > _remaining && _method != 'cash') {
      toast(context, tr('Seules les espèces peuvent dépasser le montant dû.'), error: true);
      return;
    }
    if (_method == 'loyalty') {
      final s = context.read<Session>();
      final pts = ((_card?['points'] as num?) ?? 0).toInt();
      if (pts < fidMinRedeem(s)) {
        toast(context, tr('Il faut au moins ${fidMinRedeem(s)} points pour payer en points (solde : $pts).'), error: true);
        return;
      }
      if (applied > _loyaltyAvailable + 0.009) {
        toast(context, tr('Points insuffisants : ${money(_loyaltyAvailable, s.currency)} disponibles.'), error: true);
        return;
      }
      _ref.text = '${_card?['number'] ?? ''}';
    }
    if (_method == 'gift_card') {
      final code = _ref.text.trim().toUpperCase();
      final avail = code.isEmpty ? -1.0 : _giftAvailable(code);
      if (avail < 0) {
        toast(context, tr('Carte cadeau inconnue, expirée ou épuisée.'), error: true);
        return;
      }
      if (applied > avail + 0.009) {
        toast(context, tr('Solde de la carte insuffisant : ${money(avail, context.read<Session>().currency)}.'), error: true);
        return;
      }
      _ref.text = code;
    }
    setState(() {
      if (_method == 'cash' && given > applied) _change = given - applied;
      _payments.add({'method': _method, 'amount': double.parse(applied.toStringAsFixed(2)), if (_ref.text.trim().isNotEmpty) 'reference': _ref.text.trim()});
      _amount.text = _remaining > 0 ? '${_remaining.round()}' : '';
      _ref.clear();
    });
  }

  /// Valide si le montant saisi couvre le reste à payer (Entrée = encaisser).
  void _validate() {
    if (_remaining > 0.009) _addPayment();
    if (_remaining <= 0.009) Navigator.pop(context, _PaymentResult(_payments, _change));
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final given = toDouble(_amount.text);
    final preview = _method == 'cash' && given > _remaining ? given - _remaining : 0.0;
    final quick = <double>{widget.total.ceilToDouble(), 1000, 2000, 5000, 10000, 20000}.where((v) => v >= _remaining).take(5).toList()..sort();

    return YAlertDialog(
      title: Text(tr(widget.title != null ? '${widget.title} · ${money(widget.total, cur)}' : 'Encaisser ${money(widget.total, cur)}')),
      content: SizedBox(
        width: 440,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (_payments.isNotEmpty) ...[
              for (final p in _payments) _kv(paymentMethods[p['method']] ?? '${p['method']}', money(toDouble(p['amount']), cur)),
              _kv('Reste à payer', money(_remaining, cur), bold: true),
              const Divider(height: 20),
            ],
            if (_remaining > 0) ...[
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final m in [
                  'cash',
                  'mobile_money',
                  'card',
                  'cheque',
                  if (fidActive(context.read<Session>())) ...[if (_card != null && _loyaltyAvailable > 0) 'loyalty', 'gift_card'],
                ])
                  ChoiceChip(label: Text(tr(paymentMethods[m]!)), selected: _method == m, onSelected: (_) => _selectMethod(m)),
              ]),
              if (_method == 'loyalty')
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(tr('${_card?['points'] ?? 0} points · jusqu\'à ${money(_loyaltyAvailable, cur)} utilisables'), style: const TextStyle(color: YColors.warning, fontWeight: FontWeight.w600)),
                ),
              if (widget.guests > 1 && _remaining > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Wrap(spacing: 6, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    Text(tr('Partager en parts égales :'), style: TextStyle(color: Theme.of(context).hintColor)),
                    for (final n in {2, 3, 4, widget.guests}.where((n) => n > 1 && n <= 20).toList()..sort())
                      ActionChip(
                        label: Text(tr('1/$n = ${money((widget.total / n).ceilToDouble(), cur)}')),
                        onPressed: () => setState(() {
                          final part = (widget.total / n).ceilToDouble();
                          _amount.text = '${(part < _remaining ? part : _remaining).round()}';
                        }),
                      ),
                  ]),
                ),
              const SizedBox(height: 12),
              TextField(
                controller: _amount,
                autofocus: true,
                style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w700),
                decoration: InputDecoration(labelText: trn(_method == 'cash' ? 'Montant reçu' : 'Montant'), suffixText: trn(cur == 'XOF' ? 'F CFA' : cur)),
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
                onChanged: (_) => setState(() {}),
                onSubmitted: (_) => toDouble(_amount.text) + 0.009 >= _remaining ? _validate() : _addPayment(),
              ),
              if (_method == 'cash') ...[
                const SizedBox(height: 8),
                Wrap(spacing: 6, children: [
                  for (final v in quick) ActionChip(label: Text(tr(number(v))), onPressed: () => setState(() => _amount.text = '${v.round()}')),
                ]),
              ],
              if (_method == 'gift_card') ...[
                const SizedBox(height: 8),
                TextField(
                  controller: _ref,
                  textCapitalization: TextCapitalization.characters,
                  decoration: InputDecoration(
                    labelText: tr('Code de la carte cadeau'),
                    helperText: trn(_ref.text.trim().length >= 6
                        ? (_giftAvailable(_ref.text) < 0 ? 'Carte inconnue, expirée ou épuisée' : 'Solde : ${money(_giftAvailable(_ref.text), cur)}')
                        : null),
                  ),
                  onChanged: (_) => setState(() {}),
                ),
              ] else if (_method != 'cash' && _method != 'loyalty') ...[
                const SizedBox(height: 8),
                TextField(controller: _ref, decoration: InputDecoration(labelText: tr('N° de transaction (facultatif)'))),
                if (_method == 'mobile_money' && context.read<SyncEngine>().online)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: OutlinedButton.icon(
                      onPressed: _collect,
                      icon: const Icon(Icons.send_to_mobile),
                      label: Text(tr('Demander le paiement (téléphone du client ou QR code)')),
                    ),
                  ),
              ],
              if (preview > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Text(tr('Monnaie à rendre : ${money(preview, cur)}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: YColors.blue)),
                ),
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: given > 0 && given < _remaining ? _addPayment : null,
                  icon: const Icon(Icons.call_split),
                  label: Text(tr('Paiement partiel puis autre moyen')),
                ),
              ),
            ] else if (_change > 0)
              Text(tr('Monnaie à rendre : ${money(_change, cur)}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: YColors.blue)),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: YColors.success),
          autofocus: _remaining <= 0.009,
          onPressed: (_remaining <= 0.009 || given + 0.009 >= _remaining) ? _validate : null,
          child: Text(tr('Valider le paiement')),
        ),
      ],
    );
  }
}
