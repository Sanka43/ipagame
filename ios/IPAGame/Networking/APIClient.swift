import Foundation

struct APIError: LocalizedError {
    let message: String
    let status: Int
    var errorDescription: String? { message }
}

extension Notification.Name {
    /// Posted when the server answers 401/403 "login required" so the UI can drop back to the login screen.
    static let sessionExpired = Notification.Name("ipagame.sessionExpired")
}

final class APIClient {
    static let shared = APIClient()

    static let baseURL = URL(string: "https://app.ipagame.store/api.php")!

    private let session: URLSession
    private let decoder = JSONDecoder()

    private init() {
        let config = URLSessionConfiguration.default
        config.httpCookieStorage = HTTPCookieStorage.shared
        config.httpCookieAcceptPolicy = .always
        config.httpShouldSetCookies = true
        config.timeoutIntervalForRequest = 30
        session = URLSession(configuration: config)
    }

    private func url(_ action: String, _ query: [String: String]) -> URL {
        var comps = URLComponents(url: APIClient.baseURL, resolvingAgainstBaseURL: false)!
        var items = [URLQueryItem(name: "action", value: action)]
        for (k, v) in query.sorted(by: { $0.key < $1.key }) {
            items.append(URLQueryItem(name: k, value: v))
        }
        comps.queryItems = items
        return comps.url!
    }

    func get<T: Decodable>(_ action: String, query: [String: String] = [:]) async throws -> T {
        var req = URLRequest(url: url(action, query))
        req.httpMethod = "GET"
        return try await send(req, quiet: action == "me")
    }

    func post<T: Decodable>(_ action: String, body: [String: Any] = [:]) async throws -> T {
        var req = URLRequest(url: url(action, [:]))
        req.httpMethod = "POST"
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.setValue("ipagame-ios", forHTTPHeaderField: "X-Requested-With")
        req.httpBody = try JSONSerialization.data(withJSONObject: body)
        return try await send(req, quiet: false)
    }

    /// Multipart upload used for the avatar picture.
    func upload<T: Decodable>(_ action: String, field: String, data: Data, filename: String, mime: String) async throws -> T {
        let boundary = "Boundary-" + UUID().uuidString
        var req = URLRequest(url: url(action, [:]))
        req.httpMethod = "POST"
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        req.setValue("ipagame-ios", forHTTPHeaderField: "X-Requested-With")
        var body = Data()
        body.append(Data("--\(boundary)\r\n".utf8))
        body.append(Data("Content-Disposition: form-data; name=\"\(field)\"; filename=\"\(filename)\"\r\n".utf8))
        body.append(Data("Content-Type: \(mime)\r\n\r\n".utf8))
        body.append(data)
        body.append(Data("\r\n--\(boundary)--\r\n".utf8))
        req.httpBody = body
        return try await send(req, quiet: false)
    }

    private func send<T: Decodable>(_ req: URLRequest, quiet: Bool) async throws -> T {
        let (data, response) = try await session.data(for: req)
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        if !(200..<300).contains(status) {
            var message = "Something went wrong (\(status))"
            if let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
               let err = obj["error"] as? String {
                message = err
            }
            if (status == 401 || status == 403) && !quiet {
                NotificationCenter.default.post(name: .sessionExpired, object: nil)
            }
            throw APIError(message: message, status: status)
        }
        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw APIError(message: "Unexpected reply from the server.", status: status)
        }
    }
}
