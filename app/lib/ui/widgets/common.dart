import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client.dart';
import '../theme.dart';
import 'motion.dart';

export 'motion.dart';
import '../../i18n/i18n.dart';

// ---------------------------------------------------------------------------
// Formats
// ---------------------------------------------------------------------------

/// Devises sans centimes (même liste que le serveur).
const zeroDecimalCurrencies = {'XOF', 'XAF', 'GNF', 'KMF', 'RWF', 'BIF', 'DJF', 'JPY', 'KRW', 'VND', 'CLP', 'PYG', 'UGX'};

/// Nombre de décimales de la devise de la société courante (mis à jour par Session).
int currencyDecimals = 0;

int decimalsFor(String? currency) => zeroDecimalCurrencies.contains((currency ?? '').toUpperCase()) ? 0 : 2;

String money(num? v, [String currency = 'XOF']) {
  final f = NumberFormat.decimalPattern('fr');
  final noDecimals = decimalsFor(currency) == 0;
  final value = noDecimals ? (v ?? 0).round() : (v ?? 0);
  final s = noDecimals ? f.format(value) : NumberFormat('#,##0.00', 'fr').format(value);
  final sym = currency == 'XOF' || currency == 'XAF' ? 'F CFA' : currency;
  return '$s $sym';
}

String number(num? v, {int decimals = 0}) =>
    NumberFormat.decimalPatternDigits(locale: 'fr', decimalDigits: decimals).format(v ?? 0);

String qty(num? v) {
  final d = (v ?? 0).toDouble();
  return d == d.roundToDouble() ? number(d) : number(d, decimals: 2);
}

String dateFr(dynamic v) {
  if (v == null || v.toString().isEmpty) return '—';
  final d = DateTime.tryParse(v.toString());
  return d == null ? v.toString() : DateFormat('dd/MM/yyyy', I18n.dateLocale).format(d.toLocal());
}

String dateTimeFr(dynamic v) {
  if (v == null) return '—';
  final d = DateTime.tryParse(v.toString());
  return d == null ? v.toString() : DateFormat('dd/MM/yyyy HH:mm', I18n.dateLocale).format(d.toLocal());
}

String isoDate(DateTime d) => DateFormat('yyyy-MM-dd').format(d);

double toDouble(dynamic v) => v is num ? v.toDouble() : double.tryParse('${v ?? ''}'.replaceAll(' ', '').replaceAll(',', '.')) ?? 0;

const materialIcons = <String, IconData>{
  'move_down': Icons.move_down,
  'table_chart': Icons.table_chart_outlined,
  'apps': Icons.apps,
  'receipt': Icons.receipt_long,
  'shopping_cart': Icons.shopping_cart_outlined,
  'inventory': Icons.inventory_2_outlined,
  'inventory_2': Icons.inventory_2_outlined,
  'point_of_sale': Icons.point_of_sale,
  'storefront': Icons.storefront_outlined,
  'autorenew': Icons.autorenew,
  'hourglass_top': Icons.hourglass_top,
  'work_history': Icons.work_history_outlined,
  'view_timeline': Icons.view_timeline_outlined,
  'fact_check': Icons.fact_check_outlined,
  'summarize': Icons.summarize_outlined,
  'rate_review': Icons.rate_review_outlined,
  'dns': Icons.dns_outlined,
  'published_with_changes': Icons.published_with_changes,
  'key': Icons.key_outlined,
  'handshake': Icons.handshake_outlined,
  'campaign': Icons.campaign_outlined,
  'support_agent': Icons.support_agent,
  'account_balance': Icons.account_balance_outlined,
  'savings': Icons.savings_outlined,
  'domain': Icons.domain,
  'request_quote': Icons.request_quote_outlined,
  'badge': Icons.badge_outlined,
  'payments': Icons.payments_outlined,
  'person_search': Icons.person_search_outlined,
  'task_alt': Icons.task_alt,
  'local_shipping': Icons.local_shipping_outlined,
  'directions_car': Icons.directions_car_outlined,
  'precision_manufacturing': Icons.precision_manufacturing_outlined,
  'build': Icons.build_outlined,
  'verified': Icons.verified_outlined,
  'calendar_month': Icons.calendar_month_outlined,
  'folder': Icons.folder_outlined,
  'insights': Icons.insights,
  'people': Icons.people_outline,
  'wifi': Icons.wifi,
  'trending_up': Icons.trending_up,
  'hourglass_bottom': Icons.hourglass_bottom,
  'warning': Icons.warning_amber_rounded,
  'production_quantity_limits': Icons.production_quantity_limits,
  'account_balance_wallet': Icons.account_balance_wallet_outlined,
  'confirmation_number': Icons.confirmation_number_outlined,
  'sms': Icons.sms_outlined,
  'chat': Icons.chat_outlined,
  'mark_email_read': Icons.mark_email_read_outlined,
  'web': Icons.web,
  'quiz': Icons.quiz_outlined,
  'event_available': Icons.event_available_outlined,
  'loyalty': Icons.loyalty_outlined,
  'restaurant': Icons.restaurant,
  'table_restaurant': Icons.table_restaurant_outlined,
  'soup_kitchen': Icons.soup_kitchen_outlined,
  'schedule': Icons.schedule,
  'qr_code_2': Icons.qr_code_2,
  'query_stats': Icons.query_stats,
  'engineering': Icons.engineering_outlined,
  'gavel': Icons.gavel,
  'draw': Icons.draw_outlined,
  'sync_problem': Icons.sync_problem,
  'pie_chart': Icons.pie_chart_outline,
  'notifications_active': Icons.notifications_active_outlined,
  'currency_exchange': Icons.currency_exchange,
  'event_busy': Icons.event_busy,
  'upload_file': Icons.upload_file,
  'auto_awesome': Icons.auto_awesome_outlined,
  'receipt_long': Icons.receipt_long,
  'beach_access': Icons.beach_access_outlined,
  'fingerprint': Icons.fingerprint,
  'person_off': Icons.person_off_outlined,
  'event_note': Icons.event_note,
  'alarm': Icons.alarm,
  'mail': Icons.mail_outline,
  'send': Icons.send_outlined,
  'timer': Icons.timer_outlined,
  'leaderboard': Icons.leaderboard_outlined,
  'approval': Icons.approval_outlined,
  'mark_email_unread': Icons.mark_email_unread_outlined,
  'assignment': Icons.assignment_outlined,
};

