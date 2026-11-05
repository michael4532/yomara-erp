import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../navigation.dart';
import '../widgets/common.dart' show YomaraMark;

const _navy = Color(0xFF0A1633);
const _navySoft = Color(0xFF13244D);
const _gold = Color(0xFFFFC531);

/// À quoi sert chaque espace de travail (clé de [NavGroup]).
const _groupPurpose = <String, String>{
  'pilotage': 'Votre tableau de bord : chiffres clés, prévisions, rapports, exports Excel, validations et assistant IA pour décider vite.',
  'ventes': 'Devis, factures, clients et prospects, relances des impayés, liens de paiement, fidélité et rendez-vous.',
  'caisse': 'La caisse pour vendre au comptoir, la gestion des tables et l\'écran cuisine pour les restaurants.',
  'achats': 'Fournisseurs, demandes d\'achat et bons de commande, articles et tarifs, stocks, inventaires, lots et étiquettes.',
  'operations': 'Livraisons, parc automobile, matériel, maintenance, qualité, production, interventions, projets et planning.',
  'finance': 'Banque, caisse et mobile money, comptabilité, budgets analytiques, immobilisations, devises et notes de frais.',
  'rh': 'Salariés, paie, présence et pointage, congés, recrutement, et l\'espace personnel de chaque employé.',
  'digital': 'Stratégie et calendrier éditorial, campagnes, questionnaires, avis Google, site web et boutique en ligne.',
  'juridique': 'Contrats, contentieux et échéances juridiques, avec des rappels avant chaque date importante.',
  'metiers': 'Outils spécialisés par métier : immobilier, BTP, location, appels d\'offres, événements, billetterie et hébergement.',
  'collab': 'E-mails, WhatsApp, agenda, réunions vidéo, tickets internes, documents partagés et signature électronique.',
  'admin': 'Paramètres de la société, utilisateurs et droits, moyens de paiement, sauvegardes, imports et modules.',
  'superadmin': 'La console de la plateforme : sociétés clientes, formules, modules, licences, mises à jour et support.',
};

/// Guide de démarrage : présenté une fois à chaque utilisateur (première connexion après l'inscription
/// ou l'installation), puis rejouable depuis le menu du compte ou la recherche rapide.
///
/// [available] (écrans accessibles) et [go] (ouvrir un écran) sont lus depuis [ShellNav] s'ils ne sont pas fournis.
Future<void> showOnboardingGuide(BuildContext context, {bool markSeen = true, Set<String>? available, void Function(String id)? go}) async {
  final session = context.read<Session>();
  final nav = context.getInheritedWidgetOfExactType<ShellNav>();
  final ids = available ?? nav?.available ?? const <String>{};
  final open = go ?? nav?.go;
  final reduce = MediaQuery.of(context).disableAnimations;

  final target = await showGeneralDialog<String>(
    context: context,
    barrierDismissible: false,
    barrierLabel: tr('Guide de démarrage'),
    barrierColor: _navy.withValues(alpha: .72),
    transitionDuration: Duration(milliseconds: reduce ? 0 : 380),
    pageBuilder: (_, _, _) => _Guide(session: session, available: ids),
    transitionBuilder: (_, anim, _, child) {
      final a = CurvedAnimation(parent: anim, curve: Curves.easeOutCubic);
      return FadeTransition(opacity: a, child: ScaleTransition(scale: Tween(begin: .96, end: 1.0).animate(a), child: child));
    },
  );

  if (markSeen) {
    final user = session.bootstrap?['user'];
    if (user is Map) {
      user['onboarded'] = true;
      try {
        await LocalDb.meta.put('bootstrap', jsonEncode(session.bootstrap));
      } catch (_) {}
    }
    try {
      await session.api.post('me/onboarded', {'done': true});
    } catch (_) {}
  }
  if (target != null && open != null) open(target);
}

class _Page {
  const _Page(this.key, this.builder);

  final String key;
  final Widget Function(BuildContext context, bool play, bool wide) builder;
}

class _Guide extends StatefulWidget {
  const _Guide({required this.session, required this.available});

  final Session session;
  final Set<String> available;

  @override
  State<_Guide> createState() => _GuideState();
}

class _GuideState extends State<_Guide> {
  final _ctrl = PageController();
  final Set<int> _seen = {0};
  int _index = 0;
  late final List<_Page> _pages = _buildPages();

