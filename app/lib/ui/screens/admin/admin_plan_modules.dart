import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';

bool _isFree(Map<String, dynamic> p) => p['is_free'] == true;

/// Socle et formules, en tête de l'écran Modules : pour chaque formule, ses modules et son texte de présentation.
/// Le socle = modules de la formule gratuite, compris d'office dans toutes les formules payantes.
class PlanModulesPanel extends StatefulWidget {
  const PlanModulesPanel({super.key, required this.modules, this.onChanged});

  final List<Map<String, dynamic>> modules;
  final VoidCallback? onChanged;

  @override
  State<PlanModulesPanel> createState() => _PlanModulesPanelState();
}

class _PlanModulesPanelState extends State<PlanModulesPanel> {
  List<Map<String, dynamic>>? _plans;
  Set<String> _socle = {};
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = asMap(await context.read<Session>().api.get('admin/plans'));
      if (!mounted) return;
      setState(() {
        _plans = [for (final p in (d['data'] as List? ?? const [])) asMap(p)];
        _socle = Set<String>.from((d['socle'] as List? ?? const []).map((e) => '$e'));
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _edit(Map<String, dynamic> plan) async {
    final saved = await showFormDialog<bool>(
      context,
      title: _isFree(plan) ? 'Socle — ${plan['name']}' : '${plan['name']}',
      subtitle: _isFree(plan) ? tr('Compris dans toutes les formules') : tr('Le socle est compris d\'office'),
      icon: _isFree(plan) ? Icons.foundation_outlined : Icons.workspace_premium_outlined,
      maxWidth: 640,
      builder: (_) => _PlanModulesEditor(plan: plan, modules: widget.modules, socle: _isFree(plan) ? const {} : _socle),
    );
    if (saved == true) {
      await _load();
      widget.onChanged?.call();
      if (mounted) await context.read<Session>().refreshBootstrap();
    }
  }

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final plans = _plans;
    if (_error != null) return Padding(padding: EdgeInsets.symmetric(horizontal: pad), child: Text(errorText(_error!)));
    if (plans == null) return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
    final sorted = [...plans.where(_isFree), ...plans.where((p) => !_isFree(p))];
    final hint = Theme.of(context).hintColor;
    final names = {for (final m in widget.modules) '${m['key']}': tr('${m['name']}')};
    return Padding(
      padding: EdgeInsets.fromLTRB(pad, 4, pad, 8),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr('Socle et formules'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
        const SizedBox(height: 2),
        Text(tr('Touchez une formule pour choisir ses modules et modifier son texte de présentation. Les modules du socle (formule gratuite) sont compris dans toutes les formules.'),
            style: TextStyle(color: hint, fontSize: 13)),
        const SizedBox(height: 10),
        LayoutBuilder(builder: (c, k) {
          final w = k.maxWidth >= 900 ? (k.maxWidth - 12) / 2 : k.maxWidth;
          return Wrap(spacing: 12, runSpacing: 12, children: [
            for (final p in sorted)
              SizedBox(
                width: w,
                child: Card(
                  margin: EdgeInsets.zero,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                    side: BorderSide(color: _isFree(p) ? YColors.blue.withValues(alpha: .45) : YColors.line, width: _isFree(p) ? 1.5 : 1),
                  ),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: () => _edit(p),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Icon(_isFree(p) ? Icons.foundation_outlined : Icons.workspace_premium_outlined, color: YColors.blue, size: 20),
                          const SizedBox(width: 8),
                          Expanded(child: Text(tr(_isFree(p) ? 'Socle · ${p['name']}' : '${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w800))),
                          if (p['is_active'] != true) StatusChip(tr('Inactive'), color: YColors.muted),
                          const SizedBox(width: 4),
                          const Icon(Icons.edit_outlined, size: 18, color: YColors.blue),
                        ]),
                        const SizedBox(height: 6),
                        Text(
                          '${p['description'] ?? ''}'.trim().isEmpty ? tr('Pas encore de texte de présentation.') : verbatim('${p['description']}'),
                          maxLines: 3,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(color: hint, fontSize: 13, fontStyle: '${p['description'] ?? ''}'.trim().isEmpty ? FontStyle.italic : null),
                        ),
                        const SizedBox(height: 8),
                        Builder(builder: (_) {
                          final own = Set<String>.from((p['modules'] as List? ?? const []).map((e) => '$e'));
                          final extra = _isFree(p) ? own : own.difference(_socle);
                          final shown = extra.take(8).map((k) => names[k] ?? k).toList();
                          return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(
                              tr(_isFree(p) ? '${own.length} module(s) dans le socle' : 'Socle (${_socle.length}) + ${extra.length} module(s)'),
                              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600),
                            ),
                            if (shown.isNotEmpty) ...[
                              const SizedBox(height: 6),
                              Wrap(spacing: 5, runSpacing: 5, children: [
                                for (final n in shown) _MiniChip(n),
                                if (extra.length > shown.length) _MiniChip('+${extra.length - shown.length}'),
                              ]),
                            ],
                          ]);
                        }),
                      ]),
                    ),
                  ),
                ),
              ),
          ]);
        }),
        const SizedBox(height: 18),
        Text(tr('Catalogue des modules'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      ]),
    );
  }
}

