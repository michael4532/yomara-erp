import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/fne.dart';
import '../../core/fx.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../pdf/document_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/add_to_report.dart';
import '../widgets/common.dart';
import '../widgets/mobile_money_collect.dart';
import 'documents_screen.dart';
import 'messaging_settings_screen.dart';
import 'achats_screen.dart' show PurchaseOrderPanel, purchaseApprovalLimit;
import 'logistique_screen.dart' show editDelivery;
import 'partners_screen.dart';
import 'tresorerie_screen.dart' show trActive, trAccounts, trDefaults;
import '../../core/approvals.dart';
import '../../core/vat.dart';
import 'products_screen.dart' show editProduct;
import '../../core/custom_fields.dart';
import '../widgets/custom_fields_editor.dart';
import 'approbations_screen.dart' show ApprovalBanner;
import '../widgets/payment_terms.dart';
import '../widgets/project_picker.dart';
import 'analytique_screen.dart' show AnalyticField, analyticEnabled, analyticSummary, anDist, editDistribution;
import 'fne_screen.dart' show FnePanel;
import 'immobilisations_screen.dart' show assetsFromInvoice;
import 'signature_screen.dart' show newSignatureRequest;
import '../../core/lots.dart';
import '../../core/pricing.dart';
import '../widgets/lots_dialog.dart';
import 'appro_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const paymentMethods = {
  'cash': 'Espèces',
  'mobile_money': 'Mobile Money',
  'card': 'Carte bancaire',
  'bank_transfer': 'Virement',
  'cheque': 'Chèque',
  'other': 'Autre',
  'loyalty': 'Points fidélité',
  'gift_card': 'Carte cadeau',
};

const _fidMethods = {'loyalty', 'gift_card'};

/// Conditionnement d'une ligne (choisi, ou retrouvé par l'unité d'un document enregistré).
Map<String, dynamic>? linePackaging(Map<String, dynamic>? product, Map<String, dynamic> line) {
  final pks = packagingsOf(product);
  if (line.containsKey('packaging')) return pks.where((e) => e['name'] == line['packaging']).firstOrNull;
  if (toDouble(line['unit_factor']) <= 0 || toDouble(line['unit_factor']) == 1) return null;
  return pks.where((e) => e['name'] == line['unit']).firstOrNull;
}

double _round(double v, int d) => d == 0 ? v.roundToDouble() : (v * 100).roundToDouble() / 100;

/// Calcul identique au serveur (TradeDocumentLine::computeTotals).
/// Devise sans centimes : TTC d'abord, arrondi au franc (prix de rayon respectés).
Map<String, double> computeLine(Map<String, dynamic> l, [int? decimals]) {
  final d = decimals ?? currencyDecimals;
  final net = toDouble(l['quantity']) * toDouble(l['unit_price']) * (1 - toDouble(l['discount_percent']) / 100);
  final rate = toDouble(l['tax_rate']) / 100;
  final gross = _round(toDouble(l['quantity']) * toDouble(l['unit_price']), d);
  if (d == 0) {
    final total = _round(net * (1 + rate), 0);
    final sub = _round(total / (1 + rate), 0);
    return {'gross': gross, 'subtotal': sub, 'tax_amount': total - sub, 'total': total};
  }
  final sub = _round(net, 2);
  final tax = _round(sub * rate, 2);
  return {'gross': gross, 'subtotal': sub, 'tax_amount': tax, 'total': _round(sub + tax, 2)};
}

class DocumentEditor extends StatefulWidget {
  const DocumentEditor({super.key, required this.config, this.document, required this.initialType});

  final DocConfig config;
  final Map<String, dynamic>? document;
  final String initialType;

  @override
  State<DocumentEditor> createState() => _DocumentEditorState();
}

const _termTypes = ['quote', 'order', 'invoice', 'purchase_order', 'supplier_invoice'];

/// Documents qui portent un mode de règlement et la case « Pour décharge ».
const _payModeTypes = ['quote', 'order', 'delivery', 'invoice', 'proforma'];

class _DocumentEditorState extends State<DocumentEditor> {
  bool _termChosen = false; // condition choisie à la main : le changement de tiers ne la remplace plus
  late Map<String, dynamic> _doc;
  late List<Map<String, dynamic>> _lines;
  final _notes = TextEditingController();
  final _reference = TextEditingController();
  bool _dirty = false;
  Map<String, dynamic>? _customM;
  bool _customDirty = false;
  Map<String, dynamic> get _custom => _customM ??= asMap(_doc['custom']);

  DocConfig get cfg => widget.config;

  /// Devise du document (celle de la société par défaut).
  String get _cur => '${_doc['currency'] ?? context.read<Session>().currency}';
  bool get _foreign => _cur != context.read<Session>().currency;

  void _setCurrency(String c) {
    final base = context.read<Session>().currency;
    setState(() {
      _doc['currency'] = c;
      _doc['exchange_rate'] = localRate(base, c, '${_doc['date']}');
      _rate.text = _doc['exchange_rate'] == null ? '' : '${_doc['exchange_rate']}';
      _dirty = true;
    });
  }

  final _rate = TextEditingController();
  bool get _isNew => widget.document == null;

  @override
  void initState() {
    super.initState();
    _doc = widget.document != null
        ? Map<String, dynamic>.from(widget.document!)
        : {'id': newId(), 'type': widget.initialType, 'status': 'draft', 'date': isoDate(DateTime.now())};
    _doc['currency'] ??= context.read<Session>().currency;
    // Multi-agences : nouveau document rattaché à l'agence de travail, avec son dépôt
    if (widget.document == null) {
      final b = context.read<Session>().currentBranch;
      if (b != null) {
        _doc['branch_id'] = b['id'];
        _doc['warehouse_id'] ??= b['warehouse_id'];
      }
    }
    _doc['exchange_rate'] ??= 1;
    if (_doc['currency'] != context.read<Session>().currency) _rate.text = '${_doc['exchange_rate']}';
    _lines = List<Map<String, dynamic>>.from((_doc['lines'] as List? ?? []).map((l) => Map<String, dynamic>.from(l)));
    _notes.text = '${_doc['notes'] ?? ''}';
    _reference.text = '${_doc['reference'] ?? ''}';
  }

