import SwiftUI

@main
struct IPAGameApp: App {
    @StateObject private var auth = AuthStore()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(auth)
                .task { await auth.bootstrap() }
        }
    }
}

struct RootView: View {
    @EnvironmentObject var auth: AuthStore

    var body: some View {
        if auth.loading {
            ProgressView("Loading…")
        } else if auth.user != nil {
            MainTabs()
        } else if auth.pendingEmail != nil {
            VerifyCodeView()
        } else {
            LoginView()
        }
    }
}

struct MainTabs: View {
    var body: some View {
        TabView {
            HomeView()
                .tabItem { Label("Store", systemImage: "square.grid.2x2.fill") }
            NavigationStack { CatalogListView(title: "Games", type: "game", showSourceFilter: true) }
                .tabItem { Label("Games", systemImage: "gamecontroller.fill") }
            NavigationStack { CatalogListView(title: "Apps", type: "app") }
                .tabItem { Label("Apps", systemImage: "app.fill") }
            SearchView()
                .tabItem { Label("Search", systemImage: "magnifyingglass") }
            ProfileView()
                .tabItem { Label("Account", systemImage: "person.crop.circle") }
        }
    }
}
