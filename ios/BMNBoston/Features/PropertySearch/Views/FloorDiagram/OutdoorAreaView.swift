//
//  OutdoorAreaView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Deck, patio, pool visualization for floor layout modal
//

import SwiftUI

/// Visualizes outdoor amenities: deck, patio, pool, spa
struct OutdoorAreaView: View {
    let property: PropertyDetail

    // MARK: - Computed Properties

    private var hasPool: Bool {
        property.hasPool
    }

    private var hasSpa: Bool {
        property.hasSpa
    }

    private var poolFeatures: [String] {
        guard let features = property.poolFeatures else { return [] }
        return features
            .components(separatedBy: ",")
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
    }

    private var spaFeatures: [String] {
        guard let features = property.spaFeatures else { return [] }
        return features
            .components(separatedBy: ",")
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
    }

    private var patioFeatures: [String] {
        guard let features = property.patioAndPorchFeatures else { return [] }
        return features
            .components(separatedBy: ",")
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
    }

    private var lotSizeDisplay: String? {
        if let acres = property.lotSizeAcres, acres > 0 {
            return String(format: "%.2f acre lot", acres)
        }
        if let sqft = property.lotSizeSquareFeet, sqft > 0 {
            let formatter = NumberFormatter()
            formatter.numberStyle = .decimal
            if let formatted = formatter.string(from: NSNumber(value: sqft)) {
                return "\(formatted) sq ft lot"
            }
        }
        return nil
    }

    // MARK: - Body

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            // Section header
            HStack(spacing: 8) {
                Image(systemName: "sun.max.fill")
                    .font(.system(size: 14))
                    .foregroundStyle(.yellow)

                Text("Outdoor Areas")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)

                Spacer()

