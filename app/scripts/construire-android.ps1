# Yomara ERP — construit l'application Android SIGNÉE (APK + App Bundle) sous Windows.
#   powershell -ExecutionPolicy Bypass -File scripts\construire-android.ps1
# (L'application iPhone se construit uniquement sur Mac : scripts/construire-mobiles.sh)
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) { Write-Error "Flutter n'est pas installé." }
$version = ((Select-String -Path pubspec.yaml -Pattern '^version:').Line -split '\s+')[1]
$name = $version.Split('+')[0]
$out = Join-Path $Root "build\livrables\$name"
New-Item -ItemType Directory -Force -Path $out | Out-Null
Write-Host "Yomara ERP $version — pensez à augmenter le numéro après « + » dans pubspec.yaml à chaque envoi sur Google Play."
if (-not (Test-Path android\key.properties)) {
  Write-Host "Aucune clé de signature : création du keystore…"
  & powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "generer-keystore.ps1")
}
flutter pub get | Out-Null
flutter build apk --release; if ($LASTEXITCODE -ne 0) { exit 1 }
flutter build appbundle --release; if ($LASTEXITCODE -ne 0) { exit 1 }
Copy-Item build\app\outputs\flutter-apk\app-release.apk (Join-Path $out "yomara-erp-$name.apk") -Force
Copy-Item build\app\outputs\bundle\release\app-release.aab (Join-Path $out "yomara-erp-$name.aab") -Force
Write-Host "Fichiers prêts dans : $out"
explorer $out
