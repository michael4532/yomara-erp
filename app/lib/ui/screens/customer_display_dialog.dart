import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../display/customer_display.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Réglages de l'écran client (tablette ou second écran tourné vers le client).
class CustomerDisplayDialog extends StatefulWidget {
  const CustomerDisplayDialog({super.key});

  @override
  State<CustomerDisplayDialog> createState() => _CustomerDisplayDialogState();
}

class _CustomerDisplayDialogState extends State<CustomerDisplayDialog> {
  final _display = CustomerDisplay.instance;
  late final CustomerDisplayConfig _cfg = _display.config;
  late final _port = TextEditingController(text: '${_cfg.port}');
  late final _welcome = TextEditingController(text: _cfg.welcome);
  List<String> _urls = [];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _refreshUrls();
  }

  Future<void> _refreshUrls() async {
    final u = await _display.urls();
    if (mounted) setState(() => _urls = u);
  }

  Future<void> _apply() async {
    setState(() => _busy = true);
    _cfg.port = int.tryParse(_port.text) ?? 8765;
    _cfg.welcome = _welcome.text.trim().isEmpty ? 'Bienvenue' : _welcome.text.trim();
    await _display.saveConfig(_cfg);
    await _refreshUrls();
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _open() async {
    if (!_display.running) await _apply();
    if (_urls.isEmpty) return;
    final url = _display.isServer ? _urls.last : _urls.first; // localhost sur ce poste
    await safeLaunchUrl(Uri.parse(url), webOnlyWindowName: 'yomara_customer_display');
  }

  @override
  Widget build(BuildContext context) {
    final server = _display.isServer;
    return YAlertDialog(
      title: Text(tr('Écran client')),
      content: SizedBox(
        width: 500,
        child: ListenableBuilder(
          listenable: _display,
          builder: (context, _) => SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(
                tr(server
                    ? 'Affiche le panier, le total et la monnaie rendue sur une tablette ou un second écran, via le réseau local de la boutique (Internet non nécessaire).'
                    : 'Affiche le panier, le total et la monnaie rendue dans une seconde fenêtre, à placer sur l\'écran tourné vers le client.'),
                style: const TextStyle(color: YColors.muted, fontSize: 13),
              ),
              const SizedBox(height: 8),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('Activer l\'écran client sur ce poste')),
                value: _cfg.enabled,
                onChanged: _busy
                    ? null
                    : (v) {
                        setState(() => _cfg.enabled = v);
                        _apply();
                      },
              ),
              Field(label: tr('Message d\'accueil'), child: TextField(controller: _welcome, onSubmitted: (_) => _apply())),
              if (server)
                Field(
                  label: tr('Port'),
                  child: TextField(controller: _port, inputFormatters: [FilteringTextInputFormatter.digitsOnly], onSubmitted: (_) => _apply()),
                ),
              if (_display.lastError != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(tr(_display.lastError!), style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w600)),
                ),
              if (_cfg.enabled && _display.running && server) ...[
                Text(tr('Sur la tablette ou le second écran, ouvrez dans le navigateur :'), style: Theme.of(context).textTheme.labelLarge),
                const SizedBox(height: 6),
                for (final u in _urls.where((u) => !u.contains('localhost')))
                  Card(
                    margin: const EdgeInsets.only(bottom: 6),
                    child: ListTile(
                      dense: true,
                      leading: const Icon(Icons.tablet_android_outlined),
                      title: SelectableText(tr(u), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                      trailing: IconButton(
                        tooltip: tr('Copier'),
                        icon: const Icon(Icons.copy, size: 18),
                        onPressed: () {
                          Clipboard.setData(ClipboardData(text: u));
                          toast(context, tr('Adresse copiée.'));
                        },
                      ),
                    ),
                  ),
                const SizedBox(height: 4),
                Text(
                  tr('La tablette doit être sur le même Wi-Fi que cette caisse. Au premier lancement, Windows peut demander d\'autoriser l\'application dans le pare-feu : acceptez pour les réseaux privés.'),
                  style: const TextStyle(color: YColors.muted, fontSize: 12.5),
                ),
              ],
            ]),
          ),
        ),
      ),
      actions: [
        if (_cfg.enabled)
          OutlinedButton.icon(
            onPressed: _busy ? null : _open,
            icon: const Icon(Icons.open_in_new, size: 18),
            label: Text(tr(server ? 'Ouvrir sur ce poste' : 'Ouvrir la fenêtre client')),
          ),
        FilledButton(
          onPressed: () async {
            await _apply();
            if (context.mounted) Navigator.pop(context);
          },
          child: Text(tr('Fermer')),
        ),
      ],
    );
  }
}
