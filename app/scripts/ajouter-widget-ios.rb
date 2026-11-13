#!/usr/bin/env ruby
# Ajoute au projet Xcode la cible « YomaraWidget » (widget d'écran d'accueil iPhone/iPad), une seule fois.
# Utilise l'outil xcodeproj livré avec CocoaPods. Sans effet si la cible existe déjà.
#   ruby scripts/ajouter-widget-ios.rb
require 'xcodeproj'

path = File.expand_path('../ios/Runner.xcodeproj', __dir__)
project = Xcodeproj::Project.open(path)
if project.targets.any? { |t| t.name == 'YomaraWidget' }
  puts 'Widget iOS déjà présent dans le projet.'
  exit 0
end
app = project.targets.find { |t| t.name == 'Runner' } or abort('Cible Runner introuvable.')

group = project.main_group.find_subpath('YomaraWidget', true)
group.set_source_tree('<group>')
group.set_path('YomaraWidget')
swift = group.new_reference('YomaraWidget.swift')
group.new_reference('Info.plist')
group.new_reference('YomaraWidget.entitlements')
xcconfig = group.new_reference('Version.xcconfig')

target = project.new_target(:app_extension, 'YomaraWidget', :ios, '15.0', nil, :swift)
target.add_file_references([swift])
target.add_system_framework(%w[WidgetKit SwiftUI])
target.add_build_configuration('Profile', :release) unless target.build_configurations.any? { |c| c.name == 'Profile' }
team = app.build_configurations.map { |c| c.build_settings['DEVELOPMENT_TEAM'] }.compact.first
target.build_configurations.each do |c|
  c.base_configuration_reference = xcconfig
  s = c.build_settings
  s['PRODUCT_NAME'] = 'YomaraWidget'
  s['PRODUCT_BUNDLE_IDENTIFIER'] = 'com.yomaratechnologies.yomaraerp.widgets'
  s['INFOPLIST_FILE'] = 'YomaraWidget/Info.plist'
  s['GENERATE_INFOPLIST_FILE'] = 'NO'
  s['CODE_SIGN_ENTITLEMENTS'] = 'YomaraWidget/YomaraWidget.entitlements'
  s['CODE_SIGN_STYLE'] = 'Automatic'
  s['SWIFT_VERSION'] = '5.0'
  s['IPHONEOS_DEPLOYMENT_TARGET'] = '15.0'
  s['TARGETED_DEVICE_FAMILY'] = '1,2'
  s['SKIP_INSTALL'] = 'YES'
  s['APPLICATION_EXTENSION_API_ONLY'] = 'YES'
  s['LD_RUNPATH_SEARCH_PATHS'] = ['$(inherited)', '@executable_path/Frameworks', '@executable_path/../../Frameworks']
  s['MARKETING_VERSION'] = '$(FLUTTER_BUILD_NAME)'
  s['CURRENT_PROJECT_VERSION'] = '$(FLUTTER_BUILD_NUMBER)'
  s['DEVELOPMENT_TEAM'] = team if team
end

# L'application embarque le widget (dossier PlugIns), avant les scripts Flutter pour éviter une dépendance circulaire
app.add_dependency(target)
embed = app.new_copy_files_build_phase('Embed Foundation Extensions')
embed.symbol_dst_subfolder_spec = :plug_ins
embed.add_file_reference(target.product_reference).settings = { 'ATTRIBUTES' => ['RemoveHeadersOnCopy'] }
app.build_phases.delete(embed)
first_script = app.build_phases.index { |p| p.is_a?(Xcodeproj::Project::Object::PBXShellScriptBuildPhase) && p.name.to_s.include?('Thin Binary') }
app.build_phases.insert(first_script || app.build_phases.length, embed)

project.save
puts 'Widget iOS ajouté au projet (cible YomaraWidget).'
