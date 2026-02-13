//
//  KioskSignInView.swift
//  BMNBoston
//
//  Kiosk mode for iPad open house sign-in with property slideshow
//  Created for BMN Boston Real Estate
//
//  VERSION: v408
//

import SwiftUI

// MARK: - Kiosk Sign-In View

struct KioskSignInView: View {
    let openHouse: OpenHouse
    let onAttendeeAdded: (OpenHouseAttendee) -> Void

    @Environment(\.dismiss) private var dismiss
    @StateObject private var offlineStore = OfflineOpenHouseStore.shared
    @State private var propertyImages: [String] = []
    @State private var currentImageIndex = 0
    @State private var showSignInForm = false
    @State private var isLoading = true
    @State private var imageTimer: Timer?

    // Animation state
    @State private var imageOpacity: Double = 1.0

    private let slideInterval: TimeInterval = 5.0

    var body: some View {
        GeometryReader { geometry in
            ZStack {
                // Background slideshow
                slideshowBackground
                    .ignoresSafeArea()

                // Gradient overlay for text readability
                LinearGradient(
                    colors: [
                        Color.black.opacity(0.3),
                        Color.black.opacity(0.1),
                        Color.black.opacity(0.5)
                    ],
                    startPoint: .top,
                    endPoint: .bottom
                )
                .ignoresSafeArea()

                // Property info overlay at bottom
                VStack {
                    // Top bar with exit button and sync status
                    HStack {
                        Button {
                            stopSlideshow()
                            dismiss()
                        } label: {
                            Image(systemName: "xmark.circle.fill")
                                .font(.system(size: 36))
                                .symbolRenderingMode(.palette)
                                .foregroundStyle(.white, .black.opacity(0.5))
                                .accessibilityLabel("Exit sign-in kiosk")
                        }
                        .padding()

                        Spacer()

                        // Sync status indicator
                        if offlineStore.hasPendingSync || !offlineStore.isOnline || offlineStore.syncError != nil {
                            HStack(spacing: 6) {
                                Image(systemName: offlineStore.isOnline ? "exclamationmark.triangle.fill" : "wifi.slash")
                                    .font(.caption)
                                Text(offlineStore.syncError ?? offlineStore.networkStatusMessage)
                                    .font(.caption)
                                    .lineLimit(1)
                            }
                            .padding(.horizontal, 12)
                            .padding(.vertical, 6)
                            .background(offlineStore.isOnline ? Color.orange.opacity(0.9) : Color.red.opacity(0.9))
                            .foregroundStyle(.white)
                            .clipShape(Capsule())
                            .padding(.trailing, 16)
                        }
                    }

                    Spacer()

                    // Property info and sign-in button
                    propertyInfoOverlay(geometry: geometry)
                }
            }
        }
        .sheet(isPresented: $showSignInForm) {
            KioskSignInFormView(openHouse: openHouse) { attendee in
                onAttendeeAdded(attendee)
                showSignInForm = false
            }
            .interactiveDismissDisabled()
        }
        .onChange(of: showSignInForm) { isShowing in
            if isShowing {
                stopSlideshow()
            } else {
                startSlideshow()
            }
        }
        .task {
            await loadPropertyImages()
            startSlideshow()
        }
        .onDisappear {
            stopSlideshow()
        }
    }

    // MARK: - Slideshow Background

    @ViewBuilder
    private var slideshowBackground: some View {
        if isLoading {
            Color.black
                .overlay {
                    ProgressView()
                        .tint(.white)
                        .scaleEffect(1.5)
                }
        } else if propertyImages.isEmpty {
            // Fallback if no images
            if let photoUrl = openHouse.photoUrl, let url = URL(string: photoUrl) {
                AsyncImage(url: url) { image in
                    image
                        .resizable()
                        .aspectRatio(contentMode: .fill)
                } placeholder: {
                    placeholderBackground
                }
            } else {
                placeholderBackground
            }
        } else {
            // Current image with cross-fade
            ZStack {
                ForEach(Array(propertyImages.enumerated()), id: \.offset) { index, imageUrl in
                    if index == currentImageIndex, let url = URL(string: imageUrl) {
                        AsyncImage(url: url) { image in
                            image
                                .resizable()
                                .aspectRatio(contentMode: .fill)
                        } placeholder: {
                            Color.black
                        }
                        .opacity(imageOpacity)
                        .animation(.easeInOut(duration: 0.8), value: imageOpacity)
                    }
                }
            }
        }
    }

