import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
String _size(num? b) {
  final v = (b ?? 0).toDouble();
  if (v >= 1073741824) return '${(v / 1073741824).toStringAsFixed(1).replaceAll('.', ',')} Go';
  if (v >= 1048576) return '${(v / 1048576).toStringAsFixed(1).replaceAll('.', ',')} Mo';
  return '${(v / 1024).toStringAsFixed(0)} Ko';
}

String _date(dynamic iso) {
  final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
  return d == null ? '–' : DateFormat('EEE d MMM y à HH:mm', 'fr_FR').format(d);
}

/// Sauvegardes de l'installation : archive chiffrée chaque nuit, Google Drive, Dropbox, restauration.
class BackupsScreen extends StatefulWidget {
  const BackupsScreen({super.key});

  @override
  State<BackupsScreen> createState() => _BackupsScreenState();
}

class _BackupsScreenState extends State<BackupsScreen> {
  Map<String, dynamic>? _d;
  Object? _error;
  bool _running = false;

  ApiClient get api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await api.get('system/backups');
      if (mounted) setState(() => (_d = asMap(r), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _save(Map<String, dynamic> patch) async {
    try {
      final r = await api.put('system/backups', patch);
      if (mounted) setState(() => _d = asMap(r));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _run() async {
    setState(() => _running = true);
    try {
      final r = await api.postLong('system/backups/run');
      final d = r['data'] as Map;
      final up = Map<String, dynamic>.from(d['uploads'] is Map ? d['uploads'] as Map : {});
      if (mounted) {
        toast(context, tr('Sauvegarde faite (${_size(d['size'] as num?)})${up.isEmpty ? '' : ' · ${up.entries.map((e) => '${e.key == 'google' ? 'Google Drive' : 'Dropbox'} ${(e.value as Map)['status'] == 'ok' ? 'OK' : 'en échec'}').join(', ')}'}.'));
      }
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _running = false);
    }
  }

  Future<void> _connect(String p) async {
    try {
      final r = await api.post('system/backups/connect/$p');
      await safeLaunchUrl(Uri.parse('${r['url']}'), mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank');
      if (!mounted) return;
      await showDialog(
        context: context,
        builder: (c) => YAlertDialog(
          title: Text(tr(p == 'google' ? 'Connexion à Google Drive' : 'Connexion à Dropbox')),
          content: Text(tr('Autorisez l\'accès dans la page qui s\'est ouverte, puis revenez ici.')),
          actions: [FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('C\'est fait')))],
        ),
      );
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _restore({String? name, String? provider, String? remoteId, String? label}) async {
    final ok = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => _RestoreDialog(label: label ?? name ?? 'cette sauvegarde'));
    if (ok == null || !mounted) return;
    try {
      final r = await api.postLong('system/backups/restore', {
        'name': ?name, 'provider': ?provider, 'remote_id': ?remoteId, 'confirm': true, 'files': ok['files'], if ((ok['passphrase'] as String).isNotEmpty) 'passphrase': ok['passphrase'],
      });
      if (mounted) await _restored(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _restoreUpload() async {
    final f = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: const ['zip']);
    if (f.isEmpty || !mounted) return;
    final ok = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => _RestoreDialog(label: f.first.name));
    if (ok == null || !mounted) return;
    try {
      final r = await api.uploadForm('system/backups/restore', {'confirm': '1', 'files': ok['files'] == true ? '1' : '0', if ((ok['passphrase'] as String).isNotEmpty) 'passphrase': ok['passphrase']},
          'archive', await f.first.readAsBytes(), f.first.name);
      if (mounted) await _restored(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _restored(Map<String, dynamic> d) async {
    await showDialog(
      context: context,
      builder: (c) => YAlertDialog(
        icon: const Icon(Icons.check_circle, color: YColors.success, size: 40),
        title: Text(tr('Restauration terminée')),
        content: Text(tr('${d['rows']} enregistrement(s) et ${d['files']} fichier(s) restaurés (version ${d['version']}).\n\nL\'état d\'avant la restauration est gardé dans la sauvegarde « ${d['safety']} ». '
            'Reconnectez-vous si le logiciel vous le demande ; les postes hors ligne se resynchronisent automatiquement.')),
        actions: [FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('OK')))],
      ),
    );
    await _load();
  }

  Future<void> _remote(String p) async {
    try {
      final r = await api.get('system/backups/remote/$p');
      final list = [for (final x in (r['data'] as List)) asMap(x)];
      if (!mounted) return;
      final pick = await showDialog<Map<String, dynamic>>(
        context: context,
        builder: (c) => YAlertDialog(
          title: Text(tr(p == 'google' ? 'Sauvegardes sur Google Drive' : 'Sauvegardes sur Dropbox')),
          content: SizedBox(
            width: 520,
            height: 380,
            child: list.isEmpty
                ? Center(child: Text(tr('Aucune sauvegarde pour l\'instant.')))
                : ListView(children: [
                    for (final f in list)
                      ListTile(
                        leading: const Icon(Icons.inventory_2_outlined),
                        title: Text(tr('${f['name']}'), style: const TextStyle(fontSize: 13)),
                        subtitle: Text(tr('${_date(f['created_at'])} · ${_size(f['size'] as num?)}')),
                        trailing: TextButton(onPressed: () => Navigator.pop(c, f), child: Text(tr('Restaurer'))),
                      ),
                  ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
        ),
      );
      if (pick != null) await _restore(provider: p, remoteId: '${pick['id']}', label: '${pick['name']}');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) return _error != null ? Center(child: Text(tr(errorText(_error!)))) : const Center(child: CircularProgressIndicator());
    final s = asMap(d['settings']);
    final last = asMapOrNull(d['last']);
    final dest = asMap(d['destinations']);
    final list = [for (final x in (d['backups'] as List)) asMap(x)];
    final hint = Theme.of(context).hintColor;
    final ok = last?['status'] == 'ok';
    final stale = last == null || DateTime.now().difference(DateTime.tryParse('${last['at']}') ?? DateTime(2000)).inHours > 36;
    return ListView(padding: const EdgeInsets.all(24), children: [
      PageHeader(title: tr('Sauvegardes'), subtitle: tr('Toute votre base et vos documents, chiffrés, chaque nuit — sur ce serveur et dans le cloud'), actions: [
        OutlinedButton.icon(onPressed: _restoreUpload, icon: const Icon(Icons.upload_file), label: Text(tr('Restaurer un fichier'))),
        FilledButton.icon(
          onPressed: _running ? null : _run,
          icon: _running ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.backup_outlined),
          label: Text(tr(_running ? 'Sauvegarde en cours…' : 'Sauvegarder maintenant')),
        ),
      ]),
      Card(
        color: stale || !ok ? const Color(0xFFFFF4E5) : const Color(0xFFE7F6EE),
        child: ListTile(
          leading: Icon(stale || !ok ? Icons.warning_amber_rounded : Icons.verified_user, color: stale || !ok ? YColors.warning : YColors.success, size: 34),
          title: Text(tr(last == null ? 'Aucune sauvegarde pour l\'instant' : 'Dernière sauvegarde : ${_date(last['at'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text(tr(last == null
              ? 'Lancez la première sauvegarde, puis branchez Google Drive ou Dropbox pour en garder une copie hors du serveur.'
              : [
                  if (last['size'] != null) _size(last['size'] as num?),
                  if (last['rows'] != null) '${NumberFormat.decimalPattern('fr_FR').format(last['rows'])} enregistrements',
                  if (last['files'] != null) '${last['files']} fichiers',
                  for (final u in Map<String, dynamic>.from(last['uploads'] is Map ? last['uploads'] as Map : {}).entries) '${u.key == 'google' ? 'Google Drive' : 'Dropbox'} : ${(u.value as Map)['status'] == 'ok' ? 'envoyée' : 'échec (${(u.value as Map)['error']})'}',
                  if (last['error'] != null) '${last['error']}',
                ].join(' · '))),
        ),
      ),
      const SizedBox(height: 8),
      LayoutBuilder(builder: (context, c) {
        final wide = c.maxWidth > 900;
        final left = Column(children: [
          SectionCard(
            title: tr('Planification'),
            child: Column(children: [
              SwitchListTile(contentPadding: EdgeInsets.zero, value: s['enabled'] == true, onChanged: (v) => _save({'enabled': v}), title: Text(tr('Sauvegarde automatique chaque nuit'))),
              Row(children: [
                Expanded(
                  child: DropdownButtonFormField<int>(
                    initialValue: (s['hour'] as num?)?.toInt() ?? 2,
                    decoration: InputDecoration(labelText: tr('Heure')),
                    items: [for (var h = 0; h < 24; h++) DropdownMenuItem(value: h, child: Text(tr('${h.toString().padLeft(2, '0')} h 00')))],
                    onChanged: (v) => _save({'hour': v}),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: DropdownButtonFormField<int>(
                    initialValue: (s['keep_local'] as num?)?.toInt() ?? 7,
                    decoration: InputDecoration(labelText: tr('Copies gardées sur le serveur')),
                    items: [for (final n in const [3, 7, 14, 30]) DropdownMenuItem(value: n, child: Text(tr('$n')))],
                    onChanged: (v) => _save({'keep_local': v}),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: DropdownButtonFormField<int>(
                    initialValue: (s['keep_remote'] as num?)?.toInt() ?? 30,
                    decoration: InputDecoration(labelText: tr('Copies gardées dans le cloud')),
                    items: [for (final n in const [7, 14, 30, 60, 90]) DropdownMenuItem(value: n, child: Text(tr('$n')))],
                    onChanged: (v) => _save({'keep_remote': v}),
                  ),
                ),
              ]),
              SwitchListTile(contentPadding: EdgeInsets.zero, value: s['include_files'] == true, onChanged: (v) => _save({'include_files': v}), title: Text(tr('Inclure les documents et photos déposés'))),
            ]),
          ),
          SectionCard(
            title: tr('Clé de sauvegarde'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(
                tr(d['encrypted'] == true
                    ? 'Les archives sont chiffrées (AES-256) avec cette clé. Notez-la en lieu sûr : sans elle, une sauvegarde ne peut pas être restaurée sur un autre serveur.'
                    : 'Le serveur ne permet pas le chiffrement des archives : conservez-les en lieu sûr.'),
                style: TextStyle(color: hint, fontSize: 13),
              ),
              const SizedBox(height: 10),
              Row(children: [
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    decoration: BoxDecoration(color: const Color(0xFFF4F6FB), borderRadius: BorderRadius.circular(10)),
                    child: SelectableText(tr('${d['passphrase']}'), style: const TextStyle(fontFamily: 'monospace', fontSize: 16, fontWeight: FontWeight.w700, letterSpacing: 1)),
                  ),
                ),
                IconButton(
                  tooltip: tr('Copier'),
                  onPressed: () {
                    Clipboard.setData(ClipboardData(text: '${d['passphrase']}'));
                    toast(context, tr('Clé copiée.'));
                  },
                  icon: const Icon(Icons.copy),
                ),
                IconButton(
                  tooltip: tr('Changer la clé'),
                  onPressed: () async {
                    final ctrl = TextEditingController();
                    final v = await showDialog<String>(
                      context: context,
                      builder: (c) => YAlertDialog(
                        title: Text(tr('Nouvelle clé de sauvegarde')),
                        content: TextField(controller: ctrl, decoration: InputDecoration(labelText: tr('Au moins 12 caractères'), helperText: tr('Les anciennes archives gardent leur clé d\'origine.'))),
                        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, ctrl.text.trim()), child: Text(tr('Enregistrer')))],
                      ),
                    );
                    if (v != null && v.length >= 12) _save({'passphrase': v});
                  },
                  icon: const Icon(Icons.edit_outlined),
                ),
              ]),
            ]),
          ),
        ]);
        final right = Column(children: [
          SectionCard(
            title: tr('Copie dans le cloud'),
            child: Column(children: [
              _DestTile(
                icon: Icons.add_to_drive,
                color: const Color(0xFF1A73E8),
                name: 'Google Drive',
                info: asMap(dest['google']),
                help: 'L\'application Google du serveur (GOOGLE_CLIENT_ID) doit autoriser l\'adresse de retour ci-dessous et l\'API Google Drive.',
                onConnect: () => _connect('google'),
                onDisconnect: () async {
                  final r = await api.delete('system/backups/connect/google');
                  if (mounted) setState(() => _d = asMap(r));
                },
                onBrowse: () => _remote('google'),
              ),
              const Divider(),
              _DestTile(
                icon: Icons.cloud_outlined,
                color: const Color(0xFF0061FE),
                name: 'Dropbox',
                info: asMap(dest['dropbox']),
                help: 'Créez une application sur dropbox.com/developers (accès « App folder », droits files.content.write et files.content.read), ajoutez l\'adresse de retour ci-dessous, puis saisissez sa clé et son secret.',
                onConnect: () => _connect('dropbox'),
                onDisconnect: () async {
                  final r = await api.delete('system/backups/connect/dropbox');
                  if (mounted) setState(() => _d = asMap(r));
                },
                onBrowse: () => _remote('dropbox'),
                onConfigure: () async {
                  final key = TextEditingController(text: '${d['dropbox_app_key'] ?? ''}'), secret = TextEditingController();
                  final ok = await showDialog<bool>(
                    context: context,
                    builder: (c) => YAlertDialog(
                      title: Text(tr('Application Dropbox')),
                      content: SizedBox(
                        width: 420,
                        child: Column(mainAxisSize: MainAxisSize.min, children: [
                          TextField(controller: key, decoration: InputDecoration(labelText: tr('App key'))),
                          TextField(controller: secret, obscureText: true, decoration: InputDecoration(labelText: tr('App secret'))),
                        ]),
                      ),
                      actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer')))],
                    ),
                  );
                  if (ok == true) await _save({'dropbox_app_key': key.text.trim(), if (secret.text.trim().isNotEmpty) 'dropbox_app_secret': secret.text.trim()});
                },
              ),
            ]),
          ),
        ]);
        return wide
            ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: left), const SizedBox(width: 16), Expanded(child: right)])
            : Column(children: [left, right]);
      }),
      SectionCard(
        title: tr('Copies sur ce serveur'),
        child: list.isEmpty
            ? Padding(padding: const EdgeInsets.all(12), child: Text(tr('Aucune copie.'), style: TextStyle(color: hint)))
            : Column(children: [
                for (final b in list)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(b['encrypted'] == true ? Icons.lock_outline : Icons.inventory_2_outlined, color: const Color(0xFF1F6FEB)),
                    title: Text(tr(_date(b['created_at']))),
                    subtitle: Text(tr([_size(b['size'] as num?), if (b['version'] != null) 'v${b['version']}', if (b['reason'] != null) '${b['reason']}', if (b['files'] != null) '${b['files']} fichiers'].join(' · ')),
                        style: TextStyle(color: hint, fontSize: 12.5)),
                    trailing: Wrap(spacing: 4, children: [
                      IconButton(
                        tooltip: tr('Télécharger'),
                        onPressed: () async {
                          final r = await api.get('system/backups/files/${b['name']}/link');
                          await safeLaunchUrl(Uri.parse('${r['url']}'), webOnlyWindowName: '_blank');
                        },
                        icon: const Icon(Icons.download_outlined),
                      ),
                      TextButton(onPressed: () => _restore(name: '${b['name']}', label: _date(b['created_at'])), child: Text(tr('Restaurer'))),
                      IconButton(
                        tooltip: tr('Supprimer'),
                        onPressed: () async {
                          if (!await confirm(context, tr('Supprimer cette copie ?'), tr('Les copies envoyées dans le cloud ne sont pas touchées.'), ok: tr('Supprimer'), danger: true)) return;
                          await api.delete('system/backups/files/${b['name']}');
                          _load();
                        },
                        icon: const Icon(Icons.delete_outline),
                      ),
                    ]),
                  ),
              ]),
      ),
    ]);
  }
}

