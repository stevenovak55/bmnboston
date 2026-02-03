//
//  FloorDiagramView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Interactive floor diagram showing property layout by floor level
//

import SwiftUI

/// Main container for the interactive floor diagram
/// Shows a visual house cross-section with expandable floor segments
struct FloorDiagramView: View {
    let rooms: [Room]
    let levels: String?

    @State private var expandedFloors: Set<FloorLevel> = []

    // MARK: - Computed Properties

    /// Groups rooms by floor level and sorts from top to bottom (attic first, basement last)
    private var floorData: [FloorData] {
        // v6.68.19: Only include rooms that have actual level data for floor grouping
        // This prevents rooms with NULL level from being assigned to First floor by default
        let roomsWithLevel = rooms.filter { roomHasLevelData($0) }

        guard !roomsWithLevel.isEmpty else { return [] }

        // Group by floor level
        var grouped: [FloorLevel: [Room]] = [:]
        for room in roomsWithLevel {
            let level = FloorLevel.from(levelString: room.level)
            grouped[level, default: []].append(room)
        }

        // Sort levels from top (attic) to bottom (basement) for display
        // This creates the visual effect of floors stacked like a building
        return grouped.keys
            .sorted(by: >) // Higher raw values first (attic at top)
            .map { FloorData(level: $0, rooms: grouped[$0] ?? []) }
    }

    /// Whether there are any rooms to display
    private var hasRoomData: Bool {
        !floorData.isEmpty
    }

    // MARK: - Body

    var body: some View {
        if hasRoomData {
            floorDiagramContent
                .frame(maxWidth: .infinity)
        } else {
            FloorDiagramEmptyState()
        }
    }

    // MARK: - Floor Diagram Content

    private var floorDiagramContent: some View {
        VStack(spacing: 0) {
            // House structure with roof, floors, and foundation
            houseStructure
        }
        .padding(.horizontal, 16)
    }

    // MARK: - House Structure

    private var houseStructure: some View {
        VStack(spacing: 0) {
            // Roof with chimney
            if floorData.count > 1 || shouldShowRoof {
                roofSection
            }

            // Main building (floors)
            buildingSection

            // Foundation
            foundationSection
        }
        .shadow(color: Color.black.opacity(0.08), radius: 8, x: 0, y: 4)
    }

    // MARK: - Roof Section

    private var roofSection: some View {
        ZStack(alignment: .top) {
            // Roof with eaves
            ZStack {
                // Roof fill with gradient for depth
                HouseRoofShape()
                    .fill(
                        LinearGradient(
                            colors: [
                                FloorDiagramColors.roofAccentColor,
                                FloorDiagramColors.roofColor
                            ],
                            startPoint: .top,
                            endPoint: .bottom
                        )
                    )

                // Roof outline
                HouseRoofShape()
                    .stroke(FloorDiagramColors.borderColor, lineWidth: 1.5)
            }
            .frame(height: 44)
            .padding(.horizontal, -8) // Eaves overhang

            // Chimney - positioned on right side of roof
            ChimneyView()
                .offset(x: 60, y: -6)
        }
    }

    // MARK: - Building Section (Floors)

    private var buildingSection: some View {
        ZStack {
            // Building background
            Rectangle()
                .fill(Color(.systemBackground))
                .overlay(
                    Rectangle()
                        .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
                )

            // Floor segments stacked vertically
            floorStack
        }
    }

    // MARK: - Floor Stack

    private var floorStack: some View {
        VStack(spacing: 0) {
            ForEach(Array(floorData.enumerated()), id: \.element.id) { index, floor in
                FloorSegmentView(
                    floorData: floor,
                    isExpanded: bindingForFloor(floor.level),
                    isFirst: index == 0,
                    isLast: index == floorData.count - 1
                )

                // Subtle separator line between floors
                if index < floorData.count - 1 {
                    Rectangle()
                        .fill(FloorDiagramColors.borderColor.opacity(0.6))
                        .frame(height: 1)
                }
            }
        }
    }

