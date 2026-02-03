//
//  InteractiveFloorView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Interactive floor diagram with tappable room regions
//

import SwiftUI

/// Interactive floor diagram with tappable room regions and feature markers
struct InteractiveFloorView: View {
    let config: DiagramConfiguration
    let floorData: [FloorData]
    @Binding var expandedFloors: Set<FloorLevel>
    @Binding var selectedRoom: Room?
    let showDimensions: Bool
    let showFeatureMarkers: Bool
    let property: PropertyDetail

    // MARK: - Computed Properties

    private var aboveGradeFloorData: [FloorData] {
        floorData.filter { $0.level.isAboveGrade }
    }

    private var belowGradeFloorData: [FloorData] {
        floorData.filter { $0.level.isBelowGrade }
    }

    // MARK: - Body

    var body: some View {
        VStack(spacing: 0) {
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
        .shadow(color: Color.black.opacity(0.08), radius: 8, x: 0, y: 4)
    }

    // MARK: - Main House Structure

    private var mainHouseStructure: some View {
        VStack(spacing: 0) {
            // Roof section
            roofSection

            // Main building with floors
            buildingSection

            // Basement/below grade section
            if config.shouldShowBelowGrade {
                belowGradeSection
            }

            // Foundation
            foundationSection

            // Amenity indicators
            amenityIndicators
        }
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
            pitchedRoof
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

            // Chimney with fireplace indicator
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
                    InteractiveFloorSegment(
                        floorData: floor,
                        isExpanded: bindingForFloor(floor.level),
                        selectedRoom: $selectedRoom,
                        showDimensions: showDimensions,
                        showFeatureMarkers: showFeatureMarkers,
                        property: property,
                        isFirst: index == 0,
                        isLast: index == aboveGradeFloorData.count - 1
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
                ForEach(Array(belowGradeFloorData.enumerated()), id: \.element.id) { index, floor in
                    InteractiveFloorSegment(
                        floorData: floor,
                        isExpanded: bindingForFloor(floor.level),
                        selectedRoom: $selectedRoom,
                        showDimensions: showDimensions,
                        showFeatureMarkers: showFeatureMarkers,
                        property: property,
                        isFirst: index == 0,
                        isLast: index == belowGradeFloorData.count - 1
                    )

                    if index < belowGradeFloorData.count - 1 {
                        Rectangle()
                            .fill(FloorDiagramColors.borderColor.opacity(0.6))
                            .frame(height: 1)
                    }
                }

                // Show below grade sqft badge if we have data but no basement rooms
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
                withAnimation(.spring(response: 0.25, dampingFraction: 0.8)) {
                    if isExpanded {
                        expandedFloors.insert(level)
                    } else {
                        expandedFloors.remove(level)
                    }
                }
            }
        )
    }
}

// MARK: - Interactive Floor Segment

/// A floor segment with tappable room regions
struct InteractiveFloorSegment: View {
    let floorData: FloorData
    @Binding var isExpanded: Bool
    @Binding var selectedRoom: Room?
    let showDimensions: Bool
    let showFeatureMarkers: Bool
    let property: PropertyDetail
    let isFirst: Bool
    let isLast: Bool

