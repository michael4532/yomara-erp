import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'rh_screen.dart';

// ===========================================================================
// Demandes des salariés (tickets) : congé, augmentation, avance, démission…
// Créées hors ligne depuis l'espace salarié, traitées par la RH, responsable en copie.
// ===========================================================================

const requestTypes = <String, (String, IconData, Color)>{
  'leave': ('Congé', Icons.beach_access_outlined, Color(0xFF0EA5E9)),
  'sick': ('Arrêt maladie', Icons.medical_services_outlined, Color(0xFFDC2626)),
  'absence': ('Absence ou permission', Icons.event_busy_outlined, Color(0xFF9333EA)),
  'raise': ('Augmentation de salaire', Icons.trending_up, Color(0xFF12A150)),
  'advance': ('Avance sur salaire', Icons.payments_outlined, Color(0xFFF59E0B)),
  'resignation': ('Démission', Icons.logout, Color(0xFFDC2626)),
  'training': ('Formation', Icons.school_outlined, Color(0xFF7C3AED)),
  'schedule': ('Horaires ou télétravail', Icons.schedule, Color(0xFF0D9488)),
  'transfer': ('Mutation ou changement de poste', Icons.swap_horiz, Color(0xFF1F6FEB)),
  'equipment': ('Matériel ou équipement', Icons.devices_other_outlined, Color(0xFF64748B)),
  'complaint': ('Réclamation', Icons.report_outlined, Color(0xFFEA580C)),
  'other': ('Autre demande', Icons.help_outline, Color(0xFF475569)),
};

const requestStatuses = <String, (String, Color)>{
  'submitted': ('Envoyée', YColors.blue),
  'in_review': ('En cours d\'examen', YColors.warning),
  'approved': ('Acceptée', YColors.success),
  'rejected': ('Refusée', YColors.danger),
  'cancelled': ('Annulée', YColors.muted),
};

/// Demandes avec des dates (créent un congé ou une absence).
const leaveLikeRequests = {'leave', 'sick', 'absence'};

(String, IconData, Color) _type(dynamic t) => requestTypes['$t'] ?? requestTypes['other']!;
(String, Color) _status(dynamic s) => requestStatuses['$s'] ?? ('$s', YColors.muted);

List<Map<String, dynamic>> rhRequests() => LocalDb.hasEntity('rh_requests') ? (LocalDb.all('rh_requests')..sort((a, b) => '${b['created_at'] ?? ''}'.compareTo('${a['created_at'] ?? ''}'))) : [];

String _who(dynamic employeeId) {
  final e = employeeId == null ? null : LocalDb.get('rh_employees', '$employeeId');
  return e == null ? '—' : empName(e);
}

String requestSummary(Map r, String currency) => [
      if ('${r['subject'] ?? ''}'.isNotEmpty && r['subject'] != _type(r['type']).$1) '${r['subject']}',
      if (leaveLikeRequests.contains(r['type']) && r['start_date'] != null) '${leaveTypes['${r['leave_type']}'] ?? 'Congé'} du ${dateFr(r['start_date'])} au ${dateFr(r['end_date'])}',
      if (toDouble(r['amount']) > 0) money(r['amount'], currency),
      if (r['effective_date'] != null) 'à compter du ${dateFr(r['effective_date'])}',
    ].join(' · ');

class _RequestTile extends StatelessWidget {
  const _RequestTile(this.r, {this.showEmployee = false});

  final Map<String, dynamic> r;
  final bool showEmployee;

  @override
  Widget build(BuildContext context) {
    final t = _type(r['type']);
    final st = _status(r['status']);
    final cur = context.read<Session>().currency;
    final msgs = (r['messages'] as List? ?? const []).length;
    final sum = requestSummary(r, cur);
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 4),
      leading: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(color: t.$3.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
        child: Icon(r['_local'] == true ? Icons.cloud_upload_outlined : t.$2, color: t.$3, size: 21),
      ),
      title: Text(tr(showEmployee ? '${_who(r['employee_id'])} · ${t.$1}' : t.$1), style: const TextStyle(fontWeight: FontWeight.w600), maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        tr([
          if (r['number'] != null) '${r['number']}',
          if (sum.isNotEmpty) sum,
          if (r['_local'] == true) 'en attente d\'envoi',
          if (msgs > 0) '$msgs message(s)',
        ].join(' · ')),
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: StatusChip(st.$1, color: st.$2),
      onTap: () => showRequest(context, r),
    );
  }
}

