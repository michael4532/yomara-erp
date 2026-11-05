import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/api_client.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'email_builder.dart';
import 'mk_common.dart';
import '../../../i18n/i18n.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const formFieldLabels = {'first_name': 'Prénom', 'last_name': 'Nom', 'email': 'E-mail', 'phone': 'Téléphone / WhatsApp', 'company_name': 'Société', 'city': 'Ville', 'birthday': 'Date d\'anniversaire'};

class FormsTab extends StatefulWidget {
  const FormsTab({super.key, required this.overview, required this.canManage, required this.reload});

  final Map<String, dynamic> overview;
  final bool canManage;
  final Future<void> Function() reload;

  @override
  State<FormsTab> createState() => _FormsTabState();
}

class _FormsTabState extends State<FormsTab> {
  List<Map<String, dynamic>>? _forms;

  ApiClient get _api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = mkMap(await _api.get('marketing/forms'));
      if (mounted) setState(() => _forms = mkList(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _create() async {
    final name = TextEditingController();
    var kind = 'page';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Nouveau formulaire ou page')),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(controller: name, autofocus: true, decoration: InputDecoration(labelText: tr('Nom'), hintText: tr('Ex. Inscription à la newsletter'))),
              const SizedBox(height: 12),
              RadioGroup<String>(
                groupValue: kind,
                onChanged: (v) => set(() => kind = v ?? kind),
                child: Column(children: [
                  RadioListTile(
                    contentPadding: EdgeInsets.zero,
                    value: 'page',
                    title: Text(tr('Page de destination')),
                    subtitle: Text(tr('Une page complète (bannière, texte, offre…) avec ou sans formulaire, à partager sur les réseaux, en QR code ou dans votre bio.')),
                  ),
                  RadioListTile(
                    contentPadding: EdgeInsets.zero,
                    value: 'form',
                    title: Text(tr('Formulaire d\'inscription')),
                    subtitle: Text(tr('Un formulaire simple à intégrer sur votre site (code fourni) ou à partager par lien.')),
                  ),
                ]),
              ),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Créer')))],
        ),
      ),
    );
    if (ok != true || name.text.trim().isEmpty || !mounted) return;
    Map<String, dynamic>? layout;
    if (kind == 'page') {
      layout = await pickLayout(context, title: tr('Contenu de la page'));
      if (!mounted) return;
    }
    try {
      final f = mkMap(await _api.post('marketing/forms', {
        'kind': kind,
        'name': name.text.trim(),
        'title': kind == 'form' ? name.text.trim() : 'Inscrivez-vous',
        'layout': ?layout?['key'],
        'button_label': 'Je m\'inscris',
        'fields': [
          {'key': 'first_name', 'required': true},
          {'key': 'email', 'required': true},
          if (kind == 'page') {'key': 'phone', 'required': false},
        ],
      }));
      if (!mounted) return;
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => FormScreen(form: f, lists: mkList(widget.overview['lists']))));
      _load();
      widget.reload();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final forms = _forms;
    if (forms == null) return const Center(child: CircularProgressIndicator());
    final hint = Theme.of(context).hintColor;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
        Row(children: [
          Expanded(
            child: Text(tr('Collectez de nouveaux contacts : pages de destination et formulaires d\'inscription avec double confirmation (opt-in) et consentement enregistré.'),
                style: TextStyle(color: hint)),
          ),
          if (widget.canManage) FilledButton.icon(onPressed: _create, icon: const Icon(Icons.add), label: Text(tr('Créer'))),
        ]),
        const SizedBox(height: 12),
        if (forms.isEmpty)
          EmptyState(icon: Icons.dynamic_form_outlined, title: tr('Aucun formulaire'), message: tr('Créez une page d\'inscription à partager sur WhatsApp, Facebook ou en QR code en boutique.'))
        else
          Wrap(spacing: 12, runSpacing: 12, children: [
            for (final f in forms)
              SizedBox(
                width: 320,
                child: Card(
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: widget.canManage
                        ? () async {
                            await Navigator.of(context).push(MaterialPageRoute(builder: (_) => FormScreen(form: f, lists: mkList(widget.overview['lists']))));
                            _load();
                          }
                        : null,
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Icon(f['kind'] == 'page' ? Icons.web : Icons.dynamic_form_outlined, color: YColors.blue),
                          const SizedBox(width: 8),
                          Expanded(child: Text(tr('${f['name']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                          StatusChip(f['active'] == true ? 'En ligne' : 'Hors ligne', color: f['active'] == true ? YColors.success : YColors.muted),
                        ]),
                        const SizedBox(height: 6),
                        Text(tr('${f['url']}'), style: const TextStyle(fontSize: 12, color: YColors.blue), maxLines: 1, overflow: TextOverflow.ellipsis),
                        const SizedBox(height: 10),
                        Row(children: [
                          _Stat('Vues', '${f['views'] ?? 0}'),
                          _Stat('Inscriptions', '${f['submissions'] ?? 0}'),
                          _Stat('Conversion', (f['views'] ?? 0) == 0 ? '—' : '${((f['submissions'] as num) * 100 / (f['views'] as num)).toStringAsFixed(1)} %'),
                        ]),
                      ]),
                    ),
                  ),
                ),
              ),
          ]),
      ]),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(value), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          Text(tr(label), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
        ]),
      );
}

