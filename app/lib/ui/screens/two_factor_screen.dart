import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/qr_view.dart';

/// Double authentification : code demandé à la connexion, activation (application ou e-mail), codes de secours.

// ---------------------------------------------------------------------------
// Connexion : saisie du code

/// Fenêtre « Code de vérification » après un mot de passe correct. La fenêtre se ferme dès que le code est accepté ;
/// la session s'ouvre ensuite (chargement des données) : à attendre par l'écran de connexion.
Future<bool> showTwoFactorLogin(BuildContext context, TwoFactorChallenge ch) async {
  final session = context.read<Session>();
  final res = await showDialog<Map<String, dynamic>>(context: context, barrierDismissible: false, builder: (_) => _LoginCodeDialog(ch: ch));
  if (res == null) return false;
  await session.finishTwoFactor(ch, res);
  final left = res['recovery_left'];
  if (left is num && left <= 3 && context.mounted) {
    toast(context, tr('Il vous reste $left code(s) de secours : générez-en de nouveaux dans Paramètres → Mon compte → Sécurité.'));
  }
  return true;
}

class _LoginCodeDialog extends StatefulWidget {
  const _LoginCodeDialog({required this.ch});

  final TwoFactorChallenge ch;

  @override
  State<_LoginCodeDialog> createState() => _LoginCodeDialogState();
}

class _LoginCodeDialogState extends State<_LoginCodeDialog> {
  final _code = TextEditingController();
  bool _recovery = false;
  bool _trust = false;
  bool _busy = false;
  String? _error;
  String? _info;

