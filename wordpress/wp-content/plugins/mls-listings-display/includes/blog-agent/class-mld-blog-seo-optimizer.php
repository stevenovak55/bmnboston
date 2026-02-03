<?php
/**
 * Blog SEO Optimizer
 *
 * Analyzes and optimizes blog content for SEO and GEO (local) search.
 * Provides scoring, recommendations, and Schema.org markup generation.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_SEO_Optimizer
 *
 * SEO and GEO optimization for blog articles.
 */
class MLD_Blog_SEO_Optimizer {

    /**
     * SEO scoring criteria with max points
     *
     * @var array
     */
    private $seo_criteria = array(
        'title_length' => array('max' => 10, 'min_chars' => 50, 'max_chars' => 60),
        'meta_description' => array('max' => 10, 'min_chars' => 140, 'max_chars' => 155),
        'h1_present' => array('max' => 5),
        'h2_count' => array('max' => 5, 'min' => 3, 'target' => 8),
        'word_count' => array('max' => 10, 'min' => 1200, 'target' => 2500),
        'keyword_density' => array('max' => 10, 'min' => 0.5, 'target' => 2.5),
        'internal_links' => array('max' => 15, 'min' => 3, 'target' => 7),
        'external_links' => array('max' => 10, 'min' => 2, 'target' => 5),
        'images' => array('max' => 15, 'min' => 2, 'target' => 6),
        'schema_markup' => array('max' => 10),
    );

    /**
     * GEO scoring criteria with max points
     *
     * @var array
     */
    private $geo_criteria = array(
        'boston_mentions' => array('max' => 15, 'min' => 3),
        'neighborhood_mentions' => array('max' => 10, 'min' => 2),
        'state_mentions' => array('max' => 5, 'min' => 1),
        'school_references' => array('max' => 10),
        'market_data' => array('max' => 20),
        'price_data' => array('max' => 15),
        'local_business_schema' => array('max' => 10),
        'nap_in_content' => array('max' => 10),
        'local_landmarks' => array('max' => 5),
    );

    /**
     * Boston area neighborhoods for GEO detection
     *
     * @var array
     */
    private $boston_neighborhoods = array(
        'Back Bay', 'Beacon Hill', 'South End', 'North End', 'Seaport',
        'Charlestown', 'Jamaica Plain', 'Dorchester', 'Roxbury', 'Allston',
        'Brighton', 'West Roxbury', 'Hyde Park', 'Mattapan', 'Roslindale',
        'South Boston', 'East Boston', 'Fenway', 'Mission Hill', 'Downtown',
    );

    /**
     * Greater Boston cities
     *
     * @var array
     */
    private $boston_area_cities = array(
        'Boston', 'Cambridge', 'Somerville', 'Brookline', 'Newton',
        'Quincy', 'Medford', 'Malden', 'Everett', 'Chelsea',
        'Revere', 'Waltham', 'Watertown', 'Arlington', 'Belmont',
    );

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Analyze and score article
     *
     * @param array $article Article data
     * @return array Analysis results
     */
    public function analyze($article) {
        $title = $article['title'] ?? '';
        $content = $article['content'] ?? '';
        $meta_description = $article['meta_description'] ?? '';
        $primary_keyword = $article['primary_keyword'] ?? '';

        // Run SEO analysis
        $seo_analysis = $this->analyze_seo($title, $content, $meta_description, $primary_keyword);

        // Run GEO analysis
        $geo_analysis = $this->analyze_geo($content);

        // Calculate overall scores
        $seo_score = $this->calculate_score($seo_analysis, $this->seo_criteria);
        $geo_score = $this->calculate_score($geo_analysis, $this->geo_criteria);

        // Generate recommendations
        $recommendations = $this->generate_recommendations($seo_analysis, $geo_analysis);

        return array(
            'seo_score' => $seo_score,
            'geo_score' => $geo_score,
            'seo_analysis' => $seo_analysis,
            'geo_analysis' => $geo_analysis,
            'recommendations' => $recommendations,
            'schema' => $this->generate_article_schema($article),
        );
    }

