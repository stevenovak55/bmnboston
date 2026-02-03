<?php
/**
 * District FAQ Section
 *
 * Generates dynamic, data-driven FAQs for SEO rich snippets.
 *
 * @package flavor_flavor_flavor
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();

// Use dynamic FAQ generator for data-driven, SEO-optimized FAQs
$faqs = function_exists('bmn_generate_district_faqs')
    ? bmn_generate_district_faqs($data)
    : array();

// Fallback if no FAQs generated
if (empty($faqs)) {
    $district_name = $data['name'] ?? 'This district';
    $grade = $data['letter_grade'] ?? 'N/A';

    $faqs = array(
        array(
            'question' => "What is the rating for {$district_name}?",
            'answer'   => "{$district_name} has a rating of {$grade}. Contact the district for more information.",
        ),
    );
}

if (empty($faqs)) {
    return;
}
?>

<section class="bne-section bne-district-faq">
    <div class="bne-container">
        <h2 class="bne-section-title">Frequently Asked Questions</h2>

        <div class="bne-faq-list" itemscope itemtype="https://schema.org/FAQPage">
            <?php foreach ($faqs as $faq) : ?>
                <div class="bne-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <h3 class="bne-faq-item__question" itemprop="name">
                        <?php echo esc_html($faq['question']); ?>
                    </h3>
                    <div class="bne-faq-item__answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <p itemprop="text"><?php echo esc_html($faq['answer']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
