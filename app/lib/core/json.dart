/// Lecture tolérante des réponses JSON du serveur : un objet vide envoyé sous forme de liste vide (`[]`)
/// devient une Map vide au lieu de faire planter l'écran.
Map<String, dynamic> asMap(Object? v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};
