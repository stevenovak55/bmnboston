<?php
/**
 * Blog Internal Linker
 *
 * Intelligently inserts internal links to platform tools and pages
 * based on content context and keyword matching.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Internal_Linker
 *
 * Smart internal link insertion for blog content.
 */
class MLD_Blog_Internal_Linker {

    /**
     * Link targets with their trigger phrases
     *
     * @var array
     */
    private $link_targets = array(
        'search' => array(
            'url' => '/search/',
            'phrases' => array(
                'property search',
                'find homes',
                'browse listings',
                'search for homes',
                'home search',
                'find properties',
                'search properties',
                'explore listings',
                'view listings',
                'available homes',
            ),
            'max_links' => 2,
        ),
        'schools' => array(
            'url' => '/schools/',
            'phrases' => array(
                'school ratings',
                'top schools',
                'school districts',
                'best schools',
                'school information',
                'education options',
                'school search',
            ),
            'max_links' => 2,
        ),
        'book' => array(
            'url' => '/book/',
            'phrases' => array(
                'schedule a showing',
                'book a showing',
                'book appointment',
                'schedule appointment',
                'schedule a tour',
                'book a tour',
                'private showing',
            ),
            'max_links' => 1,
        ),
        'calculator' => array(
            'url' => '/mortgage-calculator/',
            'phrases' => array(
                'mortgage calculator',
                'calculate payments',
                'payment calculator',
                'monthly payment',
                'affordability calculator',
            ),
            'max_links' => 1,
        ),
        'app' => array(
            'url' => 'https://apps.apple.com/us/app/bmn-boston-real-estate/id1234567890',
            'phrases' => array(
                'mobile app',
                'download the app',
                'our app',
                'iOS app',
                'get the app',
                'property alerts',
            ),
            'max_links' => 1,
        ),
    );

    /**
     * City URL patterns
     *
     * @var array
     */
    private $city_patterns = array(
        'Boston' => '/homes-for-sale-in-boston-ma/',
        'Cambridge' => '/homes-for-sale-in-cambridge-ma/',
        'Somerville' => '/homes-for-sale-in-somerville-ma/',
        'Brookline' => '/homes-for-sale-in-brookline-ma/',
        'Newton' => '/homes-for-sale-in-newton-ma/',
        'Quincy' => '/homes-for-sale-in-quincy-ma/',
        'Medford' => '/homes-for-sale-in-medford-ma/',
        'Malden' => '/homes-for-sale-in-malden-ma/',
        'Waltham' => '/homes-for-sale-in-waltham-ma/',
        'Watertown' => '/homes-for-sale-in-watertown-ma/',
        'Arlington' => '/homes-for-sale-in-arlington-ma/',
        'Belmont' => '/homes-for-sale-in-belmont-ma/',
        'Lexington' => '/homes-for-sale-in-lexington-ma/',
        'Winchester' => '/homes-for-sale-in-winchester-ma/',
        'Wellesley' => '/homes-for-sale-in-wellesley-ma/',
        'Needham' => '/homes-for-sale-in-needham-ma/',
        'Milton' => '/homes-for-sale-in-milton-ma/',
        'Dedham' => '/homes-for-sale-in-dedham-ma/',
    );

    /**
     * School district URL patterns
     *
     * @var array
     */
    private $school_patterns = array(
        'Newton schools' => '/schools/newton/',
        'Cambridge schools' => '/schools/cambridge/',
        'Brookline schools' => '/schools/brookline/',
        'Lexington schools' => '/schools/lexington/',
        'Wellesley schools' => '/schools/wellesley/',
        'Boston schools' => '/schools/boston/',
    );

    /**
     * Links already inserted (to prevent duplicates)
     *
     * @var array
     */
    private $inserted_links = array();

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Process content and insert internal links
     *
     * @param string $content HTML content
     * @param array $options Processing options
     * @return string Content with links
     */
    public function process_content($content, $options = array()) {
        $defaults = array(
            'max_total_links' => 7,
            'link_city_mentions' => true,
            'link_school_mentions' => true,
            'process_placeholders' => true,
        );
        $options = wp_parse_args($options, $defaults);

        // Reset tracking
        $this->inserted_links = array();

        // Process INTERNAL: placeholders first
        if ($options['process_placeholders']) {
            $content = $this->process_placeholders($content);
        }

        // Link phrase matches
        $content = $this->link_phrases($content, $options);

        // Link city mentions
        if ($options['link_city_mentions']) {
            $content = $this->link_cities($content, $options);
        }

        // Link school district mentions
        if ($options['link_school_mentions']) {
            $content = $this->link_schools($content, $options);
        }

        return $content;
    }

