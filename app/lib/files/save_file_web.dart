import 'dart:js_interop';
import 'dart:typed_data';

import 'package:web/web.dart' as web;

import 'safe_name.dart';

export 'save_prefs.dart';

/// Déclenche le téléchargement dans le navigateur.
Future<String> saveFile(String name, List<int> bytes, {String mime = 'application/octet-stream', bool ask = false}) async {
  final blob = web.Blob([Uint8List.fromList(bytes).toJS].toJS, web.BlobPropertyBag(type: mime));
  final url = web.URL.createObjectURL(blob);
  final a = web.HTMLAnchorElement()
    ..href = url
    ..download = safeFileName(name);
  web.document.body?.append(a);
  a.click();
  a.remove();
  web.URL.revokeObjectURL(url);
  return safeFileName(name);
}

/// Navigateur : c'est lui qui demande l'emplacement (selon ses réglages de téléchargement).
Future<String> saveFileAs(String name, List<int> bytes, {String mime = 'application/octet-stream'}) => saveFile(name, bytes, mime: mime);

bool get canPickSaveFolder => false;

Future<String?> pickSaveFolder() async => null;
