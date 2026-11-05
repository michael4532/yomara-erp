import 'package:flutter/material.dart';

import '../../core/lots.dart';
import '../theme.dart';
import 'common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Choix (sortie) ou saisie (entrée) des lots / numéros de série d'une ligne.
/// [quantity] est exprimée en unités de stock. Renvoie l'allocation, ou null si annulé.
Future<List<Map<String, dynamic>>?> editLotsSpec(
  BuildContext context, {
  required Map<String, dynamic> product,
  required double quantity,
  dynamic spec,
  required bool incoming,
  String? warehouseId,
}) =>
    showDialog<List<Map<String, dynamic>>>(
      context: context,
      builder: (_) => _LotsDialog(product: product, quantity: quantity, spec: [for (final s in (spec as List? ?? const [])) asMap(s)], incoming: incoming, warehouseId: warehouseId),
    );

class _LotsDialog extends StatefulWidget {
  const _LotsDialog({required this.product, required this.quantity, required this.spec, required this.incoming, this.warehouseId});

  final Map<String, dynamic> product;
  final double quantity;
  final List<Map<String, dynamic>> spec;
  final bool incoming;
  final String? warehouseId;

  @override
  State<_LotsDialog> createState() => _LotsDialogState();
}

class _LotsDialogState extends State<_LotsDialog> {
  late final bool _serial = isSerial(widget.product);
  late final bool _expiry = widget.product['use_expiry'] == true;
  // Sortie : quantité choisie par lot
  late final Map<String, double> _take = {
    for (final s in widget.spec)
      if (s['lot_id'] != null) '${s['lot_id']}': (s['quantity'] as num?)?.toDouble() ?? 1,
  };
  // Entrée : lignes saisies
  late final List<Map<String, dynamic>> _rows = widget.incoming
      ? [for (final s in widget.spec) {'number': TextEditingController(text: '${s['number'] ?? ''}'), 'qty': TextEditingController(text: _fmt(s['quantity'] ?? 1)), 'expiry': s['expiry_date']}]
      : [];
  late final _serials = TextEditingController(text: widget.incoming && _serial ? widget.spec.map((s) => s['number'] ?? '').join('\n') : '');

  static String _fmt(dynamic v) {
    final d = (v as num?)?.toDouble() ?? 1;
    return d == d.roundToDouble() ? '${d.toInt()}' : '$d';
  }

  @override
  void initState() {
    super.initState();
    if (widget.incoming && !_serial && _rows.isEmpty) _addRow();
  }

  void _addRow() => _rows.add({'number': TextEditingController(), 'qty': TextEditingController(text: _fmt(_rows.isEmpty ? widget.quantity : 0)), 'expiry': null});

  double get _allocated => widget.incoming
      ? (_serial ? _serialList().length.toDouble() : _rows.fold(0.0, (s, r) => s + toDouble((r['qty'] as TextEditingController).text)))
      : _take.values.fold(0.0, (s, v) => s + v);

  List<String> _serialList() => _serials.text.split(RegExp(r'[\n,;]+')).map((e) => e.trim()).where((e) => e.isNotEmpty).toSet().toList();

