<?php
/**
 * Blog CTA Manager
 *
 * Manages call-to-action selection and placement for blog articles.
 * Selects appropriate CTAs based on article topic and content.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_CTA_Manager
 *
 * CTA selection and placement for blog articles.
 */
class MLD_Blog_CTA_Manager {

    /**
     * Available CTA types with their configurations
     *
     * @var array
     */
    private $cta_types = array(
        'contact' => array(
            'title' => 'Ready to Start Your Home Search?',
            'description' => 'Our experienced agents are here to help you find the perfect property in Greater Boston.',
            'button_text' => 'Contact Us Today',
            'button_url' => '/contact/',
            'icon' => 'phone',
            'keywords' => array('agent', 'realtor', 'help', 'advice', 'expert', 'consultation'),
            'topics' => array('market analysis', 'investment', 'negotiation'),
        ),
        'search' => array(
            'title' => 'Explore Homes in Boston',
            'description' => 'Browse thousands of listings with our powerful property search. Filter by neighborhood, price, schools, and more.',
            'button_text' => 'Search Properties',
            'button_url' => '/search/',
            'icon' => 'search',
            'keywords' => array('search', 'browse', 'find', 'explore', 'listings', 'properties'),
            'topics' => array('neighborhood', 'buying guide', 'first-time buyer'),
        ),
        'book' => array(
            'title' => 'Found a Property You Love?',
            'description' => 'Schedule a private showing with one of our agents at your convenience.',
            'button_text' => 'Book a Showing',
            'button_url' => '/book/',
            'icon' => 'calendar',
            'keywords' => array('showing', 'tour', 'visit', 'see', 'view', 'appointment'),
            'topics' => array('open house', 'viewing', 'property tour'),
        ),
        'download' => array(
            'title' => 'Get Instant Property Alerts',
            'description' => 'Download our mobile app and never miss a new listing that matches your criteria.',
            'button_text' => 'Download the App',
            'button_url' => 'https://apps.apple.com/us/app/bmn-boston-real-estate/',
            'icon' => 'smartphone',
            'keywords' => array('app', 'mobile', 'alerts', 'notifications', 'instant'),
            'topics' => array('technology', 'convenience', 'notifications'),
        ),
        'schools' => array(
            'title' => 'Find Homes Near Top-Rated Schools',
            'description' => 'Search properties by school district and find the perfect home for your family.',
            'button_text' => 'Search by Schools',
            'button_url' => '/schools/',
            'icon' => 'graduation-cap',
            'keywords' => array('school', 'education', 'district', 'kids', 'family', 'children'),
            'topics' => array('schools', 'education', 'family'),
        ),
    );

    /**
     * CTA positions
     */
    const POSITION_END = 'end';
    const POSITION_MIDDLE = 'middle';
    const POSITION_BOTH = 'both';

    /**
     * Constructor
     */
    public function __construct() {
        // Allow customization via filter
        $this->cta_types = apply_filters('mld_blog_cta_types', $this->cta_types);
    }

    /**
     * Get appropriate CTA for a topic
     *
     * @param array $topic Topic data
     * @param string $preferred_type Preferred CTA type or 'auto'
     * @return array CTA data
     */
    public function get_cta_for_topic($topic, $preferred_type = 'auto') {
        $cta_type = $preferred_type;

        if ($cta_type === 'auto') {
            $cta_type = $this->determine_best_cta($topic);
        }

        if (!isset($this->cta_types[$cta_type])) {
            $cta_type = 'contact'; // Default fallback
        }

        $cta = $this->cta_types[$cta_type];
        $cta['type'] = $cta_type;
        $cta['position'] = $this->determine_position($topic, $cta_type);
        $cta['html'] = $this->generate_cta_html($cta);

        return $cta;
    }

    /**
     * Determine the best CTA type based on topic content
     *
     * @param array $topic Topic data
     * @return string CTA type
     */
    private function determine_best_cta($topic) {
        $title = strtolower($topic['title'] ?? '');
        $description = strtolower($topic['description'] ?? '');
        $keywords = $topic['keywords'] ?? array();

        if (is_string($keywords)) {
            $keywords = json_decode($keywords, true) ?: array();
        }

        $text = $title . ' ' . $description . ' ' . implode(' ', $keywords);

        $scores = array();

        foreach ($this->cta_types as $type => $config) {
            $score = 0;

            // Check keyword matches
            foreach ($config['keywords'] as $keyword) {
                if (stripos($text, $keyword) !== false) {
                    $score += 10;
                }
            }

            // Check topic matches
            foreach ($config['topics'] as $topic_keyword) {
                if (stripos($text, $topic_keyword) !== false) {
                    $score += 20;
                }
            }

            $scores[$type] = $score;
        }

        // If no clear winner, use default logic
        if (max($scores) === 0) {
            // Default based on common topic patterns
            if (preg_match('/first.?time|beginner|guide|how.?to/i', $text)) {
                return 'search';
            }
            if (preg_match('/school|education|family|kids/i', $text)) {
                return 'schools';
            }
            if (preg_match('/invest|market|trend|analysis/i', $text)) {
                return 'contact';
            }
            return 'search'; // Default fallback
        }

        // Return highest scoring type
        arsort($scores);
        return key($scores);
    }

