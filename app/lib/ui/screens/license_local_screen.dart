import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

import 'package:yomara_erp/core/safe_launch.dart';
import 'package:yomara_erp/core/config.dart';
/// « Compte Yomara & modules » : l'ERP est gratuit et fonctionne sans aucune clé. Pour ajouter des modules payants,
/// l'administrateur relie l'installation à son compte en ligne (e-mail + mot de passe) ; les modules achetés
/// pour cette installation arrivent à la vérification suivante (≤ 6 h) ou tout de suite avec « Vérifier maintenant ».
class LicenseLocalScreen extends StatefulWidget {
  const LicenseLocalScreen({super.key});

  @override
  State<LicenseLocalScreen> createState() => _LicenseLocalScreenState();
}

class _LicenseLocalScreenState extends State<LicenseLocalScreen> {
  Map<String, dynamic>? _info;
  Object? _error;
  bool _checking = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('license');
      if (mounted) setState(() => (_info = asMap(asMap(r)['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  String get _server => '${_info?['server'] ?? ''}'.replaceAll(RegExp(r'/+$'), '');

  Future<void> _check() async {
    setState(() => _checking = true);
    try {
      final r = await context.read<Session>().api.post('license/check');
      if (!mounted) return;
      final info = asMap(asMap(r)['data']);
      setState(() => _info = info);
      final ok = info['status'] == 'valid';
      toast(context, ok ? 'Vérification effectuée : vos modules sont à jour.' : '${info['error'] ?? 'Vérification effectuée.'}', error: !ok);
      if (ok) await context.read<Session>().refreshBootstrap();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _checking = false);
    }
  }

  Future<void> _open(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null || url.isEmpty) return;
    try {
      if (!await safeLaunchUrl(uri, mode: LaunchMode.externalApplication) && mounted) toast(context, 'Impossible d\'ouvrir $url', error: true);
    } catch (_) {
      if (mounted) toast(context, 'Impossible d\'ouvrir $url', error: true);
    }
  }

  Future<void> _link() async {
    final email = TextEditingController();
    final password = TextEditingController();
    var busy = false;
    var hidden = true;
    String? failure;
    // Compte protégé par la double authentification : défi reçu, code à saisir
    Map<String, dynamic>? twoFa;
    final code = TextEditingController();
    final relink = _info?['linked'] == true;
    final done = await showFormDialog<bool>(
      context,
      title: relink ? 'Relier à un compte Yomara' : 'Relier à mon compte Yomara',
      subtitle: 'E-mail et mot de passe de votre compte en ligne',
      icon: Icons.link_rounded,
      builder: (dc) => StatefulBuilder(builder: (dc, setS) {
        final hint = Theme.of(dc).hintColor;
        Future<void> submit() async {
          final e = email.text.trim();
          if (e.isEmpty || password.text.isEmpty) {
            setS(() => failure = tr('Saisissez l\'e-mail et le mot de passe de votre compte Yomara.'));
            return;
          }
          if (twoFa != null && code.text.trim().isEmpty) {
            setS(() => failure = tr('Saisissez le code de double authentification.'));
            return;
          }
          setS(() => (busy = true, failure = null));
          try {
            final r = await context.read<Session>().api.post('license/activate-local', {
              'email': e,
              'password': password.text,
              if (twoFa != null) 'challenge': twoFa!['challenge'],
              if (twoFa != null) 'code': code.text.trim(),
            });
            if (mounted) setState(() => _info = asMap(asMap(r)['data']));
            if (dc.mounted) Navigator.pop(dc, true);
          } catch (err) {
            if (err is ApiException && err.status == 409 && asMap(err.body)['two_factor'] == true) {
              if (dc.mounted) setS(() => twoFa = asMap(err.body));
            } else if (dc.mounted) {
              setS(() => failure = errorText(err));
            }
          } finally {
            if (dc.mounted) setS(() => busy = false);
          }
        }

        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 12, 24, 24), children: [
          Text(
            tr('Utilisez le compte sur lequel vous achetez vos modules. Une fois reliée, cette installation apparaît dans « Mes installations » '
                'de votre compte et peut recevoir les modules achetés pour elle.'),
            style: TextStyle(color: hint),
          ),
          if (relink) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: YColors.warning.withValues(alpha: .10), borderRadius: BorderRadius.circular(10)),
              child: Text(tr('Cette installation sera reliée au compte saisi : ses modules seront ceux achetés pour elle sur ce compte.'),
                  style: const TextStyle(fontSize: 13)),
            ),
          ],
          const SizedBox(height: 14),
          Field(
            label: 'E-mail du compte',
            child: TextField(
              controller: email,
              autofocus: true,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.email],
              decoration: const InputDecoration(hintText: 'vous@entreprise.com', prefixIcon: Icon(Icons.alternate_email_rounded)),
              textInputAction: TextInputAction.next,
            ),
          ),
          const SizedBox(height: 10),
          Field(
            label: 'Mot de passe',
            child: TextField(
              controller: password,
              obscureText: hidden,
              autofillHints: const [AutofillHints.password],
              decoration: InputDecoration(
                prefixIcon: const Icon(Icons.lock_outline_rounded),
                suffixIcon: IconButton(
                  tooltip: tr(hidden ? 'Afficher' : 'Masquer'),
                  onPressed: () => setS(() => hidden = !hidden),
                  icon: Icon(hidden ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                ),
              ),
              onSubmitted: (_) => busy ? null : submit(),
            ),
          ),
          if (twoFa != null) ...[
            const SizedBox(height: 10),
            Field(
              label: twoFa!['method'] == 'email'
                  ? 'Code reçu par e-mail (${twoFa!['email_hint'] ?? ''})'
                  : 'Code de votre application d\'authentification (ou code de secours)',
              child: TextField(
                controller: code,
                autofocus: true,
                textAlign: TextAlign.center,
                autofillHints: const [AutofillHints.oneTimeCode],
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: 6),
                decoration: const InputDecoration(hintText: '000000', prefixIcon: Icon(Icons.verified_user_outlined)),
                onSubmitted: (_) => busy ? null : submit(),
              ),
            ),
          ],
          if (failure != null) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: YColors.danger.withValues(alpha: .08),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: YColors.danger.withValues(alpha: .25)),
              ),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.error_outline, color: YColors.danger, size: 18),
                const SizedBox(width: 8),
                Expanded(child: Text(failure!, style: const TextStyle(fontSize: 13))),
              ]),
            ),
          ],
          const SizedBox(height: 6),
          if (_server.isNotEmpty)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: () => _open('$_server/inscription'),
                icon: const Icon(Icons.person_add_alt_1_outlined, size: 18),
                label: Text(tr('Pas encore de compte ? Créez-le gratuitement')),
              ),
            ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton.icon(
              onPressed: busy ? null : submit,
              icon: busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.link_rounded),
              label: Text(tr('Relier cette installation')),
            ),
          ),
        ]);
      }),
    );
    email.dispose();
    password.dispose();
    if (done == true && mounted) {
      final ok = _info?['status'] == 'valid';
      toast(context, ok ? 'Installation reliée à votre compte Yomara.' : '${_info?['error'] ?? 'Liaison enregistrée.'}', error: !ok);
      await context.read<Session>().refreshBootstrap();
    }
  }

  Future<void> _buy() async {
    final name = '${_info?['company_name'] ?? ''}';
    await _open('${_info?['buy_url'] ?? ''}');
    if (mounted) {
      toast(context, name.isEmpty
          ? 'Dans la boutique, choisissez cette installation dans « Acheter pour », puis payez.'
          : 'Dans la boutique, choisissez « Acheter pour : $name », puis payez.');
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _info == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_info == null) return const LoadingSkeleton();
    final i = _info!;
    final status = '${i['status'] ?? 'free'}';
    final pad = MediaQuery.sizeOf(context).width < 700 ? 16.0 : 24.0;
    final hint = Theme.of(context).hintColor;
    final header = PageHeader(
      title: tr('Compte Yomara & modules'),
      subtitle: tr('Fonctions gratuites, liaison à votre compte en ligne et modules achetés'),
      actions: [IconButton.outlined(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh))],
    );
    Widget padded(Widget w) => Padding(padding: EdgeInsets.symmetric(horizontal: pad), child: w);

    if (status == 'not_required') {
      return ListView(padding: const EdgeInsets.only(bottom: 40), children: [
        header,
        padded(SectionCard(
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const DialogIconBadge(icon: Icon(Icons.verified_user_outlined), color: YColors.success, size: 48),
            const SizedBox(width: 16),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('Rien à faire ici'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17)),
                const SizedBox(height: 6),
                Text(tr('Vos modules sont gérés directement par votre abonnement en ligne : ils s\'activent automatiquement.'), style: TextStyle(color: hint)),
              ]),
            ),
          ]),
        )),
      ]);
    }

    final mods = [for (final m in (i['modules'] is List ? i['modules'] as List : const [])) asMap(m)];
    final linked = i['linked'] == true && status != 'free';
    final hasBuy = '${i['buy_url'] ?? ''}'.isNotEmpty && !AppConfig.purchasesHidden;
    final spinner = const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white));

    final linkButton = FilledButton.icon(
      onPressed: _link,
      icon: const Icon(Icons.link_rounded),
      label: Text(tr(linked ? 'Relier à nouveau' : 'Relier à mon compte Yomara')),
    );
    final checkButton = FilledButton.icon(
      onPressed: _checking ? null : _check,
      icon: _checking ? spinner : const Icon(Icons.sync_rounded),
      label: Text(tr('Vérifier maintenant')),
    );

    final actions = <Widget>[
      if (status == 'free') linkButton,
      if (status == 'valid' || status == 'missing') ...[
        checkButton,
        if (hasBuy) OutlinedButton.icon(onPressed: _buy, icon: const Icon(Icons.shopping_cart_outlined), label: Text(tr('Acheter des modules'))),
        TextButton.icon(onPressed: _link, icon: const Icon(Icons.swap_horiz_rounded), label: Text(tr('Relier à un autre compte'))),
      ],
      if (status == 'expired') ...[
        checkButton,
        OutlinedButton.icon(onPressed: _link, icon: const Icon(Icons.link_rounded), label: Text(tr('Relier à nouveau'))),
      ],
      if (status == 'refused' || status == 'suspended' || status == 'revoked') ...[
        linkButton,
        OutlinedButton.icon(
          onPressed: _checking ? null : _check,
          icon: const Icon(Icons.sync_rounded),
          label: Text(tr('Vérifier maintenant')),
        ),
      ],
    ];

    Widget info(String label, Object? value, {IconData? icon}) {
      final v = '${value ?? ''}'.trim();
      return SizedBox(
        width: 250,
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (icon != null) ...[Icon(icon, size: 18, color: hint), const SizedBox(width: 10)],
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(label), style: TextStyle(color: hint, fontSize: 12)),
              const SizedBox(height: 2),
              SelectableText(v.isEmpty ? '—' : v, style: const TextStyle(fontWeight: FontWeight.w600)),
            ]),
          ),
        ]),
      );
    }

    final validUntil = DateTime.tryParse('${i['valid_until'] ?? ''}');
    final offlineDays = validUntil?.difference(DateTime.now()).inDays;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.only(bottom: 40), children: [
        header,
        padded(AnimatedFadeIn(child: _StatusHero(info: i))),
        const SizedBox(height: 14),
        padded(Wrap(spacing: 10, runSpacing: 10, children: actions)),
        if ('${i['error'] ?? ''}'.isNotEmpty && status != 'free') ...[
          const SizedBox(height: 12),
          padded(AnimatedFadeIn(
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: YColors.danger.withValues(alpha: .07),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: YColors.danger.withValues(alpha: .25)),
              ),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.error_outline, color: YColors.danger),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('Dernière réponse de la plateforme Yomara'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 2),
                    Text(tr('${i['error']}')),
                  ]),
                ),
              ]),
            ),
          )),
        ],
        const SizedBox(height: 14),
        if (status == 'free') ...[
          padded(SectionCard(
            title: 'Inclus gratuitement',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (mods.isEmpty)
                Text(tr('Ventes et factures, achats, stock, clients (CRM), migration de données… sans limite d\'employés.'), style: TextStyle(color: hint))
              else
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (var k = 0; k < mods.length; k++)
                    AnimatedFadeIn(
                      index: k,
                      child: StatusChip('${mods[k]['name'] ?? mods[k]['key']}', color: YColors.success, icon: Icons.check_circle_outline_rounded),
                    ),
                ]),
            ]),
          )),
          if (!AppConfig.purchasesHidden) const SizedBox(height: 12),
          if (!AppConfig.purchasesHidden) padded(SectionCard(
            title: 'Ajouter des modules payants',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              for (final (n, s) in const [
                ('Reliez cette installation à votre compte Yomara', 'Avec l\'e-mail et le mot de passe de votre compte en ligne (création gratuite).'),
                ('Achetez les modules pour cette installation', 'Dans la boutique en ligne, choisissez cette installation dans « Acheter pour ».'),
                ('Ils arrivent ici automatiquement', 'À la prochaine vérification (toutes les 6 h au plus) ou tout de suite avec « Vérifier maintenant ».'),
              ].indexed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Container(
                      width: 26,
                      height: 26,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(shape: BoxShape.circle, color: YColors.blue.withValues(alpha: .12)),
                      child: Text('${n + 1}', style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w800, fontSize: 12.5)),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr(s.$1), style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(tr(s.$2), style: TextStyle(color: hint, fontSize: 13)),
                      ]),
                    ),
                  ]),
                ),
              Align(alignment: Alignment.centerLeft, child: linkButton),
            ]),
          )),
        ] else ...[
          padded(SectionCard(
            title: 'Installation reliée',
            child: Wrap(spacing: 20, runSpacing: 16, children: [
              info('Installation', i['company_name'], icon: Icons.business_outlined),
              info('Type d\'installation', _targets[i['target']] ?? i['target'], icon: Icons.devices_outlined),
              info('Appareil', i['device'], icon: Icons.computer_outlined),
              info('Reliée le', i['activated_at'] == null ? null : dateTimeFr(i['activated_at']), icon: Icons.event_available_outlined),
              info('Dernière vérification', i['checked_at'] == null ? 'jamais' : dateTimeFr(i['checked_at']), icon: Icons.sync_rounded),
              info(
                'Fonctionne hors connexion jusqu\'au',
                validUntil == null ? null : '${dateTimeFr(i['valid_until'])}${offlineDays != null && offlineDays >= 0 ? ' ($offlineDays j)' : ''}',
                icon: Icons.cloud_off_outlined,
              ),
              info('Plateforme Yomara', i['server'], icon: Icons.dns_outlined),
            ]),
          )),
          const SizedBox(height: 12),
          padded(SectionCard(
            title: 'Modules de cette installation (${mods.length})',
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (mods.isEmpty)
                Text(tr(status == 'valid' ? 'Seules les fonctions de base gratuites sont actives pour l\'instant.' : 'Aucun module reçu pour le moment.'), style: TextStyle(color: hint))
              else
                for (var k = 0; k < mods.length; k++)
                  AnimatedFadeIn(
                    index: k,
                    child: ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.extension_outlined, color: YColors.blue),
                      title: Text(tr('${mods[k]['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      trailing: _expiry(mods[k]['expires_at']),
                    ),
                  ),
              if (!AppConfig.purchasesHidden) const Divider(height: 26),
              if (!AppConfig.purchasesHidden) Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.info_outline_rounded, size: 18, color: YColors.blue),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    tr('Les modules s\'achètent en ligne : cliquez « Acheter des modules », choisissez « Acheter pour : ${i['company_name'] ?? 'cette installation'} », puis payez. '
                        'Ils arrivent ici à la prochaine vérification automatique (toutes les 6 h) ou tout de suite avec « Vérifier maintenant ».'),
                    style: TextStyle(color: hint, fontSize: 13),
                  ),
                ),
              ]),
            ]),
          )),
        ],
      ]),
    );
  }

  Widget _expiry(Object? at) {
    final d = DateTime.tryParse('${at ?? ''}');
    if (d == null) return const StatusChip('Sans échéance', color: YColors.success, icon: Icons.all_inclusive_rounded);
    final days = d.difference(DateTime.now()).inDays;
    return StatusChip(
      days < 0 ? 'Expiré le ${dateFr(at)}' : 'Jusqu\'au ${dateFr(at)}',
      color: days < 0
          ? YColors.danger
          : days <= 7
              ? YColors.warning
              : YColors.blue,
      icon: Icons.event_outlined,
    );
  }
}

