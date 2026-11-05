import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/local_db.dart';
import '../state/session.dart';
import '../ui/widgets/common.dart';
import 'i18n.dart';

/// Applique et mémorise une langue (sur l'appareil et, si connecté, pour l'utilisateur).
Future<void> applyLanguage(String code, {Session? session, bool companyDefault = false}) async {
  await I18n.use(code);
  await LocalDb.meta.put('lang', code);
  if (session != null && session.isLoggedIn) {
    try {
      await session.api.put('me/locale', {'locale': code, if (companyDefault) 'company_default': true});
    } catch (_) {
      // hors ligne : la langue reste choisie sur cet appareil
    }
  }
}

/// Choix de la langue de l'interface.
Future<void> chooseLanguage(BuildContext context) async {
  final session = context.read<Session>();
  final admin = session.isLoggedIn && session.can('core.settings.manage');
  var code = I18n.lang;
  var companyDefault = false;
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Row(children: [const Icon(Icons.translate), const SizedBox(width: 10), Text(tr('Langue'))]),
        content: SizedBox(
          width: 380,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            for (final e in I18n.languages.entries)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(code == e.key ? Icons.radio_button_checked : Icons.radio_button_off, color: code == e.key ? Theme.of(c).colorScheme.primary : null),
                title: Text(tr(e.value)),
                onTap: () => set(() => code = e.key),
              ),
            if (admin)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: companyDefault,
                onChanged: (v) => set(() => companyDefault = v == true),
                title: Text(tr('Langue par défaut de la société')),
                subtitle: Text(tr('Pour les utilisateurs qui n\'ont pas choisi leur langue')),
              ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Appliquer'))),
        ],
      ),
    ),
  );
  if (ok != true) return;
  await applyLanguage(code, session: session.isLoggedIn ? session : null, companyDefault: companyDefault);
  if (context.mounted) toast(context, tr('Langue de l\'interface : {}').replaceFirst('{}', I18n.languages[code]!));
}
