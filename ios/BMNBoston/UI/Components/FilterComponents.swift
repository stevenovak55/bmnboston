//
//  FilterComponents.swift
//  BMNBoston
//
//  Reusable filter UI components matching MLD web plugin styling
//

import SwiftUI

// MARK: - Filter Button Group (Single Select)

struct FilterButtonGroup<T: Hashable>: View {
    let options: [(value: T, label: String)]
    @Binding var selection: T?
    var allowsNone: Bool = true

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(options, id: \.value) { option in
                    FilterButton(
                        label: option.label,
                        isSelected: selection == option.value,
                        action: {
                            if selection == option.value && allowsNone {
                                selection = nil
                            } else {
                                selection = option.value
                            }
                        }
                    )
                }
            }
            .padding(.horizontal, 4)
        }
    }
}

// MARK: - Filter Button Group (Multi Select)

struct FilterButtonGroupMulti<T: Hashable>: View {
    let options: [(value: T, label: String)]
    @Binding var selections: Set<T>

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(options, id: \.value) { option in
                    FilterButton(
                        label: option.label,
                        isSelected: selections.contains(option.value),
                        action: {
                            if selections.contains(option.value) {
                                selections.remove(option.value)
                            } else {
                                selections.insert(option.value)
                            }
                        }
                    )
                }
            }
            .padding(.horizontal, 4)
        }
    }
}

// MARK: - Filter Button

struct FilterButton: View {
    let label: String
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(label)
                .font(.subheadline)
                .fontWeight(isSelected ? .semibold : .regular)
                .foregroundStyle(isSelected ? .white : AppColors.textSecondary)
                .padding(.horizontal, 16)
                .padding(.vertical, 10)
                .background(
                    isSelected
                        ? AppColors.brandTeal
                        : Color(.secondarySystemBackground)
                )
                .clipShape(Capsule())
                .overlay(
                    Capsule()
                        .stroke(isSelected ? AppColors.brandTeal : AppColors.border, lineWidth: 1)
                )
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Beds Button Group (Matching Web: Any, 1, 2, 3, 4, 5+)

struct BedsFilterGroup: View {
    @Binding var minBeds: Int?

    private let options: [(value: Int?, label: String)] = [
        (nil, "Any"),
        (1, "1"),
        (2, "2"),
        (3, "3"),
        (4, "4"),
        (5, "5+")
    ]

    var body: some View {
        HStack(spacing: 8) {
            ForEach(options, id: \.label) { option in
                FilterButton(
                    label: option.label,
                    isSelected: minBeds == option.value,
                    action: { minBeds = option.value }
                )
            }
        }
    }
}

// MARK: - Baths Button Group (Matching Web: Any, 1+, 1.5+, 2+, 2.5+, 3+)

struct BathsFilterGroup: View {
    @Binding var minBaths: Double?

    private let options: [(value: Double?, label: String)] = [
        (nil, "Any"),
        (1.0, "1+"),
        (1.5, "1.5+"),
        (2.0, "2+"),
        (2.5, "2.5+"),
        (3.0, "3+")
    ]

    var body: some View {
        HStack(spacing: 8) {
            ForEach(options, id: \.label) { option in
                FilterButton(
                    label: option.label,
                    isSelected: minBaths == option.value,
                    action: { minBaths = option.value }
                )
            }
        }
    }
}

// MARK: - Property Type Selector (Horizontal Scroll)

struct PropertyTypeSelector: View {
    @Binding var selectedType: String?

    private let types = [
        ("Residential Lease", "For Rent"),
        ("Residential", "For Sale"),
        ("Commercial Lease", "Commercial"),
        ("Land", "Land"),
        ("Business Opportunity", "Business")
    ]

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 12) {
                ForEach(types, id: \.0) { (value, label) in
                    PropertyTypeButton(
                        label: label,
                        isSelected: selectedType == value,
                        action: {
                            if selectedType == value {
                                selectedType = nil
                            } else {
                                selectedType = value
                            }
                        }
                    )
                }
            }
            .padding(.horizontal)
        }
    }
}

struct PropertyTypeButton: View {
    let label: String
    let isSelected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(label)
                .font(.subheadline)
                .fontWeight(.medium)
                .foregroundStyle(isSelected ? .white : AppColors.textPrimary)
                .padding(.horizontal, 20)
                .padding(.vertical, 12)
                .background(
                    isSelected
                        ? AppColors.brandTeal
                        : Color(.systemBackground)
                )
                .clipShape(RoundedRectangle(cornerRadius: 8))
                .shadow(color: AppColors.shadowLight, radius: 4, x: 0, y: 2)
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Amenity Toggle

struct AmenityToggle: View {
    let label: String
    let icon: String
    @Binding var isOn: Bool

