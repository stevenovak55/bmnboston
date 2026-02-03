//
//  TownhouseBuildingView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Townhouse building visualization for floor diagrams
//

import SwiftUI

/// Renders a townhouse with narrow pitched roof and attached wall indicators
struct TownhouseBuildingView: View {
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
        HStack(spacing: 0) {
            // Left neighbor indicator
            attachedWallIndicator

            // Main townhouse structure
            mainStructure

            // Right neighbor indicator
            attachedWallIndicator
        }
        .shadow(color: Color.black.opacity(0.08), radius: 8, x: 0, y: 4)
    }

    // MARK: - Attached Wall Indicator

    private var attachedWallIndicator: some View {
        Rectangle()
            .fill(
                LinearGradient(
                    colors: [
                        FloorDiagramColors.attachedWallColor.opacity(0.3),
                        FloorDiagramColors.attachedWallColor.opacity(0.1)
                    ],
                    startPoint: .leading,
                    endPoint: .trailing
                )
            )
            .frame(width: 6)
            .overlay(
                Rectangle()
                    .stroke(FloorDiagramColors.borderColor.opacity(0.4), lineWidth: 0.5)
            )
    }

    // MARK: - Main Structure

    private var mainStructure: some View {
        VStack(spacing: 0) {
            // Narrow pitched roof
            narrowRoofSection

            // Main building (floors)
            buildingSection

            // Foundation
            foundationSection
        }
    }

    // MARK: - Narrow Roof

    private var narrowRoofSection: some View {
        ZStack(alignment: .top) {
            // Roof with narrow pitch
            ZStack {
                TownhouseRoofShape()
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

                TownhouseRoofShape()
                    .stroke(FloorDiagramColors.borderColor, lineWidth: 1.5)
            }
            .frame(height: 36)
            .padding(.horizontal, -4)

            // Chimney (if has fireplace)
            if config.hasFireplace {
                enhancedChimneyView
                    .offset(x: 30, y: -4)
            }
        }
    }

    // MARK: - Enhanced Chimney (for fireplace)

    private var enhancedChimneyView: some View {
        VStack(spacing: 0) {
            // Chimney cap
            Rectangle()
                .fill(Color.gray.opacity(0.6))
                .frame(width: 18, height: 3)

            // Chimney body
            Rectangle()
                .fill(
                    LinearGradient(
                        colors: [
                            FloorDiagramColors.fireplaceChimneyColor,
                            Color.gray.opacity(0.4)
                        ],
                        startPoint: .leading,
                        endPoint: .trailing
                    )
                )
                .frame(width: 14, height: 18)

            // Subtle glow at top
            Rectangle()
                .fill(FloorDiagramColors.fireplaceChimneyColor.opacity(0.4))
                .frame(width: 10, height: 2)
                .offset(y: -16)
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
                ForEach(Array(floorData.enumerated()), id: \.element.id) { index, floor in
                    FloorSegmentView(
                        floorData: floor,
                        isExpanded: bindingForFloor(floor.level),
                        isFirst: index == 0,
                        isLast: index == floorData.count - 1
                    )

                    if index < floorData.count - 1 {
                        Rectangle()
                            .fill(FloorDiagramColors.borderColor.opacity(0.6))
                            .frame(height: 1)
                    }
                }
            }
        }
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
}

// MARK: - Townhouse Roof Shape

/// Narrow pitched roof for townhouses
struct TownhouseRoofShape: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()
        let midX = rect.midX
        let topY = rect.minY + 2
        let bottomY = rect.maxY

        // Narrower, steeper pitch for townhouse
        path.move(to: CGPoint(x: midX, y: topY))
        path.addLine(to: CGPoint(x: rect.maxX, y: bottomY))
        path.addLine(to: CGPoint(x: rect.minX, y: bottomY))
        path.closeSubpath()

        return path
    }
}

// MARK: - Preview

#Preview("Townhouse Building") {
    ScrollView {
        TownhouseBuildingView(
            config: DiagramConfiguration(
                propertySubtype: "Townhouse",
                architecturalStyle: nil,
                stories: 3,
                garageSpaces: 1,
                attachedGarageYn: true,
                hasFireplace: true,
                isAttached: true
            ),
            floorData: [
                FloorData(
                    level: .third,
                    rooms: [
                        Room(type: "Bedroom1", level: "Third", dimensions: "14 x 12", features: "Primary Suite", description: nil),
                        Room(type: "Bathroom1", level: "Third", dimensions: "8 x 6", features: "Full Bath", description: nil)
                    ]
                ),
                FloorData(
                    level: .second,
                    rooms: [
                        Room(type: "Bedroom2", level: "Second", dimensions: "12 x 10", features: "Carpet", description: nil),
                        Room(type: "Bedroom3", level: "Second", dimensions: "11 x 10", features: "Carpet", description: nil)
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
        .frame(width: 280)
        .padding()
    }
}
