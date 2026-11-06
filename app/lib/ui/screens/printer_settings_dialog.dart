import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:printing/printing.dart';

import '../../printing/pos_printer.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';

/// Réglages de l'imprimante de caisse, enregistrés sur ce poste uniquement.
class PrinterSettingsDialog extends StatefulWidget {
  const PrinterSettingsDialog({super.key, required this.companyName});

  final String companyName;

  @override
  State<PrinterSettingsDialog> createState() => _PrinterSettingsDialogState();
}

class _PrinterSettingsDialogState extends State<PrinterSettingsDialog> {
  late final PrinterConfig _cfg = PosPrinter.load().copy();
  late final _host = TextEditingController(text: _cfg.host);
  late final _port = TextEditingController(text: '${_cfg.port}');
  List<Printer>? _printers;
  bool _testing = false;
  String? _testResult;
  bool _testOk = false;

  @override
  void initState() {
    super.initState();
    if (!PosPrinter.networkAvailable && _cfg.mode == PrintMode.network) _cfg.mode = PrintMode.pdf;
    _loadPrinters();
  }

  Future<void> _loadPrinters() async {
    try {
      final list = await PosPrinter.systemPrinters();
      if (mounted) setState(() => _printers = list);
    } catch (_) {
      if (mounted) setState(() => _printers = []);
    }
  }

  void _collect() {
    _cfg.host = _host.text.trim();
    _cfg.port = int.tryParse(_port.text) ?? 9100;
  }

  Future<void> _test() async {
    _collect();
    setState(() {
      _testing = true;
      _testResult = null;
    });
    try {
      await PosPrinter.printTest(_cfg, widget.companyName);
      _testOk = true;
      _testResult = _cfg.mode == PrintMode.pdf ? 'Aperçu ouvert.' : 'Ticket de test envoyé.';
    } on PrintFailure catch (e) {
      _testOk = false;
      _testResult = e.message;
    } catch (e) {
      _testOk = false;
      _testResult = 'Échec : $e';
    }
    if (mounted) setState(() => _testing = false);
  }

  @override
  Widget build(BuildContext context) {
    final canList = _printers != null && _printers!.isNotEmpty;
    return YAlertDialog(
      title: Text(tr('Imprimante de caisse')),
      content: SizedBox(
        width: 480,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Ces réglages concernent uniquement ce poste.'), style: TextStyle(color: YColors.muted, fontSize: 13)),
            const SizedBox(height: 16),
            Field(
              label: tr('Mode d\'impression'),
              child: SegmentedButton<PrintMode>(
                showSelectedIcon: false,
                segments: [
                  ButtonSegment(value: PrintMode.pdf, label: Text(tr('PDF')), icon: Icon(Icons.picture_as_pdf_outlined)),
                  ButtonSegment(value: PrintMode.system, label: Text(tr('Imprimante PC')), icon: const Icon(Icons.usb), enabled: _printers == null || canList),
                  ButtonSegment(value: PrintMode.network, label: Text(tr('Réseau')), icon: const Icon(Icons.lan_outlined), enabled: PosPrinter.networkAvailable),
                ],
                selected: {_cfg.mode},
                onSelectionChanged: (s) => setState(() {
                  _cfg.mode = s.first;
                  _testResult = null;
                }),
              ),
            ),
            Text(tr(_hint()), style: const TextStyle(color: YColors.muted, fontSize: 12.5)),
            if (!PosPrinter.networkAvailable) ...[
              const SizedBox(height: 6),
              Text(
                tr('Dans le navigateur, seule l\'impression PDF est possible. Pour imprimer directement sur une imprimante thermique, utilisez l\'application Windows ou Mac.'),
                style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600),
              ),
            ],
            const SizedBox(height: 16),
            if (_cfg.mode == PrintMode.system)
              Field(
                label: tr('Imprimante'),
                child: _printers == null
                    ? const LinearProgressIndicator()
                    : DropdownButtonFormField<String>(
                        initialValue: _printers!.any((p) => p.url == _cfg.systemPrinterUrl) ? _cfg.systemPrinterUrl : null,
                        hint: Text(tr('Choisir une imprimante')),
                        isExpanded: true,
                        items: [for (final p in _printers!) DropdownMenuItem(value: p.url, child: Text(tr(p.name), overflow: TextOverflow.ellipsis))],
                        onChanged: (v) => setState(() {
                          _cfg.systemPrinterUrl = v;
                          _cfg.systemPrinterName = _printers!.firstWhere((p) => p.url == v).name;
                        }),
                      ),
              ),
            if (_cfg.mode == PrintMode.network)
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(
                  flex: 3,
                  child: Field(label: tr('Adresse IP'), child: TextField(controller: _host, decoration: const InputDecoration(hintText: '192.168.1.100'))),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Field(
                    label: tr('Port'),
                    child: TextField(controller: _port, inputFormatters: [FilteringTextInputFormatter.digitsOnly]),
                  ),
                ),
              ]),
            Field(
              label: tr('Largeur du papier'),
              child: SegmentedButton<int>(
                showSelectedIcon: false,
                segments: [ButtonSegment(value: 80, label: Text(tr('80 mm'))), ButtonSegment(value: 58, label: Text(tr('58 mm')))],
                selected: {_cfg.paper},
                onSelectionChanged: (s) => setState(() => _cfg.paper = s.first),
              ),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Imprimer automatiquement après chaque vente')),
              value: _cfg.autoPrint,
              onChanged: (v) => setState(() => _cfg.autoPrint = v),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Ouvrir le tiroir-caisse pour les paiements en espèces')),
              subtitle: _cfg.mode == PrintMode.network
                  ? null
                  : Text(tr('Dans ce mode, activez l\'ouverture du tiroir dans les préférences du pilote de l\'imprimante.')),
              value: _cfg.openDrawer,
              onChanged: (v) => setState(() => _cfg.openDrawer = v),
            ),
            const SizedBox(height: 8),
            Row(children: [
              OutlinedButton.icon(
                onPressed: _testing ? null : _test,
                icon: _testing ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.receipt_outlined, size: 18),
                label: Text(tr('Imprimer un test')),
              ),
              const SizedBox(width: 12),
              if (_testResult != null)
                Expanded(
                  child: Text(tr(_testResult!), style: TextStyle(color: _testOk ? YColors.success : YColors.danger, fontWeight: FontWeight.w600, fontSize: 13)),
                ),
            ]),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler'))),
        SaveButton(
          onPressed: () async {
            _collect();
            await PosPrinter.save(_cfg);
            if (context.mounted) Navigator.pop(context, true);
          },
          child: Text(tr('Enregistrer')),
        ),
      ],
    );
  }

  String _hint() => switch (_cfg.mode) {
        PrintMode.pdf => 'Ouvre l\'aperçu d\'impression à chaque ticket. Fonctionne partout, y compris dans le navigateur.',
        PrintMode.system => 'Imprime directement, sans fenêtre, sur une imprimante installée sur cet ordinateur (USB ou partagée).',
        PrintMode.network => 'Imprimante thermique raccordée au réseau (Ethernet ou Wi-Fi). Coupe du papier et tiroir-caisse pilotés par l\'application.',
      };
}
