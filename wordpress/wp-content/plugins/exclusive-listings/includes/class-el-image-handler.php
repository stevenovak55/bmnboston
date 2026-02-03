<?php
/**
 * Image Handler for exclusive listings
 *
 * @package Exclusive_Listings
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EL_Image_Handler
 *
 * Handles image uploads, processing, and storage for exclusive listings.
 * Uses WordPress media library for storage with Kinsta CDN delivery.
 */
class EL_Image_Handler {

    /**
     * WordPress database object
     * @var wpdb
     */
    private $wpdb;

    /**
     * Database manager
     * @var EL_Database
     */
    private $database;

    /**
     * Allowed mime types
     * @var array
     */
    const ALLOWED_TYPES = array(
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    );

    /**
     * Maximum file size in bytes (10MB)
     * @var int
     */
    const MAX_FILE_SIZE = 10485760;

    /**
     * Maximum photos per listing
     * @var int
     */
    const MAX_PHOTOS = 100;

    /**
     * Maximum image dimension (longest edge) after optimization
     * @var int
     */
    const MAX_DIMENSION = 2048;

    /**
     * JPEG compression quality (0-100)
     * 80 is visually identical to 100 but 60-70% smaller
     * @var int
     */
    const JPEG_QUALITY = 80;

    /**
     * WebP compression quality (0-100)
     * @var int
     */
    const WEBP_QUALITY = 82;

    /**
     * Whether to convert images to WebP format
     * @var bool
     */
    const CONVERT_TO_WEBP = true;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->database = exclusive_listings()->get_database();

