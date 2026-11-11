# Yomara Office

Éditeur Word (.docx) et Excel (.xlsx, .xls, .ods, .csv) des Documents, servi par Laravel (`/office/{session}`).

- Traitement de texte : SuperDoc (AGPL-3.0), télémétrie désactivée.
- Tableur : FortuneSheet + FortuneExcel (MIT), interface traduite en français (`src/sheet-fr.js`).

Construction (le résultat va dans `public/office`) :

```bash
npm ci
npx vite build
rm -rf ../../public/office && cp -r dist ../../public/office
```
