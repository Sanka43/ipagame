import SwiftUI

struct SearchView: View {
    @StateObject private var model = CatalogViewModel()
    @State private var categories: [String] = []
    @State private var meta: [String: CategoryMeta] = [:]
    @State private var query = ""
    @State private var searchTask: Task<Void, Never>?

    private let columns = [GridItem(.adaptive(minimum: 150), spacing: 12)]

    var body: some View {
        NavigationStack {
            Group {
                if query.trimmingCharacters(in: .whitespaces).isEmpty {
                    ScrollView {
                        LazyVGrid(columns: columns, spacing: 12) {
                            ForEach(categories, id: \.self) { cat in
                                NavigationLink {
                                    CatalogListView(title: meta[cat]?.label ?? cat, category: cat)
                                } label: {
                                    categoryCard(cat)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding()
                    }
                } else {
                    List {
                        ForEach(model.items) { item in
                            NavigationLink {
                                GameDetailView(slug: item.slug, preview: item)
                            } label: {
                                ItemRow(item: item)
                            }
                            .onAppear {
                                if item.id == model.items.last?.id { Task { await model.loadMore() } }
                            }
                        }
                        if model.loading { HStack { Spacer(); ProgressView(); Spacer() } }
                        if let err = model.error { Text(err).foregroundColor(.red) }
                        if !model.loading && model.error == nil && model.items.isEmpty {
                            Text("No results").foregroundColor(.secondary).frame(maxWidth: .infinity)
                        }
                    }
                    .listStyle(.plain)
                }
            }
            .navigationTitle("Search")
            .searchable(text: $query, prompt: "Games and apps")
            .onChange(of: query) { newValue in
                searchTask?.cancel()
                let q = newValue.trimmingCharacters(in: .whitespaces)
                guard !q.isEmpty else { return }
                searchTask = Task {
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    if Task.isCancelled { return }
                    model.query = q
                    await model.reload()
                }
            }
            .task { if categories.isEmpty { await loadCategories() } }
        }
    }

    private func categoryCard(_ cat: String) -> some View {
        let m = meta[cat]
        let c1 = Color(hex: m?.c1) ?? .purple
        let c2 = Color(hex: m?.c2) ?? .indigo
        return HStack {
            Text(m?.emoji ?? "🎮").font(.title2)
            Text(m?.label ?? cat).font(.subheadline.bold()).foregroundColor(.white).lineLimit(2)
            Spacer(minLength: 0)
        }
        .padding(14)
        .background(LinearGradient(colors: [c1, c2], startPoint: .topLeading, endPoint: .bottomTrailing))
        .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
    }

    private func loadCategories() async {
        do {
            let r: ListResponse = try await APIClient.shared.get("list", query: ["page": "1", "per_page": "1"])
            meta = r.categoryMeta ?? [:]
            let counts = r.facets?.categoryCounts ?? [:]
            let cats = r.facets?.categories ?? []
            categories = cats.sorted { (counts[$0] ?? 0) > (counts[$1] ?? 0) }
        } catch {
            categories = []
        }
    }
}
