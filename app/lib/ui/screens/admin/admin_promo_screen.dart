import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/filter_bar.dart';
import 'admin_common.dart';
import 'admin_comms_screen.dart';

const _mono = TextStyle(fontFamily: 'monospace', fontFamilyFallback: ['Courier New', 'Courier'], fontWeight: FontWeight.w800, letterSpacing: .8);

const _usageLabels = {'any': 'Nouveau ou renouvellement', 'new': 'Nouveaux abonnements', 'renew': 'Renouvellements'};
const _appliesLabels = {'all': 'Tout', 'plan': 'Formules', 'module': 'Modules et packs'};

List<Map<String, dynamic>> _list(Object? v) => [for (final e in (v is List ? v : const [])) asMap(e)];

/// Catalogues renvoyés avec la liste (formules, modules, packs) pour libeller les cibles.
class _Catalog {
  _Catalog(Map<String, dynamic> d)
    : plans = {for (final p in _list(d['plans'])) '${p['code']}': '${p['name']}'},
      modules = {for (final m in _list(d['modules'])) '${m['key']}': '${m['name']}'},
      packs = {for (final p in _list(d['packs'])) '${p['key']}': '${p['name']}'};

  final Map<String, String> plans;
  final Map<String, String> modules;
  final Map<String, String> packs;

  /// « Toutes formules », « Formule Business », « Module CRM », « Pack RH complet »…
  String scope(Map<String, dynamic> p) {
    final applies = '${p['applies_to'] ?? 'all'}';
    final target = '${p['target'] ?? ''}';
    if (applies == 'all') return 'Toutes formules et modules';
    if (applies == 'plan') return target.isEmpty ? 'Toutes formules' : 'Formule ${plans[target] ?? target}';
    if (target.isEmpty) return 'Tous modules et packs';
    if (target.startsWith('pack:')) return _packName(target.substring(5));
    if (modules.containsKey(target)) return 'Module ${modules[target]}';
    if (packs.containsKey(target)) return _packName(target);
    return 'Module $target';
  }

  String _packName(String key) {
    final n = packs[key] ?? key;
    return n.toLowerCase().startsWith('pack') ? n : 'Pack $n';
  }
}

/// État d'un code : (clé, libellé, couleur, icône).
(String, String, Color, IconData) _status(Map<String, dynamic> p) {
  final now = DateTime.now();
  final until = DateTime.tryParse('${p['valid_until'] ?? ''}')?.toLocal();
  final from = DateTime.tryParse('${p['valid_from'] ?? ''}')?.toLocal();
  final max = p['max_uses'] as num?;
  final uses = p['uses'] as num? ?? 0;
  if (p['is_active'] != true) return ('inactive', 'Désactivé', YColors.muted, Icons.pause_circle_outline);
  if (until != null && until.isBefore(now)) return ('expired', 'Expiré', YColors.danger, Icons.event_busy_outlined);
  if (max != null && uses >= max) return ('exhausted', 'Épuisé', YColors.warning, Icons.block_outlined);
  if (from != null && from.isAfter(now)) return ('scheduled', 'Programmé', const Color(0xFF6366F1), Icons.schedule_rounded);
  if (until != null && until.difference(now).inDays < 7) return ('valid', 'Expire bientôt', YColors.warning, Icons.timelapse_rounded);
  return ('valid', 'Valide', YColors.success, Icons.check_circle_outline);
}

String _validity(Map<String, dynamic> p) {
  final until = DateTime.tryParse('${p['valid_until'] ?? ''}')?.toLocal();
  final from = DateTime.tryParse('${p['valid_from'] ?? ''}')?.toLocal();
  final now = DateTime.now();
  if (until == null) return from != null && from.isAfter(now) ? 'Dès le ${dateFr(p['valid_from'])} · sans fin' : 'Sans date de fin';
  final days = until.difference(now).inDays;
  if (until.isBefore(now)) return 'Expiré le ${dateFr(p['valid_until'])}';
  if (from != null && from.isAfter(now)) return 'Du ${dateFr(p['valid_from'])} au ${dateFr(p['valid_until'])}';
  if (days < 1) return 'Expire aujourd\'hui';
  return 'Jusqu\'au ${dateFr(p['valid_until'])} · $days j';
}

String _valueLabel(Map<String, dynamic> p, String currency) {
  final v = toDouble(p['value']);
  if (p['type'] == 'amount') return '−${money(v, currency)}';
  return '−${p['label'] ?? '${v == v.roundToDouble() ? v.round() : v} %'}';
}

Future<void> _copy(BuildContext context, String text, String message) async {
  await Clipboard.setData(ClipboardData(text: text));
  if (context.mounted) toast(context, message);
}

/// Super admin : codes promo sur les formules, les modules et les packs.
class AdminPromoScreen extends StatefulWidget {
  const AdminPromoScreen({super.key});

  @override
  State<AdminPromoScreen> createState() => _AdminPromoScreenState();
}

class _AdminPromoScreenState extends State<AdminPromoScreen> {
  String _q = '';
  String _st = '';
  String? _applies;
  Key _key = UniqueKey();
  String _view = 'promo';

  void _reloadAll() => setState(() => _key = UniqueKey());

  Future<void> _form(Map<String, dynamic> d, {Map<String, dynamic>? promo}) async {
    final created = await showFormDialog<List<Map<String, dynamic>>>(
      context,
      maxWidth: 640,
      title: promo == null ? 'Nouveau code promo' : 'Modifier le code ${promo['code']}',
      subtitle: promo == null ? 'Un code unique ou tout un lot, sur les formules, les modules ou les packs' : null,
      icon: Icons.local_offer_outlined,
      builder: (dc) => _PromoForm(data: d, promo: promo),
    );
    if (created == null || !mounted) return;
    _reloadAll();
    if (created.length > 1) {
      await _showBatch(created);
    } else if (created.isNotEmpty) {
      toast(context, promo == null ? 'Code ${created.first['code']} créé' : 'Code enregistré');
    }
  }

