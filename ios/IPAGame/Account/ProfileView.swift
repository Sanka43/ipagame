import SwiftUI
import PhotosUI

struct ProfileView: View {
    @EnvironmentObject var auth: AuthStore
    @State private var pickerItem: PhotosPickerItem?
    @State private var notify = true
    @State private var busy = false
    @State private var error: String?
    @State private var confirmDelete = false
    @State private var deletePassword = ""

    var body: some View {
        NavigationStack {
            List {
                if let user = auth.user {
                    Section {
                        HStack(spacing: 16) {
                            RemoteImage(urlString: user.avatar, cornerRadius: 36).frame(width: 72, height: 72)
                                .clipShape(Circle())
                            VStack(alignment: .leading, spacing: 3) {
                                Text(user.username).font(.headline)
                                Text(user.email).font(.subheadline).foregroundColor(.secondary)
                            }
                        }
                        PhotosPicker(selection: $pickerItem, matching: .images) {
                            Label("Change picture", systemImage: "photo")
                        }
                        if user.customAvatar == true {
                            Button("Remove picture", role: .destructive) { Task { await removeAvatar() } }
                        }
                    }

                    Section {
                        Toggle("Email me about new apps", isOn: $notify)
                            .onChange(of: notify) { value in Task { await setNotify(value) } }
                        NavigationLink {
                            ChatView()
                        } label: {
                            Label("Support chat", systemImage: "bubble.left.and.bubble.right")
                        }
                        Link(destination: URL(string: "https://app.ipagame.store/install.html")!) {
                            Label("How to install IPAs", systemImage: "questionmark.circle")
                        }
                        Link(destination: URL(string: "https://app.ipagame.store/privacy.html")!) {
                            Label("Privacy policy", systemImage: "hand.raised")
                        }
                    }

                    if let error { Section { Text(error).foregroundColor(.red).font(.footnote) } }

                    Section {
                        Button("Sign out") { Task { await auth.logout() } }
                        if user.google != true {
                            Button("Delete account", role: .destructive) { confirmDelete = true }
                        }
                    }
                }
            }
            .navigationTitle("Account")
            .onAppear { notify = auth.user?.notifyNewApps ?? true }
            .onChange(of: pickerItem) { item in
                guard let item else { return }
                Task { await uploadAvatar(item) }
            }
            .alert("Delete account?", isPresented: $confirmDelete) {
                SecureField("Password", text: $deletePassword)
                Button("Delete", role: .destructive) { Task { await deleteAccount() } }
                Button("Cancel", role: .cancel) { deletePassword = "" }
            } message: {
                Text("This permanently deletes your account. Enter your password to confirm.")
            }
        }
    }

    private func setNotify(_ on: Bool) async {
        do {
            let _: OKResponse = try await APIClient.shared.post("set_notify", body: ["on": on])
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func uploadAvatar(_ item: PhotosPickerItem) async {
        defer { pickerItem = nil }
        do {
            guard let data = try await item.loadTransferable(type: Data.self),
                  let image = UIImage(data: data),
                  let jpeg = image.jpegData(compressionQuality: 0.85) else { return }
            let _: AuthResponse = try await APIClient.shared.upload(
                "upload_avatar", field: "avatar", data: jpeg, filename: "avatar.jpg", mime: "image/jpeg")
            await auth.refreshUser()
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func removeAvatar() async {
        do {
            let _: AuthResponse = try await APIClient.shared.post("remove_avatar")
            await auth.refreshUser()
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func deleteAccount() async {
        let pw = deletePassword
        deletePassword = ""
        do {
            let _: OKResponse = try await APIClient.shared.post("delete_account", body: ["password": pw])
            auth.user = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}
