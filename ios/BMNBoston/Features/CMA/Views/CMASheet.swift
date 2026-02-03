//
//  CMASheet.swift
//  BMNBoston
//
//  Quick CMA feature - shows comparables and generates PDF reports
//

import SwiftUI

struct CMASheet: View {
    let property: PropertyDetail
    @Environment(\.dismiss) private var dismiss

    @State private var cmaData: CMAResponse?
    @State private var isLoading = true
    @State private var isGeneratingPDF = false
    @State private var pdfData: Data?
    @State private var pdfURL: URL?
    @State private var error: String?
    @State private var showShareSheet = false
    @State private var showPDFView = false
    @State private var preparedFor: String = ""
    @State private var showPreparedForPrompt = false
    @State private var selectedCompIds: Set<String> = []

    var body: some View {
        NavigationView {
            content
                .navigationTitle("CMA Report")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Close") { dismiss() }
                    }
                }
        }
        .task { await loadCMAData() }
        .sheet(isPresented: $showPDFView) {
            if let url = pdfURL {
                CMAPDFView(pdfURL: url)
            }
        }
        .alert("Prepared For", isPresented: $showPreparedForPrompt) {
            TextField("Client name (optional)", text: $preparedFor)
            Button("Generate") {
                Task { await generatePDF() }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Enter the client's name for the report (optional)")
        }
    }

    @ViewBuilder
    private var content: some View {
        if isLoading {
            loadingView
        } else if let error = error {
            errorView(error)
        } else if let cma = cmaData {
            cmaContentView(cma)
        } else {
            errorView("Unable to load CMA data")
        }
    }

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
                .scaleEffect(1.2)
            Text("Loading comparables...")
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private func errorView(_ message: String) -> some View {
        VStack(spacing: 16) {
            Image(systemName: "exclamationmark.triangle.fill")
                .font(.system(size: 40))
                .foregroundStyle(.orange)
            Text(message)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)
            Button("Try Again") {
                Task { await loadCMAData() }
            }
            .buttonStyle(.bordered)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private func cmaContentView(_ cma: CMAResponse) -> some View {
        ScrollView {
            VStack(spacing: 20) {
                // Subject Property Card
                subjectPropertyCard(cma.subject)

                // Estimated Value
                estimatedValueCard(cma)

                // Comparables List
                comparablesSection(cma.comparables)

                // Generate PDF Button
                generatePDFButton
                    .padding(.top, 8)
                    .padding(.bottom, 32)
            }
            .padding()
        }
    }

    private func subjectPropertyCard(_ subject: CMASubjectProperty) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text("Subject Property")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)
                Spacer()
            }

            HStack(spacing: 12) {
                // Property photo
                if let photoUrlString = property.photoUrl, let photoURL = URL(string: photoUrlString) {
                    AsyncImage(url: photoURL) { image in
                        image
                            .resizable()
                            .aspectRatio(contentMode: .fill)
                    } placeholder: {
                        Color.gray.opacity(0.2)
                    }
                    .frame(width: 80, height: 60)
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                } else {
                    RoundedRectangle(cornerRadius: 8)
                        .fill(Color.gray.opacity(0.2))
                        .frame(width: 80, height: 60)
                        .overlay {
                            Image(systemName: "house.fill")
                                .foregroundStyle(.gray)
                        }
                }

                VStack(alignment: .leading, spacing: 4) {
                    Text(subject.address)
                        .font(.headline)
                    Text("\(subject.city), \(subject.state)")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    Text(subject.summary)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding()
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.05), radius: 5, y: 2)
    }

    private func estimatedValueCard(_ cma: CMAResponse) -> some View {
        VStack(spacing: 16) {
            Text("Estimated Value")
                .font(.caption)
                .fontWeight(.semibold)
                .foregroundStyle(.secondary)
                .textCase(.uppercase)

            // Value Range
            Text(cma.valueRange.formatted)
                .font(.system(size: 28, weight: .bold))
                .foregroundStyle(AppColors.brandTeal)

            // Mid-point
            if let mid = cma.valueRange.mid {
                Text("Mid: $\(mid.formatted())")
                    .font(.headline)
                    .foregroundStyle(.secondary)
            }

            // Confidence Badge
            HStack(spacing: 6) {
                Circle()
                    .fill(confidenceColor(cma.confidenceColor))
                    .frame(width: 8, height: 8)
                Text("Confidence: \(cma.confidenceScore ?? 0)% (\(cma.confidenceLevel))")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 6)
            .background(Color(.tertiarySystemBackground))
            .clipShape(Capsule())

            // Range Quality Indicator
            if let quality = cma.rangeQuality, quality != "unknown" {
                HStack(spacing: 6) {
                    Image(systemName: rangeQualityIcon(quality))
                    Text(cma.rangeQualityDescription)
                        .font(.caption)
                }
                .foregroundStyle(rangeQualityColor(quality))
            }
        }
        .frame(maxWidth: .infinity)
        .padding()
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.05), radius: 5, y: 2)
    }

    private func rangeQualityIcon(_ quality: String) -> String {
        switch quality {
        case "tight": return "checkmark.seal.fill"
        case "moderate": return "seal.fill"
        default: return "exclamationmark.triangle.fill"
        }
    }

    private func rangeQualityColor(_ quality: String) -> Color {
        switch quality {
        case "tight": return .green
        case "moderate": return .blue
        default: return .orange
        }
    }

    private func confidenceColor(_ colorName: String) -> Color {
        switch colorName {
        case "green": return .green
        case "orange": return .orange
        case "red": return .red
        default: return .gray
        }
    }

    private func comparablesSection(_ comparables: [CMAComparable]) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            // Header with selection controls
            HStack {
                Text("Comparable Sales")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)

                Text("(\(selectedCompIds.count) of \(comparables.count) selected)")
                    .font(.caption)
                    .foregroundStyle(.secondary)

                Spacer()

                // Select All / Deselect All button
                Button {
                    if selectedCompIds.count == comparables.count {
                        selectedCompIds.removeAll()
                    } else {
                        selectedCompIds = Set(comparables.map { $0.id })
                    }
                } label: {
                    Text(selectedCompIds.count == comparables.count ? "Deselect All" : "Select All")
                        .font(.caption)
                        .foregroundStyle(AppColors.brandTeal)
                }
            }

            if comparables.isEmpty {
                Text("No comparable sales found")
                    .foregroundStyle(.secondary)
                    .padding()
                    .frame(maxWidth: .infinity)
                    .background(Color(.tertiarySystemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 8))
            } else {
                ForEach(comparables) { comp in
                    comparableRow(comp, isSelected: selectedCompIds.contains(comp.id))
                }
            }

            // Selection hint
            if !comparables.isEmpty {
                Text("Tap comparables to include/exclude from PDF report")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
                    .frame(maxWidth: .infinity)
            }
        }
    }

    private func comparableRow(_ comp: CMAComparable, isSelected: Bool) -> some View {
        Button {
            if selectedCompIds.contains(comp.id) {
                selectedCompIds.remove(comp.id)
            } else {
                selectedCompIds.insert(comp.id)
            }
        } label: {
            HStack(spacing: 12) {
                // Selection checkbox
                Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                    .font(.title3)
                    .foregroundStyle(isSelected ? AppColors.brandTeal : .gray)

                // Photo
                if let photoURL = comp.photoURL {
                    AsyncImage(url: photoURL) { image in
                        image
                            .resizable()
                            .aspectRatio(contentMode: .fill)
                    } placeholder: {
                        Color.gray.opacity(0.2)
                    }
                    .frame(width: 60, height: 45)
                    .clipShape(RoundedRectangle(cornerRadius: 6))
                } else {
                    RoundedRectangle(cornerRadius: 6)
                        .fill(Color.gray.opacity(0.2))
                        .frame(width: 60, height: 45)
                        .overlay {
                            Image(systemName: "house.fill")
                                .font(.caption)
                                .foregroundStyle(.gray)
                        }
                }

                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 6) {
                        Text(comp.address)
                            .font(.subheadline)
                            .fontWeight(.medium)
                            .lineLimit(1)

                        if let grade = comp.comparabilityGrade {
                            Text(grade)
                                .font(.caption2)
                                .fontWeight(.bold)
                                .foregroundStyle(.white)
                                .padding(.horizontal, 6)
                                .padding(.vertical, 2)
                                .background(comp.gradeColor)
                                .clipShape(Capsule())
                        }
                    }

                    HStack(spacing: 8) {
                        Text(comp.formattedDistance)
                            .font(.caption)
                            .foregroundStyle(.secondary)

                        if let ppsf = comp.formattedPricePerSqft {
                            Text("\u{2022}")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            Text(ppsf)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                Spacer()

                VStack(alignment: .trailing, spacing: 2) {
                    Text("$\(comp.soldPrice.formatted())")
                        .font(.subheadline)
                        .fontWeight(.semibold)
                    if let soldDate = comp.formattedSoldDate {
                        Text(soldDate)
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .padding()
            .background(isSelected ? AppColors.brandTeal.opacity(0.08) : Color(.systemBackground))
            .clipShape(RoundedRectangle(cornerRadius: 10))
            .overlay(
                RoundedRectangle(cornerRadius: 10)
                    .stroke(isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 2)
            )
            .shadow(color: .black.opacity(0.03), radius: 3, y: 1)
        }
        .buttonStyle(.plain)
    }

    private var generatePDFButton: some View {
        VStack(spacing: 8) {
            Button {
                showPreparedForPrompt = true
            } label: {
                HStack {
                    if isGeneratingPDF {
                        ProgressView()
                            .tint(.white)
                    } else {
                        Image(systemName: "doc.fill")
                    }
                    if isGeneratingPDF {
                        Text("Generating...")
                    } else if selectedCompIds.isEmpty {
                        Text("Select Comparables to Generate PDF")
                    } else {
                        Text("Generate PDF with \(selectedCompIds.count) Comp\(selectedCompIds.count == 1 ? "" : "s")")
                    }
                }
                .frame(maxWidth: .infinity)
                .padding()
                .background(selectedCompIds.isEmpty ? Color.gray : AppColors.brandTeal)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .disabled(isGeneratingPDF || cmaData == nil || selectedCompIds.isEmpty)

            if selectedCompIds.isEmpty && cmaData != nil {
                Text("Please select at least one comparable")
                    .font(.caption)
                    .foregroundStyle(.orange)
            }
        }
    }

    // MARK: - Data Loading

    private func loadCMAData() async {
        isLoading = true
        error = nil

        do {
            // Use the listing key (id) for the API call
            let listingId = property.id
            let response: CMAResponse = try await APIClient.shared.request(.propertyCMA(listingId: listingId))
            cmaData = response
            // Initialize all comparables as selected
            selectedCompIds = Set(response.comparables.map { $0.id })
            isLoading = false
        } catch {
            self.error = error.localizedDescription
            isLoading = false
        }
    }

    private func generatePDF() async {
        isGeneratingPDF = true

        do {
            let listingId = property.id
            let selectedIds = Array(selectedCompIds)
            let response: CMAPDFResponse = try await APIClient.shared.request(
                .generateCMAPDF(
                    listingId: listingId,
                    preparedFor: preparedFor.isEmpty ? nil : preparedFor,
                    selectedComparables: selectedIds.isEmpty ? nil : selectedIds
                )
            )

            if let url = response.url {
                pdfURL = url
                showPDFView = true
            } else {
                error = "Invalid PDF URL returned"
            }
            isGeneratingPDF = false
        } catch {
            self.error = "Failed to generate PDF: \(error.localizedDescription)"
            isGeneratingPDF = false
        }
    }
}

#Preview {
    // Note: Preview requires a PropertyDetail instance
    Text("CMASheet Preview")
}
