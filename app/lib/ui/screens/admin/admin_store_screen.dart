import 'dart:math' as math;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../payment_widgets.dart';
import '../store_screen.dart' show orderStatusColor;
import 'admin_common.dart';
import '../../../i18n/i18n.dart';
import '../../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Super admin : commandes de modules, moyens de paiement de la plateforme, boutique et contacts.
class AdminStoreScreen extends StatefulWidget {
  const AdminStoreScreen({super.key});

  @override
  State<AdminStoreScreen> createState() => _AdminStoreScreenState();
}

class _AdminStoreScreenState extends State<AdminStoreScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 4, vsync: this);

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      PageHeader(title: tr('Ventes de modules'), subtitle: tr('Commandes des clients, moyens de paiement et vitrine de la boutique')),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Commandes')),
        Tab(text: tr('Moyens de paiement')),
        Tab(text: tr('Boutique & contacts')),
        Tab(text: tr('Serveur e-mail')),
      ]),
      Expanded(child: TabBarView(controller: _tabs, children: const [_OrdersTab(), _GatewaysTab(), _ShopTab(), _MailTab()])),
    ]);
  }
}

class _OrdersTab extends StatefulWidget {
  const _OrdersTab();

  @override
  State<_OrdersTab> createState() => _OrdersTabState();
}

class _OrdersTabState extends State<_OrdersTab> {
  String? _status;

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    return AdminLoader(
      key: ValueKey(_status),
      path: _status == null ? 'admin/store/orders' : 'admin/store/orders?status=$_status',
      builder: (context, d, reload) {
        final rows = List<Map<String, dynamic>>.from((d['data'] as List).map((e) => Map<String, dynamic>.from(e)));
        Future<void> act(Map<String, dynamic> o, String action) async {
          if (action == 'approve' && !await confirm(context, tr('Valider le paiement ?'), tr('Le module « ${o['module_name'] ?? o['module']?['name'] ?? o['module_key']} » sera activé pour ${o['company']?['name']}.'), ok: tr('Valider et activer'))) return;
          try {
            await session.api.post('admin/store/orders/${o['id']}/$action');
            reload();
          } catch (e) {
            if (context.mounted) toast(context, errorText(e), error: true);
          }
        }

        return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
          Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            for (final e in const {null: 'Toutes', 'awaiting_validation': 'À vérifier', 'pending': 'En attente', 'paid': 'Payées', 'failed': 'Refusées'}.entries)
              ChoiceChip(label: Text(tr(e.value)), selected: _status == e.key, onSelected: (_) => setState(() => _status = e.key)),
            const SizedBox(width: 12),
            Text(tr('${d['to_validate']} à vérifier · encaissé ce mois : ${money(d['revenue_month'] as num, session.currency)}'), style: TextStyle(color: Theme.of(context).hintColor)),
          ]),
          const SizedBox(height: 12),
          if (rows.isEmpty) EmptyState(icon: Icons.shopping_bag_outlined, title: tr('Aucune commande')),
          for (final o in rows)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${o['company']?['name'] ?? '?'} · ${o['module_name'] ?? o['module']?['name'] ?? o['module_key']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      const SizedBox(height: 2),
                      Text(tr('${o['number']} · ${o['billing_label']} · ${o['gateway']?['label'] ?? ''} · ${dateTimeFr(o['created_at'])}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                      if (o['payer_reference'] != null) Text(tr('Référence du client : ${o['payer_reference']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      if (o['notes'] != null) Text(tr('${o['notes']}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                    ]),
                  ),
                  Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                    Text(tr(money(o['amount'] as num, '${o['currency'] ?? 'XOF'}')), style: const TextStyle(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 4),
                    StatusChip('${o['status_label']}', color: orderStatusColor(o['status'])),
                    if (o['status'] == 'pending' || o['status'] == 'awaiting_validation')
                      Row(mainAxisSize: MainAxisSize.min, children: [
                        TextButton(onPressed: () => act(o, 'reject'), child: Text(tr('Refuser'))),
                        FilledButton(onPressed: () => act(o, 'approve'), child: Text(tr('Valider'))),
                      ]),
                  ]),
                ]),
              ),
            ),
        ]);
      },
    );
  }
}

