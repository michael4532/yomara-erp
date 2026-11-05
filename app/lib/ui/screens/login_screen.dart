import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/config.dart';
import '../../core/firebase_setup.dart';
import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../i18n/language_picker.dart';

import 'package:yomara_erp/core/safe_launch.dart';
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, this.startRegister = false});

  /// Ouvre directement le formulaire « Créer mon entreprise » (depuis la présentation).
  final bool startRegister;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _server = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _company = TextEditingController();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _referral = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  bool _busy = false;
  // Adresse du serveur masquée en production : affichée seulement si elle manque ou ne répond pas
  bool _showServer = false;
  late bool _register = widget.startRegister;
  bool _obscure = true;
  bool _registrationAllowed = false;
  bool _googleAllowed = false;
  String? _error;
  // Coordonnées d'aide de l'éditeur (public/contact), chargées discrètement
  Map<String, dynamic>? _contact;

  @override
  void initState() {
    super.initState();
    final saved = LocalDb.meta.get('server_url') as String?;
    // Version web : servie par le serveur lui-même › adresse déduite automatiquement.
    _server.text = saved ?? (kIsWeb ? Uri.base.origin : AppConfig.defaultServer);
    _email.text = (LocalDb.meta.get('last_email') as String?) ?? '';
    if (_server.text.isEmpty) _showServer = true;
    if (_server.text.isNotEmpty) _checkServer(silent: true);
  }

  Future<void> _checkServer({bool silent = false}) async {
    try {
      final info = await context.read<Session>().probe(_server.text);
      if (mounted) {
        setState(() {
          _registrationAllowed = info['registration'] == true;
          _googleAllowed = info['google_signin'] == true && FirebaseSetup.googleSignInSupported;
        });
      }
      _loadContact();
    } catch (e) {
      // Serveur injoignable ou absent : on laisse saisir une autre adresse
      if (mounted) setState(() => _showServer = true);
      if (!silent && mounted) setState(() => _error = errorText(e));
    }
  }

  Future<void> _loadContact() async {
    try {
      final r = asMap(await ApiClient(baseUrl: ApiClient.normalizeUrl(_server.text)).get('public/contact'));
      final c = asMap(r['contact']);
      bool has(String k) => '${c[k] ?? ''}'.trim().isNotEmpty;
      if (mounted) setState(() => _contact = (has('whatsapp') || has('phone') || has('email')) ? c : null);
    } catch (_) {
      // Silencieux : le bloc d'aide n'est simplement pas affiché
    }
  }

  /// Lien de réinitialisation envoyé par e-mail (page web pour choisir le nouveau mot de passe).
  Future<void> _forgot() async {
    final c = TextEditingController(text: _email.text.trim());
    final email = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Mot de passe oublié')),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('Indiquez l\'e-mail de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.'), style: TextStyle(color: Theme.of(ctx).hintColor)),
          const SizedBox(height: 14),
          TextField(controller: c, autofocus: true, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: tr('E-mail'), prefixIcon: const Icon(Icons.alternate_email))),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('Recevoir le lien'))),
        ],
      ),
    );
    if (email == null || !email.contains('@') || !mounted) return;
    try {
      final r = asMap(await ApiClient(baseUrl: ApiClient.normalizeUrl(_server.text)).post('auth/forgot', {'email': email}));
      if (mounted) toast(context, '${r['message'] ?? tr('Lien envoyé.')}');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final session = context.read<Session>();
    try {
      await session.probe(_server.text);
      if (_register) {
        await session.register(_server.text, {
          'company_name': _company.text.trim(),
          'name': _name.text.trim(),
          'email': _email.text.trim(),
          'password': _password.text,
          if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
          if (_referral.text.trim().isNotEmpty) 'referral': _referral.text.trim().toUpperCase(),
        });
      } else {
        await session.login(_server.text, _email.text, _password.text);
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Connexion Google facultative : ouvre le compte qui a déjà la même adresse e-mail.
  Future<void> _google() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final session = context.read<Session>();
    try {
      await session.probe(_server.text);
      final idToken = await FirebaseSetup.googleIdToken();
      if (idToken == null) return; // choix du compte annulé
      await session.loginWithGoogle(_server.text, idToken);
    } catch (e) {
      if (mounted) setState(() => _error = e is ApiException ? errorText(e) : tr('Connexion Google impossible sur cet appareil. Connectez-vous avec votre e-mail et votre mot de passe.'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.of(context).size.width >= 900;
    final form = _buildForm(context);

    final language = TextButton.icon(
      style: TextButton.styleFrom(foregroundColor: wide ? null : Colors.white),
      onPressed: () => chooseLanguage(context),
      icon: const Icon(Icons.translate, size: 18),
      label: Text(I18n.languages[I18n.lang]!),
    );

    return Scaffold(
      // Ordinateur : choix de la langue en haut à droite ; téléphone : sur sa propre ligne, au-dessus du logo
      floatingActionButtonLocation: wide ? FloatingActionButtonLocation.endTop : null,
      floatingActionButton: wide ? Padding(padding: const EdgeInsets.only(top: 8), child: language) : null,
      body: wide
          ? Row(children: [
              Expanded(child: _BrandPanel(register: _register)),
              Expanded(child: Center(child: SingleChildScrollView(padding: const EdgeInsets.all(32), child: form))),
            ])
          : Container(
              color: YColors.night,
              child: SafeArea(
                child: Center(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.all(16),
                    child: Column(children: [
                      Align(alignment: Alignment.centerRight, child: language),
                      const SizedBox(height: 8),
                      const _Logo(light: true),
                      const SizedBox(height: 24),
                      Card(child: Padding(padding: const EdgeInsets.all(20), child: form)),
                    ]),
                  ),
                ),
              ),
            ),
    );
  }

  Widget _buildForm(BuildContext context) {
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 420),
      child: Form(
        key: _formKey,
        child: AutofillGroup(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            // Message de bienvenue (connexion / création) ; appui long : changer de serveur (support technique)
            GestureDetector(
              onLongPress: () => setState(() => _showServer = !_showServer),
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 380),
                switchInCurve: Curves.easeOutCubic,
                transitionBuilder: (c, a) => FadeTransition(opacity: a, child: SlideTransition(position: Tween(begin: const Offset(0, .08), end: Offset.zero).animate(a), child: c)),
                child: _Welcome(key: ValueKey(_register), register: _register),
              ),
            ),
            const SizedBox(height: 24),
            if (_error != null)
              Container(
                margin: const EdgeInsets.only(bottom: 16),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: YColors.danger.withValues(alpha: .08), borderRadius: BorderRadius.circular(8)),
                child: Text(tr(_error!), style: const TextStyle(color: YColors.danger)),
              ),
            if (_showServer)
              Field(
                label: tr('Adresse du serveur'),
                child: TextFormField(
                  controller: _server,
                  keyboardType: TextInputType.url,
                  decoration: InputDecoration(hintText: tr('erp.monentreprise.ci'), prefixIcon: Icon(Icons.dns_outlined, size: 20)),
                  validator: (v) => (v == null || v.trim().isEmpty) ? 'Indiquez l\'adresse fournie à l\'installation' : null,
                  onFieldSubmitted: (_) => _checkServer(),
                ),
              ),
            if (_register) ...[
              Field(
                label: tr('Nom de l\'entreprise'),
                child: TextFormField(controller: _company, validator: (v) => (v ?? '').trim().isEmpty ? 'Obligatoire' : null),
              ),
              Field(
                label: tr('Votre nom'),
                child: TextFormField(controller: _name, validator: (v) => (v ?? '').trim().isEmpty ? 'Obligatoire' : null),
              ),
              Field(
                label: tr('Téléphone (facultatif)'),
                child: TextFormField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(hintText: '+225 07 00 00 00 00')),
              ),
              Field(
                label: tr('Code parrain (facultatif)'),
                child: TextFormField(
                  controller: _referral,
                  textCapitalization: TextCapitalization.characters,
                  decoration: InputDecoration(hintText: tr('Ex. ABCD1234'), helperText: tr('Remise sur votre premier achat si un client vous a invité.')),
                ),
              ),
            ],
            Field(
              label: tr('E-mail'),
              child: TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autofillHints: const [AutofillHints.email],
                decoration: const InputDecoration(prefixIcon: Icon(Icons.alternate_email, size: 20)),
                validator: (v) => (v ?? '').contains('@') ? null : 'E-mail invalide',
              ),
            ),
            Field(
              label: tr('Mot de passe'),
              child: TextFormField(
                controller: _password,
                obscureText: _obscure,
                autofillHints: const [AutofillHints.password],
                decoration: InputDecoration(
                  prefixIcon: const Icon(Icons.lock_outline, size: 20),
                  suffixIcon: IconButton(
                    icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined, size: 20),
                    onPressed: () => setState(() => _obscure = !_obscure),
                  ),
                ),
                validator: (v) => (v ?? '').length < (_register ? 8 : 1) ? (_register ? '8 caractères minimum' : 'Obligatoire') : null,
                onFieldSubmitted: (_) => _submit(),
              ),
            ),
            if (!_register)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(onPressed: _forgot, child: Text(tr('Mot de passe oublié ?'))),
              ),
            const SizedBox(height: 8),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                  : Text(tr(_register ? 'Créer mon espace' : 'Se connecter')),
            ),
            if (_googleAllowed && !_register) ...[
              const SizedBox(height: 14),
              Row(children: [
                const Expanded(child: Divider()),
                Padding(padding: const EdgeInsets.symmetric(horizontal: 10), child: Text(tr('ou'), style: TextStyle(color: Theme.of(context).hintColor))),
                const Expanded(child: Divider()),
              ]),
              const SizedBox(height: 14),
              OutlinedButton(
                style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
                onPressed: _busy ? null : _google,
                child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  const _GoogleG(),
                  const SizedBox(width: 10),
                  Text(tr('Continuer avec Google'), style: const TextStyle(fontWeight: FontWeight.w600)),
                ]),
              ),
            ],
            const SizedBox(height: 12),
            Wrap(alignment: WrapAlignment.spaceBetween, children: [
              // Application installée : une entreprise avec son propre serveur saisit son adresse
              if (!kIsWeb && !_register)
                TextButton.icon(
                  onPressed: () {
                    setState(() => _showServer = !_showServer);
                    if (!_showServer) {
                      _server.text = AppConfig.defaultServer;
                      _checkServer(silent: true);
                    }
                  },
                  icon: const Icon(Icons.dns_outlined, size: 18),
                  label: Text(tr(_showServer ? 'Utiliser le serveur Yomara' : 'Mon entreprise a son propre serveur')),
                ),
              if (_registrationAllowed)
                TextButton(
                  onPressed: () => setState(() {
                    _register = !_register;
                    _error = null;
                  }),
                  child: Text(tr(_register ? 'J\'ai déjà un compte' : 'Créer un compte entreprise')),
                ),
            ]),
            AnimatedSize(
              duration: const Duration(milliseconds: 300),
              curve: Curves.easeOutCubic,
              child: _contact == null ? const SizedBox(width: double.infinity) : _HelpBlock(contact: _contact!),
            ),
          ]),
        ),
      ),
    );
  }
}