    // MARK: - Foundation Section

    private var foundationSection: some View {
        VStack(spacing: 0) {
            // Foundation step
            Rectangle()
                .fill(
                    LinearGradient(
                        colors: [Color.gray.opacity(0.2), Color.gray.opacity(0.12)],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                )
                .frame(height: 6)
                .overlay(
                    Rectangle()
                        .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
                )

            // Ground line with grass hint
            HStack(spacing: 0) {
                Rectangle()
                    .fill(Color.green.opacity(0.15))
                    .frame(height: 4)
            }
            .padding(.horizontal, -16) // Extend past building
        }
    }

    // MARK: - Helper Functions

    /// Creates a binding for a specific floor's expanded state
    private func bindingForFloor(_ level: FloorLevel) -> Binding<Bool> {
        Binding(
            get: { expandedFloors.contains(level) },
            set: { isExpanded in
                withAnimation(.easeInOut(duration: 0.25)) {
                    if isExpanded {
                        expandedFloors.insert(level)
                    } else {
                        expandedFloors.remove(level)
                    }
                }
            }
        )
    }

    /// Checks if a room has meaningful data beyond just the type
    /// v6.68.19: Also considers hasLevel flag from API
    private func roomHasMeaningfulData(_ room: Room) -> Bool {
        // Room has meaningful data if:
        // 1. It has a level assignment (either from hasLevel flag or level string)
        // 2. OR it has dimensions, features, or description
        (room.hasLevel == true) ||
        (room.level != nil && !room.level!.isEmpty) ||
        (room.dimensions != nil && !room.dimensions!.isEmpty) ||
        (room.features != nil && !room.features!.isEmpty) ||
        room.description != nil
    }

    /// v6.68.19: Checks if a room has actual floor level data (not just dimensions/features)
    /// Used for floor grouping to prevent rooms without level from defaulting to First floor
    private func roomHasLevelData(_ room: Room) -> Bool {
        (room.hasLevel == true) ||
        (room.level != nil && !room.level!.isEmpty)
    }

    /// Determines if roof should be shown based on property type/levels
    private var shouldShowRoof: Bool {
        // Show roof for multi-story properties or single-story houses
        if let levelsStr = levels, let levelsNum = Int(levelsStr) {
            return levelsNum >= 1
        }
        return true // Default to showing roof
    }
}

// MARK: - Smart Floor Diagram View

/// Property-aware floor diagram that adapts visualization based on property characteristics
struct SmartFloorDiagramView: View {
    let property: PropertyDetail
    let rooms: [Room]

    @State private var expandedFloors: Set<FloorLevel> = []

    // MARK: - Configuration

    private var config: DiagramConfiguration {
        // Check if property is a townhouse/attached building based on subtype
        let subtype = property.propertySubtype?.lowercased() ?? ""
        let isAttachedBuilding = subtype.contains("townhouse") || subtype.contains("town house") || subtype.contains("attached")

        return DiagramConfiguration(
            propertySubtype: property.propertySubtype,
            architecturalStyle: property.architecturalStyle,
            stories: property.stories,
            garageSpaces: property.garageSpaces,
            attachedGarageYn: property.attachedGarageYn,
            parkingTotal: property.parkingTotal,
            aboveGradeFinishedArea: property.aboveGradeFinishedArea,
            belowGradeFinishedArea: property.belowGradeFinishedArea,
            basement: property.basement,
            hasPool: property.hasPool,
            hasFireplace: property.hasFireplace,
            isAttached: isAttachedBuilding,
            entryLevel: property.entryLevel
        )
    }

    // MARK: - Computed Properties

    /// Groups rooms by floor level and sorts from top to bottom
    private var floorData: [FloorData] {
        // v6.68.19: Only include rooms that have actual level data for floor grouping
        // This prevents rooms with NULL level from being assigned to First floor by default
        let roomsWithLevel = rooms.filter { roomHasLevelData($0) }
        guard !roomsWithLevel.isEmpty else { return [] }

        var grouped: [FloorLevel: [Room]] = [:]
        for room in roomsWithLevel {
            let level = FloorLevel.from(levelString: room.level)
            grouped[level, default: []].append(room)
        }

        return grouped.keys
            .sorted(by: >)
            .map { FloorData(level: $0, rooms: grouped[$0] ?? []) }
    }

