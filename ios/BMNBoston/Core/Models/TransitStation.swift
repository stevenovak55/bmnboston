//
//  TransitStation.swift
//  BMNBoston
//
//  MBTA transit station model for map overlay
//

import Foundation
import CoreLocation
import UIKit

/// MBTA transit station for map display
struct TransitStation: Identifiable, Codable, Equatable {
    let id: String
    let name: String
    let type: TransitType
    let line: String?          // "Red Line", "Green Line", etc.
    let lat: Double
    let lng: Double

    var coordinate: CLLocationCoordinate2D {
        CLLocationCoordinate2D(latitude: lat, longitude: lng)
    }

    /// Display name with line info
    var displayName: String {
        if let line = line {
            return "\(name) (\(line))"
        }
        return name
    }

    private enum CodingKeys: String, CodingKey {
        case id, name, type, line, lat, lng
    }
}

/// Transit station type
enum TransitType: String, Codable, CaseIterable {
    case subway = "subway"
    case commuterRail = "commuter_rail"
    case bus = "bus"
    case ferry = "ferry"

    var displayName: String {
        switch self {
        case .subway: return "Subway"
        case .commuterRail: return "Commuter Rail"
        case .bus: return "Bus"
        case .ferry: return "Ferry"
        }
    }

    var icon: String {
        switch self {
        case .subway: return "tram.fill"
        case .commuterRail: return "train.side.front.car"
        case .bus: return "bus.fill"
        case .ferry: return "ferry.fill"
        }
    }
}

/// MBTA line colors
enum MBTALine: String, CaseIterable {
    case red = "Red Line"
    case orange = "Orange Line"
    case blue = "Blue Line"
    case greenB = "Green Line B"
    case greenC = "Green Line C"
    case greenD = "Green Line D"
    case greenE = "Green Line E"
    case silver = "Silver Line"
    case commuterRail = "Commuter Rail"

    var color: String {
        switch self {
        case .red: return "#DA291C"
        case .orange: return "#ED8B00"
        case .blue: return "#003DA5"
        case .greenB, .greenC, .greenD, .greenE: return "#00843D"
        case .silver: return "#7C878E"
        case .commuterRail: return "#80276C"
        }
    }

    /// Get line from string, handling variations
    static func from(_ string: String?) -> MBTALine? {
        guard let string = string else { return nil }
        let normalized = string.lowercased()

        if normalized.contains("red") { return .red }
        if normalized.contains("orange") { return .orange }
        if normalized.contains("blue") { return .blue }
        if normalized.contains("green") {
            if normalized.contains("b") { return .greenB }
            if normalized.contains("c") { return .greenC }
            if normalized.contains("d") { return .greenD }
            if normalized.contains("e") { return .greenE }
            return .greenB // Default green
        }
        if normalized.contains("silver") { return .silver }
        if normalized.contains("commuter") { return .commuterRail }

        // Match commuter rail line names
        let commuterRailLines = [
            "newburyport", "rockport", "haverhill", "lowell", "fitchburg",
            "worcester", "franklin", "providence", "stoughton", "middleborough",
            "lakeville", "kingston", "greenbush", "fairmount", "hub"
        ]
        for line in commuterRailLines {
            if normalized.contains(line) { return .commuterRail }
        }

        return nil
    }
}

/// Response from transit stations API
struct TransitStationsResponse: Codable {
    let stations: [TransitStation]
    let total: Int?
}

/// MBTA transit route for map overlay (polyline)
struct TransitRoute: Identifiable {
    let id: String
    let name: String
    let line: MBTALine
    let coordinates: [CLLocationCoordinate2D]

    /// Color for the route polyline
    var color: UIColor {
        switch line {
        case .red: return UIColor(red: 218/255, green: 41/255, blue: 28/255, alpha: 1)
        case .orange: return UIColor(red: 237/255, green: 139/255, blue: 0/255, alpha: 1)
        case .blue: return UIColor(red: 0/255, green: 61/255, blue: 165/255, alpha: 1)
        case .greenB, .greenC, .greenD, .greenE: return UIColor(red: 0/255, green: 132/255, blue: 61/255, alpha: 1)
        case .silver: return UIColor(red: 124/255, green: 135/255, blue: 142/255, alpha: 1)
        case .commuterRail: return UIColor(red: 128/255, green: 39/255, blue: 108/255, alpha: 1)
        }
    }

    /// Line width for the route
    var lineWidth: CGFloat {
        switch line {
        case .commuterRail: return 2.0
        default: return 4.0
        }
    }
}
