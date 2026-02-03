<?php
/**
 * Contact Form File Upload Handler
 *
 * Handles secure file uploads for contact forms with validation,
 * MIME type checking, and WordPress media library integration.
 *
 * @package MLS_Listings_Display
 * @since 6.24.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MLD_Contact_Form_Upload
 *
 * Manages file uploads for contact forms.
 */
class MLD_Contact_Form_Upload {

    /**
     * Singleton instance
     *
     * @var MLD_Contact_Form_Upload|null
     */
    private static $instance = null;

    /**
     * Table name for uploads
     *
     * @var string
     */
    private $table_name;

    /**
     * Allowed MIME types with their file extensions
     *
     * @var array
     */
    private $allowed_mime_types = [
        // Images
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        // Documents
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        // Text
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
    ];

    /**
     * Default max file size in MB
     *
     * @var int
     */
    private $default_max_size_mb = 5;

    /**
     * Default max files per field
     *
     * @var int
     */
    private $default_max_files = 3;

    /**
     * Get singleton instance
     *
     * @return MLD_Contact_Form_Upload
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mld_form_uploads';
    }

    /**
     * Initialize hooks
     */
    public static function init() {
        $instance = self::get_instance();

        // AJAX handlers
        add_action('wp_ajax_mld_upload_file', [$instance, 'handle_upload']);
        add_action('wp_ajax_nopriv_mld_upload_file', [$instance, 'handle_upload']);
        add_action('wp_ajax_mld_remove_upload', [$instance, 'handle_remove_upload']);
        add_action('wp_ajax_nopriv_mld_remove_upload', [$instance, 'handle_remove_upload']);
    }

