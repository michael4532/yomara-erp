import 'dart:convert';

import 'package:barcode/barcode.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/fne.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Facture normalisée électronique (FNE) — DGI Côte d'Ivoire :
/// suivi des certifications, relances, paramètres de connexion.
class FneScreen extends StatefulWidget {
  const FneScreen({super.key});

  @override
  State<FneScreen> createState() => _FneScreenState();
}

class _FneScreenState extends State<FneScreen> {
  String _filter = 'todo';
  String _q = '';
  FilterPeriod? _period;
  Map<String, dynamic>? _settings;
  Object? _error;
  List<Map<String, dynamic>> _uncertified = [];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final s = asMap((await api.get('fne/settings'))['data']);
      final u = (await api.get('fne/uncertified'))['data'] as List;
      if (!mounted) return;
      setState(() {
        _settings = s;
        _uncertified = [for (final d in u) asMap(d)];
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _processNow() async {
    setState(() => _busy = true);
    try {
      final r = (await context.read<Session>().api.post('fne/process'))['data'] as Map;
      if (!mounted) return;
      await context.read<SyncEngine>().sync();
      if (mounted) toast(context, tr('${r['certified']} certifiée(s), ${r['failed']} refusée(s), ${r['pending']} toujours en attente.'));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final manage = session.can('fne.manage');
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final s = _settings;

    return Column(children: [
      PageHeader(
        title: tr('Facture normalisée (FNE)'),
        subtitle: tr('Certification électronique DGI des factures, tickets et avoirs'),
        actions: [
          if (manage) OutlinedButton.icon(onPressed: _busy ? null : _processNow, icon: const Icon(Icons.sync), label: Text(tr('Envoyer la file maintenant'))),
          if (manage)
            FilledButton.icon(
              onPressed: () async {
                if (await fneSettingsDialog(context)) _load();
              },
              icon: const Icon(Icons.tune),
              label: Text(tr('Paramètres DGI')),
            ),
        ],
      ),
      if (s != null && s['enabled'] != true)
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
          child: _Banner(
            color: YColors.warning,
            icon: Icons.info_outline,
            text: tr('La FNE n\'est pas encore activée. Renseignez la clé API remise par la DGI (environnement de test d\'abord) dans les paramètres.'),
          ),
        )
      else if (s != null && s['environment'] == 'test')
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
          child: _Banner(color: YColors.blue, icon: Icons.science_outlined, text: tr('Environnement de TEST DGI : les factures certifiées ici n\'ont pas de valeur fiscale. Passez en production une fois votre intégration validée par la DGI.')),
        ),
      if (_error != null && s == null)
        Padding(padding: EdgeInsets.symmetric(horizontal: pad), child: _Banner(color: YColors.muted, icon: Icons.cloud_off, text: tr('Hors ligne : seules les certifications déjà synchronisées sont affichées.'))),
      if (s != null)
        StatStrip(
          padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
          tiles: [
            StatTile(label: 'Certifiées ce mois', value: '${s['stats']['certified_month']}', icon: Icons.verified_outlined, color: YColors.success),
            StatTile(label: 'En attente d\'envoi', value: '${s['stats']['pending']}', icon: Icons.schedule_send, color: YColors.blue),
            StatTile(label: 'Refusées à corriger', value: '${s['stats']['failed']}', icon: Icons.error_outline, color: YColors.danger),
            StatTile(label: 'Non certifiées (90 j)', value: '${s['stats']['uncertified']}', icon: Icons.receipt_long_outlined, color: YColors.warning),
            if (s['sticker_balance'] != null)
              StatTile(
                label: 'Stickers restants',
                value: '${s['sticker_balance']}',
                icon: Icons.confirmation_number_outlined,
                color: (s['sticker_balance'] as num) <= (s['sticker_alert'] as num? ?? 100) ? YColors.danger : YColors.night,
              ),
          ],
        ),
      FilterBar(
        padding: EdgeInsets.fromLTRB(pad, 0, pad, 8),
        onSearch: (v) => setState(() => _q = v.toLowerCase()),
        search: _q,
        searchHint: 'N° de facture, client, N° FNE…',
        statuses: const {'todo': 'À traiter', 'certified': 'Certifiées', 'uncertified': 'Non certifiées', '': 'Toutes'},
        status: _filter == 'all' ? '' : _filter,
        onStatus: (v) => setState(() => _filter = v.isEmpty ? 'all' : v),
        showPeriod: true,
        period: _period,
        onPeriod: (p) => setState(() => _period = p),
        onReset: () => setState(() => (_q = '', _period = null, _filter = 'todo')),
      ),
      Expanded(child: _filter == 'uncertified' ? _uncertifiedList(session, manage) : _certList(session)),
    ]);
  }

  Widget _certList(Session session) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('fne_certifications'),
        builder: (context, _, _) {
          final all = LocalDb.all('fne_certifications')
              .where((c) => switch (_filter) {
                    'todo' => c['status'] != 'certified',
                    'certified' => c['status'] == 'certified',
                    _ => true,
                  })
              .where((c) => _period == null || _period!.contains(c['created_at']))
              .where((c) => _q.isEmpty || '${c['document_number'] ?? ''} ${c['reference'] ?? ''} ${_docOf(c)?['partner_name'] ?? ''}'.toLowerCase().contains(_q))
              .toList()
            ..sort((a, b) => '${b['created_at']}'.compareTo('${a['created_at']}'));
          if (all.isEmpty) {
            return EmptyState(
              icon: _filter == 'todo' ? Icons.task_alt : Icons.verified_outlined,
              title: _filter == 'todo' ? 'Rien à traiter' : 'Aucune certification',
              message: _filter == 'todo' ? 'Toutes les factures transmises ont été certifiées par la DGI.' : 'Les factures validées apparaîtront ici dès leur certification.',
            );
          }
          return ResponsiveList<Map<String, dynamic>>(
            items: all,
            columns: const ['Document', 'Tiers', 'Modèle', 'N° FNE', 'Statut'],
            onTap: (c) => showFneDetail(context, c),
            cells: (c) {
              final doc = _docOf(c);
              return [
                Text(tr('${_typeLabel(c)} ${c['document_number'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                Row(children: [InitialsAvatar('${doc?['partner_name'] ?? '—'}'), const SizedBox(width: 10), Flexible(child: Text(tr('${doc?['partner_name'] ?? '—'}'), overflow: TextOverflow.ellipsis))]),
                Text(tr(c['invoice_type'] == 'refund' ? 'Avoir' : '${c['template'] ?? '—'}')),
                Text(tr('${c['reference'] ?? '—'}')),
                fneChip(c),
              ];
            },
            mobileTile: (c) {
              final doc = _docOf(c);
              return Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${_typeLabel(c)} ${c['document_number'] ?? ''} · ${doc?['partner_name'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    const SizedBox(height: 2),
                    Text(tr(c['status'] == 'certified' ? 'N° ${c['reference'] ?? ''}' : '${c['last_error'] ?? 'En file d\'attente'}'),
                        maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                  ]),
                ),
                fneChip(c),
              ]);
            },
          );
        },
      );

