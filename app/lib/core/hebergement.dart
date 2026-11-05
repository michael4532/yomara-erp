import 'local_db.dart';

/// Hébergement, domaines & licences : états et indicateurs calculés hors ligne (mêmes règles que le serveur).

const hbTypes = {
  'domain': 'Nom de domaine',
  'hosting': 'Hébergement',
  'ssl': 'Certificat SSL',
  'email': 'E-mail professionnel',
  'maintenance': 'Maintenance',
  'license': 'Licence logicielle',
  'cloud': 'Serveur / cloud',
  'other': 'Autre service',
};

double _d(dynamic v) => v is num ? v.toDouble() : double.tryParse('${v ?? ''}'.replaceAll(',', '.')) ?? 0;

DateTime _today([DateTime? now]) {
  final n = now ?? DateTime.now();
  return DateTime(n.year, n.month, n.day);
}

int hbDaysLeft(Map<String, dynamic> s, [DateTime? now]) {
  final e = DateTime.tryParse('${s['expiry_date']}');
  if (e == null) return 0;
  final t = _today(now);
  // En UTC : un changement d'heure (heure d'été) ne doit pas faire perdre un jour
  return DateTime.utc(e.year, e.month, e.day).difference(DateTime.utc(t.year, t.month, t.day)).inDays;
}

/// ok, expiring, expired, suspended, cancelled
String hbState(Map<String, dynamic> s, [DateTime? now]) {
  if (s['status'] != null && s['status'] != 'active') return '${s['status']}';
  final d = hbDaysLeft(s, now);
  if (d < 0) return 'expired';
  final before = _d(s['renew_days_before'] ?? 30).toInt();
  return d <= (before > 30 ? before : 30) ? 'expiring' : 'ok';
}

double hbMonthly(Map<String, dynamic> s) => _d(s['sale_price']) / (_d(s['period_months']) <= 0 ? 1 : _d(s['period_months']));
double hbMonthlyCost(Map<String, dynamic> s) => _d(s['cost_price']) / (_d(s['period_months']) <= 0 ? 1 : _d(s['period_months']));

String hbPeriod(dynamic months) {
  final m = _d(months).toInt();
  if (m <= 0) return '';
  if (m % 12 == 0) return m == 12 ? 'an' : '${m ~/ 12} ans';
  return m == 1 ? 'mois' : '$m mois';
}

class HbStats {
  HbStats(this.active, this.expiring, this.expired, this.mrr, this.margin, this.providerTodo, this.unpaid);
  final int active, expiring, expired, providerTodo, unpaid;
  final double mrr, margin;
}

HbStats hbStats([DateTime? now]) {
  final all = LocalDb.hasEntity('hb_services') ? LocalDb.all('hb_services').where((s) => s['status'] == 'active').toList() : <Map<String, dynamic>>[];
  var expiring = 0, expired = 0, todo = 0, unpaid = 0;
  var mrr = 0.0, cost = 0.0;
  for (final s in all) {
    final d = hbDaysLeft(s, now);
    if (d < 0) expired++;
    if (d >= 0 && d <= 30) expiring++;
    mrr += hbMonthly(s);
    cost += hbMonthlyCost(s);
    if (s['provider_todo'] != null) todo++;
    if (s['pending'] != null) unpaid++;
  }
  return HbStats(all.length, expiring, expired, mrr, mrr - cost, todo, unpaid);
}
