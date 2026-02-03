<?php
/**
 * Custom Post Types Class
 *
 * Registers Team Members and Testimonials custom post types
 *
 * @package flavor_flavor_flavor
 * @version 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNE_Custom_Post_Types {

    /**
     * Initialize custom post types
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'register_team_member_cpt'));
        add_action('init', array(__CLASS__, 'register_testimonial_cpt'));
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));
        add_action('save_post', array(__CLASS__, 'save_meta_boxes'));
        add_filter('manage_bne_team_member_posts_columns', array(__CLASS__, 'team_member_columns'));
        add_action('manage_bne_team_member_posts_custom_column', array(__CLASS__, 'team_member_column_content'), 10, 2);
        add_filter('manage_bne_testimonial_posts_columns', array(__CLASS__, 'testimonial_columns'));
        add_action('manage_bne_testimonial_posts_custom_column', array(__CLASS__, 'testimonial_column_content'), 10, 2);
    }

    /**
     * Register Team Member CPT
     */
    public static function register_team_member_cpt() {
        $labels = array(
            'name'               => __('Team Members', 'flavor-flavor-flavor'),
            'singular_name'      => __('Team Member', 'flavor-flavor-flavor'),
            'menu_name'          => __('Team', 'flavor-flavor-flavor'),
            'add_new'            => __('Add New Member', 'flavor-flavor-flavor'),
            'add_new_item'       => __('Add New Team Member', 'flavor-flavor-flavor'),
            'edit_item'          => __('Edit Team Member', 'flavor-flavor-flavor'),
            'new_item'           => __('New Team Member', 'flavor-flavor-flavor'),
            'view_item'          => __('View Team Member', 'flavor-flavor-flavor'),
            'search_items'       => __('Search Team Members', 'flavor-flavor-flavor'),
            'not_found'          => __('No team members found', 'flavor-flavor-flavor'),
            'not_found_in_trash' => __('No team members in trash', 'flavor-flavor-flavor'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'team'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-groups',
            'supports'           => array('title', 'editor', 'thumbnail'),
            'show_in_rest'       => true,
        );

        register_post_type('bne_team_member', $args);
    }

    /**
     * Register Testimonial CPT
     */
    public static function register_testimonial_cpt() {
        $labels = array(
            'name'               => __('Testimonials', 'flavor-flavor-flavor'),
            'singular_name'      => __('Testimonial', 'flavor-flavor-flavor'),
            'menu_name'          => __('Testimonials', 'flavor-flavor-flavor'),
            'add_new'            => __('Add New Testimonial', 'flavor-flavor-flavor'),
            'add_new_item'       => __('Add New Testimonial', 'flavor-flavor-flavor'),
            'edit_item'          => __('Edit Testimonial', 'flavor-flavor-flavor'),
            'new_item'           => __('New Testimonial', 'flavor-flavor-flavor'),
            'view_item'          => __('View Testimonial', 'flavor-flavor-flavor'),
            'search_items'       => __('Search Testimonials', 'flavor-flavor-flavor'),
            'not_found'          => __('No testimonials found', 'flavor-flavor-flavor'),
            'not_found_in_trash' => __('No testimonials in trash', 'flavor-flavor-flavor'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'testimonials'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 26,
            'menu_icon'          => 'dashicons-format-quote',
            'supports'           => array('title', 'editor', 'thumbnail'),
            'show_in_rest'       => true,
        );

        register_post_type('bne_testimonial', $args);
    }

    /**
     * Add meta boxes for CPTs
     */
    public static function add_meta_boxes() {
        // Team Member meta box
        add_meta_box(
            'bne_team_member_details',
            __('Team Member Details', 'flavor-flavor-flavor'),
            array(__CLASS__, 'render_team_member_meta_box'),
            'bne_team_member',
            'normal',
            'high'
        );

        // Testimonial meta box
        add_meta_box(
            'bne_testimonial_details',
            __('Testimonial Details', 'flavor-flavor-flavor'),
            array(__CLASS__, 'render_testimonial_meta_box'),
            'bne_testimonial',
            'normal',
            'high'
        );
    }

    /**
     * Render Team Member meta box
     */
    public static function render_team_member_meta_box($post) {
        wp_nonce_field('bne_team_member_meta', 'bne_team_member_nonce');

        $position = get_post_meta($post->ID, '_bne_position', true);
        $license_number = get_post_meta($post->ID, '_bne_license_number', true);
        $mls_agent_id = get_post_meta($post->ID, '_bne_mls_agent_id', true);
        $email = get_post_meta($post->ID, '_bne_email', true);
        $phone = get_post_meta($post->ID, '_bne_phone', true);
        $instagram = get_post_meta($post->ID, '_bne_instagram', true);
        $facebook = get_post_meta($post->ID, '_bne_facebook', true);
        $linkedin = get_post_meta($post->ID, '_bne_linkedin', true);
        $display_order = get_post_meta($post->ID, '_bne_display_order', true);
        $linked_user_id = get_post_meta($post->ID, '_bne_user_id', true);

        // Get potential users (admins, editors, authors)
        $potential_users = get_users(array(
            'role__in' => array('administrator', 'editor', 'author'),
            'orderby' => 'display_name',
            'order' => 'ASC',
        ));

        // Get client count if linked to user and MLD plugin is active
        $client_count = 0;
        if ($linked_user_id && class_exists('MLD_Agent_Client_Manager')) {
            $clients = MLD_Agent_Client_Manager::get_agent_clients($linked_user_id, 'active');
            $client_count = count($clients);
        }
        ?>
        <table class="form-table">
            <tr>
                <th colspan="2" style="padding-bottom: 0;">
                    <h3 style="margin: 0; padding: 0; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                        <?php _e('Agent System Integration', 'flavor-flavor-flavor'); ?>
                    </h3>
                </th>
            </tr>
            <tr>
                <th><label for="bne_user_id"><?php _e('Link to WordPress User', 'flavor-flavor-flavor'); ?></label></th>
                <td>
                    <select id="bne_user_id" name="bne_user_id" class="regular-text">
                        <option value=""><?php _e('-- Not Linked (Website Display Only) --', 'flavor-flavor-flavor'); ?></option>
                        <?php foreach ($potential_users as $user) : ?>
                            <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($linked_user_id, $user->ID); ?>>
                                <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_email); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php _e('Link this team member to a WordPress user account to enable client management features. This will sync their profile to the agent system.', 'flavor-flavor-flavor'); ?>
                    </p>
                    <?php if ($linked_user_id) : ?>
                        <div style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-radius: 4px;">
                            <strong><?php _e('Agent Status:', 'flavor-flavor-flavor'); ?></strong>
                            <span style="color: #00a32a;">&#10003; <?php _e('Synced to Agent System', 'flavor-flavor-flavor'); ?></span>
                            <?php if ($client_count > 0) : ?>
                                <br>
                                <strong><?php _e('Assigned Clients:', 'flavor-flavor-flavor'); ?></strong>
                                <?php echo esc_html($client_count); ?>
                                <?php if (class_exists('MLD_Agent_Client_Manager')) : ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=mld-agent-management&agent_id=' . $linked_user_id)); ?>" style="margin-left: 10px;">
                                        <?php _e('Manage Clients &rarr;', 'flavor-flavor-flavor'); ?>
                                    </a>
                                <?php endif; ?>
                            <?php else : ?>
                                <br>
                                <strong><?php _e('Assigned Clients:', 'flavor-flavor-flavor'); ?></strong>
                                <?php _e('None yet', 'flavor-flavor-flavor'); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th colspan="2" style="padding-bottom: 0;">
                    <h3 style="margin: 0; padding: 15px 0 10px 0; border-bottom: 1px solid #ddd;">
                        <?php _e('Profile Information', 'flavor-flavor-flavor'); ?>
                    </h3>
                </th>
            </tr>
            <tr>
                <th><label for="bne_position"><?php _e('Position/Title', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="text" id="bne_position" name="bne_position" value="<?php echo esc_attr($position); ?>" class="regular-text" placeholder="e.g., Real Estate Agent"></td>
            </tr>
            <tr>
                <th><label for="bne_license_number"><?php _e('License Number', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="text" id="bne_license_number" name="bne_license_number" value="<?php echo esc_attr($license_number); ?>" class="regular-text" placeholder="e.g., MA: 1234567"></td>
            </tr>
            <tr>
                <th><label for="bne_mls_agent_id"><?php _e('MLS Agent ID', 'flavor-flavor-flavor'); ?></label></th>
                <td>
                    <input type="text" id="bne_mls_agent_id" name="bne_mls_agent_id" value="<?php echo esc_attr($mls_agent_id); ?>" class="regular-text" placeholder="e.g., CT004645">
                    <p class="description"><?php _e('For ShowingTime integration. This is the agent\'s ID in the MLS system.', 'flavor-flavor-flavor'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="bne_email"><?php _e('Email Address', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="email" id="bne_email" name="bne_email" value="<?php echo esc_attr($email); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="bne_phone"><?php _e('Phone Number', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="tel" id="bne_phone" name="bne_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" placeholder="e.g., (617) 555-0123"></td>
            </tr>
            <tr>
                <th><label for="bne_display_order"><?php _e('Display Order', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="number" id="bne_display_order" name="bne_display_order" value="<?php echo esc_attr($display_order); ?>" class="small-text" min="0"> <span class="description"><?php _e('Lower numbers display first', 'flavor-flavor-flavor'); ?></span></td>
            </tr>
            <tr>
                <th colspan="2"><strong><?php _e('Social Media Links', 'flavor-flavor-flavor'); ?></strong></th>
            </tr>
            <tr>
                <th><label for="bne_instagram"><?php _e('Instagram URL', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="url" id="bne_instagram" name="bne_instagram" value="<?php echo esc_url($instagram); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="bne_facebook"><?php _e('Facebook URL', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="url" id="bne_facebook" name="bne_facebook" value="<?php echo esc_url($facebook); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="bne_linkedin"><?php _e('LinkedIn URL', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="url" id="bne_linkedin" name="bne_linkedin" value="<?php echo esc_url($linkedin); ?>" class="regular-text"></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Testimonial meta box
     */
    public static function render_testimonial_meta_box($post) {
        wp_nonce_field('bne_testimonial_meta', 'bne_testimonial_nonce');

        $client_name = get_post_meta($post->ID, '_bne_client_name', true);
        $client_location = get_post_meta($post->ID, '_bne_client_location', true);
        $rating = get_post_meta($post->ID, '_bne_rating', true);
        $transaction_type = get_post_meta($post->ID, '_bne_transaction_type', true);
        $transaction_date = get_post_meta($post->ID, '_bne_transaction_date', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="bne_client_name"><?php _e('Client Name', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="text" id="bne_client_name" name="bne_client_name" value="<?php echo esc_attr($client_name); ?>" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="bne_client_location"><?php _e('Client Location', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="text" id="bne_client_location" name="bne_client_location" value="<?php echo esc_attr($client_location); ?>" class="regular-text" placeholder="e.g., Boston, MA"></td>
            </tr>
            <tr>
                <th><label for="bne_rating"><?php _e('Rating (1-5)', 'flavor-flavor-flavor'); ?></label></th>
                <td>
                    <select id="bne_rating" name="bne_rating">
                        <option value=""><?php _e('Select Rating', 'flavor-flavor-flavor'); ?></option>
                        <?php for ($i = 5; $i >= 1; $i--) : ?>
                            <option value="<?php echo $i; ?>" <?php selected($rating, $i); ?>><?php echo $i; ?> <?php echo $i === 1 ? 'Star' : 'Stars'; ?></option>
                        <?php endfor; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="bne_transaction_type"><?php _e('Transaction Type', 'flavor-flavor-flavor'); ?></label></th>
                <td>
                    <select id="bne_transaction_type" name="bne_transaction_type">
                        <option value=""><?php _e('Select Type', 'flavor-flavor-flavor'); ?></option>
                        <option value="buyer" <?php selected($transaction_type, 'buyer'); ?>><?php _e('Buyer', 'flavor-flavor-flavor'); ?></option>
                        <option value="seller" <?php selected($transaction_type, 'seller'); ?>><?php _e('Seller', 'flavor-flavor-flavor'); ?></option>
                        <option value="rental" <?php selected($transaction_type, 'rental'); ?>><?php _e('Rental', 'flavor-flavor-flavor'); ?></option>
                        <option value="both" <?php selected($transaction_type, 'both'); ?>><?php _e('Buyer & Seller', 'flavor-flavor-flavor'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="bne_transaction_date"><?php _e('Transaction Date', 'flavor-flavor-flavor'); ?></label></th>
                <td><input type="month" id="bne_transaction_date" name="bne_transaction_date" value="<?php echo esc_attr($transaction_date); ?>"></td>
            </tr>
        </table>
        <p class="description"><?php _e('The testimonial text should be entered in the main content editor above.', 'flavor-flavor-flavor'); ?></p>
        <?php
    }

    /**
     * Save meta box data
     */
    public static function save_meta_boxes($post_id) {
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Team Member meta
        if (isset($_POST['bne_team_member_nonce']) && wp_verify_nonce($_POST['bne_team_member_nonce'], 'bne_team_member_meta')) {
            $fields = array(
                'bne_position'       => '_bne_position',
                'bne_license_number' => '_bne_license_number',
                'bne_mls_agent_id'   => '_bne_mls_agent_id',
                'bne_email'          => '_bne_email',
                'bne_phone'          => '_bne_phone',
                'bne_instagram'      => '_bne_instagram',
                'bne_facebook'       => '_bne_facebook',
                'bne_linkedin'       => '_bne_linkedin',
                'bne_display_order'  => '_bne_display_order',
            );

            foreach ($fields as $field => $meta_key) {
                if (isset($_POST[$field])) {
                    $value = sanitize_text_field($_POST[$field]);
                    update_post_meta($post_id, $meta_key, $value);
                }
            }

            // Save linked user ID
            $old_user_id = get_post_meta($post_id, '_bne_user_id', true);
            $new_user_id = isset($_POST['bne_user_id']) ? intval($_POST['bne_user_id']) : 0;

            if ($new_user_id !== intval($old_user_id)) {
                if ($new_user_id > 0) {
                    update_post_meta($post_id, '_bne_user_id', $new_user_id);
                } else {
                    delete_post_meta($post_id, '_bne_user_id');
                }
            }

            // Sync to Agent Profile if MLD plugin is active and user is linked
            if ($new_user_id > 0 && class_exists('MLD_Agent_Client_Manager')) {
                self::sync_team_member_to_agent_profile($post_id, $new_user_id);
            }
        }

        // Testimonial meta
        if (isset($_POST['bne_testimonial_nonce']) && wp_verify_nonce($_POST['bne_testimonial_nonce'], 'bne_testimonial_meta')) {
            $fields = array(
                'bne_client_name'      => '_bne_client_name',
                'bne_client_location'  => '_bne_client_location',
                'bne_rating'           => '_bne_rating',
                'bne_transaction_type' => '_bne_transaction_type',
                'bne_transaction_date' => '_bne_transaction_date',
            );

            foreach ($fields as $field => $meta_key) {
                if (isset($_POST[$field])) {
                    $value = sanitize_text_field($_POST[$field]);
                    update_post_meta($post_id, $meta_key, $value);
                }
            }
        }
    }

    /**
     * Custom columns for Team Members
     */
    public static function team_member_columns($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['position'] = __('Position', 'flavor-flavor-flavor');
                $new_columns['email'] = __('Email', 'flavor-flavor-flavor');
                $new_columns['order'] = __('Order', 'flavor-flavor-flavor');
            }
        }
        return $new_columns;
    }

    /**
     * Team Member column content
     */
    public static function team_member_column_content($column, $post_id) {
        switch ($column) {
            case 'position':
                echo esc_html(get_post_meta($post_id, '_bne_position', true));
                break;
            case 'email':
                $email = get_post_meta($post_id, '_bne_email', true);
                echo $email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '&mdash;';
                break;
            case 'order':
                echo esc_html(get_post_meta($post_id, '_bne_display_order', true) ?: '0');
                break;
        }
    }

    /**
     * Custom columns for Testimonials
     */
    public static function testimonial_columns($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['client'] = __('Client', 'flavor-flavor-flavor');
                $new_columns['rating'] = __('Rating', 'flavor-flavor-flavor');
                $new_columns['type'] = __('Type', 'flavor-flavor-flavor');
            }
        }
        return $new_columns;
    }

    /**
     * Testimonial column content
     */
    public static function testimonial_column_content($column, $post_id) {
        switch ($column) {
            case 'client':
                $name = get_post_meta($post_id, '_bne_client_name', true);
                $location = get_post_meta($post_id, '_bne_client_location', true);
                echo esc_html($name);
                if ($location) {
                    echo '<br><small>' . esc_html($location) . '</small>';
                }
                break;
            case 'rating':
                $rating = get_post_meta($post_id, '_bne_rating', true);
                if ($rating) {
                    echo str_repeat('&#9733;', intval($rating)) . str_repeat('&#9734;', 5 - intval($rating));
                } else {
                    echo '&mdash;';
                }
                break;
            case 'type':
                $type = get_post_meta($post_id, '_bne_transaction_type', true);
                $types = array(
                    'buyer'  => __('Buyer', 'flavor-flavor-flavor'),
                    'seller' => __('Seller', 'flavor-flavor-flavor'),
                    'rental' => __('Rental', 'flavor-flavor-flavor'),
                    'both'   => __('Buyer & Seller', 'flavor-flavor-flavor'),
                );
                echo isset($types[$type]) ? esc_html($types[$type]) : '&mdash;';
                break;
        }
    }

    /**
     * Get team members
     */
    public static function get_team_members($count = -1) {
        $cache_key = 'bne_team_members_' . $count;
        $cached = wp_cache_get($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $args = array(
            'post_type'      => 'bne_team_member',
            'posts_per_page' => $count,
            'orderby'        => 'meta_value_num',
            'meta_key'       => '_bne_display_order',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        );

        $query = new WP_Query($args);
        $members = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $members[] = array(
                    'id'             => get_the_ID(),
                    'name'           => get_the_title(),
                    'bio'            => get_the_content(),
                    'photo'          => get_the_post_thumbnail_url(get_the_ID(), 'bne-team-photo'),
                    'position'       => get_post_meta(get_the_ID(), '_bne_position', true),
                    'license_number' => get_post_meta(get_the_ID(), '_bne_license_number', true),
                    'email'          => get_post_meta(get_the_ID(), '_bne_email', true),
                    'phone'          => get_post_meta(get_the_ID(), '_bne_phone', true),
                    'instagram'      => get_post_meta(get_the_ID(), '_bne_instagram', true),
                    'facebook'       => get_post_meta(get_the_ID(), '_bne_facebook', true),
                    'linkedin'       => get_post_meta(get_the_ID(), '_bne_linkedin', true),
                );
            }
            wp_reset_postdata();
        }

        wp_cache_set($cache_key, $members, '', 3600);
        return $members;
    }

    /**
     * Get testimonials
     */
    public static function get_testimonials($count = -1) {
        $cache_key = 'bne_testimonials_' . $count;
        $cached = wp_cache_get($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $args = array(
            'post_type'      => 'bne_testimonial',
            'posts_per_page' => $count,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'publish',
        );

        $query = new WP_Query($args);
        $testimonials = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $testimonials[] = array(
                    'id'               => get_the_ID(),
                    'content'          => get_the_content(),
                    'excerpt'          => wp_trim_words(get_the_content(), 30, '...'),
                    'client_name'      => get_post_meta(get_the_ID(), '_bne_client_name', true),
                    'client_location'  => get_post_meta(get_the_ID(), '_bne_client_location', true),
                    'rating'           => get_post_meta(get_the_ID(), '_bne_rating', true),
                    'transaction_type' => get_post_meta(get_the_ID(), '_bne_transaction_type', true),
                    'photo'            => get_the_post_thumbnail_url(get_the_ID(), 'thumbnail'),
                );
            }
            wp_reset_postdata();
        }

        wp_cache_set($cache_key, $testimonials, '', 3600);
        return $testimonials;
    }

    /**
     * Sync Team Member data to Agent Profile in MLD plugin
     *
     * Called automatically when a Team Member with a linked user is saved.
     * Syncs: name, email, phone, photo, title, license, bio, social links
     *
     * @param int $post_id Team Member post ID
     * @param int $user_id WordPress user ID
     * @return bool Success
     */
    public static function sync_team_member_to_agent_profile($post_id, $user_id) {
        // Check if MLD plugin is active
        if (!class_exists('MLD_Agent_Client_Manager')) {
            return false;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'bne_team_member') {
            return false;
        }

        // Get team member data
        $name = $post->post_title;
        $bio = $post->post_content;
        $position = get_post_meta($post_id, '_bne_position', true);
        $license_number = get_post_meta($post_id, '_bne_license_number', true);
        $mls_agent_id = get_post_meta($post_id, '_bne_mls_agent_id', true);
        $email = get_post_meta($post_id, '_bne_email', true);
        $phone = get_post_meta($post_id, '_bne_phone', true);
        $instagram = get_post_meta($post_id, '_bne_instagram', true);
        $facebook = get_post_meta($post_id, '_bne_facebook', true);
        $linkedin = get_post_meta($post_id, '_bne_linkedin', true);

        // Get photo URL
        $photo_url = get_the_post_thumbnail_url($post_id, 'large');

        // Build social links array
        $social_links = array();
        if (!empty($instagram)) {
            $social_links['instagram'] = $instagram;
        }
        if (!empty($facebook)) {
            $social_links['facebook'] = $facebook;
        }
        if (!empty($linkedin)) {
            $social_links['linkedin'] = $linkedin;
        }

        // Prepare agent data for sync
        $agent_data = array(
            'display_name'   => $name,
            'email'          => $email ?: get_userdata($user_id)->user_email,
            'phone'          => $phone,
            'title'          => $position,
            'bio'            => wp_strip_all_tags($bio),
            'photo_url'      => $photo_url,
            'license_number' => $license_number,
            'mls_agent_id'   => $mls_agent_id,
            'social_links'   => $social_links, // Pass as array, not JSON
            'is_active'      => ($post->post_status === 'publish') ? 1 : 0,
        );

        // Use the new sync_from_team_member method which properly handles all fields
        $result = MLD_Agent_Client_Manager::sync_from_team_member($post_id, $user_id, $agent_data);

        // Clear team members cache
        wp_cache_delete('bne_team_members_-1');
        wp_cache_delete('bne_team_members_6');

        return $result;
    }

    /**
     * Get Team Member post ID for a user
     *
     * @param int $user_id WordPress user ID
     * @return int|false Post ID or false if not linked
     */
    public static function get_team_member_for_user($user_id) {
        global $wpdb;

        // First check user meta for stored link
        $post_id = get_user_meta($user_id, '_bne_team_member_post_id', true);
        if ($post_id) {
            return intval($post_id);
        }

        // Fall back to postmeta query
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_bne_user_id' AND meta_value = %d
             LIMIT 1",
            $user_id
        ));

        return $post_id ? intval($post_id) : false;
    }
}