  Widget _uncertifiedList(Session session, bool manage) {
    if (_settings == null) return OnlineOnlyNotice(error: _error ?? 'Chargement…', onRetry: _load);
    if (_uncertified.isEmpty) {
      return EmptyState(icon: Icons.task_alt, title: tr('Tout est certifié'), message: tr('Aucune facture, aucun ticket ni avoir validé ces 90 derniers jours n\'échappe à la FNE.'));
    }
    return ResponsiveList<Map<String, dynamic>>(
      items: _uncertified,
      columns: const ['Document', 'Date', 'Tiers', 'Total TTC', ''],
      cells: (d) => [
        Text(tr('${_docTypes[d['type']] ?? d['type']} ${d['number'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
        Text(tr(dateFr(d['date']))),
        Row(children: [InitialsAvatar('${d['partner_name'] ?? '—'}'), const SizedBox(width: 10), Flexible(child: Text(tr('${d['partner_name'] ?? '—'}'), overflow: TextOverflow.ellipsis))]),
        Text(tr(money(toDouble(d['total']), session.currency))),
        manage ? TextButton.icon(onPressed: () => _certify(d), icon: const Icon(Icons.verified_outlined, size: 18), label: Text(tr('Certifier'))) : const SizedBox(),
      ],
      mobileTile: (d) => Row(children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('${_docTypes[d['type']] ?? d['type']} ${d['number'] ?? ''} · ${d['partner_name'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w600)),
            Text(tr('${dateFr(d['date'])} · ${money(toDouble(d['total']), session.currency)}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
          ]),
        ),
        if (manage) IconButton(tooltip: tr('Certifier'), onPressed: () => _certify(d), icon: const Icon(Icons.verified_outlined)),
      ]),
    );
  }

  Future<void> _certify(Map<String, dynamic> d) async {
    final ok = await fneCertifyDialog(context, documentId: '${d['id']}', title: '${_docTypes[d['type']] ?? ''} ${d['number'] ?? ''}');
    if (ok) _load();
  }
}

const _docTypes = {'invoice': 'Facture', 'receipt': 'Ticket', 'credit_note': 'Avoir', 'purchase': 'Bordereau d\'achat'};

String _typeLabel(Map<String, dynamic> c) => _docTypes[c['document_type']] ?? '${c['document_type']}';

Map<String, dynamic>? _docOf(Map<String, dynamic> c) {
  final entity = c['document_type'] == 'purchase' ? 'purchase_documents' : 'sales_documents';
  return LocalDb.hasEntity(entity) ? LocalDb.get(entity, c['document_id'] as String?) : null;
}

Widget fneChip(Map<String, dynamic> c) => switch (c['status']) {
      'certified' => StatusChip(tr('Certifiée FNE'), color: YColors.success, icon: Icons.verified),
      'failed' => StatusChip(tr('FNE refusée'), color: YColors.danger, icon: Icons.error_outline),
      _ => StatusChip(tr('FNE en attente'), color: YColors.blue, icon: Icons.schedule_send),
    };

class _Banner extends StatelessWidget {
  const _Banner({required this.color, required this.icon, required this.text});

