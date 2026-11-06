import 'dart:math' as math;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/app_update.dart';
import '../../widgets/common.dart';
import 'admin_common.dart';
import '../../../i18n/i18n.dart';
import '../../../core/json.dart';

/// Super admin : diffuser les mises à jour aux installations clientes (serveurs, PC, Mac) et suivre le parc installé.
class AdminUpdatesScreen extends StatefulWidget {
  const AdminUpdatesScreen({super.key});

  @override
  State<AdminUpdatesScreen> createState() => _AdminUpdatesScreenState();
}

class _AdminUpdatesScreenState extends State<AdminUpdatesScreen> {
  Map<String, dynamic>? _d;
  String? _error;
  String? _busy;
  double? _upload;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.get('admin/releases'));
      if (mounted) setState(() => (_d = d, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  List<Map<String, dynamic>> _list(String k) => [for (final e in (_d?[k] as List? ?? const [])) asMap(e)];

  Future<Map<String, dynamic>?> _ask({required String title, required String action}) => showFormDialog<Map<String, dynamic>>(context, title: title, builder: (c) {
          final notes = TextEditingController();
          var channel = 'stable';
          var mandatory = false;
          var publish = true;
          return StatefulBuilder(
            builder: (c, set) => SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
                Column(mainAxisSize: MainAxisSize.min, children: [
                  TextField(controller: notes, maxLines: 6, decoration: InputDecoration(labelText: tr('Nouveautés (affichées aux clients)'), hintText: tr('• Nouveau module…\n• Corrections…'))),
                  const SizedBox(height: 12),
                  SegmentedButton<String>(
                    segments: [ButtonSegment(value: 'stable', label: Text(tr('Stable (tous)'))), ButtonSegment(value: 'beta', label: Text(tr('Bêta (testeurs)')))],
                    selected: {channel},
                    onSelectionChanged: (v) => set(() => channel = v.first),
                  ),
                  SwitchListTile(contentPadding: EdgeInsets.zero, value: mandatory, onChanged: (v) => set(() => mandatory = v), title: Text(tr('Mise à jour importante')), subtitle: Text(tr('Bouton rouge chez les clients'))),
                  SwitchListTile(contentPadding: EdgeInsets.zero, value: publish, onChanged: (v) => set(() => publish = v), title: Text(tr('Diffuser immédiatement'))),
                ]),
                const SizedBox(height: 20),
                Align(
                  alignment: Alignment.centerRight,
                  child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                    TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                    FilledButton(onPressed: () => Navigator.pop(c, {'notes': notes.text.trim(), 'channel': channel, 'mandatory': mandatory, 'publish': publish}), child: Text(tr(action))),
                  ]),
                ),
              ]),
            ),
          );
        });

  Future<void> _publishSelf() async {
    final o = await _ask(title: tr('Diffuser la version ${_d?['current']}'), action: 'Préparer et diffuser');
    if (o == null || !mounted) return;
    setState(() => _busy = 'self');
    try {
      await context.read<Session>().api.postLong('admin/releases/publish-self', o);
      if (mounted) toast(context, tr('Version ${_d?['current']} ${o['publish'] == true ? 'diffusée' : 'préparée'}'));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  Future<void> _uploadPackage() async {
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['zip']);
    if (files.isEmpty || !mounted) return;
    final o = await _ask(title: tr('Diffuser le paquet ${files.first.name}'), action: 'Envoyer');
    if (o == null || !mounted) return;
    final api = context.read<Session>().api;
    final bytes = await files.first.readAsBytes();
    const size = 4 * 1024 * 1024; // morceaux de 4 Mo (limites des hébergements mutualisés)
    final total = (bytes.length / size).ceil();
    final id = '${DateTime.now().millisecondsSinceEpoch}';
    setState(() => _upload = 0);
    try {
      Map<String, dynamic>? rel;
      for (var i = 0; i < total; i++) {
        final r = await api.uploadForm('admin/releases/upload', {'upload_id': id, 'index': i, 'total': total, 'channel': o['channel'], 'notes': o['notes'], 'mandatory': o['mandatory'] == true ? 1 : 0},
            'chunk', bytes.sublist(i * size, math.min(bytes.length, (i + 1) * size)), 'chunk.bin');
        if (mounted) setState(() => _upload = (i + 1) / total);
        if (r is Map && r['release'] != null) rel = asMap(r['release']);
      }
      if (rel != null && o['publish'] == true) await api.put('admin/releases/${rel['id']}', {'published': true});
      if (mounted) toast(context, tr('Version ${rel?['version'] ?? ''} envoyée'));
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _upload = null);
    }
  }

  Future<void> _patch(Map<String, dynamic> r, Map<String, dynamic> body) async {
    try {
      await context.read<Session>().api.put('admin/releases/${r['id']}', body);
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _apps(Map<String, dynamic> r) async {
    final apps = asMap(r['apps']);
    final ctrls = {for (final k in ['windows', 'macos', 'android', 'ios']) k: TextEditingController(text: '${apps[k] ?? ''}')};
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Applications de la version ${r['version']}')),
        content: SizedBox(
          width: 480,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(tr('Liens de téléchargement proposés aux utilisateurs des applications installées (la version web se met à jour toute seule).'), style: TextStyle(color: Theme.of(c).hintColor)),
            const SizedBox(height: 10),
            for (final e in ctrls.entries)
              Padding(padding: const EdgeInsets.only(bottom: 8), child: TextField(controller: e.value, decoration: InputDecoration(labelText: trn({'windows': 'Windows (.exe / .msix)', 'macos': 'Mac (.dmg)', 'android': 'Android (.apk / Play Store)', 'ios': 'iPhone (App Store / TestFlight)'}[e.key])))),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok == true) await _patch(r, {'apps': {for (final e in ctrls.entries) if (e.value.text.trim().isNotEmpty) e.key: e.value.text.trim()}});
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) return Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(tr(_error!), textAlign: TextAlign.center)));
    if (_d == null) return const Center(child: CircularProgressIndicator());
    final hint = Theme.of(context).hintColor;
    final stats = asMap(_d!['stats']);
    final releases = _list('releases');
    final installs = _list('installations');
    final latest = stats['latest'];
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(0, 0, 0, 32), children: [
        PageHeader(title: tr('Mises à jour'), subtitle: tr('Diffusez une version : les clients voient le bouton « Mettre à jour » et l\'installent en un clic'), actions: [
          OutlinedButton.icon(onPressed: _upload != null ? null : _uploadPackage, icon: const Icon(Icons.upload_file, size: 18), label: Text(tr('Envoyer un paquet .zip'))),
          FilledButton.icon(
            onPressed: _busy != null ? null : _publishSelf,
            icon: _busy == 'self' ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.campaign_outlined, size: 18),
            label: Text(tr('Diffuser la version ${_d!['current']}')),
          ),
        ]),
        const ServerUpdateBanner(),
        if (_upload != null) Padding(padding: const EdgeInsets.symmetric(horizontal: 24), child: LinearProgressIndicator(value: _upload)),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: KpiWrap(children: [
            _Stat('Installations', '${stats['installations']}', Icons.dns_outlined, YColors.blue),
            _Stat('Actives (30 j)', '${stats['active_30d']}', Icons.wifi_tethering, YColors.success),
            _Stat('À jour (${latest ?? '—'})', '${stats['up_to_date']}', Icons.verified_outlined, const Color(0xFF6A4BD8)),
            _Stat('Version de ce serveur', '${_d!['current']}', Icons.cloud_done_outlined, YColors.warning),
          ]),
        ),
        Padding(padding: const EdgeInsets.fromLTRB(24, 22, 24, 8), child: Text(tr('Versions'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800))),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: releases.isEmpty
              ? SectionCard(child: Text(tr('Aucune version préparée. Mettez ce serveur à jour puis cliquez sur « Diffuser la version ${_d!['current']} », ou envoyez le paquet .zip livré.'), style: TextStyle(color: hint)))
              : Card(
                  child: Column(children: [
                    for (final (i, r) in releases.indexed) ...[
                      if (i > 0) const Divider(height: 1),
                      ListTile(
                        leading: CircleAvatar(
                          backgroundColor: (r['published'] == true ? YColors.success : hint).withValues(alpha: .12),
                          child: Icon(r['published'] == true ? Icons.campaign : Icons.inventory_2_outlined, color: r['published'] == true ? YColors.success : hint, size: 20),
                        ),
                        title: Row(children: [
                          Text(tr('Version ${r['version']}'), style: const TextStyle(fontWeight: FontWeight.w800)),
                          const SizedBox(width: 8),
                          if (r['channel'] == 'beta') const _Tag('Bêta', Color(0xFF6A4BD8)),
                          if (r['mandatory'] == true) const _Tag('Importante', YColors.danger),
                          if (r['published'] == true) const _Tag('Diffusée', YColors.success),
                        ]),
                        subtitle: Text(
                            tr('${((r['size'] as num) / 1048576).toStringAsFixed(1)} Mo · ${r['downloads']} téléchargement(s)${r['published_at'] != null ? ' · diffusée le ${dateTimeFr('${r['published_at']}')}' : ''}${'${r['notes'] ?? ''}'.isNotEmpty ? '\n${r['notes']}' : ''}'),
                            maxLines: 3,
                            overflow: TextOverflow.ellipsis),
                        isThreeLine: '${r['notes'] ?? ''}'.isNotEmpty,
                        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                          Switch(value: r['published'] == true, onChanged: (v) => _patch(r, {'published': v})),
                          PopupMenuButton<String>(
                            onSelected: (v) async {
                              if (v == 'mandatory') _patch(r, {'mandatory': r['mandatory'] != true});
                              if (v == 'apps') _apps(r);
                              if (v == 'install') {
                                await showDialog(
                                  context: context,
                                  barrierDismissible: false,
                                  builder: (_) => UpdateDialog(status: {'current': _d!['current'], 'latest': r}, installPath: 'admin/releases/${r['id']}/install'),
                                );
                                _load();
                                return;
                              }
                              if (!context.mounted) return;
                              if (v == 'delete' && await confirm(context, tr('Supprimer la version ${r['version']} ?'), tr('Le paquet est supprimé de ce serveur.'), ok: tr('Supprimer'), danger: true)) {
                                if (!context.mounted) return;
                                await context.read<Session>().api.delete('admin/releases/${r['id']}');
                                _load();
                              }
                            },
                            itemBuilder: (_) => [
                              if (compareVersions('${r['version']}', '${_d!['current']}') > 0)
                                PopupMenuItem(value: 'install', child: Text(tr('Installer sur ce serveur…'))),
                              PopupMenuItem(value: 'mandatory', child: Text(tr(r['mandatory'] == true ? 'Marquer comme normale' : 'Marquer comme importante'))),
                              PopupMenuItem(value: 'apps', child: Text(tr('Liens des applications…'))),
                              PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
                            ],
                          ),
                        ]),
                      ),
                    ],
                  ]),
                ),
        ),
        Padding(padding: const EdgeInsets.fromLTRB(24, 22, 24, 8), child: Text(tr('Parc installé'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800))),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: installs.isEmpty
              ? SectionCard(child: Text(tr('Les installations apparaissent ici dès qu\'elles vérifient les mises à jour (toutes les 3 heures).'), style: TextStyle(color: hint)))
              : Card(
                  child: Column(children: [
                    for (final (i, x) in installs.indexed) ...[
                      if (i > 0) const Divider(height: 1),
                      ListTile(
                        dense: true,
                        leading: Icon(x['os'] == 'Windows' ? Icons.desktop_windows_outlined : (x['os'] == 'Darwin' ? Icons.laptop_mac : Icons.dns_outlined)),
                        title: Text(tr('${x['name'] ?? 'Installation'}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text(tr('${x['url'] ?? ''} · PHP ${x['php'] ?? '?'} · ${x['database'] ?? ''} · vue ${dateTimeFr('${x['last_seen_at']}')}')),
                        trailing: _Tag('v${x['version'] ?? '?'}', x['version'] == latest ? YColors.success : YColors.warning),
                      ),
                    ],
                  ]),
                ),
        ),
      ]),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat(this.label, this.value, this.icon, this.color);
  final String label, value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => KpiBox(width: 240, child: StatTile(label: label, value: value, icon: icon, color: color));
}

class _Tag extends StatelessWidget {
  const _Tag(this.text, this.color);
  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(right: 6),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
        child: Text(tr(text), style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w800)),
      );
}
