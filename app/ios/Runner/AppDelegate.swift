import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    // iOS limite une application à 256 fichiers ouverts : la base locale (un fichier + un verrou par type
    // de données) peut dépasser ce nombre. On relève la limite douce avant le démarrage de Flutter.
    var rl = rlimit()
    if getrlimit(RLIMIT_NOFILE, &rl) == 0 {
      let target = min(rlim_t(10240), rl.rlim_max)
      if rl.rlim_cur < target {
        rl.rlim_cur = target
        _ = setrlimit(RLIMIT_NOFILE, &rl)
      }
    }
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
  }
}
