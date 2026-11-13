import 'package:yomara_erp/ui/widgets/auto_update.dart';
import 'dart:convert';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_quill/flutter_quill.dart';
import 'package:intl/intl.dart';
import 'package:uuid/uuid.dart';
import 'package:vsc_quill_delta_to_html/vsc_quill_delta_to_html.dart';

import '../../../core/local_db.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/mail_html.dart';
import 'mail_store.dart';
import 'mail_theme.dart';
import 'mailbox_screen.dart' show AccountAvatar;
import 'mailbox_settings.dart' show editSignature, manageSignatures;
import '../../../i18n/i18n.dart';
import '../../../core/json.dart';

/// Ouvre la fenêtre de rédaction. [mode] : new, reply, reply_all, forward.
Future<void> openComposer(BuildContext context, MailStore store, {String mode = 'new', Map<String, dynamic>? original, List<Map<String, String>>? to, String? subject, String? bodyText}) {
  final phone = MediaQuery.sizeOf(context).width < 700;
  final accent = MailTheme.current.accent;
  final base = Theme.of(context);
  Widget themed(Widget w) => Theme(data: base.copyWith(colorScheme: base.colorScheme.copyWith(primary: accent)), child: w);
  if (phone) {
    return showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => themed(Dialog.fullscreen(child: SafeArea(child: ComposeView(store: store, mode: mode, original: original, to: to, subject: subject, bodyText: bodyText)))),
    );
  }
  return showDialog(
    context: context,
    barrierDismissible: false,
    builder: (_) => themed(Dialog(
      insetPadding: const EdgeInsets.all(18),
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 980, maxHeight: 860),
        child: ComposeView(store: store, mode: mode, original: original, to: to, subject: subject, bodyText: bodyText),
      ),
    )),
  );
}

class ComposeView extends StatefulWidget {
  const ComposeView({super.key, required this.store, this.mode = 'new', this.original, this.to, this.subject, this.bodyText});

  final MailStore store;
  final String mode;
  final Map<String, dynamic>? original;
  final List<Map<String, String>>? to;
  final String? subject;
  final String? bodyText;

  @override
  State<ComposeView> createState() => _ComposeViewState();
}

class _ComposeViewState extends State<ComposeView> {
  final _id = const Uuid().v4();
  final _quill = QuillController.basic();
  // Mention « @ » : recherche d'un contact pendant la saisie (comme dans Outlook)
  String? _mentionQ;
  int _mentionAt = -1;
  int _mentionSel = 0;
  List<Map<String, String>> _mentionHits = const [];
  late final _subject = TextEditingController(text: _initialSubject());
  final List<Map<String, String>> _to = [], _cc = [], _bcc = [];
  final List<Map<String, dynamic>> _atts = [];
  bool _showCc = false, _receipt = false, _high = false, _busy = false, _showQuote = false;
  late bool _track = s.trackClicksDefault;
  int? _accountId;
  int? _signatureId;
  String _mode = 'rich'; // rich : mise en forme ; html : code HTML
  final _html = TextEditingController();
  bool _htmlPreview = false;
  DateTime? _sendAt;

  MailStore get s => widget.store;
  Map<String, dynamic>? get o => widget.original;

  String _initialSubject() {
    final sub = '${o?['subject'] ?? ''}';
    if (widget.mode == 'forward') return RegExp(r'^(tr|fw|fwd)\s*:', caseSensitive: false).hasMatch(sub) ? sub : 'TR : $sub';
    if (widget.mode.startsWith('reply')) return RegExp(r'^re\s*:', caseSensitive: false).hasMatch(sub) ? sub : 'RE : $sub';
    return widget.subject ?? '';
  }

  @override
  void initState() {
    super.initState();
    AutoUpdate.hold.value++; // pas de rechargement automatique pendant ce travail
    final sendable = s.accounts.where((a) => a['status'] != 'paused').toList();
    _accountId = (o != null ? o!['account_id'] as int? : null) ?? (sendable.isNotEmpty ? sendable.first['id'] as int : (s.accounts.isNotEmpty ? s.accounts.first['id'] as int : null));
    if (widget.to != null) _to.addAll(widget.to!);
    if (o != null && widget.mode.startsWith('reply')) {
      final me = '${s.account(_accountId)?['email'] ?? ''}'.toLowerCase();
      final sent = s.folder(o!['folder_id'])?['role'] == 'sent';
      final replyTo = (o!['reply_to'] as String?) ?? '${o!['from_email'] ?? ''}';
      if (sent) {
        for (final t in (o!['to'] as List? ?? const [])) {
          _to.add({'name': '${t['name'] ?? ''}', 'email': '${t['email']}'});
        }
      } else if (replyTo.isNotEmpty) {
        _to.add({'name': replyTo == o!['from_email'] ? '${o!['from_name'] ?? ''}' : '', 'email': replyTo});
      }
      if (widget.mode == 'reply_all') {
        for (final t in [...(o!['to'] as List? ?? const []), ...(o!['cc'] as List? ?? const [])]) {
          final e = '${t['email']}'.toLowerCase();
          if (e != me && !_to.any((x) => x['email'] == e)) _cc.add({'name': '${t['name'] ?? ''}', 'email': e});
        }
        _showCc = _cc.isNotEmpty;
      }
    }
    if (o != null && widget.mode == 'forward') {
      for (final a in (o!['attachments'] as List? ?? const [])) {
        if (a['inline'] != true) _atts.add({'type': 'original', 'id': '${a['id']}', 'name': '${a['name']}', 'size': a['size']});
      }
    }
    _pickSignature();
    if (widget.bodyText != null) _quill.document.insert(0, widget.bodyText!);
    _quill.addListener(_watchMention);
  }

