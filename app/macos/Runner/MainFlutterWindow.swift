import Cocoa
import FlutterMacOS

class MainFlutterWindow: NSWindow {
  override func awakeFromNib() {
    MainFlutterWindow.raiseOpenFileLimit()
    let flutterViewController = FlutterViewController()
    let windowFrame = self.frame
    self.contentViewController = flutterViewController
    self.setFrame(windowFrame, display: true)

    RegisterGeneratedPlugins(registry: flutterViewController)

    super.awakeFromNib()
  }

  /// macOS limite une application à 256 fichiers ouverts : la base locale (un fichier + un verrou
  /// par type de données) dépasse ce nombre quand beaucoup de modules sont actifs (« Too many open files »).
  /// On relève la limite douce, dans la limite autorisée par le système, avant le démarrage de l'app.
  static func raiseOpenFileLimit() {
    var rl = rlimit()
    guard getrlimit(RLIMIT_NOFILE, &rl) == 0 else { return }
    let wanted: rlim_t = 10240
    let target = min(wanted, rl.rlim_max)
    if rl.rlim_cur < target {
      rl.rlim_cur = target
      _ = setrlimit(RLIMIT_NOFILE, &rl)
    }
  }
}
