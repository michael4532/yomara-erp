import 'dart:async';

import 'package:barcode/barcode.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import 'common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _driverColors = {'mtn_momo': Color(0xFFFFCB05), 'wave': Color(0xFF1DC3F0), 'orange_money': Color(0xFFFF7900), 'cinetpay': Color(0xFF00A86B), 'paydunya': Color(0xFF0B5FFF)};

/// Résultat d'un encaissement Mobile Money confirmé par le prestataire.
class CollectResult {
  CollectResult(this.amount, this.reference, this.gateway, this.method);

  final double amount;
  final String reference;
  final String gateway;
  final String method;
}

/// Encaissement Mobile Money en direct : demande sur le téléphone du client (MTN MoMo) ou QR code à scanner
/// (Wave, Orange Money, CinetPay…). Renvoie le paiement confirmé, ou null si annulé.
Future<CollectResult?> showMobileMoneyCollect(BuildContext context, {required double amount, String? documentId, String? label, String? phone}) {
  return showDialog<CollectResult>(context: context, barrierDismissible: false, builder: (_) => _CollectDialog(amount: amount, documentId: documentId, label: label, phone: phone));
}

/// Moyens configurés pour l'encaissement direct (vide si aucun prestataire en ligne n'est paramétré).
Future<List<Map<String, dynamic>>> collectGateways(BuildContext context) async {
  try {
    final r = await context.read<Session>().api.get('payments/collect/gateways');
    return [for (final g in (r['data'] as List)) asMap(g)];
  } catch (_) {
    return const [];
  }
}

class _CollectDialog extends StatefulWidget {
  const _CollectDialog({required this.amount, this.documentId, this.label, this.phone});

  final double amount;
  final String? documentId, label, phone;

  @override
  State<_CollectDialog> createState() => _CollectDialogState();
}

class _CollectDialogState extends State<_CollectDialog> {
  List<Map<String, dynamic>>? _gws;
  Map<String, dynamic>? _gw;
  Map<String, dynamic>? _tx;
  late final _phone = TextEditingController(text: widget.phone ?? '');
  Timer? _poll;
  String? _error;
  bool _busy = false;
  int _elapsed = 0;

