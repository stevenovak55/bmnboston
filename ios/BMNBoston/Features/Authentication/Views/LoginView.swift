//
//  LoginView.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//

import SwiftUI

struct LoginView: View {
    @EnvironmentObject var authViewModel: AuthViewModel

    @State private var email = ""
    @State private var password = ""
    @State private var showPassword = false
    @State private var showingRegister = false
    @State private var showingForgotPassword = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 32) {
                    // Logo
                    VStack(spacing: 12) {
                        Image("Logo")
                            .resizable()
                            .aspectRatio(contentMode: .fit)
                            .frame(maxWidth: 280)

                        Text("Find your perfect home")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.top, 60)
                    .padding(.bottom, 8)

                    // Login form
                    VStack(spacing: 16) {
                        TextField("Email", text: $email)
                            .textFieldStyle(.roundedBorder)
                            .textContentType(.emailAddress)
                            .keyboardType(.emailAddress)
                            .autocapitalization(.none)
                            .autocorrectionDisabled()

                        HStack {
                            if showPassword {
                                TextField("Password", text: $password)
                                    .textContentType(.password)
                                    .autocapitalization(.none)
                                    .autocorrectionDisabled()
                            } else {
                                SecureField("Password", text: $password)
                                    .textContentType(.password)
                            }

                            Button {
                                showPassword.toggle()
                            } label: {
                                Image(systemName: showPassword ? "eye.slash.fill" : "eye.fill")
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .padding(.horizontal, 12)
                        .padding(.vertical, 8)
                        .background(Color(.systemGray6))
                        .clipShape(RoundedRectangle(cornerRadius: 6))
                        .overlay(
                            RoundedRectangle(cornerRadius: 6)
                                .stroke(Color(.systemGray4), lineWidth: 0.5)
                        )

                        if let error = authViewModel.errorMessage {
                            Text(error)
                                .font(.caption)
                                .foregroundStyle(.red)
                                .multilineTextAlignment(.center)
                        }

                        Button {
                            Task {
                                await authViewModel.login(email: email, password: password)
                            }
                        } label: {
                            if authViewModel.isLoading {
                                ProgressView()
                                    .progressViewStyle(CircularProgressViewStyle(tint: .white))
                            } else {
                                Text("Log In")
                            }
                        }
                        .buttonStyle(PrimaryButtonStyle())
                        .disabled(authViewModel.isLoading)

                        Button("Forgot password?") {
                            showingForgotPassword = true
                        }
                        .font(.subheadline)
                        .foregroundStyle(AppColors.primary)
                    }
                    .padding(.horizontal, 24)

                    // Divider
                    HStack {
                        Rectangle()
                            .fill(AppColors.border)
                            .frame(height: 1)
                        Text("or")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Rectangle()
                            .fill(AppColors.border)
                            .frame(height: 1)
                    }
                    .padding(.horizontal, 24)

                    // Continue as guest
                    VStack(spacing: 16) {
                        Button("Continue as Guest") {
                            authViewModel.continueAsGuest()
                        }
                        .buttonStyle(SecondaryButtonStyle())
                        .padding(.horizontal, 24)

                        HStack {
                            Text("Don't have an account?")
                                .foregroundStyle(.secondary)
                            Button("Sign Up") {
                                showingRegister = true
                            }
                            .foregroundStyle(AppColors.primary)
                            .fontWeight(.semibold)
                        }
                        .font(.subheadline)
                    }

                    Spacer()
                }
            }
            .navigationBarHidden(true)
            .sheet(isPresented: $showingRegister) {
                RegisterView()
            }
            .sheet(isPresented: $showingForgotPassword) {
                ForgotPasswordView()
            }
        }
    }
}

// MARK: - Register View

struct RegisterView: View {
    @EnvironmentObject var authViewModel: AuthViewModel
    @Environment(\.dismiss) var dismiss

    @State private var email = ""
    @State private var password = ""
    @State private var confirmPassword = ""
    @State private var firstName = ""
    @State private var lastName = ""
    @State private var phone = ""
    @State private var referralCode = ""
    @State private var showPassword = false
    @State private var showConfirmPassword = false
    @State private var localError: String?

