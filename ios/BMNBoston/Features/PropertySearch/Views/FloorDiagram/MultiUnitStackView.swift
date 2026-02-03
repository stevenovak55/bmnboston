//
//  MultiUnitStackView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Multi-family property unit stacked visualization
//

import SwiftUI

/// Displays stacked units for multi-family properties with rent info
struct MultiUnitStackView: View {
    let property: PropertyDetail

    // MARK: - Computed Properties

    private var unitRents: [UnitRent] {
        property.unitRents ?? []
    }

    private var hasUnitData: Bool {
        !unitRents.isEmpty
    }

    private var totalUnits: Int {
        unitRents.count
    }

    // MARK: - Body

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Section header
            HStack(spacing: 8) {
                Image(systemName: "building.2.fill")
                    .font(.system(size: 14))
                    .foregroundStyle(AppColors.brandTeal)

                Text("Units (\(totalUnits))")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)

                Spacer()

                // Total income badge if available
                if let totalIncome = calculateTotalMonthlyIncome() {
                    HStack(spacing: 4) {
                        Image(systemName: "dollarsign.circle.fill")
                            .font(.system(size: 12))
                            .foregroundStyle(.green)
                        Text(formatCurrency(totalIncome) + "/mo")
                            .font(.caption)
                            .fontWeight(.medium)
                            .foregroundStyle(.green)
                    }
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(Color.green.opacity(0.1))
                    .clipShape(Capsule())
                }
            }

            if hasUnitData {
                // Unit stack
                VStack(spacing: 0) {
                    ForEach(unitRents) { unitRent in
                        UnitRentCardView(unitRent: unitRent)

                        if unitRent.id != unitRents.last?.id {
                            Divider()
                                .padding(.leading, 60)
                        }
                    }
                }
                .background(Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
                .overlay(
                    RoundedRectangle(cornerRadius: 12)
                        .stroke(Color.gray.opacity(0.2), lineWidth: 1)
                )

                // Summary stats
                unitSummaryStats
            } else {
                // Placeholder when no unit data available
                noUnitDataView
            }
        }
        .padding(12)
        .background(Color.gray.opacity(0.04))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - No Data View

    private var noUnitDataView: some View {
        VStack(spacing: 8) {
            Image(systemName: "building.2")
                .font(.system(size: 32))
                .foregroundStyle(.tertiary)

            Text("No unit rent data available")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 24)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(Color.gray.opacity(0.2), lineWidth: 1)
        )
    }

    // MARK: - Summary Stats

    private var unitSummaryStats: some View {
        HStack(spacing: 16) {
            // Total units
            StatPill(
                icon: "door.left.hand.closed",
                value: "\(totalUnits)",
                label: "Units"
            )

            // Average rent
            if let avgRent = calculateAverageRent() {
                StatPill(
                    icon: "dollarsign",
                    value: formatCurrency(avgRent),
                    label: "Avg Rent"
                )
            }

            // Owner occupied if available
            if let ownerOccupied = property.ownerOccupiedUnits, ownerOccupied > 0 {
                StatPill(
                    icon: "person.fill",
                    value: "\(ownerOccupied)",
                    label: "Owner Occ."
                )
            }
        }
    }

    // MARK: - Helper Functions

    private func calculateTotalMonthlyIncome() -> Int? {
        guard !unitRents.isEmpty else { return nil }
        let total = unitRents.reduce(0.0) { $0 + $1.rent }
        return total > 0 ? Int(total) : nil
    }

    private func calculateAverageRent() -> Int? {
        guard !unitRents.isEmpty else { return nil }
        let total = unitRents.reduce(0.0) { $0 + $1.rent }
        return total > 0 ? Int(total / Double(unitRents.count)) : nil
    }

    private func formatCurrency(_ value: Int) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter.string(from: NSNumber(value: value)) ?? "$\(value)"
    }
}

// MARK: - Unit Rent Card View

private struct UnitRentCardView: View {
    let unitRent: UnitRent

    var body: some View {
        HStack(spacing: 12) {
            // Unit number badge
            ZStack {
                RoundedRectangle(cornerRadius: 8)
                    .fill(AppColors.brandTeal.opacity(0.15))
                    .frame(width: 44, height: 44)

                VStack(spacing: 0) {
                    Image(systemName: "door.left.hand.closed")
                        .font(.system(size: 14))
                        .foregroundStyle(AppColors.brandTeal)

                    Text("#\(unitRent.unit)")
                        .font(.caption2)
                        .fontWeight(.semibold)
                        .foregroundStyle(AppColors.brandTeal)
                }
            }

            // Unit info
            VStack(alignment: .leading, spacing: 4) {
                Text("Unit \(unitRent.unit)")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)

                if let lease = unitRent.lease, !lease.isEmpty {
                    Text(lease)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Spacer()

            // Rent amount
            VStack(alignment: .trailing, spacing: 2) {
                Text(unitRent.formattedRent)
                    .font(.subheadline)
                    .fontWeight(.bold)
                    .foregroundStyle(AppColors.brandTeal)

                Text("/month")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
        .padding(.vertical, 12)
        .padding(.horizontal, 12)
    }
}

// MARK: - Stat Pill

private struct StatPill: View {
    let icon: String
    let value: String
    let label: String

    var body: some View {
        VStack(spacing: 4) {
            HStack(spacing: 4) {
                Image(systemName: icon)
                    .font(.system(size: 10))
                    .foregroundStyle(.secondary)
                Text(value)
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.primary)
            }

            Text(label)
                .font(.caption2)
                .foregroundStyle(.tertiary)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(Color.gray.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }
}

// MARK: - Preview
// Preview disabled - requires property data from API
// #Preview("Multi-Family Units") {
//     ScrollView {
//         MultiUnitStackView(
//             property: PropertyDetail.mockProperty()
//         )
//         .padding()
//     }
// }
