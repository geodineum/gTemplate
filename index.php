<?php
/**
 * Main template for gTemplate parent theme
 *
 * When used standalone (no child theme), renders a simple demo layout.
 * Child themes override this file to provide geometry-specific HTML
 * (e.g., 6-face cube, 8-cell tesseract, custom layouts).
 *
 * @package gTemplate
 */

get_header();

$face_count = apply_filters('gtemplate_face_count', 6);
$face_label = apply_filters('gtemplate_face_label', 'face');
$face_prefix = apply_filters('gtemplate_customizer_face_prefix', 'gtemplate_face');

// Default labels
$default_labels = apply_filters('gtemplate_default_labels', [
    0 => 'Home',
    1 => 'About',
    2 => 'Services',
    3 => 'Portfolio',
    4 => 'Blog',
    5 => 'Contact',
]);

// Build face data
$faces = [];
$first_enabled = null;

for ($i = 0; $i < $face_count; $i++) {
    $enabled = (bool) get_theme_mod("{$face_prefix}_{$i}_enabled", true);
    $title_override = get_theme_mod("{$face_prefix}_{$i}_title", '');
    $label = get_theme_mod("{$face_prefix}_{$i}_label", $default_labels[$i] ?? ucfirst($face_label) . ' ' . ($i + 1));
    $faces[$i] = [
        'key' => "{$face_label}{$i}",
        'label' => $label,
        'title' => !empty($title_override) ? $title_override : $label,
        'show_title' => (bool) get_theme_mod("{$face_prefix}_{$i}_show_title", true),
        'enabled' => $enabled,
    ];

    if ($enabled && $first_enabled === null) {
        $first_enabled = $i;
    }
}

if ($first_enabled === null) {
    $first_enabled = 0;
}

/**
 * Action: gtemplate_before_layout
 *
 * Fires before the main layout is rendered.
 * Child themes can use this to inject navigation or other UI.
 */
do_action('gtemplate_before_layout', $faces, $first_enabled);
?>

<main id="primary" class="site-main gtemplate-standalone">
    <?php
    /**
     * Action: gtemplate_render_navigation
     *
     * Fires where navigation should be rendered.
     * Child themes hook here to provide geometry-specific navigation.
     * If no child hooks in, parent renders simple tab navigation.
     */
    if (has_action('gtemplate_render_navigation')) {
        do_action('gtemplate_render_navigation', $faces, $first_enabled);
    } else {
        // Default: simple tab navigation
        ?>
        <nav class="gtemplate-nav" role="navigation" aria-label="<?php echo esc_attr(ucfirst($face_label)); ?> navigation">
            <?php foreach ($faces as $index => $face):
                if (!$face['enabled']) continue;
                $is_active = ($index === $first_enabled);
            ?>
                <button class="gtemplate-nav-btn<?php echo $is_active ? ' active' : ''; ?>"
                        data-face="<?php echo esc_attr($index); ?>"
                        aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>">
                    <?php echo esc_html($face['label']); ?>
                </button>
            <?php endforeach; ?>
        </nav>
        <?php
    }
    ?>

    <div class="gtemplate-content-layer" id="gtemplate-faces">
        <?php foreach ($faces as $index => $face):
            if (!$face['enabled']) continue;
            $is_active = ($index === $first_enabled);
        ?>
            <section class="gtemplate-face<?php echo $is_active ? ' active' : ''; ?>"
                     data-face="<?php echo esc_attr($index); ?>"
                     data-key="<?php echo esc_attr($face['key']); ?>"
                     <?php echo $is_active ? '' : 'hidden'; ?>>
                <div class="gtemplate-face-container">
                    <?php if ($face['show_title']): ?>
                    <header class="gtemplate-face-header">
                        <h1 class="gtemplate-face-title"><?php echo esc_html($face['title']); ?></h1>
                    </header>
                    <?php endif; ?>
                    <div class="gtemplate-face-body">
                        <?php echo gtemplate_get_face_content($index); ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</main>

<?php
do_action('gtemplate_after_layout', $faces, $first_enabled);

get_footer();