IconData iconFor(String? name) => materialIcons[name] ?? Icons.extension_outlined;

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------

void toast(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      content: Text(tr(message)),
      behavior: SnackBarBehavior.floating,
      backgroundColor: error ? YColors.danger : null,
      width: MediaQuery.of(context).size.width > 700 ? 480 : null,
    ));
}

String errorText(Object e) => tr(e is ApiException ? e.detail : e.toString());

Future<bool> confirm(BuildContext context, String title, String message, {String ok = 'Confirmer', bool danger = false}) async {
  final r = await showDialog<bool>(
    context: context,
    builder: (c) => YAlertDialog(
      icon: Icon(danger ? Icons.warning_amber_rounded : Icons.help_outline_rounded, color: danger ? YColors.danger : null),
      title: Text(tr(title)),
      content: Text(tr(message)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
        FilledButton(
          style: danger ? FilledButton.styleFrom(backgroundColor: YColors.danger) : null,
          onPressed: () => Navigator.pop(c, true),
          child: Text(tr(ok)),
        ),
      ],
    ),
  );
  return r == true;
}

// ---------------------------------------------------------------------------
// Composants
// ---------------------------------------------------------------------------

/// Contexte de l'écran affiché (fourni par le menu) : icône, groupe et couleur d'accent pour les en-têtes.
class NavScope extends InheritedWidget {
  const NavScope({super.key, required this.icon, required this.group, required this.groupKey, required super.child});

  final IconData icon;
  final String group;
  final String groupKey;

  static NavScope? of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<NavScope>();

  static const accents = {
    'pilotage': Color(0xFF4F46E5), 'ventes': Color(0xFF1F6FEB), 'caisse': Color(0xFFEA580C), 'achats': Color(0xFF0D9488), 'operations': Color(0xFFD97706),
    'finance': Color(0xFF16A34A), 'rh': Color(0xFFDB2777), 'digital': Color(0xFF9333EA), 'metiers': Color(0xFF0284C7), 'collab': Color(0xFF0891B2),
    'admin': Color(0xFF475569), 'superadmin': Color(0xFF0B1B3A),
  };

  Color get accent => accents[groupKey] ?? YColors.blue;

  @override
  bool updateShouldNotify(NavScope old) => old.icon != icon || old.group != group;
}

/// En-tête de page : pastille d'icône aux couleurs de l'espace, surtitre, titre, sous-titre et actions.
class PageHeader extends StatelessWidget {
  const PageHeader({super.key, required this.title, this.subtitle, this.actions = const [], this.icon});