class _Logo extends StatelessWidget {
  const _Logo({this.light = false});

  final bool light;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        const YomaraMark(size: 40),
        const SizedBox(width: 12),
        Text.rich(TextSpan(children: [
          TextSpan(text: tr('Yomara '), style: TextStyle(color: light ? Colors.white : YColors.night)),
          TextSpan(text: tr('ERP'), style: TextStyle(color: YColors.yellow)),
        ]), style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
      ]);
}

/// En-tête du formulaire : salutation selon l'heure à la connexion, accueil et étapes à la création.
class _Welcome extends StatelessWidget {
  const _Welcome({super.key, required this.register});
  final bool register;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final h = DateTime.now().hour;
    final hello = h < 5 || h >= 18 ? 'Bonsoir' : 'Bonjour';
    final email = '${LocalDb.meta.get('last_email') ?? ''}';
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: (register ? YColors.success : YColors.blue).withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(register ? Icons.celebration_outlined : Icons.waving_hand_outlined, size: 16, color: register ? YColors.success : YColors.blue),
          const SizedBox(width: 6),
          Text(tr(register ? 'Bienvenue sur Yomara ERP' : (email.isNotEmpty ? '$hello, ravi de vous revoir' : '$hello et bienvenue')),
              style: TextStyle(color: register ? YColors.success : YColors.blue, fontWeight: FontWeight.w700, fontSize: 13)),
        ]),
      ),
      const SizedBox(height: 14),
      Text(tr(register ? 'Créez votre entreprise en 2 minutes' : 'Connectez-vous à votre entreprise'),
          style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800, letterSpacing: -.3)),
      const SizedBox(height: 8),
      Text(
        tr(register
            ? 'Gratuit, sans limite de durée ni d\'utilisateurs. Vos collègues vous rejoignent ensuite avec leur propre accès.'
            : 'Entrez l\'e-mail et le mot de passe de votre compte : l\'application retrouve votre société, vos droits et vos données, même sans connexion.'),
        style: TextStyle(color: theme.hintColor, height: 1.45),
      ),
      if (register) ...[
        const SizedBox(height: 16),
        Row(children: [
          for (final (i, t) in ['Votre entreprise', 'Votre compte', 'C\'est parti'].indexed) ...[
            if (i > 0) Expanded(child: Container(height: 2, margin: const EdgeInsets.symmetric(horizontal: 6), color: theme.dividerColor)),
            CircleAvatar(radius: 11, backgroundColor: i == 0 ? YColors.yellow : theme.dividerColor.withValues(alpha: .4),
                child: Text('${i + 1}', style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w800, color: i == 0 ? YColors.night : theme.hintColor))),
            const SizedBox(width: 6),
            Text(tr(t), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: i == 0 ? null : theme.hintColor)),
          ],
        ]),
      ],
    ]);
  }
}

