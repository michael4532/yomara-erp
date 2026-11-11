import 'package:flutter/foundation.dart';

/// Nom de la plateforme envoyé au serveur (en-tête X-Client-Platform)
/// pour le suivi des personnes connectées.
String clientPlatform() {
  if (kIsWeb) return 'web';
  switch (defaultTargetPlatform) {
    case TargetPlatform.windows:
      return 'windows';
    case TargetPlatform.macOS:
      return 'macos';
    case TargetPlatform.linux:
      return 'linux';
    case TargetPlatform.android:
      return 'android';
    case TargetPlatform.iOS:
      return 'ios';
    default:
      return 'autre';
  }
}

class AppConfig {
  static const appName = 'Yomara ERP';
  /// Version de cette application (identique à pubspec.yaml et à config/erp.php du serveur).
  static const appVersion = '0.99.43';

  /// Version Mac App Store : construite avec --dart-define=APP_STORE=true (commande Envoyer-sur-App-Store).
  static const appStoreBuild = bool.fromEnvironment('APP_STORE');

  /// Apple impose ses propres achats intégrés pour tout contenu numérique acheté dans l'application :
  /// sur iPhone/iPad et dans la version Mac App Store, la boutique de modules et les boutons d'achat sont masqués
  /// (les modules achetés sur le site ou la version web fonctionnent normalement).
  static bool get purchasesHidden =>
      const bool.fromEnvironment('HIDE_PURCHASES') || !kIsWeb && (defaultTargetPlatform == TargetPlatform.iOS || (defaultTargetPlatform == TargetPlatform.macOS && appStoreBuild));
  /// Serveur proposé par défaut aux applications Windows, Mac, Android et iOS (modifiable à la connexion,
  /// ou à la compilation : --dart-define=SERVER=https://erp.client.ci).
  static const defaultServer = String.fromEnvironment('SERVER', defaultValue: 'https://erp.yomaratechnologies.com');
  static const syncInterval = Duration(seconds: 20);
  static const heartbeatInterval = Duration(seconds: 30);
  static const requestTimeout = Duration(seconds: 25);
}
