//
//  Environment.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation

enum AppEnvironment {
    case development
    case staging
    case production

    static var current: AppEnvironment {
        #if DEBUG
        // Temporarily using production for live data testing
        return .production
        #else
        return .production
        #endif
    }

    var baseURL: String {
        switch self {
        case .development:
            return "http://localhost:8080/wp-json"
        case .staging:
            return "https://staging.bmnboston.com/wp-json"
        case .production:
            return "https://bmnboston.com/wp-json"
        }
    }

    var apiNamespace: String {
        return "mld-mobile/v1"
    }

    /// MLD namespace for web analytics endpoints (v6.73.0)
    var mldNamespace: String {
        return "mld/v1"
    }

    var fullAPIURL: String {
        return "\(baseURL)/\(apiNamespace)"
    }

    /// Full URL for MLD namespace endpoints (city analytics, etc.)
    var mldAPIURL: String {
        return "\(baseURL)/\(mldNamespace)"
    }
}