  /// Signature électronique du document par le client (devis signé : commande créée automatiquement).
  Future<void> _sign(Session session, List<Map<String, dynamic>> payments) async {
    final partner = LocalDb.get('partners', _doc['partner_id'] as String?) ?? const {};
    final label = cfg.types[_doc['type']] ?? 'Document';
    try {
      final bytes = await buildDocumentPdf(session: session, config: cfg, doc: {..._doc, ..._payload(), 'status': _doc['status'], 'number': _doc['number']}, payments: payments);
      if (!mounted) return;
      await newSignatureRequest(
        context,
        bytes: bytes,
        fileName: '$label ${_doc['number'] ?? ''}.pdf'.trim(),
        title: '$label ${_doc['number'] ?? ''}',
        sourceType: 'sales_document',
        sourceId: '${_doc['id']}',
        signers: [
          {'name': '${_doc['partner_name'] ?? partner['name'] ?? ''}', 'label': _doc['type'] == 'quote' ? 'Bon pour accord — le client' : 'Pour le client', 'email': partner['email'], 'phone': partner['phone']},
        ],
      );
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  /// Lien public de paiement en ligne de la facture (copiable, partageable par WhatsApp / e-mail).
  Future<void> _payLink() async {
    final api = context.read<Session>().api;
    try {
      final r = await api.post('payments/pay-link/${_doc['id']}');
      if (!mounted) return;
      final url = '${r['url']}';
      _doc['pay_url'] = url;
      await showDialog(
        context: context,
        builder: (c) => YAlertDialog(
          title: Text(tr('Lien de paiement')),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            SelectableText(tr(url), style: const TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 10),
            Text(
              tr((r['online_methods'] as num? ?? 0) > 0
                  ? 'Votre client paie par Mobile Money ou carte ; le règlement s\'inscrit automatiquement sur la facture. Le lien figure aussi sur le PDF.'
                  : 'Aucun moyen de paiement en ligne configuré : la page affichera seulement vos coordonnées bancaires. Configurez vos comptes dans Paramètres › Encaissements.'),
              style: TextStyle(color: Theme.of(c).hintColor),
            ),
          ]),
          actions: [
            TextButton(
              onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent('Bonjour, voici le lien pour régler la facture ${_doc['number']} : $url')}'), mode: LaunchMode.externalApplication),
              child: Text(tr('Envoyer par WhatsApp')),
            ),
            TextButton(onPressed: () => Clipboard.setData(ClipboardData(text: url)), child: Text(tr('Copier'))),
            FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
          ],
        ),
      );
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  /// Envoi du document au client (PDF joint par e-mail ou WhatsApp, lien par SMS).
  Future<void> _sendDoc(Session session, List<Map<String, dynamic>> payments) async {
    final partner = LocalDb.get('partners', _doc['partner_id'] as String?) ?? const {};
    final label = cfg.types[_doc['type']] ?? 'Document';
    final number = '${_doc['number'] ?? ''}';
    final company = '${session.company['name'] ?? ''}';
    final total = money(toDouble(_doc['total']), _cur);
    final pay = _doc['pay_url'] != null ? '\nPour régler en ligne : ${_doc['pay_url']}' : '';
    await sendMessageDialog(
      context,
      email: partner['email'] as String?,
      phone: partner['phone'] as String?,
      name: (_doc['partner_name'] ?? partner['name']) as String?,
      subject: '$label $number — $company',
      message: [
        'Bonjour${(partner['name'] ?? _doc['partner_name']) != null ? ' ${_doc['partner_name'] ?? partner['name']}' : ''},',
        '',
        'Veuillez trouver ci-joint notre ${label.toLowerCase()} n° $number${_doc['date'] != null ? ' du ${dateFr(_doc['date'])}' : ''}, d\'un montant de $total TTC.',
        if (_doc['type'] == 'quote' && _doc['valid_until'] != null) 'Cette offre est valable jusqu\'au ${dateFr(_doc['valid_until'])}.',
        if (_doc['type'] == 'invoice' && _doc['due_date'] != null) 'Échéance de paiement : ${dateFr(_doc['due_date'])}.',
        if (pay.isNotEmpty) pay.trim(),
        '',
        _doc['type'] == 'quote' ? 'Pour donner votre accord, il vous suffit de répondre à ce message ou de nous retourner le devis signé.' : 'Nous restons à votre disposition pour toute question.',
        '',
        'Cordialement,',
        '${session.user['name'] ?? ''}',
        company,
      ].join('\n'),
      attachmentName: '${label.replaceAll(' ', '_')}_${number.replaceAll('/', '-')}.pdf',
      attachment: () => buildDocumentPdf(session: session, config: cfg, doc: {..._doc, ..._payload(), 'status': _doc['status'], 'number': _doc['number']}, payments: payments),
      contextRef: 'doc:${_doc['id']}',
    );
  }

  bool _editable(Session s) => _doc['status'] == 'draft' && s.can(cfg.managePerm);

  Map<String, double> get _totals {
    double sub = 0, disc = 0, tax = 0, total = 0;
    for (final l in _lines) {
      final c = computeLine(l, decimalsFor(_cur));
      sub += c['subtotal']!;
      disc += (c['gross']! - c['subtotal']!).clamp(0, double.infinity);
      tax += c['tax_amount']!;
      total += c['total']!;
    }
    return {'subtotal': sub, 'discount_total': disc, 'tax_total': tax, 'total': total};
  }

  Map<String, dynamic> _payload({String? status}) {
    final t = _totals;
    return {
      'id': _doc['id'],
      'type': _doc['type'],
      'partner_id': _doc['partner_id'],
      'partner_name': _doc['partner_name'],
      'date': _doc['date'],
      'due_date': _doc['due_date'],
      'warehouse_id': _doc['warehouse_id'],
      'branch_id': ?_doc['branch_id'],
      'currency': _cur,
      if (_foreign && _doc['exchange_rate'] != null) 'exchange_rate': _doc['exchange_rate'],
      'source_id': _doc['source_id'],
      if (projectsEnabled(context.read<Session>())) 'project_id': _doc['project_id'],
      if (ptAvailable() && _termTypes.contains(_doc['type'])) 'payment_term_id': _doc['payment_term_id'],
      'reference': _reference.text.trim().isEmpty ? null : _reference.text.trim(),
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'payment_method': _doc['payment_method'],
      'receipt_box': _doc['receipt_box'],
      if (analyticEnabled(context.read<Session>())) 'analytic': anDist(_doc['analytic']).isEmpty ? null : _doc['analytic'],
      if (context.read<Session>().hasModule('champs')) 'custom': _custom,
      'lines': [
        for (final l in _lines)
          {
            'id': l['id'] ?? newId(),
            'product_id': l['product_id'],
            'description': l['description'],
            'quantity': toDouble(l['quantity']),
            'unit': l['unit'],
            if (toDouble(l['unit_factor']) > 0 && toDouble(l['unit_factor']) != 1) 'unit_factor': toDouble(l['unit_factor']),
            if ((l['lots'] as List?)?.isNotEmpty == true) 'lots': l['lots'],
            'unit_price': toDouble(l['unit_price']),
            'discount_percent': toDouble(l['discount_percent']),
            'tax_rate': toDouble(l['tax_rate']),
            if (anDist(l['analytic']).isNotEmpty) 'analytic': l['analytic'],
            ...computeLine(l, decimalsFor(_cur))..remove('gross'),
          },
      ],
      ...t,
      'status': ?status,
    };
  }

  Future<void> _save({String? status}) async {
    if (_lines.any((l) => '${l['description'] ?? ''}'.trim().isEmpty)) {
      toast(context, tr('Chaque ligne doit avoir une désignation.'), error: true);
      return;
    }
    final cfErr = context.read<Session>().hasModule('champs') ? customRequiredError(cfg.entity, _custom) : null;
    if (cfErr != null) {
      toast(context, cfErr, error: true);
      return;
    }
    if (status == 'validated') {
      final missing = _missingSerials();
      if (missing != null) {
        toast(context, missing, error: true);
        return;
      }
      if (_lines.isEmpty) {
        toast(context, tr('Ajoutez au moins une ligne avant de valider.'), error: true);
        return;
      }
      if (_doc['partner_id'] == null && !await confirm(context, tr('Aucun ${cfg.partnerLabel.toLowerCase()}'), tr('Valider sans ${cfg.partnerLabel.toLowerCase()} ?'))) return;
      if (!mounted) return;
      // Bon de commande au-delà du seuil : approbation préalable (sauf pour l'approbateur)
      final session = context.read<Session>();
      final limit = purchaseApprovalLimit(session);
      final subtotal = toDouble(_payload()['subtotal'] ?? _doc['subtotal']);
      if (cfg.entity == 'purchase_documents' && _doc['type'] == 'purchase_order' && limit > 0 && subtotal > limit &&
          LocalDb.get(cfg.entity, '${_doc['id']}')?['approved_at'] == null && !session.can('achats.approve')) {
        await _save();
        if (mounted) toast(context, tr('Montant HT au-delà du seuil d\'approbation (${money(limit, session.currency)}) : brouillon enregistré, demandez l\'approbation.'), error: true);
        return;
      }
    }
    final payload = _payload(status: status);
    final saved = await context.read<SyncEngine>().save(cfg.entity, payload);
    if (!mounted) return;
    setState(() {
      _doc = saved;
      _dirty = false;
      _customDirty = false;
    });
    {
      final rule = status == 'validated' ? _approvalRule(payload) : null;
      final session = context.read<Session>();
      toast(
          context,
          rule != null && !approvalImplicitFor(rule, session.user)
              ? 'Circuit « ${rule['name']} » : le document part en approbation et sera validé après accord.'
              : (status == 'validated' ? 'Document validé : le numéro sera attribué à la synchronisation.' : 'Enregistré.'));
    }
  }

  bool get _lotsOn => context.read<Session>().hasModule('lots') && cfg.entity == 'sales_documents' ? !['quote'].contains(_doc['type']) : context.read<Session>().hasModule('lots') && _doc['type'] != 'rfq';

  /// Sens des lots : entrée (achat, avoir) ou sortie (vente).
  bool get _incoming => cfg.entity == 'purchase_documents' || _doc['type'] == 'credit_note';

  /// Numéros de série obligatoires à l'entrée d'une facture fournisseur.
  String? _missingSerials() {
    if (!_lotsOn || cfg.entity != 'purchase_documents' || _doc['type'] != 'supplier_invoice') return null;
    for (final l in _lines) {
      final p = l['product_id'] == null ? null : LocalDb.get('products', '${l['product_id']}');
      if (!isSerial(p)) continue;
      final need = toDouble(l['quantity']) * (toDouble(l['unit_factor']) > 0 ? toDouble(l['unit_factor']) : 1);
      if (specQuantity(l['lots']) + 0.0001 < need) return 'Indiquez les numéros de série de « ${p!['name']} » (${specQuantity(l['lots']).toInt()} sur ${need.toInt()}).';
    }
    return null;
  }

  /// Prix selon la liste de prix du client et le conditionnement (ventes) ; prix d'achat du conditionnement (achats).
  void _reprice(Map<String, dynamic> l, {bool force = false}) {
    if (l['product_id'] == null || (l['_manual'] == true && !force)) return;
    final p = LocalDb.get('products', '${l['product_id']}');
    if (p == null) return;
    final session = context.read<Session>();
    final pk = linePackaging(p, l);
    if (cfg.entity != 'sales_documents' || !session.hasModule('tarifs') || _foreign) {
      if (pk == null && toDouble(l['unit_factor']) <= 1 && !force) return;
      l['unit_price'] = basePrice(p, pk, purchase: cfg.entity != 'sales_documents');
    } else {
      final r = resolvePrice(partnerId: _doc['partner_id'] as String?, product: p, quantity: toDouble(l['quantity']), packaging: pk, date: DateTime.tryParse('${_doc['date']}'));
      l['unit_price'] = r.unitPrice;
      l['discount_percent'] = r.discount;
      l['_price_label'] = r.label;
    }
    l['_manual'] = false;
    l['_px'] = (l['_px'] as int? ?? 0) + 1;
  }

  /// Circuit d'approbation qui s'appliquera à la validation (contrôle local, le serveur fait foi).
  Map<String, dynamic>? _approvalRule(Map<String, dynamic> p) {
    if (!context.read<Session>().hasModule('approbations')) return null;
    final rate = _foreign ? toDouble(_doc['exchange_rate'] ?? 1) : 1.0;
    var disc = 0.0;
    for (final l in _lines) {
      if (toDouble(l['discount_percent']) > disc) disc = toDouble(l['discount_percent']);
    }
    return approvalRuleFor(
      target: '${cfg.entity == 'sales_documents' ? 'sale' : 'purchase'}.${_doc['type']}',
      amount: toDouble(p['subtotal']) * rate,
      discount: disc,
      partnerId: _doc['partner_id'] as String?,
    );
  }

  Future<void> _cancelDoc() async {
    if (!await confirm(context, tr('Annuler ce document ?'), tr('Le stock sera rétabli. Cette action est définitive.'), ok: tr('Annuler le document'), danger: true)) return;
    if (!mounted) return;
    final saved = await context.read<SyncEngine>().save(cfg.entity, {'id': _doc['id'], 'status': 'cancelled'});
    if (mounted) setState(() => _doc = saved);
  }

  Future<void> _deleteDraft() async {
    if (!await confirm(context, tr('Supprimer ce brouillon ?'), tr('Cette action est définitive.'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    if (!_isNew || LocalDb.get(cfg.entity, _doc['id']) != null) {
      await context.read<SyncEngine>().remove(cfg.entity, _doc['id']);
    }
    if (mounted) Navigator.pop(context);
  }

  Future<void> _convert(String type) async {
    final copy = {
      'id': newId(),
      'type': type,
      'status': 'draft',
      'date': isoDate(DateTime.now()),
      'partner_id': _doc['partner_id'],
      'partner_name': _doc['partner_name'],
      'warehouse_id': _doc['warehouse_id'],
      'branch_id': ?_doc['branch_id'],
      'source_id': _doc['id'],
      'project_id': ?_doc['project_id'],
      'notes': _doc['notes'],
      'lines': [for (final l in _lines) {...l, 'id': newId()}],
      ..._totals,
    };
    final saved = await context.read<SyncEngine>().save(cfg.entity, copy);
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => DocumentEditor(config: cfg, document: saved, initialType: type)));
  }

  Future<void> _pickPartner() async {
    final picked = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => _PartnerPicker(cfg: cfg));
    if (picked != null) {
      setState(() {
        _doc['partner_id'] = picked['id'];
        _doc['partner_name'] = picked['name'];
        if (ptAvailable() && _termTypes.contains(_doc['type']) && !_termChosen) {
          _doc['payment_term_id'] = ptDefaultFor(picked['id'], purchase: cfg.entity == 'purchase_documents');
        }
        for (final l in _lines) {
          _reprice(l);
        }
        _dirty = true;
      });
      if (!mounted) return;
      final pc = picked['currency'] as String?;
      if (context.read<Session>().hasModule('devises') && pc != null && pc.isNotEmpty && pc != _cur) _setCurrency(pc);
    }
  }

  Future<void> _pickDate(String field) async {
    final current = DateTime.tryParse('${_doc[field] ?? ''}') ?? DateTime.now();
    final d = await pickDate(context, initialDate: current, firstDate: DateTime(2015), lastDate: DateTime(2100));
    if (d != null) {
      setState(() {
        _doc[field] = isoDate(d);
        _dirty = true;
      });
    }
  }

  Future<void> _addLine([Map<String, dynamic>? product]) async {
    product ??= await showDialog<Map<String, dynamic>>(context: context, builder: (_) => const _ProductPicker());
    if (!mounted) return;
    final session = context.read<Session>();
    final sale = widget.config == DocConfig.sale;
    final taxDefault = sale ? defaultVatRate(session) : toDouble(session.companySettings['tax_rate'] ?? standardVatRate(session));
    if (product != null && product['is_template'] == true) return;
    setState(() {
      _lines.add(product == null || product.isEmpty
          ? {'id': newId(), 'description': '', 'quantity': 1, 'unit_price': 0, 'discount_percent': 0, 'tax_rate': taxDefault}
          : {
              'id': newId(),
              'product_id': product['id'],
              'description': product['name'],
              'unit': product['unit'],
              'quantity': 1,
              'unit_price': widget.config == DocConfig.sale ? toDouble(product['sale_price']) : toDouble(product['purchase_price']),
              'discount_percent': 0,
              'tax_rate': sale && !vatSubject(session) ? 0 : toDouble(product['tax_rate'] ?? taxDefault),
            });
      final pk = product == null ? null : packagingsOf(product).where((e) => e['name'] == product!['_packaging']).firstOrNull;
      if (pk != null) {
        _lines.last.addAll({'packaging': pk['name'], 'unit': pk['name'], 'unit_factor': toDouble(pk['factor'])});
        _reprice(_lines.last, force: true);
      }
      _reprice(_lines.last);
      _dirty = true;
    });
  }

  Future<void> _addPayment(Session session) async {
    final balance = toDouble(_doc['total']) - toDouble(_doc['amount_paid']);
    final r = await showFormDialog<bool>(context, title: tr('Enregistrer un paiement'), builder: (_) => _PaymentForm(doc: _doc, cfg: cfg, balance: balance, currency: _cur));
    if (r == true && mounted) setState(() => _doc = LocalDb.get(cfg.entity, _doc['id']) ?? _doc);
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final editable = _editable(session);
    final wide = MediaQuery.of(context).size.width >= 900;
    final t = _doc['status'] == 'draft' ? _totals : {
      'subtotal': toDouble(_doc['subtotal']),
      'discount_total': toDouble(_doc['discount_total']),
      'tax_total': toDouble(_doc['tax_total']),
      'total': toDouble(_doc['total']),
    };
    final typeLabel = cfg.types[_doc['type']] ?? '';
    final payable = cfg.payableTypes.contains(_doc['type']) && _doc['status'] == 'validated';
    final balance = toDouble(_doc['total']) - toDouble(_doc['amount_paid']);

    final header = SectionCard(
      child: Wrap(spacing: 16, runSpacing: 4, children: [
        SizedBox(
          width: wide ? 320 : double.infinity,
          child: Field(
            label: cfg.partnerLabel,
            child: InkWell(
              onTap: editable ? _pickPartner : null,
              child: InputDecorator(
                decoration: InputDecoration(suffixIcon: editable ? const Icon(Icons.search) : null),
                child: Text(tr('${_doc['partner_name'] ?? 'Choisir…'}')),
              ),
            ),
          ),
        ),
        if (_isNew && editable)
          SizedBox(
            width: wide ? 200 : double.infinity,
            child: Field(
              label: tr('Type'),
              child: DropdownButtonFormField<String>(
                initialValue: _doc['type'],
                items: [for (final e in cfg.types.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => setState(() => _doc['type'] = v),
              ),
            ),
          ),
        SizedBox(width: wide ? 160 : double.infinity, child: Field(label: tr('Date'), child: _DateBox(value: _doc['date'], onTap: editable ? () => _pickDate('date') : null))),
        if (cfg.payableTypes.contains(_doc['type']))
          SizedBox(width: wide ? 160 : double.infinity, child: Field(label: tr('Échéance'), child: _DateBox(value: _doc['due_date'], onTap: editable ? () => _pickDate('due_date') : null))),
        if (ptAvailable() && _termTypes.contains(_doc['type']))
          SizedBox(
            width: wide ? 260 : double.infinity,
            child: Field(
              label: tr('Conditions de paiement'),
              child: PaymentTermDropdown(
                value: _doc['payment_term_id'] as String?,
                enabled: editable,
                onChanged: (v) => setState(() {
                  _doc['payment_term_id'] = v;
                  _termChosen = true;
                  if (v != null) _doc['due_date'] = null; // l'échéance découlera des conditions à la validation
                  _dirty = true;
                }),
              ),
            ),
          ),
        if (((_doc['schedule'] as List?)?.length ?? 0) > 1)
          SizedBox(width: double.infinity, child: Field(label: tr('Échéancier'), child: ScheduleView(doc: _doc))),
        SizedBox(width: wide ? 200 : double.infinity, child: Field(label: tr('Référence'), child: TextField(controller: _reference, enabled: editable, onChanged: (_) => _dirty = true))),
        if (_payModeTypes.contains(_doc['type']))
          SizedBox(
            width: wide ? 200 : double.infinity,
            child: Field(
              label: tr('Mode de règlement'),
              child: DropdownButtonFormField<String?>(
                initialValue: paymentMethods.containsKey(_doc['payment_method']) ? _doc['payment_method'] as String : null,
                isExpanded: true,
                items: [
                  DropdownMenuItem(value: null, child: Text(tr('Non précisé'))),
                  for (final e in paymentMethods.entries)
                    if (!['loyalty', 'gift_card'].contains(e.key)) DropdownMenuItem(value: e.key, child: Text(tr(e.value))),
                ],
                onChanged: editable ? (v) => setState(() { _doc['payment_method'] = v; _dirty = true; }) : null,
              ),
            ),
          ),
        if (_payModeTypes.contains(_doc['type']))
          SizedBox(
            width: wide ? 220 : double.infinity,
            child: Field(
              label: tr('Décharge'),
              child: Tooltip(
                message: tr('Cadre « Pour décharge » : le client signe à la remise de la facture ou de la marchandise'),
                child: CheckboxListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  title: Text(tr('Case « Pour décharge »')),
                  value: (_doc['receipt_box'] as bool?) ?? session.companySettings['invoice_receipt_box'] == true,
                  onChanged: editable ? (v) => setState(() { _doc['receipt_box'] = v; _dirty = true; }) : null,
                ),
              ),
            ),
          ),
        if (session.hasModule('devises'))
          SizedBox(
            width: wide ? (_foreign ? 300 : 160) : double.infinity,
            child: Field(
              label: _foreign ? 'Devise et cours' : 'Devise',
              child: Row(children: [
                SizedBox(
                  width: 110,
                  child: DropdownButtonFormField<String>(
                    initialValue: _cur,
                    items: [
                      for (final c in {session.currency, ...((session.companySettings['devises'] as Map?)?['currencies'] as List? ?? const ['EUR', 'USD']).map((e) => '$e'), _cur})
                        DropdownMenuItem(value: c, child: Text(tr(c))),
                    ],
                    onChanged: editable ? (v) => _setCurrency(v ?? session.currency) : null,
                  ),
                ),
                if (_foreign) ...[
                  const SizedBox(width: 8),
                  Expanded(
                    child: fixedRate(session.currency, _cur) != null
                        ? Text(tr('1 $_cur = ${number(fixedRate(session.currency, _cur)!, decimals: 3)} (fixe)'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13))
                        : TextField(
                            controller: _rate,
                            enabled: editable,
                            keyboardType: const TextInputType.numberWithOptions(decimal: true),
                            decoration: InputDecoration(hintText: tr('Cours'), helperText: trn(_doc['exchange_rate'] == null ? 'Aucun cours connu : saisissez-le' : null)),
                            onChanged: (v) {
                              final r = double.tryParse(v.replaceAll(',', '.'));
                              setState(() {
                                _doc['exchange_rate'] = r != null && r > 0 ? r : null;
                                _dirty = true;
                              });
                            },
                          ),
                  ),
                ],
              ]),
            ),
          ),
        if (analyticEnabled(session) && (editable || _doc['status'] == 'validated'))
          SizedBox(
            width: wide ? 320 : double.infinity,
            child: Field(
              label: tr('Analytique'),
              child: AnalyticField(
                value: _doc['analytic'],
                showBudget: cfg.entity == 'purchase_documents',
                enabled: editable || (_doc['status'] == 'validated' && session.can(cfg.managePerm)),
                hint: tr('S\'applique aux lignes sans ventilation propre ; les règles automatiques complètent les axes non renseignés.'),
                onChanged: (v) async {
                  setState(() {
                    _doc['analytic'] = v.isEmpty ? null : v;
                    _dirty = true;
                  });
                  if (!editable) {
                    // Document validé : la ventilation se corrige sans toucher au reste
                    final saved = await context.read<SyncEngine>().save(cfg.entity, {'id': _doc['id'], 'analytic': v.isEmpty ? null : v});
                    if (!mounted || !context.mounted) return;
                    setState(() {
                      _doc = {..._doc, ...saved};
                      _dirty = false;
                    });
                    toast(context, tr('Ventilation analytique enregistrée.'));
                  }
                },
              ),
            ),
          ),
        if (projectsEnabled(session) && _doc['type'] != 'receipt' && (editable || _doc['status'] == 'validated'))
          SizedBox(
            width: wide ? 260 : double.infinity,
            child: Field(
              label: tr('Projet'),
              child: ProjectDropdown(
                value: _doc['project_id'] as String?,
                enabled: editable || (_doc['status'] == 'validated' && session.can(cfg.managePerm)),
                onChanged: (v) async {
                  setState(() {
                    _doc['project_id'] = v;
                    _dirty = true;
                  });
                  if (!editable) {
                    final saved = await context.read<SyncEngine>().save(cfg.entity, {'id': _doc['id'], 'project_id': v});
                    if (!mounted || !context.mounted) return;
                    setState(() {
                      _doc = {..._doc, ...saved};
                      _dirty = false;
                    });
                    toast(context, v == null ? 'Document détaché du projet.' : 'Document rattaché au projet.');
                  }
                },
              ),
            ),
          ),
        if (session.hasBranches && (!session.branchRestricted || session.branches.length > 1))
          SizedBox(
            width: wide ? 220 : double.infinity,
            child: Field(
              label: tr('Agence'),
              child: DropdownButtonFormField<String?>(
                initialValue: session.branches.any((b) => b['id'] == _doc['branch_id']) ? _doc['branch_id'] as String? : null,
                items: [
                  if (!session.branchRestricted) DropdownMenuItem(value: null, child: Text(tr('Sans agence'))),
                  for (final b in session.branches) DropdownMenuItem(value: b['id'] as String, child: Text(tr('${b['name']}'))),
                ],
                onChanged: editable
                    ? (v) => setState(() {
                          _doc['branch_id'] = v;
                          final wh = session.branches.where((b) => b['id'] == v).firstOrNull?['warehouse_id'];
                          if (wh != null) _doc['warehouse_id'] = wh;
                        })
                    : null,
              ),
            ),
          ),
        if (session.hasModule('stock') && LocalDb.hasEntity('warehouses') && LocalDb.all('warehouses').length > 1)
          SizedBox(
            width: wide ? 220 : double.infinity,
            child: Field(
              label: tr('Entrepôt'),
              child: DropdownButtonFormField<String?>(
                initialValue: _doc['warehouse_id'],
                items: [
                  DropdownMenuItem(value: null, child: Text(tr('Par défaut'))),
                  for (final w in LocalDb.all('warehouses')) DropdownMenuItem(value: w['id'] as String, child: Text(tr('${w['name']}'))),
                ],
                onChanged: editable ? (v) => setState(() => _doc['warehouse_id'] = v) : null,
              ),
            ),
          ),
      ]),
    );

    final linesCard = SectionCard(
      title: tr('Lignes'),
      trailing: editable
          ? Wrap(spacing: 8, children: [
              TextButton.icon(onPressed: () => _addLine({}), icon: const Icon(Icons.short_text), label: Text(tr('Ligne libre'))),
              FilledButton.tonalIcon(onPressed: _addLine, icon: const Icon(Icons.add), label: Text(tr('Produit'))),
            ])
          : null,
      child: _lines.isEmpty
          ? Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Center(child: Text(tr('Aucune ligne'), style: TextStyle(color: Theme.of(context).hintColor))),
            )
          : Column(children: [
              for (var i = 0; i < _lines.length; i++)
                _LineRow(
                  key: ValueKey(_lines[i]['id']),
                  line: _lines[i],
                  editable: editable,
                  wide: wide,
                  currency: _cur,
                  vatOptions: cfg == DocConfig.sale ? vatChoices(session, toDouble(_lines[i]['tax_rate'])) : null,
                  analytic: analyticEnabled(session),
                  packagings: session.hasModule('tarifs'),
                  lots: _lotsOn,
                  incoming: _incoming,
                  warehouseId: _doc['warehouse_id'] as String?,
                  onReprice: (force) => _reprice(_lines[i], force: force),
                  onChanged: () => setState(() => _dirty = true),
                  onDelete: () => setState(() {
                    _lines.removeAt(i);
                    _dirty = true;
                  }),
                ),
            ]),
    );

    // Société non assujettie : pas de TVA sur les ventes, total simple
    final noVat = cfg == DocConfig.sale && !vatSubject(session) && (t['tax_total'] ?? 0) == 0;
    final totalsCard = SectionCard(
      child: Column(children: [
        if (!noVat) _TotalRow('Sous-total HT', money(t['subtotal'], _cur)),
        if ((t['discount_total'] ?? 0) > 0) _TotalRow('dont remises', '− ${money(t['discount_total'], _cur)}'),
        if (!noVat) _TotalRow('TVA', money(t['tax_total'], _cur)),
        if (!noVat) const Divider(height: 20),
        _TotalRow(noVat ? 'Total' : 'Total TTC', money(t['total'], _cur), bold: true),
        if (noVat) Align(alignment: Alignment.centerRight, child: Text(tr('TVA non applicable'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))),
        if (_foreign && toDouble(_doc['exchange_rate']) > 0)
          _TotalRow('Contrevaleur (1 $_cur = ${number(toDouble(_doc['exchange_rate']), decimals: toDouble(_doc['exchange_rate']) == toDouble(_doc['exchange_rate']).roundToDouble() ? 0 : 3)})', money((t['total'] ?? 0) * toDouble(_doc['exchange_rate']), session.currency), color: Theme.of(context).hintColor),
        if (payable) ...[
          _TotalRow('Payé', money(toDouble(_doc['amount_paid']), _cur)),
          _TotalRow('Reste à payer', money(balance, _cur), bold: true, color: balance > 0 ? YColors.danger : YColors.success),
        ],
      ]),
    );

    final notesCard = SectionCard(
      title: tr('Notes'),
      child: TextField(controller: _notes, enabled: editable, maxLines: 3, decoration: InputDecoration(hintText: tr('Conditions, mentions…')), onChanged: (_) => _dirty = true),
    );

    final payments = payable && LocalDb.hasEntity(cfg.paymentEntity)
        ? (LocalDb.all(cfg.paymentEntity).where((p) => p['document_id'] == _doc['id']).toList()..sort((a, b) => '${a['date']}'.compareTo('${b['date']}')))
        : <Map<String, dynamic>>[];

    final actions = <Widget>[
      if (editable) ...[
        OutlinedButton(onPressed: _save, child: Text(tr('Enregistrer'))),
        if (session.can(cfg.validatePerm ?? cfg.managePerm))
          FilledButton.icon(onPressed: () => _save(status: 'validated'), icon: const Icon(Icons.check), label: Text(tr('Valider')))
        else
          Tooltip(message: tr('Une personne habilitée validera ce document'), child: FilledButton.icon(onPressed: null, icon: const Icon(Icons.lock_outline), label: Text(tr('Validation réservée')))),
      ],
      if (_doc['status'] != 'draft' && _doc['status'] != 'cancelled') ...[
        if (payable && balance > 0.009 && session.can(cfg.paymentPerm))
          FilledButton.icon(onPressed: () => _addPayment(session), icon: const Icon(Icons.payments_outlined), label: Text(tr('Encaisser'))),
        if (payable && balance > 0.009 && session.can(cfg.paymentPerm) && cfg.entity == 'sales_documents' && context.read<SyncEngine>().online)
          OutlinedButton.icon(
            onPressed: () async {
              final partner = _doc['partner_id'] != null ? LocalDb.get('partners', _doc['partner_id'] as String?) : null;
              final r = await showMobileMoneyCollect(context, amount: balance, documentId: '${_doc['id']}', phone: partner?['phone'] as String?);
              if (r == null || !context.mounted) return;
              toast(context, tr('Paiement reçu (${money(r.amount, _cur)}) : il est enregistré sur la facture.'));
              await context.read<SyncEngine>().sync();
              if (mounted) setState(() => _doc = LocalDb.get(cfg.entity, _doc['id']) ?? _doc);
            },
            icon: const Icon(Icons.send_to_mobile),
            label: Text(tr('Mobile Money')),
          ),
        if ((cfg.conversions[_doc['type']] ?? []).isNotEmpty && session.can(cfg.managePerm))
          PopupMenuButton<String>(
            tooltip: tr('Convertir'),
            onSelected: _convert,
            itemBuilder: (_) => [for (final t in cfg.conversions[_doc['type']]!) PopupMenuItem(value: t, child: Text(tr('Créer : ${cfg.types[t]}')))],
            child: Padding(padding: EdgeInsets.symmetric(horizontal: 12, vertical: 10), child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.transform), SizedBox(width: 6), Text(tr('Convertir'))])),
          ),
      ],
      if (cfg.entity == 'purchase_documents' && _doc['type'] == 'rfq' && !_isNew && _doc['_local'] != true && _doc['status'] != 'cancelled') CompareButton(type: 'rfq', id: '${_doc['id']}'),
      if (!_isNew && _lines.isNotEmpty)
        AddToReportButton(
          compact: true,
          title: '${cfg.types[_doc['type']] ?? 'Document'} ${_doc['number'] ?? ''} — ${_doc['partner_name'] ?? ''}'.trim(),
          module: cfg.entity == 'sales_documents' ? 'ventes' : 'achats',
          type: 'doc',
          id: '${_doc['id']}',
        ),
      if (_lines.isNotEmpty && _doc['status'] != 'draft' && cfg.entity == 'sales_documents')
        IconButton(
          tooltip: tr('Envoyer par e-mail / WhatsApp / SMS'),
          onPressed: () => _sendDoc(session, payments),
          icon: const Icon(Icons.send_outlined),
        ),
      if (_lines.isNotEmpty)
        IconButton(
          tooltip: tr('Imprimer / PDF'),
          onPressed: () => printDocument(session: session, config: cfg, doc: {..._doc, ..._payload(), 'status': _doc['status'], 'number': _doc['number']}, payments: payments),
          icon: const Icon(Icons.print_outlined),
        ),
      PopupMenuButton<String>(
        onSelected: (v) {
          if (v == 'delete') _deleteDraft();
          if (v == 'cancel') _cancelDoc();
          if (v == 'paylink') _payLink();
          if (v == 'sign') _sign(session, payments);
          if (v == 'assets') assetsFromInvoice(context, {..._doc, ..._payload(), 'id': _doc['id']});
          if (v == 'delivery') editDelivery(context, fromDocument: {..._doc, ..._payload(), 'id': _doc['id'], 'type': _doc['type'], 'number': _doc['number'], 'amount_paid': _doc['amount_paid'], 'total': _doc['total']});
        },
        itemBuilder: (_) => [
          if (cfg.entity == 'sales_documents' && ['order', 'invoice'].contains(_doc['type']) && _doc['status'] == 'validated' && session.hasModule('logistique') && session.can('logistique.manage'))
            PopupMenuItem(value: 'delivery', child: Text(tr('Créer la livraison'))),
          if (cfg.entity == 'sales_documents' && _doc['type'] == 'invoice' && _doc['status'] == 'validated' && _doc['number'] != null && _doc['payment_status'] != 'paid')
            PopupMenuItem(value: 'paylink', child: Text(tr('Lien « Payer en ligne »'))),
          if (cfg.entity == 'sales_documents' && _doc['status'] == 'validated' && _doc['number'] != null && session.hasModule('signature') && session.can('signature.manage'))
            PopupMenuItem(value: 'sign', child: Text(tr(_doc['type'] == 'quote' ? 'Faire signer le devis en ligne' : 'Faire signer en ligne'))),
          if (cfg.entity == 'purchase_documents' && _doc['type'] == 'supplier_invoice' && _doc['status'] == 'validated' && session.hasModule('immobilisations') && session.can('immobilisations.manage'))
            PopupMenuItem(value: 'assets', child: Text(tr('Créer des immobilisations'))),
          if (_doc['status'] == 'draft' && session.can(cfg.deletePerm ?? cfg.managePerm)) PopupMenuItem(value: 'delete', child: Text(tr('Supprimer le brouillon'))),
          if (_doc['status'] == 'validated' && toDouble(_doc['amount_paid']) == 0 && session.can(cfg.managePerm) && fneCertFor(_doc['id'] as String?)?['status'] != 'certified')
            PopupMenuItem(value: 'cancel', child: Text(tr('Annuler le document'))),
        ],
      ),
    ];

    return PopScope(
      canPop: !_dirty,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (await confirm(context, tr('Modifications non enregistrées'), tr('Quitter sans enregistrer ?'), ok: tr('Quitter')) && context.mounted) {
          _dirty = false;
          Navigator.pop(context);
        }
      },
      child: Scaffold(
        appBar: AppBar(
          title: Row(children: [
            Flexible(child: Text(tr('$typeLabel ${_doc['number'] ?? (_doc['status'] == 'draft' ? '(brouillon)' : '')}'), overflow: TextOverflow.ellipsis)),
            const SizedBox(width: 10),
            docStatusChip(_doc, cfg),
          ]),
          actions: wide ? [...actions.map((a) => Padding(padding: const EdgeInsets.only(right: 8), child: a))] : [actions.last],
        ),
        bottomNavigationBar: wide
            ? null
            : SafeArea(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.end, children: actions.sublist(0, actions.length - 1)),
                ),
              ),
        body: ListView(
          padding: EdgeInsets.all(wide ? 24 : 12),
          children: [
            if (_doc['_local'] == true)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Row(children: [
                  const Icon(Icons.cloud_upload_outlined, size: 18, color: YColors.warning),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr('Modifications en attente d\'envoi au serveur.'), style: TextStyle(color: Theme.of(context).hintColor))),
                ]),
              ),
            if (!_isNew) ApprovalBanner(recordId: '${_doc['id']}'),
            if (cfg.entity == 'purchase_documents' && _doc['type'] == 'purchase_order' && !_isNew) PurchaseOrderPanel(id: '${_doc['id']}'),
            header,
            if (session.hasModule('champs') && customFieldsFor(cfg.entity).isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  CustomFieldsEditor(
                    entity: cfg.entity,
                    values: _custom,
                    creating: _isNew,
                    readOnly: !editable && !session.can(cfg.managePerm),
                    onChanged: () => setState(() => editable ? _dirty = true : _customDirty = true),
                  ),
                  if (!editable && _customDirty)
                    Align(
                      alignment: Alignment.centerRight,
                      child: FilledButton.icon(
                        onPressed: () async {
                          final err = customRequiredError(cfg.entity, _custom);
                          if (err != null) return toast(context, err, error: true);
                          final saved = await context.read<SyncEngine>().save(cfg.entity, {'id': _doc['id'], 'custom': _custom});
                          if (!mounted || !context.mounted) return;
                          setState(() {
                            _doc = {..._doc, ...saved};
                            _customDirty = false;
                          });
                          toast(context, tr('Informations enregistrées.'));
                        },
                        icon: const Icon(Icons.save_outlined),
                        label: Text(tr('Enregistrer les informations')),
                      ),
                    ),
                ]),
              ),
            ],
            if (cfg.entity == 'purchase_documents' && _doc['type'] == 'supplier_invoice' && _doc['status'] == 'validated' && _doc['acc_status'] != null) ...[
              const SizedBox(height: 12),
              _AccountingStatus(doc: _doc, canReply: session.can('achats.manage')),
            ],
            if (FnePanel.applies(session, _doc, purchase: cfg.entity == 'purchase_documents')) ...[
              const SizedBox(height: 12),
              FnePanel(document: _doc, purchase: cfg.entity == 'purchase_documents'),
            ],
            const SizedBox(height: 12),
            linesCard,
            const SizedBox(height: 12),
            if (wide)
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(child: notesCard),
                const SizedBox(width: 12),
                SizedBox(width: 360, child: totalsCard),
              ])
            else ...[
              totalsCard,
              const SizedBox(height: 12),
              notesCard,
            ],
            if (payments.isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Paiements'),
                child: Column(children: [
                  for (final p in payments)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.payments_outlined),
                      title: Text(tr(money(toDouble(p['amount']), _cur))),
                      subtitle: Text(tr('${dateFr(p['date'])} · ${paymentMethods[p['method']] ?? p['method']}${p['reference'] != null ? ' · ${p['reference']}' : ''}')),
                      trailing: p['_local'] == true ? const Icon(Icons.cloud_upload_outlined, size: 18, color: YColors.warning) : null,
                    ),
                ]),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _DateBox extends StatelessWidget {
  const _DateBox({required this.value, this.onTap});

  final dynamic value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        child: InputDecorator(
          decoration: InputDecoration(suffixIcon: onTap != null ? const Icon(Icons.calendar_today, size: 18) : null, enabled: onTap != null),
          child: Text(tr(dateFr(value))),
        ),
      );
}

