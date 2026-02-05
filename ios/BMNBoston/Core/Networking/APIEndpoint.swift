//
//  APIEndpoint.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation

enum HTTPMethod: String {
    case get = "GET"
    case post = "POST"
    case put = "PUT"
    case delete = "DELETE"
}

struct APIEndpoint {
    let path: String
    let method: HTTPMethod
    let parameters: [String: Any]?
    let requiresAuth: Bool
    let useBaseURL: Bool  // If true, use baseURL instead of fullAPIURL (for different namespaces like bmn-schools)
    let useMldNamespace: Bool  // If true, use /mld/v1/ namespace instead of /mld-mobile/v1/

    init(path: String, method: HTTPMethod = .get, parameters: [String: Any]? = nil, requiresAuth: Bool = false, useBaseURL: Bool = false, useMldNamespace: Bool = false) {
        self.path = path
        self.method = method
        self.parameters = parameters
        self.requiresAuth = requiresAuth
        self.useBaseURL = useBaseURL
        self.useMldNamespace = useMldNamespace
    }
}

// MARK: - Authentication Endpoints

extension APIEndpoint {
    static func login(email: String, password: String) -> APIEndpoint {
        APIEndpoint(
            path: "/auth/login",
            method: .post,
            parameters: ["email": email, "password": password],
            requiresAuth: false
        )
    }

    static func register(email: String, password: String, firstName: String, lastName: String, phone: String? = nil, referralCode: String? = nil) -> APIEndpoint {
        var params: [String: Any] = [
            "email": email,
            "password": password,
            "first_name": firstName,
            "last_name": lastName
        ]
        if let phone = phone, !phone.isEmpty {
            params["phone"] = phone
        }
        if let code = referralCode, !code.isEmpty {
            params["referral_code"] = code
        }
        return APIEndpoint(
            path: "/auth/register",
            method: .post,
            parameters: params,
            requiresAuth: false
        )
    }

    static func refreshToken(refreshToken: String) -> APIEndpoint {
        APIEndpoint(
            path: "/auth/refresh",
            method: .post,
            parameters: ["refresh_token": refreshToken],
            requiresAuth: false
        )
    }

    static var me: APIEndpoint {
        // CRITICAL: Add cache-busting to prevent CDN from caching user-specific response
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/auth/me?_nocache=\(timestamp)", requiresAuth: true)
    }

    static var logout: APIEndpoint {
        APIEndpoint(path: "/auth/logout", method: .post, requiresAuth: true)
    }

    /// Delete user account (Apple App Store Guideline 5.1.1(v) compliance)
    /// @since v203
    static var deleteAccount: APIEndpoint {
        APIEndpoint(path: "/auth/delete-account", method: .delete, requiresAuth: true)
    }

    static func forgotPassword(email: String) -> APIEndpoint {
        APIEndpoint(
            path: "/auth/forgot-password",
            method: .post,
            parameters: ["email": email],
            requiresAuth: false
        )
    }
}

// MARK: - Settings Endpoints

extension APIEndpoint {
    /// Get MLS disclosure settings (logo, text)
    static var disclosureSettings: APIEndpoint {
        APIEndpoint(path: "/settings/disclosure", requiresAuth: false)
    }

    /// Get site contact settings (default contact info for users without assigned agent)
    static var siteContactSettings: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/settings/site-contact?_nocache=\(timestamp)", requiresAuth: false)
    }
}

// MARK: - Property Endpoints

extension APIEndpoint {
    static func properties(filters: PropertySearchFilters) -> APIEndpoint {
        // Use the comprehensive toDictionary method from PropertySearchFilters
        let params = filters.toDictionary()
        return APIEndpoint(path: "/properties", parameters: params, requiresAuth: false)
    }

    static func propertyDetail(id: String) -> APIEndpoint {
        // Add cache-busting parameter to ensure fresh data
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/properties/\(id)?_nocache=\(timestamp)", requiresAuth: false)
    }

    static func propertyHistory(id: String) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/properties/\(id)/history?_nocache=\(timestamp)", requiresAuth: false)
    }

    /// Get address history (previous sales at same address) - v6.68.0
    static func addressHistory(id: String) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/properties/\(id)/address-history?_nocache=\(timestamp)", requiresAuth: false)
    }

    /// Get available filter options (v6.59.0)
    /// Returns available home types (property_sub_type values) based on listing type and property types
    static func filterOptions(listingType: String?, propertyTypes: [String]?) -> APIEndpoint {
        var params: [String: Any] = [:]
        if let listingType = listingType {
            params["listing_type"] = listingType
        }
        if let propertyTypes = propertyTypes, !propertyTypes.isEmpty {
            params["property_type"] = propertyTypes
        }
        return APIEndpoint(path: "/filter-options", parameters: params, requiresAuth: false)
    }
}

// MARK: - Favorites Endpoints

extension APIEndpoint {
    static var favorites: APIEndpoint {
        // Add cache-busting parameter to bypass CDN caching
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/favorites?_nocache=\(timestamp)", requiresAuth: true)
    }

    static func addFavorite(listingId: String) -> APIEndpoint {
        APIEndpoint(path: "/favorites/\(listingId)", method: .post, requiresAuth: true)
    }

    static func removeFavorite(listingId: String) -> APIEndpoint {
        APIEndpoint(path: "/favorites/\(listingId)", method: .delete, requiresAuth: true)
    }
}

// MARK: - Hidden Properties Endpoints

extension APIEndpoint {
    /// Get all hidden properties for the current user
    static var hidden: APIEndpoint {
        // Add cache-busting parameter to bypass CDN caching
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/hidden?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Hide a property
    static func hideProperty(listingId: String) -> APIEndpoint {
        APIEndpoint(path: "/hidden/\(listingId)", method: .post, requiresAuth: true)
    }

    /// Unhide a property
    static func unhideProperty(listingId: String) -> APIEndpoint {
        APIEndpoint(path: "/hidden/\(listingId)", method: .delete, requiresAuth: true)
    }
}

// MARK: - Recently Viewed Properties (v6.57.0)

extension APIEndpoint {
    /// Record a property view for analytics
    /// - Parameters:
    ///   - listingId: The MLS listing ID
    ///   - listingKey: Optional listing key hash
    ///   - viewSource: Source of view (search, saved_search, shared, notification, direct, favorites)
    static func recordRecentlyViewed(listingId: String, listingKey: String? = nil, viewSource: String = "search") -> APIEndpoint {
        var parameters: [String: Any] = [
            "listing_id": listingId,
            "view_source": viewSource,
            "platform": "ios"
        ]
        if let key = listingKey {
            parameters["listing_key"] = key
        }
        return APIEndpoint(path: "/recently-viewed", method: .post, parameters: parameters, requiresAuth: true)
    }
}

// MARK: - Agent Endpoints (Phase 5)

extension APIEndpoint {
    /// Get list of all active agents
    static var agents: APIEndpoint {
        APIEndpoint(path: "/agents", requiresAuth: false)
    }

