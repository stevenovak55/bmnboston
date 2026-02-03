//
//  SavedSearchService.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Service for managing saved searches with server synchronization
//

import Foundation
import CoreLocation

// MARK: - SavedSearchError

enum SavedSearchError: Error, LocalizedError {
    case notAuthenticated
    case serverConflict(serverVersion: SavedSearch)
    case notFound
    case networkError(Error)
    case decodingError(Error)
    case serverError(String)

    var errorDescription: String? {
        switch self {
        case .notAuthenticated:
            return "You must be logged in to manage saved searches"
        case .serverConflict:
            return "This search was modified on another device. Using the latest version."
        case .notFound:
            return "Saved search not found"
        case .networkError(let error):
            return "Network error: \(error.localizedDescription)"
        case .decodingError(let error):
            return "Failed to process server response: \(error.localizedDescription)"
        case .serverError(let message):
            return message
        }
    }
}

// MARK: - SavedSearchService

/// Actor-based service for managing saved searches with server synchronization
actor SavedSearchService {

    /// Shared instance
    static let shared = SavedSearchService()

    /// Last sync timestamp
    private var lastSyncTime: Date?

    /// Cached searches
    private var cachedSearches: [SavedSearch] = []

    /// Cache expiration (5 minutes)
    private let cacheExpiration: TimeInterval = 300

    private init() {}

    // MARK: - Fetch Operations

    /// Fetch all saved searches for the current user
    /// - Parameter forceRefresh: If true, bypasses cache
    /// - Returns: Array of saved searches
    func fetchSearches(forceRefresh: Bool = false) async throws -> [SavedSearch] {
        // Check if cache is valid
        if !forceRefresh,
           let lastSync = lastSyncTime,
           Date().timeIntervalSince(lastSync) < cacheExpiration,
           !cachedSearches.isEmpty {
            return cachedSearches
        }

        // Fetch from API
        let response: SavedSearchListResponse = try await APIClient.shared.request(.savedSearches)

        // Update cache
        cachedSearches = response.searches
        lastSyncTime = Date()

        return cachedSearches
    }

    /// Fetch a single saved search by ID
    /// - Parameter id: Search ID
    /// - Returns: The saved search
    func fetchSearch(id: Int) async throws -> SavedSearch {
        let search: SavedSearch = try await APIClient.shared.request(.getSavedSearch(id: id))
        return search
    }

    // MARK: - Create Operations

    /// Create a new saved search
    /// - Parameters:
    ///   - name: Search name
    ///   - description: Optional description
    ///   - filters: Property search filters
    ///   - shapes: All drawn polygon shapes (for multi-shape support)
    ///   - frequency: Notification frequency
    /// - Returns: The created saved search
    func createSearch(
        name: String,
        description: String?,
        filters: PropertySearchFilters,
        shapes: [[CLLocationCoordinate2D]] = [],
        frequency: NotificationFrequency
    ) async throws -> SavedSearch {
        // Build polygon shapes from multi-shape array or fall back to single polygon
        var polygonShapes: [[PolygonPoint]]? = nil
        if !shapes.isEmpty {
            // Multi-shape: convert all shapes
            polygonShapes = shapes.map { shape in
                shape.map { PolygonPoint(coordinate: $0) }
            }
        } else if let coords = filters.polygonCoordinates, !coords.isEmpty {
            // Single shape fallback
            polygonShapes = [coords.map { PolygonPoint(coordinate: $0) }]
        }

        let request = CreateSavedSearchRequest(
            name: name,
            description: description,
            filters: filters,
            polygonShapes: polygonShapes,
            notificationFrequency: frequency
        )

        // API returns SavedSearch directly in data field
        let savedSearch: SavedSearch = try await APIClient.shared.request(
            .createSavedSearch(request: request)
        )

        // Update cache
        cachedSearches.insert(savedSearch, at: 0)

        return savedSearch
    }

    // MARK: - Update Operations

    /// Update an existing saved search
    /// - Parameters:
    ///   - id: Search ID
    ///   - name: New name (optional)
    ///   - description: New description (optional)
    ///   - filters: New filters (optional)
    ///   - frequency: New notification frequency (optional)
    ///   - isActive: New active state (optional)
    ///   - currentUpdatedAt: Client's last known updated_at for conflict detection
    /// - Returns: The updated saved search
    /// - Throws: SavedSearchError.serverConflict if server has newer version
    func updateSearch(
        id: Int,
        name: String? = nil,
        description: String? = nil,
        filters: PropertySearchFilters? = nil,
        frequency: NotificationFrequency? = nil,
        isActive: Bool? = nil,
        currentUpdatedAt: Date
    ) async throws -> SavedSearch {
        var filterDict: [String: Any]? = nil
        var polygonShapes: [[PolygonPoint]]? = nil

        if let filters = filters {
            // Use web-compatible keys for cross-platform compatibility
            filterDict = filters.toSavedSearchDictionary()
            if let coords = filters.polygonCoordinates, !coords.isEmpty {
                polygonShapes = [coords.map { PolygonPoint(coordinate: $0) }]
            }
        }

        do {
            // API returns SavedSearch directly in data field
            let savedSearch: SavedSearch = try await APIClient.shared.request(
                .updateSavedSearch(
                    id: id,
                    name: name,
                    description: description,
                    filters: filterDict,
                    polygonShapes: polygonShapes,
                    frequency: frequency,
                    isActive: isActive,
                    updatedAt: currentUpdatedAt
                )
            )

            // Update cache
            if let index = cachedSearches.firstIndex(where: { $0.id == id }) {
                cachedSearches[index] = savedSearch
            }

            return savedSearch
        } catch let error as APIError {
            // Handle conflict response (409)
            if case .serverError(let code, _) = error, code == "conflict" {
                // Fetch the latest version from server
                do {
                    let serverVersion = try await fetchSearch(id: id)
                    if let index = cachedSearches.firstIndex(where: { $0.id == id }) {
                        cachedSearches[index] = serverVersion
                    }
                    throw SavedSearchError.serverConflict(serverVersion: serverVersion)
                } catch let fetchError {
                    // If we can't fetch from server, try to use cached version
                    if let cachedVersion = cachedSearches.first(where: { $0.id == id }) {
                        throw SavedSearchError.serverConflict(serverVersion: cachedVersion)
                    }
                    // If not in cache either, re-throw the original fetch error
                    throw SavedSearchError.networkError(fetchError)
                }
            }
            throw error
        }
    }

    /// Toggle the active state of a saved search
    func toggleActive(id: Int, currentUpdatedAt: Date) async throws -> SavedSearch {
        guard let search = cachedSearches.first(where: { $0.id == id }) else {
            throw SavedSearchError.notFound
        }

        return try await updateSearch(
            id: id,
            isActive: !search.isActive,
            currentUpdatedAt: currentUpdatedAt
        )
    }

    /// Update notification frequency
    func updateNotificationFrequency(
        id: Int,
        frequency: NotificationFrequency,
        currentUpdatedAt: Date
    ) async throws -> SavedSearch {
        return try await updateSearch(
            id: id,
            frequency: frequency,
            currentUpdatedAt: currentUpdatedAt
        )
    }

    // MARK: - Delete Operations

    /// Delete a saved search (soft delete on server)
    /// - Parameter id: Search ID
    func deleteSearch(id: Int) async throws {
        try await APIClient.shared.requestWithoutResponse(.deleteSavedSearch(id: id))

        // Update cache
        cachedSearches.removeAll { $0.id == id }
    }

    // MARK: - Cache Management

    /// Clear the cached searches
    func clearCache() {
        cachedSearches = []
        lastSyncTime = nil
    }

    /// Get cached searches without fetching
    func getCachedSearches() -> [SavedSearch] {
        return cachedSearches
    }
}

// MARK: - JSONDecoder Extension

extension JSONDecoder {
    /// API decoder with date handling for saved searches
    static var apiDecoder: JSONDecoder {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .custom { decoder in
            let container = try decoder.singleValueContainer()
            let dateString = try container.decode(String.self)

            // Try ISO8601 first
            let isoFormatter = ISO8601DateFormatter()
            isoFormatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
            if let date = isoFormatter.date(from: dateString) {
                return date
            }

            // Try without fractional seconds
            isoFormatter.formatOptions = [.withInternetDateTime]
            if let date = isoFormatter.date(from: dateString) {
                return date
            }

            // Try MySQL datetime format
            let mysqlFormatter = DateFormatter()
            mysqlFormatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
            mysqlFormatter.timeZone = TimeZone(identifier: "UTC")
            if let date = mysqlFormatter.date(from: dateString) {
                return date
            }

            throw DecodingError.dataCorruptedError(
                in: container,
                debugDescription: "Cannot decode date: \(dateString)"
            )
        }
        return decoder
    }
}
