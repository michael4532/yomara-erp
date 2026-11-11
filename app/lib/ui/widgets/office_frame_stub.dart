import 'package:flutter/material.dart';

/// Applications installées 1.0.8 : pas de vue web intégrée, l'éditeur s'ouvre dans le navigateur
/// (la version 1.0.9 l'affiche dans l'application).
bool get officeEmbedded => false;

class OfficeFrame extends StatelessWidget {
  const OfficeFrame({super.key, required this.url, this.onClose, this.onSaved});

  final String url;
  final VoidCallback? onClose;
  final VoidCallback? onSaved;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
