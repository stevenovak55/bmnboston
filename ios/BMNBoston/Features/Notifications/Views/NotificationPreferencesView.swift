//
//  NotificationPreferencesView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Notification Preferences - User Settings for Notification Types and Quiet Hours
//

import SwiftUI

// MARK: - Models

struct NotificationPreferencesResponse: Codable {
    let notificationTypes: [String: NotificationTypePreference]
    let quietHours: QuietHoursPreference
    let timezone: String
    let timezoneOptions: [String: String]

    private enum CodingKeys: String, CodingKey {
        case notificationTypes = "notification_types"
        case quietHours = "quiet_hours"
        case timezone
        case timezoneOptions = "timezone_options"
    }
}

struct NotificationTypePreference: Codable, Identifiable {
    let label: String
    let description: String
    let icon: String
    var pushEnabled: Bool
    var emailEnabled: Bool

    var id: String { label }

    private enum CodingKeys: String, CodingKey {
        case label, description, icon
        case pushEnabled = "push_enabled"
        case emailEnabled = "email_enabled"
    }
}

struct QuietHoursPreference: Codable {
    var enabled: Bool
    var start: String
    var end: String
}

// MARK: - ViewModel

@MainActor
class NotificationPreferencesViewModel: ObservableObject {
    @Published var notificationTypes: [String: NotificationTypePreference] = [:]
    @Published var quietHours = QuietHoursPreference(enabled: false, start: "22:00", end: "08:00")
    @Published var selectedTimezone: String = "America/New_York"
    @Published var timezoneOptions: [String: String] = [:]
    @Published var isLoading = true
    @Published var isSaving = false
    @Published var error: String?
    @Published var showSaveSuccess = false

    // Order for display
    let typeOrder = ["saved_search", "price_change", "status_change", "open_house", "new_listing"]

    var sortedTypes: [(key: String, value: NotificationTypePreference)] {
        typeOrder.compactMap { key in
            if let value = notificationTypes[key] {
                return (key, value)
            }
            return nil
        }
    }

    func loadPreferences() async {
        isLoading = true
        error = nil

        do {
            let response: NotificationPreferencesResponse = try await APIClient.shared.request(.notificationPreferences)
            notificationTypes = response.notificationTypes
            quietHours = response.quietHours
            selectedTimezone = response.timezone
            timezoneOptions = response.timezoneOptions
        } catch {
            self.error = error.userFriendlyMessage
        }

        isLoading = false
    }

    func savePreferences() async {
        isSaving = true
        error = nil

        // Build notification types dict for API
        var typesDict: [String: [String: Bool]] = [:]
        for (key, pref) in notificationTypes {
            typesDict[key] = [
                "push_enabled": pref.pushEnabled,
                "email_enabled": pref.emailEnabled
            ]
        }

        // Build quiet hours dict for API
        let quietHoursDict: [String: Any] = [
            "enabled": quietHours.enabled,
            "start": quietHours.start,
            "end": quietHours.end
        ]

        do {
            let _: NotificationPreferencesResponse = try await APIClient.shared.request(
                .updateNotificationPreferences(
                    notificationTypes: typesDict,
                    quietHours: quietHoursDict,
                    timezone: selectedTimezone
                )
            )
            showSaveSuccess = true
            // Auto-dismiss success message after 2 seconds
            Task {
                try? await Task.sleep(nanoseconds: 2_000_000_000)
                showSaveSuccess = false
            }
        } catch {
            self.error = error.userFriendlyMessage
        }

        isSaving = false
    }

    func togglePush(for key: String) {
        guard var pref = notificationTypes[key] else { return }
        pref.pushEnabled.toggle()
        notificationTypes[key] = pref
    }

    func toggleEmail(for key: String) {
        guard var pref = notificationTypes[key] else { return }
        pref.emailEnabled.toggle()
        notificationTypes[key] = pref
    }
}

// MARK: - View

struct NotificationPreferencesView: View {
    @Environment(\.dismiss) private var dismiss
    @StateObject private var viewModel = NotificationPreferencesViewModel()

