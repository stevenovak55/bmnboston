//
//  FloorDiagramHelpers.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Helper shapes and utilities for the Floor Diagram feature
//

import SwiftUI

// MARK: - House Roof Shape

/// Decorative roof shape with eaves overhang for a more realistic house look
struct HouseRoofShape: Shape {
    var eaveOverhang: CGFloat = 12

    func path(in rect: CGRect) -> Path {
        var path = Path()
        let midX = rect.midX
        let topY = rect.minY + 4  // Small offset from top for peak
        let bottomY = rect.maxY
        let leftX = rect.minX
        let rightX = rect.maxX

        // Main roof triangle with eaves extending past the building
        path.move(to: CGPoint(x: midX, y: topY))
        path.addLine(to: CGPoint(x: rightX, y: bottomY))
        path.addLine(to: CGPoint(x: leftX, y: bottomY))
        path.closeSubpath()

        return path
    }
}

// MARK: - Chimney Shape

/// Decorative chimney for the roof
struct ChimneyView: View {
    var body: some View {
        VStack(spacing: 0) {
            // Chimney cap
            Rectangle()
                .fill(Color.gray.opacity(0.6))
                .frame(width: 20, height: 3)

            // Chimney body
            Rectangle()
                .fill(
                    LinearGradient(
                        colors: [Color.gray.opacity(0.5), Color.gray.opacity(0.35)],
                        startPoint: .leading,
                        endPoint: .trailing
                    )
                )
                .frame(width: 16, height: 22)
        }
    }
}

// MARK: - Foundation Shape

/// Foundation/base of the house for a complete look
struct FoundationView: View {
    var body: some View {
        Rectangle()
            .fill(
                LinearGradient(
                    colors: [Color.gray.opacity(0.25), Color.gray.opacity(0.15)],
                    startPoint: .top,
                    endPoint: .bottom
                )
            )
            .frame(height: 8)
            .overlay(
                Rectangle()
                    .stroke(FloorDiagramColors.borderColor, lineWidth: 1)
            )
    }
}

// MARK: - Rounded Corner Helper

/// Helper for creating views with specific rounded corners
struct RoundedCorner: Shape {
    var radius: CGFloat = .infinity
    var corners: UIRectCorner = .allCorners

    func path(in rect: CGRect) -> Path {
        let path = UIBezierPath(
            roundedRect: rect,
            byRoundingCorners: corners,
            cornerRadii: CGSize(width: radius, height: radius)
        )
        return Path(path.cgPath)
    }
}

extension View {
    /// Clips view to rounded corners on specified corners only
    func cornerRadius(_ radius: CGFloat, corners: UIRectCorner) -> some View {
        clipShape(RoundedCorner(radius: radius, corners: corners))
    }
}

// MARK: - Floor Colors

/// Color utilities for floor diagram
enum FloorDiagramColors {
    /// Background color for a floor segment based on floor level
    static func backgroundColor(for level: FloorLevel) -> Color {
        switch level {
        case .garage:
            return Color.gray.opacity(0.12)
        case .basement:
            return Color(red: 0.45, green: 0.42, blue: 0.38).opacity(0.15)
        case .first:
            return AppColors.brandTeal.opacity(0.06)
        case .second:
            return AppColors.brandTeal.opacity(0.10)
        case .third:
            return AppColors.brandTeal.opacity(0.14)
        case .fourth:
            return AppColors.brandTeal.opacity(0.17)
        case .attic:
            return AppColors.brandTeal.opacity(0.20)
        }
    }

    /// Gradient for floor segments for depth
    static func backgroundGradient(for level: FloorLevel) -> LinearGradient {
        let baseColor = backgroundColor(for: level)
        return LinearGradient(
            colors: [baseColor, baseColor.opacity(0.7)],
            startPoint: .top,
            endPoint: .bottom
        )
    }

    /// Border color for floor segments
    static var borderColor: Color {
        Color.gray.opacity(0.35)
    }

    /// Accent border color for expanded state
    static var accentBorderColor: Color {
        AppColors.brandTeal.opacity(0.4)
    }

    /// Expanded floor segment background
    static var expandedBackground: Color {
        AppColors.secondaryBackground
    }

    /// Roof color
    static var roofColor: Color {
        Color(red: 0.55, green: 0.35, blue: 0.25).opacity(0.4)
    }

    /// Roof accent color
    static var roofAccentColor: Color {
        Color(red: 0.55, green: 0.35, blue: 0.25).opacity(0.6)
    }
}

// MARK: - Dimension Badge

/// Displays room dimensions in a pill-shaped badge
struct DimensionBadge: View {
    let dimensions: String

    var body: some View {
        Text(dimensions)
            .font(.caption2)
            .fontWeight(.medium)
            .foregroundStyle(.secondary)
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
            .background(Color.gray.opacity(0.12))
            .clipShape(Capsule())
    }
}

// MARK: - Room Type Icon

/// Displays a room category icon
struct RoomTypeIcon: View {
    let category: RoomCategory
    var size: CGFloat = 14

    var body: some View {
        Image(systemName: category.iconName)
            .font(.system(size: size))
            .foregroundStyle(AppColors.brandTeal.opacity(0.7))
    }
}

// MARK: - Floor Level Badge

/// Badge showing the floor level (1, 2, LL, A, etc.)
struct FloorLevelBadge: View {
    let level: FloorLevel
    var textOverride: String? = nil  // For condos: override with actual floor number

    /// The text to display in the badge
    private var badgeText: String {
        textOverride ?? level.shortName
    }

    /// Adjusts size based on text length
    private var badgeWidth: CGFloat {
        badgeText.count > 1 ? 30 : 26
    }