  final Color color;
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: color.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(10), border: Border.all(color: color.withValues(alpha: 0.3))),
        child: Row(children: [Icon(icon, color: color, size: 20), const SizedBox(width: 10), Expanded(child: Text(tr(text)))]),
      );
}

// ---------------------------------------------------------------------------
// QR code (dessiné localement, hors ligne)
// ---------------------------------------------------------------------------

class QrView extends StatelessWidget {
  const QrView(this.data, {super.key, this.size = 120});

  final String data;
  final double size;

  @override
  Widget build(BuildContext context) => Container(
        color: Colors.white,
        padding: const EdgeInsets.all(6),
        child: SizedBox(width: size, height: size, child: CustomPaint(painter: _QrPainter(data))),
      );
}

class _QrPainter extends CustomPainter {
  _QrPainter(this.data);

  final String data;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = Colors.black;
    for (final e in Barcode.qrCode().make(data, width: size.width, height: size.height)) {
      if (e is BarcodeBar && e.black) canvas.drawRect(Rect.fromLTWH(e.left, e.top, e.width, e.height), paint);
    }
  }

  @override
  bool shouldRepaint(_QrPainter old) => old.data != data;
}

// ---------------------------------------------------------------------------
// Panneau dans la fiche document
// ---------------------------------------------------------------------------

/// Statut FNE d'un document validé (facture, ticket, avoir, facture fournisseur).
class FnePanel extends StatelessWidget {
  const FnePanel({super.key, required this.document, this.purchase = false});

  final Map<String, dynamic> document;
  final bool purchase;

