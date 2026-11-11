import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'agences_screen.dart';

import '../../core/config.dart';
import '../../core/vat.dart';
import 'document_editor.dart' show paymentMethods;
import 'doc_templates_screen.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../../core/firebase_setup.dart';
import '../../core/json.dart';
import '../widgets/common.dart';
import '../widgets/delete_account.dart';
import '../widgets/payment_terms.dart';
import 'company_payments_screen.dart';
import 'messaging_settings_screen.dart';
import 'team/functions_screen.dart';
import 'team/user_editor.dart';
import '../../i18n/i18n.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final sync = context.watch<SyncEngine>();
    final width = MediaQuery.of(context).size.width;
    final pad = width < 700 ? 16.0 : 24.0;
    final manage = session.can('core.settings.manage');

    void open(Widget w) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => w));
    final tiles = <Widget>[
      if (session.hasModule('agences') && session.can('agences.manage'))
        _SettingTile(
          icon: Icons.store_mall_directory_outlined,
          color: const Color(0xFF7C3AED),
          title: 'Agences',
          text: session.hasBranches
              ? '${session.branches.length} agence(s) : ${session.branches.map((b) => b['name']).join(', ')}'
              : 'Agences, succursales ou points de vente : dépôt, caisse et équipe de chacune.',
          onTap: () => open(const AgencesScreen(standalone: true, initialTab: 1)),
        ),
      if (manage) ...[
        _SettingTile(
          icon: Icons.style_outlined,
          color: const Color(0xFFB8935A),
          title: 'Modèles de documents',
          text: 'Apparence des factures, proformas, devis et offres : bibliothèque de modèles, premium et studio par glisser-déposer.',
          onTap: () => open(const DocTemplatesScreen()),
        ),
        _SettingTile(
          icon: Icons.event_note_outlined,
          color: const Color(0xFF0EA5E9),
          title: 'Conditions de paiement',
          text: 'Comptant, 30 jours, 45 jours fin de mois, acompte et solde… appliquées aux documents.',
          onTap: () => open(const PaymentTermsScreen()),
        ),
        _SettingTile(
          icon: Icons.account_balance_wallet_outlined,
          color: YColors.success,
          title: 'Encaissements de vos clients',
          text: '${(session.companySettings['bank_accounts'] as List? ?? const []).length} compte(s) bancaire(s) · paiement en ligne par CinetPay, Wave, PayDunya, Orange Money…',
          onTap: () => open(const CompanyPaymentsScreen()),
        ),
        _SettingTile(
          icon: Icons.forum_outlined,
          color: const Color(0xFFF59E0B),
          title: 'Canaux d\'envoi',
          text: 'E-mail (SMTP), SMS et WhatsApp pour envoyer factures, relances et campagnes.',
          onTap: () => open(const MessagingSettingsScreen()),
        ),
      ],
    ];

    final syncCard = _Panel(
      icon: sync.online ? Icons.cloud_done_outlined : Icons.cloud_off_outlined,
      color: sync.online ? YColors.success : YColors.warning,
      title: 'Synchronisation & hors ligne',
      badge: StatusChip(sync.online ? 'Connecté' : 'Hors ligne', color: sync.online ? YColors.success : YColors.warning),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Adresse du serveur : visible du seul super administrateur (support technique)
          if (session.isSuperAdmin) _Line(Icons.dns_outlined, 'Serveur', session.serverUrl),
          _Line(Icons.cloud_upload_outlined, 'Modifications en attente', '${sync.pendingCount}'),
          _Line(Icons.history, 'Dernière synchronisation', sync.lastSyncAt == null ? '—' : dateTimeFr(sync.lastSyncAt!.toIso8601String())),
          _Line(Icons.devices_outlined, 'Cet appareil', clientPlatform()),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              FilledButton.tonalIcon(onPressed: sync.syncing ? null : sync.sync, icon: const Icon(Icons.sync, size: 18), label: Text(tr('Synchroniser maintenant'))),
              OutlinedButton.icon(
                onPressed: sync.syncing
                    ? null
                    : () async {
                        if (await confirm(
                          context,
                          tr('Tout recharger ?'),
                          tr('Les données locales seront rechargées depuis le serveur. Les modifications en attente sont conservées et envoyées d\'abord.'),
                        )) {
                          await sync.resync();
                        }
                      },
                icon: const Icon(Icons.restart_alt, size: 18),
                label: Text(tr('Recharger les données')),
              ),
            ],
          ),
        ],
      ),
    );

    final accountCard = _Panel(
      icon: Icons.person_outline,
      color: YColors.blue,
      title: 'Mon compte',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              _Avatar(name: '${session.user['name']}', size: 48),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${session.user['name']}',
                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
                      overflow: TextOverflow.ellipsis,
                    ),
                    Text(
                      '${session.user['email']}',
                      style: TextStyle(color: Theme.of(context).hintColor),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              StatusChip(session.isSuperAdmin ? 'Super administrateur' : '${session.user['role'] ?? '—'}', color: YColors.blue, icon: Icons.badge_outlined),
            ],
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton.icon(onPressed: () => _passwordDialog(context), icon: const Icon(Icons.key_outlined, size: 18), label: Text(tr('Changer le mot de passe'))),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  foregroundColor: YColors.danger,
                  side: BorderSide(color: YColors.danger.withValues(alpha: .35)),
                ),
                onPressed: () async {
                  final pending = sync.pendingCount;
                  final msg = pending > 0
                      ? '$pending modification(s) ne sont pas encore envoyées. Elles resteront sur cet appareil et partiront à la prochaine connexion.'
                      : 'Vous pourrez vous reconnecter à tout moment.';
                  if (await confirm(context, tr('Se déconnecter ?'), msg, ok: tr('Se déconnecter'), danger: true)) {
                    await session.logout();
                  }
                },
                icon: const Icon(Icons.logout, size: 18),
                label: Text(tr('Se déconnecter')),
              ),
              if (FirebaseSetup.supported && session.bootstrap?['app']?['push'] == true)
                OutlinedButton.icon(
                  onPressed: () async {
                    try {
                      await FirebaseSetup.registerPush(session.api);
                      final r = asMap(await session.api.post('me/push-test'));
                      if (context.mounted) toast(context, tr('Notification envoyée à ${r['sent']} appareil(s).'));
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                  icon: const Icon(Icons.notifications_active_outlined, size: 18),
                  label: Text(tr('Tester les notifications')),
                ),
              if (!session.isSuperAdmin)
                TextButton.icon(
                  style: TextButton.styleFrom(foregroundColor: YColors.danger),
                  onPressed: () => showDeleteAccountDialog(context),
                  icon: const Icon(Icons.person_remove_outlined, size: 18),
                  label: Text(tr('Supprimer mon compte')),
                ),
            ],
          ),
        ],
      ),
    );

    return ListView(
      padding: const EdgeInsets.only(bottom: 32),
      children: [
        PageHeader(title: tr('Paramètres'), subtitle: tr('Société, équipe, configuration et compte')),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: pad),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _CompanyHero(onEdit: manage ? () => _editCompany(context) : null),
              if (tiles.isNotEmpty) ...[const _SectionLabel('Configuration'), _Grid(minWidth: 260, children: tiles)],
              if (session.can('core.users.manage')) ...[const _SectionLabel('Équipe'), const _UsersList()],
              const _SectionLabel('Appareil et compte'),
              _Grid(minWidth: 420, children: [syncCard, accountCard]),
            ],
          ),
        ),
      ],
    );
  }

  Future<void> _editCompany(BuildContext context) async {
    final session = context.read<Session>();
    final c = session.company;
    final ctrl = {
      for (final f in ['name', 'phone', 'email', 'address', 'city', 'tax_id', 'trade_register']) f: TextEditingController(text: '${c[f] ?? ''}'),
    };
    final tax = TextEditingController(text: vatPct(standardVatRate(session)));
    var country = '${c['country'] ?? 'CI'}'.toUpperCase();
    var vatOn = vatSubject(session);
    var receiptBox = session.companySettings['invoice_receipt_box'] == true;
    var amountWords = session.companySettings['invoice_amount_words'] != false;
    final regime = TextEditingController(text: '${session.companySettings['tax_regime'] ?? ''}');
    String? legalForm = session.companySettings['legal_form'] as String?;
    final capital = TextEditingController(text: toDouble(session.companySettings['capital']) > 0 ? '${toDouble(session.companySettings['capital']).round()}' : '');
    String? defaultMode = session.companySettings['default_payment_method'] as String?;
    final terms = TextEditingController(text: '${session.companySettings['payment_terms_days'] ?? 30}');
    final footer = TextEditingController(text: '${session.companySettings['invoice_footer'] ?? ''}');
    String n(String k) => toDouble(session.companySettings[k]) == 0 ? '' : '${toDouble(session.companySettings[k]).round()}';
    final approval = TextEditingController(text: n('purchase_approval_limit'));
    final rate = TextEditingController(text: n('intervention_hourly_rate'));
    final travel = TextEditingController(text: n('intervention_travel_fee'));
    const labels = {'name': 'Raison sociale', 'phone': 'Téléphone', 'email': 'E-mail', 'address': 'Adresse', 'city': 'Ville', 'tax_id': 'N° contribuable (NCC)', 'trade_register': 'RCCM'};

    await showFormDialog(
      context,
      title: tr('Informations de la société'),
      builder: (dc) => StatefulBuilder(builder: (dc, setD) {
        return ListView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
          shrinkWrap: true,
          children: [
            for (final e in ctrl.entries)
              Field(
                label: labels[e.key]!,
                child: TextField(controller: e.value),
              ),
            Row(
              children: [
                Expanded(
                  child: Field(
                    label: tr('Forme juridique'),
                    child: DropdownButtonFormField<String?>(
                      initialValue: legalForm,
                      isExpanded: true,
                      items: [
                        DropdownMenuItem(value: null, child: Text(tr('Non précisée'))),
                        for (final f in {...legalForms, ?legalForm}) DropdownMenuItem(value: f, child: Text(tr(f))),
                      ],
                      onChanged: (v) => setD(() => legalForm = v),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Field(
                    label: tr('Capital social'),
                    child: TextField(controller: capital, keyboardType: TextInputType.number, decoration: InputDecoration(hintText: tr('Ex. : 1000000'))),
                  ),
                ),
              ],
            ),
            Field(
              label: tr('Pays'),
              child: DropdownButtonFormField<String>(
                initialValue: vatCountryNames.containsKey(country) ? country : null,
                isExpanded: true,
                items: [
                  for (final e in (vatCountryNames.entries.toList()..sort((a, b) => a.value.compareTo(b.value))))
                    DropdownMenuItem(value: e.key, child: Text(tr('${e.value} — TVA ${vatPct(countryVatRate(e.key))} %'))),
                ],
                // Le taux normal suit le pays choisi
                onChanged: (v) => setD(() {
                  country = v ?? country;
                  tax.text = vatPct(countryVatRate(country));
                }),
              ),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Assujetti à la TVA')),
              subtitle: Text(tr(vatOn
                  ? 'Les factures de vente portent la TVA (taux normal ou 0 % au choix sur chaque ligne).'
                  : 'Aucune TVA sur les ventes ; les factures portent la mention « TVA non applicable ».')),
              value: vatOn,
              onChanged: (v) => setD(() => vatOn = v),
            ),
            Field(
              label: tr('Régime d\'imposition (facultatif)'),
              child: TextField(controller: regime, decoration: InputDecoration(hintText: tr('Ex. : RNI, RSI, Entreprenant, Micro-entreprise'))),
            ),
            Row(
              children: [
                Expanded(
                  child: Field(
                    label: tr('TVA normale (%)'),
                    child: TextField(controller: tax, enabled: vatOn, keyboardType: const TextInputType.numberWithOptions(decimal: true)),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Field(
                    label: tr('Délai de paiement (jours)'),
                    child: TextField(controller: terms),
                  ),
                ),
              ],
            ),
            Field(
              label: tr('Mention en pied de facture'),
              child: TextField(controller: footer, maxLines: 2),
            ),
            Field(
              label: tr('Mode de règlement par défaut des factures'),
              child: DropdownButtonFormField<String?>(
                initialValue: paymentMethods.containsKey(defaultMode) ? defaultMode : null,
                isExpanded: true,
                items: [
                  DropdownMenuItem(value: null, child: Text(tr('Aucun'))),
                  for (final e in paymentMethods.entries)
                    if (!['loyalty', 'gift_card'].contains(e.key)) DropdownMenuItem(value: e.key, child: Text(tr(e.value))),
                ],
                onChanged: (v) => setD(() => defaultMode = v),
              ),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Case « Pour décharge » sur les factures')),
              subtitle: Text(tr('Cadre de signature du client à la remise (modifiable sur chaque facture).')),
              value: receiptBox,
              onChanged: (v) => setD(() => receiptBox = v),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(tr('Montant en lettres')),
              subtitle: Text(tr('« Arrêtée la présente facture à la somme de … »')),
              value: amountWords,
              onChanged: (v) => setD(() => amountWords = v),
            ),
            if (session.hasModule('achats'))
              Field(
                label: tr('Seuil d\'approbation des achats (montant HT)'),
                child: TextField(
                  controller: approval,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(hintText: tr('Vide = pas d\'approbation'), helperText: tr('Au-delà, demandes d\'achat et bons de commande sont approuvés par la direction')),
                ),
              ),
            if (session.hasModule('interventions'))
              Row(
                children: [
                  Expanded(
                    child: Field(
                      label: tr('Taux horaire des interventions'),
                      child: TextField(controller: rate, keyboardType: TextInputType.number),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Field(
                      label: tr('Frais de déplacement'),
                      child: TextField(controller: travel, keyboardType: TextInputType.number),
                    ),
                  ),
                ],
              ),
            Align(
              alignment: Alignment.centerRight,
              child: SaveButton(
                onPressed: () async {
                  try {
                    await session.api.put('company', {
                      for (final e in ctrl.entries) e.key: e.value.text.trim().isEmpty ? null : e.value.text.trim(),
                      'country': country,
                      'settings': {
                        'tax_rate': toDouble(tax.text),
                        'vat_subject': vatOn,
                        'tax_regime': regime.text.trim().isEmpty ? null : regime.text.trim(),
                        'invoice_receipt_box': receiptBox,
                        'invoice_amount_words': amountWords,
                        'legal_form': legalForm,
                        'capital': capital.text.trim().isEmpty ? null : toDouble(capital.text),
                        'default_payment_method': defaultMode,
                        'payment_terms_days': toDouble(terms.text).round(),
                        'invoice_footer': footer.text.trim().isEmpty ? null : footer.text.trim(),
                        if (session.hasModule('achats')) 'purchase_approval_limit': toDouble(approval.text),
                        if (session.hasModule('interventions')) 'intervention_hourly_rate': toDouble(rate.text),
                        if (session.hasModule('interventions')) 'intervention_travel_fee': toDouble(travel.text),
                      },
                    });
                    await session.refreshBootstrap();
                    if (dc.mounted) Navigator.pop(dc);
                  } catch (e) {
                    if (dc.mounted) toast(dc, errorText(e), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
            ),
          ],
        );
      }),
    );
  }

  Future<void> _passwordDialog(BuildContext context) async {
    final session = context.read<Session>();
    final current = TextEditingController();
    final next = TextEditingController();
    final confirmC = TextEditingController();
    await showFormDialog(
      context,
      title: tr('Changer le mot de passe'),
      builder: (dc) {
        return ListView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
          shrinkWrap: true,
          children: [
            Field(
              label: tr('Mot de passe actuel'),
              child: TextField(controller: current, obscureText: true),
            ),
            Field(
              label: tr('Nouveau mot de passe (8 caractères min.)'),
              child: TextField(controller: next, obscureText: true),
            ),
            Field(
              label: tr('Confirmation'),
              child: TextField(controller: confirmC, obscureText: true),
            ),
            Align(
              alignment: Alignment.centerRight,
              child: SaveButton(
                onPressed: () async {
                  try {
                    await session.api.post('auth/password', {'current_password': current.text, 'password': next.text, 'password_confirmation': confirmC.text});
                    if (dc.mounted) {
                      Navigator.pop(dc);
                      toast(context, tr('Mot de passe modifié.'));
                    }
                  } catch (e) {
                    if (dc.mounted) toast(dc, errorText(e), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
            ),
          ],
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Éléments visuels de la page
// ---------------------------------------------------------------------------

const _avatarColors = [Color(0xFF1F6FEB), Color(0xFF7C3AED), Color(0xFF0EA5E9), Color(0xFF12A150), Color(0xFFF59E0B), Color(0xFFDB2777), Color(0xFF0D9488), Color(0xFFEA580C)];

Color _colorFor(String s) => _avatarColors[s.codeUnits.fold<int>(0, (a, c) => a + c) % _avatarColors.length];

String _initials(String name) {
  final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
  if (parts.isEmpty) return '?';
  if (parts.length == 1) return parts.first[0].toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.name, this.size = 40, this.online = false, this.faded = false});

  final String name;
  final double size;
  final bool online;
  final bool faded;

  @override
  Widget build(BuildContext context) {
    final c = faded ? Colors.grey : _colorFor(name);
    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Container(
            width: size,
            height: size,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(colors: [c, Color.lerp(c, Colors.white, .3)!], begin: Alignment.topLeft, end: Alignment.bottomRight),
            ),
            child: Text(
              _initials(name),
              style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: size * .36),
            ),
          ),
          if (online)
            Positioned(
              right: -1,
              bottom: -1,
              child: Container(
                width: size * .3,
                height: size * .3,
                decoration: BoxDecoration(
                  color: YColors.success,
                  shape: BoxShape.circle,
                  border: Border.all(color: Theme.of(context).colorScheme.surface, width: 2),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Grille adaptative : autant de colonnes que la largeur le permet.
class _Grid extends StatelessWidget {
  const _Grid({required this.children, this.minWidth = 260, this.gap = 12});

  final List<Widget> children;
  final double minWidth;
  final double gap;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, c) {
      final cols = (c.maxWidth / minWidth).floor().clamp(1, 4);
      final w = (c.maxWidth - gap * (cols - 1)) / cols;
      return Wrap(
        spacing: gap,
        runSpacing: gap,
        children: [for (final ch in children) SizedBox(width: w, child: ch)],
      );
    },
  );
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(4, 26, 4, 10),
    child: Text(
      tr(text).toUpperCase(),
      style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 1.1, color: Theme.of(context).hintColor),
    ),
  );
}

class _IconBadge extends StatelessWidget {
  const _IconBadge(this.icon, this.color, {this.size = 40});

  final IconData icon;
  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(size * .3)),
    child: Icon(icon, color: color, size: size * .52),
  );
}

/// En-tête « carte d'identité » de la société.
class _CompanyHero extends StatelessWidget {
  const _CompanyHero({this.onEdit});

  final VoidCallback? onEdit;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final c = session.company;
    final narrow = MediaQuery.of(context).size.width < 700;
    String v(dynamic x) => x == null || '$x'.trim().isEmpty ? '—' : '$x';
    final name = v(c['name']);
    final logo = '${c['logo_url'] ?? ''}';

    final facts = <(IconData, String, String)>[
      (Icons.phone_outlined, 'Téléphone', v(c['phone'])),
      (Icons.alternate_email, 'E-mail', v(c['email'])),
      (Icons.place_outlined, 'Ville', v(c['city'])),
      (Icons.receipt_long_outlined, 'NCC', v(c['tax_id'])),
      (Icons.gavel_outlined, 'RCCM', v(c['trade_register'])),
      (Icons.payments_outlined, 'Devise', session.currency),
      (Icons.percent, 'TVA', vatSubject(session) ? '${vatPct(standardVatRate(session))} %' : 'Non assujetti'),
    ];

    final identity = Row(
      children: [
        Container(
          width: narrow ? 56 : 68,
          height: narrow ? 56 : 68,
          clipBehavior: Clip.antiAlias,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: .14),
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: Colors.white.withValues(alpha: .25)),
          ),
          child: logo.startsWith('http') ? Image.network(logo, fit: BoxFit.cover, errorBuilder: (_, _, _) => _heroInitials(name, narrow)) : _heroInitials(name, narrow),
        ),
        const SizedBox(width: 16),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                tr('Société'),
                style: TextStyle(color: Colors.white.withValues(alpha: .7), fontSize: 12, fontWeight: FontWeight.w600, letterSpacing: .6),
              ),
              const SizedBox(height: 2),
              Text(
                name,
                style: TextStyle(color: Colors.white, fontSize: narrow ? 20 : 24, fontWeight: FontWeight.w800, letterSpacing: -.4),
                overflow: TextOverflow.ellipsis,
              ),
              if (v(c['address']) != '—' || v(c['city']) != '—')
                Text(
                  [c['address'], c['city']].where((x) => x != null && '$x'.trim().isNotEmpty).join(' · '),
                  style: TextStyle(color: Colors.white.withValues(alpha: .8)),
                  overflow: TextOverflow.ellipsis,
                ),
            ],
          ),
        ),
        if (onEdit != null) ...[
          const SizedBox(width: 12),
          narrow
              ? IconButton.filled(
                  onPressed: onEdit,
                  style: IconButton.styleFrom(backgroundColor: Colors.white, foregroundColor: YColors.night),
                  icon: const Icon(Icons.edit_outlined),
                  tooltip: tr('Modifier'),
                )
              : FilledButton.icon(
                  onPressed: onEdit,
                  style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: YColors.night),
                  icon: const Icon(Icons.edit_outlined, size: 18),
                  label: Text(tr('Modifier')),
                ),
        ],
      ],
    );

    return AnimatedFadeIn(
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(20),
          gradient: const LinearGradient(colors: [YColors.night, Color(0xFF1B3F86), YColors.blue], begin: Alignment.topLeft, end: Alignment.bottomRight),
          boxShadow: [BoxShadow(color: YColors.blue.withValues(alpha: .22), blurRadius: 24, offset: const Offset(0, 10))],
        ),
        padding: EdgeInsets.all(narrow ? 18 : 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            identity,
            SizedBox(height: narrow ? 16 : 22),
            _Grid(
              minWidth: narrow ? 150 : 190,
              gap: 10,
              children: [
                for (final f in facts)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: .09),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Colors.white.withValues(alpha: .12)),
                    ),
                    child: Row(
                      children: [
                        Icon(f.$1, size: 18, color: Colors.white.withValues(alpha: .75)),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(tr(f.$2), style: TextStyle(fontSize: 11.5, color: Colors.white.withValues(alpha: .65))),
                              Text(
                                f.$3,
                                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _heroInitials(String name, bool narrow) => Text(
    _initials(name),
    style: TextStyle(color: Colors.white, fontSize: narrow ? 20 : 24, fontWeight: FontWeight.w800),
  );
}

/// Raccourci vers un écran de configuration.
class _SettingTile extends StatefulWidget {
  const _SettingTile({required this.icon, required this.color, required this.title, required this.text, required this.onTap});

  final IconData icon;
  final Color color;
  final String title;
  final String text;
  final VoidCallback onTap;

  @override
  State<_SettingTile> createState() => _SettingTileState();
}

class _SettingTileState extends State<_SettingTile> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return MouseRegion(
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => _hover = false),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        transform: Matrix4.translationValues(0, _hover ? -2 : 0, 0),
        decoration: BoxDecoration(
          color: theme.colorScheme.surface,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: _hover ? widget.color.withValues(alpha: .45) : theme.dividerColor.withValues(alpha: .6)),
          boxShadow: [
            BoxShadow(
              color: (_hover ? widget.color : Colors.black).withValues(alpha: _hover ? .12 : .03),
              blurRadius: _hover ? 18 : 8,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: widget.onTap,
            child: MediaQuery.of(context).size.width < 700
                ? Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(
                      children: [
                        _IconBadge(widget.icon, widget.color),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                tr(widget.title),
                                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                              const SizedBox(height: 2),
                              Text(
                                tr(widget.text),
                                style: TextStyle(color: theme.hintColor, fontSize: 12.5, height: 1.3),
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ],
                          ),
                        ),
                        Icon(Icons.chevron_right, color: theme.hintColor),
                      ],
                    ),
                  )
                : Padding(
                    padding: const EdgeInsets.all(16),
                    child: SizedBox(
                      height: 116,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              _IconBadge(widget.icon, widget.color),
                              const Spacer(),
                              Icon(Icons.arrow_forward_rounded, size: 18, color: _hover ? widget.color : theme.hintColor),
                            ],
                          ),
                          const SizedBox(height: 12),
                          Text(
                            tr(widget.title),
                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          const SizedBox(height: 3),
                          Expanded(
                            child: Text(
                              tr(widget.text),
                              style: TextStyle(color: theme.hintColor, fontSize: 12.5, height: 1.35),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
          ),
        ),
      ),
    );
  }
}

