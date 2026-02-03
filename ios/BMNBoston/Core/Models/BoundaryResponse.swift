//
//  BoundaryResponse.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Models for GeoJSON boundary polygon API responses
//

import Foundation

/// Response from /boundaries/location endpoint
struct BoundaryResponse: Decodable {
    let geometry: GeoJSONGeometry
    let bbox: BoundingBox
    let displayName: String?
    let location: String
    let type: String

    enum CodingKeys: String, CodingKey {
        case geometry
        case bbox
        case displayName = "display_name"
        case location
        case type
    }
}

/// GeoJSON geometry object (Polygon or MultiPolygon)
struct GeoJSONGeometry: Decodable {
    let type: String  // "Polygon" or "MultiPolygon"
    let coordinates: GeoJSONCoordinates

    enum CodingKeys: String, CodingKey {
        case type
        case coordinates
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        type = try container.decode(String.self, forKey: .type)

        // GeoJSON coordinates format varies by geometry type:
        // - Polygon: [[[lon, lat], [lon, lat], ...]]  (array of rings, each ring is array of coordinate pairs)
        // - MultiPolygon: [[[[lon, lat], ...]]]  (array of polygons, each polygon has rings)

        if type == "Polygon" {
            // Polygon: [[[lon, lat], ...]]
            let polygonCoords = try container.decode([[[Double]]].self, forKey: .coordinates)
            coordinates = .polygon(polygonCoords)
        } else if type == "MultiPolygon" {
            // MultiPolygon: [[[[lon, lat], ...]]]
            let multiPolygonCoords = try container.decode([[[[Double]]]].self, forKey: .coordinates)
            coordinates = .multiPolygon(multiPolygonCoords)
        } else {
            throw DecodingError.dataCorruptedError(forKey: .type, in: container, debugDescription: "Unsupported geometry type: \(type)")
        }
    }
}

/// Flexible coordinates enum to handle both Polygon and MultiPolygon
enum GeoJSONCoordinates {
    case polygon([[[Double]]])       // Polygon: array of rings
    case multiPolygon([[[[Double]]]]) // MultiPolygon: array of polygons

    /// Get the exterior ring coordinates (for display purposes, uses the largest polygon for MultiPolygon)
    var exteriorRing: [[Double]] {
        switch self {
        case .polygon(let rings):
            // First ring is the exterior ring
            return rings.first ?? []

        case .multiPolygon(let polygons):
            // Find the polygon with the most points (likely the main shape)
            let largestPolygon = polygons.max { ($0.first?.count ?? 0) < ($1.first?.count ?? 0) }
            return largestPolygon?.first ?? []
        }
    }

    /// Get all exterior rings (for MultiPolygon, returns all polygon exteriors)
    var allExteriorRings: [[[Double]]] {
        switch self {
        case .polygon(let rings):
            // Return just the exterior ring (first ring)
            if let exterior = rings.first {
                return [exterior]
            }
            return []

        case .multiPolygon(let polygons):
            // Return exterior ring of each polygon
            return polygons.compactMap { polygon -> [[Double]]? in
                return polygon.first
            }
        }
    }
}

/// Bounding box for the geometry
struct BoundingBox: Decodable {
    let north: Double
    let south: Double
    let east: Double
    let west: Double

    /// Center coordinate of the bounding box
    var center: (latitude: Double, longitude: Double) {
        return (
            latitude: (north + south) / 2,
            longitude: (east + west) / 2
        )
    }

    /// Span of the bounding box
    var span: (latDelta: Double, lonDelta: Double) {
        return (
            latDelta: north - south,
            lonDelta: east - west
        )
    }
}

/// Cached boundary data for disk storage
struct CachedBoundaryData: Codable {
    let geometryType: String
    let coordinates: [Double]  // Flattened exterior ring: [lon1, lat1, lon2, lat2, ...]
    let bbox: CachedBoundingBox
    let location: String
    let type: String
    let cachedAt: Date

    var isExpired: Bool {
        // Expire after 7 days
        let expiryInterval: TimeInterval = 7 * 24 * 60 * 60
        return Date().timeIntervalSince(cachedAt) > expiryInterval
    }
}

struct CachedBoundingBox: Codable {
    let north: Double
    let south: Double
    let east: Double
    let west: Double
}
