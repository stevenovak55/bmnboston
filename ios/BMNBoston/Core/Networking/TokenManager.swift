//
//  TokenManager.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation
import os.log

actor TokenManager {
    static let shared = TokenManager()

    private let logger = Logger(subsystem: "com.bmnboston.app", category: "TokenManager")

    private var accessToken: String?
    private var refreshToken: String?
    private var tokenExpirationDate: Date?
    // v321: Track refresh token expiration separately to prevent using stale refresh tokens
    private var refreshTokenExpirationDate: Date?

    // Using UserDefaults with new keys for debugging
    private let accessTokenKey = "com.bmnboston.accessTokenV2"
    private let refreshTokenKey = "com.bmnboston.refreshTokenV2"
    private let expirationKey = "com.bmnboston.tokenExpirationV2"
    private let refreshExpirationKey = "com.bmnboston.refreshTokenExpirationV2"

    private init() {
        // Load tokens synchronously from UserDefaults
        loadTokensFromStorage()
    }

    // MARK: - Public Methods

    /// Save tokens with separate expiration tracking for access and refresh tokens
    /// - Parameters:
    ///   - accessToken: The JWT access token
    ///   - refreshToken: The refresh token for obtaining new access tokens
    ///   - expiresIn: Access token expiration in seconds (default 900s for backwards compatibility)
    ///   - refreshExpiresIn: Refresh token expiration in seconds (default 30 days = 2592000s per server config v6.50.8)
    func saveTokens(accessToken: String, refreshToken: String, expiresIn: Int = 900, refreshExpiresIn: Int = 2592000) {
        #if DEBUG
        debugLog("🔑 DEBUG TokenManager.saveTokens(): Saving new tokens (access expires in \(expiresIn)s, refresh expires in \(refreshExpiresIn)s)")
        #endif

        self.accessToken = accessToken
        self.refreshToken = refreshToken
        self.tokenExpirationDate = Date().addingTimeInterval(TimeInterval(expiresIn))
        // v321: Track refresh token expiration (30 days per server config)
        self.refreshTokenExpirationDate = Date().addingTimeInterval(TimeInterval(refreshExpiresIn))

        // Save to KEYCHAIN (not UserDefaults - UserDefaults syncs to iCloud which is insecure)
        KeychainManager.shared.save(accessToken, forKey: accessTokenKey)
        KeychainManager.shared.save(refreshToken, forKey: refreshTokenKey)
        if let expiration = tokenExpirationDate {
            KeychainManager.shared.save(String(expiration.timeIntervalSince1970), forKey: expirationKey)
        }
        // v321: Save refresh token expiration
        if let refreshExpiration = refreshTokenExpirationDate {
            KeychainManager.shared.save(String(refreshExpiration.timeIntervalSince1970), forKey: refreshExpirationKey)
        }

        // Clear old UserDefaults keys (migration from insecure storage)
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.accessTokenV2")
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.refreshTokenV2")
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.tokenExpirationV2")

        #if DEBUG
        debugLog("🔑 DEBUG TokenManager.saveTokens(): Saved to Keychain (secure storage)")
        #endif

        logger.debug("Tokens saved to Keychain successfully")
    }

    func getAccessToken() -> String? {
        // Check if token is expired
        if let expiration = tokenExpirationDate, Date() >= expiration {
            logger.debug("Access token expired")
            return nil
        }
        return accessToken
    }

    func getRefreshToken() -> String? {
        // v321: Check if refresh token is expired (prevents using stale tokens)
        if let expiration = refreshTokenExpirationDate, Date() >= expiration {
            logger.debug("Refresh token expired")
            return nil
        }
        return refreshToken
    }

    func hasRefreshToken() -> Bool {
        return refreshToken != nil
    }

    func clearTokens() {
        #if DEBUG
        debugLog("🔑 DEBUG TokenManager.clearTokens(): Clearing tokens - had accessToken: \(accessToken != nil), refreshToken: \(refreshToken != nil)")
        #endif

        accessToken = nil
        refreshToken = nil
        tokenExpirationDate = nil
        refreshTokenExpirationDate = nil

        // Clear from Keychain (secure storage)
        KeychainManager.shared.delete(forKey: accessTokenKey)
        KeychainManager.shared.delete(forKey: refreshTokenKey)
        KeychainManager.shared.delete(forKey: expirationKey)
        KeychainManager.shared.delete(forKey: refreshExpirationKey)

        // Also clear legacy UserDefaults keys (migration cleanup)
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.accessTokenV2")
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.refreshTokenV2")
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.tokenExpirationV2")
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.accessToken")
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.refreshToken")
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.tokenExpiration")

        #if DEBUG
        debugLog("🔑 DEBUG TokenManager.clearTokens(): Cleared from Keychain")
        #endif

        logger.debug("Tokens cleared from Keychain")
    }

    func isAuthenticated() -> Bool {
        return getAccessToken() != nil || refreshToken != nil
    }

    // MARK: - Private Methods

    private func loadTokensFromStorage() {
        // Try to load from Keychain first (secure storage)
        accessToken = KeychainManager.shared.retrieve(forKey: accessTokenKey)
        refreshToken = KeychainManager.shared.retrieve(forKey: refreshTokenKey)

        if let expirationString = KeychainManager.shared.retrieve(forKey: expirationKey),
           let expirationInterval = Double(expirationString) {
            tokenExpirationDate = Date(timeIntervalSince1970: expirationInterval)
        }

        // v321: Load refresh token expiration
        if let refreshExpirationString = KeychainManager.shared.retrieve(forKey: refreshExpirationKey),
           let refreshExpirationInterval = Double(refreshExpirationString) {
            refreshTokenExpirationDate = Date(timeIntervalSince1970: refreshExpirationInterval)
        }

        // Migration: If no tokens in Keychain, check UserDefaults and migrate
        if accessToken == nil {
            if let legacyAccessToken = UserDefaults.standard.string(forKey: "com.bmnboston.accessTokenV2") {
                #if DEBUG
                debugLog("🔑 DEBUG TokenManager: Migrating tokens from UserDefaults to Keychain")
                #endif
                accessToken = legacyAccessToken
                refreshToken = UserDefaults.standard.string(forKey: "com.bmnboston.refreshTokenV2")
                if let expirationInterval = UserDefaults.standard.object(forKey: "com.bmnboston.tokenExpirationV2") as? Double {
                    tokenExpirationDate = Date(timeIntervalSince1970: expirationInterval)
                }
                // v321: For migrated tokens without refresh expiration, default to 30 days from now
                refreshTokenExpirationDate = Date().addingTimeInterval(30 * 24 * 60 * 60)

                // Save to Keychain and clear UserDefaults
                if let at = accessToken, let rt = refreshToken {
                    KeychainManager.shared.save(at, forKey: accessTokenKey)
                    KeychainManager.shared.save(rt, forKey: refreshTokenKey)
                    if let exp = tokenExpirationDate {
                        KeychainManager.shared.save(String(exp.timeIntervalSince1970), forKey: expirationKey)
                    }
                    if let refreshExp = refreshTokenExpirationDate {
                        KeychainManager.shared.save(String(refreshExp.timeIntervalSince1970), forKey: refreshExpirationKey)
                    }
                    // Clear legacy UserDefaults
                    UserDefaults.standard.removeObject(forKey: "com.bmnboston.accessTokenV2")
                    UserDefaults.standard.removeObject(forKey: "com.bmnboston.refreshTokenV2")
                    UserDefaults.standard.removeObject(forKey: "com.bmnboston.tokenExpirationV2")
                    logger.info("Migrated tokens from UserDefaults to Keychain")
                }
            }
        }

        #if DEBUG
        debugLog("🔑 DEBUG TokenManager.loadTokensFromStorage(): accessToken exists: \(accessToken != nil), refreshToken exists: \(refreshToken != nil)")
        #endif

        if accessToken != nil {
            logger.debug("Tokens loaded from Keychain")
        }
    }
}