        // Hook into WordPress attachment deletion to clean up BME media records
        // This catches deletions from Media Library that bypass our delete_photo() method
        add_action('delete_attachment', array($this, 'cleanup_bme_media_on_attachment_delete'));
    }

    /**
     * Clean up BME media records when a WordPress attachment is deleted
     *
     * This catches the case where someone deletes an image from the WordPress
     * Media Library directly, which would leave orphaned records in wp_bme_media.
     *
     * @since 1.5.3
     * @param int $attachment_id WordPress attachment ID being deleted
     */
    public function cleanup_bme_media_on_attachment_delete($attachment_id) {
        // Get the attachment URL
        $attachment_url = wp_get_attachment_url($attachment_id);
        if (!$attachment_url) {
            return;
        }

        // Only process exclusive listing images
        if (strpos($attachment_url, '/exclusive-listings/') === false) {
            return;
        }

        $media_table = $this->database->get_bme_table('media');

        // Find and delete matching BME media record
        $deleted = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$media_table} WHERE media_url = %s",
            $attachment_url
        ));

        if ($deleted && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Exclusive Listings: Cleaned up BME media record for deleted attachment {$attachment_id}");
        }

        // Update photo counts for affected listings
        if ($deleted) {
            // Find the listing that was affected and update its photo count
            // We need to get the listing_id before the record is deleted
            // Since the record is already deleted, we'll update all exclusive listing photo counts
            $this->cleanup_orphaned_photo_counts();
        }
    }

    /**
     * Update photo counts for all exclusive listings
     *
     * @since 1.5.3
     */
    private function cleanup_orphaned_photo_counts() {
        $media_table = $this->database->get_bme_table('media');
        $summary_table = $this->database->get_bme_table('summary');

        // Update photo counts for all exclusive listings (listing_id < 1000000)
        $this->wpdb->query("
            UPDATE {$summary_table} s
            SET photo_count = (
                SELECT COUNT(*) FROM {$media_table} m WHERE m.listing_id = s.listing_id
            )
            WHERE s.listing_id < 1000000
        ");
    }

    /**
     * Upload a photo for a listing
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param array $file $_FILES array item
     * @param int|null $order Photo order (null = append at end)
     * @return array|WP_Error Photo data or error
     */
    public function upload_photo($listing_id, $file, $order = null) {
        // Validate file
        $validation = $this->validate_file($file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Check photo limit
        $current_count = $this->get_photo_count($listing_id);
        if ($current_count >= self::MAX_PHOTOS) {
            return new WP_Error(
                'photo_limit_exceeded',
                sprintf('Maximum of %d photos per listing', self::MAX_PHOTOS)
            );
        }

        // Get listing details for SEO metadata
        $listing_info = $this->get_listing_info($listing_id);

        // Determine photo number for this upload
        $photo_number = ($order !== null) ? $order : ($current_count + 1);

        // Generate SEO-friendly filename
        $seo_filename = $this->generate_seo_filename($listing_info, $photo_number, $file['name']);
        $file['name'] = $seo_filename;

        // Require WordPress file handling functions
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Set up custom upload directory
        add_filter('upload_dir', array($this, 'custom_upload_dir'));

        // Handle the upload
        $upload = wp_handle_upload($file, array(
            'test_form' => false,
            'mimes' => array(
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
            ),
        ));

        // Remove custom upload dir filter
        remove_filter('upload_dir', array($this, 'custom_upload_dir'));

        if (isset($upload['error'])) {
            return new WP_Error('upload_failed', $upload['error']);
        }

        // Optimize the uploaded image (resize and compress)
        $optimized = $this->optimize_image($upload['file'], $upload['type']);
        if (!is_wp_error($optimized)) {
            // Update upload info with optimized file
            if ($optimized['path'] !== $upload['file']) {
                $upload['file'] = $optimized['path'];
                $upload['type'] = $optimized['mime'];
                // Update URL to match new filename
                $upload['url'] = str_replace(
                    basename($upload['url']),
                    basename($optimized['path']),
                    $upload['url']
                );
            }
        }

        // Generate SEO-friendly title and metadata
        $seo_data = $this->generate_seo_metadata($listing_info, $photo_number);

        // Create attachment with SEO-optimized data
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title' => $seo_data['title'],
            'post_content' => $seo_data['description'],
            'post_excerpt' => $seo_data['caption'],
            'post_status' => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment, $upload['file']);

        if (is_wp_error($attachment_id)) {
            // Clean up uploaded file
            @unlink($upload['file']);
            return $attachment_id;
        }

        // Generate attachment metadata (responsive sizes)
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Set alt text for SEO
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $seo_data['alt_text']);

        // Link to exclusive listing via custom meta
        update_post_meta($attachment_id, '_exclusive_listing_id', $listing_id);
        update_post_meta($attachment_id, '_exclusive_listing_address', $listing_info['full_address']);

        // Determine order
        if ($order === null) {
            $order = $current_count + 1;
        }

        // Store in BME media table
        $media_data = $this->store_in_media_table($listing_id, $attachment_id, $upload['url'], $order);

        if (is_wp_error($media_data)) {
            // Clean up on failure
            wp_delete_attachment($attachment_id, true);
            return $media_data;
        }

        // Update summary table photo info
        $this->update_listing_photo_info($listing_id);

        return array(
            'id' => $media_data['id'],
            'attachment_id' => $attachment_id,
            'url' => $upload['url'],
            'order' => $order,
            'width' => $metadata['width'] ?? null,
            'height' => $metadata['height'] ?? null,
            'sizes' => $this->get_image_sizes($attachment_id),
        );
    }

    /**
     * Upload multiple photos
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param array $files Array of $_FILES items
     * @return array Results for each file (success or error)
     */
    public function upload_photos($listing_id, $files) {
        $results = array();
        $current_order = $this->get_photo_count($listing_id);

        foreach ($files as $file) {
            $current_order++;
            $result = $this->upload_photo($listing_id, $file, $current_order);

            $results[] = array(
                'filename' => $file['name'],
                'success' => !is_wp_error($result),
                'data' => is_wp_error($result) ? null : $result,
                'error' => is_wp_error($result) ? $result->get_error_message() : null,
            );
        }

        return $results;
    }

    /**
     * Delete a photo
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param int $photo_id Media table ID
     * @return bool|WP_Error True on success or error
     */
    public function delete_photo($listing_id, $photo_id) {
        $media_table = $this->database->get_bme_table('media');

        // Get the photo record
        $photo = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$media_table} WHERE id = %d AND listing_id = %s",
            $photo_id,
            $listing_id
        ));

        if (!$photo) {
            return new WP_Error('photo_not_found', 'Photo not found');
        }

        // Delete from media table
        $this->wpdb->delete($media_table, array('id' => $photo_id), array('%d'));

        // Try to find and delete the WordPress attachment
        // Match by URL to find attachment ID
        $attachment_id = attachment_url_to_postid($photo->media_url);
        if ($attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        // Re-order remaining photos
        $this->reorder_photos_after_delete($listing_id, $photo->order_index);

        // Update listing photo info
        $this->update_listing_photo_info($listing_id);

        return true;
    }

    /**
     * Reorder photos for a listing
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param array $photo_order Array of photo IDs in new order
     * @return bool|WP_Error True on success or error
     */
    public function reorder_photos($listing_id, $photo_order) {
        $media_table = $this->database->get_bme_table('media');

        $this->wpdb->query('START TRANSACTION');

        try {
            foreach ($photo_order as $index => $photo_id) {
                $new_order = $index + 1;

                $result = $this->wpdb->update(
                    $media_table,
                    array('order_index' => $new_order),
                    array(
                        'id' => $photo_id,
                        'listing_id' => $listing_id,
                    ),
                    array('%d'),
                    array('%d', '%s')
                );

                if ($result === false) {
                    throw new Exception($this->wpdb->last_error);
                }
            }

            $this->wpdb->query('COMMIT');

            // Update main photo in summary
            $this->update_listing_photo_info($listing_id);

            return true;

        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            return new WP_Error('reorder_failed', $e->getMessage());
        }
    }

    /**
     * Get all photos for a listing
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @return array Array of photo data
     */
    public function get_photos($listing_id) {
        $media_table = $this->database->get_bme_table('media');

        $photos = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$media_table}
             WHERE listing_id = %s AND media_category = 'Photo'
             ORDER BY order_index ASC",
            $listing_id
        ), ARRAY_A);

        // Add responsive size URLs
        foreach ($photos as &$photo) {
            $attachment_id = attachment_url_to_postid($photo['media_url']);
            if ($attachment_id) {
                $photo['sizes'] = $this->get_image_sizes($attachment_id);
            }
        }

        return $photos;
    }

    /**
     * Get photo count for a listing
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @return int Photo count
     */
    public function get_photo_count($listing_id) {
        $media_table = $this->database->get_bme_table('media');

        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$media_table}
             WHERE listing_id = %s AND media_category = 'Photo'",
            $listing_id
        ));

        return intval($count);
    }

    /**
     * Get main photo URL for a listing
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @return string|null Main photo URL or null
     */
    public function get_main_photo_url($listing_id) {
        $media_table = $this->database->get_bme_table('media');

        $url = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT media_url FROM {$media_table}
             WHERE listing_id = %s AND media_category = 'Photo'
             ORDER BY order_index ASC LIMIT 1",
            $listing_id
        ));

        return $url;
    }

    /**
     * Store photo in BME media table
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param int $attachment_id WordPress attachment ID
     * @param string $url Image URL
     * @param int $order Display order
     * @return array|WP_Error Inserted row data or error
     */
    private function store_in_media_table($listing_id, $attachment_id, $url, $order) {
        $media_table = $this->database->get_bme_table('media');

        // Generate a unique media key
        $media_key = md5($listing_id . '_' . $url . '_' . time());

        // Get listing_key from summary table
        $summary_table = $this->database->get_bme_table('summary');
        $listing_key = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT listing_key FROM {$summary_table} WHERE listing_id = %d",
            $listing_id
        ));

        if (!$listing_key) {
            $listing_key = md5('exclusive_' . $listing_id);
        }

        // Use correct column names matching wp_bme_media table structure
        $data = array(
            'listing_id' => $listing_id,
            'listing_key' => $listing_key,
            'media_key' => $media_key,
            'media_url' => $url,
            'media_category' => 'Photo',
            'order_index' => $order,
            'source_table' => 'active',
            'modification_timestamp' => current_time('mysql'),
        );

        $result = $this->wpdb->insert($media_table, $data);

        if ($result === false) {
            return new WP_Error('media_insert_failed', $this->wpdb->last_error);
        }

        $data['id'] = $this->wpdb->insert_id;
        // Map back to expected format for response
        $data['order'] = $order;
        return $data;
    }

    /**
     * Update listing photo info in summary table
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     */
    private function update_listing_photo_info($listing_id) {
        $main_url = $this->get_main_photo_url($listing_id);
        $count = $this->get_photo_count($listing_id);

        $bme_sync = new EL_BME_Sync();
        $bme_sync->update_photo_info($listing_id, $main_url, $count);
    }

    /**
     * Reorder photos after one is deleted
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @param int $deleted_order Order of deleted photo
     */
    private function reorder_photos_after_delete($listing_id, $deleted_order) {
        $media_table = $this->database->get_bme_table('media');

        // Decrease order of all photos after the deleted one
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$media_table}
             SET order_index = order_index - 1
             WHERE listing_id = %s
               AND media_category = 'Photo'
               AND order_index > %d",
            $listing_id,
            $deleted_order
        ));
    }

    /**
     * Validate uploaded file
     *
     * @since 1.0.0
     * @param array $file $_FILES item
     * @return true|WP_Error True if valid or error
     */
    private function validate_file($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = array(
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
            );

            return new WP_Error(
                'upload_error',
                $error_messages[$file['error']] ?? 'Unknown upload error'
            );
        }

        // Check file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new WP_Error(
                'file_too_large',
                sprintf('File size exceeds maximum of %d MB', self::MAX_FILE_SIZE / 1048576)
            );
        }

        // Check mime type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, self::ALLOWED_TYPES)) {
            return new WP_Error(
                'invalid_file_type',
                'Invalid file type. Allowed types: JPEG, PNG, GIF, WebP'
            );
        }

        return true;
    }

    /**
     * Optimize an image file - resize and compress
     *
     * @since 1.4.5
     * @param string $file_path Path to the image file
     * @param string $mime_type MIME type of the image
     * @return array|WP_Error Array with 'path' and 'mime' or error
     */
    private function optimize_image($file_path, $mime_type) {
        // Get image editor
        $editor = wp_get_image_editor($file_path);

        if (is_wp_error($editor)) {
            // If we can't edit, return original (still works, just not optimized)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Exclusive Listings: Image editor not available - ' . $editor->get_error_message());
            }
            return array('path' => $file_path, 'mime' => $mime_type);
        }

        $size = $editor->get_size();
        $width = $size['width'];
        $height = $size['height'];
        $needs_resize = ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION);

        if ($needs_resize) {
            $scale = self::MAX_DIMENSION / max($width, $height);
            $new_width = intval($width * $scale);
            $new_height = intval($height * $scale);

            $result = $editor->resize($new_width, $new_height, false);
            if (is_wp_error($result)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Exclusive Listings: Resize failed - ' . $result->get_error_message());
                }
                return array('path' => $file_path, 'mime' => $mime_type);
            }
        }

        $convert_to_webp = self::CONVERT_TO_WEBP && function_exists('imagewebp');
        $output_mime = $convert_to_webp ? 'image/webp' : $mime_type;
        $output_path = $convert_to_webp
            ? preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $file_path)
            : $file_path;

        $editor->set_quality($convert_to_webp ? self::WEBP_QUALITY : self::JPEG_QUALITY);

        $saved = $editor->save($output_path, $output_mime);

        if (is_wp_error($saved)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Exclusive Listings: Save failed - ' . $saved->get_error_message());
            }
            // Fall back to JPEG if WebP failed
            if ($output_mime === 'image/webp') {
                $editor->set_quality(self::JPEG_QUALITY);
                $saved = $editor->save($file_path, $mime_type);
                if (is_wp_error($saved)) {
                    return array('path' => $file_path, 'mime' => $mime_type);
                }
                return array('path' => $saved['path'], 'mime' => $saved['mime-type']);
            }
            return array('path' => $file_path, 'mime' => $mime_type);
        }

        $created_new_file = ($saved['path'] !== $file_path);

        if ($created_new_file && file_exists($file_path)) {
            @unlink($file_path);
        }

        return array(
            'path' => $saved['path'],
            'mime' => $saved['mime-type'],
        );
    }

    /**
     * Get responsive image sizes for an attachment
     *
     * @since 1.0.0
     * @param int $attachment_id WordPress attachment ID
     * @return array Array of size => url
     */
    private function get_image_sizes($attachment_id) {
        $sizes = array('thumbnail', 'medium', 'medium_large', 'large', 'full');
        $urls = array();

        foreach ($sizes as $size) {
            $src = wp_get_attachment_image_src($attachment_id, $size);
            if ($src) {
                $urls[$size] = $src[0];
            }
        }

        return $urls;
    }

    /**
     * Custom upload directory for exclusive listings
     *
     * @since 1.0.0
     * @param array $uploads WordPress upload dir array
     * @return array Modified upload dir array
     */
    public function custom_upload_dir($uploads) {
        $custom_dir = '/exclusive-listings/' . wp_date('Y') . '/' . wp_date('m');

        $uploads['subdir'] = $custom_dir;
        $uploads['path'] = $uploads['basedir'] . $custom_dir;
        $uploads['url'] = $uploads['baseurl'] . $custom_dir;

        // Create directory if it doesn't exist
        if (!file_exists($uploads['path'])) {
            wp_mkdir_p($uploads['path']);
        }

        return $uploads;
    }

    /**
     * Get listing information for SEO metadata
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @return array Listing info with address components
     */
    private function get_listing_info($listing_id) {
        $summary_table = $this->database->get_bme_table('summary');

        $listing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT street_number, street_name, unit_number, city, state_or_province, postal_code,
                    property_type, list_price, bedrooms_total, bathrooms_total
             FROM {$summary_table}
             WHERE listing_id = %d",
            $listing_id
        ));

        if (!$listing) {
            return array(
                'full_address' => 'Exclusive Listing ' . $listing_id,
                'street_address' => '',
                'city' => '',
                'state' => '',
                'property_type' => 'Property',
                'price' => '',
                'beds' => '',
                'baths' => '',
            );
        }

        // Build street address
        $street_parts = array_filter(array(
            $listing->street_number,
            $listing->street_name,
        ));
        $street_address = implode(' ', $street_parts);

        if (!empty($listing->unit_number)) {
            $street_address .= ' Unit ' . $listing->unit_number;
        }

        // Build full address
        $full_address = $street_address;
        if (!empty($listing->city)) {
            $full_address .= ', ' . $listing->city;
        }
        if (!empty($listing->state_or_province)) {
            $full_address .= ', ' . $listing->state_or_province;
        }

        // Format price
        $price = '';
        if (!empty($listing->list_price)) {
            $price = '$' . number_format($listing->list_price);
        }

        return array(
            'full_address' => $full_address,
            'street_address' => $street_address,
            'city' => $listing->city ?: '',
            'state' => $listing->state_or_province ?: '',
            'property_type' => $listing->property_type ?: 'Property',
            'price' => $price,
            'beds' => $listing->bedrooms_total ? $listing->bedrooms_total . ' bed' : '',
            'baths' => $listing->bathrooms_total ? $listing->bathrooms_total . ' bath' : '',
        );
    }

    /**
     * Generate SEO-friendly filename for a photo
     *
     * @since 1.0.0
     * @param array $listing_info Listing information
     * @param int $photo_number Photo number in sequence
     * @param string $original_filename Original uploaded filename
     * @return string SEO-friendly filename
     */
    private function generate_seo_filename($listing_info, $photo_number, $original_filename) {
        // Get file extension from original filename
        $extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        if (empty($extension)) {
            $extension = 'jpg';
        }

        // Build address slug: "123-main-st-boston-ma"
        $address_parts = array();

        if (!empty($listing_info['street_address'])) {
            $address_parts[] = $listing_info['street_address'];
        }
        if (!empty($listing_info['city'])) {
            $address_parts[] = $listing_info['city'];
        }
        if (!empty($listing_info['state'])) {
            $address_parts[] = $listing_info['state'];
        }

        $address_string = implode(' ', $address_parts);

        // Convert to URL-friendly slug
        $slug = sanitize_title($address_string);

        // If slug is empty, use listing ID
        if (empty($slug)) {
            $slug = 'exclusive-listing';
        }

        // Create filename: "123-main-st-boston-ma-photo-1.jpg"
        $filename = $slug . '-photo-' . $photo_number . '.' . $extension;

        return $filename;
    }

    /**
     * Generate SEO-friendly metadata for a photo
     *
     * @since 1.0.0
     * @param array $listing_info Listing information
     * @param int $photo_number Photo number in sequence
     * @return array SEO metadata (title, alt_text, caption, description)
     */
    private function generate_seo_metadata($listing_info, $photo_number) {
        $address = $listing_info['full_address'];
        $street = $listing_info['street_address'];
        $city = $listing_info['city'];
        $property_type = $listing_info['property_type'];
        $price = $listing_info['price'];
        $beds = $listing_info['beds'];
        $baths = $listing_info['baths'];

        // Title: "123 Main St Boston MA - Photo 1"
        $title = $address . ' - Photo ' . $photo_number;

        // Alt text: "123 Main St Boston - Residential property for sale"
        $alt_parts = array($street ?: $address);
        if (!empty($city)) {
            $alt_parts[0] .= ' ' . $city;
        }
        $alt_parts[] = $property_type . ' for sale';
        $alt_text = implode(' - ', $alt_parts);

        // Caption: "Photo 1 of 123 Main St, Boston"
        $caption = 'Photo ' . $photo_number . ' of ' . ($street ?: $address);
        if (!empty($city)) {
            $caption .= ', ' . $city;
        }

        // Description: Full details for SEO
        $desc_parts = array(
            'Photo ' . $photo_number . ' of ' . $address,
        );
        if (!empty($property_type)) {
            $desc_parts[] = $property_type . ' listing';
        }
        if (!empty($price)) {
            $desc_parts[] = 'Listed at ' . $price;
        }
        $features = array_filter(array($beds, $baths));
        if (!empty($features)) {
            $desc_parts[] = implode(', ', $features);
        }
        $desc_parts[] = 'Exclusive listing by BMN Boston Real Estate';

        $description = implode('. ', $desc_parts) . '.';

        return array(
            'title' => sanitize_text_field($title),
            'alt_text' => sanitize_text_field($alt_text),
            'caption' => sanitize_text_field($caption),
            'description' => sanitize_textarea_field($description),
        );
    }

    /**
     * Delete all photos for a listing
     *
     * @since 1.0.0
     * @param int $listing_id Listing ID
     * @return int Number of photos deleted
     */
    public function delete_all_photos($listing_id) {
        $photos = $this->get_photos($listing_id);
        $deleted = 0;

        foreach ($photos as $photo) {
            $result = $this->delete_photo($listing_id, $photo['id']);
            if (!is_wp_error($result)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Optimize all existing exclusive listing photos
     *
     * @since 1.4.5
     * @param int $batch_size Number of photos to process per batch
     * @param int $offset Starting offset for pagination
     * @return array Results with counts and details
     */
    public function optimize_existing_photos($batch_size = 10, $offset = 0) {
        $media_table = $this->database->get_bme_table('media');
        $upload_dir = wp_upload_dir();

        // Get exclusive listing photos that haven't been optimized
        // (identified by non-WebP format and file size > 200KB threshold)
        $photos = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, listing_id, media_url, media_key
             FROM {$media_table}
             WHERE listing_id < %d
               AND media_category = 'Photo'
               AND media_url NOT LIKE '%%.webp'
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            EL_EXCLUSIVE_ID_THRESHOLD,
            $batch_size,
            $offset
        ), ARRAY_A);

        $results = array(
            'processed' => 0,
            'optimized' => 0,
            'skipped' => 0,
            'errors' => 0,
            'space_saved' => 0,
            'details' => array(),
        );

        foreach ($photos as $photo) {
            $result = $this->optimize_single_photo($photo, $upload_dir);
            $results['processed']++;
            $results['details'][] = $result;

            switch ($result['status']) {
                case 'optimized':
                    $results['optimized']++;
                    $results['space_saved'] += $result['saved_bytes'];
                    break;
                case 'skipped':
                    $results['skipped']++;
                    break;
                default:
                    $results['errors']++;
            }
        }

        // Get total remaining count
        $results['remaining'] = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$media_table}
             WHERE listing_id < %d
               AND media_category = 'Photo'
               AND media_url NOT LIKE '%%.webp'",
            EL_EXCLUSIVE_ID_THRESHOLD
        )) - $results['processed'];

        return $results;
    }

    /**
     * Optimize a single existing photo
     *
     * @since 1.4.5
     * @param array $photo Photo data from database
     * @param array $upload_dir WordPress upload directory info
     * @return array Result with status and details
     */
    private function optimize_single_photo($photo, $upload_dir) {
        $media_table = $this->database->get_bme_table('media');

        // Convert URL to file path
        $file_path = str_replace(
            $upload_dir['baseurl'],
            $upload_dir['basedir'],
            $photo['media_url']
        );

        // Check if file exists
        if (!file_exists($file_path)) {
            return array(
                'status' => 'error',
                'photo_id' => $photo['id'],
                'message' => 'File not found: ' . $file_path,
            );
        }

        // Get original file size
        $original_size = filesize($file_path);

        // Skip if already small (under 150KB)
        if ($original_size < 150000) {
            return array(
                'status' => 'skipped',
                'photo_id' => $photo['id'],
                'message' => 'Already optimized (under 150KB)',
                'original_size' => $original_size,
            );
        }

        // Get mime type
        $mime_type = mime_content_type($file_path);

        // Optimize the image
        $optimized = $this->optimize_image($file_path, $mime_type);

        if (is_wp_error($optimized)) {
            return array(
                'status' => 'error',
                'photo_id' => $photo['id'],
                'message' => $optimized->get_error_message(),
            );
        }

        // Calculate new URL
        $new_url = str_replace(
            $upload_dir['basedir'],
            $upload_dir['baseurl'],
            $optimized['path']
        );

        // Get new file size
        $new_size = filesize($optimized['path']);
        $saved_bytes = $original_size - $new_size;

        // Update database with new URL
        $this->wpdb->update(
            $media_table,
            array(
                'media_url' => $new_url,
                'modification_timestamp' => current_time('mysql'),
            ),
            array('id' => $photo['id']),
            array('%s', '%s'),
            array('%d')
        );

        // Update summary table if this was the main photo
        $this->update_listing_photo_info($photo['listing_id']);

        // Also update WordPress attachment if it exists
        $attachment_id = attachment_url_to_postid($photo['media_url']);
        if ($attachment_id) {
            // Update attachment file
            update_attached_file($attachment_id, $optimized['path']);

            // Update attachment post mime type
            wp_update_post(array(
                'ID' => $attachment_id,
                'post_mime_type' => $optimized['mime'],
            ));

            // Regenerate metadata
            $metadata = wp_generate_attachment_metadata($attachment_id, $optimized['path']);
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        return array(
            'status' => 'optimized',
            'photo_id' => $photo['id'],
            'listing_id' => $photo['listing_id'],
            'original_url' => $photo['media_url'],
            'new_url' => $new_url,
            'original_size' => $original_size,
            'new_size' => $new_size,
            'saved_bytes' => $saved_bytes,
            'reduction_percent' => round(($saved_bytes / $original_size) * 100),
        );
    }

    /**
     * Get optimization statistics for exclusive listing photos
     *
     * @since 1.4.5
     * @return array Statistics
     */
    public function get_optimization_stats() {
        $media_table = $this->database->get_bme_table('media');

        $stats = array();

        // Total exclusive listing photos
        $stats['total_photos'] = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$media_table}
             WHERE listing_id < %d AND media_category = 'Photo'",
            EL_EXCLUSIVE_ID_THRESHOLD
        ));

        // Already WebP (optimized)
        $stats['webp_photos'] = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$media_table}
             WHERE listing_id < %d AND media_category = 'Photo'
               AND media_url LIKE '%%.webp'",
            EL_EXCLUSIVE_ID_THRESHOLD
        ));

        // Non-WebP (need optimization)
        $stats['non_webp_photos'] = $stats['total_photos'] - $stats['webp_photos'];

        // Percentage optimized
        $stats['percent_optimized'] = $stats['total_photos'] > 0
            ? round(($stats['webp_photos'] / $stats['total_photos']) * 100)
            : 0;

        return $stats;
    }

    /**
     * Find and remove orphaned media records (file deleted but record remains)
     *
     * This checks if the image files actually exist on the server and removes
     * records where the file has been deleted.
     *
     * @since 1.5.3
     * @param int|null $listing_id Optional specific listing to check, or null for all exclusive listings
     * @return array Results with 'checked', 'orphaned', and 'cleaned' counts
     */
    public function cleanup_orphaned_media_records($listing_id = null) {
        $media_table = $this->database->get_bme_table('media');
        $results = array(
            'checked' => 0,
            'orphaned' => 0,
            'cleaned' => 0,
            'errors' => array(),
        );

        // Build query for exclusive listing media
        if ($listing_id) {
            $records = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, listing_id, media_url FROM {$media_table} WHERE listing_id = %s",
                $listing_id
            ));
        } else {
            $records = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, listing_id, media_url FROM {$media_table} WHERE listing_id < %d",
                EL_EXCLUSIVE_ID_THRESHOLD
            ));
        }

        $results['checked'] = count($records);
        $affected_listings = array();

        foreach ($records as $record) {
            // Check if file exists by making a HEAD request
            $response = wp_remote_head($record->media_url, array('timeout' => 5));

            if (is_wp_error($response)) {
                $results['errors'][] = "Error checking {$record->media_url}: " . $response->get_error_message();
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code === 404) {
                // File doesn't exist - remove the orphaned record
                $deleted = $this->wpdb->delete($media_table, array('id' => $record->id), array('%d'));
                if ($deleted) {
                    $results['orphaned']++;
                    $results['cleaned']++;
                    $affected_listings[$record->listing_id] = true;

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Exclusive Listings: Removed orphaned media record {$record->id} for missing file: {$record->media_url}");
                    }
                }
            }
        }

        // Update photo counts for affected listings
        foreach (array_keys($affected_listings) as $affected_id) {
            $this->update_listing_photo_info($affected_id);
        }

        return $results;
    }
}
