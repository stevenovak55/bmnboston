//
//  AuthViewModel.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import Foundation
import os.log

@MainActor
class AuthViewModel: ObservableObject {
    @Published var isAuthenticated = false
    @Published var isGuestMode = false
    @Published var isLoading = false
    @Published var currentUser: User?
    @Published var errorMessage: String?

    private let logger = Logger(subsystem: "com.bmnboston.app", category: "AuthViewModel")
    private let guestModeKey = "com.bmnboston.guestMode"

    init() {
        #if DEBUG
        debugLog("👤 DEBUG AuthViewModel.init(): Starting initialization")
        #endif

        // One-time cleanup of legacy keychain data that was causing persistence issues
        // This runs in ALL builds (not just debug) to ensure clean state
        let migrationKey = "com.bmnboston.keychainCleanupV4"
        if !UserDefaults.standard.bool(forKey: migrationKey) {
            #if DEBUG
            debugLog("👤 DEBUG AuthViewModel.init(): Running one-time legacy cleanup (V4)")
            #endif
            KeychainManager.shared.clearAll()
            // Clear ALL old keys (both V1 and any stale V2)
            UserDefaults.standard.removeObject(forKey: "com.bmnboston.currentUser")
            UserDefaults.standard.removeObject(forKey: "com.bmnboston.currentUserV2")
            UserDefaults.standard.removeObject(forKey: "com.bmnboston.accessToken")
            UserDefaults.standard.removeObject(forKey: "com.bmnboston.refreshToken")
            UserDefaults.standard.removeObject(forKey: "com.bmnboston.tokenExpiration")
            UserDefaults.standard.removeObject(forKey: "com.bmnboston.accessTokenV2")
            UserDefaults.standard.removeObject(forKey: "com.bmnboston.refreshTokenV2")
            UserDefaults.standard.removeObject(forKey: "com.bmnboston.tokenExpirationV2")
            UserDefaults.standard.set(true, forKey: migrationKey)
            #if DEBUG
            debugLog("👤 DEBUG AuthViewModel.init(): Legacy cleanup V4 complete - ALL storage cleared")
            #endif
        }

        // Load user from storage on init
        currentUser = User.loadFromStorage()
        isGuestMode = UserDefaults.standard.bool(forKey: guestModeKey)

        #if DEBUG
        debugLog("👤 DEBUG AuthViewModel.init(): Loaded user: \(currentUser?.email ?? "nil"), isGuestMode: \(isGuestMode)")
        #endif

        // If user is found in storage, optimistically set isAuthenticated
        // checkAuthStatus() will validate the token and clear this if invalid
        if currentUser != nil {
            isAuthenticated = true
        }
    }

    /// Whether the user can access the app (authenticated or guest)
    var canAccessApp: Bool {
        isAuthenticated || isGuestMode
    }

    // MARK: - Public Methods

    func checkAuthStatus() async {
        #if DEBUG
        debugLog("👤 DEBUG checkAuthStatus(): Starting - currentUser: \(currentUser?.email ?? "nil"), isGuestMode: \(isGuestMode)")
        #endif

        // Check if guest mode
        if isGuestMode {
            #if DEBUG
            debugLog("👤 DEBUG checkAuthStatus(): In guest mode, returning")
            #endif
            return
        }

        // If we have a stored user, always try to validate with the server
        // This handles the race condition where TokenManager hasn't loaded tokens yet
        // The APIClient will read tokens from keychain and refresh if needed
        if currentUser != nil {
            #if DEBUG
            debugLog("👤 DEBUG checkAuthStatus(): Have stored user \(currentUser!.email), calling fetchCurrentUser()")
            #endif
            await fetchCurrentUser()
            return
        }

        // No stored user, check if we have tokens
        let hasAuth = await TokenManager.shared.isAuthenticated()
        #if DEBUG
        debugLog("👤 DEBUG checkAuthStatus(): No stored user, hasAuth: \(hasAuth)")
        #endif
        if hasAuth {
            await fetchCurrentUser()
        } else {
            isAuthenticated = false
            currentUser = nil
        }
    }

