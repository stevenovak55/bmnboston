<?php
/**
 * Template Name: Signup Page (Full Width)
 *
 * A clean, full-width template for the signup page without sidebar.
 */

get_header();
?>

<main id="primary" class="site-main mld-signup-page-wrapper">
    <div class="mld-signup-page-container">
        <?php
        while (have_posts()) :
            the_post();
            the_content();
        endwhile;
        ?>
    </div>
</main>

<style>
.mld-signup-page-wrapper {
    max-width: 100%;
    padding: 0;
    margin: 0;
}
.mld-signup-page-container {
    max-width: 600px;
    margin: 0 auto;
    padding: 40px 20px;
}
/* Hide any sidebar that might be injected */
.mld-signup-page-wrapper + aside,
.mld-signup-page-wrapper ~ .widget-area {
    display: none;
}
</style>

<?php
get_footer();