  void _pickSignature() {
    final reply = widget.mode != 'new';
    // Signature attribuée à cette boîte (nouveaux messages / réponses et transferts)
    final assigned = s.signatureDefaults[_accountId];
    if (assigned != null) {
      final id = assigned[reply ? 'reply_id' : 'new_id'] as int?;
      _signatureId = id != null && s.signatures.any((x) => x['id'] == id) ? id : null;
      return;
    }
    final sigs = s.signatures.where((x) => x['account_id'] == null || x['account_id'] == _accountId).toList();
    final def = sigs.where((x) => x['account_id'] == _accountId && x['is_default'] == true).firstOrNull ??
        sigs.where((x) => x['is_default'] == true && x['user_id'] != null).firstOrNull ??
        sigs.where((x) => x['is_default'] == true).firstOrNull;
    _signatureId = def == null || (reply && def['on_reply'] == false) ? null : def['id'] as int;
  }

  void _watchMention() {
    final sel = _quill.selection;
    if (!sel.isCollapsed) return _closeMention();
    final text = _quill.document.toPlainText();
    final end = sel.baseOffset.clamp(0, text.length);
    final before = text.substring(0, end);
    final m = RegExp(r'(?:^|[\s(])@([\p{L}\p{N}._\-]{0,30})$', unicode: true).firstMatch(before);
    if (m == null) return _closeMention();
    final q = m.group(1)!.toLowerCase();
    final hits = s.contacts().where((c) => q.isEmpty || c['name']!.toLowerCase().contains(q) || c['email']!.contains(q)).take(6).toList();
    setState(() {
      _mentionQ = q;
      _mentionAt = end - q.length - 1;
      _mentionHits = hits;
      _mentionSel = 0;
    });
  }

  void _closeMention() {
    if (_mentionQ != null) setState(() => _mentionQ = null);
  }

  /// Insère le nom du contact à la place de « @recherche » et l'ajoute aux destinataires (À).
  void _pickMention(Map<String, String> c) {
    final name = c['name']!.isNotEmpty ? c['name']! : c['email']!;
    final len = (_mentionQ ?? '').length + 1;
    final at = _mentionAt;
    _quill.removeListener(_watchMention);
    _quill.replaceText(at, len, '@$name ', TextSelection.collapsed(offset: at + name.length + 2));
    _quill.formatText(at, name.length + 1, Attribute.bold);
    _quill.formatText(at + name.length + 1, 1, Attribute.clone(Attribute.bold, null));
    _quill.addListener(_watchMention);
    setState(() {
      if (!_to.any((x) => x['email'] == c['email']) && !_cc.any((x) => x['email'] == c['email'])) _to.add({'name': c['name']!, 'email': c['email']!});
      _mentionQ = null;
    });
    toast(context, tr('$name ajouté(e) aux destinataires.'));
  }