class _TotalRow extends StatelessWidget {
  const _TotalRow(this.label, this.value, {this.bold = false, this.color});

  final String label;
  final String value;
  final bool bold;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final style = TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w400, fontSize: bold ? 16 : 14, color: color);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(children: [Expanded(child: Text(tr(label), style: style)), Text(tr(value), style: style)]),
    );
  }
}

class _LineRow extends StatefulWidget {
  const _LineRow({super.key, required this.line, required this.editable, required this.wide, required this.currency, required this.onChanged, required this.onDelete, this.analytic = false, this.packagings = false, this.lots = false, this.incoming = false, this.warehouseId, this.onReprice, this.vatOptions});

  final Map<String, dynamic> line;
  final bool editable;
  final bool wide;
  final String currency;
  final VoidCallback onChanged;
  final VoidCallback onDelete;
  final bool analytic;
  final bool packagings;
  final bool lots;
  final bool incoming;
  final String? warehouseId;
  final void Function(bool force)? onReprice;

  /// Taux de TVA proposés (ventes) ; null = saisie libre (achats).
  final List<double>? vatOptions;

  @override
  State<_LineRow> createState() => _LineRowState();
}

class _LineRowState extends State<_LineRow> {
  late final Map<String, TextEditingController> _c;
  int _px = 0;

