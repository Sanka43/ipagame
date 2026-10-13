import SwiftUI

struct GameDetailView: View {
    let slug: String
    let preview: Item?

    @Environment(\.openURL) private var openURL
    @State private var item: Item?
    @State private var error: String?
    @State private var copied: String?

    var body: some View {
        let shown = item ?? preview
        ScrollView {
            if let shown {
                VStack(alignment: .leading, spacing: 20) {
                    header(shown)
                    if let item {
                        details(item)
                    } else if let error {
                        ErrorBanner(message: error) { Task { await load() } }
                    } else {
                        ProgressView().frame(maxWidth: .infinity).padding(.top, 30)
                    }
                }
                .padding()
            } else if let error {
                ErrorBanner(message: error) { Task { await load() } }
            } else {
                ProgressView().padding(.top, 60)
            }
        }
        .navigationTitle(shown?.name ?? "")
        .navigationBarTitleDisplayMode(.inline)
        .task { if item == nil { await load() } }
    }

    private func header(_ it: Item) -> some View {
        HStack(alignment: .top, spacing: 16) {
            RemoteImage(urlString: it.icon, cornerRadius: 26).frame(width: 100, height: 100)
            VStack(alignment: .leading, spacing: 4) {
                Text(it.name).font(.title3.bold())
                if let dev = it.developer, !dev.isEmpty { Text(dev).font(.subheadline).foregroundColor(.secondary) }
                if let cat = it.category, !cat.isEmpty { Text(cat).font(.caption).foregroundColor(.accentColor) }
                if let r = it.rating, let v = r.value, v > 0 {
                    Label(String(format: "%.1f", v) + ((r.count ?? 0) > 0 ? " (\(r.count ?? 0))" : ""),
                          systemImage: "star.fill")
                        .font(.caption).foregroundColor(.orange)
                }
            }
        }
    }

    @ViewBuilder
    private func details(_ it: Item) -> some View {
        infoGrid(it)

        let shots = (it.screenshots ?? []).isEmpty ? (it.ipadScreenshots ?? []) : (it.screenshots ?? [])
        if !shots.isEmpty {
            ScrollView(.horizontal, showsIndicators: false) {
                LazyHStack(spacing: 12) {
                    ForEach(shots, id: \.self) { s in
                        RemoteImage(urlString: resizedScreenshot(s), cornerRadius: 14)
                            .frame(width: 180, height: 320)
                    }
                }
            }
        }

        if let d = it.desc, !d.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Text("About").font(.title3.bold())
                MarkdownLite(text: d)
            }
        }

        let versions = it.versions ?? []
        if !versions.isEmpty {
            VStack(alignment: .leading, spacing: 12) {
                Text("Versions").font(.title3.bold())
                ForEach(versions) { v in versionRow(v, item: it) }
            }
        }
        if let copied { Text(copied).font(.footnote).foregroundColor(.green) }
    }

    private func infoGrid(_ it: Item) -> some View {
        var parts: [(String, String)] = []
        if let v = it.latestVersion, !v.isEmpty { parts.append(("Version", v)) }
        if let m = it.minIos, !m.isEmpty { parts.append(("Requires", "iOS \(m)+")) }
        if let c = it.contentRating, !c.isEmpty { parts.append(("Age", c)) }
        if let p = it.price, p > 0 { parts.append(("Price", String(format: "$%.2f", p))) }
        if let langs = it.languages, !langs.isEmpty { parts.append(("Languages", langs.prefix(3).joined(separator: ", "))) }
        return ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 22) {
                ForEach(Array(parts.enumerated()), id: \.offset) { _, p in
                    VStack(spacing: 2) {
                        Text(p.0.uppercased()).font(.caption2).foregroundColor(.secondary)
                        Text(p.1).font(.subheadline.bold())
                    }
                }
            }
        }
    }

    private func versionRow(_ v: Version, item it: Item) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(v.version).font(.headline)
                    Text(meta(v)).font(.caption).foregroundColor(.secondary)
                }
                Spacer()
                Menu {
                    Button("Copy link") {
                        UIPasteboard.general.string = v.downloadUrl
                        copied = "Link copied. Paste it in SideStore or Safari."
                    }
                } label: {
                    Image(systemName: "ellipsis.circle").font(.title3)
                }
                Button(isStoreLink(v.downloadUrl) ? "Open" : "Download") {
                    download(v, item: it)
                }
                .buttonStyle(.borderedProminent)
                .clipShape(Capsule())
            }
            if let log = v.changelog, !log.isEmpty {
                Text(log).font(.footnote).foregroundColor(.secondary)
            }
        }
        .padding(14)
        .background(Color(.secondarySystemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
    }

    private func meta(_ v: Version) -> String {
        var bits: [String] = []
        if let d = v.releaseDate, !d.isEmpty { bits.append(String(d.prefix(10))) }
        if let s = v.sizeMb, s > 0 { bits.append(String(format: "%.0f MB", s)) }
        return bits.joined(separator: " · ")
    }

    private func isStoreLink(_ url: String) -> Bool {
        url.contains("apps.apple.com")
    }

    private func download(_ v: Version, item it: Item) {
        guard let url = URL(string: v.downloadUrl) else { return }
        Task {
            _ = try? await (APIClient.shared.post("track_download", body: ["slug": it.slug, "version": v.version]) as OKResponse)
        }
        openURL(url)
    }

    private func load() async {
        error = nil
        do {
            let r: ItemResponse = try await APIClient.shared.get("get", query: ["slug": slug])
            item = r.item
        } catch {
            self.error = error.localizedDescription
        }
    }
}
