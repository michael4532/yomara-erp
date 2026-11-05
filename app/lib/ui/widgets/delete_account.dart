import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import 'common.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// « Supprimer mon compte » (exigé par Google Play et l'App Store) : demande confirmée par le mot de passe.
/// Compte utilisateur : désactivé tout de suite et déconnecté ; entreprise entière : demande traitée par l'éditeur sous 30 jours.
Future<void> showDeleteAccountDialog(BuildContext context) async {
  final session = context.read<Session>();
  if (session.isSuperAdmin) {
    toast(context, tr('Le compte de l\'éditeur de la plateforme ne peut pas être supprimé depuis l\'application.'), error: true);
    return;
  }
  final pass = TextEditingController();
  final note = TextEditingController();
  var scope = 'user';
  var busy = false;
  final canCompany = session.can('core.settings.manage');
  final base = session.serverUrl.replaceAll(RegExp(r'/+$'), '');

  await showFormDialog(
    context,
    title: tr('Supprimer mon compte'),
    icon: Icons.person_remove_outlined,
    maxWidth: 520,
    builder: (dc) => StatefulBuilder(
      builder: (dc, set) {
        Widget option(String v, String title, String sub) => Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Material(
            color: scope == v ? YColors.danger.withValues(alpha: .06) : Colors.transparent,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
              side: BorderSide(color: scope == v ? YColors.danger.withValues(alpha: .5) : Theme.of(dc).dividerColor),
            ),
            child: InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: () => set(() => scope = v),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(6, 8, 12, 8),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Padding(padding: const EdgeInsets.fromLTRB(8, 10, 8, 0), child: Icon(scope == v ? Icons.radio_button_checked : Icons.radio_button_off, size: 22, color: scope == v ? YColors.danger : Theme.of(dc).hintColor)),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w700)),
                        const SizedBox(height: 2),
                        Text(tr(sub), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 13, height: 1.35)),
                      ]),
                    ),
                  ),
                ]),
              ),
            ),
          ),
        );

        return ListView(
          padding: const EdgeInsets.fromLTRB(24, 4, 24, 24),
          shrinkWrap: true,
          children: [
            option('user', 'Mon compte utilisateur', 'Désactivé tout de suite et déconnecté de tous vos appareils. Vos données personnelles sont effacées sous 30 jours ; les documents de l\'entreprise restent à l\'entreprise.'),
            if (canCompany)
              option('company', 'Toute l\'entreprise et ses données', 'Tous les comptes, documents et fichiers. Notre équipe vous contacte pour confirmer, puis supprime tout sous 30 jours.'),
            const SizedBox(height: 6),
            Field(label: tr('Mot de passe'), child: TextField(controller: pass, obscureText: true, autofillHints: const [AutofillHints.password])),
            Field(label: tr('Raison (facultatif)'), child: TextField(controller: note, maxLines: 2, maxLength: 3000)),
            TextButton.icon(
              style: TextButton.styleFrom(padding: EdgeInsets.zero, alignment: Alignment.centerLeft),
              onPressed: () => safeLaunchUrl(Uri.parse('$base/confidentialite'), mode: LaunchMode.externalApplication),
              icon: const Icon(Icons.privacy_tip_outlined, size: 18),
              label: Text(tr('Politique de confidentialité')),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: YColors.danger),
                icon: busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.delete_forever_outlined, size: 18),
                label: Text(tr(scope == 'user' ? 'Supprimer mon compte' : 'Demander la suppression')),
                onPressed: busy
                    ? null
                    : () async {
                        if (pass.text.isEmpty) {
                          toast(dc, tr('Saisissez votre mot de passe.'), error: true);
                          return;
                        }
                        set(() => busy = true);
                        try {
                          final r = asMap(await session.api.post('me/delete-account', {'password': pass.text, 'scope': scope, 'message': note.text.trim()}));
                          if (!dc.mounted) return;
                          Navigator.pop(dc);
                          if (context.mounted) {
                            await showDialog<void>(
                              context: context,
                              builder: (c) => AlertDialog(
                                title: Text(tr(scope == 'user' ? 'Compte supprimé' : 'Demande enregistrée')),
                                content: Text(tr('${r['message'] ?? ''}')),
                                actions: [FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('Compris')))],
                              ),
                            );
                          }
                          if (r['signed_out'] == true) await session.logout(remote: false);
                        } catch (e) {
                          set(() => busy = false);
                          if (dc.mounted) toast(dc, errorText(e), error: true);
                        }
                      },
              ),
            ),
          ],
        );
      },
    ),
  );
}
