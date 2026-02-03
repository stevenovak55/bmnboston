//
//  SiteContactManager.swift
//  BMNBoston
//
//  Manages site-wide contact information from the theme settings.
//  Used when a user is logged out or doesn't have an assigned agent.
//

import Foundation

/// Manages site contact settings fetched from the server
/// Used as fallback contact info when user has no assigned agent
@MainActor
class SiteContactManager: ObservableObject {
    static let shared = SiteContactManager()

    @Published private(set) var siteContact: SiteContactSettings?
    @Published private(set) var isLoading = false
    @Published private(set) var error: Error?

    private var hasFetched = false

    private init() {}

    /// Fetch site contact settings from server
    /// Only fetches once per app session unless force refreshed
    func fetchIfNeeded(forceRefresh: Bool = false) async {
        guard !hasFetched || forceRefresh else { return }
        guard !isLoading else { return }

        isLoading = true
        error = nil

        do {
            // APIClient automatically extracts 'data' from response wrapper
            let settings: SiteContactSettings = try await APIClient.shared.request(.siteContactSettings)
            siteContact = settings
            updateProperties()  // Update @Published convenience properties
            hasFetched = true
            #if DEBUG
            debugLog("✅ Site contact fetched: name=\(settings.name), email=\(settings.email), phone=\(settings.phone ?? "nil")")
            #endif
        } catch {
            self.error = error
            // Use defaults if fetch fails
            #if DEBUG
            debugLog("❌ Failed to fetch site contact settings: \(error)")
            #endif
        }

        isLoading = false
    }

    // MARK: - Convenience Accessors
    // These are @Published to ensure SwiftUI views update when data is fetched

    /// Contact name (from theme settings or default)
    @Published private(set) var name: String = "BMN Boston Team"

    /// Contact email
    @Published private(set) var email: String = "info@bmnboston.com"

    /// Contact phone
    @Published private(set) var phone: String = "+16179101010"

    /// Contact photo URL
    @Published private(set) var photoUrl: String?

    /// Brokerage/group name
    @Published private(set) var brokerageName: String?

    /// Update convenience properties when siteContact changes
    private func updateProperties() {
        name = siteContact?.name ?? "BMN Boston Team"
        email = siteContact?.email ?? "info@bmnboston.com"
        phone = siteContact?.phone ?? "+16179101010"
        photoUrl = siteContact?.photoUrl
        brokerageName = siteContact?.brokerageName
    }
}
