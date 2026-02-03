//
//  AgentReferralView.swift
//  BMNBoston
//
//  Agent referral link management view.
//  Allows agents to view/copy their referral link and see stats.
//
//  @since v209 (MLD v6.52.0)
//

import SwiftUI

// MARK: - Models

struct ReferralLinkResponse: Decodable {
    let referralCode: String
    let referralUrl: String
    let stats: ReferralStats?

    private enum CodingKeys: String, CodingKey {
        case referralCode = "referral_code"
        case referralUrl = "referral_url"
        case stats
    }
}

struct ReferralStats: Decodable {
    let totalSignups: Int
    let thisMonth: Int
    let lastSignup: String?

    private enum CodingKeys: String, CodingKey {
        case totalSignups = "total_signups"
        case thisMonth = "this_month"
        case lastSignup = "last_signup"
    }
}

struct ReferralStatsResponse: Decodable {
    let totalReferrals: Int
    let thisMonth: Int
    let lastThreeMonths: Int
    let byMonth: [MonthlyReferrals]

    private enum CodingKeys: String, CodingKey {
        case totalReferrals = "total_referrals"
        case thisMonth = "this_month"
        case lastThreeMonths = "last_three_months"
        case byMonth = "by_month"
    }
}

struct MonthlyReferrals: Decodable, Identifiable {
    let month: String
    let count: Int

    var id: String { month }
}

// MARK: - View

