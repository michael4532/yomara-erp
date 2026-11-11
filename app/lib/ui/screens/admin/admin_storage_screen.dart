import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';

int toInt(Object? v) => v is int ? v : (v is num ? v.toInt() : int.tryParse('$v') ?? 0);

String _size(num b) {
  if (b >= 1099511627776) return '${(b / 1099511627776).toStringAsFixed(1).replaceAll('.0', '')} To';
  if (b >= 1073741824) return '${(b / 1073741824).toStringAsFixed(1).replaceAll('.0', '')} Go';
  if (b >= 1048576) return '${(b / 1048576).round()} Mo';
  return '${(b / 1024).round()} Ko';
}

/// Super admin : stockages des documents des clients (serveur, Cloudflare R2, Backblaze B2, Wasabi…),
/// ordre de priorité et bascule automatique vers le suivant quand un stockage est plein ou ne répond pas.
class AdminStorageScreen extends StatefulWidget {
  const AdminStorageScreen({super.key});

  @override
  State<AdminStorageScreen> createState() => _AdminStorageScreenState();
}

class _AdminStorageScreenState extends State<AdminStorageScreen> {
  Map<String, dynamic>? _d;
  String? _error;
  int? _testing;

  @override
  void initState() {
    super.initState();
    _run(context.read<Session>().api.get('admin/storage'));
  }

  Future<Map<String, dynamic>?> _run(Future<dynamic> call) async {
    try {
      final r = asMap(await call);
      if (mounted) setState(() => (_d = r, _error = null));
      return r;
    } catch (e) {
      if (mounted) {
        if (_d == null) setState(() => _error = errorText(e));
        toast(context, errorText(e), error: true);
      }
      return null;
    }
  }

  List<Map<String, dynamic>> get _list => [for (final b in (_d?['data'] as List? ?? const [])) asMap(b)];

  Future<void> _test(Map<String, dynamic> b) async {
    setState(() => _testing = toInt(b['id']));
    final r = await _run(context.read<Session>().api.post('admin/storage/${b['id']}/test'));
    if (mounted) setState(() => _testing = null);
    if (r != null && mounted) toast(context, tr('${r['message']}'), error: r['ok'] != true);
  }

  Future<void> _move(int i, int delta) async {
    final ids = _list.map((b) => b['id']).toList();
    final j = i + delta;
    if (j < 0 || j >= ids.length) return;
    final t = ids[i];
    ids[i] = ids[j];
    ids[j] = t;
    await _run(context.read<Session>().api.post('admin/storage/reorder', {'ids': ids}));
  }

