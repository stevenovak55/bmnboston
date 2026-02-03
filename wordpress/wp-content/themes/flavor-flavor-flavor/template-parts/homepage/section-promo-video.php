<?php
/**
 * Homepage Promotional Video Section
 *
 * Displays an embedded promotional video from YouTube, Vimeo, or self-hosted source.
 * Features: autoplay on scroll, loop, no controls, full width, mute toggle button.
 * Section is completely hidden when no video URL is provided.
 *
 * @package flavor_flavor_flavor
 * @version 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get customizer values
$video_url = get_theme_mod('bne_video_url', '');

// Don't render section at all if no video URL
if (empty($video_url)) {
    return;
}

$title = get_theme_mod('bne_video_title', 'Discover Our Story');
$subtitle = get_theme_mod('bne_video_subtitle', 'Watch our video to learn more about our approach to real estate.');
$aspect_ratio = get_theme_mod('bne_video_aspect_ratio', '16:9');
$bg_style = get_theme_mod('bne_video_bg_style', 'white');

/**
 * Detect video type and generate embed code
 *
 * @param string $url Video URL
 * @return array Contains 'type', 'embed_url' or 'video_url', and 'video_id'
 */
function bne_parse_video_url($url) {
    $result = array(
        'type' => 'unknown',
        'embed_url' => '',
        'video_url' => '',
        'video_id' => ''
    );

    // YouTube detection (youtube.com, youtu.be)
    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $matches)) {
        $video_id = $matches[1];
        $result['type'] = 'youtube';
        $result['video_id'] = $video_id;
        // Autoplay, muted (required for autoplay), loop, no controls, no related videos
        $result['embed_url'] = 'https://www.youtube-nocookie.com/embed/' . $video_id . '?' . http_build_query(array(
            'autoplay' => '1',
            'mute' => '1',
            'loop' => '1',
            'playlist' => $video_id, // Required for loop to work
            'controls' => '0',
            'showinfo' => '0',
            'rel' => '0',
            'modestbranding' => '1',
            'playsinline' => '1',
            'enablejsapi' => '1',
            'origin' => home_url(),
        ));
        return $result;
    }

    // Vimeo detection
    if (preg_match('/vimeo\.com\/(?:video\/)?(\d+)/i', $url, $matches)) {
        $result['type'] = 'vimeo';
        $result['video_id'] = $matches[1];
        // Autoplay, muted, loop, no controls (background mode)
        $result['embed_url'] = 'https://player.vimeo.com/video/' . $matches[1] . '?' . http_build_query(array(
            'autoplay' => '1',
            'muted' => '1',
            'loop' => '1',
            'background' => '1', // Vimeo background mode - no controls
            'dnt' => '1',
        ));
        return $result;
    }

    // Self-hosted video detection (.mp4, .webm, .ogg)
    if (preg_match('/\.(mp4|webm|ogg)(\?.*)?$/i', $url)) {
        $result['type'] = 'self-hosted';
        $result['video_url'] = $url;
        return $result;
    }

    return $result;
}

$video_data = bne_parse_video_url($video_url);

// Don't render if video URL is invalid
if ($video_data['type'] === 'unknown') {
    return;
}

// Calculate aspect ratio padding
$aspect_paddings = array(
    '16:9' => '56.25%',
    '4:3'  => '75%',
    '1:1'  => '100%',
    '9:16' => '177.78%',
);
$padding_bottom = isset($aspect_paddings[$aspect_ratio]) ? $aspect_paddings[$aspect_ratio] : '56.25%';

// Background class
$bg_class = 'bne-promo-video--bg-' . esc_attr($bg_style);
?>