                // Lot size badge
                if let lotSize = lotSizeDisplay {
                    Text(lotSize)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(Color.green.opacity(0.1))
                        .clipShape(Capsule())
                }
            }

            // Visual diagram
            outdoorDiagram

            // Feature lists
            featureLists
        }
        .padding(12)
        .background(Color.green.opacity(0.04))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }

    // MARK: - Outdoor Diagram

    private var outdoorDiagram: some View {
        HStack(spacing: 12) {
            // Building footprint (simplified)
            buildingFootprint

            // Outdoor elements
            VStack(alignment: .leading, spacing: 8) {
                // Deck/Patio area
                if !patioFeatures.isEmpty {
                    patioVisualization
                }

                // Pool
                if hasPool {
                    poolVisualization
                }

                // Spa
                if hasSpa {
                    spaVisualization
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(8)
        .background(
            // Grass-like background
            LinearGradient(
                colors: [
                    Color.green.opacity(0.1),
                    Color.green.opacity(0.15)
                ],
                startPoint: .top,
                endPoint: .bottom
            )
        )
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    // MARK: - Building Footprint

    private var buildingFootprint: some View {
        ZStack {
            // Building shadow
            Rectangle()
                .fill(Color.gray.opacity(0.2))
                .frame(width: 60, height: 80)
                .offset(x: 2, y: 2)

            // Building
            Rectangle()
                .fill(Color.gray.opacity(0.4))
                .frame(width: 60, height: 80)
                .overlay(
                    VStack(spacing: 2) {
                        ForEach(0..<3, id: \.self) { _ in
                            HStack(spacing: 4) {
                                Rectangle()
                                    .fill(Color.white.opacity(0.3))
                                    .frame(width: 8, height: 6)
                                Rectangle()
                                    .fill(Color.white.opacity(0.3))
                                    .frame(width: 8, height: 6)
                            }
                        }
                    }
                )
        }
    }

    // MARK: - Patio Visualization

    private var patioVisualization: some View {
        HStack(spacing: 6) {
            // Wood deck texture
            ZStack {
                RoundedRectangle(cornerRadius: 4)
                    .fill(Color(red: 0.6, green: 0.45, blue: 0.3).opacity(0.6))
                    .frame(width: 50, height: 30)

                // Plank lines
                VStack(spacing: 3) {
                    ForEach(0..<4, id: \.self) { _ in
                        Rectangle()
                            .fill(Color(red: 0.5, green: 0.35, blue: 0.2).opacity(0.4))
                            .frame(height: 1)
                    }
                }
                .frame(width: 46)
            }

            VStack(alignment: .leading, spacing: 2) {
                Text("Deck/Patio")
                    .font(.caption2)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)

                Text(patioFeatures.prefix(2).joined(separator: ", "))
                    .font(.system(size: 9))
                    .foregroundStyle(.tertiary)
                    .lineLimit(1)
            }
        }
    }

    // MARK: - Pool Visualization

    private var poolVisualization: some View {
        HStack(spacing: 6) {
            // Pool shape
            ZStack {
                // Pool water
                RoundedRectangle(cornerRadius: 6)
                    .fill(
                        LinearGradient(
                            colors: [
                                Color.blue.opacity(0.5),
                                Color.cyan.opacity(0.6)
                            ],
                            startPoint: .topLeading,
                            endPoint: .bottomTrailing
                        )
                    )
                    .frame(width: 50, height: 28)

                // Water ripple effect
                Ellipse()
                    .stroke(Color.white.opacity(0.3), lineWidth: 0.5)
                    .frame(width: 20, height: 10)
                    .offset(x: -8, y: -4)

                Ellipse()
                    .stroke(Color.white.opacity(0.2), lineWidth: 0.5)
                    .frame(width: 12, height: 6)
                    .offset(x: 10, y: 6)
            }

            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 4) {
                    Text("Pool")
                        .font(.caption2)
                        .fontWeight(.semibold)
                        .foregroundStyle(.blue)

                    Image(systemName: "figure.pool.swim")
                        .font(.system(size: 10))
                        .foregroundStyle(.blue.opacity(0.7))
                }

                if !poolFeatures.isEmpty {
                    Text(poolFeatures.prefix(2).joined(separator: ", "))
                        .font(.system(size: 9))
                        .foregroundStyle(.tertiary)
                        .lineLimit(1)
                }
            }
        }
    }

    // MARK: - Spa Visualization

    private var spaVisualization: some View {
        HStack(spacing: 6) {
            // Spa/hot tub shape
            ZStack {
                // Tub
                Circle()
                    .fill(
                        LinearGradient(
                            colors: [
                                Color.blue.opacity(0.6),
                                Color.cyan.opacity(0.5)
                            ],
                            startPoint: .top,
                            endPoint: .bottom
                        )
                    )
                    .frame(width: 26, height: 26)

                // Bubbles
                ForEach(0..<5, id: \.self) { i in
                    Circle()
                        .fill(Color.white.opacity(0.4))
                        .frame(width: 3, height: 3)
                        .offset(
                            x: CGFloat([-6, 4, -2, 6, 0][i]),
                            y: CGFloat([2, -4, 6, 2, -6][i])
                        )
                }
            }

            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 4) {
                    Text("Spa/Hot Tub")
                        .font(.caption2)
                        .fontWeight(.semibold)
                        .foregroundStyle(.blue)

                    Image(systemName: "bubbles.and.sparkles.fill")
                        .font(.system(size: 10))
                        .foregroundStyle(.blue.opacity(0.7))
                }

                if !spaFeatures.isEmpty {
                    Text(spaFeatures.prefix(2).joined(separator: ", "))
                        .font(.system(size: 9))
                        .foregroundStyle(.tertiary)
                        .lineLimit(1)
                }
            }
        }
    }

    // MARK: - Feature Lists

    @ViewBuilder
    private var featureLists: some View {
        if !patioFeatures.isEmpty || !poolFeatures.isEmpty || !spaFeatures.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                // Patio features
                if patioFeatures.count > 2 {
                    OutdoorFeatureList(
                        title: "Deck & Patio",
                        icon: "rectangle.stack.fill",
                        features: patioFeatures,
                        color: Color(red: 0.6, green: 0.45, blue: 0.3)
                    )
                }

                // Pool features
                if poolFeatures.count > 2 {
                    OutdoorFeatureList(
                        title: "Pool",
                        icon: "figure.pool.swim",
                        features: poolFeatures,
                        color: .blue
                    )
                }

                // Spa features
                if spaFeatures.count > 2 {
                    OutdoorFeatureList(
                        title: "Spa",
                        icon: "bubbles.and.sparkles.fill",
                        features: spaFeatures,
                        color: .cyan
                    )
                }
            }
        }
    }
}

// MARK: - Outdoor Feature List

private struct OutdoorFeatureList: View {
    let title: String
    let icon: String
    let features: [String]
    let color: Color

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 4) {
                Image(systemName: icon)
                    .font(.system(size: 10))
                    .foregroundStyle(color)

                Text(title)
                    .font(.caption2)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)
            }

            Text(features.joined(separator: " • "))
                .font(.caption2)
                .foregroundStyle(.tertiary)
        }
    }
}

// MARK: - Preview
// Preview disabled - PropertyDetail.mockProperty() not implemented
// #Preview("Full Outdoor Features") {
//     OutdoorAreaView(
//         property: PropertyDetail.mockProperty()
//     )
//     .padding()
// }