  Widget _mentionPanel() {
    final hint = Theme.of(context).hintColor;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      constraints: const BoxConstraints(maxWidth: 420),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: YColors.line),
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .08), blurRadius: 16, offset: const Offset(0, 6))],
      ),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 4),
          child: Text(tr(_mentionHits.isEmpty ? 'Aucun contact pour « @${_mentionQ ?? ''} »' : 'Mentionner un contact · il sera ajouté aux destinataires'), style: TextStyle(color: hint, fontSize: 11.5, fontWeight: FontWeight.w600)),
        ),
        for (final (i, c) in _mentionHits.indexed)
          ListTile(
            dense: true,
            selected: i == _mentionSel,
            leading: InitialsAvatar(c['name']!.isNotEmpty ? c['name']! : c['email']!, size: 28),
            title: Text(tr(c['name']!.isNotEmpty ? c['name']! : c['email']!), style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text(tr(c['label'] != null ? '${c['email']} · ${c['label']}' : c['email']!)),
            onTap: () => _pickMention(c),
          ),
      ]),
    );
  }

  @override
  void dispose() {
    AutoUpdate.hold.value--;
    _quill.removeListener(_watchMention);
    _html.dispose();
    _quill.dispose();
    _subject.dispose();
    super.dispose();
  }

  String _richHtml() {
    final ops = List<Map<String, dynamic>>.from(_quill.document.toDelta().toJson().map((e) => asMap(e)));
    return QuillDeltaToHtmlConverter(ops, ConverterOptions.forEmail()).convert();
  }

  Future<void> _switchMode(String m) async {
    if (m == _mode) return;
    if (m == 'html') {
      final cur = _quill.document.toPlainText().trim().isEmpty ? '' : _richHtml();
      setState(() {
        _html.text = cur;
        _mode = 'html';
      });
      return;
    }
    if (_html.text.trim().isNotEmpty && !await confirm(context, tr('Revenir à la mise en forme simple ?'), tr('Le texte est conservé, mais la mise en page HTML (tableaux, images, couleurs) sera perdue.'), ok: tr('Continuer'))) {
      return;
    }
    final text = _html.text
        .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
        .replaceAll(RegExp(r'</(p|div|li|h\d|tr)>', caseSensitive: false), '\n')
        .replaceAll(RegExp(r'<(style|script)[^>]*>[\s\S]*?</\1>', caseSensitive: false), '')
        .replaceAll(RegExp(r'<[^>]+>'), '')
        .replaceAll('&nbsp;', ' ')
        .replaceAll('&amp;', '&')
        .trim();
    final doc = Document();
    if (text.isNotEmpty) doc.insert(0, '$text\n');
    _quill.document = doc;
    setState(() => _mode = 'rich');
  }

  Future<void> _insertImage() async {
    final url = await uploadMailImage(context, s);
    if (url == null) return;
    if (_mode != 'html') await _switchMode('html');
    insertAtCursor(_html, '<img src="$url" alt="" style="max-width:100%;height:auto;border:0">');
    if (mounted) setState(() {});
  }

  void _insertSkeleton() {
    final accent = '#${(Theme.of(context).colorScheme.primary.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0')}';
    insertAtCursor(_html, emailSkeleton(accent));
    setState(() {});
  }

  String _bodyHtml() {
    final html = _mode == 'html' ? _html.text : _richHtml();
    final sig = s.signatures.where((x) => x['id'] == _signatureId).firstOrNull;
    final b = StringBuffer(html);
    if (sig != null) b.write('<br><div class="yomara-signature">${sig['html']}</div>');
    if (o != null) b.write(_quoteHtml());
    return b.toString();
  }

  String _quoteHtml() {
    final date = DateTime.tryParse('${o!['date']}');
    final when = date == null ? '' : DateFormat("EEEE d MMMM y 'à' HH:mm", 'fr_FR').format(date);
    final from = '${o!['from_name'] ?? ''} &lt;${o!['from_email'] ?? ''}&gt;';
    final body = o!['html'] ?? const HtmlEscape().convert('${o!['text'] ?? ''}').replaceAll('\n', '<br>');
    if (widget.mode == 'forward') {
      final to = (o!['to'] as List? ?? const []).map((t) => '${t['name'] ?? ''} &lt;${t['email']}&gt;').join(', ');
      return '<br><br><div style="border-top:1px solid #E1E5EE;padding-top:8px;color:#5B6B85">---------- Message transféré ----------<br><b>De :</b> $from<br><b>Date :</b> $when<br>'
          '<b>Objet :</b> ${const HtmlEscape().convert('${o!['subject'] ?? ''}')}<br><b>À :</b> $to</div><br>$body';
    }
    return '<br><br><div style="color:#5B6B85">Le $when, $from a écrit :</div><blockquote style="margin:6px 0 0 4px;padding-left:10px;border-left:3px solid #C8D1E0">$body</blockquote>';
  }

  Future<void> _addFiles() async {
    final files = await FilePicker.pickFiles();
    if (files.isEmpty) return;
    var total = 0;
    for (final a in _atts) {
      total += (a['size'] as num?)?.toInt() ?? 0;
    }
    for (final f in files) {
      final bytes = await f.readAsBytes();
      total += bytes.length;
      if (total > 25 * 1024 * 1024) {
        if (mounted) toast(context, tr('Les pièces jointes dépassent 25 Mo : partagez plutôt un lien depuis les Documents.'), error: true);
        break;
      }
      _atts.add({'type': 'upload', 'name': f.name, 'mime': _mime(f.name), 'data': base64Encode(bytes), 'size': bytes.length});
    }
    if (mounted) setState(() {});
  }

  static String _mime(String name) {
    final ext = name.split('.').last.toLowerCase();
    return const {
          'pdf': 'application/pdf', 'png': 'image/png', 'jpg': 'image/jpeg', 'jpeg': 'image/jpeg', 'gif': 'image/gif', 'txt': 'text/plain', 'csv': 'text/csv',
          'doc': 'application/msword', 'docx': 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls': 'application/vnd.ms-excel',
          'xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pptx': 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'zip': 'application/zip',
        }[ext] ??
        'application/octet-stream';
  }

  Future<void> _addDocuments() async {
    final files = LocalDb.all('dc_files').where((f) => f['deleted_at'] == null).toList()..sort((a, b) => '${b['updated_at']}'.compareTo('${a['updated_at']}'));
    final picked = await showDialog<List<Map<String, dynamic>>>(context: context, builder: (_) => _DocPicker(files: files));
    if (picked == null) return;
    setState(() {
      for (final f in picked) {
        _atts.add({'type': 'document', 'id': f['id'], 'name': '${f['name']}', 'size': f['size']});
      }
    });
  }

  void _insertTemplate(Map<String, dynamic> t) {
    final text = '${t['html']}'
        .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
        .replaceAll(RegExp(r'</(p|div|li|h\d)>', caseSensitive: false), '\n')
        .replaceAll(RegExp(r'<[^>]+>'), '')
        .replaceAll('&nbsp;', ' ')
        .replaceAll('&amp;', '&')
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .replaceAll('&#039;', "'")
        .replaceAll('&quot;', '"')
        .trim();
    final idx = _quill.selection.baseOffset < 0 ? 0 : _quill.selection.baseOffset;
    _quill.document.insert(idx, '$text\n');
    if (_subject.text.trim().isEmpty && (t['subject'] ?? '').toString().isNotEmpty) _subject.text = '${t['subject']}';
    setState(() {});
  }

  Future<void> _schedule() async {
    final now = DateTime.now();
    final d = await pickDate(context, firstDate: now, lastDate: now.add(const Duration(days: 365)), initialDate: now);
    if (d == null || !mounted) return;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(now.add(const Duration(hours: 1))));
    if (t == null) return;
    final when = DateTime(d.year, d.month, d.day, t.hour, t.minute);
    if (when.isBefore(now)) {
      if (mounted) toast(context, tr('Choisissez une heure à venir.'), error: true);
      return;
    }
    setState(() => _sendAt = when);
  }

  Future<void> _send() async {
    if (_accountId == null) return toast(context, tr('Ajoutez d\'abord un compte de messagerie.'), error: true);
    if (_to.isEmpty && _cc.isEmpty && _bcc.isEmpty) return toast(context, tr('Ajoutez au moins un destinataire.'), error: true);
    if (_subject.text.trim().isEmpty && !await confirm(context, tr('Envoyer sans objet ?'), tr('Ce message n\'a pas d\'objet.'), ok: tr('Envoyer'))) return;
    setState(() => _busy = true);
    try {
      final r = await s.send({
        'id': _id, 'account_id': _accountId, 'to': _to, 'cc': _cc, 'bcc': _bcc, 'subject': _subject.text.trim(), 'html': _bodyHtml(), 'mode': widget.mode,
        if (o != null) 'message_id': o!['id'],
        'attachments': [for (final a in _atts) Map<String, dynamic>.from(a)..remove('size')],
        if (_sendAt != null) 'send_at': _sendAt!.toIso8601String(),
        'read_receipt': _receipt, 'track_clicks': _track, 'importance': _high ? 'high' : 'normal',
      });
      if (!mounted) return;
      Navigator.pop(context);
      toast(context, switch (r['status']) {
        'offline' => 'Pas de connexion : le message partira automatiquement au retour du réseau.',
        'queued' when _sendAt != null => 'Envoi programmé le ${DateFormat('d MMM à HH:mm', 'fr_FR').format(_sendAt!)}.',
        'failed' => 'Échec de l\'envoi : ${r['error'] ?? ''}',
        'queued' when r['error'] != null => 'Le serveur n\'a pas pris le message (${r['error']}) : nouvel essai automatique dans quelques minutes.',
        _ => 'Message envoyé.',
      }, error: r['status'] == 'failed');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _close() async {
    if (_quill.document.toPlainText().trim().isEmpty && _html.text.trim().isEmpty ||
        await confirm(context, tr('Abandonner ce message ?'), tr('Le texte saisi sera perdu.'), ok: tr('Abandonner'), danger: true)) {
      if (mounted) Navigator.pop(context);
    }
  }

  Widget _cmd(IconData icon, String label, VoidCallback? onTap, {bool primary = false}) {
    final accent = Theme.of(context).colorScheme.primary;
    return Padding(
      padding: const EdgeInsets.only(right: 4),
      child: primary
          ? FilledButton.icon(
              onPressed: onTap,
              style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10))),
              icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Icon(icon, size: 18),
              label: Text(tr(label), style: const TextStyle(fontWeight: FontWeight.w700)),
            )
          : TextButton.icon(
              onPressed: onTap,
              style: TextButton.styleFrom(foregroundColor: Theme.of(context).colorScheme.onSurface.withValues(alpha: .82), padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12)),
              icon: Icon(icon, size: 19, color: accent),
              label: Text(tr(label)),
            ),
    );
  }

  Widget _field(String label, Widget child, {Widget? trailing, bool focus = false}) {
    final line = Theme.of(context).brightness == Brightness.dark ? Colors.white12 : const Color(0xFFE6EAF1);
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 4),
      decoration: BoxDecoration(border: Border(bottom: BorderSide(color: focus ? Theme.of(context).colorScheme.primary : line, width: focus ? 1.4 : 1))),
      child: Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
        SizedBox(width: 72, child: Text(tr('$label :'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 15))),
        Expanded(child: child),
        ?trailing,
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final accent = theme.colorScheme.primary;
    final dark = theme.brightness == Brightness.dark;
    final hint = theme.hintColor;
    final contacts = s.contacts();
    final sigs = s.signatures.where((x) => x['account_id'] == null || x['account_id'] == _accountId).toList();
    final account = s.account(_accountId);
    final title = switch (widget.mode) { 'reply' => 'Répondre', 'reply_all' => 'Répondre à tous', 'forward' => 'Transférer', _ => _subject.text.trim().isEmpty ? 'Sans titre' : _subject.text.trim() };
    final narrow = MediaQuery.sizeOf(context).width < 700;
    final sig = sigs.where((x) => x['id'] == _signatureId).firstOrNull ?? s.signatures.where((x) => x['id'] == _signatureId).firstOrNull;

    return Container(
      color: Color.alphaBlend(accent.withValues(alpha: dark ? .10 : .06), dark ? const Color(0xFF0E1626) : const Color(0xFFF1F3F7)),
      child: Column(children: [
        // Barre de titre aux couleurs du thème de la boîte mail (comme Outlook)
        Container(
          height: 50,
          padding: const EdgeInsets.symmetric(horizontal: 12),
          decoration: BoxDecoration(gradient: LinearGradient(colors: [accent, Color.lerp(accent, Colors.black, .12)!])),
          child: Row(children: [
            Icon(widget.mode == 'forward' ? Icons.forward_rounded : widget.mode.startsWith('reply') ? Icons.reply_rounded : Icons.edit_note_rounded, color: Colors.white),
            const SizedBox(width: 10),
            Expanded(
              child: Text(tr('$title${account != null ? ' • ${account['email']}' : ''}'), maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: narrow ? TextAlign.start : TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 15.5)),
            ),
            IconButton(tooltip: tr('Fermer'), onPressed: _close, icon: const Icon(Icons.close_rounded, color: Colors.white)),
          ]),
        ),
        // Barre de commandes
        SizedBox(
          height: 58,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.fromLTRB(12, 8, 12, 6), children: [
            _cmd(Icons.send_rounded, _sendAt != null ? 'Programmer l\'envoi' : 'Envoyer', _busy ? null : _send, primary: true),
            const SizedBox(width: 6),
            _cmd(Icons.delete_outline_rounded, 'Ignorer', _close),
            _cmd(Icons.attach_file_rounded, 'Joindre un fichier', _addFiles),
            if (LocalDb.hasEntity('dc_files')) _cmd(Icons.folder_open_outlined, 'Documents', _addDocuments),
            _cmd(Icons.image_outlined, 'Image', _insertImage),
            PopupMenuButton<int?>(
              tooltip: tr('Signature'),
              onSelected: (v) async {
                if (v == -1) {
                  final id = await editSignature(context, s, accountId: _accountId);
                  if (mounted) setState(() => _signatureId = id ?? _signatureId);
                  return;
                }
                if (v == -2) {
                  await manageSignatures(context, s);
                  if (mounted) setState(() {});
                  return;
                }
                setState(() => _signatureId = v);
              },
              itemBuilder: (_) => [
                CheckedPopupMenuItem(value: null, checked: _signatureId == null, child: Text(tr('Sans signature'))),
                for (final g in sigs) CheckedPopupMenuItem(value: g['id'] as int, checked: _signatureId == g['id'], child: Text(tr('${g['name']}${g['user_id'] == null ? ' (société)' : ''}'))),
                const PopupMenuDivider(),
                PopupMenuItem(value: -1, child: ListTile(dense: true, contentPadding: EdgeInsets.zero, leading: const Icon(Icons.add_rounded), title: Text(tr('Nouvelle signature…')))),
                PopupMenuItem(value: -2, child: ListTile(dense: true, contentPadding: EdgeInsets.zero, leading: const Icon(Icons.tune_rounded), title: Text(tr('Signatures par boîte…')))),
              ],
              child: IgnorePointer(child: _cmd(Icons.draw_outlined, 'Signature', () {})),
            ),
            if (s.templates.isNotEmpty)
              PopupMenuButton<Map<String, dynamic>>(
                tooltip: tr('Insérer un modèle'),
                onSelected: _insertTemplate,
                itemBuilder: (_) => [for (final t in s.templates) PopupMenuItem(value: t, child: Text(tr('${t['name']}')))],
                child: IgnorePointer(child: _cmd(Icons.text_snippet_outlined, 'Modèles', () {})),
              ),
            _cmd(Icons.schedule_send_outlined, 'Programmer', _busy ? null : _schedule),
            PopupMenuButton<String>(
              tooltip: tr('Plus d\'options'),
              onSelected: (v) => setState(() => v == 'receipt' ? _receipt = !_receipt : v == 'track' ? _track = !_track : _high = !_high),
              itemBuilder: (_) => [
                CheckedPopupMenuItem(value: 'receipt', checked: _receipt, child: Text(tr('Demander un accusé de lecture'))),
                CheckedPopupMenuItem(value: 'track', checked: _track, child: Text(tr('Suivre les clics sur les liens'))),
                CheckedPopupMenuItem(value: 'high', checked: _high, child: Text(tr('Importance haute'))),
              ],
              icon: Icon(Icons.more_horiz_rounded, color: hint),
            ),
          ]),
        ),
        // Feuille de rédaction
        Expanded(
          child: Container(
            margin: EdgeInsets.fromLTRB(narrow ? 0 : 12, 0, narrow ? 0 : 12, narrow ? 0 : 12),
            decoration: BoxDecoration(
              color: dark ? const Color(0xFF111A2E) : Colors.white,
              borderRadius: BorderRadius.circular(narrow ? 0 : 16),
              boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .05), blurRadius: 12, offset: const Offset(0, 2))],
            ),
            clipBehavior: Clip.antiAlias,
            child: ListView(padding: EdgeInsets.fromLTRB(narrow ? 14 : 28, 10, narrow ? 14 : 28, 18), children: [
              _field(
                'De',
                DropdownButtonHideUnderline(
                  child: DropdownButton<int>(
                    value: _accountId,
                    isExpanded: true,
                    hint: Text(tr('Aucun compte')),
                    items: [
                      for (final a in s.accounts)
                        DropdownMenuItem(
                          value: a['id'] as int,
                          child: Row(children: [
                            AccountAvatar(account: a, radius: 11),
                            const SizedBox(width: 8),
                            Flexible(child: Text(tr('${a['email']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 15))),
                          ]),
                        ),
                    ],
                    onChanged: (v) => setState(() {
                      _accountId = v;
                      _pickSignature();
                    }),
                  ),
                ),
              ),
              _field(
                'À',
                _Recipients(list: _to, contacts: contacts, onChanged: () => setState(() {})),
                focus: _to.isEmpty,
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  if (!_showCc) TextButton(onPressed: () => setState(() => _showCc = true), child: Text(tr('Cc'))),
                  if (!_showCc) TextButton(onPressed: () => setState(() => _showCc = true), child: Text(tr('Cci'))),
                ]),
              ),
              if (_showCc) ...[
                _field('Cc', _Recipients(list: _cc, contacts: contacts, onChanged: () => setState(() {}))),
                _field('Cci', _Recipients(list: _bcc, contacts: contacts, onChanged: () => setState(() {}))),
              ],
              _field(
                'Objet',
                TextField(
                  controller: _subject,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(border: InputBorder.none, enabledBorder: InputBorder.none, focusedBorder: InputBorder.none, filled: false, hintText: tr('Ajouter un objet'), isDense: true),
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
                ),
                trailing: PopupMenuButton<bool>(
                  tooltip: tr('Importance'),
                  onSelected: (v) => setState(() => _high = v),
                  itemBuilder: (_) => [
                    CheckedPopupMenuItem(value: false, checked: !_high, child: Text(tr('Normale'))),
                    CheckedPopupMenuItem(value: true, checked: _high, child: Text(tr('Haute'))),
                  ],
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      if (_high) const Icon(Icons.priority_high_rounded, size: 16, color: YColors.danger),
                      Text(tr('Importance'), style: TextStyle(color: _high ? YColors.danger : hint)),
                      Icon(Icons.expand_more_rounded, size: 18, color: hint),
                    ]),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              // Barre de mise en forme
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
                decoration: BoxDecoration(color: dark ? Colors.white.withValues(alpha: .04) : const Color(0xFFF6F8FB), borderRadius: BorderRadius.circular(12)),
                child: Row(children: [
                  Expanded(
                    child: _mode == 'rich'
                        ? QuillSimpleToolbar(
                            controller: _quill,
                            config: QuillSimpleToolbarConfig(
                              multiRowsDisplay: false, showFontFamily: false, showSubscript: false, showSuperscript: false, showInlineCode: false, showCodeBlock: false, showSearchButton: false,
                              showListCheck: false, showDirection: false, showIndent: true, showAlignmentButtons: true, showStrikeThrough: true, showSmallButton: false, showQuote: false,
                            ),
                          )
                        : Wrap(spacing: 4, children: [
                            TextButton.icon(onPressed: _insertSkeleton, icon: const Icon(Icons.dashboard_customize_outlined, size: 18), label: Text(tr('Modèle e-mail'))),
                            TextButton.icon(onPressed: _insertImage, icon: const Icon(Icons.image_outlined, size: 18), label: Text(tr('Image'))),
                            if (MediaQuery.sizeOf(context).width < 1000)
                              TextButton.icon(onPressed: () => setState(() => _htmlPreview = !_htmlPreview), icon: Icon(_htmlPreview ? Icons.code : Icons.visibility_outlined, size: 18), label: Text(tr(_htmlPreview ? 'Code' : 'Aperçu'))),
                          ]),
                  ),
                  const SizedBox(width: 6),
                  SegmentedButton<String>(
                    showSelectedIcon: false,
                    style: const ButtonStyle(visualDensity: VisualDensity.compact),
                    segments: [
                      ButtonSegment(value: 'rich', icon: const Icon(Icons.text_format_rounded, size: 18), tooltip: tr('Mise en forme')),
                      ButtonSegment(value: 'html', icon: const Icon(Icons.code_rounded, size: 18), tooltip: tr('Code HTML')),
                    ],
                    selected: {_mode},
                    onSelectionChanged: (v) => _switchMode(v.first),
                  ),
                ]),
              ),
              const SizedBox(height: 14),
              if (_mode == 'rich' && _mentionQ != null) _mentionPanel(),
              if (_mode == 'rich')
                ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: 200),
                  child: QuillEditor.basic(controller: _quill, config: const QuillEditorConfig(placeholder: 'Écrivez votre message…', autoFocus: true, scrollable: false, padding: EdgeInsets.symmetric(vertical: 6))),
                )
              else
                SizedBox(
                  height: 380,
                  child: LayoutBuilder(builder: (context, c) {
                    final code = TextField(
                      controller: _html,
                      expands: true,
                      maxLines: null,
                      textAlignVertical: TextAlignVertical.top,
                      style: const TextStyle(fontFamily: 'monospace', fontSize: 12.5, height: 1.4),
                      decoration: InputDecoration(hintText: tr('<p>Bonjour,</p>\n<p>…</p>'), alignLabelWithHint: true),
                      onChanged: (_) => setState(() {}),
                    );
                    final preview = Container(
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(color: Colors.white, border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(10)),
                      child: MailHtml(key: ValueKey(_html.text.hashCode), html: _html.text.isEmpty ? '<p style="color:#8792A6">Aperçu du message</p>' : _html.text),
                    );
                    if (c.maxWidth >= 760) return Row(children: [Expanded(child: code), const SizedBox(width: 10), Expanded(child: preview)]);
                    return _htmlPreview ? preview : code;
                  }),
                ),
              // Signature affichée dans le message, comme dans Outlook
              if (sig != null)
                Padding(
                  padding: const EdgeInsets.only(top: 18),
                  child: Stack(children: [
                    ConstrainedBox(constraints: const BoxConstraints(maxHeight: 520), child: MailHtml(key: ValueKey('sig-${sig['id']}'), html: '${sig['html']}')),
                    Positioned(
                      top: 0,
                      right: 0,
                      child: IconButton.filledTonal(tooltip: tr('Retirer la signature'), visualDensity: VisualDensity.compact, onPressed: () => setState(() => _signatureId = null), icon: const Icon(Icons.close_rounded, size: 16)),
                    ),
                  ]),
                ),
              if (o != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => setState(() => _showQuote = !_showQuote),
                    icon: Icon(_showQuote ? Icons.expand_less : Icons.more_horiz),
                    label: Text(tr(_showQuote ? 'Masquer le message d\'origine' : 'Afficher le message d\'origine')),
                  ),
                ),
              if (o != null && _showQuote) SizedBox(height: 260, child: MailHtml(html: _quoteHtml())),
              if (_atts.isNotEmpty) ...[
                const SizedBox(height: 14),
                Wrap(spacing: 10, runSpacing: 10, children: [
                  for (final a in _atts)
                    Container(
                      width: 240,
                      padding: const EdgeInsets.fromLTRB(10, 8, 4, 8),
                      decoration: BoxDecoration(border: Border.all(color: dark ? Colors.white12 : const Color(0xFFE3E8F0)), borderRadius: BorderRadius.circular(10)),
                      child: Row(children: [
                        DialogIconBadge(icon: Icon(a['type'] == 'document' ? Icons.folder_outlined : Icons.insert_drive_file_outlined), size: 34),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(tr('${a['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                            if (a['size'] != null) Text(tr(fileSize(a['size'])), style: TextStyle(color: hint, fontSize: 12)),
                          ]),
                        ),
                        IconButton(visualDensity: VisualDensity.compact, onPressed: () => setState(() => _atts.remove(a)), icon: const Icon(Icons.close_rounded, size: 18)),
                      ]),
                    ),
                ]),
              ],
              if (_sendAt != null || _receipt || _track)
                Padding(
                  padding: const EdgeInsets.only(top: 14),
                  child: Wrap(spacing: 8, children: [
                    if (_sendAt != null) InputChip(avatar: const Icon(Icons.schedule, size: 16), label: Text(tr('Envoi le ${DateFormat('d MMM à HH:mm', 'fr_FR').format(_sendAt!)}')), onDeleted: () => setState(() => _sendAt = null)),
                    if (_receipt) InputChip(avatar: const Icon(Icons.mark_email_read_outlined, size: 16), label: Text(tr('Accusé de lecture')), onDeleted: () => setState(() => _receipt = false)),
                    if (_track) InputChip(avatar: const Icon(Icons.ads_click, size: 16), label: Text(tr('Clics suivis')), onDeleted: () => setState(() => _track = false)),
                  ]),
                ),
            ]),
          ),
        ),
      ]),
    );
  }
}