  final String title;
  final String? subtitle;
  final List<Widget> actions;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final narrow = MediaQuery.of(context).size.width < 700;
    final nav = NavScope.of(context);
    final ic = icon ?? nav?.icon;
    final accent = nav?.accent ?? YColors.blue;
    final theme = Theme.of(context);
    // Fil d'Ariane « Espace / Écran » au-dessus du titre
    final crumb = nav != null && nav.group.isNotEmpty && nav.group != title
        ? Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: Text.rich(
              TextSpan(children: [
                TextSpan(text: tr(nav.group), style: TextStyle(color: theme.hintColor)),
                TextSpan(text: '   /   ', style: TextStyle(color: theme.hintColor.withValues(alpha: .6))),
                TextSpan(text: tr(title), style: TextStyle(color: theme.textTheme.titleMedium?.color, fontWeight: FontWeight.w600)),
              ]),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 14),
            ),
          )
        : null;
    final titleBlock = Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
      if (ic != null && !narrow) ...[
        Container(
          width: 52,
          height: 52,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            gradient: LinearGradient(colors: [Color.lerp(accent, Colors.white, .22)!, accent], begin: Alignment.topLeft, end: Alignment.bottomRight),
            boxShadow: [BoxShadow(color: accent.withValues(alpha: .25), blurRadius: 16, offset: const Offset(0, 6))],
          ),
          child: Icon(ic, color: Colors.white, size: 25),
        ),
        const SizedBox(width: 16),
      ],
      Flexible(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(tr(title), style: theme.textTheme.headlineSmall?.copyWith(fontSize: narrow ? 23 : 28, letterSpacing: -.7, height: 1.15)),
          if (subtitle != null)
            Padding(
              padding: const EdgeInsets.only(top: 3),
              child: Text(tr(subtitle!), style: TextStyle(color: theme.hintColor, fontSize: 14.5)),
            ),
        ]),
      ),
    ]);
    return AnimatedFadeIn(
      offset: 8,
      child: Container(
        width: double.infinity,
        padding: EdgeInsets.fromLTRB(narrow ? 16 : 28, narrow ? 14 : 20, narrow ? 16 : 28, 16),
        child: narrow
            ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                ?crumb,
                titleBlock,
                if (actions.isNotEmpty) ...[const SizedBox(height: 12), Wrap(spacing: 8, runSpacing: 8, children: actions)],
              ])
            : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                ?crumb,
                Row(children: [Expanded(child: titleBlock), ...actions.expand((a) => [const SizedBox(width: 10), a])]),
              ]),
      ),
    );
  }
}

/// Icône d'indicateur déduite de son libellé (pour les indicateurs qui n'en précisent pas).
IconData kpiIcon(String label) {
  final l = label.toLowerCase();
  const map = <List<String>, IconData>{
    ['retard', 'échu', 'alerte', 'rupture', 'risque', 'litige', 'non-conform']: Icons.warning_amber_rounded,
    ['échéance', 'délai', 'jours', 'sous 7', 'à venir', 'prochain']: Icons.event_outlined,
    ['heure', 'temps', 'durée']: Icons.schedule,
    ['trésorerie', 'banque', 'solde', 'caisse']: Icons.account_balance_wallet_outlined,
    ['encaiss', 'payé', 'reçu', 'recette']: Icons.savings_outlined,
    ['dépense', 'charge', 'coût', 'achat', 'dette']: Icons.trending_down,
    ['marge', 'résultat', 'bénéfice', 'excédent']: Icons.trending_up,
    ['chiffre', 'revenu', 'ca ', 'ca\u00a0', 'ventes', 'facturé', 'montant', 'total', 'valeur', 'budget', 'mrr']: Icons.payments_outlined,
    ['stock', 'article', 'produit', 'lot']: Icons.inventory_2_outlined,
    ['client', 'contact', 'salarié', 'membre', 'participant', 'utilisateur', 'candidat', 'personne']: Icons.people_outline,
    ['taux', '%', 'conversion', 'satisfaction', 'note']: Icons.percent,
    ['actif', 'en cours', 'ouvert']: Icons.play_circle_outline,
    ['terminé', 'clôtur', 'validé', 'livré', 'résolu']: Icons.task_alt,
    ['contrat', 'document', 'facture', 'devis', 'commande']: Icons.description_outlined,
  };
  for (final e in map.entries) {
    if (e.key.any(l.contains)) return e.value;
  }
  return Icons.insights_outlined;
}

/// Indicateur chiffré (carte) : libellé, valeur, détail, icône colorée.
class StatTile extends StatelessWidget {
  const StatTile({super.key, required this.label, required this.value, this.detail, this.icon, this.color = YColors.blue, this.valueColor, this.onTap, this.autoIcon = true});

  final String label;
  final String value;
  final String? detail;
  final IconData? icon;
  final Color color;
  final Color? valueColor;
  final VoidCallback? onTap;