    var body: some View {
        NavigationStack {
            Form {
                Section("Personal Information") {
                    TextField("First Name", text: $firstName)
                        .textContentType(.givenName)
                    TextField("Last Name", text: $lastName)
                        .textContentType(.familyName)
                    TextField("Phone (optional)", text: $phone)
                        .textContentType(.telephoneNumber)
                        .keyboardType(.phonePad)
                }

                Section("Account") {
                    TextField("Email", text: $email)
                        .textContentType(.emailAddress)
                        .keyboardType(.emailAddress)
                        .autocapitalization(.none)
                        .autocorrectionDisabled()

                    HStack {
                        if showPassword {
                            TextField("Password", text: $password)
                                .textContentType(.newPassword)
                                .autocapitalization(.none)
                                .autocorrectionDisabled()
                        } else {
                            SecureField("Password", text: $password)
                                .textContentType(.newPassword)
                        }

                        Button {
                            showPassword.toggle()
                        } label: {
                            Image(systemName: showPassword ? "eye.slash.fill" : "eye.fill")
                                .foregroundStyle(.secondary)
                        }
                    }

                    HStack {
                        if showConfirmPassword {
                            TextField("Confirm Password", text: $confirmPassword)
                                .textContentType(.newPassword)
                                .autocapitalization(.none)
                                .autocorrectionDisabled()
                        } else {
                            SecureField("Confirm Password", text: $confirmPassword)
                                .textContentType(.newPassword)
                        }

                        Button {
                            showConfirmPassword.toggle()
                        } label: {
                            Image(systemName: showConfirmPassword ? "eye.slash.fill" : "eye.fill")
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                Section {
                    TextField("Referral Code (optional)", text: $referralCode)
                        .autocapitalization(.allCharacters)
                        .autocorrectionDisabled()
                } header: {
                    Text("Referral")
                } footer: {
                    Text("If you have a referral code from an agent, enter it here")
                        .font(.caption)
                }

                if let error = localError ?? authViewModel.errorMessage {
                    Section {
                        Text(error)
                            .foregroundStyle(.red)
                    }
                }

                Section {
                    Button {
                        register()
                    } label: {
                        if authViewModel.isLoading {
                            ProgressView()
                        } else {
                            Text("Create Account")
                        }
                    }
                    .disabled(authViewModel.isLoading)
                }
            }
            .navigationTitle("Sign Up")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        dismiss()
                    }
                }
            }
            .onAppear {
                // Pre-fill referral code from deep link if available
                if let code = ReferralCodeManager.shared.pendingReferralCode, !code.isEmpty {
                    referralCode = code
                }
            }
        }
    }

    private func register() {
        localError = nil

        guard password == confirmPassword else {
            localError = "Passwords do not match"
            return
        }

        guard password.count >= 8 else {
            localError = "Password must be at least 8 characters"
            return
        }

        Task {
            await authViewModel.register(
                email: email,
                password: password,
                firstName: firstName,
                lastName: lastName,
                phone: phone.isEmpty ? nil : phone,
                referralCode: referralCode.isEmpty ? nil : referralCode
            )

            if authViewModel.isAuthenticated {
                dismiss()
            }
        }
    }
}

// MARK: - Forgot Password View

struct ForgotPasswordView: View {
    @EnvironmentObject var authViewModel: AuthViewModel
    @Environment(\.dismiss) var dismiss

    @State private var email = ""
    @State private var showingSuccess = false

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Text("Enter your email address and we'll send you instructions to reset your password.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }

                Section {
                    TextField("Email", text: $email)
                        .textContentType(.emailAddress)
                        .keyboardType(.emailAddress)
                        .autocapitalization(.none)
                        .autocorrectionDisabled()
                }

                if let error = authViewModel.errorMessage {
                    Section {
                        Text(error)
                            .foregroundStyle(.red)
                    }
                }

                Section {
                    Button {
                        Task {
                            let success = await authViewModel.forgotPassword(email: email)
                            if success {
                                showingSuccess = true
                            }
                        }
                    } label: {
                        if authViewModel.isLoading {
                            ProgressView()
                        } else {
                            Text("Send Reset Link")
                        }
                    }
                    .disabled(authViewModel.isLoading)
                }
            }
            .navigationTitle("Reset Password")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        dismiss()
                    }
                }
            }
            .alert("Check Your Email", isPresented: $showingSuccess) {
                Button("OK") {
                    dismiss()
                }
            } message: {
                Text("We've sent password reset instructions to \(email)")
            }
        }
    }
}

#Preview {
    LoginView()
        .environmentObject(AuthViewModel())
}