/// Envoie une image (logo, photo, visuel) et renvoie son adresse publique, utilisable dans un e-mail.
Future<String?> uploadMailImage(BuildContext context, MailStore store) async {
  final files = await FilePicker.pickFiles(type: FileType.image);
  if (files.isEmpty) return null;
  final f = files.first;
  try {
    final bytes = await f.readAsBytes();
    if (bytes.length > 3 * 1024 * 1024) {
      if (context.mounted) toast(context, tr('Image trop lourde (3 Mo maximum) : réduisez-la avant de l\'insérer.'), error: true);
      return null;
    }
    final r = await store.api.upload('mailbox/media', 'file', bytes, f.name);
    return '${r['url']}';
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return null;
  }
}

void insertAtCursor(TextEditingController c, String text) {
  final sel = c.selection;
  final start = sel.isValid ? sel.start : c.text.length;
  final end = sel.isValid ? sel.end : c.text.length;
  c.value = TextEditingValue(text: c.text.replaceRange(start, end, text), selection: TextSelection.collapsed(offset: start + text.length));
}

/// Modèle d'e-mail HTML responsive (en-tête, contenu, bouton, pied) aux couleurs choisies.
String emailSkeleton(String accent) => '''
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F6FB;padding:24px 0;font-family:Segoe UI,Arial,sans-serif">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden">
      <tr><td style="background:$accent;padding:22px 28px;color:#ffffff;font-size:20px;font-weight:700">Titre de votre message</td></tr>
      <tr><td style="padding:26px 28px;color:#1B1F24;font-size:15px;line-height:1.6">
        <p>Bonjour,</p>
        <p>Écrivez ici votre texte. Vous pouvez ajouter des images avec le bouton « Image ».</p>
        <p style="text-align:center;margin:26px 0"><a href="https://" style="background:$accent;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:24px;font-weight:700;display:inline-block">Bouton d'action</a></p>
        <p>Cordialement,</p>
      </td></tr>
      <tr><td style="background:#F8FAFD;padding:16px 28px;color:#8792A6;font-size:12px;text-align:center">Votre société · Adresse · Téléphone</td></tr>
    </table>
  </td></tr>
</table>
''';

