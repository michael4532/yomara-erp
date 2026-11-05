# Yomara ERP

ERP complet pour PME, pensé pour l'Afrique de l'Ouest et centrale : ventes, caisse, stock, achats, comptabilité SYSCOHADA, RH et paie, CRM, projets, et une trentaine de modules. Il fonctionne **hors ligne** et se synchronise dès que la connexion revient.

Un seul compte sur le web, Windows, macOS, Linux, Android, iPhone et iPad.

- Version en ligne (SaaS) : https://erp.yomaratechnologies.com
- Télécharger les applications : https://erp.yomaratechnologies.com/telecharger
- Licence : [GNU AGPL v3](LICENSE) · marques et mentions : [NOTICE.md](NOTICE.md)

## Contenu du dépôt

| Dossier | Contenu |
|---|---|
| [`server/`](server) | API et site (PHP 8.2+, Laravel 12). Fonctionne sur un hébergement mutualisé cPanel comme sur un VPS. |
| [`app/`](app) | Application Flutter : web, Windows, macOS, Linux, Android, iOS. La version web est servie par le serveur dans `public/app`. |

## Installer sur votre serveur

Le plus simple : téléchargez le **paquet serveur prêt à l'emploi** (dépendances et interface web incluses) depuis la page [Télécharger](https://erp.yomaratechnologies.com/telecharger#licence) ou les [versions GitHub](../../releases), envoyez-le sur votre hébergement, créez une base MySQL/MariaDB, puis ouvrez votre adresse : l'assistant d'installation fait le reste.

Pré-requis : PHP 8.2 ou plus récent (extensions `pdo_mysql`, `mbstring`, `openssl`, `xml`, `fileinfo`, `curl` ; `zip` conseillée pour les mises à jour), et une base MySQL ou MariaDB. L'assistant vérifie tout au démarrage.

## Compiler depuis les sources

```bash
# Serveur
cd server
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate

# Interface web (Flutter, canal stable)
cd ../app
flutter pub get
flutter build web --release --base-href /app/ --no-web-resources-cdn
cp -r build/web ../server/public/app
```

Applications de bureau et mobiles : `flutter build windows|macos|linux|apk|ios --release` depuis `app/`. Le workflow [`build-apps.yml`](.github/workflows/build-apps.yml) produit les installateurs Windows, macOS et Linux à chaque tag `v*`.

Les notifications push et la connexion Google utilisent Firebase : pour votre propre version, créez votre projet Firebase et remplacez `app/lib/core/firebase_setup.dart`, `app/android/app/google-services.json` et `app/ios/Runner/GoogleService-Info.plist` (non fournis).

## Tests

```bash
cd server && php artisan test
cd app && flutter test
```

## Contribuer

Les contributions sont bienvenues : lisez [CONTRIBUTING.md](CONTRIBUTING.md). Pour signaler une faille de sécurité, suivez [SECURITY.md](SECURITY.md) (pas de ticket public).

## Marques

« Yomara », « Yomara ERP », « Yomeet » et « Sensya » sont des marques de Yomara Technologies, non couvertes par la licence. Une version modifiée et redistribuée doit porter un autre nom et un autre logo.

---

### English

Yomara ERP is a full, offline-first ERP for small and medium businesses (sales, POS, inventory, SYSCOHADA accounting, HR and payroll, CRM, projects…), with a Laravel server (`server/`) and a Flutter app for web, desktop and mobile (`app/`). Licensed under the GNU AGPL v3; "Yomara" names and logos are trademarks of Yomara Technologies. See the French sections above for install and build steps.

© 2026 Yomara Technologies, Abidjan, Côte d'Ivoire.
