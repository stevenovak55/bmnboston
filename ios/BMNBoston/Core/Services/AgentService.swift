//
//  AgentService.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Phase 5: Agent-Client Collaboration System
//

import Foundation

// MARK: - Agent Service

/// Actor-based service for agent-related API operations
actor AgentService {
    static let shared = AgentService()

    private init() {}

    // MARK: - Cache

    private var cachedAgents: [Agent]?
    private var agentsCacheTime: Date?
    private var cachedMyAgent: Agent?
    private var myAgentCacheTime: Date?
    private var cachedEmailPrefs: EmailPreferences?
    private var emailPrefsCacheTime: Date?

    private let agentsCacheDuration: TimeInterval = 3600 // 1 hour
    private let myAgentCacheDuration: TimeInterval = 300 // 5 minutes
    private let emailPrefsCacheDuration: TimeInterval = 60 // 1 minute

    // MARK: - Agents List

    /// Fetch all active agents
    /// - Parameter forceRefresh: If true, bypasses cache
    /// - Returns: Array of agents
    func fetchAgents(forceRefresh: Bool = false) async throws -> [Agent] {
        // Check cache
        if !forceRefresh,
           let cached = cachedAgents,
           let cacheTime = agentsCacheTime,
           Date().timeIntervalSince(cacheTime) < agentsCacheDuration {
            return cached
        }

        // Fetch from API
        let response: AgentListResponse = try await APIClient.shared.request(.agents)
        cachedAgents = response.agents
        agentsCacheTime = Date()
        return response.agents
    }

    /// Get a specific agent by ID
    func fetchAgent(id: Int) async throws -> Agent {
        // First check cache
        if let cached = cachedAgents?.first(where: { $0.id == id }) {
            return cached
        }

        // Fetch from API
        let agent: Agent = try await APIClient.shared.request(.agentDetail(id: id))
        return agent
    }

    // MARK: - My Agent (Client's Assigned Agent)

    /// Fetch the current user's assigned agent
    /// - Parameter forceRefresh: If true, bypasses cache
    /// - Returns: The assigned agent, or nil if none
    func fetchMyAgent(forceRefresh: Bool = false) async throws -> Agent? {
        // Check cache
        if !forceRefresh,
           let cacheTime = myAgentCacheTime,
           Date().timeIntervalSince(cacheTime) < myAgentCacheDuration {
            return cachedMyAgent
        }

        // Fetch from API - agent data is returned directly in 'data' field
        do {
            let agent: Agent = try await APIClient.shared.request(.myAgent)
            cachedMyAgent = agent
            myAgentCacheTime = Date()
            return agent
        } catch {
            // If 404 or no agent, return nil
            cachedMyAgent = nil
            myAgentCacheTime = Date()
            return nil
        }
    }

    /// Clear my agent cache (call when user changes)
    func clearMyAgentCache() {
        cachedMyAgent = nil
        myAgentCacheTime = nil
    }

    // MARK: - Email Preferences

    /// Fetch user's email preferences
    func fetchEmailPreferences(forceRefresh: Bool = false) async throws -> EmailPreferences {
        // Check cache
        if !forceRefresh,
           let cached = cachedEmailPrefs,
           let cacheTime = emailPrefsCacheTime,
           Date().timeIntervalSince(cacheTime) < emailPrefsCacheDuration {
            return cached
        }

        // Fetch from API
        let response: EmailPreferencesResponse = try await APIClient.shared.request(.emailPreferences)
        cachedEmailPrefs = response.preferences
        emailPrefsCacheTime = Date()
        return response.preferences
    }

    /// Update user's email preferences
    func updateEmailPreferences(
        digestEnabled: Bool? = nil,
        digestFrequency: String? = nil,
        digestTime: String? = nil,
        globalPause: Bool? = nil,
        timezone: String? = nil
    ) async throws -> EmailPreferences {
        let endpoint = APIEndpoint.updateEmailPreferences(
            digestEnabled: digestEnabled,
            digestFrequency: digestFrequency,
            digestTime: digestTime,
            globalPause: globalPause,
            timezone: timezone
        )

        let response: EmailPreferencesResponse = try await APIClient.shared.request(endpoint)

        // Update cache
        cachedEmailPrefs = response.preferences
        emailPrefsCacheTime = Date()

        return response.preferences
    }

    // MARK: - Cache Management

    func clearCache() {
        cachedAgents = nil
        agentsCacheTime = nil
        cachedMyAgent = nil
        myAgentCacheTime = nil
        cachedEmailPrefs = nil
        emailPrefsCacheTime = nil
        cachedClients = nil
        clientsCacheTime = nil
        cachedMetrics = nil
        metricsCacheTime = nil
        cachedSharedProperties = nil
        sharedPropertiesCacheTime = nil
        cachedAgentShares = nil
        agentSharesCacheTime = nil
    }

    // MARK: - Agent Client Cache

    private var cachedClients: [AgentClient]?
    private var clientsCacheTime: Date?
    private var cachedMetrics: AgentMetrics?
    private var metricsCacheTime: Date?

    private let clientsCacheDuration: TimeInterval = 60 // 1 minute
    private let metricsCacheDuration: TimeInterval = 300 // 5 minutes

    // MARK: - Agent Client Management

    /// Fetch agent's clients
    /// - Parameter forceRefresh: If true, bypasses cache
    /// - Returns: Array of clients
    func fetchAgentClients(forceRefresh: Bool = false) async throws -> [AgentClient] {
        // Check cache
        if !forceRefresh,
           let cached = cachedClients,
           let cacheTime = clientsCacheTime,
           Date().timeIntervalSince(cacheTime) < clientsCacheDuration {
            return cached
        }

        // Fetch from API
        let response: AgentClientListResponse = try await APIClient.shared.request(.agentClients)
        cachedClients = response.clients
        clientsCacheTime = Date()
        return response.clients
    }

    /// Fetch a specific client's details
    func fetchClientDetail(clientId: Int) async throws -> AgentClient {
        let response: AgentClientDetailResponse = try await APIClient.shared.request(.agentClientDetail(clientId: clientId))
        return response.client
    }

    /// Fetch a client's saved searches
    /// API returns array directly in data field, not wrapped in { searches: [] }
    func fetchClientSearches(clientId: Int) async throws -> [ClientSavedSearch] {
        let searches: [ClientSavedSearch] = try await APIClient.shared.request(.agentClientSearches(clientId: clientId))
        return searches
    }

    /// Fetch a client's favorites
    /// API returns array directly in data field, not wrapped in { favorites: [] }
    func fetchClientFavorites(clientId: Int) async throws -> [ClientFavorite] {
        let favorites: [ClientFavorite] = try await APIClient.shared.request(.agentClientFavorites(clientId: clientId))
        return favorites
    }

    /// Fetch a client's hidden properties
    /// API returns array directly in data field, not wrapped in { hidden: [] }
    func fetchClientHidden(clientId: Int) async throws -> [ClientHiddenProperty] {
        let hidden: [ClientHiddenProperty] = try await APIClient.shared.request(.agentClientHidden(clientId: clientId))
        return hidden
    }

    /// Create a new client
    func createClient(
        email: String,
        firstName: String,
        lastName: String? = nil,
        phone: String? = nil,
        sendNotification: Bool = true
    ) async throws -> AgentClient {
        let endpoint = APIEndpoint.createAgentClient(
            email: email,
            firstName: firstName,
            lastName: lastName,
            phone: phone,
            sendNotification: sendNotification
        )
        let response: CreateClientResponse = try await APIClient.shared.request(endpoint)

        // Invalidate cache since we added a new client
        cachedClients = nil
        clientsCacheTime = nil

        return response.client
    }

    /// Fetch agent metrics/stats
    func fetchAgentMetrics(forceRefresh: Bool = false) async throws -> AgentMetrics {
        // Check cache
        if !forceRefresh,
           let cached = cachedMetrics,
           let cacheTime = metricsCacheTime,
           Date().timeIntervalSince(cacheTime) < metricsCacheDuration {
            return cached
        }

        // Fetch from API
        let metrics: AgentMetrics = try await APIClient.shared.request(.agentMetrics)
        cachedMetrics = metrics
        metricsCacheTime = Date()
        return metrics
    }

    /// Clear client cache (call when agent creates/removes clients)
    func clearClientCache() {
        cachedClients = nil
        clientsCacheTime = nil
        cachedMetrics = nil
        metricsCacheTime = nil
    }

    // MARK: - Shared Properties Cache

    private var cachedSharedProperties: [SharedProperty]?
    private var sharedPropertiesCacheTime: Date?
    private var cachedAgentShares: [AgentShareRecord]?
    private var agentSharesCacheTime: Date?

    private let sharedPropertiesCacheDuration: TimeInterval = 60 // 1 minute

    // MARK: - Property Sharing (Agent Actions)

    /// Share properties with clients
    /// - Parameters:
    ///   - clientIds: IDs of clients to share with
    ///   - listingKeys: Listing keys of properties to share
    ///   - note: Optional note for clients
    /// - Returns: Share response with count and notifications sent
    func shareProperties(clientIds: [Int], listingKeys: [String], note: String?) async throws -> SharePropertiesResponse {
        let endpoint = APIEndpoint.shareProperties(clientIds: clientIds, listingKeys: listingKeys, note: note)
        let response: SharePropertiesResponse = try await APIClient.shared.request(endpoint)

        // Invalidate cache
        cachedAgentShares = nil
        agentSharesCacheTime = nil

        return response
    }

    /// Get properties shared by this agent
    func fetchAgentSharedProperties(forceRefresh: Bool = false) async throws -> [AgentShareRecord] {
        // Check cache
        if !forceRefresh,
           let cached = cachedAgentShares,
           let cacheTime = agentSharesCacheTime,
           Date().timeIntervalSince(cacheTime) < sharedPropertiesCacheDuration {
            return cached
        }

        // Fetch from API
        let response: AgentSharedPropertiesResponse = try await APIClient.shared.request(.agentSharedProperties)
        cachedAgentShares = response.shares
        agentSharesCacheTime = Date()
        return response.shares
    }

    /// Revoke a shared property
    func revokeShare(id: Int) async throws {
        let _: EmptyResponse = try await APIClient.shared.request(.revokeSharedProperty(id: id))

        // Invalidate cache
        cachedAgentShares = nil
        agentSharesCacheTime = nil
    }

    // MARK: - Shared Properties (Client Actions)

    /// Get properties shared with this client
    func fetchSharedProperties(forceRefresh: Bool = false) async throws -> [SharedProperty] {
        // Check cache
        if !forceRefresh,
           let cached = cachedSharedProperties,
           let cacheTime = sharedPropertiesCacheTime,
           Date().timeIntervalSince(cacheTime) < sharedPropertiesCacheDuration {
            return cached
        }

        // Fetch from API - handle both array and object responses
        do {
            // Try to decode as array first
            let properties: [SharedProperty] = try await APIClient.shared.request(.sharedProperties)
            cachedSharedProperties = properties
            sharedPropertiesCacheTime = Date()
            return properties
        } catch {
            // Try wrapped response
            let response: SharedPropertiesListResponse = try await APIClient.shared.request(.sharedProperties)
            let properties = response.properties ?? []
            cachedSharedProperties = properties
            sharedPropertiesCacheTime = Date()
            return properties
        }
    }

    /// Update response to shared property
    func updateSharedPropertyResponse(id: Int, response: ClientResponse, note: String?) async throws -> UpdateSharedPropertyResponse {
        let endpoint = APIEndpoint.updateSharedProperty(id: id, response: response.rawValue, note: note)
        let result: UpdateSharedPropertyResponse = try await APIClient.shared.request(endpoint)

        // Invalidate cache
        cachedSharedProperties = nil
        sharedPropertiesCacheTime = nil

        return result
    }

    /// Dismiss a shared property (client)
    func dismissSharedProperty(id: Int) async throws {
        let _: EmptyResponse = try await APIClient.shared.request(.dismissSharedProperty(id: id))

        // Invalidate cache
        cachedSharedProperties = nil
        sharedPropertiesCacheTime = nil
    }

    /// Clear shared properties cache
    func clearSharedPropertiesCache() {
        cachedSharedProperties = nil
        sharedPropertiesCacheTime = nil
        cachedAgentShares = nil
        agentSharesCacheTime = nil
    }

    // MARK: - Agent-Created Searches

    /// Create saved searches for multiple clients (batch)
    /// - Parameters:
    ///   - clientIds: IDs of clients to create searches for
    ///   - name: Search name
    ///   - filters: Search filters dictionary
    ///   - notificationFrequency: How often to notify
    ///   - agentNotes: Optional note for clients
    ///   - ccAgentOnNotify: Whether to CC agent on notifications
    /// - Returns: Response with created count and search IDs
    func createSearchesForClients(
        clientIds: [Int],
        name: String,
        filters: [String: Any],
        notificationFrequency: String,
        agentNotes: String?,
        ccAgentOnNotify: Bool
    ) async throws -> BatchSearchResponse {
        let endpoint = APIEndpoint.createSearchesForClients(
            clientIds: clientIds,
            name: name,
            filters: filters,
            notificationFrequency: notificationFrequency,
            agentNotes: agentNotes,
            ccAgentOnNotify: ccAgentOnNotify
        )
        let response: BatchSearchResponse = try await APIClient.shared.request(endpoint)
        return response
    }

    // MARK: - Client Analytics (Sprint 5)

    /// Fetch analytics for a specific client
    /// - Parameters:
    ///   - clientId: Client's user ID
    ///   - days: Number of days to look back (default 30)
    /// - Returns: Client analytics summary
    func fetchClientAnalytics(clientId: Int, days: Int = 30) async throws -> ClientAnalytics {
        let analytics: ClientAnalytics = try await APIClient.shared.request(.clientAnalytics(clientId: clientId, days: days))
        return analytics
    }

    /// Fetch activity timeline for a specific client
    /// - Parameters:
    ///   - clientId: Client's user ID
    ///   - limit: Number of activities to fetch
    ///   - offset: Pagination offset
    /// - Returns: Array of activity events
    func fetchClientActivityTimeline(clientId: Int, limit: Int = 50, offset: Int = 0) async throws -> [ClientActivityEvent] {
        let response: ClientActivityTimelineResponse = try await APIClient.shared.request(.clientActivityTimeline(clientId: clientId, limit: limit, offset: offset))
        return response.activities
    }

    /// Fetch analytics dashboard for all agent's clients
    /// - Parameter days: Number of days to look back (default 30)
    /// - Returns: Dashboard response with all client analytics and totals
    func fetchAgentAnalyticsDashboard(days: Int = 30) async throws -> AgentAnalyticsDashboardResponse {
        let response: AgentAnalyticsDashboardResponse = try await APIClient.shared.request(.agentAnalyticsDashboard(days: days))
        return response
    }
}

