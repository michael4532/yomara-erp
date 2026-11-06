import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import 'insights_view.dart' show compactMoney;
import 'sites_screen.dart' show openUrl;

const acPlatformColors = {
  'meta': Color(0xFF1877F2),
  'google': Color(0xFF34A853),
  'tiktok': Color(0xFF111111),
  'linkedin': Color(0xFF0A66C2),
  'landing': Color(0xFF9333EA),
  'website': Color(0xFFD97706),
  'zapier': Color(0xFFFF4F00),
};

/// Publicité & acquisition : prospects des publicités, campagnes, dépenses, retour sur investissement, pages de capture.
class AcquisitionScreen extends StatefulWidget {
  const AcquisitionScreen({super.key});

  @override
  State<AcquisitionScreen> createState() => _AcquisitionScreenState();
}

class _AcquisitionScreenState extends State<AcquisitionScreen> {
  final _report = GlobalKey<_ReportTabState>();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('acquisition.manage');
    void changed() => _report.currentState?.load();
    final tabs = <(String, Widget)>[
      ('Résultats', _ReportTab(key: _report)),
      ('Prospects reçus', RecordsView(module: 'acquisition', resource: 'leads', emptyIcon: Icons.person_add_alt, onChanged: changed,
          columns: const ['title', 'platform', 'received_at', 'phone', 'campaign_id', 'status', 'lead_status'],
          emptyMessage: tr('Les prospects des publicités, des pages de capture et des formulaires reliés arrivent ici et dans le CRM.'))),
      ('Campagnes', RecordsView(module: 'acquisition', resource: 'campaigns', emptyIcon: Icons.campaign_outlined, newLabel: tr('Nouvelle campagne'), onChanged: changed,
          columns: const ['title', 'platform', 'status', 'spent', 'leads', 'cpl', 'won', 'revenue', 'roas'])),
      ('Dépenses', RecordsView(module: 'acquisition', resource: 'spend', emptyIcon: Icons.payments_outlined, newLabel: tr('Nouvelle dépense'), onChanged: changed)),
      ('Sources', RecordsView(module: 'acquisition', resource: 'sources', emptyIcon: Icons.input, newLabel: tr('Nouvelle source'), onChanged: changed, actions: sourceActions)),
      ('Pages de capture', RecordsView(module: 'acquisition', resource: 'pages', emptyIcon: Icons.web_outlined, newLabel: tr('Nouvelle page'), onChanged: changed, actions: pageActions,
          emptyMessage: tr('Une page simple pour une offre : titre, avantages, compte à rebours et formulaire. Chaque envoi devient un prospect.'))),
      if (manage) ('Comptes & suivi', const _SetupTab()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Publicité & acquisition'), subtitle: tr('Prospects des publicités, dépenses et retour sur investissement'), icon: Icons.ads_click),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

Future<void> _run(BuildContext c, Future<void> Function() fn) async {
  try {
    await fn();
  } catch (e) {
    if (c.mounted) toast(c, errorText(e), error: true);
  }
}

void _copy(BuildContext c, String text, String done) {
  Clipboard.setData(ClipboardData(text: text));
  toast(c, done);
}

List<Widget> sourceActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
  final s = dialog.read<Session>();
  final url = '${row['webhook_url'] ?? ''}';
  return [
    if (url.startsWith('http')) OutlinedButton.icon(icon: const Icon(Icons.copy, size: 18), label: Text(tr('Copier l\'adresse de réception')), onPressed: () => _copy(dialog, url, tr('Adresse copiée'))),
    if (s.can('acquisition.manage'))
      OutlinedButton.icon(
        icon: const Icon(Icons.science_outlined, size: 18),
        label: Text(tr('Envoyer un prospect d\'essai')),
        onPressed: () => _run(dialog, () async {
          final r = asMap(asMap(await s.api.post('acquisition/sources/${row['id']}/test'))['data']);
          reload();
          if (dialog.mounted) {
            toast(dialog, r['status'] == 'duplicate' ? tr('Essai reçu : rattaché à une affaire en cours (même téléphone).') : tr('Essai reçu : opportunité créée dans le CRM. Supprimez-la après vérification.'));
          }
        }),
      ),
  ];
}

List<Widget> pageActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) => [
      OutlinedButton.icon(icon: const Icon(Icons.open_in_new, size: 18), label: Text(tr(row['status'] == 'published' ? 'Ouvrir la page' : 'Aperçu')), onPressed: () => openUrl('${row['url']}')),
      OutlinedButton.icon(icon: const Icon(Icons.copy, size: 18), label: Text(tr('Copier le lien')), onPressed: () => _copy(dialog, '${row['url']}', tr('Lien copié : collez-le dans votre publicité ou votre bio.'))),
      OutlinedButton.icon(
          icon: const Icon(Icons.link, size: 18),
          label: Text(tr('Lien avec suivi de campagne')),
          onPressed: () => _copy(dialog, '${row['url']}?utm_source=facebook&utm_medium=paid&utm_campaign=${row['slug']}', tr('Lien copié avec utm_campaign=${row['slug']} : remplacez « facebook » par la plateforme utilisée.'))),
    ];

// ------------------------------------------------------------------ résultats

class _ReportTab extends StatefulWidget {
  const _ReportTab({super.key});

