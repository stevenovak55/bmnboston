//
//  ExclusiveListingsViewModel.swift
//  BMNBoston
//
//  ViewModel for managing Exclusive Listings in the iOS app
//
//  Created for BMN Boston Real Estate
//

import Foundation
import SwiftUI
import UIKit
import os.log

@MainActor
class ExclusiveListingsViewModel: ObservableObject {

    // MARK: - Published Properties

    @Published var listings: [ExclusiveListing] = []
    @Published var selectedListing: ExclusiveListing?
    @Published var isLoading = false
    @Published var isLoadingMore = false
    @Published var isSaving = false
    @Published var isDeleting = false
    @Published var isUploadingPhotos = false
    @Published var uploadingPhotoIndex: Int = 0
    @Published var uploadingPhotoTotal: Int = 0
    @Published var errorMessage: String?
    @Published var successMessage: String?

    // Pagination
    @Published var currentPage = 1
    @Published var totalPages = 1
    @Published var totalListings = 0

    // Options
    @Published var options: ExclusiveListingOptionsResponse?

    // Form state
    @Published var editingRequest = ExclusiveListingRequest()

    // Filter
    @Published var statusFilter: String? = nil

    // MARK: - Private Properties

    private let logger = Logger(subsystem: "com.bmnboston.app", category: "ExclusiveListingsViewModel")
    private var loadTask: Task<Void, Never>?

    // Draft storage
    private let draftKey = "exclusiveListingDraft"
    private var draftSaveTask: Task<Void, Never>?
    private let draftDebounceInterval: TimeInterval = 2.0

    // MARK: - Initialization

    init() {
        // Start with empty state - data loaded when view appears
    }

    deinit {
        loadTask?.cancel()
    }

    // MARK: - Load Listings

    func loadListings(forceRefresh: Bool = false) async {
        loadTask?.cancel()

        guard !isLoading else { return }
        isLoading = true
        errorMessage = nil

        loadTask = Task {
            defer { isLoading = false }

            do {
                let response = try await ExclusiveListingService.shared.fetchListings(
                    page: 1,
                    perPage: 20,
                    status: statusFilter,
                    forceRefresh: forceRefresh
                )

                guard !Task.isCancelled else { return }

                listings = response.items
                currentPage = response.page
                totalPages = response.totalPages
                totalListings = response.total

                logger.info("Loaded \(response.items.count) listings (total: \(response.total))")
            } catch {
                guard !Task.isCancelled else { return }
                // Show technical error for debugging
                if let apiError = error as? APIError {
                    errorMessage = apiError.technicalDescription
                } else if let elError = error as? ExclusiveListingError {
                    errorMessage = "\(elError)"
                } else {
                    errorMessage = "\(error)"
                }
                logger.error("Failed to load listings: \(error)")
            }
        }

        await loadTask?.value
    }

    func loadMore() async {
        guard !isLoadingMore && currentPage < totalPages else { return }

        isLoadingMore = true
        let nextPage = currentPage + 1

        do {
            let response = try await ExclusiveListingService.shared.fetchListings(
                page: nextPage,
                perPage: 20,
                status: statusFilter
            )

            listings.append(contentsOf: response.items)
            currentPage = response.page
            totalPages = response.totalPages

            logger.info("Loaded page \(nextPage), total items: \(self.listings.count)")
        } catch {
            errorMessage = error.localizedDescription
            logger.error("Failed to load more: \(error.localizedDescription)")
        }

        isLoadingMore = false
    }

    // MARK: - Load Single Listing

    func loadListing(id: Int) async {
        isLoading = true
        errorMessage = nil

        do {
            selectedListing = try await ExclusiveListingService.shared.fetchListing(id: id)
            logger.info("Loaded listing: id=\(id)")
        } catch {
            errorMessage = error.localizedDescription
            logger.error("Failed to load listing: \(error.localizedDescription)")
        }

        isLoading = false
    }

