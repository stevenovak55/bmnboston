//
//  PropertyCard.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import SwiftUI

// MARK: - Property Card Carousel

struct PropertyCardCarousel: View {
    let photos: [URL]
    let height: CGFloat

    @State private var currentIndex = 0

    var body: some View {
        ZStack(alignment: .bottom) {
            if photos.isEmpty {
                // No photos placeholder
                Rectangle()
                    .fill(AppColors.shimmerBase)
                    .overlay(
                        Image(systemName: "photo")
                            .font(.largeTitle)
                            .foregroundStyle(.secondary)
                    )
            } else if photos.count == 1 {
                // Single photo - no carousel
                AsyncImage(url: photos.first) { phase in
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
                                    .font(.largeTitle)
                                    .foregroundStyle(.secondary)
                            )
                    @unknown default:
                        EmptyView()
                    }
                }
            } else {
                // Multiple photos - carousel
                TabView(selection: $currentIndex) {
                    ForEach(Array(photos.enumerated()), id: \.offset) { index, url in
                        AsyncImage(url: url) { phase in
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
                                            .font(.largeTitle)
                                            .foregroundStyle(.secondary)
                                    )
                            @unknown default:
                                EmptyView()
                            }
                        }
                        .tag(index)
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))

                // Custom page indicator
                HStack(spacing: 6) {
                    ForEach(0..<photos.count, id: \.self) { index in
                        Circle()
                            .fill(index == currentIndex ? Color.white : Color.white.opacity(0.5))
                            .frame(width: 6, height: 6)
                    }
                }
                .padding(.vertical, 8)
                .padding(.horizontal, 12)
                .background(Capsule().fill(Color.black.opacity(0.3)))
                .padding(.bottom, 8)
            }
        }
        .frame(height: height)
        .clipped()
    }
}

struct PropertyCard: View {
    let property: Property
    let onFavoriteTap: () -> Void
    var onHideTap: (() -> Void)? = nil
    var isFavoriteLoading: Bool = false
    var isHideLoading: Bool = false

    // MARK: - Static Formatters (v223 Performance Optimization)
    // Avoids creating new NumberFormatter for every cell render

    private static let currencyFormatter: NumberFormatter = {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        return formatter
    }()

