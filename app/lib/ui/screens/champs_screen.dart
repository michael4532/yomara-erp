import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/custom_fields.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/custom_fields_editor.dart';
import '../../i18n/i18n.dart';

const _typeIcons = {
  'text': Icons.short_text, 'textarea': Icons.notes, 'number': Icons.pin_outlined, 'money': Icons.payments_outlined, 'date': Icons.event,
  'select': Icons.arrow_drop_down_circle_outlined, 'multiselect': Icons.checklist, 'checkbox': Icons.check_box_outlined, 'url': Icons.link,
  'email': Icons.alternate_email, 'phone': Icons.phone_outlined,
};

/// Définition des champs personnalisés (responsables).
class ChampsScreen extends StatefulWidget {
  const ChampsScreen({super.key});

  @override
  State<ChampsScreen> createState() => _ChampsScreenState();
}

class _ChampsScreenState extends State<ChampsScreen> {
  String _entity = 'partners';

  @override
  Widget build(BuildContext context) {
    final narrow = MediaQuery.of(context).size.width < 700;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      PageHeader(
        title: tr('Champs personnalisés'),
        subtitle: tr('Vos propres informations sur les fiches et documents, saisies hors ligne et exploitables dans les rapports'),
        actions: [FilledButton.icon(onPressed: () => editCustomField(context, entity: _entity), icon: const Icon(Icons.add), label: Text(tr('Nouveau champ')))],
      ),
      Padding(
        padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: ValueListenableBuilder(
            valueListenable: LocalDb.listen('cf_fields'),
            builder: (context, _, _) {
              final all = LocalDb.all('cf_fields');
              return Row(children: [
                for (final e in customEntities.entries)
                  Padding(
                    padding: const EdgeInsets.only(right: 6),
                    child: ChoiceChip(
                      label: Text(tr('${e.value} (${all.where((f) => f['entity'] == e.key).length})')),
                      selected: _entity == e.key,
                      onSelected: (_) => setState(() => _entity = e.key),
                    ),
                  ),
              ]);
            },
          ),
        ),
      ),
      const SizedBox(height: 8),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('cf_fields'),
          builder: (context, _, _) {
            final fields = LocalDb.all('cf_fields').where((f) => f['entity'] == _entity).toList()
              ..sort((a, b) {
                final c = toDouble(a['sort']).compareTo(toDouble(b['sort']));
                return c != 0 ? c : '${a['label']}'.compareTo('${b['label']}');
              });
            if (fields.isEmpty) {
              return EmptyState(
                icon: Icons.dashboard_customize_outlined,
                title: tr('Aucun champ pour « ${customEntities[_entity]} »'),
                message: switch (_entity) {
                  'partners' => 'Exemples : secteur d\'activité, commercial attitré, date du premier contrat.',
                  'products' => 'Exemples : marque, pays d\'origine, garantie (mois).',
                  'sales_documents' => 'Exemples : n° de bon de commande du client, délai de livraison (imprimés sur le devis ou la facture).',
                  'purchase_documents' => 'Exemples : n° de proforma, lieu de livraison.',
                  'rh_employees' => 'Exemples : taille de tenue, permis de conduire, groupe sanguin.',
                  'crm_leads' => 'Exemples : budget annoncé, concurrent en place.',
                  _ => 'Exemples : n° de marché, maître d\'ouvrage.',
                },
                action: FilledButton.icon(onPressed: () => editCustomField(context, entity: _entity), icon: const Icon(Icons.add), label: Text(tr('Créer un champ'))),
              );
            }
            return ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 4, narrow ? 16 : 24, 24), children: [
              Card(
                child: Column(children: [
                  for (final f in fields)
                    ListTile(
                      onTap: () => editCustomField(context, field: f),
                      leading: Icon(_typeIcons[f['type']] ?? Icons.label_outline, color: f['active'] == false ? Theme.of(context).hintColor : YColors.blue),
                      title: Text(tr('${f['label']}${f['required'] == true ? ' *' : ''}'), style: TextStyle(fontWeight: FontWeight.w600, decoration: f['active'] == false ? TextDecoration.lineThrough : null)),
                      subtitle: Text(tr([
                        customTypes[f['type']] ?? '${f['type']}',
                        if ((f['options'] as List?)?.isNotEmpty == true) (f['options'] as List).join(', '),
                        if (!customEmpty(f['default_value'])) 'par défaut : ${f['default_value']}',
                        'clé ${f['key'] ?? '…'}',
                      ].join(' · ')), maxLines: 2, overflow: TextOverflow.ellipsis),
                      trailing: Wrap(spacing: 6, children: [
                        if (f['show_on_pdf'] == true) Tooltip(message: tr('Imprimé sur les documents'), child: Icon(Icons.print_outlined, size: 18)),
                        if (f['show_in_list'] == true) Tooltip(message: tr('Affiché dans les listes'), child: Icon(Icons.view_list_outlined, size: 18)),
                        if (f['_local'] == true) const Icon(Icons.cloud_upload_outlined, size: 18, color: YColors.warning),
                      ]),
                    ),
                ]),
              ),
              const SizedBox(height: 16),
              SectionCard(
                title: tr('Aperçu de la saisie'),
                child: CustomFieldsEditor(key: ValueKey(fields.map((f) => '${f['id']}${f['version']}').join()), entity: _entity, values: {}, creating: true, title: null),
              ),
            ]);
          },
        ),
      ),
    ]);
  }
}

