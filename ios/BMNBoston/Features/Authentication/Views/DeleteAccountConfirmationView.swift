//
//  DeleteAccountConfirmationView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Apple App Store Guideline 5.1.1(v) compliance
//

import SwiftUI

/// Second step of account deletion: requires user to type "DELETE" to confirm
/// @since v203
struct DeleteAccountConfirmationView: View {
    @Environment(\.dismiss) private var dismiss
    @EnvironmentObject var authViewModel: AuthViewModel

    @State private var confirmationText = ""
    @State private var isDeleting = false
    @State private var errorMessage: String?

    private let requiredText = "DELETE"

    private var canDelete: Bool {
        confirmationText.uppercased() == requiredText && !isDeleting
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 24) {
                    // Warning icon
                    Image(systemName: "exclamationmark.triangle.fill")
                        .font(.system(size: 60))
                        .foregroundStyle(.red)
                        .padding(.top, 20)

                    // Header
                    Text("Confirm Account Deletion")
                        .font(.title2)
                        .fontWeight(.bold)

                    // Warning message
                    VStack(alignment: .leading, spacing: 12) {
                        Text("This action will permanently delete:")
                            .font(.subheadline)
                            .fontWeight(.semibold)

                        VStack(alignment: .leading, spacing: 8) {
                            bulletPoint("Your account and profile information")
                            bulletPoint("All saved searches and notifications")
                            bulletPoint("Saved and hidden properties")
                            bulletPoint("Appointment history")
                            bulletPoint("All activity and preferences")
                        }
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding()
                    .background(Color.red.opacity(0.1))
                    .cornerRadius(12)

                    // Confirmation input
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Type DELETE to confirm:")
                            .font(.subheadline)
                            .fontWeight(.medium)

                        TextField("DELETE", text: $confirmationText)
                            .textFieldStyle(.roundedBorder)
                            .autocapitalization(.allCharacters)
                            .disableAutocorrection(true)
                            .disabled(isDeleting)
                    }

                    // Error message
                    if let error = errorMessage {
                        Text(error)
                            .font(.subheadline)
                            .foregroundStyle(.red)
                            .multilineTextAlignment(.center)
                    }

                    // Delete button
                    Button {
                        Task {
                            await deleteAccount()
                        }
                    } label: {
                        HStack {
                            if isDeleting {
                                ProgressView()
                                    .tint(.white)
                            }
                            Text(isDeleting ? "Deleting..." : "Delete My Account")
                                .fontWeight(.semibold)
                        }
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(canDelete ? Color.red : Color.gray)
                        .foregroundStyle(.white)
                        .cornerRadius(12)
                    }
                    .disabled(!canDelete)

                    // Cancel button
                    Button("Cancel") {
                        dismiss()
                    }
                    .foregroundStyle(.secondary)
                    .disabled(isDeleting)

                    Spacer()
                }
                .padding()
            }
            .navigationTitle("Delete Account")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        dismiss()
                    }
                    .disabled(isDeleting)
                }
            }
            .interactiveDismissDisabled(isDeleting)
        }
    }

    @ViewBuilder
    private func bulletPoint(_ text: String) -> some View {
        HStack(alignment: .top, spacing: 8) {
            Text("•")
            Text(text)
        }
    }

    private func deleteAccount() async {
        isDeleting = true
        errorMessage = nil

        let result = await authViewModel.deleteAccount()

        switch result {
        case .success:
            // AuthViewModel handles state reset, just dismiss
            dismiss()
        case .failure(let error):
            isDeleting = false
            if let apiError = error as? APIError {
                errorMessage = apiError.errorDescription
            } else {
                errorMessage = "Failed to delete account. Please try again."
            }
        }
    }
}

#Preview {
    DeleteAccountConfirmationView()
        .environmentObject(AuthViewModel())
}