  bool get _reduce => MediaQuery.of(context).disableAnimations;
  bool get _last => _index == _pages.length - 1;

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  void _to(int i) {
    if (i < 0 || i >= _pages.length) return;
    if (_reduce) {
      _ctrl.jumpToPage(i);
    } else {
      _ctrl.animateToPage(i, duration: const Duration(milliseconds: 460), curve: Curves.easeOutCubic);
    }
  }

  void _next() => _last ? _close() : _to(_index + 1);

  void _close([String? target]) => Navigator.of(context).pop(target);

  KeyEventResult _onKey(FocusNode _, KeyEvent ev) {
    if (ev is! KeyDownEvent && ev is! KeyRepeatEvent) return KeyEventResult.ignored;
    final k = ev.logicalKey;
    if (k == LogicalKeyboardKey.arrowRight) {
      _to(_index + 1);
    } else if (k == LogicalKeyboardKey.arrowLeft) {
      _to(_index - 1);
    } else if (k == LogicalKeyboardKey.escape) {
      _close();
    } else {
      return KeyEventResult.ignored;
    }
    return KeyEventResult.handled;
  }

  List<_Page> _buildPages() {
    final s = widget.session;
    final ids = widget.available;
    final groups = [for (final g in navGroups) if (g.ids.any(ids.contains)) g];
    final canTeam = s.can('core.settings.manage');
    final firstName = '${s.user['name'] ?? ''}'.trim().split(RegExp(r'\s+')).first;
    final company = '${s.company['name'] ?? ''}'.trim();
    final hasStore = ids.contains('store');

    return [
      _Page('welcome', (c, play, wide) => _Split(
            wide: wide,
            visual: _Entrance(play: play, child: const _HeroMark()),
            child: _Text(
              play: play,
              eyebrow: [if (firstName.isNotEmpty) tr('Bonjour ${verbatim(firstName)}'), if (company.isNotEmpty) verbatim(company)].join('  ·  '),
              title: tr('Bienvenue sur Yomara ERP'),
              body: tr('Un seul logiciel pour gérer toute votre entreprise : ventes, caisse, stocks, finances, équipes et bien plus. '
                  'Vos données restent synchronisées sur ordinateur et sur téléphone.'),
              highlight: tr('Tous vos employés peuvent l\'utiliser ; ajoutez des modules quand vous en avez besoin.'),
              foot: tr('Ce guide prend environ deux minutes.'),
            ),
          )),
      if (groups.isNotEmpty)
        _Page('spaces', (c, play, wide) => _Spaces(groups: groups, available: ids, play: play, wide: wide)),
      _Page('search', (c, play, wide) => _Split(
            wide: wide,
            visual: _Entrance(play: play, child: const _SearchMock()),
            child: _Text(
              play: play,
              eyebrow: tr('Recherche rapide'),
              title: tr('Trouver n\'importe quoi'),
              body: tr('Cliquez sur le champ « Rechercher » en haut de l\'écran, ou appuyez sur Ctrl K (Cmd K sur Mac). '
                  'Tapez quelques lettres pour ouvrir un module ou lancer une action : nouvelle facture, ouvrir la caisse, ajouter un client…'),
              highlight: tr('Vos écrans récents s\'affichent en premier : vous retrouvez votre travail en un geste.'),
            ),
          )),
      _Page('offline', (c, play, wide) => _Split(
            wide: wide,
            visual: _Entrance(play: play, child: const _OfflineMock()),
            child: _Text(
              play: play,
              eyebrow: tr('Hors ligne'),
              title: tr('Continuez même sans internet'),
              body: tr('Vos ventes, tickets de caisse et saisies sont enregistrés sur l\'appareil, puis envoyés automatiquement au serveur dès que la connexion revient.'),
              highlight: tr('Le nuage en haut à droite indique l\'état : synchronisé, en cours, en attente ou hors ligne.'),
            ),
          )),
      _Page('modules', (c, play, wide) => _Split(
            wide: wide,
            visual: _Entrance(
              play: play,
              child: const _IconOrbit(icon: Icons.storefront_outlined, chips: [
                (Icons.workspace_premium_outlined, 'Formule gratuite'),
                (Icons.extension_outlined, 'Modules à la carte'),
                (Icons.local_offer_outlined, 'Code promo'),
              ]),
            ),
            child: _Text(
              play: play,
              eyebrow: tr('Boutique de modules'),
              title: tr('Ajoutez des modules à votre rythme'),
              body: tr('Commencez avec la formule gratuite, puis activez seulement les modules utiles : paie, restaurant, comptabilité, site web… '
                  'Ils apparaissent aussitôt dans votre menu.'),
              highlight: hasStore
                  ? tr('Un code promo ? Saisissez-le dans « Boutique de modules › Mon abonnement » au moment du paiement.')
                  : tr('Besoin d\'un module ? Demandez à l\'administrateur de votre société de l\'activer.'),
            ),
          )),
      if (canTeam)
        _Page('team', (c, play, wide) => _Split(
              wide: wide,
              visual: _Entrance(
                play: play,
                child: const _IconOrbit(icon: Icons.group_add_outlined, chips: [
                  (Icons.point_of_sale, 'Caissier'),
                  (Icons.account_balance_outlined, 'Comptable'),
                  (Icons.badge_outlined, 'Manager RH'),
                ]),
              ),
              child: _Text(
                play: play,
                eyebrow: tr('Votre équipe'),
                title: tr('Invitez votre équipe'),
                body: tr('Dans Paramètres › Utilisateurs, ajoutez vos collaborateurs et choisissez leur rôle. '
                    'Chacun ne voit que ce qui concerne son département : la caisse pour le caissier, la paie pour les RH, la comptabilité pour le comptable.'),
                highlight: tr('Les droits se règlent finement, département par département.'),
              ),
            )),
      _Page('help', (c, play, wide) => _Split(
            wide: wide,
            visual: _Entrance(
              play: play,
              child: const _IconOrbit(icon: Icons.support_agent_rounded, chips: [
                (Icons.chat_bubble_outline, 'Chat en direct'),
                (Icons.notifications_outlined, 'Notifications'),
                (Icons.school_outlined, 'Ce guide'),
              ]),
            ),
            child: _Text(
              play: play,
              eyebrow: tr('Assistance'),
              title: tr('Besoin d\'aide ?'),
              body: tr('Le bouton « Support » en bas à droite ouvre un chat en direct avec notre équipe. '
                  'La cloche en haut vous prévient des validations, des tâches et des réponses qui vous attendent.'),
              highlight: tr('Vous pouvez revoir ce guide à tout moment depuis le menu de votre compte ou la recherche rapide.'),
            ),
          )),
      _Page('end', (c, play, wide) => _Finish(
            play: play,
            wide: wide,
            shortcuts: [
              if (ids.contains('ventes')) (Icons.add_card, 'Créer ma première facture', 'ventes'),
              if (canTeam && ids.contains('settings')) (Icons.person_add_alt, 'Ajouter mes utilisateurs', 'settings'),
              if (hasStore) (Icons.storefront_outlined, 'Voir la boutique', 'store'),
            ],
            onPick: _close,
          )),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    final compact = size.width < 760 || size.height < 560;
    final wide = !compact;
    final anim = Duration(milliseconds: _reduce ? 0 : 260);

    final content = Focus(
      autofocus: true,
      onKeyEvent: _onKey,
      child: Stack(children: [
        const Positioned.fill(child: _Backdrop()),
        SafeArea(
          child: Padding(
            padding: EdgeInsets.fromLTRB(wide ? 28 : 16, wide ? 20 : 8, wide ? 28 : 16, wide ? 22 : 12),
            child: Column(children: [
              // En-tête : marque, étape, passer
              Row(children: [
                const YomaraMark(size: 30),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                    Text(tr('Guide de démarrage'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14.5)),
                    Text(tr('Étape ${_index + 1} sur ${_pages.length}'), style: TextStyle(color: Colors.white.withValues(alpha: .6), fontSize: 12)),
                  ]),
                ),
                AnimatedOpacity(
                  opacity: _last ? 0 : 1,
                  duration: anim,
                  child: IgnorePointer(
                    ignoring: _last,
                    child: TextButton(
                      onPressed: _close,
                      style: TextButton.styleFrom(foregroundColor: Colors.white.withValues(alpha: .8)),
                      child: Text(tr('Passer le guide')),
                    ),
                  ),
                ),
              ]),
              Expanded(
                child: PageView.builder(
                  controller: _ctrl,
                  itemCount: _pages.length,
                  onPageChanged: (i) => setState(() {
                    _index = i;
                    _seen.add(i);
                  }),
                  itemBuilder: (c, i) => Padding(
                    padding: EdgeInsets.symmetric(horizontal: wide ? 8 : 0, vertical: 12),
                    child: _pages[i].builder(c, _seen.contains(i), wide),
                  ),
                ),
              ),
              // Pied : précédent, points de progression, suivant
              Row(children: [
                SizedBox(
                  width: wide ? 140 : 52,
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: AnimatedOpacity(
                      opacity: _index == 0 ? 0 : 1,
                      duration: anim,
                      child: IgnorePointer(
                        ignoring: _index == 0,
                        child: wide
                            ? TextButton.icon(
                                onPressed: () => _to(_index - 1),
                                style: TextButton.styleFrom(foregroundColor: Colors.white.withValues(alpha: .85)),
                                icon: const Icon(Icons.arrow_back, size: 18),
                                label: Text(tr('Précédent')),
                              )
                            : IconButton(
                                tooltip: tr('Précédent'),
                                onPressed: () => _to(_index - 1),
                                color: Colors.white,
                                icon: const Icon(Icons.arrow_back),
                              ),
                      ),
                    ),
                  ),
                ),
                Expanded(
                  child: Wrap(alignment: WrapAlignment.center, children: [
                    for (var i = 0; i < _pages.length; i++)
                      Semantics(
                        button: true,
                        label: tr('Étape ${i + 1}'),
                        child: GestureDetector(
                          behavior: HitTestBehavior.opaque,
                          onTap: () => _to(i),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 3, vertical: 10),
                            child: AnimatedContainer(
                              duration: anim,
                              curve: Curves.easeOutCubic,
                              width: i == _index ? 22 : 8,
                              height: 8,
                              decoration: BoxDecoration(
                                color: i == _index ? _gold : Colors.white.withValues(alpha: i < _index ? .5 : .22),
                                borderRadius: BorderRadius.circular(8),
                              ),
                            ),
                          ),
                        ),
                      ),
                  ]),
                ),
                SizedBox(
                  width: wide ? 140 : 120,
                  child: Align(
                    alignment: Alignment.centerRight,
                    child: FilledButton(
                      onPressed: _next,
                      style: FilledButton.styleFrom(
                        backgroundColor: _gold,
                        foregroundColor: _navy,
                        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        textStyle: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        Text(_last ? tr('Commencer') : tr('Suivant')),
                        const SizedBox(width: 6),
                        Icon(_last ? Icons.rocket_launch_outlined : Icons.arrow_forward, size: 18),
                      ]),
                    ),
                  ),
                ),
              ]),
            ]),
          ),
        ),
      ]),
    );

    if (compact) return Material(color: _navy, child: content);
    return Center(
      child: SizedBox(
        width: (size.width - 48).clamp(0, 900).toDouble(),
        height: (size.height - 48).clamp(0, 620).toDouble(),
        child: Material(
          color: _navy,
          elevation: 24,
          shadowColor: Colors.black,
          borderRadius: BorderRadius.circular(26),
          clipBehavior: Clip.antiAlias,
          child: content,
        ),
      ),
    );
  }
}

