import SwiftUI
import WebKit
import AuthenticationServices

/// The store is the website itself: this app is a full-screen window onto https://app.ipagame.store/,
/// so the UI is identical to the site (and to the old Web Clip profile).
let siteURL = URL(string: "https://app.ipagame.store/")!
private let siteHost = "app.ipagame.store"

/// Hosts that may load inside the app (Google sign-in). Everything else opens in Safari.
private func isInternal(_ host: String?) -> Bool {
    guard let h = host?.lowercased() else { return false }
    if h == siteHost || h.hasSuffix(".ipagame.store") { return true }
    return h == "accounts.google.com" || h.hasSuffix(".google.com") || h.hasSuffix(".gstatic.com")
}

@main
struct IPAGameApp: App {
    var body: some Scene {
        WindowGroup {
            WebContainer()
                .ignoresSafeArea()
                .background(Color(UIColor { $0.userInterfaceStyle == .dark ? UIColor.black : UIColor(red: 0.957, green: 0.961, blue: 0.969, alpha: 1) }))
        }
    }
}

struct WebContainer: UIViewRepresentable {
    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.websiteDataStore = .default()              // cookies persist, so sign-in is remembered
        config.allowsInlineMediaPlayback = true
        // Look like Safari so Google sign-in doesn't reject the embedded browser.
        config.applicationNameForUserAgent = "Version/17.0 Mobile/15E148 Safari/604.1 IPAGameApp"

        // App feel: no text-selection / long-press callouts or grey tap flashes on buttons and cards.
        let css = "*{-webkit-touch-callout:none;-webkit-tap-highlight-color:transparent}" +
                  "body{-webkit-user-select:none;user-select:none}input,textarea{-webkit-user-select:text;user-select:text}"
        let js = "var s=document.createElement('style');s.textContent='\(css)';(document.head||document.documentElement).appendChild(s);"
        config.userContentController.addUserScript(WKUserScript(source: js, injectionTime: .atDocumentStart, forMainFrameOnly: true))

        // "Continue with Google": Google refuses OAuth inside web views, so the page asks us to open it in a system sheet.
        config.userContentController.add(context.coordinator, name: "googleSignIn")

        let web = WKWebView(frame: .zero, configuration: config)
        web.allowsLinkPreview = false
        web.scrollView.pinchGestureRecognizer?.isEnabled = false
        web.scrollView.decelerationRate = .normal
        let bg = UIColor { $0.userInterfaceStyle == .dark ? UIColor.black : UIColor(red: 0.957, green: 0.961, blue: 0.969, alpha: 1) }
        web.underPageBackgroundColor = bg
        web.navigationDelegate = context.coordinator
        web.uiDelegate = context.coordinator
        web.allowsBackForwardNavigationGestures = true
        web.scrollView.contentInsetAdjustmentBehavior = .never   // the page handles safe areas (viewport-fit=cover)
        web.isOpaque = false
        web.backgroundColor = .clear
        web.scrollView.backgroundColor = .clear

        let refresh = UIRefreshControl()
        refresh.addTarget(context.coordinator, action: #selector(Coordinator.reload(_:)), for: .valueChanged)
        web.scrollView.refreshControl = refresh
        context.coordinator.web = web

        web.load(URLRequest(url: siteURL))
        return web
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}

    final class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate, WKScriptMessageHandler, ASWebAuthenticationPresentationContextProviding {
        weak var web: WKWebView?
        private var authSession: ASWebAuthenticationSession?

        // ---- Google sign-in (system sheet) ----
        func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
            guard message.name == "googleSignIn",
                  message.frameInfo.isMainFrame,
                  let host = message.frameInfo.request.url?.host?.lowercased(),
                  host == siteHost || host.hasSuffix(".ipagame.store"),
                  let body = message.body as? [String: Any],
                  let nonce = body["nonce"] as? String,
                  nonce.range(of: "^[A-Za-z0-9]{16,64}$", options: .regularExpression) != nil else { return }
            startGoogleSignIn(nonce: nonce)
        }

        private func startGoogleSignIn(nonce: String) {
            var c = URLComponents(url: siteURL.appendingPathComponent("google_app.php"), resolvingAgainstBaseURL: false)!
            c.queryItems = [URLQueryItem(name: "nonce", value: nonce)]
            let session = ASWebAuthenticationSession(url: c.url!, callbackURLScheme: "ipagame") { [weak self] url, error in
                guard let self else { return }
                self.authSession = nil
                var cred: String?
                if let url, let items = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems {
                    cred = items.first(where: { $0.name == "credential" })?.value
                }
                if let cred, cred.range(of: "^[A-Za-z0-9_-]+[.][A-Za-z0-9_-]+[.][A-Za-z0-9_-]+$", options: .regularExpression) != nil {
                    self.web?.evaluateJavaScript("window.onNativeGoogle && window.onNativeGoogle('\(cred)')")
                } else if (error as? ASWebAuthenticationSessionError)?.code == .canceledLogin {
                    self.web?.evaluateJavaScript("window.onNativeGoogleCancel && window.onNativeGoogleCancel()")
                } else {
                    self.web?.evaluateJavaScript("window.onNativeGoogleError && window.onNativeGoogleError()")
                }
            }
            session.presentationContextProvider = self
            session.prefersEphemeralWebBrowserSession = false      // reuse the Google account already signed in on this iPhone
            authSession = session
            if !session.start() {
                authSession = nil
                web?.evaluateJavaScript("window.onNativeGoogleError && window.onNativeGoogleError()")
            }
        }

