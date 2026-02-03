//
//  ExclusiveListingDetailView.swift
//  BMNBoston
//
//  Detail view for exclusive listings with photo management
//
//  Created for BMN Boston Real Estate
//

import SwiftUI
import PhotosUI

struct ExclusiveListingDetailView: View {
    @ObservedObject var viewModel: ExclusiveListingsViewModel
    let listing: ExclusiveListing

    @Environment(\.dismiss) private var dismiss

    @State private var detailListing: ExclusiveListing?
    @State private var isLoading = true
    @State private var showEditSheet = false
    @State private var showPhotosPicker = false
    @State private var selectedPhotos: [PhotosPickerItem] = []
    @State private var showDeletePhotoConfirmation = false
    @State private var photoToDelete: ExclusiveListingPhoto?
    @State private var showCopiedFeedback = false
    @State private var isReorderingPhotos = false
    @State private var reorderablePhotos: [ExclusiveListingPhoto] = []
    @State private var isReorderingSaving = false
    @State private var showDeleteListingConfirmation = false
    @State private var isDeleting = false

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {
                // Photo Gallery
                photoGallerySection

                // Listing Details
                VStack(alignment: .leading, spacing: 20) {
                    // Header
                    headerSection

                    Divider()

                    // Property Info
                    propertyInfoSection

                    Divider()

                    // Features
                    featuresSection

                    Divider()

                    // Actions
                    actionsSection
                }
                .padding()
            }
        }
        .navigationTitle("Listing Details")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Button {
                    dismiss()
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .font(.title2)
                        .symbolRenderingMode(.hierarchical)
                        .foregroundStyle(.secondary)
                }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button {
                    viewModel.prepareEditListing(detailListing ?? listing)
                    showEditSheet = true
                } label: {
                    Image(systemName: "pencil")
                }
            }
        }
        .sheet(isPresented: $showEditSheet) {
            if let detail = detailListing {
                EditExclusiveListingSheet(
                    viewModel: viewModel,
                    listing: detail,
                    isPresented: Binding(
                        get: { showEditSheet ? detail : nil },
                        set: { _ in showEditSheet = false }
                    )
                )
            }
        }
        .photosPicker(
            isPresented: $showPhotosPicker,
            selection: $selectedPhotos,
            maxSelectionCount: 10,
            matching: .images
        )
        .onChange(of: selectedPhotos) { newPhotos in
            if !newPhotos.isEmpty {
                Task {
                    await uploadSelectedPhotos(newPhotos)
                }
            }
        }
        .alert("Delete Photo", isPresented: $showDeletePhotoConfirmation) {
            Button("Delete", role: .destructive) {
                if let photo = photoToDelete {
                    Task {
                        await deletePhoto(photo)
                    }
                }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Are you sure you want to delete this photo?")
        }
        .overlay {
            if viewModel.isUploadingPhotos {
                uploadingOverlay
            }
        }
        .alert("Upload Error", isPresented: .init(
            get: { viewModel.errorMessage != nil },
            set: { if !$0 { viewModel.errorMessage = nil } }
        )) {
            Button("OK") {
                viewModel.errorMessage = nil
            }
        } message: {
            Text(viewModel.errorMessage ?? "An error occurred")
        }
        .alert("Delete Listing", isPresented: $showDeleteListingConfirmation) {
            Button("Archive", role: .destructive) {
                Task {
                    await deleteListing(archive: true)
                }
            }
            Button("Delete Permanently", role: .destructive) {
                Task {
                    await deleteListing(archive: false)
                }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Would you like to archive this listing (keeps data for records) or delete it permanently?")
        }
        .task {
            await loadDetails()
        }
        // Sync detailListing with viewModel.selectedListing after edits
        // This ensures the view shows updated data after saving without re-fetching
        .onChange(of: viewModel.selectedListing) { newListing in
            if let updated = newListing, updated.id == listing.id {
                detailListing = updated
            }
        }
        .presentationDragIndicator(.visible)
    }

    // MARK: - Photo Gallery

    private var photoGallerySection: some View {
        VStack(spacing: 0) {
            if let photos = (detailListing ?? listing).photos, !photos.isEmpty {
                if isReorderingPhotos {
                    // Reorder mode - draggable grid
                    photoReorderGrid
                } else {
                    // Normal mode - swipeable gallery
                    TabView {
                        ForEach(photos) { photo in
                            AsyncImage(url: photo.imageURL) { phase in
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
                                                .font(.largeTitle)
                                                .foregroundStyle(.secondary)
                                        }
                                @unknown default:
                                    Rectangle()
                                        .fill(Color(.systemGray5))
                                }
                            }
                            .frame(height: 280)
                            .clipped()
                            .contextMenu {
                                Button(role: .destructive) {
                                    photoToDelete = photo
                                    showDeletePhotoConfirmation = true
                                } label: {
                                    Label("Delete Photo", systemImage: "trash")
                                }
                            }
                        }
                    }
                    .tabViewStyle(.page(indexDisplayMode: .automatic))
                    .frame(height: 280)
                }
            } else {
                // No photos placeholder
                Rectangle()
                    .fill(Color(.systemGray5))
                    .frame(height: 200)
                    .overlay {
                        VStack(spacing: 12) {
                            Image(systemName: "photo.on.rectangle.angled")
                                .font(.system(size: 40))
                                .foregroundStyle(.secondary)
                            Text("No photos yet")
                                .foregroundStyle(.secondary)
                        }
                    }
            }

            // Photo action buttons
            photoActionButtons
        }
    }

    private var photoReorderGrid: some View {
        VStack(spacing: 8) {
            Text("Drag to reorder photos")
                .font(.caption)
                .foregroundStyle(.secondary)
                .padding(.top, 8)

            List {
                ForEach(Array(reorderablePhotos.enumerated()), id: \.element.id) { index, photo in
                    HStack(spacing: 12) {
                        AsyncImage(url: photo.imageURL) { phase in
                            switch phase {
                            case .success(let image):
                                image
                                    .resizable()
                                    .scaledToFill()
                            case .empty, .failure:
                                Rectangle()
                                    .fill(Color(.systemGray5))
                            @unknown default:
                                Rectangle()
                                    .fill(Color(.systemGray5))
                            }
                        }
                        .frame(width: 80, height: 60)
                        .clipShape(RoundedRectangle(cornerRadius: 6))

                        VStack(alignment: .leading, spacing: 2) {
                            if index == 0 {
                                Text("Primary Photo")
                                    .font(.caption)
                                    .fontWeight(.medium)
                                    .foregroundStyle(AppColors.brandTeal)
                            }
                            Text("Photo \(index + 1)")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }

                        Spacer()

                        Image(systemName: "line.3.horizontal")
                            .foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 4)
                }
                .onMove(perform: movePhotos)
            }
            .listStyle(.plain)
            .frame(height: 280)
        }
    }

    private var photoActionButtons: some View {
        HStack(spacing: 12) {
            // Add Photos Button
            Button {
                showPhotosPicker = true
            } label: {
                HStack {
                    Image(systemName: "plus.circle.fill")
                    Text("Add Photos")
                }
                .font(.subheadline)
                .fontWeight(.medium)
                .foregroundStyle(AppColors.brandTeal)
                .padding(.vertical, 12)
                .frame(maxWidth: .infinity)
                .background(Color(.systemGray6))
            }
            .disabled(isReorderingPhotos)

            // Reorder/Save Button
            if let photos = (detailListing ?? listing).photos, photos.count > 1 {
                Button {
                    if isReorderingPhotos {
                        savePhotoOrder()
                    } else {
                        startReordering()
                    }
                } label: {
                    HStack {
                        if isReorderingSaving {
                            ProgressView()
                                .scaleEffect(0.8)
                        } else {
                            Image(systemName: isReorderingPhotos ? "checkmark.circle.fill" : "arrow.up.arrow.down")
                        }
                        Text(isReorderingPhotos ? "Save Order" : "Reorder")
                    }
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .foregroundStyle(isReorderingPhotos ? .white : AppColors.brandTeal)
                    .padding(.vertical, 12)
                    .frame(maxWidth: .infinity)
                    .background(isReorderingPhotos ? AppColors.brandTeal : Color(.systemGray6))
                }
                .disabled(isReorderingSaving)

                if isReorderingPhotos {
                    Button {
                        cancelReordering()
                    } label: {
                        Text("Cancel")
                            .font(.subheadline)
                            .fontWeight(.medium)
                            .foregroundStyle(.secondary)
                            .padding(.vertical, 12)
                            .padding(.horizontal, 16)
                            .background(Color(.systemGray6))
                    }
                }
            }
        }
        .padding(.horizontal, isReorderingPhotos ? 12 : 0)
        .padding(.vertical, isReorderingPhotos ? 8 : 0)
        .background(isReorderingPhotos ? Color(.systemGray6) : Color.clear)
    }

    // MARK: - Photo Reorder Actions

    private func startReordering() {
        if let photos = (detailListing ?? listing).photos {
            reorderablePhotos = photos.sorted { $0.sortOrder < $1.sortOrder }
        }
        isReorderingPhotos = true
    }

    private func cancelReordering() {
        isReorderingPhotos = false
        reorderablePhotos = []
    }

    private func movePhotos(from source: IndexSet, to destination: Int) {
        reorderablePhotos.move(fromOffsets: source, toOffset: destination)
    }

    private func savePhotoOrder() {
        isReorderingSaving = true

        let photoIds = reorderablePhotos.map { $0.id }

        Task {
            let success = await viewModel.reorderPhotos(
                listingId: listing.id,
                photoIds: photoIds
            )

            isReorderingSaving = false

            if success {
                isReorderingPhotos = false
                reorderablePhotos = []
                // Reload to get updated order
                await loadDetails()
            }
        }
    }

    /// Format lot size to show both sq ft and acres (e.g., "21,780 Sq Ft (0.50 Acres)")
    private func formatLotSize(sqft: Int?, acres: Double?) -> String? {
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal

        // If we have sq ft, use it directly
        if let sqft = sqft, sqft > 0 {
            let sqftFormatted = formatter.string(from: NSNumber(value: sqft)) ?? "\(sqft)"
            if let acres = acres, acres > 0 {
                return "\(sqftFormatted) Sq Ft (\(String(format: "%.2f", acres)) Acres)"
            } else {
                return "\(sqftFormatted) Sq Ft"
            }
        }
        // If only acres, calculate sq ft
        else if let acres = acres, acres > 0 {
            let sqftCalculated = Int(acres * 43560)
            let sqftFormatted = formatter.string(from: NSNumber(value: sqftCalculated)) ?? "\(sqftCalculated)"
            return "\(sqftFormatted) Sq Ft (\(String(format: "%.2f", acres)) Acres)"
        }

        return nil
    }

    // MARK: - Header Section

    private var headerSection: some View {
        let current = detailListing ?? listing

        return VStack(alignment: .leading, spacing: 8) {
            // Status Badge
            ExclusiveStatusBadge(status: current.standardStatus)

            // Address
            Text(current.fullAddress)
                .font(.title2)
                .fontWeight(.bold)

            // Price
            Text(current.formattedPrice)
                .font(.title)
                .fontWeight(.bold)
                .foregroundStyle(AppColors.brandTeal)

            // Basic Stats
            HStack(spacing: 16) {
                if let beds = current.bedroomsTotal {
                    HStack(spacing: 4) {
                        Image(systemName: "bed.double.fill")
                            .foregroundStyle(.secondary)
                        Text("\(beds) bed")
                    }
                }

                if let baths = current.bathroomsTotal {
                    HStack(spacing: 4) {
                        Image(systemName: "shower.fill")
                            .foregroundStyle(.secondary)
                        Text(current.formattedBaths + " bath")
                    }
                }

                if let sqft = current.formattedSqft {
                    HStack(spacing: 4) {
                        Image(systemName: "square.fill")
                            .foregroundStyle(.secondary)
                        Text(sqft + " sqft")
                    }
                }
            }
            .font(.subheadline)

            // Listing ID and Days on Market
            HStack {
                Text("ID: \(current.id)")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                Spacer()

                Text("\(current.daysOnMarket) days on market")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
    }

    // MARK: - Property Info Section

    private var propertyInfoSection: some View {
        let current = detailListing ?? listing

        return VStack(alignment: .leading, spacing: 12) {
            Text("Property Details")
                .font(.headline)

            LazyVGrid(columns: [
                GridItem(.flexible()),
                GridItem(.flexible())
            ], spacing: 12) {
                ExclusiveDetailRow(label: "Property Type", value: current.propertyType)

                if let subType = current.propertySubType {
                    ExclusiveDetailRow(label: "Sub-Type", value: subType)
                }

                if let yearBuilt = current.yearBuilt {
                    ExclusiveDetailRow(label: "Year Built", value: String(yearBuilt))
                }

                // Display lot size with both sq ft and acres
                if let formattedLotSize = formatLotSize(sqft: current.lotSizeSquareFeet, acres: current.lotSizeAcres) {
                    ExclusiveDetailRow(label: "Lot Size", value: formattedLotSize)
                }

                if let garage = current.garageSpaces {
                    ExclusiveDetailRow(label: "Garage", value: "\(garage) spaces")
                }

                if let pricePerSqft = current.pricePerSqft {
                    ExclusiveDetailRow(label: "Price/Sq Ft", value: "$\(Int(pricePerSqft))")
                }
            }
        }
    }

    // MARK: - Features Section

    private var featuresSection: some View {
        let current = detailListing ?? listing

        return VStack(alignment: .leading, spacing: 12) {
            Text("Features")
                .font(.headline)

            LazyVGrid(columns: [
                GridItem(.flexible()),
                GridItem(.flexible())
            ], spacing: 8) {
                FeatureChip(label: "Pool", isEnabled: current.hasPool, icon: "figure.pool.swim")
                FeatureChip(label: "Fireplace", isEnabled: current.hasFireplace, icon: "flame.fill")
                FeatureChip(label: "Basement", isEnabled: current.hasBasement, icon: "arrow.down.square.fill")
                FeatureChip(label: "HOA", isEnabled: current.hasHoa, icon: "building.2.fill")
            }
        }
    }

    // MARK: - Actions Section

    private var actionsSection: some View {
        let current = detailListing ?? listing

        return VStack(spacing: 12) {
            // Share Button
            if let url = current.url, let shareURL = URL(string: url) {
                ShareLink(item: shareURL) {
                    HStack {
                        Image(systemName: "square.and.arrow.up")
                        Text("Share Listing")
                    }
                    .font(.headline)
                    .foregroundStyle(.white)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(AppColors.brandTeal)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }

                // Copy Link Button
                Button {
                    UIPasteboard.general.string = shareURL.absoluteString
                    UIImpactFeedbackGenerator(style: .light).impactOccurred()
                    withAnimation(.spring(response: 0.3)) {
                        showCopiedFeedback = true
                    }
                    // Reset after delay
                    DispatchQueue.main.asyncAfter(deadline: .now() + 2) {
                        withAnimation {
                            showCopiedFeedback = false
                        }
                    }
                } label: {
                    HStack {
                        Image(systemName: showCopiedFeedback ? "checkmark.circle.fill" : "link")
                        Text(showCopiedFeedback ? "Link Copied!" : "Copy Link")
                    }
                    .font(.headline)
                    .foregroundStyle(showCopiedFeedback ? .green : AppColors.brandTeal)
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(Color(.systemGray6))
                    .clipShape(RoundedRectangle(cornerRadius: 12))
                }
            }

            // Edit Button
            Button {
                viewModel.prepareEditListing(current)
                showEditSheet = true
            } label: {
                HStack {
                    Image(systemName: "pencil")
                    Text("Edit Listing")
                }
                .font(.headline)
                .foregroundStyle(AppColors.brandTeal)
                .frame(maxWidth: .infinity)
                .padding()
                .background(Color(.systemGray6))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }

            // Delete Button
            Button {
                showDeleteListingConfirmation = true
            } label: {
                HStack {
                    if isDeleting {
                        ProgressView()
                            .tint(.red)
                    } else {
                        Image(systemName: "trash")
                    }
                    Text(isDeleting ? "Deleting..." : "Delete Listing")
                }
                .font(.headline)
                .foregroundStyle(.red)
                .frame(maxWidth: .infinity)
                .padding()
                .background(Color.red.opacity(0.1))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .disabled(isDeleting)
        }
    }

    // MARK: - Uploading Overlay

    private var uploadingOverlay: some View {
        ZStack {
            Color.black.opacity(0.3)
                .ignoresSafeArea()

            VStack(spacing: 16) {
                ProgressView()
                    .scaleEffect(1.2)

                if viewModel.uploadingPhotoTotal > 0 {
                    Text("Uploading photo \(viewModel.uploadingPhotoIndex) of \(viewModel.uploadingPhotoTotal)")
                        .font(.headline)

                    // Progress bar
                    ProgressView(value: Double(viewModel.uploadingPhotoIndex), total: Double(viewModel.uploadingPhotoTotal))
                        .progressViewStyle(LinearProgressViewStyle(tint: AppColors.brandTeal))
                        .frame(width: 150)
                } else {
                    Text("Preparing photos...")
                        .font(.headline)
                }
            }
            .padding(24)
            .background(Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .shadow(radius: 10)
        }
    }

    // MARK: - Actions

    private func loadDetails() async {
        isLoading = true
        await viewModel.loadListing(id: listing.id)
        detailListing = viewModel.selectedListing
        isLoading = false
    }

    private func uploadSelectedPhotos(_ items: [PhotosPickerItem]) async {
        print("[PhotoUpload] Selected \(items.count) items from picker")
        var images: [UIImage] = []

        for item in items {
            if let data = try? await item.loadTransferable(type: Data.self) {
                print("[PhotoUpload] Loaded data: \(data.count) bytes")
                if let image = UIImage(data: data) {
                    images.append(image)
                    print("[PhotoUpload] Converted to UIImage: \(image.size)")
                } else {
                    print("[PhotoUpload] Failed to convert data to UIImage")
                }
            } else {
                print("[PhotoUpload] Failed to load transferable data from item")
            }
        }

        print("[PhotoUpload] Total images ready for upload: \(images.count)")

        if !images.isEmpty {
            let success = await viewModel.uploadPhotos(listingId: listing.id, images: images)
            print("[PhotoUpload] Upload result: \(success ? "success" : "failed")")
            // Reload to get updated photos
            await loadDetails()
        } else {
            print("[PhotoUpload] No images to upload")
        }

        selectedPhotos = []
    }

    private func deletePhoto(_ photo: ExclusiveListingPhoto) async {
        _ = await viewModel.deletePhoto(listingId: listing.id, photoId: photo.id)
        // Reload to get updated photos
        await loadDetails()
    }

    private func deleteListing(archive: Bool) async {
        isDeleting = true
        let success = await viewModel.deleteListing(id: listing.id, archive: archive)
        isDeleting = false

        if success {
            dismiss()
        }
    }
}

// MARK: - Supporting Views

private struct ExclusiveDetailRow: View {
    let label: String
    let value: String

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
            Text(value)
                .font(.subheadline)
                .fontWeight(.medium)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

private struct FeatureChip: View {
    let label: String
    let isEnabled: Bool
    let icon: String

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: icon)
                .foregroundStyle(isEnabled ? AppColors.brandTeal : .secondary)
            Text(label)
            Spacer()
            Image(systemName: isEnabled ? "checkmark.circle.fill" : "circle")
                .foregroundStyle(isEnabled ? .green : .secondary)
        }
        .font(.subheadline)
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(Color(.systemGray6))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }
}

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
    NavigationStack {
        ExclusiveListingDetailView(
            viewModel: ExclusiveListingsViewModel(),
            listing: ExclusiveListing(
                id: 1,
                listingId: 1,
                listingKey: "test",
                mlsId: nil,
                isExclusive: true,
                exclusiveTag: "Exclusive",
                status: "Active",
                standardStatus: "Active",
                listPrice: 1250000,
                pricePerSqft: 450,
                propertyType: "Residential",
                propertySubType: "Single Family",
                streetNumber: "123",
                streetName: "Main Street",
                unitNumber: nil,
                city: "Boston",
                state: "MA",
                stateOrProvince: "MA",
                postalCode: "02101",
                county: "Suffolk",
                subdivisionName: nil,
                unparsedAddress: nil,
                latitude: 42.36,
                longitude: -71.06,
                bedroomsTotal: 4,
                bathroomsTotal: 2.5,
                bathroomsFull: 2,
                bathroomsHalf: 1,
                buildingAreaTotal: 2800,
                lotSizeAcres: 0.25,
                lotSizeSquareFeet: 10890,
                yearBuilt: 1920,
                garageSpaces: 2,
                hasPool: false,
                hasFireplace: true,
                hasBasement: true,
                hasHoa: false,
                // Tier 1 - Property Description
                originalListPrice: nil,
                architecturalStyle: "Colonial",
                storiesTotal: 2,
                virtualTourUrl: nil,
                publicRemarks: "Beautiful colonial home",
                privateRemarks: nil,
                showingInstructions: nil,
                // Tier 2 - Interior Details
                heating: "Forced Air",
                cooling: "Central AC",
                heatingYn: true,
                coolingYn: true,
                interiorFeatures: "Hardwood Floors",
                appliances: nil,
                flooring: nil,
                laundryFeatures: nil,
                basement: "Finished",
                // Tier 3 - Exterior & Lot
                constructionMaterials: nil,
                roof: nil,
                foundationDetails: nil,
                exteriorFeatures: nil,
                waterfrontYn: false,
                waterfrontFeatures: nil,
                viewYn: false,
                view: nil,
                parkingFeatures: nil,
                parkingTotal: nil,
                // Tier 4 - Financial
                taxAnnualAmount: 12500,
                taxYear: 2024,
                associationYn: false,
                associationFee: nil,
                associationFeeFrequency: nil,
                associationFeeIncludes: nil,
                // Media & Dates
                mainPhotoUrl: nil,
                photoCount: 0,
                listingContractDate: nil,
                daysOnMarket: 14,
                modificationTimestamp: nil,
                url: nil,
                photos: nil
            )
        )
    }
}
