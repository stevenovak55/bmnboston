//
//  NearbySchoolsSection.swift
//  BMNBoston
//
//  Created for BMN Boston Real Estate
//  Displays nearby schools for a property
//

import SwiftUI

/// Section displaying nearby schools for a property
struct NearbySchoolsSection: View {
    let latitude: Double
    let longitude: Double
    let city: String?

    @State private var schoolsData: PropertySchoolsData?
    @State private var isLoading = true
    @State private var error: String?
    @State private var isExpanded = false

    // Glossary sheet state
    @State private var showGlossarySheet = false
    @State private var selectedGlossaryTerm: String?
    @State private var glossaryTerm: GlossaryTerm?
    @State private var glossaryLoading = false

    // Full glossary state
    @State private var showFullGlossary = false
    @State private var fullGlossary: GlossaryResponse?
    @State private var fullGlossaryLoading = false

    var body: some View {
        CollapsibleSection(
            title: "Nearby Schools",
            icon: "graduationcap.fill",
            isExpanded: $isExpanded
        ) {
            if isLoading {
                loadingView
            } else if let error = error {
                errorView(error)
            } else if let data = schoolsData {
                schoolsContent(data)
            }
        }
        .task {
            await loadSchools()
        }
        .sheet(isPresented: $showGlossarySheet) {
            glossarySheet
        }
        .sheet(isPresented: $showFullGlossary) {
            fullGlossarySheet
        }
    }

    // MARK: - Glossary Sheet

