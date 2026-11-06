import 'package:flutter/material.dart';

/// Identité Yomara : bleu nuit, bleu vif, jaune.
class YColors {
  static const night = Color(0xFF0B1B3A);
  static const nightSoft = Color(0xFF13274F);
  static const blue = Color(0xFF1F6FEB);
  static const yellow = Color(0xFFFFC531);
  static const success = Color(0xFF12A150);
  static const warning = Color(0xFFE8A317);
  static const danger = Color(0xFFD93025);
  static const bg = Color(0xFFF5F7FB);
  static const line = Color(0xFFE4E9F2);
  static const muted = Color(0xFF64748B);
  static const ink = Color(0xFF0F172A);
  static const inkSoft = Color(0xFF334155);
  static const subtle = Color(0xFFF8FAFC);
}

ThemeData buildTheme(Brightness brightness) {
  final dark = brightness == Brightness.dark;
  final scheme = ColorScheme.fromSeed(
    seedColor: YColors.blue,
    brightness: brightness,
    primary: dark ? const Color(0xFF6EA8FF) : YColors.blue,
    secondary: YColors.yellow,
    onSecondary: YColors.night,
    // Boutons « tonal » : bleu clair franc (la teinte lavande générée donnait l'air d'un bouton désactivé)
    secondaryContainer: dark ? const Color(0xFF1E3A66) : const Color(0xFFE2ECFF),
    onSecondaryContainer: dark ? Colors.white : const Color(0xFF1652C4),
    primaryContainer: dark ? const Color(0xFF1E3A66) : const Color(0xFFE2ECFF),
    onPrimaryContainer: dark ? Colors.white : const Color(0xFF1652C4),
    surface: dark ? const Color(0xFF111A2E) : Colors.white,
    // Surfaces neutres (pas de teinte violette Material 3 sur les dialogues, menus et champs)
    surfaceTint: Colors.transparent,
    surfaceContainerLowest: dark ? const Color(0xFF0D1628) : Colors.white,
    surfaceContainerLow: dark ? const Color(0xFF111A2E) : const Color(0xFFF8FAFD),
    surfaceContainer: dark ? const Color(0xFF14203A) : const Color(0xFFF3F6FB),
    surfaceContainerHigh: dark ? const Color(0xFF172440) : const Color(0xFFEEF2F8),
    surfaceContainerHighest: dark ? const Color(0xFF1B2A4A) : const Color(0xFFE8EDF5),
  );

  final base = ThemeData(useMaterial3: true, brightness: brightness).textTheme;
  final ink = dark ? Colors.white : YColors.ink;
  final body = dark ? Colors.white.withValues(alpha: .86) : YColors.inkSoft;
  final textTheme = base
      .apply(fontFamily: 'Inter', bodyColor: body, displayColor: ink)
      .copyWith(
        displaySmall: base.displaySmall?.copyWith(fontFamily: 'Inter', fontWeight: FontWeight.w800, letterSpacing: -1.0, color: ink),
        headlineMedium: base.headlineMedium?.copyWith(fontFamily: 'Inter', fontWeight: FontWeight.w800, letterSpacing: -.8, color: ink),
        headlineSmall: base.headlineSmall?.copyWith(fontFamily: 'Inter', fontSize: 24, fontWeight: FontWeight.w800, letterSpacing: -.6, color: ink),
        titleLarge: base.titleLarge?.copyWith(fontFamily: 'Inter', fontSize: 19, fontWeight: FontWeight.w700, letterSpacing: -.3, color: ink),
        titleMedium: base.titleMedium?.copyWith(fontFamily: 'Inter', fontSize: 15.5, fontWeight: FontWeight.w600, letterSpacing: -.1, color: ink),
        titleSmall: base.titleSmall?.copyWith(fontFamily: 'Inter', fontWeight: FontWeight.w600, color: ink),
        bodyLarge: base.bodyLarge?.copyWith(fontFamily: 'Inter', fontSize: 15, height: 1.45, color: body),
        bodyMedium: base.bodyMedium?.copyWith(fontFamily: 'Inter', fontSize: 14, height: 1.42, color: body),
        bodySmall: base.bodySmall?.copyWith(fontFamily: 'Inter', fontSize: 12.5, color: dark ? Colors.white60 : YColors.muted),
        labelLarge: base.labelLarge?.copyWith(fontFamily: 'Inter', fontWeight: FontWeight.w600, letterSpacing: 0),
      );

  return ThemeData(
    useMaterial3: true,
    fontFamily: 'Inter',
    fontFamilyFallback: const ['Roboto'],
    textTheme: textTheme,
    hintColor: dark ? Colors.white54 : YColors.muted,
    colorScheme: scheme,
    scaffoldBackgroundColor: dark ? const Color(0xFF0A1222) : YColors.bg,
    visualDensity: VisualDensity.standard,
    // Transitions modernes entre les écrans, sur toutes les plateformes
    pageTransitionsTheme: const PageTransitionsTheme(builders: {
      TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
      TargetPlatform.iOS: FadeForwardsPageTransitionsBuilder(),
      TargetPlatform.macOS: FadeForwardsPageTransitionsBuilder(),
      TargetPlatform.windows: FadeForwardsPageTransitionsBuilder(),
      TargetPlatform.linux: FadeForwardsPageTransitionsBuilder(),
      TargetPlatform.fuchsia: FadeForwardsPageTransitionsBuilder(),
    }),
    splashFactory: InkSparkle.splashFactory,
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      backgroundColor: dark ? const Color(0xFF1E2C47) : YColors.night,
      contentTextStyle: const TextStyle(color: Colors.white, fontWeight: FontWeight.w500),
      insetPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: dark ? const Color(0xFF111A2E) : Colors.white,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      shadowColor: Colors.black.withValues(alpha: .25),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24), side: BorderSide(color: dark ? Colors.white10 : const Color(0xFFE9EEF6))),
      barrierColor: (dark ? Colors.black : YColors.night).withValues(alpha: .42),
      titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, letterSpacing: -.2, color: dark ? Colors.white : YColors.night),
      contentTextStyle: TextStyle(fontSize: 14, height: 1.45, color: dark ? Colors.white70 : const Color(0xFF3B4A63)),
      actionsPadding: const EdgeInsets.fromLTRB(24, 8, 24, 20),
      insetPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
    ),
    checkboxTheme: CheckboxThemeData(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
      side: WidgetStateBorderSide.resolveWith((st) => BorderSide(width: 1.6, color: st.contains(WidgetState.selected) ? scheme.primary : (dark ? Colors.white38 : const Color(0xFFB8C3D6)))),
      fillColor: WidgetStateProperty.resolveWith((st) => st.contains(WidgetState.selected) ? scheme.primary : Colors.transparent),
      checkColor: const WidgetStatePropertyAll(Colors.white),
      splashRadius: 18,
    ),
    radioTheme: RadioThemeData(fillColor: WidgetStateProperty.resolveWith((st) => st.contains(WidgetState.selected) ? scheme.primary : (dark ? Colors.white38 : const Color(0xFFB8C3D6)))),
    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith((st) => st.contains(WidgetState.selected) ? Colors.white : (dark ? Colors.white70 : Colors.white)),
      trackColor: WidgetStateProperty.resolveWith((st) => st.contains(WidgetState.selected) ? scheme.primary : (dark ? Colors.white24 : const Color(0xFFD5DCE8))),
      trackOutlineColor: const WidgetStatePropertyAll(Colors.transparent),
      thumbIcon: WidgetStateProperty.resolveWith((st) => st.contains(WidgetState.selected) ? Icon(Icons.check, size: 14, color: scheme.primary) : null),
    ),
    listTileTheme: ListTileThemeData(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), contentPadding: const EdgeInsets.symmetric(horizontal: 12)),
    popupMenuTheme: PopupMenuThemeData(
      color: dark ? const Color(0xFF16223B) : Colors.white,
      surfaceTintColor: Colors.transparent,
      elevation: 8,
      shadowColor: Colors.black.withValues(alpha: .18),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: dark ? Colors.white10 : const Color(0xFFE6EBF4))),
    ),
    menuTheme: MenuThemeData(style: MenuStyle(
      backgroundColor: WidgetStatePropertyAll(dark ? const Color(0xFF16223B) : Colors.white),
      surfaceTintColor: const WidgetStatePropertyAll(Colors.transparent),
      shape: WidgetStatePropertyAll(RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
    )),
    bottomSheetTheme: BottomSheetThemeData(
      backgroundColor: dark ? const Color(0xFF111A2E) : Colors.white,
      surfaceTintColor: Colors.transparent,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
    ),
    datePickerTheme: DatePickerThemeData(
      backgroundColor: dark ? const Color(0xFF111A2E) : Colors.white,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      headerBackgroundColor: dark ? const Color(0xFF16223B) : YColors.night,
      headerForegroundColor: Colors.white,
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        textStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w600),
      ),
    ),
    chipTheme: ChipThemeData(
      shape: const StadiumBorder(),
      side: WidgetStateBorderSide.resolveWith((st) => BorderSide(color: st.contains(WidgetState.selected) ? scheme.primary.withValues(alpha: .55) : (dark ? Colors.white24 : YColors.line))),
      color: WidgetStateProperty.resolveWith((st) => st.contains(WidgetState.selected) ? scheme.primary.withValues(alpha: dark ? .24 : .10) : (dark ? const Color(0xFF162238) : Colors.white)),
      // Style simple + couleur selon l'état (méthode prévue par Flutter) : un WidgetStateTextStyle laissait les puces
      // non sélectionnées sans texte dans les logiciels de bureau (rendu Impeller)
      labelStyle: TextStyle(
        fontFamily: 'Inter',
        fontSize: 13,
        fontWeight: FontWeight.w600,
        color: WidgetStateColor.resolveWith((st) => st.contains(WidgetState.selected) ? scheme.primary : (dark ? Colors.white : YColors.inkSoft)),
      ),
      secondaryLabelStyle: TextStyle(fontFamily: 'Inter', fontSize: 13, fontWeight: FontWeight.w700, color: scheme.primary),
      iconTheme: IconThemeData(size: 16, color: dark ? Colors.white70 : YColors.muted),
      showCheckmark: false,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
    ),
    tooltipTheme: TooltipThemeData(
      decoration: BoxDecoration(color: dark ? const Color(0xFF2A3A5C) : YColors.night, borderRadius: BorderRadius.circular(8)),
      textStyle: const TextStyle(color: Colors.white, fontSize: 12),
      waitDuration: const Duration(milliseconds: 400),
    ),
    // Choix exclusifs (Entreprise / Particulier…) : bouton actif bleu nuit, coins arrondis
    segmentedButtonTheme: SegmentedButtonThemeData(style: SegmentedButton.styleFrom(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      side: BorderSide(color: dark ? Colors.white24 : YColors.line),
      selectedBackgroundColor: dark ? scheme.primary : const Color(0xFF17213A),
      selectedForegroundColor: Colors.white,
      backgroundColor: dark ? const Color(0xFF162238) : Colors.white,
      foregroundColor: dark ? Colors.white70 : YColors.inkSoft,
      textStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w600, fontSize: 13),
      padding: const EdgeInsets.symmetric(horizontal: 14),
      visualDensity: VisualDensity.compact,
    )),
    // Onglets : fond bleu clair et trait bleu sous l'onglet actif
    tabBarTheme: TabBarThemeData(
      indicatorSize: TabBarIndicatorSize.tab,
      splashBorderRadius: BorderRadius.circular(10),
      labelPadding: const EdgeInsets.symmetric(horizontal: 18),
      dividerColor: dark ? Colors.white12 : YColors.line,
      labelColor: scheme.primary,
      unselectedLabelColor: dark ? Colors.white60 : YColors.muted,
      labelStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w700, fontSize: 14),
      unselectedLabelStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w500, fontSize: 14),
      overlayColor: WidgetStatePropertyAll(scheme.primary.withValues(alpha: .05)),
      indicator: TabUnderlineFill(color: scheme.primary, fill: scheme.primary.withValues(alpha: dark ? .20 : .08)),
      indicatorAnimation: TabIndicatorAnimation.elastic,
    ),
    progressIndicatorTheme: ProgressIndicatorThemeData(color: scheme.primary, linearTrackColor: scheme.primary.withValues(alpha: .12)),
    scrollbarTheme: ScrollbarThemeData(radius: const Radius.circular(8), thickness: WidgetStateProperty.all(6)),
    appBarTheme: AppBarTheme(
      backgroundColor: dark ? const Color(0xFF0A1222) : YColors.bg,
      foregroundColor: dark ? Colors.white : YColors.night,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: TextStyle(fontFamily: 'Inter', fontSize: 19, fontWeight: FontWeight.w700, letterSpacing: -.3, color: dark ? Colors.white : YColors.ink),
    ),
    cardTheme: CardThemeData(
      elevation: dark ? 0 : 1,
      shadowColor: const Color(0x140B1B3A),
      color: scheme.surface,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: dark ? Colors.white12 : const Color(0xFFE8ECF4)),
      ),
    ),
    iconButtonTheme: IconButtonThemeData(style: IconButton.styleFrom(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)))),
    floatingActionButtonTheme: FloatingActionButtonThemeData(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)), elevation: 2, backgroundColor: scheme.primary, foregroundColor: Colors.white),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: dark ? const Color(0xFF162238) : Colors.white,
      isDense: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      hintStyle: TextStyle(fontFamily: 'Inter', color: dark ? Colors.white38 : const Color(0xFF94A3B8), fontWeight: FontWeight.w400),
      labelStyle: TextStyle(fontFamily: 'Inter', color: dark ? Colors.white60 : YColors.muted),
      floatingLabelStyle: TextStyle(fontFamily: 'Inter', color: scheme.primary, fontWeight: FontWeight.w600),
      prefixIconColor: dark ? Colors.white54 : const Color(0xFF94A3B8),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: dark ? Colors.white24 : YColors.line)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: dark ? Colors.white24 : YColors.line)),
      disabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: dark ? Colors.white12 : const Color(0xFFEDF1F7))),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: scheme.primary, width: 1.6)),
      errorBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: YColors.danger)),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        textStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w600, fontSize: 14, letterSpacing: -.1),
        animationDuration: const Duration(milliseconds: 180),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        side: BorderSide(color: dark ? Colors.white24 : const Color(0xFFD9E0EC)),
        foregroundColor: dark ? Colors.white : YColors.inkSoft,
        textStyle: const TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w600, fontSize: 14, letterSpacing: -.1),
      ),
    ),
    dividerTheme: DividerThemeData(color: dark ? Colors.white12 : YColors.line, space: 1),
    dataTableTheme: DataTableThemeData(
      headingRowColor: WidgetStatePropertyAll(dark ? const Color(0xFF14203A) : const Color(0xFFF3F5F9)),
      headingRowHeight: 50,
      headingTextStyle: TextStyle(fontFamily: 'Inter', fontWeight: FontWeight.w600, color: dark ? Colors.white70 : YColors.inkSoft, fontSize: 13.5),
      dataTextStyle: TextStyle(fontFamily: 'Inter', fontSize: 14.5, color: dark ? Colors.white.withValues(alpha: .9) : YColors.ink),
      dataRowMinHeight: 58,
      dataRowMaxHeight: 64,
      dividerThickness: .6,
      dataRowColor: WidgetStateProperty.resolveWith((st) => st.contains(WidgetState.hovered) ? scheme.primary.withValues(alpha: .04) : null),
    ),
  );
}