    // Cache for monthly payment calculations to avoid redundant pow() calls
    // Key: price in dollars, Value: formatted monthly payment string
    private static let monthlyPaymentCache = NSCache<NSNumber, NSString>()

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Image carousel
            ZStack(alignment: .topTrailing) {
                PropertyCardCarousel(photos: property.photoURLs, height: 220)

                // Action buttons (Hide + Favorite)
                HStack(spacing: 8) {
                    // Hide button
                    if let hideTap = onHideTap {
                        Button {
                            guard !isHideLoading else { return }
                            HapticManager.impact(.light)
                            hideTap()
                        } label: {
                            Group {
                                if isHideLoading {
                                    ProgressView()
                                        .progressViewStyle(CircularProgressViewStyle(tint: .white))
                                        .scaleEffect(0.8)
                                } else {
                                    Image(systemName: "xmark")
                                }
                            }
                            .font(.title3)
                            .foregroundStyle(.white)
                            .frame(width: 20, height: 20)
                            .padding(8)
                            .background(.ultraThinMaterial)
                            .clipShape(Circle())
                        }
                        .disabled(isHideLoading)
                        .accessibilityLabel("Hide property")
                        .accessibilityHint("Double tap to hide this property from search results")
                    }

                    // Favorite button
                    Button {
                        guard !isFavoriteLoading else { return }
                        HapticManager.impact(.light)
                        onFavoriteTap()
                    } label: {
                        Group {
                            if isFavoriteLoading {
                                ProgressView()
                                    .progressViewStyle(CircularProgressViewStyle(tint: .white))
                                    .scaleEffect(0.8)
                            } else {
                                Image(systemName: property.isFavorite ? "heart.fill" : "heart")
                                    .foregroundStyle(property.isFavorite ? .red : .white)
                            }
                        }
                        .font(.title3)
                        .frame(width: 20, height: 20)
                        .padding(8)
                        .background(.ultraThinMaterial)
                        .clipShape(Circle())
                    }
                    .disabled(isFavoriteLoading)
                    .accessibilityLabel(property.isFavorite ? "Remove from favorites" : "Add to favorites")
                    .accessibilityHint("Double tap to \(property.isFavorite ? "remove from" : "add to") your saved properties")
                }
                .padding(8)

                // v6.65.0: Exclusive Listing badge - TOP LEFT position with custom tag text
                if property.isExclusive {
                    VStack {
                        HStack {
                            HStack(spacing: 4) {
                                Image(systemName: "star.fill")
                                    .font(.caption2)
                                Text(property.exclusiveTag ?? "Exclusive")
                                    .font(.caption2)
                                    .fontWeight(.semibold)
                            }
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                            .background(Color(red: 0.85, green: 0.65, blue: 0.13))  // Gold color
                            .foregroundStyle(.white)
                            .clipShape(Capsule())
                            .padding(.leading, 8)
                            .padding(.top, 8)
                            Spacer()
                        }
                        Spacer()
                    }
                }

                // Property type badge + Status tags
                VStack {
                    Spacer()
                    HStack {
                        Text(property.propertySubtype ?? property.propertyType)
                            .font(.caption2)
                            .fontWeight(.medium)
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                            .background(.ultraThinMaterial)
                            .clipShape(Capsule())

                        // New Listing tag
                        if property.isNewListing {
                            Text("New")
                                .font(.caption2)
                                .fontWeight(.semibold)
                                .padding(.horizontal, 8)
                                .padding(.vertical, 4)
                                .background(Color.green)
                                .foregroundStyle(.white)
                                .clipShape(Capsule())
                        }

                        // Price Reduced tag
                        if property.isPriceReduced, let amount = property.priceReductionAmount {
                            let formatted = amount >= 1000 ? "-$\(amount / 1000)K" : "-$\(amount)"
                            Text(formatted)
                                .font(.caption2)
                                .fontWeight(.semibold)
                                .padding(.horizontal, 8)
                                .padding(.vertical, 4)
                                .background(Color.red)
                                .foregroundStyle(.white)
                                .clipShape(Capsule())
                        }

                        // Open House tag with date
                        if property.hasOpenHouse {
                            HStack(spacing: 4) {
                                Image(systemName: "calendar")
                                    .font(.caption2)
                                Text("Open House")
                                    .font(.caption2)
                                    .fontWeight(.medium)
                            }
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                            .background(Color.orange)
                            .foregroundStyle(.white)
                            .clipShape(Capsule())
                        }

                        // "Recommended by [Agent]" badge (v145 - Sprint 3 Property Sharing)
                        if property.isSharedByAgent {
                            HStack(spacing: 6) {
                                // Agent photo (circular, 22x22)
                                if let photoUrlString = property.sharedByAgentPhoto,
                                   let photoUrl = URL(string: photoUrlString) {
                                    AsyncImage(url: photoUrl) { phase in
                                        switch phase {
                                        case .success(let image):
                                            image
                                                .resizable()
                                                .aspectRatio(contentMode: .fill)
                                        default:
                                            EmptyView()
                                        }
                                    }
                                    .frame(width: 22, height: 22)
                                    .clipShape(Circle())
                                    .overlay(Circle().stroke(Color.white.opacity(0.9), lineWidth: 2))
                                }

                                Text("Recommended by \(property.sharedByAgentName ?? "Agent")")
                                    .font(.caption2)
                                    .fontWeight(.semibold)
                            }
                            .padding(.leading, property.sharedByAgentPhoto != nil ? 4 : 10)
                            .padding(.trailing, 10)
                            .padding(.vertical, 4)
                            .background(Color.purple)
                            .foregroundStyle(.white)
                            .clipShape(Capsule())
                        }

                        Spacer()
                    }
                    .padding(8)
                }
            }

