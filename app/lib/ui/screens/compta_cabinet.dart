part of 'compta_screen.dart';

// ---------------------------------------------------------------------------
// Tableau de bord comptable
// ---------------------------------------------------------------------------

class _DashboardTab extends StatelessWidget {
  const _DashboardTab({super.key, required this.period, required this.onOpen});

  final Period period;
  final void Function(String tab) onOpen;

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final cur = context.read<Session>().currency;
    return _Fetch(
      path: 'compta/dashboard',
      query: period.query,
      builder: (c, raw, reload) {
        final d = asMap(raw['data']);
        final k = asMap(d['kpis']);
        final review = asMap(d['review']);
        final hint = Theme.of(c).hintColor;
        String delta(num n, num n1) {
          if (n1 == 0) return 'N-1 : —';
          final p = (n - n1) / n1.abs() * 100;
          return p.abs() >= 1000 ? 'N-1 : ${_m(c, n1)}' : 'N-1 : ${_m(c, n1)} (${p >= 0 ? '+' : ''}${p.toStringAsFixed(0)} %)';
        }

        Widget kpi(String label, num value, IconData icon, Color color, {String? sub, VoidCallback? onTap}) => KpiBox(
              width: 260,
              child: StatTile(label: label, value: _m(c, value), detail: sub, icon: icon, color: color, valueColor: value < 0 ? YColors.danger : null, onTap: onTap),
            );
        final months = List<Map>.from(d['months'] as List);
        final chart = ChartData(
          [for (final m in months) DateFormat('MMM yy', I18n.dateLocale).format(DateTime.parse('${m['month']}-01'))],
          [ChartSeries('Produits', [for (final m in months) toDouble(m['income'])]), ChartSeries('Charges', [for (final m in months) toDouble(m['expenses'])])],
          'money',
        );
        final charges = List<Map>.from(d['charges'] as List);
        final treasury = List<Map>.from(d['treasury'] as List);
        Widget list(String title, List<Map> rows, String Function(Map) left, num Function(Map) right, {String empty = 'Rien à signaler.', VoidCallback? more}) => SectionCard(
              title: title,
              trailing: more == null ? null : TextButton(onPressed: more, child: Text(tr('Voir tout'))),
              child: rows.isEmpty
                  ? Text(tr(empty), style: TextStyle(color: hint))
                  : Column(children: [
                      for (final r in rows)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 5),
                          child: Row(children: [Expanded(child: Text(tr(left(r)), overflow: TextOverflow.ellipsis)), Text(tr(_m(c, right(r))), style: _num.copyWith(fontWeight: FontWeight.w600))]),
                        ),
                    ]),
            );
        return RefreshIndicator(
          onRefresh: () async => reload(),
          child: ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
            if (review['enabled'] == true && (toDouble(review['to_review']) + toDouble(review['approved']) + toDouble(review['refused'])) > 0)
              Card(
                color: YColors.warning.withValues(alpha: .08),
                child: ListTile(
                  leading: const Icon(Icons.fact_check_outlined, color: YColors.warning),
                  title: Text(tr('${review['to_review']} facture(s) fournisseur à vérifier · ${review['approved']} approuvée(s) à comptabiliser')),
                  subtitle: toDouble(review['refused']) > 0 ? Text(tr('${review['refused']} refusée(s), en attente de réponse des achats')) : null,
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => onOpen('purchases'),
                ),
              ),
            KpiWrap(children: [
              kpi('Chiffre d\'affaires', k['revenue'], Icons.trending_up, YColors.blue, sub: delta(toDouble(k['revenue']), toDouble(k['revenue_n1']))),
              if (k['margin'] != null) kpi('Marge commerciale', k['margin'], Icons.storefront_outlined, const Color(0xFF0FA3B1)),
              if (k['ebitda'] != null) kpi('Excédent brut d\'exploitation', k['ebitda'], Icons.insights_outlined, YColors.night),
              kpi('Résultat net', k['result'], Icons.emoji_events_outlined, toDouble(k['result']) >= 0 ? YColors.success : YColors.danger, sub: delta(toDouble(k['result']), toDouble(k['result_n1']))),
              kpi('Trésorerie', k['treasury'], Icons.account_balance_wallet_outlined, YColors.success),
              kpi('Créances clients', k['receivables'], Icons.call_received, YColors.warning, onTap: () => onOpen('tiers')),
              kpi('Dettes fournisseurs', k['payables'], Icons.call_made, YColors.danger, onTap: () => onOpen('tiers')),
              kpi(toDouble(k['vat_due']) > 0 ? 'TVA à payer ce mois' : 'Crédit de TVA', toDouble(k['vat_due']) > 0 ? k['vat_due'] : k['vat_credit'], Icons.receipt_long_outlined, YColors.muted),
            ]),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Produits et charges, 12 derniers mois'),
              child: SizedBox(height: 260, child: CartesianChart(data: chart, currency: cur, line: false)),
            ),
            const SizedBox(height: 12),
            Wrap(spacing: 12, runSpacing: 12, children: [
              SizedBox(width: 420, child: list('Principales charges', charges, (r) => '${r['code']} · ${r['name']}', (r) => toDouble(r['amount']))),
              SizedBox(width: 420, child: list('Trésorerie par compte', treasury, (r) => '${r['code']} · ${r['name']}', (r) => toDouble(r['balance']))),
              SizedBox(width: 420, child: list('Clients qui doivent le plus', List<Map>.from(d['debtors'] as List), (r) => '${r['aux_code']} · ${r['name']}', (r) => toDouble(r['due']), more: () => onOpen('tiers'))),
              SizedBox(width: 420, child: list('Fournisseurs à payer', List<Map>.from(d['creditors'] as List), (r) => '${r['aux_code']} · ${r['name']}', (r) => toDouble(r['due']), more: () => onOpen('tiers'))),
            ]),
            const SizedBox(height: 8),
            Text(tr('${d['entries']} écriture(s) sur la période${d['lock_date'] != null ? ' · période verrouillée jusqu\'au ${dateFr(d['lock_date'])}' : ''}'), style: TextStyle(color: hint, fontSize: 12)),
          ]),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Factures fournisseurs : vérification, nature d'achat, comptabilisation
