//
//  PropertyComparisonView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Property Comparison Feature (v237 → v238)
//

import SwiftUI

struct PropertyComparisonView: View {
    let properties: [Property]
    @Environment(\.dismiss) private var dismiss
    @State private var selectedPropertyIndex: Int = 0

    // Comparison sections with attributes
    private enum ComparisonSection: String, CaseIterable {
        case price = "Price & Value"
        case size = "Size & Layout"
        case property = "Property Details"
        case parking = "Parking"
        case market = "Market Info"
        case features = "Features"
        case location = "Location"
    }

    private enum ComparisonAttribute: String, CaseIterable {
        // Price & Value
        case price = "Price"
        case pricePerSqft = "Price/Sq Ft"
        case priceReduction = "Price Reduced"

        // Size & Layout
        case beds = "Bedrooms"
        case baths = "Bathrooms"
        case sqft = "Square Feet"
        case stories = "Stories"
        case lotSize = "Lot Size"

        // Property Details
        case propertyType = "Property Type"
        case yearBuilt = "Year Built"
        case style = "Style"

        // Parking
        case garage = "Garage Spaces"
        case parkingTotal = "Total Parking"

        // Market Info
        case status = "Status"
        case dom = "Days on Market"
        case openHouse = "Open House"

        // Features
        case pool = "Pool"
        case fireplace = "Fireplace"
        case cooling = "Central AC"
        case waterfront = "Waterfront"
        case view = "View"
        case outdoorSpace = "Outdoor Space"

        // Location
        case neighborhood = "Neighborhood"
        case schoolGrade = "School District"

        var section: ComparisonSection {
            switch self {
            case .price, .pricePerSqft, .priceReduction:
                return .price
            case .beds, .baths, .sqft, .stories, .lotSize:
                return .size
            case .propertyType, .yearBuilt, .style:
                return .property
            case .garage, .parkingTotal:
                return .parking
            case .status, .dom, .openHouse:
                return .market
            case .pool, .fireplace, .cooling, .waterfront, .view, .outdoorSpace:
                return .features
            case .neighborhood, .schoolGrade:
                return .location
            }
        }

        var highlightBest: HighlightType? {
            switch self {
            case .price, .pricePerSqft, .dom:
                return .lowest
            case .beds, .baths, .sqft, .stories, .lotSize, .garage, .parkingTotal, .yearBuilt:
                return .highest
            case .priceReduction:
                return .highest  // Bigger discount is better
            default:
                return nil  // No highlight for boolean/text attributes
            }
        }

        static func attributesForSection(_ section: ComparisonSection) -> [ComparisonAttribute] {
            allCases.filter { $0.section == section }
        }
    }

    private enum HighlightType {
        case lowest
        case highest
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                // Property carousel header
                propertyCarousel

                Divider()

