import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';

/// Charge une ressource de l'API d'administration avec rafraîchissement optionnel.
class AdminLoader extends StatefulWidget {
  const AdminLoader({super.key, required this.path, required this.builder, this.autoRefresh});

  final String path;
  final Widget Function(BuildContext context, dynamic data, VoidCallback reload) builder;
  final Duration? autoRefresh;

  @override
  State<AdminLoader> createState() => _AdminLoaderState();
}

class _AdminLoaderState extends State<AdminLoader> {
  dynamic _data;
  Object? _error;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
    if (widget.autoRefresh != null) _timer = Timer.periodic(widget.autoRefresh!, (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await context.read<Session>().api.get(widget.path);
      if (mounted) {
        setState(() {
          _data = d;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _data == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_data == null) return const Center(child: CircularProgressIndicator());
    return widget.builder(context, _data, _load);
  }
}

const platformLabels = {
  'windows': 'Windows', 'macos': 'macOS', 'linux': 'Linux', 'web': 'Navigateur', 'android': 'Android', 'ios': 'iOS', 'inconnu': 'Inconnu',
};

IconData platformIcon(String? p) => switch (p) {
      'windows' => Icons.desktop_windows_outlined,
      'macos' => Icons.laptop_mac,
      'linux' => Icons.computer,
      'android' => Icons.phone_android,
      'ios' => Icons.phone_iphone,
      _ => Icons.language,
    };

/// Bandeau super admin : la base de données attend une mise à jour (nouveaux fichiers déposés sur le serveur).
/// Invisible quand tout est à jour.
class ServerUpdateBanner extends StatefulWidget {
  const ServerUpdateBanner({super.key, this.padding = const EdgeInsets.fromLTRB(24, 0, 24, 16)});
  final EdgeInsets padding;

  @override
  State<ServerUpdateBanner> createState() => _ServerUpdateBannerState();
}

class _ServerUpdateBannerState extends State<ServerUpdateBanner> {
  int _pending = 0;
  String? _version;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await context.read<Session>().api.get('admin/system');
      if (mounted) {
        setState(() {
          _pending = (d['pending_migrations'] as num?)?.toInt() ?? 0;
          _version = '${d['version']}';
        });
      }
    } catch (_) {}
  }

  Future<void> _apply() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('admin/system/update');
      if (mounted) toast(context, tr('Mise à jour appliquée.'));
      await _load();
      if (mounted) await context.read<Session>().refreshBootstrap();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_pending == 0) return const SizedBox.shrink();
    return Padding(
      padding: widget.padding,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: YColors.warning.withValues(alpha: .14), borderRadius: BorderRadius.circular(16), border: Border.all(color: YColors.warning)),
        child: Wrap(spacing: 16, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
          const Icon(Icons.system_update_alt, color: YColors.warning, size: 30),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 520),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('Mise à jour du serveur à terminer'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
              const SizedBox(height: 4),
              Text(tr('Les fichiers de la version $_version sont en place, mais $_pending mise(s) à jour de la base de données attendent. Certaines pages afficheront une erreur tant qu\'elles ne sont pas appliquées.')),
            ]),
          ),
          FilledButton.icon(
            onPressed: _busy ? null : _apply,
            icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.play_arrow),
            label: Text(tr(_busy ? 'Mise à jour…' : 'Appliquer la mise à jour')),
          ),
        ]),
      ),
    );
  }
}