// ---------------------------------------------------------------------------

class _PurchasesTab extends StatefulWidget {
  const _PurchasesTab({super.key, required this.setup, required this.manage, required this.onChanged});

  final Map<String, dynamic> setup;
  final bool manage;
  final Future<void> Function() onChanged;

  @override
  State<_PurchasesTab> createState() => _PurchasesTabState();
}

class _PurchasesTabState extends State<_PurchasesTab> {
  String _status = 'to_review';
  String _q = '';
  int _reload = 0;

  static const _labels = {'to_review': 'À vérifier', 'approved': 'Approuvées', 'refused': 'Refusées', 'posted': 'Comptabilisées', 'all': 'Toutes'};
  static const _colors = {'to_review': YColors.warning, 'approved': YColors.blue, 'refused': YColors.danger, 'posted': YColors.success};

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final enabled = widget.setup['purchase_review'] == true;
    return _Fetch(
      key: ValueKey('$_status|$_q|$_reload'),
      path: 'compta/purchases',
      query: {'status': _status, if (_q.isNotEmpty) 'q': _q},
      builder: (c, raw, reload) {
        final rows = List<Map>.from(raw['data'] as List);
        final counts = asMap(raw['counts'] is Map ? raw['counts'] : const {});
        final hint = Theme.of(c).hintColor;
        return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
          if (!enabled)
            Card(
              color: YColors.blue.withValues(alpha: .06),
              child: ListTile(
                leading: const Icon(Icons.info_outline, color: YColors.blue),
                title: Text(tr('Circuit de vérification désactivé')),
                subtitle: Text(tr('Les factures fournisseurs passent en comptabilité dès leur validation, avec la nature d\'achat proposée. Activez le circuit pour les vérifier, approuver ou refuser avant de les comptabiliser.')),
                trailing: widget.manage
                    ? FilledButton.tonal(
                        onPressed: () async {
                          await context.read<Session>().api.put('compta/settings', {'purchase_review': true});
                          await widget.onChanged();
                        },
                        child: Text(tr('Activer')),
                      )
                    : null,
              ),
            ),
          Row(children: [
            Expanded(child: SearchField(hint: tr('Fournisseur, n° de facture…'), onChanged: (v) => setState(() => _q = v))),
            if (widget.manage && toDouble(counts['approved']) > 0) ...[
              const SizedBox(width: 12),
              FilledButton.icon(onPressed: _postApproved, icon: const Icon(Icons.done_all), label: Text(tr('Comptabiliser les approuvées (${counts['approved']})'))),
            ],
          ]),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 6, children: [
            for (final e in _labels.entries)
              ChoiceChip(
                label: Text(tr('${e.value}${counts[e.key] != null ? ' (${counts[e.key]})' : ''}')),
                selected: _status == e.key,
                onSelected: (_) => setState(() => _status = e.key),
              ),
          ]),
          const SizedBox(height: 10),
          if (rows.isEmpty)
            EmptyState(
              icon: Icons.fact_check_outlined,
              title: _status == 'to_review' ? 'Aucune facture à vérifier' : 'Aucune facture',
              message: tr('Les factures fournisseurs validées dans Achats arrivent ici avec la nature d\'achat proposée (marchandises, publicité, loyers, électricité…).'),
            ),
          for (final (i, p) in rows.indexed)
            AnimatedFadeIn(
              index: i % 10,
              child: Card(
                child: InkWell(
                  borderRadius: BorderRadius.circular(16),
                  onTap: () => _open(p),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(children: [
                      CircleAvatar(
                        backgroundColor: (_colors[p['acc_status']] ?? YColors.muted).withValues(alpha: .12),
                        child: Icon(Icons.receipt_long_outlined, color: _colors[p['acc_status']] ?? YColors.muted),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(tr('${p['number'] ?? ''} · ${p['partner_name'] ?? 'Fournisseur'}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                          Text(
                            tr('${dateFr(p['date'])}${p['reference'] != null ? ' · réf. ${p['reference']}' : ''} · ${{for (final l in p['lines'] as List) '${(l as Map)['account_code']} ${l['account_label']}'}.join(', ')}'),
                            style: TextStyle(color: hint, fontSize: 12.5),
                            overflow: TextOverflow.ellipsis,
                          ),
                          if (p['acc_status'] == 'refused' && p['acc_note'] != null)
                            Text(tr('Motif : ${p['acc_note']}'), style: const TextStyle(color: YColors.danger, fontSize: 12.5), maxLines: 2, overflow: TextOverflow.ellipsis),
                        ]),
                      ),
                      Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                        Text(tr(_m(c, p['total'])), style: _num.copyWith(fontWeight: FontWeight.w800)),
                        const SizedBox(height: 4),
                        StatusChip('${p['acc_status_label'] ?? 'Non comptabilisée'}', color: _colors[p['acc_status']] ?? YColors.muted),
                      ]),
                    ]),
                  ),
                ),
              ),
            ),
        ]);
      },
    );
  }

  Future<void> _postApproved() async {
    try {
      final r = await context.read<Session>().api.post('compta/purchases/post-approved');
      final errors = List<String>.from(r['data']['errors'] as List);
      if (mounted) toast(context, tr('${r['data']['posted']} facture(s) comptabilisée(s)${errors.isEmpty ? '' : ' · ${errors.length} erreur(s) : ${errors.first}'}.'), error: errors.isNotEmpty);
      await widget.onChanged();
      if (mounted) setState(() => _reload++);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _open(Map p) async {
    final changed = await showDialog<bool>(context: context, builder: (_) => _PurchaseReviewDialog(purchase: p, setup: widget.setup, manage: widget.manage));
    if (changed == true) {
      await widget.onChanged();
      if (mounted) setState(() => _reload++);
    }
  }
}

/// Choix d'un compte de charge ou d'immobilisation : natures d'achat d'abord, puis tout le plan (classes 2 et 6).
class _AccountPicker extends StatelessWidget {
  const _AccountPicker({required this.setup, required this.value, required this.onChanged, this.enabled = true});

  final Map<String, dynamic> setup;
  final String value;
  final ValueChanged<String> onChanged;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final kinds = List<Map>.from(setup['purchase_kinds'] as List? ?? const []);
    final accounts = List<Map>.from(setup['accounts'] as List).where((a) => '${a['code']}'.startsWith('6') || '${a['code']}'.startsWith('2')).toList();
    final options = <String, String>{
      for (final k in kinds) '${k['account']}': '${k['account']} · ${k['label']}',
      for (final a in accounts) '${a['code']}': '${a['code']} · ${a['name']}',
    };
    if (!options.containsKey(value)) options[value] = value;
    return DropdownButtonFormField<String>(
      isExpanded: true,
      initialValue: value,
      decoration: InputDecoration(isDense: true, labelText: tr('Nature d\'achat (compte)')),
      items: [
        for (final g in kinds.map((k) => '${k['group']}').toSet()) ...[
          DropdownMenuItem(enabled: false, value: '§$g', child: Text(tr(g.toUpperCase()), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: YColors.muted))),
          for (final k in kinds.where((k) => k['group'] == g)) DropdownMenuItem(value: '${k['account']}', child: Text(tr('${k['account']} · ${k['label']}'), overflow: TextOverflow.ellipsis)),
        ],
        DropdownMenuItem(enabled: false, value: '§plan', child: Text(tr('PLAN COMPTABLE'), style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: YColors.muted))),
        for (final e in options.entries.where((e) => !kinds.any((k) => '${k['account']}' == e.key))) DropdownMenuItem(value: e.key, child: Text(tr(e.value), overflow: TextOverflow.ellipsis)),
      ],
      onChanged: enabled ? (v) => v == null || v.startsWith('§') ? null : onChanged(v) : null,
    );
  }
}

