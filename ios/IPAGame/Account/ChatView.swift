import SwiftUI

struct ChatView: View {
    @State private var messages: [ChatMessage] = []
    @State private var text = ""
    @State private var error: String?
    @State private var sending = false

    var body: some View {
        VStack(spacing: 0) {
            ScrollViewReader { proxy in
                ScrollView {
                    LazyVStack(spacing: 8) {
                        if messages.isEmpty {
                            Text("Ask us anything. We'll reply here.")
                                .foregroundColor(.secondary).padding(.top, 40)
                        }
                        ForEach(messages) { m in
                            HStack {
                                if m.from == "user" { Spacer(minLength: 48) }
                                Text(m.body)
                                    .padding(.horizontal, 14).padding(.vertical, 9)
                                    .background(m.from == "user" ? Color.accentColor : Color(.secondarySystemBackground))
                                    .foregroundColor(m.from == "user" ? .white : .primary)
                                    .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                                if m.from != "user" { Spacer(minLength: 48) }
                            }
                            .id(m.id)
                        }
                    }
                    .padding()
                }
                .onChange(of: messages.count) { _ in
                    if let last = messages.last { withAnimation { proxy.scrollTo(last.id, anchor: .bottom) } }
                }
            }
            if let error { Text(error).font(.footnote).foregroundColor(.red).padding(.horizontal) }
            HStack(spacing: 10) {
                TextField("Message", text: $text, axis: .vertical)
                    .lineLimit(1...4)
                    .padding(10)
                    .background(Color(.secondarySystemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                Button {
                    Task { await send() }
                } label: {
                    Image(systemName: "arrow.up.circle.fill").font(.system(size: 32))
                }
                .disabled(sending || text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            }
            .padding(10)
        }
        .navigationTitle("Support")
        .navigationBarTitleDisplayMode(.inline)
        .task {
            // Poll for new replies while the screen is open; the task is cancelled when it closes.
            while !Task.isCancelled {
                await poll()
                try? await Task.sleep(nanoseconds: 4_000_000_000)
            }
        }
    }

    private func poll() async {
        let after = messages.last?.id ?? 0
        do {
            let r: ChatResponse = try await APIClient.shared.get("chat", query: ["after": String(after)])
            let known = Set(messages.map { $0.id })
            let fresh = r.messages.filter { !known.contains($0.id) }
            if !fresh.isEmpty { messages.append(contentsOf: fresh) }
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func send() async {
        let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty else { return }
        sending = true
        defer { sending = false }
        do {
            let r: ChatSendResponse = try await APIClient.shared.post("chat_send", body: ["body": body])
            text = ""
            if !messages.contains(where: { $0.id == r.message.id }) { messages.append(r.message) }
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }
}
