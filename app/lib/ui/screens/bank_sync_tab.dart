import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'tresorerie_screen.dart' show trAccounts;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const _providerIcons = {'wave': Icons.waves, 'gocardless': Icons.account_balance, 'email': Icons.mark_email_read_outlined};
const _providerColors = {'wave': Color(0xFF1DC3F0), 'gocardless': Color(0xFF1F6FEB), 'email': Color(0xFF7C3AED)};
const _statusLabels = {'active': ('Synchronisé', YColors.success), 'pending': ('À finaliser', YColors.warning), 'error': ('Erreur', YColors.danger),
  'expired': ('Autorisation expirée', YColors.danger), 'paused': ('En pause', YColors.muted)};

/// Banque & trésorerie › Synchronisation bancaire : relevés récupérés automatiquement et règles de classement.
class BankSyncTab extends StatefulWidget {
  const BankSyncTab({super.key});

  @override
  State<BankSyncTab> createState() => _BankSyncTabState();
}

class _BankSyncTabState extends State<BankSyncTab> {
  Map<String, dynamic>? _d;
  Object? _error;
  final Set<String> _busy = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('tresorerie/bank');
      if (mounted) {
        setState(() {
          _d = asMap(r['data']);
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  List<Map<String, dynamic>> _list(String k) => [for (final e in (_d?[k] as List? ?? const [])) asMap(e)];

  Future<void> _sync(Map<String, dynamic> c) async {
    setState(() => _busy.add('${c['id']}'));
    try {
      final r = await context.read<Session>().api.postLong('tresorerie/bank/connections/${c['id']}/sync');
      final res = asMap(r['result']);
      if (mounted) {
        toast(context, tr('${res['imported']} nouvelle(s) opération(s), ${res['matched'] ?? 0} rapprochée(s)${(res['rules'] ?? 0) > 0 ? ', ${res['rules']} classée(s) par règle' : ''}.'));
        context.read<SyncEngine>().sync();
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy.remove('${c['id']}'));
      _load();
    }
  }

  Future<void> _add() async {
    final created = await showFormDialog<bool>(context, title: tr('Connecter un compte'), maxWidth: 620, builder: (_) => _ConnectForm(platformGc: _d?['gocardless_platform'] == true));
    if (created == true) _load();
  }

  Future<void> _linkBank(Map<String, dynamic> c) async {
    final ok = await showFormDialog<bool>(context, title: tr('Choisir la banque'), maxWidth: 560, builder: (_) => _InstitutionPicker(connection: c));
    if (ok == true) _load();
  }

  Future<void> _rule([Map<String, dynamic>? r]) async {
    final saved = await showFormDialog<bool>(context, title: r == null ? 'Nouvelle règle' : 'Modifier la règle', maxWidth: 560,
        builder: (_) => _RuleForm(rule: r, categories: _list('categories')));
    if (saved == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? Center(child: Text(tr(errorText(_error!)))) : const Center(child: CircularProgressIndicator());
    final api = context.read<Session>().api;
    final cons = _list('connections'), rules = _list('rules');
    final cats = {for (final c in _list('categories')) '${c['key']}': '${c['label']}'};
    final hint = Theme.of(context).hintColor;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 40), children: [
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(borderRadius: BorderRadius.circular(16), gradient: const LinearGradient(colors: [YColors.night, Color(0xFF1B3D7A)])),
          child: Wrap(spacing: 18, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
            SizedBox(
              width: 520,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('Vos relevés arrivent tout seuls'), style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 6),
                Text(tr('Wave Business par API, banques européennes en open banking, ou relevés que votre banque envoie par e-mail. Formats reconnus au dépôt : ${(_d!['formats'] as List).join(', ')}, Excel et CSV.'),
                    style: const TextStyle(color: Colors.white70, height: 1.4)),
              ]),
            ),
            FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: YColors.night),
              onPressed: _add,
              icon: const Icon(Icons.add_link),
              label: Text(tr('Connecter un compte')),
            ),
          ]),
        ),
        const SizedBox(height: 18),
        Text(tr('Connexions'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
        const SizedBox(height: 8),
        if (cons.isEmpty)
          EmptyState(icon: Icons.sync_alt, title: tr('Aucune connexion'), message: tr('Connectez votre compte Wave Business, votre banque ou une adresse de réception des relevés.'))
        else
          for (final c in cons)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    CircleAvatar(
                      backgroundColor: (_providerColors[c['provider']] ?? YColors.blue).withValues(alpha: .12),
                      child: Icon(_providerIcons[c['provider']] ?? Icons.link, color: _providerColors[c['provider']] ?? YColors.blue),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${c['label'] ?? c['institution'] ?? c['provider_label']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(tr('${c['provider_label']} › ${c['account'] ?? ''}'), style: TextStyle(color: hint, fontSize: 12)),
                      ]),
                    ),
                    StatusChip(_statusLabels[c['status']]?.$1 ?? '${c['status']}', color: _statusLabels[c['status']]?.$2 ?? YColors.muted),
                    PopupMenuButton<String>(
                      onSelected: (v) async {
                        try {
                          if (v == 'pause') {
                            await api.put('tresorerie/bank/connections/${c['id']}', {'paused': c['status'] != 'paused'});
                          } else if (v == 'delete') {
                            if (!await confirm(context, tr('Supprimer la connexion ?'), tr('Les opérations déjà importées restent dans le rapprochement.'), danger: true, ok: tr('Supprimer'))) return;
                            await api.delete('tresorerie/bank/connections/${c['id']}');
                          }
                          _load();
                        } catch (e) {
                          if (context.mounted) toast(context, errorText(e), error: true);
                        }
                      },
                      itemBuilder: (_) => [
                        PopupMenuItem(value: 'pause', child: Text(tr(c['status'] == 'paused' ? 'Reprendre' : 'Mettre en pause'))),
                        PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
                      ],
                    ),
                  ]),
                  const SizedBox(height: 10),
                  Wrap(spacing: 16, runSpacing: 6, children: [
                    if (c['last_sync_at'] != null) Text(tr('Dernière synchro : ${dateTimeFr(c['last_sync_at'])}'), style: TextStyle(color: hint, fontSize: 12)),
                    Text(tr('${c['imported']} opération(s) importée(s)'), style: TextStyle(color: hint, fontSize: 12)),
                    if (c['last_balance'] != null) Text(tr('Solde à la source : ${money(toDouble(c['last_balance']), context.read<Session>().currency)}'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
                    if (c['expires_at'] != null) Text(tr('Autorisation jusqu\'au ${dateFr(c['expires_at'])}'), style: TextStyle(color: hint, fontSize: 12)),
                  ]),
                  if (c['last_error'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('${c['last_error']}'), style: const TextStyle(color: YColors.danger, fontSize: 12))),
                  if (c['provider'] == 'email') ...[
                    const SizedBox(height: 10),
                    _Copyable(label: c['inbound_address'] != null ? 'Adresse à donner à votre banque' : 'Adresse de réception (webhook)', value: '${c['inbound_address'] ?? c['inbound_url']}'),
                    if (c['inbound_address'] == null)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(tr('Configurez votre service de réception d\'e-mails (Mailgun, Postmark, Brevo…) pour transférer les messages vers cette adresse, ou demandez à la plateforme d\'activer les adresses releves+…@.'),
                            style: TextStyle(color: hint, fontSize: 12)),
                      ),
                  ],
                  const SizedBox(height: 10),
                  Wrap(spacing: 8, children: [
                    if (c['provider'] == 'gocardless' && c['status'] != 'active')
                      FilledButton.icon(onPressed: () => _linkBank(c), icon: const Icon(Icons.login, size: 18), label: Text(tr(c['status'] == 'expired' ? 'Renouveler l\'autorisation' : 'Autoriser l\'accès à la banque'))),
                    if (c['provider'] != 'email' && (c['provider'] != 'gocardless' || c['status'] == 'active' || c['status'] == 'error'))
                      OutlinedButton.icon(
                        onPressed: _busy.contains('${c['id']}') ? null : () => _sync(c),
                        icon: _busy.contains('${c['id']}') ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.sync, size: 18),
                        label: Text(tr('Synchroniser maintenant')),
                      ),
                  ]),
                ]),
              ),
            ),
        const SizedBox(height: 22),
        Row(children: [
          Expanded(child: Text(tr('Règles de classement'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800))),
          if (rules.isNotEmpty)
            TextButton.icon(
              onPressed: () async {
                try {
                  final r = await api.post('tresorerie/bank/rules/apply');
                  if (context.mounted) toast(context, tr('${r['applied']} ligne(s) classée(s).'));
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.play_arrow),
              label: Text(tr('Appliquer maintenant')),
            ),
          FilledButton.tonalIcon(onPressed: () => _rule(), icon: const Icon(Icons.add), label: Text(tr('Règle'))),
        ]),
        Padding(
          padding: const EdgeInsets.only(top: 4, bottom: 8),
          child: Text(tr('Les lignes de relevé sans facture correspondante sont classées automatiquement : frais bancaires, abonnements, loyers, carburant…'), style: TextStyle(color: hint, fontSize: 12)),
        ),
        if (rules.isEmpty)
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final s in [
              {'name': 'Frais bancaires', 'contains': 'frais, agios, commission, tenue de compte', 'direction': 'out', 'category': 'bank_fees'},
              {'name': 'Téléphone et internet', 'contains': 'orange, mtn, moov, canal, starlink', 'direction': 'out', 'category': 'telecom'},
              {'name': 'Électricité et eau', 'contains': 'cie, sodeci, senelec', 'direction': 'out', 'category': 'utilities'},
              {'name': 'Cotisations CNPS', 'contains': 'cnps', 'direction': 'out', 'category': 'social'},
            ])
              ActionChip(
                avatar: const Icon(Icons.auto_fix_high, size: 16),
                label: Text(tr('Ajouter « ${s['name']} »')),
                onPressed: () async {
                  try {
                    await api.post('tresorerie/bank/rules', s);
                    _load();
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
              ),
          ])
        else
          Card(
            child: Column(children: [
              for (final r in rules)
                ListTile(
                  leading: Icon(r['action'] == 'ignore' ? Icons.visibility_off_outlined : Icons.rule, color: r['active'] == true || r['active'] == 1 ? YColors.blue : YColors.muted),
                  title: Text(tr('${r['name']}')),
                  subtitle: Text(tr('Libellé contient « ${r['contains']} »${r['direction'] == 'out' ? ' · sorties' : r['direction'] == 'in' ? ' · entrées' : ''} › '
                      '${r['action'] == 'ignore' ? 'ignorer' : cats[r['category']] ?? 'opération'} · ${r['hits']} fois')),
                  trailing: IconButton(
                    icon: const Icon(Icons.delete_outline),
                    onPressed: () async {
                      await api.delete('tresorerie/bank/rules/${r['id']}');
                      _load();
                    },
                  ),
                  onTap: () => _rule(r),
                ),
            ]),
          ),
      ]),
    );
  }
}

