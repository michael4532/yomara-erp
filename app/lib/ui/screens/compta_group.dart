part of 'compta_screen.dart';

// ---------------------------------------------------------------------------
// Consolidation : périmètre du groupe, code de rattachement, états consolidés
// ---------------------------------------------------------------------------

class _GroupTab extends StatefulWidget {
  const _GroupTab({required this.manage});

  final bool manage;

  @override
  State<_GroupTab> createState() => _GroupTabState();
}

class _GroupTabState extends State<_GroupTab> {
  Map<String, dynamic>? _g;
  Map<String, dynamic>? _s;
  Object? _error;
  bool _busy = false;
  bool _computing = false;
  Period? _period;
  String _view = 'sheet';
  List<Map> _adj = const [];
  final _code = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('compta/group');
      final g = asMap(r['data']);
      setState(() {
        _g = g;
        _error = null;
        _period ??= Period(DateTime.parse('${g['default_from']}'), DateTime.parse('${g['default_to']}'), 'Exercice');
      });
      if ((g['members'] as List).isNotEmpty) await _compute();
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _compute() async {
    setState(() => _computing = true);
    try {
      final r = await context.read<Session>().api.get('compta/consolidation', query: _period!.query);
      if (mounted) {
        setState(() {
          _s = asMap(r['data']);
          _adj = List<Map>.from(r['adjustments'] as List? ?? const []);
        });
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _computing = false);
    }
  }