    /**
     * Analyze SEO factors
     *
     * @param string $title Article title
     * @param string $content Article content
     * @param string $meta_description Meta description
     * @param string $primary_keyword Primary keyword
     * @return array SEO analysis
     */
    private function analyze_seo($title, $content, $meta_description, $primary_keyword) {
        $plain_content = strip_tags($content);
        $word_count = str_word_count($plain_content);

        $analysis = array();

        // Title length
        $title_len = strlen($title);
        $analysis['title_length'] = array(
            'value' => $title_len,
            'status' => ($title_len >= 50 && $title_len <= 60) ? 'good' : (($title_len >= 40 && $title_len <= 70) ? 'ok' : 'poor'),
            'message' => "Title is $title_len characters (target: 50-60)",
        );

        // Meta description
        $meta_len = strlen($meta_description);
        $analysis['meta_description'] = array(
            'value' => $meta_len,
            'status' => ($meta_len >= 140 && $meta_len <= 155) ? 'good' : (($meta_len >= 120 && $meta_len <= 160) ? 'ok' : 'poor'),
            'message' => "Meta description is $meta_len characters (target: 140-155)",
        );

        // H1 present
        $h1_count = preg_match_all('/<h1[^>]*>/i', $content);
        $analysis['h1_present'] = array(
            'value' => $h1_count,
            'status' => $h1_count === 1 ? 'good' : ($h1_count === 0 ? 'poor' : 'ok'),
            'message' => $h1_count === 1 ? 'H1 tag present' : ($h1_count === 0 ? 'No H1 tag found' : "Multiple H1 tags ($h1_count)"),
        );

        // H2 count
        $h2_count = preg_match_all('/<h2[^>]*>/i', $content);
        $analysis['h2_count'] = array(
            'value' => $h2_count,
            'status' => ($h2_count >= 3 && $h2_count <= 8) ? 'good' : (($h2_count >= 2 && $h2_count <= 10) ? 'ok' : 'poor'),
            'message' => "$h2_count H2 headings (target: 3-8)",
        );

        // Word count
        $analysis['word_count'] = array(
            'value' => $word_count,
            'status' => ($word_count >= 1200 && $word_count <= 2500) ? 'good' : (($word_count >= 800 && $word_count <= 3000) ? 'ok' : 'poor'),
            'message' => "$word_count words (target: 1,200-2,500)",
        );

        // Keyword density
        if (!empty($primary_keyword)) {
            $keyword_count = substr_count(strtolower($plain_content), strtolower($primary_keyword));
            $density = ($word_count > 0) ? ($keyword_count / $word_count) * 100 : 0;
            $density = round($density, 2);

            $analysis['keyword_density'] = array(
                'value' => $density,
                'keyword_count' => $keyword_count,
                'status' => ($density >= 0.5 && $density <= 2.5) ? 'good' : (($density >= 0.3 && $density <= 3.5) ? 'ok' : 'poor'),
                'message' => "Keyword density: {$density}% ($keyword_count occurrences)",
            );
        } else {
            $analysis['keyword_density'] = array(
                'value' => 0,
                'status' => 'poor',
                'message' => 'No primary keyword specified',
            );
        }

        // Internal links
        preg_match_all('/<a[^>]+href=["\']([^"\']*bmnboston\.com[^"\']*|\/[^"\']*)["\'][^>]*>/i', $content, $internal_matches);
        $internal_count = count($internal_matches[0]);
        $analysis['internal_links'] = array(
            'value' => $internal_count,
            'status' => ($internal_count >= 3 && $internal_count <= 7) ? 'good' : (($internal_count >= 2 && $internal_count <= 10) ? 'ok' : 'poor'),
            'message' => "$internal_count internal links (target: 3-7)",
        );

        // External links
        preg_match_all('/<a[^>]+href=["\']https?:\/\/(?!bmnboston\.com)[^"\']+["\'][^>]*>/i', $content, $external_matches);
        $external_count = count($external_matches[0]);
        $analysis['external_links'] = array(
            'value' => $external_count,
            'status' => ($external_count >= 2 && $external_count <= 5) ? 'good' : (($external_count >= 1 && $external_count <= 7) ? 'ok' : 'poor'),
            'message' => "$external_count external links (target: 2-5)",
        );

        // Images
        preg_match_all('/<img[^>]+>/i', $content, $img_matches);
        $img_count = count($img_matches[0]);

        // Check for alt text
        $imgs_with_alt = 0;
        foreach ($img_matches[0] as $img) {
            if (preg_match('/alt=["\'][^"\']+["\']/', $img)) {
                $imgs_with_alt++;
            }
        }

        $analysis['images'] = array(
            'value' => $img_count,
            'with_alt' => $imgs_with_alt,
            'status' => ($img_count >= 2 && $img_count <= 6 && $imgs_with_alt === $img_count) ? 'good' :
                       (($img_count >= 1 && $img_count <= 8) ? 'ok' : 'poor'),
            'message' => "$img_count images ($imgs_with_alt with alt text)",
        );

        // Schema markup (check if Article schema present)
        $has_schema = strpos($content, 'application/ld+json') !== false ||
                      strpos($content, '@type') !== false;
        $analysis['schema_markup'] = array(
            'value' => $has_schema ? 1 : 0,
            'status' => $has_schema ? 'good' : 'poor',
            'message' => $has_schema ? 'Schema markup detected' : 'No schema markup found',
        );

        return $analysis;
    }

