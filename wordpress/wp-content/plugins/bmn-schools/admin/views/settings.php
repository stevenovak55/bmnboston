<?php
/**
 * Admin Settings View
 *
 * @package BMN_Schools
 * @since 0.1.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<div class="wrap bmn-schools-admin">
    <h1><?php esc_html_e('BMN Schools Settings', 'bmn-schools'); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields('bmn_schools_settings'); ?>

        <div class="bmn-settings-grid">
            <!-- General Settings -->
            <div class="bmn-card">
                <h2><?php esc_html_e('General Settings', 'bmn-schools'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="enable_cache"><?php esc_html_e('Enable Caching', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="bmn_schools_settings[enable_cache]" id="enable_cache" value="1"
                                    <?php checked(!empty($settings['enable_cache'])); ?>>
                                <?php esc_html_e('Cache API responses for faster performance', 'bmn-schools'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="cache_duration"><?php esc_html_e('Cache Duration', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="bmn_schools_settings[cache_duration]" id="cache_duration"
                                value="<?php echo esc_attr($settings['cache_duration'] ?? 1800); ?>"
                                min="300" max="86400" class="small-text">
                            <?php esc_html_e('seconds (default: 1800 = 30 minutes)', 'bmn-schools'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_state"><?php esc_html_e('Default State', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="bmn_schools_settings[default_state]" id="default_state"
                                value="<?php echo esc_attr($settings['default_state'] ?? 'MA'); ?>"
                                maxlength="2" class="small-text">
                            <p class="description"><?php esc_html_e('Two-letter state code (e.g., MA)', 'bmn-schools'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="results_per_page"><?php esc_html_e('Results Per Page', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="bmn_schools_settings[results_per_page]" id="results_per_page"
                                value="<?php echo esc_attr($settings['results_per_page'] ?? 20); ?>"
                                min="5" max="100" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="debug_mode"><?php esc_html_e('Debug Mode', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="bmn_schools_settings[debug_mode]" id="debug_mode" value="1"
                                    <?php checked(!empty($settings['debug_mode'])); ?>>
                                <?php esc_html_e('Enable verbose logging (debug level)', 'bmn-schools'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Warning: This will generate many log entries.', 'bmn-schools'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Sync Settings -->
            <div class="bmn-card">
                <h2><?php esc_html_e('Sync Settings', 'bmn-schools'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="auto_sync_enabled"><?php esc_html_e('Auto Sync', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="bmn_schools_sync_settings[auto_sync_enabled]" id="auto_sync_enabled" value="1"
                                    <?php checked(!empty($sync_settings['auto_sync_enabled'])); ?>
                                    disabled>
                                <?php esc_html_e('Enable automatic data synchronization', 'bmn-schools'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('(Available in Phase 2)', 'bmn-schools'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sync_frequency"><?php esc_html_e('Sync Frequency', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <select name="bmn_schools_sync_settings[sync_frequency]" id="sync_frequency" disabled>
                                <option value="daily" <?php selected($sync_settings['sync_frequency'] ?? 'daily', 'daily'); ?>>
                                    <?php esc_html_e('Daily', 'bmn-schools'); ?>
                                </option>
                                <option value="weekly" <?php selected($sync_settings['sync_frequency'] ?? '', 'weekly'); ?>>
                                    <?php esc_html_e('Weekly', 'bmn-schools'); ?>
                                </option>
                                <option value="monthly" <?php selected($sync_settings['sync_frequency'] ?? '', 'monthly'); ?>>
                                    <?php esc_html_e('Monthly', 'bmn-schools'); ?>
                                </option>
                            </select>
                            <p class="description"><?php esc_html_e('(Available in Phase 2)', 'bmn-schools'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Full Sync', 'bmn-schools'); ?></th>
                        <td>
                            <?php
                            if (!empty($sync_settings['last_full_sync'])) {
                                echo esc_html($sync_settings['last_full_sync']);
                            } else {
                                esc_html_e('Never', 'bmn-schools');
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- API Credentials -->
            <div class="bmn-card">
                <h2><?php esc_html_e('API Credentials', 'bmn-schools'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Optional API keys for premium data sources. Leave blank to use only free data.', 'bmn-schools'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="schooldigger_key"><?php esc_html_e('SchoolDigger API Key', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="bmn_schools_api_credentials[schooldigger_key]" id="schooldigger_key"
                                value="<?php echo esc_attr($api_credentials['schooldigger_key'] ?? ''); ?>"
                                class="regular-text">
                            <p class="description">
                                <a href="https://developer.schooldigger.com/" target="_blank">
                                    <?php esc_html_e('Get API Key', 'bmn-schools'); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="greatschools_key"><?php esc_html_e('GreatSchools API Key', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="bmn_schools_api_credentials[greatschools_key]" id="greatschools_key"
                                value="<?php echo esc_attr($api_credentials['greatschools_key'] ?? ''); ?>"
                                class="regular-text">
                            <p class="description">
                                <a href="https://www.greatschools.org/api/" target="_blank">
                                    <?php esc_html_e('Get API Key', 'bmn-schools'); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="attom_key"><?php esc_html_e('ATTOM Data API Key', 'bmn-schools'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="bmn_schools_api_credentials[attom_key]" id="attom_key"
                                value="<?php echo esc_attr($api_credentials['attom_key'] ?? ''); ?>"
                                class="regular-text">
                            <p class="description">
                                <a href="https://api.gateway.attomdata.com/" target="_blank">
                                    <?php esc_html_e('Get API Key', 'bmn-schools'); ?>
                                </a>
                                <?php esc_html_e('(For attendance zone boundaries)', 'bmn-schools'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>
</div>

<style>
.bmn-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(500px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.bmn-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
}

.bmn-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.bmn-card .form-table th {
    padding-left: 0;
}
</style>
