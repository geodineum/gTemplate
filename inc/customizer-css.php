<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/**
 * Sanitize color values — ensure # prefix, pass through rgba/rgb.
 */
function gtemplate_prepend_hash($color) {
    if (empty($color)) return $color;
    if (strpos($color, 'rgba') === 0 || strpos($color, 'rgb') === 0) return $color;
    return strpos($color, '#') === 0 ? $color : '#' . $color;
}

/**
 * Output shared customizer CSS vars and rules for all parent-registered settings.
 * Child themes append geometry-specific CSS via the gtemplate_dynamic_css filter.
 */
function gtemplate_customizer_css() {
    $h = 'gtemplate_prepend_hash';
    $css = '';

    // ════════════════════════════════════════════
    // SHARED CSS CUSTOM PROPERTIES
    // ════════════════════════════════════════════

    // ── Typography colors (parent section: typography_colors) ──
    $color_p  = get_theme_mod('typography_color_p', '#ffffff');
    $color_h1 = get_theme_mod('typography_color_h1', '#00f3ff');
    $color_h2 = get_theme_mod('typography_color_h2', '#00f3ff');
    $color_h3 = get_theme_mod('typography_color_h3', '#00f3ff');
    $color_h4 = get_theme_mod('typography_color_h4', '#00f3ff');

    // ── Post overlay (parent section: gtemplate_post_overlay) ──
    $po_max_width    = get_theme_mod('post_overlay_max_width', '900px');
    $po_padding      = get_theme_mod('post_overlay_padding', '60px 20px 40px');
    $po_hero_height  = intval(get_theme_mod('post_overlay_hero_height', 50));
    $po_title_min    = get_theme_mod('post_overlay_title_size_min', '1.8rem');
    $po_title_max    = get_theme_mod('post_overlay_title_size_max', '3rem');
    $po_body_size    = get_theme_mod('post_overlay_body_size', '1rem');
    $po_line_height  = get_theme_mod('post_overlay_line_height', '1.8');
    $po_bg_color     = get_theme_mod('post_overlay_bg_color', '#0a0a0f');
    $po_title_color  = get_theme_mod('post_overlay_title_color', '#00f3ff');
    $po_body_color   = get_theme_mod('post_overlay_body_color', 'rgba(255, 255, 255, 0.9)');
    $po_heading_col  = get_theme_mod('post_overlay_heading_color', '#00f3ff');
    $po_link_color   = get_theme_mod('post_overlay_link_color', '#00f3ff');
    $po_meta_color   = get_theme_mod('post_overlay_meta_color', 'rgba(255, 255, 255, 0.6)');
    $po_border_color = get_theme_mod('post_overlay_border_color', 'rgba(0, 243, 255, 0.2)');
    $po_show_author  = get_theme_mod('post_overlay_show_author', true) ? 1 : 0;
    $po_show_date    = get_theme_mod('post_overlay_show_date', true) ? 1 : 0;
    $po_show_cats    = get_theme_mod('post_overlay_show_categories', true) ? 1 : 0;
    $po_show_hero    = get_theme_mod('post_overlay_show_hero', true) ? 1 : 0;

    // Build :root block with shared vars
    $css .= ':root{';

    // Typography colors
    $css .= '--color-p:' . esc_attr($h($color_p)) . ';';
    $css .= '--color-h1:' . esc_attr($h($color_h1)) . ';';
    $css .= '--color-h2:' . esc_attr($h($color_h2)) . ';';
    $css .= '--color-h3:' . esc_attr($h($color_h3)) . ';';
    $css .= '--color-h4:' . esc_attr($h($color_h4)) . ';';

    // Post overlay
    $css .= '--post-max-width:' . esc_attr($po_max_width) . ';';
    $css .= '--post-padding:' . esc_attr($po_padding) . ';';
    $css .= '--post-hero-height:' . esc_attr($po_hero_height) . 'vh;';
    $css .= '--post-title-size-min:' . esc_attr($po_title_min) . ';';
    $css .= '--post-title-size-max:' . esc_attr($po_title_max) . ';';
    $css .= '--post-body-size:' . esc_attr($po_body_size) . ';';
    $css .= '--post-line-height:' . esc_attr($po_line_height) . ';';
    $css .= '--post-bg-color:' . esc_attr($po_bg_color) . ';';
    $css .= '--post-title-color:' . esc_attr($h($po_title_color)) . ';';
    $css .= '--post-body-color:' . esc_attr($po_body_color) . ';';
    $css .= '--post-heading-color:' . esc_attr($h($po_heading_col)) . ';';
    $css .= '--post-link-color:' . esc_attr($h($po_link_color)) . ';';
    $css .= '--post-meta-color:' . esc_attr($po_meta_color) . ';';
    $css .= '--post-border-color:' . esc_attr($po_border_color) . ';';
    $css .= '--post-show-author:' . esc_attr($po_show_author) . ';';
    $css .= '--post-show-date:' . esc_attr($po_show_date) . ';';
    $css .= '--post-show-categories:' . esc_attr($po_show_cats) . ';';
    $css .= '--post-show-hero:' . esc_attr($po_show_hero) . ';';

    // Legacy compat vars (used by gCube and older themes)
    $css .= '--gradcolor1:' . esc_attr($h(get_theme_mod('grad_color1', '#ee7752'))) . ';';
    $css .= '--gradcolor2:' . esc_attr($h(get_theme_mod('grad_color2', '#e73c7e'))) . ';';
    $css .= '--gradcolor3:' . esc_attr($h(get_theme_mod('grad_color3', '#23a6d5'))) . ';';
    $css .= '--gradcolor4:' . esc_attr($h(get_theme_mod('grad_color4', '#23d5ab'))) . ';';
    $css .= '--color-bg:' . esc_attr($h(get_theme_mod('color_background', '#1b1b2f'))) . ';';
    $css .= '--color-txt:' . esc_attr($h(get_theme_mod('color_text', '#ffffff'))) . ';';
    $css .= '--color-header:' . esc_attr($h(get_theme_mod('color_header', '#00f3ff'))) . ';';
    $css .= '--scrollbar-color1:' . esc_attr($h(get_theme_mod('scrollbar_color1', '#00f3ff'))) . ';';
    $css .= '--scrollbar-color2:' . esc_attr($h(get_theme_mod('scrollbar_color2', '#1b1b2f'))) . ';';

    // Content overlay desktop width
    $overlay_desktop_w = intval(get_theme_mod('content_overlay_desktop_width', 70));
    $css .= '--content-overlay-desktop-width:' . esc_attr($overlay_desktop_w) . '%;';

    // Geodineum content system (.geo) — accent + background overrides. These
    // re-declare tokens whose defaults live in golden-typography.css; emitted
    // at wp_head:100 (after the stylesheet), so the Customizer values win.
    $css .= '--gold:' . esc_attr($h(get_theme_mod('geo_gold', '#c9a961'))) . ';';
    $css .= '--gold-bright:' . esc_attr($h(get_theme_mod('geo_gold_bright', '#e8c468'))) . ';';
    $css .= '--bg-0:' . esc_attr($h(get_theme_mod('geo_bg', '#0a0a0d'))) . ';';

    // Cascading tooltip (window.GeoTip) styling knobs. Color mods default empty
    // so the tooltip inherits the theme tokens (and flips light/dark) until the
    // operator explicitly overrides one. Sizing/timing always emit.
    $tip_bg     = get_theme_mod('tooltip_bg', '');
    $tip_accent = get_theme_mod('tooltip_accent', '');
    $tip_border = get_theme_mod('tooltip_border', '');
    if ($tip_bg)     $css .= '--geo-tip-bg:' . esc_attr($h($tip_bg)) . ';';
    if ($tip_accent) $css .= '--geo-tip-accent:' . esc_attr($h($tip_accent)) . ';';
    if ($tip_border) $css .= '--geo-tip-border:' . esc_attr($h($tip_border)) . ';';
    $css .= '--geo-tip-max-width:' . intval(get_theme_mod('tooltip_max_width', 300)) . 'px;';
    $css .= '--geo-tip-open-delay:' . intval(get_theme_mod('tooltip_open_delay', 350)) . ';';
    $css .= '--geo-tip-hide-delay:' . intval(get_theme_mod('tooltip_hide_delay', 220)) . ';';
    $css .= '--geo-tip-max-depth:' . intval(get_theme_mod('tooltip_max_depth', 3)) . ';';

    $css .= '}';

    // Content overlay — scrollbar always on right screen edge, desktop width
    $css .= '.content-overlay{position:fixed;top:0;right:0;bottom:0;overflow-y:auto;overflow-x:hidden;z-index:9999;}';
    $css .= '.content-overlay .overlay-inner{margin:0 auto;max-width:var(--post-max-width);}';
    $css .= '@media(min-width:769px){';
    $css .= '.content-overlay{width:var(--content-overlay-desktop-width);}';
    $css .= '}';
    $css .= '@media(max-width:768px){';
    $css .= '.content-overlay{width:100%;left:0;}';
    $css .= '}';

    // ════════════════════════════════════════════
    // SHARED RENDERED RULES
    // ════════════════════════════════════════════

    // Heading blur radii (customizable via Typography Colors section)
    $blur_h1 = intval(get_theme_mod('typography_blur_h1', 20));
    $blur_h2 = intval(get_theme_mod('typography_blur_h2', 15));
    $blur_h3 = intval(get_theme_mod('typography_blur_h3', 10));
    $blur_h4 = intval(get_theme_mod('typography_blur_h4', 0));

    // Heading typography (uses parent-registered colors + blur)
    $css .= 'h1{color:var(--color-h1);' . ($blur_h1 ? "text-shadow:0 0 {$blur_h1}px var(--color-h1);" : '') . '}';
    $css .= 'h2{color:var(--color-h2);' . ($blur_h2 ? "text-shadow:0 0 {$blur_h2}px var(--color-h2);" : '') . '}';
    $css .= 'h3{color:var(--color-h3);' . ($blur_h3 ? "text-shadow:0 0 {$blur_h3}px var(--color-h3);" : '') . '}';

    // Content box typography (shared — uses parent-registered colors + blur)
    $css .= '.content-box p{color:var(--color-p);}';
    $css .= '.content-box h1{color:var(--color-h1);' . ($blur_h1 ? "text-shadow:0 0 {$blur_h1}px var(--color-h1);" : '') . '}';
    $css .= '.content-box h2{color:var(--color-h2);' . ($blur_h2 ? "text-shadow:0 0 {$blur_h2}px var(--color-h2);" : '') . '}';
    $css .= '.content-box h3{color:var(--color-h3);' . ($blur_h3 ? "text-shadow:0 0 {$blur_h3}px var(--color-h3);" : '') . '}';
    $css .= '.content-box h4,.content-box h5,.content-box h6{color:var(--color-h4);' . ($blur_h4 ? "text-shadow:0 0 {$blur_h4}px var(--color-h4);" : '') . '}';

    // Logo sizing (parent section: logo)
    $logo_w = get_theme_mod('logo_width', '');
    $logo_h = get_theme_mod('logo_height', '');
    if ($logo_w) $css .= ".site-logo img{width:{$logo_w};}";
    if ($logo_h) $css .= ".site-logo img{height:{$logo_h};}";

    // ════════════════════════════════════════════
    // CHILD THEME FILTER — append geometry CSS
    // ════════════════════════════════════════════
    $css = apply_filters('gtemplate_dynamic_css', $css);

    if (!empty($css)) {
        echo '<style id="gtemplate-customizer-css">' . $css . '</style>';
    }
}
add_action('wp_head', 'gtemplate_customizer_css', 100);

