//
//  AppConstants.swift
//  BMNBoston
//
//  Application-wide constants and configuration
//

import Foundation

enum AppConstants {
    // MARK: - Company Info

    static let companyName = "BMN Boston"
    static let websiteBaseURL = "https://bmnboston.com"

    // MARK: - Team Contact Info (Fallback when no assigned agent)

    /// Team name displayed when user has no assigned agent
    static let teamName = "BMN Boston Team"

    /// Team email for general inquiries
    static let teamEmail = "info@bmnboston.com"

    /// Team phone number
    static let teamPhone = "+16179101010"

    /// Team photo URL (optional)
    static let teamPhotoUrl: String? = nil

    // MARK: - Legacy Fallback (kept for compatibility)

    /// Default agent name when listing agent info is unavailable
    static let fallbackAgentName = "Steven Novak"

    /// Default agent email when listing agent info is unavailable
    static let fallbackAgentEmail = "steven@bmnboston.com"

    /// Default agent phone when listing agent info is unavailable
    static let fallbackAgentPhone = "+16175551234"

    // MARK: - URLs

    /// Generate property detail URL for web view
    static func propertyURL(id: String) -> URL? {
        URL(string: "\(websiteBaseURL)/property/\(id)")
    }
}
