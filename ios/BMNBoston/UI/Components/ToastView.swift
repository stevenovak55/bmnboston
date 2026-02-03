//
//  ToastView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  v216: Toast notification UI component
//

import SwiftUI

// MARK: - Toast View

/// Individual toast notification view
struct ToastView: View {
    let toast: ToastManager.Toast

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: toast.icon)
                .font(.system(size: 20, weight: .semibold))
                .foregroundStyle(toast.style.color)

            Text(toast.message)
                .font(.subheadline)
                .fontWeight(.medium)
                .foregroundStyle(.primary)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(.regularMaterial)
        .clipShape(Capsule())
        .shadow(color: .black.opacity(0.15), radius: 10, y: 5)
    }
}

// MARK: - Toast Overlay

/// Overlay view that displays toasts at the top of the screen
/// Add this as an overlay to your root view
struct ToastOverlay: View {
    @ObservedObject var toastManager = ToastManager.shared

    var body: some View {
        VStack {
            if let toast = toastManager.currentToast {
                ToastView(toast: toast)
                    .transition(.move(edge: .top).combined(with: .opacity))
                    .padding(.top, 60) // Below status bar
                    .onTapGesture {
                        toastManager.dismiss()
                    }
            }
            Spacer()
        }
        .animation(.spring(response: 0.3, dampingFraction: 0.8), value: toastManager.currentToast)
        .allowsHitTesting(toastManager.currentToast != nil)
    }
}

// MARK: - View Extension

extension View {
    /// Adds a toast overlay to the view
    func withToastOverlay() -> some View {
        ZStack {
            self
            ToastOverlay()
        }
    }
}

// MARK: - Preview

#Preview {
    ZStack {
        Color(.systemBackground)
            .ignoresSafeArea()

        VStack(spacing: 20) {
            Button("Show Success Toast") {
                ToastManager.shared.success("Search saved!")
            }

            Button("Show Error Toast") {
                ToastManager.shared.error("Something went wrong")
            }

            Button("Show Info Toast") {
                ToastManager.shared.info("Tip: You can swipe to dismiss")
            }
        }

        ToastOverlay()
    }
}