/// Panneau de gauche (ordinateur) : halos animés, fonctionnalités qui défilent, modules et chiffres clés.
class _BrandPanel extends StatefulWidget {
  const _BrandPanel({required this.register});
  final bool register;

  @override
  State<_BrandPanel> createState() => _BrandPanelState();
}

class _BrandPanelState extends State<_BrandPanel> with TickerProviderStateMixin {
  late final _orbs = AnimationController(vsync: this, duration: const Duration(seconds: 14))..repeat(reverse: true);
  late final _marquee = AnimationController(vsync: this, duration: const Duration(seconds: 40))..repeat();
  late final _intro = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..forward();
  Timer? _timer;
  int _i = 0;

  static const _features = [
    (Icons.payments_outlined, Color(0xFF12B76A), 'Encaissez en Mobile Money', 'Wave, Orange Money, MTN, Moov : le paiement est rapproché de la facture tout seul.', 'Paiement Wave reçu', 'FAC-0142 · 250 000 F'),
    (Icons.cloud_off_outlined, Color(0xFF7CC4FF), 'Internet coupe ? Continuez', 'Vos ventes restent sur l\'appareil puis partent au serveur dès que le réseau revient.', 'Hors ligne', '12 ventes en attente d\'envoi'),
    (Icons.inventory_2_outlined, Color(0xFFFFC531), 'Stock toujours juste', 'Chaque vente sort le stock, chaque achat le remplit. Alerte avant la rupture.', 'Stock bas', 'Riz 25 kg : 4 sacs restants'),
    (Icons.badge_outlined, Color(0xFFC084FC), 'La paie en quelques clics', 'Bulletins conformes, CNPS et ITS, congés et pointage de vos équipes.', 'Bulletins prêts', '24 salariés · novembre'),
    (Icons.share_outlined, Color(0xFFFF7A9C), 'Tous vos réseaux sociaux', 'Planifiez une fois, publiez partout : Facebook, Instagram, LinkedIn, TikTok, Sensya…', 'Publication planifiée', 'Demain à 10:00 · 4 réseaux'),
  ];
  static const _modules = ['Ventes & factures', 'Caisse', 'Stock', 'CRM', 'Comptabilité SYSCOHADA', 'Paie', 'RH', 'Achats', 'Projets', 'Boîte mail', 'WhatsApp', 'Réseaux sociaux', 'Facture normalisée', 'Fidélité', 'Rendez-vous', 'Restaurant', 'Logistique', 'Assistant IA'];

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(milliseconds: 4200), (_) {
      if (mounted) setState(() => _i = (_i + 1) % _features.length);
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _orbs.dispose();
    _marquee.dispose();
    _intro.dispose();
    super.dispose();
  }

