import SwiftUI

@MainActor
final class AuthStore: ObservableObject {
    @Published var user: User?
    /// Set while an account is waiting for its emailed 6-digit code.
    @Published var pendingEmail: String?
    @Published var loading = true

    private var observer: NSObjectProtocol?

    init() {
        observer = NotificationCenter.default.addObserver(forName: .sessionExpired, object: nil, queue: .main) { [weak self] _ in
            Task { @MainActor in
                self?.user = nil
            }
        }
    }

    deinit {
        if let observer { NotificationCenter.default.removeObserver(observer) }
    }

    func bootstrap() async {
        defer { loading = false }
        do {
            let me: MeResponse = try await APIClient.shared.get("me")
            user = me.user
            pendingEmail = me.user == nil ? me.pending?.email : nil
        } catch {
            user = nil
        }
    }

    private func apply(_ r: AuthResponse) {
        if let u = r.user {
            user = u
            pendingEmail = nil
        } else if r.verify == true {
            pendingEmail = r.email
        }
    }

    func login(login: String, password: String) async throws {
        let r: AuthResponse = try await APIClient.shared.post("user_login", body: ["login": login, "password": password])
        apply(r)
    }

    func register(username: String, email: String, password: String) async throws {
        let r: AuthResponse = try await APIClient.shared.post(
            "register", body: ["username": username, "email": email, "password": password])
        apply(r)
    }

    func verify(code: String) async throws {
        let r: AuthResponse = try await APIClient.shared.post("verify_email", body: ["code": code])
        apply(r)
    }

    func resendCode() async throws {
        let _: OKResponse = try await APIClient.shared.post("resend_code")
    }

    func forgotPassword(email: String) async throws {
        let _: OKResponse = try await APIClient.shared.post("forgot_password", body: ["email": email])
    }

    func resetPassword(code: String, password: String) async throws {
        let r: AuthResponse = try await APIClient.shared.post("reset_password", body: ["code": code, "password": password])
        apply(r)
    }

    func logout() async {
        _ = try? await (APIClient.shared.post("user_logout") as OKResponse)
        user = nil
        pendingEmail = nil
    }

    func cancelVerification() {
        pendingEmail = nil
    }

    func refreshUser() async {
        if let me: MeResponse = try? await APIClient.shared.get("me") {
            user = me.user
        }
    }
}
