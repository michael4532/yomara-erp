import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../i18n/i18n.dart';
import '../theme.dart';

/// Filtre déroulant d'une barre de filtres : client, catégorie, produit, responsable…
class FilterSpec {
  const FilterSpec(this.key, this.label, this.options, {this.icon = Icons.filter_list_rounded});

  final String key;
  final String label;
  final IconData icon;

  /// valeur → libellé affiché
  final Map<String, String> options;
}

/// Période choisie dans la barre de filtres.
class FilterPeriod {
  const FilterPeriod(this.label, this.start, this.end);

  final String label;
  final DateTime start;
  final DateTime end; // inclus

  bool contains(dynamic v) {
    final d = v is DateTime ? v : DateTime.tryParse('${v ?? ''}');
    if (d == null) return false;
    final day = DateTime(d.year, d.month, d.day);
    return !day.isBefore(DateTime(start.year, start.month, start.day)) && !day.isAfter(DateTime(end.year, end.month, end.day));
  }

  static List<FilterPeriod> presets() {
    final n = DateTime.now();
    final today = DateTime(n.year, n.month, n.day);
    final monday = today.subtract(Duration(days: today.weekday - 1));
    return [
      FilterPeriod('Aujourd\'hui', today, today),
      FilterPeriod('Cette semaine', monday, monday.add(const Duration(days: 6))),
      FilterPeriod('Ce mois', DateTime(n.year, n.month), DateTime(n.year, n.month + 1, 0)),
      FilterPeriod('Mois dernier', DateTime(n.year, n.month - 1), DateTime(n.year, n.month, 0)),
      FilterPeriod('3 derniers mois', DateTime(n.year, n.month - 2), DateTime(n.year, n.month + 1, 0)),
      FilterPeriod('Cette année', DateTime(n.year), DateTime(n.year, 12, 31)),
      FilterPeriod('Année dernière', DateTime(n.year - 1), DateTime(n.year - 1, 12, 31)),
    ];
  }
}

/// Barre de filtres moderne et uniforme pour toutes les listes : recherche, statut (pastilles avec compteurs),
/// filtres déroulants (avec recherche quand la liste est longue), période, nombre de résultats et remise à zéro.
class FilterBar extends StatelessWidget {
  const FilterBar({
    super.key,
    this.onSearch,
    this.searchHint = 'Rechercher…',
    this.search,
    this.statuses,
    this.status,
    this.onStatus,
    this.statusCounts,
    this.filters = const [],
    this.values = const {},
    this.onFilter,
    this.period,
    this.onPeriod,
    this.showPeriod = false,
    this.count,
    this.countLabel = 'résultat',
    this.onReset,
    this.trailing = const [],
    this.padding,
  });

  final ValueChanged<String>? onSearch;
  final String searchHint;

  /// Texte de recherche actuel (permet d'afficher la remise à zéro)
  final String? search;

  /// Statuts proposés en pastilles (valeur → libellé), la valeur '' = tous.
  final Map<String, String>? statuses;
  final String? status;
  final ValueChanged<String>? onStatus;
  final Map<String, int>? statusCounts;

  final List<FilterSpec> filters;
  final Map<String, String?> values;
  final void Function(String key, String? value)? onFilter;

  final bool showPeriod;
  final FilterPeriod? period;
  final ValueChanged<FilterPeriod?>? onPeriod;

  final int? count;
  final String countLabel;
  final VoidCallback? onReset;
  final List<Widget> trailing;
  final EdgeInsets? padding;

  bool get _hasSearch => onSearch != null && _SearchBoxState.anyText;

  bool get _active => _hasSearch || (search ?? '').isNotEmpty || (status ?? '').isNotEmpty || values.values.any((v) => v != null && v.isNotEmpty) || period != null;