    /**
     * Process INTERNAL: placeholder links
     *
     * @param string $content Content with placeholders
     * @return string Content with real URLs
     */
    private function process_placeholders($content) {
        // Pattern: <a href="INTERNAL:type">text</a> or [text](INTERNAL:type)
        $content = preg_replace_callback(
            '/<a[^>]+href=["\']INTERNAL:(\w+)["\'][^>]*>([^<]+)<\/a>/i',
            function($matches) {
                $type = strtolower($matches[1]);
                $text = $matches[2];

                $url = $this->get_url_for_type($type);
                if ($url) {
                    $this->track_link($type);
                    return '<a href="' . esc_url(home_url($url)) . '">' . esc_html($text) . '</a>';
                }
                return $matches[0];
            },
            $content
        );

        // Also handle markdown-style placeholders that might remain
        $content = preg_replace_callback(
            '/\[([^\]]+)\]\(INTERNAL:(\w+)\)/i',
            function($matches) {
                $text = $matches[1];
                $type = strtolower($matches[2]);

                $url = $this->get_url_for_type($type);
                if ($url) {
                    $this->track_link($type);
                    return '<a href="' . esc_url(home_url($url)) . '">' . esc_html($text) . '</a>';
                }
                return $text;
            },
            $content
        );

        return $content;
    }

