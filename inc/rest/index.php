<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/resources/faces.php';
require_once __DIR__ . '/resources/pages.php';
require_once __DIR__ . '/resources/posts.php';
require_once __DIR__ . '/resources/render.php';
require_once __DIR__ . '/resources/forms.php';

add_action('rest_api_init', function () {
    $namespace = gtemplate_get_rest_namespace();
    gtemplate_register_face_routes($namespace);
    gtemplate_register_page_routes($namespace);
    gtemplate_register_post_routes($namespace);
    gtemplate_register_render_routes($namespace);
    gtemplate_register_form_routes($namespace);
});
