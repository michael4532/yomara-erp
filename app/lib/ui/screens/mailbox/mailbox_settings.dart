import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/mail_html.dart';
import 'compose.dart' show uploadMailImage, insertAtCursor;
import 'mail_store.dart';
import 'mail_theme.dart';
import 'mailbox_screen.dart' show AccountAvatar;
import '../../../i18n/i18n.dart';
import '../../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ---------------------------------------------------------------------------- ajout d'un compte

Future<void> addAccount(BuildContext context, MailStore store) => showFormDialog(context, title: 'Ajouter un compte de messagerie', builder: (_) => _AddAccount(store: store));

class _AddAccount extends StatefulWidget {
  const _AddAccount({required this.store});

  final MailStore store;

  @override
  State<_AddAccount> createState() => _AddAccountState();
}

class _AddAccountState extends State<_AddAccount> {
  final _email = TextEditingController();
  final _name = TextEditingController();
  final _password = TextEditingController();
  final _user = TextEditingController();
  final _smtpUser = TextEditingController();
  final _smtpPassword = TextEditingController();
  final _local = TextEditingController();
  bool _ownSmtpLogin = false;
  final Map<String, TextEditingController> _f = {for (final k in ['imap_host', 'imap_port', 'smtp_host', 'smtp_port', 'pop3_host', 'pop3_port']) k: TextEditingController()};
  final Map<String, String> _sec = {'imap_security': 'ssl', 'smtp_security': 'ssl', 'pop3_security': 'ssl'};
  int _step = 0; // 0 adresse, 1 réglages, 2 attente OAuth
  String _provider = 'imap';
  String? _hint;
  bool _busy = false, _shared = false, _calendar = true, _keep = true;
  final Set<int> _sharedWith = {};
  Timer? _poll;
  int _before = 0;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _detect() async {
    final email = _email.text.trim();
    if (!email.contains('@')) return toast(context, tr('Saisissez une adresse e-mail valide.'), error: true);
    setState(() => _busy = true);
    try {
      final r = asMap(await context.read<Session>().api.post('mailbox/accounts/detect', {'email': email}));
      _hint = r['hint'] as String?;
      if (r['oauth'] == true) {
        _provider = '${r['provider']}';
      } else {
        _provider = 'imap';
        final st = asMap(r['settings']);
        for (final k in _f.keys) {
          _f[k]!.text = '${st[k] ?? ''}';
        }
        for (final k in _sec.keys) {
          if (st[k] != null) _sec[k] = '${st[k]}';
        }
        _user.text = email;
      }
      if (mounted) setState(() => _step = 1);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _oauth(String provider) async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('mailbox/oauth/$provider', {'calendar': _calendar, 'email': _email.text.trim()});
      _before = widget.store.accounts.length;
      await safeLaunchUrl(Uri.parse('${r['url']}'), mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank');
      if (mounted) setState(() => _step = 2);
      _poll = Timer.periodic(const Duration(seconds: 3), (_) async {
        await widget.store.reloadIndex();
        if (widget.store.accounts.length > _before && mounted) {
          _poll?.cancel();
          final host = Navigator.of(context).context;
          Navigator.pop(context);
          final acc = widget.store.accounts.last;
          unawaited(widget.store.refresh());
          if (widget.store.mobileUrl.isNotEmpty && host.mounted) unawaited(showMobileAppQr(host, widget.store.mobileUrl, email: '${acc['email']}'));
        }
      });
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _save() async {
    if (_password.text.isEmpty) return toast(context, tr('Saisissez le mot de passe de la boîte.'), error: true);
    setState(() => _busy = true);
    final pop = _provider == 'pop3';
    final settings = <String, dynamic>{
      'username': _user.text.trim().isEmpty ? _email.text.trim() : _user.text.trim(),
      'smtp_host': _f['smtp_host']!.text.trim(), 'smtp_port': int.tryParse(_f['smtp_port']!.text) ?? 465, 'smtp_security': _sec['smtp_security'],
      if (_ownSmtpLogin && _smtpUser.text.trim().isNotEmpty) 'smtp_username': _smtpUser.text.trim(),
      if (!pop) ...{'imap_host': _f['imap_host']!.text.trim(), 'imap_port': int.tryParse(_f['imap_port']!.text) ?? 993, 'imap_security': _sec['imap_security']},
      if (pop) ...{'pop3_host': _f['pop3_host']!.text.trim(), 'pop3_port': int.tryParse(_f['pop3_port']!.text) ?? 995, 'pop3_security': _sec['pop3_security'], 'pop_keep': _keep},
    };
    try {
      await context.read<Session>().api.postLong('mailbox/accounts', {
        'provider': _provider, 'email': _email.text.trim(), 'display_name': _name.text.trim().isEmpty ? null : _name.text.trim(), 'password': _password.text, 'settings': settings,
        if (_ownSmtpLogin && _smtpPassword.text.isNotEmpty) 'smtp_password': _smtpPassword.text,
        'shared': _shared, if (_shared) 'shared_with': _sharedWith.toList(),
      });
      if (!mounted) return;
      final host = Navigator.of(context).context;
      Navigator.pop(context);
      unawaited(widget.store.refresh());
      if (!host.mounted) return;
      if (widget.store.mobileUrl.isNotEmpty) {
        unawaited(showMobileAppQr(host, widget.store.mobileUrl, email: _email.text.trim()));
      } else {
        toast(host, tr('Compte ajouté : vos messages arrivent.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _createHosted() async {
    if (_local.text.trim().length < 2) return toast(context, tr('Choisissez le début de l\'adresse (ex. contact, compta, awa.traore).'), error: true);
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.postLong('mailbox/accounts/hosted', {
        'local': _local.text.trim(), 'display_name': _name.text.trim().isEmpty ? null : _name.text.trim(), 'shared': _shared, if (_shared) 'shared_with': _sharedWith.toList(),
      });
      if (!mounted) return;
      final host = Navigator.of(context).context;
      Navigator.pop(context);
      unawaited(widget.store.refresh());
      if (!host.mounted) return;
      if (widget.store.mobileUrl.isNotEmpty) {
        unawaited(showMobileAppQr(host, widget.store.mobileUrl, email: '${r['data']['email']}'));
      } else {
        toast(host, tr('Adresse ${r['data']['email']} créée : elle est prête à envoyer et recevoir.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String get _hostedPreview {
    final h = widget.store.hosted;
    final l = _local.text.trim().toLowerCase().replaceAll(RegExp(r'[^a-z0-9._-]'), '');
    return '${l.isEmpty ? 'contact' : l}${h['suffix'] ?? ''}@${h['domain'] ?? ''}';
  }

  Widget _server(String proto, String label) => Row(children: [
        Expanded(flex: 5, child: TextField(controller: _f['${proto}_host'], decoration: InputDecoration(labelText: tr('Serveur $label')))),
        const SizedBox(width: 8),
        SizedBox(width: 80, child: TextField(controller: _f['${proto}_port'], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Port')))),
        const SizedBox(width: 8),
        SizedBox(
          width: 130,
          child: DropdownButtonFormField<String>(
            initialValue: _sec['${proto}_security'],
            decoration: InputDecoration(labelText: tr('Sécurité')),
            items: [DropdownMenuItem(value: 'ssl', child: Text(tr('SSL/TLS'))), DropdownMenuItem(value: 'starttls', child: Text(tr('STARTTLS'))), DropdownMenuItem(value: 'none', child: Text(tr('Aucune')))],
            onChanged: (v) => setState(() => _sec['${proto}_security'] = v ?? 'ssl'),
          ),
        ),
      ]);

  @override
  Widget build(BuildContext context) {
    final s = widget.store;
    final oauth = _provider == 'microsoft' || _provider == 'google';
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (_step == 0) ...[
            Text(tr('Votre boîte reste chez votre fournisseur ; Yomara la synchronise et en garde une copie sur chaque poste pour travailler hors ligne.')),
            const SizedBox(height: 14),
            TextField(controller: _email, autofocus: true, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: tr('Adresse e-mail'), prefixIcon: Icon(Icons.alternate_email)), onSubmitted: (_) => _detect()),
            const SizedBox(height: 16),
            Text(tr('Ou connectez directement :'), style: TextStyle(color: Theme.of(context).hintColor)),
            const SizedBox(height: 8),
            Wrap(spacing: 10, runSpacing: 10, children: [
              if (s.oauthDisabled['microsoft'] != true)
                _ProviderButton(color: const Color(0xFF0F6CBD), icon: Icons.window, label: tr('Microsoft 365 / Outlook'), onTap: () => setState(() => (_provider = 'microsoft', _step = 1))),
              if (s.oauthDisabled['google'] != true)
                _ProviderButton(color: const Color(0xFFEA4335), icon: Icons.mail, label: tr('Gmail / Google Workspace'), onTap: () => setState(() => (_provider = 'google', _step = 1))),
            ]),
            if (s.hosted['configured'] == true) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: YColors.night.withValues(alpha: .04), borderRadius: BorderRadius.circular(12), border: Border.all(color: YColors.line)),
                child: Row(children: [
                  const Icon(Icons.dns_outlined, color: YColors.night),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('Pas de serveur de messagerie ?'), style: TextStyle(fontWeight: FontWeight.w700)),
                      Text(tr(s.hosted['module'] == true
                          ? 'Créez une adresse fournie par Yomara (${s.hosted['used']}/${s.hosted['max']} utilisées, ${((s.hosted['quota_mb'] as num? ?? 0) / 1024).toStringAsFixed(0)} Go chacune).'
                          : 'Les adresses fournies par Yomara font partie du module « Messagerie hébergée » (Boutique).'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
                    ]),
                  ),
                  if (s.hosted['module'] == true) TextButton(onPressed: () => setState(() => (_provider = 'hosted', _step = 1)), child: Text(tr('Créer une adresse'))),
                ]),
              ),
            ],
          ],
          if (_step == 1 && oauth) ...[
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: CircleAvatar(backgroundColor: (_provider == 'microsoft' ? const Color(0xFF0F6CBD) : const Color(0xFFEA4335)).withValues(alpha: .12),
                  child: Icon(_provider == 'microsoft' ? Icons.window : Icons.mail, color: _provider == 'microsoft' ? const Color(0xFF0F6CBD) : const Color(0xFFEA4335))),
              title: Text(tr(_provider == 'microsoft' ? 'Microsoft 365 / Outlook.com' : 'Gmail / Google Workspace'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr(_hint ?? 'Connexion sécurisée chez ${_provider == 'microsoft' ? 'Microsoft' : 'Google'} : Yomara ne voit jamais votre mot de passe.')),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: _calendar,
              onChanged: (v) => setState(() => _calendar = v),
              title: Text(tr('Synchroniser aussi le calendrier avec l\'Agenda')),
              subtitle: Text(tr(_provider == 'microsoft' ? 'Dans les deux sens ; réunions Teams créées automatiquement.' : 'Dans les deux sens ; Google Meet créé automatiquement.')),
            ),
            if (s.oauth[_provider] != true)
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: YColors.warning.withValues(alpha: .1), borderRadius: BorderRadius.circular(10)),
                child: Text(tr(s.canManage
                    ? 'La connexion ${_provider == 'microsoft' ? 'Microsoft 365' : 'Google'} n\'est pas encore paramétrée : renseignez l\'application dans Boîte mail › Paramètres (guide pas à pas fourni).'
                    : 'La connexion ${_provider == 'microsoft' ? 'Microsoft 365' : 'Google'} n\'est pas encore paramétrée : demandez à votre administrateur.')),
              ),
          ],
          if (_step == 1 && _provider == 'hosted') ...[
            Text(tr('Votre adresse est créée sur le serveur de messagerie de Yomara : stockage sécurisé, aucune configuration, utilisable aussi sur votre téléphone.')),
            const SizedBox(height: 12),
            TextField(controller: _local, autofocus: true, decoration: InputDecoration(labelText: tr('Début de l\'adresse'), hintText: tr('contact, compta, awa.traore')), onChanged: (_) => setState(() {})),
            Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr(_hostedPreview), style: const TextStyle(fontWeight: FontWeight.w700, color: YColors.blue))),
            TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom affiché (expéditeur)'))),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .06), borderRadius: BorderRadius.circular(10)),
              child: Text(tr('Protection anti-spam : nombre d\'envois limité par heure et par jour (plus bas pendant les 7 premiers jours), 30 destinataires maximum par message. '
                  'Pour les envois en nombre, utilisez une campagne du module Marketing.'), style: TextStyle(fontSize: 12.5)),
            ),
            if (s.canManage) ...[
              SwitchListTile(contentPadding: EdgeInsets.zero, value: _shared, onChanged: (v) => setState(() => _shared = v), title: Text(tr('Boîte partagée (contact@, compta@…)'))),
              if (_shared)
                Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final u in s.users)
                    FilterChip(label: Text(tr('${u['name']}')), selected: _sharedWith.contains(u['id']), onSelected: (v) => setState(() => v ? _sharedWith.add(u['id'] as int) : _sharedWith.remove(u['id']))),
                ]),
            ],
          ],
          if (_step == 1 && !oauth && _provider != 'hosted') ...[
            SegmentedButton<String>(
              segments: [ButtonSegment(value: 'imap', label: Text(tr('IMAP (conseillé)'))), ButtonSegment(value: 'pop3', label: Text(tr('POP3')))],
              selected: {_provider},
              onSelectionChanged: (v) => setState(() => _provider = v.first),
            ),
            if (_hint != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr(_hint!), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
            const SizedBox(height: 8),
            TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom affiché (expéditeur)'), hintText: tr('Société — Service commercial'))),
            TextField(controller: _user, decoration: InputDecoration(labelText: tr('Identifiant (souvent l\'adresse complète)'))),
            TextField(controller: _password, obscureText: true, decoration: InputDecoration(labelText: tr('Mot de passe de la boîte'))),
            const SizedBox(height: 10),
            if (_provider == 'imap') _server('imap', 'IMAP (réception)') else _server('pop3', 'POP3 (réception)'),
            const SizedBox(height: 6),
            _server('smtp', 'SMTP (envoi)'),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              value: _ownSmtpLogin,
              onChanged: (v) => setState(() => _ownSmtpLogin = v == true),
              title: Text(tr('Le serveur d\'envoi utilise un autre identifiant')),
              subtitle: Text(tr('Relais SMTP de votre hébergeur, SendGrid, Brevo, Mailjet…')),
            ),
            if (_ownSmtpLogin) ...[
              TextField(controller: _smtpUser, decoration: InputDecoration(labelText: tr('Identifiant SMTP'))),
              TextField(controller: _smtpPassword, obscureText: true, decoration: InputDecoration(labelText: tr('Mot de passe SMTP (ou clé API)'))),
            ],
            if (_provider == 'pop3')
              SwitchListTile(contentPadding: EdgeInsets.zero, value: _keep, onChanged: (v) => setState(() => _keep = v), title: Text(tr('Laisser une copie des messages sur le serveur'))),
            if (s.canManage) ...[
              SwitchListTile(contentPadding: EdgeInsets.zero, value: _shared, onChanged: (v) => setState(() => _shared = v), title: Text(tr('Boîte partagée (contact@, compta@…)')),
                  subtitle: Text(tr('Visible par les collègues choisis'))),
              if (_shared)
                Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final u in s.users)
                    FilterChip(label: Text(tr('${u['name']}')), selected: _sharedWith.contains(u['id']), onSelected: (v) => setState(() => v ? _sharedWith.add(u['id'] as int) : _sharedWith.remove(u['id']))),
                ]),
            ],
          ],
          if (_step == 2)
            Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Column(children: [
                CircularProgressIndicator(),
                SizedBox(height: 16),
                Text(tr('Terminez la connexion dans la page qui vient de s\'ouvrir…'), textAlign: TextAlign.center),
                SizedBox(height: 6),
                Text(tr('Cette fenêtre se fermera toute seule.'), style: TextStyle(fontSize: 12)),
              ]),
            ),
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
            if (_step == 1) TextButton(onPressed: () => setState(() => _step = 0), child: Text(tr('Retour'))),
            if (_step == 0) FilledButton(onPressed: _busy ? null : _detect, child: Text(tr('Continuer'))),
            if (_step == 1 && oauth)
              FilledButton.icon(onPressed: _busy || s.oauth[_provider] != true ? null : () => _oauth(_provider), icon: const Icon(Icons.login), label: Text(tr('Se connecter avec ${_provider == 'microsoft' ? 'Microsoft' : 'Google'}'))),
            if (_step == 1 && _provider == 'hosted') FilledButton(onPressed: _busy ? null : _createHosted, child: Text(tr(_busy ? 'Création…' : 'Créer l\'adresse'))),
            if (_step == 1 && !oauth && _provider != 'hosted') FilledButton(onPressed: _busy ? null : _save, child: Text(tr(_busy ? 'Vérification…' : 'Tester et ajouter'))),
          ]),
        ),
      ]),
    );
  }
}

