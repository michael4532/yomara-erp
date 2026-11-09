import 'package:flutter/material.dart';

/// Vrai quand le contenu d'une « feuille » est affiché dans une fenêtre centrée (écran large).
class SheetAsDialog extends InheritedWidget {
  const SheetAsDialog({super.key, required super.child});

  static bool of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<SheetAsDialog>() != null;

  @override
  bool updateShouldNotify(SheetAsDialog oldWidget) => false;
}

/// Remplace showModalBottomSheet : feuille en bas de l'écran sur téléphone, fenêtre centrée (popup) sur ordinateur et tablette.
Future<T?> showAdaptiveSheet<T>({
  required BuildContext context,
  required WidgetBuilder builder,
  bool isScrollControlled = false,
  bool showDragHandle = false,
  bool useRootNavigator = false,
  bool isDismissible = true,
  BoxConstraints? constraints,
  Color? backgroundColor,
}) {
  final size = MediaQuery.sizeOf(context);
  if (size.width < 700) {
    return showModalBottomSheet<T>(
      context: context,
      builder: builder,
      isScrollControlled: isScrollControlled,
      showDragHandle: showDragHandle,
      useRootNavigator: useRootNavigator,
      isDismissible: isDismissible,
      constraints: constraints,
      backgroundColor: backgroundColor,
    );
  }
  final maxW = (constraints?.maxWidth.isFinite ?? false) ? constraints!.maxWidth.clamp(360.0, 760.0) : 620.0;
  return showDialog<T>(
    context: context,
    useRootNavigator: useRootNavigator,
    barrierDismissible: isDismissible,
    builder: (c) => Dialog(
      backgroundColor: backgroundColor,
      insetPadding: const EdgeInsets.symmetric(horizontal: 32, vertical: 32),
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxW, maxHeight: size.height * .86),
        child: SheetAsDialog(
          child: MediaQuery.removeViewInsets(
            context: c,
            removeBottom: false,
            child: Padding(padding: const EdgeInsets.only(top: 14), child: Builder(builder: builder)),
          ),
        ),
      ),
    ),
  );
}
