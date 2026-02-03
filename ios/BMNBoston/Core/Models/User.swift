//
//  User.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation

// MARK: - Auth Response Data (API wrapper)

struct AuthResponseData: Decodable {
    let user: User
    let accessToken: String
    let refreshToken: String
    let expiresIn: Int

    enum CodingKeys: String, CodingKey {
        case user
        case accessToken = "access_token"
        case refreshToken = "refresh_token"
        case expiresIn = "expires_in"
    }
}

// MARK: - User

struct User: Identifiable, Codable {
    let id: Int
    let email: String
    let name: String
    let firstName: String?
    let lastName: String?
    let phone: String?
    let avatarUrl: String?

    // Phase 5: Agent-Client Collaboration
    let userType: UserType?
    let assignedAgent: AgentSummary?

    // MLS Agent ID for ShowingTime integration (agent users only)
    let mlsAgentId: String?

    enum CodingKeys: String, CodingKey {
        case id
        case email
        case name
        case firstName = "first_name"
        case lastName = "last_name"
        case phone
        case avatarUrl = "avatar_url"
        case userType = "user_type"
        case assignedAgent = "assigned_agent"
        case mlsAgentId = "mls_agent_id"
    }

    /// Check if user is a client (or no type set - defaults to client)
    var isClient: Bool {
        userType == nil || userType == .client
    }

    /// Check if user is an agent
    var isAgent: Bool {
        userType?.isAgent == true
    }

    /// Check if user has an assigned agent
    var hasAgent: Bool {
        assignedAgent != nil
    }

    var displayName: String {
        if let first = firstName, !first.isEmpty, let last = lastName, !last.isEmpty {
            return "\(first) \(last)"
        }
        return name.isEmpty ? email : name
    }

    var fullName: String {
        displayName
    }

    var initials: String {
        if let first = firstName?.first, let last = lastName?.first {
            return "\(first)\(last)".uppercased()
        }
        let parts = displayName.split(separator: " ")
        if parts.count >= 2 {
            return "\(parts[0].prefix(1))\(parts[1].prefix(1))".uppercased()
        }
        return String(displayName.prefix(2)).uppercased()
    }
}

// MARK: - User Storage (Keychain for security)

extension User {
    private static let storageKey = "com.bmnboston.currentUserV2"

    func save() {
        if let encoded = try? JSONEncoder().encode(self) {
            KeychainManager.shared.saveData(encoded, forKey: User.storageKey)
            #if DEBUG
            debugLog("🔐 DEBUG User.save(): Saved user to Keychain: \(self.email) (id: \(self.id))")
            #endif
        } else {
            #if DEBUG
            debugLog("🔐 DEBUG User.save(): FAILED to encode user: \(self.email)")
            #endif
        }
    }

    static func loadFromStorage() -> User? {
        if let data = KeychainManager.shared.retrieveData(forKey: storageKey),
           let user = try? JSONDecoder().decode(User.self, from: data) {
            #if DEBUG
            debugLog("🔐 DEBUG User.loadFromStorage(): Loaded from Keychain: \(user.email) (id: \(user.id))")
            #endif
            return user
        }

        #if DEBUG
        debugLog("🔐 DEBUG User.loadFromStorage(): No user found in Keychain")
        #endif
        return nil
    }

    static func clearStorage() {
        #if DEBUG
        debugLog("🔐 DEBUG User.clearStorage(): Clearing user from Keychain")
        #endif
        KeychainManager.shared.delete(forKey: storageKey)
        // Also clear legacy UserDefaults keys (migration cleanup)
        UserDefaults.standard.removeObject(forKey: storageKey)
        UserDefaults.standard.removeObject(forKey: "com.bmnboston.currentUser")
    }
}
