<?php
/**
 * Main template file
 *
 * This is the most generic template file in a WordPress theme
 * and one of the two required files for a theme (the other being style.css).
 *
 * @package flavor_flavor_flavor
 * @version 1.0.15
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<main id="main" class="bne-main" role="main">
    <div class="bne-container">
        <?php if (have_posts()) : ?>
            <div class="bne-posts">
                <?php while (have_posts()) : the_post(); ?>
                    <article id="post-<?php the_ID(); ?>" <?php post_class('bne-post'); ?>>
                        <?php if (has_post_thumbnail()) : ?>
                            <div class="bne-post__thumbnail">
                                <a href="<?php the_permalink(); ?>">
                                    <?php the_post_thumbnail('large'); ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <header class="bne-post__header">
                            <h2 class="bne-post__title">
                                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </h2>
                            <div class="bne-post__meta">
                                <time datetime="<?php echo get_the_date('c'); ?>">
                                    <?php echo get_the_date(); ?>
                                </time>
                            </div>
                        </header>

                        <div class="bne-post__excerpt">
                            <?php the_excerpt(); ?>
                        </div>

                        <a href="<?php the_permalink(); ?>" class="bne-btn bne-btn--outline">
                            Read More
                        </a>
                    </article>
                <?php endwhile; ?>
            </div>

            <?php the_posts_pagination(array(
                'mid_size' => 2,
                'prev_text' => '&laquo; Previous',
                'next_text' => 'Next &raquo;',
            )); ?>

        <?php else : ?>
            <p class="bne-no-content">No posts found.</p>
        <?php endif; ?>
    </div>
</main>

<?php get_footer(); ?>
