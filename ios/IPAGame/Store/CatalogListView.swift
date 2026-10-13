import SwiftUI

/// Infinite-scrolling list of items. Used by the Games, Apps and Category screens.
struct CatalogListView: View {
    let title: String
    var type: String = ""
    var category: String = ""
    var showSourceFilter = false

    @StateObject private var model = CatalogViewModel()
    @State private var source = ""
    @State private var query = ""

    var body: some View {
        List {
            if showSourceFilter {
                Picker("Source", selection: $source) {
                    Text("All").tag("")
                    Text("IPA").tag("ipa")
                    Text("App Store").tag("store")
                }
                .pickerStyle(.segmented)
                .listRowSeparator(.hidden)
            }
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
            if model.loading {
                HStack { Spacer(); ProgressView(); Spacer() }.listRowSeparator(.hidden)
            }
            if let err = model.error, model.items.isEmpty {
                ErrorBanner(message: err) { Task { await model.reload() } }
                    .listRowSeparator(.hidden)
            }
            if !model.loading && model.error == nil && model.items.isEmpty {
                Text("Nothing here yet.").foregroundColor(.secondary).frame(maxWidth: .infinity)
                    .listRowSeparator(.hidden)
            }
        }
        .listStyle(.plain)
        .navigationTitle(title)
        .searchable(text: $query, prompt: "Search \(title)")
        .onSubmit(of: .search) { Task { await apply() } }
        .onChange(of: query) { newValue in
            if newValue.isEmpty { Task { await apply() } }
        }
        .onChange(of: source) { _ in Task { await apply() } }
        .refreshable { await model.reload() }
        .task {
            if model.items.isEmpty && model.error == nil { await apply() }
        }
    }

    private func apply() async {
        model.type = type
        model.category = category
        model.source = source
        model.query = query.trimmingCharacters(in: .whitespaces)
        await model.reload()
    }
}