class _ProviderButton extends StatelessWidget {
  const _ProviderButton({required this.color, required this.icon, required this.label, required this.onTap});

  final Color color;
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
        onPressed: onTap,
        style: OutlinedButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14), side: BorderSide(color: color.withValues(alpha: .4))),
        icon: Icon(icon, color: color),
        label: Text(tr(label)),
      );
}

// ---------------------------------------------------------------------------- réglages d'un compte

Future<void> editAccount(BuildContext context, MailStore store, Map<String, dynamic> a) => showFormDialog(context, title: '${a['email']}', builder: (_) => _EditAccount(store: store, account: a));

class _EditAccount extends StatefulWidget {
  const _EditAccount({required this.store, required this.account});

  final MailStore store;
  final Map<String, dynamic> account;

  @override
  State<_EditAccount> createState() => _EditAccountState();
}

class _EditAccountState extends State<_EditAccount> {
  late final _name = TextEditingController(text: '${widget.account['display_name'] ?? ''}');
  final _password = TextEditingController();
  late int _days = (widget.account['sync_days'] as num?)?.toInt() ?? 30;
  late bool _calendar = widget.account['calendar_sync'] == true;
  late final Set<int> _sharedWith = {for (final x in (widget.account['shared_with'] as List? ?? const [])) (x as num).toInt()};
  late String _color = '${widget.account['color'] ?? '#2E6BE6'}';
  late String? _avatar = widget.account['avatar_url'] as String?;
  bool _busy = false, _publishPhoto = true;

