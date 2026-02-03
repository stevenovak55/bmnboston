//
//  FloorDiagramTypes.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Core types for the Smart Property-Aware Floor Diagram feature
//

import SwiftUI

// MARK: - Property Diagram Type

/// Determines the visual representation style based on property type
enum PropertyDiagramType: String, CaseIterable {
    case singleFamily = "single_family"
    case condo = "condo"
    case townhouse = "townhouse"
    case multiFamily = "multi_family"
    case land = "land"

    /// Detects the diagram type from property details
    static func from(propertySubtype: String?, isAttached: Bool = false, hasUnitRents: Bool = false) -> PropertyDiagramType {
        guard let subtype = propertySubtype?.lowercased() else {
            return .singleFamily
        }

        if subtype.contains("condo") || subtype.contains("condominium") {
            return .condo
        }
        if subtype.contains("townhouse") || subtype.contains("town house") || isAttached {
            return .townhouse
        }
        if subtype.contains("multi") || hasUnitRents {
            return .multiFamily
        }
        if subtype.contains("land") || subtype.contains("lot") {
            return .land
        }

        return .singleFamily
    }

    /// Display name for the property type
    var displayName: String {
        switch self {
        case .singleFamily: return "Single Family"
        case .condo: return "Condo"
        case .townhouse: return "Townhouse"
        case .multiFamily: return "Multi-Family"
        case .land: return "Land"
        }
    }
}

// MARK: - Roof Style

/// Determines the roof style based on property type and architectural style
enum RoofStyle: String, CaseIterable {
    case pitched = "pitched"       // Traditional house roof (single family default)
    case flat = "flat"             // Condo buildings, modern homes
    case narrowPitch = "narrow"    // Townhouse narrow peaked roof

    /// Detects roof style from property type and architectural style
    static func from(diagramType: PropertyDiagramType, architecturalStyle: String?) -> RoofStyle {
        let style = architecturalStyle?.lowercased() ?? ""

        switch diagramType {
        case .singleFamily:
            // Modern/Contemporary homes often have flat roofs
            if style.contains("modern") || style.contains("contemporary") {
                return .flat
            }
            return .pitched
        case .condo:
            return .flat
        case .townhouse:
            return .narrowPitch
        case .multiFamily:
            return .flat
        case .land:
            return .flat // No roof for land
        }
    }
}

// MARK: - Diagram Configuration

/// Configuration for rendering the property diagram
struct DiagramConfiguration {
    let diagramType: PropertyDiagramType
    let roofStyle: RoofStyle
    let garageSpaces: Int
    let hasAttachedGarage: Bool
    let stories: Int
    let aboveGradeArea: Int?
    let belowGradeArea: Int?
    let hasPool: Bool
    let hasFireplace: Bool
    let hasBasement: Bool
    let basementType: String?
    let architecturalStyle: String?
    let entryLevel: String?  // For condos: the floor level of the unit

    /// Creates a configuration from PropertyDetail
    init(
        propertySubtype: String? = nil,
        architecturalStyle: String? = nil,
        stories: Int? = nil,
        garageSpaces: Int? = nil,
        attachedGarageYn: Bool = false,
        parkingTotal: Int? = nil,
        aboveGradeFinishedArea: Int? = nil,
        belowGradeFinishedArea: Int? = nil,
        basement: String? = nil,
        hasPool: Bool = false,
        hasFireplace: Bool = false,
        isAttached: Bool = false,
        hasUnitRents: Bool = false,
        entryLevel: String? = nil
    ) {
        let type = PropertyDiagramType.from(
            propertySubtype: propertySubtype,
            isAttached: isAttached,
            hasUnitRents: hasUnitRents
        )

        self.diagramType = type
        self.roofStyle = RoofStyle.from(diagramType: type, architecturalStyle: architecturalStyle)
        self.garageSpaces = garageSpaces ?? 0
        self.hasAttachedGarage = attachedGarageYn
        self.stories = stories ?? 1
        self.aboveGradeArea = aboveGradeFinishedArea
        self.belowGradeArea = belowGradeFinishedArea
        self.hasPool = hasPool
        self.hasFireplace = hasFireplace
        self.basementType = basement
        self.architecturalStyle = architecturalStyle
        self.entryLevel = entryLevel

        // Determine if has basement from belowGradeArea or basement string
        self.hasBasement = (belowGradeFinishedArea ?? 0) > 0 ||
                          (basement?.lowercased().contains("finished") ?? false) ||
                          (basement?.lowercased().contains("walk") ?? false)
    }

