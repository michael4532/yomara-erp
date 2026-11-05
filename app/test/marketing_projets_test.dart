import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/screens/marketing/campaigns.dart' show smsInfo;
import 'package:yomara_erp/ui/screens/marketing/email_builder.dart';
import 'package:yomara_erp/ui/screens/marketing/mk_common.dart';
import 'package:yomara_erp/ui/screens/projets_screen.dart';

void main() {
  test('variables de personnalisation et compteur de SMS', () {
    expect(fillVars('Bonjour {{prenom}} de {{ entreprise }} {{inconnue}}', 'Démo SARL'), 'Bonjour Awa de Démo SARL {{inconnue}}');
    expect(smsInfo('a' * 160), startsWith('160 caractères · 1 SMS'));
    expect(smsInfo('a' * 161), contains('2 SMS'));
    expect(smsInfo('Promo été 🎉'), contains('70 caractères'));
  });

  test('conception e-mail : chaque bloc reçoit un identifiant, le HTML n\'est envoyé qu\'en mode HTML', () {
    final d = EmailDesign.from({
      'blocks': [
        {'type': 'text', 'text': 'x'},
      ],
      'html': '',
    });
    expect(d.blocks.first['_id'], isNotNull);
    expect(d.toJson()['html'], isNull);
    d
      ..html = '<p>Hi</p>'
      ..htmlMode = true;
    expect(d.toJson()['html'], '<p>Hi</p>');
  });

  test('dates calendaires insensibles au changement d\'heure', () {
    final saturday = DateTime(2026, 10, 24);
    expect(addDays(saturday, 1).day, 25);
    expect(addDays(saturday, 2).day, 26);
    expect(daysBetween(DateTime(2026, 10, 20), DateTime(2026, 10, 27)), 7);
    expect(daysBetween(DateTime(2026, 3, 27), DateTime(2026, 3, 30)), 3);
    expect(hoursFr(1.5), '1 h 30');
    expect(hoursFr(8), '8 h');
  });

  test('indicateurs de projet calculés sur l\'appareil', () {
    final p = {'id': 'p1', 'billing_mode': 'time', 'hourly_rate': 10000, 'budget_hours': 20};
    final tasks = [
      {'id': 't1', 'project_id': 'p1', 'status': 'done', 'estimate_hours': 4},
      {'id': 't2', 'project_id': 'p1', 'status': 'doing', 'due_date': '2020-01-01', 'estimate_hours': 6},
      {'id': 't3', 'project_id': 'autre', 'status': 'todo'},
    ];
    final times = [
      {'project_id': 'p1', 'hours': 3, 'billable': true},
      {'project_id': 'p1', 'hours': 2, 'billable': false},
      {'project_id': 'p1', 'hours': 5, 'billable': true, 'invoice_id': 'f1'},
    ];
    final m = ProjectMetrics(p, tasks, times, []);
    expect(m.tasks.length, 2);
    expect(m.progress, 50);
    expect(m.late, 1);
    expect(m.hours, 10);
    expect(m.toInvoiceHours, 3);
    expect(m.toInvoiceAmount, 30000);
    expect(m.budgetUsed, .5);
  });
}