  @override
  Widget build(BuildContext context) {
    final narrow = MediaQuery.sizeOf(context).width < 700;
    final hint = Theme.of(context).hintColor;
    final pad = padding ?? EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 12);
    final dropdowns = <Widget>[
      for (final f in filters)
        if (f.options.isNotEmpty) _FilterPill(spec: f, value: values[f.key], onChanged: (v) => onFilter?.call(f.key, v)),
      if (showPeriod) _PeriodPill(period: period, onChanged: onPeriod),
    ];
    // Téléphone : les filtres déroulants sont regroupés derrière un bouton « Filtres », la liste reste visible
    final fold = narrow && dropdowns.length > 1;
    final activeCount = values.values.where((v) => v != null && v.isNotEmpty).length + (period != null ? 1 : 0);
    Widget filtersButton() => _FiltersButton(
          count: activeCount,
          onTap: () => showAdaptiveSheet<void>(
            context: context,
            showDragHandle: true,
            isScrollControlled: true,
            builder: (c) => SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
                child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('Filtres'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 14),
                  Wrap(spacing: 8, runSpacing: 10, children: [
                    for (final f in filters)
                      if (f.options.isNotEmpty)
                        _FilterPill(spec: f, value: values[f.key], onChanged: (v) {
                          Navigator.pop(c);
                          onFilter?.call(f.key, v);
                        }),
                    if (showPeriod)
                      _PeriodPill(period: period, onChanged: (p) {
                        Navigator.pop(c);
                        onPeriod?.call(p);
                      }),
                  ]),
                ]),
              ),
            ),
          ),
        );
    return Padding(
      padding: pad,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        if (fold)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(children: [
              if (onSearch != null) Expanded(child: _SearchBox(hint: searchHint, value: search, onChanged: onSearch!)) else const Spacer(),
              const SizedBox(width: 8),
              filtersButton(),
            ]),
          ),
        Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          if (onSearch != null && !fold) SizedBox(width: narrow ? double.infinity : 300, child: _SearchBox(hint: searchHint, value: search, onChanged: onSearch!)),
          if (!fold) ...dropdowns,
          ...trailing,
          if (_active && onReset != null)
            TextButton.icon(
              onPressed: () {
                // Vide aussi le champ de recherche affiché
                _resetTick.value++;
                onSearch?.call('');
                onReset!();
              },
              icon: const Icon(Icons.filter_alt_off_outlined, size: 18),
              label: Text(tr('Réinitialiser')),
            ),
          if (count != null)
            Padding(
              padding: const EdgeInsets.only(left: 4),
              child: Text(tr('$count $countLabel${count! > 1 ? 's' : ''}'), style: TextStyle(color: hint, fontSize: 12.5, fontWeight: FontWeight.w600)),
            ),
        ]),
        if (statuses != null && statuses!.isNotEmpty) ...[
          const SizedBox(height: 10),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(children: [
              for (final e in statuses!.entries)
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(
                    label: Row(mainAxisSize: MainAxisSize.min, children: [
                      Text(tr(e.value)),
                      if (statusCounts?[e.key] != null) ...[
                        const SizedBox(width: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                          decoration: BoxDecoration(
                            color: (status ?? '') == e.key ? Theme.of(context).colorScheme.primary.withValues(alpha: .15) : hint.withValues(alpha: .12),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Text('${statusCounts![e.key]}', style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700)),
                        ),
                      ],
                    ]),
                    selected: (status ?? '') == e.key,
                    onSelected: (_) => onStatus?.call(e.key),
                  ),
                ),
            ]),
          ),
        ],
      ]),
    );
  }
}

/// Bouton « Filtres » (téléphone), avec le nombre de filtres actifs.
class _FiltersButton extends StatelessWidget {
  const _FiltersButton({required this.count, required this.onTap});

  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final on = count > 0;
    final c = Theme.of(context).colorScheme.primary;
    return OutlinedButton.icon(
      onPressed: onTap,
      style: OutlinedButton.styleFrom(
        minimumSize: const Size(0, 46),
        padding: const EdgeInsets.symmetric(horizontal: 14),
        backgroundColor: on ? c.withValues(alpha: .08) : null,
        side: BorderSide(color: on ? c : Theme.of(context).dividerColor),
      ),
      icon: const Icon(Icons.tune_rounded, size: 18),
      label: Text(on ? tr('Filtres ($count)') : tr('Filtres')),
    );
  }
}

class _FilterPill extends StatelessWidget {
  const _FilterPill({required this.spec, required this.value, required this.onChanged});

  final FilterSpec spec;
  final String? value;
  final ValueChanged<String?> onChanged;

