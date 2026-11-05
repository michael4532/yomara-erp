import 'local_db.dart';

/// Événements & salons : calculs hors ligne (statut des emplacements, montants, remplissage) — mêmes règles que le serveur.

double _d(dynamic v) => v is num ? v.toDouble() : double.tryParse('${v ?? ''}'.replaceAll(',', '.')) ?? 0;

const evActive = {'option', 'confirmed'};

const evBookingLabels = {'option': 'Option', 'confirmed': 'Confirmée', 'cancelled': 'Annulée', 'expired': 'Option expirée'};

const evEventLabels = {'draft': 'Préparation', 'open': 'Ventes ouvertes', 'closed': 'Ventes closes', 'done': 'Terminé', 'cancelled': 'Annulé'};

List<Map<String, dynamic>> evSpaces(String eventId) => LocalDb.hasEntity('ev_spaces')
    ? (LocalDb.all('ev_spaces').where((s) => s['event_id'] == eventId).toList()..sort((a, b) => _natural('${a['code']}', '${b['code']}')))
    : [];

List<Map<String, dynamic>> evBookings(String eventId) => LocalDb.hasEntity('ev_bookings') ? LocalDb.all('ev_bookings').where((b) => b['event_id'] == eventId).toList() : [];

List<Map<String, dynamic>> evPackages(String eventId) => LocalDb.hasEntity('ev_packages')
    ? (LocalDb.all('ev_packages').where((p) => p['event_id'] == eventId).toList()..sort((a, b) => _d(a['sort']).compareTo(_d(b['sort'])) != 0 ? _d(a['sort']).compareTo(_d(b['sort'])) : '${a['name']}'.compareTo('${b['name']}')))
    : [];

int _natural(String a, String b) {
  final ra = RegExp(r'^(\D*)(\d+)$').firstMatch(a);
  final rb = RegExp(r'^(\D*)(\d+)$').firstMatch(b);
  if (ra != null && rb != null && ra.group(1) == rb.group(1)) return int.parse(ra.group(2)!).compareTo(int.parse(rb.group(2)!));
  return a.compareTo(b);
}

/// Réservation active qui occupe chaque emplacement.
Map<String, Map<String, dynamic>> evOccupancy(String eventId) {
  final out = <String, Map<String, dynamic>>{};
  for (final b in evBookings(eventId).where((b) => evActive.contains(b['status']))) {
    for (final sid in (b['space_ids'] as List? ?? const [])) {
      out['$sid'] = b;
    }
  }
  return out;
}

/// free, option, confirmed, blocked
String evSpaceStatus(Map<String, dynamic> space, Map<String, Map<String, dynamic>> occ) {
  final b = occ['${space['id']}'];
  if (b != null) return '${b['status']}';
  return space['blocked'] == true ? 'blocked' : 'free';
}

/// État affiché sur le plan : libre, bloqué, option, réservé (à encaisser), acompte payé, payé.
String evPlanState(Map<String, dynamic> space, Map<String, Map<String, dynamic>> occ) {
  final st = evSpaceStatus(space, occ);
  if (st != 'confirmed') return st;
  return switch ('${occ['${space['id']}']?['payment_status'] ?? ''}') { 'paid' => 'paid', 'partial' => 'partial', _ => 'confirmed' };
}

class EvAmounts {
  const EvAmounts(this.subtotal, this.totalHt, this.tax, this.total, this.area);
  final double subtotal, totalHt, tax, total, area;
}

EvAmounts evAmounts(Map<String, dynamic> booking, Map<String, dynamic>? event) {
  final ids = {for (final s in (booking['space_ids'] as List? ?? const [])) '$s'};
  final spaces = LocalDb.hasEntity('ev_spaces') ? ids.map((id) => LocalDb.get('ev_spaces', id)).whereType<Map<String, dynamic>>() : const <Map<String, dynamic>>[];
  var sub = spaces.fold<double>(0, (s, x) => s + _d(x['price']));
  for (final it in (booking['items'] as List? ?? const [])) {
    final m = it as Map;
    final price = m['unit_price'] ?? LocalDb.get('ev_packages', '${m['package_id']}')?['price'];
    sub += _d(m['quantity']) * _d(price);
  }
  final ht = _round(sub * (1 - _d(booking['discount_percent']) / 100));
  final tax = _round(ht * _d(event?['tax_rate']) / 100);
  return EvAmounts(_round(sub), ht, tax, _round(ht + tax), spaces.fold<double>(0, (s, x) => s + _d(x['area'])));
}

double _round(double v) => (v * 100).roundToDouble() / 100;

/// Quantité réservée d'une offre (options et confirmées), hors réservation [except].
int evPackageSold(Map<String, dynamic> pkg, {String? except}) {
  var n = 0;
  for (final b in evBookings('${pkg['event_id']}').where((b) => evActive.contains(b['status']) && b['id'] != except)) {
    for (final it in (b['items'] as List? ?? const [])) {
      if ((it as Map)['package_id'] == pkg['id']) n += _d(it['quantity']).toInt();
    }
  }
  return n;
}

class EvStats {
  EvStats({required this.sellable, required this.confirmed, required this.option, required this.blocked, required this.revenueConfirmed, required this.revenueOptions, required this.area, required this.areaTaken});
  final int sellable, confirmed, option, blocked;
  final double revenueConfirmed, revenueOptions, area, areaTaken;
  int get free => sellable - confirmed - option;
  double get occupancy => sellable == 0 ? 0 : 100 * (confirmed + option) / sellable;
}

EvStats evStats(Map<String, dynamic> event) {
  final id = '${event['id']}';
  final spaces = evSpaces(id);
  final occ = evOccupancy(id);
  var confirmed = 0, option = 0, blocked = 0;
  var area = 0.0, areaTaken = 0.0;
  for (final s in spaces) {
    final st = evSpaceStatus(s, occ);
    if (st == 'blocked') {
      blocked++;
      continue;
    }
    area += _d(s['area']);
    if (st == 'confirmed') confirmed++;
    if (st == 'option') option++;
    if (st != 'free') areaTaken += _d(s['area']);
  }
  var rc = 0.0, ro = 0.0;
  for (final b in evBookings(id)) {
    if (b['status'] == 'confirmed') rc += evAmounts(b, event).totalHt;
    if (b['status'] == 'option') ro += evAmounts(b, event).totalHt;
  }
  return EvStats(sellable: spaces.length - blocked, confirmed: confirmed, option: option, blocked: blocked, revenueConfirmed: rc, revenueOptions: ro, area: area, areaTaken: areaTaken);
}

/// Prochaine rangée libre du plan (pour placer un nouvel emplacement).
(int, int) evFreeCell(Map<String, dynamic> event, int w, int h) {
  final cols = _d(event['plan_cols']).toInt(), rows = _d(event['plan_rows']).toInt();
  final spaces = evSpaces('${event['id']}');
  bool fits(int x, int y) {
    if (x + w > cols || y + h > rows) return false;
    for (final o in spaces) {
      final ox = _d(o['x']).toInt(), oy = _d(o['y']).toInt(), ow = _d(o['w']).toInt(), oh = _d(o['h']).toInt();
      if (x < ox + ow && ox < x + w && y < oy + oh && oy < y + h) return false;
    }
    return true;
  }

  for (var y = 0; y < rows; y++) {
    for (var x = 0; x < cols; x++) {
      if (fits(x, y)) return (x, y);
    }
  }
  return (0, 0);
}
