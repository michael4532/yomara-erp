import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';

import '../../core/sync_engine.dart' show newId;
import '../../i18n/i18n.dart';
import '../../pdf/doc_templates.dart';
import '../../pdf/document_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/pdf_viewer.dart';
import 'document_editor.dart' show computeLine;
import 'documents_screen.dart' show DocConfig;
import 'store_screen.dart' show buyModule;
import '../../core/json.dart';

/// Types de document auxquels un modèle peut s'appliquer (filtre de la bibliothèque et modèle par défaut).
const _docKinds = <String, (String, String, String)>{
  // clé : (entité, type, libellé)
  'invoice': ('sales_documents', 'invoice', 'Factures'),
  'proforma': ('sales_documents', 'proforma', 'Proformas'),
  'quote': ('sales_documents', 'quote', 'Devis et offres'),
  'order': ('sales_documents', 'order', 'Commandes'),
  'deposit': ('sales_documents', 'deposit', 'Acomptes'),
  'credit_note': ('sales_documents', 'credit_note', 'Avoirs'),
  'purchase_order': ('purchase_documents', 'purchase_order', 'Bons de commande fournisseur'),
};

/// Document d'exemple pour les aperçus.
Map<String, dynamic> sampleDocument(String type) {
  final lines = <Map<String, dynamic>>[
    {'description': 'Conception du site internet (5 pages)', 'quantity': 1, 'unit': 'forfait', 'unit_price': 450000, 'discount_percent': 0, 'tax_rate': 18},
    {'description': 'Hébergement et nom de domaine — 12 mois', 'quantity': 12, 'unit': 'mois', 'unit_price': 7500, 'discount_percent': 0, 'tax_rate': 18},
    {'description': 'Formation des utilisateurs', 'quantity': 2, 'unit': 'jour', 'unit_price': 60000, 'discount_percent': 10, 'tax_rate': 18},
  ];
  double sub = 0, tax = 0, total = 0;
  for (final l in lines) {
    final c = computeLine(l, 0);
    l.addAll(c..remove('gross'));
    sub += c['subtotal']!;
    tax += c['tax_amount']!;
    total += c['total']!;
  }
  final now = DateTime.now();
  return {
    'type': type,
    'number': switch (type) { 'quote' => 'DEV-2026-00018', 'proforma' => 'PRO-2026-00007', 'order' => 'CMD-2026-00031', 'deposit' => 'ACP-2026-00004', 'credit_note' => 'AV-2026-00002', 'purchase_order' => 'BCF-2026-00011', _ => 'FAC-2026-00042' },
    'status': 'validated',
    'date': isoDate(now),
    'due_date': isoDate(now.add(const Duration(days: 30))),
    'partner_name': 'Client Exemple SARL',
    'reference': 'BC-0457',
    'lines': lines,
    'subtotal': sub,
    'tax_total': tax,
    'total': total,
    'currency': 'XOF',
    'payment_method': 'bank_transfer',
    'receipt_box': type == 'invoice',
    'notes': 'Merci de votre confiance.',
  };
}

DocConfig _configFor(String kind) => _docKinds[kind]!.$1 == 'purchase_documents' ? DocConfig.purchase : DocConfig.sale;

Future<Uint8List> templatePdf(Session session, Map<String, dynamic> spec, String kind) =>
    buildDocumentPdf(session: session, config: _configFor(kind), doc: sampleDocument(_docKinds[kind]!.$2), template: spec);

/// Vignettes rendues une fois (clé : modèle + type + version).
final _thumbs = <String, Future<Uint8List?>>{};

Future<Uint8List?> _thumb(Session session, Map<String, dynamic> spec, String kind, {int dpi = 48}) {
  final key = '${spec['id']}|$kind|${spec.hashCode}|$dpi';
  return _thumbs[key] ??= () async {
    try {
      final bytes = await templatePdf(session, spec, kind);
      await for (final page in Printing.raster(bytes, pages: [0], dpi: dpi.toDouble())) {
        return await page.toPng();
      }
    } catch (_) {}
    return null;
  }();
}

class _Thumb extends StatelessWidget {
  const _Thumb({required this.spec, required this.kind});

  final Map<String, dynamic> spec;
  final String kind;

  @override
  Widget build(BuildContext context) => FutureBuilder<Uint8List?>(
        future: _thumb(context.read<Session>(), spec, kind),
        builder: (context, s) => AspectRatio(
          aspectRatio: 210 / 297,
          child: Container(
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(6), border: Border.all(color: YColors.line), boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 8, offset: Offset(0, 2))]),
            clipBehavior: Clip.antiAlias,
            child: s.data != null
                ? Image.memory(s.data!, fit: BoxFit.cover, filterQuality: FilterQuality.medium)
                : s.connectionState == ConnectionState.done
                    ? const Center(child: Icon(Icons.description_outlined, size: 40, color: YColors.muted))
                    : const Center(child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))),
          ),
        ),
      );
}

/// Bibliothèque de modèles + modèles de la société + accès au studio.
class DocTemplatesScreen extends StatefulWidget {
  const DocTemplatesScreen({super.key});

  @override
  State<DocTemplatesScreen> createState() => _DocTemplatesScreenState();
}

