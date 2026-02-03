//
//  ActivityTracker.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Sprint 5: Client Analytics
//

import Foundation
import UIKit

/// Tracks user activity for analytics purposes
/// Queues events locally and sends in batches to reduce API calls
actor ActivityTracker {
    static let shared = ActivityTracker()

    // MARK: - Configuration

    /// Maximum number of queued events before auto-flush
    private let flushThreshold = 10

    /// Maximum time (seconds) between flushes
    private let flushInterval: TimeInterval = 30

    // MARK: - Cached Device Info (computed once on main actor)
    @MainActor
    private static let cachedDeviceInfo: String = {
        let device = UIDevice.current
        return "\(device.model) - \(device.systemName) \(device.systemVersion)"
    }()

    // MARK: - Dedicated URLSession (v390: Isolated from main APIClient to prevent request cancellation)
    // Using an ephemeral session to avoid any cached state issues
    private let dedicatedSession: URLSession = {
        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 60  // Longer timeout for analytics
        config.timeoutIntervalForResource = 120
        config.waitsForConnectivity = true
        config.networkServiceType = .default
        return URLSession(configuration: config)
    }()

    // MARK: - State

    private var eventQueue: [ActivityEvent] = []
    private var lastFlushTime: Date = Date()
    private var flushTask: Task<Void, Never>?
    private var isAuthenticated: Bool = false

    // v388: Delay initial flush to prevent request cancellation during app initialization
    private var authenticationTime: Date?
    private let initialFlushDelay: TimeInterval = 5.0

    // v388: Prevent flush cancellation while network request is in progress
    private var isFlushInProgress: Bool = false

    // MARK: - Activity Types

    enum ActivityType: String {
        // Core events
        case propertyView = "property_view"
        case propertyShare = "property_share"
        case searchRun = "search_run"
        case filterUsed = "filter_used"
        case favoriteAdd = "favorite_add"
        case favoriteRemove = "favorite_remove"
        case hiddenAdd = "hidden_add"
        case hiddenRemove = "hidden_remove"
        case searchSave = "search_save"
        case login = "login"
        case pageView = "page_view"

        // v6.40.0 - Property detail engagement
        case photoView = "photo_view"
        case photoLightboxOpen = "photo_lightbox_open"
        case calculatorUse = "calculator_use"
        case schoolInfoView = "school_info_view"
        case streetViewOpen = "street_view_open"

        // v6.40.0 - Map interaction events
        case mapZoom = "map_zoom"
        case mapPan = "map_pan"
        case mapDrawComplete = "map_draw_complete"
        case markerClick = "marker_click"

        // v6.40.0 - High-intent contact events
        case contactClick = "contact_click"
        case scheduleShowingClick = "schedule_click"
        case callAgentClick = "call_agent_click"
        case emailAgentClick = "email_agent_click"

        // v6.40.0 - Engagement metrics
        case timeOnPage = "time_on_page"
        case scrollDepth = "scroll_depth"
    }

    // MARK: - Event Model

    struct ActivityEvent: Codable {
        let sessionId: String
        let activityType: String
        let entityId: String?
        let entityType: String?
        let metadata: [String: AnyCodableValue]?
        let platform: String
        let deviceInfo: String?
        let timestamp: Date

        @MainActor
        init(
            sessionId: String,
            activityType: ActivityType,
            entityId: String? = nil,
            entityType: String? = nil,
            metadata: [String: Any]? = nil
        ) {
            self.sessionId = sessionId
            self.activityType = activityType.rawValue
            self.entityId = entityId
            self.entityType = entityType
            self.metadata = metadata?.mapValues { AnyCodableValue(from: $0) }
            self.platform = "ios"
            self.deviceInfo = ActivityTracker.cachedDeviceInfo
            self.timestamp = Date()
        }

        func toDictionary() -> [String: Any] {
            var dict: [String: Any] = [
                "session_id": sessionId,
                "activity_type": activityType,
                "platform": platform
            ]
            if let entityId = entityId { dict["entity_id"] = entityId }
            if let entityType = entityType { dict["entity_type"] = entityType }
            if let deviceInfo = deviceInfo { dict["device_info"] = deviceInfo }
            if let metadata = metadata {
                dict["metadata"] = metadata.mapValues { $0.rawValue }
            }
            return dict
        }
    }

    // MARK: - Public Methods

    /// Set authentication state - only track when authenticated
    func setAuthenticated(_ authenticated: Bool) {
        debugLog("🔔 ActivityTracker.setAuthenticated(\(authenticated))")
        isAuthenticated = authenticated
        if authenticated {
            // v388: Record auth time to defer initial flush during app startup
            authenticationTime = Date()
        } else {
            // Clear queue and reset auth time when user logs out
            authenticationTime = nil
            eventQueue.removeAll()
        }
    }

    /// Track an activity event
    func track(
        _ activityType: ActivityType,
        entityId: String? = nil,
        entityType: String? = nil,
        metadata: [String: Any]? = nil
    ) async {
        debugLog("🔔 ActivityTracker.track(\(activityType.rawValue)) - isAuthenticated: \(isAuthenticated)")
        guard isAuthenticated else {
            debugLog("🔔 ActivityTracker: Skipping track - not authenticated")
            return
        }

        let sessionId = await SessionManager.shared.currentSessionId

        let event = await MainActor.run {
            ActivityEvent(
                sessionId: sessionId,
                activityType: activityType,
                entityId: entityId,
                entityType: entityType,
                metadata: metadata
            )
        }

        eventQueue.append(event)

        // Check if we should flush
        if eventQueue.count >= flushThreshold {
            await flush()
        } else {
            scheduleFlush()
        }
    }

    /// Convenience method: Track property view
    func trackPropertyView(listingKey: String, city: String? = nil) async {
        debugLog("🔔 ActivityTracker.trackPropertyView(\(listingKey)) - isAuthenticated: \(isAuthenticated)")

        var metadata: [String: Any] = [:]
        if let city = city { metadata["city"] = city }

        await track(
            .propertyView,
            entityId: listingKey,
            entityType: "property",
            metadata: metadata.isEmpty ? nil : metadata
        )
    }

    /// Convenience method: Track search
    func trackSearch(filters: [String: Any], resultCount: Int) async {
        await track(
            .searchRun,
            metadata: [
                "filters": filters,
                "result_count": resultCount
            ]
        )
    }

    /// Convenience method: Track filter change
    func trackFilterUsed(filterName: String, filterValue: Any) async {
        await track(
            .filterUsed,
            metadata: [
                "filter_name": filterName,
                "filter_value": "\(filterValue)"
            ]
        )
    }

    /// Convenience method: Track favorite action
    func trackFavorite(listingKey: String, added: Bool) async {
        await track(
            added ? .favoriteAdd : .favoriteRemove,
            entityId: listingKey,
            entityType: "property"
        )
    }

    /// Convenience method: Track hidden action
    func trackHidden(listingKey: String, hidden: Bool) async {
        await track(
            hidden ? .hiddenAdd : .hiddenRemove,
            entityId: listingKey,
            entityType: "property"
        )
    }

    // MARK: - v6.40.0 Convenience Methods

    /// Track photo view in property detail
    func trackPhotoView(listingKey: String, photoIndex: Int, totalPhotos: Int) async {
        await track(
            .photoView,
            entityId: listingKey,
            entityType: "property",
            metadata: [
                "photo_index": photoIndex,
                "total_photos": totalPhotos
            ]
        )
    }

    /// Track photo lightbox/fullscreen open
    func trackPhotoLightboxOpen(listingKey: String) async {
        await track(
            .photoLightboxOpen,
            entityId: listingKey,
            entityType: "property"
        )
    }

    /// Track calculator usage
    func trackCalculatorUse(listingKey: String, downPayment: Int?, interestRate: Double?, loanTerm: Int?) async {
        var metadata: [String: Any] = [:]
        if let downPayment = downPayment { metadata["down_payment"] = downPayment }
        if let interestRate = interestRate { metadata["interest_rate"] = interestRate }
        if let loanTerm = loanTerm { metadata["loan_term"] = loanTerm }

        await track(
            .calculatorUse,
            entityId: listingKey,
            entityType: "property",
            metadata: metadata.isEmpty ? nil : metadata
        )
    }

    /// Track school info section view
    func trackSchoolInfoView(listingKey: String, schoolId: Int? = nil) async {
        var metadata: [String: Any] = [:]
        if let schoolId = schoolId { metadata["school_id"] = schoolId }

        await track(
            .schoolInfoView,
            entityId: listingKey,
            entityType: "property",
            metadata: metadata.isEmpty ? nil : metadata
        )
    }

    /// Track contact/schedule actions (high-intent)
    func trackContactAction(_ action: ActivityType, listingKey: String, agentId: Int? = nil) async {
        var metadata: [String: Any] = [:]
        if let agentId = agentId { metadata["agent_id"] = agentId }

        await track(
            action,
            entityId: listingKey,
            entityType: "property",
            metadata: metadata.isEmpty ? nil : metadata
        )
    }

    /// Track map interactions
    func trackMapInteraction(_ action: ActivityType, zoomLevel: Double? = nil, bounds: String? = nil) async {
        var metadata: [String: Any] = [:]
        if let zoomLevel = zoomLevel { metadata["zoom_level"] = zoomLevel }
        if let bounds = bounds { metadata["bounds"] = bounds }

        await track(
            action,
            metadata: metadata.isEmpty ? nil : metadata
        )
    }

    /// Track draw search completion
    func trackDrawSearchComplete(polygonPoints: Int, resultCount: Int) async {
        await track(
            .mapDrawComplete,
            metadata: [
                "polygon_points": polygonPoints,
                "result_count": resultCount
            ]
        )
    }

    /// Track time spent on property detail page
    func trackTimeOnPage(listingKey: String, durationSeconds: Int) async {
        guard durationSeconds >= 5 else { return } // Only track if spent at least 5 seconds

        await track(
            .timeOnPage,
            entityId: listingKey,
            entityType: "property",
            metadata: [
                "duration": durationSeconds
            ]
        )
    }

    /// Track scroll depth on property detail
    func trackScrollDepth(listingKey: String, percentScrolled: Int) async {
        guard percentScrolled >= 25 else { return } // Only track significant scrolling

        await track(
            .scrollDepth,
            entityId: listingKey,
            entityType: "property",
            metadata: [
                "percent": percentScrolled
            ]
        )
    }

    /// Force flush all queued events
    func flush() async {
        debugLog("🔔 ActivityTracker.flush() - queue: \(eventQueue.count), isAuth: \(isAuthenticated)")
        guard !eventQueue.isEmpty else {
            debugLog("🔔 ActivityTracker.flush(): Queue empty, skipping")
            return
        }
        guard isAuthenticated else {
            debugLog("🔔 ActivityTracker.flush(): Not authenticated, clearing queue")
            eventQueue.removeAll()
            return
        }

        // v388: Defer flush during initial app startup to prevent request cancellation
        // Multiple concurrent requests during initialization can cause -999 cancelled errors
        if let authTime = authenticationTime {
            let timeSinceAuth = Date().timeIntervalSince(authTime)
            if timeSinceAuth < initialFlushDelay {
                debugLog("🔔 ActivityTracker.flush(): Deferring - \(String(format: "%.1f", timeSinceAuth))s since auth, waiting for \(initialFlushDelay)s")
                scheduleFlush()
                return
            }
        }

        let eventsToSend = eventQueue
        eventQueue.removeAll()
        lastFlushTime = Date()

        // Cancel any pending flush task (but not if we're about to flush)
        flushTask?.cancel()
        flushTask = nil

        // v388: Mark flush as in progress to prevent scheduleFlush() from cancelling this Task
        isFlushInProgress = true
        defer { isFlushInProgress = false }

        let activities = eventsToSend.map { $0.toDictionary() }
        debugLog("🔔 ActivityTracker: Sending \(activities.count) activities to server (dedicated session)")

        // v388: Use dedicated URLSession to completely isolate from other network activity
        // This avoids request cancellation issues seen with shared APIClient session
        let maxRetries = 3
        var lastError: Error?

        for attempt in 1...maxRetries {
            do {
                try await sendActivitiesDirectly(activities)
                debugLog("🔔 ActivityTracker: Successfully sent \(activities.count) activities (attempt \(attempt))")
                return  // Success!
            } catch let error as URLError where error.code == .cancelled {
                // -999 cancelled error - retry after delay
                lastError = error
                debugLog("🔔 ActivityTracker: Request cancelled (attempt \(attempt)/\(maxRetries)), retrying...")
                if attempt < maxRetries {
                    // Exponential backoff: 2s, 4s, 8s
                    let delay = UInt64(pow(2.0, Double(attempt)) * 1_000_000_000)
                    try? await Task.sleep(nanoseconds: delay)
                }
            } catch {
                // Non-retryable error - log details
                lastError = error
                debugLog("🔔 ActivityTracker: Non-retryable error: \(error)")
                break
            }
        }

        // All retries failed - re-queue events
        if eventQueue.count < 100 {
            eventQueue.insert(contentsOf: eventsToSend, at: 0)
        }
        debugLog("🔔 ActivityTracker: Failed to flush events after \(maxRetries) attempts: \(lastError?.localizedDescription ?? "unknown")")
    }

    /// Send activities using dedicated URLSession (bypasses APIClient entirely)
    /// v390: Uses Task.detached to prevent request cancellation from caller's Task being cancelled
    private func sendActivitiesDirectly(_ activities: [[String: Any]]) async throws {
        // Build the URL
        let urlString = AppEnvironment.current.fullAPIURL + "/analytics/activity/batch"
        guard let url = URL(string: urlString) else {
            throw URLError(.badURL)
        }

        // Get auth token before creating request
        guard let token = await TokenManager.shared.getAccessToken() else {
            throw URLError(.userAuthenticationRequired)
        }

        // Build the request body
        let body: [String: Any] = ["activities": activities]
        let bodyData = try JSONSerialization.data(withJSONObject: body)

        debugLog("🔔 ActivityTracker: POST \(urlString) with \(activities.count) activities")

        // v390: Use a detached task to completely isolate the network request
        // from any cancellation of the parent Task. This prevents the -999 cancelled
        // errors that occur when the calling Task is cancelled during the request.
        let session = dedicatedSession
        let result: (Data, URLResponse) = try await Task.detached {
            var request = URLRequest(url: url)
            request.httpMethod = "POST"
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.setValue("application/json", forHTTPHeaderField: "Accept")
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            request.httpBody = bodyData

            return try await session.data(for: request)
        }.value

        let (data, response) = result

        guard let httpResponse = response as? HTTPURLResponse else {
            throw URLError(.badServerResponse)
        }

        debugLog("🔔 ActivityTracker: Response status \(httpResponse.statusCode)")

        guard (200...299).contains(httpResponse.statusCode) else {
            if let responseString = String(data: data, encoding: .utf8) {
                debugLog("🔔 ActivityTracker: Error response: \(responseString.prefix(200))")
            }
            throw URLError(.init(rawValue: httpResponse.statusCode))
        }

        // Parse response to verify success
        if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
           let success = json["success"] as? Bool,
           success {
            if let dataObj = json["data"] as? [String: Any],
               let successCount = dataObj["success_count"] as? Int {
                debugLog("🔔 ActivityTracker: Server confirmed \(successCount) activities recorded")
            }
        }
    }

    // MARK: - Private Methods

    private func scheduleFlush() {
        // v388: Don't cancel if a flush is already in progress - would cancel the network request!
        if isFlushInProgress {
            debugLog("🔔 ActivityTracker.scheduleFlush(): Skipping - flush in progress")
            return
        }

        // v391: Don't cancel existing scheduled flush - let it run and batch all events
        // Previously, each new event would reset the 30s timer, preventing flush from ever firing
        // during active user sessions. Now we let the original timer complete.
        if flushTask != nil {
            debugLog("🔔 ActivityTracker.scheduleFlush(): Flush already scheduled (queue: \(eventQueue.count))")
            return
        }

        debugLog("🔔 ActivityTracker.scheduleFlush(): Scheduling flush in \(flushInterval)s (queue: \(eventQueue.count))")

        flushTask = Task {
            try? await Task.sleep(nanoseconds: UInt64(flushInterval * 1_000_000_000))
            if Task.isCancelled {
                debugLog("🔔 ActivityTracker.scheduleFlush(): Task was cancelled, skipping flush")
                return
            }
            debugLog("🔔 ActivityTracker.scheduleFlush(): Timer fired, calling flush()")
            await flush()
        }
    }
}

// MARK: - Response Models

struct BatchActivityResponse: Decodable {
    let successCount: Int
    let failCount: Int
    let total: Int

    private enum CodingKeys: String, CodingKey {
        case successCount = "success_count"
        case failCount = "fail_count"
        case total
    }
}

struct ActivityRecordResponse: Decodable {
    let activityId: Int

    private enum CodingKeys: String, CodingKey {
        case activityId = "activity_id"
    }
}