        func presentationAnchor(for session: ASWebAuthenticationSession) -> ASPresentationAnchor {
            UIApplication.shared.connectedScenes
                .compactMap { ($0 as? UIWindowScene)?.keyWindow }
                .first ?? ASPresentationAnchor()
        }

        @objc func reload(_ sender: UIRefreshControl) {
            if web?.url == nil { web?.load(URLRequest(url: siteURL)) } else { web?.reload() }
        }

        private func endRefresh() { web?.scrollView.refreshControl?.endRefreshing() }

        func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction,
                     decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            guard let url = navigationAction.request.url else { return decisionHandler(.allow) }
            let scheme = url.scheme?.lowercased() ?? ""
            if scheme == "about" || scheme == "blob" || scheme == "data" { return decisionHandler(.allow) }
            if isIPA(url) {
                installInLiveContainer(url)
                return decisionHandler(.cancel)
            }
            if (scheme == "http" || scheme == "https") && isInternal(url.host) && !navigationAction.shouldPerformDownload {
                return decisionHandler(.allow)
            }
            // Downloads (.ipa links), App Store links, social links, mailto:, itms-services: ... go to the system.
            UIApplication.shared.open(url)
            decisionHandler(.cancel)
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) { endRefresh() }

        // iOS can kill the web process in the background: reload instead of showing a blank screen.
        func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
            if webView.url == nil { webView.load(URLRequest(url: siteURL)) } else { webView.reload() }
        }

        func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) { endRefresh() }

        func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
            endRefresh()
            if (error as NSError).code == NSURLErrorCancelled { return }
            let html = """
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <body style="font-family:-apple-system;display:grid;place-items:center;min-height:90vh;text-align:center;color:#888;margin:0">
            <div><h2>You're offline</h2><p>Pull down to try again.</p></div></body>
            """
            webView.loadHTMLString(html, baseURL: nil)
        }

        // target="_blank" links: keep site pages in the same view, send the rest to Safari.
        func webView(_ webView: WKWebView, createWebViewWith configuration: WKWebViewConfiguration,
                     for navigationAction: WKNavigationAction, windowFeatures: WKWindowFeatures) -> WKWebView? {
            if let url = navigationAction.request.url {
                if isIPA(url) { installInLiveContainer(url) }
                else if isInternal(url.host) { webView.load(navigationAction.request) } else { UIApplication.shared.open(url) }
            }
            return nil
        }

        // JavaScript alert()/confirm() dialogs, which the site may use.
        func webView(_ webView: WKWebView, runJavaScriptAlertPanelWithMessage message: String,
                     initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping () -> Void) {
            present(UIAlertController(title: nil, message: message, preferredStyle: .alert)) { alert in
                alert.addAction(UIAlertAction(title: "OK", style: .default) { _ in completionHandler() })
            }
        }

        func webView(_ webView: WKWebView, runJavaScriptConfirmPanelWithMessage message: String,
                     initiatedByFrame frame: WKFrameInfo, completionHandler: @escaping (Bool) -> Void) {
            present(UIAlertController(title: nil, message: message, preferredStyle: .alert)) { alert in
                alert.addAction(UIAlertAction(title: "Cancel", style: .cancel) { _ in completionHandler(false) })
                alert.addAction(UIAlertAction(title: "OK", style: .default) { _ in completionHandler(true) })
            }
        }

        private func isIPA(_ url: URL) -> Bool {
            let s = url.scheme?.lowercased() ?? ""
            return (s == "http" || s == "https") && url.path.lowercased().hasSuffix(".ipa")
        }

        /// Hand the IPA straight to LiveContainer, which downloads and installs it itself.
        private func installInLiveContainer(_ ipa: URL) {
            var allowed = CharacterSet.urlQueryAllowed
            allowed.remove(charactersIn: "&=+?#")
            guard let enc = ipa.absoluteString.addingPercentEncoding(withAllowedCharacters: allowed),
                  let lc = URL(string: "livecontainer://install?url=\(enc)"),
                  UIApplication.shared.canOpenURL(lc) else {
                present(UIAlertController(title: "LiveContainer needed",
                                          message: "Install LiveContainer first, then tap Download again to install the game automatically.",
                                          preferredStyle: .alert)) { alert in
                    alert.addAction(UIAlertAction(title: "Cancel", style: .cancel))
                    alert.addAction(UIAlertAction(title: "How to install", style: .default) { [weak self] _ in
                        self?.web?.load(URLRequest(url: siteURL.appendingPathComponent("install.html")))
                    })
                }
                return
            }
            UIApplication.shared.open(lc)
        }

        private func present(_ alert: UIAlertController, configure: (UIAlertController) -> Void) {
            configure(alert)
            let root = UIApplication.shared.connectedScenes
                .compactMap { ($0 as? UIWindowScene)?.keyWindow }
                .first?.rootViewController
            root?.present(alert, animated: true)
        }
    }
}
