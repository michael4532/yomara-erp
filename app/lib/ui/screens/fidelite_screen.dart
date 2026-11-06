import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const lcTypes = {'earn': 'Achat', 'redeem': 'Utilisation', 'bonus': 'Bonus', 'adjust': 'Ajustement', 'expire': 'Expiration', 'reverse': 'Annulation'};
const giftStatuses = {'active': 'Active', 'used': 'Épuisée', 'expired': 'Expirée', 'cancelled': 'Annulée'};

bool fidActive(Session s) => s.hasModule('fidelite') && LocalDb.hasEntity('lc_cards');
double fidPointValue(Session s) => toDouble(s.companySettings['fid_point_value'] ?? 10) <= 0 ? 10 : toDouble(s.companySettings['fid_point_value'] ?? 10);
int fidMinRedeem(Session s) => toDouble(s.companySettings['fid_min_redeem'] ?? 100).round();

Map<String, dynamic>? loyaltyCardOf(String? partnerId) {
  if (partnerId == null || !LocalDb.hasEntity('lc_cards')) return null;
  return LocalDb.all('lc_cards').where((c) => c['partner_id'] == partnerId).firstOrNull;
}

Map<String, dynamic>? giftCardByCode(String code) {
  if (!LocalDb.hasEntity('gift_cards')) return null;
  final c = code.trim().toUpperCase();
  return LocalDb.all('gift_cards').where((g) => '${g['code']}'.toUpperCase() == c).firstOrNull;
}

bool giftUsable(Map<String, dynamic> g) {
  final exp = DateTime.tryParse('${g['expires_at'] ?? ''}');
  return g['status'] == 'active' && toDouble(g['balance']) > 0 && (exp == null || !exp.isBefore(DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day)));
}

