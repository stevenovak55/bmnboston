//
//  KeychainManager.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation
import Security
import os.log

final class KeychainManager {
    static let shared = KeychainManager()

    private let logger = Logger(subsystem: "com.bmnboston.app", category: "KeychainManager")

    // Service name for keychain items - ensures items persist correctly
    private let serviceName = "com.bmnboston.app"
    private let migrationKey = "com.bmnboston.keychainMigrationV2"

    private init() {
        // One-time migration: clear ALL legacy keychain data on first run after update
        if !UserDefaults.standard.bool(forKey: migrationKey) {
            #if DEBUG
            debugLog("🔧 DEBUG KeychainManager: Running one-time keychain cleanup migration")
            #endif
            performLegacyCleanup()
            UserDefaults.standard.set(true, forKey: migrationKey)
        }
    }

    /// One-time cleanup of ALL legacy keychain items from before service name was added
    private func performLegacyCleanup() {
        // List of all keys we've ever used
        let allKeys = [
            "com.bmnboston.currentUser",
            "com.bmnboston.accessToken",
            "com.bmnboston.refreshToken",
            "com.bmnboston.tokenExpiration"
        ]

        for key in allKeys {
            // Delete legacy items (without service name)
            let legacyQuery: [String: Any] = [
                kSecClass as String: kSecClassGenericPassword,
                kSecAttrAccount as String: key
            ]
            let status = SecItemDelete(legacyQuery as CFDictionary)
            #if DEBUG
            debugLog("🔧 DEBUG KeychainManager.performLegacyCleanup(): Deleted legacy \(key): status=\(status)")
            #endif
        }
    }

    // MARK: - Public Methods

    func save(_ value: String, forKey key: String) {
        guard let data = value.data(using: .utf8) else {
            logger.error("Failed to convert string to data for key: \(key)")
            return
        }

        // Delete any existing item first
        delete(forKey: key)

        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key,
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        ]

        let status = SecItemAdd(query as CFDictionary, nil)

        if status != errSecSuccess {
            logger.error("Failed to save to keychain. Status: \(status)")
        }
    }

    func retrieve(forKey key: String) -> String? {
        // First try WITH service name (current format)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]

        var result: AnyObject?
        var status = SecItemCopyMatching(query as CFDictionary, &result)

        if status == errSecSuccess,
           let data = result as? Data,
           let string = String(data: data, encoding: .utf8) {
            return string
        }

        // Fall back to legacy format WITHOUT service name
        let legacyQuery: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]

        result = nil
        status = SecItemCopyMatching(legacyQuery as CFDictionary, &result)

        if status == errSecSuccess,
           let data = result as? Data,
           let string = String(data: data, encoding: .utf8) {
            #if DEBUG
            debugLog("📖 DEBUG KeychainManager.retrieve(\(key)): Found LEGACY data, migrating...")
            #endif
            // Migrate to new format and delete legacy
            save(string, forKey: key)
            // Delete legacy item
            let deleteLegacy: [String: Any] = [
                kSecClass as String: kSecClassGenericPassword,
                kSecAttrAccount as String: key
            ]
            SecItemDelete(deleteLegacy as CFDictionary)
            return string
        }

        return nil
    }

    func delete(forKey key: String) {
        // Delete item WITH service name (current format)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key
        ]

        let status = SecItemDelete(query as CFDictionary)
        #if DEBUG
        debugLog("🗑️ DEBUG KeychainManager.delete(\(key)): status=\(status) (0=success, -25300=notFound)")
        #endif

        // Also delete any legacy item WITHOUT service name (old format)
        // This cleans up data saved before kSecAttrService was added
        let legacyQuery: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: key
        ]

        let legacyStatus = SecItemDelete(legacyQuery as CFDictionary)
        #if DEBUG
        debugLog("🗑️ DEBUG KeychainManager.delete(\(key)) LEGACY: status=\(legacyStatus)")
        #endif
    }

    // MARK: - Data Storage (for Codable objects)

    func saveData(_ data: Data, forKey key: String) {
        #if DEBUG
        debugLog("💾 DEBUG KeychainManager.saveData(\(key)): Starting save, data size=\(data.count) bytes")
        #endif

        // Delete any existing item first
        delete(forKey: key)

        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key,
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        ]

        let status = SecItemAdd(query as CFDictionary, nil)

        #if DEBUG
        debugLog("💾 DEBUG KeychainManager.saveData(\(key)): SecItemAdd status=\(status) (0=success, -25299=duplicate)")
        #endif

        if status != errSecSuccess {
            logger.error("Failed to save data to keychain. Status: \(status)")
        }

        // Verify the save worked by reading it back
        #if DEBUG
        if let verifyData = retrieveData(forKey: key) {
            debugLog("💾 DEBUG KeychainManager.saveData(\(key)): VERIFIED - read back \(verifyData.count) bytes")
        } else {
            debugLog("💾 DEBUG KeychainManager.saveData(\(key)): ⚠️ VERIFICATION FAILED - could not read back!")
        }
        #endif
    }

    func retrieveData(forKey key: String) -> Data? {
        // First try WITH service name (current format)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]

        var result: AnyObject?
        var status = SecItemCopyMatching(query as CFDictionary, &result)

        #if DEBUG
        debugLog("📖 DEBUG KeychainManager.retrieveData(\(key)): status=\(status) (0=success, -25300=notFound)")
        #endif

        if status == errSecSuccess, let data = result as? Data {
            return data
        }

        // Fall back to legacy format WITHOUT service name
        let legacyQuery: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]

        result = nil
        status = SecItemCopyMatching(legacyQuery as CFDictionary, &result)

        #if DEBUG
        debugLog("📖 DEBUG KeychainManager.retrieveData(\(key)) LEGACY: status=\(status)")
        #endif

        if status == errSecSuccess, let data = result as? Data {
            #if DEBUG
            debugLog("📖 DEBUG KeychainManager.retrieveData(\(key)): Found LEGACY data (\(data.count) bytes), migrating...")
            #endif
            // Migrate to new format and delete legacy
            saveData(data, forKey: key)
            // Delete legacy item
            let deleteLegacy: [String: Any] = [
                kSecClass as String: kSecClassGenericPassword,
                kSecAttrAccount as String: key
            ]
            SecItemDelete(deleteLegacy as CFDictionary)
            return data
        }

        return nil
    }

    func clearAll() {
        // Clear items WITH service name (current format)
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName
        ]
        SecItemDelete(query as CFDictionary)

        // Clear only BMNBoston's legacy keys (without service name)
        // IMPORTANT: Do NOT blanket-delete all generic passwords as that could affect other apps
        let legacyKeys = [
            "com.bmnboston.currentUser",
            "com.bmnboston.currentUserV2",
            "com.bmnboston.accessToken",
            "com.bmnboston.refreshToken",
            "com.bmnboston.tokenExpiration"
        ]

        for key in legacyKeys {
            let legacyQuery: [String: Any] = [
                kSecClass as String: kSecClassGenericPassword,
                kSecAttrAccount as String: key
            ]
            SecItemDelete(legacyQuery as CFDictionary)
        }

        logger.debug("Cleared all BMNBoston keychain items (including legacy)")
    }
}