  static String _n(dynamic v) {
    final d = toDouble(v);
    return d == d.roundToDouble() ? '${d.toInt()}' : '$d';
  }

  Map<String, dynamic>? get _product => widget.line['product_id'] == null ? null : LocalDb.get('products', '${widget.line['product_id']}');

  /// Prix recalculé par l'éditeur (liste de prix, conditionnement) : champs resynchronisés.
  void _syncPrice() {
    final px = widget.line['_px'] as int? ?? 0;
    if (px == _px) return;
    _px = px;
    _c['unit_price']!.text = _n(widget.line['unit_price']);
    _c['discount_percent']!.text = _n(widget.line['discount_percent']);
    _syncTtc();
  }

  int get _dec => decimalsFor(widget.currency);

  /// Prix unitaire TTC affiché, recalculé depuis le HT et la TVA.
  void _syncTtc() => _c['unit_price_ttc']!.text = _n(ttcFromHt(toDouble(widget.line['unit_price']), toDouble(widget.line['tax_rate']), _dec));

  Widget? _unitPicker() {
    final p = _product;
    final pks = widget.packagings ? packagingsOf(p) : const <Map<String, dynamic>>[];
    if (p == null || pks.isEmpty) return null;
    final current = linePackaging(p, widget.line)?['name'] as String?;
    return SizedBox(
      width: widget.wide ? 130 : null,
      child: DropdownButtonFormField<String?>(
        initialValue: current,
        isExpanded: true,
        decoration: InputDecoration(labelText: tr('Unité')),
        items: [
          DropdownMenuItem(value: null, child: Text(tr('${p['unit'] ?? 'Unité'}'), overflow: TextOverflow.ellipsis)),
          for (final e in pks) DropdownMenuItem(value: '${e['name']}', child: Text(tr('${e['name']} (×${_n(e['factor'])})'), overflow: TextOverflow.ellipsis)),
        ],
        onChanged: !widget.editable
            ? null
            : (v) {
                final pk = pks.where((e) => e['name'] == v).firstOrNull;
                widget.line['packaging'] = v;
                widget.line['unit'] = pk == null ? p['unit'] : pk['name'];
                widget.line['unit_factor'] = pk == null ? 1 : toDouble(pk['factor']);
                widget.onReprice?.call(true);
                setState(_syncPrice);
                widget.onChanged();
              },
      ),
    );
  }