    private var hasRoomData: Bool {
        !floorData.isEmpty
    }

    // MARK: - Auto-Expand Logic

    private var shouldAutoExpand: [FloorLevel] {
        // Don't auto-expand any floors - user can tap to expand
        // The Floor Layout section itself is expanded by default, but individual floors are collapsed
        return []
    }

    // MARK: - Body

    var body: some View {
        if hasRoomData {
            VStack(spacing: 12) {
                // Area breakdown pills (above/below grade)
                if config.shouldShowAreaBreakdown {
                    AreaBreakdownView(
                        aboveGradeArea: config.aboveGradeArea,
                        belowGradeArea: config.belowGradeArea
                    )
                }

                // Property-type specific diagram
                diagramContent
            }
            .frame(maxWidth: .infinity)
            .onAppear {
                // Apply auto-expand logic on appear
                if expandedFloors.isEmpty {
                    expandedFloors = Set(shouldAutoExpand)
                }
            }
        }
        // Note: No empty state - section should be hidden if no room data
    }

    // MARK: - Diagram Content

    @ViewBuilder
    private var diagramContent: some View {
        switch config.diagramType {
        case .condo:
            CondoBuildingView(
                config: config,
                floorData: floorData,
                expandedFloors: $expandedFloors
            )
            .padding(.horizontal, 16)

        case .townhouse:
            TownhouseBuildingView(
                config: config,
                floorData: floorData,
                expandedFloors: $expandedFloors
            )
            .padding(.horizontal, 24)

        case .singleFamily, .multiFamily:
            singleFamilyDiagram

        case .land:
            // No diagram for land
            EmptyView()
        }
    }

    // MARK: - Single Family Diagram

    private var singleFamilyDiagram: some View {
        VStack(spacing: 0) {
            // Unit floor level badge (for condos that weren't detected as condo type)
            if shouldUseEntryLevelOverride, let displayName = config.entryLevelDisplay {
                unitFloorLevelBadge(displayName)
            }

            HStack(alignment: .bottom, spacing: 0) {
                // Garage (attached, to the side)
                if config.shouldShowGarage && config.hasAttachedGarage {
                    GarageView(
                        spaces: config.garageSpaces,
                        position: .attached,
                        showRoof: true
                    )
                    .padding(.trailing, 2)
                }

                // Main house structure
                mainHouseStructure
            }
        }
        .padding(.horizontal, 16)
    }

    /// Blue badge showing the unit's floor level (e.g., "9th Floor Unit")
    private func unitFloorLevelBadge(_ levelDisplay: String) -> some View {
        HStack(spacing: 6) {
            Image(systemName: "building.2.fill")
                .font(.system(size: 12))
                .foregroundStyle(.white)

            Text(levelDisplay)
                .font(.caption)
                .fontWeight(.semibold)
                .foregroundStyle(.white)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 6)
        .background(
            LinearGradient(
                colors: [
                    Color.blue.opacity(0.8),
                    Color.blue.opacity(0.6)
                ],
                startPoint: .leading,
                endPoint: .trailing
            )
        )
        .clipShape(Capsule())
        .padding(.bottom, 8)
    }

    private var mainHouseStructure: some View {
        VStack(spacing: 0) {
            // Roof section (pitched or flat based on style)
            roofSection

            // Main building with floors
            buildingSection

            // Basement/below grade section if applicable
            if config.shouldShowBelowGrade {
                belowGradeSection
            }

            // Foundation
            foundationSection

            // Amenity indicators
            amenityIndicators
        }
        .shadow(color: Color.black.opacity(0.08), radius: 8, x: 0, y: 4)
    }

