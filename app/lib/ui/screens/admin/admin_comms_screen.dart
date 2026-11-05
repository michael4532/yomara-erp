import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/mail_html.dart';
import '../marketing/email_builder.dart';
import 'admin_common.dart';
import 'admin_promo_screen.dart';

/// Super admin › Communication clients : campagnes (notification dans l'application et / ou e-mail ;
/// texte, design par blocs ou HTML ; immédiates, programmées ou récurrentes) et relances automatiques.
class AdminCommsScreen extends StatefulWidget {
  const AdminCommsScreen({super.key});

  @override
  State<AdminCommsScreen> createState() => _AdminCommsScreenState();
}

const _statusColors = {'draft': YColors.muted, 'scheduled': YColors.warning, 'sending': YColors.blue, 'sent': YColors.success, 'paused': YColors.danger};
const _freqLabels = {'daily': 'Chaque jour', 'weekly': 'Chaque semaine', 'monthly': 'Chaque mois'};
const _weekdays = {1: 'lundi', 2: 'mardi', 3: 'mercredi', 4: 'jeudi', 5: 'vendredi', 6: 'samedi', 7: 'dimanche'};

List<Map<String, dynamic>> _list(dynamic v) => v is List ? v.map((e) => asMap(e)).toList() : <Map<String, dynamic>>[];

String _schedule(Map<String, dynamic> c) {
  final r = asMap(c['recurrence']);
  return switch ('${c['schedule']}') {
    'once' => 'Le ${dateTimeFr(c['send_at'])}',
    'recurring' => '${_freqLabels[r['freq']] ?? 'Récurrente'}${r['freq'] == 'weekly' ? ' (${_weekdays[toInt(r['weekday'] ?? 1)]})' : ''}${r['freq'] == 'monthly' ? ' (le ${r['day'] ?? 1})' : ''} à ${r['time'] ?? '09:00'}',
    _ => 'Envoi immédiat',
  };
}

int toInt(Object? v) => v is int ? v : int.tryParse('$v') ?? 0;

class _AdminCommsScreenState extends State<AdminCommsScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 2, vsync: this);
  Key _key = UniqueKey();

  void _reload() => setState(() => _key = UniqueKey());

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _compose(Map<String, dynamic> meta, {Map<String, dynamic>? campaign, Map<String, dynamic>? automation}) async {
    final changed = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => _Composer(meta: meta, campaign: campaign, automation: automation)));
    if (changed == true) _reload();
  }

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      key: _key,
      path: 'admin/comms',
      builder: (context, raw, reload) {
        final d = asMap(raw);
        final campaigns = _list(d['campaigns']);
        final autos = _list(d['automations']);
        final pad = MediaQuery.sizeOf(context).width < 700 ? 16.0 : 24.0;
        final sent = campaigns.fold<int>(0, (a, c) => a + toInt(asMap(c['stats'])['sent']));
        final opened = campaigns.fold<int>(0, (a, c) => a + toInt(asMap(c['stats'])['opened']));
        return Column(children: [
          PageHeader(
            title: tr('Marketing : e-mails à vos clients'),
            subtitle: tr('Annonces, newsletters, promotions et relances vers vos sociétés clientes : e-mail et notification dans l\'application'),
            icon: Icons.campaign_outlined,
            actions: [
              IconButton.outlined(tooltip: tr('Actualiser'), onPressed: _reload, icon: const Icon(Icons.refresh)),
              OutlinedButton.icon(
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => Scaffold(appBar: AppBar(title: Text(tr('Promotions'))), body: const AdminPromoScreen()))),
                icon: const Icon(Icons.local_offer_outlined),
                label: Text(tr('Promotions')),
              ),
              FilledButton.icon(onPressed: () => _compose(d), icon: const Icon(Icons.add), label: Text(tr('Nouvelle campagne'))),
            ],
          ),
          if (d['mail_ready'] != true)
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
              child: _Banner(
                icon: Icons.mark_email_unread_outlined,
                color: YColors.warning,
                text: 'Aucun serveur d\'e-mails n\'est configuré : les notifications partent, mais pas les e-mails. Réglez-le dans « Ventes de modules › Serveur e-mail ».',
              ),
            ),
          StatStrip(padding: EdgeInsets.fromLTRB(pad, 0, pad, 12), tiles: [
            StatTile(label: tr('Sociétés clientes'), value: number(toInt(d['clients'])), icon: Icons.business_outlined),
            StatTile(label: tr('Campagnes'), value: number(campaigns.length), detail: tr('${campaigns.where((c) => c['status'] == 'scheduled').length} programmée(s)'), icon: Icons.campaign_outlined, color: YColors.warning),
            StatTile(label: tr('E-mails envoyés'), value: number(sent), detail: sent > 0 ? tr('${(opened * 100 / sent).round()} % ouverts') : null, icon: Icons.mark_email_read_outlined, color: YColors.success),
            StatTile(label: tr('Relances actives'), value: '${autos.where((a) => a['is_active'] == true).length} / ${autos.length}', icon: Icons.autorenew_rounded, color: YColors.blue),
            StatTile(label: tr('Désinscrits'), value: number(toInt(d['unsubscribed'])), icon: Icons.unsubscribe_outlined, color: YColors.muted),
          ]),
          TabBar(
            controller: _tabs,
            isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
            tabAlignment: TabAlignment.start,
            tabs: [Tab(text: tr('Campagnes (${campaigns.length})')), Tab(text: tr('Relances automatiques'))],
          ),
          Expanded(
            child: TabBarView(controller: _tabs, children: [
              _CampaignList(campaigns: campaigns, pad: pad, onCompose: (c) => _compose(d, campaign: c), onNew: () => _compose(d), onChanged: _reload),
              _AutomationList(autos: autos, pad: pad, onEdit: (a) => _compose(d, automation: a), onChanged: _reload),
            ]),
          ),
        ]);
      },
    );
  }
}

