//
//  ShareWithClientsSheet.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Sprint 3: Property Sharing - Agent to Client
//

import SwiftUI

/// Sheet for agents to share a property with their clients
struct ShareWithClientsSheet: View {
    @Environment(\.dismiss) private var dismiss

    let listingKey: String
    let propertyAddress: String

    @State private var clients: [AgentClient] = []
    @State private var selectedClientIds: Set<Int> = []
    @State private var note: String = ""
    @State private var isLoading = true
    @State private var isSharing = false
    @State private var error: String?
    @State private var showSuccess = false
    @State private var shareResult: SharePropertiesResponse?

    var body: some View {
        NavigationView {
            VStack(spacing: 0) {
                // Property info header
                propertyHeader

                Divider()

                if isLoading {
                    loadingView
                } else if clients.isEmpty {
                    emptyState
                } else {
                    // Client list and note
                    ScrollView {
                        VStack(spacing: 16) {
                            // Select clients section
                            clientSelectionSection

                            // Note section
                            noteSection
                        }
                        .padding()
                    }
                }

                // Error message
                if let error = error {
                    Text(error)
                        .font(.caption)
                        .foregroundColor(.red)
                        .padding(.horizontal)
                }

                // Bottom buttons
                bottomButtons
            }
            .navigationTitle("Share with Clients")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Cancel") {
                        dismiss()
                    }
                }
            }
            .alert("Property Shared!", isPresented: $showSuccess) {
                Button("Done") {
                    dismiss()
                }
            } message: {
                if let result = shareResult {
                    let notifText = [
                        result.notificationsSent?.push.map { "\($0) push" },
                        result.notificationsSent?.email.map { "\($0) email" }
                    ].compactMap { $0 }.joined(separator: ", ")

                    Text("Shared with \(result.sharedCount) client(s).\(notifText.isEmpty ? "" : " Notifications sent: \(notifText)")")
                } else {
                    Text("Property has been shared with selected clients.")
                }
            }
        }
        .task {
            await loadClients()
        }
    }

    // MARK: - Property Header

    private var propertyHeader: some View {
        HStack(spacing: 12) {
            Image(systemName: "house.fill")
                .font(.title2)
                .foregroundColor(AppColors.brandTeal)
                .frame(width: 44, height: 44)
                .background(AppColors.brandTeal.opacity(0.1))
                .clipShape(RoundedRectangle(cornerRadius: 8))

            VStack(alignment: .leading, spacing: 2) {
                Text("Sharing Property")
                    .font(.caption)
                    .foregroundColor(.secondary)
                Text(propertyAddress)
                    .font(.subheadline)
                    .fontWeight(.medium)
                    .lineLimit(2)
            }

            Spacer()
        }
        .padding()
        .background(Color(.systemBackground))
    }

    // MARK: - Loading View

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Loading clients...")
                .font(.subheadline)
                .foregroundColor(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Empty State

    private var emptyState: some View {
        VStack(spacing: 16) {
            Image(systemName: "person.2.slash")
                .font(.system(size: 48))
                .foregroundColor(.secondary)

            Text("No Clients Found")
                .font(.headline)

            Text("You don't have any clients to share properties with yet. Add clients from the My Clients section.")
                .font(.subheadline)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Client Selection Section

    private var clientSelectionSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text("Select Clients")
                    .font(.headline)

                Spacer()

                if !clients.isEmpty {
                    Button(selectedClientIds.count == clients.count ? "Deselect All" : "Select All") {
                        if selectedClientIds.count == clients.count {
                            selectedClientIds.removeAll()
                        } else {
                            selectedClientIds = Set(clients.map { $0.id })
                        }
                    }
                    .font(.subheadline)
                    .foregroundColor(AppColors.brandTeal)
                }
            }

            VStack(spacing: 8) {
                ForEach(clients) { client in
                    ClientSelectionRow(
                        client: client,
                        isSelected: selectedClientIds.contains(client.id)
                    ) {
                        if selectedClientIds.contains(client.id) {
                            selectedClientIds.remove(client.id)
                        } else {
                            selectedClientIds.insert(client.id)
                        }
                    }
                }
            }
        }
    }

    // MARK: - Note Section

    private var noteSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Add a Note (Optional)")
                .font(.headline)

            TextEditor(text: $note)
                .frame(minHeight: 80)
                .padding(8)
                .background(Color(.systemGray6))
                .cornerRadius(8)
                .overlay(
                    RoundedRectangle(cornerRadius: 8)
                        .stroke(Color(.systemGray4), lineWidth: 1)
                )

            Text("This note will be visible to the client when they view the shared property.")
                .font(.caption)
                .foregroundColor(.secondary)
        }
    }

    // MARK: - Bottom Buttons

    private var bottomButtons: some View {
        VStack(spacing: 12) {
            Divider()

            Button(action: shareProperty) {
                HStack {
                    if isSharing {
                        ProgressView()
                            .progressViewStyle(CircularProgressViewStyle(tint: .white))
                    } else {
                        Image(systemName: "paperplane.fill")
                    }
                    Text(isSharing ? "Sharing..." : "Share Property")
                }
                .frame(maxWidth: .infinity)
                .padding()
                .background(selectedClientIds.isEmpty ? Color.gray : AppColors.brandTeal)
                .foregroundColor(.white)
                .cornerRadius(12)
            }
            .disabled(selectedClientIds.isEmpty || isSharing)
            .padding(.horizontal)
            .padding(.bottom)
        }
        .background(Color(.systemBackground))
    }

    // MARK: - Actions

    private func loadClients() async {
        isLoading = true
        error = nil

        do {
            clients = try await AgentService.shared.fetchAgentClients(forceRefresh: true)
        } catch {
            self.error = error.userFriendlyMessage
        }

        isLoading = false
    }

    private func shareProperty() {
        guard !selectedClientIds.isEmpty else { return }

        Task {
            isSharing = true
            error = nil

            do {
                let result = try await AgentService.shared.shareProperties(
                    clientIds: Array(selectedClientIds),
                    listingKeys: [listingKey],
                    note: note.isEmpty ? nil : note
                )
                shareResult = result
                showSuccess = true
            } catch {
                self.error = error.userFriendlyMessage
            }

            isSharing = false
        }
    }
}