    var body: some View {
        Button {
            isOn.toggle()
        } label: {
            HStack(spacing: 8) {
                Image(systemName: icon)
                    .font(.system(size: 14))
                Text(label)
                    .font(.subheadline)
            }
            .foregroundStyle(isOn ? .white : AppColors.textSecondary)
            .padding(.horizontal, 14)
            .padding(.vertical, 10)
            .background(
                isOn
                    ? AppColors.brandTeal
                    : Color(.secondarySystemBackground)
            )
            .clipShape(Capsule())
            .overlay(
                Capsule()
                    .stroke(isOn ? AppColors.brandTeal : AppColors.border, lineWidth: 1)
            )
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Price Range Input

struct PriceRangeInput: View {
    @Binding var minPrice: Int?
    @Binding var maxPrice: Int?

    @State private var minText: String = ""
    @State private var maxText: String = ""

    var body: some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 4) {
                Text("Min Price")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                TextField("$0", text: $minText)
                    .keyboardType(.numberPad)
                    .textFieldStyle(.roundedBorder)
                    .onChange(of: minText) { newValue in
                        let filtered = newValue.filter { $0.isNumber }
                        minText = filtered
                        minPrice = Int(filtered)
                    }
            }

            Text("to")
                .foregroundStyle(.secondary)
                .padding(.top, 20)

            VStack(alignment: .leading, spacing: 4) {
                Text("Max Price")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                TextField("No Max", text: $maxText)
                    .keyboardType(.numberPad)
                    .textFieldStyle(.roundedBorder)
                    .onChange(of: maxText) { newValue in
                        let filtered = newValue.filter { $0.isNumber }
                        maxText = filtered
                        maxPrice = Int(filtered)
                    }
            }
        }
        .onAppear {
            if let min = minPrice { minText = String(min) }
            if let max = maxPrice { maxText = String(max) }
        }
    }
}

// MARK: - Square Footage Range Input

struct SqftRangeInput: View {
    @Binding var minSqft: Int?
    @Binding var maxSqft: Int?

    @State private var minText: String = ""
    @State private var maxText: String = ""

    var body: some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 4) {
                Text("Min Sqft")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                TextField("0", text: $minText)
                    .keyboardType(.numberPad)
                    .textFieldStyle(.roundedBorder)
                    .onChange(of: minText) { newValue in
                        let filtered = newValue.filter { $0.isNumber }
                        minText = filtered
                        minSqft = Int(filtered)
                    }
            }

            Text("to")
                .foregroundStyle(.secondary)
                .padding(.top, 20)

            VStack(alignment: .leading, spacing: 4) {
                Text("Max Sqft")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                TextField("No Max", text: $maxText)
                    .keyboardType(.numberPad)
                    .textFieldStyle(.roundedBorder)
                    .onChange(of: maxText) { newValue in
                        let filtered = newValue.filter { $0.isNumber }
                        maxText = filtered
                        maxSqft = Int(filtered)
                    }
            }
        }
        .onAppear {
            if let min = minSqft { minText = String(min) }
            if let max = maxSqft { maxText = String(max) }
        }
    }
}

// MARK: - Collapsible Section

struct CollapsibleFilterSection<Content: View>: View {
    let title: String
    @Binding var isExpanded: Bool
    @ViewBuilder let content: Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Button {
                withAnimation(.easeInOut(duration: 0.2)) {
                    isExpanded.toggle()
                }
            } label: {
                HStack {
                    Text(title)
                        .font(.headline)
                        .foregroundStyle(.primary)

                    Spacer()

                    Image(systemName: isExpanded ? "chevron.up" : "chevron.down")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .padding(.vertical, 12)
            }
            .buttonStyle(.plain)

            if isExpanded {
                content
                    .padding(.bottom, 16)
            }
        }
    }
}

// MARK: - Price Histogram Slider

struct PriceHistogramSlider: View {
    @Binding var minPrice: Int?
    @Binding var maxPrice: Int?
    let priceData: PriceDistributionData?
    let onPriceChanged: () -> Void
    var isRental: Bool = false  // v6.60.0: Support rental price ranges

    // Local state for slider values
    @State private var sliderMinValue: Double = 0
    @State private var sliderMaxValue: Double = 1
    @State private var minPriceText: String = ""
    @State private var maxPriceText: String = ""
    // v321: Track validation error for min > max
    @State private var showPriceValidationError: Bool = false

