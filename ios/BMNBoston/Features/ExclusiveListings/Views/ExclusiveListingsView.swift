//
//  ExclusiveListingsView.swift
//  BMNBoston
//
//  Main view for listing and managing agent's exclusive listings
//
//  Created for BMN Boston Real Estate
//

import SwiftUI

struct ExclusiveListingsView: View {
    @StateObject private var viewModel = ExclusiveListingsViewModel()
    @State private var showCreateSheet = false
    @State private var selectedListingForEdit: ExclusiveListing?
    @State private var selectedListingForDetail: ExclusiveListing?
    @State private var showDeleteConfirmation = false
    @State private var listingToDelete: ExclusiveListing?

    var body: some View {
        NavigationStack {
            ZStack {
                Color(.systemGroupedBackground)
                    .ignoresSafeArea()

                if viewModel.isLoading && viewModel.listings.isEmpty {
                    loadingView
                } else if let error = viewModel.errorMessage {
                    errorView(message: error)
                } else if viewModel.listings.isEmpty {
                    emptyView
                } else {
                    listView
                }
            }
            .navigationTitle("Exclusive Listings")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button {
                        viewModel.prepareNewListing()
                        showCreateSheet = true
                    } label: {
                        Image(systemName: "plus")
                    }
                }

                ToolbarItem(placement: .topBarLeading) {
                    Menu {
                        Button {
                            viewModel.setStatusFilter(nil)
                        } label: {
                            Label("All", systemImage: viewModel.statusFilter == nil ? "checkmark" : "")
                        }
                        Button {
                            viewModel.setStatusFilter("Active")
                        } label: {
                            Label("Active", systemImage: viewModel.statusFilter == "Active" ? "checkmark" : "")
                        }
                        Button {
                            viewModel.setStatusFilter("Pending")
                        } label: {
                            Label("Pending", systemImage: viewModel.statusFilter == "Pending" ? "checkmark" : "")
                        }
                        Button {
                            viewModel.setStatusFilter("Closed")
                        } label: {
                            Label("Closed", systemImage: viewModel.statusFilter == "Closed" ? "checkmark" : "")
                        }
                    } label: {
                        HStack(spacing: 4) {
                            Image(systemName: "line.3.horizontal.decrease.circle")
                            if let filter = viewModel.statusFilter {
                                Text(filter)
                                    .font(.caption)
                            }
                        }
                    }
                }
            }
            .sheet(isPresented: $showCreateSheet) {
                CreateExclusiveListingSheet(viewModel: viewModel, isPresented: $showCreateSheet)
            }
            .sheet(item: $selectedListingForEdit) { listing in
                EditExclusiveListingSheet(viewModel: viewModel, listing: listing, isPresented: $selectedListingForEdit)
            }
            .sheet(item: $selectedListingForDetail) { listing in
                ExclusiveListingDetailView(viewModel: viewModel, listing: listing)
            }
            .alert("Delete Listing", isPresented: $showDeleteConfirmation) {
                Button("Archive", role: .destructive) {
                    if let listing = listingToDelete {
                        Task {
                            await viewModel.deleteListing(id: listing.id, archive: true)
                        }
                    }
                }
                Button("Delete Permanently", role: .destructive) {
                    if let listing = listingToDelete {
                        Task {
                            await viewModel.deleteListing(id: listing.id, archive: false)
                        }
                    }
                }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Would you like to archive this listing (keeps data for records) or delete it permanently?")
            }
            .task {
                await viewModel.loadListings()
            }
            .refreshable {
                await viewModel.loadListings(forceRefresh: true)
            }
        }
    }

    // MARK: - Subviews

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Loading listings...")
                .foregroundStyle(.secondary)
        }
    }

    private func errorView(message: String) -> some View {
        VStack(spacing: 16) {
            Image(systemName: "exclamationmark.triangle.fill")
                .font(.system(size: 50))
                .foregroundStyle(.orange)
            Text("Error Loading Listings")
                .font(.title2)
                .fontWeight(.semibold)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button {
                Task {
                    await viewModel.loadListings(forceRefresh: true)
                }
            } label: {
                Text("Try Again")
            }
            .buttonStyle(.borderedProminent)
        }
        .padding()
    }

    private var emptyView: some View {
        VStack(spacing: 16) {
            Image(systemName: "house.fill")
                .font(.system(size: 50))
                .foregroundStyle(.secondary)
            Text("No Exclusive Listings")
                .font(.title2)
                .fontWeight(.semibold)
            Text("Create your first exclusive listing to get started.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button {
                viewModel.prepareNewListing()
                showCreateSheet = true
            } label: {
                Text("Create Listing")
            }
            .buttonStyle(.borderedProminent)
        }
        .padding()
    }

    private var listView: some View {
        List {
            // Stats header
            Section {
                statsHeader
            }
            .listRowBackground(Color.clear)
            .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 8, trailing: 0))

            // Listings
            Section {
                ForEach(viewModel.listings) { listing in
                    ExclusiveListingCard(listing: listing)
                        .listRowBackground(Color.clear)
                        .listRowInsets(EdgeInsets(top: 6, leading: 16, bottom: 6, trailing: 16))
                        .listRowSeparator(.hidden)
                        .onTapGesture {
                            selectedListingForDetail = listing
                        }
                        .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                            Button(role: .destructive) {
                                listingToDelete = listing
                                showDeleteConfirmation = true
                            } label: {
                                Label("Delete", systemImage: "trash")
                            }

                            Button {
                                viewModel.prepareEditListing(listing)
                                selectedListingForEdit = listing
                            } label: {
                                Label("Edit", systemImage: "pencil")
                            }
                            .tint(AppColors.brandTeal)
                        }
                        .contextMenu {
                            Button {
                                viewModel.prepareEditListing(listing)
                                selectedListingForEdit = listing
                            } label: {
                                Label("Edit", systemImage: "pencil")
                            }

                            Button(role: .destructive) {
                                listingToDelete = listing
                                showDeleteConfirmation = true
                            } label: {
                                Label("Delete", systemImage: "trash")
                            }
                        }
                }

                // Load more indicator
                if viewModel.hasMorePages {
                    ProgressView()
                        .frame(maxWidth: .infinity)
                        .padding()
                        .listRowBackground(Color.clear)
                        .onAppear {
                            Task {
                                await viewModel.loadMore()
                            }
                        }
                }
            }
        }
        .listStyle(.plain)
        .scrollContentBackground(.hidden)
        .background(Color(.systemGroupedBackground))
    }

    private var statsHeader: some View {
        HStack(spacing: 16) {
            StatBadge(
                count: viewModel.activeListings.count,
                label: "Active",
                color: .green
            )
            StatBadge(
                count: viewModel.pendingListings.count,
                label: "Pending",
                color: .orange
            )
            StatBadge(
                count: viewModel.closedListings.count,
                label: "Closed",
                color: .gray
            )
        }
        .padding(.bottom, 8)
    }
}