    var body: some View {
        NavigationStack {
            Group {
                if viewModel.isLoading {
                    loadingView
                } else if let error = viewModel.error, viewModel.notificationTypes.isEmpty {
                    errorView(error)
                } else {
                    preferencesForm
                }
            }
            .navigationTitle("Notification Settings")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Cancel") {
                        dismiss()
                    }
                }

                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        Task {
                            await viewModel.savePreferences()
                        }
                    } label: {
                        if viewModel.isSaving {
                            ProgressView()
                        } else {
                            Text("Save")
                        }
                    }
                    .disabled(viewModel.isSaving || viewModel.isLoading)
                }
            }
        }
        .task {
            await viewModel.loadPreferences()
        }
    }

    // MARK: - Loading View

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Loading preferences...")
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - Error View

    private func errorView(_ message: String) -> some View {
        VStack(spacing: 16) {
            Image(systemName: "exclamationmark.triangle")
                .font(.system(size: 48))
                .foregroundStyle(.orange)

            Text("Unable to Load")
                .font(.headline)

            Text(message)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)

            Button("Try Again") {
                Task {
                    await viewModel.loadPreferences()
                }
            }
            .buttonStyle(.borderedProminent)
        }
    }

    // MARK: - Preferences Form

    private var preferencesForm: some View {
        Form {
            // Success banner
            if viewModel.showSaveSuccess {
                Section {
                    HStack {
                        Image(systemName: "checkmark.circle.fill")
                            .foregroundStyle(.green)
                        Text("Preferences saved")
                            .foregroundStyle(.green)
                    }
                }
            }

            // Error banner
            if let error = viewModel.error {
                Section {
                    HStack {
                        Image(systemName: "exclamationmark.triangle.fill")
                            .foregroundStyle(.orange)
                        Text(error)
                            .foregroundStyle(.orange)
                            .font(.caption)
                    }
                }
            }

            // Notification Types
            Section {
                ForEach(viewModel.sortedTypes, id: \.key) { key, pref in
                    notificationTypeRow(key: key, preference: pref)
                }
            } header: {
                Text("Alert Types")
            } footer: {
                Text("Control which notifications you receive via push and email.")
            }

            // Quiet Hours
            Section {
                Toggle(isOn: $viewModel.quietHours.enabled) {
                    HStack {
                        Image(systemName: "moon.fill")
                            .foregroundStyle(.indigo)
                            .frame(width: 28)
                        VStack(alignment: .leading) {
                            Text("Quiet Hours")
                            Text("Pause push notifications during set times")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                if viewModel.quietHours.enabled {
                    HStack {
                        Text("Start")
                        Spacer()
                        TimePicker(time: $viewModel.quietHours.start)
                    }

                    HStack {
                        Text("End")
                        Spacer()
                        TimePicker(time: $viewModel.quietHours.end)
                    }
                }
            } header: {
                Text("Quiet Hours")
            } footer: {
                if viewModel.quietHours.enabled {
                    Text("Push notifications will be held during quiet hours and delivered when they end. Email notifications are not affected.")
                }
            }

            // Timezone
            Section {
                Picker("Timezone", selection: $viewModel.selectedTimezone) {
                    ForEach(Array(viewModel.timezoneOptions.sorted(by: { $0.key < $1.key })), id: \.key) { key, label in
                        Text(label).tag(key)
                    }
                }
            } header: {
                Text("Timezone")
            } footer: {
                Text("Used for quiet hours timing.")
            }
        }
    }

    // MARK: - Notification Type Row

    private func notificationTypeRow(key: String, preference: NotificationTypePreference) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Image(systemName: iconName(for: preference.icon))
                    .foregroundStyle(iconColor(for: key))
                    .frame(width: 28)

                VStack(alignment: .leading) {
                    Text(preference.label)
                        .font(.subheadline)
                        .fontWeight(.medium)
                    Text(preference.description)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            HStack(spacing: 16) {
                Button {
                    viewModel.togglePush(for: key)
                } label: {
                    HStack(spacing: 4) {
                        Image(systemName: preference.pushEnabled ? "bell.fill" : "bell.slash")
                            .font(.caption)
                        Text("Push")
                            .font(.caption)
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 6)
                    .background(preference.pushEnabled ? Color.blue.opacity(0.15) : Color.gray.opacity(0.1))
                    .foregroundStyle(preference.pushEnabled ? .blue : .secondary)
                    .clipShape(Capsule())
                }
                .buttonStyle(.plain)

                Button {
                    viewModel.toggleEmail(for: key)
                } label: {
                    HStack(spacing: 4) {
                        Image(systemName: preference.emailEnabled ? "envelope.fill" : "envelope.badge.shield.half.filled")
                            .font(.caption)
                        Text("Email")
                            .font(.caption)
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 6)
                    .background(preference.emailEnabled ? Color.green.opacity(0.15) : Color.gray.opacity(0.1))
                    .foregroundStyle(preference.emailEnabled ? .green : .secondary)
                    .clipShape(Capsule())
                }
                .buttonStyle(.plain)

                Spacer()
            }
            .padding(.leading, 36)
        }
        .padding(.vertical, 4)
    }

    // MARK: - Helpers

    private func iconName(for icon: String) -> String {
        // Map PHP SF Symbol names to valid iOS ones
        switch icon {
        case "magnifyingglass": return "magnifyingglass"
        case "tag": return "tag.fill"
        case "arrow.triangle.swap": return "arrow.triangle.swap"
        case "door.left.hand.open": return "door.left.hand.open"
        case "house": return "house.fill"
        default: return "bell.fill"
        }
    }

    private func iconColor(for key: String) -> Color {
        switch key {
        case "saved_search": return .blue
        case "price_change": return .red
        case "status_change": return .yellow
        case "open_house": return .teal
        case "new_listing": return .green
        default: return .gray
        }
    }
}

// MARK: - Time Picker Component

struct TimePicker: View {
    @Binding var time: String

    private var hours: Int {
        let parts = time.split(separator: ":")
        return parts.count > 0 ? Int(parts[0]) ?? 0 : 0
    }

    private var minutes: Int {
        let parts = time.split(separator: ":")
        return parts.count > 1 ? Int(parts[1]) ?? 0 : 0
    }

    var body: some View {
        HStack(spacing: 2) {
            Picker("Hour", selection: Binding(
                get: { hours },
                set: { newHour in
                    time = String(format: "%02d:%02d", newHour, minutes)
                }
            )) {
                ForEach(0..<24, id: \.self) { hour in
                    Text(String(format: "%02d", hour)).tag(hour)
                }
            }
            .pickerStyle(.wheel)
            .frame(width: 60)
            .clipped()

            Text(":")

            Picker("Minute", selection: Binding(
                get: { minutes },
                set: { newMinute in
                    time = String(format: "%02d:%02d", hours, newMinute)
                }
            )) {
                ForEach([0, 15, 30, 45], id: \.self) { minute in
                    Text(String(format: "%02d", minute)).tag(minute)
                }
            }
            .pickerStyle(.wheel)
            .frame(width: 60)
            .clipped()
        }
        .frame(height: 100)
    }
}

// MARK: - Preview

#Preview {
    NotificationPreferencesView()
}
