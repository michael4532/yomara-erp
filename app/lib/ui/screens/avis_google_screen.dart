import 'dart:async';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/local_db.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/report_charts.dart';
import 'mailbox/mail_theme.dart' show QrBox;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const _gBlue = Color(0xFF1A73E8);
const _gStar = Color(0xFFFBBC04);

/// Avis Google & fiche d'établissement.
class AvisGoogleScreen extends StatefulWidget {
  const AvisGoogleScreen({super.key});

  @override
  State<AvisGoogleScreen> createState() => _AvisGoogleScreenState();
}

class _AvisGoogleScreenState extends State<AvisGoogleScreen> {
  Map<String, dynamic>? _d;
  Object? _error;
  bool _syncing = false;

  ApiClient get api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await api.get('avis_google');
      if (mounted) setState(() => (_d = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _sync() async {
    setState(() => _syncing = true);
    try {
      final r = await api.postLong('avis_google/sync');
      if (mounted) toast(context, (r['new'] ?? 0) > 0 ? '${r['new']} nouvel(s) avis.' : 'Tout est à jour.');
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) return _error != null ? Center(child: Text(tr(errorText(_error!)))) : const Center(child: CircularProgressIndicator());
    if (d['mode'] == 'none' || (d['locations'] as List).isEmpty) return _onboarding(d);
    return DefaultTabController(
      length: 4,
      child: Column(children: [
        _header(d),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, labelColor: _gBlue, indicatorColor: _gBlue, tabs: [
          Tab(icon: Icon(Icons.star_outline, size: 18), text: tr('Avis')),
          Tab(icon: Icon(Icons.send_outlined, size: 18), text: tr('Demandes d\'avis')),
          Tab(icon: Icon(Icons.campaign_outlined, size: 18), text: tr('Publications')),
          Tab(icon: Icon(Icons.storefront_outlined, size: 18), text: tr('Fiche & statistiques')),
        ]),
        Expanded(
          child: TabBarView(children: [
            _ReviewsTab(info: d, onChanged: _load),
            _RequestsTab(info: d, onChanged: _load),
            _PostsTab(info: d),
            _ProfileTab(info: d, onChanged: _load),
          ]),
        ),
      ]),
    );
  }

  Widget _header(Map<String, dynamic> d) {
    final s = asMap(d['stats']);
    final rating = (s['rating'] as num?)?.toDouble();
    final loc = asMap((d['locations'] as List).first);
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 16, 14, 16),
      decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFFE8F0FE), Color(0xFFFFFFFF)])),
      child: Wrap(spacing: 24, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
        Row(mainAxisSize: MainAxisSize.min, children: [
          Text(tr(rating == null ? '–' : rating.toStringAsFixed(1).replaceAll('.', ',')), style: const TextStyle(fontSize: 44, fontWeight: FontWeight.w800, color: Color(0xFF202124))),
          const SizedBox(width: 10),
          Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Stars(rating ?? 0, size: 22),
            Text(tr('${s['count']} avis Google · ${loc['title']}'), style: TextStyle(color: Theme.of(context).hintColor)),
          ]),
        ]),
        _kpi('Sans réponse', '${s['unanswered']}', (s['unanswered'] ?? 0) > 0 ? YColors.danger : YColors.success),
        _kpi('Taux de réponse', s['response_rate'] == null ? '–' : '${s['response_rate']} %', _gBlue),
        _kpi('Délai de réponse', s['avg_response_hours'] == null ? '–' : (s['avg_response_hours'] < 48 ? '${s['avg_response_hours']} h' : '${((s['avg_response_hours'] as num) / 24).toStringAsFixed(0)} j'), _gBlue),
        _kpi('Avis sur 30 jours', '${s['last_30']}', const Color(0xFF34A853)),
        Row(mainAxisSize: MainAxisSize.min, children: [
          OutlinedButton.icon(onPressed: () => _qr(loc), icon: const Icon(Icons.qr_code_2), label: Text(tr('QR & affiche'))),
          const SizedBox(width: 8),
          IconButton(tooltip: tr('Synchroniser'), onPressed: _syncing ? null : _sync, icon: _syncing ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.sync)),
          if (d['can_manage'] == true) IconButton(tooltip: tr('Paramètres'), onPressed: () => _settings(d), icon: const Icon(Icons.settings_outlined)),
        ]),
        if (d['error'] != null) Text(tr('${d['error']}'), style: const TextStyle(color: YColors.danger, fontSize: 12)),
        if (d['mode'] == 'places')
          Chip(avatar: Icon(Icons.visibility_outlined, size: 16), label: Text(tr('Lecture seule : 5 derniers avis, réponses impossibles')), visualDensity: VisualDensity.compact),
      ]),
    );
  }

  Widget _kpi(String label, String v, Color c) => Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        Text(tr(v), style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: c)),
        Text(tr(label), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
      ]);

  void _qr(Map<String, dynamic> loc) => showDialog(
        context: context,
        builder: (c) => YAlertDialog(
          title: Text(tr('Notez-nous sur Google')),
          content: SizedBox(
            width: 380,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('À poser sur le comptoir, la caisse, les tables ou à imprimer sur vos factures : le client scanne et arrive directement sur la page d\'avis.'), textAlign: TextAlign.center),
              const SizedBox(height: 14),
              QrBox(data: '${loc['qr_url']}', size: 180),
              const SizedBox(height: 8),
              SelectableText(tr('${loc['review_uri']}'), style: const TextStyle(fontSize: 12), textAlign: TextAlign.center),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Clipboard.setData(ClipboardData(text: '${loc['review_uri']}')), child: Text(tr('Copier le lien'))),
            FilledButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${loc['poster_url']}'), webOnlyWindowName: '_blank'), icon: const Icon(Icons.print_outlined), label: Text(tr('Imprimer l\'affiche'))),
          ],
        ),
      );

  Widget _onboarding(Map<String, dynamic> d) => Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 640),
            child: Column(children: [
              const Stars(5, size: 34),
              const SizedBox(height: 12),
              Text(tr('Vos avis Google, au cœur de l\'entreprise'), style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800), textAlign: TextAlign.center),
              const SizedBox(height: 8),
              Text(tr('Répondez à vos avis depuis le logiciel (avec l\'aide de l\'IA), soyez alerté d\'un avis négatif, envoyez automatiquement une demande d\'avis après chaque vente, et suivez vos vues, appels et itinéraires.'),
                  textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).hintColor, height: 1.5)),
              const SizedBox(height: 22),
              if (d['can_manage'] != true)
                Text(tr('Demandez à un responsable de connecter la fiche Google de l\'entreprise.'))
              else ...[
                Card(
                  child: ListTile(
                    leading: const CircleAvatar(backgroundColor: Color(0xFFE8F0FE), child: Icon(Icons.storefront, color: _gBlue)),
                    title: Text(tr('Connecter ma fiche Google Business (recommandé)'), style: TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(tr(d['google_app'] == true ? 'Tous les avis, réponses, publications, statistiques.' : 'Bouton grisé : l\'application Google (Client ID et secret) n\'est pas encore renseignée. Éditeur de la plateforme : Configuration du serveur › Boîtes mail Microsoft et Google. Ou votre société : Boîte mail › Paramètres. Adresse de retour à déclarer chez Google : ${d['redirect_uri']}')),
                    trailing: FilledButton(style: FilledButton.styleFrom(backgroundColor: _gBlue), onPressed: d['google_app'] == true ? () => _connect() : null, child: Text(tr('Connecter'))),
                  ),
                ),
                Card(
                  child: ListTile(
                    leading: const CircleAvatar(backgroundColor: Color(0xFFFEF7E0), child: Icon(Icons.search, color: Color(0xFFB06000))),
                    title: Text(tr('Mode rapide : retrouver mon établissement'), style: TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(tr('Note, nombre d\'avis, 5 derniers avis, QR code et demandes d\'avis — avec une clé API Google Maps, sans attendre l\'accès à l\'API Business.')),
                    trailing: OutlinedButton(onPressed: () => _findPlace(d), child: Text(tr('Rechercher'))),
                  ),
                ),
              ],
            ]),
          ),
        ),
      );

  Future<void> _connect() async {
    try {
      final r = await api.post('avis_google/connect');
      await safeLaunchUrl(Uri.parse('${r['url']}'), mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank');
      if (!mounted) return;
      // On attend la réponse de Google tout seul : la fenêtre se ferme dès que la fiche est connectée
      var done = false;
      String? error;
      final timer = Timer.periodic(const Duration(seconds: 3), (t) async {
        if (t.tick > 100) return t.cancel();
        try {
          final d = asMap((await api.get('avis_google'))['data']);
          if (d['mode'] == 'api') {
            done = true;
            error = d['error'] as String?;
            t.cancel();
            if (mounted && Navigator.of(context).canPop()) Navigator.of(context).pop();
          }
        } catch (_) {}
      });
      await showDialog(
        context: context,
        builder: (c) => YAlertDialog(
          title: Text(tr('Connexion à Google')),
          content: Row(children: [
            const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)),
            const SizedBox(width: 14),
            Expanded(child: Text(tr('Choisissez le compte Google de votre fiche dans la page qui s\'est ouverte et acceptez : cette fenêtre se ferme toute seule.'))),
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
        ),
      );
      timer.cancel();
      await _load();
      if (!mounted) return;
      if (done) {
        toast(context, tr(error == null ? 'Fiche Google connectée.' : 'Compte Google connecté, mais : $error'), error: error != null);
      } else {
        toast(context, tr('La connexion n\'est pas encore arrivée. Si la page Google affichait « Fiche Google connectée », actualisez ; sinon recommencez.'), error: true);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _findPlace(Map<String, dynamic> d) async {
    if (d['places_key'] != true) {
      final key = TextEditingController();
      final ok = await showDialog<bool>(
        context: context,
        builder: (c) => YAlertDialog(
          title: Text(tr('Clé API Google Maps')),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('console.cloud.google.com › API et services › activez « Places API (New) » › Identifiants › Créer une clé API (limitez-la à Places API).')),
              TextField(controller: key, decoration: InputDecoration(labelText: tr('Clé API (AIza…)'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer')))],
        ),
      );
      if (ok != true || key.text.trim().isEmpty) return;
      try {
        await api.put('avis_google/settings', {'places_api_key': key.text.trim()});
      } catch (e) {
        if (mounted) toast(context, errorText(e), error: true);
        return;
      }
    }
    if (!mounted) return;
    final picked = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => const _PlaceSearch());
    if (picked == null) return;
    try {
      await api.post('avis_google/places', {'place_id': picked['place_id'], 'title': picked['name']});
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _settings(Map<String, dynamic> d) async {
    await showDialog(context: context, builder: (_) => _AvisSettings(info: d, onConnect: _connect, onFindPlace: () => _findPlace(d)));
    await _load();
  }
}

class Stars extends StatelessWidget {
  const Stars(this.value, {super.key, this.size = 16});

  final double value;
  final double size;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        for (var i = 1; i <= 5; i++)
          Icon(value >= i ? Icons.star_rounded : (value >= i - .5 ? Icons.star_half_rounded : Icons.star_outline_rounded), size: size, color: _gStar),
      ]);
}

// ---------------------------------------------------------------------------- avis

class _ReviewsTab extends StatefulWidget {
  const _ReviewsTab({required this.info, required this.onChanged});

  final Map<String, dynamic> info;
  final VoidCallback onChanged;

  @override
  State<_ReviewsTab> createState() => _ReviewsTabState();
}

class _ReviewsTabState extends State<_ReviewsTab> {
  List<Map<String, dynamic>>? _list;
  String _filter = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _rk = 0;
  Timer? _debounce;
  final Map<int, TextEditingController> _drafts = {};

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }
  final Set<int> _busy = {};

  ApiClient get api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final q = <String, dynamic>{
      if (_filter == 'unanswered') 'unanswered': '1',
      if (_filter == 'neg') 'rating': '1,2',
      if (_filter == 'mid') 'rating': '3',
      if (_filter == 'pos') 'rating': '4,5',
      if (_q.isNotEmpty) 'q': _q,
    };
    try {
      final r = await api.get('avis_google/reviews', query: q);
      if (mounted) setState(() => _list = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _suggest(Map<String, dynamic> r) async {
    setState(() => _busy.add(r['id'] as int));
    try {
      final res = await api.post('avis_google/reviews/${r['id']}/suggest', {'tone': 'chaleureux'});
      setState(() => (_drafts[r['id'] as int] ??= TextEditingController()).text = '${res['text']}');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(r['id']));
    }
  }

  Future<void> _reply(Map<String, dynamic> r) async {
    final t = _drafts[r['id']]?.text.trim() ?? '';
    if (t.isEmpty) return;
    setState(() => _busy.add(r['id'] as int));
    try {
      await api.post('avis_google/reviews/${r['id']}/reply', {'text': t});
      _drafts.remove(r['id']);
      if (mounted) toast(context, tr('Réponse publiée sur Google.'));
      await _load();
      widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(r['id']));
    }
  }

  @override
  Widget build(BuildContext context) {
    final canReply = widget.info['can_reply'] == true && widget.info['mode'] == 'api';
    final dist = asMap((widget.info['stats'] as Map)['distribution']);
    final total = dist.values.fold<num>(0, (a, b) => a + (b as num));
    final shown = (_list ?? const <Map<String, dynamic>>[])
        .where((r) => _f['rating'] == null || '${r['rating']}' == _f['rating'])
        .where((r) => _f['reply'] == null || (r['reply'] != null) == (_f['reply'] == 'yes'))
        .where((r) => _period == null || _period!.contains(DateTime.tryParse('${r['reviewed_at'] ?? ''}')?.toLocal()))
        .toList();
    return LayoutBuilder(builder: (context, c) {
      final list = Column(children: [
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: FilterBar(
            key: ValueKey(_rk),
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
            onSearch: (v) {
              _q = v.trim();
              _debounce?.cancel();
              _debounce = Timer(const Duration(milliseconds: 450), _load);
            },
            search: _q,
            searchHint: 'Auteur, commentaire…',
            statuses: const {'': 'Tous', 'unanswered': 'Sans réponse', 'neg': '1–2 étoiles', 'mid': '3 étoiles', 'pos': '4–5 étoiles'},
            status: _filter,
            onStatus: (v) {
              setState(() => _filter = v);
              _load();
            },
            filters: const [
              FilterSpec('rating', 'Note', {'5': '5 étoiles', '4': '4 étoiles', '3': '3 étoiles', '2': '2 étoiles', '1': '1 étoile'}, icon: Icons.star_outline_rounded),
              FilterSpec('reply', 'Réponse', {'yes': 'Avec réponse', 'no': 'Sans réponse'}, icon: Icons.reply_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: _list == null ? null : shown.length,
            countLabel: 'avis',
            onReset: () {
              setState(() => (_q = '', _filter = '', _f = {}, _period = null, _rk++));
              _load();
            },
          ),
        ),
        Expanded(
          child: _list == null
              ? const Center(child: CircularProgressIndicator())
              : shown.isEmpty
                  ? EmptyState(icon: Icons.reviews_outlined, title: tr(_list!.isEmpty ? 'Aucun avis ici' : 'Aucun avis ne correspond aux filtres'))
                  : ListView(padding: const EdgeInsets.fromLTRB(16, 6, 16, 24), children: [for (final r in shown) _card(r, canReply)]),
        ),
      ]);
      if (c.maxWidth < 1000) return list;
      return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(child: list),
        SizedBox(
          width: 280,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                  Text(tr('Répartition des notes'), style: TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 10),
                  for (var i = 5; i >= 1; i--)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 3),
                      child: Row(children: [
                        SizedBox(width: 16, child: Text(tr('$i'))),
                        const Icon(Icons.star_rounded, size: 14, color: _gStar),
                        const SizedBox(width: 6),
                        Expanded(
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(4),
                            child: LinearProgressIndicator(minHeight: 8, value: total == 0 ? 0 : ((dist['$i'] as num? ?? 0) / total).toDouble(), color: _gStar, backgroundColor: const Color(0xFFF1F3F4)),
                          ),
                        ),
                        SizedBox(width: 34, child: Text(tr('${dist['$i'] ?? 0}'), textAlign: TextAlign.right, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12))),
                      ]),
                    ),
                ]),
              ),
            ),
          ),
        ),
      ]);
    });
  }

  Widget _card(Map<String, dynamic> r, bool canReply) {
    final hint = Theme.of(context).hintColor;
    final d = DateTime.tryParse('${r['reviewed_at']}')?.toLocal();
    final draft = _drafts[r['id'] as int];
    final busy = _busy.contains(r['id']);
    final neg = (r['rating'] as int) <= 2;
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: neg && r['reply'] == null ? YColors.danger.withValues(alpha: .35) : Colors.black12)),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            CircleAvatar(
              radius: 18,
              backgroundColor: const Color(0xFFE8F0FE),
              foregroundImage: r['author_photo'] != null ? NetworkImage('${r['author_photo']}') : null,
              child: Text(tr('${r['author'] ?? '?'}'.isEmpty ? '?' : '${r['author']}'[0].toUpperCase()), style: const TextStyle(color: _gBlue, fontWeight: FontWeight.w800)),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${r['author']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                Row(children: [Stars((r['rating'] as int).toDouble(), size: 15), const SizedBox(width: 8), Text(tr(d == null ? '' : DateFormat('d MMM y', 'fr_FR').format(d)), style: TextStyle(color: hint, fontSize: 12))]),
              ]),
            ),
            if (r['reply'] == null) StatusChip(neg ? 'À traiter' : 'Sans réponse', color: neg ? YColors.danger : YColors.warning),
          ]),
          if ((r['comment'] ?? '').toString().isNotEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('${r['comment']}'), style: const TextStyle(height: 1.4))),
          if (r['reply'] != null)
            Container(
              margin: const EdgeInsets.only(top: 10),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: const Color(0xFFF1F3F4), borderRadius: BorderRadius.circular(10)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(tr('Réponse du propriétaire${r['replied_by'] != null ? ' · ${r['replied_by']}' : ''}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5))),
                  if (canReply)
                    IconButton(
                      visualDensity: VisualDensity.compact,
                      tooltip: tr('Modifier'),
                      icon: const Icon(Icons.edit_outlined, size: 18),
                      onPressed: () => setState(() => (_drafts[r['id'] as int] ??= TextEditingController()).text = '${r['reply']}'),
                    ),
                ]),
                Text(tr('${r['reply']}')),
              ]),
            ),
          if (canReply && (r['reply'] == null || draft != null)) ...[
            const SizedBox(height: 10),
            if (draft == null)
              Wrap(spacing: 8, children: [
                OutlinedButton.icon(onPressed: () => setState(() => _drafts[r['id'] as int] = TextEditingController()), icon: const Icon(Icons.reply, size: 18), label: Text(tr('Répondre'))),
                if (widget.info['ai'] == true)
                  TextButton.icon(onPressed: busy ? null : () => _suggest(r), icon: const Icon(Icons.auto_awesome, size: 18), label: Text(tr('Proposer une réponse (IA)'))),
              ])
            else ...[
              TextField(controller: draft, minLines: 3, maxLines: 8, decoration: InputDecoration(hintText: tr('Votre réponse publique…'))),
              const SizedBox(height: 8),
              Row(children: [
                if (widget.info['ai'] == true)
                  TextButton.icon(onPressed: busy ? null : () => _suggest(r), icon: const Icon(Icons.auto_awesome, size: 18), label: Text(tr('Proposer avec l\'IA'))),
                const Spacer(),
                TextButton(onPressed: () => setState(() => _drafts.remove(r['id'])), child: Text(tr('Annuler'))),
                FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: _gBlue),
                  onPressed: busy ? null : () => _reply(r),
                  icon: busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.send, size: 18),
                  label: Text(tr('Publier sur Google')),
                ),
              ]),
            ],
          ],
        ]),
      ),
    );
  }
}

