import SwiftUI

struct HomeView: View {
    @State private var featured: [Item] = []
    @State private var shelves: [Shelf] = []
    @State private var meta: [String: CategoryMeta] = [:]
    @State private var loading = true
    @State private var error: String?

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 24) {
                    if let error {
                        ErrorBanner(message: error) { Task { await load() } }
                    }
                    if !featured.isEmpty {
                        section(title: "Featured", items: featured)
                    }
                    ForEach(shelves) { shelf in
                        VStack(alignment: .leading, spacing: 10) {
                            NavigationLink {
                                CatalogListView(title: label(shelf.category), category: shelf.category)
                            } label: {
                                HStack {
                                    Text("\(meta[shelf.category]?.emoji ?? "") \(label(shelf.category))")
                                        .font(.title3.bold())
                                    Spacer()
                                    Image(systemName: "chevron.right").foregroundColor(.secondary)
                                }
                                .padding(.horizontal)
                            }
                            .buttonStyle(.plain)
                            row(items: shelf.items)
                        }
                    }
                    if loading && shelves.isEmpty { ProgressView().frame(maxWidth: .infinity).padding(.top, 60) }
                }
                .padding(.vertical)
            }
            .navigationTitle("Store")
            .refreshable { await load() }
            .task { if shelves.isEmpty { await load() } }
        }
    }

    private func label(_ category: String) -> String {
        meta[category]?.label ?? category
    }

    private func section(title: String, items: [Item]) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(title).font(.title3.bold()).padding(.horizontal)
            row(items: items)
        }
    }

    private func row(items: [Item]) -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            LazyHStack(alignment: .top, spacing: 14) {
                ForEach(items) { item in
                    NavigationLink {
                        GameDetailView(slug: item.slug, preview: item)
                    } label: {
                        ItemCard(item: item)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.horizontal)
        }
    }

    private func load() async {
        loading = true
        error = nil
        defer { loading = false }
        do {
            let r: ListResponse = try await APIClient.shared.get(
                "list", query: ["page": "1", "per_page": "1", "shelves": "12"])
            featured = Array((r.featured ?? []).prefix(20))
            shelves = r.shelves ?? []
            meta = r.categoryMeta ?? [:]
        } catch {
            self.error = error.localizedDescription
        }
    }
}
