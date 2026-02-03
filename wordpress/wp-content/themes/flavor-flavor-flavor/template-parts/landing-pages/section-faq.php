<?php
/**
 * Landing Page FAQ Section
 *
 * Displays frequently asked questions with Schema.org markup
 *
 * @package flavor_flavor_flavor
 * @version 1.3.1
 *
 * @var array $args Template arguments containing 'data' and 'type'
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$type = isset($args['type']) ? $args['type'] : 'neighborhood';

$name = $data['name'] ?? '';

// Generate dynamic FAQs based on the data
$faqs = array();

if (class_exists('BNE_Landing_Page_SEO')) {
    $faqs = BNE_Landing_Page_SEO::generate_location_faqs();
}

if (empty($faqs)) {
    return;
}

// Output FAQ Schema
if (class_exists('BNE_Landing_Page_SEO')) {
    $faq_schema = BNE_Landing_Page_SEO::get_faq_schema($faqs);
    if (!empty($faq_schema)) {
        echo '<script type="application/ld+json">' . "\n";
        echo wp_json_encode($faq_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        echo "\n</script>\n";
    }
}
?>

<section class="bne-landing-faq">
    <div class="bne-landing-container">
        <h2 class="bne-landing-section-title">
            Frequently Asked Questions
        </h2>
        <p class="bne-landing-section-subtitle">
            Common questions about <?php echo esc_html($name); ?> real estate
        </p>

        <div class="bne-landing-faq__list">
            <?php foreach ($faqs as $index => $faq) : ?>
                <details class="bne-landing-faq__item" <?php echo $index === 0 ? 'open' : ''; ?>>
                    <summary class="bne-landing-faq__question">
                        <?php echo esc_html($faq['question']); ?>
                        <svg class="bne-landing-faq__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m6 9 6 6 6-6"></path>
                        </svg>
                    </summary>
                    <div class="bne-landing-faq__answer">
                        <p><?php echo esc_html($faq['answer']); ?></p>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