    // MARK: - Load Options

    func loadOptions() async {
        guard options == nil else { return }

        do {
            options = try await ExclusiveListingService.shared.fetchOptions()
            logger.info("Loaded options: \(self.options?.propertyTypes.count ?? 0) property types")
        } catch {
            logger.error("Failed to load options: \(error.localizedDescription)")
        }
    }

    // MARK: - Create Listing

    func createListing() async -> Bool {
        guard editingRequest.isValid else {
            errorMessage = "Please fill in all required fields"
            return false
        }

        isSaving = true
        errorMessage = nil
        successMessage = nil

        do {
            let listing = try await ExclusiveListingService.shared.createListing(request: editingRequest)

            // Add to list
            listings.insert(listing, at: 0)
            totalListings += 1

            // Clear form and draft
            editingRequest = ExclusiveListingRequest()
            clearDraft()

            successMessage = "Listing created successfully"
            logger.info("Created listing: id=\(listing.id)")

            isSaving = false
            return true
        } catch {
            errorMessage = error.localizedDescription
            logger.error("Failed to create listing: \(error.localizedDescription)")
            isSaving = false
            return false
        }
    }

    // MARK: - Update Listing

    func updateListing(id: Int) async -> Bool {
        isSaving = true
        errorMessage = nil
        successMessage = nil

        do {
            let updated = try await ExclusiveListingService.shared.updateListing(id: id, request: editingRequest)

            // Update in list
            if let index = listings.firstIndex(where: { $0.id == id }) {
                listings[index] = updated
            }

            // Update selected if same
            if selectedListing?.id == id {
                selectedListing = updated
            }

            successMessage = "Listing updated successfully"
            logger.info("Updated listing: id=\(id)")

            isSaving = false
            return true
        } catch {
            errorMessage = error.localizedDescription
            logger.error("Failed to update listing: \(error.localizedDescription)")
            isSaving = false
            return false
        }
    }

    // MARK: - Delete Listing

    func deleteListing(id: Int, archive: Bool = true) async -> Bool {
        isDeleting = true
        errorMessage = nil
        successMessage = nil

        do {
            try await ExclusiveListingService.shared.deleteListing(id: id, archive: archive)

            // Remove from list
            listings.removeAll { $0.id == id }
            totalListings -= 1

            // Clear selected if same
            if selectedListing?.id == id {
                selectedListing = nil
            }

            successMessage = archive ? "Listing archived successfully" : "Listing deleted successfully"
            logger.info("Deleted listing: id=\(id), archive=\(archive)")

            isDeleting = false
            return true
        } catch {
            errorMessage = error.localizedDescription
            logger.error("Failed to delete listing: \(error.localizedDescription)")
            isDeleting = false
            return false
        }
    }

    // MARK: - Photo Upload

    func uploadPhotos(listingId: Int, images: [UIImage]) async -> Bool {
        guard !images.isEmpty else { return true }

        print("[PhotoUpload] Starting upload of \(images.count) images to listing \(listingId)")
        logger.info("Starting upload of \(images.count) images to listing \(listingId)")

        isUploadingPhotos = true
        uploadingPhotoIndex = 0
        uploadingPhotoTotal = images.count
        errorMessage = nil
        successMessage = nil

        var uploadedCount = 0
        var failedCount = 0

        // Upload photos one at a time with progress tracking
        for (index, image) in images.enumerated() {
            uploadingPhotoIndex = index + 1
            print("[PhotoUpload] Uploading photo \(uploadingPhotoIndex) of \(uploadingPhotoTotal)")

            do {
                let response = try await ExclusiveListingService.shared.uploadSinglePhoto(
                    listingId: listingId,
                    image: image,
                    photoIndex: index + 1
                )
                uploadedCount += response.uploaded
                failedCount += response.failed
                print("[PhotoUpload] Photo \(uploadingPhotoIndex) completed")
            } catch {
                print("[PhotoUpload] Photo \(uploadingPhotoIndex) failed: \(error)")
                failedCount += 1
                // Continue with remaining photos even if one fails
            }
        }

        print("[PhotoUpload] All uploads completed: \(uploadedCount) uploaded, \(failedCount) failed")

        if failedCount > 0 {
            successMessage = "Uploaded \(uploadedCount) photos, \(failedCount) failed"
        } else {
            successMessage = "Uploaded \(uploadedCount) photos"
        }

        // Reload listing to get updated photos
        await loadListing(id: listingId)

        // Refresh list to get updated photo counts
        await loadListings(forceRefresh: true)

        logger.info("Uploaded \(uploadedCount) photos, \(failedCount) failed")

        isUploadingPhotos = false
        uploadingPhotoIndex = 0
        uploadingPhotoTotal = 0
        return failedCount == 0
    }