class _MiniChip extends StatelessWidget {
  const _MiniChip(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .08), borderRadius: BorderRadius.circular(20)),
        child: Text(verbatim(text), style: const TextStyle(fontSize: 11.5, color: YColors.blue, fontWeight: FontWeight.w600)),
      );
}

/// Modules et présentation d'une formule.
class _PlanModulesEditor extends StatefulWidget {
  const _PlanModulesEditor({required this.plan, required this.modules, required this.socle});

  final Map<String, dynamic> plan;
  final List<Map<String, dynamic>> modules;
  final Set<String> socle;

  @override
  State<_PlanModulesEditor> createState() => _PlanModulesEditorState();
}

class _PlanModulesEditorState extends State<_PlanModulesEditor> {
  late final _desc = TextEditingController(text: '${widget.plan['description'] ?? ''}');
  late final Set<String> _selected = Set<String>.from((widget.plan['modules'] as List? ?? const []).map((e) => '$e'));
  final _search = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _desc.dispose();
    _search.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('admin/plans/${widget.plan['id']}', {
        'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
        // les modules du socle restent hors de la liste propre des formules payantes
        'modules': _selected.difference(widget.socle).toList(),
      });
      if (mounted) {
        toast(context, tr('Formule enregistrée.'));
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final q = _search.text.trim().toLowerCase();
    final list = widget.modules.where((m) => m['is_core'] != true && (q.isEmpty || tr('${m['name']}').toLowerCase().contains(q) || '${m['key']}'.contains(q))).toList();
    final byCat = <String, List<Map<String, dynamic>>>{};
    for (final m in list) {
      byCat.putIfAbsent('${m['category'] ?? 'Autres'}', () => []).add(m);
    }
    final count = _selected.union(widget.socle).length;
    return Column(children: [
      Expanded(
        child: ListView(padding: const EdgeInsets.fromLTRB(20, 8, 20, 16), children: [
          Field(
            label: tr('Texte de présentation'),
            child: TextField(controller: _desc, maxLines: 4, minLines: 2, decoration: InputDecoration(hintText: tr('Ex. : L\'essentiel pour gérer vos ventes, vos clients et votre équipe.'))),
          ),
          Text(tr('Affiché sur la page des tarifs, dans la boutique et à l\'inscription.'), style: TextStyle(color: hint, fontSize: 12)),
          const SizedBox(height: 16),
          Row(children: [
            Expanded(child: Text(tr('Modules compris : $count'), style: const TextStyle(fontWeight: FontWeight.w800))),
            TextButton(onPressed: () => setState(() => _selected.addAll(list.map((m) => '${m['key']}'))), child: Text(tr('Tout cocher'))),
            TextButton(onPressed: () => setState(() => _selected.removeAll(list.map((m) => '${m['key']}'))), child: Text(tr('Tout décocher'))),
          ]),
          if (widget.socle.isNotEmpty)
            Text(tr('Les ${widget.socle.length} modules du socle (cadenas) sont compris d\'office : modifiez-les dans la carte Socle.'), style: TextStyle(color: hint, fontSize: 12.5)),
          const SizedBox(height: 8),
          TextField(
            controller: _search,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: tr('Rechercher un module'), isDense: true),
          ),
          const SizedBox(height: 6),
          for (final cat in byCat.entries) ...[
            Padding(padding: const EdgeInsets.fromLTRB(2, 12, 2, 2), child: Text(tr(cat.key), style: TextStyle(fontWeight: FontWeight.w700, color: hint, fontSize: 12.5))),
            for (final m in cat.value)
              Builder(builder: (_) {
                final k = '${m['key']}';
                final locked = widget.socle.contains(k);
                return CheckboxListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: locked || _selected.contains(k),
                  onChanged: locked ? null : (v) => setState(() => v == true ? _selected.add(k) : _selected.remove(k)),
                  title: Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text(tr('${m['description'] ?? ''}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                  secondary: locked ? Tooltip(message: tr('Compris dans le socle'), child: const Icon(Icons.lock_outline, size: 18, color: YColors.muted)) : null,
                );
              }),
          ],
        ]),
      ),
      const Divider(height: 1),
      Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
        child: Row(children: [
          Expanded(child: Text(tr('Les sociétés de cette formule voient le changement immédiatement.'), style: TextStyle(color: hint, fontSize: 12))),
          const SizedBox(width: 12),
          FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer'))),
        ]),
      ),
    ]);
  }
}