/// Envoyer une promotion (code promo) par e-mail et notification : campagne pré-remplie, à relire avant l'envoi.
Future<void> openPromoCampaign(BuildContext context, Map<String, dynamic> promo, String valueLabel) async {
  final api = context.read<Session>().api;
  try {
    final meta = asMap(await api.get('admin/comms'));
    if (!context.mounted) return;
    final code = '${promo['code']}';
    final until = DateTime.tryParse('${promo['valid_until'] ?? ''}');
    final untilTxt = until == null ? '' : ' jusqu\'au ${dateFr(promo['valid_until'])}';
    final desc = '${promo['description'] ?? ''}'.trim();
    final draft = <String, dynamic>{
      'name': 'Promotion $code',
      'channels': ['email', 'notification'],
      'format': 'text',
      'subject': '🎁 $valueLabel avec le code $code',
      'preheader': 'Offre réservée à nos clients$untilTxt.',
      'title': '$valueLabel sur vos modules',
      'body_text': 'Bonjour {{prenom}},\n\n'
          '${desc.isNotEmpty ? '$desc\n\n' : ''}'
          'Profitez de **$valueLabel** avec le code **$code**$untilTxt.\n\n'
          'Ouvrez la boutique de modules de {{entreprise}}, choisissez ce qui vous fait gagner du temps et saisissez le code au paiement.',
      'cta_label': 'Profiter de l\'offre',
      'cta_url': '{{lien_boutique}}',
      'audience': {'segment': 'all', 'recipients': 'admins'},
      'schedule': 'now',
    };
    await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => _Composer(meta: meta, campaign: draft)));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.icon, required this.color, required this.text});

  final IconData icon;
  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(12), border: Border.all(color: color.withValues(alpha: .35))),
        child: Row(children: [
          Icon(icon, color: color),
          const SizedBox(width: 10),
          Expanded(child: Text(tr(text), style: const TextStyle(fontSize: 13.5, height: 1.35))),
        ]),
      );
}

// ---------------------------------------------------------------------------
// Campagnes
// ---------------------------------------------------------------------------

class _CampaignList extends StatelessWidget {
  const _CampaignList({required this.campaigns, required this.pad, required this.onCompose, required this.onNew, required this.onChanged});

  final List<Map<String, dynamic>> campaigns;
  final double pad;
  final void Function(Map<String, dynamic>) onCompose;
  final VoidCallback onNew;
  final VoidCallback onChanged;

  Future<void> _act(BuildContext context, String path, String done) async {
    try {
      await context.read<Session>().api.post(path, {});
      if (context.mounted) toast(context, tr(done));
      onChanged();
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (campaigns.isEmpty) {
      return ListView(children: [
        const SizedBox(height: 30),
        EmptyState(
          icon: Icons.campaign_outlined,
          title: 'Aucune campagne pour l\'instant',
          message: 'Annoncez une promotion, une nouveauté ou une astuce à tous vos clients, ou à un groupe précis (formule gratuite, essais, abonnés…).',
          action: FilledButton.icon(onPressed: onNew, icon: const Icon(Icons.add), label: Text(tr('Créer une campagne'))),
        ),
      ]);
    }
    return ListView.separated(
      padding: EdgeInsets.fromLTRB(pad, 14, pad, 40),
      itemCount: campaigns.length,
      separatorBuilder: (_, _) => const SizedBox(height: 10),
      itemBuilder: (context, i) {
        final c = campaigns[i];
        final st = asMap(c['stats']);
        final status = '${c['status']}';
        final hint = Theme.of(context).hintColor;
        final channels = List<String>.from(c['channels'] as List? ?? const []);
        final emails = toInt(st['sent']);
        final editable = !['sending', 'sent'].contains(status) || c['schedule'] == 'recurring';
        return AnimatedFadeIn(
          index: i.clamp(0, 12),
          child: Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(14),
              onTap: () => showDialog<void>(context: context, builder: (_) => _CampaignStats(id: toInt(c['id']), name: '${c['name']}')),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(child: Text(tr('${c['name']}'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
                    StatusChip(tr('${c['status_label'] ?? status}'), color: _statusColors[status] ?? YColors.muted),
                    PopupMenuButton<String>(
                      onSelected: (v) => switch (v) {
                        'edit' => onCompose(c),
                        'dup' => _act(context, 'admin/comms/campaigns/${c['id']}/duplicate', 'Campagne dupliquée : modifiez la copie puis envoyez-la.'),
                        'pause' => _act(context, 'admin/comms/campaigns/${c['id']}/pause', 'Campagne en pause.'),
                        'resume' => _act(context, 'admin/comms/campaigns/${c['id']}/resume', 'Campagne relancée.'),
                        'delete' => () async {
                          if (await confirm(context, 'Supprimer la campagne ?', 'Les envois déjà faits restent dans les statistiques des sociétés.', ok: 'Supprimer', danger: true) && context.mounted) {
                            try {
                              await context.read<Session>().api.delete('admin/comms/campaigns/${c['id']}');
                              onChanged();
                            } catch (e) {
                              if (context.mounted) toast(context, errorText(e), error: true);
                            }
                          }
                        }(),
                        _ => null,
                      },
                      itemBuilder: (_) => [
                        if (editable) PopupMenuItem(value: 'edit', child: Text(tr('Modifier'))),
                        PopupMenuItem(value: 'dup', child: Text(tr('Dupliquer'))),
                        if (status == 'scheduled' || status == 'sending') PopupMenuItem(value: 'pause', child: Text(tr('Mettre en pause'))),
                        if (status == 'paused') PopupMenuItem(value: 'resume', child: Text(tr('Reprendre'))),
                        PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'), style: const TextStyle(color: YColors.danger))),
                      ],
                    ),
                  ]),
                  const SizedBox(height: 4),
                  Wrap(spacing: 14, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    _Meta(Icons.groups_2_outlined, '${c['segment_label'] ?? ''}${asMap(c['audience'])['recipients'] == 'all' ? ' · tous les utilisateurs' : ' · administrateurs'}'),
                    _Meta(Icons.schedule_rounded, _schedule(c) + (status == 'scheduled' && c['next_run_at'] != null ? ' · prochain envoi ${dateTimeFr(c['next_run_at'])}' : '')),
                    if (channels.contains('notification')) const _Meta(Icons.notifications_active_outlined, 'Notification'),
                    if (channels.contains('email')) _Meta(Icons.alternate_email_rounded, 'E-mail${c['format'] == 'html' ? ' HTML' : c['format'] == 'design' ? ' (design)' : ''}'),
                  ]),
                  if (st.isNotEmpty && toInt(st['companies']) > 0) ...[
                    const SizedBox(height: 12),
                    Wrap(spacing: 18, runSpacing: 6, children: [
                      _Num('${st['companies']}', 'sociétés'),
                      if (channels.contains('notification')) _Num('${st['notified']}', 'notifiés'),
                      if (channels.contains('email')) ...[
                        _Num('$emails', 'e-mails envoyés'),
                        if (toInt(st['pending']) > 0) _Num('${st['pending']}', 'en attente', color: YColors.warning),
                        _Num(emails > 0 ? '${(toInt(st['opened']) * 100 / emails).round()} %' : '—', 'ouverts', color: YColors.success),
                        _Num(emails > 0 ? '${(toInt(st['clicked']) * 100 / emails).round()} %' : '—', 'clics', color: YColors.blue),
                        if (toInt(st['failed']) > 0) _Num('${st['failed']}', 'échecs', color: YColors.danger),
                      ],
                    ]),
                  ] else if (status == 'draft')
                    Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Brouillon : ouvrez-la pour l\'envoyer.'), style: TextStyle(color: hint, fontSize: 12.5))),
                ]),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta(this.icon, this.text);

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 15, color: hint), const SizedBox(width: 4), Text(tr(text), style: TextStyle(color: hint, fontSize: 12.5))]);
  }
}