// MARK: - Batch Search Response

struct BatchSearchResponse: Decodable {
    let createdCount: Int
    let searchIds: [Int]
    let notificationsSent: BatchNotificationsSent?

    enum CodingKeys: String, CodingKey {
        case createdCount = "created_count"
        case searchIds = "search_ids"
        case notificationsSent = "notifications_sent"
    }
}

struct BatchNotificationsSent: Decodable {
    let push: Int
    let email: Int
}

// MARK: - Email Preferences Model

struct EmailPreferences: Codable, Equatable {
    let id: Int?
    let userId: Int?
    let digestEnabled: Bool
    let digestFrequency: String
    let digestTime: String
    let preferredFormat: String
    let globalPause: Bool
    let timezone: String
    let unsubscribedAt: Date?
    let createdAt: Date?
    let updatedAt: Date?

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case digestEnabled = "digest_enabled"
        case digestFrequency = "digest_frequency"
        case digestTime = "digest_time"
        case preferredFormat = "preferred_format"
        case globalPause = "global_pause"
        case timezone
        case unsubscribedAt = "unsubscribed_at"
        case createdAt = "created_at"
        case updatedAt = "updated_at"
    }

    /// Formatted digest time for display
    var formattedDigestTime: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm:ss"
        if let date = formatter.date(from: digestTime) {
            formatter.dateFormat = "h:mm a"
            return formatter.string(from: date)
        }
        return digestTime
    }

    /// Display name for frequency
    var frequencyDisplayName: String {
        switch digestFrequency {
        case "daily": return "Daily"
        case "weekly": return "Weekly"
        default: return digestFrequency.capitalized
        }
    }

    /// Display name for timezone
    var timezoneDisplayName: String {
        switch timezone {
        case "America/New_York": return "Eastern Time"
        case "America/Chicago": return "Central Time"
        case "America/Denver": return "Mountain Time"
        case "America/Los_Angeles": return "Pacific Time"
        default: return timezone
        }
    }
}

// MARK: - Email Preferences Response

struct EmailPreferencesResponse: Decodable {
    let preferences: EmailPreferences
    let stats: EmailStats?
}

struct EmailStats: Decodable {
    let totalSent: Int
    let totalOpened: Int
    let totalClicks: Int
    let openRate: Double
    let lastSent: Date?

    enum CodingKeys: String, CodingKey {
        case totalSent = "total_sent"
        case totalOpened = "total_opened"
        case totalClicks = "total_clicks"
        case openRate = "open_rate"
        case lastSent = "last_sent"
    }
}
