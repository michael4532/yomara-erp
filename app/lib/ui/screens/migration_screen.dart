import 'dart:async';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const _what = {
  'partners': ('Clients & fournisseurs', Icons.people_alt_outlined),
  'products': ('Articles et prix', Icons.inventory_2_outlined),
  'stock': ('Quantités en stock', Icons.warehouse_outlined),
  'sales': ('Factures et avoirs clients', Icons.receipt_long_outlined),
  'purchases': ('Factures fournisseurs', Icons.local_shipping_outlined),
};

const _fileKinds = {
  'partners': 'Clients & fournisseurs',
  'products': 'Articles',
  'sales': 'Factures de ventes (une ligne par article)',
  'purchases': 'Factures d\'achats',
};

const _brand = {
  'dolibarr': Color(0xFF263C5C),
  'odoo': Color(0xFF714B67),
  'erpnext': Color(0xFF2490EF),
  'sage': Color(0xFF00D639),
  'quickbooks': Color(0xFF2CA01C),
  'zoho': Color(0xFFE42527),
  'xero': Color(0xFF13B5EA),
  'ebp': Color(0xFF0060A9),
  'wave': Color(0xFF1C6BFF),
  'tally': Color(0xFF3A3A8C),
  'sap': Color(0xFF0FAAFF),
  'dynamics': Color(0xFF0B53CE),
  'odoo_csv': Color(0xFF714B67),
  'dolibarr_csv': Color(0xFF263C5C),
  'excel': Color(0xFF1D6F42),
};

/// Migration depuis un autre logiciel : connexion directe (Dolibarr, Odoo, ERPNext) ou fichiers d'export, aperçu, migration en arrière-plan.
class MigrationScreen extends StatefulWidget {
  const MigrationScreen({super.key});

  @override
  State<MigrationScreen> createState() => _MigrationScreenState();
}