    private var dataMin: Double {
        Double(priceData?.min ?? 0)
    }

    private var dataMax: Double {
        // Use rental-appropriate max if no data provided
        Double(priceData?.displayMax ?? (isRental ? 20_000 : 1_000_000))
    }

    private var distribution: [Int] {
        priceData?.distribution ?? Array(repeating: 0, count: 20)
    }

    private var maxBarCount: Int {
        max(distribution.max() ?? 1, 1)
    }

    /// Check if we have valid histogram data to display
    private var hasHistogramData: Bool {
        guard let data = priceData else { return false }
        // Check if distribution has any non-zero values
        return data.distribution.contains { $0 > 0 }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            // Histogram (only show if we have actual data)
            if hasHistogramData {
                histogramView
                    .frame(height: 80)
            }

            // Dual range slider (always show)
            dualRangeSlider
                .frame(height: 30)

            // Price input fields (always show)
            priceInputFields

            // v321: Price validation error message
            if showPriceValidationError {
                HStack(spacing: 6) {
                    Image(systemName: "exclamationmark.triangle.fill")
                        .font(.caption)
                    Text("Min price cannot exceed max price")
                        .font(.caption)
                }
                .foregroundStyle(.orange)
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color.orange.opacity(0.1))
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }

            // Outlier info
            if let data = priceData, data.outlierCount > 0 {
                outlierInfo(count: data.outlierCount, abovePrice: data.displayMax)
            }
        }
        .onAppear {
            initializeSliderValues()
        }
        .onChange(of: priceData) { _ in
            initializeSliderValues()
        }
    }

    // MARK: - Histogram View

    private var histogramView: some View {
        GeometryReader { geometry in
            HStack(alignment: .bottom, spacing: 2) {
                ForEach(Array(distribution.enumerated()), id: \.offset) { index, count in
                    let isInRange = isBarInRange(index: index)
                    Rectangle()
                        .fill(isInRange ? AppColors.brandTeal : AppColors.shimmerBase)
                        .frame(height: barHeight(count: count, totalHeight: geometry.size.height))
                        .animation(.easeInOut(duration: 0.2), value: isInRange)
                }
            }
        }
    }

    private func barHeight(count: Int, totalHeight: CGFloat) -> CGFloat {
        guard maxBarCount > 0 else { return 0 }
        let ratio = CGFloat(count) / CGFloat(maxBarCount)
        return max(ratio * totalHeight, count > 0 ? 4 : 0)
    }

    private func isBarInRange(index: Int) -> Bool {
        let bucketCount = distribution.count
        guard bucketCount > 0 else { return false }

        let bucketSize = (dataMax - dataMin) / Double(bucketCount)
        let bucketMin = dataMin + Double(index) * bucketSize
        let bucketMax = bucketMin + bucketSize

        let selectedMin = minPrice.map { Double($0) } ?? dataMin
        let selectedMax = maxPrice.map { Double($0) } ?? dataMax

        return bucketMin < selectedMax && bucketMax > selectedMin
    }

    // MARK: - Dual Range Slider

    private var dualRangeSlider: some View {
        GeometryReader { geometry in
            ZStack(alignment: .leading) {
                // Track background
                RoundedRectangle(cornerRadius: 2)
                    .fill(AppColors.shimmerBase)
                    .frame(height: 4)

                // Selected range
                RoundedRectangle(cornerRadius: 2)
                    .fill(AppColors.brandTeal)
                    .frame(width: rangeWidth(in: geometry.size.width), height: 4)
                    .offset(x: rangeOffset(in: geometry.size.width))

                // Min thumb
                Circle()
                    .fill(.white)
                    .frame(width: 24, height: 24)
                    .shadow(color: .black.opacity(0.15), radius: 4, y: 2)
                    .overlay(Circle().stroke(AppColors.brandTeal, lineWidth: 2))
                    .offset(x: thumbOffset(value: sliderMinValue, in: geometry.size.width))
                    .gesture(
                        DragGesture()
                            .onChanged { value in
                                updateMinSlider(value: value, in: geometry.size.width)
                            }
                            .onEnded { _ in
                                onPriceChanged()
                            }
                    )

                // Max thumb
                Circle()
                    .fill(.white)
                    .frame(width: 24, height: 24)
                    .shadow(color: .black.opacity(0.15), radius: 4, y: 2)
                    .overlay(Circle().stroke(AppColors.brandTeal, lineWidth: 2))
                    .offset(x: thumbOffset(value: sliderMaxValue, in: geometry.size.width))
                    .gesture(
                        DragGesture()
                            .onChanged { value in
                                updateMaxSlider(value: value, in: geometry.size.width)
                            }
                            .onEnded { _ in
                                onPriceChanged()
                            }
                    )
            }
        }
    }

    private func thumbOffset(value: Double, in width: CGFloat) -> CGFloat {
        (width - 24) * value
    }

    private func rangeWidth(in width: CGFloat) -> CGFloat {
        (width - 24) * (sliderMaxValue - sliderMinValue)
    }

    private func rangeOffset(in width: CGFloat) -> CGFloat {
        (width - 24) * sliderMinValue + 12
    }

    private func updateMinSlider(value: DragGesture.Value, in width: CGFloat) {
        let newValue = max(0, min(value.location.x / (width - 24), sliderMaxValue - 0.05))
        sliderMinValue = newValue

        let price = Int(dataMin + newValue * (dataMax - dataMin))
        minPrice = price
        minPriceText = formatPriceInput(price)
    }

    private func updateMaxSlider(value: DragGesture.Value, in width: CGFloat) {
        let newValue = max(sliderMinValue + 0.05, min(value.location.x / (width - 24), 1))
        sliderMaxValue = newValue

        let price = Int(dataMin + newValue * (dataMax - dataMin))
        maxPrice = price
        maxPriceText = formatPriceInput(price)
    }

    // MARK: - Price Input Fields

    private var priceInputFields: some View {
        HStack(spacing: 8) {
            // Min Price with steppers
            VStack(alignment: .leading, spacing: 4) {
                Text("Min Price")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                HStack(spacing: 0) {
                    // Decrement button
                    Button {
                        decrementMinPrice()
                    } label: {
                        Image(systemName: "minus")
                            .font(.system(size: 12, weight: .bold))
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 32, height: 38)
                            .background(AppColors.brandTeal.opacity(0.1))
                    }
                    .buttonStyle(.plain)

                    // Text field
                    HStack(spacing: 2) {
                        Text("$")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        TextField("No min", text: $minPriceText)
                            .font(.subheadline)
                            .keyboardType(.numberPad)
                            .multilineTextAlignment(.center)
                            .frame(minWidth: 60)
                            .onChange(of: minPriceText) { newValue in
                                if let price = parsePrice(newValue) {
                                    minPrice = price
                                    updateSliderFromPrice()
                                    // v321: Validate min <= max
                                    validatePriceRange()
                                } else if newValue.isEmpty {
                                    minPrice = nil
                                    sliderMinValue = 0
                                    showPriceValidationError = false
                                }
                            }
                            .onSubmit {
                                onPriceChanged()
                            }
                    }
                    .padding(.horizontal, 6)

                    // Increment button
                    Button {
                        incrementMinPrice()
                    } label: {
                        Image(systemName: "plus")
                            .font(.system(size: 12, weight: .bold))
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 32, height: 38)
                            .background(AppColors.brandTeal.opacity(0.1))
                    }
                    .buttonStyle(.plain)
                }
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }

            Text("—")
                .font(.caption)
                .foregroundStyle(.secondary)
                .padding(.top, 16)

            // Max Price with steppers
            VStack(alignment: .leading, spacing: 4) {
                Text("Max Price")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                HStack(spacing: 0) {
                    // Decrement button
                    Button {
                        decrementMaxPrice()
                    } label: {
                        Image(systemName: "minus")
                            .font(.system(size: 12, weight: .bold))
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 32, height: 38)
                            .background(AppColors.brandTeal.opacity(0.1))
                    }
                    .buttonStyle(.plain)

                    // Text field
                    HStack(spacing: 2) {
                        Text("$")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        TextField("No max", text: $maxPriceText)
                            .font(.subheadline)
                            .keyboardType(.numberPad)
                            .multilineTextAlignment(.center)
                            .frame(minWidth: 60)
                            .onChange(of: maxPriceText) { newValue in
                                if let price = parsePrice(newValue) {
                                    maxPrice = price
                                    updateSliderFromPrice()
                                    // v321: Validate min <= max
                                    validatePriceRange()
                                } else if newValue.isEmpty {
                                    maxPrice = nil
                                    sliderMaxValue = 1
                                    showPriceValidationError = false
                                }
                            }
                            .onSubmit {
                                onPriceChanged()
                            }
                    }
                    .padding(.horizontal, 6)

                    // Increment button
                    Button {
                        incrementMaxPrice()
                    } label: {
                        Image(systemName: "plus")
                            .font(.system(size: 12, weight: .bold))
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 32, height: 38)
                            .background(AppColors.brandTeal.opacity(0.1))
                    }
                    .buttonStyle(.plain)
                }
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }
        }
    }

    // MARK: - Price Stepper Logic

    /// Smart increment based on current price and listing type:
    /// For Sales:
    /// - Under $500K: $10,000 increments
    /// - $500K-$1M: $25,000 increments
    /// - Over $1M: $50,000 increments
    /// For Rentals:
    /// - Under $2K: $100 increments
    /// - $2K-$5K: $250 increments
    /// - Over $5K: $500 increments
    private func priceIncrement(for price: Int) -> Int {
        if isRental {
            // Rental increments (monthly rent)
            if price < 2_000 {
                return 100
            } else if price < 5_000 {
                return 250
            } else {
                return 500
            }
        } else {
            // Sale price increments
            if price < 500_000 {
                return 10_000
            } else if price < 1_000_000 {
                return 25_000
            } else {
                return 50_000
            }
        }
    }

    private func incrementMinPrice() {
        let currentPrice = minPrice ?? 0
        let increment = priceIncrement(for: currentPrice)
        let newPrice = ((currentPrice / increment) + 1) * increment // Snap to increment
        minPrice = newPrice
        minPriceText = formatPriceInput(newPrice)
        updateSliderFromPrice()
        onPriceChanged()
        HapticManager.selection()
    }

    private func decrementMinPrice() {
        let currentPrice = minPrice ?? priceIncrement(for: 0)
        let increment = priceIncrement(for: currentPrice)
        let newPrice = max(0, ((currentPrice / increment) - 1) * increment) // Snap to increment
        if newPrice == 0 {
            minPrice = nil
            minPriceText = ""
        } else {
            minPrice = newPrice
            minPriceText = formatPriceInput(newPrice)
        }
        updateSliderFromPrice()
        onPriceChanged()
        HapticManager.selection()
    }

    private func incrementMaxPrice() {
        let currentPrice = maxPrice ?? (minPrice ?? 0)
        let increment = priceIncrement(for: currentPrice)
        let newPrice = ((currentPrice / increment) + 1) * increment // Snap to increment
        maxPrice = newPrice
        maxPriceText = formatPriceInput(newPrice)
        updateSliderFromPrice()
        onPriceChanged()
        HapticManager.selection()
    }

    private func decrementMaxPrice() {
        let currentPrice = maxPrice ?? Int(dataMax)
        let increment = priceIncrement(for: currentPrice)
        let newPrice = max(minPrice ?? 0, ((currentPrice / increment) - 1) * increment) // Snap to increment, don't go below min
        if newPrice <= (minPrice ?? 0) {
            maxPrice = nil
            maxPriceText = ""
        } else {
            maxPrice = newPrice
            maxPriceText = formatPriceInput(newPrice)
        }
        updateSliderFromPrice()
        onPriceChanged()
        HapticManager.selection()
    }

    // MARK: - Outlier Info

    private func outlierInfo(count: Int, abovePrice: Int) -> some View {
        HStack(spacing: 6) {
            Image(systemName: "info.circle")
                .font(.caption)
            Text("\(count) listings above \(formatPrice(abovePrice))")
                .font(.caption)
        }
        .foregroundStyle(.secondary)
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(Color(.tertiarySystemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    // MARK: - Helper Methods

    private func initializeSliderValues() {
        if let minVal = minPrice {
            sliderMinValue = Swift.max(0, Swift.min(1, Double(minVal - Int(dataMin)) / (dataMax - dataMin)))
            minPriceText = formatPriceInput(minVal)
        } else {
            sliderMinValue = 0
            minPriceText = ""
        }

        if let maxVal = maxPrice {
            sliderMaxValue = Swift.max(0, Swift.min(1, Double(maxVal - Int(dataMin)) / (dataMax - dataMin)))
            maxPriceText = formatPriceInput(maxVal)
        } else {
            sliderMaxValue = 1
            maxPriceText = ""
        }
    }

    private func updateSliderFromPrice() {
        if let minVal = minPrice {
            sliderMinValue = Swift.max(0, Swift.min(1, Double(minVal - Int(dataMin)) / (dataMax - dataMin)))
        }
        if let maxVal = maxPrice {
            sliderMaxValue = Swift.max(0, Swift.min(1, Double(maxVal - Int(dataMin)) / (dataMax - dataMin)))
        }
    }

    /// v321: Validate that min price doesn't exceed max price
    private func validatePriceRange() {
        if let minVal = minPrice, let maxVal = maxPrice {
            showPriceValidationError = minVal > maxVal
        } else {
            showPriceValidationError = false
        }
    }

    private func parsePrice(_ text: String) -> Int? {
        let cleaned = text.replacingOccurrences(of: "[^0-9]", with: "", options: .regularExpression)
        return Int(cleaned)
    }

    private func formatPriceInput(_ price: Int) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        formatter.groupingSeparator = ","
        return formatter.string(from: NSNumber(value: price)) ?? "\(price)"
    }

    private func formatPrice(_ price: Int) -> String {
        if price >= 1_000_000 {
            return "$\(price / 1_000_000).\(String(format: "%01d", (price % 1_000_000) / 100_000))M"
        } else if price >= 1000 {
            return "$\(price / 1000)K"
        }
        return "$\(price)"
    }
}

// MARK: - Minimum Value Slider (Single Thumb)

/// A single-thumb slider for selecting a minimum value
/// Used for bedrooms and bathrooms filters (0 = Any, 1-5+)
struct MinValueSlider: View {
    @Binding var value: Int?
    let range: ClosedRange<Int>
    let formatValue: (Int?) -> String
    var onEditingChanged: (() -> Void)?

    // Internal state for dragging
    @State private var isDragging = false

    private var sliderRange: Double {
        Double(range.upperBound - range.lowerBound)
    }

    private var currentValue: Double {
        Double(value ?? range.lowerBound)
    }

    var body: some View {
        VStack(spacing: 12) {
            // Current value label (centered, prominent)
            Text(formatValue(value))
                .font(.title2)
                .fontWeight(.semibold)
                .foregroundStyle(AppColors.brandTeal)
                .frame(maxWidth: .infinity)

            // Slider track and thumb
            GeometryReader { geometry in
                let trackWidth = geometry.size.width - 28 // Account for thumb width

                ZStack(alignment: .leading) {
                    // Track background
                    RoundedRectangle(cornerRadius: 4)
                        .fill(AppColors.shimmerBase)
                        .frame(height: 8)

                    // Filled track (from start to thumb)
                    RoundedRectangle(cornerRadius: 4)
                        .fill(AppColors.brandTeal)
                        .frame(width: filledWidth(trackWidth: trackWidth) + 14, height: 8)

                    // Thumb
                    Circle()
                        .fill(.white)
                        .frame(width: 28, height: 28)
                        .shadow(color: .black.opacity(0.2), radius: 4, y: 2)
                        .overlay(
                            Circle()
                                .stroke(isDragging ? AppColors.brandTeal : AppColors.border, lineWidth: 2)
                        )
                        .offset(x: thumbOffset(trackWidth: trackWidth))
                        .gesture(
                            DragGesture()
                                .onChanged { dragValue in
                                    isDragging = true
                                    let newValue = valueFromLocation(dragValue.location.x, trackWidth: trackWidth)
                                    if newValue == range.lowerBound {
                                        value = nil // 0 means "Any"
                                    } else {
                                        value = newValue
                                    }
                                }
                                .onEnded { _ in
                                    isDragging = false
                                    onEditingChanged?()
                                    HapticManager.selection()
                                }
                        )
                }
            }
            .frame(height: 28)

            // Step labels
            HStack {
                ForEach(range.lowerBound...range.upperBound, id: \.self) { step in
                    Text(step == range.lowerBound ? "Any" : (step == range.upperBound ? "\(step)+" : "\(step)"))
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .frame(maxWidth: .infinity)
                }
            }
        }
    }

    // MARK: - Helper Methods

    private func thumbOffset(trackWidth: CGFloat) -> CGFloat {
        let normalizedValue = (currentValue - Double(range.lowerBound)) / sliderRange
        return trackWidth * normalizedValue
    }

    private func filledWidth(trackWidth: CGFloat) -> CGFloat {
        let normalizedValue = (currentValue - Double(range.lowerBound)) / sliderRange
        return trackWidth * normalizedValue
    }

    private func valueFromLocation(_ x: CGFloat, trackWidth: CGFloat) -> Int {
        let normalizedX = max(0, min(trackWidth, x)) / trackWidth
        let rawValue = Double(range.lowerBound) + (normalizedX * sliderRange)
        return Int(round(rawValue))
    }
}

// MARK: - Minimum Value Slider for Double (Bathrooms)

/// A single-thumb slider for selecting a minimum double value
/// Used for bathrooms filter with half values (0 = Any, 1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5+)
struct MinValueSliderDouble: View {
    @Binding var value: Double?
    let steps: [Double]  // e.g., [0, 1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5]
    let formatValue: (Double?) -> String
    var onEditingChanged: (() -> Void)?

    // Internal state for dragging
    @State private var isDragging = false

    private var currentIndex: Int {
        guard let val = value else { return 0 }
        return steps.firstIndex(of: val) ?? 0
    }

    var body: some View {
        VStack(spacing: 12) {
            // Current value label (centered, prominent)
            Text(formatValue(value))
                .font(.title2)
                .fontWeight(.semibold)
                .foregroundStyle(AppColors.brandTeal)
                .frame(maxWidth: .infinity)

            // Slider track and thumb
            GeometryReader { geometry in
                let trackWidth = geometry.size.width - 28 // Account for thumb width
                let stepCount = max(steps.count - 1, 1)

                ZStack(alignment: .leading) {
                    // Track background
                    RoundedRectangle(cornerRadius: 4)
                        .fill(AppColors.shimmerBase)
                        .frame(height: 8)

                    // Filled track (from start to thumb)
                    RoundedRectangle(cornerRadius: 4)
                        .fill(AppColors.brandTeal)
                        .frame(width: filledWidth(trackWidth: trackWidth, stepCount: stepCount) + 14, height: 8)

                    // Thumb
                    Circle()
                        .fill(.white)
                        .frame(width: 28, height: 28)
                        .shadow(color: .black.opacity(0.2), radius: 4, y: 2)
                        .overlay(
                            Circle()
                                .stroke(isDragging ? AppColors.brandTeal : AppColors.border, lineWidth: 2)
                        )
                        .offset(x: thumbOffset(trackWidth: trackWidth, stepCount: stepCount))
                        .gesture(
                            DragGesture()
                                .onChanged { dragValue in
                                    isDragging = true
                                    let index = indexFromLocation(dragValue.location.x, trackWidth: trackWidth, stepCount: stepCount)
                                    if index == 0 {
                                        value = nil // 0 means "Any"
                                    } else {
                                        value = steps[index]
                                    }
                                }
                                .onEnded { _ in
                                    isDragging = false
                                    onEditingChanged?()
                                    HapticManager.selection()
                                }
                        )
                }
            }
            .frame(height: 28)

            // Step labels (show subset for readability)
            HStack {
                Text("Any")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Spacer()
                Text("1")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Spacer()
                Text("2")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Spacer()
                Text("3")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Spacer()
                Text("4")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Spacer()
                Text("5+")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }
        }
    }

    // MARK: - Helper Methods

    private func thumbOffset(trackWidth: CGFloat, stepCount: Int) -> CGFloat {
        let normalizedValue = CGFloat(currentIndex) / CGFloat(stepCount)
        return trackWidth * normalizedValue
    }

    private func filledWidth(trackWidth: CGFloat, stepCount: Int) -> CGFloat {
        let normalizedValue = CGFloat(currentIndex) / CGFloat(stepCount)
        return trackWidth * normalizedValue
    }

    private func indexFromLocation(_ x: CGFloat, trackWidth: CGFloat, stepCount: Int) -> Int {
        let normalizedX = max(0, min(trackWidth, x)) / trackWidth
        let rawIndex = normalizedX * CGFloat(stepCount)
        return min(max(0, Int(round(rawIndex))), steps.count - 1)
    }
}

// MARK: - Range Slider (Dual Thumb)

/// A dual-thumb range slider for selecting min/max values
/// Used for square footage and year built filters
struct RangeSlider: View {
    @Binding var minValue: Double
    @Binding var maxValue: Double
    let range: ClosedRange<Double>
    let step: Double
    let formatValue: (Double) -> String
    var onEditingChanged: (() -> Void)?

    // Internal state for dragging
    @State private var isDraggingMin = false
    @State private var isDraggingMax = false

    private var sliderRange: Double {
        range.upperBound - range.lowerBound
    }

    var body: some View {
        VStack(spacing: 8) {
            // Value labels
            HStack {
                Text(formatValue(minValue))
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Spacer()
                Text(formatValue(maxValue))
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            // Slider track and thumbs
            GeometryReader { geometry in
                let trackWidth = geometry.size.width - 24 // Account for thumb width

                ZStack(alignment: .leading) {
                    // Track background
                    RoundedRectangle(cornerRadius: 2)
                        .fill(AppColors.shimmerBase)
                        .frame(height: 4)

                    // Selected range track
                    RoundedRectangle(cornerRadius: 2)
                        .fill(AppColors.brandTeal)
                        .frame(width: rangeWidth(trackWidth: trackWidth), height: 4)
                        .offset(x: minOffset(trackWidth: trackWidth) + 12)

                    // Min thumb
                    Circle()
                        .fill(.white)
                        .frame(width: 24, height: 24)
                        .shadow(color: .black.opacity(0.15), radius: 4, y: 2)
                        .overlay(Circle().stroke(isDraggingMin ? AppColors.brandTeal : AppColors.border, lineWidth: 2))
                        .offset(x: minOffset(trackWidth: trackWidth))
                        .gesture(
                            DragGesture()
                                .onChanged { value in
                                    isDraggingMin = true
                                    let newValue = valueFromLocation(value.location.x, trackWidth: trackWidth)
                                    let snappedValue = snapToStep(newValue)
                                    // Ensure min doesn't exceed max
                                    minValue = min(snappedValue, maxValue - step)
                                    minValue = max(minValue, range.lowerBound)
                                }
                                .onEnded { _ in
                                    isDraggingMin = false
                                    onEditingChanged?()
                                    HapticManager.selection()
                                }
                        )

                    // Max thumb
                    Circle()
                        .fill(.white)
                        .frame(width: 24, height: 24)
                        .shadow(color: .black.opacity(0.15), radius: 4, y: 2)
                        .overlay(Circle().stroke(isDraggingMax ? AppColors.brandTeal : AppColors.border, lineWidth: 2))
                        .offset(x: maxOffset(trackWidth: trackWidth))
                        .gesture(
                            DragGesture()
                                .onChanged { value in
                                    isDraggingMax = true
                                    let newValue = valueFromLocation(value.location.x, trackWidth: trackWidth)
                                    let snappedValue = snapToStep(newValue)
                                    // Ensure max doesn't go below min
                                    maxValue = max(snappedValue, minValue + step)
                                    maxValue = min(maxValue, range.upperBound)
                                }
                                .onEnded { _ in
                                    isDraggingMax = false
                                    onEditingChanged?()
                                    HapticManager.selection()
                                }
                        )
                }
            }
            .frame(height: 24)
        }
    }

    // MARK: - Helper Methods

    private func minOffset(trackWidth: CGFloat) -> CGFloat {
        let normalizedValue = (minValue - range.lowerBound) / sliderRange
        return trackWidth * normalizedValue
    }

    private func maxOffset(trackWidth: CGFloat) -> CGFloat {
        let normalizedValue = (maxValue - range.lowerBound) / sliderRange
        return trackWidth * normalizedValue
    }

    private func rangeWidth(trackWidth: CGFloat) -> CGFloat {
        let minNormalized = (minValue - range.lowerBound) / sliderRange
        let maxNormalized = (maxValue - range.lowerBound) / sliderRange
        return trackWidth * (maxNormalized - minNormalized)
    }

    private func valueFromLocation(_ x: CGFloat, trackWidth: CGFloat) -> Double {
        let normalizedX = max(0, min(trackWidth, x)) / trackWidth
        return range.lowerBound + (normalizedX * sliderRange)
    }

    private func snapToStep(_ value: Double) -> Double {
        let steps = round((value - range.lowerBound) / step)
        return range.lowerBound + (steps * step)
    }
}

// MARK: - Preview

#Preview {
    VStack(spacing: 24) {
        VStack(alignment: .leading) {
            Text("Beds")
                .font(.headline)
            BedsFilterGroup(minBeds: .constant(2))
        }

        VStack(alignment: .leading) {
            Text("Baths")
                .font(.headline)
            BathsFilterGroup(minBaths: .constant(1.5))
        }

        VStack(alignment: .leading) {
            Text("Amenities")
                .font(.headline)
            HStack {
                AmenityToggle(label: "Pool", icon: "figure.pool.swim", isOn: .constant(true))
                AmenityToggle(label: "Waterfront", icon: "water.waves", isOn: .constant(false))
            }
        }

        PropertyTypeSelector(selectedType: .constant("Residential Lease"))

        VStack(alignment: .leading) {
            Text("Square Footage")
                .font(.headline)
            RangeSlider(
                minValue: .constant(1000),
                maxValue: .constant(3000),
                range: 0...10000,
                step: 100,
                formatValue: { "\(Int($0).formatted()) sqft" }
            )
        }
        .padding(.horizontal)
    }
    .padding()
}