/// Fond : dégradé marine et halos dorés discrets.
class _Backdrop extends StatelessWidget {
  const _Backdrop();

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [_navy, _navySoft, _navy]),
        ),
        child: Stack(children: [
          Positioned(right: -120, top: -140, child: _Halo(size: 360, alpha: .16)),
          Positioned(left: -160, bottom: -180, child: _Halo(size: 420, alpha: .08)),
        ]),
      );
}

class _Halo extends StatelessWidget {
  const _Halo({required this.size, required this.alpha});

  final double size, alpha;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(colors: [_gold.withValues(alpha: alpha), _gold.withValues(alpha: 0)]),
        ),
      );
}

/// Apparition (fondu + glissement) la première fois qu'une page est affichée ; [delay] décale les éléments.
class _Entrance extends StatelessWidget {
  const _Entrance({required this.play, required this.child, this.delay = 0});

  final bool play;
  final Widget child;
  final int delay;

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.of(context).disableAnimations) return child;
    final total = 520 + delay;
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: play ? 1 : 0),
      duration: Duration(milliseconds: total),
      curve: Interval(delay / total, 1, curve: Curves.easeOutCubic),
      child: child,
      builder: (_, v, c) => Opacity(opacity: v, child: Transform.translate(offset: Offset(0, 18 * (1 - v)), child: c)),
    );
  }
}

