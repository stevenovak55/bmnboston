<?php
/**
 * Template Name: Schools Methodology Page
 *
 * Explains how school ratings are calculated
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Set up SEO
if (class_exists('BNE_Landing_Page_SEO')) {
    $seo_data = [
        'title' => 'How School Ratings Are Calculated',
        'meta_description' => 'Learn how BMN Boston calculates school grades and rankings using MCAS scores, graduation rates, attendance, and other factors from Massachusetts education data.',
    ];
    BNE_Landing_Page_SEO::set_page_data($seo_data, 'methodology');
}

get_header();
?>

<main id="main" class="bne-landing-page bne-methodology-page" role="main">

    <!-- Hero Section -->
    <section class="bne-methodology-hero">
        <div class="bne-container">
            <nav class="bne-breadcrumbs" aria-label="Breadcrumb">
                <ol class="bne-breadcrumbs__list">
                    <li class="bne-breadcrumbs__item">
                        <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
                    </li>
                    <li class="bne-breadcrumbs__item">
                        <a href="<?php echo esc_url(home_url('/schools/')); ?>">School Districts</a>
                    </li>
                    <li class="bne-breadcrumbs__item bne-breadcrumbs__item--current">
                        <span>Rating Methodology</span>
                    </li>
                </ol>
            </nav>

            <h1 class="bne-methodology-hero__title">How We Rate Schools</h1>
            <p class="bne-methodology-hero__subtitle">
                Our school ratings are based on publicly available data from the Massachusetts Department of Elementary and Secondary Education (DESE) and other official sources.
            </p>
        </div>
    </section>

    <!-- Overview Section -->
    <section class="bne-section bne-methodology-overview">
        <div class="bne-container">
            <h2 class="bne-section-title">Rating Overview</h2>
            <p class="bne-section-intro">
                Each school receives a <strong>composite score</strong> from 0-100 based on multiple factors.
                The score is then converted to a <strong>letter grade</strong> (A+ through F) based on how the school
                compares to other Massachusetts schools at the same level (elementary, middle, or high school).
            </p>

            <div class="bne-methodology-cards">
                <div class="bne-methodology-card">
                    <div class="bne-methodology-card__icon">
                        <span class="bne-grade-badge bne-grade--a">A</span>
                    </div>
                    <h3>Letter Grades</h3>
                    <p>Based on percentile ranking compared to similar schools statewide</p>
                </div>
                <div class="bne-methodology-card">
                    <div class="bne-methodology-card__icon">
                        <span class="bne-methodology-card__number">85.2</span>
                    </div>
                    <h3>Composite Score</h3>
                    <p>Weighted average of multiple academic and operational factors</p>
                </div>
                <div class="bne-methodology-card">
                    <div class="bne-methodology-card__icon">
                        <span class="bne-methodology-card__rank">#42</span>
                    </div>
                    <h3>State Rank</h3>
                    <p>Position among all rated schools of the same level in Massachusetts</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Rating Components Section -->
    <section class="bne-section bne-methodology-components">
        <div class="bne-container">
            <h2 class="bne-section-title">Rating Components</h2>
            <p class="bne-section-intro">
                Different factors are weighted differently for elementary schools vs. middle and high schools,
                reflecting what data is available and most relevant at each level.
            </p>

            <div class="bne-methodology-tables">
                <!-- Middle/High School Weights -->
                <div class="bne-methodology-table-wrapper">
                    <h3>Middle & High Schools</h3>
                    <table class="bne-methodology-table">
                        <thead>
                            <tr>
                                <th>Factor</th>
                                <th>Weight</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>MCAS Proficiency</strong></td>
                                <td>40%</td>
                                <td>Percentage of students scoring proficient or above on state tests</td>
                            </tr>
                            <tr>
                                <td><strong>Graduation Rate</strong></td>
                                <td>12%</td>
                                <td>Four-year graduation rate (high schools only)</td>
                            </tr>
                            <tr>
                                <td><strong>MCAS Growth</strong></td>
                                <td>10%</td>
                                <td>Year-over-year improvement in test scores</td>
                            </tr>
                            <tr>
                                <td><strong>AP Performance</strong></td>
                                <td>9%</td>
                                <td>Advanced Placement participation and pass rates</td>
                            </tr>
                            <tr>
                                <td><strong>MassCore Completion</strong></td>
                                <td>8%</td>
                                <td>Students completing the recommended college-prep curriculum</td>
                            </tr>
                            <tr>
                                <td><strong>Attendance</strong></td>
                                <td>8%</td>
                                <td>Inverse of chronic absenteeism rate</td>
                            </tr>
                            <tr>
                                <td><strong>Student-Teacher Ratio</strong></td>
                                <td>5%</td>
                                <td>Average class size (lower is better)</td>
                            </tr>
                            <tr>
                                <td><strong>Per-Pupil Spending</strong></td>
                                <td>4%</td>
                                <td>District spending per student</td>
                            </tr>
                            <tr>
                                <td><strong>College Outcomes</strong></td>
                                <td>4%</td>
                                <td>Percentage of graduates attending college</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Elementary School Weights -->
                <div class="bne-methodology-table-wrapper">
                    <h3>Elementary Schools</h3>
                    <table class="bne-methodology-table">
                        <thead>
                            <tr>
                                <th>Factor</th>
                                <th>Weight</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>MCAS Proficiency</strong></td>
                                <td>45%</td>
                                <td>Percentage of students scoring proficient or above</td>
                            </tr>
                            <tr>
                                <td><strong>Attendance</strong></td>
                                <td>20%</td>
                                <td>Inverse of chronic absenteeism rate</td>
                            </tr>
                            <tr>
                                <td><strong>MCAS Growth</strong></td>
                                <td>15%</td>
                                <td>Year-over-year improvement in test scores</td>
                            </tr>
                            <tr>
                                <td><strong>Per-Pupil Spending</strong></td>
                                <td>12%</td>
                                <td>District spending per student</td>
                            </tr>
                            <tr>
                                <td><strong>Student-Teacher Ratio</strong></td>
                                <td>8%</td>
                                <td>Average class size (lower is better)</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="bne-methodology-note">
                        <em>Note: Elementary schools don't have graduation rates, AP courses, or MassCore requirements,
                        so these factors are excluded from their ratings.</em>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Grade Scale Section -->
    <section class="bne-section bne-methodology-grades">
        <div class="bne-container">
            <h2 class="bne-section-title">Letter Grade Scale</h2>
            <p class="bne-section-intro">
                Letter grades are assigned based on <strong>percentile ranking</strong>, not absolute scores.
                This means grades reflect how a school compares to other Massachusetts schools at the same level.
            </p>

            <div class="bne-grade-scale">
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--a">A+</span>
                    <span class="bne-grade-scale__range">Top 10%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--a">A</span>
                    <span class="bne-grade-scale__range">Top 20%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--a">A-</span>
                    <span class="bne-grade-scale__range">Top 30%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--b">B+</span>
                    <span class="bne-grade-scale__range">Top 40%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--b">B</span>
                    <span class="bne-grade-scale__range">Top 50%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--b">B-</span>
                    <span class="bne-grade-scale__range">Top 60%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--c">C+</span>
                    <span class="bne-grade-scale__range">Top 70%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--c">C</span>
                    <span class="bne-grade-scale__range">Top 80%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--c">C-</span>
                    <span class="bne-grade-scale__range">Top 90%</span>
                </div>
                <div class="bne-grade-scale__item">
                    <span class="bne-grade-badge bne-grade--d">D</span>
                    <span class="bne-grade-scale__range">Bottom 10%</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Data Sources Section -->
    <section class="bne-section bne-methodology-sources">
        <div class="bne-container">
            <h2 class="bne-section-title">Data Sources</h2>
            <p class="bne-section-intro">
                All data comes from official Massachusetts government sources:
            </p>

            <div class="bne-sources-grid">
                <div class="bne-source-card">
                    <h3>MA DESE</h3>
                    <p>Massachusetts Department of Elementary and Secondary Education</p>
                    <ul>
                        <li>MCAS test scores</li>
                        <li>Graduation rates</li>
                        <li>Attendance data</li>
                        <li>AP course offerings</li>
                        <li>Staff-to-student ratios</li>
                    </ul>
                </div>
                <div class="bne-source-card">
                    <h3>Education to Career Hub</h3>
                    <p>Massachusetts Executive Office of Education</p>
                    <ul>
                        <li>Per-pupil spending</li>
                        <li>Demographics</li>
                        <li>College outcomes</li>
                        <li>MassCore completion</li>
                    </ul>
                </div>
                <div class="bne-source-card">
                    <h3>MIAA</h3>
                    <p>Massachusetts Interscholastic Athletic Association</p>
                    <ul>
                        <li>Sports programs</li>
                        <li>Athletic participation</li>
                    </ul>
                </div>
                <div class="bne-source-card">
                    <h3>NCES EDGE</h3>
                    <p>National Center for Education Statistics</p>
                    <ul>
                        <li>School district boundaries</li>
                        <li>Geographic data</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Update Frequency Section -->
    <section class="bne-section bne-methodology-updates">
        <div class="bne-container">
            <h2 class="bne-section-title">Data Updates</h2>
            <div class="bne-update-info">
                <div class="bne-update-item">
                    <h3>Annual Data Refresh</h3>
                    <p>
                        School ratings are recalculated each fall when new MCAS scores and other metrics
                        are released by DESE (typically September-October).
                    </p>
                </div>
                <div class="bne-update-item">
                    <h3>Current Data Year</h3>
                    <p>
                        Our current ratings use <strong>2024-2025</strong> school year data where available,
                        with some metrics from 2023-2024 for data that releases on a delay.
                    </p>
                </div>
                <div class="bne-update-item">
                    <h3>Year-Over-Year Trends</h3>
                    <p>
                        We track how each school's ranking changes from year to year, showing whether
                        they've improved, declined, or held steady in the state rankings.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Limitations Section -->
    <section class="bne-section bne-methodology-limitations">
        <div class="bne-container">
            <h2 class="bne-section-title">Limitations</h2>
            <div class="bne-limitations-list">
                <div class="bne-limitation-item">
                    <h3>Private Schools</h3>
                    <p>
                        Private and parochial schools are not included in our ratings because they don't
                        participate in MCAS testing or report data to DESE.
                    </p>
                </div>
                <div class="bne-limitation-item">
                    <h3>Early Elementary</h3>
                    <p>
                        Schools serving only pre-K through grade 2 may have limited data because MCAS
                        testing begins in grade 3.
                    </p>
                </div>
                <div class="bne-limitation-item">
                    <h3>Small Schools</h3>
                    <p>
                        Very small schools may have ratings based on limited data points, which can lead
                        to more year-to-year variability.
                    </p>
                </div>
                <div class="bne-limitation-item">
                    <h3>Special Programs</h3>
                    <p>
                        Alternative education programs, special education schools, and vocational schools
                        may not be directly comparable to traditional schools.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="bne-section bne-methodology-cta">
        <div class="bne-container">
            <h2>Explore Massachusetts School Districts</h2>
            <p>Use our school ratings to help find the right neighborhood for your family.</p>
            <a href="<?php echo esc_url(home_url('/schools/')); ?>" class="bne-btn bne-btn--primary bne-btn--large">
                Browse All Districts
            </a>
        </div>
    </section>

</main>

<?php
get_footer();
