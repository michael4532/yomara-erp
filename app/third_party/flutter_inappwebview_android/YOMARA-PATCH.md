# Copie de flutter_inappwebview_android 1.1.3 (licence Apache-2.0, voir LICENSE)

Seule modification : `android/build.gradle`, `proguard-android.txt` → `proguard-android-optimize.txt`.
Le plugin Android Gradle 9 refuse `proguard-android.txt` (« no longer supported »), ce qui faisait échouer
la construction Android. Même correction que la version 1.2.0 du plugin (encore en bêta).
À retirer quand une version stable corrigée sera publiée (pubspec.yaml › dependency_overrides).
