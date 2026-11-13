import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../core/billetterie.dart';
import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../i18n/i18n.dart';
import '../../pdf/tickets_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/qr_view.dart';
import 'attendance_screen.dart';
import 'billetterie_screen.dart' show tkCameraSupported, tkPrepareCamera, tkTypes;
import 'event_extras.dart' show regForm;

/// Bornes d'accueil d'un salon : les visiteurs s'inscrivent eux-mêmes à l'entrée (même sans Internet),
/// reçoivent un QR code pour revenir les jours suivants ; chaque inscription et chaque entrée est comptée
/// par jour et par borne (Fréquentation).

String _hash(String pin) => sha256.convert(utf8.encode('yomara-borne:$pin')).toString();

String _today() => DateTime.now().toIso8601String().substring(0, 10);

/// Prochain numéro de ticket de la borne n° [kk] : « 01 » + compteur sur 4 chiffres (« 010042 », affiché « 01-0042 »).
/// Le compteur repart du plus grand numéro connu sur l'appareil (billets synchronisés compris) : pas de doublon
/// tant que chaque borne a son propre numéro.
String kioskNextCode(int kk) {
  final prefix = kk.toString().padLeft(2, '0');
  final re = RegExp('^$prefix(\\d{4,})\$');
  var max = 0;
  if (LocalDb.hasEntity('ev_tickets')) {
    for (final t in LocalDb.all('ev_tickets')) {
      final m = re.firstMatch('${t['code'] ?? ''}');
      if (m != null) max = math.max(max, int.parse(m.group(1)!));
    }
  }
  return '$prefix${(max + 1).toString().padLeft(4, '0')}';
}

/// Autre borne ayant déjà émis des tickets avec ce numéro (réglage à corriger).
String? kioskNumberUsedBy(int kk, String name) {
  if (!LocalDb.hasEntity('ev_tickets') || !LocalDb.hasEntity('ev_ticket_orders')) return null;
  final prefix = kk.toString().padLeft(2, '0');
  final orders = {for (final o in LocalDb.all('ev_ticket_orders')) '${o['id']}': o};
  for (final t in LocalDb.all('ev_tickets')) {
    if (!RegExp('^$prefix\\d{4,}\$').hasMatch('${t['code'] ?? ''}')) continue;
    final k = '${orders['${t['order_id']}']?['kiosk'] ?? ''}';
    if (k.isNotEmpty && k != name) return k;
  }
  return null;
}

/// Logo de la société pour la borne et ses tickets : téléchargé au démarrage et gardé sur l'appareil (hors ligne).
Future<Uint8List?> kioskLogo(Session s) async {
  final url = '${s.company['logo'] ?? ''}';
  if (url.isEmpty) return null;
  // Logo intégré (data:image/png;base64,…)
  if (url.startsWith('data:image') && url.contains(',')) {
    try {
      return base64Decode(url.substring(url.indexOf(',') + 1));
    } catch (_) {
      return null;
    }
  }
  if (!url.startsWith('http')) return null;
  try {
    final r = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 8));
    if (r.statusCode == 200 && r.bodyBytes.isNotEmpty && r.bodyBytes.length < 3000000) {
      await LocalDb.meta.put('kiosk_logo', base64Encode(r.bodyBytes));
      await LocalDb.meta.put('kiosk_logo_url', url);
      return r.bodyBytes;
    }
  } catch (_) {}
  final cached = LocalDb.meta.get('kiosk_logo');
  return cached is String && LocalDb.meta.get('kiosk_logo_url') == url ? base64Decode(cached) : null;
}

/// Le compte utilisé sur la borne doit pouvoir inscrire (vente) et enregistrer les entrées (contrôle).
bool kioskAllowed(Session s) => (s.can('billetterie.sell') && s.can('billetterie.scan')) || s.can('billetterie.manage');

