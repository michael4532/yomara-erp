import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../user_access_dialog.dart';

/// Fiche utilisateur en grand : identité, fonction et place dans la hiérarchie à gauche,
/// modules auxquels la personne a accès à droite (cochés d'après sa fonction, modifiables un par un).
/// Sur téléphone : plein écran, les deux parties l'une sous l'autre. Renvoie true si enregistré.
Future<bool> openUserEditor(BuildContext context, {Map<String, dynamic>? user}) async {
  final narrow = MediaQuery.sizeOf(context).width < 760;
  final r = narrow
      ? await Navigator.of(context).push<bool>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => _UserEditor(user: user, fullscreen: true)))
      : await showDialog<bool>(
          context: context,
          builder: (_) => Dialog(
            insetPadding: const EdgeInsets.all(24),
            clipBehavior: Clip.antiAlias,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
            child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 1120, maxHeight: 820), child: _UserEditor(user: user)),
          ),
        );
  return r == true;
}

class _UserEditor extends StatefulWidget {
  const _UserEditor({this.user, this.fullscreen = false});
  final Map<String, dynamic>? user;
  final bool fullscreen;

  @override
  State<_UserEditor> createState() => _UserEditorState();
}

class _UserEditorState extends State<_UserEditor> {
  Map<String, dynamic>? _meta;
  Object? _error;
  bool _busy = false;
  bool _showPwd = false;
  late final _name = TextEditingController(text: '${widget.user?['name'] ?? ''}');
  late final _email = TextEditingController(text: '${widget.user?['email'] ?? ''}');
  late final _phone = TextEditingController(text: '${widget.user?['phone'] ?? ''}');
  late final _title = TextEditingController(text: '${widget.user?['job_title'] ?? ''}');
  final _pwd = TextEditingController();
  int? _roleId;
  int? _managerId;
  late bool _active = (widget.user?['is_active'] as bool?) ?? true;
  late bool _twoFactor = widget.user?['two_factor'] == true;
  late final Set<String> _branches = {...List<String>.from(widget.user?['branch_ids'] as List? ?? const [])};
  // Modules cochés ; null tant que l'utilisateur n'a rien touché (= ceux de la fonction)
  Set<String>? _modules;
  Set<String> _initialModules = {};
  String _q = '';
  // Filtre du haut : '' = tous, '_on' = ouverts, '_off' = fermés, sinon une catégorie
  String _cat = '';

  bool get _isNew => widget.user == null;
  bool get _isMe => widget.user != null && widget.user!['id'] == context.read<Session>().user['id'];

  List<Map<String, dynamic>> get _roles => [for (final r in (_meta?['roles'] as List? ?? const [])) asMap(r)];
  List<Map<String, dynamic>> get _allModules => [for (final m in (_meta?['modules'] as List? ?? const [])) asMap(m)];
  Map<String, dynamic>? get _role => _roles.where((r) => r['id'] == _roleId).firstOrNull;
  Set<String> get _roleModules => {for (final m in (_role?['modules'] as List? ?? const [])) '$m'};

