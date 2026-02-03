//
//  SharedPropertiesView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Sprint 3: Property Sharing - Agent to Client
//

import SwiftUI

/// View for clients to see properties shared by their agent
struct SharedPropertiesView: View {
    // Environment objects - inherited from parent views
    @EnvironmentObject var authViewModel: AuthViewModel
    @EnvironmentObject var viewModel: PropertySearchViewModel

    @State private var sharedProperties: [SharedProperty] = []
    @State private var isLoading = true
    @State private var error: String?
    @State private var selectedProperty: SharedProperty?
    @State private var propertyToRespond: SharedProperty?

    var body: some View {
        Group {
            if isLoading {
                loadingView
            } else if sharedProperties.isEmpty {
                emptyState
            } else {
                propertyList
            }
        }
        .navigationTitle("From My Agent")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable {
            await loadProperties(forceRefresh: true)
        }
        .task {
            await loadProperties()
        }
        .sheet(item: $propertyToRespond) { property in
            ResponseSheet(
                property: property,
                onResponse: { response, note in
                    await updateResponse(property: property, response: response, note: note)
                },
                onDismiss: {
                    await dismissProperty(property)
                }
            )
        }
    }

    // MARK: - Loading View

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
            Text("Loading shared properties...")
                .font(.subheadline)
                .foregroundColor(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Empty State

    private var emptyState: some View {
        VStack(spacing: 20) {
            Image(systemName: "house.and.flag")
                .font(.system(size: 56))
                .foregroundColor(.secondary)

            Text("No Shared Properties")
                .font(.title2)
                .fontWeight(.semibold)

            Text("When your agent shares properties with you, they'll appear here.")
                .font(.subheadline)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 32)

            if let error = error {
                Text(error)
                    .font(.caption)
                    .foregroundColor(.red)
                    .padding(.top, 8)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Property List

    private var propertyList: some View {
        ScrollView {
            LazyVStack(spacing: 16) {
                ForEach(sharedProperties) { shared in
                    SharedPropertyCard(
                        sharedProperty: shared,
                        onRespond: {
                            propertyToRespond = shared
                        }
                    )
                }
            }
            .padding()
        }
    }

    // MARK: - Actions

    private func loadProperties(forceRefresh: Bool = false) async {
        if !forceRefresh && !sharedProperties.isEmpty {
            return
        }

        isLoading = sharedProperties.isEmpty
        error = nil

        do {
            sharedProperties = try await AgentService.shared.fetchSharedProperties(forceRefresh: forceRefresh)
        } catch {
            self.error = error.userFriendlyMessage
        }

        isLoading = false
    }

    private func updateResponse(property: SharedProperty, response: ClientResponse, note: String?) async {
        do {
            _ = try await AgentService.shared.updateSharedPropertyResponse(
                id: property.id,
                response: response,
                note: note
            )
            // Refresh the list
            await loadProperties(forceRefresh: true)
        } catch {
            self.error = error.userFriendlyMessage
        }
    }

    private func dismissProperty(_ property: SharedProperty) async {
        do {
            try await AgentService.shared.dismissSharedProperty(id: property.id)
            // Refresh the list
            await loadProperties(forceRefresh: true)
        } catch {
            self.error = error.userFriendlyMessage
        }
    }
}

// MARK: - Shared Property Card

private struct SharedPropertyCard: View {
    let sharedProperty: SharedProperty
    let onRespond: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            // Agent header
            if let agent = sharedProperty.agent {
                agentHeader(agent)
            }

            // Property preview with navigation
            // Note: Environment objects (authViewModel, viewModel) are inherited automatically
            if let property = sharedProperty.property {
                NavigationLink(destination: PropertyDetailView(propertyId: property.listingKey)) {
                    propertyPreview(property)
                }
                .buttonStyle(PlainButtonStyle())
            }

            // Agent note
            if let note = sharedProperty.agentNote, !note.isEmpty {
                agentNote(note)
            }

            // Response section
            responseSection

            // Footer with time and actions
            footer
        }
        .background(Color(.systemBackground))
        .cornerRadius(16)
        .shadow(color: .black.opacity(0.1), radius: 8, x: 0, y: 2)
    }

    private func agentHeader(_ agent: SharedPropertyAgent) -> some View {
        HStack(spacing: 10) {
            // Agent avatar
            if let url = agent.photoURL {
                AsyncImage(url: url) { image in
                    image
                        .resizable()
                        .aspectRatio(contentMode: .fill)
                } placeholder: {
                    initialsView(agent.initials)
                }
                .frame(width: 36, height: 36)
                .clipShape(Circle())
            } else {
                initialsView(agent.initials)
            }

            VStack(alignment: .leading, spacing: 2) {
                Text(agent.name)
                    .font(.subheadline)
                    .fontWeight(.medium)
                Text("Shared a property with you")
                    .font(.caption)
                    .foregroundColor(.secondary)
            }

            Spacer()

            // Shared time
            Text(sharedProperty.relativeSharedAt)
                .font(.caption)
                .foregroundColor(.secondary)
        }
        .padding()
        .background(Color(.systemGray6))
    }

    private func initialsView(_ initials: String) -> some View {
        Text(initials)
            .font(.system(size: 14, weight: .medium))
            .foregroundColor(.white)
            .frame(width: 36, height: 36)
            .background(AppColors.brandTeal)
            .clipShape(Circle())
    }

    private func propertyPreview(_ property: SharedPropertyData) -> some View {
        HStack(spacing: 12) {
            // Property image
            if let url = property.photoURL {
                AsyncImage(url: url) { image in
                    image
                        .resizable()
                        .aspectRatio(contentMode: .fill)
                } placeholder: {
                    Rectangle()
                        .fill(Color(.systemGray5))
                        .overlay(
                            Image(systemName: "photo")
                                .foregroundColor(.secondary)
                        )
                }
                .frame(width: 100, height: 80)
                .clipShape(RoundedRectangle(cornerRadius: 8))
            }

            VStack(alignment: .leading, spacing: 4) {
                Text(property.formattedPrice)
                    .font(.headline)
                    .foregroundColor(.primary)

                Text(property.shortAddress)
                    .font(.subheadline)
                    .foregroundColor(.primary)
                    .lineLimit(1)

                if let city = property.city {
                    Text(city)
                        .font(.caption)
                        .foregroundColor(.secondary)
                }

                Text(property.bedsAndBaths)
                    .font(.caption)
                    .foregroundColor(.secondary)
            }

            Spacer()

            Image(systemName: "chevron.right")
                .foregroundColor(.secondary)
        }
        .padding()
    }

    private func agentNote(_ note: String) -> some View {
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: "quote.opening")
                .font(.caption)
                .foregroundColor(AppColors.brandTeal)

            Text(note)
                .font(.subheadline)
                .foregroundColor(.primary)
                .italic()

            Spacer()
        }
        .padding()
        .background(AppColors.brandTeal.opacity(0.05))
    }

    private var responseSection: some View {
        Group {
            if sharedProperty.clientResponse != .none {
                // Show current response
                HStack {
                    Image(systemName: sharedProperty.clientResponse.icon)
                        .foregroundColor(sharedProperty.clientResponse == .interested ? .green : .orange)

                    Text("You marked this as \(sharedProperty.clientResponse.displayName.lowercased())")
                        .font(.caption)
                        .foregroundColor(.secondary)

                    Spacer()

                    Button("Change") {
                        onRespond()
                    }
                    .font(.caption)
                    .foregroundColor(AppColors.brandTeal)
                }
                .padding(.horizontal)
                .padding(.vertical, 8)
            }
        }
    }

    private var footer: some View {
        HStack {
            // View count
            if sharedProperty.viewCount > 0 {
                Label("\(sharedProperty.viewCount) views", systemImage: "eye")
                    .font(.caption)
                    .foregroundColor(.secondary)
            }

            Spacer()

            // Respond button if no response yet
            if sharedProperty.clientResponse == .none {
                Button(action: onRespond) {
                    Text("Respond")
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundColor(.white)
                        .padding(.horizontal, 16)
                        .padding(.vertical, 8)
                        .background(AppColors.brandTeal)
                        .cornerRadius(20)
                }
            }
        }
        .padding()
    }
}

