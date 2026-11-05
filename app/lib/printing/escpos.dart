import 'dart:typed_data';

/// Générateur de commandes ESC/POS (imprimantes thermiques Epson, Xprinter,
/// Rongta, Sunmi…). Texte encodé en page de code PC850 (accents français).
class EscPos {
  EscPos({this.columns = 48});

  /// 48 colonnes en 80 mm, 32 en 58 mm (police A).
  final int columns;
  final _b = BytesBuilder();

  static const _esc = 0x1B, _gs = 0x1D;

  EscPos raw(List<int> bytes) {
    _b.add(bytes);
    return this;
  }

  /// Réinitialise l'imprimante et sélectionne la page de code PC850.
  EscPos init() => raw([_esc, 0x40, _esc, 0x74, 2]);

  EscPos _align(int a) => raw([_esc, 0x61, a]);
  EscPos _bold(bool on) => raw([_esc, 0x45, on ? 1 : 0]);
  EscPos _size({bool tall = false, bool wide = false}) => raw([_gs, 0x21, (wide ? 0x10 : 0) | (tall ? 0x01 : 0)]);

  /// Texte (retour à la ligne automatique sur la largeur du papier).
  EscPos text(String s, {bool center = false, bool bold = false, bool tall = false, bool wide = false}) {
    _align(center ? 1 : 0);
    if (bold) _bold(true);
    if (tall || wide) _size(tall: tall, wide: wide);
    for (final line in wrap(s, wide ? columns ~/ 2 : columns)) {
      raw(encode(line));
      raw([0x0A]);
    }
    if (tall || wide) _size();
    if (bold) _bold(false);
    if (center) _align(0);
    return this;
  }

  /// Ligne « libellé ............ montant ».
  EscPos row(String left, String right, {bool bold = false, bool tall = false}) {
    final r = clean(right);
    final room = columns - r.length - 1;
    var l = clean(left);
    if (l.length > room) l = room > 1 ? l.substring(0, room) : '';
    if (bold) _bold(true);
    if (tall) _size(tall: true);
    raw(encode(l.padRight(columns - r.length) + r));
    raw([0x0A]);
    if (tall) _size();
    if (bold) _bold(false);
    return this;
  }

  EscPos divider([String ch = '-']) => text(ch * columns);
  EscPos feed([int lines = 1]) => raw([_esc, 0x64, lines]);

  /// Avance le papier puis coupe (coupe partielle).
  EscPos cut() => raw([_gs, 0x56, 66, 3]);

  /// Impulsion d'ouverture du tiroir-caisse (connecteur RJ11 de l'imprimante).
  EscPos drawer() => raw([_esc, 0x70, 0, 25, 250]);

  /// QR code natif (GS ( k), centré — module 1 à 16, correction M.
  EscPos qr(String data, {int size = 6}) {
    final d = data.codeUnits.where((c) => c < 0x80).toList();
    final len = d.length + 3;
    _align(1);
    raw([_gs, 0x28, 0x6B, 4, 0, 0x31, 0x41, 0x32, 0]); // modèle 2
    raw([_gs, 0x28, 0x6B, 3, 0, 0x31, 0x43, size.clamp(1, 16)]);
    raw([_gs, 0x28, 0x6B, 3, 0, 0x31, 0x45, 0x31]);
    raw([_gs, 0x28, 0x6B, len & 0xFF, (len >> 8) & 0xFF, 0x31, 0x50, 0x30, ...d]);
    raw([_gs, 0x28, 0x6B, 3, 0, 0x31, 0x51, 0x30]);
    raw([0x0A]);
    _align(0);
    return this;
  }

  Uint8List bytes() => _b.toBytes();

  // ------------------------------------------------------------------

  static String clean(String s) => s
      .replaceAll(RegExp('[    ]'), ' ')
      .replaceAll(RegExp('[‘’]'), "'")
      .replaceAll(RegExp('[“”]'), '"')
      .replaceAll(RegExp('[–—]'), '-')
      .replaceAll('…', '...')
      .replaceAll('œ', 'oe')
      .replaceAll('Œ', 'OE')
      .replaceAll('€', 'EUR')
      .replaceAll('×', 'x');

  static List<String> wrap(String s, int width) {
    final out = <String>[];
    for (final para in clean(s).split('\n')) {
      var line = '';
      for (final word in para.split(' ')) {
        var w = word;
        while (w.length > width) {
          if (line.isNotEmpty) {
            out.add(line);
            line = '';
          }
          out.add(w.substring(0, width));
          w = w.substring(width);
        }
        if (line.isEmpty) {
          line = w;
        } else if (line.length + 1 + w.length <= width) {
          line = '$line $w';
        } else {
          out.add(line);
          line = w;
        }
      }
      out.add(line);
    }
    return out;
  }

  static const _pc850 = {
    'Ç': 0x80, 'ü': 0x81, 'é': 0x82, 'â': 0x83, 'ä': 0x84, 'à': 0x85, 'å': 0x86, 'ç': 0x87,
    'ê': 0x88, 'ë': 0x89, 'è': 0x8A, 'ï': 0x8B, 'î': 0x8C, 'ì': 0x8D, 'Ä': 0x8E, 'Å': 0x8F,
    'É': 0x90, 'æ': 0x91, 'Æ': 0x92, 'ô': 0x93, 'ö': 0x94, 'ò': 0x95, 'û': 0x96, 'ù': 0x97,
    'ÿ': 0x98, 'Ö': 0x99, 'Ü': 0x9A, '£': 0x9C, 'á': 0xA0, 'í': 0xA1, 'ó': 0xA2, 'ú': 0xA3,
    'ñ': 0xA4, 'Ñ': 0xA5, 'ª': 0xA6, 'º': 0xA7, '«': 0xAE, '»': 0xAF, 'Á': 0xB5, 'Â': 0xB6,
    'À': 0xB7, 'Ê': 0xD2, 'Ë': 0xD3, 'È': 0xD4, 'Í': 0xD6, 'Î': 0xD7, 'Ï': 0xD8, 'Ó': 0xE0,
    'Ô': 0xE2, 'Ò': 0xE3, 'Ú': 0xE9, 'Û': 0xEA, 'Ù': 0xEB, '°': 0xF8, '·': 0xFA,
  };

  static List<int> encode(String s) => [
        for (final r in clean(s).runes)
          if (r < 0x80) r else _pc850[String.fromCharCode(r)] ?? 0x3F,
      ];
}