/// Inscription d'un client au programme (fonctionne hors ligne : le numéro est attribué à la synchronisation).
Future<void> enrollCustomer(BuildContext context, String partnerId) async {
  try {
    await context.read<SyncEngine>().save('lc_cards', {'id': newId(), 'partner_id': partnerId, 'points': 0});
    if (context.mounted) toast(context, tr('Client inscrit au programme de fidélité.'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

/// Points du client (caisse, fiches).
class LoyaltyBadge extends StatelessWidget {
  const LoyaltyBadge({super.key, required this.partnerId, this.enroll = true});

  final String partnerId;
  final bool enroll;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    if (!fidActive(s)) return const SizedBox.shrink();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('lc_cards'),
      builder: (context, _, _) {
        final card = loyaltyCardOf(partnerId);
        if (card == null) {
          return enroll
              ? TextButton.icon(onPressed: () => enrollCustomer(context, partnerId), icon: const Icon(Icons.loyalty_outlined, size: 18), label: Text(tr('Inscrire au programme')))
              : const SizedBox.shrink();
        }
        final pts = ((card['points'] as num?) ?? 0).toInt();
        return Wrap(
          spacing: 6,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            StatusChip(tr('$pts points'), icon: Icons.loyalty, color: card['status'] == 'blocked' ? YColors.muted : YColors.warning),
            if (card['tier'] != null) StatusChip('${card['tier']}', color: YColors.blue),
            Text(tr('≈ ${money(pts * fidPointValue(s), s.currency)}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          ],
        );
      },
    );
  }
}

// ======================================================================
// Écran Fidélité
// ======================================================================

class FideliteScreen extends StatefulWidget {
  const FideliteScreen({super.key});

  @override
  State<FideliteScreen> createState() => _FideliteScreenState();
}

class _FideliteScreenState extends State<FideliteScreen> with SingleTickerProviderStateMixin {
  late final bool _manage = context.read<Session>().can('fidelite.manage');
  late final TabController _tabs = TabController(length: _manage ? 5 : 2, vsync: this);

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        PageHeader(
          title: tr('Fidélité & cartes cadeaux'),
          subtitle: tr('Points gagnés à chaque achat, récompenses, cartes cadeaux'),
          actions: [if (_manage) FilledButton.icon(onPressed: () => issueGiftCard(context), icon: const Icon(Icons.card_giftcard), label: Text(tr('Carte cadeau')))],
        ),
        TabBar(
          controller: _tabs,
          isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
          tabAlignment: TabAlignment.start,
          tabs: [
            Tab(text: tr('Clients fidèles')),
            Tab(text: tr('Cartes cadeaux')),
            if (_manage) ...[Tab(text: tr('Récompenses')), Tab(text: tr('Programme')), Tab(text: tr('Statistiques'))],
          ],
        ),
        Expanded(
          child: TabBarView(
            controller: _tabs,
            physics: const NeverScrollableScrollPhysics(),
            children: [
              const _CardsTab(),
              const _GiftCardsTab(),
              if (_manage) ...const [_RewardsTab(), _ProgramTab(), _FidStatsTab()],
            ],
          ),
        ),
      ],
    );
  }
}

class _CardsTab extends StatefulWidget {
  const _CardsTab();

  @override
  State<_CardsTab> createState() => _CardsTabState();
}

class _CardsTabState extends State<_CardsTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  static String _tier(Map<String, dynamic> c) => c['tier'] == null ? '\u0000member' : '${c['tier']}';

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('lc_cards'),
      builder: (context, _, _) {
        final all = LocalDb.all('lc_cards').map((c) => {...c, '_partner': LocalDb.get('partners', c['partner_id'] as String?)}).toList();
        final minRedeem = fidMinRedeem(s);
        final base = all.where((c) {
          final p = c['_partner'] as Map?;
          if (_q.isNotEmpty && !'${c['number']} ${p?['name']} ${p?['phone']}'.toLowerCase().contains(_q)) return false;
          final pts = toDouble(c['points']), pf = _f['points'], sy = _f['sync'];
          if (pf == 'zero' && pts > 0) return false;
          if (pf == 'some' && (pts <= 0 || pts >= minRedeem)) return false;
          if (pf == 'redeem' && pts < minRedeem) return false;
          if (sy == 'pending' && c['number'] != null) return false;
          if (sy == 'synced' && c['number'] == null) return false;
          return true;
        }).toList();
        final tiers = {'': 'Tous', '\u0000member': 'Membres', for (final c in all) if (c['tier'] != null) '${c['tier']}': '${c['tier']}'};
        final rows = base.where((c) => _status.isEmpty || _tier(c) == _status).toList()..sort((a, b) => ((b['points'] as num?) ?? 0).compareTo((a['points'] as num?) ?? 0));
        return Column(
          children: [
            FilterBar(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              search: _q,
              searchHint: 'N° de carte, nom, téléphone…',
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              statuses: tiers.length > 2 ? tiers : null,
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in tiers.keys) k: k.isEmpty ? base.length : base.where((c) => _tier(c) == k).length},
              filters: [
                FilterSpec('points', 'Points', {'zero': 'Aucun point', 'some': 'Moins de $minRedeem pts', 'redeem': 'Utilisables (≥ $minRedeem pts)'}, icon: Icons.stars_outlined),
                const FilterSpec('sync', 'Carte', {'synced': 'Numéro attribué', 'pending': 'En attente de numéro'}, icon: Icons.credit_card),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: rows.length,
              countLabel: 'carte',
              onReset: () => setState(() => (_q = '', _status = '', _f = {})),
            ),
            Expanded(
              child: rows.isEmpty && all.isNotEmpty
                  ? EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
                  : rows.isEmpty
                  ? EmptyState(
                      icon: Icons.loyalty_outlined,
                      title: tr('Aucun client fidèle'),
                      message: tr('Les cartes se créent toutes seules au premier achat d\'un client identifié, ou depuis la caisse.'),
                    )
                  : ResponsiveList<Map<String, dynamic>>(
                      items: rows,
                      columns: const ['Carte', 'Client', 'Téléphone', 'Niveau', 'Points', 'Valeur'],
                      cells: (c) => [
                        Text(tr('${c['number'] ?? 'en attente'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text(tr('${(c['_partner'] as Map?)?['name'] ?? ''}')),
                        Text(tr('${(c['_partner'] as Map?)?['phone'] ?? ''}')),
                        c['tier'] == null ? Text(tr('Membre')) : StatusChip('${c['tier']}', color: YColors.blue),
                        Text(tr('${c['points'] ?? 0}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(tr(money(((c['points'] as num?) ?? 0) * fidPointValue(s), s.currency))),
                      ],
                      mobileTile: (c) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: InitialsAvatar('${(c['_partner'] as Map?)?['name'] ?? '?'}', size: 38),
                        title: Text(tr('${(c['_partner'] as Map?)?['name'] ?? ''}')),
                        subtitle: Text(tr('${c['number'] ?? ''}${c['tier'] != null ? ' · ${c['tier']}' : ''}')),
                        trailing: Text(tr('${c['points'] ?? 0} pts'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      ),
                      onTap: (c) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => LoyaltyCardScreen(id: '${c['id']}'))),
                    ),
            ),
          ],
        );
      },
    );
  }
}

class LoyaltyCardScreen extends StatelessWidget {
  const LoyaltyCardScreen({super.key, required this.id});