/// Carte avec pastille d'icône et titre.
class _Panel extends StatelessWidget {
  const _Panel({required this.icon, required this.color, required this.title, required this.child, this.badge, this.actions});

  final IconData icon;
  final Color color;
  final String title;
  final Widget child;
  final Widget? badge;
  final List<Widget>? actions;

  @override
  Widget build(BuildContext context) => AnimatedFadeIn(
    child: Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                _IconBadge(icon, color, size: 36),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15.5, letterSpacing: -.2)),
                ),
                ?badge,
                ...?actions,
              ],
            ),
            const SizedBox(height: 14),
            child,
          ],
        ),
      ),
    ),
  );
}

class _Line extends StatelessWidget {
  const _Line(this.icon, this.label, this.value);

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(vertical: 9),
    decoration: BoxDecoration(
      border: Border(bottom: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: .5))),
    ),
    child: Row(
      children: [
        Icon(icon, size: 17, color: Theme.of(context).hintColor),
        const SizedBox(width: 10),
        Text(tr(label), style: TextStyle(color: Theme.of(context).hintColor)),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            tr(value),
            textAlign: TextAlign.right,
            style: const TextStyle(fontWeight: FontWeight.w600),
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    ),
  );
}

// ---------------------------------------------------------------------------
// Utilisateurs (nécessite la connexion)
// ---------------------------------------------------------------------------