  Future<void> _showBatch(List<Map<String, dynamic>> codes) async {
    final all = codes.map((c) => '${c['code']}').join('\n');
    await showDialog<void>(
      context: context,
      builder: (c) => YAlertDialog(
        icon: const Icon(Icons.auto_awesome_rounded, color: YColors.success),
        title: Text(tr('${codes.length} codes générés')),
        subtitle: codes.first['batch'] == null ? null : 'Lot ${codes.first['batch']}',
        content: SizedBox(
          width: 460,
          height: 340,
          child: Container(
            decoration: BoxDecoration(
              color: Theme.of(c).brightness == Brightness.dark ? Colors.white.withValues(alpha: .03) : const Color(0xFFF7F9FC),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Theme.of(c).dividerColor.withValues(alpha: .4)),
            ),
            child: ListView.builder(
              padding: const EdgeInsets.symmetric(vertical: 6),
              itemCount: codes.length,
              itemBuilder: (_, i) => AnimatedFadeIn(
                index: i.clamp(0, 12),
                child: ListTile(
                  dense: true,
                  leading: Text(tr('${i + 1}'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12)),
                  minLeadingWidth: 24,
                  title: SelectableText(tr('${codes[i]['code']}'), style: _mono),
                  trailing: IconButton(tooltip: tr('Copier'), icon: const Icon(Icons.copy_rounded, size: 18), onPressed: () => _copy(c, '${codes[i]['code']}', 'Code copié')),
                ),
              ),
            ),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
          FilledButton.icon(onPressed: () => _copy(c, all, '${codes.length} codes copiés'), icon: const Icon(Icons.copy_all_rounded), label: Text(tr('Tout copier'))),
        ],
      ),
    );
  }

