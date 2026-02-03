<?php
/**
 * MLS Listings Display - LocalBusiness Schema
 *
 * Generates LocalBusiness structured data for agent/brokerage
 * This improves local SEO and Google My Business integration
 *
 * @package MLS_Listings_Display
 * @since 6.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_Business_Schema {

    /**
     * Constructor
     */
    public function __construct() {
        // Add LocalBusiness schema to homepage and property pages
        add_action('wp_head', array($this, 'output_business_schema'), 5);
    }

    /**
     * Get business settings from WordPress options
     *
     * @return array Business information
     */
    private function get_business_settings() {
        return array(
            'enabled' => get_option('mld_business_schema_enabled', false),
            'business_type' => get_option('mld_business_type', 'RealEstateAgent'),
            'business_name' => get_option('mld_business_name', get_bloginfo('name')),
            'agent_name' => get_option('mld_agent_name', ''),
            'phone' => get_option('mld_business_phone', ''),
            'email' => get_option('mld_business_email', get_option('admin_email')),
            'street_address' => get_option('mld_business_street_address', ''),
            'city' => get_option('mld_business_city', ''),
            'state' => get_option('mld_business_state', ''),
            'zip' => get_option('mld_business_zip', ''),
            'hours' => get_option('mld_business_hours', ''),
            'logo' => get_option('mld_business_logo', ''),
            'image' => get_option('mld_business_image', ''),
            'description' => get_option('mld_business_description', ''),
            'service_area' => get_option('mld_business_service_area', ''),
            'facebook' => get_option('mld_business_facebook', ''),
            'twitter' => get_option('mld_business_twitter', ''),
            'instagram' => get_option('mld_business_instagram', ''),
            'linkedin' => get_option('mld_business_linkedin', ''),
            'youtube' => get_option('mld_business_youtube', ''),
            'price_range' => get_option('mld_business_price_range', ''),
            'established' => get_option('mld_business_established', '')
        );
    }

    /**
     * Check if we should output business schema on current page
     *
     * @return bool
     */
    private function should_output_schema() {
        // Output on homepage
        if (is_front_page() || is_home()) {
            return true;
        }

        // Output on property pages
        if (get_query_var('mls_number', false) !== false) {
            return true;
        }

        // Output on about/contact pages if they exist
        if (is_page(array('about', 'contact', 'about-us', 'contact-us'))) {
            return true;
        }

        return false;
    }

    /**
     * Output LocalBusiness structured data
     */
    public function output_business_schema() {
        // Check if schema is enabled
        $settings = $this->get_business_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        // Only output on specific pages
        if (!$this->should_output_schema()) {
            return;
        }

        // Build LocalBusiness schema
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => $settings['business_type'], // RealEstateAgent or RealEstateAgency
            'name' => $settings['business_name'],
            'url' => home_url('/')
        );

        // Add agent name if provided
        if (!empty($settings['agent_name'])) {
            $schema['employee'] = array(
                '@type' => 'Person',
                'name' => $settings['agent_name']
            );
        }

        // Add description
        if (!empty($settings['description'])) {
            $schema['description'] = $settings['description'];
        }

        // Add contact information
        if (!empty($settings['phone'])) {
            $schema['telephone'] = $settings['phone'];
        }

        if (!empty($settings['email'])) {
            $schema['email'] = $settings['email'];
        }

        // Add address
        if (!empty($settings['street_address']) && !empty($settings['city']) && !empty($settings['state'])) {
            $schema['address'] = array(
                '@type' => 'PostalAddress',
                'streetAddress' => $settings['street_address'],
                'addressLocality' => $settings['city'],
                'addressRegion' => $settings['state'],
                'postalCode' => $settings['zip'],
                'addressCountry' => 'US'
            );
        }

        // Add service area (cities covered)
        if (!empty($settings['service_area'])) {
            $service_areas = array_map('trim', explode(',', $settings['service_area']));
            $area_served = array();

            foreach ($service_areas as $area) {
                $area_served[] = array(
                    '@type' => 'City',
                    'name' => $area
                );
            }

            $schema['areaServed'] = $area_served;
        }

        // Add operating hours
        if (!empty($settings['hours'])) {
            $schema['openingHours'] = $settings['hours'];
        }

        // Add logo
        if (!empty($settings['logo'])) {
            $schema['logo'] = array(
                '@type' => 'ImageObject',
                'url' => $settings['logo']
            );
        }

        // Add image
        if (!empty($settings['image'])) {
            $schema['image'] = $settings['image'];
        }

        // Add social media profiles
        $social_profiles = array();
        if (!empty($settings['facebook'])) {
            $social_profiles[] = $settings['facebook'];
        }
        if (!empty($settings['twitter'])) {
            $social_profiles[] = $settings['twitter'];
        }
        if (!empty($settings['instagram'])) {
            $social_profiles[] = $settings['instagram'];
        }
        if (!empty($settings['linkedin'])) {
            $social_profiles[] = $settings['linkedin'];
        }
        if (!empty($settings['youtube'])) {
            $social_profiles[] = $settings['youtube'];
        }

        if (!empty($social_profiles)) {
            $schema['sameAs'] = $social_profiles;
        }

        // Add price range (e.g., "$$", "$$$")
        if (!empty($settings['price_range'])) {
            $schema['priceRange'] = $settings['price_range'];
        }

        // Add founding date
        if (!empty($settings['established'])) {
            $schema['foundingDate'] = $settings['established'];
        }

        // Add geo coordinates if address is set (optional enhancement)
        if (!empty($settings['street_address'])) {
            // Could add geocoding here in future version
        }

        // Output schema
        echo "\n<!-- MLD LocalBusiness Schema -->\n";
        echo '<script type="application/ld+json">' . "\n";
        echo json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo "\n" . '</script>' . "\n";
    }
}