    func deletePhoto(listingId: Int, photoId: Int) async -> Bool {
        errorMessage = nil

        do {
            try await ExclusiveListingService.shared.deletePhoto(listingId: listingId, photoId: photoId)

            // Reload listing to get updated photos
            await loadListing(id: listingId)

            logger.info("Deleted photo: \(photoId)")
            return true
        } catch {
            errorMessage = error.localizedDescription
            logger.error("Failed to delete photo: \(error.localizedDescription)")
            return false
        }
    }

    func reorderPhotos(listingId: Int, photoIds: [Int]) async -> Bool {
        errorMessage = nil

        do {
            let newPhotos = try await ExclusiveListingService.shared.reorderPhotos(
                listingId: listingId,
                photoIds: photoIds
            )

            // Update the selected listing's photos with new order
            if var listing = selectedListing, listing.id == listingId {
                listing.photos = newPhotos
                selectedListing = listing
            }

            logger.info("Reordered \(photoIds.count) photos")
            return true
        } catch {
            errorMessage = error.localizedDescription
            logger.error("Failed to reorder photos: \(error.localizedDescription)")
            return false
        }
    }

    // MARK: - Filter

    func setStatusFilter(_ status: String?) {
        statusFilter = status
        Task {
            await loadListings(forceRefresh: true)
        }
    }

    // MARK: - Form Helpers

    func prepareNewListing() {
        editingRequest = ExclusiveListingRequest()
        errorMessage = nil
        successMessage = nil
    }