    /**
     * Handle file upload AJAX request
     */
    public function handle_upload() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mld_contact_form_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'mls-listings-display')]);
        }

        // Check if file was uploaded
        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => __('No file was uploaded.', 'mls-listings-display')]);
        }

        $file = $_FILES['file'];
        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        $field_id = isset($_POST['field_id']) ? sanitize_text_field($_POST['field_id']) : '';

        if (!$form_id || !$field_id) {
            wp_send_json_error(['message' => __('Invalid form or field.', 'mls-listings-display')]);
        }

        // Get field configuration
        $field_config = $this->get_field_config($form_id, $field_id);
        if (!$field_config) {
            wp_send_json_error(['message' => __('Field configuration not found.', 'mls-listings-display')]);
        }

        // Validate the file
        $validation = $this->validate_file($file, $field_config);
        if (is_wp_error($validation)) {
            wp_send_json_error(['message' => $validation->get_error_message()]);
        }

        // Check max files limit
        $current_count = $this->get_pending_upload_count($form_id, $field_id);
        $max_files = isset($field_config['file_config']['max_files'])
            ? intval($field_config['file_config']['max_files'])
            : $this->default_max_files;

        if ($current_count >= $max_files) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Maximum of %d files allowed for this field.', 'mls-listings-display'),
                    $max_files
                )
            ]);
        }

        // Process the upload
        $result = $this->process_upload($file, $form_id, $field_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'upload_id' => $result['id'],
            'file_name' => $result['file_name'],
            'file_size' => $result['file_size'],
            'file_size_formatted' => size_format($result['file_size']),
            'file_type' => $result['file_type'],
            'upload_token' => $result['upload_token'],
            'thumbnail_url' => $result['thumbnail_url'] ?? null,
        ]);
    }

    /**
     * Handle remove upload AJAX request
     */
    public function handle_remove_upload() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mld_contact_form_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'mls-listings-display')]);
        }

        $upload_id = isset($_POST['upload_id']) ? absint($_POST['upload_id']) : 0;
        $upload_token = isset($_POST['upload_token']) ? sanitize_text_field($_POST['upload_token']) : '';

        if (!$upload_id || !$upload_token) {
            wp_send_json_error(['message' => __('Invalid upload reference.', 'mls-listings-display')]);
        }

        // Verify token matches
        $upload = $this->get_upload($upload_id);
        if (!$upload || $upload->upload_token !== $upload_token) {
            wp_send_json_error(['message' => __('Upload not found or access denied.', 'mls-listings-display')]);
        }

        // Only allow removal of pending uploads (no submission_id yet)
        if (!empty($upload->submission_id)) {
            wp_send_json_error(['message' => __('Cannot remove submitted files.', 'mls-listings-display')]);
        }

        // Delete the upload
        $result = $this->delete_upload($upload_id);
        if (!$result) {
            wp_send_json_error(['message' => __('Failed to remove file.', 'mls-listings-display')]);
        }

        wp_send_json_success(['message' => __('File removed.', 'mls-listings-display')]);
    }

    /**
     * Validate a file upload
     *
     * @param array $file PHP $_FILES array element
     * @param array $field_config Field configuration
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public function validate_file(array $file, array $field_config) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', $this->get_upload_error_message($file['error']));
        }

        // Get file configuration
        $file_config = isset($field_config['file_config']) ? $field_config['file_config'] : [];
        $allowed_types = isset($file_config['allowed_types']) ? $file_config['allowed_types'] : array_keys($this->allowed_mime_types);
        $max_size_mb = isset($file_config['max_size_mb']) ? floatval($file_config['max_size_mb']) : $this->default_max_size_mb;

        // Check file size
        $max_size_bytes = $max_size_mb * 1024 * 1024;
        if ($file['size'] > $max_size_bytes) {
            return new WP_Error('file_too_large', sprintf(
                __('File size exceeds the maximum limit of %s.', 'mls-listings-display'),
                size_format($max_size_bytes)
            ));
        }

        // Get file extension
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Check allowed extensions
        if (!in_array($file_ext, $allowed_types)) {
            return new WP_Error('invalid_type', sprintf(
                __('File type not allowed. Allowed types: %s', 'mls-listings-display'),
                implode(', ', $allowed_types)
            ));
        }

        // Validate MIME type
        $mime_validation = $this->validate_mime_type($file['tmp_name'], $file_ext);
        if (is_wp_error($mime_validation)) {
            return $mime_validation;
        }

        return true;
    }

    /**
     * Validate MIME type using magic bytes
     *
     * @param string $file_path Path to the file
     * @param string $expected_ext Expected file extension
     * @return true|WP_Error
     */
    private function validate_mime_type($file_path, $expected_ext) {
        // Use WordPress's built-in file type check
        $wp_filetype = wp_check_filetype_and_ext($file_path, 'file.' . $expected_ext);

        // If WordPress can't determine the type, use finfo
        if (empty($wp_filetype['type'])) {
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detected_type = finfo_file($finfo, $file_path);
                finfo_close($finfo);

                // Get expected MIME type
                $expected_mime = isset($this->allowed_mime_types[$expected_ext])
                    ? $this->allowed_mime_types[$expected_ext]
                    : null;

                if ($expected_mime && $detected_type !== $expected_mime) {
                    // Allow some flexibility for text files
                    if ($expected_ext === 'csv' && $detected_type === 'text/plain') {
                        return true;
                    }

                    return new WP_Error('mime_mismatch', __('File type does not match its extension.', 'mls-listings-display'));
                }
            }
        }

        return true;
    }

    /**
     * Process and store an uploaded file
     *
     * @param array  $file     PHP $_FILES array element
     * @param int    $form_id  Form ID
     * @param string $field_id Field ID
     * @return array|WP_Error Upload data on success, WP_Error on failure
     */
    private function process_upload(array $file, int $form_id, string $field_id) {
        global $wpdb;

        // Generate unique filename
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $unique_name = wp_generate_uuid4() . '.' . $file_ext;

        // Get upload directory
        $upload_dir = wp_upload_dir();
        $mld_upload_dir = $upload_dir['basedir'] . '/mld-form-uploads/' . date('Y/m');

        // Create directory if it doesn't exist
        if (!file_exists($mld_upload_dir)) {
            wp_mkdir_p($mld_upload_dir);

            // Add .htaccess to prevent direct access
            $htaccess_path = $upload_dir['basedir'] . '/mld-form-uploads/.htaccess';
            if (!file_exists($htaccess_path)) {
                file_put_contents($htaccess_path, "Options -Indexes\n");
            }

            // Add index.php for extra protection
            $index_path = $upload_dir['basedir'] . '/mld-form-uploads/index.php';
            if (!file_exists($index_path)) {
                file_put_contents($index_path, "<?php\n// Silence is golden.\n");
            }
        }

        $file_path = $mld_upload_dir . '/' . $unique_name;
        $relative_path = 'mld-form-uploads/' . date('Y/m') . '/' . $unique_name;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            return new WP_Error('move_failed', __('Failed to save uploaded file.', 'mls-listings-display'));
        }

        // Set proper file permissions
        chmod($file_path, 0644);

        // Generate upload token for security
        $upload_token = wp_generate_password(64, false);

        // Insert into database
        $result = $wpdb->insert(
            $this->table_name,
            [
                'submission_id' => null, // Will be set when form is submitted
                'form_id' => $form_id,
                'field_id' => $field_id,
                'file_name' => sanitize_file_name($file['name']),
                'file_path' => $relative_path,
                'file_type' => $file_ext,
                'file_size' => $file['size'],
                'attachment_id' => null,
                'upload_token' => $upload_token,
                'uploaded_by' => get_current_user_id() ?: null,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s']
        );

        if ($result === false) {
            // Clean up file
            @unlink($file_path);
            return new WP_Error('db_error', __('Failed to record upload.', 'mls-listings-display'));
        }

        $upload_id = $wpdb->insert_id;

        // Generate thumbnail URL for images
        $thumbnail_url = null;
        if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $thumbnail_url = $upload_dir['baseurl'] . '/' . $relative_path;
        }

        return [
            'id' => $upload_id,
            'file_name' => sanitize_file_name($file['name']),
            'file_size' => $file['size'],
            'file_type' => $file_ext,
            'upload_token' => $upload_token,
            'thumbnail_url' => $thumbnail_url,
        ];
    }

    /**
     * Get field configuration from form
     *
     * @param int    $form_id  Form ID
     * @param string $field_id Field ID
     * @return array|null Field config or null if not found
     */
    private function get_field_config(int $form_id, string $field_id) {
        $manager = MLD_Contact_Form_Manager::get_instance();
        $form = $manager->get_form($form_id);

        if (!$form || !isset($form->fields['fields'])) {
            return null;
        }

        foreach ($form->fields['fields'] as $field) {
            if ($field['id'] === $field_id && $field['type'] === 'file') {
                return $field;
            }
        }

        return null;
    }

    /**
     * Get pending upload count for a field
     *
     * @param int    $form_id  Form ID
     * @param string $field_id Field ID
     * @return int Count
     */
    private function get_pending_upload_count(int $form_id, string $field_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
             WHERE form_id = %d AND field_id = %s AND submission_id IS NULL
             AND created_at > DATE_SUB(%s, INTERVAL 24 HOUR)",
            $form_id,
            $field_id,
            current_time('mysql')
        ));
    }

    /**
     * Get an upload record
     *
     * @param int $upload_id Upload ID
     * @return object|null
     */
    public function get_upload(int $upload_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $upload_id
        ));
    }

    /**
     * Delete an upload and its file
     *
     * @param int $upload_id Upload ID
     * @return bool
     */
    public function delete_upload(int $upload_id) {
        global $wpdb;

        $upload = $this->get_upload($upload_id);
        if (!$upload) {
            return false;
        }

        // Delete the file
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $upload->file_path;
        if (file_exists($file_path)) {
            @unlink($file_path);
        }

        // Delete database record
        return $wpdb->delete($this->table_name, ['id' => $upload_id], ['%d']) !== false;
    }

    /**
     * Link pending uploads to a submission
     *
     * @param int    $form_id       Form ID
     * @param int    $submission_id Submission ID
     * @param array  $upload_tokens Array of upload tokens from form submission
     * @return int Number of uploads linked
     */
    public function link_uploads_to_submission(int $form_id, int $submission_id, array $upload_tokens) {
        global $wpdb;

        if (empty($upload_tokens)) {
            return 0;
        }

        $linked = 0;
        foreach ($upload_tokens as $token) {
            $result = $wpdb->update(
                $this->table_name,
                ['submission_id' => $submission_id],
                [
                    'form_id' => $form_id,
                    'upload_token' => sanitize_text_field($token),
                    'submission_id' => null,
                ],
                ['%d'],
                ['%d', '%s', '%d']
            );

            if ($result) {
                $linked++;
            }
        }

        return $linked;
    }

    /**
     * Get uploads for a submission
     *
     * @param int $submission_id Submission ID
     * @return array
     */
    public function get_submission_uploads(int $submission_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE submission_id = %d ORDER BY created_at ASC",
            $submission_id
        ));
    }

    /**
     * Get uploads for a specific field in a submission
     *
     * @param int    $submission_id Submission ID
     * @param string $field_id      Field ID
     * @return array
     */
    public function get_field_uploads(int $submission_id, string $field_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE submission_id = %d AND field_id = %s ORDER BY created_at ASC",
            $submission_id,
            $field_id
        ));
    }

    /**
     * Get upload URL
     *
     * @param object $upload Upload record
     * @return string
     */
    public function get_upload_url($upload) {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/' . $upload->file_path;
    }

    /**
     * Clean up orphaned uploads (older than 24 hours without submission)
     *
     * @return int Number of uploads deleted
     */
    public function cleanup_orphaned_uploads() {
        global $wpdb;

        // Get orphaned uploads
        $orphans = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE submission_id IS NULL
             AND created_at < DATE_SUB(%s, INTERVAL 24 HOUR)",
            current_time('mysql')
        ));

        $deleted = 0;
        foreach ($orphans as $upload) {
            if ($this->delete_upload($upload->id)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get human-readable upload error message
     *
     * @param int $error_code PHP upload error code
     * @return string
     */
    private function get_upload_error_message(int $error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('The file is too large.', 'mls-listings-display');
            case UPLOAD_ERR_PARTIAL:
                return __('The file was only partially uploaded.', 'mls-listings-display');
            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded.', 'mls-listings-display');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Server configuration error: Missing temporary folder.', 'mls-listings-display');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Server configuration error: Failed to write file.', 'mls-listings-display');
            case UPLOAD_ERR_EXTENSION:
                return __('File upload stopped by extension.', 'mls-listings-display');
            default:
                return __('Unknown upload error.', 'mls-listings-display');
        }
    }

    /**
     * Get allowed file types for display
     *
     * @param array $allowed_types Array of allowed extensions
     * @return string Comma-separated list
     */
    public function get_allowed_types_display(array $allowed_types = []) {
        if (empty($allowed_types)) {
            $allowed_types = array_keys($this->allowed_mime_types);
        }
        return implode(', ', array_map('strtoupper', $allowed_types));
    }

    /**
     * Get accept attribute for file input
     *
     * @param array $allowed_types Array of allowed extensions
     * @return string Accept attribute value
     */
    public function get_accept_attribute(array $allowed_types = []) {
        if (empty($allowed_types)) {
            $allowed_types = array_keys($this->allowed_mime_types);
        }

        $accept = [];
        foreach ($allowed_types as $ext) {
            $accept[] = '.' . $ext;
            if (isset($this->allowed_mime_types[$ext])) {
                $accept[] = $this->allowed_mime_types[$ext];
            }
        }

        return implode(',', array_unique($accept));
    }
}