final _usersRefresh = ValueNotifier(0);

/// Équipe & accès : l'administrateur crée l'accès de chaque employé (e-mail, mot de passe, rôle).
class TeamScreen extends StatelessWidget {
  const TeamScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 12.0 : 24.0;
    const steps = [
      (Icons.person_add_alt_1_outlined, 'Ajoutez l\'employé', 'Touchez « Ajouter » : nom, e-mail et mot de passe provisoire.'),
      (Icons.admin_panel_settings_outlined, 'Choisissez sa fonction', 'Sa fonction, son responsable et ses modules : il ne voit que ce que vous lui ouvrez.'),
      (Icons.devices_outlined, 'Il se connecte', 'Sur le web, son ordinateur ou son téléphone, avec son e-mail : il retrouve votre entreprise.'),
    ];
    return ListView(padding: const EdgeInsets.only(bottom: 32), children: [
      PageHeader(title: tr('Équipe & accès'), subtitle: tr('Créez les accès de vos employés et choisissez ce que chacun peut voir et faire')),
      Padding(
        padding: EdgeInsets.symmetric(horizontal: pad),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(spacing: 12, runSpacing: 12, children: [
            for (final (i, st) in steps.indexed)
              SizedBox(
                width: MediaQuery.sizeOf(context).width < 700 ? double.infinity : 300,
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      CircleAvatar(radius: 18, backgroundColor: YColors.blue.withValues(alpha: .1), child: Icon(st.$1, color: YColors.blue, size: 20)),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr('${i + 1}. ${st.$2}'), style: const TextStyle(fontWeight: FontWeight.w800)),
                          const SizedBox(height: 4),
                          Text(tr(st.$3), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13, height: 1.35)),
                        ]),
                      ),
                    ]),
                  ),
                ),
              ),
          ]),
          const SizedBox(height: 12),
          Card(
            margin: EdgeInsets.zero,
            child: ListTile(
              leading: Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .1), borderRadius: BorderRadius.circular(11)),
                child: const Icon(Icons.account_tree_outlined, color: YColors.blue),
              ),
              title: Text(tr('Fonctions & organigramme'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr('Créez vos propres fonctions, rangez-les par hiérarchie et choisissez ce que chacune peut faire')),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                await FunctionsScreen.open(context);
                _usersRefresh.value++;
              },
            ),
          ),
          const SizedBox(height: 16),
          const _UsersList(),
        ]),
      ),
    ]);
  }
}