    func prepareEditListing(_ listing: ExclusiveListing) {
        // Helper to parse comma-separated string to array
        func parseCommaSeparated(_ value: String?) -> [String] {
            guard let value = value, !value.isEmpty else { return [] }
            return value.components(separatedBy: ",").map { $0.trimmingCharacters(in: .whitespaces) }
        }

        editingRequest = ExclusiveListingRequest(
            // Required fields
            streetNumber: listing.streetNumber ?? "",
            streetName: listing.streetName ?? "",
            city: listing.city,
            stateOrProvince: listing.stateOrProvince,
            postalCode: listing.postalCode ?? "",
            listPrice: listing.listPrice,
            propertyType: listing.propertyType,

            // Basic optional fields
            unitNumber: listing.unitNumber,
            propertySubType: listing.propertySubType,
            standardStatus: listing.standardStatus,
            // v1.5.0: Load exclusiveTag to prevent overwrite when editing
            exclusiveTag: listing.exclusiveTag ?? "Exclusive",
            bedroomsTotal: listing.bedroomsTotal,
            bathroomsTotal: listing.bathroomsTotal,
            bathroomsFull: listing.bathroomsFull,
            bathroomsHalf: listing.bathroomsHalf,
            buildingAreaTotal: listing.buildingAreaTotal,
            lotSizeAcres: listing.lotSizeAcres,
            lotSizeSquareFeet: listing.lotSizeSquareFeet,
            yearBuilt: listing.yearBuilt,
            garageSpaces: listing.garageSpaces,
            hasPool: listing.hasPool,
            hasFireplace: listing.hasFireplace,
            hasBasement: listing.hasBasement,
            hasHoa: listing.hasHoa,
            latitude: listing.latitude,
            longitude: listing.longitude,
            listingContractDate: nil,  // Date parsing would require extra logic

            // v1.4.0 - Tier 1 Property Description
            originalListPrice: listing.originalListPrice,
            architecturalStyle: listing.architecturalStyle,
            storiesTotal: listing.storiesTotal,
            virtualTourUrl: listing.virtualTourUrl,
            publicRemarks: listing.publicRemarks,
            privateRemarks: listing.privateRemarks,
            showingInstructions: listing.showingInstructions,

            // v1.4.0 - Tier 2 Interior Details
            heating: parseCommaSeparated(listing.heating),
            cooling: parseCommaSeparated(listing.cooling),
            interiorFeatures: parseCommaSeparated(listing.interiorFeatures),
            appliances: parseCommaSeparated(listing.appliances),
            flooring: parseCommaSeparated(listing.flooring),
            laundryFeatures: parseCommaSeparated(listing.laundryFeatures),
            basement: listing.basement,

            // v1.4.0 - Tier 3 Exterior & Lot
            constructionMaterials: parseCommaSeparated(listing.constructionMaterials),
            roof: listing.roof,
            foundationDetails: listing.foundationDetails,
            exteriorFeatures: parseCommaSeparated(listing.exteriorFeatures),
            waterfrontYn: listing.waterfrontYn ?? false,
            waterfrontFeatures: parseCommaSeparated(listing.waterfrontFeatures),
            viewYn: listing.viewYn ?? false,
            view: parseCommaSeparated(listing.view),
            parkingFeatures: parseCommaSeparated(listing.parkingFeatures),
            parkingTotal: listing.parkingTotal,

            // v1.4.0 - Tier 4 Financial
            taxAnnualAmount: listing.taxAnnualAmount,
            taxYear: listing.taxYear,
            associationYn: listing.associationYn ?? false,
            associationFee: listing.associationFee,
            associationFeeFrequency: listing.associationFeeFrequency,
            associationFeeIncludes: parseCommaSeparated(listing.associationFeeIncludes)
        )
        errorMessage = nil
        successMessage = nil
    }

    // MARK: - Computed Properties

    var hasListings: Bool {
        !listings.isEmpty
    }

    var hasMorePages: Bool {
        currentPage < totalPages
    }

    var activeListings: [ExclusiveListing] {
        listings.filter { $0.standardStatus == "Active" }
    }

    var pendingListings: [ExclusiveListing] {
        listings.filter { $0.standardStatus == "Pending" || $0.standardStatus == "Active Under Contract" }
    }

    var closedListings: [ExclusiveListing] {
        listings.filter { $0.standardStatus == "Closed" }
    }

    // MARK: - Draft Management

    /// Check if there's a saved draft
    var hasDraft: Bool {
        UserDefaults.standard.data(forKey: draftKey) != nil
    }

    /// Schedule a debounced draft save
    func scheduleDraftSave() {
        draftSaveTask?.cancel()
        draftSaveTask = Task {
            try? await Task.sleep(nanoseconds: UInt64(draftDebounceInterval * 1_000_000_000))
            guard !Task.isCancelled else { return }
            saveDraft()
        }
    }