  Future<void> _redemptions(Map<String, dynamic> p, String currency) async {
    final api = context.read<Session>().api;
    await showDialog<void>(
      context: context,
      builder: (c) => YAlertDialog(
        icon: const Icon(Icons.receipt_long_outlined),
        title: Text(tr('Utilisations de ${p['code']}')),
        subtitle: '${p['uses'] ?? 0} utilisation(s) · ${money(toDouble(p['discount_total']), currency)} de remises',
        content: SizedBox(
          width: 560,
          height: 380,
          child: FutureBuilder<dynamic>(
            future: api.get('admin/promo-codes/${p['id']}/redemptions'),
            builder: (c, snap) {
              if (snap.hasError) {
                return Center(
                  child: Text(errorText(snap.error!), style: const TextStyle(color: YColors.danger)),
                );
              }
              if (!snap.hasData) return const LoadingSkeleton(lines: 5);
              final rows = _list(asMap(snap.data)['data']);
              if (rows.isEmpty) {
                return const EmptyState(icon: Icons.hourglass_empty_rounded, title: 'Pas encore utilisé', message: 'Les commandes payées avec ce code apparaîtront ici.');
              }
              final hint = Theme.of(c).hintColor;
              return ListView.separated(
                itemCount: rows.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (_, i) {
                  final r = rows[i];
                  final company = '${r['company'] ?? 'Société supprimée'}';
                  return AnimatedFadeIn(
                    index: i.clamp(0, 10),
                    child: ListTile(
                      contentPadding: const EdgeInsets.symmetric(horizontal: 4),
                      leading: InitialsAvatar(company, size: 36),
                      title: Text(tr(company), style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(
                        tr('${r['user'] ?? 'Utilisateur inconnu'} · ${dateTimeFr(r['created_at'])}${r['order_id'] == null ? '' : ' · commande n° ${r['order_id']}'}'),
                        style: TextStyle(color: hint, fontSize: 12.5),
                      ),
                      trailing: Text(
                        tr('−${money(toDouble(r['discount']), currency)}'),
                        style: const TextStyle(fontWeight: FontWeight.w800, color: YColors.success),
                      ),
                    ),
                  );
                },
              );
            },
          ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
      ),
    );
  }

  Future<void> _delete(Map<String, dynamic> p) async {
    final used = (p['uses'] as num? ?? 0) > 0;
    final ok = await confirm(
      context,
      used ? 'Désactiver le code ${p['code']} ?' : 'Supprimer le code ${p['code']} ?',
      used ? 'Ce code a déjà été utilisé : il sera désactivé et son historique conservé.' : 'Le code sera supprimé définitivement.',
      ok: used ? 'Désactiver' : 'Supprimer',
      danger: true,
    );
    if (!ok || !mounted) return;
    try {
      final r = asMap(await context.read<Session>().api.delete('admin/promo-codes/${p['id']}'));
      if (!mounted) return;
      toast(context, r['deactivated'] == true ? 'Code déjà utilisé : désactivé' : 'Code supprimé');
      _reloadAll();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _toggle(Map<String, dynamic> p, bool v, VoidCallback reload) async {
    setState(() => p['is_active'] = v);
    try {
      await context.read<Session>().api.put('admin/promo-codes/${p['id']}', {'is_active': v});
      reload();
    } catch (e) {
      if (!mounted) return;
      setState(() => p['is_active'] = !v);
      toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.sizeOf(context).width < 700 ? 16.0 : 24.0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 14, pad, 0),
          child: Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<String>(
              showSelectedIcon: false,
              segments: [
                ButtonSegment(value: 'promo', icon: const Icon(Icons.local_offer_outlined, size: 18), label: Text(tr('Codes promo'))),
                ButtonSegment(value: 'referral', icon: const Icon(Icons.volunteer_activism_outlined, size: 18), label: Text(tr('Parrainage'))),
              ],
              selected: {_view},
              onSelectionChanged: (v) => setState(() => _view = v.first),
            ),
          ),
        ),
        Expanded(
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 260),
            transitionBuilder: pageSwitchTransition,
            child: _view == 'referral' ? const _ReferralAdmin(key: ValueKey('referral')) : KeyedSubtree(key: const ValueKey('promo'), child: _promoView(context)),
          ),
        ),
      ],
    );
  }

  Widget _promoView(BuildContext context) {
    final cur = context.read<Session>().currency;
    return AdminLoader(
      key: _key,
      path: 'admin/promo-codes',
      builder: (context, raw, reload) {
        final d = asMap(raw);
        final all = _list(d['data']);
        final st = asMap(d['stats']);
        final cat = _Catalog(d);
        final pad = MediaQuery.sizeOf(context).width < 700 ? 16.0 : 24.0;
        final items = all
            .where((p) => _st.isEmpty || _status(p).$1 == _st)
            .where((p) => _applies == null || p['applies_to'] == _applies)
            .where((p) => _q.isEmpty || '${p['code']} ${p['description'] ?? ''} ${p['batch'] ?? ''} ${cat.scope(p)}'.toLowerCase().contains(_q))
            .toList();

        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(
            padding: const EdgeInsets.only(bottom: 40),
            children: [
              PageHeader(
                title: tr('Codes promo'),
                subtitle: tr('Remises sur les formules, les modules et les packs, pour les nouveaux abonnements ou les renouvellements'),
                actions: [
                  IconButton.outlined(tooltip: tr('Actualiser'), onPressed: reload, icon: const Icon(Icons.refresh)),
                  FilledButton.icon(onPressed: () => _form(d), icon: const Icon(Icons.add), label: Text(tr('Nouveau code'))),
                ],
              ),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: _Kpis(stats: st, currency: cur, onStatus: (s) => setState(() => _st = s)),
              ),
              const SizedBox(height: 16),
              FilterBar(
                padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
                onSearch: (v) => setState(() => _q = v.toLowerCase().trim()),
                search: _q,
                searchHint: 'Code, description, lot, formule…',
                statuses: const {'': 'Tous', 'valid': 'Valides', 'scheduled': 'Programmés', 'expired': 'Expirés', 'exhausted': 'Épuisés', 'inactive': 'Désactivés'},
                status: _st,
                statusCounts: {
                  '': all.length,
                  for (final k in ['valid', 'scheduled', 'expired', 'exhausted', 'inactive']) k: all.where((p) => _status(p).$1 == k).length,
                },
                onStatus: (v) => setState(() => _st = v),
                filters: [FilterSpec('applies', 'Portée', _appliesLabels, icon: Icons.category_outlined)],
                values: {'applies': _applies},
                onFilter: (k, v) => setState(() => _applies = v),
                count: items.length,
                countLabel: 'code',
                onReset: () => setState(() => (_q = '', _st = '', _applies = null)),
              ),
              if (items.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 20),
                  child: EmptyState(
                    icon: Icons.local_offer_outlined,
                    title: all.isEmpty ? 'Aucun code promo pour l\'instant' : 'Aucun code ne correspond',
                    message: all.isEmpty ? 'Créez un code unique ou générez tout un lot à distribuer.' : 'Modifiez les filtres ou la recherche.',
                    action: all.isEmpty ? FilledButton.icon(onPressed: () => _form(d), icon: const Icon(Icons.add), label: Text(tr('Créer un code'))) : null,
                  ),
                )
              else
                Padding(
                  padding: EdgeInsets.symmetric(horizontal: pad),
                  child: Column(
                    children: [
                      for (var i = 0; i < items.length; i++)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: AnimatedFadeIn(
                            key: ValueKey('promo-${items[i]['id']}'),
                            index: i.clamp(0, 14),
                            child: _PromoRow(
                              promo: items[i],
                              scope: cat.scope(items[i]),
                              currency: cur,
                              onEdit: () => _form(d, promo: items[i]),
                              onDelete: () => _delete(items[i]),
                              onRedemptions: () => _redemptions(items[i], cur),
                              onActive: (v) => _toggle(items[i], v, reload),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Indicateurs
// ---------------------------------------------------------------------------

class _Kpis extends StatelessWidget {
  const _Kpis({required this.stats, required this.currency, required this.onStatus});

  final Map<String, dynamic> stats;
  final String currency;
  final ValueChanged<String> onStatus;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    double n(String k) => toDouble(stats[k]);
    final tiles = <(String, double, String Function(double), String, IconData, Color, String?)>[
      ('Codes', n('total'), (v) => number(v.round()), 'Créés au total', Icons.local_offer_outlined, YColors.blue, ''),
      ('Actifs', n('active'), (v) => number(v.round()), 'Utilisables aujourd\'hui', Icons.verified_outlined, YColors.success, 'valid'),
      ('Utilisations', n('uses'), (v) => number(v.round()), 'Commandes payées avec un code', Icons.shopping_bag_outlined, const Color(0xFF7C3AED), null),
      ('Remises accordées', n('discount_total'), (v) => money(v, currency), 'Montant total déduit', Icons.savings_outlined, YColors.warning, null),
    ];
    return LayoutBuilder(
      builder: (context, cs) {
        final cols = cs.maxWidth > 980
            ? 4
            : cs.maxWidth > 480
            ? 2
            : 1;
        final w = (cs.maxWidth - (cols - 1) * 12) / cols;
        return Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            for (var i = 0; i < tiles.length; i++)
              SizedBox(
                width: w,
                child: AnimatedFadeIn(
                  index: i,
                  child: HoverLift(
                    child: Material(
                      color: Theme.of(context).cardColor,
                      borderRadius: BorderRadius.circular(18),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(18),
                        onTap: tiles[i].$7 == null ? null : () => onStatus(tiles[i].$7!),
                        child: Container(
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(18),
                            border: Border.all(color: Colors.black.withValues(alpha: .06)),
                          ),
                          child: Row(
                            children: [
                              DialogIconBadge(icon: Icon(tiles[i].$5), color: tiles[i].$6, size: 44),
                              const SizedBox(width: 14),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      tr(tiles[i].$1),
                                      style: TextStyle(color: hint, fontSize: 12.5),
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                    CountUp(
                                      value: tiles[i].$2,
                                      format: tiles[i].$3,
                                      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
                                    ),
                                    Text(
                                      tr(tiles[i].$4),
                                      style: TextStyle(color: hint, fontSize: 12),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Ligne d'un code
// ---------------------------------------------------------------------------

class _PromoRow extends StatelessWidget {
  const _PromoRow({required this.promo, required this.scope, required this.currency, required this.onEdit, required this.onDelete, required this.onRedemptions, required this.onActive});

  final Map<String, dynamic> promo;
  final String scope;
  final String currency;
  final VoidCallback onEdit;
  final VoidCallback onDelete;
  final VoidCallback onRedemptions;
  final ValueChanged<bool> onActive;

  @override
  Widget build(BuildContext context) {
    final p = promo;
    final hint = Theme.of(context).hintColor;
    final s = _status(p);
    final active = p['is_active'] == true;
    final uses = (p['uses'] as num? ?? 0).toInt();
    final max = (p['max_uses'] as num?)?.toInt();
    final percent = p['type'] != 'amount';
    final accent = percent ? const Color(0xFF7C3AED) : const Color(0xFF0E9F6E);

    final code = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
            decoration: BoxDecoration(
              color: accent.withValues(alpha: .07),
              borderRadius: BorderRadius.circular(9),
              border: Border.all(color: accent.withValues(alpha: .28), style: BorderStyle.solid),
            ),
            child: Text(
              tr('${p['code']}'),
              style: _mono.copyWith(fontSize: 14.5, color: active ? null : hint),
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ),
        IconButton(
          tooltip: tr('Copier le code'),
          visualDensity: VisualDensity.compact,
          icon: const Icon(Icons.copy_rounded, size: 17),
          onPressed: () => _copy(context, '${p['code']}', 'Code ${p['code']} copié'),
        ),
      ],
    );

    final value = Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [accent, Color.lerp(accent, Colors.white, .25)!]),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        tr(_valueLabel(p, currency)),
        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 14),
      ),
    );

    Widget meta(IconData icon, String text, {Color? color}) => Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 14, color: color ?? hint),
        const SizedBox(width: 4),
        Flexible(
          child: Text(
            tr(text),
            overflow: TextOverflow.ellipsis,
            style: TextStyle(color: color ?? hint, fontSize: 12.5),
          ),
        ),
      ],
    );

    final desc = '${p['description'] ?? ''}'.trim();
    final metas = <Widget>[
      meta(Icons.category_outlined, scope),
      meta(Icons.autorenew_rounded, _usageLabels['${p['usage']}'] ?? '${p['usage']}'),
      meta(Icons.event_outlined, _validity(p), color: s.$1 == 'expired' ? YColors.danger : null),
      if (p['once_per_user'] == true) meta(Icons.person_outline_rounded, '1 fois par client'),
      if (p['batch'] != null) meta(Icons.layers_outlined, 'Lot ${p['batch']}'),
    ];

    final usage = SizedBox(
      width: 130,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            tr(max == null ? '$uses / ∞' : '$uses / $max'),
            style: const TextStyle(fontWeight: FontWeight.w800, fontFeatures: [FontFeature.tabularFigures()]),
          ),
          const SizedBox(height: 4),
          ClipRRect(
            borderRadius: BorderRadius.circular(5),
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: max == null || max == 0 ? (uses > 0 ? .15 : 0) : (uses / max).clamp(0, 1).toDouble()),
              duration: const Duration(milliseconds: 800),
              curve: Curves.easeOutCubic,
              builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 6, color: max != null && uses >= max ? YColors.warning : accent, backgroundColor: accent.withValues(alpha: .10)),
            ),
          ),
          const SizedBox(height: 3),
          Text(
            tr(toDouble(p['discount_total']) > 0 ? '−${money(toDouble(p['discount_total']), currency)}' : 'utilisations'),
            style: TextStyle(color: hint, fontSize: 11.5),
            overflow: TextOverflow.ellipsis,
          ),
        ],
      ),
    );

    final menu = PopupMenuButton<String>(
      tooltip: tr('Actions'),
      onSelected: (v) => switch (v) {
        'edit' => onEdit(),
        'uses' => onRedemptions(),
        'copy' => _copy(context, '${p['code']}', 'Code ${p['code']} copié'),
        'send' => openPromoCampaign(context, p, _valueLabel(p, currency)),
        _ => onDelete(),
      },
      itemBuilder: (_) => [
        PopupMenuItem(
          value: 'send',
          child: ListTile(dense: true, leading: const Icon(Icons.forward_to_inbox_outlined, color: YColors.blue), title: Text(tr('Envoyer par e-mail aux clients'))),
        ),
        PopupMenuItem(
          value: 'edit',
          child: ListTile(dense: true, leading: const Icon(Icons.edit_outlined), title: Text(tr('Modifier'))),
        ),
        PopupMenuItem(
          value: 'uses',
          child: ListTile(dense: true, leading: const Icon(Icons.receipt_long_outlined), title: Text(tr('Voir les utilisations'))),
        ),
        PopupMenuItem(
          value: 'copy',
          child: ListTile(dense: true, leading: const Icon(Icons.copy_rounded), title: Text(tr('Copier le code'))),
        ),
        const PopupMenuDivider(),
        PopupMenuItem(
          value: 'delete',
          child: ListTile(
            dense: true,
            leading: const Icon(Icons.delete_outline, color: YColors.danger),
            title: Text(tr(uses > 0 ? 'Désactiver' : 'Supprimer'), style: const TextStyle(color: YColors.danger)),
          ),
        ),
      ],
    );
    final toggle = Tooltip(
      message: tr(active ? 'Actif' : 'Désactivé'),
      child: Switch(value: active, onChanged: onActive),
    );
    final chip = StatusChip(s.$2, color: s.$3, icon: s.$4);

    return HoverLift(
      child: Material(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onEdit,
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 250),
            padding: const EdgeInsets.fromLTRB(14, 10, 8, 12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: Colors.black.withValues(alpha: .06)),
            ),
            foregroundDecoration: active ? null : BoxDecoration(color: Theme.of(context).cardColor.withValues(alpha: .35), borderRadius: BorderRadius.circular(16)),
            child: LayoutBuilder(
              builder: (context, cs) {
                if (cs.maxWidth < 720) {
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(child: code),
                          toggle,
                          menu,
                        ],
                      ),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          value,
                          const SizedBox(width: 10),
                          Expanded(
                            child: Align(alignment: Alignment.centerLeft, child: chip),
                          ),
                        ],
                      ),
                      if (desc.isNotEmpty) ...[const SizedBox(height: 8), Text(tr(desc), style: const TextStyle(fontWeight: FontWeight.w600))],
                      const SizedBox(height: 8),
                      Wrap(spacing: 12, runSpacing: 6, children: metas),
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          Expanded(child: usage),
                          TextButton.icon(onPressed: onRedemptions, icon: const Icon(Icons.receipt_long_outlined, size: 18), label: Text(tr('Utilisations'))),
                        ],
                      ),
                    ],
                  );
                }
                return Row(
                  children: [
                    Expanded(
                      flex: 5,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Flexible(child: code),
                              const SizedBox(width: 6),
                              chip,
                            ],
                          ),
                          if (desc.isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(left: 2, bottom: 4),
                              child: Text(
                                tr(desc),
                                style: const TextStyle(fontWeight: FontWeight.w600),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          Padding(
                            padding: const EdgeInsets.only(left: 2),
                            child: Wrap(spacing: 14, runSpacing: 6, children: metas),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 14),
                    value,
                    const SizedBox(width: 18),
                    usage,
                    const SizedBox(width: 8),
                    IconButton(tooltip: tr('Voir les utilisations'), onPressed: onRedemptions, icon: const Icon(Icons.receipt_long_outlined)),
                    toggle,
                    menu,
                  ],
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Création / modification
// ---------------------------------------------------------------------------

class _PromoForm extends StatefulWidget {
  const _PromoForm({required this.data, this.promo});

  final Map<String, dynamic> data;
  final Map<String, dynamic>? promo;

  @override
  State<_PromoForm> createState() => _PromoFormState();
}

class _PromoFormState extends State<_PromoForm> {
  late final _Catalog _cat = _Catalog(widget.data);
  late final bool _edit = widget.promo != null;
  bool _batch = false;
  late final _code = TextEditingController(text: '${widget.promo?['code'] ?? ''}');
  final _count = TextEditingController(text: '20');
  final _prefix = TextEditingController(text: 'YOM');
  late final _desc = TextEditingController(text: '${widget.promo?['description'] ?? ''}');
  late final _value = TextEditingController(text: _num(widget.promo?['value']));
  late final _maxUses = TextEditingController(text: widget.promo?['max_uses'] == null ? '' : '${widget.promo!['max_uses']}');
  late String _type = '${widget.promo?['type'] ?? 'percent'}';
  late String _applies = '${widget.promo?['applies_to'] ?? 'all'}';
  late String? _target = _initialTarget();
  late String _usage = '${widget.promo?['usage'] ?? 'any'}';
  late DateTime? _from = DateTime.tryParse('${widget.promo?['valid_from'] ?? ''}')?.toLocal();
  late DateTime? _until = _edit ? DateTime.tryParse('${widget.promo?['valid_until'] ?? ''}')?.toLocal() : _addMonths(DateTime.now(), 3);
  late bool _once = (widget.promo?['once_per_user'] as bool?) ?? true;
  late bool _active = (widget.promo?['is_active'] as bool?) ?? true;
  bool _saving = false;

  static String _num(dynamic v) {
    if (v == null) return '';
    final d = toDouble(v);
    return d == d.roundToDouble() ? '${d.round()}' : '$d';
  }

  static DateTime _addMonths(DateTime d, int m) => DateTime(d.year, d.month + m, d.day);

  /// Une cible de pack est enregistrée « `pack:clé` » pour ne pas la confondre avec un module du même nom.
  String? _initialTarget() {
    final t = '${widget.promo?['target'] ?? ''}';
    if (t.isEmpty) return null;
    if (widget.promo?['applies_to'] == 'module' && !t.startsWith('pack:') && !_cat.modules.containsKey(t) && _cat.packs.containsKey(t)) return 'pack:$t';
    return t;
  }

  @override
  void dispose() {
    for (final c in [_code, _count, _prefix, _desc, _value, _maxUses]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickDate(bool from) async {
    final now = DateTime.now();
    final initial = (from ? _from : _until) ?? (from ? now : _addMonths(now, 3));
    final d = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(now.year - 1),
      lastDate: DateTime(now.year + 5),
      helpText: tr(from ? 'Valable à partir du' : 'Valable jusqu\'au'),
    );
    if (d != null) setState(() => from ? _from = d : _until = d);
  }

  Future<void> _save() async {
    final value = toDouble(_value.text);
    if (value <= 0) return toast(context, 'Indiquez la valeur de la remise', error: true);
    if (_type == 'percent' && value > 100) return toast(context, 'Un pourcentage ne peut pas dépasser 100', error: true);
    if (_from != null && _until != null && _until!.isBefore(_from!)) return toast(context, 'La date de fin précède la date de début', error: true);
    final count = toDouble(_count.text).round();
    if (_batch && (count < 2 || count > 500)) return toast(context, 'Un lot compte de 2 à 500 codes', error: true);
    final body = <String, dynamic>{
      'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
      'type': _type,
      'value': value,
      'applies_to': _applies,
      'target': _applies == 'all' ? null : _target,
      'usage': _usage,
      'valid_from': _from == null ? null : isoDate(_from!),
      'valid_until': _until == null ? null : '${isoDate(_until!)} 23:59:59',
      'max_uses': _maxUses.text.trim().isEmpty ? null : toDouble(_maxUses.text).round(),
      'once_per_user': _once,
      'is_active': _active,
      if (_edit && _code.text.trim().isNotEmpty) 'code': _code.text.trim(),
      if (!_edit && !_batch && _code.text.trim().isNotEmpty) 'code': _code.text.trim(),
      if (!_edit && _batch) ...{'count': count, 'prefix': _prefix.text.trim().isEmpty ? 'YOM' : _prefix.text.trim()},
    };
    setState(() => _saving = true);
    try {
      final api = context.read<Session>().api;
      final r = asMap(_edit ? await api.put('admin/promo-codes/${widget.promo!['id']}', body) : await api.post('admin/promo-codes', body));
      final data = r['data'];
      final created = data is List ? _list(data) : [asMap(data)];
      if (r['batch'] != null) {
        for (final c in created) {
          c['batch'] ??= r['batch'];
        }
      }
      if (mounted) Navigator.pop(context, created);
    } catch (e) {
      if (mounted) {
        setState(() => _saving = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final narrow = MediaQuery.sizeOf(context).width < 560;
    final hint = Theme.of(context).hintColor;
    final codeFmt = [FilteringTextInputFormatter.allow(RegExp(r'[A-Za-z0-9_-]')), TextInputFormatter.withFunction((o, n) => n.copyWith(text: n.text.toUpperCase()))];
    Widget pair(Widget a, Widget b) => narrow
        ? Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [a, b])
        : Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: a),
              const SizedBox(width: 12),
              Expanded(child: b),
            ],
          );

    Widget section(String title, IconData icon, List<Widget> children) => Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 2),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Theme.of(context).dividerColor.withValues(alpha: .35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(icon, size: 17, color: YColors.blue),
              const SizedBox(width: 8),
              Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800)),
            ],
          ),
          const SizedBox(height: 12),
          ...children,
        ],
      ),
    );

    final targetItems = <DropdownMenuItem<String?>>[
      DropdownMenuItem(value: null, child: Text(tr(_applies == 'plan' ? 'Toutes les formules' : 'Tous les modules et packs'))),
      if (_applies == 'plan')
        for (final e in _cat.plans.entries) DropdownMenuItem(value: e.key, child: Text(tr('Formule ${e.value}')))
      else ...[
        for (final e in _cat.packs.entries) DropdownMenuItem(value: 'pack:${e.key}', child: Text(tr(_cat.scope({'applies_to': 'module', 'target': 'pack:${e.key}'})))),
        for (final e in _cat.modules.entries) DropdownMenuItem(value: e.key, child: Text(tr('Module ${e.value}'))),
      ],
    ];
    if (_target != null && !targetItems.any((i) => i.value == _target)) {
      targetItems.add(DropdownMenuItem(value: _target, child: Text(tr(_target!))));
    }

    String dateLabel(DateTime? d, String empty) => d == null ? empty : dateFr(isoDate(d));
    final value = toDouble(_value.text);

    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      shrinkWrap: true,
      children: [
        // Code(s)
        section('Code', Icons.qr_code_2_rounded, [
          if (!_edit) ...[
            SegmentedButton<bool>(
              segments: [
                ButtonSegment(value: false, icon: const Icon(Icons.confirmation_number_outlined), label: Text(tr('Code unique'))),
                ButtonSegment(value: true, icon: const Icon(Icons.layers_outlined), label: Text(tr('Lot de codes'))),
              ],
              selected: {_batch},
              onSelectionChanged: (s) => setState(() => _batch = s.first),
            ),
            const SizedBox(height: 14),
          ],
          AnimatedSize(
            duration: const Duration(milliseconds: 220),
            curve: Curves.easeOutCubic,
            alignment: Alignment.topCenter,
            child: _batch
                ? pair(
                    Field(
                      label: tr('Nombre de codes'),
                      child: TextField(
                        controller: _count,
                        onChanged: (_) => setState(() {}),
                        keyboardType: TextInputType.number,
                        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                        decoration: InputDecoration(helperText: tr('De 2 à 500')),
                      ),
                    ),
                    Field(
                      label: tr('Préfixe'),
                      child: TextField(
                        controller: _prefix,
                        maxLength: 10,
                        style: _mono,
                        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[A-Za-z0-9]')), codeFmt.last],
                        onChanged: (_) => setState(() {}),
                        decoration: InputDecoration(counterText: '', helperText: tr('ex. ${_prefix.text.isEmpty ? 'YOM' : _prefix.text}-7KQ2M9')),
                      ),
                    ),
                  )
                : Field(
                    label: tr('Code'),
                    child: TextField(
                      controller: _code,
                      maxLength: 40,
                      style: _mono,
                      inputFormatters: codeFmt,
                      decoration: InputDecoration(
                        counterText: '',
                        hintText: tr(_edit ? '' : 'Laisser vide pour générer'),
                        prefixIcon: const Icon(Icons.tag_rounded, size: 20),
                        helperText: tr(_edit ? 'Lettres, chiffres, tirets' : 'Personnalisé (ex. RENTREE25) ou généré automatiquement'),
                      ),
                    ),
                  ),
          ),
          Field(
            label: tr('Description (interne)'),
            child: TextField(
              controller: _desc,
              maxLength: 255,
              decoration: InputDecoration(counterText: '', hintText: tr('ex. Campagne de rentrée, partenaires…')),
            ),
          ),
        ]),

        // Remise
        section('Remise', Icons.percent_rounded, [
          pair(
            Field(
              label: tr('Type'),
              child: SegmentedButton<String>(
                segments: [
                  ButtonSegment(value: 'percent', label: Text(tr('Pourcentage'))),
                  ButtonSegment(value: 'amount', label: Text(tr('Montant'))),
                ],
                selected: {_type},
                onSelectionChanged: (s) => setState(() => _type = s.first),
              ),
            ),
            Field(
              label: tr('Valeur'),
              child: TextField(
                controller: _value,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))],
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(suffixText: _type == 'percent' ? '%' : (cur == 'XOF' || cur == 'XAF' ? 'F CFA' : cur), hintText: tr(_type == 'percent' ? 'ex. 20' : 'ex. 10000')),
              ),
            ),
          ),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 200),
            child: value <= 0
                ? const SizedBox(key: ValueKey('none'), width: double.infinity)
                : Padding(
                    key: ValueKey('$_type$value'),
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Row(
                      children: [
                        const Icon(Icons.info_outline_rounded, size: 16, color: YColors.blue),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            tr(
                              _type == 'percent'
                                  ? 'Exemple : sur ${money(50000, cur)}, le client paie ${money(50000 - (50000 * value.clamp(0, 100) / 100).roundToDouble(), cur)}.'
                                  : 'Montant déduit du total, sans jamais le rendre négatif.',
                            ),
                            style: TextStyle(color: hint, fontSize: 12.5),
                          ),
                        ),
                      ],
                    ),
                  ),
          ),
        ]),

        // Portée
        section('S\'applique à', Icons.category_outlined, [
          SegmentedButton<String>(
            showSelectedIcon: !narrow,
            segments: [
              ButtonSegment(value: 'all', label: Text(tr('Tout'))),
              ButtonSegment(value: 'plan', label: Text(tr('Formules'))),
              ButtonSegment(value: 'module', label: Text(tr(narrow ? 'Modules' : 'Modules et packs'))),
            ],
            selected: {_applies},
            onSelectionChanged: (s) => setState(() {
              _applies = s.first;
              _target = null;
            }),
          ),
          const SizedBox(height: 12),
          AnimatedSize(
            duration: const Duration(milliseconds: 220),
            curve: Curves.easeOutCubic,
            alignment: Alignment.topCenter,
            child: _applies == 'all'
                ? const SizedBox(width: double.infinity)
                : Field(
                    label: tr(_applies == 'plan' ? 'Formule précise (facultatif)' : 'Module ou pack précis (facultatif)'),
                    child: DropdownButtonFormField<String?>(key: ValueKey(_applies), initialValue: _target, isExpanded: true, items: targetItems, onChanged: (v) => setState(() => _target = v)),
                  ),
          ),
          Field(
            label: tr('Pour'),
            child: Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final e in const {'new': 'Nouveaux abonnements', 'renew': 'Renouvellements', 'any': 'Les deux'}.entries)
                  ChoiceChip(label: Text(tr(e.value)), selected: _usage == e.key, onSelected: (_) => setState(() => _usage = e.key)),
              ],
            ),
          ),
        ]),

        // Validité
        section('Validité et limites', Icons.event_available_outlined, [
          pair(
            Field(
              label: tr('À partir du'),
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(alignment: Alignment.centerLeft, padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16)),
                onPressed: () => _pickDate(true),
                icon: const Icon(Icons.event_outlined, size: 18),
                label: Row(
                  children: [
                    Expanded(child: Text(tr(dateLabel(_from, 'Dès maintenant')))),
                    if (_from != null) InkWell(onTap: () => setState(() => _from = null), child: const Icon(Icons.close_rounded, size: 16)),
                  ],
                ),
              ),
            ),
            Field(
              label: tr('Jusqu\'au'),
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(alignment: Alignment.centerLeft, padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16)),
                onPressed: () => _pickDate(false),
                icon: const Icon(Icons.event_busy_outlined, size: 18),
                label: Row(
                  children: [
                    Expanded(child: Text(tr(dateLabel(_until, _edit ? 'Sans date de fin' : 'Dans 3 mois')))),
                    if (_until != null && _edit) InkWell(onTap: () => setState(() => _until = null), child: const Icon(Icons.close_rounded, size: 16)),
                  ],
                ),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final m in const [1, 3, 6, 12])
                  ActionChip(
                    avatar: const Icon(Icons.bolt_rounded, size: 16),
                    label: Text(tr(m == 12 ? '1 an' : '$m mois')),
                    onPressed: () => setState(() => _until = _addMonths(_from ?? DateTime.now(), m)),
                  ),
              ],
            ),
          ),
          Field(
            label: tr('Utilisations max'),
            child: TextField(
              controller: _maxUses,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(hintText: tr('illimité'), helperText: tr(_batch ? 'Par code du lot' : 'Toutes sociétés confondues')),
            ),
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('Une seule fois par client')),
            subtitle: Text(tr('Un utilisateur ou une société ne peut l\'utiliser qu\'une fois')),
            value: _once,
            onChanged: (v) => setState(() => _once = v),
          ),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Actif')), value: _active, onChanged: (v) => setState(() => _active = v)),
        ]),

        Row(
          children: [
            const Spacer(),
            TextButton(onPressed: _saving ? null : () => Navigator.pop(context), child: Text(tr('Annuler'))),
            const SizedBox(width: 8),
            FilledButton.icon(
              onPressed: _saving ? null : _save,
              icon: _saving
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Icon(_edit ? Icons.check_rounded : (_batch ? Icons.auto_awesome_rounded : Icons.add_rounded)),
              label: Text(tr(_edit ? 'Enregistrer' : (_batch ? 'Générer ${toDouble(_count.text).round()} codes' : 'Créer le code'))),
            ),
          ],
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Parrainage (super admin) : réglages, indicateurs et meilleurs parrains
// ---------------------------------------------------------------------------

String _num(Object? v) {
  final d = toDouble(v);
  return d == d.roundToDouble() ? '${d.round()}' : d.toStringAsFixed(1).replaceAll('.', ',');
}

class _ReferralAdmin extends StatefulWidget {
  const _ReferralAdmin({super.key});

  @override
  State<_ReferralAdmin> createState() => _ReferralAdminState();
}

class _ReferralAdminState extends State<_ReferralAdmin> {
  Key _key = UniqueKey();

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      key: _key,
      path: 'admin/referrals',
      builder: (context, raw, reload) {
        final d = asMap(raw);
        final st = asMap(d['stats']);
        final top = _list(d['top']);
        final pad = MediaQuery.sizeOf(context).width < 700 ? 16.0 : 24.0;
        final settingsForm = _ReferralSettingsForm(key: ValueKey(d['settings'].toString()), settings: asMap(d['settings']), onSaved: () => setState(() => _key = UniqueKey()));
        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(
            padding: const EdgeInsets.only(bottom: 40),
            children: [
              PageHeader(
                title: tr('Parrainage'),
                subtitle: tr('Les sociétés invitent d\'autres sociétés : remise de bienvenue pour l\'invité, crédit d\'achat pour le parrain'),
                actions: [IconButton.outlined(tooltip: tr('Actualiser'), onPressed: reload, icon: const Icon(Icons.refresh))],
              ),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: _ReferralKpis(stats: st),
              ),
              const SizedBox(height: 16),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: LayoutBuilder(
                  builder: (context, box) {
                    final table = _TopReferrers(top: top);
                    if (box.maxWidth < 980) {
                      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [settingsForm, const SizedBox(height: 16), table]);
                    }
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SizedBox(width: 420, child: settingsForm),
                        const SizedBox(width: 16),
                        Expanded(child: table),
                      ],
                    );
                  },
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _ReferralKpis extends StatelessWidget {
  const _ReferralKpis({required this.stats});

  final Map<String, dynamic> stats;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final dark = Theme.of(context).brightness == Brightness.dark;
    double n(String k) => toDouble(stats[k]);
    String count(double v) => number(v.round());
    String amount(double v) => money(v);
    final tiles = <(String, double, String Function(double), String, IconData, Color)>[
      ('Filleuls', n('referees'), count, 'Sociétés inscrites par invitation', Icons.group_add_outlined, YColors.blue),
      ('Parrains', n('referrers'), count, 'Sociétés ayant invité', Icons.campaign_outlined, const Color(0xFF7C3AED)),
      ('Crédit distribué', n('earned'), amount, 'Gagné par les parrains', Icons.emoji_events_outlined, YColors.warning),
      ('Crédit utilisé', n('spent'), amount, 'Déduit de leurs achats', Icons.shopping_cart_checkout_rounded, YColors.success),
    ];
    return LayoutBuilder(
      builder: (context, cs) {
        final cols = cs.maxWidth > 980 ? 4 : (cs.maxWidth > 480 ? 2 : 1);
        final w = (cs.maxWidth - (cols - 1) * 12) / cols;
        return Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            for (final (i, t) in tiles.indexed)
              SizedBox(
                width: w,
                child: AnimatedFadeIn(
                  index: i,
                  child: HoverLift(
                    child: Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Theme.of(context).cardColor,
                        borderRadius: BorderRadius.circular(18),
                        border: Border.all(color: dark ? Colors.white12 : Colors.black.withValues(alpha: .06)),
                      ),
                      child: Row(
                        children: [
                          DialogIconBadge(icon: Icon(t.$5), color: t.$6, size: 44),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  tr(t.$1),
                                  style: TextStyle(color: hint, fontSize: 12.5),
                                  overflow: TextOverflow.ellipsis,
                                ),
                                FittedBox(
                                  fit: BoxFit.scaleDown,
                                  alignment: Alignment.centerLeft,
                                  child: CountUp(
                                    value: t.$2,
                                    format: t.$3,
                                    style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
                                  ),
                                ),
                                Text(
                                  tr(t.$4),
                                  style: TextStyle(color: hint, fontSize: 12),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _ReferralSettingsForm extends StatefulWidget {
  const _ReferralSettingsForm({super.key, required this.settings, required this.onSaved});

  final Map<String, dynamic> settings;
  final VoidCallback onSaved;

  @override
  State<_ReferralSettingsForm> createState() => _ReferralSettingsFormState();
}

class _ReferralSettingsFormState extends State<_ReferralSettingsForm> {
  late bool _enabled = widget.settings['enabled'] != false;
  late final _reward = TextEditingController(text: _num(widget.settings['reward_percent'] ?? 10));
  late final _welcome = TextEditingController(text: _num(widget.settings['referee_discount'] ?? 10));
  late final _months = TextEditingController(text: '${toDouble(widget.settings['reward_months'] ?? 12).round()}');
  bool _busy = false;

  @override
  void dispose() {
    _reward.dispose();
    _welcome.dispose();
    _months.dispose();
    super.dispose();
  }

  double _val(TextEditingController c) => toDouble(c.text);

  String? _check() {
    final r = _val(_reward), w = _val(_welcome), m = _val(_months);
    if (r < 0 || r > 100) return 'La récompense du parrain doit être comprise entre 0 et 100 %.';
    if (w < 0 || w > 100) return 'La remise de bienvenue doit être comprise entre 0 et 100 %.';
    if (m < 0 || m > 120 || m != m.roundToDouble()) return 'La durée doit être un nombre entier de mois entre 0 et 120.';
    return null;
  }

  String get _preview {
    if (!_enabled) return 'Programme en pause : les inscriptions par lien ne donnent ni remise ni crédit. Le crédit déjà gagné reste utilisable.';
    final w = _val(_welcome), r = _val(_reward), m = _val(_months).round();
    final guest = w > 0 ? 'Le filleul obtient −${_num(w)} % sur son premier achat' : 'Le filleul n\'a pas de remise de bienvenue';
    final period = m > 0 ? 'pendant $m mois après son inscription' : 'sans limite de durée';
    final parrain = r > 0 ? 'le parrain gagne ${_num(r)} % de chacun de ses achats $period, en crédit déduit automatiquement de ses propres achats.' : 'le parrain ne gagne pas de crédit.';
    return '$guest ; $parrain';
  }

  Future<void> _save() async {
    final err = _check();
    if (err != null) return toast(context, tr(err), error: true);
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('admin/referrals/settings', {
        'enabled': _enabled,
        'reward_percent': _val(_reward),
        'referee_discount': _val(_welcome),
        'reward_months': _val(_months).round(),
      });
      if (!mounted) return;
      toast(context, tr('Réglages du parrainage enregistrés'));
      widget.onSaved();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final err = _check();
    InputDecoration deco(String suffix, String help) => InputDecoration(isDense: true, suffixText: tr(suffix), helperText: tr(help), helperMaxLines: 2);
    final digits = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    return SectionCard(
      title: 'Réglages du programme',
      trailing: Icon(Icons.tune_rounded, size: 18, color: theme.colorScheme.primary),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _enabled,
            onChanged: (v) => setState(() => _enabled = v),
            title: Text(tr('Programme actif'), style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(tr(_enabled ? 'Les invitations donnent droit aux remises et au crédit' : 'En pause : aucune nouvelle récompense'), style: TextStyle(color: hint, fontSize: 12.5)),
          ),
          const SizedBox(height: 8),
          AnimatedOpacity(
            duration: const Duration(milliseconds: 200),
            opacity: _enabled ? 1 : .55,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Field(
                  label: 'Récompense du parrain',
                  child: TextField(
                    controller: _reward,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    inputFormatters: digits,
                    onChanged: (_) => setState(() {}),
                    decoration: deco('%', 'Part de chaque achat du filleul créditée au parrain'),
                  ),
                ),
                Field(
                  label: 'Remise de bienvenue du filleul',
                  child: TextField(
                    controller: _welcome,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    inputFormatters: digits,
                    onChanged: (_) => setState(() {}),
                    decoration: deco('%', 'Sur son premier achat payant (non cumulable avec un code promo)'),
                  ),
                ),
                Field(
                  label: 'Durée des récompenses',
                  child: TextField(
                    controller: _months,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    onChanged: (_) => setState(() {}),
                    decoration: deco('mois', 'Après l\'inscription du filleul · 0 = sans limite'),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 4),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 220),
            child: Container(
              key: ValueKey(err ?? _preview),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: (err != null ? YColors.danger : (_enabled ? YColors.success : YColors.muted)).withValues(alpha: .08),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: (err != null ? YColors.danger : (_enabled ? YColors.success : YColors.muted)).withValues(alpha: .3)),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(err != null ? Icons.error_outline_rounded : Icons.record_voice_over_outlined, size: 18, color: err != null ? YColors.danger : (_enabled ? YColors.success : YColors.muted)),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr(err ?? _preview), style: const TextStyle(height: 1.4, fontSize: 13))),
                ],
              ),
            ),
          ),
          const SizedBox(height: 14),
          FilledButton.icon(
            onPressed: _busy || err != null ? null : _save,
            icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.save_outlined),
            label: Text(tr('Enregistrer')),
          ),
        ],
      ),
    );
  }
}

class _TopReferrers extends StatelessWidget {
  const _TopReferrers({required this.top});

  final List<Map<String, dynamic>> top;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return SectionCard(
      title: 'Meilleurs parrains',
      trailing: Icon(Icons.leaderboard_outlined, size: 18, color: Theme.of(context).colorScheme.primary),
      child: top.isEmpty
          ? const EmptyState(
              icon: Icons.emoji_events_outlined,
              title: 'Aucun parrain récompensé',
              message: 'Les sociétés dont les filleuls achètent dans la boutique apparaîtront ici, classées par crédit gagné.',
            )
          : LayoutBuilder(
              builder: (context, box) {
                if (box.maxWidth < 560) {
                  return Column(
                    children: [
                      for (final (i, t) in top.indexed) ...[
                        if (i > 0) const Divider(height: 1),
                        AnimatedFadeIn(
                          index: i.clamp(0, 12),
                          child: ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: _Rank(i + 1),
                            title: Text(
                              '${t['company'] ?? '—'}',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(fontWeight: FontWeight.w700),
                            ),
                            subtitle: Text(tr('${t['referees'] ?? 0} filleul(s) · ${t['paying'] ?? 0} acheteur(s)'), style: TextStyle(color: hint, fontSize: 12.5)),
                            trailing: Text(
                              money(toDouble(t['earned'])),
                              style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w800),
                            ),
                          ),
                        ),
                      ],
                    ],
                  );
                }
                return SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: ConstrainedBox(
                    constraints: BoxConstraints(minWidth: box.maxWidth),
                    child: DataTable(
                      columnSpacing: 18,
                      horizontalMargin: 4,
                      columns: [
                        const DataColumn(label: Text('#')),
                        DataColumn(label: Text(tr('Société'))),
                        DataColumn(numeric: true, label: Text(tr('Filleuls'))),
                        DataColumn(numeric: true, label: Text(tr('Acheteurs'))),
                        DataColumn(numeric: true, label: Text(tr('Crédit gagné'))),
                      ],
                      rows: [
                        for (final (i, t) in top.indexed)
                          DataRow(
                            cells: [
                              DataCell(_Rank(i + 1)),
                              DataCell(
                                Text(
                                  '${t['company'] ?? '—'}',
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(fontWeight: FontWeight.w600),
                                ),
                              ),
                              DataCell(Text(number(toDouble(t['referees']).round()))),
                              DataCell(Text(number(toDouble(t['paying']).round()))),
                              DataCell(
                                Text(
                                  money(toDouble(t['earned'])),
                                  style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w800),
                                ),
                              ),
                            ],
                          ),
                      ],
                    ),
                  ),
                );
              },
            ),
    );
  }
}

class _Rank extends StatelessWidget {
  const _Rank(this.n);

  final int n;

  @override
  Widget build(BuildContext context) {
    final color = switch (n) {
      1 => YColors.yellow,
      2 => const Color(0xFF94A3B8),
      3 => const Color(0xFFD97706),
      _ => YColors.muted,
    };
    return Container(
      width: 28,
      height: 28,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: color.withValues(alpha: n <= 3 ? .22 : .10),
      ),
      child: Text(
        '$n',
        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5, color: n <= 3 ? Color.lerp(color, Colors.black, .35) : color),
      ),
    );
  }
}