class _PurchaseReviewDialog extends StatefulWidget {
  const _PurchaseReviewDialog({required this.purchase, required this.setup, required this.manage});

  final Map purchase;
  final Map<String, dynamic> setup;
  final bool manage;

  @override
  State<_PurchaseReviewDialog> createState() => _PurchaseReviewDialogState();
}

class _PurchaseReviewDialogState extends State<_PurchaseReviewDialog> {
  late final Map<String, String> _codes = {for (final l in widget.purchase['lines'] as List) '${(l as Map)['id']}': '${l['account_code']}'};
  final _note = TextEditingController();
  bool _remember = true;
  bool _busy = false;

  static const _sources = {'line': 'choisi', 'product': 'mémorisé pour l\'article', 'supplier': 'mémorisé pour le fournisseur', 'category': 'selon la catégorie', 'default': 'par défaut'};

  Future<void> _decide(String decision) async {
    if (decision == 'refuse' && _note.text.trim().isEmpty) return toast(context, tr('Indiquez le motif du refus.'), error: true);
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('compta/purchases/${widget.purchase['id']}/decide', {
        'decision': decision,
        'note': _note.text.trim().isEmpty ? null : _note.text.trim(),
        'lines': _codes,
        'remember': _remember,
      });
      if (mounted) {
        Navigator.pop(context, true);
        toast(context, switch (decision) { 'post' => 'Facture comptabilisée.', 'approve' => 'Facture approuvée.', _ => 'Facture refusée : les achats sont prévenus.' });
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.purchase;
    final hint = Theme.of(context).hintColor;
    final posted = p['acc_status'] == 'posted';
    final lines = List<Map>.from(p['lines'] as List);
    return YAlertDialog(
      title: Row(children: [
        Expanded(child: Text(tr('Facture ${p['number'] ?? ''} · ${p['partner_name'] ?? ''}'))),
        StatusChip('${p['acc_status_label'] ?? 'Non comptabilisée'}', color: _PurchasesTabState._colors[p['acc_status']] ?? YColors.muted),
      ]),
      content: SizedBox(
        width: 760,
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Du ${dateFr(p['date'])}${p['due_date'] != null ? ' · échéance ${dateFr(p['due_date'])}' : ''}${p['reference'] != null ? ' · réf. fournisseur ${p['reference']}' : ''}'), style: TextStyle(color: hint)),
            const SizedBox(height: 12),
            for (final l in lines)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Expanded(
                    flex: 4,
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${l['description']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(tr('${qty(l['quantity'])} · HT ${_m(context, l['subtotal'])} · TVA ${_m(context, l['tax_amount'])}'), style: TextStyle(color: hint, fontSize: 12.5)),
                      if (_codes['${l['id']}'] == '${l['account_code']}')
                        Text(tr('Compte ${_sources[l['account_source']] ?? ''}'), style: TextStyle(color: hint, fontSize: 11.5)),
                    ]),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    flex: 5,
                    child: _AccountPicker(
                      setup: widget.setup,
                      value: _codes['${l['id']}']!,
                      enabled: widget.manage && !_busy,
                      onChanged: (v) => setState(() => _codes['${l['id']}'] = v),
                    ),
                  ),
                ]),
              ),
            const Divider(),
            Row(children: [
              Expanded(child: Text(tr('Total TTC'), style: TextStyle(fontWeight: FontWeight.w700))),
              Text(tr(_m(context, p['total'])), style: _num.copyWith(fontWeight: FontWeight.w800, fontSize: 17)),
            ]),
            if (p['acc_note'] != null) ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: YColors.warning.withValues(alpha: .08), borderRadius: BorderRadius.circular(10)),
                child: Text(tr('${p['acc_note']}')),
              ),
            ],
            if (p['entry'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Écriture ${p['entry']['number']} (journal des achats)'), style: TextStyle(color: hint))),
            if (widget.manage) ...[
              const SizedBox(height: 12),
              TextField(controller: _note, maxLines: 2, decoration: InputDecoration(labelText: tr('Commentaire ou motif du refus'))),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('Mémoriser les comptes choisis')),
                subtitle: Text(tr('Pour cet article (ou ce fournisseur si la ligne n\'a pas d\'article) sur les prochaines factures.')),
                value: _remember,
                onChanged: (v) => setState(() => _remember = v),
              ),
            ],
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Fermer'))),
        if (widget.manage && !posted) TextButton(style: TextButton.styleFrom(foregroundColor: YColors.danger), onPressed: _busy ? null : () => _decide('refuse'), child: Text(tr('Refuser'))),
        if (widget.manage && !posted && p['acc_status'] != 'approved') OutlinedButton(onPressed: _busy ? null : () => _decide('approve'), child: Text(tr('Approuver'))),
        if (widget.manage) FilledButton(onPressed: _busy ? null : () => _decide(posted ? 'approve' : 'post'), child: Text(tr(posted ? 'Mettre à jour l\'écriture' : 'Comptabiliser'))),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// États financiers (modèle du référentiel de la société)
