import Foundation

struct User: Codable, Identifiable {
    let id: Int
    let username: String
    let email: String
    let avatar: String?
    let customAvatar: Bool?
    let google: Bool?
    let notifyNewApps: Bool?

    enum CodingKeys: String, CodingKey {
        case id, username, email, avatar, google
        case customAvatar = "custom_avatar"
        case notifyNewApps = "notify_new_apps"
    }
}

struct Featured: Codable {
    let popular: Bool?
    let editorsChoice: Bool?

    enum CodingKeys: String, CodingKey {
        case popular
        case editorsChoice = "editors_choice"
    }
}

struct Rating: Codable {
    let value: Double?
    let count: Int?
}

struct Version: Codable, Identifiable {
    let version: String
    let releaseDate: String?
    let sizeMb: Double?
    let changelog: String?
    let downloadUrl: String

    var id: String { version + "|" + downloadUrl }

    enum CodingKeys: String, CodingKey {
        case version, changelog
        case releaseDate = "release_date"
        case sizeMb = "size_mb"
        case downloadUrl = "download_url"
    }
}

/// One catalog entry. List responses only fill the first group of fields; `get` fills everything.
struct Item: Codable, Identifiable {
    let id: Int
    let slug: String
    let type: String?
    let name: String
    let developer: String?
    let category: String?
    let icon: String?
    let shortDescription: String?
    let latestVersion: String?
    let ipa: Bool?
    let featured: Featured?

    let bundleId: String?
    let appStoreUrl: String?
    let price: Double?
    let screenshots: [String]?
    let ipadScreenshots: [String]?
    let minIos: String?
    let compatible: [String]?
    let languages: [String]?
    let contentRating: String?
    let rating: Rating?
    let desc: String?
    let tags: [String]?
    let versions: [Version]?

    enum CodingKeys: String, CodingKey {
        case id, slug, type, name, developer, category, icon, ipa, featured
        case price, screenshots, compatible, languages, rating, tags, versions
        case shortDescription = "short_description"
        case latestVersion = "latest_version"
        case bundleId = "bundle_id"
        case appStoreUrl = "app_store_url"
        case ipadScreenshots = "ipad_screenshots"
        case minIos = "min_ios"
        case contentRating = "content_rating"
        case desc = "description"
    }
}

struct CategoryMeta: Codable {
    let label: String?
    let emoji: String?
    let c1: String?
    let c2: String?
}

struct Facets: Codable {
    let types: [String]?
    let categories: [String]?
    let categoryCounts: [String: Int]?

    enum CodingKeys: String, CodingKey {
        case types, categories
        case categoryCounts = "category_counts"
    }
}

struct Shelf: Codable, Identifiable {
    let category: String
    let count: Int
    let items: [Item]
    var id: String { category }
}

struct ListResponse: Codable {
    let items: [Item]
    let total: Int?
    let page: Int?
    let pages: Int?
    let facets: Facets?
    let featured: [Item]?
    let shelves: [Shelf]?
    let categoryMeta: [String: CategoryMeta]?

    enum CodingKeys: String, CodingKey {
        case items, total, page, pages, facets, featured, shelves
        case categoryMeta = "category_meta"
    }
}

struct ItemResponse: Codable {
    let item: Item
}

struct MeResponse: Codable {
    struct Pending: Codable {
        let email: String
    }
    let user: User?
    let pending: Pending?
}

/// Reply of login / register / verify / reset: either a signed-in user or a "verify your email" step.
struct AuthResponse: Codable {
    let user: User?
    let verify: Bool?
    let email: String?
    let error: String?
}

struct OKResponse: Codable {
    let ok: Bool?
}

struct ChatMessage: Codable, Identifiable {
    let id: Int
    let from: String
    let body: String
    let at: String?
}

struct ChatResponse: Codable {
    let messages: [ChatMessage]
}

struct ChatSendResponse: Codable {
    let message: ChatMessage
}
