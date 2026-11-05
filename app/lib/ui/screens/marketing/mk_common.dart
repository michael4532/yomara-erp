import 'package:flutter/material.dart';

import '../../theme.dart';
import '../messaging_settings_screen.dart' show channelLabels;

export '../messaging_settings_screen.dart' show channelLabels, channelIcons;
import '../../../i18n/i18n.dart';

Map<String, dynamic> mkMap(dynamic v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};
List<Map<String, dynamic>> mkList(dynamic v) => v is List ? v.map(mkMap).toList() : <Map<String, dynamic>>[];

const mkChannelLabels = channelLabels;

const mkStatusColors = {
  'draft': YColors.muted,
  'scheduled': YColors.warning,
  'sending': YColors.blue,
  'sent': YColors.success,
  'cancelled': YColors.danger,
  'active': YColors.success,
  'paused': YColors.warning,
};

const mkProviderNames = {
  'smtp': 'votre serveur',
  'platform': 'serveur de l\'ERP',
  'orange': 'Orange',
  'twilio': 'Twilio',
  'infobip': 'Infobip',
  'africastalking': 'Africa\'s Talking',
  'vonage': 'Vonage',
  'http': 'passerelle HTTP',
  'meta': 'API Meta',
  'ultramsg': 'UltraMsg',
  'greenapi': 'Green API',
};

const sampleVars = {
  'prenom': 'Awa',
  'nom': 'Koné',
  'nom_complet': 'Awa Koné',
  'societe': 'Société exemple',
  'email': 'awa@exemple.ci',
  'telephone': '+225 07 00 00 00 00',
  'ville': 'Abidjan',
  'lien_desinscription': '#',
};

Color hexColor(String? s, Color fallback) {
  final h = (s ?? '').replaceAll('#', '').trim();
  if (h.length != 6) return fallback;
  final v = int.tryParse(h, radix: 16);
  return v == null ? fallback : Color(0xFF000000 | v);
}

String fillVars(String s, String company) =>
    s.replaceAllMapped(RegExp(r'\{\{\s*([a-z_]+)\s*\}\}'), (m) => m[1] == 'entreprise' ? company : (sampleVars[m[1]] ?? m[0]!));

/// Puces d'insertion de variables de personnalisation.
class VarsBar extends StatelessWidget {
  const VarsBar({super.key, required this.onInsert, this.vars = const ['prenom', 'nom_complet', 'societe', 'entreprise']});

  final void Function(String) onInsert;
  final List<String> vars;

  @override
  Widget build(BuildContext context) => Wrap(spacing: 6, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
        Text(tr('Insérer :'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
        for (final v in vars) ActionChip(visualDensity: VisualDensity.compact, label: Text(tr('{{$v}}'), style: const TextStyle(fontSize: 12)), onPressed: () => onInsert(v)),
      ]);
}

void insertVar(TextEditingController c, String v) {
  final sel = c.selection;
  final at = sel.isValid ? sel.start : c.text.length;
  c.text = c.text.replaceRange(at, sel.isValid ? sel.end : at, '{{$v}}');
  c.selection = TextSelection.collapsed(offset: at + v.length + 4);
}

/// Petite carte indicateur.
class MkKpi extends StatelessWidget {
  const MkKpi(this.label, this.value, this.icon, {super.key, this.color = YColors.blue, this.width = 200});

  final String label;
  final String value;
  final IconData icon;
  final Color color;
  final double width;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: width,
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(children: [
              Icon(icon, color: color),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr(value), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                  Text(tr(label), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                ]),
              ),
            ]),
          ),
        ),
      );
}
