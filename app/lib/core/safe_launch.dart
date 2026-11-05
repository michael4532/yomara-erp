import 'package:url_launcher/url_launcher.dart';

/// Schémas d'adresses que l'application accepte d'ouvrir. Tout le reste (file:, javascript:, data:, intent:,
/// chemins réseau \\serveur, protocoles d'applications Windows « search-ms: », « ms-… ») est refusé :
/// ces liens peuvent venir d'un e-mail, d'un message ou d'une donnée saisie par un tiers.
const safeLinkSchemes = {'http', 'https', 'mailto', 'tel', 'sms'};

bool isSafeLink(Uri uri) => safeLinkSchemes.contains(uri.scheme.toLowerCase()) && (uri.scheme.toLowerCase().startsWith('http') ? uri.host.isNotEmpty : true);

/// Remplace `launchUrl` partout dans l'application : n'ouvre que les liens web, e-mail, téléphone et SMS.
Future<bool> safeLaunchUrl(Uri url, {LaunchMode mode = LaunchMode.platformDefault, WebViewConfiguration webViewConfiguration = const WebViewConfiguration(), String? webOnlyWindowName}) async {
  if (!isSafeLink(url)) return false;
  return launchUrl(url, mode: mode, webViewConfiguration: webViewConfiguration, webOnlyWindowName: webOnlyWindowName);
}