  @override
  void initState() {
    super.initState();
    _roleId = widget.user?['role_id'] as int?;
    _managerId = widget.user?['manager_id'] as int?;
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final m = asMap(await api.get('users/form-meta'));
      Set<String>? current;
      if (!_isNew) {
        // Modules réellement ouverts aujourd'hui pour cette personne
        final a = asMap(await api.get('users/${widget.user!['id']}/access'));
        current = {
          for (final x in (a['modules'] as List? ?? const []))
            if (asMap(x)['allowed'] == true && (asMap(x)['core'] == true || (asMap(x)['permissions'] as List? ?? const []).any((p) => asMap(p)['effective'] == true))) '${asMap(x)['key']}',
        };
      }
      if (!mounted) return;
      setState(() {
        _meta = m;
        final roles = _roles;
        _roleId ??= (roles.where((r) => r['is_admin'] != true).lastOrNull ?? roles.lastOrNull)?['id'] as int?;
        _modules = current;
        _initialModules = current ?? _roleModules;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Set<String> get _effective => _modules ?? _roleModules;

  void _genPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    final r = Random.secure();
    final p = List.generate(10, (_) => chars[r.nextInt(chars.length)]).join();
    setState(() {
      _pwd.text = p;
      _showPwd = true;
    });
    Clipboard.setData(ClipboardData(text: p));
    toast(context, tr('Mot de passe généré et copié : transmettez-le à l\'employé.'));
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty || _email.text.trim().isEmpty) return toast(context, tr('Indiquez le nom et l\'e-mail.'), error: true);
    if (_isNew && _pwd.text.length < 8) return toast(context, tr('Mot de passe : 8 caractères au moins.'), error: true);
    final session = context.read<Session>();
    final changedModules = _modules != null && (_modules!.length != _initialModules.length || !_modules!.containsAll(_initialModules));
    final body = {
      'name': _name.text.trim(),
      'email': _email.text.trim(),
      'phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
      'job_title': _title.text.trim().isEmpty ? null : _title.text.trim(),
      if (_pwd.text.isNotEmpty) 'password': _pwd.text,
      'role_id': _roleId,
      'manager_id': _managerId,
      if (!_isNew) 'is_active': _active,
      if ((_meta?['branches'] as List? ?? const []).isNotEmpty) 'branch_ids': _branches.toList(),
      // nouvelle personne : on envoie toujours les modules (comme affichés) ; modification : seulement s'ils ont changé
      if (!_isMe && (_isNew || changedModules || (_modules == null && widget.user?['role_id'] != _roleId))) 'modules': _effective.toList(),
    };
    setState(() => _busy = true);
    try {
      _isNew ? await session.api.post('users', body) : await session.api.put('users/${widget.user!['id']}', body);
      if (mounted) {
        Navigator.pop(context, true);
        toast(context, tr(_isNew ? 'Accès créé pour ${_name.text.trim()}.' : 'Modifications enregistrées.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final wide = !widget.fullscreen && MediaQuery.sizeOf(context).width >= 900;
    final header = Container(
      padding: const EdgeInsets.fromLTRB(24, 18, 12, 18),
      decoration: BoxDecoration(border: Border(bottom: BorderSide(color: theme.dividerColor.withValues(alpha: .5)))),
      child: Row(children: [
        _name.text.trim().isEmpty
            ? Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .12), shape: BoxShape.circle),
                child: const Icon(Icons.person_add_alt_1_outlined, color: YColors.blue, size: 22),
              )
            : InitialsAvatar(_name.text.trim(), size: 44),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(_isNew ? 'Nouvel utilisateur' : 'Modifier ${widget.user!['name']}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            Text(tr('Identité, fonction, responsable et modules auxquels il a accès'), style: TextStyle(color: theme.hintColor, fontSize: 13)),
          ]),
        ),
        IconButton(tooltip: tr('Fermer'), onPressed: () => Navigator.pop(context, false), icon: const Icon(Icons.close)),
      ]),
    );
    final footer = Container(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 14),
      decoration: BoxDecoration(color: theme.colorScheme.surfaceContainerLow, border: Border(top: BorderSide(color: theme.dividerColor.withValues(alpha: .5)))),
      child: Row(children: [
        if (_meta != null)
          Expanded(
            child: Text(
              tr(_role == null ? '' : '${_role!['name']} · ${_effective.length} module(s) ouvert(s)'),
              style: TextStyle(color: theme.hintColor, fontWeight: FontWeight.w600),
              overflow: TextOverflow.ellipsis,
            ),
          )
        else
          const Spacer(),
        TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler'))),
        const SizedBox(width: 8),
        FilledButton.icon(
          onPressed: _busy || _meta == null ? null : _save,
          icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.check, size: 18),
          label: Text(tr(_isNew ? 'Créer l\'accès' : 'Enregistrer')),
        ),
      ]),
    );
    Widget body;
    if (_error != null) {
      body = Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(errorText(_error!))));
    } else if (_meta == null) {
      body = const Center(child: CircularProgressIndicator());
    } else if (wide) {
      body = Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        SizedBox(width: 420, child: SingleChildScrollView(padding: const EdgeInsets.fromLTRB(24, 20, 20, 24), child: _identity(theme))),
        VerticalDivider(width: 1, color: theme.dividerColor.withValues(alpha: .5)),
        Expanded(child: _modulesPane(theme, scroll: true)),
      ]);
    } else {
      body = ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 24), children: [_identity(theme), const SizedBox(height: 20), _modulesPane(theme, scroll: false)]);
    }
    final content = Column(children: [if (!widget.fullscreen) header, Expanded(child: body), footer]);
    if (widget.fullscreen) {
      return Scaffold(
        appBar: AppBar(title: Text(tr(_isNew ? 'Nouvel utilisateur' : 'Modifier l\'utilisateur'))),
        body: SafeArea(top: false, child: content),
      );
    }
    return Material(child: content);
  }

  Widget _section(String title, IconData icon, List<Widget> children) => Padding(
        padding: const EdgeInsets.only(bottom: 18),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [Icon(icon, size: 18, color: YColors.blue), const SizedBox(width: 8), Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))]),
          const SizedBox(height: 10),
          ...children,
        ]),
      );

  Widget _identity(ThemeData theme) {
    final users = [for (final u in (_meta?['users'] as List? ?? const [])) asMap(u)].where((u) => u['id'] != widget.user?['id']).toList();
    final branches = [for (final b in (_meta?['branches'] as List? ?? const [])) asMap(b)];
    final parent = _role?['parent_id'] == null ? null : _roles.where((r) => r['id'] == _role!['parent_id']).firstOrNull;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _section('Identité', Icons.badge_outlined, [
        TextField(controller: _name, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: tr('Nom et prénom'), prefixIcon: const Icon(Icons.person_outline))),
        const SizedBox(height: 10),
        TextField(controller: _email, keyboardType: TextInputType.emailAddress, decoration: InputDecoration(labelText: tr('E-mail de connexion'), prefixIcon: const Icon(Icons.alternate_email))),
        const SizedBox(height: 10),
        TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Téléphone (facultatif)'), prefixIcon: const Icon(Icons.phone_outlined))),
        const SizedBox(height: 10),
        TextField(
          controller: _pwd,
          obscureText: !_showPwd,
          decoration: InputDecoration(
            labelText: tr(_isNew ? 'Mot de passe provisoire (8 caractères min.)' : 'Nouveau mot de passe (vide = inchangé)'),
            prefixIcon: const Icon(Icons.lock_outline),
            suffixIcon: Row(mainAxisSize: MainAxisSize.min, children: [
              IconButton(tooltip: tr(_showPwd ? 'Masquer' : 'Afficher'), icon: Icon(_showPwd ? Icons.visibility_off_outlined : Icons.visibility_outlined, size: 20), onPressed: () => setState(() => _showPwd = !_showPwd)),
              IconButton(tooltip: tr('Générer un mot de passe'), icon: const Icon(Icons.auto_awesome_outlined, size: 20), onPressed: _genPassword),
            ]),
          ),
        ),
      ]),
      _section('Fonction et hiérarchie', Icons.account_tree_outlined, [
        DropdownButtonFormField<int>(
          initialValue: _roleId,
          isExpanded: true,
          decoration: InputDecoration(labelText: tr('Fonction (droits)'), prefixIcon: const Icon(Icons.work_outline)),
          items: [
            for (final r in _orderedRoles())
              DropdownMenuItem(
                value: r.$1['id'] as int,
                child: Padding(
                  padding: EdgeInsets.only(left: 14.0 * r.$2),
                  child: Text(tr('${r.$2 > 0 ? '└ ' : ''}${r.$1['name']}'), overflow: TextOverflow.ellipsis),
                ),
              ),
          ],
          onChanged: (v) => setState(() {
            _roleId = v;
            _modules = null; // les modules suivent la nouvelle fonction
          }),
        ),
        if (_role != null && ('${_role!['description'] ?? ''}'.isNotEmpty || parent != null || _role!['is_admin'] == true)) ...[
          const SizedBox(height: 6),
          Text(
            tr([
              if ('${_role!['description'] ?? ''}'.isNotEmpty) '${_role!['description']}',
              if (parent != null) 'Rattachée à : ${parent['name']}',
              if (_role!['is_admin'] == true) 'Accès complet à toute l\'entreprise',
            ].join(' · ')),
            style: TextStyle(color: theme.hintColor, fontSize: 12.5),
          ),
        ],
        const SizedBox(height: 10),
        TextField(controller: _title, decoration: InputDecoration(labelText: tr('Intitulé du poste (facultatif)'), hintText: tr('Ex. Chef de rayon, Responsable Cocody'), prefixIcon: const Icon(Icons.label_outline))),
        const SizedBox(height: 10),
        DropdownButtonFormField<int?>(
          initialValue: users.any((u) => u['id'] == _managerId) ? _managerId : null,
          isExpanded: true,
          decoration: InputDecoration(labelText: tr('Responsable direct'), prefixIcon: const Icon(Icons.supervisor_account_outlined), helperText: tr('Valide ses demandes si le circuit le prévoit'), helperMaxLines: 2),
          items: [
            DropdownMenuItem<int?>(value: null, child: Text(tr('Aucun (selon l\'organigramme)'))),
            for (final u in users) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}${u['role'] != null ? ' · ${u['role']}' : ''}'), overflow: TextOverflow.ellipsis)),
          ],
          onChanged: (v) => setState(() => _managerId = v),
        ),
      ]),
      if (branches.isNotEmpty)
        _section('Agences', Icons.store_mall_directory_outlined, [
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final b in branches)
              FilterChip(label: Text(tr('${b['name']}')), selected: _branches.contains('${b['id']}'), onSelected: (v) => setState(() => v ? _branches.add('${b['id']}') : _branches.remove('${b['id']}'))),
          ]),
          const SizedBox(height: 4),
          Text(tr(_branches.isEmpty ? 'Aucune cochée : accès à toutes les agences.' : 'Ne voit que les ventes, caisses, stocks et dépenses de ces agences.'), style: TextStyle(fontSize: 12, color: theme.hintColor)),
        ]),
      if (!_isNew)
        Card(
          margin: EdgeInsets.zero,
          child: Column(children: [
            SwitchListTile(title: Text(tr('Compte actif')), subtitle: Text(tr(_active ? 'Peut se connecter' : 'Accès bloqué partout, historique conservé')), value: _active, onChanged: _isMe ? null : (v) => setState(() => _active = v)),
            if (!_isMe)
              ListTile(
                leading: const Icon(Icons.tune),
                title: Text(tr('Droits détaillés')),
                subtitle: Text(tr('Action par action, dans chaque module')),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => showUserAccessDialog(context, widget.user!),
              ),
            // Téléphone perdu sans code de secours : l'administrateur retire la double authentification
            if (!_isMe && _twoFactor)
              ListTile(
                leading: const Icon(Icons.verified_user_outlined, color: YColors.success),
                title: Text(tr('Double authentification activée')),
                subtitle: Text(tr('Téléphone perdu ? Réinitialisez-la : la personne la réactivera à sa prochaine connexion.')),
                trailing: TextButton(
                  onPressed: () async {
                    if (!await confirm(context, tr('Réinitialiser la double authentification ?'),
                        tr('${widget.user!['name']} sera déconnecté(e) de tous ses appareils et pourra se reconnecter avec son seul mot de passe.'),
                        ok: tr('Réinitialiser'), danger: true)) {
                      return;
                    }
                    if (!mounted) return;
                    final api = context.read<Session>().api;
                    try {
                      await api.post('users/${widget.user!['id']}/2fa/reset');
                      if (!mounted) return;
                      setState(() => _twoFactor = false);
                      toast(context, tr('Double authentification réinitialisée.'));
                    } catch (e) {
                      if (mounted) toast(context, errorText(e), error: true);
                    }
                  },
                  child: Text(tr('Réinitialiser')),
                ),
              ),
          ]),
        ),
    ]);
  }

  /// Fonctions dans l'ordre de l'organigramme (racines puis subordonnées), avec leur niveau.
  List<(Map<String, dynamic>, int)> _orderedRoles() {
    final roles = _roles;
    final ids = {for (final r in roles) r['id']};
    final out = <(Map<String, dynamic>, int)>[];
    void walk(Object? parent, int depth) {
      for (final r in roles.where((r) => (parent == null ? (r['parent_id'] == null || !ids.contains(r['parent_id'])) : r['parent_id'] == parent))) {
        if (out.any((o) => o.$1['id'] == r['id']) || depth > 8) continue;
        out.add((r, depth));
        walk(r['id'], depth + 1);
      }
    }

    walk(null, 0);
    return out;
  }

  Widget _modulesPane(ThemeData theme, {required bool scroll}) {
    final role = _role;
    final admin = role?['is_admin'] == true;
    final q = _q.toLowerCase();
    final eff0 = _effective;
    final mods = _allModules
        .where((m) => m['core'] != true && (q.isEmpty || '${m['name']} ${m['category']}'.toLowerCase().contains(q)))
        .where((m) => switch (_cat) {
              '' => true,
              '_on' => eff0.contains('${m['key']}'),
              '_off' => !eff0.contains('${m['key']}'),
              _ => '${m['category'] ?? 'Autres'}' == _cat,
            })
        .toList();
    final cats = <String>{for (final m in _allModules.where((m) => m['core'] != true)) '${m['category'] ?? 'Autres'}'}.toList()..sort();
    final nOn = _allModules.where((m) => m['core'] != true && eff0.contains('${m['key']}')).length;
    final nAll = _allModules.where((m) => m['core'] != true).length;
    final byCat = <String, List<Map<String, dynamic>>>{};
    for (final m in mods) {
      byCat.putIfAbsent('${m['category'] ?? 'Autres'}', () => []).add(m);
    }
    final eff = _effective;
    final top = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        const Icon(Icons.apps_rounded, size: 18, color: YColors.blue),
        const SizedBox(width: 8),
        Expanded(child: Text(tr('Accès aux modules'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
        if (!_isMe) ...[
          TextButton(onPressed: () => setState(() => _modules = null), child: Text(tr('Selon la fonction'))),
          TextButton(onPressed: () => setState(() => _modules = {for (final m in _allModules) '${m['key']}'}), child: Text(tr('Tout'))),
          TextButton(onPressed: () => setState(() => _modules = {for (final m in _allModules.where((m) => m['core'] == true)) '${m['key']}'}), child: Text(tr('Aucun'))),
        ],
      ]),
      const SizedBox(height: 4),
      Text(
        tr(_isMe
            ? 'Vos propres accès se modifient depuis un autre compte administrateur.'
            : admin
                ? 'Fonction « ${role!['name']} » : accès complet. Décochez un module pour le masquer à cette personne.'
                : 'Cochés d\'après la fonction choisie. Cochez un module en plus pour l\'ouvrir en entier à cette personne, décochez-en un pour le masquer.'),
        style: TextStyle(color: theme.hintColor, fontSize: 12.5, height: 1.35),
      ),
      const SizedBox(height: 10),
      SearchField(hint: tr('Rechercher un module…'), onChanged: (v) => setState(() => _q = v)),
      const SizedBox(height: 10),
      // Filtre par module : ouverts / fermés / par domaine
      SizedBox(
        height: 38,
        child: ListView(scrollDirection: Axis.horizontal, children: [
          for (final (k, l) in [('', 'Tous ($nAll)'), ('_on', 'Ouverts ($nOn)'), ('_off', 'Fermés (${nAll - nOn})'), for (final c in cats) (c, c)])
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: ChoiceChip(
                label: Text(tr(l)),
                selected: _cat == k,
                showCheckmark: false,
                onSelected: (_) => setState(() => _cat = k),
              ),
            ),
        ]),
      ),
      const SizedBox(height: 12),
    ]);
    final grid = LayoutBuilder(builder: (context, c) {
      final cols = max(1, ((c.maxWidth + 10) / 250).floor());
      final w = (c.maxWidth - (cols - 1) * 10) / cols;
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        for (final e in byCat.entries) ...[
          Padding(
            padding: const EdgeInsets.only(top: 6, bottom: 8),
            child: Text(tr(e.key), style: TextStyle(color: theme.hintColor, fontWeight: FontWeight.w700, fontSize: 12.5)),
          ),
          Wrap(spacing: 10, runSpacing: 10, children: [
            for (final m in e.value) SizedBox(width: w, child: _moduleTile(theme, m, eff.contains('${m['key']}'))),
          ]),
          const SizedBox(height: 10),
        ],
        if (byCat.isEmpty) Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(tr('Aucun module ne correspond.'), style: TextStyle(color: theme.hintColor)))),
      ]);
    });
    if (!scroll) return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [top, grid]);
    return ListView(padding: const EdgeInsets.fromLTRB(20, 20, 24, 24), children: [top, grid]);
  }

  Widget _moduleTile(ThemeData theme, Map<String, dynamic> m, bool on) {
    final key = '${m['key']}';
    final fromRole = _roleModules.contains(key);
    final color = on ? YColors.blue : theme.hintColor;
    return Material(
      color: on ? YColors.blue.withValues(alpha: .06) : theme.colorScheme.surface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: on ? YColors.blue.withValues(alpha: .45) : theme.dividerColor.withValues(alpha: .7))),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: _isMe
            ? null
            : () => setState(() {
                  final s = {..._effective};
                  on ? s.remove(key) : s.add(key);
                  _modules = s;
                }),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
          child: Row(children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)),
              child: Icon(iconFor(m['icon'] as String?), size: 19, color: color),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                Text(tr('${m['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, color: on ? null : theme.hintColor)),
                Text(
                  tr(on ? (fromRole ? 'Par sa fonction' : 'Ajouté pour lui') : (fromRole ? 'Masqué pour lui' : 'Pas d\'accès')),
                  style: TextStyle(fontSize: 11.5, color: on && !fromRole ? YColors.success : (!on && fromRole ? YColors.warning : theme.hintColor)),
                ),
              ]),
            ),
            Checkbox(value: on, onChanged: _isMe ? null : (_) => setState(() {
                  final s = {..._effective};
                  on ? s.remove(key) : s.add(key);
                  _modules = s;
                })),
          ]),
        ),
      ),
    );
  }
}
