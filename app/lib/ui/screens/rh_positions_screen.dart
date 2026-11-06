import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../i18n/i18n.dart';
import '../../pdf/rh_document_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'talents_screen.dart' show RhDeadlinesCard;
import 'rh_screen.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ===========================================================================
// Référentiel : fiches de poste et catégories (synchronisés, disponibles hors ligne)
// ===========================================================================

List<Map<String, dynamic>> rhPositions({bool all = false}) => LocalDb.hasEntity('rh_positions')
    ? (LocalDb.all('rh_positions').where((p) => all || p['active'] != false).toList()..sort((a, b) => '${a['title']}'.toLowerCase().compareTo('${b['title']}'.toLowerCase())))
    : [];

List<Map<String, dynamic>> rhCategories({bool all = false}) => LocalDb.hasEntity('rh_categories')
    ? (LocalDb.all('rh_categories').where((c) => all || c['active'] != false).toList()
      ..sort((a, b) {
        final s = toDouble(a['sort']).compareTo(toDouble(b['sort']));
        return s != 0 ? s : '${a['code']}'.compareTo('${b['code']}');
      }))
    : [];

Map<String, dynamic>? rhCategoryByCode(String? code) {
  if (code == null || code.trim().isEmpty) return null;
  final c = code.trim().toLowerCase();
  return rhCategories(all: true).where((x) => '${x['code']}'.trim().toLowerCase() == c).firstOrNull;
}

/// Service du salarié : celui de sa fiche, sinon celui de son poste.
String rhDepartmentOf(Map e) {
  final d = '${e['department'] ?? ''}'.trim();
  if (d.isNotEmpty) return d;
  final p = e['position_id'] == null ? null : LocalDb.get('rh_positions', '${e['position_id']}');
  final pd = '${p?['department'] ?? ''}'.trim();
  return pd.isEmpty ? 'Sans service' : pd;
}

/// Salaire de base sous le minimum de la catégorie du salarié (null si pas de catégorie ou pas de minimum).
double? rhUnderMinimum(Map e) {
  final cat = rhCategoryByCode('${e['category'] ?? ''}');
  final min = toDouble(cat?['min_salary']);
  if (min <= 0) return null;
  final base = toDouble(e['base_salary']);
  return base < min ? min - base : null;
}

List<Map<String, dynamic>> _occupants(String positionId) => rhEmployees().where((e) => '${e['position_id'] ?? ''}' == positionId).toList();

Listenable _rhListenable() => Listenable.merge([
      LocalDb.listen('rh_employees'),
      if (LocalDb.hasEntity('rh_departments')) LocalDb.listen('rh_departments'),
      if (LocalDb.hasEntity('rh_positions')) LocalDb.listen('rh_positions'),
      if (LocalDb.hasEntity('rh_categories')) LocalDb.listen('rh_categories'),
    ]);

const _deptColors = [Color(0xFF1F6FEB), Color(0xFF7C3AED), Color(0xFF0EA5E9), Color(0xFF12A150), Color(0xFFF59E0B), Color(0xFFDB2777), Color(0xFF0D9488), Color(0xFFEA580C)];

Color _deptColor(String d) => _deptColors[d.codeUnits.fold<int>(0, (a, c) => a + c) % _deptColors.length];

double _pad(BuildContext context) => MediaQuery.of(context).size.width < 700 ? 16 : 24;

// ===========================================================================
// Effectifs
// ===========================================================================

class RhEffectifsTab extends StatelessWidget {
  const RhEffectifsTab({super.key});

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ListenableBuilder(
      listenable: _rhListenable(),
      builder: (context, _) {
        final staff = rhEmployees();
        if (staff.isEmpty) {
          return EmptyState(icon: Icons.groups_2_outlined, title: tr('Aucun salarié'), message: tr('Les effectifs par service apparaissent dès que les fiches salariés sont créées.'));
        }
        final positions = rhPositions();
        final cats = rhCategories();
        // Regroupement par service (sans tenir compte des majuscules)
        final groups = <String, List<Map<String, dynamic>>>{};
        final labels = <String, String>{};
        for (final e in staff) {
          final d = rhDepartmentOf(e);
          final k = d.toLowerCase();
          labels.putIfAbsent(k, () => d);
          (groups[k] ??= []).add(e);
        }
        final depts = groups.keys.toList()..sort((a, b) => groups[b]!.length.compareTo(groups[a]!.length));
        final vacancies = positions.fold<int>(0, (t, p) {
          final h = (p['headcount'] as num?)?.toInt() ?? 0;
          final o = _occupants('${p['id']}').length;
          return t + (h > o ? h - o : 0);
        });
        final under = staff.where((e) => rhUnderMinimum(e) != null).toList();
        final women = staff.where((e) => e['gender'] == 'F').length;
        final men = staff.where((e) => e['gender'] == 'M').length;
        final realDepts = depts.where((k) => k != 'sans service').length;

        return ListView(padding: const EdgeInsets.only(top: 16, bottom: 32), children: [
          StatStrip(tiles: [
            StatTile(label: 'Effectif', value: '${staff.length}', detail: women + men > 0 ? '$women femme(s) · $men homme(s)' : 'salariés en poste', icon: Icons.groups_2_outlined),
            StatTile(label: 'Services', value: '$realDepts', detail: groups['sans service'] == null ? 'tous les salariés sont rattachés' : '${groups['sans service']!.length} salarié(s) sans service', icon: Icons.apartment_outlined, color: const Color(0xFF7C3AED)),
            StatTile(label: 'Postes à pourvoir', value: '$vacancies', detail: positions.isEmpty ? 'créez vos fiches de poste' : '${positions.length} fiche(s) de poste', icon: Icons.person_search_outlined, color: YColors.warning),
            StatTile(label: 'Sous le minimum', value: '${under.length}', detail: cats.isEmpty ? 'renseignez la grille des catégories' : 'salaire < minimum de la catégorie', icon: Icons.warning_amber_rounded, color: under.isEmpty ? YColors.success : YColors.danger),
          ]),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: _pad(context)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (context.read<Session>().can('rh.view') || context.read<Session>().can('rh.manage')) const RhDeadlinesCard(),
              _label(context, 'Effectif par service'),
              _ResponsiveGrid(minWidth: 280, children: [
                for (final k in depts) _DeptCard(name: labels[k]!, staff: groups[k]!, total: staff.length, currency: cur),
              ]),
              if (under.isNotEmpty) ...[
                _label(context, 'Salaires sous le minimum de la catégorie'),
                Card(
                  child: Column(children: [
                    for (final e in under)
                      ListTile(
                        leading: InitialsAvatar(empName(e)),
                        title: Text(tr(empName(e)), style: const TextStyle(fontWeight: FontWeight.w600)),
                        subtitle: Text(tr('${e['job_title'] ?? ''} · catégorie ${e['category']} · minimum ${money(rhCategoryByCode('${e['category']}')!['min_salary'], cur)}')),
                        trailing: StatusChip('${money(e['base_salary'], cur)} (−${money(rhUnderMinimum(e), cur)})', color: YColors.danger),
                        onTap: context.read<Session>().can('rh.manage') ? () => editEmployee(context, e) : null,
                      ),
                  ]),
                ),
              ],
              if (cats.isNotEmpty) ...[
                _label(context, 'Effectif par catégorie'),
                Card(
                  clipBehavior: Clip.antiAlias,
                  child: Column(children: [
                    for (final c in cats)
                      Builder(builder: (context) {
                        final n = staff.where((e) => '${e['category'] ?? ''}'.trim().toLowerCase() == '${c['code']}'.trim().toLowerCase()).length;
                        return ListTile(
                          leading: _CodeBadge('${c['code']}'),
                          title: Text(tr('${c['label'] ?? c['code']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          subtitle: Text(tr([if (c['group'] != null && '${c['group']}'.isNotEmpty) '${c['group']}', if (toDouble(c['min_salary']) > 0) 'minimum ${money(c['min_salary'], cur)}'].join(' · '))),
                          trailing: Text('$n', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                        );
                      }),
                    Builder(builder: (context) {
                      final none = staff.where((e) => rhCategoryByCode('${e['category'] ?? ''}') == null).length;
                      if (none == 0) return const SizedBox.shrink();
                      return ListTile(
                        leading: const _CodeBadge('—'),
                        title: Text(tr('Sans catégorie de la grille'), style: TextStyle(color: Theme.of(context).hintColor)),
                        trailing: Text('$none', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                      );
                    }),
                  ]),
                ),
              ],
              if (positions.isNotEmpty) ...[
                _label(context, 'Postes : occupés et prévus'),
                Card(
                  clipBehavior: Clip.antiAlias,
                  child: Column(children: [
                    for (final p in positions)
                      Builder(builder: (context) {
                        final occ = _occupants('${p['id']}');
                        final h = (p['headcount'] as num?)?.toInt();
                        final (label, color) = h == null || h == 0
                            ? ('${occ.length} occupé(s)', YColors.muted)
                            : occ.length < h
                                ? ('${h - occ.length} à pourvoir', YColors.warning)
                                : occ.length > h
                                    ? ('sureffectif +${occ.length - h}', YColors.danger)
                                    : ('complet', YColors.success);
                        return ListTile(
                          leading: _IconSquare(Icons.work_outline, _deptColor('${p['department'] ?? ''}')),
                          title: Text(tr('${p['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          subtitle: Text(tr([if ('${p['department'] ?? ''}'.isNotEmpty) '${p['department']}', '${occ.length}${h != null && h > 0 ? ' / $h' : ''} occupé(s)'].join(' · '))),
                          trailing: StatusChip(label, color: color),
                          onTap: () => showPositionSheet(context, p),
                        );
                      }),
                  ]),
                ),
              ],
            ]),
          ),
        ]);
      },
    );
  }
}

Widget _label(BuildContext context, String t) => Padding(
      padding: const EdgeInsets.fromLTRB(4, 24, 4, 10),
      child: Text(tr(t).toUpperCase(), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 1.1, color: Theme.of(context).hintColor)),
    );

class _ResponsiveGrid extends StatelessWidget {
  const _ResponsiveGrid({required this.children, this.minWidth = 280});

