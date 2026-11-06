import 'package:flutter/material.dart';

import '../../core/custom_fields.dart';
import 'common.dart';
import '../../i18n/i18n.dart';

/// Saisie des champs personnalisés d'une fiche. Modifie directement [values] (envoyé dans « custom »).
class CustomFieldsEditor extends StatefulWidget {
  const CustomFieldsEditor({super.key, required this.entity, required this.values, this.creating = false, this.readOnly = false, this.title = 'Informations complémentaires', this.onChanged});

  final String entity;
  final Map<String, dynamic> values;
  final bool creating;
  final bool readOnly;
  final String? title;
  final VoidCallback? onChanged;

  @override
  State<CustomFieldsEditor> createState() => _CustomFieldsEditorState();
}

class _CustomFieldsEditorState extends State<CustomFieldsEditor> {
  final Map<String, TextEditingController> _ctrl = {};

  @override
  void initState() {
    super.initState();
    _defaults();
  }

  @override
  void didUpdateWidget(covariant CustomFieldsEditor old) {
    super.didUpdateWidget(old);
    if (!identical(old.values, widget.values)) {
      for (final c in _ctrl.values) {
        c.dispose();
      }
      _ctrl.clear();
      _defaults();
    }
  }

  void _defaults() {
    if (widget.creating) {
      for (final f in customFieldsFor(widget.entity)) {
        final k = '${f['key']}';
        final d = f['default_value'];
        if (!widget.values.containsKey(k) && !customEmpty(d)) {
          widget.values[k] = switch (f['type']) {
            'checkbox' => d == '1' || d == 'true',
            'multiselect' => '$d'.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList(),
            'number' || 'money' => num.tryParse('$d'),
            _ => '$d',
          };
        }
      }
    }
  }

  @override
  void dispose() {
    for (final c in _ctrl.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _c(Map<String, dynamic> f) {
    final k = '${f['key']}';
    return _ctrl.putIfAbsent(k, () {
      final v = widget.values[k];
      return TextEditingController(text: customEmpty(v) ? '' : (f['type'] == 'number' || f['type'] == 'money' ? customDisplay(f, v).replaceAll(' ', ' ') : '$v'));
    });
  }

  void _set(String k, dynamic v) {
    setState(() => widget.values[k] = v);
    widget.onChanged?.call();
  }

  @override
  Widget build(BuildContext context) {
    final fields = customFieldsFor(widget.entity);
    if (fields.isEmpty) return const SizedBox.shrink();
    final hint = Theme.of(context).hintColor;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (widget.title != null)
        Padding(
          padding: const EdgeInsets.only(top: 6, bottom: 10),
          child: Row(children: [
            Icon(Icons.dashboard_customize_outlined, size: 16, color: hint),
            const SizedBox(width: 6),
            Text(tr(widget.title!), style: const TextStyle(fontWeight: FontWeight.w700)),
          ]),
        ),
      LayoutBuilder(builder: (context, c) {
        final two = c.maxWidth >= 640;
        final half = (c.maxWidth - 16) / 2;
        return Wrap(spacing: 16, children: [
          for (final f in fields)
            SizedBox(
              width: two && !['textarea', 'multiselect'].contains(f['type']) ? half : c.maxWidth,
              child: Padding(padding: const EdgeInsets.only(bottom: 12), child: _field(f)),
            ),
        ]);
      }),
    ]);
  }

