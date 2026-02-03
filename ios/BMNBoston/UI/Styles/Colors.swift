//
//  Colors.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import SwiftUI

// MARK: - Adaptive Color Extension

extension Color {
    /// Creates a color that adapts to light and dark mode
    init(light: Color, dark: Color) {
        self.init(UIColor { traitCollection in
            switch traitCollection.userInterfaceStyle {
            case .dark:
                return UIColor(dark)
            default:
                return UIColor(light)
            }
        })
    }
}

// MARK: - App Colors

enum AppColors {
    // MARK: - Primary System Colors
    static let primary = Color("AccentColor")
    static let secondary = Color.secondary

    // MARK: - Background Colors (System Adaptive)
    static let background = Color(.systemBackground)
    static let secondaryBackground = Color(.secondarySystemBackground)
    static let tertiaryBackground = Color(.tertiarySystemBackground)
    static let groupedBackground = Color(.systemGroupedBackground)

    // MARK: - Brand Colors (Adaptive - brighter in dark mode)
    static var brandTeal: Color {
        Color(light: Color(hex: "#0891B2"), dark: Color(hex: "#22D3EE"))
    }

    static var brandTealHover: Color {
        Color(light: Color(hex: "#0E7490"), dark: Color(hex: "#67E8F9"))
    }

    // MARK: - Text Colors (Adaptive)
    static var textPrimary: Color {
        Color(light: Color(hex: "#000000"), dark: Color(hex: "#FFFFFF"))
    }

    static var textSecondary: Color {
        Color(light: Color(hex: "#374151"), dark: Color(hex: "#D1D5DB"))
    }

    static var textMuted: Color {
        Color(light: Color(hex: "#6B7280"), dark: Color(hex: "#9CA3AF"))
    }

    // MARK: - Border Colors (Adaptive)
    static var border: Color {
        Color(light: Color(hex: "#E5E7EB"), dark: Color(hex: "#374151"))
    }

    static var borderStrong: Color {
        Color(light: Color(hex: "#D1D5DB"), dark: Color(hex: "#4B5563"))
    }

    // MARK: - Map Marker Colors (Adaptive)
    static var markerActive: Color {
        Color(light: Color(hex: "#4A5568"), dark: Color(hex: "#E2E8F0"))
    }

    static var markerActiveBorder: Color {
        Color(light: Color(hex: "#2D3748"), dark: Color(hex: "#CBD5E0"))
    }

    static var markerArchived: Color {
        Color(light: Color(hex: "#D3D3D3"), dark: Color(hex: "#4A5568"))
    }

    static var markerArchivedText: Color {
        Color(light: Color(hex: "#4A4A4A"), dark: Color(hex: "#E2E8F0"))
    }

    // MARK: - Status Colors (System)
    static let success = Color.green
    static let warning = Color.orange
    static let error = Color.red
    static let info = Color.blue

    // MARK: - Property Status Colors (Adaptive - brighter in dark mode)
    static var activeStatus: Color {
        Color(light: Color(hex: "#059669"), dark: Color(hex: "#10B981"))
    }

    static var pendingStatus: Color {
        Color(light: Color(hex: "#D97706"), dark: Color(hex: "#F59E0B"))
    }

    static var soldStatus: Color {
        Color(light: Color(hex: "#DC2626"), dark: Color(hex: "#EF4444"))
    }

    // MARK: - School Rating Colors (Adaptive)
    static var schoolGreen: Color {
        Color(light: Color(hex: "#059669"), dark: Color(hex: "#10B981"))
    }

    // MARK: - Shimmer/Skeleton Colors (Adaptive)
    static var shimmerBase: Color {
        Color(light: Color.gray.opacity(0.2), dark: Color.gray.opacity(0.3))
    }

    static var shimmerHighlight: Color {
        Color(light: Color.white.opacity(0.6), dark: Color.white.opacity(0.15))
    }

    // MARK: - Shadow Colors (Adaptive)
    static var shadowLight: Color {
        Color(light: Color.black.opacity(0.1), dark: Color.black.opacity(0.4))
    }

    static var shadowMedium: Color {
        Color(light: Color.black.opacity(0.15), dark: Color.black.opacity(0.5))
    }

    // MARK: - Overlay Colors (Adaptive)
    static var overlayBackground: Color {
        Color(light: Color.black.opacity(0.3), dark: Color.black.opacity(0.5))
    }

