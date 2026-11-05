import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../widgets/common.dart';
import 'marketing/automations.dart';
import 'marketing/campaigns.dart';
import 'marketing/contacts.dart';
import 'marketing/forms.dart';
import 'marketing/mk_common.dart';
import 'marketing/templates.dart';
import 'messaging_settings_screen.dart';

export 'marketing/campaigns.dart' show CampaignScreen, ContentEditor, ContentEditorState;
export 'marketing/email_builder.dart' show EmailPreview, EmailBuilder, EmailDesign;
import '../../i18n/i18n.dart';

/// Marketing : campagnes, automatisations, contacts et segments, formulaires et pages, modèles.
class MarketingScreen extends StatefulWidget {
  const MarketingScreen({super.key});

  @override
  State<MarketingScreen> createState() => _MarketingScreenState();
}

class _MarketingScreenState extends State<MarketingScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 5, vsync: this);
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = mkMap(await context.read<Session>().api.get('marketing/overview'));
      if (mounted) setState(() => (_d = d, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final canManage = context.watch<Session>().can('marketing.manage');
    return Column(children: [
      PageHeader(
        title: tr('Marketing'),
        subtitle: tr('Campagnes e-mail, SMS et WhatsApp, automatisations, formulaires et pages, contacts et segments'),
        actions: [
          IconButton(tooltip: tr('Canaux d\'envoi'), onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const MessagingSettingsScreen())).then((_) => _load()), icon: const Icon(Icons.settings_outlined)),
          if (canManage && _d != null) FilledButton.icon(onPressed: () => newCampaign(context, _d!, _load), icon: const Icon(Icons.add), label: Text(tr('Nouvelle campagne'))),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Campagnes')),
        Tab(text: tr('Automatisations')),
        Tab(text: tr('Contacts & segments')),
        Tab(text: tr('Formulaires & pages')),
        Tab(text: tr('Modèles')),
      ]),
      Expanded(
        child: _d == null
            ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
            : TabBarView(controller: _tabs, children: [
                CampaignsTab(data: _d!, reload: _load, canManage: canManage),
                AutomationsTab(overview: _d!, canManage: canManage),
                ContactsTab(data: _d!, reload: _load, canManage: canManage),
                FormsTab(overview: _d!, canManage: canManage, reload: _load),
                TemplatesTab(data: _d!, reload: _load, canManage: canManage),
              ]),
      ),
    ]);
  }
}
