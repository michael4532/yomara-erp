import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/api_client.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'email_builder.dart';
import 'mk_common.dart';
import '../../../i18n/i18n.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ======================================================================
// Liste des campagnes
// ======================================================================

class CampaignsTab extends StatelessWidget {
  const CampaignsTab({super.key, required this.data, required this.reload, required this.canManage});

  final Map<String, dynamic> data;
  final Future<void> Function() reload;
  final bool canManage;

  @override
  Widget build(BuildContext context) {
    final campaigns = mkList(data['campaigns']);
    final sent = campaigns.where((c) => c['status'] == 'sent').toList();
    final rates = sent.map((c) => mkMap(c['stats'])['open_rate']).whereType<num>().toList();
    final avgOpen = rates.isEmpty ? null : rates.reduce((a, b) => a + b) / rates.length;
    final messages = sent.fold<int>(0, (s, c) => s + ((mkMap(c['stats'])['sent'] as num?)?.toInt() ?? 0));
    final channels = mkMap(data['channels']);

    return RefreshIndicator(
      onRefresh: reload,
      child: ListView(padding: EdgeInsets.fromLTRB(MediaQuery.sizeOf(context).width < 700 ? 16 : 24, 16, MediaQuery.sizeOf(context).width < 700 ? 16 : 24, 24), children: [
        KpiWrap(spacing: 10, minTile: 200, children: [
          KpiBox(child: MkKpi('Contacts', number(data['contacts_total'] as num?), Icons.people_outline)),
          KpiBox(child: MkKpi('Campagnes envoyées', '${sent.length}', Icons.campaign_outlined)),
          KpiBox(child: MkKpi('Messages envoyés', number(messages), Icons.send_outlined)),
          KpiBox(child: MkKpi('Taux d\'ouverture moyen', avgOpen == null ? '—' : '${avgOpen.toStringAsFixed(1)} %', Icons.drafts_outlined)),
          KpiBox(child: MkKpi('Automatisations actives', '${data['automations_active'] ?? 0}', Icons.account_tree_outlined)),
        ]),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final ch in ['email', 'sms', 'whatsapp'])
            Chip(
              avatar: Icon(channels[ch] != null ? Icons.check_circle : Icons.cancel_outlined, size: 18, color: channels[ch] != null ? YColors.success : YColors.muted),
              label: Text(tr('${mkChannelLabels[ch]} · ${channels[ch] == null ? 'non configuré' : (mkProviderNames[channels[ch]] ?? channels[ch])}')),
            ),
        ]),
        const SizedBox(height: 12),
        if (campaigns.isEmpty)
          EmptyState(icon: Icons.campaign_outlined, title: tr('Aucune campagne'), message: tr('Créez votre première newsletter, promotion ou proposition commerciale.'))
        else
          Card(
            child: Column(children: [
              for (final c in campaigns) ...[
                ListTile(
                  leading: CircleAvatar(backgroundColor: YColors.blue.withValues(alpha: .1), child: Icon(channelIcons[c['channel']], color: YColors.blue)),
                  title: Text(tr('${c['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text(tr([
                    c['kind_label'],
                    if (c['status'] == 'scheduled') 'programmée le ${dateTimeFr(c['scheduled_at'])}',
                    if (c['sent_at'] != null) 'envoyée le ${dateTimeFr(c['sent_at'])}',
                    if (c['status'] == 'draft') 'créée le ${dateFr(c['created_at'])}',
                  ].join(' · '))),
                  trailing: Wrap(spacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    if (mkMap(c['stats'])['sent'] != null && MediaQuery.of(context).size.width > 800) ...[
                      _Mini('Envoyés', '${mkMap(c['stats'])['sent']}'),
                      if (c['channel'] == 'email') _Mini('Ouverture', mkMap(c['stats'])['open_rate'] == null ? '—' : '${mkMap(c['stats'])['open_rate']} %'),
                      _Mini('Clics', '${mkMap(c['stats'])['clicked'] ?? 0}'),
                    ],
                    StatusChip('${c['status_label']}', color: mkStatusColors[c['status']] ?? YColors.muted),
                  ]),
                  onTap: () async {
                    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => CampaignScreen(id: c['id'] as int, overview: data)));
                    reload();
                  },
                ),
                const Divider(height: 1),
              ],
            ]),
          ),
      ]),
    );
  }
}

class _Mini extends StatelessWidget {
  const _Mini(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(tr(value), style: const TextStyle(fontWeight: FontWeight.w700)),
        Text(tr(label), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
      ]);
}

// ======================================================================
// Création : options de construction
// ======================================================================