class _DocTemplatesScreenState extends State<DocTemplatesScreen> {
  String _kind = 'invoice';
  String _family = '';

  Future<void> _saveSettings(Map<String, dynamic> settings) async {
    final session = context.read<Session>();
    try {
      await session.api.put('company', {'settings': settings});
      await session.refreshBootstrap();
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Map<String, dynamic> get _defaults => Map<String, dynamic>.from(context.read<Session>().companySettings['doc_template_defaults'] as Map? ?? const {});

  String _defaultKey(String kind) => '${_docKinds[kind]!.$1}.${_docKinds[kind]!.$2}';

  Future<void> _useAsDefault(String id, {bool everywhere = false}) async {
    final d = _defaults;
    if (everywhere) {
      for (final k in _docKinds.keys) {
        d[_defaultKey(k)] = id;
      }
    } else {
      d[_defaultKey(_kind)] = id;
    }
    await _saveSettings({'doc_template_defaults': d});
    if (mounted) toast(context, tr(everywhere ? 'Modèle appliqué à tous les documents.' : 'Modèle par défaut des ${_docKinds[_kind]!.$3.toLowerCase()}.'));
  }

  Future<void> _preview(Map<String, dynamic> spec) async {
    final bytes = await templatePdf(context.read<Session>(), spec, _kind);
    await previewPdf(name: '${spec['name']} — ${_docKinds[_kind]!.$3}.pdf', onLayout: (_) async => bytes);
  }

  /// Personnalisation simple, sans studio : couleurs, polices, total, titres, filigrane. Un modèle de la bibliothèque
  /// devient « votre » modèle (copie) ; un modèle à vous est modifié directement.
  Future<void> _tune(Map<String, dynamic> spec, {required bool builtin}) async {
    final session = context.read<Session>();
    final base = builtin
        ? {...spec, 'id': 'tpl_${newId().substring(0, 8)}', 'name': '${spec['name']} — ${session.company['name'] ?? 'ma société'}'.trim(), 'builtin': false, 'base': spec['id']}
        : Map<String, dynamic>.from(spec);
    final saved = await showFormDialog<Map<String, dynamic>>(context, title: 'Personnaliser « ${spec['name']} »', maxWidth: 640, builder: (_) => _TuneTemplate(spec: base, onPreview: _preview));
    if (saved == null || !mounted) return;
    final list = customTemplates(session)..removeWhere((t) => t['id'] == saved['id']);
    list.add(saved..remove('builtin'));
    await _saveSettings({'doc_templates': list});
    if (!mounted) return;
    if (!builtin) {
      toast(context, tr('Modèle « ${saved['name']} » enregistré.'));
      return;
    }
    if (await confirm(context, tr('Modèle enregistré'), tr('« ${saved['name']} » est dans « Vos modèles ». L\'utiliser pour les ${_docKinds[_kind]!.$3.toLowerCase()} ?'), ok: tr('Utiliser')) && mounted) {
      await _useAsDefault('${saved['id']}');
    }
  }

  Future<void> _openStudio([Map<String, dynamic>? spec, bool copy = false]) async {
    final session = context.read<Session>();
    if (!templateStudioUnlocked(session)) {
      final go = await confirm(context, tr('Studio de modèles'), tr('Créer et personnaliser vos propres modèles par glisser-déposer demande le module « Studio de modèles » (achat définitif). Ouvrir la boutique ?'), ok: tr('Voir le module'));
      if (go && mounted) await buyModule(context, templateStudioModule);
      return;
    }
    final base = spec == null
        ? {...builtinTemplates.first.toSpec(), 'id': 'tpl_${newId().substring(0, 8)}', 'name': 'Mon modèle', 'builtin': false}
        : copy
            ? {...spec, 'id': 'tpl_${newId().substring(0, 8)}', 'name': '${spec['name']} (personnalisé)', 'builtin': false}
            : spec;
    final saved = await Navigator.of(context).push<Map<String, dynamic>>(MaterialPageRoute(builder: (_) => TemplateStudio(spec: base, kind: _kind)));
    if (saved == null || !mounted) return;
    final list = customTemplates(session)..removeWhere((t) => t['id'] == saved['id']);
    list.add(saved..remove('builtin'));
    await _saveSettings({'doc_templates': list});
    if (mounted) toast(context, tr('Modèle « ${saved['name']} » enregistré.'));
  }

  Future<void> _delete(Map<String, dynamic> t) async {
    if (!await confirm(context, tr('Supprimer le modèle'), tr('Supprimer « ${t['name']} » ? Les documents qui l\'utilisaient reprendront le modèle par défaut.'), ok: tr('Supprimer'), danger: true)) return;
    if (!mounted) return;
    final list = customTemplates(context.read<Session>())..removeWhere((x) => x['id'] == t['id']);
    final d = _defaults..removeWhere((_, v) => v == t['id']);
    await _saveSettings({'doc_templates': list, 'doc_template_defaults': d});
  }

  Widget _card(Map<String, dynamic> spec, {DocTemplate? builtin}) {
    final session = context.watch<Session>();
    final id = '${spec['id']}';
    final unlocked = templateUnlocked(session, id);
    final isDefault = defaultTemplateId(session, _docKinds[_kind]!.$1, _docKinds[_kind]!.$2) == id;
    final manage = session.can('core.settings.manage');
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          InkWell(
            onTap: () => _preview(spec),
            child: Stack(children: [
              _Thumb(spec: spec, kind: _kind),
              if (!unlocked)
                Positioned(
                  top: 8,
                  right: 8,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(color: const Color(0xFF1A1A1A), borderRadius: BorderRadius.circular(20)),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      const Icon(Icons.workspace_premium, size: 14, color: Color(0xFFFFC531)),
                      const SizedBox(width: 4),
                      Text(tr('Premium'), style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                    ]),
                  ),
                ),
              if (isDefault)
                Positioned(bottom: 8, left: 8, child: Container(decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)), child: StatusChip(tr('Par défaut'), color: YColors.success, icon: Icons.check_circle))),
            ]),
          ),
          const SizedBox(height: 10),
          Text(tr('${spec['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
          if (builtin != null) Text(tr(builtin.description), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor), maxLines: 2, overflow: TextOverflow.ellipsis),
          const SizedBox(height: 6),
          Text(
            tr(builtin == null ? 'Votre modèle' : (builtin.premium ? (unlocked ? 'Premium · acheté' : 'Premium · ${money(builtin.price, 'XOF')}') : 'Gratuit')),
            style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: builtin?.premium == true && !unlocked ? const Color(0xFFB8935A) : YColors.success),
          ),
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 6, children: [
            if (unlocked && manage && !isDefault) FilledButton.tonal(onPressed: () => _useAsDefault(id), child: Text(tr('Utiliser'))),
            if (!unlocked && builtin?.module != null) FilledButton.icon(onPressed: () => buyModule(context, builtin!.module!), icon: const Icon(Icons.lock_open, size: 16), label: Text(tr('Débloquer'))),
            IconButton(tooltip: tr('Aperçu'), onPressed: () => _preview(spec), icon: const Icon(Icons.visibility_outlined)),
            if (manage)
              PopupMenuButton<String>(
                tooltip: tr('Plus'),
                onSelected: (v) => switch (v) {
                  'all' => _useAsDefault(id, everywhere: true),
                  'copy' => _openStudio(spec, true),
                  'tune' => _tune(spec, builtin: builtin != null),
                  'edit' => _openStudio(spec),
                  'delete' => _delete(spec),
                  _ => null,
                },
                itemBuilder: (_) => [
                  if (unlocked) PopupMenuItem(value: 'tune', child: Text(tr('Personnaliser (couleurs, polices, titres)'))),
                  if (unlocked) PopupMenuItem(value: 'all', child: Text(tr('Utiliser pour tous les documents'))),
                  if (builtin == null) PopupMenuItem(value: 'edit', child: Text(tr('Modifier dans le studio'))),
                  if (unlocked) PopupMenuItem(value: 'copy', child: Text(tr('Recomposer dans le studio'))),
                  if (builtin == null) PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'))),
                ],
              ),
          ]),
        ]),
      ),
    );
  }

  Widget _grid(List<Widget> cards) => LayoutBuilder(builder: (context, c) {
        final cols = (c.maxWidth / 250).floor().clamp(1, 6);
        return GridView.count(
          crossAxisCount: cols,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 0.52,
          children: cards,
        );
      });

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final mine = customTemplates(session);
    final hint = Theme.of(context).hintColor;
    final studio = templateStudioUnlocked(session);
    return Scaffold(
      appBar: AppBar(title: Text(tr('Modèles de documents')), actions: [
        if (session.can('core.settings.manage'))
          Padding(
            padding: const EdgeInsets.only(right: 12),
            child: FilledButton.icon(onPressed: () => _openStudio(), icon: Icon(studio ? Icons.design_services : Icons.lock_outline), label: Text(tr('Créer un modèle'))),
          ),
      ]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(tr('Choisissez l\'apparence de vos factures, proformas, devis et offres, commandes, acomptes et avoirs. '
            'Le modèle par défaut s\'applique à chaque type de document ; un document peut aussi choisir le sien.'), style: TextStyle(color: hint)),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final e in _docKinds.entries) ChoiceChip(label: Text(tr(e.value.$3)), selected: _kind == e.key, onSelected: (_) => setState(() => _kind = e.key)),
        ]),
        const SizedBox(height: 16),
        Text(tr('Mes modèles'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 8),
        if (mine.isEmpty)
          Card(
            child: ListTile(
              leading: Icon(studio ? Icons.design_services : Icons.workspace_premium, color: YColors.blue),
              title: Text(tr(studio ? 'Aucun modèle pour l\'instant' : 'Créez vos propres modèles')),
              subtitle: Text(tr(studio
                  ? 'Partez de zéro ou personnalisez un modèle de la bibliothèque : glissez les blocs, choisissez vos couleurs, voyez le résultat en direct.'
                  : 'Le Studio de modèles permet de composer vos factures et offres par glisser-déposer : blocs, couleurs, police, titres.')),
              trailing: FilledButton(onPressed: () => _openStudio(), child: Text(tr(studio ? 'Créer' : 'Découvrir'))),
            ),
          )
        else
          _grid([for (final t in mine) _card(t)]),
        const SizedBox(height: 20),
        Text(tr('Bibliothèque'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 8),
        // Collection premium : tous les modèles premium en un seul achat
        if (!session.isSuperAdmin && !session.hasModule(templateCollectionModule) && builtinTemplates.any((t) => t.premium && !templateUnlocked(session, t.id)))
          Card(
            color: const Color(0xFF1A1A1A),
            child: ListTile(
              leading: const Icon(Icons.workspace_premium, color: Color(0xFFC9A45C), size: 32),
              title: Text(tr('Collection premium'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
              subtitle: Text(
                tr('Les ${builtinTemplates.where((t) => t.premium).length} modèles premium, et ceux à venir, en un seul achat définitif : ${money(templateCollectionPrice, 'XOF')}.'),
                style: const TextStyle(color: Colors.white70),
              ),
              trailing: FilledButton(
                style: FilledButton.styleFrom(backgroundColor: const Color(0xFFC9A45C), foregroundColor: const Color(0xFF1A1A1A)),
                onPressed: () => buyModule(context, templateCollectionModule),
                child: Text(tr('Débloquer tout')),
              ),
            ),
          ),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final f in ['', 'Gratuits', ...templateFamilies])
            if (f == '' || f == 'Gratuits' || builtinTemplates.any((t) => t.family == f))
              FilterChip(
                label: Text(tr(f == '' ? 'Tous (${builtinTemplates.length})' : f)),
                selected: _family == f,
                onSelected: (_) => setState(() => _family = f),
              ),
        ]),
        const SizedBox(height: 10),
        _grid([
          for (final t in builtinTemplates)
            if (_family == '' || (_family == 'Gratuits' ? !t.premium : t.family == _family)) _card(t.toSpec(), builtin: t),
        ]),
      ]),
    );
  }
}

