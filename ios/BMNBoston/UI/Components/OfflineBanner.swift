//
//  OfflineBanner.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Displays when the device is offline
//

import SwiftUI

/// Banner displayed at the top of the screen when offline
struct OfflineBanner: View {
    @ObservedObject var networkMonitor = NetworkMonitor.shared

    var body: some View {
        if !networkMonitor.isConnected {
            HStack(spacing: 8) {
                Image(systemName: "wifi.slash")
                    .font(.system(size: 14, weight: .semibold))
                Text("No Internet Connection")
                    .font(.subheadline)
                    .fontWeight(.medium)
                Spacer()
            }
            .foregroundColor(.white)
            .padding(.horizontal, 16)
            .padding(.vertical, 10)
            .background(Color.orange)
            .transition(.move(edge: .top).combined(with: .opacity))
        }
    }
}

/// Modifier to add offline banner to any view
struct OfflineBannerModifier: ViewModifier {
    func body(content: Content) -> some View {
        VStack(spacing: 0) {
            OfflineBanner()
                .animation(.easeInOut(duration: 0.3), value: NetworkMonitor.shared.isConnected)
            content
        }
    }
}

extension View {
    /// Adds an offline banner at the top of the view
    func withOfflineBanner() -> some View {
        modifier(OfflineBannerModifier())
    }
}

#Preview {
    VStack {
        OfflineBanner()
        Spacer()
        Text("Content")
        Spacer()
    }
}
