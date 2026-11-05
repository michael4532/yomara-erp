import 'dart:js_interop';
import 'dart:typed_data';

import 'package:web/web.dart' as web;

/// Déclenche le téléchargement dans le navigateur.
Future<String> saveFile(String name, List<int> bytes, {String mime = 'application/octet-stream'}) async {
  final blob = web.Blob([Uint8List.fromList(bytes).toJS].toJS, web.BlobPropertyBag(type: mime));
  final url = web.URL.createObjectURL(blob);
  final a = web.HTMLAnchorElement()
    ..href = url
    ..download = name;
  web.document.body?.append(a);
  a.click();
  a.remove();
  web.URL.revokeObjectURL(url);
  return name;
}