  Widget? _lotsButton() {
    final p = _product;
    if (!widget.lots || !isTracked(p)) return null;
    final spec = widget.line['lots'];
    final summary = lotsSummary(spec);
    final need = toDouble(widget.line['quantity']) * (toDouble(widget.line['unit_factor']) > 0 ? toDouble(widget.line['unit_factor']) : 1);
    final short = isSerial(p) && widget.incoming && specQuantity(spec) + 0.0001 < need;
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          style: TextButton.styleFrom(visualDensity: VisualDensity.compact, foregroundColor: short ? YColors.danger : null),
          onPressed: !widget.editable
              ? null
              : () async {
                  final r = await editLotsSpec(context, product: p!, quantity: need, spec: spec, incoming: widget.incoming, warehouseId: widget.warehouseId);
                  if (r == null) return;
                  setState(() => widget.line['lots'] = r.isEmpty ? null : r);
                  widget.onChanged();
                },
          icon: Icon(isSerial(p) ? Icons.qr_code_2 : Icons.inventory_2_outlined, size: 16),
          label: Text(
            tr(summary.isNotEmpty
                ? '${isSerial(p) ? 'N° de série' : 'Lots'} : $summary'
                : (widget.incoming ? (isSerial(p) ? 'Saisir les numéros de série' : 'Lots : création automatique') : 'Lots : FEFO automatique')),
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ),
    );
  }

  @override
  void initState() {
    super.initState();
    String n(dynamic v) => _n(v);
    _px = widget.line['_px'] as int? ?? 0;

    _c = {
      'description': TextEditingController(text: '${widget.line['description'] ?? ''}'),
      'quantity': TextEditingController(text: n(widget.line['quantity'])),
      'unit_price': TextEditingController(text: n(widget.line['unit_price'])),
      'discount_percent': TextEditingController(text: n(widget.line['discount_percent'])),
      'tax_rate': TextEditingController(text: n(widget.line['tax_rate'])),
      'unit_price_ttc': TextEditingController(text: n(ttcFromHt(toDouble(widget.line['unit_price']), toDouble(widget.line['tax_rate']), decimalsFor(widget.currency)))),
    };
  }

  void _set(String k, String v) {
    if (k == 'unit_price_ttc') {
      // Prix TTC saisi : le HT est recalculé (même arrondi que la base)
      final ht = htFromTtc(toDouble(v), toDouble(widget.line['tax_rate']));
      widget.line['unit_price'] = ht;
      widget.line['_manual'] = true;
      _c['unit_price']!.text = _n(ht);
      widget.onChanged();
      return;
    }
    widget.line[k] = k == 'description' ? v : toDouble(v);
    if (k == 'unit_price' || k == 'tax_rate') _syncTtc();
    if (k == 'unit_price' || k == 'discount_percent') widget.line['_manual'] = true;
    if (k == 'quantity') {
      widget.onReprice?.call(false);
      _syncPrice();
    }
    widget.onChanged();
  }

  Widget _num(String k, String label, {double width = 90}) => SizedBox(
        width: widget.wide ? width : null,
        child: TextField(
          controller: _c[k],
          enabled: widget.editable,
          textAlign: TextAlign.right,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
          decoration: InputDecoration(labelText: trn(label)),
          onChanged: (v) => _set(k, v),
        ),
      );

  Widget _vat({double width = 100}) {
    final opts = widget.vatOptions;
    if (opts == null) return _num('tax_rate', 'TVA %', width: 70);
    final cur = toDouble(widget.line['tax_rate']);
    return SizedBox(
      width: widget.wide ? width : null,
      child: DropdownButtonFormField<double>(
        initialValue: opts.contains(cur) ? cur : opts.first,
        isExpanded: true,
        decoration: InputDecoration(labelText: tr('TVA')),
        items: [for (final r in opts) DropdownMenuItem(value: r, child: Text(tr('${_n(r)} %')))],
        onChanged: !widget.editable || opts.length < 2
            ? null
            : (v) {
                widget.line['tax_rate'] = v ?? 0;
                _c['tax_rate']!.text = _n(v);
                setState(_syncTtc);
                widget.onChanged();
              },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    _syncPrice();
    final c = computeLine(widget.line, decimalsFor(widget.currency));
    final unit = _unitPicker();
    final lotsBtn = _lotsButton();
    final priceLabel = widget.line['_price_label'] as String?;
    final extra = lotsBtn != null || (priceLabel != null && widget.editable)
        ? Wrap(spacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
            ?lotsBtn,
            if (priceLabel != null && widget.editable)
              Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('Tarif : $priceLabel'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))),
          ])
        : null;
    final desc = TextField(
      controller: _c['description'],
      enabled: widget.editable,
      decoration: InputDecoration(labelText: tr('Désignation')),
      onChanged: (v) => _set('description', v),
    );
    final total = SizedBox(
      width: widget.wide ? 130 : null,
      child: Text(tr(money(c['total'], widget.currency)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600)),
    );
    final del = widget.editable ? IconButton(onPressed: widget.onDelete, icon: const Icon(Icons.close, size: 18), tooltip: tr('Retirer')) : const SizedBox(width: 40);
    final hasAn = anDist(widget.line['analytic']).isNotEmpty;
    final an = widget.analytic && (widget.editable || hasAn)
        ? IconButton(
            tooltip: trn(hasAn ? 'Ventilation de la ligne : ${analyticSummary(widget.line['analytic'])}' : 'Ventiler cette ligne'),
            icon: Icon(hasAn ? Icons.pie_chart : Icons.pie_chart_outline, size: 18, color: hasAn ? YColors.blue : null),
            onPressed: !widget.editable
                ? null
                : () async {
                    final r = await editDistribution(context, widget.line['analytic'], title: tr('Ventilation de la ligne'), hint: tr('Remplace, pour cette ligne, les axes renseignés ici ; les autres axes suivent le document.'));
                    if (r == null) return;
                    setState(() => widget.line['analytic'] = r.isEmpty ? null : r);
                    widget.onChanged();
                  },
          )
        : const SizedBox();

    if (widget.wide) {
      final row = Row(children: [
          Expanded(child: desc),
          const SizedBox(width: 8),
          _num('quantity', 'Qté', width: 80),
          if (unit != null) ...[const SizedBox(width: 8), unit],
          const SizedBox(width: 8),
          _num('unit_price', 'Prix unit. HT', width: 115),
          const SizedBox(width: 8),
          _num('unit_price_ttc', 'Prix unit. TTC', width: 115),
          const SizedBox(width: 8),
          _num('discount_percent', 'Rem. %', width: 82),
          const SizedBox(width: 8),
          _vat(),
          const SizedBox(width: 12),
          total,
          an,
          del,
        ]);
      return Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: extra == null ? row : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [row, extra]),
      );
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(8)),
      child: Column(children: [
        Row(children: [Expanded(child: desc), an, del]),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: _num('quantity', 'Qté')),
          if (unit != null) ...[const SizedBox(width: 8), Expanded(flex: 2, child: unit)],
          const SizedBox(width: 8),
          Expanded(flex: 2, child: _num('unit_price', 'Prix HT')),
          const SizedBox(width: 8),
          Expanded(flex: 2, child: _num('unit_price_ttc', 'Prix TTC')),
        ]),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: _num('discount_percent', 'Remise %')),
          const SizedBox(width: 8),
          Expanded(child: _vat()),
        ]),
        ?extra,
        const SizedBox(height: 6),
        Align(alignment: Alignment.centerRight, child: total),
      ]),
    );
  }
}

