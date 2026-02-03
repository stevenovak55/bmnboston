//
//  SearchModalView.swift
//  BMNBoston
//
//  Full-screen search modal with Buy/Rent/Sold tabs
//  Similar to Zillow/Redfin search experience
//

import SwiftUI
import os

private let logger = Logger(subsystem: "com.bmnboston.app", category: "SearchModalView")

// MARK: - Search Mode Enum

enum SearchMode: String, CaseIterable, Identifiable {
    case buy = "Buy"
    case rent = "Rent"
    case sold = "Sold"

    var id: String { rawValue }

    var icon: String {
        switch self {
        case .buy: return "house.fill"
        case .rent: return "key.fill"
        case .sold: return "checkmark.circle.fill"
        }
    }
}

// MARK: - Search Modal View

struct SearchModalView: View {
    @Environment(\.dismiss) var dismiss

    @Binding var searchText: String
    @Binding var filters: PropertySearchFilters

    // v6.68.12: Changed from let to @Binding to ensure suggestions update in real-time
    // fullScreenCover can cache views, so binding ensures we always see the latest suggestions
    @Binding var suggestions: [SearchSuggestion]
    let recentSearches: [RecentSearch]
    let onSearch: (String) -> Void
    let onSuggestionTap: (SearchSuggestion) -> Void
    let onRecentSearchTap: (RecentSearch) -> Void
    let onClearRecent: () -> Void

    @State private var searchMode: SearchMode = .buy
    @FocusState private var isSearchFocused: Bool

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                // Search Mode Tabs
                searchModeTabs

                // Location Filter Bubbles
                if hasLocationFilters {
                    locationFilterBubbles
                }

                // Search Bar
                searchBar

