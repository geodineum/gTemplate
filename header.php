<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    // Per-page description: the page excerpt when set, else the site tagline.
    $gtemplate_desc = is_singular() ? trim(wp_strip_all_tags((string) get_post_field('post_excerpt', get_queried_object_id()))) : '';
    if ($gtemplate_desc === '') { $gtemplate_desc = get_bloginfo('description'); }
    ?>
    <meta name="description" content="<?php echo esc_attr($gtemplate_desc); ?>">
    <meta property="og:description" content="<?php echo esc_attr($gtemplate_desc); ?>">

    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="page" class="site">
<?php
// Render logo (shared across all child themes)
$logo_url = get_theme_mod('logo_source', '');
if (empty($logo_url)) {
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
    }
}
if (!empty($logo_url)) {
    $alt_text = get_theme_mod('logo_alt_text', get_bloginfo('name') . ' logo');
    $logo_position = get_theme_mod('logo_position', 'center');
    $position_class = 'logo-' . esc_attr($logo_position);
    printf(
        '<a href="%s" class="site-logo %s" rel="home"><img src="%s" alt="%s" loading="lazy" decoding="async"></a>',
        esc_url(home_url('/')),
        $position_class,
        esc_url($logo_url),
        esc_attr($alt_text)
    );
}
?>