    // MARK: - Roof Section

    @ViewBuilder
    private var roofSection: some View {
        switch config.roofStyle {
        case .pitched:
            pitchedRoof
        case .flat:
            FlatRoofSection(showHVAC: config.diagramType == .condo || config.diagramType == .multiFamily)
        case .narrowPitch:
            pitchedRoof // Townhouse uses own roof in TownhouseBuildingView
        }
    }

    private var pitchedRoof: some View {
        ZStack(alignment: .top) {
            ZStack {
                HouseRoofShape()
                    .fill(
                        LinearGradient(
                            colors: [
                                FloorDiagramColors.roofAccentColor,
                                FloorDiagramColors.roofColor
                            ],
                            startPoint: .top,
                            endPoint: .bottom
                        )
                    )

                HouseRoofShape()
                    .stroke(FloorDiagramColors.borderColor, lineWidth: 1.5)
            }
            .frame(height: 44)
            .padding(.horizontal, -8)

            // Chimney
            if config.hasFireplace {
                EnhancedChimneyView(hasFireplace: true)
                    .offset(x: 60, y: -6)
            } else {
                ChimneyView()
                    .offset(x: 60, y: -6)
            }
        }
    }

    // MARK: - Building Section

    private var buildingSection: some View {
        ZStack {
            Rectangle()
                .fill(Color(.systemBackground))
                .overlay(
                    Rectangle()
                        .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
                )

            VStack(spacing: 0) {
                ForEach(Array(aboveGradeFloorData.enumerated()), id: \.element.id) { index, floor in
                    FloorSegmentView(
                        floorData: floor,
                        isExpanded: bindingForFloor(floor.level),
                        isFirst: index == 0,
                        isLast: index == aboveGradeFloorData.count - 1,
                        displayNameOverride: entryLevelFloorDisplayName,
                        badgeTextOverride: entryLevelBadgeText
                    )

                    if index < aboveGradeFloorData.count - 1 {
                        Rectangle()
                            .fill(FloorDiagramColors.borderColor.opacity(0.6))
                            .frame(height: 1)
                    }
                }
            }
        }
    }

    // MARK: - Entry Level Floor Display Helpers

    /// Returns true if entry level indicates a floor above ground (e.g., 2, 3, 9)
    private var shouldUseEntryLevelOverride: Bool {
        guard let level = config.entryLevel, !level.isEmpty else { return false }

        // If numeric and > 1, use the override
        if let levelNum = Int(level), levelNum > 1 {
            return true
        }

        // Check for non-ground-floor string levels
        let lowerLevel = level.lowercased()
        if lowerLevel.contains("penthouse") || lowerLevel.contains("top") {
            return true
        }

        return false
    }

    /// For condos/apartments, returns the floor display name from entry level (e.g., "9th Floor")
    private var entryLevelFloorDisplayName: String? {
        guard shouldUseEntryLevelOverride, let level = config.entryLevel else { return nil }

        if let levelNum = Int(level) {
            return ordinalFloorName(levelNum)
        }

        let lowerLevel = level.lowercased()
        if lowerLevel.contains("penthouse") || lowerLevel.contains("top") {
            return "Penthouse"
        }

        return "Floor \(level)"
    }

    /// For condos/apartments, returns the badge text from entry level (e.g., "9")
    private var entryLevelBadgeText: String? {
        guard shouldUseEntryLevelOverride, let level = config.entryLevel else { return nil }

        if let _ = Int(level) {
            return level
        }

        let lowerLevel = level.lowercased()
        if lowerLevel.contains("penthouse") {
            return "PH"
        }

        return level
    }

    /// Returns ordinal floor name (1st Floor, 2nd Floor, etc.)
    private func ordinalFloorName(_ floor: Int) -> String {
        let suffix: String
        switch floor {
        case 11, 12, 13: suffix = "th"  // Special cases
        default:
            let lastDigit = floor % 10
            switch lastDigit {
            case 1: suffix = "st"
            case 2: suffix = "nd"
            case 3: suffix = "rd"
            default: suffix = "th"
            }
        }
        return "\(floor)\(suffix) Floor"
    }

