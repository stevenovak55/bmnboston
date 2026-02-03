//
//  ClientAnalyticsView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Sprint 5: Client Analytics
//

import SwiftUI

// MARK: - Client Analytics View

struct ClientAnalyticsView: View {
    let client: AgentClient

    @Environment(\.dismiss) private var dismiss
    @State private var analytics: ClientAnalytics?
    @State private var activities: [ClientActivityEvent] = []
    @State private var isLoading = true
    @State private var errorMessage: String?
    @State private var selectedPeriod: Int = 30

    private let periodOptions = [7, 14, 30, 90]

    var body: some View {
        NavigationStack {
            Group {
                if isLoading {
                    loadingView
                } else if let error = errorMessage {
                    errorView(error)
                } else if let analytics = analytics {
                    analyticsContent(analytics)
                } else {
                    noDataView
                }
            }
            .navigationTitle("Analytics")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Done") {
                        dismiss()
                    }
                }
            }
            .task {
                await loadAnalytics()
            }
        }
    }

    // MARK: - Loading View

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Loading analytics...")
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - Error View

    private func errorView(_ error: String) -> some View {
        VStack(spacing: 20) {
            Image(systemName: "exclamationmark.triangle")
                .font(.system(size: 50))
                .foregroundStyle(.orange)

            Text("Unable to Load Analytics")
                .font(.title2)
                .fontWeight(.semibold)

            Text(error)
                .font(.body)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 40)

            Button("Try Again") {
                Task {
                    await loadAnalytics()
                }
            }
            .buttonStyle(.borderedProminent)
            .tint(AppColors.brandTeal)
        }
    }

    // MARK: - No Data View

    private var noDataView: some View {
        VStack(spacing: 20) {
            Image(systemName: "chart.bar.xaxis")
                .font(.system(size: 60))
                .foregroundStyle(.secondary)

            Text("No Activity Yet")
                .font(.title2)
                .fontWeight(.semibold)

            Text("\(client.displayName) hasn't had any activity to track yet.")
                .font(.body)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 40)
        }
    }

    // MARK: - Analytics Content

    private func analyticsContent(_ analytics: ClientAnalytics) -> some View {
        List {
            // Client header with engagement score
            Section {
                clientHeaderView(analytics)
            }
            .listRowBackground(Color.clear)

            // Period selector
            Section {
                Picker("Time Period", selection: $selectedPeriod) {
                    ForEach(periodOptions, id: \.self) { days in
                        Text("\(days) Days").tag(days)
                    }
                }
                .pickerStyle(.segmented)
                .onChange(of: selectedPeriod) { _ in
                    Task {
                        await loadAnalytics()
                    }
                }
            }

            // Engagement summary
            Section("Engagement Summary") {
                HStack(spacing: 16) {
                    EngagementStatCard(
                        title: "Sessions",
                        value: "\(analytics.totalSessions)",
                        icon: "iphone",
                        color: .blue
                    )
                    EngagementStatCard(
                        title: "Time",
                        value: formatDuration(analytics.totalDurationMinutes),
                        icon: "clock.fill",
                        color: .green
                    )
                }
                .padding(.vertical, 4)

                HStack(spacing: 16) {
                    EngagementStatCard(
                        title: "Properties",
                        value: "\(analytics.propertiesViewed)",
                        icon: "house.fill",
                        color: .purple
                    )
                    EngagementStatCard(
                        title: "Searches",
                        value: "\(analytics.searchesRun)",
                        icon: "magnifyingglass",
                        color: .orange
                    )
                }
                .padding(.vertical, 4)
            }
            .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))

            // Platform breakdown
            if let platforms = analytics.platformBreakdown, !platforms.isEmpty {
                Section("Platform Usage") {
                    ForEach(Array(platforms.keys.sorted()), id: \.self) { platform in
                        HStack {
                            Image(systemName: platformIcon(platform))
                                .foregroundStyle(platformColor(platform))
                                .frame(width: 24)
                            Text(platform.capitalized)
                            Spacer()
                            Text("\(platforms[platform] ?? 0) activities")
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }

            // Activity breakdown
            if let breakdown = analytics.activityBreakdown, !breakdown.isEmpty {
                Section("Activity Breakdown") {
                    ForEach(Array(breakdown.keys.sorted()), id: \.self) { activity in
                        HStack {
                            Image(systemName: activityIcon(activity))
                                .foregroundStyle(activityColor(activity))
                                .frame(width: 24)
                            Text(activityLabel(activity))
                            Spacer()
                            Text("\(breakdown[activity] ?? 0)")
                                .fontWeight(.semibold)
                        }
                    }
                }
            }

            // Recent activity timeline
            if !activities.isEmpty {
                Section("Recent Activity") {
                    ForEach(activities.prefix(10)) { activity in
                        ActivityTimelineRow(activity: activity)
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
    }

    // MARK: - Client Header View

    private func clientHeaderView(_ analytics: ClientAnalytics) -> some View {
        VStack(spacing: 16) {
            // Avatar
            Circle()
                .fill(AppColors.brandTeal.opacity(0.2))
                .frame(width: 80, height: 80)
                .overlay {
                    Text(client.initials)
                        .font(.largeTitle)
                        .foregroundStyle(AppColors.brandTeal)
                }

            // Name
            Text(client.displayName)
                .font(.title2)
                .fontWeight(.bold)

            // Engagement score
            VStack(spacing: 4) {
                Text("Engagement Score")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                HStack(alignment: .firstTextBaseline, spacing: 4) {
                    Text("\(Int(analytics.engagementScore))")
                        .font(.system(size: 48, weight: .bold))
                        .foregroundStyle(engagementColor(analytics.engagementScore))

                    Text("/ 100")
                        .font(.title3)
                        .foregroundStyle(.secondary)
                }

                Text(engagementLabel(analytics.engagementScore))
                    .font(.subheadline)
                    .foregroundStyle(engagementColor(analytics.engagementScore))
            }
            .padding()
            .background(
                RoundedRectangle(cornerRadius: 16)
                    .fill(engagementColor(analytics.engagementScore).opacity(0.1))
            )

            // Last activity
            if let lastActivity = analytics.lastActivity {
                Text("Last active: \(lastActivity)")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 8)
    }

    // MARK: - Helpers

    private func formatDuration(_ minutes: Double) -> String {
        if minutes < 60 {
            return "\(Int(minutes))m"
        } else {
            let hours = Int(minutes / 60)
            let mins = Int(minutes.truncatingRemainder(dividingBy: 60))
            return mins > 0 ? "\(hours)h \(mins)m" : "\(hours)h"
        }
    }

    private func engagementColor(_ score: Double) -> Color {
        switch score {
        case 80...: return .green
        case 60..<80: return .blue
        case 40..<60: return .yellow
        case 20..<40: return .orange
        default: return .red
        }
    }

    private func engagementLabel(_ score: Double) -> String {
        switch score {
        case 80...: return "Highly Engaged"
        case 60..<80: return "Very Active"
        case 40..<60: return "Moderately Active"
        case 20..<40: return "Low Activity"
        default: return "Minimal Activity"
        }
    }

    private func platformIcon(_ platform: String) -> String {
        switch platform.lowercased() {
        case "ios": return "iphone"
        case "web": return "globe"
        default: return "device.unknown"
        }
    }

    private func platformColor(_ platform: String) -> Color {
        switch platform.lowercased() {
        case "ios": return .blue
        case "web": return .purple
        default: return .gray
        }
    }

    private func activityIcon(_ type: String) -> String {
        switch type {
        case "property_view": return "eye.fill"
        case "search_run": return "magnifyingglass"
        case "filter_used": return "slider.horizontal.3"
        case "favorite_add": return "heart.fill"
        case "favorite_remove": return "heart.slash"
        case "hidden_add": return "eye.slash.fill"
        case "hidden_remove": return "eye.fill"
        case "search_save": return "bookmark.fill"
        case "login": return "person.fill.checkmark"
        case "page_view": return "doc.text.fill"
        default: return "circle.fill"
        }
    }

    private func activityColor(_ type: String) -> Color {
        switch type {
        case "property_view": return .blue
        case "search_run": return .orange
        case "filter_used": return .purple
        case "favorite_add": return .red
        case "favorite_remove": return .gray
        case "hidden_add": return .gray
        case "hidden_remove": return .blue
        case "search_save": return .green
        case "login": return .green
        case "page_view": return .secondary
        default: return .secondary
        }
    }

    private func activityLabel(_ type: String) -> String {
        switch type {
        case "property_view": return "Properties Viewed"
        case "search_run": return "Searches Run"
        case "filter_used": return "Filters Applied"
        case "favorite_add": return "Favorites Added"
        case "favorite_remove": return "Favorites Removed"
        case "hidden_add": return "Properties Hidden"
        case "hidden_remove": return "Properties Unhidden"
        case "search_save": return "Searches Saved"
        case "login": return "Logins"
        case "page_view": return "Pages Viewed"
        default: return type.replacingOccurrences(of: "_", with: " ").capitalized
        }
    }

    // MARK: - Data Loading

    private func loadAnalytics() async {
        isLoading = true
        errorMessage = nil

        do {
            async let analyticsRequest = AgentService.shared.fetchClientAnalytics(clientId: client.id, days: selectedPeriod)
            async let activitiesRequest = AgentService.shared.fetchClientActivityTimeline(clientId: client.id, limit: 20)

            let (fetchedAnalytics, fetchedActivities) = try await (analyticsRequest, activitiesRequest)
            analytics = fetchedAnalytics
            activities = fetchedActivities
        } catch {
            errorMessage = error.userFriendlyMessage
        }

        isLoading = false
    }
}

// MARK: - Engagement Stat Card

private struct EngagementStatCard: View {
    let title: String
    let value: String
    let icon: String
    let color: Color

    var body: some View {
        VStack(spacing: 4) {
            Image(systemName: icon)
                .font(.title2)
                .foregroundStyle(color)

            Text(value)
                .font(.title2)
                .fontWeight(.bold)

            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 12)
        .background(color.opacity(0.1))
        .cornerRadius(12)
    }
}

// MARK: - Activity Timeline Row

private struct ActivityTimelineRow: View {
    let activity: ClientActivityEvent

    var body: some View {
        HStack(spacing: 12) {
            // Icon
            Image(systemName: activity.icon)
                .font(.body)
                .foregroundStyle(activity.color)
                .frame(width: 28, height: 28)
                .background(activity.color.opacity(0.15))
                .clipShape(Circle())

            // Content
            VStack(alignment: .leading, spacing: 2) {
                Text(activity.description)
                    .font(.subheadline)

                HStack(spacing: 4) {
                    Text(activity.formattedTime)
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    Text("•")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    Image(systemName: activity.platformIcon)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Spacer()
        }
        .padding(.vertical, 2)
    }
}

// MARK: - ClientActivityEvent Extensions

extension ClientActivityEvent {
    var color: Color {
        switch activityType {
        case "property_view": return .blue
        case "search_run": return .orange
        case "filter_used": return .purple
        case "favorite_add": return .red
        case "favorite_remove": return .gray
        case "hidden_add": return .gray
        case "hidden_remove": return .blue
        case "search_save": return .green
        case "login": return .green
        case "page_view": return .secondary
        default: return .secondary
        }
    }

    var platformIcon: String {
        switch platform.lowercased() {
        case "ios": return "iphone"
        case "web": return "globe"
        default: return "questionmark.circle"
        }
    }

    var formattedTime: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd'T'HH:mm:ss"
        if let date = formatter.date(from: createdAt) {
            let now = Date()
            let calendar = Calendar.current
            let components = calendar.dateComponents([.minute, .hour, .day], from: date, to: now)

            if let days = components.day, days > 0 {
                return days == 1 ? "Yesterday" : "\(days) days ago"
            } else if let hours = components.hour, hours > 0 {
                return hours == 1 ? "1 hour ago" : "\(hours) hours ago"
            } else if let minutes = components.minute, minutes > 0 {
                return minutes == 1 ? "1 min ago" : "\(minutes) min ago"
            } else {
                return "Just now"
            }
        }
        return createdAt
    }
}

// MARK: - Preview

#Preview {
    ClientAnalyticsView(client: AgentClient(
        id: 1,
        email: "test@example.com",
        firstName: "John",
        lastName: "Doe",
        phone: "555-1234",
        searchesCount: 5,
        favoritesCount: 10,
        hiddenCount: 2,
        lastActivity: nil,
        assignedAt: nil
    ))
}
