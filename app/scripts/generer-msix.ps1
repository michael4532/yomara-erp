# Yomara ERP — génère le paquet Microsoft Store (.msix) directement sur un PC Windows.
#   powershell -ExecutionPolicy Bypass -File scripts\generer-msix.ps1
# Prérequis : Flutter et Visual Studio (charge de travail « Développement Desktop en C++ »).
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')
$v = (Select-String -Path pubspec.yaml -Pattern '^version:\s*([0-9.]+)').Matches[0].Groups[1].Value
Write-Host "Yomara ERP $v — paquet Microsoft Store ($v.0)"
flutter pub get
flutter build windows --release
New-Item -ItemType Directory -Force sorties\msix | Out-Null
dart run msix:create --store --build-windows false --version "$v.0" --output-path sorties\msix --output-name "Yomara-ERP-$v-store"
Write-Host "Paquet prêt : sorties\msix\Yomara-ERP-$v-store.msix"
