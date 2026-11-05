import 'dart:js_interop';
import 'dart:ui_web' as ui_web;

import 'package:flutter/material.dart';
import 'package:web/web.dart' as web;

bool get visioEmbedded => true;

int _seq = 0;

/// Salle de réunion intégrée à l'application (iframe autorisée à utiliser caméra, micro et partage d'écran).
class VisioFrame extends StatefulWidget {
  const VisioFrame({super.key, required this.url, this.onLeft});

  final String url;
  final VoidCallback? onLeft;

  @override
  State<VisioFrame> createState() => _VisioFrameState();
}

class _VisioFrameState extends State<VisioFrame> {
  late final String _type = 'yomara-visio-${_seq++}';
  late final web.HTMLIFrameElement _frame;
  JSFunction? _listener;

  @override
  void initState() {
    super.initState();
    _frame = web.HTMLIFrameElement()
      ..src = widget.url
      ..allow = 'camera; microphone; display-capture; autoplay; fullscreen; clipboard-write; speaker-selection'
      ..allowFullscreen = true
      ..style.border = '0'
      ..style.width = '100%'
      ..style.height = '100%'
      ..style.background = '#0B1220';
    ui_web.platformViewRegistry.registerViewFactory(_type, (int id) => _frame);
    _listener = ((web.MessageEvent e) {
      final d = e.data.dartify();
      if (d is Map && d['type'] == 'yomara-visio' && d['event'] == 'left') widget.onLeft?.call();
    }).toJS;
    web.window.addEventListener('message', _listener);
  }

  @override
  void dispose() {
    if (_listener != null) web.window.removeEventListener('message', _listener);
    _frame.src = 'about:blank';
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => HtmlElementView(viewType: _type);
}
