import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config.dart';
import '../../core/local_db.dart';
import '../../files/reload.dart';
import '../../state/session.dart';
import '../theme.dart';
import 'common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
int compareVersions(String a, String b) {
  List<int> p(String v) => v.split(RegExp(r'[.+-]')).map((e) => int.tryParse(e) ?? 0).toList();
  final x = p(a), y = p(b);
  for (var i = 0; i < 4; i++) {
    final d = (i < x.length ? x[i] : 0) - (i < y.length ? y[i] : 0);
    if (d != 0) return d.sign;
  }
  return 0;
}

/// Bouton « Mettre à jour » de la barre du haut : visible pour l'administrateur quand une version est diffusée,
/// et pour tous quand l'interface web est plus ancienne que le serveur (après une mise à jour) ou quand
/// une nouvelle application (Windows, Mac, Android) est disponible.
class UpdateButton extends StatefulWidget {
  const UpdateButton({super.key, this.compact = false});
  final bool compact;

  @override
  State<UpdateButton> createState() => _UpdateButtonState();
}

class _UpdateButtonState extends State<UpdateButton> {
  Map<String, dynamic>? _status;
  Timer? _timer;

  bool _admin(Session s) => s.isSuperAdmin || (s.mode != 'saas' && s.can('core.settings.manage'));

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _check());
    _timer = Timer.periodic(kIsWeb ? const Duration(minutes: 5) : const Duration(hours: 3), (_) => _check());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  /// Version web : interface publiée sur le serveur (version.json), pour proposer le rechargement sans attendre
  Future<void> _check() async {
    final s = context.read<Session>();
    if (!_admin(s)) return;
    try {
      final r = Map<String, dynamic>.from(await s.api.get('system/update/status'));
      if (mounted) setState(() => _status = r);
    } catch (_) {
      // Hors ligne ou serveur ancien : pas de bouton
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final bootVersion = '${s.bootstrap?['app']?['version'] ?? AppConfig.appVersion}';
    // Version du serveur (bootstrap). Le fichier version.json de Flutter n'est pas utilisé : il porte la version des
    // stores (1.0.0…), sans rapport avec celle de l'ERP (0.99.x), et laissait le bouton « Nouvelle interface » allumé.
    final serverVersion = bootVersion;
    final latest = _status?['latest'] is Map ? _status!['latest'] as Map : null;
    final published = latest?['apps'] is Map ? asMap(latest!['apps']) : <String, dynamic>{};
    // liens diffusés avec la version, sinon logiciels déposés sur le serveur (page « Télécharger »)
    final apps = published.isNotEmpty ? published : asMap(s.bootstrap?['app']?['downloads']);
    // 2. Application native plus ancienne que le serveur : lien de téléchargement
    if (!kIsWeb && compareVersions(serverVersion, AppConfig.appVersion) > 0 && _status?['available'] != true) {
      return _Pill(
        compact: widget.compact,
        color: const Color(0xFF6A4BD8),
        icon: Icons.system_update_alt,
        label: tr('Nouvelle application'),
        tooltip: tr('Le serveur est en version $serverVersion, cette application en ${AppConfig.appVersion}.'),
        onTap: () => showDialog(context: context, builder: (_) => _AppDownloadDialog(server: serverVersion, apps: apps)),
      );
    }
    // 3. Mise à jour du logiciel diffusée par Yomara
    if (_status?['available'] == true && latest != null) {
      final mandatory = latest['mandatory'] == true;
      return _Pill(
        compact: widget.compact,
        color: mandatory ? YColors.danger : YColors.success,
        icon: Icons.system_update_alt,
        label: tr('Mettre à jour'),
        badge: 'v${latest['version']}',
        pulse: true,
        tooltip: tr('Yomara ERP ${latest['version']} est disponible (installé : ${_status?['current']}).'),
        onTap: () async {
          await showDialog(context: context, barrierDismissible: false, builder: (_) => UpdateDialog(status: _status!));
          _check();
        },
      );
    }
    // 1. Interface web plus ancienne que le serveur : un simple rechargement suffit
    // (si un rechargement pour cette version n'a rien changé — interface du serveur pas encore à jour —, le bouton ne revient pas)
    if (kIsWeb && compareVersions(serverVersion, AppConfig.appVersion) > 0 && LocalDb.meta.get('web_reload_for') != serverVersion) {
      return _Pill(
        compact: widget.compact,
        color: YColors.blue,
        icon: Icons.refresh,
        label: tr('Nouvelle interface'),
        tooltip: tr('Yomara ERP $serverVersion est installé : rechargez pour l\'utiliser.'),
        onTap: () async {
          await LocalDb.meta.put('web_reload_for', serverVersion);
          reloadApp();
        },
      );
    }
    return const SizedBox.shrink();
  }
}