/// Réglages de la borne (sur cet appareil) puis passage en plein écran.
Future<void> startKiosk(BuildContext context, Map<String, dynamic> event) async {
  final session = context.read<Session>();
  if (!kioskAllowed(session)) {
    toast(context, tr('Ce compte ne peut pas servir de borne : il lui faut les droits « Vendre des billets au guichet » et « Contrôler les billets à l\'entrée ».'), error: true);
    return;
  }
  final sync = context.read<SyncEngine>();
  var types = tkTypes('${event['id']}').where((t) => t['active'] != false && toDouble(t['price']) == 0 && (t['kind'] == 'visitor' || t['kind'] == null)).toList();
  final form = regForm(event);
  final name = TextEditingController(text: '${LocalDb.meta.get('kiosk_name') ?? 'Borne 1'}');
  final pin = TextEditingController();
  final digits = RegExp(r'\d+').firstMatch(name.text)?.group(0);
  final number = TextEditingController(text: '${LocalDb.meta.get('kiosk_number') ?? int.tryParse(digits ?? '') ?? 1}');
  var print = LocalDb.meta.get('kiosk_print') != false;
  var paper = '${LocalDb.meta.get('kiosk_paper') ?? 'roll80'}';
  Printer? printer = LocalDb.meta.get('kiosk_printer_url') == null ? null : Printer(url: '${LocalDb.meta.get('kiosk_printer_url')}', name: '${LocalDb.meta.get('kiosk_printer_name') ?? ''}');
  final info = await Printing.info();
  final printers = info.canListPrinters ? await Printing.listPrinters().catchError((_) => <Printer>[]) : <Printer>[];
  if (printer != null && printers.isNotEmpty && !printers.any((x) => x.url == printer!.url)) printer = null;
  printer ??= printers.where((x) => x.isDefault).firstOrNull;
  if (!context.mounted) return;
  String? typeId = types.any((t) => t['id'] == form['kiosk_type_id']) ? '${form['kiosk_type_id']}' : types.firstOrNull?['id'] as String?;
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr('Mode borne d\'accueil')),
        content: SizedBox(
          width: 460,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(
              tr('Les visiteurs s\'inscrivent eux-mêmes sur cet appareil, en plein écran. Chaque inscription vaut entrée du jour ; un visiteur qui revient présente son QR code ou donne son numéro. Fonctionne sans Internet : tout part au serveur dès que la connexion revient.'),
              style: TextStyle(color: Theme.of(c).hintColor, fontSize: 13),
            ),
            const SizedBox(height: 12),
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom de cette borne'), helperText: tr('Ex. : Borne 1, Entrée Nord. Sert aux statistiques par borne.'))),
            const SizedBox(height: 10),
            TextField(
              controller: number,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(2)],
              decoration: InputDecoration(
                labelText: tr('Numéro de la borne (1 à 99)'),
                helperText: tr('Début des numéros de ticket : borne 1 → 01-0001, 01-0002… Un numéro différent par borne.'),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: pin,
              keyboardType: TextInputType.number,
              obscureText: true,
              maxLength: 6,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(labelText: tr('Code de sortie (4 à 6 chiffres)'), helperText: tr('Demandé pour quitter la borne. Restez discret en le tapant.')),
            ),
            const Divider(height: 24),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: print,
              onChanged: (v) => set(() => print = v),
              title: Text(tr('Imprimer le ticket après chaque inscription')),
              subtitle: Text(tr(info.directPrint
                  ? 'Impression directe, sans fenêtre.'
                  : (kIsWeb
                      ? 'Le navigateur affiche sa fenêtre d\'impression ; pour imprimer sans fenêtre, ouvrez Chrome en mode borne avec --kiosk --kiosk-printing.'
                      : 'Cet appareil affiche la fenêtre d\'impression à chaque ticket ; un PC Windows, un Mac ou un iPad imprime directement.'))),
            ),
            if (print) ...[
              DropdownButtonFormField<String>(
                initialValue: paper,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Format du ticket')),
                items: [for (final f in kioskPaperFormats.entries) DropdownMenuItem(value: f.key, child: Text(tr(f.value)))],
                onChanged: (v) => set(() => paper = v ?? paper),
              ),
              if (printers.isNotEmpty)
                DropdownButtonFormField<String>(
                  initialValue: printer?.url,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: tr('Imprimante')),
                  items: [for (final x in printers) DropdownMenuItem(value: x.url, child: Text(tr('${x.name}${x.isDefault ? ' (par défaut)' : ''}')))],
                  onChanged: (v) => set(() => printer = printers.firstWhere((x) => x.url == v)),
                )
              else if (info.directPrint)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () async {
                      final p = await Printing.pickPrinter(context: c, title: tr('Imprimante des tickets'));
                      if (p != null) set(() => printer = p);
                    },
                    icon: const Icon(Icons.print_outlined),
                    label: Text(tr(printer == null ? 'Choisir l\'imprimante' : 'Imprimante : ${printer!.name}')),
                  ),
                ),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: () => printKioskTicket(
                    event: event,
                    ticket: {'code': '${(int.tryParse(number.text) ?? 1).toString().padLeft(2, '0')}0000', 'holder_name': 'Ticket d\'essai'},
                    company: '${session.company['name'] ?? ''}',
                    kiosk: name.text.trim(),
                    paper: paper,
                    printer: printer,
                    direct: info.directPrint,
                  ),
                  icon: const Icon(Icons.receipt_long_outlined),
                  label: Text(tr('Imprimer un ticket d\'essai')),
                ),
              ),
            ],
            if (types.isEmpty)
              Card(
                color: YColors.blue.withValues(alpha: .07),
                child: ListTile(
                  leading: const Icon(Icons.info_outline, color: YColors.blue),
                  title: Text(tr('Un tarif « Visiteur » gratuit sera créé')),
                  subtitle: Text(tr('Valable pendant toute la durée de l\'événement, une entrée par jour.')),
                ),
              )
            else
              DropdownButtonFormField<String>(
                initialValue: typeId,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Billet remis aux inscrits')),
                items: [for (final t in types) DropdownMenuItem(value: '${t['id']}', child: Text(tr('${t['name']}')))],
                onChanged: (v) => set(() => typeId = v),
              ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
          FilledButton.icon(
            onPressed: () {
              if (name.text.trim().isEmpty) return toast(c, tr('Donnez un nom à la borne.'), error: true);
              if (pin.text.length < 4) return toast(c, tr('Choisissez un code de sortie de 4 à 6 chiffres.'), error: true);
              final n = int.tryParse(number.text) ?? 0;
              if (n < 1 || n > 99) return toast(c, tr('Numéro de borne : de 1 à 99.'), error: true);
              final other = kioskNumberUsedBy(n, name.text.trim());
              if (other != null) return toast(c, tr('Le numéro $n est déjà utilisé par « $other ». Donnez un autre numéro à cette borne.'), error: true);
              Navigator.pop(c, true);
            },
            icon: const Icon(Icons.fullscreen),
            label: Text(tr('Démarrer la borne')),
          ),
        ],
      ),
    ),
  );
  if (ok != true || !context.mounted) return;
  if (types.isEmpty) {
    final id = newId();
    await sync.save('ev_ticket_types', {'id': id, 'event_id': event['id'], 'name': 'Visiteur', 'kind': 'visitor', 'price': 0, 'online': true, 'reentry': false, 'active': true});
    types = [LocalDb.get('ev_ticket_types', id) ?? {'id': id, 'kind': 'visitor', 'price': 0, 'reentry': false}];
    typeId = id;
  }
  await LocalDb.meta.put('kiosk_name', name.text.trim());
  await LocalDb.meta.put('kiosk_pin', _hash(pin.text));
  await LocalDb.meta.put('kiosk_number', int.parse(number.text));
  await LocalDb.meta.put('kiosk_print', print);
  await LocalDb.meta.put('kiosk_paper', paper);
  await LocalDb.meta.put('kiosk_printer_url', printer?.url);
  await LocalDb.meta.put('kiosk_printer_name', printer?.name);
  final logo = form['kiosk_logo'] == false ? null : await kioskLogo(session);
  if (!context.mounted) return;
  await Navigator.of(context).push(MaterialPageRoute(
    fullscreenDialog: true,
    builder: (_) => KioskScreen(eventId: '${event['id']}', kioskName: name.text.trim(), typeId: typeId!, number: int.parse(number.text), print: print, paper: paper, printer: printer, directPrint: info.directPrint, logo: logo),
  ));
}

/// Impression d'un ticket de borne : directe (Windows, Mac, iPad, Linux) ou par la fenêtre d'impression (Android, navigateur).
Future<void> printKioskTicket({
  required Map<String, dynamic> event,
  required Map<String, dynamic> ticket,
  required String company,
  required String kiosk,
  required String paper,
  Printer? printer,
  bool direct = false,
  String? city,
  bool returning = false,
  Uint8List? logo,
}) async {
  final bytes = Uint8List.fromList(await kioskTicketPdf(event: event, ticket: ticket, company: company, kiosk: kiosk, at: DateTime.now(), city: city, format: paper, returning: returning, logo: logo));
  final name = 'Ticket ${kioskRef('${ticket['code']}')}';
  if (direct && printer != null) {
    await Printing.directPrintPdf(printer: printer, name: name, onLayout: (_) async => bytes, usePrinterSettings: paper.startsWith('roll'));
  } else {
    await Printing.layoutPdf(name: name, onLayout: (_) async => bytes);
  }
}

enum _Step { form, returning, done }

class KioskScreen extends StatefulWidget {
  const KioskScreen({super.key, required this.eventId, required this.kioskName, required this.typeId, this.number = 1, this.print = false, this.paper = 'roll80', this.printer, this.directPrint = false, this.logo});

  final String eventId;
  final String kioskName;
  final String typeId;
  final int number;
  final bool print;
  final String paper;
  final Printer? printer;
  final bool directPrint;
  final Uint8List? logo;