            // Details
            VStack(alignment: .leading, spacing: 8) {
                // Price + Status
                HStack(alignment: .center) {
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(alignment: .firstTextBaseline, spacing: 6) {
                            Text(property.formattedPrice)
                                .font(.title2)
                                .fontWeight(.bold)

                            // Monthly estimate (v215)
                            if let monthlyEstimate = calculateMonthlyPayment(price: property.price) {
                                Text("Est. \(monthlyEstimate)/mo")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }

                        // Original price strikethrough if reduced
                        if property.isPriceReduced, let original = property.originalPrice {
                            Text(formatPrice(original))
                                .font(.caption)
                                .foregroundStyle(.secondary)
                                .strikethrough()
                        }
                    }

                    Spacer()

                    // Status pill (only show non-Active statuses prominently)
                    if property.standardStatus != .active {
                        Text(property.standardStatus.displayName)
                            .font(.caption2)
                            .fontWeight(.semibold)
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                            .background(statusColor(for: property.standardStatus))
                            .foregroundStyle(.white)
                            .clipShape(Capsule())
                    }
                }

                // Address + MLS Number
                VStack(alignment: .leading, spacing: 2) {
                    Text(property.fullAddress)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)

                    if let mls = property.mlsNumber {
                        Text("MLS# \(mls)")
                            .font(.caption2)
                            .foregroundStyle(.tertiary)
                    }
                }

