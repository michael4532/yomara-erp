import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../../core/json.dart';

/// Compte de démonstration pour la relecture App Store / Google Play :
/// création, identifiants à recopier, nouveau mot de passe (réactive le compte), désactivation.
Future<void> showStoreDemoDialog(BuildContext context, {VoidCallback? onChanged}) =>
    showDialog(context: context, builder: (_) => _StoreDemoDialog(onChanged: onChanged));

class _StoreDemoDialog extends StatefulWidget {
  const _StoreDemoDialog({this.onChanged});
  final VoidCallback? onChanged;

  @override
  State<_StoreDemoDialog> createState() => _StoreDemoDialogState();
}

class _StoreDemoDialogState extends State<_StoreDemoDialog> {
  Map<String, dynamic>? _d;
  bool _busy = false;
  String? _error;
  final _email = TextEditingController();
  final _name = TextEditingController(text: 'Yomara Démo');

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _email.dispose();
    _name.dispose();
    super.dispose();
  }

  Future<void> _load() async => _run(() => context.read<Session>().api.get('admin/store-demo'));

  Future<void> _run(Future<dynamic> Function() call, {bool changed = false}) async {
    setState(() => (_busy = true, _error = null));
    try {
      final r = await call();
      final d = asMap(r['data']);
      if (!mounted) return;
      setState(() {
        _d = d;
        if (_email.text.isEmpty && d['suggested_email'] != null) _email.text = '${d['suggested_email']}';
      });
      if (changed) widget.onChanged?.call();
    } catch (e) {
      if (mounted) setState(() => _error = errorText(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _confirm(String title, String body, String action) async =>
      await showDialog<bool>(
        context: context,
        builder: (dc) => AlertDialog(
          title: Text(tr(title)),
          content: Text(tr(body)),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr(action))),
          ],
        ),
      ) ??
      false;

  void _copy(String text, String what) {
    Clipboard.setData(ClipboardData(text: text));
    toast(context, tr('$what copié'));
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    final d = _d;
    final hint = Theme.of(context).hintColor;
    Widget body;
    if (d == null) {
      body = const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()));
    } else if (d['exists'] != true) {
      body = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        Text(
          tr('Apple et Google demandent un compte pour tester l\'application avant de la publier. Ce compte appartient à une société à part, avec tous les modules '
              'de la formule la plus complète et des données d\'exemple. Il n\'a aucun accès à l\'administration de la plateforme ni aux autres sociétés.'),
          style: TextStyle(color: hint, height: 1.4),
        ),
        const SizedBox(height: 16),
        Field(label: tr('E-mail de connexion du compte démo'), child: TextField(controller: _email, keyboardType: TextInputType.emailAddress)),
        Field(label: tr('Nom de la société de démonstration'), child: TextField(controller: _name)),
        Text(tr('Le mot de passe est généré automatiquement et affiché ici après la création.'), style: TextStyle(color: hint, fontSize: 12)),
      ]);
    } else {
      final active = d['active'] == true;
      final company = Map<String, dynamic>.from((d['company'] as Map?) ?? {});
      Widget cred(String label, String value, IconData icon) => Container(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
            decoration: BoxDecoration(color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .5), borderRadius: BorderRadius.circular(12)),
            child: Row(children: [
              Icon(icon, size: 20, color: YColors.blue),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr(label), style: TextStyle(fontSize: 12, color: hint)),
                  SelectableText(value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                ]),
              ),
              IconButton(tooltip: tr('Copier'), onPressed: () => _copy(value, label), icon: const Icon(Icons.copy_rounded, size: 20)),
            ]),
          );
      final last = d['last_login'] == null ? tr('jamais connecté') : tr('dernière connexion ${dateTimeFr(d['last_login'])} (${d['last_platform'] ?? '—'})');
      body = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        Row(children: [
          StatusChip(active ? 'Actif' : 'Désactivé', color: active ? YColors.success : YColors.danger),
          const SizedBox(width: 10),
          Expanded(child: Text(tr('${company['name'] ?? ''} · ${company['plan'] ?? 'sans formule'} · $last'), style: TextStyle(color: hint, fontSize: 13))),
        ]),
        const SizedBox(height: 14),
        cred('Adresse e-mail', '${d['email']}', Icons.alternate_email),
        cred('Mot de passe', '${d['password'] ?? '—'}', Icons.key_outlined),
        const SizedBox(height: 4),
        Row(children: [
          Expanded(child: Text(tr('Texte pour « Informations de connexion » (App Store Connect) et « Accès aux applications » (Play Console)'), style: const TextStyle(fontWeight: FontWeight.w600))),
          TextButton.icon(onPressed: () => _copy('${d['review_notes']}\nMot de passe / Password : ${d['password']}', 'Texte'), icon: const Icon(Icons.copy_all_rounded, size: 18), label: Text(tr('Copier'))),
        ]),
        Text(tr('Collez l\'e-mail et le mot de passe dans les champs prévus, et ce texte dans les notes pour la relecture.'), style: TextStyle(color: hint, fontSize: 12)),
        const SizedBox(height: 8),
        if (!active)
          Container(
            padding: const EdgeInsets.all(12),
            decoration: ShapeDecoration(color: noticeFill(context), shape: noticeShape(context)),
            child: Text(tr('Le compte est désactivé (par exemple après un test de « Supprimer mon compte » par la relecture). « Nouveau mot de passe » le réactive.')),
          ),
      ]);
    }

    final exists = d?['exists'] == true;
    return AlertDialog(
      title: Row(children: [
        const Icon(Icons.storefront_outlined),
        const SizedBox(width: 10),
        Expanded(child: Text(tr('Compte démo pour les stores'))),
      ]),
      content: SizedBox(
        width: 520,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            body,
            if (_error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: const TextStyle(color: YColors.danger))),
            if (_busy && d != null) const Padding(padding: EdgeInsets.only(top: 12), child: LinearProgressIndicator()),
          ]),
        ),
      ),
      actionsOverflowButtonSpacing: 8,
      actions: [
        if (exists) ...[
          TextButton(
            onPressed: _busy
                ? null
                : () async {
                    if (await _confirm('Supprimer le compte démo ?', 'Le compte est désactivé et la société de démonstration suspendue. Vous pourrez ensuite en créer un nouveau.', 'Supprimer')) {
                      _run(() => api.post('admin/store-demo/forget', {}), changed: true);
                    }
                  },
            style: TextButton.styleFrom(foregroundColor: YColors.danger),
            child: Text(tr('Supprimer')),
          ),
          if (d?['active'] == true)
            TextButton(
              onPressed: _busy
                  ? null
                  : () async {
                      if (await _confirm('Désactiver le compte démo ?', 'La relecture ne pourra plus se connecter. « Nouveau mot de passe » le réactive.', 'Désactiver')) {
                        _run(() => api.post('admin/store-demo/disable', {}), changed: true);
                      }
                    },
              child: Text(tr('Désactiver')),
            ),
          OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    if (await _confirm('Nouveau mot de passe ?', 'L\'ancien mot de passe ne fonctionnera plus : pensez à mettre à jour App Store Connect et la Play Console.', 'Générer')) {
                      _run(() => api.post('admin/store-demo/password', {}), changed: true);
                    }
                  },
            icon: const Icon(Icons.autorenew_rounded, size: 18),
            label: Text(tr('Nouveau mot de passe')),
          ),
        ] else if (d != null)
          FilledButton.icon(
            onPressed: _busy ? null : () => _run(() => api.post('admin/store-demo', {'email': _email.text.trim(), 'company_name': _name.text.trim()}), changed: true),
            icon: const Icon(Icons.person_add_alt_1_outlined, size: 18),
            label: Text(tr(_busy ? 'Création…' : 'Créer le compte démo')),
          ),
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))),
      ],
    );
  }
}