  final List<Widget> children;
  final double minWidth;

  @override
  Widget build(BuildContext context) => LayoutBuilder(builder: (context, c) {
        final cols = (c.maxWidth / minWidth).floor().clamp(1, 4);
        final w = (c.maxWidth - 12 * (cols - 1)) / cols;
        return Wrap(spacing: 12, runSpacing: 12, children: [for (final ch in children) SizedBox(width: w, child: ch)]);
      });
}

class _IconSquare extends StatelessWidget {
  const _IconSquare(this.icon, this.color);

  final IconData icon;
  final Color color;
  static const double size = 38;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(size * .3)),
        child: Icon(icon, color: color, size: size * .5),
      );
}

class _CodeBadge extends StatelessWidget {
  const _CodeBadge(this.code);

  final String code;

  @override
  Widget build(BuildContext context) => Container(
        width: 48,
        height: 34,
        alignment: Alignment.center,
        decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .10), borderRadius: BorderRadius.circular(10)),
        child: Text(code, maxLines: 1, overflow: TextOverflow.fade, softWrap: false, style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w800, fontSize: 13)),
      );
}

class _DeptCard extends StatelessWidget {
  const _DeptCard({required this.name, required this.staff, required this.total, required this.currency});

  final String name;
  final List<Map<String, dynamic>> staff;
  final int total;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = name == 'Sans service' ? YColors.muted : _deptColor(name);
    final byContract = <String, int>{};
    for (final e in staff) {
      final k = contractTypes['${e['contract_type']}'] ?? '${e['contract_type'] ?? '—'}';
      byContract[k] = (byContract[k] ?? 0) + 1;
    }
    final mass = staff.fold<double>(0, (t, e) => t + toDouble(e['base_salary']));
    final share = total == 0 ? 0.0 : staff.length / total;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _showDeptStaff(context, name, staff),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              _IconSquare(Icons.apartment_outlined, color),
              const SizedBox(width: 12),
              Expanded(child: Text(tr(name), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15), maxLines: 1, overflow: TextOverflow.ellipsis)),
              Text('${staff.length}', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 26, color: color, letterSpacing: -.5)),
            ]),
            const SizedBox(height: 12),
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(value: share, minHeight: 6, color: color, backgroundColor: color.withValues(alpha: .12)),
            ),
            const SizedBox(height: 6),
            Text(tr('${(share * 100).round()} % de l\'effectif · salaires de base ${money(mass, currency)}'), style: TextStyle(color: theme.hintColor, fontSize: 12)),
            const SizedBox(height: 10),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final c in byContract.entries) StatusChip('${c.key} : ${c.value}', color: YColors.blue, icon: Icons.description_outlined),
            ]),
            const SizedBox(height: 10),
            Row(children: [
              for (final e in staff.take(6))
                Padding(padding: const EdgeInsets.only(right: 4), child: Tooltip(message: empName(e), child: InitialsAvatar(empName(e), size: 28))),
              if (staff.length > 6) Text('+${staff.length - 6}', style: TextStyle(color: theme.hintColor, fontWeight: FontWeight.w600)),
            ]),
          ]),
        ),
      ),
    );
  }
}

void _showDeptStaff(BuildContext context, String name, List<Map<String, dynamic>> staff) {
  final cur = context.read<Session>().currency;
  final manage = context.read<Session>().can('rh.manage');
  showFormDialog(context, title: '$name · ${staff.length} salarié(s)', builder: (dc) {
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
      for (final e in staff)
        ListTile(
          leading: InitialsAvatar(empName(e)),
          title: Text(tr(empName(e)), style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Text(tr([e['job_title'], if (e['category'] != null && '${e['category']}'.isNotEmpty) 'cat. ${e['category']}', contractTypes['${e['contract_type']}']].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
          trailing: Text(tr(money(e['base_salary'], cur))),
          onTap: manage
              ? () {
                  Navigator.pop(dc);
                  editEmployee(context, e);
                }
              : null,
        ),
    ]);
  });
}

// ===========================================================================
// Fiches de poste et catégories
// ===========================================================================

class RhPositionsTab extends StatefulWidget {
  const RhPositionsTab({super.key, required this.manage});

  final bool manage;

  @override
  State<RhPositionsTab> createState() => _RhPositionsTabState();
}

class _RhPositionsTabState extends State<RhPositionsTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  int _view = 0; // 0 fiches, 1 organigramme, 2 catégories, 3 départements

  bool _base(Map<String, dynamic> p) {
    final q = _q.toLowerCase();
    if (q.isNotEmpty && !'${p['title']} ${p['department'] ?? ''} ${p['code'] ?? ''}'.toLowerCase().contains(q)) return false;
    if (_f['department'] != null && '${p['department'] ?? ''}'.trim() != _f['department']) return false;
    if (_f['category'] != null && '${p['category_id'] ?? ''}' != _f['category']) return false;
    if (_f['contract_type'] != null && '${p['contract_type'] ?? ''}' != _f['contract_type']) return false;
    if (_f['staffing'] != null) {
      final h = (p['headcount'] as num?)?.toInt() ?? 0;
      final o = _occupants('${p['id']}').length;
      final ok = switch (_f['staffing']) { 'vacant' => h > o, 'empty' => o == 0, _ => h > 0 && o >= h };
      if (!ok) return false;
    }
    return true;
  }

  bool _st(Map<String, dynamic> p, String k) => k.isEmpty || (k == 'active') == (p['active'] != false);

  @override
  Widget build(BuildContext context) {
    if (!LocalDb.hasEntity('rh_positions')) {
      return EmptyState(icon: Icons.sync, title: tr('Synchronisation nécessaire'), message: tr('Mettez à jour le serveur puis synchronisez pour gérer les fiches de poste.'));
    }
    return ListenableBuilder(
      listenable: _rhListenable(),
      builder: (context, _) {
        final narrow = MediaQuery.of(context).size.width < 700;
        return ListView(padding: EdgeInsets.fromLTRB(_pad(context), 16, _pad(context), 32), children: [
          Wrap(spacing: 10, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SegmentedButton<int>(
              segments: [
                ButtonSegment(value: 0, icon: const Icon(Icons.work_outline, size: 18), label: Text(tr('Fiches de poste'))),
                ButtonSegment(value: 1, icon: const Icon(Icons.account_tree_outlined, size: 18), label: Text(tr(narrow ? 'Organi.' : 'Organigramme'))),
                ButtonSegment(value: 3, icon: const Icon(Icons.apartment_outlined, size: 18), label: Text(tr(narrow ? 'Départ.' : 'Départements'))),
                ButtonSegment(value: 2, icon: const Icon(Icons.layers_outlined, size: 18), label: Text(tr('Catégories'))),
              ],
              selected: {_view},
              showSelectedIcon: false,
              onSelectionChanged: (s) => setState(() => _view = s.first),
            ),
          ]),
          const SizedBox(height: 16),
          if (_view == 0)
            Builder(builder: (context) {
              final all = rhPositions(all: true);
              if (all.isEmpty) return const SizedBox.shrink();
              return FilterBar(
                padding: const EdgeInsets.only(bottom: 12),
                onSearch: (v) => setState(() => _q = v),
                search: _q,
                searchHint: 'Intitulé, service, code…',
                statuses: const {'': 'Toutes', 'active': 'Actives', 'inactive': 'Inactives'},
                status: _status,
                onStatus: (v) => setState(() => _status = v),
                statusCounts: {for (final k in ['', 'active', 'inactive']) k: all.where((p) => _base(p) && _st(p, k)).length},
                filters: [
                  FilterSpec('department', 'Service', filterOptions([for (final p in all) {'d': '${p['department'] ?? ''}'.trim()}], 'd'), icon: Icons.apartment_outlined),
                  FilterSpec('category', 'Catégorie', {for (final c in rhCategories(all: true)) '${c['id']}': '${c['code'] ?? ''} ${c['name'] ?? ''}'.trim()}, icon: Icons.layers_outlined),
                  FilterSpec('contract_type', 'Contrat', contractTypes, icon: Icons.description_outlined),
                  const FilterSpec('staffing', 'Effectif', {'vacant': 'Postes à pourvoir', 'full': 'Pourvus', 'empty': 'Sans titulaire'}, icon: Icons.person_search_outlined),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                count: all.where((p) => _base(p) && _st(p, _status)).length,
                countLabel: 'fiche',
                onReset: () => setState(() => (_q = '', _status = '', _f = {})),
              );
            }),
          switch (_view) {
            1 => const _OrgChart(),
            2 => _CategoriesView(manage: widget.manage),
            3 => _DepartmentsView(manage: widget.manage),
            _ => _PositionsView(manage: widget.manage, test: (p) => _base(p) && _st(p, _status)),
          },
        ]);
      },
    );
  }
}

