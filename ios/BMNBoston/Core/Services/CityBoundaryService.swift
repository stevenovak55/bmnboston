//
//  CityBoundaryService.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Provides city/neighborhood/ZIP code boundary polygons for map visualization
//

import Foundation
import MapKit
import os.log

/// Service for fetching and caching location boundary polygons
/// Fetches real GeoJSON boundaries from the API and converts to MKPolygon
actor CityBoundaryService {
    static let shared = CityBoundaryService()

    private var memoryCache: [String: MKPolygon] = [:]
    private let logger = Logger(subsystem: "com.bmnboston.app", category: "CityBoundaryService")

    /// UserDefaults key prefix for cached boundaries
    private let cacheKeyPrefix = "com.bmnboston.boundary."

    /// Cache expiry duration (7 days)
    private let cacheExpiryInterval: TimeInterval = 7 * 24 * 60 * 60

    private init() {}

    // MARK: - Public API

    /// Get a boundary polygon for a location
    /// - Parameters:
    ///   - location: The location name (e.g., "Boston", "Back Bay", "02101")
    ///   - type: Type of location: "city", "neighborhood", or "zipcode"
    ///   - parentCity: Parent city name (required for neighborhoods)
    /// - Returns: An MKPolygon representing the location boundary, or nil if not found
    func boundaryForLocation(_ location: String, type: String = "city", parentCity: String? = nil) async -> MKPolygon? {
        let cacheKey = makeCacheKey(location: location, type: type)

        // 1. Check memory cache
        if let cached = memoryCache[cacheKey] {
            logger.debug("Memory cache hit for \(type) boundary: \(location)")
            return cached
        }

        // 2. Check disk cache
        if let diskCached = loadFromDiskCache(cacheKey: cacheKey, location: location, type: type) {
            memoryCache[cacheKey] = diskCached
            logger.debug("Disk cache hit for \(type) boundary: \(location)")
            return diskCached
        }

        // 3. Fetch from API
        logger.info("Fetching \(type) boundary from API: \(location)")
        do {
            // APIClient.request() returns T directly (unwrapped from APIResponse)
            let boundaryData: BoundaryResponse = try await APIClient.shared.request(
                .boundary(location: location, type: type, parentCity: parentCity)
            )

            logger.info("Got boundary: geometry type=\(boundaryData.geometry.type), location=\(boundaryData.location)")
            let ring = boundaryData.geometry.coordinates.exteriorRing
            logger.info("Exterior ring has \(ring.count) points")

            let polygon = convertGeoJSONToPolygon(
                geometry: boundaryData.geometry,
                location: location,
                type: type
            )
            logger.info("Created polygon with \(polygon.pointCount) points for \(location)")

            // Cache the result
            memoryCache[cacheKey] = polygon
            saveToDiskCache(
                cacheKey: cacheKey,
                geometry: boundaryData.geometry,
                bbox: boundaryData.bbox,
                location: location,
                type: type
            )

            logger.info("Successfully fetched and cached \(type) boundary for \(location)")
            return polygon

        } catch {
            logger.warning("API fetch failed for \(location), using fallback: \(error.localizedDescription)")
            // 4. Fallback to circular approximation
            return await createCircularFallback(location: location, type: type)
        }
    }

    /// Get a boundary polygon for a city (convenience method)
    func boundaryForCity(_ city: String) async -> MKPolygon? {
        return await boundaryForLocation(city, type: "city")
    }

    /// Get boundaries for multiple cities
    /// - Parameter cities: Set of city names
    /// - Returns: Array of MKPolygon boundaries
    func boundariesForCities(_ cities: Set<String>) async -> [MKPolygon] {
        var polygons: [MKPolygon] = []

        for city in cities {
            if let polygon = await boundaryForCity(city) {
                polygons.append(polygon)
            }
        }

        return polygons
    }

    /// Get boundaries for multiple locations with types
    /// - Parameter locations: Array of (location, type, parentCity) tuples
    /// - Returns: Array of MKPolygon boundaries
    func boundariesForLocations(_ locations: [(location: String, type: String, parentCity: String?)]) async -> [MKPolygon] {
        var polygons: [MKPolygon] = []

        for loc in locations {
            if let polygon = await boundaryForLocation(loc.location, type: loc.type, parentCity: loc.parentCity) {
                polygons.append(polygon)
            }
        }

        return polygons
    }

    /// Clear the boundary cache (both memory and disk)
    func clearCache() {
        memoryCache.removeAll()

        // Clear disk cache
        let defaults = UserDefaults.standard
        let allKeys = defaults.dictionaryRepresentation().keys
        for key in allKeys where key.hasPrefix(cacheKeyPrefix) {
            defaults.removeObject(forKey: key)
        }

        logger.info("Boundary cache cleared")
    }

    // MARK: - GeoJSON Conversion

    /// Convert GeoJSON geometry to MKPolygon
    private func convertGeoJSONToPolygon(geometry: GeoJSONGeometry, location: String, type: String) -> MKPolygon {
        // Get exterior ring coordinates
        let ring = geometry.coordinates.exteriorRing

        // Convert [lon, lat] pairs to CLLocationCoordinate2D (lat, lon)
        var coordinates: [CLLocationCoordinate2D] = []
        for coord in ring {
            guard coord.count >= 2 else { continue }
            // GeoJSON format: [longitude, latitude]
            let longitude = coord[0]
            let latitude = coord[1]
            coordinates.append(CLLocationCoordinate2D(latitude: latitude, longitude: longitude))
        }

        let polygon = MKPolygon(coordinates: coordinates, count: coordinates.count)
        polygon.title = "\(type)Boundary"  // "cityBoundary", "neighborhoodBoundary", "zipcodeBoundary"
        polygon.subtitle = location
        return polygon
    }

    // MARK: - Caching

    private func makeCacheKey(location: String, type: String) -> String {
        return "\(type):\(location.lowercased())"
    }

    private func loadFromDiskCache(cacheKey: String, location: String, type: String) -> MKPolygon? {
        let defaults = UserDefaults.standard
        let fullKey = cacheKeyPrefix + cacheKey

        guard let data = defaults.data(forKey: fullKey) else {
            return nil
        }

        do {
            let cached = try JSONDecoder().decode(CachedBoundaryData.self, from: data)

            // Check if expired
            if cached.isExpired {
                defaults.removeObject(forKey: fullKey)
                return nil
            }

            // Reconstruct polygon from cached coordinates
            var coordinates: [CLLocationCoordinate2D] = []
            for i in stride(from: 0, to: cached.coordinates.count - 1, by: 2) {
                let longitude = cached.coordinates[i]
                let latitude = cached.coordinates[i + 1]
                coordinates.append(CLLocationCoordinate2D(latitude: latitude, longitude: longitude))
            }

            let polygon = MKPolygon(coordinates: coordinates, count: coordinates.count)
            polygon.title = "\(type)Boundary"
            polygon.subtitle = location
            return polygon

        } catch {
            logger.warning("Failed to decode cached boundary: \(error.localizedDescription)")
            defaults.removeObject(forKey: fullKey)
            return nil
        }
    }

    private func saveToDiskCache(cacheKey: String, geometry: GeoJSONGeometry, bbox: BoundingBox, location: String, type: String) {
        let ring = geometry.coordinates.exteriorRing

        // Flatten coordinates for storage: [lon1, lat1, lon2, lat2, ...]
        var flatCoords: [Double] = []
        for coord in ring {
            guard coord.count >= 2 else { continue }
            flatCoords.append(coord[0])  // longitude
            flatCoords.append(coord[1])  // latitude
        }

        let cached = CachedBoundaryData(
            geometryType: geometry.type,
            coordinates: flatCoords,
            bbox: CachedBoundingBox(
                north: bbox.north,
                south: bbox.south,
                east: bbox.east,
                west: bbox.west
            ),
            location: location,
            type: type,
            cachedAt: Date()
        )

        do {
            let data = try JSONEncoder().encode(cached)
            UserDefaults.standard.set(data, forKey: cacheKeyPrefix + cacheKey)
        } catch {
            logger.warning("Failed to cache boundary: \(error.localizedDescription)")
        }
    }

    // MARK: - Fallback (Circular Approximation)

    /// Create a circular approximation as fallback when API fails
    private func createCircularFallback(location: String, type: String) async -> MKPolygon? {
        logger.info("Creating circular fallback for \(location) (type=\(type))")
        // Only use fallback for cities (we have pre-defined radii)
        guard type == "city" else { return nil }

        let request = MKLocalSearch.Request()
        request.naturalLanguageQuery = "\(location), Massachusetts"
        request.resultTypes = .address

        let search = MKLocalSearch(request: request)

        do {
            let response = try await search.start()

            guard let item = response.mapItems.first else {
                logger.warning("No geocoding result for fallback: \(location)")
                return nil
            }

            let polygon = createApproximateBoundary(
                center: item.placemark.coordinate,
                location: location,
                type: type
            )
            return polygon

        } catch {
            logger.error("Fallback geocoding failed for \(location): \(error.localizedDescription)")
            return nil
        }
    }

    /// Create an approximate circular boundary for known Greater Boston cities
    private func createApproximateBoundary(center: CLLocationCoordinate2D, location: String, type: String) -> MKPolygon {
        // Approximate radii for Greater Boston cities (in meters)
        let approximateRadii: [String: CLLocationDistance] = [
            "boston": 8000,
            "cambridge": 4000,
            "brookline": 3500,
            "somerville": 3000,
            "newton": 5000,
            "quincy": 5500,
            "medford": 3500,
            "malden": 3000,
            "everett": 2500,
            "chelsea": 2000,
            "revere": 4000,
            "waltham": 4500,
            "watertown": 2500,
            "arlington": 3000,
            "belmont": 2500,
            "lexington": 4500,
            "woburn": 4000,
            "burlington": 3500,
            "needham": 4000,
            "wellesley": 4000,
            "natick": 4500,
            "framingham": 5500,
            "dedham": 3500,
            "milton": 4000,
            "braintree": 4500,
            "weymouth": 5000
        ]

        let radius = approximateRadii[location.lowercased()] ?? 4000  // Default 4km

        return createPolygonFromCircle(center: center, radius: radius, location: location, type: type)
    }

    /// Create a polygon approximating a circle
    private func createPolygonFromCircle(
        center: CLLocationCoordinate2D,
        radius: CLLocationDistance,
        location: String,
        type: String
    ) -> MKPolygon {
        var coordinates: [CLLocationCoordinate2D] = []
        let points = 36  // 10-degree increments for smooth circle

        for i in 0..<points {
            let angle = Double(i) * (360.0 / Double(points)) * .pi / 180

            // Convert radius to degrees (approximate)
            // 1 degree latitude ≈ 111km
            // 1 degree longitude ≈ 111km * cos(latitude)
            let latDelta = (radius / 111_000) * cos(angle)
            let lngDelta = (radius / (111_000 * cos(center.latitude * .pi / 180))) * sin(angle)

            let lat = center.latitude + latDelta
            let lng = center.longitude + lngDelta

            coordinates.append(CLLocationCoordinate2D(latitude: lat, longitude: lng))
        }

        let polygon = MKPolygon(coordinates: coordinates, count: coordinates.count)
        polygon.title = "\(type)Boundary"
        polygon.subtitle = location
        return polygon
    }
}