                // Content
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 0) {
                        // Recent Searches
                        if searchText.isEmpty && !recentSearches.isEmpty {
                            recentSearchesSection
                        }

                        // Live Suggestions
                        if !suggestions.isEmpty {
                            suggestionsSection
                        }

                        // No results
                        if !searchText.isEmpty && suggestions.isEmpty {
                            noResultsView
                        }

                        // Empty state when no recent and no search
                        if searchText.isEmpty && recentSearches.isEmpty && !hasLocationFilters {
                            emptyStateView
                        }
                    }
                }
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("Search")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        dismiss()
                    }
                }

                ToolbarItem(placement: .confirmationAction) {
                    if hasLocationFilters {
                        Button("Search") {
                            onSearch("")
                            dismiss()
                        }
                        .fontWeight(.semibold)
                    }
                }
            }
            .onAppear {
                // Set initial mode based on current filters
                if filters.statuses.contains(.sold) || filters.statuses.contains(.closed) {
                    searchMode = .sold
                } else if filters.listingType == .forRent {
                    searchMode = .rent
                } else {
                    searchMode = .buy
                }

                // Auto-focus search field
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                    isSearchFocused = true
                }
            }
            .onChange(of: searchMode) { newMode in
                applySearchMode(newMode)
            }
        }
    }

    // MARK: - Search Mode Tabs

    private var searchModeTabs: some View {
        HStack(spacing: 0) {
            ForEach(SearchMode.allCases) { mode in
                Button {
                    withAnimation(.easeInOut(duration: 0.2)) {
                        searchMode = mode
                    }
                } label: {
                    VStack(spacing: 6) {
                        HStack(spacing: 6) {
                            Image(systemName: mode.icon)
                                .font(.system(size: 14, weight: .medium))
                            Text(mode.rawValue)
                                .font(.subheadline)
                                .fontWeight(.semibold)
                        }
                        .foregroundStyle(searchMode == mode ? AppColors.brandTeal : .secondary)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)

                        // Active indicator
                        Rectangle()
                            .fill(searchMode == mode ? AppColors.brandTeal : Color.clear)
                            .frame(height: 3)
                    }
                }
                .buttonStyle(.plain)
            }
        }
        .background(Color(.systemBackground))
    }

    // MARK: - Location Filter Bubbles

    private var hasLocationFilters: Bool {
        !filters.cities.isEmpty || !filters.zips.isEmpty || !filters.neighborhoods.isEmpty || (filters.streetName != nil && !filters.streetName!.isEmpty)
    }

    private var locationFilterBubbles: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                // Cities
                ForEach(Array(filters.cities), id: \.self) { city in
                    LocationFilterBubble(
                        text: city,
                        type: "City",
                        onRemove: {
                            filters.cities.remove(city)
                        }
                    )
                }

                // ZIPs
                ForEach(Array(filters.zips), id: \.self) { zip in
                    LocationFilterBubble(
                        text: zip,
                        type: "ZIP",
                        onRemove: {
                            filters.zips.remove(zip)
                        }
                    )
                }

                // Neighborhoods
                ForEach(Array(filters.neighborhoods), id: \.self) { neighborhood in
                    LocationFilterBubble(
                        text: neighborhood,
                        type: "Neighborhood",
                        onRemove: {
                            filters.neighborhoods.remove(neighborhood)
                        }
                    )
                }

                // Street Name
                if let streetName = filters.streetName, !streetName.isEmpty {
                    LocationFilterBubble(
                        text: streetName,
                        type: "Street",
                        onRemove: {
                            filters.streetName = nil
                        }
                    )
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 8)
        }
        .background(Color(.systemBackground))
    }

    // MARK: - Search Bar

    private var searchBar: some View {
        HStack(spacing: 8) {
            Image(systemName: "magnifyingglass")
                .foregroundStyle(.secondary)

            TextField("City, ZIP, Address, or MLS #", text: $searchText)
                .focused($isSearchFocused)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .submitLabel(.search)
                .onSubmit {
                    performSearch()
                }

            if !searchText.isEmpty {
                Button {
                    searchText = ""
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding(12)
        .background(Color(.secondarySystemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .padding(.horizontal)
        .padding(.bottom, 8)
    }

    // MARK: - Recent Searches Section

    private var recentSearchesSection: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text("Recent Searches")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)

                Spacer()

                Button("Clear") {
                    onClearRecent()
                }
                .font(.subheadline)
                .foregroundStyle(AppColors.brandTeal)
            }
            .padding(.horizontal)
            .padding(.vertical, 12)

            ForEach(recentSearches.prefix(5)) { search in
                RecentSearchCard(search: search) {
                    onRecentSearchTap(search)
                    dismiss()
                }

                if search.id != recentSearches.prefix(5).last?.id {
                    Divider()
                        .padding(.leading, 56)
                }
            }
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .padding(.horizontal)
        .padding(.top, 8)
    }

    // MARK: - Suggestions Section

    private var suggestionsSection: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("Suggestions")
                .font(.subheadline)
                .fontWeight(.semibold)
                .foregroundStyle(.secondary)
                .padding(.horizontal)
                .padding(.vertical, 12)

            ForEach(suggestions) { suggestion in
                Button {
                    onSuggestionTap(suggestion)
                    // Clear text so user can add more locations
                    searchText = ""
                    // Don't dismiss for city/zip/neighborhood - let user add more
                    // Dismiss for specific searches: address, MLS#, or street name
                    if suggestion.type == .address || suggestion.type == .mlsNumber || suggestion.type == .streetName {
                        dismiss()
                    }
                } label: {
                    HStack(spacing: 12) {
                        Image(systemName: suggestion.type.icon)
                            .font(.system(size: 16))
                            .foregroundStyle(AppColors.brandTeal)
                            .frame(width: 24)

                        VStack(alignment: .leading, spacing: 2) {
                            Text(highlightedText(suggestion.displayText, searchText: searchText))

                            if let subtitle = suggestion.subtitle {
                                Text(subtitle)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }

                        Spacer()

                        Text(suggestion.type.displayLabel)
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                            .background(Color(.tertiarySystemBackground))
                            .clipShape(Capsule())
                    }
                    .padding(.horizontal)
                    .padding(.vertical, 12)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)

                if suggestion.id != suggestions.last?.id {
                    Divider()
                        .padding(.leading, 56)
                }
            }
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .padding(.horizontal)
        .padding(.top, 8)
    }

    // MARK: - No Results View

    private var noResultsView: some View {
        VStack(spacing: 12) {
            Image(systemName: "magnifyingglass")
                .font(.system(size: 40))
                .foregroundStyle(.secondary)

            Text("No results found")
                .font(.headline)
                .foregroundStyle(.secondary)

            Text("Try a different search term")
                .font(.subheadline)
                .foregroundStyle(.tertiary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 60)
    }

    // MARK: - Empty State View

    private var emptyStateView: some View {
        VStack(spacing: 16) {
            Image(systemName: "house.and.flag.fill")
                .font(.system(size: 48))
                .foregroundStyle(AppColors.brandTeal.opacity(0.6))

            Text("Search for properties")
                .font(.headline)
                .foregroundStyle(.primary)

            Text("Enter a city, ZIP code, address, or MLS number to find listings")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 60)
    }

    // MARK: - Helper Functions

    private func performSearch() {
        guard !searchText.isEmpty else { return }
        onSearch(searchText)
        dismiss()
    }

    private func applySearchMode(_ mode: SearchMode) {
        switch mode {
        case .buy:
            filters.listingType = .forSale
            filters.statuses = [.active, .pending]
            logger.debug("Search mode changed to Buy")

        case .rent:
            filters.listingType = .forRent
            filters.statuses = [.active, .pending]
            logger.debug("Search mode changed to Rent")

        case .sold:
            filters.listingType = .forSale
            filters.statuses = [.sold, .closed]
            logger.debug("Search mode changed to Sold")
        }
    }

    private func highlightedText(_ text: String, searchText: String) -> AttributedString {
        var attributed = AttributedString(text)

        if let range = attributed.range(of: searchText, options: .caseInsensitive) {
            attributed[range].font = .body.bold()
            attributed[range].foregroundColor = AppColors.brandTeal
        }

        return attributed
    }
}

// MARK: - Recent Search Card

struct RecentSearchCard: View {
    let search: RecentSearch
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            HStack(spacing: 12) {
                // Icon
                Image(systemName: "clock.arrow.circlepath")
                    .font(.system(size: 18))
                    .foregroundStyle(.secondary)
                    .frame(width: 32)

                // Content
                VStack(alignment: .leading, spacing: 4) {
                    Text(search.displayText)
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundStyle(.primary)
                        .lineLimit(1)

                    // Filter pills
                    if !search.filterPills.isEmpty {
                        HStack(spacing: 6) {
                            ForEach(search.filterPills.prefix(3), id: \.self) { pill in
                                Text(pill)
                                    .font(.caption2)
                                    .foregroundStyle(.secondary)
                                    .padding(.horizontal, 6)
                                    .padding(.vertical, 2)
                                    .background(Color(.tertiarySystemBackground))
                                    .clipShape(Capsule())
                            }

                            if search.filterPills.count > 3 {
                                Text("+\(search.filterPills.count - 3)")
                                    .font(.caption2)
                                    .foregroundStyle(.tertiary)
                            }
                        }
                    }
                }

                Spacer()

                // Timestamp
                Text(search.timeAgo)
                    .font(.caption)
                    .foregroundStyle(.tertiary)

                Image(systemName: "chevron.right")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
            .padding(.horizontal)
            .padding(.vertical, 12)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Location Filter Bubble

struct LocationFilterBubble: View {
    let text: String
    let type: String
    let onRemove: () -> Void

    var body: some View {
        HStack(spacing: 6) {
            Image(systemName: iconForType)
                .font(.system(size: 10, weight: .medium))

            Text(text)
                .font(.caption)
                .fontWeight(.medium)

            Button(action: onRemove) {
                Image(systemName: "xmark")
                    .font(.system(size: 8, weight: .bold))
                    .padding(3)
                    .background(Circle().fill(Color.white.opacity(0.3)))
            }
        }
        .foregroundStyle(.white)
        .padding(.leading, 10)
        .padding(.trailing, 6)
        .padding(.vertical, 6)
        .background(AppColors.brandTeal)
        .clipShape(Capsule())
    }

    private var iconForType: String {
        switch type {
        case "City": return "building.2.fill"
        case "ZIP": return "mappin.circle.fill"
        case "Neighborhood": return "map.fill"
        case "Street": return "road.lanes"
        default: return "location.fill"
        }
    }
}

// MARK: - Preview

#Preview {
    struct PreviewWrapper: View {
        @State var searchText = ""
        @State var filters = PropertySearchFilters()
        @State var suggestions: [SearchSuggestion] = [
            SearchSuggestion(type: .city, value: "Boston", displayText: "Boston", subtitle: "Massachusetts"),
            SearchSuggestion(type: .neighborhood, value: "Back Bay", displayText: "Back Bay", subtitle: "Boston, MA"),
            SearchSuggestion(type: .zip, value: "02116", displayText: "02116", subtitle: "Boston, MA")
        ]

        var body: some View {
            // Create sample filters for preview
            let sampleFilters: PropertySearchFilters = {
                var f = PropertySearchFilters()
                f.minPrice = 500000
                f.maxPrice = 800000
                f.beds = [3, 4]
                f.minBaths = 2.0
                f.cities = ["Boston"]
                return f
            }()

            SearchModalView(
                searchText: $searchText,
                filters: $filters,
                suggestions: $suggestions,
                recentSearches: [
                    RecentSearch(
                        id: UUID(),
                        displayText: "Boston, MA",
                        filters: LocalSavedSearchFilters(from: sampleFilters),
                        timestamp: Date().addingTimeInterval(-3600)
                    )
                ],
                onSearch: { _ in },
                onSuggestionTap: { _ in },
                onRecentSearchTap: { _ in },
                onClearRecent: {}
            )
        }
    }

    return PreviewWrapper()
}