  Widget _fadeIn(double from, Widget child) => AnimatedBuilder(
        animation: _intro,
        builder: (_, c) {
          final t = Curves.easeOutCubic.transform(((_intro.value - from) / .45).clamp(0.0, 1.0));
          return Opacity(opacity: t, child: Transform.translate(offset: Offset(0, 18 * (1 - t)), child: c));
        },
        child: child,
      );

  @override
  Widget build(BuildContext context) {
    final f = _features[_i];
    return ClipRect(
      child: Stack(children: [
        Positioned.fill(child: Container(decoration: const BoxDecoration(gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color(0xFF0B1530), Color(0xFF12306B), Color(0xFF0B1530)])))),
        // Halos qui dérivent lentement
        AnimatedBuilder(
          animation: _orbs,
          builder: (context, _) {
            final t = Curves.easeInOut.transform(_orbs.value);
            return Stack(children: [
              Positioned(right: -120 + 60 * t, top: -140 + 50 * t, child: _orb(380, YColors.yellow.withValues(alpha: .22))),
              Positioned(left: -160 + 70 * t, bottom: -180 + 40 * t, child: _orb(420, const Color(0xFF2E6BE6).withValues(alpha: .35))),
            ]);
          },
        ),
        Positioned.fill(
          child: LayoutBuilder(builder: (context, c) {
            final pad = c.maxWidth > 640 ? 56.0 : 36.0;
            return SingleChildScrollView(
              child: ConstrainedBox(
                constraints: BoxConstraints(minHeight: c.maxHeight),
                child: Padding(
                  padding: EdgeInsets.fromLTRB(pad, 40, pad, 32),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
                    _fadeIn(0, const _Logo(light: true)),
                    const SizedBox(height: 34),
                    _fadeIn(.1, AnimatedSwitcher(
                      duration: const Duration(milliseconds: 400),
                      child: Text(
                        tr(widget.register ? 'Bienvenue !\nVotre entreprise\nmérite mieux qu\'un tableur.' : 'Toute votre entreprise\ndans un seul logiciel.'),
                        key: ValueKey(widget.register),
                        style: const TextStyle(color: Colors.white, fontSize: 36, fontWeight: FontWeight.w800, height: 1.15, letterSpacing: -.5),
                      ),
                    )),
                    const SizedBox(height: 28),
                    // Fonctionnalité du moment, avec une notification qui « arrive »
                    _fadeIn(.25, AnimatedSwitcher(
                      duration: const Duration(milliseconds: 550),
                      switchInCurve: Curves.easeOutCubic,
                      transitionBuilder: (c, a) => FadeTransition(opacity: a, child: SlideTransition(position: Tween(begin: const Offset(.06, 0), end: Offset.zero).animate(a), child: c)),
                      child: Container(
                        key: ValueKey(_i),
                        width: double.infinity,
                        constraints: const BoxConstraints(maxWidth: 480),
                        padding: const EdgeInsets.all(20),
                        decoration: BoxDecoration(color: Colors.white.withValues(alpha: .07), borderRadius: BorderRadius.circular(20), border: Border.all(color: Colors.white.withValues(alpha: .12))),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [
                            Container(width: 44, height: 44, decoration: BoxDecoration(color: f.$2.withValues(alpha: .18), borderRadius: BorderRadius.circular(13)), child: Icon(f.$1, color: f.$2)),
                            const SizedBox(width: 14),
                            Expanded(child: Text(tr(f.$3), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 18))),
                          ]),
                          const SizedBox(height: 10),
                          Text(tr(f.$4), style: const TextStyle(color: Color(0xFFC9D6F5), height: 1.45)),
                          const SizedBox(height: 14),
                          TweenAnimationBuilder<double>(
                            tween: Tween(begin: 0, end: 1),
                            duration: const Duration(milliseconds: 700),
                            curve: Curves.elasticOut,
                            builder: (_, v, c) => Transform.scale(scale: .85 + .15 * v, alignment: Alignment.centerLeft, child: Opacity(opacity: v.clamp(0, 1), child: c)),
                            child: Container(
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                              child: Row(mainAxisSize: MainAxisSize.min, children: [
                                Container(width: 9, height: 9, decoration: BoxDecoration(color: f.$2, shape: BoxShape.circle)),
                                const SizedBox(width: 10),
                                Text(tr(f.$5), style: const TextStyle(color: YColors.night, fontWeight: FontWeight.w800, fontSize: 13)),
                                const SizedBox(width: 8),
                                Text(tr(f.$6), style: const TextStyle(color: YColors.muted, fontSize: 12.5)),
                              ]),
                            ),
                          ),
                        ]),
                      ),
                    )),
                    const SizedBox(height: 14),
                    Row(children: [
                      for (var k = 0; k < _features.length; k++)
                        GestureDetector(
                          onTap: () => setState(() => _i = k),
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 300),
                            margin: const EdgeInsets.only(right: 6),
                            width: k == _i ? 22 : 7,
                            height: 7,
                            decoration: BoxDecoration(color: k == _i ? YColors.yellow : Colors.white24, borderRadius: BorderRadius.circular(7)),
                          ),
                        ),
                    ]),
                    const SizedBox(height: 30),
                    // Modules qui défilent
                    _fadeIn(.4, SizedBox(
                      height: 36,
                      child: ShaderMask(
                        shaderCallback: (r) => const LinearGradient(colors: [Colors.transparent, Colors.white, Colors.white, Colors.transparent], stops: [0, .08, .92, 1]).createShader(r),
                        blendMode: BlendMode.dstIn,
                        // ClipRect : sans lui, les puces qui débordent à droite échappent au fondu (« Réseaux soc…ux »)
                        child: ClipRect(child: AnimatedBuilder(
                          animation: _marquee,
                          builder: (context, _) => LayoutBuilder(builder: (context, c) {
                            return OverflowBox(
                              alignment: Alignment.centerLeft,
                              maxWidth: double.infinity,
                              child: Transform.translate(
                                offset: Offset(-_marquee.value * 1400, 0),
                                child: Row(mainAxisSize: MainAxisSize.min, children: [
                                  for (final m in [..._modules, ..._modules, ..._modules])
                                    Container(
                                      margin: const EdgeInsets.only(right: 8),
                                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                                      decoration: BoxDecoration(borderRadius: BorderRadius.circular(20), border: Border.all(color: Colors.white.withValues(alpha: .18))),
                                      child: Text(tr(m), style: const TextStyle(color: Color(0xFFDCE3F2), fontSize: 12.5, fontWeight: FontWeight.w600)),
                                    ),
                                ]),
                              ),
                            );
                          }),
                        )),
                      ),
                    )),
                    const SizedBox(height: 30),
                    _fadeIn(.55, Wrap(spacing: 28, runSpacing: 16, children: [
                      _stat(80, '+', 'modules à activer'),
                      _stat(6, '', 'écrans : web, PC, mobile'),
                      _stat(0, '', 'limite d\'utilisateurs'),
                    ])),
                    const SizedBox(height: 26),
                    _fadeIn(.7, Row(children: [
                      const Icon(Icons.verified_user_outlined, color: Color(0xFF6EE7B7), size: 18),
                      const SizedBox(width: 8),
                      Flexible(child: Text(tr('Données chiffrées, sauvegardées chaque jour, accessibles seulement à votre équipe.'), style: const TextStyle(color: Color(0xFFAEB9D3), fontSize: 13))),
                    ])),
                  ]),
                ),
              ),
            );
          }),
        ),
      ]),
    );
  }

  Widget _orb(double size, Color c) => Container(width: size, height: size, decoration: BoxDecoration(shape: BoxShape.circle, gradient: RadialGradient(colors: [c, c.withValues(alpha: 0)])));

  Widget _stat(int n, String suffix, String label) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: n.toDouble()),
          duration: const Duration(milliseconds: 1600),
          curve: Curves.easeOutCubic,
          builder: (_, v, _) => Text('${v.round()}$suffix', style: const TextStyle(color: YColors.yellow, fontSize: 30, fontWeight: FontWeight.w800, height: 1)),
        ),
        const SizedBox(height: 4),
        Text(tr(label), style: const TextStyle(color: Color(0xFFC9D6F5), fontSize: 12.5)),
      ]);
}

