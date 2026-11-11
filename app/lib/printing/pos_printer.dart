import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:pdf/pdf.dart';
import 'package:printing/printing.dart';

import '../core/fne.dart';
import '../core/local_db.dart';
import '../pdf/receipt_pdf.dart';
import '../state/session.dart';
import 'receipt_escpos.dart';
import 'transport.dart';
import '../ui/widgets/pdf_viewer.dart';

/// Mode d'impression du poste de caisse.
enum PrintMode {
  /// Boîte de dialogue d'impression / aperçu PDF (partout, y compris navigateur).
  pdf,

  /// Imprimante installée sur l'ordinateur (USB…), sans boîte de dialogue.
  system,

  /// Imprimante thermique réseau ESC/POS (IP, port 9100) : coupe et tiroir pilotés.
  network,
}

/// Réglages d'impression, propres à chaque poste (non synchronisés).
class PrinterConfig {
  PrinterConfig({
    this.mode = PrintMode.pdf,
    this.host = '',
    this.port = 9100,
    this.systemPrinterUrl,
    this.systemPrinterName,
    this.paper = 80,
    this.autoPrint = false,
    this.openDrawer = true,
  });

  PrintMode mode;
  String host;
  int port;
  String? systemPrinterUrl;
  String? systemPrinterName;
  int paper; // 80 ou 58 mm
  bool autoPrint;
  bool openDrawer;

  int get columns => paper == 58 ? 32 : 48;
  PdfPageFormat get pdfFormat => paper == 58 ? PdfPageFormat.roll57 : PdfPageFormat.roll80;

  /// Le tiroir est piloté par l'application (sinon par le pilote de l'imprimante).
  bool get drawerControlled => mode == PrintMode.network;

  Map<String, dynamic> toJson() => {
        'mode': mode.name,
        'host': host,
        'port': port,
        'system_printer_url': systemPrinterUrl,
        'system_printer_name': systemPrinterName,
        'paper': paper,
        'auto_print': autoPrint,
        'open_drawer': openDrawer,
      };

  factory PrinterConfig.fromJson(Map<String, dynamic> j) => PrinterConfig(
        mode: PrintMode.values.firstWhere((m) => m.name == j['mode'], orElse: () => PrintMode.pdf),
        host: '${j['host'] ?? ''}',
        port: (j['port'] as num?)?.toInt() ?? 9100,
        systemPrinterUrl: j['system_printer_url'] as String?,
        systemPrinterName: j['system_printer_name'] as String?,
        paper: (j['paper'] as num?)?.toInt() == 58 ? 58 : 80,
        autoPrint: j['auto_print'] == true,
        openDrawer: j['open_drawer'] != false,
      );

  PrinterConfig copy() => PrinterConfig.fromJson(toJson());
}

class PrintFailure implements Exception {
  PrintFailure(this.message);
  final String message;
  @override
  String toString() => message;
}

/// Point d'entrée unique pour tout ce qui sort sur l'imprimante de caisse.
class PosPrinter {
  static const _key = 'pos_printer';

  static bool get networkAvailable => !kIsWeb && rawNetworkSupported;

  static PrinterConfig load() {
    try {
      final raw = LocalDb.meta.get(_key);
      if (raw is String) return PrinterConfig.fromJson(Map<String, dynamic>.from(jsonDecode(raw)));
    } catch (_) {}
    return PrinterConfig();
  }

  static Future<void> save(PrinterConfig c) => LocalDb.meta.put(_key, jsonEncode(c.toJson()));

  static ReceiptContext contextFor(Session s, {String? registerName}) {
    final c = s.company;
    return ReceiptContext(
      companyName: '${c['name'] ?? ''}',
      headerLines: [
        for (final l in [c['address'], c['city'], c['phone'] != null ? 'Tél. ${c['phone']}' : null, c['tax_id'] != null ? 'NCC ${c['tax_id']}' : null])
          if (l != null && '$l'.isNotEmpty) '$l',
      ],
      cashier: '${s.user['name'] ?? ''}',
      currency: s.currency,
      footer: '${s.companySettings['receipt_footer'] ?? 'Merci de votre visite !'}',
      registerName: registerName,
    );
  }

  /// Imprime un ticket. Retourne un message d'avertissement si l'impression
  /// a dû basculer en PDF (imprimante injoignable), sinon null.
  static Future<String?> printReceipt({
    required Session session,
    required Map<String, dynamic> doc,
    required List<Map<String, dynamic>> payments,
    double change = 0,
    String? registerName,
    bool openDrawer = false,
    PrinterConfig? config,
  }) async {
    final cfg = config ?? load();
    Future<Uint8List> pdf() => receiptPdf(session: session, doc: doc, payments: payments, change: change, registerName: registerName, format: cfg.pdfFormat);
    return _dispatch(
      cfg,
      name: 'Ticket ${doc['number'] ?? doc['reference'] ?? ''}',
      escpos: () => receiptEscPos(
        ctx: contextFor(session, registerName: registerName),
        doc: doc,
        payments: payments,
        change: change,
        columns: cfg.columns,
        openDrawer: openDrawer && cfg.openDrawer,
        fne: fneCertFor(doc['id'] as String?),
      ),
      pdf: pdf,
    );
  }