// ---------------------------------------------------------------------------

Future<void> _exportStatement(BuildContext c, String kind, Period p, {String? side}) async {
  try {
    final bytes = await c.read<Session>().api.download('compta/statements/$kind/export', query: {...p.query, 'side': ?side});
    const names = {'income': 'compte_de_resultat', 'sheet': 'bilan', 'cashflow': 'flux_de_tresorerie', 'tiers': 'balance_tiers'};
    final where = await saveFile('${names[kind]}_${isoDate(p.from)}_${isoDate(p.to)}.xlsx', bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    if (c.mounted) toast(c, tr('Fichier Excel enregistré${where.isEmpty ? '' : ' : $where'}'));
  } catch (e) {
    if (c.mounted) toast(c, errorText(e), error: true);
  }
}

Widget _exportBar(BuildContext c, VoidCallback onPdf, VoidCallback onExcel) => Align(
      alignment: Alignment.centerRight,
      child: Wrap(spacing: 4, children: [
        TextButton.icon(onPressed: onPdf, icon: const Icon(Icons.print_outlined, size: 18), label: Text(tr('PDF'))),
        TextButton.icon(onPressed: onExcel, icon: const Icon(Icons.grid_on_outlined, size: 18), label: Text(tr('Excel'))),
      ]),
    );

class _StatementTable extends StatelessWidget {
  const _StatementTable({required this.headers, required this.rows, this.flex = const [1, 6, 2, 2]});

  final List<String> headers;
  final List<({String ref, String label, List<String> values, bool total})> rows;
  final List<int> flex;

  @override
  Widget build(BuildContext context) {
    final night = Theme.of(context).brightness == Brightness.dark ? Colors.white : YColors.night;
    Widget cell(String t, int i, {bool bold = false, bool head = false}) => Expanded(
          flex: flex[i],
          child: Text(tr(t),
              textAlign: i >= 2 ? TextAlign.right : TextAlign.left,
              overflow: TextOverflow.ellipsis,
              style: _num.copyWith(fontWeight: bold || head ? FontWeight.w800 : null, fontSize: head ? 12 : 13.5, color: head ? Theme.of(context).hintColor : null)),
        );
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 14, 8),
          child: Row(children: [for (var i = 0; i < headers.length; i++) cell(headers[i], i, head: true)]),
        ),
        const Divider(height: 1),
        for (final r in rows)
          Container(
            color: r.total ? night.withValues(alpha: .05) : null,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
            child: Row(children: [
              cell(r.ref, 0, bold: r.total),
              cell(r.label, 1, bold: r.total),
              for (var i = 0; i < r.values.length; i++) cell(r.values[i], i + 2, bold: r.total),
            ]),
          ),
      ]),
    );
  }
}