// MARK: - Response Sheet

private struct ResponseSheet: View {
    @Environment(\.dismiss) private var dismiss

    let property: SharedProperty
    let onResponse: (ClientResponse, String?) async -> Void
    let onDismiss: () async -> Void

    @State private var selectedResponse: ClientResponse = .none
    @State private var note: String = ""
    @State private var isSubmitting = false

    var body: some View {
        NavigationView {
            VStack(spacing: 24) {
                // Property info
                if let prop = property.property {
                    HStack {
                        if let url = prop.photoURL {
                            AsyncImage(url: url) { image in
                                image
                                    .resizable()
                                    .aspectRatio(contentMode: .fill)
                            } placeholder: {
                                Color(.systemGray5)
                            }
                            .frame(width: 60, height: 50)
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                        }

                        VStack(alignment: .leading) {
                            Text(prop.shortAddress)
                                .font(.subheadline)
                                .fontWeight(.medium)
                            Text(prop.formattedPrice)
                                .font(.caption)
                                .foregroundColor(.secondary)
                        }

                        Spacer()
                    }
                    .padding()
                    .background(Color(.systemGray6))
                    .cornerRadius(12)
                }

                // Response options
                VStack(spacing: 12) {
                    Text("What do you think?")
                        .font(.headline)

                    HStack(spacing: 16) {
                        responseButton(
                            response: .interested,
                            icon: "heart.fill",
                            label: "Interested",
                            color: .green
                        )

                        responseButton(
                            response: .notInterested,
                            icon: "xmark.circle.fill",
                            label: "Not Interested",
                            color: .orange
                        )
                    }
                }

                // Note field
                VStack(alignment: .leading, spacing: 8) {
                    Text("Add a note for your agent (optional)")
                        .font(.subheadline)
                        .foregroundColor(.secondary)

                    TextEditor(text: $note)
                        .frame(height: 80)
                        .padding(8)
                        .background(Color(.systemGray6))
                        .cornerRadius(8)
                }

                Spacer()

                // Action buttons
                VStack(spacing: 12) {
                    Button(action: submitResponse) {
                        HStack {
                            if isSubmitting {
                                ProgressView()
                                    .progressViewStyle(CircularProgressViewStyle(tint: .white))
                            }
                            Text(isSubmitting ? "Sending..." : "Send Response")
                        }
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(selectedResponse == .none ? Color.gray : AppColors.brandTeal)
                        .foregroundColor(.white)
                        .cornerRadius(12)
                    }
                    .disabled(selectedResponse == .none || isSubmitting)

                    Button("Dismiss Property") {
                        Task {
                            isSubmitting = true
                            await onDismiss()
                            dismiss()
                        }
                    }
                    .foregroundColor(.red)
                }
            }
            .padding()
            .navigationTitle("Your Response")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Cancel") {
                        dismiss()
                    }
                }
            }
        }
    }

    private func responseButton(response: ClientResponse, icon: String, label: String, color: Color) -> some View {
        Button {
            selectedResponse = response
        } label: {
            VStack(spacing: 8) {
                Image(systemName: icon)
                    .font(.title)
                Text(label)
                    .font(.caption)
                    .fontWeight(.medium)
            }
            .frame(maxWidth: .infinity)
            .padding()
            .background(selectedResponse == response ? color.opacity(0.2) : Color(.systemGray6))
            .foregroundColor(selectedResponse == response ? color : .secondary)
            .cornerRadius(12)
            .overlay(
                RoundedRectangle(cornerRadius: 12)
                    .stroke(selectedResponse == response ? color : Color.clear, lineWidth: 2)
            )
        }
        .buttonStyle(PlainButtonStyle())
    }

    private func submitResponse() {
        guard selectedResponse != .none else { return }

        Task {
            isSubmitting = true
            await onResponse(selectedResponse, note.isEmpty ? nil : note)
            dismiss()
        }
    }
}

// MARK: - Preview

#Preview {
    NavigationStack {
        SharedPropertiesView()
    }
}