    // MARK: - Drag Handle Color (Adaptive)
    static var dragHandle: Color {
        Color(light: Color.gray.opacity(0.4), dark: Color.gray.opacity(0.5))
    }
}

// MARK: - Color Hex Extension

extension Color {
    init(hex: String) {
        let hex = hex.trimmingCharacters(in: CharacterSet.alphanumerics.inverted)
        var int: UInt64 = 0
        Scanner(string: hex).scanHexInt64(&int)
        let a, r, g, b: UInt64
        switch hex.count {
        case 3: // RGB (12-bit)
            (a, r, g, b) = (255, (int >> 8) * 17, (int >> 4 & 0xF) * 17, (int & 0xF) * 17)
        case 6: // RGB (24-bit)
            (a, r, g, b) = (255, int >> 16, int >> 8 & 0xFF, int & 0xFF)
        case 8: // ARGB (32-bit)
            (a, r, g, b) = (int >> 24, int >> 16 & 0xFF, int >> 8 & 0xFF, int & 0xFF)
        default:
            (a, r, g, b) = (1, 1, 1, 0)
        }
        self.init(
            .sRGB,
            red: Double(r) / 255,
            green: Double(g) / 255,
            blue: Double(b) / 255,
            opacity: Double(a) / 255
        )
    }
}

// MARK: - Button Styles

struct PrimaryButtonStyle: ButtonStyle {
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.headline)
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity)
            .padding()
            .background(isEnabled ? AppColors.brandTeal : Color.gray)
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .opacity(configuration.isPressed ? 0.8 : 1.0)
            .scaleEffect(configuration.isPressed ? 0.98 : 1.0)
            .animation(.easeInOut(duration: 0.1), value: configuration.isPressed)
    }
}

struct SecondaryButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.headline)
            .foregroundStyle(AppColors.brandTeal)
            .frame(maxWidth: .infinity)
            .padding()
            .background(AppColors.brandTeal.opacity(0.1))
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .opacity(configuration.isPressed ? 0.8 : 1.0)
            .scaleEffect(configuration.isPressed ? 0.98 : 1.0)
            .animation(.easeInOut(duration: 0.1), value: configuration.isPressed)
    }
}

struct OutlineButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.headline)
            .foregroundStyle(AppColors.brandTeal)
            .frame(maxWidth: .infinity)
            .padding()
            .background(Color.clear)
            .overlay(
                RoundedRectangle(cornerRadius: 12)
                    .stroke(AppColors.brandTeal, lineWidth: 2)
            )
            .opacity(configuration.isPressed ? 0.8 : 1.0)
            .scaleEffect(configuration.isPressed ? 0.98 : 1.0)
            .animation(.easeInOut(duration: 0.1), value: configuration.isPressed)
    }
}

// MARK: - Text Field Styles

struct RoundedTextFieldStyle: TextFieldStyle {
    func _body(configuration: TextField<Self._Label>) -> some View {
        configuration
            .padding()
            .background(AppColors.secondaryBackground)
            .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}

// MARK: - View Modifiers

struct CardModifier: ViewModifier {
    func body(content: Content) -> some View {
        content
            .background(AppColors.background)
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .shadow(color: AppColors.shadowLight, radius: 8, x: 0, y: 2)
    }
}

extension View {
    func cardStyle() -> some View {
        modifier(CardModifier())
    }
}

// MARK: - Haptic Feedback

enum HapticManager {
    static func impact(_ style: UIImpactFeedbackGenerator.FeedbackStyle = .medium) {
        let generator = UIImpactFeedbackGenerator(style: style)
        generator.impactOccurred()
    }

    static func notification(_ type: UINotificationFeedbackGenerator.FeedbackType) {
        let generator = UINotificationFeedbackGenerator()
        generator.notificationOccurred(type)
    }

    static func selection() {
        let generator = UISelectionFeedbackGenerator()
        generator.selectionChanged()
    }
}

// MARK: - Preview

#Preview {
    VStack(spacing: 20) {
        Button("Primary Button") {}
            .buttonStyle(PrimaryButtonStyle())

        Button("Secondary Button") {}
            .buttonStyle(SecondaryButtonStyle())

        Button("Outline Button") {}
            .buttonStyle(OutlineButtonStyle())

        TextField("Rounded Text Field", text: .constant(""))
            .textFieldStyle(RoundedTextFieldStyle())
    }
    .padding()
}