  Future<void> _pickAvatar() async {
    final files = await FilePicker.pickFiles(type: FileType.image);
    if (files.isEmpty || !mounted) return;
    setState(() => _busy = true);
    try {
      final f = files.first;
      final r = await context.read<Session>().api.uploadForm('mailbox/accounts/${widget.account['id']}/avatar', {'publish': _publishPhoto ? '1' : '0'}, 'file', await f.readAsBytes(), f.name);
      if (mounted) setState(() => _avatar = '${r['data']['avatar_url']}');
      if (mounted) toast(context, r['warning'] != null ? '${r['warning']}' : (r['published'] == true ? 'Photo enregistrée et publiée sur Microsoft 365.' : 'Photo enregistrée.'), error: r['warning'] != null);
      unawaited(widget.store.reloadIndex());
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _removeAvatar() async {
    try {
      await context.read<Session>().api.delete('mailbox/accounts/${widget.account['id']}/avatar');
      if (mounted) setState(() => _avatar = null);
      unawaited(widget.store.reloadIndex());
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  static const _colors = ['#2E6BE6', '#0F6CBD', '#5B5FC7', '#12A150', '#E8A317', '#EA4335', '#D81B60', '#00897B'];

  Future<void> _save() async {
    setState(() => _busy = true);
    final a = widget.account;
    try {
      await context.read<Session>().api.put('mailbox/accounts/${a['id']}', {
        'display_name': _name.text.trim(), 'sync_days': _days, 'calendar_sync': _calendar, 'color': _color,
        if (_password.text.isNotEmpty) 'password': _password.text,
        if (a['shared'] == true) 'shared_with': _sharedWith.toList(),
      });
      if (!mounted) return;
      Navigator.pop(context);
      unawaited(widget.store.reloadIndex());
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.account;
    final api = context.read<Session>().api;
    final oauth = a['provider'] == 'microsoft' || a['provider'] == 'google';
    final folders = widget.store.foldersOf(a['id'] as int);
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            AccountAvatar(account: {...a, 'avatar_url': _avatar}, radius: 30),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${a['provider_label']}${a['shared'] == true ? ' · boîte partagée' : ''}'), style: TextStyle(color: Theme.of(context).hintColor)),
                Wrap(spacing: 4, children: [
                  TextButton.icon(onPressed: _busy ? null : _pickAvatar, icon: const Icon(Icons.photo_camera_outlined, size: 18), label: Text(tr('Photo de profil'))),
                  if (_avatar != null) TextButton(onPressed: _busy ? null : _removeAvatar, child: Text(tr('Retirer'))),
                ]),
                if (a['provider'] == 'microsoft' && a['settings']?['graph_user'] == null)
                  CheckboxListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    value: _publishPhoto,
                    onChanged: (v) => setState(() => _publishPhoto = v == true),
                    title: Text(tr('Publier aussi la photo sur Microsoft 365 (Outlook, Teams)')),
                  ),
              ]),
            ),
          ]),
          if (a['error'] != null)
            Container(
              margin: const EdgeInsets.only(top: 10),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: YColors.danger.withValues(alpha: .08), borderRadius: BorderRadius.circular(10)),
              child: Text(tr('${a['error']}'), style: const TextStyle(color: YColors.danger)),
            ),
          if (a['send_blocked_until'] != null)
            Container(
              margin: const EdgeInsets.only(top: 10),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: YColors.warning.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
              child: Text(tr('Envois suspendus jusqu\'à ${dateTimeFr(a['send_blocked_until'])} : le serveur a refusé plusieurs messages de suite. Vérifiez les adresses des destinataires.')),
            ),
          if (a['limits'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(tr('Protection anti-spam : ${a['limits']['per_hour']} envois / heure, ${a['limits']['per_day']} / jour, ${a['limits']['recipients']} destinataires par message.'),
                  style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
            ),
          const SizedBox(height: 8),
          TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom affiché (vu par vos destinataires)'), hintText: tr('Prénom Nom — Société'))),
          if (!oauth && a['hosted'] != true) TextField(controller: _password, obscureText: true, decoration: InputDecoration(labelText: tr('Nouveau mot de passe (laisser vide pour garder l\'actuel)'))),
          const SizedBox(height: 10),
          DropdownButtonFormField<int>(
            initialValue: [7, 14, 30, 60, 90, 180, 365].contains(_days) ? _days : 30,
            decoration: InputDecoration(labelText: tr('Messages gardés sur les postes')),
            items: [
              DropdownMenuItem(value: 7, child: Text(tr('7 derniers jours'))), DropdownMenuItem(value: 14, child: Text(tr('14 derniers jours'))),
              DropdownMenuItem(value: 30, child: Text(tr('30 derniers jours'))), DropdownMenuItem(value: 60, child: Text(tr('2 derniers mois'))),
              DropdownMenuItem(value: 90, child: Text(tr('3 derniers mois'))), DropdownMenuItem(value: 180, child: Text(tr('6 derniers mois'))), DropdownMenuItem(value: 365, child: Text(tr('12 derniers mois'))),
            ],
            onChanged: (v) => setState(() => _days = v ?? 30),
          ),
          if (oauth)
            SwitchListTile(contentPadding: EdgeInsets.zero, value: _calendar, onChanged: (v) => setState(() => _calendar = v), title: Text(tr('Calendrier synchronisé avec l\'Agenda'))),
          const SizedBox(height: 6),
          Wrap(spacing: 8, children: [
            for (final c in _colors)
              InkWell(
                onTap: () => setState(() => _color = c),
                child: CircleAvatar(radius: 13, backgroundColor: Color(int.parse('FF${c.substring(1)}', radix: 16)), child: _color == c ? const Icon(Icons.check, size: 15, color: Colors.white) : null),
              ),
          ]),
          if (a['shared'] == true && widget.store.canManage) ...[
            const SizedBox(height: 12),
            Text(tr('Collègues qui ont accès'), style: TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final u in widget.store.users)
                FilterChip(label: Text(tr('${u['name']}')), selected: _sharedWith.contains(u['id']), onSelected: (v) => setState(() => v ? _sharedWith.add(u['id'] as int) : _sharedWith.remove(u['id']))),
            ]),
          ],
          if (folders.isNotEmpty) ...[
            const SizedBox(height: 12),
            Text(tr('Dossiers synchronisés'), style: TextStyle(fontWeight: FontWeight.w700)),
            for (final f in folders)
              CheckboxListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                value: f['sync'] != false,
                title: Text(tr('${f['name']}')),
                subtitle: f['error'] != null ? Text(tr('${f['error']}'), style: const TextStyle(color: YColors.danger)) : null,
                onChanged: (v) async {
                  await api.put('mailbox/folders/${f['id']}', {'sync': v == true});
                  f['sync'] = v == true;
                  if (mounted) setState(() {});
                },
              ),
          ],
          const Divider(height: 24),
          Wrap(spacing: 8, runSpacing: 8, children: [
            OutlinedButton.icon(
              onPressed: () => showDialog(context: context, builder: (_) => _Deliverability(accountId: a['id'] as int)),
              icon: const Icon(Icons.verified_outlined),
              label: Text(tr('Tester la délivrabilité')),
            ),
            if (a['hosted'] == true)
              OutlinedButton.icon(
                onPressed: () async {
                  if (!await confirm(context, tr('Configurer sur un téléphone ?'), tr('Un nouveau mot de passe est créé pour cette adresse (Yomara continue de fonctionner normalement).'), ok: tr('Continuer'))) return;
                  try {
                    final r = await api.post('mailbox/accounts/${a['id']}/hosted-password');
                    if (!context.mounted) return;
                    await showDialog(
                      context: context,
                      builder: (c) => YAlertDialog(
                        title: Text(tr('Réglages pour Outlook, Gmail ou l\'application Mail')),
                        content: SelectableText(tr('Adresse : ${r['email']}\nMot de passe : ${r['password']}\n\nRéception (IMAP) : ${r['imap']} — port 993, SSL\nEnvoi (SMTP) : ${r['smtp']} — port 465, SSL'
                            '${r['usage'] != null ? '\n\nEspace utilisé : ${r['usage']['used_mb']} Mo sur ${r['usage']['quota_mb']} Mo' : ''}')),
                        actions: [FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
                      ),
                    );
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.smartphone),
                label: Text(tr('Configurer sur un téléphone')),
              ),
          ]),
          if (a['provider'] == 'microsoft' || a['provider'] == 'google') ...[
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (a['provider'] == 'microsoft' && a['settings']?['graph_user'] == null)
                OutlinedButton.icon(
                  onPressed: () async {
                    final email = await promptText(context, 'Boîte partagée Microsoft 365', 'Adresse de la boîte partagée (ex. contact@votre-societe.com)');
                    if (email == null || email.isEmpty) return;
                    try {
                      await api.post('mailbox/accounts/${a['id']}/shared', {'email': email});
                      await widget.store.refresh();
                      if (context.mounted) toast(context, tr('Boîte partagée ajoutée.'));
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                  icon: const Icon(Icons.group_add_outlined),
                  label: Text(tr('Ajouter une boîte partagée')),
                ),
              OutlinedButton.icon(
                onPressed: () async {
                  try {
                    final r = await api.postLong('mailbox/accounts/${a['id']}/contacts');
                    if (context.mounted) toast(context, tr('${r['created']} contact(s) ajouté(s) aux clients, ${r['skipped']} déjà connu(s) ou sans adresse.'));
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.contacts_outlined),
                label: Text(tr('Importer les contacts dans les clients')),
              ),
            ]),
          ],
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(
              onPressed: () async {
                if (!await confirm(context, a['hosted'] == true ? 'Supprimer cette adresse Yomara ?' : 'Retirer ce compte ?',
                    a['hosted'] == true ? 'L\'adresse et tous ses messages seront définitivement supprimés du serveur.' : 'Les messages restent chez votre fournisseur ; seule la copie de Yomara est supprimée.',
                    danger: true, ok: a['hosted'] == true ? 'Supprimer définitivement' : 'Retirer')) {
                  return;
                }
                try {
                  await api.delete('mailbox/accounts/${a['id']}');
                  if (context.mounted) Navigator.pop(context);
                  await widget.store.refresh();
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }
              },
              child: Text(tr('Retirer le compte'), style: TextStyle(color: YColors.danger)),
            ),
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
            FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
          ]),
        ),
      ]),
    );
  }
}