class _StartOption {
  const _StartOption(this.key, this.title, this.text, this.icon);

  final String key;
  final String title;
  final String text;
  final IconData icon;
}

const _emailStarts = [
  _StartOption('layout', 'Mise en page prête', 'Newsletter, promotion, lancement, invitation, anniversaire…', Icons.auto_awesome_mosaic_outlined),
  _StartOption('blank', 'Glisser-déposer', 'Page blanche : composez avec les blocs et sections', Icons.dashboard_customize_outlined),
  _StartOption('template', 'Mes modèles', 'Un modèle enregistré par votre équipe', Icons.bookmark_outline),
  _StartOption('copy', 'Copier une campagne', 'Reprendre le contenu et les destinataires', Icons.content_copy),
  _StartOption('html', 'HTML personnalisé', 'Coller ou importer votre propre code', Icons.code),
];

Future<void> newCampaign(BuildContext context, Map<String, dynamic> overview, Future<void> Function() reload) async {
  final name = TextEditingController();
  var kind = 'newsletter';
  var channel = 'email';
  var start = 'layout';
  Map<String, dynamic>? layout;
  int? template;
  int? copyOf;
  final templates = mkList(overview['templates']);
  final campaigns = mkList(overview['campaigns']);
  final kinds = mkMap(overview['kinds']);
  final api = context.read<Session>().api;
  final id = await showFormDialog<int>(
    context,
    title: tr('Nouvelle campagne'),
    builder: (c) => StatefulBuilder(
      builder: (c, set) {
        final starts = channel == 'email'
            ? _emailStarts
            : const [_StartOption('blank', 'Message vierge', 'Écrire le message', Icons.edit_note), _StartOption('template', 'Mes modèles', 'Un modèle de message', Icons.bookmark_outline)];
        if (!starts.any((s) => s.key == start)) start = 'blank';
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          Field(label: tr('Nom de la campagne'), child: TextField(controller: name, autofocus: true, decoration: InputDecoration(hintText: tr('Ex. Promotion de novembre')))),
          Row(children: [
            Expanded(
              child: Field(
                label: tr('Type'),
                child: DropdownButtonFormField<String>(
                  initialValue: kind,
                  isExpanded: true,
                  items: [for (final k in kinds.entries) DropdownMenuItem(value: k.key, child: Text(tr('${k.value}')))],
                  onChanged: (v) => set(() => kind = v ?? kind),
                ),
              ),
            ),
          ]),
          Field(
            label: tr('Canal'),
            child: SegmentedButton<String>(
              segments: [for (final ch in ['email', 'sms', 'whatsapp']) ButtonSegment(value: ch, icon: Icon(channelIcons[ch]), label: Text(tr(mkChannelLabels[ch]!)))],
              selected: {channel},
              onSelectionChanged: (s) => set(() {
                channel = s.first;
                template = null;
              }),
            ),
          ),
          Text(tr('Point de départ'), style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          for (final s in starts)
            Card(
              margin: const EdgeInsets.only(bottom: 6),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10), side: BorderSide(color: start == s.key ? YColors.blue : YColors.line, width: start == s.key ? 2 : 1)),
              child: ListTile(
                leading: Icon(s.icon, color: YColors.blue),
                title: Text(tr(s.title), style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text(
                  tr(s.key == 'layout' && layout != null ? 'Choisie : ${layout!['name']}' : s.text),
                  style: TextStyle(color: s.key == 'layout' && layout != null ? YColors.success : null, fontWeight: s.key == 'layout' && layout != null ? FontWeight.w600 : null),
                ),
                trailing: start == s.key ? const Icon(Icons.check_circle, color: YColors.blue) : null,
                onTap: () async {
                  set(() => start = s.key);
                  if (s.key == 'layout') {
                    final l = await pickLayout(c);
                    if (l != null) set(() => layout = l);
                  }
                },
              ),
            ),
          if (start == 'template')
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final t in templates.where((t) => t['channel'] == channel))
                ChoiceChip(label: Text(tr('${t['name']}')), selected: template == t['id'], onSelected: (_) => set(() => template = t['id'] as int)),
              if (templates.where((t) => t['channel'] == channel).isEmpty) Text(tr('Aucun modèle pour ce canal.')),
            ]),
          if (start == 'copy')
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final cp in campaigns.where((x) => x['channel'] == channel).take(12))
                ChoiceChip(label: Text(tr('${cp['name']}')), selected: copyOf == cp['id'], onSelected: (_) => set(() => copyOf = cp['id'] as int)),
            ]),
          if (mkMap(overview['channels'])[channel] == null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(tr('Le canal ${mkChannelLabels[channel]} n\'est pas encore configuré : vous pourrez préparer la campagne, mais pas l\'envoyer.'), style: const TextStyle(color: YColors.warning)),
            ),
          const SizedBox(height: 16),
          SaveButton(
            onPressed: () async {
              if (name.text.trim().isEmpty) {
                toast(c, tr('Donnez un nom à la campagne.'), error: true);
                return;
              }
              if (start == 'layout' && layout == null) {
                final l = await pickLayout(c);
                if (l == null) return;
                set(() => layout = l);
              }
              try {
                final r = mkMap(await api.post('marketing/campaigns', {
                  'name': name.text.trim(),
                  'kind': kind,
                  'channel': channel,
                  if (start == 'layout' && channel == 'email') 'layout': layout?['key'],
                  if (start == 'template') 'template_id': ?template,
                  if (start == 'copy') 'copy_of': ?copyOf,
                  if (start == 'html') 'html': '<!doctype html>\n<html><body style="font-family:Arial">\n  <p>Bonjour {{prenom}},</p>\n  <p>Votre contenu…</p>\n  <p><a href="{{lien_desinscription}}">Se désinscrire</a></p>\n</body></html>',
                }));
                if (c.mounted) Navigator.pop(c, r['id'] as int);
              } catch (e) {
                if (c.mounted) toast(c, errorText(e), error: true);
              }
            },
            child: Text(tr('Créer et concevoir')),
          ),
        ]);
      },
    ),
  );
  if (id != null && context.mounted) {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => CampaignScreen(id: id, overview: overview)));
    reload();
  }
}

