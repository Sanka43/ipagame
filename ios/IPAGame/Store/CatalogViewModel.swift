import SwiftUI

/// Paginated list of catalog items for one filter combination.
@MainActor
final class CatalogViewModel: ObservableObject {
    @Published var items: [Item] = []
    @Published var loading = false
    @Published var error: String?
    @Published var total = 0
    @Published var facets: Facets?
    @Published var categoryMeta: [String: CategoryMeta] = [:]

    var type = ""
    var category = ""
    var source = ""
    var query = ""

    private var page = 0
    private var pages = 1
    private var generation = 0

    var canLoadMore: Bool { page < pages }

    func reload() async {
        generation += 1
        loading = false
        page = 0
        pages = 1
        items = []
        error = nil
        await loadMore()
    }

    func loadMore() async {
        guard !loading, canLoadMore || page == 0 else { return }
        loading = true
        let gen = generation
        defer { if gen == generation { loading = false } }
        var q: [String: String] = ["page": String(page + 1), "per_page": "30"]
        if !type.isEmpty { q["type"] = type }
        if !category.isEmpty { q["category"] = category }
        if !source.isEmpty { q["source"] = source }
        if !query.isEmpty { q["q"] = query }
        do {
            let r: ListResponse = try await APIClient.shared.get("list", query: q)
            guard gen == generation else { return }
            page = r.page ?? (page + 1)
            pages = r.pages ?? 1
            total = r.total ?? r.items.count
            items.append(contentsOf: r.items)
            if let f = r.facets { facets = f }
            if let m = r.categoryMeta { categoryMeta = m }
            error = nil
        } catch {
            guard gen == generation else { return }
            self.error = error.localizedDescription
        }
    }
}