  /// Sans icône précisée : une icône est choisie d'après le libellé.
  final bool autoIcon;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final icon = this.icon ?? (autoIcon ? kpiIcon(label) : null);
    final d = (detail ?? '').trim();
    // Variation (« +12 % », « ↑ … », « -3 % ») : pastille verte ou rouge, comme sur le tableau de bord
    final up = RegExp(r'^(\+|↑|▲)').hasMatch(d);
    final down = RegExp(r'^(-\s?\d|−|↓|▼)').hasMatch(d);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
          child: Row(children: [
            if (icon != null) ...[
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(color: color.withValues(alpha: .11), borderRadius: BorderRadius.circular(13)),
                child: Icon(icon, color: color, size: 24),
              ),
              const SizedBox(width: 14),
            ],
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                Text(tr(label), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 13.5, fontWeight: FontWeight.w500)),
                const SizedBox(height: 3),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(tr(value), style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, letterSpacing: -.6, color: valueColor, fontFeatures: const [FontFeature.tabularFigures()])),
                ),
                if (up || down)
                  Container(
                    margin: const EdgeInsets.only(top: 4),
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(color: (up ? YColors.success : YColors.danger).withValues(alpha: .11), borderRadius: BorderRadius.circular(8)),
                    child: Text(tr(d), maxLines: 1, overflow: TextOverflow.ellipsis,
                        style: TextStyle(color: Color.lerp(up ? YColors.success : YColors.danger, Colors.black, .15), fontSize: 12, fontWeight: FontWeight.w600)),
                  )
                else
                  // Une ligne réservée même sans détail : toutes les cartes d'une rangée ont la même hauteur
                  Text(tr(d.isEmpty ? ' ' : d), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12.5)),
              ]),
            ),
          ]),
        ),
      ),
    );
  }
}

/// Rangée d'indicateurs qui passe à la ligne sur petit écran.
class StatStrip extends StatelessWidget {
  const StatStrip({super.key, required this.tiles, this.padding = const EdgeInsets.fromLTRB(24, 0, 24, 14)});

  final List<Widget> tiles;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => Padding(
        padding: MediaQuery.of(context).size.width < 700 ? padding.copyWith(left: 16, right: 16) : padding,
        child: LayoutBuilder(builder: (context, c) {
          // Téléphone : les indicateurs défilent en largeur au lieu d'occuper tout l'écran, le contenu reste visible.
          if (c.maxWidth < 560 && tiles.length > 1) {
            final w = (c.maxWidth * .74).clamp(200.0, 320.0);
            return SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              clipBehavior: Clip.none,
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                for (var i = 0; i < tiles.length; i++) Padding(padding: EdgeInsets.only(right: i == tiles.length - 1 ? 0 : 10), child: SizedBox(width: w, child: tiles[i])),
              ]),
            );
          }
          final fit = ((c.maxWidth + 12) / 222).floor().clamp(1, tiles.length);
          final cols = fit >= tiles.length ? tiles.length : (fit >= 2 && tiles.length == 4 ? 2 : fit);
          final w = (c.maxWidth - (cols - 1) * 12) / cols;
          return Wrap(spacing: 12, runSpacing: 12, children: [for (final t in tiles) SizedBox(width: w, child: t)]);
        }),
      );
}

/// Rangée d'indicateurs qui occupe toute la largeur : colonnes égales (au moins ~230 px chacune),
/// une seule colonne sur téléphone. Les tuiles enveloppées dans [KpiBox] prennent la largeur calculée.
class KpiWrap extends StatelessWidget {
  const KpiWrap({super.key, required this.children, this.spacing = 12, this.minTile = 230});

  final List<Widget> children;
  final double spacing;
  final double minTile;

  @override
  Widget build(BuildContext context) => LayoutBuilder(builder: (context, c) {
        final n = children.length;
        if (n == 0 || !c.hasBoundedWidth) return Wrap(spacing: spacing, runSpacing: spacing, children: children);
        // Téléphone : les indicateurs défilent en largeur au lieu d'empiler un écran entier de cartes.
        if (c.maxWidth < 560 && n > 2) {
          final w = (c.maxWidth * .72).clamp(190.0, 300.0).floorToDouble();
          return _KpiWidth(
            width: w,
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              clipBehavior: Clip.none,
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                for (var i = 0; i < n; i++)
                  Padding(padding: EdgeInsets.only(right: i == n - 1 ? 0 : spacing), child: ConstrainedBox(constraints: BoxConstraints(maxWidth: w), child: children[i])),
              ]),
            ),
          );
        }
        var cols = ((c.maxWidth + spacing) / (minTile + spacing)).floor().clamp(1, n);
        if (cols < n && n == 4 && cols == 3) cols = 2; // 2 × 2 plutôt que 3 + 1
        if (cols < n && cols > 1 && n % cols != 0 && (n / ((n / cols).ceil())).ceil() < cols) cols = (n / ((n / cols).ceil())).ceil(); // lignes équilibrées
        final w = ((c.maxWidth - (cols - 1) * spacing) / cols).floorToDouble();
        return _KpiWidth(width: w, child: Wrap(spacing: spacing, runSpacing: spacing, children: children));
      });
}