Future<void> editCustomField(BuildContext context, {Map<String, dynamic>? field, String? entity}) =>
    showFormDialog(context, title: field == null ? 'Nouveau champ' : 'Modifier le champ', builder: (_) => _FieldForm(field: field, entity: entity ?? 'partners'));

class _FieldForm extends StatefulWidget {
  const _FieldForm({this.field, required this.entity});

  final Map<String, dynamic>? field;
  final String entity;

  @override
  State<_FieldForm> createState() => _FieldFormState();
}

class _FieldFormState extends State<_FieldForm> {
  late String _entity = '${widget.field?['entity'] ?? widget.entity}';
  late String _type = '${widget.field?['type'] ?? 'text'}';
  late final _label = TextEditingController(text: '${widget.field?['label'] ?? ''}');
  late final _help = TextEditingController(text: '${widget.field?['help'] ?? ''}');
  late final _default = TextEditingController(text: '${widget.field?['default_value'] ?? ''}');
  late final _sort = TextEditingController(text: '${widget.field?['sort'] ?? 0}');
  final _opt = TextEditingController();
  late final List<String> _options = [for (final o in (widget.field?['options'] as List? ?? const [])) '$o'];
  late bool _required = widget.field?['required'] == true;
  late bool _pdf = widget.field?['show_on_pdf'] == true;
  late bool _list = widget.field?['show_in_list'] == true;
  late bool _active = widget.field?['active'] != false;

  bool get _isNew => widget.field == null;
  bool get _choices => _type == 'select' || _type == 'multiselect';