  Future<void> _submit() async {
    if (_code.text.trim().isEmpty || _busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = await context.read<Session>().verifyTwoFactor(widget.ch, _code.text, trust: _trust);
      if (mounted) Navigator.pop(context, res);
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    try {
      await context.read<Session>().resendTwoFactorCode(widget.ch);
      if (mounted) setState(() => _info = tr('Nouveau code envoyé à ${widget.ch.emailHint}.'));
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  @override
  Widget build(BuildContext context) {
    final email = widget.ch.method == 'email';
    final hint = Theme.of(context).hintColor;
    return AlertDialog(
      icon: const Icon(Icons.verified_user_outlined, color: YColors.blue, size: 36),
      title: Text(tr('Code de vérification')),
      content: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 400),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              tr(_recovery
                  ? 'Saisissez l\'un de vos codes de secours (format ABCD-EFGH). Chaque code ne sert qu\'une fois.'
                  : email
                      ? 'Un code à 6 chiffres vient d\'être envoyé à ${widget.ch.emailHint}. Il est valable 10 minutes.'
                      : 'Ouvrez votre application d\'authentification (Google Authenticator, Microsoft Authenticator…) et saisissez le code à 6 chiffres.'),
              style: TextStyle(color: hint, height: 1.4),
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _code,
              autofocus: true,
              textAlign: TextAlign.center,
              keyboardType: _recovery ? TextInputType.text : TextInputType.number,
              textCapitalization: TextCapitalization.characters,
              autofillHints: const [AutofillHints.oneTimeCode],
              inputFormatters: [
                if (!_recovery) FilteringTextInputFormatter.digitsOnly,
                LengthLimitingTextInputFormatter(_recovery ? 9 : 6),
              ],
              style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, letterSpacing: 8),
              decoration: InputDecoration(hintText: _recovery ? 'ABCD-EFGH' : '000000', errorText: _error, errorMaxLines: 3),
              onChanged: (v) {
                if (!_recovery && v.length == 6) _submit();
              },
              onSubmitted: (_) => _submit(),
            ),
            if (_info != null) ...[
              const SizedBox(height: 8),
              Text(_info!, style: const TextStyle(color: YColors.success, fontSize: 13)),
            ],
            const SizedBox(height: 8),
            CheckboxListTile(
              value: _trust,
              onChanged: (v) => setState(() => _trust = v ?? false),
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              title: Text(tr('Ne plus demander sur cet appareil pendant ${widget.ch.trustDays} jours'), style: const TextStyle(fontSize: 14)),
              subtitle: Text(tr('À éviter sur un ordinateur partagé.'), style: TextStyle(fontSize: 12, color: hint)),
            ),
            Wrap(
              spacing: 4,
              children: [
                if (email && !_recovery) TextButton(onPressed: _resend, child: Text(tr('Renvoyer le code'))),
                TextButton(
                  onPressed: () => setState(() {
                    _recovery = !_recovery;
                    _code.clear();
                    _error = null;
                  }),
                  child: Text(tr(_recovery ? 'Utiliser le code habituel' : 'Utiliser un code de secours')),
                ),
              ],
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: _busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Text(tr('Vérifier')),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Activation

/// Assistant d'activation. [forced] : obligatoire avant d'utiliser l'application (super administrateur, société qui l'exige).
class TwoFactorSetupScreen extends StatelessWidget {
  const TwoFactorSetupScreen({super.key, this.forced = false});

  final bool forced;

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('Double authentification')),
        automaticallyImplyLeading: !forced,
        actions: [
          if (forced)
            TextButton.icon(
              onPressed: () => session.logout(remote: true),
              icon: const Icon(Icons.logout, size: 18),
              label: Text(tr('Se déconnecter')),
            ),
        ],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              if (forced)
                Container(
                  margin: const EdgeInsets.only(bottom: 16),
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .08), borderRadius: BorderRadius.circular(12)),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.shield_outlined, color: YColors.blue),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          tr('Votre compte doit être protégé par un second code en plus du mot de passe. '
                              'Cela empêche quelqu\'un qui aurait volé votre mot de passe d\'ouvrir votre compte. Comptez 2 minutes.'),
                          style: const TextStyle(height: 1.4),
                        ),
                      ),
                    ],
                  ),
                ),
              TwoFactorWizard(
                onDone: () async {
                  if (forced) {
                    await session.twoFactorSetupDone();
                  } else if (context.mounted) {
                    Navigator.pop(context, true);
                  }
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Étapes : méthode et mot de passe → code (QR à scanner, ou code reçu par e-mail) → codes de secours.
class TwoFactorWizard extends StatefulWidget {
  const TwoFactorWizard({super.key, required this.onDone});

  final Future<void> Function() onDone;

  @override
  State<TwoFactorWizard> createState() => _TwoFactorWizardState();
}

class _TwoFactorWizardState extends State<TwoFactorWizard> {
  String _method = 'app';
  final _password = TextEditingController();
  final _code = TextEditingController();
  Map<String, dynamic>? _setup;
  List<String>? _codes;
  bool _busy = false;
  bool _noted = false;
  String? _error;

  Session get _session => context.read<Session>();

  Future<void> _run(Future<void> Function() f) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await f();
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _start() => _run(() async {
        final r = asMap(await _session.api.post('auth/2fa/setup', {'method': _method, 'password': _password.text}));
        setState(() => _setup = r);
      });

  Future<void> _confirm() => _run(() async {
        final r = asMap(await _session.api.post('auth/2fa/confirm', {'code': _code.text.trim()}));
        if (_session.bootstrap != null) unawaited(_session.refreshBootstrap());
        setState(() => _codes = List<String>.from(r['recovery_codes'] as List? ?? const []));
      });

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    Widget error() => _error == null
        ? const SizedBox.shrink()
        : Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: const TextStyle(color: YColors.danger)));

    // 3. Codes de secours
    if (_codes != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const _Done(),
          const SizedBox(height: 16),
          RecoveryCodes(codes: _codes!),
          CheckboxListTile(
            value: _noted,
            onChanged: (v) => setState(() => _noted = v ?? false),
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: Text(tr('J\'ai mis ces codes en lieu sûr')),
          ),
          const SizedBox(height: 8),
          FilledButton(onPressed: _noted && !_busy ? () => _run(widget.onDone) : null, child: Text(tr('Terminer'))),
          error(),
        ],
      );
    }

    // 2. Code de confirmation
    if (_setup != null) {
      final app = _setup!['method'] == 'app';
      final secret = '${_setup!['secret'] ?? ''}';
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (app) ...[
            _Step(n: 1, text: 'Installez une application d\'authentification sur votre téléphone : Google Authenticator, Microsoft Authenticator ou Authy (gratuites).'),
            _Step(n: 2, text: 'Dans l\'application, touchez « + » puis « Scanner un code QR », et visez ce code :'),
            Center(
              child: Container(
                margin: const EdgeInsets.symmetric(vertical: 12),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: Colors.black12)),
                child: QrView(data: '${_setup!['uri']}', size: 200),
              ),
            ),
            Text(tr('Impossible de scanner ? Saisissez cette clé dans l\'application :'), style: TextStyle(color: hint, fontSize: 13)),
            const SizedBox(height: 4),
            Row(
              children: [
                Expanded(
                  child: SelectableText(
                    secret.replaceAllMapped(RegExp(r'.{4}'), (m) => '${m[0]} ').trim(),
                    style: const TextStyle(fontFamily: 'monospace', fontWeight: FontWeight.w700),
                  ),
                ),
                IconButton(
                  tooltip: tr('Copier'),
                  onPressed: () {
                    Clipboard.setData(ClipboardData(text: secret));
                    toast(context, tr('Clé copiée.'));
                  },
                  icon: const Icon(Icons.copy, size: 18),
                ),
              ],
            ),
            const SizedBox(height: 8),
            _Step(n: 3, text: 'Saisissez le code à 6 chiffres affiché par l\'application :'),
          ] else
            _Step(n: 1, text: 'Un code à 6 chiffres vient d\'être envoyé à ${_setup!['email_hint']}. Saisissez-le ici (pensez aux indésirables) :'),
          const SizedBox(height: 8),
          TextField(
            controller: _code,
            autofocus: true,
            textAlign: TextAlign.center,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
            style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, letterSpacing: 8),
            decoration: const InputDecoration(hintText: '000000'),
            onChanged: (v) {
              if (v.length == 6) _confirm();
            },
            onSubmitted: (_) => _confirm(),
          ),
          error(),
          const SizedBox(height: 14),
          Row(
            children: [
              TextButton(onPressed: _busy ? null : () => setState(() => _setup = null), child: Text(tr('Retour'))),
              const Spacer(),
              FilledButton(onPressed: _busy ? null : _confirm, child: Text(tr('Activer'))),
            ],
          ),
        ],
      );
    }

    // 1. Méthode + mot de passe
    Widget choice(String value, IconData icon, String title, String sub, {bool recommended = false}) {
      final sel = _method == value;
      return Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () => setState(() => _method = value),
          child: Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: sel ? YColors.blue : Theme.of(context).dividerColor, width: sel ? 2 : 1),
              color: sel ? YColors.blue.withValues(alpha: .05) : null,
            ),
            child: Row(
              children: [
                Icon(icon, color: sel ? YColors.blue : hint, size: 28),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                        Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w700)),
                        if (recommended) StatusChip('Recommandé', color: YColors.success),
                      ]),
                      const SizedBox(height: 2),
                      Text(tr(sub), style: TextStyle(color: hint, fontSize: 13, height: 1.35)),
                    ],
                  ),
                ),
                Icon(sel ? Icons.radio_button_checked : Icons.radio_button_off, color: sel ? YColors.blue : hint),
              ],
            ),
          ),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(tr('Comment recevoir votre code ?'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        const SizedBox(height: 10),
        choice('app', Icons.phone_iphone, 'Application d\'authentification', 'Un code change toutes les 30 secondes sur votre téléphone, même sans connexion.', recommended: true),
        choice('email', Icons.mark_email_read_outlined, 'Code par e-mail', 'Un code vous est envoyé par e-mail à chaque connexion sur un nouvel appareil.'),
        const SizedBox(height: 6),
        Field(
          label: 'Votre mot de passe',
          child: TextField(controller: _password, obscureText: true, autofocus: true, onSubmitted: (_) => _start()),
        ),
        error(),
        const SizedBox(height: 10),
        FilledButton(onPressed: _busy ? null : _start, child: Text(tr('Continuer'))),
      ],
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({required this.n, required this.text});

  final int n;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            CircleAvatar(radius: 12, backgroundColor: YColors.blue, child: Text('$n', style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700))),
            const SizedBox(width: 10),
            Expanded(child: Text(tr(text), style: const TextStyle(height: 1.4))),
          ],
        ),
      );
}