// ---------------------------------------------------------------------------
// Espace salarié
// ---------------------------------------------------------------------------

/// « Mes demandes » (et celles de l'équipe pour un responsable), dans l'espace salarié.
class MyRequestsCard extends StatelessWidget {
  const MyRequestsCard({super.key, required this.employeeId});

  final String employeeId;

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final canRequest = s.can('rh.request');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_requests'),
      builder: (context, _, _) {
        final all = rhRequests();
        final mine = all.where((r) => r['employee_id'] == employeeId).toList();
        final team = all.where((r) => r['manager_id'] == employeeId && r['employee_id'] != employeeId).toList();
        if (!canRequest && mine.isEmpty && team.isEmpty) return const SizedBox.shrink();
        return Column(children: [
          SectionCard(
            title: tr('Mes demandes'),
            trailing: canRequest
                ? FilledButton.tonalIcon(onPressed: () => newRequest(context, employeeId), icon: const Icon(Icons.add, size: 18), label: Text(tr('Nouvelle demande')))
                : null,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (mine.isEmpty)
                Padding(
                  padding: const EdgeInsets.all(8),
                  child: Text(tr('Congé, augmentation, avance sur salaire, formation, démission… Votre demande part au service RH, avec votre responsable en copie.'),
                      style: TextStyle(color: Theme.of(context).hintColor)),
                ),
              for (final r in mine.take(20)) _RequestTile(r),
            ]),
          ),
          if (team.isNotEmpty) ...[
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Demandes de mon équipe'),
              child: Column(children: [for (final r in team.take(20)) _RequestTile(r, showEmployee: true)]),
            ),
          ],
        ]);
      },
    );
  }
}

/// Nouvelle demande : choix du type puis détails (fonctionne hors ligne).
Future<void> newRequest(BuildContext context, String employeeId) async {
  final s = context.read<Session>();
  var enabled = requestTypes.keys.toList();
  var ccOptions = <Map<String, dynamic>>[];
  try {
    final r = asMap(await s.api.get('rh/requests/settings'));
    enabled = [for (final t in (r['enabled'] as List? ?? const [])) '$t'];
    ccOptions = [for (final o in (r['cc_options'] as List? ?? const [])) asMap(o)];
  } catch (_) {
    // Hors ligne : tous les types sont proposés, le serveur vérifie à l'envoi
  }
  if (!context.mounted) return;
  final type = await showFormDialog<String>(context, title: 'Nouvelle demande', maxWidth: 640, builder: (dc) {
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
      Text(tr('Que souhaitez-vous demander ?'), style: TextStyle(color: Theme.of(dc).hintColor)),
      const SizedBox(height: 12),
      LayoutBuilder(builder: (context, c) {
        final cols = c.maxWidth > 480 ? 2 : 1;
        final w = (c.maxWidth - 10 * (cols - 1)) / cols;
        return Wrap(spacing: 10, runSpacing: 10, children: [
          for (final k in enabled)
            SizedBox(
              width: w,
              child: Material(
                color: Theme.of(dc).colorScheme.surfaceContainerLow,
                borderRadius: BorderRadius.circular(14),
                child: InkWell(
                  borderRadius: BorderRadius.circular(14),
                  onTap: () => Navigator.pop(dc, k),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Row(children: [
                      Container(
                        width: 38,
                        height: 38,
                        decoration: BoxDecoration(color: _type(k).$3.withValues(alpha: .14), borderRadius: BorderRadius.circular(11)),
                        child: Icon(_type(k).$2, color: _type(k).$3, size: 20),
                      ),
                      const SizedBox(width: 12),
                      Expanded(child: Text(tr(_type(k).$1), style: const TextStyle(fontWeight: FontWeight.w600))),
                      Icon(Icons.chevron_right, color: Theme.of(dc).hintColor),
                    ]),
                  ),
                ),
              ),
            ),
        ]);
      }),
    ]);
  });
  if (type == null || !context.mounted) return;
  await showFormDialog(context, title: _type(type).$1, maxWidth: 600, builder: (_) => _RequestForm(type: type, employeeId: employeeId, ccOptions: ccOptions));
}

