<p align="center">
  <a href="https://geodineum.com">
    <img src=".github/geodineum-logo.png" alt="Geodineum" width="128">
  </a>
</p>

# gTemplate

The WordPress **parent theme** of a Geodineum site: it owns the operational
backbone - gCore framework wiring, gNode connectivity, three-tier rendering, the
REST API, WP-CLI, and the customizer - so child themes contribute only visual
identity and geometry.

Built by **Niels Erik Toren** · WordPress parent theme, `geodineum/gtemplate-wp` (Composer); version in `style.css`

---

## What it is

gTemplate is a PHP parent theme built on the [gCore](https://geodineum.com)
MU-plugin framework. A site runs `gCore → gTemplate → child theme`: gCore
provides the service container and the gNode transport, gTemplate provides
everything a site needs to render and integrate, and a child theme (gCube, or a
custom one) supplies just the geometry and look.

It degrades gracefully. The renderer falls through three tiers - a pre-rendered
bundle, a gNode-rendered template, then server-side PHP - so when gNode or ValKey
is unreachable the site still renders from WordPress data, losing only the cached
fast paths. A child theme never has to know which tier served a face.

## Public build surface

What you build against as a child theme is the **filter and action contract**  - 
the `gtemplate_*` hooks gTemplate exposes for a child to parameterize rendering,
navigation, the customizer, SEO, and more. That contract has one home:
**[`FILTER_REGISTRY.md`](FILTER_REGISTRY.md)** - every public hook with its
arguments, default, and expected return shape. A hook present in code but not
listed there is private and may change without notice.

The other integration points a site or extension builds against - the
`/wp-json/gtemplate/v1/` REST API, the `[gbtn]` / `[gform]` shortcodes and their
capture stream, and the PHP config helpers - are specified in
**[`CONTRACT.md`](CONTRACT.md)**.

`FILTER_REGISTRY.md` is kept honest by a checker: `php
scripts/check-filter-registry.php` verifies every documented hook is still fired
in the code, so a rename or removal can't silently break child themes.

**Internal** - everything under `inc/` (the bootstrap, renderer, sync layer,
integrations, and CLI) is implementation and may change.

## Capabilities

- **Three-tier face rendering** - bundle cache → gNode template → PHP, chosen per
  face, with automatic fallback when the faster tiers are unavailable.
- **REST API** - resources for faces, forms, rendering, pages, and posts under a
  filterable `gtemplate/v1` namespace.
- **Content shortcodes** - `[gbtn]` call-to-action buttons and `[gform]` generic
  capture forms (honeypot, timing, mandatory consent, fingerprint rate-limiting)
  that land in a per-form ValKey stream.
- **WP-CLI** - a `wp gtemplate` command surface for registration, config, and
  environment/ViewKey management.
- **Customizer infrastructure** - parent sections plus an action for child themes
  to register their own.
- **Site integrations** - SEO, PWA manifest, cookie consent, asset versioning,
  resource hints, and an inbound-email-to-post pipeline, each degrading to a safe
  default when its backing service is absent.
- **DTAP environment gate** - non-production sites are ViewKey-gated by default;
  PWA assets are exempt.

## Contract

The precise integration surface - REST routes, the COMMS and `[gform]` capture
stream producers, wire formats, config keys, and PHP helper signatures - is in
**[`CONTRACT.md`](CONTRACT.md)**. Agents should prime from
**[`CONTRACT.scn.md`](CONTRACT.scn.md)**. The child-theme hook contract is
**[`FILTER_REGISTRY.md`](FILTER_REGISTRY.md)**.

## Quick start

You build *on* gTemplate from a child theme, parameterizing the parent through
its public hooks:

```php
// In a child theme's functions.php - see FILTER_REGISTRY.md for every hook.
add_filter('gtemplate_face_count', fn() => 8);   // this geometry has 8 faces, not 6

add_filter('gtemplate_content_sources', function (array $sources) {
    $sources['gallery'] = fn(int $face_id) => my_render_gallery($face_id);
    return $sources;
});

add_action('gtemplate_register_customizer_sections', function ($wp_customize) {
    // register the child theme's own customizer sections/controls here
});
```

Operate an installed site through WP-CLI and the Geodineum CLI:

```sh
wp gtemplate status --path=/var/www/mysite    # registration status
geodineum template contract                   # print the integration contract
sudo geodineum cache stats                    # page-cache key counts
```

## Limits worth knowing

- **Hook names are stable from Chapter-1 launch onward, not before.** Pre-launch
  has no backward-compatibility constraint; a name can still change.
- **Some surfaces are inert until their Chapter-2 extension is present.** The
  translation surface (language switcher, `/language` endpoint, hreflang) and the
  AI shortcodes (`[gtemplate_ai]`, `[gtemplate_ai_chat]`) ship as stubs in
  Chapter 1 - they render nothing until their extension is installed.
- **LLM-driven SEO needs gNode's LLM extension** - without it, gTemplate falls
  back to conventional SEO meta.
- **`gtemplate_rest_namespace` is filterable, but the route shape is
  parent-owned** - a child can rename the namespace, not the routes.
- **The bundle cache has no explicit TTL** - invalidation is keyed by the
  cell-mapping hash and relies on the gNode bundle's own TTL.

## Collaborate

Contributions are welcome. Open issues and pick up work on the ecosystem board
at [geodineum.com](https://geodineum.com); issues tagged `good-first-issue` are
a good place to start.

- Fork, branch, and open a pull request against `main`.
- Any change to a wire contract must update **both** `CONTRACT.md` and
  `CONTRACT.scn.md` in the same commit.
- A change to a signed extension must be re-signed in the same commit.

## Author & support

Built by **Niels Erik Toren**.

If you want to support the work:

| Currency | Address |
|---|---|
| Bitcoin (BTC) | `bc1qwf78fjgapt2gcts4mwf3gnfkclvqgtlg4gpu4d` |
| Ethereum (ETH) | `0xf38b517Dd2005d93E0BDc1e9807665074c5eC731` / `nierto.eth` |
| Monero (XMR) | `8BPaSoq1pEJH4LgbGNQ92kFJA3oi2frE4igHvdP9Lz2giwhFo2VnNvGT8XABYasjtoVY2Qb3LVHv6CP3qwcJ8UnyRtjWRZ5` |

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

## License

Licensed under either of

* [Apache License, Version 2.0](LICENSE-APACHE)
* [MIT License](LICENSE-MIT)

at your option.
