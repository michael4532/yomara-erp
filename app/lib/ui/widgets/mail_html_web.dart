import 'dart:async';
import 'dart:js_interop';
import 'dart:ui_web' as ui_web;

import 'package:flutter/material.dart';
import 'package:web/web.dart' as web;

import 'mail_html_dark.dart';
import 'popup_guard.dart';

int _seq = 0;

/// Affichage d'un e-mail HTML (navigateur) : cadre isolé sans script, fidèle à la mise en page d'origine.
/// Avec [scroll] : le cadre prend la hauteur de son contenu et fait défiler la page qui le contient
/// (lecture d'un seul tenant, comme Mail sur iPhone), au lieu d'une petite zone qui défile à part.
class MailHtml extends StatefulWidget {
  const MailHtml({super.key, required this.html, this.dark = false, this.scroll});

  final String html;
  final bool dark;
  final ScrollController? scroll;

  @override
  State<MailHtml> createState() => _MailHtmlState();
}

class _MailHtmlState extends State<MailHtml> {
  late final String _type = 'yomara-mail-${_seq++}';
  late final web.HTMLIFrameElement _frame;
  double _height = 240;
  Timer? _measure;
  int _ticks = 0;
  JSFunction? _wheel;
  JSFunction? _touchStart;
  JSFunction? _touchMove;
  double _touchY = 0;

  String _doc(String source) {
    // Mode sombre : e-mail simple en texte clair, e-mail mis en page sur fond clair (voir mail_html_dark.dart)
    final (dark, html) = mailForTheme(source, widget.dark);
    final auto = widget.scroll != null;
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base target="_blank">'
        '<style>html,body{margin:0;padding:${auto ? '4px 2px 12px' : '8px 6px 24px'};font-family:Segoe UI,Roboto,Arial,sans-serif;font-size:15px;line-height:1.55;'
        'color:${dark ? '#E8ECF3' : '#1B1F24'};background:${dark ? '#151B26' : '#FFFFFF'};word-wrap:break-word;overflow-wrap:anywhere}'
        '${auto ? 'html,body{overflow:hidden}' : ''}'
        '${widget.dark && !dark ? 'html{border-radius:10px}' : ''}'
        'img{max-width:100%;height:auto}table{max-width:100%}'
        'blockquote{margin:6px 0 6px 4px;padding-left:10px;border-left:3px solid ${dark ? '#3A4558' : '#C8D1E0'};color:${dark ? '#AEB8C8' : '#5B6B85'}}'
        'hr{border:0;border-top:1px solid ${dark ? '#3A4558' : '#E1E5EE'}}'
        'a{color:${dark ? '#7FA8FF' : '#2E6BE6'}}pre{white-space:pre-wrap}</style></head><body>$html</body></html>';
  }

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
    if (widget.scroll != null) {
      _frame.addEventListener('load', ((web.Event _) => _onLoad()).toJS);
    }
    ui_web.platformViewRegistry.registerViewFactory(_type, (int id) => _frame);
    popupDepth.addListener(_guard);
    _guard();
  }

  /// Contenu chargé : hauteur réelle (remesurée pendant le chargement des images) et défilement relayé à la page.
  void _onLoad() {
    _ticks = 0;
    _measure?.cancel();
    _fit();
    _measure = Timer.periodic(const Duration(milliseconds: 350), (t) {
      _fit();
      if (++_ticks > 20) t.cancel();
    });
    final win = _frame.contentWindow;
    if (win == null) return;
    _wheel ??= ((web.WheelEvent e) {
      final s = widget.scroll;
      if (s == null || !s.hasClients) return;
      s.jumpTo((s.offset + e.deltaY).clamp(0.0, s.position.maxScrollExtent));
      e.preventDefault();
    }).toJS;
    _touchStart ??= ((web.TouchEvent e) {
      if (e.touches.length > 0) _touchY = e.touches.item(0)!.clientY.toDouble();
    }).toJS;
    _touchMove ??= ((web.TouchEvent e) {
      final s = widget.scroll;
      if (s == null || !s.hasClients || e.touches.length == 0) return;
      final y = e.touches.item(0)!.clientY.toDouble();
      s.jumpTo((s.offset + (_touchY - y)).clamp(0.0, s.position.maxScrollExtent));
      _touchY = y;
    }).toJS;
    win.addEventListener('wheel', _wheel, web.AddEventListenerOptions(passive: false));
    win.addEventListener('touchstart', _touchStart, web.AddEventListenerOptions(passive: true));
    win.addEventListener('touchmove', _touchMove, web.AddEventListenerOptions(passive: true));
  }

  void _fit() {
    final doc = _frame.contentDocument;
    final h = doc?.documentElement?.scrollHeight.toDouble();
    if (h == null || h <= 0 || !mounted) return;
    final next = h.clamp(80.0, 30000.0);
    if ((next - _height).abs() > 2) setState(() => _height = next);
  }

  @override
  void didUpdateWidget(covariant MailHtml old) {
    super.didUpdateWidget(old);
    if (old.html != widget.html || old.dark != widget.dark) {
      _frame.srcdoc = _doc(widget.html).toJS;
    }
  }

  @override
  void dispose() {
    _measure?.cancel();
    popupDepth.removeListener(_guard);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final view = HtmlElementView(viewType: _type);
    return widget.scroll == null ? view : SizedBox(height: _height, child: view);
  }

  // Un panneau ou une fenêtre recouvre le cadre : il ne doit pas recevoir les clics destinés au panneau
  void _guard() => _frame.style.pointerEvents = popupDepth.value > 0 ? 'none' : 'auto';
}