  @override
  State<_ReportTab> createState() => _ReportTabState();
}

class _ReportTabState extends State<_ReportTab> {
  int _days = 30;
  Map<String, dynamic>? _d;
  String? _error;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    final api = context.read<Session>().api;
    try {
      final now = DateTime.now();
      final r = await api.get('acquisition/report', query: {'from': isoDate(now.subtract(Duration(days: _days - 1))), 'to': isoDate(now)});
      if (mounted) setState(() => (_d = asMap(asMap(r)['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) return EmptyState(icon: Icons.error_outline, title: tr('Résultats indisponibles'), message: _error!, action: FilledButton(onPressed: load, child: Text(tr('Réessayer'))));
    final d = _d;
    if (d == null) return const Center(child: CircularProgressIndicator());
    final cur = context.read<Session>().currency;
    final roas = d['roas'];
    final setup = asMap(d['setup']);
    final platforms = [for (final p in (d['by_platform'] as List? ?? const [])) asMap(p)];
    final campaigns = [for (final c in (d['campaigns'] as List? ?? const [])) asMap(c)];
    final recent = [for (final c in (d['recent'] as List? ?? const [])) asMap(c)];
    final daily = [for (final c in (d['daily'] as List? ?? const [])) asMap(c)];
    final empty = toDouble(d['spent']) == 0 && (d['leads'] as num? ?? 0) == 0;
    return RefreshIndicator(
      onRefresh: load,
      child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 10),
          child: Wrap(spacing: 8, runSpacing: 8, children: [
            for (final n in const [7, 30, 90])
              ChoiceChip(label: Text(tr('$n jours')), selected: _days == n, onSelected: (_) {
                setState(() => _days = n);
                load();
              }),
          ]),
        ),
        StatStrip(tiles: [
          StatTile(label: tr('Dépensé'), value: compactMoney(toDouble(d['spent']), cur), detail: tr('publicités de la période'), icon: Icons.payments_outlined),
          StatTile(label: tr('Prospects'), value: '${d['leads']}', detail: d['cpl'] == null ? tr('${d['duplicates']} déjà connu(s)') : tr('${compactMoney(toDouble(d['cpl']), cur)} par prospect'),
              icon: Icons.person_add_alt, color: const Color(0xFF9333EA)),
          StatTile(label: tr('Ventes'), value: '${d['won']}', detail: tr('${compactMoney(toDouble(d['revenue']), cur)} de chiffre d\'affaires'), icon: Icons.emoji_events_outlined, color: YColors.success),
          StatTile(label: tr('Retour'), value: roas == null ? '—' : '${toDouble(roas).toStringAsFixed(1).replaceAll('.', ',')} ×', detail: tr('chiffre d\'affaires / dépense'), icon: Icons.trending_up,
              color: roas != null && toDouble(roas) < 1 ? YColors.danger : const Color(0xFFD97706)),
        ]),
        if (empty) Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: _Checklist(setup: setup)),
        if (daily.isNotEmpty && !empty)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SectionCard(title: 'Jour par jour', child: SizedBox(height: 170, child: _DailyChart(days: daily, currency: cur))),
          ),
        if (platforms.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: SectionCard(
              title: 'Par plateforme',
              child: Column(children: [
                for (final p in platforms)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: CircleAvatar(radius: 6, backgroundColor: acPlatformColors[p['platform']] ?? YColors.muted),
                    minLeadingWidth: 12,
                    title: Text(tr('${p['label']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text(tr('${p['leads']} prospect(s) · ${p['cpl'] == null ? '—' : compactMoney(toDouble(p['cpl']), cur)} par prospect · ${p['won']} vente(s)')),
                    trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(compactMoney(toDouble(p['spent']), cur), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                      Text(p['roas_value'] == null ? '' : '${toDouble(p['roas_value']).toStringAsFixed(1).replaceAll('.', ',')} ×', style: const TextStyle(color: YColors.muted, fontSize: 12)),
                    ]),
                  ),
              ]),
            ),
          ),
        if (campaigns.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: SectionCard(
              title: 'Campagnes',
              child: Column(children: [
                for (final c in campaigns.take(15))
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(Icons.campaign_outlined, color: acPlatformColors[c['platform']] ?? YColors.muted),
                    title: Text(verbatim('${c['title']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                    subtitle: Text(tr('${c['leads']} prospect(s) · ${c['cpl'] == null ? '—' : compactMoney(toDouble(c['cpl']), cur)} / prospect · ${c['won']} vente(s)')),
                    trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text(compactMoney(toDouble(c['spent']), cur), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                      Text('${c['roas']}', style: TextStyle(color: (c['roas_value'] != null && toDouble(c['roas_value']) < 1) ? YColors.danger : YColors.success, fontSize: 12, fontWeight: FontWeight.w600)),
                    ]),
                  ),
              ]),
            ),
          ),
        if (recent.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: SectionCard(
              title: 'Derniers prospects',
              child: Column(children: [
                for (final l in recent)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: CircleAvatar(backgroundColor: (acPlatformColors[l['platform']] ?? YColors.muted).withValues(alpha: .12), child: Icon(Icons.person_outline, color: acPlatformColors[l['platform']] ?? YColors.muted)),
                    title: Text(verbatim('${l['title']}')),
                    subtitle: Text(verbatim([l['phone'], l['campaign'], formatDateTime(l['received_at'])].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
                    trailing: l['status'] == 'duplicate' ? Chip(label: Text(tr('Déjà connu')), visualDensity: VisualDensity.compact) : null,
                  ),
              ]),
            ),
          ),
      ]),
    );
  }
}