  Future<void> _run(Future<void> Function() fn, [String? done]) async {
    setState(() => _busy = true);
    try {
      await fn();
      if (mounted && done != null) toast(context, done);
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickPeriod() async {
    final r = await showDateRangePicker(context: context, firstDate: DateTime(2015), lastDate: DateTime(2100), initialDateRange: DateTimeRange(start: _period!.from, end: _period!.to));
    if (r == null) return;
    setState(() => _period = Period(r.start, r.end, 'Période'));
    await _compute();
  }

  Future<void> _edit(Map m, {String? code}) async {
    final methods = asMap(_g!['methods']);
    var method = '${m['method'] ?? 'ig'}';
    String num0(Object? v) => v == null ? '' : '${toDouble(v) == toDouble(v).roundToDouble() ? toDouble(v).round() : v}';
    final ctl = {
      'interest_pct': TextEditingController(text: num0(m['interest_pct'] ?? 100)),
      'control_pct': TextEditingController(text: num0(m['control_pct'] ?? 100)),
      'investment_cost': TextEditingController(text: num0(m['investment_cost'])),
      'equity_at_acquisition': TextEditingController(text: num0(m['equity_at_acquisition'])),
      'rate': TextEditingController(text: m['rate'] == null ? '' : '${m['rate']}'),
      'average_rate': TextEditingController(text: m['average_rate'] == null ? '' : '${m['average_rate']}'),
      'historical_rate': TextEditingController(text: m['historical_rate'] == null ? '' : '${m['historical_rate']}'),
      'goodwill_years': TextEditingController(text: m['goodwill_years'] == null ? '' : '${m['goodwill_years']}'),
      'goodwill_impairment': TextEditingController(text: num0(toDouble(m['goodwill_impairment']) > 0 ? m['goodwill_impairment'] : null)),
    };
    String? acq = m['acquisition_date'] as String?;
    String? impDate = m['goodwill_impairment_date'] as String?;
    final foreign = m['currency'] != null && m['currency'] != _g!['currency'];
    final ok = await showFormDialog<bool>(context, title: code != null ? 'Rattacher la filiale' : '${m['name']}', builder: (dc) => StatefulBuilder(
        builder: (dc, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              DropdownButtonFormField<String>(
                initialValue: method,
                decoration: InputDecoration(labelText: tr('Méthode de consolidation')),
                items: [for (final e in methods.entries) DropdownMenuItem(value: e.key, child: Text(tr('${e.value}')))],
                onChanged: (v) => set(() => method = v ?? method),
              ),
              Text(
                tr(switch (method) {
                  'ig' => 'Contrôle exclusif (en général plus de 50 % des droits de vote) : comptes repris à 100 %, part des minoritaires isolée.',
                  'ip' => 'Contrôle conjoint : comptes repris au pourcentage d\'intérêts.',
                  _ => 'Influence notable (20 % à 50 %) : seuls les titres sont réévalués à la quote-part des capitaux propres.',
                }),
                style: TextStyle(fontSize: 12, color: Theme.of(dc).hintColor),
              ),
              Row(children: [
                Expanded(child: TextField(controller: ctl['interest_pct'], decoration: InputDecoration(labelText: tr('% d\'intérêts')))),
                const SizedBox(width: 12),
                Expanded(child: TextField(controller: ctl['control_pct'], decoration: InputDecoration(labelText: tr('% de contrôle')))),
              ]),
              TextField(controller: ctl['investment_cost'], decoration: InputDecoration(labelText: tr('Coût d\'acquisition des titres (chez la mère)'))),
              TextField(controller: ctl['equity_at_acquisition'], decoration: InputDecoration(labelText: tr('Capitaux propres de la filiale à l\'acquisition'), helperText: tr('Sert à calculer l\'écart d\'acquisition et les réserves consolidées.'))),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: () async {
                  final d = await showDatePicker(context: dc, firstDate: DateTime(1990), lastDate: DateTime(2100), initialDate: DateTime.tryParse(acq ?? '') ?? DateTime.now());
                  if (d != null) set(() => acq = isoDate(d));
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr(acq == null ? 'Date d\'acquisition' : 'Acquise le ${dateFr(acq)}')),
              ),
              if (method != 'mee') ...[
                const SizedBox(height: 8),
                Text(tr('Écart d\'acquisition (coût des titres − quote-part des capitaux propres acquis)'), style: TextStyle(fontSize: 12, color: Theme.of(dc).hintColor)),
                Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Expanded(child: TextField(controller: ctl['goodwill_years'], decoration: InputDecoration(labelText: tr('Amorti sur (années)'), helperText: tr('Vide : non amorti')))),
                  const SizedBox(width: 8),
                  Expanded(child: TextField(controller: ctl['goodwill_impairment'], decoration: InputDecoration(labelText: tr('Dépréciation'), helperText: tr('Perte de valeur constatée')))),
                ]),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () async {
                      final d = await showDatePicker(context: dc, firstDate: DateTime(1990), lastDate: DateTime(2100), initialDate: DateTime.tryParse(impDate ?? '') ?? DateTime.now());
                      if (d != null) set(() => impDate = isoDate(d));
                    },
                    icon: const Icon(Icons.event, size: 18),
                    label: Text(tr(impDate == null ? 'Date de la dépréciation' : 'Dépréciation au ${dateFr(impDate)}')),
                  ),
                ),
              ],
              if (foreign || code != null) ...[
                const SizedBox(height: 8),
                Text(tr('Conversion (1 ${m['currency'] ?? 'devise'} = ? ${_g!['currency']}) — laissez vide si même monnaie ou pour les cours du module Devises${m['suggested_rate'] != null && foreign ? ' (${m['suggested_rate']})' : ''}'),
                    style: TextStyle(fontSize: 12, color: Theme.of(dc).hintColor)),
                Row(children: [
                  Expanded(child: TextField(controller: ctl['rate'], decoration: InputDecoration(labelText: tr('Cours de clôture'), helperText: tr('Bilan')))),
                  const SizedBox(width: 8),
                  Expanded(child: TextField(controller: ctl['average_rate'], decoration: InputDecoration(labelText: tr('Cours moyen'), helperText: tr('Compte de résultat')))),
                  const SizedBox(width: 8),
                  Expanded(child: TextField(controller: ctl['historical_rate'], decoration: InputDecoration(labelText: tr('Cours historique'), helperText: tr('Capitaux propres')))),
                ]),
              ],
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr(code != null ? 'Rattacher' : 'Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true || !mounted) return;
    final body = <String, dynamic>{
      'method': method,
      for (final k in ['interest_pct', 'control_pct', 'investment_cost', 'equity_at_acquisition']) k: toDouble(ctl[k]!.text),
      'acquisition_date': acq,
      for (final k in ['rate', 'average_rate', 'historical_rate']) k: ctl[k]!.text.trim().isEmpty ? null : toDouble(ctl[k]!.text.replaceAll(',', '.')),
      'goodwill_years': ctl['goodwill_years']!.text.trim().isEmpty ? null : toDouble(ctl['goodwill_years']!.text).round(),
      'goodwill_impairment': toDouble(ctl['goodwill_impairment']!.text),
      'goodwill_impairment_date': impDate,
    };
    final api = context.read<Session>().api;
    if (code != null) {
      await _run(() => api.post('compta/group/members', {'code': code, ...body}), 'Filiale rattachée au groupe.');
      _code.clear();
    } else {
      await _run(() => api.put('compta/group/members/${m['id']}', body), 'Paramètres de consolidation enregistrés.');
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _g == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_g == null) return const LoadingSkeleton();
    final g = _g!;
    final hint = Theme.of(context).hintColor;
    final members = List<Map>.from(g['members'] as List);
    final parent = g['parent'] as Map?;
    final link = g['link_code'] as Map?;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final api = context.read<Session>().api;

    return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
      if (parent != null)
        SectionCard(
          child: Row(children: [
            const Icon(Icons.account_tree_outlined, color: YColors.blue),
            const SizedBox(width: 12),
            Expanded(child: Text(tr('Société consolidée par ${parent['name']} (${parent['method']}, ${toDouble(parent['interest_pct']).toStringAsFixed(0)} % d\'intérêts).'), style: const TextStyle(fontWeight: FontWeight.w700))),
            if (widget.manage)
              TextButton(
                onPressed: _busy
                    ? null
                    : () async {
                        if (await confirm(context, tr('Quitter le groupe ?'), tr('${parent['name']} ne pourra plus consolider vos comptes.'), ok: tr('Se détacher'))) {
                          await _run(() => api.delete('compta/group/members/${parent['id']}'), 'Société détachée du groupe.');
                        }
                      },
                child: Text(tr('Se détacher')),
              ),
          ]),
        ),
      if (parent != null) const SizedBox(height: 12),
      // Périmètre
      SectionCard(
        title: tr('Périmètre de consolidation'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(
            tr('Cette société est la mère du groupe. Chaque filiale (gérée dans Yomara) génère un code de rattachement dans son propre onglet Consolidation ; saisissez-le ici. Les comptes de chaque filiale sont traduits dans votre plan comptable et convertis dans votre monnaie.'),
            style: TextStyle(color: hint, fontSize: 12.5),
          ),
          const SizedBox(height: 10),
          for (final m in members)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: CircleAvatar(backgroundColor: YColors.blue.withValues(alpha: .12), child: Text(tr('${toDouble(m['current_pct'] ?? m['interest_pct']).round()}%'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: YColors.blue))),
              title: Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(tr([
                '${m['method_label']}',
                if (m['ncc'] != null) 'NCC ${m['ncc']}',
                '${m['referential']} · ${m['currency']}',
                if (toDouble(m['current_cost'] ?? m['investment_cost']) > 0) 'titres ${_m(context, m['current_cost'] ?? m['investment_cost'])}',
                if ((m['stakes'] as List? ?? const []).isNotEmpty) '${(m['stakes'] as List).length} achat(s) / cession(s) de titres',
                if (m['exit_date'] != null) 'sortie le ${dateFr(m['exit_date'])}',
                if (m['fiscal_start_month'] != null && m['fiscal_start_month'] != 1) 'exercice décalé',
              ].join(' · '))),
              trailing: widget.manage
                  ? Wrap(children: [
                      IconButton(tooltip: tr('Paramètres'), onPressed: _busy ? null : () => _edit(m), icon: const Icon(Icons.tune)),
                      IconButton(tooltip: tr('Achats, cessions et sortie'), onPressed: _busy ? null : () => _stakes(m), icon: const Icon(Icons.swap_vert)),
                      IconButton(
                        tooltip: tr('Retirer du périmètre'),
                        onPressed: _busy
                            ? null
                            : () async {
                                if (await confirm(context, tr('Retirer ${m['name']} ?'), tr('La société ne sera plus consolidée. Ses comptes ne sont pas modifiés.'), ok: tr('Retirer'))) {
                                  await _run(() => api.delete('compta/group/members/${m['id']}'), 'Société retirée du périmètre.');
                                  setState(() => _s = null);
                                }
                              },
                        icon: const Icon(Icons.link_off),
                      ),
                    ])
                  : null,
            ),
          if (members.isEmpty) Text(tr('Aucune filiale rattachée.'), style: TextStyle(color: hint)),
          if (widget.manage && parent == null) ...[
            const Divider(height: 24),
            Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
              SizedBox(width: 220, child: TextField(controller: _code, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(isDense: true, labelText: tr('Code de la filiale'), hintText: tr('XXXX-XXXX')))),
              FilledButton.icon(
                onPressed: _busy ? null : () => _code.text.trim().isEmpty ? toast(context, tr('Saisissez le code communiqué par la filiale.'), error: true) : _edit(const {}, code: _code.text.trim()),
                icon: const Icon(Icons.add_link),
                label: Text(tr('Rattacher une filiale')),
              ),
            ]),
          ],
        ]),
      ),
      const SizedBox(height: 12),
      if (widget.manage && members.isEmpty)
        SectionCard(
          title: tr('Vous êtes une filiale ?'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('Générez un code et transmettez-le au comptable de la société mère : il lui permet de lire vos comptes pour la consolidation (valable 7 jours, usage unique).'), style: TextStyle(color: hint, fontSize: 12.5)),
            const SizedBox(height: 10),
            Wrap(spacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
              if (link != null) SelectableText(tr('${link['code']}'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: 2)),
              if (link != null) Text(tr('expire le ${dateFr('${link['expires_at']}'.substring(0, 10))}'), style: TextStyle(color: hint, fontSize: 12)),
              OutlinedButton.icon(
                onPressed: _busy ? null : () => _run(() => api.post('compta/group/link-code', const {}), 'Code généré.'),
                icon: const Icon(Icons.key),
                label: Text(tr(link == null ? 'Générer un code de rattachement' : 'Nouveau code')),
              ),
            ]),
          ]),
        ),
      if (members.isNotEmpty) ..._statements(context),
    ]);
  }

  /// Historique des titres : achats complémentaires, cessions partielles, sortie du périmètre.
  Future<void> _stakes(Map m0) async {
    var m = m0;
    final api = context.read<Session>().api;
    await showDialog<void>(
      context: context,
      builder: (dc) => StatefulBuilder(builder: (dc, set) {
        final hint = Theme.of(dc).hintColor;
        final stakes = List<Map>.from(m['stakes'] as List? ?? const []);
        Future<void> refresh(Future<dynamic> f) async {
          try {
            final r = await f;
            set(() => m = asMap(r['data']));
          } catch (e) {
            if (dc.mounted) toast(dc, errorText(e), error: true);
          }
        }

        Future<void> addStake(bool buy) async {
          final pct = TextEditingController();
          final amount = TextEditingController();
          final basis = TextEditingController();
          final note = TextEditingController();
          String date = isoDate(DateTime.now());
          final ok = await showFormDialog<bool>(dc, title: buy ? 'Achat de titres complémentaires' : 'Cession partielle de titres', builder: (d2) => StatefulBuilder(
              builder: (d2, set2) => SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
                  Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    OutlinedButton.icon(
                      onPressed: () async {
                        final d = await showDatePicker(context: d2, firstDate: DateTime(1990), lastDate: DateTime(2100), initialDate: DateTime.parse(date));
                        if (d != null) set2(() => date = isoDate(d));
                      },
                      icon: const Icon(Icons.event, size: 18),
                      label: Text(tr('Le ${dateFr(date)}')),
                    ),
                    TextField(controller: pct, decoration: InputDecoration(labelText: trn(buy ? 'Points de pourcentage achetés' : 'Points de pourcentage cédés'))),
                    TextField(controller: amount, decoration: InputDecoration(labelText: trn(buy ? 'Prix payé' : 'Prix de cession'))),
                    if (!buy) TextField(controller: basis, decoration: InputDecoration(labelText: tr('Coût des titres cédés'), helperText: tr('Vide : quote-part du coût moyen des titres'))),
                    TextField(controller: note, decoration: InputDecoration(labelText: tr('Note (facultatif)'))),
                    const SizedBox(height: 8),
                    Text(
                      tr(buy
                          ? 'Passez l\'achat dans les comptes de la mère (titres de participation contre banque). L\'écart entre le prix et la part des capitaux propres achetée va aux réserves consolidées.'
                          : 'Passez la cession dans les comptes de la mère (produit de cession 82 et valeur des titres cédés 81). Sans perte de contrôle, le résultat de cession est reclassé en capitaux propres.'),
                      style: TextStyle(fontSize: 12, color: Theme.of(d2).hintColor),
                    ),
                  ]),
                  const SizedBox(height: 20),
                  Align(
                    alignment: Alignment.centerRight,
                    child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                      TextButton(onPressed: () => Navigator.pop(d2, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d2, true), child: Text(tr('Enregistrer'))),
                    ]),
                  ),
                ]),
              ),
            ));
          if (ok != true) return;
          final v = toDouble(pct.text.replaceAll(',', '.')).abs();
          await refresh(api.post('compta/group/members/${m['id']}/stakes', {
            'date': date,
            'change_pct': buy ? v : -v,
            'amount': toDouble(amount.text),
            if (!buy && basis.text.trim().isNotEmpty) 'cost_basis': toDouble(basis.text),
            if (note.text.trim().isNotEmpty) 'note': note.text.trim(),
          }));
        }

        Future<void> exit() async {
          final proceeds = TextEditingController(text: m['exit_proceeds'] == null ? '' : '${toDouble(m['exit_proceeds']).round()}');
          String date = '${m['exit_date'] ?? isoDate(DateTime.now())}';
          final ok = await showDialog<String>(
            context: dc,
            builder: (d2) => StatefulBuilder(
              builder: (d2, set2) => YAlertDialog(
                title: Text(tr('Sortie du périmètre')),
                content: SizedBox(
                  width: 440,
                  child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    Text(tr('Cession de la totalité des titres restants. Le résultat de la filiale est intégré jusqu\'à cette date, puis elle sort du bilan ; la plus-value consolidée remplace celle de la mère.'),
                        style: TextStyle(fontSize: 12, color: Theme.of(d2).hintColor)),
                    OutlinedButton.icon(
                      onPressed: () async {
                        final d = await showDatePicker(context: d2, firstDate: DateTime(1990), lastDate: DateTime(2100), initialDate: DateTime.parse(date));
                        if (d != null) set2(() => date = isoDate(d));
                      },
                      icon: const Icon(Icons.event, size: 18),
                      label: Text(tr('Sortie le ${dateFr(date)}')),
                    ),
                    TextField(controller: proceeds, decoration: InputDecoration(labelText: tr('Prix de cession des titres'))),
                  ]),
                ),
                actions: [
                  if (m['exit_date'] != null) TextButton(onPressed: () => Navigator.pop(d2, 'cancel'), child: Text(tr('Annuler la sortie'))),
                  TextButton(onPressed: () => Navigator.pop(d2), child: Text(tr('Fermer'))),
                  FilledButton(onPressed: () => Navigator.pop(d2, 'save'), child: Text(tr('Enregistrer'))),
                ],
              ),
            ),
          );
          if (ok == null) return;
          await refresh(api.put('compta/group/members/${m['id']}', ok == 'save' ? {'exit_date': date, 'exit_proceeds': toDouble(proceeds.text)} : {'exit_date': null, 'exit_proceeds': null}));
        }

        return YAlertDialog(
          title: Text(tr('Titres de ${m['name']}')),
          content: SizedBox(
            width: 560,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.flag_outlined),
                  title: Text(tr('Acquisition${m['acquisition_date'] != null ? ' le ${dateFr(m['acquisition_date'])}' : ''} : ${toDouble(m['interest_pct']).toStringAsFixed(2)} %')),
                  subtitle: Text(tr('Coût ${_m(dc, m['investment_cost'])}')),
                ),
                for (final x in stakes)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(toDouble(x['change_pct']) > 0 ? Icons.add_circle_outline : Icons.remove_circle_outline, color: toDouble(x['change_pct']) > 0 ? YColors.success : YColors.warning),
                    title: Text(tr('${dateFr(x['date'])} : ${toDouble(x['change_pct']) > 0 ? 'achat' : 'cession'} de ${toDouble(x['change_pct']).abs().toStringAsFixed(2)} %, détention portée à ${toDouble(x['pct_after']).toStringAsFixed(2)} %')),
                    subtitle: Text(tr([
                      '${toDouble(x['change_pct']) > 0 ? 'prix payé' : 'prix de cession'} ${_m(dc, x['amount'])}',
                      if (x['cost_basis'] != null) 'coût des titres cédés ${_m(dc, x['cost_basis'])}${x['cost_basis_entered'] == true ? '' : ' (coût moyen)'}',
                      if (x['note'] != null) '${x['note']}',
                    ].join(' · '))),
                    trailing: IconButton(tooltip: tr('Supprimer'), onPressed: () => refresh(api.delete('compta/group/stakes/${x['id']}')), icon: const Icon(Icons.delete_outline)),
                  ),
                if (m['exit_date'] != null)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.logout, color: YColors.danger),
                    title: Text(tr('Sortie du périmètre le ${dateFr(m['exit_date'])}')),
                    subtitle: Text(tr('Prix de cession ${_m(dc, m['exit_proceeds'])}')),
                  ),
                const Divider(),
                Text(tr('Pourcentage actuel : ${toDouble(m['current_pct']).toStringAsFixed(2)} % · coût des titres ${_m(dc, m['current_cost'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                Text(tr('Le résultat de chaque période est réparti selon le pourcentage détenu à chaque date.'), style: TextStyle(fontSize: 12, color: hint)),
              ]),
            ),
          ),
          actions: [
            TextButton.icon(onPressed: () => addStake(true), icon: const Icon(Icons.add), label: Text(tr('Achat'))),
            TextButton.icon(onPressed: () => addStake(false), icon: const Icon(Icons.remove), label: Text(tr('Cession partielle'))),
            TextButton.icon(onPressed: exit, icon: const Icon(Icons.logout), label: Text(tr('Sortie'))),
            FilledButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Fermer'))),
          ],
        );
      }),
    );
    if (mounted) await _load();
  }

  Future<void> _freeze(bool on) async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post(on ? 'compta/consolidation/freeze' : 'compta/consolidation/unfreeze', _period!.query);
      if (!mounted) return;
      setState(() {
        _s = asMap(r['data']);
        _adj = List<Map>.from(r['adjustments'] as List? ?? const []);
      });
      toast(context, on ? 'Comptes consolidés arrêtés.' : 'Comptes consolidés rouverts : calcul à jour.');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _saveAdj(Map<String, dynamic> body) async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('compta/group/adjustments', {'to': isoDate(_period!.to), ...body});
      if (mounted) toast(context, tr('Retraitement enregistré.'));
      await _compute();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _addAdjustment(String kind) async {
    final companies = List<Map>.from(_g!['companies'] as List? ?? const []);
    final label = TextEditingController();
    final amount = TextEditingController();
    final margin = TextEditingController();
    final lines = [for (var i = 0; i < 2; i++) (TextEditingController(), TextEditingController(), TextEditingController())];
    int? seller = companies.length > 1 ? companies[1]['id'] as int : null;
    int? buyer = companies.isNotEmpty ? companies[0]['id'] as int : null;
    final ok = await showFormDialog<bool>(context, title: switch (kind) { 'stock_margin' => 'Marge interne sur stocks', 'dividend' => 'Dividendes internes', _ => 'Écriture de consolidation' }, builder: (dc) => StatefulBuilder(
        builder: (dc, set) {
          Widget pick(String lbl, int? v, void Function(int?) on) => DropdownButtonFormField<int>(
                initialValue: v,
                decoration: InputDecoration(labelText: trn(lbl)),
                items: [for (final x in companies) DropdownMenuItem(value: x['id'] as int, child: Text(tr('${x['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: (x) => set(() => on(x)),
              );
          final hint = Theme.of(dc).hintColor;
          return SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
              Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (kind == 'stock_margin') ...[
                  Text(tr('Marchandises achetées à une société du groupe et encore en stock à la clôture : la marge réalisée par le vendeur est annulée (variation de stocks / stock).'), style: TextStyle(fontSize: 12, color: hint)),
                  pick('Société qui a vendu', seller, (x) => seller = x),
                  pick('Société qui détient le stock', buyer, (x) => buyer = x),
                  TextField(controller: amount, decoration: InputDecoration(labelText: tr('Valeur du stock chez l\'acheteur (dans sa monnaie)'))),
                  TextField(controller: margin, decoration: InputDecoration(labelText: tr('Taux de marge du vendeur (%)'), helperText: tr('Vide : marge commerciale du vendeur sur l\'exercice'))),
                ],
                if (kind == 'dividend') ...[
                  Text(tr('Dividendes versés entre sociétés du groupe sans tiers renseigné sur l\'écriture (sinon ils sont éliminés automatiquement) : le produit est annulé et les réserves reconstituées.'), style: TextStyle(fontSize: 12, color: hint)),
                  pick('Société qui a versé', seller, (x) => seller = x),
                  pick('Société qui a reçu', buyer, (x) => buyer = x),
                  TextField(controller: amount, decoration: InputDecoration(labelText: tr('Montant reçu (monnaie de la société qui a reçu)'))),
                ],
                if (kind == 'entry') ...[
                  Text(tr('Comptes du plan de la société mère. L\'écriture ne touche que les comptes consolidés, jamais les comptes des sociétés.'), style: TextStyle(fontSize: 12, color: hint)),
                  for (var i = 0; i < lines.length; i++)
                    Row(children: [
                      SizedBox(width: 110, child: TextField(controller: lines[i].$1, decoration: InputDecoration(isDense: true, labelText: tr('Compte')))),
                      const SizedBox(width: 8),
                      Expanded(child: TextField(controller: lines[i].$2, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Débit')))),
                      const SizedBox(width: 8),
                      Expanded(child: TextField(controller: lines[i].$3, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, labelText: tr('Crédit')))),
                    ]),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(onPressed: () => set(() => lines.add((TextEditingController(), TextEditingController(), TextEditingController()))), icon: const Icon(Icons.add, size: 18), label: Text(tr('Ligne'))),
                  ),
                ],
                TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé (facultatif)'))),
              ]),
              const SizedBox(height: 20),
              Align(
                alignment: Alignment.centerRight,
                child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                  TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer'))),
                ]),
              ),
            ]),
          );
        },
      ));
    if (ok != true || !mounted) return;
    await _saveAdj({
      'kind': kind,
      if (label.text.trim().isNotEmpty) 'label': label.text.trim(),
      if (kind != 'entry') ...{'seller_id': seller, 'buyer_id': buyer, 'amount': toDouble(amount.text)},
      if (kind == 'stock_margin' && margin.text.trim().isNotEmpty) 'margin_pct': toDouble(margin.text.replaceAll(',', '.')),
      if (kind == 'entry') 'lines': [for (final l in lines) if (l.$1.text.trim().isNotEmpty) {'account': l.$1.text.trim(), 'debit': toDouble(l.$2.text), 'credit': toDouble(l.$3.text)}],
    });
  }

  Future<void> _editTaxRate() async {
    final ctl = TextEditingController(text: '${_g!['deferred_tax_rate']}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (dc) => YAlertDialog(
        title: Text(tr('Taux d\'impôt différé')),
        content: TextField(controller: ctl, decoration: InputDecoration(labelText: tr('Taux (%)'), helperText: tr('Vide : taux de l\'impôt sur les bénéfices de la société mère'))),
        actions: [TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok != true || !mounted) return;
    await _run(() => context.read<Session>().api.put('compta/group/settings', {'deferred_tax_rate': ctl.text.trim().isEmpty ? null : toDouble(ctl.text.replaceAll(',', '.'))}), 'Taux enregistré.');
  }

  Widget _adjustmentsCard(BuildContext c, bool frozen) {
    final hint = Theme.of(c).hintColor;
    final canEdit = widget.manage && !frozen;
    return SectionCard(
      title: tr('Retraitements saisis'),
      trailing: canEdit
          ? PopupMenuButton<String>(
              tooltip: tr('Ajouter un retraitement'),
              onSelected: _addAdjustment,
              itemBuilder: (_) => [
                PopupMenuItem(value: 'stock_margin', child: Text(tr('Marge interne sur stocks'))),
                PopupMenuItem(value: 'dividend', child: Text(tr('Dividendes internes'))),
                PopupMenuItem(value: 'entry', child: Text(tr('Écriture de consolidation'))),
              ],
              child: Padding(padding: EdgeInsets.all(8), child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.add, size: 18), SizedBox(width: 4), Text(tr('Ajouter'))])),
            )
          : null,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(tr('Opérations réciproques, titres, minoritaires, conversion, dividendes portant le tiers, écarts d\'acquisition et reprise des marges de la période précédente sont calculés automatiquement. Ajoutez ici les marges internes sur stocks et vos écritures de consolidation de la période.'),
            style: TextStyle(color: hint, fontSize: 12.5)),
        const SizedBox(height: 6),
        Row(children: [
          Text(tr('Impôt différé sur les retraitements : ${_g!['deferred_tax_rate']} %'), style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
          if (canEdit) TextButton(onPressed: _busy ? null : _editTaxRate, child: Text(tr('Modifier'))),
        ]),
        for (final a in _adj)
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: Icon(switch ('${a['kind']}') { 'stock_margin' => Icons.inventory_2_outlined, 'dividend' => Icons.payments_outlined, _ => Icons.edit_note }),
            title: Text(tr('${a['label']}')),
            subtitle: Text(tr(switch ('${a['kind']}') {
              'stock_margin' => 'Stock ${_m(c, a['amount'])} × marge ${a['margin_pct']} %',
              'dividend' => 'Dividende ${_m(c, a['amount'])}',
              _ => [for (final l in List.from(a['lines'] as List)) '${l[0]} ${toDouble(l[1]) > 0 ? 'D ${_m(c, l[1])}' : 'C ${_m(c, l[2])}'}'].join(' · '),
            })),
            trailing: canEdit
                ? IconButton(
                    tooltip: tr('Supprimer'),
                    onPressed: _busy
                        ? null
                        : () async {
                            try {
                              await context.read<Session>().api.delete('compta/group/adjustments/${a['id']}');
                              await _compute();
                            } catch (e) {
                              if (c.mounted) toast(c, errorText(e), error: true);
                            }
                          },
                    icon: const Icon(Icons.delete_outline),
                  )
                : null,
          ),
        if (_adj.isEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Aucun retraitement saisi.'), style: TextStyle(color: hint))),
      ]),
    );
  }

  List<Widget> _statements(BuildContext c) {
    final hint = Theme.of(c).hintColor;
    final s = _s;
    final head = Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
      Text(tr('États consolidés'), style: Theme.of(c).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
      ActionChip(avatar: const Icon(Icons.calendar_month, size: 18), label: Text(tr('du ${dateFr(isoDate(_period!.from))} au ${dateFr(isoDate(_period!.to))}')), onPressed: _pickPeriod),
      IconButton(tooltip: tr('Recalculer'), onPressed: _computing ? null : _compute, icon: const Icon(Icons.refresh)),
      if (s != null) ...[
        OutlinedButton.icon(
          onPressed: () => _openServerPdf(c, 'compta/consolidation/pdf', 'Comptes consolidés', query: _period!.query),
          icon: const Icon(Icons.picture_as_pdf_outlined, size: 18),
          label: Text(tr('Liasse PDF')),
        ),
        OutlinedButton.icon(
          onPressed: () => _saveServerXlsx(c, 'compta/consolidation/xlsx', 'consolidation_${isoDate(_period!.to)}.xlsx', query: _period!.query),
          icon: const Icon(Icons.grid_on_outlined, size: 18),
          label: Text(tr('Excel')),
        ),
        if (s['frozen'] != null) StatusChip(tr('Arrêtés le ${dateFr('${(s['frozen'] as Map)['at']}'.substring(0, 10))}'), color: YColors.success),
        if (widget.manage && s['frozen'] == null)
          FilledButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    if (await confirm(c, tr('Arrêter les comptes consolidés ?'), tr('Les états de la période sont figés tels qu\'affichés : les écritures passées ensuite chez les sociétés du groupe ne les modifient plus, et les retraitements sont verrouillés.'), ok: tr('Arrêter'))) {
                      await _freeze(true);
                    }
                  },
            icon: const Icon(Icons.lock_outline, size: 18),
            label: Text(tr('Arrêter les comptes')),
          ),
        if (widget.manage && s['frozen'] != null)
          TextButton.icon(onPressed: _busy ? null : () => _freeze(false), icon: const Icon(Icons.lock_open, size: 18), label: Text(tr('Rouvrir'))),
      ],
    ]);
    if (s == null) return [head, const SizedBox(height: 12), if (_computing) const LoadingSkeleton()];
    final sum = asMap(s['summary']);
    final sheet = asMap(s['sheet']);
    final income = asMap(s['income']);
    Widget kpi(String label, Object? v, {Color? color}) => SizedBox(
          width: 210,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr(label), style: TextStyle(color: hint, fontSize: 12)),
                const SizedBox(height: 4),
                Text(tr(_m(c, toDouble(v))), style: _num.copyWith(fontWeight: FontWeight.w800, fontSize: 16, color: color)),
              ]),
            ),
          ),
        );
    final perimeter = List<Map>.from(s['perimeter'] as List);
    final ok = sheet['balanced'] == true;
    return [
      head,
      const SizedBox(height: 10),
      Wrap(spacing: 8, runSpacing: 8, children: [
        kpi('Résultat consolidé', sum['result'], color: toDouble(sum['result']) >= 0 ? YColors.success : YColors.danger),
        kpi('Part du groupe', sum['group_result']),
        kpi('Part des minoritaires', sum['minority_result']),
        kpi('Capitaux propres part du groupe', sum['group_equity']),
        kpi('Intérêts minoritaires', sum['minority_interests_total'] ?? sum['minority_interests']),
        kpi('Écarts d\'acquisition', sum['goodwill']),
        kpi('Ventes internes éliminées', sum['intercompany_sales']),
        kpi('Créances internes éliminées', sum['intercompany_receivables']),
        if (toDouble(sum['translation_difference']) != 0) kpi('Écarts de conversion (groupe)', sum['translation_difference']),
        if (toDouble(sum['intercompany_dividends']) != 0) kpi('Dividendes internes éliminés', sum['intercompany_dividends']),
        if (toDouble(sum['stock_margin']) != 0) kpi('Marges internes sur stocks', sum['stock_margin']),
        if (toDouble(sum['deferred_tax']) != 0) kpi('Impôts différés actifs', sum['deferred_tax']),
        if (toDouble(sum['goodwill_charge']) != 0) kpi('Amort. / dépréc. écarts d\'acquisition', sum['goodwill_charge']),
      ]),
      for (final w in List.from(s['warnings'] as List))
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(children: [const Icon(Icons.warning_amber_rounded, color: YColors.warning, size: 18), const SizedBox(width: 8), Expanded(child: Text(tr('$w'), style: const TextStyle(fontSize: 12.5)))]),
        ),
      const SizedBox(height: 12),
      _StatementTable(
        headers: const ['', 'Société', 'Méthode', '% intérêts', 'Capitaux propres', 'Résultat'],
        flex: const [2, 5, 3, 2, 3, 3],
        rows: [
          for (final p in perimeter)
            (ref: '${p['referential']}', label: '${p['name']}${p['currency'] != s['currency'] ? ' (${p['currency']} × ${p['rate']})' : ''}${p['exit_date'] != null ? ' — sortie le ${dateFr(p['exit_date'])}' : ''}', values: ['${p['method_label']}', '${p['interest_pct']} %', _m(c, p['equity']), _m(c, p['result'])], total: false),
        ],
      ),
      const SizedBox(height: 12),
      _adjustmentsCard(c, s['frozen'] != null),
      const SizedBox(height: 12),
      SegmentedButton<String>(
        segments: [
          ButtonSegment(value: 'sheet', label: Text(tr('Bilan'))),
          ButtonSegment(value: 'income', label: Text(tr('Compte de résultat'))),
          ButtonSegment(value: 'cash', label: Text(tr('Flux'))),
          ButtonSegment(value: 'equity', label: Text(tr('Capitaux propres'))),
          ButtonSegment(value: 'adjust', label: Text(tr('Retraitements'))),
        ],
        showSelectedIcon: false,
        selected: {_view},
        onSelectionChanged: (v) => setState(() => _view = v.first),
      ),
      const SizedBox(height: 10),
      if (_view == 'sheet') ...[
        Text(tr('ACTIF'), style: TextStyle(fontWeight: FontWeight.w800, color: hint, letterSpacing: 1)),
        const SizedBox(height: 6),
        _StatementTable(
          headers: const ['Réf.', 'Libellé', 'Brut', 'Amort. / dépréc.', 'Net N', 'Net N-1'],
          flex: const [1, 6, 2, 2, 2, 2],
          rows: [
            for (final x in List<Map>.from(sheet['assets'] as List))
              (ref: '${x['ref']}', label: '${x['label']}', values: [_m(c, x['gross']), _m(c, x['amort']), _m(c, x['net']), _m(c, x['n1'])], total: x['total'] == true),
          ],
        ),
        const SizedBox(height: 16),
        Text(tr('PASSIF'), style: TextStyle(fontWeight: FontWeight.w800, color: hint, letterSpacing: 1)),
        const SizedBox(height: 6),
        _StatementTable(
          headers: const ['Réf.', 'Libellé', 'Net N', 'Net N-1'],
          rows: [for (final x in List<Map>.from(sheet['liabilities'] as List)) (ref: '${x['ref']}', label: '${x['label']}', values: [_m(c, x['n']), _m(c, x['n1'])], total: x['total'] == true)],
        ),
        const SizedBox(height: 8),
        Row(children: [
          Icon(ok ? Icons.check_circle : Icons.error_outline, color: ok ? YColors.success : YColors.danger, size: 18),
          const SizedBox(width: 8),
          Expanded(child: Text(tr(ok ? 'Bilan consolidé équilibré : ${_m(c, sheet['total_assets'])}' : 'Bilan consolidé déséquilibré : actif ${_m(c, sheet['total_assets'])}, passif ${_m(c, sheet['total_liabilities'])}'))),
        ]),
      ],
      if (_view == 'income')
        _StatementTable(
          headers: const ['Réf.', 'Libellé', 'Exercice N', 'Exercice N-1'],
          rows: [
            for (final x in List<Map>.from(income['rows'] as List))
              (ref: '${x['ref']}', label: '${x['sign'] == '-' ? '(−) ' : ''}${x['label']}', values: [_m(c, x['n']), _m(c, x['n1'])], total: x['total'] == true),
          ],
        ),
      if (_view == 'cash' && s['cashflow'] is Map)
        _StatementTable(
          headers: const ['Réf.', 'Flux de trésorerie consolidés', 'Montant'],
          flex: const [1, 8, 3],
          rows: [
            for (final x in List<Map>.from((s['cashflow'] as Map)['rows'] as List))
              (ref: '${x['ref']}', label: '${x['label']}', values: [x['amount'] == null ? '' : _m(c, x['amount'])], total: x['total'] == true),
          ],
        ),
      if (_view == 'cash' && s['cashflow'] is! Map) Text(tr('Tableau des flux non disponible pour ce référentiel.'), style: TextStyle(color: hint)),
      if (_view == 'equity')
        LayoutBuilder(builder: (ctx, cons) {
          final table = _StatementTable(
            headers: const ['', 'Variation des capitaux propres', 'Capital', 'Réserves', 'Conversion', 'Part du groupe', 'Minoritaires', 'Total'],
            flex: const [1, 7, 3, 3, 3, 3, 3, 3],
            rows: [
              for (final x in List<Map>.from((s['equity_changes'] as Map)['rows'] as List))
                (ref: '', label: '${x['label']}', values: [for (final k in ['capital', 'reserves', 'translation', 'group', 'minority', 'total']) _m(c, x[k])], total: x['is_total'] == true),
            ],
          );
          return cons.maxWidth >= 1000 ? table : SingleChildScrollView(scrollDirection: Axis.horizontal, child: SizedBox(width: 1000, child: table));
        }),
      if (_view == 'adjust')
        for (final a in List<Map>.from(s['adjustments'] as List))
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: _StatementTable(
              headers: ['', '${a['label']}', 'Débit', 'Crédit'],
              rows: [
                for (final l in List<Map>.from(a['lines'] as List))
                  (ref: '', label: tr('Compte ${l['account']}'), values: [toDouble(l['debit']) > 0 ? _m(c, l['debit']) : '', toDouble(l['credit']) > 0 ? _m(c, l['credit']) : ''], total: false),
              ],
            ),
          ),
      if (_view == 'adjust' && (s['adjustments'] as List).isEmpty) Text(tr('Aucun retraitement.'), style: TextStyle(color: hint)),
    ];
  }
}
