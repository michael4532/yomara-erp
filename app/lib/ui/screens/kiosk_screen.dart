import 'dart:async';
import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';

import '../../core/billetterie.dart';
import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../i18n/i18n.dart';
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
              controller: pin,
              keyboardType: TextInputType.number,
              obscureText: true,
              maxLength: 6,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(labelText: tr('Code de sortie (4 à 6 chiffres)'), helperText: tr('Demandé pour quitter la borne. Restez discret en le tapant.')),
            ),
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
  if (!context.mounted) return;
  await Navigator.of(context).push(MaterialPageRoute(
    fullscreenDialog: true,
    builder: (_) => KioskScreen(eventId: '${event['id']}', kioskName: name.text.trim(), typeId: typeId!),
  ));
}

enum _Step { welcome, form, returning, done }

class KioskScreen extends StatefulWidget {
  const KioskScreen({super.key, required this.eventId, required this.kioskName, required this.typeId});

  final String eventId;
  final String kioskName;
  final String typeId;

  @override
  State<KioskScreen> createState() => _KioskScreenState();
}

class _KioskScreenState extends State<KioskScreen> {
  _Step _step = _Step.welcome;
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
    if (_step == _Step.welcome) return;
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
      _step = _Step.welcome;
    });
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

  void _showDone(Map<String, dynamic> t, String title, String message, {Color color = YColors.success}) {
    _ticket = t;
    _title = title;
    _message = message;
    _color = color;
    _go(_Step.done);
    HapticFeedback.mediumImpact();
    _backIn = 25;
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
      _showDone(t, 'Bon retour${who.isEmpty ? '' : ', $who'} !', 'Votre entrée d\'aujourd\'hui est enregistrée. Bonne visite !');
    } else if (r.result == 'duplicate') {
      _showDone(t, 'Bienvenue${who.isEmpty ? '' : ', $who'} !', 'Votre entrée d\'aujourd\'hui était déjà enregistrée. Bonne visite !', color: YColors.blue);
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
      final ticket = {'id': newId(), 'type_id': widget.typeId, 'code': tkNewCode(), 'token': tkNewToken(), 'holder_name': name};
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
        'Votre inscription est enregistrée. Pour revenir les autres jours, présentez ce QR code (prenez-le en photo) ou donnez simplement votre numéro.${_email.text.trim().isEmpty ? '' : ' Il vous est aussi envoyé par e-mail.'}',
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
      setState(() => _notFound = 'Aucune inscription trouvée pour « ${raw.trim()} ». Vérifiez le numéro, ou inscrivez-vous.');
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

  @override
  Widget build(BuildContext context) {
    final event = _event;
    final wide = MediaQuery.sizeOf(context).width >= 900;
    return PopScope(
      canPop: false,
      child: Listener(
        onPointerDown: (_) => _touch(),
        child: Scaffold(
          backgroundColor: _step == _Step.welcome ? YColors.night : Theme.of(context).scaffoldBackgroundColor,
          body: SafeArea(
            child: Column(children: [
              _StatusBar(eventId: widget.eventId, kiosk: widget.kioskName, dark: _step == _Step.welcome, onAdmin: _admin),
              Expanded(
                child: AnimatedSwitcher(
                  duration: const Duration(milliseconds: 250),
                  child: switch (_step) {
                    _Step.welcome => _welcome(event, wide),
                    _Step.form => _formView(wide),
                    _Step.returning => _returningView(),
                    _Step.done => _doneView(),
                  },
                ),
              ),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _welcome(Map<String, dynamic> event, bool wide) {
    final session = context.read<Session>();
    final dates = [
      if (event['start_date'] != null) dateFr(event['start_date']),
      if (event['end_date'] != null && event['end_date'] != event['start_date']) dateFr(event['end_date']),
    ].join(' au ');
    final big = wide ? 64.0 : 40.0;
    return Center(
      key: const ValueKey('welcome'),
      child: SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 820),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${session.company['name'] ?? ''}'), style: const TextStyle(color: YColors.yellow, fontWeight: FontWeight.w700, fontSize: 18)),
            const SizedBox(height: 14),
            Text(tr('${event['name'] ?? ''}'), style: TextStyle(color: Colors.white, fontSize: big, height: 1.05, fontWeight: FontWeight.w900)),
            const SizedBox(height: 12),
            Text(
              tr([dates, if (event['venue'] != null) '${event['venue']}', if (event['city'] != null) '${event['city']}'].where((s) => s.isNotEmpty).join(' · ')),
              style: const TextStyle(color: Colors.white70, fontSize: 18),
            ),
            const SizedBox(height: 28),
            Text(tr('${_form['kiosk_welcome'] ?? ''}'), style: const TextStyle(color: Colors.white, fontSize: 22, height: 1.35)),
            const SizedBox(height: 36),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: YColors.yellow, foregroundColor: YColors.night, padding: const EdgeInsets.symmetric(vertical: 26), textStyle: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18))),
                onPressed: () {
                  _country.text = '${_form['default_country'] ?? ''}';
                  _go(_Step.form);
                },
                icon: const Icon(Icons.edit_note, size: 32),
                label: Text(tr('Je m\'inscris')),
              ),
            ),
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white38, width: 1.5), padding: const EdgeInsets.symmetric(vertical: 22), textStyle: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18))),
                onPressed: () => _go(_Step.returning),
                icon: const Icon(Icons.qr_code_2, size: 28),
                label: Text(tr('Déjà inscrit ? Mon QR code ou mon numéro')),
              ),
            ),
          ]),
        ),
      ),
    );
  }

  InputDecoration _dec(String label, {String? hint, IconData? icon}) => InputDecoration(labelText: tr(label), hintText: hint == null ? null : tr(hint), prefixIcon: icon == null ? null : Icon(icon));

  Widget _field(TextEditingController c, String label, {bool required = false, TextInputType? type, IconData? icon, String? Function(String)? check, TextCapitalization cap = TextCapitalization.sentences, String? autofill}) => Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: TextFormField(
          controller: c,
          keyboardType: type,
          textCapitalization: cap,
          autofillHints: autofill == null ? null : [autofill],
          style: const TextStyle(fontSize: 19),
          decoration: _dec(required ? label : '$label (facultatif)', icon: icon),
          onChanged: (_) => _touch(),
          validator: (v) {
            final s = (v ?? '').trim();
            if (required && s.isEmpty) return tr('Champ obligatoire');
            return s.isEmpty ? null : check?.call(s);
          },
        ),
      );

  Widget _formView(bool wide) {
    final f = _form;
    final questions = (f['questions'] as List? ?? const []).map(asMap).toList();
    Widget pair(Widget a, Widget b) => wide ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: a), const SizedBox(width: 14), Expanded(child: b)]) : Column(children: [a, b]);
    return Form(
      key: _formKey,
      child: ListView(
        key: const ValueKey('form'),
        padding: EdgeInsets.symmetric(horizontal: wide ? 48 : 18, vertical: 18),
        children: [
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 820),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('Votre inscription'), style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w900)),
                const SizedBox(height: 4),
                Text(tr('Une minute, et vous entrez.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 16)),
                const SizedBox(height: 20),
                pair(
                  _field(_first, 'Prénom', required: true, icon: Icons.person_outline, cap: TextCapitalization.words, autofill: AutofillHints.givenName),
                  _field(_last, 'Nom', required: true, cap: TextCapitalization.words, autofill: AutofillHints.familyName),
                ),
                pair(
                  _field(_phone, 'Téléphone', required: true, type: TextInputType.phone, icon: Icons.phone_outlined, check: (s) => s.replaceAll(RegExp(r'\D'), '').length < 8 ? tr('Numéro incomplet') : null),
                  _field(_email, 'E-mail', required: f['ask_email'] == 'required', type: TextInputType.emailAddress, icon: Icons.alternate_email, cap: TextCapitalization.none,
                      check: (s) => RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(s) ? null : tr('Adresse e-mail non valable')),
                ),
                if (f['ask_company'] == true || f['ask_job'] == true)
                  pair(
                    f['ask_company'] == true ? _field(_company, 'Entreprise / organisation', icon: Icons.business_outlined) : const SizedBox.shrink(),
                    f['ask_job'] == true ? _field(_job, 'Fonction') : const SizedBox.shrink(),
                  ),
                if (f['ask_city'] != 'off' || f['ask_country'] != 'off')
                  pair(
                    f['ask_city'] != 'off' ? _field(_city, 'Ville / commune', required: f['ask_city'] == 'required', icon: Icons.place_outlined, cap: TextCapitalization.words) : const SizedBox.shrink(),
                    f['ask_country'] != 'off' ? _field(_country, 'Pays', required: f['ask_country'] == 'required', icon: Icons.public, cap: TextCapitalization.words) : const SizedBox.shrink(),
                  ),
                for (final q in questions) _question(q),
                const SizedBox(height: 6),
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: _consent,
                  onChanged: (v) => setState(() => _consent = v == true),
                  title: Text(tr('${f['consent_text'] ?? ''}'.replaceAll('{societe}', '${context.read<Session>().company['name'] ?? ''}')), style: const TextStyle(fontSize: 16)),
                ),
                const SizedBox(height: 18),
                Row(children: [
                  OutlinedButton(
                    style: OutlinedButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20)),
                    onPressed: _reset,
                    child: Text(tr('Annuler'), style: const TextStyle(fontSize: 17)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: FilledButton.icon(
                      style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 20), textStyle: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800)),
                      onPressed: _saving ? null : _submit,
                      icon: _saving ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5)) : const Icon(Icons.check_circle_outline, size: 26),
                      label: Text(tr('Valider mon inscription')),
                    ),
                  ),
                ]),
                const SizedBox(height: 40),
              ]),
            ),
          ),
        ],
      ),
    );
  }

  Widget _question(Map<String, dynamic> q) {
    final id = '${q['id']}';
    final label = '${q['label']}${q['required'] == true ? '' : ' (facultatif)'}';
    switch (q['type']) {
      case 'select':
        return Padding(
          padding: const EdgeInsets.only(bottom: 16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(label), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            Wrap(spacing: 10, runSpacing: 10, children: [
              for (final o in (q['options'] as List? ?? const []))
                ChoiceChip(
                  label: Padding(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6), child: Text(tr('$o'), style: const TextStyle(fontSize: 17))),
                  selected: _answers[id] == '$o',
                  onSelected: (s) => setState(() => _answers[id] = s ? '$o' : null),
                ),
            ]),
          ]),
        );
      case 'checkbox':
        return SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('${q['label']}'), style: const TextStyle(fontSize: 17)), value: _answers[id] == true, onChanged: (v) => setState(() => _answers[id] = v));
      default:
        final c = _text.putIfAbsent(id, () => TextEditingController());
        return Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: TextField(
            controller: c,
            minLines: q['type'] == 'textarea' ? 2 : 1,
            maxLines: q['type'] == 'textarea' ? 4 : 1,
            keyboardType: q['type'] == 'number' ? TextInputType.number : (q['type'] == 'phone' ? TextInputType.phone : null),
            style: const TextStyle(fontSize: 19),
            decoration: _dec(label),
            onChanged: (v) {
              _answers[id] = v;
              _touch();
            },
          ),
        );
    }
  }

  Widget _returningView() => Center(
        key: const ValueKey('returning'),
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 640),
            child: Column(children: [
              Text(tr('Bon retour !'), style: const TextStyle(fontSize: 34, fontWeight: FontWeight.w900)),
              const SizedBox(height: 6),
              Text(tr(_cam != null ? 'Présentez votre QR code devant la caméra, ou tapez votre numéro.' : 'Scannez votre QR code ou tapez votre numéro de téléphone.'),
                  textAlign: TextAlign.center, style: TextStyle(fontSize: 17, color: Theme.of(context).hintColor)),
              const SizedBox(height: 18),
              if (_cam != null)
                ClipRRect(
                  borderRadius: BorderRadius.circular(20),
                  child: SizedBox(
                    height: 300,
                    child: MobileScanner(
                      controller: _cam,
                      onDetect: _onDetect,
                      errorBuilder: (context, error) => Container(color: YColors.night, alignment: Alignment.center, padding: const EdgeInsets.all(20), child: Text(tr('Caméra indisponible : tapez votre numéro.'), style: const TextStyle(color: Colors.white))),
                    ),
                  ),
                ),
              const SizedBox(height: 16),
              TextField(
                controller: _lookup,
                autofocus: _cam == null,
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
                decoration: _dec('Téléphone, e-mail ou code du billet', icon: Icons.search),
                onSubmitted: _find,
                onChanged: (_) => _touch(),
              ),
              if (_notFound != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr(_notFound!), textAlign: TextAlign.center, style: const TextStyle(color: YColors.danger, fontSize: 16))),
              const SizedBox(height: 18),
              Row(children: [
                OutlinedButton(style: OutlinedButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20)), onPressed: _reset, child: Text(tr('Retour'), style: const TextStyle(fontSize: 17))),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton(
                    style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 20), textStyle: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                    onPressed: () => _find(_lookup.text),
                    child: Text(tr('Valider')),
                  ),
                ),
              ]),
              if (_notFound != null)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: TextButton.icon(
                    onPressed: () {
                      _country.text = '${_form['default_country'] ?? ''}';
                      _go(_Step.form);
                    },
                    icon: const Icon(Icons.edit_note),
                    label: Text(tr('Je m\'inscris'), style: const TextStyle(fontSize: 18)),
                  ),
                ),
            ]),
          ),
        ),
      );

  Widget _doneView() {
    final t = _ticket;
    return Center(
      key: const ValueKey('done'),
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 640),
          child: Column(children: [
            Icon(_color == YColors.warning ? Icons.info : Icons.check_circle, color: _color, size: 88),
            const SizedBox(height: 8),
            Text(tr(_title), textAlign: TextAlign.center, style: const TextStyle(fontSize: 36, fontWeight: FontWeight.w900)),
            const SizedBox(height: 10),
            Text(tr(_message), textAlign: TextAlign.center, style: const TextStyle(fontSize: 18, height: 1.4)),
            if (t != null && _color != YColors.warning) ...[
              const SizedBox(height: 22),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18), border: Border.all(color: YColors.line)),
                child: Column(children: [
                  QrView(data: '${t['code']}', size: 220),
                  const SizedBox(height: 8),
                  Text(tr('${t['code']}'), style: const TextStyle(color: YColors.night, fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: 3)),
                ]),
              ),
            ],
            const SizedBox(height: 24),
            FilledButton(
              style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 40, vertical: 20), textStyle: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              onPressed: _reset,
              child: Text(tr('Terminer ($_backIn)')),
            ),
          ]),
        ),
      ),
    );
  }
}