// ---------------------------------------------------------------------------- demandes d'avis

class _RequestsTab extends StatefulWidget {
  const _RequestsTab({required this.info, required this.onChanged});

  final Map<String, dynamic> info;
  final VoidCallback onChanged;

  @override
  State<_RequestsTab> createState() => _RequestsTabState();
}

class _RequestsTabState extends State<_RequestsTab> {
  List<Map<String, dynamic>>? _list;
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  int _rk = 0;

  static const _reqStatuses = {'scheduled': 'Programmée', 'sent': 'Envoyée', 'clicked': 'Ouverte', 'feedback': 'Retour reçu', 'failed': 'Échec', 'cancelled': 'Annulée'};

  ApiClient get api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await api.get('avis_google/requests');
      if (mounted) setState(() => _list = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (e) {
      if (mounted) {
        setState(() => _list = []);
        toast(context, e is ApiException ? e.message : tr('Connexion impossible : réessayez plus tard.'), error: true);
      }
    }
  }

  Future<void> _new() async {
    final partners = LocalDb.all('partners').where((p) => p['type'] != 'supplier' && p['deleted_at'] == null && ((p['phone'] ?? '').toString().isNotEmpty || (p['email'] ?? '').toString().isNotEmpty)).toList()
      ..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    Map<String, dynamic>? sel;
    final phone = TextEditingController(), name = TextEditingController(), email = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Demander un avis')),
          content: SizedBox(
            width: 440,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Autocomplete<Map<String, dynamic>>(
                displayStringForOption: (p) => '${p['name']}',
                optionsBuilder: (v) => v.text.length < 2 ? const [] : partners.where((p) => '${p['name']}'.toLowerCase().contains(v.text.toLowerCase())).take(8),
                onSelected: (p) => set(() => sel = p),
                fieldViewBuilder: (c, ctrl, f, s) => TextField(controller: ctrl, focusNode: f, decoration: InputDecoration(labelText: tr('Client'), prefixIcon: Icon(Icons.search))),
              ),
              if (sel == null) ...[
                const SizedBox(height: 8),
                Text(tr('ou saisissez les coordonnées :'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12.5)),
                TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'))),
                TextField(controller: phone, decoration: InputDecoration(labelText: tr('Téléphone (WhatsApp / SMS)'))),
                TextField(controller: email, decoration: InputDecoration(labelText: tr('E-mail'))),
              ] else
                ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.person), title: Text(tr('${sel!['name']}')), subtitle: Text(tr('${sel!['phone'] ?? ''} ${sel!['email'] ?? ''}'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Envoyer')))],
        ),
      ),
    );
    if (ok != true) return;
    try {
      await api.post('avis_google/requests', sel != null ? {'partner_id': sel!['id']} : {'name': name.text.trim(), 'phone': phone.text.trim(), if (email.text.trim().isNotEmpty) 'email': email.text.trim()});
      if (mounted) toast(context, tr('Demande d\'avis envoyée.'));
      await _load();
      widget.onChanged();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rs = asMap(widget.info['requests_settings']);
    final s = asMap(widget.info['stats']);
    final hint = Theme.of(context).hintColor;
    final all = _list ?? const <Map<String, dynamic>>[];
    final q = _q.toLowerCase();
    final base = all
        .where((r) => q.isEmpty || '${r['name'] ?? ''} ${r['to'] ?? ''} ${r['feedback'] ?? ''}'.toLowerCase().contains(q))
        .where((r) => _f['channel'] == null || '${r['channel']}' == _f['channel'])
        .where((r) => _f['feedback'] == null || (r['feedback'] != null) == (_f['feedback'] == 'yes'))
        .toList();
    final rows = base.where((r) => _status.isEmpty || '${r['status']}' == _status).toList();
    return ListView(padding: const EdgeInsets.all(16), children: [
      Card(
        color: rs['enabled'] == true ? const Color(0xFFE6F4EA) : const Color(0xFFFEF7E0),
        child: ListTile(
          leading: Icon(rs['enabled'] == true ? Icons.autorenew : Icons.pause_circle_outline, color: rs['enabled'] == true ? const Color(0xFF137333) : const Color(0xFFB06000)),
          title: Text(tr(rs['enabled'] == true ? 'Demandes automatiques actives' : 'Demandes automatiques désactivées'), style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text(tr(rs['enabled'] == true
              ? 'Après : ${(rs['triggers'] as List? ?? const []).map((t) => (widget.info['triggers'] as Map?)?[t] ?? t).join(', ')} · ${rs['delay_hours']} h plus tard · au plus une fois tous les ${rs['cooldown_days']} jours par client'
              : 'Activez-les dans Paramètres : chaque client reçoit un lien d\'avis par WhatsApp, SMS ou e-mail après son achat.')),
          trailing: Wrap(spacing: 16, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [Text(tr('${s['requests_30']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)), Text(tr('envoyées 30 j'), style: TextStyle(color: hint, fontSize: 11))]),
            Column(mainAxisSize: MainAxisSize.min, children: [Text(tr('${s['clicks_30']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18, color: _gBlue)), Text(tr('ouvertes'), style: TextStyle(color: hint, fontSize: 11))]),
          ]),
        ),
      ),
      Align(alignment: Alignment.centerLeft, child: FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: _gBlue), onPressed: _new, icon: const Icon(Icons.send), label: Text(tr('Demander un avis')))),
      const SizedBox(height: 8),
      if (_list == null)
        const Center(child: CircularProgressIndicator())
      else if (_list!.isEmpty)
        EmptyState(icon: Icons.outbox_outlined, title: tr('Aucune demande pour l\'instant'))
      else ...[
        FilterBar(
          key: ValueKey(_rk),
          padding: const EdgeInsets.only(bottom: 8),
          onSearch: (v) => setState(() => _q = v),
          search: _q,
          searchHint: 'Nom, numéro, e-mail…',
          statuses: {'': 'Toutes', for (final e in _reqStatuses.entries) if (all.any((r) => r['status'] == e.key)) e.key: e.value},
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {'': base.length, for (final k in _reqStatuses.keys) k: base.where((r) => r['status'] == k).length},
          filters: [
            FilterSpec('channel', 'Canal', {
              for (final e in const {'whatsapp': 'WhatsApp', 'sms': 'SMS', 'email': 'E-mail'}.entries)
                if (all.any((r) => r['channel'] == e.key)) e.key: e.value,
            }, icon: Icons.forum_outlined),
            const FilterSpec('feedback', 'Retour privé', {'yes': 'Avec retour', 'no': 'Sans retour'}, icon: Icons.feedback_outlined),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          count: rows.length,
          countLabel: 'demande',
          onReset: () => setState(() => (_q = '', _status = '', _f = {}, _rk++)),
        ),
        if (rows.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune demande ne correspond aux filtres')),
        if (rows.isNotEmpty)
        Card(
          child: Column(children: [
            for (final r in rows)
              ListTile(
                leading: Icon(switch ('${r['channel']}') { 'whatsapp' => Icons.chat, 'sms' => Icons.sms_outlined, _ => Icons.mail_outline }, color: _gBlue),
                title: Text(tr('${r['name'] ?? r['to']}')),
                subtitle: Text(tr([
                  '${r['to']}',
                  if (r['feedback'] != null) 'Retour privé ${r['feedback_rating']}/5 : ${r['feedback']}',
                  if (r['error'] != null) '${r['error']}',
                ].join(' · ')), maxLines: 2, overflow: TextOverflow.ellipsis),
                trailing: StatusChip(
                  switch ('${r['status']}') { 'scheduled' => 'Programmée', 'sent' => 'Envoyée', 'clicked' => 'Ouverte', 'feedback' => 'Retour reçu', 'failed' => 'Échec', 'cancelled' => 'Annulée', _ => '${r['status']}' },
                  color: switch ('${r['status']}') { 'clicked' => _gBlue, 'sent' => YColors.success, 'feedback' => YColors.warning, 'failed' => YColors.danger, _ => YColors.muted },
                ),
              ),
          ]),
        ),
      ],
    ]);
  }
}

// ---------------------------------------------------------------------------- publications

class _PostsTab extends StatefulWidget {
  const _PostsTab({required this.info});

  final Map<String, dynamic> info;

  @override
  State<_PostsTab> createState() => _PostsTabState();
}

class _PostsTabState extends State<_PostsTab> {
  List<Map<String, dynamic>>? _list;
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  int _rk = 0;

  static String _st(Map<String, dynamic> p) => p['status'] == 'published' ? 'published' : (p['status'] == 'failed' ? 'failed' : 'draft');

  ApiClient get api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await api.get('avis_google/posts');
      if (mounted) setState(() => _list = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (e) {
      if (mounted) {
        setState(() => _list = []);
        toast(context, e is ApiException ? e.message : tr('Connexion impossible : réessayez plus tard.'), error: true);
      }
    }
  }

  Future<void> _new() async {
    final summary = TextEditingController(), title = TextEditingController(), url = TextEditingController(), coupon = TextEditingController();
    var type = 'STANDARD';
    var cta = 'LEARN_MORE';
    String? photo;
    DateTime? end;
    final loc = (widget.info['locations'] as List).first as Map;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Publier sur Google')),
          content: SizedBox(
            width: 520,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                SegmentedButton<String>(
                  segments: [ButtonSegment(value: 'STANDARD', label: Text(tr('Actualité'))), ButtonSegment(value: 'OFFER', label: Text(tr('Offre'))), ButtonSegment(value: 'EVENT', label: Text(tr('Événement')))],
                  selected: {type},
                  onSelectionChanged: (v) => set(() => type = v.first),
                ),
                if (type != 'STANDARD') TextField(controller: title, maxLength: 58, decoration: InputDecoration(labelText: tr('Titre'))),
                TextField(controller: summary, minLines: 3, maxLines: 6, maxLength: 1500, decoration: InputDecoration(labelText: tr('Texte'))),
                if (type == 'OFFER') TextField(controller: coupon, decoration: InputDecoration(labelText: tr('Code promo (facultatif)'))),
                if (type != 'STANDARD')
                  TextButton.icon(
                    onPressed: () async {
                      final d = await showDatePicker(context: c, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)), initialDate: DateTime.now().add(const Duration(days: 7)));
                      if (d != null) set(() => end = d);
                    },
                    icon: const Icon(Icons.event),
                    label: Text(tr(end == null ? 'Date de fin' : 'Jusqu\'au ${DateFormat('d MMM y', 'fr_FR').format(end!)}')),
                  ),
                DropdownButtonFormField<String>(
                  initialValue: cta,
                  decoration: InputDecoration(labelText: tr('Bouton')),
                  items: [
                    DropdownMenuItem(value: 'LEARN_MORE', child: Text(tr('En savoir plus'))), DropdownMenuItem(value: 'ORDER', child: Text(tr('Commander'))), DropdownMenuItem(value: 'BOOK', child: Text(tr('Réserver'))),
                    DropdownMenuItem(value: 'SHOP', child: Text(tr('Acheter'))), DropdownMenuItem(value: 'CALL', child: Text(tr('Appeler'))), DropdownMenuItem(value: 'SIGN_UP', child: Text(tr('S\'inscrire'))),
                  ],
                  onChanged: (v) => set(() => cta = v ?? 'LEARN_MORE'),
                ),
                if (cta != 'CALL') TextField(controller: url, decoration: InputDecoration(labelText: tr('Lien du bouton'), hintText: tr('https://'))),
                const SizedBox(height: 8),
                Row(children: [
                  if (photo != null) Padding(padding: const EdgeInsets.only(right: 8), child: ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.network(photo!, height: 60))),
                  OutlinedButton.icon(
                    onPressed: () async {
                      final f = await FilePicker.pickFiles(type: FileType.image);
                      if (f.isEmpty) return;
                      try {
                        final r = await api.upload('avis_google/media', 'file', await f.first.readAsBytes(), f.first.name);
                        set(() => photo = '${r['url']}');
                      } catch (e) {
                        if (c.mounted) toast(c, errorText(e), error: true);
                      }
                    },
                    icon: const Icon(Icons.image_outlined),
                    label: Text(tr(photo == null ? 'Ajouter une photo' : 'Changer la photo')),
                  ),
                ]),
              ]),
            ),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Publier')))],
        ),
      ),
    );
    if (ok != true || summary.text.trim().isEmpty) return;
    try {
      await api.post('avis_google/posts', {
        'location_id': loc['id'], 'type': type, 'summary': summary.text.trim(), if (title.text.trim().isNotEmpty) 'title': title.text.trim(), 'cta_type': cta,
        if (url.text.trim().isNotEmpty) 'cta_url': url.text.trim(), 'photo_url': ?photo, if (coupon.text.trim().isNotEmpty) 'coupon': coupon.text.trim(),
        if (end != null) 'ends_on': DateFormat('yyyy-MM-dd').format(end!),
      });
      if (mounted) toast(context, tr('Publié sur votre fiche Google.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final canPost = widget.info['can_manage'] == true && widget.info['mode'] == 'api';
    final q = _q.toLowerCase();
    final postBase = (_list ?? const <Map<String, dynamic>>[])
        .where((p) => q.isEmpty || '${p['title'] ?? ''} ${p['summary'] ?? ''}'.toLowerCase().contains(q))
        .where((p) => _f['type'] == null || '${p['type'] ?? 'STANDARD'}' == _f['type'])
        .toList();
    final posts = postBase.where((p) => _status.isEmpty || _st(p) == _status).toList();
    return ListView(padding: const EdgeInsets.all(16), children: [
      Row(children: [
        Expanded(child: Text(tr('Actualités, offres et événements affichés sur votre fiche Google (Maps et recherche).'), style: TextStyle(color: Theme.of(context).hintColor))),
        FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: _gBlue), onPressed: canPost ? _new : null, icon: const Icon(Icons.add), label: Text(tr('Publication'))),
      ]),
      if (!canPost) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Disponible avec la fiche Google Business connectée.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
      const SizedBox(height: 10),
      if (_list != null && _list!.isEmpty) EmptyState(icon: Icons.campaign_outlined, title: tr('Aucune publication')),
      if (_list != null && _list!.isNotEmpty)
        FilterBar(
          key: ValueKey(_rk),
          padding: const EdgeInsets.only(bottom: 8),
          onSearch: (v) => setState(() => _q = v),
          search: _q,
          searchHint: 'Titre, texte…',
          statuses: const {'': 'Toutes', 'published': 'Publiées', 'draft': 'Brouillons', 'failed': 'Échecs'},
          status: _status,
          onStatus: (v) => setState(() => _status = v),
          statusCounts: {'': postBase.length, for (final k in ['published', 'draft', 'failed']) k: postBase.where((p) => _st(p) == k).length},
          filters: const [FilterSpec('type', 'Type', {'STANDARD': 'Actualité', 'OFFER': 'Offre', 'EVENT': 'Événement'}, icon: Icons.category_outlined)],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          count: posts.length,
          countLabel: 'publication',
          onReset: () => setState(() => (_q = '', _status = '', _f = {}, _rk++)),
        ),
      if (_list != null && _list!.isNotEmpty && posts.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune publication ne correspond aux filtres')),
      for (final p in posts)
        Card(
          child: ListTile(
            leading: p['photo_url'] != null ? ClipRRect(borderRadius: BorderRadius.circular(6), child: Image.network('${p['photo_url']}', width: 56, height: 56, fit: BoxFit.cover)) : const Icon(Icons.article_outlined, color: _gBlue),
            title: Text(tr('${p['title'] ?? (p['type'] == 'OFFER' ? 'Offre' : p['type'] == 'EVENT' ? 'Événement' : 'Actualité')}')),
            subtitle: Text(tr('${p['summary']}'), maxLines: 2, overflow: TextOverflow.ellipsis),
            trailing: StatusChip(p['status'] == 'published' ? 'Publiée' : (p['status'] == 'failed' ? 'Échec' : 'Brouillon'),
                color: p['status'] == 'published' ? YColors.success : (p['status'] == 'failed' ? YColors.danger : YColors.muted)),
          ),
        ),
    ]);
  }
}

// ---------------------------------------------------------------------------- fiche et statistiques

class _ProfileTab extends StatefulWidget {
  const _ProfileTab({required this.info, required this.onChanged});