  @override
  void initState() {
    super.initState();
    collectGateways(context).then((g) {
      if (!mounted) return;
      setState(() {
        _gws = g;
        _gw = g.isNotEmpty ? g.first : null;
      });
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _start() async {
    if (_gw == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await context.read<Session>().api.post('payments/collect', {
        'gateway_id': _gw!['id'], 'amount': widget.amount, if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
        if (widget.documentId != null) 'document_id': widget.documentId, if (widget.label != null) 'label': widget.label,
      });
      if (!mounted) return;
      setState(() => _tx = asMap(r['data']));
      _elapsed = 0;
      _poll = Timer.periodic(const Duration(seconds: 3), (_) => _check());
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _check() async {
    if (_tx == null) return;
    _elapsed += 3;
    try {
      final r = await context.read<Session>().api.get('payments/collect/${_tx!['id']}');
      if (!mounted) return;
      final t = asMap(r['data']);
      setState(() => _tx = t);
      if (t['status'] == 'paid') {
        _poll?.cancel();
        SystemSound.play(SystemSoundType.alert);
        await Future.delayed(const Duration(milliseconds: 900));
        if (mounted) Navigator.pop(context, CollectResult(toDouble(t['amount']), '${t['gateway']} · ${t['reference'] ?? ''}'.trim(), '${t['gateway']}', '${t['method']}'));
      } else if (t['status'] == 'failed' || t['status'] == 'cancelled') {
        _poll?.cancel();
      } else if (_elapsed > 300) {
        _poll?.cancel();
        setState(() => _error = 'Pas de réponse du client après 5 minutes.');
      }
    } catch (_) {/* réseau : on réessaie au prochain tour */}
  }

  Future<void> _cancel() async {
    _poll?.cancel();
    if (_tx != null && _tx!['status'] == 'pending') {
      try {
        await context.read<Session>().api.post('payments/collect/${_tx!['id']}/cancel');
      } catch (_) {}
    }
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return YAlertDialog(
      title: Row(children: [
        const Icon(Icons.phone_iphone, color: YColors.blue),
        const SizedBox(width: 10),
        Expanded(child: Text(tr('Mobile Money · ${money(widget.amount, cur)}'))),
      ]),
      content: SizedBox(width: 420, child: _tx == null ? _setup() : _waiting(cur)),
      actions: [
        TextButton(onPressed: _cancel, child: Text(tr(_tx != null && _tx!['status'] == 'pending' ? 'Annuler la demande' : 'Fermer'))),
        if (_tx == null)
          FilledButton.icon(
            onPressed: _busy || _gw == null || (_gw!['mode'] == 'push' && _phone.text.trim().length < 8) ? null : _start,
            icon: Icon(_gw?['mode'] == 'push' ? Icons.send_to_mobile : Icons.qr_code_2),
            label: Text(tr(_gw?['mode'] == 'push' ? 'Envoyer la demande' : 'Afficher le QR code')),
          )
        else if (_tx!['status'] == 'failed' || _tx!['status'] == 'cancelled' || _error != null)
          FilledButton(
            onPressed: () {
              _poll?.cancel();
              setState(() {
                _tx = null;
                _error = null;
              });
            },
            child: Text(tr('Réessayer')),
          ),
      ],
    );
  }

  Widget _setup() {
    if (_gws == null) return const SizedBox(height: 120, child: Center(child: CircularProgressIndicator()));
    if (_gws!.isEmpty) {
      return Text(tr('Aucun prestataire Mobile Money en ligne n\'est configuré. Ajoutez MTN MoMo, Wave, Orange Money ou CinetPay dans Paramètres › Paiements en ligne.'));
    }
    return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final g in _gws!)
          ChoiceChip(
            avatar: CircleAvatar(backgroundColor: _driverColors[g['driver']] ?? YColors.blue, radius: 7),
            label: Text(tr('${g['label']}${g['test'] == true ? ' (test)' : ''}')),
            selected: _gw?['id'] == g['id'],
            onSelected: (_) => setState(() => _gw = g),
          ),
      ]),
      const SizedBox(height: 14),
      if (_gw?['mode'] == 'push') ...[
        TextField(
          controller: _phone,
          autofocus: true,
          keyboardType: TextInputType.phone,
          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, letterSpacing: 1),
          decoration: InputDecoration(labelText: tr('Numéro Mobile Money du client'), hintText: '07 07 00 00 00', prefixIcon: Icon(Icons.phone)),
          onChanged: (_) => setState(() {}),
          onSubmitted: (_) => _start(),
        ),
        const SizedBox(height: 8),
        Text(tr('Le client reçoit la demande sur son téléphone et la valide avec son code secret.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
      ] else
        Text(tr('Un QR code s\'affiche : le client le scanne avec son application et confirme le paiement.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
      if (_error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr(_error!), style: const TextStyle(color: YColors.danger))),
    ]);
  }

  Widget _waiting(String cur) {
    final t = _tx!;
    final status = '${t['status']}';
    if (status == 'paid') {
      return Column(mainAxisSize: MainAxisSize.min, children: [
        const Icon(Icons.check_circle, color: YColors.success, size: 72),
        const SizedBox(height: 8),
        Text(tr('Paiement reçu : ${money(toDouble(t['amount']), cur)}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        Text(tr('${t['gateway']} · ${t['reference'] ?? ''}'), style: TextStyle(color: Theme.of(context).hintColor)),
      ]);
    }
    if (status == 'failed' || status == 'cancelled') {
      return Column(mainAxisSize: MainAxisSize.min, children: [
        const Icon(Icons.cancel, color: YColors.danger, size: 64),
        const SizedBox(height: 8),
        Text(tr(status == 'cancelled' ? 'Demande annulée.' : 'Paiement refusé ou expiré.'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
        if (t['error'] != null) Text(tr('${t['error']}'), style: TextStyle(color: Theme.of(context).hintColor)),
      ]);
    }
    return Column(mainAxisSize: MainAxisSize.min, children: [
      if (t['mode'] == 'qr' && t['url'] != null) ...[
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: YColors.line)),
          child: SizedBox(width: 220, height: 220, child: CustomPaint(painter: _QrPainter('${t['url']}'))),
        ),
        const SizedBox(height: 10),
        Text(tr('Scannez avec ${t['gateway']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
      ] else ...[
        const SizedBox(height: 8),
        const SizedBox(width: 64, height: 64, child: CircularProgressIndicator(strokeWidth: 5)),
        const SizedBox(height: 16),
        Text(tr('Demande envoyée au ${t['phone'] ?? _phone.text}'), style: const TextStyle(fontWeight: FontWeight.w700)),
      ],
      const SizedBox(height: 6),
      Text(tr('En attente de la confirmation du client… (${_elapsed}s)'), style: TextStyle(color: Theme.of(context).hintColor)),
      if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr(_error!), style: const TextStyle(color: YColors.danger))),
    ]);
  }
}

/// QR code dessiné à partir du paquet barcode (aucune dépendance supplémentaire).
class _QrPainter extends CustomPainter {
  _QrPainter(this.data);

  final String data;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()..color = Colors.black;
    for (final e in Barcode.qrCode().make(data, width: size.width, height: size.height)) {
      if (e is BarcodeBar && e.black) canvas.drawRect(Rect.fromLTWH(e.left, e.top, e.width, e.height), p);
    }
  }

  @override
  bool shouldRepaint(covariant _QrPainter old) => old.data != data;
}