class _GatewaysTab extends StatelessWidget {
  const _GatewaysTab();

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      path: 'admin/store/settings',
      builder: (context, d, reload) {
        final drivers = List<Map<String, dynamic>>.from((d['drivers'] as List).map((e) => Map<String, dynamic>.from(e)));
        final gateways = List<Map<String, dynamic>>.from((d['gateways'] as List).map((e) => Map<String, dynamic>.from(e)));
        return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
          GatewaysEditor(
            drivers: drivers,
            gateways: gateways,
            endpoint: 'admin/payment-gateways',
            onChanged: reload,
            intro: 'Moyens proposés à vos clients pour payer les modules. En ligne (CinetPay, Wave, PayDunya…) : activation immédiate. Hors ligne (virement, dépôt Mobile Money, espèces) : vous validez la commande dans l\'onglet Commandes.',
          ),
          const SizedBox(height: 16),
          _CompanyDrivers(drivers: drivers, selected: List<String>.from(d['company_drivers'] ?? const []), onChanged: reload),
        ]);
      },
    );
  }
}

/// Prestataires que les sociétés peuvent configurer avec leurs propres clés.
class _CompanyDrivers extends StatefulWidget {
  const _CompanyDrivers({required this.drivers, required this.selected, required this.onChanged});

  final List<Map<String, dynamic>> drivers;
  final List<String> selected;
  final VoidCallback onChanged;

  @override
  State<_CompanyDrivers> createState() => _CompanyDriversState();
}

class _CompanyDriversState extends State<_CompanyDrivers> {
  late final Set<String> _on = widget.selected.toSet();

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: tr('Moyens de paiement autorisés aux sociétés'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(tr('Chaque société peut brancher ses propres comptes pour encaisser ses factures en ligne (lien « Payer en ligne »). Choisissez les prestataires qu\'elle a le droit d\'utiliser.'),
            style: TextStyle(color: Theme.of(context).hintColor)),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final d in widget.drivers)
            FilterChip(
              label: Text(tr('${d['label']}')),
              selected: _on.contains(d['driver']),
              onSelected: (v) => setState(() => v ? _on.add('${d['driver']}') : _on.remove(d['driver'])),
            ),
        ]),
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: () async {
              try {
                await context.read<Session>().api.put('admin/store/settings', {'company_drivers': _on.toList()});
                if (context.mounted) toast(context, tr('Enregistré.'));
                widget.onChanged();
              } catch (e) {
                if (context.mounted) toast(context, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ),
      ]),
    );
  }
}

class _ShopTab extends StatelessWidget {
  const _ShopTab();

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      path: 'admin/store/settings',
      builder: (context, d, reload) {
        final api = context.read<Session>().api;
        return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
          _ContactForm(contact: asMap(d['contact'])),
          const SizedBox(height: 16),
          _DownloadLinks(data: d, enabled: d['landing_enabled'] == true),
          const SizedBox(height: 16),
          _YomeetPricing(visio: asMap(d['visio'])),
          const SizedBox(height: 16),
          BankAccountsEditor(
            accounts: List<Map<String, dynamic>>.from((d['bank_accounts'] as List? ?? []).map((e) => Map<String, dynamic>.from(e))),
            onSave: (list) async {
              await api.put('admin/store/settings', {'bank_accounts': list});
              reload();
            },
          ),
          const SizedBox(height: 16),
          Card(
            color: YColors.blue.withValues(alpha: .06),
            child: Padding(
              padding: EdgeInsets.all(14),
              child: Text(tr('Les fiches (texte, fonctionnalités, captures, prix, essai gratuit) se règlent module par module dans « Modules & tarifs ».')),
            ),
          ),
        ]);
      },
    );
  }
}

/// Liens des logiciels affichés sur le site de présentation (/telecharger), dans l'e-mail de bienvenue et dans le bandeau
/// « Nouvelle application » : par plateforme, la fiche de boutique et/ou le fichier direct ; plus le dépôt du code source.
class _DownloadLinks extends StatefulWidget {
  const _DownloadLinks({required this.data, required this.enabled});

  final Map<String, dynamic> data;
  final bool enabled;

  @override
  State<_DownloadLinks> createState() => _DownloadLinksState();
}

