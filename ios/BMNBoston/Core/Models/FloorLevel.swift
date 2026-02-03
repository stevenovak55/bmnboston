//
//  FloorLevel.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Interactive Floor Diagram feature
//

import Foundation

/// Represents a floor level in a property, with support for parsing various
/// level name formats from MLS data.
enum FloorLevel: Int, Comparable, CaseIterable, Hashable {
    case garage = -2       // Underground parking level
    case basement = -1
    case first = 1
    case second = 2
    case third = 3
    case fourth = 4
    case attic = 99

    // MARK: - Grade Properties

    /// Whether this level is above grade (ground level or higher)
    var isAboveGrade: Bool {
        rawValue >= 1
    }

    /// Whether this level is below grade (basement or garage)
    var isBelowGrade: Bool {
        rawValue < 1
    }

    /// Parse a level string from MLS data into a FloorLevel
    /// Handles various formats: "First", "1st", "Main", "Ground", "Entry", "Level 1", etc.
    static func from(levelString: String?) -> FloorLevel {
        guard let level = levelString?.trimmingCharacters(in: .whitespaces).lowercased() else {
            return .first // Default to first floor if no level specified
        }

        // Garage/Parking level variations
        if level.contains("garage") || level.contains("parking") || level == "p" || level == "-2" {
            return .garage
        }

        // Basement variations
        if level.contains("basement") || level.contains("lower") || level == "b" || level == "-1" {
            return .basement
        }

        // Attic variations
        if level.contains("attic") || level.contains("loft") || level == "a" {
            return .attic
        }

        // First floor variations
        if level.contains("first") || level.contains("main") || level.contains("ground") ||
           level.contains("entry") || level == "1" || level == "1st" || level == "one" ||
           level.contains("level 1") || level.contains("floor 1") {
            return .first
        }

        // Second floor variations
        if level.contains("second") || level == "2" || level == "2nd" || level == "two" ||
           level.contains("level 2") || level.contains("floor 2") {
            return .second
        }

        // Third floor variations
        if level.contains("third") || level == "3" || level == "3rd" || level == "three" ||
           level.contains("level 3") || level.contains("floor 3") {
            return .third
        }

        // Fourth floor variations
        if level.contains("fourth") || level == "4" || level == "4th" || level == "four" ||
           level.contains("level 4") || level.contains("floor 4") {
            return .fourth
        }

        // Try to extract a number from the string
        let digits = level.filter { $0.isNumber || $0 == "-" }
        if let number = Int(digits) {
            switch number {
            case -2: return .garage
            case -1, 0: return .basement
            case 1: return .first
            case 2: return .second
            case 3: return .third
            case 4: return .fourth
            default: return number >= 5 ? .attic : .first
            }
        }

        // Default to first floor
        return .first
    }

    /// Display name for the floor level
    var displayName: String {
        switch self {
        case .garage: return "Parking Level"
        case .basement: return "Lower Level"
        case .first: return "First Floor"
        case .second: return "Second Floor"
        case .third: return "Third Floor"
        case .fourth: return "Fourth Floor"
        case .attic: return "Attic"
        }
    }

    /// Short name for compact display (used in floor badge)
    var shortName: String {
        switch self {
        case .garage: return "P"
        case .basement: return "LL"
        case .first: return "1"
        case .second: return "2"
        case .third: return "3"
        case .fourth: return "4"
        case .attic: return "A"
        }
    }

    /// Comparison for sorting floors (basement at bottom, attic at top)
    static func < (lhs: FloorLevel, rhs: FloorLevel) -> Bool {
        lhs.rawValue < rhs.rawValue
    }
}

// MARK: - FloorData Helper

/// Groups rooms by floor level with computed summaries
struct FloorData: Identifiable {
    let level: FloorLevel
    let rooms: [Room]

    var id: Int { level.rawValue }

    /// Summary text like "2 bed, 1 bath" or "3 rooms"
    var roomSummary: String {
        let bedroomCount = rooms.filter { isRoomType($0, category: .bedroom) }.count
        let bathroomCount = rooms.filter { isRoomType($0, category: .bathroom) }.count

        if bedroomCount > 0 || bathroomCount > 0 {
            var parts: [String] = []
            if bedroomCount > 0 {
                parts.append("\(bedroomCount) bed")
            }
            if bathroomCount > 0 {
                parts.append("\(bathroomCount) bath")
            }
            return parts.joined(separator: ", ")
        } else {
            return "\(rooms.count) room\(rooms.count == 1 ? "" : "s")"
        }
    }

    /// Room types with their icons for display
    var roomTypeIcons: [(type: RoomCategory, count: Int)] {
        var counts: [RoomCategory: Int] = [:]
        for room in rooms {
            let category = categorizeRoom(room)
            counts[category, default: 0] += 1
        }
        // Return in display order, filtering out categories with 0 count
        return RoomCategory.allCases.compactMap { category in
            guard let count = counts[category], count > 0 else { return nil }
            return (category, count)
        }
    }

    private func isRoomType(_ room: Room, category: RoomCategory) -> Bool {
        categorizeRoom(room) == category
    }

    private func categorizeRoom(_ room: Room) -> RoomCategory {
        guard let type = room.type?.lowercased() else { return .other }

        // v6.68.19: Check bathroom FIRST to avoid "Master Bathroom" being categorized as bedroom
        if type.contains("bath") || type.contains("shower") || type.contains("powder") {
            return .bathroom
        }
        // v6.68.20: Added "suite" for "Primary Suite", "Master Suite" room types
        if type.contains("bed") || type.contains("master") || type.contains("primary") || type.contains("suite") {
            return .bedroom
        }
        if type.contains("kitchen") {
            return .kitchen
        }
        if type.contains("living") || type.contains("family") || type.contains("great") {
            return .livingRoom
        }
        if type.contains("dining") {
            return .diningRoom
        }
        if type.contains("office") || type.contains("study") || type.contains("den") {
            return .office
        }
        if type.contains("garage") {
            return .garage
        }
        if type.contains("laundry") || type.contains("utility") || type.contains("mud") {
            return .utility
        }
        if type.contains("basement") || type.contains("storage") {
            return .storage
        }

        return .other
    }
}

// MARK: - Room Category

/// Categories for room types with associated icons
enum RoomCategory: String, CaseIterable {
    case bedroom
    case bathroom
    case kitchen
    case livingRoom
    case diningRoom
    case office
    case garage
    case utility
    case storage
    case special     // v6.68.19: For special rooms (in-law, bonus room, media, gym, etc.)
    case other

    /// SF Symbol name for this room category
    var iconName: String {
        switch self {
        case .bedroom: return "bed.double.fill"
        case .bathroom: return "shower.fill"
        case .kitchen: return "fork.knife"
        case .livingRoom: return "sofa.fill"
        case .diningRoom: return "chair.lounge.fill"
        case .office: return "desktopcomputer"
        case .garage: return "car.fill"
        case .utility: return "washer.fill"
        case .storage: return "archivebox.fill"
        case .special: return "plus.rectangle.fill"
        case .other: return "square.fill"
        }
    }

    /// Display name for this category
    var displayName: String {
        switch self {
        case .bedroom: return "Bedroom"
        case .bathroom: return "Bathroom"
        case .kitchen: return "Kitchen"
        case .livingRoom: return "Living"
        case .diningRoom: return "Dining"
        case .office: return "Office"
        case .garage: return "Garage"
        case .utility: return "Utility"
        case .storage: return "Storage"
        case .special: return "Special"
        case .other: return "Room"
        }
    }
}