/// Mise en page : visuel + texte côte à côte (grand écran) ou l'un sous l'autre (téléphone).
class _Split extends StatelessWidget {
  const _Split({required this.wide, required this.visual, required this.child});

  final bool wide;
  final Widget visual, child;

  @override
  Widget build(BuildContext context) {
    if (wide) {
      return Row(children: [
        Expanded(flex: 5, child: Center(child: visual)),
        const SizedBox(width: 28),
        Expanded(flex: 6, child: Align(alignment: Alignment.centerLeft, child: SingleChildScrollView(child: child))),
      ]);
    }
    return LayoutBuilder(
      builder: (_, box) => SingleChildScrollView(
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: box.maxHeight),
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            SizedBox(height: 210, child: FittedBox(child: visual)),
            const SizedBox(height: 20),
            child,
          ]),
        ),
      ),
    );
  }
}

class _Text extends StatelessWidget {
  const _Text({required this.play, required this.title, required this.body, this.eyebrow, this.highlight, this.foot});

  final bool play;
  final String title, body;
  final String? eyebrow, highlight, foot;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
        if (eyebrow != null && eyebrow!.isNotEmpty)
          _Entrance(
            play: play,
            delay: 60,
            child: Text(eyebrow!.toUpperCase(), style: const TextStyle(color: _gold, fontSize: 12, fontWeight: FontWeight.w800, letterSpacing: 1.1)),
          ),
        const SizedBox(height: 10),
        _Entrance(
          play: play,
          delay: 120,
          child: Text(title, style: const TextStyle(color: Colors.white, fontSize: 28, height: 1.15, fontWeight: FontWeight.w800)),
        ),
        const SizedBox(height: 14),
        _Entrance(
          play: play,
          delay: 200,
          child: Text(body, style: TextStyle(color: Colors.white.withValues(alpha: .78), fontSize: 15, height: 1.5)),
        ),
        if (highlight != null) ...[
          const SizedBox(height: 16),
          _Entrance(
            play: play,
            delay: 280,
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: _gold.withValues(alpha: .1),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: _gold.withValues(alpha: .35)),
              ),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.lightbulb_outline, color: _gold, size: 20),
                const SizedBox(width: 10),
                Expanded(child: Text(highlight!, style: const TextStyle(color: Colors.white, fontSize: 14, height: 1.45, fontWeight: FontWeight.w600))),
              ]),
            ),
          ),
        ],
        if (foot != null) ...[
          const SizedBox(height: 14),
          _Entrance(
            play: play,
            delay: 340,
            child: Row(children: [
              Icon(Icons.schedule, size: 16, color: Colors.white.withValues(alpha: .55)),
              const SizedBox(width: 6),
              Text(foot!, style: TextStyle(color: Colors.white.withValues(alpha: .55), fontSize: 13)),
            ]),
          ),
        ],
      ]);
}