  Future<void> _edit([Map<String, dynamic>? b]) async {
    final providers = asMap(_d?['providers']);
    final r = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => _BackendDialog(backend: b, providers: providers));
    if (r == null || !mounted) return;
    final api = context.read<Session>().api;
    final res = await _run(b == null ? api.post('admin/storage', r) : api.put('admin/storage/${b['id']}', r));
    if (res != null && b == null && mounted) {
      final t = asMap(res['test']);
      toast(context, tr(t['ok'] == true ? 'Stockage ajouté et testé : il fonctionne.' : 'Stockage ajouté, mais l\'essai a échoué : ${t['message']}'), error: t['ok'] != true);
    }
  }

  Future<void> _delete(Map<String, dynamic> b) async {
    if (!await confirm(context, tr('Supprimer ce stockage ?'), tr('« ${b['name']} » ne recevra plus de fichiers. Ses clés seront effacées.'), ok: tr('Supprimer'))) return;
    if (!mounted) return;
    await _run(context.read<Session>().api.delete('admin/storage/${b['id']}'));
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) return Center(child: _error == null ? const CircularProgressIndicator() : Text(_error!));
    final cs = Theme.of(context).colorScheme;
    final hint = Theme.of(context).hintColor;
    final totals = asMap(d['totals']);
    final providers = asMap(d['providers']);
    final list = _list;
    final firstActive = list.indexWhere((b) => b['is_active'] == true && b['full'] != true);
    return ListView(padding: const EdgeInsets.only(bottom: 40), children: [
      PageHeader(
        title: tr('Stockage des documents'),
        subtitle: tr('Les fichiers des clients vont dans le premier stockage actif qui a de la place. Quand il est plein ou ne répond pas, le suivant prend le relais automatiquement. Chaque fichier reste lisible là où il a été déposé.'),
        actions: [FilledButton.icon(onPressed: () => _edit(), icon: const Icon(Icons.add), label: Text(tr('Ajouter un stockage')))],
      ),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 900),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(colors: [Color(0xFF1E3A8A), Color(0xFF4F46E5)]),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Row(children: [
                  const Icon(Icons.cloud_done_outlined, color: Colors.white, size: 36),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(_size(toInt(totals['used'])), style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800)),
                      Text(
                        tr(totals['capacity'] == null ? 'utilisés · capacité sans limite fixée' : 'utilisés sur ${_size(toInt(totals['capacity']))} au total'),
                        style: const TextStyle(color: Colors.white70),
                      ),
                    ]),
                  ),
                  Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    Text('${list.where((b) => b['is_active'] == true).length}', style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800)),
                    Text(tr('stockage(s) actif(s)'), style: const TextStyle(color: Colors.white70)),
                  ]),
                ]),
              ),
              const SizedBox(height: 18),
              for (var i = 0; i < list.length; i++) _card(context, list[i], i, i == firstActive, providers, cs, hint),
              const SizedBox(height: 8),
              Text(
                tr('Astuce : beaucoup de services offrent de l\'espace gratuit au départ (par exemple Cloudflare R2 ou Backblaze B2), puis facturent au-delà. Indiquez la capacité gratuite de chacun : quand elle est atteinte, les fichiers passent sur le suivant.'),
                style: TextStyle(color: hint, fontSize: 12.5),
              ),
            ]),
          ),
        ),
      ),
    ]);
  }

  Widget _card(BuildContext context, Map<String, dynamic> b, int i, bool current, Map<String, dynamic> providers, ColorScheme cs, Color hint) {
    final used = toInt(b['used_bytes']);
    final cap = b['capacity_mb'] == null ? null : toInt(b['capacity_mb']) * 1048576;
    final r = cap == null || cap == 0 ? null : (used / cap).clamp(0, 1).toDouble();
    final active = b['is_active'] == true;
    final error = b['last_error'] != null && (b['last_ok_at'] == null || '${b['last_error_at']}'.compareTo('${b['last_ok_at']}') > 0);
    final label = b['provider'] == 'local' ? tr('Serveur') : tr('${asMap(providers[b['provider']])['label'] ?? b['provider']}');
    final tone = !active ? cs.outline : (b['full'] == true ? YColors.danger : (error ? YColors.warning : YColors.success));
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Theme.of(context).cardColor,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: current ? YColors.blue : Theme.of(context).dividerColor.withValues(alpha: .5), width: current ? 1.6 : 1),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Container(
              width: 34,
              height: 34,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .1), borderRadius: BorderRadius.circular(10)),
              child: Text('${i + 1}', style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w800)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  Text(tr('${b['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                  StatusChip(label, color: YColors.blue),
                  if (current) StatusChip(tr('Reçoit les nouveaux fichiers'), color: YColors.success),
                  if (b['full'] == true) StatusChip(tr('Plein'), color: YColors.danger),
                  if (!active) StatusChip(tr('Désactivé'), color: YColors.muted),
                ]),
                if (b['provider'] != 'local') Text('${b['bucket']} · ${b['endpoint']}', maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12)),
              ]),
            ),
            IconButton(tooltip: tr('Monter'), onPressed: i == 0 ? null : () => _move(i, -1), icon: const Icon(Icons.arrow_upward_rounded)),
            IconButton(tooltip: tr('Descendre'), onPressed: i == _list.length - 1 ? null : () => _move(i, 1), icon: const Icon(Icons.arrow_downward_rounded)),
          ]),
          const SizedBox(height: 14),
          if (r != null) LinearProgressIndicator(value: r, minHeight: 8, borderRadius: BorderRadius.circular(5), color: r >= .9 ? YColors.danger : (r >= .75 ? YColors.warning : YColors.blue)),
          const SizedBox(height: 8),
          Row(children: [
            Icon(Icons.circle, size: 10, color: tone),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                tr('${_size(used)} utilisés${cap == null ? ' · sans limite fixée' : ' sur ${_size(cap)}'}${error ? ' · dernière erreur : ${b['last_error']}' : ''}'),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(color: error ? YColors.warning : hint, fontSize: 12.5),
              ),
            ),
          ]),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            OutlinedButton.icon(
              onPressed: _testing == null ? () => _test(b) : null,
              icon: _testing == toInt(b['id']) ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.network_check_rounded, size: 18),
              label: Text(tr('Tester')),
            ),
            OutlinedButton.icon(onPressed: () => _edit(b), icon: const Icon(Icons.edit_outlined, size: 18), label: Text(tr('Modifier'))),
            OutlinedButton.icon(
              onPressed: () => _run(context.read<Session>().api.put('admin/storage/${b['id']}', {'is_active': !active})),
              icon: Icon(active ? Icons.pause_circle_outline : Icons.play_circle_outline, size: 18),
              label: Text(tr(active ? 'Désactiver' : 'Activer')),
            ),
            if (b['provider'] != 'local' && used == 0)
              TextButton.icon(onPressed: () => _delete(b), icon: const Icon(Icons.delete_outline, size: 18), label: Text(tr('Supprimer')), style: TextButton.styleFrom(foregroundColor: YColors.danger)),
          ]),
        ]),
      ),
    );
  }
}

