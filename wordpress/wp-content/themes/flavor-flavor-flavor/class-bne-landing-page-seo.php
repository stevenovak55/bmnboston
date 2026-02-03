<?php
/**
 * Landing Page SEO Class
 *
 * Handles Schema.org structured data, meta descriptions, and canonical URLs
 * for neighborhood and school district landing pages
 * Updated for GEO (Generative Engine Optimization) with data freshness signals
 *
 * @package flavor_flavor_flavor
 * @version 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNE_Landing_Page_SEO {

    /**
     * Current page data
     * @var array
     */
    private static $page_data = array();

    /**
     * Page type (neighborhood, school_district, city)
     * @var string
     */
    private static $page_type = '';

    /**
     * Initialize SEO hooks
     */
    public static function init() {
        add_action('wp_head', array(__CLASS__, 'output_schema_markup'), 5);
        add_action('wp_head', array(__CLASS__, 'output_meta_tags'), 1);
        add_filter('document_title_parts', array(__CLASS__, 'filter_document_title'), 15);
        add_filter('wpseo_title', array(__CLASS__, 'filter_seo_title'), 15);
        add_filter('wpseo_metadesc', array(__CLASS__, 'filter_seo_description'), 15);
        add_filter('rank_math/frontend/title', array(__CLASS__, 'filter_seo_title'), 15);
        add_filter('rank_math/frontend/description', array(__CLASS__, 'filter_seo_description'), 15);
    }

    /**
     * Set page data for SEO output
     *
     * @param array $data Page data
     * @param string $type Page type (neighborhood, school_district, city)
     */
    public static function set_page_data($data, $type = 'neighborhood') {
        self::$page_data = $data;
        self::$page_type = $type;
    }

    /**
     * Get current page data
     *
     * @return array
     */
    public static function get_page_data() {
        return self::$page_data;
    }

    /**
     * Output Schema.org structured data
     */
    public static function output_schema_markup() {
        // Debug logging
        error_log('BNE_Landing_Page_SEO::output_schema_markup called - page_type: ' . self::$page_type . ', data keys: ' . (is_array(self::$page_data) ? count(self::$page_data) : 'not array'));

        if (empty(self::$page_data) || empty(self::$page_type)) {
            error_log('BNE_Landing_Page_SEO::output_schema_markup - EXITING EARLY: page_data empty=' . (empty(self::$page_data) ? 'yes' : 'no') . ', page_type empty=' . (empty(self::$page_type) ? 'yes' : 'no'));
            return;
        }

        $schemas = array();

        // Add CollectionPage schema
        $schemas[] = self::get_collection_page_schema();

        // Add Place schema for location
        $schemas[] = self::get_place_schema();

        // Add BreadcrumbList schema
        $schemas[] = self::get_breadcrumb_schema();

        // Add RealEstateAgent schema (business info)
        $schemas[] = self::get_real_estate_agent_schema();

        // Add EducationalOrganization schema for school pages
        if (self::$page_type === 'school_district_detail') {
            $schemas[] = self::get_educational_organization_schema();
        } elseif (self::$page_type === 'school_detail') {
            $schemas[] = self::get_school_schema();
        } elseif (self::$page_type === 'schools_guide') {
            // Add comprehensive schemas for schools buying guide
            $schemas[] = self::get_schools_guide_article_schema();
            $schemas[] = self::get_schools_guide_itemlist_schema();
            $schemas[] = self::get_schools_guide_howto_schema();
            $schemas[] = self::get_schools_guide_faq_schema();
        }

        // Output all schemas
        foreach ($schemas as $schema) {
            if (!empty($schema)) {
                echo '<script type="application/ld+json">' . "\n";
                echo wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                echo "\n</script>\n";
            }
        }
    }

    /**
     * Get CollectionPage schema for listing collection pages
     *
     * @return array
     */
    private static function get_collection_page_schema() {
        $data = self::$page_data;

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => self::get_page_title(),
            'description' => self::get_meta_description(),
            'url' => self::get_canonical_url(),
            'isPartOf' => array(
                '@type' => 'WebSite',
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
            ),
        );

        // Add listing count if available
        if (!empty($data['listing_count'])) {
            $schema['numberOfItems'] = intval($data['listing_count']);
        }

        // Add main entity (Place)
        if (!empty($data['name'])) {
            $schema['mainEntity'] = array(
                '@type' => 'Place',
                'name' => $data['name'],
            );

            if (!empty($data['state'])) {
                $schema['mainEntity']['address'] = array(
                    '@type' => 'PostalAddress',
                    'addressLocality' => $data['name'],
                    'addressRegion' => $data['state'],
                    'addressCountry' => 'US',
                );
            }
        }

        // Add image if available
        if (!empty($data['image'])) {
            $schema['image'] = $data['image'];
        }

        // Add date modified (using data freshness for GEO optimization)
        if (!empty($data['data_freshness'])) {
            $schema['dateModified'] = wp_date('c', strtotime($data['data_freshness']));
        } else {
            $schema['dateModified'] = wp_date('c');
        }

        return $schema;
    }

    /**
     * Get Place schema for neighborhood/location
     *
     * @return array
     */
    private static function get_place_schema() {
        $data = self::$page_data;

        if (empty($data['name'])) {
            return array();
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Place',
            'name' => $data['name'],
        );

        // Add address
        if (!empty($data['state'])) {
            $schema['address'] = array(
                '@type' => 'PostalAddress',
                'addressLocality' => $data['name'],
                'addressRegion' => $data['state'],
                'addressCountry' => 'US',
            );
        }

        // Add geo coordinates if available
        if (!empty($data['latitude']) && !empty($data['longitude'])) {
            $schema['geo'] = array(
                '@type' => 'GeoCoordinates',
                'latitude' => floatval($data['latitude']),
                'longitude' => floatval($data['longitude']),
            );
        }

        // Add image if available
        if (!empty($data['image'])) {
            $schema['image'] = $data['image'];
        }

        // Add description
        if (!empty($data['description'])) {
            $schema['description'] = $data['description'];
        }

        // Add contained places (neighborhoods within city, etc.)
        if (!empty($data['contained_places']) && is_array($data['contained_places'])) {
            $schema['containsPlace'] = array_map(function($place) {
                return array(
                    '@type' => 'Place',
                    'name' => $place['name'],
                    'url' => $place['url'] ?? '',
                );
            }, $data['contained_places']);
        }

        return $schema;
    }

    /**
     * Get BreadcrumbList schema
     *
     * @return array
     */
    private static function get_breadcrumb_schema() {
        $data = self::$page_data;
        $type = self::$page_type;

        $breadcrumbs = array(
            array(
                'name' => 'Home',
                'url' => home_url('/'),
            ),
        );

        // Handle school page types differently
        if ($type === 'schools_browse') {
            // Schools browse: Home > Schools
            $breadcrumbs[] = array(
                'name' => 'Massachusetts School Districts',
                'url' => home_url('/schools/'),
            );
        } elseif ($type === 'school_district_detail') {
            // School district: Home > Schools > {District}
            $breadcrumbs[] = array(
                'name' => 'Schools',
                'url' => home_url('/schools/'),
            );
            $breadcrumbs[] = array(
                'name' => $data['name'] ?? 'District',
                'url' => self::get_canonical_url(),
            );
        } elseif ($type === 'school_detail') {
            // Individual school: Home > Schools > {District} > {School}
            $breadcrumbs[] = array(
                'name' => 'Schools',
                'url' => home_url('/schools/'),
            );
            if (!empty($data['district']['name'])) {
                $breadcrumbs[] = array(
                    'name' => $data['district']['name'],
                    'url' => $data['district']['url'] ?? home_url('/schools/'),
                );
            }
            $breadcrumbs[] = array(
                'name' => $data['name'] ?? 'School',
                'url' => self::get_canonical_url(),
            );
        } elseif ($type === 'schools_guide') {
            // Schools buying guide: Home > Schools > Buying Guide
            $breadcrumbs[] = array(
                'name' => 'Schools',
                'url' => home_url('/schools/'),
            );
            $breadcrumbs[] = array(
                'name' => 'Home Buying Guide',
                'url' => home_url('/schools-guide/'),
            );
        } else {
            // Add state level if available
            if (!empty($data['state'])) {
                $state_name = self::get_state_name($data['state']);
                $breadcrumbs[] = array(
                    'name' => $state_name . ' Real Estate',
                    'url' => home_url('/real-estate/' . strtolower($data['state']) . '/'),
                );
            }

            // Add city level if this is a neighborhood page
            if ($type === 'neighborhood' && !empty($data['city'])) {
                $breadcrumbs[] = array(
                    'name' => $data['city'] . ' Homes',
                    'url' => home_url('/real-estate/' . sanitize_title($data['city']) . '/'),
                );
            }

            // Add current page
            $breadcrumbs[] = array(
                'name' => $data['name'] ?? 'Real Estate',
                'url' => self::get_canonical_url(),
            );
        }

        // Build schema
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array(),
        );

        foreach ($breadcrumbs as $position => $crumb) {
            $schema['itemListElement'][] = array(
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            );
        }

        return $schema;
    }

    /**
     * Get RealEstateAgent schema
     *
     * @return array
     */
    private static function get_real_estate_agent_schema() {
        $agent_name = get_theme_mod('bne_agent_name', 'Steven Novak');
        $agent_email = get_theme_mod('bne_agent_email', '');
        $agent_phone = get_theme_mod('bne_phone_number', '');
        $agent_address = get_theme_mod('bne_agent_address', '');
        $brokerage_name = get_theme_mod('bne_brokerage_name', 'Douglas Elliman Real Estate');
        $agent_photo = get_theme_mod('bne_agent_photo', '');

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'RealEstateAgent',
            'name' => $agent_name,
            'url' => home_url('/'),
        );

        if (!empty($agent_email)) {
            $schema['email'] = $agent_email;
        }

        if (!empty($agent_phone)) {
            $schema['telephone'] = $agent_phone;
        }

        if (!empty($agent_address)) {
            $schema['address'] = array(
                '@type' => 'PostalAddress',
                'streetAddress' => $agent_address,
            );
        }

        if (!empty($agent_photo)) {
            $schema['image'] = $agent_photo;
        }

        if (!empty($brokerage_name)) {
            $schema['memberOf'] = array(
                '@type' => 'RealEstateAgent',
                'name' => $brokerage_name,
            );
        }

        // Add area served based on current page
        $data = self::$page_data;
        if (!empty($data['name'])) {
            $schema['areaServed'] = array(
                '@type' => 'Place',
                'name' => $data['name'],
            );
        }

        return $schema;
    }

    /**
     * Output meta tags
     */
    public static function output_meta_tags() {
        if (empty(self::$page_data)) {
            return;
        }

        $title = self::get_page_title();
        $description = self::get_meta_description();
        $canonical = self::get_canonical_url();
        $image = self::$page_data['image'] ?? '';

        // Output meta tags
        ?>
        <!-- BNE Landing Page SEO -->
        <meta name="description" content="<?php echo esc_attr($description); ?>">
        <link rel="canonical" href="<?php echo esc_url($canonical); ?>">

        <!-- Open Graph -->
        <meta property="og:type" content="website">
        <meta property="og:title" content="<?php echo esc_attr($title); ?>">
        <meta property="og:description" content="<?php echo esc_attr($description); ?>">
        <meta property="og:url" content="<?php echo esc_url($canonical); ?>">
        <meta property="og:site_name" content="<?php echo esc_attr(get_bloginfo('name')); ?>">
        <?php if (!empty($image)) : ?>
        <meta property="og:image" content="<?php echo esc_url($image); ?>">
        <?php endif; ?>

        <!-- Twitter Card -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="<?php echo esc_attr($title); ?>">
        <meta name="twitter:description" content="<?php echo esc_attr($description); ?>">
        <?php if (!empty($image)) : ?>
        <meta name="twitter:image" content="<?php echo esc_url($image); ?>">
        <?php endif; ?>
        <!-- /BNE Landing Page SEO -->
        <?php
    }

    /**
     * Filter document title
     *
     * @param array $title_parts Title parts
     * @return array
     */
    public static function filter_document_title($title_parts) {
        if (empty(self::$page_data)) {
            return $title_parts;
        }

        $title_parts['title'] = self::get_page_title();

        return $title_parts;
    }

    /**
     * Filter SEO plugin title
     *
     * @param string $title Original title
     * @return string
     */
    public static function filter_seo_title($title) {
        if (empty(self::$page_data)) {
            return $title;
        }

        return self::get_page_title() . ' | ' . get_bloginfo('name');
    }

    /**
     * Filter SEO plugin description
     *
     * @param string $description Original description
     * @return string
     */
    public static function filter_seo_description($description) {
        if (empty(self::$page_data)) {
            return $description;
        }

        return self::get_meta_description();
    }

    /**
     * Get page title based on page type
     *
     * @return string
     */
    public static function get_page_title() {
        $data = self::$page_data;
        $type = self::$page_type;

        if (empty($data['name'])) {
            return get_bloginfo('name');
        }

        $name = $data['name'];
        $state = $data['state'] ?? 'MA';
        $count = $data['listing_count'] ?? 0;
        $listing_type = $data['listing_type'] ?? 'sale';
        $is_rental = ($listing_type === 'lease');

        switch ($type) {
            case 'neighborhood':
                if ($is_rental) {
                    if ($count > 0) {
                        return sprintf(
                            '%s Rentals - %d Apartments & Homes for Rent in %s',
                            $name,
                            $count,
                            $state
                        );
                    }
                    return sprintf('%s Rentals - Apartments & Homes for Rent', $name);
                } else {
                    if ($count > 0) {
                        return sprintf(
                            '%s Real Estate - %d Homes for Sale in %s',
                            $name,
                            $count,
                            $state
                        );
                    }
                    return sprintf('%s Real Estate - Homes for Sale', $name);
                }

            case 'school_district':
                if ($count > 0) {
                    return sprintf(
                        'Homes Near %s - %d Properties for Sale',
                        $name,
                        $count
                    );
                }
                return sprintf('Homes Near %s School District', $name);

            case 'city':
                if ($is_rental) {
                    if ($count > 0) {
                        return sprintf(
                            '%s, %s Rentals - %d Apartments & Homes for Rent',
                            $name,
                            $state,
                            $count
                        );
                    }
                    return sprintf('%s, %s Rentals - Apartments & Homes for Rent', $name, $state);
                } else {
                    if ($count > 0) {
                        return sprintf(
                            '%s, %s Real Estate - %d Homes for Sale',
                            $name,
                            $state,
                            $count
                        );
                    }
                    return sprintf('%s, %s Real Estate - Homes for Sale', $name, $state);
                }

            case 'schools_browse':
                return 'Massachusetts School Districts | Rankings & School Data';

            case 'school_district_detail':
                $grade = $data['letter_grade'] ?? '';
                $school_count = $data['school_count'] ?? 0;
                if ($grade && $school_count > 0) {
                    return sprintf(
                        '%s Public Schools | Grade %s | %d Schools',
                        $name,
                        $grade,
                        $school_count
                    );
                } elseif ($grade) {
                    return sprintf('%s Public Schools | Grade %s', $name, $grade);
                }
                return sprintf('%s Public Schools | District Information', $name);

            case 'school_detail':
                $grade = $data['letter_grade'] ?? '';
                $level = ucfirst(strtolower($data['level'] ?? 'School'));
                $city = $data['city'] ?? '';
                if ($grade && $city) {
                    return sprintf(
                        '%s | Grade %s %s School | %s, MA',
                        $name,
                        $grade,
                        $level,
                        $city
                    );
                } elseif ($grade) {
                    return sprintf('%s | Grade %s %s School', $name, $grade, $level);
                }
                return sprintf('%s | %s School in Massachusetts', $name, $level);

            case 'schools_guide':
                $total_districts = $data['total_districts'] ?? 342;
                return sprintf(
                    'Best School Districts to Buy a Home in MA (2026) | %d Districts Ranked',
                    $total_districts
                );

            default:
                return sprintf('%s Real Estate', $name);
        }
    }

    /**
     * Get meta description based on page type and data
     *
     * @return string
     */
    public static function get_meta_description() {
        $data = self::$page_data;
        $type = self::$page_type;

        if (empty($data['name'])) {
            return '';
        }

        $name = $data['name'];
        $state = $data['state'] ?? 'MA';
        $count = $data['listing_count'] ?? 0;
        $median_price = $data['median_price'] ?? 0;
        $avg_dom = $data['avg_dom'] ?? 0;
        $listing_type = $data['listing_type'] ?? 'sale';
        $is_rental = ($listing_type === 'lease');

        // Build dynamic description based on available data
        $parts = array();

        switch ($type) {
            case 'neighborhood':
                if ($is_rental) {
                    if ($count > 0) {
                        $parts[] = sprintf('Browse %d apartments and homes for rent in %s, %s.', $count, $name, $state);
                    } else {
                        $parts[] = sprintf('Find your perfect rental in %s, %s.', $name, $state);
                    }

                    if ($median_price > 0) {
                        $parts[] = sprintf('Median rent: %s/month.', '$' . number_format($median_price));
                    }

                    if ($avg_dom > 0) {
                        $parts[] = sprintf('Average days on market: %d.', $avg_dom);
                    }

                    $parts[] = 'View photos, amenities, and schedule a tour.';
                } else {
                    if ($count > 0) {
                        $parts[] = sprintf('Browse %d homes for sale in %s, %s.', $count, $name, $state);
                    } else {
                        $parts[] = sprintf('Find your perfect home in %s, %s.', $name, $state);
                    }

                    if ($median_price > 0) {
                        $parts[] = sprintf('Median listing price: %s.', '$' . number_format($median_price));
                    }

                    if ($avg_dom > 0) {
                        $parts[] = sprintf('Average days on market: %d.', $avg_dom);
                    }

                    $parts[] = 'View property photos, floor plans, and neighborhood details.';
                }
                break;

            case 'school_district':
                if ($count > 0) {
                    $parts[] = sprintf('Discover %d properties near %s.', $count, $name);
                } else {
                    $parts[] = sprintf('Find homes near %s schools.', $name);
                }

                $parts[] = 'School ratings, district boundaries, and homes for sale.';

                if ($median_price > 0) {
                    $parts[] = sprintf('Median home price: %s.', '$' . number_format($median_price));
                }
                break;

            case 'city':
                if ($is_rental) {
                    if ($count > 0) {
                        $parts[] = sprintf('Search %d apartments and homes for rent in %s, %s.', $count, $name, $state);
                    } else {
                        $parts[] = sprintf('Explore rental listings in %s, %s.', $name, $state);
                    }

                    if ($median_price > 0) {
                        $parts[] = sprintf('Median rent: %s/month.', '$' . number_format($median_price));
                    }

                    $parts[] = 'View photos, amenities, and pet policies.';
                } else {
                    if ($count > 0) {
                        $parts[] = sprintf('Search %d homes for sale in %s, %s.', $count, $name, $state);
                    } else {
                        $parts[] = sprintf('Explore real estate listings in %s, %s.', $name, $state);
                    }

                    if ($median_price > 0) {
                        $parts[] = sprintf('Median price: %s.', '$' . number_format($median_price));
                    }

                    $parts[] = 'View photos, neighborhood info, and market trends.';
                }
                break;

            case 'schools_browse':
                $parts[] = 'Browse and compare 342 Massachusetts school districts.';
                $parts[] = 'Filter by grade rating, MCAS scores, and location.';
                $parts[] = 'Find the best schools for your family.';
                break;

            case 'school_district_detail':
                $grade = $data['letter_grade'] ?? '';
                $school_count = $data['school_count'] ?? 0;
                $state_rank = $data['state_rank'] ?? 0;
                $composite_score = $data['composite_score'] ?? 0;

                if ($grade && $state_rank > 0) {
                    $parts[] = sprintf('%s is a Grade %s district ranked #%d in Massachusetts.', $name, $grade, $state_rank);
                } elseif ($grade) {
                    $parts[] = sprintf('%s is a Grade %s school district in Massachusetts.', $name, $grade);
                } else {
                    $parts[] = sprintf('Explore %s school district in Massachusetts.', $name);
                }

                if ($school_count > 0) {
                    $parts[] = sprintf('View %d schools with MCAS scores and college outcomes.', $school_count);
                }

                if ($count > 0) {
                    $parts[] = sprintf('%d homes for sale in district.', $count);
                }
                break;

            case 'school_detail':
                $grade = $data['letter_grade'] ?? '';
                $level = ucfirst(strtolower($data['level'] ?? 'school'));
                $city = $data['city'] ?? '';
                $state_rank = $data['state_rank'] ?? 0;
                $district = $data['district']['name'] ?? '';

                if ($grade && $state_rank > 0) {
                    $parts[] = sprintf('%s is a Grade %s %s school ranked #%d in Massachusetts.', $name, $grade, $level, $state_rank);
                } elseif ($grade) {
                    $parts[] = sprintf('%s is a Grade %s %s school in %s, MA.', $name, $grade, $level, $city);
                } else {
                    $parts[] = sprintf('%s is a %s school in %s, Massachusetts.', $name, $level, $city);
                }

                $parts[] = 'View MCAS test scores, demographics, and school performance data.';

                if ($district) {
                    $parts[] = sprintf('Part of %s.', $district);
                }
                break;

            case 'schools_guide':
                $total_districts = $data['total_districts'] ?? 342;
                $parts[] = sprintf('Compare %d Massachusetts school districts ranked by MCAS scores.', $total_districts);
                $parts[] = 'Find family homes in A-rated zones from $500K.';
                $parts[] = 'Unique school grade filter for buyers.';
                break;
        }

        $description = implode(' ', $parts);

        // Truncate to ~155 characters for SEO
        if (strlen($description) > 155) {
            $description = substr($description, 0, 152) . '...';
        }

        return $description;
    }

    /**
     * Get canonical URL for current page
     *
     * @return string
     */
    public static function get_canonical_url() {
        $data = self::$page_data;
        $type = self::$page_type;

        if (!empty($data['url'])) {
            return $data['url'];
        }

        if (empty($data['name'])) {
            return home_url('/');
        }

        $slug = sanitize_title($data['name']);

        switch ($type) {
            case 'neighborhood':
                return home_url('/real-estate/' . $slug . '/');

            case 'school_district':
                return home_url('/schools/' . $slug . '/real-estate/');

            case 'city':
                $state = strtolower($data['state'] ?? 'ma');
                return home_url('/homes-for-sale-in-' . $slug . '-' . $state . '/');

            case 'schools_browse':
                return home_url('/schools/');

            case 'school_district_detail':
                return home_url('/schools/' . $slug . '/');

            case 'school_detail':
                $district_slug = sanitize_title($data['district']['name'] ?? '');
                return home_url('/schools/' . $district_slug . '/' . $slug . '/');

            default:
                return home_url('/real-estate/' . $slug . '/');
        }
    }

    /**
     * Get full state name from abbreviation
     *
     * @param string $abbr State abbreviation
     * @return string Full state name
     */
    private static function get_state_name($abbr) {
        $states = array(
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
            'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
            'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
            'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
            'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
            'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
            'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
            'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
        );

        $abbr = strtoupper($abbr);
        return isset($states[$abbr]) ? $states[$abbr] : $abbr;
    }

    /**
     * Generate FAQ schema for common questions about a location
     *
     * @param array $faqs Array of FAQ items with 'question' and 'answer' keys
     * @return array Schema array
     */
    public static function get_faq_schema($faqs) {
        if (empty($faqs)) {
            return array();
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array(),
        );

        foreach ($faqs as $faq) {
            if (!empty($faq['question']) && !empty($faq['answer'])) {
                $schema['mainEntity'][] = array(
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text' => $faq['answer'],
                    ),
                );
            }
        }

        return $schema;
    }

    /**
     * Generate dynamic FAQs for a location
     *
     * @return array Array of FAQ items
     */
    public static function generate_location_faqs() {
        $data = self::$page_data;
        $type = self::$page_type;

        if (empty($data['name'])) {
            return array();
        }

        $name = $data['name'];
        $state = $data['state'] ?? 'MA';
        $count = $data['listing_count'] ?? 0;
        $median_price = $data['median_price'] ?? 0;
        $avg_dom = $data['avg_dom'] ?? 0;
        $listing_type = $data['listing_type'] ?? 'sale';
        $is_rental = ($listing_type === 'lease');

        $faqs = array();

        if ($is_rental) {
            // Rental-specific FAQs
            // Q1: How many rentals are available?
            if ($count > 0) {
                $faqs[] = array(
                    'question' => sprintf('How many apartments are for rent in %s?', $name),
                    'answer' => sprintf(
                        'There are currently %d apartments and homes for rent in %s, %s. Our listings are updated daily with the latest available rentals.',
                        $count,
                        $name,
                        $state
                    ),
                );
            }

            // Q2: What is the average rent?
            if ($median_price > 0) {
                $faqs[] = array(
                    'question' => sprintf('What is the average rent in %s?', $name),
                    'answer' => sprintf(
                        'The median monthly rent in %s is %s. Rental prices vary based on unit size, amenities, and specific location within the area.',
                        $name,
                        '$' . number_format($median_price)
                    ),
                );
            }

            // Q3: How quickly do rentals get leased?
            if ($avg_dom > 0) {
                $faqs[] = array(
                    'question' => sprintf('How quickly do rentals get leased in %s?', $name),
                    'answer' => sprintf(
                        'Rentals in %s typically get leased within %d days on average. Popular units may rent faster, so scheduling a viewing promptly is recommended.',
                        $name,
                        $avg_dom
                    ),
                );
            }

            // Q4: Generic rental question
            $faqs[] = array(
                'question' => sprintf('Is %s a good place to rent?', $name),
                'answer' => sprintf(
                    '%s offers a variety of rental options in %s including apartments, condos, and houses. Contact our team for help finding a rental that fits your needs and budget.',
                    $name,
                    $state
                ),
            );
        } else {
            // Sales-specific FAQs
            // Q1: How many homes are for sale?
            if ($count > 0) {
                $faqs[] = array(
                    'question' => sprintf('How many homes are for sale in %s?', $name),
                    'answer' => sprintf(
                        'There are currently %d homes for sale in %s, %s. Our listings are updated daily with the latest properties from the MLS.',
                        $count,
                        $name,
                        $state
                    ),
                );
            }

            // Q2: What is the median home price?
            if ($median_price > 0) {
                $faqs[] = array(
                    'question' => sprintf('What is the median home price in %s?', $name),
                    'answer' => sprintf(
                        'The median listing price for homes in %s is %s. Home prices can vary based on size, condition, and specific location within the area.',
                        $name,
                        '$' . number_format($median_price)
                    ),
                );
            }

            // Q3: How long do homes stay on the market?
            if ($avg_dom > 0) {
                $faqs[] = array(
                    'question' => sprintf('How long do homes stay on the market in %s?', $name),
                    'answer' => sprintf(
                        'Homes in %s typically sell within %d days on average. Market conditions can affect how quickly homes sell.',
                        $name,
                        $avg_dom
                    ),
                );
            }

            // Q4: Generic question about the area
            $faqs[] = array(
                'question' => sprintf('Is %s a good place to buy a home?', $name),
                'answer' => sprintf(
                    '%s offers a variety of housing options in %s. Contact our team for personalized recommendations based on your needs and budget.',
                    $name,
                    $state
                ),
            );
        }

        return $faqs;
    }

    /**
     * Get EducationalOrganization schema for school district pages
     *
     * @return array
     */
    private static function get_educational_organization_schema() {
        $data = self::$page_data;

        if (empty($data['name'])) {
            return array();
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'EducationalOrganization',
            'name' => $data['name'],
            'url' => self::get_canonical_url(),
        );

        // Add address
        if (!empty($data['city'])) {
            $schema['address'] = array(
                '@type' => 'PostalAddress',
                'addressLocality' => $data['city'],
                'addressRegion' => 'MA',
                'addressCountry' => 'US',
            );
        }

        // Add number of schools
        if (!empty($data['school_count'])) {
            $schema['numberOfEmployees'] = array(
                '@type' => 'QuantitativeValue',
                'value' => intval($data['school_count']),
                'unitText' => 'schools',
            );
        }

        // Add aggregate rating based on composite score
        if (!empty($data['composite_score'])) {
            $score = floatval($data['composite_score']);
            // Convert 0-100 score to 1-5 rating
            $rating = round(($score / 100) * 4 + 1, 1);
            $schema['aggregateRating'] = array(
                '@type' => 'AggregateRating',
                'ratingValue' => $rating,
                'bestRating' => 5,
                'worstRating' => 1,
                'ratingCount' => 1,
            );
        }

        // Add description
        if (!empty($data['letter_grade'])) {
            $schema['description'] = sprintf(
                '%s is a Grade %s school district in Massachusetts with %d schools.',
                $data['name'],
                $data['letter_grade'],
                $data['schools_count'] ?? $data['school_count'] ?? 0
            );
        }

        // Add college outcomes as alumni credential
        if (!empty($data['college_outcomes'])) {
            $outcomes = $data['college_outcomes'];
            $four_year = $outcomes['four_year_pct'] ?? 0;
            $two_year = $outcomes['two_year_pct'] ?? 0;
            $total_college = $four_year + $two_year;

            if ($total_college > 0) {
                $schema['alumni'] = array(
                    '@type' => 'EducationalOccupationalCredential',
                    'description' => sprintf(
                        '%d%% of graduates attend college (%d%% 4-year, %d%% 2-year)',
                        $total_college,
                        $four_year,
                        $two_year
                    ),
                );
            }
        }

        // Add per-pupil spending as budget
        if (!empty($data['expenditure_per_pupil'])) {
            $schema['budget'] = array(
                '@type' => 'MonetaryAmount',
                'currency' => 'USD',
                'value' => intval($data['expenditure_per_pupil']),
                'description' => 'Per-pupil spending',
            );
        }

        // Add telephone if available
        if (!empty($data['phone'])) {
            $schema['telephone'] = $data['phone'];
        }

        // Add website
        if (!empty($data['website'])) {
            $schema['sameAs'] = $data['website'];
        }

        // Add dateModified for data freshness signal
        if (!empty($data['data_freshness'])) {
            $schema['dateModified'] = wp_date('c', strtotime($data['data_freshness']));
        } elseif (!empty($data['ranking_year'])) {
            $schema['dateModified'] = $data['ranking_year'] . '-09-01T00:00:00-04:00';
        }

        return $schema;
    }

    /**
     * Get School schema for individual school pages
     *
     * @return array
     */
    private static function get_school_schema() {
        $data = self::$page_data;

        if (empty($data['name'])) {
            return array();
        }

        // Determine school type for Schema.org
        $level = strtolower($data['level'] ?? '');
        $school_type = 'School';
        if (strpos($level, 'high') !== false) {
            $school_type = 'HighSchool';
        } elseif (strpos($level, 'middle') !== false) {
            $school_type = 'MiddleSchool';
        } elseif (strpos($level, 'elementary') !== false) {
            $school_type = 'ElementarySchool';
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => $school_type,
            'name' => $data['name'],
            'url' => self::get_canonical_url(),
        );

        // Add address
        if (!empty($data['address']) || !empty($data['city'])) {
            $schema['address'] = array(
                '@type' => 'PostalAddress',
                'streetAddress' => $data['address'] ?? '',
                'addressLocality' => $data['city'] ?? '',
                'addressRegion' => 'MA',
                'addressCountry' => 'US',
            );
        }

        // Add geo coordinates
        if (!empty($data['latitude']) && !empty($data['longitude'])) {
            $schema['geo'] = array(
                '@type' => 'GeoCoordinates',
                'latitude' => floatval($data['latitude']),
                'longitude' => floatval($data['longitude']),
            );
        }

        // Add aggregate rating based on composite score
        if (!empty($data['composite_score'])) {
            $score = floatval($data['composite_score']);
            // Convert 0-100 score to 1-5 rating
            $rating = round(($score / 100) * 4 + 1, 1);
            $schema['aggregateRating'] = array(
                '@type' => 'AggregateRating',
                'ratingValue' => $rating,
                'bestRating' => 5,
                'worstRating' => 1,
                'ratingCount' => 1,
            );
        }

        // Add parent organization (district)
        if (!empty($data['district']['name'])) {
            $schema['parentOrganization'] = array(
                '@type' => 'EducationalOrganization',
                'name' => $data['district']['name'],
                'url' => $data['district']['url'] ?? '',
            );
        }

        // Add description
        $grade = $data['letter_grade'] ?? '';
        $city = $data['city'] ?? '';
        if ($grade && $city) {
            $schema['description'] = sprintf(
                '%s is a Grade %s %s school in %s, Massachusetts.',
                $data['name'],
                $grade,
                ucfirst($level),
                $city
            );
        }

        // Add number of students (check both enrollment and demographics)
        if (!empty($data['enrollment'])) {
            $schema['numberOfStudents'] = intval($data['enrollment']);
        } elseif (!empty($data['demographics']['total_students'])) {
            $schema['numberOfStudents'] = intval($data['demographics']['total_students']);
        }

        // Add telephone if available
        if (!empty($data['phone'])) {
            $schema['telephone'] = $data['phone'];
        }

        // Add sports programs for high schools (SEO rich results)
        if (!empty($data['sports']['list']) && is_array($data['sports']['list'])) {
            $sport_names = array();
            foreach ($data['sports']['list'] as $sport) {
                if (!empty($sport->sport)) {
                    $sport_names[] = $sport->sport;
                }
            }
            $sport_names = array_unique($sport_names);
            if (!empty($sport_names)) {
                $schema['sport'] = array_values($sport_names);
            }
        }

        // Add AP courses as educational program catalog
        if (!empty($data['features']['ap_summary']['ap_courses_offered'])) {
            $ap_count = intval($data['features']['ap_summary']['ap_courses_offered']);
            if ($ap_count > 0) {
                $schema['hasOfferCatalog'] = array(
                    '@type' => 'OfferCatalog',
                    'name' => 'Advanced Placement (AP) Courses',
                    'numberOfItems' => $ap_count,
                );
            }
        }

        // Add dateModified for data freshness signal
        if (!empty($data['data_freshness'])) {
            $schema['dateModified'] = wp_date('c', strtotime($data['data_freshness']));
        } elseif (!empty($data['ranking_year'])) {
            // Use ranking year as approximate date
            $schema['dateModified'] = $data['ranking_year'] . '-09-01T00:00:00-04:00';
        }

        return $schema;
    }

    /**
     * Get Article schema for schools buying guide
     *
     * @return array
     */
    private static function get_schools_guide_article_schema() {
        $data = self::$page_data;

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => 'The Ultimate Guide to Buying a Home in Massachusetts\' Top School Districts',
            'description' => 'Complete guide to buying a home in Massachusetts\' top school districts with rankings, pricing, and expert advice.',
            'author' => array(
                '@type' => 'Organization',
                'name' => 'BMN Boston Team',
                'url' => home_url('/'),
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => 'BMN Boston',
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => get_stylesheet_directory_uri() . '/assets/images/logo.png',
                ),
            ),
            'datePublished' => '2026-01-01',
            'dateModified' => $data['data_freshness'] ?? wp_date('Y-m-d'),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id' => home_url('/schools-guide/'),
            ),
        );

        return $schema;
    }

    /**
     * Get ItemList schema for school district rankings
     *
     * @return array
     */
    private static function get_schools_guide_itemlist_schema() {
        $data = self::$page_data;

        // Get top districts from page data if available
        $districts = $data['top_districts'] ?? array();

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Top 40 Massachusetts School Districts 2026',
            'description' => 'Massachusetts school districts ranked by composite score based on MCAS proficiency, graduation rates, and 6 other factors.',
            'numberOfItems' => 40,
            'itemListElement' => array(),
        );

        // Add list items if districts data is available
        if (!empty($districts)) {
            $position = 1;
            foreach ($districts as $district) {
                $district_name = is_object($district) ? $district->district_name : ($district['district_name'] ?? '');
                if (!empty($district_name)) {
                    $schema['itemListElement'][] = array(
                        '@type' => 'ListItem',
                        'position' => $position,
                        'item' => array(
                            '@type' => 'EducationalOrganization',
                            'name' => $district_name,
                            'url' => home_url('/schools/' . sanitize_title($district_name) . '/'),
                        ),
                    );
                    $position++;
                    if ($position > 40) break;
                }
            }
        }

        return $schema;
    }

    /**
     * Get HowTo schema for buying process
     *
     * @return array
     */
    private static function get_schools_guide_howto_schema() {
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            'name' => 'How to Buy a Home in a Top Massachusetts School District',
            'description' => 'Step-by-step guide for families buying homes in high-performing school districts in Massachusetts.',
            'step' => array(
                array(
                    '@type' => 'HowToStep',
                    'position' => 1,
                    'name' => 'Research School Districts',
                    'text' => 'Compare district grades, MCAS scores, and rankings using our data-driven tools. Focus on districts rated A or A+ for best academic outcomes.',
                ),
                array(
                    '@type' => 'HowToStep',
                    'position' => 2,
                    'name' => 'Set Your Budget',
                    'text' => 'Premium A+ districts like Newton start at $790K for family homes. Value-oriented A+ districts like Natick offer entry points from $630K. Budget-friendly A-rated options start around $500K.',
                ),
                array(
                    '@type' => 'HowToStep',
                    'position' => 3,
                    'name' => 'Search by School Grade',
                    'text' => 'Use BMN Boston\'s unique school grade filter to find homes in A-rated districts - something you can\'t do on Zillow or Redfin.',
                ),
                array(
                    '@type' => 'HowToStep',
                    'position' => 4,
                    'name' => 'Connect with Local Experts',
                    'text' => 'Work with agents who know school district dynamics, boundary nuances, and competitive markets. Local expertise matters in bidding wars.',
                ),
            ),
        );

        return $schema;
    }

    /**
     * Get FAQPage schema for schools buying guide
     *
     * @return array
     */
    private static function get_schools_guide_faq_schema() {
        $data = self::$page_data;
        $total_districts = $data['total_districts'] ?? 342;

        $faqs = array(
            array(
                'question' => 'What are the best school districts to buy a home in Massachusetts?',
                'answer' => 'Based on our 2026 analysis of ' . $total_districts . ' districts, the top-rated districts include Lexington, Needham, Brookline, Newton, and Arlington. These A+ districts have composite scores above 65 based on MCAS proficiency, graduation rates, and college outcomes.',
            ),
            array(
                'question' => 'How much do homes cost in top Massachusetts school districts?',
                'answer' => 'Family homes (3+ bedrooms) in premium A+ districts like Newton start around $790K, while value-oriented A+ districts like Natick offer entry points from $630K. Budget-friendly A-rated options like Canton and Easton have family homes starting around $500K.',
            ),
            array(
                'question' => 'Can I search for homes by school district grade?',
                'answer' => 'Yes, BMN Boston is the only real estate platform that lets you filter properties by school district grade. You can search for all homes in A-rated, B-rated, or C-rated districts directly from our property search - something not possible on Zillow or Redfin.',
            ),
            array(
                'question' => 'What is the cheapest A-rated school district in Massachusetts?',
                'answer' => 'Canton offers the lowest entry point for A-rated schools with family homes starting at $499,900. Other affordable A-rated options include Easton ($520K), Chelmsford ($529K), and Framingham ($530K).',
            ),
            array(
                'question' => 'How do Massachusetts school district grades work?',
                'answer' => 'BMN Boston calculates school grades using a composite score based on 8 factors: MCAS proficiency (40-45%), graduation rates (12%), MCAS growth (10-15%), AP performance (9%), attendance (8-20%), student-teacher ratio (5-8%), and per-pupil spending (4-12%). Schools are graded A+ through F based on their percentile rank among all Massachusetts schools.',
            ),
        );

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array(),
        );

        foreach ($faqs as $faq) {
            $schema['mainEntity'][] = array(
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ),
            );
        }

        return $schema;
    }
}
