//
//  OpenHouseSummaryView.swift
//  BMNBoston
//
//  Post-open-house summary report view
//  Shows metrics, hot leads, marketing breakdown, and full attendee list
//
//  VERSION: v6.76.0
//

import SwiftUI

struct OpenHouseSummaryView: View {
    let summary: OpenHouseSummary
    let openHouse: OpenHouse
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                // Header
                headerSection

                // Key stats
                statsCardsSection

                // Hot leads
                if !summary.hotLeads.isEmpty {
                    hotLeadsSection
                }

                // Interest breakdown
                if !summary.interestBreakdown.isEmpty {
                    breakdownSection(
                        title: "Interest Level",
                        icon: "flame.fill",
                        data: summary.interestBreakdown,
                        colorForKey: colorForInterest
                    )
                }

                // Buying timeline
                if !summary.timelineBreakdown.isEmpty {
                    breakdownSection(
                        title: "Buying Timeline",
                        icon: "clock.fill",
                        data: summary.timelineBreakdown,
                        colorForKey: colorForTimeline
                    )
                }

                // Marketing sources
                if !summary.sourceBreakdown.isEmpty {
                    breakdownSection(
                        title: "How They Heard About It",
                        icon: "megaphone.fill",
                        data: summary.sourceBreakdown,
                        colorForKey: { _ in AppColors.brandTeal }
                    )
                }

                // Full attendee list
                if !summary.allAttendees.isEmpty {
                    allAttendeesSection
                }
            }
            .padding()
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle("Summary Report")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button {
                    dismiss()
                } label: {
                    Image(systemName: "xmark.circle.fill")
                        .font(.title2)
                        .symbolRenderingMode(.hierarchical)
                        .foregroundStyle(.secondary)
                }
            }
            ToolbarItem(placement: .topBarTrailing) {
                ShareLink(item: shareText) {
                    Image(systemName: "square.and.arrow.up")
                }
            }
        }
    }

    // MARK: - Header

    @ViewBuilder
    private var headerSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            if let address = summary.propertyAddress {
                Text(address)
                    .font(.title3)
                    .fontWeight(.bold)
            }
            if let date = summary.openHouseDate {
                HStack(spacing: 6) {
                    Image(systemName: "calendar")
                        .foregroundStyle(.secondary)
                    Text(date)
                        .foregroundStyle(.secondary)
                }
                .font(.subheadline)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding()
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Stats Cards

    @ViewBuilder
    private var statsCardsSection: some View {
        LazyVGrid(columns: [
            GridItem(.flexible()),
            GridItem(.flexible()),
            GridItem(.flexible())
        ], spacing: 12) {
            StatCard(value: "\(summary.totalAttendees)", label: "Total", icon: "person.3.fill", color: AppColors.brandTeal)
            StatCard(value: "\(summary.buyerCount)", label: "Buyers", icon: "person.fill", color: .blue)
            StatCard(value: "\(summary.agentCount)", label: "Agents", icon: "person.crop.rectangle.fill", color: .purple)
            StatCard(value: "\(summary.unrepresentedBuyerCount)", label: "No Agent", icon: "person.fill.questionmark", color: .orange)
            StatCard(value: "\(summary.preApprovedCount)", label: "Pre-Approved", icon: "checkmark.seal.fill", color: .green)
            StatCard(value: "\(summary.consentToFollowUpCount)", label: "Follow-Up OK", icon: "envelope.fill", color: .indigo)
        }
    }

    // MARK: - Hot Leads

    @ViewBuilder
    private var hotLeadsSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Image(systemName: "flame.fill")
                    .foregroundStyle(.orange)
                Text("Hot Leads")
                    .font(.headline)
                Text("(\(summary.hotLeads.count))")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            ForEach(summary.hotLeads) { lead in
                HotLeadRow(lead: lead)
            }
        }
        .padding()
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Breakdown Section

    private func breakdownSection(
        title: String,
        icon: String,
        data: [String: Int],
        colorForKey: @escaping (String) -> Color
    ) -> some View {
        BreakdownSectionView(
            title: title,
            icon: icon,
            data: data,
            colorForKey: colorForKey
        )
    }

    // MARK: - All Attendees

    @ViewBuilder
    private var allAttendeesSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Image(systemName: "person.3.fill")
                    .foregroundStyle(.secondary)
                Text("All Attendees")
                    .font(.headline)
                Text("(\(summary.allAttendees.count))")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            ForEach(summary.allAttendees) { attendee in
                SummaryAttendeeRow(attendee: attendee)
                if attendee.id != summary.allAttendees.last?.id {
                    Divider()
                }
            }
        }
        .padding()
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Share Text

    private var shareText: String {
        var text = "Open House Summary\n"
        if let address = summary.propertyAddress {
            text += "\(address)\n"
        }
        if let date = summary.openHouseDate {
            text += "\(date)\n"
        }
        text += "\nAttendees: \(summary.totalAttendees)"
        text += "\nBuyers: \(summary.buyerCount)"
        text += "\nAgents: \(summary.agentCount)"
        text += "\nUnrepresented: \(summary.unrepresentedBuyerCount)"
        text += "\nPre-Approved: \(summary.preApprovedCount)"

        if !summary.hotLeads.isEmpty {
            text += "\n\nHot Leads:"
            for lead in summary.hotLeads {
                text += "\n- \(lead.fullName) (\(lead.email))"
                if !lead.buyingTimeline.isEmpty {
                    text += " - \(formatBreakdownKey(lead.buyingTimeline))"
                }
            }
        }
        return text
    }

    // MARK: - Helpers

    private func formatBreakdownKey(_ key: String) -> String {
        key.replacingOccurrences(of: "_", with: " ")
            .split(separator: " ")
            .map { $0.prefix(1).uppercased() + $0.dropFirst().lowercased() }
            .joined(separator: " ")
    }

    private func colorForInterest(_ key: String) -> Color {
        switch key.lowercased() {
        case "high": return .red
        case "medium": return .orange
        case "low": return .blue
        default: return .gray
        }
    }

    private func colorForTimeline(_ key: String) -> Color {
        switch key {
        case "0_to_3_months": return .red
        case "3_to_6_months": return .orange
        case "6_plus": return .blue
        case "just_browsing": return .gray
        default: return .secondary
        }
    }
}