// ======================================================================
// Fiche campagne
// ======================================================================

class CampaignScreen extends StatefulWidget {
  const CampaignScreen({super.key, required this.id, required this.overview});

  final int id;
  final Map<String, dynamic> overview;

  @override
  State<CampaignScreen> createState() => _CampaignScreenState();
}

class _CampaignScreenState extends State<CampaignScreen> {
  Map<String, dynamic>? _c;
  Object? _error;
  final _editor = GlobalKey<ContentEditorState>();
  bool _busy = false;

  ApiClient get _api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final c = mkMap(await _api.get('marketing/campaigns/${widget.id}'));
      if (mounted) setState(() => (_c = c, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  bool get _editable => _c != null && ['draft', 'scheduled'].contains(_c!['status']);

  Future<bool> _save({bool quiet = false}) async {
    final content = _editor.currentState?.value;
    if (content == null) return true;
    setState(() => _busy = true);
    try {
      final c = mkMap(await _api.put('marketing/campaigns/${widget.id}', content));
      if (mounted) {
        setState(() => _c = {..._c!, 'recipients_estimate': c['recipients_estimate'], 'status_label': c['status_label'], 'attachments': c['attachments']});
        _editor.currentState?.updateEstimate(c['recipients_estimate']);
        if (!quiet) toast(context, tr('Campagne enregistrée.'));
      }
      return true;
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return false;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _action(String path, [Map<String, dynamic>? body, String? ok]) async {
    setState(() => _busy = true);
    try {
      final c = mkMap(await _api.post('marketing/campaigns/${widget.id}/$path', body ?? {}));
      if (mounted) {
        setState(() => _c = c);
        if (ok != null) toast(context, ok);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _test() async {
    if (!await _save(quiet: true) || !mounted) return;
    final to = TextEditingController(text: _c!['channel'] == 'email' ? context.read<Session>().user['email'] ?? '' : '');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Envoyer un test')),
        content: TextField(controller: to, autofocus: true, decoration: InputDecoration(labelText: trn(_c!['channel'] == 'email' ? 'Adresse e-mail' : 'Numéro'))),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Envoyer')))],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      await _api.post('marketing/campaigns/${widget.id}/test', {'to': to.text.trim()});
      if (mounted) toast(context, tr('Test envoyé à ${to.text.trim()}.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _send() async {
    if (!await _save(quiet: true) || !mounted) return;
    final n = _c!['recipients_estimate'];
    final ab = mkMap(_editor.currentState?.value['ab']);
    DateTime? when;
    final choice = await showDialog<String>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Envoyer la campagne')),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('$n destinataire(s) joignable(s) par ${mkChannelLabels[_c!['channel']]} (désinscrits, non confirmés et doublons exclus).')),
            if (ab['enabled'] == true)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(tr('Test A/B : ${ab['percent'] ?? 20} % reçoivent l\'objet A, ${ab['percent'] ?? 20} % l\'objet B ; le gagnant part au reste après ${ab['wait_hours'] ?? 4} h.'),
                    style: const TextStyle(color: YColors.blue)),
              ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: () async {
                final d = await pickDate(c, firstDate: DateTime.now(), lastDate: DateTime.now().add(const Duration(days: 365)), initialDate: DateTime.now());
                if (d == null || !c.mounted) return;
                final t = await showTimePicker(context: c, initialTime: const TimeOfDay(hour: 9, minute: 0));
                if (t == null) return;
                set(() => when = DateTime(d.year, d.month, d.day, t.hour, t.minute));
              },
              icon: const Icon(Icons.schedule),
              label: Text(tr(when == null ? 'Programmer à une date…' : 'Programmée le ${dateTimeFr(when!.toIso8601String())}')),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
            if (when != null) FilledButton.tonal(onPressed: () => Navigator.pop(c, 'schedule'), child: Text(tr('Programmer'))),
            FilledButton(onPressed: () => Navigator.pop(c, 'now'), child: Text(tr('Envoyer maintenant'))),
          ],
        ),
      ),
    );
    if (choice == null) return;
    await _action('send', choice == 'schedule' ? {'scheduled_at': when!.toIso8601String()} : {}, choice == 'schedule' ? 'Campagne programmée.' : 'Envoi lancé : les messages partent par lots.');
  }

  Future<void> _browserPreview() async {
    final content = _editor.currentState?.value ?? _c!;
    try {
      final r = mkMap(await _api.post('marketing/preview', content));
      await safeLaunchUrl(Uri.parse('${r['url']}'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _attach() async {
    final r = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']);
    if (r.isEmpty) return;
    setState(() => _busy = true);
    try {
      final c = mkMap(await _api.upload('marketing/campaigns/${widget.id}/attachments', 'file', await r.first.readAsBytes(), r.first.name));
      if (mounted) {
        setState(() => _c = {..._c!, 'attachments': c['attachments']});
        _editor.currentState?.setAttachments(mkList(c['attachments']));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = _c;
    final canManage = context.watch<Session>().can('marketing.manage');
    if (c == null) {
      return Scaffold(appBar: AppBar(), body: _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()));
    }
    final wide = MediaQuery.of(context).size.width > 900;
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('${c['name']}')),
        actions: [
          Padding(padding: const EdgeInsets.only(right: 8), child: Center(child: StatusChip('${c['status_label']}', color: mkStatusColors[c['status']] ?? YColors.muted))),
          if (c['channel'] == 'email') IconButton(tooltip: tr('Aperçu dans le navigateur'), onPressed: _browserPreview, icon: const Icon(Icons.open_in_browser)),
          if (canManage && _editable) ...[
            IconButton(tooltip: tr('Enregistrer'), onPressed: _busy ? null : () => _save(), icon: const Icon(Icons.save_outlined)),
            if (wide) TextButton.icon(onPressed: _busy ? null : _test, icon: const Icon(Icons.science_outlined), label: Text(tr('Tester'))) else IconButton(tooltip: tr('Tester'), onPressed: _busy ? null : _test, icon: const Icon(Icons.science_outlined)),
            if (c['status'] == 'scheduled') TextButton(onPressed: _busy ? null : () => _action('cancel', null, 'Programmation annulée.'), child: Text(tr('Déprogrammer'))),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: FilledButton.icon(onPressed: _busy ? null : _send, icon: const Icon(Icons.send), label: Text(tr('Envoyer'))),
            ),
          ],
          if (canManage && !_editable)
            PopupMenuButton<String>(
              onSelected: (v) async {
                if (v == 'cancel') await _action('cancel', null, 'Envoi interrompu.');
                if (v == 'dup') {
                  try {
                    final r = mkMap(await _api.post('marketing/campaigns/${widget.id}/duplicate'));
                    if (!context.mounted) return;
                    Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => CampaignScreen(id: r['id'] as int, overview: widget.overview)));
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                }
              },
              itemBuilder: (_) => [
                if (c['status'] == 'sending') PopupMenuItem(value: 'cancel', child: Text(tr('Interrompre l\'envoi'))),
                PopupMenuItem(value: 'dup', child: Text(tr('Dupliquer pour un nouvel envoi'))),
              ],
            ),
        ],
      ),
      body: _editable && canManage
          ? ContentEditor(
              key: _editor,
              initial: c,
              lists: mkList(widget.overview['lists']),
              segments: mkList(widget.overview['segments']),
              kinds: mkMap(widget.overview['kinds']),
              campaign: true,
              attachments: mkList(c['attachments']),
              onAttach: _attach,
              onDetach: (path) async {
                try {
                  final r = mkMap(await _api.delete('marketing/campaigns/${widget.id}/attachments', {'path': path}));
                  if (mounted) _editor.currentState?.setAttachments(mkList(r['attachments']));
                } catch (e) {
                  if (mounted) toast(this.context, errorText(e), error: true);
                }
              },
              estimate: c['recipients_estimate'],
            )
          : CampaignReport(c: c, onRefresh: _load),
    );
  }
}

// ======================================================================
// Rapport de campagne
// ======================================================================

class CampaignReport extends StatelessWidget {
  const CampaignReport({super.key, required this.c, required this.onRefresh});

  final Map<String, dynamic> c;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final s = mkMap(c['stats']);
    final hint = Theme.of(context).hintColor;
    final sent = (s['sent'] as num?) ?? 0;
    final total = (s['recipients'] as num?) ?? 0;
    final ab = mkMap(c['ab']);
    final variants = mkMap(c['variants']);
    final links = mkList(c['links']);
    final timeline = mkMap(c['opens_timeline']);
    Widget stat(String label, Object? v, {String? sub, Color color = YColors.blue}) => KpiBox(width: 190, child: StatTile(label: label, value: '${v ?? '—'}', detail: sub, color: color));
    Widget bar(String label, num value, num max, Color color, {double labelWidth = 120}) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(children: [
            SizedBox(width: labelWidth, child: Text(tr(label), maxLines: 1, overflow: TextOverflow.ellipsis)),
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(value: max == 0 ? 0 : (value / max).clamp(0, 1).toDouble(), minHeight: 12, color: color, backgroundColor: color.withValues(alpha: .12)),
              ),
            ),
            SizedBox(width: 60, child: Text(tr('$value'), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
          ]),
        );
    final maxT = timeline.values.fold<num>(1, (m, v) => (v as num) > m ? v : m);

    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView(padding: const EdgeInsets.all(24), children: [
        Text(
          tr([
            '${mkChannelLabels[c['channel']]}',
            if (c['subject'] != null && c['channel'] == 'email') '« ${c['subject']} »',
            if (c['started_at'] != null) 'lancée le ${dateTimeFr(c['started_at'])}',
            if (c['sent_at'] != null) 'terminée le ${dateTimeFr(c['sent_at'])}',
          ].join(' · ')),
          style: TextStyle(color: hint),
        ),
        const SizedBox(height: 12),
        if (c['status'] == 'sending')
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(ab['status'] == 'testing'
                  ? 'Test A/B en cours : le gagnant sera envoyé aux ${s['held']} autres destinataires le ${dateTimeFr(ab['decide_at'])}.'
                  : 'Envoi en cours : ${sent + ((s['failed'] as num?) ?? 0)} / $total')),
              const SizedBox(height: 6),
              LinearProgressIndicator(value: total == 0 ? null : (sent + ((s['failed'] as num?) ?? 0)) / total),
            ]),
          ),
        KpiWrap(children: [
          stat('Destinataires', s['recipients']),
          stat('Envoyés', s['sent'], color: YColors.success),
          stat('Échecs', s['failed'], color: YColors.danger),
          if (c['channel'] == 'email') stat('Ouvertures', s['opened'], sub: s['open_rate'] == null ? null : '${s['open_rate']} %'),
          stat('Clics', s['clicked'], sub: s['click_rate'] == null ? null : '${s['click_rate']} %'),
          stat('Désinscriptions', s['unsubscribed'], color: YColors.warning),
        ]),
        const SizedBox(height: 12),
        LayoutBuilder(builder: (context, cons) {
          final cards = <Widget>[
            SectionCard(
              title: tr('Entonnoir'),
              child: Column(children: [
                bar('Envoyés', sent, total, YColors.success),
                if (c['channel'] == 'email') bar('Ouverts', (s['opened'] as num?) ?? 0, total, YColors.blue),
                bar('Cliqués', (s['clicked'] as num?) ?? 0, total, YColors.yellow),
                bar('Désinscrits', (s['unsubscribed'] as num?) ?? 0, total, YColors.danger),
              ]),
            ),
            if (variants.isNotEmpty)
              SectionCard(
                title: tr('Test A/B de l\'objet'),
                trailing: ab['winner'] != null ? StatusChip(tr('Gagnant : ${ab['winner']}'), color: YColors.success) : StatusChip(tr('En cours'), color: YColors.blue),
                child: Column(children: [
                  for (final v in ['A', 'B'])
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: CircleAvatar(radius: 14, backgroundColor: ab['winner'] == v ? YColors.success : YColors.line, child: Text(tr(v), style: TextStyle(color: ab['winner'] == v ? Colors.white : YColors.night))),
                      title: Text(tr('${mkMap(variants[v])['subject']}')),
                      subtitle: Text(tr(() {
                        final x = mkMap(variants[v]);
                        final n = (x['sent'] as num?) ?? 0;
                        final pct = n == 0 ? 0 : ((x['opened'] as num) * 100 / n);
                        return '${x['sent']} envoyés · ${x['opened']} ouvertures (${pct.toStringAsFixed(1)} %) · ${x['clicked']} clics';
                      }())),
                    ),
                ]),
              ),
          ];
          if (cons.maxWidth > 900 && cards.length == 2) {
            return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: cards[0]), const SizedBox(width: 12), Expanded(child: cards[1])]);
          }
          return Column(children: [for (final w in cards) Padding(padding: const EdgeInsets.only(bottom: 12), child: w)]);
        }),
        if (timeline.isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Ouvertures heure par heure (depuis l\'envoi)'),
            child: SizedBox(
              height: 120,
              child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                for (final e in timeline.entries)
                  Expanded(
                    child: Tooltip(
                      message: tr('${e.key.substring(8, 10)}/${e.key.substring(5, 7)} ${e.key.substring(11, 13)} h : ${e.value} ouverture(s)'),
                      child: Align(
                        alignment: Alignment.bottomCenter,
                        child: Container(
                          constraints: const BoxConstraints(maxWidth: 22),
                          margin: const EdgeInsets.symmetric(horizontal: 1),
                          height: 2 + 104 * ((e.value as num) / maxT),
                          decoration: BoxDecoration(color: (e.value as num) == 0 ? YColors.line : YColors.blue, borderRadius: BorderRadius.circular(3)),
                        ),
                      ),
                    ),
                  ),
              ]),
            ),
          ),
        ],
        if (links.isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Carte des clics'),
            child: Column(children: [
              for (final l in links)
                bar('${l['url']}'.replaceFirst(RegExp(r'^https?://'), ''), (l['clicks'] as num), (links.first['clicks'] as num), YColors.yellow, labelWidth: 260),
              Align(alignment: Alignment.centerLeft, child: Text(tr('Nombre total de clics par lien (les personnes uniques sont comptées dans « Clics »).'), style: TextStyle(fontSize: 12, color: hint))),
            ]),
          ),
        ],
        if ((c['failures'] as List? ?? const []).isNotEmpty) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Échecs (extrait)'),
            child: Column(children: [
              for (final f in mkList(c['failures'])) ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${f['to']}')), subtitle: Text(tr('${f['error'] ?? ''}'))),
            ]),
          ),
        ],
        const SizedBox(height: 12),
        SectionCard(title: tr('Contenu envoyé'), child: SizedBox(height: 560, child: EmailPreview(content: c))),
      ]),
    );
  }
}

