import 'dart:math';

import 'local_db.dart';

/// Billetterie : codes de billets et contrôle d'accès hors ligne (mêmes règles que le serveur).

const tkKinds = {'visitor': 'Visiteur', 'vip': 'VIP', 'exhibitor': 'Exposant', 'staff': 'Organisation', 'press': 'Presse'};

final _rnd = Random.secure();
const _alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

String tkNewCode() {
  final s = List.generate(8, (_) => _alpha[_rnd.nextInt(_alpha.length)]).join();
  return '${s.substring(0, 4)}-${s.substring(4)}';
}

String tkNewToken() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  return List.generate(32, (_) => chars[_rnd.nextInt(chars.length)]).join();
}

String tkNormalize(String code) {
  final c = code.replaceAll(RegExp(r'[^A-Za-z0-9]'), '').toUpperCase();
  return c.length == 8 ? '${c.substring(0, 4)}-${c.substring(4)}' : c;
}

/// Billet correspondant à un code saisi, un jeton ou l'URL du QR code.
Map<String, dynamic>? tkFind(String code) {
  if (!LocalDb.hasEntity('ev_tickets')) return null;
  var raw = code.trim();
  final m = RegExp(r'/billet/([A-Za-z0-9]{24,64})').firstMatch(raw);
  if (m != null) raw = m.group(1)!;
  final norm = tkNormalize(raw);
  for (final t in LocalDb.all('ev_tickets')) {
    if (t['token'] == raw || t['code'] == norm) return t;
  }
  return null;
}

class TkResult {
  const TkResult(this.ticket, this.result, this.reason);
  final Map<String, dynamic>? ticket;
  final String result; // ok, duplicate, refused
  final String? reason;
  bool get ok => result == 'ok';
}

String _d(dynamic v) => '${v ?? ''}'.length >= 10 ? '$v'.substring(0, 10) : '${v ?? ''}';

String _fr(String iso) => iso.length >= 10 ? '${iso.substring(8, 10)}/${iso.substring(5, 7)}/${iso.substring(0, 4)}' : iso;

/// Évaluation locale d'un passage (le serveur réévalue à la synchronisation).
TkResult tkEvaluate(String eventId, String code, {DateTime? at}) {
  final now = at ?? DateTime.now();
  final t = tkFind(code);
  if (t == null) return const TkResult(null, 'refused', 'Billet inconnu');
  if (t['event_id'] != eventId) return TkResult(t, 'refused', 'Billet d\'un autre événement');
  if (t['status'] != 'valid') return TkResult(t, 'refused', 'Billet annulé');
  final day = now.toIso8601String().substring(0, 10);
  final from = _d(t['valid_from']), to = _d(t['valid_to']);
  if ((from.isNotEmpty && day.compareTo(from) < 0) || (to.isNotEmpty && day.compareTo(to) > 0)) {
    return TkResult(t, 'refused', 'Valable du ${_fr(from)} au ${_fr(to)}');
  }
  if (t['reentry'] != true && LocalDb.hasEntity('ev_checkins')) {
    final prev = LocalDb.all('ev_checkins')
        .where((c) => c['ticket_id'] == t['id'] && c['result'] == 'ok' && '${c['scanned_at'] ?? ''}'.startsWith(day))
        .map((c) => DateTime.tryParse('${c['scanned_at']}')?.toLocal())
        .whereType<DateTime>()
        .toList()
      ..sort();
    if (prev.isNotEmpty) {
      final h = prev.first;
      return TkResult(t, 'duplicate', 'Déjà entré aujourd\'hui à ${h.hour.toString().padLeft(2, '0')}:${h.minute.toString().padLeft(2, '0')}');
    }
  }
  return TkResult(t, 'ok', null);
}

/// Places restantes d'un tarif (billets émis localement).
int? tkLeft(Map<String, dynamic> type) {
  final q = type['quantity'];
  if (q == null) return null;
  final sold = LocalDb.hasEntity('ev_tickets') ? LocalDb.all('ev_tickets').where((t) => t['type_id'] == type['id'] && t['status'] == 'valid').length : 0;
  final n = (q is num ? q.toInt() : int.tryParse('$q') ?? 0) - sold;
  return n < 0 ? 0 : n;
}

/// Visiteur déjà inscrit à l'événement, retrouvé par son QR code, son code de billet, son e-mail ou son téléphone
/// (8 derniers chiffres : « 07 07 07 07 01 » = « +225 0707070701 »). Billets visiteurs valides seulement.
Map<String, dynamic>? tkFindVisitor(String eventId, String query) {
  final raw = query.trim();
  if (raw.isEmpty || !LocalDb.hasEntity('ev_tickets')) return null;
  final byCode = tkFind(raw);
  if (byCode != null && byCode['event_id'] == eventId) return byCode;
  final email = raw.contains('@') ? raw.toLowerCase() : null;
  final digits = raw.replaceAll(RegExp(r'\D'), '');
  if (email == null && digits.length < 8) return null;
  final last8 = digits.length >= 8 ? digits.substring(digits.length - 8) : '';
  for (final t in LocalDb.all('ev_tickets')) {
    if (t['event_id'] != eventId || t['status'] != 'valid') continue;
    if (email != null && '${t['holder_email'] ?? ''}'.trim().toLowerCase() == email) return t;
    if (email == null) {
      final p = '${t['holder_phone'] ?? ''}'.replaceAll(RegExp(r'\D'), '');
      if (p.length >= 8 && p.substring(p.length - 8) == last8) return t;
    }
  }
  return null;
}