class _PartnerPicker extends StatefulWidget {
  const _PartnerPicker({required this.cfg});

  final DocConfig cfg;

  @override
  State<_PartnerPicker> createState() => _PartnerPickerState();
}

class _PartnerPickerState extends State<_PartnerPicker> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final items = LocalDb.all('partners')
        .where((p) => p['active'] != false && (p['type'] == widget.cfg.partnerType || p['type'] == 'both'))
        .where((p) => _q.isEmpty || '${p['name']} ${p['phone'] ?? ''}'.toLowerCase().contains(_q))
        .toList()
      ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));

    return YAlertDialog(
      title: Text(tr('Choisir un ${widget.cfg.partnerLabel.toLowerCase()}')),
      content: SizedBox(
        width: 460,
        height: 420,
        child: Column(children: [
          SearchField(onChanged: (v) => setState(() => _q = v.toLowerCase())),
          const SizedBox(height: 8),
          Expanded(
            child: items.isEmpty
                ? Center(child: Text(tr('Aucun résultat')))
                : ListView.builder(
                    itemCount: items.length,
                    itemBuilder: (_, i) => ListTile(
                      title: Text(tr('${items[i]['name']}')),
                      subtitle: Text(tr('${items[i]['phone'] ?? ''}')),
                      onTap: () => Navigator.pop(context, items[i]),
                    ),
                  ),
          ),
        ]),
      ),
      actions: [
        if (context.read<Session>().can('core.contacts.manage'))
        TextButton.icon(
          onPressed: () async {
            final created = await editPartner(context, null, widget.cfg.partnerType);
            if (created != null && context.mounted) Navigator.pop(context, created);
          },
          icon: const Icon(Icons.person_add_alt),
          label: Text(tr('Nouveau ${widget.cfg.partnerLabel.toLowerCase()}')),
        ),
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
      ],
    );
  }
}

