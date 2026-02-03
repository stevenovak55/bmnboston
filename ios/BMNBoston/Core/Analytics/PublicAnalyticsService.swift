//
//  PublicAnalyticsService.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Phase 3: Cross-Platform Public Analytics
//
//  Tracks ALL app users (anonymous + authenticated) for unified web+iOS analytics.
//  Works independently of login state, using device-generated session IDs.
//

import Foundation
import UIKit
import os.log

/// Service for tracking all app visitors (anonymous + authenticated)
/// Sends events to the unified cross-platform analytics endpoint
actor PublicAnalyticsService {
    static let shared = PublicAnalyticsService()

    // MARK: - Configuration

    /// API endpoint for tracking (different from authenticated activity endpoint)
    private static let trackEndpoint = "/mld-analytics/v1/track"
    private static let heartbeatEndpoint = "/mld-analytics/v1/heartbeat"

    /// Maximum events before auto-flush
    private let flushThreshold = 10

    /// Maximum time between flushes (seconds)
    private let flushInterval: TimeInterval = 30

    /// Heartbeat interval (seconds)
    private let heartbeatInterval: TimeInterval = 60

    /// Session timeout (minutes)
    private let sessionTimeout: TimeInterval = 30 * 60

    // MARK: - Storage Keys

    private let sessionKey = "mld_public_session"
    private let visitorKey = "mld_public_visitor_id"
    private let pendingEventsKey = "mld_public_pending_events"

    // MARK: - State

    private var eventQueue: [PublicAnalyticsEvent] = []
    private var sessionId: String = ""
    private var visitorId: String = ""
    private var lastActivityTime: Date = Date()
    private var flushTask: Task<Void, Never>?
    private var heartbeatTask: Task<Void, Never>?
    private var isInitialized = false

    // Cached device info (captured on main actor during initialization)
    private var cachedScreenWidth: Int = 0
    private var cachedScreenHeight: Int = 0
    private var cachedDeviceType: String = "iPhone"
    private var cachedOSVersion: String = ""

    private let logger = Logger(subsystem: "com.bmnboston.app", category: "PublicAnalytics")

    // MARK: - Event Types

    enum EventType: String {
        case appOpen = "app_open"
        case appBackground = "app_background"
        case screenView = "screen_view"
        case pageView = "page_view"
        case propertyView = "property_view"
        case propertyClick = "property_click"
        case searchExecute = "search_execute"
        case filterApply = "filter_apply"
        case favoriteAdd = "favorite_add"
        case favoriteRemove = "favorite_remove"
        case contactClick = "contact_click"
        case contactSubmit = "contact_submit"
        case shareClick = "share_click"
        case scheduleClick = "schedule_click"
        case mapZoom = "map_zoom"
        case mapPan = "map_pan"
        case photoView = "photo_view"
        case scrollDepth = "scroll_depth"
        case timeOnPage = "time_on_page"
    }

    // MARK: - Event Model

    struct PublicAnalyticsEvent: Codable {
        let type: String
        let timestamp: String
        let pageUrl: String?
        let pagePath: String?
        let pageTitle: String?
        let pageType: String?
        let listingId: String?
        let listingKey: String?
        let propertyCity: String?
        let propertyPrice: Int?
        let propertyBeds: Int?
        let propertyBaths: Double?
        let searchQuery: String?
        let searchResultsCount: Int?
        let clickTarget: String?
        let clickElement: String?
        let scrollDepth: Int?
        let timeOnPage: Int?
        let data: [String: AnyCodableValue]?

        enum CodingKeys: String, CodingKey {
            case type
            case timestamp
            case pageUrl = "page_url"
            case pagePath = "page_path"
            case pageTitle = "page_title"
            case pageType = "page_type"
            case listingId = "listing_id"
            case listingKey = "listing_key"
            case propertyCity = "property_city"
            case propertyPrice = "property_price"
            case propertyBeds = "property_beds"
            case propertyBaths = "property_baths"
            case searchQuery = "search_query"
            case searchResultsCount = "search_results_count"
            case clickTarget = "click_target"
            case clickElement = "click_element"
            case scrollDepth = "scroll_depth"
            case timeOnPage = "time_on_page"
            case data
        }

        func toDictionary() -> [String: Any] {
            var dict: [String: Any] = ["type": type, "timestamp": timestamp]
            if let pageUrl = pageUrl { dict["page_url"] = pageUrl }
            if let pagePath = pagePath { dict["page_path"] = pagePath }
            if let pageTitle = pageTitle { dict["page_title"] = pageTitle }
            if let pageType = pageType { dict["page_type"] = pageType }
            if let listingId = listingId { dict["listing_id"] = listingId }
            if let listingKey = listingKey { dict["listing_key"] = listingKey }
            if let propertyCity = propertyCity { dict["property_city"] = propertyCity }
            if let propertyPrice = propertyPrice { dict["property_price"] = propertyPrice }
            if let propertyBeds = propertyBeds { dict["property_beds"] = propertyBeds }
            if let propertyBaths = propertyBaths { dict["property_baths"] = propertyBaths }
            if let searchQuery = searchQuery { dict["search_query"] = searchQuery }
            if let searchResultsCount = searchResultsCount { dict["search_results_count"] = searchResultsCount }
            if let clickTarget = clickTarget { dict["click_target"] = clickTarget }
            if let clickElement = clickElement { dict["click_element"] = clickElement }
            if let scrollDepth = scrollDepth { dict["scroll_depth"] = scrollDepth }
            if let timeOnPage = timeOnPage { dict["time_on_page"] = timeOnPage }
            if let data = data { dict["data"] = data.mapValues { $0.rawValue } }
            return dict
        }
    }

    // MARK: - Initialization

    private init() {}

    /// Initialize the service - call on app launch
    func initialize() async {
        guard !isInitialized else { return }

        // Load or create visitor ID (persistent across app installs via Keychain would be better)
        visitorId = loadOrCreateVisitorId()

        // Load or create session ID (respects 30-min timeout)
        sessionId = loadOrCreateSessionId()

        // Restore any pending events
        restorePendingEvents()

        // Cache device info from main actor
        let deviceInfo = await MainActor.run {
            let device = UIDevice.current
            let screen = UIScreen.main
            return (
                screenWidth: Int(screen.bounds.width * screen.scale),
                screenHeight: Int(screen.bounds.height * screen.scale),
                deviceType: device.model,
                osVersion: device.systemVersion
            )
        }
        cachedScreenWidth = deviceInfo.screenWidth
        cachedScreenHeight = deviceInfo.screenHeight
        cachedDeviceType = deviceInfo.deviceType
        cachedOSVersion = deviceInfo.osVersion

        isInitialized = true
        logger.info("PublicAnalyticsService initialized - session: \(self.sessionId.prefix(8))...")

        // Track app open
        await track(.appOpen, pageType: "app")

        // Start heartbeat
        startHeartbeat()

        // Schedule first flush
        scheduleFlush()
    }

    // MARK: - Session Management

    private func loadOrCreateVisitorId() -> String {
        if let stored = UserDefaults.standard.string(forKey: visitorKey) {
            return stored
        }
        let newId = UUID().uuidString.lowercased()
        UserDefaults.standard.set(newId, forKey: visitorKey)
        return newId
    }

    private func loadOrCreateSessionId() -> String {
        if let data = UserDefaults.standard.data(forKey: sessionKey),
           let session = try? JSONDecoder().decode(SessionData.self, from: data) {
            // Check if session is still valid (within 30-min timeout)
            let age = Date().timeIntervalSince(session.lastActivity)
            if age < sessionTimeout {
                // Update last activity time
                saveSession(id: session.id)
                return session.id
            }
        }

        // Create new session
        let newId = UUID().uuidString.lowercased()
        saveSession(id: newId)
        return newId
    }

    private func saveSession(id: String) {
        let session = SessionData(id: id, lastActivity: Date())
        if let data = try? JSONEncoder().encode(session) {
            UserDefaults.standard.set(data, forKey: sessionKey)
        }
    }

    private struct SessionData: Codable {
        let id: String
        let lastActivity: Date
    }

    /// Call when app goes to background to save session state
    func handleAppBackground() async {
        await track(.appBackground, pageType: "app")
        await flush()
        saveSession(id: sessionId)
    }

    /// Call when app becomes active - may create new session if timed out
    func handleAppForeground() async {
        let oldSessionId = sessionId
        sessionId = loadOrCreateSessionId()

        if sessionId != oldSessionId {
            // New session created due to timeout
            logger.info("New session created after timeout")
            await track(.appOpen, pageType: "app")
        }

        startHeartbeat()
    }

    // MARK: - Tracking Methods

    /// Track an event
    func track(
        _ eventType: EventType,
        pagePath: String? = nil,
        pageType: String? = nil,
        pageTitle: String? = nil,
        listingId: String? = nil,
        listingKey: String? = nil,
        propertyCity: String? = nil,
        propertyPrice: Int? = nil,
        propertyBeds: Int? = nil,
        propertyBaths: Double? = nil,
        searchQuery: [String: Any]? = nil,
        searchResultsCount: Int? = nil,
        clickTarget: String? = nil,
        clickElement: String? = nil,
        scrollDepth: Int? = nil,
        timeOnPage: Int? = nil,
        data: [String: Any]? = nil
    ) async {
        guard isInitialized else {
            logger.warning("PublicAnalyticsService not initialized - call initialize() first")
            return
        }

        lastActivityTime = Date()

        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]

        let event = PublicAnalyticsEvent(
            type: eventType.rawValue,
            timestamp: formatter.string(from: Date()),
            pageUrl: nil,  // iOS doesn't have URLs
            pagePath: pagePath,
            pageTitle: pageTitle,
            pageType: pageType,
            listingId: listingId,
            listingKey: listingKey,
            propertyCity: propertyCity,
            propertyPrice: propertyPrice,
            propertyBeds: propertyBeds,
            propertyBaths: propertyBaths,
            searchQuery: searchQuery != nil ? String(describing: searchQuery) : nil,
            searchResultsCount: searchResultsCount,
            clickTarget: clickTarget,
            clickElement: clickElement,
            scrollDepth: scrollDepth,
            timeOnPage: timeOnPage,
            data: data?.mapValues { AnyCodableValue(from: $0) }
        )

        eventQueue.append(event)
        savePendingEvents()

        logger.debug("Tracked: \(eventType.rawValue)")

        // Flush immediately for important events
        let immediateEvents: Set<EventType> = [.contactSubmit, .scheduleClick, .favoriteAdd]
        if immediateEvents.contains(eventType) {
            await flush()
        } else if eventQueue.count >= flushThreshold {
            await flush()
        }
    }

    // MARK: - Convenience Methods

    /// Track property view
    func trackPropertyView(
        listingId: String,
        listingKey: String? = nil,
        city: String? = nil,
        price: Int? = nil,
        beds: Int? = nil,
        baths: Double? = nil
    ) async {
        await track(
            .propertyView,
            pageType: "property_detail",
            pageTitle: "Property Detail",
            listingId: listingId,
            listingKey: listingKey,
            propertyCity: city,
            propertyPrice: price,
            propertyBeds: beds,
            propertyBaths: baths
        )
    }

    /// Track search
    func trackSearch(filters: [String: Any], resultCount: Int) async {
        await track(
            .searchExecute,
            pageType: "search",
            searchQuery: filters,
            searchResultsCount: resultCount
        )
    }

    /// Track screen view
    func trackScreenView(screenName: String, screenType: String) async {
        await track(
            .screenView,
            pagePath: "/\(screenType)",
            pageType: screenType,
            pageTitle: screenName
        )
    }

    /// Track favorite action
    func trackFavorite(listingId: String, added: Bool) async {
        await track(
            added ? .favoriteAdd : .favoriteRemove,
            listingId: listingId
        )
    }

    /// Track contact button click
    func trackContactClick(listingId: String?, contactType: String) async {
        await track(
            .contactClick,
            listingId: listingId,
            clickElement: contactType
        )
    }

    /// Track share click
    func trackShareClick(listingId: String?) async {
        await track(
            .shareClick,
            listingId: listingId
        )
    }

    /// Track schedule showing click
    func trackScheduleClick(listingId: String?) async {
        await track(
            .scheduleClick,
            listingId: listingId
        )
    }

    /// Track photo gallery view
    func trackPhotoView(listingId: String, photoIndex: Int) async {
        await track(
            .photoView,
            listingId: listingId,
            data: ["photo_index": photoIndex]
        )
    }

    // MARK: - Flush & Heartbeat

    /// Flush queued events to server
    func flush() async {
        guard !eventQueue.isEmpty else { return }

        let eventsToSend = eventQueue
        eventQueue.removeAll()
        savePendingEvents()

        do {
            try await sendEvents(eventsToSend)
            logger.debug("Flushed \(eventsToSend.count) events")
        } catch {
            // Re-queue on failure (up to limit)
            if eventQueue.count < 100 {
                eventQueue.insert(contentsOf: eventsToSend, at: 0)
                savePendingEvents()
            }
            logger.error("Flush failed: \(error.localizedDescription)")
        }
    }

    private func sendEvents(_ events: [PublicAnalyticsEvent]) async throws {
        let baseURL = AppEnvironment.current.baseURL
        guard let url = URL(string: baseURL + Self.trackEndpoint) else {
            throw URLError(.badURL)
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")

        let payload: [String: Any] = [
            "session_id": sessionId,
            "visitor_hash": visitorId,
            "events": events.map { $0.toDictionary() },
            "session_data": getSessionData()
        ]

        request.httpBody = try JSONSerialization.data(withJSONObject: payload)

        let (_, response) = try await URLSession.shared.data(for: request)

        guard let httpResponse = response as? HTTPURLResponse,
              (200...299).contains(httpResponse.statusCode) else {
            throw URLError(.badServerResponse)
        }
    }

    private func getSessionData() -> [String: Any] {
        var data: [String: Any] = [
            "visitor_hash": visitorId,
            "platform": "ios_app"
        ]

        // Add user ID if authenticated
        if let userId = UserDefaults.standard.object(forKey: "currentUserId") as? Int {
            data["user_id"] = userId
        }

        // Use cached device info (captured during initialization on main actor)
        data["screen_width"] = cachedScreenWidth
        data["screen_height"] = cachedScreenHeight
        data["device_type"] = cachedDeviceType
        data["os_version"] = cachedOSVersion

        return data
    }

    private func scheduleFlush() {
        flushTask?.cancel()
        flushTask = Task {
            try? await Task.sleep(nanoseconds: UInt64(flushInterval * 1_000_000_000))
            guard !Task.isCancelled else { return }
            await flush()
            scheduleFlush()
        }
    }

    private func startHeartbeat() {
        heartbeatTask?.cancel()
        heartbeatTask = Task {
            // Send immediate heartbeat first (don't wait)
            await sendHeartbeat()

            // Then continue with periodic heartbeats
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: UInt64(heartbeatInterval * 1_000_000_000))
                guard !Task.isCancelled else { break }
                await sendHeartbeat()
            }
        }
    }

    private func sendHeartbeat() async {
        let baseURL = AppEnvironment.current.baseURL
        guard let url = URL(string: baseURL + Self.heartbeatEndpoint) else { return }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")

        let payload: [String: Any] = [
            "session_id": sessionId,
            "page_type": "ios_app"
        ]

        request.httpBody = try? JSONSerialization.data(withJSONObject: payload)

        _ = try? await URLSession.shared.data(for: request)
    }

    // MARK: - Persistence

    private func savePendingEvents() {
        if let data = try? JSONEncoder().encode(eventQueue) {
            UserDefaults.standard.set(data, forKey: pendingEventsKey)
        }
    }

    private func restorePendingEvents() {
        if let data = UserDefaults.standard.data(forKey: pendingEventsKey),
           let events = try? JSONDecoder().decode([PublicAnalyticsEvent].self, from: data) {
            eventQueue = events
            logger.debug("Restored \(events.count) pending events")
        }
    }
}