/// Studio : composition d'un modèle par glisser-déposer, aperçu en direct.
class TemplateStudio extends StatefulWidget {
  const TemplateStudio({super.key, required this.spec, required this.kind});

  final Map<String, dynamic> spec;
  final String kind;

  @override
  State<TemplateStudio> createState() => _TemplateStudioState();
}

const _swatches = ['#0B1B3A', '#111827', '#1F6FEB', '#0E7C7B', '#3B2F8F', '#23395B', '#B91C1C', '#F77F00', '#009E60', '#B8935A', '#FFC531', '#7C6CF2', '#F4A259', '#DB2777', '#64748B'];

class _TemplateStudioState extends State<TemplateStudio> {
  late Map<String, dynamic> _style;
  late List<Map<String, dynamic>> _blocks;
  late Map<String, dynamic> _titles;
  late final TextEditingController _name;
  late String _kind = widget.kind;
  Uint8List? _png;
  Timer? _debounce;
  int _version = 0;
  bool _dirty = false;

  @override
  void initState() {
    super.initState();
    _style = asMap(widget.spec['style']);
    _blocks = [for (final b in (widget.spec['blocks'] as List? ?? defaultBlocks)) Map<String, dynamic>.from(b as Map)..putIfAbsent('_k', newId)];
    _titles = asMap(widget.spec['titles']);
    _name = TextEditingController(text: '${widget.spec['name'] ?? 'Mon modèle'}');
    _render();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _name.dispose();
    super.dispose();
  }