  @override
  State<KioskScreen> createState() => _KioskScreenState();
}

class _KioskScreenState extends State<KioskScreen> {
  _Step _step = _Step.form;
  Timer? _idle;
  Timer? _back;
  int _backIn = 0;

  // formulaire
  final _formKey = GlobalKey<FormState>();
  final _first = TextEditingController();
  final _last = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _company = TextEditingController();
  final _job = TextEditingController();
  final _city = TextEditingController();
  final _country = TextEditingController();
  final _answers = <String, dynamic>{};
  final _text = <String, TextEditingController>{};
  bool _consent = false;
  bool _saving = false;

  // résultat
  Map<String, dynamic>? _ticket;
  String _title = '';
  String _message = '';
  Color _color = YColors.success;

  // visiteur qui revient
  final _lookup = TextEditingController();
  MobileScannerController? _cam;
  String? _notFound;
  DateTime _lastScan = DateTime(2000);

  Map<String, dynamic> get _event => LocalDb.get('ev_events', widget.eventId) ?? const {};
  Map<String, dynamic> get _form => regForm(_event);

  @override
  void initState() {
    super.initState();
    _country.text = '${_form['default_country'] ?? ''}';
    if (!kIsWeb && const {TargetPlatform.android, TargetPlatform.iOS}.contains(defaultTargetPlatform)) {
      SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
    }
  }

  @override
  void dispose() {
    _idle?.cancel();
    _back?.cancel();
    _cam?.dispose();
    if (!kIsWeb && const {TargetPlatform.android, TargetPlatform.iOS}.contains(defaultTargetPlatform)) {
      SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    }
    for (final c in [_first, _last, _phone, _email, _company, _job, _city, _country, _lookup, ..._text.values]) {
      c.dispose();
    }
    super.dispose();
  }

  /// Sans action pendant 90 s, la borne revient à l'accueil et efface ce qui a été saisi.
  void _touch() {
    _idle?.cancel();
    _idle = Timer(const Duration(seconds: 90), () => mounted ? _reset() : null);
  }

  void _go(_Step s) {
    setState(() => _step = s);
    if (s == _Step.returning) _startCamera();
    if (s != _Step.returning) _stopCamera();
    _touch();
  }

  void _reset() {
    _back?.cancel();
    _stopCamera();
    for (final c in [_first, _last, _phone, _email, _company, _job, _city, _lookup, ..._text.values]) {
      c.clear();
    }
    _country.text = '${_form['default_country'] ?? ''}';
    _answers.clear();
    setState(() {
      _consent = false;
      _ticket = null;
      _notFound = null;
      _step = _Step.form;
    });
    _formKey.currentState?.reset();
    _idle?.cancel();
    FocusManager.instance.primaryFocus?.unfocus();
  }

  void _startCamera() {
    if (!tkCameraSupported || _cam != null) return;
    tkPrepareCamera();
    _cam = MobileScannerController(detectionSpeed: DetectionSpeed.normal, detectionTimeoutMs: 500, formats: const [BarcodeFormat.qrCode]);
  }

  void _stopCamera() {
    _cam?.dispose();
    _cam = null;
  }

  // ------------------------------------------------------------------ enregistrement

  Map<String, dynamic> get _type => LocalDb.get('ev_ticket_types', widget.typeId) ?? {'id': widget.typeId, 'kind': 'visitor', 'price': 0};

  /// Entrée du jour sur cette borne (réévaluée par le serveur à la synchronisation).
  Future<TkResult> _checkin(Map<String, dynamic> t) async {
    final r = tkEvaluate(widget.eventId, '${t['code']}');
    final now = DateTime.now();
    await context.read<SyncEngine>().save('ev_checkins', {
      'id': newId(),
      'event_id': widget.eventId,
      'code': '${t['code']}',
      'scanned_at': now.toUtc().toIso8601String(),
      'gate': widget.kioskName,
      'ticket_id': t['id'],
      'result': r.result,
      'reason': r.reason,
    });
    if (r.ok) {
      await LocalDb.put('ev_tickets', {...t, 'checked_in_at': t['checked_in_at'] ?? now.toUtc().toIso8601String(), 'last_checkin_at': now.toUtc().toIso8601String(), 'checkins_count': toDouble(t['checkins_count']).toInt() + 1});
    }
    return r;
  }

  void _showDone(Map<String, dynamic> t, String title, String message, {Color color = YColors.success, bool returning = false, String? city}) {
    _ticket = t;
    _title = title;
    _message = message;
    _color = color;
    _go(_Step.done);
    HapticFeedback.mediumImpact();
    // Ticket imprimé (entrée validée seulement), puis retour au formulaire pour le visiteur suivant
    if (widget.print && color != YColors.warning) {
      final session = context.read<Session>();
      printKioskTicket(
        event: _event,
        ticket: t,
        company: '${session.company['name'] ?? ''}',
        kiosk: widget.kioskName,
        paper: widget.paper,
        printer: widget.printer,
        direct: widget.directPrint,
        city: city,
        returning: returning,
        logo: _form['kiosk_logo'] == false ? null : widget.logo,
      ).catchError((Object e) {
        if (mounted) toast(context, tr('Impression impossible : ${errorText(e)}'), error: true);
      });
    }
    _backIn = color == YColors.warning ? 10 : 5;
    _back?.cancel();
    _back = Timer.periodic(const Duration(seconds: 1), (tm) {
      if (!mounted) return tm.cancel();
      setState(() => _backIn--);
      if (_backIn <= 0) {
        tm.cancel();
        _reset();
      }
    });
  }

  String _firstName(Map<String, dynamic> t) => '${t['holder_name'] ?? ''}'.trim().split(' ').first;

  Future<void> _welcomeBack(Map<String, dynamic> t) async {
    final r = await _checkin(t);
    if (!mounted) return;
    final who = _firstName(t);
    if (r.ok) {
      _showDone(t, 'Bon retour${who.isEmpty ? '' : ', $who'} !', widget.print ? 'Votre entrée d\'aujourd\'hui est enregistrée. Récupérez votre ticket. Bonne visite !' : 'Votre entrée d\'aujourd\'hui est enregistrée. Bonne visite !', returning: true);
    } else if (r.result == 'duplicate') {
      _showDone(t, 'Bienvenue${who.isEmpty ? '' : ', $who'} !', 'Votre entrée d\'aujourd\'hui était déjà enregistrée. Bonne visite !', color: YColors.blue, returning: true);
    } else {
      _showDone(t, 'Adressez-vous à l\'accueil', r.reason ?? 'Ce billet n\'est pas valable aujourd\'hui.', color: YColors.warning);
    }
  }

