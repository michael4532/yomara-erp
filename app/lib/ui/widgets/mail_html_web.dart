import 'dart:js_interop';
import 'dart:ui_web' as ui_web;

import 'package:flutter/material.dart';
import 'package:web/web.dart' as web;

int _seq = 0;

/// Affichage d'un e-mail HTML (navigateur) : cadre isolé sans script, fidèle à la mise en page d'origine.
class MailHtml extends StatefulWidget {
  const MailHtml({super.key, required this.html, this.dark = false});

  final String html;
  final bool dark;

  @override
  State<MailHtml> createState() => _MailHtmlState();
}

class _MailHtmlState extends State<MailHtml> {
  late final String _type = 'yomara-mail-${_seq++}';
  late final web.HTMLIFrameElement _frame;

  String _doc(String html) => '<!doctype html><html><head><meta charset="utf-8"><base target="_blank">'
      '<style>html,body{margin:0;padding:8px 6px 24px;font-family:Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.45;'
      'color:${widget.dark ? '#E8ECF3' : '#1B1F24'};background:${widget.dark ? '#151B26' : '#FFFFFF'};word-wrap:break-word}'
      'img{max-width:100%;height:auto}table{max-width:100%}blockquote{margin:6px 0 6px 4px;padding-left:10px;border-left:3px solid #C8D1E0;color:#5B6B85}'
      'a{color:#2E6BE6}pre{white-space:pre-wrap}</style></head><body>$html</body></html>';

  @override
  void initState() {
    super.initState();
    _frame = web.HTMLIFrameElement()
      ..setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox allow-same-origin')
      ..setAttribute('referrerpolicy', 'no-referrer')
      ..srcdoc = _doc(widget.html).toJS
      ..style.border = '0'
      ..style.width = '100%'
      ..style.height = '100%'
      ..style.background = 'transparent';
    ui_web.platformViewRegistry.registerViewFactory(_type, (int id) => _frame);
  }

  @override
  void didUpdateWidget(covariant MailHtml old) {
    super.didUpdateWidget(old);
    if (old.html != widget.html || old.dark != widget.dark) _frame.srcdoc = _doc(widget.html).toJS;
  }

  @override
  Widget build(BuildContext context) => HtmlElementView(viewType: _type);
}
