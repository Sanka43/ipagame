import SwiftUI

extension Color {
    init?(hex: String?) {
        guard var s = hex?.trimmingCharacters(in: .whitespaces), !s.isEmpty else { return nil }
        if s.hasPrefix("#") { s.removeFirst() }
        if s.count == 3 { s = s.map { "\($0)\($0)" }.joined() }
        guard s.count == 6, let v = UInt32(s, radix: 16) else { return nil }
        self.init(red: Double((v >> 16) & 0xFF) / 255,
                  green: Double((v >> 8) & 0xFF) / 255,
                  blue: Double(v & 0xFF) / 255)
    }
}

/// Icon / screenshot loader with a placeholder.
struct RemoteImage: View {
    let urlString: String?
    var cornerRadius: CGFloat = 0

    var body: some View {
        AsyncImage(url: URL(string: urlString ?? "")) { phase in
            switch phase {
            case .success(let image):
                image.resizable().scaledToFill()
            case .failure:
                Color(.secondarySystemBackground)
                    .overlay(Image(systemName: "photo").foregroundColor(.secondary))
            default:
                Color(.secondarySystemBackground).overlay(ProgressView())
            }
        }
        .clipShape(RoundedRectangle(cornerRadius: cornerRadius, style: .continuous))
    }
}

/// App Store screenshots can be asked for at a smaller size by rewriting the file suffix.
func resizedScreenshot(_ url: String) -> String {
    guard url.contains("mzstatic.com"),
          let range = url.range(of: #"/\d+x\d+[a-z]*\.(jpg|png|webp)$"#, options: .regularExpression) else { return url }
    return url.replacingCharacters(in: range, with: "/460x0w.jpg")
}

struct ItemRow: View {
    let item: Item

    var body: some View {
        HStack(spacing: 14) {
            RemoteImage(urlString: item.icon, cornerRadius: 14)
                .frame(width: 64, height: 64)
            VStack(alignment: .leading, spacing: 3) {
                Text(item.name).font(.headline).lineLimit(1)
                if let dev = item.developer, !dev.isEmpty {
                    Text(dev).font(.caption).foregroundColor(.secondary).lineLimit(1)
                }
                if let short = item.shortDescription, !short.isEmpty {
                    Text(short).font(.caption).foregroundColor(.secondary).lineLimit(2)
                }
            }
            Spacer(minLength: 0)
            if item.ipa == true {
                Text("IPA")
                    .font(.caption2.bold())
                    .padding(.horizontal, 7).padding(.vertical, 3)
                    .background(Color.accentColor.opacity(0.15))
                    .foregroundColor(.accentColor)
                    .clipShape(Capsule())
            }
        }
        .contentShape(Rectangle())
    }
}

struct ItemCard: View {
    let item: Item

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            RemoteImage(urlString: item.icon, cornerRadius: 20)
                .frame(width: 110, height: 110)
            Text(item.name).font(.footnote.bold()).lineLimit(2)
                .frame(width: 110, alignment: .leading)
            if let cat = item.category, !cat.isEmpty {
                Text(cat).font(.caption2).foregroundColor(.secondary).lineLimit(1)
                    .frame(width: 110, alignment: .leading)
            }
        }
    }
}

/// Renders the site's description syntax: `#`/`##`/`###` headings, `*bold*`, `_italic_`.
struct MarkdownLite: View {
    let text: String

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ForEach(Array(lines().enumerated()), id: \.offset) { _, line in
                lineView(line)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func lines() -> [String] {
        text.replacingOccurrences(of: "\r\n", with: "\n").components(separatedBy: "\n")
    }

    @ViewBuilder
    private func lineView(_ raw: String) -> some View {
        let line = raw.trimmingCharacters(in: .whitespaces)
        if line.isEmpty {
            Color.clear.frame(height: 2)
        } else if line.hasPrefix("###") {
            Text(inline(String(line.dropFirst(3)).trimmingCharacters(in: .whitespaces))).font(.subheadline.bold())
        } else if line.hasPrefix("##") {
            Text(inline(String(line.dropFirst(2)).trimmingCharacters(in: .whitespaces))).font(.headline)
        } else if line.hasPrefix("#") {
            Text(inline(String(line.dropFirst(1)).trimmingCharacters(in: .whitespaces))).font(.title3.bold())
        } else {
            Text(inline(line)).font(.body)
        }
    }

    private func inline(_ s: String) -> AttributedString {
        // The site uses *bold* and _italic_; Markdown wants **bold** and _italic_.
        let md = s.replacingOccurrences(of: #"\*(.+?)\*"#, with: "**$1**", options: .regularExpression)
        let options = AttributedString.MarkdownParsingOptions(interpretedSyntax: .inlineOnlyPreservingWhitespace)
        if let attr = try? AttributedString(markdown: md, options: options) { return attr }
        return AttributedString(s)
    }
}

struct ErrorBanner: View {
    let message: String
    var retry: (() -> Void)?

    var body: some View {
        VStack(spacing: 10) {
            Image(systemName: "exclamationmark.triangle").font(.largeTitle).foregroundColor(.orange)
            Text(message).multilineTextAlignment(.center).foregroundColor(.secondary)
            if let retry { Button("Try again", action: retry).buttonStyle(.borderedProminent) }
        }
        .padding(24)
        .frame(maxWidth: .infinity)
    }
}
