import 'dart:js_interop';
import 'dart:ui_web' as ui_web;

import 'package:flutter/material.dart';
import 'package:web/web.dart' as web;

bool get htmlFrameSupported => true;

int _seq = 0;

/// Aperçu HTML exact dans le navigateur (iframe isolée, scripts désactivés).
class HtmlFrame extends StatefulWidget {
  const HtmlFrame({super.key, required this.html});

  final String html;

  @override
  State<HtmlFrame> createState() => _HtmlFrameState();
}

class _HtmlFrameState extends State<HtmlFrame> {
  late final String _type = 'yomara-html-frame-${_seq++}';
  late final web.HTMLIFrameElement _frame;

  @override
  void initState() {
    super.initState();
    _frame = web.HTMLIFrameElement()
      ..style.border = '0'
      ..style.width = '100%'
      ..style.height = '100%'
      ..style.background = '#ffffff';
    _frame.sandbox.add('allow-same-origin');
    _frame.srcdoc = widget.html.toJS;
    ui_web.platformViewRegistry.registerViewFactory(_type, (int id) => _frame);
  }

  @override
  void didUpdateWidget(covariant HtmlFrame old) {
    super.didUpdateWidget(old);
    if (old.html != widget.html) _frame.srcdoc = widget.html.toJS;
  }

  @override
  Widget build(BuildContext context) => HtmlElementView(viewType: _type);
}
