import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/deep_links.dart';

void main() {
  test('yomara://mail ouvre YoMail, yomara://ecran/<id> un écran', () {
    expect(DeepLinks.screenOf(Uri.parse('yomara://mail')), 'mailbox');
    expect(DeepLinks.screenOf(Uri.parse('yomara://ecran/stock')), 'stock');
    expect(DeepLinks.screenOf(Uri.parse('yomara://ecran/../../x')), isNull);
    expect(DeepLinks.screenOf(Uri.parse('https://mail')), isNull);
    expect(DeepLinks.screenOf(Uri.parse('yomara://visio/abc-defg-hij')), isNull);
  });
}
