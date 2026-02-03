//
//  ReferralCodeManager.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Manages referral codes from deep links
//

import Foundation

/// Manages referral codes received from deep links
/// Stores the code until registration is complete
@MainActor
class ReferralCodeManager: ObservableObject {
    static let shared = ReferralCodeManager()

    private let referralCodeKey = "com.bmnboston.pendingReferralCode"
    private let referralAgentNameKey = "com.bmnboston.pendingReferralAgentName"

    @Published var pendingReferralCode: String?
    @Published var pendingAgentName: String?

    private init() {
        // Load any pending referral code from storage
        pendingReferralCode = UserDefaults.standard.string(forKey: referralCodeKey)
        pendingAgentName = UserDefaults.standard.string(forKey: referralAgentNameKey)
    }

    /// Store a referral code from a deep link
    /// - Parameters:
    ///   - code: The referral code
    ///   - agentName: Optional agent name to display
    func storeReferralCode(_ code: String, agentName: String? = nil) {
        pendingReferralCode = code
        pendingAgentName = agentName

        UserDefaults.standard.set(code, forKey: referralCodeKey)
        if let name = agentName {
            UserDefaults.standard.set(name, forKey: referralAgentNameKey)
        }

        #if DEBUG
        debugLog("📎 Stored referral code: \(code), agent: \(agentName ?? "unknown")")
        #endif
    }

    /// Get and clear the pending referral code
    /// Call this when registration is complete
    /// - Returns: The referral code if one was stored
    func consumeReferralCode() -> String? {
        let code = pendingReferralCode
        clearReferralCode()
        return code
    }

    /// Clear the stored referral code
    func clearReferralCode() {
        pendingReferralCode = nil
        pendingAgentName = nil
        UserDefaults.standard.removeObject(forKey: referralCodeKey)
        UserDefaults.standard.removeObject(forKey: referralAgentNameKey)

        #if DEBUG
        debugLog("📎 Cleared referral code")
        #endif
    }

    /// Check if there's a pending referral code
    var hasReferralCode: Bool {
        return pendingReferralCode != nil && !pendingReferralCode!.isEmpty
    }
}
