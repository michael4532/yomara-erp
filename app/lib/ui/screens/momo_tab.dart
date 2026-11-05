import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Banque & trésorerie → Mobile Money : SMS de confirmation reçus, rapprochés automatiquement des factures.
class MomoTab extends StatefulWidget {
  const MomoTab({super.key});

  @override
  State<MomoTab> createState() => _MomoTabState();
}

const _momoColors = {'wave': Color(0xFF1DC3F0), 'orange': Color(0xFFFF7900), 'mtn': Color(0xFFFFCB05), 'moov': Color(0xFF0066B3)};
const _momoStatus = {'pending': 'À traiter', 'matched': 'Rapproché', 'ignored': 'Ignoré', 'outgoing': 'Sortie', 'duplicate': 'Doublon'};

class _MomoTabState extends State<MomoTab> {
  Map<String, dynamic>? _d;
  Object? _error;
  String _status = 'pending';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('tresorerie/momo', query: {if (_status != 'all') 'status': _status});
      if (mounted) setState(() => (_d = asMap(r), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _act(String path, [Map<String, dynamic>? body, String? ok]) async {
    try {
      await context.read<Session>().api.post(path, body);
      if (!mounted) return;
      if (ok != null) toast(context, ok);
      context.read<SyncEngine>().sync();
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _setup() async {
    final api = context.read<Session>().api;
    final accounts = [for (final a in (_d?['accounts'] as List? ?? const [])) asMap(a)];
    if (accounts.isEmpty) return toast(context, tr('Créez d\'abord un compte de type Mobile Money (onglet Comptes).'), error: true);
    await showFormDialog(
      context,
      title: tr('Recevoir les SMS Mobile Money'),
      maxWidth: 640,
      builder: (ctx) => StatefulBuilder(builder: (ctx, set) {
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          Text(tr('Sur le téléphone qui reçoit les confirmations Wave, Orange Money, MTN ou Moov, installez une application de transfert de SMS '
              '(par exemple « SMS Forwarder » ou « SMS to URL Forwarder » sur Android) et envoyez les SMS de l\'opérateur vers l\'adresse ci-dessous. '
              'Chaque encaissement est lu, puis rapproché de la facture du client : n° de facture cité, ou numéro du client et montant de l\'échéance.')),
          const SizedBox(height: 12),
          for (final a in accounts)
            Card(
              child: ListTile(
                leading: const Icon(Icons.phone_android),
                title: Text(tr('${a['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: SelectableText(tr(a['url'] == null ? 'Adresse pas encore créée' : '${a['url']}'), style: const TextStyle(fontSize: 12)),
                trailing: Wrap(spacing: 4, children: [
                  if (a['url'] != null)
                    IconButton(tooltip: tr('Copier'), onPressed: () => Clipboard.setData(ClipboardData(text: '${a['url']}')).then((_) => ctx.mounted ? toast(ctx, tr('Adresse copiée.')) : null), icon: const Icon(Icons.copy)),
                  TextButton(
                    onPressed: () async {
                      if (a['url'] != null && !await confirm(ctx, tr('Changer l\'adresse ?'), tr('L\'ancienne adresse ne fonctionnera plus : mettez à jour l\'application du téléphone.'))) return;
                      try {
                        final r = await api.post('tresorerie/accounts/${a['id']}/momo-token', {'renew': a['url'] != null});
                        set(() => a['url'] = (r['data'] as Map)['url']);
                        _load();
                      } catch (e) {
                        if (ctx.mounted) toast(ctx, errorText(e), error: true);
                      }
                    },
                    child: Text(tr(a['url'] == null ? 'Créer l\'adresse' : 'Changer')),
                  ),
                ]),
              ),
            ),
          const SizedBox(height: 8),
          Text(tr('Format accepté : JSON ou formulaire avec le texte du SMS (champ message, text ou body) et l\'expéditeur (from ou sender).'), style: TextStyle(fontSize: 12, color: Theme.of(ctx).hintColor)),
        ]);
      }),
    );
  }

  Future<void> _paste() async {
    final accounts = [for (final a in (_d?['accounts'] as List? ?? const [])) asMap(a)];
    if (accounts.isEmpty) return toast(context, tr('Créez d\'abord un compte de type Mobile Money.'), error: true);
    var account = '${accounts.first['id']}';
    final text = TextEditingController();
    Map<String, dynamic>? preview;
    final api = context.read<Session>().api;
    await showFormDialog(
      context,
      title: tr('Coller un SMS de confirmation'),
      maxWidth: 560,
      builder: (ctx) => StatefulBuilder(builder: (ctx, set) {
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
          if (accounts.length > 1)
            Field(
              label: tr('Compte'),
              child: DropdownButtonFormField<String>(initialValue: account, items: [for (final a in accounts) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}')))], onChanged: (v) => account = v ?? account),
            ),
          TextField(
            controller: text,
            maxLines: 5,
            decoration: InputDecoration(hintText: tr('Vous avez reçu 25 000 FCFA de … (07 …). Ref : …')),
            onChanged: (v) async {
              if (v.trim().length < 20) return;
              try {
                final r = await api.post('tresorerie/momo/test', {'text': v});
                set(() => preview = asMap(r['data']));
              } catch (_) {}
            },
          ),
          if (preview != null)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Wrap(spacing: 8, runSpacing: 6, children: [
                StatusChip(preview!['direction'] == 'in' ? 'Encaissement' : (preview!['direction'] == 'out' ? 'Sortie' : 'Non reconnu'), color: preview!['direction'] == 'in' ? YColors.success : YColors.warning),
                if (preview!['amount'] != null) StatusChip(money(toDouble(preview!['amount']), context.read<Session>().currency), color: YColors.blue),
                if (preview!['phone'] != null) StatusChip('${preview!['phone']}', icon: Icons.phone),
                if (preview!['name'] != null) StatusChip('${preview!['name']}', icon: Icons.person),
                if (preview!['reference'] != null) StatusChip(tr('Réf. ${preview!['reference']}')),
              ]),
            ),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              onPressed: () async {
                try {
                  final r = await api.post('tresorerie/momo', {'account_id': account, 'text': text.text});
                  if (!ctx.mounted || !mounted) return;
                  Navigator.pop(ctx);
                  final st = '${(r['data'] as Map)['status']}';
                  toast(context, st == 'matched' ? 'Encaissement rapproché automatiquement.' : 'SMS enregistré : ${_momoStatus[st] ?? st}.');
                  _load();
                } catch (e) {
                  if (ctx.mounted) toast(ctx, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ),
        ]);
      }),
    );
  }

  Future<void> _other(Map<String, dynamic> m) async {
    final label = TextEditingController(text: 'Encaissement ${m['payer_name'] ?? m['phone'] ?? ''}'.trim());
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr('Recette hors facture')),
        content: SizedBox(width: 420, child: TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé (apport, remboursement, avance…)')))),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok == true) await _act('tresorerie/momo/${m['id']}/operation', {'category': 'other_income', 'label': label.text.trim()}, 'Recette enregistrée.');
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final rows = [for (final r in _d!['data'] as List) asMap(r)];
    final counts = asMap(_d!['counts'] is Map ? _d!['counts'] : const {});
    final configured = (_d!['accounts'] as List? ?? const []).any((a) => (a as Map)['url'] != null);
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        if (!configured)
          Card(
            color: YColors.blue.withValues(alpha: .06),
            child: ListTile(
              leading: const Icon(Icons.sms_outlined, color: YColors.blue),
              title: Text(tr('Encaissez sans saisie : vos SMS Wave, Orange Money, MTN et Moov rapprochés tout seuls')),
              subtitle: Text(tr('Branchez le téléphone marchand une fois ; chaque paiement reçu solde la bonne facture ou l\'échéance.')),
              trailing: FilledButton(onPressed: _setup, child: Text(tr('Configurer'))),
            ),
          ),
        Row(children: [
          Expanded(
            child: Wrap(spacing: 6, runSpacing: 6, children: [
              for (final e in {'pending': 'À traiter', 'matched': 'Rapprochés', 'ignored': 'Ignorés', 'outgoing': 'Sorties', 'all': 'Tous'}.entries)
                ChoiceChip(
                  label: Text(tr('${e.value}${e.key != 'all' && counts[e.key] != null ? ' (${counts[e.key]})' : ''}')),
                  selected: _status == e.key,
                  onSelected: (_) {
                    setState(() => _status = e.key);
                    _load();
                  },
                ),
            ]),
          ),
          IconButton(tooltip: tr('Coller un SMS'), onPressed: _paste, icon: const Icon(Icons.content_paste)),
          IconButton(tooltip: tr('Configurer la réception'), onPressed: _setup, icon: const Icon(Icons.settings_input_antenna)),
        ]),
        const SizedBox(height: 8),
        if (rows.isEmpty)
          EmptyState(icon: Icons.mark_email_read_outlined, title: _status == 'pending' ? 'Rien à traiter' : 'Aucun SMS', message: tr('Les encaissements reçus par SMS apparaissent ici ; ceux qui sont sûrs sont rapprochés automatiquement.')),
        for (final m in rows)
          AnimatedFadeIn(
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    CircleAvatar(
                      radius: 18,
                      backgroundColor: (_momoColors[m['provider']] ?? YColors.blue).withValues(alpha: .15),
                      child: Text(tr('${m['provider_label'] ?? 'MM'}'.substring(0, 1)), style: TextStyle(fontWeight: FontWeight.w800, color: _momoColors[m['provider']] ?? YColors.blue)),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(
                          tr([if (m['amount'] != null) money(toDouble(m['amount']), cur), m['payer_name'] ?? m['phone'] ?? 'Expéditeur inconnu'].join(' · ')),
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        Text(tr([dateTimeFr(m['received_at']), m['provider_label'] ?? m['sender'], if (m['phone'] != null && m['payer_name'] != null) m['phone'], if (m['reference'] != null) 'réf. ${m['reference']}'].whereType<Object>().join(' · ')),
                            style: TextStyle(fontSize: 12.5, color: hint)),
                      ]),
                    ),
                    StatusChip(_momoStatus[m['status']] ?? '${m['status']}', color: switch ('${m['status']}') { 'matched' => YColors.success, 'pending' => YColors.warning, _ => YColors.muted }),
                  ]),
                  const SizedBox(height: 8),
                  Text(tr('${m['text']}'), style: TextStyle(fontSize: 12.5, color: hint, fontStyle: FontStyle.italic), maxLines: 3, overflow: TextOverflow.ellipsis),
                  if (m['status'] == 'matched')
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(tr('${m['document_number'] != null ? 'Facture ${m['document_number']} · ' : ''}${m['match_reason'] ?? ''}${m['handled_by'] != null ? ' · ${m['handled_by']}' : ''}'),
                          style: const TextStyle(fontSize: 12.5, color: YColors.success, fontWeight: FontWeight.w600)),
                    ),
                  if (m['status'] == 'pending') ...[
                    const SizedBox(height: 8),
                    for (final c in (m['candidates'] as List? ?? const []))
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(c['exact'] == true ? Icons.task_alt : Icons.help_outline, color: c['exact'] == true ? YColors.success : hint),
                        title: Text(tr('${c['number']} — ${c['partner_name']}')),
                        subtitle: Text(tr('Reste dû ${money(toDouble(c['balance']), cur)}${toDouble(c['due_now']) < toDouble(c['balance']) ? ' · échéance ${money(toDouble(c['due_now']), cur)}' : ''} · ${c['reason']}')),
                        trailing: FilledButton.tonal(onPressed: () => _act('tresorerie/momo/${m['id']}/assign', {'document_id': c['id']}, 'Encaissement affecté à ${c['number']}.'), child: Text(tr('Affecter'))),
                      ),
                    Wrap(spacing: 8, children: [
                      OutlinedButton.icon(onPressed: () => _other(m), icon: const Icon(Icons.add_card, size: 18), label: Text(tr('Recette hors facture'))),
                      TextButton(onPressed: () => _act('tresorerie/momo/${m['id']}/ignore', null, 'SMS ignoré.'), child: Text(tr('Ignorer'))),
                    ]),
                  ],
                  if (m['status'] == 'ignored')
                    Align(alignment: Alignment.centerRight, child: TextButton(onPressed: () => _act('tresorerie/momo/${m['id']}/ignore', {'restore': true}), child: Text(tr('Remettre à traiter')))),
                ]),
              ),
            ),
          ),
      ]),
    );
  }
}
