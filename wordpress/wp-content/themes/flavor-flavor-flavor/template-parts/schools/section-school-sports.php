<?php
/**
 * School Sports Section
 *
 * @package flavor_flavor_flavor
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = $args['data'] ?? array();
$sports = $data['sports'] ?? array();

if (empty($sports) || empty($sports['list'])) {
    return;
}

$sports_list = $sports['list'];
$sports_count = $sports['count'] ?? count($sports_list);
$total_participants = $sports['total_participants'] ?? 0;

// Group by gender
$boys_sports = array_filter($sports_list, function($s) { return ($s->gender ?? '') === 'Boys'; });
$girls_sports = array_filter($sports_list, function($s) { return ($s->gender ?? '') === 'Girls'; });
$coed_sports = array_filter($sports_list, function($s) { return ($s->gender ?? '') === 'Coed'; });
?>

<section class="bne-school-sports">
    <div class="bne-container">
        <h2 class="bne-section-title">Athletics Programs</h2>
        <p class="bne-section-subtitle">
            <?php echo esc_html($sports_count); ?> sports programs with
            <?php echo esc_html(number_format($total_participants)); ?> student athletes
        </p>

        <div class="bne-sports-grid">
            <?php if (!empty($boys_sports)) : ?>
                <div class="bne-sports-category">
                    <h3 class="bne-sports-category__title">Boys Sports</h3>
                    <ul class="bne-sports-list">
                        <?php foreach ($boys_sports as $sport) : ?>
                            <li class="bne-sports-list__item">
                                <span class="bne-sports-list__name"><?php echo esc_html($sport->sport); ?></span>
                                <?php if (!empty($sport->participants)) : ?>
                                    <span class="bne-sports-list__count"><?php echo esc_html($sport->participants); ?> athletes</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($girls_sports)) : ?>
                <div class="bne-sports-category">
                    <h3 class="bne-sports-category__title">Girls Sports</h3>
                    <ul class="bne-sports-list">
                        <?php foreach ($girls_sports as $sport) : ?>
                            <li class="bne-sports-list__item">
                                <span class="bne-sports-list__name"><?php echo esc_html($sport->sport); ?></span>
                                <?php if (!empty($sport->participants)) : ?>
                                    <span class="bne-sports-list__count"><?php echo esc_html($sport->participants); ?> athletes</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($coed_sports)) : ?>
                <div class="bne-sports-category">
                    <h3 class="bne-sports-category__title">Coed Sports</h3>
                    <ul class="bne-sports-list">
                        <?php foreach ($coed_sports as $sport) : ?>
                            <li class="bne-sports-list__item">
                                <span class="bne-sports-list__name"><?php echo esc_html($sport->sport); ?></span>
                                <?php if (!empty($sport->participants)) : ?>
                                    <span class="bne-sports-list__count"><?php echo esc_html($sport->participants); ?> athletes</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <p class="bne-sports-note">
            Sports data from the Massachusetts Interscholastic Athletic Association (MIAA).
        </p>
    </div>
</section>