                // District School Rating (v89)
                if let grade = property.districtGrade {
                    HStack(spacing: 4) {
                        Image(systemName: "graduationcap.fill")
                            .font(.caption)
                            .foregroundStyle(districtGradeColor(for: grade))

                        Text(grade)
                            .font(.caption)
                            .fontWeight(.semibold)
                            .foregroundStyle(districtGradeColor(for: grade))

                        if let percentile = property.districtPercentile {
                            Text("top \(100 - percentile)%")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                // Features
                HStack(spacing: 16) {
                    Label("\(property.beds) bd", systemImage: "bed.double.fill")
                        .accessibilityLabel("\(property.beds) bedrooms")
                    Label(property.formattedBathroomsDetailed + " ba", systemImage: "shower.fill")
                        .accessibilityLabel("\(property.formattedBathroomsDetailed) bathrooms")
                    if let sqft = property.formattedSqft {
                        Label(sqft, systemImage: "square.fill")
                            .accessibilityLabel("\(sqft) square feet")
                    }
                }
                .font(.caption)
                .foregroundStyle(.secondary)

                // Property Highlight Icons
                if hasHighlights {
                    HStack(spacing: 12) {
                        if property.hasPool {
                            HighlightIcon(icon: "drop.fill", label: "Pool", color: Color(hex: "#0891B2"))
                        }
                        if property.hasWaterfront {
                            HighlightIcon(icon: "water.waves", label: "Waterfront", color: Color(hex: "#0EA5E9"))
                        }
                        if property.hasView {
                            HighlightIcon(icon: "mountain.2.fill", label: "View", color: Color(hex: "#059669"))
                        }
                        if property.hasGarage {
                            HighlightIcon(icon: "car.fill", label: "Garage", color: Color(hex: "#6366F1"))
                        }
                        if property.hasFireplace {
                            HighlightIcon(icon: "flame.fill", label: "Fireplace", color: Color(hex: "#F97316"))
                        }
                    }
                    .font(.caption2)
                }

                // Days on market + Share button
                HStack {
                    if let dom = property.dom, dom > 0 {
                        Text("\(dom) days on market")
                            .font(.caption2)
                            .foregroundStyle(.tertiary)
                    }

                    Spacer()

                    // Share button (v215)
                    ShareLink(item: propertyURL) {
                        Image(systemName: "square.and.arrow.up")
                            .font(.system(size: 16))
                            .foregroundStyle(.secondary)
                    }
                    .accessibilityLabel("Share property")
                    .accessibilityHint("Double tap to share this property listing")
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 12)
        }
        .background(Color(.systemBackground))
        .shadow(color: AppColors.shadowLight, radius: 4, x: 0, y: 2)
        .accessibilityElement(children: .contain)
        .accessibilityLabel(accessibilityDescription)
        .accessibilityHint("Double tap to view property details")
    }

    // MARK: - Accessibility

    private var accessibilityDescription: String {
        var parts: [String] = []

        // Price
        parts.append(property.formattedPrice)

        // Beds/baths
        parts.append("\(property.beds) bedrooms, \(property.formattedBathroomsDetailed) bathrooms")

        // Square footage
        if let sqft = property.formattedSqft {
            parts.append("\(sqft) square feet")
        }

        // Address
        parts.append("at \(property.fullAddress)")

        // Status
        if property.standardStatus != .active {
            parts.append("Status: \(property.standardStatus.displayName)")
        }

        // Special tags
        if property.isNewListing {
            parts.append("New listing")
        }
        if property.isPriceReduced {
            parts.append("Price reduced")
        }
        if property.hasOpenHouse {
            parts.append("Open house available")
        }

        // Favorite state
        if property.isFavorite {
            parts.append("Saved to favorites")
        }

        return parts.joined(separator: ", ")
    }

    // MARK: - Computed Properties

    // Static fallback URL - guaranteed valid, initialized once
    private static let fallbackURL = URL(string: "https://bmnboston.com")!

    private var propertyURL: URL {
        let propertyId = property.mlsNumber ?? property.id
        // URL creation with static fallback (avoids runtime force unwrap)
        return URL(string: "https://bmnboston.com/property/\(propertyId)/") ?? Self.fallbackURL
    }

    private var hasHighlights: Bool {
        property.hasPool || property.hasWaterfront || property.hasView ||
        property.hasGarage || property.hasFireplace
    }

    private func statusColor(for status: PropertyStatus) -> Color {
        switch status {
        case .active: return AppColors.activeStatus
        case .pending: return AppColors.pendingStatus
        case .sold, .closed: return AppColors.soldStatus
        case .withdrawn, .expired, .canceled: return Color.gray
        }
    }

    private func formatPrice(_ price: Int) -> String {
        Self.currencyFormatter.string(from: NSNumber(value: price)) ?? "$\(price)"
    }

    private func districtGradeColor(for grade: String) -> Color {
        switch grade.prefix(1) {
        case "A": return .green
        case "B": return .blue
        case "C": return .yellow
        case "D": return .orange
        default: return .red
        }
    }

    /// Calculate estimated monthly mortgage payment (v215, optimized v223)
    /// Assumes 20% down payment, 7% interest rate, 30-year term
    /// Uses NSCache to avoid redundant pow() calculations for the same price
    private func calculateMonthlyPayment(price: Int?) -> String? {
        guard let price = price, price > 0 else { return nil }

        // Check cache first
        let cacheKey = NSNumber(value: price)
        if let cached = Self.monthlyPaymentCache.object(forKey: cacheKey) {
            return cached as String
        }

        // Calculate if not cached
        let downPaymentPercent = 0.20
        let interestRate = 0.07
        let termYears = 30

        let principal = Double(price) * (1.0 - downPaymentPercent)
        let monthlyRate = interestRate / 12.0
        let payments = Double(termYears * 12)

        // Monthly payment formula: M = P * [r(1+r)^n] / [(1+r)^n - 1]
        let factor = pow(1 + monthlyRate, payments)
        let monthly = principal * (monthlyRate * factor) / (factor - 1)

        if let formatted = Self.currencyFormatter.string(from: NSNumber(value: monthly)) {
            Self.monthlyPaymentCache.setObject(formatted as NSString, forKey: cacheKey)
            return formatted
        }
        return nil
    }
}

// MARK: - Highlight Icon

struct HighlightIcon: View {
    let icon: String
    let label: String
    let color: Color

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: icon)
                .foregroundStyle(color)
            Text(label)
                .foregroundStyle(.secondary)
        }
    }
}

// MARK: - Compact Property Card (for grids)