  Map<String, dynamic> get _spec => {
        'id': widget.spec['id'],
        'name': _name.text.trim().isEmpty ? 'Mon modèle' : _name.text.trim(),
        'style': _style,
        'blocks': [for (final b in _blocks) Map<String, dynamic>.from(b)..remove('_k')],
        'titles': {for (final e in _titles.entries) if ('${e.value}'.trim().isNotEmpty) e.key: '${e.value}'.trim()},
      };

  /// Aperçu recalculé 400 ms après la dernière modification.
  void _changed() {
    _dirty = true;
    setState(() {});
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), _render);
  }

  Future<void> _render() async {
    final v = ++_version;
    try {
      final bytes = await templatePdf(context.read<Session>(), _spec, _kind);
      await for (final page in Printing.raster(bytes, pages: [0], dpi: 80)) {
        final png = await page.toPng();
        if (mounted && v == _version) setState(() => _png = png);
        break;
      }
    } catch (_) {}
  }

  void _addBlock(String type, [int? at]) {
    final b = <String, dynamic>{'type': type, '_k': newId(), if (type == 'text') 'text': 'Votre texte ici', if (type == 'spacer') 'height': 16};
    if (at == null) {
      // Avant le pied de page s'il existe, sinon à la fin
      final foot = _blocks.indexWhere((x) => x['type'] == 'footer_note');
      _blocks.insert(foot < 0 ? _blocks.length : foot, b);
    } else {
      _blocks.insert(at.clamp(0, _blocks.length), b);
    }
    _changed();
  }

  Future<bool> _leave() async {
    if (!_dirty) return true;
    return confirm(context, tr('Quitter le studio'), tr('Les modifications de ce modèle ne sont pas enregistrées. Quitter quand même ?'), ok: tr('Quitter'));
  }

  // ---------- Panneau des blocs ----------

  Widget _palette() => Wrap(spacing: 6, runSpacing: 6, children: [
        for (final e in docBlockTypes.entries)
          Draggable<String>(
            data: e.key,
            feedback: Material(color: Colors.transparent, child: Chip(avatar: Icon(_icon(e.key), size: 16), label: Text(tr(e.value.$1)))),
            childWhenDragging: Opacity(opacity: .4, child: Chip(label: Text(tr(e.value.$1)))),
            child: ActionChip(avatar: Icon(_icon(e.key), size: 16), label: Text(tr(e.value.$1)), tooltip: tr('${e.value.$2} — glissez dans la page ou cliquez'), onPressed: () => _addBlock(e.key)),
          ),
      ]);

  IconData _icon(String t) => switch (t) {
        'header' => Icons.web_asset,
        'parties' => Icons.person_outline,
        'info' => Icons.tag,
        'lines' => Icons.table_rows_outlined,
        'totals' => Icons.functions,
        'words' => Icons.text_fields,
        'payment' => Icons.account_balance_outlined,
        'notes' => Icons.notes,
        'fne' => Icons.verified_outlined,
        'receipt' => Icons.how_to_reg_outlined,
        'acceptance' => Icons.handshake_outlined,
        'signature' => Icons.draw_outlined,
        'text' => Icons.short_text,
        'divider' => Icons.horizontal_rule,
        'spacer' => Icons.height,
        'footer_note' => Icons.vertical_align_bottom,
        _ => Icons.widgets_outlined,
      };

  Widget _blockOptions(Map<String, dynamic> b) {
    switch (b['type']) {
      case 'text':
        return Column(children: [
          TextFormField(
            initialValue: '${b['text'] ?? ''}',
            maxLines: 3,
            decoration: InputDecoration(labelText: tr('Texte'), isDense: true),
            onChanged: (v) {
              b['text'] = v;
              _changed();
            },
          ),
          const SizedBox(height: 6),
          Row(children: [
            SegmentedButton<String>(
              showSelectedIcon: false,
              segments: const [ButtonSegment(value: 'left', icon: Icon(Icons.format_align_left, size: 16)), ButtonSegment(value: 'center', icon: Icon(Icons.format_align_center, size: 16)), ButtonSegment(value: 'right', icon: Icon(Icons.format_align_right, size: 16))],
              selected: {'${b['align'] ?? 'left'}'},
              onSelectionChanged: (s) {
                b['align'] = s.first;
                _changed();
              },
            ),
            const SizedBox(width: 8),
            FilterChip(label: Text(tr('Gras')), selected: b['bold'] == true, onSelected: (v) {
              b['bold'] = v;
              _changed();
            }),
            const SizedBox(width: 6),
            FilterChip(label: Text(tr('Couleur')), selected: b['color'] == 'primary', onSelected: (v) {
              b['color'] = v ? 'primary' : null;
              _changed();
            }),
          ]),
          Slider(
            value: toDouble(b['size'] ?? 10).clamp(7, 20),
            min: 7,
            max: 20,
            divisions: 13,
            label: '${toDouble(b['size'] ?? 10).round()} pt',
            onChanged: (v) {
              b['size'] = v.roundToDouble();
              _changed();
            },
          ),
        ]);
      case 'spacer':
        return Slider(
          value: toDouble(b['height'] ?? 16).clamp(4, 120),
          min: 4,
          max: 120,
          label: '${toDouble(b['height'] ?? 16).round()}',
          onChanged: (v) {
            b['height'] = v.roundToDouble();
            _changed();
          },
        );
      case 'parties':
      case 'signature':
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SegmentedButton<String>(
            showSelectedIcon: false,
            segments: [
              ButtonSegment(value: 'left', label: Text(tr('À gauche'))),
              ButtonSegment(value: 'right', label: Text(tr('À droite'))),
              if (b['type'] == 'parties') ButtonSegment(value: 'full', label: Text(tr('Pleine largeur'))),
            ],
            selected: {'${b['align'] ?? 'right'}'},
            onSelectionChanged: (s) {
              b['align'] = s.first;
              _changed();
            },
          ),
          if (b['type'] == 'signature') ...[
            const SizedBox(height: 6),
            TextFormField(
              initialValue: '${b['label'] ?? ''}',
              decoration: InputDecoration(labelText: tr('Titre du cadre'), hintText: tr('Pour la société'), isDense: true),
              onChanged: (v) {
                b['label'] = v.trim().isEmpty ? null : v;
                _changed();
              },
            ),
          ],
        ]);
      case 'divider':
        return FilterChip(label: Text(tr('Couleur d\'accent')), selected: b['color'] == 'accent', onSelected: (v) {
          b['color'] = v ? 'accent' : null;
          _changed();
        });
      default:
        return const SizedBox.shrink();
    }
  }

  Widget _blocksPanel() => DragTarget<String>(
        onAcceptWithDetails: (d) => _addBlock(d.data),
        builder: (context, cand, _) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr('Blocs disponibles'), style: const TextStyle(fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(tr('Glissez un bloc dans la page, ou cliquez pour l\'ajouter. Réordonnez avec la poignée.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          const SizedBox(height: 8),
          _palette(),
          const SizedBox(height: 14),
          Text(tr('Page'), style: const TextStyle(fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          AnimatedContainer(
            duration: const Duration(milliseconds: 150),
            decoration: BoxDecoration(border: Border.all(color: cand.isNotEmpty ? YColors.blue : YColors.line, width: cand.isNotEmpty ? 2 : 1), borderRadius: BorderRadius.circular(10)),
            child: ReorderableListView(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              buildDefaultDragHandles: false,
              onReorderItem: (a, b) {
                _blocks.insert(b, _blocks.removeAt(a));
                _changed();
              },
              children: [
                for (var i = 0; i < _blocks.length; i++)
                  DragTarget<String>(
                    key: ValueKey(_blocks[i]['_k']),
                    // Bloc déposé sur une ligne : inséré à cet endroit
                    onAcceptWithDetails: (d) => _addBlock(d.data, i),
                    builder: (context, over, _) => Container(
                    decoration: BoxDecoration(
                      border: Border(
                        top: BorderSide(color: over.isNotEmpty ? YColors.blue : Colors.transparent, width: 3),
                        bottom: BorderSide(color: YColors.line.withValues(alpha: .6)),
                      ),
                    ),
                    child: ExpansionTile(
                      dense: true,
                      tilePadding: const EdgeInsets.only(left: 4, right: 8),
                      leading: Row(mainAxisSize: MainAxisSize.min, children: [
                        ReorderableDragStartListener(index: i, child: const Padding(padding: EdgeInsets.all(6), child: Icon(Icons.drag_indicator, size: 20))),
                        Icon(_icon('${_blocks[i]['type']}'), size: 18, color: _blocks[i]['hidden'] == true ? Theme.of(context).disabledColor : YColors.blue),
                      ]),
                      title: Text(
                        tr(docBlockTypes['${_blocks[i]['type']}']?.$1 ?? '${_blocks[i]['type']}'),
                        style: TextStyle(fontWeight: FontWeight.w600, decoration: _blocks[i]['hidden'] == true ? TextDecoration.lineThrough : null),
                      ),
                      subtitle: _blocks[i]['type'] == 'text' ? Text('${_blocks[i]['text'] ?? ''}', maxLines: 1, overflow: TextOverflow.ellipsis) : null,
                      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                        IconButton(
                          tooltip: tr(_blocks[i]['hidden'] == true ? 'Afficher' : 'Masquer'),
                          icon: Icon(_blocks[i]['hidden'] == true ? Icons.visibility_off_outlined : Icons.visibility_outlined, size: 18),
                          onPressed: () {
                            _blocks[i]['hidden'] = _blocks[i]['hidden'] != true;
                            _changed();
                          },
                        ),
                        IconButton(
                          tooltip: tr('Retirer'),
                          icon: const Icon(Icons.close, size: 18),
                          onPressed: () {
                            _blocks.removeAt(i);
                            _changed();
                          },
                        ),
                      ]),
                      childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                      children: [_blockOptions(_blocks[i])],
                    ),
                  ),
                  ),
              ],
            ),
          ),
          if (cand.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Déposez pour ajouter le bloc'), style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w600))),
        ]),
      );

  // ---------- Panneau du style ----------

  Widget _color(String key, String label) {
    final cur = '${_style[key] ?? '#0B1B3A'}'.toUpperCase();
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(tr(label), style: const TextStyle(fontWeight: FontWeight.w700)),
      const SizedBox(height: 6),
      Wrap(spacing: 6, runSpacing: 6, children: [
        for (final c in _swatches)
          InkWell(
            onTap: () {
              _style[key] = c;
              _changed();
            },
            borderRadius: BorderRadius.circular(20),
            child: Container(
              width: 28,
              height: 28,
              decoration: BoxDecoration(color: Color(int.parse('FF${c.substring(1)}', radix: 16)), shape: BoxShape.circle, border: Border.all(color: cur == c ? YColors.blue : Colors.black12, width: cur == c ? 3 : 1)),
            ),
          ),
        SizedBox(
          width: 110,
          child: TextFormField(
            key: ValueKey('$key$cur'),
            initialValue: cur,
            decoration: const InputDecoration(isDense: true, prefixText: ''),
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[#0-9a-fA-F]')), LengthLimitingTextInputFormatter(7)],
            onFieldSubmitted: (v) {
              if (RegExp(r'^#?[0-9a-fA-F]{6}$').hasMatch(v)) {
                _style[key] = v.startsWith('#') ? v.toUpperCase() : '#${v.toUpperCase()}';
                _changed();
              }
            },
          ),
        ),
      ]),
    ]);
  }

  Widget _choice(String key, String label, Map<String, String> options) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr(label), style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final e in options.entries)
            ChoiceChip(
              label: Text(tr(e.value)),
              selected: '${_style[key] ?? options.keys.first}' == e.key,
              onSelected: (_) {
                _style[key] = e.key;
                _changed();
              },
            ),
        ]),
      ]);

  Widget _stylePanel() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        TextField(controller: _name, decoration: InputDecoration(labelText: tr('Nom du modèle')), onChanged: (_) => _dirty = true),
        const SizedBox(height: 16),
        _color('primary', 'Couleur principale'),
        const SizedBox(height: 14),
        _color('accent', 'Couleur d\'accent'),
        const SizedBox(height: 14),
        _choice('header', 'En-tête', headerStyles),
        const SizedBox(height: 14),
        _choice('table', 'Tableau des lignes', tableStyles),
        const SizedBox(height: 14),
        _choice('font', 'Police', fontChoices),
        const SizedBox(height: 14),
        _choice('title_font', 'Police des titres', titleFontChoices),
        const SizedBox(height: 14),
        _choice('decor', 'Décor de page', decorChoices),
        if (_style['decor'] == 'kente') ...[const SizedBox(height: 14), _color('third', 'Troisième couleur de la frise')],
        const SizedBox(height: 14),
        _choice('totals', 'Total', totalsStyles),
        const SizedBox(height: 14),
        Text(tr('Arrondis'), style: const TextStyle(fontWeight: FontWeight.w700)),
        Slider(
          value: toDouble(_style['radius'] ?? 6).clamp(0, 14),
          min: 0,
          max: 14,
          divisions: 7,
          label: '${toDouble(_style['radius'] ?? 6).round()}',
          onChanged: (v) {
            _style['radius'] = v;
            _changed();
          },
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr('Liseré latéral')),
          value: _style['sidebar'] == true,
          onChanged: (v) {
            _style['sidebar'] = v;
            _changed();
          },
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr('Filigrane du logo')),
          subtitle: Text(tr('Votre logo, très clair, au centre de chaque page')),
          value: _style['watermark'] == true,
          onChanged: (v) {
            _style['watermark'] = v;
            _changed();
          },
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr('Bandeau en pied de page')),
          value: _style['footer_band'] == true,
          onChanged: (v) {
            _style['footer_band'] = v;
            _changed();
          },
        ),
        const SizedBox(height: 8),
        Text(tr('Titres des documents'), style: const TextStyle(fontWeight: FontWeight.w700)),
        Text(tr('Laissez vide pour le titre habituel (ex. « OFFRE COMMERCIALE » pour un devis).'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        const SizedBox(height: 6),
        for (final e in _docKinds.entries)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: TextFormField(
              initialValue: '${_titles[e.value.$2] ?? ''}',
              decoration: InputDecoration(labelText: tr(e.value.$3), isDense: true),
              textCapitalization: TextCapitalization.characters,
              onChanged: (v) {
                _titles[e.value.$2] = v;
                _changed();
              },
            ),
          ),
      ]);

  Widget _preview() => Column(children: [
        Wrap(spacing: 6, runSpacing: 6, alignment: WrapAlignment.center, children: [
          for (final e in _docKinds.entries)
            ChoiceChip(
              label: Text(tr(e.value.$3)),
              selected: _kind == e.key,
              onSelected: (_) {
                _kind = e.key;
                _changed();
              },
            ),
        ]),
        const SizedBox(height: 10),
        Expanded(
          child: Center(
            child: AspectRatio(
              aspectRatio: 210 / 297,
              child: Container(
                decoration: BoxDecoration(color: Colors.white, boxShadow: const [BoxShadow(color: Color(0x22000000), blurRadius: 16, offset: Offset(0, 4))], borderRadius: BorderRadius.circular(4)),
                clipBehavior: Clip.antiAlias,
                child: _png == null ? const Center(child: CircularProgressIndicator()) : Image.memory(_png!, fit: BoxFit.contain, gaplessPlayback: true, filterQuality: FilterQuality.high),
              ),
            ),
          ),
        ),
      ]);

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.of(context).size.width >= 1000;
    final save = FilledButton.icon(onPressed: () => Navigator.pop(context, _spec), icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer le modèle')));
    final body = wide
        ? Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            SizedBox(
              width: 420,
              child: DefaultTabController(
                length: 2,
                child: Column(children: [
                  TabBar(tabs: [Tab(text: tr('Blocs')), Tab(text: tr('Style'))]),
                  Expanded(
                    child: TabBarView(children: [
                      SingleChildScrollView(padding: const EdgeInsets.all(16), child: _blocksPanel()),
                      SingleChildScrollView(padding: const EdgeInsets.all(16), child: _stylePanel()),
                    ]),
                  ),
                ]),
              ),
            ),
            const VerticalDivider(width: 1),
            Expanded(child: Container(color: const Color(0xFFE9EDF3), padding: const EdgeInsets.all(20), child: _preview())),
          ])
        : DefaultTabController(
            length: 3,
            child: Column(children: [
              TabBar(tabs: [Tab(text: tr('Blocs')), Tab(text: tr('Style')), Tab(text: tr('Aperçu'))]),
              Expanded(
                child: TabBarView(children: [
                  SingleChildScrollView(padding: const EdgeInsets.all(16), child: _blocksPanel()),
                  SingleChildScrollView(padding: const EdgeInsets.all(16), child: _stylePanel()),
                  Container(color: const Color(0xFFE9EDF3), padding: const EdgeInsets.all(12), child: _preview()),
                ]),
              ),
            ]),
          );
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (await _leave() && context.mounted) Navigator.pop(context);
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(tr('Studio de modèles')),
          actions: [Padding(padding: const EdgeInsets.only(right: 12), child: save)],
        ),
        body: body,
      ),
    );
  }
}

