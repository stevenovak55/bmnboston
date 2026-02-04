//
//  NotificationCenterView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Notification Center - Main View
//

import SwiftUI

struct NotificationCenterView: View {
    @StateObject private var store = NotificationStore.shared
    @Environment(\.dismiss) private var dismiss
    @EnvironmentObject var propertySearchViewModel: PropertySearchViewModel

    /// When true, shows as modal sheet with Close button. When false, shows as tab content.
    var isSheet: Bool = true

    @State private var showingClearConfirmation = false
    @State private var showNotificationSettings = false

    var body: some View {
        Group {
            if isSheet {
                // Modal sheet presentation with Close button
                NavigationView {
                    notificationContent
                        .navigationTitle("Notifications")
                        .navigationBarTitleDisplayMode(.inline)
                        .toolbar {
                            ToolbarItem(placement: .navigationBarLeading) {
                                Button("Close") {
                                    dismiss()
                                }
                            }

                            if !store.notifications.isEmpty {
                                trailingToolbarItem
                            }
                        }
                }
            } else {
                // Tab content - no Close button, uses NavigationStack
                NavigationStack {
                    notificationContent
                        .navigationTitle("Notifications")
                        .toolbar {
                            ToolbarItem(placement: .navigationBarTrailing) {
                                HStack(spacing: 16) {
                                    // Settings button
                                    Button {
                                        showNotificationSettings = true
                                    } label: {
                                        Image(systemName: "gearshape")
                                    }

                                    // Menu button (only if there are notifications)
                                    if !store.notifications.isEmpty {
                                        Menu {
                                            Button {
                                                Task {
                                                    await store.markAllAsRead()
                                                }
                                            } label: {
                                                Label("Mark All as Read", systemImage: "checkmark.circle")
                                            }

                                            Button(role: .destructive) {
                                                showingClearConfirmation = true
                                            } label: {
                                                Label("Clear All", systemImage: "trash")
                                            }
                                        } label: {
                                            Image(systemName: "ellipsis.circle")
                                        }
                                    }
                                }
                            }
                        }
                }
            }
        }
        .sheet(isPresented: $showNotificationSettings) {
            NotificationPreferencesView()
        }
        .confirmationDialog(
            "Clear All Notifications?",
            isPresented: $showingClearConfirmation,
            titleVisibility: .visible
        ) {
            Button("Clear All", role: .destructive) {
                Task {
                    await store.clearAll()
                }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("This will remove all notifications. This action cannot be undone.")
        }
        .task {
            // Sync from server when view appears
            await store.syncFromServer()
        }
    }

    // MARK: - Trailing Toolbar

    private var trailingToolbarItem: some ToolbarContent {
        ToolbarItem(placement: .navigationBarTrailing) {
            Menu {
                Button {
                    Task {
                        await store.markAllAsRead()
                    }
                } label: {
                    Label("Mark All as Read", systemImage: "checkmark.circle")
                }

                Button(role: .destructive) {
                    showingClearConfirmation = true
                } label: {
                    Label("Clear All", systemImage: "trash")
                }
            } label: {
                Image(systemName: "ellipsis.circle")
            }
        }
    }

    // MARK: - Notification Content

    @ViewBuilder
    private var notificationContent: some View {
        if store.isSyncing && store.notifications.isEmpty {
            // Initial sync loading state
            ProgressView("Loading notifications...")
        } else if let error = store.syncError, store.notifications.isEmpty {
            // Show error state
            VStack(spacing: 16) {
                Image(systemName: "exclamationmark.triangle")
                    .font(.system(size: 50))
                    .foregroundStyle(.orange)
                Text("Sync Error")
                    .font(.title2)
                    .fontWeight(.semibold)
                Text(error)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 40)
                Button("Retry") {
                    Task {
                        await store.syncFromServer()
                    }
                }
                .buttonStyle(.borderedProminent)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        } else if store.notifications.isEmpty {
            emptyState
        } else {
            notificationList
        }
    }

    // MARK: - Empty State

    private var emptyState: some View {
        VStack(spacing: 16) {
            Image(systemName: "bell.slash")
                .font(.system(size: 60))
                .foregroundStyle(.secondary)

            Text("No Notifications")
                .font(.title2)
                .fontWeight(.semibold)

            Text("You'll see notifications here when you receive alerts about saved searches, appointments, and more.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 40)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Notification List

    private var notificationList: some View {
        List {
            if !store.todayNotifications.isEmpty {
                Section("Today") {
                    ForEach(store.todayNotifications) { notification in
                        NotificationRow(notification: notification) {
                            handleNotificationTap(notification)
                        }
                        .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                            Button(role: .destructive) {
                                Task {
                                    await store.dismiss(notification)
                                }
                            } label: {
                                Label("Delete", systemImage: "trash")
                            }
                        }
                        .swipeActions(edge: .leading, allowsFullSwipe: true) {
                            if !notification.isRead {
                                Button {
                                    Task {
                                        await store.markAsRead(notification)
                                    }
                                } label: {
                                    Label("Read", systemImage: "checkmark")
                                }
                                .tint(.blue)
                            }
                        }
                    }
                }
            }

            if !store.earlierNotifications.isEmpty {
                Section("Earlier") {
                    ForEach(store.earlierNotifications) { notification in
                        NotificationRow(notification: notification) {
                            handleNotificationTap(notification)
                        }
                        .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                            Button(role: .destructive) {
                                Task {
                                    await store.dismiss(notification)
                                }
                            } label: {
                                Label("Delete", systemImage: "trash")
                            }
                        }
                        .swipeActions(edge: .leading, allowsFullSwipe: true) {
                            if !notification.isRead {
                                Button {
                                    Task {
                                        await store.markAsRead(notification)
                                    }
                                } label: {
                                    Label("Read", systemImage: "checkmark")
                                }
                                .tint(.blue)
                            }
                        }
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
        .refreshable {
            await store.syncFromServer()
        }
    }

    // MARK: - Navigation

    private func handleNotificationTap(_ notification: NotificationItem) {
        // Mark as read (async, fire and forget)
        Task {
            await store.markAsRead(notification)
        }

        // Handle navigation based on type
        switch notification.type {
        case .savedSearch:
            if let searchId = notification.savedSearchId {
                dismiss()
                // Navigate to saved search
                NotificationCenter.default.post(
                    name: .navigateToSavedSearch,
                    object: nil,
                    userInfo: ["search_id": searchId]
                )
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
            }

        case .appointmentReminder:
            if let appointmentId = notification.appointmentId {
                dismiss()
                // Set pending appointment navigation and switch to Appointments tab
                NotificationStore.shared.setPendingAppointmentNavigation(appointmentId: appointmentId)
                NotificationCenter.default.post(name: .switchToAppointmentsTab, object: nil)
            }

        case .agentActivity:
            if let clientId = notification.clientId {
                dismiss()
                // Set pending client navigation and switch to My Clients tab
                NotificationStore.shared.setPendingClientNavigation(clientId: clientId)
                NotificationCenter.default.post(name: .switchToMyClientsTab, object: nil)
            } else if notification.listingKey != nil || notification.listingId != nil {
                dismiss()
                // Navigate to property detail
                NotificationStore.shared.setPendingPropertyNavigation(
                    listingId: notification.listingId,
                    listingKey: notification.listingKey
                )
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
            }

        case .newListing, .priceChange, .statusChange, .openHouse:
            if notification.listingKey != nil || notification.listingId != nil {
                dismiss()
                // Navigate to property detail
                NotificationStore.shared.setPendingPropertyNavigation(
                    listingId: notification.listingId,
                    listingKey: notification.listingKey
                )
                NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
            }

        case .general:
            // Just mark as read, no navigation
            break
        }
    }
}

// MARK: - Notification Row

struct NotificationRow: View {
    let notification: NotificationItem
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            HStack(alignment: .top, spacing: 12) {
                // Icon
                ZStack {
                    Circle()
                        .fill(iconBackgroundColor)
                        .frame(width: 40, height: 40)

                    Image(systemName: notification.type.icon)
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundStyle(iconForegroundColor)
                }

                // Content
                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        Text(notification.title)
                            .font(.subheadline)
                            .fontWeight(notification.isRead ? .regular : .semibold)
                            .foregroundStyle(.primary)

                        Spacer()

                        Text(notification.timeAgo)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }

                    Text(notification.body)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(2)
                }

                // Unread indicator
                if !notification.isRead {
                    Circle()
                        .fill(Color.blue)
                        .frame(width: 8, height: 8)
                }
            }
            .padding(.vertical, 4)
        }
        .buttonStyle(.plain)
    }

    private var iconBackgroundColor: Color {
        switch notification.type {
        case .savedSearch: return .blue.opacity(0.15)
        case .appointmentReminder: return .orange.opacity(0.15)
        case .agentActivity: return .purple.opacity(0.15)
        case .newListing: return .green.opacity(0.15)
        case .priceChange: return .red.opacity(0.15)
        case .statusChange: return .yellow.opacity(0.15)
        case .openHouse: return .teal.opacity(0.15)
        case .general: return .gray.opacity(0.15)
        }
    }

    private var iconForegroundColor: Color {
        switch notification.type {
        case .savedSearch: return .blue
        case .appointmentReminder: return .orange
        case .agentActivity: return .purple
        case .newListing: return .green
        case .priceChange: return .red
        case .statusChange: return .yellow
        case .openHouse: return .teal
        case .general: return .gray
        }
    }
}

// MARK: - Notification Names

extension Notification.Name {
    static let switchToAppointmentsTab = Notification.Name("switchToAppointmentsTab")
    static let switchToProfileTab = Notification.Name("switchToProfileTab")
}

// MARK: - Preview

#Preview {
    NotificationCenterView()
        .environmentObject(PropertySearchViewModel())
}
