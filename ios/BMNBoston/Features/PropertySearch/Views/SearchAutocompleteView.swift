//
//  SearchAutocompleteView.swift
//  BMNBoston
//
//  Search bar with autocomplete suggestions matching MLD web plugin
//

import SwiftUI
import os

private let logger = Logger(subsystem: "com.bmnboston.app", category: "SearchAutocompleteView")

struct SearchAutocompleteView: View {
    @Binding var searchText: String
    @Binding var isSearching: Bool
    let suggestions: [SearchSuggestion]
    let recentSearches: [String]
    let onSearch: (String) -> Void
    let onSuggestionTap: (SearchSuggestion) -> Void
    let onRecentSearchTap: (String) -> Void
    let onClearRecent: () -> Void

    @FocusState private var isFocused: Bool
    @State private var showSuggestions: Bool = false

    var body: some View {
        VStack(spacing: 0) {
            // Search Bar
            HStack(spacing: 12) {
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass")
                        .foregroundStyle(.secondary)

                    TextField("City, ZIP, Address, or MLS #", text: $searchText)
                        .focused($isFocused)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .submitLabel(.search)
                        .onSubmit {
                            performSearch()
                        }
                        .onChange(of: searchText) { newValue in
                            showSuggestions = !newValue.isEmpty || isFocused
                            logger.debug("Search text changed to: '\(newValue)', showSuggestions=\(showSuggestions), isFocused=\(isFocused)")
                        }

                    if !searchText.isEmpty {
                        Button {
                            searchText = ""
                            showSuggestions = isFocused
                        } label: {
                            Image(systemName: "xmark.circle.fill")
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                .padding(12)
                .background(Color(.secondarySystemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))

                if isFocused {
                    Button("Cancel") {
                        isFocused = false
                        showSuggestions = false
                        isSearching = false
                    }
                    .foregroundStyle(AppColors.brandTeal)
                    .transition(.move(edge: .trailing).combined(with: .opacity))
                }
            }
            .padding(.horizontal)
            .padding(.vertical, 8)
            .animation(.easeInOut(duration: 0.2), value: isFocused)

            // Suggestions dropdown
            if showSuggestions && isFocused {
                suggestionsView
                    .transition(.opacity.combined(with: .move(edge: .top)))
                    .onAppear {
                        logger.debug("Suggestions view appeared, count: \(self.suggestions.count)")
                    }
            }
        }
        .onChange(of: isFocused) { newValue in
            showSuggestions = newValue
            isSearching = newValue
        }
        .onAppear {
            // Auto-focus the text field when the view appears
            logger.debug("SearchAutocompleteView appeared, setting focus")
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
                isFocused = true
                logger.debug("Focus set to true")
            }
        }
    }

    // MARK: - Suggestions View

    private var suggestionsView: some View {
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
            }
        }
        .frame(maxHeight: 400)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.1), radius: 8, y: 4)
        .padding(.horizontal)
    }

    // MARK: - Recent Searches Section

    private var recentSearchesSection: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text("Recent Searches")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)

                Spacer()

                Button("Clear") {
                    onClearRecent()
                }
                .font(.caption)
                .foregroundStyle(AppColors.brandTeal)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)

            ForEach(recentSearches.prefix(5), id: \.self) { search in
                Button {
                    searchText = search
                    onRecentSearchTap(search)
                    isFocused = false
                } label: {
                    HStack(spacing: 12) {
                        Image(systemName: "clock.arrow.circlepath")
                            .font(.system(size: 16))
                            .foregroundStyle(.secondary)

                        Text(search)
                            .font(.subheadline)
                            .foregroundStyle(.primary)

                        Spacer()

                        Image(systemName: "arrow.up.left")
                            .font(.caption)
                            .foregroundStyle(.tertiary)
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 12)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)

                if search != recentSearches.prefix(5).last {
                    Divider()
                        .padding(.leading, 52)
                }
            }
        }
    }

    // MARK: - Suggestions Section

    private var suggestionsSection: some View {
        VStack(alignment: .leading, spacing: 0) {
            if !searchText.isEmpty {
                Text("Suggestions")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 12)
            }

            ForEach(suggestions) { suggestion in
                Button {
                    onSuggestionTap(suggestion)
                    searchText = suggestion.displayText
                    isFocused = false
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

                        Text(suggestionTypeLabel(suggestion.type))
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                            .padding(.horizontal, 8)
                            .padding(.vertical, 4)
                            .background(Color(.tertiarySystemBackground))
                            .clipShape(Capsule())
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 12)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)

                if suggestion.id != suggestions.last?.id {
                    Divider()
                        .padding(.leading, 52)
                }
            }
        }
    }

    // MARK: - No Results View

    private var noResultsView: some View {
        VStack(spacing: 8) {
            Image(systemName: "magnifyingglass")
                .font(.title2)
                .foregroundStyle(.secondary)

            Text("No results found")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            Text("Try a different search term")
                .font(.caption)
                .foregroundStyle(.tertiary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 32)
    }

    // MARK: - Helper Functions

    private func performSearch() {
        guard !searchText.isEmpty else { return }
        onSearch(searchText)
        isFocused = false
        showSuggestions = false
    }

    private func highlightedText(_ text: String, searchText: String) -> AttributedString {
        var attributed = AttributedString(text)

        if let range = attributed.range(of: searchText, options: .caseInsensitive) {
            attributed[range].font = .subheadline.bold()
            attributed[range].foregroundColor = AppColors.brandTeal
        }

        return attributed
    }

    private func suggestionTypeLabel(_ type: SearchSuggestion.SuggestionType) -> String {
        // Use the displayLabel from the enum for consistency
        type.displayLabel
    }
}

// MARK: - Preview

#Preview {
    struct PreviewWrapper: View {
        @State var searchText = ""
        @State var isSearching = false

        var body: some View {
            VStack {
                SearchAutocompleteView(
                    searchText: $searchText,
                    isSearching: $isSearching,
                    suggestions: [
                        SearchSuggestion(type: .city, value: "Boston", displayText: "Boston", subtitle: "Massachusetts"),
                        SearchSuggestion(type: .neighborhood, value: "Back Bay", displayText: "Back Bay", subtitle: "Boston, MA"),
                        SearchSuggestion(type: .zip, value: "02116", displayText: "02116", subtitle: "Massachusetts")
                    ],
                    recentSearches: ["Cambridge", "02138", "Back Bay"],
                    onSearch: { _ in },
                    onSuggestionTap: { _ in },
                    onRecentSearchTap: { _ in },
                    onClearRecent: {}
                )

                Spacer()
            }
            .background(Color(.systemGroupedBackground))
        }
    }

    return PreviewWrapper()
}
