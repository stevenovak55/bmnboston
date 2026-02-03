//
//  ToastManager.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  v216: Global toast notification manager
//

import SwiftUI

/// Global manager for displaying toast notifications
/// Usage: ToastManager.shared.show("Message", icon: "checkmark.circle.fill", style: .success)
@MainActor
class ToastManager: ObservableObject {
    static let shared = ToastManager()

    @Published var currentToast: Toast?

    struct Toast: Identifiable, Equatable {
        let id = UUID()
        let message: String
        let icon: String
        let style: ToastStyle

        enum ToastStyle {
            case success
            case error
            case info

            var color: Color {
                switch self {
                case .success: return .green
                case .error: return .red
                case .info: return AppColors.brandTeal
                }
            }
        }

        static func == (lhs: Toast, rhs: Toast) -> Bool {
            lhs.id == rhs.id
        }
    }

    private init() {}

    /// Show a toast notification
    /// - Parameters:
    ///   - message: The message to display
    ///   - icon: SF Symbol name (default: checkmark.circle.fill)
    ///   - style: Toast style (success, error, info)
    ///   - duration: How long to show the toast (default: 3 seconds)
    func show(_ message: String, icon: String = "checkmark.circle.fill", style: Toast.ToastStyle = .success, duration: Double = 3.0) {
        withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
            currentToast = Toast(message: message, icon: icon, style: style)
        }

        // Auto-dismiss after duration
        Task {
            try? await Task.sleep(nanoseconds: UInt64(duration * 1_000_000_000))
            withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                currentToast = nil
            }
        }
    }

    /// Show a success toast
    func success(_ message: String, icon: String = "checkmark.circle.fill") {
        show(message, icon: icon, style: .success)
    }

    /// Show an error toast
    func error(_ message: String, icon: String = "exclamationmark.triangle.fill") {
        show(message, icon: icon, style: .error)
    }

    /// Show an info toast
    func info(_ message: String, icon: String = "info.circle.fill") {
        show(message, icon: icon, style: .info)
    }

    /// Dismiss the current toast immediately
    func dismiss() {
        withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
            currentToast = nil
        }
    }
}
