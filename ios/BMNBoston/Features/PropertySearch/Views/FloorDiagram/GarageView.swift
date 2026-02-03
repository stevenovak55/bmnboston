//
//  GarageView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Garage visualization component for floor diagrams
//

import SwiftUI

/// Renders a garage with doors based on number of spaces
struct GarageView: View {
    let spaces: Int
    let position: GaragePosition
    let showRoof: Bool

    init(spaces: Int, position: GaragePosition = .attached, showRoof: Bool = true) {
        self.spaces = max(1, min(3, spaces))
        self.position = position
        self.showRoof = showRoof
    }

    private var garageWidth: CGFloat {
        CGFloat(spaces) * 38 + 12 // 38pt per door + padding
    }

    private var garageHeight: CGFloat {
        position == .underground ? 36 : 52
    }

    var body: some View {
        VStack(spacing: 0) {
            // Small pitched roof for attached/detached garage
            if showRoof && position != .underground {
                garageRoof
            }

            // Garage body with doors
            garageBody
        }
    }

    // MARK: - Garage Roof

    private var garageRoof: some View {
        GarageRoofShape()
            .fill(
                LinearGradient(
                    colors: [
                        FloorDiagramColors.roofAccentColor.opacity(0.8),
                        FloorDiagramColors.roofColor.opacity(0.8)
                    ],
                    startPoint: .top,
                    endPoint: .bottom
                )
            )
            .frame(width: garageWidth + 4, height: 18)
            .overlay(
                GarageRoofShape()
                    .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
            )
    }

    // MARK: - Garage Body

    private var garageBody: some View {
        ZStack {
            // Background
            Rectangle()
                .fill(FloorDiagramColors.garageBackground)
                .overlay(
                    Rectangle()
                        .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
                )

            // Garage doors
            HStack(spacing: 4) {
                ForEach(0..<spaces, id: \.self) { _ in
                    GarageDoorView(isUnderground: position == .underground)
                }
            }
            .padding(.horizontal, 6)
            .padding(.vertical, position == .underground ? 4 : 6)
        }
        .frame(width: garageWidth, height: garageHeight)
    }
}

// MARK: - Garage Door View

/// Individual garage door with panels and windows
struct GarageDoorView: View {
    let isUnderground: Bool

    private var doorWidth: CGFloat { 34 }
    private var doorHeight: CGFloat { isUnderground ? 26 : 38 }

    var body: some View {
        ZStack {
            // Door background
            RoundedRectangle(cornerRadius: 2)
                .fill(FloorDiagramColors.garageDoorColor)

            // Door content
            VStack(spacing: 2) {
                // Top window row
                windowRow

                // Panel rows
                ForEach(0..<(isUnderground ? 2 : 3), id: \.self) { _ in
                    panelRow
                }
            }
            .padding(2)

            // Door outline
            RoundedRectangle(cornerRadius: 2)
                .stroke(FloorDiagramColors.borderColor.opacity(0.6), lineWidth: 0.5)
        }
        .frame(width: doorWidth, height: doorHeight)
    }

    private var windowRow: some View {
        HStack(spacing: 2) {
            ForEach(0..<4, id: \.self) { _ in
                RoundedRectangle(cornerRadius: 1)
                    .fill(FloorDiagramColors.garageDoorWindowColor)
                    .frame(height: isUnderground ? 4 : 6)
            }
        }
    }

    private var panelRow: some View {
        HStack(spacing: 2) {
            ForEach(0..<2, id: \.self) { _ in
                RoundedRectangle(cornerRadius: 1)
                    .fill(FloorDiagramColors.garageDoorPanelColor.opacity(0.3))
                    .frame(height: isUnderground ? 6 : 8)
            }
        }
    }
}

// MARK: - Garage Roof Shape

/// Small pitched roof for garage
struct GarageRoofShape: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()
        let midX = rect.midX
        let topY = rect.minY + 2
        let bottomY = rect.maxY

        // Simple pitched roof triangle
        path.move(to: CGPoint(x: midX, y: topY))
        path.addLine(to: CGPoint(x: rect.maxX, y: bottomY))
        path.addLine(to: CGPoint(x: rect.minX, y: bottomY))
        path.closeSubpath()

        return path
    }
}

// MARK: - Underground Parking View

/// Underground parking level for condos
struct UndergroundParkingView: View {
    let spaces: Int

    private var displaySpaces: Int {
        min(max(1, spaces), 6)
    }

    var body: some View {
        ZStack {
            // Background - darker to show underground
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

            // Parking level content
            HStack(spacing: 8) {
                // "P" indicator
                Text("P")
                    .font(.system(size: 12, weight: .bold, design: .rounded))
                    .foregroundStyle(.white.opacity(0.7))
                    .frame(width: 20, height: 20)
                    .background(
                        RoundedRectangle(cornerRadius: 4)
                            .fill(Color.blue.opacity(0.4))
                    )

                // Car icons
                HStack(spacing: 6) {
                    ForEach(0..<displaySpaces, id: \.self) { _ in
                        Image(systemName: "car.fill")
                            .font(.system(size: 11))
                            .foregroundStyle(.white.opacity(0.5))
                    }
                }
            }
            .padding(.horizontal, 12)
        }
        .frame(height: 36)
        .overlay(
            Rectangle()
                .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
        )
    }
}

// MARK: - Preview

#Preview("Garage Types") {
    VStack(spacing: 24) {
        // 1-car attached
        VStack(alignment: .leading, spacing: 4) {
            Text("1-Car Attached").font(.caption).foregroundStyle(.secondary)
            GarageView(spaces: 1, position: .attached)
        }

        // 2-car attached
        VStack(alignment: .leading, spacing: 4) {
            Text("2-Car Attached").font(.caption).foregroundStyle(.secondary)
            GarageView(spaces: 2, position: .attached)
        }

        // 3-car detached
        VStack(alignment: .leading, spacing: 4) {
            Text("3-Car Detached").font(.caption).foregroundStyle(.secondary)
            GarageView(spaces: 3, position: .detached)
        }

        // Underground parking
        VStack(alignment: .leading, spacing: 4) {
            Text("Underground Parking (4 spaces)").font(.caption).foregroundStyle(.secondary)
            UndergroundParkingView(spaces: 4)
                .frame(width: 200)
        }
    }
    .padding()
}

#Preview("Individual Door") {
    VStack(spacing: 16) {
        GarageDoorView(isUnderground: false)
        GarageDoorView(isUnderground: true)
    }
    .padding()
}