struct CompactPropertyCard: View {
    let property: Property
    let onFavoriteTap: () -> Void
    var onHideTap: (() -> Void)? = nil
    var isFavoriteLoading: Bool = false
    var isHideLoading: Bool = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Image
            ZStack(alignment: .topTrailing) {
                AsyncImage(url: property.thumbnailURL) { phase in
                    switch phase {
                    case .empty:
                        Rectangle()
                            .fill(AppColors.shimmerBase)
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
                .frame(height: 120)
                .clipped()

                // Action buttons (Hide + Favorite)
                HStack(spacing: 4) {
                    // Hide button
                    if let hideTap = onHideTap {
                        Button {
                            guard !isHideLoading else { return }
                            HapticManager.impact(.light)
                            hideTap()
                        } label: {
                            Group {
                                if isHideLoading {
                                    ProgressView()
                                        .progressViewStyle(CircularProgressViewStyle(tint: .white))
                                        .scaleEffect(0.6)
                                } else {
                                    Image(systemName: "xmark")
                                        .foregroundStyle(.white)
                                }
                            }
                            .font(.caption)
                            .frame(width: 16, height: 16)
                            .padding(6)
                            .background(.ultraThinMaterial)
                            .clipShape(Circle())
                        }
                        .disabled(isHideLoading)
                        .accessibilityLabel("Hide property")
                    }

                    // Favorite button
                    Button {
                        guard !isFavoriteLoading else { return }
                        HapticManager.impact(.light)
                        onFavoriteTap()
                    } label: {
                        Group {
                            if isFavoriteLoading {
                                ProgressView()
                                    .progressViewStyle(CircularProgressViewStyle(tint: .white))
                                    .scaleEffect(0.6)
                            } else {
                                Image(systemName: property.isFavorite ? "heart.fill" : "heart")
                                    .foregroundStyle(property.isFavorite ? .red : .white)
                            }
                        }
                        .font(.caption)
                        .frame(width: 16, height: 16)
                        .padding(6)
                        .background(.ultraThinMaterial)
                        .clipShape(Circle())
                    }
                    .disabled(isFavoriteLoading)
                    .accessibilityLabel(property.isFavorite ? "Remove from favorites" : "Add to favorites")
                }
                .padding(4)

                // v6.65.0: Exclusive Listing badge - TOP LEFT (compact version)
                if property.isExclusive {
                    VStack {
                        HStack {
                            HStack(spacing: 2) {
                                Image(systemName: "star.fill")
                                    .font(.system(size: 8))
                                Text(property.exclusiveTag ?? "Exclusive")
                                    .font(.system(size: 9))
                                    .fontWeight(.semibold)
                            }
                            .padding(.horizontal, 5)
                            .padding(.vertical, 2)
                            .background(Color(red: 0.85, green: 0.65, blue: 0.13))
                            .foregroundStyle(.white)
                            .clipShape(Capsule())
                            .padding(.leading, 4)
                            .padding(.top, 4)
                            Spacer()
                        }
                        Spacer()
                    }
                }
            }

            VStack(alignment: .leading, spacing: 4) {
                Text(property.formattedPrice)
                    .font(.subheadline)
                    .fontWeight(.bold)
                    .accessibilityLabel("Price \(property.formattedPrice)")

                Text(property.address)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                    .accessibilityLabel("Address \(property.address)")

                Text(property.formattedBedBath)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
                    .accessibilityLabel("\(property.beds) bedrooms, \(property.formattedBathroomsDetailed) bathrooms")
            }
            .padding(8)
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 8))
        .shadow(color: AppColors.shadowLight, radius: 4, x: 0, y: 1)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("\(property.formattedPrice), \(property.beds) bed, \(property.formattedBathroomsDetailed) bath at \(property.address)")
        .accessibilityHint("Double tap to view details")
    }
}

// MARK: - Property Row (for lists)

struct PropertyRow: View {
    let property: Property
    let onFavoriteTap: () -> Void
    var onHideTap: (() -> Void)? = nil
    var isFavoriteLoading: Bool = false
    var isHideLoading: Bool = false