/// Fenêtre « Personnaliser » : réglages courants d'un modèle, accessibles à tous (le studio sert à recomposer la mise en page).
class _TuneTemplate extends StatefulWidget {
  const _TuneTemplate({required this.spec, required this.onPreview});

  final Map<String, dynamic> spec;
  final Future<void> Function(Map<String, dynamic>) onPreview;

  @override
  State<_TuneTemplate> createState() => _TuneTemplateState();
}

class _TuneTemplateState extends State<_TuneTemplate> {
  late final Map<String, dynamic> _style = Map<String, dynamic>.from(widget.spec['style'] as Map? ?? const {});
  late final Map<String, dynamic> _titles = Map<String, dynamic>.from(widget.spec['titles'] as Map? ?? const {});
  late final _name = TextEditingController(text: '${widget.spec['name'] ?? ''}');
  late final _titleCtl = {
    for (final k in const ['invoice', 'proforma', 'quote']) k: TextEditingController(text: '${_titles[k] ?? ''}'),
  };

  Map<String, dynamic> get _result {
    final titles = {..._titles};
    for (final e in _titleCtl.entries) {
      final v = e.value.text.trim().toUpperCase();
      if (v.isEmpty) {
        titles.remove(e.key);
      } else {
        titles[e.key] = v;
      }
    }
    return {...widget.spec, 'name': _name.text.trim().isEmpty ? '${widget.spec['name']}' : _name.text.trim(), 'style': _style, 'titles': titles};
  }