class _UsersList extends StatefulWidget {
  const _UsersList();

  @override
  State<_UsersList> createState() => _UsersListState();
}

class _UsersListState extends State<_UsersList> {
  late Future<List<dynamic>> _future;
  String _q = '';
  String? _role;
  bool _all = false;

  @override
  void initState() {
    super.initState();
    _load();
    _usersRefresh.addListener(_reload);
  }

  @override
  void dispose() {
    _usersRefresh.removeListener(_reload);
    super.dispose();
  }

  void _load() => _future = context.read<Session>().api.get('users').then((r) => r['data'] as List);

  void _reload() => setState(_load);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return FutureBuilder<List<dynamic>>(
      future: _future,
      builder: (context, snap) {
        final users = snap.data ?? const [];
        final online = users.where((u) => u['online'] == true).length;
        final roles = <String>{for (final u in users) '${u['role'] ?? '—'}'}.toList()..sort();
        final q = _q.toLowerCase();
        final shown = users.where((u) {
          if (_role != null && '${u['role'] ?? '—'}' != _role) return false;
          return q.isEmpty || '${u['name']} ${u['email']} ${u['role']}'.toLowerCase().contains(q);
        }).toList();
        const limit = 12;
        final visible = _all || q.isNotEmpty || _role != null ? shown : shown.take(limit).toList();
        final narrow = MediaQuery.of(context).size.width < 700;

        final search = SizedBox(
          width: narrow ? double.infinity : 260,
          height: 40,
          child: TextField(
            onChanged: (v) => setState(() => _q = v),
            decoration: InputDecoration(
              isDense: true,
              hintText: tr('Rechercher un utilisateur'),
              prefixIcon: const Icon(Icons.search, size: 20),
              contentPadding: const EdgeInsets.symmetric(vertical: 8),
            ),
          ),
        );
        final add = FilledButton.icon(onPressed: () => _userDialog(context), icon: const Icon(Icons.person_add_alt, size: 18), label: Text(tr('Ajouter')));

        return _Panel(
          icon: Icons.groups_2_outlined,
          color: const Color(0xFF7C3AED),
          title: 'Utilisateurs',
          badge: snap.hasData && !narrow
              ? Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: Wrap(
                    spacing: 6,
                    children: [
                      StatusChip('${users.length} compte(s)', color: YColors.blue, icon: Icons.person_outline),
                      if (online > 0) StatusChip('$online en ligne', color: YColors.success),
                    ],
                  ),
                )
              : null,
          actions: narrow ? [IconButton.filledTonal(onPressed: () => _userDialog(context), icon: const Icon(Icons.person_add_alt), tooltip: tr('Ajouter'))] : [search, const SizedBox(width: 8), add],
          child: Builder(
            builder: (context) {
              if (snap.hasError) return OnlineOnlyNotice(error: snap.error!, onRetry: _reload);
              if (!snap.hasData) {
                return const Padding(
                  padding: EdgeInsets.all(24),
                  child: Center(child: CircularProgressIndicator()),
                );
              }
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (narrow) ...[
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        StatusChip('${users.length} compte(s)', color: YColors.blue, icon: Icons.person_outline),
                        if (online > 0) StatusChip('$online en ligne', color: YColors.success),
                      ],
                    ),
                    const SizedBox(height: 10),
                    search,
                    const SizedBox(height: 10),
                  ],
                  if (roles.length > 1)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 14),
                      child: SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(
                          children: [
                            _RoleChip(label: tr('Tous'), count: users.length, selected: _role == null, onTap: () => setState(() => _role = null)),
                            for (final r in roles)
                              _RoleChip(label: r, count: users.where((u) => '${u['role'] ?? '—'}' == r).length, selected: _role == r, onTap: () => setState(() => _role = _role == r ? null : r)),
                          ],
                        ),
                      ),
                    ),
                  if (shown.isEmpty)
                    Padding(
                      padding: const EdgeInsets.all(24),
                      child: Center(
                        child: Text(tr('Aucun utilisateur ne correspond.'), style: TextStyle(color: theme.hintColor)),
                      ),
                    )
                  else
                    _Grid(minWidth: 300, gap: 10, children: [for (final u in visible) _UserCard(Map<String, dynamic>.from(u))]),
                  if (visible.length < shown.length)
                    Padding(
                      padding: const EdgeInsets.only(top: 12),
                      child: Center(
                        child: TextButton.icon(
                          onPressed: () => setState(() => _all = true),
                          icon: const Icon(Icons.expand_more),
                          label: Text(tr('Afficher les ${shown.length - visible.length} autres')),
                        ),
                      ),
                    ),
                ],
              );
            },
          ),
        );
      },
    );
  }
}