String formatDateTime(dynamic v) {
  final d = DateTime.tryParse('${v ?? ''}');
  if (d == null) return '';
  String two(int n) => n.toString().padLeft(2, '0');
  return '${two(d.day)}/${two(d.month)} ${two(d.hour)}:${two(d.minute)}';
}

class _Checklist extends StatelessWidget {
  const _Checklist({required this.setup});

  final Map<String, dynamic> setup;

  @override
  Widget build(BuildContext context) {
    Widget step(bool done, String title, String text) => ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(done ? Icons.check_circle : Icons.radio_button_unchecked, color: done ? YColors.success : YColors.muted),
          title: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Text(tr(text)),
        );
    return SectionCard(
      title: 'Pour commencer',
      child: Column(children: [
        step(setup['meta_leads_ready'] == true, 'Recevoir les prospects Facebook et Instagram',
            'Réseaux sociaux › Comptes : reliez votre page, puis Boîte de réception › Activer. Les formulaires de vos publicités arrivent alors tout seuls dans le CRM.'),
        step((setup['accounts'] as num? ?? 0) > 0, 'Relier vos comptes publicitaires', 'Onglet « Comptes & suivi » : Meta, Google Ads, TikTok ou LinkedIn. Les dépenses arrivent chaque jour.'),
        step((setup['tracking'] as num? ?? 0) > 0, 'Ajouter vos balises de suivi', 'Pixel Meta, Google Analytics, TikTok… sur votre site Yomara et vos pages de capture.'),
        step((setup['pages'] as num? ?? 0) > 0, 'Créer une page de capture', 'Une page par offre, à mettre en lien dans vos publicités et votre bio.'),
      ]),
    );
  }
}

