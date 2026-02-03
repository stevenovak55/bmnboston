//
//  FloorLayoutModalView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Full-screen interactive floor layout modal with enhanced visualizations
//

import SwiftUI

/// Full-screen modal for interactive floor layout exploration
/// Supports landscape orientation, tappable room regions, and data overlays
struct FloorLayoutModalView: View {
    let property: PropertyDetail
    let rooms: [Room]
    @Binding var isPresented: Bool

    // MARK: - State

    @State private var expandedFloors: Set<FloorLevel> = []
    @State private var expandedUnknownFloor: Bool = false
    @State private var selectedRoom: Room?
    @State private var showDimensions: Bool = false
    @State private var showHVACOverlay: Bool = false
    @State private var showFlooringIndicators: Bool = false
    @State private var showFeatureMarkers: Bool = true
    @State private var currentFloorIndex: Int = 0

    // MARK: - Computed Properties

    /// All rooms including special rooms from interior_features
    private var allRooms: [Room] {
        var combined = rooms
        if let special = property.specialRooms {
            combined.append(contentsOf: special)
        }
        return combined
    }

    /// Rooms that have a known floor level
    private var roomsWithKnownLevel: [Room] {
        allRooms.filter { room in
            // Room has level if hasLevel flag is true OR if level string exists (for backward compatibility)
            (room.hasLevel == true) || (room.hasLevel == nil && room.level != nil && !room.level!.isEmpty)
        }
    }

    /// Rooms without floor level assignment (shown in "Unknown Floor" section)
    private var roomsWithUnknownLevel: [Room] {
        allRooms.filter { room in
            room.hasLevel == false && !(room.isLikelyPlaceholder == true)
        }
    }

    /// Groups rooms by floor level and sorts from top to bottom (attic first, basement last)
    private var floorData: [FloorData] {
        let roomsWithData = roomsWithKnownLevel.filter { roomHasMeaningfulData($0) }
        guard !roomsWithData.isEmpty else { return [] }

        var grouped: [FloorLevel: [Room]] = [:]
        for room in roomsWithData {
            let level = FloorLevel.from(levelString: room.level)
            grouped[level, default: []].append(room)
        }

        return grouped.keys
            .sorted(by: >) // Higher raw values first (attic at top)
            .map { FloorData(level: $0, rooms: grouped[$0] ?? []) }
    }

    /// Whether there's a room count discrepancy between listed and computed
    private var hasRoomCountDiscrepancy: Bool {
        guard let computed = property.computedRoomCounts else { return false }
        let listedBeds = property.beds
        let computedBeds = computed.bedroomsFromRooms ?? 0
        return computedBeds != listedBeds && computedBeds > 0
    }

    /// Whether the property has special rooms (in-law, bonus room, etc.)
    private var hasSpecialFeatures: Bool {
        guard let computed = property.computedRoomCounts else { return false }
        return (computed.hasInLaw == true) || (computed.hasBonusRoom == true)
    }