    private var fontSize: CGFloat {
        badgeText.count > 1 ? 10 : 12
    }

    var body: some View {
        ZStack {
            // Background with gradient
            RoundedRectangle(cornerRadius: 6)
                .fill(
                    LinearGradient(
                        colors: [AppColors.brandTeal, AppColors.brandTeal.opacity(0.85)],
                        startPoint: .topLeading,
                        endPoint: .bottomTrailing
                    )
                )

            Text(badgeText)
                .font(.system(size: fontSize, weight: .bold, design: .rounded))
                .foregroundStyle(.white)
        }
        .frame(width: badgeWidth, height: 26)
        .shadow(color: AppColors.brandTeal.opacity(0.3), radius: 2, x: 0, y: 1)
    }
}

// MARK: - Expand/Collapse Indicator

/// Chevron indicator for expand/collapse state
struct ExpandCollapseIndicator: View {
    let isExpanded: Bool

    var body: some View {
        Image(systemName: "chevron.down")
            .font(.system(size: 12, weight: .semibold))
            .foregroundStyle(AppColors.brandTeal.opacity(0.6))
            .rotationEffect(.degrees(isExpanded ? 180 : 0))
    }
}

// MARK: - Window Decoration

/// Decorative window element for the house sides
struct WindowDecoration: View {
    var body: some View {
        RoundedRectangle(cornerRadius: 2)
            .fill(Color.gray.opacity(0.2))
            .frame(width: 4, height: 28)
            .overlay(
                RoundedRectangle(cornerRadius: 2)
                    .stroke(Color.gray.opacity(0.3), lineWidth: 0.5)
            )
    }
}

// MARK: - Flat Roof Section

/// Flat roof for modern homes, condos, and multi-family buildings
struct FlatRoofSection: View {
    var showHVAC: Bool = true

    var body: some View {
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

            // HVAC unit (optional)
            if showHVAC {
                HVACUnitView()
                    .offset(x: -20, y: -8)
            }
        }
    }
}

// MARK: - HVAC Unit

/// Rooftop HVAC unit for flat-roof buildings
struct HVACUnitView: View {
    var body: some View {
        VStack(spacing: 0) {
            // Top vent
            Rectangle()
                .fill(Color.gray.opacity(0.4))
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
}

// MARK: - Enhanced Chimney (with Fireplace Indicator)

/// Chimney with optional fireplace glow indicator
struct EnhancedChimneyView: View {
    let hasFireplace: Bool

    var body: some View {
        VStack(spacing: 0) {
            // Chimney cap
            Rectangle()
                .fill(Color.gray.opacity(0.6))
                .frame(width: 20, height: 3)

            // Chimney body
            Rectangle()
                .fill(
                    LinearGradient(
                        colors: hasFireplace
                            ? [Color.orange.opacity(0.5), Color.gray.opacity(0.4)]
                            : [Color.gray.opacity(0.5), Color.gray.opacity(0.35)],
                        startPoint: .leading,
                        endPoint: .trailing
                    )
                )
                .frame(width: 16, height: 22)

            // Glow indicator for fireplace
            if hasFireplace {
                Rectangle()
                    .fill(Color.orange.opacity(0.4))
                    .frame(width: 12, height: 2)
                    .offset(y: -20)
            }
        }
    }
}

// MARK: - Pool Indicator Badge

/// Small pool indicator badge for properties with pools
struct PoolIndicatorBadge: View {
    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: "figure.pool.swim")
                .font(.system(size: 10))
                .foregroundStyle(Color.blue.opacity(0.7))
            Text("Pool")
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(Color.blue.opacity(0.1))
        .clipShape(Capsule())
    }
}

// MARK: - Empty State View

/// Empty state when no room data is available
struct FloorDiagramEmptyState: View {
    var body: some View {
        VStack(spacing: 16) {
            // House icon
            ZStack {
                Image(systemName: "house.fill")
                    .font(.system(size: 44))
                    .foregroundStyle(AppColors.brandTeal.opacity(0.2))

                Image(systemName: "questionmark")
                    .font(.system(size: 18, weight: .semibold))
                    .foregroundStyle(AppColors.brandTeal.opacity(0.4))
                    .offset(y: 4)
            }

            VStack(spacing: 6) {
                Text("No Floor Layout Available")
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .foregroundStyle(.secondary)

                Text("Room details will appear here when available")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
                    .multilineTextAlignment(.center)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 40)
        .padding(.horizontal, 24)
        .background(
            ZStack {
                RoundedRectangle(cornerRadius: 16)
                    .fill(Color.gray.opacity(0.06))
                RoundedRectangle(cornerRadius: 16)
                    .stroke(Color.gray.opacity(0.1), lineWidth: 1)
            }
        )
    }
}

// MARK: - Preview

#Preview {
    VStack(spacing: 24) {
        // House roof with chimney
        ZStack(alignment: .topTrailing) {
            HouseRoofShape()
                .fill(FloorDiagramColors.roofColor)
                .frame(width: 240, height: 50)

            ChimneyView()
                .offset(x: -40, y: -18)
        }

        // Foundation
        FoundationView()
            .frame(width: 200)

        // Floor level badges
        HStack(spacing: 8) {
            ForEach([FloorLevel.basement, .first, .second, .attic], id: \.self) { level in
                FloorLevelBadge(level: level)
            }
        }

        // Dimension badge
        DimensionBadge(dimensions: "12 x 14")

        // Room type icons
        HStack(spacing: 12) {
            ForEach([RoomCategory.bedroom, .bathroom, .kitchen, .livingRoom], id: \.self) { category in
                RoomTypeIcon(category: category)
            }
        }

        // Empty state
        FloorDiagramEmptyState()
            .padding(.horizontal)
    }
    .padding()
}
