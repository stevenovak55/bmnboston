<?php
/**
 * Template Name: MA School Districts Guide
 *
 * Custom landing page for the Massachusetts School Districts Home Buying Guide
 * SEO-optimized content page with lead capture integration
 *
 * @package flavor_flavor_flavor
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// DATA QUERIES - Must run BEFORE get_header() so SEO data is available for wp_head
// ============================================================================
global $wpdb;

// Get top 40 districts
$top_districts = $wpdb->get_results("
    SELECT d.name as district_name, dr.letter_grade, dr.composite_score, dr.percentile_rank, dr.schools_count
    FROM {$wpdb->prefix}bmn_school_districts d
    JOIN {$wpdb->prefix}bmn_district_rankings dr ON d.id = dr.district_id
    WHERE dr.year = (SELECT MAX(year) FROM {$wpdb->prefix}bmn_district_rankings)
    AND dr.letter_grade IS NOT NULL
    ORDER BY dr.composite_score DESC
    LIMIT 40
");

// Get total stats
$total_schools = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_schools");
$total_districts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bmn_school_districts");
$total_active_listings = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bme_listing_summary WHERE standard_status = 'Active' AND property_type = 'Residential'");

// App Store URLs for iOS promo
$app_store_url = 'https://apps.apple.com/us/app/bmn-boston/id6745724401';
$qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=' . urlencode($app_store_url);

// Get download count for social proof
$download_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bne_guide_leads");
$display_count = max(150, floor($download_count / 10) * 10); // Start at 150, round to nearest 10

// ============================================================================
// SEO SETUP - Output schemas directly via wp_head hook with closure
// ============================================================================
$schools_guide_seo_data = array(
    'name' => 'Massachusetts School Districts Home Buying Guide',
    'state' => 'MA',
    'total_districts' => intval($total_districts),
    'total_schools' => intval($total_schools),
    'url' => get_permalink(), // Use actual page URL
    'data_freshness' => current_time('Y-m-d'),
    'top_districts' => $top_districts,
);

// Add meta tags for SEO and social sharing (priority 1 = early in head)
add_action('wp_head', function() use ($schools_guide_seo_data) {
    $title = 'Best School Districts to Buy a Home in MA (2026) | BMN Boston';
    $description = 'Compare ' . number_format($schools_guide_seo_data['total_districts']) . ' Massachusetts school districts ranked by MCAS scores. Find family homes in A-rated zones from $500K. Unique school grade filter for buyers.';
    $image = home_url('/wp-content/uploads/2026/01/BMN-Boston-Schools-Web.jpeg');
    $url = $schools_guide_seo_data['url'];
    ?>
    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo esc_attr($description); ?>">

    <!-- Open Graph Tags (Facebook, LinkedIn) -->
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?php echo esc_attr($title); ?>">
    <meta property="og:description" content="<?php echo esc_attr($description); ?>">
    <meta property="og:image" content="<?php echo esc_url($image); ?>">
    <meta property="og:image:width" content="1424">
    <meta property="og:image:height" content="752">
    <meta property="og:image:alt" content="Top 10 Massachusetts School Districts for Home Buyers - BMN Boston">
    <meta property="og:url" content="<?php echo esc_url($url); ?>">
    <meta property="og:site_name" content="BMN Boston Real Estate">
    <meta property="og:locale" content="en_US">
    <meta property="article:published_time" content="2025-01-01T00:00:00-05:00">
    <meta property="article:modified_time" content="<?php echo esc_attr(wp_date('c')); ?>">

    <!-- Twitter Card Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo esc_attr($title); ?>">
    <meta name="twitter:description" content="<?php echo esc_attr($description); ?>">
    <meta name="twitter:image" content="<?php echo esc_url($image); ?>">
    <meta name="twitter:image:alt" content="Top 10 Massachusetts School Districts for Home Buyers - BMN Boston">
    <?php
}, 1);

// Add schemas via closure to ensure data is available (priority 6 = after meta tags)
add_action('wp_head', function() use ($schools_guide_seo_data) {
    // Article Schema
    $article_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => 'The Ultimate Guide to Buying a Home in Massachusetts\' Top School Districts',
        'description' => 'Compare ' . number_format($schools_guide_seo_data['total_districts']) . ' Massachusetts school districts ranked by MCAS scores. Find family homes in A-rated zones.',
        'image' => array(
            '@type' => 'ImageObject',
            'url' => home_url('/wp-content/uploads/2026/01/BMN-Boston-Schools-Web.jpeg'),
            'width' => 1424,
            'height' => 752,
        ),
        'author' => array(
            '@type' => 'Organization',
            'name' => 'BMN Boston Real Estate',
            'url' => home_url('/'),
        ),
        'publisher' => array(
            '@type' => 'Organization',
            'name' => 'BMN Boston Real Estate',
            'url' => home_url('/'),
            'logo' => array(
                '@type' => 'ImageObject',
                'url' => home_url('/wp-content/uploads/2025/12/BMN-Logo-Croped.png'),
            ),
        ),
        'datePublished' => '2025-01-01T00:00:00-05:00',
        'dateModified' => wp_date('c'), // ISO 8601 format with timezone
        'mainEntityOfPage' => array(
            '@type' => 'WebPage',
            '@id' => $schools_guide_seo_data['url'],
        ),
    );
    echo '<script type="application/ld+json">' . "\n" . wp_json_encode($article_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

    // ItemList Schema for Top Districts
    if (!empty($schools_guide_seo_data['top_districts'])) {
        $items = array();
        foreach ($schools_guide_seo_data['top_districts'] as $index => $district) {
            $items[] = array(
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $district->district_name,
                'description' => 'Grade: ' . $district->letter_grade . ' | Score: ' . number_format($district->composite_score, 1),
            );
        }
        $itemlist_schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Top 40 Massachusetts School Districts for Home Buyers',
            'description' => 'Ranked by MCAS composite scores for ' . date('Y'),
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        );
        echo '<script type="application/ld+json">' . "\n" . wp_json_encode($itemlist_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>\n";
    }

    // HowTo Schema
    $howto_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'HowTo',
        'name' => 'How to Buy a Home in a Top-Rated Massachusetts School District',
        'description' => 'A step-by-step guide to finding and purchasing a home in Massachusetts\' best school districts.',
        'step' => array(
            array('@type' => 'HowToStep', 'position' => 1, 'name' => 'Research School Districts', 'text' => 'Compare Massachusetts school districts by grade and MCAS scores to identify your target areas.'),
            array('@type' => 'HowToStep', 'position' => 2, 'name' => 'Set Your Budget', 'text' => 'Understand the price premiums in top districts - A-rated districts typically have median prices from $650K to $2M+.'),
            array('@type' => 'HowToStep', 'position' => 3, 'name' => 'Search Properties', 'text' => 'Use BMN Boston\'s unique school grade filter to search only in your target school districts.'),
            array('@type' => 'HowToStep', 'position' => 4, 'name' => 'Connect with Experts', 'text' => 'Work with local real estate agents who specialize in family-focused relocations and school district expertise.'),
        ),
    );
    echo '<script type="application/ld+json">' . "\n" . wp_json_encode($howto_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>\n";

    // Note: FAQPage schema is handled via microdata in the HTML FAQ section below
    // This avoids duplication and allows for dynamic content with links

    // BreadcrumbList Schema
    $breadcrumb_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => array(
            array('@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/')),
            array('@type' => 'ListItem', 'position' => 2, 'name' => 'Schools Guide', 'item' => $schools_guide_seo_data['url']),
        ),
    );
    echo '<script type="application/ld+json">' . "\n" . wp_json_encode($breadcrumb_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>\n";
}, 6);

// ============================================================================
// HEADER
// ============================================================================
get_header();
?>

<main id="main" class="bne-guide-page" role="main">

    <!-- Hero Section -->
    <section class="bne-guide-hero">
        <div class="bne-container">
            <div class="bne-guide-hero__content">
                <span class="bne-guide-hero__badge">2026 Edition</span>
                <h1 class="bne-guide-hero__title">The Ultimate Guide to Buying a Home in Massachusetts' Top School Districts</h1>
                <p class="bne-guide-hero__subtitle">
                    Compare <?php echo esc_html(number_format($total_districts)); ?> school districts, find family homes in A-rated zones,
                    and connect with local experts who know the market.
                </p>
                <p class="bne-guide-hero__social-proof">
                    <span class="bne-guide-social-proof__count"><?php echo esc_html(number_format($display_count)); ?>+</span> families have downloaded this guide
                </p>
                <div class="bne-guide-hero__stats">
                    <div class="bne-guide-hero__stat">
                        <span class="bne-guide-hero__stat-number"><?php echo esc_html(number_format($total_schools)); ?></span>
                        <span class="bne-guide-hero__stat-label">Schools Analyzed</span>
                    </div>
                    <div class="bne-guide-hero__stat">
                        <span class="bne-guide-hero__stat-number"><?php echo esc_html(number_format($total_districts)); ?></span>
                        <span class="bne-guide-hero__stat-label">School Districts</span>
                    </div>
                    <div class="bne-guide-hero__stat">
                        <span class="bne-guide-hero__stat-number"><?php echo esc_html(number_format($total_active_listings)); ?></span>
                        <span class="bne-guide-hero__stat-label">Active Listings</span>
                    </div>
                </div>
                <!-- Inline Email Form -->
                <div class="bne-guide-hero__inline-form">
                    <form class="bne-guide-inline-form" id="guide-inline-form">
                        <input type="email" name="email" placeholder="Enter your email" required>
                        <button type="submit" class="bne-btn bne-btn--primary">Get Free Guide</button>
                    </form>
                    <p class="bne-guide-inline-form__note">Free PDF with all 20 A+ districts and pricing</p>
                </div>
                <div class="bne-guide-hero__cta">
                    <a href="<?php echo esc_url(home_url('/search/#school_grade=A&PropertyType=Residential&status=Active')); ?>" class="bne-btn bne-btn--outline bne-btn--lg">Search A-Rated Districts</a>
                    <a href="<?php echo esc_url(home_url('/schools/')); ?>" class="bne-btn bne-btn--outline bne-btn--lg">Browse All Districts</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Why Schools Matter Section -->
    <section class="bne-guide-section bne-guide-section--intro">
        <div class="bne-container">
            <div class="bne-guide-content">
                <h2>Why 91% of Massachusetts Home Buyers Prioritize Schools</h2>

                <p class="bne-guide-lead">
                    If you're a growing family searching for a home in Massachusetts, you already know the drill:
                    <strong>find the best schools first, then find the house</strong>. It's not just about getting your kids
                    into good schools - it's about making one of the smartest financial decisions of your life.
                </p>

                <div class="bne-guide-highlights">
                    <div class="bne-guide-highlight">
                        <span class="bne-guide-highlight__icon">🏆</span>
                        <div class="bne-guide-highlight__content">
                            <strong>Top 5 State</strong>
                            <span>Massachusetts consistently ranks among the top 5 states for public education nationally</span>
                        </div>
                    </div>
                    <div class="bne-guide-highlight">
                        <span class="bne-guide-highlight__icon">📈</span>
                        <div class="bne-guide-highlight__content">
                            <strong>49-78% Price Premium</strong>
                            <span>Homes in top-rated school districts command significant premiums over surrounding areas</span>
                        </div>
                    </div>
                    <div class="bne-guide-highlight">
                        <span class="bne-guide-highlight__icon">⚡</span>
                        <div class="bne-guide-highlight__content">
                            <strong>8 Days Faster</strong>
                            <span>Properties in high-performing districts sell 8 days faster on average</span>
                        </div>
                    </div>
                </div>

                <div class="bne-guide-callout">
                    <h3>The Problem with Other Platforms</h3>
                    <p>
                        Most real estate platforms make it nearly impossible to actually <em>filter</em> properties by school quality.
                        You can see nearby schools after clicking on a home, but you can't search for "all homes in A-rated school districts under $1 million."
                    </p>
                    <p><strong>That's exactly why we built BMN Boston differently.</strong></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Platform Differentiator Section - MOVED UP -->
    <section class="bne-guide-section bne-guide-section--platform">
        <div class="bne-container">
            <div class="bne-guide-platform">
                <div class="bne-guide-platform__content">
                    <h2>Why BMN Boston is Different</h2>
                    <p>Most platforms show you schools <em>after</em> you find a home. We let you <strong>start with your school requirements</strong>.</p>

                    <div class="bne-guide-comparison">
                        <div class="bne-guide-comparison__row bne-guide-comparison__row--header">
                            <span>Feature</span>
                            <span>Zillow</span>
                            <span>Redfin</span>
                            <span>BMN Boston</span>
                        </div>
                        <div class="bne-guide-comparison__row">
                            <span>Filter by School Grade</span>
                            <span class="bne-guide-comparison__no">No</span>
                            <span class="bne-guide-comparison__no">No</span>
                            <span class="bne-guide-comparison__yes">Yes</span>
                        </div>
                        <div class="bne-guide-comparison__row">
                            <span>District Average Scores</span>
                            <span class="bne-guide-comparison__no">No</span>
                            <span class="bne-guide-comparison__no">No</span>
                            <span class="bne-guide-comparison__yes">Yes</span>
                        </div>
                        <div class="bne-guide-comparison__row">
                            <span>Composite Rankings (8 factors)</span>
                            <span class="bne-guide-comparison__no">No</span>
                            <span class="bne-guide-comparison__no">No</span>
                            <span class="bne-guide-comparison__yes">Yes</span>
                        </div>
                        <div class="bne-guide-comparison__row">
                            <span>Push Alerts for School Zones</span>
                            <span class="bne-guide-comparison__no">No</span>
                            <span class="bne-guide-comparison__no">No</span>
                            <span class="bne-guide-comparison__yes">Yes</span>
                        </div>
                    </div>
                </div>
                <div class="bne-guide-platform__app">
                    <div class="bne-guide-app-promo">
                        <h3>Get the iOS App</h3>
                        <p>Instant alerts when homes hit the market in your target school districts.</p>
                        <div class="bne-guide-app-promo__download" id="bne-guide-app-download">
                            <img src="<?php echo esc_url($qr_code_url); ?>" alt="Scan to download app" class="bne-guide-app-promo__qr" id="bne-guide-qr">
                            <div class="bne-guide-app-promo__badges">
                                <span class="bne-guide-app-promo__label" id="bne-guide-qr-label">Scan or click to download</span>
                                <a href="<?php echo esc_url($app_store_url); ?>" target="_blank" rel="noopener" class="bne-guide-app-badge">
                                    <img src="https://tools.applemediaservices.com/api/badges/download-on-the-app-store/white/en-us?size=250x83" alt="Download on the App Store" height="40">
                                </a>
                            </div>
                        </div>
                    </div>
                    <script>
                    (function() {
                        var ua = navigator.userAgent;
                        var isMobile = /iPhone|iPad|iPod|Android/.test(ua);
                        if (isMobile) {
                            var qr = document.getElementById('bne-guide-qr');
                            var label = document.getElementById('bne-guide-qr-label');
                            if (qr) qr.style.display = 'none';
                            if (label) label.textContent = 'Tap to download';
                        }
                    })();
                    </script>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="bne-guide-section bne-guide-section--testimonials">
        <div class="bne-container">
            <h2>What Families Say</h2>
            <div class="bne-guide-testimonials">
                <div class="bne-guide-testimonial">
                    <div class="bne-guide-testimonial__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <blockquote>
                        "This guide saved us weeks of research. We found our home in Natick and couldn't be happier with the schools."
                    </blockquote>
                    <cite>— Sarah M., Natick homeowner</cite>
                </div>
                <div class="bne-guide-testimonial">
                    <div class="bne-guide-testimonial__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <blockquote>
                        "Finally a real estate team that understands what matters to families. The school grade filter is a game-changer."
                    </blockquote>
                    <cite>— David L., Newton</cite>
                </div>
                <div class="bne-guide-testimonial">
                    <div class="bne-guide-testimonial__stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <blockquote>
                        "We relocated from California and didn't know MA school districts. This guide was our roadmap."
                    </blockquote>
                    <cite>— Jennifer &amp; Mark T., Wellesley</cite>
                </div>
            </div>
        </div>
    </section>

    <!-- Top Districts Table -->
    <section class="bne-guide-section bne-guide-section--rankings">
        <div class="bne-container">
            <h2>Top 40 School Districts in Massachusetts (2026)</h2>
            <p class="bne-guide-section__intro">Based on our comprehensive analysis of <?php echo esc_html(number_format($total_schools)); ?> schools using MCAS scores, graduation rates, and 6 other factors.</p>

            <div class="bne-guide-table-wrapper">
                <table class="bne-guide-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>District</th>
                            <th>Grade</th>
                            <th>Score</th>
                            <th>Percentile</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 1;
                        foreach ($top_districts as $district) :
                            $district_name_clean = str_replace(' School District', '', $district->district_name);
                            $search_url = home_url('/search/#city=' . urlencode($district_name_clean) . '&PropertyType=Residential&status=Active');
                            // Create district page URL slug
                            $district_slug = sanitize_title($district->district_name);
                            $district_url = home_url('/schools/' . $district_slug . '/');
                        ?>
                        <tr>
                            <td class="bne-guide-table__rank"><?php echo esc_html($rank); ?></td>
                            <td class="bne-guide-table__district">
                                <a href="<?php echo esc_url($district_url); ?>" class="bne-guide-table__district-link">
                                    <strong><?php echo esc_html($district_name_clean); ?></strong>
                                </a>
                            </td>
                            <td class="bne-guide-table__grade">
                                <span class="bne-grade-badge bne-grade-badge--<?php echo esc_attr(strtolower(str_replace('+', 'plus', $district->letter_grade))); ?>">
                                    <?php echo esc_html($district->letter_grade); ?>
                                </span>
                            </td>
                            <td class="bne-guide-table__score"><?php echo esc_html(number_format($district->composite_score, 1)); ?></td>
                            <td class="bne-guide-table__percentile"><?php echo esc_html($district->percentile_rank); ?>th</td>
                            <td class="bne-guide-table__action">
                                <a href="<?php echo esc_url($search_url); ?>" class="bne-btn bne-btn--sm">
                                    View Homes
                                </a>
                            </td>
                        </tr>
                        <?php
                            $rank++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="bne-guide-cta-inline">
                <a href="<?php echo esc_url(home_url('/schools/')); ?>" class="bne-btn bne-btn--outline">View All <?php echo esc_html($total_districts); ?> Districts</a>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="bne-guide-section bne-guide-section--faq">
        <div class="bne-container">
            <h2>Frequently Asked Questions</h2>
            <div class="bne-guide-faq-list" itemscope itemtype="https://schema.org/FAQPage">

                <div class="bne-guide-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="bne-guide-faq-item__question" itemprop="name">What are the best school districts to buy a home in Massachusetts?</h3>
                    <div class="bne-guide-faq-item__answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p itemprop="text">Based on our 2026 analysis of <?php echo esc_html($total_districts); ?> districts, the top-rated districts include <strong>Lexington, Needham, Brookline, Newton, and Arlington</strong>. These A+ districts have composite scores above 65 based on MCAS proficiency, graduation rates, and college outcomes. <a href="<?php echo esc_url(home_url('/schools/')); ?>">View all district rankings</a>.</p>
                    </div>
                </div>

                <div class="bne-guide-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="bne-guide-faq-item__question" itemprop="name">How much do homes cost in top Massachusetts school districts?</h3>
                    <div class="bne-guide-faq-item__answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p itemprop="text">Family homes (3+ bedrooms) in premium A+ districts like <strong>Newton</strong> start around $790K, while value-oriented A+ districts like <strong>Natick</strong> offer entry points from $630K. Budget-friendly A-rated options like <strong>Canton</strong> and <strong>Easton</strong> have family homes starting around $500K.</p>
                    </div>
                </div>

                <div class="bne-guide-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="bne-guide-faq-item__question" itemprop="name">Can I search for homes by school district grade?</h3>
                    <div class="bne-guide-faq-item__answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p itemprop="text">Yes, <strong>BMN Boston is the only real estate platform</strong> that lets you filter properties by school district grade. You can search for all homes in A-rated, B-rated, or C-rated districts directly from our <a href="<?php echo esc_url(home_url('/search/#school_grade=A&PropertyType=Residential&status=Active')); ?>">property search</a> - something not possible on Zillow or Redfin.</p>
                    </div>
                </div>

                <div class="bne-guide-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="bne-guide-faq-item__question" itemprop="name">What is the cheapest A-rated school district in Massachusetts?</h3>
                    <div class="bne-guide-faq-item__answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p itemprop="text"><strong>Canton</strong> offers the lowest entry point for A-rated schools with family homes starting at $499,900. Other affordable A-rated options include <strong>Easton</strong> ($520K), <strong>Chelmsford</strong> ($529K), and <strong>Framingham</strong> ($530K). <a href="<?php echo esc_url(home_url('/search/#school_grade=A&price_max=600000&PropertyType=Residential&status=Active')); ?>">Search affordable A-rated homes</a>.</p>
                    </div>
                </div>

                <div class="bne-guide-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="bne-guide-faq-item__question" itemprop="name">How do Massachusetts school district grades work?</h3>
                    <div class="bne-guide-faq-item__answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p itemprop="text">BMN Boston calculates school grades using a composite score based on <strong>8 factors</strong>: MCAS proficiency (40-45%), graduation rates (12%), MCAS growth (10-15%), AP performance (9%), attendance (8-20%), student-teacher ratio (5-8%), and per-pupil spending (4-12%). Schools are graded A+ through F based on their percentile rank among all Massachusetts schools.</p>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- Family Home Prices Section -->
    <section class="bne-guide-section bne-guide-section--prices">
        <div class="bne-container">
            <h2>Family Home Prices by School District</h2>
            <p class="bne-guide-section__intro">
                <strong>Note:</strong> If you're moving for schools, you're likely looking for a <strong>3+ bedroom single-family home or townhouse</strong>.
                The data below reflects exactly that.
            </p>

            <div class="bne-guide-price-tiers">
                <!-- Premium Tier -->
                <div class="bne-guide-price-tier">
                    <div class="bne-guide-price-tier__header bne-guide-price-tier__header--premium">
                        <h3>Premium A+ Districts</h3>
                        <span class="bne-guide-price-tier__range">$790K - $12M+</span>
                    </div>
                    <div class="bne-guide-price-tier__content">
                        <ul class="bne-guide-price-list">
                            <li><strong>Newton</strong> - 49 homes from $790K <span class="bne-tag bne-tag--best">Best Entry</span></li>
                            <li><strong>Hingham</strong> - 18 homes from $775K</li>
                            <li><strong>Westwood</strong> - 16 homes from $785K</li>
                            <li><strong>Needham</strong> - 20 homes from $849K</li>
                            <li><strong>Lexington</strong> - 28 homes from $1.48M</li>
                            <li><strong>Wellesley</strong> - 26 homes from $1.19M</li>
                        </ul>
                        <a href="<?php echo esc_url(home_url('/search/#school_grade=A&home_type=Single+Family+Residence&beds=3,4,5&PropertyType=Residential&status=Active')); ?>" class="bne-btn bne-btn--sm">
                            Search Premium Districts
                        </a>
                    </div>
                </div>

                <!-- Value Tier -->
                <div class="bne-guide-price-tier">
                    <div class="bne-guide-price-tier__header bne-guide-price-tier__header--value">
                        <h3>Best Value A+ Districts</h3>
                        <span class="bne-guide-price-tier__range">$630K - $1M</span>
                    </div>
                    <div class="bne-guide-price-tier__content">
                        <ul class="bne-guide-price-list">
                            <li><strong>Natick</strong> - 22 homes from $630K <span class="bne-tag bne-tag--best">Best Value A+</span></li>
                            <li><strong>Hopkinton</strong> - 14 homes from $700K</li>
                            <li><strong>Sharon</strong> - 8 homes from $700K</li>
                            <li><strong>Milton</strong> - 8 homes from $775K</li>
                            <li><strong>Winchester</strong> - 12 homes from $829K</li>
                            <li><strong>Bedford</strong> - 11 homes from $849K</li>
                        </ul>
                        <a href="<?php echo esc_url(home_url('/search/#school_grade=A&home_type=Single+Family+Residence&beds=3,4,5&price_max=1000000&PropertyType=Residential&status=Active')); ?>" class="bne-btn bne-btn--sm">
                            Search Value A+ Districts
                        </a>
                    </div>
                </div>

                <!-- Budget Tier -->
                <div class="bne-guide-price-tier">
                    <div class="bne-guide-price-tier__header bne-guide-price-tier__header--budget">
                        <h3>A-Rated Under $600K</h3>
                        <span class="bne-guide-price-tier__range">$250K - $600K</span>
                    </div>
                    <div class="bne-guide-price-tier__content">
                        <ul class="bne-guide-price-list">
                            <li><strong>Canton</strong> - 10 homes from $500K <span class="bne-tag bne-tag--best">Best Budget A</span></li>
                            <li><strong>Easton</strong> - 25 homes from $520K <span class="bne-tag">Most Inventory</span></li>
                            <li><strong>Chelmsford</strong> - 9 homes from $529K</li>
                            <li><strong>Framingham</strong> - 19 homes from $530K</li>
                            <li><strong>Westford</strong> - 17 homes from $540K</li>
                            <li><strong>Worcester</strong> - 95 homes from $250K (B+)</li>
                        </ul>
                        <a href="<?php echo esc_url(home_url('/search/#home_type=Single+Family+Residence&beds=3,4,5&price_max=600000&PropertyType=Residential&status=Active')); ?>" class="bne-btn bne-btn--sm">
                            Search Budget-Friendly
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Team Section -->
    <section class="bne-guide-section bne-guide-section--team">
        <div class="bne-container">
            <h2>Meet Your Local Experts</h2>
            <p class="bne-guide-section__intro">
                In a competitive market, you need more than a platform - you need people who know the territory.
            </p>

            <div class="bne-guide-team-stats">
                <div class="bne-guide-team-stat">
                    <span class="bne-guide-team-stat__number">$100M</span>
                    <span class="bne-guide-team-stat__label">2025 Sales Volume</span>
                </div>
                <div class="bne-guide-team-stat">
                    <span class="bne-guide-team-stat__number">$700M+</span>
                    <span class="bne-guide-team-stat__label">Career Total</span>
                </div>
                <div class="bne-guide-team-stat">
                    <span class="bne-guide-team-stat__number">#1</span>
                    <span class="bne-guide-team-stat__label">Small Team in MA (Douglas Elliman)</span>
                </div>
            </div>

            <div class="bne-guide-team-grid">
                <div class="bne-guide-team-member">
                    <img src="<?php echo esc_url(content_url('/uploads/2025/12/Steve-Novak-600x600-1.jpg')); ?>" alt="Steven Novak" class="bne-guide-team-member__photo">
                    <h4>Steven Novak</h4>
                    <span class="bne-guide-team-member__title">Team Lead</span>
                    <p>Top 1.5% of agents nationwide. Boston Magazine Top Producer. Fluent in English and Russian.</p>
                    <a href="tel:6179552224" class="bne-guide-team-member__phone">617-955-2224</a>
                </div>
                <div class="bne-guide-team-member">
                    <img src="<?php echo esc_url(content_url('/uploads/2025/12/craig-brody-600x600-1.jpg')); ?>" alt="Craig Brody" class="bne-guide-team-member__photo">
                    <h4>Craig Brody</h4>
                    <span class="bne-guide-team-member__title">Team Lead</span>
                    <p>Grew up in Framingham. Expert in Back Bay, Beacon Hill + Metrowest suburbs. Knows school dynamics firsthand.</p>
                    <a href="tel:6175191480" class="bne-guide-team-member__phone">617-519-1480</a>
                </div>
                <div class="bne-guide-team-member">
                    <img src="<?php echo esc_url(content_url('/uploads/2025/12/Ashlie-Tucker-600x600-1.jpg')); ?>" alt="Ashlie Tucker" class="bne-guide-team-member__photo">
                    <h4>Ashlie Tucker</h4>
                    <span class="bne-guide-team-member__title">Agent & Marketing Specialist</span>
                    <p>First-time buyer specialist. UNH Hospitality Management degree. Makes every transaction smooth.</p>
                    <a href="tel:6038489194" class="bne-guide-team-member__phone">603-848-9194</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Lead Capture Section -->
    <section id="download-guide" class="bne-guide-section bne-guide-section--download">
        <div class="bne-container">
            <div class="bne-guide-download">
                <div class="bne-guide-download__content">
                    <h2>Download the Free PDF Guide</h2>
                    <p>Get our complete 10-page guide with:</p>
                    <ul>
                        <li>All 20 A+ districts with prices</li>
                        <li>Budget tier recommendations</li>
                        <li>School search checklist</li>
                        <li>Commuter access guide</li>
                    </ul>
                    <div class="bne-guide-trust-badges">
                        <div class="bne-guide-trust-badge">
                            <span class="bne-guide-trust-badge__icon">&#127942;</span>
                            <span>#1 Small Team in MA</span>
                        </div>
                        <div class="bne-guide-trust-badge">
                            <span class="bne-guide-trust-badge__icon">&#128176;</span>
                            <span>$700M+ Career Sales</span>
                        </div>
                        <div class="bne-guide-trust-badge">
                            <span class="bne-guide-trust-badge__icon">&#128274;</span>
                            <span>100% Free, No Spam</span>
                        </div>
                    </div>
                </div>
                <div class="bne-guide-download__form">
                    <div id="guide-form-container">
                        <form class="bne-guide-form" id="guide-download-form">
                            <div class="bne-guide-form__field">
                                <label for="guide_name">Your Name</label>
                                <input type="text" id="guide_name" name="name" required placeholder="First name">
                            </div>
                            <div class="bne-guide-form__field">
                                <label for="guide_email">Email Address</label>
                                <input type="email" id="guide_email" name="email" required placeholder="you@email.com">
                            </div>
                            <button type="submit" class="bne-btn bne-btn--primary bne-btn--lg bne-btn--full" id="guide-submit-btn">
                                Download Free Guide
                            </button>
                            <p class="bne-guide-form__disclaimer">
                                We respect your privacy. Unsubscribe anytime.
                            </p>
                        </form>
                    </div>
                    <div id="guide-success-message" style="display: none;">
                        <div class="bne-guide-form__success">
                            <h3>Your Download Has Started!</h3>
                            <p>The guide is downloading to your device.</p>

                            <!-- Progressive Phone Capture -->
                            <div class="bne-guide-phone-capture" id="phone-capture-form">
                                <p><strong>One more thing...</strong></p>
                                <p>Want personalized recommendations? Our agents can text you homes in your target districts as they hit the market.</p>
                                <form id="guide-phone-form">
                                    <div class="bne-guide-form__field">
                                        <input type="tel" id="guide_phone" name="phone" placeholder="(555) 555-5555" pattern="[0-9\-\(\)\s]+">
                                    </div>
                                    <button type="submit" class="bne-btn bne-btn--outline bne-btn--full">
                                        Yes, Text Me New Listings
                                    </button>
                                    <button type="button" class="bne-guide-skip-link" id="skip-phone">
                                        No thanks, just the guide
                                    </button>
                                </form>
                            </div>

                            <!-- Final CTA (shown after phone capture or skip) -->
                            <div class="bne-guide-final-success" id="final-success" style="display: none;">
                                <p>Ready to find your perfect home?</p>
                                <a href="<?php echo esc_url(home_url('/search/#school_grade=A&PropertyType=Residential&status=Active')); ?>" class="bne-btn bne-btn--primary bne-btn--lg">
                                    Start Searching Now
                                </a>
                                <p style="margin-top: 1rem;">
                                    Or call us at <a href="tel:6178009008" style="color: #fff; font-weight: 600;">617-800-9008</a>
                                </p>
                            </div>
                        </div>
                    </div>
                    <script>
                    document.getElementById('guide-download-form').addEventListener('submit', function(e) {
                        e.preventDefault();

                        var name = document.getElementById('guide_name').value;
                        var email = document.getElementById('guide_email').value;
                        var submitBtn = document.getElementById('guide-submit-btn');

                        // Disable button while processing
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Processing...';

                        // Track the lead via AJAX
                        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=bne_track_guide_download&name=' + encodeURIComponent(name) + '&email=' + encodeURIComponent(email) + '&nonce=<?php echo wp_create_nonce('bne_guide_download'); ?>'
                        }).then(function(response) {
                            return response.json();
                        }).then(function(data) {
                            // Trigger PDF download
                            var link = document.createElement('a');
                            link.href = '<?php echo esc_url(content_url('/uploads/guides/ma-school-district-guide-2026.pdf')); ?>';
                            link.download = 'MA-School-District-Guide-2026.pdf';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);

                            // Show success message
                            document.getElementById('guide-form-container').style.display = 'none';
                            document.getElementById('guide-success-message').style.display = 'block';
                        }).catch(function(error) {
                            // Still download even if tracking fails
                            var link = document.createElement('a');
                            link.href = '<?php echo esc_url(content_url('/uploads/guides/ma-school-district-guide-2026.pdf')); ?>';
                            link.download = 'MA-School-District-Guide-2026.pdf';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);

                            document.getElementById('guide-form-container').style.display = 'none';
                            document.getElementById('guide-success-message').style.display = 'block';
                        });
                    });
                    </script>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA -->
    <section class="bne-guide-section bne-guide-section--cta">
        <div class="bne-container">
            <div class="bne-guide-final-cta">
                <h2>Ready to Find Your Family's Perfect Home?</h2>
                <p>Start searching by school district grade - something you can't do anywhere else.</p>
                <div class="bne-guide-final-cta__buttons">
                    <a href="<?php echo esc_url(home_url('/search/#school_grade=A&PropertyType=Residential&status=Active')); ?>" class="bne-btn bne-btn--primary bne-btn--lg">
                        Search A-Rated Districts
                    </a>
                    <a href="tel:6178009008" class="bne-btn bne-btn--outline bne-btn--lg">
                        Call 617-800-9008
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Sticky Bottom Bar -->
    <div class="bne-guide-sticky-bar" id="guide-sticky-bar">
        <div class="bne-container">
            <div class="bne-guide-sticky-bar__content">
                <span class="bne-guide-sticky-bar__text">
                    <strong>Free:</strong> MA School District Guide 2026
                </span>
                <button class="bne-btn bne-btn--primary bne-btn--sm" id="sticky-bar-cta">
                    Download Now
                </button>
            </div>
        </div>
    </div>

    <!-- Exit Intent Popup -->
    <div class="bne-guide-popup-overlay" id="exit-popup">
        <div class="bne-guide-popup">
            <button class="bne-guide-popup__close" id="popup-close">&times;</button>
            <div class="bne-guide-popup__content">
                <h3>Before You Go...</h3>
                <p>Get our free guide with rankings for all 20 A+ school districts in Massachusetts.</p>
                <form class="bne-guide-popup-form" id="exit-popup-form">
                    <input type="email" name="email" placeholder="Your email" required>
                    <button type="submit" class="bne-btn bne-btn--primary bne-btn--full">
                        Send Me the Free Guide
                    </button>
                </form>
                <p class="bne-guide-popup__disclaimer">No spam. Unsubscribe anytime.</p>
            </div>
        </div>
    </div>

</main>

<script>
(function() {
    var pdfUrl = '<?php echo esc_url(content_url('/uploads/guides/ma-school-district-guide-2026.pdf')); ?>';
    var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    var nonce = '<?php echo wp_create_nonce('bne_guide_download'); ?>';

    // Helper function to trigger PDF download
    function triggerPdfDownload() {
        var link = document.createElement('a');
        link.href = pdfUrl;
        link.download = 'MA-School-District-Guide-2026.pdf';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Helper function to track lead
    function trackLead(name, email, source, callback) {
        // Store email for phone capture
        sessionStorage.setItem('guide_email', email);

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=bne_track_guide_download&name=' + encodeURIComponent(name) + '&email=' + encodeURIComponent(email) + '&source=' + encodeURIComponent(source) + '&nonce=' + nonce
        })
        .then(function(response) { return response.json(); })
        .then(callback)
        .catch(callback);
    }

    // Hero inline form handler
    var inlineForm = document.getElementById('guide-inline-form');
    if (inlineForm) {
        inlineForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var email = this.querySelector('input[name="email"]').value;
            trackLead('', email, 'hero_inline', function() {
                triggerPdfDownload();
                sessionStorage.setItem('guide_downloaded', 'true');
                // Scroll to success section or show inline success
                document.getElementById('download-guide').scrollIntoView({ behavior: 'smooth' });
                document.getElementById('guide-form-container').style.display = 'none';
                document.getElementById('guide-success-message').style.display = 'block';
            });
        });
    }

    // Exit popup form handler
    var popupForm = document.getElementById('exit-popup-form');
    if (popupForm) {
        popupForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var email = this.querySelector('input[name="email"]').value;
            trackLead('', email, 'exit_popup', function() {
                triggerPdfDownload();
                sessionStorage.setItem('guide_downloaded', 'true');
                closePopup();
            });
        });
    }

    // Sticky bar logic
    var stickyBar = document.getElementById('guide-sticky-bar');
    var downloadSection = document.getElementById('download-guide');
    var heroSection = document.querySelector('.bne-guide-hero');

    function updateStickyBar() {
        var scrollY = window.scrollY;
        var heroBottom = heroSection ? heroSection.offsetTop + heroSection.offsetHeight : 300;
        var downloadRect = downloadSection.getBoundingClientRect();

        if (scrollY > heroBottom && downloadRect.top > window.innerHeight) {
            stickyBar.classList.add('is-visible');
        } else {
            stickyBar.classList.remove('is-visible');
        }
    }

    window.addEventListener('scroll', updateStickyBar);

    // Sticky bar CTA click
    var stickyBarCta = document.getElementById('sticky-bar-cta');
    if (stickyBarCta) {
        stickyBarCta.addEventListener('click', function() {
            downloadSection.scrollIntoView({ behavior: 'smooth' });
        });
    }

    // Exit intent popup logic
    var popup = document.getElementById('exit-popup');
    var popupShown = sessionStorage.getItem('guide_popup_shown');
    var formSubmitted = sessionStorage.getItem('guide_downloaded');

    function showPopup() {
        if (!popupShown && !formSubmitted) {
            popup.style.display = 'flex';
            sessionStorage.setItem('guide_popup_shown', 'true');
            popupShown = true;
        }
    }

    function closePopup() {
        popup.style.display = 'none';
    }

    // Exit intent detection (desktop)
    document.addEventListener('mouseout', function(e) {
        if (e.clientY < 10 && !popupShown && !formSubmitted) {
            showPopup();
        }
    });

    // Inactivity timer (45 seconds)
    var inactivityTimer;
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(function() {
            if (!popupShown && !formSubmitted) {
                showPopup();
            }
        }, 45000);
    }

    ['mousemove', 'scroll', 'keydown', 'click', 'touchstart'].forEach(function(event) {
        document.addEventListener(event, resetInactivityTimer);
    });
    resetInactivityTimer();

    // Popup close button
    var popupClose = document.getElementById('popup-close');
    if (popupClose) {
        popupClose.addEventListener('click', closePopup);
    }

    // Close popup when clicking overlay
    popup.addEventListener('click', function(e) {
        if (e.target === popup) {
            closePopup();
        }
    });

    // Phone capture form handler
    var phoneForm = document.getElementById('guide-phone-form');
    var skipPhone = document.getElementById('skip-phone');
    var phoneCaptureDiv = document.getElementById('phone-capture-form');
    var finalSuccessDiv = document.getElementById('final-success');

    function showFinalSuccess() {
        if (phoneCaptureDiv) phoneCaptureDiv.style.display = 'none';
        if (finalSuccessDiv) finalSuccessDiv.style.display = 'block';
    }

    if (phoneForm) {
        phoneForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var phone = document.getElementById('guide_phone').value;
            var email = sessionStorage.getItem('guide_email') || '';

            if (phone) {
                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=bne_capture_guide_phone&phone=' + encodeURIComponent(phone) + '&email=' + encodeURIComponent(email) + '&nonce=' + nonce
                }).then(function() {
                    showFinalSuccess();
                }).catch(function() {
                    showFinalSuccess();
                });
            } else {
                showFinalSuccess();
            }
        });
    }

    if (skipPhone) {
        skipPhone.addEventListener('click', showFinalSuccess);
    }
})();
</script>

<?php get_footer(); ?>
