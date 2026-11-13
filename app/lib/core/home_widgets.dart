import 'package:flutter/foundation.dart';
import 'package:home_widget/home_widget.dart';

import 'local_db.dart';

/// Widgets d'écran d'accueil (Android et iPhone/iPad) : chiffres du jour calculés sur l'appareil après chaque
/// synchronisation, lisibles même sans Internet. Sur les autres plateformes, rien n'est fait.
class HomeWidgets {
  HomeWidgets._();

  /// Groupe d'applications partagé avec l'extension iOS (même valeur dans les entitlements).
  static const appGroup = 'group.com.yomaratechnologies.yomaraerp';
  static const androidProvider = 'YomaraWidgetProvider';
  static const iosKind = 'YomaraWidget';

  static bool get supported => !kIsWeb && (defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS);

  /// Nom de la société affiché en tête du widget (renseigné à l'ouverture de session).
  static String company = '';

  static double _d(dynamic v) => v is num ? v.toDouble() : double.tryParse('${v ?? ''}'.replaceAll(' ', '').replaceAll(',', '.')) ?? 0;

  static String _money(double v) {
    final s = v.round().abs().toString();
    final b = StringBuffer();
    for (var i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 == 0) b.write(' ');
      b.write(s[i]);
    }
    return '${v < 0 ? '-' : ''}$b F';
  }

  /// Ventes facturées aujourd'hui, reste à encaisser, factures en retard.
  static Map<String, String> snapshot([DateTime? now]) {
    now ??= DateTime.now();
    final today = now.toIso8601String().substring(0, 10);
    if (!LocalDb.isOpen || !LocalDb.hasEntity('sales_documents')) {
      return {'company': company, 'sales_today': '—', 'to_collect': '—', 'overdue': '—', 'invoices_today': '0', 'updated': _hm(now), 'allowed': 'false'};
    }
    var sales = 0.0, toCollect = 0.0;
    var overdue = 0, count = 0;
    for (final d in LocalDb.all('sales_documents')) {
      if (d['status'] != 'validated' || d['number'] == null || !(d['type'] == 'invoice' || d['type'] == 'deposit')) continue;
      if ('${d['date'] ?? ''}'.startsWith(today)) {
        sales += _d(d['total']);
        count++;
      }
      if (d['payment_status'] != 'paid') {
        final due = _d(d['total']) - _d(d['amount_paid']);
        if (due > 0) toCollect += due;
        final dd = '${d['due_date'] ?? ''}';
        if (dd.length >= 10 && dd.substring(0, 10).compareTo(today) < 0) overdue++;
      }
    }
    return {
      'company': company,
      'sales_today': _money(sales),
      'invoices_today': '$count',
      'to_collect': _money(toCollect),
      'overdue': '$overdue',
      'updated': _hm(now),
      'allowed': 'true',
    };
  }

  static String _hm(DateTime t) => '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

  /// Met à jour les widgets posés sur l'écran d'accueil (sans effet s'il n'y en a pas).
  static Future<void> refresh() async {
    if (!supported) return;
    try {
      await HomeWidget.setAppGroupId(appGroup);
      for (final e in snapshot().entries) {
        await HomeWidget.saveWidgetData<String>(e.key, e.value);
      }
      await HomeWidget.updateWidget(androidName: androidProvider, iOSName: iosKind);
    } catch (e) {
      debugPrint('Widget non mis à jour : $e');
    }
  }

  /// Déconnexion : plus aucun chiffre de la société sur l'écran d'accueil.
  static Future<void> clear() async {
    company = '';
    if (!supported) return;
    try {
      await HomeWidget.setAppGroupId(appGroup);
      for (final k in const ['sales_today', 'to_collect', 'overdue', 'invoices_today']) {
        await HomeWidget.saveWidgetData<String>(k, '—');
      }
      await HomeWidget.saveWidgetData<String>('company', '');
      await HomeWidget.saveWidgetData<String>('allowed', 'false');
      await HomeWidget.updateWidget(androidName: androidProvider, iOSName: iosKind);
    } catch (_) {}
  }
}