class _KpiWidth extends InheritedWidget {
  const _KpiWidth({required this.width, required super.child});
  final double width;
  @override
  bool updateShouldNotify(_KpiWidth old) => old.width != width;
}

/// Tuile d'indicateur : largeur donnée par [KpiWrap] si elle y est, sinon [width].
class KpiBox extends StatelessWidget {
  const KpiBox({super.key, this.width = 230, required this.child});
  final double width;
  final Widget child;

  @override
  Widget build(BuildContext context) => SizedBox(width: context.dependOnInheritedWidgetOfExactType<_KpiWidth>()?.width ?? width, child: child);
}

/// Encadré d'information « à faire » (ambre clair bordé), commun à tous les écrans.
Color noticeFill(BuildContext context) => Theme.of(context).brightness == Brightness.dark ? const Color(0xFF3A2E12) : const Color(0xFFFFF8E7);
ShapeBorder noticeShape(BuildContext context) => RoundedRectangleBorder(
    borderRadius: BorderRadius.circular(14),
    side: BorderSide(color: Theme.of(context).brightness == Brightness.dark ? const Color(0xFF6B5320) : const Color(0xFFF5D899)));

/// Pastille d'initiales colorée (clients, contacts, salariés).
class InitialsAvatar extends StatelessWidget {
  const InitialsAvatar(this.name, {super.key, this.size = 32});

  final String name;
  final double size;

  static const _palette = [Color(0xFF1F6FEB), Color(0xFF16A34A), Color(0xFFEA580C), Color(0xFF9333EA), Color(0xFF0891B2), Color(0xFFDB2777), Color(0xFFD97706), Color(0xFF4F46E5)];

  @override
  Widget build(BuildContext context) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty && RegExp(r'[A-Za-zÀ-ÿ0-9]').hasMatch(p[0])).toList();
    final ini = parts.isEmpty ? '?' : (parts.length == 1 ? parts.first.substring(0, 1) : parts.first.substring(0, 1) + parts[1].substring(0, 1)).toUpperCase();
    final c = _palette[name.codeUnits.fold<int>(0, (a, b) => a + b) % _palette.length];
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: c.withValues(alpha: .12), shape: BoxShape.circle),
      child: Text(tr(ini), style: TextStyle(color: c, fontWeight: FontWeight.w700, fontSize: size * .38)),
    );
  }
}

class SearchField extends StatelessWidget {
  const SearchField({super.key, required this.onChanged, this.hint = 'Rechercher…'});

  final ValueChanged<String> onChanged;
  final String hint;

  @override
  Widget build(BuildContext context) => TextField(
        onChanged: onChanged,
        decoration: InputDecoration(prefixIcon: const Icon(Icons.search, size: 20), hintText: trn(hint), contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11)),
      );
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, this.message, this.action});

  final IconData icon;
  final String title;
  final String? message;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: AnimatedFadeIn(child: Column(mainAxisSize: MainAxisSize.min, children: [
            TweenAnimationBuilder<double>(
              tween: Tween(begin: .6, end: 1),
              duration: const Duration(milliseconds: 520),
              curve: Curves.elasticOut,
              builder: (context, v, child) => Transform.scale(scale: v, child: child),
              child: Container(
                width: 84,
                height: 84,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(colors: [YColors.blue.withValues(alpha: .14), YColors.yellow.withValues(alpha: .18)], begin: Alignment.topLeft, end: Alignment.bottomRight),
                ),
                child: Icon(icon, size: 40, color: YColors.blue),
              ),
            ),
            const SizedBox(height: 14),
            Text(tr(title), style: Theme.of(context).textTheme.titleMedium, textAlign: TextAlign.center),
            if (message != null) ...[
              const SizedBox(height: 6),
              Text(tr(message!), textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).hintColor)),
            ],
            if (action != null) ...[const SizedBox(height: 16), action!],
          ])),
        ),
      );
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, this.color = YColors.muted, this.icon});

  final String label;
  final Color color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => AnimatedContainer(
        duration: const Duration(milliseconds: 250),
        padding: EdgeInsets.fromLTRB(icon != null ? 8 : 10, 4, 10, 4),
        decoration: BoxDecoration(color: color.withValues(alpha: Theme.of(context).brightness == Brightness.dark ? .22 : .11), borderRadius: BorderRadius.circular(8)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 14, color: color), const SizedBox(width: 6)],
          Flexible(child: Text(tr(label), overflow: TextOverflow.ellipsis, style: TextStyle(color: Color.lerp(color, Theme.of(context).brightness == Brightness.dark ? Colors.white : Colors.black, Theme.of(context).brightness == Brightness.dark ? .35 : .18), fontSize: 12.5, fontWeight: FontWeight.w600))),
        ]),
      );
}

