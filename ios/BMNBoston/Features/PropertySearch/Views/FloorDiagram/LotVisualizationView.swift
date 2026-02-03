//
//  LotVisualizationView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Property lot footprint representation for land properties
//

import SwiftUI

/// Visualizes property lot for land-type properties
struct LotVisualizationView: View {
    let property: PropertyDetail

    // MARK: - Computed Properties

    private var lotSizeAcres: Double? {
        property.lotSizeAcres
    }

    private var lotSizeSquareFeet: Int? {
        property.lotSizeSquareFeet
    }

    private var lotDimensions: String? {
        property.lotSizeDimensions
    }

    private var lotFeatures: [String] {
        guard let features = property.lotFeatures else { return [] }
        return features
            .components(separatedBy: ",")
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
    }

    private var lotSizeDisplay: String {
        if let acres = lotSizeAcres, acres > 0 {
            if acres >= 1 {
                return String(format: "%.2f acres", acres)
            } else {
                return String(format: "%.3f acres", acres)
            }
        }

        if let sqft = lotSizeSquareFeet, sqft > 0 {
            let formatter = NumberFormatter()
            formatter.numberStyle = .decimal
            if let formatted = formatter.string(from: NSNumber(value: sqft)) {
                return "\(formatted) sq ft"
            }
        }

        return "Size not specified"
    }

    private var lotSizeCategory: LotSizeCategory {
        guard let acres = lotSizeAcres else {
            if let sqft = lotSizeSquareFeet {
                let acres = Double(sqft) / 43560.0
                return categorizeByAcres(acres)
            }
            return .standard
        }
        return categorizeByAcres(acres)
    }

    private func categorizeByAcres(_ acres: Double) -> LotSizeCategory {
        switch acres {
        case 0..<0.25: return .compact
        case 0.25..<0.5: return .standard
        case 0.5..<1.0: return .large
        case 1.0..<5.0: return .estate
        default: return .expansive
        }
    }

    // MARK: - Body

    var body: some View {
        VStack(spacing: 16) {
            // Lot diagram
            lotDiagram

            // Lot details
            lotDetails

            // Features list
            if !lotFeatures.isEmpty {
                lotFeaturesSection
            }
        }
    }

    // MARK: - Lot Diagram

