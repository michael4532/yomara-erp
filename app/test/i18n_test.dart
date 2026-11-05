import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/i18n/i18n.dart';

void main() {
  tearDown(() => I18n.load({}));

  test('textes exacts et modèles à valeurs variables', () {
    I18n.load({'Brouillon': 'Draft', '{} fiche(s)': '{} record(s)', 'Supprimer « {} » ?': 'Delete "{}"?', 'Facture': 'Invoice', 'Note : {}': 'Note: {}', '{}': '{}', '{} {}': '{} {}'});
    expect(tr('Brouillon'), 'Draft');
    expect(tr('12 fiche(s)'), '12 record(s)');
    expect(tr('Supprimer « Hilux » ?'), 'Delete "Hilux"?');
    expect(tr('Note : Brouillon'), 'Note: Draft'); // les parties variables sont traduites aussi
    expect(tr('Hilux commerciale'), 'Hilux commerciale'); // les modèles sans texte fixe sont ignorés
    expect(tr('Texte inconnu'), 'Texte inconnu');
    expect(trn(null), isNull);
    I18n.load({'Bonsoir': 'Good evening', 'sur 1 an': 'over 1 year', 'Espaces de travail': 'Workspaces', 'Le': 'The'});
    expect(tr('Bonsoir Awa,'), 'Good evening Awa,');
    expect(tr('+37,9 % sur 1 an'), '+37,9 % over 1 year');
    expect(tr('ESPACES DE TRAVAIL'), 'WORKSPACES');
    expect(tr('Restaurant Le Baobab'), 'Restaurant Le Baobab'); // mots courts jamais remplacés dans un texte
  });

  test('le dictionnaire anglais couvre les textes de l\'application', () {
    final f = File('assets/i18n/en.json');
    final dict = (jsonDecode(f.readAsStringSync()) as Map).cast<String, String>();
    expect(dict.length, greaterThan(10000));
    for (final e in dict.entries) {
      expect('{}'.allMatches(e.value).length, '{}'.allMatches(e.key).length, reason: e.key);
    }
    I18n.load(dict);
    expect(tr('Enregistrer'), isNot('Enregistrer'));
    expect(tr('Nouveau contrat'), isNot('Nouveau contrat'));
  });
}
