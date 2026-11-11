import 'dart:js_interop';
import 'dart:ui_web' as ui_web;

import 'package:flutter/material.dart';
import 'package:web/web.dart' as web;

import 'auto_update.dart';
import 'popup_guard.dart';

/// L'éditeur Word / Excel s'affiche dans l'application (cadre intégré).
bool get officeEmbedded => true;

int _seq = 0;

class OfficeFrame extends StatefulWidget {
  const OfficeFrame({super.key, required this.url, this.onClose, this.onSaved});

  final String url;
  final VoidCallback? onClose;
  final VoidCallback? onSaved;

  @override
  State<OfficeFrame> createState() => _OfficeFrameState();
}

class _OfficeFrameState extends State<OfficeFrame> {
  late final String _type = 'yomara-office-${_seq++}';
  late final web.HTMLIFrameElement _frame;
  JSFunction? _listener;

  @override
  void initState() {
    super.initState();
    AutoUpdate.hold.value++; // pas de rechargement automatique pendant la modification
    _frame = web.HTMLIFrameElement()
      ..src = widget.url
      ..allow = 'clipboard-read; clipboard-write; fullscreen'
      ..style.border = '0'
      ..style.width = '100%'
      ..style.height = '100%'
      ..style.background = '#F3F5F9';
    ui_web.platformViewRegistry.registerViewFactory(_type, (int id) => _frame);
    popupDepth.addListener(_guard);
    _guard();
    _listener = ((web.MessageEvent e) {
      final d = e.data.dartify();
      if (d is Map && d['type'] == 'yomara-office') {
        if (d['event'] == 'close') widget.onClose?.call();
        if (d['event'] == 'saved') widget.onSaved?.call();
      }
    }).toJS;
    web.window.addEventListener('message', _listener);
  }

  @override
  void dispose() {
    AutoUpdate.hold.value--;
    popupDepth.removeListener(_guard);
    if (_listener != null) web.window.removeEventListener('message', _listener);
    _frame.src = 'about:blank';
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => HtmlElementView(viewType: _type);

  void _guard() => _frame.style.pointerEvents = popupDepth.value > 0 ? 'none' : 'auto';
}
