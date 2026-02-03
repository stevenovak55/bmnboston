//
//  ExclusiveListingService.swift
//  BMNBoston
//
//  Actor-based service for Exclusive Listings API operations
//
//  Created for BMN Boston Real Estate
//

import Foundation
import UIKit
import os.log

// MARK: - Service Errors

enum ExclusiveListingError: Error, LocalizedError {
    case notAuthenticated
    case notAuthorized
    case listingNotFound
    case validationFailed([String])
    case uploadFailed(String)
    case networkError(Error)

    var errorDescription: String? {
        switch self {
        case .notAuthenticated:
            return "You must be logged in to manage exclusive listings"
        case .notAuthorized:
            return "You don't have permission to manage exclusive listings"
        case .listingNotFound:
            return "Listing not found"
        case .validationFailed(let errors):
            return "Validation failed: \(errors.joined(separator: ", "))"
        case .uploadFailed(let message):
            return "Upload failed: \(message)"
        case .networkError(let error):
            return "Network error: \(error.localizedDescription)"
        }
    }
}

// MARK: - ExclusiveListingService

actor ExclusiveListingService {
    static let shared = ExclusiveListingService()

    private let logger = Logger(subsystem: "com.bmnboston.app", category: "ExclusiveListingService")

    // Cache
    private var cachedListings: [ExclusiveListing] = []
    private var cachedOptions: ExclusiveListingOptionsResponse?
    private var lastListingsSync: Date?
    private var lastOptionsSync: Date?

    private let listingsCacheExpiration: TimeInterval = 60 // 1 minute
    private let optionsCacheExpiration: TimeInterval = 3600 // 1 hour

    private init() {}

    // MARK: - Fetch Listings

    /// Fetch exclusive listings with optional status filter
    func fetchListings(
        page: Int = 1,
        perPage: Int = 20,
        status: String? = nil,
        forceRefresh: Bool = false
    ) async throws -> ExclusiveListingListResponse {
        // For first page, check cache
        if page == 1 && !forceRefresh,
           let lastSync = lastListingsSync,
           Date().timeIntervalSince(lastSync) < listingsCacheExpiration,
           !cachedListings.isEmpty {
            logger.debug("Returning cached listings")
            return ExclusiveListingListResponse(
                items: cachedListings,
                total: cachedListings.count,
                page: 1,
                perPage: perPage,
                totalPages: 1
            )
        }

        logger.info("Fetching exclusive listings: page=\(page), status=\(status ?? "all")")

        do {
            let response: ExclusiveListingListResponse = try await APIClient.shared.request(
                .exclusiveListings(page: page, perPage: perPage, status: status)
            )

            // Cache first page results
            if page == 1 {
                cachedListings = response.items
                lastListingsSync = Date()
            }

            return response
        } catch let error as APIError {
            logger.error("API error fetching listings: \(error.technicalDescription)")
            if case .decodingError(let decodingError) = error {
                logger.error("Decoding error details: \(decodingError)")
            }
            throw mapAPIError(error)
        } catch {
            logger.error("Network error fetching listings: \(error.localizedDescription)")
            throw ExclusiveListingError.networkError(error)
        }
    }

    // MARK: - Fetch Single Listing

    /// Fetch a single exclusive listing with photos
    func fetchListing(id: Int) async throws -> ExclusiveListing {
        logger.info("Fetching exclusive listing: id=\(id)")

        do {
            let response: ExclusiveListingSingleResponse = try await APIClient.shared.request(
                .exclusiveListingDetail(id: id)
            )
            return response.listing
        } catch let error as APIError {
            logger.error("API error fetching listing: \(error.localizedDescription)")
            throw mapAPIError(error)
        } catch {
            logger.error("Network error fetching listing: \(error.localizedDescription)")
            throw ExclusiveListingError.networkError(error)
        }
    }

    // MARK: - Create Listing

    /// Create a new exclusive listing
    func createListing(request: ExclusiveListingRequest) async throws -> ExclusiveListing {
        guard request.isValid else {
            throw ExclusiveListingError.validationFailed(["Missing required fields"])
        }

        logger.info("Creating exclusive listing at \(request.streetNumber) \(request.streetName)")

        do {
            let response: ExclusiveListingCreateResponse = try await APIClient.shared.request(
                .createExclusiveListing(data: request.toDictionary())
            )

            // Invalidate cache
            cachedListings.insert(response.listing, at: 0)
            lastListingsSync = nil

            return response.listing
        } catch let error as APIError {
            logger.error("API error creating listing: \(error.localizedDescription)")
            throw mapAPIError(error)
        } catch {
            logger.error("Network error creating listing: \(error.localizedDescription)")
            throw ExclusiveListingError.networkError(error)
        }
    }

    // MARK: - Update Listing

    /// Update an existing exclusive listing
    func updateListing(id: Int, request: ExclusiveListingRequest) async throws -> ExclusiveListing {
        let requestData = request.toDictionary()
        logger.info("Updating exclusive listing: id=\(id)")

        // Debug: Log the data being sent
        #if DEBUG
        print("[EL Update] Updating listing ID: \(id)")
        if let priceValue = requestData["list_price"] {
            print("[EL Update] Sending list_price: \(priceValue)")
        }
        if let remarksValue = requestData["public_remarks"] {
            print("[EL Update] Sending public_remarks: \(remarksValue)")
        }
        print("[EL Update] Total fields being sent: \(requestData.count)")

        // Log all field keys
        print("[EL Update] Fields: \(requestData.keys.sorted().joined(separator: ", "))")

        // Verify JSON serialization works
        do {
            let jsonData = try JSONSerialization.data(withJSONObject: requestData)
            print("[EL Update] JSON size: \(jsonData.count) bytes")
        } catch {
            print("[EL Update] ERROR: Failed to serialize request data: \(error)")
        }
        #endif

        do {
            let response: ExclusiveListingUpdateResponse = try await APIClient.shared.request(
                .updateExclusiveListing(id: id, data: requestData)
            )

            // Debug: Log what the server returned
            #if DEBUG
            print("[EL Update] SUCCESS - Server returned listing with list_price: \(response.listing.listPrice)")
            if let remarks = response.listing.publicRemarks {
                print("[EL Update] Server returned public_remarks: \(remarks)")
            }
            #endif

            // Update cache
            if let index = cachedListings.firstIndex(where: { $0.id == id }) {
                cachedListings[index] = response.listing
            }

            return response.listing
        } catch let error as APIError {
            #if DEBUG
            print("[EL Update] API ERROR: \(error)")
            print("[EL Update] API ERROR description: \(error.localizedDescription)")
            #endif
            logger.error("API error updating listing: \(error.localizedDescription)")
            throw mapAPIError(error)
        } catch {
            #if DEBUG
            print("[EL Update] NETWORK ERROR type: \(type(of: error))")
            print("[EL Update] NETWORK ERROR: \(error)")
            print("[EL Update] NETWORK ERROR localized: \(error.localizedDescription)")
            #endif
            logger.error("Network error updating listing: \(error.localizedDescription)")
            throw ExclusiveListingError.networkError(error)
        }
    }

    // MARK: - Delete Listing

    /// Delete (archive) an exclusive listing
    func deleteListing(id: Int, archive: Bool = true) async throws {
        logger.info("Deleting exclusive listing: id=\(id), archive=\(archive)")

        do {
            let _: ExclusiveListingDeleteResponse = try await APIClient.shared.request(
                .deleteExclusiveListing(id: id, archive: archive)
            )

            // Remove from cache
            cachedListings.removeAll { $0.id == id }
        } catch let error as APIError {
            logger.error("API error deleting listing: \(error.localizedDescription)")
            throw mapAPIError(error)
        } catch {
            logger.error("Network error deleting listing: \(error.localizedDescription)")
            throw ExclusiveListingError.networkError(error)
        }
    }

    // MARK: - Photo Operations

    /// Upload photos to a listing
    func uploadPhotos(listingId: Int, images: [UIImage]) async throws -> ExclusiveListingPhotoUploadResponse {
        logger.info("Uploading \(images.count) photos to listing: id=\(listingId)")

        // Create multipart form data
        let boundary = UUID().uuidString
        var body = Data()

        for (index, image) in images.enumerated() {
            guard let imageData = image.jpegData(compressionQuality: 0.8) else {
                continue
            }

            let filename = "photo_\(index + 1).jpg"

            body.append("--\(boundary)\r\n".data(using: .utf8)!)
            body.append("Content-Disposition: form-data; name=\"photos[]\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
            body.append("Content-Type: image/jpeg\r\n\r\n".data(using: .utf8)!)
            body.append(imageData)
            body.append("\r\n".data(using: .utf8)!)
        }

        body.append("--\(boundary)--\r\n".data(using: .utf8)!)

        // Build request
        let baseURL = "https://bmnboston.com/wp-json/mld-mobile/v1"
        guard let url = URL(string: "\(baseURL)/exclusive-listings/\(listingId)/photos") else {
            throw ExclusiveListingError.uploadFailed("Invalid URL")
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")

        // Add auth header
        if let token = try? await TokenManager.shared.getAccessToken() {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        request.httpBody = body

        do {
            let (data, response) = try await URLSession.shared.data(for: request)

            // Debug: Log raw response
            if let responseString = String(data: data, encoding: .utf8) {
                logger.info("Upload response: \(responseString)")
                print("[PhotoUpload] Raw response: \(responseString)")
            }

            guard let httpResponse = response as? HTTPURLResponse else {
                throw ExclusiveListingError.uploadFailed("Invalid response")
            }

            print("[PhotoUpload] Status code: \(httpResponse.statusCode)")

            if httpResponse.statusCode == 401 {
                throw ExclusiveListingError.notAuthenticated
            }

            if httpResponse.statusCode == 403 {
                throw ExclusiveListingError.notAuthorized
            }

            // Parse response
            let decoder = JSONDecoder()
            let apiResponse = try decoder.decode(PhotoUploadAPIResponse<ExclusiveListingPhotoUploadResponse>.self, from: data)

            guard apiResponse.success, let uploadResponse = apiResponse.data else {
                throw ExclusiveListingError.uploadFailed(apiResponse.message ?? "Upload failed")
            }

            // Invalidate listing cache to get updated photo URLs
            lastListingsSync = nil

            return uploadResponse
        } catch let error as ExclusiveListingError {
            throw error
        } catch {
            logger.error("Upload error: \(error.localizedDescription)")
            throw ExclusiveListingError.uploadFailed(error.localizedDescription)
        }
    }

    /// Upload a single photo to a listing (for progress tracking)
    func uploadSinglePhoto(listingId: Int, image: UIImage, photoIndex: Int) async throws -> ExclusiveListingPhotoUploadResponse {
        logger.info("Uploading photo \(photoIndex) to listing: id=\(listingId)")

        // Create multipart form data
        let boundary = UUID().uuidString
        var body = Data()

        guard let imageData = image.jpegData(compressionQuality: 0.8) else {
            throw ExclusiveListingError.uploadFailed("Failed to convert image to JPEG")
        }

        let filename = "photo_\(photoIndex).jpg"

        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"photos[]\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: image/jpeg\r\n\r\n".data(using: .utf8)!)
        body.append(imageData)
        body.append("\r\n".data(using: .utf8)!)
        body.append("--\(boundary)--\r\n".data(using: .utf8)!)

        // Build request
        let baseURL = "https://bmnboston.com/wp-json/mld-mobile/v1"
        guard let url = URL(string: "\(baseURL)/exclusive-listings/\(listingId)/photos") else {
            throw ExclusiveListingError.uploadFailed("Invalid URL")
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")

        // Add auth header
        if let token = try? await TokenManager.shared.getAccessToken() {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        request.httpBody = body

        do {
            let (data, response) = try await URLSession.shared.data(for: request)

            guard let httpResponse = response as? HTTPURLResponse else {
                throw ExclusiveListingError.uploadFailed("Invalid response")
            }

            if httpResponse.statusCode == 401 {
                throw ExclusiveListingError.notAuthenticated
            }

            if httpResponse.statusCode == 403 {
                throw ExclusiveListingError.notAuthorized
            }

            // Parse response
            let decoder = JSONDecoder()
            let apiResponse = try decoder.decode(PhotoUploadAPIResponse<ExclusiveListingPhotoUploadResponse>.self, from: data)

            guard apiResponse.success, let uploadResponse = apiResponse.data else {
                throw ExclusiveListingError.uploadFailed(apiResponse.message ?? "Upload failed")
            }

            // Invalidate listing cache to get updated photo URLs
            lastListingsSync = nil

            return uploadResponse
        } catch let error as ExclusiveListingError {
            throw error
        } catch {
            logger.error("Upload error: \(error.localizedDescription)")
            throw ExclusiveListingError.uploadFailed(error.localizedDescription)
        }
    }

    /// Delete a photo from a listing
    func deletePhoto(listingId: Int, photoId: Int) async throws {
        logger.info("Deleting photo: listingId=\(listingId), photoId=\(photoId)")

        do {
            try await APIClient.shared.requestWithoutResponse(
                .deleteExclusiveListingPhoto(listingId: listingId, photoId: photoId)
            )

            // Invalidate listing cache
            lastListingsSync = nil
        } catch let error as APIError {
            logger.error("API error deleting photo: \(error.localizedDescription)")
            throw mapAPIError(error)
        } catch {
            logger.error("Network error deleting photo: \(error.localizedDescription)")
            throw ExclusiveListingError.networkError(error)
        }
    }

    /// Reorder photos
    func reorderPhotos(listingId: Int, photoIds: [Int]) async throws -> [ExclusiveListingPhoto] {
        logger.info("Reordering photos for listing: id=\(listingId)")

        do {
            let response: ExclusiveListingPhotosResponse = try await APIClient.shared.request(
                .reorderExclusiveListingPhotos(listingId: listingId, order: photoIds)
            )
            return response.photos
        } catch let error as APIError {
            logger.error("API error reordering photos: \(error.localizedDescription)")
            throw mapAPIError(error)
        } catch {
            logger.error("Network error reordering photos: \(error.localizedDescription)")
            throw ExclusiveListingError.networkError(error)
        }
    }

    // MARK: - Options

    /// Fetch valid options (property types, statuses, etc.)
    func fetchOptions(forceRefresh: Bool = false) async throws -> ExclusiveListingOptionsResponse {
        if !forceRefresh,
           let cached = cachedOptions,
           let lastSync = lastOptionsSync,
           Date().timeIntervalSince(lastSync) < optionsCacheExpiration {
            return cached
        }

        logger.info("Fetching exclusive listing options")

        do {
            let options: ExclusiveListingOptionsResponse = try await APIClient.shared.request(
                .exclusiveListingOptions
            )

            cachedOptions = options
            lastOptionsSync = Date()

            return options
        } catch let error as APIError {
            logger.error("API error fetching options: \(error.localizedDescription)")
            throw mapAPIError(error)
        } catch {
            logger.error("Network error fetching options: \(error.localizedDescription)")
            throw ExclusiveListingError.networkError(error)
        }
    }

    // MARK: - Cache Management

    func clearCache() {
        cachedListings = []
        cachedOptions = nil
        lastListingsSync = nil
        lastOptionsSync = nil
        logger.debug("Cache cleared")
    }

    // MARK: - Helpers

    private func mapAPIError(_ error: APIError) -> ExclusiveListingError {
        switch error {
        case .unauthorized:
            return .notAuthenticated
        case .forbidden:
            return .notAuthorized
        case .notFound:
            return .listingNotFound
        case .serverError(_, let message):
            if message.contains("validation") {
                return .validationFailed([message])
            }
            return .networkError(error)
        default:
            return .networkError(error)
        }
    }
}

// MARK: - Photo Upload Response Helper

private struct PhotoUploadAPIResponse<T: Decodable>: Decodable {
    let success: Bool
    let data: T?
    let message: String?
}