class _Num extends StatelessWidget {
  const _Num(this.value, this.label, {this.color});

  final String value;
  final String label;
  final Color? color;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        Text(value, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: color)),
        Text(tr(label), style: TextStyle(fontSize: 11.5, color: Theme.of(context).hintColor)),
      ]);
}

class _CampaignStats extends StatelessWidget {
  const _CampaignStats({required this.id, required this.name});

  final int id;
  final String name;

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    return YAlertDialog(
      icon: const Icon(Icons.insights_rounded),
      title: Text(tr(name)),
      subtitle: tr('Détail des envois par société'),
      content: SizedBox(
        width: 620,
        height: 440,
        child: FutureBuilder<dynamic>(
          future: api.get('admin/comms/campaigns/$id'),
          builder: (c, snap) {
            if (snap.hasError) return Center(child: Text(errorText(snap.error!), style: const TextStyle(color: YColors.danger)));
            if (!snap.hasData) return const LoadingSkeleton(lines: 6);
            final rows = _list(asMap(snap.data)['deliveries']);
            if (rows.isEmpty) return const EmptyState(icon: Icons.hourglass_empty_rounded, title: 'Pas encore envoyée', message: 'Le détail apparaîtra au premier envoi.');
            final hint = Theme.of(c).hintColor;
            return ListView.separated(
              itemCount: rows.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (_, i) {
                final r = rows[i];
                final email = r['channel'] == 'email';
                final status = '${r['status']}';
                final (label, color) = switch (status) {
                  'sent' when r['clicked_at'] != null => ('Cliqué', YColors.blue),
                  'sent' when r['opened_at'] != null => ('Ouvert', YColors.success),
                  'sent' => (email ? 'Envoyé' : 'Notifié', YColors.success),
                  'pending' => ('En attente', YColors.warning),
                  'skipped' => ('${r['error'] ?? 'Ignoré'}', YColors.muted),
                  _ => ('Échec', YColors.danger),
                };
                return ListTile(
                  dense: true,
                  leading: Icon(email ? Icons.alternate_email_rounded : Icons.notifications_active_outlined, size: 20, color: hint),
                  title: Text(tr('${r['company']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text(tr([if (email) '${r['email']}', if (r['sent_at'] != null) dateTimeFr(r['sent_at']), if (status == 'failed') '${r['error'] ?? ''}'].join(' · '))),
                  trailing: StatusChip(tr(label), color: color),
                );
              },
            );
          },
        ),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

// ---------------------------------------------------------------------------
// Relances automatiques
// ---------------------------------------------------------------------------

class _AutomationList extends StatelessWidget {
  const _AutomationList({required this.autos, required this.pad, required this.onEdit, required this.onChanged});

  final List<Map<String, dynamic>> autos;
  final double pad;
  final void Function(Map<String, dynamic>) onEdit;
  final VoidCallback onChanged;

  Future<void> _toggle(BuildContext context, Map<String, dynamic> a, bool v) async {
    try {
      await context.read<Session>().api.post('admin/comms/automations/${a['id']}/toggle', {'is_active': v});
      if (context.mounted) toast(context, tr(v ? 'Relance activée : elle part à chaque société concernée, une seule fois par occasion.' : 'Relance désactivée.'));
      onChanged();
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _targets(BuildContext context, Map<String, dynamic> a) async {
    final api = context.read<Session>().api;
    await showDialog<void>(
      context: context,
      builder: (c) => YAlertDialog(
        icon: const Icon(Icons.groups_2_outlined),
        title: Text(tr('Qui la recevrait maintenant ?')),
        subtitle: tr('${a['name']}'),
        content: SizedBox(
          width: 460,
          height: 300,
          child: FutureBuilder<dynamic>(
            future: api.get('admin/comms/automations/${a['id']}/targets'),
            builder: (c, snap) {
              if (snap.hasError) return Text(errorText(snap.error!));
              if (!snap.hasData) return const LoadingSkeleton(lines: 4);
              final d = asMap(snap.data);
              final rows = _list(d['sample']);
              if (rows.isEmpty) return const EmptyState(icon: Icons.check_circle_outline, title: 'Personne pour l\'instant', message: 'Aucune société n\'est concernée aujourd\'hui (ou elles l\'ont déjà reçue).');
              return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${d['count']} société(s) au prochain passage (toutes les heures, si la relance est active).'), style: const TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 8),
                Expanded(child: ListView(children: [for (final r in rows) ListTile(dense: true, leading: InitialsAvatar('${r['name']}', size: 30), title: Text(tr('${r['name']}')), subtitle: Text(tr('${r['plan'] ?? ''}')))])),
              ]);
            },
          ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return ListView(
      padding: EdgeInsets.fromLTRB(pad, 14, pad, 40),
      children: [
        _Banner(
          icon: Icons.tips_and_updates_outlined,
          color: YColors.blue,
          text: 'Chaque relance part automatiquement aux sociétés concernées, une seule fois par occasion (une fin d\'essai donnée, une échéance…). Elles sont proposées désactivées : relisez le message, puis activez-les.',
        ),
        const SizedBox(height: 12),
        for (var i = 0; i < autos.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: AnimatedFadeIn(
              index: i.clamp(0, 10),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
                  child: Row(children: [
                    Container(
                      width: 42,
                      height: 42,
                      decoration: BoxDecoration(color: (autos[i]['is_active'] == true ? YColors.success : YColors.muted).withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
                      child: Icon(Icons.autorenew_rounded, color: autos[i]['is_active'] == true ? YColors.success : YColors.muted),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${autos[i]['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                        const SizedBox(height: 2),
                        Text(tr('${autos[i]['days']} ${autos[i]['days_label']} · « ${autos[i]['subject'] ?? autos[i]['title'] ?? ''} »'), style: TextStyle(color: hint, fontSize: 12.5), maxLines: 2, overflow: TextOverflow.ellipsis),
                        const SizedBox(height: 4),
                        Text(tr('${autos[i]['sent']} société(s) relancée(s) · ${autos[i]['emails']} e-mail(s) · ${autos[i]['opened']} ouvert(s)'), style: TextStyle(color: hint, fontSize: 12)),
                      ]),
                    ),
                    IconButton(tooltip: tr('Qui la recevrait ?'), onPressed: () => _targets(context, autos[i]), icon: const Icon(Icons.groups_2_outlined)),
                    IconButton(tooltip: tr('Modifier le message'), onPressed: () => onEdit(autos[i]), icon: const Icon(Icons.edit_outlined)),
                    Switch(value: autos[i]['is_active'] == true, onChanged: (v) => _toggle(context, autos[i], v)),
                  ]),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Éditeur : campagne ou relance
// ---------------------------------------------------------------------------

class _Composer extends StatefulWidget {
  const _Composer({required this.meta, this.campaign, this.automation});

  final Map<String, dynamic> meta;
  final Map<String, dynamic>? campaign;
  final Map<String, dynamic>? automation;

  @override
  State<_Composer> createState() => _ComposerState();
}

class _ComposerState extends State<_Composer> with SingleTickerProviderStateMixin {
  late final Map<String, dynamic> _src = widget.campaign ?? widget.automation ?? {};
  bool get _isAuto => widget.automation != null;

  late final _name = TextEditingController(text: '${_src['name'] ?? ''}');
  late final _subject = TextEditingController(text: '${_src['subject'] ?? ''}');
  late final _preheader = TextEditingController(text: '${_src['preheader'] ?? ''}');
  late final _title = TextEditingController(text: '${_src['title'] ?? ''}');
  late final _body = TextEditingController(text: '${_src['body_text'] ?? ''}');
  late final _html = TextEditingController(text: '${_src['html'] ?? ''}');
  late final _ctaLabel = TextEditingController(text: '${_src['cta_label'] ?? ''}');
  late final _ctaUrl = TextEditingController(text: '${_src['cta_url'] ?? ''}');
  late final _days = TextEditingController(text: '${_src['days'] ?? asMap(_src['audience'])['days'] ?? 7}');
  late final EmailDesign _design = EmailDesign.from({'blocks': _src['blocks'] ?? const [], 'style': _src['style'] ?? const {}});

  late final Set<String> _channels = {...List<String>.from(_src['channels'] as List? ?? const ['notification', 'email'])};
  late String _format = '${_src['format'] ?? 'text'}';
  late Map<String, dynamic> _audience = {'segment': 'all', 'recipients': 'admins', ...asMap(_src['audience'])};
  late String _recipients = '${_src['recipients'] ?? _audience['recipients'] ?? 'admins'}';
  late String _scheduleMode = '${_src['schedule'] ?? 'now'}';
  late DateTime? _sendAt = DateTime.tryParse('${_src['send_at'] ?? ''}')?.toLocal();
  late Map<String, dynamic> _recurrence = {'freq': 'weekly', 'weekday': 1, 'day': 1, 'time': '09:00', ...asMap(_src['recurrence'])};
  late bool _active = _src['is_active'] == true;

  Map<String, dynamic>? _count;
  Timer? _debounce;
  bool _busy = false;
  late final TabController _tabs = TabController(length: 2, vsync: this);

  @override
  void initState() {
    super.initState();
    if (!_isAuto) _refreshCount();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _tabs.dispose();
    for (final c in [_name, _subject, _preheader, _title, _body, _html, _ctaLabel, _ctaUrl, _days]) {
      c.dispose();
    }
    super.dispose();
  }

  void _refreshCount() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () async {
      try {
        final r = asMap(await context.read<Session>().api.post('admin/comms/audience', _audiencePayload()));
        if (mounted) setState(() => _count = r);
      } catch (_) {}
    });
  }

  Map<String, dynamic> _audiencePayload() => {
        'segment': _audience['segment'],
        if (_audience['segment'] == 'plans') 'plans': List<String>.from(_audience['plans'] as List? ?? const []),
        if (['expiring', 'inactive', 'new'].contains(_audience['segment'])) 'days': int.tryParse(_days.text) ?? 7,
        if (_audience['segment'] == 'companies') 'company_ids': List<int>.from((_audience['company_ids'] as List? ?? const []).map(toInt)),
        'recipients': _recipients,
      };

  /// Contenu commun (campagne ou relance).
  Map<String, dynamic> _content() {
    var format = _format;
    var html = _html.text;
    if (format == 'design' && _design.htmlMode) {
      format = 'html';
      html = _design.html;
    }
    return {
      'channels': _channels.toList(),
      'subject': _subject.text.trim(),
      'preheader': _preheader.text.trim(),
      'title': _title.text.trim(),
      'body_text': _body.text,
      'format': format,
      'blocks': format == 'design' ? _design.blocks.map((b) => Map<String, dynamic>.from(b)..remove('_id')).toList() : null,
      'style': format == 'design' ? _design.style : null,
      'html': format == 'html' ? html : null,
      'cta_label': _ctaLabel.text.trim(),
      'cta_url': _ctaUrl.text.trim(),
    };
  }

  Map<String, dynamic> _payload() {
    if (_isAuto) {
      return {..._content(), 'name': _name.text.trim(), 'days': int.tryParse(_days.text) ?? 0, 'recipients': _recipients, 'is_active': _active};
    }
    return {
      ..._content(),
      'name': _name.text.trim().isEmpty ? (_subject.text.trim().isEmpty ? 'Campagne' : _subject.text.trim()) : _name.text.trim(),
      'audience': _audiencePayload(),
      'schedule': _scheduleMode,
      if (_scheduleMode == 'once' && _sendAt != null) 'send_at': _sendAt!.toUtc().toIso8601String(),
      if (_scheduleMode == 'recurring') 'recurrence': _recurrence,
    };
  }

  Future<void> _save({bool launch = false}) async {
    if (_channels.isEmpty) return toast(context, tr('Choisissez au moins un canal : notification ou e-mail.'), error: true);
    if (!_isAuto && launch && _scheduleMode == 'now') {
      final n = _count;
      final ok = await confirm(
        context,
        'Envoyer maintenant ?',
        n == null ? 'La campagne part tout de suite aux sociétés choisies.' : 'La campagne part tout de suite à ${n['companies']} société(s) : ${n['users']} personne(s)${_channels.contains('email') ? ', ${n['emails']} e-mail(s)' : ''}.',
        ok: 'Envoyer',
      );
      if (!ok || !mounted) return;
    }
    setState(() => _busy = true);
    final api = context.read<Session>().api;
    try {
      if (_isAuto) {
        await api.put('admin/comms/automations/${_src['id']}', _payload());
        if (mounted) toast(context, tr(_active ? 'Relance enregistrée et active.' : 'Relance enregistrée (désactivée).'));
      } else {
        final saved = asMap(_src['id'] == null ? await api.post('admin/comms/campaigns', _payload()) : await api.put('admin/comms/campaigns/${_src['id']}', _payload()));
        final id = asMap(saved['data'])['id'];
        if (launch) {
          final r = asMap(await api.post('admin/comms/campaigns/$id/launch', {}));
          final res = asMap(r['result']);
          if (mounted) {
            toast(context, tr(_scheduleMode == 'now' ? 'Envoyée : ${res['companies'] ?? 0} société(s), ${res['notified'] ?? 0} notification(s), ${res['emails'] ?? 0} e-mail(s) en cours d\'envoi.' : 'Campagne programmée : ${_schedule(asMap(r['data']))}.'));
          }
        } else if (mounted) {
          toast(context, tr('Brouillon enregistré.'));
        }
      }
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _preview() async {
    try {
      final r = asMap(await context.read<Session>().api.post('admin/comms/preview', {..._content(), if (!_isAuto) 'audience': _audiencePayload()}));
      if (!mounted) return;
      await showDialog<void>(context: context, builder: (c) => _PreviewDialog(data: r, channels: _channels));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _test() async {
    setState(() => _busy = true);
    try {
      final r = asMap(await context.read<Session>().api.post('admin/comms/test', _content()));
      final sent = List<String>.from(r['sent'] as List? ?? const []);
      if (mounted) toast(context, tr('Test envoyé${sent.contains('email') ? ' à ${r['to']}' : ''}${sent.contains('notification') ? ' (notification dans votre application)' : ''}.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickCompanies() async {
    final picked = await showDialog<List<int>>(context: context, builder: (_) => _CompanyPicker(selected: List<int>.from((_audience['company_ids'] as List? ?? const []).map(toInt))));
    if (picked != null) {
      setState(() => _audience = {..._audience, 'company_ids': picked});
      _refreshCount();
    }
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final d = await showDatePicker(context: context, firstDate: now, lastDate: now.add(const Duration(days: 365)), initialDate: _sendAt ?? now);
    if (d == null || !mounted) return;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_sendAt ?? now.add(const Duration(hours: 1))));
    if (t == null) return;
    setState(() => _sendAt = DateTime(d.year, d.month, d.day, t.hour, t.minute));
  }

  Future<void> _pickTime() async {
    final p = '${_recurrence['time'] ?? '09:00'}'.split(':');
    final t = await showTimePicker(context: context, initialTime: TimeOfDay(hour: toInt(p.first), minute: toInt(p.last)));
    if (t != null) setState(() => _recurrence = {..._recurrence, 'time': '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}'});
  }

  Widget _section(String title, IconData icon, List<Widget> children, {String? subtitle}) => Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(children: [
                Icon(icon, size: 20, color: YColors.blue),
                const SizedBox(width: 8),
                Expanded(child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5))),
              ]),
              if (subtitle != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr(subtitle), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
              const SizedBox(height: 14),
              ...children,
            ]),
          ),
        ),
      );

  Widget _variables() {
    final vars = asMap(widget.meta['variables']);
    return Wrap(spacing: 6, runSpacing: 6, children: [
      for (final e in vars.entries)
        ActionChip(
          visualDensity: VisualDensity.compact,
          label: Text('{{${e.key}}}', style: const TextStyle(fontSize: 12, fontFamily: 'monospace')),
          tooltip: tr('${e.value} : cliquez pour copier'),
          onPressed: () {
            Clipboard.setData(ClipboardData(text: '{{${e.key}}}'));
            toast(context, tr('{{${e.key}}} copié : collez-le dans le texte.'));
          },
        ),
    ]);
  }

  Widget _settings(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final segments = asMap(widget.meta['segments']);
    final plans = _list(widget.meta['plans']);
    final seg = '${_audience['segment']}';
    final email = _channels.contains('email');
    final notif = _channels.contains('notification');
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 120),
      children: [
        Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 820),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (_isAuto)
                _section('Relance', Icons.autorenew_rounded, [
                  Field(label: 'Nom', child: TextField(controller: _name)),
                  Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    SizedBox(width: 130, child: Field(label: 'Délai (jours)', child: TextField(controller: _days, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly]))),
                    const SizedBox(width: 12),
                    Expanded(child: Padding(padding: const EdgeInsets.only(top: 34), child: Text(tr('${_src['days_label'] ?? 'jours'}'), style: TextStyle(color: hint)))),
                  ]),
                  SwitchListTile(contentPadding: EdgeInsets.zero, value: _active, onChanged: (v) => setState(() => _active = v), title: Text(tr('Relance active')), subtitle: Text(tr('Vérifiée toutes les heures ; chaque société la reçoit une seule fois par occasion.'))),
                ])
              else ...[
                _section('Destinataires', Icons.groups_2_outlined, [
                  Field(
                    label: 'Sociétés',
                    child: DropdownButtonFormField<String>(
                      initialValue: segments.containsKey(seg) ? seg : 'all',
                      isExpanded: true,
                      items: [for (final e in segments.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                      onChanged: (v) {
                        setState(() => _audience = {..._audience, 'segment': v});
                        _refreshCount();
                      },
                    ),
                  ),
                  if (seg == 'plans')
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      for (final p in plans)
                        FilterChip(
                          label: Text(tr('${p['name']}')),
                          selected: (_audience['plans'] as List? ?? const []).contains(p['code']),
                          onSelected: (on) {
                            final cur = {...List<String>.from(_audience['plans'] as List? ?? const [])};
                            on ? cur.add('${p['code']}') : cur.remove('${p['code']}');
                            setState(() => _audience = {..._audience, 'plans': cur.toList()});
                            _refreshCount();
                          },
                        ),
                    ]),
                  if (['expiring', 'inactive', 'new'].contains(seg))
                    SizedBox(
                      width: 280,
                      child: Field(
                        label: seg == 'expiring' ? 'Se termine dans les … jours' : seg == 'inactive' ? 'Sans connexion depuis … jours' : 'Inscrites depuis moins de … jours',
                        child: TextField(controller: _days, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly], onChanged: (_) => _refreshCount()),
                      ),
                    ),
                  if (seg == 'companies')
                    Align(
                      alignment: Alignment.centerLeft,
                      child: OutlinedButton.icon(
                        onPressed: _pickCompanies,
                        icon: const Icon(Icons.checklist_rounded),
                        label: Text(tr('Choisir les sociétés (${(_audience['company_ids'] as List? ?? const []).length})')),
                      ),
                    ),
                  const SizedBox(height: 8),
                  SegmentedButton<String>(
                    segments: [
                      ButtonSegment(value: 'admins', icon: const Icon(Icons.admin_panel_settings_outlined), label: Text(tr('Administrateurs'))),
                      ButtonSegment(value: 'all', icon: const Icon(Icons.groups_outlined), label: Text(tr('Tous les utilisateurs'))),
                    ],
                    selected: {_recipients},
                    onSelectionChanged: (s) {
                      setState(() => _recipients = s.first);
                      _refreshCount();
                    },
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .07), borderRadius: BorderRadius.circular(12)),
                    child: _count == null
                        ? Text(tr('Calcul des destinataires…'), style: TextStyle(color: hint))
                        : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(tr('${_count!['companies']} société(s) · ${_count!['users']} personne(s) · ${_count!['emails']} adresse(s) e-mail'), style: const TextStyle(fontWeight: FontWeight.w700)),
                            if (_list(_count!['sample']).isNotEmpty)
                              Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: Text(tr('Dont ${_list(_count!['sample']).map((s) => s['name']).join(', ')}${toInt(_count!['companies']) > 8 ? '…' : ''}'), style: TextStyle(color: hint, fontSize: 12.5)),
                              ),
                          ]),
                  ),
                ]),
                _section('Nom de la campagne', Icons.label_outline, [TextField(controller: _name, decoration: InputDecoration(hintText: tr('Ex. Promo de novembre −20 %')))]),
              ],
              if (_isAuto)
                _section('Destinataires', Icons.groups_2_outlined, [
                  SegmentedButton<String>(
                    segments: [
                      ButtonSegment(value: 'admins', label: Text(tr('Administrateurs'))),
                      ButtonSegment(value: 'all', label: Text(tr('Tous les utilisateurs'))),
                    ],
                    selected: {_recipients},
                    onSelectionChanged: (s) => setState(() => _recipients = s.first),
                  ),
                ]),
              _section('Canaux', Icons.send_outlined, [
                Wrap(spacing: 10, runSpacing: 10, children: [
                  FilterChip(
                    avatar: const Icon(Icons.notifications_active_outlined, size: 18),
                    label: Text(tr('Notification dans l\'application')),
                    selected: notif,
                    onSelected: (v) => setState(() => v ? _channels.add('notification') : _channels.remove('notification')),
                  ),
                  FilterChip(
                    avatar: const Icon(Icons.alternate_email_rounded, size: 18),
                    label: Text(tr('E-mail')),
                    selected: email,
                    onSelected: (v) => setState(() => v ? _channels.add('email') : _channels.remove('email')),
                  ),
                ]),
              ], subtitle: 'La notification apparaît dans la cloche de l\'application (ordinateur et téléphone) ; un clic ouvre le message complet.'),
              _section('Message', Icons.edit_note_rounded, [
                if (notif) Field(label: 'Titre de la notification', child: TextField(controller: _title, decoration: InputDecoration(hintText: tr('Ex. −20 % sur l\'abonnement annuel')))),
                if (email) ...[
                  Field(label: 'Objet de l\'e-mail', child: TextField(controller: _subject, decoration: InputDecoration(hintText: tr('Ex. {{prenom}}, −20 % pour {{entreprise}}')))),
                  Field(label: 'Texte d\'aperçu (facultatif)', child: TextField(controller: _preheader, decoration: InputDecoration(hintText: tr('Affiché après l\'objet dans la boîte de réception')))),
                ],
                Field(
                  label: 'Format',
                  child: SegmentedButton<String>(
                    segments: [
                      ButtonSegment(value: 'text', icon: const Icon(Icons.notes_rounded), label: Text(tr('Texte'))),
                      ButtonSegment(value: 'design', icon: const Icon(Icons.dashboard_customize_outlined), label: Text(tr('Design'))),
                      ButtonSegment(value: 'html', icon: const Icon(Icons.code_rounded), label: Text(tr('HTML'))),
                    ],
                    selected: {_format},
                    onSelectionChanged: (s) => setState(() => _format = s.first),
                  ),
                ),
                if (_format == 'text' || !email)
                  Field(
                    label: _format == 'text' ? 'Message' : 'Texte de la notification',
                    child: TextField(
                      controller: _body,
                      minLines: 6,
                      maxLines: 18,
                      decoration: InputDecoration(hintText: tr('Bonjour {{prenom}},\n\nÉcrivez votre message. **gras**, [lien](https://…) et lignes vides pour les paragraphes.')),
                    ),
                  ),
                if (_format == 'design' && email)
                  _Banner(
                    icon: Icons.dashboard_customize_outlined,
                    color: YColors.blue,
                    text: 'Composez l\'e-mail dans l\'onglet « Conception » (blocs glissés-déposés, images, boutons, coupons). ${notif ? 'La notification reprend le texte ci-dessous, ou celui des blocs.' : ''}',
                  ),
                if (_format == 'design' && email && notif)
                  Padding(padding: const EdgeInsets.only(top: 12), child: Field(label: 'Texte de la notification (facultatif)', child: TextField(controller: _body, minLines: 2, maxLines: 6))),
                if (_format == 'html' && email) ...[
                  Field(
                    label: 'Code HTML de l\'e-mail',
                    child: TextField(
                      controller: _html,
                      minLines: 12,
                      maxLines: 30,
                      style: const TextStyle(fontFamily: 'monospace', fontSize: 12.5),
                      decoration: InputDecoration(hintText: tr('<h1>Votre titre</h1>\n<p>Bonjour {{prenom}}…</p>\n\nLes scripts sont retirés ; le lien de désinscription est ajouté s\'il manque.')),
                    ),
                  ),
                  if (notif) Field(label: 'Texte de la notification (facultatif)', child: TextField(controller: _body, minLines: 2, maxLines: 6, decoration: InputDecoration(hintText: tr('Sinon, le texte est extrait du HTML.')))),
                ],
                Row(children: [
                  Expanded(child: Field(label: 'Bouton : texte (facultatif)', child: TextField(controller: _ctaLabel, decoration: InputDecoration(hintText: tr('Ex. J\'en profite'))))),
                  const SizedBox(width: 12),
                  Expanded(child: Field(label: 'Bouton : lien', child: TextField(controller: _ctaUrl, decoration: InputDecoration(hintText: tr('https://… ou {{lien_boutique}}'))))),
                ]),
                Text(tr('Variables (personnalisées pour chaque destinataire) :'), style: TextStyle(color: hint, fontSize: 12.5)),
                const SizedBox(height: 6),
                _variables(),
              ]),
              if (!_isAuto)
                _section('Quand ?', Icons.schedule_rounded, [
                  SegmentedButton<String>(
                    segments: [
                      ButtonSegment(value: 'now', icon: const Icon(Icons.bolt_rounded), label: Text(tr('Maintenant'))),
                      ButtonSegment(value: 'once', icon: const Icon(Icons.event_outlined), label: Text(tr('Programmer'))),
                      ButtonSegment(value: 'recurring', icon: const Icon(Icons.repeat_rounded), label: Text(tr('Récurrente'))),
                    ],
                    selected: {_scheduleMode},
                    onSelectionChanged: (s) => setState(() => _scheduleMode = s.first),
                  ),
                  const SizedBox(height: 12),
                  if (_scheduleMode == 'once')
                    Align(
                      alignment: Alignment.centerLeft,
                      child: OutlinedButton.icon(onPressed: _pickDate, icon: const Icon(Icons.event_outlined), label: Text(tr(_sendAt == null ? 'Choisir la date et l\'heure' : 'Le ${dateTimeFr(_sendAt!.toIso8601String())}'))),
                    ),
                  if (_scheduleMode == 'recurring')
                    Wrap(spacing: 12, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      SizedBox(
                        width: 200,
                        child: DropdownButtonFormField<String>(
                          initialValue: '${_recurrence['freq']}',
                          items: [for (final e in _freqLabels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                          onChanged: (v) => setState(() => _recurrence = {..._recurrence, 'freq': v}),
                        ),
                      ),
                      if (_recurrence['freq'] == 'weekly')
                        SizedBox(
                          width: 170,
                          child: DropdownButtonFormField<int>(
                            initialValue: toInt(_recurrence['weekday']).clamp(1, 7),
                            items: [for (final e in _weekdays.entries) DropdownMenuItem(value: e.key, child: Text(tr('le ${e.value}')))],
                            onChanged: (v) => setState(() => _recurrence = {..._recurrence, 'weekday': v}),
                          ),
                        ),
                      if (_recurrence['freq'] == 'monthly')
                        SizedBox(
                          width: 150,
                          child: DropdownButtonFormField<int>(
                            initialValue: toInt(_recurrence['day']).clamp(1, 28),
                            items: [for (var d = 1; d <= 28; d++) DropdownMenuItem(value: d, child: Text(tr('le $d')))],
                            onChanged: (v) => setState(() => _recurrence = {..._recurrence, 'day': v}),
                          ),
                        ),
                      OutlinedButton.icon(onPressed: _pickTime, icon: const Icon(Icons.access_time_rounded), label: Text(tr('à ${_recurrence['time']}'))),
                      OutlinedButton.icon(
                        onPressed: () async {
                          final now = DateTime.now();
                          final d = await showDatePicker(context: context, firstDate: now, lastDate: now.add(const Duration(days: 730)));
                          setState(() => _recurrence = {..._recurrence, 'until': d == null ? null : isoDate(d)});
                        },
                        icon: const Icon(Icons.event_busy_outlined),
                        label: Text(tr(_recurrence['until'] == null ? 'Sans date de fin' : 'Jusqu\'au ${dateFr(_recurrence['until'])}')),
                      ),
                    ]),
                  if (_scheduleMode != 'now')
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Text(tr('Les destinataires sont recalculés à chaque envoi : une nouvelle société du groupe reçoit les envois suivants.'), style: TextStyle(color: hint, fontSize: 12.5)),
                    ),
                ]),
            ]),
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final design = _format == 'design' && _channels.contains('email');
    final narrow = MediaQuery.sizeOf(context).width < 700;
    final primaryLabel = _isAuto ? 'Enregistrer' : switch (_scheduleMode) { 'once' => 'Programmer', 'recurring' => 'Activer la récurrence', _ => 'Envoyer maintenant' };
    return Scaffold(
      appBar: AppBar(
        title: Text(tr(_isAuto ? 'Relance : ${_src['name']}' : (_src['id'] == null ? 'Nouvelle campagne' : 'Modifier la campagne'))),
        actions: [
          IconButton(tooltip: tr('Aperçu'), onPressed: _busy ? null : _preview, icon: const Icon(Icons.visibility_outlined)),
          IconButton(tooltip: tr('M\'envoyer un test'), onPressed: _busy ? null : _test, icon: const Icon(Icons.outgoing_mail)),
          const SizedBox(width: 6),
        ],
        bottom: design ? TabBar(controller: _tabs, tabs: [Tab(text: tr('Paramètres')), Tab(text: tr('Conception'))]) : null,
      ),
      body: design
          ? TabBarView(controller: _tabs, physics: const NeverScrollableScrollPhysics(), children: [
              _settings(context),
              EmailBuilder(design: _design, subject: _subject.text, preheader: _preheader.text, onChanged: () => setState(() {})),
            ])
          : _settings(context),
      bottomNavigationBar: SafeArea(
        child: Container(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          decoration: BoxDecoration(color: Theme.of(context).cardColor, border: Border(top: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: .4)))),
          child: Row(children: [
            if (!narrow) ...[
              OutlinedButton.icon(onPressed: _busy ? null : _preview, icon: const Icon(Icons.visibility_outlined), label: Text(tr('Aperçu'))),
              const SizedBox(width: 8),
              OutlinedButton.icon(onPressed: _busy ? null : _test, icon: const Icon(Icons.outgoing_mail), label: Text(tr('M\'envoyer un test'))),
            ],
            const Spacer(),
            if (!_isAuto) TextButton(onPressed: _busy ? null : () => _save(), child: Text(tr('Brouillon'))),
            const SizedBox(width: 8),
            FilledButton.icon(
              onPressed: _busy ? null : () => _save(launch: !_isAuto),
              icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : Icon(_isAuto ? Icons.check_rounded : Icons.send_rounded),
              label: Text(tr(primaryLabel)),
            ),
          ]),
        ),
      ),
    );
  }
}

