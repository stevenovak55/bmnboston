//
//  SavedSearchesView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  List of saved searches with server sync
//

import SwiftUI

struct SavedSearchesView: View {
    @EnvironmentObject var viewModel: PropertySearchViewModel
    @Environment(\.dismiss) var dismiss

    @State private var showCreateSheet = false
    @State private var searchToEdit: SavedSearch?
    @State private var searchToDelete: SavedSearch?
    @State private var showDeleteConfirmation = false

    var body: some View {
        NavigationView {
            Group {
                if viewModel.savedSearchSyncState == .loading && viewModel.savedSearches.isEmpty {
                    loadingView
                } else if viewModel.savedSearches.isEmpty {
                    emptyStateView
                } else {
                    searchListView
                }
            }
            .navigationTitle("Saved Searches")
            .navigationBarTitleDisplayMode(.large)
            .toolbar {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button("Done") {
                        dismiss()
                    }
                    .accessibilityLabel("Done")
                    .accessibilityHint("Double tap to close saved searches")
                }
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        showCreateSheet = true
                    } label: {
                        Image(systemName: "plus")
                    }
                    .accessibilityLabel("Create saved search")
                    .accessibilityHint("Double tap to save your current search")
                }
            }
            .refreshable {
                await viewModel.loadSavedSearchesFromServer(forceRefresh: true)
            }
            .task {
                await viewModel.loadSavedSearchesFromServer()
            }
            .sheet(isPresented: $showCreateSheet) {
                CreateSavedSearchSheet()
                    .environmentObject(viewModel)
            }
            .sheet(item: $searchToEdit) { search in
                SavedSearchDetailView(search: search)
                    .environmentObject(viewModel)
            }
            .alert("Delete Search", isPresented: $showDeleteConfirmation) {
                Button("Cancel", role: .cancel) {}
                Button("Delete", role: .destructive) {
                    if let search = searchToDelete {
                        Task {
                            await viewModel.deleteSavedSearch(search)
                        }
                    }
                }
            } message: {
                Text("Are you sure you want to delete this saved search? This cannot be undone.")
            }
        }
    }

    // MARK: - Subviews

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
                .accessibilityHidden(true)
            Text("Loading saved searches...")
                .foregroundColor(.secondary)
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Loading saved searches")
    }

    private var emptyStateView: some View {
        VStack(spacing: 20) {
            Image(systemName: "bookmark.slash")
                .font(.system(size: 60))
                .foregroundColor(.secondary)
                .accessibilityHidden(true)

            Text("No Saved Searches")
                .font(.title2)
                .fontWeight(.semibold)

            Text("Save your searches to get notified when new listings match your criteria.")
                .font(.body)
                .foregroundColor(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, 40)

            Button {
                showCreateSheet = true
            } label: {
                Label("Save Current Search", systemImage: "plus")
                    .fontWeight(.semibold)
            }
            .buttonStyle(.borderedProminent)
            .tint(AppColors.brandTeal)
            .padding(.top, 8)
            .accessibilityLabel("Save current search")
            .accessibilityHint("Double tap to save your current search filters")
        }
        .accessibilityElement(children: .contain)
        .accessibilityLabel("No saved searches. Save your searches to get notified when new listings match your criteria.")
    }

    private var searchListView: some View {
        List {
            if case .error(let message) = viewModel.savedSearchSyncState {
                errorBanner(message: message)
            }

            ForEach(viewModel.savedSearches) { search in
                SavedSearchRow(search: search) {
                    // Apply search filters first, then dismiss and switch to Search tab
                    viewModel.applySavedSearch(search)
                    dismiss()
                    // Switch to Search tab so user can see results
                    NotificationCenter.default.post(name: .switchToSearchTab, object: nil)
                }
                .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                    Button(role: .destructive) {
                        searchToDelete = search
                        showDeleteConfirmation = true
                    } label: {
                        Label("Delete", systemImage: "trash")
                    }
                    .accessibilityLabel("Delete \(search.name)")

                    Button {
                        searchToEdit = search
                    } label: {
                        Label("Edit", systemImage: "pencil")
                    }
                    .tint(.blue)
                    .accessibilityLabel("Edit \(search.name)")

                    Button {
                        Task {
                            await viewModel.duplicateSavedSearch(search)
                        }
                    } label: {
                        Label("Duplicate", systemImage: "doc.on.doc")
                    }
                    .tint(.purple)
                    .accessibilityLabel("Duplicate \(search.name)")
                }
                .swipeActions(edge: .leading, allowsFullSwipe: true) {
                    Button {
                        Task {
                            await viewModel.toggleSavedSearchActive(search)
                        }
                    } label: {
                        Label(
                            search.isActive ? "Pause" : "Resume",
                            systemImage: search.isActive ? "pause.fill" : "play.fill"
                        )
                    }
                    .tint(search.isActive ? .orange : .green)
                    .accessibilityLabel(search.isActive ? "Pause notifications for \(search.name)" : "Resume notifications for \(search.name)")
                }
            }
        }
        .listStyle(.insetGrouped)
        .accessibilityLabel("Saved searches list")
    }

    private func errorBanner(message: String) -> some View {
        HStack {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundColor(.orange)
                .accessibilityHidden(true)
            Text(message)
                .font(.caption)
            Spacer()
            Button("Dismiss") {
                viewModel.clearSavedSearchError()
            }
            .font(.caption)
            .accessibilityLabel("Dismiss error")
            .accessibilityHint("Double tap to hide this error message")
        }
        .padding()
        .background(Color.orange.opacity(0.1))
        .cornerRadius(8)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Sync error: \(message)")
    }
}

// MARK: - SavedSearchRow

struct SavedSearchRow: View {
    let search: SavedSearch
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            HStack(spacing: 12) {
                // Active indicator
                Circle()
                    .fill(search.isActive ? Color.green : Color.gray)
                    .frame(width: 10, height: 10)
                    .accessibilityHidden(true)

                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        Text(search.name)
                            .font(.headline)
                            .foregroundColor(.primary)

                        if let count = search.matchCount, count > 0 {
                            Text("\(count)")
                                .font(.caption)
                                .fontWeight(.semibold)
                                .foregroundColor(.white)
                                .padding(.horizontal, 6)
                                .padding(.vertical, 2)
                                .background(AppColors.brandTeal)
                                .clipShape(Capsule())
                        }
                    }

                    Text(search.filterSummary)
                        .font(.subheadline)
                        .foregroundColor(.secondary)
                        .lineLimit(1)

                    HStack(spacing: 8) {
                        // Notification frequency
                        Label(search.notificationFrequency.displayName, systemImage: "bell.fill")
                            .font(.caption)
                            .foregroundColor(.secondary)

                        Text("•")
                            .foregroundColor(.secondary)
                            .accessibilityHidden(true)

                        // Last updated
                        Text("Updated \(search.formattedUpdatedAt)")
                            .font(.caption)
                            .foregroundColor(.secondary)
                    }
                }

                Spacer()

                Image(systemName: "chevron.right")
                    .font(.caption)
                    .foregroundColor(.secondary)
                    .accessibilityHidden(true)
            }
            .padding(.vertical, 4)
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(search.name)\(search.isActive ? "" : ", paused"). \(search.filterSummary). \(search.matchCount ?? 0) matches. Notifications: \(search.notificationFrequency.displayName)")
        .accessibilityHint("Double tap to apply this search. Swipe left to edit, duplicate, or delete. Swipe right to pause or resume notifications.")
    }
}

// MARK: - Preview

#Preview {
    SavedSearchesView()
        .environmentObject(PropertySearchViewModel())
}
