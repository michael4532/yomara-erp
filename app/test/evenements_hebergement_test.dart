import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/evenements.dart';
import 'package:yomara_erp/core/hebergement.dart';
import 'package:yomara_erp/core/local_db.dart';

void main() {
  String day(int offset) {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day + offset).toIso8601String().substring(0, 10); // calendaire : insensible au changement d'heure
  }

  setUp(() async {
    final dir = await Directory.systemTemp.createTemp('yomara_ev');
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('t${DateTime.now().microsecondsSinceEpoch}', ['ev_events', 'ev_spaces', 'ev_packages', 'ev_bookings', 'hb_services']);
    await LocalDb.put('ev_events', {'id': 'e1', 'name': 'Salon', 'plan_cols': 10, 'plan_rows': 6, 'tax_rate': 18});
    await LocalDb.putMany('ev_spaces', [
      {'id': 's10', 'event_id': 'e1', 'code': 'A10', 'price': 100000, 'area': 9, 'x': 0, 'y': 3, 'w': 2, 'h': 2},
      {'id': 's2', 'event_id': 'e1', 'code': 'A2', 'price': 100000, 'area': 9, 'x': 0, 'y': 0, 'w': 2, 'h': 2},
      {'id': 's3', 'event_id': 'e1', 'code': 'A3', 'price': 200000, 'area': 18, 'x': 2, 'y': 0, 'w': 3, 'h': 2},
      {'id': 'sc', 'event_id': 'e1', 'code': 'SCENE', 'x': 6, 'y': 0, 'w': 4, 'h': 3, 'blocked': true},
    ]);
    await LocalDb.putMany('ev_packages', [
      {'id': 'gold', 'event_id': 'e1', 'name': 'Sponsor Or', 'price': 500000, 'quantity': 2},
    ]);
    await LocalDb.putMany('ev_bookings', [
      {'id': 'b1', 'event_id': 'e1', 'status': 'confirmed', 'space_ids': ['s2', 's3'], 'items': [{'package_id': 'gold', 'quantity': 1, 'unit_price': 450000}], 'discount_percent': 10},
      {'id': 'b2', 'event_id': 'e1', 'status': 'option', 'space_ids': ['s10']},
      {'id': 'b3', 'event_id': 'e1', 'status': 'cancelled', 'space_ids': ['s10'], 'items': [{'package_id': 'gold', 'quantity': 1}]},
    ]);
    await LocalDb.putMany('hb_services', [
      {'id': 'h1', 'status': 'active', 'type': 'domain', 'name': 'a.ci', 'sale_price': 24000, 'cost_price': 12000, 'period_months': 12, 'expiry_date': day(10), 'renew_days_before': 30, 'pending': {'number': 'F1'}},
      {'id': 'h2', 'status': 'active', 'type': 'hosting', 'name': 'b', 'sale_price': 30000, 'cost_price': 10000, 'period_months': 1, 'expiry_date': day(-2), 'provider_todo': {'period_end': day(30)}},
      {'id': 'h3', 'status': 'active', 'type': 'license', 'name': 'c', 'sale_price': 120000, 'period_months': 12, 'expiry_date': day(200), 'renew_days_before': 45},
      {'id': 'h4', 'status': 'cancelled', 'type': 'ssl', 'name': 'd', 'sale_price': 9999, 'expiry_date': day(5)},
    ]);
  });

  tearDown(() => LocalDb.closeTenant());

  test('événements : tri naturel, statuts, montants, offres vendues, remplissage', () {
    expect(evSpaces('e1').map((s) => s['code']), ['A2', 'A3', 'A10', 'SCENE']);
    final occ = evOccupancy('e1');
    expect([for (final s in evSpaces('e1')) evSpaceStatus(s, occ)], ['confirmed', 'confirmed', 'option', 'blocked']);
    final a = evAmounts(LocalDb.get('ev_bookings', 'b1')!, LocalDb.get('ev_events', 'e1'));
    expect([a.subtotal, a.totalHt, a.tax, a.total, a.area], [750000, 675000, 121500, 796500, 27]);
    // Prix de l'offre repris quand la ligne n'a pas de prix
    expect(evAmounts({'space_ids': [], 'items': [{'package_id': 'gold', 'quantity': 2}]}, LocalDb.get('ev_events', 'e1')).totalHt, 1000000);
    expect(evPackageSold(LocalDb.get('ev_packages', 'gold')!), 1); // annulée ignorée
    expect(evPackageSold(LocalDb.get('ev_packages', 'gold')!, except: 'b1'), 0);
    final st = evStats(LocalDb.get('ev_events', 'e1')!);
    expect([st.sellable, st.confirmed, st.option, st.free, st.blocked], [3, 2, 1, 0, 1]);
    expect(st.occupancy, 100);
    expect([st.revenueConfirmed, st.revenueOptions, st.area, st.areaTaken], [675000, 100000, 36, 36]);
    // Case libre : à droite de A3, avant la scène
    expect(evFreeCell(LocalDb.get('ev_events', 'e1')!, 1, 2), (5, 0));
    expect(evFreeCell(LocalDb.get('ev_events', 'e1')!, 3, 3), (2, 2));
  });

  test('hébergement : jours restants, états, périodes, indicateurs', () {
    final h = {for (final s in LocalDb.all('hb_services')) '${s['id']}': s};
    expect([hbDaysLeft(h['h1']!), hbDaysLeft(h['h2']!)], [10, -2]);
    expect([hbState(h['h1']!), hbState(h['h2']!), hbState(h['h3']!), hbState(h['h4']!)], ['expiring', 'expired', 'ok', 'cancelled']);
    expect(hbState({'status': 'active', 'expiry_date': day(40), 'renew_days_before': 45}), 'expiring');
    expect([hbPeriod(12), hbPeriod(24), hbPeriod(1), hbPeriod(3)], ['an', '2 ans', 'mois', '3 mois']);
    final st = hbStats();
    expect([st.active, st.expiring, st.expired, st.providerTodo, st.unpaid], [3, 1, 1, 1, 1]);
    expect(st.mrr, 2000 + 30000 + 10000);
    expect(st.margin, 42000 - 1000 - 10000);
  });
}