    /**
     * Analyze GEO (local SEO) factors
     *
     * @param string $content Article content
     * @return array GEO analysis
     */
    private function analyze_geo($content) {
        $plain_content = strip_tags($content);
        $lower_content = strtolower($plain_content);

        $analysis = array();

        // Boston mentions
        $boston_count = substr_count($lower_content, 'boston');
        $analysis['boston_mentions'] = array(
            'value' => $boston_count,
            'status' => $boston_count >= 3 ? 'good' : ($boston_count >= 1 ? 'ok' : 'poor'),
            'message' => "$boston_count Boston mentions (target: 3+)",
        );

        // Neighborhood mentions
        $neighborhood_count = 0;
        $found_neighborhoods = array();
        foreach ($this->boston_neighborhoods as $neighborhood) {
            if (stripos($plain_content, $neighborhood) !== false) {
                $neighborhood_count++;
                $found_neighborhoods[] = $neighborhood;
            }
        }

        // Also check Greater Boston cities
        foreach ($this->boston_area_cities as $city) {
            if ($city !== 'Boston' && stripos($plain_content, $city) !== false) {
                $neighborhood_count++;
                $found_neighborhoods[] = $city;
            }
        }

        $analysis['neighborhood_mentions'] = array(
            'value' => $neighborhood_count,
            'found' => $found_neighborhoods,
            'status' => $neighborhood_count >= 2 ? 'good' : ($neighborhood_count >= 1 ? 'ok' : 'poor'),
            'message' => "$neighborhood_count neighborhood/city mentions",
        );

        // State mentions
        $ma_count = substr_count($lower_content, 'massachusetts') +
                   preg_match_all('/\bma\b/i', $plain_content);
        $analysis['state_mentions'] = array(
            'value' => $ma_count,
            'status' => $ma_count >= 1 ? 'good' : 'poor',
            'message' => "$ma_count Massachusetts/MA mentions",
        );

        // School references
        $school_keywords = array('school', 'schools', 'education', 'district', 'elementary', 'high school', 'middle school');
        $school_count = 0;
        foreach ($school_keywords as $keyword) {
            $school_count += substr_count($lower_content, $keyword);
        }
        $analysis['school_references'] = array(
            'value' => $school_count,
            'status' => $school_count >= 2 ? 'good' : ($school_count >= 1 ? 'ok' : 'poor'),
            'message' => "$school_count school-related mentions",
        );

        // Market data (prices, percentages, statistics)
        $market_patterns = array(
            '/\$[\d,]+/' => 'price',
            '/\d+%/' => 'percentage',
            '/median|average|inventory|days on market/i' => 'statistic',
        );
        $market_data_count = 0;
        foreach ($market_patterns as $pattern => $type) {
            $market_data_count += preg_match_all($pattern, $plain_content);
        }
        $analysis['market_data'] = array(
            'value' => $market_data_count,
            'status' => $market_data_count >= 5 ? 'good' : ($market_data_count >= 2 ? 'ok' : 'poor'),
            'message' => "$market_data_count market data points",
        );

        // Price data specifically
        preg_match_all('/\$[\d,]+/', $plain_content, $price_matches);
        $price_count = count($price_matches[0]);
        $analysis['price_data'] = array(
            'value' => $price_count,
            'status' => $price_count >= 3 ? 'good' : ($price_count >= 1 ? 'ok' : 'poor'),
            'message' => "$price_count price references",
        );

        // NAP (Name, Address, Phone) check
        $has_business_name = stripos($plain_content, 'bmnboston') !== false ||
                            stripos($plain_content, 'steve novak') !== false;
        $has_phone = preg_match('/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $plain_content);
        $has_address = preg_match('/\d+\s+[\w\s]+(?:street|st|avenue|ave|road|rd|drive|dr)/i', $plain_content);

        $nap_count = ($has_business_name ? 1 : 0) + ($has_phone ? 1 : 0) + ($has_address ? 1 : 0);
        $analysis['nap_in_content'] = array(
            'value' => $nap_count,
            'has_name' => $has_business_name,
            'has_phone' => $has_phone,
            'has_address' => $has_address,
            'status' => $nap_count >= 2 ? 'good' : ($nap_count >= 1 ? 'ok' : 'poor'),
            'message' => "NAP elements: $nap_count/3 present",
        );

        // Local landmarks
        $landmarks = array(
            'fenway', 'td garden', 'freedom trail', 'charles river',
            'boston common', 'public garden', 'harvard', 'mit',
            'quincy market', 'faneuil hall',
        );
        $landmark_count = 0;
        $found_landmarks = array();
        foreach ($landmarks as $landmark) {
            if (stripos($plain_content, $landmark) !== false) {
                $landmark_count++;
                $found_landmarks[] = $landmark;
            }
        }
        $analysis['local_landmarks'] = array(
            'value' => $landmark_count,
            'found' => $found_landmarks,
            'status' => $landmark_count >= 1 ? 'good' : 'ok',
            'message' => "$landmark_count local landmark references",
        );

        // Local business schema placeholder
        $analysis['local_business_schema'] = array(
            'value' => 1, // Will be added by generate_article_schema
            'status' => 'good',
            'message' => 'LocalBusiness schema will be included',
        );

        return $analysis;
    }

    /**
     * Calculate score from analysis
     *
     * @param array $analysis Analysis results
     * @param array $criteria Scoring criteria
     * @return float Score 0-100
     */
    private function calculate_score($analysis, $criteria) {
        $total_possible = 0;
        $total_earned = 0;

        foreach ($criteria as $key => $config) {
            $total_possible += $config['max'];

            if (!isset($analysis[$key])) {
                continue;
            }

            $status = $analysis[$key]['status'] ?? 'poor';

            switch ($status) {
                case 'good':
                    $total_earned += $config['max'];
                    break;
                case 'ok':
                    $total_earned += $config['max'] * 0.6;
                    break;
                case 'poor':
                default:
                    $total_earned += $config['max'] * 0.2;
                    break;
            }
        }

        return $total_possible > 0 ? round(($total_earned / $total_possible) * 100, 1) : 0;
    }

    /**
     * Generate improvement recommendations
     *
     * @param array $seo_analysis SEO analysis
     * @param array $geo_analysis GEO analysis
     * @return array Recommendations
     */
    private function generate_recommendations($seo_analysis, $geo_analysis) {
        $recommendations = array();

        // SEO recommendations
        foreach ($seo_analysis as $key => $data) {
            if ($data['status'] === 'poor') {
                $recommendations[] = array(
                    'type' => 'seo',
                    'severity' => 'high',
                    'category' => $key,
                    'issue' => $data['message'],
                    'recommendation' => $this->get_seo_recommendation($key, $data),
                );
            } elseif ($data['status'] === 'ok') {
                $recommendations[] = array(
                    'type' => 'seo',
                    'severity' => 'medium',
                    'category' => $key,
                    'issue' => $data['message'],
                    'recommendation' => $this->get_seo_recommendation($key, $data),
                );
            }
        }

        // GEO recommendations
        foreach ($geo_analysis as $key => $data) {
            if ($data['status'] === 'poor') {
                $recommendations[] = array(
                    'type' => 'geo',
                    'severity' => 'high',
                    'category' => $key,
                    'issue' => $data['message'],
                    'recommendation' => $this->get_geo_recommendation($key, $data),
                );
            } elseif ($data['status'] === 'ok') {
                $recommendations[] = array(
                    'type' => 'geo',
                    'severity' => 'medium',
                    'category' => $key,
                    'issue' => $data['message'],
                    'recommendation' => $this->get_geo_recommendation($key, $data),
                );
            }
        }

        // Sort by severity
        usort($recommendations, function($a, $b) {
            $priority = array('high' => 0, 'medium' => 1, 'low' => 2);
            return $priority[$a['severity']] <=> $priority[$b['severity']];
        });

        return $recommendations;
    }

    /**
     * Get SEO recommendation text
     *
     * @param string $key Analysis key
     * @param array $data Analysis data
     * @return string Recommendation
     */
    private function get_seo_recommendation($key, $data) {
        $recommendations = array(
            'title_length' => 'Adjust title to be between 50-60 characters for optimal search display.',
            'meta_description' => 'Write a compelling meta description between 140-155 characters.',
            'h1_present' => 'Ensure exactly one H1 tag is present at the beginning of your content.',
            'h2_count' => 'Add more H2 subheadings to improve content structure and readability.',
            'word_count' => 'Aim for 1,200-2,500 words to provide comprehensive coverage of the topic.',
            'keyword_density' => 'Include your primary keyword more naturally throughout the content.',
            'internal_links' => 'Add 3-7 internal links to other relevant pages on the site.',
            'external_links' => 'Include 2-5 links to authoritative external sources.',
            'images' => 'Add 2-6 relevant images with descriptive alt text.',
            'schema_markup' => 'Include Article schema markup for better search engine understanding.',
        );

        return $recommendations[$key] ?? 'Review and improve this element.';
    }

    /**
     * Get GEO recommendation text
     *
     * @param string $key Analysis key
     * @param array $data Analysis data
     * @return string Recommendation
     */
    private function get_geo_recommendation($key, $data) {
        $recommendations = array(
            'boston_mentions' => 'Mention "Boston" at least 3 times throughout the article naturally.',
            'neighborhood_mentions' => 'Reference specific Boston neighborhoods or Greater Boston cities.',
            'state_mentions' => 'Include at least one mention of "Massachusetts" or "MA".',
            'school_references' => 'Add references to local schools or school districts when relevant.',
            'market_data' => 'Include local market statistics like median prices, inventory levels, or DOM.',
            'price_data' => 'Add specific price points or ranges relevant to Boston real estate.',
            'nap_in_content' => 'Include business name, phone, or address for local SEO signals.',
            'local_landmarks' => 'Reference local landmarks or institutions for geographic relevance.',
        );

        return $recommendations[$key] ?? 'Improve local relevance for this element.';
    }

    /**
     * Generate Article schema markup
     *
     * @param array $article Article data
     * @return array Schema data
     */
    public function generate_article_schema($article) {
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $article['title'] ?? '',
            'description' => $article['meta_description'] ?? '',
            'author' => array(
                '@type' => 'Organization',
                'name' => 'BMN Boston',
                'url' => home_url('/'),
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => 'BMN Boston',
                'url' => home_url('/'),
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => home_url('/wp-content/uploads/logo.png'),
                ),
            ),
            'datePublished' => current_time('c'),
            'dateModified' => current_time('c'),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id' => home_url('/'),
            ),
        );

        // Add image if available
        if (!empty($article['featured_image'])) {
            $schema['image'] = array(
                '@type' => 'ImageObject',
                'url' => $article['featured_image'],
            );
        }

        // Add article body word count
        if (!empty($article['content'])) {
            $schema['wordCount'] = str_word_count(strip_tags($article['content']));
        }

        // Add keywords
        if (!empty($article['meta_keywords'])) {
            $schema['keywords'] = $article['meta_keywords'];
        }

        // Add about (real estate topic)
        $schema['about'] = array(
            '@type' => 'Thing',
            'name' => 'Boston Real Estate',
        );

        // Add geographic coverage
        $schema['spatialCoverage'] = array(
            '@type' => 'Place',
            'name' => 'Greater Boston, Massachusetts',
            'address' => array(
                '@type' => 'PostalAddress',
                'addressLocality' => 'Boston',
                'addressRegion' => 'MA',
                'addressCountry' => 'US',
            ),
        );

        return $schema;
    }

    /**
     * Generate schema markup HTML
     *
     * @param array $schema Schema data
     * @return string HTML script tag
     */
    public function generate_schema_html($schema) {
        return '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>';
    }

    /**
     * Optimize article content
     *
     * @param array $article Article data
     * @param array $analysis Analysis results
     * @return array Optimized article
     */
    public function optimize($article, $analysis = null) {
        if (!$analysis) {
            $analysis = $this->analyze($article);
        }

        $content = $article['content'];

        // Auto-fix some issues if possible

        // Ensure all images have alt text
        $content = preg_replace_callback(
            '/<img([^>]*)>/i',
            function($matches) use ($article) {
                $img_tag = $matches[0];
                if (!preg_match('/alt=["\']/', $img_tag)) {
                    $alt_text = 'Real estate image for ' . ($article['title'] ?? 'Boston homes');
                    $img_tag = str_replace('<img', '<img alt="' . esc_attr($alt_text) . '"', $img_tag);
                }
                return $img_tag;
            },
            $content
        );

        // Ensure external links have rel="noopener" and target="_blank"
        $content = preg_replace_callback(
            '/<a([^>]+href=["\']https?:\/\/(?!bmnboston\.com)[^"\']+["\'][^>]*)>/i',
            function($matches) {
                $a_tag = $matches[0];
                if (strpos($a_tag, 'target=') === false) {
                    $a_tag = str_replace('<a', '<a target="_blank"', $a_tag);
                }
                if (strpos($a_tag, 'rel=') === false) {
                    $a_tag = str_replace('<a', '<a rel="noopener noreferrer"', $a_tag);
                }
                return $a_tag;
            },
            $content
        );

        $article['content'] = $content;
        $article['schema'] = $analysis['schema'];

        return $article;
    }
}