                // Comparison table with sections
                comparisonTable
            }
            .navigationTitle("Compare \(properties.count) Properties")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") {
                        dismiss()
                    }
                }
            }
        }
    }

    // MARK: - Property Carousel

    private var propertyCarousel: some View {
        ScrollViewReader { proxy in
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 12) {
                    ForEach(Array(properties.enumerated()), id: \.element.id) { index, property in
                        ComparisonPropertyCard(
                            property: property,
                            isSelected: index == selectedPropertyIndex,
                            index: index + 1
                        )
                        .id(index)
                        .onTapGesture {
                            withAnimation(.spring(response: 0.3)) {
                                selectedPropertyIndex = index
                            }
                        }
                    }
                }
                .padding(.horizontal)
                .padding(.vertical, 12)
            }
            .onChange(of: selectedPropertyIndex) { newValue in
                withAnimation {
                    proxy.scrollTo(newValue, anchor: .center)
                }
            }
        }
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Comparison Table

    private var comparisonTable: some View {
        ScrollView {
            LazyVStack(spacing: 0) {
                ForEach(ComparisonSection.allCases, id: \.rawValue) { section in
                    let attributes = ComparisonAttribute.attributesForSection(section)
                    let hasData = attributes.contains { attributeHasData($0) }

                    if hasData {
                        sectionHeader(section)

                        ForEach(attributes, id: \.rawValue) { attribute in
                            if attributeHasData(attribute) {
                                comparisonRow(for: attribute)
                            }
                        }
                    }
                }
            }
        }
    }

    private func sectionHeader(_ section: ComparisonSection) -> some View {
        HStack {
            Text(section.rawValue)
                .font(.headline)
                .foregroundStyle(.primary)
            Spacer()
        }
        .padding(.horizontal, 16)
        .padding(.top, 20)
        .padding(.bottom, 8)
        .background(Color(.systemGroupedBackground))
    }

    private func attributeHasData(_ attribute: ComparisonAttribute) -> Bool {
        // Check if at least one property has data for this attribute
        properties.contains { property in
            let value = getValue(for: attribute, property: property)
            return value != "—" && value != "No" && !value.isEmpty
        }
    }

    private func comparisonRow(for attribute: ComparisonAttribute) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            // Attribute label
            Text(attribute.rawValue)
                .font(.subheadline)
                .fontWeight(.medium)
                .foregroundStyle(.secondary)
                .padding(.horizontal, 16)
                .padding(.top, 12)

            // Values row (horizontal scroll)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(Array(properties.enumerated()), id: \.element.id) { index, property in
                        let value = getValue(for: attribute, property: property)
                        let isBest = isBestValue(for: attribute, property: property)
                        let isFeature = isFeatureAttribute(attribute)

                        ComparisonValueCell(
                            value: value,
                            isBest: isBest,
                            highlightColor: isBest ? highlightColor(for: attribute) : nil,
                            index: index + 1,
                            isFeature: isFeature
                        )
                    }
                }
                .padding(.horizontal, 16)
                .padding(.bottom, 12)
            }

            Divider()
                .padding(.leading, 16)
        }
    }

    private func isFeatureAttribute(_ attribute: ComparisonAttribute) -> Bool {
        switch attribute {
        case .pool, .fireplace, .cooling, .waterfront, .view, .outdoorSpace, .openHouse:
            return true
        default:
            return false
        }
    }

    // MARK: - Value Extraction

    private func getValue(for attribute: ComparisonAttribute, property: Property) -> String {
        switch attribute {
        case .price:
            return property.formattedPrice

        case .pricePerSqft:
            if let sqft = property.sqft, sqft > 0 {
                let pricePerSqft = property.price / sqft
                return "$\(pricePerSqft.formatted())"
            }
            return "—"

        case .priceReduction:
            if let reduction = property.priceReductionAmount {
                let formatted = reduction >= 1000 ? "\(reduction / 1000)K" : "\(reduction)"
                return "-$\(formatted)"
            }
            return "—"

        case .beds:
            return "\(property.beds)"

        case .baths:
            return property.formattedBathroomsDetailed

        case .sqft:
            if let sqft = property.sqft {
                return sqft.formatted() + " sf"
            }
            return "—"

        case .stories:
            if let stories = property.stories, stories > 0 {
                return "\(stories)"
            }
            return "—"

        case .lotSize:
            if let lot = property.lotSize, lot > 0 {
                if lot >= 1 {
                    return String(format: "%.2f acres", lot)
                } else {
                    let sqft = Int(lot * 43560)
                    return "\(sqft.formatted()) sf"
                }
            }
            return "—"

        case .propertyType:
            return property.propertyType

        case .yearBuilt:
            if let year = property.yearBuilt {
                return "\(year)"
            }
            return "—"

        case .style:
            return property.architecturalStyle ?? "—"

        case .garage:
            if let garage = property.garageSpaces, garage > 0 {
                return "\(garage)"
            }
            return "—"

        case .parkingTotal:
            if let parking = property.parkingTotal, parking > 0 {
                return "\(parking)"
            }
            return "—"

        case .status:
            return property.standardStatus.displayName

        case .dom:
            if let dom = property.dom {
                return "\(dom) days"
            }
            return "—"

        case .openHouse:
            if property.hasOpenHouse {
                if let nextOH = property.nextOpenHouse {
                    return formatOpenHouseDate(nextOH)
                }
                return "Yes"
            }
            return "No"

        case .pool:
            return property.hasPool ? "Yes" : "No"

        case .fireplace:
            return property.hasFireplace ? "Yes" : "No"

        case .cooling:
            return property.hasCooling ? "Yes" : "No"

        case .waterfront:
            return property.hasWaterfront ? "Yes" : "No"

        case .view:
            return property.hasView ? "Yes" : "No"

        case .outdoorSpace:
            return property.hasOutdoorSpace ? "Yes" : "No"

        case .neighborhood:
            return property.neighborhood ?? "—"

        case .schoolGrade:
            if let grade = property.districtGrade {
                return "Grade \(grade)"
            }
            return "—"
        }
    }

    private func formatOpenHouseDate(_ dateString: String) -> String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        if let date = formatter.date(from: dateString) {
            let displayFormatter = DateFormatter()
            displayFormatter.dateFormat = "MMM d"
            return displayFormatter.string(from: date)
        }
        return "Scheduled"
    }

    private func getNumericValue(for attribute: ComparisonAttribute, property: Property) -> Double? {
        switch attribute {
        case .price:
            return Double(property.price)
        case .beds:
            return Double(property.beds)
        case .baths:
            return property.baths
        case .sqft:
            return property.sqft.map { Double($0) }
        case .pricePerSqft:
            if let sqft = property.sqft, sqft > 0 {
                return Double(property.price) / Double(sqft)
            }
            return nil
        case .lotSize:
            return property.lotSize
        case .yearBuilt:
            return property.yearBuilt.map { Double($0) }
        case .dom:
            return property.dom.map { Double($0) }
        case .garage:
            return property.garageSpaces.map { Double($0) }
        case .parkingTotal:
            return property.parkingTotal.map { Double($0) }
        case .stories:
            return property.stories.map { Double($0) }
        case .priceReduction:
            return property.priceReductionAmount.map { Double($0) }
        default:
            return nil
        }
    }

    private func isBestValue(for attribute: ComparisonAttribute, property: Property) -> Bool {
        guard let highlightType = attribute.highlightBest,
              let currentValue = getNumericValue(for: attribute, property: property) else {
            return false
        }

        let allValues = properties.compactMap { getNumericValue(for: attribute, property: $0) }
        guard allValues.count > 1 else { return false }

        switch highlightType {
        case .lowest:
            let minValue = allValues.min()
            return currentValue == minValue
        case .highest:
            let maxValue = allValues.max()
            return currentValue == maxValue
        }
    }

    private func highlightColor(for attribute: ComparisonAttribute) -> Color {
        guard let highlightType = attribute.highlightBest else {
            return .clear
        }
        switch highlightType {
        case .lowest:
            return .green
        case .highest:
            return .blue
        }
    }
}