/// Bandeau de la borne : nom (appui long = menu protégé), inscrits et entrées du jour ici, état de la synchronisation.
class _StatusBar extends StatelessWidget {
  const _StatusBar({required this.eventId, required this.kiosk, required this.dark, required this.onAdmin});

  final String eventId;
  final String kiosk;
  final bool dark;
  final VoidCallback onAdmin;

  @override
  Widget build(BuildContext context) {
    final sync = context.watch<SyncEngine>();
    final fg = dark ? Colors.white70 : Theme.of(context).hintColor;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('ev_checkins'),
      builder: (context, _, _) {
        final day = _today();
        final regs = LocalDb.hasEntity('ev_ticket_orders')
            ? LocalDb.all('ev_ticket_orders').where((o) => o['event_id'] == eventId && o['kiosk'] == kiosk && DateTime.tryParse('${o['registered_at'] ?? o['created_at'] ?? ''}')?.toLocal().toIso8601String().startsWith(day) == true).length
            : 0;
        final entries = LocalDb.hasEntity('ev_checkins')
            ? LocalDb.all('ev_checkins')
                .where((c) => c['event_id'] == eventId && c['gate'] == kiosk && c['result'] == 'ok' && DateTime.tryParse('${c['scanned_at'] ?? ''}')?.toLocal().toIso8601String().startsWith(day) == true)
                .map((c) => c['ticket_id'])
                .toSet()
                .length
            : 0;
        final pending = sync.pendingCount;
        return Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: Row(children: [
            GestureDetector(
              onLongPress: onAdmin,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(border: Border.all(color: fg.withValues(alpha: .35)), borderRadius: BorderRadius.circular(20)),
                child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.tablet_android, size: 16, color: fg), const SizedBox(width: 6), Text(tr(kiosk), style: TextStyle(color: fg, fontWeight: FontWeight.w700))]),
              ),
            ),
            const Spacer(),
            Text(tr('Aujourd\'hui ici : $regs inscrit(s) · $entries entrée(s)'), style: TextStyle(color: fg, fontSize: 13)),
            const SizedBox(width: 12),
            Tooltip(
              message: trn(pending == 0 ? 'Tout est envoyé au serveur' : '$pending enregistrement(s) en attente d\'envoi'),
              child: Icon(pending == 0 ? Icons.cloud_done_outlined : Icons.cloud_upload_outlined, size: 20, color: pending == 0 ? (dark ? Colors.white54 : YColors.success) : YColors.warning),
            ),
          ]),
        );
      },
    );
  }
}
