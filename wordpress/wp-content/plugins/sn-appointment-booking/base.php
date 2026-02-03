<?php
/**
 * Base HTML Email Template
 *
 * This template provides the HTML wrapper for all appointment emails.
 * Variables available: $subject, $content, $footer_text
 *
 * @package SN_Appointment_Booking
 * @since 1.8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$site_name = get_bloginfo('name');
$site_url = home_url();
$brand_color = '#0891B2'; // BMN Boston teal
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo esc_html($subject); ?></title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        /* Reset styles */
        body, table, td, p, a, li, blockquote {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table, td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }
        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }
        body {
            height: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            background-color: #f4f4f4;
        }
        /* iOS BLUE LINKS */
        a[x-apple-data-detectors] {
            color: inherit !important;
            text-decoration: none !important;
            font-size: inherit !important;
            font-family: inherit !important;
            font-weight: inherit !important;
            line-height: inherit !important;
        }
        /* MOBILE STYLES */
        @media screen and (max-width: 600px) {
            .mobile-padding {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
            .mobile-full-width {
                width: 100% !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    <!-- Preheader text (hidden but shown in email preview) -->
    <div style="display: none; max-height: 0px; overflow: hidden;">
        <?php echo esc_html(wp_strip_all_tags(substr($content, 0, 150))); ?>
    </div>

    <!-- Main wrapper table -->
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f4f4;">
        <tr>
            <td align="center" style="padding: 40px 10px;">
                <!-- Email container -->
                <table border="0" cellpadding="0" cellspacing="0" width="600" class="mobile-full-width" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">

                    <!-- Header -->
                    <tr>
                        <td align="center" style="background-color: <?php echo esc_attr($brand_color); ?>; padding: 30px 40px;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center">
                                        <h1 style="margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 28px; font-weight: 700; color: #ffffff; letter-spacing: -0.5px;">
                                            <?php echo esc_html($site_name); ?>
                                        </h1>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="mobile-padding" style="padding: 40px;">
                            <?php echo $content; ?>
                        </td>
                    </tr>

                    <!-- Footer - Updated v1.9.5 to use unified footer with social links, QR code, App Store badge -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 30px 40px; border-top: 1px solid #e9ecef;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td align="center" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; color: #6c757d; line-height: 1.6;">
                                        <?php if (!empty($footer_text)) : ?>
                                            <p style="margin: 0 0 15px 0;"><?php echo esc_html($footer_text); ?></p>
                                        <?php endif; ?>
                                        <?php
                                        // Use unified footer from MLD_Email_Utilities if available (v1.9.5)
                                        if (class_exists('MLD_Email_Utilities')) {
                                            echo MLD_Email_Utilities::get_unified_footer([
                                                'context' => 'appointment',
                                                'show_social' => true,
                                                'show_app_download' => true,
                                                'show_qr_code' => true,
                                            ]);
                                        } else {
                                            // Fallback to basic footer if MLD plugin not active
                                            ?>
                                            <p style="margin: 0;">
                                                <a href="<?php echo esc_url($site_url); ?>" style="color: <?php echo esc_attr($brand_color); ?>; text-decoration: none; font-weight: 500;">
                                                    <?php echo esc_html($site_name); ?>
                                                </a>
                                            </p>
                                            <!-- iOS App Store Badge -->
                                            <p style="margin: 20px 0 0 0;">
                                                <a href="https://apps.apple.com/us/app/bmn-boston/id6745724401" style="display: inline-block;">
                                                    <img src="https://tools.applemediaservices.com/api/badges/download-on-the-app-store/black/en-us?size=250x83"
                                                         alt="Download on the App Store"
                                                         style="height: 36px; width: auto;">
                                                </a>
                                            </p>
                                            <p style="margin: 10px 0 0 0; font-size: 12px; color: #adb5bd;">
                                                &copy; <?php echo esc_html($year); ?> <?php echo esc_html($site_name); ?>. All rights reserved.
                                            </p>
                                            <?php
                                        }
                                        ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