    /// Get a specific agent by ID
    static func agentDetail(id: Int) -> APIEndpoint {
        APIEndpoint(path: "/agents/\(id)", requiresAuth: false)
    }

    /// Get the current client's assigned agent
    static var myAgent: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/my-agent?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Get user's email preferences
    static var emailPreferences: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/email-preferences?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Update user's email preferences
    static func updateEmailPreferences(
        digestEnabled: Bool?,
        digestFrequency: String?,
        digestTime: String?,
        globalPause: Bool?,
        timezone: String?
    ) -> APIEndpoint {
        var params: [String: Any] = [:]
        if let digestEnabled = digestEnabled { params["digest_enabled"] = digestEnabled }
        if let digestFrequency = digestFrequency { params["digest_frequency"] = digestFrequency }
        if let digestTime = digestTime { params["digest_time"] = digestTime }
        if let globalPause = globalPause { params["global_pause"] = globalPause }
        if let timezone = timezone { params["timezone"] = timezone }

        return APIEndpoint(
            path: "/email-preferences",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Get client's notification preferences (v6.48.0)
    static var notificationPreferences: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/notification-preferences?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Update client's notification preferences (v6.48.0)
    static func updateNotificationPreferences(
        notificationTypes: [String: [String: Bool]]?,
        quietHours: [String: Any]?,
        timezone: String?
    ) -> APIEndpoint {
        var params: [String: Any] = [:]
        if let notificationTypes = notificationTypes { params["notification_types"] = notificationTypes }
        if let quietHours = quietHours { params["quiet_hours"] = quietHours }
        if let timezone = timezone { params["timezone"] = timezone }

        return APIEndpoint(
            path: "/notification-preferences",
            method: .put,
            parameters: params,
            requiresAuth: true
        )
    }

    // MARK: - Badge Count Management (v6.49.0 / v179)

    /// Get current badge count for authenticated user
    static var badgeCount: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/badge-count?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Reset badge count to 0 (when user opens app or views notifications)
    static var resetBadgeCount: APIEndpoint {
        return APIEndpoint(
            path: "/badge-count/reset",
            method: .post,
            requiresAuth: true
        )
    }

    /// Get notification history for in-app notification center sync (v6.49.16 / v187)
    static func notificationHistory(limit: Int = 50, offset: Int = 0, since: Date? = nil) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        var path = "/notifications/history?limit=\(limit)&offset=\(offset)&_nocache=\(timestamp)"

        if let since = since {
            let formatter = ISO8601DateFormatter()
            path += "&since=\(formatter.string(from: since))"
        }

        return APIEndpoint(path: path, requiresAuth: true)
    }

    /// Mark a single notification as read (v6.50.0 / v192)
    static func markNotificationRead(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/notifications/\(id)/read",
            method: .post,
            requiresAuth: true
        )
    }

    /// Dismiss a notification (v6.50.0 / v192)
    static func dismissNotification(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/notifications/\(id)/dismiss",
            method: .post,
            requiresAuth: true
        )
    }

    /// Mark all notifications as read (v6.50.0 / v192)
    static var markAllNotificationsRead: APIEndpoint {
        return APIEndpoint(
            path: "/notifications/mark-all-read",
            method: .post,
            requiresAuth: true
        )
    }

    /// Dismiss all notifications (v6.50.3 / v195)
    static var dismissAllNotifications: APIEndpoint {
        return APIEndpoint(
            path: "/notifications/dismiss-all",
            method: .post,
            requiresAuth: true
        )
    }