  final String id;

  Future<void> _adjust(BuildContext context, Map<String, dynamic> card) async {
    final pts = TextEditingController();
    final note = TextEditingController();
    var bonus = true;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Ajouter ou retirer des points')),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SegmentedButton<bool>(
                segments: [
                  ButtonSegment(value: true, label: Text(tr('Ajouter (bonus)'))),
                  ButtonSegment(value: false, label: Text(tr('Retirer'))),
                ],
                selected: {bonus},
                onSelectionChanged: (v) => set(() => bonus = v.first),
              ),
              TextField(
                controller: pts,
                autofocus: true,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: tr('Points')),
              ),
              TextField(
                controller: note,
                decoration: InputDecoration(labelText: tr('Motif (obligatoire)')),
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    );
    final n = int.tryParse(pts.text) ?? 0;
    if (ok != true || n <= 0 || note.text.trim().isEmpty || !context.mounted) return;
    try {
      await context.read<SyncEngine>().save('lc_transactions', {'id': newId(), 'card_id': id, 'type': bonus ? 'bonus' : 'adjust', 'points': bonus ? n : -n, 'note': note.text.trim()});
      if (context.mounted) toast(context, tr('Enregistré : le solde se met à jour à la synchronisation.'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('lc_transactions'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('lc_cards'),
        builder: (context, _, _) {
          final c = LocalDb.get('lc_cards', id);
          if (c == null) return Scaffold(appBar: AppBar(), body: const SizedBox());
          final p = LocalDb.get('partners', c['partner_id'] as String?);
          final tx = LocalDb.all('lc_transactions').where((t) => t['card_id'] == id).toList()..sort((a, b) => '${b['created_at'] ?? ''}'.compareTo('${a['created_at'] ?? ''}'));
          final pts = ((c['points'] as num?) ?? 0).toInt();
          return Scaffold(
            appBar: AppBar(
              title: Text(tr('${p?['name'] ?? 'Carte'}')),
              actions: [
                if (s.can('fidelite.manage'))
                  PopupMenuButton<String>(
                    onSelected: (v) async {
                      if (v == 'adjust') _adjust(context, c);
                      if (v == 'block') await context.read<SyncEngine>().save('lc_cards', {'id': id, 'status': c['status'] == 'blocked' ? 'active' : 'blocked'});
                    },
                    itemBuilder: (_) => [
                      PopupMenuItem(value: 'adjust', child: Text(tr('Ajouter / retirer des points'))),
                      PopupMenuItem(value: 'block', child: Text(tr(c['status'] == 'blocked' ? 'Débloquer la carte' : 'Bloquer la carte (perte, fraude)'))),
                    ],
                  ),
              ],
            ),
            body: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 760),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Container(
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        gradient: const LinearGradient(colors: [YColors.night, Color(0xFF1F3A70)]),
                        borderRadius: BorderRadius.circular(18),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Text(tr('${s.company['name'] ?? ''}'), style: const TextStyle(color: Colors.white70)),
                              const Spacer(),
                              Text(
                                tr('${c['tier'] ?? 'Membre'}'),
                                style: const TextStyle(color: YColors.yellow, fontWeight: FontWeight.w800),
                              ),
                            ],
                          ),
                          const SizedBox(height: 18),
                          Text(
                            tr('$pts points'),
                            style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w800),
                          ),
                          Text(tr('≈ ${money(pts * fidPointValue(s), s.currency)} à utiliser en caisse'), style: const TextStyle(color: Colors.white70)),
                          const SizedBox(height: 14),
                          Row(
                            children: [
                              Text(
                                tr('${c['number'] ?? 'Numéro attribué à la synchronisation'}'),
                                style: const TextStyle(color: Colors.white, letterSpacing: 2, fontWeight: FontWeight.w700),
                              ),
                              const Spacer(),
                              if (c['status'] == 'blocked') StatusChip(tr('Bloquée'), color: YColors.danger),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        StatusChip(tr('${c['earned_12m'] ?? 0} points gagnés sur 12 mois'), color: YColors.blue),
                        if (p?['phone'] != null) StatusChip('${p!['phone']}', icon: Icons.phone, color: YColors.muted),
                      ],
                    ),
                    const SizedBox(height: 12),
                    SectionCard(
                      title: tr('Historique'),
                      child: tx.isEmpty
                          ? Text(tr('Aucun mouvement.'), style: TextStyle(color: Theme.of(context).hintColor))
                          : Column(
                              children: [
                                for (final t in tx)
                                  ListTile(
                                    dense: true,
                                    contentPadding: EdgeInsets.zero,
                                    title: Text(tr('${lcTypes[t['type']] ?? t['type']}${t['note'] != null ? ' — ${t['note']}' : ''}')),
                                    subtitle: Text(
                                      tr('${dateTimeFr(t['created_at'])}${t['expires_at'] != null && ((t['remaining'] as num?) ?? 0) > 0 ? ' · ${t['remaining']} pts expirent le ${dateFr(t['expires_at'])}' : ''}'),
                                    ),
                                    trailing: Text(
                                      tr('${((t['points'] as num?) ?? 0) >= 0 ? '+' : ''}${t['points']}'),
                                      style: TextStyle(fontWeight: FontWeight.w800, color: ((t['points'] as num?) ?? 0) >= 0 ? YColors.success : YColors.danger),
                                    ),
                                  ),
                              ],
                            ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

// ======================================================================
// Cartes cadeaux
// ======================================================================

class _GiftCardsTab extends StatefulWidget {
  const _GiftCardsTab();

  @override
  State<_GiftCardsTab> createState() => _GiftCardsTabState();
}

class _GiftCardsTabState extends State<_GiftCardsTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('gift_cards'),
      builder: (context, _, _) {
        final all = LocalDb.all('gift_cards');
        String partnerName(Map<String, dynamic> g) => '${LocalDb.get('partners', g['partner_id'] as String?)?['name'] ?? ''}';
        final base = all.where((g) {
          if (_q.isNotEmpty && !'${g['code']} ${g['recipient_name']} ${partnerName(g)}'.toLowerCase().contains(_q)) return false;
          final k = _f['kind'], cl = _f['client'];
          if (k != null && (g['kind'] == 'voucher' ? 'voucher' : 'gift') != k) return false;
          if (cl != null && '${g['partner_id']}' != cl) return false;
          if (_period != null && !_period!.contains(g['created_at'])) return false;
          return true;
        }).toList();
        final statuses = {'': 'Toutes', ...giftStatuses};
        final rows = base.where((g) => _status.isEmpty || g['status'] == _status).toList()
          ..sort((a, b) => '${b['created_at'] ?? ''}'.compareTo('${a['created_at'] ?? ''}'));
        final outstanding = rows.where((g) => g['status'] == 'active').fold<double>(0, (t, g) => t + toDouble(g['balance']));
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            FilterBar(
              padding: const EdgeInsets.only(bottom: 8),
              search: _q,
              searchHint: 'Code, bénéficiaire…',
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              statuses: statuses,
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in statuses.keys) k: k.isEmpty ? base.length : base.where((g) => g['status'] == k).length},
              filters: [
                const FilterSpec('kind', 'Type', {'gift': 'Carte cadeau', 'voucher': 'Bon d\'achat'}, icon: Icons.card_giftcard),
                FilterSpec('client', 'Client', filterOptions(all.where((g) => g['partner_id'] != null), 'partner_id', label: partnerName), icon: Icons.person_outline),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              showPeriod: true,
              period: _period,
              onPeriod: (v) => setState(() => _period = v),
              count: rows.length,
              countLabel: 'carte',
              onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
            ),
            Text(
              tr('${rows.where((g) => g['status'] == 'active').length} carte(s) active(s) · ${money(outstanding, s.currency)} restant à utiliser'),
              style: TextStyle(color: Theme.of(context).hintColor),
            ),
            const SizedBox(height: 8),
            if (rows.isEmpty && all.isNotEmpty)
              EmptyState(icon: Icons.search_off, title: tr('Aucun résultat'))
            else if (rows.isEmpty)
              EmptyState(
                icon: Icons.card_giftcard,
                title: tr('Aucune carte cadeau'),
                message: tr('Vendez des cartes cadeaux ou offrez des bons d\'achat : ils se règlent ensuite en caisse comme un moyen de paiement.'),
              ),
            for (final g in rows)
              Card(
                child: ListTile(
                  leading: Icon(g['kind'] == 'voucher' ? Icons.confirmation_number_outlined : Icons.card_giftcard, color: g['status'] == 'active' ? YColors.success : YColors.muted),
                  title: Text(tr('${g['code'] ?? 'Code attribué à la synchronisation'}'), style: const TextStyle(fontWeight: FontWeight.w700, letterSpacing: 1)),
                  subtitle: Text(
                    tr([
                      g['kind'] == 'voucher' ? 'Bon d\'achat' : 'Carte cadeau',
                      if (g['recipient_name'] != null) 'pour ${g['recipient_name']}',
                      'émise ${money(toDouble(g['initial_amount']), s.currency)}',
                      if (g['expires_at'] != null) 'jusqu\'au ${dateFr(g['expires_at'])}',
                    ].join(' · ')),
                  ),
                  trailing: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(tr(money(toDouble(g['balance']), s.currency)), style: const TextStyle(fontWeight: FontWeight.w800)),
                      StatusChip(giftStatuses[g['status']] ?? '${g['status']}', color: g['status'] == 'active' ? YColors.success : YColors.muted),
                    ],
                  ),
                  onTap: () => _detail(context, g),
                ),
              ),
          ],
        );
      },
    );
  }

  Future<void> _detail(BuildContext context, Map<String, dynamic> g) async {
    final s = context.read<Session>();
    await showDialog(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('${g['code']}')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(tr('Solde : ${money(toDouble(g['balance']), s.currency)} sur ${money(toDouble(g['initial_amount']), s.currency)}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            if (g['recipient_name'] != null) Text(tr('Bénéficiaire : ${g['recipient_name']}')),
            if (g['message'] != null) Text(tr('« ${g['message']} »')),
            if (g['expires_at'] != null) Text(tr('Valable jusqu\'au ${dateFr(g['expires_at'])}')),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () {
              Clipboard.setData(ClipboardData(text: '${g['code']}'));
              toast(c, tr('Code copié.'));
            },
            child: Text(tr('Copier le code')),
          ),
          if (s.can('fidelite.manage') && g['status'] == 'active')
            TextButton(
              onPressed: () async {
                await context.read<SyncEngine>().save('gift_cards', {'id': g['id'], 'status': 'cancelled'});
                if (c.mounted) Navigator.pop(c);
              },
              child: Text(tr('Annuler la carte'), style: TextStyle(color: YColors.danger)),
            ),
          FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
        ],
      ),
    );
  }
}

