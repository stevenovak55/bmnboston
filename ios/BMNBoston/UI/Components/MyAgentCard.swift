//
//  MyAgentCard.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Phase 5: Agent-Client Collaboration System
//

import SwiftUI

// MARK: - My Agent Card

/// Card displaying the user's assigned agent with contact options
struct MyAgentCard: View {
    let agent: Agent
    var onScheduleTap: (() -> Void)? = nil

    var body: some View {
        VStack(spacing: 0) {
            // Header with photo and info
            HStack(spacing: 16) {
                // Agent photo
                AgentAvatarView(
                    photoURL: agent.photoURL,
                    initials: agent.initials,
                    size: 64
                )

                // Agent info
                VStack(alignment: .leading, spacing: 4) {
                    Text(agent.displayName)
                        .font(.headline)
                        .fontWeight(.semibold)

                    if let title = agent.title {
                        Text(title)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }

                    if let office = agent.officeName {
                        Text(office)
                            .font(.caption)
                            .foregroundStyle(.tertiary)
                    }
                }

                Spacer()
            }
            .padding()

            Divider()

            // Contact buttons
            HStack(spacing: 0) {
                // Call button
                if agent.phone != nil, let phoneLink = agent.formattedPhoneLink {
                    Button {
                        HapticManager.impact(.light)
                        if let url = URL(string: "tel:\(phoneLink)") {
                            UIApplication.shared.open(url)
                        }
                    } label: {
                        VStack(spacing: 4) {
                            Image(systemName: "phone.fill")
                                .font(.title3)
                            Text("Call")
                                .font(.caption)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                    }
                    .foregroundStyle(AppColors.brandTeal)

                    Divider()
                        .frame(height: 40)
                }

                // Email button
                Button {
                    HapticManager.impact(.light)
                    if let url = URL(string: "mailto:\(agent.email)") {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    VStack(spacing: 4) {
                        Image(systemName: "envelope.fill")
                            .font(.title3)
                        Text("Email")
                            .font(.caption)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                }
                .foregroundStyle(AppColors.brandTeal)

                // Schedule button (if agent can book showings)
                if agent.canBookShowings, let scheduleTap = onScheduleTap {
                    Divider()
                        .frame(height: 40)

                    Button {
                        HapticManager.impact(.light)
                        scheduleTap()
                    } label: {
                        VStack(spacing: 4) {
                            Image(systemName: "calendar")
                                .font(.title3)
                            Text("Schedule")
                                .font(.caption)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                    }
                    .foregroundStyle(AppColors.brandTeal)
                }
            }
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: AppColors.shadowLight, radius: 4, x: 0, y: 2)
    }
}

// MARK: - Compact Agent Card (for inline display)

/// Compact horizontal agent card for saved search details
struct CompactAgentCard: View {
    let agent: Agent

    var body: some View {
        HStack(spacing: 12) {
            AgentAvatarView(
                photoURL: agent.photoURL,
                initials: agent.initials,
                size: 40
            )

            VStack(alignment: .leading, spacing: 2) {
                Text(agent.displayName)
                    .font(.subheadline)
                    .fontWeight(.medium)

                if let title = agent.title {
                    Text(title)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Spacer()

            // Quick contact buttons
            HStack(spacing: 12) {
                if let phoneLink = agent.formattedPhoneLink {
                    Button {
                        HapticManager.impact(.light)
                        if let url = URL(string: "tel:\(phoneLink)") {
                            UIApplication.shared.open(url)
                        }
                    } label: {
                        Image(systemName: "phone.fill")
                            .font(.body)
                            .foregroundStyle(AppColors.brandTeal)
                    }
                }

                Button {
                    HapticManager.impact(.light)
                    if let url = URL(string: "mailto:\(agent.email)") {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    Image(systemName: "envelope.fill")
                        .font(.body)
                        .foregroundStyle(AppColors.brandTeal)
                }
            }
        }
        .padding(12)
        .background(Color(.secondarySystemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}

// MARK: - Agent Avatar View

/// Reusable agent avatar with photo or initials fallback
struct AgentAvatarView: View {
    let photoURL: URL?
    let initials: String
    let size: CGFloat

    var body: some View {
        if let url = photoURL {
            AsyncImage(url: url) { phase in
                switch phase {
                case .empty:
                    initialsView
                        .overlay(ProgressView().scaleEffect(0.5))
                case .success(let image):
                    image
                        .resizable()
                        .aspectRatio(contentMode: .fill)
                        .frame(width: size, height: size)
                        .clipShape(Circle())
                case .failure:
                    initialsView
                @unknown default:
                    initialsView
                }
            }
        } else {
            initialsView
        }
    }

    private var initialsView: some View {
        Circle()
            .fill(AppColors.brandTeal.opacity(0.2))
            .frame(width: size, height: size)
            .overlay(
                Text(initials)
                    .font(.system(size: size * 0.35, weight: .semibold))
                    .foregroundStyle(AppColors.brandTeal)
            )
    }
}

// MARK: - Agent Summary Card (from User.assignedAgent)

/// Card for displaying AgentSummary from User model
struct AgentSummaryCard: View {
    let agent: AgentSummary

    var body: some View {
        HStack(spacing: 12) {
            AgentAvatarView(
                photoURL: agent.photoURL,
                initials: agent.initials,
                size: 48
            )

            VStack(alignment: .leading, spacing: 2) {
                Text(agent.name)
                    .font(.subheadline)
                    .fontWeight(.medium)

                Text(agent.email)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            Spacer()

            // Quick contact
            HStack(spacing: 12) {
                if let phone = agent.phone, !phone.isEmpty {
                    Button {
                        HapticManager.impact(.light)
                        let cleaned = phone.replacingOccurrences(of: "[^0-9+]", with: "", options: .regularExpression)
                        if let url = URL(string: "tel:\(cleaned)") {
                            UIApplication.shared.open(url)
                        }
                    } label: {
                        Image(systemName: "phone.fill")
                            .foregroundStyle(AppColors.brandTeal)
                    }
                }

                Button {
                    HapticManager.impact(.light)
                    if let url = URL(string: "mailto:\(agent.email)") {
                        UIApplication.shared.open(url)
                    }
                } label: {
                    Image(systemName: "envelope.fill")
                        .foregroundStyle(AppColors.brandTeal)
                }
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

// MARK: - Agent Pick Badge

/// Badge showing a search was recommended by agent
struct AgentPickBadge: View {
    var compact: Bool = false

    var body: some View {
        HStack(spacing: 4) {
            Image(systemName: "star.fill")
                .font(compact ? .caption2 : .caption)
            Text("Agent Pick")
                .font(compact ? .caption2 : .caption)
                .fontWeight(.medium)
        }
        .padding(.horizontal, compact ? 6 : 8)
        .padding(.vertical, compact ? 3 : 4)
        .background(Color.orange.opacity(0.15))
        .foregroundStyle(.orange)
        .clipShape(Capsule())
    }
}

// MARK: - Agent Notes View

/// View displaying agent notes for a saved search
struct AgentNotesView: View {
    let notes: String
    let agentName: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                Image(systemName: "quote.bubble.fill")
                    .foregroundStyle(.orange)
                Text("Note from \(agentName ?? "your agent")")
                    .font(.caption)
                    .fontWeight(.medium)
                    .foregroundStyle(.secondary)
            }

            Text(notes)
                .font(.subheadline)
                .foregroundStyle(.primary)
                .fixedSize(horizontal: false, vertical: true)
        }
        .padding()
        .background(Color.orange.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}
