import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shorebird_code_push/shorebird_code_push.dart';

import '../../core/config.dart';
import '../../core/local_db.dart';
import '../../files/reload.dart';
import '../../i18n/i18n.dart';
import 'app_update.dart' show compareVersions;

/// Mise à jour automatique de l'interface, pour tout le monde (pas seulement l'administrateur) :
/// - navigateur : dès que le serveur annonce une version plus récente, le cache de l'ancienne interface est vidé
///   et la page rechargée (aussitôt si l'onglet est en arrière-plan ou vient de s'ouvrir, sinon après un court
///   compte à rebours annulable) ;
/// - applications installées (Windows, Mac, Linux, Android, iPhone) : le correctif publié avec Shorebird est
///   téléchargé en arrière-plan, puis appliqué au redémarrage (bouton « Redémarrer » sur ordinateur).
/// Les données (connexion, saisies hors ligne en attente d'envoi) sont conservées dans les deux cas.
class AutoUpdate extends StatefulWidget {
  const AutoUpdate({super.key, required this.child});
  final Widget child;

  @override
  State<AutoUpdate> createState() => _AutoUpdateState();
}

enum _Banner { none, webCountdown, patchReady }

class _AutoUpdateState extends State<AutoUpdate> {
  final _started = DateTime.now();
  Timer? _timer, _tick;
  _Banner _banner = _Banner.none;
  String _version = '';
  int _left = 0;
  bool _later = false, _busy = false;
  // Créé seulement dans les applications installées (dans le navigateur, il écrit un message inutile dans la console)
  late final ShorebirdUpdater _updater = ShorebirdUpdater();

  @override
  void initState() {
    super.initState();
    if (kIsWeb) {
      onVisibilityChange(_onVisibility);
      _timer = Timer.periodic(const Duration(minutes: 3), (_) => _checkWeb());
      Future.delayed(const Duration(seconds: 3), _checkWeb);
    } else if (_updater.isAvailable) {
      _timer = Timer.periodic(const Duration(hours: 1), (_) => _checkPatch());
      Future.delayed(const Duration(seconds: 20), _checkPatch);
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _tick?.cancel();
    super.dispose();
  }

  // ---------- Navigateur ----------

  void _onVisibility() {
    if (pageHidden && _later && _version.isNotEmpty) {
      _reload();
    } else if (!pageHidden) {
      _checkWeb();
    }
  }

  Future<void> _checkWeb() async {
    if (_busy || _banner == _Banner.webCountdown) return;
    try {
      final r = await http.get(pingUri(), headers: {'Accept': 'application/json'}).timeout(const Duration(seconds: 15));
      if (r.statusCode != 200) return;
      final v = '${(jsonDecode(r.body) as Map)['version'] ?? ''}';
      if (v.isEmpty || compareVersions(v, AppConfig.appVersion) <= 0) return;
      // Déjà rechargé pour cette version sans obtenir la nouvelle interface (serveur pas encore complet) : on n'insiste pas
      if (LocalDb.meta.get('web_reload_for') == v) return;
      _version = v;
      if (_later) return;
      if (pageHidden || DateTime.now().difference(_started) < const Duration(seconds: 45)) {
        _reload();
        return;
      }
      if (!mounted) return;
      setState(() {
        _banner = _Banner.webCountdown;
        _left = 20;
      });
      _tick?.cancel();
      _tick = Timer.periodic(const Duration(seconds: 1), (t) {
        if (!mounted) return t.cancel();
        if (_left <= 1) {
          t.cancel();
          _reload();
        } else {
          setState(() => _left--);
        }
      });
    } catch (_) {
      // Hors ligne : nouvel essai au prochain passage
    }
  }

  Future<void> _reload() async {
    if (_busy || _version.isEmpty) return;
    _busy = true;
    _tick?.cancel();
    await LocalDb.meta.put('web_reload_for', _version);
    await hardReloadApp(_version);
  }

  void _postpone() {
    _tick?.cancel();
    setState(() {
      _later = true;
      _banner = _Banner.none;
    });
  }

  // ---------- Applications installées (Shorebird) ----------

  Future<void> _checkPatch() async {
    if (_busy || _banner == _Banner.patchReady) return;
    _busy = true;
    try {
      var st = await _updater.checkForUpdate();
      if (st == UpdateStatus.outdated) {
        await _updater.update();
        st = await _updater.checkForUpdate();
      }
      if (st == UpdateStatus.restartRequired && mounted && !_later) setState(() => _banner = _Banner.patchReady);
    } catch (_) {
      // Pas de réseau ou téléchargement interrompu : nouvel essai plus tard
    } finally {
      _busy = false;
    }
  }

  bool get _desktop =>
      defaultTargetPlatform == TargetPlatform.windows || defaultTargetPlatform == TargetPlatform.macOS || defaultTargetPlatform == TargetPlatform.linux;

  // ---------- Affichage ----------

  @override
  Widget build(BuildContext context) {
    if (_banner == _Banner.none) return widget.child;
    final web = _banner == _Banner.webCountdown;
    final title = web ? tr('Nouvelle version de Yomara ERP ($_version)') : tr('Mise à jour téléchargée');
    final text = web
        ? tr('Mise à jour automatique dans $_left s. Vos données et vos saisies en attente sont conservées.')
        : (_desktop ? tr('Redémarrez l\'application pour l\'utiliser.') : tr('Elle s\'appliquera à la prochaine ouverture de l\'application.'));
    return Stack(children: [
      widget.child,
      Positioned(
        left: 16,
        right: 16,
        bottom: 20,
        child: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: _UpdateCard(
                title: title,
                text: text,
                progress: web ? (20 - _left) / 20 : null,
                primary: web ? tr('Mettre à jour maintenant') : (_desktop ? tr('Redémarrer') : null),
                onPrimary: web ? _reload : () => restartApp(),
                secondary: tr('Plus tard'),
                onSecondary: web ? _postpone : () => setState(() {
                      _later = true;
                      _banner = _Banner.none;
                    }),
              ),
            ),
          ),
        ),
      ),
    ]);
  }
}

class _UpdateCard extends StatelessWidget {
  const _UpdateCard({required this.title, required this.text, this.progress, this.primary, required this.onPrimary, required this.secondary, required this.onSecondary});
  final String title, text, secondary;
  final String? primary;
  final double? progress;
  final VoidCallback onPrimary, onSecondary;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Material(
      elevation: 10,
      shadowColor: Colors.black38,
      color: cs.surface,
      borderRadius: BorderRadius.circular(18),
      clipBehavior: Clip.antiAlias,
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 12, 12),
          child: Row(children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(color: cs.primary.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
              child: Icon(Icons.system_update_alt_rounded, color: cs.primary),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
                const SizedBox(height: 2),
                Text(text, style: TextStyle(color: cs.onSurfaceVariant, fontSize: 13, height: 1.3)),
              ]),
            ),
          ]),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 10),
          child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
            TextButton(onPressed: onSecondary, child: Text(secondary)),
            if (primary != null) ...[
              const SizedBox(width: 6),
              FilledButton(onPressed: onPrimary, child: Text(primary!)),
            ],
          ]),
        ),
        if (progress != null) LinearProgressIndicator(value: progress, minHeight: 3),
      ]),
    );
  }
}