  final Map<String, dynamic> info;
  final VoidCallback onChanged;

  @override
  State<_ProfileTab> createState() => _ProfileTabState();
}

class _ProfileTabState extends State<_ProfileTab> {
  late Map<String, dynamic> loc = asMap((widget.info['locations'] as List).first);
  late final _desc = TextEditingController(text: '${loc['description'] ?? ''}');
  late final _phone = TextEditingController(text: '${loc['phone'] ?? ''}');
  late final _site = TextEditingController(text: '${loc['website'] ?? ''}');

  ApiClient get api => context.read<Session>().api;

  @override
  Widget build(BuildContext context) {
    final m = asMap(loc['metrics']);
    final hist = asMap(loc['history']);
    final days = asMap(m['days']);
    final canEdit = widget.info['can_manage'] == true;
    Widget kpi(IconData i, String l, dynamic v, Color c) => StatTile(label: l, value: '${v ?? '–'}', icon: i, color: c);
    final cur = context.read<Session>().currency;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(tr('30 derniers jours'), style: TextStyle(color: Theme.of(context).hintColor, fontWeight: FontWeight.w700)),
      const SizedBox(height: 6),
      StatStrip(padding: const EdgeInsets.only(bottom: 12), tiles: [
        kpi(Icons.visibility_outlined, 'Vues de la fiche', m['views'], _gBlue),
        kpi(Icons.call_outlined, 'Appels', m['calls'], const Color(0xFF34A853)),
        kpi(Icons.directions_outlined, 'Itinéraires', m['directions'], const Color(0xFFEA4335)),
        kpi(Icons.language, 'Clics vers le site', m['website'], const Color(0xFFB06000)),
        kpi(Icons.qr_code_2, 'Scans du QR code', m['qr_scans'] ?? 0, YColors.muted),
      ]),
      if (days.isNotEmpty)
        SectionCard(
          title: tr('Vues par jour'),
          child: SizedBox(
            height: 220,
            child: CartesianChart(
              data: ChartData([for (final k in days.keys) '${k.substring(8)}/${k.substring(5, 7)}'], [ChartSeries('Vues', [for (final v in days.values) ((v as Map)['views'] as num?)?.toDouble() ?? 0])], 'number'),
              currency: cur,
              line: true,
            ),
          ),
        ),
      if (hist.length > 1)
        SectionCard(
          title: tr('Évolution de la note'),
          child: SizedBox(
            height: 200,
            child: CartesianChart(
              data: ChartData([for (final k in hist.keys) '${k.substring(8)}/${k.substring(5, 7)}'], [ChartSeries('Note', [for (final v in hist.values) ((v as Map)['r'] as num?)?.toDouble()])], 'number'),
              currency: cur,
              line: true,
            ),
          ),
        ),
      SectionCard(
        title: tr('Informations de la fiche'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          ListTile(contentPadding: EdgeInsets.zero, leading: const Icon(Icons.storefront), title: Text(tr('${loc['title']}')), subtitle: Text(tr('${loc['address'] ?? ''}'))),
          TextField(controller: _desc, enabled: canEdit, minLines: 2, maxLines: 5, maxLength: 750, decoration: InputDecoration(labelText: tr('Description'))),
          TextField(controller: _phone, enabled: canEdit, decoration: InputDecoration(labelText: tr('Téléphone'))),
          TextField(controller: _site, enabled: canEdit, decoration: InputDecoration(labelText: tr('Site web'))),
          const SizedBox(height: 10),
          Wrap(spacing: 8, children: [
            if (loc['maps_uri'] != null) OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${loc['maps_uri']}'), webOnlyWindowName: '_blank'), icon: const Icon(Icons.map_outlined), label: Text(tr('Voir sur Google Maps'))),
            if (canEdit)
              FilledButton(
                onPressed: () async {
                  try {
                    final r = await api.put('avis_google/locations/${loc['id']}', {'description': _desc.text.trim(), 'phone': _phone.text.trim(), if (_site.text.trim().isNotEmpty) 'website': _site.text.trim()});
                    setState(() => loc = asMap(r['data']));
                    if (context.mounted) toast(context, widget.info['mode'] == 'api' ? 'Fiche Google mise à jour.' : 'Enregistré dans Yomara (connectez la fiche pour la modifier sur Google).');
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
          ]),
        ]),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------- paramètres

class _PlaceSearch extends StatefulWidget {
  const _PlaceSearch();

  @override
  State<_PlaceSearch> createState() => _PlaceSearchState();
}

class _PlaceSearchState extends State<_PlaceSearch> {
  final _q = TextEditingController();
  List<Map<String, dynamic>> _res = [];
  bool _busy = false;

  Future<void> _search() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('avis_google/places/search', {'query': _q.text.trim()});
      setState(() => _res = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => YAlertDialog(
        title: Text(tr('Retrouver mon établissement')),
        content: SizedBox(
          width: 480,
          height: 380,
          child: Column(children: [
            TextField(controller: _q, autofocus: true, decoration: InputDecoration(labelText: tr('Nom et ville'), hintText: tr('Maquis Le Bon Goût Cocody'), suffixIcon: IconButton(icon: const Icon(Icons.search), onPressed: _search)), onSubmitted: (_) => _search()),
            if (_busy) const LinearProgressIndicator(),
            Expanded(
              child: ListView(children: [
                for (final p in _res)
                  ListTile(
                    leading: const Icon(Icons.place, color: Color(0xFFEA4335)),
                    title: Text(tr('${p['name']}')),
                    subtitle: Text(tr('${p['address']}${p['rating'] != null ? ' · ${p['rating']}/5 (${p['count']} avis)' : ''}')),
                    onTap: () => Navigator.pop(context, p),
                  ),
              ]),
            ),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler')))],
      );
}

class _AvisSettings extends StatefulWidget {
  const _AvisSettings({required this.info, required this.onConnect, required this.onFindPlace});

  final Map<String, dynamic> info;
  final Future<void> Function() onConnect;
  final Future<void> Function() onFindPlace;

  @override
  State<_AvisSettings> createState() => _AvisSettingsState();
}

class _AvisSettingsState extends State<_AvisSettings> {
  late final Map<String, dynamic> rs = asMap(widget.info['requests_settings']);
  late final Map<String, dynamic> al = asMap(widget.info['alerts']);
  late final _msg = TextEditingController(text: '${rs['message'] ?? ''}');
  late final _wa = TextEditingController(text: '${al['whatsapp'] ?? ''}');
  late final _mail = TextEditingController(text: '${al['email'] ?? ''}');
  late final Set<String> _triggers = {for (final t in (rs['triggers'] as List? ?? const [])) '$t'};
  late final Set<String> _channels = {for (final t in (rs['channels'] as List? ?? const [])) '$t'};

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final triggers = asMap(widget.info['triggers']);
    return YAlertDialog(
      title: Text(tr('Paramètres des avis Google')),
      content: SizedBox(
        width: 620,
        height: 520,
        child: DefaultTabController(
          length: 3,
          child: Column(children: [
            TabBar(tabs: [Tab(text: tr('Connexion')), Tab(text: tr('Demandes d\'avis')), Tab(text: tr('Alertes'))]),
            Expanded(
              child: TabBarView(children: [
                ListView(padding: const EdgeInsets.only(top: 12), children: [
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(widget.info['mode'] == 'api' ? Icons.check_circle : Icons.link_off, color: widget.info['mode'] == 'api' ? YColors.success : YColors.warning),
                    title: Text(tr(widget.info['mode'] == 'api' ? 'Fiche Google Business connectée' : (widget.info['mode'] == 'places' ? 'Mode lecture seule (API Places)' : 'Non connecté'))),
                    subtitle: Text(tr(widget.info['connected_email'] != null ? 'Compte : ${widget.info['connected_email']}' : 'Connectez la fiche pour répondre, publier et voir les statistiques.')),
                  ),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: _gBlue), onPressed: widget.onConnect, icon: const Icon(Icons.login), label: Text(tr(widget.info['mode'] == 'api' ? 'Reconnecter' : 'Connecter ma fiche Google'))),
                    OutlinedButton.icon(onPressed: widget.onFindPlace, icon: const Icon(Icons.search), label: Text(tr('Ajouter un établissement (lecture seule)'))),
                    if (widget.info['mode'] == 'api')
                      TextButton(
                        onPressed: () async {
                          if (!await confirm(context, tr('Déconnecter la fiche Google ?'), tr('Les avis ne seront plus synchronisés ni les réponses envoyées tant que vous ne reconnectez pas.'), ok: 'Déconnecter', danger: true)) return;
                          await api.delete('avis_google/connect');
                          if (context.mounted) Navigator.pop(context);
                        },
                        child: Text(tr('Déconnecter'), style: TextStyle(color: YColors.danger)),
                      ),
                  ]),
                  const SizedBox(height: 14),
                  Text(
                    tr('La connexion utilise l\'application Google de Boîte mail › Paramètres. Ajoutez-y l\'adresse de retour ${widget.info['redirect_uri']} et activez les API « My Business Account Management », « My Business Business Information », '
                    '« Business Profile Performance » et « Google My Business » (l\'accès à ces API se demande à Google, réponse en quelques jours). En attendant, le mode lecture seule fonctionne avec une simple clé API Places.'),
                    style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5, height: 1.5),
                  ),
                ]),
                ListView(padding: const EdgeInsets.only(top: 12), children: [
                  SwitchListTile(contentPadding: EdgeInsets.zero, value: rs['enabled'] == true, onChanged: (v) => setState(() => rs['enabled'] = v), title: Text(tr('Envoyer automatiquement une demande d\'avis'))),
                  Text(tr('Après :'), style: TextStyle(fontWeight: FontWeight.w700)),
                  for (final t in triggers.entries)
                    CheckboxListTile(dense: true, contentPadding: EdgeInsets.zero, value: _triggers.contains(t.key), onChanged: (v) => setState(() => v == true ? _triggers.add(t.key) : _triggers.remove(t.key)), title: Text(tr('${t.value}'))),
                  Text(tr('Par (dans cet ordre, selon les coordonnées du client) :'), style: TextStyle(fontWeight: FontWeight.w700)),
                  Wrap(spacing: 6, children: [
                    for (final c in const [('whatsapp', 'WhatsApp'), ('sms', 'SMS'), ('email', 'E-mail')])
                      FilterChip(label: Text(tr(c.$2)), selected: _channels.contains(c.$1), onSelected: (v) => setState(() => v ? _channels.add(c.$1) : _channels.remove(c.$1))),
                  ]),
                  Row(children: [
                    Expanded(child: TextFormField(initialValue: '${rs['delay_hours']}', keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Délai (heures)')), onChanged: (v) => rs['delay_hours'] = int.tryParse(v) ?? 2)),
                    const SizedBox(width: 12),
                    Expanded(child: TextFormField(initialValue: '${rs['cooldown_days']}', keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Pas plus d\'une fois tous les (jours)')), onChanged: (v) => rs['cooldown_days'] = int.tryParse(v) ?? 90)),
                  ]),
                  TextField(controller: _msg, minLines: 3, maxLines: 5, decoration: InputDecoration(labelText: tr('Message'), helperText: tr('Variables : {nom}, {societe}, {lien} (obligatoire)'))),
                  const SizedBox(height: 8),
                  Text(tr('Tous les clients reçoivent la même demande, sans tri selon leur satisfaction (règle de Google). La page d\'avis propose aussi d\'écrire directement à l\'équipe.'),
                      style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                ]),
                ListView(padding: const EdgeInsets.only(top: 12), children: [
                  DropdownButtonFormField<int>(
                    initialValue: (al['max_rating'] as num?)?.toInt() ?? 2,
                    decoration: InputDecoration(labelText: tr('M\'alerter pour les avis jusqu\'à')),
                    items: [DropdownMenuItem(value: 1, child: Text(tr('1 étoile'))), DropdownMenuItem(value: 2, child: Text(tr('2 étoiles'))), DropdownMenuItem(value: 3, child: Text(tr('3 étoiles')))],
                    onChanged: (v) => al['max_rating'] = v ?? 2,
                  ),
                  TextField(controller: _wa, decoration: InputDecoration(labelText: tr('Aussi par WhatsApp au numéro (facultatif)'))),
                  TextField(controller: _mail, decoration: InputDecoration(labelText: tr('Aussi par e-mail à (facultatif)'))),
                  const SizedBox(height: 8),
                  Text(tr('Les responsables et les personnes qui répondent aux avis reçoivent toujours une notification dans Yomara.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                ]),
              ]),
            ),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
        FilledButton(
          onPressed: () async {
            try {
              await api.put('avis_google/settings', {
                'requests': {...rs, 'message': _msg.text.trim(), 'triggers': _triggers.toList(), 'channels': _channels.toList()},
                'alerts': {...al, 'whatsapp': _wa.text.trim().isEmpty ? null : _wa.text.trim(), 'email': _mail.text.trim().isEmpty ? null : _mail.text.trim()},
              });
              if (context.mounted) {
                Navigator.pop(context);
                toast(context, tr('Paramètres enregistrés.'));
              }
            } catch (e) {
              if (context.mounted) toast(context, errorText(e), error: true);
            }
          },
          child: Text(tr('Enregistrer')),
        ),
      ],
    );
  }
}