Future<String?> promptText(BuildContext context, String title, String label) {
  final c = TextEditingController();
  return showDialog<String>(
    context: context,
    builder: (ctx) => YAlertDialog(
      title: Text(tr(title)),
      content: SizedBox(width: 420, child: TextField(controller: c, autofocus: true, decoration: InputDecoration(labelText: trn(label)), onSubmitted: (v) => Navigator.pop(ctx, v.trim()))),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('Valider')))],
    ),
  );
}

class _Deliverability extends StatelessWidget {
  const _Deliverability({required this.accountId});

  final int accountId;

  @override
  Widget build(BuildContext context) => YAlertDialog(
        title: Text(tr('Délivrabilité du domaine')),
        content: SizedBox(
          width: 560,
          child: FutureBuilder(
            future: context.read<Session>().api.get('mailbox/accounts/$accountId/deliverability'),
            builder: (context, snap) {
              if (snap.hasError) return Text(tr(errorText(snap.error!)));
              if (!snap.hasData) return const SizedBox(height: 120, child: Center(child: CircularProgressIndicator()));
              final d = asMap((snap.data as Map)['data']);
              return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${d['domain']} · ${d['score']}/${d['max']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                Text(tr('${d['verdict']}'), style: TextStyle(color: Theme.of(context).hintColor)),
                const SizedBox(height: 10),
                for (final c in (d['checks'] as List))
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(c['ok'] == true ? Icons.check_circle : Icons.warning_amber_rounded, color: c['ok'] == true ? YColors.success : YColors.warning),
                    title: Text(tr('${c['label']}')),
                    subtitle: SelectableText(tr('${c['detail']}'), style: const TextStyle(fontSize: 12)),
                  ),
              ]);
            },
          ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
      );
}