// MARK: - Subviews

private struct StatCard: View {
    let value: String
    let label: String
    let icon: String
    let color: Color

    var body: some View {
        VStack(spacing: 6) {
            Image(systemName: icon)
                .font(.title3)
                .foregroundStyle(color)
            Text(value)
                .font(.title2)
                .fontWeight(.bold)
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
                .lineLimit(1)
                .minimumScaleFactor(0.8)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 12)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

private struct BreakdownSectionView: View {
    let title: String
    let icon: String
    let data: [String: Int]
    let colorForKey: (String) -> Color

    private var total: Int { data.values.reduce(0, +) }
    private var sortedData: [(key: String, value: Int)] { data.sorted { $0.value > $1.value } }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Image(systemName: icon)
                    .foregroundStyle(.secondary)
                Text(title)
                    .font(.headline)
            }

            ForEach(sortedData, id: \.key) { item in
                BreakdownBarRow(
                    label: formatKey(item.key),
                    value: item.value,
                    total: total,
                    color: colorForKey(item.key)
                )
            }
        }
        .padding()
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private func formatKey(_ key: String) -> String {
        key.replacingOccurrences(of: "_", with: " ")
            .split(separator: " ")
            .map { $0.prefix(1).uppercased() + $0.dropFirst().lowercased() }
            .joined(separator: " ")
    }
}

private struct BreakdownBarRow: View {
    let label: String
    let value: Int
    let total: Int
    let color: Color

    private var percentage: Int {
        total > 0 ? Int(round(Double(value) / Double(total) * 100)) : 0
    }

    private var barFraction: CGFloat {
        total > 0 ? CGFloat(value) / CGFloat(total) : 0
    }

    var body: some View {
        VStack(spacing: 4) {
            HStack {
                Text(label)
                    .font(.subheadline)
                Spacer()
                Text("\(value)")
                    .font(.subheadline)
                    .fontWeight(.semibold)
                if total > 0 {
                    Text("(\(percentage)%)")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .frame(width: 40, alignment: .trailing)
                }
            }

            GeometryReader { geo in
                ZStack(alignment: .leading) {
                    RoundedRectangle(cornerRadius: 4)
                        .fill(Color(.systemGray5))
                        .frame(height: 6)
                    RoundedRectangle(cornerRadius: 4)
                        .fill(color)
                        .frame(width: geo.size.width * barFraction, height: 6)
                }
            }
            .frame(height: 6)
        }
    }
}

