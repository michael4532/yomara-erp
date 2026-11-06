import 'dart:convert';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'logistique_screen.dart' show SignaturePad, SignaturePadState;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const sgStatuses = {'draft': 'Brouillon', 'pending': 'En cours', 'completed': 'Signé', 'declined': 'Refusé', 'cancelled': 'Annulé', 'expired': 'Expiré'};
const sgSignerStatuses = {'waiting': 'Attend son tour', 'pending': 'À signer', 'viewed': 'Document ouvert', 'signed': 'Signé', 'declined': 'Refusé'};
const sgVerif = {'sms': 'code SMS', 'whatsapp': 'code WhatsApp', 'email': 'code e-mail', 'account': 'compte utilisateur', 'link': 'lien personnel'};

Color sgColor(String? s) => switch (s) {
  'completed' || 'signed' => YColors.success,
  'pending' || 'viewed' => YColors.warning,
  'declined' || 'expired' => YColors.danger,
  'waiting' => YColors.blue,
  _ => YColors.muted,
};

String _dt(dynamic iso) {
  final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
  if (d == null) return '—';
  String p(int v) => v.toString().padLeft(2, '0');
  return '${p(d.day)}/${p(d.month)}/${d.year} ${p(d.hour)}:${p(d.minute)}';
}

String _waNumber(String phone) => phone.replaceAll(RegExp(r'\D'), '').replaceFirst(RegExp(r'^0'), '2250');

class SignatureScreen extends StatefulWidget {
  const SignatureScreen({super.key});

  @override
  State<SignatureScreen> createState() => _SignatureScreenState();
}

