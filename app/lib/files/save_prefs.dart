import '../core/local_db.dart';

/// Où enregistrer les fichiers téléchargés (PDF, Word, Excel…) sur cet appareil :
/// - « default » : dossier Téléchargements (sinon Documents) ;
/// - « ask » : demander l'emplacement à chaque fois (fenêtre « Enregistrer sous ») ;
/// - « folder » : un dossier choisi une fois pour toutes (ordinateurs Windows et Linux).
class SavePrefs {
  SavePrefs._();

  static String get mode {
    try {
      return (LocalDb.meta.get('save_mode') as String?) ?? 'default';
    } catch (_) {
      return 'default';
    }
  }

  static String? get folder {
    try {
      final f = LocalDb.meta.get('save_dir') as String?;
      return f == null || f.isEmpty ? null : f;
    } catch (_) {
      return null;
    }
  }

  /// Réglage en clair, pour le menu du compte.
  static String get label => switch (mode) {
    'ask' => 'Demander à chaque fois',
    'folder' => folder ?? 'Dossier Téléchargements',
    _ => 'Dossier Téléchargements',
  };

  static Future<void> set(String mode, {String? folder}) async {
    await LocalDb.meta.put('save_mode', mode);
    await LocalDb.meta.put('save_dir', folder ?? '');
  }
}

/// Enregistrement abandonné dans la fenêtre « Enregistrer sous ».
class SaveCancelled implements Exception {
  const SaveCancelled();

  @override
  String toString() => 'Enregistrement annulé.';
}
