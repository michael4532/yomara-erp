/// Lecture tolérante des réponses JSON du serveur : un objet vide envoyé sous forme de liste vide (`[]`)
/// devient une Map vide au lieu de faire planter l'écran.
Map<String, dynamic> asMap(Object? v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};

/// Comme [asMap], mais garde `null` (et une liste vide) comme « absent ».
Map<String, dynamic>? asMapOrNull(Object? v) => v is Map ? Map<String, dynamic>.from(v) : null;

/// `clamp` sans plantage quand la borne haute passe sous la borne basse (écran étroit, liste vide…).
/// Pour une taille : on prend alors la place disponible.
double clampSize(num v, num lo, num hi) => hi < lo ? (hi < 0 ? 0.0 : hi.toDouble()) : v.clamp(lo, hi).toDouble();

/// Pour un nombre de colonnes ou d'éléments : au moins [lo].
int clampCount(int v, int lo, int hi) => hi < lo ? lo : v.clamp(lo, hi);
