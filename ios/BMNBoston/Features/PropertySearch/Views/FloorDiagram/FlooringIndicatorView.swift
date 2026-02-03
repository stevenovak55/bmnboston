//
//  FlooringIndicatorView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Surface type badges for flooring visualization
//

import SwiftUI

/// Displays flooring types found in the property
struct FlooringIndicatorView: View {
    let flooringTypes: String

    private var parsedTypes: [FlooringType] {
        parseFlooringTypes(flooringTypes)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Section header
            HStack(spacing: 8) {
                Image(systemName: "square.fill")
                    .font(.system(size: 14))
                    .foregroundStyle(AppColors.brandTeal)

                Text("Flooring")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)

                Spacer()
            }

            // Flooring type badges
            if parsedTypes.isEmpty {
                Text("Flooring details not specified")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            } else {
                FlowLayout(spacing: 8) {
                    ForEach(parsedTypes, id: \.name) { flooring in
                        FlooringBadge(flooring: flooring)
                    }
                }
            }
        }
        .padding(12)
        .background(Color.gray.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }

    // MARK: - Parse Flooring Types

    private func parseFlooringTypes(_ types: String) -> [FlooringType] {
        // Split by comma or common separators
        let separators = CharacterSet(charactersIn: ",;/")
        let typeStrings = types
            .components(separatedBy: separators)
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }

        return typeStrings.compactMap { FlooringType.from($0) }
    }
}

// MARK: - Flooring Type

struct FlooringType: Equatable {
    let name: String
    let icon: String
    let color: Color
    let pattern: FlooringPattern

    enum FlooringPattern {
        case wood
        case tile
        case carpet
        case stone
        case vinyl
        case concrete
        case other
    }

    static func from(_ string: String) -> FlooringType? {
        let lowercased = string.lowercased()

        if lowercased.contains("hardwood") || lowercased.contains("wood") || lowercased.contains("oak") ||
           lowercased.contains("maple") || lowercased.contains("cherry") || lowercased.contains("pine") ||
           lowercased.contains("bamboo") {
            return FlooringType(
                name: string,
                icon: "rectangle.split.3x3",
                color: Color(red: 0.6, green: 0.4, blue: 0.2),
                pattern: .wood
            )
        }

        if lowercased.contains("tile") || lowercased.contains("ceramic") || lowercased.contains("porcelain") {
            return FlooringType(
                name: string,
                icon: "square.grid.3x3.fill",
                color: Color(red: 0.5, green: 0.6, blue: 0.7),
                pattern: .tile
            )
        }

        if lowercased.contains("carpet") || lowercased.contains("rug") {
            return FlooringType(
                name: string,
                icon: "rectangle.fill",
                color: Color(red: 0.4, green: 0.5, blue: 0.6),
                pattern: .carpet
            )
        }

        if lowercased.contains("stone") || lowercased.contains("marble") || lowercased.contains("granite") ||
           lowercased.contains("slate") || lowercased.contains("travertine") {
            return FlooringType(
                name: string,
                icon: "square.fill.on.square.fill",
                color: Color(red: 0.5, green: 0.5, blue: 0.5),
                pattern: .stone
            )
        }

        if lowercased.contains("vinyl") || lowercased.contains("laminate") || lowercased.contains("lvp") ||
           lowercased.contains("luxury vinyl") {
            return FlooringType(
                name: string,
                icon: "rectangle.split.3x1",
                color: Color(red: 0.55, green: 0.45, blue: 0.35),
                pattern: .vinyl
            )
        }

        if lowercased.contains("concrete") || lowercased.contains("epoxy") {
            return FlooringType(
                name: string,
                icon: "square.dashed",
                color: Color.gray,
                pattern: .concrete
            )
        }

        // Default for unrecognized types
        return FlooringType(
            name: string,
            icon: "square",
            color: Color.gray,
            pattern: .other
        )
    }
}

// MARK: - Flooring Badge

private struct FlooringBadge: View {
    let flooring: FlooringType

    var body: some View {
        HStack(spacing: 6) {
            // Pattern icon
            FlooringPatternIcon(pattern: flooring.pattern, color: flooring.color)

            // Type name
            Text(flooring.name)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .background(flooring.color.opacity(0.1))
        .clipShape(Capsule())
    }
}

// MARK: - Flooring Pattern Icon

private struct FlooringPatternIcon: View {
    let pattern: FlooringType.FlooringPattern
    let color: Color

    var body: some View {
        ZStack {
            switch pattern {
            case .wood:
                // Wood grain pattern
                VStack(spacing: 1) {
                    ForEach(0..<3, id: \.self) { _ in
                        Rectangle()
                            .fill(color.opacity(0.8))
                            .frame(height: 2)
                    }
                }
                .frame(width: 14, height: 14)

            case .tile:
                // Tile grid pattern
                Grid(horizontalSpacing: 1, verticalSpacing: 1) {
                    GridRow {
                        Rectangle().fill(color)
                        Rectangle().fill(color.opacity(0.7))
                    }
                    GridRow {
                        Rectangle().fill(color.opacity(0.7))
                        Rectangle().fill(color)
                    }
                }
                .frame(width: 14, height: 14)

            case .carpet:
                // Soft texture dots
                ZStack {
                    Circle()
                        .fill(color.opacity(0.6))
                        .frame(width: 14, height: 14)
                    ForEach(0..<5, id: \.self) { i in
                        Circle()
                            .fill(color.opacity(0.3))
                            .frame(width: 3, height: 3)
                            .offset(
                                x: CGFloat.random(in: -4...4),
                                y: CGFloat.random(in: -4...4)
                            )
                    }
                }

            case .stone:
                // Irregular stone pattern
                ZStack {
                    RoundedRectangle(cornerRadius: 2)
                        .fill(color.opacity(0.7))
                        .frame(width: 8, height: 6)
                        .offset(x: -2, y: -2)
                    RoundedRectangle(cornerRadius: 2)
                        .fill(color.opacity(0.5))
                        .frame(width: 7, height: 7)
                        .offset(x: 2, y: 2)
                }
                .frame(width: 14, height: 14)

            case .vinyl:
                // Plank pattern
                VStack(spacing: 2) {
                    HStack(spacing: 2) {
                        Rectangle().fill(color.opacity(0.8))
                        Rectangle().fill(color.opacity(0.6))
                    }
                    HStack(spacing: 2) {
                        Rectangle().fill(color.opacity(0.6))
                        Rectangle().fill(color.opacity(0.8))
                    }
                }
                .frame(width: 14, height: 14)

            case .concrete:
                // Solid with texture
                RoundedRectangle(cornerRadius: 2)
                    .fill(color.opacity(0.5))
                    .frame(width: 14, height: 14)
                    .overlay(
                        RoundedRectangle(cornerRadius: 2)
                            .stroke(color, style: StrokeStyle(lineWidth: 0.5, dash: [2, 2]))
                    )

            case .other:
                // Generic floor icon
                Image(systemName: "square")
                    .font(.system(size: 12))
                    .foregroundStyle(color)
            }
        }
    }
}

// MARK: - Preview

#Preview {
    VStack(spacing: 16) {
        FlooringIndicatorView(
            flooringTypes: "Hardwood, Tile, Carpet"
        )

        FlooringIndicatorView(
            flooringTypes: "Luxury Vinyl Plank, Ceramic Tile, Wall-to-Wall Carpet, Marble"
        )

        FlooringIndicatorView(
            flooringTypes: "Oak Hardwood, Porcelain Tile, Stone, Concrete"
        )
    }
    .padding()
}