  Widget _field(Map<String, dynamic> f) {
    final k = '${f['key']}';
    final label = '${f['label']}${f['required'] == true ? ' *' : ''}';
    final help = (f['help'] as String?)?.isNotEmpty == true ? f['help'] as String : null;
    final v = widget.values[k];
    final ro = widget.readOnly;
    switch (f['type']) {
      case 'checkbox':
        return SwitchListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          title: Text(tr(label)),
          subtitle: help == null ? null : Text(tr(help)),
          value: v == true || v == 1 || v == '1' || v == 'true',
          onChanged: ro ? null : (x) => _set(k, x),
        );
      case 'select':
        final opts = [for (final o in (f['options'] as List? ?? const [])) '$o'];
        return DropdownButtonFormField<String?>(
          initialValue: opts.contains(v) ? v as String : null,
          isExpanded: true,
          decoration: InputDecoration(labelText: trn(label), helperText: trn(help)),
          items: [const DropdownMenuItem<String?>(value: null, child: Text('—')), for (final o in opts) DropdownMenuItem<String?>(value: o, child: Text(tr(o)))],
          onChanged: ro ? null : (x) => _set(k, x),
        );
      case 'multiselect':
        final opts = [for (final o in (f['options'] as List? ?? const [])) '$o'];
        final sel = {for (final x in (v is List ? v : const [])) '$x'};
        return InputDecorator(
          decoration: InputDecoration(labelText: trn(label), helperText: trn(help)),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final o in opts)
              FilterChip(
                label: Text(tr(o)),
                selected: sel.contains(o),
                onSelected: ro
                    ? null
                    : (on) {
                        final n = {...sel};
                        on ? n.add(o) : n.remove(o);
                        _set(k, opts.where(n.contains).toList());
                      },
              ),
          ]),
        );
      case 'date':
        return InkWell(
          onTap: ro
              ? null
              : () async {
                  final d = await pickDate(context, initialDate: DateTime.tryParse('$v') ?? DateTime.now(), firstDate: DateTime(1900), lastDate: DateTime(2100));
                  if (d != null) _set(k, isoDate(d));
                },
          child: InputDecorator(
            decoration: InputDecoration(
              labelText: trn(label),
              helperText: trn(help),
              suffixIcon: customEmpty(v) || ro ? const Icon(Icons.event) : IconButton(icon: const Icon(Icons.clear), onPressed: () => _set(k, null)),
            ),
            child: Text(tr(customEmpty(v) ? '' : customDisplay(f, v))),
          ),
        );
      default:
        final type = '${f['type']}';
        return TextField(
          controller: _c(f),
          readOnly: ro,
          maxLines: type == 'textarea' ? 4 : 1,
          keyboardType: switch (type) {
            'number' || 'money' => const TextInputType.numberWithOptions(decimal: true, signed: true),
            'email' => TextInputType.emailAddress,
            'phone' => TextInputType.phone,
            'url' => TextInputType.url,
            _ => TextInputType.text,
          },
          decoration: InputDecoration(labelText: trn(label), helperText: trn(help), suffixText: trn(type == 'money' ? 'montant' : null)),
          onChanged: (s) {
            if (type == 'number' || type == 'money') {
              final n = num.tryParse(s.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.'));
              widget.values[k] = s.trim().isEmpty ? null : (n ?? s);
            } else {
              widget.values[k] = s.trim().isEmpty ? null : s;
            }
            widget.onChanged?.call();
          },
        );
    }
  }
}

/// Lecture seule compacte (fiches détaillées).
class CustomFieldsView extends StatelessWidget {
  const CustomFieldsView({super.key, required this.entity, required this.values, this.listOnly = false});

  final String entity;
  final Map<String, dynamic>? values;
  final bool listOnly;

  @override
  Widget build(BuildContext context) {
    final items = [
      for (final f in customFieldsFor(entity))
        if ((!listOnly || f['show_in_list'] == true) && !customEmpty(values?['${f['key']}'])) (f['label'], customDisplay(f, values?['${f['key']}'])),
    ];
    if (items.isEmpty) return const SizedBox.shrink();
    final hint = Theme.of(context).hintColor;
    return Wrap(spacing: 14, runSpacing: 4, children: [
      for (final (l, v) in items)
        Text.rich(TextSpan(children: [TextSpan(text: '$l : ', style: TextStyle(color: hint, fontSize: 12)), TextSpan(text: v, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600))])),
    ]);
  }
}

/// Message d'erreur si des champs obligatoires manquent (null sinon).
String? customRequiredError(String entity, Map<String, dynamic> values) {
  final m = customMissing(entity, values);
  return m.isEmpty ? null : 'Champ${m.length > 1 ? 's' : ''} obligatoire${m.length > 1 ? 's' : ''} : ${m.join(', ')}';
}