/**
 * Pre-paint theme boot. Sets <html data-theme> from the persisted choice before
 * any CSS paints, so switching to light never flashes the dark ground. Default
 * is dark (the brand ground); the floating toggle in geo-behaviors.js persists
 * the visitor's choice to localStorage. Emitted at wp_head:1, before styles.
 */
function gtemplate_theme_boot() {
    $accents = "['ice','magenta','sodium','acid','synth']";
    echo '<script>(function(){var r=document.documentElement;try{var s=localStorage.getItem("geo-theme");r.setAttribute("data-theme",s==="light"?"light":"dark");}catch(e){r.setAttribute("data-theme","dark");}'
       . 'try{var a=localStorage.getItem("geo-accent");r.setAttribute("data-accent",' . $accents . '.indexOf(a)>-1?a:"ice");}catch(e){r.setAttribute("data-accent","ice");}})();</script>' . "\n";
}
add_action('wp_head', 'gtemplate_theme_boot', 1);

/**
 * Generate button preset CSS for a given selector pair and style name.
 * Shared across child themes — each passes its own selectors.
 *
 * @param string $sel  Base selector (e.g. '.nav-btn', '.navButton')
 * @param string $hsel Hover selector (e.g. '.nav-btn:hover,.nav-btn:focus')
 * @param string $name_sel Optional name/label selector (e.g. '.navName') — gCube uses this
 * @param string $style Preset name: cyber|sleek|glass|pill|outline|neon_pink|neon_purple|neon
 * @return string CSS rules
 */
