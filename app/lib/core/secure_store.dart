import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:hive_ce_flutter/hive_ce_flutter.dart';

/// Coffre du système (Trousseau macOS / iOS, Keystore Android, Gestionnaire d'identifiants Windows,
/// libsecret sous Linux) : jeton de connexion et clé de chiffrement des données locales.
///
/// Si le coffre n'est pas disponible (navigateur, Linux sans service de trousseau, sandbox sans droit),
/// l'application continue de fonctionner comme avant : jeton dans la base locale, données non chiffrées.
class SecureStore {
  SecureStore._();

  static const _storage = FlutterSecureStorage(
    // Trousseau classique sur Mac : utilisable par une application du Mac App Store sans droit supplémentaire
    mOptions: MacOsOptions(usesDataProtectionKeychain: false, accountName: 'yomara_erp'),
    iOptions: IOSOptions(accountName: 'yomara_erp', accessibility: KeychainAccessibility.first_unlock_this_device),
  );

  static bool _ok = false;
  static String? _token;
  static List<int>? _key;

  /// Le coffre du système est utilisable sur cet appareil.
  static bool get available => _ok;

  /// Jeton chargé au démarrage (lecture synchrone ensuite).
  static String? get token => _token;

  /// Empreinte courte de la clé (pour savoir si les données locales ont été chiffrées avec elle).
  static String? get keyId => _key == null ? null : base64Encode(sha256.convert(_key!).bytes.sublist(0, 9));

  /// Clé AES des données locales (null : pas de chiffrement sur cet appareil).
  static HiveAesCipher? get cipher => _key == null ? null : HiveAesCipher(_key!);

  /// Tests : simuler un coffre avec une clé donnée (null : pas de coffre).
  @visibleForTesting
  static void debugSetKey(List<int>? key) {
    _key = key;
    _ok = key != null;
  }

  /// À appeler une fois au démarrage, avant d'ouvrir la session.
  static Future<void> init() async {
    if (kIsWeb || const bool.fromEnvironment('FLUTTER_TEST')) return;
    try {
      _token = await _storage.read(key: 'token').timeout(const Duration(seconds: 5));
      var k = await _storage.read(key: 'data_key').timeout(const Duration(seconds: 5));
      if (k == null) {
        final r = Random.secure();
        k = base64Encode(List<int>.generate(32, (_) => r.nextInt(256)));
        await _storage.write(key: 'data_key', value: k).timeout(const Duration(seconds: 5));
        // relecture : le coffre accepte vraiment l'écriture
        if (await _storage.read(key: 'data_key') != k) throw StateError('coffre en lecture seule');
      }
      _key = base64Decode(k);
      _ok = _key!.length == 32;
      if (!_ok) _key = null;
    } catch (_) {
      _ok = false;
      _key = null;
    }
  }

  static Future<bool> saveToken(String? value) async {
    _token = value;
    if (!_ok) return false;
    try {
      // Trousseau verrouillé ou qui ne répond pas : la connexion / déconnexion ne reste pas bloquée
      if (value == null) {
        await _storage.delete(key: 'token').timeout(const Duration(seconds: 5));
      } else {
        await _storage.write(key: 'token', value: value).timeout(const Duration(seconds: 5));
      }
      return true;
    } catch (_) {
      return false;
    }
  }
}