Widget _incomeStatement(BuildContext c, Map d, Period p) {
  final rows = List<Map>.from(d['rows'] as List);
  final r = toDouble(d['result']);
  String lbl(Map x) => x['total'] == true ? '${x['label']}' : '${x['sign'] == '-' ? '(−) ' : ''}${x['label']}';
  return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
    _exportBar(
      c,
      () => printReport(
        company: _company(c),
        title: '${d['title'] ?? 'Compte de résultat'}',
        period: '${p.text} · N-1 du ${dateFr(d['prev_from'])} au ${dateFr(d['prev_to'])}',
        headers: const ['Réf.', 'Libellé', 'Exercice N', 'Exercice N-1'],
        sections: [ReportSection(rows: [for (final x in rows) ['${x['ref']}', lbl(x), _m(c, x['n']), _m(c, x['n1'])]])],
      ),
      () => _exportStatement(c, 'income', p),
    ),
    _StatementTable(
      headers: const ['Réf.', 'Libellé', 'Exercice N', 'Exercice N-1'],
      rows: [for (final x in rows) (ref: '${x['ref']}', label: lbl(x), values: [_m(c, x['n']), _m(c, x['n1'])], total: x['total'] == true)],
    ),
    const SizedBox(height: 10),
    SectionCard(child: Row(children: [
      Icon(r >= 0 ? Icons.trending_up : Icons.trending_down, color: r >= 0 ? YColors.success : YColors.danger),
      const SizedBox(width: 10),
      Expanded(child: Text(tr(r >= 0 ? 'Bénéfice de l\'exercice' : 'Perte de l\'exercice'), style: const TextStyle(fontWeight: FontWeight.w700))),
      Text(tr(_m(c, r)), style: _num.copyWith(fontWeight: FontWeight.w800, fontSize: 17, color: r >= 0 ? YColors.success : YColors.danger)),
    ])),
    const SizedBox(height: 6),
    Text(tr('Soldes intermédiaires de gestion : marge commerciale (XA), valeur ajoutée (XC), excédent brut d\'exploitation (XD), résultats d\'exploitation (XE), financier (XF), HAO (XH) et net (XI).'),
        style: TextStyle(fontSize: 12, color: YColors.muted)),
  ]);
}