struct AgentReferralView: View {
    @State private var referralData: ReferralLinkResponse?
    @State private var detailedStats: ReferralStatsResponse?
    @State private var isLoading = true
    @State private var error: String?
    @State private var copied = false
    @State private var showShareSheet = false
    @State private var showRegenerateConfirm = false
    @State private var isRegenerating = false

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                if isLoading {
                    loadingView
                } else if let error = error {
                    errorView(error)
                } else if let data = referralData {
                    referralLinkCard(data)
                    statsCard(data)
                    if let detailed = detailedStats {
                        monthlyStatsCard(detailed)
                    }
                    tipsCard
                }
            }
            .padding()
        }
        .navigationTitle("Referral Link")
        .navigationBarTitleDisplayMode(.large)
        .task {
            await loadReferralData()
        }
        .confirmationDialog(
            "Regenerate Referral Code",
            isPresented: $showRegenerateConfirm,
            titleVisibility: .visible
        ) {
            Button("Regenerate", role: .destructive) {
                Task { await regenerateCode() }
            }
            Button("Cancel", role: .cancel) { }
        } message: {
            Text("This will create a new referral code. Your old link will stop working.")
        }
        .sheet(isPresented: $showShareSheet) {
            if let url = referralData?.referralUrl {
                ShareSheet(items: [url])
            }
        }
    }

    // MARK: - Subviews

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Loading referral link...")
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, minHeight: 200)
    }

    private func errorView(_ message: String) -> some View {
        VStack(spacing: 16) {
            Image(systemName: "exclamationmark.triangle")
                .font(.system(size: 40))
                .foregroundStyle(.orange)
            Text(message)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button("Try Again") {
                Task { await loadReferralData() }
            }
            .buttonStyle(.bordered)
        }
        .frame(maxWidth: .infinity, minHeight: 200)
    }

    private func referralLinkCard(_ data: ReferralLinkResponse) -> some View {
        VStack(alignment: .leading, spacing: 16) {
            HStack {
                Image(systemName: "link")
                    .foregroundStyle(.white)
                Text("Your Referral Link")
                    .font(.headline)
                    .foregroundStyle(.white)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(
                LinearGradient(
                    colors: [Color(red: 0.04, green: 0.73, blue: 0.51), Color(red: 0.02, green: 0.59, blue: 0.41)],
                    startPoint: .topLeading,
                    endPoint: .bottomTrailing
                )
            )

            VStack(alignment: .leading, spacing: 12) {
                Text("Share this link with potential clients. When they sign up, they'll be automatically assigned to you.")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)

                HStack(spacing: 8) {
                    Text(data.referralUrl)
                        .font(.system(.footnote, design: .monospaced))
                        .lineLimit(1)
                        .truncationMode(.middle)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 10)
                        .background(Color(.systemGray6))
                        .clipShape(RoundedRectangle(cornerRadius: 8))

                    Button {
                        copyToClipboard(data.referralUrl)
                    } label: {
                        Image(systemName: copied ? "checkmark" : "doc.on.doc")
                            .font(.system(size: 16, weight: .medium))
                            .foregroundStyle(copied ? .green : .blue)
                            .frame(width: 44, height: 44)
                            .background(Color(.systemGray6))
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                    }
                }

                HStack(spacing: 12) {
                    Button {
                        showShareSheet = true
                    } label: {
                        Label("Share", systemImage: "square.and.arrow.up")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)

                    Button {
                        showRegenerateConfirm = true
                    } label: {
                        if isRegenerating {
                            ProgressView()
                                .frame(maxWidth: .infinity)
                        } else {
                            Label("New Code", systemImage: "arrow.triangle.2.circlepath")
                                .frame(maxWidth: .infinity)
                        }
                    }
                    .buttonStyle(.bordered)
                    .disabled(isRegenerating)
                }

                HStack {
                    Text("Code:")
                        .foregroundStyle(.secondary)
                    Text(data.referralCode)
                        .font(.system(.subheadline, design: .monospaced))
                        .fontWeight(.semibold)
                }
                .font(.subheadline)
            }
            .padding()
        }
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.1), radius: 4, y: 2)
    }

    private func statsCard(_ data: ReferralLinkResponse) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Referral Stats")
                .font(.headline)

            HStack(spacing: 20) {
                statItem(
                    value: "\(data.stats?.totalSignups ?? 0)",
                    label: "Total Signups",
                    icon: "person.2.fill",
                    color: .blue
                )

                statItem(
                    value: "\(data.stats?.thisMonth ?? 0)",
                    label: "This Month",
                    icon: "calendar",
                    color: .green
                )
            }
        }
        .padding()
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.1), radius: 4, y: 2)
    }

    private func statItem(value: String, label: String, icon: String, color: Color) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack(spacing: 6) {
                Image(systemName: icon)
                    .foregroundStyle(color)
                Text(value)
                    .font(.title2)
                    .fontWeight(.bold)
            }
            Text(label)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func monthlyStatsCard(_ stats: ReferralStatsResponse) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Monthly Breakdown")
                .font(.headline)

            if stats.byMonth.isEmpty {
                Text("No referral data yet")
                    .foregroundStyle(.secondary)
                    .font(.subheadline)
            } else {
                ForEach(stats.byMonth.prefix(6)) { month in
                    HStack {
                        Text(month.month)
                            .font(.subheadline)
                        Spacer()
                        Text("\(month.count) signup\(month.count == 1 ? "" : "s")")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                }
            }
        }
        .padding()
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.1), radius: 4, y: 2)
    }

    private var tipsCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Image(systemName: "lightbulb.fill")
                    .foregroundStyle(.yellow)
                Text("Tips for Sharing")
                    .font(.headline)
            }

            VStack(alignment: .leading, spacing: 8) {
                tipRow(icon: "message.fill", text: "Send via text to new contacts")
                tipRow(icon: "envelope.fill", text: "Include in your email signature")
                tipRow(icon: "square.and.arrow.up", text: "Share on social media")
                tipRow(icon: "qrcode", text: "Create a QR code for open houses")
            }
        }
        .padding()
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.1), radius: 4, y: 2)
    }

    private func tipRow(icon: String, text: String) -> some View {
        HStack(spacing: 10) {
            Image(systemName: icon)
                .foregroundStyle(.blue)
                .frame(width: 20)
            Text(text)
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - Actions

    private func loadReferralData() async {
        isLoading = true
        error = nil

        do {
            let response: ReferralLinkResponse = try await APIClient.shared.request(.agentReferralLink)
            referralData = response

            // Also load detailed stats
            let statsResponse: ReferralStatsResponse = try await APIClient.shared.request(.agentReferralStats)
            detailedStats = statsResponse
        } catch {
            self.error = "Failed to load referral data. Please try again."
            debugLog("Referral load error: \(error)")
        }

        isLoading = false
    }

    private func copyToClipboard(_ text: String) {
        UIPasteboard.general.string = text
        copied = true

        // Reset after 2 seconds
        DispatchQueue.main.asyncAfter(deadline: .now() + 2) {
            copied = false
        }
    }

    private func regenerateCode() async {
        isRegenerating = true

        do {
            let response: ReferralLinkResponse = try await APIClient.shared.request(.regenerateReferralCode)
            referralData = response
        } catch {
            self.error = "Failed to regenerate code. Please try again."
            debugLog("Regenerate error: \(error)")
        }

        isRegenerating = false
    }
}

#Preview {
    NavigationStack {
        AgentReferralView()
    }
}