  List<Map<String, dynamic>> _result() {
    if (!widget.incoming) {
      return [for (final e in _take.entries) if (e.value > 0) {'lot_id': e.key, 'quantity': e.value}];
    }
    if (_serial) return [for (final s in _serialList()) {'number': s, 'quantity': 1}];
    return [
      for (final r in _rows)
        if ((r['number'] as TextEditingController).text.trim().isNotEmpty && toDouble((r['qty'] as TextEditingController).text) > 0)
          {'number': (r['number'] as TextEditingController).text.trim(), 'quantity': toDouble((r['qty'] as TextEditingController).text), 'expiry_date': ?r['expiry']},
    ];
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final label = _serial ? 'numéros de série' : 'lots';
    final over = _allocated > widget.quantity + 0.0001;
    return YAlertDialog(
      title: Text(tr('${widget.incoming ? 'Lots reçus' : 'Lots à sortir'} — ${widget.product['name']}')),
      content: SizedBox(
        width: 520,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(
            tr(widget.incoming
                ? (_serial ? 'Un numéro par ligne (lecteur de code-barres possible). Obligatoires à l\'entrée.' : 'Numéro de lot${_expiry ? ', date de péremption' : ''} et quantité. Sans saisie, un lot est créé automatiquement.')
                : 'Sans choix, le lot qui périme le plus tôt sort automatiquement (FEFO) ; les lots périmés sont écartés.'),
            style: TextStyle(color: hint, fontSize: 12.5),
          ),
          const SizedBox(height: 8),
          Text(tr('${_fmt(_allocated)} / ${_fmt(widget.quantity)} attribué(s)'), style: TextStyle(fontWeight: FontWeight.w700, color: over ? YColors.danger : null)),
          const SizedBox(height: 8),
          Flexible(
            child: SingleChildScrollView(
              child: widget.incoming ? (_serial ? _serialInput() : _lotInput()) : _picker(label),
            ),
          ),
        ]),
      ),
      actions: [
        if (widget.spec.isNotEmpty) TextButton(onPressed: () => Navigator.pop(context, <Map<String, dynamic>>[]), child: Text(tr('Tout effacer'))),
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(
          onPressed: () {
            if (over) return toast(context, tr('Plus de $label que la quantité de la ligne.'), error: true);
            Navigator.pop(context, _result());
          },
          child: Text(tr('Valider')),
        ),
      ],
    );
  }

  Widget _serialInput() => TextField(
        controller: _serials,
        autofocus: true,
        minLines: 4,
        maxLines: 10,
        decoration: InputDecoration(hintText: tr('SN-0001\nSN-0002\n…')),
        onChanged: (_) => setState(() {}),
      );

  Widget _lotInput() => Column(children: [
        for (var i = 0; i < _rows.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(children: [
              Expanded(flex: 3, child: TextField(controller: _rows[i]['number'] as TextEditingController, decoration: InputDecoration(isDense: true, labelText: tr('N° de lot')))),
              const SizedBox(width: 8),
              if (_expiry)
                Expanded(
                  flex: 3,
                  child: OutlinedButton(
                    onPressed: () async {
                      final d = await showDatePicker(context: context, initialDate: DateTime.tryParse('${_rows[i]['expiry'] ?? ''}') ?? DateTime.now().add(const Duration(days: 30)), firstDate: DateTime(2000), lastDate: DateTime(2100));
                      if (d != null) setState(() => _rows[i]['expiry'] = isoDate(d));
                    },
                    child: Text(tr(_rows[i]['expiry'] == null ? 'Péremption' : dateFr(_rows[i]['expiry'])), overflow: TextOverflow.ellipsis),
                  ),
                ),
              if (_expiry) const SizedBox(width: 8),
              SizedBox(width: 80, child: TextField(controller: _rows[i]['qty'] as TextEditingController, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(isDense: true, labelText: tr('Qté')), onChanged: (_) => setState(() {}))),
              IconButton(onPressed: () => setState(() => _rows.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
            ]),
          ),
        Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => setState(_addRow), icon: const Icon(Icons.add), label: Text(tr('Autre lot')))),
      ]);

  Widget _picker(String label) {
    final lots = availableLots('${widget.product['id']}', warehouseId: widget.warehouseId);
    if (lots.isEmpty) return Padding(padding: const EdgeInsets.all(12), child: Text(tr('Aucun $label en stock (données synchronisées).'), style: TextStyle(color: Theme.of(context).hintColor)));
    return Column(children: [
      for (final l in lots)
        ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          leading: _serial
              ? Checkbox(value: (_take[l.id] ?? 0) > 0, onChanged: l.expired ? null : (v) => setState(() => v == true ? _take[l.id] = 1 : _take.remove(l.id)))
              : SizedBox(
                  width: 70,
                  child: TextFormField(
                    initialValue: _take[l.id] == null ? '' : _fmt(_take[l.id]),
                    enabled: !l.expired,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(isDense: true, hintText: '0'),
                    onChanged: (v) => setState(() {
                      final q = toDouble(v);
                      q > 0 ? _take[l.id] = q.clamp(0, l.quantity).toDouble() : _take.remove(l.id);
                    }),
                  ),
                ),
          title: Text(tr(l.number), style: TextStyle(fontWeight: FontWeight.w600, decoration: l.expired ? TextDecoration.lineThrough : null)),
          subtitle: Text(tr([
            'en stock : ${_fmt(l.quantity)}',
            if (l.expiry != null) (l.expired ? 'périmé le ${dateFr(l.lot['expiry_date'])}' : 'péremption ${dateFr(l.lot['expiry_date'])} (${l.daysLeft} j)'),
          ].join(' · '))),
          trailing: l.expired ? const Icon(Icons.block, color: YColors.danger, size: 18) : (l.daysLeft != null && l.daysLeft! <= 7 ? const Icon(Icons.schedule, color: YColors.warning, size: 18) : null),
        ),
    ]);
  }
}
