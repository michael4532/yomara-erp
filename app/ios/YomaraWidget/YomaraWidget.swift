import SwiftUI
import WidgetKit

// Widget « Yomara ERP » : ventes du jour, reste à encaisser, factures en retard.
// Les chiffres sont écrits par l'application dans le groupe partagé à chaque synchronisation (lisibles sans Internet).

private let appGroup = "group.com.yomaratechnologies.yomaraerp"
private let night = Color(red: 11 / 255, green: 27 / 255, blue: 58 / 255)
private let nightSoft = Color(red: 19 / 255, green: 39 / 255, blue: 79 / 255)
private let gold = Color(red: 1, green: 197 / 255, blue: 49 / 255)

struct YomaraEntry: TimelineEntry {
    let date: Date
    let company: String
    let salesToday: String
    let invoicesToday: String
    let toCollect: String
    let overdue: String
    let updated: String
    let allowed: Bool
}

struct YomaraProvider: TimelineProvider {
    func read() -> YomaraEntry {
        let d = UserDefaults(suiteName: appGroup)
        func s(_ k: String, _ def: String) -> String { d?.string(forKey: k) ?? def }
        return YomaraEntry(
            date: Date(),
            company: s("company", ""),
            salesToday: s("sales_today", "—"),
            invoicesToday: s("invoices_today", "0"),
            toCollect: s("to_collect", "—"),
            overdue: s("overdue", "0"),
            updated: s("updated", "--:--"),
            allowed: s("allowed", "false") == "true"
        )
    }

    func placeholder(in context: Context) -> YomaraEntry {
        YomaraEntry(date: Date(), company: "Yomara ERP", salesToday: "450 000 F", invoicesToday: "3", toCollect: "1 250 000 F", overdue: "2", updated: "09:41", allowed: true)
    }

    func getSnapshot(in context: Context, completion: @escaping (YomaraEntry) -> Void) {
        completion(context.isPreview ? placeholder(in: context) : read())
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<YomaraEntry>) -> Void) {
        // L'application demande le rafraîchissement après chaque synchronisation ; relecture de sécurité toutes les 30 min
        completion(Timeline(entries: [read()], policy: .after(Date().addingTimeInterval(30 * 60))))
    }
}

struct YomaraWidgetView: View {
    @Environment(\.widgetFamily) var family
    let entry: YomaraEntry

    var header: some View {
        HStack {
            Text(entry.company.isEmpty ? "Yomara ERP" : entry.company)
                .font(.caption.bold()).foregroundColor(gold).lineLimit(1)
            Spacer()
            Text(entry.updated).font(.caption2).foregroundColor(.white.opacity(0.6))
        }
    }

    func card(_ value: String, _ label: String, _ color: Color = .white) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(value).font(.subheadline.bold()).foregroundColor(color).lineLimit(1).minimumScaleFactor(0.6)
            Text(label).font(.caption2).foregroundColor(.white.opacity(0.7))
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
        .padding(8)
        .background(RoundedRectangle(cornerRadius: 12).fill(nightSoft))
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            header
            if !entry.allowed {
                Spacer()
                Text("Ouvrez Yomara ERP pour afficher vos chiffres du jour.")
                    .font(.footnote).foregroundColor(.white)
                Spacer()
            } else {
                Text(entry.salesToday).font(.title2.bold()).foregroundColor(.white).lineLimit(1).minimumScaleFactor(0.5)
                Text(entry.invoicesToday == "1" ? "Ventes du jour · 1 facture" : "Ventes du jour · \(entry.invoicesToday) factures")
                    .font(.caption2).foregroundColor(.white.opacity(0.7))
                if family == .systemSmall {
                    Spacer(minLength: 2)
                    Text("À encaisser : \(entry.toCollect)").font(.caption2).foregroundColor(.white.opacity(0.85)).lineLimit(1).minimumScaleFactor(0.6)
                } else {
                    HStack(spacing: 6) {
                        card(entry.toCollect, "À encaisser")
                        card(entry.overdue, "En retard", Color(red: 1, green: 0.54, blue: 0.5))
                    }
                }
            }
        }
        .padding(family == .systemSmall ? 2 : 4)
        .widgetBackground(night)
    }
}

extension View {
    /// Fond du widget : iOS 17 demande containerBackground, les versions précédentes un simple fond.
    @ViewBuilder func widgetBackground(_ color: Color) -> some View {
        if #available(iOSApplicationExtension 17.0, *) {
            self.containerBackground(color, for: .widget)
        } else {
            self.padding(12).background(color)
        }
    }
}

@main
struct YomaraWidget: Widget {
    let kind = "YomaraWidget"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: YomaraProvider()) { entry in
            YomaraWidgetView(entry: entry)
        }
        .configurationDisplayName("Yomara ERP")
        .description("Ventes du jour, reste à encaisser et factures en retard.")
        .supportedFamilies([.systemSmall, .systemMedium])
    }
}
