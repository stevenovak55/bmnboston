//
//  SearchConstants.swift
//  BMNBoston
//
//  Centralized constants for property search functionality
//

import Foundation

enum SearchConstants {
    // MARK: - Pagination

    /// Default number of results per page for list view
    static let defaultPerPage = 20

    /// Number of results per page for map view (higher for better coverage)
    static let mapPerPage = 200

    /// Per page count when only fetching count (not full results)
    static let countOnlyPerPage = 1

    // MARK: - Retry Logic

    /// Maximum number of retry attempts for failed API calls
    static let maxRetries = 2

    /// Base delay between retries in nanoseconds (1 second)
    static let retryDelayNanoseconds: UInt64 = 1_000_000_000

    // MARK: - Debounce

    /// Debounce interval for search text changes in milliseconds
    static let searchDebounceMs = 250

    /// Debounce interval in nanoseconds for Task.sleep
    static let searchDebounceNanoseconds: UInt64 = 250_000_000

    /// Debounce interval for filter count preview in nanoseconds (200ms)
    static let filterCountDebounceNanoseconds: UInt64 = 200_000_000

    /// Debounce interval for filter modal changes in nanoseconds (300ms)
    static let filterModalDebounceNanoseconds: UInt64 = 300_000_000

    /// Debounce interval for filter modal price changes in nanoseconds (500ms)
    static let filterPriceDebounceNanoseconds: UInt64 = 500_000_000

    // MARK: - UI Dimensions

    /// Height of the image carousel in property cards and detail view
    static let imageCarouselHeight: CGFloat = 280

    // MARK: - Autocomplete

    /// Minimum query length before triggering autocomplete suggestions
    static let minAutocompleteQueryLength = 2

    /// Minimum ZIP code length to search for ZIP suggestions
    static let minZipQueryLength = 3

    /// Minimum MLS number length to search for MLS suggestions
    static let minMlsQueryLength = 4
}
