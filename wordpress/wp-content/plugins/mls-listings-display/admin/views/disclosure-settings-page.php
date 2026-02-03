<?php
/**
 * Admin view for MLS Disclosure Settings.
 *
 * Allows administrators to configure MLS disclosure text and logo
 * that will be displayed on property detail pages.
 *
 * @package MLS_Listings_Display
 * @since 6.11.21
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current settings
$disclosure_settings = get_option('mld_disclosure_settings', []);
$enabled = isset($disclosure_settings['enabled']) ? $disclosure_settings['enabled'] : 0;
$logo_url = isset($disclosure_settings['logo_url']) ? $disclosure_settings['logo_url'] : '';
$disclosure_text = isset($disclosure_settings['disclosure_text']) ? $disclosure_settings['disclosure_text'] : '';

// Default placeholder text
$default_text = 'Listing information is deemed reliable but not guaranteed. Data provided by MLS Property Information Network, Inc. All information should be independently verified.';
?>
<div class="wrap mld-disclosure-settings">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <p>Configure the MLS disclosure information that will be displayed on property detail pages. This is required for MLS compliance.</p>

    <?php settings_errors('mld_disclosure_settings'); ?>

    <form action="options.php" method="post">
        <?php settings_fields('mld_disclosure_group'); ?>

        <table class="form-table" role="presentation">
            <tbody>
                <!-- Enable/Disable Toggle -->
                <tr>
                    <th scope="row">
                        <label for="mld_disclosure_enabled">Display Disclosure</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="mld_disclosure_settings[enabled]"
                                   id="mld_disclosure_enabled"
                                   value="1"
                                   <?php checked(1, $enabled); ?>>
                            Show MLS disclosure on property detail pages
                        </label>
                        <p class="description">When enabled, the disclosure text and logo will appear in the "Listing Information" section on each property page.</p>
                    </td>
                </tr>

                <!-- MLS Logo Upload -->
                <tr>
                    <th scope="row">
                        <label for="mld_disclosure_logo">MLS Logo</label>
                    </th>
                    <td>
                        <div class="mld-logo-uploader-wrapper">
                            <div class="mld-image-preview mld-disclosure-logo-preview" id="preview-disclosure-logo">
                                <?php if ($logo_url): ?>
                                    <img src="<?php echo esc_url($logo_url); ?>" alt="MLS Logo" />
                                <?php else: ?>
                                    <span class="no-image">No logo set</span>
                                <?php endif; ?>
                            </div>
                            <div class="mld-upload-controls">
                                <input type="text"
                                       name="mld_disclosure_settings[logo_url]"
                                       id="mld_disclosure_logo"
                                       value="<?php echo esc_attr($logo_url); ?>"
                                       class="regular-text mld-icon-url-input"
                                       placeholder="https://example.com/logo.png">
                                <button type="button"
                                        class="button mld-upload-button"
                                        data-target-input="#mld_disclosure_logo"
                                        data-target-preview="#preview-disclosure-logo">
                                    Upload Logo
                                </button>
                                <?php if ($logo_url): ?>
                                <button type="button"
                                        class="button mld-remove-logo-button"
                                        data-target-input="#mld_disclosure_logo"
                                        data-target-preview="#preview-disclosure-logo">
                                    Remove
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="description">Upload your MLS logo (e.g., MLS PIN logo). Recommended size: 120x60 pixels or similar aspect ratio.</p>
                    </td>
                </tr>

                <!-- Disclosure Text -->
                <tr>
                    <th scope="row">
                        <label for="mld_disclosure_text">Disclosure Text</label>
                    </th>
                    <td>
                        <?php
                        wp_editor(
                            $disclosure_text ?: $default_text,
                            'mld_disclosure_text',
                            [
                                'textarea_name' => 'mld_disclosure_settings[disclosure_text]',
                                'textarea_rows' => 6,
                                'media_buttons' => false,
                                'teeny' => true,
                                'quicktags' => ['buttons' => 'strong,em,link'],
                            ]
                        );
                        ?>
                        <p class="description">Enter the required MLS disclosure text. Basic HTML formatting is allowed (bold, italic, links).</p>
                        <p class="description">
                            <strong>Tip:</strong> Check your MLS data agreement for the exact wording required. Common elements include:
                        </p>
                        <ul class="description" style="list-style-type: disc; margin-left: 20px;">
                            <li>Data source attribution (e.g., "Data provided by MLS Property Information Network, Inc.")</li>
                            <li>Accuracy disclaimer (e.g., "Information deemed reliable but not guaranteed")</li>
                            <li>Copyright notice with current year</li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button('Save Disclosure Settings'); ?>
    </form>

    <hr>

    <h2>Preview</h2>
    <p>This is how the disclosure will appear on property detail pages:</p>

    <div class="mld-disclosure-preview" style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; max-width: 600px;">
        <div style="display: flex; align-items: flex-start; gap: 15px;">
            <?php if ($logo_url): ?>
            <img src="<?php echo esc_url($logo_url); ?>"
                 alt="MLS Logo"
                 style="max-width: 120px; max-height: 60px; flex-shrink: 0;">
            <?php endif; ?>
            <div style="font-size: 13px; color: #6b7280; line-height: 1.5;">
                <?php echo wp_kses_post($disclosure_text ?: $default_text); ?>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Handle remove logo button
    $(document).on('click', '.mld-remove-logo-button', function(e) {
        e.preventDefault();
        var targetInput = $(this).data('target-input');
        var targetPreview = $(this).data('target-preview');

        $(targetInput).val('');
        $(targetPreview).html('<span class="no-image">No logo set</span>');
        $(this).hide();
    });
});
</script>

<style>
.mld-disclosure-settings .mld-logo-uploader-wrapper {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 500px;
}

.mld-disclosure-settings .mld-disclosure-logo-preview {
    width: 150px;
    height: 80px;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    overflow: hidden;
}

.mld-disclosure-settings .mld-disclosure-logo-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.mld-disclosure-settings .mld-disclosure-logo-preview .no-image {
    color: #9ca3af;
    font-size: 12px;
}

.mld-disclosure-settings .mld-upload-controls {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.mld-disclosure-settings .mld-upload-controls input[type="text"] {
    flex: 1;
    min-width: 200px;
}
</style>