    var body: some View {
        VStack(spacing: 0) {
            // Collapsed header (always visible)
            collapsedHeader
                .contentShape(Rectangle())
                .onTapGesture {
                    withAnimation(.spring(response: 0.25, dampingFraction: 0.8)) {
                        isExpanded.toggle()
                    }
                    HapticManager.impact(.light)
                }

            // Expanded content (rooms with tap interaction)
            if isExpanded {
                expandedContent
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .background(backgroundColor)
    }

    // MARK: - Collapsed Header

    private var collapsedHeader: some View {
        HStack(spacing: 10) {
            FloorLevelBadge(level: floorData.level)

            VStack(alignment: .leading, spacing: 2) {
                Text(floorData.level.displayName)
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)
                    .lineLimit(1)
                    .minimumScaleFactor(0.8)

                if !isExpanded {
                    Text(floorData.roomSummary)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            .layoutPriority(1)

            Spacer()

            // Room type icons (collapsed only)
            if !isExpanded {
                roomIconsRow
            }

            ExpandCollapseIndicator(isExpanded: isExpanded)
                .padding(.leading, 4)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
    }

    // MARK: - Room Icons Row

    private var roomIconsRow: some View {
        HStack(spacing: 5) {
            ForEach(floorData.roomTypeIcons.prefix(4), id: \.type) { item in
                HStack(spacing: 2) {
                    RoomTypeIcon(category: item.type, size: 13)
                    if item.count > 1 {
                        Text("\(item.count)")
                            .font(.system(size: 10, weight: .medium))
                            .foregroundStyle(.tertiary)
                    }
                }
            }
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(Color.gray.opacity(0.08))
        .clipShape(Capsule())
    }

    // MARK: - Expanded Content

    private var expandedContent: some View {
        VStack(spacing: 0) {
            Rectangle()
                .fill(FloorDiagramColors.accentBorderColor)
                .frame(height: 1)
                .padding(.horizontal, 14)

            ForEach(Array(floorData.rooms.enumerated()), id: \.element.id) { index, room in
                InteractiveRoomRow(
                    room: room,
                    isSelected: selectedRoom?.id == room.id,
                    showDimensions: showDimensions,
                    showFeatureMarkers: showFeatureMarkers,
                    property: property
                ) {
                    withAnimation(.spring(response: 0.25, dampingFraction: 0.8)) {
                        if selectedRoom?.id == room.id {
                            selectedRoom = nil
                        } else {
                            selectedRoom = room
                        }
                    }
                    HapticManager.impact(.light)
                }

                if index < floorData.rooms.count - 1 {
                    Rectangle()
                        .fill(Color.gray.opacity(0.15))
                        .frame(height: 1)
                        .padding(.leading, 58)
                }
            }
        }
        .background(FloorDiagramColors.expandedBackground)
    }

    // MARK: - Styling Helpers

    private var backgroundColor: Color {
        if isExpanded {
            return FloorDiagramColors.expandedBackground
        }
        return FloorDiagramColors.backgroundColor(for: floorData.level)
    }
}

// MARK: - Interactive Room Row

/// A room row that expands inline when tapped
private struct InteractiveRoomRow: View {
    let room: Room
    let isSelected: Bool
    let showDimensions: Bool
    let showFeatureMarkers: Bool
    let property: PropertyDetail
    let onTap: () -> Void

    private var roomName: String {
        formatRoomName(room.type ?? "Room")
    }

    private var roomCategory: RoomCategory {
        categorizeRoom(room)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Main row (always visible)
            HStack(alignment: .top, spacing: 12) {
                // Room icon
                ZStack {
                    Circle()
                        .fill(isSelected ? AppColors.brandTeal.opacity(0.2) : AppColors.brandTeal.opacity(0.1))
                        .frame(width: 32, height: 32)

                    Image(systemName: roomCategory.iconName)
                        .font(.system(size: 14))
                        .foregroundStyle(AppColors.brandTeal)
                }

                // Room info
                VStack(alignment: .leading, spacing: 4) {
                    HStack(alignment: .center) {
                        Text(roomName)
                            .font(.subheadline)
                            .fontWeight(.medium)
                            .foregroundStyle(.primary)

                        // Feature markers
                        if showFeatureMarkers {
                            roomFeatureMarkers
                        }

                        Spacer()

                        // Dimensions badge
                        if let dimensions = room.dimensions, !dimensions.isEmpty, showDimensions {
                            DimensionBadge(dimensions: dimensions)
                        }

                        // Expand indicator
                        Image(systemName: "chevron.right")
                            .font(.system(size: 10, weight: .semibold))
                            .foregroundStyle(.tertiary)
                            .rotationEffect(.degrees(isSelected ? 90 : 0))
                    }

                    // Features preview (when not expanded)
                    if !isSelected, let features = room.features, !features.isEmpty {
                        Text(features)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
            }
            .padding(.vertical, 10)
            .padding(.horizontal, 14)
            .contentShape(Rectangle())
            .onTapGesture(perform: onTap)

            // Expanded detail section
            if isSelected {
                expandedDetails
                    .transition(.opacity.combined(with: .scale(scale: 0.98, anchor: .top)))
            }
        }
        .background(isSelected ? AppColors.brandTeal.opacity(0.04) : Color.clear)
    }

    // MARK: - Room Feature Markers

    @ViewBuilder
    private var roomFeatureMarkers: some View {
        let roomType = (room.type ?? "").lowercased()

        HStack(spacing: 4) {
            // Check if this is the primary bedroom
            if roomType.contains("bedroom1") || roomType.contains("master") || roomType.contains("primary") {
                if property.masterBedroomLevel != nil {
                    Image(systemName: "crown.fill")
                        .font(.system(size: 8))
                        .foregroundStyle(.purple.opacity(0.7))
                }
            }

            // Check if room might have fireplace (based on features)
            if let features = room.features?.lowercased(), features.contains("fireplace") {
                Image(systemName: "flame.fill")
                    .font(.system(size: 8))
                    .foregroundStyle(.orange.opacity(0.7))
            }
        }
    }

    // MARK: - Expanded Details

    private var expandedDetails: some View {
        VStack(alignment: .leading, spacing: 8) {
            // Dimensions (if not shown in header)
            if let dimensions = room.dimensions, !dimensions.isEmpty, !showDimensions {
                HStack(spacing: 6) {
                    Image(systemName: "ruler")
                        .font(.system(size: 11))
                        .foregroundStyle(.secondary)
                    Text(dimensions)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            // Full features
            if let features = room.features, !features.isEmpty {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Features")
                        .font(.caption2)
                        .fontWeight(.semibold)
                        .foregroundStyle(.tertiary)

                    Text(features)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            // Description
            if let description = room.description, !description.isEmpty, description != room.features {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Notes")
                        .font(.caption2)
                        .fontWeight(.semibold)
                        .foregroundStyle(.tertiary)

                    Text(description)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .italic()
                }
            }
        }
        .padding(.horizontal, 58)
        .padding(.bottom, 12)
    }

    // MARK: - Helper Functions

    private func formatRoomName(_ name: String) -> String {
        var result = ""
        var lastWasLetter = false

        for char in name {
            if char.isNumber && lastWasLetter {
                result += " "
            }
            result += String(char)
            lastWasLetter = char.isLetter
        }

        return result
    }

    private func categorizeRoom(_ room: Room) -> RoomCategory {
        guard let type = room.type?.lowercased() else { return .other }

        if type.contains("bed") || type.contains("master") || type.contains("primary") {
            return .bedroom
        }
        if type.contains("bath") || type.contains("shower") || type.contains("powder") {
            return .bathroom
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

// MARK: - Preview
// Preview disabled - PropertyDetail.mockProperty() not implemented
// #Preview {
//     InteractiveFloorView(
//         config: DiagramConfiguration(
//             propertySubtype: "Single Family Residence",
//             stories: 2,
//             garageSpaces: 2,
//             attachedGarageYn: true,
//             hasPool: false,
//             hasFireplace: true
//         ),
//         floorData: [
//             FloorData(
//                 level: .second,
//                 rooms: [
//                     Room(type: "Bedroom1", level: "Second", dimensions: "15 x 14", features: "Walk-in Closet, Fireplace", description: "Primary Suite"),
//                     Room(type: "Bedroom2", level: "Second", dimensions: "12 x 11", features: "Carpet", description: nil),
//                     Room(type: "Bathroom1", level: "Second", dimensions: "10 x 8", features: "Full Bath", description: nil)
//                 ]
//             ),
//             FloorData(
//                 level: .first,
//                 rooms: [
//                     Room(type: "Kitchen", level: "First", dimensions: "12 x 14", features: "Granite counters", description: nil),
//                     Room(type: "Living Room", level: "First", dimensions: "18 x 20", features: "Hardwood, Fireplace", description: "Open concept")
//                 ]
//             )
//         ],
//         expandedFloors: .constant([.first]),
//         selectedRoom: .constant(nil),
//         showDimensions: true,
//         showFeatureMarkers: true,
//         property: PropertyDetail.mockProperty()
//     )
//     .padding()
// }
