import 'dart:io';
import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:path_provider/path_provider.dart';

import 'safe_name.dart';
import 'save_prefs.dart';

export 'save_prefs.dart';

/// Enregistre le fichier là où l'utilisateur l'a choisi (Téléchargements par défaut, dossier choisi,
/// ou fenêtre « Enregistrer sous » à chaque fois) et renvoie son chemin. [ask] force la fenêtre.
Future<String> saveFile(String name, List<int> bytes, {String mime = 'application/octet-stream', bool ask = false}) async {
  // Nom venu du serveur (pièce jointe…) : jamais de chemin, le fichier reste dans le dossier choisi
  name = safeFileName(name);
  if (ask || SavePrefs.mode == 'ask') return saveFileAs(name, bytes, mime: mime);
  Directory? dir;
  final chosen = SavePrefs.mode == 'folder' ? SavePrefs.folder : null;
  if (chosen != null && await Directory(chosen).exists()) dir = Directory(chosen);
  if (dir == null) {
    try {
      dir = await getDownloadsDirectory();
    } catch (_) {}
  }
  dir ??= await getApplicationDocumentsDirectory();
  var file = File('${dir.path}${Platform.pathSeparator}$name');
  var i = 1;
  while (await file.exists()) {
    final dot = name.lastIndexOf('.');
    final base = dot > 0 ? name.substring(0, dot) : name;
    final ext = dot > 0 ? name.substring(dot) : '';
    file = File('${dir.path}${Platform.pathSeparator}$base ($i)$ext');
    i++;
  }
  try {
    await file.writeAsBytes(bytes, flush: true);
  } on FileSystemException {
    // Dossier choisi devenu inaccessible (clé USB retirée, droits) : on demande où enregistrer
    return saveFileAs(name, bytes, mime: mime);
  }
  return file.path;
}

/// Fenêtre « Enregistrer sous » du système : l'utilisateur choisit le dossier et le nom.
Future<String> saveFileAs(String name, List<int> bytes, {String mime = 'application/octet-stream'}) async {
  name = safeFileName(name);
  final data = Uint8List.fromList(bytes);
  final uri = await FilePicker.saveFile(dialogTitle: 'Enregistrer sous', fileName: name, bytes: data, mimeType: mime);
  if (uri == null) throw const SaveCancelled();
  if (uri.scheme != 'file') return name; // téléphone : emplacement géré par le système (Fichiers, Drive…)
  final f = File(uri.toFilePath());
  // Ordinateur : on s'assure que le contenu est bien écrit au chemin choisi
  if (!await f.exists() || await f.length() != data.length) await f.writeAsBytes(data, flush: true);
  return f.path;
}

/// Choix d'un dossier par défaut possible sur cet appareil (pas sur téléphone ni sur Mac, où l'accès ne dure pas).
bool get canPickSaveFolder => Platform.isWindows || Platform.isLinux;

Future<String?> pickSaveFolder() => FilePicker.getDirectoryPath(dialogTitle: 'Dossier d\'enregistrement des fichiers');