class _DestTile extends StatelessWidget {
  const _DestTile({required this.icon, required this.color, required this.name, required this.info, required this.help, required this.onConnect, required this.onDisconnect, required this.onBrowse, this.onConfigure});

  final IconData icon;
  final Color color;
  final String name;
  final Map<String, dynamic> info;
  final String help;
  final VoidCallback onConnect;
  final VoidCallback onDisconnect;
  final VoidCallback onBrowse;
  final VoidCallback? onConfigure;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final on = info['connected'] == true;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      ListTile(
        contentPadding: EdgeInsets.zero,
        leading: CircleAvatar(backgroundColor: color.withValues(alpha: .12), child: Icon(icon, color: color)),
        title: Text(tr(name), style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(tr(on ? 'Connecté${info['email'] != null ? ' · ${info['email']}' : ''}' : (info['configured'] == true ? 'Non connecté' : 'Application à configurer'))),
        trailing: on
            ? Wrap(spacing: 4, children: [
                TextButton(onPressed: onBrowse, child: Text(tr('Voir les copies'))),
                IconButton(tooltip: tr('Déconnecter'), onPressed: onDisconnect, icon: const Icon(Icons.link_off)),
              ])
            : FilledButton(onPressed: info['configured'] == true ? onConnect : onConfigure, child: Text(tr(info['configured'] == true || onConfigure == null ? 'Connecter' : 'Configurer'))),
      ),
      if (!on) ...[
        Text(tr(help), style: TextStyle(color: hint, fontSize: 12)),
        SelectableText(tr('Adresse de retour : ${info['redirect_uri']}'), style: TextStyle(color: hint, fontSize: 12)),
        if (onConfigure != null && info['configured'] == true) TextButton(onPressed: onConfigure, child: Text(tr('Modifier l\'application'))),
      ],
    ]);
  }
}