  Future<void> _open(BuildContext context, Offset at) async {
    String? picked;
    var cleared = false;
    if (spec.options.length > 12) {
      final r = await showDialog<(String?,)>(context: context, builder: (_) => _SearchPicker(spec: spec, value: value));
      if (r == null) return;
      picked = r.$1;
      cleared = r.$1 == null;
    } else {
      final r = await showMenu<String>(
        context: context,
        position: RelativeRect.fromLTRB(at.dx, at.dy, at.dx + 1, at.dy + 1),
        items: [
          PopupMenuItem(value: '\u0000', child: Text(tr('Tous'), style: const TextStyle(fontWeight: FontWeight.w600))),
          const PopupMenuDivider(),
          for (final e in spec.options.entries)
            PopupMenuItem(
              value: e.key,
              child: Row(children: [
                Icon(e.key == value ? Icons.radio_button_checked : Icons.radio_button_off, size: 18, color: e.key == value ? Theme.of(context).colorScheme.primary : null),
                const SizedBox(width: 10),
                Flexible(child: Text(tr(e.value))),
              ]),
            ),
        ],
      );
      if (r == null) return;
      cleared = r == '\u0000';
      picked = cleared ? null : r;
    }
    onChanged(cleared ? null : picked);
  }

  @override
  Widget build(BuildContext context) {
    final on = value != null && value!.isNotEmpty;
    final primary = Theme.of(context).colorScheme.primary;
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Builder(builder: (ctx) {
      return Material(
        color: on ? primary.withValues(alpha: dark ? .22 : .09) : (dark ? const Color(0xFF162238) : Colors.white),
        shape: StadiumBorder(side: BorderSide(color: on ? primary.withValues(alpha: .5) : (dark ? Colors.white24 : YColors.line))),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTapDown: (d) => _open(ctx, d.globalPosition),
          onTap: () {},
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(spec.icon, size: 16, color: on ? primary : Theme.of(context).hintColor),
              const SizedBox(width: 6),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 220),
                child: Text(
                  tr(on ? '${spec.label} : ${spec.options[value] ?? value}' : spec.label),
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(fontWeight: on ? FontWeight.w700 : FontWeight.w600, fontSize: 13, color: on ? primary : null),
                ),
              ),
              const SizedBox(width: 4),
              if (on)
                InkWell(onTap: () => onChanged(null), child: Icon(Icons.close_rounded, size: 16, color: primary))
              else
                Icon(Icons.expand_more_rounded, size: 18, color: Theme.of(context).hintColor),
            ]),
          ),
        ),
      );
    });
  }
}

class _SearchPicker extends StatefulWidget {
  const _SearchPicker({required this.spec, required this.value});

  final FilterSpec spec;
  final String? value;

  @override
  State<_SearchPicker> createState() => _SearchPickerState();
}

class _SearchPickerState extends State<_SearchPicker> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final q = _q.toLowerCase();
    final list = widget.spec.options.entries.where((e) => q.isEmpty || e.value.toLowerCase().contains(q)).take(200).toList();
    return Dialog(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440, maxHeight: 560),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 18, 18, 8),
            child: Row(children: [
              Icon(widget.spec.icon, color: Theme.of(context).colorScheme.primary),
              const SizedBox(width: 10),
              Expanded(child: Text(tr(widget.spec.label), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17))),
              IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close_rounded)),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18),
            child: TextField(autofocus: true, onChanged: (v) => setState(() => _q = v), decoration: InputDecoration(prefixIcon: const Icon(Icons.search, size: 20), hintText: tr('Rechercher…'))),
          ),
          const SizedBox(height: 8),
          ListTile(leading: const Icon(Icons.clear_all_rounded), title: Text(tr('Tous')), onTap: () => Navigator.pop(context, (null,))),
          const Divider(),
          Flexible(
            child: ListView(shrinkWrap: true, children: [
              for (final e in list)
                ListTile(
                  dense: true,
                  selected: e.key == widget.value,
                  leading: Icon(e.key == widget.value ? Icons.radio_button_checked : Icons.radio_button_off, size: 18),
                  title: Text(tr(e.value)),
                  onTap: () => Navigator.pop(context, (e.key,)),
                ),
            ]),
          ),
        ]),
      ),
    );
  }
}