class _MigrationScreenState extends State<MigrationScreen> {
  Map<String, dynamic>? _d;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.get('migration'));
      if (!mounted) return;
      setState(() => _d = d);
      final running = _jobs.any((j) => j['status'] == 'queued' || j['status'] == 'running');
      _poll?.cancel();
      if (running) _poll = Timer(const Duration(seconds: 2), _load);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  List<Map<String, dynamic>> _list(String k) => [for (final e in (_d?[k] as List? ?? const [])) asMap(e)];
  List<Map<String, dynamic>> get _jobs => _list('jobs');

  Future<void> _start({Map<String, dynamic>? api, Map<String, dynamic>? vendor}) async {
    final job = await showDialog<Map<String, dynamic>>(context: context, builder: (_) => _ConnectDialog(api: api, vendor: vendor));
    if (job == null || !mounted) return;
    final started = await showDialog<bool>(
        context: context, builder: (_) => _PreviewDialog(job: job, currency: '${_d?['currency'] ?? 'XOF'}', modules: asMap(_d?['modules'])));
    if (started == true) {
      if (mounted) toast(context, tr('Migration lancée : elle continue même si vous quittez cet écran.'));
    }
    _load();
  }

  Future<void> _delete(Map<String, dynamic> j) async {
    try {
      await context.read<Session>().api.delete('migration/jobs/${j['id']}');
      _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return const Center(child: CircularProgressIndicator());
    final width = MediaQuery.of(context).size.width;
    final pad = width < 700 ? 16.0 : 24.0;
    final hint = Theme.of(context).hintColor;
    Widget grid(List<Widget> items, {double min = 240}) => LayoutBuilder(builder: (context, c) {
          final cols = (c.maxWidth / min).floor().clamp(1, 5);
          final w = (c.maxWidth - (cols - 1) * 12) / cols;
          return Wrap(spacing: 12, runSpacing: 12, children: [for (final i in items) SizedBox(width: w, child: i)]);
        });
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
        PageHeader(title: tr('Migrer depuis un autre logiciel'), subtitle: tr('Reprenez clients, articles, stocks et factures sans rien ressaisir')),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: pad),
          child: Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              gradient: const LinearGradient(colors: [YColors.night, Color(0xFF1B3D7A)], begin: Alignment.topLeft, end: Alignment.bottomRight),
            ),
            child: Wrap(spacing: 28, runSpacing: 12, children: const [
              _Step(Icons.link, '1. Connectez ou déposez', 'Adresse et clé d\'accès, ou fichiers d\'export'),
              _Step(Icons.fact_check_outlined, '2. Vérifiez l\'aperçu', 'Ce qui sera repris, devise, période'),
              _Step(Icons.rocket_launch_outlined, '3. Migrez', 'En arrière-plan, relançable sans doublon'),
            ]),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 22, pad, 4),
          child: Text(tr('Connexion directe'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 0, pad, 10),
          child: Text(tr('Yomara lit vos données directement dans votre ancien logiciel, factures et règlements compris.'), style: TextStyle(color: hint)),
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: pad),
          child: grid([
            for (final s in _list('sources'))
              _SourceCard(keyName: '${s['key']}', name: '${s['name']}', subtitle: tr('Connexion ${s['protocol']}'), live: true, onTap: () => _start(api: s)),
          ], min: 300),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 22, pad, 4),
          child: Text(tr('Fichiers d\'export'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(pad, 0, pad, 10),
          child: Text(tr('Exportez vos listes en Excel ou CSV depuis votre logiciel : les colonnes sont reconnues automatiquement.'), style: TextStyle(color: hint)),
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: pad),
          child: grid([
            for (final v in _list('vendors')) _SourceCard(keyName: '${v['key']}', name: '${v['name']}', subtitle: tr('Excel / CSV'), onTap: () => _start(vendor: v)),
          ]),
        ),
        if (_jobs.isNotEmpty) ...[
          Padding(
            padding: EdgeInsets.fromLTRB(pad, 24, pad, 10),
            child: Text(tr('Migrations'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
          ),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: pad),
            child: Column(children: [for (final j in _jobs) _JobCard(job: j, onDelete: () => _delete(j))]),
          ),
        ],
      ]),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step(this.icon, this.title, this.text);
  final IconData icon;
  final String title, text;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        Container(
          width: 38,
          height: 38,
          alignment: Alignment.center,
          decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(11)),
          child: Icon(icon, color: Colors.white, size: 20),
        ),
        const SizedBox(width: 10),
        Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(tr(title), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
          Text(tr(text), style: TextStyle(color: Colors.white.withValues(alpha: .75), fontSize: 12.5)),
        ]),
      ]);
}

class _SourceCard extends StatelessWidget {
  const _SourceCard({required this.keyName, required this.name, required this.subtitle, required this.onTap, this.live = false});
  final String keyName, name, subtitle;
  final VoidCallback onTap;
  final bool live;

  @override
  Widget build(BuildContext context) {
    final c = _brand[keyName] ?? YColors.blue;
    final initials = name.replaceAll(RegExp(r'[^A-Za-z0-9 ]'), ' ').trim().split(RegExp(r'\s+')).take(2).map((w) => w[0].toUpperCase()).join();
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(
              width: 44,
              height: 44,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(12)),
              child: Text(tr(initials), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 15)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr(name), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(height: 2),
                Row(children: [
                  if (live) ...[const Icon(Icons.bolt, size: 14, color: YColors.success), const SizedBox(width: 2)],
                  Text(tr(subtitle), style: TextStyle(fontSize: 12.5, color: live ? YColors.success : Theme.of(context).hintColor, fontWeight: live ? FontWeight.w700 : null)),
                ]),
              ]),
            ),
            Icon(Icons.chevron_right, color: Theme.of(context).hintColor),
          ]),
        ),
      ),
    );
  }
}

/// Connexion à l'API ou dépôt des fichiers, puis analyse.
class _ConnectDialog extends StatefulWidget {
  const _ConnectDialog({this.api, this.vendor});
  final Map<String, dynamic>? api, vendor;

  @override
  State<_ConnectDialog> createState() => _ConnectDialogState();
}