  Future<void> _submit() async {
    _touch();
    if (!_formKey.currentState!.validate()) return;
    final f = _form;
    for (final q in (f['questions'] as List? ?? const []).map(asMap)) {
      final v = _answers[q['id']];
      if (q['required'] == true && (v == null || v == '' || v == false)) {
        toast(context, tr('Répondez à « ${q['label']} ».'), error: true);
        return;
      }
    }
    setState(() => _saving = true);
    try {
      // Déjà inscrit (même téléphone ou e-mail) : pas de doublon, c'est une entrée de plus
      final known = tkFindVisitor(widget.eventId, _phone.text) ?? (_email.text.trim().isEmpty ? null : tkFindVisitor(widget.eventId, _email.text));
      if (known != null) {
        await _welcomeBack(known);
        return;
      }
      final type = _type;
      final event = _event;
      final name = '${_first.text.trim()} ${_last.text.trim()}'.trim();
      final orderId = newId();
      final now = DateTime.now().toUtc().toIso8601String();
      final ticket = {'id': newId(), 'type_id': widget.typeId, 'code': kioskNextCode(widget.number), 'token': tkNewToken(), 'holder_name': name};
      String? opt(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
      final answers = [
        for (final q in (f['questions'] as List? ?? const []).map(asMap))
          if (_answers[q['id']] != null && _answers[q['id']] != '')
            {'id': q['id'], 'label': q['label'], 'value': _answers[q['id']] is bool ? (_answers[q['id']] == true ? 'Oui' : 'Non') : '${_answers[q['id']]}'.trim()},
      ];
      await context.read<SyncEngine>().save('ev_ticket_orders', {
        'id': orderId,
        'event_id': widget.eventId,
        'buyer_name': name,
        'buyer_phone': opt(_phone),
        'buyer_email': opt(_email)?.toLowerCase(),
        'buyer_company': f['ask_company'] == true ? opt(_company) : null,
        'buyer_job': f['ask_job'] == true ? opt(_job) : null,
        'buyer_city': f['ask_city'] != 'off' ? opt(_city) : null,
        'buyer_country': f['ask_country'] != 'off' ? opt(_country) : null,
        'answers': answers.isEmpty ? null : answers,
        'consent': _consent,
        'items': [
          {'type_id': widget.typeId, 'quantity': 1},
        ],
        'status': 'paid',
        'payment_method': 'free',
        'kiosk': widget.kioskName,
        'registered_at': now,
        'tickets': [ticket],
      });
      // Billet disponible tout de suite sur cette borne (remplacé par celui du serveur à la synchronisation)
      final local = {
        ...ticket,
        'event_id': widget.eventId,
        'order_id': orderId,
        'kind': type['kind'] ?? 'visitor',
        'price': 0,
        'holder_phone': opt(_phone),
        'holder_email': opt(_email)?.toLowerCase(),
        'company_name': f['ask_company'] == true ? opt(_company) : null,
        'valid_from': type['valid_from'] ?? event['start_date'],
        'valid_to': type['valid_to'] ?? event['end_date'],
        'reentry': type['reentry'] == true,
        'status': 'valid',
        'checkins_count': 0,
        'created_at': now,
        '_local': true,
      };
      await LocalDb.put('ev_tickets', local);
      if (!mounted) return;
      await _checkin(local);
      if (!mounted) return;
      _showDone(
        local,
        'Bienvenue, ${_first.text.trim()} !',
        widget.print
            ? 'Récupérez votre ticket. Les jours suivants, tapez simplement votre numéro sur la borne.'
            : 'Notez votre numéro (ou prenez ce QR code en photo) : les jours suivants, tapez-le simplement sur la borne.',
        city: opt(_city),
      );
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _find(String raw) {
    _touch();
    final t = tkFindVisitor(widget.eventId, raw);
    if (t == null) {
      setState(() => _notFound = 'Aucune inscription trouvée pour « ${raw.trim()} ». Vérifiez le numéro du ticket, ou inscrivez-vous.');
      return;
    }
    _welcomeBack(t);
  }

  void _onDetect(BarcodeCapture capture) {
    final raw = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
    if (raw == null || DateTime.now().difference(_lastScan) < const Duration(seconds: 3)) return;
    _lastScan = DateTime.now();
    _find(raw);
  }

  // ------------------------------------------------------------------ sortie protégée

  /// Inscriptions et entrées du jour sur cette borne (visibles seulement derrière le code).
  (int, int) _todayCounts() {
    final day = _today();
    bool today(dynamic iso) => DateTime.tryParse('${iso ?? ''}')?.toLocal().toIso8601String().startsWith(day) == true;
    final regs = LocalDb.hasEntity('ev_ticket_orders')
        ? LocalDb.all('ev_ticket_orders').where((o) => o['event_id'] == widget.eventId && o['kiosk'] == widget.kioskName && today(o['registered_at'] ?? o['created_at'])).length
        : 0;
    final entries = LocalDb.hasEntity('ev_checkins')
        ? LocalDb.all('ev_checkins').where((c) => c['event_id'] == widget.eventId && c['gate'] == widget.kioskName && c['result'] == 'ok' && today(c['scanned_at'])).map((c) => c['ticket_id']).toSet().length
        : 0;
    return (regs, entries);
  }

  Future<void> _admin() async {
    final pin = TextEditingController();
    final r = await showDialog<String>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Borne « ${widget.kioskName} »')),
        content: SizedBox(
          width: 320,
          child: TextField(
            controller: pin,
            autofocus: true,
            obscureText: true,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            maxLength: 6,
            decoration: InputDecoration(labelText: tr('Code de sortie')),
            onSubmitted: (_) => Navigator.pop(c, 'check'),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, 'check'), child: Text(tr('Valider'))),
        ],
      ),
    );
    if (r != 'check' || !mounted) return;
    if (_hash(pin.text) != LocalDb.meta.get('kiosk_pin')) {
      toast(context, tr('Code incorrect.'), error: true);
      return;
    }
    final choice = await showDialog<String>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Borne « ${widget.kioskName} »')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Builder(builder: (_) {
            final (regs, entries) = _todayCounts();
            final pending = context.read<SyncEngine>().pendingCount;
            return Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(tr('Aujourd\'hui sur cette borne : $regs inscription(s), $entries entrée(s).${pending > 0 ? ' $pending enregistrement(s) en attente d\'envoi.' : ' Tout est envoyé au serveur.'}'),
                  style: const TextStyle(fontWeight: FontWeight.w600)),
            );
          }),
          ListTile(leading: const Icon(Icons.insights_outlined), title: Text(tr('Voir la fréquentation')), onTap: () => Navigator.pop(c, 'stats')),
          ListTile(leading: const Icon(Icons.sync), title: Text(tr('Synchroniser maintenant')), onTap: () => Navigator.pop(c, 'sync')),
          ListTile(leading: const Icon(Icons.logout, color: YColors.danger), title: Text(tr('Quitter le mode borne')), onTap: () => Navigator.pop(c, 'exit')),
        ]),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Revenir à la borne')))],
      ),
    );
    if (!mounted) return;
    switch (choice) {
      case 'exit':
        Navigator.of(context).pop();
      case 'sync':
        unawaited(context.read<SyncEngine>().sync());
        toast(context, tr('Synchronisation lancée.'));
      case 'stats':
        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => AttendanceScreen(eventId: widget.eventId)));
    }
  }

  // ------------------------------------------------------------------ affichage

  static const _bg = Color(0xFFF3F5F9);

  Color get _accent {
    final h = '${_form['kiosk_color'] ?? ''}'.replaceAll('#', '');
    final v = h.length == 6 ? int.tryParse(h, radix: 16) : null;
    return v == null ? YColors.night : Color(0xFF000000 | v);
  }

  Color get _onAccent => _accent.computeLuminance() > .5 ? YColors.night : Colors.white;

  /// Couleur de mise en avant sur le panneau : jaune Yomara sur le bleu nuit par défaut, sinon blanc.
  Color get _highlight => _accent == YColors.night ? YColors.yellow : _onAccent;

  ThemeData _kioskTheme(BuildContext context) {
    final base = Theme.of(context);
    OutlineInputBorder border(Color c, [double w = 1.2]) => OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: c, width: w));
    return base.copyWith(
      colorScheme: base.colorScheme.copyWith(primary: _accent, onPrimary: _onAccent),
      scaffoldBackgroundColor: _bg,
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 20),
        border: border(YColors.line),
        enabledBorder: border(YColors.line),
        focusedBorder: border(_accent, 2),
        errorBorder: border(YColors.danger),
        focusedErrorBorder: border(YColors.danger, 2),
        labelStyle: const TextStyle(fontSize: 17, color: YColors.muted),
        floatingLabelStyle: TextStyle(color: _accent, fontWeight: FontWeight.w600),
        prefixIconColor: YColors.muted,
      ),
    );
  }

  TextStyle _display(double size, {Color? color}) => TextStyle(fontFamily: 'Poppins', fontWeight: FontWeight.w700, fontSize: size, height: 1.1, letterSpacing: -.5, color: color ?? YColors.ink);

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      child: Listener(
        onPointerDown: (_) => _touch(),
        child: Theme(
          data: _kioskTheme(context),
          child: Scaffold(
            backgroundColor: _bg,
            body: LayoutBuilder(builder: (context, box) {
              final wide = box.maxWidth >= 1000;
              final Widget content = switch (_step) {
                _Step.form => _formView(wide),
                _Step.returning => _returningView(wide),
                _Step.done => _doneView(wide),
              };
              if (_step == _Step.done) return content;
              if (wide) {
                return Row(children: [
                  SizedBox(width: math.min(520, box.maxWidth * .38), child: _brandPanel(full: true)),
                  Expanded(child: content),
                ]);
              }
              return Column(children: [_brandPanel(full: false), Expanded(child: content)]);
            }),
          ),
        ),
      ),
    );
  }

  Widget _logoTile(double size) => widget.logo == null || _form['kiosk_logo'] == false
      ? const SizedBox.shrink()
      : Container(
          width: size,
          height: size,
          padding: EdgeInsets.all(size * .12),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(size * .24)),
          child: Image.memory(widget.logo!, fit: BoxFit.contain),
        );

  /// Nom de la borne (appui long : menu protégé) et état de l'envoi au serveur. Aucun compteur public.
  Widget _kioskChip(Color fg) {
    final pending = context.watch<SyncEngine>().pendingCount;
    return GestureDetector(
      onLongPress: _admin,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: fg.withValues(alpha: .08), borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(pending == 0 ? Icons.cloud_done_outlined : Icons.cloud_upload_outlined, size: 15, color: pending == 0 ? fg.withValues(alpha: .7) : YColors.warning),
          const SizedBox(width: 6),
          Text(tr(widget.kioskName), style: TextStyle(color: fg.withValues(alpha: .7), fontSize: 12.5, fontWeight: FontWeight.w600)),
        ]),
      ),
    );
  }

  String get _dates {
    final e = _event;
    return [
      if (e['start_date'] != null) dateFr(e['start_date']),
      if (e['end_date'] != null && e['end_date'] != e['start_date']) dateFr(e['end_date']),
    ].join(' au ');
  }

  /// Panneau aux couleurs de l'événement : à gauche sur grand écran, en bandeau sur une tablette en hauteur.
  Widget _brandPanel({required bool full}) {
    final e = _event;
    final on = _onAccent;
    final company = '${context.read<Session>().company['name'] ?? ''}';
    final place = [if (e['venue'] != null) '${e['venue']}', if (e['city'] != null) '${e['city']}'].join(', ');
    final action = _step == _Step.form
        ? FilledButton.icon(
            style: FilledButton.styleFrom(backgroundColor: _highlight, foregroundColor: _highlight == Colors.white ? _accent : YColors.night, padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 18), textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
            onPressed: () => _go(_Step.returning),
            icon: const Icon(Icons.confirmation_number_outlined, size: 22),
            label: Text(tr('Déjà inscrit ? Mon numéro')),
          )
        : OutlinedButton.icon(
            style: OutlinedButton.styleFrom(foregroundColor: on, side: BorderSide(color: on.withValues(alpha: .5), width: 1.5), padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 18), textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
            onPressed: _reset,
            icon: const Icon(Icons.edit_note, size: 24),
            label: Text(tr('Nouvelle inscription')),
          );
    final Widget body;
    if (full) {
      Widget step(int n, String label) => Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Row(children: [
              Container(
                width: 32,
                height: 32,
                alignment: Alignment.center,
                decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: _highlight, width: 2)),
                child: Text('$n', style: TextStyle(color: _highlight, fontWeight: FontWeight.w800)),
              ),
              const SizedBox(width: 14),
              Expanded(child: Text(tr(label), style: TextStyle(color: on, fontSize: 17))),
            ]),
          );
      body = Padding(
        padding: const EdgeInsets.fromLTRB(40, 36, 40, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            _logoTile(64),
            if (widget.logo != null && _form['kiosk_logo'] != false) const SizedBox(width: 14),
            Expanded(child: Text(tr(company), style: TextStyle(color: on.withValues(alpha: .8), fontSize: 15, fontWeight: FontWeight.w600))),
          ]),
          const Spacer(),
          Text(tr('${e['name'] ?? ''}'), style: _display(46, color: on)),
          const SizedBox(height: 18),
          if (_dates.isNotEmpty) _infoLine(Icons.event_outlined, _dates, on),
          if (place.isNotEmpty) _infoLine(Icons.place_outlined, place, on),
          const SizedBox(height: 22),
          Text(tr('${_form['kiosk_welcome'] ?? ''}'), style: TextStyle(color: on, fontSize: 19, height: 1.45)),
          const SizedBox(height: 28),
          if (_step == _Step.form) ...[
            step(1, 'Remplissez le formulaire'),
            step(2, widget.print ? 'Récupérez votre ticket' : 'Notez votre numéro de ticket'),
            step(3, 'Entrez, bonne visite !'),
          ],
          const Spacer(),
          SizedBox(width: double.infinity, child: action),
          const SizedBox(height: 18),
          _kioskChip(on),
        ]),
      );
    } else {
      body = Padding(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 18),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(tr(company), style: TextStyle(color: on.withValues(alpha: .8), fontSize: 13, fontWeight: FontWeight.w600))),
            _kioskChip(on),
          ]),
          const SizedBox(height: 10),
          Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
            _logoTile(54),
            if (widget.logo != null && _form['kiosk_logo'] != false) const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${e['name'] ?? ''}'), style: _display(26, color: on)),
                const SizedBox(height: 4),
                Text(tr([_dates, place].where((x) => x.isNotEmpty).join(' · ')), style: TextStyle(color: on.withValues(alpha: .8), fontSize: 14)),
              ]),
            ),
          ]),
          const SizedBox(height: 14),
          SizedBox(width: double.infinity, child: action),
        ]),
      );
    }
    return Container(
      color: _accent,
      child: Stack(children: [
        // Formes discrètes en arrière-plan
        Positioned.fill(child: CustomPaint(painter: _RingsPainter(on))),
        SafeArea(right: !full, child: body),
      ]),
    );
  }

  Widget _infoLine(IconData icon, String text, Color on) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(children: [
          Icon(icon, color: on.withValues(alpha: .75), size: 20),
          const SizedBox(width: 10),
          Expanded(child: Text(tr(text), style: TextStyle(color: on.withValues(alpha: .9), fontSize: 16))),
        ]),
      );

  InputDecoration _dec(String label, {IconData? icon}) => InputDecoration(labelText: tr(label), prefixIcon: icon == null ? null : Icon(icon));

  Widget _field(TextEditingController c, String label, {bool required = false, TextInputType? type, IconData? icon, String? Function(String)? check, TextCapitalization cap = TextCapitalization.sentences, String? autofill}) => Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: TextFormField(
          controller: c,
          keyboardType: type,
          textCapitalization: cap,
          autofillHints: autofill == null ? null : [autofill],
          style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w500),
          decoration: _dec(required ? label : '$label (facultatif)', icon: icon),
          onChanged: (_) => _touch(),
          validator: (v) {
            final s = (v ?? '').trim();
            if (required && s.isEmpty) return tr('À remplir');
            return s.isEmpty ? null : check?.call(s);
          },
        ),
      );

  Widget _section(String title, List<Widget> children) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(padding: const EdgeInsets.only(bottom: 12, top: 6), child: Text(tr(title), style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: YColors.inkSoft))),
          ...children,
        ]),
      );

  Widget _formView(bool wide) {
    final f = _form;
    final questions = (f['questions'] as List? ?? const []).map(asMap).toList();
    Widget pair(Widget a, Widget b) => wide ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: a), const SizedBox(width: 14), Expanded(child: b)]) : Column(children: [a, b]);
    final pad = wide ? 48.0 : 20.0;
    return Form(
      key: _formKey,
      child: Column(children: [
        Expanded(
          child: SingleChildScrollView(
            key: const ValueKey('form'),
            padding: EdgeInsets.fromLTRB(pad, wide ? 44 : 22, pad, 24),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 760),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('Inscription visiteur'), style: _display(wide ? 36 : 28)),
                  const SizedBox(height: 6),
                  Text(tr('Moins d\'une minute, puis vous entrez.'), style: const TextStyle(fontSize: 17, color: YColors.muted)),
                  const SizedBox(height: 26),
                  _section('Vous', [
                    pair(
                      _field(_first, 'Prénom', required: true, icon: Icons.person_outline, cap: TextCapitalization.words, autofill: AutofillHints.givenName),
                      _field(_last, 'Nom', required: true, cap: TextCapitalization.words, autofill: AutofillHints.familyName),
                    ),
                  ]),
                  _section('Vos coordonnées', [
                    pair(
                      _field(_phone, 'Téléphone', required: true, type: TextInputType.phone, icon: Icons.phone_outlined, check: (s) => s.replaceAll(RegExp(r'\D'), '').length < 8 ? tr('Numéro incomplet') : null),
                      _field(_email, 'E-mail', required: f['ask_email'] == 'required', type: TextInputType.emailAddress, icon: Icons.alternate_email, cap: TextCapitalization.none,
                          check: (s) => RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(s) ? null : tr('Adresse e-mail non valable')),
                    ),
                  ]),
                  if (f['ask_company'] == true || f['ask_job'] == true)
                    _section('Votre activité', [
                      pair(
                        f['ask_company'] == true ? _field(_company, 'Entreprise / organisation', icon: Icons.business_outlined) : const SizedBox.shrink(),
                        f['ask_job'] == true ? _field(_job, 'Fonction', icon: Icons.badge_outlined) : const SizedBox.shrink(),
                      ),
                    ]),
                  if (f['ask_city'] != 'off' || f['ask_country'] != 'off')
                    _section('D\'où venez-vous ?', [
                      pair(
                        f['ask_city'] != 'off' ? _field(_city, 'Ville / commune', required: f['ask_city'] == 'required', icon: Icons.place_outlined, cap: TextCapitalization.words) : const SizedBox.shrink(),
                        f['ask_country'] != 'off' ? _field(_country, 'Pays', required: f['ask_country'] == 'required', icon: Icons.public, cap: TextCapitalization.words) : const SizedBox.shrink(),
                      ),
                    ]),
                  for (final q in questions) _question(q, wide),
                  const SizedBox(height: 8),
                  _consentCard(),
                ]),
              ),
            ),
          ),
        ),
        // Barre d'action toujours visible
        Container(
          decoration: const BoxDecoration(color: Colors.white, border: Border(top: BorderSide(color: YColors.line))),
          padding: EdgeInsets.fromLTRB(pad, 14, pad, 14),
          child: SafeArea(
            top: false,
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 760),
                child: Row(children: [
                  TextButton.icon(
                    style: TextButton.styleFrom(foregroundColor: YColors.muted, padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 18)),
                    onPressed: _reset,
                    icon: const Icon(Icons.refresh),
                    label: Text(tr('Effacer'), style: const TextStyle(fontSize: 16)),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: FilledButton(
                      style: FilledButton.styleFrom(
                        backgroundColor: _accent,
                        foregroundColor: _onAccent,
                        padding: const EdgeInsets.symmetric(vertical: 22),
                        textStyle: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                      ),
                      onPressed: _saving ? null : _submit,
                      child: _saving
                          ? SizedBox(width: 26, height: 26, child: CircularProgressIndicator(strokeWidth: 3, color: _onAccent))
                          : Row(mainAxisAlignment: MainAxisAlignment.center, children: [Text(tr('Valider mon inscription')), const SizedBox(width: 12), const Icon(Icons.arrow_forward_rounded, size: 26)]),
                    ),
                  ),
                ]),
              ),
            ),
          ),
        ),
      ]),
    );
  }

  Widget _consentCard() {
    final text = '${_form['consent_text'] ?? ''}'.replaceAll('{societe}', '${context.read<Session>().company['name'] ?? ''}');
    if (text.trim().isEmpty) return const SizedBox.shrink();
    return Material(
      color: Colors.white,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: _consent ? _accent : YColors.line, width: _consent ? 2 : 1.2)),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () => setState(() => _consent = !_consent),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
          child: Row(children: [
            Icon(Icons.mark_email_read_outlined, color: _consent ? _accent : YColors.muted),
            const SizedBox(width: 14),
            Expanded(child: Text(tr(text), style: const TextStyle(fontSize: 16, height: 1.35))),
            Switch(value: _consent, onChanged: (v) => setState(() => _consent = v)),
          ]),
        ),
      ),
    );
  }

  /// Réponse choisie dans une liste : grandes tuiles faciles à toucher.
  Widget _tile(String label, bool selected, VoidCallback onTap, {double? width}) => SizedBox(
        width: width,
        child: Material(
          color: selected ? _accent : Colors.white,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: selected ? _accent : YColors.line, width: 1.2)),
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: () {
              _touch();
              onTap();
            },
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 17),
              child: Row(mainAxisSize: width == null ? MainAxisSize.min : MainAxisSize.max, children: [
                Icon(selected ? Icons.check_circle : Icons.circle_outlined, size: 22, color: selected ? _onAccent : YColors.muted),
                const SizedBox(width: 10),
                Flexible(child: Text(tr(label), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600, color: selected ? _onAccent : YColors.ink))),
              ]),
            ),
          ),
        ),
      );

  Widget _question(Map<String, dynamic> q, bool wide) {
    final id = '${q['id']}';
    final label = '${q['label']}${q['required'] == true ? '' : ' (facultatif)'}';
    switch (q['type']) {
      case 'select':
        final options = [for (final o in (q['options'] as List? ?? const [])) '$o'];
        return _section(label, [
          LayoutBuilder(builder: (context, box) {
            final cols = wide ? (options.length <= 4 ? 2 : 3) : 2;
            final w = (box.maxWidth - 12 * (cols - 1)) / cols;
            return Wrap(spacing: 12, runSpacing: 12, children: [
              for (final o in options) _tile(o, _answers[id] == o, () => setState(() => _answers[id] = _answers[id] == o ? null : o), width: w),
            ]);
          }),
          const SizedBox(height: 8),
        ]);
      case 'checkbox':
        return Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: _tile('${q['label']}', _answers[id] == true, () => setState(() => _answers[id] = _answers[id] != true), width: double.infinity),
        );
      default:
        final c = _text.putIfAbsent(id, () => TextEditingController());
        return Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: TextField(
            controller: c,
            minLines: q['type'] == 'textarea' ? 2 : 1,
            maxLines: q['type'] == 'textarea' ? 4 : 1,
            keyboardType: q['type'] == 'number' ? TextInputType.number : (q['type'] == 'phone' ? TextInputType.phone : null),
            style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w500),
            decoration: _dec(label),
            onChanged: (v) {
              _answers[id] = v;
              _touch();
            },
          ),
        );
    }
  }

  // ------------------------------------------------------------------ visiteur qui revient : pavé numérique

  /// Chiffres saisis, présentés comme un numéro de ticket (01-0042) ou un téléphone (07 07 07 07 01).
  String get _lookupDisplay {
    final raw = _lookup.text;
    if (raw.isEmpty) return '';
    if (RegExp(r'[A-Za-z]').hasMatch(raw)) return raw.toUpperCase();
    if (raw.length <= 6) return raw.length > 2 ? '${raw.substring(0, 2)}-${raw.substring(2)}' : raw;
    return [for (var i = 0; i < raw.length; i += 2) raw.substring(i, math.min(i + 2, raw.length))].join(' ');
  }

  void _key(String k) {
    _touch();
    setState(() {
      _notFound = null;
      if (k == 'del') {
        if (_lookup.text.isNotEmpty) _lookup.text = _lookup.text.substring(0, _lookup.text.length - 1);
      } else if (_lookup.text.length < 14) {
        _lookup.text += k;
      }
    });
  }

  /// Clavier physique ou douchette : chiffres, lettres (anciens codes), effacement, Entrée.
  KeyEventResult _onKey(FocusNode _, KeyEvent e) {
    if (e is! KeyDownEvent) return KeyEventResult.ignored;
    if (e.logicalKey == LogicalKeyboardKey.backspace) {
      _key('del');
    } else if (e.logicalKey == LogicalKeyboardKey.enter || e.logicalKey == LogicalKeyboardKey.numpadEnter) {
      if (_lookup.text.isNotEmpty) _find(_lookup.text);
    } else if (e.character != null && RegExp(r'^[A-Za-z0-9]$').hasMatch(e.character!)) {
      _key(e.character!.toUpperCase());
    } else {
      return KeyEventResult.ignored;
    }
    return KeyEventResult.handled;
  }

  Widget _returningView(bool wide) {
    Widget key(String k, {Widget? child, Color? color, Color? fg, VoidCallback? onTap}) => Expanded(
          child: Padding(
            padding: const EdgeInsets.all(6),
            child: SizedBox(
              height: wide ? 78 : 68,
              child: Material(
                color: color ?? Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: color == null ? YColors.line : Colors.transparent)),
                child: InkWell(
                  borderRadius: BorderRadius.circular(16),
                  onTap: onTap ?? () => _key(k),
                  child: Center(child: child ?? Text(k, style: TextStyle(fontSize: 30, fontWeight: FontWeight.w700, color: fg ?? YColors.ink))),
                ),
              ),
            ),
          ),
        );
    final display = _lookupDisplay;
    final pad = Column(children: [
      for (final row in const [
        ['1', '2', '3'],
        ['4', '5', '6'],
        ['7', '8', '9'],
      ])
        Row(children: [for (final k in row) key(k)]),
      Row(children: [
        key('del', child: const Icon(Icons.backspace_outlined, size: 28, color: YColors.inkSoft)),
        key('0'),
        key('ok',
            color: _lookup.text.isEmpty ? YColors.line : _accent,
            onTap: _lookup.text.isEmpty ? () {} : () => _find(_lookup.text),
            child: Text(tr('Valider'), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: _lookup.text.isEmpty ? YColors.muted : _onAccent))),
      ]),
    ]);
    final camera = _cam == null
        ? null
        : ClipRRect(
            borderRadius: BorderRadius.circular(20),
            child: SizedBox(
              height: wide ? 300 : 200,
              child: MobileScanner(
                controller: _cam,
                onDetect: _onDetect,
                errorBuilder: (context, error) => Container(
                  color: YColors.night,
                  alignment: Alignment.center,
                  padding: const EdgeInsets.all(20),
                  child: Text(tr('Caméra indisponible : utilisez le pavé.'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white70)),
                ),
                // Cadre de visée : seulement quand la caméra fonctionne
                overlayBuilder: (context, _) => IgnorePointer(
                  child: Stack(children: [
                    Center(child: Container(width: 170, height: 170, decoration: BoxDecoration(border: Border.all(color: Colors.white, width: 3), borderRadius: BorderRadius.circular(18)))),
                    Positioned(left: 0, right: 0, bottom: 10, child: Text(tr('ou présentez le QR code de votre ticket'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, shadows: [Shadow(blurRadius: 6)]))),
                  ]),
                ),
              ),
            ),
          );
    return Focus(
      autofocus: true,
      onKeyEvent: _onKey,
      child: SingleChildScrollView(
        key: const ValueKey('returning'),
        padding: EdgeInsets.fromLTRB(wide ? 48 : 20, wide ? 44 : 22, wide ? 48 : 20, 24),
        child: Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: camera != null && wide ? 900 : 520),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('Bon retour !'), style: _display(wide ? 36 : 28)),
              const SizedBox(height: 6),
              Text(tr('Tapez le numéro de votre ticket, ou votre numéro de téléphone.'), style: const TextStyle(fontSize: 17, color: YColors.muted)),
              const SizedBox(height: 24),
              Builder(builder: (_) {
                final left = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Container(
                    height: 92,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18), border: Border.all(color: _notFound != null ? YColors.danger : _accent, width: 2)),
                    child: Text(
                      display.isEmpty ? '01-0042' : display,
                      style: TextStyle(fontFamily: 'Poppins', fontSize: 40, fontWeight: FontWeight.w700, letterSpacing: 3, color: display.isEmpty ? YColors.line : YColors.ink),
                    ),
                  ),
                  if (_notFound != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Row(children: [
                        const Icon(Icons.error_outline, color: YColors.danger),
                        const SizedBox(width: 8),
                        Expanded(child: Text(tr(_notFound!), style: const TextStyle(color: YColors.danger, fontSize: 15.5))),
                      ]),
                    ),
                  const SizedBox(height: 14),
                  pad,
                ]);
                if (camera == null) return left;
                return wide
                    ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: left), const SizedBox(width: 28), Expanded(child: camera)])
                    : Column(children: [left, const SizedBox(height: 16), camera]);
              }),
            ]),
          ),
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ confirmation

  Widget _doneView(bool wide) {
    final t = _ticket;
    final ok = _color != YColors.warning;
    final total = ok ? 5 : 10;
    final on = _onAccent;
    return Container(
      key: const ValueKey('done'),
      color: _accent,
      child: Stack(children: [
        Positioned.fill(child: CustomPaint(painter: _RingsPainter(on))),
        Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 520),
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: .92, end: 1),
                duration: const Duration(milliseconds: 380),
                curve: Curves.easeOutBack,
                builder: (context, s, child) => Transform.scale(scale: s, child: child),
                child: Container(
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(26), boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .25), blurRadius: 40, offset: const Offset(0, 18))]),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(30, 34, 30, 22),
                      child: Column(children: [
                        Container(
                          width: 76,
                          height: 76,
                          decoration: BoxDecoration(color: (ok ? _color : YColors.warning).withValues(alpha: .12), shape: BoxShape.circle),
                          child: Icon(ok ? Icons.check_rounded : Icons.info_outline, color: ok ? _color : YColors.warning, size: 46),
                        ),
                        const SizedBox(height: 16),
                        Text(tr(_title), textAlign: TextAlign.center, style: _display(32)),
                        const SizedBox(height: 10),
                        Text(tr(_message), textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, height: 1.45, color: YColors.inkSoft)),
                      ]),
                    ),
                    if (t != null && ok) ...[
                      const _TicketDivider(),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(30, 20, 30, 6),
                        child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                          QrView(data: '${t['code']}', size: 118),
                          const SizedBox(width: 22),
                          Flexible(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(tr('Votre numéro'), style: const TextStyle(color: YColors.muted, fontSize: 15)),
                              FittedBox(child: Text(tr(kioskRef('${t['code']}')), style: _display(44, color: YColors.night))),
                              if (widget.print)
                                Row(children: [
                                  Icon(Icons.print_outlined, size: 18, color: _accent),
                                  const SizedBox(width: 6),
                                  Text(tr('Impression du ticket…'), style: TextStyle(color: _accent, fontWeight: FontWeight.w600)),
                                ]),
                            ]),
                          ),
                        ]),
                      ),
                    ],
                    Padding(
                      padding: const EdgeInsets.fromLTRB(30, 18, 30, 26),
                      child: Column(children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(4),
                          child: LinearProgressIndicator(value: (total - _backIn) / total, minHeight: 5, color: _accent, backgroundColor: _accent.withValues(alpha: .12)),
                        ),
                        const SizedBox(height: 16),
                        SizedBox(
                          width: double.infinity,
                          child: FilledButton(
                            style: FilledButton.styleFrom(backgroundColor: _accent, foregroundColor: on, padding: const EdgeInsets.symmetric(vertical: 18), textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
                            onPressed: _reset,
                            child: Text(tr('Visiteur suivant')),
                          ),
                        ),
                      ]),
                    ),
                  ]),
                ),
              ),
            ),
          ),
        ),
      ]),
    );
  }
}