class _DailyChart extends StatelessWidget {
  const _DailyChart({required this.days, required this.currency});

  final List<Map<String, dynamic>> days;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final maxS = days.fold<double>(0, (m, d) => toDouble(d['spent']) > m ? toDouble(d['spent']) : m);
    final maxL = days.fold<double>(0, (m, d) => toDouble(d['leads']) > m ? toDouble(d['leads']) : m);
    return Column(children: [
      Expanded(
        child: LayoutBuilder(builder: (c, box) {
          final w = box.maxWidth / days.length;
          return Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
            for (final d in days)
              Tooltip(
                message: '${d['date']} : ${compactMoney(toDouble(d['spent']), currency)} · ${d['leads']} prospect(s)',
                child: SizedBox(
                  width: w,
                  child: Row(crossAxisAlignment: CrossAxisAlignment.end, mainAxisAlignment: MainAxisAlignment.center, children: [
                    Container(width: (w * .38).clamp(1, 14), height: maxS == 0 ? 0 : (box.maxHeight * toDouble(d['spent']) / maxS).clamp(0, box.maxHeight), color: YColors.blue.withValues(alpha: .75)),
                    const SizedBox(width: 1),
                    Container(width: (w * .38).clamp(1, 14), height: maxL == 0 ? 0 : (box.maxHeight * toDouble(d['leads']) / maxL).clamp(0, box.maxHeight), color: const Color(0xFF9333EA)),
                  ]),
                ),
              ),
          ]);
        }),
      ),
      const SizedBox(height: 8),
      Row(children: [
        Container(width: 10, height: 10, color: YColors.blue.withValues(alpha: .75)),
        const SizedBox(width: 6),
        Text(tr('Dépense'), style: const TextStyle(fontSize: 12, color: YColors.muted)),
        const SizedBox(width: 16),
        Container(width: 10, height: 10, color: const Color(0xFF9333EA)),
        const SizedBox(width: 6),
        Text(tr('Prospects'), style: const TextStyle(fontSize: 12, color: YColors.muted)),
      ]),
    ]);
  }
}

// ------------------------------------------------------------------ comptes, Google, balises

class _SetupTab extends StatefulWidget {
  const _SetupTab();

  @override
  State<_SetupTab> createState() => _SetupTabState();
}