class _ConnectDialogState extends State<_ConnectDialog> {
  final _ctrl = <String, TextEditingController>{};
  final _files = <String, (List<int>, String)>{};
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final c in _ctrl.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pick(String kind) async {
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['xlsx', 'xls', 'csv', 'txt', 'ods']);
    if (files.isEmpty) return;
    final f = files.first;
    final bytes = await f.readAsBytes();
    setState(() => _files[kind] = (bytes, f.name));
  }

  Future<void> _analyze() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final api = context.read<Session>().api;
      final res = widget.api != null
          ? await api.post('migration/connect', {'source': widget.api!['key'], for (final e in _ctrl.entries) e.key: e.value.text.trim()})
          : await api.uploadFiles('migration/files', {'vendor': widget.vendor!['key']}, _files);
      if (mounted) Navigator.pop(context, asMap(res));
    } catch (e) {
      setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final isApi = widget.api != null;
    final fields = asMap(widget.api?['fields']);
    final name = '${(widget.api ?? widget.vendor)!['name']}';
    final help = switch (widget.api?['key']) {
      'dolibarr' => 'Dans Dolibarr : Configuration › Modules, activez « API / Web services (serveur REST) ». Puis sur la fiche de votre utilisateur, onglet « Utilisateur », générez une « Clé pour l\'API » et enregistrez.',
      'odoo' => 'Dans Odoo : Préférences de votre utilisateur › Sécurité du compte › Nouvelle clé d\'API. La base de données s\'affiche dans l\'adresse ou sur l\'écran de connexion (?db=).',
      'erpnext' => 'Dans ERPNext : ouvrez votre utilisateur › Paramètres › Accès API › « Générer les clés ». Copiez la clé et le secret affichés.',
      _ => '${widget.vendor?['help'] ?? ''}',
    };
    return YAlertDialog(
      title: Text(tr(isApi ? 'Se connecter à $name' : 'Fichiers $name')),
      content: SizedBox(
        width: 520,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .07), borderRadius: BorderRadius.circular(12)),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.info_outline, size: 18, color: YColors.blue),
                const SizedBox(width: 8),
                Expanded(child: Text(tr(help), style: const TextStyle(fontSize: 12.8))),
              ]),
            ),
            const SizedBox(height: 12),
            if (isApi)
              for (final f in fields.entries)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: TextField(
                    controller: _ctrl.putIfAbsent(f.key, () => TextEditingController()),
                    obscureText: f.key.contains('key') || f.key.contains('secret'),
                    decoration: InputDecoration(labelText: trn('${f.value}'), hintText: trn(f.key == 'url' ? 'https://erp.mon-entreprise.com' : null)),
                  ),
                )
            else
              for (final k in _fileKinds.entries)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: OutlinedButton(
                    style: OutlinedButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12), alignment: Alignment.centerLeft),
                    onPressed: () => _pick(k.key),
                    child: Row(children: [
                      Icon(_files.containsKey(k.key) ? Icons.check_circle : Icons.upload_file, color: _files.containsKey(k.key) ? YColors.success : null, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr(k.value), style: const TextStyle(fontWeight: FontWeight.w700)),
                          Text(tr(_files[k.key]?.$2 ?? 'Choisir un fichier (facultatif)'), style: TextStyle(color: hint, fontSize: 12)),
                        ]),
                      ),
                    ]),
                  ),
                ),
            Text(
                tr(isApi
                    ? 'Vos accès servent uniquement à lire les données ; ils sont chiffrés puis effacés à la fin de la migration.'
                    : 'Un seul fichier suffit pour commencer ; les fichiers sont supprimés du serveur à la fin de la migration.'),
                style: TextStyle(color: hint, fontSize: 12)),
            if (_error != null) ...[
              const SizedBox(height: 10),
              Text(tr(_error!), style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w600)),
            ],
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton.icon(
          onPressed: _busy || (!isApi && _files.isEmpty) ? null : _analyze,
          icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.search, size: 18),
          label: Text(tr(_busy ? 'Analyse…' : 'Analyser')),
        ),
      ],
    );
  }
}