class _PeriodPill extends StatelessWidget {
  const _PeriodPill({required this.period, required this.onChanged});

  final FilterPeriod? period;
  final ValueChanged<FilterPeriod?>? onChanged;

  @override
  Widget build(BuildContext context) {
    final presets = FilterPeriod.presets();
    final f = DateFormat('d MMM yy', 'fr');
    final spec = FilterSpec('period', 'Période', {for (final p in presets) p.label: p.label, 'custom': 'Personnalisée…'}, icon: Icons.date_range_rounded);
    final label = period == null ? null : (presets.any((p) => p.label == period!.label) ? period!.label : 'custom');
    return _FilterPill(
      spec: FilterSpec(spec.key, spec.label, {...spec.options, if (label == 'custom') 'custom': '${f.format(period!.start)} → ${f.format(period!.end)}'}, icon: spec.icon),
      value: label,
      onChanged: (v) async {
        if (v == null) return onChanged?.call(null);
        if (v == 'custom') {
          final now = DateTime.now();
          final r = await showDateRangePicker(
            context: context,
            firstDate: DateTime(2015),
            lastDate: DateTime(now.year + 5),
            initialDateRange: period == null ? null : DateTimeRange(start: period!.start, end: period!.end),
          );
          if (r != null) onChanged?.call(FilterPeriod('custom', r.start, r.end));
          return;
        }
        onChanged?.call(presets.firstWhere((p) => p.label == v));
      },
    );
  }
}

/// Options d'un filtre à partir d'une liste d'enregistrements (valeurs distinctes, triées par libellé).
Map<String, String> filterOptions(Iterable<Map<String, dynamic>> rows, String key, {String Function(Map<String, dynamic> row)? label}) {
  final out = <String, String>{};
  for (final r in rows) {
    final v = r[key];
    if (v == null || '$v'.isEmpty) continue;
    out['$v'] = label?.call(r) ?? '$v';
  }
  final sorted = out.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()));
  return Map.fromEntries(sorted);
}

/// Champ de recherche arrondi ; se vide quand la page remet la recherche à zéro.
class _SearchBox extends StatefulWidget {
  const _SearchBox({required this.hint, required this.value, required this.onChanged});

  final String hint;
  final String? value;
  final ValueChanged<String> onChanged;

  @override
  State<_SearchBox> createState() => _SearchBoxState();
}

final _resetTick = ValueNotifier<int>(0);

class _SearchBoxState extends State<_SearchBox> {
  late final _c = TextEditingController(text: widget.value ?? '');
  static bool anyText = false;

  @override
  void initState() {
    super.initState();
    _resetTick.addListener(_reset);
  }

  void _reset() {
    if (_c.text.isNotEmpty) {
      _c.clear();
      anyText = false;
      if (mounted) setState(() {});
    }
  }

  @override
  void didUpdateWidget(covariant _SearchBox old) {
    super.didUpdateWidget(old);
    if (widget.value != null && widget.value!.isEmpty && _c.text.isNotEmpty) _c.clear();
  }

  @override
  void dispose() {
    _resetTick.removeListener(_reset);
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    anyText = _c.text.isNotEmpty;
    final dark = Theme.of(context).brightness == Brightness.dark;
    OutlineInputBorder b(Color c, [double w = 1]) => OutlineInputBorder(borderRadius: BorderRadius.circular(24), borderSide: BorderSide(color: c, width: w));
    return TextField(
      controller: _c,
      onChanged: (v) {
        anyText = v.isNotEmpty;
        setState(() {});
        widget.onChanged(v);
      },
      decoration: InputDecoration(
        prefixIcon: const Icon(Icons.search_rounded, size: 20),
        suffixIcon: _c.text.isEmpty
            ? null
            : IconButton(
                icon: const Icon(Icons.close_rounded, size: 18),
                onPressed: () {
                  _c.clear();
                  anyText = false;
                  setState(() {});
                  widget.onChanged('');
                },
              ),
        hintText: trn(widget.hint),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
        border: b(YColors.line),
        enabledBorder: b(dark ? Colors.white24 : YColors.line),
        focusedBorder: b(Theme.of(context).colorScheme.primary, 1.6),
      ),
    );
  }
}