/// Émission d'une carte cadeau (vendue) ou d'un bon d'achat (offert, geste commercial).
Future<void> issueGiftCard(BuildContext context) async {
  final s = context.read<Session>();
  final sync = context.read<SyncEngine>();
  final amount = TextEditingController();
  final recipient = TextEditingController();
  final message = TextEditingController();
  var kind = 'gift';
  String? partner;
  final partners = LocalDb.all('partners').where((p) => p['type'] != 'supplier').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
  final ok = await showFormDialog<bool>(context, title: 'Nouvelle carte cadeau', builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SegmentedButton<String>(
                segments: [
                  ButtonSegment(value: 'gift', label: Text(tr('Carte vendue'))),
                  ButtonSegment(value: 'voucher', label: Text(tr('Bon offert'))),
                ],
                selected: {kind},
                onSelectionChanged: (v) => set(() => kind = v.first),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                children: [
                  for (final v in [5000, 10000, 20000, 50000]) ActionChip(label: Text(tr(money(v.toDouble(), s.currency))), onPressed: () => set(() => amount.text = '$v')),
                ],
              ),
              TextField(
                controller: amount,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: tr('Montant')),
              ),
              DropdownButtonFormField<String?>(
                initialValue: partner,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Acheteur / client (facultatif)')),
                items: [
                  const DropdownMenuItem<String?>(value: null, child: Text('—')),
                  for (final p in partners)
                    DropdownMenuItem<String?>(
                      value: '${p['id']}',
                      child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (v) => set(() => partner = v),
              ),
              TextField(
                controller: recipient,
                decoration: InputDecoration(labelText: tr('Bénéficiaire (facultatif)')),
              ),
              TextField(
                controller: message,
                decoration: InputDecoration(labelText: tr('Message (facultatif)')),
              ),
              if (kind == 'gift')
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    tr('Encaissez la carte en caisse ou par une facture (ligne « Carte cadeau », TVA 0 %). Le montant sera ensuite utilisable comme moyen de paiement.'),
                    style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
              FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Émettre'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true || toDouble(amount.text) <= 0) return;
  try {
    await sync.save('gift_cards', {
      'id': newId(),
      'initial_amount': toDouble(amount.text),
      'balance': toDouble(amount.text),
      'kind': kind,
      'partner_id': partner,
      'recipient_name': recipient.text.trim().isEmpty ? null : recipient.text.trim(),
      'message': message.text.trim().isEmpty ? null : message.text.trim(),
      'status': 'active',
    });
    await sync.sync();
    if (context.mounted) toast(context, tr('Carte émise : son code apparaît dans la liste.'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ======================================================================
// Récompenses
// ======================================================================

class _RewardsTab extends StatefulWidget {
  const _RewardsTab();

  @override
  State<_RewardsTab> createState() => _RewardsTabState();
}

class _RewardsTabState extends State<_RewardsTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  static const _statuses = {'': 'Toutes', 'active': 'Actives', 'inactive': 'Désactivées'};

  static bool _statusOk(Map<String, dynamic> r, String st) => st.isEmpty || (st == 'active') == (r['active'] != false);

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('lc_rewards'),
      builder: (context, _, _) {
        final all = LocalDb.all('lc_rewards');
        final base = all.where((r) {
          if (_q.isNotEmpty && !'${r['name']}'.toLowerCase().contains(_q)) return false;
          final k = _f['kind'];
          if (k != null && (r['kind'] == 'product' ? 'product' : 'discount') != k) return false;
          return true;
        }).toList();
        final rows = base.where((r) => _statusOk(r, _status)).toList()..sort((a, b) => ((a['points_cost'] as num?) ?? 0).compareTo((b['points_cost'] as num?) ?? 0));
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    tr('Paliers affichés au client (portail, caisse). En caisse, une récompense se règle en points : « Points fidélité » dans l\'encaissement.'),
                    style: TextStyle(color: Theme.of(context).hintColor),
                  ),
                ),
                FilledButton.tonalIcon(onPressed: () => _edit(context), icon: const Icon(Icons.add), label: Text(tr('Récompense'))),
              ],
            ),
            const SizedBox(height: 12),
            if (all.isNotEmpty)
              FilterBar(
                padding: const EdgeInsets.only(bottom: 8),
                search: _q,
                searchHint: 'Récompense…',
                onSearch: (v) => setState(() => _q = v.toLowerCase()),
                statuses: _statuses,
                status: _status,
                onStatus: (v) => setState(() => _status = v),
                statusCounts: {for (final k in _statuses.keys) k: base.where((r) => _statusOk(r, k)).length},
                filters: const [FilterSpec('kind', 'Type', {'product': 'Article offert', 'discount': 'Réduction'}, icon: Icons.redeem)],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                count: rows.length,
                countLabel: 'récompense',
                onReset: () => setState(() => (_q = '', _status = '', _f = {})),
              ),
            if (all.isNotEmpty && rows.isEmpty) EmptyState(icon: Icons.search_off, title: tr('Aucun résultat')),
            for (final r in rows)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.redeem, color: YColors.warning),
                  title: Text(tr('${r['name']}'), style: TextStyle(decoration: r['active'] == false ? TextDecoration.lineThrough : null)),
                  subtitle: Text(tr(r['kind'] == 'product' ? 'Article offert' : 'Réduction de ${money(toDouble(r['amount']), s.currency)}')),
                  trailing: Text(tr('${r['points_cost']} pts'), style: const TextStyle(fontWeight: FontWeight.w800)),
                  onTap: () => _edit(context, r),
                ),
              ),
          ],
        );
      },
    );
  }

  Future<void> _edit(BuildContext context, [Map<String, dynamic>? r]) async {
    final s = context.read<Session>();
    final sync = context.read<SyncEngine>();
    final name = TextEditingController(text: '${r?['name'] ?? ''}');
    final cost = TextEditingController(text: '${r?['points_cost'] ?? ''}');
    final amount = TextEditingController(text: r == null ? '' : '${toDouble(r['amount']).round()}');
    var kind = '${r?['kind'] ?? 'amount'}';
    var active = r?['active'] != false;
    String? product = r?['product_id'] as String?;
    final products = LocalDb.all('products').where((p) => p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    await showFormDialog(context, title: r == null ? 'Nouvelle récompense' : 'Récompense', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: name,
                  decoration: InputDecoration(labelText: tr('Nom (ex. Bon de 5 000 F)')),
                ),
                TextField(
                  controller: cost,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(labelText: tr('Coût en points'), helperText: tr('1 point = ${money(fidPointValue(s), s.currency)} en caisse')),
                ),
                SegmentedButton<String>(
                  segments: [
                    ButtonSegment(value: 'amount', label: Text(tr('Réduction'))),
                    ButtonSegment(value: 'product', label: Text(tr('Article offert'))),
                  ],
                  selected: {kind},
                  onSelectionChanged: (v) => set(() => kind = v.first),
                ),
                if (kind == 'amount')
                  TextField(
                    controller: amount,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(labelText: tr('Montant de la réduction')),
                  ),
                if (kind == 'product')
                  DropdownButtonFormField<String?>(
                    initialValue: products.any((p) => p['id'] == product) ? product : null,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: tr('Article')),
                    items: [
                      for (final p in products)
                        DropdownMenuItem<String?>(
                          value: '${p['id']}',
                          child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis),
                        ),
                    ],
                    onChanged: (v) => set(() => product = v),
                  ),
                SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Active')), value: active, onChanged: (v) => set(() => active = v)),
              ],
            ),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                if (r != null)
                  TextButton(
                    onPressed: () async {
                      await sync.remove('lc_rewards', '${r['id']}');
                      if (c.mounted) Navigator.pop(c);
                    },
                    child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                  ),
                TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                SaveButton(
                  onPressed: () async {
                    if (name.text.trim().isEmpty || (int.tryParse(cost.text) ?? 0) <= 0) return;
                    await sync.save('lc_rewards', {
                      'id': r?['id'] ?? newId(),
                      'name': name.text.trim(),
                      'points_cost': int.parse(cost.text),
                      'kind': kind,
                      'amount': kind == 'amount' ? toDouble(amount.text) : 0,
                      'product_id': kind == 'product' ? product : null,
                      'active': active,
                    });
                    if (c.mounted) Navigator.pop(c);
                  },
                  child: Text(tr('Enregistrer')),
                ),
              ]),
            ),
          ]),
        ),
      ));
  }
}