/// Aperçu (volumes, colonnes reconnues) et choix de ce qui sera migré.
class _PreviewDialog extends StatefulWidget {
  const _PreviewDialog({required this.job, required this.currency, required this.modules});
  final Map<String, dynamic> job, modules;
  final String currency;

  @override
  State<_PreviewDialog> createState() => _PreviewDialogState();
}

class _PreviewDialogState extends State<_PreviewDialog> {
  late final Set<String> _sel;
  final _rate = TextEditingController();
  DateTime? _since;
  bool _busy = false;

  Map<String, dynamic> get _probe => asMap(widget.job['probe']);
  Map<String, dynamic> get _counts => asMap(_probe['counts']);

  bool _available(String k) {
    if (k == 'stock') return widget.modules['stock'] == true && (_counts.containsKey('products'));
    if (k == 'purchases') return widget.modules['achats'] == true && _counts.containsKey('purchases');
    return _counts.containsKey(k);
  }

  @override
  void initState() {
    super.initState();
    _sel = {for (final k in _what.keys) if (_available(k)) k};
  }

  Future<void> _run() async {
    setState(() => _busy = true);
    try {
      final rate = double.tryParse(_rate.text.replaceAll(' ', '').replaceAll(',', '.'));
      await context.read<Session>().api.post('migration/jobs/${widget.job['id']}/run', {
        'what': _sel.toList(),
        if (rate != null && rate > 0) 'rate': rate,
        if (_since != null) 'since': '${_since!.year}-${_since!.month.toString().padLeft(2, '0')}-${_since!.day.toString().padLeft(2, '0')}',
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final columns = asMap(_probe['columns']);
    return YAlertDialog(
      title: Text(tr('Prêt à migrer : ${widget.job['label'] ?? ''}')),
      content: SizedBox(
        width: 540,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              const Icon(Icons.check_circle, color: YColors.success, size: 20),
              const SizedBox(width: 8),
              Expanded(child: Text(tr('Connexion réussie. Choisissez ce que vous voulez reprendre :'), style: TextStyle(color: hint))),
            ]),
            const SizedBox(height: 8),
            for (final w in _what.entries)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                value: _sel.contains(w.key),
                onChanged: _available(w.key) ? (v) => setState(() => v == true ? _sel.add(w.key) : _sel.remove(w.key)) : null,
                secondary: Icon(w.value.$2),
                title: Text(tr(w.value.$1)),
                subtitle: Text(
                  tr(w.key == 'stock'
                      ? (widget.modules['stock'] == true ? 'Quantités actuelles des articles (mouvement d\'ouverture)' : 'Module Stock non activé')
                      : _counts.containsKey(w.key)
                          ? '${_counts[w.key]}${(_probe['capped'] != null && _counts[w.key] == _probe['capped']) ? '+' : ''} trouvé(s)${columns[w.key] != null ? ' · colonnes : ${(columns[w.key] as Map).values.take(5).join(', ')}' : ''}'
                          : (w.key == 'purchases' && widget.modules['achats'] != true ? 'Module Achats non activé' : 'Non fourni')),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            const Divider(),
            TextField(
              controller: _rate,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                labelText: tr('Taux de conversion vers ${widget.currency == 'XOF' ? 'le franc CFA' : widget.currency} (facultatif)'),
                helperText: tr('Si l\'ancien logiciel est dans une autre devise : par exemple 655,957 pour des euros.'),
              ),
            ),
            const SizedBox(height: 10),
            Row(children: [
              Expanded(child: Text(tr(_since == null ? 'Toutes les factures, quelle que soit leur date' : 'Factures à partir du ${dateFr(_since!.toIso8601String())}'))),
              TextButton(
                onPressed: () async {
                  final d = await showDatePicker(context: context, firstDate: DateTime(2000), lastDate: DateTime.now(), initialDate: _since ?? DateTime(DateTime.now().year - 1));
                  setState(() => _since = d);
                },
                child: Text(tr(_since == null ? 'Limiter' : 'Changer')),
              ),
              if (_since != null) IconButton(onPressed: () => setState(() => _since = null), icon: const Icon(Icons.close, size: 18)),
            ]),
            const SizedBox(height: 6),
            Text(
                tr('Les factures reprises gardent leur numéro et leurs règlements, comptent dans vos statistiques et les impayés arrivent dans les relances. Elles ne créent ni mouvement de stock, ni écriture comptable, ni certification FNE.'),
                style: TextStyle(color: hint, fontSize: 12)),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: Text(tr('Plus tard'))),
        FilledButton.icon(
          onPressed: _busy || _sel.isEmpty ? null : _run,
          icon: const Icon(Icons.rocket_launch_outlined, size: 18),
          label: Text(tr('Lancer la migration')),
        ),
      ],
    );
  }
}