private struct HotLeadRow: View {
    let lead: SummaryAttendee

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(lead.fullName)
                    .font(.subheadline)
                    .fontWeight(.semibold)
                Spacer()
                if lead.preApproved == "yes" {
                    Label("Pre-Approved", systemImage: "checkmark.seal.fill")
                        .font(.caption2)
                        .foregroundStyle(.green)
                }
            }

            HStack(spacing: 12) {
                if !lead.email.isEmpty {
                    Label(lead.email, systemImage: "envelope")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }

            HStack(spacing: 8) {
                if !lead.phone.isEmpty {
                    Label(lead.phone, systemImage: "phone")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            HStack(spacing: 8) {
                if !lead.buyingTimeline.isEmpty {
                    Text(formatTimelineLabel(lead.buyingTimeline))
                        .font(.caption2)
                        .fontWeight(.medium)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 3)
                        .background(timelineColor(lead.buyingTimeline).opacity(0.15))
                        .foregroundStyle(timelineColor(lead.buyingTimeline))
                        .clipShape(Capsule())
                }
                if !lead.interestLevel.isEmpty {
                    Text(lead.interestLevel.capitalized)
                        .font(.caption2)
                        .fontWeight(.medium)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 3)
                        .background(interestColor(lead.interestLevel).opacity(0.15))
                        .foregroundStyle(interestColor(lead.interestLevel))
                        .clipShape(Capsule())
                }
            }
        }
        .padding(12)
        .background(Color.orange.opacity(0.05))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    private func formatTimelineLabel(_ timeline: String) -> String {
        switch timeline {
        case "0_to_3_months": return "0-3 Months"
        case "3_to_6_months": return "3-6 Months"
        case "6_plus": return "6+ Months"
        case "just_browsing": return "Just Browsing"
        default: return timeline.replacingOccurrences(of: "_", with: " ").capitalized
        }
    }

    private func timelineColor(_ timeline: String) -> Color {
        switch timeline {
        case "0_to_3_months": return .red
        case "3_to_6_months": return .orange
        default: return .blue
        }
    }

    private func interestColor(_ level: String) -> Color {
        switch level.lowercased() {
        case "high": return .red
        case "medium": return .orange
        case "low": return .blue
        default: return .gray
        }
    }
}

private struct SummaryAttendeeRow: View {
    let attendee: SummaryAttendee

    var body: some View {
        HStack(alignment: .top) {
            // Icon
            Image(systemName: attendee.isAgent ? "person.crop.rectangle.fill" : "person.fill")
                .foregroundStyle(attendee.isAgent ? .purple : AppColors.brandTeal)
                .frame(width: 28)

            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text(attendee.fullName)
                        .font(.subheadline)
                        .fontWeight(.medium)
                    if attendee.isAgent {
                        Text("Agent")
                            .font(.caption2)
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.purple.opacity(0.15))
                            .foregroundStyle(.purple)
                            .clipShape(Capsule())
                    }
                    if attendee.autoCrmProcessed {
                        Image(systemName: "checkmark.circle.fill")
                            .font(.caption2)
                            .foregroundStyle(.green)
                    }
                }

                if !attendee.email.isEmpty {
                    Text(attendee.email)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                HStack(spacing: 8) {
                    if !attendee.interestLevel.isEmpty {
                        Text(attendee.interestLevel.capitalized)
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                    if attendee.workingWithAgent == "no" {
                        Text("No Agent")
                            .font(.caption2)
                            .foregroundStyle(.orange)
                    }
                }
            }

            Spacer()

            if !attendee.signedInAt.isEmpty {
                Text(formatTime(attendee.signedInAt))
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
    }

    private func formatTime(_ dateString: String) -> String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        formatter.timeZone = TimeZone(identifier: "America/New_York")
        guard let date = formatter.date(from: dateString) else { return dateString }
        let timeFormatter = DateFormatter()
        timeFormatter.dateFormat = "h:mm a"
        timeFormatter.timeZone = TimeZone(identifier: "America/New_York")
        return timeFormatter.string(from: date)
    }
}