class _ProductPicker extends StatefulWidget {
  const _ProductPicker();

  @override
  State<_ProductPicker> createState() => _ProductPickerState();
}

class _ProductPickerState extends State<_ProductPicker> {
  String _q = '';
  String _raw = '';

  Future<void> _create() async {
    final created = await editProduct(context, null, _raw.trim().isEmpty ? null : _raw.trim());
    if (created != null && mounted) Navigator.pop(context, created);
  }

  @override
  Widget build(BuildContext context) {
    final currency = context.read<Session>().currency;
    final items = LocalDb.all('products')
        .where((p) => p['active'] != false && p['is_template'] != true)
        .where((p) => _q.isEmpty || '${p['name']} ${p['sku'] ?? ''} ${p['barcode'] ?? ''} ${packagingsOf(p).map((e) => e['barcode'] ?? '').join(' ')}'.toLowerCase().contains(_q))
        .toList()
      ..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));

    return YAlertDialog(
      title: Text(tr('Ajouter un produit')),
      content: SizedBox(
        width: 460,
        height: 420,
        child: Column(children: [
          // Un lecteur de code-barres USB tape le code puis "Entrée" : sélection directe.
          TextField(
            autofocus: true,
            decoration: InputDecoration(prefixIcon: Icon(Icons.qr_code_scanner, size: 20), hintText: tr('Nom, référence ou code-barres…')),
            onChanged: (v) => setState(() {
              _raw = v;
              _q = v.toLowerCase();
            }),
            onSubmitted: (v) {
              final pk = packagingByBarcode(v);
              if (pk != null && pk.$1['is_template'] != true) return Navigator.pop(context, {...pk.$1, '_packaging': pk.$2['name']});
              if (items.length == 1) Navigator.pop(context, items.first);
            },
          ),
          const SizedBox(height: 8),
          Expanded(
            child: items.isEmpty
                ? Center(
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Text(tr('Aucun produit')),
                      if (_q.isNotEmpty && context.read<Session>().can('core.products.manage')) ...[
                        const SizedBox(height: 12),
                        FilledButton.tonalIcon(onPressed: _create, icon: const Icon(Icons.add), label: Text(tr('Créer « ${_raw.trim()} »'))),
                      ],
                    ]),
                  )
                : ListView.builder(
                    itemCount: items.length,
                    itemBuilder: (_, i) => ListTile(
                      title: Text(tr('${items[i]['name']}')),
                      subtitle: Text(tr('${items[i]['sku'] ?? ''}')),
                      trailing: Text(tr(money(toDouble(items[i]['sale_price']), currency))),
                      onTap: () => Navigator.pop(context, items[i]),
                    ),
                  ),
          ),
        ]),
      ),
      actions: [
        if (context.read<Session>().can('core.products.manage'))
          TextButton.icon(
            onPressed: _create,
            icon: const Icon(Icons.add),
            label: Text(tr('Nouveau produit')),
          ),
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
      ],
    );
  }
}

