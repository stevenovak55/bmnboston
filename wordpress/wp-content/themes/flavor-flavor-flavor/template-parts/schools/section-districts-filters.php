<?php
/**
 * Districts Filter Section
 *
 * Filter controls for the districts browse page.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$data = isset($args['data']) ? $args['data'] : array();
$filters = isset($args['filters']) ? $args['filters'] : array();

$current_grade = $filters['grade'] ?? '';
$current_city = $filters['city'] ?? '';
$current_sort = $filters['sort'] ?? 'rank';
?>

<section class="bne-section bne-districts-filters">
    <div class="bne-container">
        <form class="bne-districts-filters__form" method="get" action="<?php echo esc_url(home_url('/schools/')); ?>">
            <div class="bne-districts-filters__row">
                <!-- Grade Filter -->
                <div class="bne-districts-filters__group">
                    <label for="grade-filter" class="bne-districts-filters__label">Filter By District Grade</label>
                    <div class="bne-districts-filters__grade-buttons">
                        <button type="button"
                                class="bne-grade-btn <?php echo $current_grade === '' ? 'bne-grade-btn--active' : ''; ?>"
                                data-grade="">All</button>
                        <button type="button"
                                class="bne-grade-btn bne-grade-btn--a <?php echo $current_grade === 'A' ? 'bne-grade-btn--active' : ''; ?>"
                                data-grade="A">A</button>
                        <button type="button"
                                class="bne-grade-btn bne-grade-btn--b <?php echo $current_grade === 'B' ? 'bne-grade-btn--active' : ''; ?>"
                                data-grade="B">B</button>
                        <button type="button"
                                class="bne-grade-btn bne-grade-btn--c <?php echo $current_grade === 'C' ? 'bne-grade-btn--active' : ''; ?>"
                                data-grade="C">C</button>
                        <button type="button"
                                class="bne-grade-btn bne-grade-btn--d <?php echo $current_grade === 'D' ? 'bne-grade-btn--active' : ''; ?>"
                                data-grade="D">D</button>
                        <button type="button"
                                class="bne-grade-btn bne-grade-btn--f <?php echo $current_grade === 'F' ? 'bne-grade-btn--active' : ''; ?>"
                                data-grade="F">F</button>
                    </div>
                    <input type="hidden" name="grade" id="grade-filter" value="<?php echo esc_attr($current_grade); ?>">
                </div>

                <!-- City Search -->
                <div class="bne-districts-filters__group bne-districts-filters__group--city">
                    <label for="city-filter" class="bne-districts-filters__label">City/Town</label>
                    <input type="text"
                           name="city"
                           id="city-filter"
                           class="bne-districts-filters__input"
                           placeholder="Search by city..."
                           value="<?php echo esc_attr($current_city); ?>">
                </div>

                <!-- Sort -->
                <div class="bne-districts-filters__group">
                    <label for="sort-filter" class="bne-districts-filters__label">Sort By</label>
                    <select name="sort" id="sort-filter" class="bne-districts-filters__select">
                        <option value="rank" <?php selected($current_sort, 'rank'); ?>>Ranking</option>
                        <option value="name" <?php selected($current_sort, 'name'); ?>>Name A-Z</option>
                        <option value="score" <?php selected($current_sort, 'score'); ?>>Composite Score</option>
                    </select>
                </div>

                <!-- Submit -->
                <div class="bne-districts-filters__group bne-districts-filters__group--submit">
                    <button type="submit" class="bne-btn bne-btn--primary">
                        Apply Filters
                    </button>
                    <?php if (!empty($current_grade) || !empty($current_city) || $current_sort !== 'rank') : ?>
                        <a href="<?php echo esc_url(home_url('/schools/')); ?>" class="bne-btn bne-btn--text">
                            Clear All
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Results summary -->
            <div class="bne-districts-filters__summary">
                <p>
                    Showing <strong><?php echo number_format($data['total'] ?? 0); ?></strong> districts
                    <?php if (!empty($current_grade)) : ?>
                        with grade <strong><?php echo esc_html($current_grade); ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($current_city)) : ?>
                        in <strong><?php echo esc_html($current_city); ?></strong>
                    <?php endif; ?>
                </p>
            </div>
        </form>
    </div>
</section>