    /**
     * Determine CTA position based on article characteristics
     *
     * @param array $topic Topic data
     * @param string $cta_type CTA type
     * @return string Position constant
     */
    private function determine_position($topic, $cta_type) {
        // For now, default to end
        // Could be enhanced to analyze article length, engagement patterns, etc.
        return self::POSITION_END;
    }

    /**
     * Generate HTML for a CTA
     *
     * @param array $cta CTA data
     * @return string HTML
     */
    private function generate_cta_html($cta) {
        $icon_svg = $this->get_icon_svg($cta['icon'] ?? 'arrow-right');

        $button_url = $cta['button_url'];
        if (strpos($button_url, 'http') !== 0) {
            $button_url = home_url($button_url);
        }

        $html = '<div class="mld-blog-cta mld-blog-cta--' . esc_attr($cta['type']) . '">';
        $html .= '<div class="mld-blog-cta__inner">';

        // Icon
        $html .= '<div class="mld-blog-cta__icon">' . $icon_svg . '</div>';

        // Content
        $html .= '<div class="mld-blog-cta__content">';
        $html .= '<h3 class="mld-blog-cta__title">' . esc_html($cta['title']) . '</h3>';
        $html .= '<p class="mld-blog-cta__description">' . esc_html($cta['description']) . '</p>';
        $html .= '</div>';

        // Button
        $html .= '<div class="mld-blog-cta__action">';
        $html .= '<a href="' . esc_url($button_url) . '" class="mld-blog-cta__button">';
        $html .= esc_html($cta['button_text']);
        $html .= '</a>';
        $html .= '</div>';

        $html .= '</div>'; // inner
        $html .= '</div>'; // cta

        return $html;
    }

    /**
     * Get SVG icon
     *
     * @param string $icon Icon name
     * @return string SVG markup
     */
    private function get_icon_svg($icon) {
        $icons = array(
            'phone' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
            'search' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
            'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>',
            'smartphone' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>',
            'graduation-cap' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>',
            'arrow-right' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>',
        );

        return $icons[$icon] ?? $icons['arrow-right'];
    }

    /**
     * Get available CTA types
     *
     * @return array CTA types
     */
    public function get_available_types() {
        $types = array();

        foreach ($this->cta_types as $key => $config) {
            $types[$key] = array(
                'title' => $config['title'],
                'description' => $config['description'],
            );
        }

        return $types;
    }

    /**
     * Add a custom CTA type
     *
     * @param string $key CTA key
     * @param array $config CTA configuration
     */
    public function add_cta_type($key, $config) {
        $this->cta_types[$key] = wp_parse_args($config, array(
            'title' => '',
            'description' => '',
            'button_text' => 'Learn More',
            'button_url' => '/',
            'icon' => 'arrow-right',
            'keywords' => array(),
            'topics' => array(),
        ));
    }

    /**
     * Customize CTA text for specific context
     *
     * @param array $cta CTA data
     * @param array $context Context data (city, property type, etc.)
     * @return array Modified CTA
     */
    public function customize_cta($cta, $context = array()) {
        // Customize title with city
        if (!empty($context['city']) && $cta['type'] === 'search') {
            $cta['title'] = 'Explore Homes in ' . $context['city'];
            $cta['button_url'] = '/homes-for-sale-in-' . sanitize_title($context['city']) . '-ma/';
        }

        // Customize for schools
        if (!empty($context['school_district']) && $cta['type'] === 'schools') {
            $cta['title'] = 'Find Homes in ' . $context['school_district'] . ' School District';
            $cta['button_url'] = '/schools/' . sanitize_title($context['school_district']) . '/';
        }

        // Regenerate HTML after customization
        $cta['html'] = $this->generate_cta_html($cta);

        return $cta;
    }

    /**
     * Get CTA CSS styles
     *
     * @return string CSS
     */
    public function get_cta_styles() {
        return '
        .mld-blog-cta {
            margin: 2rem 0;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border-left: 4px solid #0066cc;
        }

        .mld-blog-cta__inner {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1.5rem;
        }

        .mld-blog-cta__icon {
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            background: #0066cc;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .mld-blog-cta__icon svg {
            width: 24px;
            height: 24px;
        }

        .mld-blog-cta__content {
            flex: 1;
            min-width: 200px;
        }

        .mld-blog-cta__title {
            margin: 0 0 0.5rem;
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a1a1a;
        }

        .mld-blog-cta__description {
            margin: 0;
            color: #666;
            font-size: 0.95rem;
        }

        .mld-blog-cta__action {
            flex-shrink: 0;
        }

        .mld-blog-cta__button {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #0066cc;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: background 0.2s, transform 0.2s;
        }

        .mld-blog-cta__button:hover {
            background: #0052a3;
            transform: translateY(-1px);
            color: white;
        }

        @media (max-width: 600px) {
            .mld-blog-cta__inner {
                flex-direction: column;
                text-align: center;
            }

            .mld-blog-cta__action {
                width: 100%;
            }

            .mld-blog-cta__button {
                display: block;
                width: 100%;
            }
        }
        ';
    }
}
