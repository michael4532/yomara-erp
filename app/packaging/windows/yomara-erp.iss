; Installateur Windows de Yomara ERP (Inno Setup 6). Compilé par GitHub Actions : iscc /DAppVersion=0.99.8 yomara-erp.iss
#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif
[Setup]
AppId={{6F1C2E5A-9B3D-4C8E-A7F0-1E2D3C4B5A69}
AppName=Yomara ERP
AppVersion={#AppVersion}
AppVerName=Yomara ERP {#AppVersion}
AppPublisher=Yomara Technologies
AppPublisherURL=https://erp.yomaratechnologies.com
AppSupportURL=https://erp.yomaratechnologies.com/contact
AppUpdatesURL=https://erp.yomaratechnologies.com/telecharger
AppCopyright=Copyright (C) 2026 Yomara Technologies. Licence AGPL-3.0.
VersionInfoCompany=Yomara Technologies
VersionInfoCopyright=Copyright (C) 2026 Yomara Technologies
VersionInfoDescription=Installateur de Yomara ERP
VersionInfoProductName=Yomara ERP
VersionInfoVersion={#AppVersion}
DefaultDirName={autopf}\Yomara ERP
DefaultGroupName=Yomara ERP
DisableProgramGroupPage=yes
LicenseFile=..\..\LICENSE
InfoBeforeFile=..\..\NOTICE.md
OutputDir=..\..\dist
OutputBaseFilename=Yomara-ERP-Setup-v{#AppVersion}-x64
SetupIconFile=..\..\windows\runner\resources\app_icon.ico
UninstallDisplayIcon={app}\yomara_erp.exe
Compression=lzma2/ultra
SolidCompression=yes
WizardStyle=modern
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequiredOverridesAllowed=dialog
CloseApplications=yes

[Languages]
Name: "french"; MessagesFile: "compiler:Languages\French.isl"
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "{cm:CreateDesktopIcon}"; GroupDescription: "{cm:AdditionalIcons}"

[Files]
Source: "..\..\build\windows\x64\runner\Release\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\..\LICENSE"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\..\NOTICE.md"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{autoprograms}\Yomara ERP"; Filename: "{app}\yomara_erp.exe"
Name: "{autodesktop}\Yomara ERP"; Filename: "{app}\yomara_erp.exe"; Tasks: desktopicon

[Run]
Filename: "{app}\yomara_erp.exe"; Description: "{cm:LaunchProgram,Yomara ERP}"; Flags: nowait postinstall skipifsilent