class _PositionsView extends StatelessWidget {
  const _PositionsView({required this.manage, required this.test});

  final bool manage;
  final bool Function(Map<String, dynamic>) test;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final all = rhPositions(all: true);
    final list = all.where(test).toList();
    if (all.isEmpty) {
      return EmptyState(
        icon: Icons.work_outline,
        title: tr('Aucune fiche de poste'),
        message: tr('Décrivez chaque poste : missions, compétences, catégorie, rattachement et effectif prévu. Les fiches salariés s\'y rattachent.'),
        action: manage ? FilledButton.icon(onPressed: () => editPosition(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle fiche de poste'))) : null,
      );
    }
    final byDept = <String, List<Map<String, dynamic>>>{};
    for (final p in list) {
      final d = '${p['department'] ?? ''}'.trim();
      (byDept[d.isEmpty ? 'Sans service' : d] ??= []).add(p);
    }
    final depts = byDept.keys.toList()..sort((a, b) => a == 'Sans service' ? 1 : (b == 'Sans service' ? -1 : a.compareTo(b)));
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final d in depts) ...[
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 8, 4, 10),
          child: Row(children: [
            Container(width: 10, height: 10, decoration: BoxDecoration(color: _deptColor(d), shape: BoxShape.circle)),
            const SizedBox(width: 8),
            Text(tr(d), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
            const SizedBox(width: 8),
            Text('${byDept[d]!.length}', style: TextStyle(color: Theme.of(context).hintColor)),
          ]),
        ),
        _ResponsiveGrid(minWidth: 300, children: [for (final p in byDept[d]!) _PositionCard(p, currency: cur)]),
        const SizedBox(height: 12),
      ],
      if (list.isEmpty) Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(tr('Aucune fiche ne correspond.'), style: TextStyle(color: Theme.of(context).hintColor)))),
    ]);
  }
}

class _PositionCard extends StatelessWidget {
  const _PositionCard(this.p, {required this.currency});

  final Map<String, dynamic> p;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final occ = _occupants('${p['id']}');
    final h = (p['headcount'] as num?)?.toInt();
    final cat = p['category_id'] == null ? null : LocalDb.get('rh_categories', '${p['category_id']}');
    final parent = p['parent_id'] == null ? null : LocalDb.get('rh_positions', '${p['parent_id']}');
    final range = [p['salary_min'], p['salary_max']].where((x) => x != null && toDouble(x) > 0).map((x) => money(x, currency)).join(' – ');
    final inactive = p['active'] == false;
    return Opacity(
      opacity: inactive ? .55 : 1,
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => showPositionSheet(context, p),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                _IconSquare(Icons.work_outline, _deptColor('${p['department'] ?? ''}')),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${p['title']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5), maxLines: 1, overflow: TextOverflow.ellipsis),
                    if (parent != null) Text(tr('Rattaché à : ${parent['title']}'), style: TextStyle(color: theme.hintColor, fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis),
                  ]),
                ),
                if (cat != null) _CodeBadge('${cat['code']}'),
              ]),
              const SizedBox(height: 10),
              if ('${p['purpose'] ?? p['missions'] ?? ''}'.trim().isNotEmpty)
                Text(tr('${p['purpose'] ?? p['missions']}'.trim().split('\n').first), style: TextStyle(color: theme.hintColor, fontSize: 12.5), maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 10),
              Wrap(spacing: 6, runSpacing: 6, children: [
                StatusChip('${occ.length}${h != null && h > 0 ? ' / $h' : ''} occupé(s)', color: h != null && h > occ.length ? YColors.warning : YColors.success, icon: Icons.person_outline),
                if (range.isNotEmpty) StatusChip(range, color: YColors.blue, icon: Icons.payments_outlined),
                if (p['contract_type'] != null) StatusChip(contractTypes['${p['contract_type']}'] ?? '${p['contract_type']}', color: const Color(0xFF7C3AED)),
                if (inactive) const StatusChip('Inactive'),
              ]),
              if (occ.isNotEmpty) ...[
                const SizedBox(height: 10),
                Row(children: [
                  for (final e in occ.take(5)) Padding(padding: const EdgeInsets.only(right: 4), child: Tooltip(message: empName(e), child: InitialsAvatar(empName(e), size: 26))),
                  if (occ.length > 5) Text('+${occ.length - 5}', style: TextStyle(color: theme.hintColor)),
                ]),
              ],
            ]),
          ),
        ),
      ),
    );
  }
}

/// Organigramme : arbre des postes par rattachement, avec leurs titulaires.
class _OrgChart extends StatelessWidget {
  const _OrgChart();