// MARK: - Comparison Property Card

struct ComparisonPropertyCard: View {
    let property: Property
    let isSelected: Bool
    let index: Int

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            // Image with index badge
            ZStack(alignment: .topLeading) {
                AsyncImage(url: property.primaryImageURL) { phase in
                    switch phase {
                    case .empty:
                        Rectangle()
                            .fill(AppColors.shimmerBase)
                            .overlay(ProgressView())
                    case .success(let image):
                        image
                            .resizable()
                            .aspectRatio(contentMode: .fill)
                    case .failure:
                        Rectangle()
                            .fill(AppColors.shimmerBase)
                            .overlay(
                                Image(systemName: "photo")
                                    .foregroundStyle(.secondary)
                            )
                    @unknown default:
                        EmptyView()
                    }
                }
                .frame(width: 140, height: 100)
                .clipped()
                .clipShape(RoundedRectangle(cornerRadius: 8))

                // Index badge
                Text("\(index)")
                    .font(.caption2)
                    .fontWeight(.bold)
                    .foregroundStyle(.white)
                    .frame(width: 20, height: 20)
                    .background(AppColors.brandTeal)
                    .clipShape(Circle())
                    .padding(6)
            }

            // Price
            Text(property.formattedPrice)
                .font(.subheadline)
                .fontWeight(.bold)
                .lineLimit(1)

            // Address
            Text(property.address)
                .font(.caption2)
                .foregroundStyle(.secondary)
                .lineLimit(1)

            // City
            Text(property.city)
                .font(.caption2)
                .foregroundStyle(.tertiary)
                .lineLimit(1)
        }
        .frame(width: 140)
        .padding(10)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 2)
        )
        .shadow(color: AppColors.shadowLight, radius: isSelected ? 6 : 3, x: 0, y: 2)
    }
}

// MARK: - Comparison Value Cell

struct ComparisonValueCell: View {
    let value: String
    let isBest: Bool
    let highlightColor: Color?
    let index: Int
    var isFeature: Bool = false

    var body: some View {
        VStack(spacing: 4) {
            // Property index indicator
            Text("\(index)")
                .font(.caption2)
                .fontWeight(.medium)
                .foregroundStyle(.tertiary)

            // Value with icon for features
            if isFeature && value == "Yes" {
                HStack(spacing: 4) {
                    Image(systemName: "checkmark.circle.fill")
                        .font(.caption)
                        .foregroundStyle(.green)
                    Text(value)
                        .font(.subheadline)
                        .fontWeight(.medium)
                }
            } else if isFeature && value == "No" {
                HStack(spacing: 4) {
                    Image(systemName: "minus.circle")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Text(value)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            } else {
                Text(value)
                    .font(.subheadline)
                    .fontWeight(isBest ? .bold : .regular)
                    .foregroundStyle(isBest ? (highlightColor ?? .primary) : .primary)
            }
        }
        .frame(width: 100)
        .padding(.vertical, 8)
        .padding(.horizontal, 12)
        .background(
            RoundedRectangle(cornerRadius: 8)
                .fill(isBest ? (highlightColor?.opacity(0.1) ?? Color.clear) : Color(.secondarySystemGroupedBackground))
        )
        .overlay(
            RoundedRectangle(cornerRadius: 8)
                .stroke(isBest ? (highlightColor ?? .clear) : .clear, lineWidth: 1.5)
        )
    }
}

#Preview {
    Text("PropertyComparisonView Preview")
}