    /// Track notification engagement (opened, dismissed, clicked) - v6.49.4
    static func trackNotificationEngagement(
        notificationType: String,
        action: String,
        listingId: String? = nil,
        savedSearchId: Int? = nil,
        appointmentId: Int? = nil
    ) -> APIEndpoint {
        var params: [String: Any] = [
            "notification_type": notificationType,
            "action": action,
            "platform": "ios"
        ]
        if let listingId = listingId { params["listing_id"] = listingId }
        if let savedSearchId = savedSearchId { params["saved_search_id"] = savedSearchId }
        if let appointmentId = appointmentId { params["appointment_id"] = appointmentId }

        // Add device info
        var systemInfo = utsname()
        uname(&systemInfo)
        let machineMirror = Mirror(reflecting: systemInfo.machine)
        let deviceModel = machineMirror.children.reduce("") { identifier, element in
            guard let value = element.value as? Int8, value != 0 else { return identifier }
            return identifier + String(UnicodeScalar(UInt8(value)))
        }
        params["device_model"] = deviceModel

        if let appVersion = Bundle.main.infoDictionary?["CFBundleVersion"] as? String {
            params["app_version"] = appVersion
        }

        return APIEndpoint(
            path: "/notifications/engagement",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    // MARK: - Agent Client Management (for agents)

    /// Get list of agent's clients
    static var agentClients: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/clients?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Get a specific client's details
    static func agentClientDetail(clientId: Int) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/clients/\(clientId)?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Create a new client
    static func createAgentClient(
        email: String,
        firstName: String,
        lastName: String?,
        phone: String?,
        sendNotification: Bool
    ) -> APIEndpoint {
        var params: [String: Any] = [
            "email": email,
            "first_name": firstName,
            "send_notification": sendNotification
        ]
        if let lastName = lastName { params["last_name"] = lastName }
        if let phone = phone { params["phone"] = phone }

        return APIEndpoint(
            path: "/agent/clients",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Get a client's saved searches
    static func agentClientSearches(clientId: Int) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/clients/\(clientId)/searches?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Get a client's favorites
    static func agentClientFavorites(clientId: Int) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/clients/\(clientId)/favorites?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Get a client's hidden properties
    static func agentClientHidden(clientId: Int) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/clients/\(clientId)/hidden?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Get agent metrics/stats
    static var agentMetrics: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/metrics?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Create saved searches for multiple clients (agent only)
    /// - Parameters:
    ///   - clientIds: Array of client IDs to create searches for
    ///   - name: Search name
    ///   - filters: Search filters dictionary
    ///   - notificationFrequency: How often to notify (daily, immediate, etc.)
    ///   - agentNotes: Optional note from agent
    ///   - ccAgentOnNotify: Whether to CC agent on client notifications
    static func createSearchesForClients(
        clientIds: [Int],
        name: String,
        filters: [String: Any],
        notificationFrequency: String,
        agentNotes: String?,
        ccAgentOnNotify: Bool
    ) -> APIEndpoint {
        var params: [String: Any] = [
            "client_ids": clientIds,
            "name": name,
            "filters": filters,
            "notification_frequency": notificationFrequency,
            "cc_agent_on_notify": ccAgentOnNotify
        ]
        if let notes = agentNotes, !notes.isEmpty {
            params["agent_notes"] = notes
        }
        return APIEndpoint(
            path: "/agent/searches/batch",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    // MARK: - Shared Properties (Agent-to-Client Property Sharing)

    /// Share properties with clients (agent only)
    /// - Parameters:
    ///   - clientIds: Array of client IDs to share with
    ///   - listingKeys: Array of listing keys to share
    ///   - note: Optional note from agent
    static func shareProperties(clientIds: [Int], listingKeys: [String], note: String?) -> APIEndpoint {
        var params: [String: Any] = [
            "client_ids": clientIds,
            "listing_keys": listingKeys
        ]
        if let note = note, !note.isEmpty {
            params["note"] = note
        }
        return APIEndpoint(
            path: "/shared-properties",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Get properties shared by agent (agent view)
    static var agentSharedProperties: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/shared-properties?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Get properties shared with me (client view)
    static var sharedProperties: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/shared-properties?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Update response to shared property (client only)
    /// - Parameters:
    ///   - id: Share ID
    ///   - response: Client's response (interested, not_interested)
    ///   - note: Optional note from client
    static func updateSharedProperty(id: Int, response: String, note: String?) -> APIEndpoint {
        var params: [String: Any] = ["response": response]
        if let note = note, !note.isEmpty {
            params["note"] = note
        }
        return APIEndpoint(
            path: "/shared-properties/\(id)",
            method: .put,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Dismiss a shared property (client only)
    static func dismissSharedProperty(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/shared-properties/\(id)",
            method: .delete,
            requiresAuth: true
        )
    }

    /// Revoke a share (agent only)
    static func revokeSharedProperty(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/shared-properties/\(id)",
            method: .delete,
            requiresAuth: true
        )
    }
}

// MARK: - Saved Search Endpoints

extension APIEndpoint {
    /// Get all saved searches for the current user
    static var savedSearches: APIEndpoint {
        // Add cache-busting parameter to bypass CDN caching
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/saved-searches?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Get a single saved search by ID
    static func getSavedSearch(id: Int) -> APIEndpoint {
        APIEndpoint(path: "/saved-searches/\(id)", requiresAuth: true)
    }

    /// Create a new saved search
    static func createSavedSearch(request: CreateSavedSearchRequest) -> APIEndpoint {
        var params: [String: Any] = [
            "name": request.name,
            "filters": request.filters,
            "notification_frequency": request.notificationFrequency.rawValue
        ]
        if let description = request.description {
            params["description"] = description
        }
        if let polygonShapes = request.polygonShapes {
            params["polygon_shapes"] = polygonShapes.map { polygon in
                polygon.map { ["lat": $0.lat, "lng": $0.lng] }
            }
        }

        return APIEndpoint(path: "/saved-searches", method: .post, parameters: params, requiresAuth: true)
    }

    /// Update an existing saved search
    static func updateSavedSearch(
        id: Int,
        name: String?,
        description: String?,
        filters: [String: Any]?,
        polygonShapes: [[PolygonPoint]]?,
        frequency: NotificationFrequency?,
        isActive: Bool?,
        updatedAt: Date
    ) -> APIEndpoint {
        var params: [String: Any] = [:]

        // Always include updated_at for conflict detection
        let formatter = ISO8601DateFormatter()
        params["updated_at"] = formatter.string(from: updatedAt)

        if let name = name { params["name"] = name }
        if let description = description { params["description"] = description }
        if let filters = filters { params["filters"] = filters }
        if let polygonShapes = polygonShapes {
            params["polygon_shapes"] = polygonShapes.map { polygon in
                polygon.map { ["lat": $0.lat, "lng": $0.lng] }
            }
        }
        if let frequency = frequency { params["notification_frequency"] = frequency.rawValue }
        if let isActive = isActive { params["is_active"] = isActive }

        return APIEndpoint(path: "/saved-searches/\(id)", method: .put, parameters: params, requiresAuth: true)
    }

    /// Delete a saved search
    static func deleteSavedSearch(id: Int) -> APIEndpoint {
        APIEndpoint(path: "/saved-searches/\(id)", method: .delete, requiresAuth: true)
    }
}

// MARK: - Device Token Endpoints (Push Notifications)

extension APIEndpoint {
    /// Register device for push notifications
    static func registerDevice(token: String, appVersion: String, deviceModel: String) -> APIEndpoint {
        APIEndpoint(
            path: "/devices",
            method: .post,
            parameters: [
                "device_token": token,
                "platform": "ios",
                "app_version": appVersion,
                "device_model": deviceModel
            ],
            requiresAuth: true
        )
    }

    /// Unregister device from push notifications
    static func unregisterDevice(token: String) -> APIEndpoint {
        APIEndpoint(path: "/devices/\(token)", method: .delete, requiresAuth: true)
    }
}

// MARK: - Analytics Endpoints

extension APIEndpoint {
    static var cities: APIEndpoint {
        APIEndpoint(path: "/analytics/cities", requiresAuth: false)
    }

    static func cityMarketSummary(city: String) -> APIEndpoint {
        APIEndpoint(path: "/analytics/city/\(city.addingPercentEncoding(withAllowedCharacters: .urlPathAllowed) ?? city)", requiresAuth: false)
    }

    static func marketTrends(city: String) -> APIEndpoint {
        APIEndpoint(path: "/analytics/trends/\(city.addingPercentEncoding(withAllowedCharacters: .urlPathAllowed) ?? city)", requiresAuth: false)
    }

    static var marketOverview: APIEndpoint {
        APIEndpoint(path: "/analytics/overview", requiresAuth: false)
    }

    /// Get neighborhood/city analytics for map price overlays
    static func neighborhoodAnalytics(bounds: MapBounds, propertyType: String = "Residential") -> APIEndpoint {
        let boundsString = "\(bounds.south),\(bounds.west),\(bounds.north),\(bounds.east)"
        return APIEndpoint(
            path: "/neighborhoods/analytics",
            parameters: ["bounds": boundsString, "property_type": propertyType],
            requiresAuth: false
        )
    }

    /// Get city-level market insights for property details (v6.73.0)
    /// Uses mld/v1 namespace (not mld-mobile/v1)
    static func cityMarketInsights(city: String) -> APIEndpoint {
        let encodedCity = city.addingPercentEncoding(withAllowedCharacters: .urlPathAllowed) ?? city
        return APIEndpoint(
            path: "/property-analytics/\(encodedCity)?tab=overview&lite=true",
            requiresAuth: false,
            useMldNamespace: true
        )
    }
}

// MARK: - CMA Endpoints

extension APIEndpoint {
    static var cmaSessions: APIEndpoint {
        APIEndpoint(path: "/cma/sessions", requiresAuth: true)
    }

    static func cmaSession(id: Int) -> APIEndpoint {
        APIEndpoint(path: "/cma/session/\(id)", requiresAuth: true)
    }

    /// Get CMA comparables and estimated value for a property
    /// - Parameter listingId: The listing_key (hash) or MLS number of the property
    static func propertyCMA(listingId: String) -> APIEndpoint {
        APIEndpoint(path: "/cma/property/\(listingId)", requiresAuth: false)
    }

    /// Generate a CMA PDF report for a property
    /// - Parameters:
    ///   - listingId: The listing_key (hash) or MLS number of the property
    ///   - preparedFor: Optional name of the person the report is prepared for
    ///   - selectedComparables: Optional array of comparable listing IDs to include in the PDF
    ///   - subjectCondition: The condition of the subject property (for relative adjustments)
    ///   - manualAdjustments: Per-comparable manual adjustments (condition, pool, waterfront)
    static func generateCMAPDF(
        listingId: String,
        preparedFor: String? = nil,
        selectedComparables: [String]? = nil,
        subjectCondition: String? = nil,
        manualAdjustments: [String: [String: Any]]? = nil
    ) -> APIEndpoint {
        var params: [String: Any] = ["listing_id": listingId]
        if let preparedFor = preparedFor {
            params["prepared_for"] = preparedFor
        }
        if let selectedComparables = selectedComparables {
            params["selected_comparables"] = selectedComparables
        }
        if let subjectCondition = subjectCondition {
            params["subject_condition"] = subjectCondition
        }
        if let manualAdjustments = manualAdjustments {
            params["manual_adjustments"] = manualAdjustments
        }
        return APIEndpoint(path: "/cma/generate-pdf", method: .post, parameters: params, requiresAuth: true)
    }

    /// Analyze property condition using AI (Claude Vision) - v6.75.0
    /// - Parameters:
    ///   - listingId: The listing_key (hash) or MLS number for caching
    ///   - photoUrls: Array of photo URLs to analyze (up to 5)
    ///   - forceRefresh: Whether to bypass cache and re-analyze
    static func analyzeCondition(listingId: String, photoUrls: [String], forceRefresh: Bool = false) -> APIEndpoint {
        var params: [String: Any] = [
            "listing_id": listingId,
            "photo_urls": photoUrls
        ]
        if forceRefresh {
            params["force_refresh"] = true
        }
        return APIEndpoint(path: "/cma/analyze-condition", method: .post, parameters: params, requiresAuth: true)
    }
}

// MARK: - Chatbot Endpoint

extension APIEndpoint {
    static func chatbotMessage(message: String, context: [String: Any]? = nil) -> APIEndpoint {
        var params: [String: Any] = ["message": message]
        if let context = context {
            params["context"] = context
        }
        return APIEndpoint(path: "/chatbot/message", method: .post, parameters: params, requiresAuth: false)
    }
}

// MARK: - Boundary Endpoints

extension APIEndpoint {
    /// Get boundary polygon for a location (city, neighborhood, or ZIP code)
    /// Returns GeoJSON geometry from OpenStreetMap Nominatim via server cache
    static func boundary(location: String, type: String = "city", parentCity: String? = nil) -> APIEndpoint {
        var params: [String: Any] = [
            "location": location,
            "type": type,
            "state": "Massachusetts"
        ]
        if let parentCity = parentCity {
            params["parent_city"] = parentCity
        }
        return APIEndpoint(path: "/boundaries/location", parameters: params, requiresAuth: false)
    }
}

// MARK: - Search & Filter Helper Endpoints

extension APIEndpoint {
    /// Get price distribution for histogram visualization
    static func priceDistribution(filters: PropertySearchFilters) -> APIEndpoint {
        var params: [String: Any] = [:]

        // Include filters that affect price distribution (but not price filters)
        if !filters.propertyTypes.isEmpty {
            var apiPropertyTypes: Set<String> = []
            for propType in filters.propertyTypes {
                apiPropertyTypes.formUnion(propType.apiPropertyTypes)
            }
            params["property_type"] = Array(apiPropertyTypes)
        } else {
            params["property_type"] = filters.listingType.propertyTypes
        }

        if !filters.beds.isEmpty, let minBeds = filters.beds.min() {
            params["beds"] = minBeds
        }

        if let baths = filters.minBaths {
            params["baths"] = baths
        }

        if !filters.cities.isEmpty {
            params["city"] = filters.cities.first
        }

        return APIEndpoint(path: "/filters/price-distribution", parameters: params, requiresAuth: false)
    }

    /// Get autocomplete suggestions for search
    /// Production API uses /search/autocomplete with 'term' parameter
    static func autocomplete(term: String) -> APIEndpoint {
        APIEndpoint(path: "/search/autocomplete", parameters: ["term": term], requiresAuth: false)
    }
}

// MARK: - Schools Endpoints (bmn-schools plugin)

extension APIEndpoint {
    /// Base path for schools API (different namespace from mld-mobile)
    private static let schoolsBasePath = "/bmn-schools/v1"

    /// Get schools near a property location
    /// Returns schools grouped by level with district info
    /// - Parameters:
    ///   - latitude: Property latitude
    ///   - longitude: Property longitude
    ///   - radius: Search radius in miles (used as fallback if no city)
    ///   - city: City name to filter schools (preferred over radius)
    static func propertySchools(latitude: Double, longitude: Double, radius: Double = 2.0, city: String? = nil) -> APIEndpoint {
        var params: [String: Any] = [
            "lat": latitude,
            "lng": longitude,
            "radius": radius
        ]
        if let city = city {
            params["city"] = city
        }
        return APIEndpoint(
            path: "\(schoolsBasePath)/property/schools",
            parameters: params,
            requiresAuth: false,
            useBaseURL: true  // Schools API uses different namespace
        )
    }

    /// Get schools for map display within bounds
    static func mapSchools(bounds: MapBounds, level: String? = nil) -> APIEndpoint {
        var params: [String: Any] = [
            "bounds": "\(bounds.south),\(bounds.west),\(bounds.north),\(bounds.east)"
        ]
        if let level = level {
            params["level"] = level
        }
        return APIEndpoint(
            path: "\(schoolsBasePath)/schools/map",
            parameters: params,
            requiresAuth: false,
            useBaseURL: true  // Schools API uses different namespace
        )
    }

    /// Get detailed school information
    static func schoolDetail(id: Int) -> APIEndpoint {
        APIEndpoint(
            path: "\(schoolsBasePath)/schools/\(id)",
            requiresAuth: false,
            useBaseURL: true  // Schools API uses different namespace
        )
    }

    /// Get school district for a point
    static func districtForPoint(latitude: Double, longitude: Double) -> APIEndpoint {
        APIEndpoint(
            path: "\(schoolsBasePath)/districts/for-point",
            parameters: ["lat": latitude, "lng": longitude],
            requiresAuth: false,
            useBaseURL: true  // Schools API uses different namespace
        )
    }

    /// Get glossary of education terms
    /// - Parameter term: Optional specific term to look up
    static func glossary(term: String? = nil) -> APIEndpoint {
        var params: [String: Any] = [:]
        if let term = term {
            params["term"] = term
        }
        return APIEndpoint(
            path: "\(schoolsBasePath)/glossary/",
            parameters: params.isEmpty ? nil : params,
            requiresAuth: false,
            useBaseURL: true
        )
    }
}

// MARK: - Appointment Endpoints (snab plugin)

extension APIEndpoint {
    /// Base path for appointments API (different namespace from mld-mobile)
    private static let appointmentsBasePath = "/snab/v1"

    /// Get all active appointment types
    static var appointmentTypes: APIEndpoint {
        // Add cache-busting parameter to avoid CDN caching stale responses
        let params: [String: Any] = ["_nocache": Int(Date().timeIntervalSince1970)]
        return APIEndpoint(
            path: "\(appointmentsBasePath)/appointment-types",
            parameters: params,
            requiresAuth: false,
            useBaseURL: true
        )
    }

    /// Get staff members
    /// - Parameter typeId: Optional appointment type ID to filter staff
    static func appointmentStaff(typeId: Int? = nil) -> APIEndpoint {
        var params: [String: Any] = [:]
        if let typeId = typeId {
            params["type_id"] = typeId
        }
        // Add cache-busting parameter
        params["_nocache"] = Int(Date().timeIntervalSince1970)
        return APIEndpoint(
            path: "\(appointmentsBasePath)/staff",
            parameters: params,
            requiresAuth: false,
            useBaseURL: true
        )
    }

    /// Get available time slots for booking
    /// - Parameters:
    ///   - startDate: Start date (Y-m-d format)
    ///   - endDate: End date (Y-m-d format)
    ///   - typeId: Appointment type ID
    ///   - staffId: Optional staff member ID
    static func appointmentAvailability(
        startDate: String,
        endDate: String,
        typeId: Int,
        staffId: Int? = nil
    ) -> APIEndpoint {
        var params: [String: Any] = [
            "start_date": startDate,
            "end_date": endDate,
            "type_id": typeId,
            "_nocache": Int(Date().timeIntervalSince1970)
        ]
        if let staffId = staffId {
            params["staff_id"] = staffId
        }
        return APIEndpoint(
            path: "\(appointmentsBasePath)/availability",
            parameters: params,
            requiresAuth: false,
            useBaseURL: true
        )
    }

    /// Create a new appointment (guest or authenticated)
    /// - Parameter request: Booking request with appointment details
    static func createAppointment(request: BookAppointmentRequest) -> APIEndpoint {
        var params: [String: Any] = [
            "appointment_type_id": request.appointmentTypeId,
            "staff_id": request.staffId,
            "date": request.date,
            "time": request.time
        ]

        // Guest booking fields
        if let clientName = request.clientName {
            params["client_name"] = clientName
        }
        if let clientEmail = request.clientEmail {
            params["client_email"] = clientEmail
        }
        if let clientPhone = request.clientPhone {
            params["client_phone"] = clientPhone
        }
        if let notes = request.notes {
            params["notes"] = notes
        }
        if let listingId = request.listingId {
            params["listing_id"] = listingId
        }
        if let propertyAddress = request.propertyAddress {
            params["property_address"] = propertyAddress
        }

        // Multi-attendee fields (v1.10.4)
        if let additionalClients = request.additionalClients, !additionalClients.isEmpty {
            params["additional_clients"] = additionalClients.map { client in
                var dict: [String: Any] = [
                    "name": client.name,
                    "email": client.email
                ]
                if let phone = client.phone {
                    dict["phone"] = phone
                }
                return dict
            }
        }
        if let ccEmails = request.ccEmails, !ccEmails.isEmpty {
            params["cc_emails"] = ccEmails
        }

        return APIEndpoint(
            path: "\(appointmentsBasePath)/appointments",
            method: .post,
            parameters: params,
            requiresAuth: false,  // Supports guest booking
            useBaseURL: true
        )
    }

    /// Get user's appointments (requires authentication)
    /// - Parameters:
    ///   - status: Filter by status (upcoming, past, cancelled)
    ///   - page: Page number for pagination
    ///   - perPage: Results per page
    static func userAppointments(
        status: String? = nil,
        page: Int = 1,
        perPage: Int = 20
    ) -> APIEndpoint {
        var params: [String: Any] = [
            "page": page,
            "per_page": perPage,
            "_nocache": Int(Date().timeIntervalSince1970)
        ]
        if let status = status {
            params["status"] = status
        }
        return APIEndpoint(
            path: "\(appointmentsBasePath)/appointments",
            parameters: params,
            requiresAuth: true,
            useBaseURL: true
        )
    }

    /// Get a single appointment by ID
    static func appointmentDetail(id: Int) -> APIEndpoint {
        APIEndpoint(
            path: "\(appointmentsBasePath)/appointments/\(id)",
            requiresAuth: true,
            useBaseURL: true
        )
    }

    /// Cancel an appointment
    /// - Parameters:
    ///   - id: Appointment ID
    ///   - reason: Optional cancellation reason
    static func cancelAppointment(id: Int, reason: String? = nil) -> APIEndpoint {
        var params: [String: Any]? = nil
        if let reason = reason {
            params = ["reason": reason]
        }
        return APIEndpoint(
            path: "\(appointmentsBasePath)/appointments/\(id)",
            method: .delete,
            parameters: params,
            requiresAuth: true,
            useBaseURL: true
        )
    }

    /// Reschedule an appointment
    /// - Parameters:
    ///   - id: Appointment ID
    ///   - newDate: New date (Y-m-d format)
    ///   - newTime: New time (H:i format)
    static func rescheduleAppointment(id: Int, newDate: String, newTime: String) -> APIEndpoint {
        APIEndpoint(
            path: "\(appointmentsBasePath)/appointments/\(id)/reschedule",
            method: .post,
            parameters: [
                "new_date": newDate,
                "new_time": newTime
            ],
            requiresAuth: true,
            useBaseURL: true
        )
    }

    /// Get available slots for rescheduling an appointment
    static func rescheduleSlots(appointmentId: Int, startDate: String, endDate: String) -> APIEndpoint {
        APIEndpoint(
            path: "\(appointmentsBasePath)/appointments/\(appointmentId)/reschedule-slots",
            parameters: [
                "start_date": startDate,
                "end_date": endDate,
                "_nocache": Int(Date().timeIntervalSince1970)
            ],
            requiresAuth: true,
            useBaseURL: true
        )
    }

    /// Get portal cancellation/reschedule policies
    static var appointmentPortalPolicy: APIEndpoint {
        let params: [String: Any] = ["_nocache": Int(Date().timeIntervalSince1970)]
        return APIEndpoint(
            path: "\(appointmentsBasePath)/portal/policy",
            parameters: params,
            requiresAuth: false,
            useBaseURL: true
        )
    }

    /// Register device token for appointment push notifications
    static func registerDeviceToken(token: String, isSandbox: Bool) -> APIEndpoint {
        return APIEndpoint(
            path: "\(appointmentsBasePath)/device-tokens",
            method: .post,
            parameters: [
                "device_token": token,
                "device_type": "ios",
                "is_sandbox": isSandbox
            ],
            requiresAuth: true,
            useBaseURL: true
        )
    }

    /// Unregister device token from appointment push notifications
    static func unregisterDeviceToken(token: String) -> APIEndpoint {
        return APIEndpoint(
            path: "\(appointmentsBasePath)/device-tokens",
            method: .delete,
            parameters: ["device_token": token],
            requiresAuth: true,
            useBaseURL: true
        )
    }

    /// Download ICS calendar file for an appointment
    static func downloadAppointmentICS(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "\(appointmentsBasePath)/appointments/\(id)/ics",
            requiresAuth: true,
            useBaseURL: true
        )
    }
}

// MARK: - MLD Device Token Endpoints (for saved search & property change notifications)

extension APIEndpoint {

    /// Register device token for MLD push notifications (saved searches, price changes, etc.)
    static func registerMLDDeviceToken(token: String, isSandbox: Bool) -> APIEndpoint {
        return APIEndpoint(
            path: "/device-tokens",
            method: .post,
            parameters: [
                "device_token": token,
                "device_type": "ios",
                "is_sandbox": isSandbox
            ],
            requiresAuth: true
        )
    }

    /// Unregister device token from MLD push notifications
    static func unregisterMLDDeviceToken(token: String) -> APIEndpoint {
        return APIEndpoint(
            path: "/device-tokens",
            method: .delete,
            parameters: ["device_token": token],
            requiresAuth: true
        )
    }
}

// MARK: - Client Analytics Endpoints (v6.37.0)

extension APIEndpoint {
    /// Record a single activity event
    /// - Parameters:
    ///   - sessionId: Client-generated session identifier
    ///   - activityType: Type of activity (property_view, search_run, filter_used, etc.)
    ///   - entityId: Optional ID of the entity (e.g., listing key for property_view)
    ///   - entityType: Optional type of entity (e.g., "property")
    ///   - metadata: Optional additional metadata dictionary
    ///   - platform: Platform identifier ("ios")
    ///   - deviceInfo: Optional device info string
    static func recordActivity(
        sessionId: String,
        activityType: String,
        entityId: String? = nil,
        entityType: String? = nil,
        metadata: [String: Any]? = nil,
        deviceInfo: String? = nil
    ) -> APIEndpoint {
        var params: [String: Any] = [
            "session_id": sessionId,
            "activity_type": activityType,
            "platform": "ios"
        ]
        if let entityId = entityId { params["entity_id"] = entityId }
        if let entityType = entityType { params["entity_type"] = entityType }
        if let metadata = metadata { params["metadata"] = metadata }
        if let deviceInfo = deviceInfo { params["device_info"] = deviceInfo }

        return APIEndpoint(
            path: "/analytics/activity",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Record batch of activity events
    /// - Parameter activities: Array of activity dictionaries
    static func recordBatchActivities(activities: [[String: Any]]) -> APIEndpoint {
        return APIEndpoint(
            path: "/analytics/activity/batch",
            method: .post,
            parameters: ["activities": activities],
            requiresAuth: true
        )
    }

    /// Start or end a session
    /// - Parameters:
    ///   - action: "start" or "end"
    ///   - sessionId: Client-generated session identifier
    ///   - deviceType: Device type string (e.g., "iPhone 16 Pro")
    ///   - appVersion: App version string
    static func sessionEvent(
        action: String,
        sessionId: String,
        deviceType: String? = nil,
        appVersion: String? = nil
    ) -> APIEndpoint {
        var params: [String: Any] = [
            "action": action,
            "session_id": sessionId,
            "platform": "ios"
        ]
        if let deviceType = deviceType { params["device_type"] = deviceType }
        if let appVersion = appVersion { params["app_version"] = appVersion }

        return APIEndpoint(
            path: "/analytics/session",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Get analytics summary for a specific client (agent only)
    /// - Parameters:
    ///   - clientId: Client's user ID
    ///   - days: Number of days to look back (default 30)
    static func clientAnalytics(clientId: Int, days: Int = 30) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(
            path: "/agent/clients/\(clientId)/analytics?days=\(days)&_nocache=\(timestamp)",
            requiresAuth: true
        )
    }

    /// Get activity timeline for a specific client (agent only)
    /// - Parameters:
    ///   - clientId: Client's user ID
    ///   - limit: Max activities to return
    ///   - offset: Pagination offset
    static func clientActivityTimeline(clientId: Int, limit: Int = 50, offset: Int = 0) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(
            path: "/agent/clients/\(clientId)/activity?limit=\(limit)&offset=\(offset)&_nocache=\(timestamp)",
            requiresAuth: true
        )
    }

    /// Get analytics dashboard for all agent's clients (agent only)
    /// - Parameter days: Number of days to look back (default 30)
    static func agentAnalyticsDashboard(days: Int = 30) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(
            path: "/agent/analytics?days=\(days)&_nocache=\(timestamp)",
            requiresAuth: true
        )
    }
}

// MARK: - App Lifecycle Endpoints

extension APIEndpoint {
    /// Report app opened (triggers agent notification for clients with 2-hour debounce)
    /// @since v207
    static var appOpened: APIEndpoint {
        return APIEndpoint(
            path: "/app/opened",
            method: .post,
            requiresAuth: true
        )
    }
}

// MARK: - Agent Referral Endpoints (v6.52.0 / v209)

extension APIEndpoint {
    /// Get agent's referral link and stats
    static var agentReferralLink: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/referral-link?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Update agent's custom referral code
    static func updateReferralCode(customCode: String) -> APIEndpoint {
        return APIEndpoint(
            path: "/agent/referral-link",
            method: .post,
            parameters: ["custom_code": customCode],
            requiresAuth: true
        )
    }

    /// Regenerate agent's referral code
    static var regenerateReferralCode: APIEndpoint {
        return APIEndpoint(
            path: "/agent/referral-link/regenerate",
            method: .post,
            requiresAuth: true
        )
    }

    /// Get agent's referral statistics
    static var agentReferralStats: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/agent/referral-stats?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Validate a referral code (public endpoint)
    static func validateReferralCode(code: String) -> APIEndpoint {
        return APIEndpoint(
            path: "/referral/validate",
            parameters: ["code": code],
            requiresAuth: false
        )
    }

    /// Register with referral code
    static func registerWithReferral(email: String, password: String, firstName: String, lastName: String, referralCode: String?) -> APIEndpoint {
        var params: [String: Any] = [
            "email": email,
            "password": password,
            "first_name": firstName,
            "last_name": lastName
        ]
        if let code = referralCode, !code.isEmpty {
            params["referral_code"] = code
        }
        return APIEndpoint(
            path: "/auth/register",
            method: .post,
            parameters: params,
            requiresAuth: false
        )
    }
}

// MARK: - Exclusive Listings Endpoints (Agent-created non-MLS listings)

extension APIEndpoint {
    /// Get list of agent's exclusive listings
    /// - Parameters:
    ///   - page: Page number (1-based)
    ///   - perPage: Results per page (max 100)
    ///   - status: Optional status filter (Active, Pending, Closed)
    static func exclusiveListings(page: Int = 1, perPage: Int = 20, status: String? = nil) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        var params: [String: Any] = [
            "page": page,
            "per_page": perPage,
            "_nocache": timestamp
        ]
        if let status = status {
            params["status"] = status
        }
        return APIEndpoint(path: "/exclusive-listings", parameters: params, requiresAuth: true)
    }

    /// Get a single exclusive listing by ID
    static func exclusiveListingDetail(id: Int) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/exclusive-listings/\(id)?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Create a new exclusive listing
    static func createExclusiveListing(data: [String: Any]) -> APIEndpoint {
        return APIEndpoint(
            path: "/exclusive-listings",
            method: .post,
            parameters: data,
            requiresAuth: true
        )
    }

    /// Update an existing exclusive listing
    static func updateExclusiveListing(id: Int, data: [String: Any]) -> APIEndpoint {
        return APIEndpoint(
            path: "/exclusive-listings/\(id)",
            method: .put,
            parameters: data,
            requiresAuth: true
        )
    }

    /// Delete an exclusive listing
    /// - Parameters:
    ///   - id: Listing ID
    ///   - archive: If true, archives instead of permanent delete (default true)
    static func deleteExclusiveListing(id: Int, archive: Bool = true) -> APIEndpoint {
        var params: [String: Any]? = nil
        if !archive {
            params = ["archive": "false"]
        }
        return APIEndpoint(
            path: "/exclusive-listings/\(id)",
            method: .delete,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Get photos for an exclusive listing
    static func exclusiveListingPhotos(id: Int) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/exclusive-listings/\(id)/photos?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Delete a photo from an exclusive listing
    static func deleteExclusiveListingPhoto(listingId: Int, photoId: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/exclusive-listings/\(listingId)/photos/\(photoId)",
            method: .delete,
            requiresAuth: true
        )
    }

    /// Reorder photos for an exclusive listing
    static func reorderExclusiveListingPhotos(listingId: Int, order: [Int]) -> APIEndpoint {
        return APIEndpoint(
            path: "/exclusive-listings/\(listingId)/photos/order",
            method: .put,
            parameters: ["order": order],
            requiresAuth: true
        )
    }

    /// Get valid options for exclusive listings (property types, statuses, etc.)
    static var exclusiveListingOptions: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/exclusive-listings/options?_nocache=\(timestamp)", requiresAuth: true)
    }
}

// MARK: - Open House Sign-In Endpoints (v6.69.0)

extension APIEndpoint {
    /// Get list of agent's open houses
    static var openHouses: APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/open-houses?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Get single open house with attendees
    static func openHouseDetail(id: Int) -> APIEndpoint {
        let timestamp = Int(Date().timeIntervalSince1970)
        return APIEndpoint(path: "/open-houses/\(id)?_nocache=\(timestamp)", requiresAuth: true)
    }

    /// Create a new open house
    static func createOpenHouse(request: CreateOpenHouseRequest) -> APIEndpoint {
        // Encode the request to a dictionary
        let encoder = JSONEncoder()
        encoder.keyEncodingStrategy = .convertToSnakeCase
        var params: [String: Any] = [:]
        if let data = try? encoder.encode(request),
           let dict = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
            params = dict
        }
        return APIEndpoint(
            path: "/open-houses",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Update an existing open house
    static func updateOpenHouse(id: Int, request: CreateOpenHouseRequest) -> APIEndpoint {
        let encoder = JSONEncoder()
        encoder.keyEncodingStrategy = .convertToSnakeCase
        var params: [String: Any] = [:]
        if let data = try? encoder.encode(request),
           let dict = try? JSONSerialization.jsonObject(with: data) as? [String: Any] {
            params = dict
        }
        return APIEndpoint(
            path: "/open-houses/\(id)",
            method: .put,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Delete an open house
    static func deleteOpenHouse(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/\(id)",
            method: .delete,
            requiresAuth: true
        )
    }

    /// Start an open house (mark as active)
    static func startOpenHouse(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/\(id)/start",
            method: .post,
            requiresAuth: true
        )
    }

    /// End an open house (mark as completed)
    static func endOpenHouse(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/\(id)/end",
            method: .post,
            requiresAuth: true
        )
    }

    /// Get property images for kiosk slideshow (v6.71.0)
    static func openHousePropertyImages(id: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/\(id)/property-images",
            method: .get,
            requiresAuth: true
        )
    }

    /// Add a single attendee to an open house
    static func addAttendee(openHouseId: Int, attendee: OpenHouseAttendee) -> APIEndpoint {
        var params: [String: Any] = [
            "local_uuid": attendee.localUUID.uuidString,
            "first_name": attendee.firstName,
            "last_name": attendee.lastName,
            "email": attendee.email,
            "phone": attendee.phone,
            "working_with_agent": attendee.workingWithAgent.rawValue,
            "buying_timeline": attendee.buyingTimeline.rawValue,
            "pre_approved": attendee.preApproved.rawValue,
            "consent_to_follow_up": attendee.consentToFollowUp,
            "consent_to_email": attendee.consentToEmail,
            "consent_to_text": attendee.consentToText,
            "interest_level": attendee.interestLevel.rawValue
        ]

        // Optional fields
        if let agentName = attendee.otherAgentName {
            params["other_agent_name"] = agentName
        }
        if let brokerage = attendee.otherAgentBrokerage {
            params["other_agent_brokerage"] = brokerage
        }
        // v6.71.0: Enhanced agent contact fields
        if let agentPhone = attendee.otherAgentPhone {
            params["agent_phone"] = agentPhone
        }
        if let agentEmail = attendee.otherAgentEmail {
            params["agent_email"] = agentEmail
        }
        if let lender = attendee.lenderName {
            params["lender_name"] = lender
        }
        if let howHeard = attendee.howHeardAbout {
            params["how_heard_about"] = howHeard.rawValue
        }
        if let notes = attendee.agentNotes {
            params["agent_notes"] = notes
        }
        // v6.71.0: MA Disclosure acknowledgment
        params["ma_disclosure_acknowledged"] = attendee.maDisclosureAcknowledged

        // v6.70.0: Agent visitor fields
        params["is_agent"] = attendee.isAgent
        if let visitorBrokerage = attendee.visitorAgentBrokerage {
            params["agent_brokerage"] = visitorBrokerage
        }
        if let purpose = attendee.agentVisitPurpose {
            params["agent_visit_purpose"] = purpose.rawValue
        }
        if let hasBuyer = attendee.agentHasBuyer {
            params["agent_has_buyer"] = hasBuyer
        }
        if let buyerTimeline = attendee.agentBuyerTimeline {
            params["agent_buyer_timeline"] = buyerTimeline
        }
        if let networkInterest = attendee.agentNetworkInterest {
            params["agent_network_interest"] = networkInterest
        }

        // Format signed_in_at as ISO 8601
        let formatter = ISO8601DateFormatter()
        params["signed_in_at"] = formatter.string(from: attendee.signedInAt)

        // v6.72.0: Pass device token to exclude from push notification
        // This prevents the kiosk device from receiving its own sign-in notification
        // Note: deviceToken is passed in by the caller since PushNotificationManager is @MainActor isolated
        if let deviceToken = attendee.deviceTokenForExclusion {
            params["exclude_device_token"] = deviceToken
        }

        return APIEndpoint(
            path: "/open-houses/\(openHouseId)/attendees",
            method: .post,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Bulk sync offline attendees
    static func bulkSyncAttendees(openHouseId: Int, attendees: [OpenHouseAttendee]) -> APIEndpoint {
        let attendeeData = attendees.map { attendee -> [String: Any] in
            var params: [String: Any] = [
                "local_uuid": attendee.localUUID.uuidString,
                "first_name": attendee.firstName,
                "last_name": attendee.lastName,
                "email": attendee.email,
                "phone": attendee.phone,
                "working_with_agent": attendee.workingWithAgent.rawValue,
                "buying_timeline": attendee.buyingTimeline.rawValue,
                "pre_approved": attendee.preApproved.rawValue,
                "consent_to_follow_up": attendee.consentToFollowUp,
                "consent_to_email": attendee.consentToEmail,
                "consent_to_text": attendee.consentToText,
                "interest_level": attendee.interestLevel.rawValue
            ]

            // Optional fields
            if let agentName = attendee.otherAgentName {
                params["other_agent_name"] = agentName
            }
            if let brokerage = attendee.otherAgentBrokerage {
                params["other_agent_brokerage"] = brokerage
            }
            // v6.71.0: Enhanced agent contact fields
            if let agentPhone = attendee.otherAgentPhone {
                params["agent_phone"] = agentPhone
            }
            if let agentEmail = attendee.otherAgentEmail {
                params["agent_email"] = agentEmail
            }
            if let lender = attendee.lenderName {
                params["lender_name"] = lender
            }
            if let howHeard = attendee.howHeardAbout {
                params["how_heard_about"] = howHeard.rawValue
            }
            if let notes = attendee.agentNotes {
                params["agent_notes"] = notes
            }
            // v6.71.0: MA Disclosure acknowledgment
            params["ma_disclosure_acknowledged"] = attendee.maDisclosureAcknowledged

            // v6.70.0: Agent visitor fields
            params["is_agent"] = attendee.isAgent
            if let visitorBrokerage = attendee.visitorAgentBrokerage {
                params["agent_brokerage"] = visitorBrokerage
            }
            if let purpose = attendee.agentVisitPurpose {
                params["agent_visit_purpose"] = purpose.rawValue
            }
            if let hasBuyer = attendee.agentHasBuyer {
                params["agent_has_buyer"] = hasBuyer
            }
            if let buyerTimeline = attendee.agentBuyerTimeline {
                params["agent_buyer_timeline"] = buyerTimeline
            }
            if let networkInterest = attendee.agentNetworkInterest {
                params["agent_network_interest"] = networkInterest
            }

            let formatter = ISO8601DateFormatter()
            params["signed_in_at"] = formatter.string(from: attendee.signedInAt)

            return params
        }

        return APIEndpoint(
            path: "/open-houses/\(openHouseId)/attendees/bulk",
            method: .post,
            parameters: ["attendees": attendeeData],
            requiresAuth: true
        )
    }

    /// Update attendee (interest level, notes)
    static func updateAttendee(openHouseId: Int, attendeeId: Int, interestLevel: InterestLevel?, notes: String?) -> APIEndpoint {
        var params: [String: Any] = [:]
        if let level = interestLevel {
            params["interest_level"] = level.rawValue
        }
        if let notes = notes {
            params["agent_notes"] = notes
        }
        return APIEndpoint(
            path: "/open-houses/\(openHouseId)/attendees/\(attendeeId)",
            method: .put,
            parameters: params,
            requiresAuth: true
        )
    }

    /// Export attendees as CSV
    static func exportAttendees(openHouseId: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/\(openHouseId)/export",
            requiresAuth: true
        )
    }

    /// Get nearby properties for quick property selection
    static func nearbyProperties(latitude: Double, longitude: Double, radius: Double = 0.5) -> APIEndpoint {
        return APIEndpoint(
            path: "/properties/nearby?lat=\(latitude)&lng=\(longitude)&radius=\(radius)",
            requiresAuth: true
        )
    }

    // MARK: - Open House CRM Integration (v6.70.0)

    /// Convert an attendee to a CRM client
    static func convertAttendeeToClient(attendeeId: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/attendees/\(attendeeId)/convert-to-client",
            method: .post,
            requiresAuth: true
        )
    }

    /// Get CRM status for an attendee
    static func attendeeCRMStatus(attendeeId: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/attendees/\(attendeeId)/crm-status",
            requiresAuth: true
        )
    }

    /// Get attendee visit history (all open houses this email has attended)
    static func attendeeHistory(attendeeId: Int) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/attendees/\(attendeeId)/history",
            requiresAuth: true
        )
    }

    /// Fetch open house detail with filtering and sorting (v6.70.0)
    static func openHouseDetailFiltered(id: Int, filter: String, sortBy: String) -> APIEndpoint {
        return APIEndpoint(
            path: "/open-houses/\(id)?filter=\(filter)&sort=\(sortBy)",
            requiresAuth: true
        )
    }
}