class _DownloadLinksState extends State<_DownloadLinks> {
  /// Plateforme → (nom, icône, clé de la boutique et son nom, clé du fichier et son type).
  static const _platforms = <String, (String, IconData, String?, String?, String?, String?)>{
    'windows': ('Windows', Icons.desktop_windows_outlined, 'windows_store', 'Microsoft Store', 'windows', 'Fichier d\'installation (.exe ou .zip)'),
    'mac': ('macOS', Icons.laptop_mac, 'mac_store', 'Mac App Store', 'mac', 'Fichier .dmg'),
    'linux': ('Linux', Icons.computer, null, null, 'linux', 'Fichier AppImage, .deb ou .tar.gz'),
    'android': ('Android', Icons.android, 'android_store', 'Google Play', 'android', 'Fichier APK'),
    'ios': ('iPhone & iPad', Icons.phone_iphone, 'ios', 'App Store', null, null),
  };
  static const _keys = ['windows', 'windows_store', 'mac', 'mac_store', 'linux', 'android', 'android_store', 'ios', 'server', 'github'];

  late final Map<String, dynamic> _saved = asMap(widget.data['downloads']);
  late final Map<String, dynamic> _detected = Map.of(asMap(widget.data['downloads_detected']));
  late final Map<String, dynamic> _official = asMap(widget.data['official_links']);
  late final Map<String, dynamic> _counts = asMap(widget.data['download_counts']);
  late final _ctrl = {for (final k in _keys) k: TextEditingController(text: '${_saved[k] ?? ''}')};
  late final _version = TextEditingController(text: '${widget.data['apps_version'] ?? ''}');
  bool _busy = false;

