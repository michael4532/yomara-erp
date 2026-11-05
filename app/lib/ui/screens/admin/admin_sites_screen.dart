import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../sites_screen.dart' show siteRequestLabels, siteRequestColor, siteDomainLabels, openUrl;
import '../../../i18n/i18n.dart';
import '../../../core/json.dart';

/// Super admin : demandes de sites (modèle ou sur mesure), devis, création, sites en ligne, réglages DNS.
class AdminSitesScreen extends StatefulWidget {
  const AdminSitesScreen({super.key});

  @override
  State<AdminSitesScreen> createState() => _AdminSitesScreenState();
}

class _AdminSitesScreenState extends State<AdminSitesScreen> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('admin/sites');
      if (mounted) setState(() => (_d = asMap(r), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _act(String path, [Map<String, dynamic>? body, String done = 'Fait.']) async {
    try {
      await context.read<Session>().api.post(path, body);
      await _load();
      if (mounted) toast(context, done);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _d == null) return EmptyState(icon: Icons.cloud_off, title: tr('Chargement impossible'), message: errorText(_error!));
    final d = _d;
    if (d == null) return const LoadingSkeleton();
    final reqs = [for (final r in d['requests'] as List) asMap(r)];
    final sites = [for (final s in d['sites'] as List) asMap(s)];
    final todo = reqs.where((r) => ['submitted', 'accepted', 'in_progress'].contains(r['status'])).length;
    return DefaultTabController(
      length: 3,
      child: Column(children: [
        PageHeader(
          title: tr('Sites vitrines'),
          subtitle: tr('${sites.length} site(s) · ${sites.where((s) => s['status'] == 'published').length} en ligne · $todo demande(s) à traiter'),
          actions: [IconButton.outlined(onPressed: _load, icon: const Icon(Icons.refresh))],
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
          Tab(text: tr('Demandes${todo > 0 ? ' ($todo)' : ''}')),
          Tab(text: tr('Sites (${sites.length})')),
          Tab(text: tr('Réglages')),
        ]),
        const Divider(height: 1),
        Expanded(
          child: TabBarView(children: [
            _requests(reqs, d),
            _sites(sites),
            _Settings(settings: asMap(d['settings']), onSaved: _load),
          ]),
        ),
      ]),
    );
  }

  Widget _requests(List<Map<String, dynamic>> reqs, Map<String, dynamic> d) {
    final cur = context.watch<Session>().currency;
    final hint = Theme.of(context).hintColor;
    if (reqs.isEmpty) return EmptyState(icon: Icons.inbox_outlined, title: tr('Aucune demande de site'));
    return ListView(padding: const EdgeInsets.all(16), children: [
      for (final (i, r) in reqs.indexed)
        AnimatedFadeIn(
          index: i % 10,
          child: Card(
            margin: const EdgeInsets.only(bottom: 10),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Icon(r['type'] == 'custom' ? Icons.design_services_outlined : Icons.grid_view_rounded, color: YColors.blue),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${r['company']?['name'] ?? '—'} · ${r['site_name']}'), style: const TextStyle(fontWeight: FontWeight.w800)),
                      Text(tr([
                        '${r['number']}',
                        r['type'] == 'custom' ? 'Sur mesure' : 'Modèle ${r['template_name'] ?? r['template']}',
                        dateFr('${r['created_at']}'.substring(0, 10)),
                        if (r['company']?['phone'] != null) '${r['company']['phone']}',
                        if (r['company']?['email'] != null) '${r['company']['email']}',
                      ].join(' · ')), style: TextStyle(color: hint, fontSize: 12.5)),
                    ]),
                  ),
                  StatusChip(siteRequestLabels[r['status']] ?? '${r['status']}', color: siteRequestColor('${r['status']}')),
                ]),
                if (r['type'] == 'custom' && r['needs'] is Map) ...[
                  const SizedBox(height: 8),
                  if ((r['needs']['description'] ?? '') != '') Text(tr('${r['needs']['description']}')),
                  const SizedBox(height: 6),
                  Wrap(spacing: 6, runSpacing: 6, children: [
                    for (final p in (r['needs']['pages'] as List? ?? const [])) Chip(label: Text(tr('$p')), visualDensity: VisualDensity.compact),
                    for (final f in (r['needs']['features'] as List? ?? const [])) Chip(avatar: const Icon(Icons.add, size: 14), label: Text(tr('${(d['pricing']?['features']?[f]?['label']) ?? f}')), visualDensity: VisualDensity.compact),
                    if (r['needs']['urgent'] == true) Chip(label: Text(tr('Express')), visualDensity: VisualDensity.compact),
                  ]),
                  Text(tr([
                    'Estimation ${money(toDouble(r['estimate']), cur)} TTC',
                    if (r['needs']['budget'] != null) 'budget ${money(toDouble(r['needs']['budget']), cur)}',
                    if (r['needs']['deadline'] != null) 'pour le ${dateFr(r['needs']['deadline'])}',
                    if (r['needs']['domain'] != null) 'domaine ${r['needs']['domain']}',
                    if (r['needs']['references'] != null) 'références : ${r['needs']['references']}',
                  ].join(' · ')), style: TextStyle(color: hint, fontSize: 12.5)),
                ],
                if (r['quote'] is Map) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Devis ${r['quote']['number']} : ${money(toDouble(r['quote']['total']), cur)} TTC'), style: const TextStyle(fontWeight: FontWeight.w700))),
                if (r['admin_note'] != null && '${r['admin_note']}'.trim().isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr('${r['admin_note']}'), style: TextStyle(color: hint))),
                if (!['delivered', 'rejected', 'cancelled'].contains(r['status']))
                  Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.end, children: [
                      TextButton(
                        style: TextButton.styleFrom(foregroundColor: YColors.danger),
                        onPressed: () async {
                          final reason = await _ask(context, 'Refuser la demande', 'Motif communiqué au client');
                          if (reason != null) await _act('admin/sites/requests/${r['id']}/reject', {'reason': reason}, 'Demande refusée.');
                        },
                        child: Text(tr('Refuser')),
                      ),
                      if (r['type'] == 'custom' && ['submitted', 'quoted', 'refused'].contains(r['status']))
                        FilledButton.tonalIcon(onPressed: () => _quote(r), icon: const Icon(Icons.request_quote_outlined), label: Text(tr(r['quote'] == null ? 'Établir le devis' : 'Réviser le devis'))),
                      if (r['status'] == 'accepted') OutlinedButton(onPressed: () => _act('admin/sites/requests/${r['id']}/progress', null, 'Réalisation lancée.'), child: Text(tr('Passer en réalisation'))),
                      if (r['type'] == 'template' || ['accepted', 'in_progress'].contains(r['status']))
                        FilledButton.icon(onPressed: () => _deliver(r, d), icon: const Icon(Icons.rocket_launch_outlined), label: Text(tr('Créer le site'))),
                    ]),
                  ),
              ]),
            ),
          ),
        ),
    ]);
  }

  Future<void> _deliver(Map<String, dynamic> r, Map<String, dynamic> d) async {
    final tpls = [for (final t in d['templates'] as List) asMap(t)];
    String tpl = '${r['template'] ?? '${r['sector'] ?? 'agence'}-eclat'}';
    if (!tpls.any((t) => t['key'] == tpl)) tpl = '${tpls.first['key']}';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Créer le site de ${r['company']?['name']}')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              DropdownButtonFormField<String>(
                initialValue: tpl,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Modèle de départ')),
                items: [for (final t in tpls) DropdownMenuItem(value: '${t['key']}', child: Text(tr('${t['name']}')))],
                onChanged: (v) => set(() => tpl = v!),
              ),
              const SizedBox(height: 10),
              Text(tr('Le site est créé en brouillon avec les données de la société (coordonnées, logo, produits, événements, rendez-vous) ; le client est prévenu et le publie.'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 13)),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Créer')))],
        ),
      ),
    );
    if (ok == true) await _act('admin/sites/requests/${r['id']}/deliver', {'template': tpl}, 'Site créé : le client est prévenu.');
  }

  Future<void> _quote(Map<String, dynamic> r) async {
    final cur = context.read<Session>().currency;
    final base = (r['quote'] is Map ? r['quote']['lines'] : r['estimate_detail']?['lines']) as List? ?? const [];
    final lines = [
      for (final l in base)
        {'description': TextEditingController(text: '${(l as Map)['description']}'), 'quantity': TextEditingController(text: '${toDouble(l['quantity']).round()}'), 'unit_price': TextEditingController(text: '${toDouble(l['unit_price']).round()}')},
    ];
    final note = TextEditingController(text: '${r['admin_note'] ?? ''}');
    final disc = TextEditingController(text: '${toDouble(r['quote']?['discount_percent']).round()}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(builder: (c, set) {
        final sub = lines.fold<double>(0, (s, l) => s + toDouble(l['quantity']!.text) * toDouble(l['unit_price']!.text)) * (1 - toDouble(disc.text) / 100);
        return YAlertDialog(
          title: Text(tr('Devis — ${r['company']?['name']}')),
          content: SizedBox(
            width: 680,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                for (final (i, l) in lines.indexed)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Row(children: [
                      Expanded(flex: 5, child: TextField(controller: l['description'], decoration: InputDecoration(isDense: true, labelText: tr('Désignation')))),
                      const SizedBox(width: 8),
                      SizedBox(width: 60, child: TextField(controller: l['quantity'], onChanged: (_) => set(() {}), keyboardType: TextInputType.number, decoration: InputDecoration(isDense: true, labelText: tr('Qté')))),
                      const SizedBox(width: 8),
                      SizedBox(width: 110, child: TextField(controller: l['unit_price'], onChanged: (_) => set(() {}), keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly], decoration: InputDecoration(isDense: true, labelText: tr('Prix HT')))),
                      IconButton(onPressed: () => set(() => lines.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
                    ]),
                  ),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => set(() => lines.add({'description': TextEditingController(), 'quantity': TextEditingController(text: '1'), 'unit_price': TextEditingController(text: '0')})),
                    icon: const Icon(Icons.add),
                    label: Text(tr('Ligne')),
                  ),
                ),
                Row(children: [
                  SizedBox(width: 120, child: TextField(controller: disc, onChanged: (_) => set(() {}), keyboardType: TextInputType.number, decoration: InputDecoration(isDense: true, labelText: tr('Remise %')))),
                  const Spacer(),
                  Text(tr('Total HT : ${money(sub.roundToDouble(), cur)}'), style: const TextStyle(fontWeight: FontWeight.w800)),
                ]),
                const SizedBox(height: 10),
                TextField(controller: note, maxLines: 3, decoration: InputDecoration(labelText: tr('Note pour le client (délais, conditions…)'))),
                const SizedBox(height: 8),
                Text(tr('Le devis est émis dans les Ventes de la société prestataire (fiche client créée si besoin) ; le client l\'accepte depuis son ERP.'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12.5)),
              ]),
            ),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Émettre le devis')))],
        );
      }),
    );
    if (ok != true) return;
    await _act('admin/sites/requests/${r['id']}/quote', {
      'lines': [for (final l in lines) if (l['description']!.text.trim().isNotEmpty) {'description': l['description']!.text.trim(), 'quantity': toDouble(l['quantity']!.text), 'unit_price': toDouble(l['unit_price']!.text)}],
      'note': note.text.trim(),
      'discount_percent': toDouble(disc.text),
    }, 'Devis émis et envoyé au client.');
  }

  Widget _sites(List<Map<String, dynamic>> sites) {
    final hint = Theme.of(context).hintColor;
    if (sites.isEmpty) return EmptyState(icon: Icons.language, title: tr('Aucun site pour le moment'));
    return ResponsiveList<Map<String, dynamic>>(
      items: sites,
      columns: const ['Site', 'Société', 'Modèle', 'Domaine', 'Visites 30 j', 'Messages', 'État'],
      onTap: (s) => _siteMenu(s),
      cells: (s) => [
        Text(tr('${s['url']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
        Text(tr('${s['company'] ?? '—'}'), overflow: TextOverflow.ellipsis),
        Text(tr('${s['template_name']}'), overflow: TextOverflow.ellipsis),
        Text(tr(s['custom_domain'] == null ? '—' : '${s['custom_domain']} (${siteDomainLabels[s['domain_status']] ?? s['domain_status']})'), overflow: TextOverflow.ellipsis),
        Text(tr('${s['views_30d']}')),
        Text(tr('${s['messages']}')),
        StatusChip(switch ('${s['status']}') { 'published' => 'En ligne', 'suspended' => 'Suspendu', _ => 'Brouillon' }, color: switch ('${s['status']}') { 'published' => YColors.success, 'suspended' => YColors.danger, _ => YColors.warning }),
      ],
      mobileTile: (s) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr('${s['company']} · ${s['status']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
        Text(tr('${s['url']} · ${s['views_30d']} visites'), style: TextStyle(color: hint)),
      ]),
    );
  }

  Future<void> _siteMenu(Map<String, dynamic> s) => showModalBottomSheet(
        context: context,
        showDragHandle: true,
        builder: (c) => SafeArea(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            ListTile(leading: const Icon(Icons.open_in_new), title: Text(tr('Ouvrir le site')), onTap: () => openUrl('${s['status'] == 'published' ? s['url'] : s['preview_url']}')),
            if (s['custom_domain'] != null)
              ListTile(leading: const Icon(Icons.dns_outlined), title: Text(tr('Vérifier le domaine ${s['custom_domain']}')), onTap: () {
                Navigator.pop(c);
                _act('admin/sites/${s['id']}/check-domain', null, 'Domaine vérifié.');
              }),
            if (s['status'] != 'suspended')
              ListTile(leading: const Icon(Icons.block, color: YColors.danger), title: Text(tr('Suspendre le site')), onTap: () {
                Navigator.pop(c);
                _act('admin/sites/${s['id']}/status', {'status': 'suspended'}, 'Site suspendu.');
              })
            else
              ListTile(leading: const Icon(Icons.play_arrow_outlined), title: Text(tr('Rétablir (en ligne)')), onTap: () {
                Navigator.pop(c);
                _act('admin/sites/${s['id']}/status', {'status': 'published'}, 'Site rétabli.');
              }),
          ]),
        ),
      );
}