class _Copyable extends StatelessWidget {
  const _Copyable({required this.label, required this.value});

  final String label, value;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
        decoration: BoxDecoration(color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .5), borderRadius: BorderRadius.circular(10)),
        child: Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(label), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
              SelectableText(tr(value), style: const TextStyle(fontWeight: FontWeight.w600)),
            ]),
          ),
          IconButton(
            icon: const Icon(Icons.copy, size: 18),
            onPressed: () async {
              await Clipboard.setData(ClipboardData(text: value));
              if (context.mounted) toast(context, tr('Copié.'));
            },
          ),
        ]),
      );
}

class _ConnectForm extends StatefulWidget {
  const _ConnectForm({required this.platformGc});

  final bool platformGc;

  @override
  State<_ConnectForm> createState() => _ConnectFormState();
}

class _ConnectFormState extends State<_ConnectForm> {
  String _provider = 'wave';
  String? _account;
  final _label = TextEditingController(), _key = TextEditingController(), _sid = TextEditingController(), _skey = TextEditingController();
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final accounts = trAccounts();
    _account ??= accounts.where((a) => a['kind'] == (_provider == 'wave' ? 'mobile_money' : 'bank')).map((a) => '${a['id']}').firstOrNull ?? accounts.map((a) => '${a['id']}').firstOrNull;
    final hint = Theme.of(context).hintColor;
    Widget option(String k, String title, String sub) => RadioListTile<String>(
          value: k,
          contentPadding: EdgeInsets.zero,
          secondary: Icon(_providerIcons[k], color: _providerColors[k]),
          title: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text(tr(sub)),
        );
    return Column(children: [
      Expanded(
        child: ListView(padding: const EdgeInsets.all(20), children: [
          RadioGroup<String>(
            groupValue: _provider,
            onChanged: (v) => setState(() {
              _provider = v ?? 'wave';
              _account = null;
            }),
            child: Column(children: [
              option('wave', 'Wave Business', 'Encaissements, paiements et frais récupérés par l\'API de Wave, toutes les 4 heures.'),
              option('gocardless', 'Banque européenne', 'Plus de 2 000 banques (France, Belgique, Europe) via l\'open banking : vous vous authentifiez sur le site de votre banque.'),
              option('email', 'Relevés par e-mail', 'Une adresse dédiée : la banque (ou vous) y envoie les relevés OFX, MT940, CAMT, CFONB, Excel ou CSV.'),
            ]),
          ),
          const SizedBox(height: 8),
          Field(
            label: tr('Compte de trésorerie'),
            child: DropdownButtonFormField<String>(
              initialValue: _account,
              items: [for (final a in accounts) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}')))],
              onChanged: (v) => setState(() => _account = v),
            ),
          ),
          Field(label: tr('Nom (facultatif)'), child: TextField(controller: _label)),
          if (_provider == 'wave')
            Field(label: tr('Clé API Wave Business (droit « Balance & Reconciliation »)'), child: TextField(controller: _key, obscureText: true)),
          if (_provider == 'gocardless' && !widget.platformGc) ...[
            Text(tr('Créez un compte gratuit sur bankaccountdata.gocardless.com puis copiez vos identifiants :'), style: TextStyle(color: hint, fontSize: 12)),
            const SizedBox(height: 8),
            Field(label: tr('Secret ID'), child: TextField(controller: _sid)),
            Field(label: tr('Secret Key'), child: TextField(controller: _skey, obscureText: true)),
          ],
        ]),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
        child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          const SizedBox(width: 8),
          FilledButton(
            onPressed: _busy || _account == null
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      final r = await context.read<Session>().api.postLong('tresorerie/bank/connections', {
                        'provider': _provider, 'account_id': _account, 'label': _label.text.trim(),
                        if (_provider == 'wave') 'api_key': _key.text.trim(),
                        if (_sid.text.trim().isNotEmpty) 'secret_id': _sid.text.trim(),
                        if (_skey.text.trim().isNotEmpty) 'secret_key': _skey.text.trim(),
                      });
                      if (!context.mounted) return;
                      final s = r['sync'];
                      toast(context, r['warning'] != null ? '${r['warning']}' : (s != null ? 'Compte connecté : ${s['imported']} opération(s) importée(s).' : 'Connexion créée.'), error: r['warning'] != null);
                      Navigator.pop(context, true);
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            child: Text(tr('Connecter')),
          ),
        ]),
      ),
    ]);
  }
}

