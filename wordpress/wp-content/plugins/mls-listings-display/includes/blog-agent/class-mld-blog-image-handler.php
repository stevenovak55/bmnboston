<?php
/**
 * Blog Image Handler
 *
 * Multi-source image handling for blog articles including platform property photos,
 * stock photos from Unsplash/Pexels, and WordPress media library management.
 *
 * @package MLS_Listings_Display
 * @subpackage Blog_Agent
 * @since 6.73.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Blog_Image_Handler
 *
 * Handles image sourcing and management for blog articles.
 */
class MLD_Blog_Image_Handler {

    /**
     * Unsplash API endpoint
     */
    const UNSPLASH_API = 'https://api.unsplash.com';

    /**
     * Pexels API endpoint
     */
    const PEXELS_API = 'https://api.pexels.com/v1';

    /**
     * Image source priority
     *
     * @var array
     */
    private $source_priority = array(
        'platform',  // Property photos from bme_media
        'unsplash',  // Stock photos from Unsplash
        'pexels',    // Backup stock from Pexels
    );

    /**
     * Default search queries for real estate images
     *
     * @var array
     */
    private $default_queries = array(
        'featured' => 'boston skyline real estate',
        'content' => array(
            'modern home interior',
            'neighborhood street',
            'home exterior',
            'real estate agent',
            'house keys',
            'moving boxes',
        ),
    );

    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to initialize
    }

    /**
     * Get images for an article
     *
     * @param array $article Article data
     * @param array $options Image options
     * @return array Image results
     */
    public function get_images($article, $options = array()) {
        $defaults = array(
            'featured_count' => 1,
            'content_count' => 3,
            'preferred_source' => 'auto',
            'related_cities' => array(),
            'keywords' => array(),
        );
        $options = wp_parse_args($options, $defaults);

        $images = array(
            'featured' => null,
            'content' => array(),
        );

        // Extract cities from article structure if not provided
        if (empty($options['related_cities']) && !empty($article['structure']['sections'])) {
            $options['related_cities'] = $this->extract_cities_from_content($article);
        }

        // Try to get featured image
        $featured = $this->get_featured_image($options);
        if ($featured) {
            $images['featured'] = $featured;
        }

        // Get content images
        $content_images = $this->get_content_images($options);
        $images['content'] = array_slice($content_images, 0, $options['content_count']);

        return array(
            'success' => true,
            'images' => $images,
            'count' => ($images['featured'] ? 1 : 0) + count($images['content']),
        );
    }

    /**
     * Get featured image
     *
     * @param array $options Options
     * @return array|null Image data
     */
    private function get_featured_image($options) {
        // Try platform images first if cities specified
        if (!empty($options['related_cities'])) {
            $platform_image = $this->get_platform_image($options['related_cities'][0]);
            if ($platform_image) {
                return $platform_image;
            }
        }

        // Fall back to stock photos
        $query = $this->build_search_query('featured', $options);
        return $this->get_stock_image($query, 'landscape');
    }

    /**
     * Get content images
     *
     * @param array $options Options
     * @return array Image data array
     */
    private function get_content_images($options) {
        $images = array();

        // Try to get variety of images
        $queries = $this->default_queries['content'];

        // Add keyword-based queries
        if (!empty($options['keywords'])) {
            foreach (array_slice($options['keywords'], 0, 2) as $keyword) {
                $queries[] = $keyword . ' real estate';
            }
        }

        foreach ($queries as $index => $query) {
            if (count($images) >= $options['content_count']) {
                break;
            }

            $image = $this->get_stock_image($query, 'landscape', $index);
            if ($image) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * Get image from platform property photos
     *
     * @param string $city City name
     * @return array|null Image data
     */
    private function get_platform_image($city) {
        global $wpdb;

        $summary_table = $wpdb->prefix . 'bme_listing_summary';
        $media_table = $wpdb->prefix . 'bme_media';

        // Get a recent listing with photos from the specified city
        $listing = $wpdb->get_row($wpdb->prepare(
            "SELECT ls.listing_id, ls.full_street_address, ls.city
             FROM $summary_table ls
             INNER JOIN $media_table m ON ls.listing_key = m.listing_key
             WHERE ls.city = %s
             AND ls.standard_status = 'Active'
             AND m.media_category = 'Photo'
             ORDER BY ls.original_entry_timestamp DESC
             LIMIT 1",
            $city
        ), ARRAY_A);

        if (!$listing) {
            return null;
        }

        // Get the first photo for this listing
        $photo = $wpdb->get_row($wpdb->prepare(
            "SELECT media_url, media_key
             FROM $media_table
             WHERE listing_key = (
                 SELECT listing_key FROM $summary_table WHERE listing_id = %s LIMIT 1
             )
             AND media_category = 'Photo'
             ORDER BY `order` ASC
             LIMIT 1",
            $listing['listing_id']
        ), ARRAY_A);

        if (!$photo || empty($photo['media_url'])) {
            return null;
        }

        return array(
            'source' => 'platform',
            'url' => $photo['media_url'],
            'alt' => 'Property in ' . $listing['city'] . ' - ' . $listing['full_street_address'],
            'caption' => 'Featured property in ' . $listing['city'],
            'attribution' => null,
            'listing_id' => $listing['listing_id'],
        );
    }

    /**
     * Get stock image from Unsplash or Pexels
     *
     * @param string $query Search query
     * @param string $orientation Image orientation
     * @param int $page_offset Offset for pagination
     * @return array|null Image data
     */
    private function get_stock_image($query, $orientation = 'landscape', $page_offset = 0) {
        // Try Unsplash first
        $unsplash_image = $this->search_unsplash($query, $orientation, $page_offset);
        if ($unsplash_image) {
            return $unsplash_image;
        }

        // Fall back to Pexels
        $pexels_image = $this->search_pexels($query, $orientation, $page_offset);
        if ($pexels_image) {
            return $pexels_image;
        }

        return null;
    }

    /**
     * Search Unsplash for images
     *
     * @param string $query Search query
     * @param string $orientation Orientation
     * @param int $page Page number
     * @return array|null Image data
     */
    private function search_unsplash($query, $orientation, $page = 0) {
        $api_key = $this->get_api_key('unsplash');
        if (empty($api_key)) {
            return null;
        }

        $url = add_query_arg(array(
            'query' => $query,
            'orientation' => $orientation,
            'per_page' => 1,
            'page' => $page + 1,
        ), self::UNSPLASH_API . '/search/photos');

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Client-ID ' . $api_key,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['results'][0])) {
            return null;
        }

        $photo = $body['results'][0];

        return array(
            'source' => 'unsplash',
            'url' => $photo['urls']['regular'],
            'url_full' => $photo['urls']['full'],
            'url_thumb' => $photo['urls']['thumb'],
            'alt' => $photo['alt_description'] ?? $query,
            'caption' => $photo['description'] ?? '',
            'attribution' => array(
                'photographer' => $photo['user']['name'],
                'photographer_url' => $photo['user']['links']['html'],
                'source' => 'Unsplash',
                'source_url' => $photo['links']['html'],
            ),
            'download_url' => $photo['links']['download'],
            'external_id' => $photo['id'],
        );
    }

    /**
     * Search Pexels for images
     *
     * @param string $query Search query
     * @param string $orientation Orientation
     * @param int $page Page number
     * @return array|null Image data
     */
    private function search_pexels($query, $orientation, $page = 0) {
        $api_key = $this->get_api_key('pexels');
        if (empty($api_key)) {
            return null;
        }

        $url = add_query_arg(array(
            'query' => $query,
            'orientation' => $orientation,
            'per_page' => 1,
            'page' => $page + 1,
        ), self::PEXELS_API . '/search');

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $api_key,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['photos'][0])) {
            return null;
        }

        $photo = $body['photos'][0];

        return array(
            'source' => 'pexels',
            'url' => $photo['src']['large'],
            'url_full' => $photo['src']['original'],
            'url_thumb' => $photo['src']['medium'],
            'alt' => $photo['alt'] ?? $query,
            'caption' => '',
            'attribution' => array(
                'photographer' => $photo['photographer'],
                'photographer_url' => $photo['photographer_url'],
                'source' => 'Pexels',
                'source_url' => $photo['url'],
            ),
            'external_id' => $photo['id'],
        );
    }

    /**
     * Get API key from settings
     *
     * @param string $service Service name
     * @return string|null API key
     */
    private function get_api_key($service) {
        $option_key = 'mld_blog_' . $service . '_api_key';
        return get_option($option_key, '');
    }

    /**
     * Build search query based on context
     *
     * @param string $type Image type (featured/content)
     * @param array $options Options
     * @return string Search query
     */
    private function build_search_query($type, $options) {
        $parts = array();

        if (!empty($options['related_cities'])) {
            $parts[] = $options['related_cities'][0];
        } else {
            $parts[] = 'boston';
        }

        if ($type === 'featured') {
            $parts[] = 'real estate skyline';
        } else {
            $parts[] = 'home';
        }

        if (!empty($options['keywords'])) {
            $parts[] = $options['keywords'][0];
        }

        return implode(' ', $parts);
    }

    /**
     * Extract cities from article content
     *
     * @param array $article Article data
     * @return array Cities found
     */
    private function extract_cities_from_content($article) {
        $cities = array();

        // Check structure for related cities
        if (!empty($article['structure']['related_cities'])) {
            return $article['structure']['related_cities'];
        }

        // Check content for city mentions
        $boston_cities = array(
            'Boston', 'Cambridge', 'Somerville', 'Brookline', 'Newton',
            'Quincy', 'Medford', 'Waltham', 'Arlington',
        );

        $content = strip_tags($article['content'] ?? '');

        foreach ($boston_cities as $city) {
            if (stripos($content, $city) !== false) {
                $cities[] = $city;
            }
        }

        return array_slice($cities, 0, 3);
    }

    /**
     * Upload image to WordPress media library
     *
     * @param array $image Image data
     * @param int $post_id Associated post ID
     * @return int|WP_Error Attachment ID or error
     */
    public function upload_to_media_library($image, $post_id = 0) {
        if (empty($image['url'])) {
            return new WP_Error('no_url', 'No image URL provided');
        }

        // Download the image
        $temp_file = download_url($image['url'], 30);

        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        // Prepare file data
        $file_name = $this->generate_filename($image);
        $file_array = array(
            'name' => $file_name,
            'tmp_name' => $temp_file,
        );

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, $post_id, $image['alt'] ?? '');

        // Clean up temp file
        if (file_exists($temp_file)) {
            @unlink($temp_file);
        }

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Set alt text
        if (!empty($image['alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $image['alt']);
        }

        // Store attribution data
        if (!empty($image['attribution'])) {
            update_post_meta($attachment_id, '_mld_image_attribution', $image['attribution']);
            update_post_meta($attachment_id, '_mld_image_source', $image['source']);
        }

        return $attachment_id;
    }

    /**
     * Generate a filename for the image
     *
     * @param array $image Image data
     * @return string Filename
     */
    private function generate_filename($image) {
        $extension = 'jpg';

        // Try to get extension from URL
        if (!empty($image['url'])) {
            $parsed = parse_url($image['url'], PHP_URL_PATH);
            $path_ext = pathinfo($parsed, PATHINFO_EXTENSION);
            if (in_array($path_ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'))) {
                $extension = $path_ext;
            }
        }

        // Generate descriptive filename
        $base = sanitize_title($image['alt'] ?? 'blog-image');
        $base = substr($base, 0, 50); // Limit length

        return $base . '-' . time() . '.' . $extension;
    }

    /**
     * Generate SEO-friendly alt text
     *
     * @param array $image Image data
     * @param array $context Article context
     * @return string Alt text
     */
    public function generate_alt_text($image, $context = array()) {
        $parts = array();

        // Use existing alt if available and descriptive
        if (!empty($image['alt']) && strlen($image['alt']) > 10) {
            return $image['alt'];
        }

        // Build from context
        if (!empty($context['title'])) {
            $parts[] = 'Image for ' . $context['title'];
        }

        if (!empty($context['city'])) {
            $parts[] = 'in ' . $context['city'];
        }

        if ($image['source'] === 'platform' && !empty($image['listing_id'])) {
            return 'Property photo - ' . ($image['caption'] ?? 'Boston area home');
        }

        if (empty($parts)) {
            return 'Boston real estate - ' . ($image['source'] ?? 'stock photo');
        }

        return implode(' ', $parts);
    }

    /**
     * Insert images into article content
     *
     * @param string $content Article content
     * @param array $images Images to insert
     * @param array $options Insertion options
     * @return string Content with images
     */
    public function insert_images_in_content($content, $images, $options = array()) {
        $defaults = array(
            'insert_after_headings' => true,
            'max_images' => 4,
        );
        $options = wp_parse_args($options, $defaults);

        if (empty($images['content'])) {
            return $content;
        }

        $images_to_insert = array_slice($images['content'], 0, $options['max_images']);

        if (!$options['insert_after_headings']) {
            // Append all images at the end
            foreach ($images_to_insert as $image) {
                $content .= $this->build_image_html($image);
            }
            return $content;
        }

        // Find H2 headings and insert images after some of them
        $h2_pattern = '/<\/h2>/i';
        preg_match_all($h2_pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $content;
        }

        $positions = $matches[0];
        $insert_count = min(count($images_to_insert), count($positions) - 1);

        // Calculate which headings to insert after (evenly distributed)
        $step = max(1, floor(count($positions) / ($insert_count + 1)));

        $offset_adjustment = 0;
        $image_index = 0;

        for ($i = $step; $i < count($positions) && $image_index < $insert_count; $i += $step) {
            $position = $positions[$i][1] + strlen($positions[$i][0]) + $offset_adjustment;

            $image_html = "\n\n" . $this->build_image_html($images_to_insert[$image_index]) . "\n\n";

            $content = substr_replace($content, $image_html, $position, 0);
            $offset_adjustment += strlen($image_html);

            $image_index++;
        }

        return $content;
    }

    /**
     * Build HTML for an image
     *
     * @param array $image Image data
     * @return string HTML
     */
    private function build_image_html($image) {
        $html = '<figure class="wp-block-image size-large">';
        $html .= '<img src="' . esc_url($image['url']) . '" alt="' . esc_attr($image['alt'] ?? '') . '" loading="lazy" />';

        if (!empty($image['caption']) || !empty($image['attribution'])) {
            $html .= '<figcaption>';
            if (!empty($image['caption'])) {
                $html .= esc_html($image['caption']);
            }
            if (!empty($image['attribution'])) {
                if (!empty($image['caption'])) {
                    $html .= ' | ';
                }
                $html .= 'Photo by <a href="' . esc_url($image['attribution']['photographer_url']) . '" target="_blank" rel="noopener">';
                $html .= esc_html($image['attribution']['photographer']);
                $html .= '</a> on <a href="' . esc_url($image['attribution']['source_url']) . '" target="_blank" rel="noopener">';
                $html .= esc_html($image['attribution']['source']);
                $html .= '</a>';
            }
            $html .= '</figcaption>';
        }

        $html .= '</figure>';

        return $html;
    }

    /**
     * Set featured image for a post
     *
     * @param int $post_id Post ID
     * @param array $image Image data
     * @return int|WP_Error Attachment ID or error
     */
    public function set_featured_image($post_id, $image) {
        $attachment_id = $this->upload_to_media_library($image, $post_id);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        set_post_thumbnail($post_id, $attachment_id);

        return $attachment_id;
    }
}