/// « Besoin d'aide ? » : WhatsApp, appel, e-mail et horaires de l'éditeur, sous le formulaire de connexion.
class _HelpBlock extends StatelessWidget {
  const _HelpBlock({required this.contact});

  final Map<String, dynamic> contact;

  String _s(String k) => '${contact[k] ?? ''}'.trim();

  Future<void> _open(Uri uri) async {
    try {
      await safeLaunchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final phone = _s('phone');
    final wa = _s('whatsapp').isNotEmpty ? _s('whatsapp') : phone;
    final waDigits = wa.replaceAll(RegExp(r'\D'), '');
    final telDigits = phone.replaceAll(RegExp(r'[^\d+]'), '');
    final email = _s('email');
    final hours = _s('hours');
    final buttons = <Widget>[
      if (waDigits.isNotEmpty)
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: const Color(0xFF25D366), foregroundColor: Colors.white, visualDensity: VisualDensity.compact),
          onPressed: () => _open(Uri.parse('https://wa.me/$waDigits?text=${Uri.encodeComponent(tr('Bonjour, j\'ai besoin d\'aide pour me connecter à Yomara ERP.'))}')),
          icon: const Icon(Icons.chat_outlined, size: 18),
          label: Text(tr('WhatsApp')),
        ),
      if (telDigits.isNotEmpty)
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(visualDensity: VisualDensity.compact),
          onPressed: () => _open(Uri(scheme: 'tel', path: telDigits)),
          icon: const Icon(Icons.call_outlined, size: 18),
          label: Text(tr('Appeler')),
        ),
      if (email.isNotEmpty)
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(visualDensity: VisualDensity.compact),
          onPressed: () => _open(Uri(scheme: 'mailto', path: email, query: 'subject=${Uri.encodeComponent(tr('Aide à la connexion Yomara ERP'))}')),
          icon: const Icon(Icons.mail_outline_rounded, size: 18),
          label: Text(tr('E-mail')),
        ),
    ];
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        const Divider(height: 1),
        const SizedBox(height: 14),
        Row(children: [
          Icon(Icons.support_agent_rounded, size: 18, color: hint),
          const SizedBox(width: 8),
          Expanded(child: Text(tr('Besoin d\'aide ?'), style: TextStyle(fontWeight: FontWeight.w600, color: hint))),
        ]),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 8, children: buttons),
        if (hours.isNotEmpty) ...[
          const SizedBox(height: 8),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(Icons.schedule_rounded, size: 14, color: hint),
            const SizedBox(width: 6),
            Expanded(child: Text(tr(hours), style: TextStyle(fontSize: 12, color: hint))),
          ]),
        ],
      ]),
    );
  }
}


/// Logo « G » de Google, dessiné (quatre couleurs officielles), pour le bouton de connexion.
class _GoogleG extends StatelessWidget {
  const _GoogleG();

  @override
  Widget build(BuildContext context) => SizedBox(width: 20, height: 20, child: CustomPaint(painter: _GPainter()));
}

class _GPainter extends CustomPainter {
  @override
  void paint(Canvas c, Size s) {
    final r = s.width / 2, w = s.width * .2;
    final rect = Rect.fromCircle(center: Offset(r, r), radius: r - w / 2);
    Paint p(int col) => Paint()..color = Color(col)..style = PaintingStyle.stroke..strokeWidth = w;
    const deg = 3.14159265 / 180;
    c.drawArc(rect, -40 * deg, -100 * deg, false, p(0xFFEA4335));
    c.drawArc(rect, -140 * deg, -90 * deg, false, p(0xFFFBBC05));
    c.drawArc(rect, -230 * deg, -95 * deg, false, p(0xFF34A853));
    c.drawArc(rect, -325 * deg, -35 * deg, false, p(0xFF4285F4));
    c.drawRect(Rect.fromLTWH(r, r - w / 2, r - w / 4, w), Paint()..color = const Color(0xFF4285F4));
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