class _InstitutionPicker extends StatefulWidget {
  const _InstitutionPicker({required this.connection});

  final Map<String, dynamic> connection;

  @override
  State<_InstitutionPicker> createState() => _InstitutionPickerState();
}

class _InstitutionPickerState extends State<_InstitutionPicker> {
  String _country = 'fr';
  String _q = '';
  List<Map<String, dynamic>>? _list;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _list = null);
    try {
      final r = await context.read<Session>().api.get('tresorerie/bank/institutions', query: {'country': _country});
      if (mounted) setState(() => _list = [for (final i in (r['data'] as List)) asMap(i)]);
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  @override
  Widget build(BuildContext context) {
    final list = (_list ?? []).where((i) => _q.isEmpty || '${i['name']}'.toLowerCase().contains(_q.toLowerCase())).toList();
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
        child: Row(children: [
          SizedBox(
            width: 140,
            child: DropdownButtonFormField<String>(
              initialValue: _country,
              items: [
                DropdownMenuItem(value: 'fr', child: Text(tr('France'))),
                DropdownMenuItem(value: 'be', child: Text(tr('Belgique'))),
                DropdownMenuItem(value: 'de', child: Text(tr('Allemagne'))),
                DropdownMenuItem(value: 'es', child: Text(tr('Espagne'))),
                DropdownMenuItem(value: 'it', child: Text(tr('Italie'))),
                DropdownMenuItem(value: 'nl', child: Text(tr('Pays-Bas'))),
                DropdownMenuItem(value: 'pt', child: Text(tr('Portugal'))),
                DropdownMenuItem(value: 'gb', child: Text(tr('Royaume-Uni'))),
              ],
              onChanged: (v) {
                _country = v ?? 'fr';
                _load();
              },
            ),
          ),
          const SizedBox(width: 10),
          Expanded(child: SearchField(onChanged: (v) => setState(() => _q = v), hint: tr('Rechercher une banque…'))),
        ]),
      ),
      Expanded(
        child: _error != null
            ? Center(child: Text(tr(_error!), style: const TextStyle(color: YColors.danger)))
            : _list == null
                ? const Center(child: CircularProgressIndicator())
                : ListView(children: [
                    for (final i in list.take(200))
                      ListTile(
                        leading: i['logo'] != null ? CircleAvatar(backgroundColor: Colors.white, backgroundImage: NetworkImage('${i['logo']}')) : const CircleAvatar(child: Icon(Icons.account_balance)),
                        title: Text(tr('${i['name']}')),
                        subtitle: Text(tr('Historique : ${i['days']} jours')),
                        onTap: () async {
                          try {
                            final r = await context.read<Session>().api.post('tresorerie/bank/connections/${widget.connection['id']}/link', {'institution_id': i['id'], 'institution_name': i['name']});
                            await safeLaunchUrl(Uri.parse('${r['link']}'), webOnlyWindowName: '_blank');
                            if (context.mounted) {
                              toast(context, tr('Authentifiez-vous sur le site de votre banque, puis revenez ici : la synchronisation démarrera automatiquement.'));
                              Navigator.pop(context, true);
                            }
                          } catch (e) {
                            if (context.mounted) toast(context, errorText(e), error: true);
                          }
                        },
                      ),
                  ]),
      ),
    ]);
  }
}