class _PaymentForm extends StatefulWidget {
  const _PaymentForm({required this.doc, required this.cfg, required this.balance, required this.currency});

  final Map<String, dynamic> doc;
  final DocConfig cfg;
  final double balance;
  final String currency;

  @override
  State<_PaymentForm> createState() => _PaymentFormState();
}

class _PaymentFormState extends State<_PaymentForm> {
  late final TextEditingController _amount;
  final _reference = TextEditingController();
  String _method = 'cash';
  String? _account;
  DateTime _date = DateTime.now();

  @override
  void initState() {
    super.initState();
    _amount = TextEditingController(text: widget.balance == widget.balance.roundToDouble() ? '${widget.balance.toInt()}' : widget.balance.toStringAsFixed(2));
  }

  Future<void> _save() async {
    final amount = toDouble(_amount.text);
    if (amount <= 0 || amount > widget.balance + 0.009) {
      toast(context, tr('Montant invalide (reste à payer : ${money(widget.balance, widget.currency)}).'), error: true);
      return;
    }
    if (_method == 'gift_card' && _reference.text.trim().isEmpty) {
      toast(context, tr('Saisissez le code de la carte cadeau.'), error: true);
      return;
    }
    final sync = context.read<SyncEngine>();
    await sync.save(widget.cfg.paymentEntity, {
      'document_id': widget.doc['id'],
      'date': isoDate(_date),
      'amount': amount,
      'method': _method,
      'reference': _reference.text.trim().isEmpty ? null : _reference.text.trim(),
      if (_account != null && !_fidMethods.contains(_method)) 'treasury_account_id': _account,
    });

    // Mise à jour immédiate de l'affichage (le serveur recalculera à la synchro).
    final paid = toDouble(widget.doc['amount_paid']) + amount;
    final total = toDouble(widget.doc['total']);
    await LocalDb.put(widget.cfg.entity, {
      ...widget.doc,
      'amount_paid': paid,
      'payment_status': paid + 0.009 >= total ? 'paid' : 'partial',
      'balance': total - paid,
    });
    if (mounted) Navigator.pop(context, true);
  }

  @override
  Widget build(BuildContext context) {
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Text(tr('Reste à payer : ${money(widget.balance, widget.currency)}'), style: const TextStyle(fontWeight: FontWeight.w600)),
      const SizedBox(height: 16),
      Field(
        label: tr('Montant'),
        child: TextField(controller: _amount, autofocus: true, keyboardType: const TextInputType.numberWithOptions(decimal: true), inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))]),
      ),
      Field(
        label: tr('Mode de paiement'),
        child: Wrap(spacing: 8, runSpacing: 8, children: [
          for (final m in paymentMethods.entries.where((e) => !_fidMethods.contains(e.key) || (widget.cfg.paymentEntity == 'sales_payments' && context.read<Session>().hasModule('fidelite'))))
            ChoiceChip(label: Text(tr(m.value)), selected: _method == m.key, onSelected: (_) => setState(() => _method = m.key)),
        ]),
      ),
      if (!_fidMethods.contains(_method) && trActive(context.read<Session>()) && trAccounts().length > 1)
        Field(
          label: tr('Compte de trésorerie'),
          child: DropdownButtonFormField<String?>(
            key: ValueKey('acc-$_method'),
            initialValue: _account,
            isExpanded: true,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr('Par défaut (${trAccounts().where((a) => a['id'] == trDefaults()[_method]).map((a) => a['name']).firstOrNull ?? 'selon le mode'})'))),
              for (final a in trAccounts()) DropdownMenuItem<String?>(value: '${a['id']}', child: Text(tr('${a['name']}'))),
            ],
            onChanged: (v) => setState(() => _account = v),
          ),
        ),
      Field(
        label: tr('Date'),
        child: _DateBox(
          value: isoDate(_date),
          onTap: () async {
            final d = await pickDate(context, initialDate: _date, firstDate: DateTime(2015), lastDate: DateTime(2100));
            if (d != null) setState(() => _date = d);
          },
        ),
      ),
      Field(
        label: _method == 'loyalty'
            ? 'N° de carte fidélité (vide = carte du client)'
            : _method == 'gift_card'
                ? 'Code de la carte cadeau'
                : 'Référence (n° de transaction, chèque…)',
        child: TextField(controller: _reference, textCapitalization: _method == 'gift_card' ? TextCapitalization.characters : TextCapitalization.none),
      ),
      if (_fidMethods.contains(_method))
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Text(tr('Le solde est vérifié par le serveur à la synchronisation ; un paiement refusé est signalé.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        ),
      Align(alignment: Alignment.centerRight, child: SaveButton(onPressed: _save, child: Text(tr('Enregistrer le paiement')))),
    ]);
  }
}


/// Statut de la facture fournisseur côté comptabilité (à vérifier, approuvée, refusée, comptabilisée).
class _AccountingStatus extends StatelessWidget {
  const _AccountingStatus({required this.doc, required this.canReply});

  final Map<String, dynamic> doc;
  final bool canReply;

  static const _labels = {'to_review': ('En attente de la comptabilité', YColors.warning), 'approved': ('Approuvée par la comptabilité', YColors.blue),
    'refused': ('Refusée par la comptabilité', YColors.danger), 'posted': ('Comptabilisée', YColors.success)};

  @override
  Widget build(BuildContext context) {
    final (label, color) = _labels[doc['acc_status']] ?? ('${doc['acc_status']}', YColors.muted);
    return Card(
      color: color.withValues(alpha: .07),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(Icons.account_balance_outlined, color: color),
            const SizedBox(width: 10),
            Expanded(child: Text(tr(label), style: TextStyle(fontWeight: FontWeight.w700, color: color))),
            if (doc['acc_status'] == 'refused' && canReply)
              FilledButton.tonalIcon(onPressed: () => _reply(context), icon: const Icon(Icons.reply, size: 18), label: Text(tr('Répondre'))),
          ]),
          if (doc['acc_note'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('${doc['acc_note']}'))),
        ]),
      ),
    );
  }

  Future<void> _reply(BuildContext context) async {
    final ctl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Répondre à la comptabilité')),
        content: SizedBox(width: 460, child: TextField(controller: ctl, autofocus: true, maxLines: 4, decoration: InputDecoration(labelText: tr('Explication ou correction apportée')))),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Renvoyer')))],
      ),
    );
    if (ok != true || ctl.text.trim().isEmpty || !context.mounted) return;
    try {
      await context.read<Session>().api.post('compta/purchases/${doc['id']}/resubmit', {'reply': ctl.text.trim()});
      if (context.mounted) context.read<SyncEngine>().sync();
      if (context.mounted) toast(context, tr('Facture renvoyée à la comptabilité.'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }
}