// ---------------------------------------------------------------------------- signatures

Future<void> manageSignatures(BuildContext context, MailStore store) => showDialog(context: context, builder: (_) => _Signatures(store: store));

/// Nouvelle signature depuis la fenêtre de rédaction : renvoie son identifiant une fois enregistrée.
Future<int?> editSignature(BuildContext context, MailStore store, {int? accountId}) async {
  final id = await showFormDialog<int?>(context, title: 'Nouvelle signature', maxWidth: 808, builder: (_) => _SignatureEditor(store: store, accountId: accountId));
  await store.reloadIndex();
  return id;
}

class _Signatures extends StatefulWidget {
  const _Signatures({required this.store});

  final MailStore store;

  @override
  State<_Signatures> createState() => _SignaturesState();
}

class _SignaturesState extends State<_Signatures> {
  @override
  Widget build(BuildContext context) {
    final s = widget.store;
    return YAlertDialog(
      title: Text(tr('Signatures et modèles')),
      content: SizedBox(
        width: 640,
        height: 520,
        child: DefaultTabController(
          length: 2,
          child: Column(children: [
            TabBar(tabs: [Tab(text: tr('Signatures')), Tab(text: tr('Modèles de réponse'))]),
            Expanded(
              child: TabBarView(children: [
                ListView(padding: const EdgeInsets.only(top: 10), children: [
                  if (s.accounts.isNotEmpty && s.signatures.isNotEmpty) ...[
                    Text(tr('Signature de chaque boîte'), style: const TextStyle(fontWeight: FontWeight.w800)),
                    Text(tr('Comme dans Outlook : la signature est insérée d\'office selon la boîte d\'envoi.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
                    const SizedBox(height: 8),
                    for (final a in s.accounts) _AccountSignatures(store: s, account: a, onChanged: () => setState(() {})),
                    const Divider(height: 28),
                    Text(tr('Mes signatures'), style: const TextStyle(fontWeight: FontWeight.w800)),
                    const SizedBox(height: 6),
                  ],
                  for (final g in s.signatures)
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(10),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [
                            Expanded(child: Text(tr('${g['name']}${g['user_id'] == null ? ' · société' : ''}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                            if (g['is_default'] == true) StatusChip(tr('Par défaut'), color: YColors.success),
                            IconButton(icon: const Icon(Icons.edit_outlined), onPressed: () => _edit(g)),
                            IconButton(
                              icon: const Icon(Icons.delete_outline),
                              onPressed: () async {
                                await context.read<Session>().api.delete('mailbox/signatures/${g['id']}');
                                await s.reloadIndex();
                                if (mounted) setState(() {});
                              },
                            ),
                          ]),
                          SizedBox(height: 120, child: MailHtml(html: '${g['html']}')),
                        ]),
                      ),
                    ),
                  const SizedBox(height: 8),
                  Align(alignment: Alignment.centerLeft, child: FilledButton.icon(onPressed: () => _edit(null), icon: const Icon(Icons.add), label: Text(tr('Nouvelle signature')))),
                ]),
                ListView(padding: const EdgeInsets.only(top: 10), children: [
                  for (final t in s.templates)
                    ListTile(
                      leading: const Icon(Icons.text_snippet_outlined),
                      title: Text(tr('${t['name']}')),
                      subtitle: Text(tr('${t['subject'] ?? ''}')),
                      trailing: IconButton(
                        icon: const Icon(Icons.delete_outline),
                        onPressed: () async {
                          await context.read<Session>().api.delete('mailbox/templates/${t['id']}');
                          await s.reloadIndex();
                          if (mounted) setState(() {});
                        },
                      ),
                      onTap: () => _template(t),
                    ),
                  const SizedBox(height: 8),
                  Align(alignment: Alignment.centerLeft, child: FilledButton.icon(onPressed: () => _template(null), icon: const Icon(Icons.add), label: Text(tr('Nouveau modèle')))),
                ]),
              ]),
            ),
          ]),
        ),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }

  Future<void> _edit(Map<String, dynamic>? g) async {
    await showFormDialog(context, title: 'Modifier la signature', maxWidth: 808, builder: (_) => _SignatureEditor(store: widget.store, signature: g));
    await widget.store.reloadIndex();
    if (mounted) setState(() {});
  }

  Future<void> _template(Map<String, dynamic>? t) async {
    final name = TextEditingController(text: '${t?['name'] ?? ''}');
    final subject = TextEditingController(text: '${t?['subject'] ?? ''}');
    final body = TextEditingController(text: '${t?['html'] ?? ''}'.replaceAll(RegExp(r'<br\s*/?>'), '\n').replaceAll(RegExp(r'</p>'), '\n').replaceAll(RegExp(r'<[^>]+>'), '').trim());
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr(t == null ? 'Nouveau modèle' : 'Modifier le modèle')),
        content: SizedBox(
          width: 520,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom du modèle'))),
            TextField(controller: subject, decoration: InputDecoration(labelText: tr('Objet (facultatif)'))),
            const SizedBox(height: 8),
            TextField(controller: body, minLines: 6, maxLines: 12, decoration: InputDecoration(labelText: tr('Texte'), alignLabelWithHint: true)),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok != true || name.text.trim().isEmpty || !mounted) return;
    final html = body.text.split('\n').map((l) => '<p>${const HtmlEscape().convert(l)}</p>').join();
    final api = context.read<Session>().api;
    final data = {'name': name.text.trim(), 'subject': subject.text.trim(), 'html': html};
    t == null ? await api.post('mailbox/templates', data) : await api.put('mailbox/templates/${t['id']}', data);
    await widget.store.reloadIndex();
    if (mounted) setState(() {});
  }
}

class _SignatureEditor extends StatefulWidget {
  const _SignatureEditor({required this.store, this.signature, this.accountId});

