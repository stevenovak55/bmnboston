<?php
/**
 * MLD URL Helper Class
 *
 * Handles generation of SEO-friendly property URLs
 *
 * @package MLS_Listings_Display
 * @since 4.4.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLD_URL_Helper {

    /**
     * Generate SEO-friendly property URL
     *
     * @param array $listing Listing data array
     * @param bool $use_descriptive Whether to use descriptive URL format
     * @return string Property URL
     */
    public static function get_property_url($listing, $use_descriptive = true) {
        // Get the listing ID
        $listing_id = $listing['listing_id'] ?? $listing['ListingId'] ?? '';

        if (empty($listing_id)) {
            return '';
        }

        // Get base URL
        $base_url = home_url('/property/');

        // If not using descriptive URLs, return simple format
        if (!$use_descriptive) {
            return $base_url . $listing_id . '/';
        }

        // Build descriptive URL slug
        $slug = self::build_property_slug($listing);

        if (empty($slug)) {
            // Fallback to simple format if we can't build a slug
            return $base_url . $listing_id . '/';
        }

        return $base_url . $slug . '/';
    }

    /**
     * Build property slug from listing data
     * Format: {unparsed-address}-{total-beds}-bed-{total-baths}-bath-{for-sale-or-for-rent}-{listing-id}
     *
     * @param array $listing Listing data
     * @return string URL slug
     */
    private static function build_property_slug($listing) {
        $parts = array();

        // Get unparsed address if available, otherwise build from components
        $unparsed_address = $listing['unparsed_address'] ?? '';

        if (!empty($unparsed_address)) {
            // Use unparsed address directly
            $parts[] = self::sanitize_slug($unparsed_address);
        } else {
            // Build address from components as fallback
            $street_number = $listing['street_number'] ?? $listing['StreetNumber'] ?? '';
            $street_name = $listing['street_name'] ?? $listing['StreetName'] ?? '';
            $city = $listing['city'] ?? $listing['City'] ?? '';
            $state = $listing['state_or_province'] ?? $listing['StateOrProvince'] ?? '';
            $postal_code = $listing['postal_code'] ?? $listing['PostalCode'] ?? '';

            $address_parts = array();

            if (!empty($street_number)) {
                $address_parts[] = $street_number;
            }

            if (!empty($street_name)) {
                $address_parts[] = $street_name;
            }

            if (!empty($city)) {
                $address_parts[] = $city;
            }

            if (!empty($state)) {
                $address_parts[] = $state;
            }

            if (!empty($postal_code)) {
                // Only include first 5 digits of zip code
                $zip = substr(preg_replace('/[^0-9]/', '', $postal_code), 0, 5);
                if (!empty($zip)) {
                    $address_parts[] = $zip;
                }
            }

            if (!empty($address_parts)) {
                $parts[] = implode('-', $address_parts);
            }
        }

        // Get bedrooms
        $bedrooms = $listing['bedrooms_total'] ?? $listing['BedroomsTotal'] ?? 0;
        if ($bedrooms > 0) {
            $parts[] = $bedrooms . '-bed';
        }

        // Get bathrooms (full + half)
        $bathrooms_full = $listing['bathrooms_full'] ?? $listing['BathroomsFull'] ?? 0;
        $bathrooms_half = $listing['bathrooms_half'] ?? $listing['BathroomsHalf'] ?? 0;
        $total_bathrooms = $bathrooms_full + ($bathrooms_half * 0.5);

        if ($total_bathrooms > 0) {
            // Format bathrooms (e.g., 2.5 becomes 2-5, 2 becomes 2)
            if ($total_bathrooms == floor($total_bathrooms)) {
                $parts[] = intval($total_bathrooms) . '-bath';
            } else {
                $parts[] = str_replace('.', '-', strval($total_bathrooms)) . '-bath';
            }
        }

        // Determine sale or rent based on property type
        // Residential = For Sale, Residential Lease = For Rent
        $property_type = $listing['property_type'] ?? $listing['PropertyType'] ?? '';
        $property_sub_type = $listing['property_sub_type'] ?? $listing['PropertySubType'] ?? '';

        // More accurate determination: check for "Lease" or "Rental" in property type/subtype
        if (stripos($property_type, 'lease') !== false ||
            stripos($property_type, 'rental') !== false ||
            stripos($property_sub_type, 'lease') !== false ||
            stripos($property_sub_type, 'rental') !== false) {
            $parts[] = 'for-rent';
        } else {
            $parts[] = 'for-sale';
        }

        // Add listing ID at the end
        $listing_id = $listing['listing_id'] ?? $listing['ListingId'] ?? '';
        $parts[] = $listing_id;

        // Join all parts and sanitize
        $slug = implode('-', $parts);

        // Sanitize the slug
        $slug = self::sanitize_slug($slug);

        return $slug;
    }

    /**
     * Sanitize a URL slug
     *
     * @param string $slug Raw slug
     * @return string Sanitized slug
     */
    private static function sanitize_slug($slug) {
        // Convert to lowercase
        $slug = strtolower($slug);

        // Replace spaces with hyphens
        $slug = str_replace(' ', '-', $slug);

        // Remove special characters except hyphens and numbers
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

        // Replace multiple hyphens with single hyphen
        $slug = preg_replace('/-+/', '-', $slug);

        // Trim hyphens from start and end
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Extract listing ID from a descriptive URL slug
     *
     * @param string $slug URL slug
     * @return string|null Listing ID or null if not found
     */
    public static function extract_listing_id_from_slug($slug) {
        // The listing ID is the last numeric segment after the final hyphen
        if (preg_match('/-(\d+)$/', $slug, $matches)) {
            return $matches[1];
        }

        // If no hyphen, check if the entire slug is numeric (simple URL format)
        if (is_numeric($slug)) {
            return $slug;
        }

        return null;
    }

    /**
     * Get canonical URL for a property
     * Returns the descriptive URL as canonical for SEO
     *
     * @param array $listing Listing data
     * @return string Canonical URL
     */
    public static function get_canonical_url($listing) {
        // Always use descriptive format for canonical URLs
        return self::get_property_url($listing, true);
    }

    /**
     * Check if current URL is using the descriptive format
     *
     * @param string $url URL to check
     * @return bool True if using descriptive format
     */
    public static function is_descriptive_url($url) {
        // Extract path from URL
        $path = parse_url($url, PHP_URL_PATH);

        if (!$path) {
            return false;
        }

        // Remove /property/ prefix and trailing slash
        $path = trim(str_replace('/property/', '', $path), '/');

        // Check if it contains non-numeric characters (indicating descriptive format)
        return !is_numeric($path) && preg_match('/[a-z]/', $path);
    }

    /**
     * Generate URL for property from minimal data
     * Used when we only have listing ID and basic info
     *
     * @param string $listing_id MLS listing ID
     * @param array $options Optional data for building descriptive URL
     * @return string Property URL
     */
    public static function get_property_url_by_id($listing_id, $options = array()) {
        if (empty($listing_id)) {
            return '';
        }

        // If no options provided, return simple URL
        if (empty($options)) {
            return home_url('/property/' . $listing_id . '/');
        }

        // Build listing array from options
        $listing = array_merge(
            array('listing_id' => $listing_id),
            $options
        );

        return self::get_property_url($listing, true);
    }
}