    func continueAsGuest() {
        isGuestMode = true
        UserDefaults.standard.set(true, forKey: guestModeKey)
        logger.info("User continuing as guest")
    }

    func login(email: String, password: String) async {
        guard !email.isEmpty, !password.isEmpty else {
            errorMessage = "Please enter email and password"
            return
        }

        #if DEBUG
        debugLog("👤 DEBUG AuthViewModel.login(): Starting login for \(email)")
        #endif

        isLoading = true
        errorMessage = nil

        // Clear any existing tokens/user before login to prevent state confusion
        // This ensures a fresh start for the new login attempt
        #if DEBUG
        debugLog("👤 DEBUG AuthViewModel.login(): Clearing old tokens and user storage BEFORE login")
        #endif
        await TokenManager.shared.clearTokens()
        User.clearStorage()
        currentUser = nil

        do {
            let endpoint = APIEndpoint.login(email: email, password: password)
            let data: AuthResponseData = try await APIClient.shared.request(endpoint)

            // Save tokens
            await TokenManager.shared.saveTokens(
                accessToken: data.accessToken,
                refreshToken: data.refreshToken,
                expiresIn: data.expiresIn
            )

            // Save user
            currentUser = data.user
            data.user.save()

            #if DEBUG
            debugLog("👤 DEBUG AuthViewModel.login(): Login successful for \(data.user.email) (id: \(data.user.id))")
            #endif

            // Clear guest mode if was in guest mode
            isGuestMode = false
            UserDefaults.standard.set(false, forKey: guestModeKey)

            isAuthenticated = true
            logger.info("User logged in: \(data.user.email)")

            // Track login activity for analytics (v6.74.2)
            Task {
                await ActivityTracker.shared.track(.login)
            }

        } catch let error as APIError {
            errorMessage = error.errorDescription
            logger.error("Login failed: \(error.localizedDescription)")
        } catch {
            errorMessage = "An unexpected error occurred"
            logger.error("Login failed: \(error.localizedDescription)")
        }

        isLoading = false
    }

    func register(email: String, password: String, firstName: String, lastName: String, phone: String? = nil, referralCode: String? = nil) async {
        guard !email.isEmpty, !password.isEmpty else {
            errorMessage = "Please fill in all required fields"
            return
        }

        isLoading = true
        errorMessage = nil

        // Clear any existing tokens/user before registration to prevent state confusion
        await TokenManager.shared.clearTokens()
        User.clearStorage()
        currentUser = nil

        // Get referral code from parameter or from ReferralCodeManager
        let finalReferralCode = referralCode ?? ReferralCodeManager.shared.pendingReferralCode

        do {
            let endpoint = APIEndpoint.register(
                email: email,
                password: password,
                firstName: firstName,
                lastName: lastName,
                phone: phone,
                referralCode: finalReferralCode
            )
            let data: AuthResponseData = try await APIClient.shared.request(endpoint)

            // Save tokens
            await TokenManager.shared.saveTokens(
                accessToken: data.accessToken,
                refreshToken: data.refreshToken,
                expiresIn: data.expiresIn
            )

            // Save user
            currentUser = data.user
            data.user.save()

            // Clear guest mode
            isGuestMode = false
            UserDefaults.standard.set(false, forKey: guestModeKey)

            // Clear any pending referral code after successful registration
            ReferralCodeManager.shared.clearReferralCode()

            isAuthenticated = true
            logger.info("User registered: \(data.user.email)")

        } catch let error as APIError {
            errorMessage = error.errorDescription
            logger.error("Registration failed: \(error.localizedDescription)")
        } catch {
            errorMessage = "An unexpected error occurred"
            logger.error("Registration failed: \(error.localizedDescription)")
        }

        isLoading = false
    }

