import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../core/json.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'admin_common.dart';
import '../../../i18n/i18n.dart';

String _priceSummary(Map<String, dynamic> m, String cur) {
  final mode = m['billing_mode'] ?? 'subscription';
  if (mode == 'free') return 'Gratuit';
  final parts = [
    if (mode == 'subscription' || mode == 'both') '${money(m['price_monthly'] as num, cur)} / mois · ${money(m['price_yearly'] as num, cur)} / an',
    if (mode == 'one_time' || mode == 'both') '${money((m['price_one_time'] as num?) ?? 0, cur)} achat définitif',
    if (((m['trial_days'] as num?) ?? 0) > 0) 'essai ${m['trial_days']} j',
    if (m['in_store'] == false) 'hors boutique',
  ];
  return parts.join(' · ');
}

/// Catalogue des modules : activation globale, mode de vente, prix et fiche boutique.
class AdminModulesScreen extends StatelessWidget {
  const AdminModulesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    return AdminLoader(
      path: 'admin/modules',
      builder: (context, d, reload) {
        final modules = List<Map<String, dynamic>>.from((d['data'] as List).map((e) => Map<String, dynamic>.from(e)));
        final byCategory = <String, List<Map<String, dynamic>>>{};
        for (final m in modules) {
          byCategory.putIfAbsent('${m['category'] ?? 'Autres'}', () => []).add(m);
        }
        final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;

        Future<void> update(Map<String, dynamic> m, Map<String, dynamic> body) async {
          try {
            await session.api.put('admin/modules/${m['key']}', body);
            reload();
            await session.refreshBootstrap();
          } catch (e) {
            if (context.mounted) toast(context, errorText(e), error: true);
          }
        }

        return ListView(padding: const EdgeInsets.only(bottom: 32), children: [
          PageHeader(
            title: tr('Modules & tarifs'),
            subtitle: tr('Un module désactivé ici est coupé pour toutes les sociétés. Touchez le prix pour régler la vente (prix fixe, abonnement, essai) et la fiche boutique.'),
            actions: [
              FilledButton.tonalIcon(
                onPressed: () async {
                  final c = TextEditingController(text: '10000');
                  final ok = await showDialog<bool>(
                    context: context,
                    builder: (ctx) => YAlertDialog(
                      title: Text(tr('Tarif unique des modules payants')),
                      content: SizedBox(
                        width: 420,
                        child: Column(mainAxisSize: MainAxisSize.min, children: [
                          TextField(controller: c, keyboardType: TextInputType.number, autofocus: true, decoration: InputDecoration(labelText: tr('Prix mensuel (F CFA)'))),
                          const SizedBox(height: 8),
                          Text(tr('Annuel = 10 mois, licence définitive = 24 mois. Les tarifs particuliers accordés à une société sont conservés.'), style: TextStyle(fontSize: 12, color: Theme.of(ctx).hintColor)),
                        ]),
                      ),
                      actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Appliquer à tous')))],
                    ),
                  );
                  if (ok != true) return;
                  try {
                    final r = await session.api.post('admin/modules/bulk-price', {'price_monthly': toDouble(c.text)});
                    if (context.mounted) toast(context, tr('${r['updated']} module(s) mis à jour.'));
                    reload();
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.sell_outlined),
                label: Text(tr('Tarif unique')),
              ),
              FilledButton.icon(
                onPressed: () async {
                  try {
                    final p = asMap(await session.api.post('admin/modules/align-prices'));
                    final free = [for (final m in (p['to_free'] as List? ?? const [])) asMap(m)];
                    final priced = [for (final m in (p['to_price'] as List? ?? const [])) asMap(m)];
                    if (!context.mounted) return;
                    if (free.isEmpty && priced.isEmpty) {
                      toast(context, tr('Les tarifs sont déjà alignés : rien à changer.'));
                      return;
                    }
                    final hint = Theme.of(context).hintColor;
                    final ok = await showDialog<bool>(
                      context: context,
                      builder: (ctx) => YAlertDialog(
                        title: Text(tr('Aligner les tarifs')),
                        content: SizedBox(
                          width: 480,
                          height: 420,
                          child: ListView(children: [
                            if (free.isNotEmpty) ...[
                              Text(tr('Deviennent gratuits (inclus dans la formule gratuite) : ${free.length}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                              const SizedBox(height: 6),
                              Text(free.map((m) => tr('${m['name']}')).join(', '), style: TextStyle(color: hint, fontSize: 13)),
                              const SizedBox(height: 16),
                            ],
                            if (priced.isNotEmpty) ...[
                              Text(tr('Reçoivent un prix (sans prix, ou palier Standard / Premium) : ${priced.length}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                              const SizedBox(height: 6),
                              for (final m in priced)
                                Padding(
                                  padding: const EdgeInsets.only(bottom: 4),
                                  child: Row(children: [
                                    Expanded(child: Text(tr('${m['name']}'), style: const TextStyle(fontSize: 13))),
                                    Text(tr('${m['tier']} · ${money(toDouble(m['price_monthly']), session.currency)} / mois'), style: TextStyle(color: hint, fontSize: 13)),
                                  ]),
                                ),
                              const SizedBox(height: 8),
                              Text(tr('Annuel = 10 mois, achat définitif = 24 mois, essai 14 jours. Les autres modules déjà tarifés et les tarifs accordés à une société ne changent pas.'), style: TextStyle(color: hint, fontSize: 12)),
                            ],
                          ]),
                        ),
                        actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Appliquer')))],
                      ),
                    );
                    if (ok != true) return;
                    final r = asMap(await session.api.post('admin/modules/align-prices', {'apply': true}));
                    if (context.mounted) toast(context, tr('${r['free']} module(s) gratuits, ${r['priced']} module(s) tarifés.'));
                    reload();
                    await session.refreshBootstrap();
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                icon: const Icon(Icons.auto_fix_high_outlined),
                label: Text(tr('Aligner les tarifs')),
              ),
              OutlinedButton.icon(
                onPressed: () async {
                  await session.api.post('admin/modules/refresh');
                  reload();
                },
                icon: const Icon(Icons.sync),
                label: Text(tr('Actualiser le catalogue')),
              ),
            ],
          ),
          for (final cat in byCategory.entries) ...[
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 12, pad, 8),
              child: Text(tr(cat.key.toUpperCase()), style: TextStyle(fontWeight: FontWeight.w700, color: Theme.of(context).hintColor, letterSpacing: .8, fontSize: 12)),
            ),
            for (final m in cat.value)
              Padding(
                padding: EdgeInsets.fromLTRB(pad, 0, pad, 8),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(children: [
                      Icon(iconFor(m['icon']), color: m['is_available'] == true ? YColors.blue : Colors.grey),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                            Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                            if (m['is_core'] == true) StatusChip(tr('Socle'), color: YColors.blue),
                            if (m['is_available'] != true) StatusChip(tr('Bientôt'), color: YColors.muted),
                          ]),
                          const SizedBox(height: 2),
                          Text(tr('${m['description'] ?? ''}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                          if (m['is_core'] != true) ...[
                            const SizedBox(height: 6),
                            InkWell(
                              onTap: () => _priceDialog(context, m, update),
                              child: Row(mainAxisSize: MainAxisSize.min, children: [
                                Flexible(
                                  child: Text(
                                    tr(_priceSummary(m, '${m['currency'] ?? session.currency}')),
                                    style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w600),
                                  ),
                                ),
                                const SizedBox(width: 6),
                                const Icon(Icons.edit_outlined, size: 16, color: YColors.blue),
                              ]),
                            ),
                          ],
                        ]),
                      ),
                      if (m['is_core'] != true)
                        Switch(value: m['is_active'] == true, onChanged: (v) => update(m, {'is_active': v})),
                    ]),
                  ),
                ),
              ),
          ],
        ]);
      },
    );
  }

  Future<void> _priceDialog(BuildContext context, Map<String, dynamic> m, Future<void> Function(Map<String, dynamic>, Map<String, dynamic>) update) async {
    await showFormDialog(context, title: '${m['name']}', builder: (dc) => _ModuleEditor(module: m, update: update));
  }
}