    /// Returns a formatted display string for the entry level (for condos)
    var entryLevelDisplay: String? {
        guard let level = entryLevel, !level.isEmpty else { return nil }

        // Handle numeric levels
        if let levelNum = Int(level) {
            switch levelNum {
            case 1: return "1st Floor Unit"
            case 2: return "2nd Floor Unit"
            case 3: return "3rd Floor Unit"
            default: return "\(levelNum)th Floor Unit"
            }
        }

        // Handle string levels like "Ground", "Penthouse", etc.
        let lowerLevel = level.lowercased()
        if lowerLevel.contains("ground") || lowerLevel.contains("lobby") {
            return "Ground Floor Unit"
        }
        if lowerLevel.contains("penthouse") || lowerLevel.contains("top") {
            return "Penthouse Unit"
        }
        if lowerLevel.contains("basement") || lowerLevel.contains("lower") {
            return "Lower Level Unit"
        }

        return "Floor \(level) Unit"
    }

    /// Whether to show garage in the diagram
    var shouldShowGarage: Bool {
        garageSpaces > 0
    }

    /// Whether to show below grade section in the diagram
    var shouldShowBelowGrade: Bool {
        hasBasement || (belowGradeArea ?? 0) > 0
    }

    /// Whether to show the area breakdown pills
    var shouldShowAreaBreakdown: Bool {
        (aboveGradeArea ?? 0) > 0 || (belowGradeArea ?? 0) > 0
    }

    /// Garage position relative to building
    var garagePosition: GaragePosition {
        if diagramType == .condo {
            return .underground
        }
        return hasAttachedGarage ? .attached : .detached
    }
}

// MARK: - Garage Position

/// Where the garage is positioned relative to the main building
enum GaragePosition: String {
    case attached = "attached"     // Side of house
    case detached = "detached"     // Separate structure
    case underground = "underground" // Below building (condos)
}

// MARK: - Building Component Colors

/// Extended color palette for smart floor diagrams
extension FloorDiagramColors {

    // MARK: - Garage Colors

    /// Background color for garage area
    static var garageBackground: Color {
        Color.gray.opacity(0.12)
    }

    /// Garage door color
    static var garageDoorColor: Color {
        Color.gray.opacity(0.3)
    }

    /// Garage door panel accent
    static var garageDoorPanelColor: Color {
        Color.gray.opacity(0.5)
    }

    /// Garage door window
    static var garageDoorWindowColor: Color {
        Color.blue.opacity(0.15)
    }

    // MARK: - Below Grade Colors

    /// Below grade/basement background - earthy tone
    static var belowGradeBackground: Color {
        Color(red: 0.45, green: 0.38, blue: 0.30).opacity(0.20)
    }

    /// Below grade/basement accent
    static var belowGradeAccent: Color {
        Color(red: 0.55, green: 0.45, blue: 0.35).opacity(0.35)
    }

    // MARK: - Area Indicator Colors

    /// Above grade indicator - green tint
    static var aboveGradeIndicator: Color {
        Color.green.opacity(0.85)
    }

    /// Below grade indicator - brown/earthy
    static var belowGradeIndicator: Color {
        Color(red: 0.6, green: 0.45, blue: 0.3)
    }

    // MARK: - Condo/Townhouse Colors

    /// Window grid color for condo buildings
    static var windowGridColor: Color {
        Color.blue.opacity(0.15)
    }

    /// HVAC equipment color for flat roofs
    static var hvacEquipmentColor: Color {
        Color.gray.opacity(0.4)
    }

    /// Attached wall indicator for townhouses
    static var attachedWallColor: Color {
        Color.gray.opacity(0.5)
    }

    // MARK: - Amenity Colors

    /// Pool indicator color
    static var poolColor: Color {
        Color.blue.opacity(0.6)
    }

    /// Fireplace/chimney enhanced color
    static var fireplaceChimneyColor: Color {
        Color.orange.opacity(0.6)
    }
}