class _Pill extends StatefulWidget {
  const _Pill({required this.color, required this.icon, required this.label, required this.onTap, this.tooltip, this.badge, this.pulse = false, this.compact = false});
  final Color color;
  final IconData icon;
  final String label;
  final String? tooltip, badge;
  final VoidCallback onTap;
  final bool pulse, compact;

  @override
  State<_Pill> createState() => _PillState();
}

class _PillState extends State<_Pill> with SingleTickerProviderStateMixin {
  late final AnimationController _a = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat(reverse: true);

  @override
  void dispose() {
    _a.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.color;
    if (widget.compact) {
      return IconButton(tooltip: widget.tooltip ?? widget.label, onPressed: widget.onTap, icon: Badge(smallSize: 9, backgroundColor: c, child: Icon(widget.icon, color: c)));
    }
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: Tooltip(
        message: widget.tooltip ?? widget.label,
        child: AnimatedBuilder(
          animation: _a,
          builder: (context, child) => Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(22),
              boxShadow: widget.pulse ? [BoxShadow(color: c.withValues(alpha: .18 + .22 * _a.value), blurRadius: 6 + 10 * _a.value)] : null,
            ),
            child: child,
          ),
          child: Material(
            color: c,
            borderRadius: BorderRadius.circular(22),
            child: InkWell(
              borderRadius: BorderRadius.circular(22),
              onTap: widget.onTap,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(widget.icon, size: 17, color: Colors.white),
                  const SizedBox(width: 6),
                  Text(tr(widget.label), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12.5)),
                  if (widget.badge != null) ...[
                    const SizedBox(width: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                      decoration: BoxDecoration(color: Colors.white.withValues(alpha: .22), borderRadius: BorderRadius.circular(10)),
                      child: Text(tr(widget.badge!), style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                    ),
                  ],
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _AppDownloadDialog extends StatelessWidget {
  const _AppDownloadDialog({required this.server, required this.apps});
  final String server;
  final Map<String, dynamic> apps;

  @override
  Widget build(BuildContext context) {
    const labels = {'windows': ('Windows', Icons.desktop_windows_outlined), 'macos': ('Mac', Icons.laptop_mac), 'mac': ('Mac', Icons.laptop_mac), 'android': ('Android', Icons.android), 'ios': ('iPhone', Icons.phone_iphone), 'linux': ('Linux', Icons.computer)};
    return YAlertDialog(
      title: Text(tr('Nouvelle version de l\'application')),
      content: SizedBox(
        width: 440,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr('Votre serveur est en version $server et cette application en ${AppConfig.appVersion}. Tout continue de fonctionner ; installez la nouvelle application pour profiter des dernières nouveautés.')),
          const SizedBox(height: 14),
          if (apps.isEmpty)
            Text(tr('Demandez le lien d\'installation à votre administrateur ou utilisez la version web (navigateur), toujours à jour.'), style: TextStyle(color: Theme.of(context).hintColor))
          else
            for (final e in apps.entries)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: OutlinedButton.icon(
                  onPressed: () => safeLaunchUrl(Uri.parse('${e.value}'), mode: LaunchMode.externalApplication),
                  icon: Icon(labels[e.key]?.$2 ?? Icons.download),
                  label: Text(tr('Télécharger pour ${labels[e.key]?.$1 ?? e.key}')),
                ),
              ),
        ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Plus tard')))],
    );
  }
}

/// Assistant de mise à jour : nouveautés, vérifications, puis étapes en direct (téléchargement, sauvegarde,
/// installation, base de données, contrôle) ; retour arrière automatique en cas de problème.
class UpdateDialog extends StatefulWidget {
  const UpdateDialog({super.key, required this.status, this.installPath = 'system/update/install'});
  final Map<String, dynamic> status;

  /// Plateforme : installation d'une version envoyée sur ce serveur (admin/releases/{id}/install)
  final String installPath;

  @override
  State<UpdateDialog> createState() => _UpdateDialogState();
}

enum _Phase { intro, running, done, failed }

class _UpdateDialogState extends State<UpdateDialog> {
  Map<String, dynamic>? _pre;
  _Phase _phase = _Phase.intro;
  int _step = 0;
  String? _message;
  List<String> _log = [];

  static const _steps = ['Téléchargement et vérification du paquet', 'Sauvegarde de la version actuelle', 'Installation des nouveaux fichiers', 'Mise à jour de la base de données', 'Contrôle de bon fonctionnement'];

  Map<String, dynamic> get _latest => asMap(widget.status['latest']);

  @override
  void initState() {
    super.initState();
    _preflight();
  }

  Future<void> _preflight() async {
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.get('system/update/preflight'));
      if (mounted) setState(() => _pre = r);
    } catch (e) {
      if (mounted) setState(() => _pre = {'ok': false, 'checks': [{'label': errorText(e), 'ok': false}]});
    }
  }

  Future<void> _run() async {
    final s = context.read<Session>();
    setState(() {
      _phase = _Phase.running;
      _step = 0;
      _log = [];
    });
    // Animation des premières étapes pendant le téléchargement (traitement serveur d'un seul tenant)
    final ticker = Timer.periodic(const Duration(seconds: 6), (t) {
      if (mounted && _step < 2) setState(() => _step++);
    });
    String? token;
    try {
      final r = Map<String, dynamic>.from(await s.api.postLong(widget.installPath));
      token = r['rescue_token'] as String?;
      _log = [for (final l in (r['log'] as List? ?? const [])) '$l'];
    } catch (e) {
      ticker.cancel();
      setState(() {
        _phase = _Phase.failed;
        _message = 'La mise à jour n\'a pas pu être installée : ${errorText(e)}\nRien n\'a été modifié, le logiciel fonctionne comme avant.';
      });
      return;
    }
    ticker.cancel();
    setState(() => _step = 3);
    // Finalisation par une nouvelle requête : le nouveau code est chargé
    try {
      final f = Map<String, dynamic>.from(await s.api.postLong('system/update/finish'));
      _log = [for (final l in (f['log'] as List? ?? const [])) '$l'];
      if (f['ok'] == true) {
        setState(() {
          _step = 5;
          _phase = _Phase.done;
          _message = 'Yomara ERP ${f['version']} est installé.';
        });
      } else {
        setState(() {
          _phase = _Phase.failed;
          _message = f['rolled_back'] == true
              ? 'Un contrôle a échoué (${f['error']}). La version précédente a été restaurée automatiquement : vos données sont intactes.'
              : 'La mise à jour a échoué : ${f['error']}. Contactez le support Yomara.';
        });
      }
    } catch (e) {
      // Le nouveau code ne répond pas : restauration de secours sans Laravel
      final restored = await _rescue(s, token);
      setState(() {
        _phase = _Phase.failed;
        _message = restored
            ? 'La nouvelle version ne démarrait pas correctement : la version précédente a été restaurée. Vos données sont intactes.'
            : 'La nouvelle version ne répond pas (${errorText(e)}). Ouvrez la page restore.php avec le support Yomara pour revenir à la version précédente.';
      });
    }
  }

  Future<bool> _rescue(Session s, String? token) async {
    if (token == null) return false;
    try {
      final r = await http.get(Uri.parse('${s.api.baseUrl}/restore.php?token=$token')).timeout(const Duration(minutes: 5));
      return r.statusCode == 200;
    } catch (_) {
      return false;
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final hint = t.hintColor;
    final latest = _latest;
    final checks = [for (final c in (_pre?['checks'] as List? ?? const [])) asMap(c)];
    final mb = ((latest['size'] as num? ?? 0) / 1048576).toStringAsFixed(1);
    return Dialog(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 560),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  gradient: LinearGradient(colors: _phase == _Phase.failed ? [YColors.warning, YColors.danger] : [YColors.success, const Color(0xFF0B8F6B)]),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(_phase == _Phase.done ? Icons.verified : (_phase == _Phase.failed ? Icons.restore : Icons.system_update_alt), color: Colors.white),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(
                      tr(switch (_phase) {
                        _Phase.done => 'Mise à jour terminée',
                        _Phase.failed => 'Mise à jour non appliquée',
                        _Phase.running => 'Mise à jour en cours…',
                        _ => 'Yomara ERP ${latest['version']}',
                      }),
                      style: t.dialogTheme.titleTextStyle),
                  Text(tr('Version installée : ${_phase == _Phase.done ? latest['version'] : widget.status['current']} · paquet de $mb Mo'), style: TextStyle(color: hint, fontSize: 12.5)),
                ]),
              ),
              if (latest['mandatory'] == true && _phase == _Phase.intro)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(color: YColors.danger.withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
                  child: Text(tr('Importante'), style: TextStyle(color: YColors.danger, fontWeight: FontWeight.w800, fontSize: 11.5)),
                ),
            ]),
            const SizedBox(height: 16),
            if (_phase == _Phase.intro) ...[
              if ('${latest['notes'] ?? ''}'.trim().isNotEmpty)
                Container(
                  constraints: const BoxConstraints(maxHeight: 180),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .05), borderRadius: BorderRadius.circular(12)),
                  child: SingleChildScrollView(child: Text(tr('${latest['notes']}'), style: const TextStyle(height: 1.45))),
                ),
              if (widget.status['previously_failed'] == true)
                Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Text(tr('Une tentative précédente de cette version a été annulée automatiquement. Vous pouvez réessayer ou attendre une version corrigée.'),
                      style: TextStyle(color: YColors.warning, fontSize: 12.5)),
                ),
              const SizedBox(height: 14),
              Text(tr('Vérifications'), style: TextStyle(fontWeight: FontWeight.w800, color: t.colorScheme.onSurface)),
              const SizedBox(height: 6),
              if (_pre == null)
                const Padding(padding: EdgeInsets.all(8), child: LinearProgressIndicator())
              else
                for (final c in checks)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 3),
                    child: Row(children: [
                      Icon(c['ok'] == true ? Icons.check_circle : Icons.cancel, size: 18, color: c['ok'] == true ? YColors.success : YColors.danger),
                      const SizedBox(width: 8),
                      Expanded(child: Text(tr('${c['label']}${c['detail'] != null ? ' — ${c['detail']}' : ''}'), style: const TextStyle(fontSize: 13))),
                    ]),
                  ),
              const SizedBox(height: 10),
              Text(tr('Une sauvegarde complète (logiciel et base de données) est faite avant l\'installation. En cas de problème, la version actuelle est restaurée automatiquement. Comptez une à trois minutes ; les autres utilisateurs peuvent continuer à travailler.'),
                  style: TextStyle(color: hint, fontSize: 12.5)),
            ] else ...[
              for (final (i, label) in _steps.indexed)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 5),
                  child: Row(children: [
                    SizedBox(
                      width: 22,
                      height: 22,
                      child: i < _step || _phase == _Phase.done
                          ? const Icon(Icons.check_circle, color: YColors.success, size: 22)
                          : i == _step && _phase == _Phase.running
                              ? const CircularProgressIndicator(strokeWidth: 2.4)
                              : Icon(Icons.radio_button_unchecked, color: hint.withValues(alpha: .5), size: 22),
                    ),
                    const SizedBox(width: 10),
                    Text(tr(label), style: TextStyle(fontWeight: i == _step && _phase == _Phase.running ? FontWeight.w700 : FontWeight.w500, color: i > _step && _phase == _Phase.running ? hint : null)),
                  ]),
                ),
              if (_message != null) ...[
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: (_phase == _Phase.done ? YColors.success : YColors.warning).withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
                  child: Text(tr(_message!), style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
              ],
              if (_log.isNotEmpty && _phase != _Phase.running)
                Theme(
                  data: t.copyWith(dividerColor: Colors.transparent),
                  child: ExpansionTile(
                    tilePadding: EdgeInsets.zero,
                    title: Text(tr('Détails'), style: TextStyle(fontSize: 13, color: hint)),
                    children: [for (final l in _log) Align(alignment: Alignment.centerLeft, child: Text(tr('• $l'), style: const TextStyle(fontSize: 12)))],
                  ),
                ),
            ],
            const SizedBox(height: 18),
            Row(mainAxisAlignment: MainAxisAlignment.end, children: [
              if (_phase == _Phase.intro) ...[
                TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Plus tard'))),
                const SizedBox(width: 8),
                FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: YColors.success),
                  onPressed: _pre?['ok'] == true ? _run : null,
                  icon: const Icon(Icons.system_update_alt, size: 18),
                  label: Text(tr('Mettre à jour maintenant')),
                ),
              ] else if (_phase == _Phase.done)
                FilledButton.icon(
                  onPressed: () {
                    if (!reloadApp()) {
                      context.read<Session>().refreshBootstrap();
                      Navigator.pop(context);
                    }
                  },
                  icon: const Icon(Icons.refresh, size: 18),
                  label: Text(tr(kIsWeb ? 'Recharger le logiciel' : 'Continuer')),
                )
              else if (_phase == _Phase.failed)
                FilledButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
            ]),
          ]),
        ),
      ),
    );
  }
}