  @override
  Widget build(BuildContext context) {
    final all = rhPositions();
    if (all.isEmpty) return EmptyState(icon: Icons.account_tree_outlined, title: tr('Pas encore d\'organigramme'), message: tr('Créez des fiches de poste et indiquez pour chacune le poste du supérieur hiérarchique.'));
    final ids = all.map((p) => '${p['id']}').toSet();
    final roots = all.where((p) => p['parent_id'] == null || !ids.contains('${p['parent_id']}')).toList();
    final children = <String, List<Map<String, dynamic>>>{};
    for (final p in all) {
      if (p['parent_id'] != null && ids.contains('${p['parent_id']}')) (children['${p['parent_id']}'] ??= []).add(p);
    }
    Widget node(Map<String, dynamic> p, int depth) {
      final occ = _occupants('${p['id']}');
      final kids = children['${p['id']}'] ?? const [];
      final color = _deptColor('${p['department'] ?? ''}');
      return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: EdgeInsets.only(left: depth * 28.0, bottom: 8),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (depth > 0) Padding(padding: const EdgeInsets.only(top: 14, right: 6), child: Icon(Icons.subdirectory_arrow_right, size: 18, color: Theme.of(context).hintColor)),
            Expanded(
              child: Card(
                clipBehavior: Clip.antiAlias,
                child: InkWell(
                  onTap: () => showPositionSheet(context, p),
                  child: Container(
                    decoration: BoxDecoration(border: Border(left: BorderSide(color: color, width: 4))),
                    padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                    child: Row(children: [
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr('${p['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                          Text(
                            tr(occ.isEmpty ? 'Poste vacant${p['department'] != null ? ' · ${p['department']}' : ''}' : occ.map(empName).join(', ')),
                            style: TextStyle(color: occ.isEmpty ? YColors.warning : Theme.of(context).hintColor, fontSize: 12.5),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ]),
                      ),
                      if (kids.isNotEmpty) StatusChip('${kids.length} rattaché(s)', color: color),
                    ]),
                  ),
                ),
              ),
            ),
          ]),
        ),
        for (final k in kids) node(k, depth + 1),
      ]);
    }

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [for (final r in roots) node(r, 0)]);
  }
}

class _CategoriesView extends StatelessWidget {
  const _CategoriesView({required this.manage});

  final bool manage;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final cats = rhCategories(all: true);
    final staff = rhEmployees();
    if (cats.isEmpty) {
      return EmptyState(
        icon: Icons.layers_outlined,
        title: tr('Aucune catégorie'),
        message: tr('La grille des catégories professionnelles de votre convention collective, avec le salaire minimum de chacune : l\'application signale les salaires en dessous.'),
        action: manage
            ? Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center, children: [
                FilledButton.icon(onPressed: () => _loadTemplate(context), icon: const Icon(Icons.auto_awesome_outlined), label: Text(tr('Charger une grille type'))),
                OutlinedButton.icon(onPressed: () => editCategory(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle catégorie'))),
              ])
            : null,
      );
    }
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final c in cats) {
      (groups['${c['group'] ?? ''}'.trim().isEmpty ? 'Autres' : '${c['group']}'.trim()] ??= []).add(c);
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (manage)
        Align(
          alignment: Alignment.centerRight,
          child: FilledButton.tonalIcon(onPressed: () => editCategory(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle catégorie'))),
        ),
      for (final g in groups.entries) ...[
        _label(context, g.key),
        Card(
          clipBehavior: Clip.antiAlias,
          child: Column(children: [
            for (final c in g.value)
              Builder(builder: (context) {
                final n = staff.where((e) => '${e['category'] ?? ''}'.trim().toLowerCase() == '${c['code']}'.trim().toLowerCase()).length;
                final under = staff.where((e) => '${e['category'] ?? ''}'.trim().toLowerCase() == '${c['code']}'.trim().toLowerCase() && rhUnderMinimum(e) != null).length;
                return Opacity(
                  opacity: c['active'] == false ? .5 : 1,
                  child: ListTile(
                    leading: _CodeBadge('${c['code']}'),
                    title: Text(tr('${c['label'] ?? c['code']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text(tr(toDouble(c['min_salary']) > 0 ? 'Salaire minimum ${money(c['min_salary'], cur)}' : 'Pas de salaire minimum renseigné')),
                    trailing: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      if (under > 0) StatusChip('$under sous le minimum', color: YColors.danger),
                      StatusChip('$n salarié(s)', color: YColors.blue, icon: Icons.person_outline),
                    ]),
                    onTap: manage ? () => editCategory(context, c) : null,
                  ),
                );
              }),
          ]),
        ),
      ],
      const SizedBox(height: 12),
      Text(
        tr('Les salaires minimums dépendent de votre convention collective (interprofessionnelle, commerce, BTP…) et de ses avenants : vérifiez-les avant de vous y fier.'),
        style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5),
      ),
    ]);
  }

  /// Grille type à compléter : structure courante d'une convention collective (montants à renseigner).
  Future<void> _loadTemplate(BuildContext context) async {
    final sync = context.read<SyncEngine>();
    const grid = [
      ('Ouvriers et employés', ['1A', '1B', '2A', '2B', '3', '4', '5', '6', '7A', '7B']),
      ('Agents de maîtrise', ['M1', 'M2', 'M3', 'M4', 'M5']),
      ('Cadres', ['P1', 'P2', 'P3', 'P4']),
    ];
    if (!await confirm(context, tr('Charger une grille type ?'), tr('Les catégories courantes (ouvriers et employés 1A à 7B, agents de maîtrise M1 à M5, cadres P1 à P4) sont créées sans montant : renseignez ensuite le salaire minimum de chacune selon votre convention.'))) {
      return;
    }
    var sort = 0;
    for (final g in grid) {
      for (final code in g.$2) {
        sort += 10;
        await sync.save('rh_categories', {'id': newId(), 'code': code, 'label': '${g.$1.replaceAll(RegExp(r's$'), '').replaceAll('Ouvriers et employé', 'Ouvrier / employé')} — $code', 'group': g.$1, 'min_salary': 0, 'sort': sort, 'active': true});
      }
    }
  }
}

// ---------------------------------------------------------------------------
// Fiche de poste : consultation, impression
// ---------------------------------------------------------------------------

String _positionText(Map<String, dynamic> p, String currency) {
  final cat = p['category_id'] == null ? null : LocalDb.get('rh_categories', '${p['category_id']}');
  final parent = p['parent_id'] == null ? null : LocalDb.get('rh_positions', '${p['parent_id']}');
  final subs = rhPositions().where((x) => '${x['parent_id'] ?? ''}' == '${p['id']}').map((x) => '${x['title']}').toList();
  final range = [p['salary_min'], p['salary_max']].where((x) => x != null && toDouble(x) > 0).map((x) => money(x, currency)).join(' à ');
  final b = StringBuffer();
  void section(String title, String? body) {
    if (body == null || body.trim().isEmpty) return;
    b.writeln('**$title**');
    b.writeln(body.trim());
    b.writeln();
  }

  section('Identification', [
    'Intitulé du poste : ${p['title']}',
    if ('${p['code'] ?? ''}'.isNotEmpty) 'Code : ${p['code']}',
    if ('${p['department'] ?? ''}'.isNotEmpty) 'Service : ${p['department']}',
    if (cat != null) 'Catégorie : ${cat['code']}${cat['label'] != null ? ' (${cat['label']})' : ''}',
    if (parent != null) 'Supérieur hiérarchique : ${parent['title']}',
    if (subs.isNotEmpty) 'Postes encadrés : ${subs.join(', ')}',
    if (p['contract_type'] != null) 'Type de contrat : ${contractTypes['${p['contract_type']}'] ?? p['contract_type']}',
    if (range.isNotEmpty) 'Rémunération : $range',
  ].join('\n'));
  section('Raison d\'être du poste', '${p['purpose'] ?? ''}');
  section('Missions et activités principales', '${p['missions'] ?? ''}');
  section('Compétences requises', '${p['skills'] ?? ''}');
  section('Formation et expérience', '${p['requirements'] ?? ''}');
  section('Conditions de travail', '${p['conditions'] ?? ''}');
  return b.toString();
}

Future<void> printPosition(BuildContext context, Map<String, dynamic> p, {Map<String, dynamic>? holder}) async {
  final s = context.read<Session>();
  final st = s.companySettings;
  final bytes = await rhDocumentPdf(
    company: s.company,
    doc: {'type': 'other', 'title': 'Fiche de poste — ${p['title']}', 'date': isoDate(DateTime.now()), 'body': _positionText(p, s.currency)},
    employeeName: holder == null ? '' : empName(holder),
    signatory: st['rh_signatory'] as String?,
    signatoryTitle: st['rh_signatory_title'] as String?,
  );
  await Printing.layoutPdf(name: 'Fiche de poste ${p['title']}', onLayout: (_) async => bytes);
}

