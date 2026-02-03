//
//  APIError.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation

enum APIError: LocalizedError {
    case invalidURL
    case invalidResponse
    case noData
    case unauthorized
    case forbidden
    case notFound
    case rateLimited
    case httpError(statusCode: Int)
    case serverError(code: String, message: String)
    case decodingError(DecodingError)
    case networkError(Error)
    case unknown

    /// User-friendly error message suitable for display
    var errorDescription: String? {
        switch self {
        case .invalidURL:
            return "Something went wrong. Please try again."
        case .invalidResponse:
            return "We're having trouble connecting. Please try again."
        case .noData:
            return "No information available. Please try again."
        case .unauthorized:
            return "Please log in to continue."
        case .forbidden:
            return "You don't have access to this feature."
        case .notFound:
            return "This information is no longer available."
        case .rateLimited:
            return "You've made too many requests. Please wait a moment and try again."
        case .httpError(let statusCode):
            if statusCode >= 500 {
                return "Our servers are having issues. Please try again in a few minutes."
            } else {
                return "Something went wrong. Please try again."
            }
        case .serverError(let code, let message):
            // Return user-friendly messages for known error codes
            switch code {
            case "invalid_credentials":
                return "Incorrect email or password. Please try again."
            case "user_exists":
                return "An account with this email already exists."
            case "token_expired", "token_invalid":
                return "Your session has expired. Please log in again."
            case "validation_error":
                return message  // Validation messages are usually user-friendly
            // CMA-specific error codes
            case "missing_listing_id":
                return "Property information missing. Please try again."
            case "listing_not_found":
                return "Property not found. Please try a different property."
            case "missing_coordinates":
                return "Property location data unavailable for CMA analysis."
            case "no_comparables":
                return "Not enough comparable sales in this area to generate a CMA."
            case "pdf_generation_failed", "pdf_exception", "pdf_class_missing", "missing_class":
                return "Unable to generate PDF report. Please try again later."
            default:
                // If the server message looks technical, replace it
                if message.contains("Exception") || message.contains("Error:") || message.count > 100 {
                    return "Something went wrong. Please try again."
                }
                return message
            }
        case .decodingError:
            return "We had trouble loading this information. Please try again."
        case .networkError:
            return "No internet connection. Please check your network and try again."
        case .unknown:
            return "Something went wrong. Please try again."
        }
    }

    /// Technical description for logging/debugging (not shown to users)
    var technicalDescription: String {
        switch self {
        case .invalidURL:
            return "Invalid URL"
        case .invalidResponse:
            return "Invalid response from server"
        case .noData:
            return "No data received"
        case .unauthorized:
            return "Unauthorized (401)"
        case .forbidden:
            return "Forbidden (403)"
        case .notFound:
            return "Not found (404)"
        case .rateLimited:
            return "Rate limited (429)"
        case .httpError(let statusCode):
            return "HTTP error: \(statusCode)"
        case .serverError(let code, let message):
            return "Server error [\(code)]: \(message)"
        case .decodingError(let error):
            return "Decoding error: \(error.localizedDescription)"
        case .networkError(let error):
            return "Network error: \(error.localizedDescription)"
        case .unknown:
            return "Unknown error"
        }
    }

    var isAuthenticationError: Bool {
        switch self {
        case .unauthorized:
            return true
        case .serverError(let code, _):
            return code == "token_expired" || code == "token_invalid" || code == "invalid_credentials"
        default:
            return false
        }
    }
}

// MARK: - Error Extension for User-Friendly Messages

extension Error {
    /// Returns a user-friendly error message suitable for display.
    /// For APIError, uses the customized errorDescription.
    /// For other errors, provides a generic friendly message.
    var userFriendlyMessage: String {
        if let apiError = self as? APIError {
            return apiError.errorDescription ?? "Something went wrong. Please try again."
        }

        // Check for common network errors
        let nsError = self as NSError
        switch nsError.code {
        case NSURLErrorNotConnectedToInternet, NSURLErrorNetworkConnectionLost:
            return "No internet connection. Please check your network and try again."
        case NSURLErrorTimedOut:
            return "The request timed out. Please try again."
        case NSURLErrorCannotFindHost, NSURLErrorCannotConnectToHost:
            return "Unable to connect to the server. Please try again later."
        case NSURLErrorCancelled:
            return "Request was cancelled."
        default:
            return "Something went wrong. Please try again."
        }
    }
}
