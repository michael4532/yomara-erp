import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../i18n/i18n.dart';

import 'config.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.status, this.code, this.errors, this.body});

  final String message;
  final int? status;
  final String? code;
  final Map<String, dynamic>? errors;
  final dynamic body;

  /// Erreur réseau (serveur injoignable) : on bascule en mode hors ligne.
  bool get isNetwork => status == null;
  bool get isUnauthorized => status == 401;

  /// Premier message de validation lisible.
  String get detail {
    if (errors != null && errors!.isNotEmpty) {
      final first = errors!.values.first;
      if (first is List && first.isNotEmpty) return first.first.toString();
    }
    return message;
  }

  @override
  String toString() => detail;
}

/// Client HTTP de l'API Laravel.
class ApiClient {
  ApiClient({required this.baseUrl, this.token, this.companyId});

  String baseUrl;
  String? token;

  /// Super admin : agir dans une autre société.
  int? companyId;

  /// Multi-agences : agence de travail (valeur par défaut des créations côté serveur).
  String? branchId;

  void Function()? onUnauthorized;

  /// Adresse en http:// accessible depuis internet : le mot de passe et le jeton circuleraient en clair.
  /// Le http reste accepté sur le réseau local (serveur installé dans l'entreprise : 192.168.x.x, nom sans point, .local).
  static String? insecureReason(String url) {
    final u = Uri.tryParse(url);
    if (u == null || u.scheme != 'http') return null;
    final h = u.host.toLowerCase();
    if (h.isEmpty || h == 'localhost' || !h.contains('.') || h.endsWith('.local') || h.endsWith('.lan') || h.endsWith('.localhost')) return null;
    final ip = RegExp(r'^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$').firstMatch(h);
    if (ip != null) {
      final a = int.parse(ip.group(1)!), b = int.parse(ip.group(2)!);
      if (a == 10 || a == 127 || (a == 192 && b == 168) || (a == 172 && b >= 16 && b <= 31) || (a == 169 && b == 254) || (a == 100 && b >= 64 && b <= 127)) return null;
    }
    return 'Adresse non sécurisée : utilisez https:// (en http, votre mot de passe circulerait en clair sur internet).';
  }

  static String normalizeUrl(String url) {
    var u = url.trim();
    if (u.isEmpty) return u;
    if (!u.startsWith('http://') && !u.startsWith('https://')) u = 'https://$u';
    while (u.endsWith('/')) {
      u = u.substring(0, u.length - 1);
    }
    if (u.endsWith('/app')) u = u.substring(0, u.length - 4);
    return u;
  }