/// Bandeau affiché quand une page a besoin du serveur.
class OnlineOnlyNotice extends StatelessWidget {
  const OnlineOnlyNotice({super.key, required this.error, required this.onRetry});

  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final network = error is ApiException && (error as ApiException).isNetwork;
    return EmptyState(
      icon: network ? Icons.cloud_off : Icons.error_outline,
      title: network ? 'Cette page nécessite une connexion' : 'Impossible de charger',
      message: network ? 'Les ventes, le stock et les contacts restent utilisables hors ligne.' : errorText(error),
      action: OutlinedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: Text(tr('Réessayer'))),
    );
  }
}

/// Section en carte avec titre.
class SectionCard extends StatelessWidget {
  const SectionCard({super.key, this.title, required this.child, this.trailing, this.padding = const EdgeInsets.all(18)});

  final String? title;
  final Widget child;
  final Widget? trailing;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => AnimatedFadeIn(
        child: Card(
        child: AnimatedSize(
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOutCubic,
          alignment: Alignment.topCenter,
          child: Padding(
          padding: padding,
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (title != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Row(children: [
                  Expanded(child: Text(tr(title!), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15.5, letterSpacing: -.2))),
                  ?trailing,
                ]),
              ),
            child,
          ]),
        ),
        ),
        ),
      );
}

/// Liste responsive : tableau sur grand écran, cartes empilées sur mobile.
class ResponsiveList<T> extends StatelessWidget {
  const ResponsiveList({
    super.key,
    required this.items,
    required this.columns,
    required this.cells,
    required this.mobileTile,
    this.onTap,
  });

  final List<T> items;
  final List<String> columns;
  final List<Widget> Function(T item) cells;
  final Widget Function(T item) mobileTile;
  final void Function(T item)? onTap;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, c) {
      if (c.maxWidth < 760) {
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
          itemCount: items.length,
          separatorBuilder: (_, _) => const SizedBox(height: 8),
          itemBuilder: (_, i) => AnimatedFadeIn(
            index: i,
            child: Card(
              child: InkWell(
                borderRadius: BorderRadius.circular(14),
                onTap: onTap == null ? null : () => onTap!(items[i]),
                child: Padding(padding: const EdgeInsets.all(12), child: mobileTile(items[i])),
              ),
            ),
          ),
        );
      }
      return SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: AnimatedFadeIn(child: Card(
          clipBehavior: Clip.antiAlias,
          // Toute la largeur, et défilement horizontal si les colonnes ne tiennent pas (au lieu de couper la dernière)
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: ConstrainedBox(
            constraints: BoxConstraints(minWidth: c.maxWidth - 48),
            child: DataTable(
              showCheckboxColumn: false,
              columnSpacing: 24,
              horizontalMargin: 20,
              columns: [for (final col in columns) DataColumn(label: Text(tr(col)))],
              rows: [
                for (final it in items)
                  DataRow(onSelectChanged: onTap == null ? null : (_) => onTap!(it), cells: [for (final w in cells(it)) DataCell(ConstrainedBox(constraints: const BoxConstraints(maxWidth: 340), child: w))]),
              ],
            ),
          ),
          ),
        )),
      );
    });
  }
}

/// Champ de formulaire libellé.
class Field extends StatelessWidget {
  const Field({super.key, required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr(label), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          child,
        ]),
      );
}