Future<String?> _ask(BuildContext context, String title, String label) async {
  final c = TextEditingController();
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Text(tr(title)),
      content: TextField(controller: c, maxLines: 3, decoration: InputDecoration(labelText: trn(label))),
      actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Valider')))],
    ),
  );
  return ok == true ? c.text.trim() : null;
}

class _Settings extends StatefulWidget {
  const _Settings({required this.settings, required this.onSaved});

  final Map<String, dynamic> settings;
  final Future<void> Function() onSaved;

  @override
  State<_Settings> createState() => _SettingsState();
}

class _SettingsState extends State<_Settings> {
  late final _c = {for (final k in ['base_domain', 'server_ip', 'cname_target', 'provider_company_id', 'tax_rate']) k: TextEditingController(text: '${widget.settings[k] ?? ''}')};
  late bool _auto = widget.settings['auto_template'] == true;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return ListView(padding: const EdgeInsets.all(16), children: [
      SectionCard(
        title: tr('Hébergement des sites'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Field(label: tr('Domaine des sites (sous-domaines offerts)'), child: TextField(controller: _c['base_domain'], decoration: InputDecoration(hintText: tr('sites.yomara.ci')))),
          Text(tr('Créez un enregistrement DNS joker « *.sites.yomara.ci » vers ce serveur et un certificat joker. Sans domaine, les sites sont servis sur /site/{adresse}.'), style: TextStyle(color: hint, fontSize: 12.5)),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: Field(label: tr('Adresse IP du serveur (enregistrement A des domaines clients)'), child: TextField(controller: _c['server_ip']))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('Cible CNAME (si pas de domaine des sites)'), child: TextField(controller: _c['cname_target']))),
          ]),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Créer le site dès la demande d\'un modèle')), subtitle: Text(tr('Sinon, chaque demande attend votre validation.')), value: _auto, onChanged: (v) => setState(() => _auto = v)),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Devis des sites sur mesure'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Field(label: tr('Société prestataire (n°)'), child: TextField(controller: _c['provider_company_id'], keyboardType: TextInputType.number))),
            const SizedBox(width: 12),
            Expanded(child: Field(label: tr('TVA (%)'), child: TextField(controller: _c['tax_rate'], keyboardType: TextInputType.number))),
          ]),
          Text(tr('Les devis sont émis dans les Ventes de : ${widget.settings['provider_name'] ?? '—'}. Grille tarifaire : config/erp_sites.php.'), style: TextStyle(color: hint, fontSize: 12.5)),
        ]),
      ),
      const SizedBox(height: 12),
      Align(
        alignment: Alignment.centerRight,
        child: FilledButton(
          onPressed: () async {
            try {
              await context.read<Session>().api.put('admin/sites/settings', {
                'base_domain': _c['base_domain']!.text.trim(), 'server_ip': _c['server_ip']!.text.trim().isEmpty ? null : _c['server_ip']!.text.trim(),
                'cname_target': _c['cname_target']!.text.trim(), 'auto_template': _auto, 'tax_rate': toDouble(_c['tax_rate']!.text),
                if (_c['provider_company_id']!.text.trim().isNotEmpty) 'provider_company_id': toDouble(_c['provider_company_id']!.text).toInt(),
              });
              await widget.onSaved();
              if (context.mounted) toast(context, tr('Réglages enregistrés.'));
            } catch (e) {
              if (context.mounted) toast(context, errorText(e), error: true);
            }
          },
          child: Text(tr('Enregistrer')),
        ),
      ),
    ]);
  }
}
