//
//  HVACOverlayView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  HVAC zone visualization layer for floor layout modal
//

import SwiftUI

/// HVAC system visualization showing heating and cooling zones
struct HVACOverlayView: View {
    let property: PropertyDetail

    // MARK: - Computed Properties

    private var heatingSystem: String? {
        property.heating
    }

    private var coolingSystem: String? {
        property.cooling
    }

    private var heatZones: Int {
        property.heatZones ?? 1
    }

    private var coolZones: Int {
        property.coolZones ?? 1
    }

    private var hasMultipleHeatZones: Bool {
        heatZones > 1
    }

    private var hasMultipleCoolZones: Bool {
        coolZones > 1
    }

    // MARK: - Body

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Section header
            HStack(spacing: 8) {
                Image(systemName: "thermometer.medium")
                    .font(.system(size: 14))
                    .foregroundStyle(AppColors.brandTeal)

                Text("HVAC Systems")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)

                Spacer()
            }

            // Zone visualization
            HStack(spacing: 16) {
                // Heating
                if heatingSystem != nil || heatZones > 0 {
                    ZoneCard(
                        type: .heating,
                        systemType: heatingSystem ?? "Heating",
                        zoneCount: heatZones
                    )
                }

                // Cooling
                if coolingSystem != nil || coolZones > 0 {
                    ZoneCard(
                        type: .cooling,
                        systemType: coolingSystem ?? "Cooling",
                        zoneCount: coolZones
                    )
                }
            }

            // Zone diagram (if multiple zones exist)
            if hasMultipleHeatZones || hasMultipleCoolZones {
                ZoneDiagram(
                    heatZones: heatZones,
                    coolZones: coolZones,
                    stories: property.stories ?? 1
                )
            }
        }
        .padding(12)
        .background(Color.gray.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}

// MARK: - Zone Type

private enum ZoneType {
    case heating
    case cooling

    var icon: String {
        switch self {
        case .heating: return "flame.fill"
        case .cooling: return "snowflake"
        }
    }

    var color: Color {
        switch self {
        case .heating: return .orange
        case .cooling: return .blue
        }
    }

    var label: String {
        switch self {
        case .heating: return "Heat"
        case .cooling: return "Cool"
        }
    }
}

// MARK: - Zone Card