Future<void> showPositionSheet(BuildContext context, Map<String, dynamic> p) {
  final s = context.read<Session>();
  final manage = s.can('rh.manage');
  final cur = s.currency;
  return showFormDialog(context, title: '${p['title']}', maxWidth: 720, builder: (dc) {
    final occ = _occupants('${p['id']}');
    final text = _positionText(p, cur);
    final theme = Theme.of(dc);
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
      Wrap(spacing: 8, runSpacing: 8, children: [
        if (manage)
          FilledButton.tonalIcon(
            onPressed: () {
              Navigator.pop(dc);
              editPosition(context, p);
            },
            icon: const Icon(Icons.edit_outlined, size: 18),
            label: Text(tr('Modifier')),
          ),
        OutlinedButton.icon(onPressed: () => printPosition(dc, p), icon: const Icon(Icons.print_outlined, size: 18), label: Text(tr('Imprimer / PDF'))),
      ]),
      const SizedBox(height: 16),
      for (final block in text.split(RegExp(r'\n\s*\n')))
        if (block.trim().isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(block.trim().split('\n').first.replaceAll('**', '')), style: TextStyle(fontWeight: FontWeight.w700, color: theme.colorScheme.primary)),
              const SizedBox(height: 4),
              Text(tr(block.trim().split('\n').skip(1).join('\n')), style: const TextStyle(height: 1.45)),
            ]),
          ),
      Text(tr('Titulaires (${occ.length}${p['headcount'] != null ? ' / ${p['headcount']} prévus' : ''})'), style: TextStyle(fontWeight: FontWeight.w700, color: theme.colorScheme.primary)),
      const SizedBox(height: 6),
      if (occ.isEmpty) Text(tr('Poste vacant.'), style: TextStyle(color: theme.hintColor)),
      for (final e in occ)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: InitialsAvatar(empName(e)),
          title: Text(tr(empName(e))),
          subtitle: Text(tr('${e['matricule'] ?? ''} · ${contractTypes['${e['contract_type']}'] ?? ''}')),
          trailing: IconButton(tooltip: tr('Imprimer sa fiche de poste'), icon: const Icon(Icons.print_outlined, size: 20), onPressed: () => printPosition(dc, p, holder: e)),
        ),
    ]);
  });
}

// ---------------------------------------------------------------------------
// Formulaires
// ---------------------------------------------------------------------------

Future<void> editPosition(BuildContext context, [Map<String, dynamic>? p]) =>
    showFormDialog(context, title: p == null ? 'Nouvelle fiche de poste' : 'Fiche de poste — ${p['title']}', maxWidth: 720, builder: (_) => _PositionForm(p: p));

class _PositionForm extends StatefulWidget {
  const _PositionForm({this.p});

  final Map<String, dynamic>? p;

  @override
  State<_PositionForm> createState() => _PositionFormState();
}

class _PositionFormState extends State<_PositionForm> {
  late final Map<String, dynamic> x = widget.p ?? {};
  late final Map<String, TextEditingController> _c = {
    for (final f in ['title', 'code', 'department', 'purpose', 'missions', 'skills', 'requirements', 'conditions']) f: TextEditingController(text: '${x[f] ?? ''}'),
    for (final f in ['salary_min', 'salary_max', 'headcount']) f: TextEditingController(text: x[f] == null ? '' : '${(x[f] as num).round()}'),
  };
  late String? _cat = x['category_id'] as String?;
  late String? _parent = x['parent_id'] as String?;
  late String? _contract = x['contract_type'] as String?;
  late bool _active = x['active'] != false;
  bool _busy = false;

  Future<void> _save() async {
    if (_c['title']!.text.trim().isEmpty) return toast(context, tr('L\'intitulé est obligatoire.'), error: true);
    setState(() => _busy = true);
    String? t(String f) => _c[f]!.text.trim().isEmpty ? null : _c[f]!.text.trim();
    num? n(String f) => num.tryParse(_c[f]!.text.replaceAll(' ', '').replaceAll(',', '.'));
    try {
      await context.read<SyncEngine>().save('rh_positions', {
        'id': x['id'] ?? newId(),
        for (final f in ['title', 'code', 'department', 'purpose', 'missions', 'skills', 'requirements', 'conditions']) f: t(f),
        'salary_min': n('salary_min'),
        'salary_max': n('salary_max'),
        'headcount': n('headcount')?.toInt(),
        'category_id': _cat,
        'parent_id': _parent,
        'contract_type': _contract,
        'active': _active,
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  Future<void> _delete() async {
    final sync = context.read<SyncEngine>();
    if (!await confirm(context, tr('Supprimer cette fiche de poste ?'), tr('Impossible tant que des salariés l\'occupent ou que d\'autres postes y sont rattachés : désactivez-la plutôt.'), danger: true, ok: tr('Supprimer'))) return;
    try {
      await sync.remove('rh_positions', '${x['id']}');
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Widget _row(List<Widget> c) => MediaQuery.of(context).size.width < 600
      ? Column(children: c)
      : Row(crossAxisAlignment: CrossAxisAlignment.start, children: [for (var i = 0; i < c.length; i++) ...[if (i > 0) const SizedBox(width: 12), Expanded(child: c[i])]]);

  Widget _tf(String f, String label, {int lines = 1, String? hint, bool numeric = false}) => Field(
        label: label,
        child: TextField(
          controller: _c[f],
          minLines: lines,
          maxLines: lines == 1 ? 1 : lines + 6,
          keyboardType: numeric ? TextInputType.number : (lines > 1 ? TextInputType.multiline : null),
          inputFormatters: numeric ? [FilteringTextInputFormatter.allow(RegExp(r'[0-9\s]'))] : null,
          decoration: InputDecoration(hintText: hint == null ? null : trn(hint)),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final cats = rhCategories(all: true);
    final parents = rhPositions(all: true).where((p) => p['id'] != x['id']).toList();
    final depts = {
      for (final p in rhPositions(all: true)) '${p['department'] ?? ''}'.trim(),
      for (final e in rhEmployees(all: true)) '${e['department'] ?? ''}'.trim(),
    }.where((d) => d.isNotEmpty).toList()
      ..sort();
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      _row([_tf('title', 'Intitulé du poste *', hint: 'ex. Comptable principal'), _tf('code', 'Code', hint: 'ex. FIN-02')]),
      _row([
        Field(
          label: tr('Service / département'),
          child: Autocomplete<String>(
            initialValue: TextEditingValue(text: _c['department']!.text),
            optionsBuilder: (v) => depts.where((d) => d.toLowerCase().contains(v.text.toLowerCase())),
            onSelected: (v) => _c['department']!.text = v,
            fieldViewBuilder: (context, ctrl, focus, onSubmit) => TextField(
              controller: ctrl,
              focusNode: focus,
              onChanged: (v) => _c['department']!.text = v,
              decoration: InputDecoration(hintText: tr('ex. Comptabilité')),
            ),
          ),
        ),
        Field(
          label: tr('Catégorie'),
          child: DropdownButtonFormField<String?>(
            isExpanded: true,
            initialValue: cats.any((c) => c['id'] == _cat) ? _cat : null,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr(cats.isEmpty ? 'Aucune — créez la grille dans « Catégories »' : '—'))),
              for (final c in cats) DropdownMenuItem<String?>(value: '${c['id']}', child: Text(tr('${c['code']} · ${c['label'] ?? ''}'), overflow: TextOverflow.ellipsis)),
            ],
            onChanged: (v) => setState(() => _cat = v),
          ),
        ),
      ]),
      _row([
        Field(
          label: tr('Supérieur hiérarchique (poste)'),
          child: DropdownButtonFormField<String?>(
            isExpanded: true,
            initialValue: parents.any((p) => p['id'] == _parent) ? _parent : null,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr('— (haut de l\'organigramme)'))),
              for (final p in parents) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr('${p['title']}'), overflow: TextOverflow.ellipsis)),
            ],
            onChanged: (v) => setState(() => _parent = v),
          ),
        ),
        Field(
          label: tr('Type de contrat habituel'),
          child: DropdownButtonFormField<String?>(
            isExpanded: true,
            initialValue: _contract,
            items: [
              const DropdownMenuItem<String?>(value: null, child: Text('—')),
              for (final e in contractTypes.entries) DropdownMenuItem<String?>(value: e.key, child: Text(tr(e.value))),
            ],
            onChanged: (v) => setState(() => _contract = v),
          ),
        ),
      ]),
      _row([_tf('salary_min', 'Salaire minimum', numeric: true), _tf('salary_max', 'Salaire maximum', numeric: true), _tf('headcount', 'Effectif prévu', numeric: true, hint: 'ex. 2')]),
      _tf('purpose', 'Raison d\'être du poste', lines: 2, hint: 'Pourquoi ce poste existe, en une ou deux phrases'),
      _tf('missions', 'Missions et activités principales', lines: 5, hint: 'Une mission par ligne'),
      _tf('skills', 'Compétences requises', lines: 3, hint: 'Savoir-faire, savoir-être, outils maîtrisés'),
      _tf('requirements', 'Formation et expérience', lines: 2, hint: 'ex. BTS Comptabilité, 3 ans d\'expérience'),
      _tf('conditions', 'Conditions de travail', lines: 2, hint: 'Lieu, horaires, déplacements, équipements'),
      if (widget.p != null) SwitchListTile(contentPadding: EdgeInsets.zero, value: _active, onChanged: (v) => setState(() => _active = v), title: Text(tr('Fiche active'))),
      const SizedBox(height: 8),
      Row(children: [
        if (widget.p != null) TextButton.icon(onPressed: _delete, style: TextButton.styleFrom(foregroundColor: YColors.danger), icon: const Icon(Icons.delete_outline), label: Text(tr('Supprimer'))),
        const Spacer(),
        FilledButton(onPressed: _busy ? null : _save, child: Text(tr(_busy ? 'Enregistrement…' : 'Enregistrer'))),
      ]),
    ]);
  }
}