class _JobCard extends StatelessWidget {
  const _JobCard({required this.job, required this.onDelete});
  final Map<String, dynamic> job;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final status = '${job['status']}';
    final (color, label, icon) = switch (status) {
      'done' => (YColors.success, 'Terminée', Icons.check_circle_outline),
      'failed' => (YColors.danger, 'Échec', Icons.error_outline),
      'running' => (YColors.blue, 'En cours', Icons.sync),
      'queued' => (YColors.warning, 'En file d\'attente', Icons.schedule),
      _ => (hint, 'Prête', Icons.pause_circle_outline),
    };
    final counts = asMap(job['counts']);
    final warnings = [for (final w in (job['warnings'] as List? ?? const [])) '$w'];
    final progress = asMap(job['progress']);
    String c(String k) {
      final m = asMap(counts[k]);
      final parts = [
        if ((m['created'] ?? 0) > 0) '${m['created']} créé(s)',
        if ((m['updated'] ?? 0) > 0) '${m['updated']} mis à jour',
        if ((m['skipped'] ?? 0) > 0) '${m['skipped']} ignoré(s)',
        if ((m['failed'] ?? 0) > 0) '${m['failed']} en erreur',
      ];
      return parts.join(' · ');
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Icon(icon, color: color),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${job['label'] ?? job['source']}'), style: const TextStyle(fontWeight: FontWeight.w800)),
                Text(tr('$label${job['finished_at'] != null ? ' · ${dateTimeFr(job['finished_at'] as String)}' : ''}'), style: TextStyle(color: color, fontSize: 12.5, fontWeight: FontWeight.w600)),
              ]),
            ),
            if (status != 'running' && status != 'queued') IconButton(tooltip: tr('Retirer de l\'historique'), onPressed: onDelete, icon: const Icon(Icons.close, size: 18)),
          ]),
          if (status == 'running' || status == 'queued') ...[
            const SizedBox(height: 10),
            const LinearProgressIndicator(minHeight: 5),
            const SizedBox(height: 6),
            Text(tr(status == 'queued' ? 'Démarrage dans moins d\'une minute…' : 'Reprise : ${_what[progress['stage']]?.$1 ?? 'préparation'}…'), style: TextStyle(color: hint, fontSize: 12.5)),
          ],
          if (counts.isNotEmpty) ...[
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final k in _what.keys)
                if (counts.containsKey(k) && c(k).isNotEmpty)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .07), borderRadius: BorderRadius.circular(10)),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      Icon(_what[k]!.$2, size: 16, color: YColors.blue),
                      const SizedBox(width: 6),
                      Text(tr('${_what[k]!.$1} : ${c(k)}'), style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
                    ]),
                  ),
            ]),
          ],
          if (job['error'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('${job['error']}'), style: const TextStyle(color: YColors.danger))),
          if (warnings.isNotEmpty)
            Theme(
              data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
              child: ExpansionTile(
                tilePadding: EdgeInsets.zero,
                title: Text(tr('${warnings.length} avertissement(s)'), style: const TextStyle(fontSize: 13.5, color: YColors.warning, fontWeight: FontWeight.w700)),
                children: [
                  for (final w in warnings.take(50))
                    Align(alignment: Alignment.centerLeft, child: Padding(padding: const EdgeInsets.only(bottom: 4), child: Text(tr('• $w'), style: const TextStyle(fontSize: 12.5)))),
                ],
              ),
            ),
        ]),
      ),
    );
  }
}