Widget _sheetStatement(BuildContext c, Map d, Period p) {
  final assets = List<Map>.from(d['assets'] as List);
  final liab = List<Map>.from(d['liabilities'] as List);
  final ok = d['balanced'] == true;
  return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
    _exportBar(
      c,
      () => printReport(
        company: _company(c),
        title: '${d['title'] ?? 'Bilan'}',
        period: 'Au ${dateFr(d['at'])} · N-1 au ${dateFr(d['prev_at'])}',
        landscape: true,
        headers: const ['Réf.', 'Libellé', 'Brut', 'Amort. et dépréc.', 'Net N', 'Net N-1'],
        sections: [
          ReportSection(title: tr('Actif'), rows: [for (final x in assets) ['${x['ref']}', '${x['label']}', _m(c, x['gross']), _m(c, x['amort']), _m(c, x['net']), _m(c, x['n1'])]]),
          ReportSection(title: tr('Passif'), rows: [for (final x in liab) ['${x['ref']}', '${x['label']}', '', '', _m(c, x['n']), _m(c, x['n1'])]]),
        ],
        footnote: ok ? 'Bilan équilibré.' : 'Attention : bilan déséquilibré.',
      ),
      () => _exportStatement(c, 'sheet', p),
    ),
    Text(tr('ACTIF'), style: TextStyle(fontWeight: FontWeight.w800, color: Theme.of(c).hintColor, letterSpacing: 1)),
    const SizedBox(height: 6),
    _StatementTable(
      headers: const ['Réf.', 'Libellé', 'Brut', 'Amort. / dépréc.', 'Net N', 'Net N-1'],
      flex: const [1, 6, 2, 2, 2, 2],
      rows: [for (final x in assets) (ref: '${x['ref']}', label: '${x['label']}', values: [_m(c, x['gross']), _m(c, x['amort']), _m(c, x['net']), _m(c, x['n1'])], total: x['total'] == true)],
    ),
    const SizedBox(height: 16),
    Text(tr('PASSIF'), style: TextStyle(fontWeight: FontWeight.w800, color: Theme.of(c).hintColor, letterSpacing: 1)),
    const SizedBox(height: 6),
    _StatementTable(
      headers: const ['Réf.', 'Libellé', 'Net N', 'Net N-1'],
      rows: [for (final x in liab) (ref: '${x['ref']}', label: '${x['label']}', values: [_m(c, x['n']), _m(c, x['n1'])], total: x['total'] == true)],
    ),
    const SizedBox(height: 10),
    Row(children: [
      Icon(ok ? Icons.check_circle : Icons.error_outline, color: ok ? YColors.success : YColors.danger, size: 18),
      const SizedBox(width: 8),
      Expanded(child: Text(tr(ok ? 'Bilan équilibré : total actif = total passif = ${_m(c, d['total_assets'])}' : 'Bilan déséquilibré : vérifiez les écritures'))),
    ]),
  ]);
}

Widget _cashflowStatement(BuildContext c, Map d, Period p) {
  final rows = List<Map>.from(d['rows'] as List);
  return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
    _exportBar(
      c,
      () => printReport(
        company: _company(c),
        title: '${d['title'] ?? 'Tableau des flux de trésorerie'}',
        period: p.text,
        headers: const ['Réf.', 'Libellé', 'Montant'],
        sections: [ReportSection(rows: [for (final x in rows) ['${x['ref']}', '${x['label']}', x['amount'] == null ? '' : _m(c, x['amount'])]])],
      ),
      () => _exportStatement(c, 'cashflow', p),
    ),
    _StatementTable(
      headers: const ['Réf.', 'Libellé', 'Montant'],
      flex: const [1, 8, 3],
      rows: [for (final x in rows) (ref: '${x['ref']}', label: '${x['label']}', values: [x['amount'] == null ? '' : _m(c, x['amount'])], total: x['total'] == true)],
    ),
    const SizedBox(height: 8),
    Row(children: [
      Icon(d['check'] == true ? Icons.check_circle : Icons.error_outline, color: d['check'] == true ? YColors.success : YColors.danger, size: 18),
      const SizedBox(width: 8),
      Expanded(child: Text(tr('Trésorerie au début ${_m(c, d['opening'])}, à la fin ${_m(c, d['closing'])} (variation ${_m(c, d['variation'])})'))),
    ]),
  ]);
}

// ---------------------------------------------------------------------------
// Comptes de tiers
// ---------------------------------------------------------------------------

class _TiersTab extends StatefulWidget {
  const _TiersTab({super.key, required this.period});

  final Period period;

  @override
  State<_TiersTab> createState() => _TiersTabState();
}

