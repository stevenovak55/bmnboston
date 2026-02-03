<?php
/**
 * MLS Listings Display - Search Results SEO
 *
 * Handles SEO for property search result pages
 * Manages canonical URLs, pagination noindex, and dynamic titles
 *
 * @package MLS_Listings_Display
 * @since 6.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Search_SEO {

    /**
     * Constructor
     */
    public function __construct() {
        // Add SEO hooks for search pages
        add_filter('document_title_parts', array($this, 'modify_search_title'), 20, 1);
        add_action('wp_head', array($this, 'add_search_meta_tags'), 2);
    }

    /**
     * Check if current page is a listings page (has MLD shortcodes)
     *
     * @return bool
     */
    private function is_listings_page() {
        global $post;

        if (!$post) {
            return false;
        }

        // Check if page contains MLD listing shortcodes
        $shortcodes = array('bme_listings_map_view', 'bme_listings_half_map_view', 'mld_map_full', 'mld_map_half', 'mld_listings');

        foreach ($shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get current page number
     *
     * @return int
     */
    private function get_current_page() {
        return max(1, get_query_var('paged', 1));
    }

    /**
     * Get active search filters from URL parameters
     *
     * @return array
     */
    private function get_active_filters() {
        $filters = array();

        // Common filter parameters
        $filter_params = array(
            'city' => 'City',
            'min_price' => 'Min Price',
            'max_price' => 'Max Price',
            'beds' => 'Bedrooms',
            'baths' => 'Bathrooms',
            'property_type' => 'Property Type',
            'min_sqft' => 'Min Sq Ft',
            'max_sqft' => 'Max Sq Ft'
        );

        foreach ($filter_params as $param => $label) {
            $value = isset($_GET[$param]) ? sanitize_text_field($_GET[$param]) : '';
            if (!empty($value)) {
                $filters[$param] = array(
                    'label' => $label,
                    'value' => $value
                );
            }
        }

        return $filters;
    }

    /**
     * Build dynamic title based on active filters
     *
     * @param array $filters Active filters
     * @param int $page Current page number
     * @return string
     */
    private function build_dynamic_title($filters, $page) {
        $title_parts = array();

        // Add bedrooms if set
        if (!empty($filters['beds'])) {
            $title_parts[] = $filters['beds']['value'] . '+ Bed';
        }

        // Add bathrooms if set
        if (!empty($filters['baths'])) {
            $title_parts[] = $filters['baths']['value'] . '+ Bath';
        }

        // Add property type if set
        if (!empty($filters['property_type'])) {
            $title_parts[] = $filters['property_type']['value'];
        }

        // Add "Homes" if no specific type
        if (empty($filters['property_type'])) {
            $title_parts[] = 'Homes';
        }

        // Add city if set
        if (!empty($filters['city'])) {
            $title_parts[] = 'in ' . $filters['city']['value'];
        }

        // Add price range if set
        if (!empty($filters['min_price']) || !empty($filters['max_price'])) {
            $price_part = '';
            if (!empty($filters['min_price'])) {
                $price_part .= '$' . number_format($filters['min_price']['value'], 0);
            }
            if (!empty($filters['min_price']) && !empty($filters['max_price'])) {
                $price_part .= ' - ';
            }
            if (!empty($filters['max_price'])) {
                $price_part .= '$' . number_format($filters['max_price']['value'], 0);
            }
            $title_parts[] = '(' . $price_part . ')';
        }

        // Build final title
        if (empty($title_parts)) {
            $title = 'Property Listings';
        } else {
            $title = implode(' ', $title_parts);
        }

        // Add page number if paginated
        if ($page > 1) {
            $title .= ' - Page ' . $page;
        }

        return $title;
    }

    /**
     * Modify page title for search results
     *
     * @param array $title_parts
     * @return array
     */
    public function modify_search_title($title_parts) {
        if (!$this->is_listings_page()) {
            return $title_parts;
        }

        $filters = $this->get_active_filters();
        $page = $this->get_current_page();

        // Only modify if filters are active or paginated
        if (empty($filters) && $page === 1) {
            return $title_parts;
        }

        $dynamic_title = $this->build_dynamic_title($filters, $page);
        $title_parts['title'] = $dynamic_title;

        return $title_parts;
    }

    /**
     * Add meta tags for search pages
     */
    public function add_search_meta_tags() {
        if (!$this->is_listings_page()) {
            return;
        }

        $filters = $this->get_active_filters();
        $page = $this->get_current_page();
        $current_url = $this->get_current_url();
        $canonical_url = $this->get_canonical_url($current_url);

        // Build meta description
        $description = $this->build_meta_description($filters);

        // Add canonical URL
        echo '<link rel="canonical" href="' . esc_url($canonical_url) . '">' . "\n";

        // Add meta description
        if (!empty($description)) {
            echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        }

        // Add noindex for paginated pages (page 2+)
        if ($page > 1) {
            echo '<meta name="robots" content="noindex, follow">' . "\n";
        }

        // Add prev/next for pagination
        if ($page > 1 || $this->has_next_page()) {
            $this->add_pagination_links($current_url, $page);
        }
    }

    /**
     * Build meta description based on active filters
     *
     * @param array $filters
     * @return string
     */
    private function build_meta_description($filters) {
        if (empty($filters)) {
            return '';
        }

        $desc_parts = array();
        $desc_parts[] = 'Search results for';

        // Add filter details
        $filter_details = array();

        if (!empty($filters['beds'])) {
            $filter_details[] = $filters['beds']['value'] . '+ bedrooms';
        }

        if (!empty($filters['baths'])) {
            $filter_details[] = $filters['baths']['value'] . '+ bathrooms';
        }

        if (!empty($filters['property_type'])) {
            $filter_details[] = strtolower($filters['property_type']['value']);
        } else {
            $filter_details[] = 'properties';
        }

        if (!empty($filters['city'])) {
            $filter_details[] = 'in ' . $filters['city']['value'];
        }

        $desc_parts[] = implode(' ', $filter_details);
        $desc_parts[] = 'View photos, pricing, and details';

        $description = implode('. ', $desc_parts) . '.';

        // Ensure description doesn't exceed 160 characters
        if (strlen($description) > 160) {
            $description = substr($description, 0, 157) . '...';
        }

        return $description;
    }

    /**
     * Get current URL
     *
     * @return string
     */
    private function get_current_url() {
        global $wp;
        return home_url(add_query_arg(array(), $wp->request));
    }

    /**
     * Get canonical URL (without page parameter, points to page 1)
     *
     * @param string $current_url
     * @return string
     */
    private function get_canonical_url($current_url) {
        // For paginated results, canonical should point to page 1 (no paged param)
        $canonical_url = $current_url;

        // Remove paged parameter from URL
        $canonical_url = remove_query_arg('paged', $canonical_url);

        return $canonical_url;
    }

    /**
     * Check if there's a next page
     *
     * @return bool
     */
    private function has_next_page() {
        global $wp_query;

        if (!$wp_query) {
            return false;
        }

        return $wp_query->max_num_pages > $this->get_current_page();
    }

    /**
     * Add prev/next pagination link tags
     *
     * @param string $base_url
     * @param int $current_page
     */
    private function add_pagination_links($base_url, $current_page) {
        // Add prev link if not on page 1
        if ($current_page > 1) {
            $prev_url = add_query_arg('paged', $current_page - 1, $base_url);
            echo '<link rel="prev" href="' . esc_url($prev_url) . '">' . "\n";
        }

        // Add next link if there are more pages
        if ($this->has_next_page()) {
            $next_url = add_query_arg('paged', $current_page + 1, $base_url);
            echo '<link rel="next" href="' . esc_url($next_url) . '">' . "\n";
        }
    }
}
