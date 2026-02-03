//
//  SessionManager.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Sprint 5: Client Analytics
//

import Foundation
import UIKit

/// Manages client session lifecycle for analytics
/// Creates sessions on app launch, ends them on background/terminate
actor SessionManager {
    static let shared = SessionManager()

    // MARK: - State

    private var _currentSessionId: String = ""
    private var sessionStartTime: Date?
    private var isSessionActive: Bool = false
    private var isAuthenticated: Bool = false

    // MARK: - Computed Properties

    /// Current session ID (creates new one if needed)
    var currentSessionId: String {
        get async {
            if _currentSessionId.isEmpty {
                await startSession()
            }
            return _currentSessionId
        }
    }

    // MARK: - Device Info

    private var deviceType: String {
        var systemInfo = utsname()
        uname(&systemInfo)
        let machineMirror = Mirror(reflecting: systemInfo.machine)
        let identifier = machineMirror.children.reduce("") { identifier, element in
            guard let value = element.value as? Int8, value != 0 else { return identifier }
            return identifier + String(UnicodeScalar(UInt8(value)))
        }

        // Map common identifiers to friendly names
        if identifier.contains("iPhone") {
            return identifier
        }
        return UIDevice.current.model
    }

    private var appVersion: String {
        Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "unknown"
    }

    // MARK: - Public Methods

    /// Set authentication state
    func setAuthenticated(_ authenticated: Bool) {
        isAuthenticated = authenticated
        if authenticated && !isSessionActive {
            Task { await startSession() }
        } else if !authenticated && isSessionActive {
            Task { await endSession() }
        }
    }

    /// Start a new session
    func startSession() async {
        guard isAuthenticated else { return }

        // End previous session if active
        if isSessionActive {
            await endSession()
        }

        // Generate new session ID
        _currentSessionId = generateSessionId()
        sessionStartTime = Date()
        isSessionActive = true

        // Notify server of session start
        do {
            let _: SessionResponse = try await APIClient.shared.request(
                .sessionEvent(
                    action: "start",
                    sessionId: _currentSessionId,
                    deviceType: deviceType,
                    appVersion: appVersion
                )
            )
        } catch {
            debugLog("SessionManager: Failed to start session on server: \(error)")
            // Session continues locally even if server call fails
        }
    }

    /// End the current session
    func endSession() async {
        guard isSessionActive, !_currentSessionId.isEmpty else { return }

        // Flush any pending activity events first
        await ActivityTracker.shared.flush()

        let sessionId = _currentSessionId
        isSessionActive = false
        _currentSessionId = ""
        sessionStartTime = nil

        // Notify server of session end
        do {
            let _: SessionResponse = try await APIClient.shared.request(
                .sessionEvent(
                    action: "end",
                    sessionId: sessionId
                )
            )
        } catch {
            debugLog("SessionManager: Failed to end session on server: \(error)")
            // Session ends locally even if server call fails
        }
    }

    /// Called when app enters background
    func appDidEnterBackground() async {
        // Flush activities when backgrounding
        await ActivityTracker.shared.flush()

        // Optionally end session on background (or keep alive for quick resume)
        // For now, we keep the session alive
    }

    /// Called when app will terminate
    func appWillTerminate() async {
        await endSession()
    }

    /// Called when app returns to foreground
    func appWillEnterForeground() async {
        // Check if session needs refresh (e.g., after long background period)
        if let startTime = sessionStartTime {
            let backgroundDuration = Date().timeIntervalSince(startTime)
            // If backgrounded for more than 30 minutes, start new session
            if backgroundDuration > 30 * 60 {
                await startSession()
            }
        } else if isAuthenticated {
            await startSession()
        }
    }

    // MARK: - Private Methods

    private func generateSessionId() -> String {
        // Format: ios_<timestamp>_<random>
        let timestamp = Int(Date().timeIntervalSince1970)
        let random = UUID().uuidString.prefix(8)
        return "ios_\(timestamp)_\(random)"
    }
}

// MARK: - Response Model

struct SessionResponse: Decodable {
    let success: Bool
    let message: String?
}