  final MailStore store;
  final Map<String, dynamic>? signature;
  final int? accountId;

  @override
  State<_SignatureEditor> createState() => _SignatureEditorState();
}

class _SignatureEditorState extends State<_SignatureEditor> {
  late final Map<String, dynamic> _fields = asMap(widget.signature?['fields']);
  late final Map<String, TextEditingController> _c = {
    for (final k in ['name', 'title', 'company', 'phone', 'mobile', 'email', 'website', 'address', 'logo', 'photo', 'legal']) k: TextEditingController(text: '${_fields[k] ?? _prefill(k)}'),
  };
  late final _label = TextEditingController(text: '${widget.signature?['name'] ?? 'Ma signature'}');
  late final _custom = TextEditingController(text: _fields['mode'] == 'html' ? '${widget.signature?['html'] ?? ''}' : '');
  late String _mode = '${_fields['mode'] ?? 'form'}';
  late String _color = '${_fields['color'] ?? '#2E6BE6'}';
  late bool _default = widget.signature?['is_default'] == true || widget.signature == null;
  late bool _onReply = widget.signature?['on_reply'] != false;
  bool _company = false;
  int? _account;

  String _prefill(String k) {
    final s = context.read<Session>();
    return switch (k) {
      'name' => '${s.user['name'] ?? ''}',
      'email' => '${s.user['email'] ?? ''}',
      'company' => '${s.company['name'] ?? ''}',
      'phone' => '${s.company['phone'] ?? ''}',
      'website' => '${s.company['website'] ?? ''}',
      'address' => [s.company['address'], s.company['city']].where((x) => x != null && '$x'.isNotEmpty).join(', '),
      'logo' => '${s.company['logo_url'] ?? ''}',
      _ => '',
    };
  }

  @override
  void initState() {
    super.initState();
    _account = widget.signature?['account_id'] as int? ?? widget.accountId;
  }

  String _html() => _mode == 'html' ? _custom.text : _htmlFromForm();

  String _htmlFromForm() {
    String v(String k) => const HtmlEscape().convert(_c[k]!.text.trim());
    final logo = _c['logo']!.text.trim();
    final photo = _c['photo']!.text.trim();
    final lines = <String>[
      if (v('phone').isNotEmpty) 'Tél. ${v('phone')}',
      if (v('mobile').isNotEmpty) 'Mobile ${v('mobile')}',
      if (v('email').isNotEmpty) '<a href="mailto:${v('email')}" style="color:$_color;text-decoration:none">${v('email')}</a>',
      if (v('website').isNotEmpty) '<a href="${v('website').startsWith('http') ? v('website') : 'https://${v('website')}'}" style="color:$_color;text-decoration:none">${v('website')}</a>',
    ];
    return '<table cellpadding="0" cellspacing="0" style="font-family:Segoe UI,Arial,sans-serif;font-size:13px;color:#1B1F24"><tr>'
        '${photo.isNotEmpty ? '<td style="padding-right:12px;vertical-align:top"><img src="$photo" alt="" width="72" height="72" style="width:72px;height:72px;border-radius:50%;object-fit:cover"></td>' : ''}'
        '${logo.isNotEmpty && photo.isEmpty ? '<td style="padding-right:14px;vertical-align:top"><img src="$logo" alt="" style="max-width:96px;max-height:72px"></td>' : ''}'
        '<td style="border-left:3px solid $_color;padding-left:12px;vertical-align:top">'
        '<div style="font-size:15px;font-weight:700;color:#0B1B3A">${v('name')}</div>'
        '${v('title').isNotEmpty ? '<div style="color:#5B6B85">${v('title')}</div>' : ''}'
        '${v('company').isNotEmpty ? '<div style="font-weight:600;color:$_color;margin-top:2px">${v('company')}</div>' : ''}'
        '<div style="margin-top:6px;line-height:1.5">${lines.join(' · ')}</div>'
        '${v('address').isNotEmpty ? '<div style="color:#5B6B85;margin-top:2px">${v('address')}</div>' : ''}'
        '</td></tr></table>'
        '${logo.isNotEmpty && photo.isNotEmpty ? '<div style="margin-top:10px"><img src="$logo" alt="" style="max-width:140px;max-height:48px"></div>' : ''}'
        '${v('legal').isNotEmpty ? '<div style="font-size:11px;color:#8792A6;margin-top:8px">${v('legal')}</div>' : ''}';
  }

