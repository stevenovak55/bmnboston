<?php
/**
 * Admin view for the Icon & Label Manager.
 * v2.0.0
 * - FIX: This page now functions correctly as the MLD_Query::get_all_distinct_subtypes() method
 * has been implemented in the updated query class to fetch subtypes from both the active and archive tables.
 */

// Prepare data for the view
$all_subtypes = MLD_Query::get_all_distinct_subtypes();
$customizations = get_option('mld_subtype_customizations', []);
?>
<div class="wrap mld-icon-manager">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <p>Customize the display labels and icons for each property subtype found in your database. These customizations will appear in the "Home Type" filter on the map.</p>
    
    <form action="options.php" method="post">
        <?php settings_fields( 'mld_icon_manager_group' ); ?>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 25%;">Original Subtype (from MLS)</th>
                    <th scope="col" style="width: 30%;">Custom Display Label</th>
                    <th scope="col" style="width: 45%;">Custom Icon (32x32 recommended)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($all_subtypes)): ?>
                    <tr>
                        <td colspan="3">No property subtypes found in the database yet. Run an extraction first.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($all_subtypes as $subtype): 
                        $subtype_slug = sanitize_key($subtype);
                        $label = isset($customizations[$subtype_slug]['label']) ? $customizations[$subtype_slug]['label'] : '';
                        $icon = isset($customizations[$subtype_slug]['icon']) ? $customizations[$subtype_slug]['icon'] : '';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($subtype); ?></strong></td>
                        <td>
                            <input type="text" 
                                   name="mld_subtype_customizations[<?php echo esc_attr($subtype_slug); ?>][label]" 
                                   value="<?php echo esc_attr($label); ?>" 
                                   placeholder="<?php echo esc_attr($subtype); ?>"
                                   class="regular-text">
                        </td>
                        <td>
                            <div class="mld-icon-uploader-wrapper">
                                <div class="mld-image-preview" id="preview-<?php echo esc_attr($subtype_slug); ?>">
                                    <?php if ($icon): ?>
                                        <img src="<?php echo esc_url($icon); ?>" />
                                    <?php endif; ?>
                                </div>
                                <input type="text" 
                                       name="mld_subtype_customizations[<?php echo esc_attr($subtype_slug); ?>][icon]" 
                                       id="icon-<?php echo esc_attr($subtype_slug); ?>" 
                                       value="<?php echo esc_attr($icon); ?>" 
                                       class="regular-text mld-icon-url-input">
                                <button type="button" 
                                        class="button mld-upload-button" 
                                        data-target-input="#icon-<?php echo esc_attr($subtype_slug); ?>" 
                                        data-target-preview="#preview-<?php echo esc_attr($subtype_slug); ?>">Upload Icon</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php submit_button( 'Save Customizations' ); ?>
    </form>
</div>