  Uri _uri(String path, [Map<String, dynamic>? query]) {
    // Listes : paramètre répété (sections[]=a&sections[]=b)
    final q = query?.map<String, dynamic>((k, v) => MapEntry(k, v is Iterable ? [for (final x in v) '$x'] : (v?.toString() ?? '')))
      ?..removeWhere((k, v) => v is String ? v.isEmpty : (v as List).isEmpty);
    return Uri.parse('$baseUrl/api/$path').replace(queryParameters: (q == null || q.isEmpty) ? null : q);
  }

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Client-Platform': clientPlatform(),
        'X-Locale': I18n.lang,
        if (token != null) 'Authorization': 'Bearer $token',
        if (companyId != null) 'X-Company-Id': '$companyId',
        'X-Branch-Id': ?branchId,
      };

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => http.get(_uri(path, query), headers: _headers));

  Future<dynamic> post(String path, [Object? body, Map<String, String>? headers]) =>
      _send(() => http.post(_uri(path), headers: {..._headers, ...?headers}, body: jsonEncode(body ?? {})));

  /// Traitement long côté serveur (import de milliers de lignes…).
  Future<dynamic> postLong(String path, [Object? body]) =>
      _send(() => http.post(_uri(path), headers: _headers, body: jsonEncode(body ?? {})), timeout: const Duration(minutes: 10));

  Future<dynamic> put(String path, [Object? body, Map<String, String>? headers]) =>
      _send(() => http.put(_uri(path), headers: {..._headers, ...?headers}, body: jsonEncode(body ?? {})));

  /// Réponse brute (fichiers : CSV, PDF…).
  Future<List<int>> download(String path, {Map<String, dynamic>? query}) async {
    final res = await http.get(_uri(path, query), headers: {..._headers, 'Accept': '*/*'}).timeout(AppConfig.requestTimeout * 2);
    if (res.statusCode >= 200 && res.statusCode < 300) return res.bodyBytes;
    if (res.statusCode == 401) onUnauthorized?.call();
    throw ApiException('Téléchargement impossible (${res.statusCode}).', status: res.statusCode);
  }

  /// Formulaire multipart dont la réponse est un fichier (PDF, zip…) : octets bruts et nom proposé par le serveur.
  Future<(List<int>, String?)> uploadForFile(String path, Map<String, dynamic> fields, List<(String, List<int>, String)> files) async {
    final req = http.MultipartRequest('POST', _uri(path))
      ..headers.addAll(Map.of(_headers)..remove('Content-Type'))
      ..headers['Accept'] = '*/*'
      ..fields.addAll({for (final e in fields.entries) if (e.value != null) e.key: '${e.value}'});
    for (final f in files) {
      req.files.add(http.MultipartFile.fromBytes(f.$1, f.$2, filename: f.$3));
    }
    final res = await http.Response.fromStream(await req.send()).timeout(const Duration(minutes: 4));
    if (res.statusCode >= 200 && res.statusCode < 300) {
      final cd = res.headers['content-disposition'] ?? '';
      final m = RegExp(r'filename="?([^";]+)"?').firstMatch(cd);
      return (res.bodyBytes as List<int>, m?.group(1));
    }
    if (res.statusCode == 401) onUnauthorized?.call();
    String msg = 'Erreur ${res.statusCode}';
    try {
      final b = jsonDecode(utf8.decode(res.bodyBytes));
      if (b is Map && b['message'] != null) msg = '${b['message']}';
    } catch (_) {}
    throw ApiException(msg, status: res.statusCode);
  }

  Future<dynamic> delete(String path, [Object? body, Map<String, String>? headers]) =>
      _send(() => http.delete(_uri(path), headers: {..._headers, ...?headers}, body: body == null ? null : jsonEncode(body)));

  /// Envoi d'un fichier (formulaire multipart).
  Future<dynamic> upload(String path, String field, List<int> bytes, String filename) => _send(() async {
        final req = http.MultipartRequest('POST', _uri(path))
          ..headers.addAll(Map.of(_headers)..remove('Content-Type'))
          ..files.add(http.MultipartFile.fromBytes(field, bytes, filename: filename));
        return http.Response.fromStream(await req.send());
      });

  /// Formulaire multipart : champs texte + un fichier.
  Future<dynamic> uploadForm(String path, Map<String, dynamic> fields, String field, List<int> bytes, String filename) => _send(() async {
        final req = http.MultipartRequest('POST', _uri(path))
          ..headers.addAll(Map.of(_headers)..remove('Content-Type'))
          ..fields.addAll({for (final e in fields.entries) if (e.value != null) e.key: '${e.value}'})
          ..files.add(http.MultipartFile.fromBytes(field, bytes, filename: filename));
        return http.Response.fromStream(await req.send());
      }, timeout: const Duration(minutes: 3));

  /// Formulaire multipart : champs texte + plusieurs fichiers (champ => (octets, nom)).
  Future<dynamic> uploadFiles(String path, Map<String, dynamic> fields, Map<String, (List<int>, String)> files) => _send(() async {
        final req = http.MultipartRequest('POST', _uri(path))
          ..headers.addAll(Map.of(_headers)..remove('Content-Type'))
          ..fields.addAll({for (final e in fields.entries) if (e.value != null) e.key: '${e.value}'});
        for (final e in files.entries) {
          req.files.add(http.MultipartFile.fromBytes(e.key, e.value.$1, filename: e.value.$2));
        }
        return http.Response.fromStream(await req.send());
      }, timeout: const Duration(minutes: 3));

  Future<dynamic> _send(Future<http.Response> Function() call, {Duration? timeout}) async {
    http.Response res;
    try {
      res = await call().timeout(timeout ?? AppConfig.requestTimeout);
    } on TimeoutException {
      throw ApiException('Le serveur ne répond pas.');
    } on http.ClientException {
      // http 1.x : les erreurs réseau (y compris SocketException) arrivent ici
      throw ApiException('Pas de connexion au serveur.');
    }

    dynamic body;
    try {
      body = res.body.isEmpty ? null : jsonDecode(utf8.decode(res.bodyBytes));
    } catch (_) {
      body = null;
    }

    if (res.statusCode >= 200 && res.statusCode < 300) return body;

    final map = body is Map<String, dynamic> ? body : <String, dynamic>{};
    if (res.statusCode == 401) onUnauthorized?.call();
    throw ApiException(
      (map['message'] as String?) ?? 'Erreur du serveur (${res.statusCode}).',
      status: res.statusCode,
      code: map['code'] as String?,
      errors: map['errors'] is Map ? Map<String, dynamic>.from(map['errors']) : null,
      body: body,
    );
  }
}