Future<void> editCategory(BuildContext context, [Map<String, dynamic>? c]) {
  final x = c ?? {};
  final code = TextEditingController(text: '${x['code'] ?? ''}');
  final label = TextEditingController(text: '${x['label'] ?? ''}');
  final group = TextEditingController(text: '${x['group'] ?? ''}');
  final min = TextEditingController(text: toDouble(x['min_salary']) > 0 ? '${toDouble(x['min_salary']).round()}' : '');
  final desc = TextEditingController(text: '${x['description'] ?? ''}');
  var active = x['active'] != false;
  return showFormDialog(context, title: c == null ? 'Nouvelle catégorie' : 'Catégorie ${x['code']}', builder: (dc) {
    return StatefulBuilder(builder: (dc, setS) {
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Row(children: [
          SizedBox(width: 120, child: Field(label: tr('Code *'), child: TextField(controller: code, decoration: InputDecoration(hintText: tr('ex. M2'))))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Libellé'), child: TextField(controller: label, decoration: InputDecoration(hintText: tr('ex. Agent de maîtrise 2'))))),
        ]),
        Field(label: tr('Groupe'), child: TextField(controller: group, decoration: InputDecoration(hintText: tr('Ouvriers et employés, Agents de maîtrise, Cadres…')))),
        Field(
          label: tr('Salaire minimum (salaire catégoriel)'),
          child: TextField(controller: min, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9\s]'))]),
        ),
        Field(label: tr('Description'), child: TextField(controller: desc, minLines: 2, maxLines: 5)),
        if (c != null) SwitchListTile(contentPadding: EdgeInsets.zero, value: active, onChanged: (v) => setS(() => active = v), title: Text(tr('Catégorie active'))),
        Row(children: [
          if (c != null)
            TextButton.icon(
              style: TextButton.styleFrom(foregroundColor: YColors.danger),
              onPressed: () async {
                final sync = dc.read<SyncEngine>();
                if (!await confirm(dc, tr('Supprimer la catégorie ?'), tr('Les salariés gardent le code saisi sur leur fiche.'), danger: true, ok: tr('Supprimer'))) return;
                try {
                  await sync.remove('rh_categories', '${x['id']}');
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.delete_outline),
              label: Text(tr('Supprimer')),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              if (code.text.trim().isEmpty) return toast(dc, tr('Le code est obligatoire.'), error: true);
              try {
                await dc.read<SyncEngine>().save('rh_categories', {
                  'id': x['id'] ?? newId(),
                  'code': code.text.trim(),
                  'label': label.text.trim().isEmpty ? null : label.text.trim(),
                  'group': group.text.trim().isEmpty ? null : group.text.trim(),
                  'min_salary': num.tryParse(min.text.replaceAll(' ', '')) ?? 0,
                  'description': desc.text.trim().isEmpty ? null : desc.text.trim(),
                  'sort': x['sort'] ?? (rhCategories(all: true).length + 1) * 10,
                  'active': active,
                });
                if (dc.mounted) Navigator.pop(dc);
              } catch (e) {
                if (dc.mounted) toast(dc, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]);
    });
  });
}

// ===========================================================================
// Contrats des salariés (droit rh.contracts accordé par l'administrateur)
// ===========================================================================

class RhContractsTab extends StatefulWidget {
  const RhContractsTab({super.key});

  @override
  State<RhContractsTab> createState() => _RhContractsTabState();
}

class _RhContractsTabState extends State<RhContractsTab> {
  late Future<Map<String, dynamic>> _future = _load();
  String _q = '';
  String _source = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  Future<Map<String, dynamic>> _load() async => asMap(await context.read<Session>().api.get('rh/contracts'));

  void _reload() => setState(() => _future = _load());

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return OnlineOnlyNotice(error: snap.error!, onRetry: _reload);
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final d = snap.data!;
        final rows = [for (final r in (d['data'] as List? ?? const [])) asMap(r)];
        final ending = [for (final r in (d['ending_cdd'] as List? ?? const [])) asMap(r)];
        final without = [for (final r in (d['without_contract'] as List? ?? const [])) asMap(r)];
        final q = _q.toLowerCase();
        bool base(Map<String, dynamic> r) =>
            (_source.isEmpty || r['source'] == _source) &&
            (q.isEmpty || '${r['title']} ${r['employee'] ?? ''} ${r['reference'] ?? ''}'.toLowerCase().contains(q)) &&
            (_f['employee'] == null || '${r['employee'] ?? ''}' == _f['employee']) &&
            (_f['title'] == null || '${r['title'] ?? ''}' == _f['title']) &&
            (_f['alert'] == null || '${r['alert'] ?? 'none'}' == _f['alert']) &&
            (_period == null || _period!.contains(r['start_date']));
        bool st(Map<String, dynamic> r, String k) => k.isEmpty || '${r['status']}' == k;
        final shown = rows.where((r) => base(r) && st(r, _status)).toList();
        final statusOpts = <String, String>{'': 'Tous', for (final r in rows) if (r['status'] != null) '${r['status']}': '${r['status_label'] ?? r['status']}'};
        final active = rows.where((r) => r['status'] == 'active' || r['status'] == 'issued').length;
        return RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(padding: const EdgeInsets.only(top: 16, bottom: 32), children: [
            StatStrip(tiles: [
              StatTile(label: 'Contrats de travail', value: '${rows.length}', detail: '$active en vigueur / émis', icon: Icons.description_outlined),
              StatTile(label: 'CDD à échéance', value: '${ending.length}', detail: 'dans les 30 jours ou dépassés', icon: Icons.event_busy_outlined, color: ending.isEmpty ? YColors.success : YColors.warning),
              StatTile(label: 'Sans contrat enregistré', value: '${without.length}', detail: 'salariés en poste', icon: Icons.report_gmailerrorred_outlined, color: without.isEmpty ? YColors.success : YColors.danger),
            ]),
            Padding(
              padding: EdgeInsets.symmetric(horizontal: _pad(context)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (d['juridique'] != true)
                  Card(
                    color: YColors.blue.withValues(alpha: .06),
                    child: ListTile(
                      leading: const Icon(Icons.info_outline, color: YColors.blue),
                      title: Text(tr('Seuls les contrats émis par la RH apparaissent : le module Juridique & contrats n\'est pas actif.')),
                    ),
                  ),
                if (ending.isNotEmpty) ...[
                  _label(context, 'CDD qui se terminent'),
                  Card(
                    child: Column(children: [
                      for (final e in ending)
                        ListTile(
                          leading: InitialsAvatar('${e['employee']}'),
                          title: Text(tr('${e['employee']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          trailing: StatusChip(e['expired'] == true ? 'Terminé le ${dateFr('${e['end_date']}')}' : 'Fin le ${dateFr('${e['end_date']}')}', color: e['expired'] == true ? YColors.danger : YColors.warning),
                        ),
                    ]),
                  ),
                ],
                if (without.isNotEmpty) ...[
                  _label(context, 'Salariés sans contrat enregistré'),
                  Card(
                    child: Column(children: [
                      for (final e in without.take(20))
                        ListTile(
                          leading: InitialsAvatar('${e['employee']}'),
                          title: Text(tr('${e['employee']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          subtitle: Text(tr([e['job_title'], contractTypes['${e['contract_type']}']].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
                        ),
                      if (without.length > 20) Padding(padding: const EdgeInsets.all(12), child: Text(tr('… et ${without.length - 20} autre(s)'))),
                    ]),
                  ),
                ],
                _label(context, 'Registre des contrats'),
                FilterBar(
                  padding: EdgeInsets.zero,
                  onSearch: (v) => setState(() => _q = v),
                  search: _q,
                  searchHint: 'Salarié, contrat, référence…',
                  statuses: statusOpts,
                  status: _status,
                  onStatus: (v) => setState(() => _status = v),
                  statusCounts: {for (final k in statusOpts.keys) k: rows.where((r) => base(r) && st(r, k)).length},
                  filters: [
                    const FilterSpec('source', 'Origine', {'juridique': 'Rédigés par le juriste', 'rh': 'Émis par la RH'}, icon: Icons.source_outlined),
                    FilterSpec('employee', 'Salarié', filterOptions(rows, 'employee'), icon: Icons.person_outline),
                    FilterSpec('title', 'Type de contrat', filterOptions(rows, 'title'), icon: Icons.description_outlined),
                    const FilterSpec('alert', 'Échéance', {'soon': 'Échéance proche', 'expired': 'Échu', 'none': 'Sans alerte'}, icon: Icons.event_busy_outlined),
                  ],
                  values: {..._f, 'source': _source.isEmpty ? null : _source},
                  onFilter: (k, v) => setState(() => k == 'source' ? _source = v ?? '' : _f = {..._f, k: v}),
                  showPeriod: true,
                  period: _period,
                  onPeriod: (p) => setState(() => _period = p),
                  count: shown.length,
                  countLabel: 'contrat',
                  onReset: () => setState(() => (_q = '', _source = '', _status = '', _f = {}, _period = null)),
                ),
                const SizedBox(height: 12),
                if (shown.isEmpty)
                  EmptyState(icon: Icons.description_outlined, title: tr('Aucun contrat'), message: tr('Les contrats de travail rédigés par le juriste (type « Contrat de travail ») et ceux émis depuis les documents RH apparaissent ici.'))
                else
                  Card(
                    clipBehavior: Clip.antiAlias,
                    child: Column(children: [
                      for (final r in shown)
                        ListTile(
                          leading: InitialsAvatar('${r['employee'] ?? r['title']}'),
                          title: Text(tr('${r['employee'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                          subtitle: Text(tr([
                            '${r['title']}',
                            if (r['reference'] != null) '${r['reference']}',
                            if (r['start_date'] != null) 'du ${dateFr('${r['start_date']}')}',
                            if (r['end_date'] != null) 'au ${dateFr('${r['end_date']}')}',
                            r['source'] == 'juridique' ? 'juriste' : 'RH',
                          ].join(' · '))),
                          trailing: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                            if (r['alert'] == 'soon') const StatusChip('Échéance proche', color: YColors.warning),
                            if (r['alert'] == 'expired') const StatusChip('Échu', color: YColors.danger),
                            StatusChip('${r['status_label']}', color: Color(0xFF000000 | (int.tryParse('${r['status_color']}'.replaceAll('#', ''), radix: 16) ?? 0x64748B))),
                          ]),
                          onTap: () => _open(r),
                        ),
                    ]),
                  ),
              ]),
            ),
          ]),
        );
      },
    );
  }

  Future<void> _open(Map<String, dynamic> r) async {
    final s = context.read<Session>();
    Map<String, dynamic> c;
    try {
      c = asMap(asMap(await s.api.get('rh/contracts/${r['source']}/${r['id']}'))['data']);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!mounted) return;
    await showFormDialog(context, title: '${r['title']}', maxWidth: 760, builder: (dc) {
      final body = '${c['body'] ?? ''}'.trim();
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
        Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          StatusChip('${r['status_label']}', color: Color(0xFF000000 | (int.tryParse('${r['status_color']}'.replaceAll('#', ''), radix: 16) ?? 0x64748B))),
          Text(tr('${r['employee'] ?? ''} · ${r['source'] == 'juridique' ? 'rédigé par le juriste' : 'émis par la RH'}'), style: TextStyle(color: Theme.of(dc).hintColor)),
        ]),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          if (body.isNotEmpty)
            OutlinedButton.icon(
              onPressed: () async {
                final bytes = await rhDocumentPdf(
                  company: s.company,
                  doc: {'type': 'contract', 'title': '${c['title']}', 'number': r['reference'], 'date': r['date'], 'body': body, 'status': r['status'] == 'cancelled' ? 'cancelled' : 'issued'},
                  employeeName: '${r['employee'] ?? ''}',
                  signatory: s.companySettings['rh_signatory'] as String?,
                  signatoryTitle: s.companySettings['rh_signatory_title'] as String?,
                );
                await Printing.layoutPdf(name: '${c['title']}', onLayout: (_) async => bytes);
              },
              icon: const Icon(Icons.print_outlined, size: 18),
              label: Text(tr('Imprimer / PDF')),
            ),
          if ('${c['file_url'] ?? ''}'.startsWith('http'))
            FilledButton.tonalIcon(onPressed: () => safeLaunchUrl(Uri.parse('${c['file_url']}')), icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr('Contrat signé'))),
        ]),
        const SizedBox(height: 16),
        if (body.isEmpty)
          Text(tr('Le texte du contrat n\'est pas enregistré dans le logiciel.'), style: TextStyle(color: Theme.of(dc).hintColor))
        else
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(color: Theme.of(dc).colorScheme.surfaceContainerLow, borderRadius: BorderRadius.circular(12)),
            child: SelectableText(body.replaceAll('**', ''), style: const TextStyle(height: 1.5)),
          ),
        if ('${c['notes'] ?? ''}'.trim().isNotEmpty) ...[
          const SizedBox(height: 12),
          Text(tr('Remarques : ${c['notes']}'), style: TextStyle(color: Theme.of(dc).hintColor)),
        ],
        const SizedBox(height: 8),
        Text(tr('Consultation seule : le contrat se modifie dans l\'espace du juriste ou dans les documents RH.'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12)),
      ]);
    });
  }
}


