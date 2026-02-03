<?php
/**
 * Homepage Blog Section
 *
 * Displays the latest blog posts.
 *
 * @package flavor_flavor_flavor
 * @version 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get latest posts
$args = array(
    'post_type'      => 'post',
    'posts_per_page' => 3,
    'post_status'    => 'publish',
    'orderby'        => 'date',
    'order'          => 'DESC',
);

$blog_posts = new WP_Query($args);
?>

<section class="bne-section bne-section--alt bne-blog">
    <div class="bne-container">
        <h2 class="bne-section-title">Latest from Our Blog</h2>
        <p class="bne-section-subtitle">Real estate insights, market updates, and expert advice</p>

        <?php if ($blog_posts->have_posts()) : ?>
            <div class="bne-grid bne-grid--3 bne-blog__grid">
                <?php while ($blog_posts->have_posts()) : $blog_posts->the_post(); ?>
                    <article class="bne-blog-card">
                        <a href="<?php the_permalink(); ?>" class="bne-blog-card__link">
                            <div class="bne-blog-card__image-wrapper">
                                <?php if (has_post_thumbnail()) : ?>
                                    <?php the_post_thumbnail('medium_large', array('class' => 'bne-blog-card__image', 'loading' => 'lazy')); ?>
                                <?php else : ?>
                                    <div class="bne-blog-card__image-placeholder">
                                        <svg viewBox="0 0 24 24" fill="currentColor" width="48" height="48" aria-hidden="true">
                                            <path d="M19 5v14H5V5h14m0-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-4.86 8.86l-3 3.87L9 13.14 6 17h12l-3.86-5.14z"/>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                                <span class="bne-blog-card__category">
                                    <?php
                                    $categories = get_the_category();
                                    if ($categories) {
                                        echo esc_html($categories[0]->name);
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="bne-blog-card__content">
                                <time class="bne-blog-card__date" datetime="<?php echo get_the_date('c'); ?>">
                                    <?php echo get_the_date('F j, Y'); ?>
                                </time>
                                <h3 class="bne-blog-card__title"><?php the_title(); ?></h3>
                                <p class="bne-blog-card__excerpt">
                                    <?php echo wp_trim_words(get_the_excerpt(), 20, '...'); ?>
                                </p>
                                <span class="bne-blog-card__read-more">
                                    Read Article
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" aria-hidden="true">
                                        <path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/>
                                    </svg>
                                </span>
                            </div>
                        </a>
                    </article>
                <?php endwhile; ?>
            </div>
            <?php wp_reset_postdata(); ?>
        <?php else : ?>
            <p class="bne-no-content">No blog posts available yet.</p>
        <?php endif; ?>

        <div class="bne-section__cta">
            <a href="<?php echo esc_url(get_permalink(get_option('page_for_posts'))); ?>" class="bne-btn bne-btn--outline">
                View All Articles
            </a>
        </div>
    </div>
</section>