class _SetupTabState extends State<_SetupTab> {
  Map<String, dynamic>? _acc;
  List<Map<String, dynamic>> _tracking = [];
  final _ctl = <String, TextEditingController>{};
  Map<String, dynamic>? _gsc;
  bool _gscLoading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _ctl.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    await _run(context, () async {
      final a = asMap(await api.get('acquisition/accounts'));
      final t = asMap(await api.get('acquisition/tracking'));
      if (!mounted) return;
      setState(() {
        _acc = a;
        _tracking = [for (final x in (t['data'] as List? ?? const [])) asMap(x)];
        for (final x in _tracking) {
          (_ctl['${x['key']}'] ??= TextEditingController()).text = '${x['value'] ?? ''}';
        }
      });
    });
  }

  Future<void> _saveTracking() => _run(context, () async {
        await context.read<Session>().api.put('acquisition/tracking', {'values': {for (final e in _ctl.entries) e.key: e.value.text.trim()}});
        if (mounted) toast(context, tr('Balises enregistrées : elles sont ajoutées à votre site et à vos pages de capture.'));
        _load();
      });

  Future<void> _addAccount() async {
    final ok = await showFormDialog<bool>(context, title: 'Relier un compte publicitaire', builder: (_) => const _AccountDialog());
    if (ok == true) _load();
  }

  Future<void> _editAccount(Map<String, dynamic> a) async {
    final ok = await showFormDialog<bool>(context, title: 'Compte publicitaire', builder: (_) => _AccountDialog(account: a));
    if (ok == true) _load();
  }

  Future<void> _google() => _run(context, () async {
        final r = asMap(await context.read<Session>().api.post('acquisition/google/connect'));
        await openUrl('${r['url']}');
        if (mounted) toast(context, tr('Autorisez l\'accès dans la page Google, puis revenez ici et actualisez.'));
      });

  Future<void> _loadGsc() async {
    setState(() => _gscLoading = true);
    await _run(context, () async {
      final r = asMap(await context.read<Session>().api.get('acquisition/search-console'));
      if (mounted) setState(() => _gsc = asMap(r['data']));
    });
    if (mounted) setState(() => _gscLoading = false);
  }

  @override
  Widget build(BuildContext context) {
    final a = _acc;
    if (a == null) return const Center(child: CircularProgressIndicator());
    final s = context.read<Session>();
    final accounts = [for (final x in (a['data'] as List? ?? const [])) asMap(x)];
    final g = asMap(a['google']);
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 40), children: [
        SectionCard(
          title: 'Comptes publicitaires',
          trailing: FilledButton.tonalIcon(onPressed: _addAccount, icon: const Icon(Icons.add, size: 18), label: Text(tr('Relier un compte'))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (accounts.isEmpty) Text(tr('Aucun compte relié. Les dépenses peuvent aussi être saisies dans l\'onglet « Dépenses ».'), style: const TextStyle(color: YColors.muted)),
            for (final x in accounts)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: CircleAvatar(backgroundColor: (acPlatformColors[x['platform']] ?? YColors.muted).withValues(alpha: .12), child: Icon(Icons.ads_click, color: acPlatformColors[x['platform']] ?? YColors.muted)),
                title: Text(verbatim('${x['name']}')),
                subtitle: Text(x['status'] == 'error'
                    ? tr('Erreur : ${x['last_error']}')
                    : tr('${x['platform_label']} · ${x['campaigns']} campagne(s) · relevé ${formatDateTime(x['synced_at']).isEmpty ? 'en attente' : formatDateTime(x['synced_at'])}${x['send_conversions'] == true ? ' · ventes renvoyées à Meta' : ''}'),
                    style: TextStyle(color: x['status'] == 'error' ? YColors.danger : null)),
                trailing: PopupMenuButton<String>(
                  onSelected: (v) => _run(context, () async {
                    if (v == 'sync') {
                      toast(context, tr('Relevé en cours…'));
                      final r = asMap(asMap(await s.api.postLong('acquisition/accounts/${x['id']}/sync'))['data']);
                      if (context.mounted) toast(context, tr('${r['rows']} ligne(s) de dépenses relevée(s) sur 90 jours.'));
                    } else if (v == 'edit') {
                      await _editAccount(x);
                    } else if (v == 'delete') {
                      if (!await confirm(context, tr('Retirer le compte'), tr('Les campagnes et dépenses déjà relevées sont gardées.'), ok: tr('Retirer'), danger: true)) return;
                      await s.api.delete('acquisition/accounts/${x['id']}');
                    }
                    _load();
                  }),
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'sync', child: Text(tr('Relever maintenant'))),
                    if (x['platform'] != 'google') PopupMenuItem(value: 'edit', child: Text(tr('Jeton et conversions'))),
                    PopupMenuItem(value: 'delete', child: Text(tr('Retirer'))),
                  ],
                ),
              ),
            const Divider(height: 24),
            Row(children: [
              const Icon(Icons.travel_explore, color: Color(0xFF34A853)),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                    g['connected'] == true
                        ? tr('Google est relié (Google Ads et Search Console).')
                        : (g['search_console'] == true ? tr('Google Ads et Search Console : reliez votre compte Google.') : tr('Google Ads et Search Console seront disponibles quand l\'administrateur de la plateforme aura renseigné l\'application Google.')),
                    style: const TextStyle(fontWeight: FontWeight.w600)),
              ),
              if (g['search_console'] == true) OutlinedButton(onPressed: _google, child: Text(tr(g['connected'] == true ? 'Relier à nouveau' : 'Relier Google'))),
            ]),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: 'Search Console : ce que les gens cherchent sur Google',
          trailing: g['connected'] == true ? IconButton(onPressed: _gscLoading ? null : _loadGsc, icon: const Icon(Icons.refresh), tooltip: tr('Charger')) : null,
          child: g['connected'] != true
              ? Text(tr('Reliez Google ci-dessus pour voir les recherches qui amènent des visiteurs sur votre site.'), style: const TextStyle(color: YColors.muted))
              : _gsc == null
                  ? Align(alignment: Alignment.centerLeft, child: OutlinedButton.icon(onPressed: _gscLoading ? null : _loadGsc, icon: const Icon(Icons.query_stats), label: Text(tr('Afficher les 30 derniers jours'))))
                  : _GscView(d: _gsc!),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: 'Balises de suivi',
          trailing: FilledButton(onPressed: _saveTracking, child: Text(tr('Enregistrer'))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Ajoutées à votre site Yomara (sauf réglage propre au site) et à vos pages de capture. Les ventes et prospects y sont signalés comme conversions.'), style: const TextStyle(color: YColors.muted)),
            const SizedBox(height: 8),
            LayoutBuilder(builder: (c, box) {
              final w = box.maxWidth < 600 ? box.maxWidth : (box.maxWidth - 12) / 2;
              return Wrap(spacing: 12, runSpacing: 4, children: [
                for (final t in _tracking)
                  SizedBox(width: w, child: Padding(padding: const EdgeInsets.only(top: 8), child: TextField(controller: _ctl['${t['key']}'], decoration: InputDecoration(labelText: tr('${t['label']}'))))),
              ]);
            }),
          ]),
        ),
      ]),
    );
  }
}