    var body: some View {
        HStack(spacing: 12) {
            AsyncImage(url: property.thumbnailURL) { phase in
                switch phase {
                case .empty:
                    Rectangle()
                        .fill(AppColors.shimmerBase)
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
            .frame(width: 80, height: 60)
            .clipShape(RoundedRectangle(cornerRadius: 6))

            VStack(alignment: .leading, spacing: 4) {
                HStack(spacing: 4) {
                    Text(property.formattedPrice)
                        .font(.subheadline)
                        .fontWeight(.semibold)

                    // v6.65.0: Exclusive indicator (compact star + tag)
                    if property.isExclusive {
                        HStack(spacing: 2) {
                            Image(systemName: "star.fill")
                                .font(.system(size: 8))
                            Text(property.exclusiveTag ?? "Exclusive")
                                .font(.system(size: 9))
                                .fontWeight(.medium)
                        }
                        .foregroundStyle(Color(red: 0.85, green: 0.65, blue: 0.13))
                    }
                }

                Text(property.address)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)

                Text(property.formattedBedBath)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }

            Spacer()

            // Action buttons (Hide + Favorite)
            HStack(spacing: 8) {
                // Hide button
                if let hideTap = onHideTap {
                    Button {
                        guard !isHideLoading else { return }
                        HapticManager.impact(.light)
                        hideTap()
                    } label: {
                        Group {
                            if isHideLoading {
                                ProgressView()
                                    .progressViewStyle(CircularProgressViewStyle(tint: .secondary))
                                    .scaleEffect(0.7)
                            } else {
                                Image(systemName: "xmark")
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .frame(width: 20, height: 20)
                    }
                    .disabled(isHideLoading)
                    .accessibilityLabel("Hide property")
                }

                // Favorite button
                Button {
                    guard !isFavoriteLoading else { return }
                    HapticManager.impact(.light)
                    onFavoriteTap()
                } label: {
                    Group {
                        if isFavoriteLoading {
                            ProgressView()
                                .progressViewStyle(CircularProgressViewStyle(tint: .secondary))
                                .scaleEffect(0.7)
                        } else {
                            Image(systemName: property.isFavorite ? "heart.fill" : "heart")
                                .foregroundStyle(property.isFavorite ? .red : .secondary)
                        }
                    }
                    .frame(width: 20, height: 20)
                }
                .disabled(isFavoriteLoading)
                .accessibilityLabel(property.isFavorite ? "Remove from favorites" : "Add to favorites")
            }
        }
        .padding(.vertical, 4)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("\(property.formattedPrice), \(property.beds) bed, \(property.formattedBathroomsDetailed) bath at \(property.address)")
        .accessibilityHint("Double tap to view details")
    }
}

// Preview removed - Property init requires decoder

// MARK: - Shimmer Animation Modifier

struct ShimmerModifier: ViewModifier {
    @State private var phase: CGFloat = 0

    func body(content: Content) -> some View {
        content
            .overlay(
                GeometryReader { geometry in
                    LinearGradient(
                        gradient: Gradient(colors: [
                            Color.clear,
                            AppColors.shimmerHighlight,
                            Color.clear
                        ]),
                        startPoint: .leading,
                        endPoint: .trailing
                    )
                    .frame(width: geometry.size.width * 2)
                    .offset(x: -geometry.size.width + (geometry.size.width * 2 * phase))
                }
            )
            .mask(content)
            .onAppear {
                withAnimation(
                    Animation.linear(duration: 1.2)
                        .repeatForever(autoreverses: false)
                ) {
                    phase = 1
                }
            }
    }
}

extension View {
    func shimmer() -> some View {
        modifier(ShimmerModifier())
    }
}

// MARK: - Skeleton Shape

struct SkeletonShape: View {
    var width: CGFloat? = nil
    var height: CGFloat
    var cornerRadius: CGFloat = 8

    var body: some View {
        RoundedRectangle(cornerRadius: cornerRadius)
            .fill(AppColors.shimmerBase)
            .frame(width: width, height: height)
            .shimmer()
    }
}

// MARK: - Skeleton Property Card

struct SkeletonPropertyCard: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Image placeholder
            SkeletonShape(height: 180, cornerRadius: 0)

            // Details
            VStack(alignment: .leading, spacing: 8) {
                // Price
                SkeletonShape(width: 120, height: 28, cornerRadius: 4)

                // Address
                SkeletonShape(height: 18, cornerRadius: 4)

                // Features row
                HStack(spacing: 16) {
                    SkeletonShape(width: 50, height: 14, cornerRadius: 4)
                    SkeletonShape(width: 50, height: 14, cornerRadius: 4)
                    SkeletonShape(width: 70, height: 14, cornerRadius: 4)
                }

                // Days on market
                SkeletonShape(width: 100, height: 12, cornerRadius: 4)
            }
            .padding(12)
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: AppColors.shadowLight, radius: 8, x: 0, y: 2)
        .padding(.horizontal)
    }
}

