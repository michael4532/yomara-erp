# Contribuer à Yomara ERP

Merci de votre intérêt ! Quelques règles pour que votre proposition soit acceptée rapidement.

## Avant de commencer

- Pour une correction simple, ouvrez directement une *pull request*.
- Pour une nouvelle fonction ou un changement important, ouvrez d'abord un ticket pour en discuter.
- Les échanges se font en français ou en anglais.

## Règles de code

- **Serveur** (`server/`) : PSR-12, tests PHPUnit pour tout changement de comportement (`php artisan test` doit passer).
- **Application** (`app/`) : `flutter analyze` sans erreur, `dart format --line-length 160`.
- Textes de l'interface en français, passés par `tr()` pour la traduction.
- Les règles métier restent identiques en ligne et hors ligne (synchronisation idempotente).
- Aucun secret (clé d'API, mot de passe, certificat) dans le code ou les tests.

## Licence des contributions

En proposant une contribution, vous acceptez qu'elle soit publiée sous la licence AGPL-3.0 du projet et vous certifiez en avoir le droit ([Developer Certificate of Origin](https://developercertificate.org/)) : signez vos commits avec `git commit -s`.