class _RestoreDialog extends StatefulWidget {
  const _RestoreDialog({required this.label});

  final String label;

  @override
  State<_RestoreDialog> createState() => _RestoreDialogState();
}

class _RestoreDialogState extends State<_RestoreDialog> {
  final _pass = TextEditingController();
  final _confirm = TextEditingController();
  bool _files = true;

  @override
  Widget build(BuildContext context) => YAlertDialog(
        icon: const Icon(Icons.settings_backup_restore, color: YColors.danger, size: 36),
        title: Text(tr('Restaurer une sauvegarde')),
        content: SizedBox(
          width: 460,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Toutes les données actuelles seront remplacées par celles de « ${widget.label} ». Une sauvegarde de l\'état actuel est faite juste avant.'), style: const TextStyle(height: 1.4)),
            const SizedBox(height: 10),
            TextField(controller: _pass, decoration: InputDecoration(labelText: tr('Clé de sauvegarde'), helperText: tr('Laissez vide si l\'archive vient de ce serveur'))),
            SwitchListTile(contentPadding: EdgeInsets.zero, value: _files, onChanged: (v) => setState(() => _files = v), title: Text(tr('Restaurer aussi les documents et photos'))),
            TextField(controller: _confirm, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: tr('Tapez RESTAURER pour confirmer'))),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: YColors.danger),
            onPressed: _confirm.text.trim().toUpperCase() == 'RESTAURER' ? () => Navigator.pop(context, {'passphrase': _pass.text.trim(), 'files': _files}) : null,
            child: Text(tr('Restaurer')),
          ),
        ],
      );
}