/// Visuel d'accueil : le logo dans un halo doré.
class _HeroMark extends StatelessWidget {
  const _HeroMark();

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 260,
        height: 260,
        child: Stack(alignment: Alignment.center, children: [
          const _Halo(size: 260, alpha: .28),
          Container(
            width: 170,
            height: 170,
            decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: _gold.withValues(alpha: .25), width: 1.5)),
          ),
          const YomaraMark(size: 104, glow: true),
        ]),
      );
}

/// Visuel : grande icône entourée de pastilles d'exemple.
class _IconOrbit extends StatelessWidget {
  const _IconOrbit({required this.icon, required this.chips});

  final IconData icon;
  final List<(IconData, String)> chips;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 300,
        height: 260,
        child: Stack(alignment: Alignment.center, children: [
          const _Halo(size: 250, alpha: .22),
          Container(
            width: 112,
            height: 112,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: const LinearGradient(colors: [Color(0xFFFFD45E), _gold], begin: Alignment.topLeft, end: Alignment.bottomRight),
              boxShadow: [BoxShadow(color: _gold.withValues(alpha: .4), blurRadius: 36)],
            ),
            child: Icon(icon, size: 52, color: _navy),
          ),
          for (final (i, c) in chips.indexed)
            Align(
              alignment: const [Alignment(-1, -.82), Alignment(1, -.1), Alignment(-.75, .9)][i % 3],
              child: _Chip(icon: c.$1, label: tr(c.$2)),
            ),
        ]),
      );
}

