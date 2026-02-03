//
//  CondoBuildingView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Condo building visualization for floor diagrams
//

import SwiftUI

/// Renders a condo building with flat roof, window grid, and optional underground parking
struct CondoBuildingView: View {
    let config: DiagramConfiguration
    let floorData: [FloorData]
    @Binding var expandedFloors: Set<FloorLevel>

    /// Binding helper for individual floor expanded state
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

    var body: some View {
        VStack(spacing: 0) {
            // Unit floor level indicator for condos (shows which floor the unit is on)
            if let levelDisplay = config.entryLevelDisplay {
                unitFloorLevelBadge(levelDisplay)
            }

            // Flat roof with HVAC
            flatRoofSection

            // Main building with window grid background
            buildingSection

            // Underground parking (if has garage)
            if config.shouldShowGarage {
                UndergroundParkingView(spaces: config.garageSpaces)
            }

            // Foundation
            foundationSection

            // Pool indicator (if has pool)
            if config.hasPool {
                poolIndicator
            }
        }
        .shadow(color: Color.black.opacity(0.08), radius: 8, x: 0, y: 4)
    }

    // MARK: - Unit Floor Level Badge

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

    // MARK: - Flat Roof

    private var flatRoofSection: some View {
        ZStack(alignment: .trailing) {
            // Main flat roof
            Rectangle()
                .fill(
                    LinearGradient(
                        colors: [
                            Color.gray.opacity(0.25),
                            Color.gray.opacity(0.15)
                        ],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                )
                .frame(height: 10)
                .overlay(
                    Rectangle()
                        .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
                )

            // HVAC unit
            hvacUnit
                .offset(x: -20, y: -8)
        }
    }

    // MARK: - HVAC Unit

    private var hvacUnit: some View {
        VStack(spacing: 0) {
            // Top vent
            Rectangle()
                .fill(FloorDiagramColors.hvacEquipmentColor)
                .frame(width: 28, height: 3)

            // Unit body
            Rectangle()
                .fill(
                    LinearGradient(
                        colors: [
                            Color.gray.opacity(0.35),
                            Color.gray.opacity(0.25)
                        ],
                        startPoint: .leading,
                        endPoint: .trailing
                    )
                )
                .frame(width: 24, height: 14)
                .overlay(
                    // Vent lines
                    HStack(spacing: 3) {
                        ForEach(0..<4, id: \.self) { _ in
                            Rectangle()
                                .fill(Color.gray.opacity(0.5))
                                .frame(width: 1, height: 8)
                        }
                    }
                )
        }
    }

    // MARK: - Building Section

    private var buildingSection: some View {
        ZStack {
            // Building background with window grid
            windowGridBackground

            // Floor segments
            VStack(spacing: 0) {
                ForEach(Array(floorData.enumerated()), id: \.element.id) { index, floor in
                    FloorSegmentView(
                        floorData: floor,
                        isExpanded: bindingForFloor(floor.level),
                        isFirst: index == 0,
                        isLast: index == floorData.count - 1,
                        displayNameOverride: condoFloorDisplayName,
                        badgeTextOverride: condoFloorBadgeText
                    )

                    if index < floorData.count - 1 {
                        Rectangle()
                            .fill(FloorDiagramColors.borderColor.opacity(0.6))
                            .frame(height: 1)
                    }
                }
            }
        }
        .overlay(
            Rectangle()
                .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
        )
    }

    // MARK: - Condo Floor Display Helpers

    /// For condos, returns the floor display name from entry level (e.g., "9th Floor")
    private var condoFloorDisplayName: String? {
        guard let level = config.entryLevel, !level.isEmpty else { return nil }

        // Handle numeric levels
        if let levelNum = Int(level) {
            return ordinalFloorName(levelNum)
        }

        // Handle string levels
        let lowerLevel = level.lowercased()
        if lowerLevel.contains("ground") || lowerLevel.contains("lobby") {
            return "Ground Floor"
        }
        if lowerLevel.contains("penthouse") || lowerLevel.contains("top") {
            return "Penthouse"
        }
        if lowerLevel.contains("basement") || lowerLevel.contains("lower") {
            return "Lower Level"
        }

        return "Floor \(level)"
    }

    /// For condos, returns the badge text from entry level (e.g., "9")
    private var condoFloorBadgeText: String? {
        guard let level = config.entryLevel, !level.isEmpty else { return nil }

        // If it's a number, just return the number
        if let _ = Int(level) {
            return level
        }

        // Handle string levels
        let lowerLevel = level.lowercased()
        if lowerLevel.contains("ground") || lowerLevel.contains("lobby") {
            return "G"
        }
        if lowerLevel.contains("penthouse") {
            return "PH"
        }
        if lowerLevel.contains("basement") || lowerLevel.contains("lower") {
            return "LL"
        }

        return level
    }

    /// Returns ordinal floor name (1st Floor, 2nd Floor, etc.)
    private func ordinalFloorName(_ floor: Int) -> String {
        let suffix: String
        switch floor {
        case 1: suffix = "st"
        case 2: suffix = "nd"
        case 3: suffix = "rd"
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

    // MARK: - Window Grid Background

    private var windowGridBackground: some View {
        GeometryReader { geometry in
            let columns = Int(geometry.size.width / 20)
            let rows = max(1, floorData.count)

            VStack(spacing: 4) {
                ForEach(0..<rows, id: \.self) { _ in
                    HStack(spacing: 4) {
                        ForEach(0..<columns, id: \.self) { _ in
                            Rectangle()
                                .fill(FloorDiagramColors.windowGridColor)
                                .frame(width: 8, height: 12)
                        }
                    }
                }
            }
            .padding(8)
        }
        .opacity(0.3)
    }

    // MARK: - Foundation

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
        }
    }

    // MARK: - Pool Indicator

    private var poolIndicator: some View {
        HStack(spacing: 4) {
            Image(systemName: "figure.pool.swim")
                .font(.system(size: 10))
                .foregroundStyle(FloorDiagramColors.poolColor)
            Text("Pool")
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(FloorDiagramColors.poolColor.opacity(0.15))
        .clipShape(Capsule())
        .padding(.top, 8)
    }
}

// MARK: - Preview

#Preview("Condo Building") {
    ScrollView {
        CondoBuildingView(
            config: DiagramConfiguration(
                propertySubtype: "Condominium",
                architecturalStyle: nil,
                stories: 3,
                garageSpaces: 2,
                attachedGarageYn: false,
                hasPool: true,
                entryLevel: "5"
            ),
            floorData: [
                FloorData(
                    level: .second,
                    rooms: [
                        Room(type: "Bedroom1", level: "Second", dimensions: "14 x 12", features: "Hardwood", description: nil),
                        Room(type: "Bathroom1", level: "Second", dimensions: "8 x 6", features: "Full Bath", description: nil)
                    ]
                ),
                FloorData(
                    level: .first,
                    rooms: [
                        Room(type: "Living Room", level: "First", dimensions: "16 x 14", features: "Open Concept", description: nil),
                        Room(type: "Kitchen", level: "First", dimensions: "12 x 10", features: "Granite", description: nil)
                    ]
                )
            ],
            expandedFloors: .constant([])
        )
        .padding()
    }
}
