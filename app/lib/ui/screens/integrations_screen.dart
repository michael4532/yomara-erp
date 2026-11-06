import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/api_client.dart' show ApiException;
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';

/// Intégrations & API : webhooks sortants (Zapier, Make, n8n…), clés d'API, flux de données (Google Sheets, Power BI).
class IntegrationsScreen extends StatefulWidget {
  const IntegrationsScreen({super.key});

  @override
  State<IntegrationsScreen> createState() => _IntegrationsScreenState();
}

class _IntegrationsScreenState extends State<IntegrationsScreen> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('integrations');
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

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? Center(child: Text(tr(errorText(_error!)))) : const Center(child: CircularProgressIndicator());
    return DefaultTabController(
      length: 4,
      child: Column(
        children: [
          PageHeader(
            title: tr('Intégrations & API'),
            subtitle: tr('Connectez Yomara à Zapier, Make, n8n, Slack, Teams, Telegram, Google Sheets, Power BI, votre site ou vos applications'),
            actions: [
              OutlinedButton.icon(
                onPressed: () => safeLaunchUrl(Uri.parse('${_d!['docs_url']}'), webOnlyWindowName: '_blank'),
                icon: const Icon(Icons.menu_book_outlined),
                label: Text(tr('Documentation de l\'API')),
              ),
            ],
          ),
          TabBar(
            isScrollable: true,
            padding: const EdgeInsets.symmetric(horizontal: 8),
            tabAlignment: TabAlignment.start,
            tabs: [
              Tab(icon: Icon(Icons.webhook, size: 18), text: tr('Webhooks')),
              Tab(icon: Icon(Icons.key_outlined, size: 18), text: tr('Clés d\'API')),
              Tab(icon: Icon(Icons.table_view_outlined, size: 18), text: tr('Flux de données')),
              Tab(icon: Icon(Icons.forum_outlined, size: 18), text: tr('Canaux d\'équipe')),
            ],
          ),
          Expanded(
            child: TabBarView(
              children: [
                _WebhooksTab(endpoints: _list('endpoints'), events: _list('events'), onChanged: _load),
                _KeysTab(keys: _list('keys'), base: '${_d!['api_base']}', onChanged: _load),
                _FeedsTab(feeds: _list('feeds'), datasets: _list('datasets'), onChanged: _load),
                _ChannelsTab(channels: _list('channels'), kinds: _list('channel_kinds'), topics: _list('channel_topics'), onChanged: _load),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

Future<void> _copy(BuildContext context, String v, [String msg = 'Copié.']) async {
  await Clipboard.setData(ClipboardData(text: v));
  if (context.mounted) toast(context, msg);
}

Future<void> _secretDialog(BuildContext context, String title, String value, String help) => showDialog(
  context: context,
  builder: (c) => YAlertDialog(
    title: Text(tr(title)),
    content: SizedBox(
      width: 520,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(tr(help)),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: YColors.night, borderRadius: BorderRadius.circular(10)),
            child: SelectableText(
              tr(value),
              style: const TextStyle(color: Colors.white, fontFamily: 'monospace'),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            tr('Elle ne sera plus affichée : conservez-la maintenant.'),
            style: TextStyle(color: YColors.danger, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    ),
    actions: [
      TextButton(onPressed: () => _copy(c, value), child: Text(tr('Copier'))),
      FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('J\'ai copié'))),
    ],
  ),
);

// ---------------------------------------------------------------------------- Webhooks

class _WebhooksTab extends StatelessWidget {
  const _WebhooksTab({required this.endpoints, required this.events, required this.onChanged});

  final List<Map<String, dynamic>> endpoints, events;
  final VoidCallback onChanged;

  Future<void> _edit(BuildContext context, [Map<String, dynamic>? e]) async {
    final r = await showFormDialog<Map<String, dynamic>>(
      context,
      title: e == null ? 'Nouveau webhook' : 'Modifier le webhook',
      maxWidth: 680,
      builder: (_) => _EndpointForm(endpoint: e, events: events),
    );
    if (r == null || !context.mounted) return;
    if (r['secret'] != null) {
      await _secretDialog(context, 'Secret de signature', '${r['secret']}', 'Utilisez ce secret pour vérifier l\'en-tête X-Yomara-Signature de chaque appel (exemples dans la documentation).');
    }
    onChanged();
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final hint = Theme.of(context).hintColor;
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(tr('Yomara appelle vos adresses à chaque événement choisi : facture payée, nouvelle commande, client créé…'), style: TextStyle(color: hint)),
            ),
            FilledButton.icon(onPressed: () => _edit(context), icon: const Icon(Icons.add), label: Text(tr('Webhook'))),
          ],
        ),
        const SizedBox(height: 12),
        if (endpoints.isEmpty)
          EmptyState(icon: Icons.webhook, title: tr('Aucun webhook'), message: tr('Collez l\'adresse d\'un déclencheur Zapier (« Catch Hook »), Make (« Custom webhook »), n8n ou de votre site.'))
        else
          for (final e in endpoints)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(Icons.webhook, color: e['disabled_at'] != null || e['active'] != true ? YColors.muted : YColors.blue),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(tr('${e['description'] ?? e['url']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                              Text(
                                tr('${e['url']}'),
                                style: TextStyle(color: hint, fontSize: 12),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ],
                          ),
                        ),
                        if (e['disabled_at'] != null)
                          StatusChip(tr('Désactivé (échecs)'), color: YColors.danger)
                        else if (e['active'] != true)
                          StatusChip(tr('En pause'))
                        else
                          StatusChip(tr('Actif'), color: YColors.success),
                        PopupMenuButton<String>(
                          onSelected: (v) async {
                            try {
                              if (v == 'edit') return await _edit(context, e);
                              if (v == 'secret') {
                                final r = await api.post('integrations/webhooks/${e['id']}/secret');
                                if (context.mounted) await _secretDialog(context, 'Nouveau secret', '${r['secret']}', 'L\'ancien secret ne signe plus les appels.');
                              }
                              if (v == 'delete') {
                                if (!context.mounted || !await confirm(context, tr('Supprimer le webhook ?'), tr('Les envois en attente seront abandonnés.'), danger: true, ok: tr('Supprimer'))) {
                                  return;
                                }
                                await api.delete('integrations/webhooks/${e['id']}');
                              }
                              onChanged();
                            } catch (x) {
                              if (context.mounted) toast(context, errorText(x), error: true);
                            }
                          },
                          itemBuilder: (_) => [
                            PopupMenuItem(value: 'edit', child: Text(tr('Modifier'))),
                            PopupMenuItem(value: 'secret', child: Text(tr('Régénérer le secret'))),
                            PopupMenuItem(
                              value: 'delete',
                              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                            ),
                          ],
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        for (final ev in (e['events'] as List))
                          Chip(
                            label: Text(tr('$ev'), style: const TextStyle(fontSize: 11)),
                            visualDensity: VisualDensity.compact,
                          ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 14,
                      children: [
                        Text(tr('7 jours : ${e['stats']['success']} réussi(s)'), style: const TextStyle(color: YColors.success, fontSize: 12)),
                        if ((e['stats']['failed'] ?? 0) > 0) Text(tr('${e['stats']['failed']} échec(s)'), style: const TextStyle(color: YColors.danger, fontSize: 12)),
                        if ((e['stats']['pending'] ?? 0) > 0) Text(tr('${e['stats']['pending']} en relance'), style: const TextStyle(color: YColors.warning, fontSize: 12)),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      children: [
                        OutlinedButton.icon(
                          onPressed: () async {
                            try {
                              final r = await api.post('integrations/webhooks/${e['id']}/test');
                              final d = asMap(r['data']);
                              if (context.mounted) {
                                toast(
                                  context,
                                  d['status'] == 'success'
                                      ? 'Test réussi : réponse ${d['response_code']} en ${d['duration_ms']} ms.'
                                      : 'Échec : ${d['response_code'] ?? ''} ${d['response_body'] ?? ''}',
                                  error: d['status'] != 'success',
                                );
                              }
                              onChanged();
                            } catch (x) {
                              if (context.mounted) toast(context, errorText(x), error: true);
                            }
                          },
                          icon: const Icon(Icons.send, size: 18),
                          label: Text(tr('Tester')),
                        ),
                        TextButton.icon(
                          onPressed: () => showFormDialog(
                            context,
                            title: tr('Envois récents'),
                            maxWidth: 760,
                            builder: (_) => _Deliveries(endpointId: e['id'] as int),
                          ),
                          icon: const Icon(Icons.history, size: 18),
                          label: Text(tr('Envois récents')),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
      ],
    );
  }
}

class _EndpointForm extends StatefulWidget {
  const _EndpointForm({this.endpoint, required this.events});

  final Map<String, dynamic>? endpoint;
  final List<Map<String, dynamic>> events;

  @override
  State<_EndpointForm> createState() => _EndpointFormState();
}

class _EndpointFormState extends State<_EndpointForm> {
  late final _url = TextEditingController(text: widget.endpoint?['url'] ?? '');
  late final _desc = TextEditingController(text: widget.endpoint?['description'] ?? '');
  late final Set<String> _sel = {
    for (final e in (widget.endpoint?['events'] as List? ?? const ['invoice.paid'])) '$e',
  };
  late bool _active = widget.endpoint?['active'] ?? true;

  @override
  Widget build(BuildContext context) {
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final e in widget.events) {
      groups.putIfAbsent('${e['group']}', () => []).add(e);
    }
    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Field(
                label: tr('Adresse (URL) qui recevra les événements'),
                child: TextField(
                  controller: _url,
                  decoration: InputDecoration(hintText: tr('https://hooks.zapier.com/hooks/catch/…')),
                ),
              ),
              Field(
                label: tr('Nom'),
                child: TextField(
                  controller: _desc,
                  decoration: InputDecoration(hintText: tr('Zapier — factures payées')),
                ),
              ),
              SwitchListTile(contentPadding: EdgeInsets.zero, value: _active, onChanged: (v) => setState(() => _active = v), title: Text(tr('Actif'))),
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: _sel.contains('*'),
                onChanged: (v) => setState(() => v == true ? _sel.add('*') : _sel.remove('*')),
                title: Text(tr('Tous les événements (*)')),
              ),
              if (!_sel.contains('*'))
                for (final g in groups.entries)
                  ExpansionTile(
                    tilePadding: EdgeInsets.zero,
                    initiallyExpanded: g.key == 'Événements métier',
                    title: Text(tr(g.key), style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(tr('${g.value.where((e) => _sel.contains(e['key'])).length} choisi(s)')),
                    children: [
                      for (final e in g.value)
                        CheckboxListTile(
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          value: _sel.contains(e['key']),
                          onChanged: (v) => setState(() => v == true ? _sel.add('${e['key']}') : _sel.remove(e['key'])),
                          title: Text(tr('${e['label']}')),
                          subtitle: Text(tr('${e['key']}'), style: const TextStyle(fontFamily: 'monospace', fontSize: 11)),
                        ),
                    ],
                  ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
              const SizedBox(width: 8),
              FilledButton(
                onPressed: () async {
                  final body = {'url': _url.text.trim(), 'description': _desc.text.trim(), 'events': _sel.toList(), 'active': _active};
                  try {
                    final api = context.read<Session>().api;
                    final r = widget.endpoint == null ? await api.post('integrations/webhooks', body) : await api.put('integrations/webhooks/${widget.endpoint!['id']}', body);
                    if (context.mounted) Navigator.pop(context, asMap(r));
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _Deliveries extends StatefulWidget {
  const _Deliveries({required this.endpointId});

  final int endpointId;

  @override
  State<_Deliveries> createState() => _DeliveriesState();
}

class _DeliveriesState extends State<_Deliveries> {
  List<Map<String, dynamic>>? _list;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('integrations/webhooks/${widget.endpointId}/deliveries');
      if (mounted) setState(() => _list = [for (final d in (r['data'] as List)) asMap(d)]);
    } catch (e) {
      // Hors ligne ou serveur injoignable : on arrête le chargement au lieu de tourner sans fin
      if (mounted) {
        setState(() => _list = []);
        toast(context, e is ApiException ? e.message : tr('Connexion impossible : réessayez plus tard.'), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_list == null) return const Center(child: CircularProgressIndicator());
    if (_list!.isEmpty) return EmptyState(icon: Icons.history, title: tr('Aucun envoi pour le moment'));
    final api = context.read<Session>().api;
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        for (final d in _list!)
          ListTile(
            leading: Icon(
              d['status'] == 'success'
                  ? Icons.check_circle
                  : d['status'] == 'pending'
                  ? Icons.schedule
                  : Icons.error,
              color: d['status'] == 'success'
                  ? YColors.success
                  : d['status'] == 'pending'
                  ? YColors.warning
                  : YColors.danger,
            ),
            title: Text(tr('${d['event']}'), style: const TextStyle(fontFamily: 'monospace')),
            subtitle: Text(
              tr(
                '${dateTimeFr(d['created_at'])} · ${d['attempts']} essai(s)${d['response_code'] != null ? ' · HTTP ${d['response_code']}' : ''}${d['duration_ms'] != null ? ' · ${d['duration_ms']} ms' : ''}',
              ),
            ),
            trailing: Wrap(
              children: [
                IconButton(
                  tooltip: tr('Contenu'),
                  icon: const Icon(Icons.data_object),
                  onPressed: () async {
                    final r = await api.get('integrations/deliveries/${d['id']}');
                    if (!context.mounted) return;
                    final full = asMap(r['data']);
                    showDialog(
                      context: context,
                      builder: (c) => YAlertDialog(
                        title: Text(tr('${d['event']}')),
                        content: SizedBox(
                          width: 640,
                          child: SingleChildScrollView(
                            child: SelectableText(
                              tr('${const JsonEncoder.withIndent('  ').convert(full['payload'])}\n\n— Réponse —\n${full['response_body'] ?? ''}'),
                              style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
                            ),
                          ),
                        ),
                        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
                      ),
                    );
                  },
                ),
                IconButton(
                  tooltip: tr('Renvoyer'),
                  icon: const Icon(Icons.replay),
                  onPressed: () async {
                    try {
                      final r = await api.post('integrations/deliveries/${d['id']}/redeliver');
                      if (context.mounted) {
                        toast(
                          context,
                          r['data']['status'] == 'success' ? 'Renvoyé avec succès.' : 'Nouvel échec (HTTP ${r['data']['response_code'] ?? '—'}).',
                          error: r['data']['status'] != 'success',
                        );
                      }
                      _load();
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                ),
              ],
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------- Clés d'API

class _KeysTab extends StatelessWidget {
  const _KeysTab({required this.keys, required this.base, required this.onChanged});

  final List<Map<String, dynamic>> keys;
  final String base;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final hint = Theme.of(context).hintColor;
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(tr('Une clé permet à un autre logiciel de lire (ou d\'écrire) vos données avec vos droits. Adresse de l\'API : $base'), style: TextStyle(color: hint)),
            ),
            FilledButton.icon(
              onPressed: () async {
                final name = TextEditingController();
                var write = false;
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (c) => StatefulBuilder(
                    builder: (c, set) => YAlertDialog(
                      title: Text(tr('Nouvelle clé d\'API')),
                      content: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          TextField(
                            controller: name,
                            autofocus: true,
                            decoration: InputDecoration(labelText: tr('Nom (outil qui l\'utilisera)'), hintText: tr('Power BI, site web, Make…')),
                          ),
                          SwitchListTile(
                            contentPadding: EdgeInsets.zero,
                            value: write,
                            onChanged: (v) => set(() => write = v),
                            title: Text(tr('Autoriser l\'écriture')),
                            subtitle: Text(tr('Sinon : lecture seule')),
                          ),
                        ],
                      ),
                      actions: [
                        TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                        FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Créer'))),
                      ],
                    ),
                  ),
                );
                if (ok != true || name.text.trim().isEmpty || !context.mounted) return;
                try {
                  final r = await api.post('integrations/keys', {'name': name.text.trim(), 'write': write});
                  if (context.mounted) await _secretDialog(context, 'Votre clé d\'API', '${r['token']}', 'En-tête à envoyer : Authorization: Bearer <clé>');
                  onChanged();
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.add),
              label: Text(tr('Clé')),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (keys.isEmpty)
          EmptyState(icon: Icons.key_off_outlined, title: tr('Aucune clé d\'API'))
        else
          Card(
            child: Column(
              children: [
                for (final k in keys)
                  ListTile(
                    leading: Icon(k['write'] == true ? Icons.edit_note : Icons.visibility_outlined, color: YColors.blue),
                    title: Text(tr('${k['name']}')),
                    subtitle: Text(
                      tr(
                        '${k['write'] == true ? 'Lecture et écriture' : 'Lecture seule'} · créée par ${k['user'] ?? '—'} · ${k['last_used_at'] != null ? 'utilisée le ${dateTimeFr(k['last_used_at'])}' : 'jamais utilisée'}',
                      ),
                    ),
                    trailing: TextButton(
                      onPressed: () async {
                        if (!await confirm(context, tr('Révoquer la clé ?'), tr('Les outils qui l\'utilisent n\'auront plus accès.'), danger: true, ok: tr('Révoquer'))) return;
                        await api.delete('integrations/keys/${k['id']}');
                        onChanged();
                      },
                      child: Text(tr('Révoquer'), style: TextStyle(color: YColors.danger)),
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------- Flux de données

class _FeedsTab extends StatelessWidget {
  const _FeedsTab({required this.feeds, required this.datasets, required this.onChanged});

  final List<Map<String, dynamic>> feeds, datasets;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final hint = Theme.of(context).hintColor;
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(tr('Un lien secret toujours à jour : collez-le dans Google Sheets (=IMPORTDATA), Excel (Données › À partir du web) ou Power BI.'), style: TextStyle(color: hint)),
            ),
            FilledButton.icon(
              onPressed: () async {
                String ds = '${datasets.first['key']}';
                int days = 0;
                final name = TextEditingController();
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (c) => StatefulBuilder(
                    builder: (c, set) => YAlertDialog(
                      title: Text(tr('Nouveau flux de données')),
                      content: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          DropdownButtonFormField<String>(
                            initialValue: ds,
                            decoration: InputDecoration(labelText: tr('Données')),
                            items: [for (final d in datasets) DropdownMenuItem(value: '${d['key']}', child: Text(tr('${d['label']}')))],
                            onChanged: (v) => set(() => ds = v ?? ds),
                          ),
                          const SizedBox(height: 10),
                          DropdownButtonFormField<int>(
                            initialValue: days,
                            decoration: InputDecoration(labelText: tr('Période')),
                            items: [
                              DropdownMenuItem(value: 0, child: Text(tr('Tout l\'historique'))),
                              DropdownMenuItem(value: 30, child: Text(tr('30 derniers jours'))),
                              DropdownMenuItem(value: 90, child: Text(tr('3 derniers mois'))),
                              DropdownMenuItem(value: 365, child: Text(tr('12 derniers mois'))),
                            ],
                            onChanged: (v) => set(() => days = v ?? 0),
                          ),
                          const SizedBox(height: 10),
                          TextField(
                            controller: name,
                            decoration: InputDecoration(labelText: tr('Nom'), hintText: tr('Tableau de bord Sheets')),
                          ),
                        ],
                      ),
                      actions: [
                        TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                        FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Créer'))),
                      ],
                    ),
                  ),
                );
                if (ok != true || !context.mounted) return;
                try {
                  await api.post('integrations/feeds', {'dataset': ds, 'days': days, 'name': name.text.trim().isEmpty ? datasets.firstWhere((d) => d['key'] == ds)['label'] : name.text.trim()});
                  onChanged();
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.add),
              label: Text(tr('Flux')),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (feeds.isEmpty)
          EmptyState(icon: Icons.table_view_outlined, title: tr('Aucun flux'), message: tr('Exemple : vos factures dans un Google Sheets partagé avec votre comptable, mises à jour automatiquement.'))
        else
          for (final f in feeds)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.table_view_outlined, color: YColors.success),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(tr('${f['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        ),
                        Text(tr('${f['hits']} lecture(s)'), style: TextStyle(color: hint, fontSize: 12)),
                        IconButton(
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () async {
                            if (!await confirm(context, tr('Supprimer le flux ?'), tr('Les classeurs qui l\'utilisent ne seront plus mis à jour.'), danger: true, ok: tr('Supprimer'))) return;
                            await api.delete('integrations/feeds/${f['id']}');
                            onChanged();
                          },
                        ),
                      ],
                    ),
                    Text(tr('${f['dataset_label']}${(f['days'] ?? 0) > 0 ? ' · ${f['days']} derniers jours' : ''} · droits de ${f['owner'] ?? '—'}'), style: TextStyle(color: hint, fontSize: 12)),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        ActionChip(
                          avatar: const Icon(Icons.grid_on, size: 16),
                          label: Text(tr('Formule Google Sheets')),
                          onPressed: () => _copy(context, '${f['sheets']}', 'Formule copiée : collez-la dans la cellule A1.'),
                        ),
                        ActionChip(avatar: const Icon(Icons.link, size: 16), label: Text(tr('Lien CSV (Excel)')), onPressed: () => _copy(context, '${f['csv']}')),
                        ActionChip(avatar: const Icon(Icons.data_object, size: 16), label: Text(tr('Lien JSON (Power BI)')), onPressed: () => _copy(context, '${f['json']}')),
                      ],
                    ),
                  ],
                ),
              ),
            ),
      ],
    );
  }
}

/// Canaux d'équipe : notifications de l'ERP relayées dans Slack, Teams, Discord, Telegram, Google Chat.
class _ChannelsTab extends StatelessWidget {
  const _ChannelsTab({required this.channels, required this.kinds, required this.topics, required this.onChanged});

  final List<Map<String, dynamic>> channels;
  final List<Map<String, dynamic>> kinds;
  final List<Map<String, dynamic>> topics;
  final VoidCallback onChanged;

  static const _icons = {'slack': Icons.tag, 'teams': Icons.groups_outlined, 'discord': Icons.headset_mic_outlined, 'telegram': Icons.send_outlined, 'google_chat': Icons.chat_outlined};

  Future<void> _edit(BuildContext context, [Map<String, dynamic>? ch]) async {
    final api = context.read<Session>().api;
    var kind = '${ch?['kind'] ?? 'slack'}';
    final name = TextEditingController(text: '${ch?['name'] ?? ''}');
    final url = TextEditingController(), token = TextEditingController(), chat = TextEditingController();
    final events = <String>{
      ...[
        for (final e in (ch?['events'] as List? ?? const ['*'])) '$e',
      ],
    };
    const help = {
      'slack': 'Slack › Applications › « Incoming Webhooks » › choisir le canal › copier l\'adresse (https://hooks.slack.com/services/…).',
      'teams': 'Teams › canal › Workflows › « Publier dans un canal lorsqu\'une requête webhook est reçue » › copier l\'adresse.',
      'discord': 'Discord › Paramètres du salon › Intégrations › Webhooks › Nouveau webhook › copier l\'URL.',
      'telegram': 'Créez un bot avec @BotFather (jeton), ajoutez-le au groupe, puis indiquez l\'identifiant du groupe (ex. -100123…).',
      'google_chat': 'Google Chat › espace › Applications et intégrations › Webhooks › copier l\'adresse.',
    };
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr(ch == null ? 'Nouveau canal' : 'Modifier le canal')),
          content: SizedBox(
            width: 520,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (ch == null)
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        for (final k in kinds)
                          ChoiceChip(avatar: Icon(_icons[k['key']], size: 16), label: Text(tr('${k['label']}')), selected: kind == k['key'], onSelected: (_) => set(() => kind = '${k['key']}')),
                      ],
                    ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: name,
                    decoration: InputDecoration(labelText: tr('Nom (ex. #parc-auto, Direction)')),
                  ),
                  const SizedBox(height: 10),
                  if (kind == 'telegram') ...[
                    TextField(
                      controller: token,
                      decoration: InputDecoration(labelText: trn(ch == null ? 'Jeton du bot' : 'Nouveau jeton (laisser vide pour garder)')),
                    ),
                    const SizedBox(height: 10),
                    TextField(
                      controller: chat,
                      decoration: InputDecoration(labelText: tr('Identifiant de la conversation')),
                    ),
                  ] else
                    TextField(
                      controller: url,
                      decoration: InputDecoration(labelText: trn(ch == null ? 'Adresse du webhook' : 'Nouvelle adresse (laisser vide pour garder)')),
                    ),
                  const SizedBox(height: 6),
                  Text(tr(help[kind] ?? ''), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
                  const SizedBox(height: 14),
                  Text(tr('Notifications à relayer'), style: TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      FilterChip(
                        label: Text(tr('Toutes')),
                        selected: events.contains('*'),
                        onSelected: (v) => set(
                          () => v
                              ? (events
                                  ..clear()
                                  ..add('*'))
                              : events.remove('*'),
                        ),
                      ),
                      for (final t in topics)
                        FilterChip(
                          label: Text(tr('${t['label']}')),
                          selected: events.contains(t['key']),
                          onSelected: (v) => set(() {
                            events.remove('*');
                            v ? events.add('${t['key']}') : events.remove(t['key']);
                          }),
                        ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
            FilledButton(onPressed: events.isEmpty ? null : () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    );
    if (ok != true || !context.mounted) return;
    final body = {
      'name': name.text.trim().isEmpty ? kinds.firstWhere((k) => k['key'] == kind)['label'] : name.text.trim(),
      'events': events.toList(),
      if (url.text.trim().isNotEmpty) 'url': url.text.trim(),
      if (token.text.trim().isNotEmpty) 'token': token.text.trim(),
      if (chat.text.trim().isNotEmpty) 'chat_id': chat.text.trim(),
    };
    try {
      ch == null ? await api.post('integrations/channels', {'kind': kind, ...body}) : await api.put('integrations/channels/${ch['id']}', body);
      onChanged();
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final topicLabel = {for (final t in topics) '${t['key']}': '${t['label']}'};
    return ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                tr('Les alertes de l\'ERP (demandes de véhicule, contrats à renouveler, publications à valider, tâches de développement, appels de fonds…) arrivent dans le canal de chaque service.'),
                style: TextStyle(color: Theme.of(context).hintColor),
              ),
            ),
            const SizedBox(width: 12),
            FilledButton.icon(onPressed: () => _edit(context), icon: const Icon(Icons.add), label: Text(tr('Canal'))),
          ],
        ),
        const SizedBox(height: 14),
        if (channels.isEmpty)
          EmptyState(icon: Icons.forum_outlined, title: tr('Aucun canal'), message: tr('Reliez un canal Slack, Microsoft Teams, Discord, Telegram ou Google Chat.'))
        else
          for (final ch in channels)
            Card(
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: YColors.blue.withValues(alpha: .1),
                  child: Icon(_icons[ch['kind']], color: YColors.blue),
                ),
                title: Row(
                  children: [
                    Flexible(
                      child: Text(tr('${ch['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    ),
                    const SizedBox(width: 8),
                    StatusChip(
                      ch['active'] == true ? (ch['last_error'] != null ? 'Erreur' : 'Actif') : 'En pause',
                      color: ch['active'] == true ? (ch['last_error'] != null ? YColors.danger : YColors.success) : YColors.muted,
                    ),
                  ],
                ),
                subtitle: Text(
                  tr(
                    [
                      '${ch['kind_label']} · ${ch['target']}',
                      (ch['events'] as List).contains('*') ? 'Toutes les notifications' : (ch['events'] as List).map((e) => topicLabel[e] ?? e).join(', '),
                      '${ch['sent_count']} message(s)${ch['last_sent_at'] != null ? ' · dernier ${dateTimeFr(ch['last_sent_at'])}' : ''}',
                      if (ch['last_error'] != null) '${ch['last_error']}',
                    ].join('\n'),
                  ),
                ),
                isThreeLine: true,
                trailing: PopupMenuButton<String>(
                  onSelected: (v) async {
                    try {
                      if (v == 'test') {
                        await api.post('integrations/channels/${ch['id']}/test');
                        if (context.mounted) toast(context, tr('Message de test envoyé.'));
                      } else if (v == 'edit') {
                        await _edit(context, ch);
                        return;
                      } else if (v == 'toggle') {
                        await api.put('integrations/channels/${ch['id']}', {'active': ch['active'] != true});
                      } else if (v == 'delete') {
                        if (!context.mounted || !await confirm(context, tr('Supprimer le canal ?'), tr('Il ne recevra plus de notifications.'), danger: true, ok: tr('Supprimer'))) return;
                        await api.delete('integrations/channels/${ch['id']}');
                      }
                      onChanged();
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                      onChanged();
                    }
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'test', child: Text(tr('Envoyer un test'))),
                    PopupMenuItem(value: 'edit', child: Text(tr('Modifier'))),
                    PopupMenuItem(value: 'toggle', child: Text(tr(ch['active'] == true ? 'Mettre en pause' : 'Réactiver'))),
                    PopupMenuItem(
                      value: 'delete',
                      child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                    ),
                  ],
                ),
              ),
            ),
      ],
    );
  }
}