// ===========================================================================
// Départements : chefs et droits de regard sur l'équipe
// ===========================================================================

const deptVisibility = <String, (String, IconData)>{
  'presence': ('Présence, congés et absences', Icons.event_available_outlined),
  'requests': ('Demandes de l\'équipe', Icons.mail_outline),
  'work': ('Travaux : tâches, temps, rapports', Icons.task_alt),
  'personal': ('Coordonnées et pièces d\'identité', Icons.contact_phone_outlined),
  'contract': ('Type de contrat et dates', Icons.description_outlined),
  'salary': ('Salaires et bulletins', Icons.payments_outlined),
  'documents': ('Documents RH et justificatifs', Icons.folder_outlined),
};

List<Map<String, dynamic>> rhDepartments({bool all = false}) => LocalDb.hasEntity('rh_departments')
    ? (LocalDb.all('rh_departments').where((d) => all || d['active'] != false).toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase())))
    : [];

class _DepartmentsView extends StatelessWidget {
  const _DepartmentsView({required this.manage});

  final bool manage;

  @override
  Widget build(BuildContext context) {
    if (!LocalDb.hasEntity('rh_departments')) return EmptyState(icon: Icons.sync, title: tr('Synchronisation nécessaire'));
    final depts = rhDepartments(all: true);
    final staff = rhEmployees();
    final theme = Theme.of(context);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        Expanded(
          child: Text(
            tr('Chaque département a un ou plusieurs chefs. Ils voient leur équipe (collaborateurs et stagiaires) dans « Mon équipe », avec seulement ce que vous cochez ici.'),
            style: TextStyle(color: theme.hintColor, fontSize: 12.5),
          ),
        ),
        if (manage) ...[
          const SizedBox(width: 12),
          FilledButton.tonalIcon(onPressed: () => editDepartment(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau département'))),
        ],
      ]),
      const SizedBox(height: 12),
      if (depts.isEmpty)
        EmptyState(icon: Icons.apartment_outlined, title: tr('Aucun département'), message: tr('Créez vos départements (Marketing, Comptabilité, Développement, Audiovisuel…) et désignez leurs chefs.'))
      else
        _ResponsiveGrid(minWidth: 340, children: [
          for (final d in depts)
            Builder(builder: (context) {
              final members = staff.where((e) => e['department_id'] == d['id']).toList();
              final interns = members.where((e) => e['staff_type'] == 'intern' || e['staff_type'] == 'apprentice').length;
              final heads = [for (final h in (d['head_ids'] as List? ?? const [])) LocalDb.get('rh_employees', '$h')].whereType<Map<String, dynamic>>().toList();
              final vis = asMap(d['visibility']);
              final parent = d['parent_id'] == null ? null : LocalDb.get('rh_departments', '${d['parent_id']}');
              final color = _deptColor('${d['name']}');
              return Opacity(
                opacity: d['active'] == false ? .55 : 1,
                child: Card(
                  clipBehavior: Clip.antiAlias,
                  child: InkWell(
                    onTap: manage ? () => editDepartment(context, d) : null,
                    child: Container(
                      decoration: BoxDecoration(border: Border(top: BorderSide(color: color, width: 4))),
                      padding: const EdgeInsets.all(16),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          _IconSquare(Icons.apartment_outlined, color),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(tr('${d['name']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15.5)),
                              if (parent != null) Text(tr('Rattaché à ${parent['name']}'), style: TextStyle(color: theme.hintColor, fontSize: 12)),
                            ]),
                          ),
                          Text('${members.length}', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 24, color: color)),
                        ]),
                        const SizedBox(height: 10),
                        Row(children: [
                          Icon(Icons.workspace_premium_outlined, size: 16, color: theme.hintColor),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              tr(heads.isEmpty ? 'Aucun chef désigné' : 'Chef : ${heads.map(empName).join(', ')}'),
                              style: TextStyle(fontWeight: FontWeight.w600, color: heads.isEmpty ? YColors.warning : null),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ]),
                        const SizedBox(height: 4),
                        Text(tr('${members.length - interns} collaborateur(s) · $interns stagiaire(s)'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
                        const SizedBox(height: 10),
                        Wrap(spacing: 6, runSpacing: 6, children: [
                          for (final v in deptVisibility.entries)
                            if (vis[v.key] == true) StatusChip(v.value.$1, color: YColors.blue, icon: v.value.$2),
                        ]),
                        if (members.isNotEmpty) ...[
                          const SizedBox(height: 10),
                          Row(children: [
                            for (final e in members.take(7)) Padding(padding: const EdgeInsets.only(right: 4), child: Tooltip(message: empName(e), child: InitialsAvatar(empName(e), size: 26))),
                            if (members.length > 7) Text('+${members.length - 7}', style: TextStyle(color: theme.hintColor)),
                          ]),
                        ],
                      ]),
                    ),
                  ),
                ),
              );
            }),
        ]),
    ]);
  }
}