  static Future<String?> printZReport({required Session session, required Map<String, dynamic> report, required String registerName, PrinterConfig? config}) {
    final cfg = config ?? load();
    return _dispatch(
      cfg,
      name: 'Rapport Z',
      escpos: () => zReportEscPos(ctx: contextFor(session, registerName: registerName), report: report, columns: cfg.columns),
      pdf: () => zReportPdf(session: session, report: report, registerName: registerName, format: cfg.pdfFormat),
    );
  }

  /// Bon de préparation pour la cuisine ou le bar (imprimante du poste).
  static Future<String?> printKitchenTicket({required String station, required String title, required String subtitle, required List<Map<String, dynamic>> lines, PrinterConfig? config}) {
    final cfg = config ?? load();
    return _dispatch(
      cfg,
      name: 'Bon $station $title',
      escpos: () => kitchenTicketEscPos(station: station, title: title, subtitle: subtitle, lines: lines, columns: cfg.columns),
      pdf: () => kitchenTicketPdf(station: station, title: title, subtitle: subtitle, lines: lines, format: cfg.pdfFormat),
    );
  }

  /// Ouvre le tiroir-caisse (mode réseau uniquement). Lève [PrintFailure] en cas d'échec.
  static Future<void> openDrawer([PrinterConfig? config]) async {
    final cfg = config ?? load();
    if (!cfg.drawerControlled || !cfg.openDrawer) return;
    await _send(cfg, [0x1B, 0x40, 0x1B, 0x70, 0, 25, 250]);
  }

  /// Ticket de test depuis l'écran de réglages. Lève [PrintFailure] en cas d'échec.
  static Future<void> printTest(PrinterConfig cfg, String companyName) async {
    switch (cfg.mode) {
      case PrintMode.network:
        await _send(cfg, testPageEscPos(companyName: companyName, columns: cfg.columns, openDrawer: cfg.openDrawer));
      case PrintMode.system:
      case PrintMode.pdf:
        final bytes = await _testPdf(companyName, cfg);
        if (cfg.mode == PrintMode.system) {
          await _system(cfg, 'Test', bytes);
        } else {
          await previewPdf(name: 'Test', format: cfg.pdfFormat, onLayout: (_) async => bytes);
        }
    }
  }

  static Future<List<Printer>> systemPrinters() async {
    final info = await Printing.info();
    if (!info.canListPrinters) return [];
    return Printing.listPrinters();
  }

  // ------------------------------------------------------------------

  static Future<String?> _dispatch(
    PrinterConfig cfg, {
    required String name,
    required Uint8List Function() escpos,
    required Future<Uint8List> Function() pdf,
  }) async {
    try {
      switch (cfg.mode) {
        case PrintMode.network:
          await _send(cfg, escpos());
          return null;
        case PrintMode.system:
          await _system(cfg, name, await pdf());
          return null;
        case PrintMode.pdf:
          final bytes = await pdf();
          await previewPdf(name: '$name.pdf', format: cfg.pdfFormat, onLayout: (_) async => bytes);
          return null;
      }
    } on PrintFailure catch (e) {
      // Repli : la vente ne doit jamais être bloquée par l'imprimante.
      final bytes = await pdf();
      await previewPdf(name: '$name.pdf', format: cfg.pdfFormat, onLayout: (_) async => bytes);
      return '${e.message} Impression via PDF à la place.';
    }
  }

  static Future<void> _send(PrinterConfig cfg, List<int> bytes) async {
    if (!networkAvailable) throw PrintFailure('Impression réseau indisponible sur cet appareil.');
    if (cfg.host.trim().isEmpty) throw PrintFailure('Adresse IP de l\'imprimante non renseignée.');
    try {
      await sendRaw(cfg.host.trim(), cfg.port, bytes);
    } catch (_) {
      throw PrintFailure('Imprimante ${cfg.host}:${cfg.port} injoignable.');
    }
  }

  static Future<void> _system(PrinterConfig cfg, String name, Uint8List bytes) async {
    final url = cfg.systemPrinterUrl;
    if (url == null || url.isEmpty) throw PrintFailure('Aucune imprimante choisie.');
    try {
      final ok = await Printing.directPrintPdf(printer: Printer(url: url, name: cfg.systemPrinterName), name: name, format: cfg.pdfFormat, onLayout: (_) async => bytes);
      if (!ok) throw PrintFailure('L\'imprimante « ${cfg.systemPrinterName ?? url} » a refusé le travail.');
    } on PrintFailure {
      rethrow;
    } catch (_) {
      throw PrintFailure('Imprimante « ${cfg.systemPrinterName ?? url} » indisponible.');
    }
  }

  static Future<Uint8List> _testPdf(String companyName, PrinterConfig cfg) async {
    return receiptTestPdf(companyName, cfg.pdfFormat);
  }
}
