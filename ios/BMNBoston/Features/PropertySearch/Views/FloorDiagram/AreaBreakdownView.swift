//
//  AreaBreakdownView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Area breakdown indicators showing above/below grade square footage
//

import SwiftUI

/// Displays above and below grade area breakdown pills
struct AreaBreakdownView: View {
    let aboveGradeArea: Int?
    let belowGradeArea: Int?

    private var showAboveGrade: Bool {
        (aboveGradeArea ?? 0) > 0
    }

    private var showBelowGrade: Bool {
        (belowGradeArea ?? 0) > 0
    }

    var body: some View {
        if showAboveGrade || showBelowGrade {
            HStack(spacing: 12) {
                if showAboveGrade, let area = aboveGradeArea {
                    AreaPill(
                        area: area,
                        type: .aboveGrade
                    )
                }

                if showBelowGrade, let area = belowGradeArea {
                    AreaPill(
                        area: area,
                        type: .belowGrade
                    )
                }
            }
            .padding(.vertical, 8)
        }
    }
}

// MARK: - Area Pill

/// Individual area indicator pill
struct AreaPill: View {
    let area: Int
    let type: AreaType

    enum AreaType {
        case aboveGrade
        case belowGrade

        var label: String {
            switch self {
            case .aboveGrade: return "Above Grade"
            case .belowGrade: return "Below Grade"
            }
        }

        var icon: String {
            switch self {
            case .aboveGrade: return "arrow.up"
            case .belowGrade: return "arrow.down"
            }
        }

        var backgroundColor: Color {
            switch self {
            case .aboveGrade: return FloorDiagramColors.aboveGradeIndicator.opacity(0.12)
            case .belowGrade: return FloorDiagramColors.belowGradeIndicator.opacity(0.15)
            }
        }

        var iconColor: Color {
            switch self {
            case .aboveGrade: return FloorDiagramColors.aboveGradeIndicator
            case .belowGrade: return FloorDiagramColors.belowGradeIndicator
            }
        }

        var textColor: Color {
            switch self {
            case .aboveGrade: return Color.green.opacity(0.8)
            case .belowGrade: return FloorDiagramColors.belowGradeIndicator
            }
        }
    }

    private var formattedArea: String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        return formatter.string(from: NSNumber(value: area)) ?? "\(area)"
    }

    var body: some View {
        HStack(spacing: 6) {
            // Icon
            Image(systemName: type.icon)
                .font(.system(size: 10, weight: .bold))
                .foregroundStyle(type.iconColor)

            // Label and value
            VStack(alignment: .leading, spacing: 1) {
                Text(type.label)
                    .font(.caption2)
                    .fontWeight(.medium)
                    .foregroundStyle(.secondary)

                Text("\(formattedArea) sqft")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(type.textColor)
            }
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .background(type.backgroundColor)
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }
}

// MARK: - Basement Sqft Badge

/// Small badge showing basement square footage inside the floor segment
struct BasementSqftBadge: View {
    let sqft: Int

    private var formattedSqft: String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        return formatter.string(from: NSNumber(value: sqft)) ?? "\(sqft)"
    }

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: "arrow.down.square.fill")
                .font(.system(size: 9))
                .foregroundStyle(FloorDiagramColors.belowGradeIndicator)

            Text("\(formattedSqft) sqft")
                .font(.system(size: 10, weight: .medium))
                .foregroundStyle(FloorDiagramColors.belowGradeIndicator.opacity(0.9))
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(FloorDiagramColors.belowGradeBackground.opacity(0.5))
        .clipShape(Capsule())
    }
}

// MARK: - Preview

#Preview("Area Breakdown") {
    VStack(spacing: 24) {
        // Both areas
        VStack(alignment: .leading, spacing: 4) {
            Text("Both Areas").font(.caption).foregroundStyle(.secondary)
            AreaBreakdownView(
                aboveGradeArea: 2450,
                belowGradeArea: 800
            )
        }

        // Above grade only
        VStack(alignment: .leading, spacing: 4) {
            Text("Above Grade Only").font(.caption).foregroundStyle(.secondary)
            AreaBreakdownView(
                aboveGradeArea: 1850,
                belowGradeArea: nil
            )
        }

        // Below grade only
        VStack(alignment: .leading, spacing: 4) {
            Text("Below Grade Only").font(.caption).foregroundStyle(.secondary)
            AreaBreakdownView(
                aboveGradeArea: nil,
                belowGradeArea: 1200
            )
        }

        // Individual pills
        VStack(alignment: .leading, spacing: 8) {
            Text("Individual Pills").font(.caption).foregroundStyle(.secondary)
            HStack(spacing: 12) {
                AreaPill(area: 2450, type: .aboveGrade)
                AreaPill(area: 800, type: .belowGrade)
            }
        }

        // Basement badge
        VStack(alignment: .leading, spacing: 4) {
            Text("Basement Badge").font(.caption).foregroundStyle(.secondary)
            BasementSqftBadge(sqft: 650)
        }
    }
    .padding()
}
