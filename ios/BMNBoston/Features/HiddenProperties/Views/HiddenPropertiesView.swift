//
//  HiddenPropertiesView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import SwiftUI

struct HiddenPropertiesView: View {
    @EnvironmentObject var viewModel: PropertySearchViewModel
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            Group {
                if viewModel.isLoadingHidden {
                    // Loading state
                    VStack(spacing: 16) {
                        ProgressView()
                        Text("Loading hidden properties...")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else if viewModel.hiddenProperties.isEmpty {
                    // Empty state
                    VStack(spacing: 20) {
                        Image(systemName: "eye.slash")
                            .font(.system(size: 56))
                            .foregroundStyle(.secondary)

                        Text("No Hidden Properties")
                            .font(.title2)
                            .fontWeight(.semibold)

                        Text("Properties you hide will appear here.\nHide properties you're not interested in to keep your search results clean.")
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
                    // List of hidden properties
                    ScrollView {
                        LazyVStack(spacing: 16) {
                            ForEach(viewModel.hiddenProperties) { property in
                                HiddenPropertyCard(
                                    property: property,
                                    onUnhide: {
                                        Task {
                                            await viewModel.unhideProperty(id: property.id)
                                        }
                                    },
                                    isLoading: viewModel.hiddenLoadingIds.contains(property.id)
                                )
                            }
                        }
                        .padding()
                    }
                    .refreshable {
                        await viewModel.loadHiddenProperties(forceRefresh: true)
                    }
                }
            }
            .navigationTitle("Hidden Properties")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") {
                        dismiss()
                    }
                }
            }
        }
        .task {
            await viewModel.loadHiddenProperties()
        }
    }
}

// MARK: - Hidden Property Card

struct HiddenPropertyCard: View {
    let property: Property
    let onUnhide: () -> Void
    var isLoading: Bool = false

    var body: some View {
        NavigationLink(destination: PropertyDetailView(propertyId: property.id)) {
            VStack(alignment: .leading, spacing: 0) {
                // Image
                ZStack(alignment: .topTrailing) {
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

                    // Unhide button
                    Button {
                        guard !isLoading else { return }
                        HapticManager.impact(.light)
                        onUnhide()
                    } label: {
                        HStack(spacing: 4) {
                            if isLoading {
                                ProgressView()
                                    .progressViewStyle(CircularProgressViewStyle(tint: .primary))
                                    .scaleEffect(0.7)
                            } else {
                                Image(systemName: "eye")
                                    .font(.caption)
                            }
                            Text(isLoading ? "Unhiding..." : "Unhide")
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
            .shadow(color: AppColors.shadowLight, radius: 4, x: 0, y: 2)
        }
        .buttonStyle(.plain)
    }
}