    /// Save current form state as draft
    func saveDraft() {
        let draft = DraftExclusiveListing(
            streetNumber: editingRequest.streetNumber,
            streetName: editingRequest.streetName,
            city: editingRequest.city,
            stateOrProvince: editingRequest.stateOrProvince,
            postalCode: editingRequest.postalCode,
            listPrice: editingRequest.listPrice,
            propertyType: editingRequest.propertyType,
            unitNumber: editingRequest.unitNumber,
            propertySubType: editingRequest.propertySubType,
            standardStatus: editingRequest.standardStatus,
            bedroomsTotal: editingRequest.bedroomsTotal,
            bathroomsTotal: editingRequest.bathroomsTotal,
            bathroomsFull: editingRequest.bathroomsFull,
            bathroomsHalf: editingRequest.bathroomsHalf,
            buildingAreaTotal: editingRequest.buildingAreaTotal,
            lotSizeAcres: editingRequest.lotSizeAcres,
            yearBuilt: editingRequest.yearBuilt,
            garageSpaces: editingRequest.garageSpaces,
            hasPool: editingRequest.hasPool,
            hasFireplace: editingRequest.hasFireplace,
            hasBasement: editingRequest.hasBasement,
            hasHoa: editingRequest.hasHoa,
            publicRemarks: editingRequest.publicRemarks,
            listingContractDate: editingRequest.listingContractDate,
            savedAt: Date()
        )

        if let encoded = try? JSONEncoder().encode(draft) {
            UserDefaults.standard.set(encoded, forKey: draftKey)
            logger.debug("Draft saved")
        }
    }

    /// Load draft into editing request
    /// - Returns: True if draft was loaded successfully
    func loadDraft() -> Bool {
        guard let data = UserDefaults.standard.data(forKey: draftKey),
              let draft = try? JSONDecoder().decode(DraftExclusiveListing.self, from: data) else {
            return false
        }

        editingRequest = ExclusiveListingRequest(
            streetNumber: draft.streetNumber,
            streetName: draft.streetName,
            city: draft.city,
            stateOrProvince: draft.stateOrProvince,
            postalCode: draft.postalCode,
            listPrice: draft.listPrice,
            propertyType: draft.propertyType,
            unitNumber: draft.unitNumber,
            propertySubType: draft.propertySubType,
            standardStatus: draft.standardStatus,
            bedroomsTotal: draft.bedroomsTotal,
            bathroomsTotal: draft.bathroomsTotal,
            bathroomsFull: draft.bathroomsFull,
            bathroomsHalf: draft.bathroomsHalf,
            buildingAreaTotal: draft.buildingAreaTotal,
            lotSizeAcres: draft.lotSizeAcres,
            yearBuilt: draft.yearBuilt,
            garageSpaces: draft.garageSpaces,
            hasPool: draft.hasPool,
            hasFireplace: draft.hasFireplace,
            hasBasement: draft.hasBasement,
            hasHoa: draft.hasHoa,
            listingContractDate: draft.listingContractDate,
            publicRemarks: draft.publicRemarks
        )

        logger.info("Draft loaded from \(draft.savedAt)")
        return true
    }

    /// Clear the saved draft
    func clearDraft() {
        draftSaveTask?.cancel()
        UserDefaults.standard.removeObject(forKey: draftKey)
        logger.debug("Draft cleared")
    }

    /// Get the saved draft date for display
    var draftSavedDate: Date? {
        guard let data = UserDefaults.standard.data(forKey: draftKey),
              let draft = try? JSONDecoder().decode(DraftExclusiveListing.self, from: data) else {
            return nil
        }
        return draft.savedAt
    }
}

// MARK: - Draft Model

private struct DraftExclusiveListing: Codable {
    let streetNumber: String
    let streetName: String
    let city: String
    let stateOrProvince: String
    let postalCode: String
    let listPrice: Double
    let propertyType: String
    let unitNumber: String?
    let propertySubType: String?
    let standardStatus: String
    let bedroomsTotal: Int?
    let bathroomsTotal: Double?
    let bathroomsFull: Int?
    let bathroomsHalf: Int?
    let buildingAreaTotal: Int?
    let lotSizeAcres: Double?
    let yearBuilt: Int?
    let garageSpaces: Int?
    let hasPool: Bool
    let hasFireplace: Bool
    let hasBasement: Bool
    let hasHoa: Bool
    let publicRemarks: String?
    let listingContractDate: Date?
    let savedAt: Date
}
