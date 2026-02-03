//
//  CMAPDFView.swift
//  BMNBoston
//
//  Displays and allows sharing of CMA PDF reports
//

import SwiftUI
import PDFKit
import UIKit

struct CMAPDFView: View {
    let pdfURL: URL
    @Environment(\.dismiss) private var dismiss

    @State private var pdfDocument: PDFDocument?
    @State private var isLoading = true
    @State private var error: String?
    @State private var showShareSheet = false
    @State private var localPDFURL: URL?

    var body: some View {
        NavigationView {
            content
                .navigationTitle("CMA Report")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Close") { dismiss() }
                    }
                    ToolbarItem(placement: .primaryAction) {
                        if pdfDocument != nil {
                            shareButton
                        }
                    }
                }
        }
        .task { await loadPDF() }
        .sheet(isPresented: $showShareSheet) {
            if let url = localPDFURL {
                ShareSheet(items: [url])
            }
        }
    }

    @ViewBuilder
    private var content: some View {
        if isLoading {
            loadingView
        } else if let error = error {
            errorView(error)
        } else if let document = pdfDocument {
            PDFKitView(document: document)
                .ignoresSafeArea(.all, edges: .bottom)
        } else {
            errorView("Unable to load PDF")
        }
    }

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
                .scaleEffect(1.2)
            Text("Loading PDF...")
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private func errorView(_ message: String) -> some View {
        VStack(spacing: 16) {
            Image(systemName: "doc.fill.badge.exclamationmark")
                .font(.system(size: 40))
                .foregroundStyle(.orange)
            Text(message)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal)
            Button("Try Again") {
                Task { await loadPDF() }
            }
            .buttonStyle(.bordered)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private var shareButton: some View {
        Button {
            showShareSheet = true
        } label: {
            Image(systemName: "square.and.arrow.up")
        }
    }

    // MARK: - PDF Loading

    private func loadPDF() async {
        isLoading = true
        error = nil

        do {
            // Download PDF to a temporary file
            let (data, _) = try await URLSession.shared.data(from: pdfURL)

            // Save to temp file for sharing
            let tempDir = FileManager.default.temporaryDirectory
            let fileName = "CMA-Report-\(Date().timeIntervalSince1970).pdf"
            let tempURL = tempDir.appendingPathComponent(fileName)

            try data.write(to: tempURL)
            localPDFURL = tempURL

            // Create PDF document
            if let document = PDFDocument(data: data) {
                await MainActor.run {
                    self.pdfDocument = document
                    self.isLoading = false
                }
            } else {
                await MainActor.run {
                    self.error = "Invalid PDF data"
                    self.isLoading = false
                }
            }
        } catch {
            await MainActor.run {
                self.error = "Failed to load PDF: \(error.localizedDescription)"
                self.isLoading = false
            }
        }
    }
}

// MARK: - PDFKit SwiftUI Wrapper

struct PDFKitView: UIViewRepresentable {
    let document: PDFDocument

    func makeUIView(context: Context) -> PDFView {
        let pdfView = PDFView()
        pdfView.document = document
        pdfView.autoScales = true
        pdfView.displayMode = .singlePageContinuous
        pdfView.displayDirection = .vertical
        pdfView.backgroundColor = UIColor.systemBackground
        return pdfView
    }

    func updateUIView(_ pdfView: PDFView, context: Context) {
        pdfView.document = document
    }
}

#Preview {
    CMAPDFView(pdfURL: URL(string: "https://example.com/test.pdf")!)
}