    private var glossarySheet: some View {
        NavigationView {
            VStack(spacing: 16) {
                if glossaryLoading {
                    ProgressView()
                        .padding(.top, 40)
                } else if let term = glossaryTerm {
                    ScrollView {
                        VStack(alignment: .leading, spacing: 16) {
                            // Term header
                            VStack(alignment: .leading, spacing: 4) {
                                Text(term.term)
                                    .font(.title2)
                                    .fontWeight(.bold)
                                Text(term.fullName)
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                            }

                            Divider()

                            // Description
                            VStack(alignment: .leading, spacing: 8) {
                                Label("What is it?", systemImage: "info.circle.fill")
                                    .font(.headline)
                                    .foregroundStyle(AppColors.brandTeal)
                                Text(term.description)
                                    .font(.body)
                            }

                            // Parent tip
                            VStack(alignment: .leading, spacing: 8) {
                                Label("Parent Tip", systemImage: "lightbulb.fill")
                                    .font(.headline)
                                    .foregroundStyle(.orange)
                                Text(term.parentTip)
                                    .font(.body)
                                    .padding()
                                    .background(Color.orange.opacity(0.1))
                                    .clipShape(RoundedRectangle(cornerRadius: 8))
                            }

                            Spacer()
                        }
                        .padding()
                    }
                } else {
                    Text("Unable to load term information")
                        .foregroundStyle(.secondary)
                        .padding(.top, 40)
                }
                Spacer()
            }
            .navigationTitle("Glossary")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Done") {
                        showGlossarySheet = false
                    }
                }
            }
        }
    }

    // MARK: - Info Button

    private func infoButton(for term: String) -> some View {
        Button {
            selectedGlossaryTerm = term
            loadGlossaryTerm(term)
        } label: {
            Image(systemName: "info.circle")
                .font(.system(size: 12))
                .foregroundStyle(AppColors.brandTeal)
        }
        .buttonStyle(.plain)
    }

    private func loadGlossaryTerm(_ term: String) {
        glossaryLoading = true
        glossaryTerm = nil
        showGlossarySheet = true

        Task {
            do {
                let data = try await SchoolService.shared.fetchGlossaryTerm(term)
                await MainActor.run {
                    glossaryTerm = data
                    glossaryLoading = false
                }
            } catch {
                await MainActor.run {
                    glossaryLoading = false
                }
            }
        }
    }

    // MARK: - Loading View

    private var loadingView: some View {
        HStack(spacing: 8) {
            ProgressView()
                .scaleEffect(0.8)
            Text("Loading schools...")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .padding(.vertical, 8)
    }

    // MARK: - Error View

    private func errorView(_ message: String) -> some View {
        HStack(spacing: 8) {
            Image(systemName: "exclamationmark.triangle")
                .foregroundStyle(.orange)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
    }

    // MARK: - Schools Content

    private func schoolsContent(_ data: PropertySchoolsData) -> some View {
        VStack(alignment: .leading, spacing: 16) {
            // District info
            if let district = data.district {
                districtRow(district)
            }

            // School levels
            if !data.schools.elementary.isEmpty {
                schoolLevelSection(title: "Elementary Schools", schools: data.schools.elementary, color: .green)
            }

            if !data.schools.middle.isEmpty {
                schoolLevelSection(title: "Middle Schools", schools: data.schools.middle, color: .blue)
            }

            if !data.schools.high.isEmpty {
                schoolLevelSection(title: "High Schools", schools: data.schools.high, color: .purple)
            }

            // No schools message
            if data.schools.totalCount == 0 {
                Text("No schools found within 5 miles")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            // Info links section
            if data.schools.totalCount > 0 {
                glossaryLinksSection
            }
        }
    }

    // MARK: - Glossary Links Section

    private var glossaryLinksSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Text("Learn about these ratings")
                    .font(.caption)
                    .fontWeight(.medium)
                    .foregroundStyle(.secondary)

                Spacer()

                Button {
                    loadFullGlossary()
                } label: {
                    HStack(spacing: 4) {
                        Text("See All 20 Terms")
                            .font(.caption2)
                            .fontWeight(.medium)
                        Image(systemName: "chevron.right")
                            .font(.system(size: 8, weight: .semibold))
                    }
                    .foregroundStyle(AppColors.brandTeal)
                }
                .buttonStyle(.plain)
            }

            // First row: Rating terms
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    glossaryLinkChip("composite_score", label: "Composite Score")
                    glossaryLinkChip("letter_grade", label: "Letter Grades")
                    glossaryLinkChip("percentile_rank", label: "Percentile")
                    glossaryLinkChip("mcas", label: "MCAS")
                    glossaryLinkChip("student_teacher_ratio", label: "Class Size")
                }
            }

            // Second row: Programs & metrics
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    glossaryLinkChip("ap", label: "AP Courses")
                    glossaryLinkChip("masscore", label: "MassCore")
                    glossaryLinkChip("chronic_absence", label: "Attendance")
                    glossaryLinkChip("per_pupil_spending", label: "Spending")
                    glossaryLinkChip("sped", label: "Special Ed")
                }
            }
        }
        .padding(.top, 8)
    }

    private func glossaryLinkChip(_ term: String, label: String) -> some View {
        Button {
            loadGlossaryTerm(term)
        } label: {
            HStack(spacing: 4) {
                Image(systemName: "info.circle")
                    .font(.system(size: 10))
                Text(label)
                    .font(.system(size: 11, weight: .medium))
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 6)
            .background(AppColors.brandTeal.opacity(0.1))
            .foregroundStyle(AppColors.brandTeal)
            .clipShape(Capsule())
        }
        .buttonStyle(.plain)
    }

    // MARK: - Full Glossary Sheet

    private var fullGlossarySheet: some View {
        NavigationView {
            Group {
                if fullGlossaryLoading {
                    ProgressView()
                        .padding(.top, 40)
                } else if let glossary = fullGlossary {
                    ScrollView {
                        LazyVStack(alignment: .leading, spacing: 16) {
                            // Group by category
                            ForEach(orderedCategories, id: \.key) { category in
                                glossaryCategorySection(category: category, glossary: glossary)
                            }
                        }
                        .padding()
                    }
                } else {
                    Text("Unable to load glossary")
                        .foregroundStyle(.secondary)
                        .padding(.top, 40)
                }
            }
            .navigationTitle("Education Glossary")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") {
                        showFullGlossary = false
                    }
                }
            }
        }
    }

    /// Categories in display order
    private var orderedCategories: [(key: String, label: String)] {
        [
            ("rating", "Our Ratings"),
            ("testing", "Testing & Assessment"),
            ("metric", "School Metrics"),
            ("curriculum", "Curriculum & Courses"),
            ("program", "Programs & Services"),
            ("school_type", "Types of Schools"),
            ("funding", "Funding & Resources"),
            ("oversight", "Oversight & Accountability"),
            ("governance", "School Governance")
        ]
    }

    private func glossaryCategorySection(category: (key: String, label: String), glossary: GlossaryResponse) -> some View {
        let termsInCategory = glossary.terms.values.filter { $0.category == category.key }
            .sorted { $0.term < $1.term }

        return Group {
            if !termsInCategory.isEmpty {
                VStack(alignment: .leading, spacing: 12) {
                    // Category header
                    HStack(spacing: 8) {
                        Image(systemName: categoryIcon(for: category.key))
                            .font(.system(size: 14))
                            .foregroundStyle(AppColors.brandTeal)
                        Text(category.label)
                            .font(.headline)
                    }
                    .padding(.top, 8)

                    // Terms in category
                    ForEach(termsInCategory) { term in
                        glossaryTermRow(term)
                    }
                }
            }
        }
    }

    private func glossaryTermRow(_ term: GlossaryTerm) -> some View {
        Button {
            glossaryTerm = term
            showFullGlossary = false
            showGlossarySheet = true
        } label: {
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(term.term)
                        .font(.subheadline)
                        .fontWeight(.medium)
                    Text(term.fullName)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Spacer()
                Image(systemName: "chevron.right")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
            .padding(.vertical, 8)
            .padding(.horizontal, 12)
            .background(Color(.systemGray6))
            .clipShape(RoundedRectangle(cornerRadius: 8))
        }
        .buttonStyle(.plain)
    }

    private func categoryIcon(for category: String) -> String {
        switch category {
        case "testing": return "doc.text.magnifyingglass"
        case "curriculum": return "book.fill"
        case "funding": return "dollarsign.circle.fill"
        case "school_type": return "building.2.fill"
        case "program": return "star.fill"
        case "rating": return "chart.bar.fill"
        case "metric": return "number.circle.fill"
        case "oversight": return "eye.fill"
        case "governance": return "person.3.fill"
        default: return "info.circle.fill"
        }
    }

    private func loadFullGlossary() {
        fullGlossaryLoading = true
        fullGlossary = nil
        showFullGlossary = true

        Task {
            do {
                let data = try await SchoolService.shared.fetchGlossary()
                await MainActor.run {
                    fullGlossary = data
                    fullGlossaryLoading = false
                }
            } catch {
                await MainActor.run {
                    fullGlossaryLoading = false
                }
            }
        }
    }

    // MARK: - District Row

    private func districtRow(_ district: SchoolDistrict) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            // District name
            HStack(spacing: 12) {
                Image(systemName: "map.fill")
                    .font(.title3)
                    .foregroundStyle(AppColors.brandTeal)
                    .frame(width: 32)

                VStack(alignment: .leading, spacing: 2) {
                    Text("School District")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Text(district.name)
                        .font(.subheadline)
                        .fontWeight(.medium)
                }

                Spacer()

                // District letter grade if available
                if let ranking = district.ranking, let grade = ranking.letterGrade {
                    gradeBadge(grade: grade, color: gradeColor(for: grade))
                }
            }

            // College Outcomes section
            if let outcomes = district.collegeOutcomes {
                collegeOutcomesRow(outcomes)
            }

            // Discipline Data section
            if let discipline = district.discipline {
                disciplineRow(discipline)
            }
        }
        .padding(.bottom, 8)
    }

    // MARK: - Discipline Row

    private func disciplineRow(_ discipline: DistrictDiscipline) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            // Header
            HStack(spacing: 6) {
                Image(systemName: discipline.isLowDiscipline ? "checkmark.shield.fill" : "shield.fill")
                    .font(.system(size: 12))
                    .foregroundStyle(discipline.isLowDiscipline ? .green : .orange)
                Text("School Safety")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)
                Spacer()
                Text("\(discipline.year - 1)-\(discipline.year % 100)")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }

            // Main stat with percentile
            HStack(spacing: 4) {
                if let rate = discipline.rateFormatted {
                    Text(rate)
                        .font(.title3)
                        .fontWeight(.bold)
                        .foregroundStyle(discipline.isLowDiscipline ? .green : .orange)
                }
                if let percentileLabel = discipline.percentileLabel {
                    Text("•")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    Text(percentileLabel)
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .foregroundStyle(discipline.isLowDiscipline ? .green : .orange)
                } else {
                    Text(discipline.summary)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            }

            // Breakdown pills
            HStack(spacing: 8) {
                if let oss = discipline.outOfSchoolSuspensionPct, oss > 0 {
                    disciplinePill(label: "Suspensions", value: oss, color: .orange)
                }
                if let iss = discipline.inSchoolSuspensionPct, iss > 0 {
                    disciplinePill(label: "In-School", value: iss, color: .yellow)
                }
                if let exp = discipline.expulsionPct, exp > 0 {
                    disciplinePill(label: "Expulsions", value: exp, color: .red)
                }
                if let emr = discipline.emergencyRemovalPct, emr > 0 {
                    disciplinePill(label: "Emergency", value: emr, color: .purple)
                }
            }

            // Comparison to state average
            if let rate = discipline.disciplineRate {
                let diff = rate - DistrictDiscipline.stateAverageRate
                HStack(spacing: 4) {
                    Image(systemName: diff < 0 ? "arrow.down.circle.fill" : "arrow.up.circle.fill")
                        .font(.system(size: 10))
                        .foregroundStyle(diff < 0 ? .green : .orange)
                    Text(diff < 0 ? "\(String(format: "%.1f", abs(diff)))% below state avg" : "\(String(format: "%.1f", diff))% above state avg")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding(12)
        .background(discipline.isLowDiscipline ? Color.green.opacity(0.05) : Color.orange.opacity(0.05))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    private func disciplinePill(label: String, value: Double, color: Color) -> some View {
        VStack(spacing: 2) {
            Text(String(format: "%.1f%%", value))
                .font(.system(size: 12, weight: .semibold))
                .foregroundStyle(color)
            Text(label)
                .font(.system(size: 9))
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - College Outcomes Row

    private func collegeOutcomesRow(_ outcomes: CollegeOutcomes) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            // Header
            HStack(spacing: 6) {
                Image(systemName: "graduationcap.fill")
                    .font(.system(size: 12))
                    .foregroundStyle(.purple)
                Text("Where Graduates Go")
                    .font(.caption)
                    .fontWeight(.semibold)
                    .foregroundStyle(.secondary)
                Spacer()
                Text("Class of \(outcomes.year)")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }

            // Main stat
            HStack(spacing: 4) {
                Text("\(Int(outcomes.totalPostsecondaryPct))%")
                    .font(.title3)
                    .fontWeight(.bold)
                    .foregroundStyle(.purple)
                Text("attend college")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }

            // Breakdown pills
            HStack(spacing: 8) {
                outcomePill(label: "4-Year", value: outcomes.fourYearPct, color: .purple)
                outcomePill(label: "2-Year", value: outcomes.twoYearPct, color: .indigo)
                outcomePill(label: "Out of State", value: outcomes.outOfStatePct, color: .blue)
                outcomePill(label: "Working", value: outcomes.employedPct, color: .teal)
            }
        }
        .padding(12)
        .background(Color.purple.opacity(0.05))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    private func outcomePill(label: String, value: Double, color: Color) -> some View {
        VStack(spacing: 2) {
            Text("\(Int(value))%")
                .font(.system(size: 12, weight: .semibold))
                .foregroundStyle(color)
            Text(label)
                .font(.system(size: 9))
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - School Level Section

    private func schoolLevelSection(title: String, schools: [NearbySchool], color: Color) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.subheadline)
                .fontWeight(.semibold)
                .foregroundStyle(color)

            ForEach(schools) { school in
                schoolRow(school, color: color)
            }
        }
    }

    // MARK: - School Row

    private func schoolRow(_ school: NearbySchool, color: Color) -> some View {
        HStack(alignment: .top, spacing: 12) {
            // Grade badge or level icon
            if let grade = school.letterGrade {
                gradeBadge(grade: grade, color: gradeColor(for: grade))
            } else {
                Circle()
                    .fill(color.opacity(0.2))
                    .frame(width: 32, height: 32)
                    .overlay(
                        Image(systemName: "building.2.fill")
                            .font(.caption)
                            .foregroundStyle(color)
                    )
            }

            VStack(alignment: .leading, spacing: 4) {
                // School name with percentile context
                HStack(spacing: 6) {
                    Text(school.name)
                        .font(.subheadline)
                        .fontWeight(.medium)
                        .lineLimit(2)

                    // Percentile context badge (e.g., "Top 25%")
                    if let context = school.percentileContext {
                        Text(context)
                            .font(.system(size: 10, weight: .medium))
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(gradeColor(for: school.letterGrade ?? "").opacity(0.15))
                            .foregroundStyle(gradeColor(for: school.letterGrade ?? ""))
                            .clipShape(Capsule())
                    }
                }

                // Regional school indicator (e.g., "Students from Nahant attend this school")
                if let regionalNote = school.regionalNote {
                    HStack(spacing: 4) {
                        Image(systemName: "arrow.triangle.branch")
                            .font(.system(size: 10))
                        Text(regionalNote)
                            .font(.caption2)
                            .italic()
                    }
                    .foregroundStyle(.blue)
                }

                HStack(spacing: 12) {
                    // Distance
                    Label(school.distanceFormatted, systemImage: "location.fill")
                        .font(.caption)
                        .foregroundStyle(.secondary)

                    // State Rank if available (e.g., "#1 of 843")
                    if let rankFormatted = school.stateRankFormatted {
                        Text(rankFormatted)
                            .font(.caption)
                            .fontWeight(.semibold)
                            .foregroundStyle(gradeColor(for: school.letterGrade ?? ""))
                    }

                    // Composite Score if available
                    if let score = school.compositeScore {
                        Text("\(Int(score))/100")
                            .font(.caption)
                            .fontWeight(.medium)
                            .foregroundStyle(.secondary)
                    }
                    // Fallback to MCAS Score if no ranking
                    else if let mcas = school.mcasFormatted {
                        Label(mcas, systemImage: "chart.bar.fill")
                            .font(.caption)
                            .foregroundStyle(.green)
                    }
                }

                // Trend text (e.g., "Improved 5 spots from last year")
                if let trend = school.trend, trend.rankChange != 0 {
                    HStack(spacing: 4) {
                        Image(systemName: trend.trendIcon)
                            .font(.system(size: 10))
                        Text(trend.rankChangeText)
                            .font(.caption2)
                    }
                    .foregroundStyle(trendColor(for: trend.direction))
                }

                // Benchmark comparison (e.g., "+12.2 above state avg")
                if let benchmarks = school.benchmarks, let vsState = benchmarks.vsState {
                    HStack(spacing: 4) {
                        Image(systemName: vsState.contains("+") ? "arrow.up.right" : "arrow.down.right")
                            .font(.system(size: 10))
                        Text(vsState)
                            .font(.caption2)
                    }
                    .foregroundStyle(vsState.contains("+") ? .green : .orange)
                }

                // Data completeness and grades row
                HStack(spacing: 8) {
                    // Data completeness indicator
                    if let completeness = school.dataCompleteness {
                        HStack(spacing: 3) {
                            Image(systemName: completenessIcon(for: completeness.confidenceLevel))
                                .font(.system(size: 9))
                            Text(completeness.shortLabel)
                                .font(.caption2)
                        }
                        .foregroundStyle(completenessColor(for: completeness.confidenceLevel))
                    }

                    // Grades if available
                    if let grades = school.grades {
                        Text("Grades \(grades)")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                }

                // Limited data warning for elementary schools (Phase 5)
                if let completeness = school.dataCompleteness,
                   let note = completeness.limitedDataNote {
                    HStack(spacing: 4) {
                        Image(systemName: "info.circle")
                            .font(.system(size: 10))
                        Text(note)
                            .font(.caption2)
                    }
                    .foregroundStyle(.orange)
                    .padding(.vertical, 2)
                }

                // Demographics row
                if let demographics = school.demographics {
                    HStack(spacing: 8) {
                        if let students = demographics.studentsFormatted {
                            Label(students, systemImage: "person.2.fill")
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                        if let diversity = demographics.diversity {
                            Text(diversity)
                                .font(.caption2)
                                .foregroundStyle(.purple)
                        }
                        if let freeLunch = demographics.freeLunchFormatted {
                            Text(freeLunch)
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                // Discipline row (if available)
                if let discipline = school.discipline {
                    HStack(spacing: 6) {
                        Image(systemName: discipline.isLowDiscipline ? "checkmark.shield.fill" : "exclamationmark.shield.fill")
                            .font(.system(size: 10))
                            .foregroundStyle(discipline.isLowDiscipline ? .green : .orange)
                        Text(discipline.summary)
                            .font(.caption2)
                            .foregroundStyle(discipline.isLowDiscipline ? .green : .secondary)
                        if let rate = discipline.rateFormatted {
                            Text("•")
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                            Text(rate)
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                    }
                }

                // Sports row (for high schools with MIAA data)
                if let sports = school.sports {
                    HStack(spacing: 6) {
                        Image(systemName: "sportscourt.fill")
                            .font(.system(size: 10))
                            .foregroundStyle(sports.isStrongAthletics ? .teal : .secondary)
                        Text(sports.summary)
                            .font(.caption2)
                            .foregroundStyle(sports.isStrongAthletics ? .teal : .secondary)
                    }
                }

                // Highlights chips
                if let highlights = school.highlights, !highlights.isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 6) {
                            ForEach(highlights) { highlight in
                                highlightChip(highlight)
                            }
                        }
                    }
                    .padding(.top, 4)
                }

            }
        }
        .padding(.vertical, 4)
    }

    // MARK: - Highlight Chip

    private func highlightChip(_ highlight: SchoolHighlight) -> some View {
        HStack(spacing: 4) {
            Image(systemName: highlight.icon)
                .font(.system(size: 10))
            Text(highlight.shortText)
                .font(.system(size: 10, weight: .medium))
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 4)
        .background(highlightColor(for: highlight.type).opacity(0.15))
        .foregroundStyle(highlightColor(for: highlight.type))
        .clipShape(Capsule())
    }

    // MARK: - Highlight Color

    private func highlightColor(for type: String) -> Color {
        switch type {
        case "ap", "masscore":
            return .purple
        case "ratio", "resources":
            return .green
        case "diversity":
            return .orange
        case "cte", "innovation":
            return .blue
        case "early_college":
            return .indigo
        case "graduation", "attendance":
            return .teal
        case "improving":
            return .green
        case "discipline":
            return .mint  // Light green for low discipline rate
        default:
            return .gray
        }
    }

    // MARK: - Grade Badge

    private func gradeBadge(grade: String, color: Color) -> some View {
        ZStack {
            Circle()
                .fill(color)
                .frame(width: 32, height: 32)

            Text(grade)
                .font(.system(size: 12, weight: .bold))
                .foregroundStyle(.white)
        }
    }

    // MARK: - Grade Color

    private func gradeColor(for grade: String) -> Color {
        switch grade.prefix(1) {
        case "A": return .green
        case "B": return .blue
        case "C": return .yellow
        case "D": return .orange
        default: return .red
        }
    }

    // MARK: - Trend Color

    private func trendColor(for direction: String) -> Color {
        switch direction {
        case "up": return .green
        case "down": return .red
        default: return .gray
        }
    }

    // MARK: - Data Completeness Helpers

    private func completenessIcon(for level: String) -> String {
        switch level {
        case "comprehensive": return "checkmark.seal.fill"
        case "good": return "checkmark.circle.fill"
        default: return "exclamationmark.circle"
        }
    }

    private func completenessColor(for level: String) -> Color {
        switch level {
        case "comprehensive": return .green
        case "good": return .blue
        default: return .orange
        }
    }

    // MARK: - Load Schools

    private func loadSchools() async {
        isLoading = true
        error = nil

        do {
            schoolsData = try await SchoolService.shared.fetchPropertySchools(
                latitude: latitude,
                longitude: longitude,
                radius: 5.0,
                city: city
            )
        } catch let apiError as APIError {
            // Show more specific error for API issues
            switch apiError {
            case .decodingError(let decodingError):
                self.error = "Data format error"
                debugLog("[Schools] Decoding error: \(decodingError)")
            case .serverError(_, let message):
                self.error = message
            case .notFound:
                self.error = "School data not found"
            case .networkError(_):
                self.error = "Network connection issue"
            default:
                self.error = "Unable to load school data"
            }
        } catch {
            self.error = "Unable to load school data"
        }

        isLoading = false
    }
}

// MARK: - Preview

#Preview {
    ScrollView {
        NearbySchoolsSection(
            latitude: 42.3601,
            longitude: -71.0589,
            city: "Boston"
        )
        .padding()
    }
}