  Widget _colors(String key, String label) {
    final cur = '${_style[key] ?? '#0B1B3A'}'.toUpperCase();
    return Field(
      label: tr(label),
      child: Wrap(spacing: 6, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
        for (final c in _swatches)
          InkWell(
            onTap: () => setState(() => _style[key] = c),
            borderRadius: BorderRadius.circular(20),
            child: Container(
              width: 30,
              height: 30,
              decoration: BoxDecoration(color: Color(int.parse('FF${c.substring(1)}', radix: 16)), shape: BoxShape.circle, border: Border.all(color: cur == c ? YColors.blue : Colors.black12, width: cur == c ? 3 : 1)),
            ),
          ),
        SizedBox(
          width: 110,
          child: TextFormField(
            key: ValueKey('$key$cur'),
            initialValue: cur,
            decoration: const InputDecoration(isDense: true),
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[#0-9a-fA-F]')), LengthLimitingTextInputFormatter(7)],
            onChanged: (v) {
              if (RegExp(r'^#?[0-9a-fA-F]{6}$').hasMatch(v)) setState(() => _style[key] = v.startsWith('#') ? v.toUpperCase() : '#${v.toUpperCase()}');
            },
          ),
        ),
      ]),
    );
  }

  Widget _drop(String key, String label, Map<String, String> options) => Field(
        label: tr(label),
        child: DropdownButtonFormField<String>(
          initialValue: options.containsKey('${_style[key] ?? ''}') ? '${_style[key] ?? ''}' : options.keys.first,
          isExpanded: true,
          items: [for (final o in options.entries) DropdownMenuItem(value: o.key, child: Text(tr(o.value)))],
          onChanged: (v) => setState(() => _style[key] = v),
        ),
      );