  static bool applies(Session s, Map<String, dynamic> doc, {bool purchase = false}) =>
      s.hasModule('fne') &&
      doc['status'] == 'validated' &&
      doc['number'] != null &&
      (purchase ? doc['type'] == 'supplier_invoice' : ['invoice', 'receipt', 'credit_note'].contains(doc['type'])) &&
      (s.can('fne.view') || s.can('fne.manage') || s.can('ventes.view'));

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final manage = session.can('fne.manage');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('fne_certifications'),
      builder: (context, _, _) {
        final c = fneCertFor(document['id'] as String?);
        if (c == null) {
          if (purchase && !manage) return const SizedBox();
          return SectionCard(
            title: purchase ? 'Bordereau d\'achat FNE' : 'Facture normalisée (FNE)',
            child: Row(children: [
              Icon(Icons.info_outline, color: Theme.of(context).hintColor),
              const SizedBox(width: 10),
              Expanded(
                child: Text(tr(purchase
                    ? 'Fournisseur sans facture normalisée (producteur, informel) ? Émettez un bordereau d\'achat certifié par la DGI.'
                    : 'Ce document n\'a pas été transmis à la DGI.')),
              ),
              if (manage)
                FilledButton.tonalIcon(
                  onPressed: () => fneCertifyDialog(context, documentId: '${document['id']}', title: '${document['number']}', purchase: purchase),
                  icon: const Icon(Icons.verified_outlined),
                  label: Text(tr('Certifier')),
                ),
            ]),
          );
        }
        return SectionCard(
          title: c['invoice_type'] == 'refund' ? 'Avoir normalisé (FNE)' : (purchase ? 'Bordereau d\'achat FNE' : 'Facture normalisée (FNE)'),
          trailing: fneChip(c),
          child: _CertBody(cert: c, manage: manage),
        );
      },
    );
  }
}

class _CertBody extends StatelessWidget {
  const _CertBody({required this.cert, required this.manage});

  final Map<String, dynamic> cert;
  final bool manage;

  @override
  Widget build(BuildContext context) {
    final c = cert;
    final hint = TextStyle(color: Theme.of(context).hintColor);
    if (c['status'] == 'certified') {
      final info = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr('N° FNE ${c['reference'] ?? '—'}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        const SizedBox(height: 4),
        Text(tr([
          if (c['certified_at'] != null) 'Certifiée le ${dateTimeFr(c['certified_at'])}',
          if (c['template'] != null) fneTemplateLabels[c['template']] ?? c['template'],
          if (c['payment_method'] != null) fnePaymentLabels[c['payment_method']] ?? c['payment_method'],
          if (c['environment'] == 'test') 'environnement de TEST',
        ].join(' · ')), style: hint),
        if (c['warning'] != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Icon(Icons.warning_amber_rounded, size: 16, color: YColors.warning),
              const SizedBox(width: 6),
              Expanded(child: Text(tr('${c['warning']}'), style: const TextStyle(color: YColors.warning))),
            ]),
          ),
        if (c['verify_url'] != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: TextButton.icon(
              style: TextButton.styleFrom(padding: EdgeInsets.zero),
              onPressed: () => safeLaunchUrl(Uri.parse('${c['verify_url']}'), mode: LaunchMode.externalApplication),
              icon: const Icon(Icons.open_in_new, size: 16),
              label: Text(tr('Vérifier sur le site de la DGI')),
            ),
          ),
      ]);
      return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (c['verify_url'] != null) ...[QrView('${c['verify_url']}', size: 96), const SizedBox(width: 16)],
        Expanded(child: info),
      ]);
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text(
        tr(c['status'] == 'failed' ? '${c['last_error'] ?? 'Refusée par la DGI.'}' : (c['last_error'] != null ? '${c['last_error']}' : 'En file d\'attente : envoi automatique à la DGI dès que possible.')),
        style: TextStyle(color: c['status'] == 'failed' ? YColors.danger : null),
      ),
      if (c['next_attempt_at'] != null && c['status'] == 'pending') Text(tr('Prochain essai : ${dateTimeFr(c['next_attempt_at'])}'), style: hint),
      if (manage) ...[
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 8, children: [
          FilledButton.tonalIcon(onPressed: () => fneRetryDialog(context, c), icon: const Icon(Icons.refresh), label: Text(tr('Relancer'))),
          OutlinedButton.icon(onPressed: () => fneManualDialog(context, c), icon: const Icon(Icons.edit_note), label: Text(tr('Saisir la référence DGI'))),
        ]),
      ],
    ]);
  }
}