class _Chip extends StatelessWidget {
  const _Chip({required this.icon, required this.label, this.color = _gold});

  final IconData icon;
  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          color: const Color(0xFF1B2D5C),
          borderRadius: BorderRadius.circular(30),
          border: Border.all(color: Colors.white.withValues(alpha: .12)),
          boxShadow: const [BoxShadow(color: Color(0x55000000), blurRadius: 14, offset: Offset(0, 6))],
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 7),
          Text(label, style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w700)),
        ]),
      );
}

/// Visuel : la recherche rapide et ses résultats.
class _SearchMock extends StatelessWidget {
  const _SearchMock();

  @override
  Widget build(BuildContext context) {
    Widget row(IconData icon, String label, String sub, {bool sel = false}) => Container(
          margin: const EdgeInsets.only(top: 6),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
          decoration: BoxDecoration(color: sel ? _gold.withValues(alpha: .14) : null, borderRadius: BorderRadius.circular(10)),
          child: Row(children: [
            Icon(icon, size: 18, color: sel ? _gold : Colors.white70),
            const SizedBox(width: 10),
            Expanded(child: Text(tr(label), style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600))),
            Text(tr(sub), style: TextStyle(color: Colors.white.withValues(alpha: .5), fontSize: 11.5)),
          ]),
        );
    return Container(
      width: 320,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFF16285A),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Colors.white.withValues(alpha: .1)),
        boxShadow: [BoxShadow(color: _gold.withValues(alpha: .15), blurRadius: 40)],
      ),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(color: Colors.white.withValues(alpha: .07), borderRadius: BorderRadius.circular(12)),
          child: Row(children: [
            const Icon(Icons.search, size: 19, color: Colors.white70),
            const SizedBox(width: 8),
            Expanded(child: Text(tr('fact'), style: const TextStyle(color: Colors.white, fontSize: 14))),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(border: Border.all(color: Colors.white30), borderRadius: BorderRadius.circular(5)),
              child: Text(tr('Ctrl K'), style: const TextStyle(color: Colors.white70, fontSize: 10.5, fontWeight: FontWeight.w700)),
            ),
          ]),
        ),
        row(Icons.add_card, 'Nouvelle facture', 'Ventes', sel: true),
        row(Icons.receipt_long_outlined, 'Ventes', 'Factures'),
        row(Icons.post_add, 'Saisir une facture fournisseur', 'Achats'),
      ]),
    );
  }
}

/// Visuel : les états de synchronisation (comme l'indicateur en haut à droite).
class _OfflineMock extends StatelessWidget {
  const _OfflineMock();

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 300,
        height: 260,
        child: Stack(alignment: Alignment.center, children: [
          const _Halo(size: 250, alpha: .22),
          Container(
            width: 112,
            height: 112,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: const LinearGradient(colors: [Color(0xFFFFD45E), _gold], begin: Alignment.topLeft, end: Alignment.bottomRight),
              boxShadow: [BoxShadow(color: _gold.withValues(alpha: .4), blurRadius: 36)],
            ),
            child: const Icon(Icons.cloud_sync_outlined, size: 54, color: _navy),
          ),
          Align(alignment: const Alignment(-1, -.85), child: _Chip(icon: Icons.cloud_done_outlined, label: tr('Synchronisé'), color: const Color(0xFF4ADE80))),
          Align(alignment: const Alignment(1, -.15), child: _Chip(icon: Icons.sync, label: tr('Synchronisation…'), color: const Color(0xFF60A5FA))),
          Align(alignment: const Alignment(-.8, .9), child: _Chip(icon: Icons.cloud_off, label: tr('Hors ligne'), color: const Color(0xFFFBBF24))),
        ]),
      );
}

/// Page « Vos espaces de travail » : uniquement ceux auxquels l'utilisateur a accès.
class _Spaces extends StatelessWidget {
  const _Spaces({required this.groups, required this.available, required this.play, required this.wide});