  Future<void> _save() async {
    final api = context.read<Session>().api;
    final data = {
      'name': _label.text.trim().isEmpty ? 'Signature' : _label.text.trim(), 'html': _html(), 'is_default': _default, 'on_reply': _onReply, 'account_id': _account,
      'fields': {for (final e in _c.entries) e.key: e.value.text.trim(), 'color': _color, 'mode': _mode}, 'company': _company,
    };
    try {
      final r = widget.signature == null ? await api.post('mailbox/signatures', data) : await api.put('mailbox/signatures/${widget.signature!['id']}', data);
      if (mounted) Navigator.pop(context, ((r as Map)['data'] as Map?)?['id'] as int?);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    const labels = {'name': 'Nom', 'title': 'Fonction', 'company': 'Société', 'phone': 'Téléphone', 'mobile': 'Mobile', 'email': 'E-mail', 'website': 'Site web', 'address': 'Adresse', 'logo': 'Logo de la société', 'photo': 'Photo (ronde)', 'legal': 'Mention légale / RCCM'};
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Expanded(
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
              child: ListView(children: [
                TextField(controller: _label, decoration: InputDecoration(labelText: tr('Nom de la signature'))),
                const SizedBox(height: 8),
                SegmentedButton<String>(
                  segments: [ButtonSegment(value: 'form', label: Text(tr('Générateur'))), ButtonSegment(value: 'html', label: Text(tr('HTML personnalisé')))],
                  selected: {_mode},
                  onSelectionChanged: (v) => setState(() => _mode = v.first),
                ),
                if (_mode == 'form') ...[
                  for (final k in labels.keys)
                    k == 'logo' || k == 'photo'
                        ? Row(children: [
                            if (_c[k]!.text.isNotEmpty)
                              Padding(
                                padding: const EdgeInsets.only(right: 8, top: 8),
                                child: k == 'photo' ? CircleAvatar(radius: 18, foregroundImage: NetworkImage(_c[k]!.text)) : Image.network(_c[k]!.text, height: 32, errorBuilder: (_, _, _) => const Icon(Icons.broken_image)),
                              ),
                            Expanded(child: TextField(controller: _c[k], decoration: InputDecoration(labelText: labels[k], hintText: tr('Chargez une image ou collez son adresse')), onChanged: (_) => setState(() {}))),
                            IconButton(
                              tooltip: tr('Charger une image'),
                              icon: const Icon(Icons.upload_outlined),
                              onPressed: () async {
                                final url = await uploadMailImage(context, widget.store);
                                if (url != null) setState(() => _c[k]!.text = url);
                              },
                            ),
                            if (_c[k]!.text.isNotEmpty) IconButton(tooltip: tr('Retirer'), icon: const Icon(Icons.close), onPressed: () => setState(() => _c[k]!.clear())),
                          ])
                        : TextField(controller: _c[k], decoration: InputDecoration(labelText: labels[k]), onChanged: (_) => setState(() {})),
                  const SizedBox(height: 8),
                  Wrap(spacing: 8, children: [
                    for (final c in ['#2E6BE6', '#0B1B3A', '#12A150', '#E8A317', '#D81B60', '#5B5FC7'])
                      InkWell(
                        onTap: () => setState(() => _color = c),
                        child: CircleAvatar(radius: 12, backgroundColor: Color(int.parse('FF${c.substring(1)}', radix: 16)), child: _color == c ? const Icon(Icons.check, size: 14, color: Colors.white) : null),
                      ),
                  ]),
                ] else
                ...[
                  Wrap(spacing: 6, children: [
                    TextButton.icon(
                      onPressed: () async {
                        final url = await uploadMailImage(context, widget.store);
                        if (url != null) setState(() => insertAtCursor(_custom, '<img src="$url" alt="" style="max-width:160px;height:auto">'));
                      },
                      icon: const Icon(Icons.image_outlined, size: 18),
                      label: Text(tr('Insérer une image')),
                    ),
                    TextButton.icon(
                      onPressed: () async {
                        final f = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['html', 'htm', 'txt']);
                        if (f.isEmpty) return;
                        final text = utf8.decode(await f.first.readAsBytes(), allowMalformed: true);
                        final body = RegExp(r'<body[^>]*>([\s\S]*)</body>', caseSensitive: false).firstMatch(text)?.group(1) ?? text;
                        if (mounted) setState(() => _custom.text = body.trim());
                      },
                      icon: const Icon(Icons.file_open_outlined, size: 18),
                      label: Text(tr('Importer un fichier .html')),
                    ),
                    TextButton.icon(
                      onPressed: () => setState(() => _custom.text = _htmlFromForm()),
                      icon: const Icon(Icons.auto_fix_high_outlined, size: 18),
                      label: Text(tr('Partir du générateur')),
                    ),
                  ]),
                  TextField(controller: _custom, minLines: 12, maxLines: 22, style: const TextStyle(fontFamily: 'monospace', fontSize: 12), decoration: InputDecoration(hintText: tr('<table>…</table>')), onChanged: (_) => setState(() {})),
                ],
                DropdownButtonFormField<int?>(
                  initialValue: _account,
                  decoration: InputDecoration(labelText: tr('Compte')),
                  items: [DropdownMenuItem(value: null, child: Text(tr('Tous mes comptes'))), for (final a in widget.store.accounts) DropdownMenuItem(value: a['id'] as int, child: Text(tr('${a['email']}')))],
                  onChanged: (v) => setState(() => _account = v),
                ),
                SwitchListTile(contentPadding: EdgeInsets.zero, value: _default, onChanged: (v) => setState(() => _default = v), title: Text(tr('Signature par défaut'))),
                SwitchListTile(contentPadding: EdgeInsets.zero, value: _onReply, onChanged: (v) => setState(() => _onReply = v), title: Text(tr('Aussi dans les réponses et transferts'))),
                if (widget.store.canManage && widget.signature == null)
                  SwitchListTile(contentPadding: EdgeInsets.zero, value: _company, onChanged: (v) => setState(() => _company = v), title: Text(tr('Signature de la société (proposée à tous)'))),
              ]),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('Aperçu'), style: TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(color: Colors.white, border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(10)),
                    child: MailHtml(key: ValueKey(_html()), html: '<p style="color:#8792A6">Bonjour,<br>…<br>Cordialement,</p>${_html()}'),
                  ),
                ),
              ]),
            ),
          ]),
        ),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))), SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
          ]),
        ),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------- paramètres Microsoft 365 / Google (gestionnaire)

Future<void> mailboxSettings(BuildContext context) => showFormDialog(context, title: 'Paramètres de la boîte mail', maxWidth: 688, builder: (_) => const _Settings());

class _Settings extends StatefulWidget {
  const _Settings();

  @override
  State<_Settings> createState() => _SettingsState();
}