Future<void> showFneDetail(BuildContext context, Map<String, dynamic> c) async {
  final manage = context.read<Session>().can('fne.manage');
  final doc = _docOf(c);
  await showDialog<void>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Row(children: [Expanded(child: Text(tr('${_typeLabel(c)} ${c['document_number'] ?? ''}'))), fneChip(c)]),
      content: SizedBox(
        width: 520,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (doc != null) Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(tr('${doc['partner_name'] ?? 'Client comptant'} · ${money(toDouble(doc['total']), context.read<Session>().currency)} · ${dateFr(doc['date'])}'))),
            _CertBody(cert: c, manage: manage),
            if (manage && c['status'] != 'pending') ...[
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(onPressed: () => _showExchange(d, '${c['id']}'), icon: const Icon(Icons.code, size: 18), label: Text(tr('Données échangées avec la DGI'))),
              ),
            ],
          ]),
        ),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Fermer')))],
    ),
  );
}

Future<void> _showExchange(BuildContext context, String id) async {
  try {
    final r = asMap((await context.read<Session>().api.get('fne/certifications/$id'))['data']);
    if (!context.mounted) return;
    String pretty(Object? o) => o == null ? '—' : const JsonEncoder.withIndent('  ').convert(o);
    await showDialog<void>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr('Échange avec la plateforme FNE')),
        content: SizedBox(
          width: 640,
          child: SingleChildScrollView(
            child: SelectableText(tr('REQUÊTE\n${pretty(r['payload'])}\n\nRÉPONSE\n${pretty(r['response'])}'), style: const TextStyle(fontFamily: 'monospace', fontSize: 12)),
          ),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Fermer')))],
      ),
    );
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------

Future<bool> fneCertifyDialog(BuildContext context, {required String documentId, required String title, bool purchase = false}) =>
    _overrideDialog(context, title: purchase ? 'Bordereau d\'achat FNE — $title' : 'Certifier $title', confirm: 'Envoyer à la DGI', run: (template, payment) async {
      return asMap((await context.read<Session>().api.post('fne/certify', {
        'document_id': documentId,
        'direction': purchase ? 'purchase' : 'sale',
        'template': ?template,
        'payment_method': ?payment,
      }))['data']);
    });

Future<bool> fneRetryDialog(BuildContext context, Map<String, dynamic> c) => _overrideDialog(
      context,
      title: tr('Relancer la certification'),
      confirm: 'Relancer',
      refund: c['invoice_type'] == 'refund',
      initialTemplate: c['template'] as String?,
      initialPayment: c['payment_method'] as String?,
      run: (template, payment) async => asMap((await context.read<Session>().api.post('fne/certifications/${c['id']}/retry', {
        'template': ?template,
        'payment_method': ?payment,
      }))['data']),
    );

Future<bool> _overrideDialog(
  BuildContext context, {
  required String title,
  required String confirm,
  required Future<Map<String, dynamic>> Function(String? template, String? payment) run,
  bool refund = false,
  String? initialTemplate,
  String? initialPayment,
}) async {
  String? template = initialTemplate;
  String? payment = initialPayment;
  var busy = false;
  final result = await showDialog<Map<String, dynamic>>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr(title)),
        content: SizedBox(
          width: 460,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (refund)
              Text(tr('L\'avoir est rattaché ligne par ligne à la facture d\'origine certifiée. Corrigez l\'avoir si une ligne ne correspond pas.'))
            else ...[
              Text(tr('Par défaut, le modèle est déduit du client (client avec NCC : B2B, à l\'étranger : B2F, sinon B2C) et le moyen de paiement des encaissements.')),
              const SizedBox(height: 12),
              DropdownButtonFormField<String?>(
                initialValue: template,
                decoration: InputDecoration(labelText: tr('Modèle de facture')),
                items: [DropdownMenuItem(value: null, child: Text(tr('Automatique'))), for (final e in fneTemplateLabels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => set(() => template = v),
              ),
              const SizedBox(height: 10),
              DropdownButtonFormField<String?>(
                initialValue: payment,
                decoration: InputDecoration(labelText: tr('Moyen de paiement')),
                items: [DropdownMenuItem(value: null, child: Text(tr('Automatique'))), for (final e in fnePaymentLabels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => set(() => payment = v),
              ),
            ],
          ]),
        ),
        actions: [
          TextButton(onPressed: busy ? null : () => Navigator.pop(d), child: Text(tr('Annuler'))),
          FilledButton(
            onPressed: busy
                ? null
                : () async {
                    set(() => busy = true);
                    try {
                      final r = await run(template, payment);
                      if (d.mounted) Navigator.pop(d, r);
                    } catch (e) {
                      set(() => busy = false);
                      if (d.mounted) toast(d, errorText(e), error: true);
                    }
                  },
            child: busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Text(tr(confirm)),
          ),
        ],
      ),
    ),
  );
  if (result == null) return false;
  await LocalDb.put('fne_certifications', result);
  if (context.mounted) {
    toast(context, switch (result['status']) {
      'certified' => 'Certifiée par la DGI : N° ${result['reference']}.',
      'failed' => 'Refusée : ${result['last_error']}',
      _ => 'En file d\'attente : ${result['last_error'] ?? 'envoi automatique dès que possible.'}',
    }, error: result['status'] == 'failed');
  }
  return true;
}

