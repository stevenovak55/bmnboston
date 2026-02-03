<?php
/**
 * School FAQ Section
 *
 * Generates dynamic, data-driven FAQs for SEO rich snippets.
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();

// Use dynamic FAQ generator for data-driven, SEO-optimized FAQs
$faqs = function_exists('bmn_generate_school_faqs')
    ? bmn_generate_school_faqs($data)
    : array();

// Fallback if no FAQs generated
if (empty($faqs)) {
    $name = $data['name'] ?? 'This school';
    $letter_grade = $data['letter_grade'] ?? 'N/A';
    $district_name = $data['district']['name'] ?? 'the district';

    $faqs = array(
        array(
            'question' => "What is {$name}'s letter grade?",
            'answer'   => "{$name} has earned a letter grade of {$letter_grade}. Contact the school for more information.",
        ),
        array(
            'question' => "What school district is {$name} part of?",
            'answer'   => "{$name} is part of {$district_name}.",
        ),
    );
}
?>

<section class="bne-school-faq">
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