class _RequestForm extends StatefulWidget {
  const _RequestForm({required this.type, required this.employeeId, this.ccOptions = const []});

  final String type;
  final String employeeId;
  final List<Map<String, dynamic>> ccOptions;

  @override
  State<_RequestForm> createState() => _RequestFormState();
}

class _RequestFormState extends State<_RequestForm> {
  final String _id = newId();
  final Set<int> _cc = {};
  (List<int>, String)? _file;
  final _subject = TextEditingController();
  final _body = TextEditingController();
  final _amount = TextEditingController();
  String _leaveType = 'annual';
  DateTime? _start, _end, _effective;
  bool _busy = false;

  Future<DateTime?> _pick(DateTime? init) => pickDate(context, firstDate: DateTime.now().subtract(const Duration(days: 60)), lastDate: DateTime(2100), initialDate: init ?? DateTime.now());

  Widget _date(String label, DateTime? v, ValueChanged<DateTime> set) => Field(
        label: label,
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () async {
            final d = await _pick(v);
            if (d != null) setState(() => set(d));
          },
          child: InputDecorator(
            decoration: const InputDecoration(suffixIcon: Icon(Icons.event, size: 18)),
            child: Text(tr(v == null ? 'jj/mm/aaaa' : dateFr(isoDate(v))), style: TextStyle(color: v == null ? Theme.of(context).hintColor : null)),
          ),
        ),
      );

  Future<void> _send() async {
    final t = widget.type;
    String? err;
    if (leaveLikeRequests.contains(t) && (_start == null || _end == null)) err = 'Indiquez les dates.';
    if (leaveLikeRequests.contains(t) && _start != null && _end != null && _end!.isBefore(_start!)) err = 'La date de fin précède la date de début.';
    if (t == 'advance' && (num.tryParse(_amount.text.replaceAll(' ', '')) ?? 0) <= 0) err = 'Indiquez le montant de l\'avance.';
    if (t == 'resignation' && _effective == null) err = 'Indiquez votre dernier jour de travail souhaité.';
    if (!leaveLikeRequests.contains(t) && _body.text.trim().isEmpty && _subject.text.trim().isEmpty) err = 'Expliquez votre demande en quelques mots.';
    if (err != null) return toast(context, tr(err), error: true);
    setState(() => _busy = true);
    try {
      await context.read<SyncEngine>().save('rh_requests', {
        'id': _id,
        'employee_id': widget.employeeId,
        'type': t,
        'subject': _subject.text.trim().isEmpty ? _type(t).$1 : _subject.text.trim(),
        'body': _body.text.trim().isEmpty ? null : _body.text.trim(),
        'amount': num.tryParse(_amount.text.replaceAll(' ', '')),
        if (leaveLikeRequests.contains(t)) ...{if (t == 'leave') 'leave_type': _leaveType, 'start_date': isoDate(_start!), 'end_date': isoDate(_end!)},
        if (_effective != null) 'effective_date': isoDate(_effective!),
        if (_cc.isNotEmpty) 'cc_user_ids': _cc.toList(),
        'status': 'submitted',
      });
      // Justificatif : envoyé dès que la demande est arrivée au serveur
      var uploaded = _file == null;
      if (_file != null && mounted) {
        try {
          await context.read<SyncEngine>().sync();
          if (mounted) await context.read<Session>().api.upload('rh/requests/$_id/attachments', 'file', _file!.$1, _file!.$2);
          uploaded = true;
        } catch (_) {}
      }
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr(uploaded
            ? 'Demande envoyée au service RH, votre responsable est en copie.'
            : 'Demande enregistrée. Le justificatif n\'a pas pu partir (hors ligne) : ajoutez-le depuis la demande dès le retour du réseau.'));
      }
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = widget.type;
    final narrow = MediaQuery.of(context).size.width < 600;
    Widget pair(Widget a, Widget b) => narrow ? Column(children: [a, b]) : Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: a), const SizedBox(width: 12), Expanded(child: b)]);
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      if (leaveLikeRequests.contains(t)) ...[
        if (t == 'leave')
        Field(
          label: tr('Type de congé'),
          child: DropdownButtonFormField<String>(
            isExpanded: true,
            initialValue: _leaveType,
            items: [for (final e in leaveTypes.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
            onChanged: (v) => setState(() => _leaveType = v!),
          ),
        ),
        pair(_date('Du', _start, (d) => _start = d), _date('Au', _end, (d) => _end = d)),
        if (_start != null && _end != null && !_end!.isBefore(_start!))
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(tr('${number(workingDaysBetween(_start!, _end!), decimals: 1)} jour(s) ouvrable(s)'), style: TextStyle(color: Theme.of(context).hintColor)),
          ),
      ] else
        Field(label: tr('Objet'), child: TextField(controller: _subject, decoration: InputDecoration(hintText: tr(_type(t).$1)))),
      if (t == 'raise' || t == 'advance')
        Field(
          label: tr(t == 'raise' ? 'Augmentation souhaitée (montant mensuel, facultatif)' : 'Montant de l\'avance'),
          child: TextField(controller: _amount, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9\s]'))]),
        ),
      if (t == 'resignation' || t == 'transfer') _date(t == 'resignation' ? 'Dernier jour de travail souhaité' : 'À compter du', _effective, (d) => _effective = d),
      Field(
        label: tr(leaveLikeRequests.contains(t) ? 'Motif (facultatif)' : 'Votre message'),
        child: TextField(controller: _body, minLines: 4, maxLines: 10, decoration: InputDecoration(hintText: tr(t == 'resignation' ? 'Ex. : je vous informe de ma décision de démissionner…' : 'Expliquez votre demande'))),
      ),
      if (t == 'resignation')
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Text(tr('Le préavis dépend de votre contrat et de votre catégorie : le service RH vous confirme la date de fin.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
        ),
      Field(
        label: tr(t == 'sick' ? 'Certificat médical ou justificatif' : 'Pièce jointe (facultatif)'),
        child: Row(children: [
          OutlinedButton.icon(
            onPressed: () async {
              final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx']);
              if (files.isEmpty) return;
              final b = await files.first.readAsBytes();
              if (b.length > 10 * 1024 * 1024) {
                if (mounted) toast(this.context, tr('Fichier trop lourd (10 Mo maximum).'), error: true);
                return;
              }
              setState(() => _file = (b, files.first.name));
            },
            icon: const Icon(Icons.attach_file, size: 18),
            label: Text(tr(_file == null ? 'Joindre un fichier' : 'Changer')),
          ),
          const SizedBox(width: 10),
          if (_file != null) Expanded(child: Text(_file!.$2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600))),
        ]),
      ),
      if (widget.ccOptions.isNotEmpty)
        Field(
          label: tr('Mettre aussi en copie (facultatif)'),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final o in widget.ccOptions)
              FilterChip(
                avatar: Icon(o['group'] == 'Direction' ? Icons.workspace_premium_outlined : Icons.badge_outlined, size: 16),
                label: Text(tr('${o['name']} · ${o['group']}')),
                selected: _cc.contains(o['user_id']),
                onSelected: (v) => setState(() => v ? _cc.add((o['user_id'] as num).toInt()) : _cc.remove(o['user_id'])),
              ),
          ]),
        ),
      Row(children: [
        Icon(Icons.forward_to_inbox_outlined, size: 18, color: Theme.of(context).hintColor),
        const SizedBox(width: 8),
        Expanded(child: Text(tr('Envoyée au service RH par e-mail, votre responsable en copie. Suivez la réponse dans « Mes demandes ».'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
      ]),
      const SizedBox(height: 16),
      FilledButton.icon(onPressed: _busy ? null : _send, icon: const Icon(Icons.send, size: 18), label: Text(tr(_busy ? 'Envoi…' : 'Envoyer la demande'))),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Fiche d'une demande (ticket) : détails, fil d'échanges, décision
// ---------------------------------------------------------------------------

Future<void> showRequest(BuildContext context, Map<String, dynamic> request) {
  return showFormDialog(context, title: '${request['number'] ?? 'Demande'} · ${_type(request['type']).$1}', maxWidth: 720, builder: (_) => _RequestView(id: '${request['id']}'));
}

class _RequestView extends StatefulWidget {
  const _RequestView({required this.id});

  final String id;

  @override
  State<_RequestView> createState() => _RequestViewState();
}

class _RequestViewState extends State<_RequestView> {
  final _msg = TextEditingController();
  bool _busy = false;

  Future<void> _attach() async {
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx']);
    if (files.isEmpty || !mounted) return;
    final b = await files.first.readAsBytes();
    if (b.length > 10 * 1024 * 1024) {
      if (mounted) toast(context, tr('Fichier trop lourd (10 Mo maximum).'), error: true);
      return;
    }
    if (!mounted) return;
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.upload('rh/requests/${widget.id}/attachments', 'file', b, files.first.name);
      if (mounted) await context.read<SyncEngine>().sync();
      if (mounted) toast(context, tr('Justificatif envoyé (par e-mail aussi, responsable en copie).'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _open(Map a) async {
    try {
      final bytes = await context.read<Session>().api.download('rh/requests/${widget.id}/attachments/${a['id']}');
      final where = await saveFile('${a['name']}', bytes, mime: '${a['mime'] ?? 'application/octet-stream'}');
      if (mounted && where.isNotEmpty) toast(context, tr('Enregistré : $where'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _comment() async {
    final text = _msg.text.trim();
    if (text.isEmpty) return;
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('rh/requests/${widget.id}/comment', {'text': text});
      _msg.clear();
      if (mounted) await context.read<SyncEngine>().sync();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _decide(String status) async {
    final note = TextEditingController();
    final label = _status(status).$1;
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr(status == 'in_review' ? 'Passer en cours d\'examen' : 'Demande ${label.toLowerCase()}')),
        content: SizedBox(
          width: 440,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            TextField(controller: note, minLines: 3, maxLines: 6, decoration: InputDecoration(labelText: tr(status == 'rejected' ? 'Motif du refus' : 'Réponse au salarié (facultatif)'))),
            const SizedBox(height: 8),
            Text(tr('Le salarié est prévenu par e-mail, son responsable en copie.'), style: TextStyle(fontSize: 12, color: Theme.of(d).hintColor)),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))),
          FilledButton(
            style: status == 'rejected' ? FilledButton.styleFrom(backgroundColor: YColors.danger) : null,
            onPressed: () => Navigator.pop(d, true),
            child: Text(tr('Confirmer')),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('rh/requests/${widget.id}/decide', {'status': status, 'note': note.text.trim().isEmpty ? null : note.text.trim()});
      if (mounted) await context.read<SyncEngine>().sync();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _cancel() async {
    if (!await confirm(context, tr('Annuler cette demande ?'), tr('Le service RH et votre responsable en seront informés.'), ok: tr('Annuler la demande'))) return;
    if (!mounted) return;
    try {
      await context.read<SyncEngine>().save('rh_requests', {'id': widget.id, 'status': 'cancelled'});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_requests'),
      builder: (context, _, _) {
        final r = LocalDb.get('rh_requests', widget.id);
        if (r == null) return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
        final theme = Theme.of(context);
        final t = _type(r['type']);
        final st = _status(r['status']);
        final me = myEmployee(s);
        final isOwner = me != null && me['id'] == r['employee_id'];
        final hr = s.can('rh.manage');
        final open = r['status'] == 'submitted' || r['status'] == 'in_review';
        final local = r['_local'] == true;
        final messages = [for (final m in (r['messages'] as List? ?? const [])) asMap(m)];
        final rows = <(String, String)>[
          ('Salarié', _who(r['employee_id'])),
          if (r['manager_id'] != null) ('Responsable (en copie)', _who(r['manager_id'])),
          ('Envoyée le', r['created_at'] == null ? 'en attente d\'envoi' : dateTimeFr('${r['created_at']}')),
          if (leaveLikeRequests.contains(r['type']) && r['start_date'] != null) ('Période', '${leaveTypes['${r['leave_type']}'] ?? 'Congé'} du ${dateFr(r['start_date'])} au ${dateFr(r['end_date'])}'),
          if (toDouble(r['amount']) > 0) ('Montant', money(r['amount'], s.currency)),
          if (r['effective_date'] != null) (r['type'] == 'resignation' ? 'Dernier jour souhaité' : 'À compter du', dateFr(r['effective_date'])),
        ];
        Widget bubble({required String who, required String role, required String when, required String text, Color? color}) {
          final c = color ?? (role == 'hr' ? YColors.blue : (role == 'manager' ? const Color(0xFF7C3AED) : YColors.success));
          return Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              InitialsAvatar(who, size: 32),
              const SizedBox(width: 10),
              Expanded(
                child: Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: c.withValues(alpha: .07), borderRadius: BorderRadius.circular(12), border: Border.all(color: c.withValues(alpha: .18))),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Expanded(child: Text(tr('$who · ${const {'hr': 'Service RH', 'manager': 'Responsable', 'employee': 'Salarié'}[role] ?? ''}'), style: TextStyle(fontWeight: FontWeight.w700, color: c, fontSize: 12.5))),
                      Text(tr(when), style: TextStyle(color: theme.hintColor, fontSize: 11.5)),
                    ]),
                    const SizedBox(height: 4),
                    SelectableText(text, style: const TextStyle(height: 1.4)),
                  ]),
                ),
              ),
            ]),
          );
        }

        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
          Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(color: t.$3.withValues(alpha: .12), borderRadius: BorderRadius.circular(13)),
              child: Icon(t.$2, color: t.$3),
            ),
            const SizedBox(width: 12),
            Expanded(child: Text(tr('${r['subject'] ?? t.$1}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
            StatusChip(local ? 'En attente d\'envoi' : st.$1, color: local ? YColors.warning : st.$2),
          ]),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: theme.colorScheme.surfaceContainerLow, borderRadius: BorderRadius.circular(12)),
            child: Column(children: [
              for (final row in rows)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    SizedBox(width: 170, child: Text(tr(row.$1), style: TextStyle(color: theme.hintColor))),
                    Expanded(child: Text(tr(row.$2), style: const TextStyle(fontWeight: FontWeight.w600))),
                  ]),
                ),
            ]),
          ),
          const SizedBox(height: 16),
          Row(children: [
            Text(tr('Justificatifs'), style: TextStyle(fontWeight: FontWeight.w700, color: theme.colorScheme.primary)),
            const Spacer(),
            if (r['status'] != 'cancelled' && !local) TextButton.icon(onPressed: _busy ? null : _attach, icon: const Icon(Icons.attach_file, size: 18), label: Text(tr('Ajouter'))),
          ]),
          if ((r['attachments'] as List? ?? const []).isEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(tr(r['type'] == 'sick' ? 'Aucun certificat joint pour l\'instant.' : 'Aucune pièce jointe.'), style: TextStyle(color: r['type'] == 'sick' ? YColors.warning : theme.hintColor, fontSize: 12.5)),
            ),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final a in [for (final x in (r['attachments'] as List? ?? const [])) asMap(x)])
              ActionChip(
                avatar: Icon('${a['mime']}'.contains('pdf') ? Icons.picture_as_pdf_outlined : Icons.image_outlined, size: 16),
                label: Text(tr('${a['name']}')),
                tooltip: tr('Ajouté par ${a['by'] ?? ''} le ${dateTimeFr('${a['at']}')}'),
                onPressed: () => _open(a),
              ),
          ]),
          const SizedBox(height: 16),
          Text(tr('Échanges'), style: TextStyle(fontWeight: FontWeight.w700, color: theme.colorScheme.primary)),
          const SizedBox(height: 10),
          if ('${r['body'] ?? ''}'.trim().isNotEmpty)
            bubble(who: _who(r['employee_id']), role: 'employee', when: r['created_at'] == null ? '' : dateTimeFr('${r['created_at']}'), text: '${r['body']}'),
          for (final m in messages) bubble(who: '${m['name']}', role: '${m['role']}', when: dateTimeFr('${m['at']}'), text: '${m['text']}'),
          if (r['status'] == 'approved' || r['status'] == 'rejected')
            bubble(
              who: 'Service RH',
              role: 'hr',
              when: r['decided_at'] == null ? '' : dateTimeFr('${r['decided_at']}'),
              text: '${r['status'] == 'approved' ? 'Demande acceptée.' : 'Demande refusée.'}${'${r['decision_note'] ?? ''}'.isEmpty ? '' : '\n${r['decision_note']}'}',
              color: st.$2,
            ),
          if (r['status'] != 'cancelled' && !local) ...[
            const SizedBox(height: 4),
            TextField(
              controller: _msg,
              minLines: 1,
              maxLines: 5,
              decoration: InputDecoration(
                hintText: tr('Écrire un message (envoyé par e-mail, responsable en copie)'),
                suffixIcon: IconButton(onPressed: _busy ? null : _comment, icon: const Icon(Icons.send)),
              ),
            ),
          ],
          const SizedBox(height: 16),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (hr && open && !local) ...[
              FilledButton.icon(onPressed: _busy ? null : () => _decide('approved'), icon: const Icon(Icons.check, size: 18), label: Text(tr('Accepter'))),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: YColors.danger),
                onPressed: _busy ? null : () => _decide('rejected'),
                icon: const Icon(Icons.close, size: 18),
                label: Text(tr('Refuser')),
              ),
              if (r['status'] == 'submitted') OutlinedButton.icon(onPressed: _busy ? null : () => _decide('in_review'), icon: const Icon(Icons.hourglass_top, size: 18), label: Text(tr('En cours d\'examen'))),
            ],
            if (isOwner && open) TextButton.icon(onPressed: _cancel, icon: const Icon(Icons.undo, size: 18), label: Text(tr('Annuler ma demande'))),
          ]),
        ]);
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Onglet RH : toutes les demandes
// ---------------------------------------------------------------------------