    /**
     * Get URL for link type
     *
     * @param string $type Link type
     * @return string|null URL path or null
     */
    private function get_url_for_type($type) {
        if (isset($this->link_targets[$type])) {
            return $this->link_targets[$type]['url'];
        }

        // Check city patterns
        foreach ($this->city_patterns as $city => $url) {
            if (strtolower($city) === strtolower($type)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Link matching phrases in content
     *
     * @param string $content HTML content
     * @param array $options Options
     * @return string Content with links
     */
    private function link_phrases($content, $options) {
        foreach ($this->link_targets as $type => $config) {
            // Check if we've hit the max for this type
            $current_count = $this->get_link_count($type);
            if ($current_count >= $config['max_links']) {
                continue;
            }

            // Check total links
            if ($this->get_total_links() >= $options['max_total_links']) {
                break;
            }

            foreach ($config['phrases'] as $phrase) {
                // Check limits again
                if ($this->get_link_count($type) >= $config['max_links']) {
                    break;
                }
                if ($this->get_total_links() >= $options['max_total_links']) {
                    break 2;
                }

                // Find phrase not already in a link
                $content = $this->link_phrase_in_content($content, $phrase, $config['url'], $type);
            }
        }

        return $content;
    }

    /**
     * Link a specific phrase in content
     *
     * @param string $content Content
     * @param string $phrase Phrase to link
     * @param string $url URL to link to
     * @param string $type Link type for tracking
     * @return string Modified content
     */
    private function link_phrase_in_content($content, $phrase, $url, $type) {
        // Escape phrase for regex
        $escaped_phrase = preg_quote($phrase, '/');

        // Pattern: phrase not inside an existing link
        // This is a simplified approach - looks for phrase in text nodes
        $pattern = '/(?<!["\'>])(' . $escaped_phrase . ')(?![^<]*<\/a>)(?![^<]*>)/i';

        $linked = false;

        $content = preg_replace_callback(
            $pattern,
            function($matches) use ($url, $type, &$linked) {
                if ($linked) {
                    return $matches[0]; // Only link first occurrence
                }

                $linked = true;
                $this->track_link($type);

                $full_url = strpos($url, 'http') === 0 ? $url : home_url($url);

                return '<a href="' . esc_url($full_url) . '">' . esc_html($matches[1]) . '</a>';
            },
            $content,
            1 // Limit to 1 replacement
        );

        return $content;
    }

    /**
     * Link city mentions in content
     *
     * @param string $content HTML content
     * @param array $options Options
     * @return string Content with links
     */
    private function link_cities($content, $options) {
        foreach ($this->city_patterns as $city => $url) {
            // Check total links
            if ($this->get_total_links() >= $options['max_total_links']) {
                break;
            }

            // Skip if already linked this city
            if ($this->is_linked('city_' . $city)) {
                continue;
            }

            // Pattern for "homes in [City]" or "[City] homes" or "[City] real estate"
            $patterns = array(
                '/(?<!["\'>])(homes?\s+(?:for\s+sale\s+)?in\s+' . preg_quote($city, '/') . ')(?![^<]*<\/a>)/i',
                '/(?<!["\'>])(' . preg_quote($city, '/') . '\s+homes?)(?![^<]*<\/a>)/i',
                '/(?<!["\'>])(' . preg_quote($city, '/') . '\s+real\s+estate)(?![^<]*<\/a>)/i',
                '/(?<!["\'>])(' . preg_quote($city, '/') . '\s+properties)(?![^<]*<\/a>)/i',
            );

            foreach ($patterns as $pattern) {
                if ($this->get_total_links() >= $options['max_total_links']) {
                    break 2;
                }

                $linked = false;
                $content = preg_replace_callback(
                    $pattern,
                    function($matches) use ($url, $city, &$linked) {
                        if ($linked) {
                            return $matches[0];
                        }
                        $linked = true;
                        $this->track_link('city_' . $city);
                        return '<a href="' . esc_url(home_url($url)) . '">' . esc_html($matches[1]) . '</a>';
                    },
                    $content,
                    1
                );

                if ($linked) {
                    break; // Move to next city
                }
            }
        }

        return $content;
    }

    /**
     * Link school district mentions in content
     *
     * @param string $content HTML content
     * @param array $options Options
     * @return string Content with links
     */
    private function link_schools($content, $options) {
        foreach ($this->school_patterns as $phrase => $url) {
            // Check total links
            if ($this->get_total_links() >= $options['max_total_links']) {
                break;
            }

            // Skip if already linked
            if ($this->is_linked('school_' . $phrase)) {
                continue;
            }

            $escaped = preg_quote($phrase, '/');
            $pattern = '/(?<!["\'>])(' . $escaped . ')(?![^<]*<\/a>)/i';

            $linked = false;
            $content = preg_replace_callback(
                $pattern,
                function($matches) use ($url, $phrase, &$linked) {
                    if ($linked) {
                        return $matches[0];
                    }
                    $linked = true;
                    $this->track_link('school_' . $phrase);
                    return '<a href="' . esc_url(home_url($url)) . '">' . esc_html($matches[1]) . '</a>';
                },
                $content,
                1
            );
        }

        return $content;
    }

    /**
     * Track an inserted link
     *
     * @param string $type Link type
     */
    private function track_link($type) {
        if (!isset($this->inserted_links[$type])) {
            $this->inserted_links[$type] = 0;
        }
        $this->inserted_links[$type]++;
    }

    /**
     * Get count of links for a type
     *
     * @param string $type Link type
     * @return int Count
     */
    private function get_link_count($type) {
        return $this->inserted_links[$type] ?? 0;
    }

    /**
     * Check if a specific link has been inserted
     *
     * @param string $key Link key
     * @return bool
     */
    private function is_linked($key) {
        return isset($this->inserted_links[$key]) && $this->inserted_links[$key] > 0;
    }

    /**
     * Get total links inserted
     *
     * @return int Total count
     */
    private function get_total_links() {
        return array_sum($this->inserted_links);
    }

    /**
     * Get link statistics
     *
     * @return array Statistics
     */
    public function get_stats() {
        return array(
            'total' => $this->get_total_links(),
            'by_type' => $this->inserted_links,
        );
    }

    /**
     * Add a custom link target
     *
     * @param string $type Type identifier
     * @param array $config Configuration
     */
    public function add_link_target($type, $config) {
        $this->link_targets[$type] = wp_parse_args($config, array(
            'url' => '/',
            'phrases' => array(),
            'max_links' => 1,
        ));
    }

    /**
     * Add a custom city pattern
     *
     * @param string $city City name
     * @param string $url URL path
     */
    public function add_city_pattern($city, $url) {
        $this->city_patterns[$city] = $url;
    }

    /**
     * Suggest link opportunities in content
     *
     * @param string $content HTML content
     * @return array Suggested links
     */
    public function suggest_links($content) {
        $suggestions = array();
        $plain_content = strip_tags($content);

        // Check for phrases that could be linked
        foreach ($this->link_targets as $type => $config) {
            foreach ($config['phrases'] as $phrase) {
                if (stripos($plain_content, $phrase) !== false) {
                    $suggestions[] = array(
                        'type' => $type,
                        'phrase' => $phrase,
                        'url' => $config['url'],
                        'reason' => "Content mentions '$phrase' which could link to {$config['url']}",
                    );
                }
            }
        }

        // Check for city mentions
        foreach ($this->city_patterns as $city => $url) {
            if (stripos($plain_content, $city) !== false) {
                $suggestions[] = array(
                    'type' => 'city',
                    'phrase' => $city,
                    'url' => $url,
                    'reason' => "Content mentions $city which could link to city page",
                );
            }
        }

        return $suggestions;
    }
}
