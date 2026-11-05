import 'package:flutter_test/flutter_test.dart';
import 'package:flutter/material.dart';
import 'package:yomara_erp/ui/screens/feuilles_screen.dart';
import 'package:yomara_erp/ui/screens/fournisseurs_screen.dart';
import 'package:yomara_erp/ui/screens/helpdesk_screen.dart';
import 'package:yomara_erp/ui/theme.dart';

void main() {
  test('délais des tickets', () {
    final now = DateTime(2027, 3, 1, 17, 0);
    String iso(DateTime d) => d.toUtc().toIso8601String();
    expect(hdDuration(const Duration(minutes: 25)), '25 min');
    expect(hdDuration(const Duration(minutes: 190)), '3 h 10');
    expect(hdDuration(const Duration(hours: 50)), '2 j');
    final t = {'status': 'open', 'first_response_due': iso(now.add(const Duration(minutes: 20))), 'resolution_due': iso(now.add(const Duration(hours: 5)))};
    expect(hdSla(t, now: now), ('Répondre sous 20 min', YColors.warning));
    expect(hdSla({...t, 'first_response_at': iso(now)}, now: now), ('À résoudre sous 5 h', YColors.muted));
    expect(hdSla({...t, 'first_response_due': iso(now.subtract(const Duration(minutes: 90)))}, now: now)!.$1, 'Réponse en retard de 1 h 30');
    expect(hdSla({...t, 'status': 'pending'}, now: now)!.$1, 'Délai suspendu');
    expect(hdSla({...t, 'status': 'resolved'}, now: now), isNull);
    expect(hdSla({...t, 'status': 'closed', 'sla_resolve_breached': true}, now: now)!.$2, YColors.danger);
  });

  test('semaine des feuilles de temps', () {
    expect(ttMonday(DateTime(2027, 3, 10)), DateTime(2027, 3, 8));
    expect(ttMonday(DateTime(2026, 10, 25)), DateTime(2026, 10, 19));
    expect(ttMonday(DateTime(2026, 10, 26)), DateTime(2026, 10, 26));
    expect(ttH(7.5), '7,5 h');
    expect(ttH(8), '8 h');
  });

  test('pièces jointes et appels d\'offres', () {
    expect(hdSize(2048), '2 Ko');
    expect(hdSize(3 * 1048576 + 400000), '3,4 Mo');
    expect(hdFileIcon('image/png', 'x'), Icons.image_outlined);
    expect(hdFileIcon(null, 'facture.PDF'), Icons.picture_as_pdf_outlined);
    expect(hdFileIcon('application/zip', 'a.zip'), Icons.attach_file);
    expect(spTenderColor('closed'), YColors.warning);
    expect(spTenderStatuses['awarded'], 'Attribué');
  });
}
