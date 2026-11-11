part of 'compta_screen.dart';

// ---------------------------------------------------------------------------
// Fin d'exercice : contrôles, impôt, clôture, affectation du résultat, liasse (DSF)
// ---------------------------------------------------------------------------

Future<void> _openServerPdf(BuildContext c, String path, String name, {Map<String, String>? query}) async {
  try {
    final bytes = await c.read<Session>().api.download(path, query: query);
    await previewPdf(name: name, onLayout: (_) async => Uint8List.fromList(bytes));
  } catch (e) {
    if (c.mounted) toast(c, errorText(e), error: true);
  }
}

Future<void> _saveServerXlsx(BuildContext c, String path, String name, {Map<String, String>? query}) async {
  try {
    final bytes = await c.read<Session>().api.download(path, query: query);
    final where = await saveFile(name, bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    if (c.mounted) toast(c, tr('Fichier Excel enregistré${where.isEmpty ? '' : ' : $where'}'));
  } catch (e) {
    if (c.mounted) toast(c, errorText(e), error: true);
  }
}

class _ClosingTab extends StatefulWidget {
  const _ClosingTab({required this.manage, required this.onOpen, super.key});

  final bool manage;
  final void Function(String) onOpen;

  @override
  State<_ClosingTab> createState() => _ClosingTabState();
}

class _ClosingTabState extends State<_ClosingTab> {
  List<Map>? _years;
  Map<String, dynamic>? _settings;
  int? _year;
  Map<String, dynamic>? _d;
  Object? _error;
  bool _busy = false;
  final List<(TextEditingController, TextEditingController)> _reint = [];
  final List<(TextEditingController, TextEditingController)> _deduc = [];
  final _alloc = {for (final k in ['legal_reserve', 'other_reserves', 'dividends', 'report']) k: TextEditingController()};
  String? _allocDate;

  @override
  void initState() {
    super.initState();
    _loadYears();
  }

  Future<void> _loadYears() async {
    try {
      final r = await context.read<Session>().api.get('compta/years');
      final years = List<Map>.from(r['data'] as List);
      if (!mounted) return;
      setState(() {
        _years = years;
        _settings = asMap(r['fiscal_settings']);
        // Par défaut : le dernier exercice terminé et non clôturé, sinon le dernier terminé
        _year ??= (years.where((y) => y['finished'] == true && y['status'] != 'closed').lastOrNull ??
            years.where((y) => y['finished'] == true).firstOrNull ??
            years.first)['year'] as int;
      });
      await _load();
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('compta/years/$_year');
      _fill(asMap(r['data']));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _fill(Map<String, dynamic> d) {
    setState(() {
      _d = d;
      _error = null;
      final f = asMap(d['fiscal']);
      _reint
        ..clear()
        ..addAll([for (final x in f['reintegrations'] as List) (TextEditingController(text: '${(x as Map)['label']}'), TextEditingController(text: '${toDouble(x['amount']).round()}'))]);
      _deduc
        ..clear()
        ..addAll([for (final x in f['deductions'] as List) (TextEditingController(text: '${(x as Map)['label']}'), TextEditingController(text: '${toDouble(x['amount']).round()}'))]);
      final a = asMap(d['allocation']);
      final src = a['allocation'] is Map ? a['allocation'] as Map : (a['proposal'] as Map? ?? const {});
      for (final e in _alloc.entries) {
        e.value.text = src[e.key] == null ? '' : '${toDouble(src[e.key]).round()}';
      }
      final end = DateTime.tryParse('${d['end'] ?? ''}') ?? DateTime((d['year'] as int), 12, 31);
      _allocDate = a['allocation_date'] as String? ?? isoDate(DateTime(end.year, end.month + 7, 0));
    });
  }

  Future<void> _post(String path, [Map<String, dynamic> body = const {}, String? done]) async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post(path, body);
      if (r['data'] is Map && (r['data'] as Map).containsKey('checks')) _fill(asMap(r['data']));
      if (!mounted) return;
      if (done != null) toast(context, done);
      final y = await context.read<Session>().api.get('compta/years');
      if (mounted) setState(() => _years = List<Map>.from(y['data'] as List));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _saveFiscal({bool thenPost = false}) async {
    setState(() => _busy = true);
    List<Map<String, dynamic>> rows(List<(TextEditingController, TextEditingController)> l) => [
          for (final x in l)
            if (x.$1.text.trim().isNotEmpty || toDouble(x.$2.text) != 0) {'label': x.$1.text.trim(), 'amount': toDouble(x.$2.text)},
        ];
    try {
      await context.read<Session>().api.put('compta/years/$_year/fiscal', {'reintegrations': rows(_reint), 'deductions': rows(_deduc)});
      if (thenPost) {
        if (mounted) setState(() => _busy = false);
        await _post('compta/years/$_year/tax', const {}, 'Impôt comptabilisé au ${dateFr(_d?['end'])}.');
      } else {
        await _load();
        if (mounted) toast(context, tr('Calcul de l\'impôt enregistré.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _editRates() async {
    final s = _settings ?? const {};
    final c = {for (final k in ['is_rate', 'min_rate', 'min_floor', 'min_cap']) k: TextEditingController(text: '${s[k] ?? 0}')};
    final ok = await showDialog<bool>(
      context: context,
      builder: (dc) => YAlertDialog(
        title: Text(tr('Paramètres d\'imposition')),
        content: SizedBox(
          width: 440,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: c['is_rate'], decoration: InputDecoration(labelText: tr('Taux de l\'impôt sur les bénéfices (%)'))),
            TextField(controller: c['min_rate'], decoration: InputDecoration(labelText: tr('${s['min_label']} : taux sur le chiffre d\'affaires (%)'))),
            TextField(controller: c['min_floor'], decoration: InputDecoration(labelText: tr('Minimum (montant)'))),
            TextField(controller: c['min_cap'], decoration: InputDecoration(labelText: tr('Plafond (montant, 0 = aucun)'))),
            const SizedBox(height: 8),
            Text(tr('Valeurs proposées selon le pays : vérifiez-les avec la loi de finances en vigueur ou votre conseil fiscal.'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12)),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = await context.read<Session>().api.put('compta/fiscal-settings', {for (final e in c.entries) e.key: toDouble(e.value.text)});
      if (mounted) setState(() => _settings = asMap(r['data']));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  void _act(String action) {
    switch (action) {
      case 'purchases' || 'ledger' || 'tiers':
        widget.onOpen(action);
      case 'depreciation':
        _post('compta/years/$_year/depreciation', const {}, 'Dotations aux amortissements comptabilisées.');
      case 'tax':
        _saveFiscal(thenPost: true);
      case 'documents':
        toast(context, tr('Validez ou supprimez les factures en brouillon dans Ventes et Achats.'));
      case 'bank':
        toast(context, tr('Rapprochez les relevés dans Banque & trésorerie.'));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _d == null) return OnlineOnlyNotice(error: _error!, onRetry: _loadYears);
    if (_years == null || _d == null) return const LoadingSkeleton();
    final d = _d!;
    final hint = Theme.of(context).hintColor;
    final y = d['year'] as int;
    final label = '${d['label'] ?? y}';
    final endFr = dateFr(d['end'] ?? '$y-12-31');
    final endDate = DateTime.tryParse('${d['end'] ?? ''}') ?? DateTime(y, 12, 31);
    final closed = d['status'] == 'closed';
    final checks = List<Map>.from(d['checks'] as List);
    final errors = checks.where((c) => c['level'] == 'error').length;
    final f = asMap(d['fiscal']);
    final a = asMap(d['allocation']);
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final manage = widget.manage;
    Color lvl(String l) => switch (l) { 'error' => YColors.danger, 'warning' => YColors.warning, 'ok' => YColors.success, _ => YColors.blue };
    IconData ico(String l) => switch (l) { 'error' => Icons.error_outline, 'warning' => Icons.warning_amber_rounded, 'ok' => Icons.check_circle_outline, _ => Icons.info_outline };
    Widget amountRow(String label, num? v, {bool bold = false, Color? color, String? sub}) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(tr(label), style: TextStyle(fontWeight: bold ? FontWeight.w800 : null)), if (sub != null) Text(tr(sub), style: TextStyle(color: hint, fontSize: 12))])),
            Text(tr(_m(context, v)), style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w600, color: color)),
          ]),
        );
    Widget editable(String title, List<(TextEditingController, TextEditingController)> list) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w700))),
            if (manage && !closed) TextButton.icon(onPressed: () => setState(() => list.add((TextEditingController(), TextEditingController()))), icon: const Icon(Icons.add, size: 18), label: Text(tr('Ligne'))),
          ]),
          for (var i = 0; i < list.length; i++)
            Row(children: [
              Expanded(child: TextField(controller: list[i].$1, enabled: manage && !closed, decoration: InputDecoration(isDense: true, hintText: tr('Libellé (ex. amendes et pénalités)')))),
              const SizedBox(width: 8),
              SizedBox(width: 150, child: TextField(controller: list[i].$2, enabled: manage && !closed, textAlign: TextAlign.right, decoration: InputDecoration(isDense: true, hintText: tr('Montant')))),
              if (manage && !closed) IconButton(onPressed: () => setState(() => list.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
            ]),
          if (list.isEmpty) Text(tr('Aucune.'), style: TextStyle(color: hint, fontSize: 12.5)),
        ]);
    final result = toDouble(a['result']);
    final allocated = a['allocation'] is Map;
    final allocSum = _alloc.values.fold<double>(0, (s, c) => s + toDouble(c.text));

    return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
      // Exercices
      Wrap(spacing: 8, runSpacing: 8, children: [
        for (final x in _years!)
          ChoiceChip(
            avatar: Icon(x['status'] == 'closed' ? Icons.lock_outline : Icons.lock_open, size: 16),
            label: Text(tr('${x['label'] ?? x['year']}${x['status'] == 'closed' ? ' · clôturé' : ''}')),
            selected: x['year'] == y,
            onSelected: (_) {
              setState(() => _year = x['year'] as int);
              _load();
            },
          ),
      ]),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Exercice $label${d['start'] != null && !'${d['start']}'.endsWith('-01-01') ? ' (du ${dateFr(d['start'])} au $endFr)' : ''}'),
        trailing: Wrap(spacing: 8, children: [
          OutlinedButton.icon(onPressed: () => _openServerPdf(context, 'compta/years/$y/liasse/pdf', 'Liasse $label'), icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(tr('Liasse PDF'))),
          OutlinedButton.icon(onPressed: () => _saveServerXlsx(context, 'compta/years/$y/liasse/xlsx', 'liasse_$y.xlsx'), icon: const Icon(Icons.grid_on_outlined, size: 18), label: Text(tr('Liasse Excel'))),
        ]),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            StatusChip(closed ? 'Clôturé le ${dateFr(d['closed_at'])}' : 'Ouvert', color: closed ? YColors.success : YColors.blue),
            const SizedBox(width: 12),
            Text(tr('Résultat : ${_m(context, closed ? d['result'] : f['net_result'])}'), style: const TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(width: 12),
            if (d['lock_date'] != null) Text(tr('Écritures verrouillées jusqu\'au ${dateFr(d['lock_date'])}'), style: TextStyle(color: hint, fontSize: 12.5)),
          ]),
          const SizedBox(height: 6),
          Text(tr('La liasse réunit identification, bilan, compte de résultat, flux de trésorerie, notes (immobilisations, créances et dettes, chiffre d\'affaires), résultat fiscal et balance : à reporter sur votre déclaration (DSF sur e-impôts).'),
              style: TextStyle(color: hint, fontSize: 12.5)),
        ]),
      ),
      const SizedBox(height: 12),
      // 1. Contrôles
      SectionCard(
        title: tr('1. Contrôles avant clôture'),
        trailing: IconButton(tooltip: tr('Actualiser'), onPressed: _busy ? null : _load, icon: const Icon(Icons.refresh)),
        child: Column(children: [
          for (final c in checks)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Icon(ico('${c['level']}'), color: lvl('${c['level']}')),
              title: Text(tr('${c['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text(tr('${c['detail']}${c['amount'] != null && toDouble(c['amount']) != 0 && c['level'] != 'ok' ? ' (${_m(context, c['amount'])})' : ''}')),
              trailing: c['action'] != null && manage && !closed
                  ? TextButton(
                      onPressed: _busy ? null : () => _act('${c['action']}'),
                      child: Text(tr(switch ('${c['action']}') { 'depreciation' => 'Comptabiliser', 'tax' => 'Comptabiliser l\'impôt', 'purchases' => 'Voir les factures', 'ledger' => 'Grand livre', 'tiers' => 'Voir les tiers', _ => 'Que faire ?' })),
                    )
                  : null,
            ),
        ]),
      ),
      const SizedBox(height: 12),
      // 2. Impôt
      SectionCard(
        title: tr('2. Impôt sur le résultat'),
        trailing: manage ? TextButton.icon(onPressed: _editRates, icon: const Icon(Icons.tune, size: 18), label: Text(tr('Taux'))) : null,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          amountRow('Résultat comptable avant impôt', toDouble(f['result_before_tax']), bold: true),
          const SizedBox(height: 6),
          editable('Réintégrations (charges non déductibles)', _reint),
          const SizedBox(height: 6),
          editable('Déductions (produits non imposables, déficits reportables…)', _deduc),
          const Divider(height: 20),
          amountRow('Résultat fiscal', toDouble(f['taxable']), bold: true),
          amountRow('Impôt sur les bénéfices (${f['settings']['is_rate']} %)', toDouble(f['is'])),
          amountRow('${f['settings']['min_label']} (${f['settings']['min_rate']} % du chiffre d\'affaires)', toDouble(f['minimum']), sub: 'Chiffre d\'affaires ${_m(context, f['revenue'])}'),
          amountRow('Impôt dû${f['basis'] == 'minimum' ? ' (minimum d\'imposition)' : ''}', toDouble(f['due']), bold: true, color: YColors.danger),
          amountRow('Déjà comptabilisé', toDouble(f['posted']), sub: 'Compte ${f['tax_account']} / ${f['payable_account']}'),
          amountRow('Résultat net après impôt', toDouble(f['net_result']), bold: true),
          if (manage && !closed) ...[
            const SizedBox(height: 10),
            Wrap(spacing: 8, children: [
              OutlinedButton(onPressed: _busy ? null : () => _saveFiscal(), child: Text(tr('Enregistrer le calcul'))),
              FilledButton.icon(
                onPressed: _busy || (toDouble(f['due']) <= 0 && toDouble(f['posted']) <= 0) ? null : () => _saveFiscal(thenPost: true),
                icon: const Icon(Icons.receipt_long_outlined),
                label: Text(tr(toDouble(f['to_post']).abs() < 1 && toDouble(f['due']) > 0 ? 'Impôt comptabilisé' : 'Comptabiliser l\'impôt')),
              ),
            ]),
          ],
        ]),
      ),
      const SizedBox(height: 12),
      // 3. Clôture
      SectionCard(
        title: tr('3. Clôture de l\'exercice'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(
            tr(closed
                ? 'Les comptes de charges et de produits sont soldés dans le journal CL au $endFr et le résultat est porté au compte de résultat de l\'exercice. Les écritures de l\'exercice sont verrouillées.'
                : 'La clôture solde les comptes de charges et de produits vers le compte de résultat (journal CL, au $endFr) et verrouille l\'exercice. Le compte de résultat de l\'exercice reste consultable.'),
            style: TextStyle(color: hint, fontSize: 13),
          ),
          const SizedBox(height: 10),
          if (manage && !closed)
            FilledButton.icon(
              onPressed: _busy || errors > 0
                  ? null
                  : () async {
                      if (await confirm(context, tr('Clôturer l\'exercice $label ?'), tr('Les écritures de l\'exercice seront verrouillées. Vous pourrez rouvrir l\'exercice tant que le résultat n\'est pas affecté.'), ok: tr('Clôturer'))) {
                        await _post('compta/years/$y/close', const {}, 'Exercice $label clôturé.');
                      }
                    },
              icon: const Icon(Icons.lock_outline),
              label: Text(tr(errors > 0 ? 'Corrigez les $errors point(s) bloquant(s)' : 'Clôturer l\'exercice $label')),
            ),
          if (manage && closed && !allocated)
            TextButton.icon(
              onPressed: _busy
                  ? null
                  : () async {
                      if (await confirm(context, tr('Rouvrir l\'exercice $label ?'), tr('L\'écriture de clôture est supprimée et le verrouillage revient à sa date précédente.'), ok: tr('Rouvrir'))) {
                        await _post('compta/years/$y/reopen', const {}, 'Exercice $label rouvert.');
                      }
                    },
              icon: const Icon(Icons.lock_open),
              label: Text(tr('Rouvrir l\'exercice')),
            ),
        ]),
      ),
      const SizedBox(height: 12),
      // 4. Affectation
      SectionCard(
        title: tr('4. Affectation du résultat'),
        child: !closed
            ? Text(tr('Après la clôture et l\'approbation des comptes par l\'assemblée.'), style: TextStyle(color: hint))
            : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(
                  tr(result >= 0
                      ? 'Bénéfice ${_m(context, result)} · capital ${_m(context, a['capital'])} · réserve légale actuelle ${_m(context, a['legal_reserve_now'])}. Réserve légale proposée : ${a['rate']} % du bénéfice tant qu\'elle reste sous ${a['cap']} % du capital.'
                      : 'Perte ${_m(context, -result)} : portée en report à nouveau débiteur.'),
                  style: TextStyle(color: hint, fontSize: 13),
                ),
                const SizedBox(height: 10),
                if (result >= 0)
                  Wrap(spacing: 12, runSpacing: 8, children: [
                    for (final e in {'legal_reserve': 'Réserve légale', 'other_reserves': 'Autres réserves', 'dividends': 'Dividendes', 'report': 'Report à nouveau'}.entries)
                      SizedBox(
                        width: 190,
                        child: TextField(
                          controller: _alloc[e.key],
                          enabled: manage && !allocated,
                          textAlign: TextAlign.right,
                          onChanged: (_) => setState(() {}),
                          decoration: InputDecoration(labelText: trn('${e.value} (${a['accounts'][e.key]})')),
                        ),
                      ),
                  ]),
                const SizedBox(height: 8),
                if (result >= 0 && (allocSum - result).abs() > 0.5 && !allocated)
                  Text(tr('Reste à répartir : ${_m(context, result - allocSum)}'), style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w700)),
                if (allocated)
                  Text(tr('Résultat affecté le ${dateFr(a['allocation_date'])}.'), style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
                if (manage && !allocated)
                  Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    OutlinedButton.icon(
                      onPressed: () async {
                        final dt = await pickDate(context, firstDate: endDate.add(const Duration(days: 1)), lastDate: DateTime(endDate.year + 2, 12, 31), initialDate: DateTime.tryParse(_allocDate ?? '') ?? DateTime(endDate.year, endDate.month + 7, 0));
                        if (dt != null) setState(() => _allocDate = isoDate(dt));
                      },
                      icon: const Icon(Icons.event, size: 18),
                      label: Text(tr('Assemblée du ${dateFr(_allocDate)}')),
                    ),
                    FilledButton(
                      onPressed: _busy || (result >= 0 && (allocSum - result).abs() > 0.5)
                          ? null
                          : () => _post('compta/years/$y/allocate', {for (final e in _alloc.entries) e.key: toDouble(e.value.text), 'date': _allocDate}, 'Résultat affecté.'),
                      child: Text(tr('Affecter le résultat')),
                    ),
                  ]),
                if (manage && allocated)
                  TextButton(onPressed: _busy ? null : () => _post('compta/years/$y/cancel-allocation', const {}, 'Affectation annulée.'), child: Text(tr('Annuler l\'affectation'))),
              ]),
      ),
    ]);
  }
}
