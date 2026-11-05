# Yomara ERP — crée la clé de signature Android (keystore) à son emplacement et configure le projet (Windows).
#
#   powershell -ExecutionPolicy Bypass -File scripts\generer-keystore.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\generer-keystore.ps1 -Store D:\cles\yomara-erp-upload.jks
param(
  [string]$Store = "$env:USERPROFILE\.yomara-keys\yomara-erp-upload.jks",
  [string]$Alias = "yomara-erp"
)
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Props = Join-Path $Root "android\key.properties"

$keytool = (Get-Command keytool -ErrorAction SilentlyContinue).Source
if (-not $keytool) {
  foreach ($j in @("$env:ProgramFiles\Android\Android Studio\jbr\bin\keytool.exe", "$env:LOCALAPPDATA\Programs\Android Studio\jbr\bin\keytool.exe")) {
    if (Test-Path $j) { $keytool = $j; break }
  }
}
if (-not $keytool) { Write-Error "keytool introuvable : installez Java (JDK 17) ou Android Studio." }

function Read-Plain([string]$prompt) {
  $s = Read-Host -AsSecureString $prompt
  [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))
}

if (Test-Path $Store) {
  Write-Host "Un keystore existe déjà : $Store (il est conservé, seule la configuration est refaite)."
  $Pass = Read-Plain "Mot de passe du keystore"
} else {
  New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Store) | Out-Null
  while ($true) {
    $Pass = Read-Plain "Mot de passe du keystore (6 caractères minimum)"
    $Pass2 = Read-Plain "Confirmez"
    if ($Pass -eq $Pass2 -and $Pass.Length -ge 6) { break }
    Write-Host "Les mots de passe ne correspondent pas ou sont trop courts, recommencez."
  }
  $Org = Read-Host "Nom de l'entreprise [Yomara Technologies]"; if (-not $Org) { $Org = "Yomara Technologies" }
  $City = Read-Host "Ville [Abidjan]"; if (-not $City) { $City = "Abidjan" }
  & $keytool -genkeypair -v -keystore $Store -alias $Alias -keyalg RSA -keysize 2048 -validity 10000 `
    -storepass $Pass -keypass $Pass -dname "CN=$Org, O=$Org, L=$City, C=CI" | Out-Null
  Write-Host "Keystore créé : $Store"
}

& $keytool -list -keystore $Store -storepass $Pass -alias $Alias *> $null
if ($LASTEXITCODE -ne 0) { Write-Error "Mot de passe incorrect ou alias « $Alias » absent." }

$storePath = $Store -replace '\\', '/'
@("storePassword=$Pass", "keyPassword=$Pass", "keyAlias=$Alias", "storeFile=$storePath") | Set-Content -Encoding ASCII $Props
Write-Host "Projet configuré : android\key.properties (jamais dans le code source livré)."
Write-Host ""
Write-Host "Empreintes à ajouter dans Firebase › Paramètres du projet › Application Android › Ajouter une empreinte :"
& $keytool -list -v -keystore $Store -storepass $Pass -alias $Alias | Select-String -Pattern "SHA1:|SHA256:" | ForEach-Object { "  " + $_.Line.Trim() }
Write-Host ""
Write-Host "Ajoutez aussi l'empreinte de la clé de signature d'application de Google Play (Play Console › Intégrité de l'application)."
Write-Host "Construire l'application pour Google Play :  flutter build appbundle --release"
