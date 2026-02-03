//
//  ComparisonStore.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Property Comparison Selection Manager (v237)
//

import Foundation
import SwiftUI

/// Manages property selection state for comparison feature
@MainActor
class ComparisonStore: ObservableObject {
    static let shared = ComparisonStore()

    /// Set of selected property IDs for comparison
    @Published var selectedPropertyIds: Set<String> = []

    /// Whether selection mode is currently active
    @Published var isSelectionModeActive: Bool = false

    /// Minimum properties required for comparison
    let minProperties = 2

    /// Maximum properties allowed for comparison
    let maxProperties = 5

    private init() {}

    // MARK: - Computed Properties

    /// Whether we have enough properties selected to compare
    var canCompare: Bool {
        selectedPropertyIds.count >= minProperties &&
        selectedPropertyIds.count <= maxProperties
    }

    /// Number of properties currently selected
    var selectionCount: Int {
        selectedPropertyIds.count
    }

    /// Whether we can select more properties
    var canSelectMore: Bool {
        selectedPropertyIds.count < maxProperties
    }

    // MARK: - Selection Methods

    /// Toggle selection state for a property
    func toggleSelection(for propertyId: String) {
        if selectedPropertyIds.contains(propertyId) {
            selectedPropertyIds.remove(propertyId)
        } else if canSelectMore {
            selectedPropertyIds.insert(propertyId)
            HapticManager.impact(.light)
        }
    }

    /// Check if a property is selected
    func isSelected(_ propertyId: String) -> Bool {
        selectedPropertyIds.contains(propertyId)
    }

    /// Clear all selections
    func clearSelection() {
        selectedPropertyIds.removeAll()
    }

    /// Exit selection mode and clear selections
    func exitSelectionMode() {
        isSelectionModeActive = false
        clearSelection()
    }

    /// Enter selection mode
    func enterSelectionMode() {
        isSelectionModeActive = true
    }
}