class _Done extends StatelessWidget {
  const _Done();

  @override
  Widget build(BuildContext context) => Row(
        children: [
          const Icon(Icons.verified_user, color: YColors.success, size: 32),
          const SizedBox(width: 10),
          Expanded(child: Text(tr('Double authentification activée.'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
        ],
      );
}

/// Codes de secours : affichés une seule fois, à copier ou noter.
class RecoveryCodes extends StatelessWidget {
  const RecoveryCodes({super.key, required this.codes});

  final List<String> codes;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: YColors.warning.withValues(alpha: .08), borderRadius: BorderRadius.circular(12), border: Border.all(color: YColors.warning.withValues(alpha: .4))),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(tr('Codes de secours'), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 4),
          Text(
            tr('Si vous perdez votre téléphone, chaque code permet une connexion. Gardez-les hors de cet appareil (papier, coffre-fort de mots de passe). Ils ne seront plus affichés.'),
            style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13, height: 1.35),
          ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 18,
            runSpacing: 6,
            children: [for (final c in codes) SelectableText(c, style: const TextStyle(fontFamily: 'monospace', fontSize: 16, fontWeight: FontWeight.w700))],
          ),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              onPressed: () {
                Clipboard.setData(ClipboardData(text: codes.join('\n')));
                toast(context, tr('Codes copiés.'));
              },
              icon: const Icon(Icons.copy, size: 18),
              label: Text(tr('Copier')),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Paramètres → Mon compte

/// Bloc « Sécurité » : état, activation, codes de secours, désactivation ; obligation pour toute la société (administrateur).
class TwoFactorPanel extends StatefulWidget {
  const TwoFactorPanel({super.key});

  @override
  State<TwoFactorPanel> createState() => _TwoFactorPanelState();
}

class _TwoFactorPanelState extends State<TwoFactorPanel> {
  Map<String, dynamic>? _s;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('auth/2fa'));
      if (mounted) setState(() => _s = r);
    } catch (_) {
      if (mounted) setState(() => _s = const {});
    }
  }

  Future<String?> _askPassword(String title) async {
    final c = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (dc) => AlertDialog(
        title: Text(tr(title)),
        content: TextField(controller: c, obscureText: true, autofocus: true, decoration: InputDecoration(labelText: tr('Votre mot de passe')), onSubmitted: (v) => Navigator.pop(dc, v)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(dc, c.text), child: Text(tr('Continuer'))),
        ],
      ),
    );
  }

  Future<void> _regenerate() async {
    final pw = await _askPassword('Nouveaux codes de secours');
    if (pw == null || !mounted) return;
    try {
      final r = asMap(await context.read<Session>().api.post('auth/2fa/recovery-codes', {'password': pw}));
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (dc) => AlertDialog(
          title: Text(tr('Nouveaux codes de secours')),
          content: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 420), child: RecoveryCodes(codes: List<String>.from(r['recovery_codes'] as List))),
          actions: [FilledButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Je les ai notés')))],
        ),
      );
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _disable() async {
    final pw = await _askPassword('Désactiver la double authentification');
    if (pw == null || !mounted) return;
    try {
      await context.read<Session>().api.post('auth/2fa/disable', {'password': pw});
      if (mounted) toast(context, tr('Double authentification désactivée.'));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _requireAll(bool v) async {
    final session = context.read<Session>();
    if (v &&
        !await confirm(context, tr('Exiger pour toute la société ?'),
            tr('Chaque utilisateur devra activer la double authentification à sa prochaine action dans l\'application. Pensez à les prévenir.'),
            ok: tr('Exiger'))) {
      return;
    }
    try {
      await session.api.put('company', {
        'settings': {'require_2fa': v},
      });
      await session.refreshBootstrap();
      if (mounted) setState(() {});
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final s = _s;
    final hint = Theme.of(context).hintColor;
    if (s == null) return const Padding(padding: EdgeInsets.all(12), child: LinearProgressIndicator());
    final on = s['enabled'] == true;
    final required = s['required'] == true;
    final left = (s['recovery_left'] as num?)?.toInt() ?? 0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(on ? Icons.verified_user : Icons.shield_outlined, color: on ? YColors.success : YColors.warning),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(tr('Double authentification'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  Text(
                    tr(on
                        ? (s['method'] == 'app' ? 'Activée — application d\'authentification · $left code(s) de secours restant(s)' : 'Activée — code par e-mail (${s['email_hint']}) · $left code(s) de secours restant(s)')
                        : 'Désactivée : votre mot de passe suffit pour ouvrir votre compte.'),
                    style: TextStyle(color: hint, fontSize: 13),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (!on)
              FilledButton.icon(
                onPressed: () async {
                  await Navigator.push(context, MaterialPageRoute<bool>(builder: (_) => const TwoFactorSetupScreen()));
                  _load();
                },
                icon: const Icon(Icons.lock_outline, size: 18),
                label: Text(tr('Activer')),
              ),
            if (on) OutlinedButton.icon(onPressed: _regenerate, icon: const Icon(Icons.password, size: 18), label: Text(tr('Nouveaux codes de secours'))),
            if (on && !required)
              TextButton.icon(
                style: TextButton.styleFrom(foregroundColor: YColors.danger),
                onPressed: _disable,
                icon: const Icon(Icons.lock_open, size: 18),
                label: Text(tr('Désactiver')),
              ),
          ],
        ),
        if (on && required)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(tr(session.isSuperAdmin ? 'Obligatoire pour le super administrateur.' : 'Obligatoire dans votre société.'), style: TextStyle(color: hint, fontSize: 12)),
          ),
        if (session.can('core.settings.manage')) ...[
          const Divider(height: 24),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: session.companySettings['require_2fa'] == true,
            onChanged: _requireAll,
            title: Text(tr('Exiger la double authentification pour tous les utilisateurs')),
            subtitle: Text(tr('Recommandé si vos équipes accèdent à la comptabilité, à la paie ou aux comptes bancaires.'), style: TextStyle(fontSize: 12, color: hint)),
          ),
        ],
      ],
    );
  }
}