/// Icône d'en-tête de fenêtre choisie d'après le titre (supprimer, imprimer, payer, réglages…).
(IconData, bool) dialogIcon(String title) {
  final t = title.toLowerCase();
  bool has(List<String> k) => k.any(t.contains);
  if (has(['supprimer', 'retirer', 'effacer', 'réinitialiser', 'annuler la', 'annuler le', 'annuler l', 'refuser', 'rejeter', 'désactiver', 'bloquer'])) return (Icons.warning_amber_rounded, true);
  if (has(['réglage', 'paramètre', 'configur'])) return (Icons.tune_rounded, false);
  if (has(['imprim', 'pdf'])) return (Icons.print_outlined, false);
  if (has(['paie', 'paiement', 'payer', 'encaiss', 'règlement', 'caisse', 'versement'])) return (Icons.payments_outlined, false);
  if (has(['envoyer', 'e-mail', 'mail', 'message', 'sms', 'whatsapp', 'relance'])) return (Icons.send_outlined, false);
  if (has(['signature', 'signer'])) return (Icons.draw_outlined, false);
  if (has(['salle', 'véhicule', 'matériel', 'ressource'])) return (Icons.meeting_room_outlined, false);
  if (has(['réunion', 'visio', 'webinaire', 'yomeet'])) return (Icons.videocam_outlined, false);
  if (has(['événement', 'rendez-vous', 'date', 'planning', 'agenda', 'congé', 'férié'])) return (Icons.event_outlined, false);
  if (has(['code', 'pin', 'mot de passe', 'accès', 'clé', 'sécurité'])) return (Icons.lock_outline_rounded, false);
  if (has(['export', 'télécharg'])) return (Icons.download_outlined, false);
  if (has(['import'])) return (Icons.upload_file_outlined, false);
  if (has(['valider', 'confirmer', 'clôtur', 'approuv'])) return (Icons.task_alt_rounded, false);
  if (has(['stock', 'inventaire', 'article', 'produit'])) return (Icons.inventory_2_outlined, false);
  if (has(['client', 'fournisseur', 'contact', 'salarié', 'utilisateur', 'membre', 'participant'])) return (Icons.person_outline_rounded, false);
  if (has(['nouveau', 'nouvelle', 'ajouter', 'créer'])) return (Icons.add_circle_outline_rounded, false);
  if (has(['modifier', 'renommer', 'éditer'])) return (Icons.edit_outlined, false);
  return (Icons.auto_awesome_outlined, false);
}

String _titleText(Widget? w) => w is Text ? (w.data ?? w.textSpan?.toPlainText() ?? '') : '';

/// Pastille d'icône des en-têtes de fenêtres.
class DialogIconBadge extends StatelessWidget {
  const DialogIconBadge({super.key, required this.icon, this.color, this.size = 42});

  final Widget icon;
  final Color? color;
  final double size;

  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).colorScheme.primary;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [c.withValues(alpha: .16), c.withValues(alpha: .06)], begin: Alignment.topLeft, end: Alignment.bottomRight),
        borderRadius: BorderRadius.circular(13),
        border: Border.all(color: c.withValues(alpha: .18)),
      ),
      child: IconTheme(data: IconThemeData(color: c, size: size * .5), child: Center(child: icon)),
    );
  }
}

/// En-tête commun des fenêtres : pastille, titre, sous-titre facultatif et bouton de fermeture.
class DialogHeader extends StatelessWidget {
  const DialogHeader({super.key, required this.title, this.subtitle, this.icon, this.color, this.onClose, this.padding = const EdgeInsets.fromLTRB(24, 22, 14, 14)});

  final Widget title;
  final String? subtitle;
  final Widget? icon;
  final Color? color;
  final VoidCallback? onClose;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final auto = dialogIcon(_titleText(title));
    final iconColor = color ?? (icon is Icon ? (icon as Icon).color : null) ?? (auto.$2 ? YColors.danger : theme.colorScheme.primary);
    return Padding(
      padding: padding,
      child: Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
        DialogIconBadge(icon: icon is Icon ? Icon((icon as Icon).icon) : (icon ?? Icon(auto.$1)), color: iconColor),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            DefaultTextStyle.merge(
              style: theme.dialogTheme.titleTextStyle?.copyWith(fontSize: 18.5) ?? const TextStyle(fontSize: 18.5, fontWeight: FontWeight.w800),
              child: title,
            ),
            if (subtitle != null && subtitle!.isNotEmpty)
              Padding(padding: const EdgeInsets.only(top: 2), child: Text(tr(subtitle!), style: TextStyle(color: theme.hintColor, fontSize: 13))),
          ]),
        ),
        if (onClose != null)
          IconButton(
            tooltip: tr('Fermer'),
            onPressed: onClose,
            style: IconButton.styleFrom(backgroundColor: theme.colorScheme.onSurface.withValues(alpha: .05)),
            icon: const Icon(Icons.close_rounded, size: 20),
          ),
      ]),
    );
  }
}

/// Fenêtre de dialogue moderne, remplaçant direct d'AlertDialog (même paramètres) :
/// en-tête avec pastille d'icône et fermeture, contenu aéré, pied teinté pour les actions.
class YAlertDialog extends StatelessWidget {
  const YAlertDialog({super.key, this.title, this.content, this.actions, this.icon, this.titlePadding, this.actionsOverflowButtonSpacing, this.subtitle});

