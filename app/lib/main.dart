import 'package:flutter_quill/flutter_quill.dart' show FlutterQuillLocalizations;
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:provider/provider.dart';

import 'core/local_db.dart';
import 'core/secure_store.dart';
import 'i18n/i18n.dart';
import 'state/session.dart';
import 'ui/screens/boot_screen.dart';
import 'ui/screens/intro_screen.dart';
import 'ui/screens/login_screen.dart';
import 'ui/shell.dart';
import 'ui/theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('fr');
  await SecureStore.init(); // coffre du système : jeton et clé de chiffrement des données locales
  await LocalDb.init();
  ThemePrefs.load(LocalDb.meta.get('theme_mode'));
  // Langue : choix mémorisé sur l'appareil, sinon langue du système (français par défaut)
  final device = WidgetsBinding.instance.platformDispatcher.locale.languageCode;
  await I18n.use((LocalDb.meta.get('lang') as String?) ?? (I18n.languages.containsKey(device) ? device : 'fr'));

  final session = Session();
  await session.restore();

  runApp(
    MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: session),
        ChangeNotifierProvider.value(value: session.sync),
      ],
      child: const YomaraErpApp(),
    ),
  );
}

class YomaraErpApp extends StatelessWidget {
  const YomaraErpApp({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    return ValueListenableBuilder<ThemeMode>(
      valueListenable: ThemePrefs.mode,
      builder: (context, themeMode, _) => ValueListenableBuilder<String>(
      valueListenable: I18n.current,
      builder: (context, lang, _) => MaterialApp(
      key: ValueKey(lang),
      title: (session.bootstrap?['app']?['name'] as String?) ?? 'Yomara ERP',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(Brightness.light),
      darkTheme: buildTheme(Brightness.dark),
      themeMode: themeMode,
      locale: Locale(lang),
      supportedLocales: const [Locale('fr'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        FlutterQuillLocalizations.delegate,
      ],
      // Application installée, premier lancement : présentation avant la connexion (le site web a déjà sa vitrine)
      home: session.isLoggedIn ? _ShellWithBoot(preparing: session.preparing) : const _Entry(),
      ),
      ),
    );
  }
}

/// Le tableau de bord se construit derrière l'écran de démarrage, puis apparaît en fondu, déjà prêt.
class _ShellWithBoot extends StatefulWidget {
  const _ShellWithBoot({required this.preparing});

  final bool preparing;

  @override
  State<_ShellWithBoot> createState() => _ShellWithBootState();
}

class _ShellWithBootState extends State<_ShellWithBoot> {
  late bool _boot = widget.preparing;

  @override
  void didUpdateWidget(covariant _ShellWithBoot old) {
    super.didUpdateWidget(old);
    if (widget.preparing && !_boot) setState(() => _boot = true);
  }

  @override
  Widget build(BuildContext context) => Stack(children: [
        const Positioned.fill(child: AppShell()),
        if (_boot)
          Positioned.fill(
            child: IgnorePointer(
              ignoring: !widget.preparing,
              child: AnimatedOpacity(
                opacity: widget.preparing ? 1 : 0,
                duration: const Duration(milliseconds: 450),
                curve: Curves.easeOut,
                onEnd: () {
                  if (!widget.preparing && mounted) setState(() => _boot = false);
                },
                child: const BootScreen(),
              ),
            ),
          ),
      ]);
}

/// Avant la connexion : présentation au premier lancement de l'application installée, puis l'écran de connexion.
class _Entry extends StatefulWidget {
  const _Entry();

  @override
  State<_Entry> createState() => _EntryState();
}

class _EntryState extends State<_Entry> {
  // Le site web a déjà sa vitrine : la présentation ne concerne que l'application installée
  bool _intro = !kIsWeb && !IntroScreen.seen;
  bool _register = false;

  @override
  Widget build(BuildContext context) => AnimatedSwitcher(
        duration: const Duration(milliseconds: 420),
        child: _intro
            ? IntroScreen(key: const ValueKey('intro'), onDone: (register) => setState(() => (_intro = false, _register = register)))
            : LoginScreen(key: const ValueKey('login'), startRegister: _register),
      );
}