class _RuleForm extends StatefulWidget {
  const _RuleForm({this.rule, required this.categories});

  final Map<String, dynamic>? rule;
  final List<Map<String, dynamic>> categories;

  @override
  State<_RuleForm> createState() => _RuleFormState();
}

class _RuleFormState extends State<_RuleForm> {
  late final _name = TextEditingController(text: widget.rule?['name'] ?? '');
  late final _contains = TextEditingController(text: widget.rule?['contains'] ?? '');
  late final _label = TextEditingController(text: widget.rule?['label'] ?? '');
  late final _min = TextEditingController(text: widget.rule?['amount_min'] == null ? '' : '${toDouble(widget.rule!['amount_min']).round()}');
  late final _max = TextEditingController(text: widget.rule?['amount_max'] == null ? '' : '${toDouble(widget.rule!['amount_max']).round()}');
  late String _direction = widget.rule?['direction'] ?? 'out';
  late String _action = widget.rule?['action'] ?? 'operation';
  late String? _category = widget.rule?['category'];

  @override
  Widget build(BuildContext context) {
    final cats = widget.categories.where((c) => _direction == 'any' || c['sign'] == 0 || (c['sign'] as num) == (_direction == 'in' ? 1 : -1) || (c['sign'] as num) == 1 && _direction == 'in').toList();
    return Column(children: [
      Expanded(
        child: ListView(padding: const EdgeInsets.all(20), children: [
          Field(label: tr('Nom de la règle'), child: TextField(controller: _name, decoration: InputDecoration(hintText: tr('Abonnement Canal+')))),
          Field(label: tr('Le libellé contient (mots séparés par des virgules)'), child: TextField(controller: _contains, decoration: InputDecoration(hintText: tr('canal, canal+')))),
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'out', label: Text(tr('Sorties'))), ButtonSegment(value: 'in', label: Text(tr('Entrées'))), ButtonSegment(value: 'any', label: Text(tr('Les deux')))],
            selected: {_direction},
            onSelectionChanged: (s) => setState(() => _direction = s.first),
          ),
          const SizedBox(height: 14),
          Row(children: [
            Expanded(child: Field(label: tr('Montant min.'), child: TextField(controller: _min, keyboardType: TextInputType.number))),
            const SizedBox(width: 10),
            Expanded(child: Field(label: tr('Montant max.'), child: TextField(controller: _max, keyboardType: TextInputType.number))),
          ]),
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'operation', icon: Icon(Icons.post_add), label: Text(tr('Créer l\'opération'))), ButtonSegment(value: 'ignore', icon: Icon(Icons.visibility_off), label: Text(tr('Ignorer')))],
            selected: {_action},
            onSelectionChanged: (s) => setState(() => _action = s.first),
          ),
          const SizedBox(height: 14),
          if (_action == 'operation') ...[
            Field(
              label: tr('Catégorie'),
              child: DropdownButtonFormField<String>(
                initialValue: cats.any((c) => c['key'] == _category) ? _category : null,
                items: [for (final c in cats) DropdownMenuItem(value: '${c['key']}', child: Text(tr('${c['label']}')))],
                onChanged: (v) => setState(() => _category = v),
              ),
            ),
            Field(label: tr('Libellé de l\'opération (facultatif)'), child: TextField(controller: _label)),
          ],
        ]),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
        child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          const SizedBox(width: 8),
          SaveButton(
            onPressed: () async {
              final body = {
                'name': _name.text.trim(), 'contains': _contains.text.trim(), 'direction': _direction, 'action': _action, 'category': _category,
                'label': _label.text.trim().isEmpty ? null : _label.text.trim(),
                'amount_min': _min.text.trim().isEmpty ? null : toDouble(_min.text), 'amount_max': _max.text.trim().isEmpty ? null : toDouble(_max.text),
              };
              try {
                final api = context.read<Session>().api;
                final r = widget.rule == null ? await api.post('tresorerie/bank/rules', body) : await api.put('tresorerie/bank/rules/${widget.rule!['id']}', body);
                if (!context.mounted) return;
                toast(context, (r['preview'] ?? 0) > 0 ? 'Règle enregistrée : ${r['preview']} ligne(s) à traiter correspondent (« Appliquer maintenant »).' : 'Règle enregistrée.');
                Navigator.pop(context, true);
              } catch (e) {
                if (context.mounted) toast(context, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ),
    ]);
  }
}