private struct ZoneCard: View {
    let type: ZoneType
    let systemType: String
    let zoneCount: Int

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            // Header
            HStack(spacing: 6) {
                Image(systemName: type.icon)
                    .font(.system(size: 12))
                    .foregroundStyle(type.color)

                Text(type.label)
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)
            }

            // System type
            Text(formatSystemType(systemType))
                .font(.caption2)
                .foregroundStyle(.secondary)
                .lineLimit(2)

            // Zone count
            if zoneCount > 1 {
                HStack(spacing: 4) {
                    ForEach(0..<min(zoneCount, 4), id: \.self) { index in
                        Circle()
                            .fill(type.color.opacity(0.6 + Double(index) * 0.1))
                            .frame(width: 8, height: 8)
                    }

                    if zoneCount > 4 {
                        Text("+\(zoneCount - 4)")
                            .font(.system(size: 9))
                            .foregroundStyle(.secondary)
                    }
                }

                Text("\(zoneCount) zones")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
        .padding(10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(type.color.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    private func formatSystemType(_ type: String) -> String {
        // Clean up common HVAC type formats
        var cleaned = type
            .replacingOccurrences(of: "_", with: " ")
            .replacingOccurrences(of: "-", with: " ")

        // Capitalize appropriately
        cleaned = cleaned.capitalized

        // Limit length
        if cleaned.count > 30 {
            cleaned = String(cleaned.prefix(27)) + "..."
        }

        return cleaned
    }
}

// MARK: - Zone Diagram

private struct ZoneDiagram: View {
    let heatZones: Int
    let coolZones: Int
    let stories: Int

    private var maxZones: Int {
        max(heatZones, coolZones)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Zone Distribution")
                .font(.caption2)
                .fontWeight(.semibold)
                .foregroundStyle(.tertiary)

            // Visual representation of zones across floors
            HStack(spacing: 12) {
                // Building representation
                VStack(spacing: 2) {
                    ForEach((0..<min(stories, 4)).reversed(), id: \.self) { floor in
                        ZoneFloorRow(
                            floor: floor + 1,
                            totalFloors: stories,
                            heatZones: heatZones,
                            coolZones: coolZones
                        )
                    }
                }
                .padding(8)
                .background(Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 6))
                .overlay(
                    RoundedRectangle(cornerRadius: 6)
                        .stroke(Color.gray.opacity(0.2), lineWidth: 1)
                )

                // Legend
                VStack(alignment: .leading, spacing: 6) {
                    LegendItem(color: .orange, label: "Heating zone")
                    LegendItem(color: .blue, label: "Cooling zone")

                    if maxZones > 1 {
                        Text("Multiple zones allow independent temperature control")
                            .font(.caption2)
                            .foregroundStyle(.tertiary)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
            }
        }
        .padding(.top, 8)
    }
}

// MARK: - Zone Floor Row

private struct ZoneFloorRow: View {
    let floor: Int
    let totalFloors: Int
    let heatZones: Int
    let coolZones: Int

    // Determine which zones this floor belongs to
    private var heatingZoneIndex: Int {
        if heatZones <= 1 { return 0 }
        // Distribute zones across floors
        let zonesPerFloor = Double(totalFloors) / Double(heatZones)
        return min(Int(Double(floor - 1) / zonesPerFloor), heatZones - 1)
    }

    private var coolingZoneIndex: Int {
        if coolZones <= 1 { return 0 }
        let zonesPerFloor = Double(totalFloors) / Double(coolZones)
        return min(Int(Double(floor - 1) / zonesPerFloor), coolZones - 1)
    }

    var body: some View {
        HStack(spacing: 4) {
            // Floor label
            Text("F\(floor)")
                .font(.system(size: 9, weight: .medium, design: .monospaced))
                .foregroundStyle(.secondary)
                .frame(width: 20)

            // Heat zone indicator
            Rectangle()
                .fill(Color.orange.opacity(0.3 + Double(heatingZoneIndex) * 0.2))
                .frame(width: 30, height: 16)
                .overlay(
                    Text("H\(heatingZoneIndex + 1)")
                        .font(.system(size: 8, weight: .medium))
                        .foregroundStyle(.orange)
                )

            // Cool zone indicator
            Rectangle()
                .fill(Color.blue.opacity(0.3 + Double(coolingZoneIndex) * 0.2))
                .frame(width: 30, height: 16)
                .overlay(
                    Text("C\(coolingZoneIndex + 1)")
                        .font(.system(size: 8, weight: .medium))
                        .foregroundStyle(.blue)
                )
        }
    }
}

// MARK: - Legend Item

private struct LegendItem: View {
    let color: Color
    let label: String

    var body: some View {
        HStack(spacing: 6) {
            Rectangle()
                .fill(color.opacity(0.5))
                .frame(width: 12, height: 12)
                .clipShape(RoundedRectangle(cornerRadius: 2))

            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
    }
}

// MARK: - Preview
// Previews disabled - PropertyDetail.mockProperty() not implemented
// #Preview("Multi-Zone HVAC") {
//     VStack(spacing: 16) {
//         HVACOverlayView(
//             property: createMockPropertyWithHVAC(
//                 heating: "Forced Hot Air",
//                 cooling: "Central Air",
//                 heatZones: 3,
//                 coolZones: 2,
//                 stories: 3
//             )
//         )
//
//         HVACOverlayView(
//             property: createMockPropertyWithHVAC(
//                 heating: "Radiant Floor",
//                 cooling: "Mini Split",
//                 heatZones: 4,
//                 coolZones: 4,
//                 stories: 2
//             )
//         )
//     }
//     .padding()
// }
//
// #Preview("Single Zone HVAC") {
//     HVACOverlayView(
//         property: createMockPropertyWithHVAC(
//             heating: "Gas Forced Air",
//             cooling: "Central Air Conditioning",
//             heatZones: 1,
//             coolZones: 1,
//             stories: 2
//         )
//     )
//     .padding()
// }
//
// // Mock property creator for previews
// private func createMockPropertyWithHVAC(
//     heating: String,
//     cooling: String,
//     heatZones: Int,
//     coolZones: Int,
//     stories: Int
// ) -> PropertyDetail {
//     // Return a mock PropertyDetail - this would need to match the actual initializer
//     PropertyDetail.mockProperty()
// }