Future<void> editDepartment(BuildContext context, [Map<String, dynamic>? d]) {
  final x = d ?? {};
  final name = TextEditingController(text: '${x['name'] ?? ''}');
  final code = TextEditingController(text: '${x['code'] ?? ''}');
  final desc = TextEditingController(text: '${x['description'] ?? ''}');
  final heads = <String>{for (final h in (x['head_ids'] as List? ?? const [])) '$h'};
  final vis = <String, bool>{for (final k in deptVisibility.keys) k: asMap(x['visibility'])[k] == true || (d == null && ['presence', 'requests', 'work'].contains(k))};
  String? parent = x['parent_id'] as String?;
  var active = x['active'] != false;
  return showFormDialog(context, title: d == null ? 'Nouveau département' : 'Département ${x['name']}', maxWidth: 640, builder: (dc) {
    return StatefulBuilder(builder: (dc, setS) {
      final staff = rhEmployees();
      final others = rhDepartments(all: true).where((o) => o['id'] != x['id']).toList();
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(flex: 3, child: Field(label: tr('Nom *'), child: TextField(controller: name, decoration: InputDecoration(hintText: tr('ex. Marketing'))))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Code'), child: TextField(controller: code, decoration: InputDecoration(hintText: tr('ex. MKT'))))),
        ]),
        Field(
          label: tr('Rattaché à (département parent)'),
          child: DropdownButtonFormField<String?>(
            isExpanded: true,
            initialValue: others.any((o) => o['id'] == parent) ? parent : null,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr('— Aucun (direction générale)'))),
              for (final o in others) DropdownMenuItem<String?>(value: '${o['id']}', child: Text(tr('${o['name']}'))),
            ],
            onChanged: (v) => setS(() => parent = v),
          ),
        ),
        Field(
          label: tr('Chef(s) du département'),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final e in staff)
              if (heads.contains(e['id']) || e['department_id'] == x['id'] || x['id'] == null || heads.length < 5)
                FilterChip(
                  avatar: InitialsAvatar(empName(e), size: 22),
                  label: Text(tr(empName(e))),
                  selected: heads.contains(e['id']),
                  onSelected: (v) => setS(() => v ? heads.add('${e['id']}') : heads.remove(e['id'])),
                ),
          ]),
        ),
        const SizedBox(height: 4),
        Text(tr('Ce que les chefs voient de leur équipe'), style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 4),
        for (final v in deptVisibility.entries)
          CheckboxListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            secondary: Icon(v.value.$2, size: 20),
            title: Text(tr(v.value.$1)),
            value: vis[v.key],
            onChanged: (b) => setS(() => vis[v.key] = b == true),
          ),
        Field(label: tr('Description'), child: TextField(controller: desc, minLines: 1, maxLines: 4)),
        if (d != null) SwitchListTile(contentPadding: EdgeInsets.zero, value: active, onChanged: (v) => setS(() => active = v), title: Text(tr('Département actif'))),
        Row(children: [
          if (d != null)
            TextButton.icon(
              style: TextButton.styleFrom(foregroundColor: YColors.danger),
              onPressed: () async {
                final sync = dc.read<SyncEngine>();
                if (!await confirm(dc, tr('Supprimer ce département ?'), tr('Impossible tant que des salariés y sont rattachés : désactivez-le plutôt.'), danger: true, ok: tr('Supprimer'))) return;
                try {
                  await sync.remove('rh_departments', '${x['id']}');
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.delete_outline),
              label: Text(tr('Supprimer')),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              if (name.text.trim().isEmpty) return toast(dc, tr('Le nom est obligatoire.'), error: true);
              try {
                await dc.read<SyncEngine>().save('rh_departments', {
                  'id': x['id'] ?? newId(),
                  'name': name.text.trim(),
                  'code': code.text.trim().isEmpty ? null : code.text.trim(),
                  'parent_id': parent,
                  'head_ids': heads.toList(),
                  'visibility': vis,
                  'description': desc.text.trim().isEmpty ? null : desc.text.trim(),
                  'active': active,
                });
                if (dc.mounted) Navigator.pop(dc);
              } catch (e) {
                if (dc.mounted) toast(dc, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]);
    });
  });
}