  void _addOption() {
    for (final o in _opt.text.split(',')) {
      final t = o.trim();
      if (t.isNotEmpty && !_options.contains(t)) _options.add(t);
    }
    _opt.clear();
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final printable = ['partners', 'sales_documents', 'purchase_documents'].contains(_entity);
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Field(
        label: tr('Sur'),
        child: DropdownButtonFormField<String>(
          initialValue: _entity,
          items: [for (final e in customEntities.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
          onChanged: _isNew ? (v) => setState(() => _entity = v!) : null,
        ),
      ),
      Field(label: tr('Libellé'), child: TextField(controller: _label, autofocus: _isNew, decoration: InputDecoration(hintText: tr('Ex. : Secteur d\'activité')))),
      Field(
        label: tr('Type'),
        child: DropdownButtonFormField<String>(
          initialValue: _type,
          items: [for (final e in customTypes.entries) DropdownMenuItem(value: e.key, child: Row(children: [Icon(_typeIcons[e.key], size: 18), const SizedBox(width: 8), Text(tr(e.value))]))],
          onChanged: _isNew ? (v) => setState(() => _type = v!) : null,
        ),
      ),
      if (!_isNew) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(tr('L\'objet, le type et la clé (« ${widget.field!['key']} ») ne changent plus : les valeurs saisies restent lisibles.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
      if (_choices)
        Field(
          label: tr('Choix possibles'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (var i = 0; i < _options.length; i++)
                InputChip(
                  label: Text(tr(_options[i])),
                  onDeleted: () => setState(() => _options.removeAt(i)),
                ),
            ]),
            const SizedBox(height: 6),
            TextField(
              controller: _opt,
              decoration: InputDecoration(hintText: tr('Ajouter un choix (Entrée, ou plusieurs séparés par des virgules)'), suffixIcon: IconButton(icon: const Icon(Icons.add), onPressed: _addOption)),
              onSubmitted: (_) => _addOption(),
            ),
          ]),
        ),
      Field(
        label: tr('Valeur par défaut (facultatif)'),
        child: TextField(controller: _default, decoration: InputDecoration(hintText: trn(switch (_type) { 'checkbox' => '1 pour cochée', 'date' => 'AAAA-MM-JJ', 'multiselect' => 'Choix séparés par des virgules', _ => '' }))),
      ),
      Field(label: tr('Aide à la saisie (facultatif)'), child: TextField(controller: _help)),
      SwitchListTile(contentPadding: EdgeInsets.zero, value: _required, onChanged: (v) => setState(() => _required = v), title: Text(tr('Obligatoire'))),
      if (printable) SwitchListTile(contentPadding: EdgeInsets.zero, value: _pdf, onChanged: (v) => setState(() => _pdf = v), title: Text(tr('Imprimer sur les devis et factures'))),
      SwitchListTile(contentPadding: EdgeInsets.zero, value: _list, onChanged: (v) => setState(() => _list = v), title: Text(tr('Afficher dans les listes'))),
      Row(children: [
        Expanded(child: SwitchListTile(contentPadding: EdgeInsets.zero, value: _active, onChanged: (v) => setState(() => _active = v), title: Text(tr('Actif')), subtitle: Text(tr('Désactivé : masqué, valeurs conservées')))),
        SizedBox(width: 90, child: TextField(controller: _sort, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Ordre')))),
      ]),
      const SizedBox(height: 8),
      Row(children: [
        if (!_isNew)
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer « ${widget.field!['label']} » ?'), tr('Le champ disparaît des fiches et des rapports. Les valeurs déjà saisies sont conservées dans les fiches.'), danger: true) || !context.mounted) return;
              await context.read<SyncEngine>().remove('cf_fields', '${widget.field!['id']}');
              if (context.mounted) Navigator.pop(context);
            },
            icon: const Icon(Icons.delete_outline),
            label: Text(tr('Supprimer')),
          ),
        const Spacer(),
        FilledButton(
          onPressed: () async {
            if (_opt.text.trim().isNotEmpty) _addOption();
            if (_label.text.trim().isEmpty) return toast(context, tr('Indiquez un libellé.'), error: true);
            if (_choices && _options.isEmpty) return toast(context, tr('Indiquez les choix possibles.'), error: true);
            await context.read<SyncEngine>().save('cf_fields', {
              'id': widget.field?['id'] ?? newId(),
              if (_isNew) 'entity': _entity,
              if (_isNew) 'type': _type,
              'label': _label.text.trim(),
              'options': _choices ? _options : null,
              'required': _required,
              'default_value': _default.text.trim().isEmpty ? null : _default.text.trim(),
              'help': _help.text.trim().isEmpty ? null : _help.text.trim(),
              'show_on_pdf': printable && _pdf,
              'show_in_list': _list,
              'sort': int.tryParse(_sort.text) ?? 0,
              'active': _active,
            });
            if (context.mounted) Navigator.pop(context);
          },
          child: Text(tr('Enregistrer')),
        ),
      ]),
    ]);
  }
}