    /// Diagram configuration for this property
    private var config: DiagramConfiguration {
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

    /// Whether the property has HVAC zone data to display
    private var hasHVACData: Bool {
        (property.heatZones ?? 0) > 0 || (property.coolZones ?? 0) > 0 ||
        property.heating != nil || property.cooling != nil
    }

    /// Whether the property has flooring data to display
    private var hasFlooringData: Bool {
        property.flooring != nil && !property.flooring!.isEmpty
    }

    /// Whether the property has outdoor area data
    private var hasOutdoorData: Bool {
        property.hasPool || property.hasSpa ||
        (property.patioAndPorchFeatures != nil && !property.patioAndPorchFeatures!.isEmpty)
    }

    // MARK: - Body

    var body: some View {
        NavigationView {
            GeometryReader { geometry in
                ScrollView {
                    VStack(spacing: 0) {
                        // Room count comparison (if discrepancy or special features)
                        if hasRoomCountDiscrepancy || hasSpecialFeatures {
                            roomCountComparisonView
                                .padding(.horizontal, 16)
                                .padding(.top, 12)
                        }

                        // Area breakdown header
                        if config.shouldShowAreaBreakdown {
                            AreaBreakdownView(
                                aboveGradeArea: config.aboveGradeArea,
                                belowGradeArea: config.belowGradeArea
                            )
                            .padding(.horizontal, 16)
                            .padding(.top, 12)
                        }

                        // Main floor diagram section
                        mainDiagramSection
                            .padding(.vertical, 16)

                        // Unknown floor rooms section (if any)
                        if !roomsWithUnknownLevel.isEmpty {
                            unknownFloorSection
                                .padding(.horizontal, 16)
                                .padding(.bottom, 16)
                        }

                        // Control toggles
                        controlTogglesSection
                            .padding(.horizontal, 16)
                            .padding(.bottom, 8)

                        // HVAC overlay section (if enabled and data exists)
                        if showHVACOverlay && hasHVACData {
                            HVACOverlayView(property: property)
                                .padding(.horizontal, 16)
                                .padding(.bottom, 16)
                                .transition(.opacity.combined(with: .move(edge: .top)))
                        }

                        // Flooring indicators section (if enabled and data exists)
                        if showFlooringIndicators && hasFlooringData {
                            FlooringIndicatorView(flooringTypes: property.flooring ?? "")
                                .padding(.horizontal, 16)
                                .padding(.bottom, 16)
                                .transition(.opacity.combined(with: .move(edge: .top)))
                        }

                        // Outdoor areas section (if data exists)
                        if hasOutdoorData {
                            OutdoorAreaView(property: property)
                                .padding(.horizontal, 16)
                                .padding(.bottom, 16)
                        }

                        // Feature markers legend (if enabled)
                        if showFeatureMarkers {
                            featureMarkersLegend
                                .padding(.horizontal, 16)
                                .padding(.bottom, 32)
                        }
                    }
                }
            }
            .navigationTitle("Floor Layout")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button(action: { isPresented = false }) {
                        Image(systemName: "xmark.circle.fill")
                            .font(.title2)
                            .symbolRenderingMode(.hierarchical)
                            .foregroundStyle(.secondary)
                    }
                }

                ToolbarItem(placement: .navigationBarTrailing) {
                    Menu {
                        Toggle(isOn: $showDimensions) {
                            Label("Show Dimensions", systemImage: "ruler")
                        }

                        if hasHVACData {
                            Toggle(isOn: $showHVACOverlay) {
                                Label("HVAC Zones", systemImage: "thermometer.medium")
                            }
                        }

                        if hasFlooringData {
                            Toggle(isOn: $showFlooringIndicators) {
                                Label("Flooring Types", systemImage: "square.fill")
                            }
                        }

                        Toggle(isOn: $showFeatureMarkers) {
                            Label("Feature Markers", systemImage: "mappin.circle")
                        }
                    } label: {
                        Image(systemName: "ellipsis.circle")
                            .font(.title3)
                    }
                }
            }
        }
        .interactiveDismissDisabled(false)
        .onAppear {
            // Auto-expand first floor on appear
            if let firstFloor = floorData.first {
                expandedFloors.insert(firstFloor.level)
            }
        }
    }

    // MARK: - Main Diagram Section

    private var mainDiagramSection: some View {
        VStack(spacing: 0) {
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
                InteractiveFloorView(
                    config: config,
                    floorData: floorData,
                    expandedFloors: $expandedFloors,
                    selectedRoom: $selectedRoom,
                    showDimensions: showDimensions,
                    showFeatureMarkers: showFeatureMarkers,
                    property: property
                )
                .padding(.horizontal, 16)

            case .land:
                // Land properties show lot visualization instead
                LotVisualizationView(property: property)
                    .padding(.horizontal, 16)
            }
        }
    }

    // MARK: - Control Toggles

    private var controlTogglesSection: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 10) {
                ControlToggleButton(
                    title: "Dimensions",
                    icon: "ruler",
                    isActive: showDimensions
                ) {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        showDimensions.toggle()
                    }
                }

                if hasHVACData {
                    ControlToggleButton(
                        title: "HVAC",
                        icon: "thermometer.medium",
                        isActive: showHVACOverlay
                    ) {
                        withAnimation(.easeInOut(duration: 0.2)) {
                            showHVACOverlay.toggle()
                        }
                    }
                }

                if hasFlooringData {
                    ControlToggleButton(
                        title: "Flooring",
                        icon: "square.fill",
                        isActive: showFlooringIndicators
                    ) {
                        withAnimation(.easeInOut(duration: 0.2)) {
                            showFlooringIndicators.toggle()
                        }
                    }
                }

                ControlToggleButton(
                    title: "Features",
                    icon: "mappin.circle",
                    isActive: showFeatureMarkers
                ) {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        showFeatureMarkers.toggle()
                    }
                }
            }
            .padding(.vertical, 4)
        }
    }

    // MARK: - Feature Markers Legend

    private var featureMarkersLegend: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Features")
                .font(.caption)
                .fontWeight(.semibold)
                .foregroundStyle(.secondary)

            FlowLayout(spacing: 8) {
                if let fireplaces = property.fireplacesTotal, fireplaces > 0 {
                    FeatureLegendItem(icon: "flame.fill", label: "\(fireplaces) Fireplace\(fireplaces > 1 ? "s" : "")", color: .orange)
                }

                if let masterLevel = property.masterBedroomLevel, !masterLevel.isEmpty {
                    FeatureLegendItem(icon: "bed.double.fill", label: "Primary Suite: \(masterLevel)", color: .purple)
                }

                if let laundry = property.laundryFeatures, !laundry.isEmpty {
                    FeatureLegendItem(icon: "washer.fill", label: "Laundry", color: .blue)
                }

                if let otherRooms = property.otherRooms, !otherRooms.isEmpty {
                    let roomsList = otherRooms.components(separatedBy: ",").prefix(3)
                    ForEach(Array(roomsList), id: \.self) { room in
                        FeatureLegendItem(icon: "square.dashed", label: room.trimmingCharacters(in: .whitespaces), color: .gray)
                    }
                }
            }
        }
        .padding(12)
        .background(Color.gray.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }

    // MARK: - Room Count Comparison View

    private var roomCountComparisonView: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Header
            HStack {
                Image(systemName: "info.circle.fill")
                    .foregroundStyle(.blue)
                Text("Room Count Summary")
                    .font(.headline)
            }

            if let computed = property.computedRoomCounts {
                // Counts comparison
                VStack(spacing: 8) {
                    // Bedrooms
                    HStack {
                        Text("Bedrooms")
                            .foregroundStyle(.secondary)
                        Spacer()
                        VStack(alignment: .trailing) {
                            Text("Listed: \(property.beds)")
                                .fontWeight(.semibold)
                            if let roomBeds = computed.bedroomsFromRooms, roomBeds != property.beds {
                                Text("In layout: \(roomBeds)")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }

                    // Bathrooms
                    HStack {
                        Text("Bathrooms")
                            .foregroundStyle(.secondary)
                        Spacer()
                        VStack(alignment: .trailing) {
                            Text("Listed: \(String(format: "%.1f", property.baths))")
                                .fontWeight(.semibold)
                            if let roomBaths = computed.bathroomsFromRooms, Double(roomBaths) != property.baths {
                                Text("In layout: \(roomBaths)")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                }

                // Explanation if discrepancy exists
                if hasRoomCountDiscrepancy {
                    Text("Some rooms may not have detailed floor information in the MLS data. The listing total is the official count.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .padding(.top, 4)
                }

                // Special features badges
                if hasSpecialFeatures {
                    HStack(spacing: 8) {
                        if computed.hasInLaw == true {
                            specialFeatureBadge(icon: "person.2.fill", label: "In-Law")
                        }
                        if computed.hasBonusRoom == true {
                            specialFeatureBadge(icon: "plus.rectangle.fill", label: "Bonus Room")
                        }
                    }
                    .padding(.top, 4)
                }
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private func specialFeatureBadge(icon: String, label: String) -> some View {
        Label(label, systemImage: icon)
            .font(.caption)
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
            .background(Color.purple.opacity(0.1))
            .foregroundStyle(.purple)
            .clipShape(RoundedRectangle(cornerRadius: 4))
    }

    // MARK: - Unknown Floor Section

    private var unknownFloorSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Header with expand/collapse
            Button(action: {
                withAnimation(.spring(response: 0.25, dampingFraction: 0.8)) {
                    expandedUnknownFloor.toggle()
                }
            }) {
                HStack {
                    Image(systemName: "questionmark.circle.fill")
                        .foregroundStyle(.orange)
                    Text("Floor Unknown")
                        .font(.headline)
                    Spacer()
                    Text("\(roomsWithUnknownLevel.count) rooms")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Image(systemName: expandedUnknownFloor ? "chevron.up" : "chevron.down")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            .buttonStyle(.plain)

            // Explanation
            Text("MLS data does not include floor assignments for these rooms")
                .font(.caption)
                .foregroundStyle(.secondary)

            // Room list (when expanded)
            if expandedUnknownFloor {
                VStack(spacing: 8) {
                    ForEach(roomsWithUnknownLevel) { room in
                        unknownFloorRoomRow(room)
                    }
                }
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .padding()
        .background(Color.orange.opacity(0.05))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(Color.orange.opacity(0.3), lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private func unknownFloorRoomRow(_ room: Room) -> some View {
        HStack(spacing: 12) {
            // Room icon
            Image(systemName: iconForRoom(room))
                .foregroundStyle(room.isSpecial == true ? .purple : .primary)
                .frame(width: 24)

            VStack(alignment: .leading, spacing: 2) {
                HStack {
                    Text(formatRoomName(room.type))
                        .font(.subheadline)

                    // Special room badge
                    if room.isSpecial == true {
                        Text("Special")
                            .font(.caption2)
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.purple.opacity(0.15))
                            .foregroundStyle(.purple)
                            .clipShape(RoundedRectangle(cornerRadius: 4))
                    }
                }

                // Dimensions if available
                if let dimensions = room.dimensions, !dimensions.isEmpty {
                    Text(dimensions)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Spacer()

            // Unknown floor indicator
            Text("?")
                .font(.caption)
                .padding(6)
                .background(Color.orange.opacity(0.15))
                .foregroundStyle(.orange)
                .clipShape(Circle())
        }
        .padding(.vertical, 4)
    }

    private func iconForRoom(_ room: Room) -> String {
        guard let type = room.type?.lowercased() else { return "square.fill" }

        // Special room icons
        if room.isSpecial == true {
            if type.contains("in-law") || type.contains("inlaw") { return "person.2.fill" }
            if type.contains("bonus") { return "plus.rectangle.fill" }
            if type.contains("media") || type.contains("game") { return "tv.fill" }
            if type.contains("exercise") || type.contains("gym") { return "figure.run" }
            if type.contains("sun") { return "sun.max.fill" }
            if type.contains("mud") { return "boot.fill" }
        }

        // Standard room icons
        // v6.68.20: Check bathroom FIRST to avoid "Master Bathroom" getting bedroom icon
        if type.contains("bath") || type.contains("shower") || type.contains("powder") { return "shower.fill" }
        // v6.68.20: Added "suite" for "Primary Suite", "Master Suite" room types
        if type.contains("bed") || type.contains("master") || type.contains("primary") || type.contains("suite") { return "bed.double.fill" }
        if type.contains("kitchen") { return "fork.knife" }
        if type.contains("living") || type.contains("family") || type.contains("great") { return "sofa.fill" }
        if type.contains("dining") { return "chair.lounge.fill" }
        if type.contains("office") || type.contains("study") || type.contains("den") { return "desktopcomputer" }
        if type.contains("garage") { return "car.fill" }
        if type.contains("laundry") || type.contains("utility") || type.contains("mud") { return "washer.fill" }

        return "square.fill"
    }

    private func formatRoomName(_ type: String?) -> String {
        guard let type = type else { return "Room" }

        // Clean up room type names
        var name = type
            .replacingOccurrences(of: "Bedroom", with: "Bed ")
            .replacingOccurrences(of: "Bathroom", with: "Bath ")

        // Add "room" suffix to numbered bedrooms/baths
        if name.hasPrefix("Bed ") || name.hasPrefix("Bath ") {
            if let lastChar = name.last, lastChar.isNumber {
                name += " Room"
            }
        }

        return name
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

    private func roomHasMeaningfulData(_ room: Room) -> Bool {
        // Room has meaningful data if it has level, dimensions, features, or description
        // OR if it has explicit hasLevel flag set (from v6.68.19)
        room.hasLevel == true ||
        (room.level != nil && !room.level!.isEmpty) ||
        (room.dimensions != nil && !room.dimensions!.isEmpty) ||
        (room.features != nil && !room.features!.isEmpty) ||
        room.description != nil
    }
}

// MARK: - Control Toggle Button

private struct ControlToggleButton: View {
    let title: String
    let icon: String
    let isActive: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon)
                    .font(.system(size: 12))
                Text(title)
                    .font(.caption)
                    .fontWeight(.medium)
            }
            .foregroundStyle(isActive ? .white : .primary)
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(
                isActive ? AppColors.brandTeal : Color.gray.opacity(0.12)
            )
            .clipShape(Capsule())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Feature Legend Item

private struct FeatureLegendItem: View {
    let icon: String
    let label: String
    let color: Color

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: icon)
                .font(.system(size: 10))
                .foregroundStyle(color)
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(color.opacity(0.1))
        .clipShape(Capsule())
    }
}

// MARK: - Flow Layout (for wrapping feature items)

struct FlowLayout: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let sizes = subviews.map { $0.sizeThatFits(.unspecified) }
        return layout(sizes: sizes, proposal: proposal).size
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        let sizes = subviews.map { $0.sizeThatFits(.unspecified) }
        let offsets = layout(sizes: sizes, proposal: proposal).offsets

        for (offset, subview) in zip(offsets, subviews) {
            subview.place(at: CGPoint(x: bounds.minX + offset.x, y: bounds.minY + offset.y), proposal: .unspecified)
        }
    }

    private func layout(sizes: [CGSize], proposal: ProposedViewSize) -> (offsets: [CGPoint], size: CGSize) {
        let width = proposal.width ?? .infinity
        var offsets: [CGPoint] = []
        var currentX: CGFloat = 0
        var currentY: CGFloat = 0
        var lineHeight: CGFloat = 0
        var maxX: CGFloat = 0

        for size in sizes {
            if currentX + size.width > width && currentX > 0 {
                currentX = 0
                currentY += lineHeight + spacing
                lineHeight = 0
            }

            offsets.append(CGPoint(x: currentX, y: currentY))
            lineHeight = max(lineHeight, size.height)
            currentX += size.width + spacing
            maxX = max(maxX, currentX)
        }

        return (offsets, CGSize(width: maxX - spacing, height: currentY + lineHeight))
    }
}

// MARK: - Preview
// Preview disabled - PropertyDetail.mockProperty() not implemented
// #Preview {
//     FloorLayoutModalView(
//         property: PropertyDetail.mockProperty(),
//         rooms: [
//             Room(type: "Kitchen", level: "First", dimensions: "12 x 14", features: "Granite counters, stainless appliances", description: nil),
//             Room(type: "Living Room", level: "First", dimensions: "18 x 20", features: "Hardwood, Fireplace", description: "Open concept"),
//             Room(type: "Bedroom1", level: "Second", dimensions: "15 x 14", features: "Walk-in Closet", description: "Primary Suite"),
//             Room(type: "Bedroom2", level: "Second", dimensions: "12 x 11", features: "Carpet", description: nil),
//             Room(type: "Bathroom1", level: "Second", dimensions: "10 x 8", features: "Full Bath", description: nil)
//         ],
//         isPresented: .constant(true)
//     )
// }