/// Indicateur d'onglet : fond teinté arrondi en haut et trait de couleur en bas.
class TabUnderlineFill extends Decoration {
  const TabUnderlineFill({required this.color, required this.fill, this.thickness = 2.5});

  final Color color;
  final Color fill;
  final double thickness;

  @override
  BoxPainter createBoxPainter([VoidCallback? onChanged]) => _TabUnderlineFillPainter(this);
}

class _TabUnderlineFillPainter extends BoxPainter {
  _TabUnderlineFillPainter(this.d);

  final TabUnderlineFill d;

  @override
  void paint(Canvas canvas, Offset offset, ImageConfiguration configuration) {
    final size = configuration.size;
    if (size == null) return;
    final r = (offset & size).deflate(2).translate(0, 2);
    canvas.drawRRect(RRect.fromRectAndCorners(r, topLeft: const Radius.circular(10), topRight: const Radius.circular(10)), Paint()..color = d.fill);
    canvas.drawRRect(
      RRect.fromRectAndRadius(Rect.fromLTWH(r.left, r.bottom - d.thickness, r.width, d.thickness), Radius.circular(d.thickness)),
      Paint()..color = d.color,
    );
  }
}

/// Apparence choisie sur cet appareil : clair, sombre ou selon le système.
class ThemePrefs {
  ThemePrefs._();

  static final mode = ValueNotifier<ThemeMode>(ThemeMode.light);

  static void load(dynamic stored) => mode.value = switch ('$stored') {
        'dark' => ThemeMode.dark,
        'system' => ThemeMode.system,
        _ => ThemeMode.light,
      };

  static String get name => switch (mode.value) {
        ThemeMode.dark => 'dark',
        ThemeMode.system => 'system',
        _ => 'light',
      };
}