Future<void> fneManualDialog(BuildContext context, Map<String, dynamic> c) async {
  final ref = TextEditingController();
  final token = TextEditingController();
  final ok = await showDialog<bool>(
    context: context,
    builder: (d) => YAlertDialog(
      title: Text(tr('Saisir la certification DGI')),
      content: SizedBox(
        width: 460,
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(tr('Si la facture a été certifiée directement sur le portail FNE (ou si la réponse de la DGI n\'est pas parvenue), recopiez sa référence pour ne pas la certifier deux fois.')),
          const SizedBox(height: 12),
          TextField(controller: ref, decoration: InputDecoration(labelText: tr('Référence FNE *'))),
          const SizedBox(height: 10),
          TextField(controller: token, decoration: InputDecoration(labelText: tr('Lien ou code de vérification (QR code)'))),
        ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
    ),
  );
  if (ok != true || ref.text.trim().isEmpty || !context.mounted) return;
  try {
    final r = asMap((await context.read<Session>().api.post('fne/certifications/${c['id']}/manual', {
      'reference': ref.text.trim(),
      if (token.text.trim().isNotEmpty) 'token': token.text.trim(),
    }))['data']);
    await LocalDb.put('fne_certifications', r);
    if (context.mounted) toast(context, tr('Certification enregistrée.'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

/// Paramètres de connexion à la plateforme FNE. Renvoie true si enregistrés.
Future<bool> fneSettingsDialog(BuildContext context) async {
  final api = context.read<Session>().api;
  Map<String, dynamic> s;
  try {
    s = asMap((await api.get('fne/settings'))['data']);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return false;
  }
  if (!context.mounted) return false;
  var enabled = s['enabled'] == true;
  var env = '${s['environment'] ?? 'test'}';
  var template = '${s['default_template'] ?? 'B2C'}';
  var zero = '${s['zero_tax_code'] ?? 'TVAD'}';
  var auto = s['auto_certify'] != false;
  var receipts = s['certify_receipts'] != false;
  var credits = s['certify_credit_notes'] != false;
  final key = TextEditingController();
  final url = TextEditingController(text: '${s['base_url'] ?? ''}');
  final pos = TextEditingController(text: '${s['point_of_sale'] ?? ''}');
  final est = TextEditingController(text: '${s['establishment'] ?? ''}');
  final msg = TextEditingController(text: '${s['commercial_message'] ?? ''}');
  final footer = TextEditingController(text: '${s['footer'] ?? ''}');
  final alert = TextEditingController(text: '${s['sticker_alert'] ?? 100}');

  final saved = await showFormDialog<bool>(
    context,
    title: tr('Paramètres FNE (DGI)'),
    builder: (d) => StatefulBuilder(
      builder: (d, set) => Column(children: [
        Expanded(
          child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 8), children: [
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Activer la facture normalisée électronique')),
              subtitle: Text(tr('Les factures validées sont transmises à la DGI et reçoivent leur QR code.')),
              value: enabled,
              onChanged: (v) => set(() => enabled = v),
            ),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: [ButtonSegment(value: 'test', label: Text(tr('Test')), icon: Icon(Icons.science_outlined)), ButtonSegment(value: 'prod', label: Text(tr('Production')), icon: Icon(Icons.verified_outlined))],
              selected: {env},
              onSelectionChanged: (v) => set(() => env = v.first),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: url,
              decoration: InputDecoration(
                labelText: trn(env == 'prod' ? 'URL de production (communiquée par la DGI) *' : 'URL de test (facultatif)'),
                helperText: trn(env == 'prod' ? 'Exemple : https://…/ws' : 'Par défaut : ${s['test_url']}'),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: key,
              obscureText: true,
              decoration: InputDecoration(
                labelText: tr('Clé API'),
                helperText: trn(s['has_api_key'] == true ? 'Clé enregistrée (${s['api_key_hint']}). Laissez vide pour la conserver.' : 'Fournie par la DGI dans votre espace FNE (Paramètres > Intégration API).'),
              ),
            ),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: TextField(controller: est, decoration: InputDecoration(labelText: tr('Établissement')))),
              const SizedBox(width: 10),
              Expanded(child: TextField(controller: pos, decoration: InputDecoration(labelText: tr('Point de vente')))),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(
                child: DropdownButtonFormField<String>(
                  initialValue: template,
                  decoration: InputDecoration(labelText: tr('Modèle par défaut (client sans NCC)')),
                  items: [for (final e in fneTemplateLabels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                  onChanged: (v) => set(() => template = v ?? 'B2C'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: DropdownButtonFormField<String>(
                  initialValue: zero,
                  decoration: InputDecoration(labelText: tr('Lignes à 0 % de TVA')),
                  items: [DropdownMenuItem(value: 'TVAD', child: Text(tr('TVAD — exonération légale'))), DropdownMenuItem(value: 'TVAC', child: Text(tr('TVAC — exonération conventionnelle')))],
                  onChanged: (v) => set(() => zero = v ?? 'TVAD'),
                ),
              ),
            ]),
            const SizedBox(height: 8),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Certifier automatiquement à la validation')), value: auto, onChanged: (v) => set(() => auto = v)),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Certifier les tickets de caisse')), value: receipts, onChanged: (v) => set(() => receipts = v)),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Certifier les avoirs')), subtitle: Text(tr('Remboursement rattaché à la facture d\'origine')), value: credits, onChanged: (v) => set(() => credits = v)),
            TextField(controller: msg, decoration: InputDecoration(labelText: tr('Message commercial (imprimé sur la facture DGI)'))),
            const SizedBox(height: 10),
            TextField(controller: footer, decoration: InputDecoration(labelText: tr('Pied de facture DGI'))),
            const SizedBox(height: 10),
            TextField(controller: alert, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Alerte quand il reste moins de … stickers'))),
          ]),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 16),
          child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
            TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))),
            const SizedBox(width: 8),
            SaveButton(
              onPressed: () async {
                try {
                  await api.put('fne/settings', {
                    'enabled': enabled,
                    'environment': env,
                    'base_url': url.text.trim().isEmpty ? null : url.text.trim(),
                    if (key.text.trim().isNotEmpty) 'api_key': key.text.trim(),
                    'point_of_sale': pos.text.trim().isEmpty ? null : pos.text.trim(),
                    'establishment': est.text.trim().isEmpty ? null : est.text.trim(),
                    'default_template': template,
                    'zero_tax_code': zero,
                    'auto_certify': auto,
                    'certify_receipts': receipts,
                    'certify_credit_notes': credits,
                    'commercial_message': msg.text.trim().isEmpty ? null : msg.text.trim(),
                    'footer': footer.text.trim().isEmpty ? null : footer.text.trim(),
                    'sticker_alert': int.tryParse(alert.text) ?? 100,
                  });
                  if (d.mounted) Navigator.pop(d, true);
                } catch (e) {
                  if (d.mounted) toast(d, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ]),
        ),
      ]),
    ),
  );
  if (saved == true && context.mounted) toast(context, tr('Paramètres FNE enregistrés.'));
  return saved == true;
}
