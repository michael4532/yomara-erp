import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../state/session.dart';
import '../../../core/json.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/filter_bar.dart';
import 'admin_common.dart';
import 'admin_store_demo.dart';
import '../../../i18n/i18n.dart';

const companyStatus = {'active': 'Active', 'trial': 'Essai', 'suspended': 'Suspendue'};

Color statusColor(String? s) => switch (s) {
      'active' => YColors.success,
      'trial' => YColors.warning,
      _ => YColors.danger,
    };

class AdminCompaniesScreen extends StatefulWidget {
  const AdminCompaniesScreen({super.key});

  @override
  State<AdminCompaniesScreen> createState() => _AdminCompaniesScreenState();
}

class _AdminCompaniesScreenState extends State<AdminCompaniesScreen> {
  String _q = '';
  String _st = '';
  String? _plan;
  Key _key = UniqueKey();

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    return AdminLoader(
      key: _key,
      path: 'admin/companies',
      builder: (context, d, reload) {
        final all = List<Map<String, dynamic>>.from((d['data'] as List).map((e) => Map<String, dynamic>.from(e)));
        final items = all
            .where((c) => _q.isEmpty || '${c['name']} ${c['email'] ?? ''}'.toLowerCase().contains(_q))
            .where((c) => _st.isEmpty || (_st == 'online' ? ((c['online_count'] as num?) ?? 0) > 0 : c['status'] == _st))
            .where((c) => _plan == null || '${c['plan']?['name'] ?? ''}' == _plan)
            .toList();
        final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
        return Column(children: [
          PageHeader(
            title: tr('Sociétés'),
            subtitle: tr('${items.length} société(s)'),
            actions: [
              OutlinedButton.icon(
                onPressed: () => showStoreDemoDialog(context, onChanged: reload),
                icon: const Icon(Icons.storefront_outlined),
                label: Text(tr('Compte démo stores')),
              ),
              FilledButton.icon(onPressed: () => _create(context, reload), icon: const Icon(Icons.add_business_outlined), label: Text(tr('Nouvelle société'))),
            ],
          ),
          FilterBar(
            padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            search: _q,
            searchHint: 'Nom ou e-mail de la société…',
            statuses: const {'': 'Toutes', 'active': 'Actives', 'trial': 'En essai', 'suspended': 'Suspendues', 'online': 'En ligne'},
            status: _st,
            statusCounts: {
              '': all.length,
              for (final k in ['active', 'trial', 'suspended']) k: all.where((c) => c['status'] == k).length,
              'online': all.where((c) => ((c['online_count'] as num?) ?? 0) > 0).length,
            },
            onStatus: (v) => setState(() => _st = v),
            filters: [FilterSpec('plan', 'Formule', filterOptions(all.map((c) => {'p': c['plan']?['name']}), 'p'), icon: Icons.workspace_premium_outlined)],
            values: {'plan': _plan},
            onFilter: (k, v) => setState(() => _plan = v),
            count: items.length,
            countLabel: 'société',
            onReset: () => setState(() => (_q = '', _st = '', _plan = null)),
          ),
          Expanded(
            child: ResponsiveList<Map<String, dynamic>>(
              items: items,
              columns: const ['Société', 'Statut', 'Formule', 'Utilisateurs', 'En ligne', 'Mensuel'],
              onTap: (c) async {
                await Navigator.of(context).push(MaterialPageRoute(builder: (_) => CompanyDetailScreen(companyId: c['id'] as int)));
                setState(() => _key = UniqueKey());
              },
              cells: (c) => [
                Row(children: [
                  InitialsAvatar('${c['name']}'),
                  const SizedBox(width: 10),
                  Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${c['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    if (c['email'] != null) Text(tr('${c['email']}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                  ]),
                ]),
                StatusChip(companyStatus[c['status']] ?? '${c['status']}', color: statusColor(c['status'])),
                Text(tr('${c['plan']?['name'] ?? '—'}')),
                Text(tr('${c['users_count']}')),
                Row(children: [
                  if ((c['online_count'] as int) > 0) const Icon(Icons.circle, size: 10, color: YColors.success),
                  const SizedBox(width: 6),
                  Text(tr('${c['online_count']}')),
                ]),
                c['is_editor'] == true ? Text(tr('Votre société (éditeur)'), style: TextStyle(color: Theme.of(context).hintColor)) : c['is_store_demo'] == true ? Text(tr('Démo pour les stores'), style: TextStyle(color: Theme.of(context).hintColor)) : Text(tr(money(c['monthly'] as num, session.currency))),
              ],
              mobileTile: (c) => Row(children: [
                InitialsAvatar('${c['name']}', size: 36),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${c['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(tr('${c['plan']?['name'] ?? 'Sans formule'} · ${c['users_count']} util. · ${c['online_count']} en ligne'),
                        style: TextStyle(fontSize: 13, color: Theme.of(context).hintColor)),
                  ]),
                ),
                StatusChip(companyStatus[c['status']] ?? '', color: statusColor(c['status'])),
              ]),
            ),
          ),
        ]);
      },
    );
  }

  Future<void> _create(BuildContext context, VoidCallback reload) async {
    final session = context.read<Session>();
    List<dynamic> plans = [];
    try {
      plans = (await session.api.get('admin/plans'))['data'] as List;
    } catch (_) {}
    if (!context.mounted) return;
    final c = {for (final f in ['name', 'email', 'phone', 'owner_name', 'owner_email', 'owner_password']) f: TextEditingController()};
    int? planId = plans.isNotEmpty ? plans.first['id'] as int : null;
    var status = 'active';

    await showFormDialog(context, title: tr('Nouvelle société'), builder: (dc) {
      return StatefulBuilder(builder: (dc, setS) {
        return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
          Field(label: tr('Nom de la société'), child: TextField(controller: c['name'], autofocus: true)),
          Row(children: [
            Expanded(child: Field(label: tr('E-mail'), child: TextField(controller: c['email']))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Téléphone'), child: TextField(controller: c['phone']))),
          ]),
          Row(children: [
            Expanded(
              child: Field(
                label: tr('Formule'),
                child: DropdownButtonFormField<int?>(
                  initialValue: planId,
                  items: [
                    DropdownMenuItem(value: null, child: Text(tr('Aucune'))),
                    for (final p in plans) DropdownMenuItem(value: p['id'] as int, child: Text(tr('${p['name']}'))),
                  ],
                  onChanged: (v) => setS(() => planId = v),
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Field(
                label: tr('Statut'),
                child: DropdownButtonFormField<String>(
                  initialValue: status,
                  items: [for (final e in companyStatus.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                  onChanged: (v) => setS(() => status = v!),
                ),
              ),
            ),
          ]),
          const Divider(height: 24),
          Text(tr('Administrateur de la société'), style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 10),
          Field(label: tr('Nom'), child: TextField(controller: c['owner_name'])),
          Field(label: tr('E-mail de connexion'), child: TextField(controller: c['owner_email'])),
          Field(label: tr('Mot de passe provisoire (8 caractères min.)'), child: TextField(controller: c['owner_password'])),
          Align(
            alignment: Alignment.centerRight,
            child: SaveButton(
              onPressed: () async {
                try {
                  await session.api.post('admin/companies', {
                    for (final e in c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(),
                    'plan_id': planId,
                    'status': status,
                  });
                  reload();
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              child: Text(tr('Créer la société')),
            ),
          ),
        ]);
      });
    });
  }
}

/// Détail d'une société : statut, formule, échéances, modules activés au cas par cas.
class CompanyDetailScreen extends StatefulWidget {
  const CompanyDetailScreen({super.key, required this.companyId});

  final int companyId;

  @override
  State<CompanyDetailScreen> createState() => _CompanyDetailScreenState();
}

class _CompanyDetailScreenState extends State<CompanyDetailScreen> {
  Map<String, dynamic>? _company;
  Map<String, dynamic>? _billing;
  List<Map<String, dynamic>> _users = [];
  List<Map<String, dynamic>> _modules = [];
  List<dynamic> _plans = [];
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final r = await api.get('admin/companies/${widget.companyId}');
      final m = await api.get('admin/companies/${widget.companyId}/modules');
      final p = await api.get('admin/plans');
      if (!mounted) return;
      setState(() {
        _company = Map<String, dynamic>.from(r['data']);
        _billing = Map<String, dynamic>.from(r['billing']);
        _users = List<Map<String, dynamic>>.from((r['users'] as List).map((e) => Map<String, dynamic>.from(e)));
        _modules = List<Map<String, dynamic>>.from((m['data'] as List).map((e) => Map<String, dynamic>.from(e)));
        _plans = p['data'] as List;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _update(Map<String, dynamic> body) async {
    final session = context.read<Session>();
    try {
      await session.api.put('admin/companies/${widget.companyId}', body);
      await _load();
      if (session.company['id'] == widget.companyId) await session.refreshBootstrap();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _grant() async {
    final r = await showFormDialog<Map<String, dynamic>>(
      context,
      title: 'Activer manuellement',
      builder: (_) => _GrantDialog(modules: _modules.where((m) => m['is_core'] != true && m['available'] == true).toList(), plans: _plans),
    );
    if (r == null || !mounted) return;
    final session = context.read<Session>();
    try {
      final res = await session.api.post('admin/companies/${widget.companyId}/grant', r);
      if (mounted) toast(context, '${res['message']}');
      await _load();
      if (session.company['id'] == widget.companyId) await session.refreshBootstrap();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _setModule(Map<String, dynamic> m, Map<String, dynamic> body) async {
    final session = context.read<Session>();
    try {
      final r = await session.api.put('admin/companies/${widget.companyId}/modules/${m['key']}', body);
      if (mounted) setState(() => _modules = List<Map<String, dynamic>>.from((r['data'] as List).map((e) => Map<String, dynamic>.from(e))));
      await _load();
      if (session.company['id'] == widget.companyId) await session.refreshBootstrap();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _pickDate(String field) async {
    final current = DateTime.tryParse('${_company?[field] ?? ''}') ?? DateTime.now().add(const Duration(days: 30));
    final d = await pickDate(context, initialDate: current, firstDate: DateTime(2020), lastDate: DateTime(2100));
    if (d != null) await _update({field: isoDate(d)});
  }

  Future<void> _moduleOptions(Map<String, dynamic> m) async {
    final price = TextEditingController(text: m['override']?['price_override'] == null ? '' : '${(m['override']['price_override'] as num).round()}');
    DateTime? expires = DateTime.tryParse('${m['override']?['expires_at'] ?? ''}');
    await showFormDialog(context, title: m['name'] as String, builder: (dc) {
      return StatefulBuilder(builder: (dc, setS) {
        return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
          Text(tr(m['in_plan'] == true ? 'Inclus dans la formule de la société.' : 'Hors formule : facturé en supplément.'), style: TextStyle(color: Theme.of(dc).hintColor)),
          const SizedBox(height: 12),
          Field(
            label: tr('Prix spécifique pour cette société (vide = prix catalogue)'),
            child: TextField(controller: price, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))]),
          ),
          Field(
            label: tr('Actif jusqu\'au (vide = sans limite)'),
            child: InkWell(
              onTap: () async {
                final d = await pickDate(dc, initialDate: expires ?? DateTime.now().add(const Duration(days: 30)), firstDate: DateTime(2020), lastDate: DateTime(2100));
                setS(() => expires = d);
              },
              child: InputDecorator(
                decoration: InputDecoration(suffixIcon: expires != null ? IconButton(icon: const Icon(Icons.clear), onPressed: () => setS(() => expires = null)) : const Icon(Icons.calendar_today, size: 18)),
                child: Text(tr(expires == null ? 'Sans limite' : dateFr(expires!.toIso8601String()))),
              ),
            ),
          ),
          Row(children: [
            TextButton(
              onPressed: () async {
                await _setModule(m, {'reset': true});
                if (dc.mounted) Navigator.pop(dc);
              },
              child: Text(tr('Revenir à la formule')),
            ),
            const Spacer(),
            FilledButton(
              onPressed: () async {
                await _setModule(m, {
                  'enabled': true,
                  'price_override': price.text.trim().isEmpty ? null : toDouble(price.text),
                  'expires_at': expires == null ? null : isoDate(expires!),
                });
                if (dc.mounted) Navigator.pop(dc);
              },
              child: Text(tr('Activer avec ces conditions')),
            ),
          ]),
        ]);
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    final c = _company;
    return Scaffold(
      appBar: AppBar(title: Text(tr('${c?['name'] ?? 'Société'}'))),
      body: _error != null && c == null
          ? OnlineOnlyNotice(error: _error!, onRetry: _load)
          : c == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(padding: EdgeInsets.all(MediaQuery.of(context).size.width < 700 ? 12 : 24), children: [
                  Card(
                    color: YColors.night,
                    child: ListTile(
                      contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                      leading: const Icon(Icons.card_giftcard_rounded, color: YColors.yellow),
                      title: Text(tr('Activer manuellement'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                      subtitle: Text(tr('Offrir ou activer un module ou une formule pour une durée, après un paiement reçu en direct par exemple. Le client est prévenu par notification et e-mail.'),
                          style: const TextStyle(color: Color(0xFFC9D6F5))),
                      trailing: FilledButton.icon(
                        style: FilledButton.styleFrom(backgroundColor: YColors.yellow, foregroundColor: YColors.night),
                        onPressed: _grant,
                        icon: const Icon(Icons.bolt),
                        label: Text(tr('Activer')),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  SectionCard(
                    title: tr('Abonnement'),
                    child: Wrap(spacing: 16, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.end, children: [
                      SizedBox(
                        width: 200,
                        child: Field(
                          label: tr('Statut'),
                          child: DropdownButtonFormField<String>(
                            initialValue: c['status'],
                            items: [for (final e in companyStatus.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                            onChanged: (v) async {
                              if (v == 'suspended' &&
                                  !await confirm(context, tr('Suspendre la société ?'), tr('Ses utilisateurs seront déconnectés immédiatement.'), ok: tr('Suspendre'), danger: true)) {
                                setState(() {});
                                return;
                              }
                              await _update({'status': v});
                            },
                          ),
                        ),
                      ),
                      SizedBox(
                        width: 220,
                        child: Field(
                          label: tr('Formule'),
                          child: DropdownButtonFormField<int?>(
                            initialValue: c['plan_id'] as int?,
                            items: [
                              DropdownMenuItem(value: null, child: Text(tr('Aucune'))),
                              for (final p in _plans) DropdownMenuItem(value: p['id'] as int, child: Text(tr('${p['name']}'))),
                            ],
                            onChanged: (v) => _update({'plan_id': v}),
                          ),
                        ),
                      ),
                      SizedBox(
                        width: 200,
                        child: Field(
                          label: tr('Fin d\'abonnement'),
                          child: InkWell(onTap: () => _pickDate('subscription_ends_at'), child: InputDecorator(decoration: const InputDecoration(), child: Text(tr(dateFr(c['subscription_ends_at']))))),
                        ),
                      ),
                      if (c['status'] == 'trial')
                        SizedBox(
                          width: 200,
                          child: Field(
                            label: tr('Fin d\'essai'),
                            child: InkWell(onTap: () => _pickDate('trial_ends_at'), child: InputDecorator(decoration: const InputDecoration(), child: Text(tr(dateFr(c['trial_ends_at']))))),
                          ),
                        ),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  SectionCard(
                    title: tr('Facturation mensuelle : ${money(_billing?['total'] as num? ?? 0, session.currency)}'),
                    child: Column(children: [
                      for (final l in (_billing?['lines'] as List? ?? []))
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 3),
                          child: Row(children: [Expanded(child: Text(tr('${l['label']}'))), Text(tr(money(l['amount'] as num, session.currency)))]),
                        ),
                      if ((_billing?['lines'] as List? ?? []).isEmpty) Text(tr('Rien à facturer.'), style: TextStyle(color: Theme.of(context).hintColor)),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  SectionCard(
                    title: tr('Modules de la société'),
                    child: Column(children: [
                      for (final m in _modules.where((m) => m['is_core'] != true))
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: Icon(Icons.extension_outlined, color: m['enabled'] == true ? YColors.blue : Colors.grey),
                          title: Text(tr('${m['name']}')),
                          subtitle: Text(tr([
                            if (m['available'] != true) 'bientôt disponible',
                            if (m['globally_active'] != true) 'désactivé globalement',
                            if (m['in_plan'] == true) 'inclus dans la formule',
                            if (m['override'] != null) (m['override']['enabled'] == true ? 'activé manuellement' : 'désactivé manuellement'),
                            if (m['override']?['expires_at'] != null) 'jusqu\'au ${dateFr(m['override']['expires_at'])}',
                            '${money(m['price_monthly'] as num, session.currency)}/mois',
                          ].join(' · '))),
                          onTap: m['available'] == true ? () => _moduleOptions(m) : null,
                          trailing: Switch(
                            value: m['enabled'] == true,
                            onChanged: m['available'] == true && m['globally_active'] == true ? (v) => _setModule(m, {'enabled': v}) : null,
                          ),
                        ),
                    ]),
                  ),
                  const SizedBox(height: 12),
                  SectionCard(
                    title: tr('Utilisateurs (${_users.length})'),
                    child: Column(children: [
                      for (final u in _users)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: Icon(Icons.circle, size: 12, color: u['last_seen_at'] != null && DateTime.parse(u['last_seen_at']).isAfter(DateTime.now().subtract(const Duration(minutes: 2))) ? YColors.success : Colors.grey),
                          title: Text(tr('${u['name']}')),
                          subtitle: Text(tr('${u['email']} · vu ${dateTimeFr(u['last_seen_at'])} ${u['last_platform'] != null ? '(${platformLabels[u['last_platform']] ?? u['last_platform']})' : ''}')),
                        ),
                    ]),
                  ),
                ]),
    );
  }
}

/// Modules d'une société (y compris les sociétés « licence » des installations chez les clients) dans une fenêtre :
/// activation, échéance et retour à la formule. Utilisé par la page des licences.
Future<void> showCompanyModulesDialog(BuildContext context, {required int companyId, String? title, String? subtitle}) =>
    showFormDialog(context, title: title ?? tr('Modules'), subtitle: subtitle, icon: Icons.extension_outlined, maxWidth: 640, builder: (_) => _CompanyModulesEditor(companyId: companyId));

class _CompanyModulesEditor extends StatefulWidget {
  const _CompanyModulesEditor({required this.companyId});

  final int companyId;

  @override
  State<_CompanyModulesEditor> createState() => _CompanyModulesEditorState();
}

class _CompanyModulesEditorState extends State<_CompanyModulesEditor> {
  List<Map<String, dynamic>>? _modules;
  Object? _error;
  String? _busy;

  @override
  void initState() {
    super.initState();
    _load();
  }

  List<Map<String, dynamic>> _rows(dynamic r) => [for (final e in (asMap(r)['data'] as List? ?? const [])) asMap(e)];

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('admin/companies/${widget.companyId}/modules');
      if (mounted) setState(() => (_modules = _rows(r), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _set(Map<String, dynamic> m, Map<String, dynamic> body) async {
    setState(() => _busy = '${m['key']}');
    try {
      final r = await context.read<Session>().api.put('admin/companies/${widget.companyId}/modules/${m['key']}', body);
      if (mounted) setState(() => _modules = _rows(r));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  Future<void> _pickExpiry(Map<String, dynamic> m) async {
    final current = DateTime.tryParse('${asMap(m['override'])['expires_at'] ?? ''}');
    final d = await pickDate(context, initialDate: current ?? DateTime.now().add(const Duration(days: 30)), firstDate: DateTime(2020), lastDate: DateTime(2100));
    if (d != null) await _set(m, {'enabled': true, 'expires_at': isoDate(d)});
  }

  @override
  Widget build(BuildContext context) {
    final currency = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    if (_error != null && _modules == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_modules == null) return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
    final mods = _modules!.where((m) => m['is_core'] != true).toList();
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(16, 8, 16, 20), children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
        child: Text(tr('Les changements sont transmis à l\'installation lors de sa prochaine vérification (toutes les 6 h) ou dès que le client clique « Vérifier maintenant ».'),
            style: TextStyle(color: hint, fontSize: 12.5)),
      ),
      for (final m in mods)
        ListTile(
          contentPadding: const EdgeInsets.symmetric(horizontal: 8),
          leading: Icon(Icons.extension_outlined, color: m['enabled'] == true ? YColors.blue : Colors.grey),
          title: Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Text(tr([
            if (m['available'] != true) 'bientôt disponible',
            if (m['globally_active'] != true) 'désactivé globalement',
            if (m['override'] != null) (asMap(m['override'])['enabled'] == true ? 'activé manuellement' : 'désactivé manuellement'),
            if (asMap(m['override'])['expires_at'] != null) 'jusqu\'au ${dateFr(asMap(m['override'])['expires_at'])}',
            '${money(m['price_monthly'] as num? ?? 0, currency)}/mois',
          ].join(' · '))),
          trailing: _busy == '${m['key']}'
              ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
              : Row(mainAxisSize: MainAxisSize.min, children: [
                  if (m['enabled'] == true && m['available'] == true)
                    IconButton(tooltip: tr('Échéance'), onPressed: () => _pickExpiry(m), icon: const Icon(Icons.event_outlined, size: 20)),
                  if (m['override'] != null)
                    IconButton(tooltip: tr('Retirer le forçage'), onPressed: () => _set(m, {'reset': true}), icon: const Icon(Icons.undo_rounded, size: 20)),
                  Switch(
                    value: m['enabled'] == true,
                    onChanged: m['available'] == true && m['globally_active'] == true && _busy == null ? (v) => _set(m, {'enabled': v}) : null,
                  ),
                ]),
        ),
    ]);
  }
}


/// Choix de l'activation manuelle : module ou formule, durée, montant reçu éventuel.
class _GrantDialog extends StatefulWidget {
  const _GrantDialog({required this.modules, required this.plans});
  final List<Map<String, dynamic>> modules;
  final List<dynamic> plans;

  @override
  State<_GrantDialog> createState() => _GrantDialogState();
}

class _GrantDialogState extends State<_GrantDialog> {
  String _type = 'module';
  String? _key;
  int? _plan;
  int _months = 1;
  String _q = '';
  final _amount = TextEditingController();
  final _note = TextEditingController();

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final mods = widget.modules.where((m) => _q.isEmpty || '${m['name']} ${m['category']}'.toLowerCase().contains(_q.toLowerCase())).toList();
    final ok = _type == 'module' ? _key != null : _plan != null;
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'module', label: Text(tr('Un module')), icon: const Icon(Icons.extension_outlined)), ButtonSegment(value: 'plan', label: Text(tr('Une formule')), icon: const Icon(Icons.workspace_premium_outlined))],
            selected: {_type},
            onSelectionChanged: (v) => setState(() => _type = v.first),
          ),
          const SizedBox(height: 14),
          if (_type == 'module') ...[
            TextField(decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: tr('Rechercher un module'), isDense: true), onChanged: (v) => setState(() => _q = v)),
            const SizedBox(height: 8),
            SizedBox(
              height: 230,
              child: ListView(children: [
                for (final m in mods)
                  ListTile(
                    dense: true,
                    selected: _key == m['key'],
                    selectedTileColor: YColors.blue.withValues(alpha: .08),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    leading: Icon(_key == m['key'] ? Icons.radio_button_checked : Icons.radio_button_off, color: _key == m['key'] ? YColors.blue : null),
                    title: Text('${m['name']}'),
                    subtitle: Text([if (m['category'] != null) '${m['category']}', if (m['enabled'] == true) tr('déjà actif : la durée s\'ajoute')].join(' · ')),
                    onTap: () => setState(() => _key = '${m['key']}'),
                  ),
              ]),
            ),
          ] else
            DropdownButtonFormField<int>(
              initialValue: _plan,
              decoration: InputDecoration(labelText: tr('Formule')),
              items: [for (final p in widget.plans) DropdownMenuItem(value: p['id'] as int, child: Text('${p['name']}'))],
              onChanged: (v) => setState(() => _plan = v),
            ),
          const SizedBox(height: 14),
          Text(tr('Durée'), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final (m, l) in const [(1, '1 mois'), (3, '3 mois'), (6, '6 mois'), (12, '1 an'), (24, '2 ans'), (0, 'Sans fin')])
              ChoiceChip(label: Text(tr(l)), selected: _months == m, onSelected: (_) => setState(() => _months = m)),
          ]),
          const SizedBox(height: 14),
          TextField(controller: _amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant reçu (facultatif, pour vos statistiques)'))),
          const SizedBox(height: 8),
          TextField(controller: _note, decoration: InputDecoration(labelText: tr('Note interne (ex. payé en espèces au bureau)'))),
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
            FilledButton.icon(
              onPressed: !ok
                  ? null
                  : () => Navigator.pop(context, {
                        'type': _type,
                        if (_type == 'module') 'key': _key,
                        if (_type == 'plan') 'plan_id': _plan,
                        'months': _months,
                        if (_amount.text.trim().isNotEmpty) 'amount': double.tryParse(_amount.text.replaceAll(RegExp(r'[^0-9.]'), '')) ?? 0,
                        if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
                      }),
              icon: const Icon(Icons.bolt),
              label: Text(tr('Activer et prévenir le client')),
            ),
          ]),
        ),
      ]),
    );
  }
}
