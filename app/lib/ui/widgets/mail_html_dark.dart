// Lecture des e-mails en mode sombre, comme Gmail et Outlook :
// - un e-mail mis en page (fonds colorés, lettre d'information) reste sur fond clair, sinon il devient illisible ;
// - un e-mail simple suit le thème sombre : ses couleurs de texte imposées (souvent du noir, Outlook) sont retirées.

final _ownBackground = RegExp(r'''bgcolor\s*=|background(-color)?\s*:\s*(?!\s*(transparent|inherit|none|initial|unset)\b)[#a-z]''', caseSensitive: false);
final _cssColor = RegExp(r'''(?<![-\w])color\s*:\s*[^;"'>]+;?''', caseSensitive: false);
final _attrColor = RegExp(r'''\scolor\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)''', caseSensitive: false);

/// L'e-mail impose ses propres fonds : à afficher sur fond clair.
bool mailHasOwnBackground(String html) => _ownBackground.hasMatch(html);

/// Retire les couleurs de texte imposées (attribut style et balise font), pas les fonds ni les bordures.
String mailWithoutTextColors(String html) => html.replaceAll(_cssColor, '').replaceAll(_attrColor, '');

/// Mode d'affichage : (fond sombre ?, html à afficher).
(bool, String) mailForTheme(String html, bool dark) {
  if (!dark) return (false, html);
  if (mailHasOwnBackground(html)) return (false, html);
  return (true, mailWithoutTextColors(html));
}
