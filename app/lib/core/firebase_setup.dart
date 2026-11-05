import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:google_sign_in/google_sign_in.dart';

import 'api_client.dart';

/// Projet Firebase « yomara-erp » (fichiers google-services.json et GoogleService-Info.plist).
/// Notifications push et connexion Google : téléphones Android et iPhone uniquement.
class FirebaseSetup {
  static const webClientId = '400573441933-pp4h4gfg4dqjrkj0oc90mdu865lbimv9.apps.googleusercontent.com';
  static const iosClientId = '400573441933-nkgqpohhleqe73566n09hg34e9krqmdo.apps.googleusercontent.com';

  static bool get supported => !kIsWeb && (defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS);
  static bool get _ios => defaultTargetPlatform == TargetPlatform.iOS;

  static FirebaseOptions get options => _ios
      ? const FirebaseOptions(
          apiKey: 'AIzaSyB0N0mqYyqa0H7cukd4z3BuE_1hYzrN-bs',
          appId: '1:400573441933:ios:ed4d5ddddf98f03fa0ffc4',
          messagingSenderId: '400573441933',
          projectId: 'yomara-erp',
          storageBucket: 'yomara-erp.firebasestorage.app',
          iosClientId: iosClientId,
          iosBundleId: 'com.yomaratechnologies.yomaraerp',
        )
      : const FirebaseOptions(
          apiKey: 'AIzaSyB9Q4JnLVbvWvRobFBk2gGmC1pa4-056do',
          appId: '1:400573441933:android:10f071307de2f528a0ffc4',
          messagingSenderId: '400573441933',
          projectId: 'yomara-erp',
          storageBucket: 'yomara-erp.firebasestorage.app',
        );

  static Future<bool>? _ready;

  static Future<bool> ensure() => _ready ??= () async {
        if (!supported) return false;
        try {
          if (Firebase.apps.isEmpty) await Firebase.initializeApp(options: options);
          return true;
        } catch (e) {
          debugPrint('Firebase indisponible : $e');
          return false;
        }
      }();

  // ------------------------------------------------------------ notifications push

  static String? _token;
  static StreamSubscription<String>? _refresh;

  /// Après la connexion : demande l'autorisation, puis envoie le jeton de l'appareil au serveur.
  static Future<void> registerPush(ApiClient api) async {
    if (!await ensure()) return;
    try {
      final m = FirebaseMessaging.instance;
      final perm = await m.requestPermission(alert: true, badge: true, sound: true);
      if (perm.authorizationStatus == AuthorizationStatus.denied) return;
      await m.setForegroundNotificationPresentationOptions(alert: true, badge: true, sound: true);
      // Jeton d'enregistrement classique : c'est la cible attendue par l'API FCM HTTP v1 du serveur
      // ignore: deprecated_member_use
      final t = await m.getToken();
      if (t == null) return;
      _token = t;
      await api.post('me/push-token', {'token': t, 'platform': _ios ? 'ios' : 'android'});
      await _refresh?.cancel();
      // ignore: deprecated_member_use
      _refresh = m.onTokenRefresh.listen((n) {
        _token = n;
        api.post('me/push-token', {'token': n, 'platform': _ios ? 'ios' : 'android'}).catchError((_) => null);
      });
    } catch (e) {
      debugPrint('Notifications push non activées : $e');
    }
  }

  /// À la déconnexion : l'appareil ne reçoit plus les notifications de ce compte.
  static Future<void> unregisterPush(ApiClient api) async {
    final t = _token;
    _token = null;
    await _refresh?.cancel();
    _refresh = null;
    if (t == null) return;
    try {
      await api.delete('me/push-token', {'token': t});
    } catch (_) {}
    // Jeton de l'appareil supprimé aussi chez Firebase : même si la session avait expiré (serveur injoignable avec
    // l'ancien jeton), l'appareil ne reçoit plus les notifications du compte précédent. Un nouveau jeton est créé à la connexion.
    try {
      // ignore: deprecated_member_use
      if (await ensure()) await FirebaseMessaging.instance.deleteToken();
    } catch (_) {}
  }

  /// Notification reçue appli ouverte, et notification touchée (appli en arrière-plan ou fermée).
  static void listen({required void Function(RemoteMessage m) onForeground, required void Function(Map<String, dynamic> data) onOpen}) {
    ensure().then((ok) async {
      if (!ok) return;
      FirebaseMessaging.onMessage.listen(onForeground);
      FirebaseMessaging.onMessageOpenedApp.listen((m) => onOpen(m.data));
      final initial = await FirebaseMessaging.instance.getInitialMessage();
      if (initial != null) onOpen(initial.data);
    });
  }

  // ------------------------------------------------------------ connexion Google (facultative)

  /// Android uniquement : sur iPhone, Apple exigerait aussi « Se connecter avec Apple ».
  static bool get googleSignInSupported => !kIsWeb && defaultTargetPlatform == TargetPlatform.android;

  static bool _gsiReady = false;

  /// Ouvre le choix du compte Google et renvoie le jeton d'identité à vérifier par le serveur (null si annulé).
  static Future<String?> googleIdToken() async {
    if (!supported) return null;
    final g = GoogleSignIn.instance;
    if (!_gsiReady) {
      await g.initialize(clientId: _ios ? iosClientId : null, serverClientId: webClientId);
      _gsiReady = true;
    }
    try {
      final acc = await g.authenticate(scopeHint: const ['email']);
      return acc.authentication.idToken;
    } on GoogleSignInException catch (e) {
      if (e.code == GoogleSignInExceptionCode.canceled) return null;
      rethrow;
    } finally {
      // Le choix du compte est reproposé à la prochaine connexion
      unawaited(g.signOut().catchError((_) {}));
    }
  }
}