// ======================================================================
// Éditeur de contenu (campagnes et modèles)
// ======================================================================

class ContentEditor extends StatefulWidget {
  const ContentEditor({
    super.key,
    required this.initial,
    this.lists = const [],
    this.segments = const [],
    this.kinds = const {},
    this.campaign = false,
    this.attachments = const [],
    this.onAttach,
    this.onDetach,
    this.estimate,
  });

  final Map<String, dynamic> initial;
  final List<Map<String, dynamic>> lists;
  final List<Map<String, dynamic>> segments;
  final Map<String, dynamic> kinds;
  final bool campaign;
  final List<Map<String, dynamic>> attachments;
  final VoidCallback? onAttach;
  final void Function(String path)? onDetach;
  final Object? estimate;

  @override
  State<ContentEditor> createState() => ContentEditorState();
}

class ContentEditorState extends State<ContentEditor> with SingleTickerProviderStateMixin {
  late final _name = TextEditingController(text: '${widget.initial['name'] ?? ''}');
  late final _subject = TextEditingController(text: '${widget.initial['subject'] ?? ''}');
  late final _preheader = TextEditingController(text: '${widget.initial['preheader'] ?? ''}');
  late final _text = TextEditingController(text: '${widget.initial['text'] ?? ''}');
  late final _subjectB = TextEditingController(text: '${mkMap(widget.initial['ab'])['subject_b'] ?? ''}');
  late String _kind = '${widget.initial['kind'] ?? 'newsletter'}';
  late final Set<int> _lists = {for (final l in (widget.initial['list_ids'] as List? ?? const [])) (l as num).toInt()};
  late final Set<int> _segments = {for (final l in (widget.initial['segment_ids'] as List? ?? const [])) (l as num).toInt()};
  late final EmailDesign design = EmailDesign.from(widget.initial);
  late Map<String, dynamic> _ab = {'enabled': false, 'percent': 20, 'wait_hours': 4, 'metric': 'open', ...mkMap(widget.initial['ab'])};
  late List<Map<String, dynamic>> _attachments = widget.attachments;
  late Object? _estimate = widget.estimate;
  late final TabController _tabs = TabController(length: 2, vsync: this, initialIndex: channel == 'email' ? 1 : 0);