class _GscView extends StatelessWidget {
  const _GscView({required this.d});

  final Map<String, dynamic> d;

  @override
  Widget build(BuildContext context) {
    final q = [for (final x in (d['queries'] as List? ?? const [])) asMap(x)];
    if (d['site'] == null) return Text(tr('Aucun site validé dans Search Console avec ce compte Google.'), style: const TextStyle(color: YColors.muted));
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text(verbatim('${d['site']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
      Text(tr('${d['clicks']} clic(s) · ${d['impressions']} affichage(s) dans Google sur 30 jours'), style: const TextStyle(color: YColors.muted)),
      const SizedBox(height: 8),
      for (final x in q.take(15))
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 5),
          child: Row(children: [
            Expanded(child: Text(verbatim('${x['key']}'), maxLines: 1, overflow: TextOverflow.ellipsis)),
            SizedBox(width: 70, child: Text(tr('${x['clicks']} clics'), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
            SizedBox(width: 80, child: Text(tr('position ${'${x['position']}'.replaceAll('.', ',')}'), textAlign: TextAlign.right, style: const TextStyle(color: YColors.muted, fontSize: 12))),
          ]),
        ),
    ]);
  }
}

class _AccountDialog extends StatefulWidget {
  const _AccountDialog({this.account});

  final Map<String, dynamic>? account;

  @override
  State<_AccountDialog> createState() => _AccountDialogState();
}

class _AccountDialogState extends State<_AccountDialog> {
  String _platform = 'meta';
  final _id = TextEditingController();
  final _token = TextEditingController();
  final _pixel = TextEditingController();
  final _capi = TextEditingController();
  bool _send = false;
  bool _busy = false;

