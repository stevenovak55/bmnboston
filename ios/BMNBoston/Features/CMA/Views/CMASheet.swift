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

    // Expandable adjustment tracking
    @State private var expandedAdjustmentIds: Set<String> = []

    // Manual adjustment overrides per comparable
    @State private var manualAdjustments: [String: CMAManualAdjustment] = [:]
    @State private var editingAdjustmentId: String? = nil

    // AI Condition Analysis state (v6.75.0)
    @State private var analyzingIds: Set<String> = []
    @State private var analysisResults: [String: ConditionAnalysisResponse] = [:]
    @State private var analysisError: String?

    // Subject property condition (v6.75.1) - baseline for comparing comps
    @State private var subjectCondition: CMACondition = .someUpdates
    @State private var subjectConditionExpanded = false

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

                // Market Context Card (if available)
                if let marketContext = cma.marketContext {
                    marketContextCard(marketContext)
                }

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

            // Subject Condition Selection (v6.75.1)
            Divider()

            // Collapsible condition section
            Button {
                withAnimation(.easeInOut(duration: 0.2)) {
                    subjectConditionExpanded.toggle()
                }
            } label: {
                HStack {
                    Image(systemName: subjectConditionExpanded ? "chevron.down" : "chevron.right")
                        .font(.caption)
                    Text("Subject Condition")
                        .font(.caption)
                        .fontWeight(.semibold)
                    Spacer()
                    Text(subjectCondition.displayName)
                        .font(.caption)
                        .foregroundStyle(subjectCondition == .someUpdates ? .secondary : AppColors.brandTeal)
                    if subjectCondition != .someUpdates {
                        Image(systemName: "checkmark.circle.fill")
                            .font(.caption)
                            .foregroundStyle(AppColors.brandTeal)
                    }
                }
                .foregroundStyle(.primary)
            }

            if subjectConditionExpanded {
                VStack(alignment: .leading, spacing: 8) {
                    Text("Set the subject's condition to calculate relative adjustments for comparables.")
                        .font(.caption2)
                        .foregroundStyle(.tertiary)

                    // Condition picker buttons
                    ForEach(CMACondition.allCases) { condition in
                        Button {
                            subjectCondition = condition
                        } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(condition.displayName)
                                        .font(.subheadline)
                                        .fontWeight(subjectCondition == condition ? .semibold : .regular)
                                    Text(conditionDescription(condition))
                                        .font(.caption2)
                                        .foregroundStyle(.secondary)
                                }
                                Spacer()
                                if subjectCondition == condition {
                                    Image(systemName: "checkmark.circle.fill")
                                        .foregroundStyle(AppColors.brandTeal)
                                }
                            }
                            .padding(.vertical, 8)
                            .padding(.horizontal, 12)
                            .background(subjectCondition == condition ? AppColors.brandTeal.opacity(0.1) : Color.clear)
                            .clipShape(RoundedRectangle(cornerRadius: 8))
                        }
                        .buttonStyle(.plain)
                    }
                }
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .padding()
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.05), radius: 5, y: 2)
    }

    /// Description text for each condition level
    private func conditionDescription(_ condition: CMACondition) -> String {
        switch condition {
        case .newConstruction:
            return "Brand new, never occupied"
        case .fullyRenovated:
            return "Completely updated kitchen, baths, flooring"
        case .someUpdates:
            return "Mix of updated and original features"
        case .needsUpdating:
            return "Dated finishes, functional but tired"
        case .distressed:
            return "Major repairs needed"
        }
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

    // MARK: - Market Context Card

    private func marketContextCard(_ context: CMAMarketContext) -> some View {
        VStack(alignment: .leading, spacing: 16) {
            // Header
            HStack {
                Image(systemName: "chart.line.uptrend.xyaxis")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                Text("Market Conditions")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)
                    .textCase(.uppercase)
                Spacer()
            }

            // Market Type Badge
            HStack(spacing: 8) {
                Image(systemName: context.marketTypeIcon)
                    .font(.title2)
                    .foregroundStyle(marketTypeColor(context.marketTypeColorName))
                VStack(alignment: .leading, spacing: 2) {
                    Text(context.marketTypeDisplay)
                        .font(.headline)
                        .fontWeight(.semibold)
                    if let desc = context.description {
                        Text(desc)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
            }

            // Stats Grid
            HStack(spacing: 16) {
                if let dom = context.formattedAvgDom {
                    marketStatItem(value: dom, label: "Avg DOM", icon: "clock")
                }
                if let ratio = context.formattedSpLpRatio {
                    VStack(spacing: 4) {
                        Image(systemName: "percent")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Text(ratio)
                            .font(.subheadline)
                            .fontWeight(.semibold)
                        Text("SP/LP Ratio")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                        if let desc = context.spLpDescription {
                            Text(desc)
                                .font(.caption2)
                                .foregroundStyle(context.avgSpLpRatio ?? 100 > 100 ? .green : .secondary)
                        }
                    }
                    .frame(maxWidth: .infinity)
                }
                if let velocity = context.formattedVelocity {
                    marketStatItem(value: velocity, label: "Velocity", icon: "speedometer")
                }
            }
        }
        .padding()
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.05), radius: 5, y: 2)
    }

    private func marketStatItem(value: String, label: String, icon: String) -> some View {
        VStack(spacing: 4) {
            Image(systemName: icon)
                .font(.caption)
                .foregroundStyle(.secondary)
            Text(value)
                .font(.subheadline)
                .fontWeight(.semibold)
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }

    private func marketTypeColor(_ colorName: String) -> Color {
        switch colorName {
        case "green": return .green
        case "blue": return .blue
        default: return .gray
        }
    }

    // MARK: - Comparables Section

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
        let isExpanded = expandedAdjustmentIds.contains(comp.id)
        let hasCustomAdjustment = manualAdjustments[comp.id]?.hasAdjustments ?? false

        return VStack(spacing: 0) {
            // Main row (tappable for selection)
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

                            // Custom badge if manual adjustments
                            if hasCustomAdjustment {
                                Text("Custom")
                                    .font(.caption2)
                                    .fontWeight(.semibold)
                                    .foregroundStyle(.white)
                                    .padding(.horizontal, 6)
                                    .padding(.vertical, 2)
                                    .background(Color.purple)
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

                    // Price column with adjusted price
                    VStack(alignment: .trailing, spacing: 2) {
                        // Show adjusted price with original strikethrough
                        if let adjustedPrice = calculateFinalAdjustedPrice(for: comp) {
                            Text("$\(comp.soldPrice.formatted())")
                                .font(.caption)
                                .strikethrough()
                                .foregroundStyle(.secondary)
                            Text("$\(adjustedPrice.formatted())")
                                .font(.subheadline)
                                .fontWeight(.semibold)
                                .foregroundStyle(AppColors.brandTeal)
                        } else {
                            Text("$\(comp.soldPrice.formatted())")
                                .font(.subheadline)
                                .fontWeight(.semibold)
                        }

                        // Adjustment badge
                        if let adj = comp.adjustments, let total = adj.totalAdjustment, total != 0 {
                            Text(adj.formattedTotalAdjustment)
                                .font(.caption2)
                                .foregroundStyle(total > 0 ? .green : .red)
                        }

                        if let soldDate = comp.formattedSoldDate {
                            Text(soldDate)
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
                .padding()
            }
            .buttonStyle(.plain)

            // Expandable adjustments section - ALWAYS show for manual adjustments
            Button {
                withAnimation(.easeInOut(duration: 0.2)) {
                    if isExpanded {
                        expandedAdjustmentIds.remove(comp.id)
                    } else {
                        expandedAdjustmentIds.insert(comp.id)
                    }
                }
            } label: {
                HStack {
                    Image(systemName: isExpanded ? "chevron.up" : "chevron.down")
                        .font(.caption)

                    // Show different text based on whether API adjustments exist
                    if comp.hasAdjustments {
                        Text(isExpanded ? "Hide Adjustments" : "View & Customize")
                            .font(.caption)
                    } else {
                        Image(systemName: "slider.horizontal.3")
                            .font(.caption)
                        Text(isExpanded ? "Hide Adjustments" : "Customize Adjustments")
                            .font(.caption)
                    }

                    if let adj = comp.adjustments, let netPct = adj.formattedNetPct {
                        Text("(\(netPct))")
                            .font(.caption)
                            .foregroundStyle(adj.hasWarning ? .orange : .secondary)
                    }
                    Spacer()
                    if hasCustomAdjustment {
                        Button("Reset") {
                            manualAdjustments[comp.id] = nil
                        }
                        .font(.caption)
                        .foregroundStyle(.red)
                    }
                }
                .foregroundStyle(AppColors.brandTeal)
                .padding(.horizontal)
                .padding(.vertical, 8)
                .background(Color(.tertiarySystemBackground))
            }
            .buttonStyle(.plain)

            // Expanded adjustment details
            if isExpanded {
                adjustmentDetailsView(for: comp)
            }
        }
        .background(isSelected ? AppColors.brandTeal.opacity(0.08) : Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(
            RoundedRectangle(cornerRadius: 10)
                .stroke(isSelected ? AppColors.brandTeal : Color.clear, lineWidth: 2)
        )
        .shadow(color: .black.opacity(0.03), radius: 3, y: 1)
    }

    // MARK: - Adjustment Details View

    private func adjustmentDetailsView(for comp: CMAComparable) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            // Manual adjustments section - shown FIRST for prominence
            manualAdjustmentControls(for: comp)

            // API-provided adjustments (read-only)
            if let adj = comp.adjustments, let items = adj.items, !items.isEmpty {
                Divider()

                Text("Calculated Adjustments")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)

                ForEach(items) { item in
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(item.feature)
                                .font(.caption)
                                .fontWeight(.medium)
                            Text(item.difference)
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                        Text(item.formattedAdjustment)
                            .font(.caption)
                            .fontWeight(.semibold)
                            .foregroundStyle(item.adjustment >= 0 ? .green : .red)
                    }
                }
            }

            // Total line
            if let adj = comp.adjustments, let total = adj.totalAdjustment {
                // Use helper function to calculate manual adjustments (can't do inline in ViewBuilder)
                let manualTotal = calculateManualAdjustments(for: comp)
                let finalTotal = total + manualTotal

                Divider()
                HStack {
                    Text("Total Adjustment")
                        .font(.caption)
                        .fontWeight(.semibold)
                    Spacer()
                    Text(formatAdjustmentAmount(finalTotal))
                        .font(.caption)
                        .fontWeight(.bold)
                        .foregroundStyle(finalTotal >= 0 ? .green : .red)
                }

                if let netPct = adj.netPct {
                    HStack {
                        Text("Net Adjustment")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                        Spacer()
                        Text(String(format: "%.1f%%", abs(netPct)))
                            .font(.caption)
                            .foregroundStyle(adj.hasWarning ? .orange : .secondary)
                        if adj.hasWarning {
                            Image(systemName: "exclamationmark.triangle.fill")
                                .font(.caption)
                                .foregroundStyle(.orange)
                        }
                    }
                }
            }
        }
        .padding()
        .background(Color(.secondarySystemBackground))
    }

    // MARK: - Manual Adjustment Controls

    private func manualAdjustmentControls(for comp: CMAComparable) -> some View {
        let binding = Binding<CMAManualAdjustment>(
            get: { manualAdjustments[comp.id] ?? CMAManualAdjustment() },
            set: { manualAdjustments[comp.id] = $0 }
        )

        return VStack(alignment: .leading, spacing: 12) {
            VStack(alignment: .leading, spacing: 4) {
                Text("Manual Adjustments")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)

                Text("Adjust for differences not captured in auto-calculations")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }

            // AI Analyze Button (v6.75.0)
            if comp.photoUrl != nil {
                HStack {
                    Button {
                        Task { await analyzeCondition(for: comp) }
                    } label: {
                        HStack(spacing: 6) {
                            if analyzingIds.contains(comp.id) {
                                ProgressView()
                                    .scaleEffect(0.8)
                                Text("Analyzing...")
                            } else {
                                Image(systemName: "sparkles")
                                Text("Analyze Photos")
                            }
                        }
                        .font(.caption)
                        .foregroundStyle(.blue)
                    }
                    .disabled(analyzingIds.contains(comp.id))

                    Spacer()

                    if let analysis = analysisResults[comp.id], analysis.cached {
                        Text("Cached")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                }
            }

            // AI Result Badge (if available)
            if let analysis = analysisResults[comp.id] {
                AIConditionBadge(analysis: analysis)
            }

            // Condition Picker
            VStack(alignment: .leading, spacing: 4) {
                Text("Condition")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Menu {
                    ForEach(CMACondition.allCases) { condition in
                        Button {
                            binding.wrappedValue.condition = condition
                        } label: {
                            HStack {
                                Text(condition.displayName)
                                if binding.wrappedValue.condition == condition {
                                    Image(systemName: "checkmark")
                                }
                            }
                        }
                    }
                } label: {
                    HStack {
                        Text(binding.wrappedValue.condition.displayName)
                            .font(.caption)
                        Spacer()
                        // Show relative adjustment when comp condition differs from subject condition
                        if binding.wrappedValue.condition != subjectCondition {
                            // Calculate relative adjustment: (subject - comp) × price
                            // Positive = comp worse than subject, Negative = comp better than subject
                            let relativeAdj = subjectCondition.adjustmentPercent - binding.wrappedValue.condition.adjustmentPercent
                            Text(formatConditionAdjustment(binding.wrappedValue.condition, basePrice: comp.soldPrice))
                                .font(.caption)
                                .foregroundStyle(relativeAdj >= 0 ? .green : .red)
                        }
                        Image(systemName: "chevron.up.chevron.down")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                    .background(Color(.tertiarySystemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 6))
                }
            }

            // Feature Toggles
            HStack(spacing: 16) {
                Toggle(isOn: Binding(
                    get: { binding.wrappedValue.hasPool },
                    set: { binding.wrappedValue.hasPool = $0 }
                )) {
                    HStack {
                        Image(systemName: "drop.fill")
                            .font(.caption)
                            .foregroundStyle(.blue)
                        Text("Pool")
                            .font(.caption)
                        if binding.wrappedValue.hasPool {
                            Text("+$50K")
                                .font(.caption2)
                                .foregroundStyle(.green)
                        }
                    }
                }
                .toggleStyle(.button)
                .buttonStyle(.bordered)
                .tint(binding.wrappedValue.hasPool ? .blue : .gray)

                Toggle(isOn: Binding(
                    get: { binding.wrappedValue.hasWaterfront },
                    set: { binding.wrappedValue.hasWaterfront = $0 }
                )) {
                    HStack {
                        Image(systemName: "water.waves")
                            .font(.caption)
                            .foregroundStyle(.cyan)
                        Text("Waterfront")
                            .font(.caption)
                        if binding.wrappedValue.hasWaterfront {
                            Text("+$200K")
                                .font(.caption2)
                                .foregroundStyle(.green)
                        }
                    }
                }
                .toggleStyle(.button)
                .buttonStyle(.bordered)
                .tint(binding.wrappedValue.hasWaterfront ? .cyan : .gray)
            }
        }
    }

    // MARK: - Helper Functions

    /// Calculate the final adjusted price for a comparable using RELATIVE condition adjustments
    /// CMA compares comps to the subject:
    /// - If comp is BETTER condition than subject → subtract (comp worth more than subject)
    /// - If comp is WORSE condition than subject → add (comp worth less than subject)
    private func calculateFinalAdjustedPrice(for comp: CMAComparable) -> Int? {
        let baseAdjusted = comp.adjustedPrice ?? comp.soldPrice

        // Get comp's condition (default to someUpdates if not set)
        let compCondition = manualAdjustments[comp.id]?.condition ?? .someUpdates

        // Calculate RELATIVE condition adjustment: (subject - comp) × price
        // If subject is someUpdates (0%) and comp is fullyRenovated (+12%):
        //   adjustment = (0% - 12%) × price = -12% (comp is better, subtract)
        // If subject is fullyRenovated (+12%) and comp is needsUpdating (-12%):
        //   adjustment = (12% - -12%) × price = +24% (comp is worse, add)
        let relativeConditionAdjustment = Int(
            Double(comp.soldPrice) * (subjectCondition.adjustmentPercent - compCondition.adjustmentPercent)
        )

        // Pool and waterfront remain as absolute adjustments
        var absoluteAdjustments = 0
        if let manual = manualAdjustments[comp.id] {
            if manual.hasPool {
                absoluteAdjustments += CMAManualAdjustment.poolAdjustment
            }
            if manual.hasWaterfront {
                absoluteAdjustments += CMAManualAdjustment.waterfrontAdjustment
            }
        }

        let totalManualAdj = relativeConditionAdjustment + absoluteAdjustments

        // Only show adjusted if there's actually an adjustment
        if comp.adjustedPrice != nil || totalManualAdj != 0 {
            return baseAdjusted + totalManualAdj
        }
        return nil
    }

    private func formatAdjustmentAmount(_ amount: Int) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .currency
        formatter.maximumFractionDigits = 0
        let value = formatter.string(from: NSNumber(value: abs(amount))) ?? "$\(abs(amount))"
        return amount >= 0 ? "+\(value)" : "-\(value)"
    }

    /// Format the RELATIVE condition adjustment comparing comp to subject
    private func formatConditionAdjustment(_ compCondition: CMACondition, basePrice: Int) -> String {
        // Relative adjustment: (subject - comp) × price
        let adjustment = Int(Double(basePrice) * (subjectCondition.adjustmentPercent - compCondition.adjustmentPercent))
        return formatAdjustmentAmount(adjustment)
    }

    /// Calculate total manual adjustments for a comparable (relative condition + absolute adjustments)
    /// Called from ViewBuilder contexts where calculation logic can't live inline
    private func calculateManualAdjustments(for comp: CMAComparable) -> Int {
        let compCondition = manualAdjustments[comp.id]?.condition ?? .someUpdates
        let relativeConditionAdj = Int(
            Double(comp.soldPrice) * (subjectCondition.adjustmentPercent - compCondition.adjustmentPercent)
        )

        var absoluteAdj = 0
        if let manual = manualAdjustments[comp.id] {
            if manual.hasPool {
                absoluteAdj += CMAManualAdjustment.poolAdjustment
            }
            if manual.hasWaterfront {
                absoluteAdj += CMAManualAdjustment.waterfrontAdjustment
            }
        }

        return relativeConditionAdj + absoluteAdj
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

    /// Analyze property condition using AI (v6.75.0)
    private func analyzeCondition(for comp: CMAComparable) async {
        guard let photoUrl = comp.photoUrl else { return }

        analyzingIds.insert(comp.id)
        defer { analyzingIds.remove(comp.id) }

        do {
            let response: ConditionAnalysisResponse = try await APIClient.shared.request(
                .analyzeCondition(
                    listingId: comp.listingId,
                    photoUrls: [photoUrl]  // Could expand to multiple photos in future
                )
            )

            analysisResults[comp.id] = response

            // Auto-apply condition if user hasn't manually set one
            // Only auto-apply if condition is still the default (someUpdates)
            if manualAdjustments[comp.id] == nil || manualAdjustments[comp.id]?.condition == .someUpdates {
                if let condition = response.cmaCondition {
                    var adjustment = manualAdjustments[comp.id] ?? CMAManualAdjustment()
                    adjustment.condition = condition
                    manualAdjustments[comp.id] = adjustment
                }
            }
        } catch {
            analysisError = "Failed to analyze: \(error.localizedDescription)"
        }
    }

    private func generatePDF() async {
        isGeneratingPDF = true

        do {
            let listingId = property.id
            let selectedIds = Array(selectedCompIds)

            // Convert manualAdjustments to the format expected by the API
            var adjustmentsDict: [String: [String: Any]] = [:]
            for (compId, adjustment) in manualAdjustments {
                adjustmentsDict[compId] = [
                    "condition": adjustment.condition.rawValue,
                    "has_pool": adjustment.hasPool,
                    "has_waterfront": adjustment.hasWaterfront
                ]
            }

            let response: CMAPDFResponse = try await APIClient.shared.request(
                .generateCMAPDF(
                    listingId: listingId,
                    preparedFor: preparedFor.isEmpty ? nil : preparedFor,
                    selectedComparables: selectedIds.isEmpty ? nil : selectedIds,
                    subjectCondition: subjectCondition.rawValue,
                    manualAdjustments: adjustmentsDict.isEmpty ? nil : adjustmentsDict
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

// MARK: - AI Condition Badge (v6.75.0)

/// Displays the AI-generated condition analysis result
struct AIConditionBadge: View {
    let analysis: ConditionAnalysisResponse

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            // Header with condition and confidence
            HStack(spacing: 6) {
                Image(systemName: "sparkles")
                    .font(.caption2)
                    .foregroundStyle(.blue)
                Text("AI: \(analysis.conditionLabel)")
                    .font(.caption)
                    .fontWeight(.medium)
                Text("(\(analysis.confidence)%)")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
                Spacer()
            }
            .foregroundStyle(.blue)

            // Reasoning
            Text(analysis.reasoning)
                .font(.caption2)
                .foregroundStyle(.secondary)
                .lineLimit(2)

            // Feature breakdown (if available)
            if let features = analysis.featuresDetected, !features.isEmpty {
                Divider()
                HStack(spacing: 8) {
                    ForEach(features.prefix(4)) { feature in
                        VStack(spacing: 2) {
                            Image(systemName: feature.icon)
                                .font(.caption2)
                            Text(feature.assessment)
                                .font(.system(size: 9))
                                .fontWeight(.medium)
                        }
                        .foregroundStyle(feature.assessmentColor)
                    }
                }
            }
        }
        .padding(10)
        .background(Color.blue.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }
}

#Preview {
    // Note: Preview requires a PropertyDetail instance
    Text("CMASheet Preview")
}