String fileSize(dynamic v) {
  final n = (v as num?)?.toDouble() ?? 0;
  if (n < 1024) return '${n.toInt()} o';
  if (n < 1024 * 1024) return '${(n / 1024).toStringAsFixed(0)} Ko';
  return '${(n / 1024 / 1024).toStringAsFixed(1)} Mo';
}

class _Recipients extends StatefulWidget {
  const _Recipients({required this.list, required this.contacts, required this.onChanged});

  final List<Map<String, String>> list;
  final List<Map<String, String>> contacts;
  final VoidCallback onChanged;

  @override
  State<_Recipients> createState() => _RecipientsState();
}

class _RecipientsState extends State<_Recipients> {
  TextEditingController? _ctrl;

  void _add(String raw) {
    for (final part in raw.split(RegExp(r'[,;\s]+'))) {
      final e = part.trim().toLowerCase();
      if (RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(e) && !widget.list.any((x) => x['email'] == e)) {
        final known = widget.contacts.where((c) => c['email'] == e).firstOrNull;
        widget.list.add({'name': known?['name'] ?? '', 'email': e});
      }
    }
    _ctrl?.clear();
    widget.onChanged();
  }

  @override
  Widget build(BuildContext context) {
    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Expanded(
        child: Wrap(spacing: 6, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
          for (final r in widget.list)
            Tooltip(
              message: r['email']!,
              child: InputChip(
                visualDensity: VisualDensity.compact,
                avatar: CircleAvatar(backgroundColor: YColors.blue.withValues(alpha: .15), child: Text(tr((r['name']!.isNotEmpty ? r['name']! : r['email']!)[0].toUpperCase()), style: const TextStyle(fontSize: 11))),
                label: Text(tr(r['name']!.isNotEmpty ? r['name']! : r['email']!)),
                onDeleted: () {
                  widget.list.remove(r);
                  widget.onChanged();
                },
              ),
            ),
          SizedBox(
            width: 280,
            child: Autocomplete<Map<String, String>>(
              displayStringForOption: (c) => c['email']!,
              optionsBuilder: (v) {
                final q = v.text.trim().toLowerCase();
                if (q.length < 2) return const [];
                return widget.contacts.where((c) => c['email']!.contains(q) || c['name']!.toLowerCase().contains(q)).take(8);
              },
              onSelected: (c) {
                if (!widget.list.any((x) => x['email'] == c['email'])) widget.list.add({'name': c['name']!, 'email': c['email']!});
                _ctrl?.clear();
                widget.onChanged();
              },
              optionsViewBuilder: (context, onSel, opts) => Align(
                alignment: Alignment.topLeft,
                child: Material(
                  elevation: 6,
                  borderRadius: BorderRadius.circular(10),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 380, maxHeight: 300),
                    child: ListView(shrinkWrap: true, padding: EdgeInsets.zero, children: [
                      for (final c in opts)
                        ListTile(
                          dense: true,
                          leading: Icon(c['label'] != null ? Icons.badge_outlined : Icons.person_outline, size: 18, color: c['label'] != null ? YColors.blue : null),
                          title: Text(tr(c['name']!.isNotEmpty ? c['name']! : c['email']!)),
                          subtitle: Text(tr(c['label'] != null ? '${c['email']} · ${c['label']}' : c['email']!)),
                          onTap: () => onSel(c),
                        ),
                    ]),
                  ),
                ),
              ),
              fieldViewBuilder: (context, ctrl, focus, onSubmit) {
                _ctrl = ctrl;
                return Focus(
                  onFocusChange: (f) {
                    if (!f && ctrl.text.trim().isNotEmpty) _add(ctrl.text);
                  },
                  child: TextField(
                    controller: ctrl,
                    focusNode: focus,
                    decoration: InputDecoration(border: InputBorder.none, enabledBorder: InputBorder.none, focusedBorder: InputBorder.none, filled: false, hintText: tr(widget.list.isEmpty ? 'Nom ou adresse e-mail' : ''), isDense: true),
                    onChanged: (v) {
                      if (v.endsWith(',') || v.endsWith(';') || v.endsWith(' ')) _add(v);
                    },
                    onSubmitted: _add,
                  ),
                );
              },
            ),
          ),
        ]),
      ),
    ]);
  }
}

