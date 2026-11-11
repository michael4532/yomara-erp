import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/amount_words.dart';

void main() {
  test('montants en lettres', () {
    expect(amountInWords(64900, 'XOF'), 'soixante-quatre mille neuf cents francs CFA');
    expect(amountInWords(1, 'XOF'), 'un franc CFA');
    expect(amountInWords(71, 'XOF'), 'soixante et onze francs CFA');
    expect(amountInWords(80, 'XOF'), 'quatre-vingts francs CFA');
    expect(amountInWords(81, 'XOF'), 'quatre-vingt-un francs CFA');
    expect(amountInWords(200, 'XOF'), 'deux cents francs CFA');
    expect(amountInWords(280000, 'XOF'), 'deux cent quatre-vingt mille francs CFA');
    expect(amountInWords(1000, 'XOF'), 'mille francs CFA');
    expect(amountInWords(2000000, 'XOF'), 'deux millions de francs CFA');
    expect(amountInWords(1250021, 'XOF'), 'un million deux cent cinquante mille vingt et un francs CFA');
    expect(amountInWords(12.5, 'EUR'), 'douze euros et cinquante centimes');
  });
}