class _SignatureScreenState extends State<SignatureScreen> {
  final _reload = ValueNotifier(0);

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('signature.manage');
    final view = manage || s.can('signature.view');
    final tabs = [('À signer', _MineTab(reload: _reload)), if (view) ('Demandes', _RequestsTab(reload: _reload))];
    return DefaultTabController(
      length: tabs.length,
      initialIndex: view ? tabs.length - 1 : 0,
      child: Column(children: [
        PageHeader(
          title: tr('Signature électronique'),
          subtitle: tr('Contrats, devis et documents signés en ligne, avec certificat de preuve'),
          actions: [
            OutlinedButton.icon(onPressed: () => _verify(context), icon: const Icon(Icons.verified_outlined), label: Text(tr('Vérifier un document'))),
            if (manage) OutlinedButton.icon(onPressed: () => _settings(context), icon: const Icon(Icons.tune), label: Text(tr('Paramètres'))),
            if (manage)
              FilledButton.icon(
                onPressed: () async {
                  await newSignatureRequest(context);
                  _reload.value++;
                },
                icon: const Icon(Icons.add),
                label: Text(tr('Nouvelle demande')),
              ),
          ],
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }

  Future<void> _verify(BuildContext context) async {
    final base = context.read<Session>().api.baseUrl;
    await safeLaunchUrl(Uri.parse('$base/signature/verifier'), mode: LaunchMode.externalApplication);
  }

  Future<void> _settings(BuildContext context) async {
    final api = context.read<Session>().api;
    try {
      final st = asMap((await api.get('signature/settings'))['data']);
      if (!context.mounted) return;
      var otp = st['otp'] != false;
      final rem = TextEditingController(text: '${st['reminder_days']}');
      final max = TextEditingController(text: '${st['max_reminders']}');
      final exp = TextEditingController(text: '${st['expiry_days']}');
      final ok = await showDialog<bool>(
        context: context,
        builder: (d) => StatefulBuilder(
          builder: (d, set) => YAlertDialog(
            title: Text(tr('Paramètres de signature')),
            content: SizedBox(
              width: 460,
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(tr('Code de vérification')),
                  subtitle: Text(tr('Le signataire reçoit un code par SMS, WhatsApp ou e-mail avant de signer (si un canal d\'envoi est configuré).')),
                  value: otp,
                  onChanged: (v) => set(() => otp = v),
                ),
                TextField(controller: exp, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Validité d\'une demande (jours)'))),
                Row(children: [
                  Expanded(child: TextField(controller: rem, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Relance automatique tous les (jours)'), helperText: tr('0 : jamais')))),
                  const SizedBox(width: 10),
                  Expanded(child: TextField(controller: max, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Relances au maximum')))),
                ]),
              ]),
            ),
            actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
          ),
        ),
      );
      if (ok == true) {
        await api.put('signature/settings', {'otp': otp, 'reminder_days': int.tryParse(rem.text) ?? 3, 'max_reminders': int.tryParse(max.text) ?? 3, 'expiry_days': int.tryParse(exp.text) ?? 30});
        if (context.mounted) toast(context, tr('Paramètres enregistrés.'));
      }
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }
}

// ---------- Listes ----------

class _MineTab extends StatefulWidget {
  const _MineTab({required this.reload});

  final ValueNotifier<int> reload;

  @override
  State<_MineTab> createState() => _MineTabState();
}

class _MineTabState extends State<_MineTab> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _q = '';
  String _due = '';
  FilterPeriod? _period;
  int _rk = 0;

  @override
  void initState() {
    super.initState();
    widget.reload.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.reload.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('signature/mine');
      if (mounted) setState(() => (_rows = [for (final x in r['data'] as List) asMap(x)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_rows == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    if (_rows!.isEmpty) return EmptyState(icon: Icons.draw_outlined, title: tr('Rien à signer'), message: tr('Les documents qu\'on vous demande de signer apparaissent ici.'));
    final q = _q.toLowerCase();
    final soon = DateTime.now().add(const Duration(days: 7));
    bool dueOf(Map<String, dynamic> r, String k) => switch (k) {
          'soon' => DateTime.tryParse('${r['expires_at'] ?? ''}')?.isBefore(soon) ?? false,
          'none' => r['expires_at'] == null,
          _ => true,
        };
    final base = _rows!
        .where((r) => q.isEmpty || '${r['title'] ?? ''} ${r['number'] ?? ''}'.toLowerCase().contains(q))
        .where((r) => _period == null || _period!.contains(DateTime.tryParse('${r['sent_at'] ?? ''}')?.toLocal()))
        .toList();
    final rows = base.where((r) => dueOf(r, _due)).toList();
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        FilterBar(
          key: ValueKey(_rk),
          padding: const EdgeInsets.only(bottom: 10),
          onSearch: (v) => setState(() => _q = v),
          search: _q,
          searchHint: 'Titre, numéro…',
          statuses: const {'': 'Tous', 'soon': 'Expire sous 7 jours', 'none': 'Sans date limite'},
          status: _due,
          onStatus: (v) => setState(() => _due = v),
          statusCounts: {for (final k in ['', 'soon', 'none']) k: base.where((r) => dueOf(r, k)).length},
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows.length,
          countLabel: 'document',
          onReset: () => setState(() => (_q = '', _due = '', _period = null, _rk++)),
        ),
        if (rows.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun document ne correspond aux filtres')),
        for (final r in rows)
          Card(
            child: ListTile(
              leading: const Icon(Icons.draw_outlined, color: YColors.warning),
              title: Text(tr('${r['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr('${r['number']} · envoyé le ${_dt(r['sent_at'])}${r['expires_at'] != null ? ' · avant le ${dateFr(r['expires_at'])}' : ''}')),
              trailing: FilledButton(
                onPressed: () async {
                  await signAsUser(context, r);
                  _load();
                  widget.reload.value++;
                },
                child: Text(tr('Signer')),
              ),
              onTap: () async {
                await showSignatureRequest(context, '${r['id']}');
                _load();
              },
            ),
          ),
      ]),
    );
  }
}

class _RequestsTab extends StatefulWidget {
  const _RequestsTab({required this.reload});

  final ValueNotifier<int> reload;

  @override
  State<_RequestsTab> createState() => _RequestsTabState();
}

class _RequestsTabState extends State<_RequestsTab> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  // '' = en cours (vue par défaut), 'all' = toutes
  String _filter = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _rk = 0;

  @override
  void initState() {
    super.initState();
    widget.reload.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.reload.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('signature/requests');
      if (mounted) setState(() => (_rows = [for (final x in r['data'] as List) asMap(x)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_rows == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    const filters = {'': 'En cours', 'completed': 'Signés', 'closed': 'Refusés, expirés, annulés', 'draft': 'Brouillons', 'all': 'Toutes'};
    bool match(Map r, String f) => switch (f) {
      'all' => true,
      '' => r['status'] == 'pending',
      'closed' => ['declined', 'expired', 'cancelled'].contains(r['status']),
      _ => r['status'] == f,
    };
    String src(Map r) => r['source_type'] == 'rh_document' ? 'rh' : (r['source_type'] == 'sales_document' ? 'sales' : 'pdf');
    final q = _q.toLowerCase();
    final all = _rows!;
    final base = all
        .where((r) => q.isEmpty || '${r['title']} ${r['number']} ${(r['signers'] as List).map((s) => s['name']).join(' ')}'.toLowerCase().contains(q))
        .where((r) => _f['source'] == null || src(r) == _f['source'])
        .where((r) => _f['signer'] == null || (r['signers'] as List).any((s) => '${s['name']}' == _f['signer']))
        .where((r) => _f['author'] == null || '${r['created_by_name'] ?? ''}' == _f['author'])
        .where((r) => _period == null || _period!.contains(DateTime.tryParse('${r['created_at'] ?? ''}')?.toLocal()))
        .toList();
    final rows = base.where((r) => match(r, _filter)).toList();
    return Column(children: [
      Padding(
        padding: const EdgeInsets.only(top: 12),
        child: FilterBar(
          key: ValueKey(_rk),
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
          onSearch: (v) => setState(() => _q = v),
          search: _q,
          searchHint: 'Titre, numéro, signataire…',
          statuses: filters,
          status: _filter,
          onStatus: (v) => setState(() => _filter = v),
          statusCounts: {for (final k in filters.keys) k: base.where((r) => match(r, k)).length},
          filters: [
            const FilterSpec('source', 'Origine', {'pdf': 'PDF importé', 'sales': 'Devis / document commercial', 'rh': 'Document RH'}, icon: Icons.source_outlined),
            FilterSpec('signer', 'Signataire', {for (final r in all) for (final sg in (r['signers'] as List)) if ('${sg['name'] ?? ''}'.isNotEmpty) '${sg['name']}': '${sg['name']}'}, icon: Icons.person_outline),
            FilterSpec('author', 'Créée par', filterOptions(all, 'created_by_name'), icon: Icons.manage_accounts_outlined),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows.length,
          countLabel: 'demande',
          onReset: () => setState(() => (_q = '', _filter = '', _f = {}, _period = null, _rk++)),
        ),
      ),
      Expanded(
        child: rows.isEmpty
            ? (all.isNotEmpty && (base.length != all.length || _filter.isNotEmpty)
                ? EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune demande ne correspond aux filtres'))
                : EmptyState(icon: Icons.draw_outlined, title: tr('Aucune demande'), message: tr('Créez une demande depuis un PDF, ou « Faire signer » depuis un devis ou un document RH.')))
            : RefreshIndicator(
                onRefresh: _load,
                child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 16), children: [
                  for (final r in rows) _RequestCard(r: r, onTap: () async {
                    await showSignatureRequest(context, '${r['id']}');
                    _load();
                  }),
                ]),
              ),
      ),
    ]);
  }
}

class _RequestCard extends StatelessWidget {
  const _RequestCard({required this.r, required this.onTap});

  final Map<String, dynamic> r;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final signers = (r['signers'] as List).cast<Map>();
    final signed = signers.where((s) => s['status'] == 'signed').length;
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Icon(r['status'] == 'completed' ? Icons.task_alt : (r['source_type'] == 'rh_document' ? Icons.badge_outlined : (r['source_type'] == 'sales_document' ? Icons.request_quote_outlined : Icons.picture_as_pdf_outlined)), color: sgColor(r['status'])),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${r['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 2),
                Text(tr('${r['number']} · ${signers.map((s) => s['name']).join(', ')}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, color: Theme.of(context).hintColor)),
                const SizedBox(height: 6),
                Row(children: [
                  for (final s in signers)
                    Padding(
                      padding: const EdgeInsets.only(right: 4),
                      child: Tooltip(message: '${s['name']} : ${sgSignerStatuses[s['status']]}', child: Icon(s['status'] == 'signed' ? Icons.check_circle : (s['status'] == 'declined' ? Icons.cancel : Icons.radio_button_unchecked), size: 16, color: sgColor(s['status']))),
                    ),
                  const SizedBox(width: 4),
                  Text(tr('$signed/${signers.length} signé${signers.length > 1 ? 's' : ''}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                ]),
              ]),
            ),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              StatusChip(sgStatuses[r['status']] ?? '', color: sgColor(r['status'])),
              const SizedBox(height: 6),
              Text(tr(r['status'] == 'completed' ? 'le ${_dt(r['completed_at'])}' : (r['status'] == 'pending' && r['expires_at'] != null ? 'expire le ${dateFr(r['expires_at'])}' : _dt(r['created_at']))), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
            ]),
          ]),
        ),
      ),
    );
  }
}

// ---------- Détail ----------

Future<void> showSignatureRequest(BuildContext context, String id) => showDialog(context: context, builder: (_) => _RequestDialog(id: id));

Future<void> _openPdf(BuildContext context, Map r, String kind, {bool save = false}) async {
  try {
    final bytes = Uint8List.fromList(await context.read<Session>().api.download('signature/requests/${r['id']}/file/$kind'));
    final name = '${'${r['original_name'] ?? 'document.pdf'}'.replaceAll(RegExp(r'\.pdf$', caseSensitive: false), '')}${kind == 'signed' ? ' - signé' : ''}.pdf';
    if (save) {
      final where = await saveFile(name, bytes, mime: 'application/pdf');
      if (context.mounted) toast(context, tr('PDF enregistré${where.isEmpty ? '' : ' : $where'}'));
    } else {
      await Printing.layoutPdf(name: name, onLayout: (_) async => bytes);
    }
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

class _RequestDialog extends StatefulWidget {
  const _RequestDialog({required this.id});

  final String id;

  @override
  State<_RequestDialog> createState() => _RequestDialogState();
}

class _RequestDialogState extends State<_RequestDialog> {
  Map<String, dynamic>? _r;
  Object? _error;
  bool _busy = false;
  bool _log = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('signature/requests/${widget.id}');
      if (mounted) setState(() => (_r = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _act(Future<dynamic> Function() f, [String? ok]) async {
    setState(() => _busy = true);
    try {
      final res = await f();
      if (res is Map && res['data'] is Map) setState(() => _r = asMap(res['data']));
      if (ok != null && mounted) toast(context, ok);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final manage = s.can('signature.manage');
    final api = s.api;
    if (_r == null) {
      return YAlertDialog(content: SizedBox(width: 400, height: 160, child: _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator())));
    }
    final r = _r!;
    final signers = (r['signers'] as List).map((e) => asMap(e)).toList();
    final events = ((r['events'] as List?) ?? []).cast<Map>();
    final mine = signers.where((x) => x['mine'] == true && ['pending', 'viewed'].contains(x['status'])).firstOrNull;
    return YAlertDialog(
      titlePadding: const EdgeInsets.fromLTRB(24, 20, 12, 0),
      title: Row(children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${r['title']}')),
            Text(tr('${r['number']} · ${r['original_name']}${r['pages'] != null ? ' · ${r['pages']} p.' : ''}'), style: TextStyle(fontSize: 13, color: Theme.of(context).hintColor)),
          ]),
        ),
        StatusChip(sgStatuses[r['status']] ?? '', color: sgColor(r['status'])),
        IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close)),
      ]),
      content: SizedBox(
        width: 680,
        child: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 18, runSpacing: 4, children: [
              if (r['created_by_name'] != null) Text(tr('Créée par ${r['created_by_name']}'), style: TextStyle(color: Theme.of(context).hintColor)),
              if (r['sent_at'] != null) Text(tr('Envoyée le ${_dt(r['sent_at'])}'), style: TextStyle(color: Theme.of(context).hintColor)),
              if (r['expires_at'] != null && ['pending', 'draft'].contains(r['status'])) Text(tr('Expire le ${dateFr(r['expires_at'])}'), style: TextStyle(color: Theme.of(context).hintColor)),
              if (r['completed_at'] != null) Text(tr('Signé par tous le ${_dt(r['completed_at'])}'), style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w600)),
              if (r['sequential'] == true) Text(tr('Ordre de signature imposé'), style: TextStyle(color: Theme.of(context).hintColor)),
            ]),
            if ((r['message'] ?? '').toString().isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('« ${r['message']} »'))),
            const SizedBox(height: 14),
            Text(tr('Signataires'), style: TextStyle(fontWeight: FontWeight.w700)),
            for (final x in signers)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: CircleAvatar(
                  radius: 16,
                  backgroundColor: sgColor(x['status']).withValues(alpha: 0.12),
                  child: r['sequential'] == true ? Text(tr('${x['position']}'), style: TextStyle(color: sgColor(x['status']), fontWeight: FontWeight.w800)) : Icon(x['user_id'] != null ? Icons.person : Icons.person_outline, size: 18, color: sgColor(x['status'])),
                ),
                title: Text(tr('${x['name']}${x['label'] != null ? ' — ${x['label']}' : ''}')),
                subtitle: Text(tr([
                  sgSignerStatuses[x['status']] ?? '',
                  if (x['user_id'] != null) 'signe dans l\'application' else ...[if (x['phone'] != null) '${x['phone']}', if (x['email'] != null) '${x['email']}'],
                  if (x['signed_at'] != null) 'le ${_dt(x['signed_at'])} (${sgVerif[x['verified_by']] ?? ''})',
                  if (x['viewed_at'] != null && x['signed_at'] == null) 'ouvert le ${_dt(x['viewed_at'])}',
                  if (x['decline_reason'] != null) '« ${x['decline_reason']} »',
                ].join(' · '))),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  if (x['link'] != null) ...[
                    IconButton(
                      tooltip: tr('Copier le lien de signature'),
                      icon: const Icon(Icons.link),
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: '${x['link']}'));
                        toast(context, tr('Lien copié : transmettez-le à ${x['name']}.'));
                      },
                    ),
                    if (x['phone'] != null)
                      IconButton(
                        tooltip: tr('Envoyer par WhatsApp'),
                        icon: const Icon(Icons.chat_outlined, color: Color(0xFF25D366)),
                        onPressed: () => safeLaunchUrl(
                          Uri.parse('https://wa.me/${_waNumber('${x['phone']}')}?text=${Uri.encodeComponent('Bonjour ${x['name']}, merci de signer le document « ${r['title']} » en ligne : ${x['link']}')}'),
                          mode: LaunchMode.externalApplication,
                        ),
                      ),
                  ],
                  if (manage && r['status'] == 'pending' && ['pending', 'viewed'].contains(x['status']))
                    IconButton(tooltip: tr('Relancer'), icon: const Icon(Icons.notifications_active_outlined), onPressed: _busy ? null : () => _act(() => api.post('signature/requests/${r['id']}/remind', {'signer_id': x['id']}), 'Relance envoyée.')),
                ]),
              ),
            if (r['status'] == 'pending' && signers.any((x) => x['link'] != null && x['notified_at'] == null))
              Container(
                margin: const EdgeInsets.only(top: 4),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: YColors.warning.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(10)),
                child: Text(tr('Aucun canal d\'envoi n\'a pu joindre certains signataires : copiez leur lien ou envoyez-le par WhatsApp.'), style: TextStyle(fontSize: 13)),
              ),
            const SizedBox(height: 10),
            InkWell(
              onTap: () => setState(() => _log = !_log),
              child: Row(children: [Text(tr('Journal (${events.length})'), style: const TextStyle(fontWeight: FontWeight.w700)), Icon(_log ? Icons.expand_less : Icons.expand_more)]),
            ),
            if (_log)
              for (final e in events)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    SizedBox(width: 120, child: Text(tr(_dt(e['at'])), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor))),
                    Expanded(child: Text(tr([e['label'], if (e['who'] != null) e['who'], if (e['data'] is Map && e['data']['channel'] != null) '${e['data']['channel']}', if (e['data'] is Map && e['data']['reason'] != null) '« ${e['data']['reason']} »'].join(' · ')), style: const TextStyle(fontSize: 13))),
                    if (e['ip'] != null) Text(tr('${e['ip']}'), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
                  ]),
                ),
          ]),
        ),
      ),
      actionsOverflowButtonSpacing: 6,
      actions: [
        if (manage && r['status'] == 'draft') TextButton(onPressed: _busy ? null : () async {
          if (await confirm(context, tr('Supprimer le brouillon ?'), tr('Le document et la liste des signataires seront effacés.'), danger: true)) {
            await _act(() => api.delete('signature/requests/${r['id']}'));
            if (context.mounted) Navigator.pop(context);
          }
        }, child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
        if (manage && ['draft', 'pending'].contains(r['status'])) TextButton(onPressed: _busy ? null : () async {
          if (await confirm(context, tr('Annuler la demande ?'), tr('Les liens de signature ne fonctionneront plus.'), danger: true)) await _act(() => api.post('signature/requests/${r['id']}/cancel'), 'Demande annulée.');
        }, child: Text(tr('Annuler la demande'), style: TextStyle(color: YColors.danger))),
        OutlinedButton.icon(onPressed: () => _openPdf(context, r, 'original'), icon: const Icon(Icons.picture_as_pdf_outlined), label: Text(tr('Original'))),
        if (r['has_signed_file'] == true) ...[
          OutlinedButton.icon(onPressed: () => _openPdf(context, r, 'signed', save: true), icon: const Icon(Icons.download_outlined), label: Text(tr('Enregistrer'))),
          FilledButton.icon(onPressed: () => _openPdf(context, r, 'signed'), icon: const Icon(Icons.verified_outlined), label: Text(tr('PDF signé'))),
        ],
        if (manage && r['status'] == 'pending') OutlinedButton.icon(onPressed: _busy ? null : () => _act(() => api.post('signature/requests/${r['id']}/remind'), 'Relance envoyée.'), icon: const Icon(Icons.notifications_active_outlined), label: Text(tr('Relancer'))),
        if (manage && r['status'] == 'draft') FilledButton.icon(onPressed: _busy ? null : () => _act(() => api.post('signature/requests/${r['id']}/send'), 'Demande envoyée.'), icon: const Icon(Icons.send), label: Text(tr('Envoyer'))),
        if (mine != null && (r['sequential'] != true || mine['status'] != 'waiting'))
          FilledButton.icon(onPressed: _busy ? null : () async {
            await signAsUser(context, r);
            _load();
          }, icon: const Icon(Icons.draw), label: Text(tr('Signer maintenant'))),
      ],
    );
  }
}