<section class="bne-section bne-promo-video bne-promo-video--fullwidth <?php echo $bg_class; ?>">
    <?php if (!empty($title) || !empty($subtitle)) : ?>
        <div class="bne-container">
            <?php if (!empty($title)) : ?>
                <h2 class="bne-section-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>

            <?php if (!empty($subtitle)) : ?>
                <p class="bne-section-subtitle"><?php echo esc_html($subtitle); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="bne-promo-video__wrapper bne-promo-video__wrapper--fullwidth">
        <div class="bne-promo-video__container bne-promo-video__container--<?php echo esc_attr(str_replace(':', '-', $aspect_ratio)); ?>" style="padding-bottom: <?php echo esc_attr($padding_bottom); ?>;" id="promo-video-container">
            <?php if ($video_data['type'] === 'youtube') : ?>
                <iframe
                    class="bne-promo-video__iframe"
                    id="promo-video-iframe"
                    src="<?php echo esc_url($video_data['embed_url']); ?>"
                    title="<?php echo esc_attr($title); ?>"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                ></iframe>
            <?php elseif ($video_data['type'] === 'vimeo') : ?>
                <iframe
                    class="bne-promo-video__iframe"
                    id="promo-video-iframe"
                    src="<?php echo esc_url($video_data['embed_url']); ?>"
                    title="<?php echo esc_attr($title); ?>"
                    frameborder="0"
                    allow="autoplay; fullscreen"
                ></iframe>
            <?php elseif ($video_data['type'] === 'self-hosted') : ?>
                <video
                    class="bne-promo-video__video"
                    id="promo-video-element"
                    autoplay
                    muted
                    loop
                    playsinline
                    preload="auto"
                >
                    <source src="<?php echo esc_url($video_data['video_url']); ?>" type="video/<?php echo esc_attr(pathinfo($video_data['video_url'], PATHINFO_EXTENSION)); ?>">
                    Your browser does not support the video tag.
                </video>
            <?php endif; ?>

            <!-- Mute/Unmute Toggle Button -->
            <button class="bne-promo-video__mute-btn" id="promo-video-mute-btn" aria-label="Unmute video" title="Click to unmute">
                <svg class="bne-promo-video__mute-icon bne-promo-video__mute-icon--muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <line x1="23" y1="9" x2="17" y2="15"></line>
                    <line x1="17" y1="9" x2="23" y2="15"></line>
                </svg>
                <svg class="bne-promo-video__mute-icon bne-promo-video__mute-icon--unmuted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                </svg>
            </button>
        </div>
    </div>
</section>

<script>
(function() {
    var container = document.getElementById('promo-video-container');
    var muteBtn = document.getElementById('promo-video-mute-btn');
    var iframe = document.getElementById('promo-video-iframe');
    var video = document.getElementById('promo-video-element');
    var isMuted = true;

    if (!container || !muteBtn) return;

    // Update button state
    function updateMuteButton() {
        if (isMuted) {
            muteBtn.classList.remove('is-unmuted');
            muteBtn.setAttribute('aria-label', 'Unmute video');
            muteBtn.setAttribute('title', 'Click to unmute');
        } else {
            muteBtn.classList.add('is-unmuted');
            muteBtn.setAttribute('aria-label', 'Mute video');
            muteBtn.setAttribute('title', 'Click to mute');
        }
    }

    // Handle mute button click
    muteBtn.addEventListener('click', function(e) {
        e.preventDefault();
        isMuted = !isMuted;
        updateMuteButton();

        if (video) {
            // Self-hosted video
            video.muted = isMuted;
        } else if (iframe) {
            // YouTube - use postMessage API
            var command = isMuted ? 'mute' : 'unMute';
            iframe.contentWindow.postMessage(JSON.stringify({
                event: 'command',
                func: command,
                args: []
            }), '*');

            // Vimeo - use postMessage API
            iframe.contentWindow.postMessage(JSON.stringify({
                method: 'setVolume',
                value: isMuted ? 0 : 1
            }), '*');
        }
    });

    // Intersection Observer for scroll-triggered video play/pause
    if (video) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    video.play();
                } else {
                    video.pause();
                }
            });
        }, { threshold: 0.3 });
        observer.observe(container);
    }

    if (iframe) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    // Play - YouTube
                    iframe.contentWindow.postMessage(JSON.stringify({event: 'command', func: 'playVideo', args: []}), '*');
                    // Play - Vimeo
                    iframe.contentWindow.postMessage(JSON.stringify({method: 'play'}), '*');
                } else {
                    // Pause - YouTube
                    iframe.contentWindow.postMessage(JSON.stringify({event: 'command', func: 'pauseVideo', args: []}), '*');
                    // Pause - Vimeo
                    iframe.contentWindow.postMessage(JSON.stringify({method: 'pause'}), '*');
                }
            });
        }, { threshold: 0.3 });
        observer.observe(container);
    }
})();
</script>