const _targets = {'server': 'Serveur web (hébergement)', 'windows': 'PC Windows', 'mac': 'Mac', 'linux': 'PC Linux'};

class _StatusHero extends StatelessWidget {
  const _StatusHero({required this.info});

  final Map<String, dynamic> info;

  @override
  Widget build(BuildContext context) {
    final status = '${info['status']}';
    final name = '${info['company_name'] ?? 'cette installation'}';
    final (List<Color> colors, IconData icon, String title, String message) = switch (status) {
      'free' => (
          const [Color(0xFF0B1B3A), Color(0xFF1F4FB8)],
          Icons.workspace_premium_outlined,
          'Version gratuite : les fonctions de base sont actives pour tous vos employés',
          'Ventes et factures, achats, stock, clients… sans clé ni abonnement. Pour ajouter des modules payants, reliez cette installation à votre compte Yomara.',
        ),
      'valid' => (
          const [Color(0xFF065F46), Color(0xFF0E9F6E)],
          Icons.verified_rounded,
          'Reliée à votre compte Yomara',
          'Cette installation (« $name ») reçoit les modules achetés pour elle. Elle se vérifie automatiquement toutes les 6 h et continue de fonctionner jusqu\'à 30 jours sans connexion.',
        ),
      'missing' => (
          const [Color(0xFF1E3A8A), Color(0xFF3B82F6)],
          Icons.sync_rounded,
          'Liaison en cours de vérification',
          'Cette installation est reliée à votre compte mais n\'a pas encore reçu ses modules. Cliquez « Vérifier maintenant ».',
        ),
      'expired' => (
          const [Color(0xFF92400E), Color(0xFFE8A317)],
          Icons.cloud_off_rounded,
          'Vérification en retard',
          'L\'installation n\'a pas pu joindre la plateforme Yomara depuis plus de 30 jours : les modules achetés sont en pause (les fonctions de base restent actives). Vérifiez la connexion Internet de cet appareil puis cliquez « Vérifier maintenant ».',
        ),
      'refused' => (
          const [Color(0xFF7F1D1D), Color(0xFFD93025)],
          Icons.link_off_rounded,
          'Liaison refusée',
          'La plateforme Yomara ne reconnaît plus cette installation (elle a peut-être été libérée ou reliée sur un autre appareil). Les fonctions de base restent actives. Reliez-la de nouveau à votre compte pour retrouver vos modules.',
        ),
      'suspended' => (
          const [Color(0xFF7F1D1D), Color(0xFFD93025)],
          Icons.pause_circle_outline,
          'Installation suspendue',
          'Cette installation a été suspendue par l\'éditeur : les modules achetés sont désactivés, les fonctions de base restent actives. Contactez le support pour la réactiver.',
        ),
      'revoked' => (
          const [Color(0xFF7F1D1D), Color(0xFFD93025)],
          Icons.block_rounded,
          'Installation retirée du compte',
          'Cette installation a été retirée de votre compte : les modules achetés sont désactivés, les fonctions de base restent actives. Reliez-la de nouveau à votre compte ou contactez le support.',
        ),
      _ => (
          const [Color(0xFF334155), Color(0xFF64748B)],
          Icons.help_outline_rounded,
          'État inconnu',
          'Cliquez « Vérifier maintenant » pour interroger la plateforme Yomara.',
        ),
    };
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: LinearGradient(colors: colors, begin: Alignment.topLeft, end: Alignment.bottomRight),
        boxShadow: [BoxShadow(color: colors.last.withValues(alpha: .25), blurRadius: 24, offset: const Offset(0, 10))],
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        TweenAnimationBuilder<double>(
          tween: Tween(begin: .6, end: 1),
          duration: const Duration(milliseconds: 600),
          curve: Curves.elasticOut,
          builder: (_, s, child) => Transform.scale(scale: s, child: child),
          child: Container(
            width: 60,
            height: 60,
            decoration: BoxDecoration(color: Colors.white.withValues(alpha: .16), borderRadius: BorderRadius.circular(18)),
            child: Icon(icon, color: Colors.white, size: 34),
          ),
        ),
        const SizedBox(width: 18),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(title), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w900, height: 1.2)),
            const SizedBox(height: 6),
            Text(tr(message), style: const TextStyle(color: Colors.white, height: 1.4)),
            if (info['next_expiry'] != null && status != 'free') ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: .16), borderRadius: BorderRadius.circular(20)),
                child: Text(tr('Prochaine échéance d\'un module : ${dateFr(info['next_expiry'])}'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 12.5)),
              ),
            ],
          ]),
        ),
      ]),
    );
  }
}