  @override
  Widget build(BuildContext context) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 4, 24, 24), children: [
        Text(tr('Adaptez ce modèle à votre image. La mise en page reste celle du modèle ; pour la recomposer bloc par bloc, utilisez le studio.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
        const SizedBox(height: 12),
        Field(label: tr('Nom du modèle'), child: TextField(controller: _name)),
        _colors('primary', 'Couleur principale'),
        _colors('accent', 'Couleur d\'accent'),
        Row(children: [
          Expanded(child: _drop('font', 'Police du texte', fontChoices)),
          const SizedBox(width: 12),
          Expanded(child: _drop('title_font', 'Police des titres', titleFontChoices)),
        ]),
        _drop('totals', 'Présentation du total', totalsStyles),
        Text(tr('Titres des documents (vide = titre habituel)'), style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Row(children: [
          for (final (k, hint) in const [('invoice', 'FACTURE'), ('proforma', 'FACTURE PROFORMA'), ('quote', 'DEVIS')]) ...[
            Expanded(child: TextField(controller: _titleCtl[k], textCapitalization: TextCapitalization.characters, decoration: InputDecoration(isDense: true, hintText: tr(hint)))),
            if (k != 'quote') const SizedBox(width: 8),
          ],
        ]),
        const SizedBox(height: 6),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Filigrane du logo')), value: _style['watermark'] == true, onChanged: (v) => setState(() => _style['watermark'] = v)),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Bandeau en pied de page')), value: _style['footer_band'] == true, onChanged: (v) => setState(() => _style['footer_band'] = v)),
        const SizedBox(height: 10),
        Row(children: [
          OutlinedButton.icon(onPressed: () => widget.onPreview(_result), icon: const Icon(Icons.visibility_outlined), label: Text(tr('Aperçu'))),
          const Spacer(),
          TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
          const SizedBox(width: 8),
          FilledButton.icon(onPressed: () => Navigator.pop(context, _result), icon: const Icon(Icons.check), label: Text(tr('Enregistrer'))),
        ]),
      ]);
}
