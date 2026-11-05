import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/screens/planning_screen.dart';
import 'package:yomara_erp/ui/screens/prestations_screen.dart';
import 'package:yomara_erp/ui/theme.dart';

void main() {
  test('occupation : couleurs et charge d\'une personne', () {
    expect(plLoadColor(null), YColors.muted);
    expect(plLoadColor(60), YColors.success);
    expect(plLoadColor(90), YColors.warning);
    expect(plLoadColor(120), YColors.danger);
    final p = PlPerson(1, 'Awa')
      ..days['2027-03-01'] = (cap: 8, planned: 10, actual: 7, off: null)
      ..days['2027-03-02'] = (cap: 8, planned: 6, actual: 0, off: null)
      ..days['2027-03-03'] = (cap: 0, planned: 0, actual: 0, off: 'Congé');
    expect([p.capacity, p.planned, p.actual, p.occupation, p.overloadDays], [16, 16, 7, 100, 1]);
    expect(plHex('#8E44AD').toARGB32(), 0xFF8E44AD);
  });

  test('libellé du mois des rapports', () {
    expect(prMonthLabel('2026-09'), 'septembre 2026');
    expect(prMonthLabel('2027-01'), 'janvier 2027');
  });
}