    private var lotDiagram: some View {
        VStack(spacing: 8) {
            // Size label
            Text(lotSizeDisplay)
                .font(.title3)
                .fontWeight(.bold)
                .foregroundStyle(AppColors.brandTeal)

            // Visual representation
            ZStack {
                // Grass background
                RoundedRectangle(cornerRadius: 12)
                    .fill(
                        LinearGradient(
                            colors: [
                                Color.green.opacity(0.2),
                                Color.green.opacity(0.3)
                            ],
                            startPoint: .top,
                            endPoint: .bottom
                        )
                    )
                    .frame(height: lotSizeCategory.diagramHeight)

                // Lot boundary
                RoundedRectangle(cornerRadius: 8)
                    .stroke(Color.green.opacity(0.5), style: StrokeStyle(lineWidth: 2, dash: [8, 4]))
                    .padding(8)

                // Trees/vegetation indicators based on lot size
                vegetationOverlay

                // Compass
                compassIndicator
                    .frame(width: 40, height: 40)
                    .position(x: 40, y: 30)

                // Lot size category label
                Text(lotSizeCategory.label)
                    .font(.caption2)
                    .fontWeight(.semibold)
                    .foregroundStyle(.green)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(Color.white.opacity(0.9))
                    .clipShape(Capsule())
                    .position(x: 60, y: lotSizeCategory.diagramHeight - 20)
            }
            .frame(maxWidth: .infinity)
        }
        .padding(16)
        .background(Color.gray.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Vegetation Overlay

    @ViewBuilder
    private var vegetationOverlay: some View {
        switch lotSizeCategory {
        case .compact:
            EmptyView()

        case .standard:
            HStack(spacing: 30) {
                TreeIcon()
                TreeIcon()
            }
            .opacity(0.5)

        case .large:
            VStack {
                HStack(spacing: 40) {
                    TreeIcon()
                    TreeIcon()
                    TreeIcon()
                }
                HStack(spacing: 50) {
                    TreeIcon()
                    TreeIcon()
                }
            }
            .opacity(0.5)

        case .estate:
            VStack(spacing: 20) {
                HStack(spacing: 30) {
                    ForEach(0..<4, id: \.self) { _ in
                        TreeIcon()
                    }
                }
                HStack(spacing: 40) {
                    ForEach(0..<3, id: \.self) { _ in
                        TreeIcon()
                    }
                }
                HStack(spacing: 30) {
                    ForEach(0..<4, id: \.self) { _ in
                        TreeIcon()
                    }
                }
            }
            .opacity(0.4)

        case .expansive:
            VStack(spacing: 15) {
                ForEach(0..<4, id: \.self) { _ in
                    HStack(spacing: 25) {
                        ForEach(0..<5, id: \.self) { _ in
                            TreeIcon()
                        }
                    }
                }
            }
            .opacity(0.35)
        }
    }

    // MARK: - Compass Indicator

    private var compassIndicator: some View {
        ZStack {
            Circle()
                .fill(Color.white.opacity(0.8))

            Circle()
                .stroke(Color.gray.opacity(0.3), lineWidth: 1)

            // North indicator
            Path { path in
                path.move(to: CGPoint(x: 20, y: 8))
                path.addLine(to: CGPoint(x: 16, y: 18))
                path.addLine(to: CGPoint(x: 20, y: 14))
                path.addLine(to: CGPoint(x: 24, y: 18))
                path.closeSubpath()
            }
            .fill(Color.red.opacity(0.8))

            Text("N")
                .font(.system(size: 8, weight: .bold))
                .foregroundStyle(.secondary)
                .offset(y: -10)
        }
    }

    // MARK: - Lot Details

    private var lotDetails: some View {
        VStack(spacing: 8) {
            // Dimensions (if available)
            if let dimensions = lotDimensions, !dimensions.isEmpty {
                HStack(spacing: 8) {
                    Image(systemName: "ruler")
                        .font(.system(size: 12))
                        .foregroundStyle(.secondary)

                    Text("Dimensions: \(dimensions)")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            // Comparison to average
            lotComparison
        }
    }

    // MARK: - Lot Comparison

    private var lotComparison: some View {
        HStack(spacing: 16) {
            LotComparisonItem(
                label: "vs. Avg Urban Lot",
                value: comparisonToUrbanAverage,
                isPositive: lotSizeCategory != .compact
            )

            LotComparisonItem(
                label: "vs. Suburban Lot",
                value: comparisonToSuburbanAverage,
                isPositive: lotSizeCategory == .estate || lotSizeCategory == .expansive
            )
        }
    }

    private var comparisonToUrbanAverage: String {
        // Average urban lot is about 0.1 acres (4,356 sqft)
        guard let acres = effectiveAcres else { return "N/A" }
        let urbanAvg = 0.1
        let ratio = acres / urbanAvg

        if ratio > 1 {
            return String(format: "%.1fx larger", ratio)
        } else {
            return String(format: "%.0f%% of avg", ratio * 100)
        }
    }

    private var comparisonToSuburbanAverage: String {
        // Average suburban lot is about 0.25 acres
        guard let acres = effectiveAcres else { return "N/A" }
        let suburbanAvg = 0.25
        let ratio = acres / suburbanAvg

        if ratio > 1 {
            return String(format: "%.1fx larger", ratio)
        } else {
            return String(format: "%.0f%% of avg", ratio * 100)
        }
    }

    private var effectiveAcres: Double? {
        if let acres = lotSizeAcres, acres > 0 {
            return acres
        }
        if let sqft = lotSizeSquareFeet, sqft > 0 {
            return Double(sqft) / 43560.0
        }
        return nil
    }

    // MARK: - Lot Features Section

    private var lotFeaturesSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Lot Features")
                .font(.caption)
                .fontWeight(.semibold)
                .foregroundStyle(.secondary)

            FlowLayout(spacing: 6) {
                ForEach(lotFeatures, id: \.self) { feature in
                    LotFeatureBadge(feature: feature)
                }
            }
        }
        .padding(12)
        .background(Color(UIColor.systemGray6))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}

// MARK: - Lot Size Category

private enum LotSizeCategory {
    case compact      // < 0.25 acres
    case standard     // 0.25 - 0.5 acres
    case large        // 0.5 - 1.0 acres
    case estate       // 1.0 - 5.0 acres
    case expansive    // > 5.0 acres

    var label: String {
        switch self {
        case .compact: return "Compact"
        case .standard: return "Standard"
        case .large: return "Large"
        case .estate: return "Estate"
        case .expansive: return "Expansive"
        }
    }

    var diagramHeight: CGFloat {
        switch self {
        case .compact: return 100
        case .standard: return 120
        case .large: return 140
        case .estate: return 160
        case .expansive: return 180
        }
    }
}

// MARK: - Tree Icon

private struct TreeIcon: View {
    var body: some View {
        ZStack {
            // Trunk
            Rectangle()
                .fill(Color(red: 0.4, green: 0.25, blue: 0.15))
                .frame(width: 4, height: 8)
                .offset(y: 6)

            // Foliage
            Circle()
                .fill(Color.green.opacity(0.6))
                .frame(width: 16, height: 16)
                .offset(y: -2)
        }
        .frame(width: 20, height: 20)
    }
}

// MARK: - Lot Comparison Item

private struct LotComparisonItem: View {
    let label: String
    let value: String
    let isPositive: Bool

    var body: some View {
        VStack(spacing: 2) {
            Text(value)
                .font(.caption)
                .fontWeight(.semibold)
                .foregroundStyle(isPositive ? .green : .orange)

            Text(label)
                .font(.system(size: 9))
                .foregroundStyle(.tertiary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 8)
        .background(Color.gray.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 6))
    }
}

// MARK: - Lot Feature Badge

private struct LotFeatureBadge: View {
    let feature: String

    private var icon: String {
        let lowercased = feature.lowercased()

        if lowercased.contains("wooded") || lowercased.contains("trees") {
            return "tree.fill"
        }
        if lowercased.contains("level") || lowercased.contains("flat") {
            return "slider.horizontal.below.rectangle"
        }
        if lowercased.contains("corner") {
            return "rectangle.portrait.arrowtriangle.2.outward"
        }
        if lowercased.contains("water") || lowercased.contains("pond") || lowercased.contains("stream") {
            return "water.waves"
        }
        if lowercased.contains("view") || lowercased.contains("scenic") {
            return "mountain.2.fill"
        }
        if lowercased.contains("cleared") || lowercased.contains("open") {
            return "sun.max.fill"
        }
        if lowercased.contains("slope") || lowercased.contains("hill") {
            return "chart.line.uptrend.xyaxis"
        }
        if lowercased.contains("fence") {
            return "square.grid.3x3.topleft.filled"
        }

        return "leaf.fill"
    }

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: icon)
                .font(.system(size: 10))
                .foregroundStyle(.green)

            Text(feature)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(Color.green.opacity(0.1))
        .clipShape(Capsule())
    }
}

// MARK: - Preview
// Preview disabled - PropertyDetail.mockProperty() not implemented
// #Preview {
//     ScrollView {
//         VStack(spacing: 20) {
//             LotVisualizationView(
//                 property: PropertyDetail.mockProperty()
//             )
//         }
//         .padding()
//     }
// }