class _BackendDialog extends StatefulWidget {
  const _BackendDialog({this.backend, required this.providers});

  final Map<String, dynamic>? backend;
  final Map<String, dynamic> providers;

  @override
  State<_BackendDialog> createState() => _BackendDialogState();
}

class _BackendDialogState extends State<_BackendDialog> {
  late final b = widget.backend;
  late String _provider = '${b?['provider'] ?? 'r2'}';
  late final _name = TextEditingController(text: '${b?['name'] ?? ''}');
  late final _endpoint = TextEditingController(text: '${b?['endpoint'] ?? asMap(widget.providers['r2'])['endpoint']}');
  late final _region = TextEditingController(text: '${b?['region'] ?? asMap(widget.providers['r2'])['region']}');
  late final _bucket = TextEditingController(text: '${b?['bucket'] ?? ''}');
  final _key = TextEditingController();
  final _secret = TextEditingController();
  late final _cap = TextEditingController(text: b?['capacity_mb'] == null ? '' : (toInt(b!['capacity_mb']) / 1024).toStringAsFixed(1).replaceAll('.0', ''));

  bool get _local => _provider == 'local';

  void _pick(String p) {
    final d = asMap(widget.providers[p]);
    setState(() {
      _provider = p;
      _endpoint.text = '${d['endpoint'] ?? ''}';
      _region.text = '${d['region'] ?? ''}';
      if (_name.text.isEmpty || widget.providers.values.any((v) => asMap(v)['label'] == _name.text)) _name.text = '${d['label'] ?? ''}';
    });
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final info = asMap(widget.providers[_provider]);
    return YAlertDialog(
      title: Text(tr(b == null ? 'Ajouter un stockage' : 'Modifier « ${b!['name']} »')),
      content: SizedBox(
        width: 520,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (!_local && b == null) ...[
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final e in widget.providers.entries)
                  ChoiceChip(label: Text(tr('${asMap(e.value)['label']}')), selected: _provider == e.key, onSelected: (_) => _pick(e.key)),
              ]),
              const SizedBox(height: 14),
            ],
            if (!_local && info['hint'] != null)
              Container(
                padding: const EdgeInsets.all(12),
                margin: const EdgeInsets.only(bottom: 12),
                decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .06), borderRadius: BorderRadius.circular(12)),
                child: Text(tr('${info['hint']}'), style: TextStyle(fontSize: 12.5, color: hint)),
              ),
            Field(label: 'Nom', child: TextField(controller: _name)),
            if (!_local) ...[
              Field(label: 'Adresse S3 (endpoint)', child: TextField(controller: _endpoint, decoration: InputDecoration(hintText: tr('Remplacez {compte} ou {region} par vos valeurs')))),
              Row(children: [
                Expanded(child: Field(label: 'Région', child: TextField(controller: _region))),
                const SizedBox(width: 12),
                Expanded(child: Field(label: 'Compartiment (bucket)', child: TextField(controller: _bucket))),
              ]),
              Field(label: b == null ? 'Clé d\'accès' : 'Clé d\'accès (vide : inchangée)', child: TextField(controller: _key, autocorrect: false)),
              Field(label: b == null ? 'Clé secrète' : 'Clé secrète (vide : inchangée)', child: TextField(controller: _secret, obscureText: true, autocorrect: false)),
            ],
            Field(
              label: 'Capacité à utiliser (Go)',
              child: TextField(
                controller: _cap,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(hintText: tr('Vide : sans limite'), helperText: tr('Ex. 10 pour rester dans l\'offre gratuite : au-delà, les fichiers vont sur le stockage suivant.')),
              ),
            ),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: () {
            final cap = double.tryParse(_cap.text.replaceAll(',', '.'));
            Navigator.pop(context, {
              'name': _name.text.trim(),
              'capacity_mb': cap == null ? null : (cap * 1024).round(),
              if (!_local) ...{
                'provider': _provider,
                'endpoint': _endpoint.text.trim(),
                'region': _region.text.trim(),
                'bucket': _bucket.text.trim(),
                'access_key': _key.text.trim(),
                'secret_key': _secret.text.trim(),
              },
            });
          },
          child: Text(tr(b == null ? 'Ajouter et tester' : 'Enregistrer')),
        ),
      ],
    );
  }
}
