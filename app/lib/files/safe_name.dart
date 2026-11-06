/// Nom de fichier sûr pour l'enregistrement sur l'appareil : le nom peut venir du serveur ou d'un tiers
/// (pièce jointe, document partagé). Aucun dossier (« ../ », « C:\ », « /etc/ ») ni caractère interdit sous Windows ;
/// le fichier reste toujours dans le dossier de téléchargement.
String safeFileName(String name, {String fallback = 'fichier'}) {
  // Dernier segment seulement (séparateurs Unix et Windows)
  var n = name.split(RegExp(r'[\\/]')).last;
  n = n.replaceAll(RegExp(r'[\x00-\x1f\x7f<>:"|?*]'), '_').trim();
  // Pas de « . », « .. » ni de fichier caché ; Windows ignore les points et espaces finaux
  n = n.replaceFirst(RegExp(r'^[.\s]+'), '').replaceFirst(RegExp(r'[.\s]+$'), '');
  // Noms réservés de Windows (CON, PRN, AUX, NUL, COM1…, LPT1…), avec ou sans extension
  if (RegExp(r'^(con|prn|aux|nul|com[0-9]|lpt[0-9])(\..*)?$', caseSensitive: false).hasMatch(n)) n = '_$n';
  if (n.length > 180) {
    final dot = n.lastIndexOf('.');
    final ext = dot > 0 && n.length - dot <= 12 ? n.substring(dot) : '';
    n = n.substring(0, 180 - ext.length) + ext;
  }
  return n.isEmpty ? fallback : n;
}
