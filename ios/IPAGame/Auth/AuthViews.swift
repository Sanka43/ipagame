import SwiftUI

private struct AuthCard<Content: View>: View {
    let title: String
    let subtitle: String
    @ViewBuilder var content: Content

    var body: some View {
        ScrollView {
            VStack(spacing: 18) {
                Image(systemName: "gamecontroller.fill")
                    .font(.system(size: 54))
                    .foregroundColor(.accentColor)
                    .padding(.top, 40)
                Text(title).font(.largeTitle.bold())
                Text(subtitle)
                    .font(.subheadline)
                    .foregroundColor(.secondary)
                    .multilineTextAlignment(.center)
                content
            }
            .padding(24)
            .frame(maxWidth: 460)
            .frame(maxWidth: .infinity)
        }
    }
}

private struct PrimaryButton: View {
    let title: String
    let busy: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack {
                if busy { ProgressView().tint(.white) }
                Text(title).bold()
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 14)
            .background(Color.accentColor)
            .foregroundColor(.white)
            .clipShape(RoundedRectangle(cornerRadius: 14))
        }
        .disabled(busy)
    }
}

private struct ErrorText: View {
    let text: String?
    var body: some View {
        if let text, !text.isEmpty {
            Text(text).font(.footnote).foregroundColor(.red).multilineTextAlignment(.center)
        }
    }
}

struct LoginView: View {
    @EnvironmentObject var auth: AuthStore
    @State private var login = ""
    @State private var password = ""
    @State private var busy = false
    @State private var error: String?
    @State private var showRegister = false
    @State private var showForgot = false

    var body: some View {
        NavigationStack {
            AuthCard(title: "IPA Game", subtitle: "Sign in to browse games and apps.") {
                VStack(spacing: 12) {
                    TextField("Username or email", text: $login)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .textContentType(.username)
                        .padding(14)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                    SecureField("Password", text: $password)
                        .textContentType(.password)
                        .padding(14)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                    ErrorText(text: error)
                    PrimaryButton(title: "Sign in", busy: busy) { Task { await submit() } }
                    Button("Forgot password?") { showForgot = true }.font(.footnote)
                    Button("Create an account") { showRegister = true }.font(.subheadline.bold())
                }
            }
            .sheet(isPresented: $showRegister) { RegisterView() }
            .sheet(isPresented: $showForgot) { ForgotPasswordView() }
        }
    }

    private func submit() async {
        error = nil
        busy = true
        defer { busy = false }
        do {
            try await auth.login(login: login.trimmingCharacters(in: .whitespaces), password: password)
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct RegisterView: View {
    @EnvironmentObject var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    @State private var username = ""
    @State private var email = ""
    @State private var password = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            AuthCard(title: "Create account", subtitle: "We'll email you a 6-digit code to confirm your address.") {
                VStack(spacing: 12) {
                    Group {
                        TextField("Username (letters, numbers, _)", text: $username)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                        TextField("Email", text: $email)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .keyboardType(.emailAddress)
                        SecureField("Password (min 8 characters)", text: $password)
                    }
                    .padding(14)
                    .background(Color(.secondarySystemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                    ErrorText(text: error)
                    PrimaryButton(title: "Sign up", busy: busy) { Task { await submit() } }
                }
            }
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Close") { dismiss() } }
            }
        }
    }

    private func submit() async {
        error = nil
        busy = true
        defer { busy = false }
        do {
            try await auth.register(username: username.trimmingCharacters(in: .whitespaces),
                                    email: email.trimmingCharacters(in: .whitespaces),
                                    password: password)
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct VerifyCodeView: View {
    @EnvironmentObject var auth: AuthStore
    @State private var code = ""
    @State private var busy = false
    @State private var error: String?
    @State private var info: String?

    var body: some View {
        AuthCard(title: "Check your email",
                 subtitle: "We sent a 6-digit code to \(auth.pendingEmail ?? "your email").") {
            VStack(spacing: 12) {
                TextField("123456", text: $code)
                    .keyboardType(.numberPad)
                    .multilineTextAlignment(.center)
                    .font(.title2.monospacedDigit())
                    .padding(14)
                    .background(Color(.secondarySystemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                ErrorText(text: error)
                if let info { Text(info).font(.footnote).foregroundColor(.green) }
                PrimaryButton(title: "Verify", busy: busy) { Task { await submit() } }
                Button("Send a new code") { Task { await resend() } }.font(.footnote)
                Button("Back to sign in") { auth.cancelVerification() }.font(.footnote)
            }
        }
    }

    private func submit() async {
        error = nil
        busy = true
        defer { busy = false }
        do { try await auth.verify(code: code) } catch { self.error = error.localizedDescription }
    }

    private func resend() async {
        error = nil
        info = nil
        do {
            try await auth.resendCode()
            info = "A new code is on its way."
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct ForgotPasswordView: View {
    @EnvironmentObject var auth: AuthStore
    @Environment(\.dismiss) private var dismiss
    @State private var email = ""
    @State private var code = ""
    @State private var password = ""
    @State private var codeSent = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            AuthCard(title: "Reset password",
                     subtitle: codeSent ? "Enter the code we emailed and a new password."
                                        : "Enter your account email to get a reset code.") {
                VStack(spacing: 12) {
                    if !codeSent {
                        TextField("Email", text: $email)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .keyboardType(.emailAddress)
                            .padding(14)
                            .background(Color(.secondarySystemBackground))
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                        ErrorText(text: error)
                        PrimaryButton(title: "Send code", busy: busy) { Task { await sendCode() } }
                    } else {
                        Group {
                            TextField("6-digit code", text: $code).keyboardType(.numberPad)
                            SecureField("New password (min 8 characters)", text: $password)
                        }
                        .padding(14)
                        .background(Color(.secondarySystemBackground))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                        ErrorText(text: error)
                        PrimaryButton(title: "Reset password", busy: busy) { Task { await reset() } }
                    }
                }
            }
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Close") { dismiss() } }
            }
        }
    }

    private func sendCode() async {
        error = nil
        busy = true
        defer { busy = false }
        do {
            try await auth.forgotPassword(email: email.trimmingCharacters(in: .whitespaces))
            codeSent = true
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func reset() async {
        error = nil
        busy = true
        defer { busy = false }
        do {
            try await auth.resetPassword(code: code, password: password)
            dismiss()
        } catch {
            self.error = error.localizedDescription
        }
    }
}
