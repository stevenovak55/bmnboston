//
//  SavedPropertiesView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Property Comparison Selection (v237)
//

import SwiftUI

struct SavedPropertiesView: View {
    @EnvironmentObject var viewModel: PropertySearchViewModel
    @StateObject private var comparisonStore = ComparisonStore.shared
    @Environment(\.dismiss) private var dismiss
    @State private var showComparisonView = false

    var body: some View {
        NavigationStack {
            Group {
                if viewModel.isLoadingFavorites {
                    // Loading state
                    VStack(spacing: 16) {
                        ProgressView()
                        Text("Loading saved properties...")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if viewModel.favoriteProperties.isEmpty {
                    // Empty state
                    VStack(spacing: 20) {
                        Image(systemName: "heart.slash")
                            .font(.system(size: 56))
                            .foregroundStyle(.secondary)

                        Text("No Saved Properties")
                            .font(.title2)
                            .fontWeight(.semibold)

                        Text("Properties you save will appear here.\nTap the heart button on any property to save it.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                            .padding(.horizontal, 32)

                        Button {
                            dismiss()
                            NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
                        } label: {
                            Label("Search Properties", systemImage: "magnifyingglass")
                                .fontWeight(.semibold)
                        }
                        .buttonStyle(.borderedProminent)
                        .tint(AppColors.brandTeal)
                        .padding(.top, 8)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    // List of saved properties
                    ZStack(alignment: .bottom) {
                        ScrollView {
                            LazyVStack(spacing: 16) {
                                // Selection mode hint
                                if comparisonStore.isSelectionModeActive {
                                    selectionHint
                                }

                                ForEach(viewModel.favoriteProperties) { property in
                                    SavedPropertyCard(
                                        property: property,
                                        onRemove: {
                                            Task {
                                                await viewModel.toggleFavorite(for: property)
                                            }
                                        },
                                        isLoading: viewModel.favoriteLoadingIds.contains(property.id),
                                        isSelectionMode: comparisonStore.isSelectionModeActive,
                                        isSelected: comparisonStore.isSelected(property.id),
                                        onSelect: {
                                            comparisonStore.toggleSelection(for: property.id)
                                        }
                                    )
                                }
                            }
                            .padding()
                            .padding(.bottom, comparisonStore.isSelectionModeActive ? 80 : 0)
                        }
                        .refreshable {
                            await viewModel.loadFavoriteProperties(forceRefresh: true)
                        }

                        // Compare button (floating)
                        if comparisonStore.isSelectionModeActive {
                            compareButton
                        }
                    }
                }
            }
            .navigationTitle(comparisonStore.isSelectionModeActive ? "Select Properties" : "Saved Properties")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(comparisonStore.isSelectionModeActive ? "Cancel" : "Done") {
                        if comparisonStore.isSelectionModeActive {
                            comparisonStore.exitSelectionMode()
                        } else {
                            dismiss()
                        }
                    }
                }

                // Compare button in toolbar (to enter selection mode)
                if !comparisonStore.isSelectionModeActive && viewModel.favoriteProperties.count >= 2 {
                    ToolbarItem(placement: .primaryAction) {
                        Button {
                            comparisonStore.enterSelectionMode()
                        } label: {
                            Label("Compare", systemImage: "square.on.square")
                        }
                    }
                }
            }
            .sheet(isPresented: $showComparisonView) {
                PropertyComparisonView(properties: selectedProperties)
            }
        }
        .task {
            await viewModel.loadFavoriteProperties()
        }
        .onDisappear {
            // Exit selection mode when view closes
            if comparisonStore.isSelectionModeActive {
                comparisonStore.exitSelectionMode()
            }
        }
    }

    // MARK: - Selected Properties

    private var selectedProperties: [Property] {
        viewModel.favoriteProperties.filter { comparisonStore.isSelected($0.id) }
    }

    // MARK: - Selection Hint

    private var selectionHint: some View {
        HStack(spacing: 8) {
            Image(systemName: "info.circle.fill")
                .foregroundStyle(AppColors.brandTeal)
            Text("Select \(comparisonStore.minProperties)-\(comparisonStore.maxProperties) properties to compare")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity)
        .background(AppColors.brandTeal.opacity(0.1))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }

    // MARK: - Compare Button

    private var compareButton: some View {
        VStack(spacing: 0) {
            Button {
                showComparisonView = true
            } label: {
                HStack(spacing: 8) {
                    Image(systemName: "square.on.square")
                    Text("Compare \(comparisonStore.selectionCount) Properties")
                        .fontWeight(.semibold)
                }
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
                .background(comparisonStore.canCompare ? AppColors.brandTeal : Color.gray)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .disabled(!comparisonStore.canCompare)
            .padding(.horizontal, 20)
            .padding(.vertical, 12)
            .background(
                Rectangle()
                    .fill(.ultraThinMaterial)
                    .ignoresSafeArea()
            )
        }
    }
}

// MARK: - Saved Property Card

struct SavedPropertyCard: View {
    let property: Property
    let onRemove: () -> Void
    var isLoading: Bool = false
    var isSelectionMode: Bool = false
    var isSelected: Bool = false
    var onSelect: (() -> Void)? = nil

    var body: some View {
        Group {
            if isSelectionMode {
                // Selection mode - tap to select
                Button {
                    onSelect?()
                } label: {
                    cardContent
                }
                .buttonStyle(.plain)
            } else {
                // Normal mode - tap to navigate
                NavigationLink(destination: PropertyDetailView(propertyId: property.id)) {
                    cardContent
                }
                .buttonStyle(.plain)
            }
        }
    }

    private var cardContent: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Image
            ZStack {
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
                                    .font(.largeTitle)
                                    .foregroundStyle(.secondary)
                            )
                    @unknown default:
                        EmptyView()
                    }
                }
                .frame(height: 180)
                .clipped()

                // Selection checkbox overlay (top left)
                if isSelectionMode {
                    VStack {
                        HStack {
                            ZStack {
                                Circle()
                                    .fill(isSelected ? AppColors.brandTeal : Color(.systemBackground).opacity(0.9))
                                    .frame(width: 28, height: 28)

                                if isSelected {
                                    Image(systemName: "checkmark")
                                        .font(.system(size: 14, weight: .bold))
                                        .foregroundStyle(.white)
                                } else {
                                    Circle()
                                        .stroke(Color.gray.opacity(0.5), lineWidth: 2)
                                        .frame(width: 28, height: 28)
                                }
                            }
                            .shadow(color: .black.opacity(0.2), radius: 2, x: 0, y: 1)
                            .padding(12)

                            Spacer()
                        }
                        Spacer()
                    }
                }

                // Remove button (top right) - only in normal mode
                if !isSelectionMode {
                    VStack {
                        HStack {
                            Spacer()
                            Button {
                                guard !isLoading else { return }
                                HapticManager.impact(.light)
                                onRemove()
                            } label: {
                                HStack(spacing: 4) {
                                    if isLoading {
                                        ProgressView()
                                            .progressViewStyle(CircularProgressViewStyle(tint: .primary))
                                            .scaleEffect(0.7)
                                    } else {
                                        Image(systemName: "heart.slash")
                                            .font(.caption)
                                    }
                                    Text(isLoading ? "Removing..." : "Remove")
                                        .font(.caption)
                                        .fontWeight(.medium)
                                }
                                .padding(.horizontal, 12)
                                .padding(.vertical, 8)
                                .background(.ultraThinMaterial)
                                .clipShape(Capsule())
                            }
                            .disabled(isLoading)
                            .padding(8)
                        }
                        Spacer()
                    }
                }
            }

            // Details
            VStack(alignment: .leading, spacing: 8) {
                // Price
                Text(property.formattedPrice)
                    .font(.title3)
                    .fontWeight(.bold)

                // Address
                Text(property.fullAddress)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)

                // MLS Number
                if let mls = property.mlsNumber {
                    Text("MLS# \(mls)")
                        .font(.caption2)
                        .foregroundStyle(.tertiary)
                }

                // Features
                HStack(spacing: 16) {
                    Label("\(property.beds) bd", systemImage: "bed.double.fill")
                    Label(property.formattedBathroomsDetailed + " ba", systemImage: "shower.fill")
                    if let sqft = property.formattedSqft {
                        Label(sqft, systemImage: "square.fill")
                    }
                }
                .font(.caption)
                .foregroundStyle(.secondary)
            }
            .padding(12)
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 3)
        )
        .shadow(color: AppColors.shadowLight, radius: 4, x: 0, y: 2)
    }
}