class _RoleChip extends StatelessWidget {
  const _RoleChip({required this.label, required this.count, required this.selected, required this.onTap});

  final String label;
  final int count;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = theme.colorScheme.primary;
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: Material(
        color: selected ? c : theme.colorScheme.surfaceContainer,
        shape: const StadiumBorder(),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  tr(label),
                  style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: selected ? Colors.white : null),
                ),
                const SizedBox(width: 6),
                Text('$count', style: TextStyle(fontSize: 12, color: selected ? Colors.white.withValues(alpha: .8) : theme.hintColor)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _UserCard extends StatefulWidget {
  const _UserCard(this.u);

  final Map<String, dynamic> u;

  @override
  State<_UserCard> createState() => _UserCardState();
}

class _UserCardState extends State<_UserCard> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final u = widget.u;
    final theme = Theme.of(context);
    final inactive = u['is_active'] == false;
    final online = u['online'] == true;
    final name = '${u['name'] ?? ''}';
    return MouseRegion(
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => _hover = false),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        decoration: BoxDecoration(
          color: _hover ? theme.colorScheme.surfaceContainerLow : theme.colorScheme.surface,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: _hover ? theme.colorScheme.primary.withValues(alpha: .35) : theme.dividerColor.withValues(alpha: .6)),
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: () => _userDialog(context, u),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Opacity(
                opacity: inactive ? .55 : 1,
                child: Row(
                  children: [
                    _Avatar(name: name, size: 42, online: online, faded: inactive),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            name,
                            style: const TextStyle(fontWeight: FontWeight.w700),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          Text(
                            '${u['email'] ?? ''}',
                            style: TextStyle(color: theme.hintColor, fontSize: 12.5),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          const SizedBox(height: 6),
                          Wrap(
                            spacing: 6,
                            runSpacing: 4,
                            children: [
                              StatusChip('${u['role'] ?? '—'}', color: _colorFor('${u['role'] ?? '—'}'), icon: Icons.badge_outlined),
                              if (online) StatusChip('En ligne${(u['last_platform'] ?? '') == '' ? '' : ' · ${u['last_platform']}'}', color: YColors.success),
                              if (u['custom_access'] == true) const StatusChip('Droits personnalisés', color: YColors.warning, icon: Icons.tune),
                              if (inactive) const StatusChip('Désactivé', color: YColors.danger, icon: Icons.block),
                            ],
                          ),
                        ],
                      ),
                    ),
                    Icon(Icons.chevron_right, color: _hover ? theme.colorScheme.primary : theme.hintColor.withValues(alpha: .5)),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

Future<void> _userDialog(BuildContext context, [Map<String, dynamic>? user]) async {
  if (await openUserEditor(context, user: user)) _usersRefresh.value++;
}