const billingModeLabels = {
  'free': 'Gratuit (actif pour tous)',
  'subscription': 'Abonnement (mensuel / annuel)',
  'one_time': 'Prix fixe (achat définitif)',
  'both': 'Au choix : abonnement ou prix fixe',
};

/// Vente et fiche boutique d'un module.
class _ModuleEditor extends StatefulWidget {
  const _ModuleEditor({required this.module, required this.update});

  final Map<String, dynamic> module;
  final Future<void> Function(Map<String, dynamic>, Map<String, dynamic>) update;

  @override
  State<_ModuleEditor> createState() => _ModuleEditorState();
}

class _ModuleEditorState extends State<_ModuleEditor> {
  late final m = widget.module;
  String n(dynamic v) => '${((v as num?) ?? 0).round()}';
  late final _monthly = TextEditingController(text: n(m['price_monthly']));
  late final _yearly = TextEditingController(text: n(m['price_yearly']));
  late final _oneTime = TextEditingController(text: n(m['price_one_time']));
  late final _trial = TextEditingController(text: n(m['trial_days']));
  late final _tagline = TextEditingController(text: '${m['tagline'] ?? ''}');
  late final _long = TextEditingController(text: '${m['long_description'] ?? ''}');
  late final _features = TextEditingController(text: (m['features'] as List? ?? const []).join('\n'));
  late final _demo = TextEditingController(text: '${m['demo_url'] ?? ''}');
  late String _mode = '${m['billing_mode'] ?? 'subscription'}';
  late bool _inStore = m['in_store'] != false;
  late List<String> _shots = List<String>.from(m['screenshot_urls'] ?? const []);
  bool _busy = false;