// ======================================================================
// Programme (réglages)
// ======================================================================

class _ProgramTab extends StatefulWidget {
  const _ProgramTab();

  @override
  State<_ProgramTab> createState() => _ProgramTabState();
}

class _ProgramTabState extends State<_ProgramTab> {
  Map<String, dynamic>? _cfg;
  Object? _error;
  final Map<String, TextEditingController> _c = {};
  List<Map<String, dynamic>> _tiers = [];
  bool _enabled = true;
  bool _auto = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('fidelite/settings');
      final cfg = asMap(r['data']);
      if (!mounted) return;
      setState(() {
        _cfg = cfg;
        _error = null;
        for (final k in ['per_amount', 'point_value', 'min_redeem', 'expiry_months', 'welcome']) {
          _c[k] = TextEditingController(text: '${(cfg[k] as num?)?.round() ?? ''}');
        }
        _c['gift_months'] = TextEditingController(text: '${context.read<Session>().companySettings['gift_card_months'] ?? 12}');
        _tiers = [for (final t in (cfg['tiers'] as List)) asMap(t)];
        _enabled = cfg['enabled'] == true;
        _auto = cfg['auto_enroll'] == true;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _save() async {
    final s = context.read<Session>();
    int n(String k) => int.tryParse(_c[k]!.text) ?? 0;
    try {
      await s.api.put('company', {
        'settings': {
          'fid_enabled': _enabled,
          'fid_auto_enroll': _auto,
          'fid_per_amount': n('per_amount') < 1 ? 1000 : n('per_amount'),
          'fid_point_value': toDouble(_c['point_value']!.text) <= 0 ? 10 : toDouble(_c['point_value']!.text),
          'fid_min_redeem': n('min_redeem'),
          'fid_expiry_months': n('expiry_months'),
          'fid_welcome_points': n('welcome'),
          'gift_card_months': n('gift_months'),
          'fid_tiers': [
            for (final t in _tiers.where((t) => '${t['name']}'.trim().isNotEmpty))
              {'name': '${t['name']}'.trim(), 'min': toDouble(t['min']).round(), 'multiplier': toDouble(t['multiplier']) < 1 ? 1 : toDouble(t['multiplier'])},
          ],
        },
      });
      await s.refreshBootstrap();
      if (mounted) toast(context, tr('Programme enregistré.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_cfg == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final cur = context.read<Session>().currency;
    final per = toDouble(_c['per_amount']!.text);
    final val = toDouble(_c['point_value']!.text);
    final rate = per > 0 ? val / per * 100 : 0;
    Widget field(String k, String label, {String? helper}) => Field(
      label: label,
      child: TextField(
        controller: _c[k],
        keyboardType: TextInputType.number,
        onChanged: (_) => setState(() {}),
        decoration: InputDecoration(helperText: trn(helper)),
      ),
    );
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        SectionCard(
          title: tr('Règles du programme'),
          child: Column(
            children: [
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Programme actif')), value: _enabled, onChanged: (v) => setState(() => _enabled = v)),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('Inscription automatique')),
                subtitle: Text(tr('Carte créée au premier achat d\'un client identifié')),
                value: _auto,
                onChanged: (v) => setState(() => _auto = v),
              ),
              Row(
                children: [
                  Expanded(child: field('per_amount', '1 point par tranche de', helper: cur)),
                  const SizedBox(width: 12),
                  Expanded(child: field('point_value', 'Valeur d\'un point en caisse', helper: cur)),
                ],
              ),
              Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  tr('Soit ${NumberFormat('0.##', 'fr').format(rate)} % rendu au client sur ses achats.'),
                  style: const TextStyle(fontWeight: FontWeight.w600, color: YColors.blue),
                ),
              ),
              Row(
                children: [
                  Expanded(child: field('min_redeem', 'Minimum pour payer en points')),
                  const SizedBox(width: 12),
                  Expanded(child: field('expiry_months', 'Validité des points (mois, 0 = illimitée)')),
                ],
              ),
              Row(
                children: [
                  Expanded(child: field('welcome', 'Points de bienvenue')),
                  const SizedBox(width: 12),
                  Expanded(child: field('gift_months', 'Validité des cartes cadeaux (mois)')),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Niveaux'),
          trailing: TextButton.icon(onPressed: () => setState(() => _tiers.add({'name': '', 'min': 0, 'multiplier': 1.25})), icon: const Icon(Icons.add, size: 18), label: Text(tr('Niveau'))),
          child: Column(
            children: [
              Text(tr('Selon les points gagnés sur 12 mois ; le coefficient multiplie les points des achats suivants.'), style: TextStyle(color: Theme.of(context).hintColor)),
              for (var k = 0; k < _tiers.length; k++)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Row(
                    children: [
                      Expanded(
                        flex: 2,
                        child: TextFormField(
                          initialValue: '${_tiers[k]['name']}',
                          decoration: InputDecoration(labelText: tr('Nom')),
                          onChanged: (v) => _tiers[k]['name'] = v,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextFormField(
                          initialValue: '${_tiers[k]['min']}',
                          keyboardType: TextInputType.number,
                          decoration: InputDecoration(labelText: tr('Dès (points)')),
                          onChanged: (v) => _tiers[k]['min'] = toDouble(v),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextFormField(
                          initialValue: '${_tiers[k]['multiplier']}',
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          decoration: InputDecoration(labelText: tr('Coefficient')),
                          onChanged: (v) => _tiers[k]['multiplier'] = toDouble(v),
                        ),
                      ),
                      IconButton(onPressed: () => setState(() => _tiers.removeAt(k)), icon: const Icon(Icons.close)),
                    ],
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerRight,
          child: SaveButton(onPressed: _save, child: Text(tr('Enregistrer le programme'))),
        ),
      ],
    );
  }
}

// ======================================================================
// Statistiques
// ======================================================================

class _FidStatsTab extends StatefulWidget {
  const _FidStatsTab();

  @override
  State<_FidStatsTab> createState() => _FidStatsTabState();
}

class _FidStatsTabState extends State<_FidStatsTab> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await context.read<Session>().api.get('fidelite/stats');
      if (mounted) setState(() => (_d = Map<String, dynamic>.from(d), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final d = _d!;
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    Widget kpi(String label, String value, IconData icon, {Color color = YColors.blue}) => SizedBox(
      width: 220,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Icon(icon, color: color),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(tr(value), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                    Text(tr(label), style: TextStyle(fontSize: 12, color: hint)),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(tr('Mois en cours'), style: TextStyle(color: hint)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              kpi('Clients fidèles', '${d['members']}', Icons.loyalty_outlined, color: YColors.warning),
              kpi('Nouveaux ce mois', '${d['new_members']}', Icons.person_add_alt),
              kpi('Venus acheter ce mois', '${d['active_members']}', Icons.shopping_bag_outlined),
              kpi('Ventes des clients fidèles', money(toDouble(d['loyal_sales']), cur), Icons.trending_up, color: YColors.success),
              kpi('Points distribués', '${d['earned']}', Icons.add_circle_outline),
              kpi('Points utilisés', '${d['redeemed']}', Icons.redeem),
              kpi('Points en circulation (${money(toDouble(d['points_value']), cur)})', '${d['points_outstanding']} pts', Icons.account_balance_wallet_outlined, color: YColors.warning),
              kpi('Cartes cadeaux à utiliser', money(toDouble(d['gift_outstanding']), cur), Icons.card_giftcard, color: YColors.warning),
            ],
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Meilleurs clients (12 mois)'),
            child: Column(
              children: [
                for (final t in (d['top'] as List))
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.emoji_events_outlined, color: YColors.warning),
                    title: Text(tr('${t['name'] ?? ''}')),
                    subtitle: Text(tr('${t['card']}${t['tier'] != null ? ' · ${t['tier']}' : ''}')),
                    trailing: Text(tr('${t['earned_12m']} pts gagnés'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