// MARK: - Skeleton Property List

struct SkeletonPropertyList: View {
    var count: Int = 3

    var body: some View {
        ScrollView {
            LazyVStack(spacing: 16) {
                ForEach(0..<count, id: \.self) { _ in
                    SkeletonPropertyCard()
                }
            }
            .padding(.vertical)
        }
    }
}

// MARK: - Skeleton Property Detail

struct SkeletonPropertyDetail: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Image carousel placeholder
            SkeletonShape(height: SearchConstants.imageCarouselHeight, cornerRadius: 0)

            VStack(alignment: .leading, spacing: 24) {
                // Price section
                VStack(alignment: .leading, spacing: 8) {
                    HStack {
                        SkeletonShape(width: 150, height: 32, cornerRadius: 4)
                        Spacer()
                        SkeletonShape(width: 80, height: 24, cornerRadius: 12)
                    }
                    SkeletonShape(height: 18, cornerRadius: 4)
                    SkeletonShape(width: 120, height: 14, cornerRadius: 4)
                }

                Divider()

                // Key details
                HStack(spacing: 24) {
                    ForEach(0..<4, id: \.self) { _ in
                        VStack(spacing: 4) {
                            SkeletonShape(width: 28, height: 28, cornerRadius: 14)
                            SkeletonShape(width: 30, height: 18, cornerRadius: 4)
                            SkeletonShape(width: 40, height: 12, cornerRadius: 4)
                        }
                    }
                }

                Divider()

                // Description section
                VStack(alignment: .leading, spacing: 8) {
                    SkeletonShape(width: 100, height: 20, cornerRadius: 4)
                    SkeletonShape(height: 14, cornerRadius: 4)
                    SkeletonShape(height: 14, cornerRadius: 4)
                    SkeletonShape(height: 14, cornerRadius: 4)
                    SkeletonShape(width: 200, height: 14, cornerRadius: 4)
                }

                Divider()

                // Features section
                VStack(alignment: .leading, spacing: 12) {
                    SkeletonShape(width: 80, height: 20, cornerRadius: 4)
                    HStack(spacing: 8) {
                        ForEach(0..<3, id: \.self) { _ in
                            SkeletonShape(width: 80, height: 28, cornerRadius: 14)
                        }
                    }
                }

                Divider()

                // Map section
                VStack(alignment: .leading, spacing: 8) {
                    SkeletonShape(width: 80, height: 20, cornerRadius: 4)
                    SkeletonShape(height: 200, cornerRadius: 12)
                }

                // Contact button
                SkeletonShape(height: 50, cornerRadius: 12)
                    .padding(.top, 8)
            }
            .padding()
        }
    }
}

// MARK: - Skeleton Search Result Header

struct SkeletonSearchHeader: View {
    var body: some View {
        HStack {
            SkeletonShape(width: 100, height: 16, cornerRadius: 4)
            Spacer()
            SkeletonShape(width: 120, height: 16, cornerRadius: 4)
        }
        .padding(.horizontal)
        .padding(.bottom, 8)
    }
}