class _TiersTabState extends State<_TiersTab> {
  String _side = 'customers';
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    final p = widget.period;
    return ListView(padding: EdgeInsets.fromLTRB(pad, 16, pad, 32), children: [
      Wrap(spacing: 12, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
        SegmentedButton<String>(
          showSelectedIcon: false,
          segments: [ButtonSegment(value: 'customers', label: Text(tr('Clients (41)'))), ButtonSegment(value: 'suppliers', label: Text(tr('Fournisseurs (40)')))],
          selected: {_side},
          onSelectionChanged: (s) => setState(() => _side = s.first),
        ),
        SizedBox(width: 320, child: SearchField(hint: tr('Code tiers ou nom…'), onChanged: (v) => setState(() => _q = v))),
      ]),
      const SizedBox(height: 12),
      _Fetch(
        key: ValueKey('$_side|$_q|${p.query}'),
        path: 'compta/tiers',
        query: {...p.query, 'side': _side, if (_q.isNotEmpty) 'q': _q},
        builder: (c, raw, _) {
          final d = asMap(raw['data']);
          final rows = List<Map>.from(d['rows'] as List);
          final t = asMap(d['totals']);
          if (rows.isEmpty) {
            return EmptyState(icon: Icons.people_outline, title: tr('Aucun mouvement'), message: tr('Chaque client et fournisseur reçoit son compte de tiers (CLI00001, FRS00001…) dès sa première écriture.'));
          }
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _exportBar(
              c,
              () => printReport(
                company: _company(c),
                title: tr('Balance des comptes ${_side == 'customers' ? 'clients' : 'fournisseurs'}'),
                period: p.text,
                landscape: true,
                headers: const ['Compte tiers', 'Nom', 'Solde d\'ouverture', 'Débit', 'Crédit', 'Solde'],
                sections: [
                  ReportSection(
                    rows: [for (final r in rows) ['${r['aux_code']}', '${r['name']}', _m(c, r['opening']), _m(c, r['debit']), _m(c, r['credit']), _m(c, r['balance'])]],
                    total: ['', 'Totaux', _m(c, t['opening']), _m(c, t['debit']), _m(c, t['credit']), _m(c, t['balance'])],
                  ),
                ],
              ),
              () => _exportStatement(c, 'tiers', p, side: _side),
            ),
            Card(
              clipBehavior: Clip.antiAlias,
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(
                  columnSpacing: 22,
                  showCheckboxColumn: false,
                  columns: [
                    DataColumn(label: Text(tr('Compte tiers'))),
                    DataColumn(label: Text(tr('Nom'))),
                    DataColumn(label: Text(tr('Ouverture')), numeric: true),
                    DataColumn(label: Text(tr('Débit')), numeric: true),
                    DataColumn(label: Text(tr('Crédit')), numeric: true),
                    DataColumn(label: Text(tr('Solde')), numeric: true),
                  ],
                  rows: [
                    for (final r in rows)
                      DataRow(onSelectChanged: (_) => _ledger(r), cells: [
                        DataCell(Text(tr('${r['aux_code']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                        DataCell(ConstrainedBox(constraints: const BoxConstraints(maxWidth: 260), child: Text(tr('${r['name']}'), overflow: TextOverflow.ellipsis))),
                        DataCell(Text(tr(_m(c, r['opening'])), style: _num)),
                        DataCell(Text(tr(_m(c, r['debit'])), style: _num)),
                        DataCell(Text(tr(_m(c, r['credit'])), style: _num)),
                        DataCell(Text(tr('${_m(c, toDouble(r['balance']).abs())} ${toDouble(r['balance']) >= 0 ? 'D' : 'C'}'), style: _num.copyWith(fontWeight: FontWeight.w700))),
                      ]),
                    DataRow(cells: [
                      const DataCell(Text('')),
                      DataCell(Text(tr('${rows.length} tiers'), style: const TextStyle(fontWeight: FontWeight.w700))),
                      DataCell(Text(tr(_m(c, t['opening'])), style: _num.copyWith(fontWeight: FontWeight.w700))),
                      DataCell(Text(tr(_m(c, t['debit'])), style: _num.copyWith(fontWeight: FontWeight.w700))),
                      DataCell(Text(tr(_m(c, t['credit'])), style: _num.copyWith(fontWeight: FontWeight.w700))),
                      DataCell(Text(tr('${_side == 'customers' ? 'À encaisser' : 'À payer'} ${_m(c, t['due'])}'), style: _num.copyWith(fontWeight: FontWeight.w800))),
                    ]),
                  ],
                ),
              ),
            ),
          ]);
        },
      ),
    ]);
  }

  Future<void> _ledger(Map r) => showDialog(
        context: context,
        builder: (dc) => YAlertDialog(
          title: Text(tr('${r['aux_code']} · ${r['name']}')),
          content: SizedBox(
            width: 820,
            height: 520,
            child: _Fetch(
              path: 'compta/tiers/${r['partner_id']}',
              query: {...widget.period.query, 'side': _side},
              builder: (c, raw, _) {
                final d = asMap(raw['data']);
                final rows = List<Map>.from(d['rows'] as List);
                return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(children: [
                    Expanded(child: Text(tr('Solde d\'ouverture ${_m(c, d['opening'])} · solde ${_m(c, d['closing'])}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                    TextButton.icon(
                      onPressed: () => printReport(
                        company: _company(c),
                        title: tr('Extrait de compte ${r['aux_code']} · ${r['name']}'),
                        period: widget.period.text,
                        landscape: true,
                        headers: const ['Date', 'Pièce', 'Journal', 'Libellé', 'Débit', 'Crédit', 'Solde'],
                        align: {4: pw.Alignment.centerRight, 5: pw.Alignment.centerRight, 6: pw.Alignment.centerRight},
                        sections: [
                          ReportSection(rows: [
                            ['', '', '', 'Solde d\'ouverture', '', '', _m(c, d['opening'])],
                            for (final x in rows) [dateFr(x['date']), '${x['number']}', '${x['journal']}', '${x['label']}', toDouble(x['debit']) > 0 ? _m(c, x['debit']) : '', toDouble(x['credit']) > 0 ? _m(c, x['credit']) : '', _m(c, x['balance'])],
                          ], total: ['', '', '', 'Totaux', _m(c, d['debit']), _m(c, d['credit']), _m(c, d['closing'])]),
                        ],
                      ),
                      icon: const Icon(Icons.print_outlined, size: 18),
                      label: Text(tr('Extrait PDF')),
                    ),
                  ]),
                  const Divider(),
                  Expanded(
                    child: ListView(children: [
                      for (final x in rows)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 5),
                          child: Row(children: [
                            SizedBox(width: 86, child: Text(tr(dateFr(x['date'])))),
                            SizedBox(width: 120, child: Text(tr('${x['number']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                            Expanded(child: Text(tr('${x['label']}'), overflow: TextOverflow.ellipsis)),
                            SizedBox(width: 110, child: Text(tr(toDouble(x['debit']) > 0 ? _m(c, x['debit']) : ''), textAlign: TextAlign.right, style: _num)),
                            SizedBox(width: 110, child: Text(tr(toDouble(x['credit']) > 0 ? _m(c, x['credit']) : ''), textAlign: TextAlign.right, style: _num)),
                            SizedBox(width: 120, child: Text(tr(_m(c, x['balance'])), textAlign: TextAlign.right, style: _num.copyWith(fontWeight: FontWeight.w700))),
                          ]),
                        ),
                    ]),
                  ),
                ]);
              },
            ),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Fermer')))],
        ),
      );
}

// ---------------------------------------------------------------------------
// Paramètres : circuit des factures fournisseurs et comptes mémorisés
// ---------------------------------------------------------------------------

class _ReviewSettings extends StatefulWidget {
  const _ReviewSettings({required this.setup, required this.onChanged});

  final Map<String, dynamic> setup;
  final Future<void> Function() onChanged;

  @override
  State<_ReviewSettings> createState() => _ReviewSettingsState();
}

class _ReviewSettingsState extends State<_ReviewSettings> {
  int _reload = 0;

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    const scopes = {'product': 'Article', 'supplier': 'Fournisseur', 'category': 'Catégorie'};
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionCard(
        title: tr('Factures fournisseurs'),
        child: SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr('Vérifier chaque facture avant de la comptabiliser')),
          subtitle: Text(tr('Une facture validée dans Achats attend la comptabilité : approuver, refuser avec un motif (les achats sont prévenus) ou comptabiliser dans le journal des achats.')),
          value: widget.setup['purchase_review'] == true,
          onChanged: (v) async {
            try {
              await api.put('compta/settings', {'purchase_review': v});
              await widget.onChanged();
            } catch (e) {
              if (context.mounted) toast(context, errorText(e), error: true);
            }
          },
        ),
      ),
      const SizedBox(height: 16),
      _Fetch(
        key: ValueKey(_reload),
        path: 'compta/rules',
        builder: (c, raw, _) {
          final rows = List<Map>.from(raw['data'] as List);
          final cats = List<Map>.from(raw['categories'] as List? ?? const []);
          return SectionCard(
            title: tr('Natures d\'achat mémorisées'),
            trailing: cats.isEmpty ? null : TextButton.icon(onPressed: () => _addCategory(cats), icon: const Icon(Icons.add, size: 18), label: Text(tr('Par catégorie'))),
            child: rows.isEmpty
                ? Text(tr('Les comptes choisis en vérifiant les factures sont retenus ici, par article ou par fournisseur.'), style: TextStyle(color: YColors.muted))
                : Column(children: [
                    for (final r in rows)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: StatusChip(scopes[r['scope']] ?? '${r['scope']}', color: YColors.blue),
                        title: Text(tr('${r['name']}')),
                        subtitle: Text(tr('${r['account_code']} · ${r['account_label']}')),
                        trailing: IconButton(
                          tooltip: tr('Oublier'),
                          icon: const Icon(Icons.close, size: 18),
                          onPressed: () async {
                            await api.post('compta/rules', {'scope': r['scope'], 'ref_id': r['ref_id'], 'account_code': null});
                            if (mounted) setState(() => _reload++);
                          },
                        ),
                      ),
                  ]),
          );
        },
      ),
    ]);
  }

  Future<void> _addCategory(List<Map> cats) async {
    String? cat = '${cats.first['id']}';
    var code = '604';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Nature d\'achat d\'une catégorie')),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              DropdownButtonFormField<String>(
                initialValue: cat,
                decoration: InputDecoration(labelText: tr('Catégorie d\'articles')),
                items: [for (final x in cats) DropdownMenuItem(value: '${x['id']}', child: Text(tr('${x['name']}')))],
                onChanged: (v) => set(() => cat = v),
              ),
              const SizedBox(height: 12),
              _AccountPicker(setup: widget.setup, value: code, onChanged: (v) => set(() => code = v)),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer')))],
        ),
      ),
    );
    if (ok != true || cat == null || !mounted) return;
    try {
      await context.read<Session>().api.post('compta/rules', {'scope': 'category', 'ref_id': cat, 'account_code': code});
      if (mounted) setState(() => _reload++);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }
}