  String get channel => '${widget.initial['channel'] ?? 'email'}';

  Map<String, dynamic> get value => {
        'name': _name.text.trim().isEmpty ? '${widget.initial['name']}' : _name.text.trim(),
        if (widget.campaign) 'kind': _kind,
        if (widget.campaign) 'list_ids': _lists.toList(),
        if (widget.campaign) 'segment_ids': _segments.toList(),
        if (widget.campaign && channel == 'email') 'ab': {..._ab, 'subject_b': _subjectB.text.trim()},
        'subject': _subject.text.trim(),
        'preheader': _preheader.text.trim(),
        'text': _text.text,
        ...design.toJson(),
        'channel': channel,
      };

  void updateEstimate(Object? n) => setState(() => _estimate = n);
  void setAttachments(List<Map<String, dynamic>> a) => setState(() => _attachments = a);

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (channel != 'email') {
      final wide = MediaQuery.of(context).size.width > 1000;
      final settings = _settings(context);
      final preview = PhonePreview(channel: channel, text: fillVars(_text.text, '${context.read<Session>().company['name'] ?? ''}'));
      if (wide) return Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [SizedBox(width: 460, child: settings), const VerticalDivider(width: 1), Expanded(child: preview)]);
      return Column(children: [
        TabBar(controller: _tabs, tabs: [Tab(text: tr('Message')), Tab(text: tr('Aperçu'))]),
        Expanded(child: TabBarView(controller: _tabs, children: [settings, preview])),
      ]);
    }
    return Column(children: [
      TabBar(controller: _tabs, tabs: [Tab(icon: Icon(Icons.tune), text: tr('Paramètres')), Tab(icon: Icon(Icons.dashboard_customize_outlined), text: tr('Conception'))]),
      Expanded(
        child: TabBarView(controller: _tabs, physics: const NeverScrollableScrollPhysics(), children: [
          Center(child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 760), child: _settings(context))),
          EmailBuilder(design: design, subject: _subject.text, preheader: _preheader.text, onChanged: () => setState(() {})),
        ]),
      ),
    ]);
  }

  Widget _settings(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Field(label: tr('Nom'), child: TextField(controller: _name)),
      if (widget.campaign) ...[
        Field(
          label: tr('Type'),
          child: DropdownButtonFormField<String>(
            initialValue: widget.kinds.containsKey(_kind) ? _kind : null,
            isExpanded: true,
            items: [for (final k in widget.kinds.entries) DropdownMenuItem(value: k.key, child: Text(tr('${k.value}')))],
            onChanged: (v) => setState(() => _kind = v ?? _kind),
          ),
        ),
        Field(
          label: tr('Listes de destinataires'),
          child: widget.lists.isEmpty
              ? Text(tr('Aucune liste : créez-en une dans « Contacts ».'), style: TextStyle(color: hint))
              : Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final l in widget.lists)
                    FilterChip(
                      label: Text(tr('${l['name']} (${l['contacts_count'] ?? 0})')),
                      selected: _lists.contains(l['id']),
                      onSelected: (v) => setState(() => v ? _lists.add(l['id'] as int) : _lists.remove(l['id'])),
                    ),
                ]),
        ),
        if (widget.segments.isNotEmpty)
          Field(
            label: tr('Segments (ajoutés aux listes)'),
            child: Wrap(spacing: 8, runSpacing: 8, children: [
              for (final s in widget.segments)
                FilterChip(
                  avatar: const Icon(Icons.filter_alt_outlined, size: 16),
                  label: Text(tr('${s['name']}')),
                  selected: _segments.contains(s['id']),
                  onSelected: (v) => setState(() => v ? _segments.add(s['id'] as int) : _segments.remove(s['id'])),
                ),
            ]),
          ),
        if (_estimate != null) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(tr('Destinataires estimés : $_estimate (après enregistrement)'), style: const TextStyle(fontWeight: FontWeight.w600))),
      ],
      if (channel == 'email') ...[
        Field(label: tr('Objet'), child: TextField(controller: _subject, onChanged: (_) => setState(() {}))),
        Field(label: tr('Texte d\'aperçu (affiché après l\'objet)'), child: TextField(controller: _preheader, onChanged: (_) => setState(() {}))),
        VarsBar(onInsert: (v) => setState(() => insertVar(_subject, v))),
        if (widget.campaign) ...[
          const SizedBox(height: 16),
          _abCard(context),
          const SizedBox(height: 16),
          Row(children: [
            Expanded(child: Text(tr('Pièces jointes (proposition, catalogue…)'), style: TextStyle(fontWeight: FontWeight.w700))),
            TextButton.icon(onPressed: widget.onAttach, icon: const Icon(Icons.attach_file), label: Text(tr('Joindre'))),
          ]),
          for (final a in _attachments)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.insert_drive_file_outlined),
              title: Text(tr('${a['name']}')),
              subtitle: Text(tr('${((a['size'] as num? ?? 0) / 1024).round()} Ko')),
              trailing: IconButton(onPressed: () => widget.onDetach?.call('${a['path']}'), icon: const Icon(Icons.close)),
            ),
        ],
        const SizedBox(height: 12),
        OutlinedButton.icon(onPressed: () => _tabs.animateTo(1), icon: const Icon(Icons.dashboard_customize_outlined), label: Text(tr('Concevoir le contenu'))),
      ] else ...[
        Field(
          label: channel == 'sms' ? 'Message SMS' : 'Message WhatsApp',
          child: TextField(controller: _text, maxLines: 8, minLines: 4, maxLength: 2000, onChanged: (_) => setState(() {})),
        ),
        VarsBar(onInsert: (v) => setState(() => insertVar(_text, v))),
        if (channel == 'sms') Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr(smsInfo(_text.text)), style: TextStyle(color: hint, fontSize: 12))),
        if (channel == 'whatsapp')
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(tr('Avec l\'API officielle de Meta, un modèle approuvé est nécessaire pour écrire aux contacts qui ne vous ont pas écrit depuis 24 h (à indiquer dans les canaux d\'envoi).'), style: TextStyle(color: hint, fontSize: 12)),
          ),
      ],
    ]);
  }

  Widget _abCard(BuildContext context) {
    final on = _ab['enabled'] == true;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            secondary: const Icon(Icons.science_outlined, color: YColors.blue),
            title: Text(tr('Test A/B de l\'objet'), style: TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(tr('Deux objets testés sur une partie de la liste ; le meilleur part automatiquement au reste.')),
            value: on,
            onChanged: (v) => setState(() => _ab = {..._ab, 'enabled': v}),
          ),
          if (on) ...[
            TextField(controller: _subjectB, decoration: InputDecoration(labelText: tr('Objet B'))),
            const SizedBox(height: 12),
            Row(children: [
              SizedBox(width: 150, child: Text(tr('Groupe test'))),
              Expanded(
                child: Slider(
                  value: ((_ab['percent'] as num?) ?? 20).toDouble(),
                  min: 5,
                  max: 50,
                  divisions: 9,
                  label: '${_ab['percent']} % + ${_ab['percent']} %',
                  onChanged: (v) => setState(() => _ab = {..._ab, 'percent': v.round()}),
                ),
              ),
              Text(tr('${_ab['percent']} % × 2')),
            ]),
            Row(children: [
              SizedBox(width: 150, child: Text(tr('Durée du test'))),
              Expanded(
                child: Slider(
                  value: ((_ab['wait_hours'] as num?) ?? 4).toDouble(),
                  min: 1,
                  max: 48,
                  divisions: 47,
                  label: '${_ab['wait_hours']} h',
                  onChanged: (v) => setState(() => _ab = {..._ab, 'wait_hours': v.round()}),
                ),
              ),
              Text(tr('${_ab['wait_hours']} h')),
            ]),
            Row(children: [
              SizedBox(width: 150, child: Text(tr('Critère'))),
              SegmentedButton<String>(
                segments: [ButtonSegment(value: 'open', label: Text(tr('Ouvertures'))), ButtonSegment(value: 'click', label: Text(tr('Clics')))],
                selected: {'${_ab['metric'] ?? 'open'}'},
                onSelectionChanged: (s) => setState(() => _ab = {..._ab, 'metric': s.first}),
              ),
            ]),
          ],
        ]),
      ),
    );
  }
}

String smsInfo(String t) {
  final gsm = RegExp(r'^[\x20-\x7E\n\r€£¥èéùìòÇØøÅåÄÖÑÜ§äöñüà]*$').hasMatch(t);
  final per = gsm ? 160 : 70;
  final multi = gsm ? 153 : 67;
  final n = t.length <= per ? 1 : (t.length / multi).ceil();
  return '${t.length} caractères · $n SMS par destinataire${gsm ? '' : ' (accents ou emojis : 70 caractères par SMS)'} · un lien STOP est ajouté aux campagnes promotionnelles.';
}