/// Séparation façon ticket : pointillés et encoches sur les bords.
class _TicketDivider extends StatelessWidget {
  const _TicketDivider();

  @override
  Widget build(BuildContext context) {
    final bg = Theme.of(context).colorScheme.primary;
    return SizedBox(
      height: 28,
      child: Stack(alignment: Alignment.center, children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: LayoutBuilder(builder: (context, box) {
            final n = (box.maxWidth / 12).floor();
            return Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [for (var i = 0; i < n; i++) Container(width: 6, height: 2, color: YColors.line)]);
          }),
        ),
        Positioned(left: -14, child: Container(width: 28, height: 28, decoration: BoxDecoration(color: bg, shape: BoxShape.circle))),
        Positioned(right: -14, child: Container(width: 28, height: 28, decoration: BoxDecoration(color: bg, shape: BoxShape.circle))),
      ]),
    );
  }
}

/// Grands anneaux très légers en fond des panneaux de couleur.
class _RingsPainter extends CustomPainter {
  _RingsPainter(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()
      ..style = PaintingStyle.stroke
      ..color = color.withValues(alpha: .06);
    final c = Offset(size.width * 1.05, size.height * .02);
    for (var i = 1; i <= 5; i++) {
      p.strokeWidth = 26.0 - i * 3;
      canvas.drawCircle(c, size.shortestSide * .22 * i, p);
    }
    canvas.drawCircle(Offset(-size.width * .15, size.height * 1.02), size.shortestSide * .55, Paint()..color = color.withValues(alpha: .04));
  }

  @override
  bool shouldRepaint(_RingsPainter old) => old.color != color;
}