class _SettingsState extends State<_Settings> {
  Map<String, dynamic>? _d;
  final _msId = TextEditingController(), _msSecret = TextEditingController(), _msTenant = TextEditingController();
  final _gId = TextEditingController(), _gSecret = TextEditingController();
  int _retention = 90;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('mailbox/settings').then((r) {
      if (!mounted) return;
      final d = asMap(r['data']);
      setState(() {
        _d = d;
        _msId.text = '${d['microsoft_client_id'] ?? ''}';
        _msTenant.text = '${d['microsoft_tenant'] ?? ''}';
        _gId.text = '${d['google_client_id'] ?? ''}';
        _msSecret.text = d['microsoft_secret_set'] == true ? '••••••••' : '';
        _gSecret.text = d['google_secret_set'] == true ? '••••••••' : '';
        _retention = (d['retention_days'] as num?)?.toInt() ?? 90;
      });
    });
  }

  Widget _copyRow(String label, String value) => Row(children: [
        Expanded(child: SelectableText(tr('$label : $value'), style: const TextStyle(fontSize: 12.5))),
        IconButton(icon: const Icon(Icons.copy, size: 16), onPressed: () => Clipboard.setData(ClipboardData(text: value))),
      ]);

  @override
  Widget build(BuildContext context) {
    final d = _d;
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        d == null
      ? const SizedBox(height: 120, child: Center(child: CircularProgressIndicator()))
      : SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              const Icon(Icons.window, color: Color(0xFF0F6CBD)),
              const SizedBox(width: 8),
              Expanded(child: Text(tr('Microsoft 365 / Outlook'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
              StatusChip(d['microsoft_ready'] == true ? (d['microsoft_platform'] == true ? 'Prêt (plateforme)' : 'Prêt') : 'À paramétrer', color: d['microsoft_ready'] == true ? YColors.success : YColors.warning),
            ]),
            const SizedBox(height: 6),
            Text(
              tr('1. Ouvrez portal.azure.com › Microsoft Entra ID › Inscriptions d\'applications › Nouvelle inscription.\n'
              '2. Nom : Yomara ERP ; types de comptes : « tous les annuaires et comptes Microsoft personnels ».\n'
              '3. URI de redirection (Web) : l\'adresse ci-dessous.\n'
              '4. Certificats et secrets › Nouveau secret client : copiez la valeur.\n'
              '5. Autorisations d\'API › Microsoft Graph (déléguées) : les autorisations ci-dessous, puis « Accorder le consentement ».'),
              style: TextStyle(fontSize: 12.5, height: 1.5),
            ),
            _copyRow('URI de redirection', '${d['redirect_microsoft']}'),
            _copyRow('Autorisations', '${d['ms_scopes']}'),
            TextField(controller: _msId, decoration: InputDecoration(labelText: tr('ID d\'application (client)'))),
            TextField(controller: _msSecret, obscureText: true, decoration: InputDecoration(labelText: tr('Secret client'))),
            TextField(controller: _msTenant, decoration: InputDecoration(labelText: tr('ID d\'annuaire (locataire) — vide = tous les comptes'), hintText: tr('common'))),
            const Divider(height: 32),
            Row(children: [
              const Icon(Icons.mail, color: Color(0xFFEA4335)),
              const SizedBox(width: 8),
              Expanded(child: Text(tr('Gmail / Google Workspace'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
              StatusChip(d['google_ready'] == true ? (d['google_platform'] == true ? 'Prêt (plateforme)' : 'Prêt') : 'À paramétrer', color: d['google_ready'] == true ? YColors.success : YColors.warning),
            ]),
            const SizedBox(height: 6),
            Text(
              tr('1. console.cloud.google.com › Nouveau projet › API et services : activez Gmail API, Google Calendar API et People API.\n'
              '2. Écran de consentement OAuth : type Externe (ou Interne pour Workspace), ajoutez les champs d\'application ci-dessous.\n'
              '3. Identifiants › Créer › ID client OAuth › Application Web, avec l\'URI de redirection ci-dessous.'),
              style: TextStyle(fontSize: 12.5, height: 1.5),
            ),
            _copyRow('URI de redirection', '${d['redirect_google']}'),
            _copyRow('Champs d\'application', '${d['google_scopes']}'),
            TextField(controller: _gId, decoration: InputDecoration(labelText: tr('ID client'))),
            TextField(controller: _gSecret, obscureText: true, decoration: InputDecoration(labelText: tr('Code secret du client'))),
            const Divider(height: 32),
            DropdownButtonFormField<int>(
              initialValue: [30, 90, 180, 365, 730].contains(_retention) ? _retention : 90,
              decoration: InputDecoration(labelText: tr('Conservation de la copie sur le serveur Yomara')),
              items: [
                DropdownMenuItem(value: 30, child: Text(tr('30 jours'))), DropdownMenuItem(value: 90, child: Text(tr('3 mois'))), DropdownMenuItem(value: 180, child: Text(tr('6 mois'))),
                DropdownMenuItem(value: 365, child: Text(tr('1 an'))), DropdownMenuItem(value: 730, child: Text(tr('2 ans'))),
              ],
              onChanged: (v) => setState(() => _retention = v ?? 90),
            ),
            const SizedBox(height: 4),
            Text(tr('Les messages restent toujours chez votre fournisseur ; les messages suivis (drapeau) sont gardés.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
          ]),
        ),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
            FilledButton(
              onPressed: d == null
                  ? null
                  : () async {
                      try {
                        await context.read<Session>().api.put('mailbox/settings', {
                          'microsoft_client_id': _msId.text.trim(), 'microsoft_tenant': _msTenant.text.trim(), 'google_client_id': _gId.text.trim(), 'retention_days': _retention,
                          if (!_msSecret.text.startsWith('••')) 'microsoft_client_secret': _msSecret.text.trim(),
                          if (!_gSecret.text.startsWith('••')) 'google_client_secret': _gSecret.text.trim(),
                        });
                        if (context.mounted) {
                          Navigator.pop(context);
                          toast(context, tr('Paramètres enregistrés.'));
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

/// Signatures d'une boîte : nouveaux messages / réponses et transferts.
class _AccountSignatures extends StatelessWidget {
  const _AccountSignatures({required this.store, required this.account, required this.onChanged});

  final MailStore store;
  final Map<String, dynamic> account;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final id = (account['id'] as num).toInt();
    final cur = store.signatureDefaults[id];
    final sigs = store.signatures.where((x) => x['account_id'] == null || x['account_id'] == id).toList();
    Future<void> save(String key, int? v) async {
      final next = {'account_id': id, 'new_id': cur?['new_id'], 'reply_id': cur?['reply_id'], key: v};
      try {
        await context.read<Session>().api.put('mailbox/signature-defaults', next);
        store.signatureDefaults[id] = next;
        onChanged();
      } catch (e) {
        if (context.mounted) toast(context, errorText(e), error: true);
      }
    }

    Widget pick(String key, String label) => Expanded(
          child: DropdownButtonFormField<int?>(
            key: ValueKey('$id-$key-${cur?[key]}'),
            initialValue: cur == null ? null : cur[key] as int?,
            isExpanded: true,
            decoration: InputDecoration(labelText: tr(label), isDense: true),
            items: [
              DropdownMenuItem<int?>(value: null, child: Text(tr(cur == null ? 'Automatique (par défaut)' : 'Sans signature'))),
              for (final g in sigs) DropdownMenuItem<int?>(value: g['id'] as int, child: Text(tr('${g['name']}'))),
            ],
            onChanged: (v) => save(key, v),
          ),
        );
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(children: [
        SizedBox(
          width: 190,
          child: Row(children: [
            const Icon(Icons.alternate_email_rounded, size: 18),
            const SizedBox(width: 6),
            Expanded(child: Text(tr('${account['email']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600))),
          ]),
        ),
        const SizedBox(width: 8),
        pick('new_id', 'Nouveaux messages'),
        const SizedBox(width: 8),
        pick('reply_id', 'Réponses et transferts'),
      ]),
    );
  }
}
