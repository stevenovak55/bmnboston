//
//  SchoolService.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Service for fetching school data from BMN Schools API
//

import Foundation
import CoreLocation

/// Actor-based service for fetching school data
actor SchoolService {

    /// Shared instance
    static let shared = SchoolService()

    /// Cache for property schools (keyed by "lat,lng,radius")
    private var propertySchoolsCache: [String: (data: PropertySchoolsData, timestamp: Date)] = [:]

    /// Cache for map schools (keyed by bounds string)
    private var mapSchoolsCache: [String: (data: MapSchoolsData, timestamp: Date)] = [:]

    /// Cache expiration in seconds (15 minutes)
    private let cacheExpiration: TimeInterval = 900

    private init() {}

    // MARK: - Public Methods

    /// Fetch schools near a property location
    /// - Parameters:
    ///   - latitude: Property latitude
    ///   - longitude: Property longitude
    ///   - radius: Search radius in miles (default 5.0, used as fallback if no city)
    ///   - city: City name to filter schools (preferred over radius)
    /// - Returns: Schools data grouped by level with district info
    func fetchPropertySchools(
        latitude: Double,
        longitude: Double,
        radius: Double = 5.0,
        city: String? = nil
    ) async throws -> PropertySchoolsData {
        // Check cache
        let cacheKey = "\(String(format: "%.4f", latitude)),\(String(format: "%.4f", longitude)),\(city ?? "r\(radius)")"
        if let cached = propertySchoolsCache[cacheKey],
           Date().timeIntervalSince(cached.timestamp) < cacheExpiration {
            return cached.data
        }

        // Fetch from API - APIClient already unwraps the {success, data} wrapper
        let data: PropertySchoolsData = try await APIClient.shared.request(
            .propertySchools(latitude: latitude, longitude: longitude, radius: radius, city: city)
        )

        // Cache the result
        propertySchoolsCache[cacheKey] = (data: data, timestamp: Date())

        return data
    }

    /// Fetch schools for map display within bounds
    /// - Parameters:
    ///   - bounds: Map bounds (south, west, north, east)
    ///   - level: Optional school level filter (elementary, middle, high)
    /// - Returns: Schools data for map pins
    func fetchMapSchools(
        bounds: MapBounds,
        level: String? = nil
    ) async throws -> MapSchoolsData {
        // Check cache
        let cacheKey = "\(bounds.south),\(bounds.west),\(bounds.north),\(bounds.east),\(level ?? "all")"
        if let cached = mapSchoolsCache[cacheKey],
           Date().timeIntervalSince(cached.timestamp) < cacheExpiration {
            return cached.data
        }

        // Fetch from API - APIClient already unwraps the {success, data} wrapper
        let data: MapSchoolsData = try await APIClient.shared.request(
            .mapSchools(bounds: bounds, level: level)
        )

        // Cache the result
        mapSchoolsCache[cacheKey] = (data: data, timestamp: Date())

        return data
    }

    /// Fetch detailed school information
    /// - Parameter id: School ID
    /// - Returns: Full school detail with test scores and rankings
    func fetchSchoolDetail(id: Int) async throws -> SchoolDetail {
        // APIClient already unwraps the {success, data} wrapper
        let data: SchoolDetail = try await APIClient.shared.request(
            .schoolDetail(id: id)
        )
        return data
    }

    /// Clear all caches
    func clearCache() {
        propertySchoolsCache.removeAll()
        mapSchoolsCache.removeAll()
        glossaryCache = nil
    }

    // MARK: - Glossary

    /// Cached glossary data
    private var glossaryCache: (data: GlossaryResponse, timestamp: Date)?

    /// Cached individual term lookups
    private var termCache: [String: (data: GlossaryTerm, timestamp: Date)] = [:]

    /// Fetch a specific glossary term
    /// - Parameter term: The term key (e.g., "mcas", "masscore")
    /// - Returns: The glossary term with description and parent tip
    func fetchGlossaryTerm(_ term: String) async throws -> GlossaryTerm {
        // Check cache
        if let cached = termCache[term.lowercased()],
           Date().timeIntervalSince(cached.timestamp) < cacheExpiration {
            return cached.data
        }

        // Fetch from API
        let data: GlossaryTerm = try await APIClient.shared.request(
            .glossary(term: term)
        )

        // Cache result
        termCache[term.lowercased()] = (data: data, timestamp: Date())

        return data
    }

    /// Fetch all glossary terms
    /// - Returns: Complete glossary with all terms grouped by category
    func fetchGlossary() async throws -> GlossaryResponse {
        // Check cache
        if let cached = glossaryCache,
           Date().timeIntervalSince(cached.timestamp) < cacheExpiration {
            return cached.data
        }

        // Fetch from API
        let data: GlossaryResponse = try await APIClient.shared.request(
            .glossary()
        )

        // Cache result
        glossaryCache = (data: data, timestamp: Date())

        return data
    }

}

// MARK: - Errors

enum SchoolServiceError: LocalizedError {
    case apiFailed
    case noSchoolsFound
    case invalidCoordinates

    var errorDescription: String? {
        switch self {
        case .apiFailed:
            return "Failed to fetch school data"
        case .noSchoolsFound:
            return "No schools found in this area"
        case .invalidCoordinates:
            return "Invalid location coordinates"
        }
    }
}