/// Signature par l'utilisateur connecté (signataire interne).
Future<void> signAsUser(BuildContext context, Map r) async {
  final api = context.read<Session>().api;
  final pad = GlobalKey<SignaturePadState>();
  var agree = false;
  final res = await showDialog<String>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr('Signer « ${r['title']} »')),
        content: SizedBox(
          width: 520,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            OutlinedButton.icon(onPressed: () => _openPdf(d, r, 'original'), icon: const Icon(Icons.chrome_reader_mode_outlined), label: Text(tr('Lire le document'))),
            const SizedBox(height: 12),
            SignaturePad(key: pad, height: 170),
            Align(alignment: Alignment.centerRight, child: TextButton(onPressed: () => pad.currentState?.clear(), child: Text(tr('Effacer')))),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: agree,
              onChanged: (v) => set(() => agree = v ?? false),
              title: Text(tr('J\'ai lu le document et j\'accepte de le signer électroniquement.')),
            ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d, 'decline'), child: Text(tr('Refuser de signer'), style: TextStyle(color: YColors.danger))),
          TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Plus tard'))),
          FilledButton(onPressed: agree ? () => Navigator.pop(d, 'sign') : null, child: Text(tr('Signer'))),
        ],
      ),
    ),
  );
  if (!context.mounted) return;
  try {
    if (res == 'sign') {
      final png = await pad.currentState?.exportPng();
      if (png == null) {
        if (context.mounted) toast(context, tr('Tracez votre signature.'), error: true);
        return;
      }
      final out = await api.post('signature/requests/${r['id']}/sign', {'signature': png, 'agree': true});
      if (context.mounted) toast(context, out['data']['status'] == 'completed' ? 'Signé : le document est complet et scellé.' : 'Signature enregistrée.');
    } else if (res == 'decline') {
      final c = TextEditingController();
      final ok = await showDialog<bool>(
        context: context,
        builder: (d) => YAlertDialog(
          title: Text(tr('Refuser de signer')),
          content: TextField(controller: c, maxLines: 3, decoration: InputDecoration(labelText: tr('Motif'))),
          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Refuser')))],
        ),
      );
      if (ok == true) await api.post('signature/requests/${r['id']}/decline', {'reason': c.text.trim().isEmpty ? null : c.text.trim()});
    }
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ---------- Nouvelle demande ----------

/// Crée une demande de signature. [bytes] : PDF déjà produit (devis, document RH) ; sinon l'utilisateur choisit un fichier.
Future<void> newSignatureRequest(
  BuildContext context, {
  Uint8List? bytes,
  String? fileName,
  String? title,
  String? sourceType,
  String? sourceId,
  List<Map<String, dynamic>> signers = const [],
}) async {
  final s = context.read<Session>();
  final api = s.api;
  // Demande déjà en cours ou signée pour ce document ?
  if (sourceId != null) {
    try {
      final ex = [for (final x in (await api.get('signature/requests', query: {'source_id': sourceId}))['data'] as List) asMap(x)]
          .where((x) => ['pending', 'completed', 'draft'].contains(x['status']))
          .toList();
      if (ex.isNotEmpty && context.mounted) {
        final go = await showDialog<String>(
          context: context,
          builder: (d) => YAlertDialog(
            title: Text(tr('Demande existante')),
            content: Text(tr('Ce document a déjà une demande de signature (${ex.first['number']}, ${(sgStatuses[ex.first['status']] ?? '').toLowerCase()}).')),
            actions: [
              TextButton(onPressed: () => Navigator.pop(d, 'new'), child: Text(tr('Nouvelle demande'))),
              FilledButton(onPressed: () => Navigator.pop(d, 'open'), child: Text(tr('Voir la demande'))),
            ],
          ),
        );
        if (go == 'open' && context.mounted) {
          await showSignatureRequest(context, '${ex.first['id']}');
          return;
        }
        if (go != 'new') return;
      }
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
      return;
    }
  }
  List<Map<String, dynamic>> users = [];
  try {
    users = [for (final u in (await api.get('signature/users'))['data'] as List) asMap(u)];
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return;
  }
  if (!context.mounted) return;
  final result = await showFormDialog<Map<String, dynamic>>(
    context,
    title: 'Demande de signature',
    maxWidth: 728,
    builder: (_) => _NewRequestDialog(bytes: bytes, fileName: fileName, title: title, sourceType: sourceType, sourceId: sourceId, signers: signers, users: users),
  );
  if (result != null && context.mounted) await showSignatureRequest(context, '${result['id']}');
}

class _SignerRow {
  _SignerRow({Map<String, dynamic>? m})
      : userId = (m?['user_id'] as num?)?.toInt(),
        name = TextEditingController(text: '${m?['name'] ?? ''}'),
        label = TextEditingController(text: '${m?['label'] ?? ''}'),
        email = TextEditingController(text: '${m?['email'] ?? ''}'),
        phone = TextEditingController(text: '${m?['phone'] ?? ''}');

  int? userId;
  final TextEditingController name, label, email, phone;

  Map<String, dynamic> toJson() {
    String? t(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
    return {'name': t(name), 'label': t(label), if (userId == null) 'email': t(email), if (userId == null) 'phone': t(phone), 'user_id': userId};
  }
}

class _NewRequestDialog extends StatefulWidget {
  const _NewRequestDialog({this.bytes, this.fileName, this.title, this.sourceType, this.sourceId, required this.signers, required this.users});

  final Uint8List? bytes;
  final String? fileName, title, sourceType, sourceId;
  final List<Map<String, dynamic>> signers;
  final List<Map<String, dynamic>> users;

  @override
  State<_NewRequestDialog> createState() => _NewRequestDialogState();
}

class _NewRequestDialogState extends State<_NewRequestDialog> {
  late Uint8List? _bytes = widget.bytes;
  late String? _name = widget.fileName;
  late final _title = TextEditingController(text: widget.title ?? '');
  final _message = TextEditingController();
  final _days = TextEditingController(text: '30');
  late final List<_SignerRow> _rows = widget.signers.isEmpty ? [_SignerRow()] : [for (final m in widget.signers) _SignerRow(m: m)];
  bool _sequential = false;
  bool _busy = false;

  Future<void> _pick() async {
    final f = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['pdf']);
    if (f.isEmpty) return;
    final b = await f.first.readAsBytes();
    if (!mounted) return;
    setState(() {
      _bytes = Uint8List.fromList(b);
      _name = f.first.name;
      if (_title.text.trim().isEmpty) _title.text = f.first.name.replaceAll(RegExp(r'\.pdf$', caseSensitive: false), '');
    });
  }

  Future<void> _save(bool send) async {
    if (_bytes == null) return toast(context, tr('Choisissez le document PDF.'), error: true);
    final signers = _rows.map((r) => r.toJson()).where((m) => m['name'] != null || m['user_id'] != null).toList();
    if (signers.isEmpty) return toast(context, tr('Ajoutez au moins un signataire.'), error: true);
    setState(() => _busy = true);
    try {
      final res = await context.read<Session>().api.uploadForm('signature/requests', {
        'title': _title.text.trim(),
        'message': _message.text.trim().isEmpty ? null : _message.text.trim(),
        'sequential': _sequential ? '1' : '0',
        'expires_days': int.tryParse(_days.text) ?? 30,
        'send': send ? '1' : '0',
        'source_type': widget.sourceType,
        'source_id': widget.sourceId,
        'signers': jsonEncode(signers),
      }, 'file', _bytes!, _name ?? 'document.pdf');
      if (!mounted) return;
      final data = asMap(res['data']);
      final delivery = res['delivery'] is Map ? asMap(res['delivery']) : <String, dynamic>{};
      final manual = delivery.entries.where((e) => e.value == null).length;
      toast(context, send ? (manual > 0 ? 'Demande envoyée. $manual lien(s) à transmettre vous-même (copier ou WhatsApp).' : 'Demande envoyée aux signataires.') : 'Brouillon enregistré.');
      Navigator.pop(context, data);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
        Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Icon(Icons.picture_as_pdf_outlined, color: YColors.danger),
            const SizedBox(width: 8),
            Expanded(child: Text(tr(_name ?? 'Aucun document choisi'), style: TextStyle(fontWeight: FontWeight.w600, color: _name == null ? Theme.of(context).hintColor : null))),
            if (widget.bytes == null) OutlinedButton.icon(onPressed: _pick, icon: const Icon(Icons.upload_file), label: Text(tr(_name == null ? 'Choisir un PDF' : 'Changer'))),
            if (_bytes != null) TextButton(onPressed: () => Printing.layoutPdf(name: _name ?? 'document.pdf', onLayout: (_) async => _bytes!), child: Text(tr('Aperçu'))),
          ]),
          TextField(controller: _title, decoration: InputDecoration(labelText: tr('Titre (vu par les signataires)'))),
          TextField(controller: _message, maxLines: 2, decoration: InputDecoration(labelText: tr('Message aux signataires (facultatif)'))),
          const SizedBox(height: 14),
          Row(children: [
            Expanded(child: Text(tr('Signataires'), style: TextStyle(fontWeight: FontWeight.w700))),
            TextButton.icon(onPressed: () => setState(() => _rows.add(_SignerRow())), icon: const Icon(Icons.person_add_alt, size: 18), label: Text(tr('Externe'))),
            if (widget.users.isNotEmpty)
              TextButton.icon(onPressed: () => setState(() => _rows.add(_SignerRow(m: {'user_id': widget.users.first['id'], 'name': widget.users.first['name']}))), icon: const Icon(Icons.badge_outlined, size: 18), label: Text(tr('Utilisateur'))),
          ]),
          for (var i = 0; i < _rows.length; i++) _signerCard(i),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(tr('Signer dans l\'ordre de la liste')),
            subtitle: Text(tr('Chacun reçoit son lien quand le précédent a signé.')),
            value: _sequential,
            onChanged: _rows.length < 2 ? null : (v) => setState(() => _sequential = v),
          ),
          SizedBox(width: 220, child: TextField(controller: _days, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('À signer sous (jours)')))),
        ]),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: Text(tr('Annuler'))),
            OutlinedButton(onPressed: _busy ? null : () => _save(false), child: Text(tr('Brouillon'))),
            FilledButton.icon(onPressed: _busy ? null : () => _save(true), icon: const Icon(Icons.send), label: Text(tr('Envoyer'))),
          ]),
        ),
      ]),
    );
  }

  Widget _signerCard(int i) {
    final r = _rows[i];
    final internal = r.userId != null;
    return Card(
      margin: const EdgeInsets.only(top: 8),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 6, 4, 10),
        child: Column(children: [
          Row(children: [
            if (_sequential) Padding(padding: const EdgeInsets.only(right: 8), child: CircleAvatar(radius: 12, child: Text(tr('${i + 1}'), style: const TextStyle(fontSize: 12)))),
            Expanded(
              child: internal
                  ? DropdownButtonFormField<int>(
                      initialValue: r.userId,
                      isExpanded: true,
                      decoration: InputDecoration(labelText: tr('Utilisateur (signe dans l\'application)')),
                      items: [for (final u in widget.users) DropdownMenuItem(value: (u['id'] as num).toInt(), child: Text(tr('${u['name']}')))],
                      onChanged: (v) => setState(() {
                        r.userId = v;
                        r.name.text = '${widget.users.firstWhere((u) => u['id'] == v)['name']}';
                      }),
                    )
                  : TextField(controller: r.name, decoration: InputDecoration(labelText: tr('Nom du signataire *'))),
            ),
            const SizedBox(width: 8),
            Expanded(child: TextField(controller: r.label, decoration: InputDecoration(labelText: tr('Qualité'), hintText: tr('Le Salarié, Pour le client…')))),
            if (_rows.length > 1) IconButton(tooltip: tr('Retirer'), icon: const Icon(Icons.close), onPressed: () => setState(() => _rows.removeAt(i))),
          ]),
          if (!internal)
            Row(children: [
              Expanded(child: TextField(controller: r.phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone (WhatsApp / SMS)')))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: r.email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: tr('E-mail')))),
            ]),
        ]),
      ),
    );
  }
}
