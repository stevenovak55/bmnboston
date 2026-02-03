<?php
/**
 * Districts Grid Section
 *
 * Displays grid of district cards with pagination.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$filters = isset($args['filters']) ? $args['filters'] : array();

$districts = $data['districts'] ?? array();
$total_pages = $data['total_pages'] ?? 1;
$current_page = $data['page'] ?? 1;
?>

<section class="bne-section bne-districts-grid-section">
    <div class="bne-container">
        <?php if (empty($districts)) : ?>
            <div class="bne-districts-grid__empty">
                <h3>No districts found</h3>
                <p>Try adjusting your filters to see more results.</p>
                <a href="<?php echo esc_url(home_url('/schools/')); ?>" class="bne-btn bne-btn--primary">
                    View All Districts
                </a>
            </div>
        <?php else : ?>
            <div class="bne-districts-grid">
                <?php foreach ($districts as $district) : ?>
                    <article class="bne-district-card">
                        <a href="<?php echo esc_url($district->url); ?>" class="bne-district-card__link">
                            <div class="bne-district-card__header">
                                <div class="bne-district-card__grade <?php echo esc_attr(bmn_get_grade_class($district->letter_grade)); ?>">
                                    <?php echo esc_html($district->letter_grade); ?>
                                </div>
                                <div class="bne-district-card__title-wrap">
                                    <h2 class="bne-district-card__title"><?php echo esc_html($district->name); ?></h2>
                                    <p class="bne-district-card__location"><?php echo esc_html($district->city); ?>, MA</p>
                                </div>
                            </div>

                            <div class="bne-district-card__stats">
                                <?php if (!empty($district->state_rank)) : ?>
                                    <div class="bne-district-card__stat">
                                        <span class="bne-district-card__stat-value">#<?php echo number_format($district->state_rank); ?></span>
                                        <span class="bne-district-card__stat-label">State Rank</span>
                                    </div>
                                <?php endif; ?>

                                <div class="bne-district-card__stat">
                                    <span class="bne-district-card__stat-value"><?php echo number_format($district->schools_count ?? $district->total_schools ?? 0); ?></span>
                                    <span class="bne-district-card__stat-label">Schools</span>
                                </div>

                                <?php if (!empty($district->composite_score)) : ?>
                                    <div class="bne-district-card__stat">
                                        <span class="bne-district-card__stat-value"><?php echo number_format($district->composite_score, 1); ?></span>
                                        <span class="bne-district-card__stat-label">Score</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (($district->elementary_avg ?? 0) > 0 || ($district->middle_avg ?? 0) > 0 || ($district->high_avg ?? 0) > 0) : ?>
                                <div class="bne-district-card__levels">
                                    <?php if (($district->elementary_avg ?? 0) > 0) : ?>
                                        <span class="bne-district-card__level">
                                            Elem: <?php echo number_format($district->elementary_avg, 1); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (($district->middle_avg ?? 0) > 0) : ?>
                                        <span class="bne-district-card__level">
                                            Mid: <?php echo number_format($district->middle_avg, 1); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (($district->high_avg ?? 0) > 0) : ?>
                                        <span class="bne-district-card__level">
                                            High: <?php echo number_format($district->high_avg, 1); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="bne-district-card__footer">
                                <span class="bne-district-card__cta">View District &rarr;</span>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1) : ?>
                <nav class="bne-pagination" aria-label="Districts pagination">
                    <?php
                    // Build base URL with current filters
                    $base_url = home_url('/schools/');
                    $query_args = array_filter(array(
                        'grade' => $filters['grade'] ?? '',
                        'city'  => $filters['city'] ?? '',
                        'sort'  => $filters['sort'] ?? '',
                    ));

                    // Previous page
                    if ($current_page > 1) :
                        $prev_args = array_merge($query_args, array('pg' => $current_page - 1));
                        ?>
                        <a href="<?php echo esc_url(add_query_arg($prev_args, $base_url)); ?>"
                           class="bne-pagination__link bne-pagination__link--prev">
                            &larr; Previous
                        </a>
                    <?php endif; ?>

                    <span class="bne-pagination__info">
                        Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                    </span>

                    <?php
                    // Next page
                    if ($current_page < $total_pages) :
                        $next_args = array_merge($query_args, array('pg' => $current_page + 1));
                        ?>
                        <a href="<?php echo esc_url(add_query_arg($next_args, $base_url)); ?>"
                           class="bne-pagination__link bne-pagination__link--next">
                            Next &rarr;
                        </a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
