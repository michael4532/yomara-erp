import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/api_client.dart';
import 'package:yomara_erp/core/safe_launch.dart';

void main() {
  test('seuls les liens web, e-mail, téléphone et SMS s\'ouvrent', () {
    for (final ok in ['https://exemple.ci/a', 'http://exemple.ci', 'mailto:a@b.ci', 'tel:+22501020304', 'sms:+22501020304']) {
      expect(isSafeLink(Uri.parse(ok)), isTrue, reason: ok);
    }
    for (final bad in ['file:///C:/Windows/system32/calc.exe', 'javascript:alert(1)', 'data:text/html,x', 'search-ms:query=x', 'ms-settings:', 'intent://x#Intent;end', 'https:///sans-hote', '\\\\serveur\\partage\\x.lnk']) {
      expect(isSafeLink(Uri.parse(bad)), isFalse, reason: bad);
    }
  });

  test('http refusé sur internet, accepté sur le réseau local', () {
    expect(ApiClient.insecureReason('https://erp.exemple.ci'), isNull);
    for (final local in ['http://192.168.1.20', 'http://10.0.0.5:8000', 'http://172.20.1.1', 'http://localhost:8099', 'http://serveur-erp', 'http://erp.local']) {
      expect(ApiClient.insecureReason(local), isNull, reason: local);
    }
    for (final pub in ['http://erp.exemple.ci', 'http://41.207.10.10', 'http://172.32.0.1']) {
      expect(ApiClient.insecureReason(pub), isNotNull, reason: pub);
    }
  });
}