    func logout() async {
        #if DEBUG
        debugLog("👤 DEBUG AuthViewModel.logout(): Starting logout for \(currentUser?.email ?? "unknown")")
        #endif

        isLoading = true

        do {
            try await APIClient.shared.requestWithoutResponse(.logout)
        } catch {
            // Continue with local logout even if server request fails
            logger.warning("Logout request failed: \(error.localizedDescription)")
        }

        #if DEBUG
        debugLog("👤 DEBUG AuthViewModel.logout(): Clearing tokens and user storage")
        #endif

        await TokenManager.shared.clearTokens()
        User.clearStorage()

        currentUser = nil
        isAuthenticated = false
        isGuestMode = false
        UserDefaults.standard.set(false, forKey: guestModeKey)
        isLoading = false

        #if DEBUG
        debugLog("👤 DEBUG AuthViewModel.logout(): Logout complete, currentUser is now: \(currentUser?.email ?? "nil")")
        #endif

        logger.info("User logged out")
    }

    func forgotPassword(email: String) async -> Bool {
        guard !email.isEmpty else {
            errorMessage = "Please enter your email"
            return false
        }

        isLoading = true
        errorMessage = nil

        do {
            let endpoint = APIEndpoint.forgotPassword(email: email)
            try await APIClient.shared.requestWithoutResponse(endpoint)
            isLoading = false
            return true
        } catch let error as APIError {
            errorMessage = error.errorDescription
            isLoading = false
            return false
        } catch {
            errorMessage = "An unexpected error occurred"
            isLoading = false
            return false
        }
    }

    /// Delete user account permanently (Apple App Store Guideline 5.1.1(v) compliance)
    /// @since v203
    func deleteAccount() async -> Result<Void, Error> {
        #if DEBUG
        debugLog("👤 DEBUG AuthViewModel.deleteAccount(): Starting account deletion for \(currentUser?.email ?? "unknown")")
        #endif

        isLoading = true
        errorMessage = nil

        do {
            try await APIClient.shared.requestWithoutResponse(.deleteAccount)

            #if DEBUG
            debugLog("👤 DEBUG AuthViewModel.deleteAccount(): Server deletion successful, clearing local data")
            #endif

            // Clear all local data
            await TokenManager.shared.clearTokens()
            User.clearStorage()

            // Clear notification store data
            await NotificationStore.shared.clearAll()

            currentUser = nil
            isAuthenticated = false
            isGuestMode = false
            UserDefaults.standard.set(false, forKey: guestModeKey)

            isLoading = false
            logger.info("User account deleted successfully")
            return .success(())

        } catch let error as APIError {
            #if DEBUG
            debugLog("👤 DEBUG AuthViewModel.deleteAccount(): Failed with APIError: \(error.localizedDescription)")
            #endif
            errorMessage = error.errorDescription
            isLoading = false
            logger.error("Account deletion failed: \(error.localizedDescription)")
            return .failure(error)
        } catch {
            #if DEBUG
            debugLog("👤 DEBUG AuthViewModel.deleteAccount(): Failed with error: \(error.localizedDescription)")
            #endif
            errorMessage = "Failed to delete account. Please try again."
            isLoading = false
            logger.error("Account deletion failed: \(error.localizedDescription)")
            return .failure(error)
        }
    }

    // MARK: - Private Methods

    private func fetchCurrentUser() async {
        #if DEBUG
        debugLog("👤 DEBUG fetchCurrentUser(): Starting - will call /me API")
        let hasToken = await TokenManager.shared.getAccessToken() != nil
        debugLog("👤 DEBUG fetchCurrentUser(): Has access token: \(hasToken)")
        #endif

        do {
            let user: User = try await APIClient.shared.request(.me)
            #if DEBUG
            debugLog("👤 DEBUG fetchCurrentUser(): /me returned user: \(user.email) (id: \(user.id))")
            #endif
            currentUser = user
            user.save()
            isAuthenticated = true
        } catch {
            #if DEBUG
            debugLog("👤 DEBUG fetchCurrentUser(): /me failed with error: \(error)")
            #endif
            // Token might be invalid, clear everything
            await TokenManager.shared.clearTokens()
            User.clearStorage()
            currentUser = nil
            isAuthenticated = false
        }
    }
}