class RhRequestsTab extends StatefulWidget {
  const RhRequestsTab({super.key, required this.manage});

  final bool manage;

  @override
  State<RhRequestsTab> createState() => _RhRequestsTabState();
}

class _RhRequestsTabState extends State<RhRequestsTab> {
  String _filter = 'open';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    if (!LocalDb.hasEntity('rh_requests')) {
      return EmptyState(icon: Icons.sync, title: tr('Synchronisation nécessaire'), message: tr('Mettez à jour le serveur puis synchronisez pour recevoir les demandes des salariés.'));
    }
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_requests'),
      builder: (context, _, _) {
        final all = rhRequests();
        final month = isoDate(DateTime.now()).substring(0, 7);
        final q = _q.toLowerCase();
        bool st(Map<String, dynamic> r, String k) => switch (k) {
              'open' => r['status'] == 'submitted' || r['status'] == 'in_review',
              '' => true,
              _ => r['status'] == k,
            };
        bool base(Map<String, dynamic> r) =>
            (q.isEmpty || '${_who(r['employee_id'])} ${r['number'] ?? ''} ${r['subject'] ?? ''} ${_type(r['type']).$1}'.toLowerCase().contains(q)) &&
            (_f['type'] == null || '${r['type']}' == _f['type']) &&
            (_f['employee'] == null || '${r['employee_id']}' == _f['employee']) &&
            (_f['department'] == null || '${LocalDb.get('rh_employees', '${r['employee_id']}')?['department'] ?? ''}' == _f['department']) &&
            (_period == null || _period!.contains(r['created_at']));
        final shown = all.where((r) => base(r) && st(r, _filter)).toList();
        const stKeys = ['open', 'approved', 'rejected', 'cancelled', ''];
        return ListView(padding: const EdgeInsets.only(top: 16, bottom: 32), children: [
          StatStrip(tiles: [
            StatTile(label: 'À traiter', value: '${all.where((r) => r['status'] == 'submitted').length}', detail: 'nouvelles demandes', icon: Icons.mark_email_unread_outlined, color: YColors.blue),
            StatTile(label: 'En cours d\'examen', value: '${all.where((r) => r['status'] == 'in_review').length}', icon: Icons.hourglass_top, color: YColors.warning),
            StatTile(label: 'Acceptées ce mois', value: '${all.where((r) => r['status'] == 'approved' && '${r['decided_at'] ?? ''}'.startsWith(month)).length}', icon: Icons.task_alt, color: YColors.success),
            StatTile(label: 'Total', value: '${all.length}', detail: 'demandes reçues', icon: Icons.inbox_outlined, color: const Color(0xFF7C3AED)),
          ]),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: pad),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              FilterBar(
                padding: EdgeInsets.zero,
                onSearch: (v) => setState(() => _q = v),
                search: _q,
                searchHint: 'Salarié, numéro, objet…',
                statuses: const {'open': 'À traiter', 'approved': 'Acceptées', 'rejected': 'Refusées', 'cancelled': 'Annulées', '': 'Toutes'},
                status: _filter,
                onStatus: (v) => setState(() => _filter = v),
                statusCounts: {for (final k in stKeys) k: all.where((r) => base(r) && st(r, k)).length},
                filters: [
                  FilterSpec('type', 'Type de demande', {for (final e in requestTypes.entries) e.key: e.value.$1}, icon: Icons.category_outlined),
                  FilterSpec('employee', 'Salarié', filterOptions(all, 'employee_id', label: (r) => _who(r['employee_id'])), icon: Icons.person_outline),
                  FilterSpec(
                    'department',
                    'Service',
                    filterOptions([for (final r in all) {'d': LocalDb.get('rh_employees', '${r['employee_id']}')?['department']}], 'd'),
                    icon: Icons.apartment_outlined,
                  ),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                showPeriod: true,
                period: _period,
                onPeriod: (p) => setState(() => _period = p),
                count: shown.length,
                countLabel: 'demande',
                onReset: () => setState(() => (_q = '', _filter = 'open', _f = {}, _period = null)),
                trailing: [
                  if (widget.manage) OutlinedButton.icon(onPressed: () => _settings(context), icon: const Icon(Icons.tune, size: 18), label: Text(tr('Réglages'))),
                ],
              ),
              const SizedBox(height: 12),
              if (shown.isEmpty)
                EmptyState(
                  icon: Icons.inbox_outlined,
                  title: tr(_filter == 'open' ? 'Aucune demande à traiter' : 'Aucune demande'),
                  message: tr('Les salariés autorisés envoient leurs demandes depuis « Mon espace » : congé, augmentation, avance, formation, démission…'),
                )
              else
                Card(clipBehavior: Clip.antiAlias, child: Padding(padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 8), child: Column(children: [for (final r in shown) _RequestTile(r, showEmployee: true)]))),
            ]),
          ),
        ]);
      },
    );
  }

  Future<void> _settings(BuildContext context) async {
    final api = context.read<Session>().api;
    Map<String, dynamic> st;
    try {
      st = asMap(await api.get('rh/requests/settings'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!context.mounted) return;
    final enabled = <String>{for (final t in (st['enabled'] as List? ?? const [])) '$t'};
    final email = TextEditingController(text: '${st['email'] ?? ''}');
    await showFormDialog(context, title: 'Demandes des salariés : réglages', builder: (dc) {
      return StatefulBuilder(builder: (dc, setS) {
        return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
          Text(tr('Types de demandes proposés aux salariés'), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final k in requestTypes.keys)
              FilterChip(
                avatar: Icon(_type(k).$2, size: 16),
                label: Text(tr(_type(k).$1)),
                selected: enabled.contains(k),
                onSelected: (v) => setS(() => v ? enabled.add(k) : enabled.remove(k)),
              ),
          ]),
          const SizedBox(height: 16),
          Field(
            label: tr('Adresse(s) e-mail du service RH'),
            child: TextField(controller: email, decoration: InputDecoration(hintText: tr('rh@entreprise.ci (vide : les comptes RH)'))),
          ),
          Text(
            tr(st['email_ready'] == true
                ? 'Les e-mails partent par le canal de la société. Le responsable direct du salarié (fiche salarié, sinon titulaire du poste supérieur) est toujours en copie.'
                : 'Aucun canal e-mail configuré (Paramètres → Canaux d\'envoi) : les demandes arrivent seulement dans l\'application.'),
            style: TextStyle(color: st['email_ready'] == true ? Theme.of(dc).hintColor : YColors.warning, fontSize: 12.5),
          ),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .07), borderRadius: BorderRadius.circular(12)),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Icon(Icons.admin_panel_settings_outlined, color: YColors.blue, size: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  tr('Qui peut faire une demande ? L\'administrateur accorde le droit « Faire des demandes depuis son espace » : Paramètres → Utilisateurs → un utilisateur → Modules et droits (Ressources humaines), ou dans son rôle.'),
                  style: const TextStyle(fontSize: 12.5),
                ),
              ),
            ]),
          ),
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerRight,
            child: SaveButton(
              onPressed: () async {
                if (enabled.isEmpty) return toast(dc, tr('Gardez au moins un type de demande.'), error: true);
                try {
                  await api.put('rh/requests/settings', {'enabled': enabled.toList(), 'email': email.text.trim().isEmpty ? null : email.text.trim()});
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ),
        ]);
      });
    });
  }
}