class _DocPicker extends StatefulWidget {
  const _DocPicker({required this.files});

  final List<Map<String, dynamic>> files;

  @override
  State<_DocPicker> createState() => _DocPickerState();
}

class _DocPickerState extends State<_DocPicker> {
  final Set<String> _sel = {};
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final list = widget.files.where((f) => _q.isEmpty || '${f['name']} ${f['tags'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())).take(200).toList();
    return YAlertDialog(
      title: Text(tr('Joindre depuis les Documents')),
      content: SizedBox(
        width: 520,
        height: 440,
        child: Column(children: [
          TextField(decoration: InputDecoration(prefixIcon: Icon(Icons.search), hintText: tr('Rechercher un document')), onChanged: (v) => setState(() => _q = v)),
          const SizedBox(height: 8),
          Expanded(
            child: list.isEmpty
                ? EmptyState(icon: Icons.folder_off_outlined, title: tr('Aucun document'))
                : ListView(children: [
                    for (final f in list)
                      CheckboxListTile(
                        dense: true,
                        value: _sel.contains(f['id']),
                        onChanged: (v) => setState(() => v == true ? _sel.add('${f['id']}') : _sel.remove(f['id'])),
                        title: Text(tr('${f['name']}')),
                        subtitle: Text(tr(fileSize(f['size']))),
                      ),
                  ]),
          ),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(onPressed: () => Navigator.pop(context, widget.files.where((f) => _sel.contains(f['id'])).toList()), child: Text(tr('Joindre (${_sel.length})'))),
      ],
    );
  }
}