  final _fmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];

  Future<void> _upload() async {
    final res = await FilePicker.pickFiles(type: FileType.image);
    final f = res.firstOrNull;
    if (f == null || !mounted) return;
    setState(() => _busy = true);
    try {
      final bytes = await f.readAsBytes();
      if (!mounted) return;
      final r = await context.read<Session>().api.upload('admin/modules/${m['key']}/screenshots', 'image', bytes, f.name);
      if (mounted) setState(() => _shots = [..._shots, '${r['url']}']);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _remove(String url) async {
    try {
      await context.read<Session>().api.delete('admin/modules/${m['key']}/screenshots', {'path': url});
      if (mounted) setState(() => _shots.remove(url));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = '${m['currency'] ?? 'XOF'}';
    final sub = _mode == 'subscription' || _mode == 'both';
    final fixed = _mode == 'one_time' || _mode == 'both';
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Field(
        label: tr('Mode de vente'),
        child: DropdownButtonFormField<String>(
          isExpanded: true,
          initialValue: _mode,
          items: [for (final e in billingModeLabels.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
          onChanged: (v) => setState(() => _mode = v!),
        ),
      ),
      if (sub)
        Row(children: [
          Expanded(child: Field(label: tr('Prix mensuel ($cur)'), child: TextField(controller: _monthly, inputFormatters: _fmt))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Prix annuel ($cur)'), child: TextField(controller: _yearly, inputFormatters: _fmt))),
        ]),
      if (fixed) Field(label: tr('Prix fixe — achat définitif ($cur)'), child: TextField(controller: _oneTime, inputFormatters: _fmt)),
      if (_mode != 'free')
        Row(children: [
          SizedBox(width: 160, child: Field(label: tr('Essai gratuit (jours)'), child: TextField(controller: _trial, inputFormatters: [FilteringTextInputFormatter.digitsOnly]))),
          const SizedBox(width: 12),
          Expanded(child: Text(tr('0 = pas d\'essai. Astuce : un prix annuel de 10 mois offre 2 mois.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12))),
        ]),
      SwitchListTile(contentPadding: EdgeInsets.zero, value: _inStore, onChanged: (v) => setState(() => _inStore = v), title: Text(tr('Visible dans la boutique des clients'))),
      const Divider(height: 24),
      Text(tr('Fiche boutique'), style: TextStyle(fontWeight: FontWeight.w700)),
      const SizedBox(height: 8),
      Field(label: tr('Accroche'), child: TextField(controller: _tagline)),
      Field(label: tr('Présentation'), child: TextField(controller: _long, maxLines: 6)),
      Field(label: tr('Fonctionnalités (une par ligne)'), child: TextField(controller: _features, maxLines: 6)),
      Field(label: tr('Lien de démonstration (vidéo, optionnel)'), child: TextField(controller: _demo)),
      Text(tr('Captures d\'écran'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
      const SizedBox(height: 6),
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final s in _shots)
          Stack(children: [
            ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.network(s, width: 140, height: 88, fit: BoxFit.cover, errorBuilder: (_, _, _) => Container(width: 140, height: 88, color: YColors.line))),
            Positioned(right: 2, top: 2, child: IconButton.filledTonal(iconSize: 16, visualDensity: VisualDensity.compact, onPressed: () => _remove(s), icon: const Icon(Icons.close))),
          ]),
        InkWell(
          onTap: _busy ? null : _upload,
          child: Container(
            width: 140,
            height: 88,
            decoration: BoxDecoration(border: Border.all(color: YColors.line, width: 2), borderRadius: BorderRadius.circular(8)),
            child: Center(child: _busy ? const CircularProgressIndicator() : Column(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.add_photo_alternate_outlined), Text(tr('Ajouter'), style: TextStyle(fontSize: 12))])),
          ),
        ),
      ]),
      const SizedBox(height: 20),
      Align(
        alignment: Alignment.centerRight,
        child: FilledButton(
          onPressed: () async {
            await widget.update(m, {
              'billing_mode': _mode,
              'price_monthly': toDouble(_monthly.text),
              'price_yearly': toDouble(_yearly.text),
              'price_one_time': toDouble(_oneTime.text),
              'trial_days': int.tryParse(_trial.text) ?? 0,
              'in_store': _inStore,
              'tagline': _tagline.text.trim().isEmpty ? null : _tagline.text.trim(),
              'long_description': _long.text.trim().isEmpty ? null : _long.text.trim(),
              'features': _features.text.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList(),
              'demo_url': _demo.text.trim().isEmpty ? null : _demo.text.trim(),
            });
            if (context.mounted) Navigator.pop(context);
          },
          child: Text(tr('Enregistrer')),
        ),
      ),
    ]);
  }
}
