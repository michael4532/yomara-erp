import 'dart:io';

import 'package:path_provider/path_provider.dart';

/// Enregistre le fichier dans « Téléchargements » (ou Documents) et renvoie son chemin.
Future<String> saveFile(String name, List<int> bytes, {String mime = 'application/octet-stream'}) async {
  Directory? dir;
  try {
    dir = await getDownloadsDirectory();
  } catch (_) {}
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
  await file.writeAsBytes(bytes, flush: true);
  return file.path;
}