function gtemplate_button_preset_css($sel, $hsel, $name_sel, $style) {
    $css = '';
    switch ($style) {
        case 'sleek':
            $css .= "{$sel}{background:rgba(255,255,255,0.95)!important;border:none!important;border-radius:8px!important;padding:12px 24px!important;box-shadow:0 2px 8px rgba(0,0,0,0.08);transition:background-color 0.3s cubic-bezier(0.4,0,0.2,1),transform 0.3s cubic-bezier(0.4,0,0.2,1)!important;}";
            $css .= "{$hsel}{background:rgba(255,255,255,1)!important;box-shadow:0 4px 20px rgba(0,0,0,0.12);transform:translateY(-2px);border:none!important;animation:none!important;}";
            if ($name_sel) $css .= "{$name_sel}{background:transparent!important;font-weight:500;letter-spacing:0.5px;text-transform:uppercase;font-size:0.85rem!important;}";
            break;
        case 'glass':
            $css .= "{$sel}{background:rgba(255,255,255,0.15)!important;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.2)!important;border-radius:12px!important;padding:14px 28px!important;box-shadow:0 4px 30px rgba(0,0,0,0.1);transition:background-color 0.3s ease,transform 0.3s ease!important;}";
            $css .= "{$hsel}{background:rgba(255,255,255,0.25)!important;border-color:rgba(255,255,255,0.4)!important;box-shadow:0 8px 32px rgba(0,0,0,0.15);animation:none!important;}";
            if ($name_sel) $css .= "{$name_sel}{background:transparent!important;color:#333!important;font-weight:500;text-transform:uppercase;letter-spacing:1px;font-size:0.8rem!important;}";
            break;
        case 'pill':
            $css .= "{$sel}{background:var(--nav-button-bg-color,#fff)!important;border:2px solid var(--nav-button-border-color,#333)!important;border-radius:50px!important;padding:10px 28px!important;transition:background-color 0.25s ease,transform 0.25s ease!important;}";
            $css .= "{$hsel}{background:var(--nav-button-hover-bg-color,#333)!important;transform:scale(1.05);animation:none!important;}";
            if ($name_sel) $css .= "{$name_sel}{background:transparent!important;font-weight:600;text-transform:uppercase;letter-spacing:1px;font-size:0.75rem!important;}";
            break;
        case 'outline':
            $css .= "{$sel}{background:transparent!important;border:2px solid var(--nav-button-text-color,#333)!important;border-radius:4px!important;padding:12px 24px!important;transition:background-color 0.2s ease,transform 0.2s ease!important;}";
            $css .= "{$hsel}{background:var(--nav-button-text-color,#333)!important;animation:none!important;}";
            if ($name_sel) $css .= "{$name_sel}{background:transparent!important;font-weight:500;text-transform:uppercase;letter-spacing:2px;font-size:0.8rem!important;}";
            break;
        case 'neon': // gCube neon
            $css .= "{$sel}{background:rgba(0,0,0,0.8)!important;border:1px solid var(--color-highlight,#e51022)!important;border-radius:4px!important;padding:12px 24px!important;box-shadow:0 0 10px rgba(229,16,34,0.3),inset 0 0 10px rgba(229,16,34,0.1);transition:background-color 0.3s ease,transform 0.3s ease!important;}";
            $css .= "{$hsel}{box-shadow:0 0 20px rgba(229,16,34,0.6),0 0 40px rgba(229,16,34,0.3),inset 0 0 15px rgba(229,16,34,0.2);border-color:var(--color-highlight,#e51022)!important;animation:none!important;}";
            if ($name_sel) $css .= "{$name_sel}{background:transparent!important;color:var(--color-highlight,#e51022)!important;font-weight:500;text-transform:uppercase;letter-spacing:2px;font-size:0.8rem!important;text-shadow:0 0 10px rgba(229,16,34,0.5);}";
            break;
    }
    return $css;
}

function gtemplate_js_config() {
    $config = apply_filters('gtemplate_js_config', []);
    if (!empty($config)) {
        echo '<script id="gtemplate-config">window.gTemplateConfig=' . wp_json_encode($config) . ';</script>';
    }
}
add_action('wp_head', 'gtemplate_js_config', 101);