    private var aboveGradeFloorData: [FloorData] {
        floorData.filter { $0.level.isAboveGrade }
    }

    private var belowGradeFloorData: [FloorData] {
        floorData.filter { $0.level.isBelowGrade }
    }

    // MARK: - Below Grade Section

    private var belowGradeSection: some View {
        ZStack {
            Rectangle()
                .fill(
                    LinearGradient(
                        colors: [
                            FloorDiagramColors.belowGradeBackground,
                            FloorDiagramColors.belowGradeAccent
                        ],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                )
                .overlay(
                    Rectangle()
                        .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
                )

            VStack(spacing: 0) {
                // Below grade floor segments
                ForEach(Array(belowGradeFloorData.enumerated()), id: \.element.id) { index, floor in
                    FloorSegmentView(
                        floorData: floor,
                        isExpanded: bindingForFloor(floor.level),
                        isFirst: index == 0,
                        isLast: index == belowGradeFloorData.count - 1
                    )

                    if index < belowGradeFloorData.count - 1 {
                        Rectangle()
                            .fill(FloorDiagramColors.borderColor.opacity(0.6))
                            .frame(height: 1)
                    }
                }

                // Show below grade sqft badge if we have the data but no basement rooms
                if belowGradeFloorData.isEmpty, let sqft = config.belowGradeArea, sqft > 0 {
                    HStack {
                        Spacer()
                        BasementSqftBadge(sqft: sqft)
                        Spacer()
                    }
                    .padding(.vertical, 12)
                }
            }
        }
    }

    // MARK: - Foundation Section

    private var foundationSection: some View {
        VStack(spacing: 0) {
            Rectangle()
                .fill(
                    LinearGradient(
                        colors: [Color.gray.opacity(0.2), Color.gray.opacity(0.12)],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                )
                .frame(height: 6)
                .overlay(
                    Rectangle()
                        .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
                )

            HStack(spacing: 0) {
                Rectangle()
                    .fill(Color.green.opacity(0.15))
                    .frame(height: 4)
            }
            .padding(.horizontal, -16)
        }
    }

    // MARK: - Amenity Indicators

    @ViewBuilder
    private var amenityIndicators: some View {
        HStack(spacing: 12) {
            if config.hasPool {
                PoolIndicatorBadge()
            }

            // Detached garage indicator
            if config.shouldShowGarage && !config.hasAttachedGarage && config.diagramType != .condo {
                HStack(spacing: 4) {
                    Image(systemName: "car.fill")
                        .font(.system(size: 10))
                        .foregroundStyle(.secondary)
                    Text("\(config.garageSpaces)-car garage")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(Color.gray.opacity(0.1))
                .clipShape(Capsule())
            }
        }
        .padding(.top, config.hasPool || (config.shouldShowGarage && !config.hasAttachedGarage) ? 8 : 0)
    }

    // MARK: - Helper Functions

    private func bindingForFloor(_ level: FloorLevel) -> Binding<Bool> {
        Binding(
            get: { expandedFloors.contains(level) },
            set: { isExpanded in
                withAnimation(.easeInOut(duration: 0.25)) {
                    if isExpanded {
                        expandedFloors.insert(level)
                    } else {
                        expandedFloors.remove(level)
                    }
                }
            }
        )
    }

    /// Checks if a room has meaningful data beyond just the type
    /// v6.68.19: Also considers hasLevel flag from API
    private func roomHasMeaningfulData(_ room: Room) -> Bool {
        // Room has meaningful data if:
        // 1. It has a level assignment (either from hasLevel flag or level string)
        // 2. OR it has dimensions, features, or description
        (room.hasLevel == true) ||
        (room.level != nil && !room.level!.isEmpty) ||
        (room.dimensions != nil && !room.dimensions!.isEmpty) ||
        (room.features != nil && !room.features!.isEmpty) ||
        room.description != nil
    }

    /// v6.68.19: Checks if a room has actual floor level data (not just dimensions/features)
    /// Used for floor grouping to prevent rooms without level from defaulting to First floor
    private func roomHasLevelData(_ room: Room) -> Bool {
        (room.hasLevel == true) ||
        (room.level != nil && !room.level!.isEmpty)
    }
}

// MARK: - Preview

#Preview("Multi-Floor Property") {
    ScrollView {
        FloorDiagramView(
            rooms: [
                // Basement
                Room(type: "Storage", level: "Basement", dimensions: "20 x 15", features: "Unfinished", description: nil),
                Room(type: "Laundry", level: "Basement", dimensions: "10 x 8", features: "Washer/Dryer hookups", description: nil),

                // First Floor
                Room(type: "Kitchen", level: "First", dimensions: "12 x 14", features: "Flooring - Tile, Upgraded Cabinets, Granite Counters", description: nil),
                Room(type: "Living Room", level: "First", dimensions: "18 x 20", features: "Hardwood, Fireplace", description: "Open concept"),
                Room(type: "Dining Room", level: "First", dimensions: "12 x 12", features: "Hardwood", description: nil),
                Room(type: "Bathroom1", level: "First", dimensions: "6 x 5", features: "Half Bath, Pedestal Sink", description: nil),

                // Second Floor
                Room(type: "Bedroom1", level: "Second", dimensions: "15 x 14", features: "Hardwood, Walk-in Closet, Ensuite Bath", description: "Primary Suite"),
                Room(type: "Bedroom2", level: "Second", dimensions: "12 x 11", features: "Carpet, Double Closet", description: nil),
                Room(type: "Bedroom3", level: "Second", dimensions: "11 x 10", features: "Carpet", description: nil),
                Room(type: "Bathroom2", level: "Second", dimensions: "10 x 8", features: "Full Bath, Double Vanity, Tile", description: nil),
                Room(type: "Bathroom3", level: "Second", dimensions: "8 x 6", features: "Full Bath, Tub/Shower", description: nil),

                // Attic
                Room(type: "Office", level: "Attic", dimensions: "14 x 12", features: "Skylight, Built-in Desk", description: "Finished attic space")
            ],
            levels: "3"
        )
        .padding()
    }
}

#Preview("Two Floor Property") {
    ScrollView {
        FloorDiagramView(
            rooms: [
                // First Floor
                Room(type: "Kitchen", level: "First", dimensions: "12 x 14", features: "Granite counters, stainless appliances", description: nil),
                Room(type: "Living Room", level: "First", dimensions: "18 x 20", features: "Hardwood, Bay window", description: nil),
                Room(type: "Dining Room", level: "First", dimensions: "12 x 12", features: "Hardwood", description: nil),

                // Second Floor
                Room(type: "Bedroom1", level: "Second", dimensions: "15 x 14", features: "Walk-in Closet", description: "Primary"),
                Room(type: "Bedroom2", level: "Second", dimensions: "12 x 11", features: "Carpet", description: nil),
                Room(type: "Bathroom1", level: "Second", dimensions: "10 x 8", features: "Full Bath", description: nil)
            ],
            levels: "2"
        )
        .padding()
    }
}

#Preview("Single Floor Property") {
    ScrollView {
        FloorDiagramView(
            rooms: [
                Room(type: "Kitchen", level: "First", dimensions: "10 x 12", features: "Updated appliances", description: nil),
                Room(type: "Living Room", level: "First", dimensions: "14 x 16", features: "Hardwood floors", description: nil),
                Room(type: "Bedroom1", level: "First", dimensions: "12 x 12", features: "Carpet", description: nil),
                Room(type: "Bathroom1", level: "First", dimensions: "8 x 6", features: "Full bath", description: nil)
            ],
            levels: "1"
        )
        .padding()
    }
}

#Preview("Empty State") {
    FloorDiagramView(
        rooms: [],
        levels: nil
    )
    .padding()
}