  @override
  void dispose() {
    for (final c in _ctrl.values) {
      c.dispose();
    }
    _version.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('admin/store/settings', {
        'downloads': {for (final e in _ctrl.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim()},
        'apps_version': _version.text.trim(),
      });
      if (mounted) toast(context, tr('Liens des logiciels enregistrés.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Remplit les fiches de boutique vides avec les adresses officielles (sans rien enregistrer).
  void _fillOfficial() {
    var n = 0;
    _official.forEach((k, v) {
      final c = _ctrl[k];
      if (c != null && c.text.trim().isEmpty && '$v'.isNotEmpty) {
        c.text = '$v';
        n++;
      }
    });
    setState(() {});
    toast(context, n == 0 ? tr('Les fiches sont déjà renseignées.') : tr('$n fiche(s) ajoutée(s) : enregistrez une fois les applications publiées.'));
  }

  int _count(String k) => (num.tryParse('${_counts[k] ?? 0}') ?? 0).toInt();

  /// Extensions acceptées et forme du nom reconnue par le site (même règle que LandingController::LOCAL_FILES).
  static const _fileRules = <String, (List<String>, String, String)>{
    'windows': (['exe', 'msi', 'zip'], r'(\.(exe|msi)|windows[^/]*\.zip)$', 'windows'),
    'mac': (['dmg', 'zip'], r'(\.dmg|mac(os)?[^/]*\.zip)$', 'macos'),
    'linux': (['AppImage', 'appimage', 'deb', 'gz'], r'(\.appimage|\.deb|linux[^/]*\.tar\.gz)$', 'linux'),
    'android': (['apk'], r'\.apk$', 'android'),
    'server': (['zip'], r'(open-source|serveur|server)[^/]*\.zip$', 'serveur'),
  };
  String? _uploading;
  double _progress = 0;

  /// Dépose le fichier dans public/downloads (morceaux de 4 Mo) : le site le propose aussitôt quand le champ reste vide.
  Future<void> _upload(String key) async {
    final rule = _fileRules[key]!;
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: rule.$1);
    if (files.isEmpty || !mounted) return;
    var name = files.first.name.replaceAll(RegExp(r'[^A-Za-z0-9._+-]+'), '-').replaceAll(RegExp(r'^[^A-Za-z0-9]+'), '');
    if (!RegExp(rule.$2, caseSensitive: false).hasMatch(name)) name = 'yomara-erp-${rule.$3}-$name';
    final api = context.read<Session>().api;
    setState(() => (_uploading = key, _progress = 0));
    try {
      final bytes = await files.first.readAsBytes();
      const size = 4 * 1024 * 1024;
      final total = math.max(1, (bytes.length / size).ceil());
      final id = '${DateTime.now().millisecondsSinceEpoch}';
      dynamic r;
      for (var i = 0; i < total; i++) {
        r = await api.uploadForm('admin/downloads', {'upload_id': id, 'index': i, 'total': total, 'name': name}, 'chunk',
            bytes.sublist(i * size, math.min(bytes.length, (i + 1) * size)), 'chunk.bin');
        if (mounted) setState(() => _progress = (i + 1) / total);
      }
      final url = r is Map ? asMap(r['data'])['url'] : null;
      if (!mounted) return;
      setState(() {
        if (url != null) _detected[key] = url;
        _ctrl[key]!.clear(); // champ vide : le fichier déposé (le plus récent) est utilisé
      });
      toast(context, tr('$name envoyé : pensez à enregistrer si vous avez vidé un lien.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _uploading = null);
    }
  }

  Widget _field(String key, String label, IconData icon, {String? detected}) {
    final hint = detected == null ? 'https://…' : '${tr('Vide : fichier déposé')} ${Uri.tryParse(detected)?.pathSegments.lastOrNull ?? detected}';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextField(
        controller: _ctrl[key],
        keyboardType: TextInputType.url,
        decoration: InputDecoration(
          isDense: true,
          prefixIcon: Icon(icon, size: 20),
          labelText: tr(label),
          hintText: hint,
          floatingLabelBehavior: FloatingLabelBehavior.always,
          helperText: _uploading == key ? '${tr('Envoi…')} ${(_progress * 100).round()} %' : null,
          suffixIcon: _fileRules.containsKey(key)
              ? IconButton(
                  tooltip: tr('Envoyer le fichier sur le serveur'),
                  onPressed: _uploading != null ? null : () => _upload(key),
                  icon: _uploading == key
                      ? SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, value: _progress == 0 ? null : _progress))
                      : const Icon(Icons.upload_file_outlined, size: 20),
                )
              : null,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final base = context.read<Session>().api.baseUrl;
    return SectionCard(
      title: tr('Liens des logiciels'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(
          widget.enabled
              ? tr('Affichés sur la page « Télécharger », dans l\'e-mail de bienvenue et dans le bandeau « Nouvelle application ». Quand une plateforme a une fiche de boutique, le bouton principal y mène (installation et mises à jour automatiques) et le fichier direct reste proposé en second. Laissez tout vide pour afficher « Bientôt disponible ». Les fichiers déposés dans « Site › Fichiers à télécharger » sont pris automatiquement quand le champ du fichier est vide.')
              : tr('Le site de présentation et l\'inscription en ligne ne s\'affichent qu\'en mode SaaS. Ces liens servent au bandeau « Nouvelle application » des installations.'),
          style: TextStyle(color: hint, fontSize: 12.5),
        ),
        const SizedBox(height: 14),
        for (final e in _platforms.entries) ...[
          Row(children: [
            Icon(e.value.$2, size: 18),
            const SizedBox(width: 8),
            Expanded(child: Text(tr(e.value.$1), style: const TextStyle(fontWeight: FontWeight.w700))),
            Text('${_count(e.key) + _count('${e.key}_file')} ${tr('téléchargements')}', style: TextStyle(color: hint, fontSize: 12)),
          ]),
          const SizedBox(height: 8),
          if (e.value.$3 != null) _field(e.value.$3!, e.value.$4!, Icons.storefront_outlined),
          if (e.value.$5 != null) _field(e.value.$5!, e.value.$6!, Icons.insert_drive_file_outlined, detected: _detected[e.value.$5] as String?),
          const SizedBox(height: 4),
        ],
        const Divider(height: 24),
        Text(tr('Édition libre (AGPL)'), style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        _field('github', 'Dépôt du code source (GitHub, GitLab…)', Icons.code),
        _field('server', 'Logiciel serveur à installer (zip)', Icons.dns_outlined, detected: _detected['server'] as String?),
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: TextField(
            controller: _version,
            decoration: InputDecoration(isDense: true, prefixIcon: const Icon(Icons.new_releases_outlined, size: 20), labelText: tr('Version des logiciels affichée (facultatif)'), hintText: '1.0.0'),
          ),
        ),
        Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          if (_official.isNotEmpty)
            OutlinedButton.icon(onPressed: _fillOfficial, icon: const Icon(Icons.auto_fix_high_outlined, size: 18), label: Text(tr('Liens officiels des boutiques'))),
          if (widget.enabled)
            TextButton.icon(
              onPressed: () => safeLaunchUrl(Uri.parse('${base.replaceAll(RegExp(r'/+$'), '')}/telecharger')),
              icon: const Icon(Icons.open_in_new, size: 18),
              label: Text(tr('Voir la page Télécharger')),
            ),
          FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
        ]),
      ]),
    );
  }
}

/// Minutes Yomeet : quota inclus par société et forfaits vendus au mois (infrastructure ZEGOCLOUD / Agora de la plateforme).
class _YomeetPricing extends StatefulWidget {
  const _YomeetPricing({required this.visio});

  final Map<String, dynamic> visio;

  @override
  State<_YomeetPricing> createState() => _YomeetPricingState();
}

class _YomeetPricingState extends State<_YomeetPricing> {
  late final _included = TextEditingController(text: '${widget.visio['included_minutes'] ?? 300}');
  late final List<Map<String, TextEditingController>> _packs = [
    for (final p in (widget.visio['packs'] as List? ?? const []))
      {for (final k in ['code', 'minutes', 'price', 'label']) k: TextEditingController(text: '${(p as Map)[k] ?? ''}')},
  ];
  bool _busy = false;

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('admin/store/settings', {
        'visio': {
          'included_minutes': int.tryParse(_included.text.replaceAll(' ', '')) ?? 0,
          'packs': [
            for (final p in _packs)
              if (p['code']!.text.trim().isNotEmpty)
                {
                  'code': p['code']!.text.trim().toLowerCase(),
                  'minutes': int.tryParse(p['minutes']!.text.replaceAll(' ', '')) ?? 0,
                  'price': num.tryParse(p['price']!.text.replaceAll(' ', '').replaceAll(',', '.')) ?? 0,
                  'label': p['label']!.text.trim().isEmpty ? null : p['label']!.text.trim(),
                },
          ],
        },
      });
      if (mounted) toast(context, tr('Tarifs Yomeet enregistrés.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return SectionCard(
      title: tr('Yomeet : minutes de visio vendues au mois'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(
          tr('Les clés ZEGOCLOUD / Agora sont les vôtres (Administration › Paramètres serveur). Chaque société dispose d\'un quota mensuel inclus ; au-delà, elle achète des forfaits ou bascule en pair-à-pair (6 personnes max).'),
          style: TextStyle(color: hint, fontSize: 12.5),
        ),
        const SizedBox(height: 12),
        SizedBox(width: 260, child: TextField(controller: _included, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Minutes incluses par société et par mois'), isDense: true))),
        const SizedBox(height: 14),
        Text(tr('Forfaits'), style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        for (final (i, p) in _packs.indexed)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(children: [
              SizedBox(width: 110, child: TextField(controller: p['code'], decoration: InputDecoration(labelText: tr('Code'), isDense: true))),
              const SizedBox(width: 8),
              SizedBox(width: 120, child: TextField(controller: p['minutes'], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Minutes'), isDense: true))),
              const SizedBox(width: 8),
              SizedBox(width: 130, child: TextField(controller: p['price'], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Prix / mois'), isDense: true))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: p['label'], decoration: InputDecoration(labelText: tr('Libellé'), isDense: true))),
              IconButton(onPressed: () => setState(() => _packs.removeAt(i)), icon: const Icon(Icons.delete_outline)),
            ]),
          ),
        Row(children: [
          TextButton.icon(
            onPressed: () => setState(() => _packs.add({for (final k in ['code', 'minutes', 'price', 'label']) k: TextEditingController()})),
            icon: const Icon(Icons.add),
            label: Text(tr('Ajouter un forfait')),
          ),
          const Spacer(),
          FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
        ]),
      ]),
    );
  }
}

class _ContactForm extends StatefulWidget {
  const _ContactForm({required this.contact});

  final Map<String, dynamic> contact;

  @override
  State<_ContactForm> createState() => _ContactFormState();
}

class _ContactFormState extends State<_ContactForm> {
  static const _fields = {
    'name': 'Nom affiché',
    'phone': 'Téléphone',
    'whatsapp': 'WhatsApp',
    'email': 'E-mail',
    'website': 'Site web',
    'address': 'Adresse',
    'hours': 'Horaires',
    'message': 'Message d\'accueil',
  };
  late final Map<String, TextEditingController> _c = {for (final f in _fields.keys) f: TextEditingController(text: '${widget.contact[f] ?? ''}')};

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: tr('Contacts affichés dans la boutique'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Wrap(spacing: 12, children: [
          for (final f in _fields.entries) SizedBox(width: f.key == 'message' || f.key == 'address' ? 520 : 250, child: Field(label: f.value, child: TextField(controller: _c[f.key]))),
        ]),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: () async {
              try {
                await context.read<Session>().api.put('admin/store/settings', {
                  'contact': {for (final e in _c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim()},
                });
                if (context.mounted) toast(context, tr('Contacts enregistrés.'));
              } catch (e) {
                if (context.mounted) toast(context, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ),
      ]),
    );
  }
}

/// Serveur de messagerie de la plateforme, proposé aux sociétés sans serveur (module « Messagerie hébergée »).
class _MailTab extends StatelessWidget {
  const _MailTab();

  @override
  Widget build(BuildContext context) {
    return AdminLoader(
      path: 'admin/mail-platform',
      builder: (context, d, reload) => ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
        _MailForm(settings: asMap(d['settings']), onSaved: reload),
      ]),
    );
  }
}

class _MailForm extends StatefulWidget {
  const _MailForm({required this.settings, required this.onSaved});

  final Map<String, dynamic> settings;
  final VoidCallback onSaved;

  @override
  State<_MailForm> createState() => _MailFormState();
}

class _MailFormState extends State<_MailForm> {
  static const _fields = {
    'host': 'Serveur SMTP',
    'port': 'Port (587 / 465)',
    'encryption': 'Chiffrement (tls, ssl, none)',
    'username': 'Identifiant',
    'password': 'Mot de passe',
    'from_email': 'Adresse d\'expédition (ex. no-reply@votre-domaine)',
    'from_name': 'Nom par défaut',
    'quota': 'Quota mensuel par société',
  };
  late final Map<String, TextEditingController> _c = {
    for (final f in _fields.keys) f: TextEditingController(text: f == 'password' ? '' : '${widget.settings[f] ?? (f == 'quota' ? 1000 : '')}'),
  };

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: tr('Serveur de messagerie de l\'ERP'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(
          tr('Les sociétés qui activent le module « Messagerie hébergée » envoient leurs e-mails par ce serveur, '
          'avec leur propre nom d\'expéditeur et adresse de réponse. Utilisez un domaine avec SPF/DKIM configurés.'),
          style: TextStyle(color: Theme.of(context).hintColor),
        ),
        const SizedBox(height: 12),
        Wrap(spacing: 12, children: [
          for (final f in _fields.entries)
            SizedBox(
              width: 260,
              child: Field(
                label: f.value,
                child: TextField(
                  controller: _c[f.key],
                  obscureText: f.key == 'password',
                  keyboardType: f.key == 'port' || f.key == 'quota' ? TextInputType.number : null,
                  decoration: InputDecoration(hintText: trn(f.key == 'password' && widget.settings['has_password'] == true ? 'Enregistré (laisser vide)' : null)),
                ),
              ),
            ),
        ]),
        Align(
          alignment: Alignment.centerLeft,
          child: FilledButton(
            onPressed: () async {
              final body = <String, dynamic>{};
              for (final e in _c.entries) {
                final v = e.value.text.trim();
                if (v.isEmpty) continue;
                body[e.key] = e.key == 'port' || e.key == 'quota' ? int.tryParse(v) : v;
              }
              try {
                await context.read<Session>().api.put('admin/mail-platform', body);
                if (context.mounted) toast(context, tr('Serveur de messagerie enregistré.'));
                widget.onSaved();
              } catch (e) {
                if (context.mounted) toast(context, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ),
      ]),
    );
  }
}