// MARK: - Stat Badge

private struct StatBadge: View {
    let count: Int
    let label: String
    let color: Color

    var body: some View {
        VStack(spacing: 4) {
            Text("\(count)")
                .font(.title2)
                .fontWeight(.bold)
                .foregroundStyle(color)
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 12)
        .background(color.opacity(0.1))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }
}

// MARK: - Exclusive Listing Card

struct ExclusiveListingCard: View {
    let listing: ExclusiveListing

    var body: some View {
        HStack(spacing: 12) {
            // Photo
            AsyncImage(url: listing.primaryImageURL) { phase in
                switch phase {
                case .success(let image):
                    image
                        .resizable()
                        .scaledToFill()
                case .empty:
                    Rectangle()
                        .fill(Color(.systemGray5))
                        .overlay {
                            ProgressView()
                        }
                case .failure:
                    Rectangle()
                        .fill(Color(.systemGray5))
                        .overlay {
                            Image(systemName: "photo")
                                .foregroundStyle(.secondary)
                        }
                @unknown default:
                    Rectangle()
                        .fill(Color(.systemGray5))
                }
            }
            .frame(width: 100, height: 75)
            .clipShape(RoundedRectangle(cornerRadius: 8))

            // Info
            VStack(alignment: .leading, spacing: 4) {
                // Status badge
                HStack {
                    ExclusiveStatusBadge(status: listing.standardStatus)
                    Spacer()
                    Text("ID: \(listing.id)")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }

                // Address
                Text(listing.shortAddress)
                    .font(.headline)
                    .lineLimit(1)

                Text(listing.city)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)

                // Price and details
                HStack {
                    Text(listing.formattedPrice)
                        .font(.subheadline)
                        .fontWeight(.semibold)
                        .foregroundStyle(AppColors.brandTeal)

                    Spacer()

                    HStack(spacing: 8) {
                        if let beds = listing.bedroomsTotal {
                            Label("\(beds)", systemImage: "bed.double.fill")
                        }
                        if let baths = listing.bathroomsTotal {
                            Label(listing.formattedBaths, systemImage: "shower.fill")
                        }
                        if listing.photoCount > 0 {
                            Label("\(listing.photoCount)", systemImage: "photo")
                        }
                    }
                    .font(.caption)
                    .foregroundStyle(.secondary)
                }
            }
        }
        .padding(12)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.05), radius: 4, y: 2)
    }
}

// MARK: - Status Badge

private struct ExclusiveStatusBadge: View {
    let status: String

    var body: some View {
        Text(status)
            .font(.caption2)
            .fontWeight(.medium)
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
            .background(backgroundColor)
            .foregroundStyle(foregroundColor)
            .clipShape(Capsule())
    }

    private var backgroundColor: Color {
        switch status.lowercased() {
        case "active":
            return .green.opacity(0.15)
        case "pending", "active under contract":
            return .orange.opacity(0.15)
        case "closed":
            return .gray.opacity(0.15)
        default:
            return .blue.opacity(0.15)
        }
    }

    private var foregroundColor: Color {
        switch status.lowercased() {
        case "active":
            return .green
        case "pending", "active under contract":
            return .orange
        case "closed":
            return .gray
        default:
            return .blue
        }
    }
}

// MARK: - Preview

#Preview {
    ExclusiveListingsView()
}