  static const help = {
    'meta': 'Gestionnaire de publicités : identifiant du compte (act_…). Jeton : Business Manager › Utilisateurs système › Générer un jeton (autorisation ads_read).',
    'tiktok': 'TikTok Ads Manager : identifiant d\'annonceur. Jeton : TikTok for Business › Marketing API › jeton d\'accès longue durée.',
    'linkedin': 'Campaign Manager : numéro du compte publicitaire. Jeton : application LinkedIn avec l\'accès « Advertising API » (r_ads_reporting).',
  };

  @override
  void initState() {
    super.initState();
    final a = widget.account;
    if (a != null) {
      _platform = '${a['platform']}';
      _id.text = '${a['external_id'] ?? ''}';
      _pixel.text = '${a['pixel_id'] ?? ''}';
      _send = a['send_conversions'] == true;
    }
  }

  @override
  void dispose() {
    for (final c in [_id, _token, _pixel, _capi]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final api = context.read<Session>().api;
    setState(() => _busy = true);
    try {
      final body = {
        'platform': _platform,
        'external_id': _id.text.trim(),
        if (_token.text.trim().isNotEmpty) 'token': _token.text.trim(),
        'pixel_id': _pixel.text.trim().isEmpty ? null : _pixel.text.trim(),
        if (_capi.text.trim().isNotEmpty) 'capi_token': _capi.text.trim(),
        'send_conversions': _send,
      };
      if (widget.account == null) {
        await api.post('acquisition/accounts', body);
      } else {
        await api.put('acquisition/accounts/${widget.account!['id']}', body);
      }
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final edit = widget.account != null;
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (!edit)
            SegmentedButton<String>(
              segments: [for (final p in const ['meta', 'tiktok', 'linkedin']) ButtonSegment(value: p, label: Text(tr({'meta': 'Meta', 'tiktok': 'TikTok', 'linkedin': 'LinkedIn'}[p]!)))],
              selected: {_platform},
              onSelectionChanged: (v) => setState(() => _platform = v.first),
            ),
          const SizedBox(height: 10),
          Text(tr(help[_platform] ?? ''), style: const TextStyle(color: YColors.muted, fontSize: 13)),
          const SizedBox(height: 6),
          if (!edit) TextField(controller: _id, decoration: InputDecoration(labelText: tr('Identifiant du compte'))),
          const SizedBox(height: 8),
          TextField(controller: _token, obscureText: true, decoration: InputDecoration(labelText: tr(edit ? 'Nouveau jeton d\'accès (laisser vide pour garder)' : 'Jeton d\'accès'))),
          if (_platform == 'meta') ...[
            const SizedBox(height: 14),
            Text(tr('Renvoyer les ventes à Meta (API Conversions)'), style: const TextStyle(fontWeight: FontWeight.w700)),
            Text(tr('Quand une affaire issue d\'une publicité est gagnée, ou qu\'une commande est passée sur votre site, Meta en est informé : vos publicités apprennent à trouver des acheteurs.'),
                style: const TextStyle(color: YColors.muted, fontSize: 13)),
            const SizedBox(height: 6),
            TextField(controller: _pixel, decoration: InputDecoration(labelText: tr('Identifiant du pixel / jeu de données'))),
            const SizedBox(height: 8),
            TextField(controller: _capi, obscureText: true, decoration: InputDecoration(labelText: tr('Jeton de l\'API Conversions (facultatif, sinon le jeton du compte)'))),
            SwitchListTile(contentPadding: EdgeInsets.zero, value: _send, onChanged: (v) => setState(() => _send = v), title: Text(tr('Renvoyer les ventes et prospects'))),
          ],
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
            FilledButton(onPressed: _busy ? null : _save, child: Text(tr(_busy ? 'Vérification…' : (edit ? 'Enregistrer' : 'Relier')))),
          ]),
        ),
      ]),
    );
  }
}
