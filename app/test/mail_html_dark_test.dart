import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/widgets/mail_html_dark.dart';

void main() {
  test('e-mail Outlook simple : couleurs de texte retirées en mode sombre', () {
    const outlook = '<p class=MsoNormal><b><span style="color:windowtext;font-family:Calibri">De :</span></b> '
        '<font color="#000000">Bonjour</font><span style="background-color:transparent;color:black">x</span></p>';
    final (dark, html) = mailForTheme(outlook, true);
    expect(dark, isTrue);
    expect(html.contains('windowtext'), isFalse);
    expect(html.contains('#000000'), isFalse);
    expect(html.contains('color:black'), isFalse);
    expect(html.contains('font-family:Calibri'), isTrue);
    expect(html.contains('background-color:transparent'), isTrue);
  });

  test('lettre d\'information : affichée sur fond clair, sans modification', () {
    const news = '<table bgcolor="#F4F4F4"><tr><td style="color:#333">Promo</td></tr></table>';
    expect(mailForTheme(news, true), (false, news));
    const styled = '<div style="background:#1E3A8A;color:#fff">Titre</div>';
    expect(mailForTheme(styled, true).$1, isFalse);
  });

  test('mode clair : rien ne change', () {
    const h = '<p style="color:red">x</p>';
    expect(mailForTheme(h, false), (false, h));
  });
}
