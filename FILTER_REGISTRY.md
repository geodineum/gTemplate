# gTemplate — Filter & Action Registry

Contract reference for child themes (gCube and any future child theme) that
hook into the gTemplate parent. Each entry below names a WordPress filter or
action exposed by gTemplate, along with its arguments, default value (where
applicable), and the shape it expects in return.

This file is the source of truth. Filters or actions present in code but not
listed here are considered private and may be renamed or removed without
notice. To add a new public hook to this contract, edit gTemplate code and
add a row here in the same commit.

> **Pre-launch note (April 2026):** the project is pre-launch with no
> backwards-compatibility constraint. Filter names below are stable from
> Ch.1 launch onward, not before.

---

## Conventions

- All hooks use the `gtemplate_` prefix.
- "Default" is the value gTemplate would provide if no child filter were
  attached. Child themes typically replace, augment, or short-circuit it.
- "Args" are positional arguments to the filter/action callback after the
  default. WordPress passes them in declaration order; child themes must
  accept the right `accepted_args` count.
- Return type for filters is the expected output shape. For actions there
  is no return value.

---

## Bootstrap & identity

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_face_count` | filter | `GTEMPLATE_FACE_COUNT` (6) | — | Number of cube faces. Child can extend (e.g. tesseract = 8) but downstream renderers must support the count. | `int` |
| `gtemplate_face_label` | filter | `'face'` | — | Singular noun used in UI strings ("face 1", "face 2"). Cube child themes typically leave default; tesseract themes might use `'cell'`. | `string` |
| `gtemplate_customizer_face_prefix` | filter | `'gtemplate_face'` | — | Prefix for theme-mod keys per face (e.g. `gtemplate_face_0_label`). Child themes that wish to namespace their own customizer slots set this. | `string` |
| `gtemplate_theme_prefix` | filter | `'gtemplate'` | — | Prefix used for option names, transient keys, and template IDs. | `string` |
| `gtemplate_rest_namespace` | filter | `'gtemplate/v1'` | — | REST API namespace for gTemplate routes. | `string` |
| `gtemplate_gcore_config` | filter | array (see `init-helpers.php:294`) | — | gCore service-config bundle gTemplate hands to gCore on bootstrap. Use to add additional gCore service entries. | `array` |
| `gtemplate_default_labels` | filter | `['Home', 'About', 'Services', 'Portfolio', 'Blog', 'Contact']` | — | Default per-face labels when none configured. | `string[]` (length ≥ face count) |

## Faces & rendering

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_face_positions` | filter | `[]` | — | Per-face position-name list (`'top'`, `'front'`, `'right'`, `'back'`, `'left'`, `'bottom'` for a cube). Child theme MUST supply for positional CSS to work. | `string[]` indexed by face id |
| `gtemplate_face_css_classes` | filter | `[]` | — | Per-face CSS class list. Mirrors `gtemplate_face_positions` in shape. | `string[]` indexed by face id |
| `gtemplate_face_templates` | filter | gTemplate defaults | `int $face_id` | Override which Tera template ID renders a given face. | `array<int, string>` template-id by face id |
| `gtemplate_template_variables` | filter | computed `$variables` array | `string $templateName, int $faceId` | Last-mile mutation of the variable bag passed to Tera. Use for child-theme-specific variables. | `array` |
| `gtemplate_template_js_map` | filter | `['contact-form' => 'contact-form.js', …]` | — | Map face/template ID → JS file enqueued when that face renders. | `array<string, string>` |
| `gtemplate_demo_content` | filter | gTemplate default demo array | `int $face_id` | Replace the per-face demo content shipped when no real content is configured. | `array<int, array{title: string, content: string}>` |
| `gtemplate_content_sources` | filter | gTemplate default sources | — | Add or replace content-source providers (page / post / posts-list / demo / custom). | `array<string, callable>` |
| `gtemplate_dynamic_css` | filter | computed CSS string | — | Final-stage CSS modification (gCube uses this to layer 3D-cube CSS on top of base styles). | `string` |
| `gtemplate_js_config` | filter | `[]` | — | JS config object inlined to the page. | `array` |

## Navigation

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_nav_renderer` | filter | `'flat'` | — | Choose nav renderer. Recognized: `'flat'`, `'cube-3d'`. | `string` |
| `gtemplate_nav_cube_face` | filter | computed faces array | `int $index, array $face` | Per-face mutation hook inside the cube-3D nav renderer loop. | `array` |

## Enqueue (styles, scripts, settings)

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_styles` | filter | `[]` | — | Additional stylesheets the child theme wants enqueued by gTemplate's loader. Each entry: `[handle => ['src' => string, 'deps' => string[], 'ver' => string]]`. | `array<string, array>` |
| `gtemplate_scripts` | filter | `[]` | — | Same shape for scripts. Each entry may include `'args' => ['in_footer' => bool, 'strategy' => 'defer'\|'async']`. | `array<string, array>` |
| `gtemplate_primary_script_handle` | filter | `''` | — | Handle of the primary JS bundle that will receive `wp_localize_script` settings. | `string` |
| `gtemplate_js_settings` | filter | `$base_settings` | — | Final-stage mutation of the JS settings bag handed to `wp_localize_script`. | `array` |
| `gtemplate_js_settings_name` | filter | `<theme_prefix>Settings` | — | JS global name that holds localized settings (e.g. `gCubeSettings`). | `string` |