  final Widget? title;
  final Widget? content;
  final List<Widget>? actions;
  final Widget? icon;
  final EdgeInsetsGeometry? titlePadding;
  final double? actionsOverflowButtonSpacing;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final size = MediaQuery.of(context).size;
    return Dialog(
      insetPadding: EdgeInsets.symmetric(horizontal: size.width < 600 ? 14 : 40, vertical: 24),
      clipBehavior: Clip.antiAlias,
      child: ConstrainedBox(
        constraints: BoxConstraints(minWidth: 300, maxWidth: size.width < 600 ? size.width : 720),
        child: IntrinsicWidth(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (title != null) DialogHeader(title: title!, subtitle: subtitle, icon: icon, onClose: () => Navigator.of(context).maybePop()),
            if (content != null)
              Flexible(
                child: Padding(
                  padding: EdgeInsets.fromLTRB(24, title == null ? 24 : 4, 24, 20),
                  child: DefaultTextStyle.merge(style: theme.dialogTheme.contentTextStyle, child: content!),
                ),
              ),
            if (actions != null && actions!.isNotEmpty)
              Container(
                padding: const EdgeInsets.fromLTRB(20, 14, 20, 16),
                decoration: BoxDecoration(
                  color: dark ? Colors.white.withValues(alpha: .03) : const Color(0xFFF7F9FC),
                  border: Border(top: BorderSide(color: dark ? Colors.white10 : const Color(0xFFEDF1F7))),
                ),
                child: OverflowBar(alignment: MainAxisAlignment.end, spacing: 10, overflowSpacing: 8, overflowAlignment: OverflowBarAlignment.end, children: actions!),
              ),
          ]),
        ),
      ),
    );
  }
}

/// Boîte de dialogue de formulaire responsive (plein écran sur mobile).
Future<T?> showFormDialog<T>(BuildContext context, {required String title, required Widget Function(BuildContext) builder, double maxWidth = 560, String? subtitle, IconData? icon}) {
  final narrow = MediaQuery.of(context).size.width < 700;
  if (narrow) {
    return Navigator.of(context).push<T>(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (c) => Scaffold(appBar: AppBar(title: Text(tr(title))), body: builder(c)),
    ));
  }
  return showSidePanel<T>(context, title: title, subtitle: subtitle, icon: icon, width: maxWidth, builder: builder);
}

/// Panneau latéral droit (formulaires de création et de modification) : pleine hauteur, glisse depuis la droite.
Future<T?> showSidePanel<T>(BuildContext context, {required String title, required Widget Function(BuildContext) builder, double width = 520, String? subtitle, IconData? icon}) {
  final theme = Theme.of(context);
  final dark = theme.brightness == Brightness.dark;
  return showGeneralDialog<T>(
    context: context,
    barrierDismissible: false, // un clic à côté ne fait pas perdre la saisie
    barrierLabel: tr('Fermer'),
    barrierColor: (dark ? Colors.black : YColors.night).withValues(alpha: .28),
    transitionDuration: const Duration(milliseconds: 260),
    pageBuilder: (c, _, _) => Align(
      alignment: Alignment.centerRight,
      child: Material(
        color: theme.dialogTheme.backgroundColor ?? theme.colorScheme.surface,
        elevation: 16,
        shadowColor: Colors.black.withValues(alpha: .3),
        clipBehavior: Clip.antiAlias,
        borderRadius: const BorderRadius.horizontal(left: Radius.circular(20)),
        child: SizedBox(
          width: (width < 480 ? 480.0 : width).clamp(0, MediaQuery.of(c).size.width - 40).toDouble(),
          height: double.infinity,
          child: SafeArea(
            left: false,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              DialogHeader(title: Text(tr(title)), subtitle: subtitle, icon: icon == null ? null : Icon(icon), onClose: () => Navigator.pop(c), padding: const EdgeInsets.fromLTRB(24, 22, 16, 16)),
              Divider(height: 1, color: dark ? Colors.white10 : const Color(0xFFEDF1F7)),
              Expanded(child: Align(alignment: Alignment.topCenter, child: builder(c))),
            ]),
          ),
        ),
      ),
    ),
    transitionBuilder: (c, a, _, child) {
      final curve = CurvedAnimation(parent: a, curve: Curves.easeOutCubic, reverseCurve: Curves.easeInCubic);
      return SlideTransition(position: Tween(begin: const Offset(1, 0), end: Offset.zero).animate(curve), child: child);
    },
  );
}


/// Logo Yomara (marque) : rendu identique sur toutes les plateformes.
class YomaraMark extends StatelessWidget {
  const YomaraMark({super.key, this.size = 34, this.glow = false});

  final double size;
  final bool glow;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(size * .23),
          boxShadow: [BoxShadow(color: const Color(0xFFFFC531).withValues(alpha: glow ? .45 : .3), blurRadius: glow ? size * .45 : size * .3, offset: Offset(0, glow ? 0 : size * .1))],
        ),
        child: Image.asset('assets/brand/mark.png', width: size, height: size, filterQuality: FilterQuality.high),
      );
}
