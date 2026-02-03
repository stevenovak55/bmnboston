//
//  ContentView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import SwiftUI

struct ContentView: View {
    @EnvironmentObject var authViewModel: AuthViewModel
    @EnvironmentObject var pushNotificationManager: PushNotificationManager

    @State private var showPermissionsOnboarding = false

    private let permissionsOnboardingKey = "com.bmnboston.permissionsOnboardingCompleted"

    var body: some View {
        Group {
            if authViewModel.canAccessApp {
                MainTabView()
            } else {
                LoginView()
            }
        }
        .task {
            await authViewModel.checkAuthStatus()
        }
        .onChange(of: authViewModel.isAuthenticated) { isAuthenticated in
            // Show permissions onboarding after first login
            if isAuthenticated && !UserDefaults.standard.bool(forKey: permissionsOnboardingKey) {
                // Small delay to let the main view settle
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
                    showPermissionsOnboarding = true
                }
            }
        }
        .fullScreenCover(isPresented: $showPermissionsOnboarding) {
            PermissionsOnboardingView()
                .environmentObject(pushNotificationManager)
        }
    }
}

#Preview {
    ContentView()
        .environmentObject(AuthViewModel())
        .environmentObject(PushNotificationManager.shared)
}
