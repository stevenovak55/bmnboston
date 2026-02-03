//
//  PermissionsOnboardingView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Requests required permissions on first login
//

import SwiftUI
import CoreLocation
import UserNotifications

struct PermissionsOnboardingView: View {
    @EnvironmentObject var pushNotificationManager: PushNotificationManager
    @Environment(\.dismiss) var dismiss

    @State private var currentStep = 0
    @State private var notificationStatus: UNAuthorizationStatus = .notDetermined
    @State private var locationStatus: CLAuthorizationStatus = .notDetermined
    @State private var isRequestingPermission = false

    private let locationManager = CLLocationManager()

    var body: some View {
        VStack(spacing: 0) {
            // Progress indicator
            HStack(spacing: 8) {
                ForEach(0..<2) { index in
                    Capsule()
                        .fill(index <= currentStep ? AppColors.primary : Color.gray.opacity(0.3))
                        .frame(height: 4)
                }
            }
            .padding(.horizontal, 24)
            .padding(.top, 16)

            Spacer()

            // Content based on step
            if currentStep == 0 {
                notificationPermissionView
            } else {
                locationPermissionView
            }

            Spacer()

            // Bottom button
            Button {
                Task {
                    await handlePrimaryAction()
                }
            } label: {
                if isRequestingPermission {
                    ProgressView()
                        .progressViewStyle(CircularProgressViewStyle(tint: .white))
                } else {
                    Text(primaryButtonTitle)
                }
            }
            .buttonStyle(PrimaryButtonStyle())
            .disabled(isRequestingPermission)
            .padding(.horizontal, 24)
            .padding(.bottom, 40)
        }
        .task {
            await checkCurrentPermissions()
        }
    }

    // MARK: - Notification Permission View

    private var notificationPermissionView: some View {
        VStack(spacing: 24) {
            Image(systemName: "bell.badge.fill")
                .font(.system(size: 80))
                .foregroundStyle(AppColors.primary)
                .padding(.bottom, 8)

            Text("Stay Updated")
                .font(.title)
                .fontWeight(.bold)

            Text("Get notified about new listings matching your saved searches, price changes, and appointment reminders.")
                .font(.body)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)

            // Status indicator
            if notificationStatus == .authorized {
                HStack {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(.green)
                    Text("Notifications enabled")
                        .foregroundStyle(.green)
                }
                .font(.subheadline)
                .padding(.top, 8)
            } else if notificationStatus == .denied {
                HStack {
                    Image(systemName: "exclamationmark.circle.fill")
                        .foregroundStyle(.orange)
                    Text("Enable in Settings")
                        .foregroundStyle(.orange)
                }
                .font(.subheadline)
                .padding(.top, 8)
            }
        }
    }

    // MARK: - Location Permission View

    private var locationPermissionView: some View {
        VStack(spacing: 24) {
            Image(systemName: "location.fill")
                .font(.system(size: 80))
                .foregroundStyle(AppColors.primary)
                .padding(.bottom, 8)

            Text("Find Nearby Homes")
                .font(.title)
                .fontWeight(.bold)

            Text("Enable location to quickly find properties near you and see accurate distances to listings and schools.")
                .font(.body)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)

            // Status indicator
            if locationStatus == .authorizedWhenInUse || locationStatus == .authorizedAlways {
                HStack {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(.green)
                    Text("Location enabled")
                        .foregroundStyle(.green)
                }
                .font(.subheadline)
                .padding(.top, 8)
            } else if locationStatus == .denied {
                HStack {
                    Image(systemName: "exclamationmark.circle.fill")
                        .foregroundStyle(.orange)
                    Text("Enable in Settings")
                        .foregroundStyle(.orange)
                }
                .font(.subheadline)
                .padding(.top, 8)
            }
        }
    }

    // MARK: - Button Titles

    private var primaryButtonTitle: String {
        if currentStep == 0 {
            switch notificationStatus {
            case .authorized:
                return "Continue"
            case .denied:
                return "Open Settings"
            default:
                return "Continue"
            }
        } else {
            switch locationStatus {
            case .authorizedWhenInUse, .authorizedAlways:
                return "Get Started"
            case .denied:
                return "Open Settings"
            default:
                return "Continue"
            }
        }
    }


    // MARK: - Actions

    private func handlePrimaryAction() async {
        isRequestingPermission = true
        defer { isRequestingPermission = false }

        if currentStep == 0 {
            // Notification step
            if notificationStatus == .denied {
                openSettings()
            } else if notificationStatus != .authorized {
                _ = await pushNotificationManager.requestPermission()
                await checkCurrentPermissions()
            }
            // Move to next step
            withAnimation {
                currentStep = 1
            }
        } else {
            // Location step
            if locationStatus == .denied {
                openSettings()
            } else if locationStatus == .notDetermined {
                locationManager.requestWhenInUseAuthorization()
                // Give time for the permission dialog
                try? await Task.sleep(nanoseconds: 500_000_000)
                await checkCurrentPermissions()
            }
            // Complete onboarding
            completeOnboarding()
        }
    }

    private func completeOnboarding() {
        UserDefaults.standard.set(true, forKey: "com.bmnboston.permissionsOnboardingCompleted")
        // Notify waiting views that permissions onboarding is complete (event-driven)
        NotificationCenter.default.post(name: .permissionsOnboardingCompleted, object: nil)
        dismiss()
    }

    private func openSettings() {
        if let url = URL(string: UIApplication.openSettingsURLString) {
            UIApplication.shared.open(url)
        }
    }

    private func checkCurrentPermissions() async {
        // Check notification status
        let settings = await UNUserNotificationCenter.current().notificationSettings()
        notificationStatus = settings.authorizationStatus

        // Check location status
        locationStatus = locationManager.authorizationStatus
    }
}

#Preview {
    PermissionsOnboardingView()
        .environmentObject(PushNotificationManager.shared)
}