class _PreviewDialog extends StatelessWidget {
  const _PreviewDialog({required this.data, required this.channels});

  final Map<String, dynamic> data;
  final Set<String> channels;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return YAlertDialog(
      icon: const Icon(Icons.visibility_outlined),
      title: Text(tr('Aperçu')),
      subtitle: tr('Personnalisé pour ${data['company'] ?? 'une société cliente'}'),
      content: SizedBox(
        width: 680,
        height: 560,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (channels.contains('notification')) ...[
            Text(tr('Notification'), style: TextStyle(color: hint, fontSize: 12, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Card(
              child: ListTile(
                leading: const CircleAvatar(child: Icon(Icons.campaign_outlined)),
                title: Text(tr('${data['title'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(tr('${data['text'] ?? ''}'), maxLines: 3, overflow: TextOverflow.ellipsis),
              ),
            ),
            const SizedBox(height: 12),
          ],
          if (channels.contains('email')) ...[
            Text(tr('E-mail · Objet : ${data['subject'] ?? ''}'), style: TextStyle(color: hint, fontSize: 12, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Expanded(
              child: Container(
                decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), border: Border.all(color: Theme.of(context).dividerColor)),
                clipBehavior: Clip.antiAlias,
                child: MailHtml(html: '${data['html'] ?? ''}'),
              ),
            ),
          ],
        ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

class _CompanyPicker extends StatefulWidget {
  const _CompanyPicker({required this.selected});

  final List<int> selected;

  @override
  State<_CompanyPicker> createState() => _CompanyPickerState();
}

class _CompanyPickerState extends State<_CompanyPicker> {
  late final Set<int> _sel = {...widget.selected};
  List<Map<String, dynamic>> _rows = const [];
  Timer? _t;

  @override
  void initState() {
    super.initState();
    _search('');
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  void _search(String q) {
    _t?.cancel();
    _t = Timer(const Duration(milliseconds: 300), () async {
      try {
        final r = asMap(await context.read<Session>().api.get('admin/comms/companies', query: {'q': q}));
        if (mounted) setState(() => _rows = _list(r['data']));
      } catch (_) {}
    });
  }

  @override
  Widget build(BuildContext context) => YAlertDialog(
        icon: const Icon(Icons.checklist_rounded),
        title: Text(tr('Choisir les sociétés')),
        subtitle: tr('${_sel.length} sélectionnée(s)'),
        content: SizedBox(
          width: 520,
          height: 440,
          child: Column(children: [
            SearchField(onChanged: _search, hint: tr('Nom ou e-mail…')),
            const SizedBox(height: 8),
            Expanded(
              child: ListView(children: [
                for (final r in _rows)
                  CheckboxListTile(
                    dense: true,
                    value: _sel.contains(toInt(r['id'])),
                    onChanged: (v) => setState(() => v == true ? _sel.add(toInt(r['id'])) : _sel.remove(toInt(r['id']))),
                    title: Text(tr('${r['name']}')),
                    subtitle: Text(tr([r['plan'], r['email']].whereType<Object>().join(' · '))),
                  ),
              ]),
            ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(context, _sel.toList()), child: Text(tr('Valider'))),
        ],
      );
}
