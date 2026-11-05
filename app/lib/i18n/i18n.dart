import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart' show rootBundle;
import 'package:intl/date_symbol_data_local.dart';
import 'package:intl/intl.dart';

/// Langues de l'interface. Le français est la langue source : chaque texte de l'application est sa propre clé,
/// et les dictionnaires (`assets/i18n/<langue>.json`) donnent la traduction. Un texte non traduit reste en français.
/// Les textes variables utilisent des modèles : « {} fiche(s) » traduit « 12 fiche(s) ».
class I18n {
  static const languages = {'fr': 'Français', 'en': 'English'};

  static final ValueNotifier<String> current = ValueNotifier('fr');
  static String get lang => current.value;

  static Map<String, String> _exact = {};
  static Map<String, String> _upper = {};
  static Map<String, String> _trimmed = {};

  /// Marque invisible : le texte qui la porte est une donnée saisie (nom, titre…) et n'est jamais traduit.
  static const verbatim = '\u2060';
  static List<(RegExp, String)> _patterns = [];
  // Index des modèles : par les 3 premiers caractères fixes, ou par un fragment fixe (modèles commençant par {})
  static Map<String, List<int>> _byPrefix = {};
  static List<(String, int)> _floating = [];
  static final Map<String, String> _cache = {};
  static final RegExp _letters = RegExp(r'[A-Za-zÀ-ÿ]{2,}');

  /// Langue des dates et des nombres (intl).
  static String get dateLocale => lang;

  static Future<void> use(String code) async {
    code = languages.containsKey(code) ? code : 'fr';
    await initializeDateFormatting(code);
    Intl.defaultLocale = code;
    _cache.clear();
    if (code == 'fr') {
      _exact = {};
      _patterns = [];
    } else {
      try {
        final raw = jsonDecode(await rootBundle.loadString('assets/i18n/$code.json')) as Map<String, dynamic>;
        load(raw.map((k, v) => MapEntry(k, '$v')));
      } catch (e) {
        if (kDebugMode) debugPrint('i18n $code : $e');
      }
    }
    current.value = code;
  }

  /// Charge un dictionnaire (utilisé aussi par les tests).
  static void load(Map<String, String> dict) {
    _cache.clear();
    _exact = {};
    _upper = {};
    _trimmed = {};
    for (final e in dict.entries) {
      if (e.value.isEmpty) continue;
      if (!e.key.contains('{}')) {
        _exact[e.key] = e.value;
        final up = e.key.toUpperCase();
        if (up != e.key) _upper.putIfAbsent(up, () => e.value.toUpperCase());
        final tk = e.key.trim();
        if (tk != e.key && tk.isNotEmpty) _trimmed.putIfAbsent(tk, () => e.value.trim());
      }
    }
    // Les modèles les plus longs (les plus précis) d'abord
    final keys = <(RegExp, String, String)>[
      for (final e in dict.entries)
        if (e.key.contains('{}') && e.value.isNotEmpty && RegExp(r'[A-Za-zÀ-ÿ]{2,}').hasMatch(e.key.replaceAll('{}', '')))
          (RegExp('^${e.key.split('{}').map(RegExp.escape).join('(.+?)')}\$', dotAll: true), e.value, e.key),
    ]..sort((a, b) => b.$3.length.compareTo(a.$3.length));
    _patterns = [for (final k in keys) (k.$1, k.$2)];
    _byPrefix = {};
    _floating = [];
    for (var i = 0; i < keys.length; i++) {
      final key = keys[i].$3;
      if (!key.startsWith('{}') && key.indexOf('{}') >= 3) {
        (_byPrefix[key.substring(0, 3)] ??= []).add(i);
      } else {
        final frags = key.split('{}').where((f) => f.trim().length >= 2).toList()..sort((a, b) => b.length.compareTo(a.length));
        _floating.add((frags.isEmpty ? key.replaceAll('{}', '') : frags.first, i));
      }
    }
  }

  static String translate(String s) {
    if (_exact.isEmpty && _patterns.isEmpty) return s;
    if (s.startsWith(verbatim)) return s;
    final hit = _exact[s];
    if (hit != null) return hit;
    final c = _cache[s];
    if (c != null) return c;
    var out = s;
    if (s.length >= 3 && s.length < 400 && _letters.hasMatch(s)) {
      final cand = <int>{...?_byPrefix[s.substring(0, 3)], for (final (frag, i) in _floating) if (s.contains(frag)) i}.toList()..sort();
      for (final idx in cand) {
        final (re, tpl) = _patterns[idx];
        final m = re.firstMatch(s);
        if (m != null) {
          var i = 0;
          out = tpl.replaceAllMapped('{}', (_) {
            final g = m.group(++i) ?? '';
            return g == s ? g : translate(g);
          });
          break;
        }
      }
    }
    if (identical(out, s)) out = _upper[s] ?? _phrases(s);
    if (_cache.length > 5000) _cache.clear();
    return _cache[s] = out;
  }

  static final RegExp _composed = RegExp(r'[0-9:·%,(]');
  static final RegExp _word = RegExp(r"[A-Za-zÀ-ÿ0-9][A-Za-zÀ-ÿ0-9'’\-]*");

  /// Dernier recours pour les textes assemblés à l'exécution (« +37,9 % sur 1 an », « Bonsoir Awa ») :
  /// les expressions connues sont traduites sur place, la plus longue d'abord (plusieurs mots, ou un mot d'au moins 5 lettres).
  static String _phrases(String s) {
    // uniquement pour les textes composés (valeur, date, ponctuation) : jamais pour un libellé ou un nom simples
    if (s.length > 300 || !_letters.hasMatch(s) || !_composed.hasMatch(s)) return s;
    final words = _word.allMatches(s).toList();
    if (words.isEmpty || words.length > 40) return s;
    final buf = StringBuffer();
    var last = 0;
    var changed = false;
    var i = 0;
    while (i < words.length) {
      var done = false;
      for (var span = (words.length - i).clamp(1, 8); span >= 1; span--) {
        final start = words[i].start, end = words[i + span - 1].end;
        if (start == 0 && end == s.length) continue; // le texte entier a déjà été cherché
        final phrase = s.substring(start, end);
        if (span == 1 && (phrase.length < 3 || RegExp(r'^[0-9]').hasMatch(phrase))) continue;
        final hit = _exact[phrase] ?? _upper[phrase] ?? _trimmed[phrase];
        if (hit != null) {
          buf.write(s.substring(last, start));
          buf.write(hit);
          last = end;
          i += span;
          changed = true;
          done = true;
          break;
        }
      }
      if (!done) i++;
    }
    if (!changed) return s;
    buf.write(s.substring(last));
    return buf.toString();
  }
}

/// Traduit un texte de l'interface dans la langue choisie (le français est la clé).
String tr(String s) => I18n.translate(s);

/// Donnée saisie à afficher telle quelle (jamais traduite).
String verbatim(String s) => '${I18n.verbatim}$s';

/// Variante pour un texte facultatif.
String? trn(String? s) => s == null ? null : I18n.translate(s);
