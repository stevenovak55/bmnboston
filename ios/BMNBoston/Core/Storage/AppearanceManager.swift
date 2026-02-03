//
//  AppearanceManager.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import SwiftUI

enum AppearanceMode: String, Codable, CaseIterable {
    case light
    case dark

    var colorScheme: ColorScheme {
        switch self {
        case .light: return .light
        case .dark: return .dark
        }
    }

    var displayName: String {
        switch self {
        case .light: return "Light"
        case .dark: return "Dark"
        }
    }

    var icon: String {
        switch self {
        case .light: return "sun.max.fill"
        case .dark: return "moon.fill"
        }
    }
}

@MainActor
class AppearanceManager: ObservableObject {
    static let shared = AppearanceManager()

    @Published var appearanceMode: AppearanceMode {
        didSet {
            savePreference()
        }
    }

    private let userDefaultsKey = "com.bmnboston.appearanceMode"

    private init() {
        if let savedMode = UserDefaults.standard.string(forKey: userDefaultsKey),
           let mode = AppearanceMode(rawValue: savedMode) {
            self.appearanceMode = mode
        } else {
            self.appearanceMode = .light
        }
    }

    private func savePreference() {
        UserDefaults.standard.set(appearanceMode.rawValue, forKey: userDefaultsKey)
    }

    var colorScheme: ColorScheme {
        appearanceMode.colorScheme
    }
}