// ======================================================================
// Édition d'un formulaire / d'une page
// ======================================================================

class FormScreen extends StatefulWidget {
  const FormScreen({super.key, required this.form, required this.lists});

  final Map<String, dynamic> form;
  final List<Map<String, dynamic>> lists;

  @override
  State<FormScreen> createState() => _FormScreenState();
}

class _FormScreenState extends State<FormScreen> {
  late Map<String, dynamic> _f = widget.form;
  late final _c = {
    for (final k in ['name', 'slug', 'title', 'description', 'button_label', 'consent_text', 'success_message', 'redirect_url']) k: TextEditingController(text: '${widget.form[k] ?? ''}'),
  };
  late List<Map<String, dynamic>> _fields = mkList(widget.form['fields']);
  late int? _list = widget.form['list_id'] as int?;
  late bool _optin = widget.form['double_optin'] != false;
  late bool _withForm = widget.form['with_form'] != false;
  late bool _active = widget.form['active'] != false;
  late final EmailDesign _design = EmailDesign.from({'blocks': widget.form['blocks'], 'style': widget.form['style']});
  bool _busy = false;

  ApiClient get _api => context.read<Session>().api;
  bool get _page => _f['kind'] == 'page';

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final f = mkMap(await _api.put('marketing/forms/${_f['id']}', {
        for (final e in _c.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(),
        'list_id': _list,
        'fields': _fields,
        'double_optin': _optin,
        'with_form': _withForm,
        'active': _active,
        'blocks': _design.blocks,
        'style': _design.style,
      }));
      if (mounted) {
        setState(() => _f = f);
        _c['slug']!.text = '${f['slug']}';
        toast(context, tr('Enregistré.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String get _embed => '''<form action="${_f['url']}" method="post" style="max-width:420px;font-family:Arial,sans-serif">
${[for (final fl in _fields) '  <p><label>${formFieldLabels[fl['key']]}${fl['required'] == true ? ' *' : ''}<br><input name="${fl['key']}" type="${fl['key'] == 'email' ? 'email' : (fl['key'] == 'phone' ? 'tel' : (fl['key'] == 'birthday' ? 'date' : 'text'))}"${fl['required'] == true ? ' required' : ''} style="width:100%;padding:10px;border:1px solid #ccc;border-radius:8px"></label></p>'].join('\n')}
  <input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
${_c['consent_text']!.text.trim().isEmpty ? '' : '  <p><label><input type="checkbox" name="consent" value="1" required> ${_c['consent_text']!.text.trim()}</label></p>\n'}  <button type="submit" style="padding:12px 20px;background:#1F6FEB;color:#fff;border:0;border-radius:8px;font-weight:bold">${_c['button_label']!.text.trim().isEmpty ? 'Je m\'inscris' : _c['button_label']!.text.trim()}</button>
</form>''';

  @override
  Widget build(BuildContext context) {
    final tabs = [Tab(text: tr('Réglages')), if (_page) Tab(text: tr('Page')), Tab(text: tr('Partager'))];
    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: Text(tr('${_f['name']}')),
          bottom: TabBar(tabs: tabs),
          actions: [
            IconButton(tooltip: tr('Ouvrir la page'), onPressed: () => safeLaunchUrl(Uri.parse('${_f['url']}')), icon: const Icon(Icons.open_in_new)),
            IconButton(
              tooltip: tr('Supprimer'),
              onPressed: () async {
                if (!await confirm(context, tr('Supprimer ?'), tr('Le lien ne fonctionnera plus. Les contacts déjà inscrits sont conservés.'), danger: true, ok: tr('Supprimer'))) return;
                await _api.delete('marketing/forms/${_f['id']}');
                if (context.mounted) Navigator.pop(context);
              },
              icon: const Icon(Icons.delete_outline),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer'))),
            ),
          ],
        ),
        body: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [
          _settings(),
          if (_page)
            EmailBuilder(
              design: _design,
              allowHtml: false,
              showInbox: false,
              hint: tr('Contenu de la page ; le formulaire s\'affiche en dessous.'),
              footer: _withForm ? _FormPreview(title: _c['title']!.text, description: _c['description']!.text, fields: _fields, button: _c['button_label']!.text, consent: _c['consent_text']!.text) : const SizedBox(height: 24),
              onChanged: () {},
            ),
          _share(),
        ]),
      ),
    );
  }

  Widget _settings() {
    final hint = Theme.of(context).hintColor;
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: ListView(padding: const EdgeInsets.all(16), children: [
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('En ligne')), subtitle: Text(tr('Désactivez pour fermer les inscriptions')), value: _active, onChanged: (v) => setState(() => _active = v)),
          Field(label: tr('Nom (interne)'), child: TextField(controller: _c['name'])),
          Field(
            label: tr('Adresse de la page'),
            child: TextField(controller: _c['slug'], decoration: InputDecoration(prefixText: trn('${context.read<Session>().serverUrl}/f/'), helperText: tr('Lettres minuscules, chiffres et tirets'))),
          ),
          Field(
            label: tr('Les inscrits rejoignent la liste'),
            child: DropdownButtonFormField<int>(
              initialValue: widget.lists.any((l) => l['id'] == _list) ? _list : null,
              isExpanded: true,
              items: [for (final l in widget.lists) DropdownMenuItem(value: l['id'] as int, child: Text(tr('${l['name']}')))],
              onChanged: (v) => setState(() => _list = v),
            ),
          ),
          if (_page) SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Afficher un formulaire sous le contenu')), value: _withForm, onChanged: (v) => setState(() => _withForm = v)),
          if (!_page || _withForm) ...[
            Field(label: tr('Titre du formulaire'), child: TextField(controller: _c['title'], onChanged: (_) => setState(() {}))),
            Field(label: tr('Texte d\'introduction'), child: TextField(controller: _c['description'], maxLines: 3, onChanged: (_) => setState(() {}))),
            Text(tr('Champs'), style: TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            for (final k in formFieldLabels.keys)
              Row(children: [
                Checkbox(
                  value: _fields.any((f) => f['key'] == k),
                  onChanged: (v) => setState(() {
                    if (v == true) {
                      _fields = [..._fields, {'key': k, 'required': false}];
                    } else {
                      _fields = _fields.where((f) => f['key'] != k).toList();
                    }
                  }),
                ),
                Expanded(child: Text(tr(formFieldLabels[k]!))),
                if (_fields.any((f) => f['key'] == k))
                  Row(children: [
                    Text(tr('Obligatoire'), style: TextStyle(fontSize: 12, color: hint)),
                    Switch(
                      value: _fields.firstWhere((f) => f['key'] == k)['required'] == true,
                      onChanged: (v) => setState(() => _fields = [for (final f in _fields) f['key'] == k ? {...f, 'required': v} : f]),
                    ),
                  ]),
              ]),
            const SizedBox(height: 8),
            Field(label: tr('Texte du bouton'), child: TextField(controller: _c['button_label'], onChanged: (_) => setState(() {}))),
            Field(label: tr('Case de consentement (vide = pas de case)'), child: TextField(controller: _c['consent_text'], maxLines: 2, onChanged: (_) => setState(() {}))),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Double confirmation (double opt-in)')),
              subtitle: Text(tr('Un e-mail de confirmation est envoyé : seules les adresses confirmées reçoivent vos campagnes. Recommandé pour la délivrabilité.')),
              value: _optin,
              onChanged: (v) => setState(() => _optin = v),
            ),
            Field(label: tr('Message après inscription'), child: TextField(controller: _c['success_message'], maxLines: 2, decoration: InputDecoration(hintText: tr('Merci ! Votre inscription est bien enregistrée.')))),
            Field(label: tr('Rediriger vers (facultatif)'), child: TextField(controller: _c['redirect_url'], decoration: InputDecoration(hintText: tr('https://votre-site.ci/merci')))),
          ],
          Text(tr('Astuce : associez une automatisation « Inscription par un formulaire » pour envoyer automatiquement un e-mail de bienvenue.'), style: TextStyle(fontSize: 12, color: hint)),
        ]),
      ),
    );
  }

  /// Affiche A5 avec QR code, à imprimer pour la boutique ou un salon (généré sur l'appareil).
  Future<void> _printQr(String url) async {
    final company = '${context.read<Session>().company['name'] ?? ''}';
    final title = _c['title']!.text.trim().isEmpty ? '${_f['name']}' : _c['title']!.text.trim();
    final doc = pw.Document();
    doc.addPage(pw.Page(
      pageFormat: PdfPageFormat.a5,
      build: (ctx) => pw.Column(mainAxisAlignment: pw.MainAxisAlignment.center, crossAxisAlignment: pw.CrossAxisAlignment.center, children: [
        pw.Text(tr(company), style: pw.TextStyle(fontSize: 16, color: PdfColor.fromHex('#1F6FEB'), fontWeight: pw.FontWeight.bold)),
        pw.SizedBox(height: 12),
        pw.Text(tr(title), textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 24, fontWeight: pw.FontWeight.bold)),
        pw.SizedBox(height: 24),
        pw.BarcodeWidget(barcode: pw.Barcode.qrCode(), data: url, width: 220, height: 220),
        pw.SizedBox(height: 18),
        pw.Text(tr('Scannez pour vous inscrire'), style: const pw.TextStyle(fontSize: 14)),
        pw.SizedBox(height: 6),
        pw.Text(tr(url), style: const pw.TextStyle(fontSize: 10, color: PdfColors.grey700)),
      ]),
    ));
    await Printing.layoutPdf(name: 'QR $title.pdf', onLayout: (_) async => doc.save());
  }

  Widget _share() {
    final url = '${_f['url']}';
    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: ListView(padding: const EdgeInsets.all(16), children: [
          SectionCard(
            title: tr('Lien à partager'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              SelectableText(tr(url), style: const TextStyle(fontWeight: FontWeight.w700, color: YColors.blue)),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: [
                OutlinedButton.icon(onPressed: () => Clipboard.setData(ClipboardData(text: url)).then((_) => mounted ? toast(context, tr('Lien copié.')) : null), icon: const Icon(Icons.copy), label: Text(tr('Copier'))),
                OutlinedButton.icon(
                  onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent('${_c['title']!.text.isEmpty ? _f['name'] : _c['title']!.text} : $url')}'), mode: LaunchMode.externalApplication),
                  icon: const Icon(Icons.chat_outlined),
                  label: Text(tr('WhatsApp')),
                ),
                OutlinedButton.icon(
                  onPressed: () => safeLaunchUrl(Uri.parse('https://www.facebook.com/sharer/sharer.php?u=${Uri.encodeComponent(url)}'), mode: LaunchMode.externalApplication),
                  icon: const Icon(Icons.facebook),
                  label: Text(tr('Facebook')),
                ),
                OutlinedButton.icon(
                  onPressed: () => _printQr(url),
                  icon: const Icon(Icons.qr_code_2),
                  label: Text(tr('Affiche QR code (PDF)')),
                ),
              ]),
            ]),
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Code à intégrer sur votre site'),
            trailing: TextButton.icon(onPressed: () => Clipboard.setData(ClipboardData(text: _embed)).then((_) => mounted ? toast(context, tr('Code copié.')) : null), icon: const Icon(Icons.copy, size: 18), label: Text(tr('Copier'))),
            child: Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: const Color(0xFF0B1B3A), borderRadius: BorderRadius.circular(8)),
              child: SelectableText(tr(_embed), style: const TextStyle(fontFamily: 'monospace', fontSize: 12, color: Color(0xFFE3E8F1))),
            ),
          ),
          const SizedBox(height: 8),
          Text(tr('À coller dans WordPress (bloc HTML personnalisé), Wix, Shopify… Les inscriptions arrivent directement dans la liste choisie.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        ]),
      ),
    );
  }
}

class _FormPreview extends StatelessWidget {
  const _FormPreview({required this.title, required this.description, required this.fields, required this.button, required this.consent});

  final String title;
  final String description;
  final List<Map<String, dynamic>> fields;
  final String button;
  final String consent;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(32, 8, 32, 28),
        child: IgnorePointer(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (title.isNotEmpty) Text(tr(title), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
            if (description.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr(description), style: const TextStyle(color: YColors.muted))),
            for (final f in fields) ...[
              const SizedBox(height: 10),
              Text(tr('${formFieldLabels[f['key']]}${f['required'] == true ? ' *' : ''}'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
              const SizedBox(height: 4),
              Container(height: 44, decoration: BoxDecoration(border: Border.all(color: const Color(0xFFD5DCE8)), borderRadius: BorderRadius.circular(10))),
            ],
            if (consent.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Icon(Icons.check_box_outline_blank, size: 18, color: YColors.muted),
                  const SizedBox(width: 6),
                  Expanded(child: Text(tr(consent), style: const TextStyle(fontSize: 12, color: YColors.muted))),
                ]),
              ),
            const SizedBox(height: 16),
            Container(
              height: 48,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: YColors.blue, borderRadius: BorderRadius.circular(10)),
              child: Text(tr(button.isEmpty ? 'Je m\'inscris' : button), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            ),
          ]),
        ),
      );
}