  final List<NavGroup> groups;
  final Set<String> available;
  final bool play, wide;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        _Entrance(
          play: play,
          child: Text(tr('Vos espaces de travail'), style: TextStyle(color: Colors.white, fontSize: wide ? 26 : 23, fontWeight: FontWeight.w800)),
        ),
        const SizedBox(height: 6),
        _Entrance(
          play: play,
          delay: 60,
          child: Text(
            tr('Le menu de gauche range vos écrans par métier. Voici ceux auxquels vous avez accès :'),
            style: TextStyle(color: Colors.white.withValues(alpha: .72), fontSize: 14, height: 1.4),
          ),
        ),
        const SizedBox(height: 14),
        Expanded(
          child: LayoutBuilder(builder: (_, box) {
            final cols = box.maxWidth >= 760 ? 3 : (box.maxWidth >= 480 ? 2 : 1);
            final w = (box.maxWidth - 12 * (cols - 1)) / cols;
            return SingleChildScrollView(
              child: Wrap(spacing: 12, runSpacing: 12, children: [
                for (final (i, g) in groups.indexed)
                  _Entrance(
                    play: play,
                    delay: 100 + 55 * i,
                    child: SizedBox(width: w, child: _SpaceCard(group: g, screens: g.ids.where(available.contains).length)),
                  ),
              ]),
            );
          }),
        ),
      ]);
}

class _SpaceCard extends StatelessWidget {
  const _SpaceCard({required this.group, required this.screens});

  final NavGroup group;
  final int screens;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: .06),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.white.withValues(alpha: .1)),
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: _gold.withValues(alpha: .14), borderRadius: BorderRadius.circular(12)),
            child: Icon(group.icon, color: _gold, size: 21),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Flexible(
                  child: Text(tr(group.label), overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14)),
                ),
                const SizedBox(width: 6),
                Text(tr('$screens écran(s)'), style: TextStyle(color: Colors.white.withValues(alpha: .45), fontSize: 11)),
              ]),
              const SizedBox(height: 4),
              Text(
                tr(_groupPurpose[group.key] ?? group.hint ?? ''),
                style: TextStyle(color: Colors.white.withValues(alpha: .72), fontSize: 12.5, height: 1.4),
              ),
            ]),
          ),
        ]),
      );
}

/// Dernière page : on se lance, avec des raccourcis vers les premières actions utiles.
class _Finish extends StatelessWidget {
  const _Finish({required this.play, required this.wide, required this.shortcuts, required this.onPick});

  final bool play, wide;
  final List<(IconData, String, String)> shortcuts;
  final void Function([String? target]) onPick;

  @override
  Widget build(BuildContext context) => Center(
        child: SingleChildScrollView(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 560),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              _Entrance(
                play: play,
                child: Container(
                  width: 92,
                  height: 92,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: _gold,
                    boxShadow: [BoxShadow(color: _gold.withValues(alpha: .45), blurRadius: 40)],
                  ),
                  child: const Icon(Icons.check_rounded, size: 52, color: _navy),
                ),
              ),
              const SizedBox(height: 20),
              _Entrance(
                play: play,
                delay: 80,
                child: Text(tr('Vous êtes prêt !'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800)),
              ),
              const SizedBox(height: 10),
              _Entrance(
                play: play,
                delay: 140,
                child: Text(
                  tr('Par quoi voulez-vous commencer ? Vous pourrez revoir ce guide depuis le menu de votre compte.'),
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white.withValues(alpha: .75), fontSize: 15, height: 1.45),
                ),
              ),
              const SizedBox(height: 22),
              for (final (i, sc) in shortcuts.indexed)
                _Entrance(
                  play: play,
                  delay: 200 + 70 * i,
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Material(
                      color: Colors.white.withValues(alpha: .07),
                      borderRadius: BorderRadius.circular(14),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(14),
                        onTap: () => onPick(sc.$3),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                          child: Row(children: [
                            Icon(sc.$1, color: _gold),
                            const SizedBox(width: 14),
                            Expanded(child: Text(tr(sc.$2), style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700))),
                            Icon(Icons.arrow_forward, size: 18, color: Colors.white.withValues(alpha: .6)),
                          ]),
                        ),
                      ),
                    ),
                  ),
                ),
            ]),
          ),
        ),
      );
}