// MARK: - Client Selection Row

private struct ClientSelectionRow: View {
    let client: AgentClient
    let isSelected: Bool
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            HStack(spacing: 12) {
                // Selection indicator
                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                    .font(.title2)
                    .foregroundColor(isSelected ? AppColors.brandTeal : .secondary)

                // Client info
                VStack(alignment: .leading, spacing: 2) {
                    Text(client.displayName)
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundColor(.primary)

                    Text(client.email)
                        .font(.caption)
                        .foregroundColor(.secondary)
                }

                Spacer()

                // Activity stats
                if client.favoritesCount > 0 || client.searchesCount > 0 {
                    HStack(spacing: 8) {
                        if client.favoritesCount > 0 {
                            Label("\(client.favoritesCount)", systemImage: "heart.fill")
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }
                        if client.searchesCount > 0 {
                            Label("\(client.searchesCount)", systemImage: "magnifyingglass")
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }
                    }
                }
            }
            .padding()
            .background(isSelected ? AppColors.brandTeal.opacity(0.1) : Color(.systemGray6))
            .cornerRadius(12)
            .overlay(
                RoundedRectangle(cornerRadius: 12)
                    .stroke(isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 2)
            )
        }
        .buttonStyle(PlainButtonStyle())
    }
}

// MARK: - Preview

#Preview {
    ShareWithClientsSheet(
        listingKey: "abc123",
        propertyAddress: "123 Main Street, Boston, MA 02101"
    )
}
