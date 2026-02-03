//
//  GeocodingService.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Provides geocoding for location-based search with caching
//

import Foundation
import CoreLocation
import MapKit
import os.log

/// Service for geocoding location names to map regions using Apple's CLGeocoder API
actor GeocodingService {
    static let shared = GeocodingService()

    private let geocoder = CLGeocoder()
    private var cache: [String: MKCoordinateRegion] = [:]
    private let logger = Logger(subsystem: "com.bmnboston.app", category: "GeocodingService")

    private init() {}

    /// Get the appropriate map region for a location based on its type
    /// - Parameters:
    ///   - location: The location string (city name, ZIP code, neighborhood, etc.)
    ///   - type: The type of location (city, neighborhood, zip, address)
    /// - Returns: An MKCoordinateRegion centered on the location with appropriate zoom level
    func regionForLocation(_ location: String, type: SearchSuggestion.SuggestionType) async -> MKCoordinateRegion? {
        let cacheKey = "\(type.rawValue):\(location)"

        // Check cache first
        if let cached = cache[cacheKey] {
            logger.debug("Cache hit for \(cacheKey)")
            return cached
        }

        // Format the search query based on type
        let searchText = formatSearchQuery(location, type: type)
        logger.debug("Geocoding: '\(searchText)' for type: \(type.rawValue)")

        do {
            let placemarks = try await geocoder.geocodeAddressString(searchText)

            guard let placemark = placemarks.first,
                  let location = placemark.location else {
                logger.warning("No geocoding results for: \(searchText)")
                return nil
            }

            // Calculate appropriate span based on type
            let span = spanForType(type, placemark: placemark)
            let region = MKCoordinateRegion(center: location.coordinate, span: span)

            // Cache the result
            cache[cacheKey] = region
            logger.info("Geocoded '\(searchText)' to (\(location.coordinate.latitude), \(location.coordinate.longitude))")

            return region

        } catch {
            logger.error("Geocoding failed for '\(searchText)': \(error.localizedDescription)")
            return nil
        }
    }

    /// Format the search query to get better geocoding results
    private func formatSearchQuery(_ location: String, type: SearchSuggestion.SuggestionType) -> String {
        switch type {
        case .city:
            // Add state for better accuracy
            return "\(location), Massachusetts"
        case .neighborhood:
            // Neighborhoods are typically in Boston
            return "\(location), Boston, Massachusetts"
        case .zip:
            // ZIP codes work well on their own, but add state for accuracy
            return "\(location), MA"
        case .address:
            // Addresses should include the full string, maybe add state if not present
            if location.lowercased().contains("ma") || location.lowercased().contains("massachusetts") {
                return location
            }
            return "\(location), Massachusetts"
        case .streetName:
            // Street name search - append city/state context
            return "\(location), Massachusetts"
        case .mlsNumber:
            // MLS numbers don't need geocoding
            return location
        }
    }

    /// Get the appropriate map span (zoom level) for a location type
    private func spanForType(_ type: SearchSuggestion.SuggestionType, placemark: CLPlacemark) -> MKCoordinateSpan {
        switch type {
        case .city:
            // City-level view - show most of the city
            return MKCoordinateSpan(latitudeDelta: 0.12, longitudeDelta: 0.12)
        case .neighborhood:
            // Neighborhood-level - more zoomed in
            return MKCoordinateSpan(latitudeDelta: 0.04, longitudeDelta: 0.04)
        case .zip:
            // ZIP code area - moderate zoom
            return MKCoordinateSpan(latitudeDelta: 0.06, longitudeDelta: 0.06)
        case .address, .streetName:
            // Address/Street-level - zoomed in
            return MKCoordinateSpan(latitudeDelta: 0.02, longitudeDelta: 0.02)
        case .mlsNumber:
            // Default fallback (shouldn't be used for MLS numbers)
            return MKCoordinateSpan(latitudeDelta: 0.1, longitudeDelta: 0.1)
        }
    }

    /// Clear the geocoding cache
    func clearCache() {
        cache.removeAll()
        logger.info("Geocoding cache cleared")
    }
}