    private var placeholderBackground: some View {
        LinearGradient(
            colors: [
                Color(red: 26/255, green: 54/255, blue: 93/255),
                Color(red: 13/255, green: 27/255, blue: 42/255)
            ],
            startPoint: .topLeading,
            endPoint: .bottomTrailing
        )
        .overlay {
            Image(systemName: "house.fill")
                .font(.system(size: 100))
                .foregroundStyle(.white.opacity(0.2))
        }
    }

    // MARK: - Property Info Overlay

    private func propertyInfoOverlay(geometry: GeometryProxy) -> some View {
        VStack(alignment: .leading, spacing: 16) {
            // Address
            Text(openHouse.propertyAddress)
                .font(.system(size: 36, weight: .bold))
                .foregroundStyle(.white)
                .shadow(color: .black.opacity(0.5), radius: 4, x: 0, y: 2)

            Text("\(openHouse.propertyCity), \(openHouse.propertyState) \(openHouse.propertyZip)")
                .font(.system(size: 20))
                .foregroundStyle(.white.opacity(0.9))
                .shadow(color: .black.opacity(0.5), radius: 2, x: 0, y: 1)

            // Price and details
            HStack(spacing: 24) {
                if let formattedPrice = openHouse.formattedPrice {
                    Text(formattedPrice)
                        .font(.system(size: 28, weight: .semibold))
                        .foregroundStyle(.white)
                }

                if let beds = openHouse.beds {
                    HStack(spacing: 4) {
                        Image(systemName: "bed.double.fill")
                        Text("\(beds)")
                    }
                    .font(.system(size: 18))
                    .foregroundStyle(.white.opacity(0.9))
                }

                if let baths = openHouse.baths {
                    HStack(spacing: 4) {
                        Image(systemName: "shower.fill")
                        Text(String(format: "%.1f", baths))
                    }
                    .font(.system(size: 18))
                    .foregroundStyle(.white.opacity(0.9))
                }
            }

            // Sign In Button
            Button {
                showSignInForm = true
            } label: {
                HStack(spacing: 12) {
                    Image(systemName: "person.badge.plus")
                        .font(.system(size: 22))

                    Text("Sign In to Learn More")
                        .font(.system(size: 20, weight: .semibold))
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 32)
                .padding(.vertical, 18)
                .background(AppColors.brandTeal)
                .clipShape(RoundedRectangle(cornerRadius: 14))
                .shadow(color: .black.opacity(0.3), radius: 8, x: 0, y: 4)
            }
            .padding(.top, 12)

            // Image indicator dots
            if propertyImages.count > 1 {
                HStack(spacing: 8) {
                    ForEach(0..<min(propertyImages.count, 10), id: \.self) { index in
                        Circle()
                            .fill(index == currentImageIndex ? Color.white : Color.white.opacity(0.4))
                            .frame(width: 8, height: 8)
                    }
                }
                .padding(.top, 8)
            }
        }
        .padding(40)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            LinearGradient(
                colors: [Color.clear, Color.black.opacity(0.6)],
                startPoint: .top,
                endPoint: .bottom
            )
        )
    }

    // MARK: - Slideshow Control

    private func loadPropertyImages() async {
        isLoading = true
        defer { isLoading = false }

        do {
            propertyImages = try await OpenHouseService.shared.fetchPropertyImages(openHouseId: openHouse.id)
        } catch {
            print("Failed to load property images: \(error)")
            // Will fallback to open house photo
        }
    }

    private func startSlideshow() {
        guard propertyImages.count > 1 else { return }

        imageTimer = Timer.scheduledTimer(withTimeInterval: slideInterval, repeats: true) { _ in
            withAnimation {
                imageOpacity = 0.0
            }

            DispatchQueue.main.asyncAfter(deadline: .now() + 0.8) {
                currentImageIndex = (currentImageIndex + 1) % propertyImages.count
                withAnimation {
                    imageOpacity = 1.0
                }
            }
        }
    }

    private func stopSlideshow() {
        imageTimer?.invalidate()
        imageTimer = nil
    }
}