## Resource hints

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_preload_hints` | filter | `[]` | — | `<link rel="preload">` hint list (per-resource arrays). | `array<int, array>` |
| `gtemplate_dns_prefetch_domains` | filter | `[]` | — | Domains to add to `<link rel="dns-prefetch">`. | `string[]` |

## SEO & LLM

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_child_seo_active` | filter | `false` | — | Child theme returns `true` (or hooks `'__return_true'`) to take over SEO output and suppress gTemplate's own. gCube does this. | `bool` |
| `gtemplate_llms_site_details` | filter | computed string | — | Site-description block included in `llms.txt`. | `string` |
| `gtemplate_llms_optional_pages` | filter | `[]` | — | Additional pages listed in `llms.txt`. | `array` |
| `gtemplate_llms_pages` | filter | computed `$pages` | — | Final-stage mutation of the page list emitted to `llms.txt`. | `array` |
| `gtemplate_aio_post_types` | filter | `['post', 'page']` | — | Post types eligible for All-In-One SEO auto-generation. | `string[]` |
| `gtemplate_aio_auto_generate` | filter | `true` | `int $postId, WP_Post $post` | Per-post veto on auto-generation. Return `false` to skip. | `bool` |

## Content Security Policy

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_csp_script_src` | filter | gTemplate default list | — | Per-directive `script-src` source list. | `string[]` |
| `gtemplate_csp_style_src` | filter | gTemplate default list | — | `style-src` sources. | `string[]` |
| `gtemplate_csp_font_src` | filter | gTemplate default list | — | `font-src` sources. | `string[]` |
| `gtemplate_csp_img_src` | filter | gTemplate default list | — | `img-src` sources. | `string[]` |
| `gtemplate_csp_connect_src` | filter | gTemplate default list | — | `connect-src` sources (XHR/fetch/WebSocket origins). | `string[]` |

## PWA

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_child_pwa_active` | filter | `false` | — | Child theme returns `true` (or `'__return_true'`) to take over PWA manifest output. gCube does this. | `bool` |
| `gtemplate_pwa_enabled` | filter | `true` | — | Hard kill switch for the PWA manifest endpoint. | `bool` |

## Environment gate & access control (security-sensitive)

These filters govern the non-production ViewKey gate. A wrong return value opens a gated pre-production site to the public — treat them as a security surface.

| Hook | Type | Default | Args | Purpose | Return |
| --- | --- | --- | --- | --- | --- |
| `gtemplate_environment_gate_grant_access` | filter | `false` | `string $environment` | Component-granted entry to a **gated non-prod environment** without a site-wide ViewKey cookie (e.g. the gAnalyze access-code gate admits a visitor to its own surface). Return `true` to open the gate for the current request; default `false` keeps it closed. `inc/environment-gate.php:158`. | `bool` |
| `gtemplate_gate_exempt_paths` | filter | `['/sw.js', '/manifest.json', '/manifest.webmanifest']` | — | Request paths **exempted from the ViewKey gate** — served to all visitors, including unauthenticated ones, so PWA assets (service worker, manifest) install correctly. Anything added here bypasses the gate entirely. `inc/environment-gate.php:673`. | `string[]` |

---

## Actions

Actions are fire-and-forget; child themes hook them to inject markup or run
side effects.

| Hook | Args | Purpose |
| --- | --- | --- |
| `gtemplate_before_layout` | `array $faces, int $first_enabled` | Fired in `index.php` before the cube layout opens. Use to inject pre-layout markup or set up rendering context. |
| `gtemplate_after_layout` | `array $faces, int $first_enabled` | Fired after the layout closes. Use for footer-side hooks, analytics pixels, etc. |
| `gtemplate_render_navigation` | `array $faces, int $first_enabled` | Fired inside `index.php` where the nav element should render. The action's callback is responsible for emitting nav HTML. Each face may carry an optional `url` key; the cube-3d renderer then emits the item as an `<a href>` (real link for crawlers/middle-click, JS still preventDefaults) instead of a `<div>`. |
| `gtemplate_cookie_consent_check` | `array $consent_status` | Fired when the cookie-consent banner evaluates current status. Use to bridge into a GTM/CMP integration. |
| `gtemplate_register_customizer_sections` | `WP_Customize_Manager $wp_customize` | Fired during customizer init. Child themes register their own sections/controls here. |

---

## Child-theme override patterns observed in gCube

For reference, gCube currently overrides only the following gTemplate hooks:

- `gtemplate_dynamic_css` — appends gCube's 3D-cube CSS layer
  (`gCube/inc/customizer/css-output.php:28`).
- `gtemplate_child_seo_active` — short-circuits via `__return_true`
  (`gCube/inc/integrations/index.php:74`).
- `gtemplate_child_pwa_active` — short-circuits via `__return_true`
  (`gCube/inc/integrations/index.php:75`).

All other gtemplate_ filters/actions remain at their gTemplate defaults
under the gCube child theme.

---

## Adding a new public hook

1. Add the `apply_filters()` / `do_action()` site to the gTemplate code.
2. Add a row to the appropriate section of this file in the same commit.
3. If the hook is part of a security-sensitive surface (CSP, REST routing,
   cookie consent), document the security expectations alongside the args
   column.
4. If the hook is **internal** (gTemplate-only, not for child consumption),
   prefix its name with `_gtemplate_` instead of `gtemplate_` so the
   convention itself signals "private".

---

Closes: PH5-C5 (Tier-2 Commit 2.7)
