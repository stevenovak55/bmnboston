//
//  APIClient.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation
import os.log

/// APIClient handles all REST API communication with the BMNBoston server.
///
/// ## Security Considerations
///
/// ### Certificate Pinning (Not Implemented)
/// Certificate pinning would prevent MITM attacks on public WiFi by validating
/// the server's certificate against a known public key. However, it's not implemented because:
/// 1. The app displays mostly public real estate data
/// 2. No financial transactions occur
/// 3. Certificate rotation would require app updates
/// 4. HTTPS already provides encryption
///
/// If needed in the future, implement `URLSessionDelegate.urlSession(_:didReceive:completionHandler:)`
/// and pin to the server's public key hash (more stable than the certificate itself).
actor APIClient {
    static let shared = APIClient()

    private let session: URLSession
    private let decoder: JSONDecoder
    private let encoder: JSONEncoder
    private let logger = Logger(subsystem: "com.bmnboston.app", category: "APIClient")

    // Token refresh mutex - prevents race condition when multiple 401s trigger concurrent refreshes
    private var isRefreshing = false
    // Track continuations with unique IDs for proper cancellation cleanup
    private var refreshContinuations: [(id: UUID, continuation: CheckedContinuation<Void, Error>)] = []

    private init() {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 30
        config.timeoutIntervalForResource = 60
        // v388: Wait for connectivity instead of failing immediately
        config.waitsForConnectivity = true
        self.session = URLSession(configuration: config)

        self.decoder = JSONDecoder()
        // Don't use .convertFromSnakeCase - models have explicit CodingKeys
        // Use custom date decoder to handle PHP's ISO8601 format with timezone offset
        decoder.dateDecodingStrategy = .custom { decoder in
            let container = try decoder.singleValueContainer()
            let dateString = try container.decode(String.self)

            // Try ISO8601 with fractional seconds and timezone offset
            let isoFormatter = ISO8601DateFormatter()
            isoFormatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
            if let date = isoFormatter.date(from: dateString) {
                return date
            }

            // Try ISO8601 with timezone offset (PHP format: 2025-12-24T12:30:00+00:00)
            isoFormatter.formatOptions = [.withInternetDateTime]
            if let date = isoFormatter.date(from: dateString) {
                return date
            }

            // Try MySQL datetime format (2025-12-24 12:30:00)
            let mysqlFormatter = DateFormatter()
            mysqlFormatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
            mysqlFormatter.timeZone = TimeZone(identifier: "UTC")
            if let date = mysqlFormatter.date(from: dateString) {
                return date
            }

            throw DecodingError.dataCorruptedError(
                in: container,
                debugDescription: "Cannot decode date: \(dateString)"
            )
        }

        self.encoder = JSONEncoder()
        encoder.keyEncodingStrategy = .convertToSnakeCase
    }

    // MARK: - Constants

    /// Maximum number of token refresh retries to prevent infinite loops
    private static let maxTokenRefreshRetries = 2

    // MARK: - Public Methods

    func request<T: Decodable>(_ endpoint: APIEndpoint) async throws -> T {
        return try await requestWithRetry(endpoint, retryCount: 0)
    }

    /// Internal request with retry count to prevent infinite refresh loops
    private func requestWithRetry<T: Decodable>(_ endpoint: APIEndpoint, retryCount: Int) async throws -> T {
        let urlRequest = try await buildRequest(for: endpoint)

        logger.debug("[\(endpoint.method.rawValue)] \(endpoint.path)")
        if let url = urlRequest.url?.absoluteString {
            // Always log full URL for debugging
            logger.debug("Full URL: \(url)")
        }

        #if DEBUG
        // Log request body for debugging exclusive listing updates
        if endpoint.path.contains("exclusive-listings"), let body = urlRequest.httpBody {
            print("[APIClient] Request body size: \(body.count) bytes")
        }
        #endif

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: urlRequest)
        } catch {
            #if DEBUG
            print("[APIClient] Network request failed for \(endpoint.path)")
            print("[APIClient] Error type: \(type(of: error))")
            print("[APIClient] Error: \(error)")
            print("[APIClient] Error localized: \(error.localizedDescription)")
            if let urlError = error as? URLError {
                print("[APIClient] URLError code: \(urlError.code.rawValue) - \(urlError.code)")
            }
            #endif
            throw error
        }

        guard let httpResponse = response as? HTTPURLResponse else {
            throw APIError.invalidResponse
        }

        logger.debug("Response status: \(httpResponse.statusCode)")

        switch httpResponse.statusCode {
        case 200...299:
            #if DEBUG
            if endpoint.path.contains("exclusive-listings") {
                print("[APIClient] Response received for exclusive-listings, status: \(httpResponse.statusCode)")
                print("[APIClient] Response data size: \(data.count) bytes")
                if let rawString = String(data: data, encoding: .utf8) {
                    print("[APIClient] Response preview: \(rawString.prefix(300))")
                }
            }
            #endif
            do {
                let apiResponse = try decoder.decode(APIResponse<T>.self, from: data)
                if apiResponse.success {
                    guard let responseData = apiResponse.data else {
                        throw APIError.noData
                    }
                    return responseData
                } else if let error = apiResponse.error {
                    throw APIError.serverError(code: error.code, message: error.message)
                } else {
                    throw APIError.unknown
                }
            } catch let decodingError as DecodingError {
                // Log the raw response for debugging
                if let rawString = String(data: data, encoding: .utf8) {
                    logger.error("Raw API response: \(rawString.prefix(500))")
                    #if DEBUG
                    print("[APIClient] DECODING FAILED - Raw response: \(rawString)")
                    #endif
                }
                logger.error("Decoding error: \(decodingError.localizedDescription)")
                // Log more details about the decoding error
                switch decodingError {
                case .typeMismatch(let type, let context):
                    logger.error("Type mismatch: expected \(type), path: \(context.codingPath.map { $0.stringValue }.joined(separator: "."))")
                case .valueNotFound(let type, let context):
                    logger.error("Value not found: \(type), path: \(context.codingPath.map { $0.stringValue }.joined(separator: "."))")
                case .keyNotFound(let key, let context):
                    logger.error("Key not found: \(key.stringValue), path: \(context.codingPath.map { $0.stringValue }.joined(separator: "."))")
                case .dataCorrupted(let context):
                    logger.error("Data corrupted: \(context.debugDescription)")
                @unknown default:
                    break
                }
                throw APIError.decodingError(decodingError)
            }
        case 401:
            // Try to refresh token if we haven't exceeded retry limit
            if retryCount < Self.maxTokenRefreshRetries,
               await TokenManager.shared.hasRefreshToken() {
                logger.debug("Attempting token refresh (attempt \(retryCount + 1)/\(Self.maxTokenRefreshRetries))")
                try await refreshToken()
                return try await requestWithRetry(endpoint, retryCount: retryCount + 1)
            }
            // Clear tokens since they're invalid and can't be refreshed
            if retryCount >= Self.maxTokenRefreshRetries {
                logger.warning("Token refresh retry limit exceeded, clearing tokens")
                await TokenManager.shared.clearTokens()
            }
            throw APIError.unauthorized
        case 403:
            throw APIError.forbidden
        case 404:
            throw APIError.notFound
        case 429:
            throw APIError.rateLimited
        case 500...599:
            throw APIError.serverError(code: "server_error", message: "Internal server error")
        default:
            throw APIError.httpError(statusCode: httpResponse.statusCode)
        }
    }

    /// Request that decodes response directly without expecting the standard API wrapper.
    /// Use for endpoints that return raw data (like /mld/v1/ namespace endpoints).
    func requestRaw<T: Decodable>(_ endpoint: APIEndpoint) async throws -> T {
        let urlRequest = try await buildRequest(for: endpoint)

        logger.debug("[\(endpoint.method.rawValue)] \(endpoint.path) (raw)")

        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: urlRequest)
        } catch {
            #if DEBUG
            print("[APIClient] Network request failed for \(endpoint.path)")
            print("[APIClient] Error: \(error)")
            #endif
            throw error
        }

        guard let httpResponse = response as? HTTPURLResponse else {
            throw APIError.invalidResponse
        }

        logger.debug("Response status: \(httpResponse.statusCode)")

        switch httpResponse.statusCode {
        case 200...299:
            do {
                // Decode directly without wrapper
                return try decoder.decode(T.self, from: data)
            } catch let decodingError as DecodingError {
                if let rawString = String(data: data, encoding: .utf8) {
                    logger.error("Raw API response: \(rawString.prefix(500))")
                    #if DEBUG
                    print("[APIClient] DECODING FAILED (raw) - Response: \(rawString)")
                    #endif
                }
                logger.error("Decoding error: \(decodingError.localizedDescription)")
                throw APIError.decodingError(decodingError)
            }
        case 401:
            throw APIError.unauthorized
        case 403:
            throw APIError.forbidden
        case 404:
            throw APIError.notFound
        case 429:
            throw APIError.rateLimited
        case 500...599:
            throw APIError.serverError(code: "server_error", message: "Internal server error")
        default:
            throw APIError.httpError(statusCode: httpResponse.statusCode)
        }
    }

    func requestWithoutResponse(_ endpoint: APIEndpoint) async throws {
        let urlRequest = try await buildRequest(for: endpoint)

        logger.debug("[\(endpoint.method.rawValue)] \(endpoint.path)")

        let (data, response) = try await session.data(for: urlRequest)

        guard let httpResponse = response as? HTTPURLResponse else {
            throw APIError.invalidResponse
        }

        switch httpResponse.statusCode {
        case 200...299:
            // IMPORTANT: Properly decode response and handle errors - don't silently ignore
            do {
                let apiResponse = try decoder.decode(APIResponse<EmptyResponse>.self, from: data)
                if !apiResponse.success, let error = apiResponse.error {
                    logger.error("API returned error: [\(error.code)] \(error.message)")
                    throw APIError.serverError(code: error.code, message: error.message)
                }
            } catch let decodingError as DecodingError {
                // Log decoding failure with response data for debugging
                let responseString = String(data: data, encoding: .utf8) ?? "unable to decode response"
                logger.warning("Response decoding failed for \(endpoint.path): \(decodingError.localizedDescription)")
                logger.debug("Raw response: \(responseString.prefix(500))")
                // For backwards compatibility, don't fail on decode error if HTTP was success
                // But the warning in logs will help identify API format issues
            } catch {
                // Re-throw non-decoding errors
                throw error
            }
            return
        case 401:
            throw APIError.unauthorized
        case 403:
            throw APIError.forbidden
        case 404:
            throw APIError.notFound
        case 429:
            throw APIError.rateLimited
        default:
            throw APIError.httpError(statusCode: httpResponse.statusCode)
        }
    }

    // MARK: - Private Methods

    private func buildRequest(for endpoint: APIEndpoint) async throws -> URLRequest {
        // Determine base URL based on endpoint type:
        // - useBaseURL: uses baseURL for different namespaces (like bmn-schools)
        // - useMldNamespace: uses mldAPIURL for /mld/v1/ endpoints (city analytics, etc.)
        // - default: uses fullAPIURL for /mld-mobile/v1/ endpoints
        let baseURLString: String
        if endpoint.useBaseURL {
            baseURLString = AppEnvironment.current.baseURL
        } else if endpoint.useMldNamespace {
            baseURLString = AppEnvironment.current.mldAPIURL
        } else {
            baseURLString = AppEnvironment.current.fullAPIURL
        }
        guard var urlComponents = URLComponents(string: baseURLString + endpoint.path) else {
            throw APIError.invalidURL
        }

        // Add query parameters for GET requests
        if endpoint.method == .get, let parameters = endpoint.parameters {
            var queryItems: [URLQueryItem] = []
            for (key, value) in parameters {
                queryItems.append(contentsOf: encodeQueryParameter(key: key, value: value))
            }
            urlComponents.queryItems = queryItems
        }

        guard let url = urlComponents.url else {
            throw APIError.invalidURL
        }

        #if DEBUG
        // Log the actual URL being called (for debugging polygon search issues)
        if endpoint.path.contains("properties") && url.absoluteString.count < 2000 {
            debugLog("🌐 API URL: \(url.absoluteString)")
        }
        #endif

        var request = URLRequest(url: url)
        request.httpMethod = endpoint.method.rawValue
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        // Add authorization header if needed or available
        // For endpoints that require auth, throw error if no token
        // For optional auth endpoints (like booking), send token if available
        if let token = await TokenManager.shared.getAccessToken() {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        } else if endpoint.requiresAuth {
            throw APIError.unauthorized
        }

        // Add body for non-GET requests
        if endpoint.method != .get, let parameters = endpoint.parameters {
            request.httpBody = try JSONSerialization.data(withJSONObject: parameters)
        }

        return request
    }

    /// Recursively encodes query parameters, handling nested arrays and dictionaries
    /// For PHP compatibility: polygon[0][lat]=42.3&polygon[0][lng]=-71.1
    private func encodeQueryParameter(key: String, value: Any) -> [URLQueryItem] {
        var items: [URLQueryItem] = []

        if let dict = value as? [String: Any] {
            // Dictionary: encode as key[subkey]=value
            for (subKey, subValue) in dict {
                items.append(contentsOf: encodeQueryParameter(key: "\(key)[\(subKey)]", value: subValue))
            }
        } else if let array = value as? [[String: Any]] {
            // Array of dictionaries (like polygon): encode as key[index][subkey]=value
            for (index, dict) in array.enumerated() {
                for (subKey, subValue) in dict {
                    items.append(contentsOf: encodeQueryParameter(key: "\(key)[\(index)][\(subKey)]", value: subValue))
                }
            }
        } else if let array = value as? [Any] {
            // Simple array: encode as key[]=value for PHP
            for item in array {
                items.append(URLQueryItem(name: "\(key)[]", value: "\(item)"))
            }
        } else {
            // Scalar value
            items.append(URLQueryItem(name: key, value: "\(value)"))
        }

        return items
    }

    private func refreshToken() async throws {
        // MUTEX: If already refreshing, wait for that refresh to complete
        // This prevents race condition when multiple 401s trigger concurrent refreshes
        if isRefreshing {
            #if DEBUG
            debugLog("🔄 DEBUG APIClient.refreshToken(): Already refreshing, waiting for completion...")
            #endif
            // Use unique ID to track this continuation for cancellation cleanup
            let continuationId = UUID()
            return try await withTaskCancellationHandler {
                try await withCheckedThrowingContinuation { continuation in
                    refreshContinuations.append((id: continuationId, continuation: continuation))
                }
            } onCancel: {
                // Remove and resume continuation with cancellation error
                Task { @MainActor [weak self] in
                    guard let self = self else { return }
                    await self.cancelWaitingContinuation(id: continuationId)
                }
            }
        }

        isRefreshing = true
        // v321: Track completion status to handle both success and error cleanup
        var refreshError: Error? = nil

        // v321: Use defer to GUARANTEE cleanup happens even if unexpected errors occur
        // This fixes the race condition where isRefreshing could stay true permanently
        defer {
            let waiters = refreshContinuations
            refreshContinuations = []
            isRefreshing = false

            for (_, continuation) in waiters {
                if let error = refreshError {
                    continuation.resume(throwing: error)
                } else {
                    continuation.resume()
                }
            }
        }

        #if DEBUG
        debugLog("🔄 DEBUG APIClient.refreshToken(): Starting token refresh")
        #endif

        guard let refreshToken = await TokenManager.shared.getRefreshToken() else {
            #if DEBUG
            debugLog("🔄 DEBUG APIClient.refreshToken(): No refresh token found - unauthorized")
            #endif
            refreshError = APIError.unauthorized
            throw APIError.unauthorized
        }

        do {
            let endpoint = APIEndpoint.refreshToken(refreshToken: refreshToken)
            let urlRequest = try await buildRequest(for: endpoint)

            let (data, response) = try await session.data(for: urlRequest)

            guard let httpResponse = response as? HTTPURLResponse,
                  httpResponse.statusCode == 200 else {
                #if DEBUG
                debugLog("🔄 DEBUG APIClient.refreshToken(): Refresh failed - clearing tokens")
                #endif
                await TokenManager.shared.clearTokens()
                refreshError = APIError.unauthorized
                throw APIError.unauthorized
            }

            let authResponse = try decoder.decode(APIResponse<AuthResponseData>.self, from: data)
            if let authData = authResponse.data {
                #if DEBUG
                debugLog("🔄 DEBUG APIClient.refreshToken(): Server returned user: \(authData.user.email) (id: \(authData.user.id))")
                #endif

                await TokenManager.shared.saveTokens(
                    accessToken: authData.accessToken,
                    refreshToken: authData.refreshToken
                )
                // Also save the user to ensure consistency
                // This fixes potential mismatch if the initial User.save() failed
                authData.user.save()
                logger.debug("Token refreshed and user saved: \(authData.user.email)")
                // Success - refreshError stays nil, defer will resume waiters successfully
            } else {
                refreshError = APIError.unauthorized
                throw APIError.unauthorized
            }
        } catch {
            refreshError = error
            throw error
        }
    }

    /// Cancel a waiting continuation by its ID (called when task is cancelled)
    private func cancelWaitingContinuation(id: UUID) {
        if let index = refreshContinuations.firstIndex(where: { $0.id == id }) {
            let (_, continuation) = refreshContinuations.remove(at: index)
            continuation.resume(throwing: CancellationError())
        }
    }
}

// MARK: - Supporting Types

struct APIResponse<T: Decodable>: Decodable {
    let success: Bool
    let data: T?
    let error: APIErrorResponse?
}

struct APIErrorResponse: Decodable {
    let code: String
    let message: String
    let status: Int?
}

struct EmptyResponse: Decodable {}
